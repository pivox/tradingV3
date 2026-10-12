"""Technical indicators on numpy arrays for the v2 candidate-selection screen.

Every function takes one-dimensional float64 arrays aligned on the same bars and
returns arrays of the same length. Values are NaN until the indicator has enough
history. Conventions:

- EMA: seeded with the simple mean of the first ``period`` values, then the
  standard recursion with ``alpha = 2 / (period + 1)``.
- Wilder smoothing (RSI, ATR, ADX): seeded with the simple mean of the first
  ``period`` values, then ``prev + (x - prev) / period``.
- Rolling windows that describe "the previous N bars" exclude the current bar
  (Donchian, NR7 reference range); windows that describe "the last N bars"
  include it (SMA, Bollinger, percentile rank).

The module is exploratory tooling: it is not part of the ``app`` package and
the canonical PHP path is the authority for any survivor.
"""

from __future__ import annotations

from dataclasses import dataclass

import numpy as np

__all__ = [
    "sma",
    "ema",
    "wilder",
    "true_range",
    "atr",
    "rsi",
    "stochastic_k",
    "adx",
    "macd",
    "bollinger",
    "donchian",
    "roc",
    "supertrend",
    "ichimoku",
    "rolling_percentile_rank",
    "fractal_pivots",
    "narrowest_range",
    "anchored_vwap",
    "Bollinger",
    "Adx",
    "Macd",
    "Supertrend",
    "Ichimoku",
    "Vwap",
]


def _as_float(values: np.ndarray) -> np.ndarray:
    array = np.asarray(values, dtype=np.float64)
    if array.ndim != 1:
        raise ValueError("indicators expect one-dimensional arrays")
    return array


def _check_period(period: int, name: str = "period") -> int:
    if int(period) != period or period < 1:
        raise ValueError(f"{name} must be a positive integer, got {period!r}")
    return int(period)


def sma(values: np.ndarray, period: int) -> np.ndarray:
    """Simple moving average over the last ``period`` values (current included)."""
    x = _as_float(values)
    n = _check_period(period)
    out = np.full(x.shape, np.nan)
    if x.size < n:
        return out
    cumulative = np.cumsum(np.insert(x, 0, 0.0))
    out[n - 1 :] = (cumulative[n:] - cumulative[:-n]) / n
    return out


def ema(values: np.ndarray, period: int) -> np.ndarray:
    """Exponential moving average seeded with the SMA of the first ``period`` values."""
    x = _as_float(values)
    n = _check_period(period)
    out = np.full(x.shape, np.nan)
    if x.size < n:
        return out
    alpha = 2.0 / (n + 1.0)
    previous = float(np.mean(x[:n]))
    out[n - 1] = previous
    for index in range(n, x.size):
        previous = previous + alpha * (x[index] - previous)
        out[index] = previous
    return out


def wilder(values: np.ndarray, period: int) -> np.ndarray:
    """Wilder's smoothing seeded with the SMA of the first ``period`` values."""
    x = _as_float(values)
    n = _check_period(period)
    out = np.full(x.shape, np.nan)
    if x.size < n:
        return out
    previous = float(np.mean(x[:n]))
    out[n - 1] = previous
    for index in range(n, x.size):
        previous = previous + (x[index] - previous) / n
        out[index] = previous
    return out


def true_range(high: np.ndarray, low: np.ndarray, close: np.ndarray) -> np.ndarray:
    """True range; the first bar uses its own high minus low."""
    h, l, c = _as_float(high), _as_float(low), _as_float(close)
    tr = h - l
    if c.size > 1:
        previous_close = c[:-1]
        tr[1:] = np.maximum.reduce([h[1:] - l[1:], np.abs(h[1:] - previous_close), np.abs(l[1:] - previous_close)])
    return tr


def atr(high: np.ndarray, low: np.ndarray, close: np.ndarray, period: int = 14) -> np.ndarray:
    """Average true range with Wilder smoothing."""
    return wilder(true_range(high, low, close), period)


