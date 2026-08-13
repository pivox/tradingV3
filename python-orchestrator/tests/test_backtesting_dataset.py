from __future__ import annotations

import hashlib
import json
from datetime import datetime, timedelta, timezone
from pathlib import Path

import pytest
from pydantic import ValidationError

from app.backtesting.contracts import DatasetDescriptor, MarketType
from app.backtesting.dataset import (
    CandleRecord,
    DatasetArtifactVerificationError,
    DatasetArtifacts,
    DatasetBuilder,
    DatasetBuildRejected,
    DatasetBuildResult,
    DatasetQualityReport,
    DatasetSourceIdentity,
    DatasetStreamQuality,
    DatasetSerializer,
    MissingRange,
    Timeframe,
)


_FIXTURE_DIR = Path(__file__).parent / "fixtures" / "backtesting"


def _utc(value: str) -> datetime:
    return datetime.fromisoformat(value).replace(tzinfo=timezone.utc)


def _candle(**overrides: object) -> CandleRecord:
    payload: dict[str, object] = {
        "schema_version": "backtest-candle.v1",
        "source_record_id": "fake:BTCUSDT:1m:2026-01-01T00:00:00Z",
        "source_network": "fake",
        "market_data_venue": "fake",
        "market_type": MarketType.PERPETUAL,
        "symbol": "BTCUSDT",
        "timeframe": Timeframe.ONE_MINUTE,
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
    if isinstance(payload["market_type"], str):
        payload["market_type"] = MarketType(payload["market_type"])
    if isinstance(payload["timeframe"], str):
        try:
            payload["timeframe"] = Timeframe(payload["timeframe"])
        except ValueError:
            pass
    return CandleRecord(**payload)


def _source() -> DatasetSourceIdentity:
    return DatasetSourceIdentity(
        source="paper-fixture",
        source_schema_version="paper-market-events.v1",
        source_build_version="paper-exporter.v3",
        source_checksum="sha256:" + "a" * 64,
        source_network="fake",
        market_data_venue="fake",
        market_type=MarketType.PERPETUAL,
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


def _golden_records() -> tuple[CandleRecord, ...]:
    return (
        _candle_at(0),
        _candle_at(
            1,
            open="101",
            high="103",
            low="100",
            close="102",
            volume="2.5",
        ),
        _candle_at(
            0,
            symbol="ETHUSDT",
            timeframe="5m",
            open="200",
            high="205",
            low="198",
            close="204",
            volume="12.25",
            available_at=_utc("2026-01-01T00:05:02"),
        ),
    )


def _sha256(payload: bytes) -> str:
    return "sha256:" + hashlib.sha256(payload).hexdigest()


def _canonical_json(payload: object) -> bytes:
    return json.dumps(
        payload,
        ensure_ascii=False,
        separators=(",", ":"),
        sort_keys=True,
    ).encode("utf-8")


def _fixture_artifacts() -> DatasetArtifacts:
    manifest_json = (_FIXTURE_DIR / "manifest-v1.json").read_bytes()
    return DatasetArtifacts(
        candles_ndjson=(_FIXTURE_DIR / "candles-v1.ndjson").read_bytes(),
        quality_report_json=(_FIXTURE_DIR / "quality-report-v1.json").read_bytes(),
        manifest_json=manifest_json,
        descriptor=DatasetDescriptor.from_manifest(json.loads(manifest_json)),
    )


def _bind_manifest(manifest: dict[str, object]) -> dict[str, object]:
    core = {
        key: value
        for key, value in manifest.items()
        if key not in {"artifacts", "dataset_checksum", "dataset_id"}
    }
    manifest["dataset_checksum"] = _sha256(
        _canonical_json(
            {
                "candles_checksum": manifest["artifacts"]["candles.ndjson"],  # type: ignore[index]
                "manifest_core": core,
                "quality_report_checksum": manifest["artifacts"][
                    "quality-report.json"
                ],  # type: ignore[index]
            }
        )
    )
    manifest["dataset_id"] = "backtest-dataset-" + manifest[
        "dataset_checksum"
    ].removeprefix("sha256:")  # type: ignore[union-attr]
    return manifest


def _rebind_artifacts(
    artifacts: DatasetArtifacts,
    *,
    candles_ndjson: bytes | None = None,
    quality_report_json: bytes | None = None,
    manifest_updates: dict[str, object] | None = None,
) -> DatasetArtifacts:
    candles = artifacts.candles_ndjson if candles_ndjson is None else candles_ndjson
    report = (
        artifacts.quality_report_json
        if quality_report_json is None
        else quality_report_json
    )
    manifest = json.loads(artifacts.manifest_json)
    if manifest_updates:
        manifest.update(manifest_updates)
    manifest["artifacts"] = {
        "candles.ndjson": _sha256(candles),
        "quality-report.json": _sha256(report),
    }
    _bind_manifest(manifest)
    return DatasetArtifacts(
        candles_ndjson=candles,
        quality_report_json=report,
        manifest_json=_canonical_json(manifest) + b"\n",
        descriptor=DatasetDescriptor.from_manifest(manifest),
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
    ("timeframe", "opened"),
    (
        ("1m", _utc("2026-01-01T00:00:30")),
        ("5m", _utc("2026-01-01T00:01:00")),
        ("1h", _utc("2026-01-01T00:30:00")),
    ),
)
def test_candle_open_must_align_to_the_utc_timeframe_grid(
    timeframe: str,
    opened: datetime,
) -> None:
    duration = Timeframe(timeframe).duration

    with pytest.raises(ValidationError, match="open_at must align to UTC timeframe grid"):
        _candle(
            timeframe=timeframe,
            open_at=opened,
            close_at=opened + duration,
            available_at=opened + duration,
        )


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
        timeframe=Timeframe.ONE_MINUTE,
        missing_bar_count=1,
    )
    stream = DatasetStreamQuality(
        market_data_venue="fake",
        market_type=MarketType.PERPETUAL,
        symbol="BTCUSDT",
        timeframe=Timeframe.ONE_MINUTE,
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


def test_dataset_contracts_reject_python_coercions_but_accept_canonical_json() -> None:
    candle = _candle()
    source = _source()

    for contract in (
        CandleRecord,
        DatasetSourceIdentity,
        MissingRange,
        DatasetStreamQuality,
        DatasetQualityReport,
        DatasetBuildResult,
    ):
        assert contract.model_config.get("strict") is True

    with pytest.raises(ValidationError):
        CandleRecord(**{**candle.model_dump(), "open_at": "2026-01-01T00:00:00Z"})
    with pytest.raises(ValidationError):
        DatasetSourceIdentity(**{**source.model_dump(), "source": b"paper-fixture"})
    with pytest.raises(ValidationError):
        DatasetQualityReport(
            input_count=True,
            accepted_count=0,
            exact_duplicate_count=0,
            conflicting_duplicate_count=0,
        )
    with pytest.raises(ValidationError):
        DatasetQualityReport(
            input_count=1.0,
            accepted_count=0,
            exact_duplicate_count=0,
            conflicting_duplicate_count=0,
        )

    assert CandleRecord.model_validate_json(candle.model_dump_json()) == candle


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


def test_source_mismatch_is_reported_independently_from_a_mixed_dimension() -> None:
    records = (
        _candle_at(0, source_network="testnet"),
        _candle_at(1, source_network="paper"),
    )

    report = DatasetBuilder(_source()).analyze(records)

    assert report.quality_flags == (
        "mixed_source_network",
        "source_identity_mismatch",
    )


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


@pytest.mark.parametrize(
    ("variant_names", "expected_exact", "expected_conflicting", "expected_flags"),
    (
        (("a", "a", "b"), 1, 1, ("exact_duplicate", "conflicting_duplicate")),
        (("a", "a", "a"), 2, 0, ("exact_duplicate",)),
        (("a", "b", "c"), 0, 2, ("conflicting_duplicate",)),
    ),
)
def test_builder_counts_exact_repetitions_and_conflicting_variants_separately(
    variant_names: tuple[str, ...],
    expected_exact: int,
    expected_conflicting: int,
    expected_flags: tuple[str, ...],
) -> None:
    variants = {
        "a": _candle_at(0, source_record_id="source-a", close="101"),
        "b": _candle_at(0, source_record_id="source-b", close="100.5"),
        "c": _candle_at(0, source_record_id="source-c", close="100.75"),
    }
    records = tuple(variants[name] for name in variant_names)
    builder = DatasetBuilder(_source())

    report = builder.analyze(records)
    reversed_report = builder.analyze(reversed(records))

    assert report.exact_duplicate_count == expected_exact
    assert report.conflicting_duplicate_count == expected_conflicting
    assert report.quality_flags == expected_flags
    assert report == reversed_report


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
            timeframe=Timeframe.ONE_MINUTE,
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
            timeframe=Timeframe.ONE_MINUTE,
            missing_bar_count=2,
        ),
        MissingRange(
            first_missing_open_at=_utc("2026-01-01T00:05:00"),
            end_at=_utc("2026-01-01T00:07:00"),
            timeframe=Timeframe.ONE_MINUTE,
            missing_bar_count=2,
        ),
    )


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


