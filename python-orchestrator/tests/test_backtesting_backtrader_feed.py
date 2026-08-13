from datetime import datetime, timedelta, timezone

import pytest

from app.backtesting.backtrader_feed import BacktraderFeedError, VerifiedBacktraderFeedAdapter
from app.backtesting.contracts import MarketType
from app.backtesting.dataset import CandleRecord, Timeframe


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


def test_adapter_preserves_exact_stream_and_availability_time() -> None:
    adapter = VerifiedBacktraderFeedAdapter(
        (_candle(0, delay=3), _candle(1, delay=4)),
        dataset_id="backtest-dataset-" + "a" * 64,
        dataset_checksum="sha256:" + "a" * 64,
        period_start=datetime(2026, 1, 1, tzinfo=UTC),
        period_end=datetime(2026, 1, 1, 0, 10, 4, tzinfo=UTC),
    )

    bars = adapter.bars
    assert [bar.available_at.second for bar in bars] == [3, 4]
    assert [bar.source_record_id for bar in bars] == ["record-0", "record-1"]
    assert bars[0].close_at < bars[0].available_at
    assert bars[0].open == 100.0


@pytest.mark.parametrize(
    "records",
    [
        lambda: (_candle(1), _candle(0)),
        lambda: (_candle(0), _candle(2)),
        lambda: (_candle(0), _candle(0)),
        lambda: (_candle(0), _candle(1, symbol="ETHUSDT")),
    ],
)
def test_adapter_rejects_noncanonical_or_mixed_stream(records) -> None:
    with pytest.raises(BacktraderFeedError):
        VerifiedBacktraderFeedAdapter(
            records(),
            dataset_id="backtest-dataset-" + "a" * 64,
            dataset_checksum="sha256:" + "a" * 64,
            period_start=datetime(2026, 1, 1, tzinfo=UTC),
            period_end=datetime(2026, 1, 2, tzinfo=UTC),
        )
