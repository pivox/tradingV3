"""Tests of the L2 capture pilot (`scripts/research_l2`) on synthetic frames only.

No network: an autouse guard refuses every socket connection and name resolution; the WebSocket loop is driven by an injected
fake connector, the REST instruments call is never made. Clocks, byte counters and free space are simulated.
"""

from __future__ import annotations

import asyncio
import dataclasses
import gzip
import json
import os
import socket
import time
from pathlib import Path

import pytest

from scripts.research_l2 import book as bk
from scripts.research_l2 import cli as l2cli
from scripts.research_l2 import pilot as pl
from scripts.research_l2 import replay as rp
from scripts.research_lp import cli as lp_cli
from scripts.research_lp import tape
from scripts.research_lp import xperp_capture as xc

BTC = "BTC-USD_UM_XPERP-310404"
ETH = "ETH-USD_UM_XPERP-310404"
GIB = 1 << 30
START_NS = tape.parse_iso_utc("2026-11-02T00:00:00Z") * 1000  # spec v2 (P-L2-1): the reserved window starts here (2026-11-03 before, adapted)
HARD_END_NS = tape.parse_iso_utc("2026-11-03T00:00:00Z") * 1000
BEFORE_NS = tape.parse_iso_utc("2026-11-01T23:59:59Z") * 1000
V1_SPEC = pl.DEFAULT_SPEC.with_name("l2_pilot_spec_v1.json")
V1_SHA256 = "16b1a6941f539c0a5a918d1a811ebbf6c291aedfe784f536a29ad675d52515a3"
SEPARATION_REF = "P-L2-1 (D14, owner 2026-10-22)"
PRICE_MARK = "98765.4321"  # a distinctive price string that must never leave the quality summary


# ----------------------------------------------------------------------------- no network


@pytest.fixture(autouse=True)
def _no_network(monkeypatch):
    def refuse(*args, **kwargs):
        raise AssertionError(f"network access attempted: {args!r}")

    monkeypatch.setattr(socket.socket, "connect", refuse)
    monkeypatch.setattr(socket.socket, "connect_ex", refuse)
    monkeypatch.setattr(socket.socket, "sendto", refuse)
    monkeypatch.setattr(socket, "create_connection", refuse)
    monkeypatch.setattr(socket, "getaddrinfo", refuse)
    monkeypatch.setattr(pl, "fetch_instruments_raw", refuse)
    monkeypatch.setattr(pl, "default_connector", refuse)


# ----------------------------------------------------------------------------- fixtures


class FakeClock:
    def __init__(self, wall_ns: int = START_NS) -> None:
        self.wall = wall_ns
        self.mono = 10**12

    def wall_ns(self) -> int:
        return self.wall

    def mono_ns(self) -> int:
        return self.mono

    def advance(self, seconds: float) -> None:
        self.wall += int(seconds * 1e9)
        self.mono += int(seconds * 1e9)


class RealtimeClock(FakeClock):
    """Real monotonic time (the asyncio loop sleeps for real), wall clock shifted to an authorized day."""

    def __init__(self) -> None:
        super().__init__()
        self.t0 = time.monotonic_ns()

    def wall_ns(self) -> int:
        return START_NS + time.monotonic_ns() - self.t0

    def mono_ns(self) -> int:
        return time.monotonic_ns()


@pytest.fixture
def before_floor(monkeypatch):
    """The command-line tests see a wall clock before 2026-11-02, whatever the day they run."""
    monkeypatch.setattr(pl.SystemClock, "wall_ns", lambda self: BEFORE_NS)


def instrument_rows() -> list[dict]:
    base = {"instType": "FUTURES", "state": "live", "ctVal": "0.0001", "ctMult": "1", "ctValCcy": "BTC", "settleCcy": "USDC", "tickSz": "0.1", "lotSz": "1",
            "minSz": "1", "ctType": "linear", "listTime": "1743292800000", "expTime": "", "instFamily": "BTC-USD_UM"}
    return [
        {**base, "instId": BTC, "uly": "BTC-USD", "ruleType": "xperp"},
        {**base, "instId": ETH, "uly": "ETH-USD", "ruleType": "xperp", "ctVal": "0.001", "ctValCcy": "ETH", "tickSz": "0.01", "instFamily": "ETH-USD_UM"},
        {**base, "instId": "BTC-USD_UM-261225", "uly": "BTC-USD", "ruleType": "normal"},
        {**base, "instId": "SOL-USD_UM_XPERP-310404", "uly": "SOL-USD", "ruleType": "xperp"},
        {**base, "instId": "BTC-USD_UM_XPERP-310405", "uly": "BTC-USD", "ruleType": "pre_market"},
    ]


def instruments_raw() -> bytes:
    return json.dumps({"code": "0", "msg": "", "data": instrument_rows()}).encode()


def spec() -> pl.LoadedSpec:
    return pl.load_spec(pl.DEFAULT_SPEC, None)


def g5_gate2_stub(directory: Path, **overrides) -> Path:
    """The fields of the G5 Gate 2 spec the pilot reads (spec_id, spec_version, requires, pilot_separation), the block copied from the
    pilot spec (the real spec of branch claude/g5-gate2-prefreeze, 0.1.8-draft, carries the same block: same sha256)."""
    sep = spec().doc["separation"]
    document = {"spec_id": sep["g5_gate2_spec_id"], "spec_version": "0.1.8-draft", "status": "DRAFT_NOT_FROZEN", "requires": ["calendars_cover_the_window", sep["g5_gate2_requires"]],
                "pilot_separation": json.loads(json.dumps(sep["g5_gate2_block"]))}
    document.update(overrides)
    directory.mkdir(parents=True, exist_ok=True)
    path = directory / "g5_gate2_spec.json"
    path.write_text(json.dumps(document, indent=1))
    return path


def make_capture(tmp_path: Path, clock=None, free=lambda path: 50 * GIB, name: str = "run", separation_spec: Path | None = None, separation_ref: str = SEPARATION_REF,
                 **limits) -> pl.PilotCapture:
    loaded = spec()
    if separation_spec is None:
        separation_spec = g5_gate2_stub(tmp_path / "g5-stub")
    cfg = pl.PilotConfig(out_dir=tmp_path / name, spec=loaded, authorization_ref="test fixture", limits=pl.PilotLimits.from_spec(loaded, **limits), echo=False,
                         separation_spec=separation_spec, separation_ref=separation_ref)
    selected = pl.select_instruments(instrument_rows(), loaded.doc["selection"])
    return pl.PilotCapture(cfg, selected, instruments_raw(), clock=clock or FakeClock(), free_fn=free)


