"""Tests of the tick-data scalping screen: bars, features, rules, entry and exit policies."""

from __future__ import annotations

import numpy as np
import pytest

from scripts.research_exec import tick
from scripts.research_exec.capture import QUOTE_DTYPE, TRADE_DTYPE, Instrument
from scripts.research_exec.scalp_engine import EXIT_REASONS, Policy, SeriesData, run_policy
from scripts.research_exec.tick import build_bars, compute_features, rule_catalogue, signal_mask

SECOND = 1_000_000
T0 = 1_790_000_000
INSTRUMENT = Instrument("BTCUSDT", "BTC-USDT-SWAP", 0.01, 0.01, 0.01, fixed_tick=0.1)


def quotes_from(rows):
    return np.array([(ts, ts, bid, ask, bs, asz) for ts, bid, ask, bs, asz in rows], dtype=QUOTE_DTYPE)


def trades_from(rows):
    return np.array([(ts, ts, price, size, taker_buy, 1) for ts, price, size, taker_buy in rows], dtype=TRADE_DTYPE)


def test_bars_forward_fill_quotes_and_aggregate_prints():
    quotes = quotes_from([(T0 * SECOND - 500_000, 99.0, 99.1, 1.0, 2.0), ((T0 + 1) * SECOND + 200_000, 100.0, 100.1, 3.0, 1.0), ((T0 + 1) * SECOND + 900_000, 100.2, 100.3, 1.0, 1.0)])
    trades = trades_from([((T0 + 1) * SECOND + 300_000, 100.0, 2.0, 1), ((T0 + 1) * SECOND + 400_000, 99.9, 1.0, 0), ((T0 + 3) * SECOND, 101.0, 1.0, 1)])
    bars = build_bars(quotes, trades, T0, T0 + 4)
    assert bars["bid"].tolist()[:2] == [99.0, 100.2] and bars["ask"][2] == pytest.approx(100.3)  # second 0 seeded by the earlier quote, forward-filled after
    assert bars["quote_age"][0] == pytest.approx(1.5) and bars["quote_age"][3] == pytest.approx(2.1)
    assert bars["buy_vol"][1] == pytest.approx(2.0) and bars["sell_vol"][1] == pytest.approx(1.0) and bars["n"][1] == 2
    assert bars["high"][1] == pytest.approx(100.0) and bars["low"][1] == pytest.approx(99.9) and np.isnan(bars["high"][2])
    assert bars["last"][1] == pytest.approx(99.9) and bars["last"][3] == pytest.approx(101.0)


def _synthetic_series(seconds: int = 8000, seed: int = 3):
    rng = np.random.default_rng(seed)
    steps = rng.normal(0, 2e-5, size=seconds).cumsum()
    mid = 100.0 * np.exp(steps)
    q_rows, t_rows = [], []
    for s in range(seconds):
        bid = np.floor(mid[s] / 0.1) * 0.1
        q_rows.append(((T0 + s) * SECOND + 100_000, bid, bid + 0.1, 1.0 + rng.random(), 1.0 + rng.random()))
        for k in range(3):
            buy = int(rng.random() < 0.5)
            t_rows.append(((T0 + s) * SECOND + 200_000 + k * 100_000, bid + 0.1 if buy else bid, 0.5, buy))
    return quotes_from(q_rows), trades_from(t_rows)


def test_features_and_rules_have_the_bar_shape_and_fire_sometimes():
    quotes, trades = _synthetic_series()
    bars = build_bars(quotes, trades, T0, T0 + 8000)
    f = compute_features(bars, INSTRUMENT)
    assert f["r_10"].shape == (8000,) and np.isnan(f["r_10"][:9]).all() and np.isfinite(f["r_10"][10:]).all()
    assert (f["sigma_60"][60:] >= 0.1 / bars["mid"][60:] - 1e-12).all()
    assert np.isfinite(f["fi_60"][60:]).all() and (np.abs(f["fi_60"][60:]) <= 1).all()
    assert np.isnan(f["a_60"][:3599]).all() and np.isfinite(f["a_60"][3600:]).all()
    assert np.isfinite(f["dev"][5:]).all() and np.isfinite(f["band_dev"][5:]).all() and np.isfinite(f["qi_5"][5:]).all()
    fired = {rule: int(signal_mask(rule, f, 1).sum() + signal_mask(rule, f, -1).sum()) for rule in rule_catalogue(book=True)}
    assert len(fired) == 23 and len(rule_catalogue(book=False)) == 19
    assert all(count >= 0 for count in fired.values()) and sum(fired.values()) > 0
    with pytest.raises(ValueError):
        signal_mask("S9-W10", f, 1)


def _series_with_known_prints():
    # Flat book 100.0 / 100.1 every second; prints every second at 100.05 (no fill), plus a print at 99.9 at +31.5 s.
    q_rows = [((T0 + s) * SECOND + 50_000, 100.0, 100.1, 1.0, 1.0) for s in range(700)]
    t_rows = [((T0 + s) * SECOND + 500_000, 100.05, 0.1, 1) for s in range(700)]
    return q_rows, t_rows


def _make(q_rows, t_rows, seconds=700):
    quotes = quotes_from(sorted(q_rows))
    trades = trades_from(sorted(t_rows))
    bars = build_bars(quotes, trades, T0, T0 + seconds)
    features = compute_features(bars, INSTRUMENT)
    return SeriesData(0, bars, features, quotes, trades, INSTRUMENT, T0)


