"""Synthetic exploration tests: conditions, plan arithmetic, simulation and canonical-kernel parity."""
from __future__ import annotations

import json
from array import array
from decimal import ROUND_DOWN, Decimal as D

import pytest

from app.backtesting.research import exploration as ex
from app.backtesting.research.portfolio_simulator import (
    IDENTITY_FIELDS, Candle, CostAssumptions, FundingCoverage, FundingEvent, InstrumentAssumptions,
    PortfolioSimulator, RunAssumptions, Signal, canonical_hash, number, wire_number)

START = 1_709_510_400_000  # 2024-03-04T00:00:00Z, a training day
BASELINE = {'entry_spread_rate': 0, 'stop_spread_rate': 0.0002, 'target_spread_rate': 0, 'entry_slippage_rate': 0,
            'stop_slippage_rate': 0.0005, 'target_slippage_rate': 0, 'funding_provision_rate': 0.0003}
ADVERSE = {**BASELINE, 'stop_spread_rate': 0.001, 'stop_slippage_rate': 0.001, 'funding_provision_rate': 0.001}
BTC = {'symbol': 'BTCUSDT', 'tick_size': 0.1, 'quantity_step': 0.001, 'min_quantity': 0.001,
       'max_quantity': 1000.0, 'min_notional': 50.0, 'contract_size': 1.0, 'leverage_cap': 2.0,
       'mmr_proxy_rate': 0.01, 'liquidation_fee_rate': 0.005}
DOGE = {**BTC, 'symbol': 'DOGEUSDT', 'tick_size': 1e-05, 'quantity_step': 1.0, 'min_quantity': 1.0,
        'max_quantity': 300000000.0, 'min_notional': 5.0}


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
    for policy in ex.POLICIES.values():
        assert isinstance(policy.predicate(row()), bool) and policy.description


def test_geometry_catalogue_and_validation():
    assert len(ex.CANONICAL_GEOMETRIES) == 13 and len(ex.EXTENDED_GEOMETRIES) == 72
    assert ex.GEOMETRIES['Zema15a15w50.S1hx15.T30'] == ex.Geometry(
        anchor_source='ema_20', anchor_timeframe='15m', atr_timeframe='15m', zone_atr_multiplier=0.5,
        stop_timeframe='1h', stop_atr_multiplier=1.5, target_risk_multiple=3.0)
    for bad in ({'anchor_source': 'close'}, {'stop_timeframe': '2h'}, {'minimum_half_width_rate': 0.02},
                {'target_risk_multiple': 0}):
        with pytest.raises(ex.ExplorationError):
            ex.Geometry(**bad)


def test_tick_quantization_is_exact():
    assert ex.floor_to(20973.38, 0.1) == 20973.3 and ex.ceil_to(20973.31, 0.1) == 20973.4
    assert ex.ceil_to(20973.3, 0.1) == 20973.3 and ex.floor_to(0.0815149, 1e-05) == 0.08151
    assert ex.floor_to(250 / 0.08151, 1.0) == 3067.0


# --------------------------------------------------------------- PHP reference vectors
# Produced by EntryZonePriceMath, ProtectionPriceMath and NetRCostMath on 2026-10-11.
def php_row(anchor_tf, anchor_key, anchor, atr_tf, atr, stop_tf, stop_atr, candidate):
    signal = row()
    signal[anchor_tf] = {**signal[anchor_tf], anchor_key: anchor}
    signal[atr_tf] = {**signal[atr_tf], 'atr': atr}
    signal[stop_tf] = {**signal[stop_tf], 'atr': stop_atr}
    signal['15m'] = {**signal['15m'], 'close': candidate}
    return signal


