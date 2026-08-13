"""Private, atomic publication for verified deterministic datasets."""

from __future__ import annotations

import ctypes
import errno
import os
import secrets
import stat
import sys
from enum import Enum
from pathlib import Path

from pydantic import BaseModel, ConfigDict

from app.backtesting.dataset import DatasetArtifacts, DatasetSerializer


_ARTIFACT_PAYLOADS = (
    ("candles.ndjson", "candles_ndjson"),
    ("quality-report.json", "quality_report_json"),
    ("manifest.json", "manifest_json"),
)
_DIRECTORY_FLAGS = (
    os.O_RDONLY
    | getattr(os, "O_DIRECTORY", 0)
    | getattr(os, "O_NOFOLLOW", 0)
    | getattr(os, "O_CLOEXEC", 0)
)


class DatasetPublicationStatus(str, Enum):
    PUBLISHED = "published"
    ALREADY_PUBLISHED = "already_published"


class DatasetPublicationResult(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)

    dataset_id: str
    target: Path
    status: DatasetPublicationStatus


class DatasetPublicationConflict(Exception):
    """Stable conflict that does not expose target or artifact contents."""

    reason_code = "dataset_publication_conflict"

    def __init__(self) -> None:
        super().__init__(self.reason_code)


class DatasetPublisher:
    """Publish verified bytes once through an anchored private root dirfd."""

    def __init__(self, root: Path) -> None:
        self._root = Path(os.path.abspath(os.fspath(root)))

    def publish(self, artifacts: DatasetArtifacts) -> DatasetPublicationResult:
        if not isinstance(artifacts, DatasetArtifacts):
            raise TypeError("DatasetPublisher accepts only DatasetArtifacts")
        DatasetSerializer.verify(artifacts)

        root_names, root_fds, root_identities = self._prepare_and_open_root()
        root_fd = root_fds[-1]
        try:
            return self._publish_anchored(
                root_fd,
                root_names,
                root_fds,
                root_identities,
                artifacts,
            )
        finally:
            for descriptor in reversed(root_fds):
                os.close(descriptor)

    def _publish_anchored(
        self,
        root_fd: int,
        root_names: tuple[str, ...],
        root_fds: tuple[int, ...],
        root_identities: tuple[tuple[int, int], ...],
        artifacts: DatasetArtifacts,
    ) -> DatasetPublicationResult:
        dataset_id = artifacts.descriptor.dataset_id
        target = self._root / dataset_id
        existing = self._existing_status(root_fd, dataset_id, artifacts)
        if existing is not None:
            self._assert_root_path_stable(root_names, root_fds, root_identities)
            return DatasetPublicationResult(
                dataset_id=dataset_id,
                target=target,
                status=existing,
            )

        staging_name, staging_fd = self._create_staging(root_fd, dataset_id)
        renamed = False
        cleanup_staging = True
        try:
            for filename, attribute in _ARTIFACT_PAYLOADS:
                self._write_private_file(
                    staging_fd,
                    filename,
                    getattr(artifacts, attribute),
                )
            self._fsync_staging(staging_fd)

            existing = self._existing_status(root_fd, dataset_id, artifacts)
            if existing is not None:
                self._assert_root_path_stable(root_names, root_fds, root_identities)
                return DatasetPublicationResult(
                    dataset_id=dataset_id,
                    target=target,
                    status=existing,
                )

            staging_path = self._root / staging_name
            self._before_atomic_rename(staging_path, target)
            self._assert_root_path_stable(root_names, root_fds, root_identities)
            try:
                self._assert_staging_identity(root_fd, staging_name, staging_fd)
            except DatasetPublicationConflict:
                cleanup_staging = False
                raise
            try:
                self._atomic_rename_no_replace(root_fd, staging_name, dataset_id)
                renamed = True
            except OSError as exc:
                if exc.errno not in {errno.EEXIST, errno.ENOTEMPTY}:
                    raise
                existing = self._existing_status(root_fd, dataset_id, artifacts)
                if existing is None:
                    raise DatasetPublicationConflict() from exc
                self._assert_root_path_stable(root_names, root_fds, root_identities)
                return DatasetPublicationResult(
                    dataset_id=dataset_id,
                    target=target,
                    status=existing,
                )
            os.fsync(root_fd)
            if self._existing_status(root_fd, dataset_id, artifacts) is not (
                DatasetPublicationStatus.ALREADY_PUBLISHED
            ):
                raise DatasetPublicationConflict()
            self._after_atomic_rename()
            self._assert_root_path_stable(root_names, root_fds, root_identities)
            return DatasetPublicationResult(
                dataset_id=dataset_id,
                target=target,
                status=DatasetPublicationStatus.PUBLISHED,
            )
        finally:
            try:
                if not renamed and cleanup_staging:
                    self._cleanup_staging(root_fd, staging_name, staging_fd)
            finally:
                os.close(staging_fd)

    def _after_prepare_root(self) -> None:
        """Test hook at the path-to-dirfd trust boundary."""

    def _before_atomic_rename(self, staging: Path, target: Path) -> None:
        """Test hook invoked after durable staging and immediately before rename."""

    def _after_atomic_rename(self) -> None:
        """Test hook invoked before validating the final path for success."""

    def _prepare_and_open_root(
        self,
    ) -> tuple[tuple[str, ...], tuple[int, ...], tuple[tuple[int, int], ...]]:
        parts = self._root.parts
        if len(parts) < 2 or parts[0] != os.path.sep:
            raise DatasetPublicationConflict()
        names = tuple(parts[1:])
        descriptors = [os.open(os.path.sep, _DIRECTORY_FLAGS)]
        identities: list[tuple[int, int]] = []
        try:
            for index, name in enumerate(names):
                parent_fd = descriptors[-1]
                child_fd = self._open_or_create_directory(parent_fd, name)
                child_metadata = os.fstat(child_fd)
                child_identity = (child_metadata.st_dev, child_metadata.st_ino)
                is_root = index == len(names) - 1
                if is_root:
                    os.close(child_fd)
                    self._after_prepare_root()
                    root_fd = self._open_observed_directory(parent_fd, name)
                    root_metadata = os.fstat(root_fd)
                    if (
                        (root_metadata.st_dev, root_metadata.st_ino)
                        != child_identity
                        or stat.S_IMODE(root_metadata.st_mode) != 0o700
                    ):
                        os.close(root_fd)
                        raise DatasetPublicationConflict()
                    descriptors.append(root_fd)
                    identities.append(child_identity)
                    return names, tuple(descriptors), tuple(identities)
                descriptors.append(child_fd)
                identities.append(child_identity)
        except Exception:
            for descriptor in reversed(descriptors):
                os.close(descriptor)
            raise
        raise DatasetPublicationConflict()

    def _open_or_create_directory(self, parent_fd: int, name: str) -> int:
        while True:
            try:
                return DatasetPublisher._open_observed_directory(parent_fd, name)
            except FileNotFoundError:
                return self._create_directory_component(parent_fd, name)
            except OSError as exc:
                raise DatasetPublicationConflict() from exc

    def _create_directory_component(self, parent_fd: int, name: str) -> int:
        for _ in range(100):
            private_name = f".dataset-root-{secrets.token_hex(16)}"
            try:
                os.mkdir(private_name, 0o700, dir_fd=parent_fd)
            except FileExistsError:
                continue
            observed = os.stat(
                private_name,
                dir_fd=parent_fd,
                follow_symlinks=False,
            )
            expected_identity = (observed.st_dev, observed.st_ino)
            self._after_private_component_mkdir(parent_fd, private_name)
            private_fd = self._open_observed_directory(
                parent_fd,
                private_name,
                expected_identity,
            )
            try:
                os.fsync(private_fd)
                os.fsync(parent_fd)
                try:
                    _atomic_rename_no_replace(parent_fd, private_name, name)
                except OSError as exc:
                    if exc.errno not in {errno.EEXIST, errno.ENOTEMPTY}:
                        raise
                    winner_fd = self._open_observed_directory(parent_fd, name)
                    os.close(private_fd)
                    return winner_fd
                self._assert_named_directory_identity(
                    parent_fd,
                    name,
                    private_fd,
                    expected_identity,
                )
                os.fsync(parent_fd)
                return private_fd
            except Exception:
                os.close(private_fd)
                raise
        raise DatasetPublicationConflict()

    def _after_private_component_mkdir(
        self,
        parent_fd: int,
        private_name: str,
    ) -> None:
        """Test hook after the private component identity is captured."""

    @staticmethod
    def _open_observed_directory(
        parent_fd: int,
        name: str,
        expected_identity: tuple[int, int] | None = None,
    ) -> int:
        observed = os.stat(name, dir_fd=parent_fd, follow_symlinks=False)
        if not stat.S_ISDIR(observed.st_mode):
            raise DatasetPublicationConflict()
        if expected_identity is not None and (
            observed.st_dev,
            observed.st_ino,
        ) != expected_identity:
            raise DatasetPublicationConflict()
        descriptor = os.open(name, _DIRECTORY_FLAGS, dir_fd=parent_fd)
        try:
            opened = os.fstat(descriptor)
            current = os.stat(name, dir_fd=parent_fd, follow_symlinks=False)
            identities = {
                (observed.st_dev, observed.st_ino),
                (opened.st_dev, opened.st_ino),
                (current.st_dev, current.st_ino),
            }
            if (
                len(identities) != 1
                or not stat.S_ISDIR(opened.st_mode)
                or not stat.S_ISDIR(current.st_mode)
            ):
                raise DatasetPublicationConflict()
            return descriptor
        except Exception:
            os.close(descriptor)
            raise

    @staticmethod
    def _assert_named_directory_identity(
        parent_fd: int,
        name: str,
        descriptor: int,
        expected_identity: tuple[int, int],
    ) -> None:
        opened = os.fstat(descriptor)
        named = os.stat(name, dir_fd=parent_fd, follow_symlinks=False)
        if (
            not stat.S_ISDIR(opened.st_mode)
            or not stat.S_ISDIR(named.st_mode)
            or (opened.st_dev, opened.st_ino) != expected_identity
            or (named.st_dev, named.st_ino) != expected_identity
        ):
            raise DatasetPublicationConflict()

    @staticmethod
    def _assert_root_path_stable(
        names: tuple[str, ...],
        descriptors: tuple[int, ...],
        expected_identities: tuple[tuple[int, int], ...],
    ) -> None:
        for index, (name, expected_identity) in enumerate(
            zip(names, expected_identities, strict=True)
        ):
            opened = os.fstat(descriptors[index + 1])
            try:
                named = os.stat(
                    name,
                    dir_fd=descriptors[index],
                    follow_symlinks=False,
                )
            except OSError as exc:
                raise DatasetPublicationConflict() from exc
            is_root = index == len(names) - 1
            if (
                not stat.S_ISDIR(opened.st_mode)
                or not stat.S_ISDIR(named.st_mode)
                or (opened.st_dev, opened.st_ino) != expected_identity
                or (named.st_dev, named.st_ino) != expected_identity
                or (
                    is_root
                    and (
                        stat.S_IMODE(opened.st_mode) != 0o700
                        or stat.S_IMODE(named.st_mode) != 0o700
                    )
                )
            ):
                raise DatasetPublicationConflict()

    def _fsync_staging(self, staging_fd: int) -> None:
        os.fsync(staging_fd)

    def _atomic_rename_no_replace(
        self,
        root_fd: int,
        staging_name: str,
        target_name: str,
    ) -> None:
        _atomic_rename_no_replace(root_fd, staging_name, target_name)

    @staticmethod
    def _assert_staging_identity(
        root_fd: int,
        staging_name: str,
        staging_fd: int,
    ) -> None:
        opened = os.fstat(staging_fd)
        try:
            named = os.stat(
                staging_name,
                dir_fd=root_fd,
                follow_symlinks=False,
            )
        except OSError as exc:
            raise DatasetPublicationConflict() from exc
        if (
            not stat.S_ISDIR(opened.st_mode)
            or not stat.S_ISDIR(named.st_mode)
            or stat.S_IMODE(opened.st_mode) != 0o700
            or stat.S_IMODE(named.st_mode) != 0o700
            or opened.st_nlink < 1
            or named.st_nlink < 1
            or (opened.st_dev, opened.st_ino) != (named.st_dev, named.st_ino)
        ):
            raise DatasetPublicationConflict()

    def _existing_status(
        self,
        root_fd: int,
        target_name: str,
        artifacts: DatasetArtifacts,
    ) -> DatasetPublicationStatus | None:
        try:
            target_fd = os.open(target_name, _DIRECTORY_FLAGS, dir_fd=root_fd)
        except FileNotFoundError:
            return None
        except OSError as exc:
            raise DatasetPublicationConflict() from exc
        artifact_descriptors: list[tuple[str, int, os.stat_result]] = []
        try:
            target_metadata = os.fstat(target_fd)
            if not stat.S_ISDIR(target_metadata.st_mode) or stat.S_IMODE(
                target_metadata.st_mode
            ) != 0o700:
                raise DatasetPublicationConflict()
            if set(os.listdir(target_fd)) != {name for name, _ in _ARTIFACT_PAYLOADS}:
                raise DatasetPublicationConflict()
            for filename, attribute in _ARTIFACT_PAYLOADS:
                descriptor, metadata = self._open_private_file(target_fd, filename)
                artifact_descriptors.append((filename, descriptor, metadata))
                if self._read_open_private_file(descriptor) != getattr(artifacts, attribute):
                    raise DatasetPublicationConflict()
            for filename, descriptor, metadata in artifact_descriptors:
                opened_artifact = os.fstat(descriptor)
                current_artifact = os.stat(
                    filename,
                    dir_fd=target_fd,
                    follow_symlinks=False,
                )
                if (
                    not stat.S_ISREG(current_artifact.st_mode)
                    or stat.S_IMODE(current_artifact.st_mode) != 0o600
                    or current_artifact.st_nlink != 1
                    or not stat.S_ISREG(opened_artifact.st_mode)
                    or stat.S_IMODE(opened_artifact.st_mode) != 0o600
                    or opened_artifact.st_nlink != 1
                    or (current_artifact.st_dev, current_artifact.st_ino)
                    != (metadata.st_dev, metadata.st_ino)
                    or (opened_artifact.st_dev, opened_artifact.st_ino)
                    != (metadata.st_dev, metadata.st_ino)
                ):
                    raise DatasetPublicationConflict()
            if set(os.listdir(target_fd)) != {name for name, _ in _ARTIFACT_PAYLOADS}:
                raise DatasetPublicationConflict()
            for (_, descriptor, _), (_, attribute) in zip(
                artifact_descriptors,
                _ARTIFACT_PAYLOADS,
                strict=True,
            ):
                os.lseek(descriptor, 0, os.SEEK_SET)
                if self._read_open_private_file(descriptor) != getattr(
                    artifacts,
                    attribute,
                ):
                    raise DatasetPublicationConflict()
            for filename, descriptor, metadata in artifact_descriptors:
                final_opened = os.fstat(descriptor)
                final_named = os.stat(
                    filename,
                    dir_fd=target_fd,
                    follow_symlinks=False,
                )
                if (
                    not stat.S_ISREG(final_opened.st_mode)
                    or not stat.S_ISREG(final_named.st_mode)
                    or stat.S_IMODE(final_opened.st_mode) != 0o600
                    or stat.S_IMODE(final_named.st_mode) != 0o600
                    or final_opened.st_nlink != 1
                    or final_named.st_nlink != 1
                    or (final_opened.st_dev, final_opened.st_ino)
                    != (metadata.st_dev, metadata.st_ino)
                    or (final_named.st_dev, final_named.st_ino)
                    != (metadata.st_dev, metadata.st_ino)
                ):
                    raise DatasetPublicationConflict()
            if set(os.listdir(target_fd)) != {
                name for name, _ in _ARTIFACT_PAYLOADS
            }:
                raise DatasetPublicationConflict()
            current = os.stat(target_name, dir_fd=root_fd, follow_symlinks=False)
            opened_target = os.fstat(target_fd)
            if (
                not stat.S_ISDIR(current.st_mode)
                or not stat.S_ISDIR(opened_target.st_mode)
                or stat.S_IMODE(current.st_mode) != 0o700
                or stat.S_IMODE(opened_target.st_mode) != 0o700
                or current.st_nlink < 1
                or opened_target.st_nlink < 1
                or (current.st_dev, current.st_ino)
                != (target_metadata.st_dev, target_metadata.st_ino)
                or (opened_target.st_dev, opened_target.st_ino)
                != (target_metadata.st_dev, target_metadata.st_ino)
            ):
                raise DatasetPublicationConflict()
            return DatasetPublicationStatus.ALREADY_PUBLISHED
        except OSError as exc:
            raise DatasetPublicationConflict() from exc
        finally:
            for _, descriptor, _ in artifact_descriptors:
                os.close(descriptor)
            os.close(target_fd)

    def _create_staging(self, root_fd: int, dataset_id: str) -> tuple[str, int]:
        for _ in range(100):
            name = f".{dataset_id}.staging-{secrets.token_hex(16)}"
            try:
                os.mkdir(name, 0o700, dir_fd=root_fd)
            except FileExistsError:
                continue
            staging_fd: int | None = None
            try:
                staging_fd = self._open_staging(root_fd, name)
                self._validate_staging(staging_fd)
                return name, staging_fd
            except Exception:
                try:
                    self._cleanup_staging(root_fd, name, staging_fd)
                finally:
                    if staging_fd is not None:
                        os.close(staging_fd)
                raise
        raise DatasetPublicationConflict()

    def _open_staging(self, root_fd: int, staging_name: str) -> int:
        return os.open(staging_name, _DIRECTORY_FLAGS, dir_fd=root_fd)

    def _validate_staging(self, staging_fd: int) -> None:
        metadata = os.fstat(staging_fd)
        if not stat.S_ISDIR(metadata.st_mode) or stat.S_IMODE(
            metadata.st_mode
        ) != 0o700:
            raise DatasetPublicationConflict()

    @staticmethod
    def _write_private_file(parent_fd: int, name: str, payload: bytes) -> None:
        flags = (
            os.O_WRONLY
            | os.O_CREAT
            | os.O_EXCL
            | getattr(os, "O_NOFOLLOW", 0)
            | getattr(os, "O_CLOEXEC", 0)
        )
        descriptor = os.open(name, flags, 0o600, dir_fd=parent_fd)
        try:
            view = memoryview(payload)
            while view:
                written = os.write(descriptor, view)
                view = view[written:]
            os.fchmod(descriptor, 0o600)
            os.fsync(descriptor)
        finally:
            os.close(descriptor)

    @staticmethod
    def _open_private_file(
        parent_fd: int,
        name: str,
    ) -> tuple[int, os.stat_result]:
        flags = (
            os.O_RDONLY
            | getattr(os, "O_NOFOLLOW", 0)
            | getattr(os, "O_CLOEXEC", 0)
        )
        descriptor = os.open(name, flags, dir_fd=parent_fd)
        try:
            metadata = os.fstat(descriptor)
            if (
                not stat.S_ISREG(metadata.st_mode)
                or stat.S_IMODE(metadata.st_mode) != 0o600
                or metadata.st_nlink != 1
            ):
                raise DatasetPublicationConflict()
            return descriptor, metadata
        except Exception:
            os.close(descriptor)
            raise

    @staticmethod
    def _read_open_private_file(descriptor: int) -> bytes:
        chunks: list[bytes] = []
        while chunk := os.read(descriptor, 64 * 1024):
            chunks.append(chunk)
        return b"".join(chunks)

    @staticmethod
    def _cleanup_staging(
        root_fd: int,
        staging_name: str,
        staging_fd: int | None = None,
    ) -> None:
        """Preserve failed staging for an out-of-band, non-concurrent janitor.

        Closing retained descriptors is handled by the callers. Removing even
        an fd-relative child here would race with replacement of that child.
        """


