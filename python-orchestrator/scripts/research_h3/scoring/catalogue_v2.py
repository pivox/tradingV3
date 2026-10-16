"""Feature catalogue V2: a STRUCTURE, not a frozen feature list (H3-A testability, agent C).

The V1 catalogue ``h3-scoring-features`` 0.1.0 (``scoring.features.CATALOGUE``) stays unchanged and is what the V1
engine and its golden vectors use. V2 adds what V1 cannot express and the reviews asked for (R1-F8, H3-DECISION
step 1): a catalogue must be versioned to the candidate source it serves, each predictive feature must carry an M4
justification (a mechanism, the closed families it relates to and a decision about them), its provenance and
availability must be explicit, and every change from the parent catalogue must be recorded, never silent.

No candidate source is qualified today (H3_BLOCKED_DATA_OR_HYPOTHESIS). Therefore ``draft_from_v1`` only maps the V1
features into the V2 structure, UNBOUND and DRAFT, with every predictive M4 decision PENDING; ``freeze_reasons``
refuses to freeze it until a source is bound, each predictive feature has a NO_OVERLAP or JUSTIFIED decision, and its
label-free independence checks passed. Nothing here selects, drops or adds a feature on performance.

Research prototype: no data is read.
"""
from __future__ import annotations

import enum
from collections.abc import Sequence
from dataclasses import dataclass
from typing import Any

from ..contracts import canonical_json, sha256_text
from .features import CATALOGUE, FeatureSpec, FeatureSpecSet

CATALOGUE_V2_SCHEMA = 'h3.feature_catalogue/2'
#: Words that mark an input derived from outcomes or execution: never allowed in a feature available at T.
OUTCOME_INPUTS = ('label', 'outcome', 'fill', 'trade', 'exit', 'realised', 'realized', 'pnl')


class RoleV2(str, enum.Enum):
    PREDICTIVE = 'predictive'
    COST = 'cost'
    QUALITY = 'quality'
    RISK_GATE = 'risk_gate'
    SOURCE_TRIGGER = 'source_trigger'   # the variable the candidate generator itself thresholds: never a score input


class Decision(str, enum.Enum):
    """Decision about the closed families a predictive feature relates to (gate M4)."""

    NO_OVERLAP = 'NO_OVERLAP'           # reviewed: unrelated to any closed family
    JUSTIFIED = 'JUSTIFIED'             # related, kept with a written argument and passed independence checks
    EXCLUDED = 'EXCLUDED'               # related and removed from the predictive role
    PENDING = 'PENDING'                 # not decided (default until a source is bound)


class Lifecycle(str, enum.Enum):
    DRAFT = 'DRAFT'
    PROPOSED = 'PROPOSED'
    FROZEN = 'FROZEN'


class CheckResult(str, enum.Enum):
    PENDING = 'PENDING'
    PASS = 'PASS'
    FAIL = 'FAIL'


@dataclass(frozen=True)
class IndependenceCheck:
    """A check that the feature does not reintroduce a closed mechanism. ``kind`` 'label_free' checks read features
    and decisions only (never outcomes); 'confirmatory' checks are pre-registered outcome tests, run once."""

    name: str
    kind: str                     # 'label_free' | 'mechanism_review' | 'confirmatory'
    against: str                  # the closed signal or family it is compared with
    result: CheckResult = CheckResult.PENDING
    evidence_ref: str | None = None


@dataclass(frozen=True)
class M4Justification:
    mechanism: str
    closed_families: tuple[str, ...]
    decision: Decision
    rationale: str
    checks: tuple[IndependenceCheck, ...] = ()


@dataclass(frozen=True)
class Availability:
    as_of_rule: str
    max_age_ms: int | None
    uses_post_decision_data: bool = False


@dataclass(frozen=True)
class ProvenanceRequirement:
    data_kind: str
    point_in_time: bool
    licence_status: str = 'UNKNOWN'   # 'UNKNOWN' | 'OK' | 'BLOCKED'; established by the source qualification


@dataclass(frozen=True)
class FeatureDefinitionV2:
    name: str
    role: RoleV2
    unit: str
    directional: bool
    formula: str
    inputs: tuple[str, ...]
    availability: Availability
    provenance: ProvenanceRequirement
    source_binding: str | None        # None: source-agnostic; else the candidate-source id the feature serves
    portfolio_dependent: bool
    leakage_risks: str
    m4: M4Justification | None = None
    lifecycle: Lifecycle = Lifecycle.DRAFT

    def to_document(self) -> dict[str, Any]:
        return {
            'name': self.name, 'role': self.role.value, 'unit': self.unit, 'directional': self.directional,
            'formula': self.formula, 'inputs': list(self.inputs),
            'availability': {'as_of_rule': self.availability.as_of_rule, 'max_age_ms': self.availability.max_age_ms,
                             'uses_post_decision_data': self.availability.uses_post_decision_data},
            'provenance': {'data_kind': self.provenance.data_kind, 'point_in_time': self.provenance.point_in_time,
                           'licence_status': self.provenance.licence_status},
            'source_binding': self.source_binding, 'portfolio_dependent': self.portfolio_dependent,
            'leakage_risks': self.leakage_risks, 'lifecycle': self.lifecycle.value,
            'm4': None if self.m4 is None else {
                'mechanism': self.m4.mechanism, 'closed_families': list(self.m4.closed_families),
                'decision': self.m4.decision.value, 'rationale': self.m4.rationale,
                'checks': [{'name': c.name, 'kind': c.kind, 'against': c.against, 'result': c.result.value,
                            'evidence_ref': c.evidence_ref} for c in self.m4.checks]}}


