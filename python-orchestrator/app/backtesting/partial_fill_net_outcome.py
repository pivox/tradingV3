"""Authenticated net outcome for a consumed staged-fill execution prefix."""

from __future__ import annotations

import hashlib
from decimal import Decimal
from typing import Any

from app.backtesting.backtrader_contracts import CanonicalBacktestOrderPlan
from app.backtesting.backtrader_execution import BacktestExecutionResult
from app.backtesting.backtrader_feed import VerifiedBacktraderFeedAdapter
from app.backtesting.backtrader_net_outcome import _canonical_json
from app.backtesting.partial_fill_cost_bridge import (
    CanonicalPartialFillCostResult,
    canonical_partial_fill_cost_request,
    partial_fill_settlement_matches_request,
)
from app.backtesting.staged_fill_execution import (
    execute_plan_from_staged_visible_fills,
)
from app.backtesting.visible_queue_depletion import VisibleQueueDepletionResult


class PartialFillNetOutcomeError(ValueError):
    """Stable fail-closed partial-fill projection error."""


def project_partial_fill_net_outcome(
    envelope: CanonicalBacktestOrderPlan,
    execution: BacktestExecutionResult,
    feed: VerifiedBacktraderFeedAdapter,
    evidence: VisibleQueueDepletionResult,
    settlement: CanonicalPartialFillCostResult,
) -> str:
    try:
        evidence = VisibleQueueDepletionResult.model_validate(
            evidence.model_dump(mode="json")
        )
    except Exception as exc:
        raise PartialFillNetOutcomeError(
            "partial_fill_net_outcome_evidence_invalid"
        ) from exc
    try:
        settlement = CanonicalPartialFillCostResult.model_validate(
            settlement.model_dump(mode="json")
        )
    except Exception as exc:
        raise PartialFillNetOutcomeError(
            "partial_fill_net_outcome_settlement_invalid"
        ) from exc
    plan = envelope.plan
    if (
        type(envelope) is not CanonicalBacktestOrderPlan
        or type(execution) is not BacktestExecutionResult
        or not isinstance(feed, VerifiedBacktraderFeedAdapter)
        or feed.dataset_id != envelope.dataset_id
        or feed.dataset_checksum != envelope.dataset_checksum
        or feed.symbol != plan.symbol
        or feed.timeframe != envelope.timeframe
        or feed.market_type != plan.market_type
        or feed.source_network != evidence.source_network
        or feed.market_data_venue != evidence.market_data_venue
    ):
        raise PartialFillNetOutcomeError(
            "partial_fill_net_outcome_evidence_invalid"
        )
    try:
        replayed = execute_plan_from_staged_visible_fills(
            envelope, feed.bars, evidence
        )
    except Exception as exc:
        raise PartialFillNetOutcomeError(
            "partial_fill_net_outcome_evidence_invalid"
        ) from exc
    if replayed != execution:
        raise PartialFillNetOutcomeError(
            "partial_fill_net_outcome_execution_mismatch"
        )
    try:
        request = canonical_partial_fill_cost_request(
            envelope, evidence, replayed
        )
    except Exception as exc:
        raise PartialFillNetOutcomeError(
            "partial_fill_net_outcome_execution_mismatch"
        ) from exc
    if not partial_fill_settlement_matches_request(settlement, request):
        raise PartialFillNetOutcomeError(
            "partial_fill_net_outcome_settlement_invalid"
        )

    terminal = replayed.events[-1]
    fill_events = replayed.events[:-1]
    result: dict[str, Any] = {
        "schema_version": "canonical-backtest-partial-fill-net-outcome.v1",
        "cost_basis_version": settlement.cost_policy_version,
        "cost_evidence": settlement.cost_evidence,
        "costs_are_certified": False,
        "fills_are_certified": False,
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
        "maker_fill_result_hash": evidence.result_hash,
        "maker_fill_trace_hash": evidence.trace_hash,
        "partial_fill_cost_request_hash": settlement.request_hash,
        "partial_fill_cost_result_hash": settlement.result_hash,
        "consumed_fill_count": replayed.consumed_fill_count,
        "consumed_fill_source_record_ids": [
            event.source_record_id for event in fill_events
        ],
        "first_fill_happened_at": _time(fill_events[0].happened_at),
        "last_fill_happened_at": _time(fill_events[-1].happened_at),
        "terminal_event_kind": terminal.kind,
        "terminal_source_record_id": terminal.source_record_id,
        "terminal_happened_at": _time(terminal.happened_at),
        "target_id": settlement.target_id,
        "entry_price": Decimal(str(plan.entry_price)),
        "exit_price": terminal.price,
        "planned_quantity_base": Decimal(settlement.planned_quantity_base),
        "filled_quantity_base": Decimal(settlement.filled_quantity_base),
        "cancelled_residual_quantity_base": Decimal(
            settlement.remaining_quantity_base
        ),
        "gross_pnl_quote": Decimal(settlement.gross_pnl_quote),
        "entry_fee_quote": Decimal(settlement.entry_fee_quote),
        "exit_fee_quote": Decimal(settlement.exit_fee_quote),
        "entry_spread_cost_quote": Decimal(
            settlement.entry_spread_cost_quote
        ),
        "exit_spread_cost_quote": Decimal(settlement.exit_spread_cost_quote),
        "entry_slippage_cost_quote": Decimal(
            settlement.entry_slippage_cost_quote
        ),
        "exit_slippage_cost_quote": Decimal(
            settlement.exit_slippage_cost_quote
        ),
        "planned_adverse_funding_cost_quote": Decimal(
            settlement.planned_adverse_funding_cost_quote
        ),
        "total_planned_cost_quote": Decimal(
            settlement.total_planned_cost_quote
        ),
        "gross_stop_risk_quote": Decimal(settlement.gross_stop_risk_quote),
        "total_stop_risk_quote": Decimal(settlement.total_stop_risk_quote),
        "net_pnl_quote": Decimal(settlement.net_pnl_quote),
        "net_r": Decimal(settlement.net_r),
        "result_is_live_proof": False,
    }
    result["outcome_hash"] = "sha256:" + hashlib.sha256(
        _canonical_json(result).encode()
    ).hexdigest()
    return _canonical_json(result) + "\n"


def _time(value) -> str:
    return value.isoformat(timespec="microseconds").replace("+00:00", "Z")
