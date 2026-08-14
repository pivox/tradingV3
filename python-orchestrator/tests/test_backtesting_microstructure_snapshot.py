import copy
from datetime import datetime, timezone

import pytest

from app.backtesting.contracts import MarketType
from app.backtesting.dataset import CandleRecord, DatasetBuilder, DatasetSerializer, DatasetSourceIdentity, Timeframe
from app.backtesting.microstructure_snapshot import MicrostructurePolicy, build_microstructure_snapshot
from app.backtesting.public_book_tape import PublicBookRecord, VerifiedPublicBookTape, serialize_public_book_tape
from app.backtesting.public_execution_tape import PublicTradeRecord, VerifiedPublicExecutionTape, serialize_public_execution_tape


UTC = timezone.utc


def _dataset():
    source = DatasetSourceIdentity(
        source="paper_market_dataset", source_schema_version="paper-market-dataset.v2",
        source_build_version="paper-recorder.v2", source_checksum="sha256:" + "f" * 64,
        source_network="mainnet", market_data_venue="okx", market_type=MarketType.PERPETUAL,
    )
    candle = CandleRecord(
        source_record_id="c" * 64, source_network="mainnet", market_data_venue="okx",
        market_type=MarketType.PERPETUAL, symbol="BTCUSDT", timeframe=Timeframe.ONE_MINUTE,
        open_at=datetime(2026, 8, 14, 12, 0, tzinfo=UTC),
        close_at=datetime(2026, 8, 14, 12, 1, tzinfo=UTC),
        available_at=datetime(2026, 8, 14, 12, 1, tzinfo=UTC),
        open="99", high="101", low="99", close="100", volume="6", complete=True,
    )
    return DatasetSerializer.verify(DatasetSerializer.serialize(DatasetBuilder(source).build((candle,))))


def _tapes():
    dataset = _dataset()
    book = PublicBookRecord(
        schema_version="backtest-public-book.v1", source_record_id="a" * 64,
        source_checksum=dataset.source_checksum, source_network="mainnet", market_data_venue="okx",
        market_type="perpetual", symbol="BTCUSDT",
        happened_at=datetime(2026, 8, 14, 12, 0, 59, tzinfo=UTC),
        available_at=datetime(2026, 8, 14, 12, 0, 59, tzinfo=UTC),
        bid_price="99", bid_quantity="10", ask_price="101", ask_quantity="12",
        quantity_unit="contracts", bid_order_count="2", ask_order_count="3", origin="ws_books",
    )
    trades = tuple(
        PublicTradeRecord(
            schema_version="backtest-public-trade.v1", source_record_id=record_id * 64,
            source_checksum=dataset.source_checksum, source_network="mainnet", market_data_venue="okx",
            market_type="perpetual", symbol="BTCUSDT", venue_trade_id=record_id,
            happened_at=datetime(2026, 8, 14, 12, 0, second, tzinfo=UTC),
            available_at=datetime(2026, 8, 14, 12, 0, second, tzinfo=UTC),
            aggressor_side=side, price="100", quantity=quantity, quantity_unit="contracts",
        )
        for record_id, second, side, quantity in (
            ("1", 10, "buy", "3"), ("2", 30, "sell", "1"), ("3", 55, "buy", "2"),
        )
    )
    books = VerifiedPublicBookTape(serialize_public_book_tape(dataset=dataset, records=(book,)), dataset=dataset)
    executions = VerifiedPublicExecutionTape(serialize_public_execution_tape(dataset=dataset, records=trades), dataset=dataset)
    return books, executions


def test_builds_the_same_canonical_microstructure_payload_as_php() -> None:
    books, trades = _tapes()
    snapshot = build_microstructure_snapshot(
        policy=MicrostructurePolicy(
            window_seconds=60, maximum_book_age_seconds=2, maximum_trade_age_seconds=5,
            maximum_trade_gap_seconds=30, minimum_trade_count=3,
        ),
        evaluated_at=datetime(2026, 8, 14, 12, 1, tzinfo=UTC),
        public_book_tape=books,
        public_execution_tape=trades,
    )

    assert snapshot.spread_bps == "200"
    assert snapshot.order_flow_imbalance == "0.666666666667"
    assert snapshot.buy_quantity == "5"
    assert snapshot.sell_quantity == "1"
    assert snapshot.total_quantity == "6"
    assert snapshot.verify() is snapshot
    assert snapshot.input_hash == "sha256:a7e30f9c9cf55b5020b90ae3dbd0f29cb149ca3754773e07cb3f6263244f2e04"


def test_rejects_mismatched_verified_tapes() -> None:
    books, trades = _tapes()
    forged = copy.copy(trades)
    object.__setattr__(forged, "dataset_checksum", "sha256:" + "0" * 64)

    with pytest.raises(ValueError, match="canonical_microstructure_identity_mismatch"):
        build_microstructure_snapshot(
            policy=MicrostructurePolicy(
                window_seconds=60, maximum_book_age_seconds=2, maximum_trade_age_seconds=5,
                maximum_trade_gap_seconds=30, minimum_trade_count=3,
            ),
            evaluated_at=datetime(2026, 8, 14, 12, 1, tzinfo=UTC),
            public_book_tape=books,
            public_execution_tape=forged,
        )
