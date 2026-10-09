"""Synthetic authority/once-only tests; never open campaign market evidence."""
import dataclasses
import hashlib
import json
import os
from pathlib import Path
import sys

import pytest
from app.backtesting.research import holdout_authority as h_module

REAL_PREFLIGHT = h_module._preflight_identities
REAL_TRANSPORT = h_module._invoke_frozen_verifier
REAL_VERIFY_RUNTIME = h_module._verify_runtime_inventory
REAL_RUNTIME_INVENTORY = h_module._runtime_inventory


def test_authority_module_exists_and_has_fixed_campaign():
    from app.backtesting.research import holdout_authority as h
    assert h.CAMPAIGN_ID == 'historical-2026-10-09'


@pytest.fixture
def fixture(tmp_path, monkeypatch):
    from app.backtesting.research import holdout_authority as h
    monkeypatch.setattr(Path, 'home', classmethod(lambda cls: tmp_path))
    registry = tmp_path/'registry'; registry.mkdir(mode=0o700)
    paths = {}
    for name in ('dataset_root', 'app_dir', 'frozen_python_root'):
        p = tmp_path/name; p.mkdir(mode=0o700); paths[name] = str(p)
    for name in ('instrument_path', 'cost_path', 'python'):
        p = tmp_path/name; p.write_bytes(b'{}\n'); paths[name] = str(p)
    paths['funding_supplement_root'] = None
    protocol = {'schema_version':'research-experiment-protocol.v1',
        'cutoff':h.END, 'symbol_priority':list(h.SYMBOLS), 'cost_profiles':['baseline','adverse'],
        'variants':[{'id':'baseline','diff':{}}, {'id':'width_050','diff':{'zone_atr_multiplier':0.5}}],
        'risk_assumptions':dict(h.RISK_ASSUMPTIONS), 'identity':{}, 'phase_bindings':{}}
    (registry/'protocol.json').write_bytes(h.canonical_bytes(protocol))
    (registry/'protocol.json').chmod(0o600)
    freeze = {'schema_version':'research-selection-freeze.v1',
        'protocol_hash':h.hash_bytes(h.canonical_bytes(protocol)), 'cutoff':h.END,
        'selection_state':'selected', 'selected':protocol['variants'][0],
        'identity':{}, 'holdout_authorization':'guarded_selected_identity_only',
        'holdout_status':'pending','execution_authority':'none','paper_transfer':'distinct_pending'}
    config = {'schema_version':'research-campaign-authority.v1','campaign_id':h.CAMPAIGN_ID,
        'registry':str(registry),'protocol_hash':freeze['protocol_hash'],'paths':paths,
        'runtime':{'files':{},'dependency_roots':[],'python_version':'synthetic'},
        'repository_inventory':{}, 'retained':{},'snapshot':{},'original_runtime':{},'recovery':None}
    installed = h._authority_path(); installed.parent.mkdir(parents=True, mode=0o700)
    installed.write_bytes(h.canonical_bytes(config)); installed.chmod(0o600)
    monkeypatch.setattr(h, '_verify_runtime_inventory', lambda a: None)
    monkeypatch.setattr(h, '_preflight_identities', lambda a,p: {'synthetic':'bound'})
    monkeypatch.setattr(h.shutil, 'disk_usage', lambda p: type('Disk', (), {'free':100*1024**3})())
    def response(a, *, timeout):
        return (registry/'selection-freeze.json').read_bytes()
    monkeypatch.setattr(h, '_invoke_frozen_verifier', response)
    class F:
        module = h
        output = tmp_path/'output'
        other_output = tmp_path/'other-output'
        anchor = registry.parent/(registry.name+'.c2b-holdout')
        def authority(self): return h.load_campaign_authority()
        def selected(self, name='baseline', state='selected'):
            freeze.update(selection_state=state, selected=next((x for x in protocol['variants'] if x['id']==name),None))
            (registry/'selection-freeze.json').write_bytes(h.canonical_bytes(freeze))
            (registry/'selection-freeze.json').chmod(0o600)
        def claim(self, **kwargs): return h.prepare_claim(self.authority(), self.output, **kwargs)
    f = F(); f.protocol = protocol; f.config = config; f.freeze = freeze; f.installed = installed
    f.selected(); return f


def test_no_winner_never_claims_or_reads_holdout(fixture):
    f = fixture; f.selected(state='no_eligible_candidate')
    sentinel = Path(f.config['paths']['dataset_root'])/'holdout-sentinel'
    sentinel.write_bytes(b'never read')
    with pytest.raises(f.module.HoldoutError, match='no_selected_candidate'): f.claim()
    assert not (f.anchor/'claim.json').exists()
    assert not f.output.exists()


@pytest.mark.parametrize('selected,count', [('baseline',2), ('width_050',4)])
def test_exact_unique_slots_and_fresh_output_cannot_repeat(fixture, selected, count):
    f = fixture; f.selected(selected)
    auth = f.claim()
    assert len(auth.slots) == count
    contract = f.module.require_claim(auth, operation='signals')
    assert contract['window']['end'] == f.module.END
    assert auth.end_ms == 1791525600000
    assert contract['slots'][0]['input_binding_path'].startswith(str(f.output/'inputs'))
    with pytest.raises(f.module.HoldoutError, match='already_claimed'):
        f.module.prepare_claim(f.authority(), f.other_output)


