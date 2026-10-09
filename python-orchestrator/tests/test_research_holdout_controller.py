"""Controller acceptance uses synthetic transport and the actual guarded engine."""
import csv
import io
import json
import os
from pathlib import Path

import pytest

from app.backtesting.research import campaign, holdout_authority as h, signals, statistics
from tests.test_research_holdout import engine, fixture


@pytest.mark.parametrize('engine', [{'variant':'baseline'}, {'variant':'width_050'}], indirect=True)
def test_complete_fixed_controller_with_real_kernel_and_readonly_replay(engine, monkeypatch, capsys):
    from app.backtesting.research import holdout
    f, kwargs, signal_report, selections, calls = engine
    report = holdout.run_holdout(f.output)
    assert report['holdout_status'] == 'complete'
    assert report['execution_authority'] == 'none'
    assert report['paper_transfer'] == 'distinct_pending'
    assert calls == list(h.SYMBOLS)
    assert report['python_transition']['new_b1_code_identity']['code_sha256']==signal_report['code_sha256']
    transition=report['python_transition']
    assert transition['frozen_verifier_runtime_inventory']==f.config['runtime']
    assert transition['frozen_verifier_python_path']==f.config['paths']['python']
    runner=json.loads((f.anchor/'contract.json').read_bytes())['runner_interpreter']
    assert transition['runner_interpreter']==runner
    assert 'new_python_path' not in transition and 'new_runtime_inventory' not in transition
    assert len(report['units']) == (2 if f.freeze['selected']['id']=='baseline' else 4)
    assert len({(u['variant_id'],u['cost_profile']) for u in report['units']}) == len(report['units'])
    for unit in report['units']:
        assert unit['status'] == 'complete' and unit['independently_verified']
        assert unit['statistics']['closed_trades'] > 0
        assert unit['statistics']['marked_equity_verified']
        assert unit['statistics']['funding_quote'] != '0'
        assert unit['statistics']['initial_wallet_quote'] == '100000'
        assert unit['counters']['source_candles'] == signal_report['totals']['source_candles']
    before = {str(p):p.read_bytes() for root in (f.output,f.anchor) for p in root.rglob('*') if p.is_file()}
    def forbidden(*a, **kw): pytest.fail('retained replay generated or simulated')
    monkeypatch.setattr(signals, 'run_holdout_signals', forbidden)
    monkeypatch.setattr(campaign, 'run_holdout_campaign', forbidden)
    assert holdout.verify_retained_holdout() == report
    after = {str(p):p.read_bytes() for root in (f.output,f.anchor) for p in root.rglob('*') if p.is_file()}
    assert after == before
    # A verifier can use another interpreter; it cannot relabel the original
    # execution or recapture provenance while rebuilding its immutable reports.
    import sys
    with monkeypatch.context() as provenance:
        provenance.setattr(sys,'executable','/different/verification/python')
        provenance.setattr(sys,'version','different verification interpreter')
        assert holdout.verify_retained_holdout()==report
    assert holdout.main(['--verify-retained'])==0
    console=json.loads(capsys.readouterr().out)
    assert console['holdout_status']=='complete' and console['execution_authority']=='none'
    # Output disk space is irrelevant to read-only artifact replay. Only the
    # independently bounded temporary statistics index needs writable space.
    with monkeypatch.context() as space:
        space.setattr(h.shutil,'disk_usage',lambda p:type('Disk',(),{
            'free':0 if Path(p)==f.output.parent else 100*1024**3})())
        assert holdout.verify_retained_holdout()==report
        space.setattr(h.shutil,'disk_usage',lambda p:type('Disk',(),{'free':0})())
        with pytest.raises(statistics.StatisticsError,match='spool_capacity'):
            holdout.verify_retained_holdout()
    # Terminal corruption cannot be hidden behind valid standalone ledgers.
    terminal_path=f.output/'status.json'; original_terminal=terminal_path.read_bytes()
    terminal=json.loads(original_terminal)
    terminal['reports']['report.json']['sha256']='0'*64
    terminal_path.write_bytes(h.canonical_bytes(terminal))
    with pytest.raises(h.HoldoutError,match='terminal_conflict'): holdout.verify_retained_holdout()
    terminal_path.write_bytes(original_terminal)
    terminal_path.unlink()
    with pytest.raises(h.HoldoutError,match='missing_terminal'): holdout.verify_retained_holdout()
    terminal_path.write_bytes(original_terminal); terminal_path.chmod(0o600)
    for path in (f.output/'report.json',f.output/'report.csv',f.output/'report.fr.md',
                 Path(report['units'][0]['output_root'])/'verified-result.json'):
        raw=path.read_bytes(); path.write_bytes(raw+b' ')
        with pytest.raises(h.HoldoutError,match='artifact_hash_conflict'): holdout.verify_retained_holdout()
        path.write_bytes(raw)
    # Rewriting terminal hashes still cannot substitute invented financial
    # summaries for the independently reconstructed ledger statistics.
    for path,key,value,reason in (
        (f.output/'report.json','holdout_status','failed','retained_report_conflict'),
        (Path(report['units'][0]['output_root'])/'verified-result.json','independently_verified',False,'retained_unit_result_conflict')):
        raw=path.read_bytes(); modified=json.loads(raw); modified[key]=value
        path.write_bytes(h.canonical_bytes(modified))
        terminal=json.loads(original_terminal)
        import time
        auth=h.mint_retained_authorization(f.authority())
        terminal['artifact_inventory']=holdout._inventory(auth,deadline=time.monotonic()+60)
        terminal_path.write_bytes(h.canonical_bytes(terminal))
        with pytest.raises(h.HoldoutError,match=reason): holdout.verify_retained_holdout()
        path.write_bytes(raw); terminal_path.write_bytes(original_terminal)
    assert all(p.stat().st_mode & 0o777 == (0o700 if p.is_dir() else 0o600)
               for p in [f.output, *f.output.rglob('*')])
    with pytest.raises(h.HoldoutError, match='already_claimed'):
        holdout.run_holdout(f.other_output)


