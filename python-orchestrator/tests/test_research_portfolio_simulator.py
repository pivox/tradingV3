"""Synthetic research-only plans; these fixtures grant no canonical authority."""
from dataclasses import replace
from decimal import Decimal as D, ROUND_DOWN
import copy
import subprocess
import json
from pathlib import Path

import pytest

from app.backtesting.research.portfolio_simulator import (
    Candle, CostAssumptions, FundingCoverage, FundingEvent, InstrumentAssumptions,
    PortfolioSimulator, RunAssumptions, Signal, SYMBOLS, canonical_hash,
    daily_capacity, merge_candles, DAY,
)

START = 1672531200000  # 2023-01-01 UTC; no holdout prices
HASH = 'sha256:' + 'a' * 64
IDENTITY = {key: HASH for key in (
    'base_setup_hash', 'base_config_hash', 'base_catalog_hash', 'base_snapshot_hash',
    'variant_hash', 'instrument_assumptions_hash', 'cost_assumptions_hash', 'research_code_hash')}
IDENTITY['variant_id'] = 'synthetic-test'
SOURCE = {'dataset_id': 'synthetic', 'dataset_sha256': 'b' * 64,
          'signal_run_id': 'synthetic', 'signal_output_sha256': 'c' * 64}


def instrument(**changes):
    return replace(InstrumentAssumptions(D('.01'), D('.001'), D('.001'), D('100000'),
        D('5'), D('1'), D('2'), D('.005'), D('.001'), '2026-10-09T00:00:00Z', 'd' * 64), **changes)


def costs(**changes):
    return replace(CostAssumptions(D('.0002'), D('.0005'), D('.0002'),
        D('0'), D('0'), D('0'), D('0'), D('0'), D('0'), D('.0001'), 28800, 1), **changes)


def assumptions(minutes=10, symbols=SYMBOLS, funding=None, start=START, **changes):
    return replace(RunAssumptions(start, start + minutes * 60000, 'training', symbols,
        tuple((s, instrument()) for s in symbols), costs(), tuple(IDENTITY.items()),
        tuple((s, tuple(SOURCE.items())) for s in symbols),
        funding or FundingCoverage('verified_complete', HASH, tuple((s, 0) for s in symbols)),
        'adverse_possible_credit_certain.v1', 'last_known_close.v1'), **changes)


def bars(t, *, low='99', high='101', close='100', opening='100', symbols=SYMBOLS):
    return tuple(Candle(s, t, D(opening), D(high), D(low), D(close)) for s in symbols)


def signal(t, symbol='BTCUSDT', index=0):
    payload = {'schema_version': 'research-signal-result.v1', 'symbol': symbol,
               'evaluated_ms': t, 'passed': True, 'source': {
                   'dataset_id': SOURCE['dataset_id'], 'dataset_sha256': SOURCE['dataset_sha256'],
                   'source_venue': 'binance_usdm', 'source_network': 'mainnet', 'market_type': 'perpetual'}}
    payload['result_hash'] = canonical_hash(payload)
    return Signal(index, payload)


def plan(sig, view, *, quantity=2, leverage=1, stop=95, target=110):
    q, entry, st, tar = D(str(quantity)), D('100'), D(str(stop)), D(str(target))
    cost = costs()
    parts = {'gross_stop_loss': (entry-st)*q, 'entry_fee': entry*q*cost.entry_fee_rate,
        'stop_exit_fee': st*q*cost.stop_fee_rate, 'entry_spread': D('0'), 'stop_spread': D('0'),
        'entry_slippage': D('0'), 'stop_slippage': D('0'),
        'funding_provision': entry*q*cost.funding_provision_rate}
    parts['total_stop_loss'] = sum(parts.values())
    reward = (tar-entry)*q - parts['entry_fee'] - tar*q*cost.target_fee_rate - parts['funding_provision']
    p = {'schema_version': 'research-plan.v1', 'research_only': True, 'execution_authority': 'none',
         **IDENTITY, **SOURCE, 'source_venue': 'binance_usdm', 'source_network': 'mainnet',
         'market_type': 'perpetual', 'signal_result_hash': sig.payload['result_hash'],
         'signal_index': sig.index, 'symbol': sig.payload['symbol'],
         'evaluated_ms': sig.payload['evaluated_ms'], 'portfolio_hash': view['portfolio_hash'],
         'entry_price': 100, 'stop_price': float(st), 'quantity': float(q),
         'position_notional_quote': float(entry*q), 'final_leverage': leverage,
         'effective_leverage_cap': 2, 'risk_budget_quote': float(D(str(view['equity_quote']))*D('.05')),
         'risk_components_quote': {k: float(v) for k,v in parts.items()},
         'targets': [{'id': 't1', 'price': float(tar), 'net_r': float((reward/parts['total_stop_loss']).quantize(D('1e-18'),rounding=ROUND_DOWN)),
                      'net_reward_quote': float(reward), 'net_risk_quote': float(parts['total_stop_loss'])}],
         'entry_ttl_seconds': 90, 'cancel_after_seconds': 120,
         'holding_deadline_ms': sig.payload['evaluated_ms'] + 28800000,
         'holding_deadline_exclusive': True, 'instrument_math': instrument().wire(),
         'cost_model': cost.wire()}
    p['plan_hash'] = canonical_hash(p)
    return p


