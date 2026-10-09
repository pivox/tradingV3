"""Bounded, resumable public Binance USD-M history acquisition.

Run ``python -m app.backtesting.research.acquire --root /external/private/path
--start 2023-01-01T00:00:00Z --end 2023-01-02T00:00:00Z``.
No trade or credential endpoints are used.
"""

from __future__ import annotations

import argparse
import fcntl
import hashlib
import json
import os
import shutil
import stat
import sys
import time
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Callable
from urllib.parse import urlsplit

import httpx

from .binance_history import (MAX_ZIP_BYTES, SOURCE, SYMBOLS, ArchiveError,
                              ArchiveSource, parse_archive,
                              plan_archives, summarize_rows, validate_kline,
                              verify_checksum)

SCHEMA = 1
DEFAULT_CAP = 20 * 1024**3
DEFAULT_RESERVE = 20 * 1024**3
TAIL_DAYS = 3


class AcquisitionError(RuntimeError):
    """Root, identity, capacity, or integrity conflict."""


@dataclass(frozen=True)
class AcquisitionResult:
    complete: bool
    sources: tuple[dict, ...]
    manifest_path: Path


class _HTTPStatus(Exception):
    def __init__(self, status: int):
        self.status = status
        super().__init__(str(status))


def _utc(value: str) -> datetime:
    try:
        result = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError as exc:
        raise AcquisitionError("invalid UTC timestamp") from exc
    if result.tzinfo is None or result.utcoffset() != timedelta(0):
        raise AcquisitionError("UTC timezone required")
    return result


def _safe_root(root: Path, identity: dict, expected_keys: set[tuple[str, str, str, str]],
               root_cap_bytes: int, reserve_bytes: int) -> Path:
    root = Path(root)
    if not root.is_absolute() or ".." in root.parts:
        raise AcquisitionError("absolute root without traversal required")
    repo = Path(__file__).resolve().parents[4]
    repositories = [repo]
    git_pointer = repo / ".git"
    if git_pointer.is_file():
        marker = git_pointer.read_text().strip()
        if marker.startswith("gitdir: "):
            gitdir = Path(marker.removeprefix("gitdir: ")).resolve()
            if gitdir.parent.name == "worktrees" and gitdir.parent.parent.name == ".git":
                repositories.append(gitdir.parent.parent.parent)
    if (root == Path.home() or any(root == repository or repository in root.parents
                                   or root in repository.parents for repository in repositories)):
        raise AcquisitionError("history root must be outside repository")
    cursor = Path(root.anchor)
    for part in root.parts[1:]:
        cursor /= part
        if cursor.is_symlink():
            raise AcquisitionError("symlink in history root")
        if cursor.exists() and not cursor.is_dir():
            raise AcquisitionError("non-directory history root")
    if root.exists():
        entries = list(root.iterdir())
        if entries:
            manifest_path = root / "manifest.json"
            if not manifest_path.exists() and not manifest_path.is_symlink():
                _interrupted_initial_manifest(root, identity)
            else:
                try:
                    manifest = json.loads(_read_file(manifest_path, max_bytes=MAX_ZIP_BYTES))
                except (ValueError, UnicodeError) as exc:
                    raise AcquisitionError("existing root has no valid acquisition manifest") from exc
                if not isinstance(manifest, dict):
                    raise AcquisitionError("existing root has no valid acquisition manifest")
                if manifest.get("identity") != identity:
                    raise AcquisitionError("root identity conflict")
                _validate_manifest_sources(manifest, expected_keys)
        if _root_size(root) > root_cap_bytes:
            raise AcquisitionError("root capacity exceeded")
    else:
        ancestor = root.parent
        while not ancestor.exists():
            ancestor = ancestor.parent
        if shutil.disk_usage(ancestor).free < reserve_bytes:
            raise AcquisitionError("free-space reserve exceeded")
    root.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(root, 0o700)
    return root


def _regular(path: Path) -> bool:
    try:
        return stat.S_ISREG(path.lstat().st_mode)
    except FileNotFoundError:
        return False


