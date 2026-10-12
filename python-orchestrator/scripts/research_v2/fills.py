"""Per-plan fill simulation on 1-minute candles.

Conventions (the swing campaign's, made explicit):

- The decision is taken at the close of a cadence bar. The limit entry rests from that close for
  ``ttl_ms`` and fills, at the limit price, on the first 1-minute candle whose low (long) or high
  (short) trades through it.
- The stop is checked from the fill candle on (the fill candle included), the target from the
  next candle on: a target touched inside the fill candle is not credited, since the candle
  gives no order of events. When both are touched inside one candle the stop wins, and a stop
  fills at the worse of the stop price and the candle's open (gap through the stop).
- A trailing stop (Chandelier) is recomputed at every cadence bar close from the highest close
  since the fill (lowest for a short) and only tightens; it applies from the next candle.
- An optional per-bar indicator exit closes the position at that bar's close price.
- The horizon runs from the decision close; at the horizon, or at the window end, the position
  exits at the last monitored candle's close.
"""

from __future__ import annotations

from dataclasses import dataclass

import numpy as np

from .bars import MINUTE_MS, Bars, Candles


@dataclass(frozen=True)
class PlanSpec:
    symbol: str
    side: int  # +1 long, -1 short
    decision_index: int  # cadence bar index; the bar is closed
    entry_price: float
    stop_price: float
    target_price: float | None
    trailing_distance: float | None  # price units; None for no trailing stop
    horizon_ms: int
    ttl_ms: int
    entry_mode: str = "limit"  # limit: fills on a touch; limit_through: price must trade through by ``through``; market: next open
    through: float = 0.0  # price units the market must trade beyond the limit (limit_through)

    def __post_init__(self) -> None:
        if self.side not in (1, -1):
            raise ValueError("side must be +1 or -1")
        if self.entry_mode not in ("limit", "limit_through", "market"):
            raise ValueError(f"unknown entry mode {self.entry_mode!r}")
        if self.entry_mode == "limit_through" and self.through <= 0:
            raise ValueError("limit_through needs a positive through distance")
        if self.side * (self.entry_price - self.stop_price) <= 0:
            raise ValueError("the stop must sit on the losing side of the entry")
        if self.target_price is not None and self.side * (self.target_price - self.entry_price) <= 0:
            raise ValueError("the target must sit on the winning side of the entry")
        if self.trailing_distance is not None and self.trailing_distance <= 0:
            raise ValueError("trailing_distance must be positive")


@dataclass(frozen=True)
class FillResult:
    filled: bool
    fill_index: int = -1
    fill_ms: int = -1
    exit_index: int = -1
    exit_ms: int = -1
    exit_price: float = float("nan")
    exit_reason: str = "unfilled"  # stop | target | trailing | indicator | time | window_end | unfilled
    extreme_close: float = float("nan")  # highest (long) or lowest (short) cadence close while open


def simulate(
    candles: Candles,
    bars: Bars,
    plan: PlanSpec,
    end_ms: int,
    exit_flags: np.ndarray | None = None,
) -> FillResult:
    """Simulate one plan. ``exit_flags`` (bool per cadence bar) marks indicator exits at bar closes."""
    decision_close_ms = int(bars.close_ms[plan.decision_index])
    start = int(bars.last_minute[plan.decision_index])
    ttl_stop = int(np.searchsorted(candles.open_ms, decision_close_ms + plan.ttl_ms, side="left"))
    deadline_ms = min(decision_close_ms + plan.horizon_ms, end_ms)
    end = int(np.searchsorted(candles.open_ms, deadline_ms, side="left"))
    ttl_stop = min(ttl_stop, end)
    if start >= ttl_stop:
        return FillResult(False)
    if plan.entry_mode == "market":
        fill_index = start  # at the open of the first candle after the decision, at ``entry_price``
    else:
        level = plan.entry_price - plan.side * plan.through if plan.entry_mode == "limit_through" else plan.entry_price
        if plan.side == 1:
            touched = candles.low[start:ttl_stop] <= level
        else:
            touched = candles.high[start:ttl_stop] >= level
        if not touched.any():
            return FillResult(False)
        fill_index = start + int(np.argmax(touched))
    fill_ms = int(candles.open_ms[fill_index]) + MINUTE_MS
    if fill_index >= end:
        return FillResult(False)

    horizon_reason = "time" if decision_close_ms + plan.horizon_ms <= end_ms else "window_end"
    if plan.trailing_distance is None and exit_flags is None:
        return _simulate_fixed(candles, plan, fill_index, fill_ms, end, horizon_reason)
    return _simulate_by_bar(candles, bars, plan, fill_index, fill_ms, end, horizon_reason, exit_flags)


def _first_hit(values: np.ndarray, level: float, side: int, is_stop: bool) -> int:
    """Offset of the first candle touching ``level`` (-1 when none)."""
    if side == 1:
        hit = values <= level if is_stop else values >= level
    else:
        hit = values >= level if is_stop else values <= level
    return int(np.argmax(hit)) if hit.any() else -1


