from __future__ import annotations

from copy import deepcopy
from copy import copy
from datetime import datetime, timedelta, timezone
from decimal import Decimal
import json
from pathlib import Path

import pytest
from pydantic import ValidationError

from app.backtesting.backtrader_contracts import CanonicalBacktestOrderPlan, _php_plan_hash
from app.backtesting.contracts import MarketType
from app.backtesting.dataset import (
    CandleRecord,
    DatasetBuilder,
    DatasetSerializer,
    DatasetSourceIdentity,
    Timeframe,
)
from app.backtesting.public_book_tape import (
    PublicBookRecord,
    VerifiedPublicBookTape,
    serialize_public_book_tape,
)
from app.backtesting.public_execution_tape import (
    PublicTradeRecord,
    VerifiedPublicExecutionTape,
    serialize_public_execution_tape,
)
from app.backtesting.public_quantity_conversion_tape import (
    BookQuantityConversionRecord,
    InstrumentMetadataRecord,
    TradeQuantityConversionRecord,
    VerifiedPublicQuantityConversionTape,
    serialize_public_quantity_conversion_tape,
)
from app.backtesting.visible_queue_depletion import (
    VisibleQueueDepletionError,
    VisibleQueueDepletionResult,
    VisibleQueueDepletionTraceItem,
    _decimal_string,
    _hash,
    _time_string,
    model_visible_queue_depletion,
)


UTC = timezone.utc
PLAN_FIXTURE = Path(__file__).parent / "fixtures/backtesting/php-canonical-order-plan.json"
SOURCE_CHECKSUM = "sha256:" + "a" * 64


def _at(second: int, microsecond: int = 0) -> datetime:
    return datetime(2026, 8, 13, 10, 0, second, microsecond, tzinfo=UTC)


def _dataset():
    source = DatasetSourceIdentity(
        source="paper_market_dataset",
        source_schema_version="paper-market-dataset.v2",
        source_build_version="paper-recorder.v2",
        source_checksum=SOURCE_CHECKSUM,
        source_network="mainnet",
        market_data_venue="okx",
        market_type=MarketType.PERPETUAL,
    )
    candle = CandleRecord(
        source_record_id="c" * 64,
        source_network="mainnet",
        market_data_venue="okx",
        market_type=MarketType.PERPETUAL,
        symbol="BTCUSDT",
        timeframe=Timeframe.ONE_MINUTE,
        open_at=_at(0),
        close_at=_at(0) + timedelta(minutes=1),
        available_at=_at(0) + timedelta(minutes=1),
        open="100",
        high="102",
        low="98",
        close="100",
        volume="100",
        complete=True,
    )
    return DatasetSerializer.verify(
        DatasetSerializer.serialize(DatasetBuilder(source).build((candle,)))
    )


def _book(
    *, position: int = 1, available_second: int = 5,
    bid_quantity: str = "5", ask_quantity: str = "6",
):
    return PublicBookRecord(
        schema_version="backtest-public-book.v1",
        source_record_id=f"{position:064x}",
        source_checksum=SOURCE_CHECKSUM,
        source_network="mainnet",
        market_data_venue="okx",
        market_type="perpetual",
        symbol="BTCUSDT",
        happened_at=_at(available_second),
        available_at=_at(available_second),
        bid_price="100",
        bid_quantity=bid_quantity,
        ask_price="101",
        ask_quantity=ask_quantity,
        quantity_unit="contracts",
        bid_order_count="2",
        ask_order_count="3",
        origin="ws_books",
    )


def _trade(
    position: int,
    *,
    second: int,
    side: str,
    price: str,
    quantity: str,
    happened_second: int | None = None,
):
    return PublicTradeRecord(
        schema_version="backtest-public-trade.v1",
        source_record_id=f"{position:064x}",
        source_checksum=SOURCE_CHECKSUM,
        source_network="mainnet",
        market_data_venue="okx",
        market_type="perpetual",
        symbol="BTCUSDT",
        venue_trade_id=str(position),
        happened_at=_at(second if happened_second is None else happened_second),
        available_at=_at(second),
        aggressor_side=side,
        price=price,
        quantity=quantity,
        quantity_unit="contracts",
    )


def _canonical_decimal(value: Decimal) -> str:
    rendered = format(value, "f").rstrip("0").rstrip(".")
    return rendered or "0"


