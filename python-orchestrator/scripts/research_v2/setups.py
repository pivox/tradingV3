"""The pre-registered setup catalogue (PREREGISTRATION.md, section 3).

Each setup is written for the long side; ``side = -1`` mirrors every inequality. A setup
exposes three boolean arrays per (features, side):

- ``signal``: the decision bars where the setup fires;
- ``regime``: the filter the setup trades inside (the regime-matched random control draws its
  entries from these bars);
- ``exit`` (mean-reversion family only): the bars whose close triggers the indicator exit.

Every array is aligned on the cadence bars of the features and is known at the bar close.
"""

from __future__ import annotations

from dataclasses import dataclass, field

import numpy as np

from .features import Features

TREND_FAMILIES = frozenset({"A", "C", "D"})
REVERSION_FAMILY = "B"


@dataclass(frozen=True)
class SetupSpec:
    id: str
    family: str
    cadences: tuple[str, ...]
    description: str
    params: dict = field(default_factory=dict)

    @property
    def exit_family(self) -> str:
        return "reversion" if self.family == REVERSION_FAMILY else "trend"


CATALOGUE: tuple[SetupSpec, ...] = (
    SetupSpec("A1.N20", "A", ("4h", "15m"), "Donchian breakout of the previous 20 bars, trend filter", {"n": 20}),
    SetupSpec("A1.N55", "A", ("4h", "15m"), "Donchian breakout of the previous 55 bars, trend filter", {"n": 55}),
    SetupSpec("A2.N20.K1", "A", ("4h", "15m"), "ROC(20) above 1 ATR, trend filter", {"n": 20, "k": 1.0}),
    SetupSpec("A2.N20.K2", "A", ("4h", "15m"), "ROC(20) above 2 ATR, trend filter", {"n": 20, "k": 2.0}),
    SetupSpec("A2.N60.K1", "A", ("4h", "15m"), "ROC(60) above 1 ATR, trend filter", {"n": 60, "k": 1.0}),
    SetupSpec("A2.N60.K2", "A", ("4h", "15m"), "ROC(60) above 2 ATR, trend filter", {"n": 60, "k": 2.0}),
    SetupSpec("A3", "A", ("4h", "15m"), "EMA20 > EMA50 > EMA200, ADX14 > 25, EMA20 touched within 5 bars, close back above EMA20"),
    SetupSpec("A4", "A", ("4h", "15m"), "MACD histogram crosses zero in the trend direction, trend filter"),
    SetupSpec("A5", "A", ("4h", "15m"), "Supertrend(10,3) flips in the trend direction, trend filter"),
    SetupSpec("B1.RSI2", "B", ("4h", "15m"), "RSI(2) < 10 above EMA200 (mirrored), exit at EMA20", {"osc": "rsi2", "lo": 10.0, "hi": 90.0}),
    SetupSpec("B1.RSI14", "B", ("4h", "15m"), "RSI(14) < 30 above EMA200 (mirrored), exit at EMA20", {"osc": "rsi14", "lo": 30.0, "hi": 70.0}),
    SetupSpec("B1.STOCH", "B", ("4h", "15m"), "Stochastic %K < 20 above EMA200 (mirrored), exit at EMA20", {"osc": "stoch_k", "lo": 20.0, "hi": 80.0}),
    SetupSpec("B2", "B", ("4h", "15m"), "Close outside Bollinger(20,2) against the trend, exit at the middle band"),
    SetupSpec("B3", "B", ("4h", "15m"), "Close stretched 2 ATR from EMA20 against the trend, exit at EMA20"),
    SetupSpec("B4", "B", ("15m",), "Close beyond 2 sigma from the daily VWAP against the 4h trend, exit at VWAP"),
    SetupSpec("C1", "C", ("4h", "15m"), "Bollinger squeeze (width rank < 15 % over 120 bars, 3+ bars) then close beyond the squeeze range"),
    SetupSpec("C2", "C", ("4h",), "NR7 bar then close beyond its high/low, trend filter"),
    SetupSpec("C3", "C", ("4h", "15m"), "ATR14/ATR100 < 0.7 then close beyond the previous 10 bars' extreme"),
    SetupSpec("D1", "D", ("15m",), "4h EMA50 > EMA200 and close > EMA50; 15m EMA20 touched within 3 bars, close beyond the previous bar"),
    SetupSpec("D2", "D", ("15m",), "4h trend as D1; 15m RSI(14) was below 40 within 6 bars and crosses back above 40"),
    SetupSpec("D3", "D", ("4h",), "Ichimoku: price beyond the cloud, Tenkan/Kijun cross, Chikou confirmation"),
    SetupSpec("D4.ASIA", "D", ("15m",), "Opening-range (30 min from 00:00 UTC) breakout, 4h trend filter", {"session": "asia"}),
    SetupSpec("D4.US", "D", ("15m",), "Opening-range (30 min from 13:30 UTC) breakout, 4h trend filter", {"session": "us"}),
    SetupSpec("D5", "D", ("4h",), "Fibonacci 38.2-61.8 % pullback of the last fractal impulse with a reversal bar, trend filter"),
)

