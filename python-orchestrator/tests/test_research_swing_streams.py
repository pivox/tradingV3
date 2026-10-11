"""Two-sided runs: one signal stream per (symbol, side) and one cell identity per side (swing campaign PR 4).

Day_trading keeps a single stream per symbol and its own identity; these tests cover the swing cells.
"""
from __future__ import annotations

import copy
from dataclasses import replace
import hashlib
import json

import pytest

from app.backtesting.research import campaign as c, signals
from app.backtesting.research.portfolio_simulator import (CELL_FIELDS, DAY_TRADING_PROFILE, SWING_PROFILE,
    SYMBOLS, Signal, canonical_hash, composite_hash, stream_key)
from app.backtesting.research.signal_sources import FOUR_HOURS_MS, QUARTER_HOUR_MS, RESEARCH_PROFILES
from app.modern_trading_contracts import calculate_config_hash, calculate_snapshot_hash
from tests.test_research_campaign import Builder, fixture
from tests.test_research_kernel_swing import (CELL, SWING_IDENTITY, swing_assumptions, swing_ledgers, swing_plan,
                                              signal, simulator)
from tests.test_research_portfolio_simulator import IDENTITY, SOURCE, START, bars
from tests.test_research_signals import result_fixture
from tests.test_research_swing_plumbing import swing_evidence

LONG_CELL = tuple((k, canonical_hash({'cell': 'long', 'field': k})) for k in CELL_FIELDS)
SHORT_CELL = tuple((k, canonical_hash({'cell': 'short', 'field': k})) for k in CELL_FIELDS)
DISTINCT_IDENTITY = {**IDENTITY, **{k: composite_hash({'long': dict(LONG_CELL)[k], 'short': dict(SHORT_CELL)[k]})
                                    for k in CELL_FIELDS}}


def distinct(**changes):
    """Two cells with their own hashes, as the long and short swing setups have."""
    return swing_assumptions(**{'identity': tuple(DISTINCT_IDENTITY.items()),
                                'cells': (('long', LONG_CELL), ('short', SHORT_CELL)), **changes})


def cell_signal(t, symbol, index, side, cell):
    """A signal of ``side`` whose baseline is that side's cell."""
    base = signal(t, symbol, index, side)
    payload = {k: v for k, v in base.payload.items() if k != 'result_hash'}
    c = dict(cell)
    payload['baseline'] = {'setup_hash': c['base_setup_hash'], 'config_hash': c['base_config_hash'],
                           'condition_catalog_hash': c['base_catalog_hash'], 'snapshot_hash': c['base_snapshot_hash']}
    payload['result_hash'] = canonical_hash(payload)
    return Signal(index, payload)


def cell_plan(sig, view, side, cell, **changes):
    """``swing_plan`` carrying the hashes of ``cell``."""
    plan = swing_plan(sig, view, side=side, stop=95 if side == 'long' else 105,
                      target=120 if side == 'long' else 80, **dict(cell), **changes)
    return plan


def test_composite_hash_is_the_cell_hash_for_one_side_and_binds_both_sides_otherwise():
    assert composite_hash({'long': 'sha256:' + 'a' * 64}) == 'sha256:' + 'a' * 64
    both = composite_hash({'short': 's', 'long': 'l'})
    assert both == canonical_hash({'long': 'l', 'short': 's'}) != composite_hash({'long': 's', 'short': 'l'})
    assert stream_key('BTCUSDT', 'long', ('long',)) == 'BTCUSDT'
    assert stream_key('BTCUSDT', 'short', ('long', 'short')) == 'BTCUSDT:short'


