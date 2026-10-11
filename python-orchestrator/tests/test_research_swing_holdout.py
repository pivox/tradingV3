"""The swing candidate's guarded holdout campaign (swing campaign PR 5b).

The original day_trading campaign keeps its own suites; these tests cover the second campaign:
its spec, its claim and its retained scope, all synthetic.
"""
from __future__ import annotations

import json
from pathlib import Path

import pytest

from app.backtesting.research import experiments, holdout_authority as h
from app.backtesting.research.portfolio_simulator import SWING_PROFILE, canonical_hash
from tests.test_research_holdout_authority import REAL_TRANSPORT, fixture, retained, transport  # noqa: F401 (fixtures)

CELLS = {side: {k: canonical_hash({'side': side, 'field': k}) for k in experiments.CELL_IDENTITIES}
         for side in ('long', 'short')}


@pytest.fixture
def swing(fixture):
    """``fixture``'s registry and paths installed as the swing campaign; the day authority is absent."""
    f = fixture
    f.protocol.update(research_family='swing', cells=CELLS, variants=[{'id': 'baseline', 'diff': {}}],
                      kernel_profile=json.loads(h.canonical_bytes(SWING_PROFILE.wire())),
                      risk_assumptions=dict(h.SWING_RISK_ASSUMPTIONS))
    registry = Path(f.config['registry'])
    (registry / 'protocol.json').write_bytes(h.canonical_bytes(f.protocol))
    f.config.update(campaign_id=h.SWING_CAMPAIGN_ID,
                    protocol_hash=h.hash_bytes(h.canonical_bytes(f.protocol)))
    f.freeze['protocol_hash'] = f.config['protocol_hash']
    f.selected()
    f.installed.unlink()
    f.installed = h._authority_path(h.SWING_CAMPAIGN_ID)
    f.installed.write_bytes(h.canonical_bytes(f.config))
    f.installed.chmod(0o600)
    f.authority = lambda: h.load_campaign_authority(h.SWING_CAMPAIGN_ID)
    f.claim = lambda **kwargs: h.prepare_claim(f.authority(), f.output, **kwargs)
    return f


def test_the_swing_campaign_has_its_own_spec_and_stays_unpinned_until_its_frozen_commit():
    day, swing_spec = h.campaign_spec(), h.campaign_spec(h.SWING_CAMPAIGN_ID)
    assert (day.campaign_id, day.family, day.profiles) == (h.CAMPAIGN_ID, 'day_trading', ())
    assert (swing_spec.family, swing_spec.profiles) == ('swing', ('swing.long', 'swing.short'))
    assert (swing_spec.source_start, swing_spec.score_start, swing_spec.end) == (day.source_start, day.score_start, day.end)
    assert dict(swing_spec.risk_assumptions) == experiments.RISK_ASSUMPTIONS['swing']
    assert dict(day.risk_assumptions) == experiments.RISK_ASSUMPTIONS['day_trading']
    assert h._authority_path(h.SWING_CAMPAIGN_ID).name == 'swing-2026-10-11.json'
    with pytest.raises(h.HoldoutError, match='unknown_holdout_campaign'):
        h.campaign_spec('scalping-2026')
    with pytest.raises(h.HoldoutError, match='campaign_frozen_commit_not_pinned'):
        h._trusted_repository_inventory(swing_spec)


def test_a_swing_claim_names_its_family_profiles_and_cells(swing):
    f = swing
    auth = f.claim()
    contract = h.require_claim(auth, operation='signals', output_root=f.output / 'signals')
    assert contract['campaign_id'] == h.SWING_CAMPAIGN_ID
    assert (contract['family'], contract['profiles']) == ('swing', ['swing.long', 'swing.short'])
    assert contract['cells'] == CELLS and contract['kernel_profile'] == f.protocol['kernel_profile']
    assert contract['risk_assumptions'] == dict(h.SWING_RISK_ASSUMPTIONS)
    assert [(s['variant_id'], s['cost_profile']) for s in contract['slots']] == [('baseline', 'baseline'),
                                                                                 ('baseline', 'adverse')]
    claim = json.loads((f.anchor / 'claim.json').read_bytes())
    assert claim['campaign_id'] == json.loads((f.anchor / 'authority.json').read_bytes())['campaign_id'] == h.SWING_CAMPAIGN_ID
    assert not h._authority_path().exists()
    # The read-only replay derives the same fixed scope again.
    replay = h.mint_retained_authorization(f.authority())
    assert h.require_claim(replay, operation='retained')['cells'] == CELLS
    with pytest.raises(h.HoldoutError, match='already_claimed'):
        h.prepare_claim(f.authority(), f.other_output)