@pytest.mark.parametrize("forgery", ("counts", "streams", "source"))
def test_build_result_rejects_forged_quality_evidence(forgery: str) -> None:
    result = DatasetBuilder(_source()).build((_candle_at(0), _candle_at(1)))
    payload = result.model_dump()

    if forgery == "counts":
        payload["quality_report"] = result.quality_report.model_copy(
            update={"input_count": 999, "accepted_count": 999}
        )
    elif forgery == "streams":
        payload["quality_report"] = result.quality_report.model_copy(
            update={"streams": ()}
        )
    else:
        payload["records"] = (
            _candle_at(0, source_network="unexpected"),
            _candle_at(1, source_network="unexpected"),
        )

    with pytest.raises(ValidationError, match="quality report must match records"):
        DatasetBuildResult(**payload)


def test_build_result_rejects_forged_record_order() -> None:
    result = DatasetBuilder(_source()).build(_golden_records())

    with pytest.raises(ValidationError, match="records must use canonical order"):
        DatasetBuildResult(**{**result.model_dump(), "records": tuple(reversed(result.records))})


def test_serializer_matches_golden_bytes_and_is_permutation_invariant() -> None:
    builder = DatasetBuilder(_source())
    forward = DatasetSerializer.serialize(builder.build(_golden_records()))
    reverse = DatasetSerializer.serialize(builder.build(reversed(_golden_records())))

    assert forward == reverse
    assert forward.candles_ndjson == (_FIXTURE_DIR / "candles-v1.ndjson").read_bytes()
    assert forward.quality_report_json == (
        _FIXTURE_DIR / "quality-report-v1.json"
    ).read_bytes()
    assert forward.manifest_json == (_FIXTURE_DIR / "manifest-v1.json").read_bytes()
    for payload in (
        forward.candles_ndjson,
        forward.quality_report_json,
        forward.manifest_json,
    ):
        payload.decode("utf-8")
        assert payload.endswith(b"\n")
        assert not payload.endswith(b"\n\n")


