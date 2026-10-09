"""Fixed local C2b authority and a durable, once-only campaign claim.

This owner-controlled record is a workflow boundary, not a sandbox against its
owner. Standard library and OS loader bytes remain trusted host infrastructure.
Importing this module neither provisions authority nor reads market evidence.
"""
from __future__ import annotations

from dataclasses import dataclass, field
from datetime import datetime
import fcntl
import hashlib
import json
import math
import os
from pathlib import Path
import re
import selectors
import shutil
import signal
import stat
import subprocess
import time
import uuid

CAMPAIGN_ID = 'historical-2026-10-09'
FROZEN_COMMIT = '8ef8addd93a240ead5c01abbc67d053df44e8942'
FROZEN_FILES = ('app/__init__.py', 'app/backtesting/__init__.py',
    'app/backtesting/research/__init__.py', 'app/backtesting/research/experiments.py',
    'app/backtesting/research/statistics.py', 'app/backtesting/research/portfolio_simulator.py',
    'app/modern_trading_contracts.py', 'app/backtesting/contracts.py',
    'app/backtesting/dataset.py', 'app/backtesting/dataset_store.py',
    'app/backtesting/indicator_bridge.py', 'app/backtesting/tradingcore_bridge.py')
SYMBOLS = tuple(s+'USDT' for s in ('BTC','ETH','BNB','XRP','ADA','DOGE','SOL','LTC','LINK','AVAX'))
SOURCE_START = '2025-11-01T00:00:00Z'
SCORE_START = '2026-01-01T00:00:00Z'
END = '2026-10-09T06:00:00Z'
RISK_ASSUMPTIONS = (('daily_loss_limit_quote','30'), ('per_position_notional_cap_quote','250'),
    ('maximum_concurrent_positions',4), ('risk_budget_rate','0.05'), ('maximum_leverage','2'),
    ('maximum_aggregate_exposure_rate','1'), ('execution_authority','none'))
OVERALL_SECONDS = 7*24*3600
VERIFIER_SECONDS = 6*3600
OVERALL_BYTES = 24*1024**3
SIGNAL_BYTES = 8*1024**3
UNIT_BYTES = SPOOL_BYTES = 4*1024**3
DISK_RESERVE = 20*1024**3
TERMINAL_RESERVE = 64*1024
METADATA_BYTES = PROTOCOL_BYTES = 64*1024**2
FREEZE_BYTES = STDOUT_BYTES = 4*1024**2
STDERR_BYTES = 2*1024**2
INVENTORY_JSON_BYTES = 8*1024**2
INVENTORY_ENTRIES = 25_000
INVENTORY_FILE_BYTES = 256*1024**2
_ISSUED = {}


class HoldoutError(ValueError):
    """Fail-closed identity, scope, runtime or publication violation."""


def canonical_bytes(value) -> bytes:
    return (json.dumps(value, sort_keys=True, separators=(',',':'), ensure_ascii=False,
                       allow_nan=False)+'\n').encode()


def hash_bytes(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def _authority_path() -> Path:
    return Path.home()/'.config/tradingV3/research-authorities'/f'{CAMPAIGN_ID}.json'


def _safe_path(path: Path, *, directory=False, private=False) -> Path:
    path = Path(path)
    if not path.is_absolute() or '..' in path.parts:
        raise HoldoutError('absolute_canonical_path_required')
    for ancestor in (*reversed(path.parents), path):
        if ancestor.is_symlink():
            raise HoldoutError('symlink_path_denied')
    info = path.lstat()
    if not (stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode)):
        raise HoldoutError('regular_directory_or_file_required')
    if private and (info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) != (0o700 if directory else 0o600)):
        raise HoldoutError('owned_private_path_required')
    return path


