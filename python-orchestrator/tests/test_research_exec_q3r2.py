"""Tests of scalp-q3-r2 (PREREGISTRATION-scalp-q3-r2.md): engine, stop, funding, portfolio, controls, phase guard."""

from __future__ import annotations

import numpy as np
import pytest

from scripts.research_exec import q3r2_stats as st
from scripts.research_exec.capture import QUOTE_DTYPE, TRADE_DTYPE, Instrument
from scripts.research_exec.q3r2_engine import (CROSSED, FILLED, NONE, PASSIVE, STOP, UNFILLED, Funding, compute_outcomes, funding_bp, standalone, stop_trigger,
                                               trade_rows)
from scripts.research_exec.q3r2_portfolio import SCENARIOS, Book, Stream, run_window
from scripts.research_exec.scalp_engine import TRADE_DTYPE as ROW_DTYPE
from scripts.research_exec.scalp_engine import EXIT_REASONS, Policy, SeriesData, run_policy
from scripts.research_exec.tick import build_bars, compute_features, signal_mask

SECOND = 1_000_000
T0 = 1_790_000_000
INSTRUMENT = Instrument("BTCUSDT", "BTCUSDT", 1.0, 0.001, 0.001, fixed_tick=0.1)
Z3 = Policy("E1", "Z3")


def quotes_from(rows):
    return np.array([(ts, ts, bid, ask, bs, asz) for ts, bid, ask, bs, asz in rows], dtype=QUOTE_DTYPE)


def trades_from(rows):
    return np.array([(ts, ts, price, size, taker_buy, 1) for ts, price, size, taker_buy in rows], dtype=TRADE_DTYPE)


def make(q_rows, t_rows, seconds):
    quotes = quotes_from(sorted(q_rows))
    trades = trades_from(sorted(t_rows))
    bars = build_bars(quotes, trades, T0, T0 + seconds)
    features = compute_features(bars, INSTRUMENT)
    return SeriesData(0, bars, features, quotes, trades, INSTRUMENT, T0)


def random_walk(seconds=9000, seed=3, quiet_every=0):
    rng = np.random.default_rng(seed)
    mid = 100.0 * np.exp(rng.normal(0, 4e-4, size=seconds).cumsum())
    q_rows, t_rows = [], []
    for s in range(seconds):
        if quiet_every and s % quiet_every == 0:
            continue
        bid = np.floor(mid[s] / 0.1) * 0.1
        q_rows.append(((T0 + s) * SECOND + 100_000, bid, bid + 0.1, 1.0, 1.0))
        for k in range(2):
            buy = int(rng.random() < 0.5)
            t_rows.append(((T0 + s) * SECOND + 200_000 + k * 300_000, bid + 0.1 if buy else bid, 0.5, buy))
    return q_rows, t_rows


def flat_book(seconds):
    q_rows = [((T0 + s) * SECOND + 50_000, 100.0, 100.1, 1.0, 1.0) for s in range(seconds)]
    t_rows = [((T0 + s) * SECOND + 500_000, 100.05, 0.1, 1) for s in range(seconds)]
    return q_rows, t_rows


# 1. No look-ahead -------------------------------------------------------------------------------


def test_features_and_signals_ignore_the_future():
    q_rows, t_rows = random_walk(4000)
    data = make(q_rows, t_rows, 4000)
    cut = 2500
    t_future = [row if row[0] < (T0 + cut + 1) * SECOND else (row[0], row[1] * 1.05, row[2], 1 - row[3]) for row in t_rows]
    q_future = [row if row[0] < (T0 + cut + 1) * SECOND else (row[0], row[1] * 1.05, row[2] * 1.05, row[3], row[4]) for row in q_rows]
    other = make(q_future, t_future, 4000)
    for name in ("band_dev_900", "sigma_900", "sigma_900_live", "stale_900", "fi_60"):
        np.testing.assert_array_equal(data.features[name][:cut + 1], other.features[name][:cut + 1])
    for rule in ("S7-W900-K1.5", "S7L-W900-K1.5"):
        np.testing.assert_array_equal(signal_mask(rule, data.features, 1)[:cut + 1], signal_mask(rule, other.features, 1)[:cut + 1])


