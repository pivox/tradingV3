"""Pure conservative execution state machine for one canonical plan."""

from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime
from decimal import Decimal
from typing import Literal

from app.backtesting.backtrader_contracts import CanonicalBacktestOrderPlan
from app.backtesting.backtrader_feed import VerifiedBacktraderBar
from app.backtesting.visible_queue_depletion import (
    VisibleQueueDepletionResult,
    requires_partial_fill_authority,
)


class BacktestExecutionError(ValueError):
    """Stable fail-closed execution error."""


@dataclass(frozen=True)
class BacktestExecutionEvent:
    kind: Literal[
        "entry_partially_filled",
        "entry_filled",
        "stop_filled",
        "target_filled",
        "holding_expired",
    ]
    happened_at: datetime
    source_record_id: str
    price: Decimal
    quantity: float
    stop_price: float
    plan_hash: str
    config_hash: str
    dataset_id: str
    quantity_base: Decimal | None = None


@dataclass(frozen=True)
class BacktestExecutionResult:
    status: Literal["closed", "not_executed"]
    reason_code: str
    events: tuple[BacktestExecutionEvent, ...]
    filled_quantity_base: Decimal | None = None
    cancelled_residual_quantity_base: Decimal | None = None
    consumed_fill_count: int = 0


def execute_plan(
    envelope: CanonicalBacktestOrderPlan,
    bars: tuple[VerifiedBacktraderBar, ...],
) -> BacktestExecutionResult:
    return _execute_plan(envelope, bars, entry_evidence=None)


def execute_plan_from_visible_fill(
    envelope: CanonicalBacktestOrderPlan,
    bars: tuple[VerifiedBacktraderBar, ...],
    evidence: VisibleQueueDepletionResult,
) -> BacktestExecutionResult:
    try:
        evidence = VisibleQueueDepletionResult.model_validate(
            evidence.model_dump(mode="json")
        )
    except Exception as exc:
        raise BacktestExecutionError("backtrader_visible_fill_evidence_invalid") from exc
    plan = envelope.plan
    if requires_partial_fill_authority(evidence):
        raise BacktestExecutionError(
            "backtrader_partial_fill_cost_authority_missing"
        )
    if evidence.status != "filled":
        raise BacktestExecutionError("backtrader_visible_fill_incomplete")
    deadline = min(
        datetime.fromisoformat(plan.expires_at),
        datetime.fromisoformat(plan.cancel_after_at)
        if plan.cancel_after_at is not None
        else datetime.fromisoformat(plan.expires_at),
    )
    if (
        envelope.schema_version != "canonical-backtest-order-plan.v2"
        or evidence.dataset_id != envelope.dataset_id
        or evidence.dataset_checksum != envelope.dataset_checksum
        or evidence.plan_hash != plan.plan_hash
        or evidence.config_hash != plan.config_hash
        or evidence.market_type != plan.market_type
        or evidence.symbol != plan.symbol
        or evidence.side != plan.side
        or Decimal(evidence.entry_price) != Decimal(str(plan.entry_price))
        or datetime.fromisoformat(evidence.order_live_at)
        != datetime.fromisoformat(plan.created_at)
        or datetime.fromisoformat(evidence.effective_deadline_at) != deadline
        or Decimal(evidence.order_quantity_base)
        != Decimal(str(plan.quantity)) * Decimal(str(plan.contract_size))
    ):
        raise BacktestExecutionError("backtrader_visible_fill_evidence_invalid")
    total = Decimal(evidence.order_quantity_base)
    completion = next(
        (item for item in evidence.trace if Decimal(item.cumulative_fill_quantity_base) == total),
        None,
    )
    if completion is None:
        raise BacktestExecutionError("backtrader_visible_fill_trace_invalid")
    entry_evidence = BacktestExecutionEvent(
        kind="entry_filled",
        happened_at=datetime.fromisoformat(completion.available_at),
        source_record_id=completion.source_record_id,
        price=Decimal(evidence.entry_price),
        quantity=plan.quantity,
        stop_price=plan.stop_price,
        plan_hash=plan.plan_hash,
        config_hash=plan.config_hash,
        dataset_id=envelope.dataset_id,
    )
    return _execute_plan(envelope, bars, entry_evidence=entry_evidence)


