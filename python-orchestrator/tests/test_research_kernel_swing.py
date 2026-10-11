"""PortfolioSimulator under the swing kernel profile: shorts, multi-day plans, one plan per pair."""
from dataclasses import replace
from decimal import Decimal as D, ROUND_DOWN

import pytest

from app.backtesting.research.portfolio_simulator import (
    DAY, DAY_TRADING_PROFILE, SWING_PROFILE, Candle, CostAssumptions, FundingCoverage, FundingEvent, KernelProfile,
    PortfolioSimulator, RunAssumptions, SYMBOLS, canonical_hash, daily_capacity)
from tests.test_research_portfolio_simulator import IDENTITY, SOURCE, START, bars, instrument, signal

HOUR = 3_600_000


def below(t):
    """A minute that never reaches a sell limit at 100."""
    return bars(t, low='98', high='99', opening='98.5', close='98.5')


def swing_costs(**changes):
    return replace(CostAssumptions(D('.0002'), D('.0005'), D('.0002'),
        D('0'), D('0'), D('0'), D('0'), D('0'), D('0'), D('.0001'), 28800, 42), **changes)


def swing_assumptions(minutes=10, symbols=SYMBOLS, start=START, profile=SWING_PROFILE, costs=None, **changes):
    return replace(RunAssumptions(start, start + minutes * 60000, 'training', symbols,
        tuple((s, instrument()) for s in symbols), costs or swing_costs(), tuple(IDENTITY.items()),
        tuple((s, tuple(SOURCE.items())) for s in symbols),
        FundingCoverage('verified_complete', 'sha256:' + 'a' * 64, tuple((s, 0) for s in symbols)),
        'adverse_possible_credit_certain.v1', 'last_known_close.v1', kernel_profile=profile), **changes)


def swing_plan(sig, view, side='short', *, entry=100, stop=105, target=80, quantity=2, leverage=1,
               deadline=None, **changes):
    q, e, st, tar = D(str(quantity)), D(str(entry)), D(str(stop)), D(str(target))
    cost = swing_costs()
    parts = {'gross_stop_loss': abs(e - st) * q, 'entry_fee': e * q * cost.entry_fee_rate,
             'stop_exit_fee': st * q * cost.stop_fee_rate, 'entry_spread': D('0'), 'stop_spread': D('0'),
             'entry_slippage': D('0'), 'stop_slippage': D('0'),
             'funding_provision': e * q * cost.funding_provision_rate * 42}
    parts['total_stop_loss'] = sum(parts.values())
    reward = abs(tar - e) * q - parts['entry_fee'] - tar * q * cost.target_fee_rate - parts['funding_provision']
    evaluated = sig.payload['evaluated_ms']
    p = {'schema_version': 'research-plan.v1', 'research_only': True, 'execution_authority': 'none',
         **IDENTITY, **SOURCE, 'source_venue': 'binance_usdm', 'source_network': 'mainnet',
         'market_type': 'perpetual', 'signal_result_hash': sig.payload['result_hash'],
         'signal_index': sig.index, 'symbol': sig.payload['symbol'], 'evaluated_ms': evaluated,
         'portfolio_hash': view['portfolio_hash'], 'side': side,
         'entry_price': float(e), 'stop_price': float(st), 'quantity': float(q),
         'position_notional_quote': float(e * q), 'final_leverage': leverage, 'effective_leverage_cap': 2,
         'risk_budget_quote': float(D(str(view['equity_quote'])) * D('.05')),
         'risk_components_quote': {k: float(v) for k, v in parts.items()},
         'targets': [{'id': 't1', 'price': float(tar),
                      'net_r': float((reward / parts['total_stop_loss']).quantize(D('1e-18'), rounding=ROUND_DOWN)),
                      'net_reward_quote': float(reward), 'net_risk_quote': float(parts['total_stop_loss'])}],
         'entry_ttl_seconds': 3600, 'cancel_after_seconds': 3660,
         'holding_deadline_ms': evaluated + 14 * DAY if deadline is None else deadline,
         'holding_deadline_exclusive': True, 'instrument_math': instrument().wire(), 'cost_model': cost.wire()}
    p.update(changes)
    p['plan_hash'] = canonical_hash({k: v for k, v in p.items() if k != 'plan_hash'})
    return p


