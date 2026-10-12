"""Sequential admission and simulation of one configuration on one pair.

With ten pairs and one active plan per pair (a pending entry reserves the pair), the ten-plan
cap of the protocol never binds, so each pair can be replayed on its own: signals are walked in
time order, a signal is admitted when the pair is free, the plan is simulated on the 1-minute
candles, and the pair stays busy until the exit (or until the entry expires).

Outcomes carry both cost profiles at the reference sizing (250 USDT notional). Net PnL is linear
in the quantity, so the budget study re-sizes the same outcomes afterwards.
"""

from __future__ import annotations

import math
from dataclasses import dataclass

import numpy as np

from . import costs
from .bars import Candles
from .dataset import Funding, funding_marks
from .features import Features
from .fills import PlanSpec, simulate
from .filters import filter_mask
from .grid import Configuration
from .setups import SetupSignals, compute

REFERENCE_NOTIONAL = 250.0
FUNDING_INTERVAL_MS = 8 * 3_600_000

OUTCOME_DTYPE = np.dtype(
    [
        ("symbol", "U10"),
        ("side", "i1"),
        ("decision_index", "i8"),
        ("decision_ms", "i8"),
        ("fill_ms", "i8"),
        ("exit_ms", "i8"),
        ("entry", "f8"),
        ("stop", "f8"),
        ("target", "f8"),
        ("exit_price", "f8"),
        ("exit_reason", "U12"),
        ("atr", "f8"),
        ("quantity", "f8"),
        ("notional", "f8"),
        ("stop_fraction", "f8"),  # |entry - stop| / entry
        ("funding_intervals", "i4"),
        ("funding_paid", "f8"),  # at the reference quantity, positive when paid
        ("gross", "f8"),
        ("fees_base", "f8"),
        ("friction_base", "f8"),  # spread + slippage
        ("net_base", "f8"),
        ("risk_base", "f8"),
        ("net_adverse", "f8"),
        ("risk_adverse", "f8"),
        ("fees_adverse", "f8"),
        ("friction_adverse", "f8"),
    ]
)


@dataclass(frozen=True)
class Window:
    start_ms: int  # decisions at bar closes > start_ms
    end_ms: int  # decisions at bar closes <= end_ms - one bar; simulations are clipped at end_ms


@dataclass(frozen=True)
class PairInputs:
    candles: Candles
    funding: Funding
    marks: np.ndarray
    features: dict[str, Features]  # by cadence
    instrument: costs.Instrument

    @classmethod
    def build(cls, candles: Candles, funding: Funding, features: dict[str, Features], instrument: costs.Instrument) -> "PairInputs":
        return cls(candles, funding, funding_marks(candles, funding), features, instrument)


@dataclass
class PairRun:
    outcomes: np.ndarray  # OUTCOME_DTYPE
    signals: int
    admitted: int
    unfilled: int
    unsized: int


def _round_to_tick(price: float, tick: float, mode: str) -> float:
    steps = price / tick
    if mode == "up":
        value = math.ceil(steps - 1e-9) * tick
    elif mode == "down":
        value = math.floor(steps + 1e-9) * tick
    else:
        value = round(steps) * tick
    return float(round(value, 12))


def plan_prices(side: int, close: float, atr: float, stop_atr: float, target_r: float | None, tick: float) -> tuple[float, float, float | None]:
    """Entry at the close, stop ``stop_atr`` ATR away, target ``target_r`` times the stop distance.

    Rounding follows the canonical plan builder: the entry rounds toward the market (up for a long),
    the stop and the target round away from the entry (conservative for the stop, farther for the target).
    """
    entry = _round_to_tick(close, tick, "up" if side == 1 else "down")
    raw_stop = entry - side * stop_atr * atr
    stop = _round_to_tick(raw_stop, tick, "down" if side == 1 else "up")
    if side * (entry - stop) <= 0:
        stop = entry - side * tick
    target = None
    if target_r is not None:
        raw_target = entry + side * target_r * abs(entry - stop)
        target = _round_to_tick(raw_target, tick, "up" if side == 1 else "down")
    return entry, stop, target


def configuration_signals(configuration: Configuration, features: Features) -> SetupSignals:
    """The setup's signals, restricted by the configuration's second-pass filter when it has one."""
    signals = compute(configuration.setup, features, configuration.side)
    if configuration.filter_id is None:
        return signals
    mask = filter_mask(configuration.filter_id, features, configuration.side)
    return SetupSignals(signals.signal & mask, signals.regime & mask, signals.exit)


