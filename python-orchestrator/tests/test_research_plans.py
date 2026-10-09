"""Persistent PHP boundary tests; all prices and signals here are synthetic."""
import copy
import json
import os
from pathlib import Path
import subprocess
import sys

import pytest

from app.backtesting.research import plans
from app.backtesting.research.portfolio_simulator import canonical_hash, Signal

H = 'sha256:' + 'a' * 64
START = 1672531200000


def manifests():
    instruments = {'schema_version': 'research-instrument-assumptions.v1',
        'source_url': 'https://fapi.binance.com/fapi/v1/exchangeInfo',
        'retrieved_at': '2026-10-09T00:00:00Z', 'raw_sha256': 'd' * 64,
        'validity_statement': 'Current exchange metadata only; historical precision is not attested.',
        'assumption_status': 'current_metadata_not_historical',
        'symbols': [{'symbol': s, 'tick_size': .01, 'quantity_step': .001,
            'min_quantity': .001, 'max_quantity': 100000, 'min_notional': 5,
            'contract_size': 1, 'leverage_cap': 2, 'mmr_proxy_rate': .005,
            'liquidation_fee_rate': .001} for s in plans.SYMBOLS]}
    instruments['manifest_hash'] = canonical_hash(instruments)
    rates = {k: 0 for k in ('entry_spread_rate', 'stop_spread_rate', 'target_spread_rate',
        'entry_slippage_rate', 'stop_slippage_rate', 'target_slippage_rate')}
    rates['funding_provision_rate'] = .0001
    costs = {'schema_version': 'research-cost-assumptions.v1',
        'frozen_at': '2026-10-09T00:00:00Z',
        'fee_basis': 'fake_local_policy_not_historical_binance',
        'assumption_status': 'hypothetical_ohlcv_costs', 'units': 'fraction_of_notional',
        'profiles': {'baseline': rates, 'adverse': dict(rates)}}
    costs['assumption_hash'] = canonical_hash(costs)
    return instruments, costs


def opening():
    instruments, costs = manifests()
    baseline = {'config_hash': H, 'snapshot_hash': H, 'setup_hash': 'a'*64,
                'condition_catalog_hash': H, 'file_hashes': {}, 'mode_risk': {}}
    source = {'dataset_id': 'synthetic', 'dataset_sha256': 'b'*64,
              'source_venue': 'binance_usdm', 'source_network': 'mainnet', 'market_type': 'perpetual'}
    return {'schema_version': 'research-plan-open.v1', 'session_id': 'synthetic',
        'baseline': baseline, 'source_runs': [{'symbol': 'BTCUSDT', 'source': source,
            'signal_session_id': 'synthetic', 'signal_run_id': 'synthetic',
            'signal_output_sha256': 'c'*64, 'start_ms': START-60000,
            'score_start_ms': START, 'end_ms': START+60000}],
        'variant': plans.variant_selection('baseline', baseline),
        'instrument_assumptions': instruments, 'cost_assumptions': costs,
        'cost_profile': 'baseline', 'expected_signals': 1}


