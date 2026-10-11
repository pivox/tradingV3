"""Fast exploratory screen of a long swing family: 4h decisions held for days.

The day_trading family closed negative (report of 2026-10-11): with intraday stops,
costs took 0.1 to 0.2 R per trade. This screen keeps the exploration kernel's plan
arithmetic, fill, exit, cost and funding rules (``exploration``) and lengthens the
horizon:

* 4h bars are aggregated from the verified 1m candles, UTC-aligned like
  ``ResearchRollingWindows``. Indicators use the last 250 closed bars and the formulas
  of ``CanonicalPhpIndicatorCalculator`` (EMA, RSI, ATR, ADX, SMA); ``parity`` compares
  them with the PHP contexts retained by the October 9 signal runs.
* A decision is taken at every 4h close. The entry is a limit at that close resting
  ``entry_ttl_minutes``; the stop is a multiple of the 4h ATR, the target an R multiple,
  and an open position is closed at its holding deadline at the strictly prior close.
* An hour/day index of 1m extremes finds the first stop or target touch in a few
  hundred steps instead of scanning up to 20,160 minutes.

Research portfolio policy, to be fixed by a published swing mode: at most four active
plans (pending entries included), one per symbol, the frozen 250 quote notional at
leverage 1, and no daily-loss admission rule (one wide swing stop can exceed the
day_trading 30 quote cap). The net-R gate provisions funding for every 8h interval of
the holding horizon. A position still open at the end of its phase is closed there at
the last known close, so training never reads 2025 prices and nothing reads a value at
or after 2026-01-01. Results are an exploratory screen without execution authority.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import math
import re
import statistics
from array import array
from collections import Counter
from collections.abc import Callable, Iterable, Iterator, Mapping, Sequence
from concurrent.futures import ProcessPoolExecutor
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from . import exploration as ex
from .exploration import DAY, HOLDOUT_START_MS, MINUTE, PHASES, SYMBOLS, ExplorationError

HOUR = 3_600_000
FOUR_HOURS = 14_400_000
WINDOW = 250  # ResearchRollingWindows keeps the last 250 closed bars of each timeframe
CANDLE_START = '2022-11-01T00:00:00Z'  # 366 4h bars before the 2023 score start
FUNDING_INTERVAL_MS = 28_800_000
MAX_ACTIVE = 4
EMA_PERIODS = (9, 20, 21, 50, 200)
DONCHIAN_BARS = (30, 60, 120)  # 5, 10 and 20 days of 4h bars
PULLBACK_BARS = 6  # one day of 4h bars
CONTROL_MODULUS = 4


# ------------------------------------------------------------------------------ bars
@dataclass
class Bars:
    """UTC-aligned OHLC bars of one timeframe; bar ``i`` becomes known at ``close_ms(i)``."""
    step: int
    start_ms: int
    open: array
    high: array
    low: array
    close: array

    @property
    def count(self) -> int:
        return len(self.close)

    def close_ms(self, index: int) -> int:
        return self.start_ms + (index + 1) * self.step


def aggregate(candles: ex.CandleSeries, step: int) -> Bars:
    """ResearchRollingWindows bucketing: complete UTC buckets, first open, extremes, last close."""
    if step % MINUTE or candles.start_ms % step:
        raise ExplorationError('bars must start on a timeframe boundary')
    per = step // MINUTE
    o, h, l, c = (array('d') for _ in range(4))
    for first in range(0, candles.count - per + 1, per):
        last = first + per
        o.append(candles.open[first])
        h.append(max(candles.high[first:last]))
        l.append(min(candles.low[first:last]))
        c.append(candles.close[last - 1])
    return Bars(step, candles.start_ms, o, h, l, c)


# ------------------------------------------------------------------------ indicators
# Each function repeats the operation order of the PHP fallback formulas, so the
# doubles are equal, not merely close. PHP's array_sum adds left to right, whereas
# Python 3.12's sum() compensates: _php_sum keeps the PHP order.
def _php_sum(values: Iterable[float]) -> float:
    total = 0.0
    for value in values:
        total += value
    return total


def ema_pair(closes: Sequence[float], period: int) -> tuple[float, float]:
    """Ema::calculatePhp on ``closes`` and on ``closes[:-1]`` (the same recursion one step earlier)."""
    multiplier = 2.0 / (period + 1.0)
    value = previous = closes[0]
    for price in closes:
        previous = value
        value = (price * multiplier) + (value * (1.0 - multiplier))
    return value, previous


def rsi_php(closes: Sequence[float], period: int = 14) -> float:
    """Rsi::calculatePhp (Wilder smoothing seeded with the first ``period`` changes)."""
    if len(closes) < period + 1:
        raise ExplorationError('rsi window too short')
    gains = losses = 0.0
    for i in range(1, period + 1):
        change = closes[i] - closes[i - 1]
        if change >= 0.0:
            gains += change
        else:
            losses -= change
    average_gain, average_loss = gains / period, losses / period
    for i in range(period + 1, len(closes)):
        change = closes[i] - closes[i - 1]
        gain = change if change > 0.0 else 0.0
        loss = -change if change < 0.0 else 0.0
        average_gain = ((average_gain * (period - 1)) + gain) / period
        average_loss = ((average_loss * (period - 1)) + loss) / period
    strength = math.inf if average_loss == 0.0 else average_gain / average_loss
    return 100.0 - (100.0 / (1.0 + strength))


def _true_ranges(highs: Sequence[float], lows: Sequence[float], closes: Sequence[float]) -> list[float]:
    return [max(highs[i] - lows[i], abs(highs[i] - closes[i - 1]), abs(lows[i] - closes[i - 1]))
            for i in range(1, len(closes))]


def atr_php(highs: Sequence[float], lows: Sequence[float], closes: Sequence[float], period: int = 14) -> float:
    """AtrCalculator::computePhp (Wilder smoothing seeded with the mean of the first true ranges)."""
    if len(closes) < period + 1:
        raise ExplorationError('atr window too short')
    ranges = _true_ranges(highs, lows, closes)
    atr = _php_sum(ranges[:period]) / period
    for i in range(period, len(ranges)):
        atr = ((atr * (period - 1)) + ranges[i]) / period
    return atr


def adx_php(highs: Sequence[float], lows: Sequence[float], closes: Sequence[float], period: int = 14) -> float:
    """Adx::calculatePhp."""
    if len(closes) <= period:
        raise ExplorationError('adx window too short')
    ranges, plus_dm, minus_dm = [], [], []
    for i in range(1, len(closes)):
        up, down = highs[i] - highs[i - 1], lows[i - 1] - lows[i]
        ranges.append(max(highs[i] - lows[i], abs(highs[i] - closes[i - 1]), abs(lows[i] - closes[i - 1])))
        plus_dm.append(up if up > down and up > 0.0 else 0.0)
        minus_dm.append(down if down > up and down > 0.0 else 0.0)
    atr = _php_sum(ranges[:period]) / period
    plus = _php_sum(plus_dm[:period]) / period
    minus = _php_sum(minus_dm[:period]) / period
    adx = None
    for i in range(period - 1, len(ranges)):
        if i >= period:
            atr = ((atr * (period - 1)) + ranges[i]) / period
            plus = ((plus * (period - 1)) + plus_dm[i]) / period
            minus = ((minus * (period - 1)) + minus_dm[i]) / period
        plus_di = 0.0 if atr == 0.0 else 100.0 * (plus / atr)
        minus_di = 0.0 if atr == 0.0 else 100.0 * (minus / atr)
        total = plus_di + minus_di
        dx = 0.0 if total == 0.0 else 100.0 * abs(plus_di - minus_di) / total
        adx = dx if adx is None else ((adx * (period - 1)) + dx) / period
    return adx


def snapshot(bars: Bars, index: int) -> dict[str, float] | None:
    """The CanonicalPhpIndicatorCalculator fields used here, on the 250 bars closing at ``index``."""
    if not WINDOW - 1 <= index < bars.count:
        return None
    lo = index - WINDOW + 1
    closes = bars.close[lo:index + 1].tolist()
    highs, lows = bars.high[lo:index + 1].tolist(), bars.low[lo:index + 1].tolist()
    values = {'close': closes[-1]}
    for period in EMA_PERIODS:
        values[f'ema{period}'], values[f'ema{period}p'] = ema_pair(closes, period)
    values['rsi'] = rsi_php(closes)
    values['atr'] = atr_php(highs, lows, closes)
    values['adx'] = adx_php(highs, lows, closes)
    values['ma21'] = _php_sum(closes[-21:]) / 21
    values['ma_21_plus_k_atr'] = values['ma21'] + (1.3 * values['atr'])
    return values


INDICATORS = ('close', *(f'ema{p}' for p in EMA_PERIODS), *(f'ema{p}p' for p in EMA_PERIODS),
              'rsi', 'atr', 'adx', 'ma21', 'ma_21_plus_k_atr')
COLUMNS = (*INDICATORS, 'high', 'low', *(f'dh{n}' for n in DONCHIAN_BARS), f'low{PULLBACK_BARS}')


@dataclass
class Frame:
    """Per-bar features of one symbol; ``columns[name][i]`` is known at ``close_ms(i)``."""
    symbol: str
    step: int
    start_ms: int
    columns: dict[str, list]

    @property
    def count(self) -> int:
        return len(self.columns['close'])

    def close_ms(self, index: int) -> int:
        return self.start_ms + (index + 1) * self.step

    def __getitem__(self, name: str) -> list:
        return self.columns[name]

    def decisions(self, start: int, end: int) -> range:
        """Indices of the bars closing in [start, end) with a complete indicator window."""
        first = max(WINDOW - 1, -((self.start_ms - start) // self.step) - 1)
        last = min(self.count, -((self.start_ms - end) // self.step) - 1)
        return range(first, max(first, last))

    def to_json(self) -> dict:
        return {'symbol': self.symbol, 'step': self.step, 'start_ms': self.start_ms, 'columns': self.columns}

    @classmethod
    def from_json(cls, document: Mapping[str, Any]) -> 'Frame':
        return cls(document['symbol'], document['step'], document['start_ms'], dict(document['columns']))


def build_frame(symbol: str, bars: Bars) -> Frame:
    columns: dict[str, list] = {name: [None] * bars.count for name in COLUMNS}
    for index in range(bars.count):
        columns['high'][index], columns['low'][index] = bars.high[index], bars.low[index]
        for n in DONCHIAN_BARS:
            if index >= n:
                columns[f'dh{n}'][index] = max(bars.high[index - n:index])
        if index >= PULLBACK_BARS - 1:
            columns[f'low{PULLBACK_BARS}'][index] = min(bars.low[index - PULLBACK_BARS + 1:index + 1])
        values = snapshot(bars, index)
        if values is not None:
            for name in INDICATORS:
                columns[name][index] = values[name]
    return Frame(symbol, bars.step, bars.start_ms, columns)


def _frame_job(path: str) -> dict:
    candles = ex.CandleSeries.read(Path(path))
    return build_frame(candles.symbol, aggregate(candles, FOUR_HOURS)).to_json()


# --------------------------------------------------------------------------- policies
@dataclass(frozen=True)
class SwingPolicy:
    id: str
    predicate: Callable[[Frame, int], bool]
    description: str


def _up200(f: Frame, i: int) -> bool:
    return f['close'][i] > f['ema200'][i]


def _stacked(f: Frame, i: int) -> bool:
    return f['ema20'][i] > f['ema50'][i] > f['ema200'][i] and f['close'][i] > f['ema50'][i]


def _breakout(bars: int) -> Callable[[Frame, int], bool]:
    return lambda f, i: f['close'][i] > f[f'dh{bars}'][i]


def _reclaim20(f: Frame, i: int) -> bool:
    return f['close'][i - 1] < f['ema20'][i - 1] and f['close'][i] > f['ema20'][i]


def _touch50(f: Frame, i: int) -> bool:
    return f[f'low{PULLBACK_BARS}'][i] <= f['ema50'][i] < f['close'][i]


def _cross921(f: Frame, i: int) -> bool:
    return f['ema9p'][i] <= f['ema21p'][i] and f['ema9'][i] > f['ema21'][i]


def sampled(symbol: str, decision_ms: int, modulus: int = CONTROL_MODULUS) -> bool:
    """Deterministic random timing: sha256("SYMBOL:ms") mod ``modulus`` == 0."""
    return int(hashlib.sha256(f'{symbol}:{decision_ms}'.encode()).hexdigest(), 16) % modulus == 0


def _control(f: Frame, i: int) -> bool:
    return sampled(f.symbol, f.close_ms(i))


POLICIES: dict[str, SwingPolicy] = {p.id: p for p in (
    SwingPolicy('B30_up200', lambda f, i: _up200(f, i) and _breakout(30)(f, i),
                '4h close above the 5-day high, above the 4h EMA200.'),
    SwingPolicy('B60_up200', lambda f, i: _up200(f, i) and _breakout(60)(f, i),
                '4h close above the 10-day high, above the 4h EMA200.'),
    SwingPolicy('B120_up200', lambda f, i: _up200(f, i) and _breakout(120)(f, i),
                '4h close above the 20-day high, above the 4h EMA200.'),
    SwingPolicy('B60_any', _breakout(60), '4h close above the 10-day high, no trend filter.'),
    SwingPolicy('PB20_stack', lambda f, i: _stacked(f, i) and _reclaim20(f, i),
                'EMA20 > EMA50 > EMA200: close back above the EMA20 after a close below it.'),
    SwingPolicy('PB50_stack', lambda f, i: _stacked(f, i) and _touch50(f, i),
                'EMA20 > EMA50 > EMA200: a low within a day touched the EMA50, close above it.'),
    SwingPolicy('OS30_up200', lambda f, i: _up200(f, i) and f['rsi'][i] < 30.0,
                'Above the EMA200 with the 4h RSI below 30.'),
    SwingPolicy('OS40_up200', lambda f, i: _up200(f, i) and f['rsi'][i] < 40.0,
                'Above the EMA200 with the 4h RSI below 40.'),
    SwingPolicy('X921_up200', lambda f, i: _up200(f, i) and _cross921(f, i),
                'Fresh 4h EMA9-over-EMA21 cross above the EMA200.'),
    SwingPolicy('TR_stack_adx25', lambda f, i: _stacked(f, i) and f['adx'][i] >= 25.0,
                'EMA20 > EMA50 > EMA200 with ADX14 at least 25 (trend continuation).'),
    SwingPolicy('R_all', _control, f'Random timing: 1 / {CONTROL_MODULUS} of all 4h decisions.'),
    SwingPolicy('R_up200', lambda f, i: _up200(f, i) and _control(f, i),
                'Random timing restricted to closes above the 4h EMA200.'),
)}
CONTROL_POLICIES = ('R_all', 'R_up200')
SETUP_POLICIES = tuple(p for p in POLICIES if p not in CONTROL_POLICIES)


# ------------------------------------------------------------------------- geometries
@dataclass(frozen=True)
class SwingGeometry:
    stop_atr_multiplier: float = 3.0
    target_risk_multiple: float = 3.0
    horizon_days: int = 7
    entry_ttl_minutes: int = 60

    def __post_init__(self) -> None:
        if (min(self.stop_atr_multiplier, self.target_risk_multiple) <= 0
                or not 1 <= self.horizon_days <= 60 or not 1 <= self.entry_ttl_minutes <= 240):
            raise ExplorationError('swing geometry invalid')

    @property
    def horizon_ms(self) -> int:
        return self.horizon_days * DAY

    @property
    def entry_ttl_ms(self) -> int:
        return self.entry_ttl_minutes * MINUTE

    @property
    def funding_intervals(self) -> int:
        """CostAssumptions.funding_intervals_provisioned for this horizon."""
        return (self.horizon_ms // 1000 - 1) // (FUNDING_INTERVAL_MS // 1000) + 1


GEOMETRIES: dict[str, SwingGeometry] = {
    f'S{stop:g}.T{target:g}.H{days}d': SwingGeometry(stop, target, days)
    for stop in (2.0, 3.0, 4.0) for target in (2.0, 3.0, 5.0) for days in (3, 7, 14)}


# ------------------------------------------------------------------------------ plans
def build_swing_plan(frame: Frame, index: int, geometry: SwingGeometry, instrument: Mapping[str, Any],
                     profile: Mapping[str, float], phase_end: int) -> tuple[ex.Plan | None, str | None]:
    """Limit at the 4h close, ATR stop and R target with ``exploration``'s exact arithmetic."""
    close, atr = frame['close'][index], frame['atr'][index]
    for value in (close, atr):
        if not isinstance(value, float) or not math.isfinite(value) or value <= 0:
            return None, 'research_signal_price_context_missing'
    entry = ex._ceil_to(ex._decimal(close), ex._decimal(instrument['tick_size']))
    protected, reason = ex.protect_and_size(entry, ex._decimal(atr), geometry.stop_atr_multiplier,
                                            geometry.target_risk_multiple, instrument, profile,
                                            geometry.funding_intervals)
    if protected is None:
        return None, reason
    stop, target, quantity, net_risk, net_r = protected
    decision = frame.close_ms(index)
    return ex.Plan(frame.symbol, decision, float(entry), float(stop), float(target), float(quantity),
                   float(net_risk), float(net_r), min(decision + geometry.horizon_ms, phase_end)), None


