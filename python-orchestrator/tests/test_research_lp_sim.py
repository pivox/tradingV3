"""Tests of the passive-fill simulator (`scripts/research_lp/lp_sim.py`) on synthetic books and
prints: the three fill rules, the post-only model, latency, mark-outs, the exit, funding across
settlements, fees, and the portfolio reading."""

from __future__ import annotations

import math

import numpy as np
import pytest

from scripts.research_lp import events as ev
from scripts.research_lp import lp_sim as sim
from scripts.research_lp import tape

US = tape.SECOND_US
T0 = tape.parse_iso_utc("2026-11-05T10:00:00Z")
SPEC = sim.InstrumentSpec("BTC-USD_UM_XPERP-310404", tick=0.1, ct_val=1.0, lot=1.0, min_size=1.0)
NO_FUNDING = sim.FundingSchedule(np.zeros(0, dtype=np.int64), np.zeros(0))


def book_flat(bid=100.0, ask=100.1, bid_sz=10.0, ask_sz=8.0, until_s=1200, step_ms=500, start=T0):
    """A quiet one-level book repeated every `step_ms` for `until_s` seconds."""
    ts = np.arange(start, start + until_s * US + 1, step_ms * 1000, dtype=np.int64)
    return sim.Book.from_top(ts, np.full(ts.size, bid), np.full(ts.size, ask), np.full(ts.size, bid_sz), np.full(ts.size, ask_sz))


def with_prints(items):
    """`items` = (seconds after T0, price, size, taker_buy)."""
    ts = [T0 + int(round(s * US)) for s, *_ in items]
    return sim.Prints.from_lists(ts, [p for _, p, *_ in items], [q for _, _, q, _ in items], [b for *_, b in items])


def one_intent(decision=T0, side=1, price=100.0, size=1.0, tag=0, ttl_us=0):
    rows = np.zeros(1, dtype=sim.INTENT_DTYPE)
    rows[0] = (decision, side, price, size, tag, ttl_us)
    return rows


def params(**kw):
    base = {"rule": "queue", "latency_ms": 250.0, "ttl_s": 10.0, "exit_max_s": 30.0, "maker_bp": 2.0, "taker_bp": 5.0}
    base.update(kw)
    return sim.SimParams(**base)


# ----------------------------------------------------------------------------- the three fill rules

PRINTS = [(1.0, 100.0, 4.0, 0), (2.0, 100.0, 8.0, 0), (3.0, 99.9, 1.0, 0), (20.0, 100.2, 5.0, 1)]  # sells at the bid, then through, then a buyer lifting above the ask


def test_touch_rule_fills_on_the_first_opposite_print_at_the_price():
    rows = sim.simulate(book_flat(), with_prints(PRINTS), NO_FUNDING, SPEC, one_intent(), params(rule="touch"))
    assert rows["status"][0] == sim.STATUS["filled"] and rows["fill_ts"][0] == T0 + 1 * US and rows["filled_size"][0] == 1.0
    assert rows["queue_ahead"][0] == 10.0  # informational under touch


def test_through_rule_waits_for_a_print_strictly_beyond_the_price():
    rows = sim.simulate(book_flat(), with_prints(PRINTS), NO_FUNDING, SPEC, one_intent(), params(rule="through"))
    assert rows["status"][0] == sim.STATUS["filled"] and rows["fill_ts"][0] == T0 + 3 * US
    # Without the print through, the order stays unfilled under `through` while `touch` fills it.
    quiet = with_prints(PRINTS[:2])
    assert sim.simulate(book_flat(), quiet, NO_FUNDING, SPEC, one_intent(), params(rule="through"))["status"][0] == sim.STATUS["unfilled"]
    assert sim.simulate(book_flat(), quiet, NO_FUNDING, SPEC, one_intent(), params(rule="touch"))["status"][0] == sim.STATUS["filled"]