CATALOGUE_BY_ID = {spec.id: spec for spec in CATALOGUE}


@dataclass(frozen=True)
class SetupSignals:
    signal: np.ndarray
    regime: np.ndarray
    exit: np.ndarray | None
    stop_level: np.ndarray | None = None  # structural stop price per bar (scalping S3), else None


def _nan_false(mask: np.ndarray) -> np.ndarray:
    return np.where(np.isnan(mask), False, mask) if mask.dtype != bool else mask


def _gt(a: np.ndarray, b: np.ndarray | float, side: int) -> np.ndarray:
    """side * (a - b) > 0, False where either is NaN."""
    with np.errstate(invalid="ignore"):
        diff = side * (np.asarray(a, dtype=np.float64) - b)
    return np.where(np.isnan(diff), False, diff > 0)


def _ge(a: np.ndarray, b: np.ndarray | float, side: int) -> np.ndarray:
    with np.errstate(invalid="ignore"):
        diff = side * (np.asarray(a, dtype=np.float64) - b)
    return np.where(np.isnan(diff), False, diff >= 0)


def _previous(mask: np.ndarray) -> np.ndarray:
    out = np.zeros(mask.shape, dtype=bool)
    out[1:] = mask[:-1]
    return out


def _shift(values: np.ndarray, n: int = 1) -> np.ndarray:
    out = np.full(values.shape, np.nan)
    out[n:] = values[:-n]
    return out


def _any_last(mask: np.ndarray, bars: int) -> np.ndarray:
    """True when ``mask`` was True on any of the last ``bars`` bars (current included)."""
    out = np.zeros(mask.shape, dtype=bool)
    for k in range(bars):
        out[k:] |= mask[: mask.size - k] if k else mask
    return out


def trend_filter(f: Features, side: int) -> np.ndarray:
    return _gt(f["close"], f["ema200"], side)


def context_trend_filter(f: Features, side: int) -> np.ndarray:
    return _gt(f["ctx_close"], f["ctx_ema200"], side)


def context_stack_filter(f: Features, side: int) -> np.ndarray:
    return _gt(f["ctx_ema50"], f["ctx_ema200"], side) & _gt(f["ctx_close"], f["ctx_ema50"], side)


def compute(spec: SetupSpec, f: Features, side: int) -> SetupSignals:
    if side not in (1, -1):
        raise ValueError("side must be +1 or -1")
    if f.cadence not in spec.cadences:
        raise ValueError(f"{spec.id} is not defined on {f.cadence}")
    builder = _BUILDERS[spec.id.split(".")[0]]
    signal, regime, exit_flags = builder(spec, f, side)
    signal = signal & regime
    return SetupSignals(signal.astype(bool), regime.astype(bool), None if exit_flags is None else exit_flags.astype(bool))


# --- A. trend and momentum -------------------------------------------------------


def _a1(spec: SetupSpec, f: Features, side: int):
    n = spec.params["n"]
    level = f[f"dh{n}"] if side == 1 else f[f"dl{n}"]
    return _gt(f["close"], level, side), trend_filter(f, side), None


def _a2(spec: SetupSpec, f: Features, side: int):
    n, k = spec.params["n"], spec.params["k"]
    with np.errstate(invalid="ignore", divide="ignore"):
        threshold = k * f["atr14"] / f["close"]
    return _gt(side * f[f"roc{n}"], threshold, 1), trend_filter(f, side), None


def _a3(spec: SetupSpec, f: Features, side: int):
    stack = _gt(f["ema20"], f["ema50"], side) & _gt(f["ema50"], f["ema200"], side)
    strong = _gt(f["adx14"], 25.0, 1)
    touch = _ge(f["ema20"], f["low"] if side == 1 else f["high"], side)
    signal = _any_last(touch, 5) & _gt(f["close"], f["ema20"], side)
    return signal, stack & strong, None


def _a4(spec: SetupSpec, f: Features, side: int):
    hist = f["macd_hist"]
    cross = _gt(hist, 0.0, side) & ~_gt(_shift(hist), 0.0, side) & ~np.isnan(_shift(hist))
    return cross, trend_filter(f, side), None


def _a5(spec: SetupSpec, f: Features, side: int):
    direction = f["st_dir"]
    flip = (direction == side) & (_shift(direction) == -side)
    return flip, trend_filter(f, side), None


# --- B. mean reversion ------------------------------------------------------------


def _b1(spec: SetupSpec, f: Features, side: int):
    osc = f[spec.params["osc"]]
    extreme = _gt(spec.params["lo"], osc, 1) if side == 1 else _gt(osc, spec.params["hi"], 1)
    return extreme, trend_filter(f, side), _ge(f["close"], f["ema20"], side)


def _b2(spec: SetupSpec, f: Features, side: int):
    outside = _gt(f["bb_lo"], f["close"], 1) if side == 1 else _gt(f["close"], f["bb_up"], 1)
    return outside, trend_filter(f, side), _ge(f["close"], f["bb_mid"], side)