def test_retained_readonly_budget_accepts_terminal_using_reserved_capacity(fixture, monkeypatch):
    auth = fixture.claim()
    fixture.output.mkdir(mode=0o700)
    used = h.remaining_limits(auth)['used_bytes']
    padding = fixture.output/'padding'
    with padding.open('wb') as stream: stream.truncate(h.OVERALL_BYTES-used-1)
    padding.chmod(0o600)
    with pytest.raises(h.HoldoutError, match='capacity'): h.remaining_limits(auth)
    readonly = h.mint_retained_authorization(fixture.authority())
    assert h.remaining_limits(readonly)['bytes'] == 1


@pytest.mark.parametrize('engine', [{'variant':'width_050'}], indirect=True)
def test_fatal_second_unit_preserves_first_result_and_consumed_claim(engine, monkeypatch):
    from app.backtesting.research import holdout
    f = engine[0]
    worker = campaign.plans.PlanWorker
    class BrokenWorker(worker):
        def build(self, signal, view):
            if self.opening['cost_profile']=='adverse': raise campaign.plans.PlanError('synthetic malformed second unit')
            return super().build(signal,view)
    monkeypatch.setattr(campaign.plans,'PlanWorker',BrokenWorker)
    with pytest.raises(campaign.CampaignError, match='second unit'):
        holdout.run_holdout(f.output)
    report = json.loads((f.output/'report.json').read_bytes())
    assert report['holdout_status']=='failed'
    assert [u['status'] for u in report['units']]==['complete','failed','not_run_batch_failed','not_run_batch_failed']
    assert report['units'][0]['statistics']['closed_trades']==1
    assert report['units'][1]['statistics'] is None
    assert (f.output/'units'/'width_050'/'baseline'/'verified-result.json').exists()
    before = {str(p):p.read_bytes() for p in f.output.rglob('*') if p.is_file()}
    with pytest.raises(h.HoldoutError,match='incomplete_or_failed'): holdout.verify_retained_holdout()
    assert before=={str(p):p.read_bytes() for p in f.output.rglob('*') if p.is_file()}
    with pytest.raises(h.HoldoutError,match='already_claimed'): holdout.run_holdout(f.other_output)


@pytest.mark.parametrize('engine', [{'variant':'width_050'}], indirect=True)
@pytest.mark.parametrize('fatal', ['worker_error','deadline'])
def test_partial_signal_failure_stops_all_units_without_fake_statistics(engine, monkeypatch, fatal):
    from app.backtesting.research import holdout
    from app.backtesting.research.orchestration import DeadlineExpired
    f,_,_,_,calls = engine
    original = signals._run_symbol
    failure = RuntimeError('synthetic partial signal') if fatal=='worker_error' else DeadlineExpired('synthetic stage timer')
    def worker(*args):
        if len(calls)==2: raise failure
        return original(*args)
    monkeypatch.setattr(signals,'_run_symbol',worker)
    with pytest.raises((signals.SignalError,DeadlineExpired)):
        holdout.run_holdout(f.output)
    assert calls==list(h.SYMBOLS[:2])
    partial=json.loads((f.output/'signals'/'report.json').read_bytes())
    assert partial['totals']['completed_symbols']==2
    assert partial['status']=='failed'
    report=json.loads((f.output/'report.json').read_bytes())
    assert report['holdout_status']=='failed'
    assert all(u['status']=='not_run_batch_failed' and u['statistics'] is None for u in report['units'])
    assert not (f.output/'units').exists()
    assert (f.anchor/'claim.json').exists()


