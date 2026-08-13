"""Normalized, deterministic candle dataset contracts for backtesting.

The boundary accepts only source-owned, already authenticated records.  It does
not read or verify raw Paper manifests and deliberately contains no strategy
identity.
"""

from __future__ import annotations

import hashlib
import json
import re
from collections import defaultdict
from collections.abc import Iterable, Mapping
from datetime import datetime, timedelta, timezone
from decimal import Decimal
from enum import Enum
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator

from app.backtesting.contracts import DatasetDescriptor, MarketType


_CANDLE_SCHEMA_VERSION = "backtest-candle.v1"
_QUALITY_SCHEMA_VERSION = "backtest-dataset-quality.v1"
_MANIFEST_SCHEMA_VERSION = "backtest-dataset-manifest.v1"
_DATASET_BUILD_VERSION = "backtest-dataset-builder.v1"
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


def _json_value(value: Any) -> Any:
    if isinstance(value, BaseModel):
        return _json_value(value.model_dump())
    if isinstance(value, datetime):
        normalized = _require_utc(value)
        return normalized.isoformat(timespec="microseconds").replace("+00:00", "Z")
    if isinstance(value, Enum):
        return value.value
    if isinstance(value, Mapping):
        return {str(key): _json_value(item) for key, item in value.items()}
    if isinstance(value, tuple | list):
        return [_json_value(item) for item in value]
    return value


def _canonical_json(value: Any) -> bytes:
    return json.dumps(
        _json_value(value),
        ensure_ascii=False,
        separators=(",", ":"),
        sort_keys=True,
    ).encode("utf-8")


def _sha256(payload: bytes) -> str:
    return "sha256:" + hashlib.sha256(payload).hexdigest()


class CandleRecord(BaseModel):
    """One immutable, normalized, complete candle."""

    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)

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
        epoch = datetime(1970, 1, 1, tzinfo=timezone.utc)
        if (self.open_at - epoch) % self.timeframe.duration != timedelta(0):
            raise ValueError("open_at must align to UTC timeframe grid")
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

    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)

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

    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)

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

    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)

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

    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)

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

    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)

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

    @model_validator(mode="after")
    def _validate_derived_facts(self) -> "DatasetBuildResult":
        if self.records != tuple(sorted(self.records, key=_record_sort_key)):
            raise ValueError("records must use canonical order")
        recomputed_report = DatasetBuilder(self.source_identity).analyze(self.records)
        if recomputed_report != self.quality_report:
            raise ValueError("quality report must match records and source identity")
        if not recomputed_report.eligible:
            raise ValueError("build result requires an eligible quality report")
        if self.record_count != len(self.records):
            raise ValueError("record_count must equal records length")
        if self.start_at != min(item.open_at for item in self.records):
            raise ValueError("start_at must equal the first record bound")
        if self.end_at != max(item.close_at for item in self.records):
            raise ValueError("end_at must equal the last record bound")
        if self.symbols != tuple(sorted({item.symbol for item in self.records})):
            raise ValueError("symbols must equal the canonical record symbols")
        expected_timeframes = tuple(
            sorted(
                {item.timeframe for item in self.records},
                key=lambda item: item.duration_seconds,
            )
        )
        if self.timeframes != expected_timeframes:
            raise ValueError("timeframes must equal the canonical record timeframes")
        return self


class DatasetBuildRejected(Exception):
    """Stable rejection carrying diagnostics but never the raw records."""

    def __init__(self, reason_code: str, report: DatasetQualityReport) -> None:
        super().__init__(reason_code)
        self.reason_code = reason_code
        self.report = report


_QUALITY_FLAG_ORDER = (
    "empty_input",
    "mixed_source_network",
    "mixed_market_data_venue",
    "mixed_market_type",
    "source_identity_mismatch",
    "exact_duplicate",
    "conflicting_duplicate",
    "missing_range",
    "stream_overlap",
    "invalid_stream_chronology",
)


def _stream_key(record: CandleRecord) -> tuple[str, str, str, int]:
    return (
        record.market_data_venue,
        record.market_type.value,
        record.symbol,
        record.timeframe.duration_seconds,
    )


