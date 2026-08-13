from __future__ import annotations

from datetime import datetime, timedelta, timezone
from copy import deepcopy
import hashlib
import stat
import sys
import textwrap
from pathlib import Path
from typing import Literal

import pytest
from pydantic import ValidationError

from app.backtesting.dataset import (
    CandleRecord,
    DatasetArtifacts,
    DatasetBuilder,
    DatasetSerializer,
    DatasetSourceIdentity,
    Timeframe,
)
from app.backtesting.contracts import MarketType
from app.backtesting.indicator_bridge import (
    BacktestIndicatorBridge,
    CanonicalIndicatorDatasetBinding,
    CanonicalIndicatorProjectionRequest,
    CanonicalIndicatorProjectionResult,
    CanonicalProjectedIndicatorSnapshot,
    IndicatorBridgeError,
    VerifiedIndicatorWindowBuilder,
)
from app.backtesting.tradingcore_bridge import CanonicalIndicatorSnapshot
from app.modern_trading_contracts import _canonical_json


UTC = timezone.utc
EVALUATED_AT = "2026-02-12T20:00:00.000000Z"


def _record(
    venue: Literal["okx", "hyperliquid"],
    network: Literal["mainnet", "testnet"],
    timeframe: Timeframe,
    index: int,
    *,
    count: int,
    symbol: str = "BTCUSDT",
    available_delay: timedelta = timedelta(0),
) -> CandleRecord:
    # The 1h fixture has 1,004 records so its freshest 1,000-record suffix is
    # aligned to a UTC four-hour boundary.
    start = datetime(2026, 1, 1, tzinfo=UTC)
    if timeframe is not Timeframe.ONE_HOUR:
        start = datetime(2026, 2, 1, tzinfo=UTC)
    open_at = start + index * timeframe.duration
    close_at = open_at + timeframe.duration
    base = 30_000 + index
    source_record_id = hashlib.sha256(
        f"{venue}-{network}-{symbol}-{timeframe.value}-{index:05d}".encode()
    ).hexdigest()
    return CandleRecord(
        source_record_id=source_record_id,
        source_network=network,
        market_data_venue=venue,
        market_type=MarketType.PERPETUAL,
        symbol=symbol,
        timeframe=timeframe,
        open_at=open_at,
        close_at=close_at,
        available_at=close_at + available_delay,
        open=str(base),
        high=str(base + 3),
        low=str(base - 2),
        close=str(base + 1),
        volume=str(10 + (index % 17)),
        complete=True,
    )


def _artifacts(
    venue: Literal["okx", "hyperliquid"] = "okx",
    network: Literal["mainnet", "testnet"] = "mainnet",
    *,
    hourly_count: int = 1004,
    include_four_hour_source: bool = False,
) -> DatasetArtifacts:
    source = DatasetSourceIdentity(
        source=f"paper-{venue}",
        source_schema_version="paper-public-candles.v2",
        source_build_version="fixture.v1",
        source_checksum="sha256:" + "d" * 64,
        source_network=network,
        market_data_venue=venue,
        market_type=MarketType.PERPETUAL,
    )
    records: list[CandleRecord] = []
    for timeframe in (
        Timeframe.ONE_MINUTE,
        Timeframe.FIVE_MINUTES,
        Timeframe.FIFTEEN_MINUTES,
    ):
        records.extend(
            _record(venue, network, timeframe, index, count=255)
            for index in range(255)
        )
    records.extend(
        _record(venue, network, Timeframe.ONE_HOUR, index, count=hourly_count)
        for index in range(hourly_count)
    )
    if include_four_hour_source:
        records.extend(
            _record(venue, network, Timeframe.FOUR_HOURS, index, count=250)
            for index in range(250)
        )
    return DatasetSerializer.serialize(DatasetBuilder(source).build(records))


def test_indicator_bridge_public_api_exists() -> None:
    assert BacktestIndicatorBridge.DEFAULT_TIMEOUT_SECONDS == 15.0
    assert CanonicalIndicatorProjectionRequest is not None
    assert CanonicalIndicatorProjectionResult is not None
    assert IndicatorBridgeError is not None
    assert VerifiedIndicatorWindowBuilder is not None


