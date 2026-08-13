"""Pure conservative execution state machine for one canonical plan."""

from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime
from typing import Literal

from app.backtesting.backtrader_contracts import CanonicalBacktestOrderPlan
from app.backtesting.backtrader_feed import VerifiedBacktraderBar


class BacktestExecutionError(ValueError):
    """Stable fail-closed execution error."""


@dataclass(frozen=True)
class BacktestExecutionEvent:
    kind: Literal["entry_filled", "stop_filled", "target_filled", "holding_expired"]
    happened_at: datetime
    source_record_id: str
    price: float
    quantity: float
    stop_price: float
    plan_hash: str
    config_hash: str
    dataset_id: str


@dataclass(frozen=True)
class BacktestExecutionResult:
    status: Literal["closed", "not_executed"]
    reason_code: str
    events: tuple[BacktestExecutionEvent, ...]


def execute_plan(
    envelope: CanonicalBacktestOrderPlan,
    bars: tuple[VerifiedBacktraderBar, ...],
) -> BacktestExecutionResult:
    plan = envelope.plan
    created = datetime.fromisoformat(plan.created_at)
    expires = datetime.fromisoformat(plan.expires_at)
    cancel = datetime.fromisoformat(plan.cancel_after_at) if plan.cancel_after_at else expires
    entry_deadline = min(expires, cancel)
    holding = datetime.fromisoformat(plan.holding_expires_at) if plan.holding_expires_at else None
    events: list[BacktestExecutionEvent] = []
    entered = False
    for bar in bars:
        if bar.available_at < created:
            continue
        if not entered:
            if bar.open_at < created:
                continue
            if bar.open_at < entry_deadline < bar.close_at:
                raise BacktestExecutionError("backtrader_entry_window_ambiguous")
            if bar.open_at >= entry_deadline:
                return BacktestExecutionResult("not_executed", "entry_expired", ())
            if bar.low <= plan.entry_price <= bar.high:
                events.append(_event("entry_filled", bar, plan.entry_price, envelope))
                entered = True
                continue
            continue
        if holding is not None and bar.open_at >= holding:
            events.append(_event("holding_expired", bar, bar.open, envelope))
            return BacktestExecutionResult("closed", "holding_expired", tuple(events))
        stop_hit = (
            bar.low <= plan.stop_price if plan.side == "long" else bar.high >= plan.stop_price
        )
        hit_targets = tuple(
            target
            for target in plan.targets
            if (bar.high >= target.price if plan.side == "long" else bar.low <= target.price)
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


def _event(
    kind: Literal["entry_filled", "stop_filled", "target_filled", "holding_expired"],
    bar: VerifiedBacktraderBar,
    price: float,
    envelope: CanonicalBacktestOrderPlan,
) -> BacktestExecutionEvent:
    plan = envelope.plan
    return BacktestExecutionEvent(
        kind=kind,
        happened_at=bar.available_at,
        source_record_id=bar.source_record_id,
        price=price,
        quantity=plan.quantity,
        stop_price=plan.stop_price,
        plan_hash=plan.plan_hash,
        config_hash=plan.config_hash,
        dataset_id=envelope.dataset_id,
    )