def test_queue_rule_consumes_the_displayed_queue_before_the_order():
    # 10 ahead at activation: the 4 at t=1 leave 6 ahead; the 8 at t=2 clear them and fill our 1 → fill at t=2.
    rows = sim.simulate(book_flat(), with_prints(PRINTS), NO_FUNDING, SPEC, one_intent(), params(rule="queue"))
    assert rows["status"][0] == sim.STATUS["filled"] and rows["fill_ts"][0] == T0 + 2 * US and rows["first_fill_ts"][0] == T0 + 2 * US and rows["queue_ahead"][0] == 10.0
    # A bigger own size fills partially at t=2 (2 of 3), then the print through at t=3 completes it.
    rows = sim.simulate(book_flat(), with_prints(PRINTS), NO_FUNDING, SPEC, one_intent(size=3.0), params(rule="queue"))
    assert rows["status"][0] == sim.STATUS["filled"] and rows["first_fill_ts"][0] == T0 + 2 * US and rows["fill_ts"][0] == T0 + 3 * US
    # Without the print through, 2 of 3 trade: `partial` at the TTL, the position dated from the first fill.
    rows = sim.simulate(book_flat(), with_prints(PRINTS[:2]), NO_FUNDING, SPEC, one_intent(size=3.0), params(rule="queue"))
    assert rows["status"][0] == sim.STATUS["partial"] and rows["filled_size"][0] == 2.0 and rows["fill_ts"][0] == T0 + 10 * US and rows["first_fill_ts"][0] == T0 + 2 * US
    # Same prints but a buyer's print at our price does not count (it hits the ask side).
    rows = sim.simulate(book_flat(), with_prints([(1.0, 100.0, 50.0, 1)]), NO_FUNDING, SPEC, one_intent(), params(rule="queue"))
    assert rows["status"][0] == sim.STATUS["unfilled"]


def test_queue_rule_bounds_the_queue_ahead_by_the_displayed_size():
    # The displayed bid size drops from 10 to 1 at t=0.5 (cancellations ahead): the 2 at t=1 clear 1 ahead and fill us.
    ts = np.array([T0, T0 + 500_000, T0 + 2 * US, T0 + 60 * US, T0 + 400 * US, T0 + 1000 * US], dtype=np.int64)
    book = sim.Book.from_top(ts, [100.0] * 6, [100.1] * 6, [10.0, 1.0, 1.0, 1.0, 1.0, 1.0], [8.0] * 6)
    rows = sim.simulate(book, with_prints([(1.0, 100.0, 2.0, 0)]), NO_FUNDING, SPEC, one_intent(), params(rule="queue"))
    assert rows["status"][0] == sim.STATUS["filled"] and rows["fill_ts"][0] == T0 + 1 * US


def test_book_trading_through_the_price_fills_every_rule():
    # No prints at all, but the ask falls to 99.9 at t=4: our bid at 100.0 was necessarily executed.
    ts = np.array([T0, T0 + 4 * US, T0 + 60 * US, T0 + 400 * US, T0 + 1000 * US], dtype=np.int64)
    book = sim.Book.from_top(ts, [100.0, 99.8, 99.8, 99.8, 99.8], [100.1, 99.9, 99.9, 99.9, 99.9])
    for rule in sim.RULES:
        rows = sim.simulate(book, with_prints([]), NO_FUNDING, SPEC, one_intent(), params(rule=rule))
        assert rows["status"][0] == sim.STATUS["filled"] and rows["fill_ts"][0] == T0 + 4 * US, rule


# ----------------------------------------------------------------------------- post-only, latency, placement


def test_post_only_cancels_an_order_that_would_cross_at_activation():
    # The ask drops to 100.0 at t=0.2 s; our buy at 100.0 goes live at 0.25 s and would take: cancelled.
    ts = np.array([T0, T0 + 200_000, T0 + 60 * US, T0 + 1000 * US], dtype=np.int64)
    book = sim.Book.from_top(ts, [99.9, 99.9, 99.9, 99.9], [100.1, 100.0, 100.0, 100.0])
    rows = sim.simulate(book, with_prints([(1.0, 100.0, 5.0, 0)]), NO_FUNDING, SPEC, one_intent(), params())
    assert rows["status"][0] == sim.STATUS["crossed"] and rows["activation_ts"][0] == T0 + 250_000 and rows["ask0"][0] == 100.0
    # Without the post-only model the same order rests and fills.
    rows = sim.simulate(book, with_prints([(1.0, 100.0, 5.0, 0)]), NO_FUNDING, SPEC, one_intent(), params(post_only=False))
    assert rows["status"][0] == sim.STATUS["filled"]
    # A sell priced at the bid is crossed too; a sell at the ask rests.
    flat = book_flat()
    assert sim.simulate(flat, with_prints([]), NO_FUNDING, SPEC, one_intent(side=-1, price=100.0), params())["status"][0] == sim.STATUS["crossed"]
    assert sim.simulate(flat, with_prints([]), NO_FUNDING, SPEC, one_intent(side=-1, price=100.1), params())["status"][0] == sim.STATUS["unfilled"]


