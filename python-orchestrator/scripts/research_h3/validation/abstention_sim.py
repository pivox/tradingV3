"""Synthetic studies of the random-abstention control (B3 of the H3-A testability mission). SYNTHETIC ONLY.

Two parts:

1. ``run_e2e_random_control``: the BR control end to end on the H3 synthetic e2e market (``e2e.py``), through the
   real path (``run_factorial`` for B0..B3, then ``abstention.run_random_control`` with ``RunVariantSimulator`` and
   fresh ``H3RiskGate`` instances). A technical demonstration that BR runs with its own gate and trajectory.

2. A light candidate-level portfolio simulation (``Population``, ``simulate_panel``, ``evaluate_panel``), fast enough
   for Monte Carlo size and power: the full bar-replay simulator costs about a second per portfolio, far too slow for
   thousands of panels with K control draws each. What it keeps from the real mechanics: one common candidate stream,
   a portfolio capacity (``MAX_OPEN`` positions at the nominal risk each, at most one per instrument), entries at the
   decision instant in priority order, holding periods, per-trade costs in R, separate trajectories for B0, B1 and
   each BR draw. What it drops: bars, fills, stops, funding, the collar; outcomes are drawn directly in net R.

Generative model of one panel (outcomes in R, 1 R = the nominal risk of a trade):
- decision instants every ``STEP_H`` hours over ``n_blocks`` weeks; at each instant each of ``N_INST`` instruments
  emits a candidate with probability ``p_candidate``; side long/short at random; holding ``U{1..12} * STEP_H`` hours;
- the score s is unit-variance: s = sqrt(w_b) g_b + sqrt(w_i) h_i + sqrt(w_t) c_t + sqrt(1 - w_b - w_i - w_t) v, with
  g_b a persistent block factor (AR(1), ``phi_score``), h_i an instrument effect, c_t a common factor of the instant
  and v i.i.d.; with ``iid_score`` every structural weight is zero;
- the outcome is y = mu_net + ic_b * s + sqrt(1 - ic_b^2) e, where ic_b is the population's IC in block b (``ic``,
  or ``+ic`` then ``-ic`` with ``flip``) and e is unit-variance: e = sqrt(rho_t) f_b + sqrt(rho_x) x_t +
  sqrt(rho_i) a_i + sqrt(1 - rho_t - rho_x - rho_i) z, with f_b an AR(1) regime (``phi_regime``) INDEPENDENT of the
  score's g_b, x_t a common factor of the instant independent of c_t, a_i an instrument effect, z i.i.d.;
  ``inst_signal`` puts the signal in the instrument effect instead: a_i is shared by score and outcome;
- mu_net is the net expectancy per trade after ``COST_R`` (0.10 R) of costs, so ``gross = net + COST_R``.

Variants on a panel: B0 accepts every candidate (lottery priority); B1 accepts candidates with s > ``ACCEPT_Z``
(fixed a priori, about 30 % under a unit-variance score; priority by score); BR draws accept exactly B1's count per
stratum at random (``abstention.select_random``), ``k_controls`` draws per method: global budget, stratified by
decision day, and, as a diagnostic of over-matching, stratified by instrument.

Tests reported per panel (one-sided, alpha = 0.05): see ``METHODS``. Nothing here is evidence about any market.
"""
from __future__ import annotations

import hashlib
import math
import random
from collections.abc import Sequence
from dataclasses import asdict, dataclass
from typing import Any

from . import abstention as ab
from . import stats as st

STEP_H = 4
N_INST = 6
WEEK_H = 168
DAY_H = 24
MAX_OPEN = 2                      # 1 % total risk / 0.5 % per trade
COST_R = 0.10
ACCEPT_Z = 0.5244                 # P(Z > 0.5244) = 0.30 for a unit-variance score
SYNTHETIC_LABEL = 'SYNTHETIC_NOT_EVIDENCE'

METHODS = (
    'naive_b1_vs_b0',             # per-week B1 - B0 net, SN test: the old S1, confounded with abstention
    'perm_global',                # total net B1 vs K global-budget BR draws, randomisation p
    'perm_day',                   # total net B1 vs K day-stratified BR draws, randomisation p
    'block_global',               # per-week B1 - mean(BR global), SN test over weeks
    'block_day',                  # per-week B1 - mean(BR day), SN test over weeks
    'perm_inst',                  # diagnostic: total net B1 vs K instrument-stratified BR draws
    'rank_ic',                    # per-week Spearman(score, capacity-free outcome), SN test over weeks
    'kept_minus_refused',         # per-week mean outcome kept - refused (capacity-free), SN test
    'h3a_primary',                # DRAFT: rank_ic AND block_day AND mean IC > 0 in both halves
    'b1_absolute',                # per-week B1 net > 0, SN test (performance absolue, H3-C-like, not H3-A)
)


