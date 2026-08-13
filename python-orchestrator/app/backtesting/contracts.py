"""Versioned contracts for deterministic net backtests.

These models are the first #191 slice. They define the boundary between dataset
builders, effective config snapshots, future Backtrader adapters, execution
simulation, cost models and reports. No trading strategy is implemented here.
"""

from __future__ import annotations

import hashlib
import json
from collections.abc import Mapping as MappingAbc
from datetime import datetime, timedelta, timezone
from enum import Enum
from math import isclose
from typing import Any, Literal, Mapping

from pydantic import (
    BaseModel,
    ConfigDict,
    Field,
    field_validator,
    model_validator,
)

from app.modern_trading_contracts import (
    CanonicalEffectiveConfigSnapshot,
    ModeId,
    ModernTradingIdentity,
    PublishedVersion,
    SetupId,
)


_SHA256_PATTERN = r"^sha256:[0-9a-f]{64}$"
_GIT_SHA_PATTERN = r"^[0-9a-f]{40}$"


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


_TIMEFRAME_SECONDS = {
    "1m": 60,
    "5m": 300,
    "15m": 900,
    "1h": 3600,
    "4h": 14400,
}


def _canonical_hash(payload: Mapping[str, Any]) -> str:
    encoded = json.dumps(payload, sort_keys=True, separators=(",", ":"), default=str)
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


def _canonical_manifest_datetime(value: datetime) -> str:
    return _require_utc(value).isoformat(timespec="microseconds").replace(
        "+00:00", "Z"
    )


def _dataset_checksum_from_manifest_core(
    manifest_core: Mapping[str, Any],
    candles_checksum: str,
    quality_report_checksum: str,
) -> str:
    checksum_payload = json.dumps(
        {
            "candles_checksum": candles_checksum,
            "manifest_core": manifest_core,
            "quality_report_checksum": quality_report_checksum,
        },
        ensure_ascii=False,
        separators=(",", ":"),
        sort_keys=True,
    ).encode("utf-8")
    return "sha256:" + hashlib.sha256(checksum_payload).hexdigest()


class DatasetStreamCoverage(BaseModel):
    """Exact immutable coverage for one venue/market/symbol/timeframe stream."""

    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)

    market_data_venue: str = Field(..., min_length=1)
    market_type: MarketType
    symbol: str = Field(..., min_length=1)
    timeframe: Literal["1m", "5m", "15m", "1h", "4h"]
    first_open_at: datetime
    last_close_at: datetime
    record_count: int = Field(..., ge=1)

    @field_validator("first_open_at", "last_close_at")
    @classmethod
    def _validate_utc(cls, value: datetime) -> datetime:
        return _require_utc(value)

    @model_validator(mode="after")
    def _validate_bounds(self) -> "DatasetStreamCoverage":
        if self.last_close_at <= self.first_open_at:
            raise ValueError("stream last_close_at must follow first_open_at")
        duration_seconds = _TIMEFRAME_SECONDS[self.timeframe]
        duration = timedelta(seconds=duration_seconds)
        epoch = datetime(1970, 1, 1, tzinfo=timezone.utc)
        if (self.first_open_at - epoch) % duration != timedelta(0):
            raise ValueError("stream first_open_at must align to UTC timeframe grid")
        if (
            self.last_close_at - self.first_open_at
            != (self.record_count * duration)
        ):
            raise ValueError("stream duration must equal record_count times timeframe")
        return self


def _stream_coverage_sort_key(
    stream: DatasetStreamCoverage,
) -> tuple[str, str, str, int]:
    return (
        stream.market_data_venue,
        stream.market_type.value,
        stream.symbol,
        _TIMEFRAME_SECONDS[stream.timeframe],
    )


