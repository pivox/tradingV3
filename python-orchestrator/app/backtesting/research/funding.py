"""Verified, bounded funding evidence and explicitly non-attesting continuity QA.

FundingReader performs an inventory pass, then iter_events() opens a new one-pass
stream. It keeps only one bounded month per symbol in memory, never candles.
No settlement, trading, HTTP, credentials or database operations occur here.
"""
from __future__ import annotations

import csv
import hashlib
import heapq
import io
import json
import os
import stat
import zipfile
from collections import Counter
from dataclasses import asdict, dataclass, replace
from datetime import datetime, timedelta, timezone
from decimal import Decimal, InvalidOperation
from pathlib import Path
from typing import Iterator

from app.modern_trading_contracts import _canonical_json
from .binance_history import FUNDING_HEADER, SOURCE, SYMBOLS, ArchiveError, plan_archives, verify_checksum
from .source_complements import END_MS, FUNDING_URL, MAX_PAGES, PAGE_LIMIT, START_MS

TOLERANCE_MS = 1000
MAX_MANIFEST_BYTES = 16 * 1024**2
MAX_ARCHIVE_BYTES = 2 * 1024**2
MAX_CSV_BYTES = 8 * 1024**2
MAX_RECORD_BYTES = 16 * 1024
MAX_MONTH_RECORDS = 10000
MAX_ISSUES = 100
REST_HYPOTHESIS = "carry_forward_last_declared"


class FundingError(ValueError):
    """The requested funding evidence cannot be trusted."""


@dataclass(frozen=True)
class FundingEvent:
    timestamp_ms: int
    symbol: str
    rate: Decimal
    observed_mark: Decimal | None
    declared_interval_hours: Decimal | None
    source_url: str
    source_hash: str
    raw_sha256: str
    record_sha256: str


@dataclass(frozen=True)
class SourceBinding:
    symbol: str
    kind: str
    source_url: str
    source_hash: str
    raw_sha256: str
    role: str


@dataclass(frozen=True)
class QualityIssue:
    kind: str
    left_ms: int | None = None
    right_ms: int | None = None
    expected_elapsed_ms: str | None = None
    actual_elapsed_ms: int | None = None
    jitter_ms: str | None = None


@dataclass(frozen=True)
class SymbolQuality:
    symbol: str
    count: int
    first_ms: int | None
    last_ms: int | None
    continuity: str
    evidence_complete: bool
    max_abs_jitter_ms: int
    interval_counts: tuple[tuple[str, int], ...]
    issue_counts: tuple[tuple[str, int], ...]
    issues: tuple[QualityIssue, ...]


@dataclass(frozen=True)
class FundingInventory:
    acquisition_manifest_sha256: str
    supplement_status_sha256: str | None
    start_ms: int
    end_exclusive_ms: int
    coverage: str
    schedule_attestation: str
    diagnostic_tolerance_ms: int
    rest_interval_hypothesis: str | None
    rest_hypothesis_hash: str | None
    evidence_complete: bool
    sources: tuple[SourceBinding, ...]
    symbols: tuple[SymbolQuality, ...]
    inventory_hash: str


def _hash(value) -> str:
    return "sha256:" + hashlib.sha256(_canonical_json(value).encode()).hexdigest()


def _json(raw: bytes):
    def pairs(items):
        result = {}
        for key, value in items:
            if key in result:
                raise FundingError("duplicate JSON key")
            result[key] = value
        return result
    def invalid_constant(value):
        raise FundingError("nonfinite JSON constant")
    try:
        return json.loads(raw, object_pairs_hook=pairs, parse_constant=invalid_constant)
    except (ValueError, UnicodeError, RecursionError) as exc:
        raise FundingError("invalid JSON evidence") from exc


def _utc(value: str | datetime) -> datetime:
    try:
        result = datetime.fromisoformat(value.replace("Z", "+00:00")) if isinstance(value, str) else value
        if not isinstance(result, datetime) or result.tzinfo is None or result.utcoffset() != timedelta(0):
            raise ValueError
        if result.microsecond % 1000:
            raise ValueError
        return result
    except ValueError as exc:
        raise FundingError("exact UTC millisecond window required") from exc


