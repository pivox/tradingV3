"""Immutable dataset-bound public trade tape; no execution inference lives here."""

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
MAX_PUBLIC_TRADE_RECORDS = 40_000


def _utc(value: datetime) -> datetime:
    if value.tzinfo is None or value.utcoffset() != timedelta(0):
        raise ValueError("public_execution_tape_time_invalid")
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


class PublicTradeRecord(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)
    schema_version: Literal["backtest-public-trade.v1"]
    source_record_id: str = Field(pattern=r"^[0-9a-f]{64}$")
    source_checksum: str = Field(pattern=_HASH)
    source_network: Literal["mainnet", "testnet"]
    market_data_venue: Literal["okx", "hyperliquid"]
    market_type: Literal["perpetual"]
    symbol: Literal["BTCUSDT", "ETHUSDT"]
    venue_trade_id: str = Field(min_length=1, max_length=128)
    happened_at: datetime
    available_at: datetime
    aggressor_side: Literal["buy", "sell"]
    price: str
    quantity: str
    quantity_unit: Literal["contracts", "base_asset"]

    @field_validator("happened_at", "available_at")
    @classmethod
    def _times(cls, value: datetime) -> datetime:
        return _utc(value)

    @field_validator("price", "quantity", mode="before")
    @classmethod
    def _decimals(cls, value: Any) -> str:
        if (
            type(value) is not str
            or len(value.encode()) > 256
            or _DECIMAL.fullmatch(value) is None
            or Decimal(value) <= 0
        ):
            raise ValueError("public_execution_tape_decimal_invalid")
        return value

    @field_validator("venue_trade_id")
    @classmethod
    def _venue_trade_identity(cls, value: str) -> str:
        if re.fullmatch(r"(?:0|[1-9][0-9]*)(?::(?:0|[1-9][0-9]*))?", value) is None:
            raise ValueError("public_execution_tape_venue_trade_id_invalid")
        return value

    @model_validator(mode="after")
    def _semantics(self) -> "PublicTradeRecord":
        if self.available_at < self.happened_at or (
            self.market_data_venue == "okx" and self.quantity_unit != "contracts"
        ) or (
            self.market_data_venue == "hyperliquid" and self.quantity_unit != "base_asset"
        ) or (
            self.market_data_venue == "okx" and ":" in self.venue_trade_id
        ) or (
            self.market_data_venue == "hyperliquid" and ":" not in self.venue_trade_id
        ):
            raise ValueError("public_execution_tape_record_invalid")
        return self


class PublicExecutionTapeArtifacts(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)
    manifest_json: bytes = Field(min_length=1, max_length=8 * 1024 * 1024)
    trades_ndjson: bytes = Field(min_length=1, max_length=64 * 1024 * 1024)
    tape_checksum: str = Field(pattern=_HASH)


@dataclass(frozen=True, init=False)
class VerifiedPublicExecutionTape:
    artifacts: PublicExecutionTapeArtifacts
    dataset_id: str
    dataset_checksum: str
    source_checksum: str
    source_network: str
    market_data_venue: str
    records: tuple[PublicTradeRecord, ...]
    tape_checksum: str

    def __init__(
        self,
        artifacts: PublicExecutionTapeArtifacts,
        *,
        dataset: DatasetDescriptor,
    ) -> None:
        try:
            if not isinstance(artifacts, PublicExecutionTapeArtifacts) or not isinstance(
                dataset, DatasetDescriptor
            ):
                raise TypeError
            records = tuple(
                PublicTradeRecord.model_validate_json(line)
                for line in artifacts.trades_ndjson.splitlines()
            )
            expected = _serialize(dataset, records)
            if expected != artifacts:
                raise ValueError
        except Exception as exc:
            raise ValueError("public_execution_tape_invalid") from exc
        manifest = json.loads(artifacts.manifest_json)
        for field, value in {
            "artifacts": artifacts, "dataset_id": dataset.dataset_id,
            "dataset_checksum": dataset.dataset_checksum,
            "source_checksum": dataset.source_checksum,
            "source_network": dataset.source_network,
            "market_data_venue": dataset.market_data_venue,
            "records": records, "tape_checksum": manifest["tape_checksum"],
        }.items():
            object.__setattr__(self, field, value)


def serialize_public_execution_tape(
    *, dataset: DatasetDescriptor, records: tuple[PublicTradeRecord, ...],
) -> PublicExecutionTapeArtifacts:
    artifacts = _serialize(dataset, records)
    VerifiedPublicExecutionTape(artifacts, dataset=dataset)
    return artifacts


def _serialize(
    dataset: DatasetDescriptor,
    records: tuple[PublicTradeRecord, ...],
) -> PublicExecutionTapeArtifacts:
    if (
        not isinstance(dataset, DatasetDescriptor)
        or not records
        or len(records) > MAX_PUBLIC_TRADE_RECORDS
    ):
        raise ValueError("public_execution_tape_records_invalid")
    records = tuple(PublicTradeRecord.model_validate(item.model_dump()) for item in records)
    ordered = tuple(
        sorted(
            records,
            key=lambda item: (
                item.available_at,
                item.happened_at,
                item.source_record_id,
            ),
        )
    )
    source_ids = {item.source_record_id for item in records}
    venue_ids = {(item.symbol, item.venue_trade_id) for item in records}
    if (
        records != ordered
        or len(source_ids) != len(records)
        or len(venue_ids) != len(records)
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
        raise ValueError("public_execution_tape_records_invalid")
    trades = b"".join(_canonical(item) + b"\n" for item in records)
    core = {
        "schema_version": "backtest-public-execution-tape.v1",
        "dataset_id": dataset.dataset_id,
        "dataset_checksum": dataset.dataset_checksum,
        "source_checksum": dataset.source_checksum,
        "source_network": dataset.source_network,
        "market_data_venue": dataset.market_data_venue,
        "market_type": dataset.market_type.value,
        "record_schema_version": "backtest-public-trade.v1",
        "record_count": len(records),
        "trades_checksum": "sha256:" + hashlib.sha256(trades).hexdigest(),
    }
    tape_checksum = "sha256:" + hashlib.sha256(_canonical(core)).hexdigest()
    manifest = {**core, "tape_checksum": tape_checksum}
    return PublicExecutionTapeArtifacts(
        manifest_json=_canonical(manifest) + b"\n",
        trades_ndjson=trades,
        tape_checksum=tape_checksum,
    )