def _read_json(path: Path, limit: int, *, private=False, canonical=True):
    _safe_path(path, private=private)
    fd = os.open(path, os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    info = os.fstat(fd)
    if not stat.S_ISREG(info.st_mode):
        os.close(fd)
        raise HoldoutError('regular_file_required')
    if private and (info.st_uid!=os.geteuid() or stat.S_IMODE(info.st_mode)!=0o600):
        os.close(fd)
        raise HoldoutError('owned_private_file_required')
    with os.fdopen(fd,'rb') as stream:
        raw = stream.read(limit+1)
    if len(raw)>limit:
        raise HoldoutError('json_size_limit')
    try:
        value = json.loads(raw)
        if canonical and raw != canonical_bytes(value):
            raise HoldoutError('json_not_canonical')
    except (UnicodeError, json.JSONDecodeError, ValueError, TypeError) as exc:
        if isinstance(exc, HoldoutError): raise
        raise HoldoutError('invalid_canonical_json') from exc
    if not isinstance(value,dict): raise HoldoutError('json_object_required')
    return value, raw


def _file_hash(path: Path) -> str:
    _safe_path(path)
    digest = hashlib.sha256()
    with path.open('rb') as stream:
        for block in iter(lambda:stream.read(1024**2), b''):
            digest.update(block)
    return digest.hexdigest()


def _overlap(left: Path, right: Path) -> bool:
    return left == right or left in right.parents or right in left.parents


@dataclass(frozen=True)
class CampaignAuthority:
    config_bytes: bytes
    inventory_bytes: bytes
    registry: Path
    anchor: Path
    dataset_root: Path
    app_dir: Path
    instrument_path: Path
    cost_path: Path
    python: Path
    frozen_python_root: Path

    @property
    def authority_hash(self): return hash_bytes(self.config_bytes)


def _decode_authority(config, raw) -> CampaignAuthority:
    if (config.get('schema_version') != 'research-campaign-authority.v1' or
            config.get('campaign_id') != CAMPAIGN_ID):
        raise HoldoutError('campaign_authority_schema')
    try:
        registry = Path(config['registry']); paths = config['paths']
        runtime = config['runtime']
        if (not isinstance(runtime,dict) or not isinstance(runtime.get('files'),dict) or
                not isinstance(runtime.get('dependency_roots'),list) or
                not isinstance(runtime.get('python_version'),str)):
            raise HoldoutError('campaign_authority_runtime_structure')
        _safe_path(registry,directory=True,private=True)
        if not re.fullmatch('[a-f0-9]{64}',config['protocol_hash']):
            raise HoldoutError('authority_protocol_hash')
        fields = {name:Path(paths[name]) for name in ('dataset_root','app_dir','instrument_path',
            'cost_path','python','frozen_python_root')}
        for name,path in fields.items():
            _safe_path(path, directory=name.endswith('_root') or name=='app_dir')
        return CampaignAuthority(raw, canonical_bytes(config['runtime']), registry,
            registry.parent/(registry.name+'.c2b-holdout'), **fields)
    except (KeyError, TypeError) as exc:
        raise HoldoutError('campaign_authority_structure') from exc


def load_campaign_authority() -> CampaignAuthority:
    path = _authority_path()
    _safe_path(path.parent,directory=True,private=True)
    config, raw = _read_json(path, INVENTORY_JSON_BYTES+METADATA_BYTES, private=True)
    return _decode_authority(config,raw)


def _installed(authority: CampaignAuthority) -> CampaignAuthority:
    current = load_campaign_authority()
    if not isinstance(authority,CampaignAuthority) or authority != current:
        raise HoldoutError('installed_authority_conflict')
    return current


def _trusted_repository_inventory():
    value, _ = _read_json(Path(__file__).with_name('frozen_verifier_inventory.json'), INVENTORY_JSON_BYTES)
    if value.get('commit') != FROZEN_COMMIT or set(value.get('files',{})) != set(FROZEN_FILES):
        raise HoldoutError('frozen_inventory_schema')
    return value['files']


def _check_files(files):
    for name,digest in files.items():
        if _file_hash(Path(name)) != digest: raise HoldoutError('pinned_file_changed:'+name)


def _runtime_inventory(python: Path, original_python: Path):
    """Discover dependencies without site processing or executable .pth files."""
    _safe_path(python)
    total = python.stat().st_size
    if total>INVENTORY_FILE_BYTES: raise HoldoutError('runtime_inventory_limit')
    probe = _bounded_child([str(python),'-I','-S','-B','-c',
        'import json,sys;print(json.dumps([list(sys.version_info[:3]),sys.base_prefix]))'],
        cwd=python.parent,env={},stdin=b'',timeout=30,stdout_limit=4096,stderr_limit=4096)
    version, base = json.loads(probe)
    roots = []
    for prefix in (original_python.parent.parent, Path(base)):
        for lib in ('lib','lib64'):
            root = prefix/lib/f'python{version[0]}.{version[1]}'/'site-packages'
            if root.is_dir() and root.resolve() not in roots:
                roots.append(_safe_path(root.resolve(),directory=True))
    names = ('pydantic','pydantic_core','annotated_types','typing_extensions','typing_inspection')
    files, versions, used = {}, {}, []
    for name in names:
        matches = [(root,root/name) for root in roots if (root/name).is_dir()]
        matches += [(root,root/(name+'.py')) for root in roots if (root/(name+'.py')).is_file()]
        if not matches:
            if name=='typing_inspection': continue
            raise HoldoutError('runtime_dependency_missing:'+name)
        root, package = matches[0]
        if root not in used: used.append(root)
        trees = [package]
        metadata = sorted(root.glob(name+'-*.dist-info'))
        if len(metadata)!=1: raise HoldoutError('dependency_metadata_ambiguous:'+name)
        trees += metadata
        metadata_path = _safe_path(metadata[0]/'METADATA')
        with metadata_path.open('rb') as stream: metadata_raw = stream.read(INVENTORY_JSON_BYTES+1)
        if len(metadata_raw)>INVENTORY_JSON_BYTES: raise HoldoutError('dependency_metadata_size_limit')
        text = metadata_raw.decode()
        match = re.search(r'^Version: (.+)$', text,re.M)
        if match is None: raise HoldoutError('dependency_version_missing')
        versions[name] = match.group(1)
        for tree in trees:
            candidates = [tree] if tree.is_file() else sorted(tree.rglob('*'))
            for path in candidates:
                if '__pycache__' in path.parts or path.suffix in ('.pyc','.pyo'): continue
                if path.is_symlink(): raise HoldoutError('dependency_symlink')
                if path.is_dir(): continue
                _safe_path(path)
                if path.suffix=='.pth': raise HoldoutError('dependency_pth_requirement_denied')
                total += path.stat().st_size
                if total>INVENTORY_FILE_BYTES or len(files)>=INVENTORY_ENTRIES:
                    raise HoldoutError('runtime_inventory_limit')
                files[str(path)] = _file_hash(path)
    files[str(python)] = _file_hash(python)
    result = {'files':files,'dependency_roots':list(map(str,used)),
        'python_version':'.'.join(map(str,version)), 'versions':versions,
        'trusted_host':'stdlib_and_os_loader_not_attested'}
    if len(canonical_bytes(result))>INVENTORY_JSON_BYTES: raise HoldoutError('runtime_inventory_json_limit')
    return result


def _verify_runtime_inventory(authority):
    config = json.loads(authority.config_bytes)
    runtime = json.loads(authority.inventory_bytes)
    if runtime != config['runtime'] or len(authority.inventory_bytes)>INVENTORY_JSON_BYTES:
        raise HoldoutError('runtime_inventory_conflict')
    if len(runtime['files'])>INVENTORY_ENTRIES: raise HoldoutError('runtime_inventory_limit')
    _check_files(runtime['files'])
    original = Path(config['recovery']['python'] if config['recovery'] else config['original_runtime']['python'])
    if _runtime_inventory(authority.python,original)!=runtime:
        raise HoldoutError('runtime_dependency_inventory_changed')
    inventory = _trusted_repository_inventory()
    if config['repository_inventory'] != inventory: raise HoldoutError('frozen_repository_inventory_conflict')
    _check_files({str(authority.frozen_python_root/name):digest for name,digest in inventory.items()})


def _fsync_directory(path):
    fd = os.open(path,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
    try: os.fsync(fd)
    finally: os.close(fd)


def _publish_bytes(path: Path, raw: bytes):
    """Keep any interrupted temporary/link evidence; never overwrite or repair."""
    temporary = path.parent/('.publish-'+uuid.uuid4().hex+'.tmp')
    fd = os.open(temporary,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,'wb') as stream:
        view = memoryview(raw)
        while view:
            count = stream.write(view)
            if not count: raise OSError('short publication write')
            view = view[count:]
        stream.flush(); os.fsync(stream.fileno())
    os.link(temporary,path,follow_symlinks=False)
    _fsync_directory(path.parent)
    temporary.unlink()
    _fsync_directory(path.parent)


def install_campaign_authority(retained_attempt_path: Path, retained_snapshot_path: Path,
        recovery_environment_record_path: Path|None=None) -> CampaignAuthority:
    """Trusted explicit provisioning only. Evaluation has no provisioning flags."""
    destination = _authority_path()
    if destination.exists() or destination.is_symlink(): raise HoldoutError('authority_already_installed')
    attempt_path, snapshot_path = Path(retained_attempt_path), Path(retained_snapshot_path)
    if (attempt_path.name != 'attempt.json' or snapshot_path != attempt_path.with_name('input-code-identity.json')):
        raise HoldoutError('retained_snapshot_relationship')
    _safe_path(attempt_path.parent,directory=True,private=True)
    attempt,attempt_raw = _read_json(attempt_path,METADATA_BYTES,private=True)
    snapshot,snapshot_raw = _read_json(snapshot_path,METADATA_BYTES,private=True)
    terminal_path = attempt_path.with_name('terminal.json')
    terminal,terminal_raw = _read_json(terminal_path,METADATA_BYTES,private=True)
    if (attempt.get('schema_version') != 'research-orchestration-attempt.v1' or
            terminal.get('state') not in ('selected','no_eligible_candidate') or
            terminal.get('attempt_sha256') != hash_bytes(attempt_raw) or
            terminal.get('input_code_identity_sha256') != hash_bytes(snapshot_raw)):
        raise HoldoutError('authentic_terminal_required')
    try:
        c = attempt['config']
        if Path(c['evidence_root']) != attempt_path.parent: raise HoldoutError('retained_config_relationship')
        registry = _safe_path(Path(c['registry_root']),directory=True,private=True)
        protocol,protocol_raw = _read_json(registry/'protocol.json',PROTOCOL_BYTES,private=True)
        experiments = [Path(p) for p in snapshot['files'] if p.endswith('/app/backtesting/research/experiments.py')]
        if len(experiments)!=1: raise HoldoutError('frozen_root_ambiguous')
        frozen_root = experiments[0].parents[3]
        repository_inventory = _trusted_repository_inventory()
        _check_files({str(frozen_root/name):digest for name,digest in repository_inventory.items()})
        original_python = Path(c['python'])
        original_digest = snapshot['files'][str(original_python)]
        recovery = None
        if recovery_environment_record_path is None:
            python = original_python.resolve(strict=True)
            if _file_hash(python) != original_digest: raise HoldoutError('original_python_changed')
        else:
            recovery_path = Path(recovery_environment_record_path)
            _safe_path(recovery_path.parent,directory=True,private=True)
            recovery,_ = _read_json(recovery_path,INVENTORY_JSON_BYTES,private=True)
            if (recovery.get('schema_version') != 'research-runtime-recovery.v1' or
                    recovery.get('original_python') != str(original_python) or
                    recovery.get('original_python_sha256') != original_digest or
                    recovery.get('historical_dependency_identity') != 'unavailable' or
                    not isinstance(recovery.get('reason'),str) or not recovery['reason'].strip() or
                    recovery.get('reconstructed') is not True):
                raise HoldoutError('explicit_runtime_recovery_required')
            python = _safe_path(Path(recovery['python']))
        runtime = _runtime_inventory(python,Path(recovery['python']) if recovery else original_python)
        if recovery and recovery.get('runtime_inventory') != runtime:
            raise HoldoutError('reviewed_recovery_inventory_conflict')
        paths = {name:str(_safe_path(Path(c[name]),directory=name in ('dataset_root','app_dir')))
            for name in ('dataset_root','app_dir','instrument_path','cost_path')}
        paths.update(python=str(python),frozen_python_root=str(frozen_root),
            funding_supplement_root=c.get('funding_supplement_root'))
        config = {'schema_version':'research-campaign-authority.v1','campaign_id':CAMPAIGN_ID,
            'registry':str(registry),'protocol_hash':hash_bytes(protocol_raw),'paths':paths,
            'runtime':runtime,'repository_inventory':repository_inventory,'snapshot':snapshot,
            'original_runtime':{'python':str(original_python),'sha256':original_digest,
                'historical_dependency_identity':'unavailable'},'recovery':recovery,
            'retained':{str(attempt_path):hash_bytes(attempt_raw),str(snapshot_path):hash_bytes(snapshot_raw),
                        str(terminal_path):hash_bytes(terminal_raw)}}
    except (KeyError,TypeError) as exc: raise HoldoutError('retained_configuration_invalid') from exc
    raw = canonical_bytes(config)
    if len(raw)>METADATA_BYTES: raise HoldoutError('authority_metadata_limit')
    authority = _decode_authority(config,raw)
    _preflight_identities(authority,protocol)
    destination.parent.mkdir(mode=0o700,parents=True,exist_ok=True)
    _safe_path(destination.parent,directory=True,private=True)
    if any(destination.parent.glob('.publish-*.tmp')): raise HoldoutError('ambiguous_authority_installation')
    _publish_bytes(destination,raw)
    return load_campaign_authority()


# The only subprocess program. Its roots come from the fixed installed authority,
# never caller arguments, PYTHONPATH, site processing or a transport override.
FIXED_BOOTSTRAP = r'''
import sys,os,json,hashlib,pathlib,sysconfig,importlib.abc,importlib.machinery
def canonical(v):
    return (json.dumps(v,sort_keys=True,separators=(',',':'),ensure_ascii=False,allow_nan=False)+'\n').encode()
home=pathlib.Path.home()
authority_path=home/'.config/tradingV3/research-authorities/historical-2026-10-09.json'
with authority_path.open('rb') as f: raw=f.read(72*1024**2+1)
if len(raw)>72*1024**2: raise RuntimeError('authority_size_limit')
a=json.loads(raw)
if canonical(a)!=raw: raise RuntimeError('authority_not_canonical')
registry=json.loads(sys.stdin.buffer.read(65537))
if registry!=a['registry']: raise RuntimeError('registry_conflict')
root=pathlib.Path(a['paths']['frozen_python_root'])
files=dict(a['runtime']['files'])
files.update({str(root/k):v for k,v in a['repository_inventory'].items()})
total=0
for p,d in files.items():
    digest=hashlib.sha256()
    with open(p,'rb') as f:
        while True:
            block=f.read(1024**2)
            if not block: break
            total+=len(block)
            if total>256*1024**2: raise RuntimeError('pinned_bytes_limit')
            digest.update(block)
    if digest.hexdigest()!=d: raise RuntimeError('pinned_file_changed')
roots=[root]+[pathlib.Path(p) for p in a['runtime']['dependency_roots']]
stdlib=pathlib.Path(sysconfig.get_path('stdlib')).resolve()
def trusted(origin):
    if origin in (None,'built-in','frozen'): return True
    p=pathlib.Path(origin).resolve()
    if str(p) in files: return True
    return stdlib in p.parents and not any(x in p.parts for x in ('site-packages','dist-packages'))
class Guard(importlib.abc.MetaPathFinder):
    def find_spec(self,name,path=None,target=None):
        found=importlib.machinery.PathFinder.find_spec(name,path,target)
        if found and not trusted(found.origin): raise ImportError('unpinned_module:'+name)
        if found and found.origin is None and found.submodule_search_locations:
            raise ImportError('unpinned_namespace:'+name)
        return found
sys.meta_path.insert(0,Guard())
sys.path[:0]=list(map(str,roots))
from app.backtesting.research.experiments import verify_freeze,canonical_bytes
result=canonical_bytes(verify_freeze(pathlib.Path(registry)/'selection-freeze.json'))
for name,module in tuple(sys.modules.items()):
    if not trusted(getattr(module,'__file__',None)): raise RuntimeError('unpinned_loaded_module:'+name)
sys.stdout.buffer.write(result)
'''


def _bounded_child(argv, *, cwd, env, stdin: bytes, timeout: float,
        stdout_limit=STDOUT_BYTES, stderr_limit=STDERR_BYTES):
    child = subprocess.Popen(argv,cwd=cwd,env=env,stdin=subprocess.PIPE,stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,start_new_session=True)
    out,err = bytearray(),bytearray()
    deadline = time.monotonic()+timeout
    selector = selectors.DefaultSelector()
    pending = memoryview(stdin)
    try:
        for pipe in (child.stdin,child.stdout,child.stderr): os.set_blocking(pipe.fileno(),False)
        selector.register(child.stdin,selectors.EVENT_WRITE,'input')
        selector.register(child.stdout,selectors.EVENT_READ,'out')
        selector.register(child.stderr,selectors.EVENT_READ,'err')
        while selector.get_map():
            remaining = deadline-time.monotonic()
            if remaining<=0: raise HoldoutError('frozen_verifier_timeout')
            for key,_ in selector.select(min(remaining,0.1)):
                if key.data=='input':
                    if pending:
                        try: pending = pending[os.write(key.fd,pending):]
                        except BrokenPipeError: pending = pending[:0]
                    if not pending: selector.unregister(key.fileobj); key.fileobj.close()
                    continue
                data = os.read(key.fd,65536)
                if not data: selector.unregister(key.fileobj); key.fileobj.close(); continue
                target,limit = (out,stdout_limit) if key.data=='out' else (err,stderr_limit)
                if len(target)+len(data)>limit: raise HoldoutError('frozen_verifier_'+key.data+'_overflow')
                target.extend(data)
        remaining = deadline-time.monotonic()
        if remaining<=0: raise HoldoutError('frozen_verifier_timeout')
        child.wait(timeout=remaining)
        if child.returncode:
            raise HoldoutError(f'frozen_verifier_exit:{child.returncode}:stderr_sha256:{hash_bytes(bytes(err))}:stderr:{bytes(err[-2000:])!r}')
        return bytes(out)
    except subprocess.TimeoutExpired as exc:
        raise HoldoutError('frozen_verifier_timeout') from exc
    finally:
        selector.close()
        # The group can outlive its leader while retaining a pipe. Clean up only
        # this freshly created owned group, including after leader exit.
        try: os.killpg(child.pid,signal.SIGKILL)
        except ProcessLookupError: pass
        try: child.wait(timeout=10)
        except subprocess.TimeoutExpired: raise HoldoutError('owned_verifier_cleanup_timeout')
        for pipe in (child.stdin,child.stdout,child.stderr):
            if not pipe.closed: pipe.close()


def _invoke_frozen_verifier(authority: CampaignAuthority, *, timeout: float) -> bytes:
    if not math.isfinite(timeout) or not 0<timeout<=VERIFIER_SECONDS:
        raise HoldoutError('verifier_timeout_lower_only')
    _verify_runtime_inventory(authority)
    try:
        return _bounded_child([str(authority.python),'-I','-S','-B','-c',FIXED_BOOTSTRAP],
            cwd=authority.frozen_python_root, env={'HOME':str(Path.home()),'LANG':'C.UTF-8'},
            stdin=canonical_bytes(str(authority.registry)), timeout=timeout)
    finally:
        _verify_runtime_inventory(authority)


def _verified_freeze(authority, *, timeout=VERIFIER_SECONDS):
    protocol,protocol_raw = _read_json(authority.registry/'protocol.json',PROTOCOL_BYTES,private=True)
    config = json.loads(authority.config_bytes)
    if hash_bytes(protocol_raw)!=config['protocol_hash']: raise HoldoutError('protocol_identity_changed')
    freeze,freeze_raw = _read_json(authority.registry/'selection-freeze.json',FREEZE_BYTES,private=True)
    result = _invoke_frozen_verifier(authority,timeout=timeout)
    if result != freeze_raw: raise HoldoutError('frozen_verifier_response_mismatch')
    if (freeze.get('schema_version')!='research-selection-freeze.v1' or
            freeze.get('protocol_hash')!=config['protocol_hash'] or freeze.get('cutoff')!=END or
            freeze.get('identity')!=protocol.get('identity') or
            freeze.get('execution_authority')!='none' or freeze.get('holdout_status')!='pending'):
        raise HoldoutError('invalid_selection_freeze')
    if freeze.get('selection_state')=='no_eligible_candidate': raise HoldoutError('no_selected_candidate')
    selected = freeze.get('selected')
    if (freeze.get('selection_state')!='selected' or not isinstance(selected,dict) or
            selected not in protocol.get('variants',[]) or
            freeze.get('holdout_authorization')!='guarded_selected_identity_only'):
        raise HoldoutError('invalid_selected_identity')
    if (protocol.get('symbol_priority')!=list(SYMBOLS) or protocol.get('cost_profiles')!=['baseline','adverse']
            or protocol.get('risk_assumptions')!=dict(RISK_ASSUMPTIONS) or protocol.get('cutoff')!=END):
        raise HoldoutError('frozen_protocol_scope_conflict')
    return protocol,freeze,freeze_raw


def _new_code_inventory():
    root = Path(__file__).parent
    names = ('holdout.py','holdout_authority.py','frozen_verifier_inventory.json','signals.py','signal_sources.py',
        'campaign.py','campaign_evidence.py','plans.py','portfolio_simulator.py','statistics.py','funding.py',
        'binance_history.py','source_complements.py')
    return {str(root/name):_file_hash(root/name) for name in names} | {
        str(root.parents[1]/'modern_trading_contracts.py'):_file_hash(root.parents[1]/'modern_trading_contracts.py')}


def _preflight_identities(authority, protocol):
    """Metadata/code only. Never select/open holdout candles, signals or profits."""
    config = json.loads(authority.config_bytes)
    snapshot = config['snapshot']
    _check_files(config['retained'])
    original_files = dict(snapshot['files'])
    if config['recovery']: original_files.pop(config['original_runtime']['python'])
    else:
        original_files.pop(config['original_runtime']['python'])
        if _file_hash(authority.python)!=config['original_runtime']['sha256']:
            raise HoldoutError('original_python_changed')
    _check_files(original_files)
    _check_files(snapshot['runner_code'])
    _check_files({str(authority.app_dir/name):digest for name,digest in snapshot['php']['files'].items()})
    from . import plans, signals
    from .portfolio_simulator import canonical_hash
    if plans.code_inventory(authority.app_dir)!=snapshot['php']:
        raise HoldoutError('php_inventory_changed')
    baseline = None
    for binding in protocol['phase_bindings'].values():
        seen = []
        for summary in binding['signal_reports']:
            root = _safe_path(Path(summary['root']),directory=True,private=True)
            report,report_raw = _read_json(root/'report.json',16*1024**2,private=True,canonical=False)
            if (hash_bytes(report_raw)!=summary['report_sha256'] or
                    report.get('schema')!='research-signal-run.v1' or report.get('status')!='complete' or
                    report.get('errors')!=[] or not isinstance(report.get('symbols'),dict) or
                    list(report['symbols'])!=summary['symbols'] or
                    report.get('code_sha256')!=summary['code_sha256']):
                raise HoldoutError('original_report_binding_conflict')
            _check_files(summary['code_sha256'])
            for symbol,metadata in report['symbols'].items():
                seen.append(symbol)
                current = metadata['baseline']
                if baseline is not None and current!=baseline: raise HoldoutError('baseline_identity_conflict')
                baseline = current
                signals._verify_baseline_files(current,authority.app_dir,
                    metadata['effective_config_snapshot']['ordered_files'])
        if len(seen)!=len(SYMBOLS) or set(seen)!=set(SYMBOLS): raise HoldoutError('original_symbol_binding_conflict')
    if baseline is None: raise HoldoutError('baseline_binding_missing')
    identity = protocol['identity']
    for name,key in (('setup','setup_hash'),('config','config_hash'),('catalog','condition_catalog_hash'),('snapshot','snapshot_hash')):
        if identity['base_'+name+'_hash']!=baseline[key]: raise HoldoutError('baseline_protocol_conflict')
    instrument,instrument_raw = _read_json(authority.instrument_path,METADATA_BYTES,canonical=False)
    costs,cost_raw = _read_json(authority.cost_path,METADATA_BYTES,canonical=False)
    for value,key in ((instrument,'manifest_hash'),(costs,'assumption_hash')):
        try: expected_hash = canonical_hash({k:v for k,v in value.items() if k!=key})
        except (TypeError,ValueError) as exc:
            raise HoldoutError('assumption_canonical_hash_conflict') from exc
        if value.get(key)!=expected_hash:
            raise HoldoutError('assumption_canonical_hash_conflict')
    if identity['instrument_assumptions_hash']!=instrument['manifest_hash']: raise HoldoutError('instrument_identity_conflict')
    for profile in ('baseline','adverse'):
        if identity[profile+'_cost_hash']!=canonical_hash(costs['profiles'][profile]):
            raise HoldoutError('cost_identity_conflict')
    return {'original_snapshot':snapshot,'original_runtime':config['original_runtime'],'recovery':config['recovery'],
        'protocol_identity':identity,'baseline':baseline,'php':snapshot['php'],
        'instrument_sha256':hash_bytes(instrument_raw),'cost_sha256':hash_bytes(cost_raw),
        'new_code':_new_code_inventory(),'source_manifest_sha256':_file_hash(authority.dataset_root/'manifest.json')}


def _authorization_fields(auth):
    return (auth.authority_hash,auth.contract_hash,auth.claim_hash,auth.anchor,auth.output_root,
            auth.slots,auth.contract_bytes,auth.mode)


@dataclass(frozen=True)
class HoldoutAuthorization:
    authority_hash: str
    contract_hash: str
    claim_hash: str
    anchor: Path
    output_root: Path
    slots: tuple[tuple[str,str],...]
    contract_bytes: bytes
    mode: str
    _mint: object = field(repr=False,compare=False)

    @property
    def end_ms(self):
        _check_issuance(self)
        return json.loads(self.contract_bytes)['window']['end_ms']

    def for_slot(self, variant_id: str, cost_profile: str):
        require_claim(self,operation='retained')
        if (variant_id,cost_profile) not in self.slots: raise HoldoutError('slot_not_claimed')
        return _mint_authorization(self.authority_hash,self.contract_hash,self.claim_hash,self.anchor,
            self.output_root,((variant_id,cost_profile),),self.contract_bytes,self.mode)


def _mint_authorization(*fields):
    token = object()
    auth = HoldoutAuthorization(*fields,token)
    _ISSUED[token] = (auth,_authorization_fields(auth))
    return auth


def _check_issuance(auth):
    if not isinstance(auth,HoldoutAuthorization):
        raise HoldoutError('authorization_not_privately_issued')
    if type(auth._mint) is not object:
        raise HoldoutError('authorization_not_privately_issued')
    issued = _ISSUED.get(auth._mint)
    if issued is None or issued[0] is not auth or issued[1]!=_authorization_fields(auth):
        raise HoldoutError('authorization_not_privately_issued')


def _retained(authority):
    _safe_path(authority.anchor,directory=True,private=True)
    expected = {'campaign_id':CAMPAIGN_ID,'registry':str(authority.registry),
                'protocol_hash':json.loads(authority.config_bytes)['protocol_hash']}
    anchor,_ = _read_json(authority.anchor/'authority.json',METADATA_BYTES,private=True)
    if anchor!=expected: raise HoldoutError('anchor_authority_conflict')
    contract,contract_raw = _read_json(authority.anchor/'contract.json',METADATA_BYTES,private=True)
    claim,claim_raw = _read_json(authority.anchor/'claim.json',METADATA_BYTES,private=True)
    if (contract.get('schema_version')!='research-holdout-contract.v1' or
            claim.get('schema_version')!='research-holdout-claim.v1' or
            claim.get('authority_hash')!=authority.authority_hash or
            claim.get('contract_hash')!=hash_bytes(contract_raw) or
            claim.get('output_root')!=contract.get('output_root') or
            claim.get('campaign_id')!=CAMPAIGN_ID or not re.fullmatch('[a-f0-9]{32}',claim.get('run_id',''))):
        raise HoldoutError('retained_claim_conflict')
    return contract,contract_raw,claim_raw


def _fresh_output(output: Path,authority):
    if not output.is_absolute() or '..' in output.parts or output != output.resolve():
        raise HoldoutError('canonical_output_required')
    if output.exists() or output.is_symlink(): raise HoldoutError('fresh_output_required')
    _safe_path(output.parent,directory=True)
    config = json.loads(authority.config_bytes)
    inputs = [authority.registry,authority.anchor,_authority_path(),
        *(Path(p) for p in config['paths'].values() if p is not None),
        *(Path(p) for p in config['retained'])]
    if any(_overlap(output,p) for p in inputs): raise HoldoutError('input_output_overlap')


def prepare_claim(authority: CampaignAuthority,output_root: Path,*,timeout: float=OVERALL_SECONDS,
        max_output_bytes: int=OVERALL_BYTES) -> HoldoutAuthorization:
    authority = _installed(authority)
    started = time.monotonic()
    if (isinstance(timeout,bool) or not isinstance(timeout,(int,float)) or not math.isfinite(timeout) or
            not 0<timeout<=OVERALL_SECONDS or isinstance(max_output_bytes,bool) or
            not isinstance(max_output_bytes,int) or not TERMINAL_RESERVE<max_output_bytes<=OVERALL_BYTES):
        raise HoldoutError('resource_limits_lower_only')
    output = Path(output_root)
    if (authority.anchor/'claim.json').exists(): raise HoldoutError('already_claimed')
    _fresh_output(output,authority)
    _verify_runtime_inventory(authority)
    protocol,freeze,freeze_raw = _verified_freeze(authority,timeout=min(VERIFIER_SECONDS,timeout))
    identities = _preflight_identities(authority,protocol)
    selected = freeze['selected']
    variants = [selected] if selected['id']=='baseline' else [selected,next(v for v in protocol['variants'] if v['id']=='baseline')]
    slots = [{'variant_id':v['id'],'cost_profile':cost,
        'output_root':str(output/'units'/v['id']/cost),
        'input_binding_path':str(output/'inputs'/v['id']/(cost+'.json'))}
        for v in variants for cost in ('baseline','adverse')]
    if len({(s['variant_id'],s['cost_profile']) for s in slots})!=len(slots): raise HoldoutError('duplicate_slots')
    config = json.loads(authority.config_bytes)
    contract = {'schema_version':'research-holdout-contract.v1','campaign_id':CAMPAIGN_ID,
        'authority_hash':authority.authority_hash,'registry':str(authority.registry),
        'protocol_hash':config['protocol_hash'],'freeze_sha256':hash_bytes(freeze_raw),
        'freeze':freeze,'identities':identities,'paths':config['paths'],'output_root':str(output),
        'symbols':list(SYMBOLS),'variants':variants,'selected':selected,'slots':slots,
        'window':{'source_start':SOURCE_START,'score_start':SCORE_START,'end':END,
            'end_ms':int(datetime.fromisoformat(END.replace('Z','+00:00')).timestamp()*1000)},
        'risk_assumptions':dict(RISK_ASSUMPTIONS),'cost_profiles':protocol['cost_profiles'],
        'limits':{'overall_seconds':timeout,'overall_bytes':max_output_bytes,'signal_bytes':SIGNAL_BYTES,
            'unit_bytes':UNIT_BYTES,'statistics_spool_bytes':SPOOL_BYTES,'disk_reserve_bytes':DISK_RESERVE,
            'metadata_bytes':METADATA_BYTES,'terminal_reserve_bytes':TERMINAL_RESERVE,
            'verifier_seconds':VERIFIER_SECONDS,'unit_seconds':VERIFIER_SECONDS,'cleanup_seconds':10},
        'preflight_elapsed_seconds':time.monotonic()-started}
    raw = canonical_bytes(contract)
    if len(raw)>METADATA_BYTES or len(raw)+TERMINAL_RESERVE>max_output_bytes:
        raise HoldoutError('metadata_capacity_exceeded')
    if shutil.disk_usage(output.parent).free-len(raw)-TERMINAL_RESERVE<DISK_RESERVE:
        raise HoldoutError('disk_reserve_required')
    if time.monotonic()-started>=timeout: raise HoldoutError('preflight_deadline')
    try: authority.anchor.mkdir(mode=0o700)
    except FileExistsError: pass
    _safe_path(authority.anchor,directory=True,private=True)
    lock_fd = os.open(authority.anchor/'.claim.lock',os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
    with os.fdopen(lock_fd,'r+b') as lock:
        info = os.fstat(lock.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_uid!=os.geteuid() or stat.S_IMODE(info.st_mode)!=0o600:
            raise HoldoutError('owned_private_claim_lock_required')
        try: fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError as exc: raise HoldoutError('claim_writer_busy') from exc
        if (authority.anchor/'claim.json').exists(): raise HoldoutError('already_claimed')
        entries = {p.name for p in authority.anchor.iterdir()}
        if entries-{'authority.json','.claim.lock'}: raise HoldoutError('ambiguous_claim_state')
        expected = canonical_bytes({'campaign_id':CAMPAIGN_ID,'registry':str(authority.registry),
            'protocol_hash':config['protocol_hash']})
        if 'authority.json' in entries:
            _, existing = _read_json(authority.anchor/'authority.json',METADATA_BYTES,private=True)
            if existing!=expected: raise HoldoutError('anchor_authority_conflict')
        else: _publish_bytes(authority.anchor/'authority.json',expected)
        # Contract has no execution authority. Interruption here is retained and
        # blocks future claims. Claim link publication permanently consumes C2b.
        _publish_bytes(authority.anchor/'contract.json',raw)
        claim_raw = canonical_bytes({'schema_version':'research-holdout-claim.v1','campaign_id':CAMPAIGN_ID,
            'authority_hash':authority.authority_hash,'contract_hash':hash_bytes(raw),
            'output_root':str(output),'run_id':uuid.uuid4().hex})
        _publish_bytes(authority.anchor/'claim.json',claim_raw)
    return _mint_authorization(authority.authority_hash,hash_bytes(raw),hash_bytes(claim_raw),
        authority.anchor,output,tuple((s['variant_id'],s['cost_profile']) for s in slots),raw,'evaluate')


def mint_retained_authorization(authority: CampaignAuthority) -> HoldoutAuthorization:
    """Mint read-only replay scope from retained consumed evidence, never retry."""
    authority = _installed(authority)
    protocol,freeze,raw = _verified_freeze(authority)
    _verify_runtime_inventory(authority)
    contract,contract_raw,claim_raw = _retained(authority)
    _validate_retained_scope(authority,contract,protocol,freeze)
    if hash_bytes(raw)!=contract['freeze_sha256'] or _preflight_identities(authority,protocol)!=contract['identities']:
        raise HoldoutError('retained_input_identity_changed')
    return _mint_authorization(authority.authority_hash,hash_bytes(contract_raw),hash_bytes(claim_raw),
        authority.anchor,Path(contract['output_root']),
        tuple((s['variant_id'],s['cost_profile']) for s in contract['slots']),contract_raw,'verify')


def _validate_retained_scope(authority,contract,protocol,freeze):
    """Replay derives the fixed scope again; consistent rewritten hashes cannot
    broaden a retained contract's dates, risk, catalogue or resource limits.
    """
    selected = freeze['selected']
    variants = [selected] if selected['id']=='baseline' else [selected,
        next(v for v in protocol['variants'] if v['id']=='baseline')]
    config = json.loads(authority.config_bytes)
    output = Path(contract['output_root'])
    slots = [{'variant_id':v['id'],'cost_profile':cost,
        'output_root':str(output/'units'/v['id']/cost),
        'input_binding_path':str(output/'inputs'/v['id']/(cost+'.json'))}
        for v in variants for cost in ('baseline','adverse')]
    expected = {'campaign_id':CAMPAIGN_ID,'authority_hash':authority.authority_hash,
        'registry':str(authority.registry),'protocol_hash':config['protocol_hash'],
        'freeze':freeze,'selected':selected,'variants':variants,'slots':slots,
        'symbols':list(SYMBOLS),'paths':config['paths'],'risk_assumptions':dict(RISK_ASSUMPTIONS),
        'cost_profiles':['baseline','adverse'],
        'window':{'source_start':SOURCE_START,'score_start':SCORE_START,'end':END,
            'end_ms':int(datetime.fromisoformat(END.replace('Z','+00:00')).timestamp()*1000)}}
    if any(contract.get(key)!=value for key,value in expected.items()):
        raise HoldoutError('retained_contract_scope_conflict')
    limits = contract.get('limits',{})
    fixed = {'signal_bytes':SIGNAL_BYTES,'unit_bytes':UNIT_BYTES,'statistics_spool_bytes':SPOOL_BYTES,
        'disk_reserve_bytes':DISK_RESERVE,'metadata_bytes':METADATA_BYTES,
        'terminal_reserve_bytes':TERMINAL_RESERVE,'verifier_seconds':VERIFIER_SECONDS,
        'unit_seconds':VERIFIER_SECONDS,'cleanup_seconds':10}
    seconds,storage = limits.get('overall_seconds'),limits.get('overall_bytes')
    if (set(limits)!=set(fixed)|{'overall_seconds','overall_bytes'} or
            any(limits.get(k)!=v for k,v in fixed.items()) or isinstance(seconds,bool) or
            not isinstance(seconds,(int,float)) or not math.isfinite(seconds) or not 0<seconds<=OVERALL_SECONDS or
            isinstance(storage,bool) or not isinstance(storage,int) or not TERMINAL_RESERVE<storage<=OVERALL_BYTES):
        raise HoldoutError('retained_contract_scope_limits')


def require_claim(auth: HoldoutAuthorization,*,operation: str,variant_id: str|None=None,
        cost_profile: str|None=None,symbols: tuple[str,...]|None=None,source_start: str|None=None,
        score_start: str|None=None,end: str|None=None,output_root: Path|None=None) -> dict:
    """Check issuance and durable bindings before a public entrypoint reads data."""
    _check_issuance(auth)
    if operation not in ('source','signals','simulate','statistics','verify','binding','report','retained'):
        raise HoldoutError('unknown_authorized_operation')
    if auth.mode=='verify' and operation in ('source','signals','simulate','binding','report'):
        raise HoldoutError('verify_only_authorization')
    authority = load_campaign_authority()
    if auth.authority_hash!=authority.authority_hash or auth.anchor!=authority.anchor:
        raise HoldoutError('authorization_authority_conflict')
    contract,raw,claim_raw = _retained(authority)
    if (raw!=auth.contract_bytes or hash_bytes(raw)!=auth.contract_hash or
            hash_bytes(claim_raw)!=auth.claim_hash or str(auth.output_root)!=contract['output_root']):
        raise HoldoutError('authorization_retained_bytes_conflict')
    _verify_runtime_inventory(authority)
    protocol,protocol_raw = _read_json(authority.registry/'protocol.json',PROTOCOL_BYTES,private=True)
    _,freeze_raw = _read_json(authority.registry/'selection-freeze.json',FREEZE_BYTES,private=True)
    if (hash_bytes(protocol_raw)!=contract['protocol_hash'] or hash_bytes(freeze_raw)!=contract['freeze_sha256']
            or _preflight_identities(authority,protocol)!=contract['identities']):
        raise HoldoutError('claimed_input_identity_changed')
    if symbols is not None and tuple(symbols)!=tuple(contract['symbols']): raise HoldoutError('exact_symbol_scope_required')
    for requested,key in ((source_start,'source_start'),(score_start,'score_start'),(end,'end')):
        if requested is not None and requested!=contract['window'][key]: raise HoldoutError('exact_window_required')
    if operation in ('simulate','statistics','verify'):
        if len(auth.slots)!=1: raise HoldoutError('single_slot_authorization_required')
        if (variant_id,cost_profile)!=auth.slots[0]: raise HoldoutError('exact_slot_required')
    elif variant_id is not None or cost_profile is not None:
        if (variant_id,cost_profile) not in auth.slots: raise HoldoutError('exact_slot_required')
    expected = auth.output_root
    if operation in ('simulate','statistics','verify'):
        expected = expected/'units'/variant_id/cost_profile
    elif operation=='signals': expected = expected/'signals'
    if output_root is not None and Path(output_root)!=expected: raise HoldoutError('exact_output_scope_required')
    return json.loads(raw)
