"""Synthetic orchestration only; no historical processes or observations."""
import json
from pathlib import Path
import pytest

from app.backtesting.research import orchestration as o


def report(root, phase, group):
    root.mkdir(mode=0o700, exist_ok=True)
    value = dict(schema='research-signal-run.v1', status='complete', errors=[],
        symbols={s: {} for s in group}, code_sha256={'synthetic': 'a'*64})
    value.update(zip(('source_start', 'score_start', 'source_end'), o.WINDOWS[phase]))
    (root/'report.json').write_text(json.dumps(value))


class Clock:
    value = 0
    def __call__(self): return self.value
    def sleep(self, value): self.value += value


class Child:
    def __init__(self, code=0): self.code, self.stopped = code, False
    def poll(self): return self.code


class Stages:
    def __init__(self): self.events = []
    def snapshot(self, config): return {'synthetic': 'identity'}
    def protocol(self, config, deadline):
        self.events.append('protocol')
        return {'synthetic': 'protocol'}
    def execute(self, config, protocol, remaining):
        self.events.append('freeze_then_schedule')
        remaining()
        return {'selection_state': 'no_eligible_candidate', 'reports': {'json': 'synthetic-report'}}


@pytest.fixture
def setup(tmp_path):
    sources = []
    for name in ('dataset', 'app', 'b1', 'train0', 'train1'):
        path = tmp_path/name; path.mkdir(mode=0o700); sources.append(path)
    instrument, costs, python = (tmp_path/n for n in ('instrument.json', 'cost.json', 'python'))
    for path in (instrument, costs, python): path.write_text('{}')
    config = o.Config(sources[0], sources[1], sources[2], python,
        (sources[3], sources[4]), (tmp_path/'val0', tmp_path/'val1'),
        instrument, costs, tmp_path/'registry', tmp_path/'reports', tmp_path/'operations',
        timeout=90, poll_interval=10)
    clock, stages, calls = Clock(), Stages(), []
    def popen(argv, **kwargs):
        calls.append((argv, kwargs))
        index = len(calls)-1
        report(config.validation_roots[index], 'validation', o.GROUPS[index])
        return Child()
    for root, group in zip(config.training_roots, o.GROUPS): report(root, 'training', group)
    return config, clock, stages, calls, popen


def run(setup, **kwargs):
    c, clock, stages, calls, popen = setup
    return o.run(c, clock=clock, sleep=clock.sleep, stages=stages, popen=popen,
                 stop=lambda child: setattr(child, 'stopped', True), **kwargs)


def test_wait_launch_two_frozen_sanitized_then_freeze(setup, monkeypatch):
    c, clock, stages, calls, _ = setup
    monkeypatch.setenv('SECRET_TOKEN', 'must-never-leak')
    result = run(setup)
    assert result['state'] == 'no_eligible_candidate'
    assert len(calls) == 2
    assert all(k['cwd'] == c.b1_cwd and 'SECRET_TOKEN' not in k['env'] for _, k in calls)
    assert all('--end' in argv and argv[argv.index('--end')+1] == o.WINDOWS['validation'][2] for argv, _ in calls)
    assert stages.events == ['protocol', 'freeze_then_schedule']
    assert (c.evidence_root/'attempt.json').stat().st_mode & 0o777 == 0o600
    assert (c.evidence_root/'terminal.json').is_file()
    assert result['attempt_sha256'] == o.signals._file_sha256(c.evidence_root/'attempt.json')
    assert result['input_code_identity_sha256'] == o.signals._file_sha256(c.evidence_root/'input-code-identity.json')


def test_missing_training_waits_until_deadline_and_retains_failure(setup):
    c, _, _, calls, _ = setup
    (c.training_roots[0]/'report.json').unlink()
    with pytest.raises(o.OrchestrationError, match='deadline'): run(setup)
    assert calls == []
    assert json.loads((c.evidence_root/'terminal.json').read_text())['state'] == 'failed'


@pytest.mark.parametrize('change', [{'status': 'failed'}, {'errors': ['failure']},
    {'source_end': '2026-01-01T00:00:00Z'}, {'symbols': {'BTCUSDT': {}}}, {'schema': 'wrong'}])
