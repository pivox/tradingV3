"""Swing selection protocol: one candidate, one cell per side, one stream per pair and side (swing campaign PR 5).

The frozen day_trading protocol, registry and orchestration are covered by their own suites; these tests
cover the swing family only.
"""
from __future__ import annotations

import copy
import hashlib
import json

import pytest

from app.backtesting.research import orchestration as o
from app.backtesting.research.experiments import (CELL_IDENTITIES, ExperimentError, ExperimentRegistry,
    _validate_protocol, canonical_bytes, hash_bytes, make_protocol, rank_candidates, schedule_batch)
from app.backtesting.research.portfolio_simulator import SWING_PROFILE, canonical_hash, composite_hash
from app.backtesting.research.statistics import StatisticsError, verify_campaign
from tests.test_research_experiments import HASH, IDENTITY, metrics, synthetic_runner
from tests.test_research_statistics import FIELDS, START, campaign_fixture, marked_row, rehash_campaign, write_ledgers

SIDES = ('long', 'short')
CELLS = {side: {k: canonical_hash({'side': side, 'field': k}) for k in CELL_IDENTITIES} for side in SIDES}
SWING_IDENTITY = {**IDENTITY, **{k: composite_hash({s: CELLS[s][k] for s in SIDES}) for k in CELL_IDENTITIES}}
BINDING = {'source_runs': {'long': [], 'short': []}, 'signal_reports': [], 'runner_code_sha256': {},
           'cost_assumptions_hash': HASH, 'funding_inventory_hash': HASH,
           'funding_diagnostic_policy': 'synthetic_funding_diagnostic.v1'}


def swing_protocol(identity=None, cells=None, **kwargs):
    return make_protocol(identity or SWING_IDENTITY, family='swing', cells=cells or CELLS, **kwargs)


# ------------------------------------------------------------------------------ protocol
def test_swing_protocol_is_one_candidate_with_a_cell_per_side():
    protocol = swing_protocol()
    _validate_protocol(protocol)
    assert protocol['research_family'] == 'swing'
    assert protocol['variants'] == [{'id': 'baseline', 'diff': {}}]
    assert protocol['kernel_profile'] == json.loads(canonical_bytes(SWING_PROFILE.wire()))
    assert protocol['cells'] == CELLS
    assert protocol['risk_assumptions']['maximum_concurrent_positions'] == 10
    assert protocol['risk_assumptions']['one_active_plan_per_symbol'] is True
    assert protocol['risk_assumptions']['daily_loss_limit_quote'] == '100000'
    day = make_protocol(IDENTITY)
    assert protocol['thresholds'] == day['thresholds'] and protocol['ranking'] == day['ranking']
    # Day_trading keeps its frozen protocol: no family, no cells, thirteen variants.
    assert not {'research_family', 'kernel_profile', 'cells'} & set(day) and len(day['variants']) == 13
    assert day['risk_assumptions']['maximum_concurrent_positions'] == 4


@pytest.mark.parametrize('identity, kwargs, reason', [
    (SWING_IDENTITY, {'family': 'scalping'}, 'invalid_protocol_family'),
    (SWING_IDENTITY, {'family': 'swing'}, 'invalid_protocol_family'),
    (SWING_IDENTITY, {'cells': CELLS}, 'invalid_protocol_family'),
    (SWING_IDENTITY, {'family': 'swing', 'cells': {'long': CELLS['long']}}, 'invalid_protocol_cells'),
    (SWING_IDENTITY, {'family': 'swing', 'cells': {**CELLS, 'short': {**CELLS['short'], 'base_setup_hash': 'f' * 64}}},
     'invalid_protocol_cells'),
    (SWING_IDENTITY, {'family': 'swing', 'cells': {**CELLS, 'short': {**CELLS['short'], 'variant_hash': HASH}}},
     'invalid_protocol_cells'),
    (IDENTITY, {'family': 'swing', 'cells': CELLS}, 'invalid_protocol_cells'),
])
def test_swing_protocol_refuses_a_foreign_family_or_cells(identity, kwargs, reason):
    with pytest.raises(ExperimentError, match=reason):
        make_protocol(identity, **kwargs)


