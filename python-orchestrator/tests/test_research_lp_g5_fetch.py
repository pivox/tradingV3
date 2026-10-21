"""Offline tests of the G5 history fetcher (`scripts/research_lp/g5_fetch.py`): the END_EXCLUSIVE
constant and its guard, pagination, end of job and coverage (`end_reason`, `coverage_start_us`,
truncation, D_cov), malformed pages, resume and cumulative counters, `--force`, the rate limiter, the
backoff (retry codes, `Retry-After`), the request budget, the scale gates of a real run (the G5 spec and
its phase, the hashed instrument list, `--max-requests`, the dry-run `--plan`, host), the per-stream
starts of LP-G5 §4.3, the sealed mode of the pre-freeze funding fetch (P-G5-3: global totals only on the
console and in the journal, the per-instrument manifest sealed and hashed), the dry run and the metadata.

Adapted on 2026-10-21 (code (B) xix): the funding history is fetched in sealed mode only, so the two tests
that fetched `funding_hist` openly now run it sealed and read the sealed manifest; a real run is driven by
`--spec --phase` (the old real-run test without a spec is now a refusal).

No test opens a connection: an autouse fixture makes `socket.socket.connect`, `socket.create_connection`,
`urllib.request.urlopen` and `UrllibClient.get` fail loudly, and the fetcher only ever sees `FakeOkx`."""

from __future__ import annotations

import json
import math
import socket
import urllib.parse
import urllib.request
from pathlib import Path

import numpy as np
import pytest

from scripts.research_lp import cli, g5_fetch, tape

US = tape.SECOND_US
MS = 1000
MINUTE_MS = 60_000
END_MS = g5_fetch.END_EXCLUSIVE_MS
INST = "BTC-USD_UM_XPERP-310404"
INST2 = "ETH-USD_UM_XPERP-310404"
LIST_MS = tape.parse_iso_utc("2026-03-30T04:30:00Z") // 1000
PLAN_SHA = "0" * 64  # the Fetcher only checks the shape; the CLI computes the real one from the plan file


@pytest.fixture(autouse=True)
def _no_network(monkeypatch):
    def refuse(*args, **kwargs):  # pragma: no cover - the assertion is the point
        raise AssertionError(f"network access attempted: {args[1:] if len(args) > 1 else args}")

    monkeypatch.setattr(socket.socket, "connect", refuse)
    monkeypatch.setattr(socket.socket, "connect_ex", refuse)
    monkeypatch.setattr(socket, "create_connection", refuse)
    monkeypatch.setattr(urllib.request, "urlopen", refuse)
    monkeypatch.setattr(g5_fetch.UrllibClient, "get", refuse)


def snapshot(path: Path, rows=None) -> Path:
    """An `eea` instruments snapshot (`{"code","data":[…]}`) with two live X-Perps, one dated contract, one suspended and one pre-market X-Perp."""
    rows = rows if rows is not None else [
        {"instId": INST, "instType": "FUTURES", "ruleType": "xperp", "state": "live", "uly": "BTC-USD", "ctVal": "0.0001", "tickSz": "0.1", "lotSz": "1", "minSz": "1", "listTime": str(LIST_MS)},
        {"instId": INST2, "instType": "FUTURES", "ruleType": "xperp", "state": "live", "uly": "ETH-USD", "ctVal": "0.001", "tickSz": "0.01", "lotSz": "1", "minSz": "1", "listTime": str(LIST_MS + 86_400_000)},
        {"instId": "BTC-USD_UM-261226", "instType": "FUTURES", "ruleType": "normal", "state": "live", "uly": "BTC-USD", "listTime": str(LIST_MS)},
        {"instId": "SOL-USD_UM_XPERP-310404", "instType": "FUTURES", "ruleType": "xperp", "state": "suspend", "uly": "SOL-USD", "listTime": str(LIST_MS)},
        {"instId": "NEW-USD_UM_XPERP-310404", "instType": "FUTURES", "ruleType": "pre_market", "state": "live", "uly": "NEW-USD", "listTime": str(LIST_MS)},
    ]
    path.write_text(json.dumps({"code": "0", "msg": "", "data": rows}))
    return path


def candle(ts_ms: int, px: float = 100.0, confirm: str = "1"):
    return [str(ts_ms), f"{px}", f"{px + 0.5}", f"{px - 0.5}", f"{px + 0.1}", "10", "0.001", "100.1", confirm]


def px_candle(ts_ms: int, px: float = 100.0):
    return [str(ts_ms), f"{px}", f"{px + 0.5}", f"{px - 0.5}", f"{px + 0.1}", "1"]


def funding_row(ts_ms: int, rate: float = 0.0001):
    return {"instType": "FUTURES", "instId": INST, "formulaType": "withRate", "fundingRate": f"{rate}", "realizedRate": f"{rate * 0.9}", "fundingTime": str(ts_ms), "method": "next_period"}


class FakeOkx:
    """Serves `(path, instId) → rows` (any order) newest first, honouring `after` (strictly earlier) and `limit` unless told to
    ignore `after`. `script` = responses served first, in order (`(status, body_dict_or_bytes, retry_after_s)`); `fail_first` answers
    the first calls with HTTP 429 / code 50011; `fatal_code` answers every call with that OKX code."""

    def __init__(self, series: dict, fail_first: int = 0, ignore_after: bool = False, fatal_code: str | None = None, script=None):
        self.series = {key: sorted(rows, key=self._ts, reverse=True) for key, rows in series.items()}
        self.fail_first = fail_first
        self.ignore_after = ignore_after
        self.fatal_code = fatal_code
        self.script = list(script or [])
        self.calls: list[dict] = []

    @staticmethod
    def _ts(row) -> int:
        return int(row["fundingTime"]) if isinstance(row, dict) else int(row[0])

    def get(self, url: str) -> g5_fetch.HttpResponse:
        parsed = urllib.parse.urlparse(url)
        query = {k: v[0] for k, v in urllib.parse.parse_qs(parsed.query).items()}
        self.calls.append({"path": parsed.path, **query, "host": f"{parsed.scheme}://{parsed.netloc}"})
        if self.script:
            status, body, retry_after = self.script.pop(0)
            return g5_fetch.HttpResponse(status, body if isinstance(body, bytes) else json.dumps(body).encode(), retry_after)
        if self.fail_first > 0:
            self.fail_first -= 1
            return g5_fetch.HttpResponse(429, json.dumps({"code": "50011", "msg": "Rate limit reached", "data": []}).encode())
        if self.fatal_code:
            return g5_fetch.HttpResponse(200, json.dumps({"code": self.fatal_code, "msg": "Instrument ID does not exist", "data": []}).encode())
        rows = self.series.get((parsed.path, query["instId"]), [])
        after = int(query["after"])
        page = rows if self.ignore_after else [r for r in rows if self._ts(r) < after]
        page = page[: int(query["limit"])]
        return g5_fetch.HttpResponse(200, json.dumps({"code": "0", "msg": "", "data": page}).encode())


def minutes(start_ms: int, count: int, step_ms: int = MINUTE_MS) -> list[int]:
    return [start_ms + k * step_ms for k in range(count)]


def make_config(out: Path, rows: list[dict], **kw) -> g5_fetch.FetchConfig:
    defaults = dict(out_dir=out, instruments=rows, streams=("candles_1m",), decision_ref="test: synthetic server, no market data", echo=False, checkpoint_every_pages=2,
                    max_requests=10_000, plan_sha256=PLAN_SHA)
    defaults.update(kw)
    return g5_fetch.FetchConfig(**defaults)


