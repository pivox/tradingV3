"""Authenticated net settlement for canonical Backtrader executions."""

from __future__ import annotations

import hashlib
import json
from decimal import Decimal
from typing import Any

from app.backtesting.backtrader_contracts import CanonicalBacktestOrderPlan
from app.backtesting.backtrader_execution import BacktestExecutionResult


_COST_BASIS_VERSION = "canonical-order-plan-authenticated-costs.v1"


class BacktestNetOutcomeError(ValueError):
    """Stable fail-closed settlement error."""


def settle_authenticated_outcome(
    envelope: CanonicalBacktestOrderPlan,
    execution: BacktestExecutionResult,
) -> str:
    envelope = _revalidate_plan(envelope)
    plan = envelope.plan
    if (
        execution.status != "closed"
        or len(execution.events) != 2
        or execution.events[0].kind != "entry_filled"
        or execution.events[1].kind not in ("target_filled", "stop_filled")
    ):
        raise BacktestNetOutcomeError("backtrader_net_outcome_execution_unsupported")
    entry_event, terminal_event = execution.events
    _verify_event_lineage(envelope, entry_event)
    _verify_event_lineage(envelope, terminal_event)
    if (
        entry_event.price != _decimal(plan.entry_price)
        or entry_event.happened_at > terminal_event.happened_at
        or execution.reason_code not in (
            "target_filled", "stop_filled", "conservative_stop_first"
        )
        or (
            terminal_event.kind == "target_filled"
            and execution.reason_code != "target_filled"
        )
        or (
            terminal_event.kind == "stop_filled"
            and execution.reason_code not in ("stop_filled", "conservative_stop_first")
        )
    ):
        raise BacktestNetOutcomeError("backtrader_net_outcome_execution_mismatch")

    target = None
    if terminal_event.kind == "target_filled":
        target = next(
            (
                item
                for item in plan.targets
                if _decimal(item.price) == terminal_event.price
            ),
            None,
        )
        if target is None:
            raise BacktestNetOutcomeError("backtrader_net_outcome_target_unknown")
        entry_fee = _decimal(target.entry_fee)
        exit_fee = _decimal(target.target_fee)
        entry_spread = _decimal(target.entry_spread_cost)
        exit_spread = _decimal(target.target_spread_cost)
        entry_slippage = _decimal(target.entry_slippage_cost)
        exit_slippage = _decimal(target.target_slippage_cost)
        funding = _decimal(target.funding_cost)
        gross_pnl = _decimal(target.gross_reward)
        net_pnl = _decimal(target.net_reward)
        net_r = _decimal(target.net_r)
    else:
        if terminal_event.price != _decimal(plan.stop_price):
            raise BacktestNetOutcomeError("backtrader_net_outcome_execution_mismatch")
        entry_fee = _decimal(plan.entry_fee)
        exit_fee = _decimal(plan.stop_exit_fee)
        entry_spread = _decimal(plan.entry_spread_cost)
        exit_spread = _decimal(plan.stop_spread_cost)
        entry_slippage = _decimal(plan.entry_slippage_cost)
        exit_slippage = _decimal(plan.stop_slippage_cost)
        funding = _decimal(plan.funding_cost)
        gross_pnl = -_decimal(plan.gross_stop_loss)
        net_pnl = -_decimal(plan.total_stop_loss)
        net_r = Decimal(-1)
    total_cost = sum(
        (
            entry_fee,
            exit_fee,
            entry_spread,
            exit_spread,
            entry_slippage,
            exit_slippage,
            funding,
        ),
        Decimal(0),
    )
    if gross_pnl - total_cost != net_pnl:
        raise BacktestNetOutcomeError("backtrader_net_outcome_cost_mismatch")

    result: dict[str, Any] = {
        "schema_version": "canonical-backtest-net-outcome.v1",
        "cost_basis_version": _COST_BASIS_VERSION,
        "funding_evidence": "canonical_plan_provision",
        "dataset_id": envelope.dataset_id,
        "dataset_checksum": envelope.dataset_checksum,
        "plan_hash": plan.plan_hash,
        "config_hash": plan.config_hash,
        "cost_input_hash": plan.cost_input_hash,
        "mode_id": plan.mode_id,
        "mode_version": plan.mode_version,
        "setup_id": plan.setup_id,
        "setup_version": plan.setup_version,
        "exchange": plan.exchange,
        "environment": plan.environment,
        "symbol": plan.symbol,
        "market_type": plan.market_type,
        "side": plan.side,
        "terminal_event_kind": terminal_event.kind,
        "terminal_source_record_id": terminal_event.source_record_id,
        "terminal_happened_at": terminal_event.happened_at.isoformat(timespec="microseconds").replace("+00:00", "Z"),
        "target_id": target.id if target is not None else None,
        "entry_price": entry_event.price,
        "exit_price": terminal_event.price,
        "quantity": _decimal(plan.quantity),
        "gross_pnl_quote": gross_pnl,
        "entry_fee_quote": entry_fee,
        "exit_fee_quote": exit_fee,
        "entry_spread_cost_quote": entry_spread,
        "exit_spread_cost_quote": exit_spread,
        "entry_slippage_cost_quote": entry_slippage,
        "exit_slippage_cost_quote": exit_slippage,
        "planned_adverse_funding_cost_quote": funding,
        "total_planned_cost_quote": total_cost,
        "net_pnl_quote": net_pnl,
        "net_r": net_r,
        "result_is_live_proof": False,
    }
    result["outcome_hash"] = _hash(result)
    return _canonical_json(result) + "\n"


