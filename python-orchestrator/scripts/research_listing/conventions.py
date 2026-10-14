"""H1 conventions, fixed by the protocol (§3, §8): returns, relative performance, funding sign, stop and sizing.

Pure functions, unit-tested on synthetic numbers; no market data.
"""

from __future__ import annotations

import math
from collections.abc import Sequence
from dataclasses import dataclass

# --------------------------------------------------------------------------- returns
def log_return(p0: float, p1: float) -> float:
    """r = ln(p1 / p0)."""
    if p0 <= 0 or p1 <= 0:
        raise ValueError("prices must be positive")
    return math.log(p1 / p0)


def relative_return(r_instrument: float, r_index: float) -> float:
    """Relative (market-adjusted) log return: AR = r_instrument − r_index."""
    return r_instrument - r_index


def paired_surplus(ar_event: float, ar_controls: Sequence[float]) -> float:
    """Event relative return minus the mean relative return of its matched controls (same dates)."""
    if not ar_controls:
        raise ValueError("at least one control is required")
    return ar_event - sum(ar_controls) / len(ar_controls)


def normalized_return(ar: float, sigma_daily_at_t: float, horizon_days: float) -> float:
    """Risk-normalised relative return (amendment A2): AR / (σ_T × √h), σ_T the daily volatility known at T."""
    if sigma_daily_at_t <= 0 or horizon_days <= 0:
        raise ValueError("volatility and horizon must be positive")
    return ar / (sigma_daily_at_t * math.sqrt(horizon_days))


def select_established_controls(ranking: Sequence[str], usable_at_t: set[str], count: int) -> list[str]:
    """A2 primary controls: the first ``count`` established perpetuals of the previous-month volume ranking that have a
    price and a volatility at T. No volatility tolerance; nothing after T is used."""
    return [inst for inst in ranking if inst in usable_at_t][:count]


def short_return(p_entry: float, p_exit: float) -> float:
    """Absolute return of an unhedged short, before costs: (entry − exit) / entry (a price fall is a gain)."""
    return (p_entry - p_exit) / p_entry


# --------------------------------------------------------------------------- funding
def funding_cashflow(side: str, units: float, mark: float, rate: float) -> float:
    """Cash received (+) or paid (−) at one settlement.

    The long pays the short when the rate is positive: long → −rate × units × mark, short → +rate × units × mark.
    """
    if side not in ("long", "short"):
        raise ValueError("side must be long or short")
    amount = rate * units * mark
    return amount if side == "short" else -amount


# --------------------------------------------------------------------------- stop and sizing
STOP_MULTIPLE = 3.0
STOP_FLOOR = 0.15
STOP_CAP = 0.60


def sigma24(minute_log_returns: Sequence[float]) -> float:
    """Standard deviation of the 1-minute log returns over [t0, T] scaled to one day (√1440)."""
    n = len(minute_log_returns)
    if n < 2:
        raise ValueError("not enough returns")
    mean = sum(minute_log_returns) / n
    var = sum((x - mean) ** 2 for x in minute_log_returns) / (n - 1)
    return math.sqrt(var) * math.sqrt(1440)


def stop_distance(sig24: float) -> float:
    """Short stop distance as a fraction of the entry: 3 × σ24, floored at +15 %, capped at +60 %."""
    return min(STOP_CAP, max(STOP_FLOOR, STOP_MULTIPLE * sig24))


@dataclass(frozen=True)
class Costs:
    """Fractions of notional (protocol §8). Fees and slippage are separate items; nothing is counted twice."""
    entry_fee: float = 0.0005
    entry_slippage: float = 0.0020
    stop_fee: float = 0.0005
    stop_slippage: float = 0.0050
    exit_fee: float = 0.0005
    exit_slippage: float = 0.0020
    funding_provision: float = 0.0126  # 14 days at 0.03 % per 8 h, provisioned against the short


BASELINE = Costs()
PESSIMISTIC = Costs(entry_slippage=0.0060, stop_slippage=0.0150, exit_slippage=0.0060, funding_provision=0.0420)


def loss_fraction_at_stop(distance: float, costs: Costs) -> float:
    """Planned loss per unit of notional if the stop is hit: distance + entry and stop costs + funding provision."""
    return distance + costs.entry_fee + costs.entry_slippage + costs.stop_fee + costs.stop_slippage + costs.funding_provision


@dataclass(frozen=True)
class Plan:
    contracts: float
    notional: float
    planned_loss: float
    reason: str | None  # None when executable


def size_short(equity: float, risk_fraction: float, entry: float, distance: float, contract_value: float,
               lot: float, min_contracts: float, costs: Costs, min_notional: float = 0.0, leverage: float = 1.0) -> Plan:
    """Contracts for a short whose planned loss at the stop stays within ``risk_fraction`` × equity.

    The quantity is rounded DOWN to the lot; a plan below the venue minimum (contracts or notional) is refused, never
    rounded up; the notional is capped by ``leverage`` × equity.
    """
    budget = risk_fraction * equity
    per_notional = loss_fraction_at_stop(distance, costs)
    notional_allowed = min(budget / per_notional, leverage * equity)
    unit_notional = contract_value * entry
    contracts = math.floor(notional_allowed / unit_notional / lot + 1e-12) * lot
    contracts = round(contracts, 12)
    notional = contracts * unit_notional
    planned = notional * per_notional
    if contracts <= 0 or contracts < min_contracts:
        return Plan(0.0, 0.0, 0.0, "below_minimum_size")
    if notional < min_notional:
        return Plan(0.0, 0.0, 0.0, "below_minimum_notional")
    if planned > budget + 1e-9:  # pragma: no cover - guarded by the rounding down
        return Plan(0.0, 0.0, 0.0, "risk_budget_exceeded")
    return Plan(contracts, notional, planned, None)


def max_entry_price(equity: float, risk_fraction: float, distance: float, contract_value: float, min_contracts: float,
                    costs: Costs, min_notional: float = 0.0) -> float | None:
    """Highest entry price at which the venue's minimum order still fits the risk budget (None: never feasible)."""
    notional_allowed = min(risk_fraction * equity / loss_fraction_at_stop(distance, costs), equity)
    if min_notional > notional_allowed:
        return None
    return notional_allowed / (min_contracts * contract_value)
