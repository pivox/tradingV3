from datetime import datetime, timedelta, timezone
from pathlib import Path

import pytest

from app.backtesting.contracts import MarketType
from app.backtesting.dataset import (
    CandleRecord,
    DatasetBuilder,
    DatasetSerializer,
    DatasetSourceIdentity,
    Timeframe,
)
from app.backtesting.public_book_tape import (
    MAX_PUBLIC_BOOK_RECORDS,
    PublicBookRecord,
    PublicBookTapeArtifacts,
    VerifiedPublicBookTape,
    serialize_public_book_tape,
)


UTC = timezone.utc


def _dataset(*, venue: str = "okx"):
    source = DatasetSourceIdentity(
        source="paper_market_dataset",
        source_schema_version="paper-market-dataset.v2",
        source_build_version="paper-recorder.v2",
        source_checksum="sha256:" + "a" * 64,
        source_network="mainnet",
        market_data_venue=venue,
        market_type=MarketType.PERPETUAL,
    )
    candle = CandleRecord(
        source_record_id="c" * 64,
        source_network="mainnet",
        market_data_venue=venue,
        market_type=MarketType.PERPETUAL,
        symbol="BTCUSDT",
        timeframe=Timeframe.ONE_MINUTE,
        open_at=datetime(2026, 8, 13, 10, tzinfo=UTC),
        close_at=datetime(2026, 8, 13, 10, 1, tzinfo=UTC),
        available_at=datetime(2026, 8, 13, 10, 1, tzinfo=UTC),
        open="30000",
        high="30100",
        low="29900",
        close="30050",
        volume="12.5",
        complete=True,
    )
    return DatasetSerializer.verify(
        DatasetSerializer.serialize(DatasetBuilder(source).build((candle,)))
    )


def _book(record_id: str = "1" * 64) -> PublicBookRecord:
    return PublicBookRecord(
        schema_version="backtest-public-book.v1",
        source_record_id=record_id,
        source_checksum="sha256:" + "a" * 64,
        source_network="mainnet",
        market_data_venue="okx",
        market_type="perpetual",
        symbol="BTCUSDT",
        happened_at=datetime(2026, 8, 13, 10, 0, 30, tzinfo=UTC),
        available_at=datetime(2026, 8, 13, 10, 0, 30, 250000, tzinfo=UTC),
        bid_price="30000",
        bid_quantity="2.5",
        ask_price="30001",
        ask_quantity="3.5",
        quantity_unit="contracts",
        bid_order_count="2",
        ask_order_count="3",
        origin="ws_books",
    )


def test_tape_is_dataset_bound_canonical_and_byte_deterministic() -> None:
    dataset = _dataset()
    first = serialize_public_book_tape(dataset=dataset, records=(_book(),))
    second = serialize_public_book_tape(dataset=dataset, records=(_book(),))
    tape = VerifiedPublicBookTape(first, dataset=dataset)

    assert first == second
    assert tape.dataset_id == dataset.dataset_id
    assert tape.dataset_checksum == dataset.dataset_checksum
    assert tape.source_checksum == dataset.source_checksum
    assert tape.records == (_book(),)
    assert first.books_ndjson.endswith(b"\n")
    assert first.tape_checksum.startswith("sha256:")


def test_record_rejects_lookahead_crossed_book_wrong_units_and_counts() -> None:
    for updates in (
        {"available_at": datetime(2026, 8, 13, 10, 0, 29, tzinfo=UTC)},
        {"bid_price": "30001"},
        {"quantity_unit": "base_asset"},
        {"bid_order_count": "0"},
        {"bid_order_count": None},
        {"origin": "private"},
    ):
        with pytest.raises(ValueError):
            PublicBookRecord.model_validate({**_book().model_dump(), **updates})


def test_hyperliquid_contract_has_base_units_and_no_order_counts() -> None:
    payload = _book().model_dump()
    payload.update(
        {
            "market_data_venue": "hyperliquid",
            "quantity_unit": "base_asset",
            "bid_order_count": None,
            "ask_order_count": None,
            "origin": "ws_l2_book",
        }
    )
    record = PublicBookRecord.model_validate(payload)

    assert record.quantity_unit == "base_asset"
    assert record.bid_order_count is None