@pytest.mark.parametrize('change', [{'cells': {**CELLS, 'short': CELLS['long']}}, {'profiles': ['swing.long']},
                                    {'family': 'day_trading'}, {'risk_assumptions': dict(h.RISK_ASSUMPTIONS)}])
def test_a_retained_swing_contract_cannot_change_its_scope(swing, change):
    f = swing
    auth = f.claim()
    contract = json.loads(auth.contract_bytes)
    authority = f.authority()
    protocol, freeze, _ = h._verified_freeze(authority)
    h._validate_retained_scope(authority, contract, protocol, freeze)
    with pytest.raises(h.HoldoutError, match='retained_contract_scope_conflict'):
        h._validate_retained_scope(authority, {**contract, **change}, protocol, freeze)


def test_a_day_contract_cannot_carry_swing_scope(fixture):
    f = fixture
    auth = f.claim()
    contract = json.loads(auth.contract_bytes)
    authority = f.authority()
    protocol, freeze, _ = h._verified_freeze(authority)
    with pytest.raises(h.HoldoutError, match='retained_contract_scope_conflict'):
        h._validate_retained_scope(authority, {**contract, 'cells': CELLS}, protocol, freeze)


@pytest.mark.parametrize('change', [{'research_family': None}, {'risk_assumptions': dict(h.RISK_ASSUMPTIONS)}])
def test_the_swing_campaign_refuses_a_foreign_protocol(swing, change):
    f = swing
    protocol = {k: v for k, v in {**f.protocol, **change}.items() if v is not None}
    registry = Path(f.config['registry'])
    (registry / 'protocol.json').write_bytes(h.canonical_bytes(protocol))
    f.config['protocol_hash'] = f.freeze['protocol_hash'] = h.hash_bytes(h.canonical_bytes(protocol))
    f.selected()
    f.installed.write_bytes(h.canonical_bytes(f.config))
    with pytest.raises(h.HoldoutError, match='frozen_protocol_scope_conflict'):
        f.claim()
    assert not (f.anchor / 'claim.json').exists()


def test_a_swing_authority_installs_only_from_a_pinned_swing_orchestration(retained, monkeypatch):
    f = retained
    frozen = {name: h._file_hash(Path(f.config['paths']['frozen_python_root']) / name) for name in h.FROZEN_FILES}
    with pytest.raises(h.HoldoutError, match='retained_campaign_family_conflict'):
        h.install_campaign_authority(f.attempt, f.snapshot, campaign_id=h.SWING_CAMPAIGN_ID)
    attempt = json.loads(f.attempt.read_bytes())
    attempt['config']['family'] = 'swing'
    f.write(f.attempt, attempt)
    f.terminal_value['attempt_sha256'] = h._file_hash(f.attempt)
    f.write(f.terminal, f.terminal_value)
    # Until the swing pin lands, its frozen verifier inventory does not exist.
    monkeypatch.setattr(h, '_trusted_repository_inventory', h_real_inventory)
    with pytest.raises(h.HoldoutError, match='campaign_frozen_commit_not_pinned'):
        h.install_campaign_authority(f.attempt, f.snapshot, campaign_id=h.SWING_CAMPAIGN_ID)
    monkeypatch.setattr(h, '_trusted_repository_inventory', lambda spec=None: frozen)
    # A swing attempt cannot install the day campaign either.
    with pytest.raises(h.HoldoutError, match='retained_campaign_family_conflict'):
        h.install_campaign_authority(f.attempt, f.snapshot)
    authority = h.install_campaign_authority(f.attempt, f.snapshot, campaign_id=h.SWING_CAMPAIGN_ID)
    assert authority.campaign_id == h.SWING_CAMPAIGN_ID and authority.spec.family == 'swing'
    assert h._authority_path(h.SWING_CAMPAIGN_ID).exists() and not h._authority_path().exists()
    assert h.load_campaign_authority(h.SWING_CAMPAIGN_ID) == authority
    with pytest.raises(h.HoldoutError, match='already_installed'):
        h.install_campaign_authority(f.attempt, f.snapshot, campaign_id=h.SWING_CAMPAIGN_ID)