def test_backtesting_package_exports_indicator_bridge_api() -> None:
    import app.backtesting as package

    assert package.BacktestIndicatorBridge is BacktestIndicatorBridge
    assert package.CanonicalIndicatorProjectionRequest is CanonicalIndicatorProjectionRequest
    assert package.CanonicalIndicatorProjectionResult is CanonicalIndicatorProjectionResult
    assert package.IndicatorBridgeError is IndicatorBridgeError
    assert package.VerifiedIndicatorWindowBuilder is VerifiedIndicatorWindowBuilder


def test_request_model_is_strict_frozen_and_duration_ordered() -> None:
    artifacts = _artifacts()
    request = VerifiedIndicatorWindowBuilder().build(
        artifacts,
        request_id="projection-1",
        symbol="BTCUSDT",
        requested_timeframes=("1m", "1h", "4h"),
        evaluated_at=EVALUATED_AT,
        environment="test",
    )
    with pytest.raises(ValidationError):
        request.symbol = "ETHUSDT"  # type: ignore[misc]
    payload = request.model_dump(mode="json")
    payload["unexpected"] = True
    with pytest.raises(ValidationError):
        CanonicalIndicatorProjectionRequest.model_validate(payload)
    for invalid in (("5m", "1m"), ("1m", "1m"), ("1m", "30m"), ()):
        payload = request.model_dump(mode="json")
        payload["requested_timeframes"] = invalid
        with pytest.raises(ValidationError, match="requested_timeframes"):
            CanonicalIndicatorProjectionRequest.model_validate(payload)


@pytest.mark.parametrize(
    "mutate",
    [
        lambda value: value["candles_by_timeframe"]["1m"].pop(),
        lambda value: value["candles_by_timeframe"]["1m"].__setitem__(0, "bad"),
        lambda value: value["candles_by_timeframe"]["1m"][0].update(extra=True),
        lambda value: value["candles_by_timeframe"]["1m"][0].update(
            source_record_id="not-a-hash"
        ),
        lambda value: value["candles_by_timeframe"]["1m"][0].update(volume="-1"),
        lambda value: value["candles_by_timeframe"]["1m"][0].update(
            source_network="testnet"
        ),
        lambda value: value["candles_by_timeframe"]["1m"][0].update(
            available_at="2026-02-13T00:00:00.000000Z"
        ),
        lambda value: value["candles_by_timeframe"]["1m"][1].update(
            open_at="2026-02-01T04:16:00.000000Z",
            close_at="2026-02-01T04:17:00.000000Z",
            available_at="2026-02-01T04:17:00.000000Z",
        ),
        lambda value: value["candles_by_timeframe"].update(
            {"4h": value["candles_by_timeframe"]["1h"][-250:]}
        ),
    ],
)
def test_request_model_rejects_forged_candle_windows(mutate) -> None:
    payload = _request().model_dump(mode="json")
    mutate(payload)
    with pytest.raises(ValidationError, match="canonical_indicator"):
        CanonicalIndicatorProjectionRequest.model_validate(payload)


@pytest.mark.parametrize(
    ("venue", "network"),
    [("okx", "mainnet"), ("hyperliquid", "testnet")],
)
def test_verified_builder_binds_descriptor_and_selects_exact_freshest_windows(
    venue: Literal["okx", "hyperliquid"],
    network: Literal["mainnet", "testnet"],
) -> None:
    artifacts = _artifacts(venue, network)
    request = VerifiedIndicatorWindowBuilder().build(
        artifacts,
        request_id=f"projection-{venue}",
        symbol="BTCUSDT",
        requested_timeframes=("1m", "5m", "15m", "1h", "4h"),
        evaluated_at=EVALUATED_AT,
        environment="local",
    )

    assert request.dataset_binding.model_dump(mode="json") == {
        "dataset_id": artifacts.descriptor.dataset_id,
        "dataset_checksum": artifacts.descriptor.dataset_checksum,
        "candles_checksum": artifacts.descriptor.candles_checksum,
        "quality_report_checksum": artifacts.descriptor.quality_report_checksum,
        "source_checksum": artifacts.descriptor.source_checksum,
        "source_network": network,
        "market_data_venue": venue,
        "market_type": "perpetual",
    }
    windows = request.model_dump(mode="json")["candles_by_timeframe"]
    assert list(windows) == ["1m", "5m", "15m", "1h"]
    assert [len(windows[key]) for key in windows] == [250, 250, 250, 1000]
    assert windows["1m"][0]["open_at"] == "2026-02-01T00:05:00.000000Z"
    assert windows["1h"][0]["open_at"] == "2026-01-01T04:00:00.000000Z"
    assert windows["1h"][-1]["close_at"] <= EVALUATED_AT
    assert windows["1h"][-1]["available_at"] <= EVALUATED_AT
    assert "4h" not in windows


