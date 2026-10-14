"""Layers A (prediction, Q1) and B (trading, Q2) of regime-r2, with comparators and statistics (§6-§8)."""

from __future__ import annotations

import warnings
from dataclasses import dataclass

import numpy as np

from .data import Market, date_of
from .detectors import _window_sum

BLOCK = 30


# ---------------------------------------------------------------------------------------------- returns

def forward_return(m: Market, h: int) -> np.ndarray:
    """r_h(t) = close(t+h) / open(t) - 1, only when every day t .. t+h is present in the segment."""
    A, T = m.close.shape
    out = np.full((A, T), np.nan)
    if T <= h:
        return out
    present = (~np.isnan(m.close)).astype(np.int64)
    cs = np.concatenate([np.zeros((A, 1), dtype=np.int64), np.cumsum(present, axis=1)], axis=1)
    full = (cs[:, h + 1:] - cs[:, :T - h]) == h + 1  # days t .. t+h all present, for t = 0 .. T-h-1
    with np.errstate(invalid="ignore", divide="ignore"):
        r = m.close[:, h:] / m.open[:, :T - h] - 1
    out[:, :T - h] = np.where(full & ~np.isnan(m.open[:, :T - h]), r, np.nan)
    return out


# ---------------------------------------------------------------------------------------------- random comparator

def chain_params(states: np.ndarray) -> tuple[np.ndarray, float]:
    """State frequencies (LONG, SHORT, NEUTRAL) and per-day switch probability q of a sequence.

    The chain stays with probability 1-q and otherwise redraws from the frequencies, so its stationary
    distribution equals the frequencies and its expected mean run length equals the observed one.
    """
    vals = np.array([1.0, -1.0, 0.0])
    pi = np.array([(states == v).mean() for v in vals]) if len(states) else np.array([0, 0, 1.0])
    runs = 1 + int((states[1:] != states[:-1]).sum()) if len(states) else 1
    mean_run = len(states) / runs if len(states) else 1.0
    concentration = 1 - float((pi ** 2).sum())
    q = 0.0 if concentration <= 0 else min(1.0, 1.0 / (mean_run * concentration))
    return pi, q


def sample_chains(pi: np.ndarray, q: float, n_days: int, n_draws: int, rng: np.random.Generator) -> np.ndarray:
    vals = np.array([1.0, -1.0, 0.0])
    out = np.empty((n_draws, n_days))
    if n_days == 0:
        return out
    cur = vals[rng.choice(3, size=n_draws, p=pi)]
    out[:, 0] = cur
    for t in range(1, n_days):
        switch = rng.random(n_draws) < q
        if switch.any():
            cur = cur.copy()
            cur[switch] = vals[rng.choice(3, size=int(switch.sum()), p=pi)]
        out[:, t] = cur
    return out


# ---------------------------------------------------------------------------------------------- statistics

def block_bootstrap_ci(series: np.ndarray, n: int, rng: np.random.Generator, block: int = BLOCK,
                       level: float = 0.90) -> tuple[float, float]:
    """Circular block bootstrap of the mean of a daily series (blocks of whole days)."""
    x = np.asarray(series, dtype=float)
    L = len(x)
    if L == 0:
        return (float("nan"), float("nan"))
    nb = int(np.ceil(L / block))
    means = np.empty(n)
    for i0 in range(0, n, 500):
        k = min(500, n - i0)
        starts = rng.integers(0, L, size=(k, nb))
        idx = (starts[:, :, None] + np.arange(block)[None, None, :]) % L
        idx = idx.reshape(k, -1)[:, :L]
        means[i0:i0 + k] = x[idx].mean(axis=1)
    lo, hi = np.quantile(means, [(1 - level) / 2, 1 - (1 - level) / 2])
    return float(lo), float(hi)


# ---------------------------------------------------------------------------------------------- layer A