@dataclass(frozen=True)
class ChangeEntry:
    feature: str
    change: str                       # 'added' | 'removed' | 'modified' | 'role_changed'
    reason: str


@dataclass(frozen=True)
class CatalogueV2:
    spec_id: str
    spec_version: str
    parent_spec_id: str | None
    parent_spec_version: str | None
    parent_fingerprint: str | None
    source_binding: str | None
    features: tuple[FeatureDefinitionV2, ...]
    changes: tuple[ChangeEntry, ...]
    lifecycle: Lifecycle = Lifecycle.DRAFT
    schema: str = CATALOGUE_V2_SCHEMA

    def get(self, name: str) -> FeatureDefinitionV2 | None:
        return next((f for f in self.features if f.name == name), None)

    def to_document(self) -> dict[str, Any]:
        return {'schema': self.schema, 'spec_id': self.spec_id, 'spec_version': self.spec_version,
                'parent': {'spec_id': self.parent_spec_id, 'spec_version': self.parent_spec_version,
                           'fingerprint': self.parent_fingerprint},
                'source_binding': self.source_binding, 'lifecycle': self.lifecycle.value,
                'features': [f.to_document() for f in self.features],
                'changes': [{'feature': c.feature, 'change': c.change, 'reason': c.reason} for c in self.changes]}

    def fingerprint(self) -> str:
        return sha256_text(canonical_json(self.to_document()))


# ------------------------------------------------------------------------------------------------ from V1

#: Closed families (gate M4, H3-DECISION, the strategy landscape) that V1 features relate to. A listed family does not
#: forbid the feature; it forces a decision (EXCLUDED, or JUSTIFIED with passed independence checks).
_V1_CLOSED_FAMILIES: dict[str, tuple[str, ...]] = {
    'xs_rel_ret_lb': ('cross_sectional_momentum (CS-XMOM, abandoned)',
                      'residual_return_reversal (H2, closed 2026-10-15)'),
    'mkt_ret_lb': ('market_momentum_regime_filter (regime-r2 D2)',),
}
_V1_CHECKS: dict[str, tuple[IndependenceCheck, ...]] = {
    'xs_rel_ret_lb': (
        IndependenceCheck('rank_correlation_with_closed_signal', 'label_free',
                          'CS-XMOM formation signal (relative return, 14 d) and the H2 residual (3 d)'),
        IndependenceCheck('accepted_set_tilt', 'label_free',
                          'share of B1-accepted candidates in the top / bottom tercile of xs_rel_ret_lb by side '
                          '(a long-winners / short-losers tilt = disguised XMOM)'),
        IndependenceCheck('nested_ablation', 'confirmatory',
                          'B1 with vs without xs_rel_ret_lb, pre-registered secondary, run once'),
    ),
    'mkt_ret_lb': (
        IndependenceCheck('rank_correlation_with_closed_signal', 'label_free',
                          'regime-r2 market momentum filter (4 / 12 weeks)'),
    ),
}
_ROLE_MAP = {'predictive': RoleV2.PREDICTIVE, 'cost': RoleV2.COST, 'quality': RoleV2.QUALITY,
             'risk_gate': RoleV2.RISK_GATE}


def _from_v1(spec: FeatureSpec) -> FeatureDefinitionV2:
    role = _ROLE_MAP[spec.role.value]
    m4 = None
    if role is RoleV2.PREDICTIVE:
        m4 = M4Justification(mechanism=spec.rationale, closed_families=_V1_CLOSED_FAMILIES.get(spec.name, ()),
                             decision=Decision.PENDING,
                             rationale='Not decided: no candidate source is qualified; the decision depends on '
                                       'the mechanism of the bound source.',
                             checks=_V1_CHECKS.get(spec.name, ()))
    return FeatureDefinitionV2(
        name=spec.name, role=role, unit=spec.unit, directional=spec.directional, formula=spec.formula,
        inputs=spec.inputs, availability=Availability(spec.availability, spec.max_age_ms),
        provenance=ProvenanceRequirement(spec.provenance, point_in_time=True),
        source_binding=None, portfolio_dependent=spec.source.value == 'portfolio_bars',
        leakage_risks=spec.leakage_risks, m4=m4)