def decision_indices(features: Features, signals: SetupSignals, window: Window) -> np.ndarray:
    bars = features.bars
    usable = signals.signal & bars.complete & (bars.close_ms > window.start_ms) & (bars.close_ms < window.end_ms)
    atr = features["atr14"]
    usable &= ~np.isnan(atr) & (atr > 0) & ~np.isnan(features["ema200"])
    return np.flatnonzero(usable)


def run_pair(
    configuration: Configuration,
    inputs: PairInputs,
    window: Window,
    profiles: dict[str, costs.CostProfile],
    decisions: np.ndarray | None = None,
    signals: SetupSignals | None = None,
) -> PairRun:
    """Replay one configuration on one pair; ``decisions`` overrides the setup's signal bars (controls)."""
    features = inputs.features[configuration.cadence]
    if signals is None:
        signals = configuration_signals(configuration, features)
    if decisions is None:
        decisions = decision_indices(features, signals, window)
    variant = configuration.variant
    bars = features.bars
    candles = inputs.candles
    instrument = inputs.instrument
    base, adverse = profiles["baseline"], profiles["adverse"]
    exit_flags = signals.exit if variant.indicator_exit else None
    side = configuration.side
    funding_intervals = (variant.horizon_ms // 1000 - 1) // (FUNDING_INTERVAL_MS // 1000) + 1
    rows = []
    busy_until = -1
    admitted = unfilled = unsized = 0
    atr_column = features["atr14"]
    for index in decisions:
        close_ms = int(bars.close_ms[index])
        if close_ms < busy_until:
            continue
        atr = float(atr_column[index])
        entry, stop, target = plan_prices(side, float(bars.close[index]), atr, variant.stop_atr, variant.target_r, instrument.tick_size)
        quantity = costs.round_quantity(REFERENCE_NOTIONAL, entry, instrument)
        if quantity <= 0:
            unsized += 1
            continue
        admitted += 1
        trailing = variant.trailing_atr * atr if variant.trailing_atr is not None else None
        plan = PlanSpec(inputs.candles.symbol, side, int(index), entry, stop, target, trailing, variant.horizon_ms, variant.ttl_ms)
        result = simulate(candles, bars, plan, window.end_ms, exit_flags)
        if not result.filled:
            unfilled += 1
            busy_until = close_ms + variant.ttl_ms
            continue
        busy_until = result.exit_ms
        paid = costs.funding_paid(side, quantity, result.fill_ms, result.exit_ms, inputs.funding.time_ms, inputs.funding.rate, inputs.marks)
        settlement_base = costs.settle(side, entry, result.exit_price, quantity, result.exit_reason, base, paid)
        settlement_adverse = costs.settle(side, entry, result.exit_price, quantity, result.exit_reason, adverse, paid)
        rows.append(
            (
                candles.symbol,
                side,
                int(index),
                close_ms,
                result.fill_ms,
                result.exit_ms,
                entry,
                stop,
                target if target is not None else float("nan"),
                result.exit_price,
                result.exit_reason,
                atr,
                quantity,
                entry * quantity,
                abs(entry - stop) / entry,
                funding_intervals,
                paid,
                settlement_base.gross_quote,
                settlement_base.fees_quote,
                settlement_base.spread_quote + settlement_base.slippage_quote,
                settlement_base.net_quote,
                costs.stop_risk_quote(side, entry, stop, quantity, funding_intervals, base),
                settlement_adverse.net_quote,
                costs.stop_risk_quote(side, entry, stop, quantity, funding_intervals, adverse),
                settlement_adverse.fees_quote,
                settlement_adverse.spread_quote + settlement_adverse.slippage_quote,
            )
        )
    outcomes = np.array(rows, dtype=OUTCOME_DTYPE) if rows else np.zeros(0, dtype=OUTCOME_DTYPE)
    return PairRun(outcomes, int(decisions.size), admitted, unfilled, unsized)


def run_configuration(configuration: Configuration, pairs: dict[str, PairInputs], window: Window, profiles: dict[str, costs.CostProfile]) -> tuple[np.ndarray, dict]:
    """Replay a configuration on every pair; returns the outcomes sorted by decision time and counters."""
    runs = {symbol: run_pair(configuration, inputs, window, profiles) for symbol, inputs in pairs.items()}
    outcomes = np.concatenate([run.outcomes for run in runs.values()]) if runs else np.zeros(0, dtype=OUTCOME_DTYPE)
    outcomes = outcomes[np.argsort(outcomes["decision_ms"], kind="stable")]
    counters = {
        "signals": sum(run.signals for run in runs.values()),
        "admitted": sum(run.admitted for run in runs.values()),
        "unfilled": sum(run.unfilled for run in runs.values()),
        "unsized": sum(run.unsized for run in runs.values()),
        "filled": int(outcomes.size),
    }
    return outcomes, counters
