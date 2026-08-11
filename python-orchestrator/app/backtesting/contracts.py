"""Versioned contracts for deterministic net backtests.

These models are the first #191 slice. They define the boundary between dataset
builders, effective config snapshots, future Backtrader adapters, execution
simulation, cost models and reports. No trading strategy is implemented here.
"""

from __future__ import annotations

import hashlib
import json
from collections.abc import Mapping as MappingAbc
from datetime import datetime, timezone
from enum import Enum
from math import isclose, isfinite
from typing import Any, Iterator, Literal, Mapping

from pydantic import (
    BaseModel,
    ConfigDict,
    Field,
    computed_field,
    field_serializer,
    field_validator,
    model_validator,
)


_SHA256_PATTERN = r"^sha256:[0-9a-f]{64}$"
_GIT_SHA_PATTERN = r"^[0-9a-f]{40}$"
_JSON_SCALAR_TYPES = (str, int, float, bool, type(None))


class Profile(str, Enum):
    REGULAR = "regular"
    SCALPER = "scalper"
    SCALPER_MICRO = "scalper_micro"


class MarketType(str, Enum):
    PERPETUAL = "perpetual"
    SPOT = "spot"


class Direction(str, Enum):
    LONG = "long"
    SHORT = "short"


class OrderType(str, Enum):
    MAKER = "maker"
    TAKER = "taker"
    MARKET = "market"


class IntraBarPolicy(str, Enum):
    CONSERVATIVE_STOP_FIRST = "conservative_stop_first"
    PATH_FROM_LOWER_TIMEFRAME = "path_from_lower_timeframe"
    REJECT_AMBIGUOUS_TRADE = "reject_ambiguous_trade"


def _canonical_hash(payload: Mapping[str, Any]) -> str:
    encoded = json.dumps(_deep_thaw(payload), sort_keys=True, separators=(",", ":"), default=str)
    return "sha256:" + hashlib.sha256(encoded.encode("utf-8")).hexdigest()


def _tuple_subset(values: tuple[str, ...], allowed: tuple[str, ...]) -> bool:
    return set(values).issubset(set(allowed))


def _normalize_string_tuple(value: Any) -> tuple[str, ...]:
    if value is None:
        return ()
    if isinstance(value, str):
        raise ValueError("field must be a sequence of strings")
    if isinstance(value, MappingAbc):
        raise ValueError("field must be a sequence of strings")
    if isinstance(value, set | frozenset):
        raise ValueError("field must be an ordered sequence of strings")
    try:
        iterator = iter(value)
    except TypeError as exc:
        raise ValueError("field must be a sequence of strings") from exc

    normalized: list[str] = []
    for item in iterator:
        if not isinstance(item, str):
            raise ValueError("field must contain only strings")
        stripped = item.strip()
        if stripped:
            normalized.append(stripped)
    return tuple(normalized)


def _require_utc(value: datetime) -> datetime:
    if value.tzinfo is None or value.utcoffset() is None:
        raise ValueError("datetime must be UTC-aware")
    if value.utcoffset() != timezone.utc.utcoffset(value):
        raise ValueError("datetime must be UTC-aware")
    return value.astimezone(timezone.utc)


class FrozenDict(MappingAbc):
    """Recursive immutable mapping for config snapshots."""

    def __init__(self, value: Mapping[str, Any]) -> None:
        self._data = {str(key): _deep_freeze(item) for key, item in value.items()}

    def __getitem__(self, key: str) -> Any:
        return self._data[key]

    def __iter__(self) -> Iterator[str]:
        return iter(self._data)

    def __len__(self) -> int:
        return len(self._data)

    def __repr__(self) -> str:
        return repr(self._data)


def _deep_freeze(value: Any) -> Any:
    if isinstance(value, FrozenDict):
        return value
    if isinstance(value, MappingAbc):
        return FrozenDict(value)
    if isinstance(value, list | tuple):
        return tuple(_deep_freeze(item) for item in value)
    if isinstance(value, set | frozenset):
        raise ValueError("effective_config must contain JSON-compatible values")
    if isinstance(value, float):
        if not isfinite(value):
            raise ValueError("effective_config must contain finite floats")
        return value
    if isinstance(value, _JSON_SCALAR_TYPES):
        return value
    raise ValueError("effective_config must contain JSON-compatible values")


