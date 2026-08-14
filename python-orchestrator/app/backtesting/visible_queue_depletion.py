"""Deterministic non-certified maker fills from authenticated public tapes."""

from __future__ import annotations

import hashlib
import json
import re
from datetime import datetime, timezone
from decimal import Decimal
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator

from app.backtesting.backtrader_contracts import CanonicalBacktestOrderPlan
from app.backtesting.contracts import DatasetDescriptor
from app.backtesting.public_book_tape import VerifiedPublicBookTape
from app.backtesting.public_execution_tape import VerifiedPublicExecutionTape
from app.backtesting.public_quantity_conversion_tape import (
    BookQuantityConversionRecord,
    TradeQuantityConversionRecord,
    VerifiedPublicQuantityConversionTape,
)


_HASH = r"^sha256:[0-9a-f]{64}$"
_DECIMAL = re.compile(r"^(?:0|[1-9][0-9]*)(?:\.[0-9]*[1-9])?$")
_TIME = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$")


class VisibleQueueDepletionError(ValueError):
    """Stable fail-closed rejection from the maker fill evidence boundary."""


def _decimal_string(value: Decimal) -> str:
    if not value.is_finite() or value < 0:
        raise VisibleQueueDepletionError("visible_queue_depletion_decimal_invalid")
    rendered = format(value, "f")
    if "." in rendered:
        rendered = rendered.rstrip("0").rstrip(".")
    return rendered or "0"


def _time_string(value: datetime) -> str:
    if value.tzinfo is None or value.utcoffset() != timezone.utc.utcoffset(value):
        raise VisibleQueueDepletionError("visible_queue_depletion_time_invalid")
    return value.astimezone(timezone.utc).isoformat(timespec="microseconds").replace(
        "+00:00", "Z"
    )


def _parse_time_string(value: Any) -> datetime:
    if type(value) is not str or _TIME.fullmatch(value) is None:
        raise ValueError("visible_queue_depletion_time_invalid")
    return datetime.fromisoformat(value.removesuffix("Z") + "+00:00")


def _json_value(value: Any) -> Any:
    if isinstance(value, BaseModel):
        return _json_value(value.model_dump())
    if isinstance(value, dict):
        return {str(key): _json_value(item) for key, item in value.items()}
    if isinstance(value, (list, tuple)):
        return [_json_value(item) for item in value]
    return value


def _hash(value: Any) -> str:
    encoded = json.dumps(
        _json_value(value), ensure_ascii=False, separators=(",", ":"), sort_keys=True
    ).encode()
    return "sha256:" + hashlib.sha256(encoded).hexdigest()


class VisibleQueueDepletionTraceItem(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)

    source_record_id: str = Field(pattern=r"^[0-9a-f]{64}$")
    source_event_position: int = Field(ge=0)
    happened_at: str
    available_at: str
    price: str
    trade_base_quantity: str
    queue_before_base: str
    queue_after_base: str
    fill_quantity_base: str
    cumulative_fill_quantity_base: str
    remaining_order_quantity_base: str
    evidence_kind: Literal["at_price_depletion", "level_through"]

    @field_validator(
        "price", "trade_base_quantity", "queue_before_base", "queue_after_base",
        "fill_quantity_base", "cumulative_fill_quantity_base",
        "remaining_order_quantity_base", mode="before",
    )
    @classmethod
    def _decimals(cls, value: Any) -> str:
        if type(value) is not str or _DECIMAL.fullmatch(value) is None:
            raise ValueError("visible_queue_depletion_decimal_invalid")
        return value

    @field_validator("happened_at", "available_at", mode="before")
    @classmethod
    def _times(cls, value: Any) -> str:
        _parse_time_string(value)
        return value

    @model_validator(mode="after")
    def _time_order(self) -> "VisibleQueueDepletionTraceItem":
        if _parse_time_string(self.available_at) < _parse_time_string(self.happened_at):
            raise ValueError("visible_queue_depletion_time_invalid")
        return self


