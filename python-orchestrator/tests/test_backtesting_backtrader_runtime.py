from datetime import datetime, timedelta, timezone
from decimal import Decimal
import json
from pathlib import Path
import re
from copy import deepcopy
import pytest

from app.backtesting.backtrader_contracts import CanonicalBacktestOrderPlan, _php_plan_hash
from app.backtesting.backtrader_execution import (
    BacktestExecutionError,
    execute_plan,
    execute_plan_from_visible_fill,
)
from app.backtesting.backtrader_net_outcome import (
    BacktestNetOutcomeError,
    project_plan_bound_net_outcome,
)
from app.backtesting.backtrader_feed import VerifiedBacktraderFeedAdapter
from app.backtesting.backtrader_runtime import CanonicalBacktraderRuntime
from app.backtesting.backtrader_runtime import _canonical
from app.backtesting.contracts import MarketType
from app.backtesting.dataset import CandleRecord, DatasetBuilder, DatasetSerializer, DatasetSourceIdentity, Timeframe
from app.backtesting.historical_funding import (
    HistoricalFundingScheduleArtifacts,
    HistoricalFundingRecord,
    VerifiedHistoricalFundingSchedule,
    serialize_historical_funding_schedule,
)
from app.backtesting.visible_queue_depletion import (
    VisibleQueueDepletionResult,
    _decimal_string as _queue_decimal,
    _hash as _queue_hash,
)
from tests.funding_support import trusted_bridge_for


UTC = timezone.utc
FIXTURE = Path(__file__).parent / "fixtures/backtesting/php-canonical-order-plan.json"


def _plan() -> CanonicalBacktestOrderPlan:
    value = json.loads(FIXTURE.read_text())
    value["timeframe"] = "1m"
    return CanonicalBacktestOrderPlan.model_validate(value)


def _v2_plan(feed: VerifiedBacktraderFeedAdapter) -> CanonicalBacktestOrderPlan:
    value = json.loads(FIXTURE.read_text())
    value["schema_version"] = "canonical-backtest-order-plan.v2"
    value["timeframe"] = "1m"
    value["dataset_id"] = feed.dataset_id
    value["dataset_checksum"] = feed.dataset_checksum
    ordered_plan = {}
    for key, item in value["plan"].items():
        ordered_plan[key] = item
        if key == "orderType":
            ordered_plan["marketFallback"] = False
    ordered_plan["planHash"] = _php_plan_hash(
        {key: item for key, item in ordered_plan.items() if key != "planHash"}
    )
    value["plan"] = ordered_plan
    return CanonicalBacktestOrderPlan.model_validate(value)


