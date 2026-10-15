"""Synthetic tests of the H1 Gate 2 engine (v1 §11–§15, A3 diagnostics); no market data."""

from __future__ import annotations

import math

import numpy as np
import pytest

from scripts.research_listing import conventions as cv
from scripts.research_listing import gate2 as g2

T0 = 1_700_000_000 - 1_700_000_000 % 3600
T = T0 + 24 * 3600
SPEC = {"ct_val": 1.0, "lot": 0.001, "min_contracts": 0.001, "min_notional": 0.0}


def bars_from(prices: list[float], start: int = T0, step: int = 60, highs: list[float] | None = None) -> g2.Bars:
    ts = np.arange(start, start + step * len(prices), step, dtype=np.int64)
    p = np.array(prices, dtype=float)
    return g2.Bars(ts, p.copy(), np.array(highs, dtype=float) if highs is not None else p.copy(), p.copy(), step)


def wiggle(n: int, base: float = 1.0, amp: float = 0.0005) -> list[float]:
    return [base * (1 + amp * (1 if k % 2 else -1)) for k in range(n)]


def test_time_exit_short_gains_when_price_falls():
    pre = wiggle(24 * 60)
    post = list(np.linspace(1.0, 0.8, 15 * 24 * 60))
    tr = g2.simulate(bars_from(pre + post), None, T0, T, SPEC, cv.BASELINE)
    assert tr["exit"] == "time" and tr["stop_distance"] == cv.STOP_FLOOR
    assert tr["gross"] > 0.15 and tr["net"] < tr["gross"]
    assert tr["R"] > 0


def test_stop_fills_at_worse_of_stop_and_next_open():
    pre = wiggle(24 * 60)
    post = [1.0] * 100 + [1.10] * 10
    highs = pre + [1.0] * 100 + [1.20] + [1.10] * 9  # the minute 100 trades through the 15 % stop
    nxt = post.copy()
    nxt[101] = 1.30  # the next minute opens above the stop: the fill is 1.30
    b = bars_from(pre + nxt, highs=highs[: len(pre) + len(nxt)])
    tr = g2.simulate(b, None, T0, T, SPEC, cv.BASELINE)
    assert tr["exit"] == "stop"
    exit_px = 1.30 * (1 + cv.BASELINE.stop_slippage)
    assert tr["gross"] == pytest.approx((1.0 * (1 - cv.BASELINE.entry_slippage) - exit_px) / 1.0)


def test_gap_open_above_stop_fills_at_the_open():
    pre = wiggle(24 * 60)
    post = [1.0] * 10 + [1.5] * 5
    tr = g2.simulate(bars_from(pre + post), None, T0, T, SPEC, cv.BASELINE)
    assert tr["exit"] == "stop"
    assert tr["gross"] < -0.49


def test_delisted_exit_last_close_and_unfavourable_sensitivity():
    pre = wiggle(24 * 60)
    post = [1.0] * 60 + [0.9] * 60 + [0.7] * 60
    b = bars_from(pre + post)
    main = g2.simulate(b, None, T0, T, SPEC, cv.BASELINE)
    sens = g2.simulate(b, None, T0, T, SPEC, cv.BASELINE, sens_exit=True)
    assert main["exit"] == sens["exit"] == "delisted"
    assert main["gross"] > sens["gross"]


def test_minimum_size_skip_is_never_rounded_up():
    pre = wiggle(24 * 60)
    tr = g2.simulate(bars_from(pre + [1.0] * 30), None, T0, T, {**SPEC, "min_notional": 50.0}, cv.BASELINE)
    assert tr["skip"] == "below_minimum_notional"


def test_funding_sign_and_missing_settlement_rule():
    b = bars_from([1.0] * (20 * 24 * 60), start=T0)
    ts = np.array([T - 8 * 3600, T + 8 * 3600, T + 16 * 3600, T + 48 * 3600], dtype=np.int64)  # a 32-h hole after T+16h
    rate = np.array([-0.002, 0.001, -0.003, 0.001])
    total, missing = g2.funding_cash((ts, rate), b, T, T + 60 * 3600, 1.0)
    assert missing == 3  # T+24, +32, +40 inside the hole; the tail T+48 → T+60 is within 1.5 × its 32-h cadence
    received = 0.001 - 0.003 + 0.001
    assert total < received


def test_missing_settlements_cadence():
    assert g2.missing_settlements([0, 8, 16, 40], 40) == [(24, 16), (32, 16)]
    assert g2.missing_settlements([0, 8, 16], 20) == []


def test_xperp_funding_caps_and_escalates():
    ts = np.array([T + 8 * 3600 * k for k in range(1, 6)], dtype=np.int64)
    calm = g2.xperp_funding((ts, np.full(5, 0.0001)), T, T + 40 * 3600)
    assert calm == pytest.approx(0.0005, rel=1e-6)
    crowded = g2.xperp_funding((ts, np.full(5, -0.05)), T, T + 40 * 3600)
    assert crowded <= -0.01 * 10 - 1e-12  # every 4 h at the cap at least, more while escalated


def test_portfolio_two_slots_and_skips():
    trades = [{"T": 0, "exit_ts": 100, "R": 1, "key": "a"}, {"T": 10, "exit_ts": 100, "R": 1, "key": "b"},
              {"T": 20, "exit_ts": 50, "R": 1, "key": "c"}, {"T": 120, "exit_ts": 200, "R": 1, "key": "d"}, {"T": 130, "skip": "below_minimum_size"}]
    done, skips = g2.portfolio(trades)
    assert [t["key"] for t in done] == ["a", "b", "d"]
    assert skips == {"risk_budget_full": 1, "below_minimum_size": 1}


def test_criteria_and_stats():
    done = [{"T": 1_700_000_000 + k * 86_400 * 12, "exit_ts": 1_700_000_000 + k * 86_400 * 12 + 1000, "R": 0.4, "pnl": 1.0, "net": 0.1,
             "gross": 0.12, "funding": 0.0, "funding_missing_booked": 0, "exit": "time", "notional": 10.0} for k in range(70)]
    s = g2.stats(done)
    crit = g2.criteria(s, s, {"surplus_R": 0.2, "p": 0.05}, "baseline")
    assert all(crit.values()), crit
    bad = g2.criteria(s, {"n": 70, "mean_R": -0.1}, {"surplus_R": 0.2, "p": 0.3}, "baseline")
    assert not bad["2_mean_net_R_gt_0_under_stress"] and not bad["4_surplus_over_control_gt_0_p_le_0.10"]


def test_run_refuses_an_existing_output(tmp_path):
    out = tmp_path / "gate2.json"
    out.write_text("{}")
    with pytest.raises(SystemExit):
        g2.run([], out)