def test_swing_phase_bindings_name_the_source_runs_of_each_side():
    swing_protocol(phase_bindings={p: BINDING for p in ('training', 'validation')})
    for runs in ([], {'long': []}):
        with pytest.raises(ExperimentError, match='invalid_phase_bindings'):
            swing_protocol(phase_bindings={p: {**BINDING, 'source_runs': runs} for p in ('training', 'validation')})


def test_a_changed_swing_protocol_is_not_prespecified():
    protocol = swing_protocol()
    for change in ({'variants': [*protocol['variants'], {'id': 'ema20_5m', 'diff': {'anchor_source': 'ema_20'}}]},
                   {'risk_assumptions': {**protocol['risk_assumptions'], 'maximum_concurrent_positions': 4}},
                   {'kernel_profile': {**protocol['kernel_profile'], 'max_active': 4}}):
        with pytest.raises(ExperimentError, match='protocol_not_prespecified'):
            _validate_protocol({**protocol, **change})
    # Naming the default family would fork the frozen day_trading shape.
    with pytest.raises(ExperimentError, match='protocol_not_prespecified'):
        _validate_protocol({**make_protocol(IDENTITY), 'research_family': 'day_trading'})
    with pytest.raises(ExperimentError, match='invalid_protocol_family'):
        _validate_protocol({k: v for k, v in protocol.items() if k != 'research_family'})


# ------------------------------------------------------------------------------ registry
def test_the_swing_registry_runs_the_candidate_under_both_costs_then_validation(tmp_path):
    calls = []

    def runner(registration, root):
        calls.append((registration['phase'], registration['variant_id'], registration['cost_profile']))
        synthetic_runner(registration, root)

    protocol = swing_protocol()
    with ExperimentRegistry(tmp_path / 'private', protocol, min_free_bytes=0) as registry:
        candidates = schedule_batch(registry, runner, verify_unit=lambda root, p, r: metrics(r['phase']))
        assert calls == [('training', 'baseline', 'baseline'), ('training', 'baseline', 'adverse'),
                         ('validation', 'baseline', 'baseline'), ('validation', 'baseline', 'adverse')]
        assert [row['variant_id'] for row in rank_candidates(candidates, protocol)] == ['baseline']
        with pytest.raises(ExperimentError, match='unit_not_prespecified'):
            registry.register('ema20_5m', 'baseline', 'training')


