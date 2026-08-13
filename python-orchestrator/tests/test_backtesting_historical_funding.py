from datetime import datetime, timedelta, timezone
import json
from pathlib import Path

import pytest

from app.backtesting.historical_funding import (
    HistoricalFundingRecord,
    HistoricalFundingScheduleArtifacts,
    VerifiedHistoricalFundingSchedule,
    serialize_historical_funding_schedule,
)


UTC = timezone.utc
DATASET_CHECKSUM = "sha256:" + "a" * 64
DATASET_ID = "backtest-dataset-" + "a" * 64
FIXTURES = Path(__file__).parent / "fixtures/backtesting"


def _record(at: str, record_id: str = "funding-1", rate: str = "0.0001") -> HistoricalFundingRecord:
    return HistoricalFundingRecord(
        schema_version="historical-funding-record.v1",
        source_record_id=record_id,
        source_network="mainnet",
        market_data_venue="okx",
        market_type="perpetual",
        symbol="BTCUSDT",
        funding_at=datetime.fromisoformat(at).astimezone(UTC),
        available_at=datetime.fromisoformat(at).astimezone(UTC),
        funding_rate=rate,
        mark_price="100.25",
        interval_seconds=28_800,
    )


def _artifacts() -> HistoricalFundingScheduleArtifacts:
    records = (
        _record("2026-08-10T08:00:00+00:00"),
        _record("2026-08-10T16:00:00+00:00", "funding-2", "-0.00005"),
    )
    return serialize_historical_funding_schedule(
        dataset_id=DATASET_ID,
        dataset_checksum=DATASET_CHECKSUM,
        coverage_start=datetime(2026, 8, 10, 0, tzinfo=UTC),
        coverage_end=datetime(2026, 8, 10, 16, tzinfo=UTC),
        records=records,
    )


def test_schedule_is_canonical_checksum_bound_and_deterministic() -> None:
    first = _artifacts()
    second = _artifacts()
    verified = VerifiedHistoricalFundingSchedule(first)

    assert first == second
    assert first.schedule_checksum.startswith("sha256:")
    assert first.schedule_json.endswith(b"\n")
    assert json.loads(first.schedule_json)["schema_version"] == "historical-funding-schedule.v1"
    assert verified.dataset_id == DATASET_ID
    assert verified.dataset_checksum == DATASET_CHECKSUM
    assert verified.records[1].funding_rate == "-0.00005"


@pytest.mark.parametrize("field,value", [("funding_rate", "1e-4"), ("mark_price", "100.250")])
def test_record_rejects_noncanonical_decimals(field: str, value: str) -> None:
    payload = _record("2026-08-10T08:00:00+00:00").model_dump()
    payload[field] = value
    with pytest.raises(ValueError):
        HistoricalFundingRecord.model_validate(payload)


def test_schedule_rejects_gaps_late_records_and_source_mismatch() -> None:
    valid = _record("2026-08-10T08:00:00+00:00")
    for records in (
        (valid, _record("2026-08-11T00:00:00+00:00", "gap")),
        (valid.model_copy(update={"available_at": datetime(2026, 8, 10, 8, 0, 1, tzinfo=UTC)}),),
        (valid, valid.model_copy(update={"source_record_id": "other", "market_data_venue": "hyperliquid"})),
    ):
        with pytest.raises(ValueError):
            serialize_historical_funding_schedule(
                dataset_id=DATASET_ID,
                dataset_checksum=DATASET_CHECKSUM,
                coverage_start=datetime(2026, 8, 10, 0, tzinfo=UTC),
                coverage_end=records[-1].funding_at,
                records=records,
            )


