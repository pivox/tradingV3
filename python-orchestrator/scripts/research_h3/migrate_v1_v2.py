"""Explicit V1 -> V2 upgrades of the H3 contracts (prototype; no module is migrated by this file).

Principles:
- explicit: each function upgrades one contract and names every value V1 does not hold as a keyword argument;
- no invented value: a missing supplement is refused with ``migration_missing.<field>``, never defaulted;
- lossless where possible: when V2 is a superset, ``to_v1`` of the upgraded value equals the V1 input (same
  fingerprint), which the tests check for every function below;
- derivations are stated: the only derived values are ``ModelRefV2.calibration`` / ``available_ms`` (read from the
  V1 ``ModelManifest`` whose fingerprint the V1 ``ModelRef`` names; ``available_ms`` := ``created_ms``) and
  ``VariantV2`` (identity on B0-B3);
- downgrades exist only where V1 can hold the value: BR has no V1 variant and is refused.

Bridges to the V1 runtime: ``execution_assumptions`` builds the risk gate's ``ExecutionAssumptions`` from a
``CostModelV2`` (one cost source, CR-1), and ``gate_variant`` tells the V1 gate which score rule applies to a V2
variant (BR is score-blind like B0). Both are for tests and prototypes; the V1 gate itself is unchanged.
"""
from __future__ import annotations

from collections.abc import Sequence
from typing import Any

from .contracts import (
    CalibrationStatus, ContractError, CostModel, ExperimentManifest, FeatureSnapshot, ModelManifest, ModelRef,
    OpportunityCandidate, OpportunityScore, PolicyRef, Product, Provenance, Side, Variant,
)
from .contracts_v2 import (
    DESIGN_FACTORIAL, CostModelV2, ExperimentManifestV2, FeatureCatalogueRef, FeatureSnapshotV2, GeneratorRef,
    LabelOutcome, LabelSpecRef, LabelStatus, ModelManifestV2, ModelRefV2, OpportunityCandidateV2,
    OpportunityScoreV2, Population, ProvenanceV2, VariantV2,
)


class MigrationError(ContractError):
    """A V1 value cannot be upgraded (or downgraded) without inventing information. Stable reason code."""


def _require(**values: Any) -> None:
    for name, value in values.items():
        if value is None:
            raise MigrationError(f'migration_missing.{name}')


# ---------------------------------------------------------------------------------------------------- variants

def variant_to_v2(variant: Variant) -> VariantV2:
    return VariantV2(variant.value)


def variant_to_v1(variant: VariantV2) -> Variant:
    if variant is VariantV2.BR:
        raise MigrationError('migration_variant_br_has_no_v1')
    return Variant(variant.value)


def gate_variant(variant: VariantV2) -> Variant:
    """The V1 variant whose score rule the V1 risk gate must apply: BR is score-blind, as B0 (no score is given).

    The V1 ``PortfolioView`` cannot say BR; a BR portfolio keeps its own portfolio id and its own gate instance,
    so the only thing borrowed is the 'no score expected' rule (change request: a V2 portfolio view)."""
    return Variant.B0 if variant is VariantV2.BR else variant_to_v1(variant)


# ------------------------------------------------------------------------------------------------- provenance

def provenance_to_v2(provenance: Provenance, *, venue: str | None, product: Product | None,
                     underlyings: Sequence[str] | None, window_start_ms: int | None,
                     window_end_ms: int | None) -> ProvenanceV2:
    _require(venue=venue, product=product, underlyings=underlyings, window_start_ms=window_start_ms,
             window_end_ms=window_end_ms)
    return ProvenanceV2(source=provenance.source, dataset_id=provenance.dataset_id,
                        dataset_sha256=provenance.dataset_sha256, label=provenance.label, license=provenance.license,
                        venue=venue, product=product, underlyings=tuple(underlyings or ()),  # type: ignore[arg-type]
                        window_start_ms=window_start_ms, window_end_ms=window_end_ms)  # type: ignore[arg-type]


# ----------------------------------------------------------------------------------------------------- models

