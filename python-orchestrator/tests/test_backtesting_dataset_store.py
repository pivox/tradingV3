from __future__ import annotations

import errno
import json
import os
import stat
from pathlib import Path

import pytest

import app.backtesting.dataset_store as dataset_store
from app.backtesting.contracts import DatasetDescriptor
from app.backtesting.dataset import DatasetArtifacts, DatasetArtifactVerificationError
from app.backtesting.dataset_store import (
    DatasetPublicationConflict,
    DatasetPublicationStatus,
    DatasetPublisher,
)


_FIXTURES = Path(__file__).parent / "fixtures" / "backtesting"
_ARTIFACT_NAMES = {"candles.ndjson", "quality-report.json", "manifest.json"}


def _artifacts() -> DatasetArtifacts:
    manifest_bytes = (_FIXTURES / "manifest-v1.json").read_bytes()
    return DatasetArtifacts(
        candles_ndjson=(_FIXTURES / "candles-v1.ndjson").read_bytes(),
        quality_report_json=(_FIXTURES / "quality-report-v1.json").read_bytes(),
        manifest_json=manifest_bytes,
        descriptor=DatasetDescriptor.from_manifest(json.loads(manifest_bytes)),
    )


def _target(root: Path) -> Path:
    return root / _artifacts().descriptor.dataset_id


def _assert_one_staging(root: Path, expected_names: set[str]) -> Path:
    staging = tuple(root.glob(".*.staging-*"))
    assert len(staging) == 1
    assert {item.name for item in staging[0].iterdir()} == expected_names
    return staging[0]


def test_publisher_writes_private_exact_artifacts_atomically(tmp_path: Path) -> None:
    root = tmp_path / "datasets"

    result = DatasetPublisher(root).publish(_artifacts())

    target = _target(root)
    assert result.status is DatasetPublicationStatus.PUBLISHED
    assert result.dataset_id == _artifacts().descriptor.dataset_id
    assert result.target == target
    assert target.is_dir()
    assert {item.name for item in target.iterdir()} == _ARTIFACT_NAMES
    assert (target / "candles.ndjson").read_bytes() == _artifacts().candles_ndjson
    assert (
        target / "quality-report.json"
    ).read_bytes() == _artifacts().quality_report_json
    assert (target / "manifest.json").read_bytes() == _artifacts().manifest_json
    assert stat.S_IMODE(target.stat().st_mode) == 0o700
    for name in _ARTIFACT_NAMES:
        assert stat.S_IMODE((target / name).stat().st_mode) == 0o600
    assert not tuple(root.glob(".*.staging-*"))


def test_publisher_is_idempotent_only_for_three_exact_bytes(tmp_path: Path) -> None:
    root = tmp_path / "datasets"
    publisher = DatasetPublisher(root)
    first = publisher.publish(_artifacts())

    second = publisher.publish(_artifacts())

    assert first.status is DatasetPublicationStatus.PUBLISHED
    assert second.status is DatasetPublicationStatus.ALREADY_PUBLISHED
    assert second.target == first.target


@pytest.mark.parametrize("corruption", ("changed", "missing", "extra"))
def test_existing_corrupt_dataset_conflicts_without_mutation(
    tmp_path: Path, corruption: str
) -> None:
    root = tmp_path / "datasets"
    publisher = DatasetPublisher(root)
    publisher.publish(_artifacts())
    target = _target(root)
    if corruption == "changed":
        (target / "candles.ndjson").write_bytes(b"changed\n")
    elif corruption == "missing":
        (target / "quality-report.json").unlink()
    else:
        (target / "extra.txt").write_bytes(b"preserve me")
    before = {
        item.name: item.read_bytes()
        for item in target.iterdir()
        if item.is_file()
    }

    with pytest.raises(DatasetPublicationConflict) as rejected:
        publisher.publish(_artifacts())

    assert rejected.value.reason_code == "dataset_publication_conflict"
    assert {
        item.name: item.read_bytes()
        for item in target.iterdir()
        if item.is_file()
    } == before
    assert not tuple(root.glob(".*.staging-*"))


