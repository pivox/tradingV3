"""Fully synthetic power analysis of the primary criterion under block dependence (C6, C8). Nothing here is evidence
about any market: every number it produces describes the test procedure on invented panels.

Generative model of one panel (all outcomes in R, 1 R = the nominal risk of a trade = ``risk_fraction`` of capital):
- a block regime factor f_b, AR(1) with coefficient ``phi`` and unit variance (weeks are not independent);
- m_b ~ Poisson(``trades_per_block``) candidates in block b, traded by both B0 and B3 (paired);
- B0 outcome: R0 = mu0 + sigma_r * (sqrt(rho) * f_b + sqrt(1 - rho) * e), e a unit-variance shock (normal or
  Student t with ``tail_df`` degrees of freedom);
- B3 - B0 difference: D = delta + kappa * f_b + tau * u, u another unit-variance shock: ``delta`` is the true
  improvement per trade, ``kappa`` makes the improvement depend on the regime (adaptive exits help in some regimes
  and hurt in others), ``tau`` is the idiosyncratic part;
- per-block series: P1 uses risk_fraction * sum(D) (B3 - B0), P2 uses risk_fraction * sum(R0 + D) (B3 vs cash).
The primary decision is the intersection-union of the two one-sided tests of the chosen procedure (``stats``:
``cbb_percentile`` in study 1; ``sn_gaussian`` and ``batch_t`` in study 2). The naive
trade-level test (every difference treated as independent) is computed on the same panels to measure how far it
departs from its nominal level when trades share block shocks.

Cells use common random numbers across ``delta`` (the cell seed ignores delta and mu0), so power curves are paired.
"""
from __future__ import annotations

import hashlib
import math
import random
from collections.abc import Iterable, Sequence
from dataclasses import asdict, dataclass

from . import stats as st


@dataclass(frozen=True)
class Scenario:
    name: str
    kappa: float                 # sd (R) of the regime-driven part of the per-trade difference
    phi: float                   # AR(1) coefficient of the block regime factor
    tail_df: int | None          # Student t degrees of freedom of trade shocks; None = normal
    tau: float = 0.75            # sd (R) of the idiosyncratic part of the per-trade difference
    sigma_r: float = 1.0         # sd (R) of a B0 trade outcome
    rho: float = 0.2             # share of the B0 outcome variance carried by the block regime


SCENARIOS: tuple[Scenario, ...] = (
    Scenario('iid_normal', kappa=0.0, phi=0.0, tail_df=None),
    Scenario('regime_normal', kappa=0.3, phi=0.5, tail_df=None),
    Scenario('regime_t3', kappa=0.3, phi=0.5, tail_df=3),
    Scenario('strong_cluster_t3', kappa=0.6, phi=0.7, tail_df=3),
)


@dataclass(frozen=True)
class Cell:
    scenario: Scenario
    delta_r: float
    mu0_r: float
    trades_per_block: float
    n_blocks: int
    panels: int
    alpha: float
    resamples: int
    base_seed: int
    risk_fraction: float = 0.005
    procedures: tuple[str, ...] = ('cbb_percentile',)

    def seed(self) -> int:
        text = f'{self.base_seed}:{self.scenario.name}:{self.trades_per_block}:{self.n_blocks}'
        return int(hashlib.sha256(text.encode()).hexdigest()[:12], 16)


def _shock(rng: random.Random, df: int | None) -> float:
    z = rng.gauss(0.0, 1.0)
    if df is None:
        return z
    chi2 = rng.gammavariate(df / 2.0, 2.0)
    return z / math.sqrt(chi2 / df) * math.sqrt((df - 2.0) / df)


def _poisson(rng: random.Random, lam: float) -> int:
    limit, k, p = math.exp(-lam), 0, 1.0
    while True:
        p *= rng.random()
        if p <= limit:
            return k
        k += 1


def simulate_panel(scenario: Scenario, *, delta_r: float, mu0_r: float, trades_per_block: float, n_blocks: int,
                   risk_fraction: float, rng: random.Random) -> tuple[list[float], list[float], list[float]]:
    """Returns (per-block B3 - B0 series, per-block B3 series, per-trade differences), as capital fractions for the
    block series and in R for the trades."""
    s = scenario
    f = rng.gauss(0.0, 1.0)
    innovation = math.sqrt(max(0.0, 1.0 - s.phi * s.phi))
    a, b = math.sqrt(s.rho), math.sqrt(1.0 - s.rho)
    block_delta, block_b3, trade_delta = [], [], []
    for block in range(n_blocks):
        if block:
            f = s.phi * f + innovation * rng.gauss(0.0, 1.0)
        total_d = total_b3 = 0.0
        for _ in range(_poisson(rng, trades_per_block)):
            r0 = mu0_r + s.sigma_r * (a * f + b * _shock(rng, s.tail_df))
            d = delta_r + s.kappa * f + s.tau * _shock(rng, s.tail_df)
            total_d += d
            total_b3 += r0 + d
            trade_delta.append(d)
        block_delta.append(risk_fraction * total_d)
        block_b3.append(risk_fraction * total_b3)
    return block_delta, block_b3, trade_delta


