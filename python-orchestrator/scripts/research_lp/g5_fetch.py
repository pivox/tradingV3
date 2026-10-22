"""REST fetcher of the OKX X-Perps 1-minute history for the G5 Gate 1 historical read
(`okx-xperp-1m-history-2026-03-30..2026-10-13`, status `unread` in `LP-PROGRAMME-GATES.md` §6.2).

    python -m scripts.research_lp.cli g5-fetch --out DIR --instruments-file F --inst-ids A,B --dry-run --plan-out plan.json
    python -m scripts.research_lp.cli g5-fetch --out DIR --instruments-file F --inst-ids A,B --plan plan.json --max-requests N \\
        --decision-ref "<owner's written confirmation of the scale, P-G5-1>"

Streams (one per `tape` layout, `tape.HISTORY_LAYOUTS`), all public, unauthenticated, read-only
(`LP-DATA-DESK-2026-10-20.md` §1.4; docs-v5 re-read offline from the desk's raw copy on 2026-10-21):

| stream         | endpoint                                        | page              | documented rate |
|----------------|-------------------------------------------------|-------------------|-----------------|
| `candles_1m`   | `GET /api/v5/market/history-candles`            | ≤ 300 (def. 100)  | 20 req / 2 s, IP |
| `mark_1m`      | `GET /api/v5/market/history-mark-price-candles` | ≤ 100             | 20 req / 2 s, IP |
| `index_1m`     | `GET /api/v5/market/history-index-candles`      | ≤ 100             | 10 req / 2 s, IP |
| `funding_hist` | `GET /api/v5/public/funding-rate-history`       | ≤ 400 (def. 400)  | 10 req / 2 s, IP + instId |

Rows: candles `[ts,o,h,l,c,vol,volCcy,volCcyQuote,confirm]`, mark / index candles `[ts,o,h,l,c,confirm]`
(`ts` = opening time, ms), funding history objects `{fundingTime, fundingRate (predicted),
realizedRate (actual), method, formulaType, …}` ("This endpoint can return data up to three months").
The index id of an X-Perp is its `uly` (`BTC-USD_UM_XPERP-310404` → `BTC-USD`; docs-v5: "Index, e.g.
BTC-USD. Same as uly"), deduced from the instruments metadata file, with a regex fallback on the id.

**END_EXCLUSIVE = 2026-10-14T00:00:00Z is a module constant**, not a parameter: no command-line
option, no configuration field and no test hook can move it. Pagination starts at it and walks
towards the past with `after` (docs-v5: "Pagination of data to return records earlier than the
requested ts"); independently of what the server returns, `EndGuard.admits` refuses any row whose
**market timestamp `ts_us`** (candle opening time, or `fundingTime`) is at or after it, before the row
reaches the store, and the refusals are counted in the manifest (`rejected_at_or_after_end`). The
`fetched_us` column of every row is **provenance** (the local instant of the request, necessarily
≥ 2026-10-21): it is not a market time and the guard does not bear on it. Consequence written here:
the settlement of exactly 2026-10-14T00:00 is never known, so a window of 2026-10-13 that ends at
00:00 cannot have its funding observed (the evaluator counts it, `funding_unknown_end_edge`). The
forward test `fwd-okx-r1` (2026-10-14..31) is unreachable by construction; the prospective capture has
its own floor (`xperp_capture.QUARANTINE_FLOOR`).

**End of a job, coverage.** Each (instrument, stream) state records `end_reason` — `reached_start`
(the page reached the instrument's floor: history complete from `listTime`), `server_empty_before_start`
(the server stopped serving before the floor: the documented three-month horizon of the funding
history, or a shorter candle history), `malformed_page` (a `data` that is not a list, retried then
fatal), `error`, `paused` (`--max-pages`), `max_requests_reached` — and `coverage_start_us` (the
oldest instant from which the stream is complete; the D_cov of `LP-G5` §4.4) with `truncated`. A
page whose records are all unusable is `malformed_page`, never an end of history; skipped records
are counted (`skipped_records`), so are unconfirmed candles (`unconfirmed_rows`, `confirm = 0`).

**Scale (licence §9.4, `LP-G5` P-G5-1).** A real run needs: `--decision-ref TEXT` (the owner's written
confirmation, copied into the manifest and the journal), `--spec FILE --phase pre_freeze|post_freeze` (the G5
machine spec `g5-spec/2`, read by the strict parser of `g5_eval`: its `fetch_plan` fixes the streams of the
phase, the page sizes, the per-stream starts, the sealed mode, the host and the instruments = `universe.analysed`),
an explicit instrument list (`--inst-ids` or `--inst-ids-file`) equal to the spec's universe — its sha256 (of
the sorted ids, one per line, `inst_ids_sha256`) is recorded in the plan, the manifest and the receipt —,
`--max-requests N` (a hard cap on every request sent, retries included) and `--plan FILE`, the dry-run plan of
exactly this configuration (its jobs are recomputed and compared; its sha256 is copied into the manifest next
to `decision_ref`; refused when its estimate exceeds the cap). The post-freeze phase also needs
`--funding-receipt FILE`, the public receipt of the sealed funding fetch: its `coverage_start_utc` is D_cov.
`--host global` (outside the EEA licence of the owner, `france-market-eligibility`) is refused for a real run
without `--host-reason TEXT`. Without any of these the command exits 2 and connects to nothing.

**Per-stream starts (`LP-G5` §4.3, spec `fetch_plan.post_freeze`).** With an eligibility rule (spec mode):
`candles_1m` from `listTime + eligibility − 1 day`, `mark_1m` and `index_1m` from
`max(D_cov − 1 day, listTime + eligibility − 1 day)` (the index of an underlying from the earliest of its
contracts), `funding_hist` from `listTime` (the depth served). Without it (unit tests, dry runs without a
spec) every stream starts at `max(--start, listTime)`. In spec mode the estimate of a candle walk counts no
final empty page (its last page reaches the start) and the funding estimate counts its rows from the D_cov
the spec assumes (`fetch_plan.estimate_assumption`): the totals reproduce the spec's `requests_estimate`.

**Sealed mode (`LP-G5` P-G5-3, (B) xix).** The funding history is fetched **only** in sealed mode, before the
freeze: the store is `<out>/sealed-funding/` while the run lasts, whose manifest holds the per-instrument states
(rows, oldest / newest, pages, errors, files and their sha256); at the end of the run it is archived into the one
file `<out>/funding-sealed.tar` and the tree is removed (`SEALED_ARCHIVE_RULE`, CTO m3: a directory listing would
show the files and sizes per instrument and day, hence the escalations); the console and `<out>/fetch.log` carry
the global totals only (`SEALED_PRINTED`: jobs, complete jobs, jobs in error, D_cov) — no instrument id, no row,
page or request count; the public receipt `<out>/funding-sealed-receipt.json` holds those totals, the sha256 of
the archive, of the sealed manifest and a digest of every sealed file (`sealed_files_digest`); the evaluator reads
the archive in memory (`SealedArchive`). D_cov of a job = its first
settlement whose predecessor is also served (the second oldest `fundingTime` stored), once the job ended
`reached_start` or `server_empty_before_start`; the global `coverage_start_utc` = the latest of these over the
jobs the server truncated (the documented three-month horizon), else the earliest. Outside sealed mode a
`funding_hist` stream is refused, and a sealed run fetches nothing else.

**Rate limits.** One sliding-window limiter per stream, at `rate_scale` (default 0.8) of the documented
ceiling — the ceiling itself is never exceeded. HTTP 429 / 5xx, the OKX codes 50001 (service
temporarily unavailable), 50004 (endpoint request timeout, served with HTTP 400), 50011 (rate limit),
50013 (systems busy), 50026 (system error), network errors and unreadable or malformed bodies are
retried with an exponential backoff (2 s doubling to 60 s, 8 attempts), at least `Retry-After` seconds
when the server sends it (capped at 120 s); any other 4xx or OKX code is fatal for the job and recorded.

**Resume.** The store is `tape.TapeStore` (segments appended at each checkpoint, days finalized
with a sha256 in the manifest, nothing ever overwritten: the design of `xperp_capture`). The
manifest keeps one state per (instrument, stream): the oldest `ts` seen (`cursor_after_ms`), the
page / row counters, `complete`, `end_reason`, `coverage_start_us`. A restart resumes from the cursor;
rows fetched twice around a checkpoint are de-duplicated on `ts_us` at finalization; `--force`
re-walks a job from END_EXCLUSIVE. The manifest totals (`requests`, `retries`,
`rejected_at_or_after_end`) are summed over the sessions.

**Dry run.** `--dry-run` lists every first request and the estimated number of pages, requests
and minimal duration at the rate limits, and writes nothing (not even a manifest) unless
`--plan-out` is given. The metadata snapshot's date (from its file name) is reported, flagged when it
falls inside the quarantine 2026-10-14..31 (`GATES` §9.2: re-frozen after the window, `LP-G5` P-G5-4).

Network access happens only in `UrllibClient.get`; every test drives `Fetcher` with a fake client.
"""

from __future__ import annotations

import argparse
import hashlib
import io
import json
import math
import os
import re
import shutil
import sys
import tarfile
import time
import urllib.error
import urllib.parse
import urllib.request
from collections import deque
from dataclasses import dataclass, field
from datetime import date as _date
from pathlib import Path
from typing import Callable, NamedTuple

import numpy as np

from . import tape
from . import xperp_capture as xc