@pytest.mark.parametrize('engine', [{'variant':'baseline'}], indirect=True)
def test_missing_funding_finishes_all_fixed_units_inconclusive(engine, monkeypatch):
    from dataclasses import asdict, replace
    from app.backtesting.research import holdout
    reader=campaign.FundingReader
    class IncompleteReader(reader):
        def __init__(self,*args,**kwargs):
            super().__init__(*args,**kwargs)
            self.inventory=replace(self.inventory,evidence_complete=False,
                symbols=tuple(replace(row,evidence_complete=False,continuity='inconclusive',
                    count=0,first_ms=None,last_ms=None,interval_counts=())
                    for row in self.inventory.symbols))
            data=asdict(self.inventory); data.pop('inventory_hash')
            from app.backtesting.research.portfolio_simulator import canonical_hash
            self.inventory=replace(self.inventory,inventory_hash=canonical_hash(data))
        def iter_events(self): return iter(())
    monkeypatch.setattr(campaign,'FundingReader',IncompleteReader)
    report=holdout.run_holdout(engine[0].output)
    assert report['holdout_status']=='inconclusive'
    assert all(u['independently_verified'] and u['status']=='inconclusive' and
        not u['statistics']['cost_evidence_complete'] for u in report['units'])
    assert holdout.verify_retained_holdout()==report


def test_preclaim_rejection_creates_no_output_and_consumes_nothing(fixture):
    from app.backtesting.research import holdout
    fixture.selected(state='no_eligible_candidate')
    with pytest.raises(h.HoldoutError,match='no_selected_candidate'): holdout.run_holdout(fixture.output)
    assert not fixture.output.exists() and not (fixture.anchor/'claim.json').exists()


@pytest.mark.parametrize('timeout', [0,-1,True,float('nan'),float('inf'),h.OVERALL_SECONDS+1])
def test_controller_rejects_invalid_or_raised_timeout_before_claim(fixture,timeout):
    from app.backtesting.research import holdout
    with pytest.raises(h.HoldoutError,match='lower_only'): holdout.run_holdout(fixture.output,timeout=timeout)
    assert not (fixture.anchor/'claim.json').exists()


@pytest.mark.parametrize('engine', [{'variant':'baseline'}], indirect=True)
@pytest.mark.parametrize('failure', ['before_link','second_fsync'])
def test_terminal_publication_failure_keeps_claim_and_evidence(engine,monkeypatch,failure):
    from app.backtesting.research import holdout
    f=engine[0]
    original=h._publish_bytes
    def publication(path,raw):
        if path==f.output/'status.json': raise OSError('synthetic terminal publication failure')
        return original(path,raw)
    if failure=='before_link':
        monkeypatch.setattr(h,'_publish_bytes',publication)
    else:
        fsync=h._fsync_directory; calls=[0]
        def directory_fsync(path):
            if (f.output/'status.json').exists():
                calls[0]+=1
                if calls[0]==2: raise OSError('synthetic terminal publication failure')
            return fsync(path)
        monkeypatch.setattr(h,'_fsync_directory',directory_fsync)
    with pytest.raises(OSError,match='terminal publication'): holdout.run_holdout(f.output)
    assert (f.anchor/'claim.json').exists()
    assert (f.output/'report.json').exists()
    if failure=='before_link': assert not (f.output/'status.json').exists()
    else:
        markers=list(f.output.glob('.terminal-publication-failed-*'))
        assert len(markers)==1 and os.path.samefile(markers[0],f.output/'status.json')
        by_inode={}
        for root in (f.anchor,f.output):
            for path in root.rglob('*'):
                if path.is_file():
                    info=path.stat(); by_inode[(info.st_dev,info.st_ino)]=info.st_size
        retained=h.mint_retained_authorization(f.authority())
        assert h.remaining_limits(retained)['used_bytes']==sum(by_inode.values())
    with pytest.raises(h.HoldoutError): holdout.verify_retained_holdout()
    assert all((Path(s['output_root'])/'verified-result.json').exists()
        for s in json.loads((f.anchor/'contract.json').read_bytes())['slots'])


