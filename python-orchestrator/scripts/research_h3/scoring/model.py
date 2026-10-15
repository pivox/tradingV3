"""Scoring model manifests (contracts.ModelManifest): the reference-null model, the linear/ridge parameter layout,
validation against the feature catalogue, decoding, and the readiness rule of the scored variants B1/B3.

Allowed model kinds in v1:
- ``REFERENCE_NULL``: no parameters, always UNCALIBRATED; every score is UNESTIMATED (no number is produced).
- ``LINEAR`` (transparent linear score) and ``RIDGE`` (L2-regularised regression): identical at inference, three
  linear heads on standardised, side-oriented features; RIDGE also records its penalty ``train.ridge_alpha`` > 0.
- ``SIMPLE_NONLINEAR`` is reserved: its extra complexity is not justified yet, so the engine refuses it
  (``model_kind_not_supported``). No neural network, no reinforcement learning, no training or optimisation here.

Parameter layout (``ModelManifest.parameters``, NamedValue sorted by name; ``<f>`` runs over ``feature_names``, which
must be predictive features of the catalogue, in catalogue order):

- ``std.<f>.mean``, ``std.<f>.scale`` (> 0): z = (oriented value - mean) / scale;
- ``head.gross_r.intercept``, ``head.gross_r.w.<f>``: expected GROSS R under the protocol's static exit (before
  predictable costs, which the engine subtracts);
- ``head.stop.intercept``, ``head.stop.w.<f>``: stop-out index; ``head.stop.calib.<kk>.x`` / ``.p`` (kk = 00, 01, ...;
  2 to 32 points, x strictly increasing, p non-decreasing in [0, 1]): piecewise-linear calibration index -> p_stop,
  clamped at the end points;
- ``head.tail_r.intercept``, ``head.tail_r.w.<f>``: expected gross tail loss in R (positive), definition of the tail
  (e.g. CVaR level) fixed by the protocol;
- ``unc.residual_sd_r`` (> 0) and ``unc.n_train`` (integer >= 2, the EFFECTIVE number of independent training
  outcomes); optional ``unc.cinv.<fi>.<fj>`` for every i <= j in feature order (inverse correlation matrix of the
  standardised training features; identity when absent);
- ``policy.lambda_tail`` (>= 0), ``policy.gamma_uncertainty`` (>= 0), ``policy.utility_min``,
  ``policy.uncertainty_max`` (in (0, 1]), ``policy.z_support_max`` (> 0), ``policy.impute_missing`` (0 or 1),
  ``policy.max_imputed`` (integer >= 0). No default exists in code: λ, γ and every threshold must be set by an
  authorised development protocol and frozen with the manifest;
- ``train.ridge_alpha`` (> 0), RIDGE only.
"""
from __future__ import annotations

from collections.abc import Mapping, Sequence
from dataclasses import dataclass
from decimal import Decimal
from typing import Any

from ..contracts import (
    CalibrationStatus, DataLabel, ModelKind, ModelManifest, NamedValue, ProtocolRef, ProtocolStatus,
    Provenance, dec,
)
from . import arith as ar
from .features import CATALOGUE, FeatureSpecSet, Role, reason

SUPPORTED_KINDS = (ModelKind.LINEAR, ModelKind.RIDGE)
HEADS = ('gross_r', 'stop', 'tail_r')
POLICY_KEYS = ('gamma_uncertainty', 'impute_missing', 'lambda_tail', 'max_imputed', 'uncertainty_max',
               'utility_min', 'z_support_max')
MAX_CALIBRATION_POINTS = 32


@dataclass(frozen=True)
class ScoringPolicy:
    """Decision coefficients of a manifest. Values come only from an authorised, frozen development protocol."""

    lambda_tail: Decimal
    gamma_uncertainty: Decimal
    utility_min: Decimal
    uncertainty_max: Decimal
    z_support_max: Decimal
    impute_missing: bool
    max_imputed: int

    def as_parameters(self) -> dict[str, Decimal]:
        return {'policy.lambda_tail': self.lambda_tail, 'policy.gamma_uncertainty': self.gamma_uncertainty,
                'policy.utility_min': self.utility_min, 'policy.uncertainty_max': self.uncertainty_max,
                'policy.z_support_max': self.z_support_max,
                'policy.impute_missing': Decimal(1 if self.impute_missing else 0),
                'policy.max_imputed': Decimal(self.max_imputed)}


@dataclass(frozen=True)
class LinearHead:
    intercept: Decimal
    weights: tuple[Decimal, ...]


