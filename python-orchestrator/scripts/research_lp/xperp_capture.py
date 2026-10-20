"""Public OKX collector for `instType FUTURES` (X-Perps `<BASE>-USD_UM_XPERP-<YYMMDD>` and dated
`<BASE>-USD_UM-<YYMMDD>`) over the public WebSocket, into the `tape` layouts.

    python -m scripts.research_lp.cli capture --out DIR [--host global|eea|wss://…] [--inst-ids A,B | --instruments-file F | --family xperp]
    python -m scripts.research_lp.cli count   --out DIR …            # P3: counting only, no value persisted

Public data only: no credential, no private endpoint, no order. Channels (per instrument unless
noted): `trades`, `books5`, `mark-price`, `funding-rate`, and `liquidation-orders`. Hosts: the
global public endpoint `wss://ws.okx.com:8443/ws/v5/public` (the task's default) or the EEA host
`wss://wseea.okx.com/ws/v5/public` in the form the EEA documentation gives (no port;
`docs/handbook/research/LP-DATA-DESK-2026-10-20.md` §0.1 — whether a port is accepted is UNKNOWN,
`--host` takes a raw URL for the counting trial).

**`liquidation-orders` is subscribed per `instType`**, not per instrument (OKX exposes it that
way): the venue pushes the liquidation rows of *every* FUTURES instrument — dated contracts and
families that were not selected included — and the collector persists each row under its own
`instId`. This is deliberate: the G4 detector benefits from a wide universe and the rows carry no
cost on the other channels. `--liq-selected-only` restricts persistence to the selected
instruments (dropped rows are counted as `dropped_unselected`).

Four guards:

- **counting only** (`--counting-only`): messages, rows, bytes and timestamps are counted per
  channel and instrument; no price, size, rate or level is ever decoded or written (the P3
  prerequisite of `research-scalping-theory-gap-2026-10-20.md` §3.3);
- **not-before** (`--not-before`, default `QUARANTINE_FLOOR` = 2026-11-02T00:00:00Z): a value
  capture refuses to start before that instant and drops any row whose exchange timestamp
  precedes it; the counting mode is exempt (it stores no value);
- **quarantine floor**: `--not-before` is accepted only when it is **at or after**
  `QUARANTINE_FLOOR` (rule 13 of the theory report, `LP-PROGRAMME-GATES.md`: the forward test
  window 2026-10-14..31 is quarantined). A lower value is refused in every mode (exit 2, reason
  `not_before_below_floor`, nothing written). The only way below the floor is the explicit test
  hook `CaptureConfig(floor_override_for_tests=True)`, which the command line never sets;
- **one process per directory** (`tape.DirectoryLock`).

Stopping: `--duration-s`, `touch <out>/STOP`, or a signal. `SIGTERM` and `SIGINT` are handled
by the event loop (`Capture.request_stop`): the WebSocket read is interrupted, buffers are
flushed into a last segment, `stopped reason=SIGTERM` is journaled and the manifest saved — a
standard `systemctl stop` therefore loses nothing that reached the process. What a *SIGKILL*
or an OOM kill loses is the RAM buffer since the last checkpoint: `checkpoint_every_s` (default
90 s in value mode) bounds that window; see `tape.py`.

The health journal `health.log` records connections, subscriptions, errors, checkpoints, stale
periods, signals and dropped rows; the manifest keeps sessions, disconnects and errors.

The exact field lists of the FUTURES pushes could not be re-read from docs-v5 on 2026-10-20 (the
rendered page stops before the Market Data section); the parsers are tolerant (missing numbers →
NaN, missing ids → 0, a missing `seqId` → −1 and counted, unknown channels counted under their
own name) and the counting capture is the place where coverage and frequency get established
before any value is written.
"""

from __future__ import annotations

import asyncio
import json
import math
import os
import re
import signal
import sys
import time
import urllib.request
from dataclasses import dataclass, field
from pathlib import Path

from . import tape
from .tape import SECOND_US, TapeStore, iso_now, utc_now_us

try:  # orjson is optional (absent from CI)
    import orjson as _orjson
except ImportError:  # pragma: no cover
    _orjson = None

WS_PUBLIC_GLOBAL = "wss://ws.okx.com:8443/ws/v5/public"
WS_PUBLIC_EEA = "wss://wseea.okx.com/ws/v5/public"  # documented form, no port (LP-DATA-DESK §0.1)
REST_GLOBAL = "https://www.okx.com"
REST_EEA = "https://eea.okx.com"
HOST_ALIASES = {"global": WS_PUBLIC_GLOBAL, "eea": WS_PUBLIC_EEA}
REST_ALIASES = {"global": REST_GLOBAL, "eea": REST_EEA}
INSTRUMENTS_PATH = "/api/v5/public/instruments?instType=FUTURES"