def sample_report():
    from app.backtesting.research import holdout
    outcomes=statistics.OutcomeStatistics(); outcomes.add('-2','1'); s=outcomes.result()
    s.update(gross_pnl_quote='1',fees_quote='-1',funding_quote='-1',spread_quote='-0.5',slippage_quote='-0.5',
        maximum_drawdown_quote='4',cashflow_wallet_maximum_drawdown_quote='2',maximum_exposure_quote='100',
        roi_wallet_rate='-0.00002',coverage_complete=True,cost_evidence_complete=True,
        funding_provenance='observed_only',counts={'scored':10,'passed':1,'failed_rules':9,'source_candles':20},
        per_pair={'BTCUSDT':{**outcomes.result(), 'fees_quote':'-1','funding_quote':'-1'}},
        per_year={'2025':{**outcomes.result(),'cashflow_net_pnl_quote':'-1',
            'cashflow_vs_exit_attribution_difference_quote':'1'}},source_quality={'message':'=not executable'})
    return {'schema_version':holdout.REPORT_SCHEMA,'campaign_id':h.CAMPAIGN_ID,'holdout_status':'complete',
        'selected':{'id':'baseline','diff':{}},'window':{'source_start':h.SOURCE_START,
        'score_start':h.SCORE_START,'end':h.END},'matched_scenario_deltas':[],
        'units':[{'variant_id':'=DANGEROUS("formula")','cost_profile':'baseline','status':'complete',
            'independently_verified':True,'statistics':s}]}


def test_reports_preserve_fraction_nulls_signed_costs_and_escape_csv_formulas():
    from app.backtesting.research import holdout
    report=sample_report()
    row=list(csv.DictReader(io.StringIO(holdout._csv(report).decode())))[0]
    assert row['variant_id'].startswith("'=DANGEROUS")
    assert row['net_win_rate']=='0' and row['fees_quote']=='-1'
    assert json.loads(row['per_year_json'])['2025']['cashflow_vs_exit_attribution_difference_quote']=='1'
    markdown=holdout._markdown(report).decode()
    assert '0,00 %' in markdown and '-0,00 %' in markdown
    assert 'Drawdown marqué : 4,00' in markdown and 'wallet cashflow : 2,00' in markdown
    empty=statistics.OutcomeStatistics().result()
    report['units'][0]['statistics'].update(empty)
    row=list(csv.DictReader(io.StringIO(holdout._csv(report).decode())))[0]
    assert row['net_win_rate']==row['mean_realized_net_r']==row['net_profit_factor']==''
    assert row['profit_factor_undefined_reason']=='no_negative_net_trades'
    assert 'indéfini' in holdout._markdown(report).decode()


def test_scenario_deltas_are_matched_defined_and_never_ranked():
    from app.backtesting.research import holdout
    contract={'selected':{'id':'width_050'},'cost_profiles':['baseline','adverse']}
    units=[]
    for variant in ('width_050','baseline'):
        for profile in contract['cost_profiles']:
            units.append({'variant_id':variant,'cost_profile':profile,'statistics':{
                'closed_trades':1,'net_win_rate':'0' if variant=='width_050' else '1',
                'net_pnl_quote':'3' if profile=='baseline' else '-2','net_profit_factor':None}})
    rows=holdout._deltas(contract,units)
    assert [r['cost_profile'] for r in rows]==contract['cost_profiles']
    assert all(r['metrics']['net_win_rate']=='-1' and r['metrics']['net_pnl_quote']=='0'
        and r['metrics']['net_profit_factor'] is None for r in rows)
    units[0]['statistics']=None
    assert all(v is None for v in holdout._deltas(contract,units)[0]['metrics'].values())
    assert holdout._deltas({'selected':{'id':'baseline'}},[])==[]