def _record_sort_key(
    record: CandleRecord,
) -> tuple[str, str, str, int, datetime, str]:
    return (*_stream_key(record), record.open_at, record.source_record_id)


def _identity_key(
    record: CandleRecord,
) -> tuple[str, MarketType, str, Timeframe, datetime]:
    return (
        record.market_data_venue,
        record.market_type,
        record.symbol,
        record.timeframe,
        record.open_at,
    )


class DatasetBuilder:
    """Pure fail-closed quality analyzer and deterministic record builder."""

    def __init__(self, source_identity: DatasetSourceIdentity) -> None:
        self._source_identity = source_identity

    def analyze(self, records: Iterable[CandleRecord]) -> DatasetQualityReport:
        materialized = tuple(records)
        for record in materialized:
            if not isinstance(record, CandleRecord):
                raise TypeError("DatasetBuilder accepts only CandleRecord values")

        flags: set[str] = set()
        if not materialized:
            flags.add("empty_input")

        self._analyze_source_identity(materialized, flags)

        identities: dict[
            tuple[str, MarketType, str, Timeframe, datetime], list[CandleRecord]
        ] = defaultdict(list)
        stream_open_times: dict[
            tuple[str, str, str, int], set[datetime]
        ] = defaultdict(set)
        stream_examples: dict[tuple[str, str, str, int], CandleRecord] = {}
        for record in materialized:
            identities[_identity_key(record)].append(record)
            key = _stream_key(record)
            stream_open_times[key].add(record.open_at)
            stream_examples[key] = record

        exact_duplicate_count = 0
        conflicting_duplicate_count = 0
        for duplicates in identities.values():
            if len(duplicates) < 2:
                continue
            first = duplicates[0]
            if all(item == first for item in duplicates[1:]):
                exact_duplicate_count += len(duplicates) - 1
            else:
                conflicting_duplicate_count += len(duplicates) - 1
        if exact_duplicate_count:
            flags.add("exact_duplicate")
        if conflicting_duplicate_count:
            flags.add("conflicting_duplicate")

        streams: list[DatasetStreamQuality] = []
        all_missing_ranges: list[MissingRange] = []
        for key in sorted(stream_open_times):
            example = stream_examples[key]
            opens = sorted(stream_open_times[key])
            duration = example.timeframe.duration
            missing_ranges: list[MissingRange] = []
            for previous, current in zip(opens, opens[1:]):
                delta = current - previous
                if delta < duration:
                    flags.add("stream_overlap")
                    continue
                if delta == duration:
                    continue
                missing_duration = delta - duration
                if missing_duration % duration != timedelta(0):
                    flags.add("invalid_stream_chronology")
                    continue
                missing_bar_count = int(missing_duration / duration)
                missing_range = MissingRange(
                    first_missing_open_at=previous + duration,
                    end_at=current,
                    timeframe=example.timeframe,
                    missing_bar_count=missing_bar_count,
                )
                missing_ranges.append(missing_range)
                all_missing_ranges.append(missing_range)
                flags.add("missing_range")

            first_open_at = opens[0]
            last_close_at = opens[-1] + duration
            span = last_close_at - first_open_at
            expected_count = max(len(opens), int(span / duration))
            streams.append(
                DatasetStreamQuality(
                    market_data_venue=example.market_data_venue,
                    market_type=example.market_type,
                    symbol=example.symbol,
                    timeframe=example.timeframe,
                    first_open_at=first_open_at,
                    last_close_at=last_close_at,
                    expected_count=expected_count,
                    observed_count=len(opens),
                    missing_ranges=tuple(missing_ranges),
                )
            )

        ordered_flags = tuple(item for item in _QUALITY_FLAG_ORDER if item in flags)
        return DatasetQualityReport(
            input_count=len(materialized),
            accepted_count=len(materialized),
            streams=tuple(streams),
            exact_duplicate_count=exact_duplicate_count,
            conflicting_duplicate_count=conflicting_duplicate_count,
            missing_ranges=tuple(all_missing_ranges),
            quality_flags=ordered_flags,
        )

    def build(self, records: Iterable[CandleRecord]) -> DatasetBuildResult:
        materialized = tuple(records)
        report = self.analyze(materialized)
        if not report.eligible:
            raise DatasetBuildRejected("dataset_quality_rejected", report)

        ordered_records = tuple(sorted(materialized, key=_record_sort_key))
        symbols = tuple(sorted({record.symbol for record in ordered_records}))
        timeframes = tuple(
            sorted(
                {record.timeframe for record in ordered_records},
                key=lambda item: item.duration_seconds,
            )
        )
        return DatasetBuildResult(
            source_identity=self._source_identity,
            records=ordered_records,
            quality_report=report,
            symbols=symbols,
            timeframes=timeframes,
            start_at=min(record.open_at for record in ordered_records),
            end_at=max(record.close_at for record in ordered_records),
            record_count=len(ordered_records),
        )

    def _analyze_source_identity(
        self,
        records: tuple[CandleRecord, ...],
        flags: set[str],
    ) -> None:
        if not records:
            return

        dimensions: tuple[tuple[str, object, set[object]], ...] = (
            (
                "mixed_source_network",
                self._source_identity.source_network,
                {record.source_network for record in records},
            ),
            (
                "mixed_market_data_venue",
                self._source_identity.market_data_venue,
                {record.market_data_venue for record in records},
            ),
            (
                "mixed_market_type",
                self._source_identity.market_type,
                {record.market_type for record in records},
            ),
        )
        for mixed_flag, expected, observed in dimensions:
            if len(observed) > 1:
                flags.add(mixed_flag)
            if observed != {expected}:
                flags.add("source_identity_mismatch")


