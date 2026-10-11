"""Synthetic swing-screen tests: PHP-identical indicators, signals, plans, multi-day simulation and CLI."""
from __future__ import annotations

import json
import math
import random
from array import array

import pytest

from app.backtesting.research import exploration as ex
from app.backtesting.research import swing as sw

START = 1_709_510_400_000  # 2024-03-04T00:00:00Z, a training day
BASELINE = {'entry_spread_rate': 0, 'stop_spread_rate': 0.0002, 'target_spread_rate': 0, 'entry_slippage_rate': 0,
            'stop_slippage_rate': 0.0005, 'target_slippage_rate': 0, 'funding_provision_rate': 0.0003}
ADVERSE = {**BASELINE, 'stop_spread_rate': 0.001, 'stop_slippage_rate': 0.001, 'funding_provision_rate': 0.001}
CENT = {'symbol': 'BTCUSDT', 'tick_size': 0.01, 'quantity_step': 0.001, 'min_quantity': 0.001,
        'max_quantity': 1000.0, 'min_notional': 5.0, 'contract_size': 1.0, 'leverage_cap': 2.0,
        'mmr_proxy_rate': 0.01, 'liquidation_fee_rate': 0.005}


def lcg_ohlc(count, seed=11, start=100.0, scale=1.0):
    """Deterministic OHLC path, also fed to the PHP formulas for the reference vectors below."""
    state, price = seed, start
    rows = {'open': [], 'high': [], 'low': [], 'close': []}

    def draw():
        nonlocal state
        state = (state * 1103515245 + 12345) % 2 ** 31
        return state / 2 ** 31

    for _ in range(count):
        o = price
        c = round(price * (1 + (draw() - 0.5) * 0.04 * scale), 2)
        h = round(max(o, c) * (1 + draw() * 0.01 * scale), 2)
        lo = round(min(o, c) * (1 - draw() * 0.01 * scale), 2)
        for key, value in zip(('open', 'high', 'low', 'close'), (o, h, lo, c)):
            rows[key].append(value)
        price = c
    return rows


def bars_from(rows, step=sw.FOUR_HOURS, start=START):
    return sw.Bars(step, start, *(array('d', rows[k]) for k in ('open', 'high', 'low', 'close')))


def series(symbol, start, closes, *, spread=0.0):
    """1m candles from closes: open = previous close; high/low widen by ``spread``."""
    o, h, l, c = (array('d') for _ in range(4))
    previous = closes[0]
    for close in closes:
        o.append(previous)
        h.append(max(previous, close) + spread)
        l.append(min(previous, close) - spread)
        c.append(close)
        previous = close
    return ex.CandleSeries(symbol, start, o, h, l, c)


# Ema/Rsi/AtrCalculator/Adx/Sma ::*Php on lcg_ohlc(250), computed by PHP 8.4 on 2026-10-11.
PHP_REFERENCE = {
    'ema9': 77.35621726238708, 'ema9p': 77.82527157798384, 'ema20': 78.08547926478292,
    'ema20p': 78.35974024002321, 'ema21': 78.14700078972996, 'ema21p': 78.41370086870296,
    'ema50': 79.66805839444592, 'ema50p': 79.8389995534029, 'ema200': 84.67106668434768,
    'ema200p': 84.76343921383861, 'rsi': 34.71697806359214, 'atr': 1.6339184844575383,
    'adx': 29.78763046441427, 'ma21': 77.97142857142859}


# ------------------------------------------------------------------------ indicators
def test_indicators_equal_php_reference_vectors():
    values = sw.snapshot(bars_from(lcg_ohlc(250)), 249)
    assert {name: values[name] for name in PHP_REFERENCE} == PHP_REFERENCE
    assert values['ma_21_plus_k_atr'] == PHP_REFERENCE['ma21'] + 1.3 * PHP_REFERENCE['atr']
    assert values['close'] == lcg_ohlc(250)['close'][-1]


def test_php_sum_keeps_left_to_right_order():
    closes = lcg_ohlc(250)['close'][-21:]
    assert sw._php_sum(closes) / 21 == PHP_REFERENCE['ma21'] != math.fsum(closes) / 21  # a compensated sum differs
    assert sw._php_sum([1e16, 1.0, -1e16]) == 0.0


