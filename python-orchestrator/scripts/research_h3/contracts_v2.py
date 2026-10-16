"""H3 shared contracts, schema version 2 (prototype, not wired into any module).

Schema 1 (``contracts.py``) is frozen and stays the wire form of every running module. Schema 2 adds only what a
named consumer needs (see docs/handbook/research/H3-CONTRACTS-V2-DESIGN.md, one row per field). It reuses the V1
helpers and the V1 blocks that did not change, by import: a V2 document may therefore nest V1 blocks with their V1
schema ids (``h3.clock/1``, ``h3.header/1``, ...). Only the contracts whose content changed get a ``/2`` id.

Upgrades from V1 are explicit (``migrate_v1_v2``): a V2 value is never built from V1 with an invented value.
Projections back to V1 (``to_v1``) exist where V2 is a strict superset, and the V1 validator is reused through them,
so a V2 candidate, snapshot or score obeys every V1 rule by construction.

Research prototype. Nothing here reads market data, calls an exchange or a database, or depends on the Symfony or
Temporal runtime. The H3 risk policy is unchanged: ``H3_LIMITS`` (500 USDT reference equity, 0.5 % per trade, 1 %
total, leverage <= 1x, mandatory stop) is imported from V1 and required by the V2 experiment manifest.
"""
from __future__ import annotations

import enum
import re
from collections.abc import Mapping, Sequence
from dataclasses import dataclass
from decimal import Decimal
from typing import ClassVar

from .contracts import (
    H3_LIMITS, ONE, ZERO, AuditEvent, CalibrationStatus, Contract, ContractError, CostModel, DataLabel, DataStatus,
    FeatureSnapshot, FeatureValue, H3Limits, Header, ModelKind, ModelRef, NamedValue, OpportunityCandidate,
    OpportunityScore, PolicyRef, Product, ProtocolRef, ProtocolStatus, Provenance, ScoreStatus, ScoreVerdict, Side,
    SnapshotRef, check_id, check_reasons, check_sha,
)

SCHEMA_VERSION = 2
_STRATUM_KEY = re.compile(r'\A[^\s]{1,256}\Z')


def _ms(value: object, code: str) -> None:
    if isinstance(value, bool) or not isinstance(value, int) or value < 0:
        raise ContractError(code)


def _sorted_unique(values: Sequence[object], code: str) -> None:
    if list(values) != sorted(set(values)):  # type: ignore[type-var]
        raise ContractError(code)


# ----------------------------------------------------------------------------------------------------------- enums

class VariantV2(str, enum.Enum):
    """V1 ``Variant`` plus BR, the random-abstention control (review R1-F1, schema v2 backlog #22)."""

    B0 = 'B0'   # reference entries, static management
    BR = 'BR'   # B0's book, a seeded random subset with B1's activity budget, static management
    B1 = 'B1'   # scored entries, static management
    B2 = 'B2'   # reference entries, adaptive management
    B3 = 'B3'   # scored entries, adaptive management


SCORED_V2 = (VariantV2.B1, VariantV2.B3)
ADAPTIVE_V2 = (VariantV2.B2, VariantV2.B3)
#: Variants whose entry order or subset is drawn from a seed (B0/B2: lottery order; BR: random subset).
SEEDED_V2 = (VariantV2.B0, VariantV2.BR, VariantV2.B2)

DESIGN_H3A_CONTROLLED = (VariantV2.B0, VariantV2.BR, VariantV2.B1)
DESIGN_FACTORIAL = (VariantV2.B0, VariantV2.B1, VariantV2.B2, VariantV2.B3)
DESIGN_FACTORIAL_CONTROLLED = (VariantV2.B0, VariantV2.BR, VariantV2.B1, VariantV2.B2, VariantV2.B3)
DESIGNS = (DESIGN_H3A_CONTROLLED, DESIGN_FACTORIAL, DESIGN_FACTORIAL_CONTROLLED)


class Population(str, enum.Enum):
    """Shared population names (H3-A testability brief). The scoring's target population is chosen by the labels
    audit; the manifest records which one the scores and labels are evaluated on."""

    ALL_CANDIDATES = 'ALL_CANDIDATES'
    TECHNICALLY_ELIGIBLE = 'TECHNICALLY_ELIGIBLE'
    LABELABLE = 'LABELABLE'
    EXECUTABLE = 'EXECUTABLE'


class LabelUnit(str, enum.Enum):
    """Backlog #17: the scorer's training label is gross R, the validation trade record is net R."""

    GROSS_R = 'gross_r'
    NET_R = 'net_r'


class LabelStatus(str, enum.Enum):
    LABELLED = 'labelled'
    ABSENT = 'absent'


