"""Verified Paper dataset bridge to the canonical PHP indicator projector."""

from __future__ import annotations

import hashlib
import json
import math
import os
import re
import signal
import shutil
import subprocess
import threading
import time
from collections.abc import Mapping, Sequence
from dataclasses import dataclass
from datetime import datetime, timezone
from decimal import Decimal
from pathlib import Path
from types import MappingProxyType
from typing import Any, Literal

from pydantic import (
    BaseModel,
    ConfigDict,
    Field,
    ValidationInfo,
    field_serializer,
    field_validator,
    model_validator,
)

from app.backtesting.dataset import (
    CandleRecord,
    DatasetArtifacts,
    DatasetArtifactVerificationError,
    DatasetSerializer,
)
from app.modern_trading_contracts import FrozenJsonDict, _canonical_json, thaw_json


_SHA256_PATTERN = r"^sha256:[0-9a-f]{64}$"
_UTC_MICRO_PATTERN = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$")
_TIMEFRAMES = ("1m", "5m", "15m", "1h", "4h")
_NATIVE_TIMEFRAMES = ("1m", "5m", "15m", "1h")
_CERTIFIABLE_SYMBOLS = frozenset({"BTCUSDT", "ETHUSDT"})
_MAX_BYTES = 8 * 1024 * 1024
_CANDLE_KEYS = {
    "schema_version",
    "source_record_id",
    "source_network",
    "market_data_venue",
    "market_type",
    "symbol",
    "timeframe",
    "open_at",
    "close_at",
    "available_at",
    "open",
    "high",
    "low",
    "close",
    "volume",
    "complete",
}


class IndicatorBridgeError(RuntimeError):
    """Stable fail-closed bridge error without child-process diagnostics."""


def _require_exact_utc(value: Any, reason: str) -> str:
    if type(value) is not str or _UTC_MICRO_PATTERN.fullmatch(value) is None:
        raise ValueError(reason)
    try:
        datetime.fromisoformat(value.removesuffix("Z") + "+00:00")
    except ValueError as exc:
        raise ValueError(reason) from exc
    return value


def _parse_utc(value: str) -> datetime:
    return datetime.fromisoformat(value.removesuffix("Z") + "+00:00")


def _format_utc(value: datetime) -> str:
    return value.astimezone(timezone.utc).isoformat(timespec="microseconds").replace(
        "+00:00", "Z"
    )


def _canonical_hash(value: Mapping[str, Any] | BaseModel | list[Any]) -> str:
    payload = value.model_dump(mode="json") if isinstance(value, BaseModel) else value
    return "sha256:" + hashlib.sha256(_canonical_json(payload).encode()).hexdigest()


def _paper_hash(value: Mapping[str, Any] | list[Any]) -> str:
    return "sha256:" + hashlib.sha256(_canonical_json(value).encode()).hexdigest()


def _canonical_decimal(value: Decimal) -> str:
    rendered = format(value, "f")
    if "." in rendered:
        rendered = rendered.rstrip("0").rstrip(".")
    return rendered or "0"


def _derived_four_hour_records(source: Sequence[Mapping[str, Any]]) -> list[dict[str, Any]]:
    """Reproduce PHP's derived-window evidence only; no indicators are calculated."""

    derived: list[dict[str, Any]] = []
    for offset in range(0, 1000, 4):
        components = source[offset : offset + 4]
        record = {
            "schema_version": "canonical-derived-indicator-candle.v1",
            "component_source_record_ids": [
                item["source_record_id"] for item in components
            ],
            "source_network": components[0]["source_network"],
            "market_data_venue": components[0]["market_data_venue"],
            "market_type": components[0]["market_type"],
            "symbol": components[0]["symbol"],
            "timeframe": "4h",
            "open_at": components[0]["open_at"],
            "close_at": components[3]["close_at"],
            "available_at": max(item["available_at"] for item in components),
            "open": components[0]["open"],
            "high": _canonical_decimal(
                max(Decimal(item["high"]) for item in components)
            ),
            "low": _canonical_decimal(
                min(Decimal(item["low"]) for item in components)
            ),
            "close": components[3]["close"],
            "volume": _canonical_decimal(
                sum(Decimal(item["volume"]) for item in components)
            ),
            "complete": True,
            "origin": "aggregate_1h_utc",
        }
        derived.append(
            {
                "derived_record_id": _paper_hash(record).removeprefix("sha256:"),
                **record,
            }
        )
    return derived


