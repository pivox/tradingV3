"""Integrity-bound historical funding schedule contracts."""

from __future__ import annotations

import hashlib
import json
import re
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from decimal import Decimal
from enum import Enum
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator


_HASH = r"^sha256:[0-9a-f]{64}$"
_DATASET_ID = r"^backtest-dataset-[0-9a-f]{64}$"
_DECIMAL = re.compile(r"^-?(?:0|[1-9][0-9]*)(?:\.[0-9]*[1-9])?$")


def _utc(value: datetime) -> datetime:
    if value.tzinfo is None or value.utcoffset() != timedelta(0):
        raise ValueError("historical_funding_timestamp_invalid")
    return value.astimezone(timezone.utc)


def _decimal(value: object, *, positive: bool = False) -> str:
    if type(value) is not str or _DECIMAL.fullmatch(value) is None:
        raise ValueError("historical_funding_decimal_invalid")
    number = Decimal(value)
    if (number == 0 and value.startswith("-")) or (positive and number <= 0):
        raise ValueError("historical_funding_decimal_invalid")
    return value


def _json_value(value: Any) -> Any:
    if isinstance(value, BaseModel):
        return _json_value(value.model_dump())
    if isinstance(value, datetime):
        return _utc(value).isoformat(timespec="microseconds").replace("+00:00", "Z")
    if isinstance(value, Enum):
        return value.value
    if isinstance(value, dict):
        return {str(key): _json_value(item) for key, item in value.items()}
    if isinstance(value, (list, tuple)):
        return [_json_value(item) for item in value]
    return value


def _canonical_json(value: Any) -> bytes:
    return json.dumps(
        _json_value(value), ensure_ascii=False, separators=(",", ":"), sort_keys=True
    ).encode()


class HistoricalFundingRecord(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)

    schema_version: Literal["historical-funding-record.v1"]
    source_record_id: str = Field(min_length=1, max_length=256)
    source_network: Literal["mainnet", "testnet"]
    market_data_venue: Literal["okx", "hyperliquid"]
    market_type: Literal["perpetual"]
    symbol: str = Field(pattern=r"^[A-Z0-9]{2,32}$")
    funding_at: datetime
    available_at: datetime
    funding_rate: str
    mark_price: str
    interval_seconds: int = Field(gt=0, le=604_800)

    @field_validator("funding_at", "available_at", mode="before")
    @classmethod
    def _timestamps(cls, value: object) -> datetime:
        if isinstance(value, datetime):
            return _utc(value)
        return _parse_time(value)

    @field_validator("funding_rate", mode="before")
    @classmethod
    def _rate(cls, value: object) -> str:
        return _decimal(value)

    @field_validator("mark_price", mode="before")
    @classmethod
    def _mark(cls, value: object) -> str:
        return _decimal(value, positive=True)

    @model_validator(mode="after")
    def _availability(self) -> "HistoricalFundingRecord":
        if self.available_at > self.funding_at:
            raise ValueError("historical_funding_record_late")
        return self


class HistoricalFundingScheduleArtifacts(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)

    schedule_json: bytes = Field(min_length=1, max_length=8 * 1024 * 1024)
    schedule_checksum: str = Field(pattern=_HASH)


@dataclass(frozen=True, init=False)
class VerifiedHistoricalFundingSchedule:
    artifacts: HistoricalFundingScheduleArtifacts
    dataset_id: str
    dataset_checksum: str
    source_network: str
    market_data_venue: str
    market_type: str
    symbol: str
    coverage_start: datetime
    coverage_end: datetime
    records: tuple[HistoricalFundingRecord, ...]
    schedule_checksum: str

    def __init__(self, artifacts: HistoricalFundingScheduleArtifacts) -> None:
        try:
            if not isinstance(artifacts, HistoricalFundingScheduleArtifacts):
                raise TypeError
            if (
                not artifacts.schedule_json.endswith(b"\n")
                or artifacts.schedule_json.endswith(b"\n\n")
                or "sha256:" + hashlib.sha256(artifacts.schedule_json).hexdigest()
                != artifacts.schedule_checksum
            ):
                raise ValueError
            raw = json.loads(artifacts.schedule_json[:-1])
            if not isinstance(raw, dict) or _canonical_json(raw) + b"\n" != artifacts.schedule_json:
                raise ValueError
            verified = _validated_schedule(raw)
            if _canonical_json(verified) + b"\n" != artifacts.schedule_json:
                raise ValueError
        except Exception as exc:
            raise ValueError("historical_funding_schedule_invalid") from exc

        for field, value in {
            "artifacts": artifacts,
            "dataset_id": verified["dataset_id"],
            "dataset_checksum": verified["dataset_checksum"],
            "source_network": verified["source_network"],
            "market_data_venue": verified["market_data_venue"],
            "market_type": verified["market_type"],
            "symbol": verified["symbol"],
            "coverage_start": verified["coverage_start"],
            "coverage_end": verified["coverage_end"],
            "records": verified["records"],
            "schedule_checksum": artifacts.schedule_checksum,
        }.items():
            object.__setattr__(self, field, value)


