"""The DRAFT statistical plan of the H3 B0/B1/B2/B3 experiment (docs/handbook/research/H3-PREREGISTRATION-DRAFT.md).

One primary criterion, fixed before any read:
- PRIMARY = intersection-union of two one-sided tests on the per-block portfolio net PnL (fraction of the initial
  capital, blocks of ``block_ms``, circular block bootstrap):
  P1 ``global_vs_reference``: mean(B3 - B0) > 0, paired by block (same candidates, same market, same block);
  P2 ``absolute_vs_cash``: mean(B3) > 0 (B3 against keeping the capital, the no-trading comparator).
  Both must reject at ``alpha_primary``. By the intersection-union principle (Berger 1982) the composite decision has
  level ``alpha_primary`` without adjustment; it asks for an improvement over the reference AND a net gain.
- SECONDARY (confirmatory only if the primary is met: serial gatekeeping), one family under Holm at
  ``alpha_secondary``: S1 scoring (B1 - B0), S2 adaptation (B2 - B0), S3 adaptation after scoring (B3 - B1), S4
  scoring with adaptation (B3 - B2). H3-A is supported by S1, H3-B by S2, H3-C (the combination adds to each engine
  alone) by S3 and S4 together.
- DESCRIPTIVE (never a claim): the interaction contrast (B3 - B2) - (B1 - B0), each variant against cash, B3 against
  a passive exposure when one is meaningful.
- GUARDS (validity conditions; they can only turn a met criterion into a not-met one, so they add no false
  positive): pessimistic costs, temporal stability, concentration, extreme losses, minimum number of blocks.

Every numeric value below is DRAFT: an a-priori choice with its reason in the pre-registration, to be arbitrated
and frozen by the owner before any new performance is read. None was chosen from a known performance.

Test procedure. The synthetic power studies (pre-registration section 8) rejected the percentile circular block
bootstrap (study 1) and found no replacement valid in every synthetic scenario (study 2). ``procedure_validated`` is
therefore False, and a FROZEN plan cannot be built until the owner chooses a procedure and a new synthetic rule
validates it.
"""
from __future__ import annotations

import enum
from dataclasses import dataclass, field

from ..contracts import ProtocolStatus, Variant, canonical_json, sha256_text
from .stats import PROCEDURES

DAY_MS = 86_400_000
WEEK_MS = 7 * DAY_MS


class Role(str, enum.Enum):
    PRIMARY = 'primary'
    SECONDARY = 'secondary'
    DESCRIPTIVE = 'descriptive'


@dataclass(frozen=True)
class Comparison:
    """``treatment`` minus ``reference`` per block; ``reference`` None means the no-trading (cash) comparator."""

    comparison_id: str
    treatment: Variant
    reference: Variant | None
    role: Role
    question: str


PRIMARY_COMPONENTS: tuple[Comparison, ...] = (
    Comparison('global_vs_reference', Variant.B3, Variant.B0, Role.PRIMARY,
               'scoring and adaptive management together improve the net PnL of the reference portfolio'),
    Comparison('absolute_vs_cash', Variant.B3, None, Role.PRIMARY,
               'the scored and adaptively managed portfolio gains net of every cost against keeping the capital'),
)

SECONDARY_FAMILY: tuple[Comparison, ...] = (
    Comparison('scoring', Variant.B1, Variant.B0, Role.SECONDARY, 'H3-A: scoring alone, static exits'),
    Comparison('adaptation', Variant.B2, Variant.B0, Role.SECONDARY, 'H3-B: adaptive exits alone, reference entries'),
    Comparison('adaptation_after_scoring', Variant.B3, Variant.B1, Role.SECONDARY,
               'H3-C part 1: adaptive exits add value once entries are scored'),
    Comparison('scoring_with_adaptation', Variant.B3, Variant.B2, Role.SECONDARY,
               'H3-C part 2: scoring adds value once exits are adaptive'),
)

DESCRIPTIVE_VS_CASH: tuple[Comparison, ...] = tuple(
    Comparison(f'{v.value.lower()}_vs_cash', v, None, Role.DESCRIPTIVE, f'{v.value} against keeping the capital')
    for v in (Variant.B0, Variant.B1, Variant.B2))


@dataclass(frozen=True)
class Guards:
    """DRAFT validity conditions (see the pre-registration for each a-priori reason)."""

    # Pessimistic costs: under the pessimistic replay, mean(B3 - B0) > 0 and B3 net > 0 (point estimates).
    pessimistic_point_estimates_positive: bool = True
    # Temporal stability: mean(B3 - B0) > 0 in at least this many of ``stability_periods`` chronological periods.
    stability_periods: int = 4
    stability_min_positive_periods: int = 3
    # Concentration: B3 - B0 stays > 0 without the instrument contributing most to it, and B3 net stays > 0 without
    # its top ``top_trades_share`` of trades by net PnL.
    top_trades_share: float = 0.05
    # Extreme losses: expected shortfall of B3 trade R at ``tail_quantile`` not worse than B0's by more than
    # ``tail_es_tolerance_r``; no B3 trade below ``worst_trade_floor_r``.
    tail_quantile: float = 0.05
    tail_es_tolerance_r: float = 0.25
    worst_trade_floor_r: float = -2.0
    # Minimum information: blocks and effective blocks of the primary difference series, trades of B3.
    min_blocks: int = 26
    min_effective_blocks: float = 13.0
    min_trades_b3: int = 100


@dataclass(frozen=True)
class StatisticalPlan:
    status: ProtocolStatus = ProtocolStatus.DRAFT
    # Placeholder, NOT validated: study 1 rejected 'cbb_percentile'; study 2 found no candidate valid in every
    # synthetic scenario (NO_VALID_CANDIDATE). 'sn_gaussian' was the tie-break preference declared before study 2.
    test_procedure: str = 'sn_gaussian'
    procedure_validated: bool = False
    block_ms: int = WEEK_MS
    alpha_primary: float = 0.05
    alpha_secondary: float = 0.05
    resamples: int = 9_999
    block_len: int | None = None          # None: ``stats.block_length_rule`` (round(n ** (1/3)))
    seed: int = 20_261_015
    guards: Guards = field(default_factory=Guards)

    def __post_init__(self) -> None:
        if self.test_procedure not in PROCEDURES:
            raise ValueError('plan_test_procedure_unknown')
        if self.status is ProtocolStatus.FROZEN and not self.procedure_validated:
            raise ValueError('plan_procedure_not_validated_freeze_blocked')

    def to_document(self) -> dict:
        document = {k: v for k, v in self.__dict__.items() if k != 'guards'}
        document['status'] = self.status.value
        document['guards'] = dict(self.guards.__dict__)
        document['primary'] = [c.comparison_id for c in PRIMARY_COMPONENTS]
        document['secondary'] = [c.comparison_id for c in SECONDARY_FAMILY]
        return document

    def fingerprint(self) -> str:
        return sha256_text(canonical_json(self.to_document()))


DRAFT_PLAN = StatisticalPlan()