def _queue_evidence(
    plan: CanonicalBacktestOrderPlan,
    *,
    status: str = "filled",
    staged: bool = False,
) -> VisibleQueueDepletionResult:
    quantity = Decimal(str(plan.plan.quantity)) * Decimal(str(plan.plan.contract_size))
    if status == "unfilled":
        trace = ()
        filled = Decimal(0)
        remaining = quantity
    elif staged:
        filled = quantity
        remaining = Decimal(0)
        trace = (
            {
                "source_record_id": "a" * 64,
                "source_event_position": 7,
                "happened_at": "2026-08-10T12:00:20.000000Z",
                "available_at": "2026-08-10T12:00:30.000000Z",
                "price": "100.1",
                "trade_base_quantity": "2",
                "queue_before_base": "1",
                "queue_after_base": "0",
                "fill_quantity_base": "1",
                "cumulative_fill_quantity_base": "1",
                "remaining_order_quantity_base": _queue_decimal(quantity - 1),
                "evidence_kind": "at_price_depletion",
            },
            {
                "source_record_id": "c" * 64,
                "source_event_position": 8,
                "happened_at": "2026-08-10T12:00:35.000000Z",
                "available_at": "2026-08-10T12:00:45.000000Z",
                "price": "100.1",
                "trade_base_quantity": _queue_decimal(quantity - 1),
                "queue_before_base": "0",
                "queue_after_base": "0",
                "fill_quantity_base": _queue_decimal(quantity - 1),
                "cumulative_fill_quantity_base": _queue_decimal(quantity),
                "remaining_order_quantity_base": "0",
                "evidence_kind": "at_price_depletion",
            },
        )
    else:
        filled = quantity if status == "filled" else Decimal("1")
        remaining = quantity - filled
        trace = ({
            "source_record_id": "a" * 64,
            "source_event_position": 7,
            "happened_at": "2026-08-10T12:00:30.000000Z",
            "available_at": "2026-08-10T12:00:45.000000Z",
            "price": "100.1",
            "trade_base_quantity": _queue_decimal(Decimal("1") + filled),
            "queue_before_base": "1",
            "queue_after_base": "0",
            "fill_quantity_base": _queue_decimal(filled),
            "cumulative_fill_quantity_base": _queue_decimal(filled),
            "remaining_order_quantity_base": _queue_decimal(remaining),
            "evidence_kind": "at_price_depletion",
        },)
    payload = {
        "schema_version": "visible-queue-depletion-result.v1",
        "policy_version": "visible-queue-depletion.v1",
        "dataset_id": plan.dataset_id,
        "dataset_checksum": plan.dataset_checksum,
        "plan_hash": plan.plan.plan_hash,
        "config_hash": plan.plan.config_hash,
        "public_book_tape_checksum": "sha256:" + "1" * 64,
        "public_execution_tape_checksum": "sha256:" + "2" * 64,
        "quantity_conversion_tape_checksum": "sha256:" + "3" * 64,
        "source_network": "mainnet",
        "market_data_venue": "okx",
        "market_type": "perpetual",
        "symbol": "BTCUSDT",
        "side": "long",
        "entry_price": "100.1",
        "order_live_at": "2026-08-10T12:00:00.000000Z",
        "effective_deadline_at": "2026-08-10T12:03:00.000000Z",
        "initial_book_source_record_id": "b" * 64,
        "initial_book_source_event_position": 3,
        "initial_visible_queue_base": "1",
        "order_quantity_base": _queue_decimal(quantity),
        "trace": trace,
        "filled_quantity_base": _queue_decimal(filled),
        "remaining_quantity_base": _queue_decimal(remaining),
        "status": status,
        "fills_are_certified": False,
        "queue_evidence": "visible_l1_plus_public_trades",
        "latency_assumption": "available_at_ordering_no_private_ack",
        "result_is_live_proof": False,
        "trace_hash": _queue_hash(trace),
    }
    payload["result_hash"] = _queue_hash(payload)
    return VisibleQueueDepletionResult.model_validate(payload)


def _record(index: int, high: str, low: str) -> CandleRecord:
    opened = datetime(2026, 8, 10, 12, index, tzinfo=UTC)
    return CandleRecord(
        source_record_id=f"runtime-bar-{index}", source_network="mainnet",
        market_data_venue="okx", market_type=MarketType.PERPETUAL,
        symbol="BTCUSDT", timeframe=Timeframe.ONE_MINUTE,
        open_at=opened, close_at=opened + timedelta(minutes=1),
        available_at=opened + timedelta(minutes=1), open="100", high=high,
        low=low, close="100", volume="10", complete=True,
    )


def _feed(
    *,
    unfilled: bool = False,
    same_candle: bool = False,
    fill_bar_stop: bool = False,
    fill_bar_target: bool = False,
) -> VerifiedBacktraderFeedAdapter:
    records = (
        (_record(0, "100", "99"), _record(1, "100", "99"), _record(2, "100", "99"))
        if unfilled
        else ((_record(0, "103", "99"),) if same_candle else (
            _record(
                0,
                "103" if fill_bar_stop or fill_bar_target else "101",
                "98" if fill_bar_stop else "99",
            ),
            _record(1, "103", "99"),
        ))
    )
    source = DatasetSourceIdentity(
        source="paper-okx", source_schema_version="paper.v2",
        source_build_version="fixture.v1", source_checksum="sha256:" + "d" * 64,
        source_network="mainnet", market_data_venue="okx",
        market_type=MarketType.PERPETUAL,
    )
    artifacts = DatasetSerializer.serialize(DatasetBuilder(source).build(records))
    return VerifiedBacktraderFeedAdapter(
        artifacts, symbol="BTCUSDT", timeframe="1m",
        period_start=datetime(2026, 8, 10, 12, 0, tzinfo=UTC),
        period_end=datetime(2026, 8, 10, 12, len(records), tzinfo=UTC),
    )


