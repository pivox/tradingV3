"""Normalized, deterministic candle dataset contracts for backtesting.

The boundary accepts only source-owned, already authenticated records.  It does
not read or verify raw Paper manifests and deliberately contains no strategy
identity.
"""

from __future__ import annotations

import re
from datetime import datetime, timedelta, timezone
from decimal import Decimal
from enum import Enum
from typing import Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator

from app.backtesting.contracts import MarketType


_CANDLE_SCHEMA_VERSION = "backtest-candle.v1"
_QUALITY_SCHEMA_VERSION = "backtest-dataset-quality.v1"
_SHA256_PATTERN = r"^sha256:[0-9a-f]{64}$"
_CANONICAL_DECIMAL_PATTERN = re.compile(r"^(?:0|[1-9][0-9]*(?:\.[0-9]*[1-9])?)$")


class Timeframe(str, Enum):
    """The five exact v1 candle intervals, with numeric ordering metadata."""

    ONE_MINUTE = "1m"
    FIVE_MINUTES = "5m"
    FIFTEEN_MINUTES = "15m"
    ONE_HOUR = "1h"
    FOUR_HOURS = "4h"

    @property
    def duration_seconds(self) -> int:
        return {
            Timeframe.ONE_MINUTE: 60,
            Timeframe.FIVE_MINUTES: 5 * 60,
            Timeframe.FIFTEEN_MINUTES: 15 * 60,
            Timeframe.ONE_HOUR: 60 * 60,
            Timeframe.FOUR_HOURS: 4 * 60 * 60,
        }[self]

    @property
    def duration(self) -> timedelta:
        return timedelta(seconds=self.duration_seconds)


def _require_utc(value: datetime) -> datetime:
    if value.tzinfo is None or value.utcoffset() is None:
        raise ValueError("datetime must be UTC-aware")
    if value.utcoffset() != timedelta(0):
        raise ValueError("datetime must use UTC offset")
    return value.astimezone(timezone.utc)


def _require_nonblank(value: str) -> str:
    if not value.strip():
        raise ValueError("value must not be blank")
    return value


def _canonical_decimal(value: object) -> str:
    if not isinstance(value, str) or _CANONICAL_DECIMAL_PATTERN.fullmatch(value) is None:
        raise ValueError("value must be a canonical decimal string")
    return value


class CandleRecord(BaseModel):
    """One immutable, normalized, complete candle."""

    model_config = ConfigDict(frozen=True, extra="forbid")

    schema_version: Literal["backtest-candle.v1"] = _CANDLE_SCHEMA_VERSION
    source_record_id: str = Field(..., min_length=1)
    source_network: str = Field(..., min_length=1)
    market_data_venue: str = Field(..., min_length=1)
    market_type: MarketType
    symbol: str = Field(..., min_length=1)
    timeframe: Timeframe
    open_at: datetime
    close_at: datetime
    available_at: datetime
    open: str
    high: str
    low: str
    close: str
    volume: str
    complete: Literal[True] = True

    @field_validator(
        "source_record_id",
        "source_network",
        "market_data_venue",
        "symbol",
    )
    @classmethod
    def _validate_nonblank(cls, value: str) -> str:
        return _require_nonblank(value)

    @field_validator("open_at", "close_at", "available_at")
    @classmethod
    def _validate_utc(cls, value: datetime) -> datetime:
        return _require_utc(value)

    @field_validator("open", "high", "low", "close", "volume", mode="before")
    @classmethod
    def _validate_decimal_grammar(cls, value: object) -> str:
        return _canonical_decimal(value)

    @field_validator("complete", mode="before")
    @classmethod
    def _validate_complete(cls, value: object) -> object:
        if value is not True:
            raise ValueError("complete must be true")
        return value

    @model_validator(mode="after")
    def _validate_candle(self) -> "CandleRecord":
        if self.close_at - self.open_at != self.timeframe.duration:
            raise ValueError("candle duration must equal timeframe")
        if self.available_at < self.close_at:
            raise ValueError("available_at must not precede close_at")

        open_price = Decimal(self.open)
        high_price = Decimal(self.high)
        low_price = Decimal(self.low)
        close_price = Decimal(self.close)
        for price in (open_price, high_price, low_price, close_price):
            if price <= 0:
                raise ValueError("price must be positive")
        if low_price > open_price or low_price > close_price:
            raise ValueError("low must not exceed open or close")
        if high_price < open_price or high_price < close_price:
            raise ValueError("high must not be below open or close")
        return self


