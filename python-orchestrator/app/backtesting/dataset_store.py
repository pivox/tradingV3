"""Private, atomic publication for verified deterministic datasets."""

from __future__ import annotations

import os
import shutil
import stat
import tempfile
from enum import Enum
from pathlib import Path

from pydantic import BaseModel, ConfigDict

from app.backtesting.dataset import DatasetArtifacts, DatasetSerializer


_ARTIFACT_PAYLOADS = (
    ("candles.ndjson", "candles_ndjson"),
    ("quality-report.json", "quality_report_json"),
    ("manifest.json", "manifest_json"),
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
    """Publish verified bytes once without following or replacing symlinks."""

    def __init__(self, root: Path) -> None:
        self._root = Path(root)

    def publish(self, artifacts: DatasetArtifacts) -> DatasetPublicationResult:
        if not isinstance(artifacts, DatasetArtifacts):
            raise TypeError("DatasetPublisher accepts only DatasetArtifacts")
        DatasetSerializer.verify(artifacts)

        self._prepare_root()
        dataset_id = artifacts.descriptor.dataset_id
        target = self._root / dataset_id
        existing = self._existing_status(target, artifacts)
        if existing is not None:
            return DatasetPublicationResult(
                dataset_id=dataset_id,
                target=target,
                status=existing,
            )

        staging = Path(
            tempfile.mkdtemp(
                prefix=f".{dataset_id}.staging-",
                dir=self._root,
            )
        )
        os.chmod(staging, 0o700, follow_symlinks=False)
        try:
            for filename, attribute in _ARTIFACT_PAYLOADS:
                self._write_private_file(staging / filename, getattr(artifacts, attribute))
            self._fsync_directory(staging)

            existing = self._existing_status(target, artifacts)
            if existing is not None:
                return DatasetPublicationResult(
                    dataset_id=dataset_id,
                    target=target,
                    status=existing,
                )

            self._before_atomic_rename(staging, target)
            try:
                os.rename(staging, target)
            except OSError:
                existing = self._existing_status(target, artifacts)
                if existing is None:
                    raise
                return DatasetPublicationResult(
                    dataset_id=dataset_id,
                    target=target,
                    status=existing,
                )
            self._fsync_directory(self._root)
            return DatasetPublicationResult(
                dataset_id=dataset_id,
                target=target,
                status=DatasetPublicationStatus.PUBLISHED,
            )
        finally:
            if _lexists(staging):
                shutil.rmtree(staging)

    def _before_atomic_rename(self, staging: Path, target: Path) -> None:
        """Test hook invoked after durable staging and immediately before rename."""

    def _prepare_root(self) -> None:
        if _lexists(self._root):
            metadata = self._root.lstat()
            if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISDIR(metadata.st_mode):
                raise DatasetPublicationConflict()
            return
        self._root.mkdir(parents=True, mode=0o700)

    def _existing_status(
        self,
        target: Path,
        artifacts: DatasetArtifacts,
    ) -> DatasetPublicationStatus | None:
        if not _lexists(target):
            return None
        metadata = target.lstat()
        if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISDIR(metadata.st_mode):
            raise DatasetPublicationConflict()
        if stat.S_IMODE(metadata.st_mode) != 0o700:
            raise DatasetPublicationConflict()

        entries = {entry.name: entry for entry in os.scandir(target)}
        if set(entries) != {name for name, _ in _ARTIFACT_PAYLOADS}:
            raise DatasetPublicationConflict()
        for filename, attribute in _ARTIFACT_PAYLOADS:
            path = target / filename
            entry_metadata = path.lstat()
            if stat.S_ISLNK(entry_metadata.st_mode) or not stat.S_ISREG(
                entry_metadata.st_mode
            ):
                raise DatasetPublicationConflict()
            if stat.S_IMODE(entry_metadata.st_mode) != 0o600:
                raise DatasetPublicationConflict()
            if self._read_without_following(path) != getattr(artifacts, attribute):
                raise DatasetPublicationConflict()
        return DatasetPublicationStatus.ALREADY_PUBLISHED

    @staticmethod
    def _write_private_file(path: Path, payload: bytes) -> None:
        flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL
        flags |= getattr(os, "O_NOFOLLOW", 0)
        descriptor = os.open(path, flags, 0o600)
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
    def _read_without_following(path: Path) -> bytes:
        flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0)
        descriptor = os.open(path, flags)
        try:
            if not stat.S_ISREG(os.fstat(descriptor).st_mode):
                raise DatasetPublicationConflict()
            chunks: list[bytes] = []
            while chunk := os.read(descriptor, 64 * 1024):
                chunks.append(chunk)
            return b"".join(chunks)
        finally:
            os.close(descriptor)

    @staticmethod
    def _fsync_directory(path: Path) -> None:
        flags = os.O_RDONLY | getattr(os, "O_DIRECTORY", 0)
        descriptor = os.open(path, flags)
        try:
            os.fsync(descriptor)
        finally:
            os.close(descriptor)


def _lexists(path: Path) -> bool:
    try:
        path.lstat()
    except FileNotFoundError:
        return False
    return True