def _funding_schedule(feed: VerifiedBacktraderFeedAdapter) -> VerifiedHistoricalFundingSchedule:
    records = tuple(
        HistoricalFundingRecord(
            schema_version="historical-funding-record.v1",
            source_record_id=f"runtime-funding-{minute}",
            source_network=feed.source_network,
            market_data_venue=feed.market_data_venue,
            market_type="perpetual",
            symbol=feed.symbol,
            funding_at=datetime(2026, 8, 10, 12, minute, tzinfo=UTC),
            available_at=datetime(2026, 8, 10, 12, minute, tzinfo=UTC),
            funding_rate="0.0001",
            mark_price="100",
            interval_seconds=60,
        )
        for minute in (1, 2)
    )
    return VerifiedHistoricalFundingSchedule(serialize_historical_funding_schedule(
        dataset_id=feed.dataset_id,
        dataset_checksum=feed.dataset_checksum,
        coverage_start=datetime(2026, 8, 10, 12, 0, tzinfo=UTC),
        coverage_end=datetime(2026, 8, 10, 12, 2, tzinfo=UTC),
        records=records,
    ))


def test_runtime_uses_backtrader_and_is_byte_deterministic() -> None:
    feed = _feed()
    plan = _plan().model_copy(update={"dataset_id": feed.dataset_id, "dataset_checksum": feed.dataset_checksum})
    first = CanonicalBacktraderRuntime().run(plan, feed)
    second = CanonicalBacktraderRuntime().run(plan, feed)

    assert first == second
    decoded = json.loads(first)
    assert decoded["engine_version"] == "backtrader-1.9.78.123+canonical-runtime.v1"
    assert decoded["status"] == "closed"
    assert decoded["reason_code"] == "target_filled"
    assert [item["kind"] for item in decoded["events"]] == ["entry_filled", "target_filled"]
    assert decoded["result_is_live_proof"] is False
    assert decoded["input_hash"].startswith("sha256:")
    assert decoded["result_hash"].startswith("sha256:")
    assert decoded["net_outcome"]["schema_version"] == "canonical-backtest-planned-net-outcome.v1"
    assert decoded["net_outcome"]["costs_are_certified"] is False
    assert decoded["net_outcome"]["cost_evidence"] == "canonical_plan_projection"
    assert decoded["net_outcome"]["net_pnl_quote"] == 5.8632057
    assert decoded["net_outcome"]["funding_evidence"] == "canonical_plan_provision"
    assert decoded["net_outcome"]["outcome_hash"].startswith("sha256:")
    assert '"total_planned_cost_quote":0.37929430' in first

    golden = Path(__file__).parent / "fixtures/backtesting/backtrader-runtime-result.json"
    if golden.exists():
        assert first == golden.read_text(encoding="utf-8")


def test_runtime_invokes_historical_funding_authority_protocol() -> None:
    feed = _feed()
    plan = _plan().model_copy(update={"dataset_id": feed.dataset_id, "dataset_checksum": feed.dataset_checksum})
    result = json.loads(CanonicalBacktraderRuntime().run(
        plan,
        feed,
        funding_schedule=_funding_schedule(feed),
        funding_bridge=trusted_bridge_for(applied_ids=("runtime-funding-2",)),
    ))

    outcome = result["net_outcome"]
    assert outcome["schema_version"] == "canonical-backtest-historical-net-outcome.v1"
    assert outcome["funding_evidence"] == "integrity_bound_historical_schedule"
    assert outcome["applied_funding_source_record_ids"] == ["runtime-funding-2"]
    assert outcome["historical_funding_cashflow_quote"] == -0.02497
    assert outcome["costs_are_certified"] is False
    assert result["funding_schedule_checksum"] == _funding_schedule(feed).schedule_checksum


