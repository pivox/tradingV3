"""Portfolio metrics of one variant run (C3). Per-trade performance alone is never enough.

Precision. Amounts (PnL, costs, funding, equity, drawdown) are Decimal sums of the run's Decimal records and are
exact; ratios computed in Decimal use the default 28-digit context. Distribution statistics (expected shortfall,
stability, block series) are floats. The JSON report writes Decimals as plain strings (``wire_decimal``).

Block series. The per-block net PnL is the change of the marked-to-market equity between consecutive block edges
(start, start + block_ms, ..., end; the last block may be shorter). Equity at an edge is the last equity point at or
before it, so the simulator must mark to market at least at every block edge for open positions to be attributed to
the right block (otherwise their PnL lands in the block where the next point is recorded; totals are unaffected).
"""
from __future__ import annotations

import math
from collections.abc import Sequence
from decimal import Decimal

from ..contracts import ZERO, wire_decimal
from .records import EquityPoint, ExitReason, RefusalRecord, TradeRecord, VariantRun


class MetricsError(ValueError):
    """Invalid metrics input. The message is a stable reason code."""


def _s(value: Decimal | None) -> str | None:
    return None if value is None else wire_decimal(value)


# ------------------------------------------------------------------------------------------------------- blocks

def block_edges(start_ms: int, end_ms: int, block_ms: int) -> tuple[int, ...]:
    if block_ms <= 0 or end_ms <= start_ms:
        raise MetricsError('metrics_block_parameters_invalid')
    edges = list(range(start_ms, end_ms, block_ms))
    edges.append(end_ms)
    return tuple(edges)


def equity_at(curve: Sequence[EquityPoint], ts_ms: int) -> Decimal:
    """Equity of the last point at or before ``ts_ms``."""
    if not curve or ts_ms < curve[0].ts_ms:
        raise MetricsError('metrics_equity_before_start')
    lo, hi = 0, len(curve) - 1
    while lo < hi:
        mid = (lo + hi + 1) // 2
        if curve[mid].ts_ms <= ts_ms:
            lo = mid
        else:
            hi = mid - 1
    return curve[lo].equity


def block_pnl(run: VariantRun, block_ms: int) -> list[Decimal]:
    edges = block_edges(run.start_ms, run.end_ms, block_ms)
    values = [equity_at(run.equity_curve, edge) for edge in edges]
    return [b - a for a, b in zip(values, values[1:])]


def block_returns(run: VariantRun, block_ms: int) -> list[float]:
    """Per-block net PnL as a fraction of the initial capital (fixed denominator: no compounding confound)."""
    return [float(v / run.initial_equity) for v in block_pnl(run, block_ms)]


# ---------------------------------------------------------------------------------------------------- drawdown

def drawdown(curve: Sequence[EquityPoint]) -> dict:
    """Maximum peak-to-trough drawdown of the equity curve, in quote and as a fraction of the peak."""
    if not curve:
        raise MetricsError('metrics_equity_curve_empty')
    peak = curve[0].equity
    peak_ms = curve[0].ts_ms
    worst = ZERO
    worst_fraction = ZERO
    worst_peak_ms = worst_trough_ms = curve[0].ts_ms
    underwater_since: int | None = None
    longest_underwater = 0
    for point in curve:
        if point.equity >= peak:
            if underwater_since is not None:
                longest_underwater = max(longest_underwater, point.ts_ms - underwater_since)
                underwater_since = None
            peak, peak_ms = point.equity, point.ts_ms
            continue
        if underwater_since is None:
            underwater_since = peak_ms
        depth = peak - point.equity
        if depth > worst:
            worst, worst_peak_ms, worst_trough_ms = depth, peak_ms, point.ts_ms
            worst_fraction = depth / peak if peak > 0 else Decimal('1')
    if underwater_since is not None:
        longest_underwater = max(longest_underwater, curve[-1].ts_ms - underwater_since)
    return {'max_drawdown_quote': _s(worst), 'max_drawdown_fraction_of_peak': _s(worst_fraction),
            'peak_ms': worst_peak_ms, 'trough_ms': worst_trough_ms, 'longest_underwater_ms': longest_underwater}