def levels(start: float, step: float, n: int) -> list[list[str]]:
    return [[f"{start + k * step:.1f}", str(10 + k), "0", str(1 + k % 3)] for k in range(n)]


def books(inst: str, action: str, seq, prev, bids, asks, checksum: int = 0) -> str:
    row = {"asks": asks, "bids": bids, "ts": "1793750400000", "checksum": checksum, "prevSeqId": prev, "seqId": seq}
    return json.dumps({"arg": {"channel": "books", "instId": inst}, "action": action, "data": [row]})


def books5(inst: str, seq: int, bids, asks) -> str:
    return json.dumps({"arg": {"channel": "books5", "instId": inst}, "data": [{"asks": asks, "bids": bids, "instId": inst, "ts": "1793750400000", "seqId": seq}]})


def trades(inst: str, px: str = PRICE_MARK) -> str:
    row = {"instId": inst, "tradeId": "130639474", "px": px, "sz": "3", "side": "buy", "ts": "1793750400000", "count": "2", "source": "0", "seqId": 1}
    return json.dumps({"arg": {"channel": "trades", "instId": inst}, "data": [row]})


def run_frames(capture: pl.PilotCapture, frames, clock: FakeClock | None = None, step_s: float = 0.1) -> list:
    actions = []
    for frame in frames:
        actions.extend(capture.handle_raw(frame))
        if clock is not None:
            clock.advance(step_s)
    return actions


def observations(run_dir: Path, depth=None) -> list[rp.Observation]:
    return list(rp.Replayer(run_dir, depth=depth).observations())


# ----------------------------------------------------------------------------- requirement 1: the existing books5 path keeps five levels


def test_existing_books5_path_keeps_all_five_levels():
    bids = [[f"{100 - k}.5", str(k + 1), "0", "1"] for k in range(5)]
    asks = [[f"{101 + k}.5", str(k + 7), "0", "2"] for k in range(5)]
    rows = xc.rows_from_data("books5", BTC, [{"bids": bids, "asks": asks, "ts": "1793750400000", "seqId": 7}], recv_us=1)
    (_, _, row), = rows
    assert tape.LEVELS == 5
    assert list(row[3]) == [float(b[0]) for b in bids] and list(row[4]) == [float(b[1]) for b in bids]
    assert list(row[5]) == [float(a[0]) for a in asks] and list(row[6]) == [float(a[1]) for a in asks]


# ----------------------------------------------------------------------------- spec, guards


def test_committed_spec_is_frozen_and_agrees_with_its_sidecar():
    loaded = spec()
    assert loaded.doc["status"] == "FROZEN" and loaded.doc["schema"] == "l2-pilot-spec/2" and pl.DEFAULT_SPEC.name == "l2_pilot_spec_v2.json"
    # v1 stays in the repository, unchanged (frozen 2026-10-22), and is refused for a run (no hard end, no separation check).
    assert pl.sha256_bytes(V1_SPEC.read_bytes()) == V1_SHA256 == pl.sidecar_path(V1_SPEC).read_text().split()[0] == loaded.doc["supersedes"]["sha256"]
    with pytest.raises(pl.PilotRefused) as error:
        pl.load_spec(V1_SPEC, V1_SHA256)
    assert error.value.reason == "spec_superseded"
    # The reserved window (P-L2-1) and the separation block it pins (sha256 of the canonical JSON, as research_lp.separation.block_sha256).
    assert loaded.doc["window"]["start_not_before_utc"] == "2026-11-02T00:00:00Z" and loaded.doc["window"]["hard_end_utc"] == "2026-11-03T00:00:00Z"
    sep = loaded.doc["separation"]
    assert sep["g5_gate2_block_sha256"] == pl.canonical_sha256(sep["g5_gate2_block"]) == "4b96e2bd3987e123b597b6534a9c464580d560db399401849cf992af004d489e"
    assert sep["g5_gate2_block"]["excluded_interval_utc"] == ["2026-11-02T00:00:00Z", "2026-11-03T12:00:00Z"] and sep["g5_gate2_min_version"] == "0.1.8"
    assert pl.sidecar_path(pl.DEFAULT_SPEC).read_text().split()[0] == loaded.sha256
    assert loaded.doc["channels"]["depth_primary"] == "books" and loaded.doc["channels"]["subscribed"] == ["books", "books5", "trades"]
    assert loaded.limits["max_duration_s"] == 86400 and loaded.limits["max_bytes_written"] == 5 * GIB and loaded.limits["min_free_bytes"] == 10 * GIB
    assert loaded.doc["venue"]["ws_public"] == xc.WS_PUBLIC_EEA
    assert loaded.limits["not_before_utc"] == xc.QUARANTINE_FLOOR


def test_spec_hash_mismatch_and_floor_are_refused(tmp_path):
    with pytest.raises(pl.PilotRefused) as error:
        pl.load_spec(pl.DEFAULT_SPEC, "0" * 64)
    assert error.value.reason == "spec_sha256_mismatch"
    doc = json.loads(pl.DEFAULT_SPEC.read_text())
    doc["limits"]["not_before_utc"] = "2026-10-20T00:00:00Z"
    path = tmp_path / "early.json"
    path.write_text(json.dumps(doc))
    pl.sidecar_path(path).write_text("deadbeef  early.json\n")
    with pytest.raises(pl.PilotRefused) as error:
        pl.load_spec(path, None)
    assert error.value.reason == "spec_sha256_mismatch"
    pl.sidecar_path(path).write_text(pl.sha256_bytes(path.read_bytes()) + "  early.json\n")
    with pytest.raises(pl.PilotRefused) as error:
        pl.load_spec(path, None)
    assert error.value.reason == "not_before_below_floor" and error.value.exit_code == pl.EXIT_REFUSED