def _interrupted_initial_manifest(root: Path, identity: dict) -> tuple[Path, dict]:
    """Prove an unpublished initial manifest belongs to this private root."""
    uid = os.geteuid()
    root_stat = root.stat(follow_symlinks=False)
    if (root_stat.st_uid != uid or stat.S_IMODE(root_stat.st_mode) != 0o700):
        raise AcquisitionError("initial manifest identity cannot be proven for this root")
    names = {entry.name for entry in root.iterdir()}
    candidates = [name for name in names
                  if name.startswith(".manifest.") and name.endswith(".tmp")
                  and name[len(".manifest."):-len(".tmp")].isdigit()]
    if len(candidates) != 1 or names != {".writer.lock", candidates[0]}:
        raise AcquisitionError("initial manifest identity cannot be proven")
    lock = root / ".writer.lock"
    temp = root / candidates[0]
    try:
        lock_stat = lock.lstat()
        temp_stat = temp.lstat()
        if (not stat.S_ISREG(lock_stat.st_mode) or lock_stat.st_uid != uid
                or stat.S_IMODE(lock_stat.st_mode) != 0o600 or lock_stat.st_size != 0
                or not stat.S_ISREG(temp_stat.st_mode) or temp_stat.st_uid != uid
                or stat.S_IMODE(temp_stat.st_mode) != 0o600):
            raise AcquisitionError("initial manifest identity cannot be proven")
        manifest = json.loads(_read_file(temp, max_bytes=MAX_ZIP_BYTES))
    except (OSError, ValueError, UnicodeError) as exc:
        raise AcquisitionError("initial manifest identity cannot be proven") from exc
    expected = {"identity": identity, "complete": False, "sources": []}
    if manifest != expected:
        raise AcquisitionError("initial manifest identity conflict")
    return temp, manifest


def _read_file(path: Path, *, max_bytes: int = MAX_ZIP_BYTES) -> bytes:
    if not _regular(path):
        raise AcquisitionError("missing or unsafe stored file")
    if path.stat().st_size > max_bytes:
        raise AcquisitionError("stored file size limit")
    return path.read_bytes()


def _validate_manifest_sources(manifest: dict, expected_keys: set[tuple[str, str, str, str]]) -> None:
    sources = manifest.get("sources")
    if not isinstance(sources, list):
        raise AcquisitionError("invalid manifest sources")
    seen: set[tuple[str, str, str, str]] = set()
    for entry in sources:
        if not isinstance(entry, dict):
            raise AcquisitionError("invalid manifest source")
        try:
            key = (entry["symbol"], entry["kind"], entry["start"], entry["end"])
        except KeyError as exc:
            raise AcquisitionError("invalid manifest source key") from exc
        if not all(isinstance(value, str) for value in key):
            raise AcquisitionError("invalid manifest source key type")
        if key not in expected_keys or key in seen:
            raise AcquisitionError("manifest source set conflict")
        seen.add(key)


def _root_size(root: Path) -> int:
    total = 0
    for directory, subdirs, files in os.walk(root, followlinks=False):
        for name in (*subdirs, *files):
            item = Path(directory) / name
            mode = item.lstat().st_mode
            if stat.S_ISLNK(mode):
                raise AcquisitionError("symlink in history root")
            if stat.S_ISREG(mode):
                total += item.stat().st_size
            elif not stat.S_ISDIR(mode):
                raise AcquisitionError("unsupported history root entry")
    return total


def _atomic_file(path: Path, data: bytes) -> None:
    if any(parent.is_symlink() for parent in path.parents):
        raise AcquisitionError("symlink artifact directory")
    if path.exists() or path.is_symlink():
        if _read_file(path) != data:
            raise AcquisitionError("stored bytes conflict")
        return
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    if path.parent.is_symlink():
        raise AcquisitionError("symlink artifact directory")
    os.chmod(path.parent, 0o700)
    temp = path.with_name(f".{path.name}.{os.getpid()}.tmp")
    fd = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        with os.fdopen(fd, "wb") as output:
            output.write(data)
            output.flush()
            os.fsync(output.fileno())
        if path.exists() or path.is_symlink():
            if _read_file(path) != data:
                raise AcquisitionError("stored bytes conflict")
            temp.unlink()
        else:
            os.replace(temp, path)
    finally:
        if temp.exists():
            temp.unlink()


