"""REST fetcher of the 1-minute **index** candles of the G5 **Gate 2** period: P-G5-10 (ii) option (b), owner decision D10
(2026-10-21, `LP-OWNER-DECISIONS-2026-10-21.md`: « On essaye des deux un par un puis on decide » — (b) `history-index-candles`
« est récupéré une fois après la fin de la fenêtre du Gate 2 »).

    python -m scripts.research_lp.cli g5-index-fetch --out DIR --spec GATE2_SPEC --expect-spec-sha256 H [--gate1-spec G1] \\
        --index-ids BTC-USD,ETH-USD --dry-run
    python -m scripts.research_lp.cli g5-index-fetch --out DIR --spec GATE2_SPEC --expect-spec-sha256 H [--gate1-spec G1] \\
        --index-ids-file IDS --max-requests N --decision-ref "<the owner's dated text>"

One stream, `GET /api/v5/market/history-index-candles` (`bar = 1m`, `instId` = the index id = `uly` of the X-Perps of the spec's
universe), stored in the `index_1m` layout of `tape` (keyed by the index id) — the layout `g5_eval.load_capture_series` reads for
option (b) and for D10 (`--index-tape`, its `index_1m/` digest = `--expect-index-tape-sha256`). Everything else is the machinery of
`g5_fetch`, reused, not copied: the `index_1m` stream (endpoint, page ≤ 100, 10 req / 2 s per IP), the rate limiter at `rate_scale`
of the documented ceiling, the request loop with its backoff, retry codes, fatal errors and request budget (`g5_fetch.Fetcher.request`,
bound here as a method), `parse_body` / `rows_from_page`, `build_url` (pagination towards the past with `after`), the hashed id list
(`inst_ids_digest`), the day store and its lock (`tape.TapeStore`, `tape.DirectoryLock`), the journal (`xperp_capture.HealthJournal`)
and `directory_digest`. **`g5_fetch` is not touched**: its `END_EXCLUSIVE` (2026-10-14) and `EndGuard` stay the Gate 1 bound; this
module has its own guard, `WindowGuard`, bounded by the frozen Gate 2 spec.

**Guards (fail-closed; every refusal of the command happens before any client exists, exit 2).**

- `--spec` + `--expect-spec-sha256`: the sha256 of the file's bytes must equal the given one (compared before parsing), and the spec,
  read by the strict reader (`g5_eval.load_spec` → `g5_gate2.parse_capture_spec`, against `--gate1-spec` or its `frozen_with`), must be
  a Gate 2 capture spec with `status: FROZEN` whose `classifier_index_source.choice` needs option (b): `b_history_index_candles_after_window`
  or `d10_both_primary_by_quality_rule`. Dry runs included (the window comes from the frozen T0).
- **Window** (`IndexWindow`, from the frozen spec only; no `--start`, no `--end`): `read_from = max(QUARANTINE_FLOOR,
  first t_arm − lookback − margin)` where the first t_arm that can be scored is T0 + min over the types with a classifier of
  (`arm_min` − `footprint_min[0]`) minutes (an instant is in the window iff its whole footprint is in [T0 ; end)), `lookback` the largest
  `classifier.lookback_min` (480) and `margin` = `LOOKBACK_MARGIN_MIN` (60 min, declared); `end` = the spec's grid end (`spec.end_us`:
  the latest type window end with the data-gap extension the chosen option allows — 2027-04-01 under D7). `IndexWindow.check` refuses a
  start inside the quarantine [2026-10-14 ; 2026-11-02) or before it, a start before T0's own lookback (`first t_arm − lookback − margin`),
  an end not after the start; `WindowGuard.admits` drops (and counts) every row whose candle opening time `ts_us` is outside
  [read_from ; end), before it reaches the store.
- **After the window only**: a real run is refused while the current UTC time is not strictly after `end` (`window_not_ended`).
- **Ids**: `--index-ids` or `--index-ids-file` (one per line, file sha256 recorded) mandatory for a real run, equal to the set of the
  index ids of `universe.analysed` (derived by the reader: `index_id = uly`), nothing more, nothing less; the sha256 of the sorted list
  (`g5_fetch.inst_ids_digest`) is in the plan, the manifest and the receipt.
- `--decision-ref TEXT` (the owner's dated text: D10) and `--max-requests N` mandatory for a real run; the plan (one job per index id,
  pages = minutes / page size) is computed from the frozen window and the run is refused when its estimate exceeds `--max-requests`;
  `--max-requests` also caps every request sent, retries included (`BudgetExhausted`).
- `--host global` needs `--host-reason` (outside the owner's EEA licence), as in `g5_fetch`.

**Output.** `<out>/index_1m/<index_id>/<index_id>-<date>.npz` (finalized days, sha256 in the manifest), `<out>/manifest.json` (kind
`MANIFEST_KIND`, `not_before` = read_from, per-index states: cursor, pages, rows, rejected outside the window, `end_reason`,
`coverage_start`), `<out>/index-fetch.log`, and `<out>/index-fetch-receipt.json`: spec sha256, window, decision_ref, ids sha256, totals
and `index_tape_sha256` = `g5_fetch.directory_digest(<out>/index_1m)`, the value the register records and `g5-eval
--expect-index-tape-sha256` checks (`g5_gate2.check_index_tape`).

Network access happens only in `g5_fetch.UrllibClient.get`; the tests drive `IndexFetcher` with a fake client and a socket guard.
"""