def test_refused_before_the_authorized_date_and_nothing_written(tmp_path):
    with pytest.raises(pl.PilotRefused) as error:
        make_capture(tmp_path, clock=FakeClock(BEFORE_NS))
    assert error.value.reason == "before_not_before" and error.value.exit_code == pl.EXIT_REFUSED
    assert not (tmp_path / "run").exists()


@pytest.mark.parametrize("now", ["2026-11-03T00:00:00Z", "2026-11-03T00:00:01Z", "2026-11-05T12:00:00Z"])
def test_refused_at_or_after_the_hard_end_and_nothing_written(tmp_path, now):
    """P-L2-1: the reserved window ends at 2026-11-03T00:00Z whatever the start; a later run is never allowed (no extension)."""
    with pytest.raises(pl.PilotRefused) as error:
        make_capture(tmp_path, clock=FakeClock(tape.parse_iso_utc(now) * 1000))
    assert error.value.reason == "after_hard_end" and error.value.exit_code == pl.EXIT_REFUSED
    assert not (tmp_path / "run").exists()


@pytest.mark.parametrize("case", ["no_spec", "missing_file", "not_json", "other_spec_id", "old_version", "no_capability", "no_block", "other_block", "no_ref", "other_ref"])
def test_refused_while_the_separation_is_not_recorded(tmp_path, case):
    """P-L2-1: the run (and the reading of its content) needs the G5 Gate 2 spec carrying the pinned pilot_separation block and the
    decision named; otherwise the overlap with the decisive windows is unresolved and the pilot refuses, before anything is written."""
    stub_dir = tmp_path / "stub"
    block = spec().doc["separation"]["g5_gate2_block"]
    separation_spec, ref = g5_gate2_stub(stub_dir), SEPARATION_REF
    if case == "no_spec":
        separation_spec = ""
    elif case == "missing_file":
        separation_spec = stub_dir / "absent.json"
    elif case == "not_json":
        separation_spec.write_text("{not json")
    elif case == "other_spec_id":
        g5_gate2_stub(stub_dir, spec_id="LP-G5-GATE1-2026")
    elif case == "old_version":
        g5_gate2_stub(stub_dir, spec_version="0.1.7-draft")  # the version before the separation rule
    elif case == "no_capability":
        g5_gate2_stub(stub_dir, requires=["calendars_cover_the_window"])
    elif case == "no_block":
        g5_gate2_stub(stub_dir, pilot_separation=None)
    elif case == "other_block":
        g5_gate2_stub(stub_dir, pilot_separation={**block, "margin_s": 0})
    elif case == "no_ref":
        ref = ""
    elif case == "other_ref":
        ref = "owner said ok"
    with pytest.raises(pl.PilotRefused) as error:
        make_capture(tmp_path, separation_spec=separation_spec, separation_ref=ref)
    assert error.value.reason == "separation_unresolved" and error.value.exit_code == pl.EXIT_INVALID
    assert not (tmp_path / "run").exists()
    # A FROZEN later version with the same block is accepted (the version is a floor, the block is matched by sha256).
    record = pl.verify_separation(spec(), g5_gate2_stub(tmp_path / "ok", spec_version="1.0.0", status="FROZEN"), SEPARATION_REF)
    assert record["block_sha256"] == spec().doc["separation"]["g5_gate2_block_sha256"] and record["g5_gate2_spec_version"] == "1.0.0"


def test_a_late_start_is_truncated_at_the_hard_end(tmp_path):
    """P-L2-1: duration = min(24 h, hard end - start); started at 06:00 the run stops at 2026-11-03T00:00Z (18 h), reason hard_end."""
    clock = FakeClock(tape.parse_iso_utc("2026-11-02T06:00:00Z") * 1000)
    capture = make_capture(tmp_path, clock=clock)
    assert capture.duration_ns == 18 * 3600 * 10**9 and capture.duration_reason == "hard_end"
    manifest = rp.read_manifest(tmp_path / "run")
    assert manifest["bounds"]["planned_end_at_most_utc"].startswith("2026-11-03T00:00:00") and manifest["bounds"]["hard_end_utc"] == "2026-11-03T00:00:00Z"
    assert manifest["bounds"]["duration_bound_by"] == "hard_end" and manifest["separation"]["decision_id"] == "P-L2-1"
    clock.advance(18 * 3600 - 1)
    assert capture.check_limits() is None
    clock.advance(1)
    assert capture.check_limits() == "hard_end"
    capture.close()
    assert rp.read_manifest(tmp_path / "run")["stop"]["reason"] == "hard_end"
    # Started 30 s before the hard end: 30 s of run, never more; a stricter duration binds before the hard end.
    last = FakeClock(HARD_END_NS - 30 * 10**9)
    short_run = make_capture(tmp_path, clock=last, name="late")
    assert short_run.duration_ns == 30 * 10**9
    last.advance(30)
    assert short_run.check_limits() == "hard_end"
    short_run.close()
    strict = make_capture(tmp_path, clock=FakeClock(tape.parse_iso_utc("2026-11-02T06:00:00Z") * 1000), name="strict", max_duration_s=3600)
    assert strict.duration_ns == 3600 * 10**9 and strict.duration_reason == "max_duration"
    strict.close()
    # A wall clock set forward to the hard end stops the run whatever the elapsed monotonic time (the cap is never above hard end - start).
    jump = FakeClock(tape.parse_iso_utc("2026-11-02T06:00:00Z") * 1000)
    jumped = make_capture(tmp_path, clock=jump, name="jump")
    jump.wall = HARD_END_NS
    assert jumped.check_limits() == "hard_end"
    jumped.close()


def test_an_on_time_start_runs_the_full_24_hours(tmp_path):
    clock = FakeClock()
    capture = make_capture(tmp_path, clock=clock)
    assert capture.duration_ns == 86400 * 10**9 and capture.duration_reason == "max_duration"
    clock.advance(86399)
    assert capture.check_limits() is None
    clock.advance(1)
    assert capture.check_limits() == "max_duration"
    capture.close()


def test_cli_plan_before_floor_refuses_without_network_or_writes(tmp_path, capsys, before_floor):
    out = tmp_path / "plan-run"
    code = l2cli.main(["plan", "--out", str(out), "--authorization-ref", "x", "--separation-spec", str(g5_gate2_stub(tmp_path / "stub")), "--separation-ref", SEPARATION_REF])
    assert code == pl.EXIT_REFUSED
    assert "before_not_before" in capsys.readouterr().out
    assert not out.exists()