def test_builder_verifies_artifacts_before_parsing_or_slicing(monkeypatch: pytest.MonkeyPatch) -> None:
    artifacts = _artifacts()
    observed: list[DatasetArtifacts] = []

    def reject_first(cls: type[DatasetSerializer], value: DatasetArtifacts):
        observed.append(value)
        raise RuntimeError("verification-sentinel")

    monkeypatch.setattr(DatasetSerializer, "verify", classmethod(reject_first))
    with pytest.raises(RuntimeError, match="verification-sentinel"):
        VerifiedIndicatorWindowBuilder().build(
            artifacts,
            request_id="projection-verify-first",
            symbol="BTCUSDT",
            requested_timeframes=("1m",),
            evaluated_at=EVALUATED_AT,
            environment="test",
        )
    assert observed == [artifacts]


def test_builder_sanitizes_corrupt_artifact_failure() -> None:
    artifacts = _artifacts()
    tampered = artifacts.model_copy(
        update={"candles_ndjson": artifacts.candles_ndjson.replace(b'"30000"', b'"99999"', 1)}
    )
    with pytest.raises(IndicatorBridgeError, match="indicator_bridge_dataset_invalid$"):
        VerifiedIndicatorWindowBuilder().build(
            tampered,
            request_id="projection-corrupt",
            symbol="BTCUSDT",
            requested_timeframes=("1m",),
            evaluated_at=EVALUATED_AT,
            environment="test",
        )


def test_builder_rejects_insufficient_hourly_coverage_and_never_uses_source_4h() -> None:
    artifacts = _artifacts(hourly_count=999, include_four_hour_source=True)
    with pytest.raises(IndicatorBridgeError, match="indicator_bridge_window_insufficient"):
        VerifiedIndicatorWindowBuilder().build(
            artifacts,
            request_id="projection-short",
            symbol="BTCUSDT",
            requested_timeframes=("4h",),
            evaluated_at=EVALUATED_AT,
            environment="test",
        )
    misaligned = _artifacts(hourly_count=1001)
    with pytest.raises(IndicatorBridgeError, match="four_hour_alignment_invalid"):
        VerifiedIndicatorWindowBuilder().build(
            misaligned,
            request_id="projection-misaligned",
            symbol="BTCUSDT",
            requested_timeframes=("4h",),
            evaluated_at=EVALUATED_AT,
            environment="test",
        )


def test_builder_rejects_wrong_boundary_types_and_uncertifiable_symbol() -> None:
    builder = VerifiedIndicatorWindowBuilder()
    with pytest.raises(TypeError, match="dataset_artifacts_required"):
        builder.build(  # type: ignore[arg-type]
            object(), request_id="x", symbol="BTCUSDT",
            requested_timeframes=("1m",), evaluated_at=EVALUATED_AT,
            environment="test",
        )
    with pytest.raises(IndicatorBridgeError, match="symbol_invalid"):
        builder.build(
            _artifacts(), request_id="x", symbol="SOLUSDT",
            requested_timeframes=("1m",), evaluated_at=EVALUATED_AT,
            environment="test",
        )


def test_strict_models_reject_coercion_and_forged_binding() -> None:
    request_payload = _request().model_dump(mode="json")
    for field in ("schema_version", "request_id", "environment", "symbol"):
        forged = deepcopy(request_payload)
        forged[field] = 1
        with pytest.raises(ValidationError):
            CanonicalIndicatorProjectionRequest.model_validate(forged)
    for invalid in (1, [1], {}):
        forged = deepcopy(request_payload)
        forged["candles_by_timeframe"] = invalid
        with pytest.raises(ValidationError):
            CanonicalIndicatorProjectionRequest.model_validate(forged)
    for invalid in (("1m", 1), "1m"):
        forged = deepcopy(request_payload)
        forged["requested_timeframes"] = invalid
        with pytest.raises(ValidationError, match="requested_timeframes_invalid"):
            CanonicalIndicatorProjectionRequest.model_validate(forged)
    forged = deepcopy(request_payload)
    forged["evaluated_at"] = "2026-02-30T00:00:00.000000Z"
    with pytest.raises(ValidationError, match="evaluated_at_invalid"):
        CanonicalIndicatorProjectionRequest.model_validate(forged)
    with pytest.raises(ValidationError, match="dataset_binding_invalid"):
        CanonicalIndicatorDatasetBinding.model_validate(
            {**request_payload["dataset_binding"], "dataset_id": "backtest-dataset-" + "f" * 64}
        )
    with pytest.raises(ValidationError, match="dataset_binding_invalid"):
        CanonicalIndicatorDatasetBinding.model_validate(
            {**request_payload["dataset_binding"], "source_checksum": 1}
        )


