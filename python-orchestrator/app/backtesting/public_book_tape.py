"""Immutable dataset-bound public L1 book tape; no execution inference lives here."""

from __future__ import annotations

import hashlib
import json
import re
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from decimal import Decimal
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator

from app.backtesting.contracts import DatasetDescriptor


_HASH = r"^sha256:[0-9a-f]{64}$"
_DECIMAL = re.compile(r"^(?:0|[1-9][0-9]*)(?:\.[0-9]*[1-9])?$")
_COUNT = re.compile(r"^[1-9][0-9]*$")
MAX_PUBLIC_BOOK_RECORDS = 30_000


def _utc(value: datetime) -> datetime:
    if value.tzinfo is None or value.utcoffset() != timedelta(0):
        raise ValueError("public_book_tape_time_invalid")
    return value.astimezone(timezone.utc)


def _json_value(value: Any) -> Any:
    if isinstance(value, BaseModel):
        return _json_value(value.model_dump())
    if isinstance(value, datetime):
        return _utc(value).isoformat(timespec="microseconds").replace("+00:00", "Z")
    if isinstance(value, dict):
        return {str(key): _json_value(item) for key, item in value.items()}
    if isinstance(value, (list, tuple)):
        return [_json_value(item) for item in value]
    return value.value if hasattr(value, "value") else value


def _canonical(value: Any) -> bytes:
    return json.dumps(
        _json_value(value),
        ensure_ascii=False,
        separators=(",", ":"),
        sort_keys=True,
    ).encode()


class PublicBookRecord(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)
    schema_version: Literal["backtest-public-book.v1"]
    source_record_id: str = Field(pattern=r"^[0-9a-f]{64}$")
    source_checksum: str = Field(pattern=_HASH)
    source_network: Literal["mainnet", "testnet"]
    market_data_venue: Literal["okx", "hyperliquid"]
    market_type: Literal["perpetual"]
    symbol: Literal["BTCUSDT", "ETHUSDT"]
    happened_at: datetime
    available_at: datetime
    bid_price: str
    bid_quantity: str
    ask_price: str
    ask_quantity: str
    quantity_unit: Literal["contracts", "base_asset"]
    bid_order_count: str | None
    ask_order_count: str | None
    origin: Literal[
        "rest_initial_snapshot", "rest_resync_snapshot", "ws_books", "ws_l2_book"
    ]

    @field_validator("happened_at", "available_at")
    @classmethod
    def _times(cls, value: datetime) -> datetime:
        return _utc(value)

    @field_validator("bid_price", "bid_quantity", "ask_price", "ask_quantity", mode="before")
    @classmethod
    def _decimals(cls, value: Any) -> str:
        if (
            type(value) is not str
            or len(value.encode()) > 256
            or _DECIMAL.fullmatch(value) is None
            or Decimal(value) <= 0
        ):
            raise ValueError("public_book_tape_decimal_invalid")
        return value

    @field_validator("bid_order_count", "ask_order_count", mode="before")
    @classmethod
    def _counts(cls, value: Any) -> str | None:
        if value is None:
            return None
        if type(value) is not str or len(value.encode()) > 128 or _COUNT.fullmatch(value) is None:
            raise ValueError("public_book_tape_count_invalid")
        return value

    @model_validator(mode="after")
    def _semantics(self) -> "PublicBookRecord":
        okx_origins = {"rest_initial_snapshot", "rest_resync_snapshot", "ws_books"}
        if (
            self.available_at < self.happened_at
            or Decimal(self.bid_price) >= Decimal(self.ask_price)
            or (
                self.market_data_venue == "okx"
                and (
                    self.quantity_unit != "contracts"
                    or self.bid_order_count is None
                    or self.ask_order_count is None
                    or self.origin not in okx_origins
                )
            )
            or (
                self.market_data_venue == "hyperliquid"
                and (
                    self.quantity_unit != "base_asset"
                    or self.bid_order_count is not None
                    or self.ask_order_count is not None
                    or self.origin != "ws_l2_book"
                )
            )
        ):
            raise ValueError("public_book_tape_record_invalid")
        return self