CHANNELS = ("trades", "books5", "liquidation-orders", "mark-price", "funding-rate")
CHANNEL_LAYOUT = {"trades": "trades", "books5": "books5", "liquidation-orders": "liquidations", "mark-price": "mark", "funding-rate": "funding"}
INSTTYPE_CHANNELS = frozenset({"liquidation-orders"})
# The forward test fwd-okx-r1 runs 2026-10-13..31: no value of that window may be captured or read by this programme.
QUARANTINE_FLOOR = "2026-11-02T00:00:00Z"
DEFAULT_NOT_BEFORE = QUARANTINE_FLOOR
DEFAULT_CHECKPOINT_S = {"values": 90.0, "counting": 600.0}
INST_TYPE = "FUTURES"

XPERP_RE = re.compile(r"^[A-Z0-9]+-USD_UM_XPERP-\d{6}$")
DATED_RE = re.compile(r"^[A-Z0-9]+-USD_UM-\d{6}$")
FAMILIES = ("xperp", "dated", "all")

_NAN = float("nan")


def _loads(raw):
    if _orjson is not None:
        return _orjson.loads(raw)
    return json.loads(raw)


def _f(value) -> float:
    """Tolerant float: '' / None / garbage → NaN."""
    if value is None or value == "":
        return _NAN
    try:
        return float(value)
    except (TypeError, ValueError):
        return _NAN


def _i(value, default: int = 0) -> int:
    if value is None or value == "":
        return default
    try:
        return int(value)
    except (TypeError, ValueError):
        return default


def frame_nbytes(raw) -> int:
    """Bytes on the wire: a text frame is counted in UTF-8 bytes, not in characters."""
    return len(raw.encode("utf-8")) if isinstance(raw, str) else len(raw)


def resolve_host(value: str) -> str:
    return HOST_ALIASES.get(value, value)


def resolve_rest(value: str) -> str:
    return REST_ALIASES.get(value, value)


def quarantine_floor_us() -> int:
    return tape.parse_iso_utc(QUARANTINE_FLOOR)


# ----------------------------------------------------------------------------- instruments


def family_of(inst_id: str) -> str:
    if XPERP_RE.match(inst_id):
        return "xperp"
    if DATED_RE.match(inst_id):
        return "dated"
    return "other"


def select_instruments(rows: list[dict], family: str = "xperp", only_live: bool = True) -> list[dict]:
    """Filter OKX instrument rows (`instId`, `state`, …) on the id family and the `live` state."""
    if family not in FAMILIES:
        raise ValueError(f"unknown family {family}")
    out = []
    for row in rows:
        inst_id = str(row.get("instId", ""))
        fam = family_of(inst_id)
        if family != "all" and fam != family:
            continue
        if family == "all" and fam == "other":
            continue
        if only_live and row.get("state") not in (None, "live"):
            continue
        out.append(row)
    return sorted(out, key=lambda r: r["instId"])


def load_instruments_file(path: Path) -> list[dict]:
    """A saved OKX response (`{"data": [...]}`), a list of rows, or a list of instrument ids."""
    document = json.loads(Path(path).read_text())
    rows = document.get("data", document.get("instruments", [])) if isinstance(document, dict) else document
    out = []
    for item in rows:
        if isinstance(item, str):
            out.append({"instId": item})
        elif isinstance(item, dict) and "instId" in item:
            out.append(item)
        else:
            raise ValueError(f"{path}: unreadable instrument entry {item!r}")
    return out


def instrument_spec_row(row: dict) -> dict:
    """The metadata kept with a capture (contract specs, no market value)."""
    return {"instId": row.get("instId"), "instFamily": row.get("instFamily"), "uly": row.get("uly"), "ruleType": row.get("ruleType"), "state": row.get("state"),
            "ctVal": row.get("ctVal"), "ctValCcy": row.get("ctValCcy"), "ctMult": row.get("ctMult"), "settleCcy": row.get("settleCcy"), "tickSz": row.get("tickSz"),
            "lotSz": row.get("lotSz"), "minSz": row.get("minSz"), "listTime": row.get("listTime"), "expTime": row.get("expTime"), "family": family_of(str(row.get("instId", "")))}


def fetch_instruments(rest_host: str, timeout: float = 20.0) -> list[dict]:
    """REST `GET /api/v5/public/instruments?instType=FUTURES` (metadata, no price). Never called by the tests."""
    url = resolve_rest(rest_host) + INSTRUMENTS_PATH
    request = urllib.request.Request(url, headers={"User-Agent": "tradingV3-research-lp/1.0"})
    with urllib.request.urlopen(request, timeout=timeout) as response:
        document = _loads(response.read())
    if str(document.get("code")) != "0":
        raise RuntimeError(f"OKX REST {url}: {document.get('code')} {document.get('msg')}")
    return list(document["data"])