def _deep_thaw(value: Any) -> Any:
    if isinstance(value, FrozenDict):
        return {key: _deep_thaw(item) for key, item in value.items()}
    if isinstance(value, tuple):
        return [_deep_thaw(item) for item in value]
    if isinstance(value, list):
        return [_deep_thaw(item) for item in value]
    if isinstance(value, MappingAbc):
        return {str(key): _deep_thaw(item) for key, item in value.items()}
    return value


class DatasetDescriptor(BaseModel):
    """Immutable facts reconstructed from a canonical dataset manifest."""

    model_config = ConfigDict(frozen=True, extra="forbid")

    dataset_id: str = Field(..., pattern=r"^backtest-dataset-[0-9a-f]{64}$")
    schema_version: Literal["backtest-dataset-manifest.v1"]
    record_schema_version: Literal["backtest-candle.v1"]
    quality_report_schema_version: Literal["backtest-dataset-quality.v1"]
    build_version: str = Field(..., min_length=1)
    source: str = Field(..., min_length=1)
    source_schema_version: str = Field(..., min_length=1)
    source_build_version: str = Field(..., min_length=1)
    source_checksum: str = Field(..., pattern=_SHA256_PATTERN)
    source_network: str = Field(..., min_length=1)
    market_data_venue: str = Field(..., min_length=1)
    market_type: MarketType
    symbols: tuple[str, ...] = Field(..., min_length=1)
    timeframes: tuple[str, ...] = Field(..., min_length=1)
    start_at: datetime
    end_at: datetime
    record_count: int = Field(..., ge=1)
    candles_checksum: str = Field(..., pattern=_SHA256_PATTERN)
    quality_report_checksum: str = Field(..., pattern=_SHA256_PATTERN)
    quality_flags: tuple[str, ...] = ()
    dataset_checksum: str = Field(..., pattern=_SHA256_PATTERN)

    @field_validator("symbols", "timeframes", "quality_flags", mode="before")
    @classmethod
    def _normalize_tuple(cls, value: Any) -> tuple[str, ...]:
        return _normalize_string_tuple(value)

    @field_validator("start_at", "end_at")
    @classmethod
    def _validate_utc(cls, value: datetime) -> datetime:
        return _require_utc(value)

    @model_validator(mode="after")
    def _validate_bounds(self) -> "DatasetDescriptor":
        if self.end_at <= self.start_at:
            raise ValueError("dataset end_at must be after start_at")
        if self.symbols != tuple(sorted(set(self.symbols))):
            raise ValueError("dataset symbols must be unique and sorted")
        timeframe_order = {
            "1m": 60,
            "5m": 300,
            "15m": 900,
            "1h": 3600,
            "4h": 14400,
        }
        if any(item not in timeframe_order for item in self.timeframes):
            raise ValueError("dataset contains unsupported timeframe")
        if self.timeframes != tuple(
            sorted(set(self.timeframes), key=timeframe_order.__getitem__)
        ):
            raise ValueError("dataset timeframes must be unique and duration-sorted")
        checksum_hex = self.dataset_checksum.removeprefix("sha256:")
        if self.dataset_id != f"backtest-dataset-{checksum_hex}":
            raise ValueError("dataset_id must derive from dataset_checksum")
        return self

    @classmethod
    def from_manifest(cls, manifest: Mapping[str, Any]) -> "DatasetDescriptor":
        """Reconstruct the descriptor solely from exact manifest facts."""

        expected_top_level = {
            "artifacts",
            "build_version",
            "coverage",
            "dataset_checksum",
            "dataset_id",
            "quality_flags",
            "quality_report_schema_version",
            "record_schema_version",
            "schema_version",
            "source",
        }
        if set(manifest) != expected_top_level:
            raise ValueError("dataset manifest fields are invalid")
        source = manifest["source"]
        coverage = manifest["coverage"]
        artifacts = manifest["artifacts"]
        if not isinstance(source, MappingAbc) or set(source) != {
            "market_data_venue",
            "market_type",
            "source",
            "source_build_version",
            "source_checksum",
            "source_network",
            "source_schema_version",
        }:
            raise ValueError("dataset manifest source is invalid")
        if not isinstance(coverage, MappingAbc) or set(coverage) != {
            "end_at",
            "record_count",
            "start_at",
            "symbols",
            "timeframes",
        }:
            raise ValueError("dataset manifest coverage is invalid")
        if not isinstance(artifacts, MappingAbc) or set(artifacts) != {
            "candles.ndjson",
            "quality-report.json",
        }:
            raise ValueError("dataset manifest artifacts are invalid")

        return cls(
            dataset_id=manifest["dataset_id"],
            schema_version=manifest["schema_version"],
            record_schema_version=manifest["record_schema_version"],
            quality_report_schema_version=manifest["quality_report_schema_version"],
            build_version=manifest["build_version"],
            source=source["source"],
            source_schema_version=source["source_schema_version"],
            source_build_version=source["source_build_version"],
            source_checksum=source["source_checksum"],
            source_network=source["source_network"],
            market_data_venue=source["market_data_venue"],
            market_type=source["market_type"],
            symbols=coverage["symbols"],
            timeframes=coverage["timeframes"],
            start_at=coverage["start_at"],
            end_at=coverage["end_at"],
            record_count=coverage["record_count"],
            candles_checksum=artifacts["candles.ndjson"],
            quality_report_checksum=artifacts["quality-report.json"],
            quality_flags=manifest["quality_flags"],
            dataset_checksum=manifest["dataset_checksum"],
        )