def _expected_window_hash(
    request: "CanonicalIndicatorProjectionRequest", timeframe: str
) -> str:
    windows = request.candles_by_timeframe
    if timeframe == "4h":
        hourly = [thaw_json(item) for item in windows["1h"]]
        return _paper_hash(_derived_four_hour_records(hourly))
    native = windows[timeframe]
    if timeframe == "1h" and "4h" in request.requested_timeframes:
        native = native[-250:]
    return _paper_hash([thaw_json(item) for item in native])


class CanonicalIndicatorDatasetBinding(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)

    dataset_id: str = Field(pattern=r"^backtest-dataset-[0-9a-f]{64}$")
    dataset_checksum: str = Field(pattern=_SHA256_PATTERN)
    candles_checksum: str = Field(pattern=_SHA256_PATTERN)
    quality_report_checksum: str = Field(pattern=_SHA256_PATTERN)
    source_checksum: str = Field(pattern=_SHA256_PATTERN)
    source_network: Literal["mainnet", "testnet"]
    market_data_venue: Literal["okx", "hyperliquid"]
    market_type: Literal["perpetual"]

    @field_validator("*", mode="before")
    @classmethod
    def _reject_string_coercion(cls, value: Any) -> Any:
        if type(value) is not str:
            raise ValueError("canonical_indicator_dataset_binding_invalid")
        return value

    @model_validator(mode="after")
    def _bind_id_to_checksum(self) -> "CanonicalIndicatorDatasetBinding":
        if self.dataset_id != "backtest-dataset-" + self.dataset_checksum.removeprefix(
            "sha256:"
        ):
            raise ValueError("canonical_indicator_dataset_binding_invalid")
        return self


class CanonicalIndicatorProjectionRequest(BaseModel):
    model_config = ConfigDict(
        frozen=True, extra="forbid", strict=True, arbitrary_types_allowed=True
    )

    schema_version: Literal["canonical-indicator-projection-request.v1"]
    request_id: str = Field(pattern=r"^[A-Za-z0-9][A-Za-z0-9._:-]{0,95}$")
    evaluated_at: str
    environment: Literal["local", "test"]
    indicator_engine_version: Literal["php_fallback_v1"]
    dataset_binding: CanonicalIndicatorDatasetBinding
    symbol: Literal["BTCUSDT", "ETHUSDT"]
    requested_timeframes: tuple[Literal["1m", "5m", "15m", "1h", "4h"], ...]
    candles_by_timeframe: FrozenJsonDict

    @field_validator(
        "schema_version",
        "request_id",
        "environment",
        "indicator_engine_version",
        "symbol",
        mode="before",
    )
    @classmethod
    def _reject_string_coercion(cls, value: Any) -> Any:
        if type(value) is not str:
            raise ValueError("canonical_indicator_string_type_invalid")
        return value

    @field_validator("evaluated_at", mode="before")
    @classmethod
    def _validate_evaluated_at(cls, value: Any) -> str:
        return _require_exact_utc(value, "canonical_indicator_evaluated_at_invalid")

    @field_validator("requested_timeframes", mode="before")
    @classmethod
    def _validate_requested_timeframes(cls, value: Any) -> tuple[str, ...]:
        if not isinstance(value, (tuple, list)) or not value:
            raise ValueError("canonical_indicator_requested_timeframes_invalid")
        normalized = tuple(value)
        if any(type(item) is not str for item in normalized):
            raise ValueError("canonical_indicator_requested_timeframes_invalid")
        expected = tuple(item for item in _TIMEFRAMES if item in normalized)
        if normalized != expected:
            raise ValueError("canonical_indicator_requested_timeframes_invalid")
        return normalized

    @field_validator("candles_by_timeframe", mode="before")
    @classmethod
    def _freeze_candles(cls, value: Any) -> FrozenJsonDict:
        if not isinstance(value, Mapping) or not value:
            raise ValueError("canonical_indicator_candles_shape_invalid")
        return FrozenJsonDict(value)

    @field_serializer("candles_by_timeframe")
    def _serialize_candles(self, value: FrozenJsonDict) -> dict[str, Any]:
        return thaw_json(value)

    @model_validator(mode="after")
    def _validate_candle_keys(self) -> "CanonicalIndicatorProjectionRequest":
        expected = tuple(
            timeframe
            for timeframe in _NATIVE_TIMEFRAMES
            if timeframe in self.requested_timeframes
            or (timeframe == "1h" and "4h" in self.requested_timeframes)
        )
        if tuple(self.candles_by_timeframe) != expected:
            raise ValueError("canonical_indicator_candles_shape_invalid")
        evaluated = _parse_utc(self.evaluated_at)
        for timeframe in expected:
            raw_window = self.candles_by_timeframe[timeframe]
            required = (
                1000
                if timeframe == "1h" and "4h" in self.requested_timeframes
                else 250
            )
            if not isinstance(raw_window, tuple) or len(raw_window) != required:
                raise ValueError("canonical_indicator_window_count_invalid")
            parsed: list[CandleRecord] = []
            for raw_record in raw_window:
                if not isinstance(raw_record, Mapping):
                    raise ValueError("canonical_indicator_candle_shape_invalid")
                payload = thaw_json(raw_record)
                if set(payload) != _CANDLE_KEYS:
                    raise ValueError("canonical_indicator_candle_shape_invalid")
                if (
                    type(payload["source_record_id"]) is not str
                    or re.fullmatch(r"[0-9a-f]{64}", payload["source_record_id"])
                    is None
                ):
                    raise ValueError("canonical_indicator_candle_identity_invalid")
                for field in ("open_at", "close_at", "available_at"):
                    _require_exact_utc(
                        payload[field], "canonical_indicator_candle_time_invalid"
                    )
                try:
                    record = CandleRecord.model_validate_json(
                        json.dumps(
                            payload,
                            ensure_ascii=False,
                            separators=(",", ":"),
                            sort_keys=True,
                        )
                    )
                except ValueError as exc:
                    raise ValueError("canonical_indicator_candle_invalid") from exc
                if (
                    record.source_network != self.dataset_binding.source_network
                    or record.market_data_venue
                    != self.dataset_binding.market_data_venue
                    or record.market_type.value != self.dataset_binding.market_type
                    or record.symbol != self.symbol
                    or record.timeframe.value != timeframe
                ):
                    raise ValueError("canonical_indicator_candle_identity_invalid")
                if record.close_at > evaluated or record.available_at > evaluated:
                    raise ValueError("canonical_indicator_candle_future_invalid")
                parsed.append(record)
            if any(
                current.open_at != previous.close_at
                for previous, current in zip(parsed, parsed[1:])
            ):
                raise ValueError("canonical_indicator_window_chronology_invalid")
            if timeframe == "1h" and "4h" in self.requested_timeframes:
                if parsed[0].open_at.hour % 4:
                    raise ValueError("canonical_indicator_four_hour_alignment_invalid")
        return self

    def input_hash(self) -> str:
        return _canonical_hash(self)


