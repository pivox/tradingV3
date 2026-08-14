"""Pure chronology for staged maker fills backed by public queue evidence."""

from __future__ import annotations

from datetime import datetime
from decimal import Decimal

from app.backtesting.backtrader_contracts import CanonicalBacktestOrderPlan
from app.backtesting.backtrader_execution import (
    BacktestExecutionError,
    BacktestExecutionEvent,
    BacktestExecutionResult,
)
from app.backtesting.backtrader_feed import VerifiedBacktraderBar
from app.backtesting.visible_queue_depletion import (
    VisibleQueueDepletionResult,
    requires_partial_fill_authority,
)


def execute_plan_from_staged_visible_fills(
    envelope: CanonicalBacktestOrderPlan,
    bars: tuple[VerifiedBacktraderBar, ...],
    evidence: VisibleQueueDepletionResult,
) -> BacktestExecutionResult:
    evidence = _validated_evidence(envelope, evidence)
    if not requires_partial_fill_authority(evidence):
        raise BacktestExecutionError("backtrader_staged_fill_evidence_not_required")
    if evidence.status == "unfilled":
        raise BacktestExecutionError("backtrader_visible_fill_incomplete")
    plan = envelope.plan
    if plan.holding_expires_at is not None:
        raise BacktestExecutionError("backtrader_staged_fill_holding_unsupported")

    positive_fills = tuple(
        item for item in evidence.trace if Decimal(item.fill_quantity_base) > 0
    )
    _validate_fill_order(positive_fills)
    total = Decimal(evidence.order_quantity_base)
    contract_size = Decimal(str(plan.contract_size))
    cumulative = Decimal(0)
    fill_index = 0
    events: list[BacktestExecutionEvent] = []
    stop_price = Decimal(str(plan.stop_price))

    for bar in bars:
        new_fill = False
        while fill_index < len(positive_fills):
            item = positive_fills[fill_index]
            available_at = datetime.fromisoformat(item.available_at)
            if available_at > bar.available_at:
                break
            increment = Decimal(item.fill_quantity_base)
            cumulative += increment
            new_fill = True
            fill_index += 1
            events.append(
                BacktestExecutionEvent(
                    kind=(
                        "entry_filled"
                        if cumulative == total
                        else "entry_partially_filled"
                    ),
                    happened_at=available_at,
                    source_record_id=item.source_record_id,
                    price=Decimal(evidence.entry_price),
                    quantity=float(increment / contract_size),
                    stop_price=plan.stop_price,
                    plan_hash=plan.plan_hash,
                    config_hash=plan.config_hash,
                    dataset_id=envelope.dataset_id,
                    quantity_base=increment,
                )
            )
        if cumulative == 0:
            continue

        stop_hit = (
            Decimal(bar.low) <= stop_price
            if plan.side == "long"
            else Decimal(bar.high) >= stop_price
        )
        hit_targets = tuple(
            target
            for target in plan.targets
            if (
                Decimal(bar.high) >= Decimal(str(target.price))
                if plan.side == "long"
                else Decimal(bar.low) <= Decimal(str(target.price))
            )
        )
        if stop_hit:
            events.append(_terminal_event("stop_filled", bar, cumulative, envelope))
            return _result(
                events,
                total,
                cumulative,
                fill_index,
                (
                    "conservative_post_partial_fill_stop_bound"
                    if new_fill
                    else "conservative_stop_first"
                    if hit_targets
                    else "stop_filled"
                ),
            )
        if hit_targets and not new_fill:
            events.append(
                _terminal_event(
                    "target_filled",
                    bar,
                    cumulative,
                    envelope,
                    Decimal(str(hit_targets[0].price)),
                )
            )
            return _result(
                events, total, cumulative, fill_index, "target_filled"
            )

    if cumulative > 0:
        raise BacktestExecutionError("backtrader_position_open_at_dataset_end")
    raise BacktestExecutionError("backtrader_staged_fill_not_delivered")


def _result(
    events: list[BacktestExecutionEvent],
    total: Decimal,
    cumulative: Decimal,
    consumed_fill_count: int,
    reason_code: str,
) -> BacktestExecutionResult:
    return BacktestExecutionResult(
        status="closed",
        reason_code=reason_code,
        events=tuple(events),
        filled_quantity_base=cumulative,
        cancelled_residual_quantity_base=total - cumulative,
        consumed_fill_count=consumed_fill_count,
    )


def _terminal_event(
    kind: str,
    bar: VerifiedBacktraderBar,
    cumulative: Decimal,
    envelope: CanonicalBacktestOrderPlan,
    price: Decimal | None = None,
) -> BacktestExecutionEvent:
    plan = envelope.plan
    return BacktestExecutionEvent(
        kind=kind,  # type: ignore[arg-type]
        happened_at=bar.available_at,
        source_record_id=bar.source_record_id,
        price=Decimal(str(plan.stop_price)) if price is None else price,
        quantity=float(cumulative / Decimal(str(plan.contract_size))),
        stop_price=plan.stop_price,
        plan_hash=plan.plan_hash,
        config_hash=plan.config_hash,
        dataset_id=envelope.dataset_id,
        quantity_base=cumulative,
    )


def _validated_evidence(
    envelope: CanonicalBacktestOrderPlan,
    evidence: VisibleQueueDepletionResult,
) -> VisibleQueueDepletionResult:
    try:
        evidence = VisibleQueueDepletionResult.model_validate(
            evidence.model_dump(mode="json")
        )
    except Exception as exc:
        raise BacktestExecutionError("backtrader_visible_fill_evidence_invalid") from exc
    plan = envelope.plan
    live_at = datetime.fromisoformat(plan.created_at)
    deadline = min(
        datetime.fromisoformat(plan.expires_at),
        datetime.fromisoformat(plan.cancel_after_at)
        if plan.cancel_after_at is not None
        else datetime.fromisoformat(plan.expires_at),
    )
    if (
        envelope.schema_version != "canonical-backtest-order-plan.v2"
        or plan.market_fallback is not False
        or evidence.dataset_id != envelope.dataset_id
        or evidence.dataset_checksum != envelope.dataset_checksum
        or evidence.plan_hash != plan.plan_hash
        or evidence.config_hash != plan.config_hash
        or evidence.market_type != plan.market_type
        or evidence.symbol != plan.symbol
        or evidence.side != plan.side
        or Decimal(evidence.entry_price) != Decimal(str(plan.entry_price))
        or datetime.fromisoformat(evidence.order_live_at)
        != live_at
        or datetime.fromisoformat(evidence.effective_deadline_at) != deadline
        or Decimal(evidence.order_quantity_base)
        != Decimal(str(plan.quantity)) * Decimal(str(plan.contract_size))
        or any(
            datetime.fromisoformat(item.happened_at) < live_at
            or datetime.fromisoformat(item.available_at) <= live_at
            or datetime.fromisoformat(item.happened_at) > deadline
            or datetime.fromisoformat(item.available_at) > deadline
            for item in evidence.trace
        )
    ):
        raise BacktestExecutionError("backtrader_visible_fill_evidence_invalid")
    return evidence


def _validate_fill_order(fills: tuple) -> None:
    previous = None
    source_ids: set[str] = set()
    for item in fills:
        key = (
            datetime.fromisoformat(item.available_at),
            datetime.fromisoformat(item.happened_at),
            item.source_event_position,
            item.source_record_id,
        )
        if item.source_record_id in source_ids or (
            previous is not None and key <= previous
        ):
            raise BacktestExecutionError("backtrader_staged_fill_trace_invalid")
        source_ids.add(item.source_record_id)
        previous = key
