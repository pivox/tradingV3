"""Tests of the scalp-q3-r2 corrective pass (AMENDMENT-2): partial passive exits, cluster-shift control, gate, claim."""

from __future__ import annotations

import json

import numpy as np
import pytest

from scripts.research_exec import q3r2_c2 as c2
from scripts.research_exec import q3r2_stats as st
from scripts.research_exec.capture import QUOTE_DTYPE, TRADE_DTYPE, Instrument
from scripts.research_exec.q3r2_engine import FILLED, PASSIVE, STOP, compute_outcomes, exit_mix
from scripts.research_exec.q3r2_portfolio import SCENARIOS, Book, Stream, run_window
from scripts.research_exec.scalp_engine import EXIT_REASONS, Policy, SeriesData
from scripts.research_exec.tick import build_bars, compute_features

SECOND = 1_000_000
T0 = 1_790_000_000
INSTRUMENT = Instrument("BTCUSDT", "BTCUSDT", 1.0, 0.001, 0.001, fixed_tick=0.1)
Z3 = Policy("E1", "Z3")


def make(q_rows, t_rows, seconds):
    quotes = np.array([(ts, ts, b, a, 1.0, 1.0) for ts, b, a in sorted(q_rows)], dtype=QUOTE_DTYPE)
    trades = np.array([(ts, ts, p, sz, buy, 1) for ts, p, sz, buy in sorted(t_rows)], dtype=TRADE_DTYPE)
    bars = build_bars(quotes, trades, T0, T0 + seconds)
    return SeriesData(0, bars, compute_features(bars, INSTRUMENT), quotes, trades, INSTRUMENT, T0)


def passive_exit_series(through_size: float, late_drop: bool = False):
    q_rows = [((T0 + s) * SECOND + 50_000, 100.0, 100.1) for s in range(5000)]
    t_rows = [((T0 + s) * SECOND + 500_000, 100.05, 0.1, 1) for s in range(5000)]
    t_rows.append(((T0 + 3) * SECOND + 600_000, 99.9, 1.0, 0))  # entry fill at 100.0
    t_rows.append(((T0 + 3610) * SECOND, 100.2, through_size, 1))  # through the ask 100.1 posted at fill + 3 600.25 s
    if late_drop:
        t_rows.append(((T0 + 3700) * SECOND, 97.0, 1.0, 0))  # before the fallback: stops the remainder of Z3-SL200
    return make(q_rows, t_rows, 5000)


def test_old_columns_are_unchanged_by_the_remainder_columns():
    data = passive_exit_series(0.3)
    a = compute_outcomes(data, Z3, np.array([1, 2, 50]))
    b = compute_outcomes(data, Z3, np.array([1, 2, 50]), remainder=True)
    for key, value in a.items():
        np.testing.assert_array_equal(value, b[key])
    assert b["pv_thr"][0] == pytest.approx(0.3) and b["rz_exit"][0] == pytest.approx(100.0)  # fallback at the bid
    assert b["rz_exit_ts"][0] == (T0 + 3) * SECOND + 600_000 + 3900 * SECOND + 250_000


def test_partial_passive_exit_splits_maker_and_taker_legs():
    data = passive_exit_series(0.3)
    cols = compute_outcomes(data, Z3, np.arange(5000), remainder=True)
    assert cols["z_reason"][1] == PASSIVE
    for key in ("z_fund_bp", "s_fund_bp", "rz_fund_bp", "rs_fund_bp"):
        cols[key] = np.nan_to_num(cols[key])  # no funding archive in this synthetic series
    mix = exit_mix(cols, np.array([1]), "Z3", np.array([1.0]), 0.001)
    assert mix["frac"][0] == pytest.approx(0.3) and mix["rem_exit"][0] == pytest.approx(100.0)
    assert mix["rem_reason"][0] == EXIT_REASONS["fallback"]
    full = exit_mix(cols, np.array([1]), "Z3", np.array([0.2]), 0.001)
    assert full["frac"][0] == 1.0 and full["rem_exit"][0] == pytest.approx(100.1)
    stream = Stream("AAA", T0, 5000, 0.001, 0.001, 5.0, cols, np.array([1]), 0)
    old = run_window([stream], "Z3", SCENARIOS["base"], Book(), 500.0, record=True)
    new = run_window([stream], "Z3", SCENARIOS["base"], Book(), 500.0, record=True, partial_exit=True)
    r = new.records[0]
    q = r["qty"]
    assert q > 0.3 and new.partial_exit == 1 and r["passive_frac"] == pytest.approx(0.3 / q)
    q1, q2 = 0.3, q - 0.3
    expected = q1 * 0.1 + q2 * 0.0 - q * 100 * 2e-4 - q1 * 100.1 * 2e-4 - q2 * 100.0 * 5e-4
    assert r["pnl"] == pytest.approx(expected) and r["pnl"] < old.records[0]["pnl"]
    assert r["exit_ts"] == cols["rz_exit_ts"][1]