def test_entry_fill_blocks_and_x1_time_exit():
    q_rows, t_rows = _series_with_known_prints()
    t_rows.append(((T0 + 31) * SECOND + 500_000, 99.9, 1.0, 0))  # through the bid, but 30.5 s after the decision at the end of second 0: outside the 30 s TTL
    data = _make(q_rows, t_rows)
    policy = Policy("E1", "X1")
    trades, counters = run_policy(data, 1, np.array([0, 1, 2, 3, 4, 100]), policy)
    # Decision 0: order at 100.0, live at T0+1.25 s, TTL until T0+31 s → unfilled, blocks until second 32; 1..4 skipped; 100 unfilled.
    assert counters.decisions == 2 and counters.unfilled == 2 and trades.size == 0
    t_rows.append(((T0 + 5) * SECOND + 500_000, 99.9, 1.0, 0))  # inside the TTL of a decision at second 2
    data = _make(q_rows, t_rows)
    trades, counters = run_policy(data, 1, np.array([2]), policy)
    assert trades.size == 1 and trades["fill_ts"][0] == (T0 + 5) * SECOND + 500_000
    assert trades["exit_reason"][0] == EXIT_REASONS["time"] and trades["exit"][0] == pytest.approx(100.0)  # sell at the bid
    assert trades["gross_bp"][0] == pytest.approx(0.0) and trades["net_bp"][0] == pytest.approx(-7.0)
    assert trades["hold_s"][0] == pytest.approx(60.25)


def test_crossing_at_activation_is_cancelled_and_blocks_five_seconds():
    q_rows, t_rows = _series_with_known_prints()
    q_rows.append(((T0 + 1) * SECOND + 100_000, 99.8, 99.9, 1.0, 1.0))  # the book drops right after the decision at the end of second 0
    data = _make(q_rows, t_rows)
    trades, counters = run_policy(data, 1, np.array([0, 3, 7]), Policy("E1", "X1"))
    assert counters.crossed == 1 and counters.decisions == 2  # second 3 blocked, second 7 evaluated


def test_x3_stop_before_target_and_target_fill():
    q_rows, t_rows = _series_with_known_prints()
    t_rows.append(((T0 + 2) * SECOND + 600_000, 99.9, 1.0, 0))  # fills the bid at 100.0 (decision at second 0 → live at 1.25 s)
    t_rows.append(((T0 + 10) * SECOND + 100_000, 99.7, 1.0, 0))  # stop region (sigma floor two ticks → stop 99.8)
    t_rows.append(((T0 + 10) * SECOND + 200_000, 100.5, 1.0, 1))  # target region in the same second → the stop wins
    data = _make(q_rows, t_rows)
    trades, _ = run_policy(data, 1, np.array([0]), Policy("E1", "X3"))
    assert trades.size == 1 and trades["exit_reason"][0] == EXIT_REASONS["stop"]
    assert trades["exit"][0] == pytest.approx(100.0)  # taker at the (flat) bid after the latency
    # Target only: a print above entry + 1.5 × 0.2 = 100.3 → the passive target at 100.3 fills.
    q_rows, t_rows = _series_with_known_prints()
    t_rows.append(((T0 + 2) * SECOND + 600_000, 99.9, 1.0, 0))
    t_rows.append(((T0 + 20) * SECOND + 100_000, 100.35, 1.0, 1))
    data = _make(q_rows, t_rows)
    trades, _ = run_policy(data, 1, np.array([0]), Policy("E1", "X3"))
    assert trades["exit_reason"][0] == EXIT_REASONS["target"] and trades["exit"][0] == pytest.approx(100.3)
    assert trades["fees_bp"][0] == pytest.approx(4.0) and trades["net_bp"][0] == pytest.approx(30.0 - 4.0, abs=0.01)


def test_x4_passive_exit_then_fallback():
    q_rows, t_rows = _series_with_known_prints()
    t_rows.append(((T0 + 2) * SECOND + 600_000, 99.9, 1.0, 0))
    t_rows.append(((T0 + 70) * SECOND + 100_000, 100.15, 1.0, 1))  # above the ask 100.1 posted at fill + 60 s → passive exit
    data = _make(q_rows, t_rows)
    trades, _ = run_policy(data, 1, np.array([0]), Policy("E1", "X4"))
    assert trades["exit_reason"][0] == EXIT_REASONS["passive"] and trades["exit"][0] == pytest.approx(100.1) and trades["fees_bp"][0] == pytest.approx(4.0)
    q_rows, t_rows = _series_with_known_prints()
    t_rows.append(((T0 + 2) * SECOND + 600_000, 99.9, 1.0, 0))
    data = _make(q_rows, t_rows)
    trades, _ = run_policy(data, 1, np.array([0]), Policy("E1", "X4"))
    assert trades["exit_reason"][0] == EXIT_REASONS["fallback"] and trades["exit"][0] == pytest.approx(100.0) and trades["fees_bp"][0] == pytest.approx(7.0)


def test_short_side_mirrors_the_long_side():
    q_rows, t_rows = _series_with_known_prints()
    t_rows.append(((T0 + 2) * SECOND + 600_000, 100.2, 1.0, 1))  # through the ask at 100.1
    data = _make(q_rows, t_rows)
    trades, _ = run_policy(data, -1, np.array([0]), Policy("E1", "X1"))
    assert trades.size == 1 and trades["entry"][0] == pytest.approx(100.1) and trades["exit"][0] == pytest.approx(100.1)  # buy back at the ask
    assert trades["side"][0] == -1 and trades["net_bp"][0] == pytest.approx(-7.0)
