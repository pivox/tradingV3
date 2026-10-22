"""Opportunity scoring engine: implements ``contracts.OpportunityScorer`` (score, rank).

The engine scores candidates it is given; it never generates a candidate, never sizes a position and never turns a
score into an order. A TRADE_CANDIDATE verdict only means "eligible for the risk gate"; a high score is not a buy
signal.

Decision order of ``score(candidate, snapshot, model)`` (first matching rule wins; every non-estimated score carries
no number and the verdict NO_TRADE):

1. structural input errors -> INVALID_INPUT, data_quality 0: snapshot reference, instrument, side, decision time,
   provenance or protocol differing from the candidate's; another feature catalogue; unknown feature; wrong unit;
   a feature observed or available after the decision;
2. no model -> UNESTIMATED (``model_absent``);
3. invalid model (feature-spec mismatch, missing/unexpected/invalid parameter, unsupported kind) -> MODEL_INVALID;
4. reference-null or UNCALIBRATED model -> UNESTIMATED (``model_uncalibrated``): no probability or expectancy is
   ever fabricated without a calibrated model;
5. missing, stale or non-finite required data (unless the manifest allows imputing that predictive feature) ->
   INVALID_INPUT;
6. otherwise ESTIMATED. The verdict is TRADE_CANDIDATE only when no feature is outside the training support,
   utility > policy.utility_min and uncertainty <= policy.uncertainty_max; NO_TRADE otherwise (the numbers are then
   kept for audit only and must never be used for selection).

Estimation (all arithmetic in ``scoring.arith``, in this exact order):
- x_i = feature value, multiplied by +1 (long) / -1 (short) when the feature is directional; z_i = (x_i - mean_i) /
  scale_i; an imputed feature has z_i = 0;
- head(h) = intercept_h + sum_i w_h,i x z_i  (each product truncated, then summed left to right);
- gross_r = head(gross_r); p_stop = piecewise-linear calibration of head(stop); tail_gross = head(tail_r), which
  must be >= 0 (else MODEL_INVALID ``model_tail_prediction_negative``);
- expected_net_r = gross_r - cost_r - funding_cost_r; tail_loss_r = tail_gross + cost_r + funding_cost_r;
- q = sum_i sum_j (z_i x A_ij) x z_j with A = unc.cinv (identity when absent); se^2 = sd^2 x (1 + q) / n_train +
  sum over imputed i of w_gross_r,i^2; se = sqrt(se^2) (standard error of the conditional mean; OLS formula, an
  approximation for ridge);
- uncertainty = se / (se + |expected_net_r|) = 1 / (1 + |t|), in (0, 1]; 1 when both are 0;
- utility = expected_net_r - lambda_tail x tail_loss_r - gamma_uncertainty x uncertainty.

data_quality = (# required snapshot inputs with status OK and fresh) / (# required), the required inputs being the
model features' snapshot inputs plus the cost inputs (spread_bp, and funding_rate_hourly unless spot); without a
usable model, every predictive and cost input of the catalogue.

``rank(scores)``: one decision batch only (same decision_ms, same model, distinct candidates). Only ESTIMATED
TRADE_CANDIDATE scores get a rank: utility descending, then uncertainty ascending, then data_quality descending, then
candidate_id ascending (a deterministic, non-economic last resort). Unranked scores follow, by candidate_id, with
rank None. The result does not depend on the input order, and ranking twice changes nothing.
"""
from __future__ import annotations

import dataclasses
from collections.abc import Sequence
from dataclasses import dataclass, field
from decimal import Decimal

from ..contracts import (
    CalibrationStatus, CostModel, FeatureSnapshot, FeatureStatus, FeatureValue, ModelKind, ModelManifest, ModelRef,
    OpportunityCandidate, OpportunityScore, Product, ScoreStatus, ScoreVerdict, wire_decimal,
)
from . import arith as ar
from .features import (
    CATALOGUE, SNAPSHOT_SOURCES, FeatureSpecSet, Role, derived_features, orient, reason,
)
from .model import LinearHead, LinearModel, decode_manifest