def _plan(
    dataset, *, side: str = "long", entry_role: str = "maker",
    entry_price: float | None = None, maximum_input_age_seconds: int = 10,
    contract_size: float = 0.01, cancel_after_second: int | None = None,
):
    payload = deepcopy(json.loads(PLAN_FIXTURE.read_text(encoding="utf-8")))
    payload.update(
        schema_version="canonical-backtest-order-plan.v2",
        dataset_id=dataset.dataset_id,
        dataset_checksum=dataset.dataset_checksum,
        timeframe="1m",
    )
    plan = payload["plan"]
    is_long = side == "long"
    plan.update(
        side=side,
        setupId=(
            "day_trading.trend_continuation.long"
            if is_long else "day_trading.trend_continuation.short"
        ),
        quantity=4.0,
        contractSize=contract_size,
        entryPrice=entry_price if entry_price is not None else (100.0 if is_long else 101.0),
        stopPrice=98.0 if is_long else 103.0,
        zoneLowerPrice=99.0,
        zoneUpperPrice=102.0,
        targets=[
            {
                **plan["targets"][0],
                "price": 102.0 if is_long else 99.0,
            }
        ],
        entryLiquidityRole=entry_role,
        maximumInputAgeSeconds=maximum_input_age_seconds,
        inputObservedAt="2026-08-13T10:00:05.000000+00:00",
        observedAt="2026-08-13T10:00:05.000000+00:00",
        costObservedAt="2026-08-13T10:00:05.000000+00:00",
        zoneComputedAt="2026-08-13T10:00:10.000000+00:00",
        createdAt="2026-08-13T10:00:10.000000+00:00",
        expiresAt="2026-08-13T10:00:50.000000+00:00",
        cancelAfterAt=(
            None if cancel_after_second is None
            else f"2026-08-13T10:00:{cancel_after_second:02d}.000000+00:00"
        ),
        holdingExpiresAt=None,
    )
    plan = {
        key: value
        for original_key, original_value in plan.items()
        for key, value in (
            ((original_key, original_value), ("marketFallback", False))
            if original_key == "orderType"
            else ((original_key, original_value),)
        )
    }
    payload["plan"] = plan
    plan["planHash"] = _php_plan_hash({key: value for key, value in plan.items() if key != "planHash"})
    return CanonicalBacktestOrderPlan.model_validate(payload)


def _inputs(trades, *, book=None):
    dataset = _dataset()
    raw_books = (_book(),) if book is None else (book if isinstance(book, tuple) else (book,))
    execution = VerifiedPublicExecutionTape(
        serialize_public_execution_tape(dataset=dataset, records=tuple(trades)),
        dataset=dataset,
    )
    books = VerifiedPublicBookTape(
        serialize_public_book_tape(dataset=dataset, records=raw_books),
        dataset=dataset,
    )
    metadata = InstrumentMetadataRecord(
        schema_version="backtest-instrument-metadata.v1",
        source_record_id=f"{255:064x}",
        source_checksum=SOURCE_CHECKSUM,
        source_network="mainnet",
        market_data_venue="okx",
        market_type="perpetual",
        symbol="BTCUSDT",
        source_event_position=0,
        available_at=_at(0) - timedelta(seconds=1),
        source_epoch=1,
        quantity_unit="contracts",
        contract_value="0.01",
        contract_multiplier="1",
        contract_value_unit="BTC",
    )
    conversions = [
        BookQuantityConversionRecord(
            schema_version="backtest-book-quantity-conversion.v1",
            source_channel="top_of_book",
            source_record_id=raw_book.source_record_id,
            source_event_position=offset,
            source_checksum=SOURCE_CHECKSUM,
            source_network="mainnet",
            market_data_venue="okx",
            market_type="perpetual",
            symbol="BTCUSDT",
            happened_at=raw_book.happened_at,
            available_at=raw_book.available_at,
            metadata_record_id=metadata.source_record_id,
            metadata_event_position=0,
            metadata_available_at=metadata.available_at,
            source_quantity_unit="contracts",
            base_quantity_unit="base_asset",
            bid_source_quantity=raw_book.bid_quantity,
            bid_base_quantity=_canonical_decimal(Decimal(raw_book.bid_quantity) * Decimal("0.01")),
            ask_source_quantity=raw_book.ask_quantity,
            ask_base_quantity=_canonical_decimal(Decimal(raw_book.ask_quantity) * Decimal("0.01")),
        )
        for offset, raw_book in enumerate(raw_books, start=1)
    ]
    for offset, trade in enumerate(trades, start=len(raw_books) + 1):
        conversions.append(
            TradeQuantityConversionRecord(
                schema_version="backtest-trade-quantity-conversion.v1",
                source_channel="public_trade",
                source_record_id=trade.source_record_id,
                source_event_position=offset,
                source_checksum=SOURCE_CHECKSUM,
                source_network="mainnet",
                market_data_venue="okx",
                market_type="perpetual",
                symbol="BTCUSDT",
                happened_at=trade.happened_at,
                available_at=trade.available_at,
                metadata_record_id=metadata.source_record_id,
                metadata_event_position=0,
                metadata_available_at=metadata.available_at,
                source_quantity_unit="contracts",
                base_quantity_unit="base_asset",
                source_quantity=trade.quantity,
                base_quantity=_canonical_decimal(Decimal(trade.quantity) * Decimal("0.01")),
            )
        )
    quantity_tape = VerifiedPublicQuantityConversionTape(
        serialize_public_quantity_conversion_tape(
            dataset=dataset,
            public_execution_tape=execution,
            public_book_tape=books,
            metadata=(metadata,),
            conversions=tuple(conversions),
        ),
        dataset=dataset,
        public_execution_tape=execution,
        public_book_tape=books,
    )
    return dataset, execution, books, quantity_tape