def test_the_real_frozen_verifier_bootstrap_reads_the_swing_authority(transport, monkeypatch):
    f = transport
    f.config['campaign_id'] = h.SWING_CAMPAIGN_ID
    installed = h._authority_path(h.SWING_CAMPAIGN_ID)
    installed.write_bytes(h.canonical_bytes(f.config)); installed.chmod(0o600)
    f.installed.unlink()
    monkeypatch.setattr(h, '_trusted_repository_inventory', lambda spec=None: f.files)
    authority = h.load_campaign_authority(h.SWING_CAMPAIGN_ID)
    freeze = (Path(f.config['registry'])/'selection-freeze.json').read_bytes()
    assert REAL_TRANSPORT(authority, timeout=5) == freeze
    # The subprocess re-reads the installed file: one naming another campaign is refused.
    installed.write_bytes(h.canonical_bytes({**f.config, 'campaign_id': h.CAMPAIGN_ID}))
    with pytest.raises(h.HoldoutError):
        REAL_TRANSPORT(authority, timeout=5)


h_real_inventory = h._trusted_repository_inventory


# ------------------------------------------------------------------------------- engine
@pytest.fixture
def swing_engine(fixture, tmp_path, monkeypatch, request):
    """The guarded swing holdout on three synthetic 2026 minutes and 1000 warmup hours.

    Both swing sides of all ten pairs are claimed. BTC passes on both sides (its short meets the
    one-plan-per-pair rule), ETH passes short only, the other pairs fail. Only the plan worker
    is synthetic; with ``request.param == 'controller'`` the B1 worker transport is synthetic too.
    """
    import copy
    import dataclasses
    import hashlib
    from app.backtesting.research import campaign, signal_sources, signals
    from app.backtesting.research.signal_sources import FOUR_HOURS_MS, RESEARCH_PROFILES
    from app.modern_trading_contracts import calculate_config_hash, calculate_snapshot_hash
    from tests import test_research_campaign as upstream
    from tests.test_research_holdout import _materialize_engine_sources
    from tests.test_research_signals import result_fixture
    from tests.test_research_swing_plumbing import swing_evidence
    from tests.test_research_swing_streams import SwingBuilder

    controller = getattr(request, 'param', None) == 'controller'
    base = tmp_path/'engine'; base.mkdir(mode=0o700)
    start = 1767225600000
    source_start, end = start-250*FOUR_HOURS_MS, start+180000
    monkeypatch.setattr(upstream, 'START', start)
    monkeypatch.setattr(upstream, 'SOURCE_START', source_start)
    kwargs, report, _ = upstream.fixture(base, monkeypatch, passed=True, symbols=h.SYMBOLS)
    dataset = kwargs['dataset_root']
    _materialize_engine_sources(dataset, source_start, start, end, h.SYMBOLS)
    manifest_sha = hashlib.sha256((dataset/'manifest.json').read_bytes()).hexdigest()
    selections = {s: signal_sources._select_sources_validated(dataset, signal_sources._utc(upstream.iso(source_start)),
        signal_sources._utc(upstream.iso(end)), signal_sources._utc(upstream.iso(start)), s, step=FOUR_HOURS_MS)
        for s in h.SYMBOLS}
    reader = campaign.FundingReader
    class BoundFunding(reader):
        def __init__(self, *args, **kw):
            super().__init__(*args, **kw)
            self.inventory = dataclasses.replace(self.inventory, acquisition_manifest_sha256=manifest_sha)
            data = dataclasses.asdict(self.inventory); data.pop('inventory_hash')
            self.inventory = dataclasses.replace(self.inventory, inventory_hash=canonical_hash(data))
    monkeypatch.setattr(campaign, 'FundingReader', BoundFunding)
    monkeypatch.setattr(h, 'SOURCE_START', upstream.iso(source_start))
    monkeypatch.setattr(h, 'SCORE_START', upstream.iso(start))
    monkeypatch.setattr(h, 'END', upstream.iso(end))

    # One B1 root per side: the side's swing evidence, baseline, results and report.
    passed = {('BTCUSDT', 'long'), ('BTCUSDT', 'short'), ('ETHUSDT', 'short')}
    staging = base/'swing-signals'; staging.mkdir(mode=0o700)
    baselines, cells = {}, {}
    for name in h.SWING_PROFILES:
        profile = RESEARCH_PROFILES[name]
        evidence = swing_evidence(kwargs['app_dir'], profile)
        snapshot = evidence['snapshot']
        snapshot['config']['setup']['ast'] = {'execution': {'targets': {'value': [{'liquidity_role': 'maker'}]},
            'cost_contract': {'value': {'entry_liquidity_role': 'maker', 'stop_liquidity_role': 'taker',
                                        'funding_interval_seconds': 28800}}, 'time_stop': {'value': 'PT336H'}}}
        snapshot['config']['exchange'].update({'fees': {'maker_rate': .0002, 'taker_rate': .0005},
                                               'funding': {'interval': 'PT8H'}})
        snapshot['config_hash'] = calculate_config_hash(snapshot['config'], snapshot['condition_catalog_hash'])
        snapshot['snapshot_hash'] = calculate_snapshot_hash(snapshot)
        baseline = {**evidence['baseline'], 'config_hash': snapshot['config_hash'], 'snapshot_hash': snapshot['snapshot_hash'],
                    'setup_hash': ('b' if profile.side == 'long' else 'c')*64}
        baselines[profile.side] = baseline
        cells[profile.side] = {'base_setup_hash': baseline['setup_hash'], 'base_config_hash': baseline['config_hash'],
            'base_catalog_hash': baseline['condition_catalog_hash'], 'base_snapshot_hash': baseline['snapshot_hash']}
        root = staging/name; root.mkdir(mode=0o700)
        cell = copy.deepcopy(report); cell['profile'] = name
        for s, meta in cell['symbols'].items():
            selection = selections[s]
            frame = result_fixture(start)
            frame.update({'session_id': signals.signal_session_id(s, selection.dataset_sha256, profile), 'symbol': s,
                'source': campaign._source(selection), 'execution': evidence['execution'], 'baseline': baseline,
                'passed': (s, profile.side) in passed})
            if frame['passed']:
                frame['reason_code'] = 'setup_rules_passed'
            frame['result_hash'] = canonical_hash({k: v for k, v in frame.items() if k != 'result_hash'})
            raw = (json.dumps(frame)+'\n').encode()
            (root/(s+'.results.ndjson')).write_bytes(raw)
            verdict = int(frame['passed'])
            meta.update({'source_candles': selection.expected_candles, 'evaluated_ticks': selection.expected_evaluations,
                'scored_evaluations': 1, 'passed_rules': verdict, 'failed_rules': 1-verdict, 'scored_passed_rules': verdict,
                'scored_failed_rules': 1-verdict, 'boundary_diagnostic_count': 0, 'result_sha256': hashlib.sha256(raw).hexdigest(),
                'dataset_sha256': selection.dataset_sha256, 'manifest_sha256': manifest_sha, 'baseline': baseline,
                'effective_config_snapshot': snapshot, 'execution': evidence['execution']})
        cell['totals'] = {'completed_symbols': len(h.SYMBOLS),
            'source_candles': sum(m['source_candles'] for m in cell['symbols'].values()),
            'evaluated_ticks': sum(m['evaluated_ticks'] for m in cell['symbols'].values()),
            'scored_evaluations': len(h.SYMBOLS)}
        (root/'report.json').write_text(json.dumps(cell, sort_keys=True))
        for path in root.iterdir():
            path.chmod(0o600)

    # The swing authority over the engine's paths; the frozen identities name each side's baseline.
    f = fixture
    for key in ('dataset_root', 'app_dir', 'instrument_path', 'cost_path'):
        f.config['paths'][key] = str(kwargs[key])
    ip, cp = json.loads(kwargs['instrument_path'].read_bytes()), json.loads(kwargs['cost_path'].read_bytes())
    php = campaign.plans.code_inventory(kwargs['app_dir'])
    composite = {k: canonical_hash({'long': cells['long'][k], 'short': cells['short'][k]}) for k in cells['long']}
    f.protocol.update(research_family='swing', cells=cells, variants=[{'id': 'baseline', 'diff': {}}],
        kernel_profile=json.loads(h.canonical_bytes(SWING_PROFILE.wire())), risk_assumptions=dict(h.SWING_RISK_ASSUMPTIONS),
        cutoff=h.END)
    registry = Path(f.config['registry'])
    (registry/'protocol.json').write_bytes(h.canonical_bytes(f.protocol))
    f.config.update(campaign_id=h.SWING_CAMPAIGN_ID, protocol_hash=h.hash_bytes(h.canonical_bytes(f.protocol)))
    f.freeze.update(protocol_hash=f.config['protocol_hash'], cutoff=h.END)
    f.selected()
    f.installed.unlink()
    f.installed = h._authority_path(h.SWING_CAMPAIGN_ID)
    f.installed.write_bytes(h.canonical_bytes(f.config)); f.installed.chmod(0o600)
    f.authority = lambda: h.load_campaign_authority(h.SWING_CAMPAIGN_ID)
    identity = {'baselines': baselines, 'php': php,
        'instrument_sha256': hashlib.sha256(kwargs['instrument_path'].read_bytes()).hexdigest(),
        'cost_sha256': hashlib.sha256(kwargs['cost_path'].read_bytes()).hexdigest(),
        'source_manifest_sha256': manifest_sha, 'new_code': h._new_code_inventory(),
        'protocol_identity': {**composite, 'instrument_assumptions_hash': ip['manifest_hash'],
            'research_code_hash': canonical_hash(php),
            **{p+'_cost_hash': canonical_hash(cp['profiles'][p]) for p in ('baseline', 'adverse')}}}
    monkeypatch.setattr(h, '_preflight_identities', lambda a, p: identity)

    class BoundBuilder(SwingBuilder):
        calls = []  # its own list: SwingBuilder's is shared with the campaign tests

        def build(self, signal, view):
            result = super().build(signal, view)
            if result['schema_version'] == 'research-plan.v1':
                result['cost_profile'] = self.opening['cost_profile']
                result['plan_hash'] = canonical_hash({k: v for k, v in result.items() if k != 'plan_hash'})
            return result
    monkeypatch.setattr(campaign.plans, 'PlanWorker', BoundBuilder)
    if controller:
        calls = []
        def worker(root, out, app, selection, argv, timeout, stderr, capacity, profile=None):
            assert (f.anchor/'claim.json').exists()
            calls.append((profile.id, selection.symbol))
            raw = (staging/profile.id/(selection.symbol+'.results.ndjson')).read_bytes()
            (out/(selection.symbol+'.results.ndjson')).write_bytes(raw)
            (out/(selection.symbol+'.results.ndjson')).chmod(0o600)
            return json.loads((staging/profile.id/'report.json').read_bytes())['symbols'][selection.symbol]
        monkeypatch.setattr(signals, '_run_symbol', worker)
        monkeypatch.setattr(signals, '_worker_argv', lambda app, supplied: ('synthetic-private-worker',))
        return f, calls
    auth = h.prepare_claim(f.authority(), f.output)
    auth.output_root.mkdir(mode=0o700)
    staging.rename(auth.output_root/'signals')
    (auth.output_root/'units'/'baseline').mkdir(mode=0o700, parents=True)
    (auth.output_root/'units').chmod(0o700)
    return auth, baselines


