"""Tests of the event detectors (`scripts/research_lp/events.py`) and of the command line
(`cli.py`) on synthetic tapes: liquidation clusters, conditional excursions, clock windows."""

from __future__ import annotations

import socket
import urllib.request

import json
from datetime import date
from pathlib import Path

import numpy as np
import pytest

from scripts.research_lp import cli
from scripts.research_lp import events as ev
from scripts.research_lp import tape

US = tape.SECOND_US
INST = "SOL-USD_UM_XPERP-310404"
T0 = tape.parse_iso_utc("2026-11-03T00:00:00Z")


# ----------------------------------------------------------------------------- no network (CTO review-b m4)

LOOPBACK_HOSTS = ("127.0.0.1", "::1", "localhost")


@pytest.fixture
def allow_loopback_only():
    """Explicit opt-in, `@pytest.mark.usefixtures("allow_loopback_only")` (CTO m4): the test may reach 127.0.0.1 / ::1 only (a fake server
    of the test itself), never resolve or reach any other host."""
    return LOOPBACK_HOSTS


@pytest.fixture(autouse=True)
def _no_network(request, monkeypatch):
    """No connection and no name resolution: socket connect / connect_ex / sendto, create_connection, getaddrinfo and urlopen fail
    loudly — except loopback addresses for a test that opts in with `allow_loopback_only`."""
    loopback = "allow_loopback_only" in request.fixturenames
    real = {"connect": socket.socket.connect, "connect_ex": socket.socket.connect_ex, "sendto": socket.socket.sendto, "getaddrinfo": socket.getaddrinfo,
            "create_connection": socket.create_connection}

    def local(address) -> bool:
        return loopback and isinstance(address, tuple) and len(address) >= 2 and str(address[0]) in LOOPBACK_HOSTS

    def guarded(name):
        def method(self, *args):
            address = args[-1] if name == "sendto" else args[0]
            if not local(address):
                raise AssertionError(f"network access attempted: {name} {address!r}")
            return real[name](self, *args)
        return method

    def getaddrinfo(host, *args, **kwargs):
        if not (loopback and str(host) in LOOPBACK_HOSTS):
            raise AssertionError(f"name resolution attempted: {host!r}")
        return real["getaddrinfo"](host, *args, **kwargs)

    def create_connection(address, *args, **kwargs):
        if not local(address):
            raise AssertionError(f"network access attempted: create_connection {address!r}")
        return real["create_connection"](address, *args, **kwargs)

    def refuse(*args, **kwargs):  # pragma: no cover - the assertion is the point
        raise AssertionError("network access attempted: urlopen")

    for name in ("connect", "connect_ex", "sendto"):
        monkeypatch.setattr(socket.socket, name, guarded(name))
    monkeypatch.setattr(socket, "getaddrinfo", getaddrinfo)
    monkeypatch.setattr(socket, "create_connection", create_connection)
    monkeypatch.setattr(urllib.request, "urlopen", refuse)


# ----------------------------------------------------------------------------- liquidation clusters


def liq_rows(items):
    """`items` = (ts_us, side, pos_side, size, bk_px)."""
    rows = np.zeros(len(items), dtype=tape.LIQUIDATIONS_DTYPE)
    for i, (ts, side, pos, size, bk) in enumerate(items):
        rows[i] = (ts, ts, side, pos, size, bk, 0.0)
    return rows