def test_long_queue_depletion_produces_explainable_partial_then_full_fill() -> None:
    trades = (
        _trade(2, second=20, side="sell", price="100", quantity="3"),
        _trade(3, second=30, side="sell", price="100", quantity="4"),
        _trade(4, second=40, side="sell", price="99", quantity="1"),
    )
    dataset, execution, books, conversions = _inputs(trades)

    first = model_visible_queue_depletion(
        plan=_plan(dataset), dataset=dataset, public_execution_tape=execution,
        public_book_tape=books, quantity_conversion_tape=conversions,
    )
    second = model_visible_queue_depletion(
        plan=_plan(dataset), dataset=dataset, public_execution_tape=execution,
        public_book_tape=books, quantity_conversion_tape=conversions,
    )

    assert first == second
    assert first.status == "filled"
    assert first.policy_version == "visible-queue-depletion.v1"
    assert first.initial_visible_queue_base == "0.05"
    assert first.order_quantity_base == "0.04"
    assert [item.fill_quantity_base for item in first.trace] == ["0", "0.02", "0.02"]
    assert [item.queue_after_base for item in first.trace] == ["0.02", "0", "0"]
    assert first.filled_quantity_base == "0.04"
    assert first.remaining_quantity_base == "0"
    assert first.fills_are_certified is False
    assert first.queue_evidence == "visible_l1_plus_public_trades"
    assert first.result_is_live_proof is False
    assert first.result_hash.startswith("sha256:")
    with pytest.raises(ValidationError):
        first.status = "unfilled"  # type: ignore[misc]


def test_short_queue_depletion_is_symmetric_and_can_remain_partial() -> None:
    trades = (
        _trade(2, second=20, side="sell", price="101", quantity="100"),
        _trade(3, second=30, side="buy", price="101", quantity="7"),
    )
    dataset, execution, books, conversions = _inputs(trades)

    result = model_visible_queue_depletion(
        plan=_plan(dataset, side="short"), dataset=dataset,
        public_execution_tape=execution, public_book_tape=books,
        quantity_conversion_tape=conversions,
    )

    assert result.status == "partially_filled"
    assert len(result.trace) == 1
    assert result.trace[0].queue_before_base == "0.06"
    assert result.trace[0].fill_quantity_base == "0.01"
    assert result.remaining_quantity_base == "0.03"


def test_pre_live_late_delivery_post_deadline_and_wrong_side_do_not_fill() -> None:
    trades = (
        _trade(2, second=20, happened_second=9, side="sell", price="99", quantity="100"),
        _trade(3, second=25, side="sell", price="101", quantity="100"),
        _trade(4, second=30, side="buy", price="99", quantity="100"),
        _trade(5, second=51, side="sell", price="99", quantity="100"),
    )
    dataset, execution, books, conversions = _inputs(trades)

    result = model_visible_queue_depletion(
        plan=_plan(dataset), dataset=dataset, public_execution_tape=execution,
        public_book_tape=books, quantity_conversion_tape=conversions,
    )

    assert result.status == "unfilled"
    assert result.trace == ()


