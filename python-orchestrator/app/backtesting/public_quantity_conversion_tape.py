"""Dataset-bound venue quantity conversion evidence; never infers fills."""

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
from app.backtesting.public_book_tape import VerifiedPublicBookTape
from app.backtesting.public_execution_tape import VerifiedPublicExecutionTape


_HASH = r"^sha256:[0-9a-f]{64}$"
_RECORD_ID = r"^[0-9a-f]{64}$"
_DECIMAL = re.compile(r"^(?:0|[1-9][0-9]*)(?:\.[0-9]*[1-9])?$")
MAX_METADATA_RECORDS = 10_000
MAX_CONVERSION_RECORDS = 70_000


def _utc(value: datetime) -> datetime:
    if value.tzinfo is None or value.utcoffset() != timedelta(0):
        raise ValueError("public_quantity_conversion_time_invalid")
    return value.astimezone(timezone.utc)


def _decimal(value: Any) -> str:
    if (
        type(value) is not str
        or len(value.encode()) > 256
        or _DECIMAL.fullmatch(value) is None
        or Decimal(value) <= 0
    ):
        raise ValueError("public_quantity_conversion_decimal_invalid")
    return value


def _canonical_decimal(value: Decimal) -> str:
    rendered = format(value, "f")
    if "." in rendered:
        rendered = rendered.rstrip("0").rstrip(".")
    return rendered


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
        _json_value(value), ensure_ascii=False, separators=(",", ":"), sort_keys=True
    ).encode()


class InstrumentMetadataRecord(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)
    schema_version: Literal["backtest-instrument-metadata.v1"]
    source_record_id: str = Field(pattern=_RECORD_ID)
    source_checksum: str = Field(pattern=_HASH)
    source_network: Literal["mainnet", "testnet"]
    market_data_venue: Literal["okx", "hyperliquid"]
    market_type: Literal["perpetual"]
    symbol: Literal["BTCUSDT", "ETHUSDT"]
    source_event_position: int = Field(ge=0, le=9_223_372_036_854_775_807)
    available_at: datetime
    source_epoch: int = Field(ge=1, le=9_223_372_036_854_775_807)
    quantity_unit: Literal["contracts", "base_asset"]
    contract_value: str
    contract_multiplier: str
    contract_value_unit: Literal["BTC", "ETH"]

    @field_validator("available_at")
    @classmethod
    def _time(cls, value: datetime) -> datetime:
        return _utc(value)

    @field_validator("contract_value", "contract_multiplier", mode="before")
    @classmethod
    def _decimals(cls, value: Any) -> str:
        return _decimal(value)

    @model_validator(mode="after")
    def _semantics(self) -> "InstrumentMetadataRecord":
        base = "BTC" if self.symbol == "BTCUSDT" else "ETH"
        if (
            self.contract_value_unit != base
            or (self.market_data_venue == "okx" and self.quantity_unit != "contracts")
            or (
                self.market_data_venue == "hyperliquid"
                and (
                    self.quantity_unit != "base_asset"
                    or self.contract_value != "1"
                    or self.contract_multiplier != "1"
                )
            )
        ):
            raise ValueError("public_quantity_conversion_metadata_invalid")
        return self


class _ConversionRecord(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)
    source_record_id: str = Field(pattern=_RECORD_ID)
    source_event_position: int = Field(ge=0, le=9_223_372_036_854_775_807)
    source_checksum: str = Field(pattern=_HASH)
    source_network: Literal["mainnet", "testnet"]
    market_data_venue: Literal["okx", "hyperliquid"]
    market_type: Literal["perpetual"]
    symbol: Literal["BTCUSDT", "ETHUSDT"]
    happened_at: datetime
    available_at: datetime
    metadata_record_id: str = Field(pattern=_RECORD_ID)
    metadata_event_position: int = Field(ge=0, le=9_223_372_036_854_775_807)
    metadata_available_at: datetime
    source_quantity_unit: Literal["contracts", "base_asset"]
    base_quantity_unit: Literal["base_asset"]

    @field_validator("happened_at", "available_at", "metadata_available_at")
    @classmethod
    def _times(cls, value: datetime) -> datetime:
        return _utc(value)

    @model_validator(mode="after")
    def _ordering(self) -> "_ConversionRecord":
        if (
            self.available_at < self.happened_at
            or self.metadata_available_at > self.available_at
            or self.metadata_event_position >= self.source_event_position
            or (self.market_data_venue == "okx" and self.source_quantity_unit != "contracts")
            or (
                self.market_data_venue == "hyperliquid"
                and self.source_quantity_unit != "base_asset"
            )
        ):
            raise ValueError("public_quantity_conversion_record_invalid")
        return self


class TradeQuantityConversionRecord(_ConversionRecord):
    schema_version: Literal["backtest-trade-quantity-conversion.v1"]
    source_channel: Literal["public_trade"]
    source_quantity: str
    base_quantity: str

    @field_validator("source_quantity", "base_quantity", mode="before")
    @classmethod
    def _decimals(cls, value: Any) -> str:
        return _decimal(value)


