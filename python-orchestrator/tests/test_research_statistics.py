"""Synthetic statistics only: never open strategy datasets or workers."""
from decimal import Decimal as D

import pytest

from app.backtesting.research.statistics import OutcomeStatistics, StatisticsError, analyze_ledgers, verify_campaign
from app.backtesting.research.experiments import make_protocol, hash_bytes, canonical_bytes, IDENTITIES
import hashlib
import copy
import json
from pathlib import Path
from app.backtesting.research.portfolio_simulator import canonical_hash, SYMBOLS

START = 1672531200000
FIELDS = ('gross_pnl_quote','net_pnl_quote','fees_quote','funding_quote','spread_quote','slippage_quote')
LEDGERS = ('plans','rejections','events','trades','cashflows','funding-events')


def ledger_fixture(tmp_path, *, net='2', funding='0', timestamp=START+60000):
    plan = {'schema_version':'research-plan.v1', 'symbol':'BTCUSDT',
        'risk_components_quote':{'total_stop_loss':2.0},'evaluated_ms':START,
        'entry_price':100.0,'quantity':2.0,'instrument_math':{'contract_size':1.0},
        'execution_authority':'none'}
    plan['plan_hash'] = canonical_hash(plan)
    key = plan['plan_hash']
    rows = [('plans',plan), ('events',{'plan_hash':key,'symbol':'BTCUSDT','reason':'admitted'}),
        ('events',{'plan_hash':key,'symbol':'BTCUSDT','reason':'limit_fill_proxy'}),
        ('cashflows',{'plan_hash':key,'symbol':'BTCUSDT','kind':'entry_fee','amount_quote':'-1','timestamp_ms':START}),
        ('cashflows',{'plan_hash':key,'symbol':'BTCUSDT','kind':'funding','amount_quote':funding,'timestamp_ms':timestamp}),
        ('cashflows',{'plan_hash':key,'symbol':'BTCUSDT','kind':'gross_pnl','amount_quote':str(D(net)+1-D(funding)),'timestamp_ms':timestamp}),
        ('trades',{'plan_hash':key,'symbol':'BTCUSDT','net_pnl_quote':net,
            'gross_pnl_quote':str(D(net)+1-D(funding)),'fees_quote':'1','funding_quote':funding,
            'spread_quote':'0','slippage_quote':'0','exit_boundary_ms':timestamp,
            'entry_price':'100','quantity':'2','exit_price':str(D('100')+(D(net)+1-D(funding))/2),
            'fill_boundary_ms':START+30000,'exit_reason':'target','ambiguity_reasons':[]})]
    return write_ledgers(tmp_path,rows), key


def write_ledgers(root,rows):
    data = {name:b'' for name in LEDGERS}
    for sequence,(kind,row) in enumerate(rows):
        data[kind] += (json.dumps({**row,'ledger_sequence':sequence})+'\n').encode()
    files = {}
    for kind,raw in data.items():
        (root/(kind+'.ndjson')).write_bytes(raw)
        files[kind+'.ndjson'] = {'sha256':hashlib.sha256(raw).hexdigest(),
            'count':raw.count(b'\n'),'bytes':len(raw)}
    return files


def test_exact_net_outcomes_and_undefined_denominators():
    stats = OutcomeStatistics()
    assert stats.result()['net_profit_factor'] is None
    assert stats.result()['profit_factor_undefined_reason'] == 'no_negative_net_trades'
    assert stats.result()['mean_realized_net_r'] is None
    stats.add(D('3'), D('2'))
    stats.add(D('-1'), D('2'))
    stats.add(D('0'), D('2'))
    result = stats.result()
    assert result['closed_trades'] == 3
    assert (result['wins'], result['losses'], result['breakevens']) == (1, 1, 1)
    assert D(result['net_pnl_quote']) == 2
    assert result['net_profit_factor'] == '3'
    assert D(result['mean_realized_net_r']) == D(1) / 3
    assert D(result['net_win_rate']) == D(1) / 3
    assert result['profit_factor_undefined_reason'] is None


@pytest.mark.parametrize('net,risk', [('NaN','1'), ('1','0'), ('1','-1'), (True,'1'), ('1','Infinity')])
def test_invalid_risk_or_net_never_wins(net, risk):
    with pytest.raises(StatisticsError):
        OutcomeStatistics().add(net, risk)