WORKER = r'''
import json, sys, time, hashlib, os
mode = sys.argv[1]
if mode == 'backpressure': os.write(2, b'x' * 100000)
def emit(v): print(json.dumps(v), flush=True)
count = 0
for line in sys.stdin:
    f = json.loads(line)
    if mode == 'timeout': time.sleep(10)
    if mode in ('stderr','stderr_ok'): os.write(2, b'x' * 100000)
    if mode == 'malformed': print('no-json', flush=True); continue
    if mode == 'partial': os.write(1, b'{'); sys.exit(0)
    if mode == 'line': os.write(1, b'x'*10000); continue
    if mode == 'eof': sys.exit(0)
    if f['schema_version'] == 'research-plan-open.v1':
        opened = f
        r = {'schema_version':'research-plan-opened.v1', 'session_id':f['session_id'],
             'variant_id':f['variant']['id'],'variant_hash':f['variant']['variant_hash'],
             'base_config_hash':f['baseline']['config_hash'], 'base_snapshot_hash':f['baseline']['snapshot_hash'],
             'instrument_assumptions_hash':f['instrument_assumptions']['manifest_hash'],
             'cost_assumptions_hash':f['cost_assumptions']['assumption_hash'],
             'cost_profile':f['cost_profile'],'source_run_count':len(f['source_runs']),
             'expected_signals':f['expected_signals'],'research_code_hash':'sha256:'+'e'*64,
             'code_hash_scope':'research_plan_app_php_vendor_config_content_v2',
             'code_identity_check_policy':'content_at_open_and_close_immutable_appdir_during_session',
             'execution_authority':'none'}
        if mode == 'badopen': r['session_id'] = 'wrong'
    elif f['schema_version'] == 'research-plan-signal.v1':
        count += 1
        run = opened['source_runs'][0]
        b = opened['baseline']
        r = {'schema_version':'research-plan-rejection.v1','research_only':True,
             'signal_result_hash':f['signal']['result_hash'],'signal_index':f['signal_index'],
             'signal_run_id':run['signal_run_id'],'signal_output_sha256':run['signal_output_sha256'],
             **run['source'],'base_setup_hash':b['setup_hash'],'base_config_hash':b['config_hash'],
             'base_catalog_hash':b['condition_catalog_hash'],'base_snapshot_hash':b['snapshot_hash'],
             'variant_id':opened['variant']['id'],'variant_hash':opened['variant']['variant_hash'],
             'instrument_assumptions_hash':opened['instrument_assumptions']['manifest_hash'],
             'cost_assumptions_hash':opened['cost_assumptions']['assumption_hash'],
             'research_code_hash':'sha256:'+'e'*64,'code_hash_scope':'research_plan_app_php_vendor_config_content_v2',
             'integrity_boundary':'sha256_and_local_baseline_only_runner_verifies_b1_artifact',
             'reason_code':'research_signal_price_context_missing',
             'cost_profile':opened['cost_profile'],
             'portfolio_hash':f['portfolio']['portfolio_hash']}
        if mode == 'badresult': r['variant_hash'] = 'forged'
        if mode == 'badportfolio': r['portfolio_hash'] = 'forged'
        if mode == 'error': r = {'schema_version':'research-plan-error.v1','reason_code':'broken'}
        if mode == 'badreason': r['reason_code'] = 'invalid reason'
        if mode == 'badschema': r['schema_version'] = 'foreign-schema'
        if mode in ('plan','badplan'):
            r.pop('reason_code')
            r.update({'schema_version':'research-plan.v1','execution_authority':'none',
                      'symbol':f['signal']['symbol'],'evaluated_ms':f['signal']['evaluated_ms']})
            r['plan_hash'] = 'sha256:'+hashlib.sha256(json.dumps(r,sort_keys=True,separators=(',',':')).encode()).hexdigest()
            if mode == 'badplan': r['plan_hash'] = 'sha256:'+'0'*64
    else:
        r = {'schema_version':'research-plan-summary.v1','session_id':opened['session_id'],
             'received':count,'planned':0,'rejected':count,
             'rejection_counts': {'research_signal_price_context_missing':count} if count else [],
             'completion':'complete','execution_authority':'none',
             'research_code_hash':'sha256:'+'e'*64,
             'code_hash_scope':'research_plan_app_php_vendor_config_content_v2',
             'code_identity_check_policy':'content_at_open_and_close_immutable_appdir_during_session'}
        if mode == 'badsummary': r['received'] += 1
        if mode == 'missing_summary': continue
        if mode == 'plan': r.update(planned=count,rejected=0,rejection_counts=[])
    emit(r)
    if mode == 'extra': emit({})
    if mode == 'after_summary' and f['schema_version'] == 'research-plan-close.v1':
        time.sleep(.05); emit({})
if mode == 'nonzero': sys.exit(2)
'''


def worker(tmp_path, frame=None, mode='ok', **limits):
    return plans.PlanWorker(tmp_path, frame or opening(),
        worker_argv=(sys.executable, '-c', WORKER, mode),
        wall_timeout=2, io_timeout=.5, **limits)


def signal_view():
    f = opening()
    return Signal(7, {'symbol':'BTCUSDT', 'result_hash':H, 'evaluated_ms':START,
        'passed':True}), {'portfolio_hash':H}


def test_closed_catalog_hash_and_persistent_rejection(tmp_path):
    f = opening()
    assert len(plans.VARIANT_DIFFS) == 13
    for name in plans.VARIANT_DIFFS:
        variant = plans.variant_selection(name, f['baseline'])
        assert variant['variant_hash'] == canonical_hash({'schema_version':'research-variant.v1',
            'id':name,'diff':variant['diff'],'base_setup_hash':'a'*64,
            'base_config_hash':H,'base_catalog_hash':H})
    with worker(tmp_path) as w:
        pid = w.process.pid
        result = w.build(*signal_view())
        assert result['portfolio_hash'] == H
        assert w.process.pid == pid
        summary = w.close()
        assert summary['received'] == 1
        assert w.evidence()['exit_code'] == 0
        assert w.evidence()['stderr_bytes'] == 0