class BookQuantityConversionRecord(_ConversionRecord):
    schema_version: Literal["backtest-book-quantity-conversion.v1"]
    source_channel: Literal["top_of_book"]
    bid_source_quantity: str
    bid_base_quantity: str
    ask_source_quantity: str
    ask_base_quantity: str

    @field_validator(
        "bid_source_quantity", "bid_base_quantity", "ask_source_quantity", "ask_base_quantity",
        mode="before",
    )
    @classmethod
    def _decimals(cls, value: Any) -> str:
        return _decimal(value)


ConversionRecord = TradeQuantityConversionRecord | BookQuantityConversionRecord


class PublicQuantityConversionTapeArtifacts(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)
    manifest_json: bytes = Field(min_length=1, max_length=8 * 1024 * 1024)
    metadata_ndjson: bytes = Field(min_length=1, max_length=16 * 1024 * 1024)
    conversions_ndjson: bytes = Field(min_length=1, max_length=128 * 1024 * 1024)
    tape_checksum: str = Field(pattern=_HASH)


@dataclass(frozen=True, init=False)
class VerifiedPublicQuantityConversionTape:
    artifacts: PublicQuantityConversionTapeArtifacts
    metadata: tuple[InstrumentMetadataRecord, ...]
    conversions: tuple[ConversionRecord, ...]
    tape_checksum: str

    def __init__(
        self,
        artifacts: PublicQuantityConversionTapeArtifacts,
        *,
        dataset: DatasetDescriptor,
        public_execution_tape: VerifiedPublicExecutionTape | None,
        public_book_tape: VerifiedPublicBookTape | None,
    ) -> None:
        try:
            if not isinstance(artifacts, PublicQuantityConversionTapeArtifacts):
                raise TypeError
            metadata = tuple(
                InstrumentMetadataRecord.model_validate_json(line)
                for line in artifacts.metadata_ndjson.splitlines()
            )
            conversions = tuple(_parse_conversion(line) for line in artifacts.conversions_ndjson.splitlines())
            expected = _serialize(
                dataset=dataset,
                public_execution_tape=public_execution_tape,
                public_book_tape=public_book_tape,
                metadata=metadata,
                conversions=conversions,
            )
            if expected != artifacts:
                raise ValueError
        except Exception as exc:
            raise ValueError("public_quantity_conversion_tape_invalid") from exc
        object.__setattr__(self, "artifacts", artifacts)
        object.__setattr__(self, "metadata", metadata)
        object.__setattr__(self, "conversions", conversions)
        object.__setattr__(self, "tape_checksum", artifacts.tape_checksum)


def serialize_public_quantity_conversion_tape(
    *,
    dataset: DatasetDescriptor,
    public_execution_tape: VerifiedPublicExecutionTape | None,
    public_book_tape: VerifiedPublicBookTape | None,
    metadata: tuple[InstrumentMetadataRecord, ...],
    conversions: tuple[ConversionRecord, ...],
) -> PublicQuantityConversionTapeArtifacts:
    artifacts = _serialize(
        dataset=dataset,
        public_execution_tape=public_execution_tape,
        public_book_tape=public_book_tape,
        metadata=metadata,
        conversions=conversions,
    )
    VerifiedPublicQuantityConversionTape(
        artifacts,
        dataset=dataset,
        public_execution_tape=public_execution_tape,
        public_book_tape=public_book_tape,
    )
    return artifacts


def _parse_conversion(line: bytes) -> ConversionRecord:
    payload = json.loads(line)
    if payload.get("source_channel") == "public_trade":
        return TradeQuantityConversionRecord.model_validate_json(line)
    if payload.get("source_channel") == "top_of_book":
        return BookQuantityConversionRecord.model_validate_json(line)
    raise ValueError("public_quantity_conversion_record_invalid")