class DatasetDescriptor(BaseModel):
    """Immutable facts reconstructed from a canonical dataset manifest."""

    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)

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
    streams: tuple[DatasetStreamCoverage, ...] = Field(..., min_length=1)
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
        if self.streams != tuple(sorted(self.streams, key=_stream_coverage_sort_key)):
            raise ValueError("dataset streams must be unique and canonically sorted")
        stream_keys = tuple(_stream_coverage_sort_key(item) for item in self.streams)
        if len(set(stream_keys)) != len(stream_keys):
            raise ValueError("dataset streams must be unique and canonically sorted")
        if any(
            item.market_data_venue != self.market_data_venue
            or item.market_type is not self.market_type
            for item in self.streams
        ):
            raise ValueError("dataset stream source must match dataset source")
        if self.symbols != tuple(sorted(set(self.symbols))):
            raise ValueError("dataset symbols must be unique and sorted")
        if any(item not in _TIMEFRAME_SECONDS for item in self.timeframes):
            raise ValueError("dataset contains unsupported timeframe")
        if self.timeframes != tuple(
            sorted(set(self.timeframes), key=_TIMEFRAME_SECONDS.__getitem__)
        ):
            raise ValueError("dataset timeframes must be unique and duration-sorted")
        if self.symbols != tuple(sorted({item.symbol for item in self.streams})):
            raise ValueError("dataset symbols must derive from streams")
        if self.timeframes != tuple(
            sorted(
                {item.timeframe for item in self.streams},
                key=_TIMEFRAME_SECONDS.__getitem__,
            )
        ):
            raise ValueError("dataset timeframes must derive from streams")
        if self.start_at != min(item.first_open_at for item in self.streams):
            raise ValueError("dataset start_at must derive from streams")
        if self.end_at != max(item.last_close_at for item in self.streams):
            raise ValueError("dataset end_at must derive from streams")
        if self.record_count != sum(item.record_count for item in self.streams):
            raise ValueError("dataset record_count must derive from streams")
        checksum_hex = self.dataset_checksum.removeprefix("sha256:")
        if self.dataset_id != f"backtest-dataset-{checksum_hex}":
            raise ValueError("dataset_id must derive from dataset_checksum")
        manifest_core = {
            "build_version": self.build_version,
            "coverage": {
                "end_at": _canonical_manifest_datetime(self.end_at),
                "record_count": self.record_count,
                "start_at": _canonical_manifest_datetime(self.start_at),
                "streams": [
                    {
                        "first_open_at": _canonical_manifest_datetime(
                            item.first_open_at
                        ),
                        "last_close_at": _canonical_manifest_datetime(
                            item.last_close_at
                        ),
                        "market_data_venue": item.market_data_venue,
                        "market_type": item.market_type.value,
                        "record_count": item.record_count,
                        "symbol": item.symbol,
                        "timeframe": item.timeframe,
                    }
                    for item in self.streams
                ],
                "symbols": list(self.symbols),
                "timeframes": list(self.timeframes),
            },
            "quality_flags": list(self.quality_flags),
            "quality_report_schema_version": self.quality_report_schema_version,
            "record_schema_version": self.record_schema_version,
            "schema_version": self.schema_version,
            "source": {
                "market_data_venue": self.market_data_venue,
                "market_type": self.market_type.value,
                "source": self.source,
                "source_build_version": self.source_build_version,
                "source_checksum": self.source_checksum,
                "source_network": self.source_network,
                "source_schema_version": self.source_schema_version,
            },
        }
        expected_checksum = _dataset_checksum_from_manifest_core(
            manifest_core,
            self.candles_checksum,
            self.quality_report_checksum,
        )
        if self.dataset_checksum != expected_checksum:
            raise ValueError("dataset checksum does not bind descriptor facts")
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
            "streams",
            "symbols",
            "timeframes",
        }:
            raise ValueError("dataset manifest coverage is invalid")
        if not isinstance(artifacts, MappingAbc) or set(artifacts) != {
            "candles.ndjson",
            "quality-report.json",
        }:
            raise ValueError("dataset manifest artifacts are invalid")

        string_fields = (
            (manifest, "dataset_id"),
            (manifest, "schema_version"),
            (manifest, "record_schema_version"),
            (manifest, "quality_report_schema_version"),
            (manifest, "build_version"),
            (manifest, "dataset_checksum"),
            (source, "source"),
            (source, "source_schema_version"),
            (source, "source_build_version"),
            (source, "source_checksum"),
            (source, "source_network"),
            (source, "market_data_venue"),
            (source, "market_type"),
            (coverage, "start_at"),
            (coverage, "end_at"),
            (artifacts, "candles.ndjson"),
            (artifacts, "quality-report.json"),
        )
        if any(type(container[key]) is not str for container, key in string_fields):
            raise ValueError("dataset manifest string scalar is invalid")
        if type(coverage["record_count"]) is not int:
            raise ValueError("record_count must be an integer")
        for name in ("symbols", "timeframes"):
            value = coverage[name]
            if type(value) is not list or any(type(item) is not str for item in value):
                raise ValueError(f"{name} must be an array of strings")
        streams = coverage["streams"]
        expected_stream_fields = {
            "first_open_at",
            "last_close_at",
            "market_data_venue",
            "market_type",
            "record_count",
            "symbol",
            "timeframe",
        }
        if type(streams) is not list or not streams:
            raise ValueError("streams must be a non-empty array")
        for stream in streams:
            if type(stream) is not dict or set(stream) != expected_stream_fields:
                raise ValueError("dataset manifest stream is invalid")
            if any(
                type(stream[name]) is not str
                for name in expected_stream_fields - {"record_count"}
            ) or type(stream["record_count"]) is not int:
                raise ValueError("dataset manifest stream scalar is invalid")
        quality_flags = manifest["quality_flags"]
        if type(quality_flags) is not list or any(
            type(item) is not str for item in quality_flags
        ):
            raise ValueError("quality_flags must be an array of strings")

        manifest_core = {
            key: value
            for key, value in manifest.items()
            if key not in {"artifacts", "dataset_checksum", "dataset_id"}
        }
        expected_checksum = _dataset_checksum_from_manifest_core(
            manifest_core,
            artifacts["candles.ndjson"],
            artifacts["quality-report.json"],
        )
        if manifest["dataset_checksum"] != expected_checksum:
            raise ValueError("dataset checksum does not bind manifest facts")

        def parse_utc(value: str) -> datetime:
            if not value.endswith("Z"):
                raise ValueError("manifest datetime must use canonical UTC Z suffix")
            parsed = datetime.fromisoformat(value[:-1] + "+00:00")
            return _require_utc(parsed)

        try:
            market_type = MarketType(source["market_type"])
        except ValueError as exc:
            raise ValueError("dataset manifest market_type is invalid") from exc
        parsed_streams = tuple(
            DatasetStreamCoverage(
                market_data_venue=item["market_data_venue"],
                market_type=MarketType(item["market_type"]),
                symbol=item["symbol"],
                timeframe=item["timeframe"],
                first_open_at=parse_utc(item["first_open_at"]),
                last_close_at=parse_utc(item["last_close_at"]),
                record_count=item["record_count"],
            )
            for item in streams
        )

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
            market_type=market_type,
            streams=parsed_streams,
            symbols=tuple(coverage["symbols"]),
            timeframes=tuple(coverage["timeframes"]),
            start_at=parse_utc(coverage["start_at"]),
            end_at=parse_utc(coverage["end_at"]),
            record_count=coverage["record_count"],
            candles_checksum=artifacts["candles.ndjson"],
            quality_report_checksum=artifacts["quality-report.json"],
            quality_flags=tuple(quality_flags),
            dataset_checksum=manifest["dataset_checksum"],
        )


