"""Result types of one variant run: closed trades, refusals and the marked-to-market equity curve.

These types are private to the validation package (they never cross into scoring, adaptive or integration code),
but they reuse the contracts' ``Contract`` base, so they have the same canonical JSON wire form and fingerprints.

Accounting identities enforced at construction (Decimal, exact):
- a trade's ``net_pnl`` = ``gross_pnl`` - ``fees`` - ``spread_cost`` - ``slippage`` - ``funding`` (funding positive
  when paid, negative when received);
- a run's candidate book is closed: every candidate offered to the variant ends as exactly one trade or exactly one
  refusal, never both, never neither;
- a run's final equity = initial equity + the sum of the trades' net PnL (every position is closed by ``end_ms``;
  the simulator closes what is still open at the end of data with ``ExitReason.END_OF_DATA``);
- every trade and refusal carries the run's variant and portfolio id (portfolios are independent);
- a stop is never widened (``final_stop_price`` is not further from entry than ``initial_stop_price``).

``initial_risk`` is the R unit in quote currency: the nominal loss at the initial stop for the initial quantity.
``gap_excess`` is the part of the realised loss beyond that nominal risk caused by gaps or adverse fills; it is
measured, never hidden.
"""
from __future__ import annotations

import enum
from dataclasses import dataclass
from decimal import Decimal
from typing import ClassVar

from ..contracts import (
    ZERO, Contract, ContractError, Side, Variant, check_id, check_reasons, check_sha, stop_is_protective,
    stop_not_wider,
)


class ExitReason(str, enum.Enum):
    STOP = 'stop'
    TARGET = 'target'
    TIME_EXPIRY = 'time_expiry'          # max holding reached (end of validity of the candidate)
    ADAPTIVE_CLOSE = 'adaptive_close'    # FULL_CLOSE action of the management policy
    KILL_SWITCH = 'kill_switch'
    END_OF_DATA = 'end_of_data'


class RefusalStage(str, enum.Enum):
    SCORE = 'score'                # scorer abstained (NO_TRADE, invalid input, model invalid): B1/B3 only
    RISK = 'risk'                  # risk gate REJECT (per-trade, total budget, leverage, kill switch)
    EXECUTION = 'execution'        # not filled, below minimum size, liquidity
    EXPIRED = 'expired'            # still waiting when its validity ended (e.g. no capacity before expiry)


class CostScenario(str, enum.Enum):
    BASE = 'base'
    PESSIMISTIC = 'pessimistic'


def _non_negative_ms(value: int, code: str) -> None:
    if isinstance(value, bool) or not isinstance(value, int) or value < 0:
        raise ContractError(code)


@dataclass(frozen=True)
class TradeRecord(Contract):
    """One closed simulated trade of one variant portfolio. Amounts in the portfolio quote currency."""

    SCHEMA: ClassVar[str] = 'h3.validation.trade_record/1'
    candidate_id: str
    variant: Variant
    portfolio_id: str
    inst_id: str
    side: Side
    decision_ms: int
    entry_ms: int
    exit_ms: int
    quantity: Decimal
    contract_value: Decimal
    entry_price: Decimal
    exit_price: Decimal
    initial_stop_price: Decimal
    final_stop_price: Decimal
    gross_pnl: Decimal
    fees: Decimal
    spread_cost: Decimal
    slippage: Decimal
    funding: Decimal
    funding_events: int
    net_pnl: Decimal
    initial_risk: Decimal
    gap_excess: Decimal
    exit_reason: ExitReason
    partial_exits: int
    actions_applied: int

    def _validate(self) -> None:
        for value in (self.candidate_id, self.portfolio_id, self.inst_id):
            check_id(value)
        for value in (self.decision_ms, self.entry_ms, self.exit_ms):
            _non_negative_ms(value, 'validation_trade_time_invalid')
        if not self.decision_ms <= self.entry_ms <= self.exit_ms:
            raise ContractError('validation_trade_time_invalid')
        if min(self.quantity, self.contract_value, self.entry_price, self.exit_price, self.initial_stop_price,
               self.final_stop_price, self.initial_risk) <= 0:
            raise ContractError('validation_trade_amount_invalid')
        if min(self.fees, self.spread_cost, self.slippage, self.gap_excess) < 0:
            raise ContractError('validation_trade_cost_negative')
        for count in (self.funding_events, self.partial_exits, self.actions_applied):
            if isinstance(count, bool) or count < 0:
                raise ContractError('validation_trade_count_invalid')
        if not stop_is_protective(self.side, self.entry_price, self.initial_stop_price):
            raise ContractError('validation_trade_stop_not_protective')
        if not stop_not_wider(self.side, self.initial_stop_price, self.final_stop_price):
            raise ContractError('validation_trade_stop_widened')
        expected = self.gross_pnl - self.fees - self.spread_cost - self.slippage - self.funding
        if self.net_pnl != expected:
            raise ContractError('validation_trade_net_identity_broken')

    @property
    def r_multiple(self) -> Decimal:
        return self.net_pnl / self.initial_risk

    @property
    def execution_cost(self) -> Decimal:
        return self.fees + self.spread_cost + self.slippage

    @property
    def holding_ms(self) -> int:
        return self.exit_ms - self.entry_ms

    @property
    def notional_at_entry(self) -> Decimal:
        return self.quantity * self.contract_value * self.entry_price


