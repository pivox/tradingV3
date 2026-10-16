"""Explicit, multi-component uncertainty report for an H3 score (H3-A testability, agent C). Not a calibrated model.

Why. The V1 engine reports one number, ``uncertainty = se / (se + |expected_net_r|) = 1 / (1 + |t|)``. It is a
monotone transform of a t-ratio, useful as a heuristic ranking key, but it is not a probability, not calibrated and
it mixes several things:

- it measures only the standard error of the CONDITIONAL MEAN under the OLS formula, with the manifest's declared
  ``n_train`` (nothing checks that it is an effective count; overlapping trades and correlated coins overstate it);
- it says nothing about the spread of ONE trade (``residual_sd_r`` is far larger than the SE of the mean: a trade is
  stopped or not, and R is bounded below near -1 except on gaps);
- it falls towards 0 when ``|expected_net_r|`` is large, i.e. it is most confident on the most extrapolated,
  extreme predictions; the only domain check is a marginal ``|z| <= z_support_max`` per feature;
- it ignores the uncertainty of ``p_stop`` and ``tail_loss_r``, of future costs, ridge shrinkage bias,
  collinearity beyond ``unc.cinv``, regime change and model misspecification.

What. ``decompose`` turns an ESTIMATED score (and its ``ScoreTrace`` values) into an ``UncertaintyReport`` with one
component per question, each with an explicit status, so that nothing unknown is shown as a number:

1. ``conditional_mean``: SE of the conditional mean, with the declared n and, when an ``EffectiveSample`` is given
   (from ``effective_n_bartlett`` / ``effective_n_clusters`` on the training labels), inflated by sqrt(n / n_eff);
   never deflated (n_eff is capped at n);
2. ``single_trade``: predictive spread of one trade, sqrt(residual_sd^2 + se^2), a homoscedastic, symmetric summary;
   no interval or probability is derived from it;
3. ``domain``: marginal |z| support, joint Mahalanobis distance (only with ``unc.cinv`` and a declared bound) and the
   training label range (only with a declared ``TrainingDomain``); IN_DOMAIN only when all three checks ran and
   passed, otherwise OUT_OF_DOMAIN or UNKNOWN;
4. ``stop_probability`` and ``tail_loss``: NOT_MODELLED in V1 (point values only);
5. ``costs``: a deterministic SCENARIO (base and pessimistic multiplier), not a distribution;
6. ``regime`` and ``misspecification``: non-numeric statuses with reason codes (NOT_ASSESSED / FLAGGED).

The report never contains a calibrated probability (``is_calibrated_probability`` is always False) and never changes
the V1 score, so the V1 golden vectors are untouched. A future engine version may use ``abstention_reasons``.

Research prototype: synthetic data only.
"""
from __future__ import annotations

import enum
import math
from collections.abc import Mapping, Sequence
from dataclasses import dataclass, field
from decimal import Decimal
from typing import Any

from ..contracts import ModelKind, OpportunityScore, ScoreStatus, wire_decimal
from .model import LinearModel

UNCERTAINTY_SCHEMA = 'h3.uncertainty_report/1'
V1_HEURISTIC_MEANING = ('1 / (1 + |expected_net_r| / se): a monotone transform of a t-ratio computed with the '
                        'declared n_train; a ranking heuristic, not a probability and not calibrated')


class ComponentStatus(str, enum.Enum):
    ESTIMATED = 'ESTIMATED'            # a number, with its stated assumptions verified as far as the inputs allow
    FLAGGED = 'FLAGGED'                # a number, with an unverified or violated assumption named in the reasons
    SCENARIO = 'SCENARIO'              # deterministic what-if values, not a distribution
    NOT_MODELLED = 'NOT_MODELLED'      # V1 has no model for it; no number
    NOT_ASSESSED = 'NOT_ASSESSED'      # a qualitative risk nobody assessed; no number
    UNESTIMATED = 'UNESTIMATED'        # no estimate (no labels, no model, no calibration); no number