class CanonicalProjectedIndicatorSnapshot(BaseModel):
    model_config = ConfigDict(
        frozen=True, extra="forbid", strict=True, arbitrary_types_allowed=True
    )

    close: int | float
    high_series: tuple[int | float, ...]
    low_series: tuple[int | float, ...]
    rsi: int | float
    ema_20: int | float
    ema_50: int | float
    ema_200: int | float
    macd_hist: int | float
    vwap: int | float
    atr: int | float
    adx: FrozenJsonDict
    ma9: int | float
    ma21: int | float
    bb_upper: int | float
    bb_middle: int | float
    bb_lower: int | float
    ema: FrozenJsonDict
    ema_prev: FrozenJsonDict
    ema_200_slope: int | float
    ema_200_series: tuple[int | float, ...]
    ema_200_series_timestamps: tuple[int, ...]
    macd: FrozenJsonDict
    macd_hist_series: tuple[int | float, ...]
    macd_hist_series_timestamps: tuple[int, ...]
    macd_line_signal_series: tuple[int | float, ...]
    macd_line_signal_series_timestamps: tuple[int, ...]
    macd_hist_last3: tuple[int | float, ...]
    series_order: Literal["oldest_to_newest"]
    series_timestamps: tuple[int, ...]
    pullback_age_bars: int | None
    volume_ratio: int | float | None
    ma_21_plus_k_atr: int | float
    snapshot_identity: FrozenJsonDict
    kline_time: str
    window_hash: str = Field(pattern=_SHA256_PATTERN)
    indicator_engine_version: Literal["php_fallback_v1"]

    @field_validator(
        "close",
        "rsi",
        "ema_20",
        "ema_50",
        "ema_200",
        "macd_hist",
        "vwap",
        "atr",
        "ma9",
        "ma21",
        "bb_upper",
        "bb_middle",
        "bb_lower",
        "ema_200_slope",
        "ma_21_plus_k_atr",
        mode="before",
    )
    @classmethod
    def _validate_finite_number(cls, value: Any) -> int | float:
        if type(value) not in (int, float) or not math.isfinite(value):
            raise ValueError("canonical_indicator_snapshot_number_invalid")
        return value

    @field_validator("volume_ratio", mode="before")
    @classmethod
    def _validate_optional_finite_number(cls, value: Any) -> int | float | None:
        if value is None:
            return None
        return cls._validate_finite_number(value)

    @field_validator("pullback_age_bars", mode="before")
    @classmethod
    def _validate_pullback_age(cls, value: Any) -> int | None:
        if value is not None and type(value) is not int:
            raise ValueError("canonical_indicator_snapshot_pullback_age_invalid")
        return value

    @field_validator(
        "high_series",
        "low_series",
        "ema_200_series",
        "macd_hist_series",
        "macd_line_signal_series",
        "macd_hist_last3",
        mode="before",
    )
    @classmethod
    def _validate_finite_series(cls, value: Any) -> tuple[int | float, ...]:
        if not isinstance(value, (list, tuple)) or any(
            type(item) not in (int, float) or not math.isfinite(item)
            for item in value
        ):
            raise ValueError("canonical_indicator_snapshot_series_invalid")
        return tuple(value)

    @field_validator(
        "ema_200_series_timestamps",
        "macd_hist_series_timestamps",
        "macd_line_signal_series_timestamps",
        "series_timestamps",
        mode="before",
    )
    @classmethod
    def _validate_timestamp_series(cls, value: Any) -> tuple[int, ...]:
        if not isinstance(value, (list, tuple)) or any(
            type(item) is not int or item < 0 for item in value
        ):
            raise ValueError("canonical_indicator_snapshot_timestamps_invalid")
        return tuple(value)

    @field_validator("adx", mode="before")
    @classmethod
    def _validate_adx(cls, value: Any) -> FrozenJsonDict:
        return cls._validate_numeric_map(value, {"14", "15"})

    @field_validator("ema", "ema_prev", mode="before")
    @classmethod
    def _validate_ema_map(cls, value: Any) -> FrozenJsonDict:
        return cls._validate_numeric_map(value, {"9", "20", "21", "50", "200"})

    @field_validator("macd", mode="before")
    @classmethod
    def _validate_macd_map(cls, value: Any) -> FrozenJsonDict:
        return cls._validate_numeric_map(value, {"macd", "signal", "hist"})

    @staticmethod
    def _validate_numeric_map(value: Any, expected: set[str]) -> FrozenJsonDict:
        if (
            not isinstance(value, Mapping)
            or set(value) != expected
            or any(
                type(item) not in (int, float) or not math.isfinite(item)
                for item in value.values()
            )
        ):
            raise ValueError("canonical_indicator_snapshot_numeric_map_invalid")
        return FrozenJsonDict(value)

    @field_validator("snapshot_identity", mode="before")
    @classmethod
    def _validate_identity(cls, value: Any) -> FrozenJsonDict:
        if not isinstance(value, Mapping):
            raise ValueError("canonical_indicator_snapshot_identity_invalid")
        expected = {"timeframe", "symbol", "exchange", "environment", "market_type"}
        if set(value) != expected or any(type(item) is not str for item in value.values()):
            raise ValueError("canonical_indicator_snapshot_identity_invalid")
        if (
            value["timeframe"] not in _TIMEFRAMES
            or value["symbol"] not in _CERTIFIABLE_SYMBOLS
            or value["exchange"] != "fake"
            or value["environment"] not in {"local", "test"}
            or value["market_type"] != "perpetual"
        ):
            raise ValueError("canonical_indicator_snapshot_identity_invalid")
        return FrozenJsonDict(value)

    @field_serializer("snapshot_identity")
    def _serialize_identity(self, value: FrozenJsonDict) -> dict[str, Any]:
        return thaw_json(value)

    @field_serializer("adx", "ema", "ema_prev", "macd")
    def _serialize_numeric_map(self, value: FrozenJsonDict) -> dict[str, Any]:
        return thaw_json(value)

    @field_validator("kline_time", mode="before")
    @classmethod
    def _validate_kline_time(cls, value: Any) -> str:
        return _require_exact_utc(value, "canonical_indicator_kline_time_invalid")

    @field_validator("indicator_engine_version", "window_hash", mode="before")
    @classmethod
    def _reject_string_coercion(cls, value: Any) -> Any:
        if type(value) is not str:
            raise ValueError("canonical_indicator_snapshot_string_type_invalid")
        return value

    @model_validator(mode="before")
    @classmethod
    def _reject_ambiguous_json(cls, value: Any) -> Any:
        if isinstance(value, Mapping):
            _canonical_json(value)
        return value

    @model_validator(mode="after")
    def _validate_php_series_alignment(self) -> "CanonicalProjectedIndicatorSnapshot":
        timeframe = self.snapshot_identity["timeframe"]
        duration = {
            "1m": 60,
            "5m": 300,
            "15m": 900,
            "1h": 3_600,
            "4h": 14_400,
        }[timeframe]
        observed = _parse_utc(self.kline_time).timestamp()
        if not observed.is_integer():
            raise ValueError("canonical_indicator_snapshot_series_alignment_invalid")
        if (
            len(self.series_timestamps) != 250
            or any(
                current - previous != duration
                for previous, current in zip(
                    self.series_timestamps, self.series_timestamps[1:]
                )
            )
            or self.series_timestamps[-1] != int(observed)
            or len(self.high_series) != 60
            or len(self.low_series) != 60
            or len(self.ema_200_series) != 2
            or self.ema_200_series_timestamps != self.series_timestamps[-2:]
            or len(self.macd_hist_series) != 60
            or self.macd_hist_series_timestamps != self.series_timestamps[-60:]
            or self.macd_line_signal_series != self.macd_hist_series
            or self.macd_line_signal_series_timestamps
            != self.macd_hist_series_timestamps
            or self.macd_hist_last3 != self.macd_hist_series[-3:]
        ):
            raise ValueError("canonical_indicator_snapshot_series_alignment_invalid")

        ema = thaw_json(self.ema)
        ema_prev = thaw_json(self.ema_prev)
        macd = thaw_json(self.macd)
        if (
            self.ema_20 != ema["20"]
            or self.ema_50 != ema["50"]
            or self.ema_200 != ema["200"]
            or self.ema_200_series != (ema_prev["200"], ema["200"])
            or self.ema_200_slope != ema["200"] - ema_prev["200"]
            or self.macd_hist != macd["hist"]
            or self.macd_hist != self.macd_hist_series[-1]
        ):
            raise ValueError("canonical_indicator_snapshot_value_alignment_invalid")
        return self