def test_latency_delays_activation_and_prints_before_it_do_not_fill():
    rows = sim.simulate(book_flat(), with_prints(PRINTS), NO_FUNDING, SPEC, one_intent(), params(rule="touch", latency_ms=1500.0))
    assert rows["activation_ts"][0] == T0 + 1_500_000 and rows["fill_ts"][0] == T0 + 2 * US
    # A TTL shorter than the first opposite print leaves the order unfilled.
    rows = sim.simulate(book_flat(), with_prints(PRINTS), NO_FUNDING, SPEC, one_intent(), params(rule="touch", latency_ms=1500.0, ttl_s=1.8))
    assert rows["status"][0] == sim.STATUS["unfilled"]


def test_intents_follow_the_placement_and_the_lot_rules():
    book = book_flat(bid=100.0, ask=100.3, until_s=3700)
    p = params(grid_s=60.0, ttl_s=60.0, placement="touch", order_notional=250.0)
    intents = sim.grid_intents(book, SPEC, p)
    decisions = np.unique(intents["decision_ts"])
    assert decisions[0] == T0 and np.all(np.diff(decisions) == 60 * US) and intents.size == 2 * decisions.size
    assert decisions[-1] <= book.ts[-1] - (60 + 900) * US  # room for the TTL and the longest horizon
    buys = intents[intents["side"] == 1]
    assert np.all(buys["price"] == 100.0) and np.all(buys["size"] == 2.0)  # 250 USD at 100 → 2 contracts
    sells = intents[intents["side"] == -1]
    assert np.all(sells["price"] == pytest.approx(100.3))
    inside = sim.grid_intents(book, SPEC, params(placement="inside", grid_s=60.0, ttl_s=60.0))
    assert inside["price"][0] == pytest.approx(100.1) and inside[inside["side"] == -1]["price"][0] == pytest.approx(100.2)
    away = sim.grid_intents(book, SPEC, params(placement="ticks:3", grid_s=60.0, ttl_s=60.0))
    assert away["price"][0] == pytest.approx(99.7)
    # One-tick spread: no room inside → `no_inside`; a stale quote → `stale`.
    tight = book_flat(bid=100.0, ask=100.1, until_s=3700)
    rows = sim.simulate(tight, with_prints([]), NO_FUNDING, SPEC, sim.grid_intents(tight, SPEC, params(placement="inside", grid_s=600.0, ttl_s=60.0)), params(placement="inside"))
    assert set(rows["status"].tolist()) == {sim.STATUS["no_inside"]}
    stale_book = sim.Book.from_top([T0, T0 + 3600 * US], [100.0, 100.0], [100.1, 100.1])
    stale = sim.resolve_intents(np.array([T0 + 1800 * US]), np.array([1], dtype=np.int8), np.zeros(1, dtype=np.int32), stale_book, SPEC, params())
    assert np.isnan(stale["price"][0]) and sim.simulate(stale_book, with_prints([]), NO_FUNDING, SPEC, stale, params())["status"][0] == sim.STATUS["stale"]
    # A notional below one lot is skipped.
    assert SPEC.size_for_notional(50.0, 100.0) == 0.0 and sim.InstrumentSpec("x", 0.1, ct_val=0.01, lot=1, min_size=1).size_for_notional(100.0, 100.0) == 100.0
    assert sim.simulate(book, with_prints([]), NO_FUNDING, SPEC, one_intent(size=0.0), params())["status"][0] == sim.STATUS["skipped_size"]
    # The M3 case the CLI now refuses: an X-Perp of ctVal 0.0001 BTC read with ctVal 1.0 sizes 100 USD at 60,000 to 0 contracts.
    assert sim.InstrumentSpec("x", 0.1, ct_val=1.0).size_for_notional(100.0, 60_000.0) == 0.0 and sim.InstrumentSpec("x", 0.1, ct_val=0.0001).size_for_notional(100.0, 60_000.0) == 16.0