def simulator(config=None, builder=None, *, prime=True):
    ledger = {key: [] for key in ('events', 'trades', 'cashflows', 'rejections')}
    sim = PortfolioSimulator(config or assumptions(), builder or (lambda s,v: plan(s,v)),
        event_sink=ledger['events'].append, trade_sink=ledger['trades'].append,
        cashflow_sink=ledger['cashflows'].append, rejection_sink=ledger['rejections'].append)
    if prime:
        sim.prime(bars(sim.assumptions.start_ms-60000,symbols=sim.assumptions.symbols))
    return sim, ledger


def test_initial_wallet_full_batch_and_no_signal_bar_fill():
    sim, ledger = simulator()
    sim.advance(bars(START, low='90', high='120'), signals=(signal(START+60000),))
    assert sim.wallet == D('100000')
    assert sim.view()['pending_entries'] == 1
    assert sim.view()['open_positions'] == 0
    sim.advance(bars(START+60000))
    assert sim.view()['open_positions'] == 1
    assert sim.wallet == D('99999.9600')
    assert D(str(sim.view()['reserved_risk_quote'])) == D('10.155')
    assert not ledger['trades']


def test_ttl_inside_second_bar_is_ambiguous_and_never_extends_to_120():
    sim, ledger = simulator()
    sim.advance(bars(START), signals=(signal(START+60000),))
    sim.advance(bars(START+60000, low='101', high='103', opening='102', close='102'))
    sim.advance(bars(START+120000, low='99', high='103', opening='102', close='102'))
    assert sim.view()['reserved_risk_quote'] == 0
    assert sim.view()['open_positions'] == 0
    assert sim.ambiguity_counts['ttl_touch'] == 1
    assert ledger['events'][-1]['timestamp_ms'] == START+150000


@pytest.mark.parametrize('low,high,opening,reason,exit_price', [
    ('94','111','100','stop','95'), ('89','111','90','stop','90'),
    ('40','111','100','liquidation_proxy', str(D('100')*(1-D('1')/2)/(1-D('.005')))),
])
def test_fill_bar_protection_worse_outcome(low, high, opening, reason, exit_price):
    sim, ledger = simulator(builder=lambda s,v: plan(s,v,leverage=2))
    sim.advance(bars(START), signals=(signal(START+60000),))
    sim.advance(bars(START+60000, low=low, high=high, opening=opening, close='100'))
    trade = ledger['trades'][0]
    assert trade['exit_reason'] == reason
    assert trade['exit_price'] == D(exit_price)
    assert trade['net_pnl_quote'] == sum(x['amount_quote'] for x in ledger['cashflows'])
    assert sim.view()['reserved_risk_quote'] == 0


def test_target_only_fill_bar_is_uncertain_then_existing_target_fills_at_limit():
    sim, ledger = simulator()
    sim.advance(bars(START), signals=(signal(START+60000),))
    sim.advance(bars(START+60000, low='99', high='115'))
    assert not ledger['trades']
    assert sim.ambiguity_counts['fill_bar_target'] == 1
    sim.advance(bars(START+120000, low='99', high='120'))
    assert ledger['trades'][0]['exit_price'] == D('110')
    assert ledger['trades'][0]['exit_reason'] == 'target'
    assert sim.wallet == D('100019.916')  # provision is reserved, never charged


def test_four_shared_slots_and_symbol_priority():
    sim, ledger = simulator()
    signals = tuple(signal(START+60000,s) for s in reversed(SYMBOLS[:5]))
    sim.advance(bars(START), signals=signals)
    assert sim.view()['pending_entries'] == 2  # daily30 also constrains these risks
    assert len(ledger['rejections']) == 3
    assert [p['symbol'] for p in sim.pending.values()] == list(SYMBOLS[:2])


def test_php_daily_loss_golden_absolute_boundary_and_aggregate_unrealized():
    # CanonicalPortfolioAdmissionEngineTest: realized=-25,reserved=1 -> remaining4.
    assert daily_capacity(D('100000'), D('-25'), D('0'), D('1')) == D('4')
    # Its six-percent boundary fixture uses equity500 and allowance30.
    assert daily_capacity(D('500'), D('-20'), D('0'), D('0')) == D('10')
    assert daily_capacity(D('100000'), D('-3'), D('2'), D('10')) == D('17')
    assert daily_capacity(D('100'), D('2'), D('-2'), D('1')) == D('3.00')


def test_finish_requires_exact_source_end_and_reconciles_research_end():
    sim, ledger = simulator(assumptions(minutes=2))
    with pytest.raises(ValueError, match='source_incomplete'):
        sim.finish()
    sim.advance(bars(START), signals=(signal(START+60000),))
    sim.advance(bars(START+60000))
    result = sim.finish()
    assert ledger['trades'][0]['exit_reason'] == 'research_end'
    assert result['completion'] == 'complete'
    assert result['wallet_quote'] == D('99999.8600')
    with pytest.raises(ValueError, match='finished'):
        sim.advance(bars(START+120000))