def test_snapshot_needs_a_full_window_and_indicators_reject_short_inputs():
    bars = bars_from(lcg_ohlc(260))
    assert sw.snapshot(bars, 248) is None and sw.snapshot(bars, 260) is None and sw.snapshot(bars, 259)
    assert sw.rsi_php([1.0, 1.0, 2.0], 2) == 100.0  # no loss: infinite strength
    for call in (lambda: sw.rsi_php([1.0] * 14), lambda: sw.atr_php([1.0] * 14, [1.0] * 14, [1.0] * 14),
                 lambda: sw.adx_php([1.0] * 14, [1.0] * 14, [1.0] * 14)):
        with pytest.raises(ex.ExplorationError):
            call()
    assert sw.adx_php([1.0] * 20, [1.0] * 20, [1.0] * 20) == 0.0  # flat market: zero ranges


def test_aggregate_builds_complete_utc_buckets():
    candles = series('BTCUSDT', START, [100.0 + i for i in range(250)], spread=0.5)
    bars = sw.aggregate(candles, sw.HOUR)
    assert bars.count == 4 and bars.close_ms(0) == START + sw.HOUR  # the incomplete fifth hour is dropped
    assert (bars.open[1], bars.high[1], bars.low[1], bars.close[1]) == (159.0, 160.0 + 59 + 0.5, 159.0 - 0.5, 219.0)
    with pytest.raises(ex.ExplorationError):
        sw.aggregate(series('BTCUSDT', START + ex.MINUTE, [1.0] * 120), sw.HOUR)


def test_frame_columns_and_decision_range():
    rows = lcg_ohlc(300)
    frame = sw.build_frame('BTCUSDT', bars_from(rows))
    assert frame['close'][248] is None and frame['close'][249] == rows['close'][249]
    assert frame['dh30'][29] is None and frame['dh30'][30] == max(rows['high'][:30])
    assert frame['low6'][4] is None and frame['low6'][10] == min(rows['low'][5:11])
    assert frame['ema200'][299] == sw.snapshot(bars_from(rows), 299)['ema200']
    assert frame.decisions(START, START + 400 * sw.FOUR_HOURS) == range(249, 300)
    assert frame.decisions(frame.close_ms(260), frame.close_ms(270)) == range(260, 270)
    assert frame.decisions(frame.close_ms(260) + 1, frame.close_ms(270) + 1) == range(261, 271)
    assert len(frame.decisions(START + 999 * sw.FOUR_HOURS, START + 1999 * sw.FOUR_HOURS)) == 0
    assert sw.Frame.from_json(json.loads(json.dumps(frame.to_json()))).columns == frame.columns


# --------------------------------------------------------------------------- policies
def frame_of(**columns):
    """A three-bar frame whose last bar (index 2) carries ``columns``; earlier bars default."""
    base = {name: [1.0, 1.0, 1.0] for name in sw.COLUMNS}
    for name, values in columns.items():
        base[name] = list(values) if isinstance(values, (list, tuple)) else [1.0, 1.0, values]
    return sw.Frame('BTCUSDT', sw.FOUR_HOURS, START, base)


def test_policies_follow_their_definitions():
    p = sw.POLICIES
    trend = {'close': 110.0, 'ema20': 105.0, 'ema50': 100.0, 'ema200': 90.0}
    assert p['B60_up200'].predicate(frame_of(**trend, dh60=109.0), 2)
    assert not p['B60_up200'].predicate(frame_of(**trend, dh60=110.0), 2)
    assert p['B60_any'].predicate(frame_of(**{**trend, 'ema200': 120.0}, dh60=109.0), 2)
    assert not p['B30_up200'].predicate(frame_of(**{**trend, 'ema200': 120.0}, dh30=100.0), 2)
    assert p['B120_up200'].predicate(frame_of(**trend, dh120=100.0), 2)
    reclaim = frame_of(**{**trend, 'close': [1.0, 99.0, 110.0], 'ema20': [1.0, 100.0, 105.0]})
    assert p['PB20_stack'].predicate(reclaim, 2)
    assert not p['PB20_stack'].predicate(frame_of(**{**trend, 'close': [1.0, 101.0, 110.0],
                                                      'ema20': [1.0, 100.0, 105.0]}), 2)
    assert not p['PB20_stack'].predicate(frame_of(**{**trend, 'ema50': 95.0, 'ema200': 96.0,
                                                      'close': [1.0, 99.0, 110.0], 'ema20': [1.0, 100.0, 105.0]}), 2)
    assert p['PB50_stack'].predicate(frame_of(**trend, low6=99.5), 2)
    assert not p['PB50_stack'].predicate(frame_of(**trend, low6=100.5), 2)
    assert p['OS30_up200'].predicate(frame_of(**trend, rsi=29.9), 2)
    assert not p['OS30_up200'].predicate(frame_of(**trend, rsi=30.0), 2) and p['OS40_up200'].predicate(
        frame_of(**trend, rsi=30.0), 2)
    assert p['X921_up200'].predicate(frame_of(**trend, ema9p=99.0, ema21p=99.0, ema9=101.0, ema21=100.0), 2)
    assert not p['X921_up200'].predicate(frame_of(**trend, ema9p=99.1, ema21p=99.0, ema9=101.0, ema21=100.0), 2)
    assert p['TR_stack_adx25'].predicate(frame_of(**trend, adx=25.0), 2)
    assert not p['TR_stack_adx25'].predicate(frame_of(**trend, adx=24.9), 2)
    assert sw.SETUP_POLICIES == tuple(k for k in p if not k.startswith('R_')) and len(sw.SETUP_POLICIES) == 10
    assert all(policy.description for policy in p.values())