def test_malformed_or_stale_training_fatal_without_launch(setup, change):
    c, _, _, calls, _ = setup
    path = c.training_roots[0]/'report.json'; data = json.loads(path.read_text()); data.update(change)
    path.write_text(json.dumps(data))
    with pytest.raises(o.OrchestrationError, match='report'): run(setup)
    assert not calls


def test_existing_output_refused_before_attempt(setup):
    c, _, _, calls, _ = setup
    c.validation_roots[1].mkdir()
    with pytest.raises(o.OrchestrationError, match='fresh'): run(setup)
    assert not c.evidence_root.exists() and not calls


def test_pending_report_becomes_complete(setup):
    c, clock, _, calls, _ = setup
    (c.training_roots[0]/'report.json').unlink()
    def sleep(seconds):
        clock.sleep(seconds)
        report(c.training_roots[0], 'training', o.GROUPS[0])
    result = o.run(c, clock=clock, sleep=sleep, popen=setup[4], stages=setup[2], stop=lambda p: None)
    assert result['elapsed_seconds'] == 10 and len(calls) == 2


@pytest.mark.parametrize('mode', ['failed', 'pending', 'missing', 'launch', 'interrupt'])
def test_owned_child_failure_timeout_missing_and_interrupt(setup, mode):
    c, clock, stages, _, _ = setup
    children = []
    def popen(argv, **kwargs):
        if mode == 'launch' and children:
            raise OSError('synthetic launch failed')
        if mode == 'interrupt' and children:
            raise KeyboardInterrupt()
        child = Child(1 if mode == 'failed' else None if mode in ('pending', 'launch', 'interrupt') else 0)
        children.append(child)
        return child
    with pytest.raises((o.OrchestrationError, OSError, KeyboardInterrupt)):
        o.run(c, clock=clock, sleep=clock.sleep, popen=popen, stages=stages,
              stop=lambda child: setattr(child, 'stopped', True))
    assert children and all(child.stopped for child in children)
    assert stages.events == []
    assert json.loads((c.evidence_root/'terminal.json').read_text())['state'] == 'failed'


@pytest.mark.parametrize('when', ['before', 'finish', 'report'])
def test_frozen_mutation_rejected(setup, when):
    c, _, stages, _, _ = setup
    count = 0
    def snapshot(config):
        nonlocal count
        count += 1
        return {'identity': count if when == 'before' or when == 'finish' and count >= 5 else 1}
    stages.snapshot = snapshot
    if when == 'report':
        original = stages.execute
        def execute(config, protocol, remaining):
            result = original(config, protocol, remaining)
            (c.training_roots[0]/'report.json').write_text('{}')
            return result
        stages.execute = execute
    with pytest.raises(o.OrchestrationError, match='changed'): run(setup)


def test_selected_label_still_holdout_pending(setup):
    setup[2].execute = lambda c, p, remaining: {'selection_state': 'selected', 'reports': {}}
    result = run(setup)
    assert result['state'] == 'selected' and result['holdout_status'] == 'pending'


@pytest.mark.parametrize('field,value', [('timeout', 0), ('timeout', float('inf')),
    ('poll_interval', 31), ('unit_timeout', -1), ('unit_bytes', 0), ('aggregate_bytes', 1),
    ('training_roots', ()), ('validation_roots', ()), ('dataset_root', Path('relative'))])
def test_invalid_config_rejected(setup, field, value):
    from dataclasses import replace
    with pytest.raises(o.OrchestrationError): o._validate(replace(setup[0], **{field:value}))


def test_missing_symlink_and_overlap_inputs(setup):
    from dataclasses import replace
    c = setup[0]
    with pytest.raises(o.OrchestrationError, match='missing'):
        o._validate(replace(c, cost_path=c.cost_path.parent/'missing'))
    link = c.cost_path.parent/'link'; link.symlink_to(c.cost_path)
    with pytest.raises(o.OrchestrationError, match='symlink'):
        o._validate(replace(c, cost_path=link))
    with pytest.raises(o.OrchestrationError, match='overlapping'):
        o._validate(replace(c, validation_roots=(c.dataset_root/'inside', c.validation_roots[1])))
    with pytest.raises(o.OrchestrationError, match='fresh'):
        o._validate(replace(c, report_root=c.report_root/'missingparent'/'out'))
    supplement = c.cost_path.parent/'supplement'; supplement.mkdir()
    o._validate(replace(c, funding_supplement_root=supplement))


