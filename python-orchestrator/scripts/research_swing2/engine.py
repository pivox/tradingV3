"""Swing v2 engine: 4h breakouts ranked by strength, limit or stop-limit entries, fixed-R or
Chandelier exits, a four-position portfolio sized at 1 % risk, and its matched random control.

The 4h frames (bars, PHP-identical indicators, prior 30/60/120-bar extremes) and the 1m candle
series come from ``app.backtesting.research.swing``; the fill and exit conventions are the swing
screen's (a resting order fills on the first 1m bar trading through it; on the fill bar a stop
exits and a target is ignored; a bar reaching both bounds exits at the stop, at the worse of the
stop and the bar's open; the holding deadline exits at the prior close; funding is booked by
``exploration.settle``). What is new:

* **Signals.** At each 4h close with a complete 250-bar window: long when the close is above the
  EMA200 and above the prior ``lookback``-bar high; short when below the EMA200 and below the prior
  low. Strength = (close − prior high) / ATR14 for a long, (prior low − close) / ATR14 for a short;
  a configuration keeps the signals whose strength is at least its threshold (strictly positive by
  construction).
* **Ranking.** Signals of the same 4h close are admitted by decreasing strength (ties by symbol);
  the control ranks its decisions by a seeded random number instead.
* **Entries.** ``L``: a limit at the close (rounded toward the market) resting 60 minutes, maker.
  ``C``: a stop-limit triggered at the decision bar's high (low for a short), limit = trigger ±
  0.25 ATR, resting one 4h bar; a 1m bar trading through the trigger fills at the worse of the
  trigger and its open when that is within the limit, otherwise the order rests at the limit;
  taker fee plus the profile's stop-leg spread and slippage. No market fallback.
* **Exits.** ``T3``: stop ``stop_atr`` × ATR from the fill, target 3 R. ``CH``: the same initial
  stop, then at every 4h close after the fill the stop moves to (highest high since the fill −
  ``stop_atr`` × the current ATR14), never backwards (lowest low + … for a short); an exit at a
  stop that moved is a ``trailing`` exit. Both end at the holding deadline (7 or 14 days, or the
  phase end) at the prior close (``time``).
* **Portfolio.** At most four positions (pending entries included), one per symbol whatever its
  side; quantity = 1 % of the reference equity divided by the planned loss per unit (price risk,
  entry and stop-exit costs, funding provision for every 8 h of the horizon), capped so that one
  position's notional is at most a quarter of the equity (four positions = leverage 1). The
  equity is fixed (no compounding). One quantity per trade, sized with the baseline profile; each
  cost profile settles it and measures its R against its own planned loss.

Paths depend only on (symbol, decision bar, geometry, phase end), never on the portfolio, so they
are memoised and shared by a configuration and its controls.
"""

from __future__ import annotations

import bisect
import hashlib
import math
import random
from collections import Counter
from collections.abc import Mapping, Sequence
from dataclasses import dataclass, field

from app.backtesting.research import exploration as ex
from app.backtesting.research import swing as sw

MINUTE = 60_000
HOUR = 3_600_000
DAY = 86_400_000
FOUR_HOURS = 14_400_000
BAR_MINUTES = FOUR_HOURS // MINUTE
FUNDING_INTERVAL_MS = 8 * HOUR

REFERENCE_EQUITY = 10_000.0
RISK_FRACTION = 0.01
MAX_ACTIVE = 4
LEVERAGE = 1.0
LIMIT_TTL_MS = 60 * MINUTE
STOP_LIMIT_TTL_MS = FOUR_HOURS
STOP_LIMIT_OFFSET_ATR = 0.25
TARGET_R = 3.0
EXCHANGE_MIN_NOTIONAL = 5.0
GAP_WARMUP_MS = 42 * 86_400_000  # the 250-bar 4h warm-up

