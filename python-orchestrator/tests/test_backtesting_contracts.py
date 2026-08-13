from __future__ import annotations

import hashlib
import json
from copy import deepcopy
from datetime import datetime, timedelta, timezone
from math import nan

import pytest
from pydantic import ValidationError

import app.backtesting.contracts as backtesting_contracts
from app.backtesting.contracts import (
    BacktestRunRequest,
    BacktestTradeLedgerEntry,
    DatasetDescriptor,
    Direction,
    IntraBarPolicy,
    MarketType,
    OrderType,
)
from app.modern_trading_contracts import (
    CanonicalEffectiveConfigSnapshot,
    ModernTradingIdentity,
    calculate_config_hash,
    calculate_snapshot_hash,
)


def _dt(value: str) -> datetime:
    return datetime.fromisoformat(value).replace(tzinfo=timezone.utc)


def _manifest() -> dict[str, object]:
    manifest = {
        "schema_version": "backtest-dataset-manifest.v1",
        "record_schema_version": "backtest-candle.v1",
        "quality_report_schema_version": "backtest-dataset-quality.v1",
        "build_version": "backtest-dataset-builder.v1",
        "source": {
            "source": "fixture",
            "source_schema_version": "fixture-candles.v1",
            "source_build_version": "fixture-builder.v1",
            "source_checksum": "sha256:" + "d" * 64,
            "source_network": "fake",
            "market_data_venue": "fake",
            "market_type": "perpetual",
        },
        "coverage": {
            "symbols": ["BTCUSDT", "ETHUSDT"],
            "timeframes": ["1m", "5m", "15m"],
            "start_at": "2026-01-01T00:00:00.000000Z",
            "end_at": "2026-01-31T00:00:00.000000Z",
            "record_count": 63360,
            "streams": [
                {
                    "first_open_at": "2026-01-01T00:00:00.000000Z",
                    "last_close_at": "2026-01-31T00:00:00.000000Z",
                    "market_data_venue": "fake",
                    "market_type": "perpetual",
                    "record_count": 43200,
                    "symbol": "BTCUSDT",
                    "timeframe": "1m",
                },
                {
                    "first_open_at": "2026-01-01T00:00:00.000000Z",
                    "last_close_at": "2026-01-31T00:00:00.000000Z",
                    "market_data_venue": "fake",
                    "market_type": "perpetual",
                    "record_count": 8640,
                    "symbol": "BTCUSDT",
                    "timeframe": "5m",
                },
                {
                    "first_open_at": "2026-01-01T00:00:00.000000Z",
                    "last_close_at": "2026-01-31T00:00:00.000000Z",
                    "market_data_venue": "fake",
                    "market_type": "perpetual",
                    "record_count": 2880,
                    "symbol": "BTCUSDT",
                    "timeframe": "15m",
                },
                {
                    "first_open_at": "2026-01-01T00:00:00.000000Z",
                    "last_close_at": "2026-01-31T00:00:00.000000Z",
                    "market_data_venue": "fake",
                    "market_type": "perpetual",
                    "record_count": 8640,
                    "symbol": "ETHUSDT",
                    "timeframe": "5m",
                },
            ],
        },
        "quality_flags": [],
        "artifacts": {
            "candles.ndjson": "sha256:" + "b" * 64,
            "quality-report.json": "sha256:" + "c" * 64,
        },
        "dataset_checksum": "",
        "dataset_id": "",
    }
    return _bind_manifest(manifest)


def _bind_manifest(manifest: dict[str, object]) -> dict[str, object]:
    manifest_core = {
        key: value
        for key, value in manifest.items()
        if key not in {"artifacts", "dataset_checksum", "dataset_id"}
    }
    checksum_payload = json.dumps(
        {
            "candles_checksum": manifest["artifacts"]["candles.ndjson"],
            "manifest_core": manifest_core,
            "quality_report_checksum": manifest["artifacts"]["quality-report.json"],
        },
        ensure_ascii=False,
        separators=(",", ":"),
        sort_keys=True,
    ).encode("utf-8")
    checksum = "sha256:" + hashlib.sha256(checksum_payload).hexdigest()
    manifest["dataset_checksum"] = checksum
    manifest["dataset_id"] = "backtest-dataset-" + checksum.removeprefix("sha256:")
    return manifest


def _dataset() -> DatasetDescriptor:
    return DatasetDescriptor.from_manifest(_manifest())


