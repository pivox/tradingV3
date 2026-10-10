"""Fast exploratory screen of day_trading entry filters and plan geometry.

This module answers "which hypotheses deserve a canonical campaign?" in seconds.
It replays retained B1 research signal rows (PHP section/filter verdicts plus
their compact indicator contexts) one plan at a time, instead of one minute of
the whole universe at a time, through:

* the scalar filter conditions of ``day_trading.trend_continuation.long@1.1.0``,
  re-implemented here and checked against every retained PHP filter verdict;
* the plan arithmetic of ``ResearchPlanBuilder`` (``EntryZonePriceMath``,
  ``ProtectionPriceMath``, ``NetRCostMath`` and the 250 quote notional cap of
  ``CanonicalRiskEngine``) with exact decimal tick/step quantization;
* the fill, exit, cost, funding and admission rules of ``PortfolioSimulator``.

Parity with the canonical kernel is a tested property (see
``tests/test_research_exploration.py``), not an assumption. Results remain an
exploratory screen: they never read holdout data, never create campaign
artifacts and grant no execution authority. A promising hypothesis must still
be published as a versioned setup and confirmed by the canonical campaign.
"""
from __future__ import annotations

import argparse
import json
import math
from array import array
from bisect import bisect_right
from collections import Counter, defaultdict
from collections.abc import Callable, Iterable, Iterator, Mapping, Sequence
from dataclasses import dataclass, field
from datetime import datetime, timezone
from decimal import Decimal
from pathlib import Path
from typing import Any

SYMBOLS = ('BTCUSDT', 'ETHUSDT', 'BNBUSDT', 'XRPUSDT', 'ADAUSDT',
           'DOGEUSDT', 'SOLUSDT', 'LTCUSDT', 'LINKUSDT', 'AVAXUSDT')
TIMEFRAMES = ('1m', '5m', '15m', '1h', '4h')
SECTIONS = ('regime', 'context', 'trigger', 'confirmations')
FILTER_IDS = ('rsi_lt_70', 'adx_min_for_trend_1h', 'pullback_confirmed_ma9_21',
              'pullback_confirmed_vwap', 'price_lte_ma21_plus_k_atr')
BASELINE_SETUP_HASH = 'fbdbf414d2824523d68c862fc07b1623875c71305fc3d1ceac03999629f22f05'
CONTEXT_FIELDS = ('close', 'rsi', 'vwap', 'atr', 'ma_21_plus_k_atr', 'pullback_age_bars', 'ema_20', 'ema_50')

MINUTE = 60_000
DAY = 86_400_000
HOLDOUT_START_MS = 1_767_225_600_000  # 2026-01-01T00:00:00Z, never readable here.
PHASES = {'training': (1_672_531_200_000, 1_735_689_600_000),
          'validation': (1_735_689_600_000, HOLDOUT_START_MS)}

MAKER_FEE = 0.0002
TAKER_FEE = 0.0005
NOTIONAL_CAP = 250.0
EXCHANGE_MIN_NOTIONAL = 5.0
MINIMUM_NET_R = 1.3
HOLDING_MS = 8 * 3_600_000
ENTRY_FILL_WINDOW_MS = 90_000
PENDING_RELEASE_MS = 2 * MINUTE
MAX_CONCURRENT = 4
DAILY_LOSS_CAP = 30.0
DEADLINE_REASONS = ('holding_deadline', 'midnight')

TRAINING_THRESHOLDS = {'minimum_trades': 100, 'minimum_trades_per_year': 30, 'minimum_pairs': 5,
                       'baseline_profit_factor': 1.2, 'adverse_profit_factor': 1.0,
                       'maximum_drawdown_quote': 6000.0}
MINIMUM_VALIDATION_TRADES = 50


class ExplorationError(ValueError):
    """Inputs cannot support a faithful exploratory replay."""


# --------------------------------------------------------------------------- conditions
def _is_float(value: Any) -> bool:
    return isinstance(value, float)


def rsi_below(context: Mapping[str, Any], threshold: float = 73.0) -> bool:
    return _is_float(context.get('rsi')) and context['rsi'] < threshold


def adx_at_least(context: Mapping[str, Any], threshold: float = 20.0) -> bool:
    value = context.get('adx14')
    return isinstance(value, (int, float)) and not isinstance(value, bool) and value >= threshold


def ema9_crossed_up_ema21(context: Mapping[str, Any]) -> bool:
    values = (context.get('e9'), context.get('e21'), context.get('e9p'), context.get('e21p'))
    return all(_is_float(v) for v in values) and values[2] <= values[3] and values[0] > values[1]


def near_vwap(context: Mapping[str, Any], tolerance: float = 0.0015) -> bool:
    close, vwap = context.get('close'), context.get('vwap')
    return _is_float(close) and _is_float(vwap) and vwap != 0.0 and abs(close / vwap - 1.0) <= tolerance