def test_claim_retains_observed_runner_interpreter_distinct_from_frozen_verifier(fixture,monkeypatch):
    auth = fixture.claim()
    contract = json.loads(auth.contract_bytes)
    runner = contract['runner_interpreter']
    assert runner['invoked_path']==sys.executable
    assert runner['resolved_path']==str(Path(sys.executable).resolve())
    assert runner['version']==sys.version
    assert runner['on_disk_binary_sha256']==h_module._file_hash(Path(sys.executable).resolve())
    assert runner['dependency_environment_attestation']=='unavailable'
    assert runner['binary_hash_scope']=='on_disk_file_not_process_memory_or_host_os'
    assert runner['invoked_path']!=contract['paths']['python']
    monkeypatch.setattr(sys,'executable','/different/verification/python')
    monkeypatch.setattr(sys,'version','different verification interpreter')
    replay=h_module.mint_retained_authorization(fixture.authority())
    assert json.loads(replay.contract_bytes)['runner_interpreter']==runner


def test_fake_and_replaced_capabilities_deny_before_reads(fixture):
    f = fixture; auth = f.claim(); h = f.module
    for fake in (None, dataclasses.replace(auth), dataclasses.replace(auth, output_root=f.other_output),
            dataclasses.replace(auth, mode='verify'), dataclasses.replace(auth, slots=(('evil','baseline'),)),
            dataclasses.replace(auth, _mint=None)):
        with pytest.raises(h.HoldoutError, match='authorization'): h.require_claim(fake, operation='signals')
    with pytest.raises(h.HoldoutError, match='single_slot'): h.require_claim(auth, operation='simulate', variant_id='baseline',cost_profile='baseline')
    slot = auth.for_slot('baseline','baseline')
    h.require_claim(slot, operation='simulate', variant_id='baseline', cost_profile='baseline')
    with pytest.raises(h.HoldoutError): slot.for_slot('width_050','adverse')
    with pytest.raises(h.HoldoutError): h.require_claim(slot, operation='simulate', symbols=('ETHUSDT',), variant_id='baseline',cost_profile='baseline')
    verify = h.mint_retained_authorization(f.authority())
    assert verify.mode == 'verify'
    with pytest.raises(h.HoldoutError, match='verify_only'): h.require_claim(verify, operation='signals')
    h.require_claim(verify.for_slot('baseline','baseline'), operation='statistics', variant_id='baseline',cost_profile='baseline')


def test_changed_code_or_source_identity_denies_existing_claim(fixture, monkeypatch):
    f = fixture; auth = f.claim()
    monkeypatch.setattr(f.module,'_preflight_identities',lambda a,p:{'synthetic':'changed'})
    with pytest.raises(f.module.HoldoutError,match='identity_changed'):
        f.module.require_claim(auth,operation='signals')


@pytest.fixture
def preflight(fixture, monkeypatch):
    f = fixture; h = f.module
    from app.backtesting.research import plans, signals
    from app.backtesting.research.portfolio_simulator import canonical_hash
    app = Path(f.config['paths']['app_dir'])
    for name in ('src/file.php','vendor/file.php','config/file.yaml','bin/console',
                 'composer.json','composer.lock','symfony.lock'):
        path = app/name; path.parent.mkdir(parents=True,exist_ok=True); path.write_text('{}')
    php = plans.code_inventory(app)
    baseline = {'setup_hash':'a'*64,'config_hash':'b'*64,'condition_catalog_hash':'c'*64,
        'snapshot_hash':'d'*64,'file_hashes':{str(app/'src/file.php'):h._file_hash(app/'src/file.php')}}
    meta = {'baseline':baseline,'effective_config_snapshot':{'ordered_files':[str(app/'src/file.php')]}}
    protocol = dict(f.protocol)
    protocol['phase_bindings'] = {phase:{'signal_reports':[
        {'symbols':{s:meta for s in h.SYMBOLS[:5]}}, {'symbols':{s:meta for s in h.SYMBOLS[5:]}}]}
        for phase in ('training','validation')}
    loaded_reports=[]
    def refresh_reports():
        for summary,loaded in loaded_reports:
            raw=h.canonical_bytes(loaded)
            path=Path(summary['root'])/'report.json'; path.write_bytes(raw); path.chmod(0o600)
            summary.update(report_sha256=h.hash_bytes(raw),symbols=list(json.loads(raw)['symbols']))
    for phase,binding in protocol['phase_bindings'].items():
        summaries=[]
        for index,report in enumerate(binding['signal_reports']):
            root=f.output.parent/(phase+'-report-'+str(index)); root.mkdir(mode=0o700)
            loaded=report|{'schema':'research-signal-run.v1','status':'complete','errors':[],'code_sha256':{}}
            summary={'root':str(root),'report_sha256':'','symbols':[],'code_sha256':{}}
            summaries.append(summary); loaded_reports.append((summary,loaded))
        binding['signal_reports']=summaries
    refresh_reports()
    protocol['identity'] = {**{'base_'+name+'_hash':baseline[key] for name,key in
        (('setup','setup_hash'),('config','config_hash'),('catalog','condition_catalog_hash'),('snapshot','snapshot_hash'))}}
    instrument = {'synthetic':'instrument'}
    instrument['manifest_hash'] = canonical_hash(instrument)
    costs = {'profiles':{'baseline':{'fee':'0.1'},'adverse':{'fee':'0.2'}}}
    costs['assumption_hash'] = canonical_hash(costs)
    for key,value in (('instrument_path',instrument),('cost_path',costs)):
        Path(f.config['paths'][key]).write_bytes(json.dumps(value,indent=2).encode())
    protocol['identity'].update(instrument_assumptions_hash=instrument['manifest_hash'],
        **{p+'_cost_hash':canonical_hash(costs['profiles'][p]) for p in ('baseline','adverse')})
    manifest = Path(f.config['paths']['dataset_root'])/'manifest.json'; manifest.write_bytes(b'{}')
    f.config.update(snapshot={'files':{str(manifest):h._file_hash(manifest),
        f.config['paths']['python']:h._file_hash(Path(f.config['paths']['python']))},'runner_code':{},'php':php},
        original_runtime={'python':f.config['paths']['python'],'sha256':h._file_hash(Path(f.config['paths']['python']))})
    f.installed.write_bytes(h.canonical_bytes(f.config))
    monkeypatch.setattr(h,'_new_code_inventory',lambda:{'synthetic':'a'*64})
    monkeypatch.setattr(signals,'_verify_baseline_files',lambda *a:None)
    f.real_protocol = protocol
    f.baseline = baseline
    f.php = php
    f.loaded_reports=loaded_reports
    f.refresh_reports=refresh_reports
    return f