def test_hash_is_php_compatible_at_numeric_edge():
    value = {'z': 1.0, 'a': [1.25, 1e-7], 's': 'é/slash'}
    php = "$v=json_decode($argv[1],true);ksort($v);echo 'sha256:'.hash('sha256',json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));"
    expected = subprocess.check_output(['php','-r',php,json.dumps(value)]).decode()
    assert canonical_hash(value) == expected


@pytest.mark.parametrize('change', ['missing','duplicate','bad_ohlc','nan','out_of_order'])
def test_invalid_batch_is_rejected_before_state_changes(change):
    sim,_ = simulator()
    batch = list(bars(START))
    if change == 'missing': batch.pop()
    if change == 'duplicate': batch[-1] = batch[0]
    if change == 'bad_ohlc': batch[0] = replace(batch[0], high=D('98'))
    if change == 'nan': batch[0] = replace(batch[0], close=D('NaN'))
    if change == 'out_of_order': batch = list(bars(START+60000))
    with pytest.raises(ValueError): sim.advance(batch)
    assert sim.wallet == D('100000')
    assert sim.processed_batches == 0


def test_merge_iterators_requires_complete_matching_clock():
    streams = {s: iter([bars(START)[i],bars(START+60000)[i]]) for i,s in enumerate(SYMBOLS)}
    assert len(list(merge_candles(streams, SYMBOLS))) == 2
    streams = {s: iter([bars(START)[i]]) for i,s in enumerate(SYMBOLS)}
    streams[SYMBOLS[-1]] = iter([])
    with pytest.raises(ValueError, match='coverage_gap'): list(merge_candles(streams,SYMBOLS))


@pytest.mark.parametrize('rate', ['.001','-.001'])
def test_funding_exact_jitter_uses_previous_close_and_signed_cashflow(rate):
    coverage = FundingCoverage('verified_complete', HASH, tuple((s,1 if s==SYMBOLS[0] else 0) for s in SYMBOLS))
    sim, ledger = simulator(assumptions(minutes=3, funding=coverage))
    sim.advance(bars(START), signals=(signal(START+60000),))
    sim.advance(bars(START+60000))
    ts = START+120000+1532
    sim.advance(bars(START+120000, close='102', high='103'),
                funding=(FundingEvent(SYMBOLS[0],ts,D(rate)),))
    flow = next(x for x in ledger['cashflows'] if x['kind']=='funding')
    assert flow['timestamp_ms'] == ts
    assert flow['mark_price'] == D('100')
    assert flow['mark_known_ms'] == START+120000
    assert flow['amount_quote'] == -D('200')*D(rate)
    assert flow['ambiguous'] is False
    assert sim.finish()['completion'] == 'complete'


@pytest.mark.parametrize('rate,amount', [('.001','-.2'),('-.001','0')])
@pytest.mark.parametrize('exit_bar', [False,True])
def test_funding_intrabar_ambiguous_exposure_adverse_possible_credit_certain(rate,amount,exit_bar):
    sim,ledger = simulator()
    sim.advance(bars(START),signals=(signal(START+60000),))
    if exit_bar:
        sim.advance(bars(START+60000))
    t = START+(120000 if exit_bar else 60000)
    sim.advance(bars(t,low='94' if exit_bar else '99'),
                funding=(FundingEvent(SYMBOLS[0],t+1501,D(rate)),))
    flow = next(x for x in ledger['cashflows'] if x['kind']=='funding')
    assert flow['amount_quote'] == D(amount)
    assert flow['ambiguous'] is True
    assert sim.ambiguity_counts['funding_path'] == 1


def test_funding_at_boundary_settles_surviving_position_before_new_signal():
    sim,ledger = simulator()
    sim.advance(bars(START),signals=(signal(START+60000),))
    sim.advance(bars(START+60000),funding=(FundingEvent(SYMBOLS[0],START+120000,D('.001'),D('103'),START+120000),),
                signals=(signal(START+120000,'ETHUSDT'),))
    funding = next(x for x in ledger['cashflows'] if x['kind']=='funding')
    assert funding['amount_quote'] == D('-.206')
    assert funding['ambiguous'] is False
    assert funding['mark_source'] == 'verified_observation'
    assert sim.view()['pending_entries'] == 1


def test_exact_boundary_funding_ignores_bar_exited_positions_and_new_pending():
    sim,ledger = simulator()
    sim.advance(bars(START),signals=(signal(START+60000),))
    sim.advance(bars(START+60000,low='94'),funding=(FundingEvent(SYMBOLS[0],START+120000,D('.001')),),
                signals=(signal(START+120000,'ETHUSDT'),))
    assert not [x for x in ledger['cashflows'] if x['kind']=='funding']


def test_missing_funding_is_not_zero_and_observed_only_is_inconclusive():
    coverage = FundingCoverage('verified_complete',HASH,tuple((s,1) for s in SYMBOLS))
    sim,_ = simulator(assumptions(minutes=1,funding=coverage))
    sim.advance(bars(START))
    with pytest.raises(ValueError,match='funding_coverage_incomplete'): sim.finish()
    coverage = FundingCoverage('observed_only',HASH,tuple((s,0) for s in SYMBOLS))
    sim,_ = simulator(assumptions(minutes=1,funding=coverage))
    sim.advance(bars(START))
    assert sim.finish()['completion'] == 'inconclusive'


