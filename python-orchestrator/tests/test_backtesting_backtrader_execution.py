from datetime import datetime, timedelta, timezone
import json
from pathlib import Path

import pytest

from app.backtesting.backtrader_contracts import CanonicalBacktestOrderPlan
from app.backtesting.backtrader_execution import BacktestExecutionError, execute_plan
from app.backtesting.backtrader_feed import VerifiedBacktraderBar


UTC = timezone.utc
FIXTURE = Path(__file__).parent / "fixtures/backtesting/php-canonical-order-plan.json"


def _plan() -> CanonicalBacktestOrderPlan:
    value = json.loads(FIXTURE.read_text())
    value["timeframe"] = "1m"
    return CanonicalBacktestOrderPlan.model_validate(value)


def _bar(index: int, *, high: float, low: float, opened: float = 100.0) -> VerifiedBacktraderBar:
    start = datetime(2026, 8, 10, 12, index, tzinfo=UTC)
    return VerifiedBacktraderBar(
        source_record_id=f"bar-{index}", open_at=start,
        close_at=start + timedelta(minutes=1), available_at=start + timedelta(minutes=1),
        open=opened, high=high, low=low, close=opened, volume=10.0,
    )


def test_limit_fill_attaches_stop_then_target_closes() -> None:
    result = execute_plan(_plan(), (_bar(0, high=101, low=99), _bar(1, high=103, low=99)))
    assert result.status == "closed"
    assert [event.kind for event in result.events] == ["entry_filled", "target_filled"]
    assert result.events[0].stop_price == 98.4
    assert result.events[0].quantity == 2.497
    assert result.events[1].price == 102.6


def test_same_bar_stop_and_target_is_stop_first() -> None:
    result = execute_plan(_plan(), (_bar(0, high=101, low=99), _bar(1, high=103, low=98)))
    assert result.reason_code == "conservative_stop_first"
    assert result.events[-1].kind == "stop_filled"
    assert result.events[-1].price == 98.4


def test_unfilled_plan_expires_without_trade() -> None:
    result = execute_plan(_plan(), (_bar(0, high=100, low=99), _bar(1, high=100, low=99), _bar(2, high=100, low=99)))
    assert result.status == "not_executed"
    assert result.reason_code == "entry_expired"
    assert result.events == ()


def test_open_position_at_dataset_end_fails_closed() -> None:
    with pytest.raises(BacktestExecutionError, match="position_open_at_dataset_end"):
        execute_plan(_plan(), (_bar(0, high=101, low=99),))


def test_bar_crossing_plan_expiry_is_ambiguous() -> None:
    bar = _bar(2, high=101, low=99)
    bar = VerifiedBacktraderBar(**{**bar.__dict__, "close_at": bar.close_at + timedelta(minutes=1), "available_at": bar.available_at + timedelta(minutes=1)})
    with pytest.raises(BacktestExecutionError, match="entry_window_ambiguous"):
        execute_plan(_plan(), (bar,))


def test_bar_crossing_plan_creation_cannot_retroactively_fill() -> None:
    bar = _bar(0, high=101, low=99)
    bar = VerifiedBacktraderBar(
        **{
            **bar.__dict__,
            "open_at": bar.open_at - timedelta(minutes=1),
            "close_at": bar.open_at,
            "available_at": bar.open_at,
        }
    )
    result = execute_plan(_plan(), (bar,))
    assert result.status == "not_executed"
    assert result.reason_code == "entry_not_filled"
