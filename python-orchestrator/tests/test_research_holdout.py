"""Guarded engine tests use only private synthetic fixtures and worker transports."""
import dataclasses
import hashlib
import json
from pathlib import Path

import pytest

from app.backtesting.research import campaign, signals, signal_sources, statistics
from app.backtesting.research import holdout_authority as h
from tests.test_research_holdout_authority import fixture
from tests import test_research_campaign as upstream
from app.backtesting.research.portfolio_simulator import canonical_hash


@pytest.mark.parametrize('entry', ['source', 'signals', 'campaign', 'statistics', 'verify'])
@pytest.mark.parametrize('kind', ['none', 'replaced'])
def test_all_guarded_entrypoints_deny_forged_capabilities_before_data(fixture, monkeypatch, entry, kind):
    auth = fixture.claim().for_slot('baseline', 'baseline')
    forged = None if kind == 'none' else dataclasses.replace(auth)
    def denied(*args, **kwargs):
        pytest.fail('market or profit metadata read before issuance guard')
    monkeypatch.setattr(signal_sources, '_read_bounded', denied)
    monkeypatch.setattr(campaign, '_input', denied)
    monkeypatch.setattr(statistics, '_read_json', denied)
    with pytest.raises(h.HoldoutError, match='authorization_not_privately_issued'):
        if entry == 'source':
            signal_sources.select_holdout_sources(Path('/absent'), 'BTCUSDT', authorization=forged)
        elif entry == 'signals':
            signals.run_holdout_signals(Path('/absent'), authorization=forged)
        elif entry == 'campaign':
            campaign.run_holdout_campaign(Path('/absent'), authorization=forged,
                variant_id='baseline', cost_profile='baseline')
        elif entry == 'statistics':
            statistics.analyze_holdout_ledgers(Path('/absent'), {}, authorization=forged,
                variant_id='baseline', cost_profile='baseline')
        else:
            statistics.verify_holdout_campaign(Path('/absent'), authorization=forged,
                variant_id='baseline', cost_profile='baseline')


def test_existing_public_statistics_still_close_2026_before_ledger_read(tmp_path):
    with pytest.raises(statistics.StatisticsError, match='holdout_closed'):
        statistics.analyze_ledgers(tmp_path, {}, symbols=h.SYMBOLS,
            start_ms=1767225600000, end_ms=1791525600000)


def test_holdout_cutoff_is_readonly(fixture):
    auth = fixture.claim()
    assert auth.end_ms == 1791525600000
    with pytest.raises(dataclasses.FrozenInstanceError):
        auth.end_ms = 0


def test_shared_budget_does_not_reset_on_slot_and_counts_physical_bytes_once(fixture, monkeypatch):
    now = [100.0]
    monkeypatch.setattr(h.time, 'monotonic', lambda: now[0])
    auth = fixture.claim(timeout=100)
    fixture.output.mkdir(mode=0o700)
    (fixture.output/'evidence').write_bytes(b'12345')
    (fixture.output/'evidence').chmod(0o600)
    first = h.remaining_limits(auth)
    assert first['seconds'] == 100
    assert first['bytes'] == h.OVERALL_BYTES - first['used_bytes'] - h.TERMINAL_RESERVE
    now[0] = 140
    slot = auth.for_slot('baseline', 'baseline')
    assert h.remaining_limits(slot)['seconds'] == 60
    assert h.remaining_limits(slot)['used_bytes'] == first['used_bytes']
    now[0] = 200
    with pytest.raises(h.HoldoutError, match='deadline'):
        h.remaining_limits(slot)