def simulator(config=None, builder=None):
    ledger = {key: [] for key in ('events', 'trades', 'cashflows', 'rejections')}
    sim = PortfolioSimulator(config or swing_assumptions(), builder or (lambda s, v: swing_plan(s, v)),
        event_sink=ledger['events'].append, trade_sink=ledger['trades'].append,
        cashflow_sink=ledger['cashflows'].append, rejection_sink=ledger['rejections'].append)
    sim.prime(bars(sim.assumptions.start_ms - 60000, symbols=sim.assumptions.symbols))
    return sim, ledger


# ------------------------------------------------------------------------- profiles
def test_profiles_and_their_validation():
    assert DAY_TRADING_PROFILE == KernelProfile() and not DAY_TRADING_PROFILE.extended and SWING_PROFILE.extended
    assert SWING_PROFILE.wire() == {
        'profile_id': 'swing.v1', 'sides': ['long', 'short'], 'entry_ttl_seconds': 3600, 'cancel_after_seconds': 3660,
        'holding_window_seconds': 1209600, 'daily_boundary': False, 'max_active': 10, 'one_active_per_symbol': True,
        'daily_loss_rate': 1.0, 'daily_loss_absolute_quote': 100000.0}
    for bad in ({'sides': ()}, {'sides': ('long', 'long')}, {'sides': ('flat',)}, {'sides': ['long']},
                {'entry_ttl_seconds': 0}, {'cancel_after_seconds': 121}, {'holding_window_seconds': 60},
                {'max_active': 11}, {'daily_boundary': 1}, {'one_active_per_symbol': None},
                {'daily_loss_rate': D('0')}, {'daily_loss_rate': 0.06}, {'daily_loss_absolute_quote': D('Infinity')},
                {'profile_id': 'bad id'}):
        with pytest.raises(ValueError, match='research_kernel_profile_invalid'):
            replace(DAY_TRADING_PROFILE, **bad)
    assert daily_capacity(D('100000'), D('-25'), D('-5'), D('20'), D('1'), D('100000')) == D('99950')


def test_costs_and_run_assumptions_bind_the_profile_horizon():
    assert swing_costs().funding_intervals_provisioned == 42
    with pytest.raises(ValueError, match='research_baseline_cost_mismatch'):
        swing_costs(funding_intervals_provisioned=21)
    with pytest.raises(ValueError, match='research_baseline_cost_mismatch'):
        swing_assumptions(costs=swing_costs(funding_intervals_provisioned=1))
    with pytest.raises(ValueError, match='research_baseline_cost_mismatch'):
        swing_assumptions(profile=DAY_TRADING_PROFILE)
    with pytest.raises(ValueError, match='research_baseline_cost_mismatch'):
        swing_assumptions(profile='swing.v1')


# --------------------------------------------------------------------------- shorts
def test_short_fills_on_the_high_and_exits_at_the_target_low():
    sim, ledger = simulator()
    sim.advance(bars(START), signals=(signal(START + 60000),))
    assert sim.view()['active_symbols'] == ['BTCUSDT']
    sim.advance(bars(START + 60000, low='99', high='100.5', close='99.5'))
    assert sim.view()['open_positions'] == 1
    sim.advance(bars(START + 120000, low='79', high='99', opening='98', close='85'))
    trade = ledger['trades'][0]
    assert (trade['side'], trade['exit_reason'], trade['exit_price']) == ('short', 'target', D('80'))
    assert trade['gross_pnl_quote'] == D('40')
    assert trade['net_pnl_quote'] == D('40') - D('200') * D('.0002') - D('160') * D('.0002')
    assert sim.view()['active_symbols'] == []


