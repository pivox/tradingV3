"""Leverage definitions, bands, cost provision, floor strata and the analytic liquidation control
(PREREGISTRATION-leverage-gt2.md §3).

All distances and costs are in basis points of the order price. With r = 0.005 of equity at
risk, fixed-risk sizing gives N·(d + c) = r·E, so

    L_eng  = r / (d + c) = 50 / (d_bp + c_bp)   (primary, canonical engine form)
    L_stop = r / d       = 50 / d_bp            (secondary, legacy form, separate analysis)

both computed at the decision second, before any cap, rounding or minimum. Nothing here reads
a price after the decision second.
"""

from __future__ import annotations

import math

import numpy as np

R_FRACTION = 0.005  # risk per trade, a fraction (never "0.5" read as a percent)
R_BP = R_FRACTION * 1e4  # 50 bp
MAKER_ENTRY_BP = 2.0  # E1 resting entry (scalp_engine Policy.maker_bp)
TAKER_STOP_BP = 5.0  # stop / time exit at market (scalp_engine Policy.taker_bp)
SLIPPAGE_PROVISION_TICKS = 1  # owner decision 2026-10-15: p = 1 tick
STOP_ATR_MULTIPLE = 1.5  # B exits: stop at 1.5 x ATR14(5m) of the decision (scalp_engine.SETUP_STOP_ATR)
FLOOR_TICKS = 2  # floor of the B stop distance (scalp_engine._setup_exit)
GRID_GUARD = 1e-6  # fraction of a tick, the engine's tolerance

# Disjoint bands, left-open right-closed: 2.0 belongs to ]1,2], 3.0 to ]2,3].
BANDS: tuple[tuple[str, float, float], ...] = (
    ("le1", 0.0, 1.0),
    ("1-2", 1.0, 2.0),
    ("2-3", 2.0, 3.0),
    ("3-5", 3.0, 5.0),
    ("5-10", 5.0, 10.0),
    ("gt10", 10.0, math.inf),
)
BAND_NAMES = tuple(name for name, _, _ in BANDS)
REF_BAND = "1-2"
PRIMARY_BAND = "2-3"
STUDIED_BANDS = ("2-3", "3-5", "5-10")
ARM_BANDS = (REF_BAND,) + STUDIED_BANDS
CUMULATIVE = {"G3": ("2-3",), "G5": ("2-3", "3-5"), "G10": ("2-3", "3-5", "5-10")}  # descriptive only
DEFINITIONS = ("eng", "stop")
INDETERMINATE_REASONS = ("atr_missing", "quote_stale", "no_quote", "spread_missing", "nonpositive_distance")
C1_TARGET_LEVERAGE = 1.5

EXCLUDED_BASES = ("BTC",)


# ----------------------------------------------------------------------------- universe


def is_excluded_symbol(symbol: str) -> bool:
    """BTC is excluded always, by the series name, before any computation."""
    return symbol.upper().startswith(EXCLUDED_BASES)


def symbol_of_series(name: str) -> str:
    """`binance:ETHUSDT:w2404` → `ETHUSDT`; a bare symbol is returned as is."""
    parts = name.split(":")
    return parts[1] if len(parts) >= 2 else parts[0]


def assert_admissible(name: str) -> None:
    if is_excluded_symbol(symbol_of_series(name)):
        raise ValueError(f"{name}: BTC is excluded from the leverage study")


# ----------------------------------------------------------------------------- leverage


def l_eng(d_bp, c_bp):
    """Primary: r / (d + c). Scalar or array; NaN when d + c is not positive."""
    with np.errstate(divide="ignore", invalid="ignore"):
        total = np.asarray(d_bp, dtype=np.float64) + np.asarray(c_bp, dtype=np.float64)
        out = np.where(total > 0, R_BP / np.where(total > 0, total, 1.0), np.nan)
    return float(out) if out.ndim == 0 else out


def l_stop(d_bp):
    """Secondary: r / d (legacy `DynamicLeverageService` form with r passed as a fraction)."""
    with np.errstate(divide="ignore", invalid="ignore"):
        d = np.asarray(d_bp, dtype=np.float64)
        out = np.where(d > 0, R_BP / np.where(d > 0, d, 1.0), np.nan)
    return float(out) if out.ndim == 0 else out


def leverage(definition: str, d_bp, c_bp):
    if definition == "eng":
        return l_eng(d_bp, c_bp)
    if definition == "stop":
        return l_stop(d_bp)
    raise ValueError(f"unknown leverage definition {definition}")


def band_of(value: float) -> str | None:
    """Band name of one leverage value; None when indeterminable (NaN, inf, ≤ 0)."""
    if value is None or not math.isfinite(value) or value <= 0.0:
        return None
    for name, lo, hi in BANDS:
        if lo < value <= hi:
            return name
    return None


def band_index(values) -> np.ndarray:
    """Vectorised band index into `BANDS` (−1 when indeterminable)."""
    v = np.asarray(values, dtype=np.float64)
    out = np.full(v.shape, -1, dtype=np.int8)
    finite = np.isfinite(v) & (v > 0)
    for k, (_, lo, hi) in enumerate(BANDS):
        out[finite & (v > lo) & (v <= hi)] = k
    return out