# ----------------------------------------------------------------------------- frames


@dataclass
class Parsed:
    kind: str  # pong | event | data | unknown
    channel: str = ""
    inst_id: str = ""
    inst_type: str = ""
    data: list = field(default_factory=list)
    event: dict | None = None


def parse_frame(raw) -> Parsed:
    if raw == "pong" or raw == b"pong":
        return Parsed("pong")
    try:
        message = _loads(raw)
    except Exception:  # noqa: BLE001 - malformed frame: counted, never raised into the loop
        return Parsed("unknown")
    if not isinstance(message, dict):
        return Parsed("unknown")
    if "event" in message:
        return Parsed("event", channel=str((message.get("arg") or {}).get("channel", "")), event=message)
    arg = message.get("arg")
    data = message.get("data")
    if not isinstance(arg, dict) or not isinstance(data, list):
        return Parsed("unknown")
    return Parsed("data", channel=str(arg.get("channel", "")), inst_id=str(arg.get("instId", "")), inst_type=str(arg.get("instType", "")), data=data)


def _pad_levels(levels, n: int = tape.LEVELS) -> tuple[list[float], list[float]]:
    px = [_NAN] * n
    sz = [0.0] * n
    for k, level in enumerate(levels[:n]):
        if isinstance(level, (list, tuple)) and len(level) >= 2:
            px[k] = _f(level[0])
            sz[k] = _f(level[1])
            if math.isnan(sz[k]):
                sz[k] = 0.0
    return px, sz


def rows_from_data(channel: str, inst_id: str, data: list, recv_us: int) -> list[tuple[str, int, tuple]]:
    """Value rows `(inst_id, ts_us, row)` for the layout of `channel`. Only the value mode calls this."""
    out: list[tuple[str, int, tuple]] = []
    if channel == "trades":
        for row in data:
            ts_us = _i(row.get("ts")) * 1000
            inst = str(row.get("instId") or inst_id)
            out.append((inst, ts_us, (ts_us, recv_us, _f(row.get("px")), _f(row.get("sz")), 1 if row.get("side") == "buy" else 0, _i(row.get("tradeId"), 0), _i(row.get("count"), 1) or 1)))
    elif channel == "books5":
        for row in data:
            ts_us = _i(row.get("ts")) * 1000
            inst = str(row.get("instId") or inst_id)
            bid_px, bid_sz = _pad_levels(row.get("bids") or [])
            ask_px, ask_sz = _pad_levels(row.get("asks") or [])
            out.append((inst, ts_us, (ts_us, recv_us, _i(row.get("seqId"), -1), bid_px, bid_sz, ask_px, ask_sz)))
    elif channel == "liquidation-orders":
        for row in data:
            inst = str(row.get("instId") or inst_id)
            for detail in row.get("details") or []:
                ts_us = _i(detail.get("ts")) * 1000
                side = 1 if detail.get("side") == "buy" else -1 if detail.get("side") == "sell" else 0
                pos = {"long": 1, "short": -1, "net": 0}.get(str(detail.get("posSide", "")), 0)
                out.append((inst, ts_us, (ts_us, recv_us, side, pos, _f(detail.get("sz")), _f(detail.get("bkPx")), _f(detail.get("bkLoss")))))
    elif channel == "mark-price":
        for row in data:
            ts_us = _i(row.get("ts")) * 1000
            inst = str(row.get("instId") or inst_id)
            out.append((inst, ts_us, (ts_us, recv_us, _f(row.get("markPx")))))
    elif channel == "funding-rate":
        for row in data:
            ts_us = _i(row.get("ts")) * 1000
            inst = str(row.get("instId") or inst_id)
            sett = {"settled": 1, "processing": 0}.get(str(row.get("settState", "")), -1)
            method = {"next_period": 1, "current_period": 0}.get(str(row.get("method", "")), -1)
            out.append((inst, ts_us, (ts_us, recv_us, _i(row.get("fundingTime")) * 1000, _f(row.get("fundingRate")), _i(row.get("nextFundingTime")) * 1000, _f(row.get("nextFundingRate")),
                                      _f(row.get("premium")), _f(row.get("settFundingRate")), sett, method, _f(row.get("maxFundingRate")), _f(row.get("minFundingRate")), _f(row.get("interestRate")))))
    return out


def timestamps_from_data(channel: str, inst_id: str, data: list) -> list[tuple[str, int]]:
    """`(inst_id, ts_us)` per row — the only fields the counting mode reads (ids and clocks, no value)."""
    out: list[tuple[str, int]] = []
    for row in data:
        if not isinstance(row, dict):
            continue
        inst = str(row.get("instId") or inst_id)
        if channel == "liquidation-orders":
            details = row.get("details") or []
            for detail in details:
                out.append((inst, _i(detail.get("ts")) * 1000))
            if not details:
                out.append((inst, 0))
        else:
            out.append((inst, _i(row.get("ts")) * 1000))
    return out


