"""scalp-q3-r2 controls and statistics (PREREGISTRATION-scalp-q3-r2.md §7, §8, §11).

- `shift_episodes`: the main random control. Each episode (maximal run of consecutive decision
  seconds) of the candidate is moved to a start drawn uniformly among the series' seconds with
  the same UTC hour as its start, keeping its length (truncated at the series end).
- `band_state_draws`: the H1 control. Seconds of the same series in the same band (and depth)
  state but without the buy flow, drawn per stratum (hour × normalised deviation × liquidity)
  as many times as the candidate placed orders in that stratum.
- Block bootstrap by UTC day (all pairs of a day together), p-values against the seeds, tails,
  streaks and the summary rows of the report.
"""

from __future__ import annotations

import numpy as np

SEED_BASE = 20261014
BOOT_RESAMPLES = 5000
Z_EDGES = (2.0, 3.0)
STALE_EDGES = (0.05, 0.20)


def rng_for(*keys: int) -> np.random.Generator:
    return np.random.default_rng([SEED_BASE, *[int(k) for k in keys]])


def hour_of(start_s: int, n: int) -> np.ndarray:
    return ((start_s + np.arange(n, dtype=np.int64)) // 3600) % 24


def hour_pools(start_s: int, n: int) -> list[np.ndarray]:
    hours = hour_of(start_s, n)
    return [np.flatnonzero(hours == h) for h in range(24)]


def episodes(signal: np.ndarray) -> tuple[np.ndarray, np.ndarray]:
    """Starts and lengths of the maximal runs of consecutive seconds of a sorted unique array."""
    signal = np.asarray(signal, dtype=np.int64)
    if signal.size == 0:
        return np.zeros(0, dtype=np.int64), np.zeros(0, dtype=np.int64)
    breaks = np.flatnonzero(np.diff(signal) != 1) + 1
    starts_idx = np.concatenate([[0], breaks])
    ends_idx = np.concatenate([breaks, [signal.size]])
    return signal[starts_idx], ends_idx - starts_idx


def expand(starts: np.ndarray, lengths: np.ndarray) -> np.ndarray:
    total = int(lengths.sum())
    if total == 0:
        return np.zeros(0, dtype=np.int64)
    offsets = np.arange(total, dtype=np.int64) - np.repeat(np.cumsum(lengths) - lengths, lengths)
    return np.unique(np.repeat(starts, lengths) + offsets)


def shift_episodes(signal: np.ndarray, start_s: int, n: int, rng: np.random.Generator, pools: list[np.ndarray] | None = None) -> np.ndarray:
    starts, lengths = episodes(signal)
    if starts.size == 0:
        return starts
    pools = pools if pools is not None else hour_pools(start_s, n)
    hours = (start_s + starts) // 3600 % 24
    new = np.empty_like(starts)
    for h in np.unique(hours).tolist():
        sel = hours == h
        new[sel] = rng.choice(pools[h], size=int(sel.sum()), replace=True)
    lengths = np.minimum(lengths, n - new)
    return expand(new, lengths)


def strata(hours: np.ndarray, z: np.ndarray, stale: np.ndarray) -> np.ndarray:
    """hour × z bucket ([1.5, 2), [2, 3), ≥ 3) × stale_900 bucket (< 5 %, 5–20 %, ≥ 20 %)."""
    zb = np.searchsorted(np.asarray(Z_EDGES), z, side="right")
    sb = np.searchsorted(np.asarray(STALE_EDGES), np.where(np.isfinite(stale), stale, 1.0), side="right")
    return (hours * 9 + zb * 3 + sb).astype(np.int16)


def band_state_draws(pool_s: np.ndarray, pool_stratum: np.ndarray, order_strata: np.ndarray, rng: np.random.Generator) -> tuple[np.ndarray, int]:
    """Seconds drawn from the pool, as many per stratum as the candidate's orders; unmatched count."""
    picks, unmatched = [], 0
    for st, count in zip(*np.unique(order_strata, return_counts=True)):
        pool = pool_s[pool_stratum == st]
        if pool.size == 0:
            unmatched += int(count)
            continue
        picks.append(rng.choice(pool, size=int(count), replace=True))
    if not picks:
        return np.zeros(0, dtype=np.int64), unmatched
    return np.unique(np.concatenate(picks)), unmatched


def p_value(candidate: float, controls: np.ndarray) -> float | None:
    controls = np.asarray(controls, dtype=np.float64)
    controls = controls[np.isfinite(controls)]
    if controls.size == 0 or not np.isfinite(candidate):
        return None
    return float((1 + np.sum(controls >= candidate)) / (1 + controls.size))


def bootstrap_surplus(cand_sum: np.ndarray, cand_cnt: np.ndarray, ctrl_sum: np.ndarray, ctrl_cnt: np.ndarray, resamples: int = BOOT_RESAMPLES, seed: int = SEED_BASE,
                      blocks: np.ndarray | None = None) -> dict:
    """Per-block (day) sums and counts of the candidate and of the pooled controls; resample the
    blocks with replacement; surplus = candidate mean − control mean. `blocks` groups days into
    coarser blocks (windows) when given."""
    cs, cc, ks, kc = (np.asarray(a, dtype=np.float64) for a in (cand_sum, cand_cnt, ctrl_sum, ctrl_cnt))
    if blocks is not None:
        ids, inv = np.unique(blocks, return_inverse=True)
        cs, cc, ks, kc = (np.bincount(inv, weights=a, minlength=ids.size) for a in (cs, cc, ks, kc))
    m = cs.size
    rng = np.random.default_rng(seed)
    draws = rng.integers(0, m, size=(resamples, m))
    weights = np.zeros((resamples, m))
    np.add.at(weights, (np.repeat(np.arange(resamples), m), draws.ravel()), 1.0)
    with np.errstate(invalid="ignore", divide="ignore"):
        cand = (weights @ cs) / (weights @ cc)
        ctrl = (weights @ ks) / (weights @ kc)
        surplus = cand - ctrl
    surplus = surplus[np.isfinite(surplus)]
    point = (cs.sum() / cc.sum() if cc.sum() else np.nan) - (ks.sum() / kc.sum() if kc.sum() else np.nan)
    if surplus.size == 0:
        return {"point": _f(point), "ci90": [None, None], "ci95": [None, None], "blocks": int(m)}
    return {"point": _f(point), "ci90": [_f(np.quantile(surplus, 0.05)), _f(np.quantile(surplus, 0.95))],
            "ci95": [_f(np.quantile(surplus, 0.025)), _f(np.quantile(surplus, 0.975))], "blocks": int(m)}


def bootstrap_mean(day_sum: np.ndarray, day_cnt: np.ndarray, resamples: int = BOOT_RESAMPLES, seed: int = SEED_BASE) -> list:
    zero = np.zeros_like(np.asarray(day_sum, dtype=np.float64))
    out = bootstrap_surplus(day_sum, day_cnt, zero, np.ones_like(zero), resamples, seed)
    return out["ci90"]


def bootstrap_total(day_values: np.ndarray, resamples: int = BOOT_RESAMPLES, seed: int = SEED_BASE) -> dict:
    """CI of a total (sum over days) by resampling days: portfolio PnL differences."""
    v = np.asarray(day_values, dtype=np.float64)
    if v.size == 0:
        return {"point": None, "ci90": [None, None]}
    rng = np.random.default_rng(seed)
    draws = rng.integers(0, v.size, size=(resamples, v.size))
    totals = v[draws].sum(axis=1)
    return {"point": _f(v.sum()), "ci90": [_f(np.quantile(totals, 0.05)), _f(np.quantile(totals, 0.95))], "ci95": [_f(np.quantile(totals, 0.025)), _f(np.quantile(totals, 0.975))]}


def _f(value) -> float | None:
    value = float(value)
    return value if np.isfinite(value) else None


def longest_streak(losing: np.ndarray) -> int:
    best = cur = 0
    for x in np.asarray(losing, dtype=bool).tolist():
        cur = cur + 1 if x else 0
        best = max(best, cur)
    return best


def tails(net: np.ndarray, order_key: np.ndarray | None = None) -> dict:
    net = np.asarray(net, dtype=np.float64)
    if net.size == 0:
        return {"trades": 0}
    srt = np.sort(net)
    k = max(1, int(round(0.05 * net.size)))
    total = float(net.sum())
    chrono = net if order_key is None else net[np.argsort(order_key, kind="stable")]
    losses = -net[net < 0]
    return {
        "trades": int(net.size), "mean_bp": float(net.mean()), "sum_bp": total,
        "quantiles_bp": {str(q): float(np.quantile(net, q / 100)) for q in (1, 5, 50, 95, 99)},
        "loss_p95_bp": _f(np.quantile(losses, 0.95)) if losses.size else None, "loss_p99_bp": _f(np.quantile(losses, 0.99)) if losses.size else None,
        "worst_bp": float(srt[0]), "best_bp": float(srt[-1]),
        "top5_sum_bp": float(srt[-k:].sum()), "bottom5_sum_bp": float(srt[:k].sum()),
        "top5_share_of_total": _f(srt[-k:].sum() / total) if total else None,
        "without_top5_mean_bp": _f(srt[:-k].mean()) if net.size > k else None,
        "without_bottom5_mean_bp": _f(srt[k:].mean()) if net.size > k else None,
        "trimmed_5_5_mean_bp": _f(srt[k:-k].mean()) if net.size > 2 * k else None,
        "longest_loss_streak": longest_streak(chrono < 0),
    }


def signal_summary(net: np.ndarray, gross: np.ndarray, fees: np.ndarray) -> dict:
    net = np.asarray(net, dtype=np.float64)
    if net.size == 0:
        return {"trades": 0, "mean_net_bp": None, "profit_factor": None}
    gains, losses = net[net > 0].sum(), -net[net < 0].sum()
    return {"trades": int(net.size), "mean_net_bp": float(net.mean()), "median_net_bp": float(np.median(net)), "mean_gross_bp": float(np.mean(gross)),
            "mean_fees_bp": float(np.mean(fees)), "win_rate": float((net > 0).mean()), "profit_factor": _f(gains / losses) if losses > 0 else None, "std_bp": float(net.std())}


def max_drawdown(equity: np.ndarray) -> tuple[float, float]:
    """Largest peak-to-trough fall (absolute, and relative to the running peak)."""
    equity = np.asarray(equity, dtype=np.float64)
    if equity.size == 0:
        return 0.0, 0.0
    peak = np.maximum.accumulate(equity)
    dd = peak - equity
    rel = np.where(peak > 0, dd / peak, 0.0)
    return float(dd.max()), float(rel.max())