def rsi(close: np.ndarray, period: int = 14) -> np.ndarray:
    """Relative strength index with Wilder smoothing (0..100)."""
    c = _as_float(close)
    n = _check_period(period)
    out = np.full(c.shape, np.nan)
    if c.size < n + 1:
        return out
    delta = np.diff(c)
    gains = np.where(delta > 0, delta, 0.0)
    losses = np.where(delta < 0, -delta, 0.0)
    average_gain = wilder(gains, n)
    average_loss = wilder(losses, n)
    with np.errstate(divide="ignore", invalid="ignore"):
        strength = np.where(average_loss == 0, np.inf, average_gain / average_loss)
        value = np.where(average_loss == 0, 100.0, 100.0 - 100.0 / (1.0 + strength))
    value = np.where(np.isnan(average_gain) | np.isnan(average_loss), np.nan, value)
    out[1:] = value
    return out


def stochastic_k(high: np.ndarray, low: np.ndarray, close: np.ndarray, period: int = 14, smooth: int = 3) -> np.ndarray:
    """Slow stochastic %K: raw %K over ``period`` bars, smoothed by an SMA of ``smooth``."""
    h, l, c = _as_float(high), _as_float(low), _as_float(close)
    n = _check_period(period)
    highest = _rolling_max(h, n, include_current=True)
    lowest = _rolling_min(l, n, include_current=True)
    with np.errstate(divide="ignore", invalid="ignore"):
        span = highest - lowest
        raw = np.where(span == 0, 50.0, 100.0 * (c - lowest) / span)
    raw = np.where(np.isnan(highest) | np.isnan(lowest), np.nan, raw)
    if smooth <= 1:
        return raw
    return _sma_ignoring_leading_nan(raw, _check_period(smooth, "smooth"))


@dataclass(frozen=True)
class Adx:
    adx: np.ndarray
    plus_di: np.ndarray
    minus_di: np.ndarray


def adx(high: np.ndarray, low: np.ndarray, close: np.ndarray, period: int = 14) -> Adx:
    """Average directional index with Wilder smoothing of TR, +DM and -DM."""
    h, l, c = _as_float(high), _as_float(low), _as_float(close)
    n = _check_period(period)
    size = c.size
    nan = np.full(size, np.nan)
    if size < 2 * n:
        return Adx(nan, nan.copy(), nan.copy())
    up_move = np.diff(h)
    down_move = -np.diff(l)
    plus_dm = np.where((up_move > down_move) & (up_move > 0), up_move, 0.0)
    minus_dm = np.where((down_move > up_move) & (down_move > 0), down_move, 0.0)
    tr = true_range(h, l, c)[1:]
    smoothed_tr = wilder(tr, n)
    smoothed_plus = wilder(plus_dm, n)
    smoothed_minus = wilder(minus_dm, n)
    with np.errstate(divide="ignore", invalid="ignore"):
        plus_di = np.where(smoothed_tr == 0, 0.0, 100.0 * smoothed_plus / smoothed_tr)
        minus_di = np.where(smoothed_tr == 0, 0.0, 100.0 * smoothed_minus / smoothed_tr)
        di_sum = plus_di + minus_di
        dx = np.where(di_sum == 0, 0.0, 100.0 * np.abs(plus_di - minus_di) / di_sum)
    dx = np.where(np.isnan(smoothed_tr), np.nan, dx)
    adx_values = np.full(dx.shape, np.nan)
    first = n - 1
    valid = dx[first:]
    if valid.size >= n:
        adx_values[first:] = wilder(valid, n)
    out_adx, out_plus, out_minus = nan.copy(), nan.copy(), nan.copy()
    out_adx[1:] = adx_values
    out_plus[1:] = np.where(np.isnan(smoothed_tr), np.nan, plus_di)
    out_minus[1:] = np.where(np.isnan(smoothed_tr), np.nan, minus_di)
    return Adx(out_adx, out_plus, out_minus)


