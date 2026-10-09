"""Freeze and revalidate a candle-only subset of an acquisition manifest."""

from __future__ import annotations

import csv
import hashlib
import io
import json
import os
import re
import stat
import zipfile
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from decimal import Decimal, InvalidOperation
from pathlib import Path, PurePosixPath
from typing import Iterator

from .binance_history import (KLINE_HEADER, MAX_ZIP_BYTES, SOURCE, SYMBOLS, ArchiveError,
                              ArchiveSource, parse_archive, plan_archives,
                              validate_kline, verify_checksum)

MAX_MANIFEST_BYTES = 64 * 1024 * 1024
MAX_REST_BYTES = 2 * 1024 * 1024
SCORE_FLOOR = datetime(2023, 1, 1, tzinfo=timezone.utc)
HOLDOUT_START = datetime(2026, 1, 1, tzinfo=timezone.utc)


class SignalError(RuntimeError):
    """Verified signal research could not be completed."""


@dataclass(frozen=True)
class SourceSelection:
    symbol: str
    start: datetime
    end: datetime
    score_start: datetime
    manifest_sha256: str
    dataset_sha256: str
    sources: tuple[dict, ...]
    expected_candles: int
    expected_evaluations: int
    expected_warmup: int
    expected_before_score: int


def _utc(value: object) -> datetime:
    if not isinstance(value, str):
        raise SignalError("invalid UTC timestamp")
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError as exc:
        raise SignalError("invalid UTC timestamp") from exc
    if (parsed.tzinfo is None or parsed.utcoffset() != timedelta(0)
            or parsed.second or parsed.microsecond):
        raise SignalError("UTC minute boundary required")
    return parsed


def _ms(value: datetime) -> int:
    return int(value.timestamp() * 1000)


def _safe_relative(name: str) -> PurePosixPath:
    if not isinstance(name, str):
        raise SignalError("invalid stored path")
    path = PurePosixPath(name)
    if path.is_absolute() or not path.parts or any(part in {".", ".."} for part in path.parts):
        raise SignalError("stored path escape")
    return path


def _read_bounded(root: Path, name: str, maximum: int) -> bytes:
    relative = _safe_relative(name)
    path = root.joinpath(*relative.parts)
    if any(part.is_symlink() for part in (root, *path.parents)):
        raise SignalError("stored path symlink")
    try:
        mode = path.lstat().st_mode
        if not stat.S_ISREG(mode) or path.stat().st_size > maximum:
            raise SignalError("stored file type or size limit")
        descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
        try:
            if os.fstat(descriptor).st_size > maximum:
                raise SignalError("stored file size limit")
            with os.fdopen(descriptor, "rb", closefd=False) as stream:
                raw = stream.read(maximum + 1)
            if len(raw) > maximum:
                raise SignalError("stored file size limit")
            return raw
        finally:
            os.close(descriptor)
    except (FileNotFoundError, NotADirectoryError, OSError) as exc:
        raise SignalError("stored file unavailable") from exc


def _archive_source(entry: dict, symbol: str) -> ArchiveSource:
    filename = entry.get("filename")
    match = re.fullmatch(rf"{symbol}-1m-(\d{{4}}-\d{{2}}(?:-\d{{2}})?)\.zip", filename or "")
    if match is None:
        raise SignalError("archive filename invalid")
    token = match.group(1)
    try:
        beginning = datetime.strptime(token, "%Y-%m-%d" if len(token) == 10 else "%Y-%m").replace(tzinfo=timezone.utc)
    except ValueError as exc:
        raise SignalError("archive period invalid") from exc
    if len(token) == 10:
        ending = beginning + timedelta(days=1)
    else:
        ending = beginning.replace(year=beginning.year + (beginning.month == 12),
                                   month=beginning.month % 12 + 1)
    expected = plan_archives(symbol, beginning, ending)[0]
    if (expected.filename != filename or entry.get("url") != expected.url
            or entry.get("checksum_url") != expected.checksum_url):
        raise SignalError("archive origin conflict")
    if not (beginning <= _utc(entry["start"]) < _utc(entry["end"]) <= ending):
        raise SignalError("archive interval conflict")
    return expected


