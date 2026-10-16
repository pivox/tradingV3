"""Matched random-abstention controls (BR) for the H3-A scoring question (review R1-F1). Synthetic use only.

Question: does the scorer's ranking add anything beyond trading less? B1 (scored entries, static policy) can beat B0
(every candidate, static policy) only because it abstains, for example when the common generator loses money: every
refused trade then saves a loss and its fees. BR is a portfolio that abstains AS MUCH AS B1, at random:

- same candidate book, market stream, window, capital, risk gate (fresh instance, same policy), cost model and
  scenario, exit policy (the static B0/B1 policy) and portfolio limits as B0 and B1;
- its acceptance budget is B1's: per stratum, the number of candidates B1 kept at the score stage (TRADE_CANDIDATE),
  taken from B1's DECISIONS (``budget_source='b1_acceptance'``), never from an outcome, a fill or a trade;
- within each stratum it accepts exactly that many candidates, chosen by a seeded lottery on the candidate id
  (``lottery_key``), blind to scores, features and outcomes; accepted entries are prioritised within an instant by
  the same seeded lottery as B0 (``factorial.reference_order``);
- it runs through the same simulator path as B0/B1 (``VariantSimulator``, e.g. ``RunVariantSimulator``) with its
  own gate and its own portfolio trajectory: B1 and BR free capital at different times, and nothing forces equal
  executed trades afterwards;
- it is evaluated over K pre-registered seeds (``RandomControlSpec.seeds``): a distribution of BR outcomes, never a
  single draw.

Methods (``RandomControlSpec.method``):
- ``global_budget``: one stratum, the whole window. BR keeps as many candidates as B1 over the window. It measures
  ranking AND timing: a scorer that concentrates its acceptances in good periods beats it.
- ``stratified``: strata from keys known at the decision instant (``STRATA_KEYS``: decision day or instant,
  instrument, side, score status as the eligibility state). It measures ranking WITHIN strata only: whatever the
  scorer gains by choosing between strata (which day, which instrument, which side) is matched away. Matching on a
  key that carries the signal (e.g. ``inst_id`` when the edge is between instruments) removes real skill from the
  comparison; this is the price of protection against timing artefacts, documented in
  docs/handbook/research/H3-SCORING-ABSTENTION-CONTROLS.md.

The global budget is the B1 count over the whole window, a number known only at the end of the window: BR is an
experimental control (a conditional randomisation), not a tradable strategy. That count is a decision count, not an
outcome, so it does not import future returns.

On the wire: the V1 ``Variant`` enum is frozen, so a BR portfolio runs as an UNSCORED portfolio (``Variant.B0`` at
the gate, which refuses a score for it) under its own portfolio id ``<experiment>-BR-<control_id>-s<seed>``; the
label ``BR`` lives here (``BR_LABEL``) and in ``ControlRun``. Agent D's V2 contracts add ``BR`` properly;
``spec_to_v2`` / ``budget_to_v2`` (and back) serialise the shared interface as those contracts (integration).

Nothing here trains, reads market data or places an order. Every number it produces on synthetic inputs is synthetic.
"""
from __future__ import annotations

import hashlib
import math
from collections.abc import Callable, Iterable, Mapping, Sequence
from dataclasses import dataclass, field
from decimal import Decimal
from typing import Any

from ..contracts import (
    H3_LIMITS, ZERO, ExperimentManifest, ManagementPolicy, OpportunityCandidate, OpportunityScore, PolicyRef,
    RiskGate, ScoreVerdict, Variant, canonical_json, sha256_text,
)
from .. import contracts_v2 as v2
from . import metrics as mt
from . import stats as st
from .factorial import (
    CandidateBook, EntryIntent, FactorialError, MarketStream, VariantSimulator, VariantSpec, reference_order,
)
from .plan import DAY_MS
from .records import CostScenario, RefusalRecord, RefusalStage, VariantRun