class DomainStatus(str, enum.Enum):
    IN_DOMAIN = 'IN_DOMAIN'
    OUT_OF_DOMAIN = 'OUT_OF_DOMAIN'
    UNKNOWN = 'UNKNOWN'


# ------------------------------------------------------------------------------------------- effective sample

@dataclass(frozen=True)
class EffectiveSample:
    """Effective number of independent outcomes behind a training set. ``n_effective`` <= ``n_nominal`` always."""

    n_nominal: int
    n_effective: int | None
    method: str
    design_effect: float | None
    status: ComponentStatus
    reasons: tuple[str, ...] = ()


def newey_west_lag(n: int) -> int:
    """Pre-declared bandwidth rule floor(4 (n / 100)^(2/9)) (Newey-West 1994), fixed before any data."""
    return int(math.floor(4 * (n / 100) ** (2 / 9))) if n > 0 else 0


def effective_n_bartlett(values: Sequence[float], lag: int | None = None) -> EffectiveSample:
    """n / (1 + 2 sum_k (1 - k / (L + 1)) rho_k) on a time-ordered series (Bartlett weights), with the factor floored
    at 1: negative autocorrelation is never credited as extra information. A short bandwidth under-corrects strong
    persistence (AR(1) phi = 0.8 has a true factor of 9; L = 5 gives about 4), so this is a lower bound on the
    correction, combined with the cluster estimate by ``conservative``."""
    n = len(values)
    if n < 3:
        return EffectiveSample(n, None, 'bartlett', None, ComponentStatus.UNESTIMATED, ('too_few_labels',))
    lag = newey_west_lag(n) if lag is None else lag
    if lag < 0 or lag >= n:
        raise ValueError('h3_uncertainty_lag_invalid')
    mean = math.fsum(values) / n
    centred = [v - mean for v in values]
    gamma0 = math.fsum(x * x for x in centred) / n
    if gamma0 == 0:
        return EffectiveSample(n, None, 'bartlett', None, ComponentStatus.UNESTIMATED, ('labels_constant',))
    factor = 1.0 + 2.0 * math.fsum((1 - k / (lag + 1)) * math.fsum(centred[i] * centred[i + k]
                                                                     for i in range(n - k)) / n / gamma0
                                   for k in range(1, lag + 1))
    factor = max(1.0, factor)
    return EffectiveSample(n, max(1, int(math.floor(n / factor))), f'bartlett_lag_{lag}', factor,
                           ComponentStatus.ESTIMATED)


def effective_n_clusters(values: Sequence[float], clusters: Sequence[str]) -> EffectiveSample:
    """Kish/Moulton design effect 1 + (m_w - 1) x max(0, ICC) for outcomes grouped in clusters (decision instant,
    instrument, overlapping-holding group); m_w = sum m_g^2 / n; ICC from the one-way ANOVA estimator. Floored at
    1 (a negative ICC is not credited)."""
    n = len(values)
    if n != len(clusters):
        raise ValueError('h3_uncertainty_cluster_length_mismatch')
    groups: dict[str, list[float]] = {}
    for value, cluster in zip(values, clusters):
        groups.setdefault(cluster, []).append(value)
    g = len(groups)
    if n < 3 or g < 2:
        return EffectiveSample(n, None, 'clusters', None, ComponentStatus.UNESTIMATED, ('too_few_labels_or_clusters',))
    mean = math.fsum(values) / n
    ssb = math.fsum(len(v) * (math.fsum(v) / len(v) - mean) ** 2 for v in groups.values())
    ssw = math.fsum((x - math.fsum(v) / len(v)) ** 2 for v in groups.values() for x in v)
    if n == g:   # singletons: no within-cluster information, no correction possible beyond 1
        return EffectiveSample(n, n, 'clusters', 1.0, ComponentStatus.ESTIMATED, ('singleton_clusters',))
    msb, msw = ssb / (g - 1), ssw / (n - g)
    m0 = (n - math.fsum(len(v) ** 2 for v in groups.values()) / n) / (g - 1)
    icc = 0.0 if msb + (m0 - 1) * msw <= 0 else (msb - msw) / (msb + (m0 - 1) * msw)
    m_w = math.fsum(len(v) ** 2 for v in groups.values()) / n
    deff = max(1.0, 1 + (m_w - 1) * max(0.0, icc))
    return EffectiveSample(n, max(1, int(math.floor(n / deff))), 'clusters', deff, ComponentStatus.ESTIMATED)


