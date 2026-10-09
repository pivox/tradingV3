"""Synthetic experiment identities and results; no historical data."""
import copy
import json
import os
from pathlib import Path

import pytest

from app.backtesting.research.experiments import (
    ExperimentError, ExperimentRegistry, make_protocol, screening_reasons, rank_candidates,
    schedule_batch, freeze_selection, verify_freeze, write_reports,
    hash_bytes, canonical_bytes,
)
from app.backtesting.research.portfolio_simulator import SYMBOLS
from app.backtesting.research.portfolio_simulator import canonical_hash
from tests.test_research_statistics import campaign_fixture, write_ledgers, rehash_campaign, FIELDS, alter_funding_input, read_rows

HASH = 'a' * 64
IDENTITY = {k: HASH for k in ('dataset_hash', 'signal_code_hash', 'research_code_hash',
    'base_setup_hash', 'base_config_hash', 'base_catalog_hash', 'base_snapshot_hash',
    'instrument_assumptions_hash', 'baseline_cost_hash', 'adverse_cost_hash')}


def bound_protocol():
    binding = {'source_runs':[],'signal_reports':[], 'runner_code_sha256':{},'cost_assumptions_hash':HASH,
        'funding_inventory_hash':HASH,'funding_diagnostic_policy':'synthetic_funding_diagnostic.v1'}
    return make_protocol(IDENTITY,phase_bindings={p:binding for p in ('training','validation')})


def metrics(phase='training'):
    years = ('2023', '2024') if phase == 'training' else ('2025',)
    return {'status': 'complete', 'independently_reconciled': True,
        'coverage_complete': True, 'cost_evidence_complete': True,
        'closed_trades': 100 if phase == 'training' else 50,
        'pairs_with_trades': 5, 'mean_realized_net_r': '0.1', 'net_profit_factor': '1.2',
        'maximum_drawdown_quote': '6000',
        'per_year': {year: {'closed_trades': 50, 'cashflow_net_pnl_quote': '1'} for year in years}}


def test_protocol_freezes_exact_batch_and_risk():
    protocol = make_protocol(IDENTITY)
    assert len(protocol['variants']) == 13
    assert protocol['symbol_priority'] == list(SYMBOLS)
    assert protocol['cutoff'] == '2026-10-09T06:00:00Z'
    assert protocol['initial_wallet_quote'] == '100000'
    assert protocol['thresholds']['minimum_training_trades'] == 100
    assert set(protocol['selection_code_sha256']) == {'statistics.py','experiments.py'}
    assert protocol['variants'][0] == {'id':'baseline', 'diff': {}}
    assert protocol['variants'][-1]['diff'] == {'anchor_source': 'ema_20', 'zone_atr_multiplier': 0.5}
    with pytest.raises(ExperimentError):
        make_protocol({})


def test_prespecified_edges_zero_and_net_annual_rules():
    assert screening_reasons(metrics(), 'training', 'baseline') == []
    assert screening_reasons(metrics('validation'), 'validation', 'adverse') == []
    for key, value, reason in [
        ('closed_trades', 99, 'insufficient_trades'), ('pairs_with_trades',4,'insufficient_pairs'),
        ('mean_realized_net_r','0','nonpositive_mean_net_r'),
        ('maximum_drawdown_quote','6000.0001','marked_drawdown_exceeded'),
        ('net_profit_factor',None,'undefined_net_profit_factor'),
        ('net_profit_factor','1.1999','net_profit_factor_below_threshold'),
        ('coverage_complete',False,'missing_coverage_evidence'),
        ('cost_evidence_complete',False,'missing_cost_evidence'),
        ('independently_reconciled',False,'unreconciled_evidence'), ('status','failed','incomplete_evidence')]:
        row = metrics(); row[key] = value
        assert reason in screening_reasons(row, 'training', 'baseline')
    row = metrics(); row['per_year']['2023']['closed_trades'] = 29
    assert 'insufficient_annual_training_trades:2023' in screening_reasons(row,'training','baseline')
    row['per_year']['2024']['cashflow_net_pnl_quote'] = '0'
    assert 'nonpositive_annual_net_pnl:2024' in screening_reasons(row,'training','baseline')
    with pytest.raises(ExperimentError):
        screening_reasons(metrics(),'holdout','baseline')


def candidate(identifier='baseline', mean='0.1', drawdown='20'):
    row = {'variant_id':identifier, 'training':{}, 'validation':{}}
    for phase in ('training','validation'):
        for profile in ('baseline','adverse'):
            result = metrics(phase)
            result.update(mean_realized_net_r=mean, maximum_drawdown_quote=drawdown)
            row[phase][profile] = result
    return row


def test_ranking_worst_validation_then_drawdown_complexity_and_order():
    rows = [candidate('ema20_15m'),candidate('width_050'),candidate('baseline'),candidate('ema20_5m')]
    assert [r['variant_id'] for r in rank_candidates(rows, make_protocol(IDENTITY))] == [
        'baseline','ema20_5m','width_050','ema20_15m']
    rows[0]['validation']['adverse']['mean_realized_net_r'] = '0.05'
    rows[1]['validation']['baseline']['maximum_drawdown_quote'] = '10'
    assert rank_candidates(rows,make_protocol(IDENTITY))[0]['variant_id'] == 'baseline'
    rows[0]['training']['baseline']['closed_trades'] = 0
    assert len(rank_candidates(rows,make_protocol(IDENTITY))) == 3


