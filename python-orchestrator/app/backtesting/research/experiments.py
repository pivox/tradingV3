"""Prespecified, private experiment registry and bounded research selection.

The immutable registration is published before invoking any simulation. A crash
therefore leaves a visible interrupted attempt, never an erased retry identity.
Importing this module cannot run a campaign or inspect strategy observations.
"""
from __future__ import annotations

from contextlib import AbstractContextManager
from collections import Counter
import fcntl
import csv
import io
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import uuid
from typing import Any

from .portfolio_simulator import SYMBOLS
from .statistics import decimal

VARIANTS = (
    ('baseline', {}), ('ema20_5m', {'anchor_source':'ema_20'}),
    ('vwap_15m', {'anchor_timeframe':'15m'}),
    ('ema20_15m', {'anchor_source':'ema_20','anchor_timeframe':'15m'}),
    ('width_050', {'zone_atr_multiplier':0.5}), ('width_075', {'zone_atr_multiplier':0.75}),
    ('min_width_001', {'minimum_half_width_rate':0.001}),
    ('max_width_002', {'maximum_half_width_rate':0.02}),
    ('stop_125', {'stop_atr_multiplier':1.25}), ('stop_200', {'stop_atr_multiplier':2.0}),
    ('target_150', {'target_risk_multiple':1.5}), ('target_250', {'target_risk_multiple':2.5}),
    ('ema20_5m_width_050', {'anchor_source':'ema_20','zone_atr_multiplier':0.5}),
)
PROFILES = ('baseline', 'adverse')
IDENTITIES = ('dataset_hash','signal_code_hash','research_code_hash','base_setup_hash',
    'base_config_hash','base_catalog_hash','base_snapshot_hash','instrument_assumptions_hash',
    'baseline_cost_hash','adverse_cost_hash')
WINDOWS = {'training': {'start':'2023-01-01T00:00:00Z','end':'2025-01-01T00:00:00Z'},
           'validation': {'start':'2025-01-01T00:00:00Z','end':'2026-01-01T00:00:00Z'}}
LIMIT = 4 * 1024**3
RESERVE = 20 * 1024**3
MAX_PROTOCOL_BYTES = 64 * 1024**2
MAX_REGISTRY_JSON_BYTES = 4 * 1024**2


class ExperimentError(ValueError):
    """Identity, immutable storage or selection boundary violation."""


def canonical_bytes(value: Any) -> bytes:
    return (json.dumps(value, sort_keys=True, separators=(',',':'), ensure_ascii=False,
                       allow_nan=False) + '\n').encode()


