from __future__ import annotations

import hashlib
import json
from copy import deepcopy
from datetime import datetime, timedelta, timezone
from math import inf, nan

import pytest
from pydantic import ValidationError

from app.backtesting.contracts import (
    BacktestRunRequest,
    BacktestTradeLedgerEntry,
    DatasetDescriptor,
    Direction,
    EffectiveConfigSnapshot,
    FrozenDict,
    IntraBarPolicy,
    MarketType,
    OrderType,
    Profile,
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
            "record_count": 100,
            "streams": [
                {
                    "first_open_at": "2026-01-01T00:00:00.000000Z",
                    "last_close_at": "2026-01-31T00:00:00.000000Z",
                    "market_data_venue": "fake",
                    "market_type": "perpetual",
                    "record_count": 40,
                    "symbol": "BTCUSDT",
                    "timeframe": "1m",
                },
                {
                    "first_open_at": "2026-01-01T00:00:00.000000Z",
                    "last_close_at": "2026-01-31T00:00:00.000000Z",
                    "market_data_venue": "fake",
                    "market_type": "perpetual",
                    "record_count": 30,
                    "symbol": "BTCUSDT",
                    "timeframe": "5m",
                },
                {
                    "first_open_at": "2026-01-01T00:00:00.000000Z",
                    "last_close_at": "2026-01-31T00:00:00.000000Z",
                    "market_data_venue": "fake",
                    "market_type": "perpetual",
                    "record_count": 20,
                    "symbol": "BTCUSDT",
                    "timeframe": "15m",
                },
                {
                    "first_open_at": "2026-01-01T00:00:00.000000Z",
                    "last_close_at": "2026-01-31T00:00:00.000000Z",
                    "market_data_venue": "fake",
                    "market_type": "perpetual",
                    "record_count": 10,
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


def _config(profile: Profile = Profile.SCALPER) -> EffectiveConfigSnapshot:
    return EffectiveConfigSnapshot(
        profile=profile,
        config_hash="sha256:" + "b" * 64,
        config_version="effective-config-v1",
        source_layers=("base", f"mode/{profile.value}", "exchange/fake"),
        effective_config={
            "risk": {"risk_pct": 0.01},
            "entry": {"maker_first": True},
        },
    )


def _ledger_entry(**overrides: object) -> BacktestTradeLedgerEntry:
    payload = {
        "backtest_run_id": "bt_191",
        "dataset_id": "ds_btc_2026_01",
        "config_hash": "sha256:" + "b" * 64,
        "git_commit_sha": "12c9a9fbe369b49afd3d98e495991a21381e8b7b",
        "profile": Profile.SCALPER,
        "exchange": "fake",
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
    return BacktestTradeLedgerEntry(**payload)


def test_run_request_fingerprint_is_stable_for_same_inputs() -> None:
    request = BacktestRunRequest(
        dataset=_dataset(),
        config=_config(),
        profile=Profile.SCALPER,
        symbols=("BTCUSDT",),
        timeframes=("1m", "5m"),
        period_start=_dt("2026-01-02T00:00:00"),
        period_end=_dt("2026-01-03T00:00:00"),
        git_commit_sha="12c9a9fbe369b49afd3d98e495991a21381e8b7b",
        engine_version="backtest-contracts-v1",
        random_seed=191,
        cost_model_version="net-cost-v1",
    )

    same_request = BacktestRunRequest.model_validate(request.model_dump())

    assert request.reproducibility_fingerprint() == same_request.reproducibility_fingerprint()
    assert request.intra_bar_policy is IntraBarPolicy.CONSERVATIVE_STOP_FIRST
    assert request.result_is_live_proof is False


def test_run_request_rejects_profile_mismatch_and_dataset_escape() -> None:
    with pytest.raises(ValidationError, match="config profile must match run profile"):
        BacktestRunRequest(
            dataset=_dataset(),
            config=_config(Profile.REGULAR),
            profile=Profile.SCALPER,
            symbols=("BTCUSDT",),
            timeframes=("1m",),
            period_start=_dt("2026-01-02T00:00:00"),
            period_end=_dt("2026-01-03T00:00:00"),
            git_commit_sha="12c9a9fbe369b49afd3d98e495991a21381e8b7b",
            engine_version="backtest-contracts-v1",
            random_seed=191,
            cost_model_version="net-cost-v1",
        )

    with pytest.raises(ValidationError, match="symbols must be contained in dataset"):
        BacktestRunRequest(
            dataset=_dataset(),
            config=_config(),
            profile=Profile.SCALPER,
            symbols=("SOLUSDT",),
            timeframes=("1m",),
            period_start=_dt("2026-01-02T00:00:00"),
            period_end=_dt("2026-01-03T00:00:00"),
            git_commit_sha="12c9a9fbe369b49afd3d98e495991a21381e8b7b",
            engine_version="backtest-contracts-v1",
            random_seed=191,
            cost_model_version="net-cost-v1",
        )

    with pytest.raises(ValidationError, match="period must stay inside dataset bounds"):
        BacktestRunRequest(
            dataset=_dataset(),
            config=_config(),
            profile=Profile.SCALPER,
            symbols=("BTCUSDT",),
            timeframes=("1m",),
            period_start=_dt("2025-12-31T00:00:00"),
            period_end=_dt("2026-01-03T00:00:00"),
            git_commit_sha="12c9a9fbe369b49afd3d98e495991a21381e8b7b",
            engine_version="backtest-contracts-v1",
            random_seed=191,
            cost_model_version="net-cost-v1",
        )


def test_effective_config_snapshot_deep_freezes_payload() -> None:
    payload = {
        "risk": {"risk_pct": 0.01},
        "entry": {"modes": ["maker"]},
    }

    snapshot = EffectiveConfigSnapshot(
        profile=Profile.SCALPER,
        config_hash="sha256:" + "b" * 64,
        config_version="effective-config-v1",
        source_layers=("base", "mode/scalper"),
        effective_config=payload,
    )
    payload["risk"]["risk_pct"] = 0.99
    payload["entry"]["modes"].append("taker")

    assert snapshot.effective_config["risk"]["risk_pct"] == 0.01
    assert snapshot.effective_config["entry"]["modes"] == ("maker",)
    with pytest.raises(TypeError):
        snapshot.effective_config["risk"]["risk_pct"] = 0.02


def test_run_request_rejects_non_utc_datetimes_cleanly() -> None:
    naive_start = datetime.fromisoformat("2026-01-02T00:00:00")

    with pytest.raises(ValidationError, match="datetime must be UTC-aware"):
        BacktestRunRequest(
            dataset=_dataset(),
            config=_config(),
            profile=Profile.SCALPER,
            symbols=("BTCUSDT",),
            timeframes=("1m",),
            period_start=naive_start,
            period_end=_dt("2026-01-03T00:00:00"),
            git_commit_sha="12c9a9fbe369b49afd3d98e495991a21381e8b7b",
            engine_version="backtest-contracts-v1",
            random_seed=191,
            cost_model_version="net-cost-v1",
        )


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
        EffectiveConfigSnapshot(
            **{
                **_config().model_dump(),
                "source_layers": "base",
            }
        )

    with pytest.raises(ValidationError, match="must be a sequence of strings"):
        BacktestRunRequest(
            dataset=_dataset(),
            config=_config(),
            profile=Profile.SCALPER,
            symbols="BTCUSDT",
            timeframes=("1m",),
            period_start=_dt("2026-01-02T00:00:00"),
            period_end=_dt("2026-01-03T00:00:00"),
            git_commit_sha="12c9a9fbe369b49afd3d98e495991a21381e8b7b",
            engine_version="backtest-contracts-v1",
            random_seed=191,
            cost_model_version="net-cost-v1",
        )

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


def test_effective_config_snapshot_rejects_non_json_collections() -> None:
    with pytest.raises(ValidationError, match="effective_config must contain JSON-compatible values"):
        EffectiveConfigSnapshot(
            profile=Profile.SCALPER,
            config_hash="sha256:" + "b" * 64,
            config_version="effective-config-v1",
            source_layers=("base", "mode/scalper"),
            effective_config={"entry": {"modes": {"maker", "taker"}}},
        )


@pytest.mark.parametrize("value", (nan, inf))
def test_effective_config_snapshot_rejects_non_finite_floats(value: float) -> None:
    with pytest.raises(ValidationError, match="effective_config must contain finite floats"):
        EffectiveConfigSnapshot(
            profile=Profile.SCALPER,
            config_hash="sha256:" + "b" * 64,
            config_version="effective-config-v1",
            source_layers=("base", "mode/scalper"),
            effective_config={"risk": {"risk_pct": value}},
        )


def test_trade_ledger_entry_requires_stop_and_net_cost_components() -> None:
    entry = _ledger_entry()

    assert entry.total_known_cost_usdt == pytest.approx(0.8)
    assert entry.result_is_live_proof is False

    with pytest.raises(ValidationError, match="initial_stop is required"):
        BacktestTradeLedgerEntry(
            **{**entry.model_dump(), "initial_stop": None}
        )


def test_trade_ledger_entry_rejects_non_finite_values() -> None:
    with pytest.raises(ValidationError):
        _ledger_entry(initial_stop=nan)


def test_trade_ledger_entry_rejects_inconsistent_net_pnl() -> None:
    with pytest.raises(ValidationError, match="net_pnl_usdt must equal gross_pnl_usdt minus known costs"):
        _ledger_entry(net_pnl_usdt=5.0)


@pytest.mark.parametrize(
    ("value", "message"),
    (
        (None, "at least 1 item"),
        (191, "must be a sequence of strings"),
    ),
)
def test_sequence_normalization_rejects_missing_or_non_iterable_layers(
    value: object,
    message: str,
) -> None:
    with pytest.raises(ValidationError, match=message):
        EffectiveConfigSnapshot(
            profile=Profile.SCALPER,
            config_hash="sha256:" + "b" * 64,
            config_version="effective-config-v1",
            source_layers=value,  # type: ignore[arg-type]
            effective_config={"risk": {"risk_pct": 0.01}},
        )


def test_config_freeze_accepts_frozen_values_and_rejects_unknown_objects() -> None:
    frozen = FrozenDict({"entry": {"modes": ("maker",)}})
    snapshot = EffectiveConfigSnapshot(
        profile=Profile.SCALPER,
        config_hash="sha256:" + "b" * 64,
        config_version="effective-config-v1",
        source_layers=("base",),
        effective_config=frozen,
    )

    assert snapshot.model_dump(mode="json")["effective_config"] == {
        "entry": {"modes": ["maker"]}
    }
    with pytest.raises(ValidationError, match="JSON-compatible values"):
        EffectiveConfigSnapshot(
            profile=Profile.SCALPER,
            config_hash="sha256:" + "b" * 64,
            config_version="effective-config-v1",
            source_layers=("base",),
            effective_config={"bad": object()},
        )
    with pytest.raises(ValidationError, match="must be a mapping"):
        EffectiveConfigSnapshot(
            profile=Profile.SCALPER,
            config_hash="sha256:" + "b" * 64,
            config_version="effective-config-v1",
            source_layers=("base",),
            effective_config=("not", "a", "mapping"),  # type: ignore[arg-type]
        )


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
    valid = BacktestRunRequest(
        dataset=_dataset(),
        config=_config(),
        profile=Profile.SCALPER,
        symbols=("BTCUSDT",),
        timeframes=("1m",),
        period_start=_dt("2026-01-02T00:00:00"),
        period_end=_dt("2026-01-03T00:00:00"),
        git_commit_sha="12c9a9fbe369b49afd3d98e495991a21381e8b7b",
        engine_version="backtest-contracts-v1",
        random_seed=191,
        cost_model_version="net-cost-v1",
    )

    with pytest.raises(ValidationError, match="timeframes must be contained"):
        BacktestRunRequest(**{**valid.model_dump(), "timeframes": ("4h",)})
    with pytest.raises(ValidationError, match="period_end must be after"):
        BacktestRunRequest(
            **{
                **valid.model_dump(),
                "period_end": valid.period_start,
            }
        )


def test_contracts_reject_non_utc_offset_empty_config_and_invalid_stops() -> None:
    with pytest.raises(ValidationError, match="datetime must be UTC-aware"):
        DatasetDescriptor(
            **{
                **_dataset().model_dump(),
                "start_at": datetime(2026, 1, 1, tzinfo=timezone(timedelta(hours=1))),
            }
        )

    empty_layers = _config().model_copy(update={"source_layers": ()})
    with pytest.raises(ValueError, match="source_layers must not be empty"):
        empty_layers._validate_config()
    empty_config = _config().model_copy(update={"effective_config": FrozenDict({})})
    with pytest.raises(ValueError, match="effective_config must not be empty"):
        empty_config._validate_config()

    with pytest.raises(ValidationError, match="long initial_stop must be below"):
        _ledger_entry(initial_stop=100.0)
    with pytest.raises(ValidationError, match="short initial_stop must be above"):
        _ledger_entry(direction=Direction.SHORT, initial_stop=99.0)


def test_run_request_requires_each_exact_symbol_timeframe_stream() -> None:
    payload = {
        "dataset": _dataset(),
        "config": _config(),
        "profile": Profile.SCALPER,
        "symbols": ("ETHUSDT",),
        "timeframes": ("5m",),
        "period_start": _dt("2026-01-02T00:00:00"),
        "period_end": _dt("2026-01-03T00:00:00"),
        "git_commit_sha": "12c9a9fbe369b49afd3d98e495991a21381e8b7b",
        "engine_version": "backtest-contracts-v1",
        "random_seed": 191,
        "cost_model_version": "net-cost-v1",
    }

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
    dataset = DatasetDescriptor.from_manifest(_bind_manifest(manifest))
    payload = {
        "dataset": dataset,
        "config": _config(),
        "profile": Profile.SCALPER,
        "symbols": ("ETHUSDT",),
        "timeframes": ("5m",),
        "period_start": _dt("2026-01-10T00:00:00"),
        "period_end": _dt("2026-01-20T00:00:00"),
        "git_commit_sha": "12c9a9fbe369b49afd3d98e495991a21381e8b7b",
        "engine_version": "backtest-contracts-v1",
        "random_seed": 191,
        "cost_model_version": "net-cost-v1",
    }

    assert BacktestRunRequest(**payload).period_end == _dt("2026-01-20T00:00:00")
    with pytest.raises(ValidationError, match="each requested stream bounds"):
        BacktestRunRequest(
            **{
                **payload,
                "period_start": _dt("2026-01-09T23:59:00"),
            }
        )