@dataclass
class LayerA:
    score: float
    daily: np.ndarray        # (T,) daily cross-sectional score, NaN when no non-neutral decision
    rand_scores: np.ndarray  # (N,) score of each random draw
    rand_daily: np.ndarray   # (T,) mean over draws of the daily random score


def daily_score(state: np.ndarray, ret: np.ndarray, elig: np.ndarray) -> np.ndarray:
    use = elig & (state != 0) & ~np.isnan(state) & ~np.isnan(ret)
    num = np.where(use, state * ret, 0.0).sum(axis=0)
    cnt = use.sum(axis=0)
    return np.where(cnt > 0, num / np.maximum(cnt, 1), np.nan)


def layer_a(state: np.ndarray, ret: np.ndarray, elig: np.ndarray, n_draws: int, rng: np.random.Generator,
            chains: dict | None = None) -> LayerA:
    """Detector score and its matched random comparator on the eligible (asset, day) set."""
    base = elig & ~np.isnan(state) & ~np.isnan(ret)
    d = daily_score(state, ret, base)
    score = float(np.nanmean(d)) if np.isfinite(d).any() else float("nan")
    A, T = state.shape
    sums = np.zeros((n_draws, T))
    cnts = np.zeros((n_draws, T))
    for a in range(A):
        cols = np.where(base[a])[0]
        if len(cols) == 0:
            continue
        if chains is not None and a in chains:
            draws = chains[a]
        else:
            pi, q = chain_params(state[a, cols])
            draws = sample_chains(pi, q, len(cols), n_draws, rng)
        r = ret[a, cols]
        nz = draws != 0
        sums[:, cols] += np.where(nz, draws * r[None, :], 0.0)
        cnts[:, cols] += nz
    with np.errstate(invalid="ignore"):
        rd = np.where(cnts > 0, sums / np.maximum(cnts, 1), np.nan)
    rs = np.array([np.nanmean(row) if np.isfinite(row).any() else np.nan for row in rd])
    with warnings.catch_warnings():
        warnings.simplefilter("ignore", RuntimeWarning)  # days without any random decision stay NaN
        rmean = np.nanmean(rd, axis=0) if n_draws else np.full(T, np.nan)
    return LayerA(score, d, rs, rmean)


def deterministic_score(state: np.ndarray, ret: np.ndarray, elig: np.ndarray) -> float:
    d = daily_score(state, ret, elig & ~np.isnan(state) & ~np.isnan(ret))
    return float(np.nanmean(d)) if np.isfinite(d).any() else float("nan")


# ---------------------------------------------------------------------------------------------- layer B

def side_cost(symbols: list[str], profile: str) -> np.ndarray:
    """Cost per side as a rate: 5 bp fee + half-spread 1 bp (BTC, ETH) or 3 bp; adverse x1.5."""
    hs = np.array([1.0 if s in ("BTCUSDT", "ETHUSDT") else 3.0 for s in symbols])
    c = (5.0 + hs) * 1e-4
    return c * (1.5 if profile == "adverse" else 1.0)


def _ffill(x: np.ndarray) -> np.ndarray:
    A, T = x.shape
    idx = np.where(~np.isnan(x), np.arange(T)[None, :], 0)
    np.maximum.accumulate(idx, axis=1, out=idx)
    return x[np.arange(A)[:, None], idx]  # NaN before the first valid value (x[:, 0] is then NaN)


@dataclass
class Book:
    """Pre-computed position increments for the 14-tranche simulation."""

    inc: list[np.ndarray]     # k = 0..14: (A, T) price increment of day t+k for a position opened at t, / open(t)
    fund: np.ndarray          # (A, T) funding settled on day d (missing -> 0, counted)
    tradable: np.ndarray      # (A, T) perp open available at t
    missing_funding_days: int