def test_snapshot_and_result_models_reject_malformed_shapes() -> None:
    request = _request()
    payload = _result_payload(request)
    snapshot = payload["snapshots_by_timeframe"]["1m"]
    for invalid in ([], {"timeframe": "1m"}):
        forged = deepcopy(snapshot)
        forged["snapshot_identity"] = invalid
        with pytest.raises(ValidationError, match="snapshot_identity_invalid"):
            CanonicalProjectedIndicatorSnapshot.model_validate(forged)
    forged = deepcopy(snapshot)
    forged["window_hash"] = 1
    with pytest.raises(ValidationError, match="snapshot_string_type_invalid"):
        CanonicalProjectedIndicatorSnapshot.model_validate(forged)
    for invalid in ([], {}, {"1m": snapshot}):
        forged_result = deepcopy(payload)
        forged_result["snapshots_by_timeframe"] = invalid
        if invalid == {"1m": snapshot}:
            forged_result["requested_timeframes"] = ["1m", "4h"]
        with pytest.raises(ValidationError, match="snapshots_shape_invalid"):
            CanonicalIndicatorProjectionResult.model_validate(forged_result)
    forged_result = deepcopy(payload)
    forged_result["request_id"] = 1
    with pytest.raises(ValidationError, match="result_string_type_invalid"):
        CanonicalIndicatorProjectionResult.model_validate(forged_result)


def _request(
    venue: Literal["okx", "hyperliquid"] = "okx",
    network: Literal["mainnet", "testnet"] = "mainnet",
) -> CanonicalIndicatorProjectionRequest:
    return VerifiedIndicatorWindowBuilder().build(
        _artifacts(venue, network),
        request_id=f"projection-{venue}",
        symbol="BTCUSDT",
        requested_timeframes=("1m", "1h", "4h"),
        evaluated_at=EVALUATED_AT,
        environment="test",
    )


def _golden_request(
    venue: Literal["okx", "hyperliquid"],
    network: Literal["mainnet", "testnet"],
) -> CanonicalIndicatorProjectionRequest:
    return VerifiedIndicatorWindowBuilder().build(
        _artifacts(venue, network),
        request_id=f"golden-{venue}",
        symbol="BTCUSDT",
        requested_timeframes=("4h",),
        evaluated_at=EVALUATED_AT,
        environment="test",
    )


def _hash(payload: dict) -> str:
    return "sha256:" + hashlib.sha256(_canonical_json(payload).encode()).hexdigest()


def _result_payload(request: CanonicalIndicatorProjectionRequest) -> dict:
    windows = request.model_dump(mode="json")["candles_by_timeframe"]
    kline_times = {
        "1m": windows["1m"][-1]["open_at"],
        "1h": windows["1h"][-1]["open_at"],
        "4h": windows["1h"][-4]["open_at"],
    }
    snapshots = {}
    for timeframe in request.requested_timeframes:
        snapshots[timeframe] = {
            "close": 31_000.5,
            "snapshot_identity": {
                "timeframe": timeframe,
                "symbol": request.symbol,
                "exchange": "fake",
                "environment": request.environment,
                "market_type": "perpetual",
            },
            "kline_time": kline_times[timeframe],
            "window_hash": "sha256:" + ("a" if timeframe != "4h" else "b") * 64,
            "indicator_engine_version": "php_fallback_v1",
        }
    payload = {
        "schema_version": "canonical-indicator-projection-result.v1",
        "request_id": request.request_id,
        "evaluated_at": request.evaluated_at,
        "environment": request.environment,
        "indicator_engine_version": "php_fallback_v1",
        "dataset_binding": request.dataset_binding.model_dump(mode="json"),
        "symbol": request.symbol,
        "requested_timeframes": list(request.requested_timeframes),
        "snapshots_by_timeframe": snapshots,
        "input_hash": request.input_hash(),
    }
    payload["result_hash"] = _hash(payload)
    return payload


