"""Regime-matched random-timing controls (PREREGISTRATION.md, section 5).

For a configuration, a control keeps the regime filter, the exits and the admission rule, and
replaces the setup's timing by random decision bars drawn inside the regime, matched to the
candidate's trade count per pair and year (twice as many draws, since the admission rule then
thins them). Several seeds give the control distribution of the mean net R.
"""

from __future__ import annotations

import numpy as np

from . import costs
from .engine import OUTCOME_DTYPE, PairInputs, Window, configuration_signals, decision_indices, run_pair
from .grid import Configuration
from .setups import SetupSignals
from .stats import summarize, years_of

OVERSAMPLE = 2


def _regime_signals(signals: SetupSignals) -> SetupSignals:
    return SetupSignals(signals.regime.copy(), signals.regime, signals.exit)


def sample_decisions(regime_indices: np.ndarray, regime_years: np.ndarray, counts_by_year: dict[int, int], rng: np.random.Generator) -> np.ndarray:
    chosen = []
    for year, count in counts_by_year.items():
        pool = regime_indices[regime_years == year]
        if pool.size == 0 or count <= 0:
            continue
        take = min(pool.size, OVERSAMPLE * count)
        chosen.append(rng.choice(pool, size=take, replace=False))
    if not chosen:
        return np.zeros(0, dtype=np.int64)
    return np.sort(np.concatenate(chosen))


def run_control(
    configuration: Configuration,
    pairs: dict[str, PairInputs],
    window: Window,
    profiles: dict[str, costs.CostProfile],
    candidate_outcomes: np.ndarray,
    seed: int,
) -> np.ndarray:
    rng = np.random.default_rng(seed)
    pieces = []
    candidate_years = years_of(candidate_outcomes["decision_ms"]) if candidate_outcomes.size else np.zeros(0, dtype=int)
    for symbol, inputs in pairs.items():
        mask = candidate_outcomes["symbol"] == symbol
        if not mask.any():
            continue
        years, counts = np.unique(candidate_years[mask], return_counts=True)
        counts_by_year = {int(year): int(count) for year, count in zip(years, counts)}
        features = inputs.features[configuration.cadence]
        signals = configuration_signals(configuration, features)
        regime = _regime_signals(signals)
        regime_indices = decision_indices(features, regime, window)
        regime_years = years_of(features.bars.close_ms[regime_indices])
        decisions = sample_decisions(regime_indices, regime_years, counts_by_year, rng)
        run = run_pair(configuration, inputs, window, profiles, decisions=decisions, signals=regime)
        pieces.append(run.outcomes)
    if not pieces:
        return np.zeros(0, dtype=OUTCOME_DTYPE)
    outcomes = np.concatenate(pieces)
    return outcomes[np.argsort(outcomes["decision_ms"], kind="stable")]


def control_distribution(
    configuration: Configuration,
    pairs: dict[str, PairInputs],
    window: Window,
    profiles: dict[str, costs.CostProfile],
    candidate_outcomes: np.ndarray,
    seeds: int,
) -> dict:
    mean_r: list[float] = []
    mean_r_adverse: list[float] = []
    profit_factors: list[float | None] = []
    trades: list[int] = []
    for seed in range(seeds):
        outcomes = run_control(configuration, pairs, window, profiles, candidate_outcomes, seed)
        base = summarize(outcomes, "base")
        adverse = summarize(outcomes, "adverse")
        trades.append(base["trades"])
        if base["trades"] == 0:
            continue
        mean_r.append(base["mean_r"])
        mean_r_adverse.append(adverse["mean_r"])
        profit_factors.append(base["profit_factor"])
    values = np.asarray(mean_r)
    return {
        "seeds": seeds,
        "trades": trades,
        "mean_r": mean_r,
        "mean_r_adverse": mean_r_adverse,
        "profit_factor": profit_factors,
        "median": float(np.median(values)) if values.size else None,
        "p95": float(np.quantile(values, 0.95)) if values.size else None,
        "max": float(values.max()) if values.size else None,
    }