NO_MODEL = ModelRef(model_id='none', model_version='0', model_fingerprint=None)
_STATUS_REASON = {FeatureStatus.MISSING: 'feature_missing', FeatureStatus.STALE: 'feature_stale',
                  FeatureStatus.NON_FINITE: 'feature_non_finite'}
_IMPUTABLE = (FeatureStatus.MISSING, FeatureStatus.NON_FINITE)


class ScoringError(ValueError):
    """A batch cannot be ranked. The message is a stable reason code."""


@dataclass(frozen=True)
class ScoreTrace:
    """A score with every intermediate number of its estimation (empty when not estimated), for audit and parity."""

    score: OpportunityScore
    values: dict[str, str] = field(default_factory=dict)


class _Abstain(Exception):
    def __init__(self, status: ScoreStatus, reasons: Sequence[str]):
        super().__init__(status.value)
        self.status = status
        self.reasons = tuple(reasons)


class ScoringEngine:
    """Deterministic, exchange- and runtime-independent scorer. ``cost_model`` is the experiment's (B0-B3 share it)."""

    def __init__(self, cost_model: CostModel, spec: FeatureSpecSet = CATALOGUE):
        self.cost_model = cost_model
        self.spec = spec

    # ----------------------------------------------------------------------------------------------- public API

    def score(self, candidate: OpportunityCandidate, snapshot: FeatureSnapshot,
              model: ModelManifest | None) -> OpportunityScore:
        return self.evaluate(candidate, snapshot, model).score

    def evaluate(self, candidate: OpportunityCandidate, snapshot: FeatureSnapshot,
                 model: ModelManifest | None) -> ScoreTrace:
        model_ref = NO_MODEL if model is None else model.ref()
        structural = self._structural_reasons(candidate, snapshot)
        if structural:
            return self._abstain(candidate, snapshot, model_ref, ScoreStatus.INVALID_INPUT, structural, ar.ZERO)
        try:
            return self._evaluate(candidate, snapshot, model, model_ref)
        except ar.ArithmeticDomainError as error:   # a magnitude beyond the arithmetic domain (|x| >= 10**30)
            return self._abstain(candidate, snapshot, model_ref, ScoreStatus.INVALID_INPUT,
                                 (reason('arithmetic_out_of_domain', str(error)),), ar.ZERO)

    def _evaluate(self, candidate: OpportunityCandidate, snapshot: FeatureSnapshot, model: ModelManifest | None,
                  model_ref: ModelRef) -> ScoreTrace:
        decoded, model_reasons = decode_manifest(model, self.spec)
        usable = decoded is not None and model is not None and model.calibration is CalibrationStatus.CALIBRATED
        values = self._values(candidate, snapshot)
        required = self._required_inputs(candidate, decoded if usable else None)
        data_reasons, fresh = self._data_reasons(candidate, values, required)
        quality = ar.ONE if not required else ar.div(len(fresh), len(required))
        if model is None:
            return self._abstain(candidate, snapshot, model_ref, ScoreStatus.UNESTIMATED,
                                 ('model_absent', *data_reasons), quality)
        if model.kind is ModelKind.REFERENCE_NULL and model_reasons == ('model_reference_null',):
            return self._abstain(candidate, snapshot, model_ref, ScoreStatus.UNESTIMATED,
                                 ('model_reference_null', 'model_uncalibrated', *data_reasons), quality)
        if decoded is None:
            return self._abstain(candidate, snapshot, model_ref, ScoreStatus.MODEL_INVALID, model_reasons, quality)
        if not usable:
            return self._abstain(candidate, snapshot, model_ref, ScoreStatus.UNESTIMATED,
                                 ('model_uncalibrated', *data_reasons), quality)
        try:
            return self._estimate(candidate, snapshot, model_ref, decoded, values, quality)
        except _Abstain as abstain:
            return self._abstain(candidate, snapshot, model_ref, abstain.status, abstain.reasons, quality)

    def rank(self, scores: Sequence[OpportunityScore]) -> tuple[OpportunityScore, ...]:
        batch = tuple(scores)
        if not batch:
            return ()
        if len({s.candidate_id for s in batch}) != len(batch):
            raise ScoringError('scoring_rank_duplicate_candidate')
        if len({s.header.clock.decision_ms for s in batch}) != 1:
            raise ScoringError('scoring_rank_mixed_decision_times')
        if len({s.model for s in batch}) != 1:
            raise ScoringError('scoring_rank_mixed_models')
        eligible = [s for s in batch if _rankable(s)]
        rest = [s for s in batch if not _rankable(s)]
        eligible.sort(key=lambda s: (-s.utility, s.uncertainty, -s.data_quality, s.candidate_id))  # type: ignore
        ranked = [dataclasses.replace(s, rank=k + 1) for k, s in enumerate(eligible)]
        return tuple(ranked + [dataclasses.replace(s, rank=None) for s in sorted(rest, key=lambda s: s.candidate_id)])

    # -------------------------------------------------------------------------------------------------- inputs

    def _structural_reasons(self, candidate: OpportunityCandidate, snapshot: FeatureSnapshot) -> list[str]:
        out = []
        ch, sh = candidate.header, snapshot.header
        if candidate.snapshot != snapshot.ref():
            out.append('snapshot_ref_mismatch')
        if sh.instrument != ch.instrument:
            out.append('snapshot_instrument_mismatch')
        if sh.side is not None and sh.side is not ch.side:
            out.append('snapshot_side_mismatch')
        if sh.clock.decision_ms != ch.clock.decision_ms:
            out.append('snapshot_decision_time_mismatch')
        if sh.provenance != ch.provenance:
            out.append('snapshot_provenance_mismatch')
        if sh.protocol != ch.protocol:
            out.append('snapshot_protocol_mismatch')
        if (snapshot.feature_spec_id, snapshot.feature_spec_version) != (self.spec.spec_id, self.spec.spec_version):
            out.append('feature_spec_mismatch')
        decision = ch.clock.decision_ms
        for feature in snapshot.features:
            spec = self.spec.get(feature.name)
            if spec is None or spec.source not in SNAPSHOT_SOURCES:
                out.append(reason('feature_unknown', feature.name))
            elif feature.unit != spec.unit:
                out.append(reason('feature_unit_mismatch', feature.name))
            if feature.available_ms > decision or feature.observed_ms > decision:
                out.append(reason('feature_after_decision', feature.name))
        return out

    def _values(self, candidate: OpportunityCandidate, snapshot: FeatureSnapshot) -> dict[str, FeatureValue]:
        values = {f.name: f for f in snapshot.features}
        values.update(derived_features(candidate, snapshot, self.cost_model, self.spec))
        return values

    def _snapshot_inputs(self, name: str) -> tuple[str, ...]:
        spec = self.spec.get(name)
        assert spec is not None
        if spec.source in SNAPSHOT_SOURCES:
            return (name,)
        return tuple(i for i in spec.inputs if self.spec.get(i) is not None)

    def _cost_names(self, candidate: OpportunityCandidate) -> tuple[str, ...]:
        if candidate.header.instrument.product is Product.SPOT:
            return ('cost_r',)
        return ('cost_r', 'funding_cost_r')

    def _required_inputs(self, candidate: OpportunityCandidate, model: LinearModel | None) -> tuple[str, ...]:
        """Snapshot features the decision depends on, in catalogue order."""
        if model is None:
            names = self.spec.names_with(roles=(Role.PREDICTIVE, Role.COST))
        else:
            names = model.feature_names + self._cost_names(candidate)
        needed = {i for n in names for i in self._snapshot_inputs(n)}
        if candidate.header.instrument.product is Product.SPOT:
            needed.discard('funding_rate_hourly')
        return tuple(n for n in self.spec.names if n in needed)

    def _fresh(self, feature: FeatureValue | None, decision_ms: int) -> FeatureStatus:
        """Status of a snapshot feature with the max-age rule re-applied at the candidate's decision."""
        if feature is None:
            return FeatureStatus.MISSING
        spec = self.spec.get(feature.name)
        if feature.status is FeatureStatus.OK and spec is not None and spec.max_age_ms is not None \
                and decision_ms - feature.observed_ms > spec.max_age_ms:
            return FeatureStatus.STALE
        return feature.status

    def _data_reasons(self, candidate: OpportunityCandidate, values: dict[str, FeatureValue],
                      required: tuple[str, ...]) -> tuple[list[str], list[str]]:
        decision = candidate.header.clock.decision_ms
        reasons, fresh = [], []
        for name in required:
            status = self._fresh(values.get(name), decision)
            if status is FeatureStatus.OK:
                fresh.append(name)
            else:
                reasons.append(reason(_STATUS_REASON[status], name))
        return reasons, fresh

    # ---------------------------------------------------------------------------------------------- estimation

    def _feature_input(self, name: str, values: dict[str, FeatureValue], decision_ms: int) -> tuple[
            FeatureStatus, str]:
        """Status of a model or cost feature and the name its reason should carry (the failing snapshot input)."""
        for source in self._snapshot_inputs(name):
            status = self._fresh(values.get(source), decision_ms)
            if status is not FeatureStatus.OK:
                return status, source
        feature = values.get(name)
        return (FeatureStatus.MISSING if feature is None else feature.status), name

    def _estimate(self, candidate: OpportunityCandidate, snapshot: FeatureSnapshot, model_ref: ModelRef,
                  model: LinearModel, values: dict[str, FeatureValue], quality: Decimal) -> ScoreTrace:
        side = candidate.header.side
        assert side is not None
        decision = candidate.header.clock.decision_ms
        policy = model.policy
        abstain, imputed, imputable_reasons = [], [], []
        for name in model.feature_names:
            status, culprit = self._feature_input(name, values, decision)
            if status is FeatureStatus.OK:
                continue
            if policy.impute_missing and status in _IMPUTABLE:
                imputed.append(name)
                imputable_reasons.append(reason(_STATUS_REASON[status], culprit))
            else:
                abstain.append(reason(_STATUS_REASON[status], culprit))
        if len(imputed) > policy.max_imputed:
            abstain += ['too_many_imputed'] + imputable_reasons
        for name in self._cost_names(candidate):
            status, culprit = self._feature_input(name, values, decision)
            if status is not FeatureStatus.OK:
                abstain.append(reason(_STATUS_REASON[status], culprit))
        if abstain:
            raise _Abstain(ScoreStatus.INVALID_INPUT, abstain)

        verdict_reasons, z = [], []
        for i, name in enumerate(model.feature_names):
            if name in imputed:
                z.append(ar.ZERO)
                continue
            x = values[name].value
            assert x is not None
            if self.spec.get(name).directional:  # type: ignore[union-attr]
                x = orient(x, side)
            z_i = ar.div(ar.sub(x, model.means[i]), model.scales[i])
            if ar.absolute(z_i) > policy.z_support_max:
                verdict_reasons.append(reason('feature_outside_training_support', name))
            z.append(z_i)

        gross = _head(model.gross_r, z)
        stop_index = _head(model.stop, z)
        p_stop, clamped = interpolate(model.calibration, stop_index)
        tail_gross = _head(model.tail_r, z)
        if tail_gross < 0:
            raise _Abstain(ScoreStatus.MODEL_INVALID, ('model_tail_prediction_negative',))
        cost = values['cost_r'].value
        funding = values['funding_cost_r'].value if 'funding_cost_r' in self._cost_names(candidate) else ar.ZERO
        assert cost is not None and funding is not None
        expected_net = ar.sub(ar.sub(gross, cost), funding)
        tail_loss = ar.add(ar.add(tail_gross, cost), funding)
        quad = quadratic_form(z, model.cinv)
        if quad < 0:
            raise _Abstain(ScoreStatus.MODEL_INVALID, ('model_uncertainty_matrix_not_positive',))
        variance = ar.div(ar.mul(ar.mul(model.residual_sd_r, model.residual_sd_r), ar.add(ar.ONE, quad)),
                          model.n_train)
        for i, name in enumerate(model.feature_names):
            if name in imputed:
                weight = model.gross_r.weights[i]
                variance = ar.add(variance, ar.mul(weight, weight))
        se = ar.sqrt(variance)
        denominator = ar.add(se, ar.absolute(expected_net))
        uncertainty = ar.ONE if denominator == 0 else ar.div(se, denominator)
        utility = ar.sub(ar.sub(expected_net, ar.mul(policy.lambda_tail, tail_loss)),
                         ar.mul(policy.gamma_uncertainty, uncertainty))
        if utility <= policy.utility_min:
            verdict_reasons.append('utility_not_above_min')
        if uncertainty > policy.uncertainty_max:
            verdict_reasons.append('uncertainty_above_max')
        verdict = ScoreVerdict.NO_TRADE if verdict_reasons else ScoreVerdict.TRADE_CANDIDATE
        notes = [reason('feature_imputed', n) for n in imputed] + (['p_stop_index_clamped'] if clamped else [])
        score = OpportunityScore(
            header=candidate.header, candidate_id=candidate.candidate_id, snapshot=snapshot.ref(), model=model_ref,
            status=ScoreStatus.ESTIMATED, verdict=verdict, expected_net_r=expected_net, p_stop=p_stop,
            tail_loss_r=tail_loss, uncertainty=uncertainty, data_quality=quality, utility=utility, rank=None,
            reasons=tuple(sorted(set(verdict_reasons + notes))))
        trace = {'z.' + n: ar.q(v) for n, v in zip(model.feature_names, z)}
        trace.update({'gross_r': gross, 'stop_index': stop_index, 'p_stop': p_stop, 'tail_gross_r': tail_gross,
                      'cost_r': cost, 'funding_cost_r': funding, 'expected_net_r': expected_net,
                      'tail_loss_r': tail_loss, 'quadratic_form': quad, 'variance': variance, 'se': se,
                      'uncertainty': uncertainty, 'utility': utility})
        return ScoreTrace(score, {k: wire_decimal(v) for k, v in sorted(trace.items())})

    def _abstain(self, candidate: OpportunityCandidate, snapshot: FeatureSnapshot, model_ref: ModelRef,
                 status: ScoreStatus, reasons: Sequence[str], quality: Decimal) -> ScoreTrace:
        score = OpportunityScore(
            header=candidate.header, candidate_id=candidate.candidate_id, snapshot=snapshot.ref(), model=model_ref,
            status=status, verdict=ScoreVerdict.NO_TRADE, expected_net_r=None, p_stop=None, tail_loss_r=None,
            uncertainty=ar.ONE, data_quality=quality, utility=None, rank=None,
            reasons=tuple(sorted(set(reasons))))
        return ScoreTrace(score)