def _validated_schedule(raw: dict[str, Any]) -> dict[str, Any]:
    expected = {
        "schema_version", "dataset_id", "dataset_checksum", "source_network",
        "market_data_venue", "market_type", "symbol", "coverage_start",
        "coverage_end", "records",
    }
    if set(raw) != expected or raw.get("schema_version") != "historical-funding-schedule.v1":
        raise ValueError("historical_funding_schedule_shape_invalid")
    dataset_id = raw["dataset_id"]
    dataset_checksum = raw["dataset_checksum"]
    if (
        type(dataset_id) is not str or re.fullmatch(_DATASET_ID, dataset_id) is None
        or type(dataset_checksum) is not str or re.fullmatch(_HASH, dataset_checksum) is None
        or dataset_id != "backtest-dataset-" + dataset_checksum.removeprefix("sha256:")
    ):
        raise ValueError("historical_funding_dataset_invalid")
    coverage_start = _parse_time(raw["coverage_start"])
    coverage_end = _parse_time(raw["coverage_end"])
    values = raw["records"]
    if not isinstance(values, list) or not values or len(values) > 100_000:
        raise ValueError("historical_funding_records_invalid")
    records = tuple(HistoricalFundingRecord.model_validate(item) for item in values)
    identity = (
        raw["source_network"], raw["market_data_venue"], raw["market_type"], raw["symbol"]
    )
    if any(
        (item.source_network, item.market_data_venue, item.market_type, item.symbol) != identity
        for item in records
    ):
        raise ValueError("historical_funding_source_mismatch")
    interval = records[0].interval_seconds
    if (
        any(item.interval_seconds != interval for item in records)
        or records != tuple(sorted(records, key=lambda item: (item.funding_at, item.source_record_id)))
        or len({item.source_record_id for item in records}) != len(records)
        or len({item.funding_at for item in records}) != len(records)
        or records[0].funding_at != coverage_start + timedelta(seconds=interval)
        or records[-1].funding_at != coverage_end
        or any(
            current.funding_at != previous.funding_at + timedelta(seconds=interval)
            for previous, current in zip(records, records[1:])
        )
    ):
        raise ValueError("historical_funding_coverage_invalid")
    return {
        "schema_version": "historical-funding-schedule.v1",
        "dataset_id": dataset_id,
        "dataset_checksum": dataset_checksum,
        "source_network": identity[0],
        "market_data_venue": identity[1],
        "market_type": identity[2],
        "symbol": identity[3],
        "coverage_start": coverage_start,
        "coverage_end": coverage_end,
        "records": records,
    }


def _parse_time(value: object) -> datetime:
    if type(value) is not str or not value.endswith("Z"):
        raise ValueError("historical_funding_timestamp_invalid")
    try:
        return _utc(datetime.fromisoformat(value.removesuffix("Z") + "+00:00"))
    except ValueError as exc:
        raise ValueError("historical_funding_timestamp_invalid") from exc


def serialize_historical_funding_schedule(
    *,
    dataset_id: str,
    dataset_checksum: str,
    coverage_start: datetime,
    coverage_end: datetime,
    records: tuple[HistoricalFundingRecord, ...],
) -> HistoricalFundingScheduleArtifacts:
    if not records:
        raise ValueError("historical_funding_records_invalid")
    first = records[0]
    raw: dict[str, Any] = {
        "schema_version": "historical-funding-schedule.v1",
        "dataset_id": dataset_id,
        "dataset_checksum": dataset_checksum,
        "source_network": first.source_network,
        "market_data_venue": first.market_data_venue,
        "market_type": first.market_type,
        "symbol": first.symbol,
        "coverage_start": coverage_start,
        "coverage_end": coverage_end,
        "records": records,
    }
    validated = _validated_schedule(_json_value(raw))
    schedule_json = _canonical_json(validated) + b"\n"
    artifacts = HistoricalFundingScheduleArtifacts(
        schedule_json=schedule_json,
        schedule_checksum="sha256:" + hashlib.sha256(schedule_json).hexdigest(),
    )
    VerifiedHistoricalFundingSchedule(artifacts)
    return artifacts
