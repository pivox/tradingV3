"""Budget study (PREREGISTRATION.md, section 7): the same trades re-sized for small accounts.

Sizing rule: risk 1 % of the current equity per trade (quantity = risk / stop risk per unit),
the notional capped so that the open notional never exceeds 2 × equity (the leverage cap),
quantity rounded down to the instrument step, trade skipped when the quantity or the notional
falls below the exchange minimums. Costs and funding are proportional to the quantity, so each
re-sized trade's net PnL is the reference net PnL scaled by the quantity ratio.

Equity is realized equity: a position changes it only when it is settled, and positions are
settled in the order of their exits. The drawdown is therefore a realized drawdown: at every
settlement, the fall of equity relative to the highest equity reached before it, the maximum of
those relative falls being ``max_drawdown_share``. Unrealized (mark-to-market) losses of open
positions are not measured; ``max_open_risk_share`` bounds them by the sum of the open
positions' stop risks relative to equity (a gap through a stop can exceed that bound).
"""

from __future__ import annotations

import numpy as np

from . import costs

BUDGETS = (500.0, 1_000.0, 2_000.0, 5_000.0, 10_000.0, 100_000.0)
RISK_FRACTION = 0.01
LEVERAGE_CAP = 2.0


def budget_study(outcomes: np.ndarray, instruments: dict[str, costs.Instrument], profile: str = "base", budgets: tuple[float, ...] = BUDGETS) -> dict:
    """``outcomes`` sorted by decision time; returns one entry per budget."""
    results = {}
    for budget in budgets:
        results[f"{budget:g}"] = _run_budget(outcomes, instruments, profile, budget)
    return results


class DrawdownTracker:
    """Realized equity path: relative drawdown measured against the peak reached before each fall."""

    def __init__(self, start: float) -> None:
        self.equity = float(start)
        self.peak = float(start)
        self.max_drawdown_quote = 0.0
        self.max_drawdown_share = 0.0

    def settle(self, net: float) -> None:
        self.equity += net
        if self.equity > self.peak:
            self.peak = self.equity
            return
        fall = self.peak - self.equity
        self.max_drawdown_quote = max(self.max_drawdown_quote, fall)
        if self.peak > 0:
            self.max_drawdown_share = max(self.max_drawdown_share, fall / self.peak)
        else:  # equity never positive: the relative drawdown is undefined
            self.max_drawdown_share = float("nan")


def relative_drawdown(start: float, nets_in_exit_order) -> float:
    """Maximum relative fall of realized equity (``start`` plus the cumulated nets)."""
    tracker = DrawdownTracker(start)
    for net in nets_in_exit_order:
        tracker.settle(float(net))
    return tracker.max_drawdown_share


def _run_budget(outcomes: np.ndarray, instruments: dict[str, costs.Instrument], profile: str, budget: float) -> dict:
    tracker = DrawdownTracker(budget)
    open_positions: list[tuple[int, float, float, float]] = []  # (exit_ms, notional, net, risk)
    max_open_risk_share = 0.0
    taken = 0
    skipped = {"leverage": 0, "minimum": 0}
    notionals = []
    max_concurrent = 0
    per_pair_taken: dict[str, int] = {}
    per_pair_attempts: dict[str, int] = {}
    net_total = 0.0
    net_column = outcomes[f"net_{profile}"]
    risk_column = outcomes[f"risk_{profile}"]
    for i in range(outcomes.size):
        row = outcomes[i]
        decision_ms = int(row["decision_ms"])
        # Settle, in exit order, the positions closed at or before this decision.
        open_positions.sort(key=lambda position: position[0])
        while open_positions and open_positions[0][0] <= decision_ms:
            _, _, net, _ = open_positions.pop(0)
            tracker.settle(net)
            net_total += net
        equity = tracker.equity
        symbol = str(row["symbol"])
        per_pair_attempts[symbol] = per_pair_attempts.get(symbol, 0) + 1
        instrument = instruments[symbol]
        risk_per_unit = float(risk_column[i]) / float(row["quantity"])
        risk_quantity = RISK_FRACTION * equity / risk_per_unit if risk_per_unit > 0 else 0.0
        entry = float(row["entry"])
        capacity = LEVERAGE_CAP * equity - sum(position[1] for position in open_positions) if equity > 0 else 0.0
        if capacity <= 0:
            skipped["leverage"] += 1
            continue
        capped = capacity / entry < risk_quantity
        quantity = min(risk_quantity, capacity / entry)
        steps = np.floor(quantity / instrument.quantity_step + 1e-9)
        quantity = float(steps * instrument.quantity_step)
        if quantity < instrument.min_quantity or quantity * entry < instrument.min_notional:
            skipped["leverage" if capped else "minimum"] += 1
            continue
        scale = quantity / float(row["quantity"])
        net = float(net_column[i]) * scale
        notional = quantity * entry
        notionals.append(notional)
        open_positions.append((int(row["exit_ms"]), notional, net, float(risk_column[i]) * scale))
        if equity > 0:
            max_open_risk_share = max(max_open_risk_share, sum(position[3] for position in open_positions) / equity)
        max_concurrent = max(max_concurrent, len(open_positions))
        taken += 1
        per_pair_taken[symbol] = per_pair_taken.get(symbol, 0) + 1
    for _, _, net, _ in sorted(open_positions, key=lambda position: position[0]):
        tracker.settle(net)
        net_total += net
    attempts = int(outcomes.size)
    return {
        "budget": budget,
        "attempts": attempts,
        "taken": taken,
        "skipped": skipped,
        "tradable_share": taken / attempts if attempts else None,
        "net": net_total,
        "roi": net_total / budget,
        "final_equity": tracker.equity,
        "max_drawdown_quote": tracker.max_drawdown_quote,
        "max_drawdown_share": tracker.max_drawdown_share,
        "drawdown_basis": "realized equity, settlements in exit order, relative to the running peak; unrealized losses not measured",
        "max_open_risk_share": max_open_risk_share,
        "max_concurrent": max_concurrent,
        "mean_notional": float(np.mean(notionals)) if notionals else None,
        "pairs_never_tradable": sorted(symbol for symbol in per_pair_attempts if per_pair_taken.get(symbol, 0) == 0),
        "per_pair_taken": per_pair_taken,
    }
