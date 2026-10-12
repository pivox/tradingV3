"""Scalping and micro-scalping screen (PREREGISTRATION-scalping.md).

Decisions on 5-minute bars (scalping, holds up to 60 minutes) and 1-minute bars (micro-
scalping, holds up to 15 minutes), both sides, with a 15-minute context (last closed bar).
Fill rules are stricter than the v2 screen's: a resting limit fills only when the price trades
through it by one tick, and the 1-minute setups enter at market on the next candle's open with
taker costs. Candles cannot show the order book, queue position or the path inside a candle;
the screen says so and reports every result with the spread doubled as well.
"""

from __future__ import annotations

from dataclasses import dataclass, field

import numpy as np

from . import indicators as ind
from .bars import Candles, aggregate, align_higher_cadence
from .dataset import Funding
from .features import DAY_MS, Features, SESSIONS
from .setups import SetupSignals, _any_last, _ge, _gt, _previous, _shift

MINUTE_MS = 60_000
TAKER_SHARE = 0.65
VOLUME_BURST = 2.0
OPENING_BAR_WINDOW_MS = 30 * MINUTE_MS


@dataclass(frozen=True)
class ScalpSpec:
    id: str
    cadences: tuple[str, ...]
    description: str
    params: dict = field(default_factory=dict)
    structural_stop: bool = False  # S3: stop at the opening bar's low/high
    indicator_exit: bool = False  # S4, S6: exit at VWAP

    @property
    def family(self) -> str:
        return "S"


SCALP_CATALOGUE: tuple[ScalpSpec, ...] = (
    ScalpSpec("S1", ("5m",), "Momentum burst: close above the previous 12 bars' high, volume > 2x its 20-bar average, 15m close > EMA50"),
    ScalpSpec("S2", ("5m",), "VWAP pullback: 15m EMA50 > EMA200 and close > EMA50; low touched the daily VWAP within 3 bars; close back above VWAP"),
    ScalpSpec("S3.US", ("5m",), "Opening drive: break of the first 5m bar after 13:30 UTC within 30 minutes, stop at that bar's low", {"session": "us"}, structural_stop=True),
    ScalpSpec("S3.ASIA", ("5m",), "Opening drive: break of the first 5m bar after 00:00 UTC within 30 minutes, stop at that bar's low", {"session": "asia"}, structural_stop=True),
    ScalpSpec("S4", ("5m",), "Band fade: close below VWAP - 2 sigma with the 15m trend up; exit at VWAP or at the time stop", indicator_exit=True),
    ScalpSpec("S5", ("5m", "1m"), "Taker-flow proxy: taker-buy share of the last 5 bars' volume > 65 %, close > VWAP, 15m trend up"),
    ScalpSpec("S6", ("1m",), "Micro reversal: three down closes ending below VWAP - 1.5 sigma with the 15m trend up; exit at VWAP or 15 minutes", indicator_exit=True),
)
SCALP_BY_ID = {spec.id: spec for spec in SCALP_CATALOGUE}


@dataclass(frozen=True)
class ScalpVariant:
    id: str
    stop_atr: float | None  # None: structural stop
    target_r: float | None
    indicator_exit: bool
    horizon_ms: int
    ttl_ms: int
    entry_mode: str  # limit_through | market
    min_stop_ticks: int = 0
    trailing_atr: float | None = None


def scalp_exit_variants(spec: ScalpSpec, cadence: str) -> tuple[ScalpVariant, ...]:
    if cadence == "5m":
        horizon, ttl, entry_mode, floor = 60 * MINUTE_MS, 5 * MINUTE_MS, "limit_through", 0
        stops: tuple[float | None, ...] = (1.0, 1.5)
        targets: tuple[float | None, ...] = (1.5, 2.0)
    elif cadence == "1m":
        horizon, ttl, entry_mode, floor = 15 * MINUTE_MS, MINUTE_MS, "market", 2
        stops = (1.0,)
        targets = (1.5,)
    else:
        raise ValueError(f"no scalping variants on {cadence}")
    variants = []
    if spec.structural_stop:
        for target in targets:
            variants.append(ScalpVariant(f"SB.T{target:g}", None, target, False, horizon, ttl, entry_mode, floor))
        return tuple(variants)
    for stop in stops:
        label = f"S{stop:g}"
        if spec.indicator_exit:
            variants.append(ScalpVariant(f"{label}.X", stop, None, True, horizon, ttl, entry_mode, floor))
        else:
            for target in targets:
                variants.append(ScalpVariant(f"{label}.T{target:g}", stop, target, False, horizon, ttl, entry_mode, floor))
    return tuple(variants)