def run_cell(cell: Cell) -> dict:
    """Rejection rates of every procedure in ``cell.procedures`` on the same panels. The panel stream (simulation and
    the two bootstrap seeds per panel) does not depend on the procedures, so cells sharing a seed share panels."""
    rng = random.Random(cell.seed())
    counts = {p: [0, 0, 0] for p in cell.procedures}
    naive = 0
    ess_total = 0.0
    lag1_total = 0.0
    lag1_count = 0
    for _ in range(cell.panels):
        block_delta, block_b3, trade_delta = simulate_panel(
            cell.scenario, delta_r=cell.delta_r, mu0_r=cell.mu0_r, trades_per_block=cell.trades_per_block,
            n_blocks=cell.n_blocks, risk_fraction=cell.risk_fraction, rng=rng)
        seed1, seed2 = rng.getrandbits(32), rng.getrandbits(32)
        for procedure in cell.procedures:
            t1 = st.one_sided_test(block_delta, procedure=procedure, alpha=cell.alpha, resamples=cell.resamples,
                                   seed=seed1)
            t2 = st.one_sided_test(block_b3, procedure=procedure, alpha=cell.alpha, resamples=cell.resamples,
                                   seed=seed2)
            tally = counts[procedure]
            tally[0] += t1.rejected
            tally[1] += t2.rejected
            tally[2] += t1.rejected and t2.rejected
        naive_p = st.naive_iid_one_sided_p(trade_delta) if len(trade_delta) > 1 else None
        naive += naive_p is not None and naive_p <= cell.alpha
        ess_total += st.effective_sample_size(block_delta) or 0.0
        rho1 = st.autocorrelation(block_delta, 1)
        if rho1 is not None:
            lag1_total += rho1
            lag1_count += 1
    n = cell.panels
    row = {
        'scenario': cell.scenario.name, 'delta_r': cell.delta_r, 'mu0_r': cell.mu0_r,
        'trades_per_block': cell.trades_per_block, 'n_blocks': cell.n_blocks, 'panels': n,
        'alpha': cell.alpha, 'resamples': cell.resamples, 'seed': cell.seed(),
        'rate_naive_trade_level': naive / n, 'mean_effective_blocks': ess_total / n,
        'mean_lag1_autocorrelation': (lag1_total / lag1_count) if lag1_count else None,
        'delta_per_block_capital_fraction': cell.delta_r * cell.trades_per_block * cell.risk_fraction,
        'procedures': list(cell.procedures), 'synthetic': True,
    }
    for procedure, (p1, p2, iut) in counts.items():
        row[f'rate_p1__{procedure}'] = p1 / n
        row[f'rate_p2__{procedure}'] = p2 / n
        row[f'rate_iut__{procedure}'] = iut / n
    if 'cbb_percentile' in counts:   # study-1 key names
        row['rate_p1_b3_vs_b0'] = row['rate_p1__cbb_percentile']
        row['rate_p2_b3_vs_cash'] = row['rate_p2__cbb_percentile']
        row['rate_primary_iut'] = row['rate_iut__cbb_percentile']
    return row


def run_grid(cells: Sequence[Cell], workers: int = 1) -> list[dict]:
    if workers <= 1:
        return [run_cell(c) for c in cells]
    import multiprocessing
    with multiprocessing.get_context('spawn').Pool(workers) as pool:
        return pool.map(run_cell, cells, chunksize=1)


def build_cells(*, scenarios: Iterable[Scenario], deltas: Iterable[float], trades_per_block: Iterable[float],
                n_blocks: Iterable[int], panels: int, alpha: float, resamples: int, base_seed: int,
                mu0_r: float = 0.0, procedures: tuple[str, ...] = ('cbb_percentile',)) -> list[Cell]:
    return [Cell(s, d, mu0_r, lam, n, panels, alpha, resamples, base_seed, procedures=procedures)
            for s in scenarios for lam in trades_per_block for n in n_blocks for d in deltas]


def minimal_detectable_effect(rows: Sequence[dict], *, target: float = 0.8, rate: str = 'rate_primary_iut') -> list[
        dict]:
    """Per (scenario, trades_per_block, n_blocks): the smallest grid delta whose rate reaches ``target`` and a
    linear interpolation between the grid points around it (labelled interpolated)."""
    groups: dict[tuple, list[dict]] = {}
    for row in rows:
        if row['mu0_r'] != 0.0:
            continue
        groups.setdefault((row['scenario'], row['trades_per_block'], row['n_blocks']), []).append(row)
    out = []
    for key, members in sorted(groups.items()):
        members.sort(key=lambda r: r['delta_r'])
        grid, interpolated = None, None
        for prev, cur in zip([None] + members[:-1], members):
            if cur[rate] >= target:
                grid = cur['delta_r']
                if prev is not None and cur[rate] > prev[rate]:
                    w = (target - prev[rate]) / (cur[rate] - prev[rate])
                    interpolated = prev['delta_r'] + w * (cur['delta_r'] - prev['delta_r'])
                else:
                    interpolated = grid
                break
        out.append({'scenario': key[0], 'trades_per_block': key[1], 'n_blocks': key[2], 'target': target,
                    'mde_grid_delta_r': grid, 'mde_interpolated_delta_r': interpolated, 'rate': rate})
    return out


def scenarios_document() -> list[dict]:
    return [asdict(s) for s in SCENARIOS]