def test_short_stop_takes_the_worse_of_open_and_stop():
    sim, ledger = simulator()
    sim.advance(bars(START), signals=(signal(START + 60000),))
    sim.advance(bars(START + 60000, low='99.5', high='100', close='100'))
    sim.advance(bars(START + 120000, low='101', high='112', opening='107', close='110'))
    trade = ledger['trades'][0]
    assert (trade['exit_reason'], trade['exit_price'], trade['gross_pnl_quote']) == ('stop', D('107'), D('-14'))
    assert not trade['ambiguous']


def test_short_fill_bar_rules_mirror_the_long_ones():
    sim, ledger = simulator()
    sim.advance(bars(START), signals=(signal(START + 60000),))
    sim.advance(bars(START + 60000, low='95', high='106', opening='100', close='100'))
    trade = ledger['trades'][0]
    assert (trade['exit_reason'], trade['exit_price'], trade['ambiguous']) == ('stop', D('105'), True)
    sim, ledger = simulator()
    sim.advance(bars(START), signals=(signal(START + 60000),))
    sim.advance(bars(START + 60000, low='79', high='101'))
    assert not ledger['trades'] and sim.ambiguity_counts['fill_bar_target'] == 1


@pytest.mark.parametrize('high,opening,reason,price', [
    ('120', '101', 'stop', '105'),
    ('170', '101', 'liquidation_proxy', None),  # both reached in one bar: the costlier exit is kept
    ('170', '160', 'liquidation_proxy', '160'),  # a gap above both
])
def test_short_liquidation_proxy_mirrors_the_long_rule(high, opening, reason, price):
    liquidation = D('100') * (1 + D('1') / 2) / (1 + D('.005'))
    sim, ledger = simulator(builder=lambda s, v: swing_plan(s, v, leverage=2))
    sim.advance(bars(START), signals=(signal(START + 60000),))
    sim.advance(bars(START + 60000, low='99.5', high='100', close='100'))
    sim.advance(bars(START + 120000, low='100', high=high, opening=opening, close='110'))
    trade = ledger['trades'][0]
    assert (trade['exit_reason'], trade['exit_price']) == (reason, D(price) if price else liquidation)


@pytest.mark.parametrize('rate,amount', [(D('.0001'), D('.02')), (D('-.0001'), D('-.02'))])
def test_short_receives_positive_funding_and_pays_negative_funding(rate, amount):
    sim, ledger = simulator(swing_assumptions(
        funding=FundingCoverage('verified_complete', 'sha256:' + 'a' * 64, tuple((s, 0) for s in SYMBOLS))))
    sim.advance(bars(START), signals=(signal(START + 60000),))
    sim.advance(bars(START + 60000, low='99.5', high='100', close='100'))
    event = FundingEvent('BTCUSDT', START + 180000, rate, D('100'), START + 180000)
    sim.advance(bars(START + 120000), funding=(event,))
    assert sim.positions[next(iter(sim.positions))].funding == amount


@pytest.mark.parametrize('rate,booked', [(D('.0004'), False), (D('-.0004'), True)])
def test_short_never_books_an_uncertain_credit(rate, booked):
    sim, ledger = simulator()
    sim.advance(bars(START), signals=(signal(START + 60000),))
    event = FundingEvent('BTCUSDT', START + 90000, rate, D('100'), START + 90000)
    sim.advance(bars(START + 60000, low='99.5', high='100', close='100'), funding=(event,))
    funding = [row for row in ledger['cashflows'] if row['kind'] == 'funding']
    assert funding[0]['ambiguous'] and (funding[0]['amount_quote'] != 0) == booked
    assert sim.ambiguity_counts['funding_path'] == 1