def test_liquidation_clusters_need_the_absolute_and_the_percentile_threshold():
    bin_us = 300 * US
    # Three days of background: one forced sell of 1 contract (100 USD) in every bin.
    items = [(T0 + b * bin_us + 10 * US, -1, 1, 1.0, 100.0) for b in range(3 * 288)]
    spike = T0 + 400 * bin_us + 30 * US
    items += [(spike, -1, 1, 300.0, 100.0), (spike + US, -1, 1, 300.0, 100.0)]  # 60,000 USD of forced sells in bin 400
    items += [(T0 + 500 * bin_us + 5 * US, 1, -1, 150.0, 100.0)]  # 15,000 USD of forced buys in bin 500
    rows = liq_rows(items)
    events, diag = ev.liquidation_clusters(rows, ct_val=1.0, abs_notional=10_000.0, percentile=99.0, min_history_bins=288)
    assert diag["orders"] == rows.size and diag["bins_with_liquidation"] == 3 * 288 and events.size == 2
    first = events[0]
    assert first["ts_us"] == T0 + 401 * bin_us and first["side"] == 1 and first["value"] == pytest.approx(60_100.0) and first["aux"] == pytest.approx(1.0)
    # A detected event is actionable only once the bin closed: arm = end = ts (the simulator then decides at the next minute, never earlier).
    assert first["arm_ts_us"] == first["ts_us"] and first["end_ts_us"] == first["ts_us"] and first["kind"] == ev.KINDS["liquidation_cluster"]
    assert events[1]["side"] == -1 and events[1]["value"] == pytest.approx(15_100.0)  # forced buys dominate → provide on the sell side
    # A higher absolute threshold removes the smaller one; a spike inside the minimum history is ignored.
    assert ev.liquidation_clusters(rows, 1.0, abs_notional=30_000.0, min_history_bins=288)[0].size == 1
    early = liq_rows(items[:300] + [(T0 + 100 * bin_us, -1, 1, 1000.0, 100.0)])
    assert ev.liquidation_clusters(early, 1.0, abs_notional=10_000.0, min_history_bins=288)[0].size == 0
    # The percentile alone would flag the ordinary bins (all equal → every bin ≥ p99 of its history): the absolute threshold is what bites.
    assert ev.liquidation_clusters(rows, 1.0, abs_notional=0.0, min_history_bins=288)[0].size > 2
    # Orders without a bankruptcy price take the mark price; without any price they are dropped and counted.
    missing = liq_rows([(T0 + b * bin_us + 10 * US, -1, 1, 1.0, np.nan) for b in range(300)] + [(T0 + 299 * bin_us + 20 * US, -1, 1, 500.0, np.nan)])
    events, diag = ev.liquidation_clusters(missing, 1.0, abs_notional=10_000.0, min_history_bins=288, mark_ts_us=np.array([T0]), mark_px=np.array([200.0]))
    assert diag["orders_without_price"] == 0 and events.size == 1 and events[0]["value"] == pytest.approx(500.0 * 200.0 + 200.0)
    events, diag = ev.liquidation_clusters(missing, 1.0, abs_notional=10_000.0, min_history_bins=288)
    assert diag["orders_without_price"] == missing.size and events.size == 0
    assert ev.liquidation_clusters(liq_rows([]), 1.0, 1.0)[0].size == 0


def test_unknown_liquidation_side_counts_in_the_total_but_never_orients_the_event():
    """m7: side 0 (unknown) is neither a forced buy nor a forced sell."""
    ts, signed, notional, dropped = ev.liquidation_notionals(liq_rows([(T0, 0, 0, 10.0, 100.0), (T0 + US, 1, -1, 1.0, 100.0), (T0 + 2 * US, -1, 1, 2.0, 100.0)]), ct_val=1.0)
    assert dropped == 0 and notional.tolist() == [1000.0, 100.0, 200.0] and signed.tolist() == [0.0, 100.0, -200.0]
    bin_us = 300 * US
    items = [(T0 + b * bin_us + 10 * US, -1, 1, 1.0, 100.0) for b in range(300)]
    # Bin 299: 9,000 USD of unknown side plus 200 USD of forced buys. Without the m7 fix the unknown side read as a forced buy (+9,000).
    items += [(T0 + 299 * bin_us + 20 * US, 0, 0, 90.0, 100.0), (T0 + 299 * bin_us + 21 * US, 1, -1, 2.0, 100.0)]
    events, _ = ev.liquidation_clusters(liq_rows(items), 1.0, abs_notional=5_000.0, min_history_bins=288)
    assert events.size == 1 and events[0]["value"] == pytest.approx(9_300.0) and events[0]["side"] == -1  # oriented by the 200 USD of forced buys only
    items[-1] = (T0 + 299 * bin_us + 21 * US, -1, 1, 2.0, 100.0)  # the same bin with 200 USD of forced sells instead → buy side
    assert ev.liquidation_clusters(liq_rows(items), 1.0, abs_notional=5_000.0, min_history_bins=288)[0][0]["side"] == 1


# ----------------------------------------------------------------------------- excursions