def no_sleep(_: float) -> None:
    return None


def one_inst(list_ms: int) -> list[dict]:
    return [{"instId": INST, "ruleType": "xperp", "state": "live", "uly": "BTC-USD", "listTime": str(list_ms)}]


REAL_SPEC = Path(__file__).resolve().parents[1] / "scripts" / "research_lp" / "specs" / "g5_gate1_spec.json"
DAY_MS = 86_400_000


def iso_ms(ms: int) -> str:
    return tape.iso_utc(ms * 1000)[:19] + "Z"


def derived_spec(path: Path, universe: list[tuple[str, int]]) -> Path:
    """The real G5 spec with another universe (`(inst_id, listTime ms)`): the audit numbers that depend on the universe are dropped
    (T05 calendar, break-evens) or recomputed (fetch-plan estimates) — the real spec keeps its own, checked elsewhere."""
    document = json.loads(REAL_SPEC.read_text())
    document["universe"]["analysed"] = [{"inst_id": i, "index_id": i.split("_")[0], "list_time_utc": iso_ms(l), "eligible_from_utc": iso_ms(l + 30 * DAY_MS),
                                         "ct_val": 0.0001, "tick_sz": 0.1} for i, l in universe]
    for item in document["events"]:
        item.pop("audit_expected", None)
    document["economic"]["audit_break_even_net_bp"] = {}
    rows = [{"instId": i, "uly": i.split("_")[0], "listTime": str(l)} for i, l in universe]
    estimate = g5_fetch.spec_estimate(rows, 30, tape.day_of_date("2026-08-04") * 86_400 * US, {"candles_1m": 300, "mark_1m": 100, "index_1m": 100, "funding_hist": 400})
    document["fetch_plan"]["pre_freeze"]["requests_estimate"] = estimate["funding_hist"]
    for stream in ("candles_1m", "mark_1m", "index_1m"):
        document["fetch_plan"]["post_freeze"][stream]["requests_estimate"] = estimate[stream]
    document["fetch_plan"]["requests_total_estimate"] = sum(estimate.values())
    path.write_text(json.dumps(document, indent=1, ensure_ascii=False))
    return path


def spec_snapshot(path: Path, universe: list[tuple[str, int]], extra=()) -> Path:
    rows = [{"instId": i, "instType": "FUTURES", "ruleType": "xperp", "state": "live", "uly": i.split("_")[0], "ctVal": "0.0001", "tickSz": "0.1", "lotSz": "1", "minSz": "1",
             "listTime": str(l)} for i, l in universe] + list(extra)
    path.write_text(json.dumps({"code": "0", "msg": "", "data": rows}))
    return path


# ----------------------------------------------------------------------------- END_EXCLUSIVE


def test_end_exclusive_is_a_constant_and_rows_at_or_after_it_are_rejected_before_writing(tmp_path: Path):
    assert g5_fetch.END_EXCLUSIVE == "2026-10-14T00:00:00Z" and g5_fetch.END_EXCLUSIVE_US == tape.parse_iso_utc("2026-10-14T00:00:00Z")
    guard = g5_fetch.EndGuard()
    assert guard.admits(g5_fetch.END_EXCLUSIVE_US - 1) and not guard.admits(g5_fetch.END_EXCLUSIVE_US) and not guard.admits(g5_fetch.END_EXCLUSIVE_US + 3 * 86_400 * US)
    assert guard.rejected == 2
    # The parser offers no --end; the start may not reach the constant.
    with pytest.raises(SystemExit):
        cli.build_parser().parse_args(["g5-fetch", "--out", str(tmp_path), "--instruments-file", "x.json", "--end", "2026-10-20T00:00:00Z"])
    # A server that ignores `after` and serves rows across the boundary: nothing whose market time is 2026-10-14 or later reaches the disk.
    series = {("/api/v5/market/history-candles", INST): [candle(ts) for ts in minutes(END_MS - 60 * MINUTE_MS, 120)]}  # 60 before, 60 at/after END
    server = FakeOkx(series, ignore_after=True)
    summary = g5_fetch.Fetcher(make_config(tmp_path / "tape", one_inst(END_MS - 120 * MINUTE_MS), max_pages_per_job=1, limit=300), server, sleep=no_sleep).run()
    assert summary["rejected_at_or_after_end"] == 60 and summary["rows"] == 60
    manifest = tape.read_manifest(tmp_path / "tape")
    assert manifest["kind"] == g5_fetch.MANIFEST_KIND and manifest["end_exclusive"] == g5_fetch.END_EXCLUSIVE and manifest["rejected_at_or_after_end"] == 60
    state = manifest["g5_streams"][f"{INST}:candles_1m"]
    assert state["rejected_at_or_after_end"] == 60 and state["end_reason"] == "paused" and state["complete"] is False
    stored, _ = tape.load_days(tmp_path / "tape", "candles_1m", INST, tape.parse_dates("2026-10-12..2026-10-15"))
    assert stored.size == 60 and int(stored["ts_us"].max()) == g5_fetch.END_EXCLUSIVE_US - 60 * US
    assert not (tmp_path / "tape" / "candles_1m" / INST / f"{INST}-2026-10-14.npz").exists()
    # `fetched_us` is provenance (the local instant of the request), not a market time: the guard does not bear on it.
    assert np.all(stored["fetched_us"] >= g5_fetch.END_EXCLUSIVE_US)
    assert server.calls[0]["after"] == str(END_MS) and server.calls[0]["bar"] == "1m" and server.calls[0]["host"] == "https://eea.okx.com"
    # m1: a second session adds to the manifest totals instead of resetting them (forced from END: the same 60 rows are refused again).
    g5_fetch.Fetcher(make_config(tmp_path / "tape", one_inst(END_MS - 120 * MINUTE_MS), max_pages_per_job=1, limit=300), FakeOkx(series, ignore_after=True), sleep=no_sleep).run(force=True)
    manifest = tape.read_manifest(tmp_path / "tape")
    assert manifest["requests"] == 2 and manifest["rejected_at_or_after_end"] == 60 + 60
    assert [s["requests"] for s in manifest["sessions"]] == [1, 1] and [s["rejected_at_or_after_end"] for s in manifest["sessions"]] == [60, 60]
    assert tape.load_days(tmp_path / "tape", "candles_1m", INST, ["2026-10-13"])[0].size == 60  # the re-fetched rows are de-duplicated


# ----------------------------------------------------------------------------- pagination, end of job, resume


