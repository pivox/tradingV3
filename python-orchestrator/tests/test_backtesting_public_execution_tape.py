from datetime import datetime, timedelta, timezone
from pathlib import Path

import pytest

from app.backtesting.dataset import (
    CandleRecord,
    DatasetBuilder,
    DatasetSerializer,
    DatasetSourceIdentity,
    Timeframe,
)
from app.backtesting.contracts import MarketType
from app.backtesting.public_execution_tape import (
    PublicExecutionTapeArtifacts,
    PublicTradeRecord,
    VerifiedPublicExecutionTape,
    serialize_public_execution_tape,
)


UTC = timezone.utc


def _dataset():
    source = DatasetSourceIdentity(
        source="paper_market_dataset", source_schema_version="paper-market-dataset.v2",
        source_build_version="paper-recorder.v2", source_checksum="sha256:" + "a" * 64,
        source_network="mainnet", market_data_venue="okx", market_type=MarketType.PERPETUAL,
    )
    candle = CandleRecord(
        source_record_id="c" * 64, source_network="mainnet", market_data_venue="okx",
        market_type=MarketType.PERPETUAL, symbol="BTCUSDT", timeframe=Timeframe.ONE_MINUTE,
        open_at=datetime(2026, 8, 13, 10, tzinfo=UTC),
        close_at=datetime(2026, 8, 13, 10, 1, tzinfo=UTC),
        available_at=datetime(2026, 8, 13, 10, 1, tzinfo=UTC),
        open="30000", high="30100", low="29900", close="30050", volume="12.5", complete=True,
    )
    return DatasetSerializer.verify(
        DatasetSerializer.serialize(DatasetBuilder(source).build((candle,)))
    )


def _trade(record_id: str = "1" * 64, trade_id: str = "42") -> PublicTradeRecord:
    return PublicTradeRecord(
        schema_version="backtest-public-trade.v1", source_record_id=record_id,
        source_network="mainnet", market_data_venue="okx", market_type="perpetual",
        symbol="BTCUSDT", venue_trade_id=trade_id,
        happened_at=datetime(2026, 8, 13, 10, 0, 30, tzinfo=UTC),
        available_at=datetime(2026, 8, 13, 10, 0, 30, 250000, tzinfo=UTC),
        aggressor_side="buy", price="30000.5", quantity="2.5", quantity_unit="contracts",
    )


def test_tape_is_dataset_bound_canonical_and_byte_deterministic() -> None:
    dataset = _dataset()
    first = serialize_public_execution_tape(dataset=dataset, records=(_trade(),))
    second = serialize_public_execution_tape(dataset=dataset, records=(_trade(),))
    tape = VerifiedPublicExecutionTape(first, dataset=dataset)

    assert first == second
    assert tape.dataset_id == dataset.dataset_id
    assert tape.dataset_checksum == dataset.dataset_checksum
    assert tape.source_checksum == dataset.source_checksum
    assert tape.records == (_trade(),)
    assert first.trades_ndjson.endswith(b"\n")
    assert first.tape_checksum.startswith("sha256:")


def test_tape_rejects_lookahead_wrong_units_duplicates_and_tampering() -> None:
    dataset = _dataset()
    with pytest.raises(ValueError):
        PublicTradeRecord.model_validate({
            **_trade().model_dump(),
            "available_at": datetime(2026, 8, 13, 10, 0, 29, tzinfo=UTC),
        })
    with pytest.raises(ValueError):
        PublicTradeRecord.model_validate({**_trade().model_dump(), "quantity_unit": "base_asset"})
    duplicate = _trade("2" * 64)
    with pytest.raises(ValueError, match="public_execution_tape_records_invalid"):
        serialize_public_execution_tape(dataset=dataset, records=(_trade(), duplicate))

    artifacts = serialize_public_execution_tape(dataset=dataset, records=(_trade(),))
    forged = artifacts.model_copy(update={"tape_checksum": "sha256:" + "f" * 64})
    with pytest.raises(ValueError, match="public_execution_tape_invalid"):
        VerifiedPublicExecutionTape(forged, dataset=dataset)


def test_tape_rejects_an_unrelated_dataset_even_with_valid_bytes() -> None:
    dataset = _dataset()
    artifacts = serialize_public_execution_tape(dataset=dataset, records=(_trade(),))
    unrelated = dataset.model_copy(update={
        "dataset_id": "backtest-dataset-" + "f" * 64,
        "dataset_checksum": "sha256:" + "f" * 64,
    })
    with pytest.raises(ValueError, match="public_execution_tape_invalid"):
        VerifiedPublicExecutionTape(artifacts, dataset=unrelated)


@pytest.mark.parametrize(
    "updates",
    (
        {
            "happened_at": datetime(2026, 8, 13, 9, 59, 59, tzinfo=UTC),
            "available_at": datetime(2026, 8, 13, 10, 0, tzinfo=UTC),
        },
    ),
)
def test_tape_rejects_records_outside_the_dataset_observation_window(
    updates: dict[str, datetime],
) -> None:
    record = PublicTradeRecord.model_validate({**_trade().model_dump(), **updates})

    with pytest.raises(ValueError, match="public_execution_tape_records_invalid"):
        serialize_public_execution_tape(dataset=_dataset(), records=(record,))


def test_tape_keeps_a_trade_received_after_its_exchange_coverage() -> None:
    record = PublicTradeRecord.model_validate(
        {
            **_trade().model_dump(),
            "available_at": datetime(2026, 8, 13, 10, 1, tzinfo=UTC)
            + timedelta(microseconds=1),
        }
    )

    tape = VerifiedPublicExecutionTape(
        serialize_public_execution_tape(dataset=_dataset(), records=(record,)),
        dataset=_dataset(),
    )

    assert tape.records[0].available_at > _dataset().end_at


def test_tape_rejects_more_records_than_the_bounded_artifact_can_guarantee() -> None:
    template = _trade()
    records = tuple(
        template.model_copy(
            update={"source_record_id": f"{index:064x}", "venue_trade_id": str(index + 1)}
        )
        for index in range(40_001)
    )

    with pytest.raises(ValueError, match="public_execution_tape_records_invalid"):
        serialize_public_execution_tape(dataset=_dataset(), records=records)


def test_hyperliquid_venue_identity_is_block_time_plus_trade_id() -> None:
    payload = _trade().model_dump()
    payload.update(
        {
            "market_data_venue": "hyperliquid",
            "venue_trade_id": "1786615230000:84",
            "quantity_unit": "base_asset",
        }
    )

    record = PublicTradeRecord.model_validate(payload)

    assert record.venue_trade_id == "1786615230000:84"


def test_php_public_trade_fixture_is_strict_cross_runtime_input() -> None:
    root = Path(__file__).parents[2] / "trading-app/tests/Fixtures/paper-backtesting"
    payload = (root / "public-trades.ndjson").read_bytes()
    assert payload.endswith(b"\n")
    records = tuple(PublicTradeRecord.model_validate_json(line) for line in payload.splitlines())
    source = DatasetSourceIdentity.model_validate_json((root / "source-identity.json").read_bytes())
    candles = tuple(
        CandleRecord.model_validate_json(line)
        for line in (root / "candles.ndjson").read_bytes().splitlines()
    )
    dataset = DatasetSerializer.verify(
        DatasetSerializer.serialize(DatasetBuilder(source).build(candles))
    )
    tape = VerifiedPublicExecutionTape(
        serialize_public_execution_tape(dataset=dataset, records=records),
        dataset=dataset,
    )
    assert records[0].quantity_unit == "contracts"
    assert records[0].venue_trade_id == "42"
    assert tape.source_checksum == source.source_checksum