def test_registration_is_immutable_interruptions_have_new_identity(tmp_path):
    root = tmp_path / 'private'
    protocol = make_protocol(IDENTITY)
    with ExperimentRegistry(root,protocol,min_free_bytes=0) as registry:
        first = registry.register('baseline','baseline','training')
        assert first['attempt_id']
        assert (root/'attempts'/first['attempt_id']/'registration.json').is_file()
        second = registry.register('baseline','baseline','training')
        assert first['attempt_id'] != second['attempt_id']
        assert len(registry.attempts()) == 2
        registry.finish(second['attempt_id'], {'state':'failed','error':'synthetic'})
        with pytest.raises(ExperimentError):
            registry.finish(second['attempt_id'], {'state':'complete'})
        with pytest.raises(ExperimentError):
            registry.register('baseline','baseline','holdout')
        with pytest.raises(ExperimentError):
            with ExperimentRegistry(root,protocol,min_free_bytes=0):
                pass
    assert root.stat().st_mode & 0o777 == 0o700
    assert (root/'protocol.json').stat().st_mode & 0o777 == 0o600
    with ExperimentRegistry(root,protocol,min_free_bytes=0) as registry:
        assert next(row for row in registry.attempts() if row['registration']['attempt_id'] == first['attempt_id'])['terminal'] is None
    changed = copy.deepcopy(protocol); changed['thresholds']['minimum_training_trades'] = 1
    with pytest.raises(ExperimentError):
        with ExperimentRegistry(root,changed,min_free_bytes=0):
            pass


def synthetic_runner(registration, output_root):
    output_root.mkdir(mode=0o700)
    for name in ('manifest.json','status.json'):
        (output_root/name).write_text(json.dumps(registration))


def verifier(root, protocol, registration):
    # Only a dependency-injected synthetic fixture; actual verifier is strict.
    result = metrics(registration['phase'])
    if registration['variant_id'] != 'baseline':
        result['closed_trades'] = 0
    return result


def test_bounded_scheduler_all_training_first_and_only_eligible_validation(tmp_path):
    calls = []
    def runner(registration, root):
        calls.append((registration['phase'],registration['variant_id'],registration['cost_profile']))
        synthetic_runner(registration,root)
    with ExperimentRegistry(tmp_path/'private',make_protocol(IDENTITY),min_free_bytes=0) as registry:
        candidates = schedule_batch(registry,runner,verify_unit=verifier)
        assert len(calls) == 28
        assert all(phase == 'training' for phase,_,_ in calls[:26])
        assert all(phase == 'validation' for phase,_,_ in calls[26:])
        assert len(candidates) == 13
        assert candidates[1]['validation']['baseline']['status'] == 'not_run_training_ineligible'
        assert len(registry.attempts()) == 28
        assert schedule_batch(registry,runner,verify_unit=verifier) == candidates
        assert len(calls) == 28
        # Explicitly equal-result duplicate success policy is not enabled.
        duplicate = registry.register('baseline','baseline','training')
        synthetic_runner(duplicate,registry.root/'attempts'/duplicate['attempt_id']/'campaign')
        registry.finish(duplicate['attempt_id'], {'state':'complete'})
        with pytest.raises(ExperimentError,match='duplicate_success'):
            schedule_batch(registry,runner,verify_unit=verifier)


def test_failure_and_interrupted_retry_visible_no_winner_freeze(tmp_path):
    def failing(registration, root):
        raise RuntimeError('synthetic failure')
    with ExperimentRegistry(tmp_path/'private',bound_protocol(),min_free_bytes=0) as registry:
        interrupted = registry.register('baseline','baseline','training')
        candidates = schedule_batch(registry,failing,verify_unit=verifier)
        assert len(registry.attempts()) == 27
        assert all(row['terminal']['state'] == 'failed' for row in registry.attempts())
        assert any(row['registration']['attempt_id'] == interrupted['attempt_id'] for row in registry.attempts())
        freeze = freeze_selection(registry,candidates)
        assert freeze['selection_state'] == 'no_eligible_candidate'
        assert freeze['selected'] is None
        assert freeze['holdout_authorization'] == 'none'
        assert verify_freeze(registry.root/'selection-freeze.json')['selection_state'] == 'no_eligible_candidate'
        (registry.root/'selection-freeze.json').write_text('{}')
        with pytest.raises(ExperimentError):
            verify_freeze(registry.root/'selection-freeze.json')


def test_freeze_rejects_incomplete_or_forged_candidates(tmp_path):
    with ExperimentRegistry(tmp_path/'private',bound_protocol(),min_free_bytes=0) as registry:
        with pytest.raises(ExperimentError):
            freeze_selection(registry,[candidate()])
        candidates = schedule_batch(registry,lambda *_: None,verify_unit=verifier)
        forged = copy.deepcopy(candidates); forged[0] = candidate()
        with pytest.raises(ExperimentError,match='candidate_evidence'):
            freeze_selection(registry,forged)