@dataclass(frozen=True)
class ScalpConfiguration:
    id: str
    setup_id: str
    cadence: str
    side: int
    variant: ScalpVariant
    filter_id: str | None = None
    kind: str = "scalp"

    @property
    def setup(self) -> ScalpSpec:
        return SCALP_BY_ID[self.setup_id]

    @property
    def family(self) -> str:
        return "S"

    @property
    def side_name(self) -> str:
        return "long" if self.side == 1 else "short"

    @property
    def base_id(self) -> str:
        return self.id

    def to_json(self) -> dict:
        from dataclasses import asdict

        return {"id": self.id, "setup_id": self.setup_id, "family": "S", "cadence": self.cadence, "side": self.side_name, "variant": asdict(self.variant), "filter": None, "kind": "scalp"}


def build_scalping_grid() -> tuple[ScalpConfiguration, ...]:
    configurations = []
    for spec in SCALP_CATALOGUE:
        for cadence in spec.cadences:
            for side in (1, -1):
                for variant in scalp_exit_variants(spec, cadence):
                    identifier = f"{spec.id}|{cadence}|{'long' if side == 1 else 'short'}|{variant.id}"
                    configurations.append(ScalpConfiguration(identifier, spec.id, cadence, side, variant))
    return tuple(configurations)


def parse_scalp_configuration(identifier: str) -> ScalpConfiguration:
    parts = identifier.split("|")
    if len(parts) != 4:
        raise KeyError(identifier)
    setup_id, cadence, side_name, variant_id = parts
    spec = SCALP_BY_ID[setup_id]
    if side_name not in ("long", "short"):
        raise KeyError(identifier)
    side = 1 if side_name == "long" else -1
    for variant in scalp_exit_variants(spec, cadence):
        if variant.id == variant_id:
            return ScalpConfiguration(identifier, setup_id, cadence, side, variant)
    raise KeyError(identifier)


# --- features -------------------------------------------------------------------------


def compute_scalping_features(candles: Candles, taker_buy: np.ndarray, cadence: str, funding: Funding | None, context: Features) -> Features:
    """5m or 1m bars with the columns the scalping setups read; ``context`` is the 15m features."""
    bars = aggregate(candles, cadence)
    h, l, c, o, v = bars.high, bars.low, bars.close, bars.open, bars.volume
    cols: dict[str, np.ndarray] = {"open": o, "high": h, "low": l, "close": c, "volume": v}
    cols["atr14"] = ind.atr(h, l, c, 14)
    cols["ema200"] = np.zeros(bars.size)  # not used by the scalping setups; keeps the engine's warm-up check trivial
    cols["vol_avg20"] = ind.sma(v, 20)
    cols["dh12"], cols["dl12"] = ind.donchian(h, l, 12)
    vwap = ind.anchored_vwap(bars.open_ms, h, l, c, v, DAY_MS)
    cols["vwap"], cols["vwap_sigma"] = vwap.vwap, vwap.deviation_sigma
    taker_bars = np.add.reduceat(np.asarray(taker_buy, dtype=np.float64), bars.first_minute) if bars.size else np.zeros(0)
    taker5 = _rolling_sum(taker_bars, 5)
    volume5 = _rolling_sum(v, 5)
    with np.errstate(invalid="ignore", divide="ignore"):
        cols["taker_share5"] = np.where(volume5 > 0, taker5 / volume5, np.nan)
    down = np.concatenate(([False], np.diff(c) < 0))
    cols["down3"] = (_any_last(~down, 3) == False).astype(np.float64)  # noqa: E712 - three consecutive down closes
    aligned = align_higher_cadence(bars, context.bars)
    valid = aligned >= 0
    for name in ("close", "ema50", "ema200"):
        column = np.full(bars.size, np.nan)
        column[valid] = context[name][aligned[valid]]
        cols[f"ctx_{name}"] = column
    _add_opening_bars(cols, bars)
    return Features(candles.symbol, cadence, bars, cols)


def _rolling_sum(values: np.ndarray, period: int) -> np.ndarray:
    out = np.full(values.shape, np.nan)
    if values.size >= period:
        cumulative = np.cumsum(np.insert(values, 0, 0.0))
        out[period - 1 :] = cumulative[period:] - cumulative[:-period]
    return out


