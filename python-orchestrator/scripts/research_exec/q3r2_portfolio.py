"""scalp-q3-r2 portfolio (PREREGISTRATION-scalp-q3-r2.md §5, §6): 500 USDT, chronological, all pairs.

Every decision second of every pair enters one calendar. At a decision the items that ended
(orders expired or cancelled, positions closed) are released first, their PnL credited; then
the order is sized from the realised equity and the stop distance and checked against the
global limits (2 active items, one per pair, 0.5 % nominal risk per trade, 1 % in total, 80 %
gross exposure, 1x margin, instrument minimums). A refused decision does not block the pair:
its next decision second after the next release is tried (nothing that a refusal depends on
can change before then). An accepted order follows the outcome table of the engine; a fill is
capped by the quantity that traded through the order price (partial fills; a remainder below
the minimum is treated as unfilled). Ties at one decision moment go by symbol, alphabetically.
"""

from __future__ import annotations

import heapq
import math
from dataclasses import dataclass, field

import numpy as np

from .q3r2_engine import CROSSED, DEAD, FILLED, PASSIVE, STOP_BP, UNFILLED
from .tick import SECOND_US


@dataclass(frozen=True)
class Costs:
    maker_bp: float
    taker_bp: float
    slip_bp: float  # added to every taker execution (fallback, stop)


SCENARIOS = {"base": Costs(2.0, 5.0, 0.0), "pessimistic": Costs(3.0, 7.5, 3.0)}


@dataclass(frozen=True)
class Book:
    capital: float = 500.0
    risk_pct: float = 0.005
    total_risk_pct: float = 0.01
    max_items: int = 2
    max_gross: float = 0.80
    leverage: float = 1.0
    stop_bp: float = STOP_BP
    buffer_bp: float = 20.0
    latency_us: int = 250_000
    ttl_us: int = 30_000_000
    cancel_block_s: int = 5

    def risk_unit(self, costs: Costs) -> float:
        """d (§5): loss per unit of notional at the stop, with the scenario's fees, slippage and a 20 bp overshoot margin."""
        return (self.stop_bp + costs.maker_bp + costs.taker_bp + costs.slip_bp + self.buffer_bp) / 1e4


@dataclass
class Stream:
    """One pair in one window: its outcome table (full length) and its sorted decision seconds."""

    symbol: str
    start_s: int
    n: int
    step: float
    min_qty: float
    min_notional: float
    cols: dict
    signal: np.ndarray
    series_id: int = -1


@dataclass
class Result:
    equity: float
    pnl: float = 0.0
    trades: int = 0
    orders: int = 0
    refusals: dict = field(default_factory=lambda: {"slots": 0, "min_notional": 0, "exposure": 0, "risk": 0, "capital": 0})
    partial: int = 0
    partial_unfillable: int = 0
    daily: dict = field(default_factory=dict)  # UTC day → realised PnL
    records: list = field(default_factory=list)
    items: list = field(default_factory=list)


def _qty(target_notional: float, price: float, step: float) -> float:
    return math.floor(target_notional / price / step + 1e-9) * step


