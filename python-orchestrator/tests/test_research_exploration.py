"""Synthetic exploration tests: conditions, plan arithmetic, simulation, screening and canonical parity."""
from __future__ import annotations

import json
from array import array
from decimal import Decimal as D

import pytest

from app.backtesting.research import exploration as ex
from app.backtesting.research.plans import VARIANT_DIFFS
from app.backtesting.research.portfolio_simulator import canonical_hash

START = 1_709_510_400_000  # 2024-03-04T00:00:00Z, a training day
BASELINE = {'entry_spread_rate': 0, 'stop_spread_rate': 0.0002, 'target_spread_rate': 0, 'entry_slippage_rate': 0,
            'stop_slippage_rate': 0.0005, 'target_slippage_rate': 0, 'funding_provision_rate': 0.0003}
ADVERSE = {**BASELINE, 'stop_spread_rate': 0.001, 'stop_slippage_rate': 0.001, 'funding_provision_rate': 0.001}
BTC = {'symbol': 'BTCUSDT', 'tick_size': 0.1, 'quantity_step': 0.001, 'min_quantity': 0.001,
       'max_quantity': 1000.0, 'min_notional': 50.0, 'contract_size': 1.0, 'leverage_cap': 2.0,
       'mmr_proxy_rate': 0.01, 'liquidation_fee_rate': 0.005}
DOGE = {**BTC, 'symbol': 'DOGEUSDT', 'tick_size': 1e-05, 'quantity_step': 1.0, 'min_quantity': 1.0,
        'max_quantity': 300000000.0, 'min_notional': 5.0}
CENT = {**BTC, 'tick_size': 0.01, 'min_notional': 5.0}


def context(**values):
    base = {'close': 100.0, 'rsi': 55.0, 'vwap': 100.0, 'atr': 1.0, 'ma_21_plus_k_atr': 101.0,
            'pullback_age_bars': 2, 'ema_20': 100.0, 'ema_50': 99.0, 'e9': 100.2, 'e21': 100.0,
            'e9p': 99.9, 'e21p': 100.0, 'adx14': 25.0}
    return {**base, **values}


def row(ms=START + 900_000, *, sections=None, bf=None):
    result = {'ms': ms, 'sec': sections or {s: True for s in ex.SECTIONS}, 'bf': bf or [True] * 5}
    for timeframe in ex.TIMEFRAMES:
        result[timeframe] = context()
    return result


def series(symbol, start, closes, *, spread=0.0):
    """Candles from closes: open = previous close; high/low widen by ``spread``."""
    o, h, l, c = (array('d') for _ in range(4))
    previous = closes[0]
    for close in closes:
        o.append(previous)
        h.append(max(previous, close) + spread)
        l.append(min(previous, close) - spread)
        c.append(close)
        previous = close
    return ex.CandleSeries(symbol, start, o, h, l, c)


def php_row(anchor_tf, anchor_key, anchor, atr_tf, atr, stop_tf, stop_atr, candidate, ms=START + 900_000):
    signal = row(ms)
    signal[anchor_tf] = {**signal[anchor_tf], anchor_key: anchor}
    signal[atr_tf] = {**signal[atr_tf], 'atr': atr}
    signal[stop_tf] = {**signal[stop_tf], 'atr': stop_atr}
    signal['15m'] = {**signal['15m'], 'close': candidate}
    return signal


# ------------------------------------------------------------------------- conditions
def test_conditions_match_runtime_semantics():
    assert ex.rsi_below({'rsi': 72.9}) and not ex.rsi_below({'rsi': 73.0}) and not ex.rsi_below({'rsi': 50})
    assert ex.adx_at_least({'adx14': 20}) and ex.adx_at_least({'adx14': 20.0}) and not ex.adx_at_least({'adx14': True})
    assert not ex.adx_at_least({'adx14': None})
    assert ex.ema9_crossed_up_ema21(context()) and not ex.ema9_crossed_up_ema21(context(e9p=100.1))
    assert not ex.ema9_crossed_up_ema21(context(e9=101))  # integers are missing data, like is_float()
    assert ex.near_vwap(context(close=100.14)) and not ex.near_vwap(context(close=100.16))
    assert ex.near_vwap(context(close=100.39), 0.004) and not ex.near_vwap(context(vwap=0.0))
    assert ex.below_ma21_plus_k_atr(context(close=101.0)) and not ex.below_ma21_plus_k_atr(context(close=101.01))
    assert not ex.below_ma21_plus_k_atr(context(ma_21_plus_k_atr=None))
    assert ex.recent_pullback(context(pullback_age_bars=3)) and not ex.recent_pullback(context(pullback_age_bars=4))
    assert not ex.recent_pullback(context(pullback_age_bars=None)) and not ex.recent_pullback(context(pullback_age_bars=True))


def test_global_filters_require_every_timeframe():
    signal = row()
    assert ex.published_filter_verdicts(signal) == (True, True, True, True, True)
    signal['4h'] = context(rsi=75.0)
    signal['1m'] = context(close=100.2)
    assert ex.published_filter_verdicts(signal) == (False, True, True, False, True)


def test_policies_use_declared_sections_and_filters():
    ready = row(sections={'regime': True, 'context': True, 'trigger': True, 'confirmations': False},
                bf=[True, True, False, False, True])
    assert ex.POLICIES['N1_noconf_published_filters_minus_pullbacks'].sections == ('regime', 'context', 'trigger')
    assert ex.POLICIES['N1_noconf_published_filters_minus_pullbacks'].predicate(ready)
    assert not ex.POLICIES['P0_published'].predicate(ready)
    assert ex.POLICIES['R_random_control'].sections == () and 'R_random_control' not in ex.SETUP_POLICIES
    for policy in ex.POLICIES.values():
        assert isinstance(policy.predicate(row()), bool) and policy.description


