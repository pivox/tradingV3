"""Synthetic streaming composition and immutable completion evidence."""
import copy
from dataclasses import asdict, replace
from datetime import datetime, timezone
from decimal import Decimal as D
import hashlib
import json
from pathlib import Path

import pytest

from app.backtesting.research import campaign as c, signals
from app.backtesting.research.funding import FundingInventory, SymbolQuality, FundingEvent
from app.backtesting.research.portfolio_simulator import canonical_hash
from app.backtesting.research.signal_sources import SourceSelection
from tests.test_research_plans import manifests, H
from tests.test_research_signals import _worker_evidence, result_fixture
from tests.test_research_portfolio_simulator import plan

START = 1672531200000
SOURCE_START = START-250*14400000


def iso(t): return datetime.fromtimestamp(t/1000,timezone.utc).isoformat()


class Builder:
    positive = False
    calls = []
    malformed = False
    def __init__(self,app_dir,opening,**limits):
        self.opening = opening
        self.opened = {'research_code_hash':canonical_hash(c.plans.code_inventory(app_dir)),'code_hash_scope':c.plans.CODE_SCOPE,
                       'code_identity_check_policy':c.plans.CODE_POLICY}
        self.count = self.planned = 0
    def __enter__(self): return self
    def __exit__(self,*args): pass
    def evidence(self): return {'stderr_bytes':0,'stderr_sha256':'0'*64,'stdout_bytes':0,'exit_code':0}
    def build(self,sig,view):
        self.calls.append((sig.index,sig.payload['evaluated_ms'],view['as_of_ms']))
        self.count += 1
        if self.malformed: raise c.plans.PlanError('malformed reply')
        if not self.positive:
            return {'schema_version':'research-plan-rejection.v1','reason_code':'entry_zone_candidate_outside',
                    'portfolio_hash':view['portfolio_hash']}
        self.planned += 1
        p = plan(sig,view)
        o,b = self.opening,self.opening['baseline']
        run = next(r for r in o['source_runs'] if r['symbol'] == sig.payload['symbol'])
        p.update({'base_setup_hash':b['setup_hash'],'base_config_hash':b['config_hash'],
            'base_catalog_hash':b['condition_catalog_hash'],'base_snapshot_hash':b['snapshot_hash'],
            'variant_id':o['variant']['id'],'variant_hash':o['variant']['variant_hash'],
            'instrument_assumptions_hash':o['instrument_assumptions']['manifest_hash'],
            'cost_assumptions_hash':o['cost_assumptions']['assumption_hash'],'research_code_hash':self.opened['research_code_hash'],
            'dataset_id':run['source']['dataset_id'],'dataset_sha256':run['source']['dataset_sha256'],
            'signal_run_id':run['signal_run_id'],'signal_output_sha256':run['signal_output_sha256']})
        p['plan_hash'] = canonical_hash({k:v for k,v in p.items() if k != 'plan_hash'})
        return p
    def close(self): return {'received':self.count,'planned':self.planned,'completion':'complete'}