@dataclass(frozen=True)
class Macd:
    macd: np.ndarray
    signal: np.ndarray
    histogram: np.ndarray


def macd(close: np.ndarray, fast: int = 12, slow: int = 26, signal: int = 9) -> Macd:
    """MACD line, signal line and histogram from EMAs seeded with SMAs."""
    c = _as_float(close)
    line = ema(c, fast) - ema(c, slow)
    signal_line = _ema_ignoring_leading_nan(line, _check_period(signal, "signal"))
    return Macd(line, signal_line, line - signal_line)


@dataclass(frozen=True)
class Bollinger:
    middle: np.ndarray
    upper: np.ndarray
    lower: np.ndarray
    width: np.ndarray


def bollinger(close: np.ndarray, period: int = 20, deviations: float = 2.0) -> Bollinger:
    """Bollinger bands on the SMA with the population standard deviation."""
    c = _as_float(close)
    n = _check_period(period)
    middle = sma(c, n)
    std = np.full(c.shape, np.nan)
    if c.size >= n:
        windows = np.lib.stride_tricks.sliding_window_view(c, n)
        std[n - 1 :] = windows.std(axis=1)
    upper = middle + deviations * std
    lower = middle - deviations * std
    with np.errstate(divide="ignore", invalid="ignore"):
        width = np.where(middle == 0, np.nan, (upper - lower) / middle)
    return Bollinger(middle, upper, lower, width)


def donchian(high: np.ndarray, low: np.ndarray, period: int) -> tuple[np.ndarray, np.ndarray]:
    """Highest high and lowest low of the ``period`` bars before the current one."""
    h, l = _as_float(high), _as_float(low)
    n = _check_period(period)
    return _rolling_max(h, n, include_current=False), _rolling_min(l, n, include_current=False)


def roc(close: np.ndarray, period: int) -> np.ndarray:
    """Rate of change: close / close ``period`` bars ago - 1."""
    c = _as_float(close)
    n = _check_period(period)
    out = np.full(c.shape, np.nan)
    if c.size > n:
        with np.errstate(divide="ignore", invalid="ignore"):
            out[n:] = np.where(c[:-n] == 0, np.nan, c[n:] / c[:-n] - 1.0)
    return out


@dataclass(frozen=True)
class Supertrend:
    direction: np.ndarray  # +1 bullish, -1 bearish, 0 before warm-up
    line: np.ndarray


def supertrend(high: np.ndarray, low: np.ndarray, close: np.ndarray, period: int = 10, multiplier: float = 3.0) -> Supertrend:
    """Supertrend with ATR bands around the median price (standard band ratcheting)."""
    h, l, c = _as_float(high), _as_float(low), _as_float(close)
    atr_values = atr(h, l, c, period)
    median = (h + l) / 2.0
    basic_upper = median + multiplier * atr_values
    basic_lower = median - multiplier * atr_values
    size = c.size
    direction = np.zeros(size, dtype=np.int8)
    line = np.full(size, np.nan)
    final_upper = np.nan
    final_lower = np.nan
    current = 0
    for index in range(size):
        if np.isnan(atr_values[index]):
            continue
        upper = basic_upper[index]
        lower = basic_lower[index]
        if not np.isnan(final_upper) and index > 0:
            if upper < final_upper or c[index - 1] > final_upper:
                final_upper = upper
            if lower > final_lower or c[index - 1] < final_lower:
                final_lower = lower
        else:
            final_upper, final_lower = upper, lower
        if current == 0:
            current = 1 if c[index] > final_upper else -1
        elif current == -1 and c[index] > final_upper:
            current = 1
        elif current == 1 and c[index] < final_lower:
            current = -1
        direction[index] = current
        line[index] = final_lower if current == 1 else final_upper
    return Supertrend(direction, line)


