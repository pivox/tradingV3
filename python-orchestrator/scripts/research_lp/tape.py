"""Tape layouts, in-memory buffers and the resume-safe day store of the `lp` collector.

Layouts (one npz per instrument-day, `numpy.savez_compressed`, one array per field, the
convention of `scripts/research_exec/forward_okx.py`), under `<out>/<layout>/<inst>/`:

- `trades`        `ts_us`, `recv_us`, `price`, `size` (contracts, the venue unit), `taker_buy`,
                  `trade_id`, `count` (fills aggregated in the print; 1 when absent);
- `books5`        `ts_us`, `recv_us`, `seq_id`, `bid_px`, `bid_sz`, `ask_px`, `ask_sz` (n × 5, best
                  first, sizes in contracts, NaN / 0 for a missing level);
- `liquidations`  `ts_us`, `recv_us`, `side` (+1 buy, −1 sell: the liquidation order), `pos_side`
                  (+1 long, −1 short, 0 net), `size` (contracts), `bk_px` (bankruptcy price, NaN when
                  empty), `bk_loss`;
- `mark`          `ts_us`, `recv_us`, `mark_px`;
- `funding`       `ts_us`, `recv_us`, `funding_time_us`, `funding_rate`, `next_funding_time_us`,
                  `next_funding_rate`, `premium`, `sett_funding_rate`, `sett_state` (1 settled,
                  0 processing, −1 unknown), `method` (1 next_period, 0 current_period, −1 unknown),
                  `max_rate`, `min_rate`, `interest_rate`;
- `candles_1m`, `mark_1m`, `index_1m`, `funding_hist`
                  the REST 1-minute history layouts of `g5_fetch` (`HISTORY_LAYOUTS`; one row per
                  candle opening time or per settlement, `fetched_us` instead of `recv_us`), stored
                  by the same segment store under `<out>/<layout>/<inst>/` — the index layout is keyed
                  by the index id (`BTC-USD`), shared by the contracts of one underlying.

Sizes stay in **contracts** (OKX `sz`); the instruments file gives `ctVal` and the loaders of
`lp_sim` convert when a notional is needed. Every `*_us` field is microseconds UTC; `ts_us` is
the exchange timestamp, `recv_us` the local receipt.

**Resume safety.** The collector never rewrites a file it did not create in the current
checkpoint: each checkpoint appends a new *segment* `<inst>-<date>.segNNNN.npz` holding only
the rows received since the previous checkpoint; a day is *finalized* (segments merged, sorted,
de-duplicated into `<inst>-<date>.npz`, sha256 in the manifest, segments deleted) once the UTC
day is over. A process that restarts during a day finds the earlier segments on disk and simply
opens the next segment index, so nothing recorded before the restart can be overwritten — the
defect of the fwd-okx capture before 2026-10-13 (`docs/handbook/runbooks/research-forward-okx.md`:
"before that fix a restart overwrote them at its next checkpoint") is impossible by
construction. A single lock file refuses a second concurrent collector on the same directory.

**What can still be lost.** "Nothing overwritten" holds for everything on disk; "nothing lost"
holds only for rows already in a segment. The rows received since the last checkpoint live in
RAM: a clean stop (`SIGTERM`, `SIGINT`, `STOP` file, `--duration-s`) flushes them in `close()`,
but a `SIGKILL`, an OOM kill or a power cut loses at most `checkpoint_every_s` of rows (90 s by
default in value mode — at ~10 `books5` snapshots per second that is ~900 rows per instrument).
Segment and manifest writes are `tmp → fsync → rename` (+ directory fsync) so a crash leaves
either the previous file or the complete new one, never a truncated file under the final name.

**Duplicates and late rows.** Keys in `DEDUPE_KEYS`; for `books5` the key is `(ts_us, seq_id,
best bid, best ask)`: when the venue sends no `seqId` (stored as −1) two distinct snapshots in
the same millisecond are still told apart by their top of book. A row of an already finalized
day (a reconnect replaying the liquidation channel after midnight, a late print) opens a new
segment; the day is *re-finalized* (final file + late segments merged, `refinalized: true`) at
most once per `refinalize_after_s` (1 h by default) so a trickle of late rows cannot trigger a
re-read + re-hash of a ~10⁸-byte `books5` file at every arrival. Finalization itself runs on a
timer in the collector (`finalize_every_s`, 60 s), not on every housekeeping tick.
"""

