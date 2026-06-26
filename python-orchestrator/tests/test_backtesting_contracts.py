from __future__ import annotations

from datetime import datetime, timezone
from math import nan

import pytest
from pydantic import ValidationError

from app.backtesting.contracts import (
    BacktestRunRequest,
    BacktestTradeLedgerEntry,
    DatasetDescriptor,
    Direction,
    EffectiveConfigSnapshot,
    IntraBarPolicy,
    MarketType,
    OrderType,
    Profile,
)


def _dt(value: str) -> datetime:
    return datetime.fromisoformat(value).replace(tzinfo=timezone.utc)


def _dataset() -> DatasetDescriptor:
    return DatasetDescriptor(
        dataset_id="ds_btc_2026_01",
        source="fixture",
        exchange="fake",
        market_type=MarketType.PERPETUAL,
        symbols=("BTCUSDT", "ETHUSDT"),
        timeframes=("1m", "5m", "15m"),
        start_at=_dt("2026-01-01T00:00:00"),
        end_at=_dt("2026-01-31T00:00:00"),
        missing_ranges=(),
        quality_flags=(),
        build_version="dataset-builder-v1",
        checksum="sha256:" + "a" * 64,
    )


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