def test_verifier_rejects_tampered_bytes_or_checksum() -> None:
    artifacts = _artifacts()
    decoded = json.loads(artifacts.schedule_json)
    decoded["records"][0]["funding_rate"] = "0.5"
    tampered = json.dumps(decoded, separators=(",", ":"), sort_keys=True).encode() + b"\n"
    for forged in (
        artifacts.model_copy(update={"schedule_json": tampered}),
        artifacts.model_copy(update={"schedule_checksum": "sha256:" + "b" * 64}),
    ):
        with pytest.raises(ValueError, match="historical_funding_schedule_invalid"):
            VerifiedHistoricalFundingSchedule(forged)


def test_versioned_schedule_fixture_is_canonical() -> None:
    payload = (FIXTURES / "historical-funding-schedule.json").read_bytes()
    artifacts = HistoricalFundingScheduleArtifacts(
        schedule_json=payload,
        schedule_checksum="sha256:378bbdd4e7d1b65b5f97454ef69cf33b1e58675bcb710e0b024f9c3044311439",
    )
    verified = VerifiedHistoricalFundingSchedule(artifacts)
    assert verified.records[1].source_record_id == "golden-funding-2"


def test_timestamp_and_decimal_guards_fail_closed() -> None:
    from app.backtesting.historical_funding import _decimal, _parse_time, _utc
    for value in (datetime(2026, 8, 10), datetime(2026, 8, 10, tzinfo=timezone(timedelta(hours=1)))):
        with pytest.raises(ValueError, match="timestamp_invalid"):
            _utc(value)
    for value, positive in (("-0", False), ("0", True), (1, False)):
        with pytest.raises(ValueError, match="decimal_invalid"):
            _decimal(value, positive=positive)
    for value in (1, "2026-08-10T08:00:00+00:00", "2026-99-99T08:00:00Z"):
        with pytest.raises(ValueError, match="timestamp_invalid"):
            _parse_time(value)


def test_schedule_shape_identity_and_coverage_guards_fail_closed() -> None:
    from app.backtesting.historical_funding import _json_value, _validated_schedule
    raw = json.loads(_artifacts().schedule_json)
    mutations = []
    missing_schema = dict(raw); missing_schema.pop("schema_version"); mutations.append(missing_schema)
    wrong_dataset = dict(raw); wrong_dataset["dataset_id"] = "backtest-dataset-" + "b" * 64; mutations.append(wrong_dataset)
    empty = dict(raw); empty["records"] = []; mutations.append(empty)
    mixed = json.loads(json.dumps(raw)); mixed["records"][1]["symbol"] = "ETHUSDT"; mutations.append(mixed)
    duplicate_id = json.loads(json.dumps(raw)); duplicate_id["records"][1]["source_record_id"] = "funding-1"; mutations.append(duplicate_id)
    duplicate_time = json.loads(json.dumps(raw)); duplicate_time["records"][1]["funding_at"] = raw["records"][0]["funding_at"]; mutations.append(duplicate_time)
    for mutation in mutations:
        with pytest.raises(ValueError):
            _validated_schedule(_json_value(mutation))

    with pytest.raises(ValueError, match="records_invalid"):
        serialize_historical_funding_schedule(
            dataset_id=DATASET_ID, dataset_checksum=DATASET_CHECKSUM,
            coverage_start=datetime(2026, 8, 10, tzinfo=UTC),
            coverage_end=datetime(2026, 8, 10, 8, tzinfo=UTC), records=(),
        )


def test_verifier_rejects_wrong_object_and_noncanonical_file_shape() -> None:
    with pytest.raises(ValueError, match="schedule_invalid"):
        VerifiedHistoricalFundingSchedule(object())  # type: ignore[arg-type]
    artifacts = _artifacts()
    for payload in (
        artifacts.schedule_json.rstrip(b"\n"),
        artifacts.schedule_json + b"\n",
        b"[]\n",
    ):
        checksum = "sha256:" + __import__("hashlib").sha256(payload).hexdigest()
        with pytest.raises(ValueError, match="schedule_invalid"):
            VerifiedHistoricalFundingSchedule(HistoricalFundingScheduleArtifacts(
                schedule_json=payload, schedule_checksum=checksum,
            ))
