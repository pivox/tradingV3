from pathlib import Path

import pytest

from app.backtesting.dataset import CandleRecord, DatasetBuilder, DatasetSerializer, DatasetSourceIdentity
from app.backtesting.public_book_tape import PublicBookRecord, VerifiedPublicBookTape, serialize_public_book_tape
from app.backtesting.public_execution_tape import PublicTradeRecord, VerifiedPublicExecutionTape, serialize_public_execution_tape
from app.backtesting.public_quantity_conversion_tape import (
    BookQuantityConversionRecord,
    InstrumentMetadataRecord,
    PublicQuantityConversionTapeArtifacts,
    TradeQuantityConversionRecord,
    VerifiedPublicQuantityConversionTape,
    serialize_public_quantity_conversion_tape,
)


FIXTURES = Path(__file__).parents[2] / "trading-app/tests/Fixtures/paper-backtesting"


def _inputs():
    source = DatasetSourceIdentity.model_validate_json((FIXTURES / "source-identity.json").read_bytes())
    candles = tuple(
        CandleRecord.model_validate_json(line)
        for line in (FIXTURES / "candles.ndjson").read_bytes().splitlines()
    )
    dataset = DatasetSerializer.verify(DatasetSerializer.serialize(DatasetBuilder(source).build(candles)))
    trades = tuple(
        PublicTradeRecord.model_validate_json(line)
        for line in (FIXTURES / "public-trades.ndjson").read_bytes().splitlines()
    )
    books = tuple(
        PublicBookRecord.model_validate_json(line)
        for line in (FIXTURES / "public-books.ndjson").read_bytes().splitlines()
    )
    execution = VerifiedPublicExecutionTape(
        serialize_public_execution_tape(dataset=dataset, records=trades), dataset=dataset
    )
    book_tape = VerifiedPublicBookTape(
        serialize_public_book_tape(dataset=dataset, records=books), dataset=dataset
    )
    metadata = tuple(
        InstrumentMetadataRecord.model_validate_json(line)
        for line in (FIXTURES / "instrument-metadata.ndjson").read_bytes().splitlines()
    )
    conversions = tuple(
        TradeQuantityConversionRecord.model_validate_json(line)
        if b'"source_channel":"public_trade"' in line
        else BookQuantityConversionRecord.model_validate_json(line)
        for line in (FIXTURES / "quantity-conversions.ndjson").read_bytes().splitlines()
    )
    return dataset, execution, book_tape, metadata, conversions


def test_php_fixture_builds_a_dataset_bound_byte_deterministic_tape() -> None:
    dataset, execution, books, metadata, conversions = _inputs()

    first = serialize_public_quantity_conversion_tape(
        dataset=dataset,
        public_execution_tape=execution,
        public_book_tape=books,
        metadata=metadata,
        conversions=conversions,
    )
    second = serialize_public_quantity_conversion_tape(
        dataset=dataset,
        public_execution_tape=execution,
        public_book_tape=books,
        metadata=metadata,
        conversions=conversions,
    )
    verified = VerifiedPublicQuantityConversionTape(
        first,
        dataset=dataset,
        public_execution_tape=execution,
        public_book_tape=books,
    )

    assert first == second
    assert verified.metadata == metadata
    assert verified.conversions == conversions
    assert first.metadata_ndjson == (FIXTURES / "instrument-metadata.ndjson").read_bytes()
    assert first.conversions_ndjson == (FIXTURES / "quantity-conversions.ndjson").read_bytes()
    assert first.tape_checksum.startswith("sha256:")


def test_tape_independently_rejects_formula_drift_and_incomplete_coverage() -> None:
    dataset, execution, books, metadata, conversions = _inputs()
    forged = conversions[0].model_copy(update={"base_quantity": "0.06"})

    with pytest.raises(ValueError, match="public_quantity_conversion_records_invalid"):
        serialize_public_quantity_conversion_tape(
            dataset=dataset,
            public_execution_tape=execution,
            public_book_tape=books,
            metadata=metadata,
            conversions=(forged, conversions[1]),
        )
    with pytest.raises(ValueError, match="public_quantity_conversion_records_invalid"):
        serialize_public_quantity_conversion_tape(
            dataset=dataset,
            public_execution_tape=execution,
            public_book_tape=books,
            metadata=metadata,
            conversions=(conversions[0],),
        )


def test_tape_rejects_metadata_lookahead_and_wrong_reference() -> None:
    dataset, execution, books, metadata, conversions = _inputs()
    late = metadata[0].model_copy(update={"source_event_position": 3})
    wrong_reference = conversions[0].model_copy(update={"metadata_record_id": "f" * 64})
    for changed_metadata, changed_conversions in (
        ((late,), conversions),
        (metadata, (wrong_reference, conversions[1])),
    ):
        with pytest.raises(ValueError, match="public_quantity_conversion_records_invalid"):
            serialize_public_quantity_conversion_tape(
                dataset=dataset,
                public_execution_tape=execution,
                public_book_tape=books,
                metadata=changed_metadata,
                conversions=changed_conversions,
            )
def test_artifact_tampering_and_empty_sources_fail_closed() -> None:
    dataset, execution, books, metadata, conversions = _inputs()
    artifacts = serialize_public_quantity_conversion_tape(
        dataset=dataset,
        public_execution_tape=execution,
        public_book_tape=books,
        metadata=metadata,
        conversions=conversions,
    )
    forged = PublicQuantityConversionTapeArtifacts.model_validate(
        {**artifacts.model_dump(), "tape_checksum": "sha256:" + "f" * 64}
    )
    with pytest.raises(ValueError, match="public_quantity_conversion_tape_invalid"):
        VerifiedPublicQuantityConversionTape(
            forged,
            dataset=dataset,
            public_execution_tape=execution,
            public_book_tape=books,
        )
    with pytest.raises(ValueError, match="public_quantity_conversion_records_invalid"):
        serialize_public_quantity_conversion_tape(
            dataset=dataset,
            public_execution_tape=None,
            public_book_tape=None,
            metadata=metadata,
            conversions=conversions,
        )


def test_serializer_never_silently_drops_an_unknown_conversion_object() -> None:
    dataset, execution, books, metadata, conversions = _inputs()

    with pytest.raises(ValueError, match="public_quantity_conversion_records_invalid"):
        serialize_public_quantity_conversion_tape(
            dataset=dataset,
            public_execution_tape=execution,
            public_book_tape=books,
            metadata=metadata,
            conversions=(metadata[0], *conversions),  # type: ignore[arg-type]
        )


def test_source_event_positions_are_unique_evidence_coordinates() -> None:
    dataset, execution, books, metadata, conversions = _inputs()
    duplicate_metadata_position = metadata[0].model_copy(
        update={"source_record_id": "f" * 64}
    )
    duplicate_conversion_position = conversions[1].model_copy(
        update={"source_event_position": conversions[0].source_event_position}
    )

    for changed_metadata, changed_conversions in (
        ((metadata[0], duplicate_metadata_position), conversions),
        (metadata, (conversions[0], duplicate_conversion_position)),
    ):
        with pytest.raises(ValueError, match="public_quantity_conversion_records_invalid"):
            serialize_public_quantity_conversion_tape(
                dataset=dataset,
                public_execution_tape=execution,
                public_book_tape=books,
                metadata=changed_metadata,
                conversions=changed_conversions,
            )