BR_LABEL = 'BR'
METHODS = ('global_budget', 'stratified')
#: Sorted; mirrored by ``contracts_v2.DECISION_TIME_STRATA`` (integration: a test pins the equality).
STRATA_KEYS = ('decision_day', 'decision_ms', 'inst_id', 'score_status', 'side')
BUDGET_SOURCES = ('b1_acceptance',)
GLOBAL_STRATUM = '*'
CONTROL_REFUSAL_REASON = 'random_abstention_control'
#: statuses this module can return about H3-A; none of them says the scorer is validated or profitable
H3A_STATUSES = (
    'h3a_ranking_and_selection_beyond_abstention_draft',
    'h3a_ranking_without_selection_value',
    'h3a_selection_without_ranking_evidence',
    'h3a_abstention_only_no_skill_shown',
    'h3a_not_supported',
    'h3a_insufficient_information',
)


class AbstentionError(ValueError):
    """The random-abstention control would be broken. The message is a stable reason code."""


def _fp(document: Any) -> str:
    return sha256_text(canonical_json(document))


# ------------------------------------------------------------------------------------------------ shared interface

@dataclass(frozen=True)
class RandomControlSpec:
    """Shared interface (COMMON.md; mirrored by agent D's V2 contracts). Pre-registered before any run."""

    control_id: str
    method: str
    strata_keys: tuple[str, ...]
    seeds: tuple[int, ...]
    budget_source: str = 'b1_acceptance'

    def __post_init__(self) -> None:
        object.__setattr__(self, 'strata_keys', tuple(self.strata_keys))
        object.__setattr__(self, 'seeds', tuple(self.seeds))
        if not isinstance(self.control_id, str) or not self.control_id or not all(
                ch.isalnum() or ch in '._-' for ch in self.control_id):
            raise AbstentionError('abstention_control_id_invalid')
        if self.method not in METHODS:
            raise AbstentionError('abstention_method_unknown')
        if self.method == 'global_budget' and self.strata_keys:
            raise AbstentionError('abstention_global_budget_takes_no_strata')
        if self.method == 'stratified' and not self.strata_keys:
            raise AbstentionError('abstention_stratified_needs_strata')
        if any(k not in STRATA_KEYS for k in self.strata_keys) or len(set(self.strata_keys)) != len(self.strata_keys):
            raise AbstentionError('abstention_stratum_key_not_known_at_decision')
        # integration (H3-A testability): one canonical form shared with the V2 contracts, which require sorted keys
        # and seeds; the key order also fixes the stratum labels, so it cannot be left free
        if list(self.strata_keys) != sorted(self.strata_keys):
            raise AbstentionError('abstention_strata_keys_not_sorted')
        if not self.seeds or len(set(self.seeds)) != len(self.seeds) or any(
                isinstance(s, bool) or not isinstance(s, int) or s < 0 for s in self.seeds):
            raise AbstentionError('abstention_seeds_invalid')
        if list(self.seeds) != sorted(self.seeds):
            raise AbstentionError('abstention_seeds_not_sorted')
        if self.budget_source not in BUDGET_SOURCES:
            raise AbstentionError('abstention_budget_source_invalid')

    def to_wire(self) -> dict:
        return {'control_id': self.control_id, 'method': self.method, 'strata_keys': list(self.strata_keys),
                'seeds': list(self.seeds), 'budget_source': self.budget_source}

    def fingerprint(self) -> str:
        return _fp(self.to_wire())