@dataclass(frozen=True)
class LinearModel:
    """A decoded, validated LINEAR or RIDGE manifest."""

    kind: ModelKind
    feature_names: tuple[str, ...]
    means: tuple[Decimal, ...]
    scales: tuple[Decimal, ...]
    gross_r: LinearHead
    stop: LinearHead
    tail_r: LinearHead
    calibration: tuple[tuple[Decimal, Decimal], ...]
    residual_sd_r: Decimal
    n_train: int
    cinv: tuple[tuple[Decimal, ...], ...] | None
    policy: ScoringPolicy
    ridge_alpha: Decimal | None


# --------------------------------------------------------------------------------------------------- builders

def reference_null_manifest(*, protocol: ProtocolRef, created_ms: int, spec: FeatureSpecSet = CATALOGUE,
                            model_id: str = 'h3-score-null', model_version: str = '0.1.0') -> ModelManifest:
    """The reference null model: no parameters, UNCALIBRATED. Scores under it are UNESTIMATED."""
    return ModelManifest(model_id=model_id, model_version=model_version, kind=ModelKind.REFERENCE_NULL,
                         feature_spec_id=spec.spec_id, feature_spec_version=spec.spec_version, feature_names=(),
                         parameters=(), calibration=CalibrationStatus.UNCALIBRATED, training_provenance=(),
                         protocol=protocol, created_ms=created_ms,
                         notes='Reference null model: no estimate; every score is UNESTIMATED.')


def _calib_name(index: int, field: str) -> str:
    return f'head.stop.calib.{index:02d}.{field}'


def build_linear_manifest(*, model_id: str, model_version: str, kind: ModelKind, feature_names: Sequence[str],
                          standardisation: Mapping[str, tuple[Any, Any]], heads: Mapping[str, Mapping[str, Any]],
                          stop_calibration: Sequence[tuple[Any, Any]], residual_sd_r: Any, n_train: int,
                          policy: ScoringPolicy, calibration: CalibrationStatus,
                          training_provenance: Sequence[Provenance], protocol: ProtocolRef, created_ms: int,
                          notes: str, cinv: Mapping[tuple[str, str], Any] | None = None, ridge_alpha: Any = None,
                          spec: FeatureSpecSet = CATALOGUE) -> ModelManifest:
    """Lay out a LINEAR/RIDGE manifest. ``heads[head]`` maps 'intercept' and each feature name to its coefficient;
    ``cinv`` maps (fi, fj) with fi before fj in feature order (or equal) to the inverse-correlation entry.

    The builder only lays out parameters; ``validate_manifest`` decides whether the result is usable.
    """
    params: dict[str, Any] = {}
    for name in feature_names:
        mean, scale = standardisation[name]
        params[f'std.{name}.mean'], params[f'std.{name}.scale'] = mean, scale
    for head in HEADS:
        params[f'head.{head}.intercept'] = heads[head]['intercept']
        for name in feature_names:
            params[f'head.{head}.w.{name}'] = heads[head][name]
    for index, (x, p) in enumerate(stop_calibration):
        params[_calib_name(index, 'x')], params[_calib_name(index, 'p')] = x, p
    params['unc.residual_sd_r'], params['unc.n_train'] = residual_sd_r, n_train
    for (first, second), value in (cinv or {}).items():
        params[f'unc.cinv.{first}.{second}'] = value
    params.update(policy.as_parameters())
    if ridge_alpha is not None:
        params['train.ridge_alpha'] = ridge_alpha
    parameters = tuple(NamedValue(name=k, value=dec(params[k])) for k in sorted(params))
    return ModelManifest(model_id=model_id, model_version=model_version, kind=kind,
                         feature_spec_id=spec.spec_id, feature_spec_version=spec.spec_version,
                         feature_names=tuple(feature_names), parameters=parameters, calibration=calibration,
                         training_provenance=tuple(training_provenance), protocol=protocol, created_ms=created_ms,
                         notes=notes)


# ------------------------------------------------------------------------------------------------- validation

def _feature_reasons(manifest: ModelManifest, spec: FeatureSpecSet) -> list[str]:
    reasons = []
    if (manifest.feature_spec_id, manifest.feature_spec_version) != (spec.spec_id, spec.spec_version):
        reasons.append('model_feature_spec_mismatch')
    for name in manifest.feature_names:
        feature = spec.get(name)
        if feature is None:
            reasons.append(reason('model_feature_unknown', name))
        elif feature.role is not Role.PREDICTIVE:
            reasons.append(reason('model_feature_not_predictive', name))
    known = [n for n in manifest.feature_names if spec.get(n) is not None]
    if known != [n for n in spec.names if n in known]:
        reasons.append('model_feature_order_invalid')
    return reasons