def _modern_identity(**overrides: str) -> ModernTradingIdentity:
    payload = {
        "mode_id": "scalping",
        "mode_version": "1.1.0",
        "setup_id": "scalping.pullback.long",
        "setup_version": "1.1.0",
        "exchange": "fake",
        "environment": "test",
        "side": "long",
    }
    payload.update(overrides)
    return ModernTradingIdentity(**payload)


def _canonical_config(
    identity: ModernTradingIdentity | None = None,
    *,
    executable: bool = True,
    blockers: tuple[str, ...] = (),
    marker: str = "baseline",
    condition_catalog_hash: str = "sha256:" + "b" * 64,
    execution_capability: str = "backtest",
) -> CanonicalEffectiveConfigSnapshot:
    identity = identity or _modern_identity()
    request = {**identity.model_dump(), "execution_capability": execution_capability}
    config = {
        "schema_version": "effective-trading-config.v2",
        "units": {
            "percent": "percentage_points",
            "duration": "iso8601",
            "price": "quote_price",
            "notional": "quote_notional",
        },
        "safety": {
            "mainnet_write_enabled": False,
            "demo_testnet_write_enabled": False,
            "require_stop_loss": True,
            "kill_switch_enabled": True,
        },
        "mode": {
            "mode_id": identity.mode_id,
            "mode_version": identity.mode_version,
            "marker": marker,
        },
        "setup": {
            "setup_id": identity.setup_id,
            "setup_version": identity.setup_version,
            "side": identity.side,
        },
        "exchange": {"id": identity.exchange},
        "environment": {"id": identity.environment},
    }
    layers = [
        {"type": kind, "name": kind, "path": f"/{kind}.yaml", "required": True}
        for kind in ("base", "mode", "setup", "exchange", "mode_exchange", "environment")
    ]
    payload = {
        "request": request,
        "config": config,
        "config_hash": calculate_config_hash(config, condition_catalog_hash),
        "condition_catalog_hash": condition_catalog_hash,
        "ordered_layers": layers,
        "ordered_files": [layer["path"] for layer in layers],
        "provenance": {"mode.mode_id": deepcopy(layers[1])},
        "executable": executable,
        "blockers": list(blockers),
    }
    payload["snapshot_hash"] = calculate_snapshot_hash(payload)
    return CanonicalEffectiveConfigSnapshot(**payload)


def _modern_run_payload(**overrides: object) -> dict[str, object]:
    identity = _modern_identity()
    payload: dict[str, object] = {
        "dataset": _dataset(),
        "identity": identity,
        "config": _canonical_config(identity),
        "symbols": ("BTCUSDT",),
        "timeframes": ("1m", "5m"),
        "period_start": _dt("2026-01-02T00:00:00"),
        "period_end": _dt("2026-01-03T00:00:00"),
        "git_commit_sha": "12c9a9fbe369b49afd3d98e495991a21381e8b7b",
        "engine_version": "backtest-contracts-v1",
        "random_seed": 191,
        "cost_model_version": "net-cost-v1",
    }
    payload.update(overrides)
    return payload


def test_modern_run_request_accepts_exact_executable_identity_snapshot() -> None:
    request = BacktestRunRequest(**_modern_run_payload())

    assert request.identity.model_dump() == request.config.request.model_dump(
        exclude={"execution_capability"}
    )
    assert request.config.request.execution_capability == "backtest"


def test_legacy_profile_contracts_and_payloads_are_rejected() -> None:
    assert not hasattr(backtesting_contracts, "Profile")
    assert not hasattr(backtesting_contracts, "EffectiveConfigSnapshot")

    with pytest.raises(ValidationError, match="extra_forbidden"):
        BacktestRunRequest(**_modern_run_payload(profile="scalper"))


def test_run_request_requires_exact_identity_snapshot_equality() -> None:
    with pytest.raises(ValidationError, match="backtest_identity_snapshot_mismatch"):
        BacktestRunRequest(
            **_modern_run_payload(
                identity=_modern_identity(
                    setup_id="scalping.trend_momentum.short", side="short"
                )
            )
        )


def test_run_request_requires_executable_unblocked_backtest_snapshot() -> None:
    blocked = _canonical_config(executable=False, blockers=("mode_blocked",))
    with pytest.raises(ValidationError, match="backtest_config_not_executable"):
        BacktestRunRequest(**_modern_run_payload(config=blocked))

    paper = _canonical_config(execution_capability="paper")
    with pytest.raises(ValidationError, match="backtest_execution_capability_required"):
        BacktestRunRequest(**_modern_run_payload(config=paper))