from __future__ import annotations

import hashlib
import json
import os
import re
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path

import numpy as np

DAY_S = 86_400
SECOND_US = 1_000_000
MANIFEST_NAME = "manifest-lp.json"
LOCK_NAME = ".lp-capture.lock"
LEVELS = 5

TRADES_DTYPE = np.dtype(
    [("ts_us", "<i8"), ("recv_us", "<i8"), ("price", "<f8"), ("size", "<f8"), ("taker_buy", "<i1"), ("trade_id", "<i8"), ("count", "<i4")]
)
BOOKS5_DTYPE = np.dtype(
    [("ts_us", "<i8"), ("recv_us", "<i8"), ("seq_id", "<i8"), ("bid_px", "<f8", (LEVELS,)), ("bid_sz", "<f8", (LEVELS,)), ("ask_px", "<f8", (LEVELS,)), ("ask_sz", "<f8", (LEVELS,))]
)
LIQUIDATIONS_DTYPE = np.dtype(
    [("ts_us", "<i8"), ("recv_us", "<i8"), ("side", "<i1"), ("pos_side", "<i1"), ("size", "<f8"), ("bk_px", "<f8"), ("bk_loss", "<f8")]
)
MARK_DTYPE = np.dtype([("ts_us", "<i8"), ("recv_us", "<i8"), ("mark_px", "<f8")])
FUNDING_DTYPE = np.dtype(
    [("ts_us", "<i8"), ("recv_us", "<i8"), ("funding_time_us", "<i8"), ("funding_rate", "<f8"), ("next_funding_time_us", "<i8"), ("next_funding_rate", "<f8"),
     ("premium", "<f8"), ("sett_funding_rate", "<f8"), ("sett_state", "<i1"), ("method", "<i1"), ("max_rate", "<f8"), ("min_rate", "<f8"), ("interest_rate", "<f8")]
)

# REST 1-minute history of the X-Perps (G5 Gate 1, `g5_fetch`; `okx-xperp-1m-history-2026-03-30..2026-10-13`): `ts_us` is the
# candle **opening** time (OKX `ts`), `fetched_us` the local instant of the request; `confirm` 1 = completed candle. `funding_hist`
# rows: `ts_us` = `fundingTime` (the settlement instant), `funding_rate` = predicted, `realized_rate` = actual, `method` (1 next_period,
# 0 current_period, −1 unknown), `formula_type` (1 withRate, 0 noRate, −1 unknown).
CANDLES_1M_DTYPE = np.dtype(
    [("ts_us", "<i8"), ("fetched_us", "<i8"), ("open", "<f8"), ("high", "<f8"), ("low", "<f8"), ("close", "<f8"), ("vol", "<f8"), ("vol_ccy", "<f8"), ("vol_ccy_quote", "<f8"), ("confirm", "<i1")]
)
PX_CANDLES_1M_DTYPE = np.dtype([("ts_us", "<i8"), ("fetched_us", "<i8"), ("open", "<f8"), ("high", "<f8"), ("low", "<f8"), ("close", "<f8"), ("confirm", "<i1")])
FUNDING_HIST_DTYPE = np.dtype([("ts_us", "<i8"), ("fetched_us", "<i8"), ("funding_rate", "<f8"), ("realized_rate", "<f8"), ("method", "<i1"), ("formula_type", "<i1")])

LAYOUTS: dict[str, np.dtype] = {"trades": TRADES_DTYPE, "books5": BOOKS5_DTYPE, "liquidations": LIQUIDATIONS_DTYPE, "mark": MARK_DTYPE, "funding": FUNDING_DTYPE,
                                "candles_1m": CANDLES_1M_DTYPE, "mark_1m": PX_CANDLES_1M_DTYPE, "index_1m": PX_CANDLES_1M_DTYPE, "funding_hist": FUNDING_HIST_DTYPE}