@pytest.mark.parametrize("entry_role", ["taker"])
def test_unsupported_liquidity_role_fails_closed(entry_role: str) -> None:
    dataset, execution, books, conversions = _inputs(
        (_trade(2, second=20, side="sell", price="99", quantity="1"),)
    )
    with pytest.raises(VisibleQueueDepletionError, match="unsupported_liquidity_role"):
        model_visible_queue_depletion(
            plan=_plan(dataset, entry_role=entry_role), dataset=dataset,
            public_execution_tape=execution, public_book_tape=books,
            quantity_conversion_tape=conversions,
        )


def test_v1_plan_without_explicit_fallback_policy_fails_closed() -> None:
    dataset, execution, books, conversions = _inputs(
        (_trade(2, second=20, side="sell", price="99", quantity="1"),)
    )
    v2 = _plan(dataset).model_dump(mode="json", by_alias=True)
    v2["schema_version"] = "canonical-backtest-order-plan.v1"
    v2["plan"].pop("marketFallback")
    v2["plan"]["planHash"] = _php_plan_hash(
        {key: value for key, value in v2["plan"].items() if key != "planHash"}
    )
    legacy = CanonicalBacktestOrderPlan.model_validate(v2)

    with pytest.raises(VisibleQueueDepletionError, match="fallback_policy_missing"):
        model_visible_queue_depletion(
            plan=legacy, dataset=dataset, public_execution_tape=execution,
            public_book_tape=books, quantity_conversion_tape=conversions,
        )


@pytest.mark.parametrize(
    ("book", "entry_price", "reason"),
    [
        (_book(available_second=0), None, "initial_book_stale"),
        (_book(), 99.0, "entry_not_at_visible_top"),
    ],
)
def test_stale_book_and_non_top_entry_fail_closed(book, entry_price, reason: str) -> None:
    dataset, execution, books, conversions = _inputs(
        (_trade(2, second=20, side="sell", price="99", quantity="1"),), book=book
    )
    with pytest.raises(VisibleQueueDepletionError, match=reason):
        model_visible_queue_depletion(
            plan=_plan(
                dataset, entry_price=entry_price,
                maximum_input_age_seconds=9 if reason == "initial_book_stale" else 10,
            ), dataset=dataset,
            public_execution_tape=execution, public_book_tape=books,
            quantity_conversion_tape=conversions,
        )


def test_plan_dataset_mismatch_fails_closed() -> None:
    dataset, execution, books, conversions = _inputs(
        (_trade(2, second=20, side="sell", price="99", quantity="1"),)
    )
    plan = _plan(dataset).model_copy(update={"dataset_id": "backtest-dataset-" + "f" * 64})
    with pytest.raises(VisibleQueueDepletionError, match="lineage_invalid"):
        model_visible_queue_depletion(
            plan=plan, dataset=dataset, public_execution_tape=execution,
            public_book_tape=books, quantity_conversion_tape=conversions,
        )


def test_latest_available_pre_live_book_is_selected_in_tape_order() -> None:
    books_input = (
        _book(position=1, available_second=5, bid_quantity="9"),
        _book(position=9, available_second=8, bid_quantity="5"),
    )
    dataset, execution, books, conversions = _inputs(
        (_trade(20, second=20, side="sell", price="100", quantity="5"),),
        book=books_input,
    )

    result = model_visible_queue_depletion(
        plan=_plan(dataset), dataset=dataset, public_execution_tape=execution,
        public_book_tape=books, quantity_conversion_tape=conversions,
    )

    assert result.initial_book_source_record_id == books_input[1].source_record_id
    assert result.initial_visible_queue_base == "0.05"


def test_cancel_after_is_the_effective_deadline() -> None:
    dataset, execution, books, conversions = _inputs(
        (_trade(2, second=40, side="sell", price="99", quantity="100"),)
    )

    result = model_visible_queue_depletion(
        plan=_plan(dataset, cancel_after_second=35), dataset=dataset,
        public_execution_tape=execution, public_book_tape=books,
        quantity_conversion_tape=conversions,
    )

    assert result.effective_deadline_at == "2026-08-13T10:00:35.000000Z"
    assert result.status == "unfilled"