def test_symlinked_target_conflicts_without_following_it(tmp_path: Path) -> None:
    root = tmp_path / "datasets"
    root.mkdir(mode=0o700)
    root.chmod(0o700)
    outside = tmp_path / "outside"
    outside.mkdir()
    marker = outside / "marker"
    marker.write_bytes(b"unchanged")
    _target(root).symlink_to(outside, target_is_directory=True)

    with pytest.raises(DatasetPublicationConflict):
        DatasetPublisher(root).publish(_artifacts())

    assert marker.read_bytes() == b"unchanged"
    assert _target(root).is_symlink()


def test_symlinked_artifact_conflicts_without_following_it(tmp_path: Path) -> None:
    root = tmp_path / "datasets"
    DatasetPublisher(root).publish(_artifacts())
    target = _target(root)
    outside = tmp_path / "outside-candles"
    outside.write_bytes(_artifacts().candles_ndjson)
    (target / "candles.ndjson").unlink()
    (target / "candles.ndjson").symlink_to(outside)

    with pytest.raises(DatasetPublicationConflict):
        DatasetPublisher(root).publish(_artifacts())

    assert outside.read_bytes() == _artifacts().candles_ndjson
    assert (target / "candles.ndjson").is_symlink()


class _SwapArtifactAfterReadPublisher(DatasetPublisher):
    def __init__(self, root: Path) -> None:
        super().__init__(root)
        self._swapped = False

    def _read_open_private_file(self, descriptor: int) -> bytes:
        payload = super()._read_open_private_file(descriptor)
        if not self._swapped:
            self._swapped = True
            artifact = _target(self._root) / "candles.ndjson"
            artifact.rename(artifact.with_name("candles-original"))
            artifact.write_bytes(payload)
            artifact.chmod(0o600)
        return payload


def test_artifact_replaced_after_read_never_becomes_idempotent(tmp_path: Path) -> None:
    root = tmp_path / "datasets"
    DatasetPublisher(root).publish(_artifacts())

    with pytest.raises(DatasetPublicationConflict):
        _SwapArtifactAfterReadPublisher(root).publish(_artifacts())

    assert (_target(root) / "candles-original").read_bytes() == (
        _artifacts().candles_ndjson
    )