# ---------------------------------------------------------------------------- statistics
def swing_campaign(root, trades=()):
    """``campaign_fixture``'s unit rebound to two swing cells; ``trades`` are (symbol, side, minute, gross)."""
    day_protocol, registration = campaign_fixture(root)
    manifest, summary, status = (json.loads((root / f'{name}.json').read_bytes())
                                 for name in ('manifest', 'summary', 'status'))
    baselines = {side: {**manifest['baseline'], 'setup_hash': ('5' if side == 'long' else '6') * 64} for side in SIDES}
    runs = {side: [{**run, 'signal_run_id': 'synthetic-' + side} for run in manifest['source_runs']] for side in SIDES}
    cells, identities, sources = [], {}, {}
    for side in SIDES:
        b = baselines[side]
        variant = {'id': 'baseline', 'diff': []}
        variant['variant_hash'] = canonical_hash({'schema_version': 'research-variant.v1', **variant,
            'base_setup_hash': b['setup_hash'], 'base_config_hash': b['config_hash'],
            'base_catalog_hash': b['condition_catalog_hash']})
        cells.append({'profile': 'swing.' + side, 'side': side, 'baseline': b, 'variant': variant,
                      'source_runs': runs[side]})
        identities[side] = {'base_setup_hash': b['setup_hash'], 'base_config_hash': b['config_hash'],
            'base_catalog_hash': b['condition_catalog_hash'], 'base_snapshot_hash': b['snapshot_hash'],
            'instrument_assumptions_hash': day_protocol['identity']['instrument_assumptions_hash'],
            'research_code_hash': day_protocol['identity']['research_code_hash'], 'variant_id': 'baseline',
            'variant_hash': variant['variant_hash'], 'cost_assumptions_hash': manifest['cost_assumptions']['assumption_hash']}
        for run in runs[side]:
            sources[(run['symbol'], side)] = {**run['source'], 'signal_run_id': run['signal_run_id'],
                                              'signal_output_sha256': run['signal_output_sha256']}
    for key in ('variant', 'baseline', 'source_runs'):
        del manifest[key]
    manifest.update(kernel_profile=json.loads(canonical_bytes(SWING_PROFILE.wire())), cells=cells)

    bindings = copy.deepcopy(day_protocol['phase_bindings'])
    for binding in bindings.values():
        binding['source_runs'] = runs
    protocol_cells = {side: {k: identities[side][k] for k in CELL_IDENTITIES} for side in SIDES}
    identity = {**day_protocol['identity'],
                **{k: composite_hash({s: protocol_cells[s][k] for s in SIDES}) for k in CELL_IDENTITIES},
                'dataset_hash': hash_bytes(canonical_bytes({p: b['source_runs'] for p, b in bindings.items()}))}
    protocol = make_protocol(identity, phase_bindings=bindings, family='swing', cells=protocol_cells)

    end = manifest['end_ms']
    rows, net_total, gross_total = [marked_row(START, kind='initial')], 0, 0
    for symbol, side, minute, gross in trades:
        at, direction = START + minute * 60000, 1 if side == 'long' else -1
        plan = {'schema_version': 'research-plan.v1', **identities[side], **sources[(symbol, side)], 'symbol': symbol,
            'side': side, 'cost_profile': 'baseline', 'cost_model': manifest['cost_assumptions']['profiles']['baseline'],
            'entry_price': 100.0, 'quantity': 2.0, 'instrument_math': {'contract_size': 1.0},
            'risk_components_quote': {'total_stop_loss': 2.0}, 'evaluated_ms': at - 60000}
        plan['plan_hash'] = key = canonical_hash(plan)
        net = gross - 1
        net_total, gross_total = net_total + net, gross_total + gross
        rows += [('plans', plan), ('events', {'symbol': symbol, 'plan_hash': key, 'reason': 'admitted'}),
            ('events', {'symbol': symbol, 'plan_hash': key, 'reason': 'limit_fill_proxy', 'timestamp_ms': at}),
            ('cashflows', {'symbol': symbol, 'plan_hash': key, 'kind': 'entry_fee', 'amount_quote': '-1',
                           'timestamp_ms': at - 30000}),
            ('cashflows', {'symbol': symbol, 'plan_hash': key, 'kind': 'gross_pnl', 'amount_quote': str(gross),
                           'timestamp_ms': at}),
            ('trades', {'symbol': symbol, 'plan_hash': key, 'side': side, **identities[side], **sources[(symbol, side)],
                **{f: '0' for f in FIELDS}, 'net_pnl_quote': str(net), 'gross_pnl_quote': str(gross), 'fees_quote': '1',
                'exit_boundary_ms': at, 'fill_boundary_ms': at, 'exit_reason': 'synthetic', 'entry_price': '100',
                'quantity': '2', 'exit_price': str(100 + direction * gross / 2)}),
            marked_row(at)]
    rows.append(marked_row(end, kind='terminal'))
    status['files'] = write_ledgers(root, rows)
    counters = {'evaluated_ticks': 200, 'passed_rules': len(trades), 'failed_rules': 200 - len(trades)}
    manifest['b1_counters'] = counters
    summary.update(trades=len(trades), admitted_plans=len(trades), attempted_signals=len(trades), b1_counters=counters,
                   gross_pnl_quote=str(gross_total), net_pnl_quote=str(net_total), fees_quote=str(len(trades)),
                   wallet_quote=str(100000 + net_total))
    rehash_campaign(root, manifest, summary, status)
    return protocol, {**registration, 'protocol_hash': hash_bytes(canonical_bytes(protocol))}, identities