class BacktestRunRequest(BaseModel):
    """Input contract for a deterministic net backtest run."""

    model_config = ConfigDict(frozen=True, extra="forbid")

    dataset: DatasetDescriptor
    identity: ModernTradingIdentity
    config: CanonicalEffectiveConfigSnapshot
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

    @field_validator(
        "git_commit_sha", "engine_version", "cost_model_version", mode="before"
    )
    @classmethod
    def _reject_coerced_strings(cls, value: Any) -> Any:
        if type(value) is not str:
            raise ValueError("backtest_run_scalar_type_invalid")
        return value

    @field_validator("random_seed", mode="before")
    @classmethod
    def _reject_coerced_seed(cls, value: Any) -> Any:
        if type(value) is not int:
            raise ValueError("backtest_run_scalar_type_invalid")
        return value

    @field_validator("period_start", "period_end")
    @classmethod
    def _validate_utc(cls, value: datetime) -> datetime:
        return _require_utc(value)

    @model_validator(mode="after")
    def _validate_scope(self) -> "BacktestRunRequest":
        snapshot_identity = self.config.request.model_dump(
            exclude={"execution_capability"}
        )
        if self.identity.model_dump() != snapshot_identity:
            raise ValueError("backtest_identity_snapshot_mismatch")
        if not self.config.executable or self.config.blockers:
            raise ValueError("backtest_config_not_executable")
        if self.config.request.execution_capability != "backtest":
            raise ValueError("backtest_execution_capability_required")
        if not _tuple_subset(self.symbols, self.dataset.symbols):
            raise ValueError("symbols must be contained in dataset")
        if not _tuple_subset(self.timeframes, self.dataset.timeframes):
            raise ValueError("timeframes must be contained in dataset")
        if self.period_end <= self.period_start:
            raise ValueError("period_end must be after period_start")
        if self.period_start < self.dataset.start_at or self.period_end > self.dataset.end_at:
            raise ValueError("period must stay inside dataset bounds")
        streams_by_key = {
            (item.symbol, item.timeframe): item for item in self.dataset.streams
        }
        requested_streams: list[DatasetStreamCoverage] = []
        for symbol in self.symbols:
            for timeframe in self.timeframes:
                stream = streams_by_key.get((symbol, timeframe))
                if stream is None:
                    raise ValueError("requested symbol/timeframe stream is not in dataset")
                requested_streams.append(stream)
        if any(
            self.period_start < item.first_open_at
            or self.period_end > item.last_close_at
            for item in requested_streams
        ):
            raise ValueError("period must stay inside each requested stream bounds")
        return self

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

    model_config = ConfigDict(frozen=True, extra="forbid", allow_inf_nan=False)

    backtest_run_id: str = Field(..., min_length=1)
    dataset_id: str = Field(..., min_length=1)
    config_hash: str = Field(..., pattern=_SHA256_PATTERN)
    condition_catalog_hash: str = Field(..., pattern=_SHA256_PATTERN)
    snapshot_hash: str = Field(..., pattern=_SHA256_PATTERN)
    git_commit_sha: str = Field(..., pattern=_GIT_SHA_PATTERN)
    mode_id: ModeId
    mode_version: PublishedVersion
    setup_id: SetupId
    setup_version: PublishedVersion
    exchange: Literal["fake", "okx", "hyperliquid"]
    environment: Literal["local", "test", "demo", "testnet", "mainnet"]
    side: Literal["long", "short"]
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

    @field_validator(
        "backtest_run_id",
        "dataset_id",
        "git_commit_sha",
        "mode_id",
        "mode_version",
        "setup_id",
        "setup_version",
        "exchange",
        "environment",
        "side",
        "symbol",
        mode="before",
    )
    @classmethod
    def _reject_coerced_strings(cls, value: Any) -> Any:
        if type(value) is not str:
            raise ValueError("backtest_ledger_string_type_invalid")
        return value

    @field_validator(
        "entry_price",
        "entry_quantity",
        "initial_stop",
        "gross_pnl_usdt",
        "net_pnl_usdt",
        "pnl_r",
        "fee_usdt",
        "spread_cost_usdt",
        "slippage_cost_usdt",
        "funding_usdt",
        "borrow_cost_usdt",
        "liquidation_fee_usdt",
        mode="before",
    )
    @classmethod
    def _reject_coerced_numbers(cls, value: Any) -> Any:
        if value is None:
            return value
        if type(value) not in (int, float):
            raise ValueError("backtest_ledger_numeric_type_invalid")
        return value

    @field_validator(
        "config_hash", "condition_catalog_hash", "snapshot_hash", mode="before"
    )
    @classmethod
    def _reject_coerced_hashes(cls, value: Any) -> Any:
        if not isinstance(value, str):
            raise ValueError("backtest_ledger_hash_type_invalid")
        return value

    @field_validator("signal_at")
    @classmethod
    def _validate_utc(cls, value: datetime) -> datetime:
        return _require_utc(value)

    @model_validator(mode="after")
    def _validate_ledger(self) -> "BacktestTradeLedgerEntry":
        try:
            ModernTradingIdentity(
                mode_id=self.mode_id,
                mode_version=self.mode_version,
                setup_id=self.setup_id,
                setup_version=self.setup_version,
                exchange=self.exchange,
                environment=self.environment,
                side=self.side,
            )
        except ValueError as exc:
            raise ValueError("backtest_ledger_identity_invalid") from exc
        if self.direction.value != self.side:
            raise ValueError("backtest_ledger_direction_side_mismatch")
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

    @property
    def result_is_live_proof(self) -> bool:
        return False