def test_malformed_report_and_unsafe_inventory(setup):
    c = setup[0]
    path = c.training_roots[0]/'report.json'; path.write_text('not-json')
    with pytest.raises(o.OrchestrationError, match='malformed'): o._report(path.parent, 'training', o.GROUPS[0])
    assert o._size(c.training_roots[0]) == len('not-json')
    (c.training_roots[0]/'link').symlink_to(path)
    with pytest.raises(o.OrchestrationError, match='unsafe'): o._size(c.training_roots[0])


def test_stop_only_owned_group_and_escalates(monkeypatch):
    calls, clock = [], Clock()
    child = Child(0); child.pid = 123456  # Leader exited; descendant ignores TERM.
    def killpg(pid, sig):
        calls.append((pid, sig))
        if any(s == o.signal.SIGKILL for _, s in calls[:-1]): raise ProcessLookupError()
    monkeypatch.setattr(o.os, 'killpg', killpg)
    o._stop(child, clock=clock, sleep=clock.sleep)
    assert (child.pid, o.signal.SIGTERM) in calls
    assert (child.pid, o.signal.SIGKILL) in calls
    assert all(pid == child.pid for pid, _ in calls)
    assert 5 <= clock.value <= 10


def test_stop_handles_child_exit_race_and_graceful_exit(monkeypatch):
    child = Child(None); child.pid = 123456; calls = []
    def exited(pid, sig):
        calls.append(sig)
        if sig == 0: raise ProcessLookupError()
    monkeypatch.setattr(o.os, 'killpg', exited)
    o._stop(child)
    def gone(*args): raise ProcessLookupError()
    monkeypatch.setattr(o.os, 'killpg', gone)
    o._stop(child)
    assert calls == [o.signal.SIGTERM, 0]


def test_stop_group_that_survives_kill_is_bounded_failure(monkeypatch):
    child = Child(0); child.pid = 123456; clock = Clock()
    monkeypatch.setattr(o.os, 'killpg', lambda *args: None)
    with pytest.raises(o.OrchestrationError, match='group'):
        o._stop(child, clock=clock, sleep=clock.sleep)
    assert 10 <= clock.value < 11


@pytest.mark.parametrize('stage', ['protocol', 'replay', 'freeze', 'report'])
def test_real_deadline_interrupts_production_stages_and_retains_failure(production, setup, monkeypatch, stage):
    from dataclasses import replace
    import time
    c = replace(production[0], timeout=.05)
    stages = o.Stages()
    stages.snapshot = lambda config: {'synthetic':'identity'}
    events = []
    def delay(label):
        events.append(label)
        if stage == label: time.sleep(.2)
    if stage == 'protocol':
        original = o.FundingReader
        def funding(*args, **kwargs):
            delay('protocol')
            return original(*args, **kwargs)
        monkeypatch.setattr(o, 'FundingReader', funding)
    class Registry:
        def __init__(self, root, protocol, **kwargs): self.root = root
        def __enter__(self): return self
        def __exit__(self, *args): events.append('closed')
    monkeypatch.setattr(o.e, 'ExperimentRegistry', Registry)
    # Emulate C2's Exception handler: deadline must escape it and stop execution.
    def schedule(*args):
        try: delay('replay')
        except Exception: events.append('wrongly_swallowed')
        return []
    monkeypatch.setattr(o.e, 'schedule_batch', schedule)
    monkeypatch.setattr(o.e, 'freeze_selection', lambda *args: delay('freeze') or {'selection_state':'no_eligible_candidate'})
    monkeypatch.setattr(o.e, 'write_reports', lambda *args: delay('report') or {})
    started = time.monotonic()
    with pytest.raises(o.DeadlineExpired):
        o.run(c, stages=stages, popen=setup[4], stop=lambda child:None)
    assert time.monotonic()-started < .15
    terminal = json.loads((c.evidence_root/'terminal.json').read_text())
    assert terminal['state'] == 'failed' and terminal['error_type'] == 'DeadlineExpired'
    assert 'wrongly_swallowed' not in events
    if stage != 'protocol': assert events[-1] == 'closed'
    assert not c.report_root.exists()


