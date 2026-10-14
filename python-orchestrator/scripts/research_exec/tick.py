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
from .platform_setups import SETUP_SIDES, volume_key
from .positioning import METRICS_LAG_S, OI_WINDOW_S

SECOND_US = 1_000_000
HOUR_S = 3600
FEATURE_WINDOWS = (10, 60, 300)
SIGMA_WINDOWS = (60, 300)
FAMILIES = ("S1", "S2", "S3", "S4", "S5", "S6")
_FAMILY = re.compile(r"^(S[1-7]|S7L|Q[1-4]|QB|QD|Q3L|M[1-3]|P[0-3]|R1|O[12])$")  # QB/QD/Q3L (q3x): Q3 ablations; S7L (scalp-q3-r2): S7 with the active-seconds sigma  # P0 (r11): the pool of the three platform setups; R1 (r12): the zone alone; O1/O2 (r13): open interest
MICROPRICE_WINDOWS = (5, 30)  # r9: microprice momentum horizons in seconds
SETUP_FEE_BP = 7.0  # r10 cost gate: maker entry 2 bp + taker exit 5 bp, plus the quoted spread (PREREGISTRATION-r10.md §3)
DEPTH_STALE_S = 120  # a bookDepth snapshot older than this gives no depth feature (r8 §2)
DEPTH_REF_S = 300  # `ddi` compares with the last snapshot at least this much earlier …
DEPTH_REF_MAX_S = 900  # … and none within this horizon gives NaN


def parse_rule(rule: str) -> tuple[str, dict]:
    """`S7-W900-K1.5` → ("S7", {"W": "900", "K": "1.5"}). Tokens: W window, B depth band (a
    digit), T threshold, K sigma multiple (cost multiple for the P rules), D delta, Q queue
    threshold, N run length, A stack layers (r10), V volume-ratio threshold and Z entry zone
    (r10x exploration); flags F (flow), C (continuation) and a bare B (the VWAP band of S5-B,
    key `band`)."""
    family, *tokens = rule.split("-")
    if _FAMILY.match(family) is None:
        raise ValueError(f"unknown rule {rule}")
    params: dict = {}
    for token in tokens:
        if token == "B":
            params["band"] = True
        elif token in ("F", "C"):
            params[token] = True
        elif len(token) > 1 and token[0] in "WBTKDQNAVZ":
            params[token[0]] = token[1:]
        else:
            raise ValueError(f"unknown rule {rule}")
    return family, params


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


def depth_features(ts: np.ndarray, depth: dict[str, np.ndarray]) -> dict[str, np.ndarray]:
    """Depth imbalance per bar from bookDepth snapshots (`ts_s`, `bid` and `ask` of shape n × 5:
    cumulative notional within 1 … 5 % of the mid). A bar at second `s` reads the last snapshot
    stamped at or before `s` (NaN when older than `DEPTH_STALE_S`); `di_b` is the bid-minus-ask
    share within `b` %, `ddi_b` its change since the last snapshot at least `DEPTH_REF_S` earlier
    (NaN when none lies within `DEPTH_REF_MAX_S`)."""
    n = ts.size
    snap = np.asarray(depth["ts_s"], dtype=np.int64)
    out: dict[str, np.ndarray] = {}
    if snap.size == 0:
        for b in (1, 2):
            out[f"di_{b}"], out[f"ddi_{b}"] = np.full(n, np.nan), np.full(n, np.nan)
        return out
    order = np.argsort(snap, kind="stable")
    snap, bid, ask = snap[order], np.asarray(depth["bid"], dtype=np.float64)[order], np.asarray(depth["ask"], dtype=np.float64)[order]
    idx = np.searchsorted(snap, ts, side="right") - 1
    safe = np.maximum(idx, 0)
    fresh = (idx >= 0) & (ts - snap[safe] <= DEPTH_STALE_S)
    ref = np.searchsorted(snap, snap - DEPTH_REF_S, side="right") - 1
    ref_safe = np.maximum(ref, 0)
    ref_ok = (ref >= 0) & (snap - snap[ref_safe] <= DEPTH_REF_MAX_S)
    with np.errstate(invalid="ignore", divide="ignore"):
        for b in (1, 2):
            total = bid[:, b - 1] + ask[:, b - 1]
            di = np.where(total > 0, (bid[:, b - 1] - ask[:, b - 1]) / total, np.nan)
            ddi = np.where(ref_ok, di - di[ref_safe], np.nan)
            out[f"di_{b}"] = np.where(fresh, di[safe], np.nan)
            out[f"ddi_{b}"] = np.where(fresh, ddi[safe], np.nan)
    return out