def test_preflight_grouped_original_reports_and_real_canonical_hash(preflight):
    f = preflight; h = f.module
    # Restore the genuine production metadata preflight, not a business seam.
    result = REAL_PREFLIGHT(f.authority(),f.real_protocol)
    assert result['baseline'] == f.baseline
    assert result['php'] == f.php
    (Path(f.config['paths']['app_dir'])/'src/file.php').write_bytes(b'changed')
    with pytest.raises(h.HoldoutError): REAL_PREFLIGHT(f.authority(),f.real_protocol)


@pytest.mark.parametrize('mutation', ['noncanonical','schema','mismatch','unknown_selected'])
def test_invalid_freeze_denies_before_claim(fixture, monkeypatch, mutation):
    f = fixture; h = f.module; path = Path(f.config['registry'])/'selection-freeze.json'
    if mutation == 'noncanonical': path.write_text(json.dumps(f.freeze))
    elif mutation == 'mismatch': monkeypatch.setattr(h, '_invoke_frozen_verifier', lambda a,timeout:b'{}\n')
    else:
        f.freeze['schema_version' if mutation=='schema' else 'selected'] = 'unknown'
        path.write_bytes(h.canonical_bytes(f.freeze))
    with pytest.raises(h.HoldoutError): f.claim()
    assert not (f.anchor/'claim.json').exists()


@pytest.mark.parametrize('artifact', ['contract.json','.publish-interrupted.tmp','unknown'])
def test_ambiguous_state_retained_and_never_repaired(fixture, artifact):
    f = fixture; f.anchor.mkdir(mode=0o700)
    (f.anchor/artifact).write_bytes(b'proof'); (f.anchor/artifact).chmod(0o600)
    with pytest.raises(f.module.HoldoutError, match='ambiguous'): f.claim()
    assert (f.anchor/artifact).read_bytes() == b'proof'


def test_claim_remains_consumed_after_fsync_failure(fixture, monkeypatch):
    f = fixture; h = f.module; original = h._fsync_directory
    def fail(path):
        if (f.anchor/'claim.json').exists(): raise OSError('synthetic fsync')
        return original(path)
    monkeypatch.setattr(h, '_fsync_directory', fail)
    with pytest.raises(OSError, match='fsync'): f.claim()
    assert (f.anchor/'claim.json').exists()
    with pytest.raises(h.HoldoutError, match='already_claimed'):
        h.prepare_claim(f.authority(), f.other_output)


def test_installed_authority_private_canonical_no_symlinks(fixture):
    f = fixture; h = f.module
    f.installed.chmod(0o644)
    with pytest.raises(h.HoldoutError, match='private'): h.load_campaign_authority()
    f.installed.chmod(0o600); raw = f.installed.read_bytes(); f.installed.write_bytes(raw+b' ')
    with pytest.raises(h.HoldoutError, match='canonical'): h.load_campaign_authority()


def test_copied_registry_cannot_establish_authority(fixture):
    f = fixture; authority = f.authority()
    copied = f.output; copied.mkdir(mode=0o700)
    forged = dataclasses.replace(authority, registry=copied, anchor=copied.parent/'new-anchor')
    with pytest.raises(f.module.HoldoutError, match='authority'): f.module.prepare_claim(forged, f.other_output)


def test_retained_claim_and_contract_mutation_deny(fixture):
    f = fixture; auth = f.claim(); path = f.anchor/'contract.json'
    value = json.loads(path.read_bytes()); value['window']['end'] = '2099-01-01T00:00:00Z'
    path.write_bytes(f.module.canonical_bytes(value))
    with pytest.raises(f.module.HoldoutError): f.module.require_claim(auth, operation='signals')


@pytest.mark.parametrize('kwargs', [{'timeout':0}, {'timeout':float('inf')}, {'max_output_bytes':25*1024**3}, {'max_output_bytes':1}])
def test_limits_only_lower_and_terminal_capacity(fixture, kwargs):
    with pytest.raises(fixture.module.HoldoutError): fixture.claim(**kwargs)


def test_inventory_is_fixed_reviewed_relative_manifest():
    from app.backtesting.research import holdout_authority as h
    import re
    inventory = json.loads(Path(h.__file__).with_name('frozen_verifier_inventory.json').read_bytes())
    assert inventory['commit'] == h.FROZEN_COMMIT
    for name,digest in inventory['files'].items():
        assert not Path(name).is_absolute() and '..' not in Path(name).parts
        assert re.fullmatch('[a-f0-9]{64}',digest)
    assert set(inventory['files']) == set(h.FROZEN_FILES)


