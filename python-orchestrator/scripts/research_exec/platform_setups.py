"""Research port of the platform's three scalping setups (contracts 1.1.0) for the r10 screen
(PREREGISTRATION-r10.md §2): indicators on closed candles with the canonical conventions of
`CanonicalPhpIndicatorCalculator`, the catalog 1.1.0 conditions as `StrictCompiledExpressionEvaluator`
and the `App\\Indicator\\Condition` classes evaluate them, the three stacks (regime, context,
trigger, confirmations) at the 5-minute cadence, and their projection on the one-second bars of
the execution tape (a candle closed at T is usable from the end of second T − 1).

Candles come from the Binance monthly 1-minute klines (`acquire.py klines`); 5m, 15m and 1h
candles are UTC aggregations of the 1-minute ones.
"""

from __future__ import annotations

from datetime import datetime, timedelta, timezone
from pathlib import Path

import numpy as np

TIMEFRAMES = {"1m": 60, "5m": 300, "15m": 900, "1h": 3600}
FRESHNESS_S = {"1m": 180, "5m": 480, "15m": 1200, "1h": 4500}  # condition catalog 1.1.0 `input_freshness_seconds`
CADENCE_S = 300  # scalping@1.1.0 evaluation cadence PT5M
CANONICAL_WINDOW = 250  # `CanonicalIndicatorWindow::RECORD_COUNT`: the VWAP window
PULLBACK_WINDOW = 100
PULLBACK_VALIDITY_BARS = 3
NEAR_VWAP_TOLERANCE = 0.0015  # CanonicalPullbackAgeCalculator
RELAXED_NEAR_VWAP_TOLERANCE = 0.004  # close_above_vwap_or_ma9_relaxed
VOLUME_RATIO_WINDOW = 20
VOLUME_RATIO_THRESHOLD = 1.4
ATR_REL_MIN, ATR_REL_MAX = 0.0005, 0.045
MACD_EPS = 1.0e-6
REGIME_TOLERANCE = 0.003
EMA20_50_TOLERANCE = 0.0015
STOP_ATR_MULTIPLE = 1.5
ZONE_ATR_MULTIPLE, ZONE_MIN_RATE, ZONE_MAX_RATE = 0.22, 0.0004, 0.0065
SETUP_SIDES = {1: 1, 2: 1, 3: -1}  # P1 pullback long, P2 trend continuation long, P3 trend momentum short
STACK_NAMES = tuple(f"p{n}_a{layers}" for n in (1, 2, 3) for layers in (3, 4))
SETUP_FEATURES = STACK_NAMES + ("atr5", "atr5_bp", "vwap5", "zone_lo", "zone_hi", "cadence5")
KLINE_FIELDS = ("ts_s", "open", "high", "low", "close", "volume")
# r10x (EXPLORATORY-r10x.md, owner's request of 2026-10-13): the full stacks re-evaluated with the 5m and 1m
# volume-ratio confirmation at these thresholds instead of the catalog's 1.4 (`VOLUME_RATIO_THRESHOLD`).
EXPLORATION_VOLUME_THRESHOLDS = (1.1, 1.2, 1.3)


def setup_rule_catalogue() -> list[str]:
    """r10 §3: `P{n}-A{layers}-K{k}`, twenty-four rules."""
    return [f"P{n}-A{layers}-K{k}" for n in (1, 2, 3) for layers in (3, 4) for k in (0, 2, 4, 6)]


def volume_key(threshold: float) -> str:
    """Suffix of the stack computed with another volume-ratio threshold: 1.2 → `v12`."""
    return f"v{int(round(float(threshold) * 10))}"


def setup_exploration_catalogue() -> list[str]:
    """r10x (exploratory, no selection): the full stacks at the volume-ratio thresholds 1.1, 1.2 and
    1.3 with the cost gate at k ∈ {0, 2, 4} (`P{n}-A4-K{k}-V{t}`, 27 rules); the core and full
    stacks with the 1.1.0 entry zone imposed (`Z1`) or excluded (`Z0`) at k = 0 (12 rules); and the
    six k = 0 rules of r10 again, as the in-run reference (45 rules)."""
    rules = [f"P{n}-A4-K{k}-V{t}" for n in (1, 2, 3) for k in (0, 2, 4) for t in EXPLORATION_VOLUME_THRESHOLDS]
    rules += [f"P{n}-A{layers}-K0-Z{zone}" for n in (1, 2, 3) for layers in (3, 4) for zone in (1, 0)]
    rules += [f"P{n}-A{layers}-K0" for n in (1, 2, 3) for layers in (3, 4)]
    return rules