class DatasetSourceIdentity(BaseModel):
    """Exact identity of the already verified source passed to the builder."""

    model_config = ConfigDict(frozen=True, extra="forbid")

    source: str = Field(..., min_length=1)
    source_schema_version: str = Field(..., min_length=1)
    source_build_version: str = Field(..., min_length=1)
    source_checksum: str = Field(..., pattern=_SHA256_PATTERN)
    source_network: str = Field(..., min_length=1)
    market_data_venue: str = Field(..., min_length=1)
    market_type: MarketType

    @field_validator(
        "source",
        "source_schema_version",
        "source_build_version",
        "source_network",
        "market_data_venue",
    )
    @classmethod
    def _validate_nonblank(cls, value: str) -> str:
        return _require_nonblank(value)


class MissingRange(BaseModel):
    """Inclusive first missing candle open and exclusive range end."""

    model_config = ConfigDict(frozen=True, extra="forbid")

    first_missing_open_at: datetime
    end_at: datetime
    timeframe: Timeframe
    missing_bar_count: int = Field(..., gt=0)

    @field_validator("first_missing_open_at", "end_at")
    @classmethod
    def _validate_utc(cls, value: datetime) -> datetime:
        return _require_utc(value)

    @model_validator(mode="after")
    def _validate_bounds(self) -> "MissingRange":
        expected_end = self.first_missing_open_at + (
            self.timeframe.duration * self.missing_bar_count
        )
        if self.end_at != expected_end:
            raise ValueError("missing range duration must match missing_bar_count")
        return self


class DatasetStreamQuality(BaseModel):
    """Coverage and defects for one canonical candle stream."""

    model_config = ConfigDict(frozen=True, extra="forbid")

    market_data_venue: str = Field(..., min_length=1)
    market_type: MarketType
    symbol: str = Field(..., min_length=1)
    timeframe: Timeframe
    first_open_at: datetime
    last_close_at: datetime
    expected_count: int = Field(..., ge=1)
    observed_count: int = Field(..., ge=1)
    missing_ranges: tuple[MissingRange, ...] = ()

    @field_validator("market_data_venue", "symbol")
    @classmethod
    def _validate_nonblank(cls, value: str) -> str:
        return _require_nonblank(value)

    @field_validator("first_open_at", "last_close_at")
    @classmethod
    def _validate_utc(cls, value: datetime) -> datetime:
        return _require_utc(value)

    @model_validator(mode="after")
    def _validate_coverage(self) -> "DatasetStreamQuality":
        if self.last_close_at <= self.first_open_at:
            raise ValueError("stream last_close_at must follow first_open_at")
        if any(item.timeframe is not self.timeframe for item in self.missing_ranges):
            raise ValueError("missing range timeframe must match stream timeframe")
        return self


class DatasetQualityReport(BaseModel):
    """Deterministically ordered, machine-readable dataset analysis."""

    model_config = ConfigDict(frozen=True, extra="forbid")

    schema_version: Literal["backtest-dataset-quality.v1"] = _QUALITY_SCHEMA_VERSION
    input_count: int = Field(..., ge=0)
    accepted_count: int = Field(..., ge=0)
    streams: tuple[DatasetStreamQuality, ...] = ()
    exact_duplicate_count: int = Field(..., ge=0)
    conflicting_duplicate_count: int = Field(..., ge=0)
    missing_ranges: tuple[MissingRange, ...] = ()
    quality_flags: tuple[str, ...] = ()

    @field_validator("quality_flags")
    @classmethod
    def _validate_flags(cls, value: tuple[str, ...]) -> tuple[str, ...]:
        if any(not item.strip() for item in value):
            raise ValueError("quality flags must not be blank")
        if len(set(value)) != len(value):
            raise ValueError("quality flags must be unique")
        return value

    @property
    def eligible(self) -> bool:
        return not self.quality_flags


class DatasetBuildResult(BaseModel):
    """Pure, eligible build output before canonical serialization."""

    model_config = ConfigDict(frozen=True, extra="forbid")

    source_identity: DatasetSourceIdentity
    records: tuple[CandleRecord, ...] = Field(..., min_length=1)
    quality_report: DatasetQualityReport
    symbols: tuple[str, ...] = Field(..., min_length=1)
    timeframes: tuple[Timeframe, ...] = Field(..., min_length=1)
    start_at: datetime
    end_at: datetime
    record_count: int = Field(..., ge=1)

    @field_validator("start_at", "end_at")
    @classmethod
    def _validate_utc(cls, value: datetime) -> datetime:
        return _require_utc(value)


class DatasetBuildRejected(Exception):
    """Stable rejection carrying diagnostics but never the raw records."""

    def __init__(self, reason_code: str, report: DatasetQualityReport) -> None:
        super().__init__(reason_code)
        self.reason_code = reason_code
        self.report = report
