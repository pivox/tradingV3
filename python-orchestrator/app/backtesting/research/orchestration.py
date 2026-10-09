"""Finite private supervisor for the approved training/validation batch only.

No historical work occurs on import. Existing training is observed, never
restarted or signaled. Only validation children created here can be terminated.
"""
from __future__ import annotations

import argparse
from contextlib import contextmanager
from dataclasses import dataclass, asdict
import math
import os
from pathlib import Path
import resource
import signal
import stat
import subprocess
import threading
import time

from . import campaign, experiments as e, plans, signals
from .funding import FundingReader, REST_HYPOTHESIS
from .portfolio_simulator import SYMBOLS, canonical_hash
from .signal_sources import _utc

GROUPS = (SYMBOLS[:5], SYMBOLS[5:])
WINDOWS = {'training': ('2022-11-01T00:00:00Z', '2023-01-01T00:00:00Z', '2025-01-01T00:00:00Z'),
           'validation': ('2024-11-01T00:00:00Z', '2025-01-01T00:00:00Z', '2026-01-01T00:00:00Z')}


class OrchestrationError(RuntimeError):
    """A finite stage or immutable identity boundary failed."""


class DeadlineExpired(BaseException):
    """Fatal deadline: C2's per-unit Exception handling must not continue."""


@contextmanager
def _wall_guard(seconds):
    """Main-thread POSIX stage timer; cleanup runs after signal state restores."""
    if threading.current_thread() is not threading.main_thread():
        raise OrchestrationError('finite POSIX supervisor requires the main thread')
    started = time.monotonic()
    old_timer = signal.setitimer(signal.ITIMER_REAL, 0)
    def expired(signum, frame):
        raise DeadlineExpired('overall orchestration deadline exceeded during stage')
    old_handler = signal.signal(signal.SIGALRM, expired)
    try:
        signal.setitimer(signal.ITIMER_REAL, seconds)
        yield
    finally:
        signal.setitimer(signal.ITIMER_REAL, 0)
        signal.signal(signal.SIGALRM, old_handler)
        delay, interval = old_timer
        if delay:
            delay = max(.000001, delay-(time.monotonic()-started))
        signal.setitimer(signal.ITIMER_REAL, delay, interval)


@dataclass(frozen=True)
class Config:
    dataset_root: Path
    app_dir: Path
    b1_cwd: Path
    python: Path
    training_roots: tuple[Path, Path]
    validation_roots: tuple[Path, Path]
    instrument_path: Path
    cost_path: Path
    registry_root: Path
    report_root: Path
    evidence_root: Path
    funding_supplement_root: Path | None = None
    timeout: float = 7*24*3600
    poll_interval: float = 10
    unit_timeout: float = 6*3600
    unit_bytes: int = 4*1024**3
    aggregate_bytes: int = 52*4*1024**3


def _validate(c):
    if (any(not math.isfinite(v) or v <= 0 for v in (c.timeout, c.poll_interval, c.unit_timeout))
        or c.poll_interval > 30 or type(c.unit_bytes) is not int or c.unit_bytes <= 0
        or type(c.aggregate_bytes) is not int or c.aggregate_bytes < 52*c.unit_bytes
        or len(c.training_roots) != 2 or len(c.validation_roots) != 2):
        raise OrchestrationError('invalid finite orchestration bounds')
    sources = (c.dataset_root, c.app_dir, c.b1_cwd, c.python, c.instrument_path,
               c.cost_path, *c.training_roots)
    if c.funding_supplement_root is not None:
        sources += (c.funding_supplement_root,)
    outputs = (*c.validation_roots, c.registry_root, c.report_root, c.evidence_root)
    for path in (*sources, *outputs):
        if not isinstance(path, Path) or not path.is_absolute() or '..' in path.parts:
            raise OrchestrationError('absolute explicit paths required')
        if any(p.is_symlink() for p in (*path.parents, *((path,) if path != c.python else ()))):
            raise OrchestrationError('symlink input or output path')
    if any(not path.exists() for path in sources):
        raise OrchestrationError('explicit input missing')
    for index, output in enumerate(outputs):
        e._safe_directory(output)
        if output.exists() or not output.parent.is_dir():
            raise OrchestrationError('fresh output with existing parent required')
        for other in (*sources, *outputs[:index]):
            if output == other or output in other.parents or other in output.parents:
                raise OrchestrationError('overlapping input or output roots')