def test_decimal_cashflow_expectancy_not_planned_r():
    stats = OutcomeStatistics()
    stats.add('0.0000000000000000001', '0.01')
    assert stats.result()['mean_realized_net_r'] == '1E-17'


def test_independent_plan_cashflow_r_pair_and_year_rollups(tmp_path):
    files,_ = ledger_fixture(tmp_path,funding='-0.1')
    result = analyze_ledgers(tmp_path,files, symbols=SYMBOLS,start_ms=START,end_ms=1735689600000)
    assert D(result['net_pnl_quote']) == 2
    assert result['funding_quote'] == '-0.1'
    assert result['fees_quote'] == '1'
    assert result['mean_realized_net_r'] == '1'
    assert D(result['wallet_quote']) == 100002
    assert result['pairs_with_trades'] == 1
    assert D(result['per_pair']['BTCUSDT']['net_pnl_quote']) == 2
    assert D(result['per_year']['2023']['cashflow_net_pnl_quote']) == 2
    assert result['per_year']['2023']['closed_trades'] == 1
    assert result['counts']['admitted'] == 1
    assert result['counts']['filled'] == 1


def test_ledger_tamper_partial_and_wrong_risk_rejected(tmp_path):
    files,key = ledger_fixture(tmp_path)
    path = tmp_path/'cashflows.ndjson'
    path.write_bytes(path.read_bytes()+b'{}')
    with pytest.raises(StatisticsError):
        analyze_ledgers(tmp_path,files,symbols=SYMBOLS,start_ms=START,end_ms=1735689600000)
    files,_ = ledger_fixture(tmp_path)
    path = tmp_path/'plans.ndjson'
    row = json.loads(path.read_bytes()); row['risk_components_quote']['total_stop_loss'] = 0
    path.write_text(json.dumps(row)+'\n')
    files['plans.ndjson'] = {'sha256':hashlib.sha256(path.read_bytes()).hexdigest(),'count':1,'bytes':path.stat().st_size}
    with pytest.raises(StatisticsError,match='plan_hash'):
        analyze_ledgers(tmp_path,files,symbols=SYMBOLS,start_ms=START,end_ms=1735689600000)


def test_trade_and_cashflow_year_difference_is_reported(tmp_path):
    files,_ = ledger_fixture(tmp_path,timestamp=1704067200000)
    # The fixture crosses midnight and must fail the declared holding policy.
    with pytest.raises(StatisticsError,match='midnight'):
        analyze_ledgers(tmp_path,files,symbols=SYMBOLS,start_ms=START,end_ms=1735689600000)