def model_ref_to_v2(ref: ModelRef, manifest: ModelManifest | None) -> ModelRefV2:
    """No model (fingerprint None) needs no manifest. Otherwise the V1 manifest the ref names must be given."""
    if ref.model_fingerprint is None:
        if manifest is not None:
            raise MigrationError('migration_manifest_for_null_model')
        return ModelRefV2(model_id=ref.model_id, model_version=ref.model_version, model_fingerprint=None,
                          calibration=CalibrationStatus.UNCALIBRATED, available_ms=None)
    _require(manifest=manifest)
    assert manifest is not None
    if manifest.ref() != ref:
        raise MigrationError('migration_manifest_not_the_referenced_model')
    return ModelRefV2(model_id=ref.model_id, model_version=ref.model_version, model_fingerprint=ref.model_fingerprint,
                      calibration=manifest.calibration, available_ms=manifest.created_ms)


def _same_datasets(v1: Sequence[Provenance], v2: Sequence[ProvenanceV2]) -> None:
    if sorted(p.fingerprint() for p in v1) != sorted(p.to_v1().fingerprint() for p in v2) or len(v1) != len(v2):
        raise MigrationError('migration_datasets_not_the_v1_datasets')


def model_manifest_to_v2(manifest: ModelManifest, *, catalogue_fingerprint: str | None, side: Side | None,
                         label_spec: LabelSpecRef | None, training_provenance: Sequence[ProvenanceV2],
                         training_start_ms: int | None, training_end_ms: int | None,
                         standardisation_cutoff_ms: int | None) -> ModelManifestV2:
    """``side`` None is an explicit 'both sides' (V1 models are side-agnostic). ``available_ms`` := ``created_ms``
    (the V1 manifest existed from its creation). Calibrated models need the label identity and the training
    window; ``training_provenance`` must be the V1 training datasets, upgraded."""
    _require(catalogue_fingerprint=catalogue_fingerprint)
    _same_datasets(manifest.training_provenance, training_provenance)
    if manifest.calibration is CalibrationStatus.CALIBRATED:
        _require(label_spec=label_spec, training_start_ms=training_start_ms, training_end_ms=training_end_ms,
                 standardisation_cutoff_ms=standardisation_cutoff_ms)
    catalogue = FeatureCatalogueRef(catalogue_id=manifest.feature_spec_id,
                                    catalogue_version=manifest.feature_spec_version,
                                    catalogue_fingerprint=catalogue_fingerprint)  # type: ignore[arg-type]
    return ModelManifestV2(
        model_id=manifest.model_id, model_version=manifest.model_version, kind=manifest.kind, catalogue=catalogue,
        side=side, feature_names=manifest.feature_names, parameters=manifest.parameters,
        calibration=manifest.calibration, label_spec=label_spec,
        training_provenance=tuple(sorted(training_provenance, key=lambda p: p.dataset_id)),
        training_start_ms=training_start_ms, training_end_ms=training_end_ms,
        standardisation_cutoff_ms=standardisation_cutoff_ms, protocol=manifest.protocol,
        created_ms=manifest.created_ms, available_ms=manifest.created_ms, notes=manifest.notes)


# ------------------------------------------------------------------------------------------ snapshots/candidates

def snapshot_to_v2(snapshot: FeatureSnapshot, *, catalogue_fingerprint: str | None) -> FeatureSnapshotV2:
    _require(catalogue_fingerprint=catalogue_fingerprint)
    catalogue = FeatureCatalogueRef(catalogue_id=snapshot.feature_spec_id,
                                    catalogue_version=snapshot.feature_spec_version,
                                    catalogue_fingerprint=catalogue_fingerprint)  # type: ignore[arg-type]
    return FeatureSnapshotV2(header=snapshot.header, snapshot_id=snapshot.snapshot_id, catalogue=catalogue,
                             features=snapshot.features, data_status=snapshot.data_status)


def candidate_to_v2(candidate: OpportunityCandidate, *, generator_fingerprint: str | None) -> OpportunityCandidateV2:
    """The candidate keeps its V1 ``SnapshotRef``: a snapshot upgraded to V2 has a new fingerprint, so a caller
    that also upgrades snapshots must rebuild candidates from them, not migrate the old references."""
    _require(generator_fingerprint=generator_fingerprint)
    generator = GeneratorRef(generator_id=candidate.generator_id, generator_version=candidate.generator_version,
                             hypothesis_id=candidate.hypothesis_id,
                             generator_fingerprint=generator_fingerprint)  # type: ignore[arg-type]
    return OpportunityCandidateV2(
        header=candidate.header, candidate_id=candidate.candidate_id, generator=generator, snapshot=candidate.snapshot,
        entry_reference_price=candidate.entry_reference_price, initial_stop_price=candidate.initial_stop_price,
        initial_target_price=candidate.initial_target_price, max_holding_ms=candidate.max_holding_ms)


