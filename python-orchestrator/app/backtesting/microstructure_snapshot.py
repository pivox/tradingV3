"""Canonical spread and aggressor-volume OFI from verified public tapes."""

from __future__ import annotations

import hashlib
import json
from datetime import datetime, timedelta, timezone
from decimal import Decimal, ROUND_HALF_EVEN
from typing import Any

from pydantic import BaseModel, ConfigDict, Field, model_validator

from app.backtesting.public_book_tape import VerifiedPublicBookTape
from app.backtesting.public_execution_tape import VerifiedPublicExecutionTape


_HASH = r"^sha256:[0-9a-f]{64}$"
_QUANTUM = Decimal("0.000000000001")


def _utc(value: datetime) -> datetime:
    if value.tzinfo is None or value.utcoffset() != timedelta(0):
        raise ValueError("canonical_microstructure_time_invalid")
    return value.astimezone(timezone.utc)


def _time(value: datetime) -> str:
    return _utc(value).isoformat(timespec="microseconds").replace("+00:00", "Z")


def _text(value: Decimal, *, rounded: bool = False) -> str:
    if rounded:
        value = value.quantize(_QUANTUM, rounding=ROUND_HALF_EVEN)
    rendered = format(value, "f").rstrip("0").rstrip(".")
    return rendered if rendered not in {"", "-0"} else "0"


def _canonical(value: Any) -> bytes:
    return json.dumps(value, ensure_ascii=False, separators=(",", ":"), sort_keys=True).encode()


class MicrostructurePolicy(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)
    schema_version: str = "canonical-microstructure-policy.v1"
    window_seconds: int
    maximum_book_age_seconds: int
    maximum_trade_age_seconds: int
    maximum_trade_gap_seconds: int
    minimum_trade_count: int

    @model_validator(mode="after")
    def _valid(self) -> "MicrostructurePolicy":
        if (
            self.schema_version != "canonical-microstructure-policy.v1"
            or not 1 <= self.window_seconds <= 3600
            or not 1 <= self.maximum_book_age_seconds <= self.window_seconds
            or not 1 <= self.maximum_trade_age_seconds <= self.window_seconds
            or not 1 <= self.maximum_trade_gap_seconds <= self.window_seconds
            or not 1 <= self.minimum_trade_count <= 100_000
        ):
            raise ValueError("canonical_microstructure_policy_invalid")
        return self


class CanonicalMicrostructureSnapshot(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)
    schema_version: str = "canonical-microstructure-snapshot.v1"
    source_network: str
    market_data_venue: str
    market_type: str
    symbol: str
    source_checksum: str = Field(pattern=_HASH)
    evaluated_at: str
    window_start: str
    policy: MicrostructurePolicy
    book_source_record_id: str
    book_happened_at: str
    book_available_at: str
    best_bid: str
    best_ask: str
    spread_bps: str
    order_flow_imbalance_definition: str = "aggressor_volume_ratio.v1"
    quantity_unit: str
    trade_count: int
    buy_quantity: str
    sell_quantity: str
    total_quantity: str
    order_flow_imbalance: str
    first_trade_happened_at: str
    last_trade_happened_at: str
    last_trade_available_at: str
    trade_source_record_ids: tuple[str, ...]
    input_hash: str = Field(pattern=_HASH)

    def payload(self) -> dict[str, Any]:
        return {
            "schema_version": self.schema_version,
            "source_network": self.source_network,
            "market_data_venue": self.market_data_venue,
            "market_type": self.market_type,
            "symbol": self.symbol,
            "source_checksum": self.source_checksum,
            "evaluated_at": self.evaluated_at,
            "window_start": self.window_start,
            "policy": self.policy.model_dump(),
            "book": {
                "source_record_id": self.book_source_record_id,
                "happened_at": self.book_happened_at,
                "available_at": self.book_available_at,
                "best_bid": self.best_bid,
                "best_ask": self.best_ask,
                "spread_bps": self.spread_bps,
            },
            "flow": {
                "definition": self.order_flow_imbalance_definition,
                "quantity_unit": self.quantity_unit,
                "trade_count": self.trade_count,
                "buy_quantity": self.buy_quantity,
                "sell_quantity": self.sell_quantity,
                "total_quantity": self.total_quantity,
                "order_flow_imbalance": self.order_flow_imbalance,
                "first_trade_happened_at": self.first_trade_happened_at,
                "last_trade_happened_at": self.last_trade_happened_at,
                "last_trade_available_at": self.last_trade_available_at,
                "source_record_ids": list(self.trade_source_record_ids),
            },
        }

    def verify(self) -> "CanonicalMicrostructureSnapshot":
        expected = "sha256:" + hashlib.sha256(_canonical(self.payload())).hexdigest()
        if expected != self.input_hash:
            raise ValueError("canonical_microstructure_snapshot_hash_mismatch")
        return self