# ------------------------------------------------------------------------ portfolio
def test_one_plan_per_pair_and_ten_slots():
    sim, ledger = simulator(builder=lambda s, v: swing_plan(s, v, side='long' if s.index % 2 else 'short',
                                                            stop=95 if s.index % 2 else 105,
                                                            target=120 if s.index % 2 else 80))
    sim.advance(bars(START), signals=tuple(signal(START + 60000, s, i) for i, s in enumerate(SYMBOLS)))
    assert sim.view()['pending_entries'] == 10 and not ledger['rejections']
    assert sim.view()['active_symbols'] == sorted(SYMBOLS)
    sim.advance(bars(START + 60000), signals=(signal(START + 120000, 'ETHUSDT', 11),))
    assert ledger['rejections'][-1]['reason_code'] == 'research_portfolio_symbol_active'
    eleven = replace(SWING_PROFILE, one_active_per_symbol=False, max_active=10)
    sim, ledger = simulator(swing_assumptions(profile=eleven))
    sim.advance(bars(START), signals=tuple(signal(START + 60000, s, i) for i, s in enumerate(SYMBOLS)))
    sim.advance(below(START + 60000), signals=(signal(START + 120000, 'ETHUSDT', 11),))
    assert ledger['rejections'][-1]['reason_code'] == 'research_portfolio_concurrency_exceeded'


def test_unfilled_short_releases_its_pair_after_the_entry_ttl():
    sim, ledger = simulator(swing_assumptions(minutes=64))
    sim.advance(below(START), signals=(signal(START + 60000),))
    for minute in range(1, 61):
        sim.advance(below(START + minute * 60000))
    assert sim.view()['pending_entries'] == 1  # boundary START+61 min == decision + 3600 s
    sim.advance(below(START + 61 * 60000))
    assert sim.view()['pending_entries'] == 0 and sim.view()['active_symbols'] == []
    assert ledger['events'][-1]['reason'] == 'entry_expired'


def test_multi_day_deadline_exits_at_the_prior_close_without_a_midnight_label():
    start = START + DAY - 120000
    deadline = start + 60000 + 2 * DAY
    sim, ledger = simulator(swing_assumptions(minutes=2 * 1440 + 3, start=start),
                            builder=lambda s, v: swing_plan(s, v, deadline=deadline))
    sim.advance(bars(start), signals=(signal(start + 60000),))
    for minute in range(1, 2 * 1440 + 3):
        sim.advance(bars(start + minute * 60000, close='99' if minute == 2 * 1440 - 1 else '100'))
    trade = ledger['trades'][0]
    assert (trade['exit_reason'], trade['exit_boundary_ms'], trade['exit_price']) == ('holding_deadline', deadline, D('99'))


@pytest.mark.parametrize('changes,reason', [
    ({'side': None}, 'research_plan_side_invalid'),
    ({'side': 'flat'}, 'research_plan_side_invalid'),
    ({'entry_ttl_seconds': 90}, 'research_plan_lifetime_invalid'),
    ({'cancel_after_seconds': 3600}, 'research_plan_lifetime_invalid'),
    ({'holding_deadline_ms': START + 60000 + 14 * DAY + 60000}, 'research_plan_lifetime_invalid'),
    ({'stop_price': 99.0}, 'research_plan_geometry_invalid'),
    ({'targets': [{'id': 't1', 'price': 101.0, 'net_r': 2.0, 'net_reward_quote': 1.0, 'net_risk_quote': 1.0}]},
     'research_plan_geometry_invalid'),
])
def test_kernel_rejects_short_plans_breaking_the_profile(changes, reason):
    sim, ledger = simulator(builder=lambda s, v: swing_plan(s, v, **changes))
    sim.advance(bars(START), signals=(signal(START + 60000),))
    assert ledger['rejections'][0]['reason_code'] == reason


def test_day_trading_plans_must_not_carry_a_side():
    from tests.test_research_portfolio_simulator import plan, simulator as day_simulator

    def builder(s, v):
        p = plan(s, v)
        p['side'] = 'long'
        p['plan_hash'] = canonical_hash({k: x for k, x in p.items() if k != 'plan_hash'})
        return p
    sim, ledger = day_simulator(builder=builder)
    sim.advance(bars(START), signals=(signal(START + 60000),))
    assert ledger['rejections'][0]['reason_code'] == 'research_plan_side_invalid'
    assert 'active_symbols' not in sim.view()


