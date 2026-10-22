"""Offline tests of `g5_index_fetch`, the fetcher of the 1-minute index candles of the G5 Gate 2 period (P-G5-10 (ii) option b, owner
decision D10 of 2026-10-21: « (b) l'historique history-index-candles est récupéré une fois après la fin de la fenêtre du Gate 2 »).

Covered: the window comes from the FROZEN spec only ([first t_arm − 480 min − 60 min ; grid end), never before the quarantine floor);
the guards refuse a start inside the quarantine, before the floor or before T0's lookback; pagination towards the past with `after`;
no stored row outside the window, even when the server sends some; the request loop, backoff and budget of `g5_fetch` reused (and
`g5_fetch.END_EXCLUSIVE` untouched); every refusal of the command (no decision-ref, no max-requests, wrong spec sha256, DRAFT spec,
index ids not those of the universe, run before the end of the window, plan above max-requests, global host without reason) happens
before any client exists; the output is the `index_1m` layout read by `g5_gate2.index_minutes_from_candles`, by
`g5_eval.load_capture_series` and by the D10 selection, and its receipt carries the digest `g5_gate2.check_index_tape` checks.

No market data exists here: the server is a fake transport; no test opens a connection or resolves a name (socket-level autouse guard)."""

from __future__ import annotations

import copy
import json
import math
import socket
import urllib.parse
import urllib.request
from pathlib import Path

import numpy as np
import pytest

from scripts.research_lp import cli, g5_eval, g5_fetch, g5_gate2, g5_index_fetch, tape
from tests.test_research_lp_g5_gate2 import BASE, BTC, ETH, FROZEN_GATE1_TEXT, GATE2_SPEC, add_index_ticks, frozen, item, resolve, short, write_capture

US = tape.SECOND_US
MINUTE_US = 60 * US
HOUR_US = 60 * MINUTE_US
DAY_US = tape.DAY_S * US
FLOOR_US = tape.parse_iso_utc("2026-11-02T00:00:00Z")
# P-L2-1 (D14, 2026-10-22): a FROZEN Gate 2 has T0 >= 2026-11-04; the short windows of these tests run 2026-11-04 -> 2026-11-07 (adapted;
# 2026-11-02 -> 2026-11-05 before), the index read starts at T0 - 540 min = 2026-11-03T15:00Z, after the separation floor 2026-11-03T12:00Z.
T0_US = tape.parse_iso_utc("2026-11-04T00:00:00Z")
SEPARATION_FLOOR_US = tape.parse_iso_utc("2026-11-03T12:00:00Z")
D10 = g5_gate2.INDEX_CHOICE_D10
DECISION = "D10, owner, 2026-10-21: On essaye des deux un par un puis on decide (test)"


@pytest.fixture(autouse=True)
def _no_network(monkeypatch):
    def refuse(*args, **kwargs):  # pragma: no cover - the assertion is the point
        raise AssertionError("network access attempted")

    monkeypatch.setattr(socket.socket, "connect", refuse)
    monkeypatch.setattr(socket.socket, "connect_ex", refuse)
    monkeypatch.setattr(socket.socket, "sendto", refuse)
    monkeypatch.setattr(socket, "create_connection", refuse)
    monkeypatch.setattr(socket, "getaddrinfo", refuse)
    monkeypatch.setattr(urllib.request, "urlopen", refuse)
    monkeypatch.setattr(g5_fetch.UrllibClient, "get", refuse)


# ----------------------------------------------------------------------------- helpers


def gate2_document(end: str = "2026-11-07", t0: str = "2026-11-04T00:00:00Z", index: str = D10, max_gap_days: int | None = 0) -> dict:
    """A FROZEN Gate 2 document on a short window (T0 → `end`), D10 (or option b), P-S3 moot (D8), BTC and ETH; `max_gap_days` 0 keeps the
    grid end at `end` (None: the draft's 14 days, which the reader caps at 2027-04-01 under D7)."""
    document = short(frozen(copy.deepcopy(BASE)), end)
    resolve(document, index=index, p_s3=g5_gate2.P_S3_MOOT)
    last = tape.iso_utc(tape.parse_iso_utc(f"{end}T00:00:00Z") - DAY_US)[:10]
    document["freeze_fill"].update({"t05_end_inclusive_utc": last, "t0_utc": t0})
    document["universe"]["analysed"] = [item(BTC, "2026-09-01T00:00:00Z"), item(ETH, "2026-09-01T00:00:00Z", 0.01)]
    if max_gap_days is not None:
        document["window"]["data_gap_extension"]["max_days"] = max_gap_days
    return document