# ------------------------------------------------------------------------------------------------------ trades

def profit_factor(trades: Sequence[TradeRecord]) -> tuple[Decimal | None, str | None]:
    gains = sum((t.net_pnl for t in trades if t.net_pnl > 0), ZERO)
    losses = -sum((t.net_pnl for t in trades if t.net_pnl < 0), ZERO)
    if not trades:
        return None, 'metrics_no_trades'
    if losses == 0:
        return None, 'metrics_no_losing_trades'
    return gains / losses, None


def expected_shortfall(values: Sequence[float], quantile: float) -> float | None:
    """Mean of the worst ceil(quantile * n) values (lower tail). None without values."""
    if not 0 < quantile < 1:
        raise MetricsError('metrics_quantile_invalid')
    values = sorted(float(v) for v in values)
    if not values:
        return None
    k = max(1, int(math.ceil(quantile * len(values))))
    return sum(values[:k]) / k


def concentration(trades: Sequence[TradeRecord], top_share: float) -> dict:
    """How much of the result depends on a few trades or one instrument."""
    if not 0 < top_share < 1:
        raise MetricsError('metrics_share_invalid')
    net = sum((t.net_pnl for t in trades), ZERO)
    by_instrument: dict[str, Decimal] = {}
    for t in trades:
        by_instrument[t.inst_id] = by_instrument.get(t.inst_id, ZERO) + t.net_pnl
    ordered = sorted(trades, key=lambda t: (-t.net_pnl, t.candidate_id))
    k = int(math.ceil(top_share * len(ordered))) if ordered else 0
    top = ordered[:k]
    gains = sum((t.net_pnl for t in trades if t.net_pnl > 0), ZERO)
    top_instrument = min(by_instrument, key=lambda i: (-by_instrument[i], i)) if by_instrument else None
    contributions = [v for v in by_instrument.values()]
    total_abs = sum((abs(v) for v in contributions), ZERO)
    hhi = (sum(((abs(v) / total_abs) ** 2 for v in contributions), ZERO) if total_abs > 0 else None)
    return {
        'top_trades_count': k,
        'top_trades_share_of_gains': _s(sum((t.net_pnl for t in top), ZERO) / gains) if gains > 0 else None,
        'net_without_top_trades': _s(net - sum((t.net_pnl for t in top), ZERO)),
        'top_instrument': top_instrument,
        'top_instrument_net': _s(by_instrument[top_instrument]) if top_instrument else None,
        'net_without_top_instrument': _s(net - by_instrument[top_instrument]) if top_instrument else None,
        'instrument_count': len(by_instrument),
        'instrument_herfindahl': _s(hhi),
    }


def stability(block_values: Sequence[float], periods: int) -> dict:
    """Sum of the block series in ``periods`` consecutive chronological periods of (almost) equal block counts."""
    if periods < 1:
        raise MetricsError('metrics_periods_invalid')
    n = len(block_values)
    sums = []
    for p in range(periods):
        lo, hi = (p * n) // periods, ((p + 1) * n) // periods
        sums.append(sum(block_values[lo:hi]) if hi > lo else None)
    defined = [s for s in sums if s is not None]
    return {'periods': periods, 'period_sums': sums,
            'positive_periods': sum(1 for s in defined if s > 0),
            'positive_blocks_share': (sum(1 for v in block_values if v > 0) / n) if n else None,
            'worst_block': min(block_values) if n else None, 'best_block': max(block_values) if n else None}


def cost_stress(trades: Sequence[TradeRecord], multiplier: Decimal) -> dict:
    """Sensitivity bound only: re-costs closed trades with execution costs x ``multiplier`` and adverse funding
    (paid x multiplier, received / multiplier). It ignores the path changes higher costs would cause (fills, stops);
    the protocol's pessimistic scenario is a full replay with the pessimistic CostModel."""
    if multiplier < 1:
        raise MetricsError('metrics_multiplier_invalid')
    net = ZERO
    r_values = []
    for t in trades:
        funding = t.funding * multiplier if t.funding > 0 else t.funding / multiplier
        stressed = t.gross_pnl - t.execution_cost * multiplier - funding
        net += stressed
        r_values.append(stressed / t.initial_risk)
    mean_r = (sum(r_values, ZERO) / len(r_values)) if r_values else None
    return {'multiplier': _s(multiplier), 'net_pnl': _s(net), 'expectancy_net_r': _s(mean_r)}