def build_book(m: Market, horizon: int = 14) -> Book:
    po, pc = m.perp_open, m.perp_close
    pcf = _ffill(pc)
    A, T = po.shape
    inc = []
    with np.errstate(invalid="ignore", divide="ignore"):
        for k in range(horizon + 1):
            cur = np.full((A, T), np.nan)
            if k == 0:
                cur = (pcf - po) / po
            elif k < horizon:
                cur[:, :T - k] = (pcf[:, k:] - pcf[:, k - 1:T - 1]) / po[:, :T - k]
            else:
                exit_open = np.where(np.isnan(po), pcf, po)  # exit at the next open; last known price if absent
                prev_close = pcf
                cur[:, :T - k] = (exit_open[:, k:] - prev_close[:, k - 1:T - 1]) / po[:, :T - k]
            inc.append(np.where(np.isnan(cur), 0.0, cur))
    tradable = ~np.isnan(po)
    listed = ~np.isnan(pcf)
    missing = int((listed & (m.fund_cnt == 0)).sum())
    fund = np.where(m.fund_cnt > 0, m.fund_sum, 0.0)
    return Book(inc, fund, tradable, missing)


def simulate(state: np.ndarray, open_mask: np.ndarray, book: Book, costs: np.ndarray, horizon: int = 14) -> dict:
    """Daily PnL of the 14-tranche portfolio (capital 1, no compounding, no leverage).

    open_mask (A, T): assets in the basket on day t (they share the tranche equally; NEUTRAL stays cash).
    """
    rows = np.where(open_mask.any(axis=1))[0]  # only assets that ever enter the basket
    state, open_mask, costs = state[rows], open_mask[rows], costs[rows]
    book = Book([x[rows] for x in book.inc], book.fund[rows], book.tradable[rows], book.missing_funding_days)
    A, T = state.shape
    n = open_mask.sum(axis=0)
    w = np.where(n > 0, 1.0 / np.maximum(n, 1), 0.0) / horizon  # per-asset notional of the tranche of day t
    s = np.where(open_mask & ~np.isnan(state), state, 0.0)
    ws = s * w[None, :]
    pnl = np.zeros(T)
    for k in range(horizon + 1):
        contrib = (ws * book.inc[k]).sum(axis=0)
        pnl[k:] += contrib[:T - k] if k else contrib
    for k in range(horizon):  # funding of days t .. t+13; a long pays a positive rate
        contrib = -(ws[:, :T - k] * book.fund[:, k:]).sum(axis=0) if k else -(ws * book.fund).sum(axis=0)
        pnl[k:] += contrib
    cost = (np.abs(s) * w[None, :] * costs[:, None]).sum(axis=0)
    pnl -= cost                                  # entry
    pnl[horizon:] -= cost[:T - horizon]          # exit
    exposure = np.where(n > 0, (s != 0).sum(axis=0) / np.maximum(n, 1), np.nan)
    return {"pnl": pnl, "exposure": exposure, "tranches": int((n > 0).sum())}


def metrics(pnl: np.ndarray, days: np.ndarray, lo: int, hi: int, exposure: np.ndarray) -> dict:
    """Metrics of the PnL of days lo .. hi (inclusive)."""
    p = pnl[lo:hi + 1]
    eq = 1.0 + np.cumsum(p)
    peak = np.maximum.accumulate(np.concatenate([[1.0], eq]))[1:]
    dd = float((peak - eq).max()) if len(eq) else 0.0
    years = len(p) / 365.25
    net = float(p.sum())
    ann = net / years if years > 0 else float("nan")
    by_year = {}
    for i, v in enumerate(p):
        y = date_of(days[lo + i]).year
        by_year[y] = by_year.get(y, 0.0) + float(v)
    ex = exposure[lo:hi + 1]
    return {"net": net, "annualized": ann, "max_dd": dd, "calmar": ann / dd if dd > 0 else float("inf"),
            "exposure": float(np.nanmean(ex)) if np.isfinite(ex).any() else 0.0, "by_year": by_year}