def test_a_swing_holdout_unit_binds_runs_and_verifies_both_sides(swing_engine):
    from app.backtesting.research import campaign, statistics
    auth, baselines = swing_engine
    slot = auth.for_slot('baseline', 'baseline')
    binding = campaign.publish_holdout_unit_inputs(auth.output_root/'signals', authorization=slot,
                                                   variant_id='baseline', cost_profile='baseline')
    assert [(c['profile'], c['side']) for c in binding['cells']] == [('swing.long', 'long'), ('swing.short', 'short')]
    assert not {'baseline', 'variant', 'source_runs'} & set(binding)
    assert binding['identity']['short']['base_setup_hash'] == baselines['short']['setup_hash']
    assert set(binding['sources']) == {f'{s}:{side}' for s in h.SYMBOLS for side in ('long', 'short')}
    assert binding['b1_counters'] == {'evaluated_ticks': 20, 'passed_rules': 3, 'failed_rules': 17}
    result = campaign.run_holdout_campaign(auth.output_root/'signals', authorization=slot,
                                           variant_id='baseline', cost_profile='baseline')
    assert result['phase'] == 'holdout' and result['trades'] == 2
    assert {side: (s['received'], s['planned']) for side, s in result['planner_summary'].items()} == {
        'long': (1, 1), 'short': (2, 1)}
    root = auth.output_root/'units'/'baseline'/'baseline'
    verified = statistics.verify_holdout_campaign(root, authorization=slot, variant_id='baseline',
                                                  cost_profile='baseline')
    assert verified['independently_reconciled'] is True and verified['closed_trades'] == 2
    assert verified['net_pnl_quote'] == str(result['net_pnl_quote'])
    assert verified['kernel_profile'] == SWING_PROFILE.wire()
    retained = h.mint_retained_authorization(h.load_campaign_authority(h.SWING_CAMPAIGN_ID)).for_slot('baseline', 'baseline')
    assert statistics.verify_holdout_campaign(root, authorization=retained, variant_id='baseline',
                                              cost_profile='baseline') == verified


