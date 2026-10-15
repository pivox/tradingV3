"""Verification of the adaptive position manager (C5): same initial opportunities, static against adaptive exits.

The comparison pairs a static-exit run with the adaptive-exit run that has the same entry rule (B0 with B2, B1 with
B3): same candidate book, same market, same costs. Both runs come from one consistent replay of the full price path
(stops, targets, end of validity, partial exits, liquidity, fill assumptions, funding over the real holding time),
never from picking the best future exit. The checks below look for the signatures of a replay that cheats:
- ``oracle_exit_findings``: adaptive closes that land on the best price of the candidate's whole validity window,
  including bars after the exit (a hindsight exit), or outside the traded range;
- ``fill_realism_findings``: stop exits filled better than the stop, prices outside their bar's range, sizes above
  a share of the bar's traded value;
- ``funding_coverage_findings``: funding settlements between entry and exit that the trade did not count.
Thresholds are DRAFT (pre-registration).
"""
from __future__ import annotations

from collections.abc import Mapping, Sequence
from decimal import Decimal

from ..contracts import Bar, FundingObservation, Side, Variant
from . import stats as st
from .compare import check_comparable
from .records import ExitReason, TradeRecord, VariantRun

STATIC_TO_ADAPTIVE = {Variant.B0: Variant.B2, Variant.B1: Variant.B3}
ORACLE_SHARE_DRAFT = 0.5          # DRAFT: half of adaptive closes on the window's best price is not chance
VOLUME_SHARE_DRAFT = Decimal('0.01')  # DRAFT: one trade above 1 % of a bar's traded value needs a depth model


class AdaptiveCheckError(ValueError):
    """Invalid adaptive-check input. The message is a stable reason code."""


def paired_exits(static_run: VariantRun, adaptive_run: VariantRun) -> dict:
    """Per-candidate comparison of exits for candidates both portfolios traded, and the ones only one traded."""
    check_comparable(static_run, adaptive_run)
    if STATIC_TO_ADAPTIVE.get(static_run.variant) is not adaptive_run.variant:
        raise AdaptiveCheckError('adaptive_pair_not_same_entry_rule')
    s = {t.candidate_id: t for t in static_run.trades}
    a = {t.candidate_id: t for t in adaptive_run.trades}
    common = sorted(set(s) & set(a))
    delta_r = [float(a[c].r_multiple - s[c].r_multiple) for c in common]
    crosstab: dict[str, dict[str, int]] = {}
    for c in common:
        row = crosstab.setdefault(s[c].exit_reason.value, {})
        row[a[c].exit_reason.value] = row.get(a[c].exit_reason.value, 0) + 1
    ordered = sorted(delta_r)
    median = None
    if ordered:
        mid = len(ordered) // 2
        median = ordered[mid] if len(ordered) % 2 else (ordered[mid - 1] + ordered[mid]) / 2
    return {
        'static_variant': static_run.variant.value, 'adaptive_variant': adaptive_run.variant.value,
        'common_candidates': len(common),
        'only_static': sorted(set(s) - set(a)), 'only_adaptive': sorted(set(a) - set(s)),
        'mean_delta_r': st.mean(delta_r) if delta_r else None, 'median_delta_r': median,
        'improved_share': (sum(1 for d in delta_r if d > 0) / len(delta_r)) if delta_r else None,
        'exit_reason_crosstab': {k: dict(sorted(v.items())) for k, v in sorted(crosstab.items())},
        'mean_holding_ms': {'static': st.mean([s[c].holding_ms for c in common]) if common else None,
                            'adaptive': st.mean([a[c].holding_ms for c in common]) if common else None},
        'funding_paid': {'static': str(sum((s[c].funding for c in common), Decimal(0))),
                         'adaptive': str(sum((a[c].funding for c in common), Decimal(0)))},
        'partial_exits_adaptive': sum(a[c].partial_exits for c in common),
        'gap_excess': {'static': str(sum((s[c].gap_excess for c in common), Decimal(0))),
                       'adaptive': str(sum((a[c].gap_excess for c in common), Decimal(0)))},
    }


def _path(bars: Sequence[Bar], start_ms: int, end_ms: int) -> list[Bar]:
    return [b for b in bars if b.close_ms > start_ms and b.open_ms < end_ms]


