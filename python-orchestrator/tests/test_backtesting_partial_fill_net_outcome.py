from __future__ import annotations

from dataclasses import replace
from decimal import Decimal
import hashlib
import json

import pytest

from app.backtesting.partial_fill_cost_bridge import (
    CanonicalPartialFillCostResult,
    _ordered_json,
    canonical_partial_fill_cost_request,
)
from app.backtesting.partial_fill_net_outcome import (
    PartialFillNetOutcomeError,
    project_partial_fill_net_outcome,
)
from app.backtesting.staged_fill_execution import execute_plan_from_staged_visible_fills
from tests.test_backtesting_backtrader_runtime import _feed, _v2_plan
from tests.test_backtesting_partial_fill_cost_bridge import _result_payload
from tests.test_backtesting_staged_fill_execution import _evidence


def _case():
    feed = _feed()
    plan = _v2_plan(feed)
    evidence = _evidence(plan, (("a", 0, 45, Decimal("1")),))
    execution = execute_plan_from_staged_visible_fills(plan, feed.bars, evidence)
    request = canonical_partial_fill_cost_request(plan, evidence, execution)
    settlement = CanonicalPartialFillCostResult.model_validate(
        _result_payload(request)
    )
    return plan, feed, evidence, execution, settlement


def test_projects_formula_free_php_settlement_with_fill_prefix_lineage() -> None:
    plan, feed, evidence, execution, settlement = _case()

    encoded = project_partial_fill_net_outcome(
        plan, execution, feed, evidence, settlement
    )
    outcome = json.loads(encoded, parse_float=Decimal, parse_int=Decimal)

    assert outcome["schema_version"] == (
        "canonical-backtest-partial-fill-net-outcome.v1"
    )
    assert outcome["cost_basis_version"] == "canonical-plan-partial-quantity.v1"
    assert outcome["filled_quantity_base"] == Decimal("1")
    assert outcome["cancelled_residual_quantity_base"] == Decimal("1.497")
    assert outcome["consumed_fill_count"] == 1
    assert outcome["partial_fill_cost_result_hash"] == settlement.result_hash
    assert outcome["maker_fill_result_hash"] == evidence.result_hash
    assert outcome["gross_pnl_quote"] == Decimal("2.5")
    assert outcome["net_pnl_quote"] == Decimal("2.3481")
    assert outcome["costs_are_certified"] is False
    assert outcome["fills_are_certified"] is False
    assert outcome["result_is_live_proof"] is False
    assert outcome["outcome_hash"].startswith("sha256:")
    assert project_partial_fill_net_outcome(
        plan, execution, feed, evidence, settlement
    ) == encoded


def test_projector_replays_execution_and_rejects_forged_terminal_candle() -> None:
    plan, feed, evidence, execution, settlement = _case()
    fill, terminal = execution.events
    forged = replace(
        execution,
        events=(fill, replace(terminal, source_record_id="forged-bar")),
    )

    with pytest.raises(PartialFillNetOutcomeError, match="execution_mismatch"):
        project_partial_fill_net_outcome(plan, forged, feed, evidence, settlement)


def test_projector_rejects_settlement_substitution_and_wrong_evidence() -> None:
    plan, feed, evidence, execution, settlement = _case()
    forged_settlement = settlement.model_copy(
        update={"result_hash": "sha256:" + "f" * 64}
    )
    with pytest.raises(PartialFillNetOutcomeError, match="settlement_invalid"):
        project_partial_fill_net_outcome(
            plan, execution, feed, evidence, forged_settlement
        )

    forged_evidence = evidence.model_copy(
        update={"result_hash": "sha256:" + "f" * 64}
    )
    with pytest.raises(PartialFillNetOutcomeError, match="evidence_invalid"):
        project_partial_fill_net_outcome(
            plan, execution, feed, forged_evidence, settlement
        )

    payload = settlement.model_dump(mode="json")
    payload["maker_fill_result_hash"] = "sha256:" + "d" * 64
    payload["result_hash"] = "sha256:" + hashlib.sha256(
        _ordered_json(
            {key: value for key, value in payload.items() if key != "result_hash"}
        )
    ).hexdigest()
    unrelated_settlement = CanonicalPartialFillCostResult.model_validate(payload)
    with pytest.raises(PartialFillNetOutcomeError, match="settlement_invalid"):
        project_partial_fill_net_outcome(
            plan, execution, feed, evidence, unrelated_settlement
        )


def test_projector_rejects_feed_identity_or_missing_replay_coverage() -> None:
    plan, feed, evidence, execution, settlement = _case()
    object.__setattr__(feed, "market_data_venue", "hyperliquid")
    with pytest.raises(PartialFillNetOutcomeError, match="evidence_invalid"):
        project_partial_fill_net_outcome(
            plan, execution, feed, evidence, settlement
        )

    plan, feed, evidence, execution, settlement = _case()
    object.__setattr__(feed, "bars", ())
    with pytest.raises(PartialFillNetOutcomeError, match="evidence_invalid"):
        project_partial_fill_net_outcome(
            plan, execution, feed, evidence, settlement
        )


def test_projector_contains_no_cost_formula_inputs() -> None:
    from pathlib import Path

    source = (
        Path(__file__).parents[1]
        / "app/backtesting/partial_fill_net_outcome.py"
    ).read_text(encoding="utf-8")
    for forbidden in (
        "maker_fee_rate",
        "taker_fee_rate",
        "entry_spread_rate",
        "entry_slippage_rate",
        "funding_rate",
    ):
        assert forbidden not in source