def test_run_market_data_venue_is_independent_from_simulated_exchange() -> None:
    manifest = _manifest()
    manifest["source"]["market_data_venue"] = "okx"  # type: ignore[index]
    manifest["source"]["source_network"] = "okx-demo"  # type: ignore[index]
    for stream in manifest["coverage"]["streams"]:  # type: ignore[index]
        stream["market_data_venue"] = "okx"
    dataset = DatasetDescriptor.from_manifest(_bind_manifest(manifest))

    request = BacktestRunRequest(**_modern_run_payload(dataset=dataset))

    assert request.dataset.market_data_venue == "okx"
    assert request.identity.exchange == "fake"


@pytest.mark.parametrize(
    ("target", "field", "value"),
    (
        ("identity", "mode_id", "day_trading"),
        ("identity", "mode_version", "1.0.0"),
        ("identity", "setup_id", "scalping.trend_momentum.short"),
        ("identity", "setup_version", "1.0.0"),
        ("identity", "exchange", "okx"),
        ("identity", "environment", "local"),
        ("identity", "side", "short"),
        ("config", "config_hash", "sha256:" + "c" * 64),
        ("config", "condition_catalog_hash", "sha256:" + "c" * 64),
        ("config", "snapshot_hash", "sha256:" + "c" * 64),
    ),
)
def test_fingerprint_binds_every_identity_and_hash_field(
    target: str, field: str, value: str
) -> None:
    request = BacktestRunRequest(**_modern_run_payload())
    if target == "identity":
        altered = request.model_copy(
            update={"identity": request.identity.model_copy(update={field: value})}
        )
    else:
        altered = request.model_copy(
            update={"config": request.config.model_copy(update={field: value})}
        )

    assert altered.reproducibility_fingerprint() != request.reproducibility_fingerprint()


def _modern_ledger_payload(**overrides: object) -> dict[str, object]:
    identity = _modern_identity()
    config = _canonical_config(identity)
    payload: dict[str, object] = {
        "backtest_run_id": "bt_191",
        "dataset_id": "ds_btc_2026_01",
        **identity.model_dump(),
        "config_hash": config.config_hash,
        "condition_catalog_hash": config.condition_catalog_hash,
        "snapshot_hash": config.snapshot_hash,
        "git_commit_sha": "12c9a9fbe369b49afd3d98e495991a21381e8b7b",
        "market_type": MarketType.PERPETUAL,
        "symbol": "BTCUSDT",
        "direction": Direction.LONG,
        "signal_at": _dt("2026-01-02T00:00:00"),
        "entry_order_type": OrderType.MAKER,
        "entry_price": 100.0,
        "entry_quantity": 1.0,
        "initial_stop": 98.0,
        "gross_pnl_usdt": 5.0,
        "net_pnl_usdt": 4.2,
        "pnl_r": 2.1,
        "fee_usdt": 0.2,
        "spread_cost_usdt": 0.1,
        "slippage_cost_usdt": 0.1,
        "funding_usdt": -0.4,
        "quality_flags": (),
    }
    payload.update(overrides)
    return payload


def test_ledger_carries_exact_identity_and_rejects_direction_divergence() -> None:
    entry = BacktestTradeLedgerEntry(**_modern_ledger_payload())
    restored = BacktestTradeLedgerEntry.model_validate(entry.model_dump(round_trip=True))
    assert entry.setup_id == "scalping.pullback.long"
    assert entry.condition_catalog_hash.startswith("sha256:")
    assert entry.snapshot_hash.startswith("sha256:")
    assert restored == entry

    with pytest.raises(ValidationError, match="backtest_ledger_direction_side_mismatch"):
        BacktestTradeLedgerEntry(
            **_modern_ledger_payload(direction=Direction.SHORT, initial_stop=102.0)
        )


def test_ledger_rejects_legacy_profile_and_unknown_identity_fields() -> None:
    with pytest.raises(ValidationError, match="extra_forbidden"):
        BacktestTradeLedgerEntry(**_modern_ledger_payload(profile="scalper"))


def _ledger_entry(**overrides: object) -> BacktestTradeLedgerEntry:
    return BacktestTradeLedgerEntry(**_modern_ledger_payload(**overrides))