def test_terminal_can_use_the_last_reserved_byte_without_new_execution_capacity(fixture):
    from app.backtesting.research import holdout
    auth=fixture.claim(); fixture.output.mkdir(mode=0o700)
    used=h.remaining_limits(auth)['used_bytes']
    with (fixture.output/'padding').open('wb') as stream:
        stream.truncate(h.OVERALL_BYTES-used-h.TERMINAL_RESERVE)
    (fixture.output/'padding').chmod(0o600)
    raw=b'x'*h.TERMINAL_RESERVE
    holdout._publish_artifacts(auth,[(fixture.output/'status.json',raw)],terminal=True)
    assert (fixture.output/'status.json').stat().st_size==h.TERMINAL_RESERVE
    retained=h.mint_retained_authorization(fixture.authority())
    assert h.remaining_limits(retained)['bytes']==0
    with pytest.raises(h.HoldoutError,match='capacity'): h.remaining_limits(auth)


def test_report_publication_respects_projected_actual_disk_floor(fixture,monkeypatch):
    from app.backtesting.research import holdout
    auth=fixture.claim(); fixture.output.mkdir(mode=0o700)
    monkeypatch.setattr(h.shutil,'disk_usage',lambda p:type('Disk',(),{'free':h.DISK_RESERVE+9})())
    with pytest.raises(h.HoldoutError,match='disk_reserve'):
        holdout._publish_artifacts(auth,[(fixture.output/'report.json',b'x'*10)])
    assert not (fixture.output/'report.json').exists()


def test_claim_link_fsync_failure_gets_only_its_own_failure_terminal(fixture,monkeypatch):
    from app.backtesting.research import holdout
    original=h._fsync_directory
    failed=[False]
    def fsync(path):
        if (fixture.anchor/'claim.json').exists() and not failed[0]:
            failed[0]=True
            raise OSError('synthetic claim link fsync')
        return original(path)
    monkeypatch.setattr(h,'_fsync_directory',fsync)
    with pytest.raises(OSError,match='claim link fsync'): holdout.run_holdout(fixture.output)
    assert (fixture.anchor/'claim.json').exists()
    terminal=json.loads((fixture.output/'status.json').read_bytes())
    assert terminal['holdout_status']=='failed'
    assert terminal['fatal_error']['type']=='OSError'
    assert all(u['status']=='not_run_batch_failed' for u in terminal['units'])
    assert not (fixture.output/'signals').exists()
    before=(fixture.output/'status.json').read_bytes()
    with pytest.raises(h.HoldoutError,match='already_claimed'): holdout.run_holdout(fixture.other_output)
    assert not fixture.other_output.exists()
    assert (fixture.output/'status.json').read_bytes()==before


def test_before_claim_link_failure_never_publishes_terminal(fixture,monkeypatch):
    from app.backtesting.research import holdout
    original=h._publish_bytes
    def publication(path,raw):
        if path==fixture.anchor/'claim.json': raise OSError('synthetic before claim link')
        return original(path,raw)
    monkeypatch.setattr(h,'_publish_bytes',publication)
    with pytest.raises(OSError,match='before claim link'): holdout.run_holdout(fixture.output)
    assert not fixture.output.exists() and not (fixture.anchor/'claim.json').exists()
    assert (fixture.anchor/'contract.json').exists()


def test_report_bundle_capacity_is_aggregate_and_failure_publishes_nothing(fixture):
    from app.backtesting.research import holdout
    auth=fixture.claim(max_output_bytes=100000); fixture.output.mkdir(mode=0o700)
    remaining=h.remaining_limits(auth)['bytes']
    padding=fixture.output/'padding'; padding.write_bytes(b'x'*(remaining-15)); padding.chmod(0o600)
    with pytest.raises(h.HoldoutError,match='report_capacity'):
        holdout._publish_artifacts(auth,[(fixture.output/'report.json',b'a'*10),
                                       (fixture.output/'report.csv',b'b'*10)])
    assert not (fixture.output/'report.json').exists() and not (fixture.output/'report.csv').exists()


def test_report_metadata_is_bounded_before_publication(fixture):
    from app.backtesting.research import holdout
    auth=fixture.claim(); fixture.output.mkdir(mode=0o700)
    with pytest.raises(h.HoldoutError,match='metadata_capacity'):
        holdout._publish_artifacts(auth,[(fixture.output/'report.json',b'x'*(h.METADATA_BYTES+1))])
    assert not (fixture.output/'report.json').exists()


def test_inventory_checks_deadline_and_private_paths(fixture):
    import time
    from app.backtesting.research import holdout
    auth=fixture.claim(); fixture.output.mkdir(mode=0o700)
    with pytest.raises(h.HoldoutError,match='inventory_deadline'):
        holdout._inventory(auth,deadline=time.monotonic()-1)
    (fixture.output/'link').symlink_to(fixture.anchor,target_is_directory=True)
    with pytest.raises(h.HoldoutError,match='symlink'):
        holdout._inventory(auth,deadline=time.monotonic()+10)