def test_cli_capture_refused_before_date_never_fetches(tmp_path, before_floor):
    loaded = spec()
    code = l2cli.main(["capture", "--out", str(tmp_path / "c"), "--spec", str(pl.DEFAULT_SPEC), "--expect-spec-sha256", loaded.sha256,
                       "--expect-code-digest", pl.code_digest(), "--authorization-ref", "x", "--separation-spec", str(g5_gate2_stub(tmp_path / "stub")),
                       "--separation-ref", SEPARATION_REF])
    assert code == pl.EXIT_REFUSED and not (tmp_path / "c").exists()  # fetch_instruments_raw would have raised (guard)


def test_cli_capture_and_plan_refuse_an_unresolved_separation_without_network(tmp_path, capsys, monkeypatch):
    monkeypatch.setattr(pl.SystemClock, "wall_ns", lambda self: START_NS + 3600 * 10**9)  # inside the reserved window
    loaded = spec()
    base = ["capture", "--out", str(tmp_path / "c"), "--spec", str(pl.DEFAULT_SPEC), "--expect-spec-sha256", loaded.sha256, "--expect-code-digest", pl.code_digest(),
            "--authorization-ref", "x", "--separation-ref", SEPARATION_REF]
    old = g5_gate2_stub(tmp_path / "old", spec_version="0.1.7-draft")
    assert l2cli.main(base + ["--separation-spec", str(old)]) == pl.EXIT_INVALID and not (tmp_path / "c").exists()
    assert "reason=separation_unresolved" in capsys.readouterr().err
    assert l2cli.main(["plan", "--out", str(tmp_path / "p"), "--authorization-ref", "x"]) == pl.EXIT_INVALID  # plan: same guards, no --separation-spec
    assert "separation_unresolved" in capsys.readouterr().out and not (tmp_path / "p").exists()
    monkeypatch.setattr(pl.SystemClock, "wall_ns", lambda self: HARD_END_NS)
    assert l2cli.main(base + ["--separation-spec", str(g5_gate2_stub(tmp_path / "ok"))]) == pl.EXIT_REFUSED and not (tmp_path / "c").exists()
    assert "reason=after_hard_end" in capsys.readouterr().err


def test_cli_capture_refuses_a_wrong_code_digest(tmp_path):
    code = l2cli.main(["capture", "--out", str(tmp_path / "c"), "--spec", str(pl.DEFAULT_SPEC), "--expect-spec-sha256", spec().sha256,
                       "--expect-code-digest", "0" * 64, "--authorization-ref", "x", "--separation-spec", str(g5_gate2_stub(tmp_path / "stub")),
                       "--separation-ref", SEPARATION_REF])
    assert code == pl.EXIT_INVALID


@pytest.mark.parametrize("path", ["/x/tradingV3-private/research/20261013T100000Z-forward-okx-r1/l2", "/x/research/20261020T2321Z-g5-periods/out",
                                  "/x/research/20261021T0700Z-scalping-diagnostic/g5-a1", "/x/research/20261014T1200Z-scalp-q3-r2/a", "/x/sealed-store/r1",
                                  "/x/fwd-okx-r1-legacy-format/r", "/x/okx-xperp-capture-2026-11-02/r", "/x/a/G5_gate2/r"])
def test_protected_output_paths_are_refused(tmp_path, path):
    with pytest.raises(pl.PilotRefused) as error:
        pl.check_output_dir(Path(path), spec().doc["protected_path_tokens"])
    assert error.value.reason == "protected_output_path"


def test_protected_path_through_a_symlink_is_refused(tmp_path):
    target = tmp_path / "20261020T2321Z-g5-periods"
    target.mkdir()
    link = tmp_path / "innocent"
    link.symlink_to(target)
    with pytest.raises(pl.PilotRefused):
        pl.check_output_dir(link / "run", spec().doc["protected_path_tokens"])
    pl.check_output_dir(tmp_path / "l2-capture-pilot" / "r1", spec().doc["protected_path_tokens"])  # allowed


def test_non_empty_output_dir_and_held_lock_are_refused(tmp_path):
    (tmp_path / "run").mkdir()
    (tmp_path / "run" / "old.txt").write_text("x")
    with pytest.raises(pl.PilotRefused) as error:
        make_capture(tmp_path)
    assert error.value.reason == "output_dir_not_empty"
    capture = make_capture(tmp_path, name="fresh")
    with pytest.raises(pl.PilotRefused) as error:
        pl.PilotLock(tmp_path / "fresh").acquire()
    assert error.value.reason == "lock_held" and error.value.exit_code == pl.EXIT_LOCK
    capture.close()
    with pytest.raises(pl.PilotRefused) as error:  # no restart in the same directory after a stop
        make_capture(tmp_path, name="fresh")
    assert error.value.reason == "output_dir_not_empty"


def test_limits_can_only_be_made_stricter():
    loaded = spec()
    assert pl.PilotLimits.from_spec(loaded, max_duration_s=3600).max_duration_s == 3600
    for key, value in (("max_duration_s", 86401), ("max_bytes_written", 6 * GIB), ("min_free_bytes", 9 * GIB)):
        with pytest.raises(pl.PilotRefused):
            pl.PilotLimits.from_spec(loaded, **{key: value})


def test_free_space_below_floor_at_start_is_refused(tmp_path):
    with pytest.raises(pl.PilotRefused) as error:
        make_capture(tmp_path, free=lambda path: 10 * GIB - 1)
    assert error.value.reason == "free_space_below_min" and error.value.exit_code == pl.EXIT_FREE_SPACE
    assert not (tmp_path / "run").exists()


# ----------------------------------------------------------------------------- selection


def test_selection_exactly_one_xperp_per_underlying():
    chosen = pl.select_instruments(instrument_rows(), spec().doc["selection"])
    assert [row["instId"] for row in chosen] == [BTC, ETH]