def test_pagination_walks_backwards_and_a_restart_resumes_from_the_cursor_without_loss_or_duplicate(tmp_path: Path):
    n = 1000  # minutes: 2026-10-13T07:20 → 2026-10-14T00:00 (exclusive)
    first_ms = END_MS - n * MINUTE_MS
    rows = one_inst(first_ms)
    series = {("/api/v5/market/history-candles", INST): [candle(ts, 100.0 + k * 0.01) for k, ts in enumerate(minutes(first_ms, n))]}
    server = FakeOkx(series)
    out = tmp_path / "tape"
    summary = g5_fetch.Fetcher(make_config(out, rows, max_pages_per_job=3), server, sleep=no_sleep).run()
    state = tape.read_manifest(out)["g5_streams"][f"{INST}:candles_1m"]
    assert summary["rows"] == 300 and state["complete"] is False and state["pages"] == 3 and state["cursor_after_ms"] == END_MS - 300 * MINUTE_MS
    assert [c["after"] for c in server.calls] == [str(END_MS), str(END_MS - 100 * MINUTE_MS), str(END_MS - 200 * MINUTE_MS)]
    second = g5_fetch.Fetcher(make_config(out, rows), server, sleep=no_sleep)
    summary = second.run()
    state = tape.read_manifest(out)["g5_streams"][f"{INST}:candles_1m"]
    assert state["complete"] is True and state["rows"] == n and summary["errors"] == 0
    assert state["end_reason"] == "reached_start" and state["coverage_start_us"] == first_ms * 1000 and state["truncated"] is False
    assert server.calls[3]["after"] == str(END_MS - 300 * MINUTE_MS) and len(server.calls) == 3 + 7  # the last page reaches the listTime: no empty page needed
    stored, receipts = tape.load_days(out, "candles_1m", INST, ["2026-10-13"])
    assert stored.size == n and np.all(np.diff(stored["ts_us"]) == 60 * US) and int(stored["ts_us"][0]) == first_ms * 1000
    assert all(r["source"] == "final" for r in receipts) and tape.verify(out)["ok"]
    assert tape.read_manifest(out)["files"][f"{INST}:2026-10-13:candles_1m"]["refinalized"] is True
    before = len(server.calls)
    g5_fetch.Fetcher(make_config(out, rows), server, sleep=no_sleep).run()
    assert len(server.calls) == before  # nothing to do
    # m2: --force re-walks a complete job from END_EXCLUSIVE (not from the cursor); rows stay de-duplicated.
    g5_fetch.Fetcher(make_config(out, rows), server, sleep=no_sleep).run(force=True)
    assert server.calls[before]["after"] == str(END_MS) and len(server.calls) == before + 10
    state = tape.read_manifest(out)["g5_streams"][f"{INST}:candles_1m"]
    assert state["forced"] == 1 and state["complete"] is True and tape.load_days(out, "candles_1m", INST, ["2026-10-13"])[0].size == n
    assert (out / g5_fetch.JOURNAL_NAME).exists() and json.loads((out / "instruments-futures.json").read_text())["instruments"][0]["indexId"] == "BTC-USD"
    with pytest.raises(ValueError):  # a directory keeps its kind: not a capture store
        tape.TapeStore(out, "values")


def test_funding_truncation_is_recorded_and_never_read_as_a_complete_history(tmp_path: Path):
    """M1: the funding history is served "up to three months"; the job ends `server_empty_before_start`, `truncated`, with the
    oldest settlement served as coverage start and the next one as D_cov (its predecessor is served) — distinct from a history
    that reaches the listTime. Fetched sealed (P-G5-3): the states live in the sealed manifest, the summary shows totals only."""
    listed_ms = END_MS - 30 * 86_400_000  # listed 30 days before END
    served = [funding_row(END_MS - k * 8 * 3600 * MS) for k in range(1, 31)]  # the server keeps 10 days only
    server = FakeOkx({("/api/v5/public/funding-rate-history", INST): served})
    with pytest.raises(ValueError, match="sealed mode only"):
        g5_fetch.Fetcher(make_config(tmp_path / "open", one_inst(listed_ms), streams=("funding_hist",)), server, sleep=no_sleep)
    summary = g5_fetch.Fetcher(make_config(tmp_path / "t", one_inst(listed_ms), streams=("funding_hist",), sealed=True), server, sleep=no_sleep).run()
    assert tape.read_manifest(tmp_path / "t") is None  # nothing per instrument outside the sealed store
    state = tape.read_manifest(tmp_path / "t" / g5_fetch.SEALED_DIR_NAME)["g5_streams"][f"{INST}:funding_hist"]
    oldest = (END_MS - 30 * 8 * 3600 * MS) * 1000
    assert state["complete"] is True and state["end_reason"] == "server_empty_before_start" and state["truncated"] is True
    assert state["coverage_start_us"] == oldest and state["start_us"] == listed_ms * 1000 and state["d_cov_start_us"] == oldest + 8 * 3600 * US
    assert summary == {"jobs_total": 1, "jobs_complete": 1, "jobs_error": 0, "coverage_start_utc": tape.iso_utc(oldest + 8 * 3600 * US), "mode": "sealed",
                       "receipt": str(tmp_path / "t" / g5_fetch.SEALED_RECEIPT_NAME), "receipt_sha256": summary["receipt_sha256"],
                       "sealed_manifest_sha256": summary["sealed_manifest_sha256"], "sealed_files_digest": summary["sealed_files_digest"]}
    assert server.calls[0]["limit"] == "400" and len(server.calls) == 2  # docs-v5 default page 400; one page, then the empty page
    # A server with no row at all: complete, truncated, no coverage (the evaluator then gives the stream no D_cov).
    empty = FakeOkx({})
    summary = g5_fetch.Fetcher(make_config(tmp_path / "e", one_inst(listed_ms), streams=("funding_hist",), sealed=True), empty, sleep=no_sleep).run()
    state = tape.read_manifest(tmp_path / "e" / g5_fetch.SEALED_DIR_NAME)["g5_streams"][f"{INST}:funding_hist"]
    assert state["end_reason"] == "server_empty_before_start" and state["coverage_start_us"] is None and state["truncated"] is True and state["d_cov_start_us"] is None
    assert summary["coverage_start_utc"] is None and summary["jobs_complete"] == 1
    with pytest.raises(ValueError, match="sealed run fetches"):
        g5_fetch.Fetcher(make_config(tmp_path / "x", one_inst(listed_ms), streams=("candles_1m",), sealed=True), empty, sleep=no_sleep)


def test_a_malformed_page_is_retried_then_fatal_and_never_ends_the_history(tmp_path: Path):
    """M1: `{"code":"0","data":null}` used to close the job as complete with 0 rows; it is now transient, then `malformed_page`."""
    rows = one_inst(END_MS - 10 * MINUTE_MS)
    series = {("/api/v5/market/history-candles", INST): [candle(ts) for ts in minutes(END_MS - 10 * MINUTE_MS, 10)]}
    sleeps: list[float] = []
    server = FakeOkx(series, script=[(200, {"code": "0", "msg": "", "data": None}, None)] * 3)
    summary = g5_fetch.Fetcher(make_config(tmp_path / "a", rows, max_attempts=3, backoff_first_s=1.0), server, sleep=sleeps.append).run()
    state = tape.read_manifest(tmp_path / "a")["g5_streams"][f"{INST}:candles_1m"]
    assert state["complete"] is False and state["end_reason"] == "malformed_page" and state["rows"] == 0 and summary["errors"] == 1 and sleeps == [1.0, 2.0, 4.0]
    # A non-list `data` once, then a good page: the retry recovers and the job completes.
    server = FakeOkx(series, script=[(200, {"code": "0", "msg": "", "data": {"oops": 1}}, None)])
    summary = g5_fetch.Fetcher(make_config(tmp_path / "b", rows), server, sleep=no_sleep).run()
    assert summary["complete"] == 1 and summary["rows"] == 10 and summary["retries"] == 1
    # A page whose every record is unusable is a malformed page too (counted), not an empty history.
    server = FakeOkx(series, script=[(200, {"code": "0", "msg": "", "data": [["bad"], "garbage", []]}, None)])
    g5_fetch.Fetcher(make_config(tmp_path / "c", rows), server, sleep=no_sleep).run()
    state = tape.read_manifest(tmp_path / "c")["g5_streams"][f"{INST}:candles_1m"]
    assert state["end_reason"] == "malformed_page" and state["complete"] is False and state["skipped_records"] == 3
    with pytest.raises(g5_fetch.MalformedPage):
        g5_fetch.parse_body(b'{"code":"0","msg":"","data":null}')
    assert g5_fetch.parse_body(b'{"code":"51001","msg":"x","data":null}') == ("51001", "x", [])  # an error code: data is irrelevant


