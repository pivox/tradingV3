"""Cost profiles and trade settlement, mirroring the canonical research kernel's conventions.

The numbers reproduce the plan rows of the swing campaign (``research-plan.v1``): maker entry
fee, taker exit fees, spread and slippage charged on stop-like exits only, a funding provision
of ``funding_provision_rate × notional × intervals`` inside the stop risk, and observed funding
settled on the notional at every funding timestamp between the fill and the exit.
"""

from __future__ import annotations

import json
import math
from dataclasses import dataclass
from pathlib import Path

import numpy as np

MAKER_FEE_RATE = 0.0002
TAKER_FEE_RATE = 0.0005
FUNDING_INTERVAL_MS = 8 * 3_600_000

MARKET_LIKE_EXITS = frozenset({"stop", "trailing", "time", "indicator", "window_end"})


@dataclass(frozen=True)
class CostProfile:
    name: str
    entry_spread_rate: float
    stop_spread_rate: float
    target_spread_rate: float
    entry_slippage_rate: float
    stop_slippage_rate: float
    target_slippage_rate: float
    funding_provision_rate: float
    entry_fee_rate: float = MAKER_FEE_RATE
    exit_fee_rate: float = TAKER_FEE_RATE


def load_cost_profiles(path: Path) -> dict[str, CostProfile]:
    """Read the frozen ``cost-assumptions.json`` (``research-cost-assumptions.v1``)."""
    document = json.loads(Path(path).read_text())
    if document.get("schema_version") != "research-cost-assumptions.v1":
        raise ValueError(f"unexpected cost assumptions schema: {document.get('schema_version')!r}")
    profiles = {}
    for name, values in document["profiles"].items():
        profiles[name] = CostProfile(
            name=name,
            entry_spread_rate=float(values["entry_spread_rate"]),
            stop_spread_rate=float(values["stop_spread_rate"]),
            target_spread_rate=float(values["target_spread_rate"]),
            entry_slippage_rate=float(values["entry_slippage_rate"]),
            stop_slippage_rate=float(values["stop_slippage_rate"]),
            target_slippage_rate=float(values["target_slippage_rate"]),
            funding_provision_rate=float(values["funding_provision_rate"]),
        )
    return profiles


@dataclass(frozen=True)
class Instrument:
    symbol: str
    tick_size: float
    quantity_step: float
    min_quantity: float
    min_notional: float
    max_quantity: float
    leverage_cap: float
    contract_size: float = 1.0


def load_instruments(path: Path) -> dict[str, Instrument]:
    """Read ``instrument-assumptions.json`` (``research-instrument-assumptions.v1``)."""
    document = json.loads(Path(path).read_text())
    if document.get("schema_version") != "research-instrument-assumptions.v1":
        raise ValueError(f"unexpected instrument assumptions schema: {document.get('schema_version')!r}")
    instruments = {}
    for row in document["symbols"]:
        instruments[row["symbol"]] = Instrument(
            symbol=row["symbol"],
            tick_size=float(row["tick_size"]),
            quantity_step=float(row["quantity_step"]),
            min_quantity=float(row["min_quantity"]),
            min_notional=float(row["min_notional"]),
            max_quantity=float(row["max_quantity"]),
            leverage_cap=float(row["leverage_cap"]),
            contract_size=float(row.get("contract_size", 1.0)),
        )
    return instruments


def round_quantity(notional_quote: float, price: float, instrument: Instrument) -> float:
    """Quantity for a notional at ``price``, rounded down to the step; 0 when below the minimums."""
    if price <= 0 or notional_quote <= 0:
        return 0.0
    raw = notional_quote / (price * instrument.contract_size)
    steps = math.floor(raw / instrument.quantity_step + 1e-9)
    quantity = steps * instrument.quantity_step
    quantity = min(quantity, instrument.max_quantity)
    if quantity < instrument.min_quantity or quantity * price * instrument.contract_size < instrument.min_notional:
        return 0.0
    return float(round(quantity, 12))


def stop_risk_quote(
    side: int,
    entry_price: float,
    stop_price: float,
    quantity: float,
    funding_intervals: int,
    profile: CostProfile,
) -> float:
    """Total loss if the stop is hit: gross distance plus every cost charged on that path."""
    gross = abs(entry_price - stop_price) * quantity
    notional = entry_price * quantity
    entry_fee = profile.entry_fee_rate * notional
    stop_notional = stop_price * quantity
    stop_fee = profile.exit_fee_rate * stop_notional
    stop_spread = profile.stop_spread_rate * stop_notional
    stop_slippage = profile.stop_slippage_rate * stop_notional
    entry_spread = profile.entry_spread_rate * notional
    entry_slippage = profile.entry_slippage_rate * notional
    funding_provision = profile.funding_provision_rate * notional * funding_intervals
    return gross + entry_fee + stop_fee + stop_spread + stop_slippage + entry_spread + entry_slippage + funding_provision


@dataclass(frozen=True)
class Settlement:
    gross_quote: float
    fees_quote: float
    spread_quote: float
    slippage_quote: float
    funding_quote: float  # signed: negative when the position paid funding
    net_quote: float


def settle(
    side: int,
    entry_price: float,
    exit_price: float,
    quantity: float,
    exit_reason: str,
    profile: CostProfile,
    funding_paid_quote: float = 0.0,
) -> Settlement:
    """Net PnL of a closed trade. ``funding_paid_quote`` is the sum of rate × mark × quantity × side."""
    gross = side * (exit_price - entry_price) * quantity
    entry_notional = entry_price * quantity
    exit_notional = exit_price * quantity
    fees = profile.entry_fee_rate * entry_notional + profile.exit_fee_rate * exit_notional
    if exit_reason in MARKET_LIKE_EXITS:
        spread = profile.entry_spread_rate * entry_notional + profile.stop_spread_rate * exit_notional
        slippage = profile.entry_slippage_rate * entry_notional + profile.stop_slippage_rate * exit_notional
    elif exit_reason == "target":
        spread = profile.entry_spread_rate * entry_notional + profile.target_spread_rate * exit_notional
        slippage = profile.entry_slippage_rate * entry_notional + profile.target_slippage_rate * exit_notional
    else:
        raise ValueError(f"unknown exit reason {exit_reason!r}")
    funding = -funding_paid_quote
    net = gross - fees - spread - slippage + funding
    return Settlement(gross, fees, spread, slippage, funding, net)


def funding_paid(
    side: int,
    quantity: float,
    fill_ms: int,
    exit_ms: int,
    funding_ms: np.ndarray,
    funding_rate: np.ndarray,
    mark_price: np.ndarray,
) -> float:
    """Funding paid (positive) or received (negative) at the funding timestamps in (fill, exit].

    ``mark_price`` is aligned on ``funding_ms`` (the 1-minute close at each funding timestamp).
    A long pays a positive rate; a short receives it.
    """
    start = int(np.searchsorted(funding_ms, fill_ms, side="right"))
    stop = int(np.searchsorted(funding_ms, exit_ms, side="right"))
    if stop <= start:
        return 0.0
    rates = funding_rate[start:stop]
    marks = mark_price[start:stop]
    return float(side * quantity * np.sum(rates * marks))