def score_to_v2(score: OpportunityScore, *, candidate: OpportunityCandidateV2,
                model_manifest: ModelManifest | None) -> OpportunityScoreV2:
    """Binds the score to the exact V2 candidate it scores (same id; its fingerprint is recorded)."""
    if candidate.candidate_id != score.candidate_id or candidate.header != score.header:
        raise MigrationError('migration_score_candidate_mismatch')
    return OpportunityScoreV2(
        header=score.header, candidate_id=score.candidate_id, candidate_fingerprint=candidate.fingerprint(),
        snapshot=score.snapshot, model=model_ref_to_v2(score.model, model_manifest), status=score.status,
        verdict=score.verdict, expected_net_r=score.expected_net_r, p_stop=score.p_stop,
        tail_loss_r=score.tail_loss_r, uncertainty=score.uncertainty, data_quality=score.data_quality,
        utility=score.utility, rank=score.rank, reasons=score.reasons)


# ------------------------------------------------------------------------------------------------------ costs

def cost_model_to_v2(cost_model: CostModel, assumptions: Any) -> CostModelV2:
    """``assumptions`` is the risk gate's ``integration.costs.ExecutionAssumptions`` that held the numeric
    half-spread and funding provision in V1 (CR-1). It must be the base scenario (``pessimistic`` False)."""
    _require(assumptions=assumptions)
    if assumptions.pessimistic:
        raise MigrationError('migration_cost_assumptions_pessimistic')
    return CostModelV2(maker_fee_rate=cost_model.maker_fee_rate, taker_fee_rate=cost_model.taker_fee_rate,
                       slippage_rate=cost_model.slippage_rate, half_spread_rate=assumptions.half_spread_rate,
                       funding_provision_rate=assumptions.funding_provision_rate,
                       funding_interval_ms=assumptions.funding_interval_ms, spread_model=cost_model.spread_model,
                       funding_model=cost_model.funding_model,
                       pessimistic_multiplier=cost_model.pessimistic_multiplier)


def execution_assumptions(cost_model: CostModelV2, *, pessimistic: bool = False, **others: Any) -> Any:
    """The V1 gate's ``ExecutionAssumptions`` carrying the V2 numbers; ``others`` sets the remaining (DRAFT)
    assumptions explicitly. Import is local: this module stays importable without the integration package."""
    from .integration.costs import ExecutionAssumptions
    for name in ('half_spread_rate', 'funding_provision_rate', 'funding_interval_ms', 'pessimistic'):
        if name in others:
            raise MigrationError('migration_cost_source_duplicated')
    return ExecutionAssumptions(half_spread_rate=cost_model.half_spread_rate,
                                funding_provision_rate=cost_model.funding_provision_rate,
                                funding_interval_ms=cost_model.funding_interval_ms, pessimistic=pessimistic, **others)


# ----------------------------------------------------------------------------------------------------- labels