@pytest.fixture
def transport(fixture, monkeypatch):
    f = fixture; h = f.module
    root = Path(f.config['paths']['frozen_python_root'])
    for name in h.FROZEN_FILES:
        path = root/name; path.parent.mkdir(parents=True,exist_ok=True)
        path.write_bytes(b'')
    program = '''import json\nfrom pathlib import Path\ndef canonical_bytes(v):\n return (json.dumps(v,sort_keys=True,separators=(',',':'))+'\\n').encode()\ndef verify_freeze(p):\n return json.loads(Path(p).read_bytes())\n'''
    (root/'app/backtesting/research/experiments.py').write_text(program)
    f.config['paths']['python'] = str(Path(sys.executable).resolve())
    files = {name:h._file_hash(root/name) for name in h.FROZEN_FILES}
    runtime = {'files':{str(Path(sys.executable).resolve()):h._file_hash(Path(sys.executable).resolve())},
        'dependency_roots':[], 'python_version':'synthetic'}
    f.config.update(runtime=runtime,repository_inventory=files,
        original_runtime={'python':str(Path(sys.executable).resolve())})
    f.installed.write_bytes(h.canonical_bytes(f.config))
    monkeypatch.setattr(h,'_runtime_inventory',lambda *a:runtime)
    monkeypatch.setattr(h,'_trusted_repository_inventory',lambda:files)
    monkeypatch.setattr(h,'_verify_runtime_inventory',REAL_VERIFY_RUNTIME)
    f.root = root; f.files = files; f.runtime = runtime
    def program_changed(program):
        path = root/'app/backtesting/research/experiments.py'; path.write_text(program)
        files['app/backtesting/research/experiments.py'] = h._file_hash(path)
        f.installed.write_bytes(h.canonical_bytes(f.config))
    f.program_changed = program_changed
    return f


def test_real_fixed_bootstrap_ignores_pythonpath_and_sitecustomize(transport,monkeypatch):
    f = transport
    poison = f.output; poison.mkdir()
    sentinel = poison/'executed'
    (poison/'sitecustomize.py').write_text(f'from pathlib import Path;Path({str(sentinel)!r}).touch()')
    (poison/'app.py').write_text('raise RuntimeError("poison")')
    monkeypatch.setenv('PYTHONPATH',str(poison))
    monkeypatch.setenv('PYTHONSTARTUP',str(poison/'sitecustomize.py'))
    response = REAL_TRANSPORT(f.authority(),timeout=3)
    assert response == (Path(f.config['registry'])/'selection-freeze.json').read_bytes()
    assert not sentinel.exists()
    assert not list(f.root.rglob('__pycache__'))


@pytest.mark.parametrize('name', ['python','repository','dependency'])
def test_changed_runtime_bytes_denied_before_launch(transport,monkeypatch,name):
    f = transport; h = f.module
    if name=='repository': (f.root/'app/backtesting/research/statistics.py').write_bytes(b'changed')
    elif name=='python':
        f.runtime['files'][str(Path(sys.executable).resolve())]='f'*64
        f.installed.write_bytes(h.canonical_bytes(f.config))
    else:
        dep = f.output; dep.write_bytes(b'original'); f.runtime['files'][str(dep)] = h._file_hash(dep)
        f.installed.write_bytes(h.canonical_bytes(f.config)); dep.write_bytes(b'altered')
    monkeypatch.setattr(h,'_bounded_child',lambda *a,**kw:pytest.fail('must reject before launch'))
    with pytest.raises(h.HoldoutError,match='pinned_file_changed'): REAL_TRANSPORT(f.authority(),timeout=3)


def test_uninventoried_module_rejected_before_execution(transport):
    f = transport
    sentinel = f.root/'executed'
    (f.root/'rogue.py').write_text(f'from pathlib import Path;Path({str(sentinel)!r}).touch()')
    f.program_changed('import rogue\n'+(f.root/'app/backtesting/research/experiments.py').read_text())
    with pytest.raises(f.module.HoldoutError,match='unpinned_module'): REAL_TRANSPORT(f.authority(),timeout=3)
    assert not sentinel.exists()


@pytest.mark.parametrize('program,expected', [
    ('import os\nos.write(1,b"x"*(4*1024**2+1))','out_overflow'),
    ('import os\nos.write(2,b"x"*(2*1024**2+1))','err_overflow'),
    ('import time\ntime.sleep(10)','timeout'),
    ('raise RuntimeError("synthetic_exit")','exit:1')])
def test_noisy_hung_nonzero_owned_verifier_bounded(transport, program, expected):
    f = transport; f.program_changed(program)
    with pytest.raises(f.module.HoldoutError,match=expected): REAL_TRANSPORT(f.authority(),timeout=.5)


def test_cleanup_stops_owned_group_after_leader_exits(tmp_path,monkeypatch):
    h=h_module; spawned=[]; stopped=[]
    popen=h.subprocess.Popen; killpg=os.killpg
    def own(*args,**kwargs):
        child=popen(*args,**kwargs); spawned.append(child); return child
    def stop(pid,sig): stopped.append(pid); return killpg(pid,sig)
    monkeypatch.setattr(h.subprocess,'Popen',own); monkeypatch.setattr(os,'killpg',stop)
    try:
        with pytest.raises(h.HoldoutError,match='timeout'):
            h._bounded_child([str(Path(sys.executable).resolve()),'-I','-S','-B','-c',
                'import os,time;pid=os.fork();time.sleep(10) if pid==0 else os._exit(0)'],
                cwd=tmp_path,env={},stdin=b'',timeout=.25)
        assert stopped==[spawned[0].pid]
    finally:
        # Even RED must clean up exactly the synthetic group created by this test.
        if spawned:
            try: killpg(spawned[0].pid,9)
            except ProcessLookupError: pass


def test_post_child_pinned_mutation_denied(transport,monkeypatch):
    f = transport; h = f.module
    def child(*a,**kw):
        (f.root/'app/backtesting/research/statistics.py').write_bytes(b'mutated')
        return b'{}\n'
    monkeypatch.setattr(h,'_bounded_child',child)
    with pytest.raises(h.HoldoutError,match='pinned_file_changed'): REAL_TRANSPORT(f.authority(),timeout=3)


def test_two_processes_one_durable_claim(fixture):
    import multiprocessing
    f = fixture; context = multiprocessing.get_context('fork')
    start = context.Event(); queue = context.Queue()
    def run(output):
        start.wait()
        try:
            f.module.prepare_claim(f.authority(),output)
            queue.put('winner')
        except f.module.HoldoutError as exc: queue.put(str(exc))
    children = [context.Process(target=run,args=(p,)) for p in (f.output,f.other_output)]
    for child in children: child.start()
    start.set()
    answers = [queue.get(timeout=10) for _ in children]
    for child in children: child.join(timeout=10); assert child.exitcode == 0
    assert answers.count('winner') == 1
    assert any(x in ('already_claimed','claim_writer_busy') for x in answers)