def test_claim_failure_receipt_cannot_be_reused_for_other_output(fixture,monkeypatch):
    original=h._fsync_directory
    def fail(path):
        if (fixture.anchor/'claim.json').exists(): raise OSError('synthetic link failure')
        return original(path)
    monkeypatch.setattr(h,'_fsync_directory',fail)
    with pytest.raises(OSError) as caught: fixture.claim()
    assert h._claim_failure_receipt(caught.value,fixture.authority(),fixture.other_output) is None
    assert h._claim_failure_receipt(RuntimeError('not this attempt'),fixture.authority(),fixture.output) is None
    receipt=h._claim_failure_receipt(caught.value,fixture.authority(),fixture.output)
    assert receipt is not None
    with pytest.raises(h.HoldoutError,match='not_privately_issued'): h.require_claim(receipt,operation='source')


def test_cli_requires_one_mode_and_excludes_evaluation_options_from_replay(fixture):
    from app.backtesting.research import holdout
    for argv in ([],['--verify-retained','--output-root',str(fixture.output)],
                 ['--verify-retained','--timeout','1'],['--verify-retained','--max-output-bytes','100000']):
        with pytest.raises(SystemExit) as error: holdout.main(argv)
        assert error.value.code==2
    assert not (fixture.anchor/'claim.json').exists()


@pytest.mark.parametrize('guard', ['entries','metadata','hash_deadline'])
def test_inventory_limits_are_finite_before_terminal_encoding(fixture,monkeypatch,guard):
    import time
    from app.backtesting.research import holdout
    auth=fixture.claim(); fixture.output.mkdir(mode=0o700)
    if guard=='entries':
        monkeypatch.setattr(h,'INVENTORY_ENTRIES',1)
        expected='inventory_metadata_capacity'
        deadline=time.monotonic()+10
    elif guard=='metadata':
        monkeypatch.setattr(h,'METADATA_BYTES',1)
        expected='inventory_metadata_capacity'
        deadline=time.monotonic()+10
    else:
        readings=iter([0,0,2])
        monkeypatch.setattr(time,'monotonic',lambda:next(readings))
        expected='inventory_deadline'; deadline=1
    with pytest.raises(h.HoldoutError,match=expected): holdout._inventory(auth,deadline=deadline)


def test_failed_batch_terminal_is_bounded_and_does_not_touch_old_tree(fixture):
    from app.backtesting.research import holdout
    old=fixture.output.parent/'old-training'; old.mkdir(mode=0o700)
    sentinel=old/'still-running'; sentinel.write_bytes(b'old state')
    auth=fixture.claim(); contract=json.loads(auth.contract_bytes)
    units=holdout._pending_units(contract); units[0]['status']='running'
    holdout._publish_batch_failure_best_effort(auth,contract,units,RuntimeError('x'*10000))
    terminal=json.loads((auth.output_root/'status.json').read_bytes())
    assert terminal['holdout_status']=='failed'
    assert len(terminal['fatal_error']['message'])==2048
    assert terminal['units'][0]['status']=='failed'
    assert terminal['units'][1]['status']=='not_run_batch_failed'
    assert sentinel.read_bytes()==b'old state'


def test_controller_stages_share_a_decreasing_deadline(fixture,monkeypatch):
    from app.backtesting.research import holdout
    now=[100.0]; monkeypatch.setattr(h.time,'monotonic',lambda:now[0])
    auth=fixture.claim(timeout=100)
    seen=[]
    def stage():
        seen.append(h.remaining_limits(auth)['seconds']); now[0]+=10
    holdout._stage(auth,stage)
    holdout._stage(auth.for_slot('baseline','baseline'),stage)
    assert seen==[100,90]
    now[0]=200
    with pytest.raises(h.HoldoutError,match='deadline'): holdout._stage(auth,stage)
    assert seen==[100,90]


def test_authority_loading_is_also_inside_the_finite_controller_guard(fixture,monkeypatch):
    import time
    from app.backtesting.research import holdout
    from app.backtesting.research.orchestration import DeadlineExpired
    original=h.load_campaign_authority
    def stalled():
        time.sleep(.2)
        return original()
    monkeypatch.setattr(h,'load_campaign_authority',stalled)
    started=time.monotonic()
    with pytest.raises(DeadlineExpired): holdout.run_holdout(fixture.output,timeout=.02)
    assert time.monotonic()-started < .15
    assert not (fixture.anchor/'claim.json').exists() and not fixture.output.exists()