@pytest.mark.parametrize('mode', ['timeout','stderr','malformed','partial','line','eof','badopen'])
def test_bounded_open_errors_reap_child(tmp_path, mode):
    with pytest.raises(plans.PlanError):
        worker(tmp_path, mode=mode, max_stderr_bytes=100, max_line_bytes=8000)


def test_unsolicited_extra_frame_aborts_by_next_exchange(tmp_path):
    # Pipe writes may arrive in separate reads; completion must still fail.
    with pytest.raises(plans.PlanError):
        with worker(tmp_path,mode='extra') as w:
            w.build(*signal_view())
            w.close()


@pytest.mark.parametrize('mode', ['badresult','badportfolio','error'])
def test_malformed_reply_aborts_instead_of_becoming_rejection(tmp_path, mode):
    with worker(tmp_path, mode=mode) as w:
        with pytest.raises(plans.PlanError):
            w.build(*signal_view())


@pytest.mark.parametrize('mode', ['badsummary','missing_summary','nonzero'])
def test_close_requires_exact_accounting_and_clean_exit(tmp_path, mode):
    with worker(tmp_path, mode=mode) as w:
        w.build(*signal_view())
        with pytest.raises(plans.PlanError):
            w.close()


def test_missing_count_duplicate_index_and_unknown_variant(tmp_path):
    with pytest.raises(plans.PlanError): plans.variant_selection('unbounded', opening()['baseline'])
    with worker(tmp_path) as w:
        with pytest.raises(plans.PlanError): w.close()
        w.build(*signal_view())
        with pytest.raises(plans.PlanError): w.build(*signal_view())


def test_zero_expected_signal_session(tmp_path):
    f = opening()
    f['expected_signals'] = 0
    with worker(tmp_path, f) as w:
        assert w.close()['received'] == 0


def test_explicit_isolated_environment():
    env = plans.worker_environment()
    assert env['APP_ENV'] == 'prod'
    assert env['DOTENV_PATH'] == '/dev/null'
    assert env['SYMFONY_DOTENV_PATH'] == '/dev/null'
    assert '127.0.0.1:1/unreachable' in env['DATABASE_URL']
    assert set(env) <= {'APP_ENV','APP_DEBUG','DOTENV_PATH','SYMFONY_DOTENV_PATH',
        'DATABASE_URL','DEFAULT_URI','PATH','LC_ALL','LANG'}


@pytest.fixture(scope='module')
def actual_opening():
    import shutil
    app = Path(__file__).resolve().parents[2]/'trading-app'
    php = shutil.which('php')
    if php is None or not (app/'vendor/autoload.php').is_file():
        pytest.skip('actual Symfony handshake requires PHP and locally installed vendor')
    f = opening()
    run = f['source_runs'][0]
    frame = {'schema_version':'research-signal-open.v1','session_id':run['signal_session_id'],
        **run['source'],'symbol':run['symbol'],'start_ms':run['start_ms'],
        'end_ms':run['end_ms'],'score_start_ms':run['score_start_ms']}
    raw = json.dumps(frame)+'\n'+json.dumps({'schema_version':'research-signal-close.v1','session_id':run['signal_session_id']})+'\n'
    result = subprocess.run((php,'-d','display_errors=stderr',str(app/'bin/console'),
        'app:research:signals','--no-interaction'),input=raw.encode(),capture_output=True,
        env=plans.worker_environment(),cwd=app,timeout=30)
    acknowledgement = json.loads(result.stdout.splitlines()[0])
    assert acknowledgement['schema_version'] == 'research-signal-opened.v1'
    f['baseline'] = acknowledgement['baseline']
    f['expected_signals'] = 0
    return app,f


@pytest.mark.parametrize('name',tuple(plans.VARIANT_DIFFS))
def test_actual_thirteen_wire_catalogue_handshakes(actual_opening,name):
    app,f = actual_opening
    f = copy.deepcopy(f)
    f['variant'] = plans.variant_selection(name,f['baseline'])
    with plans.PlanWorker(app,f,wall_timeout=30,io_timeout=20) as w:
        assert w.opened['variant_hash'] == f['variant']['variant_hash']
        assert w.close()['research_code_hash'] == canonical_hash(plans.code_inventory(app))
        assert w.evidence()['exit_code'] == 0


