"""The L2 capture pilot: frozen spec, guards, instrument selection, raw gzip JSONL segments, manifest, WebSocket loop.

One run = one process, one output directory, one WebSocket connection to the EEA public host, the channels of the frozen
spec (`books`, `books5`, `trades`) for exactly two X-Perps (BTC-USD and ETH-USD underlyings), at most 24 h, at most 5 GiB
written, at least 10 GiB kept free. Every limit stops the run cleanly with a recorded reason; nothing restarts or extends a
run (the output directory must be new or empty, so a second run needs a new command and a new directory).

What is stored, per received frame, in availability order: the receive counter `rx`, the local wall clock `recv_ns`, the
monotonic clock `mono_ns`, the connection index, a header (`book.frame_header`: ids, seqId / prevSeqId, exchange ts, checksum
value, counts — no price) and the frame text exactly as delivered (`raw`). Meta records (connect, subscribe sent,
resubscription, disconnect, stop) are interleaved in the same stream. No reduction to L1, no filtering of levels.

Network: `fetch_instruments_raw` (REST public instruments, metadata) and `PilotCapture.run` (public WebSocket) are the only
network paths; the tests never reach them (socket guard, injected connector).
"""

from __future__ import annotations

import asyncio
import gzip
import hashlib
import json
import os
import re
import shutil
import signal
import sys
import time
import urllib.request
import zlib
from dataclasses import dataclass, field, fields, replace
from pathlib import Path

from scripts.research_lp import tape
from scripts.research_lp import xperp_capture as xc

from . import book as bk

SPEC_SCHEMA = "l2-pilot-spec/1"
MANIFEST_SCHEMA = "l2-pilot-manifest/1"
RECORD_SCHEMA = 1
LOCK_NAME = ".l2-pilot.lock"
SEGMENTS_DIR = "segments"
MANIFEST_NAME = "manifest.json"
DEFAULT_SPEC = Path(__file__).resolve().parent / "specs" / "l2_pilot_spec_v1.json"
KNOWN_CHANNELS = ("books", "books5", "trades")
SPEC_KEYS = ("schema", "spec_id", "version", "status", "frozen_at_utc", "purpose", "authorization", "venue", "selection", "channels", "limits", "storage",
             "quality_criteria", "protected_path_tokens", "protection")
EXIT_OK, EXIT_REFUSED, EXIT_LOCK, EXIT_INVALID, EXIT_FREE_SPACE = 0, 2, 3, 4, 5


class PilotRefused(RuntimeError):
    """A guard refused the run before anything was written (or, for the lock, before the run started)."""

    def __init__(self, reason: str, detail: str, exit_code: int = EXIT_INVALID) -> None:
        super().__init__(f"{reason}: {detail}")
        self.reason = reason
        self.detail = detail
        self.exit_code = exit_code


# ----------------------------------------------------------------------------- code identity


def code_identity() -> dict[str, str]:
    package = Path(__file__).resolve().parent
    files = sorted(package.glob("*.py")) + sorted((package / "specs").glob("*.json"))
    return {str(path.relative_to(package)): hashlib.sha256(path.read_bytes()).hexdigest() for path in files}


def code_digest() -> str:
    return hashlib.sha256(json.dumps(code_identity(), sort_keys=True, separators=(",", ":")).encode()).hexdigest()


# ----------------------------------------------------------------------------- spec


@dataclass(frozen=True)
class LoadedSpec:
    path: Path
    sha256: str
    doc: dict

    @property
    def limits(self) -> dict:
        return self.doc["limits"]

    @property
    def not_before_ns(self) -> int:
        return tape.parse_iso_utc(self.limits["not_before_utc"]) * 1000


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sidecar_path(spec_path: Path) -> Path:
    return spec_path.with_name(spec_path.name + ".sha256")


def load_spec(path: Path, expect_sha256: str | None) -> LoadedSpec:
    """Read the frozen pilot spec. Refused unless: its sha256 equals the sidecar `<spec>.sha256` and `expect_sha256`; schema
    `l2-pilot-spec/1`; status FROZEN; no unknown or missing top-level key; channels known; not-before at or after the LP
    quarantine floor."""
    path = Path(path)
    data = path.read_bytes()
    digest = sha256_bytes(data)
    side = sidecar_path(path)
    if not side.exists():
        raise PilotRefused("spec_sidecar_missing", str(side))
    frozen = side.read_text().split()[0] if side.read_text().split() else ""
    if frozen != digest:
        raise PilotRefused("spec_sha256_mismatch", f"file {digest} != sidecar {frozen}")
    if expect_sha256 is not None and expect_sha256 != digest:
        raise PilotRefused("spec_sha256_mismatch", f"file {digest} != expected {expect_sha256}")
    doc = json.loads(data)
    if doc.get("schema") != SPEC_SCHEMA:
        raise PilotRefused("spec_schema", str(doc.get("schema")))
    if doc.get("status") != "FROZEN":
        raise PilotRefused("spec_not_frozen", str(doc.get("status")))
    unknown = sorted(set(doc) - set(SPEC_KEYS))
    missing = sorted(set(SPEC_KEYS) - set(doc))
    if unknown or missing:
        raise PilotRefused("spec_keys", f"unknown {unknown} missing {missing}")
    channels = doc["channels"]["subscribed"]
    if not channels or any(c not in KNOWN_CHANNELS for c in channels):
        raise PilotRefused("spec_channels", str(channels))
    spec = LoadedSpec(path=path, sha256=digest, doc=doc)
    if spec.not_before_ns < xc.quarantine_floor_us() * 1000:
        raise PilotRefused("not_before_below_floor", f"{spec.limits['not_before_utc']} < {xc.QUARANTINE_FLOOR}", EXIT_REFUSED)
    return spec


