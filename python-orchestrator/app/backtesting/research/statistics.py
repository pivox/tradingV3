"""Independent exact research statistics; no data access on import."""
from __future__ import annotations

from decimal import Decimal
from collections import Counter
from datetime import datetime, timezone
import hashlib
import heapq
import json
import os
from pathlib import Path
import shutil
import sqlite3
import stat
import tempfile
from typing import Any

ZERO = Decimal('0')
LEDGERS = ('plans','rejections','events','trades','cashflows','funding-events')
COMPONENTS = ('gross_pnl_quote','net_pnl_quote','fees_quote','funding_quote','spread_quote','slippage_quote')
MAX_LINE = 2*1024**2
DAY = 86400000


class StatisticsError(ValueError):
    """Missing, inconsistent or unsafe research evidence."""


def decimal(value: Any) -> Decimal:
    if isinstance(value, bool) or not isinstance(value, (str, int, float, Decimal)):
        raise StatisticsError('invalid_decimal')
    try:
        result = Decimal(str(value))
    except Exception as exc:
        raise StatisticsError('invalid_decimal') from exc
    if not result.is_finite():
        raise StatisticsError('invalid_decimal')
    return result


class OutcomeStatistics:
    """Constant-memory net trade outcomes using initial planned stop risk."""

    def __init__(self) -> None:
        self.count = self.wins = self.losses = self.breakevens = 0
        self.net = self.positive = self.negative = self.net_r = ZERO

    def add(self, net: Any, initial_risk: Any) -> None:
        net, risk = decimal(net), decimal(initial_risk)
        if risk <= 0:
            raise StatisticsError('initial_stop_risk_nonpositive')
        self.count += 1
        self.net += net
        self.net_r += net / risk
        if net > 0:
            self.wins += 1
            self.positive += net
        elif net < 0:
            self.losses += 1
            self.negative -= net
        else:
            self.breakevens += 1

    def result(self) -> dict:
        return {'closed_trades': self.count, 'wins': self.wins, 'losses': self.losses,
                'breakevens': self.breakevens, 'net_pnl_quote': str(self.net),
                'net_win_rate': str(Decimal(self.wins) / self.count) if self.count else None,
                'mean_net_expectancy_quote': str(self.net / self.count) if self.count else None,
                'mean_realized_net_r': str(self.net_r / self.count) if self.count else None,
                'net_profit_factor': str(self.positive / self.negative) if self.negative else None,
                'profit_factor_undefined_reason': None if self.negative else 'no_negative_net_trades',
                'expectancy_undefined_reason': None if self.count else 'no_completed_trades'}


def _json(raw: bytes) -> dict:
    def pairs(items):
        result = {}
        for key,value in items:
            if key in result:
                raise StatisticsError('duplicate_json_key')
            result[key] = value
        return result
    def invalid(value):
        raise StatisticsError('nonfinite_json')
    try:
        result = json.loads(raw,object_pairs_hook=pairs,parse_constant=invalid)
    except (ValueError,UnicodeError,RecursionError) as exc:
        raise StatisticsError('invalid_json') from exc
    if not isinstance(result,dict):
        raise StatisticsError('json_object_required')
    return result


def _safe_file(path: Path, cap: int) -> None:
    if (not path.is_absolute() or '..' in path.parts or any(p.is_symlink() for p in (path,*path.parents))
            or not stat.S_ISREG(path.lstat().st_mode) or path.stat().st_size > cap):
        raise StatisticsError('unsafe_or_oversized_evidence_file')


def _read_json(path: Path) -> tuple[dict,str]:
    _safe_file(path,16*1024**2)
    raw = path.read_bytes()
    return _json(raw),hashlib.sha256(raw).hexdigest()


def _ledger_rows(path: Path, expected: dict):
    _safe_file(path,4*1024**3)
    if set(expected) != {'sha256','count','bytes'} or any(type(expected[k]) is not int or expected[k] < 0
                                                       for k in ('count','bytes')):
        raise StatisticsError('invalid_ledger_inventory')
    digest = hashlib.sha256()
    previous, count, size = -1,0,0
    with path.open('rb') as stream:
        while raw := stream.readline(MAX_LINE+1):
            if len(raw) > MAX_LINE or not raw.endswith(b'\n'):
                raise StatisticsError('invalid_ledger_line_framing')
            digest.update(raw); size += len(raw)
            row = _json(raw)
            sequence = row.get('ledger_sequence')
            if type(sequence) is not int or sequence <= previous:
                raise StatisticsError('invalid_ledger_sequence')
            previous = sequence; count += 1
            yield row
    if digest.hexdigest() != expected['sha256'] or count != expected['count'] or size != expected['bytes']:
        raise StatisticsError('ledger_hash_count_or_size_conflict')