def test_plan_contract_size_must_match_active_instrument_metadata() -> None:
    dataset, execution, books, conversions = _inputs(
        (_trade(2, second=20, side="sell", price="99", quantity="1"),)
    )
    with pytest.raises(VisibleQueueDepletionError, match="contract_size_mismatch"):
        model_visible_queue_depletion(
            plan=_plan(dataset, contract_size=0.02), dataset=dataset,
            public_execution_tape=execution, public_book_tape=books,
            quantity_conversion_tape=conversions,
        )


def test_rehashed_internally_inconsistent_trace_is_rejected() -> None:
    dataset, execution, books, conversions = _inputs(
        (
            _trade(2, second=20, side="sell", price="100", quantity="3"),
            _trade(3, second=30, side="sell", price="100", quantity="4"),
        )
    )
    result = model_visible_queue_depletion(
        plan=_plan(dataset), dataset=dataset, public_execution_tape=execution,
        public_book_tape=books, quantity_conversion_tape=conversions,
    )
    payload = result.model_dump()
    payload["trace"][1]["cumulative_fill_quantity_base"] = "0.03"
    payload["trace_hash"] = _hash(payload["trace"])
    payload["result_hash"] = _hash({key: value for key, value in payload.items() if key != "result_hash"})

    with pytest.raises(ValidationError, match="trace_invalid"):
        VisibleQueueDepletionResult.model_validate(payload)


def test_contract_helpers_and_hashes_fail_closed() -> None:
    with pytest.raises(VisibleQueueDepletionError, match="decimal_invalid"):
        _decimal_string(Decimal("-1"))
    with pytest.raises(VisibleQueueDepletionError, match="time_invalid"):
        _time_string(datetime(2026, 8, 13, 10, 0, 0))
    with pytest.raises(ValidationError, match="decimal_invalid"):
        VisibleQueueDepletionTraceItem.model_validate(
            {
                "source_record_id": "1" * 64,
                "source_event_position": 1,
                "happened_at": "2026-08-13T10:00:20.000000Z",
                "available_at": "2026-08-13T10:00:20.000000Z",
                "price": "not-a-decimal",
                "trade_base_quantity": "1",
                "queue_before_base": "1",
                "queue_after_base": "0",
                "fill_quantity_base": "0",
                "cumulative_fill_quantity_base": "0",
                "remaining_order_quantity_base": "1",
                "evidence_kind": "at_price_depletion",
            }
        )

    dataset, execution, books, conversions = _inputs(
        (_trade(2, second=20, side="sell", price="100", quantity="3"),)
    )
    result = model_visible_queue_depletion(
        plan=_plan(dataset), dataset=dataset, public_execution_tape=execution,
        public_book_tape=books, quantity_conversion_tape=conversions,
    )
    for field in ("trace_hash", "result_hash"):
        payload = result.model_dump()
        payload[field] = "sha256:" + "f" * 64
        with pytest.raises(ValidationError, match=f"{field}_mismatch"):
            VisibleQueueDepletionResult.model_validate(payload)

    for field, value, reason in (
        ("entry_price", "not-a-decimal", "decimal_invalid"),
        ("trace", "not-a-sequence", "trace_invalid"),
    ):
        payload = result.model_dump()
        payload[field] = value
        with pytest.raises(ValidationError, match=reason):
            VisibleQueueDepletionResult.model_validate(payload)

    payload = result.model_dump()
    payload["initial_visible_queue_base"] = "0"
    payload["result_hash"] = _hash(
        {key: value for key, value in payload.items() if key != "result_hash"}
    )
    with pytest.raises(ValidationError, match="result_invalid"):
        VisibleQueueDepletionResult.model_validate(payload)

    payload = result.model_dump()
    payload["trace"][0]["queue_before_base"] = "0.04"
    payload["trace_hash"] = _hash(payload["trace"])
    payload["result_hash"] = _hash(
        {key: value for key, value in payload.items() if key != "result_hash"}
    )
    with pytest.raises(ValidationError, match="trace_invalid"):
        VisibleQueueDepletionResult.model_validate(payload)

    unfilled_payload = result.model_dump()
    unfilled_payload.update(
        trace=[], filled_quantity_base="0.01", remaining_quantity_base="0.03",
        status="partially_filled",
    )
    unfilled_payload["trace_hash"] = _hash([])
    unfilled_payload["result_hash"] = _hash(
        {key: value for key, value in unfilled_payload.items() if key != "result_hash"}
    )
    with pytest.raises(ValidationError, match="trace_invalid"):
        VisibleQueueDepletionResult.model_validate(unfilled_payload)

    status_payload = result.model_dump()
    status_payload["status"] = "filled"
    status_payload["result_hash"] = _hash(
        {key: value for key, value in status_payload.items() if key != "result_hash"}
    )
    with pytest.raises(ValidationError, match="result_invalid"):
        VisibleQueueDepletionResult.model_validate(status_payload)

    time_payload = result.model_dump()
    time_payload["order_live_at"] = "not-a-time"
    time_payload["result_hash"] = _hash(
        {key: value for key, value in time_payload.items() if key != "result_hash"}
    )
    with pytest.raises(ValidationError, match="time_invalid"):
        VisibleQueueDepletionResult.model_validate(time_payload)

    identity_payload = result.model_dump()
    identity_payload["dataset_id"] = "not-a-dataset"
    identity_payload["result_hash"] = _hash(
        {key: value for key, value in identity_payload.items() if key != "result_hash"}
    )
    with pytest.raises(ValidationError):
        VisibleQueueDepletionResult.model_validate(identity_payload)