# ----------------------------------------------------------------------------- limits


@dataclass(frozen=True)
class PilotLimits:
    max_duration_s: float
    max_bytes_written: int
    stop_margin_bytes: int
    min_free_bytes: int
    free_space_check_every_s: float
    segment_max_s: float
    segment_max_bytes: int
    flush_every_s: float
    max_reconnects: int
    resync_min_interval_s: float
    resync_max_per_hour: int
    ping_every_s: float
    recv_timeout_s: float

    @classmethod
    def from_spec(cls, spec: LoadedSpec, **overrides) -> "PilotLimits":
        """The spec values, optionally made STRICTER: a duration, byte budget or segment size can only be lowered, a free-space
        floor only raised. A looser value is refused (the spec caps are the owner's authorization)."""
        base = cls(**{f.name: type_of(f.name)(spec.limits[f.name]) for f in fields(cls)})
        clean = {k: v for k, v in overrides.items() if v is not None}
        lower_only = ("max_duration_s", "max_bytes_written", "segment_max_s", "segment_max_bytes", "max_reconnects", "resync_max_per_hour")
        raise_only = ("min_free_bytes", "stop_margin_bytes")
        for key, value in clean.items():
            if key not in {f.name for f in fields(cls)}:
                raise PilotRefused("limits_unknown", key)
            current = getattr(base, key)
            if key in lower_only and value > current:
                raise PilotRefused("limit_above_authorization", f"{key}={value} > {current}")
            if key in raise_only and value < current:
                raise PilotRefused("limit_below_authorization", f"{key}={value} < {current}")
            if value <= 0 and key != "stop_margin_bytes":
                raise PilotRefused("limit_not_positive", f"{key}={value}")
        return replace(base, **{k: type_of(k)(v) for k, v in clean.items()})


_INT_LIMITS = {"max_bytes_written", "stop_margin_bytes", "min_free_bytes", "segment_max_bytes", "max_reconnects", "resync_max_per_hour"}


def type_of(name: str):
    return int if name in _INT_LIMITS else float


# ----------------------------------------------------------------------------- paths and disk


def protected_component(path: Path, tokens) -> str | None:
    """The first component of the resolved path that names a protected tree (`forward-okx*`, `g5*`, `scalp-q3*`, `sealed*`…):
    a token at the start of a component or right after `-`, `_` or `.`."""
    pattern = re.compile(r"(?:^|[-_.])(?:" + "|".join(re.escape(t) for t in tokens) + r")", re.IGNORECASE)
    for part in Path(os.path.abspath(path)).resolve(strict=False).parts:
        if pattern.search(part):
            return part
    return None


def check_output_dir(out_dir: Path, tokens) -> None:
    hit = protected_component(out_dir, tokens)
    if hit is not None:
        raise PilotRefused("protected_output_path", f"{out_dir} (component {hit!r})")
    out = Path(out_dir)
    if out.exists() and not out.is_dir():
        raise PilotRefused("output_not_a_directory", str(out))
    if out.exists() and any(out.iterdir()):
        raise PilotRefused("output_dir_not_empty", f"{out}: a run never resumes or extends; use a new directory")


def nearest_existing(path: Path) -> Path:
    path = Path(os.path.abspath(path))
    while not path.exists() and path != path.parent:
        path = path.parent
    return path


def free_bytes(path: Path) -> int:
    return int(shutil.disk_usage(nearest_existing(path)).free)


# ----------------------------------------------------------------------------- instruments


def fetch_instruments_raw(rest: str, path: str, timeout: float = 20.0) -> bytes:
    """REST `GET /api/v5/public/instruments?instType=FUTURES` on the EEA host: contract metadata, no market value. Called once,
    by `cli capture`, at launch. Never called by the tests."""
    request = urllib.request.Request(rest + path, headers={"User-Agent": "tradingV3-research-l2-pilot/1.0"})
    with urllib.request.urlopen(request, timeout=timeout) as response:
        return response.read()