def test_manifest_checksum_graph_recomputes_from_exact_fixture_bytes() -> None:
    candles = (_FIXTURE_DIR / "candles-v1.ndjson").read_bytes()
    report = (_FIXTURE_DIR / "quality-report-v1.json").read_bytes()
    manifest_bytes = (_FIXTURE_DIR / "manifest-v1.json").read_bytes()
    manifest = json.loads(manifest_bytes)

    assert manifest["artifacts"] == {
        "candles.ndjson": _sha256(candles),
        "quality-report.json": _sha256(report),
    }
    manifest_core = {
        key: value
        for key, value in manifest.items()
        if key not in {"artifacts", "dataset_checksum", "dataset_id"}
    }
    checksum_payload = _canonical_json(
        {
            "candles_checksum": manifest["artifacts"]["candles.ndjson"],
            "manifest_core": manifest_core,
            "quality_report_checksum": manifest["artifacts"]["quality-report.json"],
        }
    )
    expected_dataset_checksum = _sha256(checksum_payload)

    assert manifest["dataset_checksum"] == expected_dataset_checksum
    assert manifest["dataset_id"] == (
        "backtest-dataset-" + expected_dataset_checksum.removeprefix("sha256:")
    )
    assert manifest_bytes == _canonical_json(manifest) + b"\n"