def test_plan_matches_php_reference_vectors():
    geometry = ex.Geometry(stop_timeframe='15m')
    plan, reason = ex.build_plan(php_row('5m', 'vwap', 26500.12, '5m', 40.5, '15m', 120.4, 26505.3),
                                 'BTCUSDT', geometry, BTC, BASELINE)
    assert reason is None
    assert (plan.entry, plan.stop, plan.target, plan.quantity) == (26505.3, 26324.7, 26866.5, 0.009)
    assert plan.net_risk == pytest.approx(2.02898061, abs=1e-9)
    assert plan.net_r == pytest.approx(1.483812553536428324, abs=1e-12)
    _, reason = ex.build_plan(php_row('5m', 'vwap', 26500.12, '5m', 40.5, '15m', 120.4, 26530.0),
                              'BTCUSDT', geometry, BTC, BASELINE)
    assert reason == 'canonical_entry_zone_candidate_outside'
    _, reason = ex.build_plan(php_row('5m', 'vwap', 26500.12, '5m', 40.5, '5m', 40.5, 26505.3),
                              'BTCUSDT', ex.Geometry(), BTC, BASELINE)
    assert reason == 'research_minimum_net_r_not_met'
    doge = ex.Geometry(anchor_source='ema_20', anchor_timeframe='15m', atr_timeframe='15m',
                       zone_atr_multiplier=0.5, stop_timeframe='1h', target_risk_multiple=3.0)
    signal = php_row('15m', 'ema_20', 0.081234, '15m', 0.00091, '1h', 0.00262, 0.08151)
    plan, reason = ex.build_plan(signal, 'DOGEUSDT', doge, DOGE, ADVERSE)
    assert reason is None
    assert (plan.entry, plan.stop, plan.target, plan.quantity) == (0.08151, 0.07758, 0.0933, 3067.0)
    assert plan.net_risk == pytest.approx(12.948144054, abs=1e-9)


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