def test_reports_include_failures_zero_missing_validation_and_french_limits(tmp_path):
    def fail(registration,root):
        raise RuntimeError('=synthetic_formula')
    with ExperimentRegistry(tmp_path/'private',bound_protocol(),min_free_bytes=0) as registry:
        candidates = schedule_batch(registry,fail,verify_unit=verifier)
        freeze_selection(registry,candidates)
        files = write_reports(registry,tmp_path/'rapport',candidates)
        report = json.loads(files['json'].read_bytes())
        assert len(report['attempts']) == 26
        assert report['holdout_status'] == 'pending'
        csv = files['csv'].read_text()
        assert "'=synthetic_formula" in csv
        text = files['markdown'].read_text()
        for term in ('Aucun candidat éligible','biais de sélection','survivance','Paper',
                     '100000','250','observed_only','2026-10-09T06:00:00Z','Fake'):
            assert term in text
        assert files['markdown'].stat().st_mode & 0o777 == 0o600
        with pytest.raises(ExperimentError):
            write_reports(registry,tmp_path/'rapport',candidates)


def test_actual_schedule_freeze_and_verify_reject_absent_phase_bindings(tmp_path):
    with ExperimentRegistry(tmp_path/'private',make_protocol(IDENTITY),min_free_bytes=0) as registry:
        with pytest.raises(ExperimentError,match='phase_bindings'):
            schedule_batch(registry,lambda *_: None)
        with pytest.raises(ExperimentError,match='phase_bindings'):
            freeze_selection(registry,[])


def test_pending_report_does_not_fabricate_zero_or_no_winner(tmp_path):
    with ExperimentRegistry(tmp_path/'private',bound_protocol(),min_free_bytes=0) as registry:
        registration = registry.register('baseline','baseline','training')
        paths = write_reports(registry,tmp_path/'rapport-pending')
        report = json.loads(paths['json'].read_bytes())
        assert report['selection_state'] == 'pending_required_units'
        assert report['attempts'][0]['terminal'] is None
        assert report['candidates'][0]['training']['baseline']['net_pnl_quote'] is None
        assert 'en attente' in paths['markdown'].read_text()


def test_interrupted_partial_artifacts_bound_and_mutable_protocol_rejected(tmp_path):
    root = tmp_path/'private'
    with ExperimentRegistry(root,bound_protocol(),min_free_bytes=0) as registry:
        registration = registry.register('baseline','baseline','training')
        campaign = root/'attempts'/registration['attempt_id']/'campaign'
        campaign.mkdir()
        (campaign/'cashflows.partial.ndjson').write_bytes(b'{"synthetic":"partial"}\n')
        rows = schedule_batch(registry,lambda *_: (_ for _ in ()).throw(RuntimeError('synthetic')),verify_unit=verifier)
        attempt = next(a for a in registry.attempts() if a['registration']['attempt_id'] == registration['attempt_id'])
        assert 'cashflows.partial.ndjson' in attempt['terminal']['partial_evidence']
        (campaign/'cashflows.partial.ndjson').write_text('altered')
        with pytest.raises(ExperimentError,match='partial_evidence'):
            freeze_selection(registry,rows)
        registry.protocol['identity']['dataset_hash'] = 'b'*64
        with pytest.raises(ExperimentError,match='mutable_protocol'):
            registry.register('baseline','adverse','training')


@pytest.mark.parametrize('field,value', [('phase','holdout'),('variant_id','invented'),('identity',{}),('symbol_priority',[])])
def test_registration_tampering_rejected(tmp_path,field,value):
    with ExperimentRegistry(tmp_path/'private',bound_protocol(),min_free_bytes=0) as registry:
        row = registry.register('baseline','baseline','training')
        path = registry.root/'attempts'/row['attempt_id']/'registration.json'
        changed = dict(row); changed[field] = value
        path.write_text(json.dumps(changed))
        with pytest.raises(ExperimentError):
            registry.attempts()