def test_tape_rejects_duplicates_order_drift_and_tampering() -> None:
    dataset = _dataset()
    later = _book("2" * 64)
    earlier = _book().model_copy(
        update={
            "happened_at": datetime(2026, 8, 13, 10, 0, 20, tzinfo=UTC),
            "available_at": datetime(2026, 8, 13, 10, 0, 20, tzinfo=UTC),
        }
    )
    with pytest.raises(ValueError, match="public_book_tape_records_invalid"):
        serialize_public_book_tape(dataset=dataset, records=(later, earlier))
    with pytest.raises(ValueError, match="public_book_tape_records_invalid"):
        serialize_public_book_tape(dataset=dataset, records=(_book(), _book()))

    artifacts = serialize_public_book_tape(dataset=dataset, records=(_book(),))
    forged = artifacts.model_copy(update={"tape_checksum": "sha256:" + "f" * 64})
    with pytest.raises(ValueError, match="public_book_tape_invalid"):
        VerifiedPublicBookTape(forged, dataset=dataset)


def test_tape_rejects_foreign_source_and_unrelated_dataset() -> None:
    dataset = _dataset()
    foreign = _book().model_copy(update={"source_checksum": "sha256:" + "b" * 64})
    with pytest.raises(ValueError, match="public_book_tape_records_invalid"):
        serialize_public_book_tape(dataset=dataset, records=(foreign,))

    artifacts = serialize_public_book_tape(dataset=dataset, records=(_book(),))
    unrelated = dataset.model_copy(
        update={
            "dataset_id": "backtest-dataset-" + "f" * 64,
            "dataset_checksum": "sha256:" + "f" * 64,
        }
    )
    with pytest.raises(ValueError, match="public_book_tape_invalid"):
        VerifiedPublicBookTape(artifacts, dataset=unrelated)


def test_tape_enforces_symbol_stream_coverage_but_keeps_delayed_receipt() -> None:
    outside = _book().model_copy(
        update={
            "happened_at": datetime(2026, 8, 13, 9, 59, 59, tzinfo=UTC),
            "available_at": datetime(2026, 8, 13, 10, tzinfo=UTC),
        }
    )
    with pytest.raises(ValueError, match="public_book_tape_records_invalid"):
        serialize_public_book_tape(dataset=_dataset(), records=(outside,))

    delayed = _book().model_copy(
        update={"available_at": datetime(2026, 8, 13, 10, 1, tzinfo=UTC) + timedelta(microseconds=1)}
    )
    tape = VerifiedPublicBookTape(
        serialize_public_book_tape(dataset=_dataset(), records=(delayed,)),
        dataset=_dataset(),
    )
    assert tape.records[0].available_at > _dataset().end_at


def test_tape_record_bound_guarantees_the_64_mib_artifact_budget() -> None:
    maximum = PublicBookRecord.model_validate(
        {
            **_book().model_dump(),
            "bid_price": "8" * 256,
            "bid_quantity": "9" * 256,
            "ask_price": "9" * 256,
            "ask_quantity": "9" * 256,
            "bid_order_count": "9" * 128,
            "ask_order_count": "9" * 128,
        }
    )
    maximum_bytes = len(maximum.model_dump_json().encode()) + 1
    assert maximum_bytes * MAX_PUBLIC_BOOK_RECORDS < 64 * 1024 * 1024

    records = tuple(
        _book(f"{index:064x}") for index in range(MAX_PUBLIC_BOOK_RECORDS + 1)
    )
    with pytest.raises(ValueError, match="public_book_tape_records_invalid"):
        serialize_public_book_tape(dataset=_dataset(), records=records)


def test_php_public_book_fixture_is_strict_cross_runtime_input() -> None:
    root = Path(__file__).parents[2] / "trading-app/tests/Fixtures/paper-backtesting"
    payload = (root / "public-books.ndjson").read_bytes()
    records = tuple(PublicBookRecord.model_validate_json(line) for line in payload.splitlines())
    source = DatasetSourceIdentity.model_validate_json((root / "source-identity.json").read_bytes())
    candles = tuple(
        CandleRecord.model_validate_json(line)
        for line in (root / "candles.ndjson").read_bytes().splitlines()
    )
    dataset = DatasetSerializer.verify(
        DatasetSerializer.serialize(DatasetBuilder(source).build(candles))
    )
    tape = VerifiedPublicBookTape(
        serialize_public_book_tape(dataset=dataset, records=records),
        dataset=dataset,
    )

    assert payload.endswith(b"\n")
    assert records[0].quantity_unit == "contracts"
    assert records[0].bid_order_count == "2"
    assert tape.source_checksum == source.source_checksum