def below_ma21_plus_k_atr(context: Mapping[str, Any]) -> bool:
    close, level = context.get('close'), context.get('ma_21_plus_k_atr')
    return _is_float(close) and _is_float(level) and close <= level * (1.0 + 1.0e-8)


def recent_pullback(context: Mapping[str, Any], bars: int = 3) -> bool:
    age = context.get('pullback_age_bars')
    return isinstance(age, int) and not isinstance(age, bool) and 0 <= age <= bars


def on_all_timeframes(condition: Callable[..., bool], row: Mapping[str, Any], *args: Any) -> bool:
    """The canonical ``global`` aggregation: every available snapshot must pass."""
    return all(condition(row[timeframe], *args) for timeframe in TIMEFRAMES)


def published_filter_verdicts(row: Mapping[str, Any]) -> tuple[bool, ...]:
    """The five 1.1.0 filters exactly as the canonical runtime evaluates them."""
    return (on_all_timeframes(rsi_below, row), adx_at_least(row['1h']),
            on_all_timeframes(ema9_crossed_up_ema21, row), on_all_timeframes(near_vwap, row),
            on_all_timeframes(below_ma21_plus_k_atr, row))


# ---------------------------------------------------------------------------- policies
@dataclass(frozen=True)
class FilterPolicy:
    id: str
    sections: tuple[str, ...]
    predicate: Callable[[Mapping[str, Any]], bool]
    description: str


_NO_CONFIRMATIONS = SECTIONS[:3]
POLICIES: dict[str, FilterPolicy] = {p.id: p for p in (
    FilterPolicy('P0_published', SECTIONS, lambda r: all(r['bf']), 'Published 1.1.0 filters (global aggregation).'),
    FilterPolicy('S_sections_only', SECTIONS, lambda r: True, 'All four sections, no filter.'),
    FilterPolicy('D2_drop_two_global_pullbacks', SECTIONS, lambda r: r['bf'][0] and r['bf'][1] and r['bf'][4],
                 'Published filters minus the two global pullback filters.'),
    FilterPolicy('X1_exec15_cross_near015_extall', SECTIONS,
                 lambda r: rsi_below(r['15m']) and adx_at_least(r['1h']) and ema9_crossed_up_ema21(r['15m'])
                 and near_vwap(r['15m']) and on_all_timeframes(below_ma21_plus_k_atr, r),
                 'Pullback filters on the 15m execution timeframe only.'),
    FilterPolicy('X2_exec15_cross_near040_extall', SECTIONS,
                 lambda r: rsi_below(r['15m']) and adx_at_least(r['1h']) and ema9_crossed_up_ema21(r['15m'])
                 and near_vwap(r['15m'], 0.004) and on_all_timeframes(below_ma21_plus_k_atr, r),
                 'As X1 with a 0.4 % VWAP tolerance.'),
    FilterPolicy('X3_exec15_cross_extall', SECTIONS,
                 lambda r: rsi_below(r['15m']) and adx_at_least(r['1h']) and ema9_crossed_up_ema21(r['15m'])
                 and on_all_timeframes(below_ma21_plus_k_atr, r), 'Fresh 15m EMA9/21 cross, no VWAP filter.'),
    FilterPolicy('X4_exec15_near015_extall', SECTIONS,
                 lambda r: rsi_below(r['15m']) and adx_at_least(r['1h']) and near_vwap(r['15m'])
                 and on_all_timeframes(below_ma21_plus_k_atr, r), '15m VWAP proximity, no cross filter.'),
    FilterPolicy('X5_rsi15_adx_extall', SECTIONS,
                 lambda r: rsi_below(r['15m']) and adx_at_least(r['1h']) and on_all_timeframes(below_ma21_plus_k_atr, r),
                 'No pullback filter, global anti-extension.'),
    FilterPolicy('X6_rsi15_adx', SECTIONS, lambda r: rsi_below(r['15m']) and adx_at_least(r['1h']),
                 '15m RSI and 1h ADX only.'),
    FilterPolicy('X7_rsi15_adx_ext15', SECTIONS,
                 lambda r: rsi_below(r['15m']) and adx_at_least(r['1h']) and below_ma21_plus_k_atr(r['15m']),
                 '15m RSI, 1h ADX and 15m anti-extension.'),
    FilterPolicy('Q1_pullback15_3_extall', SECTIONS,
                 lambda r: rsi_below(r['15m']) and adx_at_least(r['1h']) and recent_pullback(r['15m'])
                 and on_all_timeframes(below_ma21_plus_k_atr, r), '15m pullback event within three bars.'),
    FilterPolicy('Q2_pullback15_3_ext15', SECTIONS,
                 lambda r: rsi_below(r['15m']) and adx_at_least(r['1h']) and recent_pullback(r['15m'])
                 and below_ma21_plus_k_atr(r['15m']), 'Q1 with 15m anti-extension.'),
    FilterPolicy('Q3_pullback15_3', SECTIONS,
                 lambda r: rsi_below(r['15m']) and adx_at_least(r['1h']) and recent_pullback(r['15m']),
                 'Q1 without anti-extension.'),
    FilterPolicy('N1_noconf_published_filters_minus_pullbacks', _NO_CONFIRMATIONS,
                 lambda r: r['bf'][0] and r['bf'][1] and r['bf'][4], 'D2 without the 5m/1m confirmation section.'),
    FilterPolicy('N2_noconf_rsi15_adx_ext15', _NO_CONFIRMATIONS,
                 lambda r: rsi_below(r['15m']) and adx_at_least(r['1h']) and below_ma21_plus_k_atr(r['15m']),
                 'X7 without the 5m/1m confirmation section.'),
    FilterPolicy('N3_noconf_pullback15_3_ext15', _NO_CONFIRMATIONS,
                 lambda r: rsi_below(r['15m']) and adx_at_least(r['1h']) and recent_pullback(r['15m'])
                 and below_ma21_plus_k_atr(r['15m']), 'Q2 without the 5m/1m confirmation section.'),
)}