def test_descriptor_is_derived_from_and_agrees_with_manifest_facts() -> None:
    artifacts = DatasetSerializer.serialize(
        DatasetBuilder(_source()).build(_golden_records())
    )
    manifest = json.loads(artifacts.manifest_json)
    descriptor = artifacts.descriptor

    assert descriptor == DatasetDescriptor.from_manifest(manifest)
    assert descriptor.schema_version == "backtest-dataset-manifest.v1"
    assert descriptor.record_schema_version == "backtest-candle.v1"
    assert descriptor.quality_report_schema_version == "backtest-dataset-quality.v1"
    assert descriptor.build_version == "backtest-dataset-builder.v1"
    assert descriptor.source == "paper-fixture"
    assert descriptor.source_schema_version == "paper-market-events.v1"
    assert descriptor.source_build_version == "paper-exporter.v3"
    assert descriptor.source_checksum == "sha256:" + "a" * 64
    assert descriptor.source_network == "fake"
    assert descriptor.market_data_venue == "fake"
    assert descriptor.market_type is MarketType.PERPETUAL
    assert descriptor.symbols == ("BTCUSDT", "ETHUSDT")
    assert descriptor.timeframes == ("1m", "5m")
    assert descriptor.start_at == _utc("2026-01-01T00:00:00")
    assert descriptor.end_at == _utc("2026-01-01T00:05:00")
    assert descriptor.record_count == 3
    assert descriptor.candles_checksum == manifest["artifacts"]["candles.ndjson"]
    assert descriptor.quality_report_checksum == (
        manifest["artifacts"]["quality-report.json"]
    )
    assert descriptor.quality_flags == ()
    assert descriptor.dataset_checksum == manifest["dataset_checksum"]

    for contract in (DatasetArtifacts, DatasetDescriptor):
        assert set(contract.model_fields).isdisjoint(
            {"profile", "mode", "mode_id", "setup", "setup_id", "alias"}
        )
    assert "generated_at" not in manifest
    assert "created_at" not in manifest


def test_descriptor_constructor_rejects_facts_not_bound_by_checksum() -> None:
    descriptor = DatasetSerializer.serialize(
        DatasetBuilder(_source()).build(_golden_records())
    ).descriptor

    with pytest.raises(ValidationError, match="dataset checksum does not bind descriptor facts"):
        DatasetDescriptor(
            **{**descriptor.model_dump(), "source_build_version": "forged.v1"}
        )


@pytest.mark.parametrize(
    ("path", "replacement"),
    (
        (("coverage", "record_count"), 4),
        (("coverage", "end_at"), "2026-01-01T00:06:00.000000Z"),
        (("source", "source_network"), "testnet"),
    ),
)
def test_descriptor_rejects_manifest_facts_not_bound_by_dataset_checksum(
    path: tuple[str, str], replacement: object
) -> None:
    manifest = json.loads((_FIXTURE_DIR / "manifest-v1.json").read_bytes())
    manifest[path[0]][path[1]] = replacement

    with pytest.raises(ValueError, match="dataset checksum does not bind manifest"):
        DatasetDescriptor.from_manifest(manifest)