@pytest.mark.parametrize('change', ['short_report', 'short_rows', 'cells'])
def test_a_swing_holdout_binding_is_checked_per_side_before_profit(swing_engine, monkeypatch, change):
    from app.backtesting.research import campaign, statistics
    auth, _ = swing_engine
    slot = auth.for_slot('baseline', 'baseline')
    campaign.publish_holdout_unit_inputs(auth.output_root/'signals', authorization=slot,
                                         variant_id='baseline', cost_profile='baseline')
    short = auth.output_root/'signals'/'swing.short'
    if change == 'short_report':
        path = short/'report.json'
        path.write_bytes(path.read_bytes()+b' ')
    elif change == 'short_rows':
        path = short/'ETHUSDT.results.ndjson'
        path.write_bytes(path.read_bytes()+b' ')
    else:
        path = auth.output_root/'inputs'/'baseline'/'baseline.json'
        value = json.loads(path.read_bytes())
        value['cells'].reverse()
        path.write_bytes(h.canonical_bytes(value))
    def profit(*args): pytest.fail('profit read before bound inputs denied')
    monkeypatch.setattr(statistics, '_read_json', profit)
    with pytest.raises((campaign.CampaignError, h.HoldoutError)):
        statistics.verify_holdout_campaign(auth.output_root/'units'/'baseline'/'baseline', authorization=slot,
                                           variant_id='baseline', cost_profile='baseline')


