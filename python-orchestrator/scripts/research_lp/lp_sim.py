"""Passive-fill simulator on the `lp` tape: resting post-only orders, three fill rules, latency,
mark-outs, funding, fees, and two readings (unconstrained; 500 USDT portfolio with ≤ N live orders).

An *intent* is a decision `(decision_ts, side, price, size, tag, ttl_us)`. The order becomes live
`latency_ms` later; if at that instant it would cross the opposite touch it is cancelled
(post-only model, status `crossed`). A live order rests until `decision + ttl` where `ttl` is the
intent's own `ttl_us` when > 0, else the global `ttl_s`.

Event-driven intents (`event_intents`): a *detected* event (liquidation cluster, excursion:
`arm_ts_us == ts_us`) is decided at the next minute boundary after `ts_us` (a minute-loop
participant) with the global TTL; a *scheduled* clock window (`arm_ts_us < ts_us`, G5) is decided
at `arm_ts_us` — h − 5 (or h − 2), never h − 1 — and its order lives until `end_ts_us` (h + 1), so
the resting order spans the settlement instant as the mechanism requires (architecture §2.3).

Fill rules (`rule`):

- `through`  — pessimistic: filled by the first print strictly beyond the price (a buy by a
  print below it), or by the book trading through it (opposite best at or beyond the price);
- `touch`    — optimistic, front of the queue: filled by the first print at or beyond the price
  whose taker is on the opposite side (a buy by a seller's print at or below it);
- `queue`    — queue rule with own size: the displayed size at the price when the order becomes
  live is ahead of it; prints at the price by opposite takers consume that queue first, then the
  order, which fills progressively (`partial` at the TTL if some but not all of it traded); a
  print beyond the price or a book-through fills the remainder. Cancellations ahead are bounded
  by the displayed size (`queue_ahead = min(queue_ahead, displayed)`), an upper bound since
  newcomers queue behind. **Known optimism**: `Book.displayed` returns 0 when the price is no
  longer among the five displayed levels (the book moved away, or the level was consumed), so
  the queue ahead collapses to 0 and the next opposite print at the price fills us first. The
  five-level book cannot tell "level emptied" from "level out of view"; the true queue is between
  0 and the last value seen. Protocols must read `queue` as an optimistic bound of that kind
  (A2 §5.6), `through` as the pessimistic one.

Mark-outs: `m_0` against the mid just before the fill (half-spread captured), `m_h = side ×
(mid(τ+h) − p) / p` in bp at 60 / 300 / 900 s. Exit: a passive order on the opposite touch at
τ + latency under the same rule, until `exit_max_s` — the remainder exits as a taker at the
opposite touch then (`exit_kind` maker / taker / mixed). The round trip `rt_bp` is gross;
`fee_bp` = maker on entry + weighted maker/taker on exit (2 / 5 bp by default); `funding_bp` =
−side × Σ rates of the settlements crossed (`first_fill < T ≤ exit`; a long pays a positive
rate), from the tape's `funding-rate` rows or a constant schedule anchored at 00:00 UTC with a
parameterized interval (8 h → 00/08/16 UTC); `net_bp = rt − fee + funding`.

Portfolio reading: orders are admitted in decision order while fewer than `max_live` commitments
are alive and their notionals (contracts × ctVal × price, frozen margin at 1×) fit in `capital ×
leverage`; a commitment lasts until the exit (filled), the activation (crossed) or the TTL
(unfilled). Outcomes are those of the unconstrained run (no market impact assumed).

Sums use `math.fsum`; the block bootstrap resamples 10-minute blocks of fill time.
"""

from __future__ import annotations

import math
from dataclasses import asdict, dataclass

import numpy as np

from . import tape

HORIZONS_S = (60, 300, 900)
RULES = ("touch", "through", "queue")
STATUS = {"stale": 0, "no_inside": 1, "crossed": 2, "unfilled": 3, "partial": 4, "filled": 5, "skipped_size": 6}
STATUS_NAME = {v: k for k, v in STATUS.items()}
EXIT_KIND = {"none": 0, "maker": 1, "taker": 2, "mixed": 3}
PLACEMENTS = ("touch", "inside")  # or "ticks:k"
SPREAD_TRANCHES_BP = ((0.0, 1.0), (1.0, 2.0), (2.0, 4.0), (4.0, 8.0), (8.0, math.inf))
BLOCK_S = 600
RESAMPLES = 1000
SEED = 20261102

