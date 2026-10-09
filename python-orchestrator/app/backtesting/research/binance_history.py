"""Pure Binance archive planning and bounded market-data validation."""

from __future__ import annotations

import csv
import hashlib
import io
import re
import zipfile
from dataclasses import dataclass
from datetime import datetime, timedelta
from decimal import Decimal, InvalidOperation

SYMBOLS = ("BTCUSDT", "ETHUSDT", "BNBUSDT", "XRPUSDT", "ADAUSDT", "DOGEUSDT",
           "SOLUSDT", "LTCUSDT", "LINKUSDT", "AVAXUSDT")
SOURCE = "binance/usds_m_perpetual/1m"
MAX_ZIP_BYTES = 64 * 1024 * 1024
MAX_UNCOMPRESSED_BYTES = 128 * 1024 * 1024
KLINE_HEADER = ("open_time", "open", "high", "low", "close", "volume", "close_time",
                "quote_volume", "count", "taker_buy_volume", "taker_buy_quote_volume", "ignore")
FUNDING_HEADER = ("calc_time", "funding_interval_hours", "last_funding_rate")


class ArchiveError(ValueError):
    """An archive failed verification or format checks."""


@dataclass(frozen=True)
class ArchiveSource:
    symbol: str
    kind: str
    start: datetime
    end: datetime
    filename: str
    url: str
    checksum_url: str


@dataclass(frozen=True)
class ParsedArchive:
    count: int
    first_open: int | None
    last_close: int | None
    gaps: tuple[tuple[int, int], ...]
    coverage: str


def _utc_minute(value: datetime) -> None:
    if value.tzinfo is None or value.utcoffset() != timedelta(0) or value.second or value.microsecond:
        raise ValueError("range must use UTC minute boundaries")


def _next_month(value: datetime) -> datetime:
    return value.replace(year=value.year + (value.month == 12), month=value.month % 12 + 1, day=1)


def plan_archives(symbol: str, start_UTC: datetime, end_exclusive_UTC: datetime,
                  kind: str = "klines") -> list[ArchiveSource]:
    if symbol not in SYMBOLS or kind not in {"klines", "funding"}:
        raise ValueError("unsupported symbol or kind")
    _utc_minute(start_UTC)
    _utc_minute(end_exclusive_UTC)
    if start_UTC >= end_exclusive_UTC:
        raise ValueError("empty range")
    base = "https://data.binance.vision/data/futures/um"
    result: list[ArchiveSource] = []
    day = start_UTC.replace(hour=0, minute=0)
    while day < end_exclusive_UTC:
        month_start = day.replace(day=1)
        month_end = _next_month(month_start)
        monthly = kind == "funding" or (day == month_start and month_end <= end_exclusive_UTC)
        period_end = month_end if monthly else day + timedelta(days=1)
        suffix = day.strftime("%Y-%m" if monthly else "%Y-%m-%d")
        if kind == "klines":
            filename = f"{symbol}-1m-{suffix}.zip"
            folder = f"{'monthly' if monthly else 'daily'}/klines/{symbol}/1m"
        else:
            filename = f"{symbol}-fundingRate-{suffix}.zip"
            folder = f"monthly/fundingRate/{symbol}"
        url = f"{base}/{folder}/{filename}"
        period_start = month_start if kind == "funding" else day
        result.append(ArchiveSource(symbol, kind, period_start, period_end, filename,
                                    url, url + ".CHECKSUM"))
        day = period_end
    return result


def verify_checksum(raw: bytes, checksum_text: str, filename: str) -> str:
    digest = hashlib.sha256(raw).hexdigest()
    lines = checksum_text.strip().splitlines()
    if len(lines) != 1:
        raise ArchiveError("checksum format")
    match = re.fullmatch(r"([0-9a-fA-F]{64})\s+\*?([^\s/\\]+)", lines[0].strip())
    if match is None or match.group(2) != filename or match.group(1).lower() != digest:
        raise ArchiveError("checksum mismatch")
    return digest


def _decimal(value: str, *, positive: bool = False, nonnegative: bool = False) -> Decimal:
    try:
        number = Decimal(value)
    except (InvalidOperation, TypeError) as exc:
        raise ArchiveError("invalid number") from exc
    if not number.is_finite() or (positive and number <= 0) or (nonnegative and number < 0):
        raise ArchiveError("invalid number")
    return number


