"""Real-data parity checks for ``app.backtesting.research.exploration``.

kernel     Replays one hypothesis over a window of a prepared exploration root,
           both with the exploration engine and with the canonical
           PortfolioSimulator (``canonical_replay``), and prints every
           difference. The canonical side receives the exploration plans: this
           checks fills, exits, costs, funding and admission, not plan math.
plan-math  Evaluates the zone, protection and net-R gate of retained rows with
           the PHP value classes (EntryZonePriceMath, ProtectionPriceMath,
           NetRCostMath) through ``php`` and the trading-app autoloader, and
           compares them with ``build_plan``. The harness applies the 250 quote
           quantity cap itself (CanonicalRiskEngine's binding cap).

Both commands exit 1 on any difference. Read-only; no holdout row is accepted.
"""
from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from app.backtesting.research import exploration as ex  # noqa: E402

PHP_HARNESS = r'''
require getenv('TRADING_APP_AUTOLOAD');
use App\TradingCore\OrderPlan\Canonical\CanonicalEntryZonePolicy;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanException;
use App\TradingCore\OrderPlan\Canonical\CanonicalStopPolicy;
use App\TradingCore\OrderPlan\Canonical\CanonicalTargetPolicy;
use App\TradingCore\OrderPlan\Canonical\EntryZonePriceMath;
use App\TradingCore\OrderPlan\Canonical\NetRCostMath;
use App\TradingCore\OrderPlan\Canonical\ProtectionPriceMath;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
while (($line = fgets(STDIN)) !== false) {
    $in = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
    $z = $in['zone'];
    $out = ['id' => $in['id']];
    try {
        $zone = EntryZonePriceMath::calculate(new CanonicalEntryZonePolicy($z['anchor_source'], $z['anchor_timeframe'],
            $z['atr_timeframe'], (float) $z['zone_atr_multiplier'], (float) $z['minimum_half_width_rate'],
            (float) $z['maximum_half_width_rate'], 0.0, 240, 60, true),
            'long', (float) $in['anchor'], (float) $in['atr'], (float) $in['candidate'], (float) $in['tick']);
        $protection = ProtectionPriceMath::calculate(new CanonicalStopPolicy('atr', $in['stop_tf'], (float) $in['stop_mult'], null, 0.0),
            [new CanonicalTargetPolicy('tp1', (float) $in['target_r'], 'taker')], 'long', $zone['entry_price'],
            (float) $in['tick'], (float) $in['stop_atr']);
        $entry = BigDecimal::of(json_encode($zone['entry_price'], JSON_PRESERVE_ZERO_FRACTION));
        $step = BigDecimal::of(json_encode((float) $in['qty_step'], JSON_PRESERVE_ZERO_FRACTION));
        $qty = BigDecimal::of('250')->dividedBy($entry->multipliedBy($step), 0, RoundingMode::FLOOR)->multipliedBy($step);
        $p = $in['profile'];
        $risk = NetRCostMath::risk('long', $zone['entry_price'], $protection['stop_price'], $qty->toFloat(), 1.0,
            0.0002, 0.0005, (float) $p['entry_spread_rate'], (float) $p['stop_spread_rate'],
            (float) $p['entry_slippage_rate'], (float) $p['stop_slippage_rate'], (float) $p['funding_provision_rate'], 1);
        $target = $protection['targets'][0];
        $amounts = NetRCostMath::target($zone['entry_price'], $target->price, $qty->toFloat(), 1.0, 0.0005,
            (float) $p['target_spread_rate'], (float) $p['target_slippage_rate'], $risk);
        $out += ['status' => $amounts['net_r']->isLessThan(BigDecimal::of('1.3')) ? 'research_minimum_net_r_not_met' : 'accepted',
            'entry' => $zone['entry_price'], 'stop' => $protection['stop_price'], 'target' => $target->price,
            'qty' => $qty->toFloat(), 'net_risk' => $risk['net_risk']->toFloat(), 'net_r' => (string) $amounts['net_r']];
    } catch (CanonicalOrderPlanException $exception) {
        $out['status'] = $exception->reasonCode;
    }
    echo json_encode($out, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), "\n";
}
'''
def _utc_ms(text: str) -> int:
    return int(datetime.fromisoformat(text).replace(tzinfo=timezone.utc).timestamp() * 1000)