class CanonicalIndicatorProjectionResult(BaseModel):
    model_config = ConfigDict(
        frozen=True, extra="forbid", strict=True, arbitrary_types_allowed=True
    )

    schema_version: Literal["canonical-indicator-projection-result.v1"]
    request_id: str = Field(pattern=r"^[A-Za-z0-9][A-Za-z0-9._:-]{0,95}$")
    evaluated_at: str
    environment: Literal["local", "test"]
    indicator_engine_version: Literal["php_fallback_v1"]
    dataset_binding: CanonicalIndicatorDatasetBinding
    symbol: Literal["BTCUSDT", "ETHUSDT"]
    requested_timeframes: tuple[Literal["1m", "5m", "15m", "1h", "4h"], ...]
    snapshots_by_timeframe: FrozenJsonDict
    input_hash: str = Field(pattern=_SHA256_PATTERN)
    result_hash: str = Field(pattern=_SHA256_PATTERN)

    @field_validator(
        "schema_version",
        "request_id",
        "environment",
        "indicator_engine_version",
        "symbol",
        "input_hash",
        "result_hash",
        mode="before",
    )
    @classmethod
    def _reject_string_coercion(cls, value: Any) -> Any:
        if type(value) is not str:
            raise ValueError("canonical_indicator_result_string_type_invalid")
        return value

    @field_validator("evaluated_at", mode="before")
    @classmethod
    def _validate_evaluated_at(cls, value: Any) -> str:
        return _require_exact_utc(value, "canonical_indicator_evaluated_at_invalid")

    @field_validator("requested_timeframes", mode="before")
    @classmethod
    def _validate_requested_timeframes(cls, value: Any) -> tuple[str, ...]:
        return CanonicalIndicatorProjectionRequest._validate_requested_timeframes(value)

    @field_validator("snapshots_by_timeframe", mode="before")
    @classmethod
    def _validate_snapshots(cls, value: Any, info: ValidationInfo) -> FrozenJsonDict:
        if not isinstance(value, Mapping) or not value:
            raise ValueError("canonical_indicator_snapshots_shape_invalid")
        requested = info.data.get("requested_timeframes")
        if not isinstance(requested, tuple) or set(value) != set(requested):
            raise ValueError("canonical_indicator_snapshots_shape_invalid")
        normalized = {
            timeframe: CanonicalProjectedIndicatorSnapshot.model_validate(
                value[timeframe]
            ).model_dump(mode="json")
            for timeframe in requested
        }
        return FrozenJsonDict(normalized)

    @field_serializer("snapshots_by_timeframe")
    def _serialize_snapshots(self, value: FrozenJsonDict) -> dict[str, Any]:
        return thaw_json(value)

    @model_validator(mode="after")
    def _validate_snapshots_and_hash(self) -> "CanonicalIndicatorProjectionResult":
        for timeframe, snapshot in self.snapshots_by_timeframe.items():
            identity = thaw_json(snapshot["snapshot_identity"])
            if identity != {
                "timeframe": timeframe,
                "symbol": self.symbol,
                "exchange": "fake",
                "environment": self.environment,
                "market_type": "perpetual",
            }:
                raise ValueError("canonical_indicator_result_identity_mismatch")
            if snapshot["indicator_engine_version"] != self.indicator_engine_version:
                raise ValueError("canonical_indicator_result_identity_mismatch")
        payload = self.model_dump(mode="json", exclude={"result_hash"})
        if _canonical_hash(payload) != self.result_hash:
            raise ValueError("canonical_indicator_result_hash_mismatch")
        return self