def draft_from_v1(parent: FeatureSpecSet = CATALOGUE, *, spec_version: str = '2.0.0-draft') -> CatalogueV2:
    """The V1 catalogue mapped, unchanged, into the V2 structure: unbound, DRAFT, every predictive decision PENDING.
    It records no change (none was made); a bound catalogue derived from it must record each change."""
    return CatalogueV2(spec_id=parent.spec_id, spec_version=spec_version, parent_spec_id=parent.spec_id,
                       parent_spec_version=parent.spec_version, parent_fingerprint=parent.fingerprint(),
                       source_binding=None, features=tuple(_from_v1(s) for s in parent.specs), changes=())


# ---------------------------------------------------------------------------------------------- validation

def _modified(v2: FeatureDefinitionV2, v1: FeatureSpec) -> bool:
    return (v2.unit, v2.directional, v2.formula, tuple(v2.inputs), v2.availability.max_age_ms,
            v2.role.value) != (v1.unit, v1.directional, v1.formula, tuple(v1.inputs), v1.max_age_ms, v1.role.value)


def validate(catalogue: CatalogueV2, parent: FeatureSpecSet | None = CATALOGUE) -> tuple[str, ...]:
    """Structural reasons why the catalogue is not acceptable even as a draft (empty tuple: acceptable)."""
    out: list[str] = []
    names = [f.name for f in catalogue.features]
    if len(set(names)) != len(names):
        out.append('catalogue_v2_duplicate_feature')
    for f in catalogue.features:
        if f.availability.uses_post_decision_data:
            out.append(f'feature_uses_post_decision_data:{f.name}')
        if any(word in i.lower() for i in f.inputs for word in OUTCOME_INPUTS):
            out.append(f'feature_input_outcome_derived:{f.name}')
        if f.role is RoleV2.PREDICTIVE:
            if f.portfolio_dependent:
                out.append(f'predictive_feature_portfolio_dependent:{f.name}')
            if f.m4 is None:
                out.append(f'm4_justification_missing:{f.name}')
            elif f.m4.closed_families and f.m4.decision is Decision.NO_OVERLAP:
                out.append(f'closed_family_overlap_contradiction:{f.name}')
            elif f.m4.decision is Decision.EXCLUDED:
                out.append(f'excluded_feature_still_predictive:{f.name}')
        if catalogue.source_binding is not None and f.source_binding not in (None, catalogue.source_binding):
            out.append(f'feature_source_binding_mismatch:{f.name}')
    if parent is not None and catalogue.parent_spec_id is not None:
        if (catalogue.parent_spec_id, catalogue.parent_spec_version, catalogue.parent_fingerprint) != (
                parent.spec_id, parent.spec_version, parent.fingerprint()):
            out.append('catalogue_v2_parent_mismatch')
        if catalogue.spec_version == parent.spec_version:
            out.append('catalogue_version_not_bumped')
        recorded = {c.feature for c in catalogue.changes}
        for spec in parent.specs:
            current = catalogue.get(spec.name)
            if current is None and spec.name not in recorded:
                out.append(f'unversioned_change:removed:{spec.name}')
            elif current is not None and _modified(current, spec) and spec.name not in recorded:
                out.append(f'unversioned_change:modified:{spec.name}')
        for f in catalogue.features:
            if parent.get(f.name) is None and f.name not in recorded:
                out.append(f'unversioned_change:added:{f.name}')
    return tuple(out)


def freeze_reasons(catalogue: CatalogueV2, parent: FeatureSpecSet | None = CATALOGUE) -> tuple[str, ...]:
    """Reasons why the catalogue may NOT be frozen (empty tuple: it may)."""
    out = list(validate(catalogue, parent))
    if catalogue.source_binding is None:
        out.append('catalogue_not_bound_to_source')
    for f in catalogue.features:
        if f.provenance.licence_status != 'OK':
            out.append(f'provenance_licence_not_established:{f.name}')
        if f.role is RoleV2.PREDICTIVE and f.m4 is not None:
            if f.m4.decision is Decision.PENDING:
                out.append(f'm4_decision_pending:{f.name}')
            if f.m4.decision is Decision.JUSTIFIED and not f.m4.checks:
                out.append(f'justified_without_independence_checks:{f.name}')
            for check in f.m4.checks:
                if check.kind != 'confirmatory' and check.result is not CheckResult.PASS:
                    out.append(f'independence_check_not_passed:{f.name}:{check.name}')
    return tuple(dict.fromkeys(out))


def predictive_names(catalogue: CatalogueV2) -> tuple[str, ...]:
    return tuple(f.name for f in catalogue.features if f.role is RoleV2.PREDICTIVE)


def with_changes(catalogue: CatalogueV2, *, spec_version: str, source_binding: str | None,
                 features: Sequence[FeatureDefinitionV2], changes: Sequence[ChangeEntry]) -> CatalogueV2:
    """A new version derived from ``catalogue``'s parent; the caller records every change (``validate`` checks)."""
    return CatalogueV2(spec_id=catalogue.spec_id, spec_version=spec_version,
                       parent_spec_id=catalogue.parent_spec_id, parent_spec_version=catalogue.parent_spec_version,
                       parent_fingerprint=catalogue.parent_fingerprint, source_binding=source_binding,
                       features=tuple(features), changes=tuple(changes))