def test_every_stream_is_fetched_and_the_index_stream_is_shared_by_the_underlying(tmp_path: Path):
    start_ms = END_MS - 30 * MINUTE_MS
    listed_ms = END_MS - 20 * 3600 * MS
    rows = [{"instId": INST, "ruleType": "xperp", "state": "live", "uly": "BTC-USD", "listTime": str(listed_ms)},
            {"instId": "BTC-USD_UM_XPERP-310502", "ruleType": "xperp", "state": "live", "uly": "BTC-USD", "listTime": str(listed_ms + 10 * MINUTE_MS)}]
    series = {("/api/v5/market/history-candles", INST): [candle(ts, confirm=("0" if k == 29 else "1")) for k, ts in enumerate(minutes(start_ms, 30))],
              ("/api/v5/market/history-candles", "BTC-USD_UM_XPERP-310502"): [candle(ts) for ts in minutes(start_ms + 10 * MINUTE_MS, 20)],
              ("/api/v5/market/history-mark-price-candles", INST): [px_candle(ts) for ts in minutes(start_ms, 30)],
              ("/api/v5/market/history-mark-price-candles", "BTC-USD_UM_XPERP-310502"): [px_candle(ts) for ts in minutes(start_ms + 10 * MINUTE_MS, 20)],
              ("/api/v5/market/history-index-candles", "BTC-USD"): [px_candle(ts, 99.0) for ts in minutes(start_ms, 30)],
              ("/api/v5/public/funding-rate-history", INST): [funding_row(END_MS - 8 * 3600 * MS), funding_row(END_MS - 16 * 3600 * MS), funding_row(END_MS)],
              ("/api/v5/public/funding-rate-history", "BTC-USD_UM_XPERP-310502"): []}
    server = FakeOkx(series)
    jobs = g5_fetch.jobs_for(rows, tuple(g5_fetch.STREAMS), "2026-10-13T00:00:00Z")
    assert [j.key for j in jobs] == [f"{INST}:candles_1m", f"{INST}:mark_1m", f"{INST}:funding_hist", "BTC-USD_UM_XPERP-310502:candles_1m", "BTC-USD_UM_XPERP-310502:mark_1m",
                                     "BTC-USD_UM_XPERP-310502:funding_hist", "BTC-USD:index_1m"]
    assert jobs[-1].request_inst_id == "BTC-USD" and jobs[-1].start_us == listed_ms * 1000
    # The candle streams openly, then the funding history sealed (B xix), in the same output directory.
    cfg = make_config(tmp_path / "tape", rows, streams=("candles_1m", "mark_1m", "index_1m"), start="2026-10-13T00:00:00Z")
    summary = g5_fetch.Fetcher(cfg, server, sleep=no_sleep).run()
    assert summary["complete"] == 5 and summary["errors"] == 0 and summary["rejected_at_or_after_end"] == 0
    sealed = g5_fetch.Fetcher(make_config(tmp_path / "tape", rows, streams=("funding_hist",), start="2026-10-13T00:00:00Z", sealed=True), server, sleep=no_sleep).run()
    assert sealed["jobs_complete"] == 2 and sealed["jobs_error"] == 0
    states = tape.read_manifest(tmp_path / "tape")["g5_streams"]
    assert states[f"{INST}:candles_1m"]["unconfirmed_rows"] == 1  # stored, counted; the evaluator drops it
    assert states[f"{INST}:candles_1m"]["end_reason"] == "server_empty_before_start" and states[f"{INST}:candles_1m"]["coverage_start_us"] == start_ms * 1000
    assert f"{INST}:funding_hist" not in states and f"{INST}:funding_hist" in tape.read_manifest(tmp_path / "tape" / g5_fetch.SEALED_DIR_NAME)["g5_streams"]
    index_rows, _ = tape.load_days(tmp_path / "tape", "index_1m", "BTC-USD", ["2026-10-13"])
    assert index_rows.size == 30 and index_rows["close"][0] == pytest.approx(99.1)
    funding, _ = tape.load_days(tmp_path / "tape" / g5_fetch.SEALED_DIR_NAME, "funding_hist", INST, ["2026-10-13"])
    assert funding.size == 2 and funding["method"].tolist() == [1, 1] and funding["realized_rate"][0] == pytest.approx(0.00009)
    assert not (tmp_path / "tape" / "funding_hist").exists()
    assert {c["path"] for c in server.calls} == {spec.path for spec in g5_fetch.STREAMS.values()}
    assert all("bar" not in c for c in server.calls if c["path"].endswith("funding-rate-history"))


# ----------------------------------------------------------------------------- rate limit, backoff, budget


def test_rate_limiter_holds_the_request_back_when_the_sliding_window_is_full():
    clock = {"t": 0.0}
    sleeps: list[float] = []

    def fake_sleep(seconds: float) -> None:
        sleeps.append(seconds)
        clock["t"] += seconds

    limiter = g5_fetch.RateLimiter(20, 2.0, scale=1.0, clock=lambda: clock["t"], sleep=fake_sleep)
    for _ in range(20):
        limiter.acquire()
    assert sleeps == [] and limiter.waits == 0
    limiter.acquire()
    assert sleeps == [pytest.approx(2.0)] and limiter.waits == 1 and limiter.waited_s == pytest.approx(2.0)
    clock["t"] += 10.0
    limiter.acquire()
    assert len(sleeps) == 1
    scaled = g5_fetch.RateLimiter(20, 2.0, scale=0.8, clock=lambda: clock["t"], sleep=fake_sleep)
    assert scaled.capacity == 16 and g5_fetch.RateLimiter(10, 2.0, scale=0.8).capacity == 8
    assert scaled.min_duration_s(16) == 0.0 and scaled.min_duration_s(17) == pytest.approx(2.0) and scaled.min_duration_s(160) == pytest.approx(18.0)
    with pytest.raises(ValueError):
        g5_fetch.RateLimiter(20, 2.0, scale=1.5)
    assert {k: (v.rate_requests, v.rate_window_s) for k, v in g5_fetch.STREAMS.items()} == {"candles_1m": (20, 2.0), "mark_1m": (20, 2.0), "index_1m": (10, 2.0), "funding_hist": (10, 2.0)}
    assert {k: v.limit_max for k, v in g5_fetch.STREAMS.items()} == {"candles_1m": 300, "mark_1m": 100, "index_1m": 100, "funding_hist": 400}
    assert g5_fetch.STREAMS["funding_hist"].limit_default == 400