def compute_features(bars: dict[str, np.ndarray], instrument: Instrument, depth: dict[str, np.ndarray] | None = None) -> dict[str, np.ndarray]:
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
        # r9 (PREREGISTRATION-r9.md §2): queue imbalance of the bar's quote, microprice offset and momentum.
        features["qi"] = qi
        mp = np.where(sizes > 0, (bars["ask"] * bars["bid_size"] + bars["bid"] * bars["ask_size"]) / sizes, np.nan)
        spread = bars["ask"] - bars["bid"]
        features["mpo"] = np.where(spread > 0, (mp - mid) / spread, np.nan)
        logmp = np.log(mp)
        for w in MICROPRICE_WINDOWS:
            lagged = np.full(n, np.nan)
            if n > w:
                lagged[w:] = logmp[:-w]
            features[f"dmp_{w}"] = logmp - lagged
        day = bars["ts"] // 86400
        volume = bars["buy_vol"] + bars["sell_vol"]
        price = np.where(np.isfinite(bars["last"]), bars["last"], 0.0)
        pv = _segment_cumsum(price * volume, day)
        v = _segment_cumsum(volume, day)
        vwap = np.where(v > 0, pv / v, np.nan)
        features["dev"] = (mid - vwap) / mid
        ema = _ema(mid, 300)
        features["band_dev"] = (mid - ema) / mid
        features["band_dev_300"] = features["band_dev"]
        features["band_dev_900"] = (mid - _ema(mid, 900)) / mid
        features["sigma_900"] = np.maximum(np.sqrt(_rolling_sum(cum2, 900)), tick_floor)
        # q3x (EXPLORATORY-q3x.md): share of the last 900 s without any print, and sigma_900 computed on the seconds with
        # prints only, rescaled to 900 s (a quiet stretch no longer lowers it).
        live = (bars["n"] > 0).astype(np.float64)
        live_n = _rolling_sum(np.cumsum(live), 900)
        features["stale_900"] = 1.0 - live_n / 900.0
        live2 = _rolling_sum(np.cumsum(np.where(live > 0, dlog * dlog, 0.0)), 900)
        features["sigma_900_live"] = np.maximum(np.sqrt(np.where(live_n > 0, live2 * 900.0 / np.maximum(live_n, 1.0), np.nan)), tick_floor)
        count = _rolling_sum(np.cumsum(bars["n"]), 60)
        count_hour = _rolling_sum(np.cumsum(np.where(np.isfinite(count), count, 0.0)), HOUR_S) / HOUR_S
        features["burst"] = (count > 3.0 * count_hour) & (count_hour > 0)
        features["mid"] = mid
        features["quote_age"] = bars["quote_age"]
        features["spread_bp"] = (bars["ask"] - bars["bid"]) / mid * 1e4  # r10 cost gate
    if depth is not None:
        features.update(depth_features(bars["ts"], depth))
    assert all(value.shape == (n,) for value in features.values())
    return features


def depth_rule_catalogue() -> tuple[list[str], list[str]]:
    """r8 (PREREGISTRATION-r8.md §3): the 28 depth rules, and the eight Q3 rules that also run
    two-sided."""
    q1 = [f"Q1-B{b}-T{t}{flow}" for b in (1, 2) for t in ("0.25", "0.4") for flow in ("", "-F")]
    q4 = [f"Q4-B{b}-T{t}{flow}" for b in (1, 2) for t in ("0.25", "0.4") for flow in ("", "-F")]
    q2 = [f"Q2-B{b}-D{d}" for b in (1, 2) for d in ("0.15", "0.3")]
    q3 = [f"Q3-W{w}-K{k}-T{t}" for w in (300, 900) for k in ("1.5", "2.0") for t in ("0.1", "0.25")]
    return q1 + q4 + q2 + q3, q3


def book_rule_catalogue() -> list[str]:
    """r9 (PREREGISTRATION-r9.md §3): queue imbalance, microprice offset, microprice momentum
    and queue persistence; sixteen rules."""
    s3 = [f"S3-Q{q}{flow}" for q in ("0.3", "0.6") for flow in ("", "-F")]
    m1 = [f"M1-T{t}{flow}" for t in ("0.2", "0.35") for flow in ("", "-F")]
    m2 = [f"M2-W{w}-K{k}" for w in MICROPRICE_WINDOWS for k in ("1.0", "2.0")]
    m3 = [f"M3-Q{q}-N{n}" for q in ("0.3", "0.5") for n in (3, 10)]
    return s3 + m1 + m2 + m3