def test_geometry_catalogue_matches_research_variants():
    assert ex.CANONICAL_GEOMETRIES == tuple(VARIANT_DIFFS)
    for identifier, diff in VARIANT_DIFFS.items():
        assert ex.GEOMETRIES[identifier] == ex.Geometry(**diff)
    assert len(ex.EXTENDED_GEOMETRIES) == 69
    assert len(set(ex.GEOMETRIES.values())) == len(ex.GEOMETRIES)
    assert ex.GEOMETRIES['Zema15a15w50.S1hx15.T30'] == ex.Geometry(
        anchor_source='ema_20', anchor_timeframe='15m', atr_timeframe='15m', zone_atr_multiplier=0.5,
        stop_timeframe='1h', stop_atr_multiplier=1.5, target_risk_multiple=3.0)
    for bad in ({'anchor_source': 'close'}, {'stop_timeframe': '2h'}, {'minimum_half_width_rate': 0.02},
                {'target_risk_multiple': 0}):
        with pytest.raises(ex.ExplorationError):
            ex.Geometry(**bad)


def test_tick_quantization_is_exact_decimal():
    assert ex.floor_to(20973.38, 0.1) == 20973.3 and ex.ceil_to(20973.31, 0.1) == 20973.4
    assert ex.ceil_to(20973.3, 0.1) == 20973.3 and ex.floor_to(0.0815149, 1e-05) == 0.08151
    assert ex.floor_to(100.0 - 1e-12, 0.1) == 99.9  # no float epsilon: exact like BigDecimal


# --------------------------------------------------------------- PHP reference vectors
# Produced by EntryZonePriceMath, ProtectionPriceMath and NetRCostMath on 2026-10-11.
def test_plan_matches_php_reference_vectors():
    geometry = ex.Geometry(stop_timeframe='15m')
    plan, reason = ex.build_plan(php_row('5m', 'vwap', 26500.12, '5m', 40.5, '15m', 120.4, 26505.3),
                                 'BTCUSDT', geometry, BTC, BASELINE)
    assert reason is None
    assert (plan.entry, plan.stop, plan.target, plan.quantity) == (26505.3, 26324.7, 26866.5, 0.009)
    assert plan.net_risk == pytest.approx(2.02898061, abs=1e-12)
    assert plan.net_r == float(D('1.483812553536428324'))
    _, reason = ex.build_plan(php_row('5m', 'vwap', 26500.12, '5m', 40.5, '15m', 120.4, 26530.0),
                              'BTCUSDT', geometry, BTC, BASELINE)
    assert reason == 'canonical_entry_zone_candidate_outside'
    _, reason = ex.build_plan(php_row('5m', 'vwap', 26500.12, '5m', 40.5, '5m', 40.5, 26505.3),
                              'BTCUSDT', ex.Geometry(), BTC, BASELINE)
    assert reason == 'research_minimum_net_r_not_met'
    doge = ex.Geometry(anchor_source='ema_20', anchor_timeframe='15m', atr_timeframe='15m',
                       zone_atr_multiplier=0.5, stop_timeframe='1h', target_risk_multiple=3.0)
    plan, reason = ex.build_plan(php_row('15m', 'ema_20', 0.081234, '15m', 0.00091, '1h', 0.00262, 0.08151),
                                 'DOGEUSDT', doge, DOGE, ADVERSE)
    assert reason is None
    assert (plan.entry, plan.stop, plan.target, plan.quantity) == (0.08151, 0.07758, 0.0933, 3067.0)
    assert plan.net_risk == pytest.approx(12.948144054, abs=1e-12)
    clamp = php_row('5m', 'vwap', 26500.12, '5m', 2000.0, '15m', 420.0, 26720.0)
    plan, reason = ex.build_plan(clamp, 'BTCUSDT', geometry, BTC, BASELINE)  # half width capped at 1 %
    assert reason is None and (plan.entry, plan.stop, plan.target) == (26720.0, 26090.0, 27980.0)
    assert plan.net_risk == pytest.approx(6.072012, abs=1e-12)
    clamp['15m'] = {**clamp['15m'], 'close': 26770.0}
    assert ex.build_plan(clamp, 'BTCUSDT', geometry, BTC, BASELINE)[1] == 'canonical_entry_zone_candidate_outside'


@pytest.mark.parametrize('change,instrument,reason', [
    ({'15m': {'close': None}}, BTC, 'research_signal_price_context_missing'),
    ({'5m': {'atr': -1.0}}, BTC, 'research_signal_price_context_missing'),
    ({}, {**BTC, 'min_quantity': 5.0}, 'canonical_risk_quantity_below_minimum'),
    ({}, {**BTC, 'min_notional': 300.0}, 'research_instrument_notional_below_minimum'),
    ({'15m': {'atr': 30000.0}}, BTC, 'canonical_protection_stop_invalid'),
])
def test_plan_rejections(change, instrument, reason):
    signal = php_row('5m', 'vwap', 26500.12, '5m', 40.5, '15m', 120.4, 26505.3)
    for timeframe, values in change.items():
        signal[timeframe] = {**signal[timeframe], **values}
    assert ex.build_plan(signal, 'BTCUSDT', ex.Geometry(stop_timeframe='15m'), instrument, BASELINE) == (None, reason)