def oracle_exit_findings(trades: Sequence[TradeRecord], bars_by_inst: Mapping[str, Sequence[Bar]],
                         window_end_ms: Mapping[str, int], tick_by_inst: Mapping[str, Decimal],
                         threshold: float = ORACLE_SHARE_DRAFT) -> dict:
    """Share of adaptive closes priced at the best price of the candidate's whole validity window."""
    examined = at_best = outside = 0
    flagged = []
    for t in trades:
        if t.exit_reason is not ExitReason.ADAPTIVE_CLOSE or t.partial_exits:
            continue
        bars = bars_by_inst.get(t.inst_id, ())
        window = _path(bars, t.entry_ms, window_end_ms.get(t.candidate_id, t.exit_ms))
        traded = _path(bars, t.entry_ms, t.exit_ms + 1)
        if not window or not traded:
            continue
        examined += 1
        tick = tick_by_inst.get(t.inst_id, Decimal(0))
        best = max(b.high for b in window) if t.side is Side.LONG else min(b.low for b in window)
        if abs(t.exit_price - best) <= tick:
            at_best += 1
            flagged.append(t.candidate_id)
        if not min(b.low for b in traded) - tick <= t.exit_price <= max(b.high for b in traded) + tick:
            outside += 1
    share = (at_best / examined) if examined else None
    suspected = share is not None and share >= threshold
    return {'examined': examined, 'at_window_best': at_best, 'share': share, 'threshold_draft': threshold,
            'exit_outside_traded_range': outside, 'flagged': flagged,
            'reason': 'adaptive_oracle_exit_suspected' if suspected or outside else 'adaptive_no_oracle_signature'}


def _bar_at(bars: Sequence[Bar], ms: int) -> Bar | None:
    """The bar containing ``ms``; a fill stamped exactly at a bar's close belongs to that bar when no later bar
    starts there (the last bar of the data)."""
    ending = None
    for b in bars:
        if b.open_ms <= ms < b.close_ms:
            return b
        if b.close_ms == ms:
            ending = b
    return ending


def _bars_at_boundary(bars: Sequence[Bar], ms: int) -> list[Bar]:
    """Bars an exit stamped at ``ms`` may belong to: the bar containing it and, when ``ms`` is a bar boundary, the
    bar ending there. The simulator stamps an intrabar exit (stop, target) at its bar's ``close_ms`` and an exit at an
    open at that bar's ``open_ms``; both instants are the same boundary (exit-time convention fixed at review R1-F11,
    backlog #14). Before, the bar ending there was ignored and every intrabar exit gave a false finding."""
    return [b for b in bars if b.open_ms <= ms < b.close_ms or b.close_ms == ms]


def fill_realism_findings(trades: Sequence[TradeRecord], bars_by_inst: Mapping[str, Sequence[Bar]],
                          volume_share: Decimal = VOLUME_SHARE_DRAFT) -> list[dict]:
    """Entries fill at a bar open (the bar containing the entry instant). An exit on a bar boundary is realistic when
    its price is inside the range of the bar ending there (an intrabar fill) or of the bar starting there (a fill at
    the open)."""
    findings = []
    for t in trades:
        bars = bars_by_inst.get(t.inst_id, ())
        if t.exit_reason is ExitReason.STOP and not t.partial_exits:
            better = t.exit_price > t.final_stop_price if t.side is Side.LONG else t.exit_price < t.final_stop_price
            if better:
                findings.append({'candidate_id': t.candidate_id, 'reason': 'fill_stop_better_than_stop'})
        bar = _bar_at(bars, t.entry_ms)
        if bar is None:
            findings.append({'candidate_id': t.candidate_id, 'reason': 'fill_entry_no_bar'})
        elif not bar.low <= t.entry_price <= bar.high:
            findings.append({'candidate_id': t.candidate_id, 'reason': 'fill_entry_outside_bar_range'})
        elif bar.volume_quote > 0 and t.notional_at_entry > volume_share * bar.volume_quote:
            findings.append({'candidate_id': t.candidate_id, 'reason': 'fill_size_above_volume_share'})
        candidates = _bars_at_boundary(bars, t.exit_ms)
        if not candidates:
            findings.append({'candidate_id': t.candidate_id, 'reason': 'fill_exit_no_bar'})
        elif not any(b.low <= t.exit_price <= b.high for b in candidates):
            findings.append({'candidate_id': t.candidate_id, 'reason': 'fill_exit_outside_bar_range'})
    return findings


def funding_coverage_findings(trades: Sequence[TradeRecord], funding: Sequence[FundingObservation]) -> list[dict]:
    """Settlements with entry_ms < funding_ms <= exit_ms must all be counted by the trade (real holding time)."""
    findings = []
    for t in trades:
        expected = sum(1 for f in funding if f.inst_id == t.inst_id and t.entry_ms < f.funding_ms <= t.exit_ms)
        if expected != t.funding_events:
            findings.append({'candidate_id': t.candidate_id, 'expected': expected, 'counted': t.funding_events,
                             'reason': 'funding_events_mismatch'})
    return findings