INTENT_DTYPE = np.dtype([("decision_ts", "<i8"), ("side", "<i1"), ("price", "<f8"), ("size", "<f8"), ("tag", "<i4"), ("ttl_us", "<i8")])  # ttl_us 0 → SimParams.ttl_s
ORDER_DTYPE = np.dtype(
    [("decision_ts", "<i8"), ("activation_ts", "<i8"), ("side", "<i1"), ("price", "<f8"), ("size", "<f8"), ("tag", "<i4"), ("ttl_us", "<i8"), ("status", "<i1"),
     ("bid0", "<f8"), ("ask0", "<f8"), ("half_spread_bp", "<f8"), ("queue_ahead", "<f8"),
     ("first_fill_ts", "<i8"), ("fill_ts", "<i8"), ("filled_size", "<f8"), ("m_0", "<f8")]
    + [(f"m_{h}s", "<f8") for h in HORIZONS_S]
    + [("exit_ts", "<i8"), ("exit_price", "<f8"), ("exit_kind", "<i1"), ("exit_truncated", "<i1"), ("rt_bp", "<f8"), ("fee_bp", "<f8"), ("funding_bp", "<f8"),
       ("settlements", "<i2"), ("net_bp", "<f8"), ("admitted", "<i1")]
)


def fsum_mean(values) -> float:
    values = [float(v) for v in values if math.isfinite(float(v))]
    return math.fsum(values) / len(values) if values else float("nan")


# ----------------------------------------------------------------------------- inputs


@dataclass(frozen=True)
class InstrumentSpec:
    inst_id: str
    tick: float
    ct_val: float = 1.0
    lot: float = 1.0
    min_size: float = 1.0

    def notional(self, price: float, size: float) -> float:
        return float(price) * float(size) * self.ct_val

    def size_for_notional(self, notional: float, price: float) -> float:
        """Contracts (a multiple of the lot) whose notional at `price` does not exceed `notional`; 0 below the minimum."""
        if not (price > 0) or not math.isfinite(price):
            return 0.0
        raw = notional / (price * self.ct_val)
        size = math.floor(raw / self.lot + 1e-9) * self.lot
        return size if size + 1e-12 >= self.min_size else 0.0

    @classmethod
    def from_okx_row(cls, row: dict) -> "InstrumentSpec":
        return cls(str(row["instId"]), float(row["tickSz"]), float(row.get("ctVal") or 1.0), float(row.get("lotSz") or 1.0), float(row.get("minSz") or row.get("lotSz") or 1.0))

    def to_dict(self) -> dict:
        return asdict(self)


@dataclass
class Book:
    """books5 arrays of one instrument, sorted by `ts_us`."""

    ts: np.ndarray
    bid_px: np.ndarray
    bid_sz: np.ndarray
    ask_px: np.ndarray
    ask_sz: np.ndarray

    @classmethod
    def from_rows(cls, rows: np.ndarray) -> "Book":
        order = np.argsort(rows["ts_us"], kind="stable")
        rows = rows[order]
        return cls(rows["ts_us"].astype(np.int64), np.ascontiguousarray(rows["bid_px"], dtype=np.float64), np.ascontiguousarray(rows["bid_sz"], dtype=np.float64),
                   np.ascontiguousarray(rows["ask_px"], dtype=np.float64), np.ascontiguousarray(rows["ask_sz"], dtype=np.float64))

    @classmethod
    def from_top(cls, ts_us, bid, ask, bid_sz=None, ask_sz=None) -> "Book":
        """A one-level book from best bid / ask series (tests, or a bbo reduction)."""
        n = len(ts_us)
        nan = np.full((n, tape.LEVELS - 1), np.nan)
        zero = np.zeros((n, tape.LEVELS - 1))
        bid_sz = np.ones(n) if bid_sz is None else np.asarray(bid_sz, dtype=np.float64)
        ask_sz = np.ones(n) if ask_sz is None else np.asarray(ask_sz, dtype=np.float64)
        return cls(np.asarray(ts_us, dtype=np.int64), np.column_stack([np.asarray(bid, dtype=np.float64), nan]), np.column_stack([bid_sz, zero]),
                   np.column_stack([np.asarray(ask, dtype=np.float64), nan]), np.column_stack([ask_sz, zero]))

    @property
    def size(self) -> int:
        return int(self.ts.size)

    @property
    def bid(self) -> np.ndarray:
        return self.bid_px[:, 0]

    @property
    def ask(self) -> np.ndarray:
        return self.ask_px[:, 0]

    @property
    def mid(self) -> np.ndarray:
        return (self.bid_px[:, 0] + self.ask_px[:, 0]) / 2.0

    def index_at(self, ts_us: int, strict: bool = False) -> int:
        """Last snapshot at or before `ts_us` (strictly before when `strict`); −1 when none."""
        return int(np.searchsorted(self.ts, ts_us, side="left" if strict else "right")) - 1

    def displayed(self, side: int, price: float, index: int, tol: float) -> float:
        """Size displayed at `price` on the order's own side (bids for a buy) in snapshot `index`; 0 when the level is absent."""
        if index < 0:
            return 0.0
        px = self.bid_px[index] if side > 0 else self.ask_px[index]
        sz = self.bid_sz[index] if side > 0 else self.ask_sz[index]
        hit = np.abs(px - price) <= tol
        return float(sz[hit].sum()) if hit.any() else 0.0

    def inferred_tick(self) -> float:
        """Smallest positive price difference between displayed levels (fallback when no instrument file is given)."""
        diffs = []
        for px in (self.bid_px, self.ask_px):
            d = np.abs(np.diff(px, axis=1))
            d = d[np.isfinite(d) & (d > 0)]
            if d.size:
                diffs.append(float(d.min()))
        spread = self.ask - self.bid
        spread = spread[np.isfinite(spread) & (spread > 0)]
        if spread.size:
            diffs.append(float(spread.min()))
        return min(diffs) if diffs else float("nan")