from __future__ import annotations

import argparse
import json
import math
import sys
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Callable

from . import g5_fetch, tape
from . import xperp_capture as xc

LAYOUT = "index_1m"
STREAM = g5_fetch.STREAMS[LAYOUT]
MANIFEST_KIND = "lp-g5-gate2-index-1m-history"
PLAN_KIND = "lp-g5-gate2-index-fetch-plan"
RECEIPT_KIND = "lp-g5-gate2-index-fetch-receipt"
RECEIPT_NAME = "index-fetch-receipt.json"
JOURNAL_NAME = "index-fetch.log"
MINUTE_US = 60 * tape.SECOND_US
LOOKBACK_MARGIN_MIN = 60  # declared margin before the first scored t_arm's 480-minute lookback (protocol 10.1, D10)
QUARANTINE_START_US = g5_fetch.END_EXCLUSIVE_US  # 2026-10-14T00:00:00Z
QUARANTINE_END_US = xc.quarantine_floor_us()  # 2026-11-02T00:00:00Z
# The classifier_index_source choices that read option (b) (g5_gate2.INDEX_OPTION_B, g5_gate2.INDEX_CHOICE_D10; checked equal in the tests).
CHOICES_NEEDING_B = ("b_history_index_candles_after_window", "d10_both_primary_by_quality_rule")
END_REASONS = ("reached_start", "server_empty_before_start", "malformed_page", "error", "paused", "max_requests_reached")
COMPLETE_REASONS = ("reached_start", "server_empty_before_start")


def _iso(ts_us: int) -> str:
    """ISO UTC without the microseconds when they are zero (the spec's and the selection record's form)."""
    text = tape.iso_utc(int(ts_us))
    return text.replace(".000000Z", "Z")


class RefusedRun(ValueError):
    """A guard refused the run (exit 2, nothing fetched); `reason` is the short code printed."""

    def __init__(self, reason: str, detail: str) -> None:
        super().__init__(f"{reason}: {detail}")
        self.reason = reason
        self.detail = detail


# ----------------------------------------------------------------------------- the frozen window


@dataclass(frozen=True)
class IndexWindow:
    t0_us: int
    first_t_arm_us: int
    lookback_min: int
    margin_min: int
    read_from_us: int  # inclusive
    end_us: int  # exclusive

    @property
    def lookback_floor_us(self) -> int:
        """The oldest instant any scored instant may need: first t_arm − lookback − margin."""
        return int(self.first_t_arm_us) - (int(self.lookback_min) + int(self.margin_min)) * MINUTE_US

    def check(self) -> "IndexWindow":
        if self.t0_us < QUARANTINE_END_US:
            raise RefusedRun("t0_before_quarantine_floor", f"T0 {_iso(self.t0_us)} is before {xc.QUARANTINE_FLOOR}")
        if QUARANTINE_START_US <= self.read_from_us < QUARANTINE_END_US:
            raise RefusedRun("start_in_quarantine", f"start {_iso(self.read_from_us)} inside the quarantine [{g5_fetch.END_EXCLUSIVE} ; {xc.QUARANTINE_FLOOR})")
        if self.read_from_us < QUARANTINE_END_US:
            raise RefusedRun("start_before_quarantine_floor", f"start {_iso(self.read_from_us)} before {xc.QUARANTINE_FLOOR}")
        if self.read_from_us < self.lookback_floor_us:
            raise RefusedRun("start_before_t0_lookback", f"start {_iso(self.read_from_us)} before the first t_arm {_iso(self.first_t_arm_us)} "
                                                         f"- {self.lookback_min} min - {self.margin_min} min (T0 {_iso(self.t0_us)})")
        if self.read_from_us % MINUTE_US:
            raise RefusedRun("start_not_on_a_minute", f"start {_iso(self.read_from_us)}")
        if self.end_us <= self.read_from_us:
            raise RefusedRun("empty_window", f"end {_iso(self.end_us)} is not after the start {_iso(self.read_from_us)}")
        return self

    def as_dict(self) -> dict:
        return {"t0": _iso(self.t0_us), "first_t_arm": _iso(self.first_t_arm_us), "lookback_min": int(self.lookback_min),
                "margin_min": int(self.margin_min), "read_from": _iso(self.read_from_us), "end_exclusive": _iso(self.end_us),
                "rule": "read_from = max(quarantine floor, T0 + min(arm_min - footprint_min[0]) - max(classifier.lookback_min) - margin) ; end = spec grid end"}