def _b3(spec: SetupSpec, f: Features, side: int):
    with np.errstate(invalid="ignore", divide="ignore"):
        stretch = side * (f["close"] - f["ema20"]) / f["atr14"]
    stretched = np.where(np.isnan(stretch), False, stretch < -2.0)
    return stretched, trend_filter(f, side), _ge(f["close"], f["ema20"], side)


def _b4(spec: SetupSpec, f: Features, side: int):
    with np.errstate(invalid="ignore"):
        band = f["vwap"] - side * 2.0 * f["vwap_sigma"]
    beyond = _gt(band, f["close"], side)
    return beyond, context_trend_filter(f, side), _ge(f["close"], f["vwap"], side)


# --- C. volatility ------------------------------------------------------------------


def _c1(spec: SetupSpec, f: Features, side: int):
    level = f["squeeze_high"] if side == 1 else f["squeeze_low"]
    present = ~np.isnan(f["squeeze_high"])
    return _gt(f["close"], level, side), present, None


def _c2(spec: SetupSpec, f: Features, side: int):
    narrow_previous = _previous(f["nr7"] > 0.5)
    level = _shift(f["high"]) if side == 1 else _shift(f["low"])
    return narrow_previous & _gt(f["close"], level, side), trend_filter(f, side), None


def _c3(spec: SetupSpec, f: Features, side: int):
    with np.errstate(invalid="ignore", divide="ignore"):
        ratio = f["atr14"] / f["atr100"]
    compressed = np.where(np.isnan(ratio), False, ratio < 0.7)
    level = f["dh10"] if side == 1 else f["dl10"]
    return _gt(f["close"], level, side), compressed, None


# --- D. multi-timeframe continuation and structure -------------------------------------


def _d1(spec: SetupSpec, f: Features, side: int):
    touch = _ge(f["ema20"], f["low"] if side == 1 else f["high"], side)
    level = _shift(f["high"]) if side == 1 else _shift(f["low"])
    return _any_last(touch, 3) & _gt(f["close"], level, side), context_stack_filter(f, side), None


def _d2(spec: SetupSpec, f: Features, side: int):
    rsi = f["rsi14"]
    threshold = 40.0 if side == 1 else 60.0
    below = _gt(threshold, rsi, side)  # long: rsi < 40 ; short: rsi > 60
    was_below = _any_last(_previous(below), 6)
    cross = _gt(rsi, threshold, side) & _previous(~_gt(rsi, threshold, side) & ~np.isnan(rsi))
    return was_below & cross, context_stack_filter(f, side), None


def _d3(spec: SetupSpec, f: Features, side: int):
    with np.errstate(invalid="ignore"):
        cloud_edge = np.maximum(f["senkou_a"], f["senkou_b"]) if side == 1 else np.minimum(f["senkou_a"], f["senkou_b"])
    beyond_cloud = _gt(f["close"], cloud_edge, side)
    cross = _gt(f["tenkan"], f["kijun"], side) & ~_gt(_shift(f["tenkan"]), _shift(f["kijun"]), side) & ~np.isnan(_shift(f["kijun"]))
    chikou = _gt(f["close"], f["chikou_ref"], side)
    return cross & chikou, beyond_cloud, None


def _d4(spec: SetupSpec, f: Features, side: int):
    session = spec.params["session"]
    high, low = f[f"or_{session}_high"], f[f"or_{session}_low"]
    available = ~np.isnan(high)
    broke = _gt(f["close"], high if side == 1 else low, side)
    first = broke & ~_previous(broke)  # first bar of the window where the break holds
    return first, available & context_trend_filter(f, side), None


def _d5(spec: SetupSpec, f: Features, side: int):
    high_index, low_index = f["last_pivot_high_index"], f["last_pivot_low_index"]
    pivot_high, pivot_low = f["last_pivot_high"], f["last_pivot_low"]
    both = (high_index >= 0) & (low_index >= 0)
    impulse = both & ((low_index < high_index) if side == 1 else (high_index < low_index))
    with np.errstate(invalid="ignore"):
        span = pivot_high - pivot_low
        if side == 1:
            upper, lower = pivot_high - 0.382 * span, pivot_high - 0.618 * span
        else:
            upper, lower = pivot_low + 0.618 * span, pivot_low + 0.382 * span
        in_zone = (f["close"] <= upper) & (f["close"] >= lower) & (span > 0)
        bar_range = f["high"] - f["low"]
        if side == 1:
            reversal = (f["close"] > f["open"]) & ((f["close"] - f["low"]) >= 2.0 / 3.0 * bar_range)
        else:
            reversal = (f["close"] < f["open"]) & ((f["high"] - f["close"]) >= 2.0 / 3.0 * bar_range)
    in_zone = np.where(np.isnan(upper) | np.isnan(lower), False, in_zone)
    return in_zone & reversal, impulse & trend_filter(f, side), None


_BUILDERS = {
    "A1": _a1,
    "A2": _a2,
    "A3": _a3,
    "A4": _a4,
    "A5": _a5,
    "B1": _b1,
    "B2": _b2,
    "B3": _b3,
    "B4": _b4,
    "C1": _c1,
    "C2": _c2,
    "C3": _c3,
    "D1": _d1,
    "D2": _d2,
    "D3": _d3,
    "D4": _d4,
    "D5": _d5,
}