LOOKBACKS = (30, 60, 120)
STRENGTHS = (0.0, 0.25, 0.5)
ENTRIES = ("L", "C")
STOPS = (2.0, 3.0)
EXITS = ("T3", "CH")
HOLDS = (7, 14)
SIDES = ("long", "short")
EXIT_CLASSES = ("stop", "target", "time", "trailing", "delisting", "censored")


class EngineError(ValueError):
    pass


@dataclass(frozen=True)
class Geometry:
    side: str
    entry: str
    stop_atr: float
    exit: str
    hold_days: int
    target_r: float = TARGET_R

    def __post_init__(self) -> None:
        if (self.side not in SIDES or self.entry not in ENTRIES or self.exit not in (*EXITS, "T5")
                or self.stop_atr <= 0 or not 1 <= self.hold_days <= 60):
            raise EngineError(f"geometry invalid: {self}")

    @property
    def id(self) -> str:
        return f"{self.side}|{self.entry}|S{self.stop_atr:g}|{self.exit}|H{self.hold_days}d"

    @property
    def horizon_ms(self) -> int:
        return self.hold_days * DAY

    @property
    def ttl_ms(self) -> int:
        return LIMIT_TTL_MS if self.entry == "L" else STOP_LIMIT_TTL_MS

    @property
    def funding_intervals(self) -> int:
        return (self.horizon_ms // 1000 - 1) // (FUNDING_INTERVAL_MS // 1000) + 1

    @property
    def fixed_target(self) -> float | None:
        return {"T3": 3.0, "T5": 5.0}.get(self.exit)


@dataclass(frozen=True)
class Config:
    lookback: int
    strength: float
    geometry: Geometry

    @property
    def side(self) -> str:
        return self.geometry.side

    @property
    def id(self) -> str:
        g = self.geometry
        return f"B{self.lookback}-Q{self.strength:g}|{g.side}|{g.entry}|S{g.stop_atr:g}|{g.exit}|H{g.hold_days}d"

    @classmethod
    def parse(cls, text: str) -> "Config":
        head, side, entry, stop, exit_, hold = text.split("|")
        lookback, strength = head.split("-Q")
        return cls(int(lookback[1:]), float(strength), Geometry(side, entry, float(stop[1:]), exit_, int(hold[1:-1])))


def grid() -> list[Config]:
    """The pre-registered grid: 3 lookbacks × 3 strengths × 2 entries × 2 stops × 2 exits × 2 holds per side."""
    return [Config(n, q, Geometry(side, entry, stop, exit_, hold))
            for side in SIDES for n in LOOKBACKS for q in STRENGTHS for entry in ENTRIES
            for stop in STOPS for exit_ in EXITS for hold in HOLDS]


# ------------------------------------------------------------------------------ market
@dataclass
class Market:
    """Everything one universe needs: frames, 1m candles, their extremes index, funding, instruments."""
    frames: Mapping[str, sw.Frame]
    candles: Mapping[str, ex.CandleSeries]
    blocks: Mapping[str, sw.Extremes]
    funding: Mapping[str, ex.FundingSeries]
    instruments: Mapping[str, Mapping]
    paths: dict = field(default_factory=dict)  # memo of simulated paths
    eligible: Mapping[str, frozenset] | None = None  # symbol -> months "YYYY-MM" open to NEW entries (None: always)
    cadence_aware: bool = False  # OKX: funding provision at the cadence known at entry, missing settlements imputed
    gaps: Mapping[str, Sequence[tuple[int, int]]] | None = None  # symbol -> [first missing minute, next real minute)

    def open_to_entries(self, symbol: str, decision_ms: int) -> bool:
        """Universe membership gates only new entries (an open position runs to its exit regardless); no entry
        when a data gap lies within the warm-up (the 42 days before the decision)."""
        if self.eligible is not None and month_of_ms(decision_ms) not in self.eligible.get(symbol, frozenset()):
            return False
        for start, end in (self.gaps or {}).get(symbol, ()):
            if start <= decision_ms and end > decision_ms - GAP_WARMUP_MS:
                return False
        return True

    def gap_containing(self, symbol: str, at_ms: int) -> tuple[int, int] | None:
        for start, end in (self.gaps or {}).get(symbol, ()):
            if start <= at_ms < end:
                return start, end
        return None

    def overlaps_gap(self, symbol: str, begin_ms: int, end_ms: int) -> bool:
        return any(start < end_ms and end > begin_ms for start, end in (self.gaps or {}).get(symbol, ()))

    def cadence_hours(self, symbol: str, at_ms: int) -> int:
        """Funding settlement cadence known at ``at_ms``: the last interval between two recorded settlements before
        it, rounded to 1, 2, 4 or 8 h (8 h when fewer than two settlements are known)."""
        series = self.funding.get(symbol)
        if series is None:
            return 8
        index = bisect.bisect_left(series.timestamps, at_ms)
        if index < 2:
            return 8
        hours = (series.timestamps[index - 1] - series.timestamps[index - 2]) / 3_600_000
        return min((1, 2, 4, 8), key=lambda h: abs(h - hours))

    @property
    def symbols(self) -> tuple[str, ...]:
        return tuple(sorted(self.frames))


def month_of_ms(ms: int) -> str:
    from datetime import datetime, timezone

    return datetime.fromtimestamp(ms / 1000, tz=timezone.utc).strftime("%Y-%m")


def _regime(frame: sw.Frame, index: int, side: str) -> bool:
    close, ema = frame["close"][index], frame["ema200"][index]
    atr = frame["atr"][index]
    if close is None or ema is None or atr is None or not atr > 0:
        return False
    return close > ema if side == "long" else close < ema


def strength_at(frame: sw.Frame, index: int, side: str, lookback: int) -> float | None:
    """Breakout strength in ATR at bar ``index`` (None outside the regime or without a breakout)."""
    if not _regime(frame, index, side):
        return None
    close, atr = frame["close"][index], frame["atr"][index]
    if side == "long":
        level = frame[f"dh{lookback}"][index]
        distance = None if level is None else close - level
    else:
        level = frame[f"dl{lookback}"][index]
        distance = None if level is None else level - close
    if distance is None or not distance > 0:
        return None
    return distance / atr


# A signal: (decision_ms, rank key, symbol, frame index, side, strength)
Signal = tuple[int, float, str, int, str, float]


def signals(market: Market, config: Config, window: tuple[int, int]) -> list[Signal]:
    rows = []
    for symbol in market.symbols:
        frame = market.frames[symbol]
        for index in frame.decisions(*window):
            if not market.open_to_entries(symbol, frame.close_ms(index)):
                continue
            value = strength_at(frame, index, config.side, config.lookback)
            if value is not None and value >= config.strength:
                rows.append((frame.close_ms(index), -value, symbol, index, config.side, value))
    return sorted(rows)


def regime_bars(market: Market, side: str, window: tuple[int, int]) -> dict[str, list[int]]:
    return {symbol: [i for i in market.frames[symbol].decisions(*window) if _regime(market.frames[symbol], i, side)
                     and market.open_to_entries(symbol, market.frames[symbol].close_ms(i))]
            for symbol in market.symbols}


def _seed(*parts: object) -> int:
    return int(hashlib.sha256("|".join(str(p) for p in parts).encode()).hexdigest()[:16], 16)


def control_signals(market: Market, config: Config, window: tuple[int, int], seed: int,
                    regime: Mapping[str, Sequence[int]] | None = None,
                    counts: Mapping[str, int] | None = None) -> list[Signal]:
    """Random timing matched to the regime and to the configuration's signal count per pair.

    For each pair, as many decisions as the configuration has signals there, drawn without
    replacement among the pair's bars of the same regime (close on the same side of the EMA200,
    ATR valid) in the window, ranked by a seeded random number. Same geometry, same portfolio.
    """
    if regime is None:
        regime = regime_bars(market, config.side, window)
    if counts is None:
        counts = Counter(row[2] for row in signals(market, config, window))
    rows = []
    for symbol in market.symbols:
        n = counts.get(symbol, 0)
        pool = regime.get(symbol, [])
        if not n or not pool:
            continue
        rng = random.Random(_seed(config.id, seed, symbol))
        frame = market.frames[symbol]
        for index in rng.sample(pool, min(n, len(pool))):
            rows.append((frame.close_ms(index), rng.random(), symbol, index, config.side, 0.0))  # no strength: random rank
    return sorted(rows)


# --------------------------------------------------------------------------- paths
@dataclass(frozen=True)
class Path:
    filled: bool
    release_ms: int
    entry: float = float("nan")
    initial_stop: float = float("nan")
    target: float = float("nan")
    fill_index: int = -1
    fill_ms: int = 0
    exit_index: int = -1
    exit_ms: int = 0
    exit_price: float = float("nan")
    reason: str = "entry_expired"
    delisting: bool = False  # the deadline is the end of the instrument's data before the holding limit or the phase end
    event: str | None = None  # "delisting", "interruption" (an interruption inside the position) or "censored"
    ambiguous: bool = False
    atr: float = float("nan")
    deadline_ms: int = 0
    reference_entry: float = float("nan")


def _round_entry(price: float, tick: float, side: str) -> float:
    return ex.ceil_to(price, tick) if side == "long" else ex.floor_to(price, tick)


def _stop_from(entry: float, atr: float, multiple: float, tick: float, side: str) -> float:
    """``ProtectionPriceMath``: the ATR distance in exact decimals, the stop rounded away from the entry."""
    x, d = ex._EXACT, ex._decimal
    distance = x.multiply(d(atr), d(multiple))
    if side == "long":
        return float(ex._floor_to(x.subtract(d(entry), distance), d(tick)))
    return float(ex._ceil_to(x.add(d(entry), distance), d(tick)))


def _target_from(entry: float, stop: float, multiple: float, tick: float, side: str) -> float:
    x, d = ex._EXACT, ex._decimal
    reward = x.multiply(x.abs(x.subtract(d(entry), d(stop))), d(multiple))
    if side == "long":
        return float(ex._floor_to(x.add(d(entry), reward), d(tick)))
    return float(ex._ceil_to(x.subtract(d(entry), reward), d(tick)))


def reference_entry(frame: sw.Frame, index: int, geometry: Geometry, tick: float) -> float:
    """The entry price known at the decision: the limit (L) or the trigger (C), used for sizing checks."""
    if geometry.entry == "L":
        return _round_entry(frame["close"][index], tick, geometry.side)
    level = frame["high"][index] if geometry.side == "long" else frame["low"][index]
    return _round_entry(level, tick, geometry.side)


def simulate_path(market: Market, symbol: str, index: int, geometry: Geometry, phase_end: int) -> Path:
    key = (symbol, index, geometry, phase_end)
    cached = market.paths.get(key)
    if cached is None:
        cached = market.paths[key] = _with_event(market, symbol, _simulate(market, symbol, index, geometry, phase_end))
    return cached


SETTLEMENT_MINUTES = 60


def settlement_proxy(candles: ex.CandleSeries) -> float:
    """Delisting settlement proxy: the mean of the last hour of closes (OKX settles a delisted swap on the average
    index price of its last hour; the index itself is not archived)."""
    count = min(SETTLEMENT_MINUTES, candles.count)
    return float(sum(candles.close[candles.count - count:]) / count)


def _with_event(market: Market, symbol: str, path: "Path") -> "Path":
    if not path.filled:
        return path
    import dataclasses

    if path.reason == "delisting":
        return dataclasses.replace(path, exit_price=settlement_proxy(market.candles[symbol]), event="delisting")
    if path.reason == "censored":
        return dataclasses.replace(path, event="censored")
    if market.overlaps_gap(symbol, path.fill_ms, path.exit_ms):
        return dataclasses.replace(path, event="interruption")
    return path


def _simulate(market: Market, symbol: str, index: int, geometry: Geometry, phase_end: int) -> Path:
    frame, candles, blocks = market.frames[symbol], market.candles[symbol], market.blocks[symbol]
    tick = float(market.instruments[symbol]["tick_size"])
    long = geometry.side == "long"
    decision = frame.close_ms(index)
    atr = float(frame["atr"][index])
    # The series of a segment ends before the phase end only when the instrument was delisted: a position still open
    # then settles at the delisting settlement proxy. A deadline falling inside an interruption moves to the first
    # bar after it (the position could not be closed before); beyond the phase end it is censored.
    series_end = candles.start_ms + candles.count * MINUTE
    natural = min(decision + geometry.horizon_ms, phase_end)
    delisting = series_end < natural
    deadline = min(natural, series_end)
    censored = False
    gap = market.gap_containing(symbol, deadline - MINUTE)
    if gap is not None:
        deadline = gap[1] + MINUTE
        if deadline > min(phase_end, series_end):
            deadline, censored = min(phase_end, series_end), True
    release = decision + geometry.ttl_ms
    first = candles.index(decision)
    if first < 0 or first >= candles.count:
        raise EngineError(f"{symbol}: decision outside candle coverage")
    # An order resting past the end of the data (a window ending inside a 4h bar) is scanned up to the
    # last candle; the scan also stops at the deadline, which never exceeds the phase end.
    eligible = min(geometry.ttl_ms // MINUTE, candles.count - first)
    low, high, opening, close = candles.low, candles.high, candles.open, candles.close
    reference = reference_entry(frame, index, geometry, tick)
    fill = entry = None
    if geometry.entry == "L":
        for i in range(first, first + eligible):
            if candles.start_ms + (i + 1) * MINUTE >= deadline:
                break
            if (low[i] <= reference) if long else (high[i] >= reference):
                fill, entry = i, reference
                break
    else:
        trigger = reference
        offset = STOP_LIMIT_OFFSET_ATR * atr
        limit = ex.ceil_to(trigger + offset, tick) if long else ex.floor_to(trigger - offset, tick)
        triggered = False
        for i in range(first, first + eligible):
            if candles.start_ms + (i + 1) * MINUTE >= deadline:
                break
            if not triggered:
                if not ((high[i] >= trigger) if long else (low[i] <= trigger)):
                    continue
                triggered = True
                price = max(trigger, opening[i]) if long else min(trigger, opening[i])
                if (price <= limit) if long else (price >= limit):
                    fill, entry = i, price
                    break
                if (low[i] <= limit) if long else (high[i] >= limit):  # gapped through, traded back to the limit
                    fill, entry = i, limit
                    break
                continue
            if (low[i] <= limit) if long else (high[i] >= limit):
                fill, entry = i, limit
                break
    if fill is None:
        return Path(False, release, reference_entry=reference, atr=atr, deadline_ms=deadline)
    fill_ms = candles.start_ms + (fill + 1) * MINUTE
    stop = _stop_from(entry, atr, geometry.stop_atr, tick, geometry.side)
    polarity_ok = stop < entry if long else stop > entry
    if not polarity_ok or stop <= 0:
        stop = entry - tick if long else entry + tick
    multiple = geometry.fixed_target
    target = _target_from(entry, stop, multiple, tick, geometry.side) if multiple is not None else float("nan")
    deadline_index = -((candles.start_ms - deadline) // MINUTE) - 1
    if deadline_index >= candles.count:
        raise EngineError(f"{symbol}: position outlives candle coverage")
    common = dict(entry=entry, initial_stop=stop, target=target, fill_index=fill, fill_ms=fill_ms, atr=atr,
                  deadline_ms=deadline, reference_entry=reference, delisting=delisting and deadline == series_end)

    def stop_price(i: int, level: float) -> float:
        return min(opening[i], level) if long else max(opening[i], level)

    if (low[fill] <= stop) if long else (high[fill] >= stop):
        return Path(True, fill_ms, exit_index=fill, exit_ms=fill_ms, exit_price=stop_price(fill, stop), reason="stop",
                    ambiguous=True, **common)
    if multiple is not None:
        ambiguous = (high[fill] >= target) if long else (low[fill] <= target)
        lower, upper = (stop, target) if long else (target, stop)
        hit = sw.first_touch(candles, blocks, fill + 1, deadline_index, lower, upper)
        if hit is None:
            return Path(True, deadline, exit_index=deadline_index, exit_ms=deadline, exit_price=close[deadline_index - 1],
                        reason="delisting" if delisting and deadline == series_end else ("censored" if censored else "time"),
                        ambiguous=ambiguous, **common)
        exit_ms = candles.start_ms + (hit + 1) * MINUTE
        stop_hit = (low[hit] <= stop) if long else (high[hit] >= stop)
        if stop_hit:
            both = (high[hit] >= target) if long else (low[hit] <= target)
            return Path(True, exit_ms, exit_index=hit, exit_ms=exit_ms, exit_price=stop_price(hit, stop), reason="stop",
                        ambiguous=ambiguous or both, **common)
        return Path(True, exit_ms, exit_index=hit, exit_ms=exit_ms, exit_price=target, reason="target",
                    ambiguous=ambiguous, **common)
    # Chandelier: the stop ratchets at every 4h close after the fill.
    level = stop
    extreme = entry
    begin = fill + 1
    bar_offset = (candles.start_ms - frame.start_ms) // MINUTE  # candle index -> minutes since the frame start
    while begin < deadline_index:
        boundary = ((begin + bar_offset) // BAR_MINUTES + 1) * BAR_MINUTES - bar_offset
        end = min(boundary, deadline_index)
        lower, upper = (level, math.inf) if long else (-math.inf, level)
        hit = sw.first_touch(candles, blocks, begin, end, lower, upper)
        if hit is not None:
            exit_ms = candles.start_ms + (hit + 1) * MINUTE
            reason = "trailing" if level != stop else "stop"
            return Path(True, exit_ms, exit_index=hit, exit_ms=exit_ms, exit_price=stop_price(hit, level), reason=reason,
                        **common)
        if end == boundary:
            extreme = max(extreme, max(high[begin:end])) if long else min(extreme, min(low[begin:end]))
            bar = (boundary + bar_offset) // BAR_MINUTES - 1
            current_atr = frame["atr"][bar]
            if current_atr is not None and current_atr > 0:
                candidate = (ex.floor_to(extreme - geometry.stop_atr * current_atr, tick) if long
                             else ex.ceil_to(extreme + geometry.stop_atr * current_atr, tick))
                level = max(level, candidate) if long else min(level, candidate)
        begin = end
    reason = "delisting" if delisting and deadline == series_end else ("censored" if censored else "time")
    return Path(True, deadline, exit_index=deadline_index, exit_ms=deadline, exit_price=close[deadline_index - 1],
                reason=reason, **common)


# --------------------------------------------------------------------------- sizing
def entry_rate(geometry: Geometry, profile: Mapping[str, float]) -> float:
    if geometry.entry == "L":
        return ex.MAKER_FEE + profile["entry_spread_rate"] + profile["entry_slippage_rate"]
    return ex.TAKER_FEE + profile["stop_spread_rate"] + profile["stop_slippage_rate"]


def planned_loss(entry: float, stop: float, geometry: Geometry, profile: Mapping[str, float], contract: float,
                 intervals: int | None = None) -> float:
    """Loss per unit of quantity if the initial stop is hit: price, entry and exit costs, funding provision for
    ``intervals`` settlements (default: one per 8 h of the horizon)."""
    stop_rate = ex.TAKER_FEE + profile["stop_spread_rate"] + profile["stop_slippage_rate"]
    count = geometry.funding_intervals if intervals is None else intervals
    funding = entry * max(0.0, profile["funding_provision_rate"]) * count
    return contract * (abs(entry - stop) + entry * entry_rate(geometry, profile) + stop * stop_rate + funding)


def size(entry: float, stop: float, geometry: Geometry, profile: Mapping[str, float], instrument: Mapping,
         equity: float, intervals: int | None = None) -> float:
    contract = float(instrument["contract_size"])
    step = float(instrument["quantity_step"])
    per_unit = planned_loss(entry, stop, geometry, profile, contract, intervals)
    if not per_unit > 0:
        return 0.0
    cap = equity * LEVERAGE / MAX_ACTIVE
    raw = min(RISK_FRACTION * equity / per_unit, cap / (entry * contract))
    quantity = math.floor(raw / step + 1e-9) * step
    quantity = float(round(quantity, 12))
    notional = quantity * entry * contract
    if quantity <= 0 or quantity < float(instrument["min_quantity"]) or notional < max(EXCHANGE_MIN_NOTIONAL, float(instrument["min_notional"])):
        return 0.0
    return quantity


# --------------------------------------------------------------------------- portfolio
@dataclass
class Trade:
    symbol: str
    side: str
    decision_ms: int
    fill_ms: int
    exit_ms: int
    entry: float
    stop: float
    exit_price: float
    reason: str
    ambiguous: bool
    quantity: float
    strength: float
    net: dict  # by profile
    risk: dict  # planned loss by profile
    funding: dict
    event: str | None = None  # delisting / interruption / censored
    risk_conservative: dict | None = None  # planned loss with the conservative funding provision
    funding_imputed: int = 0  # missing settlements booked against the position

    def r(self, profile: str) -> float:
        return self.net[profile] / self.risk[profile]

    def net_conservative(self, profile: str) -> float:
        """Conservative scenario: a trade touched by a delisting, an interruption or the censoring is booked at no
        better than the loss of its full conservative planned risk."""
        risk = (self.risk_conservative or self.risk)[profile]
        return min(self.net[profile], -risk) if self.event else self.net[profile]

    def r_conservative(self, profile: str) -> float:
        return self.net_conservative(profile) / (self.risk_conservative or self.risk)[profile]


@dataclass
class StreamRun:
    trades: list
    signals: int
    admitted: int
    unfilled: int
    unsized: int
    rejections: Counter


def run_stream(market: Market, rows: Sequence[Signal], geometries: Mapping[str, Geometry], window: tuple[int, int],
               profiles: Mapping[str, Mapping[str, float]], equity: float = REFERENCE_EQUITY,
               max_active: int = MAX_ACTIVE, sizing_profile: str = "baseline", phase_end: int | None = None) -> StreamRun:
    """Admit ``rows`` (sorted signals) through the portfolio; ``geometries`` maps a side to its geometry.

    ``window`` bounds the decisions; positions run until ``phase_end`` (default: the window end)."""
    start, end = window
    end = end if phase_end is None else phase_end
    active: list[tuple[str, int]] = []  # (symbol, release_ms)
    trades: list[Trade] = []
    rejections: Counter = Counter()
    admitted = unfilled = unsized = 0
    for decision, _, symbol, index, side, value in rows:
        active = [item for item in active if item[1] > decision]
        if any(item[0] == symbol for item in active):
            rejections["symbol_already_active"] += 1
            continue
        if len(active) >= max_active:
            rejections["portfolio_full"] += 1
            continue
        geometry = geometries[side]
        instrument = market.instruments[symbol]
        frame = market.frames[symbol]
        tick = float(instrument["tick_size"])
        reference = reference_entry(frame, index, geometry, tick)
        probe_stop = _stop_from(reference, float(frame["atr"][index]), geometry.stop_atr, tick, side)
        cadence = market.cadence_hours(symbol, decision) if market.cadence_aware else 8
        intervals = -(-geometry.hold_days * 24 // cadence)
        conservative_intervals = -(-geometry.hold_days * 24 // max(1, cadence // 2))
        if size(reference, probe_stop, geometry, profiles[sizing_profile], instrument, equity, intervals if market.cadence_aware else None) <= 0:
            unsized += 1
            rejections["unsized"] += 1
            continue
        path = simulate_path(market, symbol, index, geometry, end)
        admitted += 1
        active.append((symbol, path.release_ms))
        if not path.filled:
            unfilled += 1
            continue
        quantity = size(path.entry, path.initial_stop, geometry, profiles[sizing_profile], instrument, equity,
                        intervals if market.cadence_aware else None)
        if quantity <= 0:
            unsized += 1
            rejections["unsized_at_fill"] += 1
            continue
        nets, risks, fundings, risks_conservative = {}, {}, {}, {}
        imputed = missing_settlements(market, symbol, path.fill_ms, path.exit_ms, cadence) if market.cadence_aware else []
        contract = float(instrument["contract_size"])
        for name, profile in profiles.items():
            net, funding = settle(market, symbol, path, geometry, quantity, profile)
            # A missing settlement is never booked as zero: the nearest recorded rate, against the position.
            charge = sum(abs(rate) for _, rate in imputed) * quantity * contract * path.entry
            nets[name] = net - charge
            fundings[name] = funding - charge
            risks[name] = quantity * planned_loss(path.entry, path.initial_stop, geometry, profile, contract,
                                                  intervals if market.cadence_aware else None)
            risks_conservative[name] = quantity * planned_loss(path.entry, path.initial_stop, geometry, profile, contract,
                                                               conservative_intervals if market.cadence_aware else None)
        trades.append(Trade(symbol, side, decision, path.fill_ms, path.exit_ms, path.entry, path.initial_stop,
                            path.exit_price, path.reason, path.ambiguous, quantity, value, nets, risks, fundings,
                            path.event, risks_conservative, len(imputed)))
    return StreamRun(trades, len(rows), admitted, unfilled, unsized, rejections)


def missing_settlements(market: Market, symbol: str, begin_ms: int, end_ms: int, cadence_h: int) -> list[tuple[int, float]]:
    """Settlements missing between ``begin_ms`` and ``end_ms``: every interval between consecutive recorded settlements
    (the boundaries included) longer than 1.5 x the cadence lacks round(interval / cadence) - 1 of them; each is
    returned with the nearest recorded rate."""
    series = market.funding.get(symbol)
    if series is None or not len(series.timestamps):
        return []
    step = cadence_h * 3_600_000
    times = list(series.timestamps)
    lo = bisect.bisect_left(times, begin_ms - step)
    hi = bisect.bisect_right(times, end_ms + step)
    window = times[lo:hi]
    out = []
    for a, b in zip(window, window[1:]):
        if b - a > 1.5 * step and a < end_ms and b > begin_ms:
            for k in range(1, round((b - a) / step)):
                ts = a + k * step
                if begin_ms < ts <= end_ms:
                    nearest = lo + (window.index(a) if ts - a <= b - ts else window.index(b))
                    out.append((ts, float(series.rates[nearest])))
    return out


def settle(market: Market, symbol: str, path: Path, geometry: Geometry, quantity: float,
           profile: Mapping[str, float]) -> tuple[float, float]:
    """Net PnL (quote) and funding of one filled path at ``quantity`` under ``profile``."""
    contract = float(market.instruments[symbol]["contract_size"])
    units = quantity * contract
    plan = ex.Plan(symbol, path.fill_ms, path.entry, path.initial_stop,
                   path.target if path.reason == "target" else math.nan, units, 1.0, 0.0, path.deadline_ms,
                   geometry.side)
    outcome = ex.Outcome(plan, filled=True, fill_ms=path.fill_ms, exit_ms=path.exit_ms, exit_price=path.exit_price,
                         reason="holding_deadline" if path.reason in ("time", "delisting", "censored") else path.reason,
                         ambiguous=path.ambiguous)
    outcome.entry_cost = units * path.entry * entry_rate(geometry, profile)
    ex.settle(outcome, market.candles[symbol], market.funding.get(symbol), profile, path.fill_index, path.exit_index)
    return outcome.net, outcome.funding