@pytest.fixture
def engine(fixture, tmp_path, monkeypatch, request):
    """Three synthetic minutes, not a full-calendar evaluation."""
    base = tmp_path/'engine'; base.mkdir(mode=0o700)
    start = 1767225600000
    source_start = start-250*14400000
    monkeypatch.setattr(upstream, 'START', start)
    monkeypatch.setattr(upstream, 'SOURCE_START', source_start)
    kwargs, report, selections = upstream.fixture(base, monkeypatch, passed=True, symbols=h.SYMBOLS)
    monkeypatch.setattr(h, 'SOURCE_START', upstream.iso(source_start))
    monkeypatch.setattr(h, 'SCORE_START', upstream.iso(start))
    monkeypatch.setattr(h, 'END', upstream.iso(start+180000))
    for key in ('dataset_root', 'app_dir', 'instrument_path', 'cost_path'):
        fixture.config['paths'][key] = str(kwargs[key])
    fixture.freeze['cutoff'] = h.END
    fixture.protocol['cutoff'] = h.END
    fixture.config['protocol_hash'] = fixture.freeze['protocol_hash'] = h.hash_bytes(h.canonical_bytes(fixture.protocol))
    fixture.installed.write_bytes(h.canonical_bytes(fixture.config))
    (Path(fixture.config['registry'])/'protocol.json').write_bytes(h.canonical_bytes(fixture.protocol))
    fixture.selected(getattr(request,'param','baseline'))
    baseline = report['symbols']['BTCUSDT']['baseline']
    ip = json.loads(kwargs['instrument_path'].read_bytes())
    cp = json.loads(kwargs['cost_path'].read_bytes())
    identity = {'baseline':baseline, 'php':campaign.plans.code_inventory(kwargs['app_dir']),
        'instrument_sha256':hashlib.sha256(kwargs['instrument_path'].read_bytes()).hexdigest(),
        'cost_sha256':hashlib.sha256(kwargs['cost_path'].read_bytes()).hexdigest(),
        'source_manifest_sha256':hashlib.sha256((kwargs['dataset_root']/'manifest.json').read_bytes()).hexdigest(),
        'new_code':campaign._runner_code(), 'protocol_identity':{
            **{target:baseline[source] for target,source in
               (('base_setup_hash','setup_hash'),('base_config_hash','config_hash'),
                ('base_catalog_hash','condition_catalog_hash'),('base_snapshot_hash','snapshot_hash'))},
            'instrument_assumptions_hash':ip['manifest_hash'],
            'research_code_hash':canonical_hash(campaign.plans.code_inventory(kwargs['app_dir'])),
            **{p+'_cost_hash':canonical_hash(cp['profiles'][p]) for p in ('baseline','adverse')}}}
    monkeypatch.setattr(h, '_preflight_identities', lambda a,p: identity)
    auth = fixture.claim()
    auth.output_root.mkdir(mode=0o700)
    kwargs['signal_root'].rename(auth.output_root/'signals')
    report_path = auth.output_root/'signals'/'report.json'
    for symbol in h.SYMBOLS[1:]:
        path = auth.output_root/'signals'/(symbol+'.results.ndjson')
        frame = json.loads(path.read_bytes())
        frame.update(passed=False,reason_code='sample_reject')
        frame['result_hash'] = canonical_hash({k:v for k,v in frame.items() if k != 'result_hash'})
        raw = (json.dumps(frame)+'\n').encode(); path.write_bytes(raw)
        report['symbols'][symbol].update(passed_rules=0,failed_rules=1,scored_passed_rules=0,
            scored_failed_rules=1,result_sha256=hashlib.sha256(raw).hexdigest())
    report_path.write_text(json.dumps(report,sort_keys=True))
    # Exact canonical production report window strings are claimed, including
    # the UTC +00:00 representation used by this private fixture.
    monkeypatch.setattr(signal_sources, '_select_sources_validated',
        lambda root,beginning,ending,score,symbol: selections[symbol])
    for p in auth.output_root.rglob('*'):
        if p.is_file(): p.chmod(0o600)
    (auth.output_root/'units'/'baseline').mkdir(mode=0o700, parents=True)
    (auth.output_root/'units').chmod(0o700)
    upstream.Builder.positive = True
    class BoundBuilder(upstream.Builder):
        def build(self, signal, view):
            result = super().build(signal, view)
            if result['schema_version'] == 'research-plan.v1':
                result['cost_profile'] = self.opening['cost_profile']
                result['plan_hash'] = canonical_hash({k:v for k,v in result.items() if k != 'plan_hash'})
            return result
    monkeypatch.setattr(campaign.plans, 'PlanWorker', BoundBuilder)
    return auth, kwargs, report, selections, report_path


def test_binding_publication_and_real_guarded_engine_reconcile(engine):
    auth, kwargs, report, selections, _ = engine
    slot = auth.for_slot('baseline', 'baseline')
    binding = campaign.publish_holdout_unit_inputs(auth.output_root/'signals',
        authorization=slot, variant_id='baseline', cost_profile='baseline')
    assert binding['schema_version'] == 'research-holdout-unit-inputs.v1'
    assert binding['symbols'] == list(h.SYMBOLS)
    assert binding['signal_reports'][0]['symbols'] == sorted(h.SYMBOLS)
    result = campaign.run_holdout_campaign(auth.output_root/'signals', authorization=slot,
        variant_id='baseline', cost_profile='baseline')
    assert result['phase'] == 'holdout'
    assert result['trades'] > 0
    root = auth.output_root/'units'/'baseline'/'baseline'
    verified = statistics.verify_holdout_campaign(root, authorization=slot,
        variant_id='baseline', cost_profile='baseline')
    assert verified['independently_reconciled'] is True
    assert verified['net_pnl_quote'] == str(result['net_pnl_quote'])


def publish(engine):
    auth = engine[0].for_slot('baseline', 'baseline')
    binding = campaign.publish_holdout_unit_inputs(auth.output_root/'signals',
        authorization=auth,variant_id='baseline',cost_profile='baseline')
    return auth,binding