class ControlMethod(str, enum.Enum):
    GLOBAL_BUDGET = 'global_budget'
    STRATIFIED = 'stratified'


class BudgetSource(str, enum.Enum):
    B1_ACCEPTANCE = 'b1_acceptance'   # B1's acceptance count per stratum, from B1's decisions, never from outcomes


class CostScenarioV2(str, enum.Enum):
    BASE = 'base'
    PESSIMISTIC = 'pessimistic'


#: Strata keys known at decision time. Anything else (an outcome, a score, a label) is refused.
DECISION_TIME_STRATA = ('decision_day', 'decision_week', 'inst_id', 'side', 'underlying', 'venue')


# --------------------------------------------------------------------------------------------- identity blocks

@dataclass(frozen=True)
class ProvenanceV2(Contract):
    """V1 provenance plus venue/product, underlyings and the observation window ``[window_start_ms,
    window_end_ms)`` of the dataset (backlog #20, review R1-F7): independence is an (underlying x time) property,
    across venues, not a dataset-id property."""

    SCHEMA: ClassVar[str] = 'h3.provenance/2'
    source: str
    dataset_id: str
    dataset_sha256: str | None
    label: DataLabel
    license: str
    venue: str
    product: Product
    underlyings: tuple[str, ...]
    window_start_ms: int
    window_end_ms: int

    def _validate(self) -> None:
        self.to_v1()   # every V1 rule (synthetic <-> source, real data needs a sha256)
        check_id(self.venue, 'h3_provenance_venue_invalid')
        if not self.underlyings:
            raise ContractError('h3_provenance_underlyings_required')
        for underlying in self.underlyings:
            check_id(underlying, 'h3_provenance_underlying_invalid')
        _sorted_unique(self.underlyings, 'h3_provenance_underlyings_not_sorted_unique')
        _ms(self.window_start_ms, 'h3_provenance_window_invalid')
        _ms(self.window_end_ms, 'h3_provenance_window_invalid')
        if self.window_start_ms >= self.window_end_ms:
            raise ContractError('h3_provenance_window_invalid')

    def to_v1(self) -> Provenance:
        return Provenance(source=self.source, dataset_id=self.dataset_id, dataset_sha256=self.dataset_sha256,
                          label=self.label, license=self.license)

    def matches_v1(self, provenance: Provenance) -> bool:
        """True when a V1 header provenance names this dataset (same id, hash, label, source, licence)."""
        return self.to_v1() == provenance

    def overlaps(self, other: ProvenanceV2) -> bool:
        """Same underlying and intersecting windows, whatever the venue (R1-F7). Synthetic data never overlaps."""
        if DataLabel.SYNTHETIC in (self.label, other.label):
            return False
        return bool(set(self.underlyings) & set(other.underlyings)) \
            and self.window_start_ms < other.window_end_ms and other.window_start_ms < self.window_end_ms


@dataclass(frozen=True)
class GeneratorRef(Contract):
    """Candidate generator identity. ``generator_fingerprint`` hashes its full parameter document, so two
    generators with the same id and version but different parameters are distinguishable (trial ledger, manifest)."""

    SCHEMA: ClassVar[str] = 'h3.generator_ref/2'
    generator_id: str
    generator_version: str
    hypothesis_id: str
    generator_fingerprint: str

    def _validate(self) -> None:
        for value in (self.generator_id, self.generator_version, self.hypothesis_id):
            check_id(value)
        check_sha(self.generator_fingerprint)


@dataclass(frozen=True)
class FeatureCatalogueRef(Contract):
    """Feature catalogue identity bound to its content (backlog #1, #2)."""

    SCHEMA: ClassVar[str] = 'h3.feature_catalogue_ref/2'
    catalogue_id: str
    catalogue_version: str
    catalogue_fingerprint: str

    def _validate(self) -> None:
        check_id(self.catalogue_id)
        check_id(self.catalogue_version)
        check_sha(self.catalogue_fingerprint)


@dataclass(frozen=True)
class LabelSpecRef(Contract):
    """Label identity: which outcome, in which unit, built by which replay rule (content fingerprint)."""

    SCHEMA: ClassVar[str] = 'h3.label_spec_ref/2'
    label_id: str
    label_version: str
    unit: LabelUnit
    label_fingerprint: str

    def _validate(self) -> None:
        check_id(self.label_id)
        check_id(self.label_version)
        check_sha(self.label_fingerprint)