class DatasetArtifacts(BaseModel):
    """The three canonical artifact bytes plus their derived descriptor."""

    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)

    candles_ndjson: bytes = Field(..., min_length=1)
    quality_report_json: bytes = Field(..., min_length=1)
    manifest_json: bytes = Field(..., min_length=1)
    descriptor: DatasetDescriptor


class DatasetArtifactVerificationError(Exception):
    """Stable fail-closed rejection that never includes artifact contents."""

    reason_code = "dataset_artifact_verification_failed"

    def __init__(self) -> None:
        super().__init__(self.reason_code)


def _manifest_core(result: DatasetBuildResult) -> dict[str, Any]:
    return {
        "build_version": _DATASET_BUILD_VERSION,
        "coverage": {
            "end_at": result.end_at,
            "record_count": result.record_count,
            "start_at": result.start_at,
            "symbols": result.symbols,
            "timeframes": tuple(item.value for item in result.timeframes),
        },
        "quality_flags": result.quality_report.quality_flags,
        "quality_report_schema_version": result.quality_report.schema_version,
        "record_schema_version": _CANDLE_SCHEMA_VERSION,
        "schema_version": _MANIFEST_SCHEMA_VERSION,
        "source": result.source_identity,
    }


def _dataset_checksum(
    manifest_core: Mapping[str, Any],
    candles_checksum: str,
    quality_report_checksum: str,
) -> str:
    checksum_payload = _canonical_json(
        {
            "candles_checksum": candles_checksum,
            "manifest_core": manifest_core,
            "quality_report_checksum": quality_report_checksum,
        }
    )
    return _sha256(checksum_payload)


def _manifest(
    result: DatasetBuildResult,
    candles_checksum: str,
    quality_report_checksum: str,
) -> dict[str, Any]:
    core = _json_value(_manifest_core(result))
    dataset_checksum = _dataset_checksum(
        core,
        candles_checksum,
        quality_report_checksum,
    )
    return {
        **core,
        "artifacts": {
            "candles.ndjson": candles_checksum,
            "quality-report.json": quality_report_checksum,
        },
        "dataset_checksum": dataset_checksum,
        "dataset_id": "backtest-dataset-"
        + dataset_checksum.removeprefix("sha256:"),
    }