# ----------------------------------------------------------------------------- event intents: detected vs scheduled


def test_scheduled_clock_windows_are_decided_at_the_arming_instant_and_rest_until_the_window_end():
    """M2: a G5 window at h is decided at h − 5 (never h − 1) and its order lives until h + 1; detected events keep the next-minute rule."""
    h = tape.parse_iso_utc("2026-11-05T08:00:00Z")
    book = book_flat(start=h - 600 * US, until_s=1200)  # 07:50 → 08:10
    windows = ev.clock_windows(["2026-11-05"], arm_min=5, end_min=1)
    window = windows[(windows["kind"] == ev.KINDS["clock_funding"]) & (windows["ts_us"] == h)]
    assert window.size == 1 and window[0]["arm_ts_us"] == h - 300 * US and window[0]["end_ts_us"] == h + 60 * US
    p = params(rule="touch", ttl_s=60.0, exit_max_s=30.0, order_notional=200.0)  # 200 USD → 1 contract on both sides (100 USD would size the sell at 100.1 to 0)
    intents = sim.event_intents(window["ts_us"], window["side"], book, SPEC, p, tags=window["kind"].astype(np.int32), arm_ts_us=window["arm_ts_us"], end_ts_us=window["end_ts_us"])
    assert intents.size == 2 and set(intents["decision_ts"].tolist()) == {h - 300 * US} and set(intents["ttl_us"].tolist()) == {360 * US} and set(intents["side"].tolist()) == {1, -1}
    # A seller hits the bid at 08:00:40 — 5 min 40 s after the decision, inside the window: the buy fills under the window's own TTL, where the 60 s default would have expired.
    prints = sim.Prints.from_lists([h + 40 * US], [100.0], [1.0], [0])
    rows = sim.simulate(book, prints, NO_FUNDING, SPEC, intents, p)
    buy, sell = rows[rows["side"] == 1][0], rows[rows["side"] == -1][0]
    assert buy["status"] == sim.STATUS["filled"] and buy["fill_ts"] == h + 40 * US and buy["ttl_us"] == 360 * US and buy["activation_ts"] == h - 300 * US + 250_000
    assert sell["status"] == sim.STATUS["unfilled"] and sell["ttl_us"] == 360 * US
    # The legacy call (no arm / end) decided at h + 1 with the global TTL and missed the print: the behaviour the review reproduced.
    legacy = sim.event_intents(window["ts_us"], window["side"], book, SPEC, p)
    assert set(legacy["decision_ts"].tolist()) == {h + 60 * US} and set(legacy["ttl_us"].tolist()) == {0}
    assert sim.simulate(book, prints, NO_FUNDING, SPEC, legacy, p)["ttl_us"].tolist() == [60 * US, 60 * US]
    # Detected events (arm == ts): the next minute boundary, no own TTL — even when `end_ts_us` lies ahead (a cool-down is not a lifetime).
    t = h + 7 * US
    decisions, ttl = sim.event_decisions(np.array([t, t]), np.array([t, t]), np.array([t, t + 900 * US]))
    assert decisions.tolist() == [h + 60 * US, h + 60 * US] and ttl.tolist() == [0, 0]
    decisions, ttl = sim.event_decisions(np.array([t]), None, None, next_minute=False)
    assert decisions.tolist() == [t] and ttl.tolist() == [0]
    # A scheduled window without an end keeps the global TTL; an end before the arming instant is ignored.
    decisions, ttl = sim.event_decisions(np.array([h, h]), np.array([h - 300 * US, h - 300 * US]), np.array([0, h - 400 * US]))
    assert decisions.tolist() == [h - 300 * US, h - 300 * US] and ttl.tolist() == [0, 0]
    assert sim.event_decisions(np.zeros(0, dtype=np.int64))[0].size == 0