# ----------------------------------------------------------------------------- costs, distances


def cost_bp(spread_bp, tick_bp):
    """P2 provision c = 2 bp maker entry + 5 bp taker stop + spread proxy s of the decision second
    (tick.py `spread_bp`) + p = 1 tick of slippage, in bp of the order price."""
    return MAKER_ENTRY_BP + TAKER_STOP_BP + np.asarray(spread_bp, dtype=np.float64) + SLIPPAGE_PROVISION_TICKS * np.asarray(tick_bp, dtype=np.float64)


def stop_distance(atr5, tick):
    """B stop distance in price units: max(1.5 x ATR5, 2 ticks) — `scalp_engine._setup_exit`."""
    return np.maximum(STOP_ATR_MULTIPLE * np.asarray(atr5, dtype=np.float64), FLOOR_TICKS * np.asarray(tick, dtype=np.float64))


def floor_binding(atr5, tick):
    """True when the two-tick floor sets the stop (a flagged stratum, never indeterminable)."""
    return FLOOR_TICKS * np.asarray(tick, dtype=np.float64) >= STOP_ATR_MULTIPLE * np.asarray(atr5, dtype=np.float64)


def stop_trigger(entry, side, distance, tick):
    """Effective trigger on the tick grid: the first grid price at or beyond `entry − side·distance`
    (rounded away from the entry). A print on the grid reaches the unrounded stop iff it reaches
    this price, so the frozen detection is unchanged; d is measured to this price."""
    entry = np.asarray(entry, dtype=np.float64)
    tick = np.asarray(tick, dtype=np.float64)
    raw = entry - np.asarray(side, dtype=np.float64) * np.asarray(distance, dtype=np.float64)
    down = np.floor(raw / tick + GRID_GUARD) * tick
    up = np.ceil(raw / tick - GRID_GUARD) * tick
    out = np.where(np.asarray(side) > 0, down, up)
    return float(out) if out.ndim == 0 else out


def distance_bp(entry, trigger, side):
    return np.asarray(side, dtype=np.float64) * (np.asarray(entry, dtype=np.float64) - np.asarray(trigger, dtype=np.float64)) / np.asarray(entry, dtype=np.float64) * 1e4


def c1_distance_bp(c_bp, target: float = C1_TARGET_LEVERAGE):
    """C1: the stop distance d′ with r / (d′ + c) = 1.5, i.e. d′ = 33.33 bp − c; NaN when c alone
    already exceeds it (C1 indeterminable)."""
    d = R_BP / target - np.asarray(c_bp, dtype=np.float64)
    out = np.where(d > 0, d, np.nan)
    return float(out) if out.ndim == 0 else out


# ----------------------------------------------------------------------------- sizing guard


def theoretical_notional(equity: float, d_bp: float, c_bp: float) -> float:
    """N = r·E / (d + c): the trade at its own size. Nothing in this study scales it."""
    return R_FRACTION * equity / ((d_bp + c_bp) / 1e4)


def assert_not_levered_up(l_theoretical: float, notional: float, equity: float) -> None:
    """A trade is never taken above its own theoretical size: in particular a trade of L ≤ 2 is
    never levered up into a higher band."""
    if notional / equity > l_theoretical * (1.0 + 1e-12):
        raise ValueError(f"notional/equity {notional / equity:.6g} above the trade's theoretical leverage {l_theoretical:.6g}")


# ----------------------------------------------------------------------------- applied leverage and liquidation


APPLIED_RULES = ("ceil", "full")
A_MAX_SCENARIOS = (2, 10)
MMR_MAJORS = 0.01
MMR_MIDCAP_SENSITIVITY = (0.005, 0.01, 0.02)


def applied_leverage(l_theoretical: float, rule: str, a_max: int) -> int:
    """Margin setting at the exchange (not the size): A_ceil = min(ceil(L), A_max) — the canonical
    `finalLeverage = max(1, ceil(notional / balance))` — or A_full = A_max."""
    if rule == "ceil":
        return int(min(max(1, math.ceil(l_theoretical - 1e-12)), a_max))
    if rule == "full":
        return int(a_max)
    raise ValueError(f"unknown applied-leverage rule {rule}")


def margin_refused(l_theoretical: float, applied: int) -> bool:
    """Isolated margin N / A must fit in the equity: refused iff L > A."""
    return l_theoretical > applied * (1.0 + 1e-12)


def liquidation_price(entry: float, side: int, applied: int, mmr: float) -> float:
    """Isolated-margin proxy of `portfolio_simulator.py` (long entry·(1 − 1/A)/(1 − MMR), short
    mirrored); a long at A = 1 has no liquidation price above zero."""
    if side > 0:
        return entry * (1.0 - 1.0 / applied) / (1.0 - mmr)
    return entry * (1.0 + 1.0 / applied) / (1.0 + mmr)


def liquidation_hit(worst: float, liquidation: float, side: int) -> bool:
    if side > 0:
        return liquidation > 0 and worst <= liquidation
    return worst >= liquidation