@pytest.mark.parametrize('offset', [180000,179000])
def test_forced_deadline_uses_last_fully_known_close_not_deadline_candle(offset):
    def builder(s,v):
        p = plan(s,v)
        p['holding_deadline_ms'] = START+offset
        p['plan_hash'] = canonical_hash({k:v for k,v in p.items() if k!='plan_hash'})
        return p
    sim,ledger = simulator(builder=builder)
    sim.advance(bars(START),signals=(signal(START+60000),))
    sim.advance(bars(START+60000,close='102',high='103'))
    sim.advance(bars(START+120000,low='40',high='150',close='120'))
    trade = ledger['trades'][0]
    assert trade['exit_reason'] == 'holding_deadline'
    assert trade['exit_price'] == D('102')
    assert trade['exit_boundary_ms'] == START+offset
    assert trade['settlement_price_known_ms'] == START+120000


def test_midnight_exclusive_deadline_and_day_reset_preserve_wallet_loss():
    start = START+DAY-240000
    def builder(s,v):
        p=plan(s,v)
        p['holding_deadline_ms']=START+DAY
        p['plan_hash']=canonical_hash({k:v for k,v in p.items() if k!='plan_hash'})
        return p
    sim,ledger=simulator(assumptions(minutes=5,start=start),builder)
    sim.advance(bars(start),signals=(signal(start+60000),))
    sim.advance(bars(start+60000))
    sim.advance(bars(start+120000,low='98',close='99'))
    assert sim.maximum_drawdown >= D('2.04')
    sim.advance(bars(start+180000,low='40',high='150',close='120'))
    assert ledger['trades'][0]['exit_reason']=='midnight'
    assert ledger['trades'][0]['exit_price']==D('99')
    assert sim.view()['realized_net_pnl_quote']==0
    assert sim.wallet < 100000
    sim.advance(bars(start+240000))
    assert sim.finish()['net_pnl_quote'] < 0


def test_uninterrupted_and_chunked_streams_are_identical():
    stream=[bars(START+i*60000) for i in range(5)]
    outputs=[]
    for chunks in ((stream,), (stream[:1],stream[1:3],stream[3:])):
        sim,ledger=simulator(assumptions(minutes=5))
        for chunk in chunks:
            for b in chunk:
                sim.advance(b,signals=(signal(START+60000),) if b[0].open_ms==START else ())
        outputs.append((sim.finish(),ledger))
    assert outputs[0]==outputs[1]


def test_concurrency_four_and_pending_margin_not_wallet_charge():
    sim,ledger=simulator(builder=lambda s,v:plan(s,v,quantity=1,stop=99,target=103))
    sim.advance(bars(START),signals=tuple(signal(START+60000,s) for s in reversed(SYMBOLS[:5])))
    assert sim.view()['pending_entries']==4
    assert sim.view()['available_balance_quote']==99600
    assert sim.wallet==100000
    assert ledger['rejections'][0]['reason_code']=='research_portfolio_concurrency_exceeded'
    assert [p['symbol'] for p in sim.pending.values()]==list(SYMBOLS[:4])


@pytest.mark.parametrize('mutation,reason', [
    ({'quantity':3},'geometry'), ({'final_leverage':3},'geometry'),
    ({'entry_price':100.001},'geometry'), ({'stop_price':100},'geometry'),
    ({'entry_ttl_seconds':120},'lifetime'), ({'holding_deadline_exclusive':False},'lifetime'),
    ({'holding_deadline_ms':START+120000},'lifetime'),
    ({'execution_authority':'canonical'},'binding'), ({'portfolio_hash':HASH},'binding'),
    ({'dataset_sha256':'e'*64},'binding'), ({'base_config_hash':HASH.replace('a','e')},'binding'),
    ({'risk_budget_quote':1},'risk'), ({'risk_components_quote':{'total_stop_loss':1}},'risk'),
    ({'targets':[]},'geometry'), ({'cost_model':{'entry_fee_rate':.01}},'assumption'),
])
def test_kernel_independently_rejects_malformed_or_policy_violating_plan(mutation,reason):
    def builder(s,v):
        p=plan(s,v); p.update(copy.deepcopy(mutation))
        p['plan_hash']=canonical_hash({k:v for k,v in p.items() if k!='plan_hash'})
        return p
    sim,ledger=simulator(builder=builder)
    sim.advance(bars(START),signals=(signal(START+60000),))
    assert sim.view()['pending_entries']==0
    assert reason in ledger['rejections'][0]['reason_code']


def test_duplicate_future_or_nonpassed_signals_are_errors_before_batch_processing():
    sim,_=simulator()
    future=signal(START+120000)
    with pytest.raises(ValueError,match='signal_invalid'): sim.advance(bars(START),signals=(future,))
    s=signal(START+60000)
    with pytest.raises(ValueError,match='duplicate'): sim.advance(bars(START),signals=(s,s))
    assert sim.processed_batches==0
    sim.advance(bars(START),signals=(s,))
    with pytest.raises(ValueError,match='out_of_order'):
        sim.advance(bars(START+60000),signals=(signal(START+120000,index=0),))