def test_portfolio_reading_holds_an_unfilled_order_for_its_own_ttl():
    p = params(capital=500.0, max_live=1, order_notional=100.0, ttl_s=10.0)
    rows = np.zeros(2, dtype=sim.ORDER_DTYPE)
    rows["decision_ts"] = [T0, T0 + 120 * US]
    rows["activation_ts"] = rows["decision_ts"] + 250_000
    rows["side"], rows["price"], rows["size"] = 1, 100.0, 1.0
    rows["status"] = sim.STATUS["unfilled"]
    rows["exit_ts"] = -1
    rows["ttl_us"] = [360 * US, 0]  # a clock-window order resting six minutes, then a grid order
    assert sim.portfolio_admit(rows, SPEC, p).tolist() == [1, 0]  # the first commitment is still alive at T0 + 120 s
    rows["ttl_us"] = [0, 0]
    assert sim.portfolio_admit(rows, SPEC, p).tolist() == [1, 1]  # with the 10 s default it has expired


# ----------------------------------------------------------------------------- mark-outs, exit, fees, funding


def test_markouts_exit_and_fees():
    # Fill at t=1 (touch) at 100.0; the mid rises to 100.25 at t=61, 100.45 at t=301, 100.95 at t=901.
    ts = [T0, T0 + 61 * US, T0 + 301 * US, T0 + 901 * US, T0 + 1200 * US]
    book = sim.Book.from_top(ts, [100.0, 100.2, 100.4, 100.9, 100.9], [100.1, 100.3, 100.5, 101.0, 101.0])
    prints = with_prints([(1.0, 100.0, 1.0, 0), (5.0, 100.1, 1.0, 1)])  # a buyer lifts the ask at t=5: our exit sell at 100.1 fills under `touch`
    rows = sim.simulate(book, prints, NO_FUNDING, SPEC, one_intent(), params(rule="touch", exit_max_s=30.0))
    r = rows[0]
    assert r["status"] == sim.STATUS["filled"] and r["m_0"] == pytest.approx((100.05 - 100.0) / 100.0 * 1e4)
    assert r["m_60s"] == pytest.approx((100.25 - 100.0) / 100.0 * 1e4) and r["m_300s"] == pytest.approx(45.0) and r["m_900s"] == pytest.approx(95.0)
    assert r["exit_kind"] == sim.EXIT_KIND["maker"] and r["exit_ts"] == T0 + 5 * US and r["exit_price"] == pytest.approx(100.1)
    assert r["rt_bp"] == pytest.approx(10.0) and r["fee_bp"] == pytest.approx(4.0) and r["funding_bp"] == 0.0 and r["settlements"] == 0 and r["net_bp"] == pytest.approx(6.0)
    # No buyer for the exit: taker exit at the bid after exit_max (30 s → the quote at t=0 still rules at t=31: bid 100.0).
    rows = sim.simulate(book, with_prints([(1.0, 100.0, 1.0, 0)]), NO_FUNDING, SPEC, one_intent(), params(rule="touch", exit_max_s=30.0))
    r = rows[0]
    assert r["exit_kind"] == sim.EXIT_KIND["taker"] and r["exit_ts"] == T0 + 31 * US and r["exit_price"] == pytest.approx(100.0)
    assert r["rt_bp"] == pytest.approx(0.0) and r["fee_bp"] == pytest.approx(7.0) and r["net_bp"] == pytest.approx(-7.0)
    # The bid rising to 100.2 at t=61 trades through the resting exit sell at 100.1: a passive exit, even with a long window (flagged as clipped by the tape end).
    rows = sim.simulate(book, with_prints([(1.0, 100.0, 1.0, 0)]), NO_FUNDING, SPEC, one_intent(), params(rule="touch", exit_max_s=5000.0))
    assert rows["exit_truncated"][0] == 1 and rows["exit_kind"][0] == sim.EXIT_KIND["maker"] and rows["exit_ts"][0] == T0 + 61 * US and rows["exit_price"][0] == pytest.approx(100.1)
    # On a quiet short tape the exit window is clipped at the last snapshot, taker at the bid; mark-outs beyond the tape end stay NaN.
    short = book_flat(until_s=100)
    rows = sim.simulate(short, with_prints([(1.0, 100.0, 1.0, 0)]), NO_FUNDING, SPEC, one_intent(), params(rule="touch", exit_max_s=5000.0))
    assert rows["exit_truncated"][0] == 1 and rows["exit_kind"][0] == sim.EXIT_KIND["taker"] and rows["exit_ts"][0] == T0 + 100 * US and rows["exit_price"][0] == pytest.approx(100.0)
    assert math.isfinite(rows["m_60s"][0]) and math.isnan(rows["m_300s"][0]) and math.isnan(rows["m_900s"][0])