@dataclass(frozen=True)
class ModelRefV2(Contract):
    """V1 ``ModelRef`` plus the calibration status and the instant the model became available.

    ``available_ms`` lets a score prove, without loading the manifest, that its model existed at the decision
    (walk-forward anti-lookahead). No model at all (``model_fingerprint`` None) is UNCALIBRATED with no instant.
    """

    SCHEMA: ClassVar[str] = 'h3.model_ref/2'
    model_id: str
    model_version: str
    model_fingerprint: str | None
    calibration: CalibrationStatus
    available_ms: int | None

    def _validate(self) -> None:
        self.to_v1()
        if self.model_fingerprint is None:
            if self.calibration is not CalibrationStatus.UNCALIBRATED or self.available_ms is not None:
                raise ContractError('h3_model_ref_null_model_invalid')
        elif self.available_ms is None:
            raise ContractError('h3_model_ref_available_required')
        if self.available_ms is not None:
            _ms(self.available_ms, 'h3_model_ref_available_invalid')

    def to_v1(self) -> ModelRef:
        return ModelRef(model_id=self.model_id, model_version=self.model_version,
                        model_fingerprint=self.model_fingerprint)


# ------------------------------------------------------------------------------------------------------ features

@dataclass(frozen=True)
class FeatureSnapshotV2(Contract):
    """V1 snapshot with the catalogue bound by content (backlog #2). Every V1 rule applies (via ``to_v1``)."""

    SCHEMA: ClassVar[str] = 'h3.feature_snapshot/2'
    header: Header
    snapshot_id: str
    catalogue: FeatureCatalogueRef
    features: tuple[FeatureValue, ...]
    data_status: DataStatus

    def _validate(self) -> None:
        self.to_v1()

    def to_v1(self) -> FeatureSnapshot:
        return FeatureSnapshot(header=self.header, snapshot_id=self.snapshot_id,
                               feature_spec_id=self.catalogue.catalogue_id,
                               feature_spec_version=self.catalogue.catalogue_version, features=self.features,
                               data_status=self.data_status)

    def ref(self) -> SnapshotRef:
        return SnapshotRef(snapshot_id=self.snapshot_id, fingerprint=self.fingerprint())


# ----------------------------------------------------------------------------------------------- candidates/scores

@dataclass(frozen=True)
class OpportunityCandidateV2(Contract):
    """V1 candidate with the generator bound by content (``GeneratorRef``). ``to_v1`` feeds the V1 risk gate."""

    SCHEMA: ClassVar[str] = 'h3.opportunity_candidate/2'
    header: Header
    candidate_id: str
    generator: GeneratorRef
    snapshot: SnapshotRef
    entry_reference_price: Decimal
    initial_stop_price: Decimal
    initial_target_price: Decimal | None
    max_holding_ms: int

    def _validate(self) -> None:
        self.to_v1()

    def to_v1(self) -> OpportunityCandidate:
        g = self.generator
        return OpportunityCandidate(
            header=self.header, candidate_id=self.candidate_id, generator_id=g.generator_id,
            generator_version=g.generator_version, hypothesis_id=g.hypothesis_id, snapshot=self.snapshot,
            entry_reference_price=self.entry_reference_price, initial_stop_price=self.initial_stop_price,
            initial_target_price=self.initial_target_price, max_holding_ms=self.max_holding_ms)


@dataclass(frozen=True)
class OpportunityScoreV2(Contract):
    """V1 score bound to the exact candidate (``candidate_fingerprint``) and to a ``ModelRefV2``.

    Added rules: an ESTIMATED score needs a CALIBRATED model that was available at the decision. Abstention is
    unchanged: NO_TRADE needs a reason code; INVALID_INPUT / MODEL_INVALID must abstain; UNESTIMATED carries no number.
    """

    SCHEMA: ClassVar[str] = 'h3.opportunity_score/2'
    header: Header
    candidate_id: str
    candidate_fingerprint: str
    snapshot: SnapshotRef
    model: ModelRefV2
    status: ScoreStatus
    verdict: ScoreVerdict
    expected_net_r: Decimal | None
    p_stop: Decimal | None
    tail_loss_r: Decimal | None
    uncertainty: Decimal | None
    data_quality: Decimal
    utility: Decimal | None
    rank: int | None
    reasons: tuple[str, ...]

    def _validate(self) -> None:
        self.to_v1()
        check_sha(self.candidate_fingerprint)
        if self.status is ScoreStatus.ESTIMATED:
            if self.model.calibration is not CalibrationStatus.CALIBRATED:
                raise ContractError('h3_score_estimate_model_uncalibrated')
            assert self.model.available_ms is not None
            if self.model.available_ms > self.header.clock.decision_ms:
                raise ContractError('h3_score_model_from_future')

    def to_v1(self) -> OpportunityScore:
        return OpportunityScore(
            header=self.header, candidate_id=self.candidate_id, snapshot=self.snapshot, model=self.model.to_v1(),
            status=self.status, verdict=self.verdict, expected_net_r=self.expected_net_r, p_stop=self.p_stop,
            tail_loss_r=self.tail_loss_r, uncertainty=self.uncertainty, data_quality=self.data_quality,
            utility=self.utility, rank=self.rank, reasons=self.reasons)

    def scores(self, candidate: OpportunityCandidateV2) -> bool:
        return candidate.candidate_id == self.candidate_id and candidate.fingerprint() == self.candidate_fingerprint


