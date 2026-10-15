"""Cost, risk and position arithmetic shared by the H3 risk gate and the H3 simulator.

One module so that the gate sizes positions with exactly the costs the simulator charges. The conventions are those
of TradingCore's canonical engines (``CanonicalRiskEngine``, ``CanonicalOrderPlanValidator``, ``NetRCostMath``) and of
the research portfolio simulator (``app/backtesting/research/portfolio_simulator.py``):

- quantities are contracts; ``units = quantity x contract_value``; ``notional = units x price``;
- every cost line is ``units x price x rate`` and is booked separately (fee, spread, slippage). Costs are never folded
  into the fill price, so gross PnL is always computed on raw prices and nothing is counted twice;
- the nominal loss at the stop (the R unit) is cost-inclusive, as ``totalStopLoss`` in PHP: gross stop loss + entry
  fee + stop exit fee + entry and stop spread + entry and stop slippage + an adverse funding provision;
- funding: a long pays a positive rate. Cash flow = ``-units x mark x rate`` for a long, ``+units x mark x rate`` for a
  short;
- liquidation proxy (isolated margin): long ``entry x (1 - 1/L) / (1 - mmr)``, short ``entry x (1 + 1/L) / (1 + mmr)``.
  At L = 1 a long cannot be liquidated (price 0) and a short only after a ~+99 % move.

``ExecutionAssumptions`` holds the numbers the shared ``CostModel`` does not carry (numeric half-spread, funding
provision, liquidation proxy). Every default is DRAFT, chosen a priori and labelled with its reason; none comes from
known performance. Research prototype: no market data, no exchange, no order.
"""
from __future__ import annotations

import dataclasses
from dataclasses import dataclass
from decimal import ROUND_CEILING, ROUND_FLOOR, Decimal
from typing import Any

from ..contracts import (
    D, ONE, ZERO, ContractError, CostModel, Side, canonical_json, check_id, sha256_text, wire_decimal,
)

HOUR_MS = 3_600_000
FEE_ROLES = ('maker', 'taker')