def test_deadline_guard_restores_signal_handler_and_existing_timer():
    import time
    prior_handler = o.signal.getsignal(o.signal.SIGALRM)
    prior_timer = o.signal.getitimer(o.signal.ITIMER_REAL)
    custom = lambda signum, frame: None
    try:
        o.signal.signal(o.signal.SIGALRM, custom)
        o.signal.setitimer(o.signal.ITIMER_REAL, 30, 10)
        with o._wall_guard(.2): time.sleep(.01)
        assert o.signal.getsignal(o.signal.SIGALRM) is custom
        delay, interval = o.signal.getitimer(o.signal.ITIMER_REAL)
        assert 29 < delay < 30 and interval == 10
    finally:
        o.signal.setitimer(o.signal.ITIMER_REAL, 0)
        o.signal.signal(o.signal.SIGALRM, prior_handler)
        o.signal.setitimer(o.signal.ITIMER_REAL, *prior_timer)


def test_deadline_guard_rejects_nonmain_thread_without_changing_timer():
    import threading
    errors = []
    prior_handler = o.signal.getsignal(o.signal.SIGALRM)
    prior_timer = o.signal.getitimer(o.signal.ITIMER_REAL)
    def work():
        try:
            with o._wall_guard(.1): pass
        except BaseException as exc: errors.append(exc)
    thread = threading.Thread(target=work); thread.start(); thread.join(timeout=1)
    assert not thread.is_alive()
    assert isinstance(errors[0], o.OrchestrationError)
    assert o.signal.getsignal(o.signal.SIGALRM) == prior_handler
    assert o.signal.getitimer(o.signal.ITIMER_REAL) == prior_timer


@pytest.fixture
def production(setup, monkeypatch):
    """Real protocol glue with tiny synthetic verified C1 inputs."""
    from types import SimpleNamespace
    c = setup[0]
    baseline = {k:'sha256:'+'a'*64 for k in ('setup_hash', 'config_hash', 'condition_catalog_hash', 'snapshot_hash')}
    codemap = {}
    for codepath in o._b1_files(c):
        codepath.parent.mkdir(parents=True, exist_ok=True)
        codepath.write_text('synthetic B1')
        codemap[str(codepath)] = o.signals._file_sha256(codepath)
    instruments = {'symbols': {'synthetic': 1}}
    instruments['manifest_hash'] = o.canonical_hash(instruments)
    costs = {'profiles': {'baseline': {'spread':1}, 'adverse': {'spread':2}}}
    costs['assumption_hash'] = o.canonical_hash(costs)
    c.instrument_path.write_bytes(o.e.canonical_bytes(instruments))
    c.cost_path.write_bytes(o.e.canonical_bytes(costs))
    def verify(dataset, roots, app, symbols, phase, deadline):
        bindings = {s:(None, None, {'baseline':dict(baseline)}, None, {'symbol':s, 'phase':phase}) for s in symbols}
        return [{'code_sha256':dict(codemap), 'report_sha256':'raw-report'}], bindings, o.WINDOWS[phase], {}
    monkeypatch.setattr(o.campaign, '_verify_reports', verify)
    monkeypatch.setattr(o.campaign, '_runner_code', lambda: {'campaign':'b'*64})
    monkeypatch.setattr(o.plans, 'code_inventory', lambda app: {'php':'c'*64})
    funding_calls = []
    def reader(dataset, start, end, **kwargs):
        funding_calls.append((start, end, kwargs))
        return SimpleNamespace(inventory=SimpleNamespace(inventory_hash='sha256:'+'d'*64))
    monkeypatch.setattr(o, 'FundingReader', reader)
    return c, baseline, codemap, verify, funding_calls