@pytest.mark.parametrize("mutate", ["drop_eth", "second_btc", "missing_rule_type", "not_live"])
def test_selection_refuses_unless_exactly_one(mutate):
    rows = instrument_rows()
    if mutate == "drop_eth":
        rows = [r for r in rows if r["instId"] != ETH]
    elif mutate == "second_btc":
        rows.append({**rows[0], "instId": "BTC-USD_UM_XPERP-310406"})
    elif mutate == "missing_rule_type":
        del rows[0]["ruleType"]
    elif mutate == "not_live":
        rows[1]["state"] = "suspend"
    with pytest.raises(pl.PilotRefused) as error:
        pl.select_instruments(rows, spec().doc["selection"])
    assert error.value.reason == "selection_not_exactly_one"


# ----------------------------------------------------------------------------- storage: raw frames, all levels


def test_all_400_levels_and_raw_frames_are_kept(tmp_path):
    clock = FakeClock()
    capture = make_capture(tmp_path, clock=clock)
    snap = books(BTC, "snapshot", 100, -1, levels(50000.0, -0.1, 400), levels(50000.1, 0.1, 400))
    frames = [snap, books5(BTC, 100, levels(50000.0, -0.1, 5), levels(50000.1, 0.1, 5)), trades(BTC)]
    run_frames(capture, frames, clock)
    capture.close()
    records = list(rp.iter_records(tmp_path / "run"))
    raws = [r["raw"] for r in records if r["k"] == "frame"]
    assert raws == frames
    head = next(r for r in records if r["k"] == "frame" and r["h"]["ch"] == "books")["h"]
    assert head == {"t": "data", "ch": "books", "inst": BTC, "n": 1, "action": "snapshot", "ts": 1793750400000, "seq": 100, "prev": -1, "cs": 0, "nb": 400, "na": 400}
    assert all(isinstance(r["recv_ns"], int) and r["recv_ns"] >= START_NS for r in records)
    obs = [o for o in observations(tmp_path / "run") if o.kind == "book"]
    assert obs[0].valid and obs[0].book.depth_bids == 400 and len(obs[0].book.bids) == 400 and len(obs[0].book.asks) == 400
    assert obs[0].book.bids[0][0] == "50000.0" and obs[0].book.asks[0][0] == "50000.1"


def test_header_never_holds_a_price():
    for frame in (books(BTC, "snapshot", 1, -1, [[PRICE_MARK, "1", "0", "1"]], [["98766.0", "2", "0", "1"]]), trades(BTC), books5(BTC, 1, [[PRICE_MARK, "1", "0", "1"]], [])):
        assert PRICE_MARK not in json.dumps(bk.frame_header(frame))


# ----------------------------------------------------------------------------- book reconstruction and breaks


def test_snapshot_plus_updates_rebuilds_the_book(tmp_path):
    capture = make_capture(tmp_path)
    frames = [
        books(BTC, "snapshot", 10, -1, [["100.0", "5", "0", "1"], ["99.0", "3", "0", "1"]], [["101.0", "4", "0", "1"], ["102.0", "6", "0", "2"]]),
        books(BTC, "update", 15, 10, [["99.5", "7", "0", "2"], ["99.0", "0", "0", "0"]], [["101.0", "1", "0", "1"]]),
        books(BTC, "update", 15, 15, [], []),  # heartbeat
        books(BTC, "update", 3, 15, [], [["102.0", "0", "0", "0"]]),  # documented sequence reset
        books(BTC, "update", 5, 3, [["100.5", "2", "0", "1"]], []),
    ]
    actions = run_frames(capture, frames)
    capture.close()
    assert actions == []
    obs = [o for o in observations(tmp_path / "run") if o.kind == "book"]
    assert [o.verdict for o in obs] == ["snapshot", "continuous", "heartbeat", "seq_reset", "continuous"]
    assert all(o.valid for o in obs)
    last = obs[-1].book
    assert [lvl[:2] for lvl in last.bids] == [("100.5", "2"), ("100.0", "5"), ("99.5", "7")]
    assert [lvl[:2] for lvl in last.asks] == [("101.0", "1")]


def test_gap_marks_invalid_until_new_snapshot_and_requests_one_resync(tmp_path):
    clock = FakeClock()
    capture = make_capture(tmp_path, clock=clock)
    base = books(BTC, "snapshot", 10, -1, [["100.0", "5", "0", "1"]], [["101.0", "4", "0", "1"]])
    actions = run_frames(capture, [base, books(BTC, "update", 12, 11, [["100.0", "6", "0", "1"]], [])], clock)
    assert actions == [("resync", BTC)]
    sent = capture.resync_messages(BTC)
    assert [json.loads(m)["op"] for m in sent] == ["unsubscribe", "subscribe"]
    assert json.loads(sent[1])["args"] == [{"channel": "books", "instId": BTC}]
    # still invalid: further updates do not trigger a second resync inside the minimum interval
    assert run_frames(capture, [books(BTC, "update", 13, 12, [], [])], clock) == []
    run_frames(capture, [books(BTC, "snapshot", 20, -1, [["100.2", "1", "0", "1"]], [["100.9", "1", "0", "1"]])], clock)
    capture.close()
    obs = [o for o in observations(tmp_path / "run") if o.kind in ("book", "meta")]
    kinds = [(o.kind, o.verdict, o.valid) for o in obs if not (o.kind == "meta" and o.verdict in ("stopped",))]
    assert ("book", "gap", False) in kinds
    after_gap = kinds[kinds.index(("book", "gap", False)) + 1:]
    assert after_gap[0] == ("meta", "resync_sent", False)
    assert ("book", "update_while_invalid", False) in after_gap
    assert after_gap[-1] == ("book", "snapshot", True)
    manifest = rp.read_manifest(tmp_path / "run")
    assert manifest["gaps"][0]["kind"] == "gap" and manifest["resyncs"][0]["inst"] == BTC


def test_resync_budget_per_hour(tmp_path):
    clock = FakeClock()
    capture = make_capture(tmp_path, clock=clock)
    capture.resync_times = [clock.mono_ns()] * capture.limits.resync_max_per_hour
    assert not capture.resync_allowed(ETH)
    clock.advance(3601)
    assert capture.resync_allowed(ETH)
    capture.close()