def test_plan_builder_rejection_and_failure_remain_attributable_and_nonretryable():
    sim,ledger=simulator(builder=lambda s,v:{'schema_version':'research-plan-rejection.v1','reason_code':'synthetic_rejected'})
    sim.advance(bars(START),signals=(signal(START+60000),))
    assert ledger['rejections'][0]['variant_id']=='synthetic-test'
    def fail(s,v): raise RuntimeError('planner unavailable')
    sim,_=simulator(builder=fail)
    with pytest.raises(RuntimeError): sim.advance(bars(START),signals=(signal(START+60000),))
    with pytest.raises(ValueError,match='failed'): sim.finish()


@pytest.mark.parametrize('bad', [float('nan'), True, object(), 'no-number'])
def test_numeric_protocol_rejects_nonfinite_or_non_numeric(bad):
    from app.backtesting.research.portfolio_simulator import number
    with pytest.raises(ValueError,match='number_invalid'): number(bad)


def test_immutable_assumptions_and_explicit_subset_are_validated():
    config=assumptions(minutes=1,symbols=SYMBOLS[:2])
    sim,_=simulator(config)
    sim.advance(bars(START,symbols=SYMBOLS[:2]))
    assert sim.finish()['wallet_quote']==100000
    with pytest.raises(ValueError,match='universe'): assumptions(symbols=(SYMBOLS[1],SYMBOLS[0]))
    with pytest.raises(ValueError,match='mutable'): assumptions(symbols=list(SYMBOLS))
    with pytest.raises(ValueError,match='source_window'): assumptions(start=1767225600000)
    with pytest.raises(ValueError,match='funding_coverage'): assumptions(funding_path_policy='optimistic')
    with pytest.raises(ValueError,match='instrument'): instrument(tick_size=D('0'))
    with pytest.raises(ValueError,match='cost'): costs(entry_fee_rate=D('.01'))


def test_liquidation_and_stop_choose_worse_net_outcome_even_stop_below_proxy():
    sim,ledger=simulator(builder=lambda s,v:plan(s,v,quantity=.1,leverage=2,stop=40,target=250))
    sim.advance(bars(START),signals=(signal(START+60000),))
    sim.advance(bars(START+60000,low='30',high='260'))
    assert ledger['trades'][0]['exit_reason']=='stop'
    assert ledger['trades'][0]['exit_price']==D('40')


def test_forced_exit_before_deadline_funding_credit_is_certain():
    def builder(s,v):
        p=plan(s,v); p['holding_deadline_ms']=START+179000
        p['plan_hash']=canonical_hash({k:v for k,v in p.items() if k!='plan_hash'})
        return p
    sim,ledger=simulator(builder=builder)
    sim.advance(bars(START),signals=(signal(START+60000),))
    sim.advance(bars(START+60000))
    sim.advance(bars(START+120000),funding=(FundingEvent(SYMBOLS[0],START+150001,D('-.001')),))
    flow=next(x for x in ledger['cashflows'] if x['kind']=='funding')
    assert flow['amount_quote']==D('.2')
    assert flow['ambiguous'] is False


@pytest.mark.parametrize('change', [
    {'symbol':'UNSEEN'}, {'timestamp_ms':START}, {'timestamp_ms':START+60001},
    {'rate':D('NaN')}, {'rate':D('1')}, {'mark_price':D('100')},
    {'mark_price':D('100'),'mark_observed_ms':START+1},
    {'mark_price':D('-1'),'mark_observed_ms':START+60000},
])
def test_invalid_funding_is_rejected_before_state_change(change):
    sim,_=simulator()
    event=replace(FundingEvent(SYMBOLS[0],START+60000,D('.001')),**change)
    with pytest.raises(ValueError,match='funding_event_invalid'): sim.advance(bars(START),funding=(event,))
    assert sim.processed_batches==0


def test_duplicate_funding_cannot_charge_twice():
    sim,_=simulator(); event=FundingEvent(SYMBOLS[0],START+1,D('.001'))
    with pytest.raises(ValueError,match='funding_event_invalid'): sim.advance(bars(START),funding=(event,event))


def test_finish_expires_pending_and_can_only_be_called_once():
    sim,ledger=simulator(assumptions(minutes=2))
    sim.advance(bars(START),signals=(signal(START+60000),))
    sim.advance(bars(START+60000,low='101',high='102',opening='102',close='102'))
    assert sim.finish()['wallet_quote']==100000
    assert ledger['events'][-1]['reason']=='research_end_pending'
    with pytest.raises(ValueError,match='finished'): sim.finish()


def test_source_exhaustion_and_merge_invalid_shapes():
    sim,_=simulator(assumptions(minutes=1)); sim.advance(bars(START))
    with pytest.raises(ValueError,match='coverage_gap'): sim.advance(bars(START+60000))
    with pytest.raises(ValueError,match='coverage_gap'): list(merge_candles({},SYMBOLS))
    for wrong in ('symbol','time','gap'):
        streams={s:iter([bars(START)[i],bars(START+60000)[i]]) for i,s in enumerate(SYMBOLS)}
        c=bars(START)[0]
        if wrong=='symbol': c=replace(c,symbol='OTHER')
        if wrong=='time': c=replace(c,open_ms=START+60000)
        if wrong=='gap': streams[SYMBOLS[0]]=iter([c,replace(c,open_ms=START+120000)])
        else: streams[SYMBOLS[0]]=iter([c])
        with pytest.raises(ValueError,match='coverage_gap'): list(merge_candles(streams,SYMBOLS))