def _additional_storage_bytes(path: Path, data: bytes) -> int:
    if any(parent.is_symlink() for parent in path.parents):
        raise AcquisitionError("symlink artifact directory")
    if path.exists() or path.is_symlink():
        if _read_file(path, max_bytes=len(data)) != data:
            raise AcquisitionError("stored bytes conflict")
        return 0
    return len(data)


def _manifest_write(path: Path, manifest: dict) -> None:
    data = (json.dumps(manifest, sort_keys=True, indent=2) + "\n").encode()
    temp = path.with_name(f".manifest.{os.getpid()}.tmp")
    fd = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        with os.fdopen(fd, "wb") as output:
            output.write(data)
            output.flush()
            os.fsync(output.fileno())
        os.replace(temp, path)
    finally:
        if temp.exists():
            temp.unlink()


def _fetch(client: httpx.Client, url: str, *, max_bytes: int,
           sleep: Callable[[float], None]) -> bytes:
    parts = urlsplit(url)
    allowed_archive = (parts.scheme == "https" and parts.netloc == "data.binance.vision"
                       and parts.path.startswith("/data/futures/um/") and not parts.query)
    allowed_rest = (parts.scheme == "https" and parts.netloc == "fapi.binance.com"
                    and parts.path == "/fapi/v1/klines" and parts.query.startswith("symbol=")
                    and "&interval=1m&startTime=" in parts.query
                    and "&endTime=" in parts.query and parts.query.endswith("&limit=1000"))
    if not (allowed_archive or allowed_rest) or parts.fragment or parts.username or parts.password:
        raise AcquisitionError("unapproved URL")
    for attempt in range(3):
        try:
            with client.stream("GET", url, follow_redirects=False) as response:
                if response.status_code != 200:
                    status = response.status_code
                    if (status == 429 or 500 <= status <= 599) and attempt < 2:
                        value = response.headers.get("Retry-After", "")
                        try:
                            delay = min(2.0, max(0.0, float(value))) if value else 0.2 * (2**attempt)
                        except ValueError:
                            delay = 0.2 * (2**attempt)
                        sleep(delay)
                        continue
                    raise _HTTPStatus(status)
                body = bytearray()
                for chunk in response.iter_bytes():
                    body.extend(chunk)
                    if len(body) > max_bytes:
                        raise ArchiveError("download size limit")
                return bytes(body)
        except httpx.TransportError:
            if attempt == 2:
                raise
            sleep(0.2 * (2**attempt))
    raise AssertionError("retry loop exhausted")


def _entry(source: ArchiveSource | None, *, symbol: str, kind: str, start: datetime,
           end: datetime, status: str, error: str | None = None, **extra: object) -> dict:
    result = {"symbol": symbol, "kind": kind, "start": start.isoformat(), "end": end.isoformat(),
              "status": status, "error": error}
    if source is not None:
        result.update(filename=source.filename, url=source.url, checksum_url=source.checksum_url)
    result.update(extra)
    return result