def test_checksum_verified_when_delivered_and_failure_invalidates(tmp_path):
    bids, asks = [["100.0", "5", "0", "1"], ["99.0", "3", "0", "1"]], [["101.0", "4", "0", "1"]]
    good = bk.okx_checksum([b[:2] for b in bids], [a[:2] for a in asks])
    assert good == bk.okx_checksum([["100.0", "5"], ["99.0", "3"]], [["101.0", "4"]]) and good != 0
    capture = make_capture(tmp_path)
    run_frames(capture, [books(BTC, "snapshot", 1, -1, bids, asks, checksum=good), books(BTC, "update", 2, 1, [["99.0", "4", "0", "1"]], [], checksum=12345),
                         books(BTC, "update", 3, 2, [], []), books(BTC, "snapshot", 4, -1, bids, asks, checksum=0)])
    capture.close()
    stats: dict = {}
    obs = [o for o in rp.replay_records(rp.iter_records(tmp_path / "run"), 25, stats) if o.kind == "book"]
    assert [o.valid for o in obs] == [True, False, False, True]
    assert stats["checksum_checked"] == 2 and stats["checksum_failures"] == 1 and stats["checksum_zero_or_absent"] == 1
    assert stats["invalidations"]["checksum"] == 1


def test_checksum_string_interleaves_bids_and_asks():
    import zlib

    value = zlib.crc32(b"3366.1:7:3366.8:9:3366:6:3368:8")
    expected = value - (1 << 32) if value >= 1 << 31 else value
    assert bk.okx_checksum([["3366.1", "7"], ["3366", "6"]], [["3366.8", "9"], ["3368", "8"]]) == expected


def test_duplicates_are_skipped_and_out_of_order_invalidates(tmp_path):
    capture = make_capture(tmp_path)
    snap = books(BTC, "snapshot", 10, -1, [["100.0", "5", "0", "1"]], [["101.0", "4", "0", "1"]])
    u1 = books(BTC, "update", 11, 10, [["100.0", "6", "0", "1"]], [])
    u2 = books(BTC, "update", 12, 11, [["100.0", "7", "0", "1"]], [])
    late = books(BTC, "update", 9, 8, [["100.0", "1", "0", "1"]], [])
    run_frames(capture, [snap, u1, u1, u2, late])
    capture.close()
    obs = [o for o in observations(tmp_path / "run") if o.kind == "book"]
    assert [o.verdict for o in obs] == ["snapshot", "continuous", "duplicate", "continuous", "out_of_order"]
    assert obs[2].valid and obs[2].book.bids[0][1] == "6"  # the duplicate changed nothing
    assert obs[3].book.bids[0][1] == "7"
    assert not obs[4].valid and obs[4].book is None


def test_reconnect_and_unsubscribe_invalidate_until_snapshot(tmp_path):
    capture = make_capture(tmp_path)
    snap = books(BTC, "snapshot", 10, -1, [["100.0", "5", "0", "1"]], [["101.0", "4", "0", "1"]])
    capture.on_connected()
    run_frames(capture, [snap, books(ETH, "snapshot", 3, -1, [["10.0", "1", "0", "1"]], [["10.1", "1", "0", "1"]])])
    capture.on_disconnected("ConnectionClosed")
    capture.on_connected()
    run_frames(capture, [books(BTC, "update", 11, 10, [], []), snap,
                         json.dumps({"event": "unsubscribe", "arg": {"channel": "books", "instId": BTC}, "connId": "a"}), books(BTC, "update", 11, 10, [], [])])
    capture.close()
    obs = [o for o in observations(tmp_path / "run") if o.kind == "book" and o.inst == BTC]
    assert [(o.verdict, o.valid) for o in obs] == [("snapshot", True), ("update_while_invalid", False), ("snapshot", True), ("update_while_invalid", False)]
    assert capture.reconnects == 1


def test_reader_exposes_no_future_information(tmp_path):
    """Prefix invariance: the observations of the first k records are exactly the first observations of the full run."""
    capture = make_capture(tmp_path)
    frames = [books(BTC, "snapshot", 10, -1, levels(100.0, -0.1, 30), levels(100.1, 0.1, 30)), trades(BTC),
              books(BTC, "update", 11, 10, [["99.95", "3", "0", "1"]], []), books(BTC, "update", 13, 12, [], []),
              books(BTC, "snapshot", 20, -1, levels(100.0, -0.1, 30), levels(100.1, 0.1, 30)), books(BTC, "update", 21, 20, [["100.0", "0", "0", "0"]], [])]
    run_frames(capture, frames)
    capture.close()
    records = list(rp.iter_records(tmp_path / "run"))
    full = list(rp.replay_records(records, 10))
    assert [o.rx for o in full] == sorted(o.rx for o in full)
    for k in range(len(records) + 1):
        prefix = list(rp.replay_records(records[:k], 10))
        assert prefix == full[:len(prefix)]
    # the record order, not the clock, defines availability: a wall clock stepping back is counted, never reordered
    shuffled = [dict(r) for r in records]
    shuffled[3]["recv_ns"] = shuffled[2]["recv_ns"] - 5
    stats: dict = {}
    assert [o.rx for o in rp.replay_records(shuffled, 10, stats)] == [o.rx for o in full]
    assert stats["recv_clock_backwards"] == 1


# ----------------------------------------------------------------------------- limits


def test_duration_cap_stops_cleanly_with_reason(tmp_path):
    clock = FakeClock()
    capture = make_capture(tmp_path, clock=clock, max_duration_s=3600)
    clock.advance(3599)
    assert capture.check_limits() is None
    clock.advance(1)
    assert capture.check_limits() == "max_duration"
    capture.close()
    manifest = rp.read_manifest(tmp_path / "run")
    assert manifest["stop"]["reason"] == "max_duration" and manifest["status"] == "stopped"


def test_wall_clock_jump_also_counts_toward_duration(tmp_path):
    clock = FakeClock()
    capture = make_capture(tmp_path, clock=clock)
    clock.wall += 86400 * 10**9  # a suspended host: the monotonic clock did not move
    assert capture.check_limits() == "max_duration"
    capture.close()