@pytest.mark.parametrize('change,reason', [
    ({'instruments':()},'bindings'), ({'identity':()},'bindings'),
    ({'sources':tuple((s,(('dataset_id','bad'),)) for s in SYMBOLS)},'source_identity'),
    ({'funding':FundingCoverage('missing',HASH,tuple((s,0) for s in SYMBOLS))},'funding_coverage'),
])
def test_invalid_declared_bindings_are_errors(change,reason):
    with pytest.raises(ValueError,match=reason): assumptions(**change)


def test_invalid_typed_cost_and_instrument_decimal_inputs():
    with pytest.raises(ValueError,match='instrument'): instrument(tick_size=D('NaN'))
    with pytest.raises(ValueError,match='cost'): costs(entry_spread_rate=D('NaN'))
    from app.backtesting.research.portfolio_simulator import wire_number
    with pytest.raises(ValueError,match='nonfinite'): wire_number(D('1e400'))


@pytest.mark.parametrize('wallet,leverage,expected', [('99',2,'exposure')])
def test_exposure_and_margin_independent_admission_guards(wallet,leverage,expected):
    sim,ledger=simulator(builder=lambda s,v:plan(s,v,quantity=2,leverage=leverage,stop=99,target=104))
    # Explicit stressed account state, no artificial deposit or simulation completion claim.
    sim.wallet=D(wallet)
    sim.advance(bars(START),signals=(signal(START+60000),))
    assert expected in ledger['rejections'][0]['reason_code']


def test_unrealized_gains_do_not_unlock_wallet_margin():
    sim,ledger=simulator(builder=lambda s,v:plan(s,v,quantity=2 if s.payload['symbol']==SYMBOLS[0] else 1,stop=99,target=300))
    sim.wallet=D('250')
    sim.advance(bars(START),signals=(signal(START+60000),))
    sim.advance(bars(START+60000,low='99.5',high='151',close='150'),signals=(signal(START+120000,SYMBOLS[1]),))
    assert ledger['rejections'][0]['reason_code']=='research_portfolio_margin_exceeded'
    assert sim.view()['open_positions']==1
    assert sim.view()['equity_quote']==349.96
    assert sim.view()['available_balance_quote']==49.96


def test_attempt_denominator_survives_zero_trade_expiry_and_rejection():
    sim,ledger=simulator(assumptions(minutes=4))
    sim.advance(bars(START),signals=tuple(signal(START+60000,s) for s in SYMBOLS[:3]))
    for i in range(1,4):
        sim.advance(bars(START+i*60000,low='101',high='102',opening='102',close='102'))
    summary=sim.finish()
    assert summary['attempted_signals']==3
    assert summary['admitted_plans']==2
    assert summary['trades']==0


def test_risk_budget_and_net_r_are_independent_of_builder_hash():
    sim,ledger=simulator(builder=lambda s,v:plan(s,v,quantity=2,stop=99,target=101))
    sim.advance(bars(START),signals=(signal(START+60000),))
    assert ledger['rejections'][0]['reason_code']=='research_plan_net_r_invalid'


def test_entry_fees_and_actual_funding_are_not_double_counted_in_unrealized():
    sim,_=simulator()
    sim.advance(bars(START),signals=(signal(START+60000),))
    sim.advance(bars(START+60000,low='98',close='99'),funding=(FundingEvent(SYMBOLS[0],START+120000,D('.001')),))
    assert D(str(sim.view()['realized_net_pnl_quote']))==D('-.24')
    assert D(str(sim.view()['unrealized_net_pnl_quote']))==D('-2')
    assert D(str(sim.view()['equity_quote']))==D('99997.76')


def test_wallet_reconciliation_guard_detects_corrupted_state():
    sim,_=simulator(assumptions(minutes=1)); sim.advance(bars(START)); sim.wallet-=1
    with pytest.raises(ValueError,match='wallet_unreconciled'): sim.finish()


def test_marked_drawdown_never_roundtrips_through_float_protocol():
    sim,_=simulator()
    sim.advance(bars(START),signals=(signal(START+60000),))
    sim.advance(bars(START+60000,close='100.0000000000001'))
    assert sim.maximum_drawdown == D('0.0399999999998')


def test_recursive_mutable_assumption_pairs_are_rejected():
    with pytest.raises(ValueError,match='mutable'):
        assumptions(identity=tuple([k,v] for k,v in IDENTITY.items()))


