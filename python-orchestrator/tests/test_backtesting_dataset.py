from __future__ import annotations

from datetime import datetime, timedelta, timezone

import pytest
from pydantic import ValidationError

from app.backtesting.dataset import (
    CandleRecord,
    DatasetBuildRejected,
    DatasetBuildResult,
    DatasetQualityReport,
    DatasetSourceIdentity,
    DatasetStreamQuality,
    MissingRange,
    Timeframe,
)


def _utc(value: str) -> datetime:
    return datetime.fromisoformat(value).replace(tzinfo=timezone.utc)


def _candle(**overrides: object) -> CandleRecord:
    payload: dict[str, object] = {
        "schema_version": "backtest-candle.v1",
        "source_record_id": "fake:BTCUSDT:1m:2026-01-01T00:00:00Z",
        "source_network": "fake",
        "market_data_venue": "fake",
        "market_type": "perpetual",
        "symbol": "BTCUSDT",
        "timeframe": "1m",
        "open_at": _utc("2026-01-01T00:00:00"),
        "close_at": _utc("2026-01-01T00:01:00"),
        "available_at": _utc("2026-01-01T00:01:00"),
        "open": "100",
        "high": "102.5",
        "low": "99.75",
        "close": "101",
        "volume": "0",
        "complete": True,
    }
    payload.update(overrides)
    return CandleRecord(**payload)


def _source() -> DatasetSourceIdentity:
    return DatasetSourceIdentity(
        source="paper-fixture",
        source_schema_version="paper-market-events.v1",
        source_build_version="paper-exporter.v3",
        source_checksum="sha256:" + "a" * 64,
        source_network="fake",
        market_data_venue="fake",
        market_type="perpetual",
    )


@pytest.mark.parametrize(
    ("timeframe", "duration"),
    (("1m", 60), ("5m", 300), ("15m", 900), ("1h", 3600), ("4h", 14400)),
)
def test_candle_accepts_only_the_five_versioned_timeframes(
    timeframe: str,
    duration: int,
) -> None:
    opened = _utc("2026-01-01T00:00:00")

    candle = _candle(
        timeframe=timeframe,
        close_at=opened + timedelta(seconds=duration),
        available_at=opened + timedelta(seconds=duration),
    )

    assert candle.timeframe is Timeframe(timeframe)
    assert candle.timeframe.duration_seconds == duration


def test_candle_is_frozen_and_rejects_unknown_or_unsupported_fields() -> None:
    candle = _candle()

    with pytest.raises(ValidationError, match="frozen"):
        candle.symbol = "ETHUSDT"
    with pytest.raises(ValidationError, match="Extra inputs are not permitted"):
        _candle(profile="regular")
    with pytest.raises(ValidationError):
        _candle(timeframe="30m")


@pytest.mark.parametrize(
    "field",
    ("open_at", "close_at", "available_at"),
)
def test_candle_requires_utc_aware_timestamps(field: str) -> None:
    with pytest.raises(ValidationError, match="must be UTC-aware"):
        _candle(**{field: datetime.fromisoformat("2026-01-01T00:00:00")})

    with pytest.raises(ValidationError, match="must use UTC offset"):
        _candle(
            **{
                field: datetime.fromisoformat("2026-01-01T01:00:00+01:00"),
            }
        )


def test_candle_requires_exact_timeframe_duration_and_availability_boundary() -> None:
    with pytest.raises(ValidationError, match="duration must equal timeframe"):
        _candle(close_at=_utc("2026-01-01T00:02:00"))

    with pytest.raises(ValidationError, match="available_at must not precede close_at"):
        _candle(available_at=_utc("2026-01-01T00:00:59"))

    with pytest.raises(ValidationError, match="complete must be true"):
        _candle(complete=False)


@pytest.mark.parametrize(
    ("field", "value"),
    (
        ("open", "01"),
        ("high", "1.0"),
        ("low", "+1"),
        ("close", "1e2"),
        ("volume", "0.00"),
        ("open", " 1"),
        ("open", 1),
    ),
)
def test_candle_rejects_noncanonical_decimal_representations(
    field: str,
    value: object,
) -> None:
    with pytest.raises(ValidationError, match="canonical decimal string"):
        _candle(**{field: value})


@pytest.mark.parametrize("field", ("open", "high", "low", "close"))
def test_candle_requires_positive_prices(field: str) -> None:
    with pytest.raises(ValidationError, match="price must be positive"):
        _candle(**{field: "0"})


def test_candle_requires_nonnegative_volume_and_valid_ohlc_envelope() -> None:
    with pytest.raises(ValidationError, match="canonical decimal string"):
        _candle(volume="-1")
    with pytest.raises(ValidationError, match="low must not exceed open or close"):
        _candle(low="101.5")
    with pytest.raises(ValidationError, match="high must not be below open or close"):
        _candle(high="100.5")


def test_source_and_quality_contracts_are_strict_frozen_and_strategy_independent() -> None:
    source = _source()
    missing = MissingRange(
        first_missing_open_at=_utc("2026-01-01T00:01:00"),
        end_at=_utc("2026-01-01T00:02:00"),
        timeframe="1m",
        missing_bar_count=1,
    )
    stream = DatasetStreamQuality(
        market_data_venue="fake",
        market_type="perpetual",
        symbol="BTCUSDT",
        timeframe="1m",
        first_open_at=_utc("2026-01-01T00:00:00"),
        last_close_at=_utc("2026-01-01T00:03:00"),
        expected_count=3,
        observed_count=2,
        missing_ranges=(missing,),
    )
    report = DatasetQualityReport(
        schema_version="backtest-dataset-quality.v1",
        input_count=2,
        accepted_count=2,
        streams=(stream,),
        exact_duplicate_count=0,
        conflicting_duplicate_count=0,
        missing_ranges=(missing,),
        quality_flags=("missing_range",),
    )
    result = DatasetBuildResult(
        source_identity=source,
        records=(_candle(),),
        quality_report=report,
        symbols=("BTCUSDT",),
        timeframes=(Timeframe.ONE_MINUTE,),
        start_at=_utc("2026-01-01T00:00:00"),
        end_at=_utc("2026-01-01T00:01:00"),
        record_count=1,
    )

    for contract in (
        CandleRecord,
        DatasetSourceIdentity,
        MissingRange,
        DatasetStreamQuality,
        DatasetQualityReport,
        DatasetBuildResult,
    ):
        fields = set(contract.model_fields)
        assert fields.isdisjoint(
            {"profile", "mode", "mode_id", "setup", "setup_id", "alias"}
        )

    assert result.records == (_candle(),)
    with pytest.raises(ValidationError, match="frozen"):
        source.source = "changed"
    with pytest.raises(ValidationError, match="Extra inputs are not permitted"):
        DatasetSourceIdentity(**{**source.model_dump(), "profile": "regular"})


def test_build_rejection_exposes_only_stable_reason_and_typed_report() -> None:
    report = DatasetQualityReport(
        schema_version="backtest-dataset-quality.v1",
        input_count=0,
        accepted_count=0,
        streams=(),
        exact_duplicate_count=0,
        conflicting_duplicate_count=0,
        missing_ranges=(),
        quality_flags=("empty_input",),
    )

    rejection = DatasetBuildRejected("dataset_quality_rejected", report)

    assert rejection.reason_code == "dataset_quality_rejected"
    assert rejection.report is report
    assert str(rejection) == "dataset_quality_rejected"
    assert not hasattr(rejection, "records")
