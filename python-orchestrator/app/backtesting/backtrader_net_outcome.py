"""Authenticated net settlement for canonical Backtrader executions."""

from __future__ import annotations

import hashlib
import json
from datetime import datetime
from decimal import Decimal
from typing import Any

from app.backtesting.backtrader_contracts import CanonicalBacktestOrderPlan
from app.backtesting.backtrader_execution import (
    BacktestExecutionResult,
    execute_plan,
    execute_plan_from_visible_fill,
)
from app.backtesting.backtrader_feed import VerifiedBacktraderFeedAdapter
from app.backtesting.historical_funding import VerifiedHistoricalFundingSchedule
from app.backtesting.historical_funding_bridge import (
    CanonicalHistoricalFundingResult,
    canonical_historical_funding_request,
    settlement_matches_request,
)
from app.backtesting.visible_queue_depletion import (
    VisibleQueueDepletionResult,
    requires_partial_fill_authority,
)


_COST_BASIS_VERSION = "canonical-order-plan-bound-costs.v1"
_HISTORICAL_COST_BASIS_VERSION = "canonical-plan-plus-historical-funding.v1"


class BacktestNetOutcomeError(ValueError):
    """Stable fail-closed settlement error."""


def project_plan_bound_net_outcome(
    envelope: CanonicalBacktestOrderPlan,
    execution: BacktestExecutionResult,
    feed: VerifiedBacktraderFeedAdapter,
    *,
    funding_schedule: VerifiedHistoricalFundingSchedule | None = None,
    funding_settlement: CanonicalHistoricalFundingResult | None = None,
    maker_fill_evidence: VisibleQueueDepletionResult | None = None,
) -> str:
    envelope = _revalidate_plan(envelope)
    maker_fill_evidence = _revalidate_maker_fill_evidence(
        envelope, feed, maker_fill_evidence
    )
    _verify_dataset_evidence(envelope, execution, feed, maker_fill_evidence)
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
    replayed_execution = (
        execute_plan(envelope, feed.bars)
        if maker_fill_evidence is None
        else execute_plan_from_visible_fill(envelope, feed.bars, maker_fill_evidence)
    )
    if replayed_execution != execution:
        raise BacktestNetOutcomeError("backtrader_net_outcome_execution_evidence_mismatch")
    execution = replayed_execution
    entry_event, terminal_event = execution.events

    target = None
    if terminal_event.kind == "target_filled":
        target = next(
            item
            for item in plan.targets
            if _decimal(item.price) == terminal_event.price
        )
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
    historical = _historical_funding(
        envelope, execution, feed, funding_schedule, funding_settlement
    )
    projected_non_funding_cost = sum(
        (
            entry_fee,
            exit_fee,
            entry_spread,
            exit_spread,
            entry_slippage,
            exit_slippage,
        ),
        Decimal(0),
    )
    total_cost = projected_non_funding_cost + funding
    if gross_pnl - total_cost != net_pnl:
        raise BacktestNetOutcomeError("backtrader_net_outcome_cost_mismatch")
    if historical is not None:
        historical_cashflow = _decimal(historical.funding_cashflow_quote)
        net_pnl = gross_pnl - projected_non_funding_cost + historical_cashflow
        total_cost = projected_non_funding_cost - historical_cashflow
        stop_non_funding_cost = (
            _decimal(plan.entry_fee) + _decimal(plan.stop_exit_fee)
            + _decimal(plan.entry_spread_cost) + _decimal(plan.stop_spread_cost)
            + _decimal(plan.entry_slippage_cost) + _decimal(plan.stop_slippage_cost)
        )
        historical_net_risk = _decimal(plan.gross_stop_loss) + stop_non_funding_cost - historical_cashflow
        if historical_net_risk <= 0:
            raise BacktestNetOutcomeError("backtrader_net_outcome_historical_net_risk_invalid")
        net_r = net_pnl / historical_net_risk

    result: dict[str, Any] = {
        "schema_version": (
            "canonical-backtest-historical-net-outcome.v2"
            if historical is not None and maker_fill_evidence is not None
            else "canonical-backtest-planned-net-outcome.v2"
            if maker_fill_evidence is not None
            else "canonical-backtest-historical-net-outcome.v1"
            if historical is not None
            else "canonical-backtest-planned-net-outcome.v1"
        ),
        "cost_basis_version": _HISTORICAL_COST_BASIS_VERSION if historical is not None else _COST_BASIS_VERSION,
        "cost_evidence": "canonical_plan_projection_with_historical_funding" if historical is not None else "canonical_plan_projection",
        "costs_are_certified": False,
        "funding_evidence": (
            "integrity_bound_historical_schedule"
            if historical is not None
            else "canonical_plan_provision"
        ),
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
        **(
            {
                "fills_are_certified": False,
                "maker_fill_result_hash": maker_fill_evidence.result_hash,
                "maker_fill_trace_hash": maker_fill_evidence.trace_hash,
            }
            if maker_fill_evidence is not None
            else {}
        ),
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
        **(
            {
                "funding_schedule_checksum": funding_schedule.schedule_checksum,
                "funding_settlement_hash": historical.result_hash,
                "applied_funding_source_record_ids": historical.applied_source_record_ids,
                "historical_funding_cashflow_quote": _decimal(historical.funding_cashflow_quote),
                "total_execution_cost_quote": total_cost,
            }
            if historical is not None
            else {
                "planned_adverse_funding_cost_quote": funding,
                "total_planned_cost_quote": total_cost,
            }
        ),
        "net_pnl_quote": net_pnl,
        "net_r": net_r,
        **({"net_r_evidence": "historical_funding_with_plan_bound_stop_costs"} if historical is not None else {}),
        "result_is_live_proof": False,
    }
    result["outcome_hash"] = _hash(result)
    return _canonical_json(result) + "\n"