def conservative(*samples: EffectiveSample) -> EffectiveSample:
    """The smallest estimated n_effective among the given estimates; UNESTIMATED when none is estimated."""
    estimated = [s for s in samples if s.status is ComponentStatus.ESTIMATED and s.n_effective is not None]
    if not estimated:
        n = max((s.n_nominal for s in samples), default=0)
        return EffectiveSample(n, None, 'conservative', None, ComponentStatus.UNESTIMATED, ('no_estimate',))
    best = min(estimated, key=lambda s: s.n_effective)  # type: ignore[arg-type, return-value]
    return EffectiveSample(best.n_nominal, best.n_effective, 'conservative:' + best.method, best.design_effect,
                           ComponentStatus.ESTIMATED, best.reasons)


# ------------------------------------------------------------------------------------------------- domain

@dataclass(frozen=True)
class TrainingDomain:
    """Declared at training time with the manifest (never derived from evaluated outcomes). ``None`` fields leave
    that check UNKNOWN."""

    domain_id: str
    mahalanobis_q_max: Decimal | None = None
    label_range_r: tuple[Decimal, Decimal] | None = None
    z_support_max: Decimal | None = None   # None: the manifest's policy.z_support_max


# ------------------------------------------------------------------------------------------------- report

@dataclass(frozen=True)
class Component:
    status: ComponentStatus
    values: dict[str, str] = field(default_factory=dict)
    reasons: tuple[str, ...] = ()

    def to_wire(self) -> dict[str, Any]:
        return {'status': self.status.value, 'values': dict(sorted(self.values.items())),
                'reasons': list(self.reasons)}


@dataclass(frozen=True)
class UncertaintyReport:
    candidate_id: str
    score_status: str
    overall: str                       # 'UNESTIMATED' or 'DESCRIPTIVE_NOT_CALIBRATED'
    v1_heuristic: str | None
    conditional_mean: Component
    single_trade: Component
    domain: Component
    domain_status: DomainStatus
    stop_probability: Component
    tail_loss: Component
    costs: Component
    regime: Component
    misspecification: Component
    is_calibrated_probability: bool = False
    schema: str = UNCERTAINTY_SCHEMA

    def to_wire(self) -> dict[str, Any]:
        return {'schema': self.schema, 'candidate_id': self.candidate_id, 'score_status': self.score_status,
                'overall': self.overall, 'is_calibrated_probability': self.is_calibrated_probability,
                'v1_heuristic': self.v1_heuristic, 'v1_heuristic_meaning': V1_HEURISTIC_MEANING,
                'domain_status': self.domain_status.value,
                'components': {name: getattr(self, name).to_wire() for name in (
                    'conditional_mean', 'single_trade', 'domain', 'stop_probability', 'tail_loss', 'costs',
                    'regime', 'misspecification')}}


def _w(value: float | Decimal) -> str:
    return wire_decimal(value) if isinstance(value, Decimal) else repr(float(value))


def _unestimated(score: OpportunityScore, reasons: Sequence[str]) -> UncertaintyReport:
    none = Component(ComponentStatus.UNESTIMATED, {}, tuple(reasons))
    return UncertaintyReport(candidate_id=score.candidate_id, score_status=score.status.value, overall='UNESTIMATED',
                             v1_heuristic=None, conditional_mean=none, single_trade=none, domain=none,
                             domain_status=DomainStatus.UNKNOWN, stop_probability=none, tail_loss=none, costs=none,
                             regime=Component(ComponentStatus.NOT_ASSESSED), misspecification=none)