def test_artifact_replaced_during_collective_stability_check_is_rejected(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    root = tmp_path / "datasets"
    artifacts = _artifacts()
    DatasetPublisher(root).publish(artifacts)
    target = _target(root)
    real_stat = os.stat
    swapped = False

    def swap_before_first_named_stability_stat(
        path: str | bytes | os.PathLike[str] | os.PathLike[bytes],
        *,
        dir_fd: int | None = None,
        follow_symlinks: bool = True,
    ) -> os.stat_result:
        nonlocal swapped
        if path == "candles.ndjson" and dir_fd is not None and not swapped:
            swapped = True
            quality = target / "quality-report.json"
            quality.rename(target / "quality-report-original")
            quality.write_bytes(artifacts.quality_report_json)
            quality.chmod(0o600)
        return real_stat(path, dir_fd=dir_fd, follow_symlinks=follow_symlinks)

    monkeypatch.setattr(os, "stat", swap_before_first_named_stability_stat)

    with pytest.raises(DatasetPublicationConflict):
        DatasetPublisher(root).publish(artifacts)

    assert (target / "quality-report-original").read_bytes() == (
        artifacts.quality_report_json
    )


class _MutateAfterInitialReadsPublisher(DatasetPublisher):
    def __init__(self, root: Path, *, chmod_target: bool = False) -> None:
        super().__init__(root)
        self._read_count = 0
        self._chmod_target = chmod_target

    def _read_open_private_file(self, descriptor: int) -> bytes:
        payload = super()._read_open_private_file(descriptor)
        self._read_count += 1
        if self._read_count == 3:
            if self._chmod_target:
                _target(self._root).chmod(0o755)
            else:
                candles = _target(self._root) / "candles.ndjson"
                changed = bytearray(candles.read_bytes())
                changed[0] = ord(" ")
                candles.write_bytes(bytes(changed))
        return payload


def test_in_place_artifact_mutation_after_initial_reads_is_rejected(
    tmp_path: Path,
) -> None:
    root = tmp_path / "datasets"
    DatasetPublisher(root).publish(_artifacts())

    with pytest.raises(DatasetPublicationConflict):
        _MutateAfterInitialReadsPublisher(root).publish(_artifacts())

    assert (_target(root) / "candles.ndjson").read_bytes() != (
        _artifacts().candles_ndjson
    )


def test_target_mode_change_after_initial_reads_is_rejected(tmp_path: Path) -> None:
    root = tmp_path / "datasets"
    DatasetPublisher(root).publish(_artifacts())

    with pytest.raises(DatasetPublicationConflict):
        _MutateAfterInitialReadsPublisher(root, chmod_target=True).publish(_artifacts())

    assert stat.S_IMODE(_target(root).stat().st_mode) == 0o755


class _SwapDuringSecondReadPassPublisher(DatasetPublisher):
    def __init__(self, root: Path) -> None:
        super().__init__(root)
        self._read_count = 0

    def _read_open_private_file(self, descriptor: int) -> bytes:
        payload = super()._read_open_private_file(descriptor)
        self._read_count += 1
        if self._read_count == 4:
            quality = _target(self._root) / "quality-report.json"
            quality.rename(_target(self._root) / "quality-report-original")
            quality.write_bytes(b"corrupt")
            quality.chmod(0o600)
        return payload


def test_artifact_swap_during_second_read_pass_fails_final_stability_check(
    tmp_path: Path,
) -> None:
    root = tmp_path / "datasets"
    DatasetPublisher(root).publish(_artifacts())

    with pytest.raises(DatasetPublicationConflict):
        _SwapDuringSecondReadPassPublisher(root).publish(_artifacts())

    target = _target(root)
    assert (target / "quality-report.json").read_bytes() == b"corrupt"
    assert (target / "quality-report-original").read_bytes() == (
        _artifacts().quality_report_json
    )


class _FailBeforeRenamePublisher(DatasetPublisher):
    def _before_atomic_rename(self, staging: Path, target: Path) -> None:
        raise OSError("injected before rename")


class _FailSecondWritePublisher(DatasetPublisher):
    def __init__(self, root: Path) -> None:
        super().__init__(root)
        self._write_count = 0

    def _write_private_file(self, parent_fd: int, name: str, payload: bytes) -> None:
        self._write_count += 1
        if self._write_count == 2:
            raise OSError("injected second write")
        super()._write_private_file(parent_fd, name, payload)


class _FailStagingFsyncPublisher(DatasetPublisher):
    def _fsync_staging(self, staging_fd: int) -> None:
        raise OSError("injected staging fsync")


class _FailStagingOpenPublisher(DatasetPublisher):
    def _open_staging(self, root_fd: int, staging_name: str) -> int:
        raise OSError(errno.EMFILE, "injected staging open")


class _FailStagingMetadataPublisher(DatasetPublisher):
    def _validate_staging(self, staging_fd: int) -> None:
        raise DatasetPublicationConflict()


@pytest.mark.parametrize(
    "publisher_type",
    (_FailStagingOpenPublisher, _FailStagingMetadataPublisher),
)
def test_staging_creation_failure_leaves_only_empty_recoverable_directory(
    tmp_path: Path,
    publisher_type: type[DatasetPublisher],
) -> None:
    root = tmp_path / "datasets"

    with pytest.raises((OSError, DatasetPublicationConflict)):
        publisher_type(root).publish(_artifacts())

    assert not _target(root).exists()
    _assert_one_staging(root, set())


def test_real_emfile_during_staging_open_leaves_unknown_directory_untouched(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    root = tmp_path / "datasets"
    artifacts = _artifacts()
    target = root / artifacts.descriptor.dataset_id
    real_open = os.open

    def fail_every_staging_open(
        path: str | bytes | os.PathLike[str] | os.PathLike[bytes],
        flags: int,
        mode: int = 0o777,
        *,
        dir_fd: int | None = None,
    ) -> int:
        if isinstance(path, str) and ".staging-" in path:
            raise OSError(errno.EMFILE, "too many open files")
        return real_open(path, flags, mode, dir_fd=dir_fd)

    monkeypatch.setattr(os, "open", fail_every_staging_open)

    with pytest.raises(OSError) as rejected:
        DatasetPublisher(root).publish(artifacts)

    assert rejected.value.errno == errno.EMFILE
    assert not target.exists()
    _assert_one_staging(root, set())


def test_emfile_after_first_artifact_preserves_contents_with_retained_staging_fd(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    root = tmp_path / "datasets"
    artifacts = _artifacts()
    target = root / artifacts.descriptor.dataset_id
    real_open = os.open
    staging_open_count = 0

    def fail_second_artifact_and_staging_reopen(
        path: str | bytes | os.PathLike[str] | os.PathLike[bytes],
        flags: int,
        mode: int = 0o777,
        *,
        dir_fd: int | None = None,
    ) -> int:
        nonlocal staging_open_count
        if isinstance(path, str) and ".staging-" in path:
            staging_open_count += 1
            if staging_open_count > 1:
                raise OSError(errno.EMFILE, "too many open files")
        if path == "quality-report.json":
            raise OSError(errno.EMFILE, "too many open files")
        return real_open(path, flags, mode, dir_fd=dir_fd)

    monkeypatch.setattr(os, "open", fail_second_artifact_and_staging_reopen)

    with pytest.raises(OSError) as rejected:
        DatasetPublisher(root).publish(artifacts)

    assert rejected.value.errno == errno.EMFILE
    assert not target.exists()
    _assert_one_staging(root, {"candles.ndjson"})


@pytest.mark.parametrize(
    ("publisher_type", "message"),
    (
        (_FailSecondWritePublisher, "injected second write"),
        (_FailStagingFsyncPublisher, "injected staging fsync"),
    ),
)
def test_write_or_staging_fsync_failure_preserves_staging_contents(
    tmp_path: Path,
    publisher_type: type[DatasetPublisher],
    message: str,
) -> None:
    root = tmp_path / "datasets"

    with pytest.raises(OSError, match=message):
        publisher_type(root).publish(_artifacts())

    assert not _target(root).exists()
    expected_names = (
        {"candles.ndjson"}
        if publisher_type is _FailSecondWritePublisher
        else _ARTIFACT_NAMES
    )
    _assert_one_staging(root, expected_names)


class _CreateEmptyTargetPublisher(DatasetPublisher):
    def _before_atomic_rename(self, staging: Path, target: Path) -> None:
        target.mkdir(mode=0o700)


class _SwapStagingNamePublisher(DatasetPublisher):
    def _before_atomic_rename(self, staging: Path, target: Path) -> None:
        parked = staging.with_name(staging.name + "-parked")
        staging.rename(parked)
        staging.mkdir(mode=0o700)


def test_staging_name_swap_never_publishes_unknown_directory(tmp_path: Path) -> None:
    root = tmp_path / "datasets"

    with pytest.raises(DatasetPublicationConflict):
        _SwapStagingNamePublisher(root).publish(_artifacts())

    assert not _target(root).exists()
    parked = tuple(root.glob(".*.staging-*-parked"))
    assert len(parked) == 1
    assert {item.name for item in parked[0].iterdir()} == _ARTIFACT_NAMES


class _SwapDuringNoReplacePublisher(DatasetPublisher):
    def _atomic_rename_no_replace(
        self,
        root_fd: int,
        staging_name: str,
        target_name: str,
    ) -> None:
        os.rename(
            staging_name,
            staging_name + "-parked",
            src_dir_fd=root_fd,
            dst_dir_fd=root_fd,
        )
        os.mkdir(staging_name, 0o700, dir_fd=root_fd)
        super()._atomic_rename_no_replace(root_fd, staging_name, target_name)


class _SwapThenFailNoReplacePublisher(DatasetPublisher):
    def _atomic_rename_no_replace(
        self,
        root_fd: int,
        staging_name: str,
        target_name: str,
    ) -> None:
        os.rename(
            staging_name,
            staging_name + "-parked",
            src_dir_fd=root_fd,
            dst_dir_fd=root_fd,
        )
        os.mkdir(staging_name, 0o700, dir_fd=root_fd)
        raise OSError(errno.EIO, "injected rename failure after staging swap")


def test_cleanup_preserves_swapped_staging_after_rename_failure(
    tmp_path: Path,
) -> None:
    root = tmp_path / "datasets"

    with pytest.raises(OSError) as rejected:
        _SwapThenFailNoReplacePublisher(root).publish(_artifacts())

    assert rejected.value.errno == errno.EIO
    assert not _target(root).exists()
    replacement = tuple(root.glob(".*.staging-*"))
    replacement = tuple(path for path in replacement if not path.name.endswith("-parked"))
    parked = tuple(root.glob(".*.staging-*-parked"))
    assert len(replacement) == 1
    assert tuple(replacement[0].iterdir()) == ()
    assert len(parked) == 1
    assert {item.name for item in parked[0].iterdir()} == _ARTIFACT_NAMES


def test_post_rename_verification_rejects_swap_after_identity_check(
    tmp_path: Path,
) -> None:
    root = tmp_path / "datasets"

    with pytest.raises(DatasetPublicationConflict):
        _SwapDuringNoReplacePublisher(root).publish(_artifacts())

    assert _target(root).is_dir()
    assert tuple(_target(root).iterdir()) == ()
    parked = tuple(root.glob(".*.staging-*-parked"))
    assert len(parked) == 1
    assert {item.name for item in parked[0].iterdir()} == _ARTIFACT_NAMES


def test_target_created_in_pre_rename_window_is_never_replaced(tmp_path: Path) -> None:
    root = tmp_path / "datasets"

    with pytest.raises(DatasetPublicationConflict):
        _CreateEmptyTargetPublisher(root).publish(_artifacts())

    target = _target(root)
    assert target.is_dir()
    assert tuple(target.iterdir()) == ()
    _assert_one_staging(root, _ARTIFACT_NAMES)


class _PublishExactWinnerPublisher(DatasetPublisher):
    def __init__(self, root: Path, artifacts: DatasetArtifacts) -> None:
        super().__init__(root)
        self._winner_artifacts = artifacts

    def _before_atomic_rename(self, staging: Path, target: Path) -> None:
        DatasetPublisher(self._root).publish(self._winner_artifacts)


def test_concurrent_exact_winner_becomes_idempotent(tmp_path: Path) -> None:
    root = tmp_path / "datasets"

    result = _PublishExactWinnerPublisher(root, _artifacts()).publish(_artifacts())

    assert result.status is DatasetPublicationStatus.ALREADY_PUBLISHED
    assert result.target == _target(root)
    _assert_one_staging(root, _ARTIFACT_NAMES)


class _SwapRootForSymlinkPublisher(DatasetPublisher):
    def __init__(self, root: Path, outside: Path) -> None:
        super().__init__(root)
        self._outside = outside

    def _after_prepare_root(self) -> None:
        parked = self._root.with_name(self._root.name + "-parked")
        self._root.rename(parked)
        self._root.symlink_to(self._outside, target_is_directory=True)


def test_root_swapped_for_symlink_never_redirects_publication(tmp_path: Path) -> None:
    root = tmp_path / "datasets"
    outside = tmp_path / "outside"
    outside.mkdir(mode=0o700)
    marker = outside / "marker"
    marker.write_bytes(b"preserved")

    with pytest.raises(DatasetPublicationConflict):
        _SwapRootForSymlinkPublisher(root, outside).publish(_artifacts())

    assert marker.read_bytes() == b"preserved"
    assert {item.name for item in outside.iterdir()} == {"marker"}


class _SwapRootForDirectoryPublisher(DatasetPublisher):
    def _after_prepare_root(self) -> None:
        parked = self._root.with_name(self._root.name + "-parked")
        self._root.rename(parked)
        self._root.mkdir(mode=0o700)


def test_root_replaced_by_private_directory_between_stat_and_open_fails_closed(
    tmp_path: Path,
) -> None:
    root = tmp_path / "datasets"

    with pytest.raises(DatasetPublicationConflict):
        _SwapRootForDirectoryPublisher(root).publish(_artifacts())

    assert tuple(root.iterdir()) == ()
    assert tuple((tmp_path / "datasets-parked").iterdir()) == ()


class _SwapPrivateComponentPublisher(DatasetPublisher):
    def _after_private_component_mkdir(
        self,
        parent_fd: int,
        private_name: str,
    ) -> None:
        os.rename(
            private_name,
            private_name + "-parked",
            src_dir_fd=parent_fd,
            dst_dir_fd=parent_fd,
        )
        os.mkdir(private_name, 0o700, dir_fd=parent_fd)


def test_private_component_replaced_after_identity_capture_fails_closed(
    tmp_path: Path,
) -> None:
    root = tmp_path / "datasets"

    with pytest.raises(DatasetPublicationConflict):
        _SwapPrivateComponentPublisher(root).publish(_artifacts())

    assert not root.exists()
    private_entries = tuple(tmp_path.glob(".dataset-root-*"))
    assert len(private_entries) == 2
    assert all(path.is_dir() for path in private_entries)


def test_missing_root_components_are_fsynced_before_publication(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    root = tmp_path / "level-one" / "level-two" / "datasets"
    real_mkdir = os.mkdir
    real_fsync = os.fsync
    events: list[tuple[str, int]] = []
    created: list[tuple[int, int]] = []

    def record_mkdir(
        path: str | bytes | os.PathLike[str] | os.PathLike[bytes],
        mode: int = 0o777,
        *,
        dir_fd: int | None = None,
    ) -> None:
        assert dir_fd is not None
        parent_inode = os.fstat(dir_fd).st_ino
        real_mkdir(path, mode, dir_fd=dir_fd)
        child = os.stat(path, dir_fd=dir_fd, follow_symlinks=False)
        created.append((parent_inode, child.st_ino))
        events.append(("mkdir", child.st_ino))

    def record_fsync(descriptor: int) -> None:
        events.append(("fsync", os.fstat(descriptor).st_ino))
        real_fsync(descriptor)

    monkeypatch.setattr(os, "mkdir", record_mkdir)
    monkeypatch.setattr(os, "fsync", record_fsync)

    assert DatasetPublisher(root).publish(_artifacts()).status is (
        DatasetPublicationStatus.PUBLISHED
    )

    assert len(created) == 4  # three root components plus publication staging
    for parent_inode, child_inode in created[:3]:
        mkdir_index = events.index(("mkdir", child_inode))
        child_fsync = events.index(("fsync", child_inode), mkdir_index + 1)
        parent_fsync = events.index(("fsync", parent_inode), child_fsync + 1)
        assert mkdir_index < child_fsync < parent_fsync


class _MoveRootBeforeRenamePublisher(DatasetPublisher):
    def _before_atomic_rename(self, staging: Path, target: Path) -> None:
        parked = self._root.with_name(self._root.name + "-parked")
        self._root.rename(parked)
        self._root.mkdir(mode=0o700)


def test_root_move_before_rename_fails_closed_and_preserves_anchored_staging(
    tmp_path: Path,
) -> None:
    root = tmp_path / "datasets"
    parked = tmp_path / "datasets-parked"

    with pytest.raises(DatasetPublicationConflict):
        _MoveRootBeforeRenamePublisher(root).publish(_artifacts())

    assert tuple(root.iterdir()) == ()
    assert not (_target(parked)).exists()
    _assert_one_staging(parked, _ARTIFACT_NAMES)


class _MoveRootAfterRenamePublisher(DatasetPublisher):
    def _after_atomic_rename(self) -> None:
        parked = self._root.with_name(self._root.name + "-parked")
        self._root.rename(parked)
        self._root.mkdir(mode=0o700)


def test_root_move_after_rename_never_reports_false_success(tmp_path: Path) -> None:
    root = tmp_path / "datasets"
    parked = tmp_path / "datasets-parked"

    with pytest.raises(DatasetPublicationConflict):
        _MoveRootAfterRenamePublisher(root).publish(_artifacts())

    assert tuple(root.iterdir()) == ()
    assert (_target(parked) / "manifest.json").read_bytes() == _artifacts().manifest_json
    assert not tuple(parked.glob(".*.staging-*"))


def test_hardlinked_existing_artifact_is_not_idempotent(tmp_path: Path) -> None:
    root = tmp_path / "datasets"
    DatasetPublisher(root).publish(_artifacts())
    candles = _target(root) / "candles.ndjson"
    outside_link = tmp_path / "candles-hardlink"
    os.link(candles, outside_link)

    with pytest.raises(DatasetPublicationConflict):
        DatasetPublisher(root).publish(_artifacts())

    assert candles.read_bytes() == _artifacts().candles_ndjson
    assert outside_link.read_bytes() == _artifacts().candles_ndjson


def test_failure_before_rename_leaves_no_target_and_complete_staging(
    tmp_path: Path,
) -> None:
    root = tmp_path / "datasets"

    with pytest.raises(OSError, match="injected before rename"):
        _FailBeforeRenamePublisher(root).publish(_artifacts())

    assert not _target(root).exists()
    _assert_one_staging(root, _ARTIFACT_NAMES)


def test_failure_cleanup_never_removes_staging_or_its_contents(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    root = tmp_path / "datasets"

    def forbid_removal(*args: object, **kwargs: object) -> None:
        raise AssertionError("failure cleanup must not remove filesystem entries")

    monkeypatch.setattr(os, "rmdir", forbid_removal)
    monkeypatch.setattr(os, "unlink", forbid_removal)

    with pytest.raises(OSError, match="injected before rename"):
        _FailBeforeRenamePublisher(root).publish(_artifacts())

    assert not _target(root).exists()
    _assert_one_staging(root, _ARTIFACT_NAMES)


def test_publisher_rejects_non_artifact_input_without_touching_root(
    tmp_path: Path,
) -> None:
    root = tmp_path / "datasets"

    with pytest.raises(TypeError, match="only DatasetArtifacts"):
        DatasetPublisher(root).publish(object())  # type: ignore[arg-type]

    assert not root.exists()


def test_publisher_verifies_artifacts_before_touching_root(tmp_path: Path) -> None:
    root = tmp_path / "datasets"
    artifacts = _artifacts().model_copy(
        update={"candles_ndjson": _artifacts().candles_ndjson + b" "}
    )

    with pytest.raises(
        DatasetArtifactVerificationError,
        match="dataset_artifact_verification_failed",
    ):
        DatasetPublisher(root).publish(artifacts)

    assert not root.exists()


class _FakeRenameFunction:
    def __init__(self, result: int = 0) -> None:
        self.result = result
        self.calls: list[tuple[object, ...]] = []
        self.argtypes: list[object] = []
        self.restype: object = None

    def __call__(self, *args: object) -> int:
        self.calls.append(args)
        return self.result


class _FakeLibc:
    def __init__(self, function_name: str, result: int = 0) -> None:
        setattr(self, function_name, _FakeRenameFunction(result))


@pytest.mark.parametrize(
    ("platform", "function_name", "flag"),
    (("darwin", "renameatx_np", 0x4), ("linux", "renameat2", 0x1)),
)
def test_atomic_no_replace_uses_platform_exclusive_primitive(
    monkeypatch: pytest.MonkeyPatch,
    platform: str,
    function_name: str,
    flag: int,
) -> None:
    libc = _FakeLibc(function_name)
    monkeypatch.setattr(dataset_store.sys, "platform", platform)
    monkeypatch.setattr(dataset_store.ctypes, "CDLL", lambda *args, **kwargs: libc)

    dataset_store._atomic_rename_no_replace(17, "source", "target")

    function = getattr(libc, function_name)
    assert function.calls == [(17, b"source", 17, b"target", flag)]


def test_atomic_no_replace_fails_closed_without_supported_primitive(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setattr(dataset_store.ctypes, "CDLL", lambda *args, **kwargs: object())

    monkeypatch.setattr(dataset_store.sys, "platform", "linux")
    with pytest.raises(OSError) as missing:
        dataset_store._atomic_rename_no_replace(17, "source", "target")
    assert missing.value.errno == errno.ENOTSUP

    monkeypatch.setattr(dataset_store.sys, "platform", "win32")
    with pytest.raises(OSError) as unsupported:
        dataset_store._atomic_rename_no_replace(17, "source", "target")
    assert unsupported.value.errno == errno.ENOTSUP


def test_atomic_no_replace_propagates_native_errno(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    libc = _FakeLibc("renameat2", result=-1)
    monkeypatch.setattr(dataset_store.sys, "platform", "linux")
    monkeypatch.setattr(dataset_store.ctypes, "CDLL", lambda *args, **kwargs: libc)
    monkeypatch.setattr(dataset_store.ctypes, "get_errno", lambda: errno.EEXIST)

    with pytest.raises(OSError) as rejected:
        dataset_store._atomic_rename_no_replace(17, "source", "target")

    assert rejected.value.errno == errno.EEXIST
    assert rejected.value.filename == "target"