def _revalidate_plan(envelope: CanonicalBacktestOrderPlan) -> CanonicalBacktestOrderPlan:
    wire = envelope.model_dump(mode="json", by_alias=True)
    for optional_key in ("cancelAfterAt", "holdingExpiresAt", "orderBookInputHash"):
        if wire["plan"].get(optional_key) is None:
            wire["plan"].pop(optional_key)
    try:
        return CanonicalBacktestOrderPlan.model_validate(wire)
    except Exception as exc:
        raise BacktestNetOutcomeError("backtrader_net_outcome_plan_invalid") from exc


def _verify_event_lineage(envelope: CanonicalBacktestOrderPlan, event: Any) -> None:
    plan = envelope.plan
    if (
        event.dataset_id != envelope.dataset_id
        or event.plan_hash != plan.plan_hash
        or event.config_hash != plan.config_hash
        or _decimal(event.quantity) != _decimal(plan.quantity)
        or _decimal(event.stop_price) != _decimal(plan.stop_price)
    ):
        raise BacktestNetOutcomeError("backtrader_net_outcome_lineage_mismatch")


def _decimal(value: Decimal | float | int) -> Decimal:
    result = value if isinstance(value, Decimal) else Decimal(str(value))
    if not result.is_finite():
        raise BacktestNetOutcomeError("backtrader_net_outcome_number_invalid")
    return result


def _hash(value: Any) -> str:
    return "sha256:" + hashlib.sha256(_canonical_json(value).encode()).hexdigest()


def _canonical_json(value: Any) -> str:
    if isinstance(value, dict):
        return "{" + ",".join(
            json.dumps(key, ensure_ascii=False, separators=(",", ":"))
            + ":"
            + _canonical_json(value[key])
            for key in sorted(value)
        ) + "}"
    if isinstance(value, (list, tuple)):
        return "[" + ",".join(_canonical_json(item) for item in value) + "]"
    if isinstance(value, Decimal):
        if not value.is_finite():
            raise BacktestNetOutcomeError("backtrader_net_outcome_number_invalid")
        return str(value)
    return json.dumps(value, ensure_ascii=False, allow_nan=False, separators=(",", ":"))