class PublicBookTapeArtifacts(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)
    manifest_json: bytes = Field(min_length=1, max_length=8 * 1024 * 1024)
    books_ndjson: bytes = Field(min_length=1, max_length=64 * 1024 * 1024)
    tape_checksum: str = Field(pattern=_HASH)


@dataclass(frozen=True, init=False)
class VerifiedPublicBookTape:
    artifacts: PublicBookTapeArtifacts
    dataset_id: str
    dataset_checksum: str
    source_checksum: str
    source_network: str
    market_data_venue: str
    records: tuple[PublicBookRecord, ...]
    tape_checksum: str

    def __init__(
        self,
        artifacts: PublicBookTapeArtifacts,
        *,
        dataset: DatasetDescriptor,
    ) -> None:
        try:
            if not isinstance(artifacts, PublicBookTapeArtifacts) or not isinstance(
                dataset, DatasetDescriptor
            ):
                raise TypeError
            records = tuple(
                PublicBookRecord.model_validate_json(line)
                for line in artifacts.books_ndjson.splitlines()
            )
            expected = _serialize(dataset, records)
            if expected != artifacts:
                raise ValueError
        except Exception as exc:
            raise ValueError("public_book_tape_invalid") from exc
        manifest = json.loads(artifacts.manifest_json)
        for field, value in {
            "artifacts": artifacts,
            "dataset_id": dataset.dataset_id,
            "dataset_checksum": dataset.dataset_checksum,
            "source_checksum": dataset.source_checksum,
            "source_network": dataset.source_network,
            "market_data_venue": dataset.market_data_venue,
            "records": records,
            "tape_checksum": manifest["tape_checksum"],
        }.items():
            object.__setattr__(self, field, value)


def serialize_public_book_tape(
    *, dataset: DatasetDescriptor, records: tuple[PublicBookRecord, ...]
) -> PublicBookTapeArtifacts:
    artifacts = _serialize(dataset, records)
    VerifiedPublicBookTape(artifacts, dataset=dataset)
    return artifacts


def _serialize(
    dataset: DatasetDescriptor,
    records: tuple[PublicBookRecord, ...],
) -> PublicBookTapeArtifacts:
    if (
        not isinstance(dataset, DatasetDescriptor)
        or not records
        or len(records) > MAX_PUBLIC_BOOK_RECORDS
    ):
        raise ValueError("public_book_tape_records_invalid")
    records = tuple(PublicBookRecord.model_validate(item.model_dump()) for item in records)
    ordered = tuple(
        sorted(
            records,
            key=lambda item: (item.available_at, item.happened_at, item.source_record_id),
        )
    )
    source_ids = {item.source_record_id for item in records}
    if (
        records != ordered
        or len(source_ids) != len(records)
        or any(
            item.source_network != dataset.source_network
            or item.source_checksum != dataset.source_checksum
            or item.market_data_venue != dataset.market_data_venue
            or item.market_type != dataset.market_type.value
            or not any(
                stream.symbol == item.symbol
                and stream.first_open_at <= item.happened_at < stream.last_close_at
                for stream in dataset.streams
            )
            for item in records
        )
    ):
        raise ValueError("public_book_tape_records_invalid")
    books = b"".join(_canonical(item) + b"\n" for item in records)
    core = {
        "schema_version": "backtest-public-book-tape.v1",
        "dataset_id": dataset.dataset_id,
        "dataset_checksum": dataset.dataset_checksum,
        "source_checksum": dataset.source_checksum,
        "source_network": dataset.source_network,
        "market_data_venue": dataset.market_data_venue,
        "market_type": dataset.market_type.value,
        "record_schema_version": "backtest-public-book.v1",
        "record_count": len(records),
        "books_checksum": "sha256:" + hashlib.sha256(books).hexdigest(),
    }
    tape_checksum = "sha256:" + hashlib.sha256(_canonical(core)).hexdigest()
    manifest = {**core, "tape_checksum": tape_checksum}
    return PublicBookTapeArtifacts(
        manifest_json=_canonical(manifest) + b"\n",
        books_ndjson=books,
        tape_checksum=tape_checksum,
    )