def test_summary_records_the_extended_profile():
    sim, ledger = simulator(swing_assumptions(minutes=2))
    sim.advance(bars(START), signals=(signal(START + 60000),))
    sim.advance(bars(START + 60000, low='99.5', high='100', close='100'))
    summary = sim.finish()
    assert summary['kernel_profile'] == SWING_PROFILE.wire()
    assert ledger['trades'][0]['exit_reason'] == 'research_end' and ledger['trades'][0]['side'] == 'short'


# ------------------------------------------------------------------------ reflection
def test_a_short_equals_the_long_on_the_reflected_market():
    """Kernel kinematics are symmetric: reflect prices around a pivot and negate funding."""
    pivot = D('240')
    path = [('100', '101', '99', '100.5'), ('100.5', '100.6', '99.4', '99.6'), ('99.6', '103', '99', '102.5'),
            ('102.5', '106', '101', '104'), ('104', '104.5', '96', '97'), ('97', '98', '79', '80')]

    def run(side, reflected):
        def price(text):
            return pivot - D(text) if reflected else D(text)
        e, st, tar = ('100', '105', '80')
        plan_side = side
        builder = (lambda s, v: swing_plan(s, v, side=plan_side, entry=float(price(e)), stop=float(price(st)),
                                           target=float(price(tar)), quantity=1))
        sim, ledger = simulator(swing_assumptions(minutes=len(path) + 1), builder=builder)
        sim.advance(bars(START), signals=(signal(START + 60000),))
        for minute, (o, h, lo, c) in enumerate(path, start=1):
            high, low = (price(lo), price(h)) if reflected else (price(h), price(lo))
            rate = D('.0003') * (-1 if reflected else 1)
            events = (FundingEvent('BTCUSDT', START + minute * 60000 + 30000, rate, None, None),) if minute == 3 else ()
            sim.advance(tuple(Candle(s, START + minute * 60000, price(o), high, low, price(c)) for s in SYMBOLS),
                        funding=events)
        return ledger['trades'], [row['timestamp_ms'] for row in ledger['cashflows'] if row['kind'] == 'funding']

    short_trades, short_funding = run('short', False)
    long_trades, long_funding = run('long', True)
    assert len(short_trades) == len(long_trades) == 1 and short_funding == long_funding
    keys = ('fill_boundary_ms', 'exit_boundary_ms', 'exit_reason', 'ambiguous')
    assert {k: short_trades[0][k] for k in keys} == {k: long_trades[0][k] for k in keys}
    assert pivot - short_trades[0]['exit_price'] == long_trades[0]['exit_price']
    assert short_trades[0]['exit_reason'] == 'stop'


# ------------------------------------------------------------- evidence and statistics
def swing_ledgers(root):
    """Run a swing scenario through the kernel into real evidence ledgers."""
    from app.backtesting.research import campaign_evidence as evidence_module

    start = START + DAY - 30 * 60000  # positions cross the UTC midnight
    minutes = 120
    events = {s: 1 if s in SYMBOLS[:3] else 0 for s in SYMBOLS}
    config = swing_assumptions(minutes=minutes, start=start, funding=FundingCoverage(
        'verified_complete', 'sha256:' + 'a' * 64, tuple(events.items())))
    with evidence_module.Evidence(root, (), max_bytes=10 ** 8, min_free_bytes=0) as evidence:
        def build(sig, view):
            wide = sig.index >= 4
            side = 'long' if sig.index % 2 else 'short'
            stop = (90 if wide else 95) if side == 'long' else (110 if wide else 105)
            plan = swing_plan(sig, view, side=side, stop=stop, target=120 if side == 'long' else 80)
            evidence.emit('plans', plan)
            return plan
        sim = PortfolioSimulator(config, build, event_sink=lambda row: evidence.emit('events', row),
                                 trade_sink=lambda row: evidence.emit('trades', row),
                                 cashflow_sink=lambda row: evidence.emit('cashflows', row),
                                 rejection_sink=lambda row: evidence.emit('rejections', row),
                                 marked_sink=lambda row: evidence.emit('marked-equity', row))
        sim.prime(bars(start - 60000))
        sim.advance(bars(start), signals=tuple(signal(start + 60000, s, i) for i, s in enumerate(SYMBOLS[:6])))
        for minute in range(1, minutes):
            at = start + minute * 60000
            funding = tuple(FundingEvent(s, START + DAY, D('.0002'), None, None)
                            for s in SYMBOLS[:3]) if at + 60000 == START + DAY else ()
            candles = bars(at, low='94', high='106', close='100') if minute == 60 else bars(at, close='100.5')
            sim.advance(candles, funding=funding)
        summary = sim.finish()
        files = evidence.finish_ledgers()
    return summary, files, start, start + minutes * 60000