def rule_catalogue(book: bool) -> list[str]:
    rules = [f"S1-W{w}-T{t}" for w in FEATURE_WINDOWS for t in (0.5, 0.7)]
    rules += [f"S2-W{w}-K{k}" for w in SIGMA_WINDOWS for k in (1.5, 2.5)]
    if book:
        rules += [f"S3-Q{q}{suffix}" for q in (0.3, 0.6) for suffix in ("", "-F")]
    rules += [f"S4-W{w}-K{k}" for w in SIGMA_WINDOWS for k in (2, 3)]
    rules += ["S5-K2", "S5-K3", "S5-B", "S6-C", "S6-F"]
    return rules


def _lagged(values: np.ndarray, steps: int) -> np.ndarray:
    """The value `steps` seconds earlier (NaN before the start of the series)."""
    out = np.full(values.shape, np.nan)
    if steps == 0:
        return values.astype(np.float64)
    if steps < values.size:
        out[steps:] = values[:-steps]
    return out


def oi_price_condition(family: str, f: dict[str, np.ndarray], side: int, k: float, lag_s: int = METRICS_LAG_S, window_s: int = OI_WINDOW_S) -> np.ndarray:
    """r13 (PREREGISTRATION-r13.md §3): the price part of the O rules at the decision seconds of the
    metrics cadence — the log move of the mid over the open-interest window `[T − window_s, T]`
    (`T` = decision − lag) against `k` × sigma_900 at `T`: O1 (flush reversal) wants a move against
    the stream (`side` × move ≤ −k σ), O2 (build-up continuation) a move with it (≥ +k σ). Also the
    candidate set of the price-matched random control (§4)."""
    sgn = float(side)
    with np.errstate(invalid="ignore", divide="ignore"):
        logmid = np.log(f["mid"])
        move = _lagged(logmid, lag_s) - _lagged(logmid, lag_s + window_s)
        sigma = _lagged(f["sigma_900"], lag_s)
        usable = np.isfinite(move) & np.isfinite(sigma)
        condition = (sgn * move <= -k * sigma) if family == "O1" else (sgn * move >= k * sigma)
    return f["metrics_tick"] & usable & condition


def family_of(rule: str) -> str:
    return rule.split("-")[0]


