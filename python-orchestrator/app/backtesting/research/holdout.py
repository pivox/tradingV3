"""Once-only historical holdout controller. Importing grants no authority.

All financial arithmetic belongs to the shared kernel and independently replayed
statistics. Each fixed scenario owns a fresh 100000 quote wallet. No aggregate
return, ranking, executable override, retry, Paper or order authority is provided.
"""
from __future__ import annotations

import argparse
import csv
from decimal import Decimal
import hashlib
import io
import json
import math
import os
from pathlib import Path
import time
import uuid

from . import campaign, holdout_authority as h, signals, statistics
from .experiments import _csv_text
from .orchestration import _wall_guard

OVERALL_SECONDS, OVERALL_BYTES = h.OVERALL_SECONDS, h.OVERALL_BYTES
REPORT_SCHEMA = 'research-holdout-report.v1'
RESULT_SCHEMA = 'research-holdout-unit-result.v1'
TERMINAL_SCHEMA = 'research-holdout-terminal.v1'
REPORT_FILES = ('report.json', 'report.csv', 'report.fr.md')
METRICS = ('closed_trades','wins','losses','breakevens','net_win_rate','net_pnl_quote',
    'gross_pnl_quote','fees_quote','funding_quote','spread_quote','slippage_quote',
    'net_profit_factor','profit_factor_undefined_reason','mean_net_expectancy_quote',
    'mean_realized_net_r','expectancy_undefined_reason','maximum_drawdown_quote',
    'cashflow_wallet_maximum_drawdown_quote','maximum_exposure_quote','roi_wallet_rate')
DELTA_METRICS = ('net_win_rate','closed_trades','net_pnl_quote','net_profit_factor',
    'mean_realized_net_r','maximum_drawdown_quote','maximum_exposure_quote','roi_wallet_rate')


def _private_directory(path):
    path = Path(path)
    if not path.exists(): path.mkdir(mode=0o700)
    h._safe_path(path, directory=True, private=True)
    return path


def _stage(auth, function, *args, **kwargs):
    limits = h.remaining_limits(auth)
    with _wall_guard(min(h.VERIFIER_SECONDS, limits['seconds'])):
        result = function(*args, **kwargs)
    h.remaining_limits(auth)
    return result


def _run_shared_signals(auth):
    # The ten sequential symbols each have a six-hour worker limit. The whole
    # shared B1 generation uses the original overall clock, not six hours total.
    limits = h.remaining_limits(auth)
    with _wall_guard(limits['seconds']):
        report = signals.run_holdout_signals(auth.output_root/'signals',authorization=auth)
    h.remaining_limits(auth)
    return report


def _unit_root(auth, variant_id, cost_profile):
    contract = h.require_claim(auth, operation='report', variant_id=variant_id, cost_profile=cost_profile)
    root = next(Path(s['output_root']) for s in contract['slots']
                if (s['variant_id'],s['cost_profile']) == (variant_id,cost_profile))
    _private_directory(auth.output_root/'units')
    _private_directory(root.parent)
    if root.exists() or root.is_symlink(): raise h.HoldoutError('fresh_unit_output_required')
    return root


def _bind_unit_inputs(auth, variant_id, cost_profile):
    return campaign.publish_holdout_unit_inputs(auth.output_root/'signals', authorization=auth,
        variant_id=variant_id, cost_profile=cost_profile)


def _file_reference(path):
    h._safe_path(path, private=True)
    return {'sha256':h._file_hash(path), 'bytes':path.stat().st_size}


def _unit_result(auth, slot, verified):
    root = Path(slot['output_root'])
    summary, _ = h._read_json(root/'summary.json', h.METADATA_BYTES, private=True, canonical=False)
    return {'schema_version':RESULT_SCHEMA, 'phase':'holdout',
        'authority_hash':auth.authority_hash, 'contract_hash':auth.contract_hash,
        'claim_hash':auth.claim_hash, 'variant_id':slot['variant_id'],
        'cost_profile':slot['cost_profile'], 'output_root':str(root),
        'status':verified['status'], 'independently_verified':True,
        'execution_authority':'none', 'paper_transfer':'distinct_pending',
        'statistics':verified, 'counters':dict(verified['counts']),
        'coverage_quality':verified['source_quality'],
        'b1_counters':summary['b1_counters'],
        'evidence':{name:_file_reference(root/name) for name in ('manifest.json','summary.json','status.json')},
        'input_binding':_file_reference(Path(slot['input_binding_path']))}