def test_funding_schedules_and_the_rates_crossed():
    start = tape.parse_iso_utc("2026-11-05T07:00:00Z")
    eight = sim.FundingSchedule.constant(start, start + 20 * 3600 * US, 8.0, 0.0001)
    assert [tape.iso_utc(int(t))[11:16] for t in eight.ts_us[:3]] == ["08:00", "16:00", "00:00"]
    four = sim.FundingSchedule.constant(start, start + 10 * 3600 * US, 4.0, 0.0005)
    assert [tape.iso_utc(int(t))[11:16] for t in four.ts_us[:4]] == ["08:00", "12:00", "16:00", "20:00"]
    s0800 = tape.parse_iso_utc("2026-11-05T08:00:00Z")
    assert eight.crossed(s0800 - 60 * US, s0800 + 60 * US) == (1, pytest.approx(0.0001))  # open before, close after the settlement
    assert eight.crossed(s0800 - 60 * US, s0800) == (1, pytest.approx(0.0001))  # a position open at the instant pays
    assert eight.crossed(s0800, s0800 + 60 * US) == (0, 0.0)  # opened at the instant: not assessed
    assert eight.crossed(s0800 - 60 * US, s0800 + 9 * 3600 * US) == (2, pytest.approx(0.0002))
    # From the tape: the settled rate wins over the announced one for the same funding time.
    rows = np.zeros(3, dtype=tape.FUNDING_DTYPE)
    rows["ts_us"] = [s0800 - 3600 * US, s0800 - 60 * US, s0800 + 30 * US]
    rows["funding_time_us"] = [s0800, s0800, s0800]
    rows["funding_rate"] = [0.0003, 0.00025, 0.00025]
    rows["sett_state"] = [0, 0, 1]
    rows["sett_funding_rate"] = [np.nan, np.nan, 0.0002]
    schedule = sim.FundingSchedule.from_tape(rows)
    assert schedule.ts_us.tolist() == [s0800] and schedule.rate.tolist() == [0.0002] and schedule.source == "tape"
    assert sim.FundingSchedule.from_tape(rows[:2]).rate.tolist() == [0.00025]


def test_funding_is_charged_when_the_round_trip_crosses_a_settlement():
    # Long filled at 15:59:30, exit maker at 16:00:30: the 16:00 settlement (rate 0.0005) costs the long 5 bp; the short earns it.
    start = tape.parse_iso_utc("2026-11-05T15:59:00Z")
    ts = [start, start + 60 * US, start + 120 * US, start + 1100 * US]
    book = sim.Book.from_top(ts, [100.0] * 4, [100.1] * 4)
    prints = sim.Prints.from_lists([start + 30 * US, start + 90 * US], [100.0, 100.1], [1.0, 1.0], [0, 1])
    funding = sim.FundingSchedule.constant(start, start + 2000 * US, 8.0, 0.0005)
    long_rows = sim.simulate(book, prints, funding, SPEC, one_intent(decision=start, side=1, price=100.0), params(rule="touch", ttl_s=60.0, exit_max_s=120.0))
    assert long_rows["settlements"][0] == 1 and long_rows["funding_bp"][0] == pytest.approx(-5.0) and long_rows["net_bp"][0] == pytest.approx(10.0 - 4.0 - 5.0)
    short_prints = sim.Prints.from_lists([start + 30 * US, start + 90 * US], [100.1, 100.0], [1.0, 1.0], [1, 0])
    short_rows = sim.simulate(book, short_prints, funding, SPEC, one_intent(decision=start, side=-1, price=100.1), params(rule="touch", ttl_s=60.0, exit_max_s=120.0))
    assert short_rows["funding_bp"][0] == pytest.approx(5.0) and short_rows["rt_bp"][0] == pytest.approx((100.1 - 100.0) / 100.1 * 1e4)
    # The same trip one hour earlier crosses nothing.
    early = sim.FundingSchedule.constant(start, start + 2000 * US, 8.0, 0.0005, anchor_h=1.0)  # settlements at 01/09/17 UTC
    assert sim.simulate(book, prints, early, SPEC, one_intent(decision=start, side=1, price=100.0), params(rule="touch", ttl_s=60.0, exit_max_s=120.0))["settlements"][0] == 0


