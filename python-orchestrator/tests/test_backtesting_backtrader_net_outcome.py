from datetime import datetime, timedelta, timezone
from dataclasses import replace
from decimal import Decimal
import json
from pathlib import Path

from app.backtesting.backtrader_contracts import CanonicalBacktestOrderPlan, _php_plan_hash
from app.backtesting.backtrader_execution import BacktestExecutionEvent, BacktestExecutionResult
from app.backtesting.backtrader_feed import VerifiedBacktraderBar, VerifiedBacktraderFeedAdapter
import pytest

from app.backtesting.backtrader_net_outcome import (
    BacktestNetOutcomeError,
    _canonical_json,
    _decimal,
    project_plan_bound_net_outcome,
)


FIXTURE = Path(__file__).parent / "fixtures/backtesting/php-canonical-order-plan.json"
UTC = timezone.utc


def _plan() -> CanonicalBacktestOrderPlan:
    return CanonicalBacktestOrderPlan.model_validate_json(FIXTURE.read_text())


def _feed(*, stop: bool = False) -> VerifiedBacktraderFeedAdapter:
    plan = _plan()
    entry = (
        VerifiedBacktraderBar(
            source_record_id="bar-entry",
            open_at=datetime(2026, 8, 10, 12, 0, tzinfo=UTC),
            close_at=datetime(2026, 8, 10, 12, 1, tzinfo=UTC),
            available_at=datetime(2026, 8, 10, 12, 1, tzinfo=UTC),
            open=Decimal("100"), high=Decimal("101"), low=Decimal("99"),
            close=Decimal("100"), volume=Decimal("10"),
        ),
    )
    target = (
        VerifiedBacktraderBar(
            source_record_id="bar-target",
            open_at=datetime(2026, 8, 10, 12, 1, tzinfo=UTC),
            close_at=datetime(2026, 8, 10, 12, 2, tzinfo=UTC),
            available_at=datetime(2026, 8, 10, 12, 2, tzinfo=UTC),
            open=Decimal("100"), high=Decimal("103"), low=Decimal("99"),
            close=Decimal("102"), volume=Decimal("10"),
        ),
    )
    stopped = (
        VerifiedBacktraderBar(
            source_record_id="bar-stop",
            open_at=datetime(2026, 8, 10, 12, 1, tzinfo=UTC),
            close_at=datetime(2026, 8, 10, 12, 2, tzinfo=UTC),
            available_at=datetime(2026, 8, 10, 12, 2, tzinfo=UTC),
            open=Decimal("100"), high=Decimal("101"), low=Decimal("98"),
            close=Decimal("99"), volume=Decimal("10"),
        ),
    )
    bars = entry + (stopped if stop else target)
    feed = object.__new__(VerifiedBacktraderFeedAdapter)
    for field, value in {
        "bars": bars,
        "dataset_id": plan.dataset_id,
        "dataset_checksum": plan.dataset_checksum,
        "symbol": plan.plan.symbol,
        "timeframe": plan.timeframe,
        "source_network": "mainnet",
        "market_data_venue": "okx",
        "market_type": plan.plan.market_type,
    }.items():
        object.__setattr__(feed, field, value)
    return feed


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


def test_target_outcome_projects_plan_bound_php_cost_components() -> None:
    encoded = project_plan_bound_net_outcome(_plan(), _target_execution(), _feed())
    outcome = json.loads(encoded)

    assert outcome["schema_version"] == "canonical-backtest-planned-net-outcome.v1"
    assert outcome["costs_are_certified"] is False
    assert outcome["cost_evidence"] == "canonical_plan_projection"
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
    assert outcome["cost_basis_version"] == "canonical-order-plan-bound-costs.v1"
    assert outcome["dataset_id"] == _plan().dataset_id
    assert outcome["dataset_checksum"] == _plan().dataset_checksum
    assert outcome["plan_hash"] == _plan().plan.plan_hash
    assert outcome["config_hash"] == _plan().plan.config_hash
    assert outcome["cost_input_hash"] == _plan().plan.cost_input_hash
    assert outcome["outcome_hash"].startswith("sha256:")
    assert project_plan_bound_net_outcome(_plan(), _target_execution(), _feed()) == encoded