# The historical window of G5 Gate 1 ends here. A constant: nothing whose market timestamp is 2026-10-14 or later may be persisted by this module.
END_EXCLUSIVE = "2026-10-14T00:00:00Z"
END_EXCLUSIVE_US = tape.parse_iso_utc(END_EXCLUSIVE)
END_EXCLUSIVE_MS = END_EXCLUSIVE_US // 1000
DEFAULT_START = "2026-03-30T00:00:00Z"  # first X-Perp listing (metadata, `listTime` of BTC-USD_UM_XPERP-310404); the per-instrument floor is its own listTime
DEFAULT_HOST = "https://eea.okx.com"
USER_AGENT = "tradingV3-research-lp-g5/1.1"
MANIFEST_KIND = "lp-xperp-1m-history"
PLAN_KIND = "lp-g5-fetch-plan"
JOURNAL_NAME = "fetch.log"
MINUTE_MS = 60_000
RETRY_AFTER_CAP_S = 120.0
_INDEX_RE = re.compile(r"^(?P<base>[A-Z0-9]+)-USD_UM(?:_XPERP)?-\d{6}$")
_SNAPSHOT_DATE_RE = re.compile(r"(?<!\d)(20\d{2})(\d{2})(\d{2})(?!\d)")
_SHA256_RE = re.compile(r"^[0-9a-f]{64}$")
END_REASONS = ("reached_start", "server_empty_before_start", "malformed_page", "error", "paused", "max_requests_reached")
COMPLETE_REASONS = ("reached_start", "server_empty_before_start")
DAY_US = tape.DAY_S * tape.SECOND_US
# Sealed mode (LP-G5 P-G5-3): the pre-freeze funding fetch.
SEALED_DIR_NAME = "sealed-funding"
SEALED_RECEIPT_NAME = "funding-sealed-receipt.json"
SEALED_ARCHIVE_NAME = "funding-sealed.tar"
SEALED_ARCHIVE_RULE = ("at the end of a sealed run the store is archived into one file (funding-sealed.tar: deterministic tar, members in the order of "
                       "directory_digest, mtime 0, owner 0, mode 0600) whose sha256 is in the receipt, then the sealed-funding/ tree is removed: no "
                       "per-instrument file, name or size stays listable; the evaluator reads the archive in memory; a resumed sealed run unpacks it "
                       "first (CTO m3)")
RECEIPT_KIND = "lp-g5-funding-sealed-receipt"
SEALED_STREAMS = ("funding_hist",)
SEALED_PRINTED = ("jobs_total", "jobs_complete", "jobs_error", "coverage_start_utc")
SEALED_COVERAGE_RULE = ("D_cov of a job = its first fundingTime whose predecessor is also served (second oldest stored), once it ended reached_start or "
                        "server_empty_before_start; global = the latest over the server-truncated jobs, else the earliest")
# What a sealed run may write to the console and the journal: these events, these fields; nothing per instrument.
SEALED_JOURNAL = {"fetch_start": ("jobs", "host", "end_exclusive", "decision_ref", "plan_sha256", "max_requests", "mode"),
                  "retry": ("stream", "attempt", "reason", "delay_s", "retry_after_s"),
                  "snapshot_in_quarantine": ("snapshot_date", "note"),
                  "sealed_end": SEALED_PRINTED + ("receipt_sha256", "sealed_manifest_sha256", "sealed_archive_sha256", "budget_exhausted")}
# The start rules of the spec's fetch_plan.post_freeze (text → rule) and the streams of each phase.
START_RULES = {"eligible_from_utc - 1 day": "eligibility_minus_1d", "max(coverage_start - 1 day, eligible_from_utc - 1 day)": "max_coverage_eligibility_minus_1d"}
PHASES = {"pre_freeze": SEALED_STREAMS, "post_freeze": ("candles_1m", "mark_1m", "index_1m")}


@dataclass(frozen=True)
class StreamSpec:
    name: str  # tape layout
    path: str
    rate_requests: int
    rate_window_s: float
    limit_max: int
    limit_default: int
    kind: str  # candles | px_candles | funding
    with_bar: bool
    rate_rule: str


STREAMS: dict[str, StreamSpec] = {
    "candles_1m": StreamSpec("candles_1m", "/api/v5/market/history-candles", 20, 2.0, 300, 100, "candles", True, "IP"),
    "mark_1m": StreamSpec("mark_1m", "/api/v5/market/history-mark-price-candles", 20, 2.0, 100, 100, "px_candles", True, "IP"),
    "index_1m": StreamSpec("index_1m", "/api/v5/market/history-index-candles", 10, 2.0, 100, 100, "px_candles", True, "IP"),
    # docs-v5: "Number of results per request. The maximum is 400; The default is 400".
    "funding_hist": StreamSpec("funding_hist", "/api/v5/public/funding-rate-history", 10, 2.0, 400, 400, "funding", False, "IP + instId"),
}
assert tuple(STREAMS) == tape.HISTORY_LAYOUTS
# docs-v5 error table (offline copy): 50001 503 "Service temporarily unavailable", 50004 400 "API endpoint request timeout",
# 50011 200/429 "Rate limit reached" / "Too Many Requests", 50013 429 "Systems are busy", 50026 500 "System error. Try again later".
RETRY_CODES = frozenset({"50001", "50004", "50011", "50013", "50026"})
METHOD_CODE = {"next_period": 1, "current_period": 0}
FORMULA_CODE = {"withRate": 1, "noRate": 0}


def sha256_text(text: str) -> str:
    return hashlib.sha256(text.encode()).hexdigest()


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def inst_ids_digest(ids) -> str:
    """sha256 of the sorted instrument ids, one per line (the hashed list of a real run, whatever form it was given in)."""
    return sha256_text("".join(f"{i}\n" for i in sorted(set(str(x) for x in ids))))


def directory_digest(root: Path) -> tuple[str, int]:
    """sha256 of the lines `<relative path> <sha256>` of every file under `root`, sorted; and the number of files."""
    root = Path(root)
    lines = [f"{path.relative_to(root).as_posix()} {tape.sha256_of(path)}\n" for path in sorted(root.rglob("*")) if path.is_file()]
    return sha256_text("".join(lines)), len(lines)


# ----------------------------------------------------------------------------- the sealed archive (CTO m3)


class SealedArchive:
    """The sealed funding store read from its archive `funding-sealed.tar`, in memory: nothing is extracted to disk, no member is
    listed anywhere (`SEALED_ARCHIVE_RULE`). `files_digest` and `manifest_sha256` are those of `directory_digest` and of the store's
    manifest before archiving (the receipt holds both, plus the archive's own sha256)."""

    def __init__(self, path: Path, data: bytes) -> None:
        self.path = Path(path)
        self.sha256 = sha256_bytes(data)
        members: dict[str, bytes] = {}
        with tarfile.open(fileobj=io.BytesIO(data), mode="r:") as tar:
            for info in tar.getmembers():
                parts = Path(info.name).parts
                if not info.isfile() or info.name.startswith("/") or ".." in parts or not parts or info.name in members:
                    raise ValueError(f"{self.path}: unexpected archive member {info.name!r}")
                handle = tar.extractfile(info)
                members[info.name] = handle.read() if handle is not None else b""
        self.members = members  # archive order = directory_digest order

    @classmethod
    def open(cls, path: Path) -> "SealedArchive":
        return cls(path, Path(path).read_bytes())

    @property
    def files_digest(self) -> str:
        return sha256_text("".join(f"{name} {sha256_bytes(data)}\n" for name, data in self.members.items()))

    @property
    def manifest_sha256(self) -> str | None:
        data = self.members.get(tape.MANIFEST_NAME)
        return sha256_bytes(data) if data is not None else None

    @property
    def manifest(self) -> dict | None:
        data = self.members.get(tape.MANIFEST_NAME)
        return json.loads(data.decode("utf-8")) if data is not None else None

    def load_days(self, layout: str, inst: str, dates: list[str]) -> np.ndarray:
        """The finalized day files of one instrument (as `tape.load_days(..., allow_pending=False)`), de-duplicated and sorted."""
        parts = []
        for date in dates:
            data = self.members.get(f"{layout}/{tape.check_inst_name(inst)}/{inst}-{date}.npz")
            if data is None:
                continue
            with np.load(io.BytesIO(data)) as z:
                parts.append(tape.arrays_to_rows(layout, {name: z[name] for name in z.files}))
        if not parts:
            return tape.empty_rows(layout)
        return tape.dedupe_and_sort(layout, np.concatenate(parts))[0]


def archive_sealed_store(out_dir: Path) -> dict:
    """`SEALED_ARCHIVE_RULE`: `<out>/sealed-funding/` → `<out>/funding-sealed.tar` (written atomically, re-read and compared with the
    store before the tree is removed; on any difference the store is kept and the run fails)."""
    out_dir = Path(out_dir)
    root = out_dir / SEALED_DIR_NAME
    files = [path for path in sorted(root.rglob("*")) if path.is_file()] if root.is_dir() else []
    if not (root / tape.MANIFEST_NAME).is_file():
        raise RuntimeError(f"{root}: no sealed store to archive")
    digest, count = directory_digest(root)
    manifest_sha = tape.sha256_of(root / tape.MANIFEST_NAME)
    target = out_dir / SEALED_ARCHIVE_NAME
    tmp = target.with_name(target.name + ".tmp")
    with tmp.open("wb") as handle:
        with tarfile.open(fileobj=handle, mode="w", format=tarfile.PAX_FORMAT) as tar:
            for path in files:
                data = path.read_bytes()
                info = tarfile.TarInfo(path.relative_to(root).as_posix())
                info.size, info.mtime, info.mode, info.uid, info.gid, info.uname, info.gname = len(data), 0, 0o600, 0, 0, "", ""
                tar.addfile(info, io.BytesIO(data))
        handle.flush()
        os.fsync(handle.fileno())
    os.chmod(tmp, 0o600)
    tmp.replace(target)
    view = SealedArchive.open(target)
    if view.files_digest != digest or view.manifest_sha256 != manifest_sha or len(view.members) != count:
        raise RuntimeError(f"{target}: the archive differs from the sealed store (the store is kept)")
    shutil.rmtree(root)
    return {"sealed_archive": SEALED_ARCHIVE_NAME, "sealed_archive_sha256": view.sha256, "sealed_manifest_sha256": manifest_sha, "sealed_files_digest": digest}