def test_runtime_input_hash_changes_with_historical_schedule() -> None:
    feed = _feed()
    plan = _plan().model_copy(update={"dataset_id": feed.dataset_id, "dataset_checksum": feed.dataset_checksum})
    plain = json.loads(CanonicalBacktraderRuntime().run(plan, feed))
    historical = json.loads(CanonicalBacktraderRuntime().run(
        plan,
        feed,
        funding_schedule=_funding_schedule(feed),
        funding_bridge=trusted_bridge_for(applied_ids=("runtime-funding-2",)),
    ))
    assert plain["input_hash"] != historical["input_hash"]


def test_runtime_files_do_not_reimplement_trading_authorities() -> None:
    root = Path(__file__).parents[1] / "app/backtesting"
    source = "\n".join(
        (root / name).read_text()
        for name in ("backtrader_feed.py", "backtrader_execution.py", "backtrader_runtime.py")
    ).lower()
    for forbidden in ("rsi", "macd", "position_sizer", "risk_rate *", "entryzonecalculator"):
        assert re.search(rf"\b{re.escape(forbidden)}\b", source) is None


def test_runtime_canonical_json_preserves_decimal_event_prices() -> None:
    assert _canonical({"price": Decimal("100.09999999999999999")}) == (
        '{"price":100.09999999999999999}'
    )


def test_runtime_rejects_plan_feed_identity_mismatch() -> None:
    forged = deepcopy(json.loads(FIXTURE.read_text()))
    forged["timeframe"] = "5m"
    with pytest.raises(ValueError, match="identity_mismatch"):
        CanonicalBacktraderRuntime().run(CanonicalBacktestOrderPlan.model_validate(forged), _feed())


def test_runtime_revalidates_a_forged_model_instance() -> None:
    feed = _feed()
    plan = _plan().model_copy(
        update={
            "dataset_id": feed.dataset_id,
            "dataset_checksum": feed.dataset_checksum,
            "plan": _plan().plan.model_copy(update={"stop_price": 101.0}),
        }
    )
    with pytest.raises(ValueError, match="plan_hash_mismatch"):
        CanonicalBacktraderRuntime().run(plan, feed)


def test_runtime_rejects_holding_exit_without_plan_bound_cost_branch() -> None:
    feed = _feed()
    payload = json.loads(FIXTURE.read_text())
    payload["plan"]["holdingExpiresAt"] = "2026-08-10T12:01:00.000000+00:00"
    unsigned = {key: value for key, value in payload["plan"].items() if key != "planHash"}
    payload["plan"]["planHash"] = _php_plan_hash(unsigned)
    payload.update(
        dataset_id=feed.dataset_id,
        dataset_checksum=feed.dataset_checksum,
        timeframe="1m",
    )

    with pytest.raises(BacktestNetOutcomeError, match="execution_unsupported"):
        CanonicalBacktraderRuntime().run(CanonicalBacktestOrderPlan.model_validate(payload), feed)


def test_runtime_revalidation_preserves_hash_bearing_null_caps() -> None:
    feed = _feed()
    payload = json.loads(FIXTURE.read_text())
    payload["plan"]["symbolLeverageCap"] = None
    payload["plan"]["marketMaxQuantity"] = None
    unsigned = {key: value for key, value in payload["plan"].items() if key != "planHash"}
    payload["plan"]["planHash"] = _php_plan_hash(unsigned)
    payload.update(dataset_id=feed.dataset_id, dataset_checksum=feed.dataset_checksum, timeframe="1m")
    result = CanonicalBacktraderRuntime().run(CanonicalBacktestOrderPlan.model_validate(payload), feed)
    assert json.loads(result)["status"] == "closed"