HISTORY_LAYOUTS = ("candles_1m", "mark_1m", "index_1m", "funding_hist")
# Fields identifying one record (duplicates come from reconnects and from the liquidation channel's repeated first push).
# A key item is a field name, or `(field, column)` for one column of a vector field (`("bid_px", 0)` = best bid).
KeyItem = str | tuple[str, int]
DEDUPE_KEYS: dict[str, tuple[KeyItem, ...]] = {
    "trades": ("trade_id", "ts_us", "price", "size", "taker_buy"),
    "books5": ("ts_us", "seq_id", ("bid_px", 0), ("ask_px", 0)),  # seq_id = -1 when the venue sent none: the top of book then tells snapshots apart
    "liquidations": ("ts_us", "side", "pos_side", "size", "bk_px"),
    "mark": ("ts_us", "mark_px"),
    "funding": ("ts_us", "funding_time_us"),
    # One candle (or one settlement) per instant: a page fetched twice across a resume is the same row.
    "candles_1m": ("ts_us",),
    "mark_1m": ("ts_us",),
    "index_1m": ("ts_us",),
    "funding_hist": ("ts_us",),
}
DEFAULT_REFINALIZE_AFTER_S = 3600.0
_SEGMENT_RE = re.compile(r"^(?P<inst>.+)-(?P<date>\d{4}-\d{2}-\d{2})\.seg(?P<index>\d{4,})\.npz$")
_INST_RE = re.compile(r"^[A-Za-z0-9_\-.]+$")


def utc_now_us() -> int:
    return int(datetime.now(timezone.utc).timestamp() * SECOND_US)


def iso_utc(ts_us: int) -> str:
    return datetime.fromtimestamp(ts_us / SECOND_US, tz=timezone.utc).strftime("%Y-%m-%dT%H:%M:%S.%fZ")


def iso_now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def parse_iso_utc(value: str) -> int:
    """`2026-11-02T00:00:00Z` (or with an offset) to microseconds since the epoch; naive values are refused."""
    text = value.strip()
    if text.endswith("Z"):
        text = text[:-1] + "+00:00"
    moment = datetime.fromisoformat(text)
    if moment.tzinfo is None:
        raise ValueError(f"timestamp without timezone: {value}")
    return int(round((moment - datetime(1970, 1, 1, tzinfo=timezone.utc)).total_seconds() * SECOND_US))