def test_exact_protocol_sources_original_code_funding_identity(production):
    c, baseline, code, _, calls = production
    protocol = o.Stages().protocol(c, 90)
    phases = protocol['phase_bindings']
    assert list(phases) == ['training', 'validation']
    assert all([r['symbol'] for r in b['source_runs']] == list(o.SYMBOLS) for b in phases.values())
    assert protocol['identity']['dataset_hash'] == o.e.hash_bytes(o.e.canonical_bytes({p:b['source_runs'] for p,b in phases.items()}))
    assert protocol['identity']['signal_code_hash'] == o.canonical_hash(code)
    assert protocol['identity']['base_catalog_hash'] == baseline['condition_catalog_hash']
    assert all(b['funding_diagnostic_policy'] == o.campaign.FUNDING_DIAGNOSTIC_POLICY for b in phases.values())
    assert all(b['funding_inventory_hash'] == 'sha256:'+'d'*64 for b in phases.values())
    assert [call[:2] for call in calls] == [o.WINDOWS[p][1:] for p in phases]
    assert all(call[2]['rest_interval_hypothesis'] == o.REST_HYPOTHESIS for call in calls)


@pytest.mark.parametrize('mode', ['cost', 'windows', 'code', 'baseline', 'original'])
def test_protocol_identity_conflicts(production, monkeypatch, mode):
    c, _, code, verify, _ = production
    if mode == 'cost': c.cost_path.write_text('{"mutation":1}')
    if mode == 'original': Path(next(iter(code))).write_text('mutation')
    def altered(*args):
        reports, bindings, windows, current = verify(*args)
        if mode == 'windows': windows = ('2023-01-01T00:00:00Z', *windows[1:])
        if args[4] == 'validation':
            if mode == 'code': reports[0]['code_sha256'] = {}
            if mode == 'baseline': bindings[o.SYMBOLS[0]][2]['baseline']['setup_hash'] = 'different'
        return reports, bindings, windows, current
    monkeypatch.setattr(o.campaign, '_verify_reports', altered)
    with pytest.raises(o.OrchestrationError): o.Stages().protocol(c, 90)


def test_production_snapshot_binds_code_inputs_and_optional_funding(setup, monkeypatch):
    from dataclasses import replace
    c = setup[0]
    (c.dataset_root/'manifest.json').write_text('{}')
    folder = c.b1_cwd/'app/backtesting/research'; folder.mkdir(parents=True)
    for name in ('signals', 'signal_sources', 'binance_history'): (folder/f'{name}.py').write_text('synthetic')
    (c.b1_cwd/'app/modern_trading_contracts.py').write_text('synthetic')
    monkeypatch.setattr(o.campaign, '_runner_code', lambda: {})
    monkeypatch.setattr(o.plans, 'code_inventory', lambda app: {})
    snapshot = o.Stages().snapshot(c)
    assert str(c.python) in snapshot['files']
    supplement = c.dataset_root.parent/'supplement'; supplement.mkdir(); (supplement/'status.json').write_text('{}')
    assert str(supplement/'status.json') in o.Stages().snapshot(replace(c, funding_supplement_root=supplement))['files']


@pytest.mark.parametrize('provided_cap,provided_floor', [(None, None), (1024, o.e.RESERVE+8*1024**2),
    (8*1024**3, 0)])
