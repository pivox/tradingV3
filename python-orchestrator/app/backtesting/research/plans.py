"""One bounded, persistent, read-only Symfony plan process per campaign unit.

Only the closed geometry diffs are serialized here. PHP owns all geometry,
risk, fees and portfolio admission arithmetic. Hashes bind evidence and never
grant order authority. The supplied app directory must remain immutable.
"""
from __future__ import annotations

from collections import Counter
from collections.abc import Mapping
import json
import os
from pathlib import Path
import re
import selectors
import shutil
import subprocess
import time

from .portfolio_simulator import SYMBOLS, Signal, canonical_hash, digest
from .signals import _terminate, _file_sha256

VARIANT_DIFFS = {
    'baseline': {}, 'ema20_5m': {'anchor_source':'ema_20'},
    'vwap_15m': {'anchor_timeframe':'15m'},
    'ema20_15m': {'anchor_source':'ema_20','anchor_timeframe':'15m'},
    'width_050': {'zone_atr_multiplier':.50}, 'width_075': {'zone_atr_multiplier':.75},
    'min_width_001': {'minimum_half_width_rate':.001},
    'max_width_002': {'maximum_half_width_rate':.02},
    'stop_125': {'stop_atr_multiplier':1.25}, 'stop_200': {'stop_atr_multiplier':2.0},
    'target_150': {'target_risk_multiple':1.5}, 'target_250': {'target_risk_multiple':2.5},
    'ema20_5m_width_050': {'anchor_source':'ema_20','zone_atr_multiplier':.50},
}
CODE_SCOPE = 'research_plan_app_php_vendor_config_content_v2'
CODE_POLICY = 'content_at_open_and_close_immutable_appdir_during_session'
INTEGRITY = 'sha256_and_local_baseline_only_runner_verifies_b1_artifact'


class PlanError(RuntimeError):
    """A planner failure invalidates a campaign; it is never a zero-trade result."""


def variant_selection(name: str, baseline: Mapping) -> dict:
    if name not in VARIANT_DIFFS:
        raise PlanError('unknown closed research variant')
    diff = dict(VARIANT_DIFFS[name]) if VARIANT_DIFFS[name] else []
    payload = {'schema_version':'research-variant.v1','id':name,'diff':diff,
        'base_setup_hash':baseline['setup_hash'],'base_config_hash':baseline['config_hash'],
        'base_catalog_hash':baseline['condition_catalog_hash']}
    return {'schema_version':'research-variant-selection.v1','id':name,
            'diff':diff,'variant_hash':canonical_hash(payload)}


def worker_environment() -> dict[str, str]:
    return {'APP_ENV':'prod','APP_DEBUG':'0','DOTENV_PATH':'/dev/null',
        'SYMFONY_DOTENV_PATH':'/dev/null',
        'DATABASE_URL':'postgresql://none:none@127.0.0.1:1/unreachable?serverVersion=16&charset=utf8',
        'DEFAULT_URI':'http://localhost','PATH':os.defpath,'LC_ALL':'C','LANG':'C'}


def code_inventory(app_dir: Path) -> dict:
    """Match the PHP scope exactly, including dependency data and all config."""
    files = {}
    for directory in ('src','vendor','config'):
        root = app_dir/directory
        if not root.is_dir() or root.is_symlink():
            raise PlanError('planner code directory missing')
        for path in sorted(root.rglob('*')):
            if path.is_symlink():
                raise PlanError('planner code symlink')
            if path.is_dir():
                continue
            if not path.is_file():
                raise PlanError('planner code nonregular file')
            if directory != 'src' or path.suffix == '.php':
                files[str(path.relative_to(app_dir))] = _file_sha256(path)
    for relative in ('bin/console','composer.json','composer.lock','symfony.lock'):
        path = app_dir/relative
        if not path.is_file() or path.is_symlink():
            raise PlanError('planner code file missing')
        files[relative] = _file_sha256(path)
    return {'scope':CODE_SCOPE,'files':files}