def synthetic_minutes(days: int, jump_minute: int | None = None, jump_pct: float = 0.03, seed: int = 7):
    n = days * 1440
    rng = np.random.default_rng(seed)
    returns = rng.normal(0.0, 0.0003, n)  # ≈ 3 bp per minute, ≈ 1.1 % per day
    if jump_minute is not None:
        returns[jump_minute] += np.log1p(jump_pct)
    closes = 100.0 * np.exp(np.cumsum(returns))
    ts = T0 - days * tape.DAY_S * US + np.arange(1, n + 1) * 60 * US
    return ts, closes


def test_excursion_detector_flags_k_sigma_moves_and_knows_the_stress_only_after_thirty_days():
    jump = 35 * 1440 + 600
    ts, closes = synthetic_minutes(40, jump_minute=jump)
    events, diag = ev.excursions(ts, closes, k=4.0)
    assert diag["minutes"] == ts.size and events.size >= 1
    hit = events[(events["ts_us"] >= ts[jump]) & (events["ts_us"] <= ts[jump] + 2 * 60 * US)]
    assert hit.size == 1 and hit[0]["side"] == -1 and hit[0]["value"] > 4.0 and hit[0]["stress"] in (0, 1) and np.isfinite(hit[0]["aux"])
    assert hit[0]["end_ts_us"] - hit[0]["ts_us"] == 15 * 60 * US  # cool-down window
    # Events are at least one cool-down apart.
    assert np.all(np.diff(events["ts_us"]) >= 15 * 60 * US)
    # z is scale-free: on a pure Gaussian random walk k = 1.5 still fires on ≈ 0.1 % of minutes (the detector's null rate, to control for).
    null_ts, null_closes = synthetic_minutes(10)
    null_events, _ = ev.excursions(null_ts, null_closes, k=1.5, cooldown_min=0)
    assert 0.0002 < null_events.size / null_ts.size < 0.02
    # The same jump in a five-day series has an unknown stress state.
    short_ts, short_closes = synthetic_minutes(5, jump_minute=4 * 1440 + 600)
    events, diag = ev.excursions(short_ts, short_closes, k=4.0)
    hit = events[(events["ts_us"] >= short_ts[4 * 1440 + 600]) & (events["ts_us"] <= short_ts[4 * 1440 + 600] + 2 * 60 * US)]
    assert hit.size == 1 and hit[0]["stress"] == -1 and np.isnan(hit[0]["aux"]) and diag["stress_known_share"] == 0.0
    # A downward jump is a buy-side event; a flat series yields nothing.
    ts, closes = synthetic_minutes(3, jump_minute=2 * 1440, jump_pct=-0.05)
    events, _ = ev.excursions(ts, closes, k=4.0)
    assert events[events["ts_us"] == ts[2 * 1440]]["side"].tolist() == [1]
    assert ev.excursions(ts[:100], np.full(100, 100.0))[0].size == 0


def test_minute_series_forward_fills_and_marks_observed_minutes():
    ts = np.array([T0 + 5 * US, T0 + 30 * US, T0 + 150 * US], dtype=np.int64)
    grid, closes, observed = ev.minute_series(ts, np.array([1.0, 2.0, 3.0]))
    assert grid.tolist() == [T0 + 60 * US, T0 + 120 * US, T0 + 180 * US] and closes.tolist() == [2.0, 2.0, 3.0] and observed.tolist() == [True, False, True]
    assert ev.minute_series(np.zeros(0, dtype=np.int64), np.zeros(0))[0].size == 0


# ----------------------------------------------------------------------------- clock windows