def _publish_artifacts(auth, artifacts, *, terminal=False):
    """Reserve all bytes before any immutable publication, including temp links."""
    contract = h.require_claim(auth, operation='report')
    if any(len(raw) > (h.TERMINAL_RESERVE if terminal else h.METADATA_BYTES)
           for _,raw in artifacts): raise h.HoldoutError('report_metadata_capacity_exceeded')
    limits = h._terminal_limits(auth) if terminal else h.remaining_limits(auth)
    available = limits['bytes']
    if sum(len(raw) for _,raw in artifacts) > available:
        raise h.HoldoutError('holdout_report_capacity')
    if h.shutil.disk_usage(auth.output_root.parent).free-sum(len(raw) for _,raw in artifacts) < contract['limits']['disk_reserve_bytes']:
        raise h.HoldoutError('disk_reserve_required')
    for path, raw in artifacts:
        if path.parent != auth.output_root:
            h._safe_path(path.parent, directory=True, private=True)
        try:
            h._publish_bytes(path, raw)
        except BaseException:
            if terminal and path.exists():
                # A final unlink/fsync failure may leave a fully linked terminal.
                # Preserve the primary failure as a no-replace diagnostic link:
                # it consumes no additional file-content bytes and invalidates
                # the complete inventory. Storage failure can also prevent this
                # best-effort marker; no retry or execution authority follows.
                try:
                    with _wall_guard(contract['limits']['cleanup_seconds']):
                        h._safe_path(path,private=True)
                        marker = auth.output_root/('.terminal-publication-failed-'+uuid.uuid4().hex)
                        os.link(path,marker,follow_symlinks=False)
                        h._fsync_directory(marker.parent)
                except BaseException:
                    pass
            raise


def _publish_unit_result(auth, slot, verified):
    result = _unit_result(auth, slot, verified)
    _publish_artifacts(auth, [(Path(slot['output_root'])/'verified-result.json', h.canonical_bytes(result))])
    return result


def _pending_units(contract):
    return [{'variant_id':s['variant_id'], 'cost_profile':s['cost_profile'],
        'output_root':s['output_root'], 'status':'not_run', 'independently_verified':False,
        'statistics':None, 'execution_authority':'none', 'paper_transfer':'distinct_pending'}
        for s in contract['slots']]


def _deltas(contract, units):
    if contract['selected']['id'] == 'baseline': return []
    indexed = {(u['variant_id'],u['cost_profile']):u for u in units}
    rows = []
    for profile in contract['cost_profiles']:
        selected = indexed[(contract['selected']['id'],profile)]
        baseline = indexed[('baseline',profile)]
        a,b = selected.get('statistics'), baseline.get('statistics')
        values = {key:str(Decimal(str(a[key]))-Decimal(str(b[key])))
            if a is not None and b is not None and a.get(key) is not None and b.get(key) is not None
            else None for key in DELTA_METRICS}
        rows.append({'cost_profile':profile, 'selected_variant_id':contract['selected']['id'],
            'baseline_variant_id':'baseline', 'comparison':'selected_minus_baseline_same_cost_scenario',
            'metrics':values})
    return rows


