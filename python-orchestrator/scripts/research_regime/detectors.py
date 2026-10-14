"""Detectors D1-D6 of regime-r2 (§5). State matrices (A, T): +1 LONG, -1 SHORT, 0 NEUTRAL, NaN ineligible.

The decision of day t uses data up to the close of day t-1 only: every window below ends at t-1.
Windows require every day present (no reconstruction): a missing day makes the decision ineligible.
"""

from __future__ import annotations

import numpy as np

from .data import Market


def _window_sum(x: np.ndarray, n: int) -> tuple[np.ndarray, np.ndarray]:
    """Sum and count of valid values of x over days [t-n, t-1], for every t (columns)."""
    A, T = x.shape
    v = np.where(np.isnan(x), 0.0, x)
    c = (~np.isnan(x)).astype(np.int32)
    cs = np.concatenate([np.zeros((A, 1)), np.cumsum(v, axis=1)], axis=1)
    cc = np.concatenate([np.zeros((A, 1), dtype=np.int64), np.cumsum(c, axis=1)], axis=1)
    s = np.full((A, T), np.nan)
    k = np.zeros((A, T), dtype=np.int64)
    if T > n:
        s[:, n:] = cs[:, n:T] - cs[:, 0:T - n]
        k[:, n:] = cc[:, n:T] - cc[:, 0:T - n]
    return s, k


def full_mean(x: np.ndarray, n: int) -> np.ndarray:
    """Mean over [t-n, t-1] when all n days are present, else NaN."""
    s, k = _window_sum(x, n)
    return np.where(k == n, s / n, np.nan)


def prev(x: np.ndarray, lag: int = 1) -> np.ndarray:
    """x[:, t-lag] placed at column t."""
    out = np.full_like(x, np.nan)
    out[:, lag:] = x[:, :-lag]
    return out


def _side(cond_long: np.ndarray, cond_short: np.ndarray, valid: np.ndarray) -> np.ndarray:
    s = np.where(cond_long, 1.0, np.where(cond_short, -1.0, 0.0))
    return np.where(valid, s, np.nan)


def d1_ma200(m: Market) -> np.ndarray:
    sma = full_mean(m.close, 200)
    x = prev(m.close)
    valid = ~np.isnan(sma) & ~np.isnan(x)
    with np.errstate(invalid="ignore"):
        return _side(x > sma * 1.02, x < sma * 0.98, valid)


def simple_trend(m: Market) -> np.ndarray:
    """Comparator: sign(close - SMA200), no neutral band."""
    sma = full_mean(m.close, 200)
    x = prev(m.close)
    valid = ~np.isnan(sma) & ~np.isnan(x)
    with np.errstate(invalid="ignore"):
        return _side(x > sma, x < sma, valid)


def d2_momentum(m: Market) -> np.ndarray:
    _, k = _window_sum(m.close, 85)  # closes of t-85 .. t-1 all present
    x = prev(m.close)
    r28 = x / prev(m.close, 29) - 1
    r84 = x / prev(m.close, 85) - 1
    valid = k == 85
    with np.errstate(invalid="ignore"):
        return _side((r28 > 0) & (r84 > 0), (r28 < 0) & (r84 < 0), valid)


def d3_breadth(m: Market, u50: np.ndarray) -> tuple[np.ndarray, np.ndarray]:
    """Market-wide state from the share of U50(t) members above their SMA50; same state for every asset."""
    sma50 = full_mean(m.close, 50)
    x = prev(m.close)
    ok = u50 & ~np.isnan(sma50) & ~np.isnan(x)
    with np.errstate(invalid="ignore"):
        above = (x > sma50) & ok
    n = ok.sum(axis=0)
    share = np.where(n > 0, above.sum(axis=0) / np.maximum(n, 1), np.nan)
    state = np.where(np.isnan(share), np.nan, np.where(share > 0.60, 1.0, np.where(share < 0.40, -1.0, 0.0)))
    alive = ~np.isnan(x)
    return np.where(alive, state[None, :], np.nan), share


def d4_funding(m: Market, rows: np.ndarray | None = None) -> np.ndarray:
    """Contrarian funding z-score: 7-day mean funding against its own 365 previous 7-day means."""
    A, T = m.close.shape
    out = np.full((A, T), np.nan)
    fsum, fcnt = m.fund_sum, m.fund_cnt.astype(float)
    have = fcnt > 0
    s7, _ = _window_sum(np.where(have, fsum, np.nan), 7)
    c7, _ = _window_sum(np.where(have, fcnt, np.nan), 7)
    _, days7 = _window_sum(np.where(have, 1.0, np.nan), 7)
    f7 = np.where(days7 == 7, s7 / np.where(c7 > 0, c7, 1), np.nan)  # known at t (days t-7 .. t-1)
    rows = range(A) if rows is None else rows
    for a in rows:
        series = f7[a]
        for t in range(365, T):
            cur = series[t]
            if np.isnan(cur):
                continue
            hist = series[t - 365:t]
            if np.isnan(hist).any():
                continue
            sd = hist.std()
            if sd == 0:
                continue
            z = (cur - hist.mean()) / sd
            out[a, t] = -1.0 if z > 1.5 else (1.0 if z < -1.5 else 0.0)
    return out


def d5_volatility(m: Market, d2: np.ndarray, rows: np.ndarray | None = None) -> np.ndarray:
    """NEUTRAL when 30-day realized volatility is above the 80th percentile of its previous 365 values, else D2."""
    A, T = m.close.shape
    with np.errstate(invalid="ignore", divide="ignore"):
        lr = np.log(m.close / prev(m.close))  # return of day d, known at the close of d
    vs, vk = _window_sum(lr, 30)
    vq, _ = _window_sum(lr * lr, 30)
    with np.errstate(invalid="ignore"):
        var = (vq - vs * vs / 30) / 29
    vol = np.where(vk == 30, np.sqrt(np.maximum(var, 0)), np.nan)  # uses returns of t-30 .. t-1
    out = np.full((A, T), np.nan)
    rows = range(A) if rows is None else rows
    for a in rows:
        for t in range(365, T):
            cur = vol[a, t]
            if np.isnan(cur) or np.isnan(d2[a, t]):
                continue
            hist = vol[a, t - 365:t]
            if np.isnan(hist).any():
                continue
            out[a, t] = 0.0 if cur > np.percentile(hist, 80) else d2[a, t]
    return out


def d6_combo(d1: np.ndarray, d3: np.ndarray) -> np.ndarray:
    valid = ~np.isnan(d1) & ~np.isnan(d3)
    agree = (d1 == d3) & (d1 != 0)
    return np.where(valid, np.where(agree, d1, 0.0), np.nan)
