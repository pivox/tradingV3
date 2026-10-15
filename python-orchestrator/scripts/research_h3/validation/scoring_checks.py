"""Verification of the opportunity scoring engine (C4). A good classification metric is not enough: the scoring
verdict also requires the economic test (B1 - B0, ``compare``), and reports ``ranking_without_economic_value`` when
the ranking looks good but the portfolio does not improve.

Labels. The outcome of a candidate is its net R under the static reference management (B0 policy), produced by a
consistent replay of that candidate: never the best exit seen afterwards. ``join_scores_outcomes`` takes it from a
B0 run; a candidate B0 did not trade (risk budget, execution) has no label and is counted as ``unlabelled`` (a
capacity-free label replay with the B0 policy removes that selection; the simulator owns it). A score must be known
before the trade it labels: a score decided after the entry is refused.

Groups fixed without future data. Bucket edges come from ``walkforward.FrozenEdges``: either fixed a priori in the
protocol, or fitted on training rows only and applied to rows decided at or after the fit cutoff.
"""
from __future__ import annotations

import math
from collections.abc import Callable, Mapping, Sequence
from dataclasses import dataclass

from ..contracts import OpportunityScore, ScoreStatus, ScoreVerdict, Variant
from . import stats as st
from .records import ExitReason, VariantRun
from .walkforward import FrozenEdges


class ScoringCheckError(ValueError):
    """Invalid scoring-check input. The message is a stable reason code."""


@dataclass(frozen=True)
class ScoredOutcome:
    candidate_id: str
    decision_ms: int
    inst_id: str
    status: ScoreStatus
    verdict: ScoreVerdict
    value: float | None          # utility when present, else expected net R; None when not estimated
    p_stop: float | None
    realised_r: float | None     # net R under the static reference management; None when unlabelled
    stopped: bool | None


def _score_value(score: OpportunityScore) -> float | None:
    if score.utility is not None:
        return float(score.utility)
    return None if score.expected_net_r is None else float(score.expected_net_r)


def join_scores_outcomes(scores: Sequence[OpportunityScore], label_run: VariantRun) -> list[ScoredOutcome]:
    if label_run.variant is not Variant.B0:
        raise ScoringCheckError('scoring_labels_must_come_from_static_reference')
    by_candidate = {t.candidate_id: t for t in label_run.trades}
    seen = set()
    rows = []
    for score in scores:
        if score.candidate_id in seen:
            raise ScoringCheckError('scoring_duplicate_score')
        seen.add(score.candidate_id)
        if score.candidate_id not in label_run.candidate_ids:
            raise ScoringCheckError('scoring_score_for_unknown_candidate')
        trade = by_candidate.get(score.candidate_id)
        decision = score.header.clock.decision_ms
        if trade is not None and decision > trade.entry_ms:
            raise ScoringCheckError('scoring_score_after_entry')
        rows.append(ScoredOutcome(
            candidate_id=score.candidate_id, decision_ms=decision, inst_id=score.header.instrument.inst_id,
            status=score.status, verdict=score.verdict, value=_score_value(score),
            p_stop=None if score.p_stop is None else float(score.p_stop),
            realised_r=None if trade is None else float(trade.r_multiple),
            stopped=None if trade is None else trade.exit_reason is ExitReason.STOP))
    return rows


def _labelled(rows: Sequence[ScoredOutcome]) -> list[ScoredOutcome]:
    return [r for r in rows if r.realised_r is not None and r.value is not None
            and r.status is ScoreStatus.ESTIMATED]


# -------------------------------------------------------------------------------------------------- calibration

def calibration_table(rows: Sequence[ScoredOutcome], edges: FrozenEdges) -> list[dict]:
    """Mean predicted vs mean realised net R per bucket of predicted value (edges fixed without future data)."""
    usable = _labelled(rows)
    for r in usable:
        edges.check_out_of_sample(r.decision_ms)
    buckets: dict[int, list[ScoredOutcome]] = {}
    for r in usable:
        buckets.setdefault(edges.bucket(r.value), []).append(r)  # type: ignore[arg-type]
    table = []
    for index in range(len(edges.edges) + 1):
        members = buckets.get(index, [])
        lo = edges.edges[index - 1] if index > 0 else None
        hi = edges.edges[index] if index < len(edges.edges) else None
        table.append({'bucket': index, 'lower': lo, 'upper': hi, 'n': len(members),
                      'mean_predicted': st.mean([m.value for m in members]) if members else None,  # type: ignore
                      'mean_realised_r': st.mean([m.realised_r for m in members]) if members else None})  # type: ignore
    return table