def restore_sealed_store(out_dir: Path) -> bool:
    """A resumed sealed run: the archive of an earlier session is unpacked into `sealed-funding/` (re-archived at the end of the run).
    Nothing happens when the tree exists (an interrupted session) or when there is no archive."""
    out_dir = Path(out_dir)
    root, path = out_dir / SEALED_DIR_NAME, out_dir / SEALED_ARCHIVE_NAME
    if root.exists() or not path.exists():
        return False
    view = SealedArchive.open(path)
    for name, data in view.members.items():
        target = root / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)
    return True


# ----------------------------------------------------------------------------- guard


class EndGuard:
    """Refuses every row whose market timestamp `ts_us` is at or after `END_EXCLUSIVE_US` before it reaches the store.
    The bound is the module constant: this class takes no parameter and offers no override."""

    __slots__ = ("rejected",)

    def __init__(self) -> None:
        self.rejected = 0

    def admits(self, ts_us: int) -> bool:
        if int(ts_us) >= END_EXCLUSIVE_US:
            self.rejected += 1
            return False
        return True


# ----------------------------------------------------------------------------- instruments


def index_id_of(row: dict) -> str:
    """The index instrument id of a FUTURES row: `uly` when present, else `<BASE>-USD` from the id."""
    uly = str(row.get("uly") or "").strip()
    if uly:
        return uly
    match = _INDEX_RE.match(str(row.get("instId", "")))
    if not match:
        raise ValueError(f"cannot deduce the index id of {row.get('instId')!r}: no uly and an unknown id shape")
    return f"{match.group('base')}-USD"


def load_instruments(path: Path, inst_ids: list[str] | None = None, family: str = "xperp", only_live: bool = True, max_instruments: int | None = None,
                     rule_type: str | None = "xperp") -> list[dict]:
    """Instrument rows of the metadata snapshot (`{"code","data":[…]}`, `{"instruments":[…]}` or a list), filtered on the
    id family, the `live` state and `ruleType` (a row stating another rule type — `pre_market` X-Perps before their
    conversion — is skipped; a row without the field is kept), restricted to `inst_ids` when given. Every row must carry `listTime`."""
    rows = xc.select_instruments(xc.load_instruments_file(Path(path)), family, only_live=only_live)
    if rule_type:
        rows = [row for row in rows if not str(row.get("ruleType") or "").strip() or str(row.get("ruleType")) == rule_type]
    if inst_ids:
        wanted = set(inst_ids)
        rows = [row for row in rows if row["instId"] in wanted]
        missing = wanted - {row["instId"] for row in rows}
        if missing:
            raise SystemExit(f"instruments absent from {path} (or not live / not {family}): {sorted(missing)}")
    for row in rows:
        if not str(row.get("listTime") or "").strip():
            raise SystemExit(f"{path}: {row['instId']} has no listTime; the metadata file must carry it (the per-instrument start of the history)")
    if max_instruments is not None:
        rows = rows[: int(max_instruments)]
    return rows


def read_inst_ids_file(path: Path) -> tuple[list[str], str]:
    """One instrument id per line (`#` comments and blank lines ignored); returns the ids and the file's sha256."""
    data = Path(path).read_bytes()
    ids = []
    for line in data.decode("utf-8").splitlines():
        text = line.split("#", 1)[0].strip()
        if text:
            ids.append(text)
    if len(set(ids)) != len(ids):
        raise SystemExit(f"{path}: duplicate instrument ids")
    return ids, sha256_bytes(data)


def snapshot_info(path: Path) -> dict:
    """Provenance of the metadata snapshot: file, sha256, the date read in its file name (else UNKNOWN), and whether that date
    falls inside the quarantine [2026-10-14, 2026-11-02) — metadata dated there must be re-frozen after the window (GATES §9.2)."""
    path = Path(path)
    info = {"file": str(path), "sha256": tape.sha256_of(path) if path.exists() else None, "snapshot_date": "UNKNOWN", "snapshot_date_source": None, "in_quarantine": None}
    for match in _SNAPSHOT_DATE_RE.finditer(path.name):
        try:
            day = _date(int(match.group(1)), int(match.group(2)), int(match.group(3)))
        except ValueError:
            continue
        iso = day.isoformat()
        info.update({"snapshot_date": iso, "snapshot_date_source": "file name"})
        day_us = tape.day_of_date(iso) * tape.DAY_S * tape.SECOND_US
        info["in_quarantine"] = END_EXCLUSIVE_US <= day_us < xc.quarantine_floor_us()
        break
    if info["in_quarantine"]:
        info["note"] = "metadata snapshot dated inside the quarantine 2026-10-14..31: re-freeze it after the window (GATES §9.2, LP-G5 P-G5-4)"
    return info


# ----------------------------------------------------------------------------- rate limiting


class RateLimiter:
    """At most `floor(requests × scale)` calls in any sliding window of `window_s` seconds. The clock and the sleep
    are injectable (tests); `waits` / `waited_s` tell how often the limiter had to hold a request back."""

    def __init__(self, requests: int, window_s: float, scale: float = 1.0, clock: Callable[[], float] = time.monotonic, sleep: Callable[[float], None] = time.sleep) -> None:
        if not (0.0 < scale <= 1.0):
            raise ValueError(f"rate scale must be in (0, 1], got {scale}")
        self.capacity = max(1, int(math.floor(requests * scale + 1e-9)))
        self.window_s = float(window_s)
        self.clock = clock
        self.sleep = sleep
        self.stamps: deque[float] = deque()
        self.calls = 0
        self.waits = 0
        self.waited_s = 0.0

    def _prune(self, now: float) -> None:
        while self.stamps and now - self.stamps[0] >= self.window_s:
            self.stamps.popleft()

    def acquire(self) -> None:
        now = self.clock()
        self._prune(now)
        while len(self.stamps) >= self.capacity:
            wait = self.window_s - (now - self.stamps[0])
            if wait > 0:
                self.sleep(wait)
                self.waits += 1
                self.waited_s += wait
            now = self.clock()
            before = len(self.stamps)
            self._prune(now)
            if len(self.stamps) == before:  # a clock that does not advance: never spin
                self.stamps.popleft()
        self.stamps.append(now)
        self.calls += 1

    def min_duration_s(self, requests: int) -> float:
        """Lower bound of the time `requests` calls take under this limiter (dry-run estimate)."""
        if requests <= 0:
            return 0.0
        return max(0.0, (math.ceil(requests / self.capacity) - 1) * self.window_s)


# ----------------------------------------------------------------------------- HTTP


class HttpResponse(NamedTuple):
    status: int
    body: bytes
    retry_after_s: float | None = None  # the `Retry-After` header, in seconds, when the server sent one


class TransientError(RuntimeError):
    """Network-level failure (timeout, connection reset) or an unreadable body: retried with backoff."""


class MalformedPage(TransientError):
    """`code = 0` but `data` is not a list: retried with backoff, then fatal (`malformed_page`) — never an end of history."""


class FatalStreamError(RuntimeError):
    """The (instrument, stream) job cannot continue: recorded, the fetcher moves on."""


class BudgetExhausted(RuntimeError):
    """`max_requests` reached: the whole run stops (the next session resumes from the cursors)."""


def _retry_after(headers) -> float | None:
    if headers is None:
        return None
    try:
        value = headers.get("Retry-After")
    except AttributeError:
        return None
    if value is None:
        return None
    try:
        seconds = float(str(value).strip())
    except ValueError:  # an HTTP-date: not honoured (the exponential backoff applies)
        return None
    return seconds if math.isfinite(seconds) and seconds >= 0 else None


class UrllibClient:
    """The only place of this module that opens a connection. Never constructed by the tests or by a dry run."""

    def __init__(self, timeout_s: float = 20.0) -> None:
        self.timeout_s = float(timeout_s)

    def get(self, url: str) -> HttpResponse:
        request = urllib.request.Request(url, headers={"User-Agent": USER_AGENT, "Accept": "application/json"})
        try:
            with urllib.request.urlopen(request, timeout=self.timeout_s) as response:
                return HttpResponse(int(response.status), response.read(), _retry_after(response.headers))
        except urllib.error.HTTPError as error:
            return HttpResponse(int(error.code), error.read() or b"", _retry_after(error.headers))
        except (urllib.error.URLError, TimeoutError, OSError) as error:
            raise TransientError(str(error)) from None


def build_url(host: str, stream: StreamSpec, request_inst_id: str, after_ms: int, limit: int) -> str:
    params = {"instId": request_inst_id}
    if stream.with_bar:
        params["bar"] = "1m"
    params["after"] = str(int(after_ms))
    params["limit"] = str(int(limit))
    return xc.resolve_rest(host).rstrip("/") + stream.path + "?" + urllib.parse.urlencode(params)


def parse_body(body: bytes) -> tuple[str, str, list]:
    """`(code, msg, data)` of an OKX envelope. An unreadable body raises `TransientError`; `code = 0` with a `data` that
    is not a list raises `MalformedPage` (both retried, then fatal): a malformed page is never read as the end of the history."""
    try:
        document = json.loads(body.decode("utf-8"))
    except (UnicodeDecodeError, ValueError) as error:
        raise TransientError(f"unreadable body: {error}") from None
    if not isinstance(document, dict):
        raise TransientError("body is not a JSON object")
    code = str(document.get("code", ""))
    data = document.get("data")
    if code == "0" and not isinstance(data, list):
        raise MalformedPage(f"data is {type(data).__name__}, not a list")
    return code, str(document.get("msg", "")), data if isinstance(data, list) else []