def day_of_us(ts_us: int) -> int:
    return int(ts_us // SECOND_US // DAY_S)


def date_of_day(day: int) -> str:
    return datetime.fromtimestamp(day * DAY_S, tz=timezone.utc).strftime("%Y-%m-%d")


def day_of_date(date: str) -> int:
    return int(datetime.strptime(date, "%Y-%m-%d").replace(tzinfo=timezone.utc).timestamp() // DAY_S)


def parse_dates(spec: str) -> list[str]:
    """`2026-11-03..2026-11-05,2026-11-09` → every calendar day of each range, in order."""
    out: list[str] = []
    for item in spec.split(","):
        item = item.strip()
        if not item:
            continue
        first, last = item.split("..") if ".." in item else (item, item)
        day, end = day_of_date(first), day_of_date(last)
        while day <= end:
            out.append(date_of_day(day))
            day += 1
    return out


def sha256_of(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""):
            digest.update(chunk)
    return digest.hexdigest()


def check_inst_name(inst: str) -> str:
    if not _INST_RE.match(inst) or inst in (".", "..") or "/" in inst:
        raise ValueError(f"instrument id not usable as a file name: {inst!r}")
    return inst


# ----------------------------------------------------------------------------- structured rows


def empty_rows(layout: str) -> np.ndarray:
    return np.zeros(0, dtype=LAYOUTS[layout])


def rows_to_arrays(rows: np.ndarray) -> dict[str, np.ndarray]:
    return {name: np.ascontiguousarray(rows[name]) for name in rows.dtype.names}


def arrays_to_rows(layout: str, data) -> np.ndarray:
    dtype = LAYOUTS[layout]
    n = int(np.asarray(data["ts_us"]).shape[0])
    rows = np.zeros(n, dtype=dtype)
    for name in dtype.names:
        if name in data:
            rows[name] = np.asarray(data[name])
        elif name == "count":
            rows[name] = 1
    return rows


def _key_column(rows: np.ndarray, item: KeyItem) -> np.ndarray:
    if isinstance(item, tuple):
        name, column = item
        return rows[name][:, column]
    return rows[item]


def dedupe_and_sort(layout: str, rows: np.ndarray) -> tuple[np.ndarray, int]:
    """Rows sorted by `ts_us` (stable on the dedupe key), duplicates on `DEDUPE_KEYS[layout]`
    removed (first occurrence kept). Returns the rows and the number of duplicates dropped."""
    if rows.size == 0:
        return rows, 0
    keys = DEDUPE_KEYS[layout]
    order = np.lexsort([_key_column(rows, item) for item in reversed(keys)])
    sorted_rows = rows[order]
    dup = np.ones(sorted_rows.size, dtype=bool)
    dup[0] = False
    for item in keys:
        values = _key_column(sorted_rows, item)
        same = values[1:] == values[:-1]
        if values.dtype.kind == "f":  # NaN == NaN for the purpose of a duplicate (an empty bankruptcy price twice, a missing level)
            same |= np.isnan(values[1:]) & np.isnan(values[:-1])
        dup[1:] &= same
    kept = sorted_rows[~dup]
    final = np.lexsort([_key_column(kept, item) for item in reversed(("ts_us",) + tuple(k for k in keys if k != "ts_us"))])
    return kept[final], int(dup.sum())


def _fsync_directory(path: Path) -> None:
    """Make the rename durable; silently skipped where directories cannot be fsync'ed."""
    try:
        fd = os.open(str(path), os.O_RDONLY)
    except OSError:
        return
    try:
        os.fsync(fd)
    except OSError:
        pass
    finally:
        os.close(fd)


def atomic_write_text(path: Path, text: str) -> None:
    """`tmp → flush → fsync → rename → directory fsync`: the reader sees the old file or the whole new one."""
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_name(path.name + ".tmp")
    with tmp.open("w") as handle:
        handle.write(text)
        handle.flush()
        os.fsync(handle.fileno())
    tmp.replace(path)
    _fsync_directory(path.parent)


def save_rows(path: Path, rows: np.ndarray) -> None:
    """Durable atomic write (tmp + fsync + replace) of one layout file; a file object so savez keeps the exact name."""
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_name(path.name + ".tmp")
    with tmp.open("wb") as handle:
        np.savez_compressed(handle, **rows_to_arrays(rows))
        handle.flush()
        os.fsync(handle.fileno())
    tmp.replace(path)
    _fsync_directory(path.parent)


def load_rows(layout: str, path: Path) -> np.ndarray:
    with np.load(path) as data:
        return arrays_to_rows(layout, {name: data[name] for name in data.files})


# ----------------------------------------------------------------------------- buffers


class RowBuffer:
    """Append-only buffer of one (layout, inst, day) between two checkpoints."""

    __slots__ = ("layout", "rows")

    def __init__(self, layout: str) -> None:
        self.layout = layout
        self.rows: list[tuple] = []

    def append(self, row: tuple) -> None:
        self.rows.append(row)

    def __len__(self) -> int:
        return len(self.rows)

    def drain(self) -> np.ndarray:
        rows = np.array(self.rows, dtype=LAYOUTS[self.layout]) if self.rows else empty_rows(self.layout)
        self.rows = []
        return rows


# ----------------------------------------------------------------------------- lock


class LockHeld(RuntimeError):
    """Another collector already works on this directory."""


class DirectoryLock:
    """`flock` on `<out>/.lp-capture.lock`; held for the life of the process."""

    def __init__(self, out_dir: Path) -> None:
        self.path = out_dir / LOCK_NAME
        self._handle = None

    def acquire(self) -> "DirectoryLock":
        import fcntl

        self.path.parent.mkdir(parents=True, exist_ok=True)
        handle = self.path.open("a+")
        try:
            fcntl.flock(handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError as error:
            handle.close()
            raise LockHeld(f"{self.path}: another capture process holds the lock ({error})") from None
        handle.seek(0)
        handle.truncate()
        handle.write(f"{os.getpid()} {iso_now()}\n")
        handle.flush()
        self._handle = handle
        return self

    def release(self) -> None:
        if self._handle is not None:
            import fcntl

            fcntl.flock(self._handle.fileno(), fcntl.LOCK_UN)
            self._handle.close()
            self._handle = None


# ----------------------------------------------------------------------------- store


@dataclass
class SegmentInfo:
    path: Path
    index: int
    rows: int = 0


@dataclass
class DayKey:
    layout: str
    inst: str
    day: int

    @property
    def date(self) -> str:
        return date_of_day(self.day)

    @property
    def manifest_key(self) -> str:
        return f"{self.inst}:{self.date}:{self.layout}"


@dataclass
class _Open:
    buffer: RowBuffer
    next_index: int
    segments_written: list[int] = field(default_factory=list)
    resumed_segments: int = 0  # segments found on disk at open (an earlier process)


class TapeStore:
    """Per (layout, inst, UTC day) buffers, segment files and the manifest under `out_dir`."""

    def __init__(self, out_dir: Path, mode: str, source: dict | None = None, not_before: str | None = None, refinalize_after_s: float = DEFAULT_REFINALIZE_AFTER_S,
                 kind: str = "lp-xperp") -> None:
        if mode not in ("values", "counting"):
            raise ValueError(f"unknown store mode {mode}")
        self.out_dir = Path(out_dir)
        self.mode = mode
        self.kind = kind
        self.refinalize_after_s = float(refinalize_after_s)
        self.out_dir.mkdir(parents=True, exist_ok=True)
        self.manifest_path = self.out_dir / MANIFEST_NAME
        fresh = not self.manifest_path.exists()
        self.manifest = self._load_manifest(source or {}, not_before)
        self.open: dict[tuple[str, str, int], _Open] = {}
        if fresh:
            self.save_manifest()  # the directory is marked with its mode from the start

    # -- manifest

    def _load_manifest(self, source: dict, not_before: str | None) -> dict:
        if self.manifest_path.exists():
            manifest = json.loads(self.manifest_path.read_text())
            if manifest.get("mode") != self.mode:
                raise ValueError(f"{self.manifest_path}: directory holds a {manifest.get('mode')!r} tape, refusing to open it in {self.mode!r} mode")
            if manifest.get("kind", self.kind) != self.kind:
                raise ValueError(f"{self.manifest_path}: directory holds a {manifest.get('kind')!r} tape, refusing to open it as {self.kind!r}")
            return manifest
        return {"kind": self.kind, "version": 1, "mode": self.mode, "not_before": not_before, "source": source, "created_at": iso_now(),
                "files": {}, "pending": {}, "sessions": [], "disconnects": [], "errors": []}

    def save_manifest(self) -> None:
        self.manifest["updated_at"] = iso_now()
        atomic_write_text(self.manifest_path, json.dumps(self.manifest, indent=1, sort_keys=True) + "\n")

    # -- paths

    def layout_dir(self, layout: str, inst: str) -> Path:
        return self.out_dir / layout / check_inst_name(inst)

    def final_path(self, key: DayKey) -> Path:
        return self.layout_dir(key.layout, key.inst) / f"{key.inst}-{key.date}.npz"

    def segment_path(self, key: DayKey, index: int) -> Path:
        return self.layout_dir(key.layout, key.inst) / f"{key.inst}-{key.date}.seg{index:04d}.npz"

    def list_segments(self, key: DayKey) -> list[SegmentInfo]:
        folder = self.layout_dir(key.layout, key.inst)
        if not folder.exists():
            return []
        found = []
        for path in folder.iterdir():
            match = _SEGMENT_RE.match(path.name)
            if match and match.group("inst") == key.inst and match.group("date") == key.date:
                found.append(SegmentInfo(path, int(match.group("index"))))
        return sorted(found, key=lambda item: item.index)

    # -- writes

    def append(self, layout: str, inst: str, ts_us: int, row: tuple) -> None:
        if self.mode != "values":
            raise RuntimeError("a counting store never receives value rows")
        if layout not in LAYOUTS:
            raise ValueError(f"unknown layout {layout}")
        key = (layout, inst, day_of_us(ts_us))
        state = self.open.get(key)
        if state is None:
            check_inst_name(inst)
            existing = self.list_segments(DayKey(*key))
            state = self.open[key] = _Open(RowBuffer(layout), (existing[-1].index + 1) if existing else 0, [], len(existing))
        state.buffer.append(row)

    def checkpoint(self) -> list[str]:
        """Flush every non-empty buffer into a new segment file; returns the manifest keys touched."""
        touched = []
        for (layout, inst, day), state in list(self.open.items()):
            if len(state.buffer) == 0:
                continue
            key = DayKey(layout, inst, day)
            rows = state.buffer.drain()
            save_rows(self.segment_path(key, state.next_index), rows)
            state.segments_written.append(state.next_index)
            state.next_index += 1
            pending = self.manifest["pending"].setdefault(key.manifest_key, {"segments": 0, "rows": 0, "first_ts_us": None, "last_ts_us": None, "resumed_segments": state.resumed_segments})
            pending["segments"] = len(self.list_segments(key))
            pending["resumed_segments"] = max(int(pending.get("resumed_segments", 0)), state.resumed_segments)  # disclosed: segments left by an earlier process
            pending["rows"] = int(pending["rows"]) + int(rows.size)
            first, last = int(rows["ts_us"].min()), int(rows["ts_us"].max())
            pending["first_ts_us"] = first if pending["first_ts_us"] is None else min(int(pending["first_ts_us"]), first)
            pending["last_ts_us"] = last if pending["last_ts_us"] is None else max(int(pending["last_ts_us"]), last)
            pending["updated_at"] = iso_now()
            touched.append(key.manifest_key)
        self.save_manifest()
        return touched

    def pending_keys(self) -> list[DayKey]:
        """Every (layout, inst, day) with segments on disk — including days left by an earlier process."""
        keys: dict[tuple[str, str, int], DayKey] = {}
        for layout in LAYOUTS:
            folder = self.out_dir / layout
            if not folder.exists():
                continue
            for inst_dir in folder.iterdir():
                if not inst_dir.is_dir():
                    continue
                for path in inst_dir.iterdir():
                    match = _SEGMENT_RE.match(path.name)
                    if match and match.group("inst") == inst_dir.name:
                        day = day_of_date(match.group("date"))
                        keys.setdefault((layout, inst_dir.name, day), DayKey(layout, inst_dir.name, day))
        return sorted(keys.values(), key=lambda k: (k.day, k.layout, k.inst))

    def finalize(self, key: DayKey) -> dict:
        """Merge the segments of one instrument-day into the final file (sorted, de-duplicated,
        hashed), merge an older final file if the day was already finalized once, delete the
        segments and record the entry in the manifest."""
        state = self.open.pop((key.layout, key.inst, key.day), None)
        if state is not None and len(state.buffer):
            save_rows(self.segment_path(key, state.next_index), state.buffer.drain())
        segments = self.list_segments(key)
        parts = [load_rows(key.layout, segment.path) for segment in segments]
        final_path = self.final_path(key)
        refinalized = final_path.exists()
        if refinalized:
            parts.insert(0, load_rows(key.layout, final_path))
        if not parts:
            return {}
        merged, duplicates = dedupe_and_sort(key.layout, np.concatenate(parts))
        save_rows(final_path, merged)
        for segment in segments:
            segment.path.unlink()
        previous = self.manifest["files"].get(key.manifest_key) or {}
        entry = {"inst": key.inst, "date": key.date, "layout": key.layout, "rows": int(merged.size), "duplicates_dropped": duplicates,
                 "first_ts_us": int(merged["ts_us"][0]) if merged.size else None, "last_ts_us": int(merged["ts_us"][-1]) if merged.size else None,
                 "segments_merged": len(segments), "refinalized": refinalized, "refinalizations": int(previous.get("refinalizations", 0)) + (1 if refinalized else 0),
                 "npz_sha256": sha256_of(final_path), "npz_bytes": final_path.stat().st_size, "finalized_at": iso_now(), "finalized_at_us": utc_now_us(), "partial": False}
        pending = self.manifest["pending"].pop(key.manifest_key, None)
        if pending is not None:
            entry["resumed_segments"] = pending.get("resumed_segments", 0)
        if merged.size > 1:
            gaps = np.diff(merged["ts_us"])
            entry["gap_s_max"] = float(gaps.max() / SECOND_US)
        self.manifest["files"][key.manifest_key] = entry
        return entry

    def refinalize_due(self, key: DayKey, now_us: int | None = None) -> bool:
        """A day already finalized once is merged with its late segments at most every `refinalize_after_s`."""
        entry = self.manifest["files"].get(key.manifest_key)
        if entry is None or not self.final_path(key).exists():
            return True
        last = entry.get("finalized_at_us")
        if last is None:
            return True
        now_us = utc_now_us() if now_us is None else int(now_us)
        return now_us - int(last) >= self.refinalize_after_s * SECOND_US

    def finalize_days_before(self, day: int, force: bool = False, now_us: int | None = None) -> list[str]:
        """Finalize every pending (layout, inst, day) strictly before `day`; a day finalized earlier is
        re-finalized only when `refinalize_due` (or `force`). Returns the manifest keys finalized."""
        done = []
        candidates = {(k.layout, k.inst, k.day): k for k in self.pending_keys()}
        for (layout, inst, open_day), state in self.open.items():
            if len(state.buffer):
                candidates.setdefault((layout, inst, open_day), DayKey(layout, inst, open_day))
        for key in sorted(candidates.values(), key=lambda k: (k.day, k.layout, k.inst)):
            if key.day >= day:
                continue
            if not force and not self.refinalize_due(key, now_us):
                continue
            if self.finalize(key):
                done.append(key.manifest_key)
        if done:
            self.save_manifest()
        return sorted(set(done))

    def close(self) -> list[str]:
        """Flush buffers (segments only, nothing finalized): what a stopping process must do."""
        return self.checkpoint()


# ----------------------------------------------------------------------------- read side


def load_day(out_dir: Path, layout: str, inst: str, date: str, allow_pending: bool = True) -> tuple[np.ndarray, dict]:
    """The rows of one instrument-day: the final file when it exists, else the merge of the
    pending segments (read-only; nothing is written). The receipt says which."""
    out_dir = Path(out_dir)
    folder = out_dir / layout / check_inst_name(inst)
    final = folder / f"{inst}-{date}.npz"
    if final.exists():
        rows = load_rows(layout, final)
        return rows, {"source": "final", "path": str(final), "rows": int(rows.size), "sha256": sha256_of(final)}
    if not allow_pending or not folder.exists():
        return empty_rows(layout), {"source": "missing", "path": str(final), "rows": 0}
    parts = []
    for path in sorted(folder.iterdir()):
        match = _SEGMENT_RE.match(path.name)
        if match and match.group("inst") == inst and match.group("date") == date:
            parts.append(load_rows(layout, path))
    if not parts:
        return empty_rows(layout), {"source": "missing", "path": str(final), "rows": 0}
    rows, duplicates = dedupe_and_sort(layout, np.concatenate(parts))
    return rows, {"source": "segments", "path": str(folder), "segments": len(parts), "rows": int(rows.size), "duplicates_dropped": duplicates, "partial": True}


def load_days(out_dir: Path, layout: str, inst: str, dates: list[str], allow_pending: bool = True) -> tuple[np.ndarray, list[dict]]:
    parts, receipts = [], []
    for date in dates:
        rows, receipt = load_day(out_dir, layout, inst, date, allow_pending)
        receipt["date"] = date
        receipts.append(receipt)
        if rows.size:
            parts.append(rows)
    if not parts:
        return empty_rows(layout), receipts
    rows, _ = dedupe_and_sort(layout, np.concatenate(parts))
    return rows, receipts


def instruments_in(out_dir: Path, layout: str = "trades") -> list[str]:
    folder = Path(out_dir) / layout
    if not folder.exists():
        return []
    return sorted(path.name for path in folder.iterdir() if path.is_dir())


def read_manifest(out_dir: Path) -> dict | None:
    path = Path(out_dir) / MANIFEST_NAME
    return json.loads(path.read_text()) if path.exists() else None


def verify(out_dir: Path) -> dict:
    """Re-hash every finalized file against the manifest; list pending segments and partial days."""
    manifest = read_manifest(out_dir)
    if manifest is None:
        return {"ok": False, "error": f"no {MANIFEST_NAME} under {out_dir}"}
    mismatches, missing, checked = [], [], 0
    for key, entry in manifest.get("files", {}).items():
        path = Path(out_dir) / entry["layout"] / entry["inst"] / f"{entry['inst']}-{entry['date']}.npz"
        if not path.exists():
            missing.append(key)
            continue
        checked += 1
        if sha256_of(path) != entry["npz_sha256"]:
            mismatches.append(key)
    store_pending = {}
    for layout in LAYOUTS:
        folder = Path(out_dir) / layout
        if not folder.exists():
            continue
        for inst_dir in folder.iterdir():
            if inst_dir.is_dir():
                for path in inst_dir.iterdir():
                    match = _SEGMENT_RE.match(path.name)
                    if match:
                        label = f"{inst_dir.name}:{match.group('date')}:{layout}"
                        store_pending[label] = store_pending.get(label, 0) + 1
    return {"ok": not mismatches and not missing, "mode": manifest.get("mode"), "not_before": manifest.get("not_before"), "files_checked": checked,
            "sha256_mismatches": mismatches, "missing_files": missing, "pending_segments": store_pending, "disconnects": len(manifest.get("disconnects", [])),
            "errors": len(manifest.get("errors", [])), "sessions": len(manifest.get("sessions", []))}
