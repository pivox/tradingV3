from datetime import datetime, timedelta, timezone
from decimal import Decimal

import pytest

from app.backtesting.backtrader_feed import BacktraderFeedError, VerifiedBacktraderFeedAdapter
from app.backtesting.contracts import MarketType
from app.backtesting.dataset import CandleRecord, DatasetBuilder, DatasetSerializer, DatasetSourceIdentity, Timeframe


UTC = timezone.utc


def _candle(index: int, *, delay: int = 0, symbol: str = "BTCUSDT") -> CandleRecord:
    opened = datetime(2026, 1, 1, tzinfo=UTC) + index * timedelta(minutes=5)
    return CandleRecord(
        source_record_id=f"record-{index}",
        source_network="mainnet",
        market_data_venue="okx",
        market_type=MarketType.PERPETUAL,
        symbol=symbol,
        timeframe=Timeframe.FIVE_MINUTES,
        open_at=opened,
        close_at=opened + timedelta(minutes=5),
        available_at=opened + timedelta(minutes=5, seconds=delay),
        open="100",
        high="102",
        low="99",
        close="101",
        volume="10",
        complete=True,
    )


def _artifacts(records):
    source = DatasetSourceIdentity(
        source="paper-okx", source_schema_version="paper.v2",
        source_build_version="fixture.v1", source_checksum="sha256:" + "d" * 64,
        source_network="mainnet", market_data_venue="okx",
        market_type=MarketType.PERPETUAL,
    )
    return DatasetSerializer.serialize(DatasetBuilder(source).build(records))


def test_adapter_preserves_exact_stream_and_availability_time() -> None:
    adapter = VerifiedBacktraderFeedAdapter(
        _artifacts((_candle(0, delay=3), _candle(1, delay=4))),
        symbol="BTCUSDT", timeframe="5m",
        period_start=datetime(2026, 1, 1, tzinfo=UTC),
        period_end=datetime(2026, 1, 1, 0, 10, 4, tzinfo=UTC),
    )

    bars = adapter.bars
    assert [bar.available_at.second for bar in bars] == [3, 4]
    assert [bar.source_record_id for bar in bars] == ["record-0", "record-1"]
    assert bars[0].close_at < bars[0].available_at
    assert bars[0].open == 100.0
    assert isinstance(bars[0].open, Decimal)


def test_adapter_preserves_sub_float_price_precision() -> None:
    candle = CandleRecord.model_validate({
        **_candle(0).model_dump(),
        "close": "100",
        "high": "100.09999999999999999",
    })
    adapter = VerifiedBacktraderFeedAdapter(
        _artifacts((candle,)), symbol="BTCUSDT", timeframe="5m",
        period_start=datetime(2026, 1, 1, tzinfo=UTC),
        period_end=datetime(2026, 1, 1, 0, 5, tzinfo=UTC),
    )
    assert adapter.bars[0].high == Decimal("100.09999999999999999")


def test_adapter_rejects_a_missing_requested_stream() -> None:
    with pytest.raises(BacktraderFeedError, match="scope_invalid"):
        VerifiedBacktraderFeedAdapter(
            _artifacts((_candle(0), _candle(1))), symbol="ETHUSDT", timeframe="5m",
            period_start=datetime(2026, 1, 1, tzinfo=UTC),
            period_end=datetime(2026, 1, 2, tzinfo=UTC),
        )


def test_adapter_rejects_empty_scope() -> None:
    with pytest.raises(BacktraderFeedError, match="scope_invalid"):
        VerifiedBacktraderFeedAdapter(
            _artifacts((_candle(0),)), symbol="ETHUSDT", timeframe="5m",
            period_start=datetime(2026, 1, 1, tzinfo=UTC),
            period_end=datetime(2026, 1, 2, tzinfo=UTC),
        )


def test_adapter_rejects_nonmonotonic_availability() -> None:
    with pytest.raises(BacktraderFeedError, match="stream_invalid"):
        VerifiedBacktraderFeedAdapter(
            _artifacts((_candle(0, delay=400), _candle(1))),
            symbol="BTCUSDT", timeframe="5m",
            period_start=datetime(2026, 1, 1, tzinfo=UTC),
            period_end=datetime(2026, 1, 2, tzinfo=UTC),
        )