@dataclass
class Prints:
    """Trade prints of one instrument, sorted by `ts_us`."""

    ts: np.ndarray
    price: np.ndarray
    size: np.ndarray
    taker_buy: np.ndarray

    @classmethod
    def from_rows(cls, rows: np.ndarray) -> "Prints":
        order = np.argsort(rows["ts_us"], kind="stable")
        rows = rows[order]
        return cls(rows["ts_us"].astype(np.int64), rows["price"].astype(np.float64), rows["size"].astype(np.float64), rows["taker_buy"].astype(np.int8))

    @classmethod
    def from_lists(cls, ts_us, price, size, taker_buy) -> "Prints":
        return cls(np.asarray(ts_us, dtype=np.int64), np.asarray(price, dtype=np.float64), np.asarray(size, dtype=np.float64), np.asarray(taker_buy, dtype=np.int8))


class FundingSchedule:
    """Settlement instants (µs, sorted) and the rate applied at each (fraction, positive = longs pay)."""

    def __init__(self, ts_us, rate, source: str = "constant") -> None:
        ts_us = np.asarray(ts_us, dtype=np.int64)
        rate = np.asarray(rate, dtype=np.float64)
        order = np.argsort(ts_us, kind="stable")
        self.ts_us = ts_us[order]
        self.rate = rate[order]
        self.source = source

    @classmethod
    def constant(cls, start_us: int, end_us: int, interval_h: float, rate: float, anchor_h: float = 0.0) -> "FundingSchedule":
        """Settlements every `interval_h` hours from `anchor_h` UTC (8 h → 00 / 08 / 16 UTC), covering [start, end + interval]."""
        step = int(round(interval_h * 3600 * tape.SECOND_US))
        anchor = int(round(anchor_h * 3600 * tape.SECOND_US))
        day0 = tape.day_of_us(int(start_us)) * tape.DAY_S * tape.SECOND_US
        first = day0 + anchor
        while first > start_us:
            first -= step
        while first <= start_us:
            first += step
        moments = np.arange(first, int(end_us) + step + 1, step, dtype=np.int64)
        return cls(moments, np.full(moments.size, float(rate)), source=f"constant {rate:.6g} every {interval_h} h")

    @classmethod
    def from_tape(cls, rows: np.ndarray) -> "FundingSchedule":
        """From `funding` rows: for each `funding_time`, the settled rate (`sett_state` = settled)
        when observed, else the last `funding_rate` announced for it."""
        if rows.size == 0:
            return cls(np.zeros(0, dtype=np.int64), np.zeros(0), source="tape (empty)")
        order = np.argsort(rows["ts_us"], kind="stable")
        rows = rows[order]
        rates: dict[int, float] = {}
        settled: set[int] = set()
        for row in rows:
            moment = int(row["funding_time_us"])
            if moment <= 0:
                continue
            if int(row["sett_state"]) == 1 and math.isfinite(float(row["sett_funding_rate"])):
                rates[moment] = float(row["sett_funding_rate"])
                settled.add(moment)
            elif moment not in settled and math.isfinite(float(row["funding_rate"])):
                rates[moment] = float(row["funding_rate"])
        moments = sorted(rates)
        return cls(np.array(moments, dtype=np.int64), np.array([rates[m] for m in moments]), source="tape")

    def crossed(self, open_us: int, close_us: int) -> tuple[int, float]:
        """Settlements with `open < T ≤ close`: their count and the fsum of their rates."""
        lo = int(np.searchsorted(self.ts_us, open_us, side="right"))
        hi = int(np.searchsorted(self.ts_us, close_us, side="right"))
        if hi <= lo:
            return 0, 0.0
        return hi - lo, math.fsum(self.rate[lo:hi].tolist())