def test_two_sided_runs_require_exact_cells_composites_and_streams():
    assumptions = distinct()
    assert assumptions.stream_keys() == tuple(f'{s}:{side}' for s in SYMBOLS for side in ('long', 'short'))
    assert assumptions.cell_identity('short')['base_setup_hash'] == dict(SHORT_CELL)['base_setup_hash']
    assert assumptions.cell_identity('long')['variant_id'] == IDENTITY['variant_id']
    with pytest.raises(ValueError, match='research_cells_composite_conflict'):
        distinct(identity=tuple(SWING_IDENTITY.items()))
    for cells in ((), (('short', SHORT_CELL), ('long', LONG_CELL)), (('long', LONG_CELL),),
                  (('long', LONG_CELL), ('short', SHORT_CELL[:-1]))):
        with pytest.raises(ValueError, match='research_cells_invalid'):
            distinct(cells=cells)
    with pytest.raises(ValueError, match='research_bindings_invalid'):
        distinct(sources=tuple((s, tuple(SOURCE.items())) for s in SYMBOLS))
    with pytest.raises(ValueError, match='research_cells_invalid'):
        swing_assumptions(profile=DAY_TRADING_PROFILE, costs=replace(swing_assumptions().costs,
                          funding_intervals_provisioned=1), cells=(('long', CELL),))


def test_each_side_is_validated_against_its_own_cell():
    def build(sig, view):
        side = sig.payload['execution']['side']
        return cell_plan(sig, view, side, LONG_CELL if side == 'long' else SHORT_CELL)

    sim, ledger = simulator(distinct(), build)
    sim.advance(bars(START), signals=(cell_signal(START + 60000, 'BTCUSDT', 0, 'long', LONG_CELL),
                                      cell_signal(START + 60000, 'ETHUSDT', 0, 'short', SHORT_CELL)))
    assert sim.admitted_plans == 2 and not ledger['rejections']

    # A plan carrying the other side's cell, or a side that differs from its signal, is refused.
    sim, ledger = simulator(distinct(), lambda s, v: cell_plan(s, v, 'long', SHORT_CELL))
    sim.advance(bars(START), signals=(cell_signal(START + 60000, 'BTCUSDT', 0, 'long', LONG_CELL),))
    assert ledger['rejections'][-1]['reason_code'] == 'research_plan_binding_invalid'
    sim, ledger = simulator(distinct(), lambda s, v: cell_plan(s, v, 'short', LONG_CELL))
    sim.advance(bars(START), signals=(cell_signal(START + 60000, 'BTCUSDT', 0, 'long', LONG_CELL),))
    assert ledger['rejections'][-1]['reason_code'] == 'research_plan_side_invalid'
    # Rejection rows carry the identity of the signal's own cell.
    assert ledger['rejections'][-1]['base_setup_hash'] == dict(LONG_CELL)['base_setup_hash']


def test_a_signal_with_the_other_sides_baseline_or_no_side_is_fatal():
    sim, _ = simulator(distinct())
    with pytest.raises(ValueError, match='research_signal_baseline_invalid'):
        sim.advance(bars(START), signals=(cell_signal(START + 60000, 'BTCUSDT', 0, 'long', SHORT_CELL),))
    sim, _ = simulator(distinct())
    sideless = signal(START + 60000)
    payload = {k: v for k, v in sideless.payload.items() if k not in ('execution', 'result_hash')}
    payload['result_hash'] = canonical_hash(payload)
    with pytest.raises(ValueError, match='research_signal_invalid'):
        sim.advance(bars(START), signals=(Signal(0, payload),))


def test_each_stream_keeps_its_own_signal_order():
    def build(sig, view):
        side = sig.payload['execution']['side']
        return cell_plan(sig, view, side, LONG_CELL if side == 'long' else SHORT_CELL)

    sim, ledger = simulator(distinct(), build)
    sim.advance(bars(START), signals=(cell_signal(START + 60000, 'BTCUSDT', 7, 'long', LONG_CELL),))
    # The short stream of the same pair has its own indices; the pair itself is still occupied.
    sim.advance(bars(START + 60000), signals=(cell_signal(START + 120000, 'BTCUSDT', 0, 'short', SHORT_CELL),))
    assert ledger['rejections'][-1]['reason_code'] == 'research_portfolio_symbol_active'
    with pytest.raises(ValueError, match='research_signal_duplicate_or_out_of_order'):
        sim.advance(bars(START + 120000), signals=(cell_signal(START + 180000, 'BTCUSDT', 7, 'long', LONG_CELL),))


