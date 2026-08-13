from datetime import datetime, timedelta, timezone
import json
from pathlib import Path
import re
from copy import deepcopy
import pytest

from app.backtesting.backtrader_contracts import CanonicalBacktestOrderPlan
from app.backtesting.backtrader_feed import VerifiedBacktraderFeedAdapter
from app.backtesting.backtrader_runtime import CanonicalBacktraderRuntime
from app.backtesting.contracts import MarketType
from app.backtesting.dataset import CandleRecord, DatasetBuilder, DatasetSerializer, DatasetSourceIdentity, Timeframe


UTC = timezone.utc
FIXTURE = Path(__file__).parent / "fixtures/backtesting/php-canonical-order-plan.json"


def _plan() -> CanonicalBacktestOrderPlan:
    value = json.loads(FIXTURE.read_text())
    value["timeframe"] = "1m"
    return CanonicalBacktestOrderPlan.model_validate(value)


def _record(index: int, high: str, low: str) -> CandleRecord:
    opened = datetime(2026, 8, 10, 12, index, tzinfo=UTC)
    return CandleRecord(
        source_record_id=f"runtime-bar-{index}", source_network="mainnet",
        market_data_venue="okx", market_type=MarketType.PERPETUAL,
        symbol="BTCUSDT", timeframe=Timeframe.ONE_MINUTE,
        open_at=opened, close_at=opened + timedelta(minutes=1),
        available_at=opened + timedelta(minutes=1), open="100", high=high,
        low=low, close="100", volume="10", complete=True,
    )


def _feed() -> VerifiedBacktraderFeedAdapter:
    records = (_record(0, "101", "99"), _record(1, "103", "99"))
    source = DatasetSourceIdentity(
        source="paper-okx", source_schema_version="paper.v2",
        source_build_version="fixture.v1", source_checksum="sha256:" + "d" * 64,
        source_network="mainnet", market_data_venue="okx",
        market_type=MarketType.PERPETUAL,
    )
    artifacts = DatasetSerializer.serialize(DatasetBuilder(source).build(records))
    return VerifiedBacktraderFeedAdapter(
        artifacts, symbol="BTCUSDT", timeframe="1m",
        period_start=datetime(2026, 8, 10, 12, 0, tzinfo=UTC),
        period_end=datetime(2026, 8, 10, 12, 2, tzinfo=UTC),
    )


def test_runtime_uses_backtrader_and_is_byte_deterministic() -> None:
    feed = _feed()
    plan = _plan().model_copy(update={"dataset_id": feed.dataset_id, "dataset_checksum": feed.dataset_checksum})
    first = CanonicalBacktraderRuntime().run(plan, feed)
    second = CanonicalBacktraderRuntime().run(plan, feed)

    assert first == second
    decoded = json.loads(first)
    assert decoded["engine_version"] == "backtrader-1.9.78.123+canonical-runtime.v1"
    assert decoded["status"] == "closed"
    assert decoded["reason_code"] == "target_filled"
    assert [item["kind"] for item in decoded["events"]] == ["entry_filled", "target_filled"]
    assert decoded["result_is_live_proof"] is False
    assert decoded["input_hash"].startswith("sha256:")
    assert decoded["result_hash"].startswith("sha256:")

    golden = Path(__file__).parent / "fixtures/backtesting/backtrader-runtime-result.json"
    if golden.exists():
        assert first == golden.read_text(encoding="utf-8")


def test_runtime_files_do_not_reimplement_trading_authorities() -> None:
    root = Path(__file__).parents[1] / "app/backtesting"
    source = "\n".join(
        (root / name).read_text()
        for name in ("backtrader_feed.py", "backtrader_execution.py", "backtrader_runtime.py")
    ).lower()
    for forbidden in ("rsi", "macd", "position_sizer", "risk_rate *", "entryzonecalculator"):
        assert re.search(rf"\b{re.escape(forbidden)}\b", source) is None


def test_runtime_rejects_plan_feed_identity_mismatch() -> None:
    forged = deepcopy(json.loads(FIXTURE.read_text()))
    forged["timeframe"] = "5m"
    with pytest.raises(ValueError, match="identity_mismatch"):
        CanonicalBacktraderRuntime().run(CanonicalBacktestOrderPlan.model_validate(forged), _feed())


def test_runtime_revalidates_a_forged_model_instance() -> None:
    feed = _feed()
    plan = _plan().model_copy(
        update={
            "dataset_id": feed.dataset_id,
            "dataset_checksum": feed.dataset_checksum,
            "plan": _plan().plan.model_copy(update={"stop_price": 101.0}),
        }
    )
    with pytest.raises(ValueError, match="plan_hash_mismatch"):
        CanonicalBacktraderRuntime().run(plan, feed)
