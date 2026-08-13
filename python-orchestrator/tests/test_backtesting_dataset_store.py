from __future__ import annotations

import json
import os
import stat
from pathlib import Path

import pytest

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


class _FailBeforeRenamePublisher(DatasetPublisher):
    def _before_atomic_rename(self, staging: Path, target: Path) -> None:
        raise OSError("injected before rename")


class _CreateEmptyTargetPublisher(DatasetPublisher):
    def _before_atomic_rename(self, staging: Path, target: Path) -> None:
        target.mkdir(mode=0o700)


def test_target_created_in_pre_rename_window_is_never_replaced(tmp_path: Path) -> None:
    root = tmp_path / "datasets"

    with pytest.raises(DatasetPublicationConflict):
        _CreateEmptyTargetPublisher(root).publish(_artifacts())

    target = _target(root)
    assert target.is_dir()
    assert tuple(target.iterdir()) == ()
    assert not tuple(root.glob(".*.staging-*"))


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
    assert not tuple(root.glob(".*.staging-*"))


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


def test_failure_before_rename_leaves_no_target_or_staging(tmp_path: Path) -> None:
    root = tmp_path / "datasets"

    with pytest.raises(OSError, match="injected before rename"):
        _FailBeforeRenamePublisher(root).publish(_artifacts())

    assert not _target(root).exists()
    assert not tuple(root.glob(".*.staging-*"))


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
