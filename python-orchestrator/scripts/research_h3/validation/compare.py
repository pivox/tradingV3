"""The five B0/B1/B2/B3 comparisons, the single primary criterion, the gatekept secondary family and the guards (C2).

Unit of analysis. Not the trade: the time block. Each variant's per-block net PnL (marked-to-market equity change,
fraction of the initial capital) is paired with the other variant's on the same block; both portfolios saw the same
candidates and the same market during that block. Thousands of trades inside a few dozen weeks are a few dozen
observations, and the blocks themselves may be autocorrelated: hence a dependence-robust test over blocks, the
effective sample size of the difference series, the Ljung-Box diagnostic and the trade-level design effect. The test
procedure is the plan's ``test_procedure`` (``stats.one_sided_test``); the synthetic power studies decide which
procedures keep their level under block dependence (H3-PREREGISTRATION-DRAFT.md section 8).

Decision (``plan``): primary = intersection-union of P1 (B3 - B0 > 0) and P2 (B3 > 0, against cash); verdicts
``INSUFFICIENT_EVIDENCE`` (minimum information not reached), ``CRITERION_NOT_MET``, ``CRITERION_MET_GUARDS_FAILED``
or ``CRITERION_MET``. Secondary tests are always computed and published, but they are confirmatory only when the
verdict is ``CRITERION_MET`` (serial gatekeeping), with Holm over the four. Nothing here ranks comparisons by their
result, and no verdict word means "profitable" or "validated": on synthetic or development data the report says so.

Seeds: each comparison's bootstrap seed is derived from the plan seed and the comparison id, so a result does not
depend on the order of the calls.
"""
from __future__ import annotations

import hashlib
from collections.abc import Mapping, Sequence
from decimal import Decimal

from ..contracts import ZERO, DataLabel, ExperimentManifest, ProtocolStatus, Variant, wire_decimal
from . import metrics as mt
from . import stats as st
from .plan import DESCRIPTIVE_VS_CASH, PRIMARY_COMPONENTS, SECONDARY_FAMILY, Comparison, StatisticalPlan
from .records import CostScenario, VariantRun

VERDICTS = ('INSUFFICIENT_EVIDENCE', 'CRITERION_NOT_MET', 'CRITERION_MET_GUARDS_FAILED', 'CRITERION_MET')
FACTORIAL = (Variant.B0, Variant.B1, Variant.B2, Variant.B3)


class CompareError(ValueError):
    """Runs that must not be compared. The message is a stable reason code."""


def comparison_seed(plan_seed: int, comparison_id: str) -> int:
    return int(hashlib.sha256(f'{plan_seed}:{comparison_id}'.encode()).hexdigest()[:12], 16)


def check_comparable(a: VariantRun, b: VariantRun) -> None:
    """Two runs are comparable only on the same experiment, candidate book, market, capital, period and costs."""
    keys = ('experiment_id', 'candidate_book_fingerprint', 'market_fingerprint', 'initial_equity', 'quote_ccy',
            'start_ms', 'end_ms', 'cost_scenario', 'candidate_ids')
    for key in keys:
        if getattr(a, key) != getattr(b, key):
            raise CompareError('compare_runs_not_comparable:' + key)
    if a.portfolio_id == b.portfolio_id or a.variant is b.variant:
        raise CompareError('compare_portfolios_not_independent')


def check_factorial(runs: Mapping[Variant, VariantRun]) -> None:
    if tuple(sorted(runs, key=lambda v: v.value)) != FACTORIAL:
        raise CompareError('compare_factorial_incomplete')
    for variant, run in runs.items():
        if run.variant is not variant:
            raise CompareError('compare_variant_key_mismatch')
    ordered = [runs[v] for v in FACTORIAL]
    for i, a in enumerate(ordered):
        for b in ordered[i + 1:]:
            check_comparable(a, b)


