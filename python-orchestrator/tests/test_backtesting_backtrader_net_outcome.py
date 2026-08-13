from datetime import datetime, timezone
from decimal import Decimal
import json
from pathlib import Path

from app.backtesting.backtrader_contracts import CanonicalBacktestOrderPlan
from app.backtesting.backtrader_execution import BacktestExecutionEvent, BacktestExecutionResult
from app.backtesting.backtrader_net_outcome import settle_authenticated_outcome


FIXTURE = Path(__file__).parent / "fixtures/backtesting/php-canonical-order-plan.json"
UTC = timezone.utc


def _plan() -> CanonicalBacktestOrderPlan:
    return CanonicalBacktestOrderPlan.model_validate_json(FIXTURE.read_text())


def _target_execution() -> BacktestExecutionResult:
    plan = _plan()
    common = {
        "quantity": plan.plan.quantity,
        "stop_price": plan.plan.stop_price,
        "plan_hash": plan.plan.plan_hash,
        "config_hash": plan.plan.config_hash,
        "dataset_id": plan.dataset_id,
    }
    return BacktestExecutionResult(
        status="closed",
        reason_code="target_filled",
        events=(
            BacktestExecutionEvent(
                kind="entry_filled", happened_at=datetime(2026, 8, 10, 12, 1, tzinfo=UTC),
                source_record_id="bar-entry", price=Decimal("100.1"), **common,
            ),
            BacktestExecutionEvent(
                kind="target_filled", happened_at=datetime(2026, 8, 10, 12, 2, tzinfo=UTC),
                source_record_id="bar-target", price=Decimal("102.6"), **common,
            ),
        ),
    )


def test_target_outcome_uses_only_authenticated_php_cost_components() -> None:
    encoded = settle_authenticated_outcome(_plan(), _target_execution())
    outcome = json.loads(encoded)

    assert outcome["schema_version"] == "canonical-backtest-net-outcome.v1"
    assert outcome["terminal_event_kind"] == "target_filled"
    assert outcome["target_id"] == "tp1"
    assert outcome["gross_pnl_quote"] == 6.2425
    assert outcome["entry_fee_quote"] == 0.12497485
    assert outcome["exit_fee_quote"] == 0.1280961
    assert outcome["entry_spread_cost_quote"] == 0.02499497
    assert outcome["exit_spread_cost_quote"] == 0.02561922
    assert outcome["entry_slippage_cost_quote"] == 0.02499497
    assert outcome["exit_slippage_cost_quote"] == 0.02561922
    assert outcome["planned_adverse_funding_cost_quote"] == 0.02499497
    assert outcome["total_planned_cost_quote"] == 0.37929430
    assert outcome["net_pnl_quote"] == 5.8632057
    assert outcome["net_r"] == 1.2699571651090342
    assert outcome["funding_evidence"] == "canonical_plan_provision"
    assert outcome["cost_basis_version"] == "canonical-order-plan-authenticated-costs.v1"
    assert outcome["dataset_id"] == _plan().dataset_id
    assert outcome["dataset_checksum"] == _plan().dataset_checksum
    assert outcome["plan_hash"] == _plan().plan.plan_hash
    assert outcome["config_hash"] == _plan().plan.config_hash
    assert outcome["cost_input_hash"] == _plan().plan.cost_input_hash
    assert outcome["outcome_hash"].startswith("sha256:")
    assert settle_authenticated_outcome(_plan(), _target_execution()) == encoded