def test_stop_outcome_is_signed_and_reconciles_plan_bound_risk_components() -> None:
    outcome = json.loads(project_plan_bound_net_outcome(_plan(), _stop_execution(), _feed(stop=True)))

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
            "execution_evidence_mismatch",
        ),
    ],
)
def test_rejects_unsupported_or_unknown_terminal_execution(
    execution: BacktestExecutionResult,
    error: str,
) -> None:
    with pytest.raises(BacktestNetOutcomeError, match=error):
        project_plan_bound_net_outcome(_plan(), execution, _feed())


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
        project_plan_bound_net_outcome(_plan(), replace(execution, events=(execution.events[0], forged)), _feed())


def test_rejects_wrong_entry_price_and_non_chronological_trace() -> None:
    execution = _target_execution()
    with pytest.raises(BacktestNetOutcomeError, match="execution_evidence_mismatch"):
        project_plan_bound_net_outcome(
            _plan(),
            replace(
                execution,
                events=(replace(execution.events[0], price=Decimal("100.2")), execution.events[1]),
            ),
            _feed(),
        )
    with pytest.raises(BacktestNetOutcomeError, match="dataset_evidence_mismatch"):
        project_plan_bound_net_outcome(
            _plan(),
            replace(
                execution,
                events=(
                    replace(
                        execution.events[0],
                        happened_at=execution.events[1].happened_at + timedelta(seconds=1),
                    ),
                    execution.events[1],
                ),
            ),
            _feed(),
        )


def test_rejects_non_reconciling_plan_bound_cost_total() -> None:
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
        project_plan_bound_net_outcome(envelope, replace(execution, events=events), _feed())


def test_rejects_forged_plan_instance_and_stop_price() -> None:
    forged_plan = _plan().model_copy(
        update={"plan": _plan().plan.model_copy(update={"entry_fee": 99.0})}
    )
    with pytest.raises(BacktestNetOutcomeError, match="plan_invalid"):
        project_plan_bound_net_outcome(forged_plan, _target_execution(), _feed())

    execution = _stop_execution()
    with pytest.raises(BacktestNetOutcomeError, match="execution_evidence_mismatch"):
        project_plan_bound_net_outcome(
            _plan(),
            replace(
                execution,
                events=(execution.events[0], replace(execution.events[1], price=Decimal("98.3"))),
            ), _feed(stop=True),
        )


def test_rejects_terminal_record_or_time_not_in_verified_dataset() -> None:
    execution = _target_execution()
    for forged_terminal in (
        replace(execution.events[1], source_record_id="not-in-dataset"),
        replace(execution.events[1], happened_at=datetime(2026, 8, 10, 12, 2, 1, tzinfo=UTC)),
    ):
        with pytest.raises(BacktestNetOutcomeError, match="dataset_evidence_mismatch"):
            project_plan_bound_net_outcome(
                _plan(),
                replace(execution, events=(execution.events[0], forged_terminal)),
                _feed(),
            )


def test_rejects_fill_that_does_not_match_execution_replayed_from_feed() -> None:
    execution = _target_execution()
    forged_terminal = replace(
        execution.events[1],
        source_record_id="bar-entry",
        happened_at=execution.events[0].happened_at,
    )
    with pytest.raises(BacktestNetOutcomeError, match="execution_evidence_mismatch"):
        project_plan_bound_net_outcome(
            _plan(),
            replace(execution, events=(execution.events[0], forged_terminal)),
            _feed(),
        )


def test_rejects_feed_identity_mismatch() -> None:
    feed = _feed()
    object.__setattr__(feed, "dataset_checksum", "sha256:" + "b" * 64)
    with pytest.raises(BacktestNetOutcomeError, match="dataset_evidence_mismatch"):
        project_plan_bound_net_outcome(_plan(), _target_execution(), feed)

    duplicate = _feed()
    object.__setattr__(duplicate, "bars", duplicate.bars + (duplicate.bars[0],))
    with pytest.raises(BacktestNetOutcomeError, match="dataset_evidence_mismatch"):
        project_plan_bound_net_outcome(_plan(), _target_execution(), duplicate)


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