def test_actual_php_plan_is_admitted_and_settled_by_kernel(actual_opening):
    from decimal import Decimal as D
    from app.backtesting.research.campaign import _kernel_instruments
    from app.backtesting.research.portfolio_simulator import (
        Candle,CostAssumptions,FundingCoverage,PortfolioSimulator,RunAssumptions)
    app,f = actual_opening
    f = copy.deepcopy(f)
    fixture = json.loads((Path(__file__).parent/'fixtures/backtesting/php-research-plan.json').read_text())
    signal = fixture['signal']
    tick = signal['evaluated_ms']
    f['source_runs'][0]['end_ms'] = tick+180000
    f['expected_signals'] = 1
    f['variant'] = plans.variant_selection('baseline',f['baseline'])
    signal.update(session_id=f['source_runs'][0]['signal_session_id'],
                  source=f['source_runs'][0]['source'],baseline=f['baseline'])
    for field,baseline_key in (('setup_hash','setup_hash'),('catalog_hash','condition_catalog_hash'),('config_hash','config_hash')):
        signal['trace'][field] = f['baseline'][baseline_key]
    signal['trace_hash'] = canonical_hash(signal['trace'])
    signal['result_hash'] = canonical_hash({k:v for k,v in signal.items() if k != 'result_hash'})
    with plans.PlanWorker(app,f,wall_timeout=30,io_timeout=20) as worker:
        b = f['baseline']
        identity = {'base_setup_hash':b['setup_hash'],'base_config_hash':b['config_hash'],
            'base_catalog_hash':b['condition_catalog_hash'],'base_snapshot_hash':b['snapshot_hash'],
            'variant_id':'baseline','variant_hash':f['variant']['variant_hash'],
            'instrument_assumptions_hash':f['instrument_assumptions']['manifest_hash'],
            'cost_assumptions_hash':f['cost_assumptions']['assumption_hash'],
            'research_code_hash':worker.opened['research_code_hash']}
        run = f['source_runs'][0]
        source = {**{k:run['source'][k] for k in ('dataset_id','dataset_sha256')},
                  **{k:run[k] for k in ('signal_run_id','signal_output_sha256')}}
        assumptions = RunAssumptions(tick,tick+180000,'training',('BTCUSDT',),
            _kernel_instruments(f['instrument_assumptions'],('BTCUSDT',)),
            CostAssumptions(D('.0002'),D('.0005'),D('.0005'),*(D(0) for _ in range(6)),D('.0001'),28800,1),
            tuple(identity.items()),(('BTCUSDT',tuple(source.items())),),
            FundingCoverage('verified_complete',H,(('BTCUSDT',0),)),
            'adverse_possible_credit_certain.v1','last_known_close.v1')
        ledger = {key:[] for key in ('events','trades','cashflows','rejections')}
        sim = PortfolioSimulator(assumptions,worker.build,event_sink=ledger['events'].append,
            trade_sink=ledger['trades'].append,cashflow_sink=ledger['cashflows'].append,
            rejection_sink=ledger['rejections'].append)
        def bar(t,high='101'):
            return (Candle('BTCUSDT',t,D('100'),D(high),D('99'),D('100')),)
        sim.prime(bar(tick-60000),signals=(Signal(0,signal),))
        assert sim.admitted_plans == 1, ledger['rejections']
        sim.advance(bar(tick))
        sim.advance(bar(tick+60000,high='120'))
        sim.advance(bar(tick+120000))
        summary = sim.finish()
        assert summary['trades'] == 1
        assert summary['net_pnl_quote'] > 0
        assert worker.close()['planned'] == 1


@pytest.mark.parametrize('raw',[b'{"a":1,"a":2}',b'{"a":NaN}',b'[]',b'null',b'\xff'])
def test_strict_json_invalid_inputs(raw):
    with pytest.raises(plans.PlanError): plans._json(raw)


@pytest.mark.parametrize('limits',[{'wall_timeout':0},{'io_timeout':0},{'max_stderr_bytes':-1},
    {'max_output_bytes':0},{'max_line_bytes':0}])
def test_invalid_process_limits(tmp_path,limits):
    with pytest.raises(plans.PlanError): plans.PlanWorker(tmp_path,opening(),worker_argv=(sys.executable,),**limits)