@pytest.mark.parametrize('change', ['binding', 'marker', 'source', 'instrument', 'cost', 'report', 'signal_rows', 'funding', 'runner', 'php'])
def test_bound_inputs_tamper_denied_before_summary_read(engine, monkeypatch, change):
    auth,binding = publish(engine)
    contract = h.require_claim(auth,operation='retained')
    path = auth.output_root/'inputs'/'baseline'/'baseline.json'
    marker = auth.anchor/'inputs'/'baseline'/'baseline.json'
    if change in ('binding','marker'):
        selected = path if change == 'binding' else marker
        value = json.loads(selected.read_bytes()); value['claim_hash'] = 'f'*64
        selected.write_bytes(h.canonical_bytes(value))
    elif change in ('source','instrument','cost','report','signal_rows'):
        selected = {'source':Path(contract['paths']['dataset_root'])/'manifest.json',
            'instrument':Path(contract['paths']['instrument_path']),
            'cost':Path(contract['paths']['cost_path']),
            'report':auth.output_root/'signals'/'report.json',
            'signal_rows':auth.output_root/'signals'/'BTCUSDT.results.ndjson'}[change]
        selected.write_bytes(selected.read_bytes()+b' ')
    elif change == 'runner': monkeypatch.setattr(campaign,'_runner_code',lambda:{})
    elif change == 'php': monkeypatch.setattr(campaign.plans,'code_inventory',lambda app:{})
    else:
        original = campaign.FundingReader
        class Changed(original):
            def __init__(self,*args,**kwargs):
                super().__init__(*args,**kwargs)
                self.inventory = dataclasses.replace(self.inventory,inventory_hash='f'*64)
        monkeypatch.setattr(campaign,'FundingReader',Changed)
    def profit(*args): pytest.fail('profit read before bound inputs denied')
    monkeypatch.setattr(statistics,'_read_json',profit)
    with pytest.raises((campaign.CampaignError,h.HoldoutError)):
        statistics.verify_holdout_campaign(auth.output_root/'units'/'baseline'/'baseline',
            authorization=auth,variant_id='baseline',cost_profile='baseline')


@pytest.mark.parametrize('missing', ['binding', 'marker'])
def test_interrupted_binding_publication_is_retained_and_never_repaired(engine, monkeypatch, missing):
    auth = engine[0].for_slot('baseline','baseline')
    original = h._publish_bytes
    def interrupted(path,raw):
        if path.parent.parent.parent == auth.anchor and path.name == 'baseline.json':
            raise OSError('synthetic marker interruption')
        original(path,raw)
    if missing == 'marker':
        monkeypatch.setattr(h,'_publish_bytes',interrupted)
        with pytest.raises(OSError):
            campaign.publish_holdout_unit_inputs(auth.output_root/'signals',authorization=auth,
                variant_id='baseline',cost_profile='baseline')
    else:
        path = auth.anchor/'inputs'/'baseline'/'baseline.json'
        path.parent.mkdir(mode=0o700,parents=True)
        path.write_bytes(b'{}\n'); path.chmod(0o600)
    with pytest.raises(campaign.CampaignError,match='interrupted'):
        campaign.publish_holdout_unit_inputs(auth.output_root/'signals',authorization=auth,
            variant_id='baseline',cost_profile='baseline')
    with pytest.raises((OSError,h.HoldoutError,campaign.CampaignError)):
        campaign.validate_holdout_unit_inputs(authorization=auth,variant_id='baseline',
            cost_profile='baseline',operation='verify')


def test_retained_verification_replays_without_source_generation(engine, monkeypatch):
    auth,binding = publish(engine)
    campaign.run_holdout_campaign(auth.output_root/'signals',authorization=auth,
        variant_id='baseline',cost_profile='baseline')
    retained = h.mint_retained_authorization(h.load_campaign_authority()).for_slot('baseline','baseline')
    def forbidden(*args,**kwargs): pytest.fail('retained replay generated sources or simulated')
    monkeypatch.setattr(signal_sources,'select_holdout_sources',forbidden)
    monkeypatch.setattr(campaign,'PortfolioSimulator',forbidden)
    result = statistics.verify_holdout_campaign(auth.output_root/'units'/'baseline'/'baseline',
        authorization=retained,variant_id='baseline',cost_profile='baseline')
    assert result['closed_trades'] == 1
    with pytest.raises(h.HoldoutError,match='verify_only'):
        campaign.run_holdout_campaign(auth.output_root/'signals',authorization=retained,
            variant_id='baseline',cost_profile='baseline')