def test_byte_cap_stops_before_the_limit(tmp_path):
    clock = FakeClock()
    cap = 3 * 1024 * 1024
    capture = make_capture(tmp_path, clock=clock, max_bytes_written=cap, segment_max_bytes=512 * 1024)
    capture.limits = dataclasses.replace(capture.limits, stop_margin_bytes=768 * 1024)
    reason = None
    for _ in range(10000):  # incompressible frames: a fresh random payload each time
        capture.handle_raw(json.dumps({"arg": {"channel": "trades", "instId": BTC}, "data": [{"instId": BTC, "ts": "1", "pad": os.urandom(6000).hex()}]}))
        clock.advance(0.01)
        capture.housekeeping()
        reason = capture.check_limits()
        if reason:
            break
    assert reason == "max_bytes"
    capture.close()
    on_disk = sum(p.stat().st_size for p in (tmp_path / "run").rglob("*") if p.is_file())
    assert on_disk <= cap
    manifest = rp.read_manifest(tmp_path / "run")
    assert len(manifest["segments"]) >= 2 and manifest["bytes"]["written_total"] <= cap
    assert rp.verify(tmp_path / "run")["ok"]


def test_free_space_checked_periodically(tmp_path):
    clock = FakeClock()
    free = {"value": 50 * GIB}
    capture = make_capture(tmp_path, clock=clock, free=lambda path: free["value"])
    free["value"] = 9 * GIB
    clock.advance(10)
    assert capture.check_limits() is None  # not yet due (every 30 s)
    clock.advance(25)
    assert capture.check_limits() == "min_free_space"
    capture.close()
    manifest = rp.read_manifest(tmp_path / "run")
    assert manifest["free_space"]["min_free_bytes"] == 9 * GIB and manifest["stop"]["reason"] == "min_free_space"


def test_stop_file_and_reconnect_cap(tmp_path):
    capture = make_capture(tmp_path)
    (tmp_path / "run" / "STOP").write_text("")
    assert capture.check_limits() == "stop_file"
    capture.close()
    other = make_capture(tmp_path, name="other", max_reconnects=2)
    for _ in range(3):
        other.on_connected()
        other.on_disconnected("ConnectionClosed")
    assert other.stopping and other.stop_reason == "max_reconnects"
    other.close()


def test_segments_rotate_hourly_with_hashes(tmp_path):
    clock = FakeClock()
    capture = make_capture(tmp_path, clock=clock)
    for hour in range(3):
        run_frames(capture, [trades(BTC), trades(ETH)], clock)
        clock.advance(3600)
        capture.housekeeping()
    capture.close()
    manifest = rp.read_manifest(tmp_path / "run")
    assert [s["index"] for s in manifest["segments"]] == [1, 2, 3, 4]  # the 4th holds the stop record
    for entry in manifest["segments"]:
        assert tape.sha256_of(tmp_path / "run" / entry["file"]) == entry["sha256"]


# ----------------------------------------------------------------------------- manifest, verify, quality


def test_manifest_and_hash_integrity(tmp_path):
    clock = FakeClock()
    capture = make_capture(tmp_path, clock=clock)
    run_frames(capture, [books(BTC, "snapshot", 1, -1, [[PRICE_MARK, "1", "0", "1"]], [["98766.0", "1", "0", "1"]]), trades(BTC)], clock)
    capture.close()
    run = tmp_path / "run"
    manifest = rp.read_manifest(run)
    assert manifest["schema"] == pl.MANIFEST_SCHEMA and manifest["spec"]["sha256"] == spec().sha256
    assert manifest["code"]["digest"] == pl.code_digest() and "pilot.py" in manifest["code"]["files"]
    assert manifest["instruments"]["selected"] == [BTC, ETH]
    assert manifest["bounds"]["started_at_utc"].startswith("2026-11-02T00:00:00") and manifest["bounds"]["ended_at_utc"]
    selected = json.loads((run / "instruments-selected.json").read_text())["selected"]
    assert selected[0]["ctVal"] == "0.0001" and selected[0]["tickSz"] == "0.1" and selected[0]["lotSz"] == "1" and selected[0]["ctMult"] == "1"
    assert rp.verify(run)["ok"]
    segment = run / manifest["segments"][0]["file"]
    data = gzip.decompress(segment.read_bytes()).replace(b"98766.0", b"98766.1")
    segment.write_bytes(gzip.compress(data))
    result = rp.verify(run)
    assert not result["ok"] and any(p.startswith("sha256") for p in result["problems"])


def test_quality_summary_has_no_price_and_applies_criteria(tmp_path):
    clock = FakeClock()
    capture = make_capture(tmp_path, clock=clock)
    capture.on_connected()
    for inst in (BTC, ETH):
        capture.handle_raw(books(inst, "snapshot", 1, -1, [[PRICE_MARK, "1", "0", "1"]], [["98766.0", "1", "0", "1"]]))
    seq = 1
    for _ in range(600):  # 10 minutes, one update per second per instrument
        clock.advance(1)
        for inst in (BTC, ETH):
            capture.handle_raw(books(inst, "update", seq + 1, seq, [[PRICE_MARK, str(seq % 9 + 1), "0", "1"]], []))
            capture.handle_raw(books5(inst, seq, [[PRICE_MARK, "1", "0", "1"]], [["98766.0", "1", "0", "1"]]))
        capture.handle_raw(trades(BTC))
        seq += 1
    clock.advance(1)
    capture.request_stop("max_duration", "test")
    capture.close()
    summary = rp.quality_summary(tmp_path / "run")
    text = json.dumps(summary)
    assert PRICE_MARK not in text and "98766" not in text and summary["contains_prices"] is False
    assert summary["verdict"] == "PASS", summary["criteria"]
    cell = summary["instruments"][BTC]
    assert cell["books_messages"] == 601 and cell["books_breaks"] == 0 and cell["book_valid_fraction"] > 0.99
    assert cell["checksum"] == {"nonzero": 0, "zero": 601, "absent": 0}


def test_quality_fails_on_too_many_gaps(tmp_path):
    clock = FakeClock()
    capture = make_capture(tmp_path, clock=clock)
    for k in range(30):
        clock.advance(10)
        capture.handle_raw(books(BTC, "snapshot", 100 * k, -1, [["1.0", "1", "0", "1"]], [["2.0", "1", "0", "1"]]))
        capture.handle_raw(books(BTC, "update", 100 * k + 5, 100 * k + 3, [], []))  # gap
    capture.request_stop("max_duration")
    capture.close()
    summary = rp.quality_summary(tmp_path / "run")
    assert summary["instruments"][BTC]["books_breaks"] == 30
    assert summary["verdict"] == "FAIL" and summary["criteria"]["checks"][f"{BTC}.gaps"] is False