def test_a_swing_unit_is_verified_side_by_side_against_its_protocol_cells(tmp_path):
    protocol, registration, _ = swing_campaign(tmp_path, trades=(('BTCUSDT', 'long', 2, 4), ('ETHUSDT', 'short', 4, 6)))
    result = verify_campaign(tmp_path, protocol, registration)
    assert result['status'] == 'complete'
    assert result['closed_trades'] == 2 and result['pairs_with_trades'] == 2
    assert result['net_pnl_quote'] == '8'
    assert result['kernel_profile'] == SWING_PROFILE.wire()


def test_a_zero_swing_unit_is_verified(tmp_path):
    protocol, registration, _ = swing_campaign(tmp_path)
    assert verify_campaign(tmp_path, protocol, registration)['closed_trades'] == 0


def edit(root, name, change):
    document = json.loads((root / f'{name}.json').read_bytes())
    change(document)
    rehash_campaign(root, document if name == 'manifest' else None, document if name == 'summary' else None)


@pytest.mark.parametrize('mutation, reason', [
    ('foreign_family', 'campaign_research_family_conflict'),
    ('kernel_profile', 'campaign_research_cells_conflict'),
    ('cell_order', 'campaign_research_cells_conflict'),
    ('single_cell_keys', 'campaign_research_cells_conflict'),
    ('short_runs', 'campaign_frozen_source_or_code_conflict'),
    ('short_baseline', 'baseline_identity_conflict'),
    ('short_variant', 'closed_variant_identity_conflict'),
])
def test_a_swing_unit_must_match_its_protocol_family_cells_and_sources(tmp_path, mutation, reason):
    protocol, registration, _ = swing_campaign(tmp_path)
    if mutation == 'foreign_family':
        day = make_protocol(protocol['identity'], phase_bindings={p: {**b, 'source_runs': b['source_runs']['long']}
                                                                  for p, b in protocol['phase_bindings'].items()})
        protocol, registration = day, {**registration, 'protocol_hash': hash_bytes(canonical_bytes(day))}
    elif mutation == 'kernel_profile':
        edit(tmp_path, 'manifest', lambda m: m['kernel_profile'].update(max_active=4))
    elif mutation == 'cell_order':
        edit(tmp_path, 'manifest', lambda m: m['cells'].reverse())
    elif mutation == 'single_cell_keys':
        edit(tmp_path, 'manifest', lambda m: m.update(variant=m['cells'][0]['variant']))
    elif mutation == 'short_runs':
        edit(tmp_path, 'manifest', lambda m: m['cells'][1]['source_runs'][0].update(signal_run_id='foreign'))
    elif mutation == 'short_baseline':
        edit(tmp_path, 'manifest', lambda m: m['cells'][1]['baseline'].update(setup_hash='5' * 64))
    else:
        edit(tmp_path, 'manifest', lambda m: m['cells'][1]['variant'].update(variant_hash='sha256:' + 'f' * 64))
    with pytest.raises(StatisticsError, match=reason):
        verify_campaign(tmp_path, protocol, registration)


