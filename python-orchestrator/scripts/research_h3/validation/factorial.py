"""Factorial harness: runs B0, B1, B2 and B3 from injected components and proves that they share their inputs and
that their portfolios are independent (C1).

By construction:
- the common generator (``contracts.CandidateProvider``) is called exactly once per decision instant, before any
  variant runs, producing one frozen ``CandidateBook``; no score exists at that point;
- the scorer is called exactly once per candidate; B1 and B3 receive the same ranked scores;
- one frozen ``MarketStream`` (bars, funding, quotes) is handed, as the same object, to the four simulator runs;
- each variant gets a fresh simulator and a fresh risk gate from their factories (the same instance twice is
  refused), its own portfolio id, the same capital, cost model, cost scenario, limits and period;
- B0 and B2 receive the same reference entries: every candidate, in a fixed order that depends only on the
  experiment seed and the candidate id (a deterministic lottery, independent of scores and features);
- B1 and B3 receive the scored entries: TRADE_CANDIDATE scores in the scorer's rank order; NO_TRADE candidates are
  refusals at the ``score`` stage.

By check, after every variant run: the run reports the same candidate book and market fingerprints, the same
candidate ids, its own variant and portfolio id; the book and market fingerprints recomputed from the shared objects
are unchanged (no variant mutated a shared input). The test suite adds the behavioural proofs: running the variants
in another order gives byte-identical runs, and changing one variant's component changes no other variant.

``VariantSimulator`` is the small protocol a bar-replay simulator implements to be driven by this harness. It must:
apply ``spec.cost_model`` with every cost component (fees, spread, slippage, adverse funding) multiplied by
``cost_model.pessimistic_multiplier`` when ``spec.cost_scenario`` is PESSIMISTIC; call ``spec.risk_gate`` for every
entry and every non-HOLD action; call ``spec.policy.decide`` on observable ``PositionState`` values only; close every
position by ``spec.end_ms``; return a ``records.VariantRun`` echoing the spec's identity fields.
"""
from __future__ import annotations

import hashlib
from collections.abc import Callable, Sequence
from dataclasses import dataclass, field
from decimal import Decimal
from typing import Any, Protocol

from ..contracts import (
    H3_LIMITS, Bar, CandidateProvider, CostModel, ExperimentManifest, FeatureSnapshot, FundingObservation, H3Limits,
    ManagementPolicy, ModelManifest, OpportunityCandidate, OpportunityScore, OpportunityScorer, Quote, RiskGate,
    ScoreVerdict, Variant, canonical_json, sha256_text,
)
from .records import CostScenario, RefusalRecord, RefusalStage, VariantRun

FACTORIAL = (Variant.B0, Variant.B1, Variant.B2, Variant.B3)
SCORED = (Variant.B1, Variant.B3)
ADAPTIVE = (Variant.B2, Variant.B3)


class FactorialError(ValueError):
    """The factorial design would be broken. The message is a stable reason code."""


def _digest(parts: Sequence[str]) -> str:
    return sha256_text(canonical_json(list(parts)))


# --------------------------------------------------------------------------------------------- shared inputs

@dataclass(frozen=True)
class MarketStream:
    """The one market stream all variants replay. Sorted, duplicate-free, fingerprinted at construction."""

    bars: tuple[Bar, ...]
    funding: tuple[FundingObservation, ...] = ()
    quotes: tuple[Quote, ...] = ()
    fingerprint_at_construction: str = field(default='', compare=False)

    def __post_init__(self) -> None:
        bars, funding, quotes = tuple(self.bars), tuple(self.funding), tuple(self.quotes)
        object.__setattr__(self, 'bars', bars)
        object.__setattr__(self, 'funding', funding)
        object.__setattr__(self, 'quotes', quotes)
        for items, key in ((bars, lambda b: (b.open_ms, b.inst_id, b.interval_ms)),
                           (funding, lambda f: (f.funding_ms, f.inst_id)),
                           (quotes, lambda q: (q.observed_ms, q.inst_id))):
            keys = [key(x) for x in items]
            if keys != sorted(keys) or len(set(keys)) != len(keys):
                raise FactorialError('factorial_market_not_sorted_unique')
        object.__setattr__(self, 'fingerprint_at_construction', self.fingerprint())

    def fingerprint(self) -> str:
        return _digest(['bars'] + [b.fingerprint() for b in self.bars] + ['funding']
                       + [f.fingerprint() for f in self.funding] + ['quotes'] + [q.fingerprint() for q in self.quotes])

    def bars_visible_at(self, decision_ms: int, inst_id: str | None = None) -> tuple[Bar, ...]:
        """Closed bars already available at ``decision_ms`` (the anti-lookahead view for decisions)."""
        return tuple(b for b in self.bars if b.usable_at(decision_ms) and (inst_id is None or b.inst_id == inst_id))

    def funding_visible_at(self, decision_ms: int, inst_id: str | None = None) -> tuple[FundingObservation, ...]:
        return tuple(f for f in self.funding
                     if f.available_ms <= decision_ms and (inst_id is None or f.inst_id == inst_id))

    def quotes_visible_at(self, decision_ms: int, inst_id: str | None = None) -> tuple[Quote, ...]:
        return tuple(q for q in self.quotes
                     if q.available_ms <= decision_ms and (inst_id is None or q.inst_id == inst_id))