def _script(tmp_path: Path, source: str, *, name: str = "child.py") -> str:
    path = tmp_path / name
    path.write_text("#!/usr/bin/env python3\n" + textwrap.dedent(source))
    path.chmod(path.stat().st_mode | stat.S_IXUSR)
    return str(path)


def test_result_is_frozen_strict_hash_bound_and_rule_snapshot_compatible() -> None:
    request = _request()
    payload = _result_payload(request)
    result = CanonicalIndicatorProjectionResult.model_validate(payload)
    with pytest.raises(ValidationError):
        result.symbol = "ETHUSDT"  # type: ignore[misc]
    assert tuple(result.snapshots_by_timeframe) == request.requested_timeframes
    for snapshot in result.snapshots_by_timeframe.values():
        CanonicalIndicatorSnapshot.model_validate(dict(snapshot))

    forged = deepcopy(payload)
    forged["snapshots_by_timeframe"]["1m"]["close"] = 1.0
    with pytest.raises(ValidationError, match="result_hash"):
        CanonicalIndicatorProjectionResult.model_validate(forged)


def test_bridge_uses_fixed_shell_free_argv_and_canonical_stdin(tmp_path: Path) -> None:
    request = _request()
    response = _canonical_json(_result_payload(request))
    script = _script(
        tmp_path,
        f"""
        import json, sys
        payload = sys.stdin.buffer.read()
        json.loads(payload)
        sys.stdout.write({response!r} + "\\n")
        """,
    )
    bridge = BacktestIndicatorBridge((sys.executable, script))
    result = bridge.project(request)
    assert bridge.argv == (sys.executable, script)
    assert result.input_hash == request.input_hash()
    assert BacktestIndicatorBridge().argv[-3:] == (
        "app:backtest:indicators:project",
        "--no-interaction",
        "--no-ansi",
    )


def test_bridge_rejects_invalid_argv_and_request_type() -> None:
    for argv in ((), ("",), (1,)):  # type: ignore[list-item]
        with pytest.raises(ValueError, match="indicator_bridge_argv_invalid"):
            BacktestIndicatorBridge(argv)  # type: ignore[arg-type]
    with pytest.raises(TypeError, match="projection_request_required"):
        BacktestIndicatorBridge(("php",)).project(object())  # type: ignore[arg-type]


@pytest.mark.parametrize(
    ("source", "reason"),
    [
        ("import sys; sys.exit(2)", "process_failed"),
        ("print('not-json')", "result_invalid"),
        ("print('{} {}')", "result_invalid"),
        ("print('[]')", "result_invalid"),
        ("print('{\"a\":1,\"a\":2}')", "result_invalid"),
        ("import sys; sys.stdout.buffer.write(b'\\xff')", "result_invalid"),
        ("pass", "result_invalid"),
    ],
)
def test_bridge_rejects_process_and_strict_json_failures(
    source: str, reason: str, tmp_path: Path
) -> None:
    script = _script(tmp_path, source)
    with pytest.raises(IndicatorBridgeError, match=f"indicator_bridge_{reason}$"):
        BacktestIndicatorBridge((sys.executable, script)).project(_request())


def test_bridge_handles_missing_executable_timeout_and_independent_bounds(
    tmp_path: Path,
) -> None:
    request = _request()
    with pytest.raises(IndicatorBridgeError, match="process_unavailable"):
        BacktestIndicatorBridge((str(tmp_path / "missing"),)).project(request)
    sleeper = _script(tmp_path, "import time; time.sleep(2)", name="sleep.py")
    with pytest.raises(IndicatorBridgeError, match="timeout"):
        BacktestIndicatorBridge(
            (sys.executable, sleeper), timeout_seconds=0.05
        ).project(request)
    noisy = _script(tmp_path, "print('x' * 10000)", name="stdout.py")
    with pytest.raises(IndicatorBridgeError, match="output_too_large"):
        BacktestIndicatorBridge(
            (sys.executable, noisy), max_output_bytes=100
        ).project(request)
    noisy_err = _script(
        tmp_path,
        "import sys; sys.stderr.write('sensitive-child-data' * 1000)",
        name="stderr.py",
    )
    with pytest.raises(IndicatorBridgeError, match="output_too_large") as failure:
        BacktestIndicatorBridge(
            (sys.executable, noisy_err), max_output_bytes=100
        ).project(request)
    assert "sensitive-child-data" not in str(failure.value)