def _report(root, phase, group):
    path = root/'report.json'
    if not path.exists() and not path.is_symlink():
        return False
    try:
        value, _ = campaign._input(path, campaign.MAX_REPORT)
        if (value.get('schema') != 'research-signal-run.v1' or value.get('status') != 'complete'
            or value.get('errors') != [] or set(value.get('symbols', {})) != set(group)
            or tuple(_utc(value[k]) for k in ('source_start', 'score_start', 'source_end'))
                != tuple(map(_utc, WINDOWS[phase]))):
            raise OrchestrationError('B1 report failed or fixed group/window conflict')
    except (ValueError, KeyError, TypeError, campaign.CampaignError, plans.PlanError) as exc:
        raise OrchestrationError('B1 report malformed') from exc
    return True


def _stop(proc, *, clock=time.monotonic, sleep=time.sleep):
    # Session leader exit does not imply that its owned PHP descendants exited.
    for signum in (signal.SIGTERM, signal.SIGKILL):
        try:
            os.killpg(proc.pid, signum)
        except ProcessLookupError:
            proc.poll()
            return
        deadline = clock()+5
        while True:
            proc.poll()  # Reap the leader independently of group liveness.
            try:
                os.killpg(proc.pid, 0)
            except ProcessLookupError:
                return
            remaining = deadline-clock()
            if remaining <= 0:
                break
            sleep(min(.05, remaining))
    raise OrchestrationError('owned validation process group survived bounded cleanup')


def _size(root):
    total = 0
    for directory, dirs, files in os.walk(root, followlinks=False):
        for name in (*dirs, *files):
            path = Path(directory)/name
            mode = path.lstat().st_mode
            if not (stat.S_ISREG(mode) or stat.S_ISDIR(mode)):
                raise OrchestrationError('unsafe output inventory')
            if stat.S_ISREG(mode):
                total += path.stat().st_size
    return total