def test_plan_rejects_bad_zone_and_target_polarity():
    tiny = php_row('5m', 'vwap', 0.0001, '5m', 0.00005, '15m', 0.00001, 0.0001)
    assert ex.build_plan(tiny, 'BTCUSDT', ex.Geometry(stop_timeframe='15m'), BTC, BASELINE)[1] == \
        'canonical_entry_zone_bounds_invalid'
    flat_stop = php_row('5m', 'vwap', 26500.12, '5m', 40.5, '15m', 0.01, 26505.3)
    assert ex.build_plan(flat_stop, 'BTCUSDT', ex.Geometry(stop_timeframe='15m', target_risk_multiple=0.1),
                         BTC, BASELINE)[1] == 'canonical_protection_target_polarity_invalid'


# --------------------------------------------------------------------------- simulation
def plan(**values):
    base = {'symbol': 'BTCUSDT', 'decision_ms': START + 900_000, 'entry': 100.0, 'stop': 98.0,
            'target': 103.0, 'quantity': 2.0, 'net_risk': 4.5, 'net_r': 1.4,
            'deadline_ms': START + 900_000 + ex.HOLDING_MS}
    return ex.Plan(**{**base, **values})


def flat(minutes, price=100.5):
    return [price] * minutes


def test_unfilled_order_expires_after_one_candle():
    candles = series('BTCUSDT', START, flat(40, 100.5) + [99.0] * 20)
    outcome = ex.simulate(plan(), candles, None, BASELINE)
    assert not outcome.filled and outcome.reason == 'entry_expired'
    assert outcome.release_ms == START + 900_000 + ex.PENDING_RELEASE_MS == START + 900_000 + 120_000


def test_stop_on_fill_bar_is_ambiguous_and_gap_exits_at_open():
    closes = flat(15) + [97.5] + flat(30, 97.0)
    outcome = ex.simulate(plan(), series('BTCUSDT', START, closes), None, BASELINE)
    assert outcome.filled and outcome.reason == 'stop' and outcome.ambiguous
    assert outcome.exit_price == 98.0 and outcome.exit_ms == START + 16 * ex.MINUTE
    gap = series('BTCUSDT', START, flat(15) + [100.0, 99.0, 97.0] + flat(30, 97.0))
    gap.open[17] = 97.5
    outcome = ex.simulate(plan(), gap, None, BASELINE)
    assert outcome.reason == 'stop' and outcome.exit_price == 97.5 and not outcome.ambiguous


def test_fill_bar_target_is_ignored_and_its_ambiguity_persists():
    closes = flat(15) + [103.5, 103.6] + flat(30, 103.6)
    candles = series('BTCUSDT', START, closes)
    candles.low[15] = 99.9
    outcome = ex.simulate(plan(), candles, None, BASELINE)
    assert outcome.reason == 'target' and outcome.exit_ms == START + 17 * ex.MINUTE and outcome.ambiguous
    entry_cost = 2.0 * 100.0 * 0.0002
    exit_cash = 2.0 * 3.0 - 2.0 * 103.0 * 0.0005
    assert outcome.net == pytest.approx(exit_cash - entry_cost) and outcome.net_r == pytest.approx(outcome.net / 4.5)
    stopped = series('BTCUSDT', START, flat(15) + [103.5, 102.0, 97.0] + flat(30, 97.0))
    stopped.low[15] = 99.9
    stopped.open[16] = stopped.high[16] = 102.5  # no target touch after the fill bar
    outcome = ex.simulate(plan(), stopped, None, BASELINE)
    assert outcome.reason == 'stop' and outcome.ambiguous  # a clean stop bar keeps the fill-bar ambiguity


def test_stop_wins_when_both_bounds_are_touched():
    candles = series('BTCUSDT', START, flat(15) + [100.0, 100.5] + flat(30))
    candles.high[16], candles.low[16] = 104.0, 97.0
    outcome = ex.simulate(plan(), candles, None, BASELINE)
    assert outcome.reason == 'stop' and outcome.ambiguous and outcome.exit_price == 98.0


def test_deadline_and_midnight_exit_at_strictly_prior_close():
    candles = series('BTCUSDT', START, flat(15) + [100.0] + [100.0 + i * 0.001 for i in range(600)])
    outcome = ex.simulate(plan(deadline_ms=START + 900_000 + 2 * 3_600_000), candles, None, BASELINE)
    assert outcome.reason == 'holding_deadline' and outcome.exit_ms == START + 900_000 + 7_200_000
    assert outcome.exit_price == candles.close[candles.index(outcome.exit_ms) - 2] == candles.prior_close(outcome.exit_ms)
    late = START + ex.DAY - 1_800_000
    night = series('BTCUSDT', late - 15 * ex.MINUTE, flat(15) + [100.0] * 60)
    outcome = ex.simulate(plan(decision_ms=late, deadline_ms=START + ex.DAY), night, None, BASELINE)
    assert outcome.reason == 'midnight' and outcome.exit_ms == START + ex.DAY
    with pytest.raises(ex.ExplorationError):
        night.prior_close(night.start_ms + ex.MINUTE)


def test_candle_series_validation_and_unfilled_release():
    with pytest.raises(ex.ExplorationError):
        ex.CandleSeries('BTCUSDT', START + 1, array('d', [1.0]), array('d', [1.0]), array('d', [1.0]), array('d', [1.0]))
    rows = {'BTCUSDT': [php_row('5m', 'vwap', 100.0, '5m', 0.1, '15m', 0.5, 100.0, ms=START + k * 900_000)
                        for k in (1, 2)]}
    candles = {'BTCUSDT': series('BTCUSDT', START, flat(120, 100.5))}  # never trades down to the entry
    summary, outcomes = ex.run_hypothesis(rows, candles, None, ex.POLICIES['S_sections_only'],
                                          ex.Geometry(stop_timeframe='15m'), {'BTCUSDT': CENT}, BASELINE,
                                          ex.PHASES['training'])
    assert summary['signals'] == 2 and summary['trades'] == 0 and outcomes == []