def _report_data(auth, contract, units, *, fatal=None):
    status = 'failed' if fatal is not None else ('complete' if all(
        u['status']=='complete' and u['independently_verified'] and
        u['statistics']['coverage_complete'] and u['statistics']['cost_evidence_complete']
        for u in units) else 'inconclusive')
    identities = contract['identities']
    signal_path = auth.output_root/'signals'/'report.json'
    b1_identity = None
    if signal_path.exists():
        signal_report,_ = h._read_json(signal_path,h.METADATA_BYTES,private=True,canonical=False)
        b1_identity = {'report':_file_reference(signal_path),
            'code_sha256':signal_report['code_sha256'], 'baseline':identities['baseline'],
            'generation_status':signal_report['status']}
    installed = h.load_campaign_authority()
    if installed.authority_hash != auth.authority_hash:
        raise h.HoldoutError('report_authority_conflict')
    return {'schema_version':REPORT_SCHEMA, 'campaign_id':contract['campaign_id'], 'phase':'holdout',
        'holdout_status':status, 'execution_authority':'none', 'paper_transfer':'distinct_pending',
        'authority_hash':auth.authority_hash, 'contract_hash':auth.contract_hash, 'claim_hash':auth.claim_hash,
        'output_root':str(auth.output_root), 'freeze_sha256':contract['freeze_sha256'],
        'original_selection_freeze':contract['freeze'], 'selected':contract['selected'],
        'window':dict(contract['window'], end_exclusive=True, warmup_is_scored=False),
        'symbols':contract['symbols'], 'risk_assumptions':contract['risk_assumptions'],
        'identities':identities,
        'python_transition':{'original_runtime':identities.get('original_runtime'),
            'original_snapshot':identities.get('original_snapshot'), 'recovery':identities.get('recovery'),
            'new_runner_inventory':identities['new_code'],
            'frozen_verifier_python_path':contract['paths']['python'],
            'frozen_verifier_runtime_inventory':json.loads(installed.config_bytes)['runtime'],
            'runner_interpreter':contract['runner_interpreter'],
            'reason':'guarded_claimed_2026_boundary_and_shared_kernel_with_immutable_inputs',
            'new_b1_code_identity':b1_identity},
        'unit_wallet_quote':'100000', 'wallets_independent_per_unit':True,
        'returns_must_not_be_summed_across_scenarios_or_phases':True,
        'units':units, 'matched_scenario_deltas':_deltas(contract,units), 'fatal_error':fatal,
        'limitations':['hypothetical_ohlc_fills_and_close_proxy_marked_equity',
            'current_not_historical_instrument_metadata','survivorship_bias',
            'parameter_selection_bias','observed_only_funding_not_exchange_certified',
            'no_optimum_or_future_profit_claim','paper_transfer_requires_distinct_evidence']}


def _csv(report):
    stream = io.StringIO(newline='')
    fields = ('variant_id','cost_profile','status','independently_verified',*METRICS,
        'coverage_complete','cost_evidence_complete','funding_provenance','scored','passed',
        'failed_rules','admitted','filled','rejected','source_candles','scored_symbol_minutes',
        'per_pair_json','per_year_json','source_quality_json')
    writer = csv.DictWriter(stream,fieldnames=fields,lineterminator='\n')
    writer.writeheader()
    for unit in report['units']:
        stats = unit.get('statistics') or {}
        row = {**unit, **stats, **stats.get('counts',{})}
        for name in ('per_pair','per_year','source_quality'):
            row[name+'_json'] = json.dumps(stats[name],ensure_ascii=False,sort_keys=True,separators=(',',':'),
                allow_nan=False) if name in stats else None
        writer.writerow({key:_csv_text(row.get(key)) for key in fields})
    return stream.getvalue().encode('utf-8')


def _fr_value(value, *, percent=False):
    if value is None: return 'indéfini'
    number = Decimal(str(value))*(100 if percent else 1)
    return format(number, '.2f').replace('.',',')+(' %' if percent else '')