# ----------------------------------------------------------------------------------------------------- helpers

def _rankable(score: OpportunityScore) -> bool:
    return score.status is ScoreStatus.ESTIMATED and score.verdict is ScoreVerdict.TRADE_CANDIDATE


def _head(head: LinearHead, z: Sequence[Decimal]) -> Decimal:
    return ar.total([head.intercept] + [ar.mul(w, z_i) for w, z_i in zip(head.weights, z)])


def quadratic_form(z: Sequence[Decimal], matrix: tuple[tuple[Decimal, ...], ...] | None) -> Decimal:
    """sum_i sum_j (z_i x A_ij) x z_j, row-major; A = identity when ``matrix`` is None (then sum of z_i^2)."""
    terms = []
    for i, z_i in enumerate(z):
        for j, z_j in enumerate(z):
            entry = (ar.ONE if i == j else ar.ZERO) if matrix is None else matrix[i][j]
            if entry != 0:
                terms.append(ar.mul(ar.mul(z_i, entry), z_j))
    return ar.total(terms)


def interpolate(table: Sequence[tuple[Decimal, Decimal]], index: Decimal) -> tuple[Decimal, bool]:
    """Piecewise-linear calibration, clamped at the end points. Returns (p, clamped).

    Segment k is the last one whose left point x_k <= index; p = p_k + (p_k+1 - p_k) x ((index - x_k) /
    (x_k+1 - x_k)).
    """
    first, last = table[0], table[-1]
    if index <= first[0]:
        return first[1], index < first[0]
    if index >= last[0]:
        return last[1], index > last[0]
    k = max(i for i in range(len(table) - 1) if table[i][0] <= index)
    (x0, p0), (x1, p1) = table[k], table[k + 1]
    return ar.add(p0, ar.mul(ar.sub(p1, p0), ar.div(ar.sub(index, x0), ar.sub(x1, x0)))), False

