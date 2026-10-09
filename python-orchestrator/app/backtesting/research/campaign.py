"""Verified streaming composition for one historical research campaign unit.

Training and validation only. B1 supplies canonical decisions, persistent PHP
supplies hypothetical geometry, and the incremental kernel supplies a declared
OHLC execution proxy. No network, application database, secrets or orders.
"""
from __future__ import annotations

import argparse
from dataclasses import asdict
from decimal import Decimal
import hashlib
import heapq
from pathlib import Path
import os
import stat
import sys
import time
import uuid

from . import plans, signals
from .campaign_evidence import Evidence, EvidenceError, TERMINAL_STATUS_BYTES, encoded, reconcile
from .funding import FundingReader, REST_HYPOTHESIS
from .portfolio_simulator import (Candle, CostAssumptions, FundingCoverage,
    FundingEvent, InstrumentAssumptions, PortfolioSimulator, RunAssumptions,
    Signal, SYMBOLS, canonical_hash, merge_candles, number)
from .signal_sources import (_ms, _utc, _read_bounded, select_sources, iter_verified_candles)
from .signal_sources import _source_artifact_identity, _verify_bound_source_identity, MAX_MANIFEST_BYTES

MAX_ARTIFACT = 4*1024**3
MAX_REPORT = 16*1024**2
MIN_FREE = 20*1024**3
FUNDING_DIAGNOSTIC_POLICY = 'verified_window_declared_or_explicit_hypothesis_continuity_1000ms.v1'


class CampaignError(RuntimeError):
    """A failed input, worker or reconciliation invalidates the whole unit."""


def _deadline(deadline):
    if time.monotonic() >= deadline:
        raise CampaignError('campaign wall timeout')


def _read(path: Path, cap: int) -> bytes:
    if not path.is_absolute() or '..' in path.parts:
        raise CampaignError('absolute input path required')
    return _read_bounded(path.parent,path.name,cap)


def _input(path: Path, cap: int) -> tuple[dict, str]:
    raw = _read(path,cap)
    return plans._json(raw),hashlib.sha256(raw).hexdigest()


def _source(selection) -> dict:
    return {'dataset_id':'research-'+selection.dataset_sha256[:24],
        'dataset_sha256':selection.dataset_sha256,'source_venue':'binance_usdm',
        'source_network':'mainnet','market_type':'perpetual'}


def _opened(selection, meta) -> dict:
    return {'schema_version':'research-signal-opened.v1',
        'session_id':f'research-{selection.symbol}-{selection.dataset_sha256[:20]}',
        'source':_source(selection),'execution':meta['execution'],'baseline':meta['baseline'],
        'effective_config_snapshot':meta['effective_config_snapshot'],
        'indicator_engine_version':meta['indicator_engine_version']}