def _calibration_size(values: Mapping[str, Decimal]) -> int:
    size = 0
    while _calib_name(size, 'x') in values or _calib_name(size, 'p') in values:
        size += 1
    return size


def expected_parameter_names(manifest: ModelManifest, values: Mapping[str, Decimal]) -> set[str]:
    names = manifest.feature_names
    expected = {f'std.{n}.{k}' for n in names for k in ('mean', 'scale')}
    expected |= {f'head.{h}.intercept' for h in HEADS} | {f'head.{h}.w.{n}' for h in HEADS for n in names}
    expected |= {_calib_name(i, f) for i in range(max(_calibration_size(values), 2)) for f in ('x', 'p')}
    expected |= {'unc.residual_sd_r', 'unc.n_train'} | {f'policy.{k}' for k in POLICY_KEYS}
    if any(k.startswith('unc.cinv.') for k in values):
        expected |= {f'unc.cinv.{names[i]}.{names[j]}' for i in range(len(names)) for j in range(i, len(names))}
    if manifest.kind is ModelKind.RIDGE:
        expected.add('train.ridge_alpha')
    return expected


def decode_manifest(manifest: ModelManifest | None,
                    spec: FeatureSpecSet = CATALOGUE) -> tuple[LinearModel | None, tuple[str, ...]]:
    """(model, ()) for a valid LINEAR/RIDGE manifest; (None, reasons) otherwise. Calibration status is not judged
    here: an UNCALIBRATED linear manifest decodes, the engine still refuses to estimate with it."""
    if manifest is None:
        return None, ('model_absent',)
    if manifest.kind is ModelKind.REFERENCE_NULL:
        reasons = _feature_reasons(manifest, spec)
        if manifest.parameters:
            reasons.append('model_parameter_unexpected:reference_null')
        return None, tuple(sorted(set(reasons))) or ('model_reference_null',)
    if manifest.kind not in SUPPORTED_KINDS:
        return None, ('model_kind_not_supported',)
    reasons = _feature_reasons(manifest, spec)
    if not manifest.feature_names:
        reasons.append('model_feature_names_empty')
    values = {p.name: p.value for p in manifest.parameters}
    expected = expected_parameter_names(manifest, values)
    reasons += [reason('model_parameter_missing', n) for n in sorted(expected - set(values))]
    reasons += [reason('model_parameter_unexpected', n) for n in sorted(set(values) - expected)]
    if reasons:
        return None, tuple(sorted(set(reasons)))
    try:
        model = _decode(manifest, values)
    except _Invalid as error:
        return None, (str(error),)
    return model, ()


class _Invalid(Exception):
    pass


def _value(values: Mapping[str, Decimal], name: str, *, positive: bool = False, non_negative: bool = False,
           unit_interval: bool = False, integer: bool = False) -> Decimal:
    try:
        value = ar.q(values[name])
    except ar.ArithmeticDomainError as error:
        raise _Invalid(reason('model_parameter_invalid', name)) from error
    if value != values[name] or (positive and value <= 0) or (non_negative and value < 0) \
            or (unit_interval and not ar.ZERO <= value <= ar.ONE) or (integer and not ar.is_integral(value)):
        raise _Invalid(reason('model_parameter_invalid', name))
    return value


