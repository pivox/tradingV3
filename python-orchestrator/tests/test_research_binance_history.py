from __future__ import annotations

import hashlib
import io
import zipfile
from datetime import datetime, timezone

import pytest

from app.backtesting.research.binance_history import (
    ArchiveError,
    parse_archive,
    plan_archives,
    verify_checksum,
)

UTC = timezone.utc


def at(value: str) -> datetime:
    return datetime.fromisoformat(value.replace("Z", "+00:00"))


def zipped(name: str, body: str, *, extra: dict[str, str] | None = None) -> bytes:
    stream = io.BytesIO()
    with zipfile.ZipFile(stream, "w") as archive:
        archive.writestr(name, body)
        for other, content in (extra or {}).items():
            archive.writestr(other, content)
    return stream.getvalue()


def row(open_ms: int, *, close: str = "101") -> str:
    return f"{open_ms},100,102,99,{close},2,{open_ms + 59999},200,3,1,100,0"


def test_plan_full_month_and_two_days_and_funding() -> None:
    start, end = at("2023-01-01T00:00:00Z"), at("2023-02-03T00:00:00Z")
    candles = plan_archives("BTCUSDT", start, end)
    assert [source.filename for source in candles] == [
        "BTCUSDT-1m-2023-01.zip", "BTCUSDT-1m-2023-02-01.zip", "BTCUSDT-1m-2023-02-02.zip"
    ]
    assert candles[0].url == "https://data.binance.vision/data/futures/um/monthly/klines/BTCUSDT/1m/BTCUSDT-1m-2023-01.zip"
    assert candles[0].checksum_url == candles[0].url + ".CHECKSUM"
    funding = plan_archives("BTCUSDT", start, end, kind="funding")
    assert [source.filename for source in funding] == [
        "BTCUSDT-fundingRate-2023-01.zip", "BTCUSDT-fundingRate-2023-02.zip"
    ]
    assert "/monthly/fundingRate/BTCUSDT/" in funding[0].url
    partial = plan_archives("BTCUSDT", at("2023-01-15T12:00:00Z"),
                            at("2023-01-16T00:00:00Z"), kind="funding")
    assert partial[0].start == at("2023-01-01T00:00:00Z")
    assert partial[0].end == at("2023-02-01T00:00:00Z")


@pytest.mark.parametrize("symbol,start,end", [
    ("BADUSDT", at("2023-01-01T00:00:00Z"), at("2023-01-02T00:00:00Z")),
    ("BTCUSDT", datetime(2023, 1, 1), at("2023-01-02T00:00:00Z")),
    ("BTCUSDT", at("2023-01-01T00:00:00Z"), at("2023-01-01T00:00:00Z")),
    ("BTCUSDT", at("2023-01-01T00:00:01Z"), at("2023-01-02T00:00:00Z")),
])
def test_plan_rejects_invalid_ranges(symbol: str, start: datetime, end: datetime) -> None:
    with pytest.raises(ValueError):
        plan_archives(symbol, start, end)


def test_checksum_matches_exact_filename_and_bytes() -> None:
    raw = b"archive"
    digest = hashlib.sha256(raw).hexdigest()
    assert verify_checksum(raw, f"{digest}  BTCUSDT.zip\n", "BTCUSDT.zip") == digest
    with pytest.raises(ArchiveError):
        verify_checksum(raw, f"{digest}  OTHER.zip", "BTCUSDT.zip")
    with pytest.raises(ArchiveError):
        verify_checksum(b"tampered", f"{digest}  BTCUSDT.zip", "BTCUSDT.zip")


def test_parse_headerless_headered_gap_and_closed_cutoff() -> None:
    source = plan_archives("BTCUSDT", at("2023-01-01T00:00:00Z"), at("2023-01-02T00:00:00Z"))[0]
    first = 1672531200000
    body = "\n".join((row(first), row(first + 120000), row(first + 180000)))
    parsed = parse_archive(zipped(source.filename.removesuffix(".zip") + ".csv", body), source,
                           at("2023-01-01T00:00:00Z"), at("2023-01-01T00:03:00Z"))
    assert parsed.count == 2
    assert parsed.gaps == ((first + 60000, first + 120000),)
    assert parsed.first_open == first
    assert parsed.last_close == first + 179999
    header = "open_time,open,high,low,close,volume,close_time,quote_volume,count,taker_buy_volume,taker_buy_quote_volume,ignore"
    assert parse_archive(zipped(source.filename.removesuffix(".zip") + ".csv", header + "\n" + row(first)),
                         source, at("2023-01-01T00:00:00Z"), at("2023-01-01T00:01:00Z")).count == 1


@pytest.mark.parametrize("body", [
    "\n".join((row(1672531200000), row(1672531200000))),
    row(1672531200000, close="NaN"),
    row(1672531200000, close="103"),
    row(1672531200001),
])
def test_parse_rejects_bad_candles(body: str) -> None:
    source = plan_archives("BTCUSDT", at("2023-01-01T00:00:00Z"), at("2023-01-02T00:00:00Z"))[0]
    with pytest.raises(ArchiveError):
        parse_archive(zipped("BTCUSDT-1m-2023-01-01.csv", body), source,
                      at("2023-01-01T00:00:00Z"), at("2023-01-02T00:00:00Z"))