def window_of(spec, margin_min: int = LOOKBACK_MARGIN_MIN) -> IndexWindow:
    """The window of the index read, from a parsed Gate 2 spec (`g5_eval.Spec`, source capture) and nothing else."""
    if getattr(spec, "source", None) != "capture" or not getattr(spec, "gate2", None):
        raise RefusedRun("not_a_gate2_spec", "the index fetcher reads the period of a Gate 2 (capture) spec only")
    if spec.gate2.get("t0_provisional"):
        raise RefusedRun("t0_provisional", "freeze_fill.t0_utc is not set: the window is not frozen")
    with_classifier = [t for t in spec.types if t.classifier is not None]
    if not with_classifier:
        raise RefusedRun("no_classifier", "no type of the spec reads the index (no classifier)")
    t0_us = int(spec.gate2["t0_us"])
    first_t_arm_us = t0_us + min(int(t.arm_min) - int(t.footprint[0]) for t in with_classifier) * MINUTE_US
    lookback = max(int(t.classifier["lookback_min"]) for t in with_classifier)
    read_from = max(QUARANTINE_END_US, first_t_arm_us - (lookback + int(margin_min)) * MINUTE_US)
    read_from -= read_from % MINUTE_US
    return IndexWindow(t0_us, first_t_arm_us, lookback, int(margin_min), int(read_from), int(spec.end_us)).check()


class WindowGuard:
    """Drops every row whose market timestamp (candle opening time) is outside [read_from ; end) before it reaches the store; counts both sides."""

    __slots__ = ("read_from_us", "end_us", "rejected_before_start", "rejected_at_or_after_end")

    def __init__(self, window: IndexWindow) -> None:
        self.read_from_us, self.end_us = int(window.read_from_us), int(window.end_us)
        self.rejected_before_start = 0
        self.rejected_at_or_after_end = 0

    def admits(self, ts_us: int) -> bool:
        ts_us = int(ts_us)
        if ts_us >= self.end_us:
            self.rejected_at_or_after_end += 1
            return False
        if ts_us < self.read_from_us:
            self.rejected_before_start += 1
            return False
        return True


# ----------------------------------------------------------------------------- spec, ids, plan


def load_frozen_spec(path: Path, expected_sha256: str, gate1=None) -> tuple[object, str]:
    """The FROZEN Gate 2 spec whose sha256 is `expected_sha256` (compared on the file's bytes before parsing)."""
    from . import g5_eval  # lazy: g5_eval imports g5_fetch, not this module

    path = Path(path)
    if not expected_sha256 or not g5_fetch._SHA256_RE.match(expected_sha256.lower()):
        raise RefusedRun("expect_spec_sha256_missing", "pass --expect-spec-sha256 HEX (the sha256 of the FROZEN Gate 2 spec, protocol 13)")
    if not path.is_file():
        raise RefusedRun("spec_missing", f"{path}: no such file")
    sha = g5_fetch.sha256_bytes(path.read_bytes())
    if sha != expected_sha256.lower():
        raise RefusedRun("spec_sha256_mismatch", f"{path}: sha256 {sha} differs from --expect-spec-sha256 {expected_sha256}")
    try:
        spec, read_sha = g5_eval.load_spec(path, gate1)
    except g5_eval.SpecError as error:
        raise RefusedRun("spec_error", str(error)) from None
    if read_sha != sha:
        raise RefusedRun("spec_changed_while_read", f"{path}: the file changed while it was read")
    if spec.source != "capture" or spec.gate2 is None:
        raise RefusedRun("not_a_gate2_spec", f"{path}: source {spec.source!r}, not the Gate 2 capture spec")
    if spec.status != "FROZEN":
        raise RefusedRun("spec_not_frozen", f"{path}: status {spec.status!r}; the index read of the Gate 2 period needs the FROZEN spec")
    choice = spec.gate2["index_choice"]
    if choice not in CHOICES_NEEDING_B:
        raise RefusedRun("index_choice_without_b", f"classifier_index_source.choice {choice!r} does not read option (b)")
    return spec, sha


def index_ids_of(spec) -> list[str]:
    """The distinct index ids of `universe.analysed` (the reader checks index_id = uly of each X-Perp)."""
    return sorted({tape.check_inst_name(str(i.index_id)) for i in spec.universe})


def read_index_ids(text: str, path: str) -> tuple[list[str] | None, str]:
    if text and path:
        raise RefusedRun("index_ids_twice", "--index-ids and --index-ids-file are exclusive")
    if path:
        ids, digest = g5_fetch.read_inst_ids_file(Path(path))
        return ids, f"file {path} sha256={digest}"
    ids = [s.strip() for s in text.split(",") if s.strip()]
    if len(set(ids)) != len(ids):
        raise RefusedRun("index_ids_duplicate", "--index-ids lists an id twice")
    return (ids or None), (f"--index-ids sha256={g5_fetch.inst_ids_digest(ids)}" if ids else "")