def test_a_swing_trade_carrying_the_other_sides_cell_or_stream_is_refused(tmp_path):
    protocol, registration, identities = swing_campaign(tmp_path, trades=(('BTCUSDT', 'short', 2, 4),))
    for name, change, reason in (
            ('trades', {'base_setup_hash': identities['long']['base_setup_hash']}, 'ledger_identity_conflict'),
            ('plans', {'signal_run_id': 'synthetic-long'}, 'ledger_source_binding_conflict')):
        path = tmp_path / f'{name}.ndjson'
        original = path.read_bytes()
        row = json.loads(original)
        row.update(change)
        if name == 'plans':
            row['plan_hash'] = canonical_hash({k: v for k, v in row.items() if k not in ('plan_hash', 'ledger_sequence')})
        path.write_bytes((json.dumps(row) + '\n').encode())
        status = json.loads((tmp_path / 'status.json').read_bytes())
        status['files'][name + '.ndjson'].update(sha256=hashlib.sha256(path.read_bytes()).hexdigest(),
                                                 bytes=len(path.read_bytes()))
        (tmp_path / 'status.json').write_bytes(canonical_bytes(status))
        with pytest.raises(StatisticsError, match=reason):
            verify_campaign(tmp_path, protocol, registration)
        path.write_bytes(original)


# ------------------------------------------------------------------------- orchestration
def swing_report(root, phase, group, profile):
    root.mkdir(mode=0o700, exist_ok=True)
    value = dict(schema='research-signal-run.v1', status='complete', errors=[], profile=profile,
                 symbols={s: {} for s in group}, code_sha256={'synthetic': 'a' * 64})
    value.update(zip(('source_start', 'score_start', 'source_end'), o.WINDOWS[phase]))
    (root / 'report.json').write_text(json.dumps(value))


@pytest.fixture
def swing_setup(tmp_path):
    from tests.test_research_orchestration import Child, Clock, Stages
    sources = []
    for name in ('dataset', 'app', 'b1', *(f'train{i}' for i in range(4))):
        path = tmp_path / name
        path.mkdir(mode=0o700)
        sources.append(path)
    instrument, costs, python = (tmp_path / n for n in ('instrument.json', 'cost.json', 'python'))
    for path in (instrument, costs, python):
        path.write_text('{}')
    config = o.Config(sources[0], sources[1], sources[2], python, tuple(sources[3:]),
        tuple(tmp_path / f'val{i}' for i in range(4)), instrument, costs, tmp_path / 'registry',
        tmp_path / 'reports', tmp_path / 'operations', timeout=90, poll_interval=10, family='swing',
        aggregate_bytes=4 * 4 * 1024 ** 3)
    clock, stages, calls = Clock(), Stages(), []
    layout = [(profile, group) for profile in ('swing.long', 'swing.short') for group in o.GROUPS]

    def popen(argv, **kwargs):
        calls.append((argv, kwargs))
        swing_report(config.validation_roots[len(calls) - 1], 'validation', *reversed(layout[len(calls) - 1]))
        return Child()

    for root, (profile, group) in zip(config.training_roots, layout):
        swing_report(root, 'training', group, profile)
    return config, clock, stages, calls, popen


def test_a_swing_orchestration_waits_for_and_launches_one_b1_run_per_side_and_group(swing_setup):
    c, clock, stages, calls, popen = swing_setup
    result = o.run(c, clock=clock, sleep=clock.sleep, stages=stages, popen=popen,
                   stop=lambda child: setattr(child, 'stopped', True))
    assert result['state'] == 'no_eligible_candidate'
    launched = [(argv[argv.index('--profile') + 1], argv[argv.index('--symbols') + 1]) for argv, _ in calls]
    assert launched == [(p, ','.join(g)) for p in ('swing.long', 'swing.short') for g in o.GROUPS]


def test_a_swing_orchestration_needs_four_roots_per_phase_and_profiled_reports(swing_setup):
    from dataclasses import replace
    c = swing_setup[0]
    for change in ({'training_roots': c.training_roots[:2]}, {'validation_roots': c.validation_roots[:2]},
                   {'family': 'scalping'}):
        with pytest.raises(o.OrchestrationError):
            o._validate(replace(c, **change))
    # A day_trading report in a swing root is a conflict, not a pending run.
    swing_report(c.training_roots[1], 'training', o.GROUPS[1], None)
    with pytest.raises(o.OrchestrationError, match='group/window conflict'):
        o._report(c.training_roots[1], 'training', o.GROUPS[1], 'swing.long')


