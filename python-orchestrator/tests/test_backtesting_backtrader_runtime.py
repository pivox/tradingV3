from datetime import datetime, timedelta, timezone
import json
from pathlib import Path
import re

from app.backtesting.backtrader_contracts import CanonicalBacktestOrderPlan
from app.backtesting.backtrader_feed import VerifiedBacktraderFeedAdapter
from app.backtesting.backtrader_runtime import CanonicalBacktraderRuntime
from app.backtesting.contracts import MarketType
from app.backtesting.dataset import CandleRecord, Timeframe


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
    return VerifiedBacktraderFeedAdapter(
        (_record(0, "101", "99"), _record(1, "103", "99")),
        dataset_id="backtest-dataset-" + "a" * 64,
        dataset_checksum="sha256:" + "a" * 64,
        period_start=datetime(2026, 8, 10, 12, 0, tzinfo=UTC),
        period_end=datetime(2026, 8, 10, 12, 2, tzinfo=UTC),
    )


def test_runtime_uses_backtrader_and_is_byte_deterministic() -> None:
    first = CanonicalBacktraderRuntime().run(_plan(), _feed())
    second = CanonicalBacktraderRuntime().run(_plan(), _feed())

    assert first == second
    decoded = json.loads(first)
    assert decoded["engine_version"] == "backtrader-1.9.78.123+canonical-runtime.v1"
    assert decoded["status"] == "closed"
    assert decoded["reason_code"] == "target_filled"
    assert [item["kind"] for item in decoded["events"]] == ["entry_filled", "target_filled"]
    assert decoded["result_is_live_proof"] is False
    assert decoded["input_hash"].startswith("sha256:")
    assert decoded["result_hash"].startswith("sha256:")


def test_runtime_files_do_not_reimplement_trading_authorities() -> None:
    root = Path(__file__).parents[1] / "app/backtesting"
    source = "\n".join(
        (root / name).read_text()
        for name in ("backtrader_feed.py", "backtrader_execution.py", "backtrader_runtime.py")
    ).lower()
    for forbidden in ("rsi", "macd", "position_sizer", "risk_rate *", "entryzonecalculator"):
        assert re.search(rf"\b{re.escape(forbidden)}\b", source) is None