def _stored_archive(root: Path, entry: dict, source: ArchiveSource, start: datetime,
                    end: datetime) -> None:
    expected_path = f"archives/{source.kind}/{source.filename}"
    if (entry.get("raw_path") != expected_path
            or entry.get("checksum_path") != expected_path + ".CHECKSUM"
            or entry.get("url") != source.url or entry.get("checksum_url") != source.checksum_url):
        raise AcquisitionError("resumed archive path conflict")
    raw = _read_file(root / entry["raw_path"], max_bytes=MAX_ZIP_BYTES)
    checksum = _read_file(root / entry["checksum_path"], max_bytes=4096).decode("utf-8")
    try:
        digest = verify_checksum(raw, checksum, source.filename)
        parsed = parse_archive(raw, source, start, end)
    except ArchiveError as exc:
        raise AcquisitionError("resumed archive verification failed") from exc
    if (digest != entry["sha256"] or len(raw) != entry["size"]
            or parsed.count != entry["count"] or parsed.first_open != entry.get("first_open")
            or parsed.last_close != entry.get("last_close")
            or [list(gap) for gap in parsed.gaps] != entry.get("gaps")
            or parsed.coverage != entry.get("coverage")):
        raise AcquisitionError("resumed archive differs from manifest")
    expected_status = "incomplete" if parsed.coverage == "incomplete" else "ok"
    if entry.get("status") != expected_status:
        raise AcquisitionError("resumed archive status conflict")


def _stored_tail(root: Path, entry: dict, symbol: str, start: datetime,
                 end: datetime) -> None:
    cursor = int(start.timestamp() * 1000)
    rows: list[tuple[int, int]] = []
    try:
        for page in entry["pages"]:
            if page["raw_path"] != f"rest/{symbol}/{cursor}.json":
                raise AcquisitionError("resumed REST path conflict")
            expected_url = ("https://fapi.binance.com/fapi/v1/klines?"
                            f"symbol={symbol}&interval=1m&startTime={cursor}"
                            f"&endTime={int(end.timestamp() * 1000) - 1}&limit=1000")
            if page.get("url") != expected_url:
                raise AcquisitionError("resumed REST URL conflict")
            raw = _read_file(root / page["raw_path"], max_bytes=2 * 1024 * 1024)
            if len(raw) != page["size"] or hashlib.sha256(raw).hexdigest() != page["sha256"]:
                raise AcquisitionError("resumed REST hash conflict")
            payload = json.loads(raw)
            if not isinstance(payload, list) or not payload or len(payload) > 1000:
                raise AcquisitionError("resumed REST format conflict")
            parsed_rows = [validate_kline(row) for row in payload]
            if parsed_rows[0][0] < cursor or any(a[0] >= b[0] for a, b in zip(parsed_rows, parsed_rows[1:])):
                raise AcquisitionError("resumed REST ordering conflict")
            cursor = parsed_rows[-1][0] + 60000
            rows.extend(parsed_rows)
        parsed = summarize_rows(rows, start, end, "klines")
    except (KeyError, ValueError, ArchiveError, TypeError) as exc:
        raise AcquisitionError("resumed REST verification failed") from exc
    if (parsed.count != entry.get("count") or parsed.first_open != entry.get("first_open")
            or parsed.last_close != entry.get("last_close") or parsed.coverage != entry.get("coverage")
            or [list(gap) for gap in parsed.gaps] != entry.get("gaps")):
        raise AcquisitionError("resumed REST summary conflict")
    if entry.get("status") != ("ok" if parsed.coverage == "complete" else "missing"):
        raise AcquisitionError("resumed REST status conflict")


