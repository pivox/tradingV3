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

``canonical_replay`` runs the same signals and plans through the canonical
``PortfolioSimulator``; tests and ``scripts/research_exploration_parity.py``
use it as the parity oracle. Results remain an exploratory screen: no value at
or after 2026-01-01 enters any input or output, no campaign artifact is created
and no execution authority is granted. A promising hypothesis must still be
published as a versioned setup and confirmed by the canonical campaign.

Paths that cannot bind under the frozen research limits are not modelled: the
250 quote position cap keeps ``final_leverage`` at 1 on a 100,000 quote wallet,
so the isolated-liquidation proxy, the exposure and margin checks and the 6 %
equity side of the daily-loss cap stay inactive (the 30 quote cap binds).
"""
from __future__ import annotations

import argparse
import hashlib
import json
import math
import statistics
from array import array
from bisect import bisect_right
from collections import Counter, defaultdict
from collections.abc import Callable, Iterable, Iterator, Mapping, Sequence
from dataclasses import dataclass, field
from datetime import datetime, timezone
from decimal import ROUND_CEILING, ROUND_DOWN, ROUND_FLOOR, Context, Decimal
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
HOLDOUT_START_MS = 1_767_225_600_000  # 2026-01-01T00:00:00Z
PHASES = {'training': (1_672_531_200_000, 1_735_689_600_000),
          'validation': (1_735_689_600_000, HOLDOUT_START_MS)}
PHASE_YEARS = {'training': ('2023', '2024'), 'validation': ('2025',)}

MAKER_FEE = 0.0002
TAKER_FEE = 0.0005
NOTIONAL_CAP = 250.0
EXCHANGE_MIN_NOTIONAL = 5.0
HOLDING_MS = 8 * 3_600_000
ENTRY_TTL_MS = 90_000  # a resting entry expires once a bar boundary passes decision + 90 s
PENDING_RELEASE_MS = (ENTRY_TTL_MS // MINUTE + 1) * MINUTE
MAX_CONCURRENT = 4
DAILY_LOSS_CAP = 30.0
DEADLINE_REASONS = ('holding_deadline', 'midnight')

# experiments.screening_reasons, applied to every phase x cost profile.
PROTOCOL = {'minimum_trades': {'training': 100, 'validation': 50}, 'minimum_pairs': 5,
            'minimum_annual_training_trades': 30,
            'minimum_profit_factor': {'baseline': 1.2, 'adverse': 1.0},
            'maximum_drawdown_quote': 6000.0}


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
    FilterPolicy('R_random_control', (), lambda r: True,
                 'Control on sampled rows: no section or filter (meaningful only on a control root).'),
)}
SETUP_POLICIES = tuple(p for p in POLICIES if p != 'R_random_control')


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


# The canonical ResearchVariant catalogue (plans.VARIANT_DIFFS), then a bounded
# higher-timeframe stop family without geometries equal to a canonical one.
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
            _candidate = Geometry(**_zone_diff, stop_timeframe=_stop_tf, stop_atr_multiplier=_stop_multiple,
                                  target_risk_multiple=_risk_multiple)
            if _candidate not in GEOMETRIES.values():
                GEOMETRIES[f'{_zone}.{_stop}.{_target}'] = _candidate
EXTENDED_GEOMETRIES = tuple(g for g in GEOMETRIES if g not in CANONICAL_GEOMETRIES)


# --------------------------------------------------------------------------- market data
_EXACT = Context(prec=80)  # BigDecimal-like exactness for the plan arithmetic


def _decimal(value: float) -> Decimal:
    """CanonicalOrderPlanDecimal::fromFloat: the shortest round-trip decimal of a double."""
    return Decimal(repr(float(value)))


def _floor_to(value: Decimal, tick: Decimal) -> Decimal:
    return _EXACT.multiply(_EXACT.divide(value, tick).to_integral_value(rounding=ROUND_FLOOR), tick)


def _ceil_to(value: Decimal, tick: Decimal) -> Decimal:
    return _EXACT.multiply(_EXACT.divide(value, tick).to_integral_value(rounding=ROUND_CEILING), tick)


def floor_to(value: float, tick: float) -> float:
    return float(_floor_to(_decimal(value), _decimal(tick)))


def ceil_to(value: float, tick: float) -> float:
    return float(_ceil_to(_decimal(value), _decimal(tick)))


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

    def prior_close(self, boundary_ms: int) -> float:
        """Close known strictly before the bar that ends at ``boundary_ms`` (the canonical mark)."""
        index = self.index(boundary_ms) - 2
        if index < 0:
            raise ExplorationError('no prior close: the canonical prephase mark is outside the series')
        return self.close[index]

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
    """ResearchPlanBuilder arithmetic up to the net-R gate; returns (plan, None) or (None, reason).

    Decimal arithmetic on the shortest decimal of every double, as the PHP value
    classes do. Portfolio admission and the holding-window check follow in
    ``run_hypothesis``, in the builder's order.
    """
    inputs = (row[geometry.anchor_timeframe].get(geometry.anchor_source), row[geometry.atr_timeframe].get('atr'),
              row[geometry.stop_timeframe].get('atr'), row['15m'].get('close'))
    for value in inputs:
        if (not isinstance(value, (int, float)) or isinstance(value, bool)
                or not math.isfinite(value) or value <= 0):
            return None, 'research_signal_price_context_missing'
    x = _EXACT
    anchor, atr, stop_atr, candidate = (_decimal(v) for v in inputs)
    tick, step = _decimal(instrument['tick_size']), _decimal(instrument['quantity_step'])
    half_width = min(max(x.multiply(atr, _decimal(geometry.zone_atr_multiplier)),
                         x.multiply(anchor, _decimal(geometry.minimum_half_width_rate))),
                     x.multiply(anchor, _decimal(geometry.maximum_half_width_rate)))
    lower, upper = _floor_to(x.subtract(anchor, half_width), tick), _ceil_to(x.add(anchor, half_width), tick)
    if lower <= 0 or upper <= lower:
        return None, 'canonical_entry_zone_bounds_invalid'
    entry = _ceil_to(candidate, tick)
    if not lower <= entry <= upper:
        return None, 'canonical_entry_zone_candidate_outside'
    stop = _floor_to(x.subtract(entry, x.multiply(stop_atr, _decimal(geometry.stop_atr_multiplier))), tick)
    if stop <= 0:
        return None, 'canonical_protection_stop_invalid'
    if stop >= entry:
        return None, 'canonical_protection_stop_polarity_invalid'
    risk_distance = x.subtract(entry, stop)
    target = _floor_to(x.add(entry, x.multiply(risk_distance, _decimal(geometry.target_risk_multiple))), tick)
    if target <= entry:
        return None, 'canonical_protection_target_polarity_invalid'
    contract = _decimal(instrument['contract_size'])
    quantity = x.multiply(x.divide(_decimal(NOTIONAL_CAP), x.multiply(x.multiply(entry, contract), step))
                          .to_integral_value(rounding=ROUND_FLOOR), step)
    if quantity <= 0 or quantity < _decimal(instrument['min_quantity']):
        return None, 'canonical_risk_quantity_below_minimum'
    notional = x.multiply(x.multiply(entry, contract), quantity)
    if notional < _decimal(EXCHANGE_MIN_NOTIONAL):
        return None, 'canonical_risk_notional_below_minimum'
    if notional < max(_decimal(EXCHANGE_MIN_NOTIONAL), _decimal(instrument['min_notional'])):
        return None, 'research_instrument_notional_below_minimum'
    rate = {key: _decimal(profile[key]) for key in ('entry_spread_rate', 'stop_spread_rate', 'target_spread_rate',
                                                     'entry_slippage_rate', 'stop_slippage_rate',
                                                     'target_slippage_rate', 'funding_provision_rate')}
    stop_notional = x.multiply(x.multiply(stop, contract), quantity)
    target_notional = x.multiply(x.multiply(target, contract), quantity)
    entry_costs = x.multiply(notional, _decimal(MAKER_FEE) + rate['entry_spread_rate'] + rate['entry_slippage_rate'])
    funding = x.multiply(notional, max(Decimal(0), rate['funding_provision_rate']))
    net_risk = (x.multiply(x.multiply(risk_distance, contract), quantity) + entry_costs + funding
                + x.multiply(stop_notional, _decimal(TAKER_FEE) + rate['stop_spread_rate'] + rate['stop_slippage_rate']))
    net_reward = (x.multiply(x.multiply(target - entry, contract), quantity) - entry_costs - funding
                  - x.multiply(target_notional,
                               _decimal(TAKER_FEE) + rate['target_spread_rate'] + rate['target_slippage_rate']))
    net_r = x.divide(net_reward, net_risk).quantize(Decimal('1e-18'), rounding=ROUND_DOWN)
    if net_r < Decimal('1.3'):
        return None, 'research_minimum_net_r_not_met'
    decision = row['ms']
    deadline = min(decision + HOLDING_MS, (decision // DAY + 1) * DAY)
    return Plan(symbol, decision, float(entry), float(stop), float(target), float(quantity), float(net_risk),
                float(net_r), deadline), None


def holding_window_available(plan: Plan) -> bool:
    return plan.deadline_ms > plan.decision_ms + MINUTE


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
    # Only bars whose boundary is within the entry TTL can fill: with 1m bars, the first one.
    fill_boundary = plan.decision_ms + MINUTE
    if not (fill_boundary <= plan.decision_ms + ENTRY_TTL_MS and candles.low[first] <= plan.entry
            and fill_boundary < plan.deadline_ms):
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
            outcome.ambiguous = outcome.ambiguous or candles.high[index] >= plan.target or new
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
        if timestamp < boundary and (batch == first or uncertain_exit):
            outcome.ambiguous = True  # funding_path: exposure at the instant is uncertain
            if rate <= 0:
                continue  # adverse_possible_credit_certain.v1: an uncertain credit is never booked.
        if mark is None:
            if batch < 1:
                raise ExplorationError('no prior close: the canonical prephase mark is outside the series')
            mark = candles.close[batch - 1]
        amount = -plan.quantity * mark * rate
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
    recent: list[Outcome] = []  # filled outcomes whose exit cashflow may belong to the current UTC day
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
        unrealized = sum(o.plan.quantity * (candles[o.plan.symbol].prior_close(decision + MINUTE) - o.plan.entry)
                         for o in active if o.filled and o.fill_ms <= decision)
        consumed = max(0.0, -realized) + max(0.0, -unrealized) + sum(o.plan.net_risk for o in active)
        if plan.net_risk > DAILY_LOSS_CAP - consumed:
            rejections['research_portfolio_daily_loss_exceeded'] += 1
            continue
        if len(active) >= MAX_CONCURRENT:
            rejections['research_portfolio_concurrency_exceeded'] += 1
            continue
        if not holding_window_available(plan):
            rejections['research_holding_window_unavailable'] += 1
            continue
        active.append(simulate(plan, candles[symbol], funding[symbol] if funding is not None else None, profile))
    closed.extend(o for o in active if o.filled)
    return summarize(len(signals), rejections, closed), closed


def _year(timestamp_ms: int) -> str:
    return str(datetime.fromtimestamp(timestamp_ms / 1000, tz=timezone.utc).year)


def summarize(signals: int, rejections: Mapping[str, int], outcomes: Sequence[Outcome]) -> dict:
    """Statistics with the canonical attribution: trades by exit year, cash by cashflow timestamp."""
    trades = sorted((o for o in outcomes if o.filled), key=lambda o: o.exit_ms)
    count = len(trades)
    gains = sum(o.net for o in trades if o.net > 0)
    losses = -sum(o.net for o in trades if o.net <= 0)
    equity = peak = drawdown = 0.0
    for outcome in trades:
        equity += outcome.net
        peak = max(peak, equity)
        drawdown = max(drawdown, peak - equity)
    by_year: dict[str, dict[str, float]] = defaultdict(lambda: {'trades': 0, 'net_r': 0.0, 'cashflow_net': 0.0})
    by_pair: dict[str, dict[str, float]] = defaultdict(lambda: {'trades': 0, 'net': 0.0, 'net_r': 0.0})
    for outcome in trades:
        year = by_year[_year(outcome.exit_ms)]
        year['trades'] += 1
        year['net_r'] += outcome.net_r
        by_year[_year(outcome.fill_ms)]['cashflow_net'] -= outcome.entry_cost
        by_year[_year(outcome.exit_ms)]['cashflow_net'] += outcome.exit_cash
        for at, amount in outcome.funding_cash:
            by_year[_year(at)]['cashflow_net'] += amount
        pair = by_pair[outcome.plan.symbol]
        pair['trades'] += 1
        pair['net'] += outcome.net
        pair['net_r'] += outcome.net_r
    net_rs = [o.net_r for o in trades]
    return {
        'execution_authority': 'none',
        'signals': signals, 'rejections': dict(sorted(rejections.items())), 'trades': count,
        'wins': sum(1 for o in trades if o.net > 0),
        'win_rate': sum(1 for o in trades if o.net > 0) / count if count else None,
        'net_pnl': sum(o.net for o in trades), 'funding_quote': sum(o.funding for o in trades),
        'total_net_r': sum(net_rs),
        'mean_net_r': sum(net_rs) / count if count else None,
        'net_r_stdev': statistics.stdev(net_rs) if count > 1 else None,
        'profit_factor': gains / losses if losses > 0 else None,
        'realized_drawdown_quote': drawdown,
        'exit_reasons': dict(sorted(Counter(o.reason for o in trades).items())),
        'ambiguous': sum(1 for o in trades if o.ambiguous),
        'by_year': {k: dict(v) for k, v in sorted(by_year.items())},
        'by_pair': {k: dict(v) for k, v in sorted(by_pair.items())},
        'pairs_with_trades': len(by_pair),
    }


# ----------------------------------------------------------------------------- protocol
def screening_reasons(summary: Mapping[str, Any], phase: str, profile: str) -> list[str]:
    """``experiments.screening_reasons`` for an exploratory summary (realized drawdown as proxy)."""
    if phase not in PHASES or profile not in PROTOCOL['minimum_profit_factor']:
        raise ExplorationError('screening phase or profile invalid')
    reasons = []
    if summary['trades'] < PROTOCOL['minimum_trades'][phase]:
        reasons.append('insufficient_trades')
    if summary['pairs_with_trades'] < PROTOCOL['minimum_pairs']:
        reasons.append('insufficient_pairs')
    if summary['mean_net_r'] is None or summary['mean_net_r'] <= 0:
        reasons.append('nonpositive_mean_net_r')
    if summary['profit_factor'] is None:
        reasons.append('undefined_net_profit_factor')
    elif summary['profit_factor'] < PROTOCOL['minimum_profit_factor'][profile]:
        reasons.append('net_profit_factor_below_threshold')
    if summary['realized_drawdown_quote'] > PROTOCOL['maximum_drawdown_quote']:
        reasons.append('marked_drawdown_exceeded')
    for year in PHASE_YEARS[phase]:
        annual = summary['by_year'].get(year, {})
        if phase == 'training' and annual.get('trades', 0) < PROTOCOL['minimum_annual_training_trades']:
            reasons.append('insufficient_annual_training_trades:' + year)
        if annual.get('cashflow_net', 0.0) <= 0:
            reasons.append('nonpositive_annual_net_pnl:' + year)
    return reasons


def screen_report(results: Iterable[Mapping[str, Any]], minimum_trades: int = 100, top: int = 10) -> dict:
    """Aggregate grid replays: distribution of baseline training outcomes and protocol screening."""
    by_key: dict[tuple, dict] = {}
    for row in results:
        by_key[(row['policy'], row['geometry_id'], row['phase'], row['profile'])] = dict(row)
    configs = sorted({(p, g) for p, g, _, _ in by_key})
    sampled = [by_key[(p, g, 'training', 'baseline')] for p, g in configs if (p, g, 'training', 'baseline') in by_key
               and by_key[(p, g, 'training', 'baseline')]['trades'] >= minimum_trades]
    means = [row['mean_net_r'] for row in sampled]

    def passes(policy: str, geometry: str, phases: Sequence[str], profiles: Sequence[str]) -> bool:
        keys = [(policy, geometry, phase, profile) for phase in phases for profile in profiles]
        return all(key in by_key for key in keys) and not any(
            screening_reasons(by_key[key], key[2], key[3]) for key in keys)

    ranked = sorted(sampled, key=lambda row: -row['mean_net_r'])[:top]
    return {
        'execution_authority': 'none',
        'configurations': len(configs),
        'minimum_trades': minimum_trades,
        'configurations_with_minimum_trades': len(sampled),
        'median_mean_net_r': statistics.median(means) if means else None,
        'share_positive_mean_net_r': sum(1 for m in means if m > 0) / len(means) if means else None,
        'training_baseline_passes': [f'{p}|{g}' for p, g in configs if passes(p, g, ('training',), ('baseline',))],
        'training_protocol_passes': [f'{p}|{g}' for p, g in configs
                                     if passes(p, g, ('training',), ('baseline', 'adverse'))],
        'protocol_eligible': [f'{p}|{g}' for p, g in configs
                              if passes(p, g, ('training', 'validation'), ('baseline', 'adverse'))],
        'top_baseline_training': [{'policy': row['policy'], 'geometry_id': row['geometry_id'], 'trades': row['trades'],
                                   'mean_net_r': row['mean_net_r'], 'net_r_stdev': row.get('net_r_stdev'),
                                   'profit_factor': row['profit_factor']} for row in ranked],
    }


# -------------------------------------------------------------------------- extraction
def extract_rows(lines: Iterable[bytes | str], symbol: str, sample_modulus: int | None = None) -> tuple[list[dict], dict]:
    """Prove filter parity on every row; keep section-ready rows, or a deterministic random-timing sample.

    With ``sample_modulus`` the kept rows are those with sha256("SYMBOL:ms") % modulus == 0,
    whatever their verdicts: a control for the ``R_random_control`` policy.
    """
    if sample_modulus is not None and sample_modulus < 2:
        raise ExplorationError('sample modulus must be at least 2')
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
        if sample_modulus is not None:
            keep = int(hashlib.sha256(f'{symbol}:{decision}'.encode()).hexdigest(), 16) % sample_modulus == 0
        else:
            keep = sections['regime'] and sections['context'] and sections['trigger']
        if keep:
            kept.append({'ms': decision, 'sec': sections, 'bf': list(stored), **compact})
    return kept, {'execution_authority': 'none', 'symbol': symbol, 'rows': rows, 'kept': len(kept),
                  'sample_modulus': sample_modulus, 'filter_mismatches': dict(parity)}


def _compact(context: Mapping[str, Any]) -> dict:
    ema, previous, adx = context.get('ema') or {}, context.get('ema_prev') or {}, context.get('adx')
    return {**{k: context.get(k) for k in CONTEXT_FIELDS},
            'e9': ema.get('9'), 'e21': ema.get('21'), 'e9p': previous.get('9'), 'e21p': previous.get('21'),
            'adx14': adx.get('14') if isinstance(adx, dict) else None}


# ----------------------------------------------------------------------- preparation
def prepare(root: Path, dataset_root: Path, supplement_root: Path, instrument_path: Path, cost_path: Path) -> dict:
    """Materialize verified pre-holdout candles/funding and the frozen assumptions into ``root``.

    The funding inventory is the canonical one: like the campaign, it opens the next
    archive only to attest continuity at the window end; no 2026 value is kept.
    """
    from .funding import REST_HYPOTHESIS, FundingReader
    from .portfolio_simulator import canonical_hash
    from .signal_sources import iter_verified_candles, select_sources

    start, end = '2023-01-01T00:00:00Z', '2026-01-01T00:00:00Z'
    (root / 'candles').mkdir(parents=True, exist_ok=True)
    report: dict[str, Any] = {'execution_authority': 'none', 'window': [start, end], 'candles': {}, 'funding': {}}
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
    inventory = reader.inventory
    if not inventory.evidence_complete:
        raise ExplorationError('funding evidence incomplete for the exploration window')
    events: dict[str, list] = {symbol: [] for symbol in SYMBOLS}
    for event in reader.iter_events():
        if not reader.start_ms <= event.timestamp_ms < reader.end_ms:
            raise ExplorationError('funding event outside the exploration window')
        events[event.symbol].append([event.timestamp_ms, float(event.rate),
                                     None if event.observed_mark is None else float(event.observed_mark)])
    (root / 'funding.json').write_text(json.dumps(events))
    report['funding'] = {'inventory_hash': inventory.inventory_hash, 'coverage': inventory.coverage,
                         'events': {symbol: len(rows) for symbol, rows in events.items()}}
    for source, name, hash_field in ((instrument_path, 'instrument-assumptions.json', 'manifest_hash'),
                                     (cost_path, 'cost-assumptions.json', 'assumption_hash')):
        document = json.loads(source.read_text())
        if canonical_hash({k: v for k, v in document.items() if k != hash_field}) != document.get(hash_field):
            raise ExplorationError(f'{name} hash conflict')
        (root / name).write_text(json.dumps(document))
    (root / 'prepare-report.json').write_text(json.dumps(report, indent=1))
    return report


# ---------------------------------------------------------------- canonical oracle
def canonical_replay(rows: Mapping[str, Sequence[Mapping[str, Any]]], candles: Mapping[str, CandleSeries],
                     funding_events: Mapping[str, Sequence[Sequence[Any]]], policy: FilterPolicy, geometry: Geometry,
                     instruments: Mapping[str, Mapping[str, Any]], profile: Mapping[str, float],
                     window: tuple[int, int]) -> tuple[dict, Counter]:
    """Replay the same signals and plans through the canonical PortfolioSimulator (parity oracle).

    Identities are placeholders: the kernel's own fill, exit, cost, funding and admission
    rules are what is compared. Candles must include the prephase minute before the window.
    """
    from .portfolio_simulator import (IDENTITY_FIELDS, Candle, CostAssumptions, FundingCoverage, FundingEvent,
                                      InstrumentAssumptions, PortfolioSimulator, RunAssumptions, Signal,
                                      canonical_hash, number, wire_number)

    start, end = window
    symbols = tuple(s for s in SYMBOLS if s in candles)
    phase = 'training' if end <= PHASES['training'][1] else 'validation'
    d = lambda value: Decimal(repr(float(value)))  # noqa: E731 - exact decimal of the shared double
    identity = {k: ('parity' if k == 'variant_id' else 'sha256:' + format(i + 1, 'x') * 64)
                for i, k in enumerate(IDENTITY_FIELDS)}
    sources = {s: {'dataset_id': f'parity-{s}', 'dataset_sha256': '7' * 64, 'signal_run_id': f'parity-run-{s}',
                   'signal_output_sha256': '9' * 64} for s in symbols}
    kernel_instruments = {s: InstrumentAssumptions(
        d(i['tick_size']), d(i['quantity_step']), d(i['min_quantity']), d(i['max_quantity']),
        d(max(EXCHANGE_MIN_NOTIONAL, i['min_notional'])), d(i['contract_size']), d(i['leverage_cap']),
        d(i['mmr_proxy_rate']), d(i['liquidation_fee_rate']), '2026-10-09T07:12:34Z', 'c' * 64)
        for s, i in ((s, instruments[s]) for s in symbols)}
    costs = CostAssumptions(Decimal('.0002'), Decimal('.0005'), Decimal('.0005'), d(profile['entry_spread_rate']),
                            d(profile['stop_spread_rate']), d(profile['target_spread_rate']),
                            d(profile['entry_slippage_rate']), d(profile['stop_slippage_rate']),
                            d(profile['target_slippage_rate']), d(profile['funding_provision_rate']), 28800, 1)
    events = {s: [e for e in funding_events.get(s, ()) if start <= e[0] < end] for s in symbols}
    assumptions = RunAssumptions(
        start, end, phase, symbols, tuple(kernel_instruments.items()), costs, tuple(identity.items()),
        tuple((s, tuple(sources[s].items())) for s in symbols),
        FundingCoverage('observed_only', 'd' * 64, tuple((s, len(events[s])) for s in symbols)),
        'adverse_possible_credit_certain.v1', 'last_known_close.v1')
    selected = {(s, row['ms']): row for s in symbols for row in rows.get(s, ())
                if start <= row['ms'] < end and all(row['sec'][k] for k in policy.sections) and policy.predicate(row)}
    trades: list[Mapping[str, Any]] = []
    rejections: Counter[str] = Counter()

    def builder(signal: Any, view: Mapping[str, Any]) -> dict:
        payload = signal.payload
        symbol = payload['symbol']
        plan, reason = build_plan(selected[(symbol, payload['evaluated_ms'])], symbol, geometry,
                                  instruments[symbol], profile)
        if plan is None:
            return {'schema_version': 'research-plan-rejection.v1', 'reason_code': reason}
        if not holding_window_available(plan):  # never binds on 15m decisions; the builder checks it last
            return {'schema_version': 'research-plan-rejection.v1', 'reason_code': 'research_holding_window_unavailable'}
        instrument = kernel_instruments[symbol]
        entry, stop, target, quantity = (d(v) for v in (plan.entry, plan.stop, plan.target, plan.quantity))
        units = quantity * instrument.contract_size
        parts = {'gross_stop_loss': (entry - stop) * units, 'entry_fee': entry * units * costs.entry_fee_rate,
                 'stop_exit_fee': stop * units * costs.stop_fee_rate,
                 'entry_spread': entry * units * costs.entry_spread_rate,
                 'stop_spread': stop * units * costs.stop_spread_rate,
                 'entry_slippage': entry * units * costs.entry_slippage_rate,
                 'stop_slippage': stop * units * costs.stop_slippage_rate,
                 'funding_provision': entry * units * costs.funding_provision_rate * costs.funding_intervals_provisioned}
        risk = sum(parts.values(), Decimal(0))
        parts['total_stop_loss'] = risk
        reward = ((target - entry) * units - parts['entry_fee'] - parts['entry_spread'] - parts['entry_slippage']
                  - parts['funding_provision']
                  - target * units * (costs.target_fee_rate + costs.target_spread_rate + costs.target_slippage_rate))
        document = {
            'schema_version': 'research-plan.v1', 'research_only': True, 'execution_authority': 'none',
            'symbol': symbol, 'signal_result_hash': payload['result_hash'], 'signal_index': signal.index,
            'evaluated_ms': payload['evaluated_ms'], 'portfolio_hash': view['portfolio_hash'], **identity,
            **sources[symbol], 'source_venue': 'binance_usdm', 'source_network': 'mainnet', 'market_type': 'perpetual',
            'instrument_math': instrument.wire(), 'cost_model': costs.wire(), 'entry_price': float(entry),
            'stop_price': float(stop), 'quantity': float(quantity), 'final_leverage': 1,
            'effective_leverage_cap': float(instrument.leverage_cap_assumed),
            'position_notional_quote': float(entry * quantity * instrument.contract_size),
            'targets': [{'price': float(target),
                         'net_r': wire_number((reward / risk).quantize(Decimal('1e-18'), rounding=ROUND_DOWN)),
                         'net_reward_quote': wire_number(reward), 'net_risk_quote': wire_number(risk)}],
            'entry_ttl_seconds': ENTRY_TTL_MS // 1000, 'cancel_after_seconds': PENDING_RELEASE_MS // 1000,
            'holding_deadline_exclusive': True, 'holding_deadline_ms': plan.deadline_ms,
            'risk_components_quote': {k: wire_number(v) for k, v in parts.items()},
            'risk_budget_quote': wire_number(number(view['equity_quote']) * Decimal('.05'))}
        document['plan_hash'] = canonical_hash(document)
        return document

    simulator = PortfolioSimulator(assumptions, builder, event_sink=lambda event: None, trade_sink=trades.append,
                                   cashflow_sink=lambda cashflow: None,
                                   rejection_sink=lambda row: rejections.update([row['reason_code']]))
    indices = iter(range(10 ** 12))
    by_time: dict[int, list[str]] = defaultdict(list)
    for symbol, decision in sorted(selected, key=lambda key: (key[1], SYMBOLS.index(key[0]))):
        by_time[decision].append(symbol)

    def signals(at: int) -> list:
        out = []
        for symbol in by_time.get(at, ()):
            payload = {'schema_version': 'research-signal-result.v1', 'passed': True, 'evaluated_ms': at,
                       'symbol': symbol, 'source': {'dataset_id': sources[symbol]['dataset_id'],
                       'dataset_sha256': sources[symbol]['dataset_sha256'], 'source_venue': 'binance_usdm',
                       'source_network': 'mainnet', 'market_type': 'perpetual'},
                       'baseline': {'setup_hash': identity['base_setup_hash'], 'config_hash': identity['base_config_hash'],
                                    'condition_catalog_hash': identity['base_catalog_hash'],
                                    'snapshot_hash': identity['base_snapshot_hash']}}
            payload['result_hash'] = canonical_hash(payload)
            out.append(Signal(next(indices), payload))
        return out

    def batch(open_ms: int) -> list:
        out = []
        for symbol in symbols:
            series, index = candles[symbol], candles[symbol].index(open_ms)
            if not 0 <= index < series.count:
                raise ExplorationError('canonical replay needs candles from the prephase minute')
            out.append(Candle(symbol, open_ms, *(d(values[index]) for values in
                                                 (series.open, series.high, series.low, series.close))))
        return out

    startup, by_batch = [], defaultdict(list)
    for symbol in symbols:
        for timestamp, rate, *rest in events[symbol]:
            mark = rest[0] if rest else None
            event = FundingEvent(symbol, timestamp, d(rate), None if mark is None else d(mark),
                                 None if mark is None else timestamp)
            if timestamp == start:
                startup.append(event)
            else:
                by_batch[start + (-((start - timestamp) // MINUTE) - 1) * MINUTE].append(event)
    simulator.prime(batch(start - MINUTE), funding=startup, signals=signals(start))
    for opening in range(start, end, MINUTE):
        simulator.advance(batch(opening), funding=by_batch.get(opening, ()),
                          signals=signals(opening + MINUTE) if opening + MINUTE < end else ())
    simulator.finish()
    return {(trade['symbol'], trade['decision_ms']): trade for trade in trades}, rejections


def parity_differences(outcomes: Sequence[Outcome], rejections: Mapping[str, int], canonical: Mapping[tuple, Any],
                       canonical_rejections: Mapping[str, int], end: int) -> list[str]:
    """Every observable difference between this replay and the canonical oracle."""
    ours = {(o.plan.symbol, o.plan.decision_ms): o for o in outcomes if o.filled and o.exit_ms <= end}
    differences = [f'only_canonical:{key}' for key in sorted(set(canonical) - set(ours))]
    differences += [f'only_exploration:{key}' for key in sorted(set(ours) - set(canonical))]
    for key in sorted(set(canonical) & set(ours)):
        trade, outcome = canonical[key], ours[key]
        expected = {'exit_reason': outcome.reason, 'fill_boundary_ms': outcome.fill_ms,
                    'exit_boundary_ms': outcome.exit_ms, 'ambiguous': outcome.ambiguous}
        for name, value in expected.items():
            if trade[name] != value:
                differences.append(f'{name}:{key}:{trade[name]}!={value}')
        if float(trade['exit_price']) != outcome.exit_price:
            differences.append(f'exit_price:{key}')
        for name, value in (('net_pnl_quote', outcome.net), ('funding_quote', outcome.funding)):
            if abs(float(trade[name]) - value) > 1e-9:
                differences.append(f'{name}:{key}:{trade[name]}!={value}')
    if dict(canonical_rejections) != dict(rejections):
        differences.append(f'rejections:{dict(canonical_rejections)}!={dict(rejections)}')
    return differences


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
    extract.add_argument('--control-modulus', type=int, help='keep a deterministic 1/N random-timing sample')
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
    run.add_argument('--policies', default=','.join(SETUP_POLICIES))
    run.add_argument('--geometries', default='canonical')
    run.add_argument('--profiles', default='baseline,adverse')
    run.add_argument('--output', type=Path, required=True)
    screen = commands.add_parser('screen', help='aggregate grid outputs with the protocol screening')
    screen.add_argument('grids', type=Path, nargs='+')
    screen.add_argument('--minimum-trades', type=int, default=100)
    args = parser.parse_args(argv)
    if args.command == 'extract':
        args.output.mkdir(parents=True, exist_ok=True)
        reports = []
        for path in args.results:
            symbol = path.name.split('.')[0]
            with open(path, 'rb') as handle:
                kept, report = extract_rows(handle, symbol, args.control_modulus)
            (args.output / f'{symbol}.kept.json').write_text(json.dumps(kept))
            reports.append(report)
        (args.output / 'extract-report.json').write_text(json.dumps(reports, indent=1))
        print(json.dumps(reports))
        return 1 if any(r['filter_mismatches'] for r in reports) else 0
    if args.command == 'prepare':
        print(json.dumps(prepare(args.root, args.dataset_root, args.funding_supplement_root,
                                 args.instrument_path, args.cost_path)))
        return 0
    if args.command == 'screen':
        results = [row for path in args.grids for row in json.loads(path.read_text())]
        print(json.dumps(screen_report(results, args.minimum_trades), indent=1))
        return 0
    geometries = {'canonical': CANONICAL_GEOMETRIES, 'extended': EXTENDED_GEOMETRIES,
                  'all': tuple(GEOMETRIES)}.get(args.geometries) or tuple(args.geometries.split(','))
    results = list(grid(args.root, args.phase, args.policies.split(','), geometries, args.profiles.split(',')))
    args.output.write_text(json.dumps(results))
    print(json.dumps({'runs': len(results), 'output': str(args.output)}))
    return 0


if __name__ == '__main__':  # pragma: no cover - module CLI
    raise SystemExit(main())