# --------------------------------------------------------------------------- geometries
@dataclass(frozen=True)
class Geometry:
    anchor_source: str = 'vwap'
    anchor_timeframe: str = '5m'
    atr_timeframe: str = '5m'
    zone_atr_multiplier: float = 0.30
    minimum_half_width_rate: float = 0.0005
    maximum_half_width_rate: float = 0.01
    stop_timeframe: str = '5m'
    stop_atr_multiplier: float = 1.5
    target_risk_multiple: float = 2.0

    def __post_init__(self) -> None:
        if (self.anchor_source not in ('vwap', 'ema_20')
                or any(tf not in TIMEFRAMES for tf in (self.anchor_timeframe, self.atr_timeframe, self.stop_timeframe))
                or not 0 < self.minimum_half_width_rate <= self.maximum_half_width_rate < 1
                or min(self.zone_atr_multiplier, self.stop_atr_multiplier, self.target_risk_multiple) <= 0):
            raise ExplorationError('geometry invalid')


# The canonical ResearchVariant catalogue, then a bounded higher-timeframe stop family.
GEOMETRIES: dict[str, Geometry] = {
    'baseline': Geometry(),
    'ema20_5m': Geometry(anchor_source='ema_20'),
    'vwap_15m': Geometry(anchor_timeframe='15m'),
    'ema20_15m': Geometry(anchor_source='ema_20', anchor_timeframe='15m'),
    'width_050': Geometry(zone_atr_multiplier=0.50),
    'width_075': Geometry(zone_atr_multiplier=0.75),
    'min_width_001': Geometry(minimum_half_width_rate=0.001),
    'max_width_002': Geometry(maximum_half_width_rate=0.02),
    'stop_125': Geometry(stop_atr_multiplier=1.25),
    'stop_200': Geometry(stop_atr_multiplier=2.0),
    'target_150': Geometry(target_risk_multiple=1.5),
    'target_250': Geometry(target_risk_multiple=2.5),
    'ema20_5m_width_050': Geometry(anchor_source='ema_20', zone_atr_multiplier=0.50),
}
CANONICAL_GEOMETRIES = tuple(GEOMETRIES)
_ZONES = {
    'Zema5w50': {'anchor_source': 'ema_20', 'zone_atr_multiplier': 0.50},
    'Zvwap5w75': {'zone_atr_multiplier': 0.75},
    'Zema15w30': {'anchor_source': 'ema_20', 'anchor_timeframe': '15m'},
    'Zema15a15w50': {'anchor_source': 'ema_20', 'anchor_timeframe': '15m', 'atr_timeframe': '15m',
                     'zone_atr_multiplier': 0.50},
}
_STOPS = {'S5x15': ('5m', 1.5), 'S15x10': ('15m', 1.0), 'S15x15': ('15m', 1.5), 'S15x20': ('15m', 2.0),
          'S1hx10': ('1h', 1.0), 'S1hx15': ('1h', 1.5)}
_TARGETS = {'T15': 1.5, 'T20': 2.0, 'T30': 3.0}
for _zone, _zone_diff in _ZONES.items():
    for _stop, (_stop_tf, _stop_multiple) in _STOPS.items():
        for _target, _risk_multiple in _TARGETS.items():
            GEOMETRIES[f'{_zone}.{_stop}.{_target}'] = Geometry(
                **_zone_diff, stop_timeframe=_stop_tf, stop_atr_multiplier=_stop_multiple,
                target_risk_multiple=_risk_multiple)
EXTENDED_GEOMETRIES = tuple(g for g in GEOMETRIES if g not in CANONICAL_GEOMETRIES)


# --------------------------------------------------------------------------- market data
def _ticks(count: int, tick: float) -> float:
    """Nearest double to an exact decimal multiple, matching decimal price strings."""
    return float(Decimal(count) * Decimal(repr(tick)))


def floor_to(value: float, tick: float) -> float:
    return _ticks(math.floor(value / tick + 1e-9), tick)