def test_remainder_of_the_protected_exit_stays_under_the_stop():
    data = passive_exit_series(0.3, late_drop=True)
    cols = compute_outcomes(data, Z3, np.array([1]), remainder=True)
    assert cols["s_reason"][0] == PASSIVE and cols["rs_reason"][0] == STOP and cols["rs_exit"][0] == pytest.approx(97.0)
    mix = exit_mix(cols, np.array([0]), "SL", np.array([1.0]), 0.001)
    assert mix["frac"][0] == pytest.approx(0.3) and mix["rem_reason"][0] == STOP and mix["rem_exit"][0] == pytest.approx(97.0)


def test_signal_level_view_uses_the_reference_quantity():
    data = passive_exit_series(0.3)
    cols = compute_outcomes(data, Z3, np.arange(5000), remainder=True)
    view = c2.corrected_view(cols, 5000, "SL", T0)
    qref = c2.QREF_NOTIONAL / 100.0
    assert view["_frac"][1] == pytest.approx(0.3 / qref)
    nb = c2.net_bp(view, np.array([1]), "SL", 2.0, 5.0, 0.0)
    f = 0.3 / qref
    assert nb["net"][0] == pytest.approx(f * 10.0 + (1 - f) * 0.0 - (2.0 + f * 2.0 + (1 - f) * 5.0))
    assert view["s_busy"][1] > cols["s_busy"][1]


def test_global_clusters_and_cluster_shift_keep_the_structure():
    n, start = 5 * 86400, T0 - T0 % 86400
    signals = {0: np.concatenate([np.arange(1000, 1010), np.arange(2000, 2005)]), 1: np.arange(1500, 1520), 2: np.arange(200_000, 200_030)}
    clusters = c2.global_clusters(signals)
    assert len(clusters) == 2 and {m[0] for m in clusters[0]["members"]} == {0, 1}
    a = c2.cluster_shift(clusters, start, n, st.rng_for(3, 1, 7, 0))
    b = c2.cluster_shift(clusters, start, n, st.rng_for(3, 1, 7, 0))
    assert all(np.array_equal(a[k], b[k]) for k in a)
    assert a[0].size == 15 and a[1].size == 20 and a[2].size == 30
    # Relative timing inside the cluster is kept across pairs, and the start keeps its UTC hour.
    assert a[1][0] - a[0][0] == 500 and a[0][10] - a[0][0] == 1000
    assert (start + a[0][0]) // 3600 % 24 == (start + 1000) // 3600 % 24
    other = c2.cluster_shift(clusters, start, n, st.rng_for(3, 1, 8, 0))
    assert not np.array_equal(other[0], a[0])


def test_holm_and_gate_logic():
    h = c2.holm({"C2": 0.01, "C4": 0.04})
    assert h["C2"] == pytest.approx(0.02) and h["C4"] == pytest.approx(0.04)
    row = {"portfolio_controls": {"cluster": {"residual_trades": 0.1, "residual_position_time": 0.25, "residual_notional_exposure": 0.0}},
           "controls": {"cluster": {"signal": {"residual_trades": 0.05}}}}
    comp = c2.comparable(row)
    assert not comp["ok"] and not comp["checks"]["position_time"]


def test_validation_claim_is_single_use_and_needs_the_frozen_amendment(tmp_path):
    amendment = tmp_path / "A2.md"
    amendment.write_text("frozen")
    import hashlib
    sha = hashlib.sha256(amendment.read_bytes()).hexdigest()
    gate = tmp_path / "gate.json"
    gate.write_text(json.dumps({"decision": "STATISTICAL_BLOCKER", "forwarded": []}))
    args = type("A", (), {"out": str(tmp_path / "nov"), "amendment": str(amendment), "expected_sha256": sha, "gate": str(gate)})
    with pytest.raises(SystemExit):
        c2.claim(args)
    gate.write_text(json.dumps({"decision": "GATE_PASS", "forwarded": ["C2"]}))
    bad = type("A", (), {"out": str(tmp_path / "nov"), "amendment": str(amendment), "expected_sha256": "0" * 64, "gate": str(gate)})
    with pytest.raises(SystemExit):
        c2.claim(bad)
    assert c2.claim(args) == 0
    with pytest.raises(SystemExit):
        c2.claim(args)
    assert set(c2.VALIDATION_WINDOWS) == {"v2411a", "v2411b", "v2411c", "v2411d"}


def test_training_guard_still_refuses_november():
    from scripts.research_exec.q3r2_run import check_phase
    for window in c2.VALIDATION_WINDOWS:
        with pytest.raises(SystemExit):
            check_phase("training", (window,))