def test_clock_windows_follow_the_funding_hours_and_the_us_daylight_rule():
    assert ev.us_dst_active(date(2026, 3, 7)) is False and ev.us_dst_active(date(2026, 3, 8)) is True
    assert ev.us_dst_active(date(2026, 10, 31)) is True and ev.us_dst_active(date(2026, 11, 1)) is False
    assert ev.us_open_utc_minutes(date(2026, 10, 20)) == 13 * 60 + 30 and ev.us_open_utc_minutes(date(2026, 11, 3)) == 14 * 60 + 30
    rows = ev.clock_windows(["2026-11-01", "2026-11-03"], arm_min=5, end_min=1)
    sunday = rows[rows["ts_us"] < T0]
    assert sunday.size == 3 and set(sunday["kind"].tolist()) == {ev.KINDS["clock_funding"]}  # no US cash open on a Sunday
    tuesday = rows[rows["ts_us"] >= T0]
    assert [tape.iso_utc(int(t))[11:16] for t in tuesday["ts_us"]] == ["00:00", "08:00", "14:30", "16:00"]
    us_open = tuesday[tuesday["kind"] == ev.KINDS["clock_us_open"]][0]
    assert us_open["arm_ts_us"] == us_open["ts_us"] - 5 * 60 * US and us_open["end_ts_us"] == us_open["ts_us"] + 60 * US and us_open["aux"] == 0.0 and us_open["side"] == 0
    october = ev.clock_windows(["2026-10-20"], arm_min=2)
    assert [tape.iso_utc(int(t))[11:16] for t in october["ts_us"]] == ["00:00", "08:00", "13:30", "16:00"] and october[2]["arm_ts_us"] == october[2]["ts_us"] - 120 * US
    summary = ev.summarize_events(rows)
    assert summary["events"] == 7 and summary["by_kind"]["clock_funding"]["n"] == 6 and summary["by_kind"]["clock_us_open"]["both"] == 1
    # Every clock window is a scheduled event: armed strictly before its instant (the simulator decides there).
    assert np.all(rows["arm_ts_us"] < rows["ts_us"]) and np.all(rows["end_ts_us"] > rows["ts_us"])


def test_clock_windows_take_the_settlement_instants_of_the_instrument_from_its_funding_rows():
    """m5: a 4 h contract (or one shortened under stress) settles when its funding rows say, not at 00/08/16."""
    day = tape.day_of_date("2026-11-03") * tape.DAY_S * US
    rows = np.zeros(3, dtype=tape.FUNDING_DTYPE)
    rows["ts_us"] = [day + 3600 * US, day + 5 * 3600 * US, day + 9 * 3600 * US]
    rows["funding_time_us"] = [day + 4 * 3600 * US, day + 8 * 3600 * US, day + 12 * 3600 * US]
    rows["next_funding_time_us"] = [day + 8 * 3600 * US, day + 12 * 3600 * US, day + 14 * 3600 * US]  # shortened to 2 h at the end
    instants = ev.funding_times_from_tape(rows, ["2026-11-03"])
    assert [tape.iso_utc(int(t))[11:16] for t in instants] == ["04:00", "08:00", "12:00", "14:00"]
    assert ev.funding_times_from_tape(rows, ["2026-11-04"]).size == 0 and ev.funding_times_from_tape(np.zeros(0, dtype=tape.FUNDING_DTYPE), ["2026-11-03"]).size == 0
    windows = ev.clock_windows(["2026-11-03"], funding_times_us=instants)
    assert [tape.iso_utc(int(t))[11:16] for t in windows["ts_us"]] == ["04:00", "08:00", "12:00", "14:00", "14:30"]
    funding = windows[windows["kind"] == ev.KINDS["clock_funding"]]
    assert funding["value"].tolist() == [4.0, 8.0, 12.0, 14.0] and np.all(funding["arm_ts_us"] == funding["ts_us"] - 5 * 60 * US)
    # No instants given → the fixed hours; an empty array behaves like None.
    assert ev.clock_windows(["2026-11-03"], funding_times_us=np.zeros(0, dtype=np.int64)).size == 4


# ----------------------------------------------------------------------------- command line on a synthetic tape