@dataclass(frozen=True)
class RefusalRecord(Contract):
    """A candidate offered to a variant and not traded, with the stage and stable reason codes."""

    SCHEMA: ClassVar[str] = 'h3.validation.refusal_record/1'
    candidate_id: str
    variant: Variant
    portfolio_id: str
    inst_id: str
    decision_ms: int
    stage: RefusalStage
    reasons: tuple[str, ...]

    def _validate(self) -> None:
        for value in (self.candidate_id, self.portfolio_id, self.inst_id):
            check_id(value)
        _non_negative_ms(self.decision_ms, 'validation_refusal_time_invalid')
        check_reasons(self.reasons)
        if not self.reasons:
            raise ContractError('validation_refusal_needs_reason')


@dataclass(frozen=True)
class EquityPoint(Contract):
    """Portfolio equity marked to market at ``ts_ms`` (cash + open positions at the last usable price)."""

    SCHEMA: ClassVar[str] = 'h3.validation.equity_point/1'
    ts_ms: int
    equity: Decimal
    open_risk: Decimal
    open_positions: int

    def _validate(self) -> None:
        _non_negative_ms(self.ts_ms, 'validation_equity_time_invalid')
        if self.open_risk < 0 or isinstance(self.open_positions, bool) or self.open_positions < 0:
            raise ContractError('validation_equity_point_invalid')


@dataclass(frozen=True)
class VariantRun(Contract):
    """Everything one variant portfolio did over one experiment period under one cost scenario.

    ``candidate_book_fingerprint`` and ``market_fingerprint`` identify the shared inputs: two runs are comparable
    only when both match (checked by ``compare``). ``candidate_ids`` are sorted and unique.
    """

    SCHEMA: ClassVar[str] = 'h3.validation.variant_run/1'
    experiment_id: str
    variant: Variant
    portfolio_id: str
    quote_ccy: str
    cost_scenario: CostScenario
    initial_equity: Decimal
    start_ms: int
    end_ms: int
    candidate_book_fingerprint: str
    market_fingerprint: str
    candidate_ids: tuple[str, ...]
    trades: tuple[TradeRecord, ...]
    refusals: tuple[RefusalRecord, ...]
    equity_curve: tuple[EquityPoint, ...]

    def _validate(self) -> None:
        for value in (self.experiment_id, self.portfolio_id, self.quote_ccy):
            check_id(value)
        check_sha(self.candidate_book_fingerprint)
        check_sha(self.market_fingerprint)
        _non_negative_ms(self.start_ms, 'validation_run_time_invalid')
        _non_negative_ms(self.end_ms, 'validation_run_time_invalid')
        if self.end_ms <= self.start_ms or self.initial_equity <= 0:
            raise ContractError('validation_run_time_invalid')
        if list(self.candidate_ids) != sorted(set(self.candidate_ids)):
            raise ContractError('validation_run_candidates_not_sorted_unique')
        for record in self.trades + self.refusals:  # type: ignore[operator]
            if record.variant is not self.variant or record.portfolio_id != self.portfolio_id:
                raise ContractError('validation_run_record_foreign_portfolio')
        traded = [t.candidate_id for t in self.trades]
        refused = [r.candidate_id for r in self.refusals]
        if len(set(traded)) != len(traded) or len(set(refused)) != len(refused):
            raise ContractError('validation_run_candidate_outcome_duplicate')
        if set(traded) & set(refused):
            raise ContractError('validation_run_candidate_traded_and_refused')
        if set(traded) | set(refused) != set(self.candidate_ids):
            raise ContractError('validation_run_candidate_book_not_closed')
        for trade in self.trades:
            if trade.entry_ms < self.start_ms or trade.exit_ms > self.end_ms:
                raise ContractError('validation_run_trade_outside_period')
        curve = self.equity_curve
        if not curve or curve[0].ts_ms != self.start_ms or curve[-1].ts_ms != self.end_ms:
            raise ContractError('validation_run_equity_curve_bounds')
        if any(b.ts_ms <= a.ts_ms for a, b in zip(curve, curve[1:])):
            raise ContractError('validation_run_equity_curve_not_increasing')
        if curve[0].equity != self.initial_equity:
            raise ContractError('validation_run_equity_start_mismatch')
        if curve[-1].equity != self.initial_equity + sum((t.net_pnl for t in self.trades), ZERO):
            raise ContractError('validation_run_equity_end_mismatch')
        if curve[-1].open_positions != 0 or curve[-1].open_risk != 0:
            raise ContractError('validation_run_positions_open_at_end')

    @property
    def net_pnl(self) -> Decimal:
        return sum((t.net_pnl for t in self.trades), ZERO)