def kernel(args: argparse.Namespace) -> int:
    start, end = _utc_ms(args.start), _utc_ms(args.end)
    phase = 'training' if end <= ex.PHASES['training'][1] else 'validation'
    rows, candles, funding, instruments, profiles = ex._load_inputs(args.root, phase)
    events = json.loads((args.root / 'funding.json').read_text())
    policy, geometry, profile = ex.POLICIES[args.policy], ex.GEOMETRIES[args.geometry], profiles[args.profile]
    summary, outcomes = ex.run_hypothesis(rows, candles, funding, policy, geometry, instruments, profile, (start, end))
    canonical, rejections = ex.canonical_replay(rows, candles, events, policy, geometry, instruments, profile,
                                                (start, end))
    differences = ex.parity_differences(outcomes, summary['rejections'], canonical, rejections, end)
    print(json.dumps({'policy': args.policy, 'geometry': args.geometry, 'profile': args.profile, 'start': args.start,
                      'end': args.end, 'canonical_trades': len(canonical), 'rejections': dict(rejections),
                      'differences': differences[:20], 'difference_count': len(differences)}))
    return 1 if differences else 0


def plan_math(args: argparse.Namespace) -> int:
    rows, _, _, instruments, profiles = ex._load_inputs(args.root, args.phase)
    inputs, expected = [], {}
    for case in args.cases.split(','):
        geometry_id, profile_id = case.split(':')
        geometry, profile = ex.GEOMETRIES[geometry_id], profiles[profile_id]
        for symbol in ex.SYMBOLS:
            for row in rows[symbol]:
                values = (row[geometry.anchor_timeframe].get(geometry.anchor_source),
                          row[geometry.atr_timeframe].get('atr'), row[geometry.stop_timeframe].get('atr'),
                          row['15m'].get('close'))
                if not all(isinstance(v, float) and v > 0 for v in values):
                    continue
                key = len(inputs)
                expected[key] = ex.build_plan(row, symbol, geometry, instruments[symbol], profile)
                inputs.append(json.dumps({
                    'id': key, 'anchor': values[0], 'atr': values[1], 'stop_atr': values[2], 'candidate': values[3],
                    'zone': {name: getattr(geometry, name) for name in ('anchor_source', 'anchor_timeframe',
                             'atr_timeframe', 'zone_atr_multiplier', 'minimum_half_width_rate', 'maximum_half_width_rate')},
                    'tick': instruments[symbol]['tick_size'], 'qty_step': instruments[symbol]['quantity_step'],
                    'stop_tf': geometry.stop_timeframe, 'stop_mult': geometry.stop_atr_multiplier,
                    'target_r': geometry.target_risk_multiple, 'profile': profile}))
    autoload = Path(args.trading_app).resolve() / 'vendor' / 'autoload.php'
    result = subprocess.run(['php', '-r', PHP_HARNESS], input='\n'.join(inputs) + '\n', capture_output=True,
                            text=True, check=True, env={**os.environ, 'TRADING_APP_AUTOLOAD': str(autoload)})
    outcomes, mismatches = Counter(), Counter()
    for line in result.stdout.splitlines():
        php = json.loads(line)
        plan, reason = expected[php['id']]
        outcomes[php['status']] += 1
        mine = 'accepted' if plan is not None else reason
        if php['status'] != mine:
            mismatches[f"status:{php['status']}!={mine}"] += 1
        elif plan is not None and ((php['entry'], php['stop'], php['target'], php['qty'])
                                   != (plan.entry, plan.stop, plan.target, plan.quantity)
                                   or php['net_risk'] != plan.net_risk or float(php['net_r']) != plan.net_r):
            mismatches['value'] += 1
    print(json.dumps({'phase': args.phase, 'cases': len(inputs), 'php_outcomes': dict(outcomes),
                      'mismatches': dict(mismatches)}))
    return 1 if mismatches or len(inputs) != sum(outcomes.values()) else 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    commands = parser.add_subparsers(dest='command', required=True)
    k = commands.add_parser('kernel')
    k.add_argument('--root', type=Path, required=True)
    k.add_argument('--policy', required=True)
    k.add_argument('--geometry', required=True)
    k.add_argument('--profile', default='baseline')
    k.add_argument('--start', required=True, help='UTC date, after 2023-01-01 (the prephase minute is required)')
    k.add_argument('--end', required=True)
    p = commands.add_parser('plan-math')
    p.add_argument('--root', type=Path, required=True)
    p.add_argument('--phase', choices=tuple(ex.PHASES), required=True)
    p.add_argument('--cases', required=True, help='geometry:profile,...')
    p.add_argument('--trading-app', default=str(Path(__file__).resolve().parents[2] / 'trading-app'))
    args = parser.parse_args(argv)
    return kernel(args) if args.command == 'kernel' else plan_math(args)


if __name__ == '__main__':
    raise SystemExit(main())