@pytest.mark.parametrize("record_count", (True, "3", 3.0))
def test_descriptor_rejects_coerced_manifest_record_count(record_count: object) -> None:
    manifest = json.loads((_FIXTURE_DIR / "manifest-v1.json").read_bytes())
    manifest["coverage"]["record_count"] = record_count

    with pytest.raises(ValueError, match="record_count must be an integer"):
        DatasetDescriptor.from_manifest(manifest)


@pytest.mark.parametrize("forgery", ("order", "duplicate", "flattened"))
def test_descriptor_rejects_stream_coverage_forgery_with_rebound_checksum(
    forgery: str,
) -> None:
    manifest = json.loads((_FIXTURE_DIR / "manifest-v1.json").read_bytes())
    streams = manifest["coverage"]["streams"]
    if forgery == "order":
        manifest["coverage"]["streams"] = list(reversed(streams))
    elif forgery == "duplicate":
        manifest["coverage"]["streams"] = [streams[0], streams[0]]
        manifest["coverage"]["symbols"] = ["BTCUSDT"]
        manifest["coverage"]["timeframes"] = ["1m"]
        manifest["coverage"]["end_at"] = streams[0]["last_close_at"]
        manifest["coverage"]["record_count"] = 4
    else:
        manifest["coverage"]["symbols"] = ["BTCUSDT"]
    _bind_manifest(manifest)

    with pytest.raises((ValueError, ValidationError), match="streams|derive"):
        DatasetDescriptor.from_manifest(manifest)


def test_manifest_stream_coverage_is_exact_and_checksum_bound() -> None:
    artifacts = DatasetSerializer.serialize(
        DatasetBuilder(_source()).build(_golden_records())
    )
    manifest = json.loads(artifacts.manifest_json)

    assert manifest["coverage"]["streams"] == [
        {
            "first_open_at": "2026-01-01T00:00:00.000000Z",
            "last_close_at": "2026-01-01T00:02:00.000000Z",
            "market_data_venue": "fake",
            "market_type": "perpetual",
            "record_count": 2,
            "symbol": "BTCUSDT",
            "timeframe": "1m",
        },
        {
            "first_open_at": "2026-01-01T00:00:00.000000Z",
            "last_close_at": "2026-01-01T00:05:00.000000Z",
            "market_data_venue": "fake",
            "market_type": "perpetual",
            "record_count": 1,
            "symbol": "ETHUSDT",
            "timeframe": "5m",
        },
    ]
    forged = json.loads(artifacts.manifest_json)
    forged["coverage"]["streams"][0]["record_count"] = 99
    with pytest.raises(ValueError, match="dataset checksum does not bind manifest"):
        DatasetDescriptor.from_manifest(forged)


def test_verifier_rejects_reordered_candles_with_fully_recomputed_graph() -> None:
    artifacts = DatasetSerializer.serialize(
        DatasetBuilder(_source()).build(_golden_records())
    )
    lines = artifacts.candles_ndjson.rstrip(b"\n").split(b"\n")
    reordered_candles = b"\n".join(reversed(lines)) + b"\n"
    manifest = json.loads(artifacts.manifest_json)
    manifest["artifacts"]["candles.ndjson"] = _sha256(reordered_candles)
    core = {
        key: value
        for key, value in manifest.items()
        if key not in {"artifacts", "dataset_checksum", "dataset_id"}
    }
    manifest["dataset_checksum"] = _sha256(
        _canonical_json(
            {
                "candles_checksum": manifest["artifacts"]["candles.ndjson"],
                "manifest_core": core,
                "quality_report_checksum": manifest["artifacts"][
                    "quality-report.json"
                ],
            }
        )
    )
    manifest["dataset_id"] = "backtest-dataset-" + manifest[
        "dataset_checksum"
    ].removeprefix("sha256:")
    forged = DatasetArtifacts(
        candles_ndjson=reordered_candles,
        quality_report_json=artifacts.quality_report_json,
        manifest_json=_canonical_json(manifest) + b"\n",
        descriptor=DatasetDescriptor.from_manifest(manifest),
    )

    with pytest.raises(DatasetArtifactVerificationError):
        DatasetSerializer.verify(forged)