def build_microstructure_snapshot(
    *,
    policy: MicrostructurePolicy,
    evaluated_at: datetime,
    public_book_tape: VerifiedPublicBookTape,
    public_execution_tape: VerifiedPublicExecutionTape,
) -> CanonicalMicrostructureSnapshot:
    evaluated_at = _utc(evaluated_at)
    if not isinstance(policy, MicrostructurePolicy) or not isinstance(public_book_tape, VerifiedPublicBookTape) or not isinstance(public_execution_tape, VerifiedPublicExecutionTape):
        raise ValueError("canonical_microstructure_input_invalid")
    identity = (
        public_book_tape.dataset_id, public_book_tape.dataset_checksum,
        public_book_tape.source_checksum, public_book_tape.source_network,
        public_book_tape.market_data_venue,
    )
    if identity != (
        public_execution_tape.dataset_id, public_execution_tape.dataset_checksum,
        public_execution_tape.source_checksum, public_execution_tape.source_network,
        public_execution_tape.market_data_venue,
    ):
        raise ValueError("canonical_microstructure_identity_mismatch")
    available_books = [item for item in public_book_tape.records if item.available_at <= evaluated_at]
    if not available_books:
        raise ValueError("canonical_microstructure_book_unavailable")
    book = available_books[-1]
    if (evaluated_at - book.happened_at).total_seconds() > policy.maximum_book_age_seconds:
        raise ValueError("canonical_microstructure_book_stale")
    window_start = evaluated_at - timedelta(seconds=policy.window_seconds)
    trades = [
        item for item in public_execution_tape.records
        if window_start <= item.happened_at <= evaluated_at and item.available_at <= evaluated_at
    ]
    if len(trades) < policy.minimum_trade_count:
        raise ValueError("canonical_microstructure_trades_insufficient")
    if (evaluated_at - trades[-1].happened_at).total_seconds() > policy.maximum_trade_age_seconds:
        raise ValueError("canonical_microstructure_trades_stale")
    points = [window_start, *(item.happened_at for item in trades), evaluated_at]
    if any((right - left).total_seconds() > policy.maximum_trade_gap_seconds for left, right in zip(points, points[1:])):
        raise ValueError("canonical_microstructure_trade_gap")
    units = {item.quantity_unit for item in trades} | {book.quantity_unit}
    symbols = {item.symbol for item in trades} | {book.symbol}
    if len(units) != 1 or len(symbols) != 1:
        raise ValueError("canonical_microstructure_identity_mismatch")
    buy = sum((Decimal(item.quantity) for item in trades if item.aggressor_side == "buy"), Decimal(0))
    sell = sum((Decimal(item.quantity) for item in trades if item.aggressor_side == "sell"), Decimal(0))
    total = buy + sell
    imbalance = (buy - sell) / total
    bid, ask = Decimal(book.bid_price), Decimal(book.ask_price)
    spread = Decimal(10000) * (ask - bid) / ((ask + bid) / Decimal(2))
    values: dict[str, Any] = {
        "source_network": book.source_network, "market_data_venue": book.market_data_venue,
        "market_type": book.market_type, "symbol": book.symbol,
        "source_checksum": book.source_checksum, "evaluated_at": _time(evaluated_at),
        "window_start": _time(window_start), "policy": policy,
        "book_source_record_id": book.source_record_id, "book_happened_at": _time(book.happened_at),
        "book_available_at": _time(book.available_at), "best_bid": _text(bid), "best_ask": _text(ask),
        "spread_bps": _text(spread, rounded=True), "quantity_unit": book.quantity_unit,
        "trade_count": len(trades), "buy_quantity": _text(buy), "sell_quantity": _text(sell),
        "total_quantity": _text(total), "order_flow_imbalance": _text(imbalance, rounded=True),
        "first_trade_happened_at": _time(trades[0].happened_at),
        "last_trade_happened_at": _time(trades[-1].happened_at),
        "last_trade_available_at": _time(trades[-1].available_at),
        "trade_source_record_ids": tuple(item.source_record_id for item in trades),
    }
    temporary = CanonicalMicrostructureSnapshot(**values, input_hash="sha256:" + "0" * 64)
    snapshot = temporary.model_copy(update={"input_hash": "sha256:" + hashlib.sha256(_canonical(temporary.payload())).hexdigest()})
    return snapshot.verify()