class Stages:
    """Production adapters use C1 strict verification and C2 scheduling only."""

    def snapshot(self, c):
        files = (c.dataset_root/'manifest.json', c.instrument_path, c.cost_path, c.python,
                 Path(__file__), Path(e.__file__), Path(e.__file__).with_name('statistics.py'))
        if c.funding_supplement_root is not None:
            files += (c.funding_supplement_root/'status.json',)
        # B1's four Python modules are content-bound in their frozen cwd.
        files += _b1_files(c)
        return {'files': {str(p): signals._file_sha256(p) for p in files},
                'runner_code': campaign._runner_code(), 'php': plans.code_inventory(c.app_dir)}

    def protocol(self, c, deadline):
        instrument, _ = campaign._input(c.instrument_path, campaign.MAX_REPORT)
        costs, _ = campaign._input(c.cost_path, campaign.MAX_REPORT)
        for document, field in ((instrument, 'manifest_hash'), (costs, 'assumption_hash')):
            if canonical_hash({k:v for k,v in document.items() if k != field}) != document.get(field):
                raise OrchestrationError('assumption canonical hash conflict')
        phase_bindings, baseline, code = {}, None, None
        for phase, roots in (('training', c.training_roots), ('validation', c.validation_roots)):
            reports, bindings, windows, _ = campaign._verify_reports(
                c.dataset_root, roots, c.app_dir, SYMBOLS, phase, deadline)
            if tuple(map(_utc, windows)) != tuple(map(_utc, WINDOWS[phase])):
                raise OrchestrationError('verified windows differ from fixed approval')
            for report in reports:
                original = report['code_sha256']
                if not set(map(str, _b1_files(c))) <= set(original):
                    raise OrchestrationError('B1 reports do not bind designated frozen working directory')
                if code is not None and code != original:
                    raise OrchestrationError('original B1 code differs across reports')
                code = original
                # Exact recorded paths must still contain the original B1 bytes.
                for name, digest in original.items():
                    if signals._file_sha256(Path(name)) != digest:
                        raise OrchestrationError('original B1 code changed')
            for symbol in SYMBOLS:
                current = bindings[symbol][2]['baseline']
                if baseline is not None and baseline != current:
                    raise OrchestrationError('baseline differs across phases')
                baseline = current
            inventory = FundingReader(c.dataset_root, windows[1], windows[2], symbols=SYMBOLS,
                supplement_root=c.funding_supplement_root, rest_interval_hypothesis=REST_HYPOTHESIS).inventory
            phase_bindings[phase] = {'source_runs': [bindings[s][4] for s in SYMBOLS],
                'signal_reports': reports, 'runner_code_sha256': campaign._runner_code(),
                'cost_assumptions_hash': costs['assumption_hash'],
                'funding_inventory_hash': inventory.inventory_hash,
                'funding_diagnostic_policy': campaign.FUNDING_DIAGNOSTIC_POLICY}
        identity = {'dataset_hash': e.hash_bytes(e.canonical_bytes(
            {p: b['source_runs'] for p,b in phase_bindings.items()})),
            'signal_code_hash': canonical_hash(code), 'research_code_hash': canonical_hash(plans.code_inventory(c.app_dir)),
            'instrument_assumptions_hash': instrument['manifest_hash'],
            **{p+'_cost_hash': canonical_hash(costs['profiles'][p]) for p in e.PROFILES},
            **{'base_'+key+'_hash': baseline[field] for key,field in
               (('setup', 'setup_hash'), ('config', 'config_hash'), ('catalog', 'condition_catalog_hash'), ('snapshot', 'snapshot_hash'))}}
        return e.make_protocol(identity, phase_bindings=phase_bindings)

    def execute(self, c, protocol, remaining):
        with e.ExperimentRegistry(c.registry_root, protocol, max_output_bytes=c.aggregate_bytes) as registry:
            def unit(registration, output):
                seconds = min(c.unit_timeout, remaining())
                used = _size(c.registry_root)
                allowance = min(c.unit_bytes, c.aggregate_bytes-used-4*1024**2)
                if allowance <= 0:
                    raise OrchestrationError('aggregate storage allowance exhausted')
                registry._budget(allowance)
                runner = e.make_campaign_runner(dataset_root=c.dataset_root,
                    signal_roots={'training':c.training_roots, 'validation':c.validation_roots},
                    app_dir=c.app_dir, instrument_path=c.instrument_path, cost_path=c.cost_path,
                    funding_supplement_root=c.funding_supplement_root, wall_timeout=seconds,
                    max_output_bytes=allowance, min_free_bytes=e.RESERVE)
                return runner(registration, output)
            candidates = e.schedule_batch(registry, unit)
            remaining()
            freeze = e.freeze_selection(registry, candidates)
            reports = e.write_reports(registry, c.report_root, candidates)
            return {'selection_state':freeze['selection_state'], 'reports':{k:str(v) for k,v in reports.items()}}


def _b1_files(c):
    return tuple(c.b1_cwd/'app/backtesting/research'/f'{name}.py'
                 for name in ('signals', 'signal_sources', 'binance_history')) + (
                 c.b1_cwd/'app/modern_trading_contracts.py',)


