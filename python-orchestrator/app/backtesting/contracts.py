"""Versioned contracts for deterministic net backtests.

These models are the first #191 slice. They define the boundary between dataset
builders, effective config snapshots, future Backtrader adapters, execution
simulation, cost models and reports. No trading strategy is implemented here.
"""

from __future__ import annotations

import hashlib
import json
from datetime import datetime
from enum import Enum
from typing import Any, Mapping

from pydantic import BaseModel, ConfigDict, Field, computed_field, field_validator, model_validator


_SHA256_PATTERN = r"^sha256:[0-9a-f]{64}$"
_GIT_SHA_PATTERN = r"^[0-9a-f]{40}$"


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
    encoded = json.dumps(payload, sort_keys=True, separators=(",", ":"), default=str)
    return "sha256:" + hashlib.sha256(encoded.encode("utf-8")).hexdigest()


def _tuple_subset(values: tuple[str, ...], allowed: tuple[str, ...]) -> bool:
    return set(values).issubset(set(allowed))


class DatasetDescriptor(BaseModel):
    """Immutable descriptor for a versioned backtest dataset."""

    model_config = ConfigDict(frozen=True)

    dataset_id: str = Field(..., min_length=1)
    source: str = Field(..., min_length=1)
    exchange: str = Field(..., min_length=1)
    market_type: MarketType
    symbols: tuple[str, ...] = Field(..., min_length=1)
    timeframes: tuple[str, ...] = Field(..., min_length=1)
    start_at: datetime
    end_at: datetime
    missing_ranges: tuple[str, ...] = ()
    quality_flags: tuple[str, ...] = ()
    build_version: str = Field(..., min_length=1)
    checksum: str = Field(..., pattern=_SHA256_PATTERN)

    @field_validator("symbols", "timeframes", "missing_ranges", "quality_flags", mode="before")
    @classmethod
    def _normalize_tuple(cls, value: Any) -> tuple[str, ...]:
        if value is None:
            return ()
        return tuple(str(item).strip() for item in value if str(item).strip())

    @model_validator(mode="after")
    def _validate_bounds(self) -> "DatasetDescriptor":
        if self.end_at <= self.start_at:
            raise ValueError("dataset end_at must be after start_at")
        return self


class EffectiveConfigSnapshot(BaseModel):
    """Versioned effective config used by a backtest run."""

    model_config = ConfigDict(frozen=True)

    profile: Profile
    config_hash: str = Field(..., pattern=_SHA256_PATTERN)
    config_version: str = Field(..., min_length=1)
    source_layers: tuple[str, ...] = Field(..., min_length=1)
    effective_config: Mapping[str, Any] = Field(..., min_length=1)

    @field_validator("source_layers", mode="before")
    @classmethod
    def _normalize_layers(cls, value: Any) -> tuple[str, ...]:
        return tuple(str(item).strip() for item in value if str(item).strip())

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
        return tuple(str(item).strip() for item in value if str(item).strip())

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

    model_config = ConfigDict(frozen=True)

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
        if value is None:
            return ()
        return tuple(str(item).strip() for item in value if str(item).strip())

    @model_validator(mode="after")
    def _validate_stop(self) -> "BacktestTradeLedgerEntry":
        if self.initial_stop is None or self.initial_stop <= 0:
            raise ValueError("initial_stop is required and must be positive")
        if self.direction is Direction.LONG and self.initial_stop >= self.entry_price:
            raise ValueError("long initial_stop must be below entry_price")
        if self.direction is Direction.SHORT and self.initial_stop <= self.entry_price:
            raise ValueError("short initial_stop must be above entry_price")
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