def ceil_to(value: float, tick: float) -> float:
    return _ticks(math.ceil(value / tick - 1e-9), tick)


@dataclass
class CandleSeries:
    """Contiguous 1m OHLC for one symbol, indexed by open time."""
    symbol: str
    start_ms: int
    open: array
    high: array
    low: array
    close: array

    def __post_init__(self) -> None:
        if (self.start_ms % MINUTE or not len(self.close)
                or not len(self.open) == len(self.high) == len(self.low) == len(self.close)):
            raise ExplorationError('candle series invalid')

    @property
    def count(self) -> int:
        return len(self.close)

    def index(self, open_ms: int) -> int:
        return (open_ms - self.start_ms) // MINUTE

    def write(self, path: Path, header: Mapping[str, Any]) -> None:
        raw = json.dumps({**header, 'symbol': self.symbol, 'start_ms': self.start_ms, 'count': self.count}).encode()
        with open(path, 'wb') as handle:
            handle.write(len(raw).to_bytes(4, 'little'))
            handle.write(raw)
            for values in (self.open, self.high, self.low, self.close):
                values.tofile(handle)

    @classmethod
    def read(cls, path: Path) -> 'CandleSeries':
        with open(path, 'rb') as handle:
            header = json.loads(handle.read(int.from_bytes(handle.read(4), 'little')))
            arrays = []
            for _ in range(4):
                values = array('d')
                values.fromfile(handle, header['count'])
                arrays.append(values)
        return cls(header['symbol'], header['start_ms'], *arrays)


@dataclass
class FundingSeries:
    """Observed funding events of one symbol, sorted by timestamp."""
    timestamps: array = field(default_factory=lambda: array('q'))
    rates: array = field(default_factory=lambda: array('d'))
    marks: list[float | None] = field(default_factory=list)

    @classmethod
    def from_events(cls, events: Iterable[Sequence[Any]]) -> 'FundingSeries':
        series = cls()
        for timestamp, rate, mark in sorted(events, key=lambda e: e[0]):
            series.timestamps.append(int(timestamp))
            series.rates.append(float(rate))
            series.marks.append(None if mark is None else float(mark))
        return series


# ------------------------------------------------------------------------------- plans
@dataclass(frozen=True)
class Plan:
    symbol: str
    decision_ms: int
    entry: float
    stop: float
    target: float
    quantity: float
    net_risk: float
    net_r: float
    deadline_ms: int