# -------------------------------------------------------------------------- simulation
@dataclass
class Extremes:
    """Lowest low and highest high of the 1m candles in each complete hour and day of a series."""
    hour_low: array
    hour_high: array
    day_low: array
    day_high: array


def extremes(candles: ex.CandleSeries) -> Extremes:
    blocks = []
    for size in (60, 1440):
        lows, highs = array('d'), array('d')
        for first in range(0, candles.count - size + 1, size):
            lows.append(min(candles.low[first:first + size]))
            highs.append(max(candles.high[first:first + size]))
        blocks += [lows, highs]
    return Extremes(*blocks)


def first_touch(candles: ex.CandleSeries, blocks: Extremes, begin: int, end: int, stop: float,
                target: float) -> int | None:
    """First index in [begin, end) whose low reaches ``stop`` or whose high reaches ``target``."""
    low, high = candles.low, candles.high
    index = begin
    while index < end:
        if index % 1440 == 0 and index + 1440 <= end:
            day = index // 1440
            if blocks.day_low[day] > stop and blocks.day_high[day] < target:
                index += 1440
                continue
        if index % 60 == 0 and index + 60 <= end:
            hour = index // 60
            if blocks.hour_low[hour] > stop and blocks.hour_high[hour] < target:
                index += 60
                continue
        if low[index] <= stop or high[index] >= target:
            return index
        index += 1
    return None