def _add_opening_bars(cols: dict[str, np.ndarray], bars) -> None:
    """High/low of the first bar of each session, available on the following bars for 30 minutes."""
    day_start = (bars.open_ms // DAY_MS) * DAY_MS
    offset = bars.open_ms - day_start
    bar_ms = int(bars.close_ms[0] - bars.open_ms[0]) if bars.size else MINUTE_MS
    for name, start in SESSIONS.items():
        high_col = np.full(bars.size, np.nan)
        low_col = np.full(bars.size, np.nan)
        is_first = offset == start
        in_window = (offset > start) & (offset < start + OPENING_BAR_WINDOW_MS)
        current_day = -1
        first_high = first_low = np.nan
        for t in range(bars.size):
            if day_start[t] != current_day:
                current_day = day_start[t]
                first_high = first_low = np.nan
            if is_first[t] and bars.close_ms[t] - bars.open_ms[t] == bar_ms:
                first_high, first_low = bars.high[t], bars.low[t]
            elif in_window[t] and not np.isnan(first_high):
                high_col[t], low_col[t] = first_high, first_low
        cols[f"ob_{name}_high"] = high_col
        cols[f"ob_{name}_low"] = low_col


# --- signals ----------------------------------------------------------------------------


def context_trend(f: Features, side: int) -> np.ndarray:
    return _gt(f["ctx_close"], f["ctx_ema50"], side)


def context_stack(f: Features, side: int) -> np.ndarray:
    return _gt(f["ctx_ema50"], f["ctx_ema200"], side) & _gt(f["ctx_close"], f["ctx_ema50"], side)


def compute_scalp_signals(spec: ScalpSpec, f: Features, side: int) -> SetupSignals:
    if side not in (1, -1):
        raise ValueError("side must be +1 or -1")
    if f.cadence not in spec.cadences:
        raise ValueError(f"{spec.id} is not defined on {f.cadence}")
    builder = _BUILDERS[spec.id.split(".")[0]]
    signal, regime, exit_flags, stop_level = builder(spec, f, side)
    return SetupSignals((signal & regime).astype(bool), regime.astype(bool), None if exit_flags is None else exit_flags.astype(bool), stop_level)


def _s1(spec, f, side):
    level = f["dh12"] if side == 1 else f["dl12"]
    with np.errstate(invalid="ignore", divide="ignore"):
        burst = np.where(np.isnan(f["vol_avg20"]), False, f["volume"] > VOLUME_BURST * f["vol_avg20"])
    return _gt(f["close"], level, side) & burst, context_trend(f, side), None, None


def _s2(spec, f, side):
    touch = _ge(f["vwap"], f["low"] if side == 1 else f["high"], side)
    return _any_last(touch, 3) & _gt(f["close"], f["vwap"], side), context_stack(f, side), None, None


def _s3(spec, f, side):
    session = spec.params["session"]
    high, low = f[f"ob_{session}_high"], f[f"ob_{session}_low"]
    available = ~np.isnan(high)
    broke = _gt(f["close"], high if side == 1 else low, side)
    first = broke & ~_previous(broke)
    stop_level = low if side == 1 else high
    return first, available & context_trend(f, side), None, stop_level


def _s4(spec, f, side):
    with np.errstate(invalid="ignore"):
        band = f["vwap"] - side * 2.0 * f["vwap_sigma"]
    return _gt(band, f["close"], side), context_trend(f, side), _ge(f["close"], f["vwap"], side), None


def _s5(spec, f, side):
    share = f["taker_share5"]
    flow = np.where(np.isnan(share), False, (share > TAKER_SHARE) if side == 1 else (share < 1.0 - TAKER_SHARE))
    return flow & _gt(f["close"], f["vwap"], side), context_trend(f, side), None, None


def _s6(spec, f, side):
    if side == 1:
        runs = f["down3"] > 0.5
    else:
        up = np.concatenate(([False], np.diff(f["close"]) > 0))
        runs = _any_last(~up, 3) == False  # noqa: E712 - three consecutive up closes
    with np.errstate(invalid="ignore"):
        band = f["vwap"] - side * 1.5 * f["vwap_sigma"]
    return runs & _gt(band, f["close"], side), context_trend(f, side), _ge(f["close"], f["vwap"], side), None


_BUILDERS = {"S1": _s1, "S2": _s2, "S3": _s3, "S4": _s4, "S5": _s5, "S6": _s6}