@pytest.mark.parametrize('target,when', [('contract.json','before_link'),('contract.json','after_link'),
    ('claim.json','before_link'),('claim.json','after_link')])
def test_publication_faults_retain_ambiguous_or_consumed_state(fixture,monkeypatch,target,when):
    f = fixture; h = f.module; link = os.link
    def injected(source,destination,**kwargs):
        if Path(destination).name==target and when=='before_link': raise OSError('crash_before')
        result = link(source,destination,**kwargs)
        if Path(destination).name==target and when=='after_link': raise OSError('crash_after')
        return result
    monkeypatch.setattr(os,'link',injected)
    with pytest.raises(OSError): f.claim()
    assert list(f.anchor.glob('.publish-*.tmp'))
    if target=='claim.json' and when=='after_link': assert (f.anchor/'claim.json').exists()
    with pytest.raises(h.HoldoutError,match='already_claimed|ambiguous'):
        h.prepare_claim(f.authority(),f.other_output)


@pytest.fixture
def retained(fixture,monkeypatch):
    f = fixture; h = f.module
    f.installed.unlink()
    root = Path(f.config['paths']['frozen_python_root'])
    frozen = {}
    for name in h.FROZEN_FILES:
        path = root/name; path.parent.mkdir(parents=True,exist_ok=True); path.write_bytes(b'')
        frozen[name] = h._file_hash(path)
    c = f.config['paths'] | {'registry_root':f.config['registry']}
    c['python'] = str(Path(sys.executable).resolve())
    evidence = f.installed.parent/'retained'; evidence.mkdir(mode=0o700)
    c['evidence_root'] = str(evidence)
    attempt = {'schema_version':'research-orchestration-attempt.v1','config':c}
    snapshot = {'files':{str(root/'app/backtesting/research/experiments.py'):frozen['app/backtesting/research/experiments.py'],
        c['python']:h._file_hash(Path(c['python']))},'runner_code':{},'php':{}}
    def write(path,value): path.write_bytes(h.canonical_bytes(value)); path.chmod(0o600)
    f.attempt = evidence/'attempt.json'; f.snapshot = evidence/'input-code-identity.json'; f.terminal = evidence/'terminal.json'
    write(f.attempt,attempt); write(f.snapshot,snapshot)
    f.terminal_value = {'state':'selected','attempt_sha256':h._file_hash(f.attempt),
        'input_code_identity_sha256':h._file_hash(f.snapshot)}
    write(f.terminal,f.terminal_value)
    monkeypatch.setattr(h,'_trusted_repository_inventory',lambda:frozen)
    runtime = {'files':{c['python']:h._file_hash(Path(c['python']))},'dependency_roots':[],'python_version':'synthetic'}
    monkeypatch.setattr(h,'_runtime_inventory',lambda *a:runtime)
    f.write = write; f.runtime = runtime; f.original_python = c['python']; f.original_digest = snapshot['files'][c['python']]
    return f


def test_trusted_install_derives_paths_never_replaces(retained):
    f = retained; h = f.module
    a = h.install_campaign_authority(f.attempt,f.snapshot)
    assert a.registry == Path(f.config['registry'])
    assert a.frozen_python_root == Path(f.config['paths']['frozen_python_root'])
    assert a.python == Path(f.original_python)
    assert json.loads(a.config_bytes)['original_runtime']['historical_dependency_identity'] == 'unavailable'
    with pytest.raises(h.HoldoutError,match='already_installed'): h.install_campaign_authority(f.attempt,f.snapshot)


@pytest.mark.parametrize('mode', ['missing','nonterminal','hash','relationship','frozen','python'])
def test_install_requires_authentic_terminal_and_frozen_identity(retained,mode):
    f = retained; h = f.module
    if mode=='missing': f.terminal.unlink()
    elif mode=='nonterminal': f.terminal_value['state']='running'; f.write(f.terminal,f.terminal_value)
    elif mode=='hash': f.terminal_value['input_code_identity_sha256']='a'*64; f.write(f.terminal,f.terminal_value)
    elif mode=='frozen':
        (Path(f.config['paths']['frozen_python_root'])/'app/__init__.py').write_bytes(b'altered')
    elif mode=='python':
        value = json.loads(f.snapshot.read_bytes()); value['files'][f.original_python] = 'a'*64
        f.write(f.snapshot,value); f.terminal_value['input_code_identity_sha256']=h._file_hash(f.snapshot); f.write(f.terminal,f.terminal_value)
    if mode=='relationship':
        with pytest.raises(h.HoldoutError): h.install_campaign_authority(f.attempt,f.snapshot.with_name('other.json'))
    else:
        with pytest.raises((h.HoldoutError,FileNotFoundError)): h.install_campaign_authority(f.attempt,f.snapshot)
    assert not f.installed.exists()


def test_explicit_reconstruction_record_preserves_original_identity(retained):
    f = retained; h = f.module
    recovery = {'schema_version':'research-runtime-recovery.v1','original_python':f.original_python,
        'original_python_sha256':f.original_digest,'historical_dependency_identity':'unavailable',
        'reconstructed':True,'reason':'Original dependency environment lost in synthetic reboot',
        'python':f.original_python,'runtime_inventory':f.runtime}
    path = f.installed.parent/'recovery.json'; f.write(path,recovery)
    a = h.install_campaign_authority(f.attempt,f.snapshot,path)
    record = json.loads(a.config_bytes)
    assert record['recovery']['reconstructed'] is True
    assert record['original_runtime']['sha256'] == f.original_digest