def campaign_fixture(root):
    """Synthetic C1-shaped zero ledger fixture, never actual C1/source execution."""
    files = write_ledgers(root,[])
    baseline = {'setup_hash':'a'*64,'config_hash':'b'*64,'condition_catalog_hash':'c'*64,'snapshot_hash':'d'*64}
    instruments = {'synthetic':True}; instruments['manifest_hash'] = canonical_hash(instruments)
    costs = {'profiles':{'baseline':{'synthetic':'base'},'adverse':{'synthetic':'adverse'}}}
    costs['assumption_hash'] = canonical_hash(costs)
    source_runs = [{'symbol':s,'source':{'dataset_id':'synthetic','dataset_sha256':'e'*64},
        'signal_run_id':'synthetic','signal_output_sha256':'f'*64} for s in SYMBOLS]
    reports = [{'root':str(root/'synthetic-signals'),'report_sha256':'1'*64,
        'symbols':list(SYMBOLS),'code_sha256':{'synthetic':'2'*64}}]
    binding = {'source_runs':source_runs,'signal_reports':reports,'runner_code_sha256':{'synthetic':'3'*64},
        'cost_assumptions_hash':costs['assumption_hash']}
    bindings = {p:binding for p in ('training','validation')}
    identity = {k:'a'*64 for k in IDENTITIES}
    identity.update(dataset_hash=hash_bytes(canonical_bytes({p:binding['source_runs'] for p in bindings})),
        signal_code_hash=canonical_hash(reports[0]['code_sha256']),research_code_hash='4'*64,
        base_setup_hash=baseline['setup_hash'],base_config_hash=baseline['config_hash'],
        base_catalog_hash=baseline['condition_catalog_hash'],base_snapshot_hash=baseline['snapshot_hash'],
        instrument_assumptions_hash=instruments['manifest_hash'],
        baseline_cost_hash=canonical_hash(costs['profiles']['baseline']),
        adverse_cost_hash=canonical_hash(costs['profiles']['adverse']))
    protocol = make_protocol(identity,phase_bindings=bindings)
    variant = {'id':'baseline','diff':[]}
    variant['variant_hash'] = canonical_hash({'schema_version':'research-variant.v1',**variant,
        'base_setup_hash':baseline['setup_hash'],'base_config_hash':baseline['config_hash'],
        'base_catalog_hash':baseline['condition_catalog_hash']})
    funding = {'coverage':'observed_only','evidence_complete':True,
        'symbols':[{'symbol':s,'count':0,'evidence_complete':True,'continuity':'declared_interval_consistent'} for s in SYMBOLS]}
    funding['inventory_hash'] = canonical_hash(funding)
    manifest = {'schema_version':'research-campaign-manifest.v1','run_id':'synthetic',
        'phase':'training','start_ms':START,'end_ms':1735689600000,'end_exclusive':True,
        'symbol_priority':list(SYMBOLS),'variant':variant,'cost_profile':'baseline','baseline':baseline,
        **binding,'research_code_hash':identity['research_code_hash'],'instrument_assumptions':instruments,
        'cost_assumptions':costs,'funding_inventory':funding,'funding_inventory_hash':funding['inventory_hash'],
        'b1_counters':{'evaluated_ticks':200,'passed_rules':0,'failed_rules':200},
        'initial_wallet_quote':'100000','execution_authority':'none'}
    (root/'manifest.json').write_bytes(canonical_bytes(manifest))
    manifest_hash = hashlib.sha256((root/'manifest.json').read_bytes()).hexdigest()
    summary = {**{f:'0' for f in FIELDS},'schema_version':'research-campaign-summary.v1',
        'run_id':'synthetic','phase':'training','start_ms':START,'end_ms':1735689600000,
        'status':'complete','manifest_sha256':manifest_hash,'symbol_priority':list(SYMBOLS),
        'wallet_quote':'100000','trades':0,'attempted_signals':0,'admitted_plans':0,
        'maximum_drawdown_quote':'0','maximum_exposure_quote':'0','funding_events':0,
        'phase_batches':(1735689600000-START)//60000,'source_candles':(1735689600000-START)//60000*10,
        'b1_counters':{'evaluated_ticks':200,'passed_rules':0,'failed_rules':200},
        'source_quality':{'candles':'verified_complete','funding_inventory':funding,
            'kernel_funding_coverage':'verified_complete'},'reconciliation':{'status':'verified'}}
    (root/'summary.json').write_bytes(canonical_bytes(summary))
    status = {'schema_version':'research-campaign-status.v1','run_id':'synthetic','status':'complete',
        'manifest_sha256':manifest_hash,'summary_sha256':hashlib.sha256((root/'summary.json').read_bytes()).hexdigest(),
        'files':files,'completion':'input_complete','execution_authority':'none'}
    (root/'status.json').write_bytes(canonical_bytes(status))
    registration = {'phase':'training','variant_id':'baseline','cost_profile':'baseline',
        'protocol_hash':hash_bytes(canonical_bytes(protocol))}
    return protocol,registration


def test_complete_zero_unit_independently_verified_not_eligible(tmp_path):
    protocol,registration = campaign_fixture(tmp_path)
    result = verify_campaign(tmp_path,protocol,registration)
    assert result['status'] == 'complete'
    assert result['closed_trades'] == 0
    assert result['counts']['scored'] == 200
    assert result['counts']['passed'] == 0
    assert result['funding_provenance'] == 'observed_only'
    assert result['cost_evidence_complete'] is True
    assert result['maximum_drawdown_quote'] == '0'