# 2. Identity with the historical engine ---------------------------------------------------------


def test_outcome_table_replay_equals_run_policy_trade_for_trade():
    q_rows, t_rows = random_walk(9000)
    data = make(q_rows, t_rows, 9000)
    cols = compute_outcomes(data, Z3)
    decisions = np.arange(0, 9000, 7)
    expected, counters = run_policy(data, 1, decisions, Z3)
    filled, mine = standalone(cols, decisions, "Z3", data.n, Z3, count_decisions=True)
    rows = trade_rows(cols, filled, "Z3", 0, T0, Z3)
    got = np.zeros(filled.size, dtype=ROW_DTYPE)
    for name in ROW_DTYPE.names:
        got[name] = 1 if name == "side" else rows[name]
    assert expected.size >= 2 and mine == counters.__dict__
    for name in ROW_DTYPE.names:
        np.testing.assert_array_equal(got[name], expected[name])
    # Every single order, one decision at a time, equals the table row (fills, misses, exits).
    for s in np.random.default_rng(1).choice(5000, 150, replace=False).tolist():
        one, cnt = run_policy(data, 1, np.array([s]), Z3)
        if cnt.filled:
            assert cols["status"][s] == FILLED and one["exit_ts"][0] == cols["z_exit_ts"][s] and one["exit"][0] == cols["z_exit"][s] and one["fill_ts"][0] == cols["fill_ts"][s]
        else:
            assert cols["status"][s] == (UNFILLED if cnt.unfilled else CROSSED if cnt.crossed else NONE)
    # The protected exit never ends later than Z3 and only differs through the stop.
    stopped = cols["s_reason"] == STOP
    same = (cols["status"] == FILLED) & ~stopped
    np.testing.assert_array_equal(cols["s_exit_ts"][same], cols["z_exit_ts"][same])
    assert (cols["s_exit_ts"][stopped] <= cols["z_exit_ts"][stopped]).all()


# 3. Indicators -----------------------------------------------------------------------------------


def test_active_sigma_is_calendar_sigma_rescaled_and_s7l_uses_it():
    q_rows, t_rows = random_walk(3000, quiet_every=3)
    data = make(q_rows, t_rows, 3000)
    f = data.features
    live = 900 * (1 - f["stale_900"])
    t = np.arange(1200, 3000)
    expected = np.maximum(f["sigma_900"][t] * np.sqrt(900 / live[t]), 0.1 / f["mid"][t])
    np.testing.assert_allclose(f["sigma_900_live"][t], expected, rtol=1e-9)
    mask = signal_mask("S7L-W900-K1.5", f, 1)
    with np.errstate(invalid="ignore"):
        np.testing.assert_array_equal(mask, (f["band_dev_900"] < -1.5 * f["sigma_900_live"]) & (f["fi_60"] > 0))


# 4-5. Quiet seconds, missing and stale quotes -----------------------------------------------------