@dataclass(frozen=True)
class SimParams:
    rule: str = "queue"
    latency_ms: float = 250.0
    ttl_s: float = 60.0
    stale_s: float = 10.0
    post_only: bool = True
    order_notional: float = 100.0
    maker_bp: float = 2.0
    taker_bp: float = 5.0
    exit_max_s: float = 3600.0
    funding_interval_h: float = 8.0
    funding_anchor_h: float = 0.0
    funding_rate: float = 0.0001  # 0.01 % per interval, the OKX interest-rate term
    capital: float = 500.0
    max_live: int = 4
    leverage: float = 1.0
    grid_s: float = 60.0
    placement: str = "touch"

    def to_dict(self) -> dict:
        return asdict(self)


# ----------------------------------------------------------------------------- intents


def _placement_price(placement: str, side: int, bid: float, ask: float, tick: float) -> tuple[float, bool]:
    """Order price for a placement; `ok` False when the placement is impossible (no room inside)."""
    if placement == "touch":
        return (bid if side > 0 else ask), True
    if placement == "inside":
        if ask - bid < 2 * tick - tick * 1e-6:
            return float("nan"), False
        return (bid + tick if side > 0 else ask - tick), True
    if placement.startswith("ticks:"):
        k = float(placement.split(":", 1)[1])
        return (bid - k * tick if side > 0 else ask + k * tick), True
    raise ValueError(f"unknown placement {placement}")


def resolve_intents(decisions: np.ndarray, sides: np.ndarray, tags: np.ndarray, book: Book, spec: InstrumentSpec, params: SimParams, ttl_us: np.ndarray | None = None) -> np.ndarray:
    """Intents from decision instants: the price follows the placement on the last quote at or
    before the decision (NaN → `stale` / `no_inside` in the simulation), the size is the lot
    multiple under `order_notional`; `ttl_us` per intent (0 → the global `ttl_s`)."""
    n = int(decisions.size)
    intents = np.zeros(n, dtype=INTENT_DTYPE)
    intents["decision_ts"] = decisions
    intents["side"] = sides
    intents["tag"] = tags
    if ttl_us is not None:
        intents["ttl_us"] = np.maximum(np.asarray(ttl_us, dtype=np.int64), 0)
    intents["price"] = np.nan
    stale_us = int(round(params.stale_s * tape.SECOND_US))
    for i in range(n):
        k = book.index_at(int(decisions[i]))
        if k < 0 or decisions[i] - book.ts[k] > stale_us:
            continue  # stale quote: price stays NaN, size 0
        bid, ask = float(book.bid[k]), float(book.ask[k])
        if not (math.isfinite(bid) and math.isfinite(ask) and bid > 0 and ask > bid):
            continue
        price, ok = _placement_price(params.placement, int(sides[i]), bid, ask, spec.tick)
        if not ok:
            intents["price"][i] = -1.0  # marker: no room inside
            continue
        price = round(price / spec.tick) * spec.tick
        intents["price"][i] = price
        intents["size"][i] = spec.size_for_notional(params.order_notional, price)
    return intents