def test_synthetic_2026_net_plus_two_uses_same_guarded_arithmetic(engine):
    from tests.test_research_statistics import write_ledgers, marked_row
    auth,binding = publish(engine)
    root = auth.output_root/'units'/'baseline'/'baseline'; root.mkdir(mode=0o700)
    start,end = 1767225600000,1767225780000
    plan = {'schema_version':'research-plan.v1','symbol':'BTCUSDT','evaluated_ms':start,
        'risk_components_quote':{'total_stop_loss':2},'entry_price':100,'quantity':2,
        'instrument_math':{'contract_size':1},'execution_authority':'none',
        **binding['identity'],**binding['sources']['BTCUSDT'],'cost_profile':'baseline',
        'cost_model':binding['kernel_costs']}
    plan['plan_hash'] = canonical_hash(plan); key = plan['plan_hash']
    common = {'symbol':'BTCUSDT','plan_hash':key}
    rows = [marked_row(start,start=start,kind='initial'),('plans',plan),
        ('events',{**common,'reason':'admitted'}),
        ('events',{**common,'reason':'limit_fill_proxy','timestamp_ms':start+60000}),
        ('cashflows',{**common,'kind':'entry_fee','amount_quote':'-1','timestamp_ms':start+60000}),
        marked_row(start+60000,start=start,marks={key:'100'}),
        ('cashflows',{**common,'kind':'gross_pnl','amount_quote':'3','timestamp_ms':start+120000}),
        ('trades',{**common,**binding['identity'],**binding['sources']['BTCUSDT'],
            'net_pnl_quote':'2','gross_pnl_quote':'3','fees_quote':'1','funding_quote':'0',
            'spread_quote':'0','slippage_quote':'0','exit_boundary_ms':start+120000,
            'fill_boundary_ms':start+60000,'entry_price':'100','quantity':'2','exit_price':'101.5',
            'exit_reason':'target','ambiguity_reasons':[]}),
        marked_row(start+120000,start=start),marked_row(end,start=start,kind='terminal')]
    files = write_ledgers(root,rows)
    for path in root.iterdir(): path.chmod(0o600)
    with pytest.raises(statistics.StatisticsError,match='holdout_closed'):
        statistics.analyze_ledgers(root,files,symbols=h.SYMBOLS,start_ms=start,end_ms=end)
    result = statistics.analyze_holdout_ledgers(root,files,authorization=auth,
        variant_id='baseline',cost_profile='baseline')
    assert result['closed_trades'] == 1 and result['net_pnl_quote'] == '2'
    assert result['mean_realized_net_r'] == '1'
    assert result['net_profit_factor'] is None
    assert result['marked_equity_verified'] is True


@pytest.mark.parametrize('operation', ['campaign','statistics','verify'])
@pytest.mark.parametrize('slot', [('width_050','baseline'), ('baseline','unknown'), ('baseline','adverse')])
def test_exact_single_slot_required_before_any_input_read(engine,monkeypatch,operation,slot):
    auth = engine[0].for_slot('baseline','baseline')
    def forbidden(*args,**kwargs): pytest.fail('input read before slot denied')
    monkeypatch.setattr(campaign,'_input',forbidden)
    with pytest.raises(h.HoldoutError,match='exact_slot'):
        if operation == 'campaign':
            campaign.run_holdout_campaign(auth.output_root/'signals',authorization=auth,
                variant_id=slot[0],cost_profile=slot[1])
        elif operation == 'statistics':
            statistics.analyze_holdout_ledgers(auth.output_root/'units'/slot[0]/slot[1],{},authorization=auth,
                variant_id=slot[0],cost_profile=slot[1])
        else:
            statistics.verify_holdout_campaign(auth.output_root/'units'/slot[0]/slot[1],authorization=auth,
                variant_id=slot[0],cost_profile=slot[1])


@pytest.mark.parametrize('change', ['start', 'end', 'symbols', 'identity', 'sources', 'costs', 'instruments', 'funding', 'policy'])
def test_kernel_exact_immutable_inputs_cannot_be_replaced(engine,change):
    from app.backtesting.research.portfolio_simulator import RunAssumptions, FundingCoverage
    from decimal import Decimal
    auth,binding = publish(engine)
    report = engine[2]['symbols']['BTCUSDT']
    instruments = campaign._kernel_instruments(binding['instrument_assumptions'],h.SYMBOLS)
    costs = campaign._kernel_costs(report['effective_config_snapshot'],binding['cost_assumptions']['profiles']['baseline'])
    coverage = binding['funding_coverage']
    values = dict(start_ms=1767225600000,end_ms=1767225780000,phase='holdout',symbols=h.SYMBOLS,
        instruments=instruments,costs=costs,identity=tuple(binding['identity'].items()),
        sources=tuple((s,tuple(binding['sources'][s].items())) for s in h.SYMBOLS),
        funding=FundingCoverage(coverage['status'],coverage['evidence_hash'],
            tuple(tuple(row) for row in coverage['expected_counts'])),
        funding_path_policy=binding['funding_path_policy'],funding_mark_policy=binding['funding_mark_policy'],
        authorization=auth)
    accepted = RunAssumptions(**values)
    if change == 'start': values['start_ms'] -= 60000
    elif change == 'end': values['end_ms'] += 60000
    elif change == 'symbols': values['symbols'] = tuple(reversed(h.SYMBOLS))
    elif change == 'identity': values['identity'] = tuple((k,'width_050' if k == 'variant_id' else v) for k,v in values['identity'])
    elif change == 'sources':
        values['sources'] = tuple((s,tuple((k,'f'*64 if k == 'dataset_sha256' else v) for k,v in rows)) for s,rows in values['sources'])
    elif change == 'costs': values['costs'] = dataclasses.replace(costs,entry_spread_rate=Decimal('.0001'))
    elif change == 'instruments': values['instruments'] = tuple((s,dataclasses.replace(i,tick_size=Decimal('.02'))) for s,i in instruments)
    elif change == 'funding': values['funding'] = dataclasses.replace(values['funding'],evidence_hash='f'*64)
    else: values['funding_mark_policy'] = 'unknown'
    with pytest.raises((ValueError,h.HoldoutError)):
        RunAssumptions(**values)
    with pytest.raises(ValueError,match='authorization_invalid'):
        dataclasses.replace(accepted,phase='validation')