@dataclass(frozen=True)
class ActivityBudget:
    """B1's acceptance per stratum: ``per_stratum[label] = (candidate_count, accepted_count)``. Derived from B1's
    score-stage decisions only (``derived_from_variant='B1'``)."""

    budget_id: str
    strata_keys: tuple[str, ...]
    per_stratum: Mapping[str, tuple[int, int]]
    derived_from_variant: str = 'B1'
    fingerprint: str = field(default='')

    def __post_init__(self) -> None:
        items = {str(k): (int(v[0]), int(v[1])) for k, v in sorted(dict(self.per_stratum).items())}
        if any(n < 0 or a < 0 or a > n for n, a in items.values()):
            raise AbstentionError('abstention_budget_counts_invalid')
        if self.derived_from_variant != 'B1':
            raise AbstentionError('abstention_budget_not_from_b1')
        object.__setattr__(self, 'per_stratum', items)
        object.__setattr__(self, 'strata_keys', tuple(self.strata_keys))
        expected = _fp({'budget_id': self.budget_id, 'strata_keys': list(self.strata_keys),
                        'per_stratum': {k: list(v) for k, v in items.items()},
                        'derived_from_variant': self.derived_from_variant})
        if self.fingerprint and self.fingerprint != expected:
            raise AbstentionError('abstention_budget_fingerprint_mismatch')
        object.__setattr__(self, 'fingerprint', expected)

    @property
    def candidate_count(self) -> int:
        return sum(n for n, _ in self.per_stratum.values())

    @property
    def accepted_count(self) -> int:
        return sum(a for _, a in self.per_stratum.values())

    @property
    def acceptance_rate(self) -> float | None:
        return self.accepted_count / self.candidate_count if self.candidate_count else None


# ------------------------------------------------------------------------------------- V2 contracts (integration)

def spec_to_v2(spec: RandomControlSpec) -> v2.RandomControlSpec:
    """The shared interface as the V2 contract ``contracts_v2.RandomControlSpec`` (same field names and values)."""
    return v2.RandomControlSpec(control_id=spec.control_id, method=v2.ControlMethod(spec.method),
                                strata_keys=spec.strata_keys, seeds=spec.seeds,
                                budget_source=v2.BudgetSource(spec.budget_source))


def spec_from_v2(spec: v2.RandomControlSpec) -> RandomControlSpec:
    return RandomControlSpec(control_id=spec.control_id, method=spec.method.value, strata_keys=spec.strata_keys,
                             seeds=spec.seeds, budget_source=spec.budget_source.value)


def budget_to_v2(budget: ActivityBudget) -> v2.ActivityBudget:
    """``contracts_v2.ActivityBudget``: the per-stratum mapping as sorted ``strata``, the same ``strata_keys``. Its
    ``fingerprint()`` is the one ``VariantRunRef.activity_budget_fingerprint`` carries for a BR run."""
    if budget.derived_from_variant != v2.VariantV2.B1.value:
        raise AbstentionError('abstention_budget_not_from_b1')
    return v2.ActivityBudget.from_mapping(budget.budget_id, budget.per_stratum, budget.strata_keys)


def budget_from_v2(budget: v2.ActivityBudget) -> ActivityBudget:
    return ActivityBudget(budget.budget_id, budget.strata_keys, budget.as_mapping(),
                          derived_from_variant=budget.derived_from_variant.value)


# --------------------------------------------------------------------------------------------- strata and budget

@dataclass(frozen=True)
class DecisionItem:
    """What the control may know about a candidate at its decision instant: its id and its stratum values."""

    candidate_id: str
    values: Mapping[str, str]


