from __future__ import annotations

from datetime import datetime, timezone
from decimal import Decimal

import pytest

from app.backtesting.backtrader_execution import BacktestExecutionError
from app.backtesting.staged_fill_execution import (
    execute_plan_from_staged_visible_fills,
)
from app.backtesting.visible_queue_depletion import (
    VisibleQueueDepletionResult,
    _decimal_string,
    _hash,
)
from tests.test_backtesting_backtrader_runtime import _feed, _v2_plan


UTC = timezone.utc


def _time(minute: int, second: int) -> str:
    return datetime(2026, 8, 10, 12, minute, second, tzinfo=UTC).isoformat(
        timespec="microseconds"
    ).replace("+00:00", "Z")


def _evidence(
    plan,
    fills: tuple[tuple[str, int, int, Decimal], ...],
) -> VisibleQueueDepletionResult:
    order_quantity = Decimal(str(plan.plan.quantity)) * Decimal(
        str(plan.plan.contract_size)
    )
    queue = Decimal("1")
    cumulative = Decimal(0)
    trace = []
    for position, (source_id, minute, second, fill) in enumerate(fills, start=7):
        queue_before = queue
        trade_quantity = queue + fill
        queue = Decimal(0)
        cumulative += fill
        trace.append(
            {
                "source_record_id": source_id * 64,
                "source_event_position": position,
                "happened_at": _time(minute, second - 10),
                "available_at": _time(minute, second),
                "price": "100.1",
                "trade_base_quantity": _decimal_string(trade_quantity),
                "queue_before_base": _decimal_string(queue_before),
                "queue_after_base": "0",
                "fill_quantity_base": _decimal_string(fill),
                "cumulative_fill_quantity_base": _decimal_string(cumulative),
                "remaining_order_quantity_base": _decimal_string(
                    order_quantity - cumulative
                ),
                "evidence_kind": "at_price_depletion",
            }
        )
    remaining = order_quantity - cumulative
    status = "filled" if remaining == 0 else "partially_filled"
    payload = {
        "schema_version": "visible-queue-depletion-result.v1",
        "policy_version": "visible-queue-depletion.v1",
        "dataset_id": plan.dataset_id,
        "dataset_checksum": plan.dataset_checksum,
        "plan_hash": plan.plan.plan_hash,
        "config_hash": plan.plan.config_hash,
        "public_book_tape_checksum": "sha256:" + "1" * 64,
        "public_execution_tape_checksum": "sha256:" + "2" * 64,
        "quantity_conversion_tape_checksum": "sha256:" + "3" * 64,
        "source_network": "mainnet",
        "market_data_venue": "okx",
        "market_type": "perpetual",
        "symbol": "BTCUSDT",
        "side": "long",
        "entry_price": "100.1",
        "order_live_at": "2026-08-10T12:00:00.000000Z",
        "effective_deadline_at": "2026-08-10T12:03:00.000000Z",
        "initial_book_source_record_id": "b" * 64,
        "initial_book_source_event_position": 3,
        "initial_visible_queue_base": "1",
        "order_quantity_base": _decimal_string(order_quantity),
        "trace": trace,
        "filled_quantity_base": _decimal_string(cumulative),
        "remaining_quantity_base": _decimal_string(remaining),
        "status": status,
        "fills_are_certified": False,
        "queue_evidence": "visible_l1_plus_public_trades",
        "latency_assumption": "available_at_ordering_no_private_ack",
        "result_is_live_proof": False,
        "trace_hash": _hash(tuple(trace)),
    }
    payload["result_hash"] = _hash(payload)
    return VisibleQueueDepletionResult.model_validate(payload)


def test_staged_completion_preserves_each_increment_before_later_target() -> None:
    feed = _feed()
    plan = _v2_plan(feed)
    evidence = _evidence(
        plan,
        (("a", 0, 30, Decimal("1")), ("c", 0, 45, Decimal("1.497"))),
    )

    execution = execute_plan_from_staged_visible_fills(plan, feed.bars, evidence)

    assert [event.kind for event in execution.events] == [
        "entry_partially_filled",
        "entry_filled",
        "target_filled",
    ]
    assert [event.quantity_base for event in execution.events] == [
        Decimal("1"),
        Decimal("1.497"),
        Decimal("2.497"),
    ]
    assert execution.filled_quantity_base == Decimal("2.497")
    assert execution.cancelled_residual_quantity_base == 0
    assert execution.consumed_fill_count == 2


def test_partial_at_deadline_keeps_exposure_and_cancels_only_residual() -> None:
    feed = _feed()
    plan = _v2_plan(feed)
    evidence = _evidence(plan, (("a", 0, 45, Decimal("1")),))

    execution = execute_plan_from_staged_visible_fills(plan, feed.bars, evidence)

    assert [event.kind for event in execution.events] == [
        "entry_partially_filled",
        "target_filled",
    ]
    assert execution.events[-1].quantity_base == Decimal("1")
    assert execution.filled_quantity_base == Decimal("1")
    assert execution.cancelled_residual_quantity_base == Decimal("1.497")


def test_stop_closes_exposed_prefix_and_ignores_later_evidence_fill() -> None:
    feed = _feed(fill_bar_stop=True)
    plan = _v2_plan(feed)
    evidence = _evidence(
        plan,
        (("a", 0, 30, Decimal("1")), ("c", 1, 30, Decimal("1.497"))),
    )

    execution = execute_plan_from_staged_visible_fills(plan, feed.bars, evidence)

    assert [event.kind for event in execution.events] == [
        "entry_partially_filled",
        "stop_filled",
    ]
    assert execution.reason_code == "conservative_post_partial_fill_stop_bound"
    assert execution.events[-1].quantity_base == Decimal("1")
    assert execution.filled_quantity_base == Decimal("1")
    assert execution.cancelled_residual_quantity_base == Decimal("1.497")
    assert execution.consumed_fill_count == 1


def test_target_on_fill_bar_is_not_credited_but_next_bar_target_is() -> None:
    feed = _feed(fill_bar_target=True)
    plan = _v2_plan(feed)
    evidence = _evidence(plan, (("a", 0, 45, Decimal("1")),))

    execution = execute_plan_from_staged_visible_fills(plan, feed.bars, evidence)

    assert execution.events[-1].kind == "target_filled"
    assert execution.events[-1].source_record_id == "runtime-bar-1"


def test_staged_execution_revalidates_evidence_and_requires_a_delivered_terminal() -> None:
    feed = _feed(unfilled=True)
    plan = _v2_plan(feed)
    evidence = _evidence(plan, (("a", 0, 45, Decimal("1")),))
    forged = evidence.model_copy(update={"result_hash": "sha256:" + "f" * 64})

    with pytest.raises(BacktestExecutionError, match="visible_fill_evidence_invalid"):
        execute_plan_from_staged_visible_fills(plan, feed.bars, forged)
    with pytest.raises(BacktestExecutionError, match="position_open_at_dataset_end"):
        execute_plan_from_staged_visible_fills(plan, feed.bars, evidence)