def build_plan(row: Mapping[str, Any], symbol: str, geometry: Geometry, instrument: Mapping[str, Any],
               profile: Mapping[str, float]) -> tuple[Plan | None, str | None]:
    """ResearchPlanBuilder arithmetic for one signal; returns (plan, None) or (None, reason)."""
    anchor = row[geometry.anchor_timeframe].get(geometry.anchor_source)
    atr = row[geometry.atr_timeframe].get('atr')
    stop_atr = row[geometry.stop_timeframe].get('atr')
    candidate = row['15m'].get('close')
    for value in (anchor, atr, stop_atr, candidate):
        if (not isinstance(value, (int, float)) or isinstance(value, bool)
                or not math.isfinite(value) or value <= 0):
            return None, 'research_signal_price_context_missing'
    tick = instrument['tick_size']
    half_width = min(max(atr * geometry.zone_atr_multiplier, anchor * geometry.minimum_half_width_rate),
                     anchor * geometry.maximum_half_width_rate)
    lower, upper = floor_to(anchor - half_width, tick), ceil_to(anchor + half_width, tick)
    if lower <= 0 or upper <= lower:
        return None, 'canonical_entry_zone_bounds_invalid'
    entry = ceil_to(candidate, tick)
    if not lower <= entry <= upper:
        return None, 'canonical_entry_zone_candidate_outside'
    stop = floor_to(entry - stop_atr * geometry.stop_atr_multiplier, tick)
    if not 0 < stop < entry:
        return None, 'canonical_protection_stop_invalid'
    risk_distance = entry - stop
    target = floor_to(entry + risk_distance * geometry.target_risk_multiple, tick)
    if target <= entry:
        return None, 'canonical_protection_target_polarity_invalid'
    quantity = floor_to(NOTIONAL_CAP / entry, instrument['quantity_step'])
    if quantity <= 0 or quantity < instrument['min_quantity']:
        return None, 'canonical_risk_quantity_below_minimum'
    notional = entry * quantity
    if notional < max(EXCHANGE_MIN_NOTIONAL, instrument['min_notional']):
        return None, 'research_instrument_notional_below_minimum'
    stop_notional, target_notional = stop * quantity, target * quantity
    funding = notional * max(0.0, profile['funding_provision_rate'])
    net_risk = (risk_distance * quantity + notional * MAKER_FEE + stop_notional * TAKER_FEE
                + notional * (profile['entry_spread_rate'] + profile['entry_slippage_rate'])
                + stop_notional * (profile['stop_spread_rate'] + profile['stop_slippage_rate']) + funding)
    net_reward = ((target - entry) * quantity - notional * MAKER_FEE - target_notional * TAKER_FEE
                  - notional * (profile['entry_spread_rate'] + profile['entry_slippage_rate'])
                  - target_notional * (profile['target_spread_rate'] + profile['target_slippage_rate']) - funding)
    if net_reward / net_risk < MINIMUM_NET_R:
        return None, 'research_minimum_net_r_not_met'
    decision = row['ms']
    deadline = min(decision + HOLDING_MS, (decision // DAY + 1) * DAY)
    if deadline <= decision + MINUTE:
        return None, 'research_holding_window_unavailable'
    return Plan(symbol, decision, entry, stop, target, quantity, net_risk, net_reward / net_risk, deadline), None


# ------------------------------------------------------------------------- simulation
@dataclass
class Outcome:
    plan: Plan
    filled: bool = False
    release_ms: int = 0
    fill_ms: int | None = None
    exit_ms: int | None = None
    exit_price: float | None = None
    reason: str = 'entry_expired'
    ambiguous: bool = False
    entry_cost: float = 0.0
    exit_cash: float = 0.0
    funding: float = 0.0
    funding_cash: list[tuple[int, float]] = field(default_factory=list)
    net: float = 0.0
    net_r: float = 0.0


def simulate(plan: Plan, candles: CandleSeries, funding: FundingSeries | None,
             profile: Mapping[str, float]) -> Outcome:
    """PortfolioSimulator rules for one admitted plan (conservative closed-OHLC proxy)."""
    outcome = Outcome(plan, release_ms=plan.decision_ms + PENDING_RELEASE_MS)
    first = candles.index(plan.decision_ms)
    if not 0 <= first < candles.count:
        raise ExplorationError('decision outside candle coverage')
    fill_boundary = plan.decision_ms + MINUTE
    if not (candles.low[first] <= plan.entry and fill_boundary < plan.deadline_ms):
        return outcome
    outcome.filled, outcome.fill_ms = True, fill_boundary
    outcome.entry_cost = plan.quantity * plan.entry * (MAKER_FEE + profile['entry_spread_rate'] + profile['entry_slippage_rate'])
    index, new = first, True
    while True:
        boundary = candles.start_ms + (index + 1) * MINUTE
        if not new and plan.deadline_ms <= boundary:
            outcome.exit_ms, outcome.exit_price = plan.deadline_ms, candles.close[index - 1]
            outcome.reason = 'midnight' if plan.deadline_ms // DAY > plan.decision_ms // DAY else 'holding_deadline'
            break
        if candles.low[index] <= plan.stop:
            outcome.ambiguous = candles.high[index] >= plan.target or new
            outcome.exit_ms, outcome.exit_price = boundary, min(candles.open[index], plan.stop)
            outcome.reason = 'stop'
            break
        if candles.high[index] >= plan.target:
            if new:
                outcome.ambiguous = True
            else:
                outcome.exit_ms, outcome.exit_price, outcome.reason = boundary, plan.target, 'target'
                break
        new = False
        index += 1
        if index >= candles.count:
            raise ExplorationError('position outlives candle coverage')
    if funding is not None:
        _apply_funding(outcome, candles, funding, first, index)
    gross = plan.quantity * (outcome.exit_price - plan.entry)
    leg = 'target' if outcome.reason == 'target' else 'stop'
    exit_rate = TAKER_FEE + profile[f'{leg}_spread_rate'] + profile[f'{leg}_slippage_rate']
    outcome.exit_cash = gross - plan.quantity * outcome.exit_price * exit_rate
    outcome.net = outcome.exit_cash - outcome.entry_cost + outcome.funding
    outcome.net_r = outcome.net / plan.net_risk
    outcome.release_ms = outcome.exit_ms
    return outcome


def _apply_funding(outcome: Outcome, candles: CandleSeries, funding: FundingSeries, first: int, last: int) -> None:
    plan = outcome.plan
    position = bisect_right(funding.timestamps, candles.start_ms + first * MINUTE)
    last_boundary = candles.start_ms + (last + 1) * MINUTE
    while position < len(funding.timestamps) and funding.timestamps[position] <= last_boundary:
        timestamp, rate, mark = funding.timestamps[position], funding.rates[position], funding.marks[position]
        position += 1
        batch = -((candles.start_ms - timestamp) // MINUTE) - 1  # open < timestamp <= boundary
        boundary = candles.start_ms + (batch + 1) * MINUTE
        exiting = batch == last
        if timestamp >= plan.deadline_ms or (timestamp == boundary and exiting):
            continue
        uncertain_exit = exiting and outcome.reason not in DEADLINE_REASONS
        if timestamp < boundary and (batch == first or uncertain_exit) and rate <= 0:
            continue  # adverse_possible_credit_certain.v1: an uncertain credit is never booked.
        amount = -plan.quantity * (mark if mark is not None else candles.close[batch - 1]) * rate
        outcome.funding += amount
        outcome.funding_cash.append((timestamp, amount))


def realized_today(now: int, outcomes: Iterable[Outcome]) -> float:
    """PortfolioSimulator.daily_realized: cashflows in (UTC day start, now]; midnight belongs to the prior day."""
    day_start = (now // DAY) * DAY
    total = 0.0
    for outcome in outcomes:
        if not outcome.filled:
            continue
        if day_start < outcome.fill_ms <= now:
            total -= outcome.entry_cost
        if day_start < outcome.exit_ms <= now:
            total += outcome.exit_cash
        total += sum(amount for at, amount in outcome.funding_cash if day_start < at <= now)
    return total


def run_hypothesis(rows: Mapping[str, Sequence[Mapping[str, Any]]], candles: Mapping[str, CandleSeries],
                   funding: Mapping[str, FundingSeries] | None, policy: FilterPolicy, geometry: Geometry,
                   instruments: Mapping[str, Mapping[str, Any]], profile: Mapping[str, float],
                   window: tuple[int, int]) -> tuple[dict, list[Outcome]]:
    """Replay one hypothesis over a pre-holdout window with shared portfolio admission."""
    start, end = window
    if start >= end or end > HOLDOUT_START_MS:
        raise ExplorationError('holdout or empty window')
    signals = sorted(((row['ms'], SYMBOLS.index(symbol), symbol, row) for symbol, symbol_rows in rows.items()
                      for row in symbol_rows if start <= row['ms'] < end
                      and all(row['sec'][s] for s in policy.sections) and policy.predicate(row)),
                     key=lambda item: item[:2])
    rejections: Counter[str] = Counter()
    active: list[Outcome] = []
    recent: list[Outcome] = []  # closed today or still carrying today's cashflows
    closed: list[Outcome] = []
    for decision, _, symbol, row in signals:
        still = []
        for outcome in active:
            if outcome.release_ms <= decision:
                if outcome.filled:
                    closed.append(outcome)
                    recent.append(outcome)
            else:
                still.append(outcome)
        active = still
        day_start = (decision // DAY) * DAY
        recent = [o for o in recent if o.exit_ms > day_start]
        plan, reason = build_plan(row, symbol, geometry, instruments[symbol], profile)
        if plan is None:
            rejections[reason] += 1
            continue
        realized = realized_today(decision, recent + active)
        unrealized = sum(o.plan.quantity * (candles[o.plan.symbol].close[candles[o.plan.symbol].index(decision) - 1]
                                            - o.plan.entry)
                         for o in active if o.filled and o.fill_ms <= decision)
        consumed = max(0.0, -realized) + max(0.0, -unrealized) + sum(o.plan.net_risk for o in active)
        if plan.net_risk > DAILY_LOSS_CAP - consumed:
            rejections['research_portfolio_daily_loss_exceeded'] += 1
            continue
        if len(active) >= MAX_CONCURRENT:
            rejections['research_portfolio_concurrency_exceeded'] += 1
            continue
        active.append(simulate(plan, candles[symbol], funding[symbol] if funding is not None else None, profile))
    closed.extend(o for o in active if o.filled)
    return summarize(len(signals), rejections, closed), closed


def summarize(signals: int, rejections: Mapping[str, int], outcomes: Sequence[Outcome]) -> dict:
    trades = sorted((o for o in outcomes if o.filled), key=lambda o: o.exit_ms)
    count = len(trades)
    gains = sum(o.net for o in trades if o.net > 0)
    losses = -sum(o.net for o in trades if o.net <= 0)
    equity = peak = drawdown = 0.0
    for outcome in trades:
        equity += outcome.net
        peak = max(peak, equity)
        drawdown = max(drawdown, peak - equity)
    by_year: dict[str, dict[str, float]] = defaultdict(lambda: {'trades': 0, 'net': 0.0, 'net_r': 0.0})
    by_pair: dict[str, dict[str, float]] = defaultdict(lambda: {'trades': 0, 'net': 0.0, 'net_r': 0.0})
    for outcome in trades:
        year = str(datetime.fromtimestamp(outcome.plan.decision_ms / 1000, tz=timezone.utc).year)
        for bucket in (by_year[year], by_pair[outcome.plan.symbol]):
            bucket['trades'] += 1
            bucket['net'] += outcome.net
            bucket['net_r'] += outcome.net_r
    return {
        'signals': signals, 'rejections': dict(sorted(rejections.items())), 'trades': count,
        'wins': sum(1 for o in trades if o.net > 0),
        'win_rate': sum(1 for o in trades if o.net > 0) / count if count else None,
        'net_pnl': sum(o.net for o in trades), 'funding_quote': sum(o.funding for o in trades),
        'total_net_r': sum(o.net_r for o in trades),
        'mean_net_r': sum(o.net_r for o in trades) / count if count else None,
        'profit_factor': gains / losses if losses > 0 else None,
        'realized_drawdown_quote': drawdown,
        'exit_reasons': dict(sorted(Counter(o.reason for o in trades).items())),
        'ambiguous': sum(1 for o in trades if o.ambiguous),
        'by_year': {k: dict(v) for k, v in sorted(by_year.items())},
        'by_pair': {k: dict(v) for k, v in sorted(by_pair.items())},
        'pairs_with_trades': len(by_pair),
    }


def training_eligible(baseline: Mapping[str, Any], adverse: Mapping[str, Any],
                      years: Sequence[str] = ('2023', '2024')) -> bool:
    """The frozen campaign protocol's training gates, applied to an exploratory replay."""
    t = TRAINING_THRESHOLDS
    by_year = baseline['by_year']
    return (baseline['trades'] >= t['minimum_trades'] and baseline['pairs_with_trades'] >= t['minimum_pairs']
            and all(by_year.get(y, {}).get('trades', 0) >= t['minimum_trades_per_year'] for y in years)
            and all(by_year.get(y, {}).get('net', 0.0) > 0 for y in years)
            and (baseline['profit_factor'] or 0.0) >= t['baseline_profit_factor']
            and (adverse['profit_factor'] or 0.0) >= t['adverse_profit_factor']
            and (baseline['mean_net_r'] or 0.0) > 0
            and baseline['realized_drawdown_quote'] <= t['maximum_drawdown_quote'])


# -------------------------------------------------------------------------- extraction
def extract_rows(lines: Iterable[bytes | str], symbol: str) -> tuple[list[dict], dict]:
    """Keep rows whose regime, context and trigger passed; prove filter parity on every row."""
    parity: Counter[str] = Counter()
    kept: list[dict] = []
    previous = None
    rows = 0
    for line in lines:
        record = json.loads(line)
        if (record.get('schema_version') != 'research-signal-result.v1' or record.get('symbol') != symbol
                or record['baseline'].get('setup_hash') != BASELINE_SETUP_HASH
                or len(record['verdicts']['filters']) != len(FILTER_IDS)):
            raise ExplorationError('unexpected research signal record')
        decision = record['evaluated_ms']
        if decision >= HOLDOUT_START_MS:
            raise ExplorationError('holdout signal rows are not exploratory inputs')
        if previous is not None and decision != previous + 900_000:
            raise ExplorationError('signal rows are not contiguous 15m evaluations')
        previous = decision
        rows += 1
        contexts = record['contexts']
        compact = {timeframe: _compact(contexts[timeframe]) for timeframe in TIMEFRAMES}
        stored = tuple(bool(v['passed']) for v in record['verdicts']['filters'])
        for identifier, mine, theirs in zip(FILTER_IDS, published_filter_verdicts(compact), stored):
            if mine != theirs:
                parity[identifier] += 1
        sections = {name: bool(record['verdicts']['sections'][name]['passed']) for name in SECTIONS}
        if sections['regime'] and sections['context'] and sections['trigger']:
            kept.append({'ms': decision, 'sec': sections, 'bf': list(stored), **compact})
    return kept, {'symbol': symbol, 'rows': rows, 'kept': len(kept), 'filter_mismatches': dict(parity)}


def _compact(context: Mapping[str, Any]) -> dict:
    ema, previous, adx = context.get('ema') or {}, context.get('ema_prev') or {}, context.get('adx')
    return {**{k: context.get(k) for k in CONTEXT_FIELDS},
            'e9': ema.get('9'), 'e21': ema.get('21'), 'e9p': previous.get('9'), 'e21p': previous.get('21'),
            'adx14': adx.get('14') if isinstance(adx, dict) else None}


# ----------------------------------------------------------------------- preparation
def prepare(root: Path, dataset_root: Path, supplement_root: Path, instrument_path: Path, cost_path: Path) -> dict:
    """Materialize verified pre-holdout candles/funding and the frozen assumptions into ``root``."""
    from .funding import REST_HYPOTHESIS, FundingReader
    from .portfolio_simulator import canonical_hash
    from .signal_sources import iter_verified_candles, select_sources

    start, end = '2023-01-01T00:00:00Z', '2026-01-01T00:00:00Z'  # holdout stays unread
    (root / 'candles').mkdir(parents=True, exist_ok=True)
    report: dict[str, Any] = {'candles': {}, 'funding': {}}
    for symbol in SYMBOLS:
        selection = select_sources(dataset_root, start, end, start, symbol)
        values = [array('d') for _ in range(4)]
        first = None
        for record in iter_verified_candles(dataset_root, selection):
            first = record['open_ms'] if first is None else first
            for target, key in zip(values, ('open', 'high', 'low', 'close')):
                target.append(float(record[key]))
        series = CandleSeries(symbol, first, *values)
        series.write(root / 'candles' / f'{symbol}.candles.bin',
                     {'dataset_subset_sha256': selection.dataset_sha256, 'manifest_sha256': selection.manifest_sha256})
        report['candles'][symbol] = {'count': series.count, 'dataset_subset_sha256': selection.dataset_sha256}
    reader = FundingReader(dataset_root, start, end, supplement_root=supplement_root,
                           rest_interval_hypothesis=REST_HYPOTHESIS)
    events: dict[str, list] = {symbol: [] for symbol in SYMBOLS}
    for event in reader.iter_events():
        events[event.symbol].append([event.timestamp_ms, float(event.rate),
                                     None if event.observed_mark is None else float(event.observed_mark)])
    (root / 'funding.json').write_text(json.dumps(events))
    report['funding'] = {symbol: len(rows) for symbol, rows in events.items()}
    for source, name, hash_field in ((instrument_path, 'instrument-assumptions.json', 'manifest_hash'),
                                     (cost_path, 'cost-assumptions.json', 'assumption_hash')):
        document = json.loads(source.read_text())
        if canonical_hash({k: v for k, v in document.items() if k != hash_field}) != document.get(hash_field):
            raise ExplorationError(f'{name} hash conflict')
        (root / name).write_text(json.dumps(document))
    (root / 'prepare-report.json').write_text(json.dumps(report, indent=1))
    return report


# ------------------------------------------------------------------------------- CLI
def _load_inputs(root: Path, phase: str) -> tuple[dict, dict, dict, dict, dict]:
    rows = {s: json.loads((root / phase / f'{s}.kept.json').read_text()) for s in SYMBOLS}
    candles = {s: CandleSeries.read(root / 'candles' / f'{s}.candles.bin') for s in SYMBOLS}
    funding_doc = json.loads((root / 'funding.json').read_text())
    funding = {s: FundingSeries.from_events(funding_doc[s]) for s in SYMBOLS}
    instruments_doc = json.loads((root / 'instrument-assumptions.json').read_text())
    instruments = {i['symbol']: i for i in instruments_doc['symbols']}
    profiles = json.loads((root / 'cost-assumptions.json').read_text())['profiles']
    return rows, candles, funding, instruments, profiles


def grid(root: Path, phase: str, policies: Sequence[str], geometries: Sequence[str],
         profiles: Sequence[str]) -> Iterator[dict]:
    rows, candles, funding, instruments, cost_profiles = _load_inputs(root, phase)
    for policy in policies:
        for geometry in geometries:
            for profile in profiles:
                summary, _ = run_hypothesis(rows, candles, funding, POLICIES[policy], GEOMETRIES[geometry],
                                            instruments, cost_profiles[profile], PHASES[phase])
                yield {'phase': phase, 'policy': policy, 'geometry_id': geometry, 'profile': profile, **summary}


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    commands = parser.add_subparsers(dest='command', required=True)
    extract = commands.add_parser('extract', help='retained signal results -> kept rows + parity proof')
    extract.add_argument('--output', type=Path, required=True)
    extract.add_argument('results', type=Path, nargs='+')
    prep = commands.add_parser('prepare', help='materialize verified candles, funding and assumptions')
    prep.add_argument('--root', type=Path, required=True)
    prep.add_argument('--dataset-root', type=Path, required=True)
    prep.add_argument('--funding-supplement-root', type=Path, required=True)
    prep.add_argument('--instrument-path', type=Path, required=True)
    prep.add_argument('--cost-path', type=Path, required=True)
    run = commands.add_parser('grid', help='replay hypotheses over a prepared exploration root')
    run.add_argument('--root', type=Path, required=True)
    run.add_argument('--phase', choices=tuple(PHASES), required=True)
    run.add_argument('--policies', default=','.join(POLICIES))
    run.add_argument('--geometries', default='canonical')
    run.add_argument('--profiles', default='baseline,adverse')
    run.add_argument('--output', type=Path, required=True)
    args = parser.parse_args(argv)
    if args.command == 'extract':
        args.output.mkdir(parents=True, exist_ok=True)
        reports = []
        for path in args.results:
            symbol = path.name.split('.')[0]
            with open(path, 'rb') as handle:
                kept, report = extract_rows(handle, symbol)
            (args.output / f'{symbol}.kept.json').write_text(json.dumps(kept))
            reports.append(report)
        (args.output / 'extract-report.json').write_text(json.dumps(reports, indent=1))
        print(json.dumps(reports))
        return 1 if any(r['filter_mismatches'] for r in reports) else 0
    if args.command == 'prepare':
        print(json.dumps(prepare(args.root, args.dataset_root, args.funding_supplement_root,
                                 args.instrument_path, args.cost_path)))
        return 0
    geometries = {'canonical': CANONICAL_GEOMETRIES, 'extended': EXTENDED_GEOMETRIES,
                  'all': tuple(GEOMETRIES)}.get(args.geometries) or tuple(args.geometries.split(','))
    results = list(grid(args.root, args.phase, args.policies.split(','), geometries, args.profiles.split(',')))
    args.output.write_text(json.dumps(results))
    print(json.dumps({'runs': len(results), 'output': str(args.output)}))
    return 0


if __name__ == '__main__':  # pragma: no cover - module CLI
    raise SystemExit(main())