@pytest.mark.parametrize('swing_engine', ['controller'], indirect=True)
def test_the_swing_holdout_controller_runs_both_profiles_and_replays_read_only(swing_engine, monkeypatch):
    from app.backtesting.research import campaign, holdout, signals
    f, calls = swing_engine
    report = holdout.run_holdout(f.output, campaign_id=h.SWING_CAMPAIGN_ID)
    assert report['holdout_status'] == 'complete' and report['campaign_id'] == h.SWING_CAMPAIGN_ID
    assert calls == [(p, s) for p in h.SWING_PROFILES for s in h.SYMBOLS]
    assert set(report['python_transition']['new_b1_code_identity']) == set(h.SWING_PROFILES)
    assert len(report['units']) == 2
    for unit in report['units']:
        assert unit['status'] == 'complete' and unit['independently_verified']
        assert unit['statistics']['closed_trades'] == 2
    def forbidden(*a, **kw): pytest.fail('retained replay generated or simulated')
    monkeypatch.setattr(signals, 'run_holdout_signals', forbidden)
    monkeypatch.setattr(campaign, 'run_holdout_campaign', forbidden)
    assert holdout.verify_retained_holdout(h.SWING_CAMPAIGN_ID) == report
    with pytest.raises(h.HoldoutError, match='already_claimed'):
        holdout.run_holdout(f.other_output, campaign_id=h.SWING_CAMPAIGN_ID)