@dataclass(frozen=True)
class Ichimoku:
    tenkan: np.ndarray
    kijun: np.ndarray
    senkou_a: np.ndarray  # already displaced forward: value plotted at bar i
    senkou_b: np.ndarray
    chikou_reference: np.ndarray  # close ``displacement`` bars ago, aligned on bar i


def ichimoku(
    high: np.ndarray,
    low: np.ndarray,
    close: np.ndarray,
    tenkan: int = 9,
    kijun: int = 26,
    senkou: int = 52,
    displacement: int = 26,
) -> Ichimoku:
    """Ichimoku lines; the cloud arrays are displaced so that index i holds the cloud under bar i."""
    h, l, c = _as_float(high), _as_float(low), _as_float(close)
    tenkan_line = _midline(h, l, _check_period(tenkan, "tenkan"))
    kijun_line = _midline(h, l, _check_period(kijun, "kijun"))
    span_a_raw = (tenkan_line + kijun_line) / 2.0
    span_b_raw = _midline(h, l, _check_period(senkou, "senkou"))
    shift = _check_period(displacement, "displacement")
    span_a = np.full(c.shape, np.nan)
    span_b = np.full(c.shape, np.nan)
    chikou = np.full(c.shape, np.nan)
    if c.size > shift:
        span_a[shift:] = span_a_raw[:-shift]
        span_b[shift:] = span_b_raw[:-shift]
        chikou[shift:] = c[:-shift]
    return Ichimoku(tenkan_line, kijun_line, span_a, span_b, chikou)


def rolling_percentile_rank(values: np.ndarray, window: int) -> np.ndarray:
    """Share of the last ``window`` values (current included) strictly below the current value."""
    x = _as_float(values)
    n = _check_period(window, "window")
    out = np.full(x.shape, np.nan)
    if x.size < n:
        return out
    windows = np.lib.stride_tricks.sliding_window_view(x, n)
    current = windows[:, -1][:, None]
    valid = ~np.isnan(windows)
    below = np.sum((windows < current) & valid, axis=1)
    count = np.sum(valid, axis=1) - 1
    with np.errstate(divide="ignore", invalid="ignore"):
        rank = np.where(count > 0, below / count, np.nan)
    rank = np.where(np.isnan(windows[:, -1]), np.nan, rank)
    out[n - 1 :] = rank
    return out


def fractal_pivots(high: np.ndarray, low: np.ndarray, wing: int = 2) -> tuple[np.ndarray, np.ndarray]:
    """Williams fractals: a bar whose high (low) is the strict maximum (minimum) of ``wing`` bars each side.

    The flag is set on the pivot bar itself; it is only *known* ``wing`` bars later, so callers
    must shift by ``wing`` before using it as a decision input.
    """
    h, l = _as_float(high), _as_float(low)
    w = _check_period(wing, "wing")
    size = h.size
    pivot_high = np.zeros(size, dtype=bool)
    pivot_low = np.zeros(size, dtype=bool)
    if size < 2 * w + 1:
        return pivot_high, pivot_low
    windows_h = np.lib.stride_tricks.sliding_window_view(h, 2 * w + 1)
    windows_l = np.lib.stride_tricks.sliding_window_view(l, 2 * w + 1)
    centre_h = windows_h[:, w]
    centre_l = windows_l[:, w]
    others = np.r_[0:w, w + 1 : 2 * w + 1]
    pivot_high[w : size - w] = np.all(windows_h[:, others] < centre_h[:, None], axis=1)
    pivot_low[w : size - w] = np.all(windows_l[:, others] > centre_l[:, None], axis=1)
    return pivot_high, pivot_low


def narrowest_range(high: np.ndarray, low: np.ndarray, lookback: int = 7) -> np.ndarray:
    """True when the bar's range is the smallest of the last ``lookback`` bars (current included)."""
    h, l = _as_float(high), _as_float(low)
    n = _check_period(lookback, "lookback")
    span = h - l
    smallest = _rolling_min(span, n, include_current=True)
    return np.where(np.isnan(smallest), False, span <= smallest)