# --------------------------------------------------------------------------------------------------------- labels

@dataclass(frozen=True)
class LabelOutcome(Contract):
    """The label of one candidate, or the reasons it has none.

    ``available_ms`` is when the outcome was known (the exit), strictly after the decision; a training set may use a
    label only if ``available_ms`` is before the model's standardisation cutoff. An ABSENT label keeps its reason
    codes (e.g. ``fill_beyond_collar``, ``no_market_data_after_decision``, ``horizon_not_elapsed``), so the
    labelled subset can be compared with the evaluated population (review R1-F3).
    """

    SCHEMA: ClassVar[str] = 'h3.label_outcome/2'
    candidate_id: str
    candidate_fingerprint: str
    label_spec: LabelSpecRef
    decision_ms: int
    available_ms: int | None
    status: LabelStatus
    value: Decimal | None
    stopped: bool | None
    absence_reasons: tuple[str, ...]

    def _validate(self) -> None:
        check_id(self.candidate_id)
        check_sha(self.candidate_fingerprint)
        check_reasons(self.absence_reasons)
        _ms(self.decision_ms, 'h3_label_time_invalid')
        if self.available_ms is not None:
            _ms(self.available_ms, 'h3_label_time_invalid')
            if self.available_ms <= self.decision_ms:
                raise ContractError('h3_label_available_before_outcome')
        if self.status is LabelStatus.LABELLED:
            if self.value is None or self.stopped is None or self.available_ms is None or self.absence_reasons:
                raise ContractError('h3_label_labelled_incomplete')
        elif self.value is not None or self.stopped is not None or not self.absence_reasons:
            raise ContractError('h3_label_absent_needs_reason_and_no_value')


# --------------------------------------------------------------------------------------------- random control

@dataclass(frozen=True)
class RandomControlSpec(Contract):
    """Pre-registered random-abstention control BR (shared interface with the abstention module).

    ``method`` GLOBAL_BUDGET draws B1's total acceptance count over the whole book (``strata_keys`` empty);
    STRATIFIED draws B1's acceptance count inside each stratum of ``strata_keys`` (decision-time keys only).
    ``seeds`` are pre-registered, sorted and unique: every seed is run and reported, none is chosen.
    """

    SCHEMA: ClassVar[str] = 'h3.random_control_spec/2'
    control_id: str
    method: ControlMethod
    strata_keys: tuple[str, ...]
    seeds: tuple[int, ...]
    budget_source: BudgetSource

    def _validate(self) -> None:
        check_id(self.control_id)
        if (self.method is ControlMethod.STRATIFIED) != bool(self.strata_keys):
            raise ContractError('h3_control_strata_method_mismatch')
        if any(key not in DECISION_TIME_STRATA for key in self.strata_keys):
            raise ContractError('h3_control_stratum_not_decision_time')
        _sorted_unique(self.strata_keys, 'h3_control_strata_not_sorted_unique')
        if not self.seeds or any(isinstance(s, bool) or s < 0 for s in self.seeds):
            raise ContractError('h3_control_seeds_invalid')
        _sorted_unique(self.seeds, 'h3_control_seeds_not_sorted_unique')


@dataclass(frozen=True)
class StratumCount(Contract):
    SCHEMA: ClassVar[str] = 'h3.stratum_count/2'
    stratum_key: str
    candidate_count: int
    accepted_count: int

    def _validate(self) -> None:
        if not isinstance(self.stratum_key, str) or not _STRATUM_KEY.match(self.stratum_key):
            raise ContractError('h3_budget_stratum_key_invalid')
        if self.candidate_count < 0 or not 0 <= self.accepted_count <= self.candidate_count:
            raise ContractError('h3_budget_count_invalid')