def parse_instruments(raw: bytes) -> list[dict]:
    document = json.loads(raw)
    if isinstance(document, dict):
        if "code" in document and str(document.get("code")) != "0":
            raise PilotRefused("instruments_error", f"{document.get('code')} {document.get('msg')}")
        rows = document.get("data")
    else:
        rows = document
    if not isinstance(rows, list):
        raise PilotRefused("instruments_unreadable", "no data list")
    return [row for row in rows if isinstance(row, dict)]


def select_instruments(rows: list[dict], selection: dict) -> list[dict]:
    """Exactly one row per underlying of the spec, by metadata only: `instType` FUTURES, `ruleType` xperp, `state` live,
    `uly` = the underlying, `instId` of the X-Perp shape. Every field must be present (no default). Refused otherwise."""
    id_re = re.compile(selection["inst_id_regex"])
    chosen: list[dict] = []
    counts: dict[str, list[str]] = {}
    for underlying in selection["underlyings"]:
        matches = [row for row in rows
                   if row.get("instType") == selection["inst_type"] and row.get("ruleType") == selection["rule_type"] and row.get("state") == selection["state"]
                   and row.get("uly") == underlying and id_re.match(str(row.get("instId", "")))]
        counts[underlying] = sorted(str(r.get("instId")) for r in matches)
        if len(matches) == 1:
            chosen.append(matches[0])
    if any(len(ids) != 1 for ids in counts.values()):
        raise PilotRefused("selection_not_exactly_one", json.dumps(counts, sort_keys=True))
    return chosen


def units_of(row: dict, keys) -> dict:
    return {key: row.get(key) for key in keys}


# ----------------------------------------------------------------------------- clock


class SystemClock:
    def wall_ns(self) -> int:
        return time.time_ns()

    def mono_ns(self) -> int:
        return time.monotonic_ns()