@dataclass(frozen=True)
class Vwap:
    vwap: np.ndarray
    deviation_sigma: np.ndarray  # running standard deviation of (close - vwap) since the anchor


def anchored_vwap(
    timestamps_ms: np.ndarray,
    high: np.ndarray,
    low: np.ndarray,
    close: np.ndarray,
    volume: np.ndarray,
    anchor_ms: int = 86_400_000,
    anchor_offset_ms: int = 0,
) -> Vwap:
    """Volume-weighted average price of the typical price, re-anchored every ``anchor_ms``.

    ``deviation_sigma`` is the running population standard deviation of ``close - vwap`` over
    the bars since the anchor (NaN on the first bar of each session).
    """
    ts = np.asarray(timestamps_ms, dtype=np.int64)
    h, l, c, v = _as_float(high), _as_float(low), _as_float(close), _as_float(volume)
    if anchor_ms <= 0:
        raise ValueError("anchor_ms must be positive")
    session = (ts - anchor_offset_ms) // anchor_ms
    typical = (h + l + c) / 3.0
    size = c.size
    vwap = np.full(size, np.nan)
    sigma = np.full(size, np.nan)
    pv_sum = 0.0
    v_sum = 0.0
    dev_count = 0
    dev_mean = 0.0
    dev_m2 = 0.0
    current_session = None
    for index in range(size):
        if session[index] != current_session:
            current_session = session[index]
            pv_sum = v_sum = 0.0
            dev_count = 0
            dev_mean = dev_m2 = 0.0
        pv_sum += typical[index] * v[index]
        v_sum += v[index]
        if v_sum > 0:
            vwap[index] = pv_sum / v_sum
            deviation = c[index] - vwap[index]
            dev_count += 1
            delta = deviation - dev_mean
            dev_mean += delta / dev_count
            dev_m2 += delta * (deviation - dev_mean)
            if dev_count > 1:
                sigma[index] = float(np.sqrt(dev_m2 / dev_count))
    return Vwap(vwap, sigma)


# --- helpers -----------------------------------------------------------------


def _rolling_max(values: np.ndarray, period: int, include_current: bool) -> np.ndarray:
    out = np.full(values.shape, np.nan)
    if values.size < period:
        return out
    windows = np.lib.stride_tricks.sliding_window_view(values, period)
    maxima = windows.max(axis=1)
    if include_current:
        out[period - 1 :] = maxima
    elif values.size > period:
        out[period:] = maxima[:-1]
    return out


def _rolling_min(values: np.ndarray, period: int, include_current: bool) -> np.ndarray:
    out = np.full(values.shape, np.nan)
    if values.size < period:
        return out
    windows = np.lib.stride_tricks.sliding_window_view(values, period)
    minima = windows.min(axis=1)
    if include_current:
        out[period - 1 :] = minima
    elif values.size > period:
        out[period:] = minima[:-1]
    return out


def _midline(high: np.ndarray, low: np.ndarray, period: int) -> np.ndarray:
    return (_rolling_max(high, period, True) + _rolling_min(low, period, True)) / 2.0


def _first_valid(values: np.ndarray) -> int:
    valid = np.flatnonzero(~np.isnan(values))
    return int(valid[0]) if valid.size else values.size


def _ema_ignoring_leading_nan(values: np.ndarray, period: int) -> np.ndarray:
    out = np.full(values.shape, np.nan)
    start = _first_valid(values)
    if values.size - start >= period:
        out[start:] = ema(values[start:], period)
    return out


def _sma_ignoring_leading_nan(values: np.ndarray, period: int) -> np.ndarray:
    out = np.full(values.shape, np.nan)
    start = _first_valid(values)
    if values.size - start >= period:
        out[start:] = sma(values[start:], period)
    return out