def test_simulation_rejects_missing_coverage():
    candles = series('BTCUSDT', START, flat(20))
    with pytest.raises(ex.ExplorationError):
        ex.simulate(plan(decision_ms=START + ex.DAY), candles, None, BASELINE)
    with pytest.raises(ex.ExplorationError):
        ex.simulate(plan(), series('BTCUSDT', START, flat(15) + [100.0] * 5), None, BASELINE)


def test_funding_follows_adverse_possible_credit_certain_policy():
    candles = series('BTCUSDT', START, flat(15) + [100.0] + [100.0 + i * 0.0001 for i in range(600)])
    fill_batch = START + 900_000 + 30_000
    funding = ex.FundingSeries.from_events([
        (fill_batch, -0.001, None),          # credit inside the fill batch: uncertain, skipped
        (START + 900_000 + 3_600_000 + 7, 0.0002, None),   # certain charge at the prior close
        (START + 900_000 + 7_200_000 + 9, -0.0001, 101.0),  # certain credit with observed mark
        (START + 900_000 + ex.HOLDING_MS, 0.5, None),       # at/after deadline: never charged
    ])
    outcome = ex.simulate(plan(), candles, funding, BASELINE)
    assert outcome.reason == 'holding_deadline' and len(outcome.funding_cash) == 2 and outcome.ambiguous
    first_mark = candles.close[candles.index(START + 900_000 + 3_600_000) - 1]
    assert outcome.funding == pytest.approx(-2.0 * first_mark * 0.0002 + 2.0 * 101.0 * 0.0001)
    exact = ex.FundingSeries.from_events([(START + 900_000 + 3 * ex.MINUTE, 0.01, None)])
    stopped = series('BTCUSDT', START, flat(15) + [100.0, 100.2, 97.0] + flat(20, 97.0))
    outcome = ex.simulate(plan(), stopped, exact, BASELINE)
    assert outcome.reason == 'stop' and outcome.funding == 0.0 and not outcome.ambiguous  # exact exit boundary


@pytest.mark.parametrize('rate,charged', [(0.001, True), (-0.001, False)])
def test_funding_inside_an_uncertain_exit_bar(rate, charged):
    stopped = series('BTCUSDT', START, flat(15) + [100.0, 100.2, 97.0] + flat(20, 97.0))
    inside = ex.FundingSeries.from_events([(START + 900_000 + 3 * ex.MINUTE - 1, rate, None)])
    outcome = ex.simulate(plan(), stopped, inside, BASELINE)
    assert outcome.reason == 'stop' and outcome.ambiguous
    expected = -2.0 * stopped.close[16] * rate if charged else 0.0
    assert outcome.funding == pytest.approx(expected) and bool(outcome.funding_cash) is charged


def test_funding_without_prior_close_fails_closed():
    candles = series('BTCUSDT', START + 900_000, [99.9] + [100.0 + i * 0.0001 for i in range(600)])
    early = ex.FundingSeries.from_events([(START + 900_000 + 7, 0.0001, None)])
    with pytest.raises(ex.ExplorationError):
        ex.simulate(plan(), candles, early, BASELINE)


# ---------------------------------------------------------------- portfolio admission
def five_signals(decision, stop_atr=0.5):
    rows, candles, instruments = {s: [] for s in ex.SYMBOLS}, {}, {}
    for symbol in ex.SYMBOLS[:5]:
        rows[symbol].append(php_row('5m', 'vwap', 100.0, '5m', 0.1, '15m', stop_atr, 100.0, ms=decision))
        candles[symbol] = series(symbol, START, flat(15) + [100.0] * 2000)
        instruments[symbol] = {**CENT, 'symbol': symbol}
    return rows, candles, instruments


def test_admission_limits_concurrency_and_daily_loss():
    rows, candles, instruments = five_signals(START + 900_000)
    summary, outcomes = ex.run_hypothesis(rows, candles, None, ex.POLICIES['S_sections_only'],
                                          ex.Geometry(stop_timeframe='15m'), instruments, BASELINE,
                                          ex.PHASES['training'])
    assert summary['signals'] == 5 and summary['rejections'] == {'research_portfolio_concurrency_exceeded': 1}
    assert len(outcomes) == 4 and summary['execution_authority'] == 'none'
    risky = dict(BASELINE, stop_slippage_rate=0.05)
    summary, _ = ex.run_hypothesis(rows, candles, None, ex.POLICIES['S_sections_only'],
                                   ex.Geometry(stop_timeframe='15m', stop_atr_multiplier=5.0, target_risk_multiple=4.0),
                                   instruments, risky, ex.PHASES['training'])
    assert summary['rejections'].get('research_portfolio_daily_loss_exceeded', 0) >= 1
    with pytest.raises(ex.ExplorationError):
        ex.run_hypothesis(rows, candles, None, ex.POLICIES['S_sections_only'], ex.Geometry(), instruments,
                          BASELINE, (ex.PHASES['validation'][0], ex.HOLDOUT_START_MS + ex.DAY))


def test_holding_window_is_checked_after_portfolio_admission():
    last = START + ex.DAY - ex.MINUTE  # not 15m aligned: the deadline would be one minute away
    rows, candles, instruments = five_signals(START + ex.DAY - 1_800_000)
    for symbol in ex.SYMBOLS[:2]:
        rows[symbol].append(php_row('5m', 'vwap', 100.0, '5m', 0.1, '15m', 0.5, 100.0, ms=last))
    summary, _ = ex.run_hypothesis(rows, candles, None, ex.POLICIES['S_sections_only'], ex.Geometry(stop_timeframe='15m'),
                                   instruments, BASELINE, ex.PHASES['training'])
    assert summary['rejections'] == {'research_portfolio_concurrency_exceeded': 3}
    alone = {s: [r for r in rows[s] if r['ms'] == last] for s in ex.SYMBOLS}
    summary, _ = ex.run_hypothesis(alone, candles, None, ex.POLICIES['S_sections_only'], ex.Geometry(stop_timeframe='15m'),
                                   instruments, BASELINE, ex.PHASES['training'])
    assert summary['rejections'] == {'research_holding_window_unavailable': 2}


