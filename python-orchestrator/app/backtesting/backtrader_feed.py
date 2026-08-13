"""Verified candle boundary for the deterministic Backtrader adapter."""

from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime, timezone
from decimal import Decimal

from app.backtesting.dataset import CandleRecord, DatasetArtifacts, DatasetSerializer


class BacktraderFeedError(ValueError):
    """Stable fail-closed feed error."""


@dataclass(frozen=True)
class VerifiedBacktraderBar:
    source_record_id: str
    open_at: datetime
    close_at: datetime
    available_at: datetime
    open: float
    high: float
    low: float
    close: float
    volume: float


@dataclass(frozen=True)
class VerifiedBacktraderFeedAdapter:
    bars: tuple[VerifiedBacktraderBar, ...]
    dataset_id: str
    dataset_checksum: str
    symbol: str
    timeframe: str
    source_network: str
    market_data_venue: str

    def __init__(
        self,
        artifacts: DatasetArtifacts,
        *,
        symbol: str,
        timeframe: str,
        period_start: datetime,
        period_end: datetime,
    ) -> None:
        try:
            descriptor = DatasetSerializer.verify(artifacts)
            records = tuple(
                CandleRecord.model_validate_json(line)
                for line in artifacts.candles_ndjson.rstrip(b"\n").split(b"\n")
            )
        except Exception as exc:
            raise BacktraderFeedError("backtrader_feed_artifacts_invalid") from exc
        records = tuple(
            record for record in records
            if record.symbol == symbol and record.timeframe.value == timeframe
        )
        if (
            not records
            or period_start.tzinfo is None
            or period_start.utcoffset() != timezone.utc.utcoffset(period_start)
            or period_end.tzinfo is None
            or period_end.utcoffset() != timezone.utc.utcoffset(period_end)
            or period_end <= period_start
        ):
            raise BacktraderFeedError("backtrader_feed_scope_invalid")
        first = records[0]
        identity = (
            first.source_network,
            first.market_data_venue,
            first.market_type,
            first.symbol,
            first.timeframe,
        )
        seen_ids: set[str] = set()
        previous: CandleRecord | None = None
        previous_available_at: datetime | None = None
        bars: list[VerifiedBacktraderBar] = []
        for record in records:
            if (
                not isinstance(record, CandleRecord)
                or (
                    record.source_network,
                    record.market_data_venue,
                    record.market_type,
                    record.symbol,
                    record.timeframe,
                ) != identity
                or record.source_record_id in seen_ids
                or record.open_at < period_start
                or record.available_at > period_end
                or (
                    previous_available_at is not None
                    and record.available_at <= previous_available_at
                )
                or (previous is not None and record.open_at != previous.close_at)
            ):
                raise BacktraderFeedError("backtrader_feed_stream_invalid")
            seen_ids.add(record.source_record_id)
            previous = record
            previous_available_at = record.available_at
            bars.append(
                VerifiedBacktraderBar(
                    source_record_id=record.source_record_id,
                    open_at=record.open_at,
                    close_at=record.close_at,
                    available_at=record.available_at,
                    open=float(Decimal(record.open)),
                    high=float(Decimal(record.high)),
                    low=float(Decimal(record.low)),
                    close=float(Decimal(record.close)),
                    volume=float(Decimal(record.volume)),
                )
            )
        object.__setattr__(self, "bars", tuple(bars))
        object.__setattr__(self, "dataset_id", descriptor.dataset_id)
        object.__setattr__(self, "dataset_checksum", descriptor.dataset_checksum)
        object.__setattr__(self, "symbol", first.symbol)
        object.__setattr__(self, "timeframe", first.timeframe.value)
        object.__setattr__(self, "source_network", first.source_network)
        object.__setattr__(self, "market_data_venue", first.market_data_venue)