@pytest.mark.parametrize('change', ['date', 'missing_symbol', 'baseline', 'source_hash', 'source_root', 'signal_root'])
def test_binding_construction_requires_exact_source_and_b1_scope(engine,change):
    auth,kwargs,report,selections,report_path = engine
    slot = auth.for_slot('baseline','baseline')
    if change == 'source_root':
        with pytest.raises(signal_sources.SignalError):
            signal_sources.select_holdout_sources(Path('/wrong'),'BTCUSDT',authorization=slot)
        return
    if change == 'source_hash':
        selections['BTCUSDT'] = dataclasses.replace(selections['BTCUSDT'],manifest_sha256='f'*64)
    elif change == 'date': report['source_end'] = '2026-10-10T06:00:00Z'
    elif change == 'missing_symbol': report['symbols'].pop('AVAXUSDT')
    elif change == 'baseline': report['symbols']['BTCUSDT']['baseline'] = {}
    report_path.write_text(json.dumps(report))
    with pytest.raises((campaign.CampaignError,signal_sources.SignalError)):
        campaign.build_holdout_unit_inputs(auth.output_root/('wrong' if change == 'signal_root' else 'signals'),
            authorization=slot,variant_id='baseline',cost_profile='baseline')


def test_full_fixed_cutoff_scope_and_minute_counts(fixture):
    auth = fixture.claim(); contract = h.require_claim(auth,operation='retained')
    w = contract['window']
    assert w == {'source_start':'2025-11-01T00:00:00Z','score_start':'2026-01-01T00:00:00Z',
                 'end':'2026-10-09T06:00:00Z','end_ms':1791525600000}
    assert contract['symbols'] == list(h.SYMBOLS)
    begin,end,score = map(signal_sources._utc,(w['source_start'],w['end'],w['score_start']))
    assert (signal_sources._ms(end)-signal_sources._ms(score))//60000 == 405000
    assert (signal_sources._ms(end)-signal_sources._ms(begin))//60000 == 492840
    assert signal_sources._expected_ticks(begin,end,score) == (27001,3999,1856)


@pytest.fixture
def signal_transport(fixture, tmp_path, monkeypatch):
    """Actual source provenance and signal transport, with a Python worker fake."""
    import sys
    from tests.test_research_signals import _worker_evidence, FAKE_WORKER
    from app.backtesting.research.binance_history import SOURCE
    app = Path(fixture.config['paths']['app_dir'])
    evidence = _worker_evidence(app)
    for name in (*signals.CODE_FILES,'bin/console','composer.json','composer.lock','vendor/composer/installed.json'):
        path = app/name; path.parent.mkdir(parents=True,exist_ok=True)
        path.write_text('synthetic worker code')
    evidence_path = tmp_path/'worker-evidence.json'; evidence_path.write_text(json.dumps(evidence))
    source = Path(fixture.config['paths']['dataset_root'])
    start,end = 1767225600000,1767225780000
    beginning,ending,score = map(upstream.iso,(start,end,start+60000))
    monkeypatch.setattr(h,'SOURCE_START',beginning); monkeypatch.setattr(h,'SCORE_START',score)
    monkeypatch.setattr(h,'END',ending)
    entries = []
    for symbol in h.SYMBOLS:
        directory = source/'rest'/symbol; directory.mkdir(parents=True)
        path = directory/(str(start)+'.json')
        raw = json.dumps([[t,'100','102','99','101','2',t+59999,'200',3,'1','100','0']
                          for t in range(start,end,60000)]).encode()
        path.write_bytes(raw)
        entries.append({'symbol':symbol,'kind':'klines','start':beginning,'end':ending,
            'status':'ok','coverage':'complete','gaps':[],'count':3,'first_open':start,'last_close':end-1,
            'evidence':'download_sha256','pages':[{'raw_path':str(path.relative_to(source)),
                'sha256':hashlib.sha256(raw).hexdigest(),'size':len(raw),
                'url':f'https://fapi.binance.com/fapi/v1/klines?symbol={symbol}&interval=1m&startTime={start}&endTime={end-1}&limit=1000'}]})
    manifest = {'identity':{'schema':1,'source':SOURCE,'symbols':list(h.SYMBOLS),
        'start':beginning,'end':ending,'include_funding':False},'sources':entries}
    (source/'manifest.json').write_text(json.dumps(manifest))
    identity = {'source_manifest_sha256':hashlib.sha256((source/'manifest.json').read_bytes()).hexdigest(),
                'baseline':evidence['baseline']}
    monkeypatch.setattr(h,'_preflight_identities',lambda a,p:identity)
    fixture.protocol['cutoff'] = ending; fixture.freeze['cutoff'] = ending
    fixture.config['protocol_hash'] = fixture.freeze['protocol_hash'] = h.hash_bytes(h.canonical_bytes(fixture.protocol))
    fixture.installed.write_bytes(h.canonical_bytes(fixture.config))
    (Path(fixture.config['registry'])/'protocol.json').write_bytes(h.canonical_bytes(fixture.protocol))
    fixture.selected()
    monkeypatch.setattr(signals,'_worker_argv',lambda app,supplied:(sys.executable,'-c',FAKE_WORKER,'ok',str(evidence_path)))
    auth = fixture.claim(timeout=60); auth.output_root.mkdir(mode=0o700)
    return auth,source