def test_zip_limits_member_and_funding_millisecond_precision() -> None:
    source = plan_archives("BTCUSDT", at("2023-01-01T00:00:00Z"), at("2023-02-01T00:00:00Z"))[0]
    with pytest.raises(ArchiveError):
        parse_archive(zipped("../escape.csv", row(1672531200000)), source, source.start, source.end)
    with pytest.raises(ArchiveError):
        parse_archive(zipped("BTCUSDT-1m-2023-01.csv", row(1672531200000), extra={"other.csv": "x"}),
                      source, source.start, source.end)
    with pytest.raises(ArchiveError):
        parse_archive(zipped("BTCUSDT-1m-2023-01.csv", row(1672531200000)), source,
                      source.start, source.end, max_zip_bytes=10)
    funding = plan_archives("BTCUSDT", source.start, source.end, kind="funding")[0]
    content = "calc_time,funding_interval_hours,last_funding_rate\n1672560000008,8,-0.0001"
    parsed = parse_archive(zipped("BTCUSDT-fundingRate-2023-01.csv", content), funding,
                           source.start, source.end)
    assert parsed.first_open == 1672560000008
    assert parsed.count == 1


def test_funding_signed_fractional_interval_and_order() -> None:
    source = plan_archives("BTCUSDT", at("2023-01-01T00:00:00Z"),
                           at("2023-01-02T00:00:00Z"), kind="funding")[0]
    content = "calc_time,funding_interval_hours,last_funding_rate\n1672560000008,0.5,-0.0001"
    parsed = parse_archive(zipped("BTCUSDT-fundingRate-2023-01.csv", content), source,
                           at("2023-01-01T00:00:00Z"), at("2023-01-02T00:00:00Z"))
    assert parsed.coverage == "observed_only"
    for bad in ("1672560000008,0,-0.001", "1672560000008,8,NaN",
                "1672560000008,8,0.1\n1672560000008,8,0.2"):
        with pytest.raises(ArchiveError):
            parse_archive(zipped("BTCUSDT-fundingRate-2023-01.csv", bad), source,
                          at("2023-01-01T00:00:00Z"), at("2023-01-02T00:00:00Z"))


def test_uncompressed_limit_and_wrong_member() -> None:
    source = plan_archives("BTCUSDT", at("2023-01-01T00:00:00Z"),
                           at("2023-02-01T00:00:00Z"))[0]
    raw = zipped("BTCUSDT-1m-2023-01.csv", row(1672531200000))
    with pytest.raises(ArchiveError):
        parse_archive(raw, source, source.start, source.end, max_uncompressed_bytes=10)
    with pytest.raises(ArchiveError):
        parse_archive(zipped("other.csv", row(1672531200000)), source,
                      source.start, source.end)


def test_checksum_format_and_kline_numeric_rules() -> None:
    raw = b"data"
    digest = hashlib.sha256(raw).hexdigest()
    for checksum in ("", f"{digest}  BTCUSDT.zip\n{digest}  BTCUSDT.zip",
                     f"{digest}  ../BTCUSDT.zip", f"{digest}  BTCUSDT.zip/evil"):
        with pytest.raises(ArchiveError):
            verify_checksum(raw, checksum, "BTCUSDT.zip")
    source = plan_archives("BTCUSDT", at("2023-01-01T00:00:00Z"),
                           at("2023-01-02T00:00:00Z"))[0]
    opening = 1672531200000
    good = row(opening).split(",")
    variants = [
        good[:-1],
        ["bad", *good[1:]],
        [*good[:6], str(opening + 60000), *good[7:]],
        [*good[:8], "-1", *good[9:]],
        [*good[:1], "0", *good[2:]],
        [*good[:2], "98", *good[3:]],
        [*good[:3], "103", *good[4:]],
        [*good[:5], "-1", *good[6:]],
        [*good[:7], "NaN", *good[8:]],
    ]
    for fields in variants:
        with pytest.raises(ArchiveError):
            parse_archive(zipped("BTCUSDT-1m-2023-01-01.csv", ",".join(fields)), source,
                          at("2023-01-01T00:00:00Z"), at("2023-01-02T00:00:00Z"))


def test_funding_invalid_timestamp_fields_and_clipping() -> None:
    source = plan_archives("BTCUSDT", at("2023-01-01T00:00:00Z"),
                           at("2023-01-02T00:00:00Z"), kind="funding")[0]
    for content in ("a,8,0.1", "-1,8,0.1", "1672560000000,bad,0.1",
                    "1672560000000,8", "1672560000000,8,0.1\n1672560000000,8,0.2"):
        with pytest.raises(ArchiveError):
            parse_archive(zipped("BTCUSDT-fundingRate-2023-01.csv", content), source,
                          at("2023-01-01T00:00:00Z"), at("2023-01-02T00:00:00Z"))
    parsed = parse_archive(zipped("BTCUSDT-fundingRate-2023-01.csv", "1672560000008,8,-0.1"),
                           source, at("2023-01-01T00:00:00Z"), at("2023-01-01T00:01:00Z"))
    assert parsed.count == 0 and parsed.coverage == "empty"


def test_csv_late_header_and_invalid_zip_bytes() -> None:
    source = plan_archives("BTCUSDT", at("2023-01-01T00:00:00Z"),
                           at("2023-01-02T00:00:00Z"))[0]
    header = ",".join(("open_time", "open", "high", "low", "close", "volume", "close_time",
                       "quote_volume", "count", "taker_buy_volume", "taker_buy_quote_volume", "ignore"))
    with pytest.raises(ArchiveError):
        parse_archive(zipped("BTCUSDT-1m-2023-01-01.csv", row(1672531200000) + "\n" + header),
                      source, source.start, source.end)
    with pytest.raises(ArchiveError):
        parse_archive(b"broken", source, source.start, source.end)