def _write_tape(root: Path, date_str: str = "2026-11-03", seconds: int = 7200, instruments: bool = True, start_s: int = 0) -> None:
    """A quiet one-tick book with regular prints, a funding row and liquidations, finalized."""
    store = tape.TapeStore(root, "values", source={"ws": "synthetic"}, not_before="2026-11-02T00:00:00Z")
    day_us = tape.day_of_date(date_str) * tape.DAY_S * US
    for s in range(start_s, start_s + seconds):
        ts = day_us + s * US
        store.append("books5", INST, ts, (ts, ts + 1000, s, [100.0, 99.9, 99.8, 99.7, 99.6], [5.0, 5.0, 5.0, 5.0, 5.0], [100.2, 100.3, 100.4, 100.5, 100.6], [5.0, 5.0, 5.0, 5.0, 5.0]))
        if s % 20 == 1:
            store.append("trades", INST, ts, (ts, ts + 1000, 100.0, 2.0, 0, s, 1))  # a seller hits the bid
        if s % 20 == 5:
            store.append("trades", INST, ts, (ts, ts + 1000, 100.2, 2.0, 1, s, 1))  # a buyer lifts the ask
        if s % 300 == 7:
            store.append("liquidations", INST, ts, (ts, ts + 1000, -1, 1, 1.0, 100.0, 0.0))
        if s % 600 == 0:
            store.append("mark", INST, ts, (ts, ts + 1000, 100.1))
    f = day_us + 8 * 3600 * US
    store.append("funding", INST, day_us, (day_us, day_us + 1, f, 0.0001, f + 8 * 3600 * US, np.nan, 0.0, np.nan, 0, 1, 0.00375, -0.00375, 0.0001))
    store.checkpoint()
    store.finalize_days_before(tape.day_of_date(date_str) + 1)
    if instruments:
        (root / "instruments-futures.json").write_text(json.dumps({"instruments": [{"instId": INST, "tickSz": "0.1", "ctVal": "0.1", "lotSz": "1", "minSz": "1"}]}))


def test_cli_simulate_events_and_verify_on_a_synthetic_tape(tmp_path: Path, capsys):
    root = tmp_path / "tape"
    _write_tape(root)
    assert cli.main(["verify", "--tape", str(root)]) == 0
    out, summary = tmp_path / "orders.npz", tmp_path / "summary.json"
    assert cli.main(["simulate", "--tape", str(root), "--dates", "2026-11-03", "--rule", "touch", "--grid-s", "60", "--ttl-s", "30", "--exit-max-s", "60",
                     "--order-notional", "50", "--out", str(out), "--summary", str(summary)]) == 0
    document = json.loads(summary.read_text())
    cell = document["symbols"][INST]
    assert cell["instrument"]["ct_val"] == 0.1 and cell["funding_source"] == "tape" and cell["settlements"] == 1
    assert cell["ct_val_source"] == "instruments_file" and cell["tick_source"] == "instruments_file" and document["ct_val_source"] == {INST: "instruments_file"}
    assert document["guard"] == {"not_before": "2026-11-02T00:00:00Z", "quarantine_floor": "2026-11-02T00:00:00Z", "allow_before": False, "allow_before_reason": None, "dates_before_not_before": []}
    assert cell["unconstrained"]["fills"] > 0 and cell["unconstrained"]["round_trip"]["net_bp_mean"] == pytest.approx(20.0 - 4.0, abs=0.05)  # 2 ticks of spread (20 bp on 100, 19.96 on 100.2), maker/maker
    assert cell["portfolio"]["orders"] <= cell["unconstrained"]["orders"] and cell["scheduled_intents"] == 0
    with np.load(out) as data:
        assert data[f"orders_{INST}"].dtype.names == np.dtype(cli.lp_sim.ORDER_DTYPE).names and json.loads(bytes(data["meta"]).decode())["instruments"] == [INST]
        assert json.loads(bytes(data["meta"]).decode())["guard"]["allow_before"] is False
    events_out, events_summary = tmp_path / "events.npz", tmp_path / "events.json"
    assert cli.main(["events", "--tape", str(root), "--dates", "2026-11-03", "--kind", "liquidation,excursion,clock", "--abs-notional", "1", "--min-history-bins", "2",
                     "--min-days", "1", "--out", str(events_out), "--summary", str(events_summary)]) == 0
    ev_summary = json.loads(events_summary.read_text())
    diag = ev_summary["diagnostics"]
    # Clock windows are per instrument, from the tape's funding rows (08:00 and 16:00 announced) plus the US open: 3, not the fixed 4.
    assert diag[INST]["clock"] == {**diag[INST]["clock"], "events": 3, "funding_source": "tape", "funding_instants": 2, "funding_rows": 1}
    assert diag[INST]["liquidation"]["orders"] == 24 and diag[INST]["liquidation"]["ct_val_source"] == "instruments_file" and diag[INST]["excursion"]["minutes"] > 100
    assert ev_summary["ct_val_source"] == {INST: "instruments_file"} and ev_summary["guard"]["allow_before"] is False
    with np.load(events_out) as data:
        assert f"events_{INST}" in data.files and "events__all" not in data.files
        clock = data[f"events_{INST}"][data[f"events_{INST}"]["kind"] >= ev.KINDS["clock_funding"]]
        assert [tape.iso_utc(int(t))[11:16] for t in clock["ts_us"]] == ["08:00", "14:30", "16:00"]
    # Events feed the simulator: detected events at the next minute (global TTL), the three clock windows at their arming instant (own TTL = 6 min, both sides).
    assert cli.main(["simulate", "--tape", str(root), "--dates", "2026-11-03", "--rule", "queue", "--events", str(events_out), "--out", str(tmp_path / "ev-orders.npz"),
                     "--summary", str(tmp_path / "ev-summary.json")]) == 0
    ev_document = json.loads((tmp_path / "ev-summary.json").read_text())
    assert ev_document["symbols"][INST]["intents"] > 6 and ev_document["symbols"][INST]["scheduled_intents"] == 6
    with np.load(tmp_path / "ev-orders.npz") as data:
        orders = data[f"orders_{INST}"]
    scheduled = orders[orders["tag"] >= ev.KINDS["clock_funding"]]  # the order rows carry the effective lifetime: 6 min for the windows, the 60 s default otherwise
    assert scheduled.size == 6 and set(scheduled["ttl_us"].tolist()) == {6 * 60 * US} and set(scheduled["decision_ts"].tolist()) == {int(t) - 5 * 60 * US for t in clock["ts_us"]}
    assert set(scheduled["status"].tolist()) == {cli.lp_sim.STATUS["stale"]}  # all three windows fall outside the two-hour book: no quote → stale, never a decision at h+1
    detected = orders[orders["tag"] < ev.KINDS["clock_funding"]]
    assert detected.size == orders.size - 6 and np.all(detected["ttl_us"] == 60 * US) and np.all(orders["decision_ts"] % (60 * US) == 0)
    capsys.readouterr()