@pytest.mark.parametrize('change', [{'historical_dependency_identity':'identical'}, {'reconstructed':False},
    {'reason':''}, {'original_python_sha256':'f'*64}, {'runtime_inventory':{}}])
def test_reconstruction_cannot_claim_lost_dependency_identity(retained,change):
    f = retained; h = f.module
    value = {'schema_version':'research-runtime-recovery.v1','original_python':f.original_python,
        'original_python_sha256':f.original_digest,'historical_dependency_identity':'unavailable',
        'reconstructed':True,'reason':'Lost synthetic environment','python':f.original_python,'runtime_inventory':f.runtime}
    value.update(change); path = f.installed.parent/'recovery.json'; f.write(path,value)
    with pytest.raises(h.HoldoutError): h.install_campaign_authority(f.attempt,f.snapshot,path)


def test_runtime_inventory_pins_dependency_trees_metadata_without_site(tmp_path,monkeypatch):
    h = h_module
    version = f'python{sys.version_info.major}.{sys.version_info.minor}'
    root = tmp_path/'venv/lib'/version/'site-packages'; root.mkdir(parents=True)
    python = Path(sys.executable).resolve()
    for name in ('pydantic','pydantic_core','annotated_types','typing_extensions','typing_inspection'):
        package = root/name; package.mkdir(); (package/'__init__.py').write_text('')
        (package/'__pycache__').mkdir(); (package/'__pycache__/cache.pyc').write_bytes(b'ignored')
        metadata = root/(name+'-1.0.dist-info'); metadata.mkdir(); (metadata/'METADATA').write_text('Version: 1.0\n')
    poison = tmp_path/'executed'
    (root/'sitecustomize.py').write_text(f'from pathlib import Path;Path({str(poison)!r}).touch()')
    (root/'poison.pth').write_text(f'import pathlib;pathlib.Path({str(poison)!r}).touch()')
    result = h._runtime_inventory(python,tmp_path/'venv/bin/python')
    assert result['versions']['pydantic'] == '1.0'
    assert len(result['files']) == 11
    assert not poison.exists()
    assert not any('pyc' in p for p in result['files'])
    (root/'pydantic/new.py').write_text('new')
    assert h._runtime_inventory(python,tmp_path/'venv/bin/python') != result
    monkeypatch.setattr(h,'INVENTORY_ENTRIES',1)
    with pytest.raises(h.HoldoutError,match='inventory_limit'): h._runtime_inventory(python,tmp_path/'venv/bin/python')


def test_claim_lock_contention_is_bounded(fixture,monkeypatch):
    f = fixture; h = f.module
    import fcntl
    def busy(fd,flags):
        assert flags & fcntl.LOCK_NB
        raise BlockingIOError('another owned claimant')
    monkeypatch.setattr(fcntl,'flock',busy)
    with pytest.raises(h.HoldoutError,match='claim_writer_busy'): f.claim()
    assert not (f.anchor/'claim.json').exists()


def test_fixed_bootstrap_accepts_actual_pydantic_closure(transport,monkeypatch):
    f = transport; h = f.module
    runtime = REAL_RUNTIME_INVENTORY(Path(sys.executable).resolve(),Path(sys.executable))
    # The transport fixture inventories synthetic repository code. This one
    # module exercises the genuine external dependency imports of the old lane.
    modern = f.root/'app/modern_trading_contracts.py'
    modern.write_bytes((Path(h.__file__).parents[2]/'modern_trading_contracts.py').read_bytes())
    f.files['app/modern_trading_contracts.py'] = h._file_hash(modern)
    f.program_changed('import app.modern_trading_contracts\n'+(f.root/'app/backtesting/research/experiments.py').read_text())
    f.config['runtime'] = runtime
    f.config['original_runtime']['python'] = str(Path(sys.executable))
    f.installed.write_bytes(h.canonical_bytes(f.config))
    monkeypatch.setattr(h,'_runtime_inventory',lambda *a:runtime)
    assert REAL_TRANSPORT(f.authority(),timeout=5) == (Path(f.config['registry'])/'selection-freeze.json').read_bytes()


def test_actual_package_initializer_import_only_closure(tmp_path):
    h = h_module; root = tmp_path/'frozen'; root.mkdir()
    for name in h.FROZEN_FILES:
        path = root/name; path.parent.mkdir(parents=True,exist_ok=True)
        if name=='app/backtesting/research/experiments.py':
            path.write_text('from . import statistics,portfolio_simulator\n')
        elif name in ('app/backtesting/research/statistics.py','app/backtesting/research/portfolio_simulator.py'):
            path.write_text('')
        else: path.write_bytes((Path(h.__file__).parents[3]/name).read_bytes())
    runtime = REAL_RUNTIME_INVENTORY(Path(sys.executable).resolve(),Path(sys.executable))
    roots = [str(root),*runtime['dependency_roots']]
    code = f'''import sys,json,pathlib
sys.path[:0]={roots!r}
import app.backtesting.research.experiments
root=pathlib.Path({str(root)!r})
print(json.dumps(sorted({{str(pathlib.Path(m.__file__).relative_to(root)) for m in sys.modules.values() if getattr(m,'__file__',None) and pathlib.Path(m.__file__).is_relative_to(root)}})))
'''
    result = h._bounded_child([str(Path(sys.executable).resolve()),'-I','-S','-B','-c',code],
        cwd=root,env={},stdin=b'',timeout=5)
    assert json.loads(result) == sorted(h.FROZEN_FILES)
    assert not list(root.rglob('__pycache__'))


@pytest.mark.parametrize('change,expected', [
    ('python','original_python_changed'),('php_extra','php_inventory_changed'),
    ('baseline','baseline_identity_conflict'),('symbols','original_symbol_binding_conflict'),
    ('empty','baseline_binding_missing'),('identity','baseline_protocol_conflict'),
    ('instrument_hash','assumption_canonical_hash_conflict'),('instrument_identity','instrument_identity_conflict'),
    ('cost_identity','cost_identity_conflict')])