def _decode(manifest: ModelManifest, values: Mapping[str, Decimal]) -> LinearModel:
    names = manifest.feature_names
    heads = {h: LinearHead(_value(values, f'head.{h}.intercept'),
                           tuple(_value(values, f'head.{h}.w.{n}') for n in names)) for h in HEADS}
    points = tuple((_value(values, _calib_name(i, 'x')), _value(values, _calib_name(i, 'p'), unit_interval=True))
                   for i in range(_calibration_size(values)))
    if not 2 <= len(points) <= MAX_CALIBRATION_POINTS or any(
            points[k + 1][0] <= points[k][0] or points[k + 1][1] < points[k][1] for k in range(len(points) - 1)):
        raise _Invalid('model_calibration_table_invalid')
    cinv = None
    if any(k.startswith('unc.cinv.') for k in values):
        matrix = [[ar.ZERO] * len(names) for _ in names]
        for i in range(len(names)):
            for j in range(i, len(names)):
                entry = _value(values, f'unc.cinv.{names[i]}.{names[j]}', positive=(i == j))
                matrix[i][j] = matrix[j][i] = entry
        cinv = tuple(tuple(row) for row in matrix)
    max_imputed = _value(values, 'policy.max_imputed', non_negative=True, integer=True)
    impute = _value(values, 'policy.impute_missing', integer=True)
    if impute not in (0, 1):
        raise _Invalid('model_parameter_invalid:policy.impute_missing')
    uncertainty_max = _value(values, 'policy.uncertainty_max', unit_interval=True)
    if uncertainty_max == 0:
        raise _Invalid('model_parameter_invalid:policy.uncertainty_max')
    policy = ScoringPolicy(lambda_tail=_value(values, 'policy.lambda_tail', non_negative=True),
                           gamma_uncertainty=_value(values, 'policy.gamma_uncertainty', non_negative=True),
                           utility_min=_value(values, 'policy.utility_min'), uncertainty_max=uncertainty_max,
                           z_support_max=_value(values, 'policy.z_support_max', positive=True),
                           impute_missing=impute == 1, max_imputed=int(max_imputed))
    n_train = _value(values, 'unc.n_train', positive=True, integer=True)
    if n_train < 2:
        raise _Invalid('model_parameter_invalid:unc.n_train')
    return LinearModel(
        kind=manifest.kind, feature_names=names,
        means=tuple(_value(values, f'std.{n}.mean') for n in names),
        scales=tuple(_value(values, f'std.{n}.scale', positive=True) for n in names),
        gross_r=heads['gross_r'], stop=heads['stop'], tail_r=heads['tail_r'], calibration=points,
        residual_sd_r=_value(values, 'unc.residual_sd_r', positive=True), n_train=int(n_train), cinv=cinv,
        policy=policy,
        ridge_alpha=_value(values, 'train.ridge_alpha', positive=True) if manifest.kind is ModelKind.RIDGE else None)


def validate_manifest(manifest: ModelManifest | None, spec: FeatureSpecSet = CATALOGUE) -> tuple[str, ...]:
    """Reasons why the manifest is unusable by this engine; () for a valid LINEAR/RIDGE manifest.

    The reference null model is "valid" as a reference (it has no parameters) but never estimates: it returns
    ('model_reference_null',).
    """
    return decode_manifest(manifest, spec)[1]


# ----------------------------------------------------------------------------------------- B1/B3 readiness rule

def scored_variant_readiness(model: ModelManifest | None, experiment_datasets: Sequence[Provenance],
                             experiment_protocol: ProtocolRef | None = None,
                             spec: FeatureSpecSet = CATALOGUE) -> tuple[str, ...]:
    """Reasons why the scored variants (B1, B3) may NOT run with ``model``; () when they may.

    The rule keeps B1 from silently degenerating into B0 (an uncalibrated scorer abstaining everywhere, or letting
    everything through): B1/B3 need a decodable LINEAR/RIDGE manifest that is CALIBRATED. On any non-synthetic
    experiment dataset they also need: training data that is real (a synthetic test fixture never scores real
    data), a FROZEN training protocol, training datasets disjoint from the evaluated ones (by dataset id and
    sha256), and a FROZEN experiment protocol. Without such a model the experiment runs B0/B2 only and reports
    B1/B3 as NOT_RUNNABLE; it never substitutes reference entries for scored ones.
    """
    model_obj, reasons = decode_manifest(model, spec)
    out = list(reasons)
    if model is not None and model.calibration is not CalibrationStatus.CALIBRATED:
        out.append('model_uncalibrated')
    real = [p for p in experiment_datasets if p.label is not DataLabel.SYNTHETIC]
    if model is not None and model_obj is not None and real:
        if all(p.label is DataLabel.SYNTHETIC for p in model.training_provenance):
            out.append('model_trained_on_synthetic_only')
        if model.protocol.status is not ProtocolStatus.FROZEN:
            out.append('model_protocol_not_frozen')
        evaluated = {p.dataset_id for p in real} | {p.dataset_sha256 for p in real if p.dataset_sha256}
        for p in model.training_provenance:
            if p.label is not DataLabel.SYNTHETIC and (p.dataset_id in evaluated or p.dataset_sha256 in evaluated):
                out.append(reason('model_training_overlaps_evaluation', p.dataset_id))
        if experiment_protocol is None or experiment_protocol.status is not ProtocolStatus.FROZEN:
            out.append('experiment_protocol_not_frozen')
    return tuple(sorted(set(out)))
