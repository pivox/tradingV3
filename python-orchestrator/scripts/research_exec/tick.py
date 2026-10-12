"""One-second bars, features and signal rules of the tick-data scalping screen (part B).

Bars cover the seconds `start_s ≤ s < end_s` of the exchange clock. The quote fields of a bar
are the last quote at or before the end of the second (forward-filled; `quote_age` is its age
in seconds at the end of the second). Trade fields aggregate the prints of the second. Features
at second `s` use nothing after the end of `s`.
"""

from __future__ import annotations

import re

import numpy as np

from .capture import Instrument

SECOND_US = 1_000_000
HOUR_S = 3600
FEATURE_WINDOWS = (10, 60, 300)
SIGMA_WINDOWS = (60, 300)
FAMILIES = ("S1", "S2", "S3", "S4", "S5", "S6")
_RULE = re.compile(r"^(S[1-6])(?:-W(\d+))?(?:-T([0-9.]+))?(?:-K([0-9.]+))?(?:-Q([0-9.]+))?(-F)?(-C)?(-B)?$")


def build_bars(quotes: np.ndarray, trades: np.ndarray, start_s: int, end_s: int) -> dict[str, np.ndarray]:
    n = int(end_s - start_s)
    if n <= 0:
        raise ValueError("empty bar window")
    bars: dict[str, np.ndarray] = {"ts": start_s + np.arange(n, dtype=np.int64)}
    # Quotes: index of the last quote at or before the end of each second, forward-filled.
    qsec = quotes["ts"] // SECOND_US - start_s
    before = np.flatnonzero(qsec < 0)
    seed = int(before[-1]) if before.size else -1
    inside = (qsec >= 0) & (qsec < n)
    last = np.full(n, -1, dtype=np.int64)
    last[qsec[inside]] = np.flatnonzero(inside)  # later rows overwrite earlier ones: the last quote wins
    marker = np.where(last >= 0, np.arange(n), -1)
    marker = np.maximum.accumulate(marker)
    source = np.where(marker >= 0, last[np.maximum(marker, 0)], seed)
    has = source >= 0
    safe = np.maximum(source, 0)
    for field in ("bid", "ask", "bid_size", "ask_size"):
        values = quotes[field][safe].astype(np.float64)
        bars[field] = np.where(has, values, np.nan)
    bars["mid"] = (bars["bid"] + bars["ask"]) / 2.0
    end_us = (start_s + np.arange(n) + 1) * SECOND_US
    bars["quote_age"] = np.where(has, (end_us - quotes["ts"][safe]) / 1e6, np.inf)
    # Trades.
    tsec = trades["ts"] // SECOND_US - start_s
    keep = (tsec >= 0) & (tsec < n)
    idx = tsec[keep]
    size = trades["size"][keep]
    price = trades["price"][keep]
    buy = trades["taker_buy"][keep].astype(np.float64)
    bars["buy_vol"] = np.bincount(idx, weights=size * buy, minlength=n)
    bars["sell_vol"] = np.bincount(idx, weights=size * (1.0 - buy), minlength=n)
    bars["n"] = np.bincount(idx, minlength=n).astype(np.float64)
    high = np.full(n, -np.inf)
    low = np.full(n, np.inf)
    np.maximum.at(high, idx, price)
    np.minimum.at(low, idx, price)
    bars["high"] = np.where(np.isfinite(high), high, np.nan)
    bars["low"] = np.where(np.isfinite(low), low, np.nan)
    last_trade = np.full(n, -1, dtype=np.int64)
    last_trade[idx] = np.flatnonzero(keep)
    bars["last"] = np.where(last_trade >= 0, trades["price"][np.maximum(last_trade, 0)], np.nan)
    return bars


def _rolling_sum(cumulative: np.ndarray, window: int) -> np.ndarray:
    """Sum over the last `window` entries (NaN while fewer than `window` entries exist)."""
    out = np.full(cumulative.shape, np.nan)
    if window - 1 >= cumulative.size:
        return out
    out[window - 1] = cumulative[window - 1]
    out[window:] = cumulative[window:] - cumulative[:-window]
    return out


def _segment_cumsum(values: np.ndarray, segment: np.ndarray) -> np.ndarray:
    """Cumulative sum restarting at every change of `segment`."""
    total = np.cumsum(values)
    starts = np.flatnonzero(np.diff(segment, prepend=segment[0] - 1))
    offsets = np.where(starts > 0, total[np.maximum(starts - 1, 0)], 0.0)
    base = np.repeat(offsets, np.diff(np.append(starts, values.size)))
    return total - base


def _ema(values: np.ndarray, span: int) -> np.ndarray:
    alpha = 2.0 / (span + 1)
    out = np.full(values.shape, np.nan)
    state = np.nan
    for i, value in enumerate(values):
        if not np.isfinite(value):
            out[i] = state
            continue
        state = value if not np.isfinite(state) else state + alpha * (value - state)
        out[i] = state
    return out