def _integer(value: object) -> int:
    if type(value) is int:
        return value
    if isinstance(value, str) and re.fullmatch(r"[0-9]+", value):
        try:
            return int(value)
        except ValueError as exc:
            raise ArchiveError("integer field exceeds limit") from exc
    raise ArchiveError("integer field required")


def validate_kline(row: list[str] | tuple[object, ...]) -> tuple[int, int]:
    if not isinstance(row, (list, tuple)) or len(row) != 12:
        raise ArchiveError("kline field count")
    opening, closing, trades = (_integer(row[i]) for i in (0, 6, 8))
    if opening < 0 or opening % 60000 or closing != opening + 59999 or trades < 0:
        raise ArchiveError("kline timestamp or trades")
    open_price, high, low, close = (_decimal(str(row[i]), positive=True) for i in (1, 2, 3, 4))
    if low > high or not (low <= open_price <= high and low <= close <= high):
        raise ArchiveError("incoherent OHLC")
    for index in (5, 7, 9, 10):
        _decimal(str(row[index]), nonnegative=True)
    return opening, closing


def _validate_funding(row: list[str]) -> tuple[int, int]:
    if len(row) != 3:
        raise ArchiveError("funding field count")
    try:
        timestamp = int(row[0])
    except ValueError as exc:
        raise ArchiveError("funding timestamp") from exc
    if timestamp < 0:
        raise ArchiveError("funding interval")
    _decimal(row[1], positive=True)
    _decimal(row[2])
    return timestamp, timestamp


def summarize_rows(rows: list[tuple[int, int]], start: datetime, end: datetime,
                   kind: str) -> ParsedArchive:
    start_ms, end_ms = int(start.timestamp() * 1000), int(end.timestamp() * 1000)
    selected: list[tuple[int, int]] = []
    previous: int | None = None
    for opening, closing in rows:
        if previous is not None and opening <= previous:
            raise ArchiveError("duplicate or out-of-order timestamp")
        previous = opening
        if opening >= start_ms and closing < end_ms:
            selected.append((opening, closing))
    gaps = tuple((left[0] + 60000, right[0]) for left, right in zip(selected, selected[1:])
                 if kind == "klines" and right[0] > left[0] + 60000)
    if kind == "funding":
        coverage = "observed_only" if selected else "empty"
    else:
        coverage = ("complete" if selected and selected[0][0] == start_ms
                    and selected[-1][1] == end_ms - 1 and not gaps else "incomplete")
    return ParsedArchive(len(selected), selected[0][0] if selected else None,
                         selected[-1][1] if selected else None, gaps, coverage)


def parse_archive(raw: bytes, source: ArchiveSource, start: datetime, end: datetime,
                  *, max_zip_bytes: int = MAX_ZIP_BYTES,
                  max_uncompressed_bytes: int = MAX_UNCOMPRESSED_BYTES) -> ParsedArchive:
    if len(raw) > max_zip_bytes:
        raise ArchiveError("ZIP size limit")
    expected = source.filename.removesuffix(".zip") + ".csv"
    try:
        with zipfile.ZipFile(io.BytesIO(raw)) as archive:
            members = archive.infolist()
            if len(members) != 1 or members[0].filename != expected or members[0].file_size > max_uncompressed_bytes:
                raise ArchiveError("ZIP member or size limit")
            with archive.open(members[0]) as binary:
                reader = csv.reader(io.TextIOWrapper(binary, encoding="utf-8-sig", newline=""))
                rows: list[tuple[int, int]] = []
                total = 0
                for row in reader:
                    total += sum(len(field.encode("utf-8")) for field in row) + len(row)
                    if total > max_uncompressed_bytes:
                        raise ArchiveError("uncompressed size limit")
                    if not row:
                        continue
                    if source.kind == "klines" and tuple(row) == KLINE_HEADER:
                        if rows:
                            raise ArchiveError("late header")
                        continue
                    if source.kind == "funding" and tuple(row) == FUNDING_HEADER:
                        if rows:
                            raise ArchiveError("late header")
                        continue
                    rows.append(validate_kline(row) if source.kind == "klines" else _validate_funding(row))
                return summarize_rows(rows, start, end, source.kind)
    except (zipfile.BadZipFile, UnicodeError, csv.Error, RuntimeError, EOFError) as exc:
        raise ArchiveError("invalid ZIP or CSV") from exc