def test_both_sides_of_a_pair_may_decide_at_one_boundary_long_first():
    def build(sig, view):
        side = sig.payload['execution']['side']
        return cell_plan(sig, view, side, LONG_CELL if side == 'long' else SHORT_CELL)

    sim, ledger = simulator(distinct(), build)
    # Given the short first, the kernel still admits in symbol then side order.
    sim.advance(bars(START), signals=(cell_signal(START + 60000, 'BTCUSDT', 0, 'short', SHORT_CELL),
                                      cell_signal(START + 60000, 'BTCUSDT', 0, 'long', LONG_CELL)))
    assert sim.admitted_plans == 1
    assert [r['reason_code'] for r in ledger['rejections']] == ['research_portfolio_symbol_active']
    assert ledger['rejections'][0]['base_setup_hash'] == dict(SHORT_CELL)['base_setup_hash']
    # One stream still decides at most once per boundary.
    sim, _ = simulator(distinct(), build)
    with pytest.raises(ValueError, match='research_signal_duplicate_or_outside_window'):
        sim.advance(bars(START), signals=(cell_signal(START + 60000, 'BTCUSDT', 0, 'long', LONG_CELL),
                                          cell_signal(START + 60000, 'BTCUSDT', 1, 'long', LONG_CELL)))


def test_statistics_bind_each_row_to_its_cell_and_stream(tmp_path):
    from app.backtesting.research.statistics import StatisticsError, analyze_ledgers

    summary, files, start, end = swing_ledgers(tmp_path / 'unit')
    identity = {side: {k: v for k, v in SWING_IDENTITY.items() if k not in CELL_FIELDS} | dict(CELL)
                for side in ('long', 'short')}
    sources = {f'{s}:{side}': SOURCE for s in SYMBOLS for side in ('long', 'short')}
    result = analyze_ledgers(tmp_path / 'unit', files, symbols=SYMBOLS, start_ms=start, end_ms=end,
                             max_spool_bytes=10 ** 8, min_free_bytes=0, expected_identity=identity,
                             expected_sources=sources, kernel_profile=SWING_PROFILE)
    assert result['closed_trades'] == summary['trades']

    for broken_identity, broken_sources, reason in (
            ({'long': identity['long']}, sources, 'ledger_identity_scope_invalid'),
            ({**identity, 'short': {**identity['short'], 'base_setup_hash': 'sha256:' + 'f' * 64}}, sources,
             'ledger_identity_conflict'),
            (identity, {k: v for k, v in sources.items() if not k.endswith(':short')}, 'ledger_source_binding_conflict')):
        with pytest.raises(StatisticsError, match=reason):
            analyze_ledgers(tmp_path / 'unit', files, symbols=SYMBOLS, start_ms=start, end_ms=end,
                            max_spool_bytes=10 ** 8, min_free_bytes=0, expected_identity=broken_identity,
                            expected_sources=broken_sources, kernel_profile=SWING_PROFILE)


# ------------------------------------------------------------------------------ campaign
class SwingBuilder(Builder):
    """One planner per cell: it sees only its own side and refuses a pair that is already active."""
    def build(self, sig, view):
        self.calls.append((self.opening['profile'], sig.payload['symbol'], sig.payload['evaluated_ms']))
        self.count += 1
        side = sig.payload['execution']['side']
        assert self.opening['profile'] == 'swing.' + side
        if sig.payload['symbol'] in view['active_symbols']:
            return {'schema_version': 'research-plan-rejection.v1', 'reason_code': 'research_portfolio_symbol_active',
                    'portfolio_hash': view['portfolio_hash']}
        self.planned += 1
        o, b = self.opening, self.opening['baseline']
        run = next(r for r in o['source_runs'] if r['symbol'] == sig.payload['symbol'])
        return swing_plan(sig, view, side=side, stop=95 if side == 'long' else 105,
            target=120 if side == 'long' else 80, base_setup_hash=b['setup_hash'], base_config_hash=b['config_hash'],
            base_catalog_hash=b['condition_catalog_hash'], base_snapshot_hash=b['snapshot_hash'],
            variant_id=o['variant']['id'], variant_hash=o['variant']['variant_hash'],
            instrument_assumptions_hash=o['instrument_assumptions']['manifest_hash'],
            cost_assumptions_hash=o['cost_assumptions']['assumption_hash'],
            research_code_hash=self.opened['research_code_hash'], dataset_id=run['source']['dataset_id'],
            dataset_sha256=run['source']['dataset_sha256'], signal_run_id=run['signal_run_id'],
            signal_output_sha256=run['signal_output_sha256'])