def test_realized_today_excludes_midnight_and_prior_day_cash():
    day = START + ex.DAY
    outcome = ex.Outcome(plan(), filled=True, fill_ms=day - ex.MINUTE, exit_ms=day + 3_600_000,
                         entry_cost=1.0, exit_cash=5.0, funding_cash=[(day, -0.5), (day + 60_000, -0.25)])
    assert ex.realized_today(day + 7_200_000, [outcome]) == pytest.approx(4.75)
    assert ex.realized_today(day, [outcome]) == 0.0
    assert ex.realized_today(day + 7_200_000, [ex.Outcome(plan())]) == 0.0
    midnight_exit = ex.Outcome(plan(), filled=True, fill_ms=day - 3_600_000, exit_ms=day, entry_cost=1.0, exit_cash=-4.0)
    assert ex.realized_today(day + 900_000, [midnight_exit]) == 0.0  # settled by the prior day's ledger
    assert ex.realized_today(day, [midnight_exit]) == 0.0
    assert ex.realized_today(day - 60_000, [midnight_exit]) == pytest.approx(-1.0)


# ----------------------------------------------------------- statistics and screening
def outcome_at(decision, exit_ms, net, symbol='BTCUSDT', *, risk=4.5, entry_cost=0.0, funding_cash=()):
    result = ex.Outcome(plan(symbol=symbol, decision_ms=decision, net_risk=risk), filled=True,
                        fill_ms=decision + ex.MINUTE, exit_ms=exit_ms, reason='target' if net > 0 else 'stop',
                        entry_cost=entry_cost, funding_cash=list(funding_cash))
    result.exit_cash = net + entry_cost - sum(a for _, a in funding_cash)
    result.funding = sum(a for _, a in funding_cash)
    result.net, result.net_r = net, net / risk
    return result


def test_summary_uses_exit_year_for_trades_and_cash_timestamps_for_annual_pnl():
    new_year = 1_735_689_600_000  # 2025-01-01T00:00:00Z
    crossing = outcome_at(new_year - 900_000, new_year, 3.0, entry_cost=0.25, funding_cash=[(new_year - 60_000, -0.1)])
    summary = ex.summarize(1, {}, [crossing])
    assert summary['by_year']['2025']['trades'] == 1 and summary['by_year']['2024']['trades'] == 0
    assert summary['by_year']['2024']['cashflow_net'] == pytest.approx(-0.35)
    assert summary['by_year']['2025']['cashflow_net'] == pytest.approx(3.35)
    assert summary['net_r_stdev'] is None


def screening_fixture(trades=120, adverse_trades=120, losing_year=None):
    outcomes = []
    for index in range(max(trades, adverse_trades)):
        year_ms = START if index % 2 else START - 300 * ex.DAY
        net = 3.0 if index % 3 else -2.0
        if losing_year == ('2023' if index % 2 == 0 else '2024'):
            net = -abs(net)
        outcomes.append(outcome_at(year_ms, year_ms + index * ex.MINUTE, net, ex.SYMBOLS[index % 6]))
    return ex.summarize(500, {}, outcomes[:trades]), ex.summarize(500, {}, outcomes[:adverse_trades])


def test_screening_applies_every_gate_to_both_profiles():
    baseline, adverse = screening_fixture()
    assert baseline['profit_factor'] == 3.0 and baseline['realized_drawdown_quote'] == 2.0
    assert baseline['net_r_stdev'] > 0
    assert ex.screening_reasons(baseline, 'training', 'baseline') == []
    assert ex.screening_reasons(adverse, 'training', 'adverse') == []
    _, thin = screening_fixture(adverse_trades=3)
    assert 'insufficient_trades' in ex.screening_reasons(thin, 'training', 'adverse')
    assert 'insufficient_annual_training_trades:2024' in ex.screening_reasons(thin, 'training', 'adverse')
    losing, _ = screening_fixture(losing_year='2023')
    assert 'nonpositive_annual_net_pnl:2023' in ex.screening_reasons(losing, 'training', 'baseline')
    empty = ex.summarize(0, {}, [])
    reasons = ex.screening_reasons(empty, 'validation', 'baseline')
    assert {'insufficient_trades', 'insufficient_pairs', 'nonpositive_mean_net_r', 'undefined_net_profit_factor',
            'nonpositive_annual_net_pnl:2025'} <= set(reasons)
    weak = dict(baseline, profit_factor=1.1, realized_drawdown_quote=7000.0)
    assert {'net_profit_factor_below_threshold', 'marked_drawdown_exceeded'} <= set(
        ex.screening_reasons(weak, 'training', 'baseline'))
    assert 'net_profit_factor_below_threshold' not in ex.screening_reasons(weak, 'training', 'adverse')
    with pytest.raises(ex.ExplorationError):
        ex.screening_reasons(baseline, 'holdout', 'baseline')