def test_bad_process_paths_and_missing_php(tmp_path,monkeypatch):
    for app in (Path('relative'),tmp_path/'missing'):
        with pytest.raises(plans.PlanError): plans.PlanWorker(app,opening())
    link = tmp_path/'link';link.symlink_to(tmp_path,target_is_directory=True)
    with pytest.raises(plans.PlanError): plans.PlanWorker(link,opening())
    for argv in ((),('relative',),('/missing-php',)):
        with pytest.raises(plans.PlanError): plans.PlanWorker(tmp_path,opening(),worker_argv=argv)
    monkeypatch.setattr(plans,'code_inventory',lambda root:{'scope':'synthetic','files':{'one':'a'*64}})
    monkeypatch.setattr(plans.shutil,'which',lambda name:None)
    with pytest.raises(plans.PlanError): plans.PlanWorker(tmp_path,opening())


def test_full_inventory_includes_data_config_locks_but_only_php_src(tmp_path):
    for name in ('src/One.php','src/Skip.txt','vendor/package/data.txt','config/one.yaml',
                 'bin/console','composer.json','composer.lock','symfony.lock'):
        path = tmp_path/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_text(name)
    inventory = plans.code_inventory(tmp_path)
    assert 'src/Skip.txt' not in inventory['files']
    assert 'vendor/package/data.txt' in inventory['files']
    (tmp_path/'config/link').symlink_to(tmp_path/'config/one.yaml')
    with pytest.raises(plans.PlanError): plans.code_inventory(tmp_path)
    (tmp_path/'config/link').unlink()
    os.mkfifo(tmp_path/'vendor/fifo')
    with pytest.raises(plans.PlanError): plans.code_inventory(tmp_path)
    (tmp_path/'vendor/fifo').unlink()
    (tmp_path/'symfony.lock').unlink()
    with pytest.raises(plans.PlanError): plans.code_inventory(tmp_path)
    with pytest.raises(plans.PlanError): plans.code_inventory(tmp_path/'missing')


@pytest.mark.parametrize('mode',['badreason','badschema','badplan'])
def test_result_shape_and_hash_errors(tmp_path,mode):
    with worker(tmp_path,mode=mode) as w:
        with pytest.raises(plans.PlanError): w.build(*signal_view())


def test_positive_wire_plan_and_stderr_backpressure(tmp_path):
    with worker(tmp_path,mode='plan') as w:
        assert w.build(*signal_view())['schema_version'] == 'research-plan.v1'
        assert w.close()['planned'] == 1
        with pytest.raises(plans.PlanError): w.build(*signal_view())
    with worker(tmp_path,mode='stderr_ok') as w:
        w.build(*signal_view());w.close()
        assert w.evidence()['stderr_bytes'] == 300000
    f = opening();f['padding'] = 'p'*500000
    with worker(tmp_path,f,mode='backpressure') as w:
        w.build(*signal_view());w.close()
        assert w.evidence()['stderr_bytes'] == 100000


def test_nested_immutable_kernel_signal_payload_roundtrips(tmp_path):
    sig,view = signal_view()
    nested = Signal(sig.index,{**sig.payload,'contexts':{'15m':{'close':100,'series':[1,2]}}})
    with worker(tmp_path) as w:
        assert w.build(nested,view)['schema_version'] == 'research-plan-rejection.v1'
        w.close()


def test_input_output_size_caps_and_unsolicited_data(tmp_path):
    with pytest.raises(plans.PlanError): worker(tmp_path,max_output_bytes=1)
    with pytest.raises(plans.PlanError): worker(tmp_path,max_line_bytes=1)
    with worker(tmp_path) as w:
        w.pending.extend(b'unsolicited')
        with pytest.raises(plans.PlanError): w.build(*signal_view())
    with worker(tmp_path,mode='after_summary') as w:
        w.build(*signal_view())
        with pytest.raises(plans.PlanError): w.close()


def test_failure_terminates_and_reaps_owned_child(tmp_path,monkeypatch):
    original = plans.subprocess.Popen
    children = []
    def capture(*args,**kwargs):
        child = original(*args,**kwargs);children.append(child);return child
    monkeypatch.setattr(plans.subprocess,'Popen',capture)
    with pytest.raises(plans.PlanError): worker(tmp_path,mode='timeout')
    assert len(children) == 1
    assert children[0].poll() is not None