class VisibleQueueDepletionResult(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)

    schema_version: Literal["visible-queue-depletion-result.v1"]
    policy_version: Literal["visible-queue-depletion.v1"]
    dataset_id: str = Field(pattern=r"^backtest-dataset-[0-9a-f]{64}$")
    dataset_checksum: str = Field(pattern=_HASH)
    plan_hash: str = Field(pattern=_HASH)
    config_hash: str = Field(pattern=_HASH)
    public_book_tape_checksum: str = Field(pattern=_HASH)
    public_execution_tape_checksum: str = Field(pattern=_HASH)
    quantity_conversion_tape_checksum: str = Field(pattern=_HASH)
    source_network: Literal["mainnet", "testnet"]
    market_data_venue: Literal["okx", "hyperliquid"]
    market_type: Literal["perpetual"]
    symbol: Literal["BTCUSDT", "ETHUSDT"]
    side: Literal["long", "short"]
    entry_price: str
    order_live_at: str
    effective_deadline_at: str
    initial_book_source_record_id: str = Field(pattern=r"^[0-9a-f]{64}$")
    initial_book_source_event_position: int = Field(ge=0)
    initial_visible_queue_base: str
    order_quantity_base: str
    trace: tuple[VisibleQueueDepletionTraceItem, ...]
    filled_quantity_base: str
    remaining_quantity_base: str
    status: Literal["unfilled", "partially_filled", "filled"]
    fills_are_certified: Literal[False]
    queue_evidence: Literal["visible_l1_plus_public_trades"]
    latency_assumption: Literal["available_at_ordering_no_private_ack"]
    result_is_live_proof: Literal[False]
    trace_hash: str = Field(pattern=_HASH)
    result_hash: str = Field(pattern=_HASH)

    @field_validator(
        "entry_price", "initial_visible_queue_base", "order_quantity_base",
        "filled_quantity_base", "remaining_quantity_base", mode="before",
    )
    @classmethod
    def _decimals(cls, value: Any) -> str:
        if type(value) is not str or _DECIMAL.fullmatch(value) is None:
            raise ValueError("visible_queue_depletion_decimal_invalid")
        return value

    @field_validator("trace", mode="before")
    @classmethod
    def _trace_tuple(cls, value: Any) -> Any:
        if not isinstance(value, (list, tuple)):
            raise ValueError("visible_queue_depletion_trace_invalid")
        return tuple(value)

    @field_validator("order_live_at", "effective_deadline_at", mode="before")
    @classmethod
    def _times(cls, value: Any) -> str:
        _parse_time_string(value)
        return value

    @model_validator(mode="after")
    def _verify_hashes(self) -> "VisibleQueueDepletionResult":
        if self.trace_hash != _hash(self.trace):
            raise ValueError("visible_queue_depletion_trace_hash_mismatch")
        unsigned = self.model_dump(exclude={"result_hash"})
        if self.result_hash != _hash(unsigned):
            raise ValueError("visible_queue_depletion_result_hash_mismatch")
        filled = Decimal(self.filled_quantity_base)
        remaining = Decimal(self.remaining_quantity_base)
        total = Decimal(self.order_quantity_base)
        entry = Decimal(self.entry_price)
        queue = Decimal(self.initial_visible_queue_base)
        cumulative = Decimal(0)
        trace_remaining = total
        if (
            entry <= 0
            or queue <= 0
            or total <= 0
            or _parse_time_string(self.effective_deadline_at)
            <= _parse_time_string(self.order_live_at)
        ):
            raise ValueError("visible_queue_depletion_result_invalid")
        for item in self.trace:
            trade_quantity = Decimal(item.trade_base_quantity)
            item_price = Decimal(item.price)
            item_queue_before = Decimal(item.queue_before_base)
            item_queue_after = Decimal(item.queue_after_base)
            item_fill = Decimal(item.fill_quantity_base)
            if item_queue_before != queue or trade_quantity <= 0:
                raise ValueError("visible_queue_depletion_trace_invalid")
            if item.evidence_kind == "level_through":
                is_through = (
                    item_price < entry if self.side == "long" else item_price > entry
                )
                expected_queue = Decimal(0)
                expected_fill = trace_remaining
            else:
                is_through = item_price == entry
                consumed = min(queue, trade_quantity)
                expected_queue = queue - consumed
                expected_fill = min(trace_remaining, trade_quantity - consumed)
            cumulative += expected_fill
            trace_remaining -= expected_fill
            if (
                not is_through
                or item_queue_after != expected_queue
                or item_fill != expected_fill
                or Decimal(item.cumulative_fill_quantity_base) != cumulative
                or Decimal(item.remaining_order_quantity_base) != trace_remaining
            ):
                raise ValueError("visible_queue_depletion_trace_invalid")
            queue = expected_queue
        if cumulative != filled or trace_remaining != remaining:
            raise ValueError("visible_queue_depletion_trace_invalid")
        expected_status = (
            "unfilled" if filled == 0 else "filled" if remaining == 0 else "partially_filled"
        )
        if filled + remaining != total or self.status != expected_status:
            raise ValueError("visible_queue_depletion_result_invalid")
        return self