def _parse_canonical_json_file(payload: bytes) -> Any:
    if not payload.endswith(b"\n") or payload.endswith(b"\n\n"):
        raise ValueError("canonical JSON file must have one trailing newline")
    decoded = json.loads(payload[:-1].decode("utf-8"))
    if _canonical_json(decoded) + b"\n" != payload:
        raise ValueError("JSON file is not canonical")
    return decoded


class DatasetSerializer:
    """Serialize and cross-verify deterministic in-memory dataset artifacts."""

    @classmethod
    def serialize(cls, result: DatasetBuildResult) -> DatasetArtifacts:
        if not isinstance(result, DatasetBuildResult):
            raise TypeError("DatasetSerializer accepts only DatasetBuildResult")
        validated_result = DatasetBuildResult(**result.model_dump())
        candles_ndjson = b"".join(
            _canonical_json(record) + b"\n" for record in validated_result.records
        )
        quality_report_json = (
            _canonical_json(validated_result.quality_report) + b"\n"
        )
        manifest = _manifest(
            validated_result,
            _sha256(candles_ndjson),
            _sha256(quality_report_json),
        )
        manifest_json = _canonical_json(manifest) + b"\n"
        artifacts = DatasetArtifacts(
            candles_ndjson=candles_ndjson,
            quality_report_json=quality_report_json,
            manifest_json=manifest_json,
            descriptor=DatasetDescriptor.from_manifest(manifest),
        )
        cls.verify(artifacts)
        return artifacts

    @classmethod
    def verify(cls, artifacts: DatasetArtifacts) -> DatasetDescriptor:
        try:
            if not isinstance(artifacts, DatasetArtifacts):
                raise TypeError("DatasetSerializer verifies only DatasetArtifacts")

            manifest = _parse_canonical_json_file(artifacts.manifest_json)
            if not isinstance(manifest, dict):
                raise ValueError("dataset manifest must be an object")
            descriptor = DatasetDescriptor.from_manifest(manifest)
            if descriptor != artifacts.descriptor:
                raise ValueError("dataset descriptor does not match manifest")

            expected_candles_checksum = manifest["artifacts"]["candles.ndjson"]
            expected_report_checksum = manifest["artifacts"]["quality-report.json"]
            if _sha256(artifacts.candles_ndjson) != expected_candles_checksum:
                raise ValueError("candles checksum mismatch")
            if _sha256(artifacts.quality_report_json) != expected_report_checksum:
                raise ValueError("quality report checksum mismatch")

            report_payload = _parse_canonical_json_file(
                artifacts.quality_report_json
            )
            report = DatasetQualityReport.model_validate_json(
                _canonical_json(report_payload)
            )

            if not artifacts.candles_ndjson.endswith(b"\n") or (
                artifacts.candles_ndjson.endswith(b"\n\n")
            ):
                raise ValueError("candles file must have one trailing newline")
            lines = artifacts.candles_ndjson[:-1].split(b"\n")
            if not lines or any(not line for line in lines):
                raise ValueError("candles file must contain canonical records")
            records: list[CandleRecord] = []
            for line in lines:
                record = CandleRecord.model_validate_json(line)
                if _canonical_json(record) != line:
                    raise ValueError("candle record is not canonical")
                records.append(record)

            source = DatasetSourceIdentity.model_validate_json(
                _canonical_json(manifest["source"])
            )
            result = DatasetBuilder(source).build(records)
            canonical_candles = b"".join(
                _canonical_json(record) + b"\n" for record in result.records
            )
            if tuple(records) != result.records or artifacts.candles_ndjson != canonical_candles:
                raise ValueError("candles are not in canonical order")
            if result.quality_report != report:
                raise ValueError("quality report does not match candle records")

            expected_manifest = _manifest(
                result,
                expected_candles_checksum,
                expected_report_checksum,
            )
            if manifest != expected_manifest:
                raise ValueError("manifest facts or checksum graph mismatch")
            if _canonical_json(expected_manifest) + b"\n" != artifacts.manifest_json:
                raise ValueError("manifest bytes are not canonical")
            return descriptor
        except DatasetArtifactVerificationError:
            raise
        except Exception as exc:
            raise DatasetArtifactVerificationError() from exc