def test_screen_report_separates_baseline_passes_from_protocol_eligibility():
    baseline, adverse = screening_fixture()
    _, thin = screening_fixture(adverse_trades=3)
    rows = []
    for policy, adverse_summary in (('A', adverse), ('B', thin)):
        for phase in ('training', 'validation'):
            rows.append({'policy': policy, 'geometry_id': 'g', 'phase': phase, 'profile': 'baseline', **baseline})
            rows.append({'policy': policy, 'geometry_id': 'g', 'phase': phase, 'profile': 'adverse', **adverse_summary})
    rows.append({'policy': 'C', 'geometry_id': 'g', 'phase': 'training', 'profile': 'baseline', **ex.summarize(0, {}, [])})
    report = ex.screen_report(rows)
    assert report['configurations'] == 3 and report['configurations_with_minimum_trades'] == 2
    assert report['training_baseline_passes'] == ['A|g', 'B|g']
    assert report['training_protocol_passes'] == ['A|g']
    assert report['protocol_eligible'] == []  # the 2025 fixture rows carry 2023/2024 trades only
    assert report['median_mean_net_r'] == pytest.approx(baseline['mean_net_r'])
    assert report['share_positive_mean_net_r'] == 1.0 and len(report['top_baseline_training']) == 2
    assert ex.screen_report([])['median_mean_net_r'] is None


# ---------------------------------------------------------------- extraction and CLI
def result_line(ms, *, sections=True, setup_hash=ex.BASELINE_SETUP_HASH, symbol='BTCUSDT',
                filters=(True, True, True, True, True)):
    compact = {'close': 100.0, 'rsi': 50.0, 'vwap': 100.0, 'atr': 1.0, 'ma_21_plus_k_atr': 101.0,
               'pullback_age_bars': 1, 'ema_20': 100.0, 'ema_50': 99.0,
               'ema': {'9': 100.2, '20': 100.0, '21': 100.0, '50': 99.0, '200': 95.0},
               'ema_prev': {'9': 99.9, '20': 100.0, '21': 100.0, '50': 99.0, '200': 95.0},
               'adx': {'14': 25.0, '15': 24.0}}
    return json.dumps({'schema_version': 'research-signal-result.v1', 'symbol': symbol, 'evaluated_ms': ms,
                       'baseline': {'setup_hash': setup_hash}, 'contexts': {t: compact for t in ex.TIMEFRAMES},
                       'verdicts': {'sections': {s: {'passed': sections} for s in ex.SECTIONS},
                                    'filters': [{'passed': v} for v in filters]}})


def test_extract_rows_proves_parity_and_keeps_section_ready_rows():
    lines = [result_line(START), result_line(START + 900_000, sections=False)]
    kept, report = ex.extract_rows(lines, 'BTCUSDT')
    assert report == {'execution_authority': 'none', 'symbol': 'BTCUSDT', 'rows': 2, 'kept': 1,
                      'sample_modulus': None, 'filter_mismatches': {}}
    assert kept[0]['15m']['e9'] == 100.2 and kept[0]['1h']['adx14'] == 25.0
    with pytest.raises(ex.ExplorationError):
        ex.extract_rows([result_line(START), result_line(START + 1_800_000)], 'BTCUSDT')
    with pytest.raises(ex.ExplorationError):
        ex.extract_rows([result_line(START, setup_hash='0' * 64)], 'BTCUSDT')
    with pytest.raises(ex.ExplorationError):
        ex.extract_rows([result_line(ex.HOLDOUT_START_MS)], 'BTCUSDT')


def test_extract_rows_random_control_ignores_verdicts():
    lines = [result_line(START + i * 900_000, sections=False) for i in range(400)]
    kept, report = ex.extract_rows(lines, 'BTCUSDT', sample_modulus=20)
    assert 0 < len(kept) < 60 and report['sample_modulus'] == 20
    assert all(not r['sec']['regime'] for r in kept)
    summary, _ = ex.run_hypothesis({'BTCUSDT': kept}, {}, None, ex.POLICIES['R_random_control'], ex.Geometry(),
                                   {'BTCUSDT': {**BTC, 'min_quantity': 1e9}}, BASELINE, ex.PHASES['training'])
    assert summary['signals'] == len(kept) and summary['trades'] == 0
    assert summary['rejections'] == {'canonical_risk_quantity_below_minimum': len(kept)}
    with pytest.raises(ex.ExplorationError):
        ex.extract_rows(lines, 'BTCUSDT', sample_modulus=1)


def prepared_root(tmp_path, rows_by_symbol):
    root = tmp_path / 'root'
    (root / 'training').mkdir(parents=True)
    (root / 'candles').mkdir()
    for symbol in ex.SYMBOLS:
        (root / 'training' / f'{symbol}.kept.json').write_text(json.dumps(rows_by_symbol.get(symbol, [])))
        series(symbol, START, flat(15) + [100.0] * 600).write(root / 'candles' / f'{symbol}.candles.bin', {})
    (root / 'funding.json').write_text(json.dumps({s: [[START + 3_600_000, 0.0001, None]] for s in ex.SYMBOLS}))
    (root / 'instrument-assumptions.json').write_text(json.dumps({'symbols': [{**CENT, 'symbol': s} for s in ex.SYMBOLS]}))
    (root / 'cost-assumptions.json').write_text(json.dumps({'profiles': {'baseline': BASELINE, 'adverse': ADVERSE}}))
    return root