def _markdown(report):
    lines = ['# Holdout historique — campagne '+report['campaign_id'], '',
        'Statut : '+report['holdout_status']+'. Autorité d’exécution : aucune. Paper : distinct_pending.', '',
        'Chaque unité dispose d’un portefeuille indépendant de 100 000 unités de cotation. '
        'Les rendements des scénarios et des phases ne s’additionnent pas.', '',
        'Sélection gelée : '+report['selected']['id']+' ; diff : '+json.dumps(report['selected']['diff'],ensure_ascii=False)+'.',
        'Fenêtre scorée : '+report['window']['score_start']+' → '+report['window']['end']+
        ' (fin exclusive). Warmup non scoré depuis '+report['window']['source_start']+'.', '']
    for u in report['units']:
        lines += ['## '+u['variant_id']+' / '+u['cost_profile'], '', 'Statut : '+u['status']+'.']
        s = u.get('statistics')
        if s is None:
            lines += ['Statistiques indisponibles ; aucun zéro financier n’est imputé.', '']; continue
        lines += ['Trades clôturés : '+str(s['closed_trades'])+' ; taux de gain net : '+_fr_value(s['net_win_rate'],percent=True)+'.',
            'PnL net : '+_fr_value(s['net_pnl_quote'])+' ; frais : '+_fr_value(s['fees_quote'])+
            ' ; funding signé : '+_fr_value(s['funding_quote'])+' ; spread : '+_fr_value(s['spread_quote'])+
            ' ; slippage : '+_fr_value(s['slippage_quote'])+'.',
            'Profit factor net : '+_fr_value(s['net_profit_factor'])+' ('+str(s['profit_factor_undefined_reason'])+
            ') ; espérance R nette : '+_fr_value(s['mean_realized_net_r'])+'.',
            'Drawdown marqué : '+_fr_value(s['maximum_drawdown_quote'])+' ; drawdown du wallet cashflow : '+
            _fr_value(s['cashflow_wallet_maximum_drawdown_quote'])+' ; exposition maximale : '+_fr_value(s['maximum_exposure_quote'])+'.',
            'ROI du portefeuille : '+_fr_value(s['roi_wallet_rate'],percent=True)+'.',
            'Compteurs : '+json.dumps(s['counts'],ensure_ascii=False,sort_keys=True)+'.',
            'Couverture bougies : '+str(s['coverage_complete'])+' ; preuves des coûts : '+str(s['cost_evidence_complete'])+'.', '',
            '| Paire | Trades | Taux de gain net | PnL net | Frais | Funding signé |',
            '| --- | ---: | ---: | ---: | ---: | ---: |']
        for pair, p in s['per_pair'].items():
            lines += ['| '+pair+' | '+str(p['closed_trades'])+' | '+_fr_value(p['net_win_rate'],percent=True)+
                ' | '+_fr_value(p['net_pnl_quote'])+' | '+_fr_value(p['fees_quote'])+' | '+_fr_value(p['funding_quote'])+' |']
        lines += ['', '| Année UTC | Trades | PnL par année de sortie | Cashflows UTC | Écart attribution |',
            '| --- | ---: | ---: | ---: | ---: |']
        for year,p in s['per_year'].items():
            lines += ['| '+year+' | '+str(p['closed_trades'])+' | '+_fr_value(p['net_pnl_quote'])+' | '+
                _fr_value(p['cashflow_net_pnl_quote'])+' | '+_fr_value(p['cashflow_vs_exit_attribution_difference_quote'])+' |']
        lines += ['', 'Qualité des sources : `'+json.dumps(s['source_quality'],ensure_ascii=False,sort_keys=True)+'`.', '']
    if report['matched_scenario_deltas']:
        lines += ['Comparaisons fixes, sélection moins baseline, à scénario de coûts identique :', '']
        for row in report['matched_scenario_deltas']:
            lines += ['- '+row['cost_profile']+' : '+json.dumps(row['metrics'],ensure_ascii=False,sort_keys=True)+'.']
        lines += ['']
    lines += ['Les fills OHLC et l’équité marquée sont hypothétiques. Les métadonnées des instruments sont actuelles, '
        'non historiques. Biais de survivance et de sélection des paramètres ; funding observé uniquement, '
        'sans certification de l’exchange. Aucun optimum, profit futur ou transfert Paper n’est établi.', '']
    return '\n'.join(lines).encode('utf-8')


def _report_artifacts(auth, report):
    return list(zip((auth.output_root/name for name in REPORT_FILES),
        (h.canonical_bytes(report),_csv(report),_markdown(report))))