def iso_ns(ns: int) -> str:
    return tape.iso_utc(ns // 1000)


# ----------------------------------------------------------------------------- segments


class SegmentWriter:
    """gzip JSONL segments `segments/seg-NNNNNN.jsonl.gz` (created exclusively, never reopened); rotation on age or size; each
    closed segment is fsync'ed and hashed. A segment left open by a kill is readable up to its last sync flush."""

    def __init__(self, out_dir: Path, max_s: float, max_bytes: int) -> None:
        self.dir = Path(out_dir) / SEGMENTS_DIR
        self.dir.mkdir(parents=True, exist_ok=False)
        self.max_ns = int(max_s * 1e9)
        self.max_bytes = int(max_bytes)
        self.index = 0
        self.closed: list[dict] = []
        self.closed_bytes = 0
        self.uncompressed_total = 0
        self._raw = None
        self._gz = None
        self._cur: dict | None = None

    def _open(self, mono_ns: int) -> None:
        self.index += 1
        path = self.dir / f"seg-{self.index:06d}.jsonl.gz"
        self._raw = path.open("xb")
        self._gz = gzip.GzipFile(filename="", mode="wb", fileobj=self._raw, mtime=0)
        self._cur = {"index": self.index, "file": f"{SEGMENTS_DIR}/{path.name}", "opened_mono_ns": mono_ns, "records": 0, "uncompressed_bytes": 0,
                     "first_rx": None, "last_rx": None, "first_recv_ns": None, "last_recv_ns": None}

    def write(self, line: bytes, rx: int, recv_ns: int, mono_ns: int) -> None:
        if self._gz is None:
            self._open(mono_ns)
        self._gz.write(line)
        cur = self._cur
        cur["records"] += 1
        cur["uncompressed_bytes"] += len(line)
        self.uncompressed_total += len(line)
        if cur["first_rx"] is None:
            cur["first_rx"], cur["first_recv_ns"] = rx, recv_ns
        cur["last_rx"], cur["last_recv_ns"] = rx, recv_ns

    def current_bytes(self) -> int:
        return self._raw.tell() if self._raw is not None else 0

    def total_bytes(self) -> int:
        return self.closed_bytes + self.current_bytes()

    def rotation_due(self, mono_ns: int) -> bool:
        if self._cur is None:
            return False
        return mono_ns - self._cur["opened_mono_ns"] >= self.max_ns or self.current_bytes() >= self.max_bytes

    def flush(self) -> None:
        if self._gz is not None:
            self._gz.flush(zlib.Z_SYNC_FLUSH)
            self._raw.flush()

    def close_current(self) -> dict | None:
        if self._gz is None:
            return None
        self._gz.close()
        self._raw.flush()
        os.fsync(self._raw.fileno())
        self._raw.close()
        path = self.dir.parent / self._cur["file"]
        entry = {key: value for key, value in self._cur.items() if key != "opened_mono_ns"}
        entry["bytes"] = path.stat().st_size
        entry["sha256"] = tape.sha256_of(path)
        entry["first_recv_utc"] = iso_ns(entry["first_recv_ns"]) if entry["first_recv_ns"] else None
        entry["last_recv_utc"] = iso_ns(entry["last_recv_ns"]) if entry["last_recv_ns"] else None
        self.closed.append(entry)
        self.closed_bytes += entry["bytes"]
        self._raw = self._gz = self._cur = None
        return entry

    def open_segment(self) -> dict | None:
        if self._cur is None:
            return None
        return {"file": self._cur["file"], "records": self._cur["records"], "bytes_so_far": self.current_bytes(), "status": "open (hashed at close)"}


# ----------------------------------------------------------------------------- lock


class PilotLock:
    """`flock` on `<out>/.l2-pilot.lock` (distinct from the LP collector's `.lp-capture.lock`)."""

    def __init__(self, out_dir: Path) -> None:
        self.path = Path(out_dir) / LOCK_NAME
        self._handle = None

    def acquire(self) -> "PilotLock":
        import fcntl

        handle = self.path.open("a+")
        try:
            fcntl.flock(handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError as error:
            handle.close()
            raise PilotRefused("lock_held", f"{self.path}: {error}", EXIT_LOCK) from None
        handle.seek(0)
        handle.truncate()
        handle.write(f"{os.getpid()} {tape.iso_now()}\n")
        handle.flush()
        self._handle = handle
        return self

    def release(self) -> None:
        if self._handle is not None:
            import fcntl

            fcntl.flock(self._handle.fileno(), fcntl.LOCK_UN)
            self._handle.close()
            self._handle = None


# ----------------------------------------------------------------------------- capture


@dataclass
class PilotConfig:
    out_dir: Path
    spec: LoadedSpec
    authorization_ref: str
    limits: PilotLimits
    argv: list[str] = field(default_factory=list)
    echo: bool = True


def preflight(cfg: PilotConfig, clock, free_fn) -> None:
    """Every guard that needs no network and writes nothing: authorization text, protected path, empty directory, date, free
    space. Run by the CLI before the instruments request, and again by `PilotCapture` before anything is written."""
    if not cfg.authorization_ref or not cfg.authorization_ref.strip():
        raise PilotRefused("authorization_ref_missing", "--authorization-ref TEXT is required")
    check_output_dir(cfg.out_dir, cfg.spec.doc["protected_path_tokens"])
    now = clock.wall_ns()
    if now < cfg.spec.not_before_ns:
        raise PilotRefused("before_not_before", f"now {iso_ns(now)} < {cfg.spec.limits['not_before_utc']}", EXIT_REFUSED)
    free = free_fn(cfg.out_dir)
    if free < cfg.limits.min_free_bytes:
        raise PilotRefused("free_space_below_min", f"{free} < {cfg.limits.min_free_bytes} bytes free", EXIT_FREE_SPACE)


class PilotCapture:
    """`handle_raw` and `check_limits` are synchronous and network-free (what the tests drive); `run` owns the WebSocket."""

    def __init__(self, cfg: PilotConfig, selected: list[dict], instruments_raw: bytes, clock=None, free_fn=free_bytes) -> None:
        self.cfg = cfg
        self.spec = cfg.spec
        self.limits = cfg.limits
        self.clock = clock or SystemClock()
        self.free_fn = free_fn
        preflight(cfg, self.clock, free_fn)
        self.inst_ids = [str(row["instId"]) for row in selected]
        if len(self.inst_ids) != len(self.spec.doc["selection"]["underlyings"]):
            raise PilotRefused("selection_not_exactly_one", str(self.inst_ids))
        self.out = Path(cfg.out_dir)
        self.out.mkdir(parents=True, exist_ok=True)
        self.lock = PilotLock(self.out).acquire()
        try:
            self.health = xc.HealthJournal(self.out / "health.log", echo=cfg.echo)
            (self.out / "pilot-spec.json").write_bytes(self.spec.path.read_bytes())
            (self.out / "instruments-futures-raw.json").write_bytes(instruments_raw)
            keys = self.spec.doc["selection"]["units_kept"]
            selected_doc = {"source": self.spec.doc["venue"]["rest"] + self.spec.doc["venue"]["instruments_path"], "taken_at_utc": iso_ns(self.clock.wall_ns()),
                            "raw_file": "instruments-futures-raw.json", "raw_sha256": sha256_bytes(instruments_raw), "selection_rule": self.spec.doc["selection"],
                            "selected": [units_of(row, keys) for row in selected]}
            tape.atomic_write_text(self.out / "instruments-selected.json", json.dumps(selected_doc, indent=1, sort_keys=True) + "\n")
            self.writer = SegmentWriter(self.out, self.limits.segment_max_s, self.limits.segment_max_bytes)
        except Exception:
            self.lock.release()
            raise
        self.host = self.spec.doc["venue"]["ws_public"]
        self.channels = tuple(self.spec.doc["channels"]["subscribed"])
        self.rx = 0
        self.conn = 0
        self.connected = False
        self.trackers = {inst: bk.SeqTracker() for inst in self.inst_ids}
        self.invalid_since_mono: dict[str, int | None] = {inst: None for inst in self.inst_ids}
        self.last_resync_mono: dict[str, int | None] = {inst: None for inst in self.inst_ids}
        self.resync_times: list[int] = []
        self.counts: dict[str, int] = {}
        self.seq_verdicts: dict[str, dict[str, int]] = {inst: {} for inst in self.inst_ids}
        self.channel_seen: dict[str, set] = {inst: set() for inst in self.inst_ids}
        self.reconnects = 0
        self.gaps: list[dict] = []
        self.resyncs: list[dict] = []
        self.errors: list[dict] = []
        self.connections: list[dict] = []
        self.free_checks = {"count": 0, "min_free_bytes": None, "last_free_bytes": None}
        self.stopping = False
        self.stop_reason = ""
        self.stop_detail = ""
        self.closed = False
        self.started_wall_ns = self.clock.wall_ns()
        self.started_mono_ns = self.clock.mono_ns()
        self.last_free_check_mono = self.started_mono_ns
        self.last_flush_mono = self.started_mono_ns
        self.last_manifest_mono = self.started_mono_ns
        self._stop_event: asyncio.Event | None = None
        self._note_free(free_fn(self.out))
        self.save_manifest()
        self.health.log("INFO", "started", host=self.host, instruments=",".join(self.inst_ids), channels=",".join(self.channels),
                        spec_sha256=self.spec.sha256, max_duration_s=self.limits.max_duration_s, max_bytes=self.limits.max_bytes_written,
                        min_free_bytes=self.limits.min_free_bytes)

    # -- accounting

    def _count(self, key: str, n: int = 1) -> None:
        self.counts[key] = self.counts.get(key, 0) + n

    def _note_free(self, free: int) -> None:
        self.free_checks["count"] += 1
        self.free_checks["last_free_bytes"] = int(free)
        if self.free_checks["min_free_bytes"] is None or free < self.free_checks["min_free_bytes"]:
            self.free_checks["min_free_bytes"] = int(free)

    def other_files_bytes(self) -> int:
        total = 0
        for name in (MANIFEST_NAME, "health.log", "pilot-spec.json", "instruments-futures-raw.json", "instruments-selected.json", LOCK_NAME):
            try:
                total += (self.out / name).stat().st_size
            except OSError:
                pass
        return total

    def bytes_written(self) -> int:
        return self.writer.total_bytes() + self.other_files_bytes()

    # -- records

    def _write(self, record: dict) -> None:
        self.rx += 1
        record = {"v": RECORD_SCHEMA, "rx": self.rx, "recv_ns": self.clock.wall_ns(), "mono_ns": self.clock.mono_ns(), "conn": self.conn, **record}
        line = (json.dumps(record, separators=(",", ":"), ensure_ascii=False) + "\n").encode("utf-8")
        self.writer.write(line, record["rx"], record["recv_ns"], record["mono_ns"])

    def meta(self, event: str, **fields_) -> None:
        self._write({"k": "meta", "event": event, **fields_})

    def handle_raw(self, raw) -> list[tuple[str, str]]:
        """Write one received frame (raw text + header) and update the live seqId tracking. Returns the actions for the loop:
        `("resync", instId)` when a `books` stream breaks and a resubscription is allowed."""
        text = raw.decode("utf-8") if isinstance(raw, (bytes, bytearray)) else str(raw)
        header = bk.frame_header(text)
        kind = header["t"]
        self._count(f"frames_{kind}")
        if kind == "pong":
            return []
        self._write({"k": "frame", "h": header, "raw": text})
        actions: list[tuple[str, str]] = []
        if kind == "event":
            self._on_event(header)
        elif kind == "data":
            inst, channel = header.get("inst", ""), header.get("ch", "")
            if inst in self.channel_seen:
                self.channel_seen[inst].add(channel if channel != "books" else f"books_{header.get('action', '')}")
            self._count(f"data_{channel}")
            if channel in bk.BOOK_CHANNELS and inst in self.trackers:
                actions.extend(self._on_books(inst, header))
        return actions

    def _on_event(self, header: dict) -> None:
        event = header.get("event", "")
        self._count(f"event_{event}")
        if event == "error":
            entry = {"at_utc": iso_ns(self.clock.wall_ns()), "rx": self.rx, "code": header.get("code"), "msg": header.get("msg")}
            self.errors.append(entry)
            self.health.log("ERROR", "ws_error", code=header.get("code", ""), msg=header.get("msg", ""))
        elif event == "unsubscribe" and header.get("ch") in bk.BOOK_CHANNELS and header.get("inst") in self.trackers:
            self._invalidate(header["inst"], "unsubscribed")

    def _invalidate(self, inst: str, reason: str) -> None:
        self.trackers[inst].invalidate(reason)
        if self.invalid_since_mono[inst] is None:
            self.invalid_since_mono[inst] = self.clock.mono_ns()

    def _on_books(self, inst: str, header: dict) -> list[tuple[str, str]]:
        tracker = self.trackers[inst]
        verdict = tracker.classify(header.get("action"), header.get("prev"), header.get("seq"))
        self.seq_verdicts[inst][verdict] = self.seq_verdicts[inst].get(verdict, 0) + 1
        if verdict == bk.SNAPSHOT:
            self.invalid_since_mono[inst] = None
            return []
        if verdict in bk.BREAKS:
            self.gaps.append({"inst": inst, "rx": self.rx, "kind": verdict, "at_utc": iso_ns(self.clock.wall_ns()), "seq": header.get("seq"), "prev": header.get("prev")})
            if self.invalid_since_mono[inst] is None:
                self.invalid_since_mono[inst] = self.clock.mono_ns()
            self.health.log("WARN", "books_break", inst=inst, kind=verdict)
        if not tracker.valid and self.resync_allowed(inst):
            return [("resync", inst)]
        return []

    def resync_allowed(self, inst: str) -> bool:
        now = self.clock.mono_ns()
        last = self.last_resync_mono[inst]
        if last is not None and now - last < self.limits.resync_min_interval_s * 1e9:
            return False
        hour_ago = now - 3600 * 10**9
        self.resync_times = [t for t in self.resync_times if t > hour_ago]
        return len(self.resync_times) < self.limits.resync_max_per_hour

    def resync_messages(self, inst: str) -> list[str]:
        """Unsubscribe then subscribe `books` for one instrument: OKX answers a subscription with a new full snapshot."""
        now = self.clock.mono_ns()
        self.last_resync_mono[inst] = now
        self.resync_times.append(now)
        self._invalidate(inst, "resync")
        self.resyncs.append({"inst": inst, "rx": self.rx + 1, "at_utc": iso_ns(self.clock.wall_ns())})
        arg = {"channel": "books", "instId": inst}
        self.meta("resync_sent", inst=inst)
        self.health.log("INFO", "resync", inst=inst)
        return [json.dumps({"op": "unsubscribe", "args": [arg]}), json.dumps({"op": "subscribe", "args": [arg]})]

    def subscription_args(self) -> list[dict]:
        return [{"channel": channel, "instId": inst} for channel in self.channels for inst in self.inst_ids]

    def on_connected(self) -> str:
        self.conn += 1
        self.connected = True
        self.connections.append({"conn": self.conn, "opened_utc": iso_ns(self.clock.wall_ns()), "closed_utc": None, "close_reason": None})
        args = self.subscription_args()
        self.meta("connected", host=self.host)
        self.meta("subscribe_sent", args=args)
        self.health.log("INFO", "connected", conn=self.conn, subscriptions=len(args))
        return json.dumps({"op": "subscribe", "args": args})

    def on_disconnected(self, reason: str) -> None:
        if self.connections and self.connections[-1]["closed_utc"] is None:
            self.connections[-1].update(closed_utc=iso_ns(self.clock.wall_ns()), close_reason=reason[:200])
        was_connected = self.connected
        self.connected = False
        for inst in self.inst_ids:
            self._invalidate(inst, "disconnected")
        self.meta("disconnected", reason=reason[:200])
        if was_connected and not self.stopping:
            self.reconnects += 1
            self.health.log("WARN", "disconnected", reason=reason[:160], reconnects=self.reconnects)
            if self.reconnects > self.limits.max_reconnects:
                self.request_stop("max_reconnects", f"{self.reconnects} > {self.limits.max_reconnects}")

    # -- limits and lifecycle

    def check_limits(self) -> str | None:
        """Duration (monotonic and wall clock), bytes written, free space (every `free_space_check_every_s`), STOP file."""
        if self.stopping:
            return self.stop_reason
        mono, wall = self.clock.mono_ns(), self.clock.wall_ns()
        limit_ns = int(self.limits.max_duration_s * 1e9)
        if mono - self.started_mono_ns >= limit_ns or wall - self.started_wall_ns >= limit_ns:
            self.request_stop("max_duration", f"{self.limits.max_duration_s} s")
        elif self.bytes_written() >= self.limits.max_bytes_written - self.limits.stop_margin_bytes:
            self.request_stop("max_bytes", f"{self.bytes_written()} >= {self.limits.max_bytes_written} - {self.limits.stop_margin_bytes}")
        elif mono - self.last_free_check_mono >= self.limits.free_space_check_every_s * 1e9:
            self.last_free_check_mono = mono
            free = self.free_fn(self.out)
            self._note_free(free)
            if free < self.limits.min_free_bytes:
                self.request_stop("min_free_space", f"{free} < {self.limits.min_free_bytes}")
        if not self.stopping and (self.out / "STOP").exists():
            self.request_stop("stop_file", "STOP file")
        return self.stop_reason if self.stopping else None

    def housekeeping(self) -> None:
        """Rotation, periodic sync flush and manifest checkpoint (called by the loop every 0.5 s and by the tests)."""
        mono = self.clock.mono_ns()
        if self.writer.rotation_due(mono):
            entry = self.writer.close_current()
            if entry:
                self.health.log("INFO", "segment_closed", file=entry["file"], bytes=entry["bytes"], sha256=entry["sha256"])
                self.save_manifest()
        if mono - self.last_flush_mono >= self.limits.flush_every_s * 1e9:
            self.writer.flush()
            self.last_flush_mono = mono
        if mono - self.last_manifest_mono >= 60e9:
            self.save_manifest()
            self.last_manifest_mono = mono

    def request_stop(self, reason: str, detail: str = "") -> None:
        if not self.stopping:
            self.stopping = True
            self.stop_reason = reason
            self.stop_detail = detail
            self.health.log("INFO", "stop_requested", reason=reason, detail=detail)
        if self._stop_event is not None:
            self._stop_event.set()

    def depth_status(self) -> dict[str, str]:
        out = {}
        for inst, seen in self.channel_seen.items():
            out[inst] = "books" if "books_snapshot" in seen else "books5_only" if "books5" in seen else "none"
        return out

    def manifest(self) -> dict:
        end_ns = self.started_wall_ns + int(self.limits.max_duration_s * 1e9)
        return {
            "schema": MANIFEST_SCHEMA, "record_schema": RECORD_SCHEMA, "status": "stopped" if self.closed else "running",
            "spec": {"path": str(self.spec.path), "sha256": self.spec.sha256, "spec_id": self.spec.doc["spec_id"], "version": self.spec.doc["version"], "copy": "pilot-spec.json"},
            "code": {"package": "scripts.research_l2", "digest": code_digest(), "files": code_identity()},
            "authorization_ref": self.cfg.authorization_ref, "argv": list(self.cfg.argv), "pid": os.getpid(), "host": self.host, "channels": list(self.channels),
            "limits": {f.name: getattr(self.limits, f.name) for f in fields(self.limits)},
            "instruments": {"selected": self.inst_ids, "file": "instruments-selected.json", "raw_file": "instruments-futures-raw.json",
                            "raw_sha256": tape.sha256_of(self.out / "instruments-futures-raw.json")},
            "bounds": {"not_before_utc": self.spec.limits["not_before_utc"], "started_at_utc": iso_ns(self.started_wall_ns), "planned_end_at_most_utc": iso_ns(end_ns),
                       "ended_at_utc": iso_ns(self.clock.wall_ns()) if self.closed else None},
            "stop": {"reason": self.stop_reason or None, "detail": self.stop_detail or None},
            "segments": list(self.writer.closed), "open_segment": self.writer.open_segment(),
            "bytes": {"segments_compressed": self.writer.total_bytes(), "segments_uncompressed": self.writer.uncompressed_total, "other_files": self.other_files_bytes(),
                      "written_total": self.bytes_written(), "cap": self.limits.max_bytes_written},
            "free_space": dict(self.free_checks), "records": self.rx, "counts": dict(sorted(self.counts.items())),
            "books_seq_verdicts": self.seq_verdicts, "gaps": self.gaps, "resyncs": self.resyncs, "reconnects": self.reconnects, "connections": self.connections,
            "errors": self.errors, "depth_status": self.depth_status(), "contains_prices_in_manifest": False,
        }

    def save_manifest(self) -> None:
        tape.atomic_write_text(self.out / MANIFEST_NAME, json.dumps(self.manifest(), indent=1, sort_keys=True) + "\n")

    def close(self) -> None:
        """Last segment closed and hashed, `stopped` journaled, manifest saved, lock released. Idempotent; never restarts."""
        if self.closed:
            return
        try:
            if not self.stop_reason:
                self.stop_reason = "ended"
            try:
                self.meta("stopped", reason=self.stop_reason, detail=self.stop_detail)
            except Exception as error:  # noqa: BLE001 - the disk may be the cause of the stop
                self.errors.append({"at_utc": iso_ns(self.clock.wall_ns()), "close_meta_error": f"{type(error).__name__}: {error}"})
            entry = self.writer.close_current()
            if entry:
                self.health.log("INFO", "segment_closed", file=entry["file"], bytes=entry["bytes"], sha256=entry["sha256"])
        finally:
            self.closed = True
            self.save_manifest()
            self.health.log("INFO", "stopped", reason=self.stop_reason, detail=self.stop_detail, records=self.rx, bytes=self.bytes_written())
            self.lock.release()

    # -- network loop

    async def run(self, connector=None) -> None:
        self._stop_event = asyncio.Event()
        if self.stopping:
            self._stop_event.set()
        loop = asyncio.get_running_loop()
        installed = []
        for sig in (signal.SIGTERM, signal.SIGINT):
            try:
                loop.add_signal_handler(sig, self.request_stop, sig.name, "signal")
                installed.append(sig)
            except (NotImplementedError, RuntimeError, ValueError):
                self.health.log("WARN", "signal_handler_unavailable", signal=sig.name)
        tasks = [asyncio.create_task(self._ws_loop(connector or default_connector)), asyncio.create_task(self._housekeeping_loop())]
        try:
            await asyncio.gather(*tasks)
        except Exception as error:  # noqa: BLE001 - fatal error: clean stop with the reason
            self.request_stop("fatal_error", f"{type(error).__name__}: {str(error)[:200]}")
        finally:
            for sig in installed:
                try:
                    loop.remove_signal_handler(sig)
                except (NotImplementedError, RuntimeError, ValueError):  # pragma: no cover
                    pass
            for task in tasks:
                task.cancel()
            self.close()

    async def _housekeeping_loop(self) -> None:
        while self.check_limits() is None:
            try:
                await asyncio.wait_for(self._stop_event.wait(), timeout=0.5)
            except asyncio.TimeoutError:
                pass
            self.housekeeping()

    async def _ws_loop(self, connector) -> None:
        backoff = 1.0
        while not self.stopping:
            try:
                async with connector(self.host) as ws:
                    await ws.send(self.on_connected())
                    backoff = 1.0
                    pinger = asyncio.create_task(self._pinger(ws))
                    try:
                        while not self.stopping:
                            raw = await self._recv_or_stop(ws)
                            if raw is None:
                                break
                            try:
                                actions = self.handle_raw(raw)
                            except Exception as error:  # noqa: BLE001 - local failure (disk, encoding): never retried as a network error
                                self.request_stop("fatal_error", f"{type(error).__name__}: {str(error)[:200]}")
                                break
                            for action, inst in actions:
                                if action == "resync":
                                    for message in self.resync_messages(inst):
                                        await ws.send(message)
                            if self.check_limits() is not None:
                                break
                    finally:
                        pinger.cancel()
                self.on_disconnected("stop" if self.stopping else "closed")
            except asyncio.CancelledError:
                raise
            except Exception as error:  # noqa: BLE001 - websockets closes, timeouts: recorded, reconnected
                if self.connected:
                    self.on_disconnected(f"{type(error).__name__}: {error}")
                else:
                    self._connect_failed(error)
            if self.stopping:
                break
            try:
                await asyncio.wait_for(self._stop_event.wait(), timeout=backoff)
            except asyncio.TimeoutError:
                pass
            backoff = min(backoff * 2.0, 30.0)

    def _connect_failed(self, error: Exception) -> None:
        self.reconnects += 1
        detail = f"{type(error).__name__}: {str(error)[:160]}"
        self.errors.append({"at_utc": iso_ns(self.clock.wall_ns()), "connect_failed": detail})
        self.meta("connect_failed", reason=detail)
        self.health.log("WARN", "connect_failed", error=detail, reconnects=self.reconnects)
        if self.reconnects > self.limits.max_reconnects:
            self.request_stop("max_reconnects", f"{self.reconnects} > {self.limits.max_reconnects}")

    async def _recv_or_stop(self, ws):
        recv = asyncio.ensure_future(ws.recv())
        stop = asyncio.ensure_future(self._stop_event.wait())
        try:
            done, _ = await asyncio.wait({recv, stop}, timeout=self.limits.recv_timeout_s, return_when=asyncio.FIRST_COMPLETED)
        finally:
            stop.cancel()
        if recv in done:
            return recv.result()
        recv.cancel()
        if self.stopping:
            return None
        raise TimeoutError(f"no frame for {self.limits.recv_timeout_s} s")

    async def _pinger(self, ws) -> None:
        while True:
            await asyncio.sleep(self.limits.ping_every_s)
            await ws.send("ping")


def default_connector(url: str):
    import websockets

    return websockets.connect(url, ping_interval=None, max_size=1 << 24, open_timeout=20)


def log_refusal(error: PilotRefused) -> None:
    print(f"{tape.iso_now()} level=ERROR event=refused reason={error.reason} detail={json.dumps(error.detail)}", file=sys.stderr, flush=True)