def test_cli_extract_grid_and_screen(tmp_path, capsys):
    results = tmp_path / 'BTCUSDT.results.ndjson'
    results.write_text(result_line(START, filters=(True, True, True, False, True)) + '\n')
    assert ex.main(['extract', '--output', str(tmp_path / 'training'), str(results)]) == 1  # vwap parity breaks
    report = json.loads((tmp_path / 'training' / 'extract-report.json').read_text())
    assert report[0]['filter_mismatches'] == {'pullback_confirmed_vwap': 1}
    assert ex.main(['extract', '--control-modulus', '2', '--output', str(tmp_path / 'control'), str(results)]) == 1
    root = prepared_root(tmp_path, {'BTCUSDT': [php_row('5m', 'vwap', 100.0, '5m', 0.1, '15m', 2.0, 100.0)]})
    output = tmp_path / 'grid.json'
    assert ex.main(['grid', '--root', str(root), '--phase', 'training', '--policies', 'S_sections_only',
                    '--geometries', 'baseline,Zema15w30.S15x15.T15', '--output', str(output)]) == 0
    grid = json.loads(output.read_text())
    assert len(grid) == 4 and {g['geometry_id'] for g in grid} == {'baseline', 'Zema15w30.S15x15.T15'}
    assert all(g['execution_authority'] == 'none' for g in grid)
    assert ex.CandleSeries.read(root / 'candles' / 'BTCUSDT.candles.bin').count == 615
    assert ex.main(['grid', '--root', str(root), '--phase', 'training', '--geometries', 'extended',
                    '--profiles', 'baseline', '--output', str(tmp_path / 'all.json')]) == 0
    assert len(json.loads((tmp_path / 'all.json').read_text())) == len(ex.SETUP_POLICIES) * 69
    capsys.readouterr()
    assert ex.main(['screen', str(output)]) == 0
    assert json.loads(capsys.readouterr().out)['configurations'] == 2


def test_cli_prepare_uses_verified_readers(tmp_path, monkeypatch):
    from app.backtesting.research import funding as funding_module
    from app.backtesting.research import signal_sources

    class Selection:
        dataset_sha256 = 'a' * 64
        manifest_sha256 = 'b' * 64

    calls, readers = [], []
    monkeypatch.setattr(signal_sources, 'select_sources',
                        lambda root, s, e, sc, symbol: calls.append((s, e, sc)) or Selection())
    monkeypatch.setattr(signal_sources, 'iter_verified_candles', lambda root, selection: iter([
        {'open_ms': START, 'open': '1', 'high': '2', 'low': '0.5', 'close': '1.5'}]))

    class Inventory:
        evidence_complete, inventory_hash, coverage = True, 'sha256:' + 'e' * 64, 'complete'

    class Event:
        def __init__(self, symbol, timestamp=START):
            self.symbol, self.timestamp_ms, self.rate, self.observed_mark = symbol, timestamp, D('0.0001'), None

    class Reader:
        start_ms, end_ms = 1_672_531_200_000, ex.HOLDOUT_START_MS
        inventory = Inventory()
        events = [Event('BTCUSDT')]

        def __init__(self, *args, **kwargs):
            readers.append((args, kwargs))

        def iter_events(self):
            return iter(self.events)

    monkeypatch.setattr(funding_module, 'FundingReader', Reader)
    documents = []
    for name, field in (('instruments.json', 'manifest_hash'), ('costs.json', 'assumption_hash')):
        document = {'profiles': {'baseline': BASELINE}, 'symbols': [BTC]}
        document[field] = canonical_hash(document)
        (tmp_path / name).write_text(json.dumps(document))
        documents.append(tmp_path / name)
    root = tmp_path / 'prepared'
    arguments = ['prepare', '--root', str(root), '--dataset-root', str(tmp_path), '--funding-supplement-root',
                 str(tmp_path / 'supplement'), '--instrument-path', str(documents[0]), '--cost-path', str(documents[1])]
    assert ex.main(arguments) == 0
    assert calls[0] == ('2023-01-01T00:00:00Z', '2026-01-01T00:00:00Z', '2023-01-01T00:00:00Z')
    assert len(calls) == len(ex.SYMBOLS)
    assert readers[0] == ((tmp_path, '2023-01-01T00:00:00Z', '2026-01-01T00:00:00Z'),
                          {'supplement_root': tmp_path / 'supplement',
                           'rest_interval_hypothesis': funding_module.REST_HYPOTHESIS})
    report = json.loads((root / 'prepare-report.json').read_text())
    assert report['funding']['events']['BTCUSDT'] == 1 and report['execution_authority'] == 'none'
    assert json.loads((root / 'funding.json').read_text())['BTCUSDT'] == [[START, 0.0001, None]]
    Reader.events = [Event('BTCUSDT', ex.HOLDOUT_START_MS)]
    with pytest.raises(ex.ExplorationError):
        ex.main(arguments)
    Reader.events, Inventory.evidence_complete = [Event('BTCUSDT')], False
    with pytest.raises(ex.ExplorationError):
        ex.main(arguments)
    Inventory.evidence_complete = True
    broken = json.loads(documents[1].read_text())
    broken['assumption_hash'] = 'sha256:' + '0' * 64
    documents[1].write_text(json.dumps(broken))
    with pytest.raises(ex.ExplorationError):
        ex.prepare(root, tmp_path, tmp_path, documents[0], documents[1])


# ----------------------------------------------------------- canonical-kernel parity
def _lcg_path(seed, count, start_price, volatility, drift):
    state, price, closes = seed, start_price, []
    for _ in range(count):
        state = (state * 1103515245 + 12345) % (2 ** 31)
        price = max(price * (1 + drift + volatility * ((state / 2 ** 31) - 0.5) * 2), 0.01)
        closes.append(round(price, 2))
    return closes