def test_cli_refuses_quarantined_dates_and_counting_tapes(tmp_path: Path):
    root = tmp_path / "tape"
    _write_tape(root, date_str="2026-11-03", seconds=10)
    with pytest.raises(SystemExit):
        cli.main(["simulate", "--tape", str(root), "--dates", "2026-10-20"])
    with pytest.raises(SystemExit):
        cli.main(["events", "--tape", str(root), "--dates", "2026-11-01..2026-11-03", "--kind", "clock"])
    # M1: the old `--allow-before` flag no longer exists; the guard is lifted only with a recorded reason, and never below the floor.
    with pytest.raises(SystemExit):
        cli.main(["events", "--tape", str(root), "--dates", "2026-11-01..2026-11-03", "--kind", "clock", "--allow-before"])
    for command in ("simulate", "events"):
        with pytest.raises(SystemExit, match="not_before_below_floor"):
            cli.main([command, "--tape", str(root), "--dates", "2026-11-03", "--not-before", "2026-10-15T00:00:00Z"])
        with pytest.raises(SystemExit, match="not_before_below_floor"):
            cli.main([command, "--tape", str(root), "--dates", "2026-11-03", "--not-before", "2026-10-15T00:00:00Z", "--allow-before-reason", "trying anyway"])
    summary = tmp_path / "clock.json"
    assert cli.main(["events", "--tape", str(root), "--dates", "2026-11-01..2026-11-03", "--kind", "clock", "--allow-before-reason", "synthetic tape", "--summary", str(summary)]) == 0
    guard = json.loads(summary.read_text())["guard"]
    assert guard == {"not_before": "2026-11-02T00:00:00Z", "quarantine_floor": "2026-11-02T00:00:00Z", "allow_before": True, "allow_before_reason": "synthetic tape", "dates_before_not_before": ["2026-11-01"]}
    counting = tmp_path / "counting"
    tape.TapeStore(counting, "counting")
    with pytest.raises(SystemExit):
        cli.main(["simulate", "--tape", str(counting), "--dates", "2026-11-03"])
    assert cli.main(["verify", "--tape", str(tmp_path / "nowhere")]) == 1