def full_fixture_runner(protocol):
    def runner(registration,root):
        root.mkdir(mode=0o700)
        campaign_fixture(root)
        manifest = json.loads((root/'manifest.json').read_bytes())
        summary = json.loads((root/'summary.json').read_bytes())
        status = json.loads((root/'status.json').read_bytes())
        phase = registration['phase']; variant = registration['variant_id']; profile = registration['cost_profile']
        start = 1672531200000 if phase == 'training' else 1735689600000
        end = 1735689600000 if phase == 'training' else 1767225600000
        manifest.update(protocol['phase_bindings'][phase],phase=phase,start_ms=start,end_ms=end,cost_profile=profile)
        diff = next(r['diff'] for r in protocol['variants'] if r['id'] == variant) or []
        manifest['variant'] = {'id':variant,'diff':diff,'variant_hash':canonical_hash({
            'schema_version':'research-variant.v1','id':variant,'diff':diff,
            'base_setup_hash':manifest['baseline']['setup_hash'],
            'base_config_hash':manifest['baseline']['config_hash'],
            'base_catalog_hash':manifest['baseline']['condition_catalog_hash']})}
        identity = {k:v for k,v in protocol['identity'].items() if k in (
            'base_setup_hash','base_config_hash','base_catalog_hash','base_snapshot_hash',
            'instrument_assumptions_hash','research_code_hash')}
        identity.update(variant_id=variant,variant_hash=manifest['variant']['variant_hash'],
                        cost_assumptions_hash=manifest['cost_assumptions']['assumption_hash'])
        count = (100 if phase == 'training' else 50) if variant == 'baseline' else 0
        from tests.test_research_statistics import marked_row
        rows = [marked_row(start,start,kind='initial')]; net_total = gross_total = 0
        for index in range(count):
            source = manifest['source_runs'][index%5]
            symbol = source['symbol']
            sources = {**source['source'],'signal_run_id':source['signal_run_id'],
                       'signal_output_sha256':source['signal_output_sha256']}
            at = (1704067200000 if phase == 'training' and index >= 50 else start)+(index%50+1)*60000
            plan = {'schema_version':'research-plan.v1',**identity,**sources,'symbol':symbol,
                'cost_profile':profile,'cost_model':manifest['cost_assumptions']['profiles'][profile],
                'entry_price':100.0,'quantity':2.0,'instrument_math':{'contract_size':1.0},
                'risk_components_quote':{'total_stop_loss':2.0},'evaluated_ms':at-60000}
            plan['plan_hash'] = canonical_hash(plan); key = plan['plan_hash']
            net = -1 if index%5 == 0 else 3; gross = net+1
            net_total += net; gross_total += gross
            rows += [('plans',plan),('events',{'symbol':symbol,'plan_hash':key,'reason':'admitted'}),
                     ('events',{'symbol':symbol,'plan_hash':key,'reason':'limit_fill_proxy','timestamp_ms':at}),
                     ('cashflows',{'symbol':symbol,'plan_hash':key,'kind':'entry_fee','amount_quote':'-1','timestamp_ms':at-30000}),
                     ('cashflows',{'symbol':symbol,'plan_hash':key,'kind':'gross_pnl','amount_quote':str(gross),'timestamp_ms':at}),
                     ('trades',{'symbol':symbol,'plan_hash':key,**identity,**sources,
                         **{f:'0' for f in FIELDS},'net_pnl_quote':str(net),'gross_pnl_quote':str(gross),
                         'fees_quote':'1','exit_boundary_ms':at,'fill_boundary_ms':at,'exit_reason':'synthetic'})]
            rows[-1][1].update(entry_price='100',quantity='2',exit_price=str(100+gross/2))
            rows.append(marked_row(at,start))
        rows.append(marked_row(end,start,kind='terminal'))
        status['files'] = write_ledgers(root,rows)
        summary.update(phase=phase,start_ms=start,end_ms=end,trades=count,admitted_plans=count,attempted_signals=count,
            gross_pnl_quote=str(gross_total),net_pnl_quote=str(net_total),fees_quote=str(count),
            maximum_drawdown_quote='1' if count else '0',
            wallet_quote=str(100000+net_total),b1_counters={'evaluated_ticks':200,'passed_rules':count,'failed_rules':200-count})
        manifest['b1_counters'] = summary['b1_counters']
        summary.update(phase_batches=(end-start)//60000,source_candles=(end-start)//60000*10)
        rehash_campaign(root,manifest,summary,status)
    return runner


@pytest.mark.parametrize('encoding',['whitespace','reorder','duplicate'])
def test_freeze_rejects_noncanonical_protocol_bytes(tmp_path,encoding):
    protocol = bound_protocol()
    with ExperimentRegistry(tmp_path/'registry',protocol,min_free_bytes=0) as registry:
        rows = schedule_batch(registry,lambda *args: (_ for _ in ()).throw(ValueError('synthetic')),
                              verify_unit=verifier)
        freeze_selection(registry,rows)
    path = registry.root/'protocol.json'
    if encoding == 'whitespace':
        raw = json.dumps(protocol,indent=2).encode()
    elif encoding == 'reorder':
        raw = json.dumps(dict(reversed(list(protocol.items()))),separators=(',',':')).encode()
    else:
        raw = canonical_bytes(protocol).rstrip()[:-1]+b',"schema_version":'+json.dumps(protocol['schema_version']).encode()+b'}'
    path.write_bytes(raw)
    with pytest.raises(ExperimentError,match='protocol.*canonical'):
        verify_freeze(registry.root/'selection-freeze.json')


def test_scheduler_passes_remaining_budget_and_preserves_failure_terminal(tmp_path):
    seen = []
    def bounded(registration,root,*,max_output_bytes,min_free_bytes):
        seen.append((max_output_bytes,min_free_bytes))
        root.mkdir()
        (root/'partial').write_bytes(b'x'*max_output_bytes)
        raise ValueError('synthetic cap reached')
    with ExperimentRegistry(tmp_path/'registry',bound_protocol(),max_output_bytes=9*1024**2,min_free_bytes=0) as registry:
        rows = schedule_batch(registry,bounded,verify_unit=verifier)
        assert seen and seen[0][0] < registry.max_output_bytes
        assert all(a['terminal']['state'] == 'failed' for a in registry.attempts())
        registry._budget()


def test_selected_freeze_replays_real_shaped_ledgers_and_detects_evidence_tampering(tmp_path):
    seed = tmp_path/'seed'; seed.mkdir()
    protocol,_ = campaign_fixture(seed)
    with ExperimentRegistry(tmp_path/'private',protocol,min_free_bytes=0) as registry:
        candidates = schedule_batch(registry,full_fixture_runner(protocol))
        assert len(registry.attempts()) == 28
        freeze = freeze_selection(registry,candidates)
        assert freeze['selected'] == {'id':'baseline','diff':{}}
        assert freeze['holdout_status'] == 'pending'
        assert verify_freeze(registry.root/'selection-freeze.json') == freeze
        paths = write_reports(registry,tmp_path/'eligible-report',candidates)
        assert json.loads(paths['json'].read_bytes())['selection_state'] == 'selected'
        assert 'baseline' in paths['markdown'].read_text()
        assert '220 USDT' in paths['markdown'].read_text()
        assert 'scored,passed,admitted,filled,rejected' in paths['csv'].read_text().splitlines()[0]
        complete = next(a for a in registry.attempts() if a['terminal']['state'] == 'complete')
        path = registry.root/'attempts'/complete['registration']['attempt_id']/'statistics.json'
        path.write_text('{}')
        with pytest.raises(ExperimentError,match='freeze_evidence'):
            verify_freeze(registry.root/'selection-freeze.json')


@pytest.mark.parametrize('root_kind',['relative','traversal','home','filesystem','symlink','git','file'])
def test_private_root_guards(tmp_path,root_kind):
    if root_kind == 'relative': root = Path('relative')
    elif root_kind == 'traversal': root = tmp_path/'..'/'escape'
    elif root_kind == 'home': root = Path.home()
    elif root_kind == 'filesystem': root = Path('/')
    elif root_kind == 'symlink':
        root = tmp_path/'linked'; root.symlink_to(tmp_path,target_is_directory=True)
    elif root_kind == 'git':
        (tmp_path/'.git').mkdir(); root = tmp_path/'private'
    else:
        root = tmp_path/'file'; root.write_text('synthetic')
    with pytest.raises(ExperimentError):
        ExperimentRegistry(root,make_protocol(IDENTITY),min_free_bytes=0)


@pytest.mark.parametrize('failure',['permissions','foreign','unidentified','cap','reserve','symlink','fifo','protocol_changed','protocol_noncanonical'])
def test_registry_reopen_and_capacity_guards(tmp_path,failure,monkeypatch):
    root = tmp_path/'private'; protocol = bound_protocol()
    with ExperimentRegistry(root,protocol,min_free_bytes=0): pass
    kwargs = {'min_free_bytes':0}
    if failure == 'permissions': root.chmod(0o755)
    elif failure == 'foreign':
        monkeypatch.setattr(os,'geteuid',lambda: -1)
    elif failure == 'unidentified':
        (root/'protocol.json').unlink(); (root/'unknown').write_text('synthetic')
    elif failure == 'cap': kwargs['max_output_bytes'] = 1
    elif failure == 'reserve': kwargs['min_free_bytes'] = 10**20
    elif failure == 'symlink': (root/'linked').symlink_to(tmp_path)
    elif failure == 'fifo': os.mkfifo(root/'fifo')
    elif failure == 'protocol_changed':
        altered = copy.deepcopy(protocol); altered['identity']['dataset_hash'] = 'b'*64
        (root/'protocol.json').write_text(json.dumps(altered))
    else: (root/'protocol.json').write_text(json.dumps(protocol,indent=2))
    with pytest.raises(ExperimentError):
        with ExperimentRegistry(root,protocol,**kwargs): pass


@pytest.mark.parametrize('raw',[b'{bad}',b'[]',b'{"a":1,"a":2}',b'{"a":NaN}'])
def test_invalid_registry_json_rejected(tmp_path,raw):
    root = tmp_path/'private'
    with ExperimentRegistry(root,bound_protocol(),min_free_bytes=0): pass
    (root/'protocol.json').write_bytes(raw)
    with pytest.raises(ExperimentError):
        with ExperimentRegistry(root,bound_protocol(),min_free_bytes=0): pass


def test_registration_terminal_and_unlocked_writer_guards(tmp_path):
    registry = ExperimentRegistry(tmp_path/'private',bound_protocol(),min_free_bytes=0)
    for operation in (lambda:registry.register('baseline','baseline','training'),
                      lambda:registry._write(tmp_path/'artifact.json',{}),
                      lambda:schedule_batch(registry,lambda *_: None,verify_unit=verifier),
                      lambda:write_reports(registry,tmp_path/'report')):
        with pytest.raises(ExperimentError): operation()
    with registry:
        row = registry.register('baseline','baseline','training')
        with pytest.raises(ExperimentError): registry.finish('../escape',{'state':'failed'})
        with pytest.raises(ExperimentError): registry.finish(row['attempt_id'],{'state':'pending'})
        with pytest.raises(ExperimentError): registry.finish(row['attempt_id'],{'state':'failed','attempt_id':'spoof'})
        registry.finish(row['attempt_id'],{'state':'failed'})
        path = registry.root/'attempts'/row['attempt_id']/'terminal.json'
        altered = json.loads(path.read_bytes()); altered['registration_hash'] = 'b'*64
        path.write_text(json.dumps(altered))
        with pytest.raises(ExperimentError): registry.attempts()


def test_unknown_duplicate_candidates_invalid_phase_bindings_and_limits(tmp_path):
    with pytest.raises(ExperimentError): rank_candidates([candidate(),candidate()],bound_protocol())
    with pytest.raises(ExperimentError): rank_candidates([candidate('invented')],bound_protocol())
    for binding in ({'training':{}},{'training':{},'validation':{}}):
        with pytest.raises(ExperimentError): make_protocol(IDENTITY,phase_bindings=binding)
    for limits in ({'max_output_bytes':0},{'max_output_bytes':True},{'min_free_bytes':-1}):
        with pytest.raises(ExperimentError): ExperimentRegistry(tmp_path/'private',bound_protocol(),**limits)


def test_private_artifact_and_attempt_symlink_guards(tmp_path):
    from app.backtesting.research import experiments
    path = tmp_path/'large.json'; path.write_bytes(b' '*(4*1024**2+1))
    with pytest.raises(ExperimentError): experiments._read_json(path)
    linked = tmp_path/'linked.json'; linked.symlink_to(path)
    with pytest.raises(ExperimentError): experiments._read_json(linked)
    with pytest.raises(ExperimentError): experiments._file_hash(linked)
    with ExperimentRegistry(tmp_path/'private',bound_protocol(),min_free_bytes=0) as registry:
        (registry.root/'attempts'/'linked').symlink_to(tmp_path)
        with pytest.raises(ExperimentError): registry.attempts()


def test_pending_nonterminal_missing_validation_and_duplicate_attempts_block_freeze(tmp_path):
    from app.backtesting.research import experiments
    with ExperimentRegistry(tmp_path/'private',bound_protocol(),min_free_bytes=0) as registry:
        row = registry.register('baseline','baseline','training')
        with pytest.raises(ExperimentError): experiments._candidate_rows(registry)
        with pytest.raises(ExperimentError): experiments._verify_attempt_statistics(registry)
        registry.finish(row['attempt_id'],{'state':'complete','statistics':metrics()})
        duplicate = registry.register('baseline','baseline','training')
        registry.finish(duplicate['attempt_id'],{'state':'failed'})
        with pytest.raises(ExperimentError): experiments._candidate_rows(registry)
        with pytest.raises(ExperimentError): schedule_batch(registry,lambda *_:None,verify_unit=verifier)


def test_reuse_hash_statistics_and_unfinished_verifier_conflicts(tmp_path):
    from app.backtesting.research import experiments
    with ExperimentRegistry(tmp_path/'private',bound_protocol(),min_free_bytes=0) as registry:
        def unfinished(*_): return {'status':'pending'}
        rows = schedule_batch(registry,synthetic_runner,verify_unit=unfinished)
        assert all(r['training']['baseline']['status'] == 'failed' for r in rows)
    with ExperimentRegistry(tmp_path/'reuse',bound_protocol(),min_free_bytes=0) as registry:
        schedule_batch(registry,synthetic_runner,verify_unit=verifier)
        with pytest.raises(ExperimentError,match='reused_statistics'):
            schedule_batch(registry,synthetic_runner,verify_unit=lambda *args:{**verifier(*args),'synthetic':'changed'})
        complete = next(a for a in registry.attempts() if a['terminal']['state'] == 'complete')
        path = registry.root/'attempts'/complete['registration']['attempt_id']/'campaign'/'manifest.json'
        path.write_text('{}')
        with pytest.raises(ExperimentError,match='reused_evidence'):
            schedule_batch(registry,synthetic_runner,verify_unit=verifier)


def test_lazy_c1_runner_does_not_start_workers_on_import(tmp_path,monkeypatch):
    from app.backtesting.research import experiments
    import sys
    from types import SimpleNamespace
    calls = []
    monkeypatch.setitem(sys.modules,'app.backtesting.research.campaign',
        SimpleNamespace(run_campaign=lambda *args,**kwargs:calls.append((args,kwargs))))
    kwargs = dict(dataset_root=tmp_path/'data',signal_roots={'training':tmp_path/'train','validation':tmp_path/'val'},
        app_dir=tmp_path/'app',instrument_path=tmp_path/'instrument.json',cost_path=tmp_path/'cost.json')
    run = experiments.make_campaign_runner(**kwargs,min_free_bytes=0)
    assert calls == []
    run({'phase':'training','variant_id':'baseline','cost_profile':'adverse'},tmp_path/'output')
    assert calls[0][0][1] == tmp_path/'train'
    assert calls[0][1]['symbols'] == SYMBOLS
    run({'phase':'validation','variant_id':'baseline','cost_profile':'baseline'},tmp_path/'output2',
        max_output_bytes=100000,min_free_bytes=12345)
    assert calls[1][1]['max_output_bytes'] == 100000
    assert calls[1][1]['min_free_bytes'] == 12345
    kwargs['signal_roots'] = {}
    with pytest.raises(ExperimentError): experiments.make_campaign_runner(**kwargs)


def test_rehashed_summary_drawdown_cannot_be_reused_or_frozen(tmp_path):
    from app.backtesting.research.statistics import StatisticsError
    seed = tmp_path/'seed'; seed.mkdir(); protocol,_ = campaign_fixture(seed)
    with ExperimentRegistry(tmp_path/'private',protocol,min_free_bytes=0) as registry:
        rows = schedule_batch(registry,full_fixture_runner(protocol))
        attempt = next(a for a in registry.attempts() if a['terminal']['state'] == 'complete')
        root = registry.root/'attempts'/attempt['registration']['attempt_id']/'campaign'
        summary = json.loads((root/'summary.json').read_bytes())
        summary['maximum_drawdown_quote'] = '5999'
        rehash_campaign(root,summary=summary)
        with pytest.raises(StatisticsError,match='marked_drawdown'):
            schedule_batch(registry,lambda *_: pytest.fail('must reverify without rerun'))
        with pytest.raises(StatisticsError,match='marked_drawdown'):
            freeze_selection(registry,rows)
        assert not (registry.root/'selection-freeze.json').exists()


def test_missing_validation_pending_report_and_forbidden_validation(tmp_path):
    from app.backtesting.research import experiments
    with ExperimentRegistry(tmp_path/'private',bound_protocol(),min_free_bytes=0) as registry:
        for variant,_ in experiments.VARIANTS:
            for profile in ('baseline','adverse'):
                row = registry.register(variant,profile,'training')
                registry.finish(row['attempt_id'],{'state':'complete','statistics':metrics()} if variant == 'baseline' else {'state':'failed'})
        with pytest.raises(ExperimentError,match='validation_unit_missing'):
            experiments._candidate_rows(registry)
        report = experiments._candidate_rows(registry,allow_pending=True)
        assert report[0]['validation']['adverse']['status'] == 'pending'
        forged = registry.register('ema20_5m','baseline','validation')
        registry.finish(forged['attempt_id'],{'state':'failed'})
        with pytest.raises(ExperimentError,match='validation_of_ineligible'):
            experiments._candidate_rows(registry,allow_pending=True)


def test_reports_capacity_candidate_conflict_and_missing_ancestors(tmp_path):
    from app.backtesting.research import experiments
    with ExperimentRegistry(tmp_path/'private',bound_protocol(),min_free_bytes=0) as registry:
        candidates = schedule_batch(registry,lambda *_:(_ for _ in ()).throw(RuntimeError('synthetic')),verify_unit=verifier)
        # Failed attempts are reused and stay failed rather than being silently retried.
        assert schedule_batch(registry,lambda *_:None,verify_unit=verifier) == candidates
        with pytest.raises(ExperimentError,match='candidate_evidence'):
            write_reports(registry,tmp_path/'report',[])
        registry.max_output_bytes = 1
        with pytest.raises(ExperimentError,match='report_capacity'):
            write_reports(registry,tmp_path/'missing'/'nested'/'report',candidates)
        registry.protocol['identity']['dataset_hash'] = 'b'*64
        with pytest.raises(ExperimentError,match='mutable_protocol'):
            registry._write(registry.root/'bad.json',{})
    linked = tmp_path/'linked'; linked.symlink_to(tmp_path,target_is_directory=True)
    with pytest.raises(ExperimentError): experiments._partial_inventory(linked)


def test_freeze_missing_bindings_wrong_path_and_frozen_scheduler(tmp_path):
    from app.backtesting.research import experiments
    with pytest.raises(ExperimentError,match='freeze_path'):
        verify_freeze(tmp_path/'arbitrary.json')
    with ExperimentRegistry(tmp_path/'private',make_protocol(IDENTITY),min_free_bytes=0) as registry:
        experiments._publish(registry.root/'selection-freeze.json',{})
        with pytest.raises(ExperimentError,match='phase_bindings'):
            verify_freeze(registry.root/'selection-freeze.json')
        with pytest.raises(ExperimentError,match='already_frozen'):
            schedule_batch(registry,lambda *_:None,verify_unit=verifier)


def test_stats_replayed_not_only_trusted_hash(tmp_path):
    from app.backtesting.research import experiments
    from app.backtesting.research.statistics import verify_campaign
    seed = tmp_path/'seed'; seed.mkdir(); protocol,_ = campaign_fixture(seed)
    with ExperimentRegistry(tmp_path/'private',protocol,min_free_bytes=0) as registry:
        row = registry.register('baseline','baseline','training')
        root = registry.root/'attempts'/row['attempt_id']/'campaign'
        full_fixture_runner(protocol)(row,root)
        stats = verify_campaign(root,protocol,row)
        stats['net_win_rate'] = '1'
        registry.finish(row['attempt_id'],{'state':'complete','statistics':stats})
        with pytest.raises(ExperimentError,match='replayed_statistics'):
            experiments._verify_attempt_statistics(registry)


def test_symlink_ancestor_cannot_redirect_immutable_evidence(tmp_path):
    from app.backtesting.research import experiments
    target = tmp_path/'target'; target.mkdir(); (target/'artifact.json').write_text('{}')
    linked = tmp_path/'linked'; linked.symlink_to(target,target_is_directory=True)
    with pytest.raises(ExperimentError): experiments._read_json(linked/'artifact.json')
    with pytest.raises(ExperimentError): experiments._file_hash(linked/'artifact.json')
    with pytest.raises(ExperimentError): experiments._publish(linked/'new.json',{})


def test_report_refuses_unverified_complete_statistics(tmp_path):
    with ExperimentRegistry(tmp_path/'private',bound_protocol(),min_free_bytes=0) as registry:
        row = registry.register('baseline','baseline','training')
        registry.finish(row['attempt_id'],{'state':'complete','statistics':metrics()})
        with pytest.raises(ValueError): write_reports(registry,tmp_path/'report')


def test_rehashed_funding_cannot_be_reused_or_selected(tmp_path):
    from app.backtesting.research.statistics import StatisticsError
    seed = tmp_path/'seed'; seed.mkdir(); protocol,_ = campaign_fixture(seed)
    with ExperimentRegistry(tmp_path/'private',protocol,min_free_bytes=0) as registry:
        rows = schedule_batch(registry,full_fixture_runner(protocol))
        attempt = next(a for a in registry.attempts() if a['terminal']['state'] == 'complete')
        root = registry.root/'attempts'/attempt['registration']['attempt_id']/'campaign'
        alter_funding_input(root,'rest_interval_hypothesis')
        with pytest.raises(StatisticsError,match='frozen_funding'):
            schedule_batch(registry,lambda *_: pytest.fail('reuse must not run a new campaign'))
        with pytest.raises(StatisticsError,match='frozen_funding'):
            freeze_selection(registry,rows)
        assert not (registry.root/'selection-freeze.json').exists()
        assert len(registry.attempts()) == 28


def alter_plan_scenario(root,mutation):
    rows = read_rows(root)
    references = {}
    for kind,row in rows:
        if kind == 'plans':
            old = row['plan_hash']
            if mutation == 'profile_label':
                row['cost_profile'] = 'baseline'
            elif mutation == 'missing_profile':
                row.pop('cost_profile')
            elif mutation == 'missing_cost_field':
                row['cost_model'].pop('funding_provision_rate')
            else:
                row['cost_model'][mutation] = '0.0001'
            new = canonical_hash({k:v for k,v in row.items() if k != 'plan_hash'})
            references[old] = new
    for _,row in rows:
        if 'plan_hash' in row:
            row['plan_hash'] = references[row['plan_hash']]
    status = json.loads((root/'status.json').read_bytes())
    status['files'] = write_ledgers(root,rows)
    rehash_campaign(root,status=status)


@pytest.mark.parametrize('mutation',['profile_label','missing_profile','missing_cost_field','funding_provision_rate',
    'entry_spread_rate','stop_spread_rate','target_spread_rate','entry_slippage_rate','stop_slippage_rate','target_slippage_rate'])
def test_adverse_plan_scenario_bound_beyond_shared_manifest_hash(tmp_path,mutation):
    from app.backtesting.research.statistics import StatisticsError, verify_campaign
    seed = tmp_path/'seed'; seed.mkdir(); protocol,_ = campaign_fixture(seed)
    registration = {'phase':'training','variant_id':'baseline','cost_profile':'adverse',
        'protocol_hash':hash_bytes(canonical_bytes(protocol))}
    root = tmp_path/'adverse'; full_fixture_runner(protocol)(registration,root)
    assert verify_campaign(root,protocol,registration)['closed_trades'] == 100
    alter_plan_scenario(root,mutation)
    with pytest.raises(StatisticsError,match='cost_profile|cost_model'):
        verify_campaign(root,protocol,registration)


def test_rehashed_wrong_scenario_cannot_be_reused_or_selected(tmp_path):
    from app.backtesting.research.statistics import StatisticsError
    seed = tmp_path/'seed'; seed.mkdir(); protocol,_ = campaign_fixture(seed)
    with ExperimentRegistry(tmp_path/'private',protocol,min_free_bytes=0) as registry:
        rows = schedule_batch(registry,full_fixture_runner(protocol))
        attempt = next(a for a in registry.attempts() if a['registration']['variant_id'] == 'baseline'
                       and a['registration']['phase'] == 'training' and a['registration']['cost_profile'] == 'adverse')
        root = registry.root/'attempts'/attempt['registration']['attempt_id']/'campaign'
        alter_plan_scenario(root,'profile_label')
        with pytest.raises(StatisticsError,match='cost_profile'):
            schedule_batch(registry,lambda *_: pytest.fail('reuse must not run a new campaign'))
        with pytest.raises(StatisticsError,match='cost_profile'):
            freeze_selection(registry,rows)
        assert not (registry.root/'selection-freeze.json').exists()
        assert len(registry.attempts()) == 28


def test_large_frozen_code_maps_protocol_publishes_and_reopens(tmp_path):
    code = {'synthetic-vendor/'+('x'*180)+f'/{i:05}.php':'a'*64 for i in range(8192)}
    bindings = bound_protocol()['phase_bindings']
    for phase,binding in bindings.items():
        binding['signal_reports'] = [{'root':str(tmp_path/f'{phase}-group-{group}'),
            'report_sha256':'b'*64,'symbols':list(SYMBOLS[group*5:(group+1)*5]),'code_sha256':code}
            for group in range(2)]
    protocol = make_protocol(IDENTITY,phase_bindings=bindings)
    assert len(canonical_bytes(protocol)) > 4*1024**2
    with ExperimentRegistry(tmp_path/'large-private',protocol,min_free_bytes=0) as registry:
        registry.register('baseline','baseline','training')
    with ExperimentRegistry(tmp_path/'large-private',protocol,min_free_bytes=0) as registry:
        assert len(registry.attempts()) == 1


def test_protocol_writer_checks_matching_reader_size_limit_before_publication(tmp_path,monkeypatch):
    from app.backtesting.research import experiments
    monkeypatch.setattr(experiments,'MAX_PROTOCOL_BYTES',32,raising=False)
    with pytest.raises(ExperimentError,match='json_capacity'):
        with ExperimentRegistry(tmp_path/'private',bound_protocol(),min_free_bytes=0): pass
    assert not (tmp_path/'private'/'protocol.json').exists()
    assert not list((tmp_path/'private').glob('*.tmp'))


@pytest.mark.parametrize('field,value',[('funding_inventory_hash',None),('funding_inventory_hash','invalid'),
    ('funding_diagnostic_policy',None),('funding_diagnostic_policy','')])
def test_funding_bindings_required_before_protocol_registration(field,value):
    binding = bound_protocol()['phase_bindings']
    binding['training'][field] = value
    with pytest.raises(ExperimentError,match='funding_binding'):
        make_protocol(IDENTITY,phase_bindings=binding)