def _historical_funding(
    envelope: CanonicalBacktestOrderPlan,
    execution: BacktestExecutionResult,
    feed: VerifiedBacktraderFeedAdapter,
    schedule: VerifiedHistoricalFundingSchedule | None,
    settlement: CanonicalHistoricalFundingResult | None,
) -> CanonicalHistoricalFundingResult | None:
    if schedule is None and settlement is None:
        return None
    if schedule is None or settlement is None:
        raise BacktestNetOutcomeError("backtrader_net_outcome_historical_funding_evidence_required")
    plan = envelope.plan
    entry, terminal = execution.events
    try:
        schedule = VerifiedHistoricalFundingSchedule(schedule.artifacts)
        settlement = CanonicalHistoricalFundingResult.model_validate(
            settlement.model_dump(mode="json")
        )
    except Exception as exc:
        raise BacktestNetOutcomeError(
            "backtrader_net_outcome_historical_funding_evidence_mismatch"
        ) from exc
    if (
        not isinstance(schedule, VerifiedHistoricalFundingSchedule)
        or not isinstance(settlement, CanonicalHistoricalFundingResult)
        or schedule.dataset_id != feed.dataset_id
        or schedule.dataset_checksum != feed.dataset_checksum
        or schedule.source_network != feed.source_network
        or schedule.market_data_venue != feed.market_data_venue
        or schedule.market_type != feed.market_type
        or schedule.symbol != feed.symbol
    ):
        raise BacktestNetOutcomeError("backtrader_net_outcome_historical_funding_evidence_mismatch")
    expected_ids = tuple(
        record.source_record_id
        for record in schedule.records
        if entry.happened_at < record.funding_at <= terminal.happened_at
    )
    request = canonical_historical_funding_request(envelope, execution, schedule)
    if (
        not settlement_matches_request(settlement, request)
        or
        settlement.applied_source_record_ids != expected_ids
        or settlement.request_hash != request.request_hash()
    ):
        raise BacktestNetOutcomeError("backtrader_net_outcome_historical_funding_evidence_mismatch")
    return settlement


def _revalidate_plan(envelope: CanonicalBacktestOrderPlan) -> CanonicalBacktestOrderPlan:
    wire = envelope.model_dump(mode="json", by_alias=True)
    for optional_key in (
        "marketFallback", "cancelAfterAt", "holdingExpiresAt", "orderBookInputHash",
    ):
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