def test_cli_refuses_to_assume_a_contract_value(tmp_path: Path, capsys):
    """M3: no ctVal anywhere → refusal, not a silent 1.0 (which sizes every X-Perp order to 0 or scales every notional)."""
    root = tmp_path / "tape"
    _write_tape(root, instruments=False)
    with pytest.raises(SystemExit, match="ct_val_missing"):
        cli.main(["simulate", "--tape", str(root), "--dates", "2026-11-03"])
    (root / "instruments-futures.json").write_text(json.dumps({"instruments": [{"instId": INST, "tickSz": "0.1"}]}))  # a row without ctVal is no better
    with pytest.raises(SystemExit, match="ct_val_missing"):
        cli.main(["simulate", "--tape", str(root), "--dates", "2026-11-03"])
    with pytest.raises(SystemExit, match="ct_val_missing"):
        cli.main(["events", "--tape", str(root), "--dates", "2026-11-03", "--kind", "liquidation"])
    assert cli.main(["events", "--tape", str(root), "--dates", "2026-11-03", "--kind", "excursion,clock"]) == 0  # these kinds need no ctVal
    summary = tmp_path / "summary.json"
    assert cli.main(["simulate", "--tape", str(root), "--dates", "2026-11-03", "--rule", "touch", "--ct-val", "0.1", "--summary", str(summary)]) == 0
    cell = json.loads(summary.read_text())["symbols"][INST]
    assert cell["instrument"]["ct_val"] == 0.1 and cell["ct_val_source"] == "cli" and cell["tick_source"] == "instruments_file" and cell["unconstrained"]["fills"] > 0
    (root / "instruments-futures.json").unlink()
    assert cli.main(["simulate", "--tape", str(root), "--dates", "2026-11-03", "--rule", "touch", "--ct-val", "0.1", "--summary", str(summary)]) == 0
    cell = json.loads(summary.read_text())["symbols"][INST]
    assert cell["tick_source"] == "inferred" and cell["instrument"]["tick"] == pytest.approx(0.1) and cell["ct_val_source"] == "cli"
    events_summary = tmp_path / "events.json"
    assert cli.main(["events", "--tape", str(root), "--dates", "2026-11-03", "--kind", "liquidation", "--ct-val", "0.1", "--abs-notional", "1", "--min-history-bins", "2", "--summary", str(events_summary)]) == 0
    assert json.loads(events_summary.read_text())["ct_val_source"] == {INST: "cli"}
    capsys.readouterr()


def test_cli_clock_window_is_decided_at_the_arming_instant_on_a_tape_around_a_settlement(tmp_path: Path, capsys):
    """M2 end to end: a book 07:50 → 08:10, the 08:00 window → one decision at 07:55:00 per side, resting until 08:01:00."""
    root = tmp_path / "tape"
    _write_tape(root, start_s=7 * 3600 + 50 * 60, seconds=20 * 60)
    events_out = tmp_path / "events.npz"
    assert cli.main(["events", "--tape", str(root), "--dates", "2026-11-03", "--kind", "clock", "--out", str(events_out)]) == 0
    out = tmp_path / "orders.npz"
    assert cli.main(["simulate", "--tape", str(root), "--dates", "2026-11-03", "--rule", "touch", "--ttl-s", "60", "--exit-max-s", "60", "--events", str(events_out), "--out", str(out)]) == 0
    with np.load(out) as data:
        orders = data[f"orders_{INST}"]
    h = tape.parse_iso_utc("2026-11-03T08:00:00Z")
    window = orders[orders["decision_ts"] == h - 5 * 60 * US]
    assert window.size == 2 and set(window["side"].tolist()) == {1, -1} and set(window["ttl_us"].tolist()) == {6 * 60 * US}
    assert not np.any(orders["decision_ts"] == h + 60 * US)  # nothing is decided at h+1 any more
    buy = window[window["side"] == 1][0]
    # The synthetic tape prints a seller at the bid every 20 s (s % 20 == 1): the buy at 100.0 fills at 07:55:01 under `touch`, inside its window.
    assert buy["status"] == cli.lp_sim.STATUS["filled"] and buy["fill_ts"] == h - 5 * 60 * US + 1 * US
    capsys.readouterr()


def test_no_test_can_open_a_socket_or_resolve_a_name():
    """CTO m4: the guard of this module (no loopback opt-in here)."""
    for attempt in (lambda: socket.create_connection(("127.0.0.1", 9)), lambda: socket.socket().connect(("127.0.0.1", 9)), lambda: socket.getaddrinfo("eea.okx.com", 443),
                    lambda: urllib.request.urlopen("https://eea.okx.com/api/v5/public/time")):
        with pytest.raises(AssertionError):
            attempt()