def test_backoff_retry_codes_retry_after_and_fatal_codes(tmp_path: Path):
    start_ms = END_MS - 10 * MINUTE_MS
    rows = one_inst(start_ms)
    series = {("/api/v5/market/history-candles", INST): [candle(ts) for ts in minutes(start_ms, 10)]}
    sleeps: list[float] = []
    summary = g5_fetch.Fetcher(make_config(tmp_path / "a", rows, backoff_first_s=2.0), FakeOkx(series, fail_first=2), sleep=sleeps.append).run()
    assert summary["complete"] == 1 and summary["retries"] == 2 and summary["rows"] == 10 and sleeps == [2.0, 4.0]
    # m3: 50001 (HTTP 503), 50004 (served with HTTP 400), 50026 (HTTP 500) and 50013 with HTTP 200 are all retried; Retry-After is honoured (capped).
    sleeps.clear()
    script = [(503, {"code": "50001", "msg": "Service temporarily unavailable", "data": []}, 7.0), (400, {"code": "50004", "msg": "API endpoint request timeout", "data": []}, None),
              (500, {"code": "50026", "msg": "System error", "data": []}, 999.0), (200, {"code": "50013", "msg": "Systems are busy", "data": []}, None)]
    server = FakeOkx(series, script=script)
    summary = g5_fetch.Fetcher(make_config(tmp_path / "b", rows, backoff_first_s=1.0, backoff_max_s=60.0), server, sleep=sleeps.append).run()
    assert summary["complete"] == 1 and summary["retries"] == 4 and sleeps == [7.0, 2.0, g5_fetch.RETRY_AFTER_CAP_S, 8.0]
    # Too many consecutive failures: `max_attempts`, the job is not complete.
    sleeps.clear()
    summary = g5_fetch.Fetcher(make_config(tmp_path / "c", rows, max_attempts=3, backoff_first_s=1.0, backoff_max_s=2.0), FakeOkx(series, fail_first=99), sleep=sleeps.append).run()
    state = tape.read_manifest(tmp_path / "c")["g5_streams"][f"{INST}:candles_1m"]
    assert summary["errors"] == 1 and state["errors"][0]["error"] == "max_attempts" and state["end_reason"] == "error" and state["complete"] is False and sleeps == [1.0, 2.0, 2.0]
    # A fatal OKX code (unknown instrument), and a plain HTTP 400 without a retry code, are not retried.
    server = FakeOkx(series, fatal_code="51001")
    summary = g5_fetch.Fetcher(make_config(tmp_path / "d", rows), server, sleep=sleeps.append).run()
    assert summary["errors"] == 1 and len(server.calls) == 1 and tape.read_manifest(tmp_path / "d")["g5_streams"][f"{INST}:candles_1m"]["errors"][0]["error"].startswith("code_51001")
    server = FakeOkx(series, script=[(400, {"code": "51000", "msg": "Parameter error", "data": []}, None)])
    summary = g5_fetch.Fetcher(make_config(tmp_path / "e", rows), server, sleep=sleeps.append).run()
    assert summary["errors"] == 1 and len(server.calls) == 1
    assert g5_fetch._retry_after({"Retry-After": "3"}) == 3.0 and g5_fetch._retry_after({"Retry-After": "Wed, 21 Oct 2026 07:28:00 GMT"}) is None and g5_fetch._retry_after(None) is None


def test_the_request_budget_stops_the_run_and_the_real_run_preconditions(tmp_path: Path):
    """M3: every request (retries included) is capped by max_requests; a Fetcher without decision_ref, max_requests or plan_sha256,
    or on the global host without a written reason, is refused before anything is built."""
    start_ms = END_MS - 1000 * MINUTE_MS
    rows = [*one_inst(start_ms), {"instId": INST2, "ruleType": "xperp", "state": "live", "uly": "ETH-USD", "listTime": str(start_ms)}]
    series = {("/api/v5/market/history-candles", INST): [candle(ts) for ts in minutes(start_ms, 1000)],
              ("/api/v5/market/history-candles", INST2): [candle(ts) for ts in minutes(start_ms, 1000)]}
    server = FakeOkx(series)
    summary = g5_fetch.Fetcher(make_config(tmp_path / "a", rows, max_requests=4), server, sleep=no_sleep).run()
    states = tape.read_manifest(tmp_path / "a")["g5_streams"]
    assert len(server.calls) == 4 and summary["budget_exhausted"] is True and states[f"{INST}:candles_1m"]["end_reason"] == "max_requests_reached"
    assert f"{INST2}:candles_1m" not in states  # the run stopped: the next job never started
    for bad in (dict(decision_ref=""), dict(max_requests=None), dict(max_requests=0), dict(plan_sha256=""), dict(plan_sha256="not-a-sha"), dict(host="global")):
        with pytest.raises(ValueError):
            g5_fetch.Fetcher(make_config(tmp_path / "b", rows, **bad), server)
    assert not (tmp_path / "b").exists()
    fetcher = g5_fetch.Fetcher(make_config(tmp_path / "c", rows, host="global", host_reason="test: documented exception"), FakeOkx(series), sleep=no_sleep)
    fetcher.lock.release()
    manifest = tape.read_manifest(tmp_path / "c")
    assert manifest["source"]["host_reason"] == "test: documented exception" and manifest["source"]["plan_sha256"] == PLAN_SHA and manifest["source"]["max_requests"] == 10_000


# ----------------------------------------------------------------------------- dry run and command line


def test_dry_run_lists_the_requests_and_writes_nothing(tmp_path: Path, capsys):
    file = snapshot(tmp_path / "eea-instruments-FUTURES-20261016.json")
    out = tmp_path / "tape"
    plan_out = tmp_path / "plan.json"
    assert cli.main(["g5-fetch", "--out", str(out), "--instruments-file", str(file), "--dry-run", "--plan-out", str(plan_out)]) == 0
    plan = json.loads(plan_out.read_text())
    printed = json.loads(capsys.readouterr().out)
    assert printed["jobs"] == plan["jobs"] and plan["dry_run"] is True and plan["host"] == "https://eea.okx.com" and plan["end_exclusive"] == g5_fetch.END_EXCLUSIVE
    assert plan["instruments"] == [INST, INST2]
    # m4: the snapshot's date is read from its file name and flagged when it falls inside the quarantine.
    assert plan["snapshot"]["snapshot_date"] == "2026-10-16" and plan["snapshot"]["in_quarantine"] is True and "re-freeze" in plan["snapshot"]["note"]
    keys = [(j["inst_id"], j["stream"]) for j in plan["jobs"]]
    assert keys == [(INST, "candles_1m"), (INST, "mark_1m"), (INST, "funding_hist"), (INST2, "candles_1m"), (INST2, "mark_1m"), (INST2, "funding_hist"), ("BTC-USD", "index_1m"), ("ETH-USD", "index_1m")]
    first = plan["jobs"][0]
    assert first["first_url"] == f"https://eea.okx.com/api/v5/market/history-candles?instId={INST}&bar=1m&after={END_MS}&limit=100" and first["limit"] == 100
    assert plan["jobs"][2]["limit"] == 400  # funding history: docs-v5 default and maximum
    span_minutes = (END_MS - LIST_MS) // MINUTE_MS
    assert first["estimated_pages"] == math.ceil(span_minutes / 100) + 1 and first["start"] == "2026-03-30T04:30:00.000000Z"
    assert plan["requests_total"] == sum(plan["requests_estimated"].values()) and plan["min_duration_s_sequential"] > 0
    assert plan["rate_limits"]["index_1m"] == {"documented": "10 req / 2 s (IP)", "used_capacity": 8}
    assert not out.exists()
    assert g5_fetch.snapshot_info(tmp_path / "snapshot.json")["snapshot_date"] == "UNKNOWN"
    assert cli.main(["g5-fetch", "--out", str(out), "--instruments-file", str(file), "--dry-run", "--limit", "1000", "--streams", "candles_1m,mark_1m"]) == 0
    plan = json.loads(capsys.readouterr().out)
    assert {j["stream"]: j["limit"] for j in plan["jobs"]} == {"candles_1m": 300, "mark_1m": 100}
    with pytest.raises(SystemExit):
        cli.main(["g5-fetch", "--out", str(out), "--instruments-file", str(file), "--dry-run", "--start", "2026-10-14T00:00:00Z"])
    with pytest.raises(SystemExit):
        cli.main(["g5-fetch", "--out", str(out), "--instruments-file", str(file), "--dry-run", "--streams", "books5"])
    assert cli.main(["g5-fetch", "--out", str(out), "--instruments-file", str(file), "--dry-run", "--host", "global", "--inst-ids", INST2]) == 0  # a dry run connects to nothing
    plan = json.loads(capsys.readouterr().out)
    assert plan["host"] == "https://www.okx.com" and plan["instruments"] == [INST2] and [j["inst_id"] for j in plan["jobs"]][-1] == "ETH-USD"