def _json(raw: bytes) -> dict:
    def pairs(items):
        result = {}
        for key, value in items:
            if key in result:
                raise PlanError('duplicate worker JSON key')
            result[key] = value
        return result
    def invalid(value):
        raise PlanError('nonfinite worker JSON')
    try:
        value = json.loads(raw, object_pairs_hook=pairs, parse_constant=invalid)
    except (ValueError, UnicodeError, RecursionError) as exc:
        raise PlanError('invalid worker JSON') from exc
    if not isinstance(value, dict):
        raise PlanError('worker object frame required')
    return value


def _wire_mapping(value):
    if isinstance(value,Mapping):
        return dict(value)
    raise PlanError('unsupported planner wire value')


class PlanWorker:
    def __init__(self, app_dir: Path, opening: dict, *,
                 worker_argv: tuple[str, ...] | None = None,
                 wall_timeout: float = 6*3600, io_timeout: float = 60,
                 max_stderr_bytes: int = 1024**2, max_output_bytes: int = 4*1024**3,
                 max_line_bytes: int = 2*1024**2):
        app_dir = Path(app_dir)
        if (not app_dir.is_absolute() or not app_dir.is_dir() or app_dir.is_symlink()
            or wall_timeout <= 0 or io_timeout <= 0 or max_stderr_bytes < 0
            or max_output_bytes <= 0 or max_line_bytes <= 0):
            raise PlanError('planner directory or limits invalid')
        self.app_dir = app_dir
        self.code_hash = canonical_hash(code_inventory(app_dir)) if worker_argv is None else None
        if worker_argv is None:
            php = shutil.which('php')
            if php is None or not (app_dir/'bin/console').is_file():
                raise PlanError('PHP planner unavailable')
            worker_argv = (php,'-d','display_errors=stderr',str(app_dir/'bin/console'),
                           'app:research:plans','--no-interaction')
        if not worker_argv or not Path(worker_argv[0]).is_absolute():
            raise PlanError('absolute worker executable required')
        self.opening = _json(json.dumps(opening, allow_nan=False,default=_wire_mapping).encode())
        self.received = self.planned = 0
        self.rejections: Counter = Counter()
        self.indices: dict[str, int] = {}
        self.closed = False
        self.stderr = bytearray()
        self.output_bytes = 0
        self.pending = bytearray()
        self.max_stderr, self.max_output, self.max_line = max_stderr_bytes, max_output_bytes, max_line_bytes
        self.deadline = time.monotonic()+wall_timeout
        self.io_timeout = io_timeout
        self.selector = selectors.DefaultSelector()
        try:
            self.process = subprocess.Popen(worker_argv, cwd=app_dir, env=worker_environment(),
                shell=False, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, bufsize=0)
        except OSError as exc:
            self.selector.close()
            raise PlanError('planner process could not start') from exc
        for stream, label in ((self.process.stdout,'stdout'),(self.process.stderr,'stderr')):
            os.set_blocking(stream.fileno(), False)
            self.selector.register(stream, selectors.EVENT_READ, label)
        os.set_blocking(self.process.stdin.fileno(), False)
        try:
            self.opened = self._exchange(self.opening)
            self._validate_opened()
        except Exception:
            self.abort()
            raise

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc, tb):
        self.abort()

    def _read(self, key) -> None:
        chunk = os.read(key.fileobj.fileno(), 65536)
        if not chunk:
            self.selector.unregister(key.fileobj)
            return
        if key.data == 'stderr':
            self.stderr.extend(chunk)
            if len(self.stderr) > self.max_stderr:
                raise PlanError('planner stderr size limit')
            return
        self.output_bytes += len(chunk)
        self.pending.extend(chunk)
        if self.output_bytes > self.max_output:
            raise PlanError('planner output size limit')
        if len(self.pending.split(b'\n',1)[0]) > self.max_line:
            raise PlanError('planner output line size limit')

    def _poll(self, deadline: float) -> list:
        remaining = min(deadline,self.deadline)-time.monotonic()
        if remaining <= 0:
            raise PlanError('planner wall or I/O timeout')
        return self.selector.select(min(.1,remaining))

    def _exchange(self, frame: dict) -> dict:
        if self.closed:
            raise PlanError('planner session closed')
        if self.pending:
            raise PlanError('unsolicited planner output')
        raw = json.dumps(frame, separators=(',',':'), allow_nan=False,default=_wire_mapping).encode()+b'\n'
        if len(raw) > self.max_line:
            raise PlanError('planner input line size limit')
        deadline = time.monotonic()+self.io_timeout
        offset = 0
        self.selector.register(self.process.stdin, selectors.EVENT_WRITE, 'stdin')
        try:
            while offset < len(raw) or b'\n' not in self.pending:
                if not any(k.data == 'stdout' for k in self.selector.get_map().values()):
                    raise PlanError('planner ended without explicit frame')
                for key, _ in self._poll(deadline):
                    if key.data == 'stdin':
                        try:
                            offset += os.write(key.fileobj.fileno(), raw[offset:])
                        except OSError as exc:
                            raise PlanError('planner input failed') from exc
                        if offset == len(raw):
                            self.selector.unregister(key.fileobj)
                    else:
                        self._read(key)
            line, _, rest = self.pending.partition(b'\n')
            self.pending = bytearray(rest)
            if self.pending:
                raise PlanError('unexpected extra planner output')
            result = _json(bytes(line))
            if result.get('schema_version') == 'research-plan-error.v1':
                raise PlanError('planner structured error: '+str(result.get('reason_code')))
            return result
        finally:
            if self.process.stdin in [k.fileobj for k in self.selector.get_map().values()]:
                self.selector.unregister(self.process.stdin)

    def _validate_opened(self) -> None:
        f, o = self.opened, self.opening
        expected = {'schema_version':'research-plan-opened.v1','session_id':o['session_id'],
            'variant_id':o['variant']['id'],'variant_hash':o['variant']['variant_hash'],
            'base_config_hash':o['baseline']['config_hash'], 'base_snapshot_hash':o['baseline']['snapshot_hash'],
            'instrument_assumptions_hash':o['instrument_assumptions']['manifest_hash'],
            'cost_assumptions_hash':o['cost_assumptions']['assumption_hash'],
            'cost_profile':o['cost_profile'],'source_run_count':len(o['source_runs']),
            'expected_signals':o['expected_signals'],'code_hash_scope':CODE_SCOPE,
            'code_identity_check_policy':CODE_POLICY,'execution_authority':'none'}
        if (set(f) != set(expected)|{'research_code_hash'} or not digest(f.get('research_code_hash'))
            or (self.code_hash is not None and f.get('research_code_hash') != self.code_hash)
            or any(f.get(k) != v or type(f.get(k)) != type(v) for k,v in expected.items())):
            raise PlanError('planner opened identity conflict')

    def build(self, signal: Signal, portfolio: Mapping) -> dict:
        symbol = signal.payload.get('symbol')
        run = next((r for r in self.opening['source_runs'] if r['symbol'] == symbol), None)
        if (run is None or type(signal.index) is not int or signal.index <= self.indices.get(symbol,-1)
            or self.received >= self.opening['expected_signals'] or signal.payload.get('passed') is not True):
            raise PlanError('planner input signal binding or count conflict')
        result = self._exchange({'schema_version':'research-plan-signal.v1',
            'session_id':self.opening['session_id'],'signal_index':signal.index,
            'signal':dict(signal.payload),'portfolio':dict(portfolio)})
        b, o = self.opening['baseline'], self.opening
        expected = {'signal_result_hash':signal.payload['result_hash'],'signal_index':signal.index,
            'signal_run_id':run['signal_run_id'],'signal_output_sha256':run['signal_output_sha256'],
            **run['source'],'base_setup_hash':b['setup_hash'],'base_config_hash':b['config_hash'],
            'base_catalog_hash':b['condition_catalog_hash'],'base_snapshot_hash':b['snapshot_hash'],
            'variant_id':o['variant']['id'],'variant_hash':o['variant']['variant_hash'],
            'instrument_assumptions_hash':o['instrument_assumptions']['manifest_hash'],
            'cost_assumptions_hash':o['cost_assumptions']['assumption_hash'],
            'research_code_hash':self.opened['research_code_hash'],'code_hash_scope':CODE_SCOPE,
            'integrity_boundary':INTEGRITY,'research_only':True,'portfolio_hash':portfolio['portfolio_hash'],
            'cost_profile':o['cost_profile']}
        if any(result.get(k) != v or type(result.get(k)) != type(v) for k,v in expected.items()):
            raise PlanError('planner result identity conflict')
        schema = result.get('schema_version')
        if schema == 'research-plan.v1':
            if (result.get('execution_authority') != 'none' or result.get('symbol') != symbol
                or result.get('evaluated_ms') != signal.payload['evaluated_ms']
                or canonical_hash({k:v for k,v in result.items() if k != 'plan_hash'}) != result.get('plan_hash')):
                raise PlanError('planner plan hash or execution binding invalid')
            self.planned += 1
        elif schema == 'research-plan-rejection.v1':
            reason = result.get('reason_code')
            if not isinstance(reason,str) or re.fullmatch('[a-z0-9_]{1,100}',reason) is None:
                raise PlanError('planner rejection reason invalid')
            self.rejections[reason] += 1
        else:
            raise PlanError('planner response schema invalid')
        self.indices[symbol] = signal.index
        self.received += 1
        return result

    def close(self) -> dict:
        if self.received != self.opening['expected_signals']:
            raise PlanError('planner received count incomplete')
        result = self._exchange({'schema_version':'research-plan-close.v1',
            'session_id':self.opening['session_id'],'expected_signals':self.received})
        expected = {'schema_version':'research-plan-summary.v1','session_id':self.opening['session_id'],
            'received':self.received,'planned':self.planned,'rejected':self.received-self.planned,
            'rejection_counts':dict(self.rejections) if self.rejections else [],
            'completion':'complete','execution_authority':'none',
            'research_code_hash':self.opened['research_code_hash'],
            'code_hash_scope':CODE_SCOPE,'code_identity_check_policy':CODE_POLICY}
        if result != expected or any(type(result.get(k)) != type(v) for k,v in expected.items()):
            raise PlanError('planner summary or accounting conflict')
        self.process.stdin.close()
        deadline = time.monotonic()+self.io_timeout
        while self.selector.get_map():
            for key, _ in self._poll(deadline):
                self._read(key)
            if self.pending:
                raise PlanError('planner data after summary')
        try:
            exit_code = self.process.wait(timeout=max(.001,min(deadline,self.deadline)-time.monotonic()))
        except subprocess.TimeoutExpired as exc:
            raise PlanError('planner exit timeout') from exc
        if exit_code != 0:
            raise PlanError('planner exited nonzero')
        if self.code_hash is not None and canonical_hash(code_inventory(self.app_dir)) != self.code_hash:
            raise PlanError('planner code changed during session')
        self.closed = True
        return result

    def evidence(self) -> dict:
        import hashlib
        return {'stderr_bytes':len(self.stderr),'stderr_sha256':hashlib.sha256(self.stderr).hexdigest(),
                'stdout_bytes':self.output_bytes,'exit_code':self.process.poll()}

    def abort(self) -> None:
        self.selector.close()
        _terminate(self.process)
        for stream in (self.process.stdin,self.process.stdout,self.process.stderr):
            stream.close()