def test_plan_rejects_unavailable_holding_window_and_bad_zone():
    signal = php_row('5m', 'vwap', 26500.12, '5m', 40.5, '15m', 120.4, 26505.3)
    signal['ms'] = ex.DAY * 19700 - ex.MINUTE
    assert ex.build_plan(signal, 'BTCUSDT', ex.Geometry(stop_timeframe='15m'), BTC, BASELINE)[1] == \
        'research_holding_window_unavailable'
    tiny = php_row('5m', 'vwap', 0.0001, '5m', 0.00005, '15m', 0.00001, 0.0001)
    assert ex.build_plan(tiny, 'BTCUSDT', ex.Geometry(stop_timeframe='15m'), BTC, BASELINE)[1] == \
        'canonical_entry_zone_bounds_invalid'
    flat = php_row('5m', 'vwap', 26500.12, '5m', 40.5, '15m', 0.01, 26505.3)
    assert ex.build_plan(flat, 'BTCUSDT', ex.Geometry(stop_timeframe='15m', target_risk_multiple=0.1),
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
    assert outcome.release_ms == START + 900_000 + ex.PENDING_RELEASE_MS


def test_stop_on_fill_bar_is_ambiguous_and_gap_exits_at_open():
    closes = flat(15) + [97.5] + flat(30, 97.0)
    outcome = ex.simulate(plan(), series('BTCUSDT', START, closes), None, BASELINE)
    assert outcome.filled and outcome.reason == 'stop' and outcome.ambiguous
    assert outcome.exit_price == 98.0 and outcome.exit_ms == START + 16 * ex.MINUTE
    gap = series('BTCUSDT', START, flat(15) + [100.0, 99.0, 97.0] + flat(30, 97.0))
    gap.open[17] = 97.5
    outcome = ex.simulate(plan(), gap, None, BASELINE)
    assert outcome.reason == 'stop' and outcome.exit_price == 97.5 and not outcome.ambiguous


def test_fill_bar_target_is_ignored_then_target_and_costs():
    closes = flat(15) + [103.5, 103.6] + flat(30, 103.6)
    candles = series('BTCUSDT', START, closes)
    candles.low[15] = 99.9
    outcome = ex.simulate(plan(), candles, None, BASELINE)
    assert outcome.reason == 'target' and outcome.exit_ms == START + 17 * ex.MINUTE and outcome.ambiguous
    entry_cost = 2.0 * 100.0 * 0.0002
    exit_cash = 2.0 * 3.0 - 2.0 * 103.0 * 0.0005
    assert outcome.net == pytest.approx(exit_cash - entry_cost) and outcome.net_r == pytest.approx(outcome.net / 4.5)


def test_stop_wins_when_both_bounds_are_touched():
    candles = series('BTCUSDT', START, flat(15) + [100.0, 100.5] + flat(30))
    candles.high[16], candles.low[16] = 104.0, 97.0
    outcome = ex.simulate(plan(), candles, None, BASELINE)
    assert outcome.reason == 'stop' and outcome.ambiguous and outcome.exit_price == 98.0


def test_deadline_and_midnight_exit_at_previous_close():
    candles = series('BTCUSDT', START, flat(15) + [100.0] + [100.0 + i * 0.001 for i in range(600)])
    outcome = ex.simulate(plan(deadline_ms=START + 900_000 + 2 * 3_600_000), candles, None, BASELINE)
    assert outcome.reason == 'holding_deadline' and outcome.exit_ms == START + 900_000 + 7_200_000
    assert outcome.exit_price == candles.close[candles.index(outcome.exit_ms) - 2]  # strictly prior close
    late = START + ex.DAY - 1_800_000
    night = series('BTCUSDT', late - 15 * ex.MINUTE, flat(15) + [100.0] * 60)
    outcome = ex.simulate(plan(decision_ms=late, deadline_ms=START + ex.DAY), night, None, BASELINE)
    assert outcome.reason == 'midnight' and outcome.exit_ms == START + ex.DAY


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
    assert outcome.reason == 'holding_deadline' and len(outcome.funding_cash) == 2
    first_mark = candles.close[candles.index(START + 900_000 + 3_600_000) - 1]
    assert outcome.funding == pytest.approx(-2.0 * first_mark * 0.0002 + 2.0 * 101.0 * 0.0001)
    exact = ex.FundingSeries.from_events([(START + 900_000 + 3 * ex.MINUTE, 0.01, None)])
    stopped = series('BTCUSDT', START, flat(15) + [100.0, 100.2, 97.0] + flat(20, 97.0))
    outcome = ex.simulate(plan(), stopped, exact, BASELINE)
    assert outcome.reason == 'stop' and outcome.funding == 0.0  # exact exit boundary is skipped


# ---------------------------------------------------------------- portfolio admission
def test_admission_limits_concurrency_and_daily_loss():
    decision = START + 900_000
    rows = {s: [] for s in ex.SYMBOLS}
    candles, instruments = {}, {}
    for symbol in ex.SYMBOLS[:5]:
        signal = php_row('5m', 'vwap', 100.0, '5m', 0.1, '15m', 0.5, 100.0)
        signal['ms'] = decision
        rows[symbol].append(signal)
        candles[symbol] = series(symbol, START, flat(15) + [100.0] * 600)
        instruments[symbol] = {**BTC, 'symbol': symbol, 'tick_size': 0.01, 'min_notional': 5.0}
    summary, outcomes = ex.run_hypothesis(rows, candles, None, ex.POLICIES['S_sections_only'],
                                          ex.Geometry(stop_timeframe='15m'), instruments, BASELINE,
                                          ex.PHASES['training'])
    assert summary['signals'] == 5 and summary['rejections'] == {'research_portfolio_concurrency_exceeded': 1}
    assert len(outcomes) == 4
    capped = {s: {**i, 'min_notional': 5.0} for s, i in instruments.items()}
    risky = dict(BASELINE, stop_slippage_rate=0.05)
    summary, _ = ex.run_hypothesis(rows, candles, None, ex.POLICIES['S_sections_only'],
                                   ex.Geometry(stop_timeframe='15m', stop_atr_multiplier=5.0, target_risk_multiple=4.0),
                                   capped, risky, ex.PHASES['training'])
    assert summary['rejections'].get('research_portfolio_daily_loss_exceeded', 0) >= 1
    with pytest.raises(ex.ExplorationError):
        ex.run_hypothesis(rows, candles, None, ex.POLICIES['S_sections_only'], ex.Geometry(), instruments,
                          BASELINE, (ex.PHASES['validation'][0], ex.HOLDOUT_START_MS + ex.DAY))


def test_realized_today_excludes_midnight_and_prior_day_cash():
    day = START + ex.DAY
    outcome = ex.Outcome(plan(), filled=True, fill_ms=day - ex.MINUTE, exit_ms=day + 3_600_000,
                         entry_cost=1.0, exit_cash=5.0, funding_cash=[(day, -0.5), (day + 60_000, -0.25)])
    assert ex.realized_today(day + 7_200_000, [outcome]) == pytest.approx(4.75)
    assert ex.realized_today(day, [outcome]) == 0.0
    assert ex.realized_today(day + 7_200_000, [ex.Outcome(plan())]) == 0.0


def test_summary_and_training_gates():
    trades = []
    for index in range(120):
        year_ms = START if index % 2 else START - 300 * ex.DAY
        trade = ex.Outcome(plan(symbol=ex.SYMBOLS[index % 6], decision_ms=year_ms), filled=True,
                           exit_ms=year_ms + index * ex.MINUTE, reason='target' if index % 3 else 'stop')
        trade.net = 3.0 if index % 3 else -2.0
        trade.net_r = trade.net / 4.5
        trades.append(trade)
    summary = ex.summarize(500, {'x': 1}, trades)
    assert summary['trades'] == 120 and summary['pairs_with_trades'] == 6 and summary['profit_factor'] == 3.0
    assert set(summary['by_year']) == {'2023', '2024'} and summary['realized_drawdown_quote'] == 2.0
    assert ex.training_eligible(summary, {'profit_factor': 1.0})
    assert not ex.training_eligible(summary, {'profit_factor': 0.9})
    assert not ex.training_eligible(ex.summarize(0, {}, []), {'profit_factor': None})


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
    assert report == {'symbol': 'BTCUSDT', 'rows': 2, 'kept': 1, 'filter_mismatches': {}}
    assert kept[0]['15m']['e9'] == 100.2 and kept[0]['1h']['adx14'] == 25.0
    with pytest.raises(ex.ExplorationError):
        ex.extract_rows([result_line(START), result_line(START + 1_800_000)], 'BTCUSDT')
    with pytest.raises(ex.ExplorationError):
        ex.extract_rows([result_line(START, setup_hash='0' * 64)], 'BTCUSDT')
    with pytest.raises(ex.ExplorationError):
        ex.extract_rows([result_line(ex.HOLDOUT_START_MS)], 'BTCUSDT')


def test_cli_extract_and_grid(tmp_path, capsys):
    results = tmp_path / 'BTCUSDT.results.ndjson'
    results.write_text(result_line(START, filters=(True, True, True, False, True)) + '\n')
    assert ex.main(['extract', '--output', str(tmp_path / 'training'), str(results)]) == 1  # vwap parity breaks
    report = json.loads((tmp_path / 'training' / 'extract-report.json').read_text())
    assert report[0]['filter_mismatches'] == {'pullback_confirmed_vwap': 1}
    root = tmp_path / 'root'
    (root / 'training').mkdir(parents=True)
    (root / 'candles').mkdir()
    for symbol in ex.SYMBOLS:
        signal = php_row('5m', 'vwap', 100.0, '5m', 0.1, '15m', 2.0, 100.0)
        (root / 'training' / f'{symbol}.kept.json').write_text(json.dumps([signal] if symbol == 'BTCUSDT' else []))
        series(symbol, START, flat(15) + [100.0] * 600).write(root / 'candles' / f'{symbol}.candles.bin', {})
    (root / 'funding.json').write_text(json.dumps({s: [[START + 3_600_000, 0.0001, None]] for s in ex.SYMBOLS}))
    (root / 'instrument-assumptions.json').write_text(json.dumps({'symbols': [
        {**BTC, 'symbol': s, 'tick_size': 0.01, 'min_notional': 5.0} for s in ex.SYMBOLS]}))
    (root / 'cost-assumptions.json').write_text(json.dumps({'profiles': {'baseline': BASELINE, 'adverse': ADVERSE}}))
    output = tmp_path / 'grid.json'
    assert ex.main(['grid', '--root', str(root), '--phase', 'training', '--policies', 'S_sections_only',
                    '--geometries', 'baseline,Zema15w30.S15x15.T15', '--output', str(output)]) == 0
    grid = json.loads(output.read_text())
    assert len(grid) == 4 and {g['geometry_id'] for g in grid} == {'baseline', 'Zema15w30.S15x15.T15'}
    assert ex.CandleSeries.read(root / 'candles' / 'BTCUSDT.candles.bin').count == 615
    assert '"runs": 4' in capsys.readouterr().out


def test_cli_prepare_uses_verified_readers(tmp_path, monkeypatch):
    from app.backtesting.research import funding as funding_module
    from app.backtesting.research import signal_sources

    class Selection:
        dataset_sha256 = 'a' * 64
        manifest_sha256 = 'b' * 64

    calls = []
    monkeypatch.setattr(signal_sources, 'select_sources', lambda root, s, e, sc, symbol: calls.append((s, e)) or Selection())
    monkeypatch.setattr(signal_sources, 'iter_verified_candles', lambda root, selection: iter([
        {'open_ms': START, 'open': '1', 'high': '2', 'low': '0.5', 'close': '1.5'}]))

    class Event:
        def __init__(self, symbol):
            self.symbol, self.timestamp_ms, self.rate, self.observed_mark = symbol, START, D('0.0001'), None

    class Reader:
        def __init__(self, *args, **kwargs):
            pass

        def iter_events(self):
            return iter([Event('BTCUSDT')])

    monkeypatch.setattr(funding_module, 'FundingReader', Reader)
    documents = []
    for name, field in (('instruments.json', 'manifest_hash'), ('costs.json', 'assumption_hash')):
        document = {'profiles': {'baseline': BASELINE}, 'symbols': [BTC]}
        document[field] = canonical_hash(document)
        (tmp_path / name).write_text(json.dumps(document))
        documents.append(tmp_path / name)
    root = tmp_path / 'prepared'
    assert ex.main(['prepare', '--root', str(root), '--dataset-root', str(tmp_path), '--funding-supplement-root',
                    str(tmp_path), '--instrument-path', str(documents[0]), '--cost-path', str(documents[1])]) == 0
    assert calls[0] == ('2023-01-01T00:00:00Z', '2026-01-01T00:00:00Z') and len(calls) == len(ex.SYMBOLS)
    assert json.loads((root / 'funding.json').read_text())['BTCUSDT'] == [[START, 0.0001, None]]
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


@pytest.mark.parametrize('volatility,drifts,expected', [
    (0.003, (-0.0002, 0.0008), lambda s: {'stop', 'target', 'midnight'} <= set(s['exit_reasons'])
     and s['rejections'].get('research_portfolio_concurrency_exceeded', 0) > 0),
    (0.004, (-0.0001, 0.0003), lambda s: s['rejections'].get('research_portfolio_daily_loss_exceeded', 0) > 0),
])
def test_exploration_matches_portfolio_simulator_on_synthetic_day(volatility, drifts, expected):
    symbols = ('BTCUSDT', 'ETHUSDT')
    end = START + ex.DAY
    candles, rows, instruments = {}, {s: [] for s in ex.SYMBOLS}, {}
    for offset, symbol in enumerate(symbols):
        closes = _lcg_path(7 + offset, 1441, 100.0 + offset, volatility, drifts[offset])
        candles[symbol] = series(symbol, START - ex.MINUTE, closes, spread=0.05)
        instruments[symbol] = {**BTC, 'symbol': symbol, 'tick_size': 0.01, 'min_notional': 5.0}
        for decision in range(START, end, 900_000):
            close = candles[symbol].close[candles[symbol].index(decision) - 1]
            signal = php_row('5m', 'vwap', close * 1.0004, '5m', close * 0.002, '15m', close * 0.01, close)
            signal['ms'] = decision
            rows[symbol].append(signal)
    funding_events = {s: [(START + h * 28_800_000 + 3, (-1) ** h * 0.0003, None) for h in range(3)] for s in symbols}
    funding = {s: ex.FundingSeries.from_events(funding_events[s]) for s in symbols}
    geometry = ex.Geometry(stop_timeframe='15m', stop_atr_multiplier=1.0, target_risk_multiple=2.0)
    summary, mine = ex.run_hypothesis({s: rows[s] for s in symbols}, candles, funding, ex.POLICIES['S_sections_only'],
                                      geometry, instruments, BASELINE, (START, end))
    canonical = _canonical_trades(symbols, candles, rows, funding_events, geometry, instruments, START, end)
    ours = {(o.plan.symbol, o.plan.decision_ms): o for o in mine if o.exit_ms <= end}
    assert summary['trades'] >= 10 and expected(summary)
    assert set(ours) == set(canonical)
    for key, trade in canonical.items():
        assert trade['exit_reason'] == ours[key].reason
        assert float(trade['exit_price']) == ours[key].exit_price
        assert float(trade['net_pnl_quote']) == pytest.approx(ours[key].net, abs=1e-9)
        assert float(trade['funding_quote']) == pytest.approx(ours[key].funding, abs=1e-12)


def _canonical_trades(symbols, candles, rows, funding_events, geometry, instruments, start, end):
    identity = {k: ('sha256:' + str(i + 1) * 64 if k != 'variant_id' else 'parity') for i, k in enumerate(IDENTITY_FIELDS)}
    sources = {s: {'dataset_id': f'ds-{s}', 'dataset_sha256': '7' * 64, 'signal_run_id': f'run-{s}',
                   'signal_output_sha256': '9' * 64} for s in symbols}
    kernel_instruments = {s: InstrumentAssumptions(
        D('0.01'), D('0.001'), D('0.001'), D('1000.0'), D('5.0'), D('1.0'), D('2.0'), D('0.01'), D('0.005'),
        '2026-10-09T07:12:34Z', 'c' * 64) for s in symbols}
    costs = CostAssumptions(D('.0002'), D('.0005'), D('.0005'), D('0'), D('0.0002'), D('0'), D('0'), D('0.0005'),
                            D('0'), D('0.0003'), 28800, 1)
    counts = tuple((s, sum(1 for e in funding_events[s] if start <= e[0] < end)) for s in symbols)
    assumptions = RunAssumptions(start, end, 'training', symbols, tuple(kernel_instruments.items()), costs,
                                 tuple(identity.items()), tuple((s, tuple(sources[s].items())) for s in symbols),
                                 FundingCoverage('observed_only', 'd' * 64, counts),
                                 'adverse_possible_credit_certain.v1', 'last_known_close.v1')
    by_key = {(s, r['ms']): r for s in symbols for r in rows[s]}
    trades = []

    def builder(signal, view):
        payload = signal.payload
        symbol, decision = payload['symbol'], payload['evaluated_ms']
        result, reason = ex.build_plan(by_key[(symbol, decision)], symbol, geometry, instruments[symbol], BASELINE)
        if result is None:
            return {'schema_version': 'research-plan-rejection.v1', 'reason_code': reason}
        entry, stop, target, quantity = (D(repr(v)) for v in (result.entry, result.stop, result.target, result.quantity))
        parts = {'gross_stop_loss': (entry - stop) * quantity, 'entry_fee': entry * quantity * costs.entry_fee_rate,
                 'stop_exit_fee': stop * quantity * costs.stop_fee_rate, 'entry_spread': D(0),
                 'stop_spread': stop * quantity * costs.stop_spread_rate, 'entry_slippage': D(0),
                 'stop_slippage': stop * quantity * costs.stop_slippage_rate,
                 'funding_provision': entry * quantity * costs.funding_provision_rate}
        risk = sum(parts.values(), D(0))
        parts['total_stop_loss'] = risk
        reward = ((target - entry) * quantity - parts['entry_fee'] - parts['funding_provision']
                  - target * quantity * costs.target_fee_rate)
        document = {'schema_version': 'research-plan.v1', 'research_only': True, 'execution_authority': 'none',
                    'symbol': symbol, 'signal_result_hash': payload['result_hash'], 'signal_index': signal.index,
                    'evaluated_ms': decision, 'portfolio_hash': view['portfolio_hash'], **identity, **sources[symbol],
                    'source_venue': 'binance_usdm', 'source_network': 'mainnet', 'market_type': 'perpetual',
                    'instrument_math': kernel_instruments[symbol].wire(), 'cost_model': costs.wire(),
                    'entry_price': float(entry), 'stop_price': float(stop), 'quantity': float(quantity),
                    'final_leverage': 1, 'effective_leverage_cap': 2.0,
                    'position_notional_quote': float(entry * quantity),
                    'targets': [{'price': float(target),
                                 'net_r': wire_number((reward / risk).quantize(D('1e-18'), rounding=ROUND_DOWN)),
                                 'net_reward_quote': wire_number(reward), 'net_risk_quote': wire_number(risk)}],
                    'entry_ttl_seconds': 90, 'cancel_after_seconds': 120, 'holding_deadline_exclusive': True,
                    'holding_deadline_ms': result.deadline_ms,
                    'risk_components_quote': {k: wire_number(v) for k, v in parts.items()},
                    'risk_budget_quote': wire_number(number(view['equity_quote']) * D('.05'))}
        document['plan_hash'] = canonical_hash(document)
        return document

    simulator = PortfolioSimulator(assumptions, builder, event_sink=lambda e: None, trade_sink=trades.append,
                                   cashflow_sink=lambda c: None, rejection_sink=lambda r: None)
    counter = iter(range(10 ** 6))

    def signals(at):
        out = []
        for symbol in symbols:
            if (symbol, at) in by_key:
                payload = {'schema_version': 'research-signal-result.v1', 'passed': True, 'evaluated_ms': at,
                           'symbol': symbol, 'source': {'dataset_id': sources[symbol]['dataset_id'],
                           'dataset_sha256': sources[symbol]['dataset_sha256'], 'source_venue': 'binance_usdm',
                           'source_network': 'mainnet', 'market_type': 'perpetual'},
                           'baseline': {'setup_hash': identity['base_setup_hash'], 'config_hash': identity['base_config_hash'],
                                        'condition_catalog_hash': identity['base_catalog_hash'],
                                        'snapshot_hash': identity['base_snapshot_hash']}}
                payload['result_hash'] = canonical_hash(payload)
                out.append(Signal(next(counter), payload))
        return out

    def batch(open_ms):
        return [Candle(s, open_ms, *(D(repr(values[candles[s].index(open_ms)])) for values in
                                     (candles[s].open, candles[s].high, candles[s].low, candles[s].close)))
                for s in symbols]

    funding_by_batch = {}
    for symbol in symbols:
        for timestamp, rate, _ in funding_events[symbol]:
            opening = start + (-((start - timestamp) // ex.MINUTE) - 1) * ex.MINUTE
            funding_by_batch.setdefault(opening, []).append(FundingEvent(symbol, timestamp, D(repr(rate))))
    simulator.prime(batch(start - ex.MINUTE), signals=signals(start))
    for opening in range(start, end, ex.MINUTE):
        simulator.advance(batch(opening), funding=funding_by_batch.get(opening, ()),
                          signals=signals(opening + ex.MINUTE) if opening + ex.MINUTE < end else ())
    simulator.finish()
    return {(t['symbol'], t['decision_ms']): t for t in trades}


def test_unrealized_loss_blocks_admission_like_the_canonical_kernel():
    symbols = ('BTCUSDT', 'ETHUSDT')
    end = START + ex.DAY
    first, second = START + 900_000, START + 1_800_000
    btc = [100.5] * 16 + [100.0] + [100.0 - 0.2 * i for i in range(1, 16)] + [97.0] * (1441 - 32)
    eth = [100.5] * 31 + [100.0] * (1441 - 31)
    candles = {'BTCUSDT': series('BTCUSDT', START - ex.MINUTE, btc), 'ETHUSDT': series('ETHUSDT', START - ex.MINUTE, eth)}
    instruments = {s: {**BTC, 'symbol': s, 'tick_size': 0.01, 'min_notional': 5.0} for s in symbols}
    rows = {s: [] for s in ex.SYMBOLS}
    for symbol, decision in (('BTCUSDT', first), ('ETHUSDT', second)):
        signal = php_row('5m', 'vwap', 100.5, '5m', 0.5, '15m', 3.62, 100.5)
        signal['ms'] = decision
        rows[symbol].append(signal)
    geometry = ex.Geometry(stop_timeframe='15m')
    summary, mine = ex.run_hypothesis({s: rows[s] for s in symbols}, candles, None, ex.POLICIES['S_sections_only'],
                                      geometry, instruments, BASELINE, (START, end))
    canonical = _canonical_trades(symbols, candles, rows, {s: [] for s in symbols}, geometry, instruments, START, end)
    assert summary['rejections'] == {'research_portfolio_daily_loss_exceeded': 1}
    assert set(canonical) == {(o.plan.symbol, o.plan.decision_ms) for o in mine} == {('BTCUSDT', first)}
    assert canonical[('BTCUSDT', first)]['exit_reason'] == mine[0].reason == 'holding_deadline'