@dataclass(frozen=True)
class ActivityBudget(Contract):
    """B1's activity per stratum, taken from B1's decisions (never outcomes); BR draws the same counts.

    The shared interface's mapping ``{stratum_key: (candidate_count, accepted_count)}`` is carried as
    ``strata`` sorted by key (``from_mapping`` / ``as_mapping``); ``fingerprint()`` is the contract fingerprint.
    """

    SCHEMA: ClassVar[str] = 'h3.activity_budget/2'
    budget_id: str
    strata: tuple[StratumCount, ...]
    derived_from_variant: VariantV2

    def _validate(self) -> None:
        check_id(self.budget_id)
        if self.derived_from_variant is not VariantV2.B1:
            raise ContractError('h3_budget_not_from_b1')
        if not self.strata:
            raise ContractError('h3_budget_empty')
        _sorted_unique([s.stratum_key for s in self.strata], 'h3_budget_strata_not_sorted_unique')

    @classmethod
    def from_mapping(cls, budget_id: str, counts: Mapping[str, tuple[int, int]]) -> ActivityBudget:
        return cls(budget_id=budget_id, derived_from_variant=VariantV2.B1,
                   strata=tuple(StratumCount(stratum_key=k, candidate_count=v[0], accepted_count=v[1])
                                for k, v in sorted(counts.items())))

    def as_mapping(self) -> dict[str, tuple[int, int]]:
        return {s.stratum_key: (s.candidate_count, s.accepted_count) for s in self.strata}

    @property
    def candidate_count(self) -> int:
        return sum(s.candidate_count for s in self.strata)

    @property
    def accepted_count(self) -> int:
        return sum(s.accepted_count for s in self.strata)


# ------------------------------------------------------------------------------------------------------- costs

@dataclass(frozen=True)
class CostModelV2(Contract):
    """V1 cost model plus the numeric half-spread and funding provision (backlog #7, CR-1), so the scorer, the risk
    gate and the simulator share one cost source. ``to_v1`` projects onto the V1 model the gate still takes."""

    SCHEMA: ClassVar[str] = 'h3.cost_model/2'
    maker_fee_rate: Decimal
    taker_fee_rate: Decimal
    slippage_rate: Decimal
    half_spread_rate: Decimal
    funding_provision_rate: Decimal
    funding_interval_ms: int
    spread_model: str
    funding_model: str
    pessimistic_multiplier: Decimal

    def _validate(self) -> None:
        self.to_v1()
        for value in (self.maker_fee_rate, self.taker_fee_rate, self.slippage_rate, self.half_spread_rate,
                      self.funding_provision_rate):
            if not ZERO <= value < ONE:
                raise ContractError('h3_cost_model_invalid')
        if self.funding_interval_ms <= 0:
            raise ContractError('h3_cost_model_invalid')

    def to_v1(self) -> CostModel:
        return CostModel(maker_fee_rate=self.maker_fee_rate, taker_fee_rate=self.taker_fee_rate,
                         slippage_rate=self.slippage_rate, spread_model=self.spread_model,
                         funding_model=self.funding_model, pessimistic_multiplier=self.pessimistic_multiplier)


# --------------------------------------------------------------------------------------------------- manifests