def comparison_series(runs: Mapping[Variant, VariantRun], comparison: Comparison, block_ms: int) -> list[float]:
    treatment = mt.block_returns(runs[comparison.treatment], block_ms)
    if comparison.reference is None:
        return treatment
    reference = mt.block_returns(runs[comparison.reference], block_ms)
    return [t - r for t, r in zip(treatment, reference)]


def _test(values: Sequence[float], plan: StatisticalPlan, comparison_id: str, alpha: float) -> dict:
    result = st.one_sided_test(values, procedure=plan.test_procedure, alpha=alpha, resamples=plan.resamples,
                               seed=comparison_seed(plan.seed, comparison_id), block_len=plan.block_len)
    out = result.as_dict()
    out['comparison_id'] = comparison_id
    return out


def _two_sided_interval(values: Sequence[float], plan: StatisticalPlan, comparison_id: str,
                        level: float = 0.90) -> dict:
    interval = st.two_sided_interval(values, procedure=plan.test_procedure, level=level, resamples=plan.resamples,
                                     seed=comparison_seed(plan.seed, comparison_id), block_len=plan.block_len)
    return {'estimate': (sum(values) / len(values)) if values else None, 'interval': interval, 'level': level,
            'procedure': plan.test_procedure}


def dependence_diagnostics(values: Sequence[float]) -> dict:
    n = len(values)
    lags = max(1, min(4, n // 5))
    lb = st.ljung_box(values, lags)
    return {'blocks': n, 'effective_blocks': st.effective_sample_size(values),
            'lag1_autocorrelation': st.autocorrelation(values, 1),
            'ljung_box': None if lb is None else {'q': lb[0], 'lags': lags, 'p_value': lb[1]}}


def trade_clustering(run: VariantRun, block_ms: int) -> dict:
    """Trades sharing a block share market shocks: ICC of trade R by block, Kish design effect, effective trades."""
    edges = mt.block_edges(run.start_ms, run.end_ms, block_ms)
    groups: list[list[float]] = [[] for _ in range(len(edges) - 1)]
    for t in run.trades:
        index = min(len(groups) - 1, max(0, (t.exit_ms - run.start_ms) // block_ms))
        groups[index].append(float(t.r_multiple))
    occupied = [g for g in groups if g]
    icc = st.intraclass_correlation(occupied)
    n = len(run.trades)
    mean_size = (n / len(occupied)) if occupied else 0.0
    deff = st.design_effect(mean_size, icc) if icc is not None else None
    return {'trades': n, 'occupied_blocks': len(occupied), 'mean_trades_per_block': mean_size,
            'icc_r_by_block': icc, 'design_effect': deff,
            'effective_trades': (n / deff) if deff else None}


def _instrument_net(run: VariantRun) -> dict[str, Decimal]:
    out: dict[str, Decimal] = {}
    for t in run.trades:
        out[t.inst_id] = out.get(t.inst_id, ZERO) + t.net_pnl
    return out


def guards(runs: Mapping[Variant, VariantRun], plan: StatisticalPlan, delta: Sequence[float],
           pessimistic: Mapping[Variant, VariantRun] | None) -> dict:
    g = plan.guards
    b3, b0 = runs[Variant.B3], runs[Variant.B0]
    out: dict[str, dict] = {}
    # Pessimistic costs (full replay with the pessimistic CostModel).
    if pessimistic is None:
        out['pessimistic_costs'] = {'passed': False, 'reason': 'guard_pessimistic_replay_missing'}
    else:
        check_factorial(pessimistic)
        if any(r.cost_scenario is not CostScenario.PESSIMISTIC for r in pessimistic.values()):
            raise CompareError('compare_pessimistic_runs_mislabelled')
        p_delta = comparison_series(pessimistic, PRIMARY_COMPONENTS[0], plan.block_ms)
        p_b3_net = pessimistic[Variant.B3].net_pnl
        passed = sum(p_delta) > 0 and p_b3_net > 0
        out['pessimistic_costs'] = {'passed': passed, 'mean_delta': sum(p_delta) / len(p_delta) if p_delta else None,
                                    'b3_net_pnl': wire_decimal(p_b3_net),
                                    'reason': 'guard_passed' if passed else 'guard_pessimistic_not_positive'}
    # Temporal stability of the primary difference.
    stab = mt.stability(list(delta), g.stability_periods)
    passed = stab['positive_periods'] >= g.stability_min_positive_periods
    out['temporal_stability'] = {'passed': passed, **stab,
                                 'reason': 'guard_passed' if passed else 'guard_unstable_over_periods'}
    # Concentration: the difference without its top contributing instrument; B3 without its top trades.
    n3, n0 = _instrument_net(b3), _instrument_net(b0)
    contributions = {i: n3.get(i, ZERO) - n0.get(i, ZERO) for i in set(n3) | set(n0)}
    total = sum(contributions.values(), ZERO)
    top = min(contributions, key=lambda i: (-contributions[i], i)) if contributions else None
    without_top = total - contributions[top] if top is not None else ZERO
    conc = mt.concentration(b3.trades, g.top_trades_share)
    b3_without_top_trades = Decimal(conc['net_without_top_trades'])
    passed = top is not None and without_top > 0 and b3_without_top_trades > 0
    out['concentration'] = {'passed': passed, 'top_instrument': top,
                            'delta_without_top_instrument': wire_decimal(without_top),
                            'b3_net_without_top_trades': conc['net_without_top_trades'],
                            'reason': 'guard_passed' if passed else 'guard_result_concentrated'}
    # Extreme losses.
    r3 = [float(t.r_multiple) for t in b3.trades]
    r0 = [float(t.r_multiple) for t in b0.trades]
    es3, es0 = mt.expected_shortfall(r3, g.tail_quantile), mt.expected_shortfall(r0, g.tail_quantile)
    worst3 = min(r3) if r3 else None
    passed = (es3 is not None and es0 is not None and es3 >= es0 - g.tail_es_tolerance_r
              and worst3 is not None and worst3 >= g.worst_trade_floor_r)
    out['extreme_losses'] = {'passed': passed, 'es_b3_r': es3, 'es_b0_r': es0, 'worst_b3_r': worst3,
                             'reason': 'guard_passed' if passed else 'guard_tail_worse'}
    # Minimum information.
    ess = st.effective_sample_size(delta) if delta else None
    passed = (len(delta) >= g.min_blocks and ess is not None and ess >= g.min_effective_blocks
              and len(b3.trades) >= g.min_trades_b3)
    out['minimum_information'] = {'passed': passed, 'blocks': len(delta), 'effective_blocks': ess,
                                  'b3_trades': len(b3.trades),
                                  'reason': 'guard_passed' if passed else 'guard_insufficient_information'}
    return out


def evidence_label(manifest: ExperimentManifest | None, plan: StatisticalPlan) -> str:
    if manifest is None:
        return 'unlabelled_not_evidence'
    labels = {p.label for p in manifest.datasets}
    if labels == {DataLabel.SYNTHETIC}:
        return 'synthetic_not_evidence'
    if DataLabel.SYNTHETIC in labels or DataLabel.DEVELOPMENT in labels:
        return 'development_not_confirmatory'
    if manifest.protocol.status is ProtocolStatus.FROZEN and plan.status is ProtocolStatus.FROZEN:
        return 'confirmatory'
    return 'protocol_not_frozen_not_confirmatory'


def evaluate(runs: Mapping[Variant, VariantRun], plan: StatisticalPlan, *,
             manifest: ExperimentManifest | None = None,
             pessimistic: Mapping[Variant, VariantRun] | None = None) -> dict:
    """The full pre-declared analysis of one B0-B3 experiment. Base-cost runs in ``runs``."""
    check_factorial(runs)
    if any(r.cost_scenario is not CostScenario.BASE for r in runs.values()):
        raise CompareError('compare_base_runs_mislabelled')
    if manifest is not None:
        if manifest.experiment_id != runs[Variant.B0].experiment_id:
            raise CompareError('compare_manifest_experiment_mismatch')
        if Decimal(manifest.initial_equity) != runs[Variant.B0].initial_equity:
            raise CompareError('compare_manifest_capital_mismatch')
    series = {c.comparison_id: comparison_series(runs, c, plan.block_ms)
              for c in PRIMARY_COMPONENTS + SECONDARY_FAMILY + DESCRIPTIVE_VS_CASH}
    delta = series[PRIMARY_COMPONENTS[0].comparison_id]
    primary_tests = {c.comparison_id: _test(series[c.comparison_id], plan, c.comparison_id, plan.alpha_primary)
                     for c in PRIMARY_COMPONENTS}
    primary_met = all(t['rejected'] for t in primary_tests.values())
    guard_results = guards(runs, plan, delta, pessimistic)
    if not guard_results['minimum_information']['passed']:
        verdict = 'INSUFFICIENT_EVIDENCE'
    elif not primary_met:
        verdict = 'CRITERION_NOT_MET'
    elif not all(g['passed'] for g in guard_results.values()):
        verdict = 'CRITERION_MET_GUARDS_FAILED'
    else:
        verdict = 'CRITERION_MET'
    confirmatory = verdict == 'CRITERION_MET'
    secondary_tests = {c.comparison_id: _test(series[c.comparison_id], plan, c.comparison_id, plan.alpha_secondary)
                       for c in SECONDARY_FAMILY}
    adjusted = st.holm({k: v['p_value'] for k, v in secondary_tests.items()})
    for key, test in secondary_tests.items():
        test['p_holm'] = adjusted[key]
        rejects = adjusted[key] <= plan.alpha_secondary
        test['status'] = (('secondary_rejects_null' if rejects else 'secondary_does_not_reject_null')
                          if confirmatory else 'secondary_exploratory_only_primary_not_met')
    def supported(*keys: str) -> str:
        if not confirmatory:
            return 'not_confirmatory'
        return 'supported' if all(secondary_tests[k]['status'] == 'secondary_rejects_null' for k in keys) \
            else 'not_supported'
    interaction = [b3 - b2 - b1 + b0 for b3, b2, b1, b0 in zip(
        *(mt.block_returns(runs[v], plan.block_ms) for v in (Variant.B3, Variant.B2, Variant.B1, Variant.B0)))]
    descriptive = {'interaction_b3_b2_b1_b0': _two_sided_interval(interaction, plan, 'interaction')}
    for c in DESCRIPTIVE_VS_CASH:
        descriptive[c.comparison_id] = _two_sided_interval(series[c.comparison_id], plan, c.comparison_id)
    return {
        'plan_status': plan.status.value,
        'plan_fingerprint': plan.fingerprint(),
        'evidence': evidence_label(manifest, plan),
        'verdict': verdict,
        'primary': {'rule': 'intersection_union_all_components_reject', 'met': primary_met,
                    'components': primary_tests},
        'guards': guard_results,
        'secondary': {'gatekeeping': 'confirmatory_only_if_verdict_criterion_met', 'multiplicity': 'holm',
                      'alpha_family': plan.alpha_secondary, 'tests': secondary_tests},
        'hypotheses': {'H3-A_scoring': supported('scoring'), 'H3-B_adaptive': supported('adaptation'),
                       'H3-C_combination': supported('adaptation_after_scoring', 'scoring_with_adaptation')},
        'descriptive': descriptive,
        'dependence': {'primary_difference': dependence_diagnostics(delta),
                       'b3_trades': trade_clustering(runs[Variant.B3], plan.block_ms),
                       'b0_trades': trade_clustering(runs[Variant.B0], plan.block_ms)},
        'metrics': {v.value: mt.summarize(runs[v], block_ms=plan.block_ms,
                                          top_trades_share=plan.guards.top_trades_share,
                                          tail_quantile=plan.guards.tail_quantile,
                                          stability_periods=plan.guards.stability_periods) for v in FACTORIAL},
    }
