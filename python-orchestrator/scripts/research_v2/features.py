"""Per-cadence feature columns for the v2 setup catalogue.

``compute_features`` turns the 1-minute candles of one pair into cadence bars (15m or 4h) and
the indicator columns the setups read. Every column is known at the bar close it is stored on;
columns derived from a higher cadence (``ctx_*``) come from the last *closed* higher bar, so a
15-minute decision never sees the forming 4-hour bar.
"""

from __future__ import annotations

from dataclasses import dataclass, field

import numpy as np

from . import indicators as ind
from .bars import Bars, Candles, aggregate, align_higher_cadence
from .dataset import Funding

HOUR_MS = 3_600_000
DAY_MS = 86_400_000
SESSIONS = {"asia": 0, "us": 13 * HOUR_MS + 30 * 60_000}  # session start offsets inside the UTC day
OPENING_RANGE_MS = 30 * 60_000
OPENING_RANGE_VALID_MS = 6 * HOUR_MS


@dataclass
class Features:
    symbol: str
    cadence: str
    bars: Bars
    columns: dict[str, np.ndarray] = field(default_factory=dict)

    def __getitem__(self, name: str) -> np.ndarray:
        return self.columns[name]

    @property
    def size(self) -> int:
        return self.bars.size


def compute_features(candles: Candles, cadence: str, funding: Funding | None = None, context: Features | None = None) -> Features:
    """Bars of ``cadence`` plus the indicator columns; ``context`` supplies the 4h columns for 15m."""
    bars = aggregate(candles, cadence)
    h, l, c, o, v = bars.high, bars.low, bars.close, bars.open, bars.volume
    cols: dict[str, np.ndarray] = {"open": o, "high": h, "low": l, "close": c, "volume": v}
    cols["ema20"] = ind.ema(c, 20)
    cols["ema50"] = ind.ema(c, 50)
    cols["ema200"] = ind.ema(c, 200)
    cols["atr14"] = ind.atr(h, l, c, 14)
    cols["atr100"] = ind.atr(h, l, c, 100)
    cols["rsi2"] = ind.rsi(c, 2)
    cols["rsi14"] = ind.rsi(c, 14)
    cols["stoch_k"] = ind.stochastic_k(h, l, c, 14, 3)
    cols["adx14"] = ind.adx(h, l, c, 14).adx
    cols["macd_hist"] = ind.macd(c, 12, 26, 9).histogram
    bands = ind.bollinger(c, 20, 2.0)
    cols["bb_mid"], cols["bb_up"], cols["bb_lo"], cols["bb_width"] = bands.middle, bands.upper, bands.lower, bands.width
    cols["bb_width_rank120"] = ind.rolling_percentile_rank(bands.width, 120)
    for n in (10, 20, 55):
        cols[f"dh{n}"], cols[f"dl{n}"] = ind.donchian(h, l, n)
    cols["roc20"] = ind.roc(c, 20)
    cols["roc60"] = ind.roc(c, 60)
    cols["st_dir"] = ind.supertrend(h, l, c, 10, 3.0).direction.astype(np.float64)
    cloud = ind.ichimoku(h, l, c)
    cols["tenkan"], cols["kijun"], cols["senkou_a"], cols["senkou_b"], cols["chikou_ref"] = cloud.tenkan, cloud.kijun, cloud.senkou_a, cloud.senkou_b, cloud.chikou_reference
    cols["nr7"] = ind.narrowest_range(h, l, 7).astype(np.float64)
    cols["vol_avg20"] = ind.sma(v, 20)
    cols["hour_utc"] = ((bars.open_ms // HOUR_MS) % 24).astype(np.float64)
    cols["weekday"] = (((bars.open_ms // DAY_MS) + 3) % 7).astype(np.float64)  # Monday = 0
    cols["funding_last"] = _last_funding_rate(bars, funding)
    _add_pivot_columns(cols, h, l)
    _add_squeeze_columns(cols, h, l)
    if cadence == "15m":
        vwap = ind.anchored_vwap(bars.open_ms, h, l, c, v, DAY_MS)
        cols["vwap"], cols["vwap_sigma"] = vwap.vwap, vwap.deviation_sigma
        _add_opening_ranges(cols, bars)
    if context is not None:
        aligned = align_higher_cadence(bars, context.bars)
        valid = aligned >= 0
        for name in ("close", "ema50", "ema200"):
            column = np.full(bars.size, np.nan)
            column[valid] = context[name][aligned[valid]]
            cols[f"ctx_{name}"] = column
    return Features(candles.symbol, cadence, bars, cols)


def _last_funding_rate(bars: Bars, funding: Funding | None) -> np.ndarray:
    out = np.full(bars.size, np.nan)
    if funding is None or funding.time_ms.size == 0:
        return out
    index = np.searchsorted(funding.time_ms, bars.close_ms, side="right") - 1
    valid = index >= 0
    out[valid] = funding.rate[index[valid]]
    return out


def _add_pivot_columns(cols: dict[str, np.ndarray], high: np.ndarray, low: np.ndarray, wing: int = 2) -> None:
    """Last confirmed fractal pivots, known ``wing`` bars after the pivot bar.

    ``last_pivot_high`` / ``last_pivot_low`` hold the price of the most recent confirmed pivot,
    ``last_pivot_high_index`` / ``last_pivot_low_index`` its bar index (-1 when none yet).
    """
    pivot_high, pivot_low = ind.fractal_pivots(high, low, wing)
    size = high.size
    last_high = np.full(size, np.nan)
    last_low = np.full(size, np.nan)
    last_high_index = np.full(size, -1.0)
    last_low_index = np.full(size, -1.0)
    current_high = current_low = np.nan
    current_high_index = current_low_index = -1.0
    for t in range(size):
        confirmed = t - wing
        if confirmed >= 0:
            if pivot_high[confirmed]:
                current_high, current_high_index = high[confirmed], float(confirmed)
            if pivot_low[confirmed]:
                current_low, current_low_index = low[confirmed], float(confirmed)
        last_high[t], last_low[t] = current_high, current_low
        last_high_index[t], last_low_index[t] = current_high_index, current_low_index
    cols["last_pivot_high"] = last_high
    cols["last_pivot_low"] = last_low
    cols["last_pivot_high_index"] = last_high_index
    cols["last_pivot_low_index"] = last_low_index


def _add_squeeze_columns(cols: dict[str, np.ndarray], high: np.ndarray, low: np.ndarray, threshold: float = 0.15, min_bars: int = 3) -> None:
    """Bollinger squeeze runs: ``squeeze_high`` / ``squeeze_low`` are the range of the squeeze run
    that ended at the previous bar (NaN unless the previous bar closed a run of ``min_bars`` or more
    squeezed bars and the current bar is the first bar after it, or the run is still going)."""
    rank = cols["bb_width_rank120"]
    size = rank.size
    squeezed = np.where(np.isnan(rank), False, rank < threshold)
    run_high = np.full(size, np.nan)
    run_low = np.full(size, np.nan)
    run_length = np.zeros(size)
    current_high = current_low = np.nan
    length = 0
    for t in range(size):
        # Values stored on bar t describe the run formed by bars < t (so a breakout at t compares to it).
        if length >= min_bars:
            run_high[t], run_low[t], run_length[t] = current_high, current_low, length
        if squeezed[t]:
            if length == 0:
                current_high, current_low = high[t], low[t]
            else:
                current_high, current_low = max(current_high, high[t]), min(current_low, low[t])
            length += 1
        else:
            length = 0
            current_high = current_low = np.nan
    cols["squeeze_high"] = run_high
    cols["squeeze_low"] = run_low
    cols["squeeze_length"] = run_length


def _add_opening_ranges(cols: dict[str, np.ndarray], bars: Bars) -> None:
    """Opening-range high/low per session, available from the bar after the range completes until
    ``OPENING_RANGE_VALID_MS`` after the session start; NaN elsewhere."""
    day_start = (bars.open_ms // DAY_MS) * DAY_MS
    offset = bars.open_ms - day_start
    for name, start in SESSIONS.items():
        high_col = np.full(bars.size, np.nan)
        low_col = np.full(bars.size, np.nan)
        in_range = (offset >= start) & (offset < start + OPENING_RANGE_MS)
        in_window = (offset >= start + OPENING_RANGE_MS) & (offset < start + OPENING_RANGE_VALID_MS)
        current_day = -1
        range_high = range_low = np.nan
        range_bars = 0
        for t in range(bars.size):
            if day_start[t] != current_day:
                current_day = day_start[t]
                range_high = range_low = np.nan
                range_bars = 0
            if in_range[t]:
                range_high = bars.high[t] if np.isnan(range_high) else max(range_high, bars.high[t])
                range_low = bars.low[t] if np.isnan(range_low) else min(range_low, bars.low[t])
                range_bars += 1
            elif in_window[t] and range_bars == OPENING_RANGE_MS // (bars.close_ms[t] - bars.open_ms[t]):
                high_col[t], low_col[t] = range_high, range_low
        cols[f"or_{name}_high"] = high_col
        cols[f"or_{name}_low"] = low_col