def write_spec(tmp_path: Path, document: dict, name: str = "gate2.json") -> tuple[Path, str, Path]:
    gate1 = tmp_path / "gate1-frozen.json"
    gate1.write_text(FROZEN_GATE1_TEXT)
    path = tmp_path / name
    path.write_text(json.dumps(document, indent=1))
    return path, tape.sha256_of(path), gate1


def parsed(document: dict):
    return g5_eval.parse_spec(document, json.loads(FROZEN_GATE1_TEXT))


class FakeTime:
    def __init__(self) -> None:
        self.t = 0.0
        self.slept: list[float] = []

    def clock(self) -> float:
        return self.t

    def sleep(self, seconds: float) -> None:
        self.slept.append(float(seconds))
        self.t += max(0.0, float(seconds))


class FakeIndexServer:
    """`history-index-candles` of a synthetic server: one confirmed candle per minute of [lo ; hi) per index (close `close`), served newest
    first, strictly before `after`, at most `limit` per page. `straddle_first`: the first page of each index ignores `after` and straddles
    the end of the window (a server that sends rows the window excludes). `fail_first`: HTTP 429 at the first request."""

    def __init__(self, lo_us: int, hi_us: int, close: float = 100.1, straddle_end_us: int | None = None, fail_first: bool = False) -> None:
        self.lo_us, self.hi_us, self.close = lo_us, hi_us, close
        self.straddle_end_us = straddle_end_us
        self.fail_first = fail_first
        self.urls: list[str] = []
        self.first_seen: set[str] = set()

    def get(self, url: str) -> g5_fetch.HttpResponse:
        self.urls.append(url)
        if self.fail_first and len(self.urls) == 1:
            return g5_fetch.HttpResponse(429, b'{"code":"50011","msg":"Too Many Requests","data":[]}', None)
        parts = urllib.parse.urlsplit(url)
        assert parts.path == "/api/v5/market/history-index-candles"
        query = dict(urllib.parse.parse_qsl(parts.query))
        inst, after_ms, limit = query["instId"], int(query["after"]), int(query["limit"])
        assert query["bar"] == "1m" and limit <= 100
        if self.straddle_end_us is not None and inst not in self.first_seen:
            top = self.straddle_end_us + 50 * MINUTE_US
        else:
            top = min(after_ms * 1000, self.hi_us)
        self.first_seen.add(inst)
        rows = []
        ts = (top - 1) // MINUTE_US * MINUTE_US if top % MINUTE_US else top - MINUTE_US
        while ts >= self.lo_us and len(rows) < limit:
            if ts < self.hi_us:
                c = f"{self.close:.4f}"
                rows.append([str(ts // 1000), c, c, c, c, "1"])
            ts -= MINUTE_US
        return g5_fetch.HttpResponse(200, json.dumps({"code": "0", "msg": "", "data": rows}).encode())


def config(spec, sha: str, path: Path, out: Path, max_requests: int | None = 1000, ids=None, **kwargs) -> g5_index_fetch.IndexFetchConfig:
    return g5_index_fetch.IndexFetchConfig(out_dir=out, spec=spec, spec_sha256=sha, spec_path=str(path), window=g5_index_fetch.window_of(spec),
                                           index_ids=list(ids if ids is not None else g5_index_fetch.index_ids_of(spec)), max_requests=max_requests,
                                           decision_ref=kwargs.pop("decision_ref", DECISION), echo=False, **kwargs)


def args_for(path: Path, sha: str, gate1: Path | None, out: Path, *extra: str) -> object:
    gate1_args = ["--gate1-spec", str(gate1)] if gate1 is not None else []  # None: the spec's frozen_with (the repository's Gate 1)
    return cli.build_parser().parse_args(["g5-index-fetch", "--out", str(out), "--spec", str(path), "--expect-spec-sha256", sha, *gate1_args, "--quiet", *extra])


def no_client():  # pragma: no cover - the assertion is the point
    raise AssertionError("a refused run built a client")


# ----------------------------------------------------------------------------- window and guards


def test_the_window_comes_from_the_frozen_spec_only():
    """read_from = max(quarantine floor, first t_arm − 480 min − 60 min), end = the spec's grid end (data-gap extension included as the
    chosen option allows it); the reused stream is g5_fetch's index_1m; the choices that need (b) are the reader's."""
    assert g5_index_fetch.STREAM is g5_fetch.STREAMS["index_1m"] and g5_index_fetch.STREAM.path == "/api/v5/market/history-index-candles"
    assert g5_index_fetch.CHOICES_NEEDING_B == (g5_gate2.INDEX_OPTION_B, g5_gate2.INDEX_CHOICE_D10) == g5_gate2.INDEX_FROZEN_ALLOWED
    spec = parsed(gate2_document())
    w = g5_index_fetch.window_of(spec)
    # T01: arm −5 = footprint start −5, so the first scored t_arm is T0; the read starts at T0 − 540 min, after the separation floor.
    assert (w.t0_us, w.first_t_arm_us, w.lookback_min, w.margin_min) == (T0_US, T0_US, 480, 60)
    assert (w.read_from_us, w.end_us) == (T0_US - 540 * MINUTE_US, tape.parse_iso_utc("2026-11-07T00:00:00Z")) and w.lookback_floor_us == T0_US - 540 * MINUTE_US
    assert w.read_from_us >= SEPARATION_FLOOR_US and w.separation == (FLOOR_US, SEPARATION_FLOOR_US)
    late = g5_index_fetch.window_of(parsed(gate2_document(t0="2026-11-05T00:00:00Z")))
    assert late.read_from_us == tape.parse_iso_utc("2026-11-04T15:00:00Z")  # 2026-11-05 − 480 min − 60 min
    # The data-gap extension of the draft (14 days) is part of the grid the evaluator reads: the index read covers it (under D7 the reader
    # never extends beyond 2027-04-01).
    extended = g5_index_fetch.window_of(parsed(gate2_document(max_gap_days=None)))
    assert extended.end_us == tape.parse_iso_utc("2026-11-21T00:00:00Z")
    assert g5_index_fetch.index_ids_of(spec) == ["BTC-USD", "ETH-USD"]
    with pytest.raises(g5_index_fetch.RefusedRun, match="t0_provisional"):
        g5_index_fetch.window_of(g5_eval.load_spec(GATE2_SPEC)[0])  # the repository's draft: T0 not frozen


@pytest.mark.parametrize("read_from, t0, reason", [
    ("2026-10-20T00:00:00Z", "2026-11-02T00:00:00Z", "start_in_quarantine"),
    ("2026-10-14T00:00:00Z", "2026-11-02T00:00:00Z", "start_in_quarantine"),
    ("2026-10-13T23:59:00Z", "2026-11-02T00:00:00Z", "start_before_quarantine_floor"),
    ("2026-11-02T14:00:00Z", "2026-11-03T00:00:00Z", "start_before_t0_lookback"),
    ("2026-11-05T00:00:00Z", "2026-11-03T00:00:00Z", "empty_window"),
    ("2026-11-02T00:00:00Z", "2026-11-01T00:00:00Z", "t0_before_quarantine_floor"),
    ("2026-11-02T15:00:30Z", "2026-11-03T00:00:00Z", "start_not_on_a_minute"),
])
def test_the_window_check_refuses_the_quarantine_and_t0_violations(read_from: str, t0: str, reason: str):
    t0_us = tape.parse_iso_utc(t0)
    window = g5_index_fetch.IndexWindow(t0_us, t0_us, 480, 60, tape.parse_iso_utc(read_from), tape.parse_iso_utc("2026-11-05T00:00:00Z"))
    with pytest.raises(g5_index_fetch.RefusedRun) as error:
        window.check()
    assert error.value.reason == reason


def test_the_window_check_refuses_a_start_inside_the_pilot_separation():
    """P-L2-1 (D14): a read starting inside [2026-11-02T00:00Z ; 2026-11-03T12:00Z) is refused, whatever T0 allows."""
    window = g5_index_fetch.IndexWindow(T0_US, T0_US, 480, 60, SEPARATION_FLOOR_US - MINUTE_US, tape.parse_iso_utc("2026-11-07T00:00:00Z"),
                                        (FLOOR_US, SEPARATION_FLOOR_US))
    with pytest.raises(g5_index_fetch.RefusedRun) as error:
        window.check()
    assert error.value.reason in ("start_inside_pilot_separation", "start_before_t0_lookback")
    inside = g5_index_fetch.IndexWindow(SEPARATION_FLOOR_US, SEPARATION_FLOOR_US, 480, 60, SEPARATION_FLOOR_US - 540 * MINUTE_US,
                                        tape.parse_iso_utc("2026-11-07T00:00:00Z"), (FLOOR_US, SEPARATION_FLOOR_US))
    with pytest.raises(g5_index_fetch.RefusedRun, match="pilot_separation") as error:
        inside.check()
    assert error.value.reason == "start_inside_pilot_separation"
    g5_index_fetch.IndexWindow(T0_US, T0_US, 480, 60, T0_US - 540 * MINUTE_US, T0_US + DAY_US, (FLOOR_US, SEPARATION_FLOOR_US)).check()


def test_the_window_guard_drops_both_sides_and_counts_them():
    w = g5_index_fetch.window_of(parsed(gate2_document()))
    guard = g5_index_fetch.WindowGuard(w)
    assert guard.admits(w.read_from_us) and guard.admits(w.end_us - MINUTE_US)
    assert not guard.admits(w.end_us) and not guard.admits(w.read_from_us - MINUTE_US) and not guard.admits(g5_fetch.END_EXCLUSIVE_US)
    assert (guard.rejected_before_start, guard.rejected_at_or_after_end) == (2, 1)


# ----------------------------------------------------------------------------- the fetcher (fake transport)


def test_pagination_stores_only_rows_inside_the_window_and_writes_a_hashed_receipt(tmp_path: Path):
    """The walk starts at `after = end` and goes back page by page; a server that straddles the end and serves the quarantine days is
    filtered by the guard before the store; every index ends `reached_start` with no row outside [read_from ; end)."""
    document = gate2_document()
    path, sha, _ = write_spec(tmp_path, document)
    spec = parsed(document)
    w = g5_index_fetch.window_of(spec)
    server = FakeIndexServer(tape.parse_iso_utc("2026-11-01T00:00:00Z"), tape.parse_iso_utc("2026-11-09T00:00:00Z"), straddle_end_us=w.end_us)
    clock = FakeTime()
    out = tmp_path / "index"
    cfg = config(spec, sha, path, out, max_requests=200)
    fetcher = g5_index_fetch.IndexFetcher(cfg, server, sleep=clock.sleep, clock=clock.clock, now_us=lambda: w.end_us + HOUR_US)
    plan = fetcher.plan
    minutes = (w.end_us - w.read_from_us) // MINUTE_US
    assert minutes == 3 * 1440 + 540 and plan["requests_total"] == 2 * math.ceil(minutes / 100) and plan["jobs"][0]["first_url"].endswith(f"after={w.end_us // 1000}&limit=100")
    summary = fetcher.run()
    assert fetcher.ok and summary["complete"] == 2 and summary["end_reasons"] == {"BTC-USD": "reached_start", "ETH-USD": "reached_start"}
    assert summary["rejected_at_or_after_end"] == 2 * 50 and summary["rejected_before_start"] > 0 and summary["rows"] == 2 * minutes
    queried = {dict(urllib.parse.parse_qsl(urllib.parse.urlsplit(u).query))["instId"] for u in server.urls}
    assert queried == {"BTC-USD", "ETH-USD"} and len(server.urls) == summary["requests"] <= 200
    dates = [tape.iso_utc(w.read_from_us + d * DAY_US)[:10] for d in range(-3, 6)]
    for index_id in ("BTC-USD", "ETH-USD"):
        rows, _ = tape.load_days(out, "index_1m", index_id, dates, allow_pending=False)
        assert rows.size == minutes and rows["ts_us"].min() == w.read_from_us and rows["ts_us"].max() == w.end_us - MINUTE_US
        assert np.all(np.diff(rows["ts_us"]) == MINUTE_US) and np.all(rows["close"] == 100.1) and np.all(rows["confirm"] == 1)
    receipt = json.loads((out / g5_index_fetch.RECEIPT_NAME).read_text())
    digest = g5_fetch.directory_digest(out / "index_1m")[0]
    assert receipt["index_tape_sha256"] == digest == summary["index_tape_sha256"] and receipt["spec"]["sha256"] == sha and receipt["decision_ref"] == DECISION
    assert receipt["window"]["read_from"] == "2026-11-03T15:00:00Z" and receipt["index_ids_sha256"] == g5_fetch.inst_ids_digest(["BTC-USD", "ETH-USD"])
    manifest = tape.read_manifest(out)
    assert manifest["kind"] == g5_index_fetch.MANIFEST_KIND and manifest["not_before"] == "2026-11-03T15:00:00Z" and manifest["mode"] == "values"
    assert g5_gate2.check_index_tape(out, digest)["digest"] == digest  # what g5-eval --expect-index-tape-sha256 checks
    with pytest.raises(SystemExit, match="differs"):
        g5_gate2.check_index_tape(out, "0" * 64)
    # A second session skips the complete jobs (resume): no request, the same tape.
    again = g5_index_fetch.IndexFetcher(cfg, server, sleep=clock.sleep, clock=clock.clock, now_us=lambda: w.end_us + HOUR_US)
    before = len(server.urls)
    again.run()
    assert len(server.urls) == before and g5_fetch.directory_digest(out / "index_1m")[0] == digest


def test_the_request_loop_of_g5_fetch_is_reused_with_its_backoff_and_budget(tmp_path: Path):
    """`IndexFetcher.request` is `g5_fetch.Fetcher.request`: a 429 is retried after the backoff (no real sleep), and the budget counts the
    retry: with max_requests = the plan, one retry exhausts it (max_requests_reached, run not ok). g5_fetch's Gate 1 bound is untouched."""
    assert g5_index_fetch.IndexFetcher.request is g5_fetch.Fetcher.request
    assert g5_fetch.END_EXCLUSIVE == "2026-10-14T00:00:00Z" and not g5_fetch.EndGuard().admits(g5_fetch.END_EXCLUSIVE_US)
    document = gate2_document()
    path, sha, _ = write_spec(tmp_path, document)
    spec = parsed(document)
    w = g5_index_fetch.window_of(spec)
    server = FakeIndexServer(w.read_from_us - DAY_US, w.end_us, fail_first=True)
    clock = FakeTime()
    total = g5_index_fetch.plan(config(spec, sha, path, tmp_path / "x"))["requests_total"]
    fetcher = g5_index_fetch.IndexFetcher(config(spec, sha, path, tmp_path / "index", max_requests=total), server, sleep=clock.sleep, clock=clock.clock,
                                          now_us=lambda: w.end_us + 1)
    summary = fetcher.run()
    assert 2.0 in clock.slept and summary["retries"] == 1 and summary["requests"] == total and summary["budget_exhausted"] and not fetcher.ok
    assert "max_requests_reached" in summary["end_reasons"].values()


def test_the_fetcher_itself_refuses_what_the_command_refuses(tmp_path: Path):
    """Defence in depth: the class refuses without decision_ref, without max_requests, below the plan, before the end of the window, with
    other ids or another window, with a DRAFT spec — before any request."""
    document = gate2_document()
    path, sha, _ = write_spec(tmp_path, document)
    spec = parsed(document)
    w = g5_index_fetch.window_of(spec)
    after = lambda: w.end_us + 1  # noqa: E731
    cases = (
        (dict(decision_ref=" "), after, "decision_ref_missing"),
        (dict(max_requests=None), after, "max_requests_missing"),
        (dict(max_requests=10), after, "plan_exceeds_max_requests"),
        (dict(), lambda: w.end_us, "window_not_ended"),
        (dict(ids=["BTC-USD"]), after, "index_ids_differ_from_spec"),
        (dict(ids=["BTC-USD", "ETH-USD", "SOL-USD"]), after, "index_ids_differ_from_spec"),
    )
    for kwargs, now, reason in cases:
        with pytest.raises(g5_index_fetch.RefusedRun) as error:
            g5_index_fetch.IndexFetcher(config(spec, sha, path, tmp_path / "never", **kwargs), no_client, now_us=now)
        assert error.value.reason == reason
    cfg = config(spec, sha, path, tmp_path / "never")
    cfg.window = g5_index_fetch.IndexWindow(w.t0_us, w.first_t_arm_us, 480, 60, w.read_from_us + HOUR_US, w.end_us)
    with pytest.raises(g5_index_fetch.RefusedRun, match="window_differs_from_spec"):
        g5_index_fetch.IndexFetcher(cfg, no_client, now_us=after)
    draft = g5_eval.parse_spec(copy.deepcopy(BASE))
    with pytest.raises(g5_index_fetch.RefusedRun):
        g5_index_fetch.IndexFetcher(g5_index_fetch.IndexFetchConfig(tmp_path / "never", draft, sha, str(path), w, ["BTC-USD", "ETH-USD"], max_requests=1000,
                                                                    decision_ref=DECISION), no_client, now_us=after)
    assert not (tmp_path / "never").exists()


# ----------------------------------------------------------------------------- the command line


def test_every_refusal_of_the_command_happens_before_any_client(tmp_path: Path, capsys):
    document = gate2_document()
    path, sha, gate1 = write_spec(tmp_path, document)
    end_us = g5_index_fetch.window_of(parsed(document)).end_us
    draft_path = tmp_path / "draft.json"
    draft_path.write_bytes(GATE2_SPEC.read_bytes())
    ids = ["--index-ids", "BTC-USD,ETH-USD"]
    real = ["--max-requests", "1000", "--decision-ref", DECISION]
    out = tmp_path / "index"
    cases = (
        (args_for(path, sha, gate1, out, *ids, "--max-requests", "1000"), end_us + 1, "decision_ref_missing"),
        (args_for(path, sha, gate1, out, *ids, "--decision-ref", DECISION), end_us + 1, "max_requests_missing"),
        (args_for(path, "0" * 64, gate1, out, *ids, *real), end_us + 1, "spec_sha256_mismatch"),
        (args_for(path, "not-a-sha", gate1, out, *ids, *real), end_us + 1, "expect_spec_sha256_missing"),
        (args_for(draft_path, tape.sha256_of(draft_path), None, out, *ids, *real), end_us + 1, "spec_not_frozen"),
        (args_for(path, sha, gate1, out, *real), end_us + 1, "index_ids_missing"),
        (args_for(path, sha, gate1, out, "--index-ids", "BTC-USD", *real), end_us + 1, "index_ids_differ_from_spec"),
        (args_for(path, sha, gate1, out, *ids, *real), end_us, "window_not_ended"),
        (args_for(path, sha, gate1, out, *ids, "--max-requests", "50", "--decision-ref", DECISION), end_us + 1, "plan_exceeds_max_requests"),
        (args_for(path, sha, gate1, out, *ids, *real, "--host", "global"), end_us + 1, "host_global_without_reason"),
    )
    for args, now, reason in cases:
        assert g5_index_fetch.command_index_fetch(args, client_factory=no_client, now_us=lambda now=now: now) == 2
        assert f"reason={reason}" in capsys.readouterr().err
    assert not out.exists()
    # The DRAFT reader also refuses a FROZEN document that keeps a pending owner decision (no fetch can be planned on it).
    pending = gate2_document()
    resolve(pending, t05="owner_decision_pending")
    p_path, p_sha, _ = write_spec(tmp_path, pending, "pending.json")
    assert g5_index_fetch.command_index_fetch(args_for(p_path, p_sha, gate1, out, *ids, *real), client_factory=no_client, now_us=lambda: end_us + 1) == 2
    assert "reason=spec_error" in capsys.readouterr().err


def test_the_dry_run_plans_without_a_client_and_the_cli_is_wired(tmp_path: Path, capsys):
    document = gate2_document()
    path, sha, gate1 = write_spec(tmp_path, document)
    plan_out = tmp_path / "plan.json"
    assert g5_index_fetch.command_index_fetch(args_for(path, sha, gate1, tmp_path / "index", "--dry-run", "--plan-out", str(plan_out)), client_factory=no_client) == 0
    printed = json.loads(capsys.readouterr().out)
    assert printed == json.loads(plan_out.read_text()) and printed["kind"] == g5_index_fetch.PLAN_KIND and printed["requests_total"] == 2 * 49
    assert printed["index_ids"] == ["BTC-USD", "ETH-USD"] and printed["spec"]["sha256"] == sha and printed["spec"]["index_choice"] == D10
    assert printed["window"]["end_exclusive"] == "2026-11-07T00:00:00Z" and not (tmp_path / "index").exists()
    assert "g5-index-fetch" in cli.build_parser().format_help()


def test_the_command_runs_after_the_window_and_its_tape_feeds_option_b_and_the_d10_choice(tmp_path: Path, capsys):
    """End to end: the fetched `index_1m` tape is read by `g5_gate2.index_minutes_from_candles`, by `g5_eval.load_capture_series`
    (`--index-tape`) and by the D10 selection, which picks (a) when the captured index agrees within 0.5 bp and covers >= 98 %."""
    document = gate2_document()
    path, sha, gate1 = write_spec(tmp_path, document)
    spec = parsed(document)
    w = g5_index_fetch.window_of(spec)
    server = FakeIndexServer(w.read_from_us - DAY_US, w.end_us + DAY_US)
    clock = FakeTime()
    out = tmp_path / "index"
    args = args_for(path, sha, gate1, out, "--index-ids", "ETH-USD,BTC-USD", "--max-requests", "100", "--decision-ref", DECISION)
    code = g5_index_fetch.command_index_fetch(args, client_factory=lambda: server, now_us=lambda: w.end_us + HOUR_US, sleep=clock.sleep, clock=clock.clock)
    summary = json.loads(capsys.readouterr().out)
    assert code == 0 and summary["complete"] == 2 and summary["requests"] == 98 and summary["rejected_at_or_after_end"] == 0
    # The layout read by option (b): the close of the confirmed candle ending at g, at every minute boundary of (read_from ; end].
    rows, _ = tape.load_days(out, "index_1m", "BTC-USD", ["2026-11-03", "2026-11-04", "2026-11-05", "2026-11-06"], allow_pending=False)
    grid = np.arange(w.read_from_us, w.end_us + MINUTE_US, MINUTE_US, dtype=np.int64)
    idx = g5_gate2.index_minutes_from_candles(grid, rows)
    assert np.isnan(idx[0]) and np.all(idx[1:] == 100.1)
    # A capture with the index-tickers layout 0.1 bp away (D10 (a)); the candles of (b) come from the fetched tape (--index-tape).
    root = tmp_path / "capture"
    write_capture(root, index_root=tmp_path / "unused-index", days=6)
    add_index_ticks(root, 0.1e-4, days=6)
    series = {BTC: g5_eval.load_capture_series(root, spec.instrument(BTC), spec, {"instId": BTC}, out)}
    sources = series[BTC].cache["index_sources"]
    in_window = (series[BTC].grid_us > w.read_from_us) & (series[BTC].grid_us <= w.end_us)
    assert np.all(np.asarray(sources[g5_gate2.INDEX_OPTION_B])[in_window] == 100.1)
    selection = g5_gate2.apply_index_selection(series, spec)
    assert selection["primary"] == g5_gate2.INDEX_OPTION_A and selection["measures"]["median_abs_diff_bp"] == pytest.approx(0.1, abs=1e-6)
    assert selection["measures"]["pairs_b"] == selection["measures"]["pairs"]  # T0 - 540 min is read: the candle ending at g = T0 exists (P-L2-1: T0 >= 2026-11-04)
    assert g5_gate2.verify_index_selection(selection, spec) == []
    # Disagreeing sources (the fetched candles 2 bp away): (b), the fetched tape, becomes the primary.
    far = tmp_path / "index-far"
    server_far = FakeIndexServer(w.read_from_us - DAY_US, w.end_us + DAY_US, close=100.1 * (1 + 2e-4))
    args = args_for(path, sha, gate1, far, "--index-ids", "BTC-USD,ETH-USD", "--max-requests", "100", "--decision-ref", DECISION)
    assert g5_index_fetch.command_index_fetch(args, client_factory=lambda: server_far, now_us=lambda: w.end_us + 1, sleep=clock.sleep, clock=clock.clock) == 0
    capsys.readouterr()
    series = {BTC: g5_eval.load_capture_series(root, spec.instrument(BTC), spec, {"instId": BTC}, far)}
    assert g5_gate2.apply_index_selection(series, spec)["primary"] == g5_gate2.INDEX_OPTION_B