def run_window(streams: list[Stream], variant: str, costs: Costs, book: Book, equity: float, record: bool = False) -> Result:
    """Simulate one window (pairs trading at the same time) from `equity`; returns the window's result and final equity."""
    res = Result(equity=equity)
    d = book.risk_unit(costs)
    exit_prefix = "z" if variant == "Z3" else "s"
    ttl_s = book.ttl_us // SECOND_US
    order = sorted(range(len(streams)), key=lambda i: streams[i].symbol)
    rank = {i: r for r, i in enumerate(order)}
    heap: list = []

    def push(i: int, from_s: int) -> None:
        st = streams[i]
        pos = int(np.searchsorted(st.signal, from_s, side="left"))
        if pos < st.signal.size and int(st.signal[pos]) < st.n:
            s = int(st.signal[pos])
            heapq.heappush(heap, ((st.start_s + s + 1) * SECOND_US, rank[i], i, s))

    for i in range(len(streams)):
        push(i, 0)
    active: list = []  # (end_us, seq, notional, risk, pnl, day)
    seq = 0
    cash = equity
    gross = risk_tot = 0.0
    while heap:
        t, _, i, s = heapq.heappop(heap)
        while active and active[0][0] <= t:
            _, _, notional, risk, pnl, day = heapq.heappop(active)
            cash += pnl
            gross -= notional
            risk_tot -= risk
            if pnl:
                res.daily[day] = res.daily.get(day, 0.0) + pnl
        st = streams[i]
        cols = st.cols
        status = int(cols["status"][s])
        price = float(cols["price"][s])
        qty = _qty(book.risk_pct * cash / d, price, st.step) if cash > 0 else 0.0
        notional = qty * price
        reason = None
        if len(active) >= book.max_items:
            reason = "slots"
        elif qty < st.min_qty - 1e-12 or notional < st.min_notional:
            reason = "min_notional"
        elif gross + notional > book.max_gross * cash + 1e-9:
            reason = "exposure"
        elif risk_tot + notional * d > book.total_risk_pct * cash + 1e-9:
            reason = "risk"
        elif gross + notional > book.leverage * cash + 1e-9:
            reason = "capital"
        if reason is not None:
            res.refusals[reason] += 1
            if active:
                tmin = min(item[0] for item in active)
                push(i, max(s + 1, -(-tmin // SECOND_US) - st.start_s - 1))
            continue
        res.orders += 1
        decision_us = t
        pnl = 0.0
        day = None
        rec = None
        if status == CROSSED:
            end = decision_us + book.latency_us
            busy = s + 1 + book.cancel_block_s
        elif status == UNFILLED:
            end = decision_us + book.ttl_us
            busy = s + 1 + ttl_s
        elif status == DEAD:
            end = (st.start_s + st.n) * SECOND_US
            busy = st.n
        else:
            thr = float(cols["thr_qty"][s])
            fq = min(qty, math.floor(thr / st.step + 1e-9) * st.step)
            if fq < st.min_qty - 1e-12 or fq * price < st.min_notional:
                res.partial_unfillable += 1
                end = decision_us + book.ttl_us
                busy = s + 1 + ttl_s
            else:
                if fq < qty - 1e-12:
                    res.partial += 1
                exit_price = float(cols[f"{exit_prefix}_exit"][s])
                exit_reason = int(cols[f"{exit_prefix}_reason"][s])
                end = int(cols[f"{exit_prefix}_exit_ts"][s])
                busy = int(cols[f"{exit_prefix}_busy"][s])
                taker = exit_reason != PASSIVE
                fee_in = fq * price * costs.maker_bp / 1e4
                fee_out = fq * exit_price * (costs.taker_bp if taker else costs.maker_bp) / 1e4
                slip = fq * exit_price * costs.slip_bp / 1e4 if taker else 0.0
                fund_bp = float(cols[f"{exit_prefix}_fund_bp"][s])
                funding = fq * price * fund_bp / 1e4
                pnl = fq * (exit_price - price) - fee_in - fee_out - slip - funding
                day = end // (86400 * SECOND_US)
                res.trades += 1
                res.pnl += pnl
                if record:
                    rec = {"series_id": st.series_id, "symbol": st.symbol, "s": s, "decision_us": decision_us, "fill_ts": int(cols["fill_ts"][s]), "exit_ts": end,
                           "qty": fq, "order_qty": qty, "entry": price, "exit": exit_price, "reason": exit_reason, "pnl": pnl, "fee_in": fee_in, "fee_out": fee_out,
                           "slip": slip, "funding": funding, "fund_missing": int(cols["fund_missing"][s]), "equity_at_entry": cash, "notional": fq * price,
                           "nominal_risk": notional * d, "risk_budget": book.risk_pct * cash, "partial": fq < qty - 1e-12}
                    res.records.append(rec)
        risk = notional * d
        heapq.heappush(active, (end, seq, notional, risk, pnl, day))
        seq += 1
        gross += notional
        risk_tot += risk
        if record:
            res.items.append((decision_us, end, notional, status))
        push(i, busy)
    while active:
        _, _, notional, risk, pnl, day = heapq.heappop(active)
        cash += pnl
        if pnl:
            res.daily[day] = res.daily.get(day, 0.0) + pnl
    res.equity = cash
    return res