# ----------------------------------------------------------------------------- candles


def months_covering(start_s: int, end_s: int, warmup_months: int = 1) -> list[str]:
    """`YYYY-MM` of every month from `warmup_months` before the window's first month to its last."""
    first = datetime.fromtimestamp(int(start_s), tz=timezone.utc).replace(day=1, hour=0, minute=0, second=0, microsecond=0)
    for _ in range(warmup_months):
        first = (first - timedelta(days=1)).replace(day=1)
    last = datetime.fromtimestamp(int(end_s) - 1, tz=timezone.utc).replace(day=1, hour=0, minute=0, second=0, microsecond=0)
    out, month = [], first
    while month <= last:
        out.append(month.strftime("%Y-%m"))
        month = (month + timedelta(days=32)).replace(day=1)
    return out


def load_klines(klines_dir: Path, symbol: str, months: list[str]) -> tuple[dict[str, np.ndarray], list[str]]:
    """The 1-minute candles of `months`, concatenated, sorted, duplicates dropped; the second item
    lists the months without a file."""
    pieces: dict[str, list[np.ndarray]] = {name: [] for name in KLINE_FIELDS}
    missing = []
    for month in months:
        path = klines_dir / symbol / f"{symbol}-1m-{month}.npz"
        if not path.exists():
            missing.append(month)
            continue
        with np.load(path) as data:
            for name in KLINE_FIELDS:
                pieces[name].append(data[name].astype(np.int64 if name == "ts_s" else np.float64))
    if not pieces["ts_s"]:
        return {name: np.zeros(0, dtype=np.int64 if name == "ts_s" else np.float64) for name in KLINE_FIELDS}, missing
    out = {name: np.concatenate(values) for name, values in pieces.items()}
    order = np.argsort(out["ts_s"], kind="stable")
    out = {name: values[order] for name, values in out.items()}
    keep = np.ones(out["ts_s"].size, dtype=bool)
    keep[:-1] = out["ts_s"][1:] != out["ts_s"][:-1]
    return {name: values[keep] for name, values in out.items()}, missing