def test_guarded_signals_real_source_and_owned_worker_transport(signal_transport,monkeypatch):
    auth,source = signal_transport
    durations = []; original = signals._run_symbol
    def transport(*args):
        durations.append(args[5])
        return original(*args)
    monkeypatch.setattr(signals,'_run_symbol',transport)
    report = signals.run_holdout_signals(auth.output_root/'signals',authorization=auth)
    assert report['status'] == 'complete' and list(report['symbols']) == list(h.SYMBOLS)
    assert report['totals']['source_candles'] == 30 and report['totals']['scored_evaluations'] == 0
    assert report['symbols']['BTCUSDT']['manifest_sha256'] == hashlib.sha256((source/'manifest.json').read_bytes()).hexdigest()
    assert durations[0] > durations[-1] > 0
    assert str(Path(h.__file__)) in report['code_sha256']


def test_guarded_source_manifest_change_and_outside_symbol_denied(signal_transport):
    auth,source = signal_transport
    with pytest.raises(signal_sources.SignalError,match='symbol'):
        signal_sources.select_holdout_sources(source,'BADUSDT',authorization=auth)
    path = source/'manifest.json'; path.write_bytes(path.read_bytes()+b' ')
    with pytest.raises(signal_sources.SignalError,match='manifest'):
        signal_sources.select_holdout_sources(source,'BTCUSDT',authorization=auth)


def test_guarded_signals_reserve_aggregate_report_capacity(signal_transport):
    auth,_ = signal_transport
    contract = h.require_claim(auth,operation='retained')
    path = auth.output_root/'synthetic-allocated.bin'
    with path.open('wb') as stream:
        stream.truncate(contract['limits']['overall_bytes']-1024**2)
    path.chmod(0o600)
    with pytest.raises(signal_sources.SignalError,match='capacity'):
        signals.run_holdout_signals(auth.output_root/'signals',authorization=auth)


def test_guarded_statistics_checks_actual_temp_floor_before_index(engine,monkeypatch):
    import tempfile
    auth,binding = publish(engine)
    temp_root = Path(tempfile.gettempdir())
    def disk(path):
        free = 0 if Path(path) == temp_root else 100*1024**3
        return type('Disk',(),{'free':free})()
    monkeypatch.setattr(statistics.shutil,'disk_usage',disk)
    def index(*args,**kwargs): pytest.fail('index created before temp capacity guard')
    monkeypatch.setattr(statistics.tempfile,'TemporaryDirectory',index)
    files = {name+'.ndjson':{'sha256':hashlib.sha256(b'').hexdigest(),'count':0,'bytes':0}
             for name in statistics.LEDGERS}
    with pytest.raises(statistics.StatisticsError,match='spool_capacity'):
        statistics.analyze_holdout_ledgers(auth.output_root/'units'/'baseline'/'baseline',files,
            authorization=auth,variant_id='baseline',cost_profile='baseline')


def test_overall_inventory_scan_cannot_return_an_expired_budget(fixture,monkeypatch):
    now = [100.0]
    monkeypatch.setattr(h.time,'monotonic',lambda:now[0])
    auth = fixture.claim(timeout=10)
    original = h.os.walk
    def walk(*args,**kwargs):
        for item in original(*args,**kwargs):
            now[0] = 111.0
            yield item
    monkeypatch.setattr(h.os,'walk',walk)
    with pytest.raises(h.HoldoutError,match='deadline'):
        h.remaining_limits(auth)


def test_shared_byte_inventory_deduplicates_hardlinks_and_denies_symlinks(fixture):
    import os
    auth = fixture.claim(); auth.output_root.mkdir(mode=0o700)
    path = auth.output_root/'physical'; path.write_bytes(b'12345'); path.chmod(0o600)
    used = h.remaining_limits(auth)['used_bytes']
    os.link(path,auth.output_root/'same-physical')
    assert h.remaining_limits(auth)['used_bytes'] == used
    (auth.output_root/'escape').symlink_to(path)
    with pytest.raises(h.HoldoutError,match='symlink'):
        h.remaining_limits(auth)