def test_actual_php_research_plan_schema_cost_parity_and_portfolio_hash():
    # Captured from ResearchPlanBuilderTest::fixture(), never a canonical order.
    fixture=json.loads((Path(__file__).parent/'fixtures/backtesting/php-research-plan.json').read_text())
    p=fixture['plan']; t=p['evaluated_ms']; symbols=SYMBOLS[:1]
    math=p['instrument_math']; cost=p['cost_model']
    i=InstrumentAssumptions(**{k:D(str(v)) if isinstance(v,(int,float)) else v for k,v in math.items()})
    c=CostAssumptions(**{k:D(str(v)) if k.endswith('_rate') else v for k,v in cost.items() if not k.endswith('_role')})
    config=RunAssumptions(t,t+120000,'training',symbols,((symbols[0],i),),c,
        tuple((k,p[k]) for k in IDENTITY),((symbols[0],tuple((k,p[k]) for k in SOURCE)),),
        FundingCoverage('verified_complete',HASH,((symbols[0],0),)),
        'adverse_possible_credit_certain.v1','last_known_close.v1')
    sim,ledger=simulator(config,builder=lambda s,v:p,prime=False)
    sim.prime(bars(t-60000,symbols=symbols),signals=(Signal(2,fixture['signal']),))
    assert sim.view()['pending_entries']==1, ledger['rejections']
    assert sim.view()['portfolio_hash'] != fixture['portfolio']['portfolio_hash']  # reservation now committed
    sim.advance(bars(t,symbols=symbols,low='100',high='102',close='101'))
    sim.advance(bars(t+60000,symbols=symbols,high='104',close='103'))
    result=sim.finish()
    assert ledger['trades'][0]['exit_reason']=='target'
    assert ledger['trades'][0]['gross_pnl_quote']==D('7.485')
    assert ledger['trades'][0]['net_pnl_quote']==D('7.2047616')  # funding provision not an actual charge
    assert result['net_pnl_quote']==D('7.2047616')
    assert canonical_hash({k:v for k,v in p.items() if k!='plan_hash'})==p['plan_hash']
    assert canonical_hash({k:v for k,v in fixture['portfolio'].items() if k!='portfolio_hash'})==fixture['portfolio']['portfolio_hash']


def test_claimed_net_r_cannot_diverge_from_frozen_cost_math():
    def builder(s,v):
        p=plan(s,v); p['targets'][0]['net_r']=99
        p['plan_hash']=canonical_hash({k:v for k,v in p.items() if k!='plan_hash'})
        return p
    sim,ledger=simulator(builder=builder)
    sim.advance(bars(START),signals=(signal(START+60000),))
    assert ledger['rejections'][0]['reason_code']=='research_plan_net_r_invalid'


def test_trade_keeps_prior_fill_bar_target_ambiguity_on_later_exit():
    sim,ledger=simulator()
    sim.advance(bars(START),signals=(signal(START+60000),))
    sim.advance(bars(START+60000,high='111'))
    sim.advance(bars(START+120000,high='112'))
    assert ledger['trades'][0]['ambiguous'] is True
    assert ledger['trades'][0]['ambiguity_reasons']==('fill_bar_target',)


def test_finish_sink_failure_cannot_be_retried_as_a_complete_campaign():
    sim,_=simulator(assumptions(minutes=2))
    sim.advance(bars(START),signals=(signal(START+60000),))
    sim.advance(bars(START+60000))
    def fail(record): raise RuntimeError('ledger unavailable')
    sim.trade_sink=fail
    with pytest.raises(RuntimeError,match='ledger unavailable'): sim.finish()
    with pytest.raises(ValueError,match='failed'): sim.finish()


@pytest.mark.parametrize('quantity', [3,.01,2.0005])
def test_genuine_self_consistent_plan_still_must_respect_cap_minimum_and_grid(quantity):
    sim,ledger=simulator(builder=lambda s,v:plan(s,v,quantity=quantity))
    sim.advance(bars(START),signals=(signal(START+60000),))
    assert ledger['rejections'][0]['reason_code']=='research_plan_geometry_invalid'


def test_prime_exact_start_signal_and_funding_before_admission_without_pnl():
    counts=tuple((s,1 if s==SYMBOLS[0] else 0) for s in SYMBOLS)
    config=assumptions(minutes=2,funding=FundingCoverage('verified_complete',HASH,counts))
    holder={}; admission_observations=[]
    def builder(s,v):
        admission_observations.append((holder['sim'].funding_counts[SYMBOLS[0]],v['pending_entries'],v['open_positions'],v['equity_quote']))
        return plan(s,v)
    sim,ledger=simulator(config,builder=builder,prime=False)
    holder['sim']=sim
    view=sim.prime(bars(START-60000),funding=(FundingEvent(SYMBOLS[0],START,D('.001')),),
                   signals=(signal(START),))
    assert view['as_of_ms']==START
    assert view['pending_entries']==1 and view['open_positions']==0
    assert sim.processed_batches==0 and sim.wallet==100000
    assert sim.funding_counts[SYMBOLS[0]]==1
    assert admission_observations==[(1,0,0,100000)]
    assert not ledger['cashflows']
    sim.advance(bars(START))
    sim.advance(bars(START+60000))
    result=sim.finish()
    assert result['start_ms']==START and result['processed_batches']==2
    assert result['completion']=='complete'
    assert ledger['trades'][0]['decision_ms']==START
    assert ledger['trades'][0]['funding_quote']==0