def test_run_request_fingerprint_is_stable_for_same_inputs() -> None:
    request = BacktestRunRequest(**_modern_run_payload())

    same_request = BacktestRunRequest.model_validate(request.model_dump(round_trip=True))

    assert request.reproducibility_fingerprint() == same_request.reproducibility_fingerprint()
    assert request.intra_bar_policy is IntraBarPolicy.CONSERVATIVE_STOP_FIRST
    assert request.result_is_live_proof is False


def test_run_request_rejects_dataset_and_period_escape() -> None:
    with pytest.raises(ValidationError, match="symbols must be contained in dataset"):
        BacktestRunRequest(**_modern_run_payload(symbols=("SOLUSDT",), timeframes=("1m",)))

    with pytest.raises(ValidationError, match="period must stay inside dataset bounds"):
        BacktestRunRequest(
            **_modern_run_payload(
                timeframes=("1m",),
                period_start=_dt("2025-12-31T00:00:00"),
            )
        )


def test_run_request_rejects_non_utc_datetimes_cleanly() -> None:
    naive_start = datetime.fromisoformat("2026-01-02T00:00:00")

    with pytest.raises(ValidationError, match="datetime must be UTC-aware"):
        BacktestRunRequest(**_modern_run_payload(period_start=naive_start))


def test_sequence_fields_reject_scalar_strings_and_non_string_items() -> None:
    with pytest.raises(ValidationError, match="must be a sequence of strings"):
        DatasetDescriptor(
            **{
                **_dataset().model_dump(),
                "symbols": "BTCUSDT",
            }
        )

    with pytest.raises(ValidationError, match="must contain only strings"):
        DatasetDescriptor(
            **{
                **_dataset().model_dump(),
                "timeframes": ("1m", None),
            }
        )

    with pytest.raises(ValidationError, match="must be a sequence of strings"):
        BacktestRunRequest(**_modern_run_payload(symbols="BTCUSDT", timeframes=("1m",)))

    with pytest.raises(ValidationError, match="must be a sequence of strings"):
        DatasetDescriptor(
            **{
                **_dataset().model_dump(),
                "symbols": {"BTCUSDT": False},
            }
        )

    with pytest.raises(ValidationError, match="must be an ordered sequence of strings"):
        DatasetDescriptor(
            **{
                **_dataset().model_dump(),
                "symbols": {"BTCUSDT", "ETHUSDT"},
            }
        )


def test_trade_ledger_entry_requires_stop_and_net_cost_components() -> None:
    entry = _ledger_entry()

    assert entry.total_known_cost_usdt == pytest.approx(0.8)
    assert entry.result_is_live_proof is False

    with pytest.raises(ValidationError, match="initial_stop is required"):
        BacktestTradeLedgerEntry(
            **{**entry.model_dump(round_trip=True), "initial_stop": None}
        )


def test_trade_ledger_entry_rejects_non_finite_values() -> None:
    with pytest.raises(ValidationError):
        _ledger_entry(initial_stop=nan)


def test_trade_ledger_entry_rejects_inconsistent_net_pnl() -> None:
    with pytest.raises(ValidationError, match="net_pnl_usdt must equal gross_pnl_usdt minus known costs"):
        _ledger_entry(net_pnl_usdt=5.0)


@pytest.mark.parametrize(
    ("updates", "message"),
    (
        ({"end_at": _dt("2026-01-01T00:00:00")}, "end_at must be after"),
        ({"symbols": ("ETHUSDT", "BTCUSDT")}, "symbols must be unique and sorted"),
        ({"timeframes": ("30m",)}, "unsupported timeframe"),
        ({"timeframes": ("5m", "1m")}, "duration-sorted"),
        ({"dataset_id": "backtest-dataset-" + "0" * 64}, "must derive"),
        ({"source": "changed"}, "does not bind descriptor facts"),
    ),
)
def test_descriptor_semantic_invariants_fail_closed(
    updates: dict[str, object],
    message: str,
) -> None:
    forged = _dataset().model_copy(update=updates)

    with pytest.raises(ValueError, match=message):
        forged._validate_bounds()


