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
        self._root = Path(root)

    def publish(self, artifacts: DatasetArtifacts) -> DatasetPublicationResult:
        if not isinstance(artifacts, DatasetArtifacts):
            raise TypeError("DatasetPublisher accepts only DatasetArtifacts")
        DatasetSerializer.verify(artifacts)

        self._prepare_root()
        self._after_prepare_root()
        root_fd = self._open_private_root()
        try:
            root_metadata = os.fstat(root_fd)
            root_identity = (root_metadata.st_dev, root_metadata.st_ino)
            return self._publish_anchored(root_fd, root_identity, artifacts)
        finally:
            os.close(root_fd)

    def _publish_anchored(
        self,
        root_fd: int,
        root_identity: tuple[int, int],
        artifacts: DatasetArtifacts,
    ) -> DatasetPublicationResult:
        dataset_id = artifacts.descriptor.dataset_id
        target = self._root / dataset_id
        existing = self._existing_status(root_fd, dataset_id, artifacts)
        if existing is not None:
            self._assert_root_path_stable(root_identity)
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
                self._assert_root_path_stable(root_identity)
                return DatasetPublicationResult(
                    dataset_id=dataset_id,
                    target=target,
                    status=existing,
                )

            staging_path = self._root / staging_name
            self._before_atomic_rename(staging_path, target)
            self._assert_root_path_stable(root_identity)
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
                self._assert_root_path_stable(root_identity)
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
            self._assert_root_path_stable(root_identity)
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

    def _prepare_root(self) -> None:
        try:
            self._root.mkdir(parents=True, mode=0o700)
        except FileExistsError:
            pass

    def _open_private_root(self) -> int:
        try:
            root_fd = os.open(self._root, _DIRECTORY_FLAGS)
        except OSError as exc:
            raise DatasetPublicationConflict() from exc
        metadata = os.fstat(root_fd)
        if not stat.S_ISDIR(metadata.st_mode) or stat.S_IMODE(metadata.st_mode) != 0o700:
            os.close(root_fd)
            raise DatasetPublicationConflict()
        return root_fd

    def _assert_root_path_stable(self, expected_identity: tuple[int, int]) -> None:
        current_fd = self._open_private_root()
        try:
            metadata = os.fstat(current_fd)
            if (metadata.st_dev, metadata.st_ino) != expected_identity:
                raise DatasetPublicationConflict()
        finally:
            os.close(current_fd)

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
        try:
            target_metadata = os.fstat(target_fd)
            if not stat.S_ISDIR(target_metadata.st_mode) or stat.S_IMODE(
                target_metadata.st_mode
            ) != 0o700:
                raise DatasetPublicationConflict()
            if set(os.listdir(target_fd)) != {name for name, _ in _ARTIFACT_PAYLOADS}:
                raise DatasetPublicationConflict()
            for filename, attribute in _ARTIFACT_PAYLOADS:
                if self._read_private_file(target_fd, filename) != getattr(
                    artifacts, attribute
                ):
                    raise DatasetPublicationConflict()
            current = os.stat(target_name, dir_fd=root_fd, follow_symlinks=False)
            if (current.st_dev, current.st_ino) != (
                target_metadata.st_dev,
                target_metadata.st_ino,
            ):
                raise DatasetPublicationConflict()
            return DatasetPublicationStatus.ALREADY_PUBLISHED
        except OSError as exc:
            raise DatasetPublicationConflict() from exc
        finally:
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
    def _read_private_file(parent_fd: int, name: str) -> bytes:
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
            chunks: list[bytes] = []
            while chunk := os.read(descriptor, 64 * 1024):
                chunks.append(chunk)
            return b"".join(chunks)
        finally:
            os.close(descriptor)

    @staticmethod
    def _cleanup_staging(
        root_fd: int,
        staging_name: str,
        staging_fd: int | None = None,
    ) -> None:
        owns_fd = False
        if staging_fd is None:
            try:
                staging_fd = os.open(
                    staging_name,
                    _DIRECTORY_FLAGS,
                    dir_fd=root_fd,
                )
                owns_fd = True
            except OSError:
                return
        assert staging_fd is not None
        if not DatasetPublisher._named_directory_matches_fd(
            root_fd,
            staging_name,
            staging_fd,
        ):
            if owns_fd:
                os.close(staging_fd)
            return
        try:
            for name in os.listdir(staging_fd):
                os.unlink(name, dir_fd=staging_fd)
        finally:
            if owns_fd:
                os.close(staging_fd)

    @staticmethod
    def _named_directory_matches_fd(
        root_fd: int,
        name: str,
        directory_fd: int,
    ) -> bool:
        opened = os.fstat(directory_fd)
        return (
            stat.S_ISDIR(opened.st_mode)
            and stat.S_IMODE(opened.st_mode) == 0o700
            and opened.st_nlink >= 1
            and DatasetPublisher._named_directory_matches_identity(
                root_fd,
                name,
                (opened.st_dev, opened.st_ino),
            )
        )

    @staticmethod
    def _named_directory_matches_identity(
        root_fd: int,
        name: str,
        expected_identity: tuple[int, int],
    ) -> bool:
        try:
            named = os.stat(name, dir_fd=root_fd, follow_symlinks=False)
        except FileNotFoundError:
            return False
        return (
            stat.S_ISDIR(named.st_mode)
            and stat.S_IMODE(named.st_mode) == 0o700
            and (named.st_dev, named.st_ino) == expected_identity
        )


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