def synthetic_days(days, volatility, drifts):
    symbols = ('BTCUSDT', 'ETHUSDT')
    end = START + days * ex.DAY
    candles, rows, instruments = {}, {s: [] for s in ex.SYMBOLS}, {}
    for offset, symbol in enumerate(symbols):
        closes = _lcg_path(7 + offset, days * 1440 + 1, 100.0 + offset, volatility, drifts[offset])
        candles[symbol] = series(symbol, START - ex.MINUTE, closes, spread=0.05)
        instruments[symbol] = {**CENT, 'symbol': symbol}
        for number, decision in enumerate(range(START, end, 900_000)):
            close = candles[symbol].close[candles[symbol].index(decision) - 1]
            anchor = close * (1.05 if number % 7 == 3 else 1.0004)  # some candidates fall outside the zone
            rows[symbol].append(php_row('5m', 'vwap', anchor, '5m', close * 0.002, '15m', close * 0.01,
                                        close, ms=decision))
    events = {s: [(START + h * 28_800_000 + (3 if h else 0), (-1) ** h * 0.0003, 100.0 if h % 2 else None)
                  for h in range(3 * days)] for s in symbols}
    return symbols, end, candles, {s: rows[s] for s in symbols}, events, instruments


@pytest.mark.parametrize('volatility,drifts,expected', [
    (0.003, (-0.0002, 0.0008), lambda s: {'stop', 'target', 'midnight'} <= set(s['exit_reasons'])
     and s['rejections'].get('research_portfolio_concurrency_exceeded', 0) > 0),
    (0.004, (-0.0001, 0.0003), lambda s: s['rejections'].get('research_portfolio_daily_loss_exceeded', 0) > 0),
])
def test_exploration_matches_portfolio_simulator_over_two_days(volatility, drifts, expected):
    symbols, end, candles, rows, events, instruments = synthetic_days(2, volatility, drifts)
    funding = {s: ex.FundingSeries.from_events(events[s]) for s in symbols}
    geometry = ex.Geometry(stop_timeframe='15m', stop_atr_multiplier=1.0, target_risk_multiple=2.0)
    policy = ex.POLICIES['S_sections_only']
    summary, mine = ex.run_hypothesis(rows, candles, funding, policy, geometry, instruments, BASELINE, (START, end))
    canonical, rejections = ex.canonical_replay(rows, candles, events, policy, geometry, instruments, BASELINE,
                                                (START, end))
    assert summary['trades'] >= 10 and expected(summary)
    assert summary['rejections'].get('canonical_entry_zone_candidate_outside', 0) > 0
    assert {(t['exit_boundary_ms'] - START - 1) // ex.DAY for t in canonical.values()} == {0, 1}  # both UTC days
    assert ex.parity_differences(mine, summary['rejections'], canonical, rejections, end) == []


def test_unrealized_loss_blocks_admission_like_the_canonical_kernel():
    symbols = ('BTCUSDT', 'ETHUSDT')
    end = START + ex.DAY
    first, second = START + 900_000, START + 1_800_000
    btc = [100.5] * 16 + [100.0] + [100.0 - 0.2 * i for i in range(1, 16)] + [97.0] * (1441 - 32)
    eth = [100.5] * 31 + [100.0] * (1441 - 31)
    candles = {'BTCUSDT': series('BTCUSDT', START - ex.MINUTE, btc), 'ETHUSDT': series('ETHUSDT', START - ex.MINUTE, eth)}
    instruments = {s: {**CENT, 'symbol': s} for s in symbols}
    rows = {'BTCUSDT': [php_row('5m', 'vwap', 100.5, '5m', 0.5, '15m', 3.62, 100.5, ms=first)],
            'ETHUSDT': [php_row('5m', 'vwap', 100.5, '5m', 0.5, '15m', 3.62, 100.5, ms=second)]}
    geometry, policy = ex.Geometry(stop_timeframe='15m'), ex.POLICIES['S_sections_only']
    summary, mine = ex.run_hypothesis(rows, candles, None, policy, geometry, instruments, BASELINE, (START, end))
    canonical, rejections = ex.canonical_replay(rows, candles, {}, policy, geometry, instruments, BASELINE, (START, end))
    assert summary['rejections'] == {'research_portfolio_daily_loss_exceeded': 1}
    assert set(canonical) == {('BTCUSDT', first)} and mine[0].reason == 'holding_deadline'
    assert ex.parity_differences(mine, summary['rejections'], canonical, rejections, end) == []


def test_canonical_replay_requires_the_prephase_minute():
    symbols, end, candles, rows, events, instruments = synthetic_days(1, 0.003, (0.0, 0.0))
    shifted = {s: ex.CandleSeries(s, START, c.open[1:], c.high[1:], c.low[1:], c.close[1:]) for s, c in candles.items()}
    with pytest.raises(ex.ExplorationError):
        ex.canonical_replay(rows, shifted, events, ex.POLICIES['S_sections_only'], ex.Geometry(), instruments,
                            BASELINE, (START, end))


def test_parity_differences_reports_every_mismatch():
    outcome = outcome_at(START + 900_000, START + 2_000_000, 3.0)
    outcome.exit_price = 2.0
    trade = {'exit_reason': 'stop', 'fill_boundary_ms': outcome.fill_ms, 'exit_boundary_ms': outcome.exit_ms,
             'ambiguous': False, 'exit_price': 1.0, 'net_pnl_quote': '2.0', 'funding_quote': '0'}
    differences = ex.parity_differences([outcome], {'a': 1}, {('BTCUSDT', START + 900_000): trade,
                                                              ('ETHUSDT', START): trade}, {'a': 2}, START + ex.DAY)
    assert any(d.startswith('only_canonical') for d in differences)
    assert any(d.startswith('exit_reason') for d in differences) and any(d.startswith('exit_price') for d in differences)
    assert any(d.startswith('net_pnl_quote') for d in differences) and any(d.startswith('rejections') for d in differences)
    assert ex.parity_differences([outcome], {}, {}, {}, START + ex.DAY) == [f"only_exploration:{('BTCUSDT', START + 900_000)}"]