def test_a_real_run_needs_the_spec_the_decision_the_instruments_the_budget_and_the_matching_plan(tmp_path: Path, capsys):
    """M3, (B) xix: a real run is driven by the G5 spec and its phase; every refusal happens before any client exists (the autouse
    fixture would fail on a connection): exit 2 for the command-level gates, SystemExit for the spec-level ones."""
    list_ms = END_MS - 30 * DAY_MS - MINUTE_MS  # eligible one minute before END: candles, mark and index from END − 1 day − 1 min
    universe = [(INST, list_ms)]
    spec_path = derived_spec(tmp_path / "spec.json", universe)
    file = spec_snapshot(tmp_path / "snapshot.json", universe, extra=[{"instId": INST2, "instType": "FUTURES", "ruleType": "xperp", "state": "live", "uly": "ETH-USD",
                                                                      "listTime": str(list_ms)}])
    coverage_us = tape.parse_iso_utc("2026-08-04T00:00:00Z")
    receipt = tmp_path / "receipt.json"
    receipt.write_text(json.dumps({"kind": g5_fetch.RECEIPT_KIND, "end_exclusive": g5_fetch.END_EXCLUSIVE, "coverage_start_us": coverage_us, "coverage_start_utc": "2026-08-04T00:00:00Z"}))
    out = tmp_path / "tape"
    base = ["g5-fetch", "--out", str(out), "--instruments-file", str(file), "--quiet"]
    spec_args = ["--spec", str(spec_path), "--phase", "post_freeze", "--funding-receipt", str(receipt)]
    plan_path = tmp_path / "plan.json"
    assert cli.main(base + spec_args + ["--inst-ids", INST, "--dry-run", "--plan-out", str(plan_path)]) == 0
    capsys.readouterr()
    plan = json.loads(plan_path.read_text())
    start_ms = END_MS - DAY_MS - MINUTE_MS
    assert plan["requests_total"] == 5 + 15 + 15 and plan["mode"] == "values" and plan["spec"]["phase"] == "post_freeze" and plan["inst_ids_sha256"] == g5_fetch.inst_ids_digest([INST])
    assert {j["stream"]: j["start"] for j in plan["jobs"]} == {s: tape.iso_utc(start_ms * 1000) for s in ("candles_1m", "mark_1m", "index_1m")}
    assert {j["stream"]: j["limit"] for j in plan["jobs"]} == {"candles_1m": 300, "mark_1m": 100, "index_1m": 100}  # the spec's pages
    total = plan["requests_total"]

    def refused(extra: list[str]) -> None:
        assert g5_fetch.command_fetch(cli.build_parser().parse_args(base + extra), client_factory=lambda: pytest.fail("a client was built")) == 2
        assert not out.exists()

    def refused_early(extra: list[str], match: str) -> None:
        with pytest.raises(SystemExit, match=match):
            g5_fetch.command_fetch(cli.build_parser().parse_args(base + extra), client_factory=lambda: pytest.fail("a client was built"))
        assert not out.exists()

    ok = spec_args + ["--inst-ids", INST, "--plan", str(plan_path), "--max-requests", "100"]
    refused(ok)  # no decision
    refused(["--inst-ids", INST, "--plan", str(plan_path), "--max-requests", "100", "--decision-ref", "t", "--streams", "candles_1m"])  # no spec
    refused(spec_args + ["--decision-ref", "t", "--inst-ids", INST, "--max-requests", "100"])  # no plan
    refused(spec_args + ["--decision-ref", "t", "--inst-ids", INST, "--plan", str(plan_path)])  # no budget
    refused(spec_args + ["--decision-ref", "t", "--inst-ids", INST, "--plan", str(plan_path), "--max-requests", str(total - 1)])  # the plan exceeds the budget
    refused_early(spec_args + ["--decision-ref", "t", "--plan", str(plan_path), "--max-requests", "100"], "universe")  # no explicit instruments
    refused_early(spec_args + ["--decision-ref", "t", "--inst-ids", f"{INST},{INST2}", "--plan", str(plan_path), "--max-requests", "100"], "universe")  # not the universe
    refused_early(spec_args + ["--decision-ref", "t", "--inst-ids", INST, "--plan", str(plan_path), "--max-requests", "100", "--host", "global", "--host-reason", "x"], "host")
    refused_early(spec_args + ["--decision-ref", "t", "--inst-ids", INST, "--plan", str(plan_path), "--max-requests", "100", "--max-instruments", "1"], "max-instruments")
    refused_early(["--spec", str(spec_path), "--phase", "post_freeze", "--decision-ref", "t", "--inst-ids", INST, "--plan", str(plan_path), "--max-requests", "100"], "funding-receipt")
    refused_early(["--phase", "post_freeze", "--decision-ref", "t", "--inst-ids", INST, "--plan", str(plan_path), "--max-requests", "100"], "need --spec")
    refused_early(spec_args + ["--decision-ref", "t", "--inst-ids", INST, "--plan", str(plan_path), "--max-requests", "100", "--streams", "candles_1m"], "phase fetches")
    other_plan = tmp_path / "plan-pre.json"
    assert cli.main(base + ["--spec", str(spec_path), "--phase", "pre_freeze", "--inst-ids", INST, "--dry-run", "--plan-out", str(other_plan)]) == 0
    capsys.readouterr()
    refused(spec_args + ["--decision-ref", "t", "--inst-ids", INST, "--plan", str(other_plan), "--max-requests", "100"])  # the plan of another phase
    moments = minutes(start_ms, 1441)
    series = {("/api/v5/market/history-candles", INST): [candle(ts) for ts in moments], ("/api/v5/market/history-mark-price-candles", INST): [px_candle(ts) for ts in moments],
              ("/api/v5/market/history-index-candles", "BTC-USD"): [px_candle(ts, 99.0) for ts in moments]}
    server = FakeOkx(series)
    ids_file = tmp_path / "ids.txt"
    ids_file.write_text(f"# G5 universe (test)\n{INST}\n")
    args = cli.build_parser().parse_args(base + spec_args + ["--inst-ids-file", str(ids_file), "--plan", str(plan_path), "--max-requests", "100", "--decision-ref",
                                                             "test only: scale confirmed"])
    assert g5_fetch.command_fetch(args, client_factory=lambda: server) == 0
    summary = json.loads(capsys.readouterr().out)
    assert summary["rows"] == 3 * 1441 and summary["complete"] == 3 and len(server.calls) == 35
    manifest = tape.read_manifest(out)
    src = manifest["source"]
    assert src["decision_ref"] == "test only: scale confirmed" and src["plan_sha256"] == g5_fetch.sha256_bytes(plan_path.read_bytes()) and src["max_requests"] == 100
    assert src["inst_ids_sha256"] == g5_fetch.inst_ids_digest([INST]) and src["inst_ids_source"].startswith(f"file {ids_file} sha256=") and src["mode"] == "values"
    assert src["spec"]["sha256"] == g5_fetch.sha256_bytes(spec_path.read_bytes()) and src["spec"]["phase"] == "post_freeze" and src["dataset_id"] == "okx-xperp-1m-history-2026-03-30..2026-10-13"
    assert src["coverage_start"].startswith("2026-08-04T00:00:00") and src["funding_receipt"]["sha256"] == g5_fetch.sha256_bytes(receipt.read_bytes())
    assert manifest["sessions"][-1]["requests"] == 35 and manifest["requests"] == 35 and manifest["g5_streams"][f"{INST}:candles_1m"]["end_reason"] == "reached_start"
    held = tape.DirectoryLock(out).acquire()
    try:
        assert g5_fetch.command_fetch(args, client_factory=lambda: server) == 3
    finally:
        held.release()


