#!/usr/bin/env python3
"""The PHP research workers against the swing exploration (swing campaign PR P2).

For one symbol and side, the verified 1m candles of a phase are streamed through
``app:research:signals`` with the swing profile. Every 4h decision of the phase must be
evaluated, and the passed decisions must equal the exploration frames' ``B30_2s`` rule.

The passed signals are then replayed through ``app:research:plans`` with an empty
one-plan-per-pair portfolio. Each plan must equal ``swing.build_swing_plan`` for
``S3.T5.H14d``: entry, stop, target, quantity, net risk and net R. The holding deadline
is compared only when the exploration did not clip it at the phase end.

Outputs (worker transcripts and a JSON report) land in ``--out``.
"""
from __future__ import annotations

import argparse
import hashlib
from collections import Counter
import json
import os
import subprocess
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from app.backtesting.research import exploration as ex  # noqa: E402
from app.backtesting.research import swing  # noqa: E402
from app.backtesting.research.portfolio_simulator import canonical_hash  # noqa: E402
from app.backtesting.research.signal_sources import iter_verified_candles, select_sources  # noqa: E402

PROFILES = {'long': 'swing.long', 'short': 'swing.short'}
POLICY = 'B30_2s'
GEOMETRY = 'S3.T5.H14d'
WARMUP = timedelta(days=50)  # more than the 250 4h bars every rolling window needs
VARIANT_PHP = r'''
require getenv('TRADING_APP_AUTOLOAD');
use App\TradingCore\Backtesting\Research\ResearchProfile;
use App\TradingCore\Backtesting\Research\ResearchVariant;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicy;
$profile = ResearchProfile::fromId($argv[1]);
$snapshot = (new EffectiveTradingConfigResolver())->resolve($profile->request());
$setup = (new App\TradingCore\Setup\SetupContractLoader())->load($profile->setupId, $profile->setupVersion);
echo ResearchVariant::select('baseline', [], CanonicalExecutionPolicy::fromSnapshot($snapshot), $setup->stableHash(),
    (string) $snapshot->conditionCatalogHash, $profile)->hash;
'''