def _ms(value: datetime) -> int:
    return int((value - datetime(1970, 1, 1, tzinfo=timezone.utc)) // timedelta(milliseconds=1))


def _decimal(value, *, positive=False) -> Decimal:
    try:
        if not isinstance(value, str):
            raise ValueError
        result = Decimal(value)
        if not result.is_finite() or (positive and result <= 0):
            raise ValueError
        return result
    except (InvalidOperation, ValueError) as exc:
        raise FundingError("finite decimal string required") from exc


def _root(value: Path) -> Path:
    root = Path(value)
    if not root.is_absolute() or ".." in root.parts or any(p.is_symlink() for p in (root, *root.parents)):
        raise FundingError("absolute no-symlink root required")
    try:
        mode = root.stat()
        if not stat.S_ISDIR(mode.st_mode) or mode.st_uid != os.geteuid() or stat.S_IMODE(mode.st_mode) != 0o700:
            raise FundingError("private existing root required")
    except OSError as exc:
        raise FundingError("missing evidence root") from exc
    return root


def _read(root: Path, relative: str, cap: int) -> bytes:
    _root(root)
    path = Path(relative)
    if path.is_absolute() or not path.parts or any(part in ("..", ".") for part in path.parts):
        raise FundingError("evidence path traversal")
    fd = os.open(root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        root_stat = os.fstat(fd)
        if root_stat.st_uid != os.geteuid() or stat.S_IMODE(root_stat.st_mode) != 0o700:
            raise FundingError("private opened root required")
        for part in path.parts[:-1]:
            next_fd = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            os.close(fd)
            fd = next_fd
            directory = os.fstat(fd)
            # Root700 already prevents foreign traversal. Collector-created
            # intermediate directories can inherit775 without exposing files.
            if directory.st_uid != os.geteuid():
                raise FundingError("owned artifact directory required")
        file_fd = os.open(path.name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=fd)
        with os.fdopen(file_fd, "rb") as stream:
            info = os.fstat(stream.fileno())
            if (not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid()
                    or stat.S_IMODE(info.st_mode) != 0o600 or info.st_size > cap):
                raise FundingError("unsafe or oversized evidence file")
            body = stream.read(cap + 1)
            if len(body) > cap:
                raise FundingError("evidence file size cap")
            return body
    except OSError as exc:
        raise FundingError("missing or unsafe evidence file") from exc
    finally:
        os.close(fd)


class FundingReader:
    def __init__(self, acquisition_root: Path, start: str | datetime, end: str | datetime,
                 *, symbols: tuple[str, ...] = SYMBOLS, supplement_root: Path | None = None,
                 rest_interval_hypothesis: str | None = None):
        self.root = _root(acquisition_root)
        self.start, self.end = _utc(start), _utc(end)
        self.start_ms, self.end_ms = _ms(self.start), _ms(self.end)
        if self.start_ms < 0 or self.start >= self.end or not symbols or len(set(symbols)) != len(symbols) or any(s not in SYMBOLS for s in symbols):
            raise FundingError("approved symbols and nonempty window required")
        if rest_interval_hypothesis not in (None, REST_HYPOTHESIS):
            raise FundingError("unknown explicit REST interval hypothesis")
        self.symbols = tuple(s for s in SYMBOLS if s in symbols)
        self.hypothesis = rest_interval_hypothesis
        raw = _read(self.root, "manifest.json", MAX_MANIFEST_BYTES)
        self.manifest_sha = hashlib.sha256(raw).hexdigest()
        manifest = _json(raw)
        try:
            identity = manifest["identity"]
            self.origin_start, self.origin_end = _utc(identity["start"]), _utc(identity["end"])
            if (identity["schema"] != 1 or identity["source"] != SOURCE or identity["include_funding"] is not True
                    or any(s not in identity["symbols"] for s in self.symbols)
                    or self.start < self.origin_start or self.end > self.origin_end
                    or not isinstance(manifest["sources"], list) or len(manifest["sources"]) > 10000):
                raise FundingError("acquisition identity mismatch")
            self.archives = {}
            for entry in manifest["sources"]:
                if entry.get("kind") != "funding" or entry.get("symbol") not in self.symbols:
                    continue
                key = (entry["symbol"], entry["filename"])
                if key in self.archives:
                    raise FundingError("duplicate funding source identity")
                self.archives[key] = entry
        except (KeyError, TypeError, AttributeError) as exc:
            raise FundingError("invalid acquisition manifest") from exc
        self.supplement_root = _root(supplement_root) if supplement_root is not None else None
        self.supplement = None
        self.supplement_sha = None
        if self.supplement_root is not None:
            raw = _read(self.supplement_root, "status.json", MAX_MANIFEST_BYTES)
            self.supplement_sha = hashlib.sha256(raw).hexdigest()
            document = _json(raw)
            try:
                payload = dict(document)
                digest = payload.pop("status_hash")
                if (digest != _hash(payload) or document["schema_version"] != "research-source-complements.v1"
                        or document["start_ms"] != START_MS or document["end_exclusive_ms"] != END_MS
                        or document["symbols"] != list(SYMBOLS) or document["retrieval_complete"] is not True
                        or not isinstance(document["funding"], list)):
                    raise FundingError("supplement status binding mismatch")
                self.supplement = {}
                for entry in document["funding"]:
                    symbol = entry["symbol"]
                    if symbol not in SYMBOLS or symbol in self.supplement:
                        raise FundingError("duplicate supplement symbol")
                    self.supplement[symbol] = entry
            except (KeyError, TypeError, ValueError) as exc:
                raise FundingError("invalid supplement manifest") from exc
        self.inventory = self._inventory()

    def _planned(self, symbol: str):
        # Archive planner accepts UTC minute boundaries; only monthly identities
        # are needed, while selection below retains exact caller milliseconds.
        start = self.start.replace(day=1, hour=0, minute=0, second=0, microsecond=0)
        end = self.end.replace(second=0, microsecond=0)
        if end < self.end:
            end += timedelta(minutes=1)
        return plan_archives(symbol, start, end, "funding")

    def _archive_events(self, source, entry: dict, bindings: set | None = None) -> tuple[FundingEvent, ...]:
        expected_path = "archives/funding/" + source.filename
        if (entry.get("url") != source.url or entry.get("checksum_url") != source.checksum_url
                or entry.get("raw_path") != expected_path or entry.get("checksum_path") != expected_path + ".CHECKSUM"
                or entry.get("start") != source.start.isoformat() or entry.get("end") != source.end.isoformat()
                or entry.get("evidence") != "official_archive_checksum" or entry.get("status") != "ok"):
            raise FundingError("archive source identity mismatch")
        raw = _read(self.root, expected_path, MAX_ARCHIVE_BYTES)
        checksum = _read(self.root, expected_path + ".CHECKSUM", 4096)
        try:
            digest = verify_checksum(raw, checksum.decode(), source.filename)
            if digest != entry.get("sha256") or len(raw) != entry.get("size"):
                raise FundingError("archive raw hash mismatch")
            source_hash = _hash(entry)
            events = []
            with zipfile.ZipFile(io.BytesIO(raw)) as archive:
                members = archive.infolist()
                if (len(members) != 1 or members[0].filename != source.filename[:-4] + ".csv"
                        or members[0].file_size > MAX_CSV_BYTES):
                    raise FundingError("archive member or expansion cap")
                with archive.open(members[0]) as stream:
                    total = 0
                    previous = -1
                    line_index = 0
                    while line := stream.readline(MAX_RECORD_BYTES + 1):
                        total += len(line)
                        if total > MAX_CSV_BYTES or len(line) > MAX_RECORD_BYTES or len(events) >= MAX_MONTH_RECORDS:
                            raise FundingError("archive record or expansion cap")
                        row = next(csv.reader([line.decode("utf-8-sig" if line_index == 0 else "utf-8")]))
                        line_index += 1
                        if tuple(row) == FUNDING_HEADER and line_index == 1:
                            continue
                        if len(row) != 3 or not row[0].isdigit():
                            raise FundingError("malformed funding CSV record")
                        timestamp = int(row[0])
                        if not _ms(source.start) <= timestamp < _ms(source.end) or timestamp <= previous:
                            raise FundingError("archive timestamp range or order")
                        interval, rate = _decimal(row[1], positive=True), _decimal(row[2])
                        events.append(FundingEvent(timestamp, source.symbol, rate, None, interval,
                            source.url, source_hash, digest, hashlib.sha256(line).hexdigest()))
                        previous = timestamp
            selected = [e for e in events if _ms(self.origin_start) <= e.timestamp_ms < _ms(self.origin_end)]
            if (len(selected) != entry.get("count") or (selected[0].timestamp_ms if selected else None) != entry.get("first_open")
                    or (selected[-1].timestamp_ms if selected else None) != entry.get("last_close")
                    or entry.get("gaps") != [] or entry.get("coverage") != ("observed_only" if selected else "empty")):
                raise FundingError("archive summary mismatch")
            if bindings is not None:
                role = "selected" if _ms(source.start) < self.end_ms and self.start_ms < _ms(source.end) else "boundary_context"
                bindings.add(SourceBinding(source.symbol, "archive", source.url, source_hash, digest, role))
            return tuple(events)
        except (ArchiveError, UnicodeError, csv.Error, zipfile.BadZipFile, RuntimeError, EOFError, ValueError) as exc:
            raise FundingError("archive verification failed") from exc

    def _tail_events(self, symbol: str, bindings: set | None = None) -> tuple[FundingEvent, ...]:
        entry = self.supplement.get(symbol) if self.supplement is not None else None
        if entry is None:
            return ()
        try:
            if (entry["coverage"] != "observed_only" or entry["retrieval_complete"] is not True
                    or entry["status"] not in ("observed", "empty") or not 1 <= len(entry["pages"]) <= MAX_PAGES):
                raise FundingError("supplement source not successfully retrieved")
            events, indexed = [], []
            cursor = START_MS
            terminal = False
            for index, page in enumerate(entry["pages"]):
                expected = FUNDING_URL + f"?symbol={symbol}&startTime={cursor}&endTime={END_MS-1}&limit={PAGE_LIMIT}"
                name = f"funding-{symbol}-{index:02d}.raw.json"
                if (terminal or page["status"] != "validated" or page["source_url"] != expected
                        or page["request_start_ms"] != cursor or page["raw_path"] != name):
                    raise FundingError("supplement page identity or ordering mismatch")
                body = _read(self.supplement_root, name, 1024**2)
                digest = hashlib.sha256(body).hexdigest()
                if digest != page["raw_sha256"]:
                    raise FundingError("supplement raw hash mismatch")
                rows = _json(body)
                if not isinstance(rows, list) or len(rows) > PAGE_LIMIT:
                    raise FundingError("invalid supplement page")
                source_hash = _hash({"source": entry, "page": page})
                previous = cursor - 1
                for row in rows:
                    timestamp = row["fundingTime"]
                    if (row["symbol"] != symbol or type(timestamp) is not int or not cursor <= timestamp < END_MS
                            or timestamp <= previous):
                        raise FundingError("supplement observation ordering or symbol mismatch")
                    rate = _decimal(row["fundingRate"])
                    mark = _decimal(row["markPrice"], positive=True) if "markPrice" in row else None
                    events.append(FundingEvent(timestamp, symbol, rate, mark, None, expected,
                        source_hash, digest, _hash(row).removeprefix("sha256:")))
                    previous = timestamp
                indexed.extend(rows)
                terminal = len(rows) < PAGE_LIMIT
                if rows:
                    cursor = rows[-1]["fundingTime"] + 1
            if (not terminal or indexed != entry["observations"]
                    or entry["status"] != ("observed" if indexed else "empty")):
                raise FundingError("supplement indexed observations or terminal page mismatch")
            if bindings is not None:
                role = "selected" if START_MS < self.end_ms and self.start_ms < END_MS else "boundary_context"
                for page in entry["pages"]:
                    bindings.add(SourceBinding(symbol, "rest", page["source_url"],
                        _hash({"source": entry, "page": page}), page["raw_sha256"], role))
            return tuple(events)
        except (KeyError, TypeError, AttributeError, ValueError) as exc:
            raise FundingError("supplement verification failed") from exc

    def _symbol_events(self, symbol: str, *, context=False, bindings: set | None = None) -> Iterator[FundingEvent]:
        sources = self._planned(symbol)
        if context:
            prior = sources[0].start - timedelta(days=1)
            following = sources[-1].end
            sources = (plan_archives(symbol, prior.replace(day=1), sources[0].start, "funding") + sources
                       + plan_archives(symbol, following, following + timedelta(minutes=1), "funding"))
        previous = -1
        for source in sources:
            entry = self.archives.get((symbol, source.filename))
            if entry is None or entry.get("status") != "ok":
                continue
            if (self.supplement is not None and symbol in self.supplement
                    and max(self.start_ms, _ms(source.start), START_MS) < min(self.end_ms, _ms(source.end), END_MS)):
                raise FundingError("overlapping archive and REST evidence windows")
            for event in self._archive_events(source, entry, bindings):
                if event.timestamp_ms <= previous:
                    raise FundingError("duplicate or overlapping archive observations")
                previous = event.timestamp_ms
                if context or self.start_ms <= event.timestamp_ms < self.end_ms:
                    yield event
        if not (_ms(sources[0].start) < END_MS and START_MS < _ms(sources[-1].end)):
            return
        for event in self._tail_events(symbol, bindings):
            if not _ms(sources[0].start) <= event.timestamp_ms < _ms(sources[-1].end):
                continue
            if event.timestamp_ms <= previous:
                raise FundingError("duplicate or overlapping archive and REST observations")
            previous = event.timestamp_ms
            if context or self.start_ms <= event.timestamp_ms < self.end_ms:
                yield event

    def iter_events(self) -> Iterator[FundingEvent]:
        return heapq.merge(*(self._symbol_events(symbol) for symbol in self.symbols),
                           key=lambda event: (event.timestamp_ms, SYMBOLS.index(event.symbol)))

    def _inventory(self) -> FundingInventory:
        qualities, bindings = [], set()
        for symbol in self.symbols:
            issues, counts, intervals = [], Counter(), Counter()
            def issue(kind, left=None, right=None, expected=None, actual=None, jitter=None):
                counts[kind] += 1
                if len(issues) < MAX_ISSUES:
                    issues.append(QualityIssue(kind, left, right, str(expected) if expected is not None else None,
                                              actual, str(jitter) if jitter is not None else None))
            evidence_complete = True
            for source in self._planned(symbol):
                entry = self.archives.get((symbol, source.filename))
                if entry is None or entry.get("status") != "ok":
                    left, right = max(self.start_ms, _ms(source.start)), min(self.end_ms, _ms(source.end))
                    if self.supplement is None or symbol not in self.supplement or not START_MS <= left < right <= END_MS:
                        evidence_complete = False
                        issue("missing_evidence", left, right)
            selected_count = 0
            first = last = previous = None
            prior_declared = None
            max_jitter = 0
            used_assumption = False
            after = None
            left_context = False
            for event in self._symbol_events(symbol, context=True, bindings=bindings):
                role = "selected" if self.start_ms <= event.timestamp_ms < self.end_ms else "boundary_context"
                bindings.add(SourceBinding(symbol, "archive" if event.declared_interval_hours is not None else "rest",
                                           event.source_url, event.source_hash, event.raw_sha256, role))
                effective_interval = event.declared_interval_hours
                if effective_interval is None and self.hypothesis == REST_HYPOTHESIS:
                    effective_interval = prior_declared
                if event.timestamp_ms < self.start_ms:
                    left_context = True
                    previous = event
                    if event.declared_interval_hours is not None:
                        prior_declared = event.declared_interval_hours
                    continue
                # The successor can supply the diagnostic interval even when
                # it falls outside the selected window. That remains assumed.
                if event.declared_interval_hours is None and effective_interval is not None:
                    used_assumption = True
                if event.timestamp_ms >= self.end_ms:
                    after = event
                else:
                    selected_count += 1
                    first = first or event
                    last = event
                    intervals[str(event.declared_interval_hours) if event.declared_interval_hours is not None else "undeclared"] += 1
                if previous is not None and (previous.timestamp_ms < self.end_ms):
                    actual = event.timestamp_ms - previous.timestamp_ms
                    if effective_interval is None:
                        issue("undeclared_interval", previous.timestamp_ms, event.timestamp_ms)
                    else:
                        expected = effective_interval * 3600000
                        jitter = Decimal(actual) - expected
                        max_jitter = max(max_jitter, int(abs(jitter)))
                        if abs(jitter) > TOLERANCE_MS:
                            old = previous.declared_interval_hours
                            transition = old is not None and old != event.declared_interval_hours
                            if transition and abs(Decimal(actual) - old * 3600000) <= TOLERANCE_MS:
                                kind = "transition_inconclusive"
                            else:
                                kind = "interval_gap" if jitter > 0 else "interval_mismatch"
                            issue(kind, previous.timestamp_ms, event.timestamp_ms, expected, actual, jitter)
                elif effective_interval is None:
                    issue("undeclared_interval", right=event.timestamp_ms)
                if after is not None:
                    break
                previous = event
                if event.declared_interval_hours is not None:
                    prior_declared = event.declared_interval_hours
            bracketed_empty = first is None and left_context and after is not None
            if (first is None and not bracketed_empty) or (first is not None and
                    first.timestamp_ms - self.start_ms > TOLERANCE_MS and not left_context):
                issue("boundary_context_absent", right=first.timestamp_ms if first else None)
            if after is None:
                interval = last.declared_interval_hours if last is not None else None
                if interval is None and self.hypothesis == REST_HYPOTHESIS:
                    interval = prior_declared
                if last is None or interval is None or self.end_ms - last.timestamp_ms > interval * 3600000 + TOLERANCE_MS:
                    issue("boundary_context_absent", left=last.timestamp_ms if last else None)
            inconclusive = any(counts[k] for k in ("missing_evidence", "transition_inconclusive",
                                                  "interval_mismatch", "undeclared_interval", "boundary_context_absent"))
            continuity = ("gaps" if counts["interval_gap"] else "inconclusive" if inconclusive else
                          "assumed_interval" if used_assumption else "declared_interval_consistent")
            qualities.append(SymbolQuality(symbol, selected_count, first.timestamp_ms if first else None,
                last.timestamp_ms if last else None, continuity, evidence_complete, max_jitter,
                tuple(sorted(intervals.items())), tuple(sorted((k, v) for k, v in counts.items() if v)), tuple(issues)))
        source_bindings = tuple(sorted(bindings, key=lambda b: (SYMBOLS.index(b.symbol), b.source_url, b.role)))
        hypothesis_hash = _hash({"name": self.hypothesis, "tolerance_ms": TOLERANCE_MS,
                                 "source_bindings": [asdict(b) for b in source_bindings]}) if self.hypothesis else None
        inventory = FundingInventory(self.manifest_sha, self.supplement_sha, self.start_ms, self.end_ms,
            "observed_only", "not_attested", TOLERANCE_MS, self.hypothesis, hypothesis_hash,
            all(q.evidence_complete for q in qualities), source_bindings, tuple(qualities), "")
        payload = asdict(inventory)
        payload.pop("inventory_hash")
        return replace(inventory, inventory_hash=_hash(payload))