def test_the_swing_protocol_stage_binds_each_sides_baseline_and_runs(swing_setup, monkeypatch):
    from types import SimpleNamespace
    c = swing_setup[0]
    codemap = {}
    for codepath in o._b1_files(c):
        codepath.parent.mkdir(parents=True, exist_ok=True)
        codepath.write_text('synthetic B1')
        codemap[str(codepath)] = o.signals._file_sha256(codepath)
    instruments = {'symbols': {'synthetic': 1}}
    instruments['manifest_hash'] = canonical_hash(instruments)
    costs = {'profiles': {'baseline': {'spread': 1}, 'adverse': {'spread': 2}}}
    costs['assumption_hash'] = canonical_hash(costs)
    c.instrument_path.write_bytes(canonical_bytes(instruments))
    c.cost_path.write_bytes(canonical_bytes(costs))
    baselines = {side: {k: 'sha256:' + (('a' if side == 'long' else 'b') if k == 'setup_hash' else 'c') * 64
                        for k in ('setup_hash', 'config_hash', 'condition_catalog_hash', 'snapshot_hash')} for side in SIDES}

    def verify(dataset, roots, app, symbols, phase, deadline):
        assert roots == (c.training_roots if phase == 'training' else c.validation_roots)
        bindings = {f'{s}:{side}': (None, None, {'baseline': dict(baselines[side])}, None,
                                    {'symbol': s, 'phase': phase, 'side': side}) for s in symbols for side in SIDES}
        return [{'code_sha256': dict(codemap), 'report_sha256': 'raw-report'}], bindings, o.WINDOWS[phase], {}

    monkeypatch.setattr(o.campaign, '_verify_reports', verify)
    monkeypatch.setattr(o.campaign, '_runner_code', lambda: {'campaign': 'b' * 64})
    monkeypatch.setattr(o.plans, 'code_inventory', lambda app: {'php': 'c' * 64})
    monkeypatch.setattr(o, 'FundingReader', lambda *a, **k: SimpleNamespace(
        inventory=SimpleNamespace(inventory_hash='sha256:' + 'd' * 64)))
    protocol = o.Stages().protocol(c, 90)
    _validate_protocol(protocol)
    assert protocol['research_family'] == 'swing'
    assert protocol['cells']['short']['base_setup_hash'] == baselines['short']['setup_hash']
    assert protocol['identity']['base_setup_hash'] == composite_hash({s: baselines[s]['setup_hash'] for s in SIDES})
    assert protocol['identity']['base_config_hash'] == composite_hash({s: baselines[s]['config_hash'] for s in SIDES})
    runs = protocol['phase_bindings']['validation']['source_runs']
    assert [(r['symbol'], r['side']) for r in runs['short']] == [(s, 'short') for s in o.SYMBOLS]

    # A side whose baseline differs between pairs or phases is refused.
    def drifting(dataset, roots, app, symbols, phase, deadline):
        reports, bindings, windows, current = verify(dataset, roots, app, symbols, phase, deadline)
        if phase == 'validation':
            bindings[f'{o.SYMBOLS[3]}:short'][2]['baseline']['setup_hash'] = 'sha256:' + 'e' * 64
        return reports, bindings, windows, current

    monkeypatch.setattr(o.campaign, '_verify_reports', drifting)
    with pytest.raises(o.OrchestrationError, match='baseline differs'):
        o.Stages().protocol(c, 90)


def test_the_swing_report_names_its_family(tmp_path):
    from app.backtesting.research.experiments import write_reports
    protocol = swing_protocol()
    with ExperimentRegistry(tmp_path / 'private', protocol, min_free_bytes=0) as registry:
        paths = write_reports(registry, tmp_path / 'reports')
        assert 'Famille swing' in paths['markdown'].read_text()
    with ExperimentRegistry(tmp_path / 'day', make_protocol(IDENTITY), min_free_bytes=0) as registry:
        assert 'Famille swing' not in write_reports(registry, tmp_path / 'day-reports')['markdown'].read_text()