def _serialize(
    *,
    dataset: DatasetDescriptor,
    public_execution_tape: VerifiedPublicExecutionTape | None,
    public_book_tape: VerifiedPublicBookTape | None,
    metadata: tuple[InstrumentMetadataRecord, ...],
    conversions: tuple[ConversionRecord, ...],
) -> PublicQuantityConversionTapeArtifacts:
    if (
        not isinstance(dataset, DatasetDescriptor)
        or (public_execution_tape is None and public_book_tape is None)
        or not metadata
        or not conversions
        or len(metadata) > MAX_METADATA_RECORDS
        or len(conversions) > MAX_CONVERSION_RECORDS
    ):
        raise ValueError("public_quantity_conversion_records_invalid")
    tapes = tuple(tape for tape in (public_execution_tape, public_book_tape) if tape is not None)
    if any(
        tape.dataset_id != dataset.dataset_id
        or tape.dataset_checksum != dataset.dataset_checksum
        or tape.source_checksum != dataset.source_checksum
        or tape.source_network != dataset.source_network
        or tape.market_data_venue != dataset.market_data_venue
        for tape in tapes
    ):
        raise ValueError("public_quantity_conversion_records_invalid")

    if any(not isinstance(item, InstrumentMetadataRecord) for item in metadata) or any(
        not isinstance(item, (TradeQuantityConversionRecord, BookQuantityConversionRecord))
        for item in conversions
    ):
        raise ValueError("public_quantity_conversion_records_invalid")
    metadata = tuple(InstrumentMetadataRecord.model_validate(item.model_dump()) for item in metadata)
    conversions = tuple(
        type(item).model_validate(item.model_dump()) for item in conversions
    )
    if (
        len(conversions) == 0
        or tuple(sorted(metadata, key=lambda item: item.source_event_position)) != metadata
        or tuple(sorted(conversions, key=lambda item: item.source_event_position)) != conversions
        or len({item.source_record_id for item in metadata}) != len(metadata)
        or len({item.source_record_id for item in conversions}) != len(conversions)
    ):
        raise ValueError("public_quantity_conversion_records_invalid")
    metadata_by_id = {item.source_record_id: item for item in metadata}
    raw_records: dict[str, Any] = {}
    if public_execution_tape is not None:
        raw_records.update({item.source_record_id: item for item in public_execution_tape.records})
    if public_book_tape is not None:
        for item in public_book_tape.records:
            if item.source_record_id in raw_records:
                raise ValueError("public_quantity_conversion_records_invalid")
            raw_records[item.source_record_id] = item
    if set(raw_records) != {item.source_record_id for item in conversions}:
        raise ValueError("public_quantity_conversion_records_invalid")

    for item in metadata:
        if (
            item.source_checksum != dataset.source_checksum
            or item.source_network != dataset.source_network
            or item.market_data_venue != dataset.market_data_venue
        ):
            raise ValueError("public_quantity_conversion_records_invalid")
    for conversion in conversions:
        raw = raw_records[conversion.source_record_id]
        authority = metadata_by_id.get(conversion.metadata_record_id)
        if authority is None or not _matches_common(conversion, raw, authority, dataset):
            raise ValueError("public_quantity_conversion_records_invalid")
        factor = Decimal(authority.contract_value) * Decimal(authority.contract_multiplier)
        if isinstance(conversion, TradeQuantityConversionRecord):
            if not hasattr(raw, "quantity") or (
                conversion.source_quantity != raw.quantity
                or conversion.base_quantity
                != _canonical_decimal(Decimal(raw.quantity) * factor)
            ):
                raise ValueError("public_quantity_conversion_records_invalid")
        elif not hasattr(raw, "bid_quantity") or (
            conversion.bid_source_quantity != raw.bid_quantity
            or conversion.ask_source_quantity != raw.ask_quantity
            or conversion.bid_base_quantity
            != _canonical_decimal(Decimal(raw.bid_quantity) * factor)
            or conversion.ask_base_quantity
            != _canonical_decimal(Decimal(raw.ask_quantity) * factor)
        ):
            raise ValueError("public_quantity_conversion_records_invalid")

    metadata_bytes = b"".join(_canonical(item) + b"\n" for item in metadata)
    conversion_bytes = b"".join(_canonical(item) + b"\n" for item in conversions)
    core = {
        "schema_version": "backtest-public-quantity-conversion-tape.v1",
        "dataset_id": dataset.dataset_id,
        "dataset_checksum": dataset.dataset_checksum,
        "source_checksum": dataset.source_checksum,
        "source_network": dataset.source_network,
        "market_data_venue": dataset.market_data_venue,
        "market_type": dataset.market_type.value,
        "public_execution_tape_checksum": (
            None if public_execution_tape is None else public_execution_tape.tape_checksum
        ),
        "public_book_tape_checksum": None if public_book_tape is None else public_book_tape.tape_checksum,
        "metadata_record_count": len(metadata),
        "metadata_checksum": "sha256:" + hashlib.sha256(metadata_bytes).hexdigest(),
        "conversion_record_count": len(conversions),
        "conversions_checksum": "sha256:" + hashlib.sha256(conversion_bytes).hexdigest(),
    }
    tape_checksum = "sha256:" + hashlib.sha256(_canonical(core)).hexdigest()
    return PublicQuantityConversionTapeArtifacts(
        manifest_json=_canonical({**core, "tape_checksum": tape_checksum}) + b"\n",
        metadata_ndjson=metadata_bytes,
        conversions_ndjson=conversion_bytes,
        tape_checksum=tape_checksum,
    )


def _matches_common(
    conversion: ConversionRecord,
    raw: Any,
    metadata: InstrumentMetadataRecord,
    dataset: DatasetDescriptor,
) -> bool:
    return (
        conversion.source_checksum == dataset.source_checksum == metadata.source_checksum
        and conversion.source_network == dataset.source_network == metadata.source_network
        and conversion.market_data_venue == dataset.market_data_venue == metadata.market_data_venue
        and conversion.symbol == raw.symbol == metadata.symbol
        and conversion.happened_at == raw.happened_at
        and conversion.available_at == raw.available_at
        and conversion.source_quantity_unit == raw.quantity_unit == metadata.quantity_unit
        and conversion.metadata_event_position == metadata.source_event_position
        and conversion.metadata_available_at == metadata.available_at
        and conversion.metadata_event_position < conversion.source_event_position
        and metadata.available_at <= raw.available_at
    )