def _inventory(auth, *, deadline):
    """Hash every retained artifact; never open a link or operational input."""
    items, metadata_bytes = [], 0
    for scope,root in (('anchor',auth.anchor),('output',auth.output_root)):
        if not root.exists(): continue
        h._safe_path(root,directory=True,private=True)
        for directory,dirs,files in os.walk(root,followlinks=False):
            if time.monotonic() >= deadline: raise h.HoldoutError('inventory_deadline')
            for name in dirs: h._safe_path(Path(directory)/name,directory=True,private=True)
            for name in sorted(files):
                path = Path(directory)/name
                if scope=='output' and path == root/'status.json': continue
                h._safe_path(path,private=True)
                digest = hashlib.sha256()
                with path.open('rb') as stream:
                    while chunk := stream.read(1024*1024):
                        if time.monotonic() >= deadline: raise h.HoldoutError('inventory_deadline')
                        digest.update(chunk)
                item = {'scope':scope,'path':str(path.relative_to(root)),
                    'sha256':digest.hexdigest(),'bytes':path.stat().st_size}
                metadata_bytes += len(h.canonical_bytes(item))
                if len(items)>=h.INVENTORY_ENTRIES or metadata_bytes>h.METADATA_BYTES:
                    raise h.HoldoutError('inventory_metadata_capacity')
                items.append(item)
    return sorted(items,key=lambda row:(row['scope'],row['path']))


def _terminal(auth, report, inventory):
    return {'schema_version':TERMINAL_SCHEMA,'campaign_id':report['campaign_id'],
        'authority_hash':auth.authority_hash,'contract_hash':auth.contract_hash,'claim_hash':auth.claim_hash,
        'holdout_status':report['holdout_status'],'execution_authority':'none','paper_transfer':'distinct_pending',
        'reports':{name:_file_reference(auth.output_root/name) for name in REPORT_FILES},
        'units':[{'variant_id':u['variant_id'],'cost_profile':u['cost_profile'],
            'status':u['status'],'independently_verified':u['independently_verified']} for u in report['units']],
        'artifact_inventory':inventory,'fatal_error':report['fatal_error']}


def _failure_inventory(auth, *, deadline):
    """Bounded metadata only. A fragment never attests omitted files or content.

    Scan incrementally (scandir, not an unbounded directory listing). A complete
    scan supplies inode-unique size accounting; an incomplete scan cannot grant
    extra terminal capacity. Only 128 entries/32 KiB can enter the diagnostic.
    """
    fragment, seen = [], set()
    used = scanned = metadata_bytes = fragment_bytes = 0
    truncated = False
    stack = [('output',auth.output_root),('anchor',auth.anchor)]

    def result(complete, reason=None):
        return {'entries':sorted(fragment,key=lambda row:(row['scope'],row['path'])),
            'truncated':truncated or not complete, 'accounting_complete':complete,
            'used_bytes':used if complete else None, 'scan_reason':reason}

    while stack:
        scope,directory = stack.pop()
        root = auth.anchor if scope=='anchor' else auth.output_root
        if time.monotonic()>=deadline: return result(False,'deadline')
        if not directory.exists(): continue
        h._safe_path(directory,directory=True,private=True)
        with os.scandir(directory) as entries:
            for entry in entries:
                if time.monotonic()>=deadline: return result(False,'deadline')
                if scanned>=h.INVENTORY_ENTRIES: return result(False,'entry_limit')
                scanned += 1
                path = Path(entry.path)
                if scope=='output' and path==root/'status.json': continue
                is_directory = entry.is_dir(follow_symlinks=False)
                h._safe_path(path,directory=is_directory,private=True)
                info = path.stat()
                row = {'scope':scope,'path':str(path.relative_to(root)),
                    'kind':'directory' if is_directory else 'file',
                    'bytes':0 if is_directory else info.st_size,
                    'sha256':None,'hash_not_computed':True}
                size = len(h.canonical_bytes(row))
                metadata_bytes += size+len(str(path).encode('utf-8'))
                if metadata_bytes>h.METADATA_BYTES: return result(False,'metadata_limit')
                if is_directory:
                    stack.append((scope,path))
                elif (info.st_dev,info.st_ino) not in seen:
                    seen.add((info.st_dev,info.st_ino)); used += info.st_size
                if len(fragment)<128 and fragment_bytes+size<=32*1024:
                    fragment.append(row); fragment_bytes += size
                else:
                    truncated = True
    return result(True)


def _publish_holdout_report(auth, contract, units):
    report = _report_data(auth,contract,units)
    _publish_artifacts(auth,_report_artifacts(auth,report))
    limits = h._terminal_limits(auth)
    inventory = _inventory(auth,deadline=time.monotonic()+limits['seconds'])
    terminal = _terminal(auth,report,inventory)
    _publish_artifacts(auth,[(auth.output_root/'status.json',h.canonical_bytes(terminal))],terminal=True)
    return report