def label_to_v2(label: Any, *, candidate: OpportunityCandidateV2, label_spec: LabelSpecRef,
                available_ms: int | None = None) -> LabelOutcome:
    """From ``integration.label_replay.CandidateLabel``. The value is the label in ``label_spec.unit``; the exit
    instant of a traded candidate is the label's ``exit_ms`` (added at integration, CR-V2-3) or, for a record
    without it, must be given (never guessed); both given and different is refused.

    Independent review CTO-02: a replay label exists only where an order filled, so its spec must declare
    ``Population.EXECUTABLE`` (which ``ModelManifestV2`` refuses for training); and a traded label whose horizon is
    not covered or whose price path has gaps is ABSENT (``label_horizon_not_covered`` / ``label_price_path_gap``),
    never a LABELLED value."""
    if label.candidate_id != candidate.candidate_id or label.decision_ms != candidate.header.clock.decision_ms:
        raise MigrationError('migration_label_candidate_mismatch')
    if label_spec.population is not Population.EXECUTABLE:
        raise MigrationError('migration_replay_label_is_executable_conditioned')
    exit_ms = getattr(label, 'exit_ms', None)
    if exit_ms is not None and available_ms is not None and exit_ms != available_ms:
        raise MigrationError('migration_label_exit_time_mismatch')
    if label.traded and available_ms is None:
        available_ms = exit_ms
    if not label.traded:
        return LabelOutcome(candidate_id=label.candidate_id, candidate_fingerprint=candidate.fingerprint(),
                            label_spec=label_spec, decision_ms=label.decision_ms, available_ms=available_ms,
                            status=LabelStatus.ABSENT, value=None, stopped=None,
                            absence_reasons=tuple(label.refusal_reasons))
    incomplete = []
    if getattr(label, 'horizon_covered', True) is False:
        incomplete.append('label_horizon_not_covered')
    if (getattr(label, 'price_path_gaps', 0) or 0) > 0:
        incomplete.append('label_price_path_gap')
    if incomplete:
        return LabelOutcome(candidate_id=label.candidate_id, candidate_fingerprint=candidate.fingerprint(),
                            label_spec=label_spec, decision_ms=label.decision_ms, available_ms=available_ms,
                            status=LabelStatus.ABSENT, value=None, stopped=None, absence_reasons=tuple(incomplete))
    _require(available_ms=available_ms)
    value = getattr(label, label_spec.unit.value)
    _require(value=value)
    return LabelOutcome(candidate_id=label.candidate_id, candidate_fingerprint=candidate.fingerprint(),
                        label_spec=label_spec, decision_ms=label.decision_ms, available_ms=available_ms,
                        status=LabelStatus.LABELLED, value=value, stopped=label.stopped, absence_reasons=())


# ------------------------------------------------------------------------------------------------- experiments

def experiment_to_v2(manifest: ExperimentManifest, *, generator_fingerprint: str | None,
                     catalogue: FeatureCatalogueRef | None, scoring_model_manifest: ModelManifest | None,
                     label_spec: LabelSpecRef | None, target_population: Population | None,
                     pessimistic_risk_policy: PolicyRef | None, cost_assumptions: Any,
                     datasets: Sequence[ProvenanceV2], statistical_plan_fingerprint: str | None,
                     trial_ledger_head: str | None) -> ExperimentManifestV2:
    """V1 factorial manifest -> V2 factorial (no BR: a random control is a new pre-registration, not a migration).
    The V1 ``seed`` becomes the single pre-declared lottery seed. ``statistical_plan_fingerprint`` and
    ``trial_ledger_head`` may be None only where V2 allows it (DRAFT protocol)."""
    _require(generator_fingerprint=generator_fingerprint, catalogue=catalogue, target_population=target_population,
             pessimistic_risk_policy=pessimistic_risk_policy)
    _same_datasets(manifest.datasets, datasets)
    generator = GeneratorRef(generator_id=manifest.candidate_generator_id,
                             generator_version=manifest.candidate_generator_version,
                             hypothesis_id=manifest.hypothesis_id,
                             generator_fingerprint=generator_fingerprint)  # type: ignore[arg-type]
    return ExperimentManifestV2(
        experiment_id=manifest.experiment_id, protocol=manifest.protocol,
        statistical_plan_fingerprint=statistical_plan_fingerprint, variants=DESIGN_FACTORIAL, generator=generator,
        catalogue=catalogue, scoring_model=model_ref_to_v2(manifest.scoring_model, scoring_model_manifest),  # type: ignore[arg-type]
        label_spec=label_spec, target_population=target_population,  # type: ignore[arg-type]
        random_control=None, reference_lottery_seeds=(manifest.seed,), static_policy=manifest.static_policy,
        adaptive_policy=manifest.adaptive_policy, risk_policy=manifest.risk_policy,
        pessimistic_risk_policy=pessimistic_risk_policy,  # type: ignore[arg-type]
        limits=manifest.limits, cost_model=cost_model_to_v2(manifest.cost_model, cost_assumptions),
        initial_equity=manifest.initial_equity, quote_ccy=manifest.quote_ccy,
        datasets=tuple(sorted(datasets, key=lambda p: p.dataset_id)), trial_ledger_head=trial_ledger_head,
        code_digest=manifest.code_digest, created_ms=manifest.created_ms)