def test_per_stream_starts_and_the_spec_request_estimates(tmp_path: Path, capsys):
    """LP-G5 §4.3: candles from eligibility − 1 day, mark and index from max(D_cov − 1 day, eligibility − 1 day), the funding history
    from listTime; the estimates of the real spec (13 023 / 22 606 / 22 606 / 50 requests) are reproduced from its own universe."""
    listed = tape.parse_iso_utc("2026-05-20T08:00:00Z") // 1000
    row = one_inst(listed)[0]
    late, early = tape.parse_iso_utc("2026-08-04T00:00:00Z"), tape.parse_iso_utc("2026-05-01T00:00:00Z")
    starts = {j.stream: j.start_us for j in g5_fetch.jobs_for([row], tuple(g5_fetch.STREAMS), g5_fetch.DEFAULT_START, 30, late)}
    eligible_minus_day = (listed + 29 * DAY_MS) * 1000
    assert starts == {"candles_1m": eligible_minus_day, "mark_1m": late - 86_400 * US, "funding_hist": listed * 1000, "index_1m": late - 86_400 * US}
    starts = {j.stream: j.start_us for j in g5_fetch.jobs_for([row], tuple(g5_fetch.STREAMS), g5_fetch.DEFAULT_START, 30, early)}
    assert starts["mark_1m"] == starts["index_1m"] == eligible_minus_day
    assert {j.stream: j.start_us for j in g5_fetch.jobs_for([row], tuple(g5_fetch.STREAMS))}["candles_1m"] == listed * 1000  # without the rule: listTime (unit tests)
    with pytest.raises(ValueError, match="coverage_start_us"):
        g5_fetch.jobs_for([row], ("mark_1m",), g5_fetch.DEFAULT_START, 30, None)
    with pytest.raises(ValueError, match="never fetched"):
        g5_fetch.jobs_for(one_inst(END_MS - 10 * DAY_MS), ("candles_1m",), g5_fetch.DEFAULT_START, 30, late)
    from scripts.research_lp import g5_eval

    spec, _ = g5_eval.load_spec(REAL_SPEC)
    assert spec.fetch_plan["estimates"] == {"funding_hist": 50, "candles_1m": 13023, "mark_1m": 22606, "index_1m": 22606} and spec.fetch_plan["total"] == 58285
    universe = [(i.inst_id, i.list_time_us // 1000) for i in spec.universe]
    file = spec_snapshot(tmp_path / "real-universe-metadata.json", universe)
    ids = ",".join(i for i, _ in universe)
    base = ["g5-fetch", "--out", str(tmp_path / "x"), "--instruments-file", str(file), "--inst-ids", ids, "--spec", str(REAL_SPEC), "--dry-run"]
    assert cli.main(base + ["--phase", "pre_freeze"]) == 0
    pre = json.loads(capsys.readouterr().out)
    assert pre["requests_total"] == 50 and pre["mode"] == "sealed" and {j["stream"] for j in pre["jobs"]} == {"funding_hist"} and pre["spec"]["status"] == "DRAFT_NOT_FROZEN"
    receipt = tmp_path / "receipt.json"
    receipt.write_text(json.dumps({"kind": g5_fetch.RECEIPT_KIND, "end_exclusive": g5_fetch.END_EXCLUSIVE, "coverage_start_us": tape.parse_iso_utc("2026-08-04T00:00:00Z")}))
    assert cli.main(base + ["--phase", "post_freeze", "--funding-receipt", str(receipt)]) == 0
    post = json.loads(capsys.readouterr().out)
    assert post["requests_estimated"] == {"candles_1m": 13023, "mark_1m": 22606, "index_1m": 22606, "funding_hist": 0} and post["requests_total"] == 58285 - 50
    assert not (tmp_path / "x").exists()


def test_sealed_mode_shows_only_global_totals_and_seals_the_per_instrument_manifest(tmp_path: Path, capsys):
    """P-G5-3, (B) xix: the pre-freeze funding fetch prints and logs the global totals and D_cov only; the per-instrument manifest
    (rows, oldest / newest, pages) is in the sealed store, hashed in the public receipt; nothing names an instrument outside it."""
    l1, l2 = END_MS - 40 * DAY_MS, END_MS - 35 * DAY_MS
    universe = [(INST, l1), (INST2, l2)]
    spec_path = derived_spec(tmp_path / "spec.json", universe)
    file = spec_snapshot(tmp_path / "snapshot.json", universe)
    out = tmp_path / "run"
    base = ["g5-fetch", "--out", str(out), "--instruments-file", str(file), "--spec", str(spec_path), "--phase", "pre_freeze"]
    ids_file = tmp_path / "ids.txt"
    ids_file.write_text(f"{INST2}\n{INST}\n")
    plan_path = tmp_path / "plan.json"
    assert cli.main(base + ["--inst-ids-file", str(ids_file), "--dry-run", "--plan-out", str(plan_path)]) == 0
    capsys.readouterr()
    assert json.loads(plan_path.read_text())["requests_total"] == 4
    eight_h = 8 * 3600 * MS
    served = {("/api/v5/public/funding-rate-history", INST): [funding_row(END_MS - k * eight_h, 0.0002 * (k % 3)) for k in range(1, 31)],  # truncated: 10 days
              ("/api/v5/public/funding-rate-history", INST2): [funding_row(END_MS - k * eight_h) for k in range(1, 3 * 35)]}  # from listTime + 8 h
    server = FakeOkx(served)
    args = cli.build_parser().parse_args(base + ["--inst-ids-file", str(ids_file), "--plan", str(plan_path), "--max-requests", "10", "--decision-ref", "test: synthetic"])
    assert g5_fetch.command_fetch(args, client_factory=lambda: server) == 0
    captured = capsys.readouterr()
    printed = json.loads(captured.out)
    assert set(printed) == set(g5_fetch.SEALED_PRINTED) | {"mode", "receipt", "receipt_sha256", "sealed_manifest_sha256", "sealed_files_digest"}
    assert printed["jobs_total"] == 2 and printed["jobs_complete"] == 2 and printed["jobs_error"] == 0
    d_cov_inst = (END_MS - 29 * eight_h) * 1000  # second oldest settlement served of the truncated job
    assert printed["coverage_start_utc"] == tape.iso_utc(d_cov_inst)
    journal = (out / g5_fetch.JOURNAL_NAME).read_text()
    receipt_text = (out / g5_fetch.SEALED_RECEIPT_NAME).read_text()
    for text in (captured.out, captured.err, journal, receipt_text):
        assert INST not in text and INST2 not in text and "BTC-USD" not in text and "ETH-USD" not in text
        assert '"rows"' not in text and "rows=" not in text and "pages" not in text
    events = [line.split("event=")[1].split()[0] for line in journal.splitlines()]
    assert set(events) <= set(g5_fetch.SEALED_JOURNAL) and "sealed_end" in events and "job_end" not in events
    for line in journal.splitlines():
        event = line.split("event=")[1].split()[0]
        fields = [part.split("=", 1)[0] for part in line.split()[3:] if "=" in part]
        assert set(fields) <= set(g5_fetch.SEALED_JOURNAL[event]), line
    receipt = json.loads(receipt_text)
    sealed_dir = out / g5_fetch.SEALED_DIR_NAME
    assert receipt["sealed_manifest_sha256"] == tape.sha256_of(sealed_dir / tape.MANIFEST_NAME) == printed["sealed_manifest_sha256"]
    assert receipt["sealed_files_digest"] == g5_fetch.directory_digest(sealed_dir)[0] and printed["receipt_sha256"] == tape.sha256_of(out / g5_fetch.SEALED_RECEIPT_NAME)
    assert receipt["inst_ids_sha256"] == g5_fetch.inst_ids_digest([INST, INST2]) and receipt["register_status"] == "unread" and receipt["coverage_start_us"] == d_cov_inst
    sealed = tape.read_manifest(sealed_dir)["g5_streams"]
    assert sealed[f"{INST}:funding_hist"]["rows"] == 30 and sealed[f"{INST2}:funding_hist"]["rows"] == 104 and sealed[f"{INST}:funding_hist"]["pages"] == 2
    assert not (out / tape.MANIFEST_NAME).exists() and not (out / "funding_hist").exists()
    # The receipt is what the post-freeze phase reads: D_cov = coverage_start_utc.
    doc, sha = g5_fetch.read_receipt(out / g5_fetch.SEALED_RECEIPT_NAME)
    assert doc["coverage_start_us"] == d_cov_inst and sha == printed["receipt_sha256"]
    with pytest.raises(SystemExit):
        bad = tmp_path / "bad-receipt.json"
        bad.write_text(json.dumps({"kind": "other"}))
        g5_fetch.read_receipt(bad)


# ----------------------------------------------------------------------------- metadata and parsing


def test_instruments_snapshot_selection_and_index_id_deduction(tmp_path: Path):
    file = snapshot(tmp_path / "snapshot.json")
    assert [r["instId"] for r in g5_fetch.load_instruments(file)] == [INST, INST2]
    assert [r["instId"] for r in g5_fetch.load_instruments(file, only_live=False)] == [INST, INST2, "SOL-USD_UM_XPERP-310404"]
    assert [r["instId"] for r in g5_fetch.load_instruments(file, rule_type=None)] == [INST, INST2, "NEW-USD_UM_XPERP-310404"]
    assert [r["instId"] for r in g5_fetch.load_instruments(file, family="dated", rule_type=None)] == ["BTC-USD_UM-261226"] and g5_fetch.load_instruments(file, family="dated") == []
    assert [r["instId"] for r in g5_fetch.load_instruments(file, inst_ids=[INST2])] == [INST2] and len(g5_fetch.load_instruments(file, max_instruments=1)) == 1
    with pytest.raises(SystemExit):
        g5_fetch.load_instruments(file, inst_ids=["XRP-USD_UM_XPERP-310404"])
    assert g5_fetch.index_id_of({"instId": INST, "uly": "BTC-USD"}) == "BTC-USD" and g5_fetch.index_id_of({"instId": "SOL-USD_UM_XPERP-310404"}) == "SOL-USD"
    assert g5_fetch.index_id_of({"instId": "BTC-USD_UM-261226", "uly": ""}) == "BTC-USD"
    with pytest.raises(ValueError):
        g5_fetch.index_id_of({"instId": "BTC-USDT-SWAP"})
    with pytest.raises(SystemExit):
        g5_fetch.load_instruments(snapshot(tmp_path / "nolist.json", rows=[{"instId": INST, "ruleType": "xperp", "state": "live", "uly": "BTC-USD"}]))
    (tmp_path / "cap.json").write_text(json.dumps({"instruments": [{"instId": INST, "state": "live", "listTime": "1"}]}))
    assert [r["instId"] for r in g5_fetch.load_instruments(tmp_path / "cap.json")] == [INST]
    dup = tmp_path / "dup.txt"
    dup.write_text(f"{INST}\n{INST}\n")
    with pytest.raises(SystemExit):
        g5_fetch.read_inst_ids_file(dup)


def test_rows_from_page_counts_short_and_malformed_records():
    ts = END_MS - MINUTE_MS
    rows, skipped = g5_fetch.rows_from_page(g5_fetch.STREAMS["candles_1m"], [candle(ts, 50.0), ["bad"], "garbage", [str(ts - MINUTE_MS), "1", "2"]], fetched_us=7)
    assert len(rows) == 2 and skipped == 2 and rows[0][0] == ts * 1000 and rows[0][1][:4] == (ts * 1000, 7, 50.0, 50.5) and rows[0][1][-1] == 1
    short = rows[1][1]
    assert short[2] == 1.0 and math.isnan(short[4]) and math.isnan(short[6]) and short[-1] == -1
    px, skipped = g5_fetch.rows_from_page(g5_fetch.STREAMS["mark_1m"], [px_candle(ts, 10.0)], fetched_us=1)
    assert px == [(ts * 1000, (ts * 1000, 1, 10.0, 10.5, 9.5, 10.1, 1))] and skipped == 0
    funding, skipped = g5_fetch.rows_from_page(g5_fetch.STREAMS["funding_hist"], [funding_row(ts), {"fundingTime": "", "fundingRate": "1"}, {"fundingTime": str(ts), "method": "weird"}], fetched_us=1)
    assert len(funding) == 2 and skipped == 1 and funding[0][1] == (ts * 1000, 1, 0.0001, pytest.approx(0.00009), 1, 1) and funding[1][1][4:] == (-1, -1) and math.isnan(funding[1][1][2])
    assert np.array([r for _, r in rows], dtype=tape.CANDLES_1M_DTYPE).size == 2
    code, msg, data = g5_fetch.parse_body(b'{"code":"50011","msg":"Rate limit reached","data":[]}')
    assert code == "50011" and data == []
    with pytest.raises(g5_fetch.TransientError):
        g5_fetch.parse_body(b"<html>")
    assert g5_fetch.build_url("eea", g5_fetch.STREAMS["funding_hist"], INST, END_MS, 400) == f"https://eea.okx.com/api/v5/public/funding-rate-history?instId={INST}&after={END_MS}&limit=400"


def test_no_test_can_open_a_socket():
    """m6: the guard is at the socket level, not only on the fetcher's client."""
    with pytest.raises(AssertionError):
        socket.create_connection(("127.0.0.1", 9))
    with pytest.raises(AssertionError):
        socket.socket().connect(("127.0.0.1", 9))
    with pytest.raises(AssertionError):
        urllib.request.urlopen("https://eea.okx.com/api/v5/public/time")