@dataclass(frozen=True)
class ModelManifestV2(Contract):
    """V1 model manifest plus: catalogue bound by content and optional side (#1), label identity, training window
    and standardisation cutoff (#20), and the instant the model became available.

    CALIBRATED needs all of them, real training data needs a FROZEN protocol (V1 rule), the training window must lie
    inside the training datasets' windows, and nothing may become available before it was trained:
    ``standardisation_cutoff_ms <= training_end_ms <= available_ms`` and ``created_ms <= available_ms``.
    UNCALIBRATED carries none of them.
    """

    SCHEMA: ClassVar[str] = 'h3.model_manifest/2'
    model_id: str
    model_version: str
    kind: ModelKind
    catalogue: FeatureCatalogueRef
    side: Side | None
    feature_names: tuple[str, ...]
    parameters: tuple[NamedValue, ...]
    calibration: CalibrationStatus
    label_spec: LabelSpecRef | None
    training_provenance: tuple[ProvenanceV2, ...]
    training_start_ms: int | None
    training_end_ms: int | None
    standardisation_cutoff_ms: int | None
    protocol: ProtocolRef
    created_ms: int
    available_ms: int
    notes: str

    def _validate(self) -> None:
        for value in (self.model_id, self.model_version):
            check_id(value)
        _ms(self.created_ms, 'h3_model_time_invalid')
        _ms(self.available_ms, 'h3_model_time_invalid')
        if self.created_ms > self.available_ms:
            raise ContractError('h3_model_time_invalid')
        if len(set(self.feature_names)) != len(self.feature_names):
            raise ContractError('h3_model_feature_duplicate')
        for name in self.feature_names:
            check_id(name)
        _sorted_unique([p.name for p in self.parameters], 'h3_model_parameters_not_sorted_unique')
        window = (self.training_start_ms, self.training_end_ms, self.standardisation_cutoff_ms)
        if self.calibration is CalibrationStatus.CALIBRATED:
            if self.kind is ModelKind.REFERENCE_NULL or not self.training_provenance:
                raise ContractError('h3_model_calibration_without_training')
            if self.label_spec is None or any(v is None for v in window):
                raise ContractError('h3_model_calibration_identity_incomplete')
            start, end, cutoff = window
            assert start is not None and end is not None and cutoff is not None
            for value in window:
                _ms(value, 'h3_model_time_invalid')
            if not start < end or not start <= cutoff <= end or end > self.available_ms:
                raise ContractError('h3_model_training_window_invalid')
            if start < min(p.window_start_ms for p in self.training_provenance) \
                    or end > max(p.window_end_ms for p in self.training_provenance):
                raise ContractError('h3_model_training_window_outside_data')
            real = any(p.label is not DataLabel.SYNTHETIC for p in self.training_provenance)
            if real and self.protocol.status is not ProtocolStatus.FROZEN:
                raise ContractError('h3_model_real_training_needs_frozen_protocol')
            if any(p.label not in (DataLabel.SYNTHETIC, DataLabel.DEVELOPMENT) for p in self.training_provenance):
                raise ContractError('h3_model_trained_on_confirmatory_data')
        elif self.training_provenance or self.label_spec is not None or any(v is not None for v in window):
            raise ContractError('h3_model_uncalibrated_with_training')

    def ref(self) -> ModelRefV2:
        return ModelRefV2(model_id=self.model_id, model_version=self.model_version,
                          model_fingerprint=self.fingerprint(), calibration=self.calibration,
                          available_ms=self.available_ms)


@dataclass(frozen=True)
class ExperimentManifestV2(Contract):
    """One pre-registered H3 experiment. Designs: (B0, BR, B1) for H3-A against its random-abstention control,
    the factorial (B0, B1, B2, B3), or both (B0, BR, B1, B2, B3). Every variant shares candidates, market data,
    costs and risk policy; BR exists iff ``random_control`` is declared, B2/B3 iff ``adaptive_policy`` is.

    Rules beyond V1: capital is the H3 reference equity (500) under ``H3_LIMITS``; base and pessimistic risk
    policies are both anchored (#21); B0/B2 lottery seeds are a pre-declared set (R1-F12); a FROZEN protocol needs
    the statistical plan fingerprint (#12) and the trial-ledger head (R1-F12); a calibrated scoring model needs a
    label identity and must exist before the manifest; non-synthetic datasets with different labels may not overlap
    in (underlying x time) (#20).
    """

    SCHEMA: ClassVar[str] = 'h3.experiment_manifest/2'
    experiment_id: str
    protocol: ProtocolRef
    statistical_plan_fingerprint: str | None
    variants: tuple[VariantV2, ...]
    generator: GeneratorRef
    catalogue: FeatureCatalogueRef
    scoring_model: ModelRefV2
    label_spec: LabelSpecRef | None
    target_population: Population
    random_control: RandomControlSpec | None
    reference_lottery_seeds: tuple[int, ...]
    static_policy: PolicyRef
    adaptive_policy: PolicyRef | None
    risk_policy: PolicyRef
    pessimistic_risk_policy: PolicyRef
    limits: H3Limits
    cost_model: CostModelV2
    initial_equity: Decimal
    quote_ccy: str
    datasets: tuple[ProvenanceV2, ...]
    trial_ledger_head: str | None
    code_digest: str
    created_ms: int

    def _validate(self) -> None:
        check_id(self.experiment_id)
        check_id(self.quote_ccy)
        check_sha(self.code_digest)
        _ms(self.created_ms, 'h3_experiment_time_invalid')
        for value in (self.statistical_plan_fingerprint, self.trial_ledger_head):
            if value is not None:
                check_sha(value)
        if self.variants not in DESIGNS:
            raise ContractError('h3_experiment_design_invalid')
        if (VariantV2.BR in self.variants) != (self.random_control is not None):
            raise ContractError('h3_experiment_random_control_mismatch')
        if (VariantV2.B2 in self.variants) != (self.adaptive_policy is not None):
            raise ContractError('h3_experiment_adaptive_policy_mismatch')
        if self.adaptive_policy is not None and self.adaptive_policy == self.static_policy:
            raise ContractError('h3_experiment_policies_identical')
        if self.risk_policy == self.pessimistic_risk_policy:
            raise ContractError('h3_experiment_pessimistic_policy_not_distinct')
        if self.limits != H3_LIMITS:
            raise ContractError('h3_experiment_limits_not_h3')
        if self.initial_equity != self.limits.reference_equity:
            raise ContractError('h3_experiment_equity_not_h3')
        seeds = self.reference_lottery_seeds
        if not seeds or any(isinstance(s, bool) or s < 0 for s in seeds):
            raise ContractError('h3_experiment_seeds_invalid')
        _sorted_unique(seeds, 'h3_experiment_seeds_not_sorted_unique')
        if not self.datasets:
            raise ContractError('h3_experiment_dataset_required')
        _sorted_unique([p.dataset_id for p in self.datasets], 'h3_experiment_datasets_not_sorted_unique')
        real = any(p.label is not DataLabel.SYNTHETIC for p in self.datasets)
        if real and self.protocol.status is not ProtocolStatus.FROZEN:
            raise ContractError('h3_experiment_real_data_needs_frozen_protocol')
        if self.protocol.status is ProtocolStatus.FROZEN and (self.statistical_plan_fingerprint is None
                                                              or self.trial_ledger_head is None):
            raise ContractError('h3_experiment_frozen_needs_plan_and_ledger')
        for i, a in enumerate(self.datasets):
            for b in self.datasets[i + 1:]:
                if a.label is not b.label and a.overlaps(b):
                    raise ContractError('h3_experiment_dataset_overlap')
        model = self.scoring_model
        if model.calibration is CalibrationStatus.CALIBRATED:
            if self.label_spec is None:
                raise ContractError('h3_experiment_calibrated_model_needs_label_spec')
            assert model.available_ms is not None
            if model.available_ms > self.created_ms:
                raise ContractError('h3_experiment_model_after_manifest')

    def dataset_for(self, provenance: Provenance) -> ProvenanceV2 | None:
        """The V2 dataset a V1 header provenance refers to, or None (a header outside the manifest's data)."""
        return next((p for p in self.datasets if p.matches_v1(provenance)), None)