@dataclass(frozen=True)
class DecisionInput:
    """Feature snapshots observable at one decision instant (side-agnostic or not), given to the generator."""

    decision_ms: int
    snapshots: tuple[FeatureSnapshot, ...]


@dataclass(frozen=True)
class CandidateBook:
    """All candidates of the experiment, produced once by the common generator, sorted by (decision, id)."""

    generator_id: str
    generator_version: str
    hypothesis_id: str
    candidates: tuple[OpportunityCandidate, ...]
    snapshots: tuple[FeatureSnapshot, ...]

    def __post_init__(self) -> None:
        keys = [(c.header.clock.decision_ms, c.candidate_id) for c in self.candidates]
        ids = [c.candidate_id for c in self.candidates]
        if keys != sorted(keys) or len(set(ids)) != len(ids):
            raise FactorialError('factorial_book_not_sorted_unique')
        snapshot_refs = {s.snapshot_id: s.ref() for s in self.snapshots}
        if len(snapshot_refs) != len(self.snapshots):
            raise FactorialError('factorial_book_snapshot_duplicate')
        for c in self.candidates:
            if (c.generator_id, c.generator_version, c.hypothesis_id) != (
                    self.generator_id, self.generator_version, self.hypothesis_id):
                raise FactorialError('factorial_book_mixed_generators')
            if snapshot_refs.get(c.snapshot.snapshot_id) != c.snapshot:
                raise FactorialError('factorial_book_snapshot_missing')

    @property
    def ids(self) -> tuple[str, ...]:
        return tuple(sorted(c.candidate_id for c in self.candidates))

    def snapshot_for(self, candidate: OpportunityCandidate) -> FeatureSnapshot:
        return next(s for s in self.snapshots if s.snapshot_id == candidate.snapshot.snapshot_id)

    def by_decision(self) -> list[tuple[int, tuple[OpportunityCandidate, ...]]]:
        groups: dict[int, list[OpportunityCandidate]] = {}
        for c in self.candidates:
            groups.setdefault(c.header.clock.decision_ms, []).append(c)
        return [(ms, tuple(g)) for ms, g in sorted(groups.items())]

    def fingerprint(self) -> str:
        return _digest([self.generator_id, self.generator_version, self.hypothesis_id, 'candidates']
                       + [c.fingerprint() for c in self.candidates] + ['snapshots']
                       + [s.fingerprint() for s in self.snapshots])


def build_candidate_book(provider: CandidateProvider, decisions: Sequence[DecisionInput], *, start_ms: int,
                         end_ms: int, universe: Callable[[int], Sequence[str]] | None = None) -> CandidateBook:
    """Call the common generator once per decision instant, before any variant and without any score."""
    instants = [d.decision_ms for d in decisions]
    if instants != sorted(set(instants)):
        raise FactorialError('factorial_decisions_not_increasing')
    candidates: list[OpportunityCandidate] = []
    snapshots: dict[str, FeatureSnapshot] = {}
    for decision in decisions:
        if not start_ms <= decision.decision_ms < end_ms:
            raise FactorialError('factorial_decision_outside_period')
        refs = {}
        for snapshot in decision.snapshots:
            if snapshot.header.clock.decision_ms > decision.decision_ms:
                raise FactorialError('factorial_snapshot_after_decision')
            refs[snapshot.snapshot_id] = snapshot.ref()
            if snapshots.setdefault(snapshot.snapshot_id, snapshot) != snapshot:
                raise FactorialError('factorial_snapshot_id_reused')
        allowed = None if universe is None else set(universe(decision.decision_ms))
        produced = provider.candidates(decision.snapshots, decision.decision_ms)
        for c in produced:
            if c.header.clock.decision_ms != decision.decision_ms:
                raise FactorialError('factorial_candidate_decision_mismatch')
            if c.header.portfolio_id is not None:
                raise FactorialError('factorial_candidate_bound_to_portfolio')
            if (c.generator_id, c.generator_version, c.hypothesis_id) != (
                    provider.generator_id, provider.generator_version, provider.hypothesis_id):
                raise FactorialError('factorial_candidate_generator_mismatch')
            if refs.get(c.snapshot.snapshot_id) != c.snapshot:
                raise FactorialError('factorial_candidate_snapshot_not_from_this_decision')
            if allowed is not None and c.header.instrument.inst_id not in allowed:
                raise FactorialError('factorial_candidate_outside_point_in_time_universe')
        candidates.extend(sorted(produced, key=lambda c: c.candidate_id))
    used = {c.snapshot.snapshot_id for c in candidates}
    return CandidateBook(provider.generator_id, provider.generator_version, provider.hypothesis_id,
                         tuple(candidates), tuple(sorted((s for k, s in snapshots.items() if k in used),
                                                         key=lambda s: s.snapshot_id)))