def test_random_control_is_deterministic_and_regime_restricted():
    frame = frame_of(close=110.0, ema200=90.0)
    hits = [sw.sampled('BTCUSDT', START + k * sw.FOUR_HOURS) for k in range(400)]
    assert 70 < sum(hits) < 130 and hits == [sw.sampled('BTCUSDT', START + k * sw.FOUR_HOURS) for k in range(400)]
    assert sw.POLICIES['R_all'].predicate(frame, 2) == sw.sampled('BTCUSDT', frame.close_ms(2))
    below = frame_of(close=80.0, ema200=90.0)
    assert not any(sw.POLICIES['R_up200'].predicate(sw.Frame('BTCUSDT', sw.FOUR_HOURS, START + k * sw.FOUR_HOURS,
                                                             below.columns), 2) for k in range(50))


# ------------------------------------------------------------------------- geometries
def test_geometry_catalogue_and_funding_intervals():
    assert len(sw.GEOMETRIES) == 27 and sw.GEOMETRIES['S3.T5.H14d'] == sw.SwingGeometry(3.0, 5.0, 14)
    assert [sw.SwingGeometry(horizon_days=d).funding_intervals for d in (1, 3, 7, 14)] == [3, 9, 21, 42]
    assert sw.SwingGeometry().entry_ttl_ms == 3_600_000 and sw.SwingGeometry().horizon_ms == 7 * ex.DAY
    for bad in ({'stop_atr_multiplier': 0}, {'horizon_days': 0}, {'entry_ttl_minutes': 241}):
        with pytest.raises(ex.ExplorationError):
            sw.SwingGeometry(**bad)


# ------------------------------------------------------------------------------ plans
def test_swing_plan_uses_exact_arithmetic_and_provisions_the_horizon():
    frame = frame_of(close=100.004, atr=1.0)
    geometry = sw.SwingGeometry(3.0, 3.0, 7)
    plan, reason = sw.build_swing_plan(frame, 2, geometry, CENT, BASELINE, START + 30 * ex.DAY)
    assert reason is None
    assert (plan.entry, plan.stop, plan.target, plan.quantity) == (100.01, 97.01, 109.01, 2.499)
    expected, _ = ex.protect_and_size(ex._decimal(100.01), ex._decimal(1.0), 3.0, 3.0, CENT, BASELINE, 21)
    assert (plan.net_risk, plan.net_r) == (float(expected[3]), float(expected[4]))
    one, _ = ex.protect_and_size(ex._decimal(100.01), ex._decimal(1.0), 3.0, 3.0, CENT, BASELINE, 1)
    assert plan.net_risk - float(one[3]) == pytest.approx(249.924990 * 0.0003 * 20)
    assert plan.decision_ms == frame.close_ms(2) and plan.deadline_ms == frame.close_ms(2) + 7 * ex.DAY
    truncated, _ = sw.build_swing_plan(frame, 2, geometry, CENT, BASELINE, frame.close_ms(2) + ex.DAY)
    assert truncated.deadline_ms == frame.close_ms(2) + ex.DAY


def test_swing_plan_rejections():
    long_horizon = sw.SwingGeometry(1.0, 2.0, 14)
    assert sw.build_swing_plan(frame_of(close=100.0, atr=1.0), 2, long_horizon, CENT, ADVERSE,
                               START + 60 * ex.DAY) == (None, 'research_minimum_net_r_not_met')
    assert sw.build_swing_plan(frame_of(close=100.0, atr=None), 2, long_horizon, CENT, BASELINE,
                               START + 60 * ex.DAY) == (None, 'research_signal_price_context_missing')
    assert sw.build_swing_plan(frame_of(close=100.0, atr=40.0), 2, sw.SwingGeometry(3.0, 2.0, 3), CENT, BASELINE,
                               START + 60 * ex.DAY) == (None, 'canonical_protection_stop_invalid')