def _iso(ms: int) -> str:
    return datetime.fromtimestamp(ms / 1000, timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')


def _line(frame: dict) -> bytes:
    return json.dumps(frame, separators=(',', ':'), ensure_ascii=False).encode() + b'\n'


def _worker(app: Path, command: str, frames, out: Path) -> list[dict]:
    """Stream frames to one worker; its stdout goes to a file so neither side can block."""
    argv = ('php', '-d', 'memory_limit=-1', '-d', 'display_errors=stderr', str(app / 'bin/console'),
            command, '--no-interaction')
    with out.open('wb') as sink, out.with_suffix('.stderr').open('wb') as errors:
        process = subprocess.Popen(argv, cwd=app, stdin=subprocess.PIPE, stdout=sink, stderr=errors)
        assert process.stdin is not None
        try:
            for frame in frames:
                process.stdin.write(_line(frame))
        finally:
            process.stdin.close()
        code = process.wait()
    records = [json.loads(line) for line in out.read_bytes().splitlines()]
    failures = [r for r in records if str(r.get('schema_version', '')).endswith('-error.v1')]
    if code != 0 or failures:
        raise SystemExit(f'{command} failed ({code}): {failures[:1]}')
    return records


def run_signals(args: argparse.Namespace, symbol: str, side: str, start: int, end: int, out: Path) -> tuple[list[dict], Path]:
    source_start = start - int(WARMUP.total_seconds() * 1000)
    selection = select_sources(args.dataset, _iso(source_start), _iso(end), _iso(start), symbol)
    session = f'swing-{side}-{symbol}'

    def frames():
        yield {'schema_version': 'research-signal-open.v1', 'session_id': session, 'profile': PROFILES[side],
               'dataset_id': 'research-' + selection.dataset_sha256[:24], 'dataset_sha256': selection.dataset_sha256,
               'source_venue': 'binance_usdm', 'source_network': 'mainnet', 'market_type': 'perpetual',
               'symbol': symbol, 'start_ms': source_start, 'end_ms': end, 'score_start_ms': start}
        batch: list[dict] = []
        for record in iter_verified_candles(args.dataset, selection):
            batch.append(record)
            if len(batch) == 1440:
                yield {'schema_version': 'research-signal-candles.v1', 'session_id': session, 'candles': batch}
                batch = []
        if batch:
            yield {'schema_version': 'research-signal-candles.v1', 'session_id': session, 'candles': batch}
        yield {'schema_version': 'research-signal-close.v1', 'session_id': session}

    path = out / f'signals-{side}-{symbol}.jsonl'
    if args.reuse and path.is_file() and path.read_bytes().rstrip().endswith(b'}'):
        records = [json.loads(line) for line in path.read_bytes().splitlines()]
        if records and records[-1].get('schema_version') == 'research-signal-summary.v1':
            return records, path
    return _worker(args.trading_app, 'app:research:signals', frames(), path), path


def _scored(records: list[dict], end: int) -> list[tuple[int, dict]]:
    """Indexed results before the phase end; the end-boundary tick is diagnostic only."""
    results = [r for r in records if r.get('schema_version') == 'research-signal-result.v1']
    return [(index, r) for index, r in enumerate(results) if r['evaluated_ms'] < end]


def compare_signals(records: list[dict], frame: swing.Frame, rule, start: int, end: int) -> dict:
    results = [r for _, r in _scored(records, end)]
    decisions = list(frame.decisions(start, end))
    expected_ticks = [frame.close_ms(i) for i in decisions]
    expected_passed = {frame.close_ms(i) for i in decisions if rule(frame, i)}
    php_ticks = [r['evaluated_ms'] for r in results]
    php_passed = {r['evaluated_ms'] for r in results if r['passed']}
    return {'evaluated': len(php_ticks), 'expected_evaluated': len(expected_ticks),
            'tick_differences': len(set(php_ticks) ^ set(expected_ticks)),
            'passed': len(php_passed), 'expected_passed': len(expected_passed),
            'php_only': sorted(php_passed - expected_passed)[:20],
            'exploration_only': sorted(expected_passed - php_passed)[:20],
            'reasons': dict(sorted(Counter(r['reason_code'] for r in results).items()))}


def _variant_hash(args: argparse.Namespace, profile: str) -> str:
    environment = {**os.environ, 'TRADING_APP_AUTOLOAD': str(args.trading_app / 'vendor/autoload.php')}
    result = subprocess.run(('php', '-r', VARIANT_PHP, '--', profile), cwd=args.trading_app, env=environment,
                            check=True, capture_output=True, text=True)
    return result.stdout.strip()


def run_plans(args: argparse.Namespace, symbol: str, side: str, records: list[dict], signal_path: Path,
              start: int, end: int, out: Path) -> list[dict]:
    scored = _scored(records, end)
    results = [r for _, r in scored]
    passed = [(index, r) for index, r in scored if r['passed']]
    instruments = json.loads((args.root / 'instrument-assumptions.json').read_text())
    costs = json.loads((args.root / 'cost-assumptions.json').read_text())
    session = f'plans-{side}-{symbol}'
    run = {'symbol': symbol, 'source': results[0]['source'], 'signal_session_id': results[0]['session_id'],
           'signal_run_id': f'parity-{side}-{symbol}',
           'signal_output_sha256': hashlib.sha256(signal_path.read_bytes()).hexdigest(),
           'start_ms': start - int(WARMUP.total_seconds() * 1000), 'score_start_ms': start, 'end_ms': end}

    def frames():
        yield {'schema_version': 'research-plan-open.v1', 'session_id': session, 'profile': PROFILES[side],
               'baseline': results[0]['baseline'], 'source_runs': [run],
               'variant': {'schema_version': 'research-variant-selection.v1', 'id': 'baseline', 'diff': [],
                           'variant_hash': _variant_hash(args, PROFILES[side])},
               'instrument_assumptions': instruments, 'cost_assumptions': costs,
               'cost_profile': args.cost_profile, 'expected_signals': len(passed)}
        for index, signal in passed:
            view = {'schema_version': 'research-portfolio-view.v1', 'as_of_ms': signal['evaluated_ms'],
                    'equity_quote': 100000.0, 'available_balance_quote': 100000.0,
                    'realized_net_pnl_quote': 0.0, 'unrealized_net_pnl_quote': 0.0,
                    'open_positions': 0, 'pending_entries': 0, 'open_notional_quote': 0.0,
                    'pending_notional_quote': 0.0, 'reserved_risk_quote': 0.0,
                    'active_signal_hashes': [], 'active_symbols': []}
            view['portfolio_hash'] = canonical_hash(view)
            yield {'schema_version': 'research-plan-signal.v1', 'session_id': session, 'signal_index': index,
                   'signal': signal, 'portfolio': view}
        yield {'schema_version': 'research-plan-close.v1', 'session_id': session, 'expected_signals': len(passed)}

    records = _worker(args.trading_app, 'app:research:plans', frames(), out / f'plans-{side}-{symbol}.jsonl')
    return [r for r in records if r.get('schema_version') in ('research-plan.v1', 'research-plan-rejection.v1')]


def compare_plans(plans: list[dict], frame: swing.Frame, side: str, instrument: dict, profile: dict, end: int) -> dict:
    geometry = swing.GEOMETRIES[GEOMETRY]
    index_of = {frame.close_ms(i): i for i in range(frame.count)}
    differences, clipped, accepted, rejected = [], 0, 0, {}
    for plan in plans:
        expected, reason = swing.build_swing_plan(frame, index_of[plan['evaluated_ms']], geometry, instrument,
                                                  profile, end, side)
        if plan['schema_version'] == 'research-plan-rejection.v1':
            rejected[plan['reason_code']] = rejected.get(plan['reason_code'], 0) + 1
            if expected is not None or reason != plan['reason_code']:
                differences.append({'at': plan['evaluated_ms'], 'php': plan['reason_code'], 'exploration': reason})
            continue
        accepted += 1
        if expected is None:
            differences.append({'at': plan['evaluated_ms'], 'php': 'accepted', 'exploration': reason})
            continue
        observed = {'side': plan['side'], 'entry': plan['entry_price'], 'stop': plan['stop_price'],
                    'target': plan['targets'][0]['price'], 'quantity': plan['quantity'],
                    'net_risk': plan['risk_components_quote']['total_stop_loss'],
                    'net_r': plan['targets'][0]['net_r']}
        wanted = {'side': expected.side, 'entry': expected.entry, 'stop': expected.stop, 'target': expected.target,
                  'quantity': expected.quantity, 'net_risk': expected.net_risk, 'net_r': expected.net_r}
        if expected.deadline_ms == plan['evaluated_ms'] + geometry.horizon_ms:
            observed['deadline'], wanted['deadline'] = plan['holding_deadline_ms'], expected.deadline_ms
        else:
            clipped += 1
        if observed != wanted:
            differences.append({'at': plan['evaluated_ms'], 'php': observed, 'exploration': wanted})
    return {'plans': accepted, 'rejections': rejected, 'clipped_deadlines': clipped,
            'differences': len(differences), 'examples': differences[:5]}


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--dataset', type=Path, required=True, help='verified acquisition root (manifest.json)')
    parser.add_argument('--root', type=Path, required=True, help='swing exploration root (features, assumptions)')
    parser.add_argument('--phase', choices=tuple(ex.PHASES), required=True)
    parser.add_argument('--symbol', required=True)
    parser.add_argument('--side', choices=tuple(PROFILES), required=True)
    parser.add_argument('--cost-profile', default='baseline')
    parser.add_argument('--out', type=Path, required=True)
    parser.add_argument('--trading-app', type=Path, default=Path(__file__).resolve().parents[2] / 'trading-app')
    parser.add_argument('--reuse', action='store_true', help='reuse a complete signal transcript from --out')
    args = parser.parse_args(argv)
    args.dataset, args.root, args.trading_app = args.dataset.resolve(), args.root.resolve(), args.trading_app.resolve()
    args.out.mkdir(parents=True, exist_ok=True)

    start, end = ex.PHASES[args.phase]
    frame = swing.load_frames(args.root)[args.symbol]
    rule = getattr(swing.POLICIES[POLICY], args.side)
    records, signal_path = run_signals(args, args.symbol, args.side, start, end, args.out)
    signals = compare_signals(records, frame, rule, start, end)
    instruments = {i['symbol']: i for i in json.loads((args.root / 'instrument-assumptions.json').read_text())['symbols']}
    profiles = json.loads((args.root / 'cost-assumptions.json').read_text())['profiles']
    plans = compare_plans(run_plans(args, args.symbol, args.side, records, signal_path, start, end, args.out),
                          frame, args.side, instruments[args.symbol], profiles[args.cost_profile], end)
    report = {'phase': args.phase, 'symbol': args.symbol, 'side': args.side, 'cost_profile': args.cost_profile,
              'policy': POLICY, 'geometry': GEOMETRY, 'signals': signals, 'plans': plans}
    (args.out / f'report-{args.side}-{args.symbol}.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report))
    clean = (signals['tick_differences'] == 0 and signals['evaluated'] == signals['expected_evaluated']
             and not signals['php_only'] and not signals['exploration_only'] and plans['differences'] == 0)
    return 0 if clean else 1


if __name__ == '__main__':
    raise SystemExit(main())