def test_runtime_binds_plan_to_feed_market_type() -> None:
    records = tuple(
        item.model_copy(update={"market_type": MarketType.SPOT})
        for item in (_record(0, "101", "99"), _record(1, "103", "99"))
    )
    source = DatasetSourceIdentity(
        source="paper-okx", source_schema_version="paper.v2",
        source_build_version="fixture.v1", source_checksum="sha256:" + "e" * 64,
        source_network="mainnet", market_data_venue="okx", market_type=MarketType.SPOT,
    )
    artifacts = DatasetSerializer.serialize(DatasetBuilder(source).build(records))
    feed = VerifiedBacktraderFeedAdapter(
        artifacts, symbol="BTCUSDT", timeframe="1m",
        period_start=datetime(2026, 8, 10, 12, 0, tzinfo=UTC),
        period_end=datetime(2026, 8, 10, 12, 2, tzinfo=UTC),
    )
    plan = _plan().model_copy(update={"dataset_id": feed.dataset_id, "dataset_checksum": feed.dataset_checksum})
    with pytest.raises(ValueError, match="identity_mismatch"):
        CanonicalBacktraderRuntime().run(plan, feed)


def test_runtime_rejects_noncanonical_funding_authority() -> None:
    class ForgedBridge:
        def settle(self, request):
            raise AssertionError("untrusted authority must never run")

    feed = _feed()
    plan = _plan().model_copy(update={"dataset_id": feed.dataset_id, "dataset_checksum": feed.dataset_checksum})
    with pytest.raises(ValueError, match="funding_authority_invalid"):
        CanonicalBacktraderRuntime().run(
            plan,
            feed,
            funding_schedule=_funding_schedule(feed),
            funding_bridge=ForgedBridge(),  # type: ignore[arg-type]
        )


def test_runtime_preserves_unfilled_result_with_paired_funding_evidence() -> None:
    feed = _feed(unfilled=True)
    plan = _plan().model_copy(update={"dataset_id": feed.dataset_id, "dataset_checksum": feed.dataset_checksum})
    result = json.loads(CanonicalBacktraderRuntime().run(
        plan,
        feed,
        funding_schedule=_funding_schedule(feed),
        funding_bridge=trusted_bridge_for(applied_ids=("must-not-be-applied",)),
    ))

    assert result["status"] == "not_executed"
    assert result["reason_code"] == "entry_expired"
    assert result["net_outcome"] is None
    assert result["events"] == []
    assert result["funding_schedule_checksum"] == _funding_schedule(feed).schedule_checksum


def test_runtime_rejects_unfilled_schedule_bound_to_another_dataset() -> None:
    feed = _feed(unfilled=True)
    plan = _plan().model_copy(update={"dataset_id": feed.dataset_id, "dataset_checksum": feed.dataset_checksum})
    schedule = _funding_schedule(feed)
    raw = json.loads(schedule.artifacts.schedule_json)
    raw["dataset_checksum"] = "sha256:" + "f" * 64
    raw["dataset_id"] = "backtest-dataset-" + "f" * 64
    payload = json.dumps(raw, ensure_ascii=False, separators=(",", ":"), sort_keys=True).encode() + b"\n"
    checksum = "sha256:" + __import__("hashlib").sha256(payload).hexdigest()
    unrelated = VerifiedHistoricalFundingSchedule(HistoricalFundingScheduleArtifacts(
        schedule_json=payload,
        schedule_checksum=checksum,
    ))

    with pytest.raises(ValueError, match="schedule_binding_invalid"):
        CanonicalBacktraderRuntime().run(
            plan,
            feed,
            funding_schedule=unrelated,
            funding_bridge=trusted_bridge_for(),
        )