def _scored_count(selection) -> int:
    first = signals._first_evaluable_ms(selection)
    return max(0,(_ms(selection.end)-1-first)//900000+1)


def _b1_code_matches(recorded,current) -> bool:
    logical = ('app/backtesting/research/signals.py','app/backtesting/research/signal_sources.py',
               'app/backtesting/research/holdout_authority.py',
               'app/backtesting/research/binance_history.py','app/modern_trading_contracts.py')
    def normalized(items):
        result = {}
        for path,digest in items.items():
            key = next(('python:'+name for name in logical
                        if path.endswith('/python-orchestrator/'+name)),path)
            if key in result:
                return None
            result[key] = digest
        return result
    if not isinstance(recorded,dict):
        return False
    left,right = normalized(recorded),normalized(current)
    return left is not None and right is not None and left == right


def _scan(root,selection,meta,opened,deadline, *, boundary=False):
    """Read every physical scored row, including rule failures, without buffering."""
    name = selection.symbol+('.boundary.ndjson' if boundary else '.results.ndjson')
    path = root/name
    # Validate ancestor/leaf safety before opening the potentially large stream.
    if (not path.is_absolute() or '..' in path.parts or not path.is_file()
        or path.is_symlink() or any(p.is_symlink() for p in path.parents)
        or path.stat().st_size > MAX_ARTIFACT):
        raise CampaignError('signal artifact unsafe or missing')
    digest = hashlib.sha256()
    count = passed = 0
    first = _ms(selection.end) if boundary else signals._first_evaluable_ms(selection)
    with path.open('rb') as stream:
        while raw := stream.readline(signals.MAX_LINE_BYTES+1):
            _deadline(deadline)
            if len(raw) > signals.MAX_LINE_BYTES or not raw.endswith(b'\n'):
                raise CampaignError('signal artifact line framing invalid')
            digest.update(raw)
            frame = plans._json(raw)
            tick = frame.get('evaluated_ms')
            if (type(tick) is not int or tick != first+count*900000
                or (tick != _ms(selection.end) if boundary else not _ms(selection.score_start) <= tick < _ms(selection.end))
                or type(frame.get('passed')) is not bool):
                raise CampaignError('signal artifact chronology or verdict invalid')
            signals._identity(frame,opened,opened['session_id'],selection)
            signals._validate_result(frame,tick)
            if frame['passed'] and (frame['reason_code'] != 'setup_rules_passed' or not isinstance(frame['trace'],dict)):
                raise CampaignError('passed signal trace or verdict invalid')
            index = count
            count += 1
            passed += int(frame['passed'])
            yield Signal(index,frame)
    prefix = 'boundary' if boundary else 'result'
    expected_count = meta['boundary_diagnostic_count'] if boundary else meta['scored_evaluations']
    if (digest.hexdigest() != meta[prefix+'_sha256'] or count != expected_count
        or (not boundary and passed != meta['scored_passed_rules'])
        or (boundary and passed != meta['passed_rules']-meta['scored_passed_rules'])):
        raise CampaignError('signal artifact hash or counts conflict')


def _verify_reports(dataset_root, roots, app_dir, symbols, phase, deadline, *, authorization=None):
    contract = None
    if authorization is not None:
        from .holdout_authority import require_claim
        contract = require_claim(authorization, operation='binding', symbols=symbols)
        if (phase != 'holdout' or dataset_root != Path(contract['paths']['dataset_root'])
            or app_dir != Path(contract['paths']['app_dir'])
            or roots != (authorization.output_root/'signals',)):
            raise CampaignError('claimed B1 input scope conflict')
    if not roots or len(roots) > 10 or len(set(roots)) != len(roots):
        raise CampaignError('one to ten distinct B1 report roots required')
    current_code = signals._code_hashes(app_dir,fake_worker=False)
    reports, bindings, windows = [], {}, None
    baseline = None
    for root in roots:
        _deadline(deadline)
        report, report_sha = _input(root/'report.json',MAX_REPORT)
        if (report.get('schema') != 'research-signal-run.v1' or report.get('status') != 'complete'
            or report.get('errors') != [] or not isinstance(report.get('symbols'),dict)
            or not report['symbols'] or not _b1_code_matches(report.get('code_sha256'),current_code)):
            raise CampaignError('B1 report incomplete or code identity conflict')
        this_window = (report['source_start'],report['score_start'],report['source_end'])
        start, score, end = map(lambda v:_ms(_utc(v)),this_window)
        if contract is not None:
            w = contract['window']
            if (this_window != (w['source_start'],w['score_start'],w['end'])
                or set(report['symbols']) != set(symbols) or report['code_sha256'] != current_code):
                raise CampaignError('claimed B1 fixed window or inventory conflict')
        elif (phase not in ('training','validation') or start >= score or score >= end
            or score < (1672531200000 if phase == 'training' else 1735689600000)
            or end > (1735689600000 if phase == 'training' else 1767225600000)
            or (windows is not None and tuple(map(_utc,windows)) != tuple(map(_utc,this_window)))):
            raise CampaignError('B1 source or scored phase window invalid; holdout closed')
        windows = this_window
        for symbol,meta in report['symbols'].items():
            if symbol not in symbols or symbol in bindings:
                raise CampaignError('B1 symbols duplicate or outside selected universe')
            if contract is not None:
                from .signal_sources import select_holdout_sources
                selection = select_holdout_sources(dataset_root, symbol, authorization=authorization)
                if meta.get('baseline') != contract['identities']['baseline']:
                    raise CampaignError('claimed baseline identity conflict')
            else:
                selection = select_sources(dataset_root,report['source_start'],report['source_end'],report['score_start'],symbol)
            expected_scored = _scored_count(selection)
            expected_boundary = selection.expected_evaluations-expected_scored
            counters = ('source_candles','evaluated_ticks','passed_rules','failed_rules',
                'scored_evaluations','scored_passed_rules','scored_failed_rules','boundary_diagnostic_count')
            if (meta.get('status') != 'complete'
                or any(type(meta.get(k)) is not int or meta[k] < 0 for k in counters)
                or meta['source_candles'] != selection.expected_candles
                or meta['evaluated_ticks'] != selection.expected_evaluations
                or meta['scored_evaluations'] != expected_scored
                or meta['boundary_diagnostic_count'] != expected_boundary
                or meta['passed_rules']+meta['failed_rules'] != selection.expected_evaluations
                or meta['scored_passed_rules']+meta['scored_failed_rules'] != expected_scored
                or meta['dataset_sha256'] != selection.dataset_sha256
                or meta['manifest_sha256'] != selection.manifest_sha256):
                raise CampaignError('B1 report source or denominator conflict')
            opened = _opened(selection,meta)
            signals._validate_opened(opened,opened['session_id'],selection,app_dir)
            if baseline is not None and baseline != meta['baseline']:
                raise CampaignError('B1 baseline differs across symbols or reports')
            baseline = meta['baseline']
            for _ in _scan(root,selection,meta,opened,deadline):
                pass
            # Boundary is diagnostic evidence only and is never a scored stream.
            if expected_boundary or (root/(symbol+'.boundary.ndjson')).exists():
                for _ in _scan(root,selection,meta,opened,deadline,boundary=True):
                    pass
            run = {'symbol':symbol,'source':_source(selection),'signal_session_id':opened['session_id'],
                'signal_run_id':'b1-'+report_sha[:24],'signal_output_sha256':meta['result_sha256'],
                'start_ms':start,'score_start_ms':score,'end_ms':end}
            bindings[symbol] = (root,selection,meta,opened,run)
        totals = report.get('totals',{})
        expected = {'completed_symbols':len(report['symbols']),
            'source_candles':sum(m['source_candles'] for m in report['symbols'].values()),
            'evaluated_ticks':sum(m['evaluated_ticks'] for m in report['symbols'].values()),
            'scored_evaluations':sum(m['scored_evaluations'] for m in report['symbols'].values())}
        if any(totals.get(k) != v or type(totals.get(k)) is not int for k,v in expected.items()):
            raise CampaignError('B1 report aggregate counts conflict')
        reports.append({'root':str(root),'report_sha256':report_sha,
                        'symbols':list(report['symbols']),'code_sha256':report['code_sha256']})
    if set(bindings) != set(symbols):
        raise CampaignError('B1 reports missing selected symbols')
    return reports,bindings,windows,current_code


def _kernel_costs(snapshot,profile) -> CostAssumptions:
    config = snapshot['config']
    execution = config['setup']['ast']['execution']
    contract = execution['cost_contract']['value']
    fees = config['exchange']['fees']
    if (contract['entry_liquidity_role'] != 'maker' or contract['stop_liquidity_role'] != 'taker'
        or contract['funding_interval_seconds'] != 28800
        or config['exchange']['funding']['interval'] != 'PT8H'
        or execution['time_stop']['value'] != 'PT8H'):
        raise CampaignError('baseline cost or holding policy differs')
    target = execution['targets']['value']
    if len(target) != 1 or target[0]['liquidity_role'] not in ('maker','taker'):
        raise CampaignError('baseline target fee role invalid')
    return CostAssumptions(number(fees['maker_rate']),number(fees['taker_rate']),
        number(fees[target[0]['liquidity_role']+'_rate']),
        *(number(profile[key]) for key in ('entry_spread_rate','stop_spread_rate','target_spread_rate',
            'entry_slippage_rate','stop_slippage_rate','target_slippage_rate','funding_provision_rate')),
        contract['funding_interval_seconds'],1)


def _kernel_instruments(manifest,symbols):
    rows = {row['symbol']:row for row in manifest['symbols']}
    return tuple((s,InstrumentAssumptions(*(number(rows[s][key]) for key in
        ('tick_size','quantity_step','min_quantity','max_quantity','min_notional','contract_size',
         'leverage_cap','mmr_proxy_rate','liquidation_fee_rate')),
        manifest['retrieved_at'],manifest['raw_sha256'])) for s in symbols)


def _funding_coverage(inventory,symbols):
    full = (inventory.coverage == 'observed_only' and inventory.evidence_complete
        and tuple(q.symbol for q in inventory.symbols) == symbols
        and all(q.evidence_complete and q.continuity in ('declared_interval_consistent','assumed_interval')
                for q in inventory.symbols))
    payload = asdict(inventory)
    digest = payload.pop('inventory_hash')
    if canonical_hash(payload) != digest:
        raise CampaignError('funding inventory hash conflict')
    return FundingCoverage('verified_complete' if full else 'observed_only',digest,
                           tuple((q.symbol,q.count) for q in inventory.symbols))


class _Clock:
    def __init__(self, stream, timestamp):
        self.stream = iter(stream)
        self.timestamp = timestamp
        self.next = next(self.stream,None)

    def through(self,boundary):
        values = []
        while self.next is not None and self.timestamp(self.next) <= boundary:
            values.append(self.next)
            self.next = next(self.stream,None)
        return tuple(values)

    def exhausted(self):
        if self.next is not None:
            raise CampaignError('unconsumed signal or funding outside phase')


def _runner_code():
    paths = [Path(__file__).with_name(name+'.py') for name in
             ('campaign','campaign_evidence','plans','portfolio_simulator','funding','signals','signal_sources','binance_history','source_complements')]
    paths.extend(Path(__file__).with_name(name) for name in
                 ('statistics.py','holdout.py','holdout_authority.py','frozen_verifier_inventory.json',
                  'orchestration.py','experiments.py'))
    paths.append(Path(__file__).resolve().parents[2]/'modern_trading_contracts.py')
    # Task3 supplies the controller; ordinary pre-holdout callers remain usable
    # while a claim's stricter preflight requires the complete inventory.
    return {str(p):signals._file_sha256(p) for p in paths if p.exists()}


def _holdout_metadata(authorization, binding, *, include_binding=False):
    from .holdout_authority import canonical_bytes, hash_bytes
    result = {'phase':'holdout', 'authority_hash':authorization.authority_hash,
        'contract_hash':authorization.contract_hash, 'claim_hash':authorization.claim_hash,
        'unit_input_binding_sha256':hash_bytes(canonical_bytes(binding))}
    if include_binding:
        result['unit_input_binding'] = binding
    return result


def _unit_inputs(authorization, variant_id, cost_profile, reports, bindings, windows,
                 instruments, instrument_sha, costs, cost_sha, inventory, php, runner):
    """The sole canonical unit binding schema; only verified metadata enters it."""
    from .holdout_authority import require_claim
    import json
    contract = require_claim(authorization, operation='binding', variant_id=variant_id,
        cost_profile=cost_profile)
    symbols = tuple(contract['symbols'])
    variant = plans.variant_selection(variant_id,bindings[symbols[0]][2]['baseline'])
    kernel = _kernel_costs(bindings[symbols[0]][2]['effective_config_snapshot'],costs['profiles'][cost_profile])
    coverage = _funding_coverage(inventory,symbols)
    baseline = bindings[symbols[0]][2]['baseline']
    identity = {'base_setup_hash':baseline['setup_hash'],'base_config_hash':baseline['config_hash'],
        'base_catalog_hash':baseline['condition_catalog_hash'],'base_snapshot_hash':baseline['snapshot_hash'],
        'variant_id':variant_id,'variant_hash':variant['variant_hash'],
        'instrument_assumptions_hash':instruments['manifest_hash'],'cost_assumptions_hash':costs['assumption_hash'],
        'research_code_hash':canonical_hash(php)}
    runs = [bindings[s][4] for s in symbols]
    sources = {r['symbol']:{k:(r['source'][k] if k.startswith('dataset_') else r[k]) for k in
        ('dataset_id','dataset_sha256','signal_run_id','signal_output_sha256')} for r in runs}
    document = {'schema_version':'research-holdout-unit-inputs.v1',
        'authority_hash':authorization.authority_hash,'contract_hash':authorization.contract_hash,
        'claim_hash':authorization.claim_hash,'variant_id':variant_id,'cost_profile':cost_profile,
        'phase':'holdout','window':contract['window'],'symbols':contract['symbols'],
        'risk_assumptions':contract['risk_assumptions'],'baseline':baseline,'variant':variant,
        'b1_counters':{key:sum(bindings[s][2][field] for s in symbols) for key,field in
            (('evaluated_ticks','scored_evaluations'),('passed_rules','scored_passed_rules'),
             ('failed_rules','scored_failed_rules'))},
        'source_candles':sum(bindings[s][1].expected_candles for s in symbols),
        'phase_batches':(_ms(_utc(windows[2]))-_ms(_utc(windows[1])))//60000,
        'signal_reports':reports,'source_runs':runs,'source_manifest_sha256':inventory.acquisition_manifest_sha256,
        'instrument_assumptions':instruments,'instrument_file_sha256':instrument_sha,
        'cost_assumptions':costs,'cost_file_sha256':cost_sha,'kernel_costs':kernel.wire(),
        'kernel_instruments':{s:asdict(i) for s,i in _kernel_instruments(instruments,symbols)},
        'funding_inventory':asdict(inventory),'funding_inventory_hash':inventory.inventory_hash,
        'funding_coverage':asdict(coverage),'funding_diagnostic_policy':FUNDING_DIAGNOSTIC_POLICY,
        'funding_path_policy':'adverse_possible_credit_certain.v1','funding_mark_policy':'last_known_close.v1',
        'php_code_inventory':php,'runner_code_sha256':runner,'identity':identity,'sources':sources}
    # Evidence's Decimal serialization is the canonical retained representation.
    return json.loads(encoded(document))


def _binding_paths(authorization, contract, variant_id, cost_profile):
    slot = next(s for s in contract['slots'] if (s['variant_id'],s['cost_profile']) == (variant_id,cost_profile))
    path = Path(slot['input_binding_path'])
    expected = authorization.output_root/'inputs'/variant_id/(cost_profile+'.json')
    if path != expected or Path(slot['output_root']) != authorization.output_root/'units'/variant_id/cost_profile:
        raise CampaignError('claimed unit binding path conflict')
    return path, authorization.anchor/'inputs'/variant_id/(cost_profile+'.json')


def build_holdout_unit_inputs(signal_root: Path, *, authorization, variant_id: str,
                              cost_profile: str) -> dict:
    """Construct inputs from actual verified metadata, before any unit PnL."""
    from .holdout_authority import require_claim, remaining_limits
    contract = require_claim(authorization,operation='binding',variant_id=variant_id,cost_profile=cost_profile)
    p = contract['paths']; symbols = tuple(contract['symbols'])
    limits = remaining_limits(authorization)
    deadline = time.monotonic()+limits['seconds']
    reports,bindings,windows,_ = _verify_reports(Path(p['dataset_root']), (Path(signal_root),),
        Path(p['app_dir']),symbols,'holdout',deadline,authorization=authorization)
    _,_,raw_manifest = _source_artifact_identity(Path(p['dataset_root']),'manifest.json',
        MAX_MANIFEST_BYTES,deadline,capture=True)
    for symbol in symbols:
        selection = bindings[symbol][1]
        _verify_bound_source_identity(Path(p['dataset_root']),raw_manifest,selection.start,selection.end,
            selection.score_start,symbol,selection.dataset_sha256,deadline)
    instruments, instrument_sha = _input(Path(p['instrument_path']), MAX_REPORT)
    costs, cost_sha = _input(Path(p['cost_path']), MAX_REPORT)
    for document,field in ((instruments,'manifest_hash'),(costs,'assumption_hash')):
        if canonical_hash({k:v for k,v in document.items() if k != field}) != document.get(field):
            raise CampaignError('assumption manifest hash conflict')
    reader = FundingReader(Path(p['dataset_root']),windows[1],windows[2],symbols=symbols,
        supplement_root=Path(p['funding_supplement_root']) if p['funding_supplement_root'] else None,
        rest_interval_hypothesis=REST_HYPOTHESIS)
    inventory = reader.inventory
    if (inventory.start_ms != _ms(_utc(windows[1])) or inventory.end_exclusive_ms != _ms(_utc(windows[2]))
        or any(bindings[s][1].manifest_sha256 != inventory.acquisition_manifest_sha256 for s in symbols)):
        raise CampaignError('funding inventory window conflict')
    binding = _unit_inputs(authorization,variant_id,cost_profile,reports,bindings,windows,instruments,
        instrument_sha,costs,cost_sha,inventory,plans.code_inventory(Path(p['app_dir'])),_runner_code())
    _validate_binding_scope(binding, authorization, contract)
    return binding


def publish_holdout_unit_inputs(signal_root: Path, *, authorization, variant_id: str,
                                cost_profile: str) -> dict:
    """Build and publish once, anchored by an immutable hash under the claim.

    An interrupted publication remains ambiguous and cannot be repaired/retried.
    Callers never supply an unverified binding mapping.
    """
    from .holdout_authority import require_claim, remaining_limits, canonical_bytes, hash_bytes, _publish_bytes, _safe_path
    contract = require_claim(authorization,operation='binding',variant_id=variant_id,cost_profile=cost_profile)
    path, marker = _binding_paths(authorization,contract,variant_id,cost_profile)
    if path.exists() or path.is_symlink() or marker.exists() or marker.is_symlink():
        raise CampaignError('unit input publication already exists or interrupted')
    binding = build_holdout_unit_inputs(signal_root,authorization=authorization,
        variant_id=variant_id,cost_profile=cost_profile)
    raw = canonical_bytes(binding)
    marker_raw = canonical_bytes({'schema_version':'research-holdout-unit-input-anchor.v1',
        'authority_hash':authorization.authority_hash,'contract_hash':authorization.contract_hash,
        'claim_hash':authorization.claim_hash,'variant_id':variant_id,'cost_profile':cost_profile,
        'input_binding_path':str(path),'input_binding_sha256':hash_bytes(raw)})
    limits = remaining_limits(authorization)
    if len(raw) > contract['limits']['metadata_bytes'] or len(raw)+len(marker_raw) > limits['bytes']:
        raise CampaignError('unit input metadata capacity exceeded')
    def private_directory(directory, boundary):
        if directory.exists() or directory.is_symlink():
            _safe_path(directory,directory=True,private=True)
            return
        if directory == boundary:
            raise CampaignError('claimed output directory missing')
        private_directory(directory.parent,boundary)
        directory.mkdir(mode=0o700)
        _safe_path(directory,directory=True,private=True)
    private_directory(path.parent,authorization.output_root)
    private_directory(marker.parent,authorization.anchor)
    _publish_bytes(path,raw)
    _publish_bytes(marker,marker_raw)
    return binding


def _validate_binding_scope(binding, authorization, contract):
    from .holdout_authority import RISK_ASSUMPTIONS
    ident, w = contract['identities'],contract['window']
    for key,value in {'schema_version':'research-holdout-unit-inputs.v1','phase':'holdout',
        'authority_hash':authorization.authority_hash,'contract_hash':authorization.contract_hash,
        'claim_hash':authorization.claim_hash,'window':w,'symbols':contract['symbols'],
        'risk_assumptions':dict(RISK_ASSUMPTIONS),'baseline':ident['baseline'],
        'source_manifest_sha256':ident['source_manifest_sha256'],
        'instrument_file_sha256':ident['instrument_sha256'],'cost_file_sha256':ident['cost_sha256'],
        'php_code_inventory':ident['php'],'runner_code_sha256':ident['new_code'],
        'funding_diagnostic_policy':FUNDING_DIAGNOSTIC_POLICY,
        'funding_path_policy':'adverse_possible_credit_certain.v1','funding_mark_policy':'last_known_close.v1'}.items():
        if binding.get(key) != value:
            raise CampaignError('unit input contract scope conflict: '+key)
    selected = next(v for v in contract['variants'] if v['id'] == binding['variant_id'])
    variant = plans.variant_selection(selected['id'],ident['baseline'])
    if (binding['variant'] != variant or (variant['diff'] or []) != (selected['diff'] or [])
        or binding['cost_profile'] not in contract['cost_profiles']):
        raise CampaignError('unit input variant or cost profile conflict')
    instruments,costs = binding['instrument_assumptions'],binding['cost_assumptions']
    for doc,field in ((instruments,'manifest_hash'),(costs,'assumption_hash')):
        if canonical_hash({k:v for k,v in doc.items() if k != field}) != doc[field]:
            raise CampaignError('unit input assumption hash conflict')
    pi = ident['protocol_identity']; profile = binding['cost_profile']
    if (instruments['manifest_hash'] != pi['instrument_assumptions_hash']
        or canonical_hash(costs['profiles'][profile]) != pi[profile+'_cost_hash']
        or binding['identity']['research_code_hash'] != canonical_hash(ident['php'])):
        raise CampaignError('unit input frozen identity conflict')
    funding = binding['funding_inventory']
    if (canonical_hash({k:v for k,v in funding.items() if k != 'inventory_hash'}) != funding['inventory_hash']
        or binding['funding_inventory_hash'] != funding['inventory_hash']
        or funding['start_ms'] != _ms(_utc(w['score_start'])) or funding['end_exclusive_ms'] != w['end_ms']
        or funding['acquisition_manifest_sha256'] != ident['source_manifest_sha256']):
        raise CampaignError('unit input funding identity conflict')


def validate_holdout_unit_inputs(*, authorization, variant_id: str, cost_profile: str,
                                 operation: str) -> dict:
    """Read-only validation anchored to the once-published binding, before PnL.

    This deliberately never invokes source selection or workers, permitting a
    retained verification capability without granting evaluation authority.
    """
    from .holdout_authority import require_claim, canonical_bytes, hash_bytes, _read_json, remaining_limits
    contract = require_claim(authorization,operation=operation,variant_id=variant_id,cost_profile=cost_profile)
    deadline = time.monotonic()+remaining_limits(authorization)['seconds']
    path, marker_path = _binding_paths(authorization,contract,variant_id,cost_profile)
    binding,raw = _read_json(path,contract['limits']['metadata_bytes'],private=True)
    marker,_ = _read_json(marker_path,contract['limits']['metadata_bytes'],private=True)
    expected = {'schema_version':'research-holdout-unit-input-anchor.v1',
        'authority_hash':authorization.authority_hash,'contract_hash':authorization.contract_hash,
        'claim_hash':authorization.claim_hash,'variant_id':variant_id,'cost_profile':cost_profile,
        'input_binding_path':str(path),'input_binding_sha256':hash_bytes(raw)}
    if marker != expected or binding.get('variant_id') != variant_id or binding.get('cost_profile') != cost_profile:
        raise CampaignError('unit input immutable anchor conflict')
    _validate_binding_scope(binding,authorization,contract)
    p = contract['paths']; ident = contract['identities']
    source_digest,_,raw_manifest = _source_artifact_identity(Path(p['dataset_root']),'manifest.json',
        MAX_MANIFEST_BYTES,deadline,capture=True)
    if source_digest!=ident['source_manifest_sha256']:
        raise CampaignError('unit input retained file identity conflict')
    for name,digest in ((p['instrument_path'],ident['instrument_sha256']),
                        (p['cost_path'],ident['cost_sha256'])):
        if hashlib.sha256(_read(Path(name),64*1024**2)).hexdigest() != digest:
            raise CampaignError('unit input retained file identity conflict')
    if _runner_code() != binding['runner_code_sha256'] or plans.code_inventory(Path(p['app_dir'])) != ident['php']:
        raise CampaignError('unit input retained code identity conflict')
    reports = binding['signal_reports']
    if len(reports) != 1 or reports[0]['root'] != str(authorization.output_root/'signals'):
        raise CampaignError('unit input retained B1 root conflict')
    report, digest = _input(Path(reports[0]['root'])/'report.json',MAX_REPORT)
    if (digest != reports[0]['report_sha256'] or set(report['symbols']) != set(contract['symbols'])
        or list(report['symbols']) != reports[0]['symbols']
        or report['code_sha256'] != reports[0]['code_sha256']
        or report['code_sha256'] != signals._code_hashes(Path(p['app_dir']),fake_worker=False)):
        raise CampaignError('unit input retained B1 identity conflict')
    _validate_retained_metadata(binding,contract,report,digest,deadline,raw_manifest)
    # Provenance metadata can be revalidated in verify mode; no funding events,
    # candles, signal rows, planner or simulator are generated here.
    reader = FundingReader(Path(p['dataset_root']),contract['window']['score_start'],contract['window']['end'],
        symbols=tuple(contract['symbols']),supplement_root=Path(p['funding_supplement_root']) if p['funding_supplement_root'] else None,
        rest_interval_hypothesis=REST_HYPOTHESIS)
    import json
    if json.loads(encoded(asdict(reader.inventory))) != binding['funding_inventory']:
        raise CampaignError('unit input retained funding identity conflict')
    return binding


def _artifact_identity(path,deadline):
    """Bounded read-only signal hash/count, with no-follow and the shared clock."""
    from .holdout_authority import _safe_path
    _safe_path(path,private=True)
    fd = os.open(path,os.O_RDONLY|os.O_NOFOLLOW)
    digest, size, count = hashlib.sha256(),0,0
    with os.fdopen(fd,'rb') as stream:
        info = os.fstat(stream.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_size > MAX_ARTIFACT:
            raise CampaignError('retained signal artifact type or size conflict')
        while block := stream.read(1024**2):
            _deadline(deadline)
            size += len(block)
            if size > MAX_ARTIFACT:
                raise CampaignError('retained signal artifact capacity exceeded')
            digest.update(block); count += block.count(b'\n')
    return digest.hexdigest(),count


def _validate_retained_metadata(binding,contract,report,report_sha,deadline,raw_manifest):
    """Reconstruct the unit's immutable expected inputs from hashed metadata.

    No source selector, candle/signal row iterator, worker or simulation is used.
    The anchor binds the actual source subsets established during publication;
    their source/session/output links are independently reconstructed here.
    """
    import json
    from app.modern_trading_contracts import CanonicalEffectiveConfigSnapshot
    w, symbols, baseline = contract['window'],contract['symbols'],contract['identities']['baseline']
    if (report.get('schema') != 'research-signal-run.v1' or report.get('status') != 'complete'
        or report.get('errors') != [] or (report.get('source_start'),report.get('score_start'),report.get('source_end'))
        != (w['source_start'],w['score_start'],w['end'])):
        raise CampaignError('unit input retained B1 scope conflict')
    runs = []
    for symbol in symbols:
        meta = report['symbols'][symbol]
        snapshot = meta['effective_config_snapshot']
        verified = CanonicalEffectiveConfigSnapshot.model_validate(snapshot)
        if (meta['baseline'] != baseline or meta['manifest_sha256'] != contract['identities']['source_manifest_sha256']
            or not signals._hash_shape(meta['dataset_sha256'],prefix=False)
            or not verified.executable or verified.blockers
            or any(snapshot[key] != baseline[key] for key in ('config_hash','condition_catalog_hash','snapshot_hash'))):
            raise CampaignError('unit input retained baseline or source identity conflict')
        selection = _verify_bound_source_identity(Path(contract['paths']['dataset_root']),raw_manifest,
            _utc(w['source_start']),_utc(w['end']),_utc(w['score_start']),symbol,meta['dataset_sha256'],deadline)
        if selection.expected_candles!=meta['source_candles']:
            raise CampaignError('unit input retained source denominator conflict')
        root = Path(binding['signal_reports'][0]['root'])
        if _artifact_identity(root/(symbol+'.results.ndjson'),deadline) != (meta['result_sha256'],meta['scored_evaluations']):
            raise CampaignError('unit input retained signal artifact identity conflict')
        boundary = root/(symbol+'.boundary.ndjson')
        if meta['boundary_diagnostic_count'] or boundary.exists() or boundary.is_symlink():
            if _artifact_identity(boundary,deadline) != (meta['boundary_sha256'],meta['boundary_diagnostic_count']):
                raise CampaignError('unit input retained boundary artifact identity conflict')
        source = {'dataset_id':'research-'+meta['dataset_sha256'][:24],'dataset_sha256':meta['dataset_sha256'],
            'source_venue':'binance_usdm','source_network':'mainnet','market_type':'perpetual'}
        runs.append({'symbol':symbol,'source':source,
            'signal_session_id':f"research-{symbol}-{meta['dataset_sha256'][:20]}",
            'signal_run_id':'b1-'+report_sha[:24],'signal_output_sha256':meta['result_sha256'],
            'start_ms':_ms(_utc(w['source_start'])),'score_start_ms':_ms(_utc(w['score_start'])),
            'end_ms':w['end_ms']})
    sources = {r['symbol']:{k:(r['source'][k] if k.startswith('dataset_') else r[k]) for k in
        ('dataset_id','dataset_sha256','signal_run_id','signal_output_sha256')} for r in runs}
    if binding['source_runs'] != runs or binding['sources'] != sources:
        raise CampaignError('unit input retained source links conflict')
    instruments,costs,variant = binding['instrument_assumptions'],binding['cost_assumptions'],binding['variant']
    identity = {'base_setup_hash':baseline['setup_hash'],'base_config_hash':baseline['config_hash'],
        'base_catalog_hash':baseline['condition_catalog_hash'],'base_snapshot_hash':baseline['snapshot_hash'],
        'variant_id':variant['id'],'variant_hash':variant['variant_hash'],
        'instrument_assumptions_hash':instruments['manifest_hash'],'cost_assumptions_hash':costs['assumption_hash'],
        'research_code_hash':canonical_hash(contract['identities']['php'])}
    if binding['identity'] != identity:
        raise CampaignError('unit input retained ledger identity conflict')
    kernel = _kernel_costs(report['symbols'][symbols[0]]['effective_config_snapshot'],costs['profiles'][binding['cost_profile']])
    expected_instruments = json.loads(encoded({s:asdict(i) for s,i in _kernel_instruments(instruments,tuple(symbols))}))
    if (binding['kernel_costs'] != kernel.wire()
        or binding['kernel_instruments'] != expected_instruments):
        raise CampaignError('unit input retained exact kernel assumptions conflict')
    counters = {key:sum(report['symbols'][s][field] for s in symbols) for key,field in
        (('evaluated_ticks','scored_evaluations'),('passed_rules','scored_passed_rules'),
         ('failed_rules','scored_failed_rules'))}
    if (binding['b1_counters'] != counters
        or binding['source_candles'] != sum(report['symbols'][s]['source_candles'] for s in symbols)
        or binding['phase_batches'] != (w['end_ms']-_ms(_utc(w['score_start'])))//60000):
        raise CampaignError('unit input retained physical denominators conflict')
    funding = binding['funding_inventory']
    full = (funding['coverage'] == 'observed_only' and funding['evidence_complete'] is True
        and [row['symbol'] for row in funding['symbols']] == symbols
        and all(row['evidence_complete'] is True and row['continuity'] in ('declared_interval_consistent','assumed_interval')
                for row in funding['symbols']))
    coverage = {'status':'verified_complete' if full else 'observed_only','evidence_hash':funding['inventory_hash'],
                'expected_counts':[[row['symbol'],row['count']] for row in funding['symbols']]}
    if binding['funding_coverage'] != coverage:
        raise CampaignError('unit input retained funding coverage conflict')


def run_campaign(dataset_root: Path, signal_root: Path | tuple[Path,...], output_root: Path,
                 app_dir: Path, phase: str, variant_id: str, cost_profile: str,
                 instrument_path: Path, cost_path: Path, *, symbols: tuple[str,...] = SYMBOLS,
                 funding_supplement_root: Path | None = None,
                 wall_timeout: float = 6*3600, io_timeout: float = 60,
                 max_output_bytes: int = MAX_ARTIFACT, min_free_bytes: int = MIN_FREE) -> dict:
    return _run_campaign_core(dataset_root, signal_root, output_root, app_dir, phase,
        variant_id, cost_profile, instrument_path, cost_path, symbols=symbols,
        funding_supplement_root=funding_supplement_root, wall_timeout=wall_timeout,
        io_timeout=io_timeout, max_output_bytes=max_output_bytes, min_free_bytes=min_free_bytes)


def run_holdout_campaign(signal_root: Path, *, authorization, variant_id: str,
                         cost_profile: str) -> dict:
    from .holdout_authority import require_claim, remaining_limits
    contract = require_claim(authorization, operation='simulate', variant_id=variant_id,
        cost_profile=cost_profile)
    if Path(signal_root) != authorization.output_root/'signals':
        raise CampaignError('claimed signal root conflict')
    binding = validate_holdout_unit_inputs(authorization=authorization, variant_id=variant_id,
        cost_profile=cost_profile, operation='simulate')
    limits = remaining_limits(authorization)
    p, cap = contract['paths'], contract['limits']
    output = authorization.output_root/'units'/variant_id/cost_profile
    return _run_campaign_core(Path(p['dataset_root']), Path(signal_root), output,
        Path(p['app_dir']), 'holdout', variant_id, cost_profile,
        Path(p['instrument_path']), Path(p['cost_path']), symbols=tuple(contract['symbols']),
        funding_supplement_root=Path(p['funding_supplement_root']) if p['funding_supplement_root'] else None,
        wall_timeout=min(cap['unit_seconds'],limits['seconds']), io_timeout=60,
        max_output_bytes=min(cap['unit_bytes'], limits['bytes']), min_free_bytes=cap['disk_reserve_bytes'],
        authorization=authorization, input_binding=binding)


def _run_campaign_core(dataset_root: Path, signal_root: Path | tuple[Path,...], output_root: Path,
                 app_dir: Path, phase: str, variant_id: str, cost_profile: str,
                 instrument_path: Path, cost_path: Path, *, symbols: tuple[str,...] = SYMBOLS,
                 funding_supplement_root: Path | None = None,
                 wall_timeout: float = 6*3600, io_timeout: float = 60,
                 max_output_bytes: int = MAX_ARTIFACT, min_free_bytes: int = MIN_FREE,
                 authorization=None, input_binding=None) -> dict:
    roots = (Path(signal_root),) if isinstance(signal_root,(str,Path)) else tuple(map(Path,signal_root))
    dataset_root, app_dir = Path(dataset_root),Path(app_dir)
    instrument_path,cost_path = Path(instrument_path),Path(cost_path)
    run_id = 'campaign-'+uuid.uuid4().hex
    deadline = time.monotonic()+wall_timeout
    exclusions = (dataset_root,*roots,instrument_path.parent,cost_path.parent)
    if funding_supplement_root is not None:
        exclusions += (Path(funding_supplement_root),)
    manifest_sha = None
    attempt = {'schema_version':'research-campaign-attempt.v1','run_id':run_id,
        'phase':phase,'variant_id':variant_id,'cost_profile':cost_profile,
        'dataset_root':str(dataset_root),'signal_roots':list(map(str,roots)),
        'app_dir':str(app_dir),'instrument_path':str(instrument_path),'cost_path':str(cost_path),
        'symbol_priority':symbols}
    with Evidence(Path(output_root),exclusions,max_bytes=max_output_bytes,min_free_bytes=min_free_bytes,
                  initial_bytes=len(encoded(attempt))) as evidence:
        worker = None
        try:
            evidence.json('attempt.json',attempt)
            if (wall_timeout <= 0 or io_timeout <= 0
                or (phase not in ('training','validation') if authorization is None else phase != 'holdout')
                or symbols != tuple(s for s in SYMBOLS if s in symbols) or not symbols):
                raise CampaignError('campaign phase, universe or limits invalid; holdout closed')
            if authorization is None:
                reports,bindings,windows,b1_code = _verify_reports(dataset_root,roots,app_dir,symbols,phase,deadline)
            else:
                reports,bindings,windows,b1_code = _verify_reports(dataset_root,roots,app_dir,symbols,phase,deadline,
                                                                 authorization=authorization)
            source_start,start,end = map(lambda value:_ms(_utc(value)),windows)
            instruments,instrument_sha = _input(instrument_path,MAX_REPORT)
            costs,cost_sha = _input(cost_path,MAX_REPORT)
            for document,field in ((instruments,'manifest_hash'),(costs,'assumption_hash')):
                if canonical_hash({k:v for k,v in document.items() if k != field}) != document.get(field):
                    raise CampaignError('assumption manifest hash conflict')
            variant = plans.variant_selection(variant_id,bindings[symbols[0]][2]['baseline'])
            kernel_costs = _kernel_costs(bindings[symbols[0]][2]['effective_config_snapshot'],costs['profiles'][cost_profile])
            kernel_instruments = _kernel_instruments(instruments,symbols)
            reader = FundingReader(dataset_root,windows[1],windows[2],symbols=symbols,
                supplement_root=funding_supplement_root,rest_interval_hypothesis=REST_HYPOTHESIS)
            inventory = reader.inventory
            if (inventory.start_ms != start or inventory.end_exclusive_ms != end
                or any(bindings[s][1].manifest_sha256 != inventory.acquisition_manifest_sha256 for s in symbols)):
                raise CampaignError('funding inventory window conflict')
            coverage = _funding_coverage(inventory,symbols)
            php_inventory = plans.code_inventory(app_dir)
            runner_code = _runner_code()
            counters = {key:sum(bindings[s][2][field] for s in symbols) for key,field in
                (('evaluated_ticks','scored_evaluations'),('passed_rules','scored_passed_rules'),
                 ('failed_rules','scored_failed_rules'))}
            manifest = {'schema_version':'research-campaign-manifest.v1','run_id':run_id,
                'phase':phase,'source_start_ms':source_start,'start_ms':start,'end_ms':end,
                'end_exclusive':True,'symbol_priority':symbols,'variant':variant,'cost_profile':cost_profile,
                'baseline':bindings[symbols[0]][2]['baseline'],'signal_reports':reports,
                'source_runs':[bindings[s][4] for s in symbols],
                'instrument_assumptions':instruments,'instrument_file_sha256':instrument_sha,
                'cost_assumptions':costs,'cost_file_sha256':cost_sha,
                'funding_inventory':asdict(inventory),'funding_inventory_hash':inventory.inventory_hash,
                'funding_diagnostic_policy':FUNDING_DIAGNOSTIC_POLICY,
                'php_code_inventory':php_inventory,'research_code_hash':canonical_hash(php_inventory),
                'runner_code_sha256':runner_code,'app_dir':str(app_dir),
                'b1_python_code_binding_policy':'five_known_logical_modules_exact_content_php_paths_exact.v1',
                'code_identity_check_policy':plans.CODE_POLICY,'code_hash_scope':plans.CODE_SCOPE,
                'execution_authority':'none','initial_wallet_quote':'100000','b1_counters':counters,
                'storage_policy':{'max_bytes':max_output_bytes,'min_free_bytes':min_free_bytes,
                    'terminal_status_reserved_bytes':TERMINAL_STATUS_BYTES},
                'fill_policy':'conservative_closed_ohlc.v1',
                'holding_settlement_policy':'prior_close_exclusive_deadline.v1',
                'funding_path_policy':'adverse_possible_credit_certain.v1',
                'funding_mark_policy':'last_known_close.v1','maximum_campaign_workers':2,
                'instrument_status':'current_metadata_not_historical',
                'cost_status':'fake_local_fees_hypothetical_ohlcv_costs',
                'universe_status':'selected_2026_survivorship_selection_bias', 'resume_supported':False}
            if authorization is not None:
                actual = _unit_inputs(authorization, variant_id, cost_profile, reports, bindings,
                    windows, instruments, instrument_sha, costs, cost_sha, inventory, php_inventory, runner_code)
                if actual != input_binding:
                    raise CampaignError('claimed unit inputs changed before simulation')
                manifest.update(_holdout_metadata(authorization, input_binding, include_binding=True))
                manifest['b1_python_code_binding_policy'] = 'claimed_current_inventory_exact.v1'
            manifest_sha = evidence.json('manifest.json',manifest)
            opening = {'schema_version':'research-plan-open.v1','session_id':run_id,
                'baseline':manifest['baseline'],'source_runs':manifest['source_runs'],'variant':variant,
                'instrument_assumptions':instruments,'cost_assumptions':costs,'cost_profile':cost_profile,
                'expected_signals':counters['passed_rules']}
            worker = plans.PlanWorker(app_dir,opening,wall_timeout=max(.001,deadline-time.monotonic()),
                io_timeout=io_timeout,max_output_bytes=max_output_bytes)
            with worker:
                if worker.opened['research_code_hash'] != manifest['research_code_hash']:
                    raise CampaignError('planner code identity differs from frozen manifest')
                identity = {'base_setup_hash':manifest['baseline']['setup_hash'],
                    'base_config_hash':manifest['baseline']['config_hash'],
                    'base_catalog_hash':manifest['baseline']['condition_catalog_hash'],
                    'base_snapshot_hash':manifest['baseline']['snapshot_hash'],'variant_id':variant_id,
                    'variant_hash':variant['variant_hash'],'instrument_assumptions_hash':instruments['manifest_hash'],
                    'cost_assumptions_hash':costs['assumption_hash'],'research_code_hash':worker.opened['research_code_hash']}
                source_fields = ('dataset_id','dataset_sha256','signal_run_id','signal_output_sha256')
                sources = tuple((s,tuple((key,bindings[s][4]['source'][key] if key.startswith('dataset_') else bindings[s][4][key])
                                         for key in source_fields)) for s in symbols)
                assumptions = RunAssumptions(start,end,phase,symbols,kernel_instruments,kernel_costs,
                    tuple(identity.items()),sources,coverage,manifest['funding_path_policy'],manifest['funding_mark_policy'],
                    authorization)
                def build(signal,portfolio):
                    result = worker.build(signal,portfolio)
                    if result['schema_version'] == 'research-plan.v1':
                        evidence.emit('plans',result)
                    else:
                        evidence.emit('rejections',{'schema_version':'research-planner-rejection-evidence.v1',
                            'symbol':signal.payload['symbol'],'evaluated_ms':signal.payload['evaluated_ms'],
                            'signal_index':signal.index,'portfolio_hash':portfolio['portfolio_hash'],'response':result})
                    return result
                def rejected(row):
                    evidence.emit('rejections',row)
                    # An unexpected kernel validation rejection is an integration failure.
                    if row['reason_code'].startswith('research_plan_'):
                        raise CampaignError('kernel rejected malformed planner plan: '+row['reason_code'])
                simulator = PortfolioSimulator(assumptions,build,
                    event_sink=lambda row:evidence.emit('events',row),
                    trade_sink=lambda row:evidence.emit('trades',row),
                    cashflow_sink=lambda row:evidence.emit('cashflows',row),rejection_sink=rejected,
                    marked_sink=lambda row:evidence.emit('marked-equity',row))
                def passed_signals(symbol):
                    root,selection,meta,opened,_ = bindings[symbol]
                    for signal in _scan(root,selection,meta,opened,deadline):
                        if signal.payload['passed']:
                            yield signal
                signal_clock = _Clock(heapq.merge(*(passed_signals(s) for s in symbols),
                    key=lambda sig:(sig.payload['evaluated_ms'],symbols.index(sig.payload['symbol']))),
                    lambda sig:sig.payload['evaluated_ms'])
                def funding_events():
                    for event in reader.iter_events():
                        evidence.emit('funding-events',asdict(event))
                        yield FundingEvent(event.symbol,event.timestamp_ms,event.rate,event.observed_mark,
                                           event.timestamp_ms if event.observed_mark is not None else None)
                funding_clock = _Clock(funding_events(),lambda event:event.timestamp_ms)
                def candle_stream(symbol):
                    for row in iter_verified_candles(dataset_root,bindings[symbol][1]):
                        yield Candle(symbol,row['open_ms'],*(number(row[key]) for key in ('open','high','low','close')))
                source_candles = phase_batches = 0
                previous = None
                for batch in merge_candles({s:candle_stream(s) for s in symbols},symbols):
                    _deadline(deadline)
                    source_candles += len(batch)
                    if batch[0].open_ms < start:
                        previous = batch
                        continue
                    if not simulator.primed:
                        if previous is None or previous[0].open_ms != start-60000:
                            raise CampaignError('closed prephase minute missing')
                        simulator.prime(previous,funding=funding_clock.through(start),signals=signal_clock.through(start))
                    boundary = batch[0].open_ms+60000
                    simulator.advance(batch,funding=funding_clock.through(min(boundary,end-1)),
                                      signals=signal_clock.through(min(boundary,end-1)))
                    phase_batches += 1
                signal_clock.exhausted();funding_clock.exhausted()
                if source_candles != sum(bindings[s][1].expected_candles for s in symbols):
                    raise CampaignError('candle source count incomplete')
                kernel_summary = simulator.finish()
                planner_summary = worker.close()
                if (simulator.attempted_signals != counters['passed_rules']
                    or planner_summary['received'] != counters['passed_rules']):
                    raise CampaignError('planner or signal counts incomplete')
                if (planner_summary['planned'] != simulator.admitted_plans
                    or kernel_summary['admitted_plans'] != simulator.admitted_plans):
                    raise CampaignError('planner and kernel admission counts differ')
            if (signals._code_hashes(app_dir,fake_worker=False) != b1_code or _runner_code() != runner_code
                or hashlib.sha256(_read(dataset_root/'manifest.json',64*1024**2)).hexdigest() != inventory.acquisition_manifest_sha256
                or _input(instrument_path,MAX_REPORT)[1] != instrument_sha
                or _input(cost_path,MAX_REPORT)[1] != cost_sha
                or any(_input(Path(r['root'])/'report.json',MAX_REPORT)[1] != r['report_sha256'] for r in reports)):
                raise CampaignError('frozen input or code changed during campaign')
            files = evidence.finish_ledgers()
            replay = reconcile(evidence.root,kernel_summary)
            status = kernel_summary['completion']
            summary = {**kernel_summary, 'schema_version':'research-campaign-summary.v1','run_id':run_id,
                'status':status,'manifest_sha256':manifest_sha,'b1_counters':counters,
                'source_candles':source_candles,'phase_batches':phase_batches,
                'funding_events':sum(simulator.funding_counts.values()),
                **{k:v for k,v in replay.items() if k not in ('status','trades')},
                'source_quality':{'candles':'verified_complete','funding_inventory':asdict(inventory),
                    'funding_diagnostic_policy':FUNDING_DIAGNOSTIC_POLICY,
                    'kernel_funding_coverage':coverage.status},
                'planner_summary':planner_summary,'planner_evidence':worker.evidence(),
                'reconciliation':replay,'profit_factor':None,
                'profit_factor_reason':'aggregate_ratios_owned_by_campaign_selection_ledger',
                'average_net_r':None,'average_net_r_reason':'requires_plan_risk_join_in_selection_ledger'}
            summary_sha = hashlib.sha256(encoded(summary)).hexdigest()
            terminal = {'schema_version':'research-campaign-status.v1','run_id':run_id,
                'status':status,'manifest_sha256':manifest_sha,'summary_sha256':summary_sha,'files':files,
                'completion':'input_complete','execution_authority':'none'}
            if authorization is not None:
                terminal.update(_holdout_metadata(authorization, input_binding))
                summary.update(_holdout_metadata(authorization, input_binding))
                terminal['summary_sha256'] = hashlib.sha256(encoded(summary)).hexdigest()
            evidence.check_status(terminal)
            evidence.json('summary.json',summary)
            evidence.json('status.json',terminal)
            return summary
        except Exception as exc:
            message, error_type = str(exc), type(exc).__name__
            failure = {'schema_version':'research-campaign-status.v1','run_id':run_id,'status':'failed',
                'manifest_sha256':manifest_sha,'summary_sha256':None,'execution_authority':'none',
                'error':{'type':error_type[:96],'type_truncated':len(error_type)>96,
                    'message':message[:256],'message_truncated':len(message)>256,
                    'message_sha256':hashlib.sha256(message.encode('utf-8',errors='surrogatepass')).hexdigest()},
                'planner_evidence':worker.evidence() if worker is not None else None}
            if authorization is not None:
                failure.update(_holdout_metadata(authorization,input_binding))
            try:
                evidence.json('status.json',failure)
            except (OSError,EvidenceError):
                pass  # Physical I/O failure can still prevent terminal publication.
            raise CampaignError(message[:256]) from exc


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description='One verified research variant/cost/phase; holdout closed')
    for name in ('dataset-root','signal-root','output-root','app-dir','instrument-path','cost-path'):
        parser.add_argument('--'+name,type=Path,required=True,**({'action':'append'} if name == 'signal-root' else {}))
    parser.add_argument('--phase',choices=('training','validation'),required=True)
    parser.add_argument('--variant-id',choices=tuple(plans.VARIANT_DIFFS),required=True)
    parser.add_argument('--cost-profile',choices=('baseline','adverse'),required=True)
    parser.add_argument('--symbols',default=','.join(SYMBOLS))
    parser.add_argument('--funding-supplement-root',type=Path)
    parser.add_argument('--wall-timeout',type=float,default=6*3600)
    parser.add_argument('--io-timeout',type=float,default=60)
    parser.add_argument('--max-output-bytes',type=int,default=MAX_ARTIFACT)
    parser.add_argument('--min-free-bytes',type=int,default=MIN_FREE)
    args = vars(parser.parse_args(argv))
    args['symbols'] = tuple(args['symbols'].split(','));args['signal_root'] = tuple(args['signal_root'])
    try:
        summary = run_campaign(**args)
    except (CampaignError,EvidenceError) as exc:
        print('research campaign failed: '+str(exc),file=sys.stderr)
        return 1
    print('research campaign '+summary['status']+': '+summary['run_id'])
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