def requires_partial_fill_authority(result: VisibleQueueDepletionResult) -> bool:
    """True when any executed quantity was accumulated rather than atomic."""
    total = Decimal(result.order_quantity_base)
    positive_fills = tuple(
        Decimal(item.fill_quantity_base)
        for item in result.trace
        if Decimal(item.fill_quantity_base) > 0
    )
    return result.status == "partially_filled" or (
        result.status == "filled"
        and (len(positive_fills) != 1 or positive_fills[0] != total)
    )


def model_visible_queue_depletion(
    *,
    plan: CanonicalBacktestOrderPlan,
    dataset: DatasetDescriptor,
    public_execution_tape: VerifiedPublicExecutionTape,
    public_book_tape: VerifiedPublicBookTape,
    quantity_conversion_tape: VerifiedPublicQuantityConversionTape,
) -> VisibleQueueDepletionResult:
    if (
        not isinstance(plan, CanonicalBacktestOrderPlan)
        or not isinstance(dataset, DatasetDescriptor)
        or not isinstance(public_execution_tape, VerifiedPublicExecutionTape)
        or not isinstance(public_book_tape, VerifiedPublicBookTape)
        or not isinstance(quantity_conversion_tape, VerifiedPublicQuantityConversionTape)
    ):
        raise VisibleQueueDepletionError("visible_queue_depletion_input_invalid")
    if plan.plan.entry_liquidity_role != "maker":
        raise VisibleQueueDepletionError("visible_queue_depletion_unsupported_liquidity_role")
    if (
        plan.schema_version != "canonical-backtest-order-plan.v2"
        or plan.plan.market_fallback is not False
    ):
        raise VisibleQueueDepletionError(
            "visible_queue_depletion_fallback_policy_missing"
        )
    _validate_lineage(
        plan, dataset, public_execution_tape, public_book_tape,
        quantity_conversion_tape,
    )

    live_at = datetime.fromisoformat(plan.plan.created_at)
    expires_at = datetime.fromisoformat(plan.plan.expires_at)
    cancel_at = (
        None if plan.plan.cancel_after_at is None
        else datetime.fromisoformat(plan.plan.cancel_after_at)
    )
    deadline = min(expires_at, cancel_at) if cancel_at is not None else expires_at
    if deadline <= live_at or plan.plan.maximum_input_age_seconds < 0:
        raise VisibleQueueDepletionError("visible_queue_depletion_deadline_invalid")

    books = tuple(
        item for item in public_book_tape.records
        if item.symbol == plan.plan.symbol and item.available_at <= live_at
    )
    if not books:
        raise VisibleQueueDepletionError("visible_queue_depletion_initial_book_missing")
    initial_book = books[-1]
    if (
        live_at - initial_book.available_at
    ).total_seconds() > plan.plan.maximum_input_age_seconds:
        raise VisibleQueueDepletionError("visible_queue_depletion_initial_book_stale")

    conversions_by_id = {
        item.source_record_id: item for item in quantity_conversion_tape.conversions
    }
    book_conversion = conversions_by_id.get(initial_book.source_record_id)
    if not isinstance(book_conversion, BookQuantityConversionRecord):
        raise VisibleQueueDepletionError("visible_queue_depletion_book_conversion_missing")
    metadata_by_id = {
        item.source_record_id: item for item in quantity_conversion_tape.metadata
    }
    metadata = metadata_by_id.get(book_conversion.metadata_record_id)
    if metadata is None or Decimal(str(plan.plan.contract_size)) != (
        Decimal(metadata.contract_value) * Decimal(metadata.contract_multiplier)
    ):
        raise VisibleQueueDepletionError("visible_queue_depletion_contract_size_mismatch")

    entry = Decimal(str(plan.plan.entry_price))
    visible_price = Decimal(
        initial_book.bid_price if plan.plan.side == "long" else initial_book.ask_price
    )
    if entry != visible_price:
        raise VisibleQueueDepletionError("visible_queue_depletion_entry_not_at_visible_top")
    queue = Decimal(
        book_conversion.bid_base_quantity
        if plan.plan.side == "long" else book_conversion.ask_base_quantity
    )
    initial_queue = queue
    order_quantity = Decimal(str(plan.plan.quantity)) * Decimal(str(plan.plan.contract_size))
    remaining = order_quantity
    cumulative = Decimal(0)
    trace: list[VisibleQueueDepletionTraceItem] = []
    contra_side = "sell" if plan.plan.side == "long" else "buy"

    for trade in public_execution_tape.records:
        if remaining == 0:
            break
        if (
            trade.symbol != plan.plan.symbol
            or trade.aggressor_side != contra_side
            or trade.happened_at < live_at
            or trade.available_at <= live_at
            or trade.happened_at > deadline
            or trade.available_at > deadline
        ):
            continue
        trade_price = Decimal(trade.price)
        through = (
            trade_price < entry if plan.plan.side == "long" else trade_price > entry
        )
        if trade_price != entry and not through:
            continue
        conversion = conversions_by_id.get(trade.source_record_id)
        if not isinstance(conversion, TradeQuantityConversionRecord):
            raise VisibleQueueDepletionError("visible_queue_depletion_trade_conversion_missing")
        trade_quantity = Decimal(conversion.base_quantity)
        queue_before = queue
        fill = Decimal(0)
        evidence_kind: Literal["at_price_depletion", "level_through"]
        if through:
            queue = Decimal(0)
            fill = remaining
            evidence_kind = "level_through"
        else:
            queue_consumed = min(queue, trade_quantity)
            queue -= queue_consumed
            fill = min(remaining, trade_quantity - queue_consumed)
            evidence_kind = "at_price_depletion"
        remaining -= fill
        cumulative += fill
        trace.append(
            VisibleQueueDepletionTraceItem(
                source_record_id=trade.source_record_id,
                source_event_position=conversion.source_event_position,
                happened_at=_time_string(trade.happened_at),
                available_at=_time_string(trade.available_at),
                price=_decimal_string(trade_price),
                trade_base_quantity=_decimal_string(trade_quantity),
                queue_before_base=_decimal_string(queue_before),
                queue_after_base=_decimal_string(queue),
                fill_quantity_base=_decimal_string(fill),
                cumulative_fill_quantity_base=_decimal_string(cumulative),
                remaining_order_quantity_base=_decimal_string(remaining),
                evidence_kind=evidence_kind,
            )
        )

    status: Literal["unfilled", "partially_filled", "filled"] = (
        "unfilled" if cumulative == 0 else "filled" if remaining == 0 else "partially_filled"
    )
    trace_tuple = tuple(trace)
    payload: dict[str, Any] = {
        "schema_version": "visible-queue-depletion-result.v1",
        "policy_version": "visible-queue-depletion.v1",
        "dataset_id": dataset.dataset_id,
        "dataset_checksum": dataset.dataset_checksum,
        "plan_hash": plan.plan.plan_hash,
        "config_hash": plan.plan.config_hash,
        "public_book_tape_checksum": public_book_tape.tape_checksum,
        "public_execution_tape_checksum": public_execution_tape.tape_checksum,
        "quantity_conversion_tape_checksum": quantity_conversion_tape.tape_checksum,
        "source_network": dataset.source_network,
        "market_data_venue": dataset.market_data_venue,
        "market_type": dataset.market_type.value,
        "symbol": plan.plan.symbol,
        "side": plan.plan.side,
        "entry_price": _decimal_string(entry),
        "order_live_at": _time_string(live_at),
        "effective_deadline_at": _time_string(deadline),
        "initial_book_source_record_id": initial_book.source_record_id,
        "initial_book_source_event_position": book_conversion.source_event_position,
        "initial_visible_queue_base": _decimal_string(initial_queue),
        "order_quantity_base": _decimal_string(order_quantity),
        "trace": trace_tuple,
        "filled_quantity_base": _decimal_string(cumulative),
        "remaining_quantity_base": _decimal_string(remaining),
        "status": status,
        "fills_are_certified": False,
        "queue_evidence": "visible_l1_plus_public_trades",
        "latency_assumption": "available_at_ordering_no_private_ack",
        "result_is_live_proof": False,
        "trace_hash": _hash(trace_tuple),
    }
    payload["result_hash"] = _hash(payload)
    return VisibleQueueDepletionResult.model_validate(payload)


def _validate_lineage(
    plan: CanonicalBacktestOrderPlan,
    dataset: DatasetDescriptor,
    execution: VerifiedPublicExecutionTape,
    books: VerifiedPublicBookTape,
    conversions: VerifiedPublicQuantityConversionTape,
) -> None:
    if (
        plan.dataset_id != dataset.dataset_id
        or plan.dataset_checksum != dataset.dataset_checksum
        or plan.plan.symbol not in dataset.symbols
        or plan.plan.market_type != dataset.market_type.value
        or any(
            tape.dataset_id != dataset.dataset_id
            or tape.dataset_checksum != dataset.dataset_checksum
            or tape.source_checksum != dataset.source_checksum
            or tape.source_network != dataset.source_network
            or tape.market_data_venue != dataset.market_data_venue
            for tape in (execution, books, conversions)
        )
        or conversions.market_type != dataset.market_type.value
        or conversions.public_execution_tape_checksum != execution.tape_checksum
        or conversions.public_book_tape_checksum != books.tape_checksum
    ):
        raise VisibleQueueDepletionError("visible_queue_depletion_lineage_invalid")