def swing_unit(tmp_path, monkeypatch, passed, sides=('long', 'short')):
    """One B1 root per swing side over the campaign fixture's window; ``passed`` maps (symbol, side) to a verdict."""
    kwargs, report, selections = fixture(tmp_path, monkeypatch, symbols=('BTCUSDT', 'ETHUSDT'))
    four_hourly = {s: replace(selection, step_ms=FOUR_HOURS_MS) for s, selection in selections.items()}
    monkeypatch.setattr(c, 'select_sources', lambda root, start, end, score, s, *, step=QUARTER_HOUR_MS:
                        (four_hourly if step == FOUR_HOURS_MS else selections)[s])
    monkeypatch.setattr(c.plans, 'PlanWorker', SwingBuilder)
    roots = []
    for side in sides:
        profile = RESEARCH_PROFILES['swing.' + side]
        evidence = swing_evidence(kwargs['app_dir'], profile)
        snapshot = evidence['snapshot']
        snapshot['config']['setup']['ast'] = {'execution': {'targets': {'value': [{'liquidity_role': 'maker'}]},
            'cost_contract': {'value': {'entry_liquidity_role': 'maker', 'stop_liquidity_role': 'taker',
                                        'funding_interval_seconds': 28800}},
            'time_stop': {'value': 'PT336H'}}}
        snapshot['config']['exchange'].update({'fees': {'maker_rate': .0002, 'taker_rate': .0005},
                                               'funding': {'interval': 'PT8H'}})
        snapshot['config_hash'] = calculate_config_hash(snapshot['config'], snapshot['condition_catalog_hash'])
        snapshot['snapshot_hash'] = calculate_snapshot_hash(snapshot)
        baseline = {**evidence['baseline'], 'config_hash': snapshot['config_hash'],
                    'snapshot_hash': snapshot['snapshot_hash'], 'setup_hash': ('b' if side == 'long' else 'c') * 64}
        root = tmp_path / ('swing-' + side)
        root.mkdir(mode=0o700)
        cell = copy.deepcopy(report)
        cell['profile'] = profile.id
        for s, meta in cell['symbols'].items():
            selection = four_hourly[s]
            frame = result_fixture(START)
            frame.update({'session_id': signals.signal_session_id(s, selection.dataset_sha256, profile), 'symbol': s,
                'source': {'dataset_id': 'research-' + selection.dataset_sha256[:24],
                           'dataset_sha256': selection.dataset_sha256, 'source_venue': 'binance_usdm',
                           'source_network': 'mainnet', 'market_type': 'perpetual'},
                'execution': evidence['execution'], 'baseline': baseline, 'passed': passed[(s, side)]})
            if frame['passed']:
                frame['reason_code'] = 'setup_rules_passed'
            frame['result_hash'] = canonical_hash({k: v for k, v in frame.items() if k != 'result_hash'})
            raw = (json.dumps(frame) + '\n').encode()
            (root / (s + '.results.ndjson')).write_bytes(raw)
            verdict = int(frame['passed'])
            meta.update({'passed_rules': verdict, 'failed_rules': 1 - verdict, 'scored_passed_rules': verdict,
                         'scored_failed_rules': 1 - verdict, 'result_sha256': hashlib.sha256(raw).hexdigest(),
                         'baseline': baseline, 'effective_config_snapshot': snapshot,
                         'execution': evidence['execution']})
        (root / 'report.json').write_text(json.dumps(cell))
        roots.append(root)
    kwargs['signal_root'] = tuple(roots)
    return kwargs


