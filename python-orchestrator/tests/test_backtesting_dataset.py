from __future__ import annotations

from datetime import datetime, timedelta, timezone

import pytest
from pydantic import ValidationError

from app.backtesting.dataset import (
    CandleRecord,
    DatasetBuilder,
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


def _candle_at(
    minute: int,
    *,
    symbol: str = "BTCUSDT",
    timeframe: str = "1m",
    source_record_id: str | None = None,
    **overrides: object,
) -> CandleRecord:
    duration = Timeframe(timeframe).duration
    opened = _utc("2026-01-01T00:00:00") + timedelta(minutes=minute)
    payload: dict[str, object] = {
        "source_record_id": source_record_id
        or f"fake:{symbol}:{timeframe}:{opened.isoformat()}",
        "symbol": symbol,
        "timeframe": timeframe,
        "open_at": opened,
        "close_at": opened + duration,
        "available_at": opened + duration,
    }
    payload.update(overrides)
    return _candle(**payload)


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
    result = DatasetBuilder(source).build((_candle(),))

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


def test_builder_rejects_empty_input_with_a_typed_report() -> None:
    builder = DatasetBuilder(_source())

    report = builder.analyze([])

    assert report.input_count == 0
    assert report.accepted_count == 0
    assert report.streams == ()
    assert report.quality_flags == ("empty_input",)
    with pytest.raises(DatasetBuildRejected) as rejected:
        builder.build(iter(()))
    assert rejected.value.reason_code == "dataset_quality_rejected"
    assert rejected.value.report == report


@pytest.mark.parametrize(
    ("field", "first", "second", "expected_flag"),
    (
        ("source_network", "fake", "testnet", "mixed_source_network"),
        ("market_data_venue", "fake", "bitmart", "mixed_market_data_venue"),
        ("market_type", "perpetual", "spot", "mixed_market_type"),
    ),
)
def test_builder_rejects_mixed_source_identity_dimensions(
    field: str,
    first: str,
    second: str,
    expected_flag: str,
) -> None:
    records = (
        _candle_at(0, **{field: first}),
        _candle_at(1, **{field: second}),
    )

    report = DatasetBuilder(_source()).analyze(records)

    assert expected_flag in report.quality_flags
    with pytest.raises(DatasetBuildRejected) as rejected:
        DatasetBuilder(_source()).build(records)
    assert expected_flag in rejected.value.report.quality_flags


def test_builder_builds_one_complete_stream_and_derives_bounds() -> None:
    records = (_candle_at(1), _candle_at(0), _candle_at(2))

    result = DatasetBuilder(_source()).build(records)

    assert result.records == (_candle_at(0), _candle_at(1), _candle_at(2))
    assert result.record_count == 3
    assert result.symbols == ("BTCUSDT",)
    assert result.timeframes == (Timeframe.ONE_MINUTE,)
    assert result.start_at == _utc("2026-01-01T00:00:00")
    assert result.end_at == _utc("2026-01-01T00:03:00")
    assert result.quality_report.eligible is True
    assert result.quality_report.input_count == 3
    assert result.quality_report.accepted_count == 3
    assert result.quality_report.streams[0].expected_count == 3
    assert result.quality_report.streams[0].observed_count == 3


def test_builder_orders_multiple_symbols_and_timeframes_canonically() -> None:
    records = (
        _candle_at(0, symbol="ETHUSDT", timeframe="1h"),
        _candle_at(0, symbol="BTCUSDT", timeframe="1h"),
        _candle_at(0, symbol="BTCUSDT", timeframe="15m"),
        _candle_at(0, symbol="BTCUSDT", timeframe="1m"),
    )

    result = DatasetBuilder(_source()).build(reversed(records))

    assert result.symbols == ("BTCUSDT", "ETHUSDT")
    assert result.timeframes == (
        Timeframe.ONE_MINUTE,
        Timeframe.FIFTEEN_MINUTES,
        Timeframe.ONE_HOUR,
    )
    assert tuple((item.symbol, item.timeframe.value) for item in result.records) == (
        ("BTCUSDT", "1m"),
        ("BTCUSDT", "15m"),
        ("BTCUSDT", "1h"),
        ("ETHUSDT", "1h"),
    )
    assert tuple(
        (stream.symbol, stream.timeframe.value)
        for stream in result.quality_report.streams
    ) == (
        ("BTCUSDT", "1m"),
        ("BTCUSDT", "15m"),
        ("BTCUSDT", "1h"),
        ("ETHUSDT", "1h"),
    )


def test_builder_reports_exact_duplicate_without_deduplicating_or_building() -> None:
    candle = _candle_at(0)

    report = DatasetBuilder(_source()).analyze((candle, candle))

    assert report.input_count == 2
    assert report.accepted_count == 2
    assert report.exact_duplicate_count == 1
    assert report.conflicting_duplicate_count == 0
    assert report.quality_flags == ("exact_duplicate",)
    with pytest.raises(DatasetBuildRejected):
        DatasetBuilder(_source()).build((candle, candle))


def test_builder_reports_conflicting_duplicate_without_selecting_a_winner() -> None:
    first = _candle_at(0, source_record_id="source-a")
    second = _candle_at(0, source_record_id="source-b", close="100.5")

    report = DatasetBuilder(_source()).analyze((second, first))

    assert report.exact_duplicate_count == 0
    assert report.conflicting_duplicate_count == 1
    assert report.quality_flags == ("conflicting_duplicate",)
    assert report.streams[0].observed_count == 1


def test_builder_reports_one_gap_only_inside_the_observed_bounds() -> None:
    records = (_candle_at(0), _candle_at(2))

    report = DatasetBuilder(_source()).analyze(records)

    assert report.quality_flags == ("missing_range",)
    assert report.streams[0].expected_count == 3
    assert report.streams[0].observed_count == 2
    assert report.streams[0].first_open_at == _utc("2026-01-01T00:00:00")
    assert report.streams[0].last_close_at == _utc("2026-01-01T00:03:00")
    assert report.missing_ranges == (
        MissingRange(
            first_missing_open_at=_utc("2026-01-01T00:01:00"),
            end_at=_utc("2026-01-01T00:02:00"),
            timeframe="1m",
            missing_bar_count=1,
        ),
    )


def test_builder_reports_multiple_contiguous_missing_ranges() -> None:
    records = tuple(_candle_at(minute) for minute in (0, 3, 4, 7))

    report = DatasetBuilder(_source()).analyze(records)

    assert report.streams[0].expected_count == 8
    assert report.streams[0].observed_count == 4
    assert report.missing_ranges == (
        MissingRange(
            first_missing_open_at=_utc("2026-01-01T00:01:00"),
            end_at=_utc("2026-01-01T00:03:00"),
            timeframe="1m",
            missing_bar_count=2,
        ),
        MissingRange(
            first_missing_open_at=_utc("2026-01-01T00:05:00"),
            end_at=_utc("2026-01-01T00:07:00"),
            timeframe="1m",
            missing_bar_count=2,
        ),
    )


def test_builder_rejects_overlapping_and_off_grid_stream_chronology() -> None:
    overlap = _candle_at(
        0,
        source_record_id="overlap",
        open_at=_utc("2026-01-01T00:00:30"),
        close_at=_utc("2026-01-01T00:01:30"),
        available_at=_utc("2026-01-01T00:01:30"),
    )
    off_grid_gap = _candle_at(
        0,
        source_record_id="off-grid",
        open_at=_utc("2026-01-01T00:02:30"),
        close_at=_utc("2026-01-01T00:03:30"),
        available_at=_utc("2026-01-01T00:03:30"),
    )

    overlap_report = DatasetBuilder(_source()).analyze((_candle_at(0), overlap))
    off_grid_report = DatasetBuilder(_source()).analyze(
        (_candle_at(0), off_grid_gap)
    )

    assert overlap_report.quality_flags == ("stream_overlap",)
    assert off_grid_report.quality_flags == ("invalid_stream_chronology",)


def test_builder_report_and_result_are_stable_for_input_permutations() -> None:
    records = (
        _candle_at(0, symbol="ETHUSDT", timeframe="5m"),
        _candle_at(0),
        _candle_at(1),
    )
    builder = DatasetBuilder(_source())

    forward_report = builder.analyze(records)
    reverse_report = builder.analyze(reversed(records))
    forward_result = builder.build(records)
    reverse_result = builder.build(reversed(records))

    assert forward_report.model_dump(mode="json") == reverse_report.model_dump(mode="json")
    assert forward_result.model_dump(mode="json") == reverse_result.model_dump(mode="json")
