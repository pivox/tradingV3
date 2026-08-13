from datetime import datetime, timedelta, timezone
from decimal import Decimal
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
    assert result.events[1].price == Decimal("102.6")


def test_same_bar_stop_and_target_is_stop_first() -> None:
    result = execute_plan(_plan(), (_bar(0, high=101, low=99), _bar(1, high=103, low=98)))
    assert result.reason_code == "conservative_stop_first"
    assert result.events[-1].kind == "stop_filled"
    assert result.events[-1].price == Decimal("98.4")


def test_sub_float_high_does_not_trigger_target_early() -> None:
    almost = _bar(1, high=Decimal("102.59999999999999999"), low=99)
    exact = _bar(2, high=Decimal("102.6"), low=99)
    result = execute_plan(_plan(), (_bar(0, high=101, low=99), almost, exact))

    assert result.reason_code == "target_filled"
    assert result.events[-1].source_record_id == "bar-2"


def test_entry_bar_touching_stop_attaches_then_executes_stop_conservatively() -> None:
    result = execute_plan(_plan(), (_bar(0, high=103, low=98),))
    assert [event.kind for event in result.events] == ["entry_filled", "stop_filled"]
    assert result.reason_code == "conservative_stop_first"
    assert result.events[0].stop_price == 98.4
    assert result.events[1].price == Decimal("98.4")


def test_stop_without_target_uses_stop_reason() -> None:
    result = execute_plan(_plan(), (_bar(0, high=101, low=99), _bar(1, high=101, low=98)))
    assert result.reason_code == "stop_filled"


def test_unfilled_plan_expires_without_trade() -> None:
    result = execute_plan(_plan(), (_bar(0, high=100, low=99), _bar(1, high=100, low=99), _bar(2, high=100, low=99)))
    assert result.status == "not_executed"
    assert result.reason_code == "entry_expired"
    assert result.events == ()


def test_late_delivered_pre_expiry_bar_cannot_fill_after_entry_deadline() -> None:
    delayed = _bar(0, high=101, low=99)
    delayed = VerifiedBacktraderBar(
        **{
            **delayed.__dict__,
            "available_at": datetime(2026, 8, 10, 12, 4, tzinfo=UTC),
        }
    )

    result = execute_plan(_plan(), (delayed,))

    assert result.reason_code == "entry_expired"
    assert result.events == ()


def test_open_position_at_dataset_end_fails_closed() -> None:
    with pytest.raises(BacktestExecutionError, match="position_open_at_dataset_end"):
        execute_plan(_plan(), (_bar(0, high=101, low=99),))


def test_bar_crossing_plan_expiry_is_ambiguous() -> None:
    bar = _bar(2, high=101, low=99)
    bar = VerifiedBacktraderBar(**{**bar.__dict__, "close_at": bar.close_at + timedelta(minutes=1), "available_at": bar.available_at + timedelta(minutes=1)})
    result = execute_plan(_plan(), (bar,))
    assert result.reason_code == "entry_expired"
    assert result.events == ()


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


def test_holding_boundary_closes_at_next_bar_open() -> None:
    envelope = _plan()
    envelope = envelope.model_copy(
        update={
            "plan": envelope.plan.model_copy(
                update={"holding_expires_at": "2026-08-10T12:01:00.000000+00:00"}
            )
        }
    )
    result = execute_plan(envelope, (_bar(0, high=101, low=99), _bar(1, high=101, low=99, opened=100.5)))
    assert result.reason_code == "holding_expired"
    assert result.events[-1].price == 100.5


def test_holding_expiry_preserves_exact_dataset_open() -> None:
    envelope = _plan().model_copy(
        update={
            "plan": _plan().plan.model_copy(
                update={"holding_expires_at": "2026-08-10T12:01:00.000000+00:00"}
            )
        }
    )
    precise_open = Decimal("100.09999999999999999")

    result = execute_plan(
        envelope,
        (_bar(0, high=101, low=99), _bar(1, high=101, low=99, opened=precise_open)),
    )

    assert result.events[-1].price == precise_open
    assert isinstance(result.events[-1].price, Decimal)


def test_bar_straddling_holding_boundary_is_ambiguous() -> None:
    envelope = _plan().model_copy(
        update={
            "plan": _plan().plan.model_copy(
                update={"holding_expires_at": "2026-08-10T12:01:30.000000+00:00"}
            )
        }
    )
    with pytest.raises(BacktestExecutionError, match="holding_window_ambiguous"):
        execute_plan(envelope, (_bar(0, high=101, low=99), _bar(1, high=103, low=99)))


def test_bar_available_before_creation_is_ignored_and_late_bar_expires() -> None:
    early = _bar(0, high=101, low=99)
    early = VerifiedBacktraderBar(**{**early.__dict__, "available_at": early.open_at - timedelta(seconds=1)})
    assert execute_plan(_plan(), (early,)).reason_code == "entry_not_filled"

    late = _bar(3, high=101, low=99)
    assert execute_plan(_plan(), (late,)).reason_code == "entry_expired"