class VerifiedIndicatorWindowBuilder:
    """Verify complete artifacts, then select exact admissible native suffixes."""

    def build(
        self,
        artifacts: DatasetArtifacts,
        *,
        request_id: str,
        symbol: str,
        requested_timeframes: Sequence[str],
        evaluated_at: str,
        environment: str,
    ) -> CanonicalIndicatorProjectionRequest:
        if not isinstance(artifacts, DatasetArtifacts):
            raise TypeError("indicator_bridge_dataset_artifacts_required")
        try:
            descriptor = DatasetSerializer.verify(artifacts)
        except DatasetArtifactVerificationError as exc:
            raise IndicatorBridgeError("indicator_bridge_dataset_invalid") from exc

        # Validate request primitives before materializing any artifact records.
        evaluated = _parse_utc(
            _require_exact_utc(evaluated_at, "canonical_indicator_evaluated_at_invalid")
        )
        if symbol not in _CERTIFIABLE_SYMBOLS:
            raise IndicatorBridgeError("indicator_bridge_symbol_invalid")

        records = tuple(
            CandleRecord.model_validate_json(line)
            for line in artifacts.candles_ndjson.removesuffix(b"\n").split(b"\n")
        )
        requested = tuple(requested_timeframes)
        source_timeframes = tuple(
            timeframe
            for timeframe in _NATIVE_TIMEFRAMES
            if timeframe in requested or (timeframe == "1h" and "4h" in requested)
        )
        candles: dict[str, list[dict[str, Any]]] = {}
        for timeframe in source_timeframes:
            required = 1000 if timeframe == "1h" and "4h" in requested else 250
            candidates = [
                record
                for record in records
                if record.symbol == symbol
                and record.timeframe.value == timeframe
                and record.close_at <= evaluated
                and record.available_at <= evaluated
            ]
            if len(candidates) < required:
                raise IndicatorBridgeError("indicator_bridge_window_insufficient")
            window = candidates[-required:]
            duration = window[0].timeframe.duration
            if any(
                current.open_at != previous.close_at
                for previous, current in zip(window, window[1:])
            ) or window[-1].close_at - window[0].open_at != duration * required:
                raise IndicatorBridgeError("indicator_bridge_window_chronology_invalid")
            if timeframe == "1h" and "4h" in requested and window[0].open_at.hour % 4:
                raise IndicatorBridgeError("indicator_bridge_four_hour_alignment_invalid")
            candles[timeframe] = [
                self._canonical_record(record) for record in window
            ]

        return CanonicalIndicatorProjectionRequest(
            schema_version="canonical-indicator-projection-request.v1",
            request_id=request_id,
            evaluated_at=_format_utc(evaluated),
            environment=environment,
            indicator_engine_version="php_fallback_v1",
            dataset_binding=CanonicalIndicatorDatasetBinding(
                dataset_id=descriptor.dataset_id,
                dataset_checksum=descriptor.dataset_checksum,
                candles_checksum=descriptor.candles_checksum,
                quality_report_checksum=descriptor.quality_report_checksum,
                source_checksum=descriptor.source_checksum,
                source_network=descriptor.source_network,
                market_data_venue=descriptor.market_data_venue,
                market_type=descriptor.market_type.value,
            ),
            symbol=symbol,
            requested_timeframes=requested,
            candles_by_timeframe=candles,
        )

    @staticmethod
    def _canonical_record(record: CandleRecord) -> dict[str, Any]:
        # Reuse the serializer's public model contract; timestamps must use the
        # PHP protocol's exact microsecond representation.
        payload = record.model_dump(mode="json")
        for field in ("open_at", "close_at", "available_at"):
            payload[field] = _format_utc(getattr(record, field))
        return payload