def check_index_ids(given: list[str] | None, spec) -> list[str]:
    expected = index_ids_of(spec)
    if given is None:
        raise RefusedRun("index_ids_missing", f"a real run names its index ids (--index-ids or --index-ids-file): the {len(expected)} of the spec's universe")
    if sorted(given) != expected:
        raise RefusedRun("index_ids_differ_from_spec", f"the ids are the index ids of universe.analysed, exactly ({len(expected)}); got {sorted(given)}")
    return expected


def estimate_pages(window: IndexWindow, limit: int) -> int:
    """Pages of one index walk from `end` back to `read_from` (one row per minute; the last page reaches the start, no empty page)."""
    minutes = math.ceil((window.end_us - window.read_from_us) / MINUTE_US)
    return max(1, math.ceil(minutes / int(limit)))


@dataclass
class IndexFetchConfig:
    out_dir: Path
    spec: object
    spec_sha256: str
    spec_path: str
    window: IndexWindow
    index_ids: list[str]
    index_ids_source: str = ""
    host: str = g5_fetch.DEFAULT_HOST
    host_reason: str = ""
    limit: int = STREAM.limit_max
    rate_scale: float = 0.8
    max_attempts: int = 8
    backoff_first_s: float = 2.0
    backoff_max_s: float = 60.0
    checkpoint_every_pages: int = 20
    max_requests: int | None = None
    decision_ref: str = ""
    echo: bool = True
    argv: list[str] | None = field(default_factory=list)

    @property
    def index_ids_sha256(self) -> str:
        return g5_fetch.inst_ids_digest(self.index_ids)

    def spec_info(self) -> dict:
        return {"path": self.spec_path, "sha256": self.spec_sha256, "spec_id": self.spec.spec_id, "status": self.spec.status, "dataset_id": self.spec.dataset_id,
                "index_choice": self.spec.gate2["index_choice"]}