def fixture(tmp_path,monkeypatch,*,passed=False,symbols=('BTCUSDT',),minutes=3):
    Builder.positive = Builder.malformed = False
    Builder.calls = []
    app = tmp_path/'app'
    app.mkdir()
    ev = _worker_evidence(app)
    ast = {'execution':{'targets':{'value':[{'liquidity_role':'maker'}]},
            'cost_contract':{'value':{'entry_liquidity_role':'maker','stop_liquidity_role':'taker',
                                    'funding_interval_seconds':28800}},
            'time_stop':{'value':'PT8H'}}}
    ev['snapshot']['config']['setup']['ast'] = ast
    ev['snapshot']['config']['exchange'].update({'fees':{'maker_rate':.0002,'taker_rate':.0005},
                                               'funding':{'interval':'PT8H'}})
    from app.modern_trading_contracts import calculate_config_hash,calculate_snapshot_hash
    ev['snapshot']['config_hash'] = calculate_config_hash(ev['snapshot']['config'],ev['snapshot']['condition_catalog_hash'])
    ev['snapshot']['snapshot_hash'] = calculate_snapshot_hash(ev['snapshot'])
    ev['baseline'].update({k:ev['snapshot'][k] for k in ('config_hash','snapshot_hash')})
    source = tmp_path/'source'
    source.mkdir(mode=0o700)
    (source/'manifest.json').write_text('{}')
    source_manifest_sha = hashlib.sha256(b'{}').hexdigest()
    signal_root = tmp_path/'signals'
    signal_root.mkdir(mode=0o700)
    end = START+minutes*60000
    selections = {s:SourceSelection(s,datetime.fromtimestamp(SOURCE_START/1000,timezone.utc),
        datetime.fromtimestamp(end/1000,timezone.utc),datetime.fromtimestamp(START/1000,timezone.utc),
        source_manifest_sha,hashlib.sha256(s.encode()).hexdigest(),(),(end-SOURCE_START)//60000,
        1, (START-SOURCE_START)//900000-1, 0) for s in symbols}
    report = {'schema':'research-signal-run.v1','status':'complete','source_start':iso(SOURCE_START),
        'source_end':iso(end),'score_start':iso(START),'symbols':{},'errors':[],
        'code_sha256':{'synthetic-code':'d'*64}}
    for s in symbols:
        sel = selections[s]
        frame = result_fixture(START)
        frame.update({'session_id':f'research-{s}-{sel.dataset_sha256[:20]}','symbol':s,
            'source':{'dataset_id':'research-'+sel.dataset_sha256[:24],
                'dataset_sha256':sel.dataset_sha256,'source_venue':'binance_usdm',
                'source_network':'mainnet','market_type':'perpetual'},
            'execution':ev['execution'],'baseline':ev['baseline'],'passed':passed})
        if passed:
            frame['reason_code'] = 'setup_rules_passed'
            frame['trace'].update({'mode_id':'day_trading','mode_version':'1.1.0',
                'setup_id':'day_trading.trend_continuation.long','setup_version':'1.1.0','side':'long',
                'setup_hash':ev['baseline']['setup_hash'],'catalog_hash':ev['baseline']['condition_catalog_hash'],
                'config_hash':ev['baseline']['config_hash'],'execution_timeframe':'15m',
                'mandatory_confirmations':['5m','1m'],'evaluated_at':frame['evaluated_at']})
            frame['trace_hash'] = canonical_hash(frame['trace'])
        frame['result_hash'] = canonical_hash({k:v for k,v in frame.items() if k != 'result_hash'})
        raw = (json.dumps(frame)+'\n').encode()
        (signal_root/(s+'.results.ndjson')).write_bytes(raw)
        report['symbols'][s] = {'status':'complete','source_candles':sel.expected_candles,
            'evaluated_ticks':1,'passed_rules':int(passed),'failed_rules':int(not passed),
            'scored_evaluations':1,'scored_passed_rules':int(passed),'scored_failed_rules':int(not passed),
            'boundary_diagnostic_count':0,'boundary_sha256':hashlib.sha256(b'').hexdigest(),
            'result_sha256':hashlib.sha256(raw).hexdigest(),'dataset_sha256':sel.dataset_sha256,
            'manifest_sha256':sel.manifest_sha256,'baseline':ev['baseline'],
            'effective_config_snapshot':ev['snapshot'],'execution':ev['execution'],
            'indicator_engine_version':'php_fallback_v1'}
    report['totals'] = {'completed_symbols':len(symbols),'source_candles':sum(s.expected_candles for s in selections.values()),
        'evaluated_ticks':len(symbols),'scored_evaluations':len(symbols)}
    (signal_root/'report.json').write_text(json.dumps(report))
    instruments,costs = manifests()
    assumption_root = tmp_path/'assumptions'
    assumption_root.mkdir()
    ip,cp = assumption_root/'instrument.json',assumption_root/'costs.json'
    ip.write_text(json.dumps(instruments));cp.write_text(json.dumps(costs))
    monkeypatch.setattr(c,'select_sources',lambda root,start,end,score,s:selections[s])
    def candles(root,selection):
        for t in range(SOURCE_START,end,60000):
            yield {'symbol':selection.symbol,'open_ms':t,'open':'100','low':'99','high':'120' if t >= START+60000 else '101','close':'100'}
    monkeypatch.setattr(c,'iter_verified_candles',candles)
    monkeypatch.setattr(c.signals,'_code_hashes',lambda *args,**kwargs:report['code_sha256'])
    monkeypatch.setattr(c.plans,'code_inventory',lambda app:{'scope':c.plans.CODE_SCOPE,'files':{'synthetic':'a'*64}})
    monkeypatch.setattr(c.plans,'PlanWorker',Builder)
    quality = tuple(SymbolQuality(s,1,START,START,'declared_interval_consistent',True,0,(('8',1),),(),()) for s in symbols)
    inventory = FundingInventory(source_manifest_sha,None,START,end,'observed_only','not_attested',1000,None,None,True,(),quality,'')
    data = asdict(inventory); data.pop('inventory_hash')
    inventory = replace(inventory,inventory_hash=canonical_hash(data))
    class Reader:
        def __init__(self,*args,**kwargs): self.inventory = inventory
        def iter_events(self):
            for s in symbols: yield FundingEvent(START,s,D('.001'),None,D('8'),'https://synthetic',H,'d'*64,'e'*64)
    monkeypatch.setattr(c,'FundingReader',Reader)
    kwargs = dict(dataset_root=source,signal_root=signal_root,output_root=tmp_path/'run',app_dir=app,
        phase='training',variant_id='baseline',cost_profile='baseline',instrument_path=ip,cost_path=cp,
        symbols=symbols,min_free_bytes=0)
    return kwargs,report,selections


def test_zero_run_preserves_denominator_initial_funding_and_exact_windows(tmp_path,monkeypatch):
    kwargs,_,selections = fixture(tmp_path,monkeypatch)
    result = c.run_campaign(**kwargs)
    assert result['status'] == 'complete'
    assert result['trades'] == 0 and result['wallet_quote'] == D('100000')
    assert result['b1_counters']['failed_rules'] == 1
    assert result['source_candles'] == sum(s.expected_candles for s in selections.values())
    assert result['processed_batches'] == 3
    assert result['funding_events'] == 1
    assert result['source_quality']['funding_inventory']['coverage'] == 'observed_only'
    assert result['profit_factor'] is None and result['profit_factor_reason']
    assert json.loads((kwargs['output_root']/'status.json').read_text())['status'] == 'complete'


def test_positive_fixture_uses_start_index_and_reconciles_ledger(tmp_path,monkeypatch):
    kwargs,_,_ = fixture(tmp_path,monkeypatch,passed=True)
    Builder.positive = True
    result = c.run_campaign(**kwargs)
    assert result['trades'] == 1
    assert result['net_pnl_quote'] == D('19.916')
    assert Builder.calls == [(0,START,START)]
    assert result['reconciliation']['status'] == 'verified'


def test_legitimate_planner_rejection_and_malformed_error_differ(tmp_path,monkeypatch):
    kwargs,_,_ = fixture(tmp_path,monkeypatch,passed=True)
    assert c.run_campaign(**kwargs)['trades'] == 0
    kwargs['output_root'] = tmp_path/'malformed'
    Builder.malformed = True
    with pytest.raises(c.CampaignError): c.run_campaign(**kwargs)
    assert json.loads((kwargs['output_root']/'status.json').read_text())['status'] == 'failed'
    assert not (kwargs['output_root']/'summary.json').exists()


@pytest.mark.parametrize('mutation',['incomplete','hash','signal_hash','chronology','count','source','code','baseline','window','passed','boundary'])
def test_input_tampering_is_failure_with_evidence(tmp_path,monkeypatch,mutation):
    kwargs,report,_ = fixture(tmp_path,monkeypatch)
    row = report['symbols']['BTCUSDT']
    root = kwargs['signal_root']
    if mutation == 'incomplete': report['status'] = 'failed'
    elif mutation == 'hash': row['result_sha256'] = 'a'*64
    elif mutation in ('signal_hash','chronology','passed','boundary'):
        path = root/'BTCUSDT.results.ndjson'
        frame = json.loads(path.read_text())
        if mutation == 'signal_hash': frame['result_hash'] = H
        elif mutation == 'passed': frame['passed'] = 'true'
        else: frame['evaluated_ms'] += 60000 if mutation == 'chronology' else 180000
        raw = (json.dumps(frame)+'\n').encode();path.write_bytes(raw)
        row['result_sha256'] = hashlib.sha256(raw).hexdigest()
    elif mutation == 'count': row['scored_evaluations'] += 1
    elif mutation == 'source': row['dataset_sha256'] = 'a'*64
    elif mutation == 'code': report['code_sha256'] = {'foreign':'a'*64}; monkeypatch.setattr(c.signals,'_code_hashes',lambda *a,**k:{'synthetic-code':'d'*64})
    elif mutation == 'baseline': row['baseline']['snapshot_hash'] = H
    elif mutation == 'window': report['score_start'] = '2026-01-01T00:00:00Z'
    (root/'report.json').write_text(json.dumps(report))
    with pytest.raises(c.CampaignError): c.run_campaign(**kwargs)
    assert json.loads((kwargs['output_root']/'status.json').read_text())['status'] == 'failed'


@pytest.mark.parametrize('continuity',['gaps','inconclusive','assumed_interval'])
def test_raw_funding_provenance_and_quality_stay_visible(tmp_path,monkeypatch,continuity):
    kwargs,_,_ = fixture(tmp_path,monkeypatch)
    previous = c.FundingReader
    class Reader(previous):
        def __init__(self,*a,**k):
            super().__init__(*a,**k)
            self.inventory = replace(self.inventory,symbols=(replace(self.inventory.symbols[0],continuity=continuity),))
            payload = asdict(self.inventory);payload.pop('inventory_hash')
            self.inventory = replace(self.inventory,inventory_hash=canonical_hash(payload))
    monkeypatch.setattr(c,'FundingReader',Reader)
    result = c.run_campaign(**kwargs)
    assert result['status'] == ('complete' if continuity == 'assumed_interval' else 'inconclusive')
    assert result['source_quality']['funding_inventory']['coverage'] == 'observed_only'


def test_two_disjoint_reports_fixed_priority_and_no_fabricated_combined_hash(tmp_path,monkeypatch):
    kwargs,report,_ = fixture(tmp_path,monkeypatch,symbols=('BTCUSDT','ETHUSDT'))
    roots = []
    for s in ('BTCUSDT','ETHUSDT'):
        root = tmp_path/s;root.mkdir()
        divided = copy.deepcopy(report);divided['symbols'] = {s:divided['symbols'][s]}
        divided['totals'] = {'completed_symbols':1,'source_candles':divided['symbols'][s]['source_candles'],
                            'evaluated_ticks':1,'scored_evaluations':1}
        (root/'report.json').write_text(json.dumps(divided))
        (root/(s+'.results.ndjson')).write_bytes((kwargs['signal_root']/(s+'.results.ndjson')).read_bytes())
        roots.append(root)
    kwargs['signal_root'] = tuple(roots)
    result = c.run_campaign(**kwargs)
    assert result['b1_counters']['evaluated_ticks'] == 2
    manifest = json.loads((kwargs['output_root']/'manifest.json').read_text())
    assert len(manifest['signal_reports']) == 2


def test_python_checkout_paths_may_differ_but_php_app_binding_and_code_bytes_must_match():
    logical = ('app/backtesting/research/signals.py','app/backtesting/research/signal_sources.py',
               'app/backtesting/research/binance_history.py','app/modern_trading_contracts.py')
    left = {'/one/python-orchestrator/'+name:'a'*64 for name in logical}
    right = {'/two/python-orchestrator/'+name:'a'*64 for name in logical}
    left['/frozen/trading-app/src/Worker.php'] = right['/frozen/trading-app/src/Worker.php'] = 'b'*64
    assert c._b1_code_matches(left,right)
    right['/two/python-orchestrator/'+logical[0]] = 'c'*64
    assert not c._b1_code_matches(left,right)
    right['/two/python-orchestrator/'+logical[0]] = 'a'*64
    right['/other/trading-app/src/Worker.php'] = right.pop('/frozen/trading-app/src/Worker.php')
    assert not c._b1_code_matches(left,right)


def test_duplicate_unknown_missing_python_code_bindings_fail():
    logical = 'app/backtesting/research/signals.py'
    current = {'/one/python-orchestrator/'+logical:'a'*64}
    recorded = {'/two/python-orchestrator/'+logical:'a'*64,'/three/python-orchestrator/'+logical:'a'*64}
    assert not c._b1_code_matches(recorded,current)
    assert not c._b1_code_matches({'unknown':'a'*64},current)
    assert not c._b1_code_matches({},current)
    assert not c._b1_code_matches(None,current)


@pytest.mark.parametrize('mutation',['emptyroots','duplicateroot','universe','unknownsymbol','aggregates','missingselected','differentbaseline'])
def test_report_topology_and_aggregate_contracts(tmp_path,monkeypatch,mutation):
    kwargs,report,_ = fixture(tmp_path,monkeypatch,symbols=('BTCUSDT','ETHUSDT'))
    if mutation == 'emptyroots': kwargs['signal_root'] = ()
    elif mutation == 'duplicateroot': kwargs['signal_root'] = (kwargs['signal_root'],kwargs['signal_root'])
    elif mutation == 'universe': kwargs['symbols'] = ('ETHUSDT','BTCUSDT')
    elif mutation == 'unknownsymbol': report['symbols']['DOGEUSDT'] = report['symbols'].pop('ETHUSDT')
    elif mutation == 'aggregates': report['totals']['scored_evaluations'] += 1
    elif mutation == 'missingselected': kwargs['symbols'] += ('BNBUSDT',)
    elif mutation == 'differentbaseline':
        monkeypatch.setattr(c.signals,'_validate_opened',lambda *args:None)
        monkeypatch.setattr(c,'_scan',lambda *args,**kwargs:iter(()))
        report['symbols']['ETHUSDT']['baseline'] = copy.deepcopy(report['symbols']['ETHUSDT']['baseline'])
        report['symbols']['ETHUSDT']['baseline']['setup_hash'] = 'b'*64
    (tmp_path/'signals/report.json').write_text(json.dumps(report))
    with pytest.raises(c.CampaignError): c.run_campaign(**kwargs)


@pytest.mark.parametrize('mutation',['missing','symlink','partialline','longline','passed_reason','denominator'])
def test_scored_file_failure_modes(tmp_path,monkeypatch,mutation):
    kwargs,report,_ = fixture(tmp_path,monkeypatch,passed=mutation == 'passed_reason')
    path = kwargs['signal_root']/'BTCUSDT.results.ndjson'
    raw = path.read_bytes()
    if mutation == 'missing': path.unlink()
    elif mutation == 'symlink':
        path.unlink();target = tmp_path/'foreign';target.write_bytes(raw);path.symlink_to(target)
    elif mutation == 'partialline': path.write_bytes(raw.rstrip(b'\n'))
    elif mutation == 'longline': path.write_bytes(b'x'*(signals.MAX_LINE_BYTES+1))
    elif mutation == 'passed_reason':
        frame = json.loads(raw);frame['reason_code'] = 'forged'
        frame['result_hash'] = canonical_hash({k:v for k,v in frame.items() if k != 'result_hash'})
        raw = (json.dumps(frame)+'\n').encode();path.write_bytes(raw)
        report['symbols']['BTCUSDT']['result_sha256'] = hashlib.sha256(raw).hexdigest()
        (kwargs['signal_root']/'report.json').write_text(json.dumps(report))
    else:
        report['symbols']['BTCUSDT'].update(scored_passed_rules=1,scored_failed_rules=0,passed_rules=1,failed_rules=0)
        (kwargs['signal_root']/'report.json').write_text(json.dumps(report))
    with pytest.raises(c.CampaignError): c.run_campaign(**kwargs)


def test_boundary_diagnostics_verified_and_never_scored(tmp_path,monkeypatch):
    kwargs,report,selections = fixture(tmp_path,monkeypatch,minutes=15)
    sel = selections['BTCUSDT']
    selections['BTCUSDT'] = replace(sel,expected_evaluations=2)
    row = report['symbols']['BTCUSDT'];row.update(evaluated_ticks=2,failed_rules=2,boundary_diagnostic_count=1)
    boundary = json.loads((kwargs['signal_root']/'BTCUSDT.results.ndjson').read_text())
    tick = START+900000
    boundary.update(evaluated_ms=tick,evaluated_at=datetime.fromtimestamp(tick/1000,timezone.utc).strftime('%Y-%m-%dT%H:%M:%S.000000Z'),
                    contexts=result_fixture(tick)['contexts'])
    boundary['result_hash'] = canonical_hash({k:v for k,v in boundary.items() if k != 'result_hash'})
    raw = (json.dumps(boundary)+'\n').encode()
    (kwargs['signal_root']/'BTCUSDT.boundary.ndjson').write_bytes(raw)
    row['boundary_sha256'] = hashlib.sha256(raw).hexdigest()
    report['totals']['evaluated_ticks'] = 2
    (kwargs['signal_root']/'report.json').write_text(json.dumps(report))
    result = c.run_campaign(**kwargs)
    assert result['b1_counters']['evaluated_ticks'] == 1
    assert result['attempted_signals'] == 0


@pytest.mark.parametrize('mutation',['phase','deadline','assumption','funding_window','funding_hash','worker_hash','planner_count','code_changed','missingprime','source_count','malformedplan'])
def test_composition_failures_are_preserved(tmp_path,monkeypatch,mutation):
    kwargs,report,selections = fixture(tmp_path,monkeypatch,passed=mutation == 'malformedplan')
    if mutation == 'phase': kwargs['phase'] = 'holdout'
    elif mutation == 'deadline': kwargs['wall_timeout'] = 1e-9
    elif mutation == 'assumption':
        doc = json.loads(kwargs['cost_path'].read_text());doc['assumption_hash'] = H
        kwargs['cost_path'].write_text(json.dumps(doc))
    elif mutation.startswith('funding_'):
        previous = c.FundingReader
        class Reader(previous):
            def __init__(self,*a,**k):
                super().__init__(*a,**k)
                self.inventory = replace(self.inventory,**({'start_ms':START+1} if mutation == 'funding_window' else {'inventory_hash':H}))
        monkeypatch.setattr(c,'FundingReader',Reader)
    elif mutation in ('worker_hash','planner_count','malformedplan'):
        class InvalidBuilder(Builder):
            def __init__(self,*a,**k):
                super().__init__(*a,**k)
                if mutation == 'worker_hash': self.opened['research_code_hash'] = H
            def close(self):
                value = super().close();value['received'] += 1;return value
            def build(self,*a):
                value = super().build(*a)
                if mutation == 'malformedplan': value['plan_hash'] = H
                return value
        if mutation == 'malformedplan': Builder.positive = True
        monkeypatch.setattr(c.plans,'PlanWorker',InvalidBuilder)
    elif mutation == 'code_changed':
        previous = c._runner_code
        calls = []
        def fingerprints():
            calls.append(1);return previous() if len(calls) == 1 else {'changed':'a'*64}
        monkeypatch.setattr(c,'_runner_code',fingerprints)
    else:
        previous = c.iter_verified_candles
        def incomplete(root,selection):
            for candle in previous(root,selection):
                if mutation == 'missingprime' and candle['open_ms'] < START: continue
                if mutation == 'source_count' and candle['open_ms'] == SOURCE_START: continue
                yield candle
        monkeypatch.setattr(c,'iter_verified_candles',incomplete)
    with pytest.raises(c.CampaignError): c.run_campaign(**kwargs)
    assert json.loads((kwargs['output_root']/'status.json').read_text())['status'] == 'failed'


def test_cost_contract_no_defaults_and_explicit_taker_target(tmp_path,monkeypatch):
    kwargs,report,_ = fixture(tmp_path,monkeypatch)
    snapshot = report['symbols']['BTCUSDT']['effective_config_snapshot']
    profile = json.loads(kwargs['cost_path'].read_text())['profiles']['baseline']
    changed = copy.deepcopy(snapshot)
    changed['config']['setup']['ast']['execution']['targets']['value'][0]['liquidity_role'] = 'taker'
    assert c._kernel_costs(changed,profile).target_fee_rate == D('.0005')
    for key,value in (('time_stop','PT7H'),('targets',[])):
        changed = copy.deepcopy(snapshot)
        changed['config']['setup']['ast']['execution'][key]['value'] = value
        with pytest.raises(c.CampaignError): c._kernel_costs(changed,profile)


def test_clock_unconsumed_future_event_and_relative_input():
    clock = c._Clock(iter((1,3)),lambda value:value)
    assert clock.through(1) == (1,)
    with pytest.raises(c.CampaignError): clock.exhausted()
    assert clock.through(3) == (3,)
    clock.exhausted()
    with pytest.raises(c.CampaignError): c._read(Path('relative'),10)


def test_funding_inventory_must_bind_same_candle_acquisition_manifest(tmp_path,monkeypatch):
    kwargs,_,_ = fixture(tmp_path,monkeypatch)
    previous = c.FundingReader
    class Reader(previous):
        def __init__(self,*a,**k):
            super().__init__(*a,**k)
            self.inventory = replace(self.inventory,acquisition_manifest_sha256='a'*64)
            payload = asdict(self.inventory);payload.pop('inventory_hash')
            self.inventory = replace(self.inventory,inventory_hash=canonical_hash(payload))
    monkeypatch.setattr(c,'FundingReader',Reader)
    with pytest.raises(c.CampaignError): c.run_campaign(**kwargs)


def test_observed_funding_mark_and_between_boundary_charge(tmp_path,monkeypatch):
    kwargs,_,_ = fixture(tmp_path,monkeypatch,passed=True)
    Builder.positive = True
    previous = c.FundingReader
    class Reader(previous):
        def iter_events(self):
            for event in super().iter_events():
                yield replace(event,timestamp_ms=START+60001,observed_mark=D('100'))
    monkeypatch.setattr(c,'FundingReader',Reader)
    result = c.run_campaign(**kwargs)
    assert result['funding_quote'] == D('-.200')
    assert result['net_pnl_quote'] == D('19.716')


def test_failed_status_write_keeps_attempt_and_partial_evidence(tmp_path,monkeypatch):
    kwargs,report,_ = fixture(tmp_path,monkeypatch)
    report['status'] = 'failed';(kwargs['signal_root']/'report.json').write_text(json.dumps(report))
    original = c.Evidence.json
    def exhausted(evidence,name,value):
        if name == 'status.json': raise OSError('full disk')
        return original(evidence,name,value)
    monkeypatch.setattr(c.Evidence,'json',exhausted)
    with pytest.raises(c.CampaignError): c.run_campaign(**kwargs)
    assert (kwargs['output_root']/'attempt.json').exists()


@pytest.mark.parametrize('cap',[15000,22000])
def test_real_evidence_cap_exhaustion_publishes_failed_status(tmp_path,monkeypatch,cap):
    kwargs,_,_ = fixture(tmp_path,monkeypatch,passed=True)
    Builder.positive = True
    with pytest.raises(c.CampaignError,match='disk cap'):
        c.run_campaign(**kwargs,max_output_bytes=cap)
    out = kwargs['output_root']
    assert json.loads((out/'status.json').read_text())['status'] == 'failed'
    assert sum(p.stat().st_size for p in out.iterdir()) <= cap


def test_final_publication_exhaustion_preserves_failed_status(tmp_path,monkeypatch):
    kwargs,_,_ = fixture(tmp_path,monkeypatch,passed=True)
    Builder.positive = True
    original = c.Evidence.json
    def limited(evidence,name,value):
        if name == 'summary.json':
            evidence.max_bytes = evidence.bytes+8192
        return original(evidence,name,value)
    monkeypatch.setattr(c.Evidence,'json',limited)
    with pytest.raises(c.CampaignError,match='disk cap'): c.run_campaign(**kwargs)
    out = kwargs['output_root']
    assert (out/'trades.ndjson').exists()
    assert not (out/'summary.json').exists()
    assert json.loads((out/'status.json').read_text())['status'] == 'failed'


def test_initial_attempt_budget_refuses_before_root(tmp_path,monkeypatch):
    kwargs,_,_ = fixture(tmp_path,monkeypatch)
    with pytest.raises(c.EvidenceError): c.run_campaign(**kwargs,max_output_bytes=8193)
    assert not kwargs['output_root'].exists()


def test_failure_unicode_diagnostics_are_bounded_and_hashed(tmp_path,monkeypatch):
    kwargs,_,_ = fixture(tmp_path,monkeypatch)
    message = '\U0001f680'*10000
    error_type = type('\U0001f680'*1000,(RuntimeError,),{})
    def failed(*args): raise error_type(message)
    monkeypatch.setattr(c,'_verify_reports',failed)
    with pytest.raises(c.CampaignError): c.run_campaign(**kwargs)
    raw = (kwargs['output_root']/'status.json').read_bytes()
    error = json.loads(raw)['error']
    assert len(raw) <= 8192
    assert error['message_truncated'] and error['type_truncated']
    assert error['message_sha256'] == hashlib.sha256(message.encode()).hexdigest()


def test_oversized_success_status_refuses_before_summary(tmp_path,monkeypatch):
    kwargs,_,_ = fixture(tmp_path,monkeypatch)
    original = c.Evidence.finish_ledgers
    def oversized(evidence):
        files = original(evidence)
        files['events.ndjson']['sha256'] = 'x'*8192
        return files
    monkeypatch.setattr(c.Evidence,'finish_ledgers',oversized)
    with pytest.raises(c.CampaignError,match='terminal status'): c.run_campaign(**kwargs)
    assert not (kwargs['output_root']/'summary.json').exists()
    assert json.loads((kwargs['output_root']/'status.json').read_text())['status'] == 'failed'


def test_cli_one_unit_with_repeated_report_roots_and_error_exit(tmp_path,monkeypatch,capsys):
    values = []
    def run(**kwargs): values.append(kwargs);return {'status':'complete','run_id':'synthetic'}
    monkeypatch.setattr(c,'run_campaign',run)
    argv = []
    for name in ('dataset-root','signal-root','output-root','app-dir','instrument-path','cost-path'):
        argv += ['--'+name,str(tmp_path/name)]
    argv += ['--signal-root',str(tmp_path/'second'),'--phase','training','--variant-id','baseline','--cost-profile','baseline']
    assert c.main(argv) == 0
    assert len(values[0]['signal_root']) == 2
    def failed(**kwargs): raise c.CampaignError('input failure')
    monkeypatch.setattr(c,'run_campaign',failed)
    assert c.main(argv) == 1
    assert 'input failure' in capsys.readouterr().err