def test_current_inventory_pins_controller_guard_and_csv_dependencies():
    from app.backtesting.research import experiments, orchestration
    inventory=h._new_code_inventory()
    for module in (experiments,orchestration):
        assert inventory[str(Path(module.__file__))]==h._file_hash(Path(module.__file__))


@pytest.mark.parametrize('dependency', ['experiments.py','orchestration.py'])
def test_changed_controller_dependency_rejects_claim_and_retained_replay(fixture,tmp_path,monkeypatch,dependency):
    original_python=Path(h.__file__).parents[3]
    copied_python=tmp_path/'author-python'
    inventory=h._new_code_inventory()
    for name in inventory:
        source=Path(name); target=copied_python/source.relative_to(original_python)
        target.parent.mkdir(parents=True,exist_ok=True); target.write_bytes(source.read_bytes())
    copied_root=copied_python/'app'/'backtesting'/'research'
    monkeypatch.setattr(h,'__file__',str(copied_root/'holdout_authority.py'))
    monkeypatch.setattr(h,'_preflight_identities',lambda a,p:{'new_code':h._new_code_inventory()})
    auth=fixture.claim()
    target=copied_root/dependency
    target.write_bytes(b'changed synthetic dependency')
    with pytest.raises(h.HoldoutError,match='claimed_input_identity_changed'):
        h.require_claim(auth,operation='source')
    with pytest.raises(h.HoldoutError,match='retained_input_identity_changed'):
        h.mint_retained_authorization(fixture.authority())


def test_readonly_capacity_does_not_require_output_volume_free_space(fixture,monkeypatch):
    auth=fixture.claim(); fixture.output.mkdir(mode=0o700)
    retained=h.mint_retained_authorization(fixture.authority())
    monkeypatch.setattr(h.shutil,'disk_usage',lambda p:type('Disk',(),{'free':0})())
    assert h.remaining_limits(retained)['bytes']>0
    with pytest.raises(h.HoldoutError,match='verify_only_authorization'):
        h.require_claim(retained.for_slot('baseline','baseline'),operation='simulate',
            variant_id='baseline',cost_profile='baseline')
    with pytest.raises(h.HoldoutError,match='disk_reserve_required'): h.remaining_limits(auth)


def test_failure_inventory_never_reads_large_artifact_content(fixture,monkeypatch):
    import time
    from app.backtesting.research import holdout
    auth=fixture.claim(); fixture.output.mkdir(mode=0o700)
    artifact=fixture.output/'large.partial.ndjson'
    with artifact.open('wb') as stream: stream.truncate(4*1024**3)
    artifact.chmod(0o600)
    def forbidden(*a,**kw): pytest.fail('failure inventory opened artifact content')
    monkeypatch.setattr(Path,'open',forbidden)
    inventory=holdout._failure_inventory(auth,deadline=time.monotonic()+10)
    record=next(row for row in inventory['entries'] if row['path']==artifact.name)
    assert record['bytes']==4*1024**3 and record['hash_not_computed'] is True
    assert record['sha256'] is None
    assert inventory['accounting_complete'] and not inventory['truncated']


@pytest.mark.parametrize('limit', ['entries','metadata','deadline','fragment'])
def test_failure_inventory_reports_its_bounded_fragment_without_full_hash_claim(fixture,monkeypatch,limit):
    import time
    from app.backtesting.research import holdout
    auth=fixture.claim(); fixture.output.mkdir(mode=0o700)
    deadline=time.monotonic()+10
    if limit=='entries': monkeypatch.setattr(h,'INVENTORY_ENTRIES',1)
    elif limit=='metadata': monkeypatch.setattr(h,'METADATA_BYTES',1)
    elif limit=='deadline': deadline=time.monotonic()-1
    else:
        for n in range(140):
            path=fixture.output/(str(n)+'.partial'); path.write_bytes(b''); path.chmod(0o600)
    inventory=holdout._failure_inventory(auth,deadline=deadline)
    assert inventory['truncated']
    assert len(inventory['entries'])<=128
    assert inventory['accounting_complete']==(limit=='fragment')
    assert 'full' not in inventory


