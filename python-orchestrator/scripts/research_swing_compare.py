#!/usr/bin/env python3
"""Every canonical swing unit against the exploration on the same window (swing campaign PR 6).

The canonical trades of each completed unit come from the PHP plans, the swing kernel and its
ledgers. Each trade is matched with ``swing.run_swing`` (the exploration, one plan per pair,
ten plans) by (pair, 4h decision) and compared field by field with ``swing.kernel_differences``:
fill and exit boundaries, exit reason and price, net PnL, funding, ambiguity and side.

Expected differences: a position still open at the window end. The exploration clips its
deadline to the window end and exits at the close before it (``holding_deadline``); the kernel
settles it at the research end at the final close (``research_end``). Such a trade differs only
in its exit reason, exit price and net PnL; any other field that differs on it is reported.
They are counted, not hidden. Rejection labels are not compared: the exploration counts them
before the plan arithmetic, the kernel after it.

Units of the 2026 holdout are never compared: the exploration refuses holdout windows.

usage: research_swing_compare.py --registry <registry root> [--holdout <holdout output root>]
                                 --exploration <exploration root> --output <report.json>
"""
from __future__ import annotations

import argparse
import json
import sys
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from app.backtesting.research import swing  # noqa: E402
from app.backtesting.research.exploration import HOLDOUT_START_MS  # noqa: E402

PHASE_END_LABEL = 'research_end!=holding_deadline'
PHASE_END_FIELDS = ('exit_reason', 'exit_price', 'net_pnl_quote')


def _ms(text: str) -> int:
    return int(datetime.fromisoformat(text.replace('Z', '+00:00')).timestamp() * 1000)


def _rows(path: Path):
    with path.open() as stream:
        for line in stream:
            yield json.loads(line)


def units(registry: Path, holdout: Path | None):
    """(phase, cost profile, window, unit root) of every registered and claimed unit."""
    for attempt in sorted((registry / 'attempts').iterdir()):
        registration = json.loads((attempt / 'registration.json').read_text())
        window = registration['window']
        yield (registration['phase'], registration['cost_profile'], (_ms(window['start']), _ms(window['end'])),
               attempt / 'campaign')
    if holdout is not None:
        contract = json.loads((registry.parent / (registry.name + '.c2b-holdout') / 'contract.json').read_text())
        window = (_ms(contract['window']['score_start']), contract['window']['end_ms'])
        for slot in contract['slots']:
            yield 'holdout', slot['cost_profile'], window, Path(slot['output_root'])


def compare(root: Path, window: tuple[int, int], cost: str, market) -> dict:
    frames, candles, blocks, funding, instruments, costs = market
    summary, outcomes = swing.run_swing(frames, candles, blocks, funding, swing.POLICIES['B30_2s'],
                                        swing.GEOMETRIES['S3.T5.H14d'], instruments, costs[cost], window, max_active=10)
    plans = {row['plan_hash']: row for row in _rows(root / 'plans.ndjson')}
    canonical = {(t['symbol'], plans[t['plan_hash']]['evaluated_ms']): t for t in _rows(root / 'trades.ndjson')}
    differences = swing.kernel_differences(outcomes, canonical, window[1])

    def key(difference: str) -> str:
        return difference.split(':')[1]

    open_at_end = {key(d) for d in differences if d.startswith('exit_reason:') and d.endswith(PHASE_END_LABEL)}
    phase_end = [d for d in differences if key(d) in open_at_end and d.split(':')[0] in PHASE_END_FIELDS]
    other = [d for d in differences if d not in phase_end]
    filled = [o for o in outcomes if o.filled]
    return {'canonical_trades': len(canonical), 'exploration_trades': len(filled),
            'canonical_net_quote': sum(float(t['net_pnl_quote']) for t in canonical.values()),
            'exploration_net_quote': sum(o.net for o in filled),
            'canonical_by_side': {side: sum(1 for t in canonical.values() if t.get('side') == side)
                                  for side in ('long', 'short')},
            'exploration_by_side': summary.get('by_side', {}),
            'open_at_end_trades': len(open_at_end), 'phase_end_differences': len(phase_end),
            'other_difference_count': len(other), 'other_differences': other[:50]}


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.split('\n\n')[0])
    parser.add_argument('--registry', type=Path, required=True)
    parser.add_argument('--holdout', type=Path)
    parser.add_argument('--exploration', type=Path, required=True, help='swing exploration root')
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args(argv)
    frames = swing.load_frames(args.exploration)
    market = (frames, *swing._load_market(args.exploration))
    report = []
    for phase, cost, window, root in units(args.registry, args.holdout):
        row = {'phase': phase, 'cost_profile': cost, 'window_ms': list(window), 'unit': str(root)}
        if not (root / 'trades.ndjson').exists():
            row['status'] = 'no_completed_ledger'
        elif window[1] > HOLDOUT_START_MS:
            row['status'] = 'holdout_not_compared'
        else:
            row.update(status='compared', **compare(root, window, cost, market))
        report.append(row)
    args.output.write_text(json.dumps(report, indent=2, default=str) + '\n')
    print(json.dumps([{k: r.get(k) for k in ('phase', 'cost_profile', 'status', 'canonical_trades',
                                              'exploration_trades', 'open_at_end_trades',
                                              'other_difference_count')} for r in report]))
    return 0 if all(r.get('other_difference_count', 0) == 0 for r in report) else 1


if __name__ == '__main__':
    raise SystemExit(main())