def calibration_regression(rows: Sequence[ScoredOutcome]) -> dict:
    """OLS of realised R on predicted R: a calibrated predictor has slope 1 and intercept 0."""
    usable = _labelled(rows)
    x = [r.value for r in usable]
    y = [r.realised_r for r in usable]
    n = len(x)
    if n < 3:
        return {'n': n, 'slope': None, 'intercept': None, 'reason': 'scoring_insufficient_labels'}
    mx, my = sum(x) / n, sum(y) / n  # type: ignore[arg-type]
    sxx = sum((a - mx) ** 2 for a in x)  # type: ignore[operator]
    if sxx == 0:
        return {'n': n, 'slope': None, 'intercept': None, 'reason': 'scoring_predictions_constant'}
    slope = sum((a - mx) * (b - my) for a, b in zip(x, y)) / sxx  # type: ignore[operator]
    return {'n': n, 'slope': slope, 'intercept': my - slope * mx, 'reason': 'scoring_calibration_computed',
            'mean_error': my - mx}


def brier(rows: Sequence[ScoredOutcome], edges: Sequence[float] = (0.2, 0.4, 0.6, 0.8)) -> dict:
    """Brier score of ``p_stop`` against stop exits, and its reliability table (probability edges a priori)."""
    usable = [r for r in rows if r.p_stop is not None and r.stopped is not None]
    if not usable:
        return {'n': 0, 'brier': None, 'reliability': []}
    score = sum((r.p_stop - (1.0 if r.stopped else 0.0)) ** 2 for r in usable) / len(usable)  # type: ignore
    frozen = FrozenEdges(tuple(edges), 0, 'a_priori')
    groups: dict[int, list[ScoredOutcome]] = {}
    for r in usable:
        groups.setdefault(frozen.bucket(r.p_stop), []).append(r)  # type: ignore[arg-type]
    reliability = [{'bucket': k, 'n': len(v), 'mean_p_stop': sum(m.p_stop for m in v) / len(v),  # type: ignore
                    'stop_rate': sum(1 for m in v if m.stopped) / len(v)} for k, v in sorted(groups.items())]
    return {'n': len(usable), 'brier': score, 'reliability': reliability}


# ---------------------------------------------------------------------------------------------------- ranking

def rank_quality(rows: Sequence[ScoredOutcome], min_cross_section: int = 3) -> dict:
    """Pooled Spearman(score, realised R) and the cross-sectional rank correlation per decision instant."""
    usable = _labelled(rows)
    pooled = st.spearman([r.value for r in usable], [r.realised_r for r in usable])  # type: ignore[misc]
    by_instant: dict[int, list[ScoredOutcome]] = {}
    for r in usable:
        by_instant.setdefault(r.decision_ms, []).append(r)
    per_instant = []
    for decision_ms, members in sorted(by_instant.items()):
        if len(members) >= min_cross_section:
            ic = st.spearman([m.value for m in members], [m.realised_r for m in members])  # type: ignore[misc]
            if ic is not None:
                per_instant.append((decision_ms, ic))
    ics = [ic for _, ic in per_instant]
    return {'n': len(usable), 'spearman_pooled': pooled, 'instants': len(ics),
            'mean_cross_sectional_ic': st.mean(ics) if ics else None,
            'positive_ic_share': (sum(1 for ic in ics if ic > 0) / len(ics)) if ics else None,
            'per_instant': per_instant}