@pytest.mark.parametrize('change', ['holdout','hash','symbols','identity','missing_bindings'])
def test_campaign_scope_hash_and_protocol_changes_rejected(tmp_path,change):
    protocol,registration = campaign_fixture(tmp_path)
    if change == 'holdout':
        registration['phase'] = 'holdout'
    elif change == 'hash':
        (tmp_path/'summary.json').write_text('{}')
    elif change == 'symbols':
        protocol['symbol_priority'] = ['BTCUSDT']
    elif change == 'identity':
        protocol['identity']['base_setup_hash'] = '9'*64
        registration['protocol_hash'] = hash_bytes(canonical_bytes(protocol))
    else:
        protocol = make_protocol(protocol['identity'])
        registration['protocol_hash'] = hash_bytes(canonical_bytes(protocol))
    with pytest.raises(StatisticsError):
        verify_campaign(tmp_path,protocol,registration)


def test_holdout_manifest_rejected_before_profit_summary_read(tmp_path,monkeypatch):
    protocol,registration = campaign_fixture(tmp_path)
    manifest = json.loads((tmp_path/'manifest.json').read_bytes())
    manifest.update(phase='holdout',start_ms=1767225600000,end_ms=1791525600000)
    (tmp_path/'manifest.json').write_bytes(canonical_bytes(manifest))
    from app.backtesting.research import statistics
    original = statistics._read_json
    def guard(path):
        assert path.name != 'summary.json', 'holdout profits were opened before scope guard'
        return original(path)
    monkeypatch.setattr(statistics,'_read_json',guard)
    with pytest.raises(StatisticsError):
        verify_campaign(tmp_path,protocol,registration)


def read_rows(root):
    rows = [(kind,json.loads(line)) for kind in LEDGERS for line in (root/(kind+'.ndjson')).read_text().splitlines()]
    return [(kind,{k:v for k,v in row.items() if k != 'ledger_sequence'}) for kind,row in
            sorted(rows,key=lambda item:item[1]['ledger_sequence'])]


@pytest.mark.parametrize('mutation,reason', [
    ('unknown_kind','unknown_cashflow'),('expense_credit','expense_cannot'),
    ('wrong_symbol','ledger_symbol'),('trade_net','trade_cashflow'),('missing_plan','missing_closed'),
    ('late_flow','cashflow_timestamp'),('late_trade','trade_timestamp'),
    ('duplicate_plan','duplicate_plan'),('double_close','missing_closed'),
    ('unclosed','unclosed_cashflow'),('zero_risk','initial_stop'),
])
def test_independent_ledger_rejects_inconsistent_rows(tmp_path,mutation,reason):
    ledger_fixture(tmp_path)
    rows = read_rows(tmp_path)
    if mutation == 'unknown_kind':
        rows[3][1]['kind'] = 'invented'
    elif mutation == 'expense_credit':
        rows[3][1]['amount_quote'] = '1'
    elif mutation == 'wrong_symbol':
        rows[3][1]['symbol'] = 'INVALID'
    elif mutation == 'trade_net':
        rows[-1][1]['net_pnl_quote'] = '99'
    elif mutation == 'missing_plan':
        rows[3][1]['plan_hash'] = 'missing'
    elif mutation == 'late_flow':
        rows[3][1]['timestamp_ms'] = START-1
    elif mutation == 'late_trade':
        rows[-1][1]['exit_boundary_ms'] = START-1
    elif mutation == 'duplicate_plan':
        rows.insert(1,rows[0])
    elif mutation == 'double_close':
        rows.append(rows[-1])
    elif mutation == 'unclosed':
        rows.pop()
    else:
        plan = rows[0][1]
        plan['risk_components_quote']['total_stop_loss'] = 0
        key = canonical_hash({k:v for k,v in plan.items() if k != 'plan_hash'})
        for _,row in rows:
            row['plan_hash'] = key
    files = write_ledgers(tmp_path,rows)
    with pytest.raises(StatisticsError,match=reason):
        analyze_ledgers(tmp_path,files,symbols=SYMBOLS,start_ms=START,end_ms=1735689600000)