def hash_bytes(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def make_protocol(identity: dict, *, phase_bindings: dict | None = None) -> dict:
    if (set(identity) != set(IDENTITIES) or any(not isinstance(v,str) or
            re.fullmatch(r'(?:sha256:)?[a-f0-9]{64}',v) is None for v in identity.values())):
        raise ExperimentError('invalid_protocol_identity')
    if phase_bindings is not None and (set(phase_bindings) != set(WINDOWS) or
            any(set(phase_bindings[p]) != {'source_runs','signal_reports','runner_code_sha256',
                'cost_assumptions_hash','funding_inventory_hash','funding_diagnostic_policy'} for p in WINDOWS)):
        raise ExperimentError('invalid_phase_bindings')
    if phase_bindings is not None:
        for binding in phase_bindings.values():
            funding_hash,policy = binding['funding_inventory_hash'],binding['funding_diagnostic_policy']
            if (not isinstance(funding_hash,str) or re.fullmatch(r'(?:sha256:)?[a-f0-9]{64}',funding_hash) is None
                    or not isinstance(policy,str) or re.fullmatch(r'[A-Za-z0-9_.:-]{1,128}',policy) is None):
                raise ExperimentError('invalid_frozen_funding_binding')
    return {'schema_version':'research-experiment-protocol.v1', 'identity':dict(identity),
        'phase_bindings':json.loads(canonical_bytes(phase_bindings)),
        'selection_code_sha256':{name:hash_bytes(Path(__file__).with_name(name).read_bytes())
                                 for name in ('statistics.py','experiments.py')},
        'cutoff':'2026-10-09T06:00:00Z', 'symbol_priority':list(SYMBOLS),
        'windows':json.loads(canonical_bytes(WINDOWS)), 'initial_wallet_quote':'100000',
        'variants':[{'id':key,'diff':dict(diff)} for key,diff in VARIANTS],
        'cost_profiles':list(PROFILES),
        'risk_assumptions':{'daily_loss_limit_quote':'30','per_position_notional_cap_quote':'250',
            'maximum_concurrent_positions':4,'risk_budget_rate':'0.05','maximum_leverage':'2',
            'maximum_aggregate_exposure_rate':'1','execution_authority':'none'},
        'thresholds':{'minimum_training_trades':100,'minimum_training_trades_per_year':30,
            'minimum_validation_trades':50,'minimum_pairs':5,'baseline_minimum_net_profit_factor':'1.2',
            'adverse_minimum_net_profit_factor':'1.0','maximum_marked_drawdown_quote':'6000',
            'annual_net_pnl_strictly_positive':True,'mean_realized_net_r_strictly_positive':True,
            'undefined_profit_factor_eligible':False},
        'ranking':['worst_validation_mean_realized_net_r_desc',
            'worst_validation_marked_drawdown_asc','changed_field_count_asc','catalogue_order_asc'],
        'holdout_policy':'closed_until_verified_selection_freeze',
        'paper_transfer':'distinct_pending', 'criteria_are_statistical_significance':False}


def _validate_protocol(protocol: dict) -> None:
    if protocol != make_protocol(protocol.get('identity', {}),phase_bindings=protocol.get('phase_bindings')):
        raise ExperimentError('protocol_not_prespecified')


def screening_reasons(metrics: dict, phase: str, profile: str) -> list[str]:
    if phase not in WINDOWS or profile not in PROFILES:
        raise ExperimentError('screening_phase_or_profile_invalid')
    reasons = []
    for field, expected, reason in (
        ('status','complete','incomplete_evidence'),
        ('independently_reconciled',True,'unreconciled_evidence'),
        ('coverage_complete',True,'missing_coverage_evidence'),
        ('cost_evidence_complete',True,'missing_cost_evidence')):
        if metrics.get(field) != expected:
            reasons.append(reason)
    if metrics.get('closed_trades',0) < (100 if phase == 'training' else 50):
        reasons.append('insufficient_trades')
    if metrics.get('pairs_with_trades',0) < 5:
        reasons.append('insufficient_pairs')
    if metrics.get('mean_realized_net_r') is None or decimal(metrics['mean_realized_net_r']) <= 0:
        reasons.append('nonpositive_mean_net_r')
    factor = metrics.get('net_profit_factor')
    if factor is None:
        reasons.append('undefined_net_profit_factor')
    elif decimal(factor) < decimal('1.2' if profile == 'baseline' else '1.0'):
        reasons.append('net_profit_factor_below_threshold')
    if metrics.get('maximum_drawdown_quote') is None:
        reasons.append('missing_marked_drawdown')
    elif decimal(metrics['maximum_drawdown_quote']) > 6000:
        reasons.append('marked_drawdown_exceeded')
    for year in (('2023','2024') if phase == 'training' else ('2025',)):
        annual = metrics.get('per_year',{}).get(year,{})
        if phase == 'training' and annual.get('closed_trades',0) < 30:
            reasons.append('insufficient_annual_training_trades:'+year)
        if decimal(annual.get('cashflow_net_pnl_quote','0')) <= 0:
            reasons.append('nonpositive_annual_net_pnl:'+year)
    return reasons


def rank_candidates(candidates: list[dict], protocol: dict) -> list[dict]:
    _validate_protocol(protocol)
    catalogue = {row['id']:(len(row['diff']),index) for index,row in enumerate(protocol['variants'])}
    seen = set()
    eligible = []
    for candidate in candidates:
        variant = candidate['variant_id']
        if variant not in catalogue or variant in seen:
            raise ExperimentError('duplicate_or_unknown_candidate')
        seen.add(variant)
        if any(screening_reasons(candidate.get(phase,{}).get(profile,{}),phase,profile)
               for phase in WINDOWS for profile in PROFILES):
            continue
        validation = candidate['validation']
        key = (-min(decimal(validation[p]['mean_realized_net_r']) for p in PROFILES),
               max(decimal(validation[p]['maximum_drawdown_quote']) for p in PROFILES),
               *catalogue[variant])
        eligible.append((key,candidate))
    return [candidate for _,candidate in sorted(eligible,key=lambda row:row[0])]


def _safe_directory(path: Path) -> None:
    if not path.is_absolute() or '..' in path.parts or path == Path.home() or path == Path('/'):
        raise ExperimentError('unsafe_private_root')
    for directory in (path,*path.parents):
        if directory.is_symlink():
            raise ExperimentError('symlink_private_root')
        if (directory/'.git').exists():
            raise ExperimentError('private_root_inside_git_tree')
        if directory.exists() and not directory.is_dir():
            raise ExperimentError('private_root_not_directory')


def _read_json(path: Path) -> dict:
    cap = MAX_PROTOCOL_BYTES if path.name == 'protocol.json' else MAX_REGISTRY_JSON_BYTES
    if (any(parent.is_symlink() for parent in path.parents) or
            not stat.S_ISREG(path.lstat().st_mode) or path.stat().st_size > cap):
        raise ExperimentError('unsafe_or_oversized_artifact')
    try:
        value = json.loads(path.read_bytes())
    except (ValueError,UnicodeError) as exc:
        raise ExperimentError('invalid_json_artifact') from exc
    if not isinstance(value,dict):
        raise ExperimentError('invalid_json_artifact')
    return value


def _publish(path: Path, value: dict) -> str:
    data = canonical_bytes(value)
    cap = MAX_PROTOCOL_BYTES if path.name == 'protocol.json' else MAX_REGISTRY_JSON_BYTES
    if len(data) > cap:
        raise ExperimentError('artifact_json_capacity_exceeded')
    return _publish_bytes(path,data)


def _publish_bytes(path: Path, data: bytes) -> str:
    if any(parent.is_symlink() for parent in path.parents):
        raise ExperimentError('symlink_artifact_directory')
    temporary = path.parent / ('.'+path.name+'.'+uuid.uuid4().hex+'.tmp')
    fd = os.open(temporary,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    try:
        with os.fdopen(fd,'wb') as stream:
            stream.write(data); stream.flush(); os.fsync(stream.fileno())
        try:
            os.link(temporary,path,follow_symlinks=False)
        except FileExistsError as exc:
            raise ExperimentError('immutable_artifact_exists') from exc
        directory = os.open(path.parent,os.O_RDONLY|os.O_DIRECTORY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        temporary.unlink(missing_ok=True)
    return hash_bytes(data)


class ExperimentRegistry(AbstractContextManager):
    """One writer, immutable per-attempt records, explicit interrupted attempts."""

    def __init__(self, root: Path, protocol: dict, *, max_output_bytes: int = LIMIT,
                 min_free_bytes: int = RESERVE):
        if (type(max_output_bytes) is not int or max_output_bytes <= 0 or
                type(min_free_bytes) is not int or min_free_bytes < 0):
            raise ExperimentError('registry_limits_invalid')
        self.root = Path(root)
        _safe_directory(self.root)
        _validate_protocol(protocol)
        self.protocol = json.loads(canonical_bytes(protocol))
        self.protocol_hash = hash_bytes(canonical_bytes(protocol))
        self.max_output_bytes, self.min_free_bytes = max_output_bytes,min_free_bytes
        self.lock = None

    def _budget(self, extra: int = 0) -> None:
        total = 0
        for directory,subdirs,files in os.walk(self.root,followlinks=False):
            for name in (*subdirs,*files):
                item = Path(directory)/name
                mode = item.lstat().st_mode
                if stat.S_ISLNK(mode) or not (stat.S_ISREG(mode) or stat.S_ISDIR(mode)):
                    raise ExperimentError('unsafe_registry_entry')
                if stat.S_ISREG(mode):
                    total += item.stat().st_size
        if total+extra > self.max_output_bytes or shutil.disk_usage(self.root).free-extra < self.min_free_bytes:
            raise ExperimentError('registry_capacity_exceeded')

    def __enter__(self):
        if self.root.exists():
            info = self.root.stat()
            if info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) != 0o700:
                raise ExperimentError('root_not_owned_private_directory')
        else:
            self.root.mkdir(parents=True,mode=0o700)
        fd = os.open(self.root/'.writer.lock',os.O_RDWR|os.O_CREAT|os.O_NOFOLLOW,0o600)
        self.lock = os.fdopen(fd,'r+b')
        try:
            try:
                fcntl.flock(self.lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
            except BlockingIOError as exc:
                raise ExperimentError('registry_writer_busy') from exc
            self._budget()
            path = self.root/'protocol.json'
            if path.exists() or path.is_symlink():
                if _read_json(path) != self.protocol or hash_bytes(path.read_bytes()) != self.protocol_hash:
                    raise ExperimentError('registry_protocol_conflict')
            else:
                if set(p.name for p in self.root.iterdir()) != {'.writer.lock'}:
                    raise ExperimentError('unidentified_existing_root')
                self._write(path,self.protocol)
            (self.root/'attempts').mkdir(mode=0o700,exist_ok=True)
        except BaseException:
            self.lock.close(); self.lock = None
            raise
        return self

    def __exit__(self,*args):
        self.lock.close(); self.lock = None

    def _write(self,path: Path,value: dict) -> str:
        if self.lock is None:
            raise ExperimentError('registry_writer_not_acquired')
        if hash_bytes(canonical_bytes(self.protocol)) != self.protocol_hash:
            raise ExperimentError('mutable_protocol_conflict')
        self._budget(len(canonical_bytes(value)))
        return _publish(path,value)

    def register(self, variant: str, profile: str, phase: str) -> dict:
        if self.lock is None:
            raise ExperimentError('registry_writer_not_acquired')
        attempt_id = uuid.uuid4().hex
        registration = self._registration(attempt_id,variant,profile,phase)
        if hash_bytes(canonical_bytes(self.protocol)) != self.protocol_hash:
            raise ExperimentError('mutable_protocol_conflict')
        path = self.root/'attempts'/attempt_id
        path.mkdir(mode=0o700)
        self._write(path/'registration.json',registration)
        return registration

    def _registration(self,attempt_id: str,variant: str,profile: str,phase: str) -> dict:
        if (re.fullmatch(r'[a-f0-9]{32}',attempt_id) is None or variant not in dict(VARIANTS)
                or profile not in PROFILES or phase not in WINDOWS):
            raise ExperimentError('unit_not_prespecified')
        return json.loads(canonical_bytes({'schema_version':'research-attempt-registration.v1','attempt_id':attempt_id,
            'protocol_hash':self.protocol_hash,'variant_id':variant,'cost_profile':profile,'phase':phase,
            'window':WINDOWS[phase],'symbol_priority':list(SYMBOLS),'identity':self.protocol['identity']}))

    def finish(self, attempt_id: str, terminal: dict) -> str:
        if re.fullmatch(r'[a-f0-9]{32}',attempt_id) is None:
            raise ExperimentError('attempt_id_invalid')
        path = self.root/'attempts'/attempt_id
        registration = _read_json(path/'registration.json')
        if terminal.get('state') not in ('complete','inconclusive','failed'):
            raise ExperimentError('terminal_state_invalid')
        if set(terminal) & {'schema_version','attempt_id','protocol_hash','registration_hash'}:
            raise ExperimentError('terminal_reserved_identity')
        value = {'schema_version':'research-attempt-terminal.v1','attempt_id':attempt_id,
                 'protocol_hash':self.protocol_hash, 'registration_hash':hash_bytes(canonical_bytes(registration)),
                 **terminal}
        return self._write(path/'terminal.json',value)

    def attempts(self) -> list[dict]:
        rows = []
        for path in sorted((self.root/'attempts').iterdir()):
            if path.is_symlink() or not path.is_dir():
                raise ExperimentError('unsafe_attempt_directory')
            registration = _read_json(path/'registration.json')
            expected = self._registration(path.name,registration.get('variant_id'),
                                          registration.get('cost_profile'),registration.get('phase'))
            if registration != expected or (path/'registration.json').read_bytes() != canonical_bytes(registration):
                raise ExperimentError('attempt_identity_conflict')
            terminal = _read_json(path/'terminal.json') if (path/'terminal.json').exists() else None
            if terminal is not None and (terminal.get('attempt_id') != path.name or
                    terminal.get('registration_hash') != hash_bytes(canonical_bytes(registration)) or
                    terminal.get('protocol_hash') != self.protocol_hash or
                    terminal.get('state') not in ('complete','inconclusive','failed')):
                raise ExperimentError('terminal_identity_conflict')
            rows.append({'registration':registration,'terminal':terminal})
        return rows


def _file_hash(path: Path) -> str:
    if any(parent.is_symlink() for parent in path.parents) or not stat.S_ISREG(path.lstat().st_mode):
        raise ExperimentError('unsafe_evidence_file')
    digest = hashlib.sha256()
    with path.open('rb') as stream:
        for chunk in iter(lambda:stream.read(1024**2),b''):
            digest.update(chunk)
    return digest.hexdigest()


def _unit_key(registration: dict) -> tuple:
    return tuple(registration[field] for field in ('variant_id','cost_profile','phase'))


def _partial_inventory(root: Path) -> dict:
    if not root.exists():
        return {}
    if root.is_symlink() or not root.is_dir():
        raise ExperimentError('unsafe_partial_evidence_root')
    return {path.name:{'sha256':_file_hash(path),'bytes':path.stat().st_size}
            for path in sorted(root.iterdir())}


def _candidate_rows(registry: ExperimentRegistry, *, allow_pending: bool = False) -> list[dict]:
    """Derive reported candidates exclusively from immutable terminal evidence."""
    units = {}
    for attempt in registry.attempts():
        registration,terminal = attempt['registration'],attempt['terminal']
        if terminal is None:
            if not allow_pending:
                raise ExperimentError('nonterminal_attempt_blocks_selection')
            terminal = {'state':'pending','statistics':{'status':'pending','net_pnl_quote':None}}
        if terminal.get('error') == 'interrupted_requires_fresh_attempt':
            continue
        key = _unit_key(registration)
        if key in units:
            raise ExperimentError('ambiguous_duplicate_unit')
        units[key] = terminal.get('statistics',{'status':terminal['state']})
    candidates = []
    for variant,_ in VARIANTS:
        row = {'variant_id':variant,'training':{},'validation':{}}
        for profile in PROFILES:
            key = (variant,profile,'training')
            if key not in units:
                if not allow_pending:
                    raise ExperimentError('required_training_unit_missing')
                row['training'][profile] = {'status':'pending','net_pnl_quote':None}
            else:
                row['training'][profile] = units[key]
        training_eligible = not any(screening_reasons(row['training'][p],'training',p) for p in PROFILES)
        for profile in PROFILES:
            key = (variant,profile,'validation')
            if training_eligible and key not in units:
                if not allow_pending:
                    raise ExperimentError('required_validation_unit_missing')
                units[key] = {'status':'pending','net_pnl_quote':None}
            if not training_eligible and key in units:
                raise ExperimentError('validation_of_ineligible_training')
            pending_training = any(row['training'][p]['status'] == 'pending' for p in PROFILES)
            row['validation'][profile] = units[key] if training_eligible else {
                'status':'pending_training' if pending_training else 'not_run_training_ineligible','net_pnl_quote':None}
        row['screening_reasons'] = {phase:{p:screening_reasons(row[phase][p],phase,p)
                                         for p in PROFILES} for phase in WINDOWS}
        candidates.append(row)
    return candidates


def schedule_batch(registry: ExperimentRegistry, run_unit, *, verify_unit=None) -> list[dict]:
    """Run exactly 26 training units, then eligible validation; sequential only.

    run_unit(registration, fresh_output_root) is dependency injected. The default
    verifier replays the actual C1 ledgers; tests supply synthetic fixtures.
    No attempt is resumed halfway and every failure remains in the registry.
    """
    if verify_unit is None:
        if registry.protocol.get('phase_bindings') is None:
            raise ExperimentError('phase_bindings_required')
        from .statistics import verify_campaign
        verify_unit = verify_campaign
    if registry.lock is None:
        raise ExperimentError('registry_writer_not_acquired')
    if (registry.root/'selection-freeze.json').exists():
        raise ExperimentError('selection_already_frozen')
    attempts = registry.attempts()
    for attempt in attempts:
        if attempt['terminal'] is None:
            registry.finish(attempt['registration']['attempt_id'],
                {'state':'failed','error':'interrupted_requires_fresh_attempt',
                 'partial_evidence':_partial_inventory(registry.root/'attempts'/attempt['registration']['attempt_id']/'campaign')})

    def execute(variant,profile,phase):
        matching = [a for a in registry.attempts() if _unit_key(a['registration']) == (variant,profile,phase)
                    and a['terminal'].get('error') != 'interrupted_requires_fresh_attempt']
        successes = [a for a in matching if a['terminal']['state'] == 'complete']
        if len(successes) > 1:
            raise ExperimentError('duplicate_success')
        if len(matching) > 1:
            raise ExperimentError('ambiguous_duplicate_unit')
        if matching:
            attempt = matching[0]
            terminal, registration = attempt['terminal'],attempt['registration']
            if terminal['state'] in ('complete','inconclusive'):
                output = registry.root/'attempts'/registration['attempt_id']/'campaign'
                stats = verify_unit(output,registry.protocol,registration)
                for name in ('manifest','status','statistics'):
                    path = (output/(name+'.json') if name != 'statistics' else output.parent/'statistics.json')
                    if _file_hash(path) != terminal[name+'_hash']:
                        raise ExperimentError('reused_evidence_hash_conflict')
                if stats != terminal['statistics']:
                    raise ExperimentError('reused_statistics_conflict')
            return terminal.get('statistics',{'status':terminal['state']})
        registration = registry.register(variant,profile,phase)
        parent = registry.root/'attempts'/registration['attempt_id']
        output = parent/'campaign'
        try:
            run_unit(registration,output)
            registry._budget()
            stats = verify_unit(output,registry.protocol,registration)
            if stats.get('status') not in ('complete','inconclusive'):
                raise ExperimentError('unit_not_finished')
            statistics_hash = registry._write(parent/'statistics.json',stats)
            registry.finish(registration['attempt_id'],{
                'state':stats['status'],'statistics':stats,'statistics_hash':statistics_hash,
                'manifest_hash':_file_hash(output/'manifest.json'),
                'status_hash':_file_hash(output/'status.json')})
            return stats
        except Exception as exc:
            stats = {'status':'failed','error_type':type(exc).__name__,'error':str(exc)[:2000]}
            registry.finish(registration['attempt_id'],{'state':'failed','statistics':stats,
                'partial_evidence':_partial_inventory(output)})
            return stats

    training = {}
    for variant,_ in VARIANTS:
        training[variant] = {profile:execute(variant,profile,'training') for profile in PROFILES}
    for variant,_ in VARIANTS:
        if not any(screening_reasons(training[variant][p],'training',p) for p in PROFILES):
            for profile in PROFILES:
                execute(variant,profile,'validation')
    return _candidate_rows(registry)


def _freeze_document(registry: ExperimentRegistry,candidates: list[dict]) -> dict:
    if _candidate_rows(registry) != candidates:
        raise ExperimentError('candidate_evidence_conflict')
    ranking = rank_candidates(candidates,registry.protocol)
    selected = ranking[0]['variant_id'] if ranking else None
    references = []
    for attempt in registry.attempts():
        registration = attempt['registration']
        parent = registry.root/'attempts'/registration['attempt_id']
        reference = {'attempt_id':registration['attempt_id'],
                     'registration_hash':_file_hash(parent/'registration.json'),
                     'terminal_hash':_file_hash(parent/'terminal.json')}
        terminal = attempt['terminal']
        if terminal.get('partial_evidence') is not None:
            if _partial_inventory(parent/'campaign') != terminal['partial_evidence']:
                raise ExperimentError('partial_evidence_conflict')
            reference['partial_evidence'] = terminal['partial_evidence']
        for name in ('manifest','status','statistics'):
            if name+'_hash' in terminal:
                path = parent/'statistics.json' if name == 'statistics' else parent/'campaign'/(name+'.json')
                if _file_hash(path) != terminal[name+'_hash']:
                    raise ExperimentError('freeze_evidence_hash_conflict')
                reference[name+'_hash'] = terminal[name+'_hash']
        references.append(reference)
    return {'schema_version':'research-selection-freeze.v1','protocol_hash':registry.protocol_hash,
        'cutoff':registry.protocol['cutoff'], 'selection_state':'selected' if selected else 'no_eligible_candidate',
        'selected':next((row for row in registry.protocol['variants'] if row['id'] == selected),None),
        'identity':registry.protocol['identity'],'attempts':references,
        'ranking':[{'variant_id':r['variant_id'],
            'worst_validation_mean_realized_net_r':str(min(decimal(r['validation'][p]['mean_realized_net_r']) for p in PROFILES)),
            'worst_validation_marked_drawdown_quote':str(max(decimal(r['validation'][p]['maximum_drawdown_quote']) for p in PROFILES))}
            for r in ranking],
        'screening':[{'variant_id':row['variant_id'],'screening_reasons':row['screening_reasons'],
            **{phase:{profile:{k:v for k,v in row[phase][profile].items()
                              if k not in ('source_quality','per_pair')} for profile in PROFILES}
               for phase in WINDOWS}} for row in candidates],
        'holdout_authorization':'guarded_selected_identity_only' if selected else 'none',
        'holdout_status':'pending','paper_transfer':'distinct_pending','execution_authority':'none'}


def freeze_selection(registry: ExperimentRegistry,candidates: list[dict]) -> dict:
    if registry.protocol.get('phase_bindings') is None:
        raise ExperimentError('phase_bindings_required')
    _verify_attempt_statistics(registry)
    freeze = _freeze_document(registry,candidates)
    registry._write(registry.root/'selection-freeze.json',freeze)
    return freeze


def verify_freeze(path: Path) -> dict:
    """Verify every referenced byte and replay successful C1 evidence for C2b.

    A valid hash proves integrity only. A no-winner freeze has no holdout authority.
    This helper offers no arbitrary window or boolean bypass.
    """
    path = Path(path)
    if path.name != 'selection-freeze.json':
        raise ExperimentError('freeze_path_invalid')
    _safe_directory(path.parent)
    freeze = _read_json(path)
    protocol = _read_json(path.parent/'protocol.json')
    if protocol.get('phase_bindings') is None:
        raise ExperimentError('phase_bindings_required')
    registry = ExperimentRegistry(path.parent,protocol)
    candidates = _candidate_rows(registry)
    if _freeze_document(registry,candidates) != freeze or path.read_bytes() != canonical_bytes(freeze):
        raise ExperimentError('selection_freeze_conflict')
    _verify_attempt_statistics(registry)
    return freeze


def _verify_attempt_statistics(registry: ExperimentRegistry, *, allow_pending: bool = False) -> None:
    for attempt in registry.attempts():
        terminal,registration = attempt['terminal'],attempt['registration']
        if terminal is None:
            if allow_pending:
                continue
            raise ExperimentError('nonterminal_attempt_blocks_selection')
        if terminal['state'] in ('complete','inconclusive'):
            from .statistics import verify_campaign
            stats = verify_campaign(registry.root/'attempts'/registration['attempt_id']/'campaign',registry.protocol,registration)
            if stats != terminal['statistics']:
                raise ExperimentError('freeze_replayed_statistics_conflict')


def make_campaign_runner(*, dataset_root: Path, signal_roots: dict, app_dir: Path,
                         instrument_path: Path,cost_path: Path, **limits):
    """Bind C1 lazily. The caller separately authorizes actual benchmark/search."""
    if set(signal_roots) != set(WINDOWS):
        raise ExperimentError('both_signal_phases_required')
    def run_unit(registration,output_root):
        from .campaign import run_campaign
        return run_campaign(dataset_root,signal_roots[registration['phase']],output_root,app_dir,
            registration['phase'],registration['variant_id'],registration['cost_profile'],
            instrument_path,cost_path,symbols=SYMBOLS,**limits)
    return run_unit


def _csv_text(value: Any) -> str:
    if value is None:
        return ''
    text = str(value)
    # Numeric negative Decimal values stay numeric; arbitrary text is escaped.
    try:
        decimal(value)
    except ValueError:
        if text.lstrip().startswith(('=','+','-','@')) or text.startswith(('\t','\r','\n')):
            return "'"+text
    return text


def write_reports(registry: ExperimentRegistry,report_root: Path,candidates: list[dict] | None = None) -> dict[str,Path]:
    """Write private JSON/CSV/French Markdown with every attempt preserved."""
    if registry.lock is None:
        raise ExperimentError('registry_writer_not_acquired')
    derived = _candidate_rows(registry,allow_pending=True)
    if candidates is None:
        candidates = derived
    if derived != candidates:
        raise ExperimentError('candidate_evidence_conflict')
    _verify_attempt_statistics(registry,allow_pending=True)
    report_root = Path(report_root)
    _safe_directory(report_root)
    if report_root.exists():
        raise ExperimentError('report_root_must_be_fresh')
    ranking = rank_candidates(candidates,registry.protocol)
    attempts = registry.attempts()
    pending = any(row[phase][p]['status'] in ('pending','pending_training')
                  for row in candidates for phase in WINDOWS for p in PROFILES)
    freeze_path = registry.root/'selection-freeze.json'
    freeze = _read_json(freeze_path) if freeze_path.exists() else None
    if freeze is not None and _freeze_document(registry,candidates) != freeze:
        raise ExperimentError('report_selection_freeze_conflict')
    report = {'schema_version':'research-campaign-report.v1','protocol_hash':registry.protocol_hash,
        'protocol':registry.protocol,'candidates':candidates,'attempts':attempts,
        'ranking':[r['variant_id'] for r in ranking],
        'selection_state':freeze['selection_state'] if freeze is not None else
            ('pending_required_units' if pending else ('eligible_not_frozen' if ranking else 'no_eligible_candidate')),
        'selection_freeze_hash':_file_hash(freeze_path) if freeze is not None else None,
        'holdout_status':'pending','paper_transfer':'distinct_pending','execution_authority':'none',
        'runtime_resource_estimate':'pending_actual_benchmark'}
    fields = ('attempt_id','variant_id','cost_profile','phase','status','closed_trades','wins','losses',
              'breakevens','net_win_rate','gross_pnl_quote','net_pnl_quote','fees_quote','funding_quote',
              'spread_quote','slippage_quote','mean_net_expectancy_quote','mean_realized_net_r',
              'net_profit_factor','profit_factor_undefined_reason','maximum_drawdown_quote',
              'maximum_exposure_quote','source_candles','scored','passed','admitted','filled','rejected','error')
    stream = io.StringIO(newline='')
    writer = csv.DictWriter(stream,fieldnames=fields); writer.writeheader()
    for attempt in attempts:
        registration,terminal = attempt['registration'],attempt['terminal']
        terminal = terminal or {'state':'pending'}
        metrics = terminal.get('statistics',{})
        row = {**metrics,**metrics.get('counts',{}),**{k:registration[k] for k in ('attempt_id','variant_id','cost_profile','phase')},
               'status':terminal['state'],'error':metrics.get('error',terminal.get('error'))}
        writer.writerow({key:_csv_text(row.get(key)) for key in fields})
    for candidate in candidates:
        for profile in PROFILES:
            if candidate['validation'][profile]['status'] == 'not_run_training_ineligible':
                writer.writerow({'variant_id':candidate['variant_id'],'cost_profile':profile,
                    'phase':'validation','status':'not_run_training_ineligible'})
    states = Counter(a['terminal']['state'] if a['terminal'] else 'pending' for a in attempts)
    lines = ['# Recherche historique — statistiques et limites', '',
        'Arrêt exclusif figé : '+registry.protocol['cutoff']+'. Entraînement : 2023–2024 ; validation : 2025.',
        'Chaque phase et scénario repart d’un portefeuille partagé de 100000 USDT. Le ROI utilise ce capital ; plafond par position : 250 USDT. Les wallets des phases ne sont pas additionnés ; les paires partagent le même wallet.',
        f"Tentatives : {len(attempts)} ; complètes : {states['complete']} ; inconclusives : {states['inconclusive']} ; échouées : {states['failed']}.",
        'Chaque échec, interruption, résultat nul ou négatif reste inscrit. Une validation non lancée conserve une valeur manquante.', '',
        ('Sélection en attente des unités requises.' if pending else
         ('Classement éligible : '+', '.join(r['variant_id'] for r in ranking)+
          ('. Sélection figée ; holdout en attente.' if freeze is not None else '. Sélection à figer avant le holdout.')
          if ranking else 'Aucun candidat éligible. Aucune autorisation de holdout.')), '',
        '| Variante | Entraînement base / adverse | Validation base / adverse |',
        '| --- | --- | --- |']
    for candidate in candidates:
        cells = []
        for phase in WINDOWS:
            descriptions = []
            for profile in PROFILES:
                unit = candidate[phase][profile]
                description = unit['status']
                if unit.get('net_pnl_quote') is not None:
                    description += f" ; n={unit.get('closed_trades',0)}, net={unit['net_pnl_quote']} USDT, R={unit.get('mean_realized_net_r')}, PF={unit.get('net_profit_factor')}"
                descriptions.append(description)
            cells.append(' / '.join(descriptions))
        lines.append('| '+candidate['variant_id']+' | '+' | '.join(cells)+' |')
    lines.extend(['',
        'Les seuils sont des critères de recherche préspecifiés ; ils ne démontrent ni significativité statistique ni rentabilité future.',
        'Échantillons requis : 100 trades d’entraînement, dont 30 par année ; 50 de validation ; au moins 5 paires pour chaque phase et scénario. Un échantillon insuffisant reste inéligible.',
        'Le taux de réussite et le profit factor portent sur les résultats nets. Un dénominateur sans pertes reste indéfini et inéligible.',
        'Le R réalisé net divise le PnL réel par le risque initial du plan. Le drawdown de sélection est celui des capitaux marqués du noyau ; le drawdown du portefeuille de cashflows est distinct.',
        'Les totaux annuels de cashflows suivent leur date UTC ; les trades suivent leur sortie. Le JSON conserve tout écart, notamment au règlement exclusif de minuit.',
        'Les frais sont ceux de Fake/local, et les spreads, glissements, MMR et liquidations sont hypothétiques. Les bougies ne prouvent ni trajectoire intrabougie ni priorité des ordres passifs.',
        'Le funding conserve la provenance observed_only, les lacunes et les hypothèses d’intervalle ; un diagnostic de continuité ne certifie pas une bourse.',
        'La recherche de paramètres entraîne un biais de sélection. Univers choisi en 2026 : biais de survivance et de sélection ; métadonnées actuelles non attestées pour 2023.',
        'Les comptes de sources, règles échouées, admissions, rejets, fills, trades, frais, funding signé et ambiguïtés sont conservés dans les artefacts JSON/CSV.',
        'Les comptes de bougies sources incluent le contexte de chauffe rejoué ; ils sont distincts des minutes calendaires scorées et des lignes uniques d’acquisition.',
        'Durée et ressources : estimation en attente du benchmark réel. Aucune promesse de résultat optimal ou de fin pendant la nuit.',
        'Holdout 2026 : en attente, fermé avant un gel vérifié. Transfert Paper : étape distincte en attente ; aucune activation opérationnelle.', ''])
    payloads = {'json':canonical_bytes(report),'csv':stream.getvalue().encode(),
                'markdown':'\n'.join(lines).encode()}
    size = sum(len(raw) for raw in payloads.values())
    ancestor = report_root.parent
    while not ancestor.exists():
        ancestor = ancestor.parent
    if size > registry.max_output_bytes or shutil.disk_usage(ancestor).free-size < registry.min_free_bytes:
        raise ExperimentError('report_capacity_exceeded')
    report_root.mkdir(parents=True,mode=0o700)
    paths = {'json':report_root/'report.json','csv':report_root/'statistics.csv','markdown':report_root/'rapport-fr.md'}
    for kind,path in paths.items():
        _publish_bytes(path,payloads[kind])
    return paths