def test_failed_holdout_unit_retains_authority_phase_and_binding(engine):
    auth,binding = publish(engine)
    upstream.Builder.malformed = True
    with pytest.raises(campaign.CampaignError,match='malformed'):
        campaign.run_holdout_campaign(auth.output_root/'signals',authorization=auth,
            variant_id='baseline',cost_profile='baseline')
    root = auth.output_root/'units'/'baseline'/'baseline'
    status = json.loads((root/'status.json').read_bytes())
    assert status['status'] == 'failed'
    assert status['phase'] == 'holdout'
    assert status['authority_hash'] == auth.authority_hash
    assert status['contract_hash'] == auth.contract_hash
    assert status['claim_hash'] == auth.claim_hash
    assert status['unit_input_binding_sha256'] == h.hash_bytes(h.canonical_bytes(binding))
    assert not (root/'summary.json').exists()


@pytest.mark.parametrize('engine', ['width_050'], indirect=True)
@pytest.mark.parametrize('profile', ['baseline','adverse'])
def test_selected_hypothesis_and_both_profile_slots_use_real_engine(engine,profile):
    batch = engine[0]
    assert set(batch.slots) == {('width_050','baseline'),('width_050','adverse'),
                              ('baseline','baseline'),('baseline','adverse')}
    auth = batch.for_slot('width_050',profile)
    (auth.output_root/'units'/'width_050').mkdir(mode=0o700)
    binding = campaign.publish_holdout_unit_inputs(auth.output_root/'signals',authorization=auth,
        variant_id='width_050',cost_profile=profile)
    assert binding['variant']['diff'] == {'zone_atr_multiplier':0.5}
    result = campaign.run_holdout_campaign(auth.output_root/'signals',authorization=auth,
        variant_id='width_050',cost_profile=profile)
    verified = statistics.verify_holdout_campaign(auth.output_root/'units'/'width_050'/profile,
        authorization=auth,variant_id='width_050',cost_profile=profile)
    assert verified['closed_trades'] == result['trades'] == 1
    assert verified['variant_id'] == 'width_050' and verified['cost_profile'] == profile


@pytest.mark.parametrize('guard', ['missing_output','capacity','disk_floor','final_deadline','missing_private_budget'])
def test_shared_budget_remaining_guard_paths(fixture,monkeypatch,guard):
    now = [100.0]
    monkeypatch.setattr(h.time,'monotonic',lambda:now[0])
    auth = fixture.claim(timeout=10)
    if guard == 'missing_output':
        assert h.remaining_limits(auth)['seconds'] == 10
        return
    if guard == 'missing_private_budget':
        with pytest.raises(h.HoldoutError,match='budget_missing'):
            h._mint_authorization(auth.authority_hash,auth.contract_hash,'f'*64,auth.anchor,
                auth.output_root,auth.slots,auth.contract_bytes,'verify')
        return
    if guard == 'capacity':
        auth.output_root.mkdir(mode=0o700)
        path = auth.output_root/'synthetic-allocated.bin'
        with path.open('wb') as stream: stream.truncate(h.OVERALL_BYTES)
        path.chmod(0o600)
        reason = 'overall_capacity'
    elif guard == 'disk_floor':
        monkeypatch.setattr(h.shutil,'disk_usage',lambda p:type('Disk',(),{'free':0})())
        reason = 'disk_reserve'
    else:
        def disk(p):
            now[0] = 111.0
            return type('Disk',(),{'free':100*1024**3})()
        monkeypatch.setattr(h.shutil,'disk_usage',disk)
        reason = 'deadline'
    with pytest.raises(h.HoldoutError,match=reason): h.remaining_limits(auth)


@pytest.mark.parametrize('field', ['window','risk_assumptions','variant','instrument_assumptions',
    'cost_assumptions','identity','funding_inventory'])
def test_private_binding_scope_revalidates_fixed_contract_and_metadata(engine,field):
    import copy
    auth,binding = publish(engine)
    document = copy.deepcopy(binding)
    if field == 'window': document[field]['end_ms'] += 60000
    elif field == 'risk_assumptions': document[field]['daily_loss_limit_quote'] = '31'
    elif field == 'variant': document[field]['diff'] = {'zone_atr_multiplier':0.5}
    elif field == 'instrument_assumptions': document[field]['manifest_hash'] = 'f'*64
    elif field == 'cost_assumptions': document[field]['assumption_hash'] = 'f'*64
    elif field == 'identity': document[field]['research_code_hash'] = 'f'*64
    else: document[field]['inventory_hash'] = 'f'*64
    with pytest.raises(campaign.CampaignError):
        campaign._validate_binding_scope(document,auth,h.require_claim(auth,operation='retained'))