def plan(cfg: IndexFetchConfig) -> dict:
    """The plan of the read (opens no connection, writes nothing): one job per index id, its first request and its page estimate."""
    limit = max(1, min(int(cfg.limit), STREAM.limit_max))
    pages = estimate_pages(cfg.window, limit)
    limiter = g5_fetch.RateLimiter(STREAM.rate_requests, STREAM.rate_window_s, cfg.rate_scale)
    jobs = [{"index_id": i, "stream": LAYOUT, "start": _iso(cfg.window.read_from_us), "end_exclusive": _iso(cfg.window.end_us), "limit": limit,
             "first_url": g5_fetch.build_url(cfg.host, STREAM, i, cfg.window.end_us // 1000, limit), "estimated_pages": pages} for i in cfg.index_ids]
    total = pages * len(jobs)
    return {"kind": PLAN_KIND, "host": xc.resolve_rest(cfg.host), "endpoint": STREAM.path, "rate_limit": f"{STREAM.rate_requests} req / {STREAM.rate_window_s:g} s ({STREAM.rate_rule})",
            "rate_scale": cfg.rate_scale, "used_capacity": limiter.capacity, "spec": cfg.spec_info(), "window": cfg.window.as_dict(), "index_ids": list(cfg.index_ids),
            "index_ids_sha256": cfg.index_ids_sha256, "jobs": jobs, "requests_total": total, "min_duration_s": limiter.min_duration_s(total),
            "note": "estimate assuming the server holds every minute of the window; retries not included; --max-requests caps every request sent"}


# ----------------------------------------------------------------------------- fetcher


class IndexFetcher:
    """Runs the index jobs against an injected client (`client.get(url) → g5_fetch.HttpResponse`); `sleep`, `clock` and `now_us` are injectable."""

    # The request loop of g5_fetch (rate limiter, budget, backoff, retry codes, fatal errors), reused as a method: it reads self.cfg
    # (max_requests, max_attempts, backoff_first_s, backoff_max_s), self.limiters, self.client, self.sleep, self.journal and keeps
    # self.requests, self.retries and self.budget_exhausted.
    request = g5_fetch.Fetcher.request

    def __init__(self, cfg: IndexFetchConfig, client, sleep: Callable[[float], None] = time.sleep, clock: Callable[[], float] = time.monotonic,
                 now_us: Callable[[], int] = tape.utc_now_us) -> None:
        if not cfg.decision_ref.strip():
            raise RefusedRun("decision_ref_missing", "a real index read needs decision_ref (the owner's dated text, D10)")
        if cfg.max_requests is None or int(cfg.max_requests) <= 0:
            raise RefusedRun("max_requests_missing", "a real index read needs max_requests > 0 (a hard cap on every request, retries included)")
        if xc.resolve_rest(cfg.host) == xc.REST_GLOBAL and not cfg.host_reason.strip():
            raise RefusedRun("host_global_without_reason", "the global host is outside the owner's EEA licence")
        if not (0.0 < cfg.rate_scale <= 1.0):
            raise RefusedRun("rate_scale", f"rate_scale must be in (0, 1], got {cfg.rate_scale}")
        if getattr(cfg.spec, "status", None) != "FROZEN" or cfg.spec.gate2["index_choice"] not in CHOICES_NEEDING_B:
            raise RefusedRun("spec_not_frozen", "the index read of the Gate 2 period needs the FROZEN spec choosing option (b) or D10")
        cfg.window.check()
        if window_of(cfg.spec, cfg.window.margin_min) != cfg.window:
            raise RefusedRun("window_differs_from_spec", "the window is not the frozen spec's")
        check_index_ids(cfg.index_ids, cfg.spec)
        now = int(now_us())
        if now <= cfg.window.end_us:
            raise RefusedRun("window_not_ended", f"now {_iso(now)} is not after the end of the Gate 2 window {_iso(cfg.window.end_us)} (D10: once, after the window)")
        expected = plan(cfg)
        if int(expected["requests_total"]) > int(cfg.max_requests):
            raise RefusedRun("plan_exceeds_max_requests", f"the plan needs {expected['requests_total']} requests, more than --max-requests {cfg.max_requests}")
        self.cfg = cfg
        self.client = client
        self.sleep = sleep
        self.now_us = now_us
        self.plan = expected
        self.out_dir = Path(cfg.out_dir)
        self.guard = WindowGuard(cfg.window)
        self.limiters = {LAYOUT: g5_fetch.RateLimiter(STREAM.rate_requests, STREAM.rate_window_s, cfg.rate_scale, clock, sleep)}
        self.out_dir.mkdir(parents=True, exist_ok=True)
        self.lock = tape.DirectoryLock(self.out_dir).acquire()
        self.receipt: dict | None = None
        try:
            self.journal = xc.HealthJournal(self.out_dir / JOURNAL_NAME, echo=cfg.echo)
            source = {"rest": xc.resolve_rest(cfg.host), "endpoint": STREAM.path, "layout": LAYOUT, "window": cfg.window.as_dict(), "spec": cfg.spec_info(),
                      "decision_ref": cfg.decision_ref, "max_requests": int(cfg.max_requests), "host_reason": cfg.host_reason or None,
                      "index_ids_source": cfg.index_ids_source or None, "index_ids_sha256": cfg.index_ids_sha256, "rate_scale": cfg.rate_scale}
            self.store = tape.TapeStore(self.out_dir, "values", source=source, not_before=_iso(cfg.window.read_from_us), kind=MANIFEST_KIND)
            manifest = self.store.manifest
            if manifest.get("kind") != MANIFEST_KIND:
                raise RefusedRun("other_tape", f"{self.out_dir}: holds a {manifest.get('kind')!r} tape")
            recorded = (manifest.get("source") or {})
            if (recorded.get("spec") or {}).get("sha256") != cfg.spec_sha256 or recorded.get("window") != cfg.window.as_dict():
                raise RefusedRun("other_spec_or_window", f"{self.out_dir}: started for another spec or window; use a new directory")
        except Exception:
            self.lock.release()
            raise
        self.states: dict = manifest.setdefault("g5_index_streams", {})
        manifest.setdefault("sessions", []).append({"started_at": tape.iso_now(), "argv": list(cfg.argv or []), "decision_ref": cfg.decision_ref,
                                                    "max_requests": int(cfg.max_requests), "host": xc.resolve_rest(cfg.host), "plan_requests_total": expected["requests_total"]})
        self._previous = {key: int(manifest.get(key, 0)) for key in ("requests", "retries", "rejected_before_start", "rejected_at_or_after_end")}
        self.requests = 0
        self.retries = 0
        self.budget_exhausted = False

    # -- bookkeeping

    def _defaults(self, index_id: str) -> dict:
        return {"index_id": index_id, "start_us": int(self.cfg.window.read_from_us), "end_us": int(self.cfg.window.end_us), "cursor_after_ms": int(self.cfg.window.end_us // 1000),
                "pages": 0, "rows": 0, "rejected_before_start": 0, "rejected_at_or_after_end": 0, "skipped_records": 0, "unconfirmed_rows": 0, "oldest_ts_us": None,
                "newest_ts_us": None, "complete": False, "end_reason": None, "coverage_start_us": None, "truncated": None, "errors": []}

    def state_of(self, index_id: str) -> dict:
        state = self.states.setdefault(index_id, self._defaults(index_id))
        for key, value in self._defaults(index_id).items():
            state.setdefault(key, value)
        return state

    def _save(self) -> None:
        manifest = self.store.manifest
        manifest["requests"] = self._previous["requests"] + int(self.requests)
        manifest["retries"] = self._previous["retries"] + int(self.retries)
        manifest["rejected_before_start"] = self._previous["rejected_before_start"] + int(self.guard.rejected_before_start)
        manifest["rejected_at_or_after_end"] = self._previous["rejected_at_or_after_end"] + int(self.guard.rejected_at_or_after_end)
        self.store.save_manifest()

    def _end(self, state: dict, reason: str, **fields) -> None:
        state["end_reason"] = reason
        if reason in COMPLETE_REASONS:
            state["complete"] = True
            if reason == "reached_start":
                state["coverage_start_us"], state["truncated"] = int(state["start_us"]), False
            else:
                oldest = state.get("oldest_ts_us")
                state["coverage_start_us"] = None if oldest is None else int(oldest)
                state["truncated"] = oldest is None or int(oldest) > int(state["start_us"])
        self.journal.log("INFO" if reason in ("reached_start", "server_empty_before_start", "paused") else "ERROR", "job_end", index_id=state["index_id"], end_reason=reason,
                         rows=state["rows"], truncated=state.get("truncated"), **fields)

    # -- one index

    def run_job(self, index_id: str) -> dict:
        state = self.state_of(index_id)
        if state.get("complete"):
            self.journal.log("INFO", "job_skipped", index_id=index_id, end_reason=state.get("end_reason"))
            return state
        limit = max(1, min(int(self.cfg.limit), STREAM.limit_max))
        after_ms = int(state["cursor_after_ms"])
        pages = 0
        state["end_reason"] = None
        self.journal.log("INFO", "job_start", index_id=index_id, after_ms=after_ms, start=_iso(int(state["start_us"])), limit=limit)
        try:
            while True:
                data = self.request(g5_fetch.build_url(self.cfg.host, STREAM, index_id, after_ms, limit), LAYOUT)
                rows, skipped = g5_fetch.rows_from_page(STREAM, data, int(self.now_us()))
                pages += 1
                state["pages"] = int(state["pages"]) + 1
                state["skipped_records"] = int(state["skipped_records"]) + skipped
                if not rows:
                    if data:
                        state["errors"].append({"at": tape.iso_now(), "error": "malformed_page", "detail": f"{skipped} unusable records", "after_ms": after_ms})
                        self._end(state, "malformed_page", after_ms=after_ms)
                    else:
                        self._end(state, "server_empty_before_start", after_ms=after_ms)
                    break
                oldest_us = min(ts for ts, _ in rows)
                if oldest_us // 1000 >= after_ms:
                    state["errors"].append({"at": tape.iso_now(), "error": "pagination_not_advancing", "after_ms": after_ms, "oldest_ms": oldest_us // 1000})
                    self._end(state, "error", detail="pagination_not_advancing", after_ms=after_ms)
                    break
                for ts_us, row in rows:
                    before, after = self.guard.rejected_before_start, self.guard.rejected_at_or_after_end
                    if not self.guard.admits(ts_us):
                        state["rejected_before_start"] = int(state["rejected_before_start"]) + (self.guard.rejected_before_start - before)
                        state["rejected_at_or_after_end"] = int(state["rejected_at_or_after_end"]) + (self.guard.rejected_at_or_after_end - after)
                        continue
                    if int(row[-1]) == 0:
                        state["unconfirmed_rows"] = int(state["unconfirmed_rows"]) + 1  # stored; the evaluator drops them (index_minutes_from_candles)
                    self.store.append(LAYOUT, index_id, ts_us, row)
                    state["rows"] = int(state["rows"]) + 1
                    state["oldest_ts_us"] = ts_us if state["oldest_ts_us"] is None else min(int(state["oldest_ts_us"]), ts_us)
                    state["newest_ts_us"] = ts_us if state["newest_ts_us"] is None else max(int(state["newest_ts_us"]), ts_us)
                after_ms = oldest_us // 1000
                state["cursor_after_ms"] = after_ms
                if oldest_us <= int(state["start_us"]):
                    self._end(state, "reached_start")
                    break
                if pages % max(1, int(self.cfg.checkpoint_every_pages)) == 0:
                    self.store.checkpoint()
                    self._save()
        except g5_fetch.FatalStreamError as error:
            state["errors"].append({"at": tape.iso_now(), "error": str(error), "after_ms": after_ms})
            self._end(state, "malformed_page" if str(error) == "malformed_page" else "error", detail=str(error), after_ms=after_ms)
        except g5_fetch.BudgetExhausted as error:
            state["errors"].append({"at": tape.iso_now(), "error": "max_requests_reached", "detail": str(error), "after_ms": after_ms})
            self._end(state, "max_requests_reached", after_ms=after_ms)
        state["updated_at"] = tape.iso_now()
        self.store.checkpoint()
        self._save()
        return state

    def run(self) -> dict:
        self.journal.log("INFO", "fetch_start", jobs=len(self.cfg.index_ids), host=xc.resolve_rest(self.cfg.host), read_from=_iso(self.cfg.window.read_from_us),
                         end_exclusive=_iso(self.cfg.window.end_us), spec_sha256=self.cfg.spec_sha256, decision_ref=self.cfg.decision_ref,
                         max_requests=self.cfg.max_requests, plan_requests_total=self.plan["requests_total"])
        done = 0
        try:
            for index_id in self.cfg.index_ids:
                if self.budget_exhausted:
                    break
                self.run_job(index_id)
                done += 1
            finalized = self.store.finalize_days_before(tape.day_of_us(self.cfg.window.end_us - 1) + 1, force=True)
            self.journal.log("INFO", "finalized", days=len(finalized))
        finally:
            self.store.close()
            self.store.manifest["sessions"][-1].update({"ended_at": tape.iso_now(), "jobs_run": done, "requests": self.requests, "retries": self.retries,
                                                         "rejected_before_start": self.guard.rejected_before_start,
                                                         "rejected_at_or_after_end": self.guard.rejected_at_or_after_end, "budget_exhausted": self.budget_exhausted})
            self._save()
            try:
                self.receipt = self._write_receipt()
            finally:
                self.journal.log("INFO", "fetch_end", jobs_run=done, requests=self.requests, retries=self.retries, rejected_before_start=self.guard.rejected_before_start,
                                 rejected_at_or_after_end=self.guard.rejected_at_or_after_end, budget_exhausted=self.budget_exhausted)
                self.lock.release()
        return self.summary()

    def _write_receipt(self) -> dict:
        folder = self.out_dir / LAYOUT
        digest, files = g5_fetch.directory_digest(folder) if folder.is_dir() else (None, 0)
        states = [self.state_of(i) for i in self.cfg.index_ids]
        receipt = {"kind": RECEIPT_KIND, "generated_at": tape.iso_now(), "spec": self.cfg.spec_info(), "window": self.cfg.window.as_dict(), "decision_ref": self.cfg.decision_ref,
                   "index_ids": list(self.cfg.index_ids), "index_ids_sha256": self.cfg.index_ids_sha256, "max_requests": int(self.cfg.max_requests),
                   "plan_requests_total": self.plan["requests_total"], "requests": int(self.store.manifest.get("requests", 0)),
                   "jobs_complete": sum(1 for s in states if s.get("complete")), "jobs_total": len(states),
                   "truncated": sorted(s["index_id"] for s in states if s.get("truncated")), "budget_exhausted": self.budget_exhausted,
                   "rejected_before_start": int(self.store.manifest.get("rejected_before_start", 0)),
                   "rejected_at_or_after_end": int(self.store.manifest.get("rejected_at_or_after_end", 0)),
                   "index_tape_sha256": digest, "index_tape_files": files,
                   "index_tape_rule": "g5_fetch.directory_digest(<out>/index_1m): the value the register records and g5-eval --expect-index-tape-sha256 checks",
                   "register_status": "unread"}
        tape.atomic_write_text(self.out_dir / RECEIPT_NAME, json.dumps(receipt, indent=1, sort_keys=True) + "\n")
        receipt["receipt_sha256"] = tape.sha256_of(self.out_dir / RECEIPT_NAME)
        return receipt

    @property
    def ok(self) -> bool:
        return not self.budget_exhausted and all(self.state_of(i).get("complete") and not self.state_of(i).get("errors") for i in self.cfg.index_ids)

    def summary(self) -> dict:
        states = [self.state_of(i) for i in self.cfg.index_ids]
        return {"jobs": len(states), "complete": sum(1 for s in states if s.get("complete")), "rows": sum(int(s["rows"]) for s in states), "requests": self.requests,
                "retries": self.retries, "rejected_before_start": self.guard.rejected_before_start, "rejected_at_or_after_end": self.guard.rejected_at_or_after_end,
                "end_reasons": {s["index_id"]: s.get("end_reason") for s in states}, "budget_exhausted": self.budget_exhausted,
                "index_tape_sha256": (self.receipt or {}).get("index_tape_sha256"), "receipt_sha256": (self.receipt or {}).get("receipt_sha256")}


# ----------------------------------------------------------------------------- command line


def add_parser(sub) -> None:
    parser = sub.add_parser("g5-index-fetch", allow_abbrev=False,
                            help="REST 1-minute index candles of the G5 Gate 2 period (P-G5-10 (ii) option b, D10), once, after the window; FROZEN spec only")
    parser.add_argument("--out", required=True)
    parser.add_argument("--spec", required=True, help="the FROZEN Gate 2 spec (g5_gate2_spec.json)")
    parser.add_argument("--expect-spec-sha256", required=True, help="its sha256 (protocol 13); a different file is refused before parsing")
    parser.add_argument("--gate1-spec", default="", help="the FROZEN Gate 1 spec frozen with it (default: frozen_with.spec)")
    parser.add_argument("--index-ids", default="", help="comma-separated index ids (= the uly of universe.analysed, exactly); a real run needs this or --index-ids-file")
    parser.add_argument("--index-ids-file", default="", help="one index id per line (its sha256 is recorded)")
    parser.add_argument("--host", default=g5_fetch.DEFAULT_HOST, help="eea | global | https:// URL (default the EEA REST host; global needs --host-reason)")
    parser.add_argument("--host-reason", default="")
    parser.add_argument("--rate-scale", type=float, default=0.8, help="fraction of the documented rate limit used (0 < x <= 1)")
    parser.add_argument("--max-requests", type=int, default=None, help="hard cap on the requests, retries included (mandatory for a real run; the plan must fit)")
    parser.add_argument("--decision-ref", default="", help="the owner's dated text (D10, 2026-10-21; mandatory for a real run; recorded)")
    parser.add_argument("--checkpoint-every-pages", type=int, default=20)
    parser.add_argument("--dry-run", action="store_true", help="print the plan; connect to nothing; write nothing unless --plan-out")
    parser.add_argument("--plan-out", default="")
    parser.add_argument("--quiet", action="store_true")
    parser.set_defaults(func=command_index_fetch)


def _refuse(reason: str, detail: str) -> int:
    print(f"{tape.iso_now()} level=ERROR event=refused reason={reason} detail={json.dumps(detail)}", file=sys.stderr, flush=True)
    return 2


def config_from_args(args: argparse.Namespace, real_run: bool) -> IndexFetchConfig:
    spec, sha = load_frozen_spec(Path(args.spec), args.expect_spec_sha256, Path(args.gate1_spec) if args.gate1_spec else None)
    window = window_of(spec)
    ids, source = read_index_ids(args.index_ids, args.index_ids_file)
    if real_run or ids is not None:
        ids = check_index_ids(ids, spec)
    else:
        ids, source = index_ids_of(spec), "spec universe (dry run)"
    if not (0.0 < args.rate_scale <= 1.0):
        raise RefusedRun("rate_scale", "--rate-scale must be in (0, 1]")
    return IndexFetchConfig(out_dir=Path(args.out), spec=spec, spec_sha256=sha, spec_path=str(args.spec), window=window, index_ids=ids, index_ids_source=source,
                            host=args.host, host_reason=args.host_reason, rate_scale=args.rate_scale, checkpoint_every_pages=args.checkpoint_every_pages,
                            max_requests=args.max_requests, decision_ref=args.decision_ref, echo=not args.quiet, argv=list(sys.argv[1:]))


def command_index_fetch(args: argparse.Namespace, client_factory: Callable[[], object] | None = None, now_us: Callable[[], int] = tape.utc_now_us,
                        sleep: Callable[[float], None] = time.sleep, clock: Callable[[], float] = time.monotonic) -> int:
    real_run = not args.dry_run
    # Every refusal happens here, before any client exists: a refused run opens no connection.
    if real_run and not args.decision_ref.strip():
        return _refuse("decision_ref_missing", "pass --decision-ref TEXT (the owner's dated text, D10) or --dry-run")
    if real_run and (args.max_requests is None or args.max_requests <= 0):
        return _refuse("max_requests_missing", "pass --max-requests N (hard cap, retries included), at least the plan's requests_total")
    try:
        cfg = config_from_args(args, real_run)
    except RefusedRun as error:
        return _refuse(error.reason, error.detail)
    document = plan(cfg)
    if args.dry_run:
        if args.plan_out:
            path = Path(args.plan_out)
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(json.dumps(document, indent=1, sort_keys=True) + "\n")
        print(json.dumps(document, indent=1, sort_keys=True))
        return 0
    if xc.resolve_rest(cfg.host) == xc.REST_GLOBAL and not cfg.host_reason.strip():
        return _refuse("host_global_without_reason", "the global host is outside the owner's EEA licence; pass --host-reason TEXT or use the default EEA host")
    if int(document["requests_total"]) > int(cfg.max_requests):
        return _refuse("plan_exceeds_max_requests", f"the plan needs {document['requests_total']} requests, more than --max-requests {cfg.max_requests}")
    now = int(now_us())
    if now <= cfg.window.end_us:
        return _refuse("window_not_ended", f"now {_iso(now)} is not after the end of the Gate 2 window {_iso(cfg.window.end_us)}")
    client = (client_factory or g5_fetch.UrllibClient)()
    try:
        fetcher = IndexFetcher(cfg, client, sleep=sleep, clock=clock, now_us=now_us)
    except RefusedRun as error:
        return _refuse(error.reason, error.detail)
    except tape.LockHeld as error:
        print(f"{tape.iso_now()} level=ERROR event=refused reason=lock_held detail={json.dumps(str(error))}", file=sys.stderr, flush=True)
        return 3
    except ValueError as error:  # the store refuses a directory holding another tape (tape.TapeStore)
        return _refuse("store_refused", str(error))
    summary = fetcher.run()
    print(json.dumps(summary, sort_keys=True))
    return 0 if fetcher.ok else 1