# --------------------------------------------------------------------------------------------- entry selection

def reference_key(seed: int, candidate_id: str) -> str:
    return hashlib.sha256(f'{seed}:{candidate_id}'.encode()).hexdigest()


def reference_order(candidates: Sequence[OpportunityCandidate], seed: int) -> list[OpportunityCandidate]:
    """B0/B2 priority within a decision instant: a seeded lottery on the candidate id, blind to scores and features."""
    return sorted(candidates, key=lambda c: (reference_key(seed, c.candidate_id), c.candidate_id))


def score_book(book: CandidateBook, scorer: OpportunityScorer, model: ModelManifest | None) -> tuple[
        OpportunityScore, ...]:
    """Score every candidate once and rank each decision instant once; the result feeds both B1 and B3."""
    expected_model = None if model is None else model.ref()
    ranked_all: list[OpportunityScore] = []
    for decision_ms, group in book.by_decision():
        scores = []
        for candidate in group:
            score = scorer.score(candidate, book.snapshot_for(candidate), model)
            if score.candidate_id != candidate.candidate_id or score.snapshot != candidate.snapshot:
                raise FactorialError('factorial_score_candidate_mismatch')
            if score.header.clock.decision_ms != decision_ms or score.header.portfolio_id is not None:
                raise FactorialError('factorial_score_clock_or_portfolio_invalid')
            if expected_model is not None and score.model != expected_model:
                raise FactorialError('factorial_score_model_mismatch')
            scores.append(score)
        ranked = tuple(scorer.rank(scores))
        if sorted(s.candidate_id for s in ranked) != sorted(s.candidate_id for s in scores):
            raise FactorialError('factorial_rank_changed_candidate_set')
        ranked_all.extend(ranked)
    return tuple(ranked_all)


@dataclass(frozen=True)
class EntryIntent:
    """A candidate offered to the variant's portfolio, in priority order within its decision instant (0 first)."""

    candidate: OpportunityCandidate
    score: OpportunityScore | None
    priority: int


def plan_entries(variant: Variant, book: CandidateBook, scores: Sequence[OpportunityScore], *, seed: int,
                 portfolio_id: str) -> tuple[tuple[EntryIntent, ...], tuple[RefusalRecord, ...]]:
    entries: list[EntryIntent] = []
    refusals: list[RefusalRecord] = []
    by_candidate = {s.candidate_id: s for s in scores}
    position = {s.candidate_id: i for i, s in enumerate(scores)}
    for _, group in book.by_decision():
        if variant not in SCORED:
            for priority, c in enumerate(reference_order(group, seed)):
                entries.append(EntryIntent(c, None, priority))
            continue
        priority = 0
        for c in sorted(group, key=lambda x: position[x.candidate_id]):
            score = by_candidate[c.candidate_id]
            if score.verdict is ScoreVerdict.TRADE_CANDIDATE:
                entries.append(EntryIntent(c, score, priority))
                priority += 1
            else:
                refusals.append(RefusalRecord(candidate_id=c.candidate_id, variant=variant, portfolio_id=portfolio_id,
                                              inst_id=c.header.instrument.inst_id,
                                              decision_ms=c.header.clock.decision_ms, stage=RefusalStage.SCORE,
                                              reasons=score.reasons))
    return tuple(entries), tuple(refusals)


# ------------------------------------------------------------------------------------------------- simulator

@dataclass(frozen=True)
class VariantSpec:
    experiment_id: str
    variant: Variant
    portfolio_id: str
    quote_ccy: str
    initial_equity: Decimal
    start_ms: int
    end_ms: int
    limits: H3Limits
    cost_model: CostModel
    cost_scenario: CostScenario
    candidate_book_fingerprint: str
    market_fingerprint: str
    candidate_ids: tuple[str, ...]
    entries: tuple[EntryIntent, ...]
    pre_refusals: tuple[RefusalRecord, ...]
    policy: Any          # contracts.ManagementPolicy (static for B0/B1, adaptive for B2/B3)
    risk_gate: Any       # contracts.RiskGate, fresh for this variant

    def __post_init__(self) -> None:
        offered = [e.candidate.candidate_id for e in self.entries]
        refused = [r.candidate_id for r in self.pre_refusals]
        if sorted(offered + refused) != list(self.candidate_ids):
            raise FactorialError('factorial_spec_candidates_not_partitioned')