def _atomic_rename_no_replace(root_fd: int, source: str, target: str) -> None:
    """Atomically rename a directory without ever replacing the target."""

    libc = ctypes.CDLL(None, use_errno=True)
    source_bytes = os.fsencode(source)
    target_bytes = os.fsencode(target)
    if sys.platform == "darwin":
        function = libc.renameatx_np
        function.argtypes = [
            ctypes.c_int,
            ctypes.c_char_p,
            ctypes.c_int,
            ctypes.c_char_p,
            ctypes.c_uint,
        ]
        function.restype = ctypes.c_int
        result = function(root_fd, source_bytes, root_fd, target_bytes, 0x4)
    elif sys.platform.startswith("linux"):
        try:
            function = libc.renameat2
        except AttributeError as exc:
            raise OSError(errno.ENOTSUP, "atomic no-replace rename unavailable") from exc
        function.argtypes = [
            ctypes.c_int,
            ctypes.c_char_p,
            ctypes.c_int,
            ctypes.c_char_p,
            ctypes.c_uint,
        ]
        function.restype = ctypes.c_int
        result = function(root_fd, source_bytes, root_fd, target_bytes, 0x1)
    else:
        raise OSError(errno.ENOTSUP, "atomic no-replace rename unavailable")
    if result != 0:
        error_number = ctypes.get_errno()
        raise OSError(error_number, os.strerror(error_number), target)