def test_metadata_preflight_denials(preflight,change,expected):
    f = preflight; h = f.module; protocol = f.real_protocol
    if change=='python': f.config['original_runtime']['sha256']='a'*64
    elif change=='php_extra': (Path(f.config['paths']['app_dir'])/'src/extra.php').write_bytes(b'new')
    elif change=='baseline':
        f.loaded_reports[0][1]['symbols']['BTCUSDT'] = {
            'baseline':{'different':True},'effective_config_snapshot':{'ordered_files':[]}}
    elif change=='symbols': f.loaded_reports[0][1]['symbols'].pop('BTCUSDT')
    elif change=='empty': protocol['phase_bindings']={}
    elif change=='identity': protocol['identity']['base_config_hash']='a'*64
    elif change=='instrument_hash': Path(f.config['paths']['instrument_path']).write_bytes(b'{}\n')
    elif change=='instrument_identity': protocol['identity']['instrument_assumptions_hash']='a'*64
    elif change=='cost_identity': protocol['identity']['baseline_cost_hash']='a'*64
    f.refresh_reports()
    f.installed.write_bytes(h.canonical_bytes(f.config))
    with pytest.raises(h.HoldoutError,match=expected): REAL_PREFLIGHT(f.authority(),protocol)


def test_metadata_preflight_explicit_recovery(preflight):
    f = preflight; f.config['recovery']={'reconstructed':True}
    f.installed.write_bytes(f.module.canonical_bytes(f.config))
    assert REAL_PREFLIGHT(f.authority(),f.real_protocol)['recovery']=={'reconstructed':True}


@pytest.mark.parametrize('change', ['bytes','symbols','code'])
def test_original_report_summary_is_verified_before_metadata(preflight,change):
    f=preflight; h=f.module; summary,loaded=f.loaded_reports[0]
    if change=='bytes': (Path(summary['root'])/'report.json').write_bytes(b'{}\n')
    elif change=='symbols': summary['symbols']=list(reversed(summary['symbols']))
    else: summary['code_sha256']={'wrong':'a'*64}
    with pytest.raises(h.HoldoutError,match='original_report_binding_conflict'):
        REAL_PREFLIGHT(f.authority(),f.real_protocol)


@pytest.mark.parametrize('kind', ['relative','parent','symlink','directory','oversize','malformed','nonobject'])
def test_bounded_json_and_path_denials(tmp_path,kind):
    h = h_module; path = tmp_path/'input.json'; path.write_bytes(b'{}\n')
    if kind=='relative': path=Path('input.json')
    elif kind=='parent': path=tmp_path/'..'/'input.json'
    elif kind=='symlink':
        target=path; path=tmp_path/'link'; path.symlink_to(target)
    elif kind=='directory': path=tmp_path
    elif kind=='oversize': path.write_bytes(b' '*100)
    elif kind=='malformed': path.write_bytes(b'{oops')
    elif kind=='nonobject': path.write_bytes(b'[]\n')
    with pytest.raises(h.HoldoutError): h._read_json(path,20)


def test_json_open_race_rejects_changed_file_type(tmp_path,monkeypatch):
    h = h_module; path = tmp_path/'input.json'; path.write_bytes(b'{}\n')
    original = os.open
    monkeypatch.setattr(os,'open',lambda p,flags:original(tmp_path,os.O_RDONLY))
    with pytest.raises(h.HoldoutError,match='regular_file'): h._read_json(path,20)


@pytest.mark.parametrize('change', [{'schema_version':'unknown'}, {'campaign_id':'different'},
    {'protocol_hash':'unknown'}, {'paths':{}}, {'runtime':None}])
def test_malformed_installed_authority_denied(fixture,change):
    f = fixture; f.config.update(change); f.installed.write_bytes(f.module.canonical_bytes(f.config))
    with pytest.raises(f.module.HoldoutError): f.module.load_campaign_authority()


@pytest.mark.parametrize('change', ['inventory','extra','repository','too_many','json_limit'])
def test_runtime_inventory_mismatch_denials(transport,monkeypatch,change):
    f = transport; h = f.module; authority = f.authority()
    if change=='inventory': authority = dataclasses.replace(authority,inventory_bytes=b'{}\n')
    elif change=='extra': monkeypatch.setattr(h,'_runtime_inventory',lambda *a:{'different':True})
    elif change=='repository': monkeypatch.setattr(h,'_trusted_repository_inventory',lambda:{})
    elif change=='too_many': monkeypatch.setattr(h,'INVENTORY_ENTRIES',0)
    elif change=='json_limit': monkeypatch.setattr(h,'INVENTORY_JSON_BYTES',1)
    with pytest.raises(h.HoldoutError): REAL_VERIFY_RUNTIME(authority)


@pytest.mark.parametrize('change', ['output_exists','relative','overlap','metadata','disk','deadline','lower_budget'])
def test_preclaim_capacity_and_output_denials(fixture,monkeypatch,change):
    f = fixture; h = f.module; output = f.output; kwargs={}
    if change=='output_exists': output.mkdir()
    elif change=='relative': output=Path('output')
    elif change=='overlap': output=Path(f.config['paths']['dataset_root'])/'nested'
    elif change=='metadata': monkeypatch.setattr(h,'METADATA_BYTES',1)
    elif change=='disk': monkeypatch.setattr(h.shutil,'disk_usage',lambda p:type('Disk',(),{'free':0})())
    elif change=='deadline':
        clock = iter([0,1,2]); monkeypatch.setattr(h.time,'monotonic',lambda:next(clock)); kwargs={'timeout':.5}
    elif change=='lower_budget': kwargs={'max_output_bytes':h.TERMINAL_RESERVE+1}
    with pytest.raises(h.HoldoutError): h.prepare_claim(f.authority(),output,**kwargs)
    assert not (f.anchor/'claim.json').exists()