def refusal_summary(refusals: Sequence[RefusalRecord], candidates: int) -> dict:
    by_stage: dict[str, int] = {}
    by_reason: dict[str, int] = {}
    for r in refusals:
        by_stage[r.stage.value] = by_stage.get(r.stage.value, 0) + 1
        for reason in r.reasons:
            by_reason[reason] = by_reason.get(reason, 0) + 1
    return {'refused': len(refusals), 'refusal_rate': (len(refusals) / candidates) if candidates else None,
            'by_stage': dict(sorted(by_stage.items())), 'by_reason': dict(sorted(by_reason.items()))}


# ---------------------------------------------------------------------------------------------------- summary

def summarize(run: VariantRun, *, block_ms: int, top_trades_share: float = 0.05, tail_quantile: float = 0.05,
              stability_periods: int = 4, stress_multiplier: Decimal = Decimal('2')) -> dict:
    """Every C3 metric of one run, JSON-friendly."""
    trades = run.trades
    net = run.net_pnl
    r_values = [t.r_multiple for t in trades]
    r_float = [float(r) for r in r_values]
    sorted_r = sorted(r_values)
    pf, pf_reason = profit_factor(trades)
    blocks = block_returns(run, block_ms)
    exit_reasons: dict[str, int] = {}
    for t in trades:
        exit_reasons[t.exit_reason.value] = exit_reasons.get(t.exit_reason.value, 0) + 1
    median = None
    if sorted_r:
        mid = len(sorted_r) // 2
        median = sorted_r[mid] if len(sorted_r) % 2 else (sorted_r[mid - 1] + sorted_r[mid]) / 2
    return {
        'variant': run.variant.value,
        'cost_scenario': run.cost_scenario.value,
        'candidates': len(run.candidate_ids),
        'trades': len(trades),
        'net_pnl': _s(net),
        'return_on_initial_capital': _s(net / run.initial_equity),
        'gross_pnl': _s(sum((t.gross_pnl for t in trades), ZERO)),
        'expectancy_net_r': _s(sum(r_values, ZERO) / len(r_values)) if r_values else None,
        'median_net_r': _s(median),
        'win_rate': (sum(1 for t in trades if t.net_pnl > 0) / len(trades)) if trades else None,
        'profit_factor': _s(pf),
        'profit_factor_undefined_reason': pf_reason,
        'drawdown': drawdown(run.equity_curve),
        'costs': {'fees': _s(sum((t.fees for t in trades), ZERO)),
                  'spread': _s(sum((t.spread_cost for t in trades), ZERO)),
                  'slippage': _s(sum((t.slippage for t in trades), ZERO)),
                  'execution_total': _s(sum((t.execution_cost for t in trades), ZERO)),
                  'funding_net_paid': _s(sum((t.funding for t in trades), ZERO)),
                  'funding_events': sum(t.funding_events for t in trades)},
        'refusals': refusal_summary(run.refusals, len(run.candidate_ids)),
        'exit_reasons': dict(sorted(exit_reasons.items())),
        'extreme_losses': {
            'worst_trade_r': _s(sorted_r[0]) if sorted_r else None,
            'expected_shortfall_r': expected_shortfall(r_float, tail_quantile),
            'tail_quantile': tail_quantile,
            'trades_below_minus_1r': sum(1 for r in r_values if r < -1),
            'gap_excess_total': _s(sum((t.gap_excess for t in trades), ZERO)),
            'stop_exits': sum(1 for t in trades if t.exit_reason is ExitReason.STOP),
        },
        'temporal_stability': stability(blocks, stability_periods),
        'blocks': len(blocks),
        'concentration': concentration(trades, top_trades_share),
        'cost_stress': cost_stress(trades, stress_multiplier),
        'mean_holding_ms': (sum(t.holding_ms for t in trades) / len(trades)) if trades else None,
    }