@dataclass(frozen=True)
class ExecutionAssumptions:
    """Numeric execution assumptions missing from ``CostModel`` (contract change request CR-1). DRAFT defaults.

    - ``half_spread_rate`` 0: DRAFT, synthetic; the real value must come from captured quotes, per instrument.
    - ``funding_provision_rate`` 0.0001 per interval: DRAFT, the conventional 0.01 %/8 h interest component of
      perpetual funding formulas, used only as an adverse provision inside the nominal risk (never charged).
    - ``funding_interval_ms`` 8 h: DRAFT, the funding cadence of OKX swaps and X-Perps (H1-XPERP-TECHNICAL-GAP.md).
    - fee roles: market entry, stop-market and market closes are taker; a resting target is maker.
    - ``target_fill`` 'trade_through': a resting target fills only when the bar trades strictly beyond it. The
      research portfolio simulator fills on touch; 'touch' is kept for cross-tests only. Trade-through is the
      conservative choice and avoids favouring policies that move targets closer (B2/B3).
    - ``pessimistic``: when true, every cost component is multiplied by ``CostModel.pessimistic_multiplier``: fees,
      spread, slippage, the adverse funding provision (here) and adverse funding cash flows (in the simulator), as the
      pre-registration and the factorial harness state. Until review R1-F10 fees were not scaled (D's original rule:
      fees are contractual); the stricter reading was adopted so that the pessimistic guard is the one
      pre-registered.
    - ``mmr_proxy_rate`` 0.005 and ``liquidation_fee_rate`` 0.001: DRAFT synthetic proxies, the same orders of
      magnitude as the research portfolio simulator's fixtures; at 1x they only matter for a short after a ~+99 % move.
    - ``entry_collar_rate`` 0.001: DRAFT. The entry is an IOC limit at reference x (1 +/- collar) on the tick grid;
      the gate sizes the position as if filled at the collar, so any fill inside the collar respects 0.5 %, and a
      fill beyond it is refused. 10 bp is the order of one market leg's fee + slippage (0.05 % + 0.05 %), chosen
      before any data.
    """

    half_spread_rate: Decimal = D('0')
    funding_provision_rate: Decimal = D('0.0001')
    funding_interval_ms: int = 8 * HOUR_MS
    entry_role: str = 'taker'
    stop_role: str = 'taker'
    target_role: str = 'maker'
    close_role: str = 'taker'
    target_fill: str = 'trade_through'
    pessimistic: bool = False
    mmr_proxy_rate: Decimal = D('0.005')
    liquidation_fee_rate: Decimal = D('0.001')
    entry_collar_rate: Decimal = D('0.001')

    def __post_init__(self) -> None:
        for name in ('half_spread_rate', 'funding_provision_rate', 'mmr_proxy_rate', 'liquidation_fee_rate',
                     'entry_collar_rate'):
            value = getattr(self, name)
            if not isinstance(value, Decimal) or not value.is_finite() or not ZERO <= value < ONE:
                raise ContractError('h3_execution_assumptions_invalid')
        if isinstance(self.funding_interval_ms, bool) or not isinstance(self.funding_interval_ms, int) \
                or self.funding_interval_ms <= 0:
            raise ContractError('h3_execution_assumptions_invalid')
        if any(role not in FEE_ROLES for role in (self.entry_role, self.stop_role, self.target_role, self.close_role)):
            raise ContractError('h3_execution_assumptions_invalid')
        if self.target_fill not in ('trade_through', 'touch') or not isinstance(self.pessimistic, bool):
            raise ContractError('h3_execution_assumptions_invalid')

    def to_wire(self) -> dict[str, Any]:
        return {f.name: wire_decimal(v) if isinstance(v := getattr(self, f.name), Decimal) else v
                for f in dataclasses.fields(self)}


@dataclass(frozen=True)
class LegRates:
    fee: Decimal
    spread: Decimal
    slippage: Decimal

    @property
    def total(self) -> Decimal:
        return self.fee + self.spread + self.slippage


@dataclass(frozen=True)
class CostRates:
    """Effective per-leg rates. A resting target pays no spread and no slippage (it fills at its limit price)."""

    entry: LegRates
    stop: LegRates
    target: LegRates
    close: LegRates
    funding_provision_rate: Decimal
    funding_interval_ms: int


def cost_rates(cost_model: CostModel, assumptions: ExecutionAssumptions) -> CostRates:
    scale = cost_model.pessimistic_multiplier if assumptions.pessimistic else ONE
    spread = assumptions.half_spread_rate * scale
    slippage = cost_model.slippage_rate * scale

    def fee(role: str) -> Decimal:
        return (cost_model.maker_fee_rate if role == 'maker' else cost_model.taker_fee_rate) * scale

    def market(role: str) -> LegRates:
        return LegRates(fee=fee(role), spread=spread, slippage=slippage)

    return CostRates(entry=market(assumptions.entry_role), stop=market(assumptions.stop_role),
                     target=LegRates(fee=fee(assumptions.target_role), spread=ZERO, slippage=ZERO),
                     close=market(assumptions.close_role),
                     funding_provision_rate=assumptions.funding_provision_rate * scale,
                     funding_interval_ms=assumptions.funding_interval_ms)