def run(c: Config, *, clock=time.monotonic, sleep=time.sleep, popen=subprocess.Popen,
        stop=_stop, stages=None):
    _validate(c)
    stages = stages or Stages()
    started = clock()
    deadline = started+c.timeout
    def remaining():
        value = deadline-clock()
        if value <= 0:
            raise OrchestrationError('overall orchestration deadline exceeded')
        return value
    def pause():
        sleep(min(c.poll_interval, remaining()))
    def stage(function, *args):
        with _wall_guard(remaining()):
            result = function(*args)
        remaining()
        return result
    c.evidence_root.mkdir(mode=0o700)
    children = []
    resources_before = resource.getrusage(resource.RUSAGE_CHILDREN)
    terminal = {'state':'failed', 'holdout_status':'pending', 'paper_transfer':'distinct_pending',
                'execution_authority':'none'}
    try:
        config = {key: [str(p) for p in value] if isinstance(value, tuple) else
                  str(value) if isinstance(value, Path) else value for key,value in asdict(c).items()}
        terminal['attempt_sha256'] = e._publish(c.evidence_root/'attempt.json', {'schema_version':'research-orchestration-attempt.v1',
            'config':config, 'orchestration_sha256':signals._file_sha256(Path(__file__)), 'resume_supported':False})
        snapshot = stage(stages.snapshot, c)
        terminal['input_code_identity_sha256'] = e._publish(c.evidence_root/'input-code-identity.json', snapshot)
        while True:
            remaining()
            ready = [_report(root, 'training', group) for root,group in zip(c.training_roots, GROUPS)]
            if all(ready):
                break
            pause()
        if stage(stages.snapshot, c) != snapshot:
            raise OrchestrationError('frozen input or code changed before validation launch')
        for root, group in zip(c.validation_roots, GROUPS):
            remaining()
            argv = [str(c.python), '-m', 'app.backtesting.research.signals', '--dataset-root', str(c.dataset_root),
                '--app-dir', str(c.app_dir), '--output-root', str(root), '--symbols', ','.join(group),
                '--start', WINDOWS['validation'][0], '--score-start', WINDOWS['validation'][1],
                '--end', WINDOWS['validation'][2]]
            children.append(popen(argv, cwd=c.b1_cwd, env=plans.worker_environment(),
                stdin=subprocess.DEVNULL, start_new_session=True))
        while True:
            remaining()
            codes = [proc.poll() for proc in children]
            if any(code is not None and code != 0 for code in codes):
                raise OrchestrationError('owned validation subprocess failed')
            if all(code == 0 for code in codes):
                break
            pause()
        if not all(_report(root, 'validation', group) for root,group in zip(c.validation_roots, GROUPS)):
            raise OrchestrationError('completed validation subprocess missing report')
        if stage(stages.snapshot, c) != snapshot:
            raise OrchestrationError('frozen input or code changed before protocol freeze')
        protocol = stage(stages.protocol, c, deadline)
        if stage(stages.snapshot, c) != snapshot:
            raise OrchestrationError('frozen input or code changed before protocol freeze')
        report_hashes = {str(root):campaign._input(root/'report.json', campaign.MAX_REPORT)[1]
                         for root in (*c.training_roots, *c.validation_roots)}
        result = stage(stages.execute, c, protocol, remaining)
        remaining()
        if (stage(stages.snapshot, c) != snapshot or any(campaign._input(Path(root)/'report.json', campaign.MAX_REPORT)[1] != digest
                for root,digest in report_hashes.items())):
            raise OrchestrationError('frozen input or code changed at finish')
        terminal.update(state=result['selection_state'], reports=result['reports'])
    except BaseException as exc:
        terminal.update(error_type=type(exc).__name__, error=str(exc)[:2000])
        raise
    finally:
        cleanup_errors = []
        for proc in children:
            try:
                stop(proc)
            except Exception as exc:
                cleanup_errors.append(type(exc).__name__+': '+str(exc)[:1000])
        if cleanup_errors:
            terminal.update(state='failed', cleanup_errors=cleanup_errors)
        resources_after = resource.getrusage(resource.RUSAGE_CHILDREN)
        terminal.update(elapsed_seconds=clock()-started,
            supervisor_peak_rss_kib=resource.getrusage(resource.RUSAGE_SELF).ru_maxrss,
            reaped_children_user_seconds=resources_after.ru_utime-resources_before.ru_utime,
            reaped_children_system_seconds=resources_after.ru_stime-resources_before.ru_stime,
            resource_scope='supervisor_process_peak_and_reaped_child_cpu_delta_not_full_process_tree')
        e._publish(c.evidence_root/'terminal.json', terminal)
        if cleanup_errors:
            raise OrchestrationError('owned validation cleanup failed; evidence retained')
    return terminal


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ('dataset_root', 'app_dir', 'b1_cwd', 'python', 'instrument_path', 'cost_path',
                 'registry_root', 'report_root', 'evidence_root'):
        parser.add_argument('--'+name.replace('_','-'), type=Path, required=True)
    for name in ('training_roots', 'validation_roots'):
        parser.add_argument('--'+name.replace('_','-'), type=Path, nargs=2, required=True)
    parser.add_argument('--funding-supplement-root', type=Path)
    parser.add_argument('--timeout', type=float, required=True)
    args = vars(parser.parse_args(argv))
    for name in ('training_roots', 'validation_roots'):
        args[name] = tuple(args[name])
    result = run(Config(**args))
    print(e.canonical_bytes(result).decode(), end='')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