class EffectiveConfigSnapshot(BaseModel):
    """Versioned effective config used by a backtest run."""

    model_config = ConfigDict(frozen=True, arbitrary_types_allowed=True)

    profile: Profile
    config_hash: str = Field(..., pattern=_SHA256_PATTERN)
    config_version: str = Field(..., min_length=1)
    source_layers: tuple[str, ...] = Field(..., min_length=1)
    effective_config: FrozenDict = Field(...)

    @field_validator("source_layers", mode="before")
    @classmethod
    def _normalize_layers(cls, value: Any) -> tuple[str, ...]:
        return _normalize_string_tuple(value)

    @field_validator("effective_config", mode="before")
    @classmethod
    def _freeze_config(cls, value: Any) -> FrozenDict:
        if not isinstance(value, MappingAbc):
            raise ValueError("effective_config must be a mapping")
        return FrozenDict(value)

    @field_serializer("effective_config")
    def _serialize_config(self, value: FrozenDict) -> dict[str, Any]:
        return _deep_thaw(value)

    @model_validator(mode="after")
    def _validate_config(self) -> "EffectiveConfigSnapshot":
        if not self.source_layers:
            raise ValueError("source_layers must not be empty")
        if not self.effective_config:
            raise ValueError("effective_config must not be empty")
        return self