def _verify_dataset_evidence(
    envelope: CanonicalBacktestOrderPlan,
    execution: BacktestExecutionResult,
    feed: VerifiedBacktraderFeedAdapter,
    maker_fill_evidence: VisibleQueueDepletionResult | None,
) -> None:
    plan = envelope.plan
    if (
        not isinstance(feed, VerifiedBacktraderFeedAdapter)
        or feed.dataset_id != envelope.dataset_id
        or feed.dataset_checksum != envelope.dataset_checksum
        or feed.symbol != plan.symbol
        or feed.timeframe != envelope.timeframe
        or feed.market_type != plan.market_type
    ):
        raise BacktestNetOutcomeError("backtrader_net_outcome_dataset_evidence_mismatch")
    bars = {bar.source_record_id: bar for bar in feed.bars}
    if len(bars) != len(feed.bars):
        raise BacktestNetOutcomeError("backtrader_net_outcome_dataset_evidence_mismatch")
    for index, event in enumerate(execution.events):
        if maker_fill_evidence is not None and index == 0:
            total = Decimal(maker_fill_evidence.order_quantity_base)
            completion = next(
                (
                    item
                    for item in maker_fill_evidence.trace
                    if Decimal(item.cumulative_fill_quantity_base) == total
                ),
                None,
            )
            if (
                completion is None
                or event.source_record_id != completion.source_record_id
                or event.happened_at != datetime.fromisoformat(completion.available_at)
            ):
                raise BacktestNetOutcomeError(
                    "backtrader_net_outcome_dataset_evidence_mismatch"
                )
            continue
        bar = bars.get(event.source_record_id)
        if bar is None or bar.available_at != event.happened_at:
            raise BacktestNetOutcomeError("backtrader_net_outcome_dataset_evidence_mismatch")


def _revalidate_maker_fill_evidence(
    envelope: CanonicalBacktestOrderPlan,
    feed: VerifiedBacktraderFeedAdapter,
    evidence: VisibleQueueDepletionResult | None,
) -> VisibleQueueDepletionResult | None:
    if evidence is None:
        if envelope.schema_version == "canonical-backtest-order-plan.v2":
            raise BacktestNetOutcomeError(
                "backtrader_net_outcome_visible_fill_evidence_required"
            )
        return None
    if envelope.schema_version != "canonical-backtest-order-plan.v2":
        raise BacktestNetOutcomeError(
            "backtrader_net_outcome_visible_fill_evidence_forbidden"
        )
    try:
        evidence = VisibleQueueDepletionResult.model_validate(
            evidence.model_dump(mode="json")
        )
    except Exception as exc:
        raise BacktestNetOutcomeError(
            "backtrader_net_outcome_visible_fill_evidence_invalid"
        ) from exc
    plan = envelope.plan
    deadline = min(
        datetime.fromisoformat(plan.expires_at),
        datetime.fromisoformat(plan.cancel_after_at)
        if plan.cancel_after_at is not None
        else datetime.fromisoformat(plan.expires_at),
    )
    if (
        evidence.status != "filled"
        or requires_partial_fill_authority(evidence)
        or evidence.dataset_id != envelope.dataset_id
        or evidence.dataset_checksum != envelope.dataset_checksum
        or evidence.plan_hash != plan.plan_hash
        or evidence.config_hash != plan.config_hash
        or evidence.source_network != feed.source_network
        or evidence.market_data_venue != feed.market_data_venue
        or evidence.market_type != plan.market_type
        or evidence.symbol != plan.symbol
        or evidence.side != plan.side
        or Decimal(evidence.entry_price) != Decimal(str(plan.entry_price))
        or datetime.fromisoformat(evidence.order_live_at)
        != datetime.fromisoformat(plan.created_at)
        or datetime.fromisoformat(evidence.effective_deadline_at) != deadline
    ):
        raise BacktestNetOutcomeError(
            "backtrader_net_outcome_visible_fill_evidence_invalid"
        )
    return evidence


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