def signal_mask(rule: str, f: dict[str, np.ndarray], side: int) -> np.ndarray:
    """Boolean array of the decision seconds where the rule fires for `side` (+1 long, −1 short)."""
    family, p = parse_rule(rule)
    w = int(p["W"]) if "W" in p else None
    b = int(p["B"]) if "B" in p else None
    theta, k, q, d = (float(p[key]) if key in p else None for key in ("T", "K", "Q", "D"))
    run_n = int(p["N"]) if "N" in p else None
    flow, cont, band = p.get("F", False), p.get("C", False), p.get("band", False)
    sgn = float(side)
    with np.errstate(invalid="ignore"):
        if family == "S1":
            return (sgn * f[f"fi_{w}"] > theta) & (f[f"a_{w}"] >= 1.0)
        if family == "S2":
            return (sgn * f[f"fi_{w}"] < -0.6) & (sgn * f[f"r_{w}"] < -k * f[f"sigma_{w}"])
        if family == "S3":
            mask = sgn * f["qi_5"] > q
            if flow:
                mask &= sgn * f["fi_10"] >= 0.0
            return mask
        if family == "S4":
            return (sgn * f[f"r_{w}"] > k * f[f"sigma_{w}"]) & (f[f"a_{w}"] >= 2.0)
        if family == "S5":
            if band:
                return (sgn * f["band_dev"] < -2.0 * f["sigma_300"]) & (sgn * f["fi_60"] > 0.0)
            return (sgn * f["dev"] < -k * f["sigma_300"]) & (sgn * f["fi_60"] > 0.0)
        if family == "S6":
            if cont:
                return f["burst"] & (sgn * f["fi_60"] > 0.5)
            return f["burst"] & (sgn * f["fi_60"] < -0.5) & (sgn * f["r_60"] < -2.0 * f["sigma_60"])
        if family == "S7":
            return (sgn * f[f"band_dev_{w}"] < -k * f[f"sigma_{w}"]) & (sgn * f["fi_60"] > 0.0)
        if family == "S7L":  # scalp-q3-r2 C3 (PREREGISTRATION-scalp-q3-r2.md §3): S7 with the active-seconds sigma
            return (sgn * f[f"band_dev_{w}"] < -k * f[f"sigma_{w}_live"]) & (sgn * f["fi_60"] > 0.0)
        # r8 depth families (PREREGISTRATION-r8.md §3): `di_b` bid-minus-ask share of the notional
        # within b % of the mid, `ddi_b` its change over the last five minutes.
        if family == "Q1":
            mask = sgn * f[f"di_{b}"] > theta
            if flow:
                mask &= sgn * f["fi_60"] > 0.0
            return mask
        if family == "Q4":
            mask = sgn * f[f"di_{b}"] < -theta
            if flow:
                mask &= sgn * f["fi_60"] > 0.0
            return mask
        if family == "Q2":
            return sgn * f[f"ddi_{b}"] > d
        if family == "Q3":
            return (sgn * f[f"band_dev_{w}"] < -k * f[f"sigma_{w}"]) & (sgn * f["fi_60"] > 0.0) & (sgn * f["di_1"] > theta)
        # q3x ablations (EXPLORATORY-q3x.md): band only, band + depth, and Q3 with the live-seconds sigma.
        if family == "QB":
            return sgn * f[f"band_dev_{w}"] < -k * f[f"sigma_{w}"]
        if family == "QD":
            return (sgn * f[f"band_dev_{w}"] < -k * f[f"sigma_{w}"]) & (sgn * f["di_1"] > theta)
        if family == "Q3L":
            return (sgn * f[f"band_dev_{w}"] < -k * f[f"sigma_{w}_live"]) & (sgn * f["fi_60"] > 0.0) & (sgn * f["di_1"] > theta)
        # r9 microprice and queue families (PREREGISTRATION-r9.md §3).
        if family == "M1":
            mask = sgn * f["mpo"] > theta
            if flow:
                mask &= sgn * f["fi_10"] >= 0.0
            return mask
        if family == "M2":
            return (sgn * f[f"dmp_{w}"] > k * f["sigma_60"] * np.sqrt(w / 60.0)) & (f["a_60"] >= 1.0)
        if family == "M3":
            persistent = (sgn * f["qi"] > q).astype(np.float64)
            return _rolling_sum(np.cumsum(persistent), run_n) >= run_n
        # r12 (PREREGISTRATION-r12.md §2): the zone alone — at the last second of each 5-minute candle, the mid inside
        # the 1.1.0 entry zone with a usable ATR; no stack, no regime, no side rule (each stream takes the same seconds).
        # r13 (PREREGISTRATION-r13.md §3): open-interest positioning at the 5-minute metrics cadence — O1 a flush
        # (open interest down by T % over fifteen minutes) against the price move, O2 a build-up (up by T %) with it.
        if family in ("O1", "O2"):
            mask = oi_price_condition(family, f, side, k)
            oi = f["oi_chg_15"]
            return mask & ((oi <= -theta / 100.0) if family == "O1" else (oi >= theta / 100.0))
        if family == "R1":
            return f["cadence5"] & np.isfinite(f["atr5"]) & (f["mid"] >= f["zone_lo"]) & (f["mid"] <= f["zone_hi"])
        # r10 platform setups (PREREGISTRATION-r10.md §3): the stack of the decision second (klines-derived,
        # `platform_setups.setup_features`) and the cost gate ATR14(5m) ≥ k × (7 bp + spread).
        if family in ("P0", "P1", "P2", "P3"):
            layers = int(p["A"])
            if family == "P0":
                # r11 (PREREGISTRATION-r11.md §3): the pool of the three setups, replayed as a "both" configuration —
                # the long stream is P1 ∪ P2, the short stream P3 (each stream one position at a time).
                if "V" in p:
                    raise ValueError(f"{rule}: the pool has no volume-threshold variant")
                stack = (f[f"p1_a{layers}"] | f[f"p2_a{layers}"]) if side > 0 else f[f"p3_a{layers}"]
            else:
                if side != SETUP_SIDES[int(family[1])]:
                    return np.zeros(f["mid"].shape, dtype=bool)
                name = f"p{family[1]}_a{layers}"
                if "V" in p:  # r10x exploration: the confirmation layers at another volume-ratio threshold (full stack only)
                    if layers != 4:
                        raise ValueError(f"{rule}: the volume threshold needs the confirmation layers (A4)")
                    name += "_" + volume_key(float(p["V"]))
                stack = f[name]
            mask = stack & np.isfinite(f["atr5"])
            if k:
                mask &= f["atr5_bp"] >= k * (SETUP_FEE_BP + f["spread_bp"])
            if "Z" in p:  # r10x exploration: the 1.1.0 entry zone imposed (Z1) or excluded (Z0) on the mid of the decision second
                if p["Z"] not in ("0", "1"):
                    raise ValueError(f"{rule}: Z takes 0 (outside the zone) or 1 (inside)")
                inside = (f["mid"] >= f["zone_lo"]) & (f["mid"] <= f["zone_hi"])
                mask &= inside if p["Z"] == "1" else ~inside
            return mask
    raise ValueError(f"unknown family {family}")