def _simulate_fixed(candles: Candles, plan: PlanSpec, fill_index: int, fill_ms: int, end: int, horizon_reason: str) -> FillResult:
    lows = candles.low[fill_index:end]
    highs = candles.high[fill_index:end]
    stop_offset = _first_hit(lows if plan.side == 1 else highs, plan.stop_price, plan.side, True)
    target_offset = -1
    if plan.target_price is not None and end - fill_index > 1:
        target_offset = _first_hit(highs[1:] if plan.side == 1 else lows[1:], plan.target_price, plan.side, False)
        if target_offset >= 0:
            target_offset += 1
    return _resolve(candles, plan, fill_index, fill_ms, end, horizon_reason, stop_offset, target_offset, plan.stop_price, float("nan"))


def _stop_fill_price(candles: Candles, index: int, side: int, stop_level: float) -> float:
    """A stop fills at the stop, or at the candle's open when the candle opened through it."""
    open_price = float(candles.open[index])
    return min(stop_level, open_price) if side == 1 else max(stop_level, open_price)


def _resolve(
    candles: Candles,
    plan: PlanSpec,
    fill_index: int,
    fill_ms: int,
    end: int,
    horizon_reason: str,
    stop_offset: int,
    target_offset: int,
    stop_level: float,
    extreme_close: float,
    stop_reason: str = "stop",
) -> FillResult:
    candidates = []
    if stop_offset >= 0:
        candidates.append((stop_offset, 0, stop_reason, stop_level))
    if target_offset >= 0:
        candidates.append((target_offset, 1, "target", plan.target_price))
    if candidates:
        offset, kind, reason, price = min(candidates)
        exit_index = fill_index + offset
        if kind == 0:
            price = _stop_fill_price(candles, exit_index, plan.side, stop_level)
        return FillResult(True, fill_index, fill_ms, exit_index, int(candles.open_ms[exit_index]) + MINUTE_MS, float(price), reason, extreme_close)
    exit_index = end - 1
    return FillResult(True, fill_index, fill_ms, exit_index, int(candles.open_ms[exit_index]) + MINUTE_MS, float(candles.close[exit_index]), horizon_reason, extreme_close)


def _simulate_by_bar(
    candles: Candles,
    bars: Bars,
    plan: PlanSpec,
    fill_index: int,
    fill_ms: int,
    end: int,
    horizon_reason: str,
    exit_flags: np.ndarray | None,
) -> FillResult:
    side = plan.side
    stop_level = plan.stop_price
    stop_reason = "stop"
    extreme_close = float("nan")
    bar = int(np.searchsorted(bars.first_minute, fill_index, side="right")) - 1
    segment_start = fill_index
    while segment_start < end:
        segment_end = min(int(bars.last_minute[bar]), end) if bar < bars.size else end
        if segment_end <= segment_start:
            bar += 1
            continue
        stop_offset = target_offset = -1
        whole_bar = bar < bars.size and segment_start == int(bars.first_minute[bar]) and segment_end == int(bars.last_minute[bar])
        if whole_bar:
            # Bar-level pruning: no 1-minute scan when the bar's range never reaches a level.
            stop_possible = (bars.low[bar] <= stop_level) if side == 1 else (bars.high[bar] >= stop_level)
            target_possible = plan.target_price is not None and ((bars.high[bar] >= plan.target_price) if side == 1 else (bars.low[bar] <= plan.target_price))
        else:
            stop_possible = target_possible = True
        if stop_possible or target_possible:
            lows = candles.low[segment_start:segment_end]
            highs = candles.high[segment_start:segment_end]
            if stop_possible:
                stop_offset = _first_hit(lows if side == 1 else highs, stop_level, side, True)
            if plan.target_price is not None and target_possible:
                skip = 1 if segment_start == fill_index else 0  # no target credit on the fill candle
                if segment_end - segment_start > skip:
                    target_offset = _first_hit(highs[skip:] if side == 1 else lows[skip:], plan.target_price, side, False)
                    if target_offset >= 0:
                        target_offset += skip
        if stop_offset >= 0 or target_offset >= 0:
            return _resolve(candles, plan, segment_start, fill_ms, end, horizon_reason, stop_offset, target_offset, stop_level, extreme_close, stop_reason)._replace_fill(fill_index)
        # Bar close: indicator exit, then trailing update.
        bar_closed = segment_end == int(bars.last_minute[bar]) and segment_end <= end
        if bar_closed and bar > plan.decision_index:
            close = float(bars.close[bar])
            if np.isnan(extreme_close) or side * (close - extreme_close) > 0:
                extreme_close = close
            if exit_flags is not None and bool(exit_flags[bar]):
                exit_index = segment_end - 1
                return FillResult(True, fill_index, fill_ms, exit_index, int(candles.open_ms[exit_index]) + MINUTE_MS, close, "indicator", extreme_close)
            if plan.trailing_distance is not None:
                candidate = extreme_close - side * plan.trailing_distance
                if side * (candidate - stop_level) > 0:
                    stop_level = candidate
                    stop_reason = "trailing"
        segment_start = segment_end
        bar += 1
    exit_index = end - 1
    return FillResult(True, fill_index, fill_ms, exit_index, int(candles.open_ms[exit_index]) + MINUTE_MS, float(candles.close[exit_index]), horizon_reason, extreme_close)


def _replace_fill(self: FillResult, fill_index: int) -> FillResult:
    return FillResult(self.filled, fill_index, self.fill_ms, self.exit_index, self.exit_ms, self.exit_price, self.exit_reason, self.extreme_close)


FillResult._replace_fill = _replace_fill  # type: ignore[attr-defined]
