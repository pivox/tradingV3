from datetime import datetime, timedelta, timezone
from dataclasses import replace
from decimal import Decimal
import json
from pathlib import Path

from app.backtesting.backtrader_contracts import CanonicalBacktestOrderPlan, _php_plan_hash
from app.backtesting.backtrader_execution import BacktestExecutionEvent, BacktestExecutionResult
import pytest

from app.backtesting.backtrader_net_outcome import (
    BacktestNetOutcomeError,
    _canonical_json,
    _decimal,
    settle_authenticated_outcome,
)


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


def _stop_execution() -> BacktestExecutionResult:
    target = _target_execution()
    return BacktestExecutionResult(
        status="closed",
        reason_code="stop_filled",
        events=(
            target.events[0],
            replace(
                target.events[1],
                kind="stop_filled",
                source_record_id="bar-stop",
                price=Decimal("98.4"),
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


def test_stop_outcome_is_signed_and_reconciles_authenticated_risk_components() -> None:
    outcome = json.loads(settle_authenticated_outcome(_plan(), _stop_execution()))

    assert outcome["terminal_event_kind"] == "stop_filled"
    assert outcome["target_id"] is None
    assert outcome["gross_pnl_quote"] == -4.2449
    assert outcome["entry_fee_quote"] == 0.12497485
    assert outcome["exit_fee_quote"] == 0.1228524
    assert outcome["entry_spread_cost_quote"] == 0.02499497
    assert outcome["exit_spread_cost_quote"] == 0.02457048
    assert outcome["entry_slippage_cost_quote"] == 0.02499497
    assert outcome["exit_slippage_cost_quote"] == 0.02457048
    assert outcome["planned_adverse_funding_cost_quote"] == 0.02499497
    assert outcome["total_planned_cost_quote"] == 0.37195312
    assert outcome["net_pnl_quote"] == -4.61685312
    assert outcome["net_r"] == -1


@pytest.mark.parametrize(
    ("execution", "error"),
    [
        (BacktestExecutionResult("not_executed", "entry_expired", ()), "execution_unsupported"),
        (
            BacktestExecutionResult(
                "closed", "holding_expired",
                (_target_execution().events[0], replace(_target_execution().events[1], kind="holding_expired")),
            ),
            "execution_unsupported",
        ),
        (
            replace(
                _target_execution(),
                events=(_target_execution().events[0], replace(_target_execution().events[1], price=Decimal("103.0"))),
            ),
            "target_unknown",
        ),
    ],
)
def test_rejects_unsupported_or_unknown_terminal_execution(
    execution: BacktestExecutionResult,
    error: str,
) -> None:
    with pytest.raises(BacktestNetOutcomeError, match=error):
        settle_authenticated_outcome(_plan(), execution)


@pytest.mark.parametrize(
    "change",
    [
        {"dataset_id": "backtest-dataset-" + "b" * 64},
        {"plan_hash": "sha256:" + "b" * 64},
        {"config_hash": "sha256:" + "b" * 64},
        {"quantity": 1.0},
        {"stop_price": 97.0},
    ],
)
def test_rejects_forged_event_lineage(change: dict) -> None:
    execution = _target_execution()
    forged = replace(execution.events[1], **change)
    with pytest.raises(BacktestNetOutcomeError, match="lineage_mismatch"):
        settle_authenticated_outcome(_plan(), replace(execution, events=(execution.events[0], forged)))


def test_rejects_wrong_entry_price_and_non_chronological_trace() -> None:
    execution = _target_execution()
    for forged_entry in (
        replace(execution.events[0], price=Decimal("100.2")),
        replace(
            execution.events[0],
            happened_at=execution.events[1].happened_at + timedelta(seconds=1),
        ),
    ):
        with pytest.raises(BacktestNetOutcomeError, match="execution_mismatch"):
            settle_authenticated_outcome(
                _plan(), replace(execution, events=(forged_entry, execution.events[1]))
            )


def test_rejects_forged_authenticated_cost_total() -> None:
    payload = json.loads(FIXTURE.read_text())
    payload["plan"]["targets"][0]["netReward"] = 99.0
    unsigned = {key: value for key, value in payload["plan"].items() if key != "planHash"}
    payload["plan"]["planHash"] = _php_plan_hash(unsigned)
    envelope = CanonicalBacktestOrderPlan.model_validate(payload)
    execution = _target_execution()
    events = tuple(
        replace(event, plan_hash=envelope.plan.plan_hash) for event in execution.events
    )
    with pytest.raises(BacktestNetOutcomeError, match="cost_mismatch"):
        settle_authenticated_outcome(envelope, replace(execution, events=events))


def test_rejects_forged_plan_instance_and_stop_price() -> None:
    forged_plan = _plan().model_copy(
        update={"plan": _plan().plan.model_copy(update={"entry_fee": 99.0})}
    )
    with pytest.raises(BacktestNetOutcomeError, match="plan_invalid"):
        settle_authenticated_outcome(forged_plan, _target_execution())

    execution = _stop_execution()
    with pytest.raises(BacktestNetOutcomeError, match="execution_mismatch"):
        settle_authenticated_outcome(
            _plan(),
            replace(
                execution,
                events=(execution.events[0], replace(execution.events[1], price=Decimal("98.3"))),
            ),
        )


def test_exact_encoder_rejects_non_finite_decimals_and_supports_sequences() -> None:
    assert _canonical_json((Decimal("1.25"), True)) == "[1.25,true]"
    for value in (Decimal("NaN"), Decimal("Infinity")):
        with pytest.raises(BacktestNetOutcomeError, match="number_invalid"):
            _decimal(value)
        with pytest.raises(BacktestNetOutcomeError, match="number_invalid"):
            _canonical_json(value)


def test_settlement_does_not_reimplement_cost_formulas() -> None:
    source = (
        Path(__file__).parents[1] / "app/backtesting/backtrader_net_outcome.py"
    ).read_text().lower()
    for forbidden in (
        "maker_fee_rate", "taker_fee_rate", "funding_rate", "position_notional",
        "spread_rate", "slippage_rate", "contract_size",
    ):
        assert forbidden not in source