def _publish_batch_failure_best_effort(auth, contract, units, error):
    """Finite cleanup evidence. Never reclaim, erase, repair or mask the fatal error."""
    fatal = {'type':type(error).__name__[:100], 'message':str(error)[:2048]}
    for unit in units:
        if unit['status']=='not_run': unit['status']='not_run_batch_failed'
        elif unit['status']=='running': unit['status']='failed'
    try:
        with _wall_guard(contract['limits']['cleanup_seconds']):
            cleanup_deadline = time.monotonic()+contract['limits']['cleanup_seconds']
            _private_directory(auth.output_root)
            if (auth.output_root/'status.json').exists() or (auth.output_root/'status.json').is_symlink():
                return  # Immutable terminal/interruption evidence is never repaired.
            inventory = _failure_inventory(auth,deadline=cleanup_deadline)
            if not inventory['accounting_complete']:
                return  # Unknown total usage cannot authorize another publication.
            # Publication ordinarily uses the live budget. An exhausted deadline
            # leaves only the reserved, bounded terminal, never extra execution.
            if isinstance(auth,h.HoldoutAuthorization) and not any((auth.output_root/name).exists() for name in REPORT_FILES):
                try:
                    report = _report_data(auth,contract,units,fatal=fatal)
                    _publish_artifacts(auth,_report_artifacts(auth,report))
                except BaseException: pass
            inventory = _failure_inventory(auth,deadline=cleanup_deadline)
            terminal = {'schema_version':TERMINAL_SCHEMA,'campaign_id':contract['campaign_id'],
                'authority_hash':auth.authority_hash,'contract_hash':auth.contract_hash,'claim_hash':auth.claim_hash,
                'holdout_status':'failed','execution_authority':'none','paper_transfer':'distinct_pending',
                'fatal_error':fatal,'units':[{'variant_id':u['variant_id'],'cost_profile':u['cost_profile'],
                    'status':u['status'],'independently_verified':u['independently_verified']} for u in units],
                'artifact_inventory':inventory['entries'], 'inventory_truncated':inventory['truncated'],
                'inventory_accounting_complete':inventory['accounting_complete'],
                'inventory_scan_reason':inventory['scan_reason'],'artifact_content_hashes_computed':False,
                'artifact_inventory_fragment_sha256':h.hash_bytes(h.canonical_bytes(inventory['entries']))}
            raw = h.canonical_bytes(terminal)
            used = inventory['used_bytes']
            if (inventory['accounting_complete'] and len(raw)<=h.TERMINAL_RESERVE and used+len(raw)<=contract['limits']['overall_bytes']
                and h.shutil.disk_usage(auth.output_root.parent).free-len(raw)>=contract['limits']['disk_reserve_bytes']):
                h._publish_bytes(auth.output_root/'status.json',raw)
    except BaseException:
        pass


def run_holdout(output_root: Path, *, timeout=OVERALL_SECONDS, max_output_bytes=OVERALL_BYTES) -> dict:
    # Validate before arming a POSIX timer; prepare_claim repeats the boundary.
    if (isinstance(timeout,bool) or not isinstance(timeout,(int,float)) or not math.isfinite(timeout)
        or not 0<timeout<=OVERALL_SECONDS): raise h.HoldoutError('resource_limits_lower_only')
    authority = None
    try:
        with _wall_guard(timeout):
            authority = h.load_campaign_authority()
            auth = h.prepare_claim(authority,Path(output_root),timeout=timeout,max_output_bytes=max_output_bytes)
    except BaseException as exc:
        try:
            with _wall_guard(10):
                receipt = h._claim_failure_receipt(exc,authority,output_root) if authority is not None else None
                if receipt is not None:
                    contract = json.loads(receipt.contract_bytes)
                    _publish_batch_failure_best_effort(receipt,contract,_pending_units(contract),exc)
        except BaseException:
            pass
        raise
    contract = json.loads(auth.contract_bytes)
    units = _pending_units(contract)
    try:
        _private_directory(auth.output_root)
        _run_shared_signals(auth)
        for index,slot in enumerate(contract['slots']):
            units[index]['status'] = 'running'
            variant,profile = slot['variant_id'],slot['cost_profile']
            _stage(auth,_bind_unit_inputs,auth,variant,profile)
            slot_auth = auth.for_slot(variant,profile)
            root = _stage(auth,_unit_root,auth,variant,profile)
            _stage(slot_auth,campaign.run_holdout_campaign,auth.output_root/'signals',authorization=slot_auth,
                variant_id=variant,cost_profile=profile)
            verified = _stage(slot_auth,statistics.verify_holdout_campaign,root,authorization=slot_auth,
                variant_id=variant,cost_profile=profile)
            units[index] = _stage(auth,_publish_unit_result,auth,slot,verified)
        # Terminal consumes its reserved capacity, so no execution-budget check
        # follows its publication.
        remaining = h.remaining_limits(auth)
        with _wall_guard(min(h.VERIFIER_SECONDS,remaining['seconds'])):
            return _publish_holdout_report(auth,contract,units)
    except BaseException as exc:
        _publish_batch_failure_best_effort(auth,contract,units,exc)
        raise