@dataclass(frozen=True)
class Population:
    name: str
    null: bool                    # True when the score carries no usable information on average
    mu_net: float = 0.0
    ic: float = 0.0
    flip: bool = False
    inst_signal: float = 0.0      # sd of an instrument effect shared by score and outcome (between-instrument skill)
    iid_score: bool = False
    w_b: float = 0.15             # score: persistent block factor share
    w_i: float = 0.10             # score: instrument effect share
    w_t: float = 0.0              # score: instant common factor share
    phi_score: float = 0.5
    rho_t: float = 0.10           # outcome: regime share
    rho_x: float = 0.0            # outcome: instant common factor share
    rho_i: float = 0.05           # outcome: instrument effect share
    phi_regime: float = 0.3
    p_candidate: float = 0.04     # about 10 candidates a week over six instruments


POPULATIONS: tuple[Population, ...] = (
    Population('p1_no_signal', null=True),
    Population('p2_weak_signal', null=False, ic=0.05),
    Population('p3_strong_signal', null=False, ic=0.15),
    Population('p4_losing_generator', null=True, mu_net=-0.15),
    Population('p5_random_selection', null=True, mu_net=-0.15, iid_score=True),
    Population('p6_dependent_null', null=True, mu_net=-0.05, w_b=0.5, phi_score=0.8, rho_t=0.4, phi_regime=0.8),
    Population('p6s_dependent_signal', null=False, mu_net=-0.05, ic=0.10, w_b=0.5, phi_score=0.8, rho_t=0.4,
               phi_regime=0.8),
    Population('p7_correlated_null', null=True, w_t=0.4, rho_x=0.5),
    Population('p7s_correlated_signal', null=False, ic=0.10, w_t=0.4, rho_x=0.5),
    Population('p8_regime_flip', null=True, ic=0.15, flip=True),
    Population('p9_between_instrument_signal', null=False, inst_signal=0.3),
    Population('p10_signal_on_losing_generator', null=False, mu_net=-0.15, ic=0.15),
)


def population(name: str) -> Population:
    return next(p for p in POPULATIONS if p.name == name)


# ---------------------------------------------------------------------------------------------- panel generation

@dataclass(frozen=True)
class SimCandidate:
    cid: str
    t_h: int                      # decision hour from the panel start
    inst: int
    side: str
    hold_h: int
    score: float
    outcome: float                # net R (capacity-free label)


def _ar1(rng: random.Random, n: int, phi: float) -> list[float]:
    out, f = [], rng.gauss(0.0, 1.0)
    innovation = math.sqrt(max(0.0, 1.0 - phi * phi))
    for i in range(n):
        if i:
            f = phi * f + innovation * rng.gauss(0.0, 1.0)
        out.append(f)
    return out


def simulate_panel(pop: Population, *, n_blocks: int, rng: random.Random) -> list[SimCandidate]:
    g = _ar1(rng, n_blocks, pop.phi_score)
    f = _ar1(rng, n_blocks, pop.phi_regime)
    h = [rng.gauss(0.0, 1.0) for _ in range(N_INST)]
    a = [rng.gauss(0.0, 1.0) for _ in range(N_INST)]
    w_b, w_i, w_t = (0.0, 0.0, 0.0) if pop.iid_score else (pop.w_b, pop.w_i, pop.w_t)
    w_v = 1.0 - w_b - w_i - w_t
    rho_z = 1.0 - pop.rho_t - pop.rho_x - pop.rho_i
    out: list[SimCandidate] = []
    steps = n_blocks * WEEK_H // STEP_H
    for k in range(steps):
        t_h = k * STEP_H
        b = t_h // WEEK_H
        c_t, x_t = rng.gauss(0.0, 1.0), rng.gauss(0.0, 1.0)
        ic_b = pop.ic if not pop.flip or b < n_blocks // 2 else -pop.ic
        for i in range(N_INST):
            if rng.random() >= pop.p_candidate:
                continue
            side = 'long' if rng.random() < 0.5 else 'short'
            hold = STEP_H * rng.randint(1, 12)
            if pop.inst_signal:
                # the instrument effect a_i is seen by the score and pays in the outcome: between-instrument skill
                s = math.sqrt(0.5) * a[i] + math.sqrt(0.5) * rng.gauss(0.0, 1.0)
                y = pop.mu_net + pop.inst_signal * a[i] + math.sqrt(1.0 - pop.inst_signal ** 2) * rng.gauss(0.0, 1.0)
            else:
                s = (math.sqrt(w_b) * g[b] + math.sqrt(w_i) * h[i] + math.sqrt(w_t) * c_t
                     + math.sqrt(w_v) * rng.gauss(0.0, 1.0))
                e = (math.sqrt(pop.rho_t) * f[b] + math.sqrt(pop.rho_x) * x_t + math.sqrt(pop.rho_i) * a[i]
                     + math.sqrt(rho_z) * rng.gauss(0.0, 1.0))
                y = pop.mu_net + ic_b * s + math.sqrt(1.0 - ic_b * ic_b) * e
            out.append(SimCandidate(f'c{len(out)}', t_h, i, side, hold, s, y))
    return out