# -------------------------------------------------------------------------- simulation
def test_first_touch_matches_a_minute_scan():
    rng = random.Random(5)
    closes, price = [], 100.0
    for _ in range(3 * 1440 + 17):
        price *= 1 + rng.uniform(-0.002, 0.002)
        closes.append(round(price, 2))
    candles = series('BTCUSDT', START, closes, spread=0.01)
    blocks = sw.extremes(candles)
    assert len(blocks.day_low) == 3 and len(blocks.hour_low) == 72
    for _ in range(300):
        begin = rng.randrange(0, candles.count - 1)
        end = rng.randrange(begin, candles.count + 1)
        stop, target = price * rng.uniform(0.85, 1.0), price * rng.uniform(1.0, 1.15)
        scan = next((i for i in range(begin, end) if candles.low[i] <= stop or candles.high[i] >= target), None)
        assert sw.first_touch(candles, blocks, begin, end, stop, target) == scan


def day_plan(decision, **values):
    base = {'symbol': 'BTCUSDT', 'decision_ms': decision, 'entry': 100.0, 'stop': 98.0, 'target': 103.0,
            'quantity': 2.0, 'net_risk': 4.5, 'net_r': 1.4, 'deadline_ms': decision + ex.HOLDING_MS}
    return ex.Plan(**{**base, **values})