def test_runtime_settles_same_candle_close_with_zero_funding() -> None:
    feed = _feed(same_candle=True)
    plan = _plan().model_copy(update={"dataset_id": feed.dataset_id, "dataset_checksum": feed.dataset_checksum})
    result = json.loads(CanonicalBacktraderRuntime().run(
        plan,
        feed,
        funding_schedule=_funding_schedule(feed),
        funding_bridge=trusted_bridge_for(cashflow="0", applied_ids=()),
    ))

    assert result["status"] == "closed"
    assert [event["kind"] for event in result["events"]] == ["entry_filled", "target_filled"]
    assert result["events"][0]["happened_at"] == result["events"][1]["happened_at"]
    assert result["net_outcome"]["historical_funding_cashflow_quote"] == 0
    assert result["net_outcome"]["applied_funding_source_record_ids"] == []


def test_v2_runtime_requires_visible_queue_evidence() -> None:
    feed = _feed()

    with pytest.raises(ValueError, match="visible_fill_evidence_required"):
        CanonicalBacktraderRuntime().run(_v2_plan(feed), feed)


def test_v1_runtime_rejects_visible_queue_evidence() -> None:
    feed = _feed()
    plan = _plan().model_copy(
        update={"dataset_id": feed.dataset_id, "dataset_checksum": feed.dataset_checksum}
    )

    with pytest.raises(ValueError, match="visible_fill_evidence_forbidden"):
        CanonicalBacktraderRuntime().run(
            plan,
            feed,
            maker_fill_evidence=_queue_evidence(_v2_plan(feed)),
        )


def test_v2_runtime_preserves_authenticated_queue_non_fill() -> None:
    feed = _feed(unfilled=True)
    plan = _v2_plan(feed)
    evidence = _queue_evidence(plan, status="unfilled")

    result = json.loads(
        CanonicalBacktraderRuntime().run(plan, feed, maker_fill_evidence=evidence)
    )

    assert result["schema_version"] == "canonical-backtrader-result.v2"
    assert result["status"] == "not_executed"
    assert result["reason_code"] == "visible_queue_unfilled"
    assert result["events"] == []
    assert result["net_outcome"] is None
    assert result["maker_fill_result_hash"] == evidence.result_hash
    assert result["fills_are_certified"] is False


def test_v2_runtime_uses_public_fill_then_only_complete_later_bars() -> None:
    feed = _feed(fill_bar_target=True)
    plan = _v2_plan(feed)
    evidence = _queue_evidence(plan)

    result = json.loads(
        CanonicalBacktraderRuntime().run(plan, feed, maker_fill_evidence=evidence)
    )

    assert result["status"] == "closed"
    assert result["reason_code"] == "target_filled"
    assert result["events"][0]["source_record_id"] == "a" * 64
    assert result["events"][0]["happened_at"] == "2026-08-10T12:00:45.000000Z"
    assert result["events"][1]["source_record_id"] == "runtime-bar-1"
    assert result["net_outcome"]["schema_version"] == (
        "canonical-backtest-planned-net-outcome.v2"
    )
    assert result["net_outcome"]["maker_fill_result_hash"] == evidence.result_hash


def test_v2_runtime_counts_ambiguous_fill_bar_stop_conservatively() -> None:
    feed = _feed(fill_bar_stop=True)
    plan = _v2_plan(feed)

    result = json.loads(CanonicalBacktraderRuntime().run(
        plan, feed, maker_fill_evidence=_queue_evidence(plan)
    ))

    assert result["status"] == "closed"
    assert result["reason_code"] == "conservative_post_fill_stop_bound"
    assert result["events"][1]["kind"] == "stop_filled"
    assert result["events"][1]["source_record_id"] == "runtime-bar-0"


def test_v2_runtime_is_byte_deterministic() -> None:
    feed = _feed()
    plan = _v2_plan(feed)
    evidence = _queue_evidence(plan)

    first = CanonicalBacktraderRuntime().run(plan, feed, maker_fill_evidence=evidence)
    second = CanonicalBacktraderRuntime().run(plan, feed, maker_fill_evidence=evidence)

    assert first == second