def rank_ic_test(rows: Sequence[ScoredOutcome], *, start_ms: int, block_ms: int, alpha: float, procedure: str,
                 resamples: int = 9_999, seed: int = 0, min_cross_section: int = 3) -> dict:
    """Uncertainty of the ranking: one-sided test (the plan's procedure) of the per-block mean cross-sectional IC."""
    per_instant = rank_quality(rows, min_cross_section)['per_instant']
    blocks: dict[int, list[float]] = {}
    for decision_ms, ic in per_instant:
        blocks.setdefault((decision_ms - start_ms) // block_ms, []).append(ic)
    series = [sum(v) / len(v) for _, v in sorted(blocks.items())]
    return st.one_sided_test(series, procedure=procedure, alpha=alpha, resamples=resamples, seed=seed).as_dict()


def bucket_returns(rows: Sequence[ScoredOutcome], edges: FrozenEdges) -> dict:
    """Net R by score group; the groups are fixed without future data (``FrozenEdges``)."""
    table = calibration_table(rows, edges)
    filled = [b for b in table if b['n'] > 0]
    spread = (filled[-1]['mean_realised_r'] - filled[0]['mean_realised_r']) if len(filled) >= 2 else None
    means = [b['mean_realised_r'] for b in filled]
    monotone = all(b >= a for a, b in zip(means, means[1:])) if len(means) >= 2 else None
    return {'buckets': table, 'top_minus_bottom_r': spread, 'monotone_non_decreasing': monotone,
            'edges_source': edges.source, 'edges_fitted_until_ms': edges.fitted_until_ms}


# --------------------------------------------------------------------------------------------------- abstention

def abstention(rows: Sequence[ScoredOutcome]) -> dict:
    """Did the scorer abstain on the right candidates? Compares the reference (B0) outcomes of the candidates it
    kept (TRADE_CANDIDATE) and of those it refused (NO_TRADE, any status)."""
    kept = [r for r in rows if r.verdict is ScoreVerdict.TRADE_CANDIDATE]
    refused = [r for r in rows if r.verdict is ScoreVerdict.NO_TRADE]
    kept_r = [r.realised_r for r in kept if r.realised_r is not None]
    refused_r = [r.realised_r for r in refused if r.realised_r is not None]
    by_status: dict[str, int] = {}
    for r in refused:
        by_status[r.status.value] = by_status.get(r.status.value, 0) + 1
    mean_kept, mean_refused = st.mean(kept_r) if kept_r else None, st.mean(refused_r) if refused_r else None
    return {'candidates': len(rows), 'abstention_rate': (len(refused) / len(rows)) if rows else None,
            'abstained_by_status': dict(sorted(by_status.items())),
            'kept_labelled': len(kept_r), 'refused_labelled': len(refused_r),
            'unlabelled': sum(1 for r in rows if r.realised_r is None),
            'mean_r_kept': mean_kept, 'mean_r_refused': mean_refused,
            'kept_minus_refused_r': (mean_kept - mean_refused)
            if mean_kept is not None and mean_refused is not None else None}


# ---------------------------------------------------------------------------------------------------- stability

def stability_by(rows: Sequence[ScoredOutcome], key: Callable[[ScoredOutcome], str], min_n: int = 10) -> dict:
    """Spearman per group (period, instrument...) and the share of groups with a positive rank correlation."""
    groups: dict[str, list[ScoredOutcome]] = {}
    for r in _labelled(rows):
        groups.setdefault(key(r), []).append(r)
    per_group = {}
    for name, members in sorted(groups.items()):
        rho = st.spearman([m.value for m in members], [m.realised_r for m in members]) \
            if len(members) >= min_n else None  # type: ignore[misc]
        per_group[name] = {'n': len(members), 'spearman': rho}
    rated = [g['spearman'] for g in per_group.values() if g['spearman'] is not None]
    return {'groups': per_group, 'rated_groups': len(rated),
            'positive_share': (sum(1 for r in rated if r > 0) / len(rated)) if rated else None}


def period_key(start_ms: int, period_ms: int) -> Callable[[ScoredOutcome], str]:
    return lambda r: f'p{(r.decision_ms - start_ms) // period_ms:04d}'


def instrument_key(r: ScoredOutcome) -> str:
    return r.inst_id


def population_stability_index(train: Sequence[float], test: Sequence[float], edges: FrozenEdges,
                               floor: float = 1e-4) -> float | None:
    """PSI of a feature between training and test, on training-fitted (or a-priori) edges."""
    if not train or not test:
        return None
    k = len(edges.edges) + 1
    p = [0] * k
    q = [0] * k
    for v in train:
        p[edges.bucket(v)] += 1
    for v in test:
        q[edges.bucket(v)] += 1
    total = 0.0
    for a, b in zip(p, q):
        pa, qb = max(a / len(train), floor), max(b / len(test), floor)
        total += (qb - pa) * math.log(qb / pa)
    return total


def coefficient_sign_stability(fold_parameters: Sequence[Mapping[str, float]]) -> dict:
    """Across walk-forward folds: share of folds where each parameter keeps its majority sign."""
    names = sorted({n for params in fold_parameters for n in params})
    out = {}
    for name in names:
        values = [params[name] for params in fold_parameters if name in params]
        positive = sum(1 for v in values if v > 0)
        negative = sum(1 for v in values if v < 0)
        out[name] = {'folds': len(values), 'majority_sign_share': max(positive, negative) / len(values)
                     if values else None}
    return out


# ----------------------------------------------------------------------------------------------------- verdict

def scoring_verdict(rank_test: Mapping, economic_test: Mapping) -> str:
    """Combine the ranking test and the economic test (B1 - B0) into one stable reason code. ``economic_test`` is
    the ``scoring`` entry of ``compare.evaluate(...)['secondary']['tests']``."""
    ranking = bool(rank_test.get('rejected'))
    status = economic_test.get('status')
    if status == 'secondary_exploratory_only_primary_not_met':
        return 'scoring_economic_not_confirmatory_ranking_' + ('positive' if ranking else 'not_shown')
    economic = status == 'secondary_rejects_null'
    if ranking and economic:
        return 'scoring_ranking_and_economic_value'
    if ranking:
        return 'scoring_ranking_without_economic_value'
    if economic:
        return 'scoring_economic_value_without_ranking_check'
    return 'scoring_no_value_shown'