def test_funding_at_exclusive_end_is_rejected_without_charging_or_advancing():
    sim,ledger=simulator(assumptions(minutes=2),prime=False)
    sim.prime(bars(START-60000),signals=(signal(START),))
    sim.advance(bars(START))
    previous=(sim.wallet,sim.view(),len(ledger['cashflows']))
    with pytest.raises(ValueError,match='funding_event_invalid'):
        sim.advance(bars(START+60000),funding=(FundingEvent(SYMBOLS[0],START+120000,D('.001')),))
    assert (sim.wallet,sim.view(),len(ledger['cashflows']))==previous
    assert sim.funding_counts[SYMBOLS[0]]==0
    sim.advance(bars(START+60000))
    assert sim.finish()['completion']=='complete'


@pytest.mark.parametrize('invalid', ['future_marks','missing_marks','wrong_funding','duplicate_funding','future_signal'])
def test_prime_invalid_input_leaves_startup_unchanged_and_retryable(invalid):
    sim,ledger=simulator(prime=False)
    seed=bars(START-60000); funding=(); signals=()
    if invalid=='future_marks': seed=bars(START)
    if invalid=='missing_marks': seed=seed[:-1]
    if invalid=='wrong_funding': funding=(FundingEvent(SYMBOLS[0],START+1,D('.001')),)
    if invalid=='duplicate_funding': funding=(FundingEvent(SYMBOLS[0],START,D('.001')),)*2
    if invalid=='future_signal': signals=(signal(START+60000),)
    with pytest.raises(ValueError): sim.prime(seed,funding=funding,signals=signals)
    assert sim.marks=={} and sim.processed_batches==0 and sim.wallet==100000
    assert not sim.funding_counts and not sim.pending and not sim.last_signals
    assert not any(ledger.values())
    sim.prime(bars(START-60000),signals=(signal(START),))
    assert sim.view()['pending_entries']==1


def test_prime_required_once_and_startup_planner_failure_is_terminal():
    sim,_=simulator(prime=False)
    with pytest.raises(ValueError,match='not_primed'): sim.advance(bars(START))
    with pytest.raises(ValueError,match='not_primed'): sim.finish()
    sim.prime(bars(START-60000))
    with pytest.raises(ValueError,match='already_primed'): sim.prime(bars(START-60000))
    def fail(s,v): raise RuntimeError('startup planner unavailable')
    sim,_=simulator(builder=fail,prime=False)
    with pytest.raises(RuntimeError): sim.prime(bars(START-60000),signals=(signal(START),))
    with pytest.raises(ValueError,match='failed'): sim.prime(bars(START-60000))
    with pytest.raises(ValueError,match='failed'): sim.advance(bars(START))


def test_primed_exact_start_stream_remains_deterministic_across_chunks():
    outputs=[]
    stream=[bars(START+i*60000) for i in range(3)]
    for chunks in ((stream,),(stream[:1],stream[1:])):
        sim,ledger=simulator(assumptions(minutes=3),prime=False)
        sim.prime(bars(START-60000),signals=(signal(START),))
        for chunk in chunks:
            for batch in chunk: sim.advance(batch)
        outputs.append((sim.finish(),ledger))
    assert outputs[0]==outputs[1]


@pytest.mark.parametrize('field', ['variant_id','dataset_id','signal_run_id'])
@pytest.mark.parametrize('bad', [['mutable'], {'mutable':'value'}, '', 'bad id', 'a'*97])
def test_mutable_or_invalid_provenance_identifier_is_rejected_before_creation(field,bad):
    if field=='variant_id':
        identity=tuple((k,bad if k==field else v) for k,v in IDENTITY.items())
        with pytest.raises(ValueError,match='bindings'): assumptions(identity=identity)
    else:
        source=tuple((k,bad if k==field else v) for k,v in SOURCE.items())
        with pytest.raises(ValueError,match='source_identity'):
            assumptions(sources=tuple((s,source) for s in SYMBOLS))


@pytest.mark.parametrize('bad', [['mutable'], {'mutable':'value'}, '', 'not-a-date', '2026-99-09T00:00:00Z'])
def test_instrument_metadata_text_is_validated_and_cannot_carry_mutable_values(bad):
    with pytest.raises(ValueError,match='instrument'):
        instrument(metadata_retrieved_at=bad)
    with pytest.raises(ValueError,match='instrument'):
        instrument(metadata_raw_sha256=bad)


def test_observed_funding_one_millisecond_before_end_is_in_phase():
    counts=tuple((s,1 if s==SYMBOLS[0] else 0) for s in SYMBOLS)
    config=assumptions(minutes=2,funding=FundingCoverage('verified_complete',HASH,counts))
    sim,ledger=simulator(config,prime=False)
    sim.prime(bars(START-60000),signals=(signal(START),))
    sim.advance(bars(START))
    at=config.end_ms-1
    sim.advance(bars(START+60000),funding=(FundingEvent(SYMBOLS[0],at,D('.001')),))
    flow=next(x for x in ledger['cashflows'] if x['kind']=='funding')
    assert flow['timestamp_ms']==at and flow['amount_quote']==D('-.2')
    assert sim.finish()['completion']=='complete'