def test_failure_terminal_byte_accounting_deduplicates_hardlinks(fixture):
    from app.backtesting.research import holdout
    auth=fixture.claim(max_output_bytes=100000); fixture.output.mkdir(mode=0o700)
    path=fixture.output/'partial'; path.write_bytes(b'x'*70000); path.chmod(0o600)
    os.link(path,fixture.output/'same-inode')
    contract=json.loads(auth.contract_bytes)
    holdout._publish_batch_failure_best_effort(auth,contract,holdout._pending_units(contract),RuntimeError('failed'))
    terminal=json.loads((fixture.output/'status.json').read_bytes())
    assert terminal['holdout_status']=='failed'
    assert terminal['artifact_content_hashes_computed'] is False
    assert 'artifact_inventory_sha256' not in terminal
    assert terminal['inventory_accounting_complete'] is True


def test_existing_partial_unit_is_not_repaired_or_reused(fixture):
    from app.backtesting.research import holdout
    auth=fixture.claim(); fixture.output.mkdir(mode=0o700)
    root=fixture.output/'units'/'baseline'/'baseline'
    root.mkdir(parents=True,mode=0o700)
    for directory in (root.parent,root.parent.parent): directory.chmod(0o700)
    with pytest.raises(h.HoldoutError,match='fresh_unit_output_required'):
        holdout._unit_root(auth,'baseline','baseline')


def test_receipt_rejects_corrupted_retained_claim_without_repair(fixture,monkeypatch):
    original=h._fsync_directory
    def failed(path):
        if (fixture.anchor/'claim.json').exists(): raise OSError('synthetic link failure')
        return original(path)
    monkeypatch.setattr(h,'_fsync_directory',failed)
    with pytest.raises(OSError) as caught: fixture.claim()
    path=fixture.anchor/'claim.json'; raw=path.read_bytes(); path.write_bytes(raw+b' ')
    assert h._claim_failure_receipt(caught.value,fixture.authority(),fixture.output) is None
    assert path.read_bytes()==raw+b' ' and not fixture.output.exists()


@pytest.mark.parametrize('engine', [{'variant':'baseline'}], indirect=True)
def test_shared_signal_stage_uses_overall_clock_while_each_symbol_is_six_hours(engine,monkeypatch):
    import signal
    from app.backtesting.research import holdout
    observed=[]
    def worker(*args):
        observed.append((signal.getitimer(signal.ITIMER_REAL)[0],args[5]))
        raise RuntimeError('synthetic stop before first worker')
    monkeypatch.setattr(signals,'_run_symbol',worker)
    with pytest.raises(signals.SignalError,match='synthetic stop'): holdout.run_holdout(engine[0].output)
    assert len(observed)==1
    whole_stage,symbol=observed[0]
    assert whole_stage>h.VERIFIER_SECONDS
    assert 0<symbol<=h.VERIFIER_SECONDS


def test_failure_inventory_exhaustion_cannot_start_report_publication(fixture,monkeypatch):
    from app.backtesting.research import holdout
    auth=fixture.claim(); contract=json.loads(auth.contract_bytes)
    calls=[]; report_data=holdout._report_data
    def report(*args,**kwargs):
        calls.append(True)
        return report_data(*args,**kwargs)
    monkeypatch.setattr(holdout,'_report_data',report)
    monkeypatch.setattr(h,'INVENTORY_ENTRIES',1)
    holdout._publish_batch_failure_best_effort(auth,contract,holdout._pending_units(contract),RuntimeError('failed'))
    assert calls==[]
    assert not (fixture.output/'status.json').exists()
    assert (fixture.anchor/'claim.json').exists()


def test_terminal_diagnostic_marker_io_failure_preserves_primary_exception(fixture,monkeypatch):
    from app.backtesting.research import holdout
    auth=fixture.claim(); fixture.output.mkdir(mode=0o700)
    claim=(fixture.anchor/'claim.json').read_bytes()
    fsync=h._fsync_directory; link=os.link
    def directory_fsync(path):
        if (fixture.output/'status.json').exists(): raise OSError('primary terminal fsync')
        return fsync(path)
    def hardlink(source,destination,**kwargs):
        if Path(destination).name.startswith('.terminal-publication-failed-'):
            raise OSError('secondary marker failure')
        return link(source,destination,**kwargs)
    monkeypatch.setattr(h,'_fsync_directory',directory_fsync)
    monkeypatch.setattr(os,'link',hardlink)
    with pytest.raises(OSError,match='primary terminal fsync'):
        holdout._publish_artifacts(auth,[(fixture.output/'status.json',b'publication test')],terminal=True)
    assert (fixture.anchor/'claim.json').read_bytes()==claim
    assert not list(fixture.output.glob('.terminal-publication-failed-*'))