def test_invalid_input_deadline_and_missing_initial_book_fail_closed() -> None:
    future_book = _book(available_second=15)
    dataset, execution, books, conversions = _inputs(
        (_trade(2, second=20, side="sell", price="100", quantity="1"),),
        book=future_book,
    )
    with pytest.raises(VisibleQueueDepletionError, match="input_invalid"):
        model_visible_queue_depletion(
            plan=None,  # type: ignore[arg-type]
            dataset=dataset, public_execution_tape=execution,
            public_book_tape=books, quantity_conversion_tape=conversions,
        )
    with pytest.raises(VisibleQueueDepletionError, match="initial_book_missing"):
        model_visible_queue_depletion(
            plan=_plan(dataset), dataset=dataset, public_execution_tape=execution,
            public_book_tape=books, quantity_conversion_tape=conversions,
        )

    normal_dataset, normal_execution, normal_books, normal_conversions = _inputs(
        (_trade(2, second=20, side="sell", price="100", quantity="1"),)
    )
    normal_plan = _plan(normal_dataset)
    invalid_plan = normal_plan.model_copy(
        update={
            "plan": normal_plan.plan.model_copy(update={"maximum_input_age_seconds": -1})
        }
    )
    with pytest.raises(VisibleQueueDepletionError, match="deadline_invalid"):
        model_visible_queue_depletion(
            plan=invalid_plan, dataset=normal_dataset,
            public_execution_tape=normal_execution, public_book_tape=normal_books,
            quantity_conversion_tape=normal_conversions,
        )


def test_missing_verified_conversion_references_fail_closed_defensively() -> None:
    trades = (_trade(2, second=20, side="sell", price="100", quantity="10"),)
    dataset, execution, books, conversions = _inputs(trades)

    missing_book = copy(conversions)
    object.__setattr__(
        missing_book,
        "conversions",
        tuple(item for item in conversions.conversions if item.source_channel != "top_of_book"),
    )
    with pytest.raises(VisibleQueueDepletionError, match="book_conversion_missing"):
        model_visible_queue_depletion(
            plan=_plan(dataset), dataset=dataset, public_execution_tape=execution,
            public_book_tape=books, quantity_conversion_tape=missing_book,
        )

    missing_trade = copy(conversions)
    object.__setattr__(
        missing_trade,
        "conversions",
        tuple(item for item in conversions.conversions if item.source_channel != "public_trade"),
    )
    with pytest.raises(VisibleQueueDepletionError, match="trade_conversion_missing"):
        model_visible_queue_depletion(
            plan=_plan(dataset), dataset=dataset, public_execution_tape=execution,
            public_book_tape=books, quantity_conversion_tape=missing_trade,
        )


def test_short_level_through_fills_and_stops_consuming_later_trades() -> None:
    trades = (
        _trade(2, second=20, side="buy", price="102", quantity="1"),
        _trade(3, second=30, side="buy", price="102", quantity="1"),
    )
    dataset, execution, books, conversions = _inputs(trades)

    result = model_visible_queue_depletion(
        plan=_plan(dataset, side="short"), dataset=dataset,
        public_execution_tape=execution, public_book_tape=books,
        quantity_conversion_tape=conversions,
    )

    assert result.status == "filled"
    assert len(result.trace) == 1
    assert result.trace[0].evidence_kind == "level_through"