def _year(timestamp: int) -> str:
    return str(datetime.fromtimestamp(timestamp/1000,tz=timezone.utc).year)


def analyze_ledgers(root: Path, files: dict, *, symbols: tuple[str,...], start_ms: int,
                    end_ms: int, max_spool_bytes: int = 4*1024**3,
                    min_free_bytes: int = 20*1024**3, expected_identity: dict | None = None,
                    expected_sources: dict | None = None) -> dict:
    """Merge six ordered ledgers and independently join plans/trades/cashflows.

    SQLite is a private temporary local index, not an application database.
    Its bounded file retains closed-plan identities to detect duplicate closes
    without accumulating all plans/candles in memory. Cashflow state is capped
    at the four portfolio slots; pair/year aggregations have fixed dimensions.
    """
    from .portfolio_simulator import canonical_hash
    root = Path(root)
    if (set(files) != {name+'.ndjson' for name in LEDGERS} or not symbols or
            start_ms >= end_ms or end_ms > 1767225600000 or start_ms < 1672531200000):
        raise StatisticsError('ledger_scope_invalid_holdout_closed')
    for directory in (root,*root.parents):
        if directory.is_symlink():
            raise StatisticsError('unsafe_ledger_root')
    totals = {field:ZERO for field in COMPONENTS}
    per_pair = {s:{field:ZERO for field in COMPONENTS} for s in symbols}
    pair_stats = {s:OutcomeStatistics() for s in symbols}
    years = {str(y):{'cashflow_net_pnl_quote':ZERO, 'outcomes':OutcomeStatistics()}
             for y in range(int(_year(start_ms)),int(_year(end_ms-1))+1)}
    outcomes = OutcomeStatistics()
    counts, ambiguity, exits = Counter(),Counter(),Counter()
    active = {}
    maximum_closed_drawdown = peak_wallet = ZERO
    wallet = Decimal('100000'); peak_wallet = wallet
    sequence = 0

    def tagged(name):
        for row in _ledger_rows(root/(name+'.ndjson'),files[name+'.ndjson']):
            yield name,row

    with tempfile.TemporaryDirectory(prefix='tradingv3-statistics-') as temporary:
        spool = Path(temporary)/'plan-index.sqlite'
        connection = sqlite3.connect(spool)
        os.chmod(spool,0o600)
        connection.execute('PRAGMA cache_size=-2048')
        connection.execute('PRAGMA temp_store=FILE')
        connection.execute('CREATE TABLE plans (hash TEXT PRIMARY KEY, symbol TEXT, risk TEXT, closed INTEGER DEFAULT 0, entry TEXT, quantity TEXT, contract TEXT)')
        try:
            for name,row in heapq.merge(*(tagged(name) for name in LEDGERS),
                                       key=lambda item:item[1]['ledger_sequence']):
                if row['ledger_sequence'] != sequence:
                    raise StatisticsError('global_ledger_sequence_conflict')
                sequence += 1
                if sequence % 128 == 0:
                    connection.commit()
                    if spool.stat().st_size > max_spool_bytes or shutil.disk_usage(spool.parent).free < min_free_bytes:
                        raise StatisticsError('statistics_spool_capacity_exceeded')
                symbol = row.get('symbol')
                if symbol not in symbols:
                    raise StatisticsError('ledger_symbol_outside_protocol')
                if name in ('plans','trades') and expected_identity is not None:
                    if any(row.get(k) != v for k,v in expected_identity.items()):
                        raise StatisticsError('ledger_identity_conflict')
                    if any(row.get(k) != v for k,v in expected_sources[symbol].items()):
                        raise StatisticsError('ledger_source_binding_conflict')
                if name == 'plans':
                    key = row.get('plan_hash')
                    if canonical_hash({k:v for k,v in row.items() if k not in ('plan_hash','ledger_sequence')}) != key:
                        raise StatisticsError('plan_hash_conflict')
                    risk = decimal(row['risk_components_quote']['total_stop_loss'])
                    if risk <= 0:
                        raise StatisticsError('initial_stop_risk_nonpositive')
                    try:
                        connection.execute('INSERT INTO plans(hash,symbol,risk,entry,quantity,contract) VALUES(?,?,?,?,?,?)',
                            (key,symbol,str(risk),str(decimal(row['entry_price'])),str(decimal(row['quantity'])),
                             str(decimal(row['instrument_math']['contract_size']))))
                    except sqlite3.IntegrityError as exc:
                        raise StatisticsError('duplicate_plan_identity') from exc
                    counts['plans'] += 1
                elif name in ('cashflows','trades','events'):
                    key = row.get('plan_hash')
                    plan = connection.execute('SELECT symbol,risk,closed,entry,quantity,contract FROM plans WHERE hash=?',(key,)).fetchone()
                    if plan is None or plan[0] != symbol or plan[2]:
                        raise StatisticsError('missing_closed_or_foreign_plan')
                    if name == 'events':
                        reason = row['reason']
                        if reason == 'admitted':
                            counts['admitted'] += 1
                        if reason == 'limit_fill_proxy':
                            counts['filled'] += 1
                        if row.get('ambiguous'):
                            ambiguity[reason] += 1
                        counts['events'] += 1
                    elif name == 'cashflows':
                        at = row['timestamp_ms']
                        if type(at) is not int or not start_ms <= at <= end_ms:
                            raise StatisticsError('cashflow_timestamp_outside_phase')
                        amount = decimal(row['amount_quote'])
                        kind = row['kind']
                        if kind == 'gross_pnl':
                            field,component = 'gross_pnl_quote',amount
                        elif kind == 'funding':
                            field,component = 'funding_quote',amount
                        elif kind in ('entry_fee','stop_fee','target_fee','liquidation_proxy_fee'):
                            field,component = 'fees_quote',-amount
                        elif kind in ('entry_spread','stop_spread','target_spread'):
                            field,component = 'spread_quote',-amount
                        elif kind in ('entry_slippage','stop_slippage','target_slippage'):
                            field,component = 'slippage_quote',-amount
                        else:
                            raise StatisticsError('unknown_cashflow_kind')
                        if field in ('fees_quote','spread_quote','slippage_quote') and amount > 0:
                            raise StatisticsError('expense_cannot_be_credit')
                        position = active.setdefault(key,{f:ZERO for f in COMPONENTS})
                        if len(active) > 4:
                            raise StatisticsError('cashflow_concurrency_exceeded')
                        for target in (position,totals,per_pair[symbol]):
                            target['net_pnl_quote'] += amount; target[field] += component
                        # The exclusive year boundary can settle a prior-year position.
                        year = _year(at)
                        if year not in years:
                            years[year] = {'cashflow_net_pnl_quote':ZERO,'outcomes':OutcomeStatistics()}
                        years[year]['cashflow_net_pnl_quote'] += amount
                        wallet += amount; peak_wallet = max(peak_wallet,wallet)
                        maximum_closed_drawdown = max(maximum_closed_drawdown,peak_wallet-wallet)
                        counts['cashflows'] += 1
                    else:
                        position = active.pop(key,None)
                        if position is None or any(decimal(row[f]) != position[f] for f in COMPONENTS):
                            raise StatisticsError('trade_cashflow_reconciliation_conflict')
                        entry,quantity,contract = map(decimal,plan[3:])
                        if (decimal(row['entry_price']) != entry or decimal(row['quantity']) != quantity
                                or quantity*contract*(decimal(row['exit_price'])-entry) != position['gross_pnl_quote']):
                            raise StatisticsError('gross_price_pnl_or_units_conflict')
                        expected_net = position['gross_pnl_quote']+position['funding_quote']-sum(
                            (position[f] for f in ('fees_quote','spread_quote','slippage_quote')),ZERO)
                        if position['net_pnl_quote'] != expected_net:
                            raise StatisticsError('component_net_pnl_conflict')
                        at,fill = row['exit_boundary_ms'],row['fill_boundary_ms']
                        if type(at) is not int or type(fill) is not int or not start_ms <= fill <= at <= end_ms:
                            raise StatisticsError('trade_timestamp_outside_phase')
                        # Exactly midnight is a declared exclusive settlement, allowed.
                        if fill//DAY != (at-1)//DAY:
                            raise StatisticsError('trade_crosses_utc_midnight')
                        risk = decimal(plan[1]); net = position['net_pnl_quote']
                        outcomes.add(net,risk); pair_stats[symbol].add(net,risk)
                        year = _year(at)
                        if year not in years:
                            years[year] = {'cashflow_net_pnl_quote':ZERO,'outcomes':OutcomeStatistics()}
                        years[year]['outcomes'].add(net,risk)
                        exits[row['exit_reason']] += 1
                        connection.execute('UPDATE plans SET closed=1 WHERE hash=?',(key,))
                elif name == 'rejections':
                    if row.get('schema_version') == 'research-simulation-rejection.v1':
                        counts['rejected'] += 1
                    else:
                        counts['planner_rejections'] += 1
                else:
                    at = row.get('timestamp_ms')
                    if type(at) is not int or not start_ms <= at < end_ms:
                        raise StatisticsError('funding_event_outside_phase')
                    decimal(row['rate'])
                    counts['funding_events'] += 1
            if active:
                raise StatisticsError('unclosed_cashflow_position')
            connection.commit()
            if spool.stat().st_size > max_spool_bytes or shutil.disk_usage(spool.parent).free < min_free_bytes:
                raise StatisticsError('statistics_spool_capacity_exceeded')
        finally:
            connection.close()
    if sum((p['net_pnl_quote'] for p in per_pair.values()),ZERO) != totals['net_pnl_quote']:
        raise StatisticsError('pair_portfolio_totals_conflict')
    return {'schema_version':'research-unit-statistics.v1', **outcomes.result(),
        **{k:str(v) for k,v in totals.items()},'wallet_quote':str(wallet),'initial_wallet_quote':'100000',
        'roi_wallet_denominator_quote':'100000','roi_wallet_rate':str(totals['net_pnl_quote']/100000),
        'phase_wallet_reset':True,'pair_wallets_are_not_independent':True,
        'pairs_with_trades':sum(bool(s.count) for s in pair_stats.values()),
        'per_pair':{s:{**pair_stats[s].result(),**{k:str(v) for k,v in per_pair[s].items()}} for s in symbols},
        'per_year':{year:{**row['outcomes'].result(),'cashflow_net_pnl_quote':str(row['cashflow_net_pnl_quote']),
            'cashflow_vs_exit_attribution_difference_quote':str(row['cashflow_net_pnl_quote']-row['outcomes'].net)}
            for year,row in sorted(years.items())},
        'counts':{key:counts[key] for key in ('plans','admitted','filled','rejected','planner_rejections','events','cashflows','funding_events')},
        'ambiguity_counts':dict(ambiguity),'exit_reason_counts':dict(exits),
        'cashflow_wallet_maximum_drawdown_quote':str(maximum_closed_drawdown),
        'cashflow_wallet_drawdown_is_marked':False,'independently_reconciled':True}