def test_all_cost_components_signed_funding_ambiguity_and_rejections(tmp_path):
    ledger_fixture(tmp_path)
    rows = read_rows(tmp_path); key = rows[0][1]['plan_hash']
    rows[1][1]['ambiguous'] = True
    additions = []
    for kind,amount in [('entry_spread','-0.25'),('entry_slippage','-0.5'),('liquidation_proxy_fee','-0.1')]:
        additions.append(('cashflows',{'plan_hash':key,'symbol':'BTCUSDT','kind':kind,
                                       'amount_quote':amount,'timestamp_ms':START}))
    rows[4][1]['amount_quote'] = '0.2'
    rows[-1][1].update(net_pnl_quote='1.35',fees_quote='1.1',funding_quote='0.2',
                      spread_quote='0.25',slippage_quote='0.5')
    rows[4:4] = additions
    rows += [('rejections',{'schema_version':'research-simulation-rejection.v1','symbol':'ETHUSDT'}),
             ('rejections',{'schema_version':'research-planner-rejection-evidence.v1','symbol':'ETHUSDT'}),
             ('funding-events',{'symbol':'BTCUSDT','timestamp_ms':START,'rate':'0.0001'})]
    files = write_ledgers(tmp_path,rows)
    result = analyze_ledgers(tmp_path,files,symbols=SYMBOLS,start_ms=START,end_ms=1735689600000)
    assert result['mean_realized_net_r'] == '0.675'
    assert result['funding_quote'] == '0.2'
    assert result['counts']['planner_rejections'] == 1
    assert result['counts']['rejected'] == 1
    assert result['counts']['funding_events'] == 1
    assert result['ambiguity_counts'] == {'admitted':1}


def test_exclusive_midnight_settlement_explains_annual_attribution(tmp_path):
    timestamp = 1704067200000
    files,_ = ledger_fixture(tmp_path,timestamp=timestamp)
    rows = read_rows(tmp_path)
    rows[3][1]['timestamp_ms'] = timestamp-60000
    rows[-1][1]['fill_boundary_ms'] = timestamp-30000
    files = write_ledgers(tmp_path,rows)
    result = analyze_ledgers(tmp_path,files,symbols=SYMBOLS,start_ms=START,end_ms=timestamp)
    assert result['per_year']['2023']['cashflow_net_pnl_quote'] == '-1'
    assert result['per_year']['2024']['closed_trades'] == 1
    assert D(result['per_year']['2024']['cashflow_vs_exit_attribution_difference_quote']) == 1


@pytest.mark.parametrize('invalid', ['duplicate_key','nonfinite','nonobject','invalid_json','large_line','sequence','global','count','size','inventory','symlink','scope','spool'])
def test_bounded_ledger_framing_integrity_and_storage_guards(tmp_path,invalid):
    files,_ = ledger_fixture(tmp_path)
    path = tmp_path/'cashflows.ndjson'
    kwargs = dict(symbols=SYMBOLS,start_ms=START,end_ms=1735689600000)
    if invalid in ('duplicate_key','nonfinite','nonobject','invalid_json','large_line'):
        path.write_bytes({'duplicate_key':b'{"a":1,"a":2}\n','nonfinite':b'{"a":NaN}\n',
                         'nonobject':b'[]\n','invalid_json':b'{bad}\n','large_line':b'x'*(2*1024**2+1)}[invalid])
    elif invalid == 'sequence':
        raw = path.read_bytes(); path.write_bytes(raw.replace(b'"ledger_sequence": 4',b'"ledger_sequence": 3'))
    elif invalid == 'global':
        raw = path.read_bytes(); path.write_bytes(raw.replace(b'"ledger_sequence": 3',b'"ledger_sequence": 99'))
    elif invalid == 'count':
        files['cashflows.ndjson']['count'] += 1
    elif invalid == 'size':
        files['cashflows.ndjson']['bytes'] += 1
    elif invalid == 'inventory':
        files['cashflows.ndjson']['count'] = True
    elif invalid == 'symlink':
        target = tmp_path/'target'; path.rename(target); path.symlink_to(target)
    elif invalid == 'scope':
        kwargs['end_ms'] = 1791525600000
    else:
        kwargs['max_spool_bytes'] = 0
    with pytest.raises(StatisticsError):
        analyze_ledgers(tmp_path,files,**kwargs)