@dataclass(frozen=True, slots=True, init=False)
class BacktestIndicatorBridge:
    DEFAULT_TIMEOUT_SECONDS = 15.0

    _argv: tuple[str, ...]
    _timeout: float
    _max_output: int
    _environment: Mapping[str, str]

    def __init__(
        self,
        argv: tuple[str, ...] | None = None,
        *,
        timeout_seconds: float = DEFAULT_TIMEOUT_SECONDS,
        max_output_bytes: int = _MAX_BYTES,
    ) -> None:
        if argv is None:
            repository = Path(__file__).resolve().parents[3]
            argv = (
                "php",
                str(repository / "trading-app" / "bin" / "console"),
                "app:backtest:indicators:project",
                "--no-interaction",
                "--no-ansi",
            )
        if not argv or any(type(item) is not str or not item for item in argv):
            raise ValueError("indicator_bridge_argv_invalid")
        executable = argv[0]
        if os.sep not in executable:
            resolved = shutil.which(executable)
            if resolved is not None:
                argv = (resolved, *argv[1:])
        if (
            type(timeout_seconds) not in (int, float)
            or not math.isfinite(timeout_seconds)
            or timeout_seconds <= 0
            or type(max_output_bytes) is not int
            or not 1 <= max_output_bytes <= _MAX_BYTES
        ):
            raise ValueError("indicator_bridge_bounds_invalid")
        object.__setattr__(self, "_argv", argv)
        object.__setattr__(self, "_timeout", float(timeout_seconds))
        object.__setattr__(self, "_max_output", max_output_bytes)
        object.__setattr__(
            self,
            "_environment",
            MappingProxyType(
                {
                    "LANG": "C.UTF-8",
                    "LC_ALL": "C.UTF-8",
                    "PATH": os.defpath,
                    # macOS otherwise injects a user-derived value at exec time.
                    "__CF_USER_TEXT_ENCODING": "0x0:0x0:0x0",
                }
            ),
        )

    @property
    def argv(self) -> tuple[str, ...]:
        return self._argv

    def project(
        self, request: CanonicalIndicatorProjectionRequest
    ) -> CanonicalIndicatorProjectionResult:
        if not isinstance(request, CanonicalIndicatorProjectionRequest):
            raise TypeError("canonical_indicator_projection_request_required")
        payload = _canonical_json(request.model_dump(mode="json")).encode()
        if len(payload) > _MAX_BYTES:
            raise IndicatorBridgeError("indicator_bridge_input_too_large")
        returncode, stdout, _stderr = self._run_bounded(payload)
        if returncode != 0:
            raise IndicatorBridgeError("indicator_bridge_process_failed")
        decoded = self._decode_result(stdout)
        try:
            result = CanonicalIndicatorProjectionResult.model_validate(decoded)
        except ValueError as exc:
            raise IndicatorBridgeError("indicator_bridge_result_invalid") from exc
        self._assert_request_binding(request, result)
        return result

    def _run_bounded(self, payload: bytes) -> tuple[int, bytes, bytes]:
        try:
            process = subprocess.Popen(
                list(self._argv),
                stdin=subprocess.PIPE,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                shell=False,
                env=dict(self._environment),
                start_new_session=True,
            )
        except (OSError, ValueError) as exc:
            raise IndicatorBridgeError("indicator_bridge_process_unavailable") from exc
        assert process.stdin is not None
        assert process.stdout is not None
        assert process.stderr is not None
        streams = (process.stdin, process.stdout, process.stderr)
        buffers = {"stdout": bytearray(), "stderr": bytearray()}
        overflow = threading.Event()
        failures: list[BaseException] = []

        def read_stream(name: str, stream: Any) -> None:
            try:
                while chunk := stream.read(65_536):
                    target = buffers[name]
                    if len(target) + len(chunk) > self._max_output:
                        overflow.set()
                        return
                    target.extend(chunk)
            except BaseException as exc:  # pragma: no cover - defensive OS boundary
                failures.append(exc)

        def write_input() -> None:
            try:
                process.stdin.write(payload)
                process.stdin.close()
            except BrokenPipeError:
                pass
            except BaseException as exc:  # pragma: no cover - defensive OS boundary
                failures.append(exc)

        threads = (
            threading.Thread(
                target=read_stream, args=("stdout", process.stdout), daemon=True
            ),
            threading.Thread(
                target=read_stream, args=("stderr", process.stderr), daemon=True
            ),
            threading.Thread(target=write_input, daemon=True),
        )
        for thread in threads:
            thread.start()
        deadline = time.monotonic() + self._timeout
        try:
            while process.poll() is None:
                if overflow.is_set():
                    raise IndicatorBridgeError("indicator_bridge_output_too_large")
                if time.monotonic() >= deadline:
                    raise IndicatorBridgeError("indicator_bridge_timeout")
                time.sleep(0.005)
            remaining = max(0.0, deadline - time.monotonic())
            for thread in threads:
                thread.join(remaining)
                remaining = max(0.0, deadline - time.monotonic())
            if overflow.is_set():
                raise IndicatorBridgeError("indicator_bridge_output_too_large")
            if any(thread.is_alive() for thread in threads):
                raise IndicatorBridgeError("indicator_bridge_timeout")
            if failures:
                raise IndicatorBridgeError("indicator_bridge_io_failed")
            return process.returncode, bytes(buffers["stdout"]), bytes(buffers["stderr"])
        except IndicatorBridgeError:
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except OSError:
                pass
            process.wait()
            for stream in streams:
                try:
                    stream.close()
                except OSError:
                    pass
            for thread in threads:
                thread.join(1.0)
            raise
        finally:
            if process.poll() is not None:
                process.wait()
                for stream in streams:
                    try:
                        stream.close()
                    except OSError:
                        pass

    def _decode_result(self, stdout: bytes) -> Mapping[str, Any]:
        if not stdout or len(stdout) > self._max_output:
            raise IndicatorBridgeError("indicator_bridge_result_invalid")
        try:
            decoded = json.loads(
                stdout.decode("utf-8"),
                object_pairs_hook=self._unique_object,
                parse_constant=lambda _value: (_ for _ in ()).throw(ValueError()),
            )
        except (UnicodeDecodeError, json.JSONDecodeError, ValueError) as exc:
            raise IndicatorBridgeError("indicator_bridge_result_invalid") from exc
        if not isinstance(decoded, Mapping):
            raise IndicatorBridgeError("indicator_bridge_result_invalid")
        return decoded

    @staticmethod
    def _unique_object(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            if key in result:
                raise ValueError("duplicate")
            result[key] = value
        return result

    @staticmethod
    def _assert_request_binding(
        request: CanonicalIndicatorProjectionRequest,
        result: CanonicalIndicatorProjectionResult,
    ) -> None:
        expected = {
            "request_id": request.request_id,
            "evaluated_at": request.evaluated_at,
            "environment": request.environment,
            "indicator_engine_version": request.indicator_engine_version,
            "dataset_binding": request.dataset_binding,
            "symbol": request.symbol,
            "requested_timeframes": request.requested_timeframes,
            "input_hash": request.input_hash(),
        }
        for field, value in expected.items():
            if getattr(result, field) != value:
                raise IndicatorBridgeError("indicator_bridge_result_identity_mismatch")

        windows = request.candles_by_timeframe
        for timeframe, snapshot in result.snapshots_by_timeframe.items():
            if timeframe == "4h":
                expected_kline = windows["1h"][-4]["open_at"]
            else:
                expected_kline = windows[timeframe][-1]["open_at"]
            if snapshot["kline_time"] != expected_kline:
                raise IndicatorBridgeError("indicator_bridge_result_identity_mismatch")
            if snapshot["window_hash"] != _expected_window_hash(request, timeframe):
                raise IndicatorBridgeError("indicator_bridge_window_hash_mismatch")


__all__ = (
    "BacktestIndicatorBridge",
    "CanonicalIndicatorDatasetBinding",
    "CanonicalIndicatorProjectionRequest",
    "CanonicalIndicatorProjectionResult",
    "CanonicalProjectedIndicatorSnapshot",
    "IndicatorBridgeError",
    "VerifiedIndicatorWindowBuilder",
)
