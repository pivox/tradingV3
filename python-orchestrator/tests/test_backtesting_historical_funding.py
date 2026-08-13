from datetime import datetime, timezone
import json

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