class VariantSimulator(Protocol):
    def run(self, spec: VariantSpec, market: MarketStream) -> VariantRun:
        ...


def portfolio_id_for(experiment_id: str, variant: Variant) -> str:
    return f'{experiment_id}-{variant.value}'


@dataclass(frozen=True)
class FactorialResult:
    runs: dict
    specs: dict
    scores: tuple[OpportunityScore, ...]
    candidate_book_fingerprint: str
    market_fingerprint: str
    order: tuple[Variant, ...]


def _check_manifest(manifest: ExperimentManifest, book: CandidateBook, static_policy: ManagementPolicy,
                    adaptive_policy: ManagementPolicy, model: ModelManifest | None) -> None:
    if (manifest.candidate_generator_id, manifest.candidate_generator_version, manifest.hypothesis_id) != (
            book.generator_id, book.generator_version, book.hypothesis_id):
        raise FactorialError('factorial_manifest_generator_mismatch')
    if manifest.static_policy != static_policy.policy or manifest.adaptive_policy != adaptive_policy.policy:
        raise FactorialError('factorial_manifest_policy_mismatch')
    if model is None:
        if manifest.scoring_model.model_fingerprint is not None:
            raise FactorialError('factorial_manifest_model_mismatch')
    elif manifest.scoring_model != model.ref():
        raise FactorialError('factorial_manifest_model_mismatch')


def run_factorial(*, manifest: ExperimentManifest, book: CandidateBook, market: MarketStream,
                  scorer: OpportunityScorer, model: ModelManifest | None, static_policy: ManagementPolicy,
                  adaptive_policy: ManagementPolicy, risk_gate_factory: Callable[[], RiskGate],
                  simulator_factory: Callable[[], VariantSimulator], start_ms: int, end_ms: int,
                  cost_scenario: CostScenario = CostScenario.BASE,
                  order: Sequence[Variant] = FACTORIAL) -> FactorialResult:
    if tuple(sorted(order, key=lambda v: v.value)) != FACTORIAL:
        raise FactorialError('factorial_order_not_a_permutation')
    _check_manifest(manifest, book, static_policy, adaptive_policy, model)
    book_fp, market_fp = book.fingerprint(), market.fingerprint()
    if market_fp != market.fingerprint_at_construction:
        raise FactorialError('factorial_shared_input_mutated')
    scores = score_book(book, scorer, model)
    gates: list[Any] = []
    simulators: list[Any] = []
    runs: dict[Variant, VariantRun] = {}
    specs: dict[Variant, VariantSpec] = {}
    for variant in order:
        gate, simulator = risk_gate_factory(), simulator_factory()
        if any(gate is g for g in gates) or any(simulator is s for s in simulators):
            raise FactorialError('factorial_component_shared_between_variants')
        gates.append(gate)
        simulators.append(simulator)
        portfolio_id = portfolio_id_for(manifest.experiment_id, variant)
        entries, refusals = plan_entries(variant, book, scores, seed=manifest.seed, portfolio_id=portfolio_id)
        spec = VariantSpec(
            experiment_id=manifest.experiment_id, variant=variant, portfolio_id=portfolio_id,
            quote_ccy=manifest.quote_ccy, initial_equity=manifest.initial_equity, start_ms=start_ms, end_ms=end_ms,
            limits=H3_LIMITS, cost_model=manifest.cost_model, cost_scenario=cost_scenario,
            candidate_book_fingerprint=book_fp, market_fingerprint=market_fp, candidate_ids=book.ids,
            entries=entries, pre_refusals=refusals,
            policy=adaptive_policy if variant in ADAPTIVE else static_policy, risk_gate=gate)
        run = simulator.run(spec, market)
        identity = (run.experiment_id, run.variant, run.portfolio_id, run.quote_ccy, run.initial_equity,
                    run.start_ms, run.end_ms, run.cost_scenario, run.candidate_book_fingerprint,
                    run.market_fingerprint, run.candidate_ids)
        if identity != (spec.experiment_id, variant, portfolio_id, spec.quote_ccy, spec.initial_equity, start_ms,
                        end_ms, cost_scenario, book_fp, market_fp, book.ids):
            raise FactorialError('factorial_run_identity_mismatch')
        for refusal in refusals:
            if refusal not in run.refusals:
                raise FactorialError('factorial_run_dropped_score_refusal')
        if book.fingerprint() != book_fp or market.fingerprint() != market_fp:
            raise FactorialError('factorial_shared_input_mutated')
        runs[variant] = run
        specs[variant] = spec
    return FactorialResult(runs=runs, specs=specs, scores=scores, candidate_book_fingerprint=book_fp,
                           market_fingerprint=market_fp, order=tuple(order))