def rehash_campaign(root,manifest=None,summary=None,status=None):
    if manifest is not None:
        (root/'manifest.json').write_bytes(canonical_bytes(manifest))
    if summary is None:
        summary = json.loads((root/'summary.json').read_bytes())
    summary['manifest_sha256'] = hashlib.sha256((root/'manifest.json').read_bytes()).hexdigest()
    (root/'summary.json').write_bytes(canonical_bytes(summary))
    if status is None:
        status = json.loads((root/'status.json').read_bytes())
    status['manifest_sha256'] = summary['manifest_sha256']
    status['summary_sha256'] = hashlib.sha256((root/'summary.json').read_bytes()).hexdigest()
    (root/'status.json').write_bytes(canonical_bytes(status))


@pytest.mark.parametrize('mutation', ['status','scope','source','data_hash','signal_code','php_code',
    'instrument_hash','cost_identity','variant','source_order','net','trades','admission',
    'denominator','funding_provenance','funding_count','funding_gap','schema'])
def test_verified_campaign_rejects_forged_rehashed_semantics(tmp_path,mutation):
    protocol,registration = campaign_fixture(tmp_path)
    manifest = json.loads((tmp_path/'manifest.json').read_bytes())
    summary = json.loads((tmp_path/'summary.json').read_bytes())
    status = json.loads((tmp_path/'status.json').read_bytes())
    if mutation == 'status': status['status'] = 'failed'
    elif mutation == 'scope': manifest['cost_profile'] = 'adverse'
    elif mutation == 'source': manifest['source_runs'][0]['source']['dataset_sha256'] = '9'*64
    elif mutation == 'data_hash': protocol['identity']['dataset_hash'] = '9'*64
    elif mutation == 'signal_code': protocol['identity']['signal_code_hash'] = '9'*64
    elif mutation == 'php_code': manifest['research_code_hash'] = '9'*64
    elif mutation == 'instrument_hash': manifest['instrument_assumptions']['synthetic'] = False
    elif mutation == 'cost_identity': protocol['identity']['baseline_cost_hash'] = '9'*64
    elif mutation == 'variant': manifest['variant']['variant_hash'] = '9'*64
    elif mutation == 'source_order':
        manifest['source_runs'].reverse()
        protocol['phase_bindings']['training']['source_runs'] = manifest['source_runs']
        protocol['identity']['dataset_hash'] = hash_bytes(canonical_bytes({p:protocol['phase_bindings'][p]['source_runs'] for p in ('training','validation')}))
    elif mutation == 'net': summary['net_pnl_quote'] = '1'
    elif mutation == 'trades': summary['trades'] = 1
    elif mutation == 'admission': summary['admitted_plans'] = 1
    elif mutation == 'denominator': summary['b1_counters']['passed_rules'] = 1
    elif mutation == 'funding_provenance': summary['source_quality']['funding_inventory']['coverage'] = 'verified_complete'
    elif mutation == 'funding_count':
        manifest['funding_inventory']['symbols'][0]['count'] = 1
        manifest['funding_inventory']['inventory_hash'] = canonical_hash({k:v for k,v in manifest['funding_inventory'].items() if k != 'inventory_hash'})
        manifest['funding_inventory_hash'] = manifest['funding_inventory']['inventory_hash']
        summary['source_quality']['funding_inventory'] = manifest['funding_inventory']
    elif mutation == 'funding_gap': summary['source_quality']['kernel_funding_coverage'] = 'observed_only'
    else: status['schema_version'] = 'invented'
    registration['protocol_hash'] = hash_bytes(canonical_bytes(protocol))
    rehash_campaign(tmp_path,manifest,summary,status)
    with pytest.raises(StatisticsError):
        verify_campaign(tmp_path,protocol,registration)


def test_plan_and_source_identity_guard_and_private_root(tmp_path):
    files,_ = ledger_fixture(tmp_path)
    kwargs = dict(symbols=SYMBOLS,start_ms=START,end_ms=1735689600000)
    with pytest.raises(StatisticsError,match='ledger_identity'):
        analyze_ledgers(tmp_path,files,**kwargs,expected_identity={'variant_id':'foreign'},expected_sources={})
    with pytest.raises(StatisticsError,match='ledger_source'):
        analyze_ledgers(tmp_path,files,**kwargs,expected_identity={},expected_sources={s:{'dataset_id':'foreign'} for s in SYMBOLS})
    linked = tmp_path/'linked'; linked.symlink_to(tmp_path,target_is_directory=True)
    with pytest.raises(StatisticsError,match='unsafe_ledger_root'):
        analyze_ledgers(linked,files,**kwargs)