def verify_campaign(root: Path, protocol: dict, registration: dict) -> dict:
    """Strict C1 evidence verification for the fixed pre-holdout research screen."""
    try:
        return _verify_campaign(Path(root),protocol,registration)
    except StatisticsError:
        raise
    except (OSError,ValueError,KeyError,TypeError,OverflowError) as exc:
        raise StatisticsError('invalid_or_missing_campaign_evidence') from exc


def _verify_campaign(root: Path, protocol: dict, registration: dict) -> dict:
    from .experiments import _validate_protocol, hash_bytes, canonical_bytes, WINDOWS
    from .portfolio_simulator import canonical_hash, SYMBOLS
    _validate_protocol(protocol)
    phase = registration['phase']
    if phase not in WINDOWS or protocol.get('phase_bindings') is None:
        raise StatisticsError('phase_bindings_required_holdout_closed')
    if registration['protocol_hash'] != hash_bytes(canonical_bytes(protocol)):
        raise StatisticsError('registration_protocol_conflict')
    binding = protocol['phase_bindings'][phase]
    manifest,manifest_sha = _read_json(root/'manifest.json')
    start = int(datetime.fromisoformat(WINDOWS[phase]['start'].replace('Z','+00:00')).timestamp()*1000)
    end = int(datetime.fromisoformat(WINDOWS[phase]['end'].replace('Z','+00:00')).timestamp()*1000)
    if (manifest.get('phase') != phase or manifest.get('start_ms') != start
            or manifest.get('end_ms') != end or manifest.get('end_exclusive') is not True):
        raise StatisticsError('campaign_scope_invalid_before_profit_read')
    summary,summary_sha = _read_json(root/'summary.json')
    status,_ = _read_json(root/'status.json')
    if (manifest['schema_version'] != 'research-campaign-manifest.v1'
        or summary['schema_version'] != 'research-campaign-summary.v1'
        or status['schema_version'] != 'research-campaign-status.v1'
        or status['status'] not in ('complete','inconclusive')
        or summary['status'] != status['status'] or status['completion'] != 'input_complete'
        or status['manifest_sha256'] != manifest_sha or summary['manifest_sha256'] != manifest_sha
        or status['summary_sha256'] != summary_sha
        or not manifest['run_id'] == summary['run_id'] == status['run_id']
        or any(doc.get('execution_authority','none') != 'none' for doc in (manifest,summary,status))):
        raise StatisticsError('campaign_status_hash_or_identity_conflict')
    if (manifest['phase'] != phase or summary['phase'] != phase or
        manifest['start_ms'] != start or summary['start_ms'] != start or
        manifest['end_ms'] != end or summary['end_ms'] != end or manifest['end_exclusive'] is not True
        or manifest['symbol_priority'] != list(SYMBOLS) or summary['symbol_priority'] != list(SYMBOLS)
        or manifest['cost_profile'] != registration['cost_profile']
        or manifest['variant']['id'] != registration['variant_id']
        or manifest['initial_wallet_quote'] != '100000'):
        raise StatisticsError('campaign_protocol_scope_conflict')
    for field in ('source_runs','signal_reports','runner_code_sha256'):
        if manifest[field] != binding[field]:
            raise StatisticsError('campaign_frozen_source_or_code_conflict')
    identity = protocol['identity']
    frozen_data = {p:protocol['phase_bindings'][p]['source_runs'] for p in WINDOWS}
    if hash_bytes(canonical_bytes(frozen_data)) != identity['dataset_hash']:
        raise StatisticsError('protocol_dataset_identity_conflict')
    if any(canonical_hash(report['code_sha256']) != identity['signal_code_hash'] for report in manifest['signal_reports']):
        raise StatisticsError('signal_code_identity_conflict')
    baseline = manifest['baseline']
    for target,source in (('base_setup_hash','setup_hash'),('base_config_hash','config_hash'),
                          ('base_catalog_hash','condition_catalog_hash'),('base_snapshot_hash','snapshot_hash')):
        if baseline[source] != identity[target]:
            raise StatisticsError('baseline_identity_conflict')
    if manifest['research_code_hash'] != identity['research_code_hash']:
        raise StatisticsError('research_code_identity_conflict')
    instruments,costs = manifest['instrument_assumptions'],manifest['cost_assumptions']
    for document,field in ((instruments,'manifest_hash'),(costs,'assumption_hash')):
        if canonical_hash({k:v for k,v in document.items() if k != field}) != document[field]:
            raise StatisticsError('assumption_hash_conflict')
    profile = registration['cost_profile']
    if (instruments['manifest_hash'] != identity['instrument_assumptions_hash']
        or costs['assumption_hash'] != binding['cost_assumptions_hash']
        or canonical_hash(costs['profiles'][profile]) != identity[profile+'_cost_hash']):
        raise StatisticsError('assumption_protocol_identity_conflict')
    selected = next(row for row in protocol['variants'] if row['id'] == registration['variant_id'])
    diff = selected['diff'] or []  # PHP baseline serializes its empty array as [].
    variant_hash = canonical_hash({'schema_version':'research-variant.v1','id':selected['id'],'diff':diff,
        'base_setup_hash':baseline['setup_hash'],'base_config_hash':baseline['config_hash'],
        'base_catalog_hash':baseline['condition_catalog_hash']})
    if manifest['variant']['diff'] != diff or manifest['variant']['variant_hash'] != variant_hash:
        raise StatisticsError('closed_variant_identity_conflict')
    expected = {k:v for k,v in identity.items() if k in ('base_setup_hash','base_config_hash',
        'base_catalog_hash','base_snapshot_hash','instrument_assumptions_hash','research_code_hash')}
    expected.update(variant_id=selected['id'],variant_hash=variant_hash,cost_assumptions_hash=costs['assumption_hash'])
    sources = {row['symbol']:{**row['source'],'signal_run_id':row['signal_run_id'],
               'signal_output_sha256':row['signal_output_sha256']} for row in manifest['source_runs']}
    if list(sources) != list(SYMBOLS):
        raise StatisticsError('source_priority_conflict')
    # Source venue belongs to plan evidence but is absent from the trade schema.
    sources = {symbol:{k:v for k,v in row.items() if k in ('dataset_id','dataset_sha256',
               'signal_run_id','signal_output_sha256')} for symbol,row in sources.items()}
    result = analyze_ledgers(root,status['files'],symbols=SYMBOLS,start_ms=start,end_ms=end,
                             expected_identity=expected,expected_sources=sources)
    if (any(decimal(summary[field]) != decimal(result[field]) for field in (*COMPONENTS,'wallet_quote'))
        or summary['trades'] != result['closed_trades']
        or summary['admitted_plans'] != result['counts']['admitted']
        or summary['admitted_plans'] != result['counts']['plans']
        or result['counts']['filled'] != result['closed_trades']
        or summary['funding_events'] != result['counts']['funding_events']
        or summary['reconciliation']['status'] != 'verified'):
        raise StatisticsError('campaign_summary_reconciliation_conflict')
    counters = summary['b1_counters']
    if (counters != manifest['b1_counters']
        or summary['phase_batches'] != (end-start)//60000
        or type(summary['source_candles']) is not int or summary['source_candles'] < (end-start)//60000*len(SYMBOLS)
        or any(type(counters[k]) is not int or counters[k] < 0 for k in ('evaluated_ticks','passed_rules','failed_rules'))
        or counters['passed_rules']+counters['failed_rules'] != counters['evaluated_ticks']
        or summary['attempted_signals'] != counters['passed_rules']
        or counters['passed_rules'] != result['counts']['admitted']+result['counts']['rejected']):
        raise StatisticsError('physical_signal_denominator_conflict')
    if decimal(summary['maximum_drawdown_quote']) < 0 or decimal(summary['maximum_exposure_quote']) < 0:
        raise StatisticsError('negative_marked_drawdown_or_exposure')
    quality = summary['source_quality']; funding = manifest['funding_inventory']
    if (quality['funding_inventory'] != funding or funding['coverage'] != 'observed_only'
        or manifest['funding_inventory_hash'] != funding['inventory_hash']
        or canonical_hash({k:v for k,v in funding.items() if k != 'inventory_hash'}) != funding['inventory_hash']):
        raise StatisticsError('funding_provenance_conflict')
    complete_funding = (funding['evidence_complete'] is True and
        [r['symbol'] for r in funding['symbols']] == list(SYMBOLS) and
        all(r['evidence_complete'] is True and r['continuity'] in ('declared_interval_consistent','assumed_interval')
            for r in funding['symbols']) and quality['kernel_funding_coverage'] == 'verified_complete')
    if sum(r['count'] for r in funding['symbols']) != result['counts']['funding_events']:
        raise StatisticsError('funding_event_denominator_conflict')
    if status['status'] == 'complete' and (not complete_funding or quality['candles'] != 'verified_complete'):
        raise StatisticsError('complete_status_without_source_evidence')
    result.update(status=status['status'],phase=phase,variant_id=selected['id'],cost_profile=profile,
        coverage_complete=quality['candles'] == 'verified_complete',cost_evidence_complete=complete_funding,
        funding_provenance='observed_only',funding_diagnostic_is_exchange_certification=False,
        source_quality=quality,maximum_drawdown_quote=str(decimal(summary['maximum_drawdown_quote'])),
        maximum_exposure_quote=str(decimal(summary['maximum_exposure_quote'])),
        marked_drawdown_source='kernel_marked_equity',cutoff=protocol['cutoff'])
    result['counts'].update(scored=counters['evaluated_ticks'],passed=counters['passed_rules'],
        failed_rules=counters['failed_rules'],source_candles=summary.get('source_candles'),
        scored_clock_minutes=(end-start)//60000,scored_symbol_minutes=(end-start)//60000*len(SYMBOLS))
    result['source_candles_include_warmup'] = True
    result['source_candle_count_is_unique_acquisition_rows'] = False
    return result