def grid_decisions(book: Book, params: SimParams, horizon_margin_s: float | None = None) -> np.ndarray:
    """Epoch-aligned decisions every `grid_s`, leaving the TTL and the longest mark-out horizon before the tape ends."""
    if book.size == 0:
        return np.zeros(0, dtype=np.int64)
    grid = int(round(params.grid_s * tape.SECOND_US))
    margin = (params.ttl_s + max(HORIZONS_S)) if horizon_margin_s is None else horizon_margin_s
    start = int(book.ts[0])
    end = int(book.ts[-1]) - int(round(margin * tape.SECOND_US))
    first = -(-start // grid) * grid
    if end <= first:
        return np.zeros(0, dtype=np.int64)
    return np.arange(first, end, grid, dtype=np.int64)


def grid_intents(book: Book, spec: InstrumentSpec, params: SimParams, sides: tuple[int, ...] = (1, -1)) -> np.ndarray:
    decisions = grid_decisions(book, params)
    all_dec = np.concatenate([decisions for _ in sides]) if decisions.size else np.zeros(0, dtype=np.int64)
    all_sides = np.concatenate([np.full(decisions.size, s, dtype=np.int8) for s in sides]) if decisions.size else np.zeros(0, dtype=np.int8)
    return resolve_intents(all_dec, all_sides, np.zeros(all_dec.size, dtype=np.int32), book, spec, params)


def event_decisions(event_ts_us: np.ndarray, arm_ts_us: np.ndarray | None = None, end_ts_us: np.ndarray | None = None, next_minute: bool = True) -> tuple[np.ndarray, np.ndarray]:
    """`(decision_ts, ttl_us)` per event. Detected event (`arm ≥ ts`, or no `arm`): decision at the next
    minute boundary after `ts` (`next_minute`) or at `ts`, `ttl_us` 0 (the global TTL). Scheduled window
    (`arm < ts`, the clock windows of G5): decision at `arm`, `ttl_us = end − arm` when `end` is given
    and later than `arm` (else 0). Decisions never precede the instant the event became known."""
    ts = np.asarray(event_ts_us, dtype=np.int64)
    minute = 60 * tape.SECOND_US
    next_min = (ts // minute + 1) * minute
    decisions = next_min.copy() if next_minute else ts.copy()
    ttl = np.zeros(ts.size, dtype=np.int64)
    if arm_ts_us is not None and ts.size:
        arm = np.asarray(arm_ts_us, dtype=np.int64)
        scheduled = arm < ts
        decisions = np.where(scheduled, arm, decisions)
        if end_ts_us is not None:
            end = np.asarray(end_ts_us, dtype=np.int64)
            ttl = np.where(scheduled & (end > arm), end - arm, 0).astype(np.int64)
    return decisions, ttl


def event_intents(event_ts_us: np.ndarray, event_sides: np.ndarray, book: Book, spec: InstrumentSpec, params: SimParams, tags: np.ndarray | None = None, next_minute: bool = True,
                  arm_ts_us: np.ndarray | None = None, end_ts_us: np.ndarray | None = None) -> np.ndarray:
    """One intent per event (both sides when the event's side is 0), decided per `event_decisions`:
    the next minute boundary after a detected event, the arming instant of a scheduled clock window
    (then resting until its `end_ts_us`)."""
    decisions, ttls = event_decisions(event_ts_us, arm_ts_us, end_ts_us, next_minute)
    out_dec, out_sides, out_tags, out_ttl = [], [], [], []
    for i in range(int(decisions.size)):
        side = int(event_sides[i])
        for s in ((1, -1) if side == 0 else (side,)):
            out_dec.append(int(decisions[i]))
            out_sides.append(s)
            out_tags.append(int(tags[i]) if tags is not None else i)
            out_ttl.append(int(ttls[i]))
    return resolve_intents(np.array(out_dec, dtype=np.int64), np.array(out_sides, dtype=np.int8), np.array(out_tags, dtype=np.int32), book, spec, params, ttl_us=np.array(out_ttl, dtype=np.int64))


# ----------------------------------------------------------------------------- fills


def _fill(book: Book, prints: Prints, side: int, price: float, size: float, start_us: int, end_us: int, queue_ahead: float, rule: str, tol: float) -> tuple[int, int, float]:
    """`(first_fill_ts, fill_ts, filled_size)` of an order resting at `price` over `(start, end]`;
    `fill_ts` is −1 unless the whole size traded, `first_fill_ts` −1 when nothing traded."""
    j0 = int(np.searchsorted(prints.ts, start_us, side="right"))
    j1 = int(np.searchsorted(prints.ts, end_us, side="right"))
    k0 = int(np.searchsorted(book.ts, start_us, side="right"))
    k1 = int(np.searchsorted(book.ts, end_us, side="right"))
    book_through_ts = -1
    if k1 > k0:
        opposite = book.ask[k0:k1] if side > 0 else book.bid[k0:k1]
        crossed = (opposite <= price + tol) if side > 0 else (opposite >= price - tol)
        crossed &= np.isfinite(opposite)
        if crossed.any():
            book_through_ts = int(book.ts[k0 + int(np.argmax(crossed))])
    first_fill, fill_ts, filled = -1, -1, 0.0
    px = prints.price[j0:j1]
    tss = prints.ts[j0:j1]
    if rule in ("through", "touch"):
        if px.size:
            if rule == "through":
                hit = (px < price - tol) if side > 0 else (px > price + tol)
            else:
                takers = prints.taker_buy[j0:j1]
                hit = ((px <= price + tol) & (takers == 0)) if side > 0 else ((px >= price - tol) & (takers == 1))
            if hit.any():
                fill_ts = int(tss[int(np.argmax(hit))])
        if book_through_ts >= 0 and (fill_ts < 0 or book_through_ts < fill_ts):
            fill_ts = book_through_ts
        if fill_ts >= 0:
            return fill_ts, fill_ts, size
        return -1, -1, 0.0
    if rule != "queue":
        raise ValueError(f"unknown fill rule {rule}")
    qa = float(queue_ahead)
    sizes = prints.size[j0:j1]
    takers = prints.taker_buy[j0:j1]
    for j in range(px.size):
        t = int(tss[j])
        if book_through_ts >= 0 and t > book_through_ts:
            break
        k = book.index_at(t, strict=True)
        if k >= 0:
            qa = min(qa, book.displayed(side, price, k, tol))
        p = float(px[j])
        beyond = (p < price - tol) if side > 0 else (p > price + tol)
        if beyond:
            if first_fill < 0:
                first_fill = t
            return first_fill, t, size
        at_price = abs(p - price) <= tol
        opposite_taker = (takers[j] == 0) if side > 0 else (takers[j] == 1)
        if at_price and opposite_taker:
            take = float(sizes[j])
            ahead = min(qa, take)
            qa -= ahead
            take -= ahead
            if take > 0:
                add = min(take, size - filled)
                if add > 0:
                    if first_fill < 0:
                        first_fill = t
                    filled += add
                    if filled >= size - 1e-12:
                        return first_fill, t, size
    if book_through_ts >= 0:
        if first_fill < 0:
            first_fill = book_through_ts
        return first_fill, book_through_ts, size
    return first_fill, -1, filled


def simulate(book: Book, prints: Prints, funding: FundingSchedule, spec: InstrumentSpec, intents: np.ndarray, params: SimParams) -> np.ndarray:
    """Simulate every intent independently (the unconstrained reading) and return the order rows."""
    if params.rule not in RULES:
        raise ValueError(f"unknown fill rule {params.rule}")
    n = int(intents.size)
    rows = np.zeros(n, dtype=ORDER_DTYPE)
    for name in ORDER_DTYPE.names:
        if ORDER_DTYPE[name].kind == "f":
            rows[name] = np.nan
    for name in ("activation_ts", "first_fill_ts", "fill_ts", "exit_ts"):
        rows[name] = -1
    rows["decision_ts"] = intents["decision_ts"]
    rows["side"] = intents["side"]
    rows["price"] = intents["price"]
    rows["size"] = intents["size"]
    rows["tag"] = intents["tag"]
    rows["admitted"] = -1
    default_ttl_us = int(round(params.ttl_s * tape.SECOND_US))
    own_ttl = intents["ttl_us"] if "ttl_us" in intents.dtype.names else np.zeros(n, dtype=np.int64)
    rows["ttl_us"] = np.where(own_ttl > 0, own_ttl, default_ttl_us)  # the effective lifetime of each order
    if n == 0 or book.size == 0:
        return rows
    latency_us = int(round(params.latency_ms * 1000))
    exit_max_us = int(round(params.exit_max_s * tape.SECOND_US))
    tol = spec.tick * 1e-6
    mid = book.mid
    last_ts = int(book.ts[-1])
    for i in range(n):
        d = int(intents["decision_ts"][i])
        side = int(intents["side"][i])
        price = float(intents["price"][i])
        size = float(intents["size"][i])
        if not math.isfinite(price):
            rows["status"][i] = STATUS["stale"]
            continue
        if price < 0:
            rows["status"][i] = STATUS["no_inside"]
            continue
        if size <= 0:
            rows["status"][i] = STATUS["skipped_size"]
            continue
        a = d + latency_us
        rows["activation_ts"][i] = a
        ka = book.index_at(a)
        if ka < 0:
            rows["status"][i] = STATUS["stale"]
            continue
        bid0, ask0 = float(book.bid[ka]), float(book.ask[ka])
        rows["bid0"][i], rows["ask0"][i] = bid0, ask0
        rows["half_spread_bp"][i] = (ask0 - bid0) / (ask0 + bid0) * 1e4
        if params.post_only and ((side > 0 and price >= ask0 - tol) or (side < 0 and price <= bid0 + tol)):
            rows["status"][i] = STATUS["crossed"]
            continue
        queue_ahead = book.displayed(side, price, ka, tol)
        rows["queue_ahead"][i] = queue_ahead
        end = d + int(rows["ttl_us"][i])
        first_fill, fill_ts, filled = _fill(book, prints, side, price, size, a, end, queue_ahead, params.rule, tol)
        if filled <= 0:
            rows["status"][i] = STATUS["unfilled"]
            continue
        complete = fill_ts >= 0
        tau = fill_ts if complete else end
        rows["status"][i] = STATUS["filled"] if complete else STATUS["partial"]
        rows["first_fill_ts"][i] = first_fill
        rows["fill_ts"][i] = tau
        rows["filled_size"][i] = filled
        kb = book.index_at(tau, strict=True)
        if kb >= 0:
            rows["m_0"][i] = side * (float(mid[kb]) - price) / price * 1e4
        for h in HORIZONS_S:
            kh = book.index_at(tau + h * tape.SECOND_US)
            if kh >= 0 and tau + h * tape.SECOND_US <= last_ts:
                rows[f"m_{h}s"][i] = side * (float(mid[kh]) - price) / price * 1e4
        # Exit: passive on the opposite touch, remainder taker at exit_max.
        ex_start = tau + latency_us
        ke = book.index_at(ex_start)
        if ke < 0:
            continue
        ex_bid, ex_ask = float(book.bid[ke]), float(book.ask[ke])
        if side > 0:
            exit_price = ex_ask if ex_ask > ex_bid else ex_bid + spec.tick
        else:
            exit_price = ex_bid if ex_bid < ex_ask else ex_ask - spec.tick
        exit_price = round(exit_price / spec.tick) * spec.tick
        ex_end = tau + exit_max_us
        truncated = ex_end > last_ts
        ex_end = min(ex_end, last_ts)
        qa_exit = book.displayed(-side, exit_price, ke, tol)
        _, ex_fill_ts, ex_filled = _fill(book, prints, -side, exit_price, filled, ex_start, ex_end, qa_exit, params.rule, tol)
        ex_filled = max(0.0, min(ex_filled, filled))
        rest = filled - ex_filled
        if ex_fill_ts >= 0:
            exit_ts, weighted_price, exit_fee, kind = ex_fill_ts, exit_price, params.maker_bp, EXIT_KIND["maker"]
        else:
            kl = book.index_at(ex_end)
            taker_px = float(book.bid[kl]) if side > 0 else float(book.ask[kl])
            weighted_price = (ex_filled * exit_price + rest * taker_px) / filled
            exit_fee = (ex_filled * params.maker_bp + rest * params.taker_bp) / filled
            kind = EXIT_KIND["taker"] if ex_filled <= 0 else EXIT_KIND["mixed"]
            exit_ts = ex_end
        rows["exit_ts"][i] = exit_ts
        rows["exit_price"][i] = weighted_price
        rows["exit_kind"][i] = kind
        rows["exit_truncated"][i] = 1 if truncated else 0
        rt = side * (weighted_price - price) / price * 1e4
        settlements, rate_sum = funding.crossed(first_fill, exit_ts)
        funding_bp = -side * rate_sum * 1e4
        rows["rt_bp"][i] = rt
        rows["fee_bp"][i] = params.maker_bp + exit_fee
        rows["funding_bp"][i] = funding_bp
        rows["settlements"][i] = settlements
        rows["net_bp"][i] = math.fsum([rt, -(params.maker_bp + exit_fee), funding_bp])
    return rows


# ----------------------------------------------------------------------------- portfolio reading


def portfolio_admit(rows: np.ndarray, spec: InstrumentSpec, params: SimParams, ttl_us: int | None = None) -> np.ndarray:
    """`admitted` flags (1 admitted, 0 refused for capacity, −1 not eligible) for the 500 USDT reading.
    An unfilled order holds its commitment until `decision + ttl`, the order's own `ttl_us` when set."""
    default_ttl_us = int(round(params.ttl_s * tape.SECOND_US)) if ttl_us is None else ttl_us
    own_ttl = rows["ttl_us"] if "ttl_us" in rows.dtype.names else np.zeros(rows.size, dtype=np.int64)
    admitted = np.full(rows.size, -1, dtype=np.int8)
    order = np.argsort(rows["decision_ts"], kind="stable")
    alive: list[tuple[int, float]] = []  # (end_ts, notional)
    budget = params.capital * params.leverage
    for i in order:
        status = int(rows["status"][i])
        if status in (STATUS["stale"], STATUS["no_inside"], STATUS["skipped_size"]):
            continue
        d = int(rows["decision_ts"][i])
        life = int(own_ttl[i]) if own_ttl[i] > 0 else default_ttl_us
        alive = [item for item in alive if item[0] > d]
        notional = spec.notional(float(rows["price"][i]), float(rows["size"][i]))
        used = math.fsum(item[1] for item in alive)
        if len(alive) >= params.max_live or used + notional > budget + 1e-9:
            admitted[i] = 0
            continue
        admitted[i] = 1
        if status == STATUS["crossed"]:
            end = int(rows["activation_ts"][i])
        elif status == STATUS["unfilled"]:
            end = d + life
        else:
            end = int(rows["exit_ts"][i]) if rows["exit_ts"][i] >= 0 else d + life + int(round(params.exit_max_s * tape.SECOND_US))
        alive.append((end, notional))
    return admitted


# ----------------------------------------------------------------------------- summaries


def block_bootstrap_lower_bound(values: np.ndarray, moments_us: np.ndarray, quantile: float = 0.10, resamples: int = RESAMPLES, block_s: int = BLOCK_S, seed: int = SEED) -> float | None:
    """Lower quantile of the resampled mean over whole time blocks (dependence-aware)."""
    values = np.asarray(values, dtype=np.float64)
    keep = np.isfinite(values)
    values, moments_us = values[keep], np.asarray(moments_us, dtype=np.int64)[keep]
    if values.size == 0:
        return None
    blocks = moments_us // (block_s * tape.SECOND_US)
    unique, inverse = np.unique(blocks, return_inverse=True)
    if unique.size == 1:
        return fsum_mean(values)
    sums = np.bincount(inverse, weights=values, minlength=unique.size)
    counts = np.bincount(inverse, minlength=unique.size).astype(np.float64)
    rng = np.random.default_rng(seed)
    draws = rng.integers(0, unique.size, size=(resamples, unique.size))
    means = np.empty(resamples)
    for r in range(resamples):
        picked = np.bincount(draws[r], minlength=unique.size).astype(np.float64)
        means[r] = float(picked @ sums) / float(picked @ counts)
    return float(np.quantile(means, quantile))


def _finite(value) -> float | None:
    value = float(value)
    return value if math.isfinite(value) else None


def summarize(rows: np.ndarray, params: SimParams, label: str = "unconstrained") -> dict:
    status = rows["status"]
    eligible = status >= STATUS["crossed"]
    filled_mask = (status == STATUS["filled"]) | (status == STATUS["partial"])
    filled = rows[filled_mask]
    out: dict = {"label": label, "orders": int(rows.size), "by_status": {name: int(np.sum(status == code)) for name, code in STATUS.items()},
                 "eligible": int(eligible.sum()), "fills": int(filled.size), "fill_rate": (float(filled.size) / float(eligible.sum())) if eligible.any() else None,
                 "crossed_rate": (float(np.sum(status == STATUS["crossed"])) / float(eligible.sum())) if eligible.any() else None,
                 "parameters": params.to_dict()}
    if filled.size == 0:
        return out
    mo = {"m_0": {"mean": _finite(fsum_mean(filled["m_0"])), "n": int(np.isfinite(filled["m_0"]).sum())}}
    for h in HORIZONS_S:
        values = filled[f"m_{h}s"]
        mo[f"m_{h}s"] = {"mean": _finite(fsum_mean(values)), "median": _finite(np.nanmedian(values)) if np.isfinite(values).any() else None,
                         "lb90": block_bootstrap_lower_bound(values, filled["fill_ts"]), "n": int(np.isfinite(values).sum())}
    out["markouts_bp"] = mo
    with_exit = filled[filled["exit_kind"] > 0]
    out["round_trip"] = {"n": int(with_exit.size)}
    if with_exit.size:
        out["round_trip"].update({
            "rt_bp_mean": _finite(fsum_mean(with_exit["rt_bp"])), "fee_bp_mean": _finite(fsum_mean(with_exit["fee_bp"])), "funding_bp_mean": _finite(fsum_mean(with_exit["funding_bp"])),
            "net_bp_mean": _finite(fsum_mean(with_exit["net_bp"])), "net_bp_lb90": block_bootstrap_lower_bound(with_exit["net_bp"], with_exit["fill_ts"]),
            "net_positive_share": float(np.mean(with_exit["net_bp"] > 0)), "settlement_crossed_share": float(np.mean(with_exit["settlements"] > 0)),
            "exit_kind": {name: int(np.sum(with_exit["exit_kind"] == code)) for name, code in EXIT_KIND.items() if code},
            "exit_truncated": int(with_exit["exit_truncated"].sum()), "net_bp_fees_x1_5_mean": _finite(fsum_mean(with_exit["net_bp"] - 0.5 * with_exit["fee_bp"])),
        })
    hours = (rows["decision_ts"] // tape.SECOND_US % tape.DAY_S) // 3600
    by_hour = {}
    for hour in range(24):
        sel = eligible & (hours == hour)
        if not sel.any():
            continue
        fills_h = rows[sel & filled_mask]
        by_hour[f"{hour:02d}"] = {"eligible": int(sel.sum()), "fills": int(fills_h.size), "m_60s_mean": _finite(fsum_mean(fills_h["m_60s"])) if fills_h.size else None}
    out["by_hour_utc"] = by_hour
    by_spread = {}
    for lo, hi in SPREAD_TRANCHES_BP:
        sel = eligible & (rows["half_spread_bp"] >= lo) & (rows["half_spread_bp"] < hi)
        if not sel.any():
            continue
        fills_s = rows[sel & filled_mask]
        by_spread[f"[{lo:g},{hi:g})"] = {"eligible": int(sel.sum()), "fills": int(fills_s.size), "m_60s_mean": _finite(fsum_mean(fills_s["m_60s"])) if fills_s.size else None,
                                        "net_bp_mean": _finite(fsum_mean(fills_s["net_bp"])) if fills_s.size else None}
    out["by_half_spread_bp"] = by_spread
    return out


def summarize_both(rows: np.ndarray, spec: InstrumentSpec, params: SimParams) -> dict:
    """The unconstrained reading and the portfolio reading (admitted orders only)."""
    admitted = portfolio_admit(rows, spec, params)
    rows["admitted"] = admitted
    portfolio = summarize(rows[admitted == 1], params, label=f"portfolio {params.capital:g} USDT, max_live {params.max_live}")
    portfolio["refused_capacity"] = int(np.sum(admitted == 0))
    return {"unconstrained": summarize(rows, params), "portfolio": portfolio}