def compute_features(bars: dict[str, np.ndarray], instrument: Instrument) -> dict[str, np.ndarray]:
    mid = bars["mid"]
    n = mid.size
    features: dict[str, np.ndarray] = {}
    with np.errstate(invalid="ignore", divide="ignore"):
        logm = np.log(mid)
        dlog = np.diff(logm, prepend=logm[:1])
        dlog = np.where(np.isfinite(dlog), dlog, 0.0)
        cum = np.cumsum(dlog)
        cum2 = np.cumsum(dlog * dlog)
        tick_floor = instrument.tick_array(np.where(np.isfinite(mid), mid, 1.0)) / np.where(np.isfinite(mid), mid, np.nan)
        for w in FEATURE_WINDOWS:
            features[f"r_{w}"] = _rolling_sum(cum, w)
        for w in SIGMA_WINDOWS:
            features[f"sigma_{w}"] = np.maximum(np.sqrt(_rolling_sum(cum2, w)), tick_floor)
        buy = np.cumsum(bars["buy_vol"])
        sell = np.cumsum(bars["sell_vol"])
        for w in FEATURE_WINDOWS:
            b = _rolling_sum(buy, w)
            s = _rolling_sum(sell, w)
            total = b + s
            features[f"fi_{w}"] = np.where(total > 0, (b - s) / total, np.nan)
            hour_mean = _rolling_sum(np.cumsum(np.where(np.isfinite(total), total, 0.0)), HOUR_S) / HOUR_S
            features[f"a_{w}"] = np.where(hour_mean > 0, total / hour_mean, np.nan)
        sizes = bars["bid_size"] + bars["ask_size"]
        qi = np.where(sizes > 0, (bars["bid_size"] - bars["ask_size"]) / sizes, np.nan)
        qi_ok = np.isfinite(qi)
        qi_sum = _rolling_sum(np.cumsum(np.where(qi_ok, qi, 0.0)), 5)
        qi_cnt = _rolling_sum(np.cumsum(qi_ok.astype(np.float64)), 5)
        features["qi_5"] = np.where(qi_cnt > 0, qi_sum / qi_cnt, np.nan)
        day = bars["ts"] // 86400
        volume = bars["buy_vol"] + bars["sell_vol"]
        price = np.where(np.isfinite(bars["last"]), bars["last"], 0.0)
        pv = _segment_cumsum(price * volume, day)
        v = _segment_cumsum(volume, day)
        vwap = np.where(v > 0, pv / v, np.nan)
        features["dev"] = (mid - vwap) / mid
        ema = _ema(mid, 300)
        features["band_dev"] = (mid - ema) / mid
        count = _rolling_sum(np.cumsum(bars["n"]), 60)
        count_hour = _rolling_sum(np.cumsum(np.where(np.isfinite(count), count, 0.0)), HOUR_S) / HOUR_S
        features["burst"] = (count > 3.0 * count_hour) & (count_hour > 0)
        features["mid"] = mid
        features["quote_age"] = bars["quote_age"]
    assert all(value.shape == (n,) for value in features.values())
    return features


def rule_catalogue(book: bool) -> list[str]:
    rules = [f"S1-W{w}-T{t}" for w in FEATURE_WINDOWS for t in (0.5, 0.7)]
    rules += [f"S2-W{w}-K{k}" for w in SIGMA_WINDOWS for k in (1.5, 2.5)]
    if book:
        rules += [f"S3-Q{q}{suffix}" for q in (0.3, 0.6) for suffix in ("", "-F")]
    rules += [f"S4-W{w}-K{k}" for w in SIGMA_WINDOWS for k in (2, 3)]
    rules += ["S5-K2", "S5-K3", "S5-B", "S6-C", "S6-F"]
    return rules


def family_of(rule: str) -> str:
    return rule.split("-")[0]


def signal_mask(rule: str, f: dict[str, np.ndarray], side: int) -> np.ndarray:
    """Boolean array of the decision seconds where the rule fires for `side` (+1 long, −1 short)."""
    match = _RULE.match(rule)
    if match is None:
        raise ValueError(f"unknown rule {rule}")
    family, w, theta, k, q, flow, cont, band = match.groups()
    w = int(w) if w else None
    sgn = float(side)
    with np.errstate(invalid="ignore"):
        if family == "S1":
            return (sgn * f[f"fi_{w}"] > float(theta)) & (f[f"a_{w}"] >= 1.0)
        if family == "S2":
            return (sgn * f[f"fi_{w}"] < -0.6) & (sgn * f[f"r_{w}"] < -float(k) * f[f"sigma_{w}"])
        if family == "S3":
            mask = sgn * f["qi_5"] > float(q)
            if flow:
                mask &= sgn * f["fi_10"] >= 0.0
            return mask
        if family == "S4":
            return (sgn * f[f"r_{w}"] > float(k) * f[f"sigma_{w}"]) & (f[f"a_{w}"] >= 2.0)
        if family == "S5":
            if band:
                return (sgn * f["band_dev"] < -2.0 * f["sigma_300"]) & (sgn * f["fi_60"] > 0.0)
            return (sgn * f["dev"] < -float(k) * f["sigma_300"]) & (sgn * f["fi_60"] > 0.0)
        if family == "S6":
            if cont:
                return f["burst"] & (sgn * f["fi_60"] > 0.5)
            return f["burst"] & (sgn * f["fi_60"] < -0.5) & (sgn * f["r_60"] < -2.0 * f["sigma_60"])
    raise ValueError(f"unknown family {family}")