def test_production_execute_freezes_before_runner_and_caps_remaining(setup, monkeypatch, provided_cap, provided_floor):
    c, clock, _, _, _ = setup
    events, allowances = [], []
    class Registry:
        def __init__(self, root, protocol, **kwargs): self.root = root; root.mkdir()
        def __enter__(self): events.append('protocol_frozen'); return self
        def __exit__(self, *args): pass
        def _budget(self, allowance): allowances.append(allowance)
    monkeypatch.setattr(o.e, 'ExperimentRegistry', Registry)
    def runner(**kwargs):
        events.append('runner'); assert kwargs['wall_timeout'] == 50
        assert kwargs['max_output_bytes'] == min(c.unit_bytes, provided_cap if provided_cap is not None else c.unit_bytes)
        assert kwargs['min_free_bytes'] == max(o.e.RESERVE, provided_floor if provided_floor is not None else o.e.RESERVE)
        assert kwargs['max_output_bytes'] <= c.aggregate_bytes-o._size(c.registry_root)
        assert kwargs['signal_roots'] == {'training':c.training_roots, 'validation':c.validation_roots}
        return lambda registration, output: events.append('simulation')
    monkeypatch.setattr(o.e, 'make_campaign_runner', runner)
    monkeypatch.setattr(o.e, 'schedule_batch', lambda registry, unit: (unit({}, registry.root/'unit',
        max_output_bytes=provided_cap, min_free_bytes=provided_floor), [])[1])
    monkeypatch.setattr(o.e, 'freeze_selection', lambda *args: {'selection_state':'no_eligible_candidate'})
    monkeypatch.setattr(o.e, 'write_reports', lambda *args: {'json': c.report_root/'report.json'})
    result = o.Stages().execute(c, {}, lambda:50)
    assert events == ['protocol_frozen', 'runner', 'simulation']
    assert allowances == [min(c.unit_bytes, provided_cap if provided_cap is not None else c.unit_bytes)]
    assert result['selection_state'] == 'no_eligible_candidate'


def test_aggregate_exhaustion_no_runner(setup, monkeypatch):
    c = setup[0]
    class Registry:
        def __init__(self, root, protocol, **kwargs): self.root = root
        def __enter__(self): return self
        def __exit__(self, *args): pass
    monkeypatch.setattr(o.e, 'ExperimentRegistry', Registry)
    monkeypatch.setattr(o, '_size', lambda path:c.aggregate_bytes)
    monkeypatch.setattr(o.e, 'schedule_batch', lambda registry, unit:unit({}, registry.root/'unit'))
    with pytest.raises(o.OrchestrationError, match='storage'): o.Stages().execute(c, {}, lambda:50)


def test_cli_exact_inputs_and_terminal_output(setup, monkeypatch, capsys):
    c = setup[0]; observed = []
    monkeypatch.setattr(o, 'run', lambda config: observed.append(config) or {'state':'selected', 'reports':{}})
    argv = []
    for name in ('dataset_root', 'app_dir', 'b1_cwd', 'python', 'instrument_path', 'cost_path',
                 'registry_root', 'report_root', 'evidence_root'):
        argv.extend(('--'+name.replace('_','-'), str(getattr(c, name))))
    for name in ('training_roots', 'validation_roots'):
        argv.extend(('--'+name.replace('_','-'), *map(str, getattr(c,name))))
    argv.extend(('--timeout', '90'))
    assert o.main(argv) == 0
    assert observed[0] == c
    assert json.loads(capsys.readouterr().out)['state'] == 'selected'


def test_freeze_snapshot_mutation_prevents_simulation(setup):
    stages = setup[2]
    original = stages.protocol
    def protocol(config, deadline):
        result = original(config, deadline)
        stages.snapshot = lambda c:{'changed':'during strict verification'}
        return result
    stages.protocol = protocol
    with pytest.raises(o.OrchestrationError, match='before protocol freeze'): run(setup)
    assert stages.events == ['protocol']


def test_cleanup_failure_keeps_terminal_and_attempt(setup):
    c, clock, stages, _, popen = setup
    def stop(child): raise OSError('synthetic cleanup failed')
    with pytest.raises(o.OrchestrationError, match='cleanup'):
        o.run(c, clock=clock, sleep=clock.sleep, stages=stages, popen=popen, stop=stop)
    terminal = json.loads((c.evidence_root/'terminal.json').read_text())
    assert terminal['state'] == 'failed' and len(terminal['cleanup_errors']) == 2


def test_snapshot_failure_retains_intended_attempt_before_any_launch(setup):
    c, _, stages, calls, _ = setup
    def snapshot(config): raise OSError('synthetic input vanished')
    stages.snapshot = snapshot
    with pytest.raises(OSError): run(setup)
    attempt = json.loads((c.evidence_root/'attempt.json').read_text())
    assert attempt['config']['dataset_root'] == str(c.dataset_root)
    assert attempt['orchestration_sha256'] == o.signals._file_sha256(Path(o.__file__))
    assert not calls and (c.evidence_root/'terminal.json').exists()