class BacktestRunRequest(BaseModel):
    """Input contract for a deterministic net backtest run."""

    model_config = ConfigDict(frozen=True)

    dataset: DatasetDescriptor
    config: EffectiveConfigSnapshot
    profile: Profile
    symbols: tuple[str, ...] = Field(..., min_length=1)
    timeframes: tuple[str, ...] = Field(..., min_length=1)
    period_start: datetime
    period_end: datetime
    git_commit_sha: str = Field(..., pattern=_GIT_SHA_PATTERN)
    engine_version: str = Field(..., min_length=1)
    random_seed: int = Field(..., ge=0)
    cost_model_version: str = Field(..., min_length=1)
    intra_bar_policy: IntraBarPolicy = IntraBarPolicy.CONSERVATIVE_STOP_FIRST

    @field_validator("symbols", "timeframes", mode="before")
    @classmethod
    def _normalize_tuple(cls, value: Any) -> tuple[str, ...]:
        return _normalize_string_tuple(value)

    @field_validator("period_start", "period_end")
    @classmethod
    def _validate_utc(cls, value: datetime) -> datetime:
        return _require_utc(value)

    @model_validator(mode="after")
    def _validate_scope(self) -> "BacktestRunRequest":
        if self.config.profile is not self.profile:
            raise ValueError("config profile must match run profile")
        if not _tuple_subset(self.symbols, self.dataset.symbols):
            raise ValueError("symbols must be contained in dataset")
        if not _tuple_subset(self.timeframes, self.dataset.timeframes):
            raise ValueError("timeframes must be contained in dataset")
        if self.period_end <= self.period_start:
            raise ValueError("period_end must be after period_start")
        if self.period_start < self.dataset.start_at or self.period_end > self.dataset.end_at:
            raise ValueError("period must stay inside dataset bounds")
        return self

    @computed_field
    @property
    def result_is_live_proof(self) -> bool:
        return False

    def reproducibility_fingerprint(self) -> str:
        payload = self.model_dump(mode="json", exclude={"result_is_live_proof"})
        return _canonical_hash(payload)


class BacktestTradeLedgerEntry(BaseModel):
    """Output ledger row for one simulated executed trade.

    Signals that do not execute should be exported separately by later slices.
    This contract covers executed simulated trades and requires an immediate SL.
    """

    model_config = ConfigDict(frozen=True, allow_inf_nan=False)

    backtest_run_id: str = Field(..., min_length=1)
    dataset_id: str = Field(..., min_length=1)
    config_hash: str = Field(..., pattern=_SHA256_PATTERN)
    git_commit_sha: str = Field(..., pattern=_GIT_SHA_PATTERN)
    profile: Profile
    exchange: str = Field(..., min_length=1)
    market_type: MarketType
    symbol: str = Field(..., min_length=1)
    direction: Direction
    signal_at: datetime
    entry_order_type: OrderType
    entry_price: float = Field(..., gt=0)
    entry_quantity: float = Field(..., gt=0)
    initial_stop: float | None = Field(...)
    gross_pnl_usdt: float
    net_pnl_usdt: float
    pnl_r: float
    fee_usdt: float = Field(..., ge=0)
    spread_cost_usdt: float = Field(..., ge=0)
    slippage_cost_usdt: float = Field(..., ge=0)
    funding_usdt: float
    borrow_cost_usdt: float = Field(default=0.0, ge=0)
    liquidation_fee_usdt: float = Field(default=0.0, ge=0)
    quality_flags: tuple[str, ...] = ()

    @field_validator("quality_flags", mode="before")
    @classmethod
    def _normalize_flags(cls, value: Any) -> tuple[str, ...]:
        return _normalize_string_tuple(value)

    @field_validator("signal_at")
    @classmethod
    def _validate_utc(cls, value: datetime) -> datetime:
        return _require_utc(value)

    @model_validator(mode="after")
    def _validate_ledger(self) -> "BacktestTradeLedgerEntry":
        if self.initial_stop is None or self.initial_stop <= 0:
            raise ValueError("initial_stop is required and must be positive")
        if self.direction is Direction.LONG and self.initial_stop >= self.entry_price:
            raise ValueError("long initial_stop must be below entry_price")
        if self.direction is Direction.SHORT and self.initial_stop <= self.entry_price:
            raise ValueError("short initial_stop must be above entry_price")
        expected_net_pnl = self.gross_pnl_usdt - self.total_known_cost_usdt
        if not isclose(self.net_pnl_usdt, expected_net_pnl, rel_tol=1e-9, abs_tol=1e-9):
            raise ValueError("net_pnl_usdt must equal gross_pnl_usdt minus known costs")
        return self

    @computed_field
    @property
    def total_known_cost_usdt(self) -> float:
        return (
            self.fee_usdt
            + self.spread_cost_usdt
            + self.slippage_cost_usdt
            - self.funding_usdt
            + self.borrow_cost_usdt
            + self.liquidation_fee_usdt
        )

    @computed_field
    @property
    def result_is_live_proof(self) -> bool:
        return False