def provision_intervals(max_holding_ms: int, interval_ms: int) -> int:
    """Funding settlements a position may cross during ``max_holding_ms`` (ceil, at least one)."""
    return max(1, -(-max_holding_ms // interval_ms))


def direction(side: Side) -> Decimal:
    return ONE if side is Side.LONG else -ONE


def floor_to_step(value: Decimal, step: Decimal) -> Decimal:
    """Largest multiple of ``step`` <= ``value`` (0 for a non-positive value)."""
    if value <= 0:
        return ZERO
    return (value / step).to_integral_value(rounding=ROUND_FLOOR) * step


def on_grid(value: Decimal, step: Decimal) -> bool:
    return value % step == 0


def round_stop_away(side: Side, stop: Decimal, tick: Decimal) -> Decimal:
    """Round a stop to the tick grid away from the price (a long's stop down, a short's up): never tighter."""
    steps = stop / tick
    rounded = steps.to_integral_value(rounding=ROUND_FLOOR if side is Side.LONG else ROUND_CEILING)
    return rounded * tick


def stop_loss_components(side: Side, entry: Decimal, stop: Decimal, units: Decimal, rates: CostRates,
                         intervals: int) -> dict[str, Decimal]:
    """Cost-inclusive loss if the position is closed at ``stop`` (names follow CanonicalRiskEngine components).

    ``gross`` is signed: negative once the stop is beyond the entry on the profit side (locked-in gain).
    """
    gross = (entry - stop) * units * direction(side)
    parts = {
        'gross': gross,
        'entry_fee': entry * units * rates.entry.fee,
        'stop_exit_fee': stop * units * rates.stop.fee,
        'entry_spread': entry * units * rates.entry.spread,
        'stop_spread': stop * units * rates.stop.spread,
        'entry_slippage': entry * units * rates.entry.slippage,
        'stop_slippage': stop * units * rates.stop.slippage,
        'funding_provision': entry * units * rates.funding_provision_rate * intervals,
    }
    parts['total'] = sum(parts.values(), ZERO)
    return parts


def position_risk(side: Side, entry: Decimal, stop: Decimal, units: Decimal, rates: CostRates,
                  intervals: int) -> Decimal:
    """Nominal cost-inclusive loss at the stop, floored at 0 (a stop locked in profit carries no risk)."""
    if units <= 0:
        return ZERO
    return max(ZERO, stop_loss_components(side, entry, stop, units, rates, intervals)['total'])


def leg_costs(units: Decimal, price: Decimal, rates: LegRates) -> dict[str, Decimal]:
    """Cost lines of one execution leg, positive amounts (paid)."""
    notional = units * price
    return {'fee': notional * rates.fee, 'spread': notional * rates.spread, 'slippage': notional * rates.slippage}


def gross_pnl(side: Side, entry: Decimal, exit_price: Decimal, units: Decimal) -> Decimal:
    return (exit_price - entry) * units * direction(side)


def funding_cashflow(side: Side, units: Decimal, mark: Decimal, rate: Decimal) -> Decimal:
    """Signed cash flow of one funding settlement: a long pays a positive rate, a short receives it."""
    return -units * mark * rate * direction(side)


def liquidation_price(side: Side, entry: Decimal, leverage: Decimal, mmr: Decimal) -> Decimal:
    """Isolated-margin liquidation proxy (portfolio_simulator convention). 0 for a long at 1x: unreachable."""
    if leverage < ONE:
        raise ContractError('h3_leverage_invalid')
    if side is Side.LONG:
        return entry * (ONE - ONE / leverage) / (ONE - mmr)
    return entry * (ONE + ONE / leverage) / (ONE + mmr)


def entry_collar_price(side: Side, reference: Decimal, rate: Decimal, tick: Decimal) -> Decimal:
    """Limit price of the IOC entry: reference moved against the trader by ``rate``, rounded on the tick grid back
    towards the reference (never beyond the collar rate). Equals the reference when the rate is 0."""
    return round_stop_away(side, reference * (ONE + rate * direction(side)), tick)


def config_fingerprint(document: Any) -> str:
    return sha256_text(canonical_json(document))


def derived_id(prefix: str, *parts: Any) -> str:
    """Deterministic identifier ``<prefix>-<32 hex>`` from canonical JSON parts."""
    return check_id(prefix + '-' + sha256_text(canonical_json(list(parts)))[7:39])