def _expected_ticks(start: datetime, end: datetime, score_start: datetime) -> tuple[int, int, int]:
    start_ms, end_ms, score_ms = _ms(start), _ms(end), _ms(score_start)
    quarter, four_hours = 900000, 14400000
    first_4h_open = ((start_ms + four_hours - 1) // four_hours) * four_hours
    ready = first_4h_open + 250 * four_hours
    warmup = before = evaluated = 0
    tick = ((start_ms + quarter - 1) // quarter) * quarter
    if tick <= start_ms:
        tick += quarter
    while tick <= end_ms:
        if tick < ready:
            warmup += 1
        elif tick < score_ms:
            before += 1
        else:
            evaluated += 1
        tick += quarter
    return evaluated, warmup, before


def select_sources(root: Path, start: str, end: str, score_start: str,
                   symbol: str) -> SourceSelection:
    beginning, ending, score = _utc(start), _utc(end), _utc(score_start)
    if (symbol not in SYMBOLS or beginning >= ending or score < SCORE_FLOOR
            or score < beginning or score >= ending or ending > HOLDOUT_START):
        raise SignalError("unsupported research range or symbol")
    root = Path(root)
    if not root.is_absolute() or root.is_symlink():
        raise SignalError("absolute non-symlink dataset root required")
    raw_manifest = _read_bounded(root, "manifest.json", MAX_MANIFEST_BYTES)
    try:
        manifest = json.loads(raw_manifest)
        identity = manifest["identity"]
        entries = manifest["sources"]
    except (ValueError, TypeError, KeyError) as exc:
        raise SignalError("acquisition manifest invalid") from exc
    if (not isinstance(identity, dict) or not isinstance(entries, list)
            or identity.get("schema") != 1 or identity.get("source") != SOURCE
            or not isinstance(identity.get("symbols"), list)
            or symbol not in identity["symbols"]
            or _utc(identity.get("start")) > beginning or _utc(identity.get("end")) < ending):
        raise SignalError("acquisition identity conflict")
    selected: list[dict] = []
    for entry in entries:
        if not isinstance(entry, dict):
            raise SignalError("acquisition source invalid")
        if entry.get("symbol") != symbol or entry.get("kind") != "klines":
            continue
        left, right = _utc(entry.get("start")), _utc(entry.get("end"))
        if left >= ending or right <= beginning:
            continue
        if (entry.get("status") != "ok" or entry.get("coverage") != "complete"
                or entry.get("gaps") != [] or entry.get("count") != (_ms(right) - _ms(left)) // 60000
                or entry.get("first_open") != _ms(left)
                or entry.get("last_close") != _ms(right) - 1):
            raise SignalError("selected candle coverage incomplete")
        if entry.get("evidence") == "official_archive_checksum":
            _archive_source(entry, symbol)
            expected_path = f"archives/klines/{entry['filename']}"
            if (entry.get("raw_path") != expected_path
                    or entry.get("checksum_path") != expected_path + ".CHECKSUM"):
                raise SignalError("archive storage path conflict")
        elif entry.get("evidence") == "download_sha256":
            if not isinstance(entry.get("pages"), list) or not entry["pages"]:
                raise SignalError("REST provenance missing")
        else:
            raise SignalError("unsupported candle evidence")
        selected.append(entry)
    selected.sort(key=lambda entry: entry["start"])
    cursor = beginning
    for entry in selected:
        left, right = _utc(entry["start"]), _utc(entry["end"])
        if left > cursor or right <= cursor or (left < cursor and entry is not selected[0]):
            raise SignalError("selected candle gap or overlap")
        cursor = right
    if not selected or cursor < ending:
        raise SignalError("selected candle window missing")
    frozen = [{key: entry.get(key) for key in
               ("symbol", "kind", "start", "end", "evidence", "filename", "url",
                "checksum_url", "raw_path", "checksum_path", "sha256", "size",
                "pages", "count", "first_open", "last_close", "gaps", "coverage")}
              for entry in selected]
    binding = {"schema": "research-source-subset.v1", "symbol": symbol,
               "start": beginning.isoformat(), "end": ending.isoformat(),
               "score_start": score.isoformat(), "sources": frozen}
    subset = hashlib.sha256(json.dumps(binding, sort_keys=True, separators=(",", ":")).encode()).hexdigest()
    evaluated, warmup, before = _expected_ticks(beginning, ending, score)
    return SourceSelection(symbol, beginning, ending, score, hashlib.sha256(raw_manifest).hexdigest(),
                           subset, tuple(selected), (_ms(ending) - _ms(beginning)) // 60000,
                           evaluated, warmup, before)


def _decimal_text(value: object) -> str:
    if not isinstance(value, str):
        raise SignalError("candle decimal is not a string")
    try:
        decimal = Decimal(value)
    except InvalidOperation as exc:
        raise SignalError("candle decimal invalid") from exc
    if not decimal.is_finite():
        raise SignalError("candle decimal nonfinite")
    rendered = format(decimal, "f")
    if len(rendered) > 64 or rendered.startswith("-"):
        raise SignalError("candle decimal invalid")
    return rendered


def _normalized(symbol: str, row: list | tuple, digest: str, index: int) -> dict:
    opening, _ = validate_kline(row)
    record_id = hashlib.sha256(f"{digest}:{index}:{opening}".encode()).hexdigest()
    return {"symbol": symbol, "open_ms": opening,
            "open": _decimal_text(row[1]), "high": _decimal_text(row[2]),
            "low": _decimal_text(row[3]), "close": _decimal_text(row[4]),
            "volume": _decimal_text(row[5]), "source_record_id": record_id}


def _archive_records(root: Path, entry: dict, symbol: str) -> Iterator[dict]:
    source = _archive_source(entry, symbol)
    raw = _read_bounded(root, entry["raw_path"], MAX_ZIP_BYTES)
    checksum = _read_bounded(root, entry["checksum_path"], 4096)
    digest = verify_checksum(raw, checksum.decode("utf-8"), source.filename)
    summary = parse_archive(raw, source, _utc(entry["start"]), _utc(entry["end"]))
    if (digest != entry.get("sha256") or len(raw) != entry.get("size")
            or summary.count != entry.get("count") or summary.coverage != "complete"
            or summary.first_open != entry.get("first_open")
            or summary.last_close != entry.get("last_close") or summary.gaps):
        raise SignalError("archive bytes differ from manifest")
    with zipfile.ZipFile(io.BytesIO(raw)) as archive:
        with archive.open(source.filename.removesuffix(".zip") + ".csv") as stream:
            reader = csv.reader(io.TextIOWrapper(stream, encoding="utf-8-sig", newline=""))
            index = 0
            for row in reader:
                if tuple(row) == KLINE_HEADER:
                    continue
                index += 1
                record = _normalized(symbol, row, digest, index)
                if _ms(_utc(entry["start"])) <= record["open_ms"] < _ms(_utc(entry["end"])):
                    yield record


def _rest_records(root: Path, entry: dict, symbol: str) -> Iterator[dict]:
    cursor = _ms(_utc(entry["start"]))
    end_ms = _ms(_utc(entry["end"]))
    for page in entry["pages"]:
        expected_path = f"rest/{symbol}/{cursor}.json"
        expected_url = ("https://fapi.binance.com/fapi/v1/klines?"
                        f"symbol={symbol}&interval=1m&startTime={cursor}"
                        f"&endTime={end_ms - 1}&limit=1000")
        if page.get("raw_path") != expected_path or page.get("url") != expected_url:
            raise SignalError("REST provenance conflict")
        raw = _read_bounded(root, expected_path, MAX_REST_BYTES)
        digest = hashlib.sha256(raw).hexdigest()
        if digest != page.get("sha256") or len(raw) != page.get("size"):
            raise SignalError("REST bytes differ from manifest")
        try:
            rows = json.loads(raw)
        except (ValueError, UnicodeError) as exc:
            raise SignalError("REST payload invalid") from exc
        if not isinstance(rows, list) or not rows or len(rows) > 1000:
            raise SignalError("REST page invalid")
        for index, row in enumerate(rows, 1):
            record = _normalized(symbol, row, digest, index)
            if record["open_ms"] != cursor:
                raise SignalError("REST candle gap or order")
            cursor += 60000
            yield record
    if cursor != end_ms:
        raise SignalError("REST candle count incomplete")


def iter_verified_candles(root: Path, selection: SourceSelection) -> Iterator[dict]:
    cursor = _ms(selection.start)
    end_ms = _ms(selection.end)
    for entry in selection.sources:
        records = (_archive_records(root, entry, selection.symbol)
                   if entry["evidence"] == "official_archive_checksum"
                   else _rest_records(root, entry, selection.symbol))
        for record in records:
            opening = record["open_ms"]
            if opening < _ms(selection.start) or opening >= end_ms:
                continue
            if opening != cursor:
                raise SignalError("selected candle gap or order")
            cursor += 60000
            yield record
    if cursor != end_ms:
        raise SignalError("selected candle count incomplete")