def missing_seq_ids(channel: str, data: list) -> int:
    """How many `books5` rows come without a `seqId` (presence only — the value is never read in counting mode).
    Without it the de-duplication key of the tape falls back to (ts_us, best bid, best ask); the count tells how often."""
    if channel != "books5":
        return 0
    return sum(1 for row in data if isinstance(row, dict) and (row.get("seqId") is None or row.get("seqId") == ""))


def subscription_args(inst_ids: list[str], channels: tuple[str, ...]) -> list[dict]:
    args = []
    for channel in channels:
        if channel in INSTTYPE_CHANNELS:
            args.append({"channel": channel, "instType": INST_TYPE})
        else:
            args.extend({"channel": channel, "instId": inst_id} for inst_id in inst_ids)
    return args


# ----------------------------------------------------------------------------- counting


class Counter:
    """Coverage × frequency per (channel, instrument): messages, rows, bytes, first/last exchange
    clock, largest gap, rows per UTC hour, rows without `seqId`. Never sees a price, a size or a rate."""

    def __init__(self) -> None:
        self.cells: dict[str, dict] = {}
        self.unknown_frames = 0
        self.pongs = 0
        self.events: dict[str, int] = {}
        self.started_at = iso_now()

    def observe(self, channel: str, inst_id: str, nbytes: int, stamps: list[tuple[str, int]], missing_seq_id: int = 0) -> None:
        by_inst: dict[str, list[int]] = {}
        for inst, ts_us in stamps:
            by_inst.setdefault(inst or inst_id or "?", []).append(ts_us)
        if not by_inst:
            by_inst[inst_id or "?"] = []
        for inst, ts_list in by_inst.items():
            cell = self.cells.setdefault(f"{channel}|{inst}", {"channel": channel, "inst_id": inst, "messages": 0, "rows": 0, "bytes": 0, "first_ts_us": None, "last_ts_us": None, "max_gap_us": 0,
                                                             "rows_per_hour": [0] * 24, "missing_seq_id": 0})
            cell["messages"] += 1
            cell["bytes"] += int(nbytes)
            cell["rows"] += len(ts_list)
            cell["missing_seq_id"] += int(missing_seq_id)
            for ts_us in ts_list:
                if ts_us <= 0:
                    continue
                if cell["first_ts_us"] is None or ts_us < cell["first_ts_us"]:
                    cell["first_ts_us"] = ts_us
                if cell["last_ts_us"] is not None and ts_us > cell["last_ts_us"]:
                    cell["max_gap_us"] = max(cell["max_gap_us"], ts_us - cell["last_ts_us"])
                if cell["last_ts_us"] is None or ts_us > cell["last_ts_us"]:
                    cell["last_ts_us"] = ts_us
                cell["rows_per_hour"][int(ts_us // SECOND_US % tape.DAY_S) // 3600] += 1

    def observe_event(self, name: str) -> None:
        self.events[name] = self.events.get(name, 0) + 1

    def snapshot(self) -> dict:
        channels: dict[str, dict] = {}
        for cell in self.cells.values():
            agg = channels.setdefault(cell["channel"], {"instruments": 0, "messages": 0, "rows": 0, "bytes": 0})
            agg["instruments"] += 1
            agg["messages"] += cell["messages"]
            agg["rows"] += cell["rows"]
            agg["bytes"] += cell["bytes"]
        cells = []
        for cell in sorted(self.cells.values(), key=lambda c: (c["channel"], c["inst_id"])):
            span_s = (cell["last_ts_us"] - cell["first_ts_us"]) / SECOND_US if cell["first_ts_us"] is not None and cell["last_ts_us"] is not None else 0.0
            cells.append({**cell, "span_s": span_s, "rows_per_minute": (cell["rows"] / span_s * 60.0) if span_s > 0 else None,
                          "first_ts": tape.iso_utc(cell["first_ts_us"]) if cell["first_ts_us"] else None, "last_ts": tape.iso_utc(cell["last_ts_us"]) if cell["last_ts_us"] else None})
        return {"kind": "lp-xperp-counting", "started_at": self.started_at, "updated_at": iso_now(), "contains_values": False, "by_channel": channels, "cells": cells,
                "unknown_frames": self.unknown_frames, "pongs": self.pongs, "events": self.events}

    def save(self, path: Path) -> None:
        tape.atomic_write_text(path, json.dumps(self.snapshot(), indent=1, sort_keys=True) + "\n")


# ----------------------------------------------------------------------------- guards and journal


class NotBeforeViolation(RuntimeError):
    pass


class NotBeforeBelowFloor(NotBeforeViolation):
    """`--not-before` under `QUARANTINE_FLOOR`: refused in every mode, nothing written."""


class NotBeforeGuard:
    """Values may be captured only from `not_before_us` on; the counting mode stores no value and is exempt.
    The instant itself may not be set below the quarantine floor (test hook aside)."""

    def __init__(self, not_before_us: int, counting_only: bool) -> None:
        self.not_before_us = int(not_before_us)
        self.counting_only = bool(counting_only)
        self.dropped = 0

    def assert_above_floor(self, floor_override_for_tests: bool = False) -> None:
        floor_us = quarantine_floor_us()
        if self.not_before_us < floor_us and not floor_override_for_tests:
            raise NotBeforeBelowFloor(f"not_before_below_floor: not-before {tape.iso_utc(self.not_before_us)} is below the quarantine floor {QUARANTINE_FLOOR}")

    def assert_may_start(self, now_us: int | None = None) -> None:
        now_us = utc_now_us() if now_us is None else now_us
        if not self.counting_only and now_us < self.not_before_us:
            raise NotBeforeViolation(f"value capture refused before {tape.iso_utc(self.not_before_us)} (now {tape.iso_utc(now_us)}); use --counting-only for the P3 count")

    def admits(self, ts_us: int) -> bool:
        if self.counting_only or ts_us >= self.not_before_us:
            return True
        self.dropped += 1
        return False


class HealthJournal:
    """Append-only `health.log`: one line per event, `ts level=… event=… key=value…`, echoed to stderr."""

    def __init__(self, path: Path | None, echo: bool = True) -> None:
        self.path = path
        self.echo = echo
        self.lines = 0
        if path is not None:
            path.parent.mkdir(parents=True, exist_ok=True)

    def log(self, level: str, event: str, **fields) -> str:
        parts = [iso_now(), f"level={level}", f"event={event}"]
        for key, value in fields.items():
            text = str(value).replace("\n", " ")
            if " " in text or text == "":
                text = json.dumps(text)
            parts.append(f"{key}={text}")
        line = " ".join(parts)
        self.lines += 1
        if self.path is not None:
            with self.path.open("a") as handle:
                handle.write(line + "\n")
        if self.echo:
            print(line, file=sys.stderr, flush=True)
        return line


# ----------------------------------------------------------------------------- capture


@dataclass
class CaptureConfig:
    out_dir: Path
    inst_ids: tuple[str, ...]
    host: str = WS_PUBLIC_GLOBAL
    channels: tuple[str, ...] = CHANNELS
    counting_only: bool = False
    not_before: str = DEFAULT_NOT_BEFORE
    checkpoint_every_s: float | None = None  # None → DEFAULT_CHECKPOINT_S[mode]
    duration_s: float | None = None
    ping_every_s: float = 20.0
    recv_timeout_s: float = 30.0
    reconnect_max_s: float = 30.0
    reconnect_first_s: float = 1.0
    max_connection_failures: int | None = None
    stale_warn_s: float = 60.0
    finalize_grace_s: float = 90.0
    finalize_every_s: float = 60.0  # the finalization scan (a disk walk) runs at most this often
    subscribe_chunk: int = 50
    open_timeout_s: float = 20.0
    instruments_rows: list[dict] | None = None
    liq_selected_only: bool = False
    echo: bool = True
    argv: list[str] | None = None
    # Test hook only: lets a synthetic fixture use a not-before below the quarantine floor. The CLI never sets it.
    floor_override_for_tests: bool = False

    @property
    def mode(self) -> str:
        return "counting" if self.counting_only else "values"

    @property
    def checkpoint_s(self) -> float:
        return float(self.checkpoint_every_s) if self.checkpoint_every_s else DEFAULT_CHECKPOINT_S[self.mode]


class Capture:
    """The collector. `handle_raw` is synchronous and network-free (what the tests drive); `run`
    owns the WebSocket loop and the housekeeping (checkpoints, finalization, health, signals)."""

    def __init__(self, cfg: CaptureConfig) -> None:
        self.cfg = cfg
        self.out_dir = Path(cfg.out_dir)
        self.host = resolve_host(cfg.host)
        for channel in cfg.channels:
            if channel not in CHANNELS:
                raise ValueError(f"unknown channel {channel}")
        self.not_before_us = tape.parse_iso_utc(cfg.not_before)
        self.guard = NotBeforeGuard(self.not_before_us, cfg.counting_only)
        self.guard.assert_above_floor(cfg.floor_override_for_tests)  # before anything is written
        self.guard.assert_may_start()
        self.lock = tape.DirectoryLock(self.out_dir).acquire()
        try:
            self.health = HealthJournal(self.out_dir / "health.log", echo=cfg.echo)
            source = {"ws": self.host, "channels": list(cfg.channels), "inst_type": INST_TYPE}
            self.store = TapeStore(self.out_dir, cfg.mode, source=source, not_before=cfg.not_before)
        except Exception:
            self.lock.release()
            raise
        self.counter = Counter() if cfg.counting_only else None
        self.selected = frozenset(cfg.inst_ids)
        self.messages = 0
        self.rows_written = 0
        self.unknown = 0
        self.dropped_unselected = 0
        self.last_message_mono: float | None = None
        self.last_message_channel: dict[str, float] = {}
        self.connected = False
        self.connection_failures = 0
        self.stopping = False
        self.stop_reason = ""
        self.started_mono = time.monotonic()
        self.stale_reported = False
        self.closed = False
        self._stop_event = asyncio.Event()
        if cfg.instruments_rows:
            self.write_instruments(cfg.instruments_rows)
        self.store.manifest["sessions"].append({"started_at": iso_now(), "pid": os.getpid(), "mode": cfg.mode, "host": self.host, "channels": list(cfg.channels),
                                                "inst_count": len(cfg.inst_ids), "not_before": cfg.not_before, "quarantine_floor": QUARANTINE_FLOOR,
                                                "floor_override_for_tests": bool(cfg.floor_override_for_tests), "checkpoint_every_s": cfg.checkpoint_s,
                                                "liq_selected_only": bool(cfg.liq_selected_only), "argv": list(cfg.argv or [])})
        self.store.save_manifest()
        self.health.log("INFO", "started", mode=cfg.mode, host=self.host, instruments=len(cfg.inst_ids), channels=",".join(cfg.channels), not_before=cfg.not_before,
                        quarantine_floor=QUARANTINE_FLOOR, checkpoint_every_s=cfg.checkpoint_s)

    # -- metadata

    def write_instruments(self, rows: list[dict]) -> Path:
        """`instruments-futures.json`: contract specifications (no market value). A later session merges
        its rows and its selection into the existing file (`selected` = union), so a resume with another
        selection is visible to the readers."""
        path = self.out_dir / "instruments-futures.json"
        fresh = {"source": f"{INSTRUMENTS_PATH} (contract specifications, no market value)", "fetched_at": iso_now(), "selected": sorted(self.cfg.inst_ids),
                 "instruments": [instrument_spec_row(row) for row in rows]}
        if path.exists():
            try:
                existing = json.loads(path.read_text())
            except ValueError:
                existing = {}
            merged_rows = {str(r.get("instId")): r for r in existing.get("instruments", []) if isinstance(r, dict)}
            for row in fresh["instruments"]:
                merged_rows[str(row.get("instId"))] = row
            document = {**existing, "updated_at": iso_now(), "selected": sorted(set(existing.get("selected", [])) | set(self.cfg.inst_ids)),
                        "instruments": [merged_rows[k] for k in sorted(merged_rows)]}
            document.setdefault("fetched_at", fresh["fetched_at"])
            document.setdefault("source", fresh["source"])
        else:
            document = fresh
        tape.atomic_write_text(path, json.dumps(document, indent=1, sort_keys=True) + "\n")
        return path

    # -- messages

    def handle_raw(self, raw, recv_us: int | None = None) -> Parsed:
        recv_us = utc_now_us() if recv_us is None else recv_us
        nbytes = frame_nbytes(raw)
        parsed = parse_frame(raw)
        now = time.monotonic()
        if parsed.kind == "pong":
            if self.counter is not None:
                self.counter.pongs += 1
            self.last_message_mono = now
            return parsed
        if parsed.kind == "event":
            self._on_event(parsed.event or {})
            self.last_message_mono = now
            return parsed
        if parsed.kind != "data":
            self.unknown += 1
            if self.counter is not None:
                self.counter.unknown_frames += 1
            return parsed
        self.messages += 1
        self.last_message_mono = now
        self.last_message_channel[parsed.channel] = now
        if self.counter is not None:
            self.counter.observe(parsed.channel, parsed.inst_id, nbytes, timestamps_from_data(parsed.channel, parsed.inst_id, parsed.data), missing_seq_ids(parsed.channel, parsed.data))
            return parsed
        layout = CHANNEL_LAYOUT.get(parsed.channel)
        if layout is None:
            self.unknown += 1
            return parsed
        restrict = self.cfg.liq_selected_only and parsed.channel in INSTTYPE_CHANNELS
        for inst, ts_us, row in rows_from_data(parsed.channel, parsed.inst_id, parsed.data, recv_us):
            if ts_us <= 0 or not self.guard.admits(ts_us):
                continue
            if restrict and inst not in self.selected:
                self.dropped_unselected += 1
                continue
            self.store.append(layout, inst, ts_us, row)
            self.rows_written += 1
        return parsed

    def _on_event(self, message: dict) -> None:
        event = str(message.get("event"))
        if self.counter is not None:
            self.counter.observe_event(event)
        if event == "error":
            text = json.dumps(message, sort_keys=True)[:300]
            self.store.manifest["errors"].append({"at": iso_now(), "message": text})
            self.health.log("ERROR", "ws_error", detail=text)
        elif event == "subscribe":
            arg = message.get("arg") or {}
            self.health.log("DEBUG", "subscribed", channel=arg.get("channel", ""), inst=arg.get("instId", arg.get("instType", "")))
        else:
            self.health.log("DEBUG", "ws_event", kind=event)

    # -- lifecycle

    def _set_stopping(self, reason: str) -> None:
        if not self.stopping:
            self.stopping = True
            self.stop_reason = self.stop_reason or reason
        self._stop_event.set()

    def request_stop(self, reason: str = "signal") -> None:
        """Signal handler (SIGTERM / SIGINT) and programmatic stop: the WebSocket read is interrupted,
        the loops exit, `close()` flushes a last segment and journals `stopped reason=<reason>`."""
        first = not self.stopping
        self._set_stopping(reason)
        if first:
            self.health.log("INFO", "signal", reason=reason)

    def should_stop(self) -> bool:
        if self.stopping:
            return True
        if self.cfg.duration_s is not None and time.monotonic() - self.started_mono >= self.cfg.duration_s:
            self._set_stopping("duration")
        elif (self.out_dir / "STOP").exists():
            self._set_stopping("STOP file")
        return self.stopping

    def checkpoint(self) -> dict:
        summary = {"messages": self.messages, "rows_written": self.rows_written, "dropped_not_before": self.guard.dropped, "dropped_unselected": self.dropped_unselected,
                   "unknown_frames": self.unknown, "connected": self.connected}
        if self.counter is not None:
            self.counter.save(self.out_dir / "counting.json")
            summary["cells"] = len(self.counter.cells)
            self.store.save_manifest()
        else:
            summary["segments"] = len(self.store.checkpoint())
        self.health.log("INFO", "checkpoint", **summary)
        return summary

    def finalize_due(self, now_us: int | None = None) -> list[str]:
        if self.counter is not None:
            return []
        now_us = utc_now_us() if now_us is None else now_us
        today = tape.day_of_us(now_us)
        if now_us - today * tape.DAY_S * SECOND_US < self.cfg.finalize_grace_s * SECOND_US:
            return []
        done = self.store.finalize_days_before(today, now_us=now_us)
        if done:
            self.health.log("INFO", "finalized", count=len(done), first=done[0])
        return done

    def close(self) -> None:
        """Flush, journal `stopped`, save the manifest, release the lock. Idempotent."""
        if self.closed:
            return
        self.closed = True
        try:
            self.checkpoint()
        finally:
            self.health.log("INFO", "stopped", reason=self.stop_reason or "signal", messages=self.messages, rows_written=self.rows_written, dropped_not_before=self.guard.dropped,
                            dropped_unselected=self.dropped_unselected)
            self.store.save_manifest()
            self.lock.release()

    def _install_signal_handlers(self, loop: asyncio.AbstractEventLoop) -> list:
        installed = []
        for sig in (signal.SIGTERM, signal.SIGINT):
            try:
                loop.add_signal_handler(sig, self.request_stop, sig.name)
                installed.append(sig)
            except (NotImplementedError, RuntimeError, ValueError):  # not the main thread, or no signal support on this loop
                self.health.log("WARN", "signal_handler_unavailable", signal=sig.name)
        return installed

    async def run(self) -> None:
        loop = asyncio.get_running_loop()
        installed = self._install_signal_handlers(loop)
        tasks = [asyncio.create_task(self.ws_loop(), name="ws"), asyncio.create_task(self.housekeeping_loop(), name="housekeeping")]
        try:
            await asyncio.gather(*tasks)
        finally:
            for sig in installed:
                try:
                    loop.remove_signal_handler(sig)
                except (NotImplementedError, RuntimeError, ValueError):  # pragma: no cover
                    pass
            for task in tasks:
                task.cancel()
            self.close()

    async def housekeeping_loop(self) -> None:
        last_checkpoint = time.monotonic()
        last_finalize = -math.inf
        while not self.should_stop():
            try:
                await asyncio.wait_for(self._stop_event.wait(), timeout=0.5)
            except asyncio.TimeoutError:
                pass
            now = time.monotonic()
            if now - last_finalize >= self.cfg.finalize_every_s:
                self.finalize_due()
                last_finalize = now
            if now - last_checkpoint >= self.cfg.checkpoint_s:
                self.checkpoint()
                last_checkpoint = now
            if self.connected and self.last_message_mono is not None and now - self.last_message_mono > self.cfg.stale_warn_s:
                if not self.stale_reported:
                    self.health.log("WARN", "stale", seconds=round(now - self.last_message_mono, 1))
                    self.stale_reported = True
            else:
                self.stale_reported = False
        self._set_stopping("housekeeping")

    async def _recv_or_stop(self, ws, stop_waiter: asyncio.Future):
        """The next frame, or `None` when a stop was requested while waiting; `asyncio.TimeoutError` after `recv_timeout_s` of silence."""
        recv = asyncio.ensure_future(ws.recv())
        done, _ = await asyncio.wait({recv, stop_waiter}, timeout=self.cfg.recv_timeout_s, return_when=asyncio.FIRST_COMPLETED)
        if recv in done:
            return recv.result()
        recv.cancel()
        if stop_waiter in done:
            return None
        raise asyncio.TimeoutError(f"no frame for {self.cfg.recv_timeout_s} s")

    async def ws_loop(self) -> None:
        import websockets

        backoff = self.cfg.reconnect_first_s
        args = subscription_args(list(self.cfg.inst_ids), self.cfg.channels)
        while not self.should_stop():
            stop_waiter = asyncio.ensure_future(self._stop_event.wait())
            try:
                async with websockets.connect(self.host, ping_interval=None, max_size=1 << 24, open_timeout=self.cfg.open_timeout_s) as ws:
                    for start in range(0, len(args), self.cfg.subscribe_chunk):
                        await ws.send(json.dumps({"op": "subscribe", "args": args[start:start + self.cfg.subscribe_chunk]}))
                        await asyncio.sleep(0.2)
                    self.connected = True
                    self.connection_failures = 0
                    backoff = self.cfg.reconnect_first_s
                    self.health.log("INFO", "connected", host=self.host, subscriptions=len(args))
                    pinger = asyncio.create_task(self._pinger(ws))
                    try:
                        while not self.should_stop():
                            raw = await self._recv_or_stop(ws, stop_waiter)
                            if raw is None:
                                break
                            self.handle_raw(raw)
                    finally:
                        pinger.cancel()
            except asyncio.CancelledError:
                raise
            except Exception as error:  # noqa: BLE001 - recorded, reconnected
                detail = f"{type(error).__name__}: {str(error)[:160]}"
                if self.connected:
                    self.store.manifest["disconnects"].append({"at": iso_now(), "error": detail})
                    self.health.log("WARN", "disconnected", error=detail, retry_s=backoff)
                else:
                    self.connection_failures += 1
                    self.health.log("WARN", "connect_failed", error=detail, retry_s=backoff, failures=self.connection_failures)
                    if self.cfg.max_connection_failures is not None and self.connection_failures > self.cfg.max_connection_failures:
                        self._set_stopping("connection failures")
                        break
                self.connected = False
                if self.should_stop():
                    break
                try:
                    await asyncio.wait_for(self._stop_event.wait(), timeout=backoff)
                except asyncio.TimeoutError:
                    pass
                backoff = min(backoff * 2.0, self.cfg.reconnect_max_s)
            finally:
                stop_waiter.cancel()
        self.connected = False
        self._set_stopping("ws loop")

    async def _pinger(self, ws) -> None:
        while True:
            await asyncio.sleep(self.cfg.ping_every_s)
            await ws.send("ping")


def run_capture(cfg: CaptureConfig) -> int:
    """Build and run a capture; exit codes: 0 stopped normally, 2 not-before refused (below the
    quarantine floor, or a value capture before the instant), 3 lock held, 4 invalid directory / channel."""
    try:
        capture = Capture(cfg)
    except NotBeforeBelowFloor as error:
        print(f"{iso_now()} level=ERROR event=refused reason=not_before_below_floor detail={json.dumps(str(error))}", file=sys.stderr, flush=True)
        return 2
    except NotBeforeViolation as error:
        print(f"{iso_now()} level=ERROR event=refused reason=not_before detail={json.dumps(str(error))}", file=sys.stderr, flush=True)
        return 2
    except tape.LockHeld as error:
        print(f"{iso_now()} level=ERROR event=lock_held detail={json.dumps(str(error))}", file=sys.stderr, flush=True)
        return 3
    except ValueError as error:  # a directory holding a tape of the other mode, an unknown channel…
        print(f"{iso_now()} level=ERROR event=invalid detail={json.dumps(str(error))}", file=sys.stderr, flush=True)
        return 4
    try:
        asyncio.run(capture.run())
    except KeyboardInterrupt:  # only reachable when the loop could not install its SIGINT handler
        capture.stop_reason = capture.stop_reason or "interrupted"
    finally:
        capture.close()  # idempotent: `run()` normally closed already
    return 0