@pytest.mark.parametrize('kwargs', [
    {'operation':'unknown'}, {'operation':'signals','source_start':'wrong'},
    {'operation':'signals','score_start':'wrong'}, {'operation':'signals','end':'wrong'},
    {'operation':'signals','variant_id':'unknown','cost_profile':'adverse'},
    {'operation':'simulate','variant_id':'baseline','cost_profile':'adverse'},
    {'operation':'signals','output_root':Path('/wrong')}])
def test_exact_claim_scope_denials(fixture,kwargs):
    f = fixture; auth=f.claim()
    if kwargs['operation']=='simulate': auth=auth.for_slot('baseline','baseline')
    with pytest.raises(f.module.HoldoutError): f.module.require_claim(auth,**kwargs)


def test_exact_scope_accepts_paths_and_returns_fresh_copy(fixture):
    f=fixture; auth=f.claim(); h=f.module
    result=h.require_claim(auth,operation='signals',symbols=h.SYMBOLS,source_start=h.SOURCE_START,
        score_start=h.SCORE_START,end=h.END,output_root=f.output/'signals')
    result['window']['end']='altered'
    assert h.require_claim(auth,operation='report')['window']['end']==h.END
    h.require_claim(auth,operation='binding',variant_id='baseline',cost_profile='baseline',output_root=f.output)
    slot=auth.for_slot('baseline','baseline')
    h.require_claim(slot,operation='verify',variant_id='baseline',cost_profile='baseline',
        output_root=f.output/'units/baseline/baseline')


@pytest.mark.parametrize('artifact', ['authority.json','claim.json','installed','protocol','freeze'])
def test_changed_retained_authority_and_evidence_denied(fixture,artifact):
    f=fixture; auth=f.claim(); h=f.module
    path = (f.installed if artifact=='installed' else Path(f.config['registry'])/('protocol.json' if artifact=='protocol' else 'selection-freeze.json')
        if artifact in ('protocol','freeze') else f.anchor/artifact)
    value=json.loads(path.read_bytes()); value['changed']=True; path.write_bytes(h.canonical_bytes(value))
    with pytest.raises(h.HoldoutError): h.require_claim(auth,operation='signals')


def test_retained_replay_changed_identity_denied(fixture,monkeypatch):
    f=fixture; f.claim(); h=f.module
    monkeypatch.setattr(h,'_preflight_identities',lambda *a:{'changed':True})
    with pytest.raises(h.HoldoutError,match='retained_input_identity_changed'): h.mint_retained_authorization(f.authority())


@pytest.mark.parametrize('field', ['window','slots','risk_assumptions','limits'])
def test_retained_replay_rejects_consistently_rewritten_scope(fixture,field):
    f=fixture; f.claim(); h=f.module
    path=f.anchor/'contract.json'; contract=json.loads(path.read_bytes())
    if field=='window': contract['window']['end']='2099-01-01T00:00:00Z'
    elif field=='slots': contract['slots'][0]['variant_id']='unknown'
    elif field=='risk_assumptions': contract['risk_assumptions']['maximum_leverage']='999'
    elif field=='limits': contract['limits']['overall_bytes']=h.OVERALL_BYTES+1
    raw=h.canonical_bytes(contract); path.write_bytes(raw)
    claim_path=f.anchor/'claim.json'; claim=json.loads(claim_path.read_bytes())
    claim['contract_hash']=h.hash_bytes(raw); claim_path.write_bytes(h.canonical_bytes(claim))
    with pytest.raises(h.HoldoutError,match='contract_scope'):
        h.mint_retained_authorization(f.authority())


@pytest.mark.parametrize('valid', [True,False])
def test_preexisting_anchor_identity_checked(fixture,valid):
    f=fixture; h=f.module; f.anchor.mkdir(mode=0o700)
    value={'campaign_id':h.CAMPAIGN_ID,'registry':f.config['registry'],'protocol_hash':f.config['protocol_hash']}
    if not valid: value['registry']='wrong'
    path=f.anchor/'authority.json'; path.write_bytes(h.canonical_bytes(value)); path.chmod(0o600)
    if valid: f.claim()
    else:
        with pytest.raises(h.HoldoutError,match='anchor_authority_conflict'): f.claim()


def test_genuine_new_inventory_requires_complete_controller(tmp_path,monkeypatch):
    h=h_module; root=tmp_path/'app/backtesting/research'; root.mkdir(parents=True)
    fake=root/'holdout_authority.py'; fake.write_text('')
    monkeypatch.setattr(h,'__file__',str(fake))
    with pytest.raises(FileNotFoundError): h._new_code_inventory()
    names = ('holdout.py','holdout_authority.py','frozen_verifier_inventory.json','signals.py','signal_sources.py',
        'campaign.py','campaign_evidence.py','plans.py','portfolio_simulator.py','statistics.py','funding.py',
        'binance_history.py','source_complements.py','orchestration.py','experiments.py')
    for name in names:
        (root/name).write_text('')
    (root.parents[1]/'modern_trading_contracts.py').write_text('')
    inventory = h._new_code_inventory()
    assert len(inventory)==16
    assert set(inventory)=={str(root/name) for name in names} | {str(root.parents[1]/'modern_trading_contracts.py')}


def test_trusted_inventory_schema_denials(monkeypatch):
    h=h_module; assert set(h._trusted_repository_inventory())==set(h.FROZEN_FILES)
    monkeypatch.setattr(h,'_read_json',lambda *a:({'commit':'wrong','files':{}},b''))
    with pytest.raises(h.HoldoutError,match='inventory_schema'): h._trusted_repository_inventory()