def simulate_swing(plan: ex.Plan, candles: ex.CandleSeries, blocks: Extremes, funding: ex.FundingSeries | None,
                   profile: Mapping[str, float], entry_ttl_ms: int) -> ex.Outcome:
    """``exploration.simulate`` with a resting entry and a multi-day deadline.

    The limit fills on the first 1m bar closing within the TTL whose low reaches it. On
    the fill bar a stop exits (ambiguous) and a target is ignored (ambiguous). The stop
    wins when a later bar reaches both bounds; the deadline exits at the prior close.
    """
    outcome = ex.Outcome(plan, release_ms=plan.decision_ms + (entry_ttl_ms // MINUTE + 1) * MINUTE)
    first = candles.index(plan.decision_ms)
    eligible = entry_ttl_ms // MINUTE
    if first < 0 or first + eligible > candles.count:
        raise ExplorationError('decision outside candle coverage')
    fill = None
    for index in range(first, first + eligible):
        if candles.start_ms + (index + 1) * MINUTE >= plan.deadline_ms:
            break
        if candles.low[index] <= plan.entry:
            fill = index
            break
    if fill is None:
        return outcome
    outcome.filled, outcome.fill_ms = True, candles.start_ms + (fill + 1) * MINUTE
    outcome.entry_cost = plan.quantity * plan.entry * (ex.MAKER_FEE + profile['entry_spread_rate']
                                                       + profile['entry_slippage_rate'])
    deadline_index = -((candles.start_ms - plan.deadline_ms) // MINUTE) - 1  # first bar closing at or after it
    if deadline_index >= candles.count:
        raise ExplorationError('position outlives candle coverage')
    if candles.low[fill] <= plan.stop:
        outcome.ambiguous = True
        outcome.exit_ms, outcome.exit_price = outcome.fill_ms, min(candles.open[fill], plan.stop)
        outcome.reason = 'stop'
        return ex.settle(outcome, candles, funding, profile, fill, fill)
    outcome.ambiguous = candles.high[fill] >= plan.target
    hit = first_touch(candles, blocks, fill + 1, deadline_index, plan.stop, plan.target)
    if hit is None:
        outcome.exit_ms, outcome.exit_price = plan.deadline_ms, candles.close[deadline_index - 1]
        outcome.reason, last = 'holding_deadline', deadline_index
    else:
        outcome.exit_ms, last = candles.start_ms + (hit + 1) * MINUTE, hit
        if candles.low[hit] <= plan.stop:
            outcome.ambiguous = outcome.ambiguous or candles.high[hit] >= plan.target
            outcome.exit_price, outcome.reason = min(candles.open[hit], plan.stop), 'stop'
        else:
            outcome.exit_price, outcome.reason = plan.target, 'target'
    return ex.settle(outcome, candles, funding, profile, fill, last)


def run_swing(frames: Mapping[str, Frame], candles: Mapping[str, ex.CandleSeries],
              blocks: Mapping[str, Extremes], funding: Mapping[str, ex.FundingSeries] | None,
              policy: SwingPolicy, geometry: SwingGeometry, instruments: Mapping[str, Mapping[str, Any]],
              profile: Mapping[str, float], window: tuple[int, int], *, max_active: int = MAX_ACTIVE,
              signals: Sequence[tuple[int, int, str, int]] | None = None) -> tuple[dict, list[ex.Outcome]]:
    """Replay one swing hypothesis over a pre-holdout phase with shared portfolio admission.

    Admission order: one active plan per symbol, then ``max_active`` (pending entries
    included), then the plan arithmetic. A rejected plan never holds capacity, so the
    order changes only the rejection counts.
    """
    start, end = window
    if start >= end or end > HOLDOUT_START_MS:
        raise ExplorationError('holdout or empty window')
    if signals is None:
        signals = select_signals(frames, policy, window)
    rejections: Counter[str] = Counter()
    active: list[ex.Outcome] = []
    closed: list[ex.Outcome] = []
    for decision, _, symbol, index in signals:
        still = []
        for outcome in active:
            if outcome.release_ms <= decision:
                if outcome.filled:
                    closed.append(outcome)
            else:
                still.append(outcome)
        active = still
        if any(outcome.plan.symbol == symbol for outcome in active):
            rejections['research_symbol_already_active'] += 1
            continue
        if len(active) >= max_active:
            rejections['research_portfolio_concurrency_exceeded'] += 1
            continue
        plan, reason = build_swing_plan(frames[symbol], index, geometry, instruments[symbol], profile, end)
        if plan is None:
            rejections[reason] += 1
            continue
        if not ex.holding_window_available(plan):
            rejections['research_holding_window_unavailable'] += 1
            continue
        active.append(simulate_swing(plan, candles[symbol], blocks[symbol],
                                     funding[symbol] if funding is not None else None, profile,
                                     geometry.entry_ttl_ms))
    closed.extend(outcome for outcome in active if outcome.filled)
    summary = ex.summarize(len(signals), rejections, closed)
    trades = [outcome for outcome in closed if outcome.filled]
    summary['mean_holding_hours'] = (statistics.fmean((o.exit_ms - o.fill_ms) / HOUR for o in trades)
                                     if trades else None)
    summary['phase_end_exits'] = sum(1 for o in trades if o.reason == 'holding_deadline' and o.plan.deadline_ms == end)
    return summary, closed


def select_signals(frames: Mapping[str, Frame], policy: SwingPolicy,
                   window: tuple[int, int]) -> list[tuple[int, int, str, int]]:
    start, end = window
    return sorted((frame.close_ms(index), SYMBOLS.index(symbol), symbol, index)
                  for symbol, frame in frames.items() for index in frame.decisions(start, end)
                  if policy.predicate(frame, index))


# ------------------------------------------------------------------------------ parity
_PHP_FIELDS = {'close': 'close', 'rsi': 'rsi', 'atr': 'atr', 'ema20': 'ema_20', 'ema50': 'ema_50',
               'ema9': 'e9', 'ema21': 'e21', 'ema9p': 'e9p', 'ema21p': 'e21p', 'adx': 'adx14',
               'ma_21_plus_k_atr': 'ma_21_plus_k_atr'}


def compact_parity(frame_or_bars: Frame | Bars, step: int, rows: Iterable[Mapping[str, Any]],
                   timeframe: str) -> tuple[int, Counter]:
    """Compare retained compact PHP contexts (``exploration`` kept rows) with the Python indicators.

    A row evaluated at ``ms`` holds the context of the last ``timeframe`` bar closed at ``ms``.
    """
    compared, mismatches = 0, Counter()
    cache: dict[int, dict[str, float] | None] = {}
    for row in rows:
        context = row[timeframe]
        if isinstance(frame_or_bars, Frame):
            index = (row['ms'] - frame_or_bars.start_ms) // step - 1
            values = {name: frame_or_bars[name][index] for name in _PHP_FIELDS}
        else:
            index = (row['ms'] - frame_or_bars.start_ms) // step - 1
            if index not in cache:
                cache[index] = snapshot(frame_or_bars, index)
            values = cache[index]
        if values is None or values['close'] is None:
            mismatches['missing_window'] += 1
            continue
        compared += 1
        for mine, theirs in _PHP_FIELDS.items():
            if values[mine] != context[theirs]:
                mismatches[mine] += 1
    return compared, mismatches


_EVALUATED = re.compile(rb'"evaluated_ms":\s*(\d+)')


def raw_parity(frame: Frame, lines: Iterable[bytes]) -> tuple[int, Counter]:
    """Compare full PHP 4h contexts of retained signal results at every 4h decision."""
    compared, mismatches = 0, Counter()
    for line in lines:
        found = _EVALUATED.search(line, 0, 4096)
        if found is None:
            raise ExplorationError('signal result without evaluated_ms')
        decision = int(found.group(1))
        if decision % FOUR_HOURS:
            continue
        record = json.loads(line)
        if record['symbol'] != frame.symbol or record['evaluated_ms'] != decision:
            raise ExplorationError('signal result symbol or time mismatch')
        if decision >= HOLDOUT_START_MS:
            raise ExplorationError('holdout signal rows are not exploratory inputs')
        context = record['contexts']['4h']
        index = (decision - frame.start_ms) // FOUR_HOURS - 1
        if context['available_ms'] != decision or context['open_ms'] != frame.close_ms(index) - FOUR_HOURS:
            raise ExplorationError('4h context is not the bar closing at the decision')
        theirs = {'close': context['close'], 'rsi': context['rsi'], 'atr': context['atr'],
                  'adx': context['adx']['14'], 'ma21': context['ma21'], 'ma_21_plus_k_atr': context['ma_21_plus_k_atr'],
                  **{f'ema{p}': context['ema'][str(p)] for p in EMA_PERIODS},
                  **{f'ema{p}p': context['ema_prev'][str(p)] for p in EMA_PERIODS}}
        compared += 1
        for name, value in theirs.items():
            if frame[name][index] != value:
                mismatches[name] += 1
    return compared, mismatches


# --------------------------------------------------------------------------------- CLI
def load_frames(root: Path) -> dict[str, Frame]:
    return {s: Frame.from_json(json.loads((root / 'features' / f'{s}.frame.json').read_text())) for s in SYMBOLS}


def _load_market(root: Path) -> tuple[dict, dict, dict, dict, dict]:
    candles = {s: ex.CandleSeries.read(root / 'candles' / f'{s}.candles.bin') for s in SYMBOLS}
    if any(series.start_ms % DAY for series in candles.values()):
        raise ExplorationError('swing candles must start on a UTC day')
    funding_doc = json.loads((root / 'funding.json').read_text())
    funding = {s: ex.FundingSeries.from_events(funding_doc[s]) for s in SYMBOLS}
    instruments = {i['symbol']: i for i in json.loads((root / 'instrument-assumptions.json').read_text())['symbols']}
    profiles = json.loads((root / 'cost-assumptions.json').read_text())['profiles']
    return candles, {s: extremes(c) for s, c in candles.items()}, funding, instruments, profiles


def grid(root: Path, phase: str, policies: Sequence[str], geometries: Sequence[str], profiles: Sequence[str],
         *, max_active: int = MAX_ACTIVE) -> Iterator[dict]:
    frames = load_frames(root)
    candles, blocks, funding, instruments, cost_profiles = _load_market(root)
    for policy_id in policies:
        policy = POLICIES[policy_id]
        signals = select_signals(frames, policy, PHASES[phase])
        for geometry_id in geometries:
            for profile in profiles:
                summary, _ = run_swing(frames, candles, blocks, funding, policy, GEOMETRIES[geometry_id], instruments,
                                       cost_profiles[profile], PHASES[phase], max_active=max_active, signals=signals)
                yield {'phase': phase, 'policy': policy_id, 'geometry_id': geometry_id, 'profile': profile,
                       'max_active': max_active, **summary}


def _grid_job(arguments: tuple) -> list[dict]:
    root, phase, policy, geometries, profiles, max_active = arguments
    return list(grid(Path(root), phase, (policy,), geometries, profiles, max_active=max_active))


def _map(function: Callable, items: Sequence, workers: int) -> list:
    """``map`` in worker processes, or in this process for one worker."""
    if workers <= 1:
        return [function(item) for item in items]
    with ProcessPoolExecutor(max_workers=workers) as pool:
        return list(pool.map(function, items))


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    commands = parser.add_subparsers(dest='command', required=True)
    prep = commands.add_parser('prepare', help='verified candles from 2022-11-01, funding and assumptions')
    prep.add_argument('--root', type=Path, required=True)
    prep.add_argument('--dataset-root', type=Path, required=True)
    prep.add_argument('--funding-supplement-root', type=Path, required=True)
    prep.add_argument('--instrument-path', type=Path, required=True)
    prep.add_argument('--cost-path', type=Path, required=True)
    features = commands.add_parser('features', help='4h bars and PHP-identical indicators per symbol')
    features.add_argument('--root', type=Path, required=True)
    features.add_argument('--workers', type=int, default=4)
    parity = commands.add_parser('parity', help='compare the indicators with retained PHP contexts')
    parity.add_argument('--root', type=Path, required=True)
    parity.add_argument('--rows', type=Path, nargs='*', default=(), help='exploration kept-row directories')
    parity.add_argument('--results', type=Path, nargs='*', default=(), help='retained *.results.ndjson files')
    run = commands.add_parser('grid', help='replay swing hypotheses over a prepared root')
    run.add_argument('--root', type=Path, required=True)
    run.add_argument('--phase', choices=tuple(PHASES), required=True)
    run.add_argument('--policies', default=','.join(POLICIES))
    run.add_argument('--geometries', default=','.join(GEOMETRIES))
    run.add_argument('--profiles', default='baseline,adverse')
    run.add_argument('--max-active', type=int, default=MAX_ACTIVE)
    run.add_argument('--workers', type=int, default=4)
    run.add_argument('--output', type=Path, required=True)
    args = parser.parse_args(argv)
    if args.command == 'prepare':
        print(json.dumps(ex.prepare(args.root, args.dataset_root, args.funding_supplement_root, args.instrument_path,
                                    args.cost_path, candle_start=CANDLE_START)))
        return 0
    if args.command == 'features':
        (args.root / 'features').mkdir(parents=True, exist_ok=True)
        paths = [str(args.root / 'candles' / f'{s}.candles.bin') for s in SYMBOLS]
        for document in _map(_frame_job, paths, args.workers):
            (args.root / 'features' / f"{document['symbol']}.frame.json").write_text(json.dumps(document))
        print(json.dumps({'frames': len(paths), 'root': str(args.root)}))
        return 0
    if args.command == 'parity':
        frames = load_frames(args.root)
        report: dict[str, Any] = {'execution_authority': 'none', 'compact': {}, 'raw': {}}
        hourly = {s: aggregate(ex.CandleSeries.read(args.root / 'candles' / f'{s}.candles.bin'), HOUR)
                  for s in SYMBOLS} if args.rows else {}
        for directory in args.rows:
            for symbol in SYMBOLS:
                rows = json.loads((directory / f'{symbol}.kept.json').read_text())
                for timeframe, source, step in (('4h', frames[symbol], FOUR_HOURS), ('1h', hourly[symbol], HOUR)):
                    compared, mismatches = compact_parity(source, step, rows, timeframe)
                    report['compact'][f'{directory.name}:{symbol}:{timeframe}'] = {
                        'compared': compared, 'mismatches': dict(mismatches)}
        for path in args.results:
            symbol = path.name.split('.')[0]
            with open(path, 'rb') as handle:
                compared, mismatches = raw_parity(frames[symbol], handle)
            report['raw'][str(path)] = {'compared': compared, 'mismatches': dict(mismatches)}
        print(json.dumps(report, indent=1))
        return 1 if any(entry['mismatches'] for part in ('compact', 'raw') for entry in report[part].values()) else 0
    geometries = tuple(args.geometries.split(','))
    profiles = tuple(args.profiles.split(','))
    jobs = [(str(args.root), args.phase, policy, geometries, profiles, args.max_active)
            for policy in args.policies.split(',')]
    results = [row for rows in _map(_grid_job, jobs, args.workers) for row in rows]
    args.output.write_text(json.dumps(results))
    print(json.dumps({'runs': len(results), 'output': str(args.output)}))
    return 0


if __name__ == '__main__':  # pragma: no cover - module CLI
    raise SystemExit(main())