def decision_item(candidate: OpportunityCandidate, score: OpportunityScore | None, *, start_ms: int,
                  day_ms: int = DAY_MS) -> DecisionItem:
    """Stratum values known at the decision: day index from ``start_ms``, instant, instrument, side, and the score
    STATUS (the eligibility state: estimated, unestimated, invalid input...; never the score value)."""
    decision_ms = candidate.header.clock.decision_ms
    side = candidate.header.side
    return DecisionItem(candidate.candidate_id, {
        'decision_day': str((decision_ms - start_ms) // day_ms),
        'decision_ms': str(decision_ms),
        'inst_id': candidate.header.instrument.inst_id,
        'side': 'none' if side is None else side.value,
        'score_status': 'none' if score is None else score.status.value,
    })


def stratum_label(values: Mapping[str, str], keys: Sequence[str]) -> str:
    if not keys:
        return GLOBAL_STRATUM
    return '|'.join(f'{k}={values[k]}' for k in keys)


def activity_budget(items: Sequence[DecisionItem], accepted_ids: Iterable[str], strata_keys: Sequence[str],
                    budget_id: str) -> ActivityBudget:
    """Count candidates and B1-accepted candidates per stratum. ``accepted_ids`` are B1's decisions."""
    accepted = set(accepted_ids)
    ids = [i.candidate_id for i in items]
    if len(set(ids)) != len(ids):
        raise AbstentionError('abstention_duplicate_candidate')
    if not accepted <= set(ids):
        raise AbstentionError('abstention_accepted_outside_candidates')
    counts: dict[str, list[int]] = {}
    for item in items:
        label = stratum_label(item.values, strata_keys)
        pair = counts.setdefault(label, [0, 0])
        pair[0] += 1
        pair[1] += item.candidate_id in accepted
    return ActivityBudget(budget_id, tuple(strata_keys), {k: (v[0], v[1]) for k, v in counts.items()})


def b1_accepted_ids(scores: Sequence[OpportunityScore]) -> frozenset[str]:
    """B1's acceptance: the candidates its scorer kept (TRADE_CANDIDATE). A decision, not an outcome."""
    return frozenset(s.candidate_id for s in scores if s.verdict is ScoreVerdict.TRADE_CANDIDATE)


def lottery_key(control_id: str, seed: int, candidate_id: str) -> str:
    return hashlib.sha256(f'br:{control_id}:{seed}:{candidate_id}'.encode()).hexdigest()


def select_random(items: Sequence[DecisionItem], budget: ActivityBudget,
                  key: Callable[[str], Any]) -> frozenset[str]:
    """Within each stratum, accept exactly the budget's count: the candidates with the smallest ``key(id)``.
    ``key`` must be a seeded draw independent of scores, features and outcomes (``lottery_key`` in the harness,
    a PRNG stream in the light simulation)."""
    groups: dict[str, list[str]] = {}
    for item in items:
        groups.setdefault(stratum_label(item.values, budget.strata_keys), []).append(item.candidate_id)
    if set(groups) != set(budget.per_stratum):
        raise AbstentionError('abstention_strata_do_not_match_budget')
    chosen: list[str] = []
    for label, members in groups.items():
        n, k = budget.per_stratum[label]
        if n != len(members):
            raise AbstentionError('abstention_stratum_count_mismatch')
        chosen.extend(sorted(members, key=lambda cid: (key(cid), cid))[:k])
    return frozenset(chosen)


# ------------------------------------------------------------------------------------------ harness (real path)

@dataclass(frozen=True)
class ControlRun:
    label: str
    control_id: str
    method: str
    seed: int
    accepted_ids: frozenset[str]
    run: VariantRun


@dataclass(frozen=True)
class RandomControlResult:
    spec: RandomControlSpec
    budget: ActivityBudget
    runs: tuple[ControlRun, ...]
    candidate_book_fingerprint: str
    market_fingerprint: str


def control_portfolio_id(experiment_id: str, control_id: str, seed: int) -> str:
    return f'{experiment_id}-{BR_LABEL}-{control_id}-s{seed}'


def plan_control_entries(book: CandidateBook, accepted: frozenset[str], *, seed: int,
                         portfolio_id: str) -> tuple[tuple[EntryIntent, ...], tuple[RefusalRecord, ...]]:
    """Accepted candidates in the seeded lottery order within each instant (as B0); the others are score-stage
    refusals with the reason ``random_abstention_control``. No score is attached (BR is an unscored portfolio)."""
    entries: list[EntryIntent] = []
    refusals: list[RefusalRecord] = []
    for _, group in book.by_decision():
        priority = 0
        for c in reference_order(group, seed):
            if c.candidate_id in accepted:
                entries.append(EntryIntent(c, None, priority))
                priority += 1
            else:
                refusals.append(RefusalRecord(candidate_id=c.candidate_id, variant=Variant.B0,
                                              portfolio_id=portfolio_id, inst_id=c.header.instrument.inst_id,
                                              decision_ms=c.header.clock.decision_ms, stage=RefusalStage.SCORE,
                                              reasons=(CONTROL_REFUSAL_REASON,)))
    return tuple(entries), tuple(refusals)


def run_random_control(*, manifest: ExperimentManifest, book: CandidateBook, scores: Sequence[OpportunityScore],
                       market: MarketStream, spec: RandomControlSpec, static_policy: ManagementPolicy,
                       risk_gate_factory: Callable[[], RiskGate], simulator_factory: Callable[[], VariantSimulator],
                       start_ms: int, end_ms: int, cost_scenario: CostScenario = CostScenario.BASE,
                       risk_policy: PolicyRef | None = None) -> RandomControlResult:
    """Run BR once per pre-registered seed, each with a fresh gate and a fresh simulator, through the same
    ``VariantSimulator`` path as B0/B1. ``scores`` are the scores B1 received (``factorial.score_book``); the budget
    is derived from them here, so no outcome can enter it."""
    if manifest.static_policy != static_policy.policy:
        raise AbstentionError('abstention_static_policy_not_the_manifest_policy')
    if cost_scenario is CostScenario.PESSIMISTIC and risk_policy is None:
        raise AbstentionError('abstention_pessimistic_risk_policy_required')
    expected_risk = manifest.risk_policy if risk_policy is None else risk_policy
    if sorted(s.candidate_id for s in scores) != list(book.ids):
        raise AbstentionError('abstention_scores_do_not_cover_the_book')
    book_fp, market_fp = book.fingerprint(), market.fingerprint()
    if market_fp != market.fingerprint_at_construction:
        raise FactorialError('factorial_shared_input_mutated')
    by_id = {s.candidate_id: s for s in scores}
    items = [decision_item(c, by_id[c.candidate_id], start_ms=start_ms) for c in book.candidates]
    budget = activity_budget(items, b1_accepted_ids(scores), spec.strata_keys,
                             budget_id=f'{manifest.experiment_id}:{spec.control_id}')
    gates: list[Any] = []
    simulators: list[Any] = []
    runs: list[ControlRun] = []
    for seed in spec.seeds:
        gate, simulator = risk_gate_factory(), simulator_factory()
        if any(gate is g for g in gates) or any(simulator is s for s in simulators):
            raise FactorialError('factorial_component_shared_between_variants')
        if getattr(gate, 'policy', None) != expected_risk:
            raise FactorialError('factorial_gate_risk_policy_mismatch')
        gates.append(gate)
        simulators.append(simulator)
        accepted = select_random(items, budget, lambda cid, s=seed: lottery_key(spec.control_id, s, cid))
        portfolio_id = control_portfolio_id(manifest.experiment_id, spec.control_id, seed)
        entries, refusals = plan_control_entries(book, accepted, seed=seed, portfolio_id=portfolio_id)
        vspec = VariantSpec(
            experiment_id=manifest.experiment_id, variant=Variant.B0, portfolio_id=portfolio_id,
            quote_ccy=manifest.quote_ccy, initial_equity=manifest.initial_equity, start_ms=start_ms, end_ms=end_ms,
            limits=H3_LIMITS, cost_model=manifest.cost_model, cost_scenario=cost_scenario,
            candidate_book_fingerprint=book_fp, market_fingerprint=market_fp, candidate_ids=book.ids,
            entries=entries, pre_refusals=refusals, policy=static_policy, risk_gate=gate)
        run = simulator.run(vspec, market)
        if (run.portfolio_id, run.candidate_book_fingerprint, run.market_fingerprint, run.candidate_ids,
                run.initial_equity, run.start_ms, run.end_ms) != (portfolio_id, book_fp, market_fp, book.ids,
                                                                   manifest.initial_equity, start_ms, end_ms):
            raise FactorialError('factorial_run_identity_mismatch')
        if book.fingerprint() != book_fp or market.fingerprint() != market_fp:
            raise FactorialError('factorial_shared_input_mutated')
        runs.append(ControlRun(BR_LABEL, spec.control_id, spec.method, seed, accepted, run))
    return RandomControlResult(spec, budget, tuple(runs), book_fp, market_fp)


# ------------------------------------------------------------------------------------------ portfolio activity

def _time_weighted_mean(points: Sequence[Any], attr: str, start_ms: int, end_ms: int) -> float | None:
    if end_ms <= start_ms or not points:
        return None
    total = 0.0
    for a, b in zip(points, points[1:]):
        total += float(getattr(a, attr)) * (b.ts_ms - a.ts_ms)
    return total / (end_ms - start_ms)


def portfolio_activity(run: VariantRun, *, block_ms: int) -> dict:
    """B2 activity and outcome metrics of one portfolio, each keeping its own trajectory: candidates evaluated,
    acceptance (not refused at the score stage), executable orders (accepted and not refused by the risk gate),
    trades executed, exposure, capital mobilised, net PnL per block, drawdown, net per unit of exposure."""
    candidates = len(run.candidate_ids)
    score_refused = sum(1 for r in run.refusals if r.stage is RefusalStage.SCORE)
    risk_refused = sum(1 for r in run.refusals if r.stage is RefusalStage.RISK)
    accepted = candidates - score_refused
    trades = run.trades
    exposure_ms = sum(t.holding_ms for t in trades)
    risk_ms = math.fsum(float(t.initial_risk) * t.holding_ms for t in trades)
    net = run.net_pnl
    gross = sum((t.gross_pnl for t in trades), ZERO)
    costs = sum((t.execution_cost + t.funding for t in trades), ZERO)
    blocks = [float(x) for x in mt.block_pnl(run, block_ms)]
    return {
        'portfolio_id': run.portfolio_id, 'candidates_evaluated': candidates, 'accepted': accepted,
        'acceptance_rate': accepted / candidates if candidates else None,
        'executable_orders': accepted - risk_refused, 'trades_executed': len(trades),
        'exposure_days': exposure_ms / DAY_MS,
        'mean_open_risk': _time_weighted_mean(run.equity_curve, 'open_risk', run.start_ms, run.end_ms),
        'mean_notional_at_entry': (math.fsum(float(t.notional_at_entry) for t in trades) / len(trades))
        if trades else None,
        'net_pnl': float(net), 'gross_pnl': float(gross), 'costs_and_funding': float(costs),
        'net_pnl_per_block': blocks, 'max_drawdown': mt.drawdown(run.equity_curve),
        'net_per_exposure_day': float(net) / (exposure_ms / DAY_MS) if exposure_ms else None,
        'net_per_risk_day': float(net) / (risk_ms / DAY_MS) if risk_ms else None,
    }


# ---------------------------------------------------------------------------------------- B1 against BR

def randomisation_p(observed: float, controls: Sequence[float]) -> float:
    """One-sided Monte Carlo p-value of ``observed`` against K control draws: (1 + #{c >= observed}) / (K + 1).
    Exact under the null that B1's selection is exchangeable with BR's given the strata (no information); it is
    CONDITIONAL on the realised market, so it does not cover dependence between the score and the outcome process
    (see ``block_excess_test``)."""
    if not controls:
        raise AbstentionError('abstention_no_control_draws')
    return (1 + sum(1 for c in controls if c >= observed)) / (len(controls) + 1)


def block_excess_series(b1_blocks: Sequence[float], control_blocks: Sequence[Sequence[float]]) -> list[float]:
    """d_b = B1 net PnL in block b minus the mean over the K BR draws in block b."""
    if not control_blocks or any(len(c) != len(b1_blocks) for c in control_blocks):
        raise AbstentionError('abstention_block_series_mismatch')
    k = len(control_blocks)
    return [b - math.fsum(c[i] for c in control_blocks) / k for i, b in enumerate(b1_blocks)]


def block_excess_test(series: Sequence[float], *, alpha: float, procedure: str = 'sn_gaussian') -> dict:
    """Dependence-robust one-sided test of mean(d_b) > 0 over blocks (the plan's procedure), with the ESS."""
    result = st.one_sided_test(series, procedure=procedure, alpha=alpha).as_dict()
    result['effective_blocks'] = st.effective_sample_size(series)
    result['blocks'] = len(series)
    return result


def compare_b1_to_controls(b1: VariantRun, controls: RandomControlResult, *, block_ms: int, alpha: float,
                           procedure: str = 'sn_gaussian', b0: VariantRun | None = None) -> dict:
    """B1 against the distribution of its K matched BR portfolios (and B0 when given). Every quantity is
    descriptive of this sample; a positive excess over BR is not a profit and not a validation."""
    b1_act = portfolio_activity(b1, block_ms=block_ms)
    acts = [portfolio_activity(c.run, block_ms=block_ms) for c in controls.runs]

    def dist(key: str) -> dict:
        values = sorted(a[key] for a in acts if a[key] is not None)
        if not values:
            return {'n': 0}
        return {'n': len(values), 'min': values[0], 'median': values[len(values) // 2], 'max': values[-1],
                'mean': math.fsum(values) / len(values)}

    keys = ('accepted', 'executable_orders', 'trades_executed', 'exposure_days', 'mean_open_risk', 'net_pnl',
            'gross_pnl', 'costs_and_funding', 'net_per_exposure_day', 'net_per_risk_day')
    excess = block_excess_series(b1_act['net_pnl_per_block'], [a['net_pnl_per_block'] for a in acts])
    out = {
        'control': controls.spec.to_wire(), 'control_fingerprint': controls.spec.fingerprint(),
        'budget': {'fingerprint': controls.budget.fingerprint, 'accepted': controls.budget.accepted_count,
                   'candidates': controls.budget.candidate_count, 'strata': len(controls.budget.per_stratum)},
        'b1': {k: b1_act[k] for k in keys + ('acceptance_rate', 'max_drawdown')},
        'br_distribution': {k: dist(k) for k in keys},
        'p_net_pnl_vs_br': randomisation_p(b1_act['net_pnl'], [a['net_pnl'] for a in acts]),
        'p_net_per_risk_day_vs_br': randomisation_p(
            b1_act['net_per_risk_day'] if b1_act['net_per_risk_day'] is not None else -math.inf,
            [a['net_per_risk_day'] if a['net_per_risk_day'] is not None else -math.inf for a in acts]),
        'block_excess_test': block_excess_test(excess, alpha=alpha, procedure=procedure),
        'reading': ('beating BR says the selection is better than random abstention at the same activity in this '
                    'sample; it does not say the strategy earns money (see b1.net_pnl) and is not a validation'),
    }
    if b0 is not None:
        out['b0'] = {k: portfolio_activity(b0, block_ms=block_ms)[k] for k in keys}
    return out


# ------------------------------------------------------------------------------------------ H3-A DRAFT criterion

def h3a_status(*, rank_ic_rejects: bool | None, br_excess_rejects: bool | None, b1_beats_b0: bool | None,
               ic_halves_positive: bool | None) -> str:
    """DRAFT H3-A reading (H3-A-STATISTICAL-VALIDATION-DRAFT.md): the primary needs BOTH a ranking test on
    capacity-free labels (rank IC > 0) AND a dependence-robust excess over the matched random-abstention control,
    plus the stability guard (mean IC > 0 in both halves). B1 beating B0 alone is abstention, never skill.
    Absolute profitability is a separate question (H3-C) and is not part of this status."""
    if rank_ic_rejects is None or br_excess_rejects is None:
        return 'h3a_insufficient_information'
    if rank_ic_rejects and br_excess_rejects and ic_halves_positive:
        return 'h3a_ranking_and_selection_beyond_abstention_draft'
    if rank_ic_rejects and not br_excess_rejects:
        return 'h3a_ranking_without_selection_value'
    if br_excess_rejects and not rank_ic_rejects:
        return 'h3a_selection_without_ranking_evidence'
    if b1_beats_b0:
        return 'h3a_abstention_only_no_skill_shown'
    return 'h3a_not_supported'