@pytest.mark.parametrize('guard', ['paths','assumption_hash','funding_window','metadata_capacity','wrong_signal_root','retained_report_root'])
def test_unit_binding_additional_preprofit_guards(engine,monkeypatch,guard):
    import copy
    auth = engine[0].for_slot('baseline','baseline')
    if guard == 'paths':
        contract = h.require_claim(auth,operation='retained')
        contract['slots'][0]['input_binding_path'] = '/wrong'
        with pytest.raises(campaign.CampaignError,match='binding path'):
            campaign._binding_paths(auth,contract,'baseline','baseline')
        return
    if guard == 'wrong_signal_root':
        with pytest.raises(campaign.CampaignError,match='signal root'):
            campaign.run_holdout_campaign(Path('/wrong'),authorization=auth,
                variant_id='baseline',cost_profile='baseline')
        return
    if guard == 'retained_report_root':
        auth,binding = publish(engine)
        path = auth.output_root/'inputs'/'baseline'/'baseline.json'
        binding['signal_reports'][0]['root'] = '/wrong'
        raw = h.canonical_bytes(binding); path.write_bytes(raw)
        marker = auth.anchor/'inputs'/'baseline'/'baseline.json'
        value = json.loads(marker.read_bytes()); value['input_binding_sha256'] = h.hash_bytes(raw)
        marker.write_bytes(h.canonical_bytes(value))
        with pytest.raises(campaign.CampaignError,match='B1 root'):
            campaign.validate_holdout_unit_inputs(authorization=auth,variant_id='baseline',
                cost_profile='baseline',operation='verify')
        return
    if guard == 'assumption_hash':
        path = engine[1]['instrument_path']; value = json.loads(path.read_bytes())
        value['manifest_hash'] = 'f'*64; path.write_text(json.dumps(value))
        reason = 'assumption manifest hash'
    elif guard == 'funding_window':
        original = campaign.FundingReader
        class Shifted(original):
            def __init__(self,*args,**kwargs):
                super().__init__(*args,**kwargs)
                self.inventory = dataclasses.replace(self.inventory,start_ms=self.inventory.start_ms+60000)
        monkeypatch.setattr(campaign,'FundingReader',Shifted)
        reason = 'funding inventory window'
    else:
        remaining = h.remaining_limits(auth)['bytes']
        path = auth.output_root/'synthetic-allocated.bin'
        with path.open('wb') as stream: stream.truncate(remaining-1)
        path.chmod(0o600)
        reason = 'metadata capacity'
    with pytest.raises(campaign.CampaignError,match=reason):
        campaign.publish_holdout_unit_inputs(auth.output_root/'signals',authorization=auth,
            variant_id='baseline',cost_profile='baseline')


@pytest.mark.parametrize('bad', ['missing','extra','funding'])
def test_guarded_statistics_retain_structural_and_funding_checks(engine,bad):
    from tests.test_research_statistics import write_ledgers
    auth,binding = publish(engine)
    root = auth.output_root/'units'/'baseline'/'baseline'; root.mkdir(mode=0o700)
    rows = [('funding-events',{'symbol':'BTCUSDT','timestamp_ms':1767225600000,'rate':'NaN'})] if bad == 'funding' else []
    files = write_ledgers(root,rows)
    for path in root.iterdir(): path.chmod(0o600)
    if bad == 'missing': files.pop('funding-events.ndjson')
    elif bad == 'extra': files['extra.ndjson'] = {}
    with pytest.raises(statistics.StatisticsError,match='invalid_decimal' if bad == 'funding' else 'ledger_scope'):
        statistics.analyze_holdout_ledgers(root,files,authorization=auth,
            variant_id='baseline',cost_profile='baseline')


@pytest.mark.parametrize('field', ['identity','sources','kernel_costs','kernel_instruments','b1_counters','funding_coverage'])
def test_retained_binding_reconstructs_exact_physical_metadata_before_profit(engine,field):
    auth,binding = publish(engine)
    retained = h.mint_retained_authorization(h.load_campaign_authority()).for_slot('baseline','baseline')
    if field == 'identity': binding[field]['base_setup_hash'] = 'f'*64
    elif field == 'sources': binding[field]['BTCUSDT']['dataset_sha256'] = 'f'*64
    elif field == 'kernel_costs': binding[field]['entry_fee_rate'] = .0003
    elif field == 'kernel_instruments': binding[field]['BTCUSDT']['tick_size'] = '.02'
    elif field == 'b1_counters': binding[field]['evaluated_ticks'] += 1
    else: binding[field]['evidence_hash'] = 'f'*64
    path = auth.output_root/'inputs'/'baseline'/'baseline.json'
    raw = h.canonical_bytes(binding); path.write_bytes(raw)
    marker = auth.anchor/'inputs'/'baseline'/'baseline.json'
    value = json.loads(marker.read_bytes()); value['input_binding_sha256'] = h.hash_bytes(raw)
    marker.write_bytes(h.canonical_bytes(value))
    with pytest.raises(campaign.CampaignError):
        campaign.validate_holdout_unit_inputs(authorization=retained,variant_id='baseline',
            cost_profile='baseline',operation='verify')