def test_stale_or_missing_quotes_place_no_order():
    q_rows, t_rows = flat_book(200)
    q_rows = [row for row in q_rows if not 50 <= row[0] // SECOND - T0 < 80]  # 30 s without quote: age > 10 s from second 60
    t_rows = [row for row in t_rows if not 50 <= row[0] // SECOND - T0 < 80]
    data = make(q_rows, t_rows, 200)
    cols = compute_outcomes(data, Z3, np.array([45, 65, 75, 120]))
    assert cols["status"].tolist() == [UNFILLED, NONE, NONE, UNFILLED]
    late = make([row for row in flat_book(200)[0] if row[0] >= (T0 + 100) * SECOND], flat_book(200)[1], 200)
    assert compute_outcomes(late, Z3, np.array([10]))["status"][0] == NONE  # no quote yet: NaN mid


# 6. Stop at an adverse price after the latency ----------------------------------------------------


def test_stop_fills_below_the_trigger_after_the_latency():
    q_rows, t_rows = flat_book(5000)
    t_rows.append(((T0 + 3) * SECOND + 600_000, 99.9, 1.0, 0))  # fills the bid at 100.0
    t_rows.append(((T0 + 900) * SECOND + 100_000, 97.9, 1.0, 0))  # triggers the stop (trigger 98.0)
    t_rows.append(((T0 + 900) * SECOND + 300_000, 97.5, 1.0, 0))  # within the 250 ms latency: the execution is worse
    q_rows.append(((T0 + 900) * SECOND + 200_000, 97.6, 97.7, 1.0, 1.0))
    data = make(q_rows, t_rows, 5000)
    cols = compute_outcomes(data, Z3, np.array([1]))
    assert stop_trigger(100.0, 0.1) == pytest.approx(98.0)
    assert cols["status"][0] == FILLED and cols["s_reason"][0] == STOP
    assert cols["s_exit_ts"][0] == (T0 + 900) * SECOND + 350_000
    assert cols["s_exit"][0] == pytest.approx(97.5) and cols["s_slip_bp"][0] == pytest.approx((98.0 - 97.5) / 98.0 * 1e4)
    assert cols["z_reason"][0] == EXIT_REASONS["fallback"]  # without the stop: taker at fill + 3 900 s
    rows = trade_rows(cols, np.array([0]), "SL", 0, T0, Z3)
    assert rows["fees_bp"][0] == pytest.approx(7.0) and rows["net_bp"][0] == pytest.approx(-250.0 - 7.0)


def test_stop_is_inactive_after_the_passive_exit():
    q_rows, t_rows = flat_book(5000)
    t_rows.append(((T0 + 3) * SECOND + 600_000, 99.9, 1.0, 0))
    t_rows.append(((T0 + 3610) * SECOND, 100.2, 1.0, 1))  # through the ask posted at fill + 3 600.25 s
    t_rows.append(((T0 + 3700) * SECOND, 97.0, 1.0, 0))  # after the exit: no stop
    data = make(q_rows, t_rows, 5000)
    cols = compute_outcomes(data, Z3, np.array([1]))
    assert cols["z_reason"][0] == PASSIVE and cols["s_reason"][0] == PASSIVE and cols["s_exit"][0] == pytest.approx(100.1)


# 7. Unfilled orders ------------------------------------------------------------------------------


def test_unfilled_order_reserves_capital_until_the_ttl():
    q_rows, t_rows = flat_book(300)
    data = make(q_rows, t_rows, 300)
    cols = compute_outcomes(data, Z3)
    assert cols["status"][10] == UNFILLED
    stream = Stream("AAA", T0, 300, 0.001, 0.001, 5.0, cols, np.array([10, 20, 41]), 0)
    res = run_window([stream], "Z3", SCENARIOS["base"], Book(), 500.0, record=True)
    assert res.orders == 2 and res.trades == 0 and res.equity == 500.0  # 20 falls inside the 30 s TTL of 10, 41 is free again
    assert [item[1] - item[0] for item in res.items] == [30 * SECOND, 30 * SECOND]


# 8-10. Simultaneous orders, capital, exposure and risk ---------------------------------------------


def _filled_table(n=10_000, gain=0.01, thr=1e9):
    cols = {"status": np.full(n, FILLED, dtype=np.int8), "price": np.full(n, 100.0), "fill_ts": (T0 + np.arange(n) + 2) * SECOND, "thr_qty": np.full(n, thr),
            "fund_missing": np.zeros(n, dtype=np.int8)}
    for p in ("z", "s"):
        cols[f"{p}_exit"] = np.full(n, 100.0 * (1 + gain))
        cols[f"{p}_reason"] = np.full(n, PASSIVE, dtype=np.int8)
        cols[f"{p}_exit_ts"] = (T0 + np.arange(n) + 100) * SECOND
        cols[f"{p}_busy"] = np.arange(n, dtype=np.int32) + 100
        cols[f"{p}_fund_bp"] = np.zeros(n)
    return cols


def test_simultaneous_signals_go_alphabetically_and_slots_are_capped():
    streams = [Stream(sym, T0, 10_000, 0.001, 0.001, 5.0, _filled_table(), np.array([5]), k) for k, sym in enumerate(("CCC", "AAA", "BBB"))]
    res = run_window(streams, "Z3", SCENARIOS["base"], Book(), 500.0, record=True)
    assert [r["symbol"] for r in res.records] == ["AAA", "BBB"] and res.refusals["slots"] == 1


def test_sizing_risk_exposure_and_capital_refusals():
    book = Book()
    d = book.risk_unit(SCENARIOS["base"])
    assert d == pytest.approx(0.0227)
    stream = Stream("AAA", T0, 10_000, 0.001, 0.001, 5.0, _filled_table(), np.array([5]), 0)
    res = run_window([stream], "Z3", SCENARIOS["base"], book, 500.0, record=True)
    rec = res.records[0]
    assert rec["notional"] == pytest.approx(np.floor(0.005 * 500 / d / 100 / 0.001 + 1e-9) * 0.001 * 100)
    assert rec["nominal_risk"] <= 0.005 * 500 + 1e-9 and rec["notional"] <= 0.8 * 500
    tiny = Stream("AAA", T0, 10_000, 0.001, 0.001, 200.0, _filled_table(), np.array([5]), 0)  # minimum notional above the risk-sized order
    assert run_window([tiny], "Z3", SCENARIOS["base"], book, 500.0).refusals["min_notional"] == 1
    tight = Book(max_gross=0.1)
    assert run_window([stream], "Z3", SCENARIOS["base"], tight, 500.0).refusals["exposure"] == 1
    risky = Book(total_risk_pct=0.004)
    assert run_window([stream], "Z3", SCENARIOS["base"], risky, 500.0).refusals["risk"] == 1


def test_random_run_never_exceeds_the_limits():
    rng = np.random.default_rng(5)
    streams = []
    for k in range(6):
        cols = _filled_table(gain=0.0)
        cols["z_exit"] = 100.0 * (1 + rng.normal(0, 0.01, 10_000))
        streams.append(Stream(f"S{k}", T0, 10_000, 0.001, 0.001, 5.0, cols, np.sort(rng.choice(9_000, 300, replace=False)), k))
    res = run_window(streams, "Z3", SCENARIOS["base"], Book(), 500.0, record=True)
    events = sorted([(a, 1, n) for a, b, n, _ in res.items] + [(b, -1, n) for a, b, n, _ in res.items], key=lambda e: (e[0], e[1]))
    live = gross = 0
    for _, kind, notional in events:
        live += kind
        gross += kind * notional
        assert live <= 2 and gross <= 0.8 * 600
    assert res.refusals["slots"] > 0 and res.trades > 10


def test_partial_fill_is_capped_by_the_through_volume():
    stream = Stream("AAA", T0, 10_000, 0.001, 0.001, 5.0, _filled_table(thr=0.2), np.array([5]), 0)
    res = run_window([stream], "Z3", SCENARIOS["base"], Book(), 500.0, record=True)
    assert res.partial == 1 and res.records[0]["qty"] == pytest.approx(0.2)
    stream = Stream("AAA", T0, 10_000, 0.001, 0.001, 50.0, _filled_table(thr=0.2), np.array([5]), 0)
    res = run_window([stream], "Z3", SCENARIOS["base"], Book(), 500.0, record=True)
    assert res.partial_unfillable == 1 and res.trades == 0  # 20 USDT left below the 50 USDT minimum: treated as unfilled


# 11. Fees and funding ------------------------------------------------------------------------------


def test_portfolio_pnl_fees_slippage_and_funding():
    cols = _filled_table(gain=0.0)
    cols["z_exit"][:] = 101.0
    cols["z_reason"][:] = EXIT_REASONS["fallback"]
    cols["z_fund_bp"][:] = 1.0
    stream = Stream("AAA", T0, 10_000, 0.001, 0.001, 5.0, cols, np.array([5]), 0)
    res = run_window([stream], "Z3", SCENARIOS["pessimistic"], Book(), 500.0, record=True)
    r = res.records[0]
    q = r["qty"]
    expected = q * 1.0 - q * 100 * 3e-4 - q * 101 * 7.5e-4 - q * 101 * 3e-4 - q * 100 * 1e-4
    assert r["pnl"] == pytest.approx(expected) and res.equity == pytest.approx(500.0 + expected)


def test_funding_is_paid_across_settlements_and_gaps_are_charged_not_zeroed():
    tts = np.array([0, 10, 20, 30], dtype=np.int64) * 3600 * SECOND
    tpx = np.array([100.0, 100.0, 110.0, 110.0])
    settle = np.array([8, 16, 24, 32], dtype=np.int64) * 3600 * SECOND
    full = Funding(settle, np.array([1e-4, 2e-4, -1e-4, 1e-4]), np.full(4, 8.0), 8 * 3600, 24 * 3600, 3e-4)
    bp, missing = funding_bp(full, tts, tpx, np.array([7 * 3600 * SECOND]), np.array([17 * 3600 * SECOND]), np.array([100.0]))
    assert bp[0] == pytest.approx((1e-4 * 100 + 2e-4 * 100) / 100 * 1e4) and missing[0] == 0
    gap = Funding(settle[[0, 2, 3]], np.array([1e-4, -1e-4, 1e-4]), np.full(3, 8.0), 8 * 3600, 24 * 3600, 3e-4)
    assert gap.gaps
    bp, missing = funding_bp(gap, tts, tpx, np.array([9 * 3600 * SECOND]), np.array([17 * 3600 * SECOND]), np.array([100.0]))
    assert missing[0] == 1 and bp[0] == pytest.approx(3e-4 * 1e4)


# 12. Reproducible controls ---------------------------------------------------------------------------


def test_episode_shift_is_reproducible_and_keeps_lengths_and_hours():
    n, start = 5 * 86400, T0 - T0 % 86400
    signal = np.concatenate([np.arange(1000, 1010), np.arange(40_000, 40_003), np.arange(90_000, 90_050)])
    a = st.shift_episodes(signal, start, n, st.rng_for(1, 0, 7, 3))
    b = st.shift_episodes(signal, start, n, st.rng_for(1, 0, 7, 3))
    c = st.shift_episodes(signal, start, n, st.rng_for(1, 0, 8, 3))
    np.testing.assert_array_equal(a, b)
    assert not np.array_equal(a, c)
    starts, lengths = st.episodes(a)
    assert sorted(lengths.tolist()) == sorted(st.episodes(signal)[1].tolist()) or a.size <= signal.size
    assert set(((start + starts) // 3600 % 24).tolist()) <= set(((start + st.episodes(signal)[0]) // 3600 % 24).tolist())


def test_band_state_draws_match_strata_and_p_value():
    rng = st.rng_for(2, 0, 0, 0, 0)
    pool_s = np.arange(100)
    pool_st = np.where(pool_s < 50, 3, 7).astype(np.int16)
    draws, unmatched = st.band_state_draws(pool_s, pool_st, np.array([3, 3, 9]), rng)
    assert unmatched == 1 and (draws < 50).all()
    assert st.p_value(5.0, np.array([1.0, 2.0, 6.0])) == pytest.approx(2 / 4)
    boot = st.bootstrap_surplus(np.array([10.0, 20.0]), np.array([2.0, 2.0]), np.array([0.0, 2.0]), np.array([2.0, 2.0]))
    assert boot["point"] == pytest.approx(7.5 - 0.5)


# 13. Phase separation ---------------------------------------------------------------------------------


def test_only_the_training_windows_can_be_read():
    from scripts.research_exec.q3r2_run import TRAINING_WINDOWS, check_phase
    check_phase("training", TRAINING_WINDOWS)
    for phase in ("validation", "holdout"):
        with pytest.raises(SystemExit):
            check_phase(phase, TRAINING_WINDOWS)
    for window in ("january", "december-2024", "november", "fwd-okx-w1", "fwd-okx-w2", "m2411"):
        with pytest.raises(SystemExit):
            check_phase("training", (window,))