@pytest.mark.parametrize(
    "artifact_name",
    ("candles_ndjson", "quality_report_json", "manifest_json", "descriptor"),
)
def test_cross_verification_rejects_any_tampered_artifact(
    artifact_name: str,
) -> None:
    artifacts = DatasetSerializer.serialize(
        DatasetBuilder(_source()).build(_golden_records())
    )
    if artifact_name == "descriptor":
        replacement: object = artifacts.descriptor.model_copy(
            update={"record_count": 999}
        )
    else:
        replacement = getattr(artifacts, artifact_name) + b" "
    tampered = artifacts.model_copy(update={artifact_name: replacement})

    with pytest.raises(DatasetArtifactVerificationError):
        DatasetSerializer.verify(tampered)


def test_quality_contracts_reject_inconsistent_bounds_and_flags() -> None:
    missing = MissingRange(
        first_missing_open_at=_utc("2026-01-01T00:01:00"),
        end_at=_utc("2026-01-01T00:02:00"),
        timeframe=Timeframe.ONE_MINUTE,
        missing_bar_count=1,
    )
    with pytest.raises(ValidationError, match="duration must match"):
        MissingRange(
            **{
                **missing.model_dump(),
                "end_at": _utc("2026-01-01T00:03:00"),
            }
        )
    with pytest.raises(ValidationError, match="last_close_at must follow"):
        DatasetStreamQuality(
            market_data_venue="fake",
            market_type=MarketType.PERPETUAL,
            symbol="BTCUSDT",
            timeframe=Timeframe.ONE_MINUTE,
            first_open_at=_utc("2026-01-01T00:02:00"),
            last_close_at=_utc("2026-01-01T00:01:00"),
            expected_count=1,
            observed_count=1,
        )
    with pytest.raises(ValidationError, match="timeframe must match"):
        DatasetStreamQuality(
            market_data_venue="fake",
            market_type=MarketType.PERPETUAL,
            symbol="BTCUSDT",
            timeframe=Timeframe.FIVE_MINUTES,
            first_open_at=_utc("2026-01-01T00:00:00"),
            last_close_at=_utc("2026-01-01T00:05:00"),
            expected_count=1,
            observed_count=1,
            missing_ranges=(missing,),
        )
    for flags, message in (
        ((" ",), "must not be blank"),
        (("missing_range", "missing_range"), "must be unique"),
    ):
        with pytest.raises(ValidationError, match=message):
            DatasetQualityReport(
                input_count=0,
                accepted_count=0,
                exact_duplicate_count=0,
                conflicting_duplicate_count=0,
                quality_flags=flags,
            )


def test_builder_reports_overlap_and_non_integral_stream_chronology() -> None:
    first = _candle_at(0)
    overlap_open = first.open_at + timedelta(seconds=30)
    overlap = first.model_copy(
        update={
            "source_record_id": "overlap",
            "open_at": overlap_open,
            "close_at": overlap_open + Timeframe.ONE_MINUTE.duration,
            "available_at": overlap_open + Timeframe.ONE_MINUTE.duration,
        }
    )
    irregular_open = first.open_at + timedelta(seconds=90)
    irregular = first.model_copy(
        update={
            "source_record_id": "irregular",
            "open_at": irregular_open,
            "close_at": irregular_open + Timeframe.ONE_MINUTE.duration,
            "available_at": irregular_open + Timeframe.ONE_MINUTE.duration,
        }
    )

    assert "stream_overlap" in DatasetBuilder(_source()).analyze((first, overlap)).quality_flags
    assert "invalid_stream_chronology" in DatasetBuilder(_source()).analyze(
        (first, irregular)
    ).quality_flags
    with pytest.raises(TypeError, match="only CandleRecord"):
        DatasetBuilder(_source()).analyze((first, object()))  # type: ignore[arg-type]