@pytest.mark.parametrize(
    ("mutation", "message"),
    (
        (("top", None), "manifest fields"),
        (("source", None), "manifest source"),
        (("coverage", None), "manifest coverage"),
        (("artifacts", None), "manifest artifacts"),
        (("dataset_id", 7), "string scalar"),
        (("record_count", True), "record_count must be an integer"),
        (("symbols", ["BTCUSDT", 7]), "symbols must be an array of strings"),
        (("quality_flags", [7]), "quality_flags must be an array of strings"),
    ),
)
def test_descriptor_manifest_shape_rejects_python_coercions(
    mutation: tuple[str, object],
    message: str,
) -> None:
    manifest = deepcopy(_manifest())
    field, value = mutation
    if field == "top":
        manifest["unexpected"] = value
    elif field == "source":
        manifest["source"] = value
    elif field == "coverage":
        manifest["coverage"] = value
    elif field == "artifacts":
        manifest["artifacts"] = value
    elif field == "record_count":
        manifest["coverage"][field] = value  # type: ignore[index]
    elif field == "symbols":
        manifest["coverage"][field] = value  # type: ignore[index]
    else:
        manifest[field] = value

    with pytest.raises(ValueError, match=message):
        DatasetDescriptor.from_manifest(manifest)


def test_run_scope_rejects_timeframe_and_period_order_escape() -> None:
    valid = BacktestRunRequest(**_modern_run_payload(timeframes=("1m",)))

    with pytest.raises(ValidationError, match="timeframes must be contained"):
        BacktestRunRequest(**{**valid.model_dump(round_trip=True), "timeframes": ("4h",)})
    with pytest.raises(ValidationError, match="period_end must be after"):
        BacktestRunRequest(
            **{
                **valid.model_dump(round_trip=True),
                "period_end": valid.period_start,
            }
        )


def test_contracts_reject_non_utc_offset_and_invalid_stops() -> None:
    with pytest.raises(ValidationError, match="datetime must be UTC-aware"):
        DatasetDescriptor(
            **{
                **_dataset().model_dump(),
                "start_at": datetime(2026, 1, 1, tzinfo=timezone(timedelta(hours=1))),
            }
        )

    with pytest.raises(ValidationError, match="long initial_stop must be below"):
        _ledger_entry(initial_stop=100.0)
    with pytest.raises(ValidationError, match="short initial_stop must be above"):
        _ledger_entry(
            setup_id="scalping.trend_momentum.short",
            side="short",
            direction=Direction.SHORT,
            initial_stop=99.0,
        )


def test_run_request_requires_each_exact_symbol_timeframe_stream() -> None:
    payload = _modern_run_payload(symbols=("ETHUSDT",), timeframes=("5m",))

    assert BacktestRunRequest(**payload).symbols == ("ETHUSDT",)
    with pytest.raises(ValidationError, match="stream is not in dataset"):
        BacktestRunRequest(**{**payload, "timeframes": ("1m",)})
    with pytest.raises(ValidationError, match="stream is not in dataset"):
        BacktestRunRequest(
            **{
                **payload,
                "symbols": ("BTCUSDT", "ETHUSDT"),
                "timeframes": ("1m", "5m"),
            }
        )


def test_run_request_period_must_fit_every_selected_stream() -> None:
    manifest = _manifest()
    eth_stream = manifest["coverage"]["streams"][3]  # type: ignore[index]
    eth_stream["first_open_at"] = "2026-01-10T00:00:00.000000Z"
    eth_stream["last_close_at"] = "2026-01-20T00:00:00.000000Z"
    eth_stream["record_count"] = 2880
    manifest["coverage"]["record_count"] = 57600  # type: ignore[index]
    dataset = DatasetDescriptor.from_manifest(_bind_manifest(manifest))
    payload = _modern_run_payload(
        dataset=dataset,
        symbols=("ETHUSDT",),
        timeframes=("5m",),
        period_start=_dt("2026-01-10T00:00:00"),
        period_end=_dt("2026-01-20T00:00:00"),
    )

    assert BacktestRunRequest(**payload).period_end == _dt("2026-01-20T00:00:00")
    with pytest.raises(ValidationError, match="each requested stream bounds"):
        BacktestRunRequest(
            **{
                **payload,
                "period_start": _dt("2026-01-09T23:59:00"),
            }
        )


@pytest.mark.parametrize(
    ("update", "message"),
    (
        ({"record_count": 43201}, "duration must equal record_count"),
        (
            {"first_open_at": _dt("2026-01-01T00:00:30")},
            "must align to UTC timeframe grid",
        ),
    ),
)
def test_stream_coverage_constructor_rejects_duration_or_grid_forgery(
    update: dict[str, object],
    message: str,
) -> None:
    stream = _dataset().streams[0].model_copy(update=update)

    with pytest.raises(ValueError, match=message):
        stream._validate_bounds()