def test_readers_refuse_protected_inputs(tmp_path):
    with pytest.raises(pl.PilotRefused):
        rp.verify(tmp_path / "20261020T2321Z-g5-periods" / "x")
    with pytest.raises(pl.PilotRefused):
        rp.quality_summary(Path("/x/20261013T100000Z-forward-okx-r1/run"))


# ----------------------------------------------------------------------------- the loop, with a fake connector


class FakeWS:
    def __init__(self, frames, sent):
        self.frames = list(frames)
        self.sent = sent

    async def send(self, message):
        self.sent.append(message)

    async def recv(self):
        if self.frames:
            return self.frames.pop(0)
        await asyncio.sleep(3600)

    async def __aenter__(self):
        return self

    async def __aexit__(self, *exc):
        return False


def test_run_loop_with_fake_connector_stops_on_duration(tmp_path):
    sent: list = []
    frames = [json.dumps({"event": "subscribe", "arg": {"channel": "books", "instId": BTC}, "connId": "c"}),
              books(BTC, "snapshot", 10, -1, [["100.0", "5", "0", "1"]], [["101.0", "4", "0", "1"]]), books(BTC, "update", 12, 11, [], []), trades(BTC)]
    capture = make_capture(tmp_path, clock=RealtimeClock(), max_duration_s=1.5)
    asyncio.run(capture.run(connector=lambda url: FakeWS(frames, sent)))
    assert capture.stop_reason == "max_duration" and capture.closed
    first = json.loads(sent[0])
    assert first["op"] == "subscribe" and {(a["channel"], a["instId"]) for a in first["args"]} == {(c, i) for c in ("books", "books5", "trades") for i in (BTC, ETH)}
    assert [json.loads(m)["op"] for m in sent[1:3]] == ["unsubscribe", "subscribe"]  # resync after the gap
    assert rp.verify(tmp_path / "run")["ok"]


def test_run_loop_records_a_fatal_error(tmp_path):
    class Boom(FakeWS):
        async def recv(self):
            raise RuntimeError("socket gone")

    capture = make_capture(tmp_path, clock=RealtimeClock(), max_reconnects=1, max_duration_s=60)
    asyncio.run(capture.run(connector=lambda url: Boom([], [])))
    assert capture.stop_reason == "max_reconnects" and capture.closed
    assert rp.read_manifest(tmp_path / "run")["connections"][0]["close_reason"].startswith("RuntimeError")


def test_run_loop_stops_on_a_local_write_failure_without_retrying(tmp_path):
    capture = make_capture(tmp_path, clock=RealtimeClock(), max_duration_s=60)
    real_write = capture.writer.write
    calls = {"n": 0}

    def failing(line, rx, recv_ns, mono_ns):
        calls["n"] += 1
        if calls["n"] == 3:  # connected, subscribe_sent, then the first frame
            raise OSError(28, "No space left on device")
        return real_write(line, rx, recv_ns, mono_ns)

    capture.writer.write = failing
    asyncio.run(capture.run(connector=lambda url: FakeWS([trades(BTC)], [])))
    assert capture.stop_reason == "fatal_error" and "No space left" in capture.stop_detail
    assert capture.reconnects == 0 and capture.closed
    manifest = rp.read_manifest(tmp_path / "run")
    assert manifest["stop"]["reason"] == "fatal_error" and manifest["status"] == "stopped"


# ----------------------------------------------------------------------------- the LP collector is untouched


def test_lp_collector_defaults_and_digest_scope_unchanged():
    assert xc.CHANNELS == ("trades", "books5", "liquidation-orders", "mark-price", "funding-rate", "index-tickers")
    assert xc.DEFAULT_NOT_BEFORE == xc.QUARANTINE_FLOOR == "2026-11-02T00:00:00Z"
    assert tape.LOCK_NAME != pl.LOCK_NAME
    assert not any("l2" in name for name in lp_cli.code_identity())  # the G5 code digest does not see this package


# ----------------------------------------------------------------------------- P-L2-1: reading the content


def test_replay_check_opens_the_content_only_once_the_separation_is_recorded(tmp_path, capsys):
    """The books (content) are rebuilt only with the separation recorded: --unsealed-ref naming P-L2-1 and --separation-spec carrying the
    pinned block. The quality summary (no price) needs neither; a run shortened by the hard end passes with a reservation."""
    clock = FakeClock(tape.parse_iso_utc("2026-11-02T23:00:00Z") * 1000)
    capture = make_capture(tmp_path, clock=clock)
    capture.on_connected()
    for inst in (BTC, ETH):
        capture.handle_raw(books(inst, "snapshot", 1, -1, [["100.0", "1", "0", "1"]], [["101.0", "1", "0", "1"]]))
    clock.advance(3600)
    assert capture.check_limits() == "hard_end"
    capture.close()
    run = tmp_path / "run"
    summary = rp.quality_summary(run)
    assert summary["stop_reason"] == "hard_end" and any("hard end" in r for r in summary["criteria"]["reservations"])
    capsys.readouterr()
    assert l2cli.main(["replay-check", "--run", str(run), "--unsealed-ref", SEPARATION_REF]) == pl.EXIT_INVALID
    assert "reason=separation_unresolved" in capsys.readouterr().err
    stub = g5_gate2_stub(tmp_path / "stub")
    assert l2cli.main(["replay-check", "--run", str(run), "--unsealed-ref", "owner said ok", "--separation-spec", str(stub)]) == pl.EXIT_INVALID
    assert "reason=separation_unresolved" in capsys.readouterr().err
    assert l2cli.main(["replay-check", "--run", str(run), "--unsealed-ref", SEPARATION_REF, "--separation-spec", str(stub)]) == 0
    printed = json.loads(capsys.readouterr().out)
    assert printed["separation"]["decision_id"] == "P-L2-1" and printed["unsealed_ref"] == SEPARATION_REF