# ------------------------------------------------------------------------------------------- portfolio replay

def replay(cands: Sequence[SimCandidate], accepted: set[str] | frozenset[str], priority: dict[str, float],
           n_blocks: int) -> dict:
    """Capacity-limited replay of one portfolio: per instant, accepted candidates in ``priority`` order (smallest
    first), admitted while fewer than MAX_OPEN positions are open and the instrument is free. Positions close after
    their holding period; the trade's net R is booked in the block of its exit."""
    open_pos: list[tuple[int, int]] = []          # (exit hour, instrument)
    blocks = [0.0] * n_blocks
    trades = 0
    exposure_h = 0
    i, n = 0, len(cands)
    while i < n:
        t = cands[i].t_h
        j = i
        while j < n and cands[j].t_h == t:
            j += 1
        open_pos = [p for p in open_pos if p[0] > t]
        group = sorted((c for c in cands[i:j] if c.cid in accepted), key=lambda c: (priority[c.cid], c.cid))
        for c in group:
            if len(open_pos) >= MAX_OPEN or any(p[1] == c.inst for p in open_pos):
                continue
            exit_h = t + c.hold_h
            open_pos.append((exit_h, c.inst))
            blocks[min(n_blocks - 1, exit_h // WEEK_H)] += c.outcome
            trades += 1
            exposure_h += c.hold_h
        i = j
    return {'blocks': blocks, 'net': math.fsum(blocks), 'trades': trades, 'exposure_h': exposure_h,
            'accepted': len(accepted)}


def _items(cands: Sequence[SimCandidate]) -> list[ab.DecisionItem]:
    return [ab.DecisionItem(c.cid, {'decision_day': str(c.t_h // DAY_H), 'decision_ms': str(c.t_h),
                                    'inst_id': str(c.inst), 'side': c.side, 'score_status': 'estimated'})
            for c in cands]


def _sn(series: Sequence[float], alpha: float) -> bool:
    return bool(st.sn_one_sided(series, alpha=alpha).rejected)


def _block_ic(cands: Sequence[SimCandidate], n_blocks: int) -> list[float | None]:
    by: list[list[SimCandidate]] = [[] for _ in range(n_blocks)]
    for c in cands:
        by[c.t_h // WEEK_H].append(c)
    out: list[float | None] = []
    for members in by:
        out.append(st.spearman([m.score for m in members], [m.outcome for m in members])
                   if len(members) >= 3 else None)
    return out


def evaluate_panel(cands: Sequence[SimCandidate], *, n_blocks: int, k_controls: int, alpha: float,
                   rng: random.Random, with_inst: bool = True) -> dict:
    """Every method's decision on one panel, plus descriptive activity numbers."""
    b0_priority = {c.cid: rng.random() for c in cands}
    all_ids = {c.cid for c in cands}
    b0 = replay(cands, all_ids, b0_priority, n_blocks)
    accepted_b1 = {c.cid for c in cands if c.score > ACCEPT_Z}
    b1 = replay(cands, accepted_b1, {c.cid: -c.score for c in cands}, n_blocks)
    items = _items(cands)
    controls: dict[str, list[dict]] = {}
    strata = {'global': (), 'day': ('decision_day',)}
    if with_inst:
        strata['inst'] = ('inst_id',)
    for name, keys in strata.items():
        budget = ab.activity_budget(items, accepted_b1, keys, budget_id=f'sim:{name}')
        runs = []
        for _ in range(k_controls):
            keys_draw = {c.cid: rng.random() for c in cands}
            chosen = ab.select_random(items, budget, keys_draw.__getitem__)
            runs.append(replay(cands, chosen, {c.cid: rng.random() for c in cands}, n_blocks))
        controls[name] = runs
    decisions: dict[str, bool | None] = {}
    decisions['naive_b1_vs_b0'] = _sn([x - y for x, y in zip(b1['blocks'], b0['blocks'])], alpha)
    for name in strata:
        p = ab.randomisation_p(b1['net'], [r['net'] for r in controls[name]])
        decisions[f'perm_{name}'] = p <= alpha
    for name in ('global', 'day'):
        series = ab.block_excess_series(b1['blocks'], [r['blocks'] for r in controls[name]])
        decisions[f'block_{name}'] = _sn(series, alpha)
    if not with_inst:
        decisions['perm_inst'] = None
    ics = _block_ic(cands, n_blocks)
    ic_series = [x for x in ics if x is not None]
    decisions['rank_ic'] = _sn(ic_series, alpha) if len(ic_series) >= 3 else False
    half = n_blocks // 2
    first = [x for x in ics[:half] if x is not None]
    second = [x for x in ics[half:] if x is not None]
    halves_positive = bool(first and second and math.fsum(first) > 0 and math.fsum(second) > 0)
    kept_minus = []
    for b in range(n_blocks):
        members = [c for c in cands if c.t_h // WEEK_H == b]
        kept = [c.outcome for c in members if c.cid in accepted_b1]
        refused = [c.outcome for c in members if c.cid not in accepted_b1]
        if kept and refused:
            kept_minus.append(math.fsum(kept) / len(kept) - math.fsum(refused) / len(refused))
    decisions['kept_minus_refused'] = _sn(kept_minus, alpha) if len(kept_minus) >= 3 else False
    decisions['h3a_primary'] = bool(decisions['rank_ic'] and decisions['block_day'] and halves_positive)
    decisions['b1_absolute'] = _sn(b1['blocks'], alpha)
    mean_ctrl = {name: math.fsum(r['net'] for r in runs) / len(runs) for name, runs in controls.items()}
    return {'decisions': decisions, 'b0_net': b0['net'], 'b1_net': b1['net'], 'br_mean_net': mean_ctrl,
            'b0_trades': b0['trades'], 'b1_trades': b1['trades'],
            'br_trades_mean': {n: math.fsum(r['trades'] for r in runs) / len(runs) for n, runs in controls.items()},
            'b1_exposure_h': b1['exposure_h'], 'br_day_exposure_h_mean':
                math.fsum(r['exposure_h'] for r in controls['day']) / len(controls['day']),
            'candidates': len(cands), 'b1_accepted': len(accepted_b1),
            'ess_ic': st.effective_sample_size(ic_series) if len(ic_series) >= 3 else None}


# ----------------------------------------------------------------------------------------------- Monte Carlo

@dataclass(frozen=True)
class StudyCell:
    population: str
    n_blocks: int
    panels: int
    k_controls: int
    alpha: float
    base_seed: int
    with_inst: bool = True

    def seed(self) -> int:
        text = f'{self.base_seed}:{self.population}:{self.n_blocks}:{self.k_controls}'
        return int(hashlib.sha256(text.encode()).hexdigest()[:12], 16)


def wilson(k: int, n: int, z: float = 1.959964) -> tuple[float, float]:
    if n == 0:
        return (0.0, 1.0)
    p = k / n
    d = 1 + z * z / n
    centre = (p + z * z / (2 * n)) / d
    half = z * math.sqrt(p * (1 - p) / n + z * z / (4 * n * n)) / d
    return (max(0.0, centre - half), min(1.0, centre + half))


def run_cell(cell: StudyCell) -> dict:
    pop = population(cell.population)
    rng = random.Random(cell.seed())
    counts = {m: 0 for m in METHODS}
    defined = {m: 0 for m in METHODS}
    sums: dict[str, float] = {'b0_net': 0.0, 'b1_net': 0.0, 'br_global_net': 0.0, 'br_day_net': 0.0,
                              'b0_trades': 0.0, 'b1_trades': 0.0, 'br_day_trades': 0.0, 'candidates': 0.0,
                              'b1_accepted': 0.0, 'b1_exposure_h': 0.0, 'br_day_exposure_h': 0.0, 'ess_ic': 0.0}
    for _ in range(cell.panels):
        cands = simulate_panel(pop, n_blocks=cell.n_blocks, rng=rng)
        r = evaluate_panel(cands, n_blocks=cell.n_blocks, k_controls=cell.k_controls, alpha=cell.alpha, rng=rng,
                           with_inst=cell.with_inst)
        for m, v in r['decisions'].items():
            if v is not None:
                defined[m] += 1
                counts[m] += bool(v)
        sums['b0_net'] += r['b0_net']
        sums['b1_net'] += r['b1_net']
        sums['br_global_net'] += r['br_mean_net']['global']
        sums['br_day_net'] += r['br_mean_net']['day']
        sums['b0_trades'] += r['b0_trades']
        sums['b1_trades'] += r['b1_trades']
        sums['br_day_trades'] += r['br_trades_mean']['day']
        sums['candidates'] += r['candidates']
        sums['b1_accepted'] += r['b1_accepted']
        sums['b1_exposure_h'] += r['b1_exposure_h']
        sums['br_day_exposure_h'] += r['br_day_exposure_h_mean']
        sums['ess_ic'] += r['ess_ic'] or 0.0
    n = cell.panels
    rates = {}
    for m in METHODS:
        k, d = counts[m], defined[m]
        if d == 0:
            rates[m] = None
            continue
        lo, hi = wilson(k, d)
        rates[m] = {'rate': k / d, 'k': k, 'n': d, 'binomial_se': math.sqrt((k / d) * (1 - k / d) / d),
                    'wilson95': [lo, hi]}
    return {'cell': asdict(cell), 'population': asdict(pop), 'seed': cell.seed(), 'rates': rates,
            'means': {k: v / n for k, v in sums.items()}, 'label': SYNTHETIC_LABEL}


# --------------------------------------------------------------------------------------- e2e demonstration

def run_e2e_random_control(seeds: Sequence[int] = (101, 102, 103), *,
                           strata: Sequence[tuple[str, str, tuple[str, ...]]] = (
                               ('glob', 'global_budget', ()), ('day', 'stratified', ('decision_day',)))) -> dict:
    """B0, B1 (fixture model) and BR on the e2e synthetic market, through the real simulator path. Technical
    demonstration only: every number is synthetic."""
    from .. import e2e
    from ..adaptive import AdaptivePolicyV1, StaticPolicy
    from ..scoring import ScoringEngine, scored_variant_readiness
    from ..scoring.fixtures import synthetic_fixture_manifest
    from ..integration import SyntheticCandidateProvider
    from ..integration.validation_adapter import RunVariantSimulator
    from .factorial import DecisionInput, build_candidate_book, run_factorial
    from .records import CostScenario

    market = e2e.synthetic_market()
    start_ms, end_ms = e2e.experiment_period()
    provider = e2e.DataCheckedCandidateProvider(
        inner=SyntheticCandidateProvider(seed=e2e.SEED, max_holding_ms=e2e.MAX_HOLDING_MS,
                                         target_r_multiple=e2e.TARGET_R), market=market)
    decisions = [DecisionInput(decision_ms=d, snapshots=e2e.snapshots_at(market, d)) for d in e2e.decision_times()]
    book = build_candidate_book(provider, decisions, start_ms=start_ms, end_ms=end_ms)
    static, adaptive = StaticPolicy(), AdaptivePolicyV1()
    fixture = synthetic_fixture_manifest()
    make_gate = e2e.gate_factory(CostScenario.BASE, adaptive.guard_params)
    manifest = e2e._manifest('h3a-br-e2e', book, fixture, static, adaptive, make_gate().policy, e2e.code_digest())
    readiness = scored_variant_readiness(fixture, manifest.datasets, manifest.protocol)
    factorial = run_factorial(manifest=manifest, book=book, market=market, scorer=ScoringEngine(e2e.COST_MODEL),
                              model=fixture, static_policy=static, adaptive_policy=adaptive,
                              risk_gate_factory=make_gate, simulator_factory=RunVariantSimulator, start_ms=start_ms,
                              end_ms=end_ms, scored_readiness=readiness)
    from ..contracts import Variant
    out: dict[str, Any] = {'label': SYNTHETIC_LABEL, 'candidates': len(book.candidates), 'controls': {}}
    results = {}
    for control_id, method, keys in strata:
        spec = ab.RandomControlSpec(control_id=control_id, method=method, strata_keys=keys, seeds=tuple(seeds))
        res = ab.run_random_control(manifest=manifest, book=book, scores=factorial.scores, market=market, spec=spec,
                                    static_policy=static, risk_gate_factory=make_gate,
                                    simulator_factory=RunVariantSimulator, start_ms=start_ms, end_ms=end_ms)
        results[control_id] = res
        out['controls'][control_id] = ab.compare_b1_to_controls(
            factorial.runs[Variant.B1], res, block_ms=e2e.DAY_MS, alpha=0.05, b0=factorial.runs[Variant.B0])
    out.update(factorial=factorial, results=results, book=book, market=market, manifest=manifest,
               make_gate=make_gate, period=(start_ms, end_ms))
    return out