def check_model_for_experiment(manifest: ExperimentManifestV2, model: ModelManifestV2) -> tuple[str, ...]:
    """Reasons the model cannot score this experiment (empty: compatible). Training data may not overlap, in
    (underlying x time), any non-development dataset of the experiment (R1-F7)."""
    reasons = []
    if model.ref() != manifest.scoring_model:
        reasons.append('model_not_the_manifest_model')
    if model.catalogue != manifest.catalogue:
        reasons.append('model_catalogue_mismatch')
    if model.label_spec != manifest.label_spec:
        reasons.append('model_label_spec_mismatch')
    for trained in model.training_provenance:
        for evaluated in manifest.datasets:
            if evaluated.label not in (DataLabel.SYNTHETIC, DataLabel.DEVELOPMENT) and trained.overlaps(evaluated):
                reasons.append('model_training_overlaps_evaluation_data')
    return tuple(dict.fromkeys(reasons))


# --------------------------------------------------------------------------------------------- run references

@dataclass(frozen=True)
class VariantRunRef(Contract):
    """Identity of one variant run, enough to check a B0/BR/B1 (or factorial) comparison without its trades.

    ``seed`` is the lottery seed of B0/B2 or the draw seed of BR (None for B1/B3: their order is the score rank);
    ``activity_budget_fingerprint`` is set for BR only. ``run_fingerprint`` hashes the run's full record.
    """

    SCHEMA: ClassVar[str] = 'h3.variant_run_ref/2'
    experiment_id: str
    manifest_fingerprint: str
    variant: VariantV2
    portfolio_id: str
    cost_scenario: CostScenarioV2
    seed: int | None
    candidate_book_fingerprint: str
    market_fingerprint: str
    risk_policy: PolicyRef
    activity_budget_fingerprint: str | None
    run_fingerprint: str

    def _validate(self) -> None:
        check_id(self.experiment_id)
        check_id(self.portfolio_id)
        for value in (self.manifest_fingerprint, self.candidate_book_fingerprint, self.market_fingerprint,
                      self.run_fingerprint):
            check_sha(value)
        if (self.variant in SEEDED_V2) != (self.seed is not None):
            raise ContractError('h3_run_seed_mismatch')
        if self.seed is not None and (isinstance(self.seed, bool) or self.seed < 0):
            raise ContractError('h3_run_seed_mismatch')
        if (self.variant is VariantV2.BR) != (self.activity_budget_fingerprint is not None):
            raise ContractError('h3_run_budget_mismatch')
        if self.activity_budget_fingerprint is not None:
            check_sha(self.activity_budget_fingerprint)