def test_a_swing_unit_runs_one_planner_per_side_and_one_stream_per_pair_and_side(tmp_path, monkeypatch):
    passed = {('BTCUSDT', 'long'): True, ('BTCUSDT', 'short'): True,
              ('ETHUSDT', 'long'): False, ('ETHUSDT', 'short'): True}
    kwargs = swing_unit(tmp_path, monkeypatch, passed)
    result = c.run_campaign(**kwargs)
    assert result['status'] == 'complete' and result['reconciliation']['status'] == 'verified'
    # Same decision time: BTC before ETH, and on BTC the long before the short, which finds the pair active.
    assert SwingBuilder.calls == [('swing.long', 'BTCUSDT', START), ('swing.short', 'BTCUSDT', START),
                                  ('swing.short', 'ETHUSDT', START)]
    assert result['b1_counters'] == {'evaluated_ticks': 4, 'passed_rules': 3, 'failed_rules': 1}
    assert result['admitted_plans'] == 2
    assert {side: (s['received'], s['planned']) for side, s in result['planner_summary'].items()} == {
        'long': (1, 1), 'short': (2, 1)}
    assert set(result['planner_evidence']) == {'long', 'short'}

    out = kwargs['output_root']
    manifest = json.loads((out / 'manifest.json').read_text())
    assert manifest['kernel_profile'] == json.loads(json.dumps(SWING_PROFILE.wire()))
    assert not {'variant', 'baseline', 'source_runs'} & set(manifest)
    assert [(cell['profile'], cell['side']) for cell in manifest['cells']] == [('swing.long', 'long'),
                                                                               ('swing.short', 'short')]
    long_cell, short_cell = manifest['cells']
    assert long_cell['baseline']['setup_hash'] == 'b' * 64 and short_cell['baseline']['setup_hash'] == 'c' * 64
    assert long_cell['variant']['variant_hash'] != short_cell['variant']['variant_hash']
    assert [run['symbol'] for run in short_cell['source_runs']] == ['BTCUSDT', 'ETHUSDT']

    planner, kernel = (json.loads(line) for line in (out / 'rejections.ndjson').read_text().splitlines())
    assert planner['schema_version'] == 'research-planner-rejection-evidence.v1' and planner['symbol'] == 'BTCUSDT'
    assert kernel['reason_code'] == planner['response']['reason_code'] == 'research_portfolio_symbol_active'
    assert kernel['base_setup_hash'] == 'c' * 64
    assert kernel['signal_run_id'] == short_cell['source_runs'][0]['signal_run_id']
    plans = [json.loads(line) for line in (out / 'plans.ndjson').read_text().splitlines()]
    assert [(p['symbol'], p['side'], p['base_setup_hash']) for p in plans] == [
        ('BTCUSDT', 'long', 'b' * 64), ('ETHUSDT', 'short', 'c' * 64)]
    assert all(p['holding_deadline_ms'] == START + 14 * 86400000 for p in plans)


@pytest.mark.parametrize('sides', [('long',), ('short',)])
def test_a_swing_unit_needs_both_sides(tmp_path, monkeypatch, sides):
    passed = dict.fromkeys(((s, side) for s in ('BTCUSDT', 'ETHUSDT') for side in ('long', 'short')), False)
    kwargs = swing_unit(tmp_path, monkeypatch, passed, sides)
    with pytest.raises(c.CampaignError, match='one research cell set'):
        c.run_campaign(**kwargs)
    assert json.loads((kwargs['output_root'] / 'status.json').read_text())['status'] == 'failed'


def test_a_swing_unit_refuses_a_day_trading_report_among_its_cells(tmp_path, monkeypatch):
    passed = dict.fromkeys(((s, side) for s in ('BTCUSDT', 'ETHUSDT') for side in ('long', 'short')), False)
    kwargs = swing_unit(tmp_path, monkeypatch, passed)
    kwargs['signal_root'] = (tmp_path / 'signals', *kwargs['signal_root'])
    with pytest.raises(c.CampaignError, match='one research cell set'):
        c.run_campaign(**kwargs)


def test_a_swing_unit_needs_every_pair_on_each_side(tmp_path, monkeypatch):
    passed = dict.fromkeys(((s, side) for s in ('BTCUSDT', 'ETHUSDT') for side in ('long', 'short')), False)
    kwargs = swing_unit(tmp_path, monkeypatch, passed)
    root = kwargs['signal_root'][1]
    report = json.loads((root / 'report.json').read_text())
    del report['symbols']['ETHUSDT']
    report['totals'] = {'completed_symbols': 1, 'source_candles': report['symbols']['BTCUSDT']['source_candles'],
                        'evaluated_ticks': 1, 'scored_evaluations': 1}
    (root / 'report.json').write_text(json.dumps(report))
    with pytest.raises(c.CampaignError, match='missing selected symbols'):
        c.run_campaign(**kwargs)