def _archive(root: Path, source: ArchiveSource, start: datetime, end: datetime,
             client: httpx.Client, sleep: Callable[[float], None],
             capacity: Callable[[int], None]) -> dict:
    try:
        raw = _fetch(client, source.url, max_bytes=MAX_ZIP_BYTES, sleep=sleep)
        try:
            checksum = _fetch(client, source.checksum_url, max_bytes=4096, sleep=sleep)
        except _HTTPStatus as exc:
            return _entry(source, symbol=source.symbol, kind=source.kind, start=start, end=end,
                          status="checksum_error", error=f"checksum HTTP {exc.status}")
        try:
            checksum_text = checksum.decode("utf-8")
        except UnicodeDecodeError as exc:
            raise ArchiveError("checksum encoding invalid") from exc
        digest = verify_checksum(raw, checksum_text, source.filename)
        parsed = parse_archive(raw, source, start, end)
        raw_path = f"archives/{source.kind}/{source.filename}"
        checksum_path = raw_path + ".CHECKSUM"
        capacity(
            _additional_storage_bytes(root / raw_path, raw)
            + _additional_storage_bytes(root / checksum_path, checksum)
        )
        _atomic_file(root / raw_path, raw)
        _atomic_file(root / checksum_path, checksum)
        return _entry(source, symbol=source.symbol, kind=source.kind, start=start, end=end,
                      status="ok" if parsed.coverage != "incomplete" else "incomplete",
                      evidence="official_archive_checksum", raw_path=raw_path,
                      checksum_path=checksum_path, sha256=digest, size=len(raw),
                      fetched_at=datetime.now(timezone.utc).isoformat(), count=parsed.count,
                      first_open=parsed.first_open, last_close=parsed.last_close,
                      gaps=parsed.gaps, coverage=parsed.coverage)
    except _HTTPStatus as exc:
        return _entry(source, symbol=source.symbol, kind=source.kind, start=start, end=end,
                      status="missing" if exc.status == 404 else "http_error", error=f"HTTP {exc.status}")
    except (ArchiveError, UnicodeError) as exc:
        status = "checksum_error" if "checksum" in str(exc) else "invalid"
        return _entry(source, symbol=source.symbol, kind=source.kind, start=start, end=end,
                      status=status, error=str(exc))
    except httpx.TransportError as exc:
        return _entry(source, symbol=source.symbol, kind=source.kind, start=start, end=end,
                      status="http_error", error=type(exc).__name__)