# ----------------------------------------------------------------------------- portfolio reading and summaries


def test_portfolio_reading_caps_live_orders_and_frozen_notional():
    p = params(capital=500.0, max_live=4, order_notional=100.0, ttl_s=10.0)
    rows = np.zeros(7, dtype=sim.ORDER_DTYPE)
    rows["decision_ts"] = [T0] * 6 + [T0 + 20 * US]
    rows["activation_ts"] = rows["decision_ts"] + 250_000
    rows["side"] = 1
    rows["price"] = 100.0
    rows["size"] = 1.0  # 100 USD each at ctVal 1
    rows["status"] = [sim.STATUS["filled"]] * 5 + [sim.STATUS["stale"], sim.STATUS["unfilled"]]
    rows["exit_ts"] = [T0 + 15 * US] * 5 + [-1, -1]
    admitted = sim.portfolio_admit(rows, SPEC, p)
    assert admitted.tolist() == [1, 1, 1, 1, 0, -1, 1]  # four live at most; the stale one is not eligible; after the exits the next order fits
    tight = sim.portfolio_admit(rows, SPEC, sim.SimParams(capital=250.0, max_live=10, order_notional=100.0, ttl_s=10.0))
    assert tight.tolist() == [1, 1, 0, 0, 0, -1, 1]  # 250 USD at 1× freezes two orders of 100


def test_summaries_use_fsum_means_and_report_both_readings():
    book = book_flat(until_s=4000)
    prints = with_prints([(s + 1.0, 100.0, 2.0, 0) for s in range(0, 2400, 60)] + [(s + 5.0, 100.1, 2.0, 1) for s in range(0, 2400, 60)])
    p = params(rule="touch", grid_s=60.0, ttl_s=30.0, exit_max_s=20.0)
    intents = sim.grid_intents(book, SPEC, p)
    rows = sim.simulate(book, prints, NO_FUNDING, SPEC, intents, p)
    both = sim.summarize_both(rows, SPEC, p)
    unc, port = both["unconstrained"], both["portfolio"]
    assert unc["orders"] == rows.size and unc["fills"] > 0 and unc["fill_rate"] == pytest.approx(unc["fills"] / unc["eligible"])
    assert unc["markouts_bp"]["m_60s"]["n"] == unc["fills"] and unc["markouts_bp"]["m_0"]["mean"] == pytest.approx(5.0, abs=1e-6)  # half spread captured
    assert unc["round_trip"]["net_bp_mean"] == pytest.approx(10.0 - 4.0, abs=1e-6) and unc["round_trip"]["exit_kind"]["maker"] == unc["round_trip"]["n"]
    assert unc["round_trip"]["net_bp_lb90"] is not None and list(unc["by_half_spread_bp"]) == ["[4,8)"] and "10" in unc["by_hour_utc"]  # 0.1 on 100.05 → half spread 5 bp
    assert port["orders"] + port["refused_capacity"] <= unc["orders"] and port["label"].startswith("portfolio 500")
    assert sim.fsum_mean([0.1] * 10) == pytest.approx(0.1) and math.isnan(sim.fsum_mean([float("nan")]))
    assert sim.block_bootstrap_lower_bound(np.array([1.0, 1.0]), np.array([T0, T0 + 1])) == pytest.approx(1.0)
    assert sim.block_bootstrap_lower_bound(np.array([np.nan]), np.array([T0])) is None