def test_ninety_second_entries_reproduce_the_exploration_kernel():
    rng = random.Random(9)
    closes, price = [], 100.0
    for _ in range(4 * 1440):
        price *= 1 + rng.uniform(-0.0015, 0.0015)
        closes.append(round(price, 2))
    candles = series('BTCUSDT', START - ex.MINUTE, closes, spread=0.02)
    blocks = sw.extremes(candles)
    events = [(START + h * 28_800_000 + (90_000 if h % 3 else 0), (-1) ** h * 0.0004, None) for h in range(10)]
    funding = ex.FundingSeries.from_events(events)
    compared = 0
    for decision in range(START, START + 3 * ex.DAY, 900_000):
        close = candles.close[candles.index(decision) - 1]
        for stop_gap, target_gap in ((0.004, 0.008), (0.01, 0.02), (0.002, 0.03)):
            plan = day_plan(decision, entry=close, stop=round(close * (1 - stop_gap), 2),
                            target=round(close * (1 + target_gap), 2),
                            deadline_ms=min(decision + ex.HOLDING_MS, (decision // ex.DAY + 1) * ex.DAY))
            mine = sw.simulate_swing(plan, candles, blocks, funding, BASELINE, ex.ENTRY_TTL_MS)
            theirs = ex.simulate(plan, candles, funding, BASELINE)
            reason = 'holding_deadline' if theirs.reason == 'midnight' else theirs.reason
            assert (mine.filled, mine.fill_ms, mine.exit_ms, mine.exit_price, mine.reason, mine.ambiguous,
                    mine.funding, mine.funding_cash, mine.net, mine.release_ms) == (
                theirs.filled, theirs.fill_ms, theirs.exit_ms, theirs.exit_price, reason, theirs.ambiguous,
                theirs.funding, theirs.funding_cash, theirs.net, theirs.release_ms)
            compared += mine.filled
    assert compared > 150


def flat(minutes, price=100.5):
    return [price] * minutes


def test_resting_entry_fills_within_its_ttl_or_expires():
    candles = series('BTCUSDT', START, flat(30) + [99.9] + flat(3000, 100.2))
    blocks = sw.extremes(candles)
    plan = day_plan(START, deadline_ms=START + 2 * ex.DAY)
    filled = sw.simulate_swing(plan, candles, blocks, None, BASELINE, 60 * ex.MINUTE)
    assert filled.filled and filled.fill_ms == START + 31 * ex.MINUTE and not filled.ambiguous
    assert filled.reason == 'holding_deadline' and filled.exit_ms == START + 2 * ex.DAY
    assert filled.exit_price == 100.2 and filled.net == pytest.approx(2 * 0.2 - 2 * 100 * 0.0002
                                                                      - 2 * 100.2 * (0.0005 + 0.0007))
    expired = sw.simulate_swing(plan, candles, blocks, None, BASELINE, 30 * ex.MINUTE)
    assert not expired.filled and expired.release_ms == START + 31 * ex.MINUTE
    late = day_plan(START, deadline_ms=START + 20 * ex.MINUTE)
    assert not sw.simulate_swing(late, candles, blocks, None, BASELINE, 60 * ex.MINUTE).filled


def padded(path, minutes=3 * 1440 + 1):
    return path + [path[-1]] * (minutes - len(path))


def test_multi_day_stop_target_and_fill_bar_rules():
    plan = day_plan(START, deadline_ms=START + 3 * ex.DAY)
    path = padded([99.9] + flat(2 * 1440, 100.5) + [97.0])
    stopped = sw.simulate_swing(plan, series('BTCUSDT', START, path), sw.extremes(series('BTCUSDT', START, path)),
                                None, BASELINE, 60 * ex.MINUTE)
    assert stopped.reason == 'stop' and stopped.exit_ms == START + (2 * 1440 + 2) * ex.MINUTE
    assert stopped.exit_price == 98.0 and not stopped.ambiguous and stopped.release_ms == stopped.exit_ms
    gap = series('BTCUSDT', START, path)
    gap.open[2 * 1440 + 1] = 97.5
    assert sw.simulate_swing(plan, gap, sw.extremes(gap), None, BASELINE, 60 * ex.MINUTE).exit_price == 97.5
    rally = series('BTCUSDT', START, padded([99.9] + flat(1500, 100.5) + [103.5]))
    won = sw.simulate_swing(plan, rally, sw.extremes(rally), None, BASELINE, 60 * ex.MINUTE)
    assert won.reason == 'target' and won.exit_price == 103.0 and won.exit_ms == START + 1502 * ex.MINUTE
    both = series('BTCUSDT', START, padded([99.9] + flat(200, 100.5)))
    both.high[101], both.low[101] = 104.0, 97.0
    worst = sw.simulate_swing(plan, both, sw.extremes(both), None, BASELINE, 60 * ex.MINUTE)
    assert worst.reason == 'stop' and worst.ambiguous and worst.exit_ms == START + 102 * ex.MINUTE
    fill_bar = series('BTCUSDT', START, padded([99.9] + flat(200, 100.5)))
    fill_bar.high[0] = 104.0
    ignored = sw.simulate_swing(plan, fill_bar, sw.extremes(fill_bar), None, BASELINE, 60 * ex.MINUTE)
    assert ignored.ambiguous and ignored.reason == 'holding_deadline' and ignored.exit_price == 100.5
    crash = series('BTCUSDT', START, padded([97.5] + flat(200, 100.5)))
    instant = sw.simulate_swing(plan, crash, sw.extremes(crash), None, BASELINE, 60 * ex.MINUTE)
    assert instant.reason == 'stop' and instant.ambiguous and instant.exit_ms == START + ex.MINUTE
    assert instant.exit_price == 97.5


def test_simulation_rejects_missing_coverage():
    candles = series('BTCUSDT', START, [99.9] + flat(100))
    blocks = sw.extremes(candles)
    with pytest.raises(ex.ExplorationError):
        sw.simulate_swing(day_plan(START + 90 * ex.MINUTE), candles, blocks, None, BASELINE, 60 * ex.MINUTE)
    with pytest.raises(ex.ExplorationError):
        sw.simulate_swing(day_plan(START, deadline_ms=START + ex.DAY), candles, blocks, None, BASELINE,
                          60 * ex.MINUTE)


def test_funding_accrues_over_a_multi_day_hold():
    candles = series('BTCUSDT', START, [99.9] + flat(3 * 1440, 100.5))
    events = [(START + h * 28_800_000, 0.0001, None) for h in range(1, 9)]
    outcome = sw.simulate_swing(day_plan(START, deadline_ms=START + 3 * ex.DAY), candles, sw.extremes(candles),
                                ex.FundingSeries.from_events(events), BASELINE, 60 * ex.MINUTE)
    assert len(outcome.funding_cash) == 8 and outcome.funding == pytest.approx(-8 * 2 * 100.5 * 0.0001)


# --------------------------------------------------------------------------- portfolio
NEUTRAL = {'close': 100.0, 'atr': 1.0, 'rsi': 50.0, 'adx': 20.0, 'ma21': 100.0, 'ma_21_plus_k_atr': 101.3,
           'high': 100.5, 'low': 99.5, 'dh30': 101.0, 'dh60': 101.0, 'dh120': 101.0, 'low6': 99.5,
           **{f'ema{p}': 100.0 for p in sw.EMA_PERIODS}, **{f'ema{p}p': 100.0 for p in sw.EMA_PERIODS}}


def portfolio_frames(symbols, count=260, **values):
    """Frames whose bar 249 closes at START; every bar carries ``NEUTRAL`` updated by ``values``."""
    columns = {**NEUTRAL, **values}
    return {symbol: sw.Frame(symbol, sw.FOUR_HOURS, START - 250 * sw.FOUR_HOURS,
                             {name: [columns[name]] * count for name in sw.COLUMNS}) for symbol in symbols}


def test_run_swing_admission_rules_and_summary():
    symbols = ('BTCUSDT', 'ETHUSDT', 'BNBUSDT', 'XRPUSDT', 'ADAUSDT', 'DOGEUSDT')
    frames = portfolio_frames(symbols)
    window = (START, START + 8 * sw.FOUR_HOURS)  # eight decisions; the phase ends 32 h after START
    candles = {s: series(s, START, flat(3 * 1440, 99.9)) for s in symbols}
    blocks = {s: sw.extremes(c) for s, c in candles.items()}
    instruments = {s: {**CENT, 'symbol': s} for s in symbols}
    policy = sw.SwingPolicy('every', lambda f, i: True, 'test')
    geometry = sw.SwingGeometry(3.0, 3.0, 1)
    summary, outcomes = sw.run_swing(frames, candles, blocks, None, policy, geometry, instruments, BASELINE, window)
    # Four plans at START hold until START + 24 h; four more then run to the phase end.
    assert summary['signals'] == 6 * 8 and summary['trades'] == 8
    assert summary['rejections'] == {'research_portfolio_concurrency_exceeded': 2 * 8,
                                     'research_symbol_already_active': 4 * 6}
    assert {o.plan.symbol for o in outcomes} == set(symbols[:4]) and summary['phase_end_exits'] == 4
    assert sorted(o.plan.deadline_ms for o in outcomes) == [START + ex.DAY] * 4 + [window[1]] * 4
    assert summary['mean_holding_hours'] == pytest.approx((24 * 60 - 1 + 8 * 60 - 1) / 2 / 60)
    wide = sw.run_swing(frames, candles, blocks, None, policy, geometry, instruments, BASELINE, window,
                        max_active=10)[0]
    assert wide['trades'] == 12 and wide['rejections'] == {'research_symbol_already_active': 6 * 6}
    rejected = sw.run_swing(portfolio_frames(symbols, atr=40.0), candles, blocks, None, policy, geometry,
                            instruments, BASELINE, window)[0]
    assert rejected['trades'] == 0 and rejected['mean_holding_hours'] is None
    assert rejected['rejections'] == {'canonical_protection_stop_invalid': 48}
    for bad in ((START, START), (START, ex.HOLDOUT_START_MS + sw.FOUR_HOURS)):
        with pytest.raises(ex.ExplorationError):
            sw.run_swing(frames, candles, blocks, None, policy, geometry, instruments, BASELINE, bad)


def test_unfilled_entries_release_capacity_and_holding_window_is_checked(monkeypatch):
    symbols = ('BTCUSDT',)
    frames = portfolio_frames(symbols)
    candles = {'BTCUSDT': series('BTCUSDT', START, flat(3 * 1440, 100.5))}
    blocks = {'BTCUSDT': sw.extremes(candles['BTCUSDT'])}
    policy = sw.SwingPolicy('every', lambda f, i: True, 'test')
    summary, _ = sw.run_swing(frames, candles, blocks, None, policy, sw.SwingGeometry(3.0, 3.0, 1),
                              {'BTCUSDT': CENT}, BASELINE, (START, START + 4 * sw.FOUR_HOURS))
    assert summary['signals'] == 4 and summary['trades'] == 0 and summary['rejections'] == {}
    monkeypatch.setattr(ex, 'holding_window_available', lambda plan: False)
    summary, _ = sw.run_swing(frames, candles, blocks, None, policy, sw.SwingGeometry(3.0, 3.0, 1),
                              {'BTCUSDT': CENT}, BASELINE, (START, START + 4 * sw.FOUR_HOURS))
    assert summary['rejections'] == {'research_holding_window_unavailable': 4}


# ------------------------------------------------------------------------------ parity
def test_compact_parity_compares_every_php_field():
    rows = lcg_ohlc(262)
    bars = bars_from(rows)
    frame = sw.build_frame('BTCUSDT', bars)
    values = sw.snapshot(bars, 255)
    context = {theirs: values[mine] for mine, theirs in sw._PHP_FIELDS.items()}
    retained = [{'ms': frame.close_ms(255), '4h': context}, {'ms': frame.close_ms(255) + 900_000, '4h': context}]
    assert sw.compact_parity(frame, sw.FOUR_HOURS, retained, '4h') == (2, {})
    assert sw.compact_parity(bars, sw.FOUR_HOURS, retained, '4h') == (2, {})
    broken = [{'ms': frame.close_ms(255), '4h': {**context, 'adx14': context['adx14'] + 1e-12}},
              {'ms': frame.close_ms(100), '4h': context}]
    compared, mismatches = sw.compact_parity(frame, sw.FOUR_HOURS, broken, '4h')
    assert compared == 1 and mismatches == {'adx': 1, 'missing_window': 1}
    assert sw.compact_parity(bars, sw.FOUR_HOURS, broken[1:], '4h') == (0, {'missing_window': 1})


def raw_line(frame, index, *, symbol='BTCUSDT', shift=0.0, decision=None):
    values = {name: frame[name][index] for name in sw.INDICATORS}
    decision = frame.close_ms(index) if decision is None else decision
    context = {'open_ms': frame.close_ms(index) - sw.FOUR_HOURS, 'available_ms': decision,
               'close': values['close'], 'rsi': values['rsi'] + shift, 'atr': values['atr'],
               'adx': {'14': values['adx'], '15': 0.0}, 'ma21': values['ma21'],
               'ma_21_plus_k_atr': values['ma_21_plus_k_atr'],
               'ema': {str(p): values[f'ema{p}'] for p in sw.EMA_PERIODS},
               'ema_prev': {str(p): values[f'ema{p}p'] for p in sw.EMA_PERIODS}}
    return json.dumps({'schema_version': 'research-signal-result.v1', 'symbol': symbol, 'evaluated_ms': decision,
                       'contexts': {'4h': context}}).encode()


def test_raw_parity_checks_every_4h_decision():
    frame = sw.build_frame('BTCUSDT', bars_from(lcg_ohlc(262)))
    lines = [raw_line(frame, 255), raw_line(frame, 256, shift=1e-9),
             raw_line(frame, 256, decision=frame.close_ms(256) + 900_000).replace(b'"available_ms"', b'"x"')]
    assert sw.raw_parity(frame, lines) == (2, {'rsi': 1})
    for bad in (b'{"symbol": "BTCUSDT"}', raw_line(frame, 255, symbol='ETHUSDT')):
        with pytest.raises(ex.ExplorationError):
            sw.raw_parity(frame, [bad])
    shifted = json.loads(raw_line(frame, 255))
    shifted['contexts']['4h']['available_ms'] += sw.FOUR_HOURS
    with pytest.raises(ex.ExplorationError):
        sw.raw_parity(frame, [json.dumps(shifted).encode()])
    late = sw.Frame('BTCUSDT', sw.FOUR_HOURS, ex.HOLDOUT_START_MS - 250 * sw.FOUR_HOURS, frame.columns)
    with pytest.raises(ex.ExplorationError):
        sw.raw_parity(late, [raw_line(late, 255)])


# --------------------------------------------------------------------------------- CLI
def prepared_root(tmp_path):
    root = tmp_path / 'root'
    (root / 'candles').mkdir(parents=True)
    (root / 'features').mkdir()
    day = 1_672_531_200_000  # 2023-01-01T00:00:00Z
    for symbol in sw.SYMBOLS:
        series(symbol, day, flat(5 * 1440 + 1, 99.9)).write(root / 'candles' / f'{symbol}.candles.bin', {})
        frame = portfolio_frames((symbol,))[symbol]
        frame.start_ms = day - 249 * sw.FOUR_HOURS
        (root / 'features' / f'{symbol}.frame.json').write_text(json.dumps(frame.to_json()))
    (root / 'funding.json').write_text(json.dumps({s: [[day + 28_800_000, 0.0001, None]] for s in sw.SYMBOLS}))
    (root / 'instrument-assumptions.json').write_text(json.dumps({'symbols': [{**CENT, 'symbol': s}
                                                                              for s in sw.SYMBOLS]}))
    (root / 'cost-assumptions.json').write_text(json.dumps({'profiles': {'baseline': BASELINE, 'adverse': ADVERSE}}))
    return root


def test_cli_grid_runs_in_process_and_in_workers(tmp_path, capsys, monkeypatch):
    root = prepared_root(tmp_path)
    output = tmp_path / 'grid.json'
    assert sw.main(['grid', '--root', str(root), '--phase', 'training', '--policies', 'R_all,B60_any',
                    '--geometries', 'S3.T3.H3d', '--workers', '1', '--output', str(output)]) == 0
    grid = json.loads(output.read_text())
    assert [(g['policy'], g['profile']) for g in grid] == [('R_all', 'baseline'), ('R_all', 'adverse'),
                                                           ('B60_any', 'baseline'), ('B60_any', 'adverse')]
    assert all(g['execution_authority'] == 'none' and g['max_active'] == 4 for g in grid)
    assert grid[0]['trades'] > 0 and grid[2]['signals'] == 0
    monkeypatch.setattr(sw, 'ProcessPoolExecutor', _InlinePool)
    assert sw.main(['grid', '--root', str(root), '--phase', 'training', '--policies', 'R_all',
                    '--geometries', 'S3.T3.H3d', '--profiles', 'baseline', '--output', str(output)]) == 0
    assert len(json.loads(output.read_text())) == 1
    assert sw.main(['grid', '--root', str(root), '--phase', 'training', '--geometries', 'S3.T3.H3d',
                    '--profiles', 'baseline', '--output', str(output)]) == 0
    assert [g['policy'] for g in json.loads(output.read_text())] == list(sw.SETUP_POLICIES)
    bad = series('BTCUSDT', 1_672_531_200_000 + sw.HOUR, flat(10))
    bad.write(root / 'candles' / 'BTCUSDT.candles.bin', {})
    with pytest.raises(ex.ExplorationError):
        sw.main(['grid', '--root', str(root), '--phase', 'training', '--workers', '1', '--output', str(output)])
    capsys.readouterr()


class _InlinePool:
    def __init__(self, max_workers):
        self.max_workers = max_workers

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False

    def map(self, function, items):
        return map(function, items)


def test_cli_features_and_parity(tmp_path, capsys, monkeypatch):
    monkeypatch.setattr(sw, 'ProcessPoolExecutor', _InlinePool)
    root = tmp_path / 'root'
    (root / 'candles').mkdir(parents=True)
    rows = lcg_ohlc(252 * 240, seed=3, scale=0.05)
    day = 1_672_531_200_000 - 250 * sw.FOUR_HOURS
    for symbol in sw.SYMBOLS:
        ex.CandleSeries(symbol, day, *(array('d', rows[k]) for k in ('open', 'high', 'low', 'close'))).write(
            root / 'candles' / f'{symbol}.candles.bin', {})
    assert sw.main(['features', '--root', str(root)]) == 0
    frames = sw.load_frames(root)
    assert frames['BTCUSDT'].count == 252 and frames['ETHUSDT']['ema200'][251] is not None
    frame = frames['BTCUSDT']
    hourly = sw.aggregate(ex.CandleSeries.read(root / 'candles' / 'BTCUSDT.candles.bin'), sw.HOUR)
    decision = frame.close_ms(251)
    four = {theirs: frame[mine][251] for mine, theirs in sw._PHP_FIELDS.items()}
    one = {theirs: sw.snapshot(hourly, (decision - hourly.start_ms) // sw.HOUR - 1)[mine]
           for mine, theirs in sw._PHP_FIELDS.items()}
    kept = tmp_path / 'control' / 'training'
    kept.mkdir(parents=True)
    for symbol in sw.SYMBOLS:
        (kept / f'{symbol}.kept.json').write_text(json.dumps([{'ms': decision, '4h': four, '1h': one}]))
    results = tmp_path / 'BTCUSDT.results.ndjson'
    results.write_bytes(raw_line(frame, 251) + b'\n')
    capsys.readouterr()
    assert sw.main(['parity', '--root', str(root), '--rows', str(kept), '--results', str(results)]) == 0
    report = json.loads(capsys.readouterr().out)
    assert report['compact']['training:BTCUSDT:1h'] == {'compared': 1, 'mismatches': {}}
    assert report['raw'][str(results)] == {'compared': 1, 'mismatches': {}}
    results.write_bytes(raw_line(frame, 251, shift=1.0) + b'\n')
    assert sw.main(['parity', '--root', str(root), '--results', str(results)]) == 1


def test_cli_prepare_warms_up_from_november_2022(tmp_path, monkeypatch, capsys):
    calls = []
    monkeypatch.setattr(ex, 'prepare', lambda *args, **kwargs: calls.append((args, kwargs)) or {'ok': True})
    arguments = ['prepare', '--root', 'r', '--dataset-root', 'd', '--funding-supplement-root', 's',
                 '--instrument-path', 'i', '--cost-path', 'c']
    assert sw.main(arguments) == 0
    assert calls[0][1] == {'candle_start': '2022-11-01T00:00:00Z'} and len(calls[0][0]) == 5
    assert json.loads(capsys.readouterr().out) == {'ok': True}