def verify_retained_holdout() -> dict:
    """Read-only replay of the originally consumed output; no caller-selected root."""
    with _wall_guard(h.VERIFIER_SECONDS):
        authority = h.load_campaign_authority()
        auth = h.mint_retained_authorization(authority)
        contract = h.require_claim(auth,operation='retained')
        limits = h.remaining_limits(auth)
        try:
            terminal,_ = h._read_json(auth.output_root/'status.json',h.TERMINAL_RESERVE,private=True)
        except OSError as exc:
            raise h.HoldoutError('retained_batch_missing_terminal') from exc
        if terminal.get('schema_version')!=TERMINAL_SCHEMA or terminal.get('holdout_status') not in ('complete','inconclusive'):
            raise h.HoldoutError('retained_batch_incomplete_or_failed')
        inventory = _inventory(auth,deadline=time.monotonic()+limits['seconds'])
        if inventory != terminal.get('artifact_inventory'): raise h.HoldoutError('retained_artifact_hash_conflict')
        units = []
        for slot in contract['slots']:
            slot_auth = auth.for_slot(slot['variant_id'],slot['cost_profile'])
            verified = _stage(slot_auth,statistics.verify_holdout_campaign,Path(slot['output_root']),
                authorization=slot_auth,variant_id=slot['variant_id'],cost_profile=slot['cost_profile'])
            result = _unit_result(auth,slot,verified)
            retained,_ = h._read_json(Path(slot['output_root'])/'verified-result.json',h.METADATA_BYTES,private=True)
            if retained != result: raise h.HoldoutError('retained_unit_result_conflict')
            units.append(result)
        report = _report_data(auth,contract,units)
        for path,expected in _report_artifacts(auth,report):
            h._safe_path(path,private=True)
            if path.stat().st_size>h.METADATA_BYTES or path.read_bytes()!=expected:
                raise h.HoldoutError('retained_report_conflict')
        if terminal != _terminal(auth,report,inventory): raise h.HoldoutError('retained_terminal_conflict')
        return report


def main(argv=None):
    parser = argparse.ArgumentParser(description='Once-only fixed historical holdout; no order authority')
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--output-root',type=Path)
    mode.add_argument('--verify-retained',action='store_true')
    parser.add_argument('--timeout',type=float,default=OVERALL_SECONDS)
    parser.add_argument('--max-output-bytes',type=int,default=OVERALL_BYTES)
    args = parser.parse_args(argv)
    if args.verify_retained and (args.timeout!=OVERALL_SECONDS or args.max_output_bytes!=OVERALL_BYTES):
        parser.error('resource limits apply only to evaluation')
    report = verify_retained_holdout() if args.verify_retained else run_holdout(args.output_root,
        timeout=args.timeout,max_output_bytes=args.max_output_bytes)
    stdout = h.canonical_bytes({'schema_version':REPORT_SCHEMA,'output_root':report['output_root'],
        'holdout_status':report['holdout_status'],'execution_authority':'none','paper_transfer':'distinct_pending'})
    if len(stdout)>h.STDOUT_BYTES: raise h.HoldoutError('report_stdout_capacity')
    print(stdout.decode('utf-8'),end='')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