def test_v2_runtime_keeps_historical_funding_bound_to_public_fill_time() -> None:
    feed = _feed()
    plan = _v2_plan(feed)

    result = json.loads(CanonicalBacktraderRuntime().run(
        plan,
        feed,
        maker_fill_evidence=_queue_evidence(plan),
        funding_schedule=_funding_schedule(feed),
        funding_bridge=trusted_bridge_for(
            cashflow="0",
            applied_ids=("runtime-funding-1", "runtime-funding-2"),
        ),
    ))

    assert result["net_outcome"]["schema_version"] == (
        "canonical-backtest-historical-net-outcome.v2"
    )
    assert result["net_outcome"]["applied_funding_source_record_ids"] == [
        "runtime-funding-1",
        "runtime-funding-2",
    ]


def test_v2_runtime_rejects_partial_fill_without_php_cost_authority() -> None:
    feed = _feed()
    plan = _v2_plan(feed)

    with pytest.raises(ValueError, match="partial_fill_cost_authority_missing"):
        CanonicalBacktraderRuntime().run(
            plan,
            feed,
            maker_fill_evidence=_queue_evidence(plan, status="partially_filled"),
        )


def test_v2_runtime_rejects_staged_fill_without_php_cost_authority() -> None:
    feed = _feed()
    plan = _v2_plan(feed)

    with pytest.raises(ValueError, match="partial_fill_cost_authority_missing"):
        CanonicalBacktraderRuntime().run(
            plan,
            feed,
            maker_fill_evidence=_queue_evidence(plan, staged=True),
        )


def test_v2_runtime_revalidates_visible_queue_evidence() -> None:
    feed = _feed()
    plan = _v2_plan(feed)
    forged = _queue_evidence(plan).model_copy(
        update={"plan_hash": "sha256:" + "f" * 64}
    )

    with pytest.raises(ValueError, match="visible_fill_evidence_invalid"):
        CanonicalBacktraderRuntime().run(plan, feed, maker_fill_evidence=forged)


def test_v2_runtime_rejects_valid_evidence_bound_to_another_network() -> None:
    feed = _feed()
    plan = _v2_plan(feed)
    raw = _queue_evidence(plan).model_dump(mode="json")
    raw["source_network"] = "testnet"
    raw["result_hash"] = _queue_hash(
        {key: value for key, value in raw.items() if key != "result_hash"}
    )
    unrelated = VisibleQueueDepletionResult.model_validate(raw)

    with pytest.raises(ValueError, match="visible_fill_evidence_invalid"):
        CanonicalBacktraderRuntime().run(
            plan, feed, maker_fill_evidence=unrelated
        )


def test_execution_boundary_revalidates_and_rejects_partial_fill_evidence() -> None:
    feed = _feed()
    plan = _v2_plan(feed)
    forged = _queue_evidence(plan).model_copy(
        update={"result_hash": "sha256:" + "f" * 64}
    )

    with pytest.raises(BacktestExecutionError, match="visible_fill_evidence_invalid"):
        execute_plan_from_visible_fill(plan, feed.bars, forged)
    with pytest.raises(BacktestExecutionError, match="partial_fill_cost_authority_missing"):
        execute_plan_from_visible_fill(
            plan, feed.bars, _queue_evidence(plan, status="partially_filled")
        )


def test_net_outcome_enforces_visible_fill_version_boundary_directly() -> None:
    feed = _feed()
    v2 = _v2_plan(feed)
    execution = execute_plan(v2, feed.bars)

    with pytest.raises(BacktestNetOutcomeError, match="visible_fill_evidence_required"):
        project_plan_bound_net_outcome(v2, execution, feed)

    v1 = _plan().model_copy(
        update={"dataset_id": feed.dataset_id, "dataset_checksum": feed.dataset_checksum}
    )
    with pytest.raises(BacktestNetOutcomeError, match="visible_fill_evidence_forbidden"):
        project_plan_bound_net_outcome(
            v1,
            execute_plan(v1, feed.bars),
            feed,
            maker_fill_evidence=_queue_evidence(v2),
        )
    with pytest.raises(BacktestNetOutcomeError, match="visible_fill_evidence_invalid"):
        project_plan_bound_net_outcome(
            v2,
            execution,
            feed,
            maker_fill_evidence=_queue_evidence(v2, staged=True),
        )