def test_cashflow_concurrency_and_periodic_spool_budget(tmp_path):
    ledger_fixture(tmp_path)
    rows = read_rows(tmp_path); opening = []
    for index in range(5):
        plan = copy.deepcopy(rows[0][1]); plan['evaluated_ms'] += index
        plan['plan_hash'] = canonical_hash({k:v for k,v in plan.items() if k != 'plan_hash'})
        flow = {**rows[3][1],'plan_hash':plan['plan_hash']}
        opening += [('plans',plan),('cashflows',flow)]
    files = write_ledgers(tmp_path,opening)
    with pytest.raises(StatisticsError,match='concurrency'):
        analyze_ledgers(tmp_path,files,symbols=SYMBOLS,start_ms=START,end_ms=1735689600000)
    # Repeated non-trading evidence still exercises periodic resource checks.
    files = write_ledgers(tmp_path,[('rejections',{'symbol':'BTCUSDT'}) for _ in range(130)])
    with pytest.raises(StatisticsError,match='spool_capacity'):
        analyze_ledgers(tmp_path,files,symbols=SYMBOLS,start_ms=START,end_ms=1735689600000,max_spool_bytes=0)


def test_funding_outside_phase_and_missing_campaign_evidence(tmp_path):
    files = write_ledgers(tmp_path,[('funding-events',{'symbol':'BTCUSDT','timestamp_ms':START-1,'rate':'0.01'})])
    with pytest.raises(StatisticsError,match='funding_event'):
        analyze_ledgers(tmp_path,files,symbols=SYMBOLS,start_ms=START,end_ms=1735689600000)
    protocol,registration = campaign_fixture(tmp_path)
    (tmp_path/'summary.json').unlink()
    with pytest.raises(StatisticsError,match='missing_campaign'):
        verify_campaign(tmp_path,protocol,registration)


def test_independent_gross_price_pnl_from_actual_plan_units(tmp_path):
    ledger_fixture(tmp_path)
    rows = read_rows(tmp_path)
    rows[-1][1]['exit_price'] = '105'
    files = write_ledgers(tmp_path,rows)
    with pytest.raises(StatisticsError,match='gross_price'):
        analyze_ledgers(tmp_path,files,symbols=SYMBOLS,start_ms=START,end_ms=1735689600000)


def test_actual_c1_synthetic_nonzero_artifact_integration(tmp_path,monkeypatch):
    """C1 calls are dependency-injected; no real PHP/source worker runs."""
    from tests.test_research_campaign import fixture,Builder
    from app.backtesting.research import campaign
    kwargs,_,_ = fixture(tmp_path,monkeypatch,passed=True)
    Builder.positive = True
    summary = campaign.run_campaign(**kwargs)
    root = kwargs['output_root']; status = json.loads((root/'status.json').read_bytes())
    result = analyze_ledgers(root,status['files'],symbols=('BTCUSDT',),
                            start_ms=summary['start_ms'],end_ms=summary['end_ms'])
    assert result['closed_trades'] == 1
    assert D(result['net_pnl_quote']) == D('19.916')
    assert D(result['mean_realized_net_r']) == D('19.916')/D('10.155')
    assert result['counts']['funding_events'] == 1


@pytest.mark.parametrize('mutation',['physical_denominator','phase_clock','negative_drawdown'])
def test_completed_evidence_counters_and_marked_metric_bounds(tmp_path,mutation):
    protocol,registration = campaign_fixture(tmp_path)
    summary = json.loads((tmp_path/'summary.json').read_bytes())
    if mutation == 'physical_denominator':
        summary['b1_counters'].update(evaluated_ticks=1000,failed_rules=1000)
    elif mutation == 'phase_clock':
        summary['phase_batches'] -= 1
    else:
        summary['maximum_drawdown_quote'] = '-1'
    rehash_campaign(tmp_path,summary=summary)
    with pytest.raises(StatisticsError): verify_campaign(tmp_path,protocol,registration)