def _tail(root: Path, symbol: str, start: datetime, end: datetime, client: httpx.Client,
          sleep: Callable[[float], None], capacity: Callable[[int], None],
          closed_until: datetime) -> dict:
    start_ms, end_ms = int(start.timestamp() * 1000), int(end.timestamp() * 1000)
    cutoff_ms = min(end_ms, int(closed_until.timestamp() * 1000) // 60000 * 60000)
    cursor = start_ms
    rows: list[tuple[int, int]] = []
    pages: list[dict] = []
    try:
        while cursor < cutoff_ms:
            url = ("https://fapi.binance.com/fapi/v1/klines?"
                   f"symbol={symbol}&interval=1m&startTime={cursor}&endTime={cutoff_ms - 1}&limit=1000")
            raw = _fetch(client, url, max_bytes=2 * 1024 * 1024, sleep=sleep)
            try:
                payload = json.loads(raw)
            except (ValueError, UnicodeError) as exc:
                raise ArchiveError("invalid REST JSON") from exc
            if not isinstance(payload, list) or len(payload) > 1000:
                raise ArchiveError("invalid REST page")
            if not payload:
                break
            page_rows = [validate_kline(row) for row in payload]
            if page_rows[0][0] < cursor:
                raise ArchiveError("REST page did not progress from cursor")
            if any(a[0] >= b[0] for a, b in zip(page_rows, page_rows[1:])):
                raise ArchiveError("REST page order")
            if page_rows[-1][0] >= cutoff_ms:
                raise ArchiveError("REST page beyond end")
            path = f"rest/{symbol}/{cursor}.json"
            capacity(_additional_storage_bytes(root / path, raw))
            _atomic_file(root / path, raw)
            pages.append({"url": url, "raw_path": path, "sha256": hashlib.sha256(raw).hexdigest(),
                          "size": len(raw)})
            rows.extend(page_rows)
            cursor = page_rows[-1][0] + 60000
        parsed = summarize_rows(rows, start, end, "klines")
        return _entry(None, symbol=symbol, kind="klines", start=start, end=end,
                      status="ok" if parsed.coverage == "complete" else "missing",
                      evidence="download_sha256", pages=pages, count=parsed.count,
                      first_open=parsed.first_open, last_close=parsed.last_close,
                      gaps=parsed.gaps, coverage=parsed.coverage,
                      fetched_at=datetime.now(timezone.utc).isoformat())
    except (_HTTPStatus, ArchiveError, httpx.TransportError) as exc:
        status = "http_error" if isinstance(exc, (_HTTPStatus, httpx.TransportError)) else "invalid"
        return _entry(None, symbol=symbol, kind="klines", start=start, end=end,
                      status=status, error=str(exc), evidence="download_sha256", pages=pages)


def acquire(root: Path, start: str, end: str, *, symbols: tuple[str, ...] = SYMBOLS,
            include_funding: bool = False, client: httpx.Client | None = None,
            now: datetime | None = None, root_cap_bytes: int = DEFAULT_CAP,
            reserve_bytes: int = DEFAULT_RESERVE,
            sleep: Callable[[float], None] = time.sleep) -> AcquisitionResult:
    beginning, ending = _utc(start), _utc(end)
    if not symbols or len(set(symbols)) != len(symbols) or any(s not in SYMBOLS for s in symbols):
        raise AcquisitionError("invalid symbol universe")
    if root_cap_bytes < MAX_ZIP_BYTES or reserve_bytes < 0:
        raise AcquisitionError("capacity limits too small")
    try:
        for symbol in symbols:
            plan_archives(symbol, beginning, ending)
    except ValueError as exc:
        raise AcquisitionError(str(exc)) from exc
    current = now or datetime.now(timezone.utc)
    if current.tzinfo is None or current.utcoffset() != timedelta(0):
        raise AcquisitionError("UTC now required")
    identity = {"schema": SCHEMA, "source": SOURCE, "symbols": list(symbols),
                "start": beginning.isoformat(), "end": ending.isoformat(),
                "include_funding": include_funding}
    expected_keys = set()
    for symbol in symbols:
        for kind in (("klines", "funding") if include_funding else ("klines",)):
            for source in plan_archives(symbol, beginning, ending, kind):
                expected_keys.add((symbol, kind, max(beginning, source.start).isoformat(),
                                   min(ending, source.end).isoformat()))
    requested_root = Path(root)
    clean_root = not requested_root.exists() or (
        requested_root.is_dir() and not any(requested_root.iterdir())
    )
    target = _safe_root(requested_root, identity, expected_keys, root_cap_bytes, reserve_bytes)
    if shutil.disk_usage(target).free < reserve_bytes:
        raise AcquisitionError("free-space reserve exceeded")
    lock_path = target / ".writer.lock"
    if lock_path.exists() and not _regular(lock_path):
        raise AcquisitionError("unsafe writer lock")
    try:
        lock_fd = os.open(lock_path, os.O_RDWR | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        lock_created = True
    except FileExistsError:
        lock_fd = os.open(lock_path, os.O_RDWR | os.O_NOFOLLOW, 0o600)
        lock_created = False
    lock_stat = os.fstat(lock_fd)
    if (not stat.S_ISREG(lock_stat.st_mode) or lock_stat.st_uid != os.geteuid()
            or stat.S_IMODE(lock_stat.st_mode) != 0o600):
        os.close(lock_fd)
        raise AcquisitionError("unsafe writer lock")
    owned = client is None
    if owned:
        client = httpx.Client(timeout=30, follow_redirects=False)
    try:
        try:
            fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError as exc:
            raise AcquisitionError("another writer holds the root lock") from exc
        manifest_path = target / "manifest.json"
        if not manifest_path.exists() and not manifest_path.is_symlink():
            entries = list(target.iterdir())
            if entries:
                if clean_root and lock_created and {entry.name for entry in entries} == {".writer.lock"}:
                    manifest = None
                else:
                    temp_path, manifest = _interrupted_initial_manifest(target, identity)
                    os.replace(temp_path, manifest_path)
            else:
                manifest = None
        if manifest_path.exists() or manifest_path.is_symlink():
            try:
                manifest = json.loads(_read_file(manifest_path, max_bytes=MAX_ZIP_BYTES))
            except (ValueError, UnicodeError) as exc:
                raise AcquisitionError("invalid manifest") from exc
            if manifest.get("identity") != identity:
                raise AcquisitionError("root identity conflict")
        else:
            manifest = {"identity": identity, "complete": False, "sources": []}
            _manifest_write(manifest_path, manifest)
        existing = {(e["symbol"], e["kind"], e["start"], e["end"]): e
                    for e in manifest["sources"]}
        _validate_manifest_sources(manifest, expected_keys)

        def capacity(next_bytes: int) -> None:
            stored = _root_size(target)
            if stored + next_bytes > root_cap_bytes or shutil.disk_usage(target).free - next_bytes < reserve_bytes:
                raise AcquisitionError("root capacity or free-space reserve exceeded")

        def record(entry: dict) -> None:
            key = (entry["symbol"], entry["kind"], entry["start"], entry["end"])
            manifest["sources"] = [e for e in manifest["sources"] if
                                   (e["symbol"], e["kind"], e["start"], e["end"]) != key]
            manifest["sources"].append(entry)
            actual = {(e["symbol"], e["kind"], e["start"], e["end"]) for e in manifest["sources"]}
            manifest["complete"] = actual == expected_keys and all(
                e["status"] == "ok" for e in manifest["sources"])
            _manifest_write(manifest_path, manifest)

        for symbol in symbols:
            kinds = ("klines", "funding") if include_funding else ("klines",)
            for kind in kinds:
                for source in plan_archives(symbol, beginning, ending, kind):
                    source_start = max(beginning, source.start)
                    source_end = min(ending, source.end)
                    key = (symbol, kind, source_start.isoformat(), source_end.isoformat())
                    prior = existing.get(key)
                    if prior and prior["status"] in {"ok", "incomplete"}:
                        if prior.get("evidence") == "official_archive_checksum":
                            _stored_archive(target, prior, source, source_start, source_end)
                        elif prior.get("evidence") == "download_sha256":
                            _stored_tail(target, prior, symbol, source_start, source_end)
                        else:
                            raise AcquisitionError("unknown resumed evidence")
                        continue
                    if source_start >= current:
                        entry = _entry(source, symbol=symbol, kind=kind, start=source_start,
                                       end=source_end, status="missing", error="future interval")
                    elif (kind == "klines" and source.start.date() == current.date()
                            and source.start <= current):
                        entry = _tail(target, symbol, source_start, source_end, client, sleep,
                                      capacity, current)
                    else:
                        entry = _archive(target, source, source_start, source_end, client, sleep, capacity)
                        if (entry["status"] == "missing" and kind == "klines"
                                and source.start >= current - timedelta(days=TAIL_DAYS)
                                and source_start < current):
                            entry = _tail(target, symbol, source_start, source_end, client, sleep,
                                          capacity, current)
                    record(entry)
        actual_keys = {(e["symbol"], e["kind"], e["start"], e["end"])
                       for e in manifest["sources"]}
        result = AcquisitionResult(actual_keys == expected_keys
                                   and all(e["status"] == "ok" for e in manifest["sources"]),
                                   tuple(manifest["sources"]), manifest_path)
        manifest["complete"] = result.complete
        _manifest_write(manifest_path, manifest)
        return result
    finally:
        if owned:
            client.close()
        os.close(lock_fd)


def main(argv: list[str] | None = None, *, client: httpx.Client | None = None,
         reserve_bytes: int = DEFAULT_RESERVE) -> int:
    parser = argparse.ArgumentParser(description="Acquire verified public Binance USD-M history")
    parser.add_argument("--root", type=Path, required=True)
    parser.add_argument("--start", required=True)
    parser.add_argument("--end", required=True)
    parser.add_argument("--symbols", default=",".join(SYMBOLS))
    parser.add_argument("--include-funding", action="store_true")
    args = parser.parse_args(argv)
    try:
        result = acquire(args.root, args.start, args.end,
                         symbols=tuple(args.symbols.split(",")), include_funding=args.include_funding,
                         client=client, reserve_bytes=reserve_bytes)
    except AcquisitionError as exc:
        print(f"acquisition error: {exc}", file=sys.stderr)
        return 2
    print(json.dumps({"complete": result.complete, "manifest": str(result.manifest_path),
                      "sources": len(result.sources)}))
    return 0 if result.complete else 1


if __name__ == "__main__":
    raise SystemExit(main())