def aggregate(candles: dict[str, np.ndarray], seconds: int) -> dict[str, np.ndarray]:
    """UTC-aligned candles of `seconds` from finer ones (open first, high max, low min, close last,
    volume sum); a bucket without any finer candle does not exist."""
    ts = candles["ts_s"]
    if ts.size == 0:
        return {name: values.copy() for name, values in candles.items()}
    bucket = (ts // seconds) * seconds
    starts = np.flatnonzero(np.diff(bucket, prepend=bucket[0] - 1))
    ends = np.append(starts[1:], ts.size)
    return {
        "ts_s": bucket[starts], "open": candles["open"][starts], "close": candles["close"][ends - 1],
        "high": np.maximum.reduceat(candles["high"], starts), "low": np.minimum.reduceat(candles["low"], starts),
        "volume": np.add.reduceat(candles["volume"], starts),
    }


# ----------------------------------------------------------------------------- indicators


def ema(values: np.ndarray, period: int) -> np.ndarray:
    """Recursive EMA seeded with the first value (`Ema::calculateSeriesPhp`)."""
    out = np.empty(values.shape)
    if values.size == 0:
        return out
    alpha = 2.0 / (period + 1.0)
    state = float(values[0])
    out[0] = state
    for index in range(1, values.size):
        state += alpha * (float(values[index]) - state)
        out[index] = state
    return out


def ema_sma_seeded(values: np.ndarray, period: int) -> np.ndarray:
    """EMA seeded with the SMA of the first `period` values (MACD, `Macd::calculateFullPhp`); NaN before."""
    out = np.full(values.shape, np.nan)
    if values.size < period:
        return out
    alpha = 2.0 / (period + 1.0)
    state = float(np.mean(values[:period]))
    out[period - 1] = state
    for index in range(period, values.size):
        state += alpha * (float(values[index]) - state)
        out[index] = state
    return out


def wilder(values: np.ndarray, period: int) -> np.ndarray:
    """Wilder smoothing seeded with the mean of the first `period` values; NaN before."""
    out = np.full(values.shape, np.nan)
    if values.size < period:
        return out
    state = float(np.mean(values[:period]))
    out[period - 1] = state
    for index in range(period, values.size):
        state = (state * (period - 1) + float(values[index])) / period
        out[index] = state
    return out


def macd(close: np.ndarray, fast: int = 12, slow: int = 26, signal: int = 9) -> tuple[np.ndarray, np.ndarray, np.ndarray]:
    line = ema_sma_seeded(close, fast) - ema_sma_seeded(close, slow)
    valid = np.isfinite(line)
    signal_line = np.full(line.shape, np.nan)
    if valid.any():
        first = int(np.argmax(valid))
        signal_line[first:] = ema_sma_seeded(line[first:], signal)
    return line, signal_line, line - signal_line


def rsi(close: np.ndarray, period: int = 14) -> np.ndarray:
    out = np.full(close.shape, np.nan)
    if close.size < period + 1:
        return out
    change = np.diff(close)
    gains = np.where(change > 0, change, 0.0)
    losses = np.where(change < 0, -change, 0.0)
    average_gain, average_loss = wilder(gains, period), wilder(losses, period)
    with np.errstate(divide="ignore", invalid="ignore"):
        strength = np.where(average_loss == 0.0, np.inf, average_gain / average_loss)
        out[1:] = 100.0 - 100.0 / (1.0 + strength)
    return out


def true_range(high: np.ndarray, low: np.ndarray, close: np.ndarray) -> np.ndarray:
    tr = high - low
    if close.size > 1:
        previous = close[:-1]
        tr[1:] = np.maximum.reduce([high[1:] - low[1:], np.abs(high[1:] - previous), np.abs(low[1:] - previous)])
    return tr


def atr(high: np.ndarray, low: np.ndarray, close: np.ndarray, period: int = 14) -> np.ndarray:
    return wilder(true_range(high, low, close), period)


def adx(high: np.ndarray, low: np.ndarray, close: np.ndarray, period: int = 14) -> np.ndarray:
    """`Adx::calculatePhp`: Wilder-smoothed TR, +DM, −DM from the second candle, DX, and the ADX
    seeded with the first DX then Wilder-smoothed."""
    out = np.full(close.shape, np.nan)
    if close.size <= period:
        return out
    up = np.diff(high)
    down = -np.diff(low)
    plus_dm = np.where((up > down) & (up > 0), up, 0.0)
    minus_dm = np.where((down > up) & (down > 0), down, 0.0)
    tr = true_range(high, low, close)[1:]
    s_tr, s_plus, s_minus = wilder(tr, period), wilder(plus_dm, period), wilder(minus_dm, period)
    with np.errstate(divide="ignore", invalid="ignore"):
        plus_di = np.where(s_tr == 0.0, 0.0, 100.0 * s_plus / s_tr)
        minus_di = np.where(s_tr == 0.0, 0.0, 100.0 * s_minus / s_tr)
        total = plus_di + minus_di
        dx = np.where(total == 0.0, 0.0, 100.0 * np.abs(plus_di - minus_di) / total)
    state = np.nan
    for index in range(period - 1, dx.size):
        state = dx[index] if not np.isfinite(state) else (state * (period - 1) + dx[index]) / period
        out[index + 1] = state
    return out


def _window_sum(values: np.ndarray, window: int, lag: int = 0) -> np.ndarray:
    """Sum of the last `window` values ending `lag` entries before each index (fewer when the
    series is shorter), NaN when no value is available."""
    cumulative = np.concatenate([[0.0], np.cumsum(values)])
    index = np.arange(values.size)
    end = index + 1 - lag
    start = np.maximum(end - window, 0)
    out = np.full(values.shape, np.nan)
    ok = end > 0
    out[ok] = cumulative[end[ok]] - cumulative[start[ok]]
    return out


def windowed_vwap(high: np.ndarray, low: np.ndarray, close: np.ndarray, volume: np.ndarray, window: int = CANONICAL_WINDOW) -> np.ndarray:
    """Typical-price VWAP cumulated over the last `window` candles (the canonical 250-record
    window handed to `Vwap::calculate`); all the candles so far when fewer exist."""
    typical = (high + low + close) / 3.0
    v = np.maximum(volume, 0.0)
    pv = _window_sum(typical * v, window)
    vv = _window_sum(v, window)
    with np.errstate(divide="ignore", invalid="ignore"):
        return np.where(vv > 0, pv / vv, 0.0)


def volume_ratio(volume: np.ndarray, window: int = VOLUME_RATIO_WINDOW) -> np.ndarray:
    """Current volume over the mean of the previous `window` positive volumes (NaN with fewer than
    three candles, a non-positive current volume or no positive history)."""
    positive = volume > 0
    history_sum = _window_sum(np.where(positive, volume, 0.0), window, lag=1)
    history_count = _window_sum(positive.astype(np.float64), window, lag=1)
    out = np.full(volume.shape, np.nan)
    ok = (np.arange(volume.size) >= 2) & positive & (history_count > 0)
    out[ok] = volume[ok] / (history_sum[ok] / history_count[ok])
    return out


def pullback_age(ema9: np.ndarray, ema21: np.ndarray, close: np.ndarray, vwap: np.ndarray, window: int = PULLBACK_WINDOW, tolerance: float = NEAR_VWAP_TOLERANCE) -> np.ndarray:
    """Candles since the last EMA9/EMA21 upward cross or close within `tolerance` of the VWAP
    (`CanonicalPullbackAgeCalculator`); NaN when none within `window` or before the second candle."""
    n = close.size
    event = np.zeros(n, dtype=bool)
    if n > 1:
        crossed = (ema9[:-1] <= ema21[:-1]) & (ema9[1:] > ema21[1:])
        event[1:] = crossed
    with np.errstate(divide="ignore", invalid="ignore"):
        near = (vwap > 0.0) & (np.abs(close / vwap - 1.0) <= tolerance)
    event |= near
    event[0] = False
    last = np.where(event, np.arange(n), -1)
    last = np.maximum.accumulate(last)
    age = np.arange(n) - last
    age = np.where((last >= 0) & (age <= window) & (np.arange(n) >= 1), age, np.nan)
    return age.astype(np.float64)


def indicators(candles: dict[str, np.ndarray]) -> dict[str, np.ndarray]:
    high, low, close, volume = candles["high"], candles["low"], candles["close"], candles["volume"]
    out: dict[str, np.ndarray] = {"ts_s": candles["ts_s"], "close": close}
    for period in (9, 20, 21, 50, 200):
        out[f"ema{period}"] = ema(close, period)
    line, signal, hist = macd(close)
    out["macd"], out["macd_signal"], out["macd_hist"] = line, signal, hist
    out["rsi"] = rsi(close)
    out["atr"] = atr(high, low, close)
    out["adx"] = adx(high, low, close)
    out["vwap"] = windowed_vwap(high, low, close, volume)
    out["volume_ratio"] = volume_ratio(volume)
    out["pullback_age"] = pullback_age(out["ema9"], out["ema21"], close, out["vwap"])
    return out


# ----------------------------------------------------------------------------- conditions


def _lag(values: np.ndarray, steps: int = 1) -> np.ndarray:
    if steps == 0:
        return values.astype(np.float64)
    out = np.full(values.shape, np.nan)
    if values.size > steps:
        out[steps:] = values[:-steps]
    return out


def _hist_cross(hist: np.ndarray, gap: float, cool_down_bars: int, up: bool) -> np.ndarray:
    """`macd_line_cross_{up,down}_with_hysteresis` on the histogram series: within the last
    `cool_down_bars` + 1 candles, a value beyond ±gap right after one beyond ∓gap."""
    out = np.zeros(hist.shape, dtype=bool)
    for offset in range(cool_down_bars + 1):
        current, previous = _lag(hist, offset), _lag(hist, offset + 1)
        if up:
            out |= (current >= gap) & (previous <= -gap)
        else:
            out |= (current <= -gap) & (previous >= gap)
    return out


def conditions(ind: dict[str, np.ndarray], volume_threshold: float = VOLUME_RATIO_THRESHOLD) -> dict[str, np.ndarray]:
    """Boolean series of the catalog 1.1.0 conditions used by the three contracts (NaN inputs
    give False, as `missing_data` rejects); `volume_threshold` replaces the catalog's 1.4 of
    `volume_ratio_ok` for the r10x exploration only."""
    close, ema9, ema20, ema50, ema200 = ind["close"], ind["ema9"], ind["ema20"], ind["ema50"], ind["ema200"]
    hist, line, signal, rsi_v, atr_v, adx_v, vwap = ind["macd_hist"], ind["macd"], ind["macd_signal"], ind["rsi"], ind["atr"], ind["adx"], ind["vwap"]
    with np.errstate(invalid="ignore", divide="ignore"):
        c: dict[str, np.ndarray] = {}
        ema200_prev, ema20_prev = _lag(ema200), _lag(ema20)
        hist_prev, hist_prev2 = _lag(hist), _lag(hist, 2)
        ratio200 = close / ema200 - 1.0
        c["close_above_ema_200"] = close > ema200
        c["close_below_ema_200"] = close < ema200
        c["ema200_slope_pos"] = ema200 > ema200_prev
        c["ema200_slope_neg"] = ema200 < ema200_prev
        c["ema_50_gt_200"] = ema50 > ema200
        c["ema_50_lt_200"] = ema50 < ema200
        above_tol = c["close_above_ema_200"] | (ratio200 > -REGIME_TOLERANCE) | c["ema200_slope_pos"]
        below_tol = c["close_below_ema_200"] | (ratio200 < -REGIME_TOLERANCE) | c["ema200_slope_neg"]
        c["price_regime_ok_long"] = (above_tol & c["ema_50_gt_200"]) | (c["close_above_ema_200"] & c["ema200_slope_pos"])
        c["price_regime_ok_short"] = (below_tol & c["ema_50_lt_200"]) | (c["close_below_ema_200"] & c["ema200_slope_neg"])
        c["macd_hist_gt_eps"] = hist >= -MACD_EPS
        c["macd_hist_slope_pos"] = (hist - hist_prev) > 0.0
        c["macd_hist_slope_neg"] = (hist - hist_prev) < 0.0
        c["macd_hist_decreasing_n"] = ((hist_prev - hist_prev2) < 0.0) & ((hist - hist_prev) < 0.0)
        c["macd_line_above_signal"] = (line - signal) > 0.0
        c["macd_line_below_signal"] = (line - signal) < 0.0
        c["macd_cross_up_5m"] = _hist_cross(hist, 0.0006, 2, up=True)
        c["macd_cross_down_5m"] = _hist_cross(hist, 0.0012, 3, up=False)
        c["ema_20_gt_50"] = ema20 > ema50
        c["ema_20_lt_50"] = ema20 < ema50
        c["ema20_over_50_tol"] = c["ema_20_gt_50"] | ((ema20 / ema50 - 1.0) > -EMA20_50_TOLERANCE) | (ema20 > ema20_prev)
        c["close_above_vwap"] = close > vwap
        c["close_below_vwap"] = close < vwap
        c["close_above_ma_9"] = close > ema9
        c["close_above_vwap_or_ma9"] = c["close_above_vwap"] | c["close_above_ma_9"]
        c["close_above_vwap_and_ma9"] = c["close_above_vwap"] & c["close_above_ma_9"]
        atr_rel = atr_v / close
        c["atr_rel_in_range"] = (atr_rel >= ATR_REL_MIN) & (atr_rel <= ATR_REL_MAX)
        near = (vwap > 0.0) & (np.abs(close / vwap - 1.0) <= RELAXED_NEAR_VWAP_TOLERANCE)
        c["close_above_vwap_or_ma9_relaxed"] = c["close_above_vwap_or_ma9"] | (c["atr_rel_in_range"] & near)
        for threshold in (68, 70, 72, 74):
            c[f"rsi_lt_{threshold}"] = rsi_v < threshold
        for threshold in (18, 20, 22):
            c[f"rsi_gt_{threshold}"] = rsi_v > threshold
        c["adx_ge_15"] = adx_v >= 15.0
        c["adx_ge_18"] = adx_v >= 18.0
        c["volume_ratio_ok"] = (ind["volume_ratio"] + 1.0e-9) >= volume_threshold
        c["pullback_confirmed"] = ind["pullback_age"] <= PULLBACK_VALIDITY_BARS
    return {name: np.where(np.isfinite(values.astype(np.float64)), values, False).astype(bool) if values.dtype != bool else values for name, values in c.items()}


def layers(c1h: dict, c15: dict, c5: dict, c1: dict) -> dict[str, dict[str, np.ndarray]]:
    """The four layers of each setup on its own timeframe's candles (1h regime, 15m context and
    trigger, 5m and 1m confirmations), as boolean series per timeframe."""
    long_regime = c1h["price_regime_ok_long"] & (c1h["macd_hist_gt_eps"] | c1h["macd_hist_slope_pos"]) & c1h["adx_ge_18"]
    short_regime = c1h["price_regime_ok_short"] & (c1h["macd_hist_decreasing_n"] | c1h["macd_hist_slope_neg"]) & c1h["adx_ge_15"]
    p1_15 = c15["ema20_over_50_tol"] & c15["pullback_confirmed"] & c15["rsi_lt_70"] & c15["atr_rel_in_range"]
    p2_15 = c15["ema20_over_50_tol"] & c15["close_above_vwap_or_ma9"] & c15["rsi_lt_74"] & c15["atr_rel_in_range"] & (c15["macd_hist_gt_eps"] | c15["macd_hist_slope_pos"] | c15["macd_line_above_signal"])
    p3_15 = (c15["ema_20_lt_50"] | c15["ema_50_lt_200"]) & (c15["macd_hist_decreasing_n"] | c15["macd_hist_slope_neg"] | c15["macd_line_below_signal"]) & c15["close_below_vwap"] & c15["rsi_gt_20"] & c15["atr_rel_in_range"]
    long_5m = (c5["macd_hist_gt_eps"] | c5["macd_cross_up_5m"]) & c5["close_above_vwap_or_ma9_relaxed"] & c5["rsi_lt_72"] & c5["atr_rel_in_range"] & c5["volume_ratio_ok"]
    long_1m = c1["macd_hist_gt_eps"] & c1["close_above_vwap_and_ma9"] & c1["rsi_lt_68"] & c1["atr_rel_in_range"] & c1["volume_ratio_ok"]
    short_5m = (c5["macd_hist_decreasing_n"] | c5["macd_cross_down_5m"]) & c5["close_below_vwap"] & c5["rsi_gt_18"] & c5["atr_rel_in_range"] & c5["volume_ratio_ok"]
    short_1m = c1["macd_hist_decreasing_n"] & c1["close_below_vwap"] & c1["rsi_gt_22"] & c1["atr_rel_in_range"] & c1["volume_ratio_ok"]
    return {
        "1h": {"long": long_regime, "short": short_regime},
        "15m": {"p1": p1_15, "p2": p2_15, "p3": p3_15},
        "5m": {"long": long_5m, "short": short_5m},
        "1m": {"long": long_1m, "short": short_1m},
    }


# ----------------------------------------------------------------------------- stacks at the cadence


def _closed_index(ts_tf: np.ndarray, seconds: int, closes: np.ndarray, exact: bool) -> np.ndarray:
    """Index of the candle of `ts_tf` closed at or before each close time of `closes` (−1 when
    none, stale beyond the catalog freshness, or, with `exact`, not closing exactly then)."""
    close_times = ts_tf + seconds
    index = np.searchsorted(close_times, closes, side="right") - 1
    ok = index >= 0
    safe = np.maximum(index, 0)
    age = closes - close_times[safe]
    limit = FRESHNESS_S[{60: "1m", 300: "5m", 900: "15m", 3600: "1h"}[seconds]]
    ok &= (age == 0) if exact else (age <= limit)
    return np.where(ok, index, -1)


def _pick(values: np.ndarray, index: np.ndarray, fill):
    out = np.full(index.shape, fill, dtype=values.dtype if values.dtype != bool else bool)
    ok = index >= 0
    out[ok] = values[index[ok]]
    return out


def stacks_at_cadence(k1m: dict[str, np.ndarray], volume_thresholds: tuple[float, ...] = ()) -> dict[str, np.ndarray]:
    """The six stacks (P1–P3 × A3/A4) at every 5-minute close `T` present in the candles, with the
    5m ATR14, the 5m VWAP and the 1.1.0 entry zone, keyed by `T` (`close_s`). Each extra
    `volume_thresholds` value adds the full stacks `p{n}_a4_v{t}` whose 5m and 1m confirmation
    layers use that volume-ratio threshold (r10x exploration)."""
    k5, k15, k1h = aggregate(k1m, 300), aggregate(k1m, 900), aggregate(k1m, 3600)
    i1, i5, i15, i1h = indicators(k1m), indicators(k5), indicators(k15), indicators(k1h)
    c1h, c15 = conditions(i1h), conditions(i15)
    c = layers(c1h, c15, conditions(i5), conditions(i1))
    closes = k5["ts_s"] + 300
    j1 = _closed_index(k1m["ts_s"], 60, closes, exact=True)
    j15 = _closed_index(k15["ts_s"], 900, closes, exact=False)
    j1h = _closed_index(k1h["ts_s"], 3600, closes, exact=False)
    long_regime, short_regime = _pick(c["1h"]["long"], j1h, False), _pick(c["1h"]["short"], j1h, False)
    out: dict[str, np.ndarray] = {"close_s": closes}
    variants = [("", c)] + [(f"_{volume_key(t)}", layers(c1h, c15, conditions(i5, t), conditions(i1, t))) for t in volume_thresholds]
    for n in (1, 2, 3):
        trigger = _pick(c["15m"][f"p{n}"], j15, False)
        regime = long_regime if SETUP_SIDES[n] > 0 else short_regime
        out[f"p{n}_a3"] = regime & trigger
        for suffix, variant in variants:
            if SETUP_SIDES[n] > 0:
                confirm = variant["5m"]["long"] & _pick(variant["1m"]["long"], j1, False)
            else:
                confirm = variant["5m"]["short"] & _pick(variant["1m"]["short"], j1, False)
            out[f"p{n}_a4{suffix}"] = regime & trigger & confirm
    out["atr5"] = i5["atr"]
    out["close5"] = i5["close"]
    out["vwap5"] = i5["vwap"]
    with np.errstate(invalid="ignore", divide="ignore"):
        half = np.clip(ZONE_ATR_MULTIPLE * i5["atr"] / i5["close"], ZONE_MIN_RATE, ZONE_MAX_RATE) * i5["vwap"]
    out["zone_lo"], out["zone_hi"] = i5["vwap"] - half, i5["vwap"] + half
    return out


def setup_features(k1m: dict[str, np.ndarray], start_s: int, end_s: int, volume_thresholds: tuple[float, ...] = ()) -> dict[str, np.ndarray]:
    """Per-second arrays over `start_s ≤ s < end_s`: the stacks are True at the decision second
    `T − 1` of each 5-minute close `T` in `(start_s, end_s]`; `atr5` (price units), `atr5_bp`
    (ATR / close × 1e4), `vwap5`, `zone_lo`, `zone_hi` are forward-filled from that second."""
    n = int(end_s - start_s)
    at = stacks_at_cadence(k1m, volume_thresholds)
    seconds = at["close_s"] - 1 - int(start_s)
    inside = (seconds >= 0) & (seconds < n)
    seconds = seconds[inside]
    out: dict[str, np.ndarray] = {}
    for name, values in at.items():
        if values.dtype != bool:
            continue
        mask = np.zeros(n, dtype=bool)
        mask[seconds] = values[inside]
        out[name] = mask
    cadence = np.zeros(n, dtype=bool)
    cadence[seconds] = True
    out["cadence5"] = cadence  # r12: every decision second of the 5-minute cadence, whatever the stacks say
    marker = np.full(n, -1, dtype=np.int64)
    marker[seconds] = np.flatnonzero(inside)
    marker = np.maximum.accumulate(np.where(marker >= 0, marker, -1))
    has = marker >= 0
    safe = np.maximum(marker, 0)
    with np.errstate(invalid="ignore", divide="ignore"):
        atr5_bp = at["atr5"] / at["close5"] * 1e4
    for name, source in (("atr5", at["atr5"]), ("atr5_bp", atr5_bp), ("vwap5", at["vwap5"]), ("zone_lo", at["zone_lo"]), ("zone_hi", at["zone_hi"])):
        out[name] = np.where(has, source[safe], np.nan)
    return out


def klines_loader(klines_dir: Path, symbol: str, start_s: int, end_s: int, volume_thresholds: tuple[float, ...] = ()):
    """Deferred computation of the setup features of one series from the monthly klines."""

    def load() -> dict[str, np.ndarray]:
        candles, missing = load_klines(klines_dir, symbol, months_covering(start_s, end_s))
        if missing or candles["ts_s"].size == 0:
            raise FileNotFoundError(f"{symbol}: klines missing for {missing or 'every month'} under {klines_dir}")
        return setup_features(candles, start_s, end_s, tuple(volume_thresholds))

    return load