def test_independent_statistics_replay_swing_ledgers(tmp_path):
    from app.backtesting.research import campaign_evidence as evidence_module
    from app.backtesting.research.statistics import StatisticsError, analyze_ledgers

    root = tmp_path / 'unit'
    summary, files, start, end = swing_ledgers(root)
    assert summary['trades'] == 6 and summary['kernel_profile'] == SWING_PROFILE.wire()
    assert evidence_module.reconcile(root, summary, max_active=10)['status'] == 'verified'
    result = analyze_ledgers(root, files, symbols=SYMBOLS, start_ms=start, end_ms=end, require_marked=True,
                             kernel_profile=SWING_PROFILE)
    assert result['closed_trades'] == 6 and D(result['net_pnl_quote']) == summary['net_pnl_quote']
    assert D(result['wallet_quote']) == summary['wallet_quote']
    assert D(result['maximum_drawdown_quote']) == summary['maximum_drawdown_quote'] > 0
    assert result['exit_reason_counts'] == {'stop': 4, 'research_end': 2}
    assert D(result['funding_quote']) != 0 and result['kernel_profile'] == SWING_PROFILE.wire()
    with pytest.raises(evidence_module.EvidenceError):
        evidence_module.reconcile(root, summary)  # six positions exceed day_trading's four
    with pytest.raises(StatisticsError):
        analyze_ledgers(root, files, symbols=SYMBOLS, start_ms=start, end_ms=end, require_marked=True)
    with pytest.raises(StatisticsError, match='kernel_profile_invalid'):
        analyze_ledgers(root, files, symbols=SYMBOLS, start_ms=start, end_ms=end, kernel_profile='swing.v1')


def test_profile_wire_round_trip_and_rejections():
    assert KernelProfile.from_wire(None) is DAY_TRADING_PROFILE
    assert KernelProfile.from_wire(SWING_PROFILE.wire()) == SWING_PROFILE
    assert KernelProfile.from_wire(SWING_PROFILE.wire()).extended
    for broken in ({**SWING_PROFILE.wire(), 'max_active': 11}, {'profile_id': 'swing.v1'},
                   {**SWING_PROFILE.wire(), 'sides': 'long'}, {**SWING_PROFILE.wire(), 'extra': 1},
                   {**SWING_PROFILE.wire(), 'daily_loss_rate': '1'}):
        with pytest.raises(ValueError, match='research_kernel_profile_invalid'):
            KernelProfile.from_wire(broken)


def test_statistics_reject_a_trade_whose_side_differs_from_its_plan(tmp_path):
    import hashlib
    import json

    from app.backtesting.research.statistics import StatisticsError, analyze_ledgers

    root = tmp_path / 'unit'
    _, files, start, end = swing_ledgers(root)
    path = root / 'trades.ndjson'
    rows = [json.loads(line) for line in path.read_text().splitlines()]
    rows[0]['side'] = 'long' if rows[0]['side'] == 'short' else 'short'
    raw = ''.join(json.dumps(row, sort_keys=True, separators=(',', ':')) + '\n' for row in rows).encode()
    path.chmod(0o600)
    path.write_bytes(raw)
    files['trades.ndjson'] = {'sha256': hashlib.sha256(raw).hexdigest(), 'count': len(rows), 'bytes': len(raw)}
    with pytest.raises(StatisticsError, match='trade_side_conflict'):
        analyze_ledgers(root, files, symbols=SYMBOLS, start_ms=start, end_ms=end, require_marked=True,
                        kernel_profile=SWING_PROFILE)