def _execute_plan(
    envelope: CanonicalBacktestOrderPlan,
    bars: tuple[VerifiedBacktraderBar, ...],
    *,
    entry_evidence: BacktestExecutionEvent | None,
) -> BacktestExecutionResult:
    plan = envelope.plan
    created = datetime.fromisoformat(plan.created_at)
    expires = datetime.fromisoformat(plan.expires_at)
    cancel = datetime.fromisoformat(plan.cancel_after_at) if plan.cancel_after_at else expires
    entry_deadline = min(expires, cancel)
    holding = datetime.fromisoformat(plan.holding_expires_at) if plan.holding_expires_at else None
    events: list[BacktestExecutionEvent] = (
        [] if entry_evidence is None else [entry_evidence]
    )
    entered = entry_evidence is not None
    if entry_evidence is not None and not (
        created <= entry_evidence.happened_at < entry_deadline
    ):
        raise BacktestExecutionError("backtrader_visible_fill_time_invalid")
    entry_price = Decimal(str(plan.entry_price))
    stop_price = Decimal(str(plan.stop_price))
    for bar in bars:
        if entry_evidence is not None and bar.open_at < entry_evidence.happened_at:
            if bar.close_at <= entry_evidence.happened_at:
                continue
            if (
                holding is not None
                and entry_evidence.happened_at < holding < bar.close_at
            ):
                raise BacktestExecutionError("backtrader_holding_window_ambiguous")
            stop_hit = (
                _decimal(bar.low) <= stop_price
                if plan.side == "long"
                else _decimal(bar.high) >= stop_price
            )
            if stop_hit:
                events.append(_event("stop_filled", bar, plan.stop_price, envelope))
                return BacktestExecutionResult(
                    "closed", "conservative_post_fill_stop_bound", tuple(events)
                )
            continue
        if bar.available_at < created:
            continue
        if not entered:
            if bar.open_at < created:
                continue
            if bar.available_at >= entry_deadline:
                return BacktestExecutionResult("not_executed", "entry_expired", ())
            if bar.open_at < entry_deadline < bar.close_at:
                raise BacktestExecutionError("backtrader_entry_window_ambiguous")
            if bar.open_at >= entry_deadline:
                return BacktestExecutionResult("not_executed", "entry_expired", ())
            if _decimal(bar.low) <= entry_price <= _decimal(bar.high):
                events.append(_event("entry_filled", bar, plan.entry_price, envelope))
                entered = True
                if holding is not None and bar.open_at < holding < bar.close_at:
                    raise BacktestExecutionError("backtrader_holding_window_ambiguous")
                stop_hit = (
                    _decimal(bar.low) <= stop_price
                    if plan.side == "long"
                    else _decimal(bar.high) >= stop_price
                )
                hit_targets = tuple(
                    target
                    for target in plan.targets
                    if (
                        _decimal(bar.high) >= Decimal(str(target.price))
                        if plan.side == "long"
                        else _decimal(bar.low) <= Decimal(str(target.price))
                    )
                )
                if stop_hit:
                    events.append(_event("stop_filled", bar, plan.stop_price, envelope))
                    reason = "conservative_stop_first" if hit_targets else "stop_filled"
                    return BacktestExecutionResult("closed", reason, tuple(events))
                if hit_targets:
                    events.append(_event("target_filled", bar, hit_targets[0].price, envelope))
                    return BacktestExecutionResult("closed", "target_filled", tuple(events))
                continue
            continue
        if holding is not None and bar.open_at < holding < bar.close_at:
            raise BacktestExecutionError("backtrader_holding_window_ambiguous")
        if holding is not None and bar.close_at <= holding <= bar.available_at:
            raise BacktestExecutionError("backtrader_holding_delivery_ambiguous")
        if holding is not None and bar.open_at >= holding:
            events.append(_event("holding_expired", bar, _decimal(bar.open), envelope))
            return BacktestExecutionResult("closed", "holding_expired", tuple(events))
        stop_hit = (
            _decimal(bar.low) <= stop_price
            if plan.side == "long"
            else _decimal(bar.high) >= stop_price
        )
        hit_targets = tuple(
            target
            for target in plan.targets
            if (
                _decimal(bar.high) >= Decimal(str(target.price))
                if plan.side == "long"
                else _decimal(bar.low) <= Decimal(str(target.price))
            )
        )
        if stop_hit:
            events.append(_event("stop_filled", bar, plan.stop_price, envelope))
            reason = "conservative_stop_first" if hit_targets else "stop_filled"
            return BacktestExecutionResult("closed", reason, tuple(events))
        if hit_targets:
            events.append(_event("target_filled", bar, hit_targets[0].price, envelope))
            return BacktestExecutionResult("closed", "target_filled", tuple(events))
    if entered:
        raise BacktestExecutionError("backtrader_position_open_at_dataset_end")
    if bars and bars[-1].close_at >= entry_deadline:
        return BacktestExecutionResult("not_executed", "entry_expired", ())
    return BacktestExecutionResult("not_executed", "entry_not_filled", ())


def _decimal(value: Decimal | float) -> Decimal:
    return value if isinstance(value, Decimal) else Decimal(str(value))


def _event(
    kind: Literal["entry_filled", "stop_filled", "target_filled", "holding_expired"],
    bar: VerifiedBacktraderBar,
    price: Decimal | float,
    envelope: CanonicalBacktestOrderPlan,
) -> BacktestExecutionEvent:
    plan = envelope.plan
    return BacktestExecutionEvent(
        kind=kind,
        happened_at=bar.available_at,
        source_record_id=bar.source_record_id,
        price=_decimal(price),
        quantity=plan.quantity,
        stop_price=plan.stop_price,
        plan_hash=plan.plan_hash,
        config_hash=plan.config_hash,
        dataset_id=envelope.dataset_id,
    )