def rows_from_page(stream: StreamSpec, data: list, fetched_us: int) -> tuple[list[tuple[int, tuple]], int]:
    """`(rows, skipped)`: `(ts_us, row)` per usable record, in the order served (newest first), and the number of records
    skipped (not a list / an object, no usable timestamp). Missing numbers → NaN. `fetched_us` is provenance, not a market time."""
    out: list[tuple[int, tuple]] = []
    skipped = 0
    for record in data:
        if stream.kind in ("candles", "px_candles"):
            if not isinstance(record, (list, tuple)) or not record:
                skipped += 1
                continue
            ts_ms = xc._i(record[0], 0)
            if ts_ms <= 0:
                skipped += 1
                continue
            ts_us = ts_ms * 1000
            values = [xc._f(record[k]) if k < len(record) else float("nan") for k in range(1, 5)]
            if stream.kind == "candles":
                vol = [xc._f(record[k]) if k < len(record) else float("nan") for k in range(5, 8)]
                confirm = xc._i(record[8], -1) if len(record) > 8 else -1
                out.append((ts_us, (ts_us, fetched_us, *values, *vol, confirm)))
            else:
                confirm = xc._i(record[5], -1) if len(record) > 5 else -1
                out.append((ts_us, (ts_us, fetched_us, *values, confirm)))
        elif stream.kind == "funding":
            if not isinstance(record, dict):
                skipped += 1
                continue
            ts_ms = xc._i(record.get("fundingTime"), 0)
            if ts_ms <= 0:
                skipped += 1
                continue
            ts_us = ts_ms * 1000
            out.append((ts_us, (ts_us, fetched_us, xc._f(record.get("fundingRate")), xc._f(record.get("realizedRate")),
                                METHOD_CODE.get(str(record.get("method", "")), -1), FORMULA_CODE.get(str(record.get("formulaType", "")), -1))))
    return out, skipped


# ----------------------------------------------------------------------------- jobs and plan


@dataclass(frozen=True)
class Job:
    inst_id: str  # store key (the index id for `index_1m`)
    stream: str
    request_inst_id: str
    start_us: int  # oldest instant wanted (listTime, or the global --start when later)

    @property
    def key(self) -> str:
        return f"{self.inst_id}:{self.stream}"


def stream_start_us(row: dict, stream: str, floor_us: int, eligibility_days: float | None = None, coverage_start_us: int | None = None) -> int:
    """Oldest instant wanted for one (instrument, stream) (LP-G5 §4.3). Without `eligibility_days`: max(floor, listTime). With it:
    candles from listTime + eligibility − 1 day; mark / index from max(D_cov − 1 day, listTime + eligibility − 1 day); funding
    history from listTime (the depth served)."""
    listed_us = xc._i(row.get("listTime"), 0) * 1000
    if eligibility_days is None or stream == "funding_hist":
        return max(floor_us, listed_us)
    eligible_minus_day = listed_us + int(round(float(eligibility_days) * DAY_US)) - DAY_US
    if stream == "candles_1m":
        return max(floor_us, eligible_minus_day)
    if coverage_start_us is None:
        raise ValueError(f"{stream} starts at max(D_cov - 1 day, eligibility - 1 day): coverage_start_us (D_cov of the sealed funding receipt) is required")
    return max(floor_us, eligible_minus_day, int(coverage_start_us) - DAY_US)


def jobs_for(rows: list[dict], streams: tuple[str, ...], start: str = DEFAULT_START, eligibility_days: float | None = None,
             coverage_start_us: int | None = None) -> list[Job]:
    """One job per (instrument, stream), starting at `stream_start_us`; the index stream is shared by the contracts of one
    underlying and starts at the earliest of their starts. A job whose start is not before END_EXCLUSIVE is refused (an
    instrument without any eligible minute is never fetched, spec `universe.excluded_never_eligible`)."""
    for stream in streams:
        if stream not in STREAMS:
            raise ValueError(f"unknown stream {stream}")
    floor_us = tape.parse_iso_utc(start)
    if floor_us >= END_EXCLUSIVE_US:
        raise ValueError(f"start {start} is not before END_EXCLUSIVE {END_EXCLUSIVE}")
    jobs: list[Job] = []
    index_start: dict[str, int] = {}
    for row in rows:
        inst = tape.check_inst_name(str(row["instId"]))
        for stream in streams:
            start_us = stream_start_us(row, stream, floor_us, eligibility_days, coverage_start_us)
            if start_us >= END_EXCLUSIVE_US:
                raise ValueError(f"{inst} {stream}: start {tape.iso_utc(start_us)} is not before END_EXCLUSIVE {END_EXCLUSIVE} (no eligible minute: never fetched)")
            if stream == "index_1m":
                index = tape.check_inst_name(index_id_of(row))
                index_start[index] = min(index_start.get(index, start_us), start_us)
            else:
                jobs.append(Job(inst, stream, inst, start_us))
    if "index_1m" in streams:
        jobs.extend(Job(index, "index_1m", index, start_us) for index, start_us in sorted(index_start.items()))
    return jobs