def comparison_readiness(manifest: ExperimentManifestV2, runs: Sequence[VariantRunRef],
                         budget: ActivityBudget | None) -> tuple[str, ...]:
    """Reasons the runs do not form the pre-registered controlled comparison (empty: comparable).

    Per cost scenario present, every declared variant must be there, B0/B2 once per lottery seed, BR once per
    control seed (all seeds, no more, no fewer: no seed can be picked), B1/B3 once; every run shares the same
    candidate book and market and the risk policy the manifest anchors for its scenario; BR's budget is the given
    B1 budget.
    """
    reasons: list[str] = []
    fingerprint = manifest.fingerprint()
    if not runs:
        return ('runs_missing',)
    if any(r.manifest_fingerprint != fingerprint or r.experiment_id != manifest.experiment_id for r in runs):
        reasons.append('run_not_from_manifest')
    if len({r.candidate_book_fingerprint for r in runs}) != 1:
        reasons.append('candidate_book_not_shared')
    if len({r.market_fingerprint for r in runs}) != 1:
        reasons.append('market_not_shared')
    if len({(r.cost_scenario, r.portfolio_id) for r in runs}) != len(runs):
        reasons.append('portfolio_not_independent')
    expected_policy = {CostScenarioV2.BASE: manifest.risk_policy,
                       CostScenarioV2.PESSIMISTIC: manifest.pessimistic_risk_policy}
    if any(r.risk_policy != expected_policy[r.cost_scenario] for r in runs):
        reasons.append('risk_policy_not_anchored')
    control_seeds = manifest.random_control.seeds if manifest.random_control is not None else ()
    for scenario in sorted({r.cost_scenario for r in runs}, key=lambda s: s.value):
        scoped = [r for r in runs if r.cost_scenario is scenario]
        if {r.variant for r in scoped} != set(manifest.variants):
            reasons.append('variant_set_mismatch')
        for variant in manifest.variants:
            seeds = sorted(r.seed for r in scoped if r.variant is variant and r.seed is not None)
            count = sum(1 for r in scoped if r.variant is variant)
            if variant is VariantV2.BR:
                if seeds != list(control_seeds):
                    reasons.append('control_seeds_not_exactly_preregistered')
            elif variant in SEEDED_V2:
                if seeds != list(manifest.reference_lottery_seeds):
                    reasons.append('lottery_seeds_not_exactly_preregistered')
            elif count != 1:
                reasons.append('scored_variant_run_count_invalid')
    br_runs = [r for r in runs if r.variant is VariantV2.BR]
    if br_runs:
        if budget is None:
            reasons.append('activity_budget_missing')
        elif any(r.activity_budget_fingerprint != budget.fingerprint() for r in br_runs):
            reasons.append('activity_budget_mismatch')
    return tuple(dict.fromkeys(reasons))


# --------------------------------------------------------------------------------------------------------- audit

@dataclass(frozen=True)
class DecisionAuditV2(Contract):
    """V1 audit record whose ``variant`` can be BR and whose model reference carries calibration. Without it a BR
    journal would have to borrow a V1 variant label; the hash chain per (experiment, variant) is unchanged."""

    SCHEMA: ClassVar[str] = 'h3.decision_audit/2'
    header: Header
    audit_id: str
    sequence: int
    experiment_id: str
    variant: VariantV2 | None
    event: AuditEvent
    subject_schema: str
    subject_id: str
    subject_fingerprint: str
    previous_audit_fingerprint: str | None
    model: ModelRefV2 | None
    policy: PolicyRef | None
    reasons: tuple[str, ...]

    def _validate(self) -> None:
        for value in (self.audit_id, self.experiment_id, self.subject_schema, self.subject_id):
            check_id(value)
        check_sha(self.subject_fingerprint)
        check_reasons(self.reasons)
        if isinstance(self.sequence, bool) or self.sequence < 0:
            raise ContractError('h3_audit_sequence_invalid')
        if (self.sequence == 0) != (self.previous_audit_fingerprint is None):
            raise ContractError('h3_audit_chain_invalid')
        if self.previous_audit_fingerprint is not None:
            check_sha(self.previous_audit_fingerprint)


CONTRACTS_V2: tuple[type[Contract], ...] = (
    ProvenanceV2, GeneratorRef, FeatureCatalogueRef, LabelSpecRef, ModelRefV2, FeatureSnapshotV2,
    OpportunityCandidateV2, OpportunityScoreV2, LabelOutcome, RandomControlSpec, StratumCount, ActivityBudget,
    CostModelV2, ModelManifestV2, ExperimentManifestV2, VariantRunRef, DecisionAuditV2,
)
