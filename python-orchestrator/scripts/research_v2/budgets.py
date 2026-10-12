"""Budget study (PREREGISTRATION.md, section 7): the same trades re-sized for small accounts.

Sizing rule: risk 1 % of the current equity per trade (quantity = risk / stop risk per unit),
the notional capped so that the open notional never exceeds 2 × equity (the leverage cap),
quantity rounded down to the instrument step, trade skipped when the quantity or the notional
falls below the exchange minimums. Costs and funding are proportional to the quantity, so each
re-sized trade's net PnL is the reference net PnL scaled by the quantity ratio.
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


def _run_budget(outcomes: np.ndarray, instruments: dict[str, costs.Instrument], profile: str, budget: float) -> dict:
    equity = budget
    peak = budget
    max_drawdown = 0.0
    open_positions: list[tuple[int, float, float]] = []  # (exit_ms, notional, net)
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
        # Settle the positions closed before this decision.
        still_open = []
        for exit_ms, notional, net in open_positions:
            if exit_ms <= decision_ms:
                equity += net
                net_total += net
                peak = max(peak, equity)
                max_drawdown = max(max_drawdown, peak - equity)
            else:
                still_open.append((exit_ms, notional, net))
        open_positions = still_open
        symbol = str(row["symbol"])
        per_pair_attempts[symbol] = per_pair_attempts.get(symbol, 0) + 1
        instrument = instruments[symbol]
        risk_per_unit = float(risk_column[i]) / float(row["quantity"])
        risk_quantity = RISK_FRACTION * equity / risk_per_unit if risk_per_unit > 0 else 0.0
        entry = float(row["entry"])
        capacity = LEVERAGE_CAP * equity - sum(position[1] for position in open_positions)
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
        open_positions.append((int(row["exit_ms"]), notional, net))
        max_concurrent = max(max_concurrent, len(open_positions))
        taken += 1
        per_pair_taken[symbol] = per_pair_taken.get(symbol, 0) + 1
    for _, _, net in sorted(open_positions):
        equity += net
        net_total += net
        peak = max(peak, equity)
        max_drawdown = max(max_drawdown, peak - equity)
    attempts = int(outcomes.size)
    return {
        "budget": budget,
        "attempts": attempts,
        "taken": taken,
        "skipped": skipped,
        "tradable_share": taken / attempts if attempts else None,
        "net": net_total,
        "roi": net_total / budget,
        "final_equity": equity,
        "max_drawdown_quote": max_drawdown,
        "max_drawdown_share": max_drawdown / peak if peak > 0 else None,
        "max_concurrent": max_concurrent,
        "mean_notional": float(np.mean(notionals)) if notionals else None,
        "pairs_never_tradable": sorted(symbol for symbol in per_pair_attempts if per_pair_taken.get(symbol, 0) == 0),
        "per_pair_taken": per_pair_taken,
    }