def estimate_pages(job: Job, limit: int, walk_reaches_start: bool = False, funding_from_us: int | None = None) -> int:
    """Pages a job needs if the server holds the whole window (candles: one row per minute; funding: three per day at
    the 8 h interval — an estimate labelled as such, 4 h contracts need up to twice as many), + the empty page that ends
    the walk. Spec mode (LP-G5 §4.3 audit): a candle walk whose last page reaches its start needs no empty page
    (`walk_reaches_start`); the funding walk always ends with one (its oldest settlement is after listTime) and counts its
    rows from max(start, `funding_from_us`), the D_cov the spec assumes (the server keeps three months)."""
    first_us = int(job.start_us)
    if STREAMS[job.stream].kind == "funding":
        if funding_from_us is not None:
            first_us = max(first_us, int(funding_from_us))
        rows = math.ceil((END_EXCLUSIVE_MS - first_us // 1000) / (8 * 3600 * 1000))
        return max(1, math.ceil(rows / limit) + 1)
    rows = math.ceil((END_EXCLUSIVE_MS - first_us // 1000) / MINUTE_MS)
    return max(1, math.ceil(rows / limit) + (0 if walk_reaches_start else 1))


def spec_estimate(instruments: list[dict], eligibility_days: float, coverage_start_us: int, pages: dict) -> dict:
    """Requests per stream of the spec's fetch plan (calcul d'audit, no data): `instruments` = rows with `instId`, `listTime`
    (ms) and `uly`; `pages` = the page size per stream. Reproduces `fetch_plan.*.requests_estimate` (LP-G5 §4.3)."""
    out = {}
    floor = DEFAULT_START
    for stream in STREAMS:
        jobs = jobs_for(instruments, (stream,), floor, eligibility_days, coverage_start_us)
        out[stream] = sum(estimate_pages(job, int(pages[stream]), walk_reaches_start=True, funding_from_us=coverage_start_us) for job in jobs)
    return out


# ----------------------------------------------------------------------------- fetcher


@dataclass
class FetchConfig:
    out_dir: Path
    instruments: list[dict]
    host: str = DEFAULT_HOST
    streams: tuple[str, ...] = tuple(STREAMS)
    limit: int | None = None  # None → each stream's default; capped at its documented maximum
    start: str = DEFAULT_START
    rate_scale: float = 0.8
    max_attempts: int = 8
    backoff_first_s: float = 2.0
    backoff_max_s: float = 60.0
    checkpoint_every_pages: int = 20
    max_pages_per_job: int | None = None  # a budget bound for a partial session (resumed later); None = until exhausted
    max_requests: int | None = None  # hard cap on the requests of this session, retries included (mandatory for a real run)
    decision_ref: str = ""
    plan_sha256: str = ""  # sha256 of the dry-run plan file of this configuration (mandatory for a real run)
    host_reason: str = ""  # written reason for a non-EEA host (mandatory for `global`)
    inst_ids_source: str = ""  # how the instrument list was given (`--inst-ids` text, or the hashed `--inst-ids-file`)
    inst_ids_sha256: str = ""  # sha256 of the sorted instrument ids, one per line (`inst_ids_digest`): the hashed list of a real run
    sealed: bool = False  # the pre-freeze funding fetch (LP-G5 P-G5-3): per-instrument state sealed, global totals only on screen and journal
    eligibility_days: float | None = None  # spec mode: per-stream starts (LP-G5 §4.3)
    coverage_start_us: int | None = None  # D_cov of the sealed funding receipt (spec mode, post-freeze phase: mark / index starts)
    estimate_coverage_start_us: int | None = None  # the D_cov the spec's estimate assumes (funding pages of a spec-mode dry run)
    limits: dict | None = None  # per-stream page sizes (spec mode: the fetch_plan pages); otherwise `limit`
    spec_info: dict | None = None  # path, sha256, spec_id, dataset_id, status and phase of the G5 spec driving the run
    funding_receipt: dict | None = None  # path, sha256 and coverage of the sealed receipt (post-freeze phase)
    instruments_file: str = ""
    snapshot: dict | None = None
    echo: bool = True
    argv: list[str] | None = field(default_factory=list)

    def limit_for(self, stream: str) -> int:
        spec = STREAMS[stream]
        if self.limits and stream in self.limits:
            wanted = int(self.limits[stream])
        else:
            wanted = spec.limit_default if self.limit is None else int(self.limit)
        return max(1, min(wanted, spec.limit_max))

    @property
    def spec_mode(self) -> bool:
        return self.eligibility_days is not None


def plan(cfg: FetchConfig) -> dict:
    """The dry run: every first request, the page estimates, the request total and the minimal duration at the limits.
    Opens no connection and writes nothing."""
    jobs = jobs_for(cfg.instruments, cfg.streams, cfg.start, cfg.eligibility_days, cfg.coverage_start_us)
    limiters = {name: RateLimiter(spec.rate_requests, spec.rate_window_s, cfg.rate_scale) for name, spec in STREAMS.items()}
    requests: dict[str, int] = {name: 0 for name in STREAMS}
    items = []
    for job in jobs:
        limit = cfg.limit_for(job.stream)
        pages = estimate_pages(job, limit, walk_reaches_start=cfg.spec_mode, funding_from_us=cfg.estimate_coverage_start_us if cfg.spec_mode else None)
        requests[job.stream] += pages
        items.append({"inst_id": job.inst_id, "stream": job.stream, "request_inst_id": job.request_inst_id, "start": tape.iso_utc(job.start_us), "end_exclusive": END_EXCLUSIVE,
                      "first_url": build_url(cfg.host, STREAMS[job.stream], job.request_inst_id, END_EXCLUSIVE_MS, limit), "limit": limit, "estimated_pages": pages})
    duration = {name: limiters[name].min_duration_s(count) for name, count in requests.items()}
    return {"kind": PLAN_KIND, "dry_run": True, "host": xc.resolve_rest(cfg.host), "end_exclusive": END_EXCLUSIVE, "start_floor": cfg.start, "rate_scale": cfg.rate_scale,
            "rate_limits": {name: {"documented": f"{spec.rate_requests} req / {spec.rate_window_s:g} s ({spec.rate_rule})", "used_capacity": limiters[name].capacity} for name, spec in STREAMS.items()},
            "instruments": [row["instId"] for row in cfg.instruments], "instruments_file": cfg.instruments_file or None, "snapshot": cfg.snapshot, "jobs": items,
            "requests_estimated": requests, "requests_total": sum(requests.values()),
            "min_duration_s_by_stream": duration, "min_duration_s_sequential": math.fsum(duration.values()), "generated_at": tape.iso_now(),
            "mode": "sealed" if cfg.sealed else "values", "inst_ids_sha256": cfg.inst_ids_sha256 or None, "spec": cfg.spec_info, "eligibility_days": cfg.eligibility_days,
            "coverage_start": tape.iso_utc(int(cfg.coverage_start_us)) if cfg.coverage_start_us is not None else None,
            "estimate_coverage_start": tape.iso_utc(int(cfg.estimate_coverage_start_us)) if cfg.estimate_coverage_start_us is not None else None,
            "funding_receipt": cfg.funding_receipt,
            "note": "estimate assuming the server holds the whole window from each start; retries not included; the real run caps every request with --max-requests"}


def _plan_signature(document: dict) -> list[tuple]:
    return [(str(j.get("inst_id")), str(j.get("stream")), str(j.get("request_inst_id")), str(j.get("start")), int(j.get("limit", 0))) for j in document.get("jobs", [])]


def check_plan(cfg: FetchConfig, plan_path: Path, max_requests: int) -> tuple[dict, str]:
    """The dry-run plan of a real run: same kind, END_EXCLUSIVE, host and jobs as this configuration, and an estimate
    within `max_requests`. Returns the plan document and the file's sha256; `SystemExit` otherwise."""
    data = Path(plan_path).read_bytes()
    try:
        document = json.loads(data.decode("utf-8"))
    except (UnicodeDecodeError, ValueError) as error:
        raise SystemExit(f"plan {plan_path}: unreadable ({error})") from None
    if not isinstance(document, dict) or document.get("kind") != PLAN_KIND:
        raise SystemExit(f"plan {plan_path}: not a {PLAN_KIND} document")
    if document.get("end_exclusive") != END_EXCLUSIVE:
        raise SystemExit(f"plan {plan_path}: end_exclusive {document.get('end_exclusive')!r} differs from the constant {END_EXCLUSIVE}")
    if document.get("host") != xc.resolve_rest(cfg.host):
        raise SystemExit(f"plan {plan_path}: host {document.get('host')!r} differs from {xc.resolve_rest(cfg.host)!r}")
    if document.get("mode", "values") != ("sealed" if cfg.sealed else "values"):
        raise SystemExit(f"plan {plan_path}: mode {document.get('mode', 'values')!r} differs from this configuration's")
    if (document.get("spec") or {}).get("sha256") != (cfg.spec_info or {}).get("sha256") or (document.get("spec") or {}).get("phase") != (cfg.spec_info or {}).get("phase"):
        raise SystemExit(f"plan {plan_path}: made for another spec or phase than this configuration's")
    if (document.get("inst_ids_sha256") or None) != (cfg.inst_ids_sha256 or None):
        raise SystemExit(f"plan {plan_path}: its instrument list (inst_ids_sha256) differs from this configuration's")
    expected = plan(cfg)
    if _plan_signature(document) != _plan_signature(expected):
        raise SystemExit(f"plan {plan_path}: its jobs differ from this configuration's (re-run the dry run with the same options)")
    total = int(document.get("requests_total", 0))
    if total != int(expected["requests_total"]):
        raise SystemExit(f"plan {plan_path}: requests_total {total} differs from the recomputed {expected['requests_total']}")
    if total > int(max_requests):
        raise SystemExit(f"plan {plan_path}: estimated {total} requests exceed --max-requests {max_requests}")
    return document, sha256_bytes(data)


class Fetcher:
    """Runs the jobs against an injected HTTP client (`client.get(url) → HttpResponse`). `sleep` and `clock` are
    injectable so the tests never wait."""

    def __init__(self, cfg: FetchConfig, client, sleep: Callable[[float], None] = time.sleep, clock: Callable[[], float] = time.monotonic, now_us: Callable[[], int] = tape.utc_now_us) -> None:
        if not cfg.decision_ref.strip():
            raise ValueError("a real fetch needs decision_ref (the owner's written decision on the Market Data licence and its scale); use the dry run otherwise")
        if cfg.max_requests is None or int(cfg.max_requests) <= 0:
            raise ValueError("a real fetch needs max_requests > 0 (a hard cap on every request, retries included)")
        if not _SHA256_RE.match(str(cfg.plan_sha256 or "")):
            raise ValueError("a real fetch needs plan_sha256 (the sha256 of the dry-run plan of this configuration)")
        if xc.resolve_rest(cfg.host) == xc.REST_GLOBAL and not cfg.host_reason.strip():
            raise ValueError("the global host is outside the owner's EEA licence: a real fetch there needs host_reason")
        if not (0.0 < cfg.rate_scale <= 1.0):
            raise ValueError(f"rate_scale must be in (0, 1], got {cfg.rate_scale}")
        if "funding_hist" in cfg.streams and not cfg.sealed:
            raise ValueError("the funding history is fetched in sealed mode only (LP-G5 P-G5-3): its per-instrument rows and pages would reveal the 2 h / 1 h escalations")
        if cfg.sealed and tuple(cfg.streams) != SEALED_STREAMS:
            raise ValueError(f"a sealed run fetches {SEALED_STREAMS} only, not {tuple(cfg.streams)}")
        self.cfg = cfg
        self.client = client
        self.sleep = sleep
        self.now_us = now_us
        self.out_dir = Path(cfg.out_dir)
        self.store_dir = self.out_dir / SEALED_DIR_NAME if cfg.sealed else self.out_dir
        self.guard = EndGuard()
        self.limiters = {name: RateLimiter(spec.rate_requests, spec.rate_window_s, cfg.rate_scale, clock, sleep) for name, spec in STREAMS.items()}
        self.lock = tape.DirectoryLock(self.out_dir).acquire()
        self.receipt: dict | None = None
        try:
            if cfg.sealed:
                restore_sealed_store(self.out_dir)  # a resumed sealed run continues the archived store (CTO m3)
            journal = xc.HealthJournal(self.out_dir / JOURNAL_NAME, echo=cfg.echo)
            self.journal = SealedJournal(journal) if cfg.sealed else journal
            source = {"rest": xc.resolve_rest(cfg.host), "streams": list(cfg.streams), "end_exclusive": END_EXCLUSIVE, "start_floor": cfg.start, "rate_scale": cfg.rate_scale,
                      "endpoints": {name: STREAMS[name].path for name in cfg.streams}, "decision_ref": cfg.decision_ref, "plan_sha256": cfg.plan_sha256,
                      "max_requests": int(cfg.max_requests), "host_reason": cfg.host_reason or None, "inst_ids_source": cfg.inst_ids_source or None,
                      "inst_ids_sha256": cfg.inst_ids_sha256 or None, "mode": "sealed" if cfg.sealed else "values", "spec": cfg.spec_info,
                      "dataset_id": (cfg.spec_info or {}).get("dataset_id"), "eligibility_days": cfg.eligibility_days,
                      "coverage_start": tape.iso_utc(int(cfg.coverage_start_us)) if cfg.coverage_start_us is not None else None, "funding_receipt": cfg.funding_receipt,
                      "instruments_file": cfg.instruments_file, "instruments_snapshot": cfg.snapshot}
            self.store = tape.TapeStore(self.store_dir, "values", source=source, not_before=None, kind=MANIFEST_KIND)
        except Exception:
            self.lock.release()
            raise
        if self.store.manifest.get("kind") != MANIFEST_KIND:
            self.lock.release()
            raise ValueError(f"{self.out_dir}: holds a {self.store.manifest.get('kind')!r} tape, not a 1-minute history")
        manifest = self.store.manifest
        self.states: dict = manifest.setdefault("g5_streams", {})
        manifest.setdefault("sessions", []).append({"started_at": tape.iso_now(), "argv": list(cfg.argv or []), "decision_ref": cfg.decision_ref, "plan_sha256": cfg.plan_sha256,
                                                    "max_requests": int(cfg.max_requests), "host": xc.resolve_rest(cfg.host), "host_reason": cfg.host_reason or None})
        manifest["end_exclusive"] = END_EXCLUSIVE
        # Totals over every session: the values of the earlier sessions plus this one's (CTO m1).
        self._previous = {key: int(manifest.get(key, 0)) for key in ("rejected_at_or_after_end", "requests", "retries")}
        self.requests = 0
        self.retries = 0
        self.budget_exhausted = False
        if cfg.snapshot and cfg.snapshot.get("in_quarantine"):
            self.journal.log("WARN", "snapshot_in_quarantine", snapshot_date=cfg.snapshot.get("snapshot_date"), note="metadata only; re-freeze after 2026-10-31 (GATES §9.2)")
        self._write_instruments()

    # -- bookkeeping

    def _write_instruments(self) -> None:
        path = self.out_dir / "instruments-futures.json"
        known = {}
        if path.exists():
            try:
                known = {row["instId"]: row for row in json.loads(path.read_text()).get("instruments", []) if isinstance(row, dict) and row.get("instId")}
            except (ValueError, AttributeError):
                known = {}
        for row in self.cfg.instruments:
            known[row["instId"]] = {**xc.instrument_spec_row(row), "indexId": index_id_of(row)}
        document = {"kind": MANIFEST_KIND, "source_file": self.cfg.instruments_file or None, "snapshot": self.cfg.snapshot, "updated_at": tape.iso_now(),
                    "instruments": [known[k] for k in sorted(known)]}
        tape.atomic_write_text(path, json.dumps(document, indent=1, sort_keys=True) + "\n")

    @staticmethod
    def _defaults(job: Job) -> dict:
        return {"inst_id": job.inst_id, "stream": job.stream, "request_inst_id": job.request_inst_id, "start_us": int(job.start_us), "cursor_after_ms": END_EXCLUSIVE_MS,
                "pages": 0, "rows": 0, "rejected_at_or_after_end": 0, "dropped_before_start": 0, "skipped_records": 0, "unconfirmed_rows": 0, "oldest_ts_us": None,
                "second_oldest_ts_us": None, "newest_ts_us": None, "complete": False, "end_reason": None, "coverage_start_us": None, "d_cov_start_us": None, "truncated": None,
                "forced": 0, "errors": []}

    def state_of(self, job: Job) -> dict:
        state = self.states.setdefault(job.key, self._defaults(job))
        for key, value in self._defaults(job).items():
            state.setdefault(key, value)
        return state

    def _save(self) -> None:
        manifest = self.store.manifest
        manifest["rejected_at_or_after_end"] = self._previous["rejected_at_or_after_end"] + int(self.guard.rejected)
        manifest["requests"] = self._previous["requests"] + int(self.requests)
        manifest["retries"] = self._previous["retries"] + int(self.retries)
        self.store.save_manifest()

    # -- one request with backoff

    def request(self, url: str, stream: str) -> list:
        delay = float(self.cfg.backoff_first_s)
        last_reason = ""
        for attempt in range(1, int(self.cfg.max_attempts) + 1):
            if self.requests >= int(self.cfg.max_requests):
                self.budget_exhausted = True
                raise BudgetExhausted(f"max_requests={self.cfg.max_requests} reached")
            self.limiters[stream].acquire()
            self.requests += 1
            reason = ""
            retry_after = None
            try:
                response = self.client.get(url)
            except TransientError as error:
                reason = f"network:{error}"
            else:
                retry_after = getattr(response, "retry_after_s", None)
                code, msg, data, body_error = None, "", None, ""
                try:
                    code, msg, data = parse_body(response.body)
                except MalformedPage as error:
                    body_error = f"malformed_page:{error}"
                except TransientError as error:
                    body_error = str(error)
                if response.status == 200:
                    if body_error:
                        reason = body_error
                    elif code == "0":
                        return data
                    elif code in RETRY_CODES:
                        reason = f"code_{code}"
                    else:
                        raise FatalStreamError(f"code_{code}:{msg}")
                elif code in RETRY_CODES:
                    reason = f"http_{response.status}:code_{code}"  # e.g. 50004 is served with HTTP 400
                elif response.status == 429 or response.status >= 500:
                    reason = f"http_{response.status}"
                else:
                    raise FatalStreamError(f"http_{response.status}" + (f":code_{code}:{msg}" if code else ""))
            self.retries += 1
            last_reason = reason
            wait = delay if retry_after is None else max(delay, min(float(retry_after), RETRY_AFTER_CAP_S))
            self.journal.log("WARN", "retry", stream=stream, attempt=attempt, reason=reason, delay_s=f"{wait:.1f}", retry_after_s=retry_after)
            self.sleep(wait)
            delay = min(delay * 2.0, float(self.cfg.backoff_max_s))
        raise FatalStreamError("malformed_page" if last_reason.startswith("malformed_page") else "max_attempts")

    # -- one job

    def _end(self, job: Job, state: dict, end_reason: str, **fields) -> None:
        reason = end_reason
        state["end_reason"] = reason
        if reason in COMPLETE_REASONS:
            state["complete"] = True
            if reason == "reached_start":
                state["coverage_start_us"] = int(state["start_us"])
                state["truncated"] = False
            else:
                oldest = state.get("oldest_ts_us")
                state["coverage_start_us"] = None if oldest is None else int(oldest)
                state["truncated"] = oldest is None or int(oldest) > int(state["start_us"])
            if STREAMS[job.stream].kind == "funding":
                # D_cov (LP-G5 §4.4): the first settlement whose predecessor is also served.
                second = state.get("second_oldest_ts_us")
                state["d_cov_start_us"] = None if second is None else int(second)
        self.journal.log("INFO" if reason in ("reached_start", "server_empty_before_start", "paused") else "ERROR", "job_end", key=job.key, end_reason=reason, rows=state["rows"],
                         coverage_start=tape.iso_utc(int(state["coverage_start_us"])) if state.get("coverage_start_us") is not None else None, truncated=state.get("truncated"), **fields)

    def run_job(self, job: Job) -> dict:
        state = self.state_of(job)
        if state.get("complete"):
            self.journal.log("INFO", "job_skipped", key=job.key, reason="complete", end_reason=state.get("end_reason"))
            return state
        stream = STREAMS[job.stream]
        limit = self.cfg.limit_for(job.stream)
        after_ms = int(state["cursor_after_ms"])
        pages_this_session = 0
        state["end_reason"] = None
        self.journal.log("INFO", "job_start", key=job.key, after_ms=after_ms, start=tape.iso_utc(int(state["start_us"])), limit=limit)
        try:
            while True:
                if self.cfg.max_pages_per_job is not None and pages_this_session >= int(self.cfg.max_pages_per_job):
                    self._end(job, state, "paused", cursor_after_ms=after_ms)
                    break
                url = build_url(self.cfg.host, stream, job.request_inst_id, after_ms, limit)
                data = self.request(url, job.stream)
                fetched_us = int(self.now_us())
                rows, skipped = rows_from_page(stream, data, fetched_us)
                pages_this_session += 1
                state["pages"] = int(state["pages"]) + 1
                state["skipped_records"] = int(state["skipped_records"]) + skipped
                if not rows:
                    if data:  # records were served but none is usable: a malformed page, never the end of the history
                        state["errors"].append({"at": tape.iso_now(), "error": "malformed_page", "detail": f"{skipped} unusable records", "after_ms": after_ms})
                        self._end(job, state, "malformed_page", after_ms=after_ms)
                    else:
                        self._end(job, state, "server_empty_before_start", after_ms=after_ms)
                    break
                oldest_us = min(ts for ts, _ in rows)
                if oldest_us // 1000 >= after_ms:
                    state["errors"].append({"at": tape.iso_now(), "error": "pagination_not_advancing", "after_ms": after_ms, "oldest_ms": oldest_us // 1000})
                    self._end(job, state, "error", detail="pagination_not_advancing", after_ms=after_ms)
                    break
                for ts_us, row in rows:
                    if not self.guard.admits(ts_us):
                        state["rejected_at_or_after_end"] = int(state["rejected_at_or_after_end"]) + 1
                        continue
                    if ts_us < int(state["start_us"]):
                        state["dropped_before_start"] = int(state["dropped_before_start"]) + 1
                        continue
                    if stream.kind in ("candles", "px_candles") and int(row[-1]) == 0:
                        state["unconfirmed_rows"] = int(state["unconfirmed_rows"]) + 1  # stored; the evaluator drops and counts them
                    self.store.append(job.stream, job.inst_id, ts_us, row)
                    state["rows"] = int(state["rows"]) + 1
                    _track_two_oldest(state, ts_us)
                    state["newest_ts_us"] = ts_us if state["newest_ts_us"] is None else max(int(state["newest_ts_us"]), ts_us)
                after_ms = oldest_us // 1000
                state["cursor_after_ms"] = after_ms
                if oldest_us <= int(state["start_us"]):
                    self._end(job, state, "reached_start")
                    break
                if pages_this_session % max(1, int(self.cfg.checkpoint_every_pages)) == 0:
                    self.store.checkpoint()
                    self._save()
        except FatalStreamError as error:
            state["errors"].append({"at": tape.iso_now(), "error": str(error), "after_ms": after_ms})
            self._end(job, state, "malformed_page" if str(error) == "malformed_page" else "error", detail=str(error), after_ms=after_ms)
        except BudgetExhausted as error:
            state["errors"].append({"at": tape.iso_now(), "error": "max_requests_reached", "detail": str(error), "after_ms": after_ms})
            self._end(job, state, "max_requests_reached", after_ms=after_ms)
        state["updated_at"] = tape.iso_now()
        self.store.checkpoint()
        self._save()
        return state

    def jobs(self) -> list[Job]:
        return jobs_for(self.cfg.instruments, self.cfg.streams, self.cfg.start, self.cfg.eligibility_days, self.cfg.coverage_start_us)

    def run(self, force: bool = False) -> dict:
        jobs = self.jobs()
        self.journal.log("INFO", "fetch_start", jobs=len(jobs), host=xc.resolve_rest(self.cfg.host), end_exclusive=END_EXCLUSIVE, decision_ref=self.cfg.decision_ref,
                         plan_sha256=self.cfg.plan_sha256, max_requests=self.cfg.max_requests, mode="sealed" if self.cfg.sealed else "values")
        done = 0
        try:
            for job in jobs:
                if self.budget_exhausted:
                    break
                if force:  # re-walk from END_EXCLUSIVE (rows are de-duplicated at finalization); counters stay cumulative
                    state = self.state_of(job)
                    state.update({"complete": False, "cursor_after_ms": END_EXCLUSIVE_MS, "end_reason": None, "coverage_start_us": None, "truncated": None})
                    state["forced"] = int(state.get("forced", 0)) + 1
                self.run_job(job)
                done += 1
            # Every row is before END_EXCLUSIVE, so every day is strictly before its UTC day: finalize them all (force: the
            # 1 h re-finalization throttle is for a live capture; here a resumed day must be merged now).
            finalized = self.store.finalize_days_before(tape.day_of_us(END_EXCLUSIVE_US), force=True)
            self.journal.log("INFO", "finalized", days=len(finalized))
        finally:
            self.store.close()
            self.store.manifest["sessions"][-1].update({"ended_at": tape.iso_now(), "jobs_run": done, "requests": self.requests, "retries": self.retries,
                                                         "rejected_at_or_after_end": self.guard.rejected, "budget_exhausted": self.budget_exhausted})
            self._save()
            self.journal.log("INFO", "fetch_end", jobs_run=done, requests=self.requests, retries=self.retries, rejected_at_or_after_end=self.guard.rejected,
                             budget_exhausted=self.budget_exhausted, waits={name: lim.waits for name, lim in self.limiters.items() if lim.waits})
            try:
                if self.cfg.sealed:
                    self.receipt = self._write_receipt(jobs)
            finally:
                self.lock.release()
        return self.summary()

    # -- sealed mode

    def sealed_totals(self, jobs: list[Job]) -> dict:
        """The only numbers a sealed run shows (`SEALED_PRINTED`): jobs, complete jobs, jobs in error (planned jobs that did not
        end complete) and the global D_cov (`SEALED_COVERAGE_RULE`)."""
        states = [self.states.get(job.key) or {} for job in jobs]
        complete = [s for s in states if s.get("complete") and s.get("end_reason") in COMPLETE_REASONS]
        truncated = [int(s["d_cov_start_us"]) for s in complete if s.get("end_reason") == "server_empty_before_start" and s.get("d_cov_start_us") is not None]
        every = [int(s["d_cov_start_us"]) for s in complete if s.get("d_cov_start_us") is not None]
        coverage = max(truncated) if truncated else (min(every) if every else None)
        return {"jobs_total": len(jobs), "jobs_complete": len(complete), "jobs_error": len(jobs) - len(complete),
                "coverage_start_utc": tape.iso_utc(coverage) if coverage is not None else None, "coverage_start_us": coverage}

    def _write_receipt(self, jobs: list[Job]) -> dict:
        totals = self.sealed_totals(jobs)
        sealed = archive_sealed_store(self.out_dir)  # one hashed file, the tree removed (CTO m3)
        receipt = {"kind": RECEIPT_KIND, "generated_at": tape.iso_now(), "end_exclusive": END_EXCLUSIVE, "printed": list(SEALED_PRINTED), **totals,
                   "coverage_rule": SEALED_COVERAGE_RULE, "sealed_archive": sealed["sealed_archive"], "sealed_archive_sha256": sealed["sealed_archive_sha256"],
                   "archive_rule": SEALED_ARCHIVE_RULE, "sealed_manifest": f"{SEALED_ARCHIVE_NAME}:{tape.MANIFEST_NAME}",
                   "sealed_manifest_sha256": sealed["sealed_manifest_sha256"], "sealed_files_digest": sealed["sealed_files_digest"], "decision_ref": self.cfg.decision_ref,
                   "plan_sha256": self.cfg.plan_sha256, "inst_ids_sha256": self.cfg.inst_ids_sha256 or None, "max_requests": int(self.cfg.max_requests),
                   "spec": self.cfg.spec_info, "register_status": "unread",
                   "note": "sealed (LP-G5 P-G5-3): the per-instrument manifest is opened by no agent before the evaluator runs; this receipt carries global totals only"}
        path = self.out_dir / SEALED_RECEIPT_NAME
        tape.atomic_write_text(path, json.dumps(receipt, indent=1, sort_keys=True) + "\n")
        receipt["receipt_sha256"] = tape.sha256_of(path)
        self.journal.log("INFO", "sealed_end", **{k: totals[k] for k in SEALED_PRINTED}, receipt_sha256=receipt["receipt_sha256"],
                         sealed_manifest_sha256=receipt["sealed_manifest_sha256"], sealed_archive_sha256=receipt["sealed_archive_sha256"],
                         budget_exhausted=self.budget_exhausted)
        return receipt

    @property
    def ok(self) -> bool:
        states = list(self.states.values())
        return not self.budget_exhausted and not any(s.get("errors") for s in states)

    def summary(self) -> dict:
        if self.cfg.sealed:
            receipt = self.receipt or {"kind": RECEIPT_KIND, **self.sealed_totals(self.jobs())}
            return {**{k: receipt.get(k) for k in SEALED_PRINTED}, "mode": "sealed", "receipt": str(self.out_dir / SEALED_RECEIPT_NAME), "receipt_sha256": receipt.get("receipt_sha256"),
                    "sealed_manifest_sha256": receipt.get("sealed_manifest_sha256"), "sealed_files_digest": receipt.get("sealed_files_digest"),
                    "sealed_archive_sha256": receipt.get("sealed_archive_sha256")}
        states = list(self.states.values())
        reasons: dict[str, int] = {}
        for s in states:
            key = str(s.get("end_reason"))
            reasons[key] = reasons.get(key, 0) + 1
        return {"jobs": len(states), "complete": sum(1 for s in states if s.get("complete")), "rows": sum(int(s["rows"]) for s in states), "requests": self.requests, "retries": self.retries,
                "rejected_at_or_after_end": self.guard.rejected, "errors": sum(len(s.get("errors", [])) for s in states), "end_reasons": reasons,
                "truncated": sorted(k for k, s in self.states.items() if s.get("truncated")), "skipped_records": sum(int(s.get("skipped_records", 0)) for s in states),
                "budget_exhausted": self.budget_exhausted, "files_finalized": len(self.store.manifest.get("files", {}))}


def _track_two_oldest(state: dict, ts_us: int) -> None:
    """Keep the two oldest distinct market timestamps stored by a job (the second one starts D_cov for the funding history)."""
    oldest, second = state.get("oldest_ts_us"), state.get("second_oldest_ts_us")
    if oldest is None or ts_us < int(oldest):
        state["second_oldest_ts_us"] = None if oldest is None else int(oldest)
        state["oldest_ts_us"] = int(ts_us)
    elif ts_us != int(oldest) and (second is None or ts_us < int(second)):
        state["second_oldest_ts_us"] = int(ts_us)


class SealedJournal:
    """The journal of a sealed run: only the events of `SEALED_JOURNAL`, with their allowed fields (nothing per instrument);
    every other event is dropped and counted (`suppressed`)."""

    def __init__(self, journal: xc.HealthJournal) -> None:
        self.journal = journal
        self.suppressed = 0

    def log(self, level: str, event: str, **fields) -> str | None:
        allowed = SEALED_JOURNAL.get(event)
        if allowed is None:
            self.suppressed += 1
            return None
        return self.journal.log(level, event, **{k: v for k, v in fields.items() if k in allowed})


def read_receipt(path: Path) -> tuple[dict, str]:
    """The public receipt of a sealed funding fetch and its sha256 (`--funding-receipt`, post-freeze phase)."""
    data = Path(path).read_bytes()
    try:
        document = json.loads(data.decode("utf-8"))
    except (UnicodeDecodeError, ValueError) as error:
        raise SystemExit(f"receipt {path}: unreadable ({error})") from None
    if not isinstance(document, dict) or document.get("kind") != RECEIPT_KIND or document.get("end_exclusive") != END_EXCLUSIVE:
        raise SystemExit(f"receipt {path}: not a {RECEIPT_KIND} document for END_EXCLUSIVE {END_EXCLUSIVE}")
    return document, sha256_bytes(data)


# ----------------------------------------------------------------------------- command line


def add_parser(sub) -> None:
    parser = sub.add_parser("g5-fetch", help=f"REST 1-minute history of the X-Perps before {END_EXCLUSIVE} (constant) for G5 Gate 1; --dry-run lists the requests", allow_abbrev=False)
    parser.add_argument("--out", required=True)
    parser.add_argument("--instruments-file", required=True, help="OKX instruments snapshot (instType FUTURES; listTime and uly are read from it)")
    parser.add_argument("--inst-ids", default="", help="comma-separated instrument ids (subset of the file); a real run needs this or --inst-ids-file")
    parser.add_argument("--inst-ids-file", default="", help="file with one instrument id per line (its sha256 is recorded)")
    parser.add_argument("--family", choices=xc.FAMILIES, default="xperp")
    parser.add_argument("--include-not-live", action="store_true")
    parser.add_argument("--rule-type", default="xperp", help="keep rows whose ruleType equals this (default xperp: pre_market X-Perps are skipped); empty = no filter")
    parser.add_argument("--max-instruments", type=int, default=None, help="dry run only: truncate the selection")
    parser.add_argument("--host", default=DEFAULT_HOST, help="eea | global | https:// URL (default the EEA REST host; global needs --host-reason for a real run)")
    parser.add_argument("--host-reason", default="", help="written reason for a non-EEA host (recorded)")
    parser.add_argument("--streams", default="", help=f"comma-separated subset of {','.join(STREAMS)} (default: every stream; with --spec, the streams of the phase)")
    parser.add_argument("--spec", default="", help="the G5 machine spec (g5-spec/2; mandatory for a real run): streams, pages, starts, sealed mode and instruments come from its fetch_plan")
    parser.add_argument("--phase", choices=tuple(PHASES), default=None, help="pre_freeze (funding history, sealed) or post_freeze (candles, mark, index); with --spec")
    parser.add_argument("--funding-receipt", default="", help="post_freeze: the receipt of the sealed funding fetch (its coverage_start_utc is D_cov)")
    parser.add_argument("--limit", type=int, default=None, help="rows per page (default: 100 for the candles, 400 for the funding history; capped at each endpoint's documented maximum)")
    parser.add_argument("--start", default=DEFAULT_START, help=f"global floor (ISO UTC); each instrument starts at max(floor, listTime). There is no --end: {END_EXCLUSIVE} is a constant")
    parser.add_argument("--rate-scale", type=float, default=0.8, help="fraction of the documented rate limits used (0 < x <= 1)")
    parser.add_argument("--max-pages", type=int, default=None, help="pages per (instrument, stream) in this session; the next session resumes")
    parser.add_argument("--max-requests", type=int, default=None, help="hard cap on the requests of the session, retries included (mandatory for a real run)")
    parser.add_argument("--plan", default="", help="the dry-run plan (--plan-out) of exactly this configuration (mandatory for a real run; sha256 recorded)")
    parser.add_argument("--checkpoint-every-pages", type=int, default=20)
    parser.add_argument("--decision-ref", default="", help="the owner's written decision on the Market Data licence and its scale (mandatory for a real run; recorded)")
    parser.add_argument("--force", action="store_true", help="re-walk every job from END_EXCLUSIVE, including the complete ones (rows are de-duplicated; counters stay cumulative)")
    parser.add_argument("--dry-run", action="store_true", help="list the requests and estimates; connect to nothing; write nothing unless --plan-out")
    parser.add_argument("--plan-out", default="")
    parser.add_argument("--quiet", action="store_true")
    parser.set_defaults(func=command_fetch)


def _inst_ids(args: argparse.Namespace) -> tuple[list[str] | None, str]:
    if args.inst_ids and args.inst_ids_file:
        raise SystemExit("--inst-ids and --inst-ids-file are exclusive")
    if args.inst_ids_file:
        ids, digest = read_inst_ids_file(Path(args.inst_ids_file))
        return ids, f"file {args.inst_ids_file} sha256={digest}"
    ids = [s for s in args.inst_ids.split(",") if s]
    return (ids or None), (f"--inst-ids sha256={inst_ids_digest(ids)}" if ids else "")


def _spec_settings(args: argparse.Namespace, rows: list[dict], inst_ids: list[str] | None) -> dict:
    """The settings a G5 spec imposes on the fetch (`fetch_plan`, universe, eligibility): read by the strict parser of `g5_eval`
    (a DRAFT spec is accepted: the pre-freeze funding fetch happens before the freeze), checked against the instruments file."""
    from . import g5_eval  # lazy: g5_eval imports this module

    if args.phase is None:
        raise SystemExit("--spec needs --phase pre_freeze|post_freeze")
    try:
        spec, spec_sha = g5_eval.load_spec(Path(args.spec))
    except g5_eval.SpecError as error:
        raise SystemExit(f"spec error: {error}") from None
    if spec.source != "history" or spec.fetch_plan is None:
        raise SystemExit("the spec has no history fetch plan (source must be history)")
    plan_spec = spec.fetch_plan
    if xc.resolve_rest(args.host) != plan_spec["host"]:
        raise SystemExit(f"--host {args.host} differs from the spec's fetch_plan.host {plan_spec['host']}")
    universe = {i.inst_id: i for i in spec.universe}
    selected = {str(r["instId"]): r for r in rows}
    if inst_ids is None or sorted(inst_ids) != sorted(universe):
        raise SystemExit(f"the instruments of a G5 fetch are the spec's universe.analysed ({len(universe)}), exactly; got {len(inst_ids or [])}")
    for inst, meta in universe.items():
        row = selected.get(inst)
        if row is None or xc._i(row.get("listTime"), 0) * 1000 != meta.list_time_us or index_id_of(row) != meta.index_id:
            raise SystemExit(f"{inst}: the instruments file disagrees with the spec (listTime / uly) — mismatch_with_snapshot: refuse_run")
    streams = PHASES[args.phase]
    given = tuple(s for s in args.streams.split(",") if s)
    if given and given != streams:
        raise SystemExit(f"the {args.phase} phase fetches {','.join(streams)} (spec fetch_plan), not {','.join(given)}")
    limits = {name: int(block["page"]) for name, block in plan_spec["post_freeze"].items()}
    limits["funding_hist"] = STREAMS["funding_hist"].limit_max
    coverage, receipt_info = None, None
    if args.phase == "post_freeze":
        if not args.funding_receipt:
            raise SystemExit("the post_freeze phase needs --funding-receipt (the receipt of the sealed funding fetch: D_cov)")
        receipt, receipt_sha = read_receipt(Path(args.funding_receipt))
        if receipt.get("coverage_start_us") is None:
            raise SystemExit(f"receipt {args.funding_receipt}: no coverage_start (D_cov unknown): mark / index have no start")
        coverage = int(receipt["coverage_start_us"])
        receipt_info = {"path": str(args.funding_receipt), "sha256": receipt_sha, "coverage_start_utc": receipt.get("coverage_start_utc")}
    elif args.funding_receipt:
        raise SystemExit("--funding-receipt belongs to the post_freeze phase")
    return {"streams": streams, "sealed": args.phase == "pre_freeze", "eligibility_days": spec.eligibility_days, "coverage_start_us": coverage,
            "estimate_coverage_start_us": plan_spec["estimate_coverage_start_us"], "limits": limits, "funding_receipt": receipt_info,
            "spec_info": {"path": str(args.spec), "sha256": spec_sha, "spec_id": spec.spec_id, "status": spec.status, "dataset_id": spec.dataset_id, "phase": args.phase}}


def config_from_args(args: argparse.Namespace) -> FetchConfig:
    streams = tuple(s for s in args.streams.split(",") if s) or tuple(STREAMS)
    for stream in streams:
        if stream not in STREAMS:
            raise SystemExit(f"unknown stream {stream}; choose among {','.join(STREAMS)}")
    if tape.parse_iso_utc(args.start) >= END_EXCLUSIVE_US:
        raise SystemExit(f"--start {args.start} is not before END_EXCLUSIVE {END_EXCLUSIVE}")
    if not (0.0 < args.rate_scale <= 1.0):
        raise SystemExit("--rate-scale must be in (0, 1]")
    inst_ids, inst_ids_source = _inst_ids(args)
    rows = load_instruments(Path(args.instruments_file), inst_ids, args.family, only_live=not args.include_not_live, max_instruments=args.max_instruments, rule_type=args.rule_type or None)
    if not rows:
        raise SystemExit(f"no instrument selected from {args.instruments_file} (family {args.family})")
    settings: dict = {}
    if args.spec:
        if args.max_instruments is not None:
            raise SystemExit("--max-instruments truncates the spec's universe: refused with --spec")
        settings = _spec_settings(args, rows, inst_ids)
        streams = settings.pop("streams")
    elif args.phase is not None or args.funding_receipt:
        raise SystemExit("--phase and --funding-receipt need --spec")
    return FetchConfig(out_dir=Path(args.out), instruments=rows, host=args.host, streams=streams, limit=args.limit, start=args.start, rate_scale=args.rate_scale,
                       checkpoint_every_pages=args.checkpoint_every_pages, max_pages_per_job=args.max_pages, max_requests=args.max_requests, decision_ref=args.decision_ref,
                       host_reason=args.host_reason, inst_ids_source=inst_ids_source, inst_ids_sha256=inst_ids_digest(inst_ids) if inst_ids else "",
                       instruments_file=f"{args.instruments_file} sha256={tape.sha256_of(Path(args.instruments_file))}", snapshot=snapshot_info(Path(args.instruments_file)),
                       echo=not args.quiet, argv=list(sys.argv[1:]), **settings)


def _refuse(reason: str, hint: str) -> int:
    print(f"{tape.iso_now()} level=ERROR event=refused reason={reason} hint={json.dumps(hint)}", file=sys.stderr, flush=True)
    return 2


def command_fetch(args: argparse.Namespace, client_factory: Callable[[], object] | None = None) -> int:
    cfg = config_from_args(args)
    if args.dry_run:
        document = plan(cfg)
        if args.plan_out:
            path = Path(args.plan_out)
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(json.dumps(document, indent=1, sort_keys=True) + "\n")
        print(json.dumps(document, indent=1, sort_keys=True))
        return 0
    # A real run: every refusal happens here, before any client exists (no connection is ever opened by a refused run).
    if not cfg.decision_ref.strip():
        return _refuse("decision_ref_missing", "pass --decision-ref TEXT (the owner's written confirmation of the read and its scale, P-G5-1) or --dry-run")
    if not cfg.spec_info:
        return _refuse("spec_missing", "a real run is driven by the G5 spec: --spec FILE --phase pre_freeze|post_freeze (streams, starts, sealed mode, instruments)")
    if not cfg.inst_ids_source:
        return _refuse("inst_ids_missing", "a real run names its instruments: --inst-ids A,B or --inst-ids-file F (the default 'every instrument of the file' is for the dry run only)")
    if args.max_instruments is not None:
        return _refuse("max_instruments_in_real_run", "--max-instruments truncates silently; name the instruments with --inst-ids instead")
    if cfg.max_requests is None or cfg.max_requests <= 0:
        return _refuse("max_requests_missing", "pass --max-requests N (hard cap, retries included), at least the plan's requests_total")
    if not args.plan:
        return _refuse("plan_missing", "run the dry run with the same options and --plan-out FILE, then pass --plan FILE")
    if xc.resolve_rest(cfg.host) == xc.REST_GLOBAL and not cfg.host_reason.strip():
        return _refuse("host_global_without_reason", "the global host is outside the owner's EEA licence; pass --host-reason TEXT or use the default EEA host")
    try:
        _, cfg.plan_sha256 = check_plan(cfg, Path(args.plan), int(cfg.max_requests))
    except SystemExit as error:
        return _refuse("plan_rejected", str(error))
    client = (client_factory or UrllibClient)()
    try:
        fetcher = Fetcher(cfg, client)
    except tape.LockHeld as error:
        print(f"{tape.iso_now()} level=ERROR event=refused reason=lock_held detail={json.dumps(str(error))}", file=sys.stderr, flush=True)
        return 3
    summary = fetcher.run(force=bool(args.force))
    print(json.dumps(summary, sort_keys=True))
    return 0 if fetcher.ok else 1