def test_bridge_rejects_oversized_input_before_starting_process(tmp_path: Path) -> None:
    request = _request().model_copy(update={"request_id": "x" * (8 * 1024 * 1024)})
    with pytest.raises(IndicatorBridgeError, match="indicator_bridge_input_too_large$"):
        BacktestIndicatorBridge((str(tmp_path / "must-not-start"),)).project(request)


@pytest.mark.parametrize("timeout", [float("nan"), float("inf"), True, "15"])
def test_bridge_rejects_invalid_timeout(timeout: object) -> None:
    with pytest.raises(ValueError, match="indicator_bridge_bounds_invalid"):
        BacktestIndicatorBridge(("php",), timeout_seconds=timeout)  # type: ignore[arg-type]


@pytest.mark.parametrize(
    "limit", [float("nan"), float("inf"), True, 1.5, 8 * 1024 * 1024 + 1]
)
def test_bridge_rejects_invalid_output_limit(limit: object) -> None:
    with pytest.raises(ValueError, match="indicator_bridge_bounds_invalid"):
        BacktestIndicatorBridge(("php",), max_output_bytes=limit)  # type: ignore[arg-type]


@pytest.mark.parametrize(
    ("mutate", "reason"),
    [
        (lambda value: value.update(symbol="ETHUSDT"), "result_invalid"),
        (
            lambda value: value["dataset_binding"].update(
                source_network="testnet"
            ),
            "result_identity_mismatch",
        ),
        (
            lambda value: value.update(input_hash="sha256:" + "f" * 64),
            "result_identity_mismatch",
        ),
        (
            lambda value: value["snapshots_by_timeframe"]["1m"].update(
                window_hash="forged"
            ),
            "result_invalid",
        ),
        (
            lambda value: value["snapshots_by_timeframe"]["1m"][
                "snapshot_identity"
            ].update(exchange="okx"),
            "result_invalid",
        ),
    ],
)
def test_bridge_rejects_forged_hashes_and_identity(
    mutate, reason: str, tmp_path: Path
) -> None:
    request = _request()
    payload = _result_payload(request)
    mutate(payload)
    payload.pop("result_hash")
    payload["result_hash"] = _hash(payload)
    script = _script(tmp_path, f"print({_canonical_json(payload)!r})")
    with pytest.raises(IndicatorBridgeError, match=f"indicator_bridge_{reason}$"):
        BacktestIndicatorBridge((sys.executable, script)).project(request)


def test_bridge_rejects_kline_identity_drift(tmp_path: Path) -> None:
    request = _request()
    payload = _result_payload(request)
    payload["snapshots_by_timeframe"]["1m"]["kline_time"] = (
        payload["snapshots_by_timeframe"]["1h"]["kline_time"]
    )
    payload.pop("result_hash")
    payload["result_hash"] = _hash(payload)
    script = _script(tmp_path, f"print({_canonical_json(payload)!r})")
    with pytest.raises(IndicatorBridgeError, match="result_identity_mismatch"):
        BacktestIndicatorBridge((sys.executable, script)).project(request)


@pytest.mark.parametrize(
    ("venue", "network"),
    [("okx", "mainnet"), ("hyperliquid", "testnet")],
)
def test_real_symfony_projection_is_byte_deterministic_and_rule_compatible(
    venue: Literal["okx", "hyperliquid"],
    network: Literal["mainnet", "testnet"],
) -> None:
    request = _golden_request(venue, network)
    bridge = BacktestIndicatorBridge()
    request_bytes = _canonical_json(request.model_dump(mode="json")).encode()
    first = bridge._run_bounded(request_bytes)
    second = bridge._run_bounded(request_bytes)
    assert first == second
    assert first[0] == 0
    assert first[2] == b""
    projected = bridge.project(request)
    assert projected == bridge.project(request)
    for snapshot in projected.snapshots_by_timeframe.values():
        CanonicalIndicatorSnapshot.model_validate(dict(snapshot))