def decompose(score: OpportunityScore, trace_values: Mapping[str, str], model: LinearModel | None, *,
              dependence: EffectiveSample | None = None, domain: TrainingDomain | None = None,
              cost_multiplier: Decimal | None = None, regime_flags: Sequence[str] = ()) -> UncertaintyReport:
    """The multi-component report of one score (module docstring). ``trace_values``: ``ScoreTrace.values`` of the
    same evaluation; ``model``: the decoded manifest (``model.decode_manifest``). Pure; does not modify the score."""
    if score.status is not ScoreStatus.ESTIMATED or model is None or not trace_values:
        reasons = ('score_not_estimated',) + tuple(score.reasons) if score.status is not ScoreStatus.ESTIMATED \
            else ('model_or_trace_missing',)
        return _unestimated(score, reasons)
    t = {k: Decimal(v) for k, v in trace_values.items()}
    sd, n = float(model.residual_sd_r), model.n_train
    q, variance = float(t['quadratic_form']), float(t['variance'])
    expected = float(t['expected_net_r'])

    # 1. conditional mean
    var_mean = sd * sd * (1 + q) / n
    var_imputed = max(0.0, variance - var_mean)
    mean_reasons: list[str] = []
    inflation = 1.0
    n_eff: int | None = None
    if dependence is None or dependence.status is not ComponentStatus.ESTIMATED or dependence.n_effective is None:
        mean_reasons.append('effective_n_not_verified')
    else:
        n_eff = min(dependence.n_effective, n)
        if dependence.n_effective < n:
            inflation = math.sqrt(n / dependence.n_effective)
            mean_reasons.append('n_train_exceeds_effective_n')
    se_mean = math.sqrt(var_mean) * inflation
    se_total = math.sqrt(se_mean * se_mean + var_imputed)
    if var_imputed > 0:
        mean_reasons.append('imputation_variance_added')
    mean_values = {'se_mean_declared_n': _w(math.sqrt(var_mean)), 'se_mean_dependence_adjusted': _w(se_mean),
                   'se_total': _w(se_total), 'n_train_declared': str(n), 'inflation': _w(inflation),
                   'imputation_variance': _w(var_imputed),
                   't_ratio_adjusted': _w(abs(expected) / se_total) if se_total > 0 else 'inf'}
    if n_eff is not None:
        mean_values['n_effective'] = str(n_eff)
    mean = Component(ComponentStatus.FLAGGED if mean_reasons else ComponentStatus.ESTIMATED, mean_values,
                     tuple(mean_reasons))

    # 2. one trade
    predictive_sd = math.sqrt(sd * sd + se_total * se_total)
    single = Component(ComponentStatus.FLAGGED, {
        'predictive_sd_r': _w(predictive_sd), 'expected_over_predictive_sd': _w(expected / predictive_sd)},
        ('residual_sd_constant_assumed', 'outcome_distribution_bounded_and_skewed_no_interval'))

    # 3. domain
    z_max = domain.z_support_max if domain is not None and domain.z_support_max is not None \
        else model.policy.z_support_max
    zs = [abs(v) for k, v in t.items() if k.startswith('z.')]
    max_z = max(zs) if zs else Decimal(0)
    domain_reasons: list[str] = []
    checks = {'marginal': max_z <= z_max, 'joint': None, 'label_range': None}
    if max_z > z_max:
        domain_reasons.append('feature_outside_training_support')
    if model.cinv is None:
        domain_reasons.append('joint_domain_unknown_no_cinv')
    elif domain is None or domain.mahalanobis_q_max is None:
        domain_reasons.append('joint_domain_unknown_no_bound')
    else:
        checks['joint'] = t['quadratic_form'] <= domain.mahalanobis_q_max
        if not checks['joint']:
            domain_reasons.append('joint_mahalanobis_above_training_bound')
    if domain is None or domain.label_range_r is None:
        domain_reasons.append('label_range_unknown')
    else:
        low, high = domain.label_range_r
        checks['label_range'] = low <= t['gross_r'] <= high
        if not checks['label_range']:
            domain_reasons.append('prediction_outside_training_label_range')
    if any(v is False for v in checks.values()):
        domain_status = DomainStatus.OUT_OF_DOMAIN
        domain_reasons.append('v1_heuristic_not_meaningful_out_of_domain')
    elif all(v is True for v in checks.values()):
        domain_status = DomainStatus.IN_DOMAIN
    else:
        domain_status = DomainStatus.UNKNOWN
    domain_values = {'max_abs_z': _w(max_z), 'z_support_max': _w(z_max), 'quadratic_form': _w(t['quadratic_form'])}
    domain_component = Component(ComponentStatus.ESTIMATED if domain_status is DomainStatus.IN_DOMAIN
                                 else ComponentStatus.FLAGGED, domain_values, tuple(domain_reasons))

    # 4. heads without an uncertainty model
    stop = Component(ComponentStatus.NOT_MODELLED, {'p_stop_point': _w(t['p_stop'])},
                     ('p_stop_calibration_uncertainty_not_modelled',))
    tail = Component(ComponentStatus.NOT_MODELLED, {'tail_loss_r_point': _w(t['tail_loss_r'])},
                     ('tail_head_uncertainty_not_modelled', 'gap_and_squeeze_losses_not_modelled'))

    # 5. costs: deterministic scenarios, subtracted once from the gross head
    gross, cost, funding = t['gross_r'], t['cost_r'], t['funding_cost_r']
    cost_values = {'gross_r': _w(gross), 'cost_r': _w(cost), 'funding_cost_r': _w(funding),
                   'expected_net_r_base': _w(gross - cost - funding)}
    if cost_multiplier is not None:
        cost_values['expected_net_r_pessimistic'] = _w(gross - cost_multiplier * cost - cost_multiplier * funding)
        cost_values['cost_multiplier'] = _w(cost_multiplier)
    costs = Component(ComponentStatus.SCENARIO, cost_values, ('future_costs_scenario_not_distribution',))

    # 6. qualitative risks
    regime = Component(ComponentStatus.FLAGGED, {}, tuple(regime_flags)) if regime_flags \
        else Component(ComponentStatus.NOT_ASSESSED, {}, ('regime_change_not_assessed',))
    mis = ['linear_heads_not_validated_out_of_sample', 'homoscedastic_residual_assumed']
    if model.kind is ModelKind.RIDGE:
        mis.append('ridge_se_ols_approximation_shrinkage_bias_ignored')
    if model.cinv is None:
        mis.append('cinv_absent_identity_assumed_collinearity_ignored')
    if var_imputed > 0:
        mis.append('imputed_features_mean_substitution')
    misspecification = Component(ComponentStatus.FLAGGED, {}, tuple(mis))
    return UncertaintyReport(
        candidate_id=score.candidate_id, score_status=score.status.value, overall='DESCRIPTIVE_NOT_CALIBRATED',
        v1_heuristic=_w(t['uncertainty']), conditional_mean=mean, single_trade=single, domain=domain_component,
        domain_status=domain_status, stop_probability=stop, tail_loss=tail, costs=costs, regime=regime,
        misspecification=misspecification)


def abstention_reasons(report: UncertaintyReport) -> tuple[str, ...]:
    """Conservative reasons for a future engine version to abstain (empty: no reason found by this report). Unknown
    domain and unverified effective n count as reasons: absence of a check is not a pass."""
    out: list[str] = []
    if report.overall == 'UNESTIMATED':
        return ('uncertainty_unestimated',)
    if report.domain_status is DomainStatus.OUT_OF_DOMAIN:
        out.append('out_of_domain')
    elif report.domain_status is DomainStatus.UNKNOWN:
        out.append('domain_unknown')
    if 'effective_n_not_verified' in report.conditional_mean.reasons:
        out.append('effective_n_not_verified')
    return tuple(out)