@pytest.mark.parametrize(
    ("update", "message"),
    (
        ({"record_count": 99}, "record_count must equal"),
        ({"start_at": _utc("2025-12-31T23:59:00")}, "first record bound"),
        ({"end_at": _utc("2026-01-01T01:00:00")}, "last record bound"),
        ({"symbols": ("FORGED",)}, "canonical record symbols"),
        ({"timeframes": (Timeframe.FIVE_MINUTES,)}, "canonical record timeframes"),
    ),
)
def test_build_result_rejects_forged_derived_facts(
    update: dict[str, object],
    message: str,
) -> None:
    forged = DatasetBuilder(_source()).build(_golden_records()).model_copy(update=update)

    with pytest.raises(ValueError, match=message):
        forged._validate_derived_facts()


def test_build_result_rejects_an_ineligible_recomputed_report() -> None:
    records = (_candle_at(0), _candle_at(2))
    report = DatasetBuilder(_source()).analyze(records)
    forged = DatasetBuildResult.model_construct(
        source_identity=_source(),
        records=records,
        quality_report=report,
        symbols=("BTCUSDT",),
        timeframes=(Timeframe.ONE_MINUTE,),
        start_at=records[0].open_at,
        end_at=records[-1].close_at,
        record_count=2,
    )

    with pytest.raises(ValueError, match="requires an eligible quality report"):
        forged._validate_derived_facts()


def test_serializer_rejects_wrong_types_and_noncanonical_json() -> None:
    with pytest.raises(TypeError, match="only DatasetBuildResult"):
        DatasetSerializer.serialize(object())  # type: ignore[arg-type]
    with pytest.raises(DatasetArtifactVerificationError):
        DatasetSerializer.verify(object())  # type: ignore[arg-type]

    noncanonical_manifest = _fixture_artifacts().model_copy(
        update={"manifest_json": b'{"not": "canonical"}\n'}
    )
    with pytest.raises(DatasetArtifactVerificationError):
        DatasetSerializer.verify(noncanonical_manifest)

    scalar_manifest = _fixture_artifacts().model_copy(update={"manifest_json": b"[]\n"})
    with pytest.raises(DatasetArtifactVerificationError):
        DatasetSerializer.verify(scalar_manifest)


@pytest.mark.parametrize(
    "candles",
    (
        b"not-newline-terminated",
        b"\n",
        b'{"available_at": "not-canonical"}\n',
    ),
)
def test_verifier_rejects_rebound_noncanonical_candle_files(candles: bytes) -> None:
    forged = _rebind_artifacts(_fixture_artifacts(), candles_ndjson=candles)

    with pytest.raises(DatasetArtifactVerificationError):
        DatasetSerializer.verify(forged)


def test_verifier_rejects_rebound_report_and_manifest_semantic_forgery() -> None:
    artifacts = _fixture_artifacts()
    report = json.loads(artifacts.quality_report_json)
    report["input_count"] = 999
    forged_report = _rebind_artifacts(
        artifacts,
        quality_report_json=_canonical_json(report) + b"\n",
    )
    with pytest.raises(DatasetArtifactVerificationError):
        DatasetSerializer.verify(forged_report)

    forged_manifest = _rebind_artifacts(
        artifacts,
        manifest_updates={"build_version": "forged-builder.v1"},
    )
    with pytest.raises(DatasetArtifactVerificationError):
        DatasetSerializer.verify(forged_manifest)


def test_verifier_preserves_stable_verification_error(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    artifacts = _fixture_artifacts()

    def reject_manifest(*args: object, **kwargs: object) -> DatasetDescriptor:
        raise DatasetArtifactVerificationError()

    monkeypatch.setattr(DatasetDescriptor, "from_manifest", reject_manifest)

    with pytest.raises(DatasetArtifactVerificationError):
        DatasetSerializer.verify(artifacts)
