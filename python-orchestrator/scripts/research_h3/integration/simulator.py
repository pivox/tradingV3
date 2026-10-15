"""Deterministic bar replay of ONE H3 variant portfolio (B0, B1, B2 or B3), synthetic data only.

Entry point: ``run_variant(...) -> VariantRunResult`` (trades, equity curve, refusals, risk decisions, audit). The
harness of the validation framework calls it once per variant with the same bars, funding, cost model and risk gate
configuration, and with that variant's candidates, scores and management policy.

Timeline (all instruments share one bar interval; bars must be closed; an instrument may miss bars). For each bar
open ``t`` (boundary ``b = t + interval``, decision instant ``D`` = latest ``available_ms`` of the bars closing at
``b``):

A. at the open, per instrument: (1) resting protective orders first: an open through the stop fills at the open (a
   gap, worse than the stop, recorded separately), an open through the target fills at the target; (2) pending
   adaptive actions decided at a previous ``D <= t`` (a stop tightened through the open triggers at the open);
   (3) market closes: max holding reached, fail-safe for an unprotected position, kill-switch flattening; (4) pending
   entries decided at ``D <= t``: an IOC limit at the gate's collar price fills at the open when the open is inside
   the collar and the gate's re-check at that price passes; otherwise no position opens.
B. intrabar: stop before target when both are touched (pessimistic, flagged); liquidation proxy (isolated, 1x:
   unreachable for a long, a short needs ~+99 %) compared with the stop, the worse wins; a target fills only on a
   trade-through by default.
C. funding settlements with ``funding_ms`` in the bar: cash = -units x mark x rate for a long (mark = last close known
   before the settlement). An exposure that is uncertain at bar resolution (entry or exit at the same instant,
   intrabar exit) is charged when adverse and dropped when a credit (``adverse_possible_credit_certain``, as the
   research portfolio simulator).
D. marks at the close; one equity point at ``b``.
E. decisions at ``D``: kill switch; for each open position, ``ManagementPolicy.decide`` with a ``PositionState`` built
   only from bars available at ``D``, every action validated by the risk gate (admitted actions run at the next
   open); then the candidates whose decision instant is in ``(previous D, D]`` go through ``admit_entry``.

Costs are separate cash lines (fee, spread, slippage) on raw prices; the nominal risk R of a trade is the gate's
cost-inclusive risk at the actual fill. ``loss_beyond_nominal`` = max(0, -net PnL - R) and ``gap_loss`` (fills worse
than the stop) are reported per trade and in total, never clipped. The wallet is reconciled at the end.

Injected failures (``FailurePlan``): entry order rejected; entry network error with the venue truth (filled or not,
the client order id is the admission decision id, so a retry cannot double it); stop attachment failed (the position
is unprotected until a fail-safe market close at the next open); stop or target modification failed (the old one is
kept); close order rejected (the position stays protected). A modification that arrives after the stop already
filled is refused as ``position_already_closed``.

Research prototype: no order, no exchange, no market data file, no Temporal or Symfony dependency.
"""
from __future__ import annotations

import dataclasses
import enum
from collections.abc import Mapping, Sequence
from dataclasses import dataclass, field
from decimal import Decimal
from typing import Any

from ..contracts import (
    H3_LIMITS, ONE, ZERO, ActionKind, AdaptiveAction, AuditEvent, Bar, Clock, ContractError, DataStatus,
    FundingObservation, Header, InstrumentRef, LiquidityStatus, ManagementPolicy, OpportunityCandidate,
    OpportunityScore, PortfolioView, PositionState, PositionStatus, RiskDecision, RiskVerdict, Side, Variant,
    check_id, wire_decimal,
)
from .audit import AuditJournal
from .costs import (
    LegRates, derived_id, direction, funding_cashflow, gross_pnl, leg_costs, liquidation_price, round_stop_away,
)
from .risk_gate import H3RiskGate


class FailureKind(str, enum.Enum):
    ORDER_REJECTED = 'order_rejected'
    NETWORK_ERROR = 'network_error'
    PROTECTION_FAILED = 'protection_failed'
    MODIFICATION_FAILED = 'modification_failed'


_ENTRY_FAILURES = (FailureKind.ORDER_REJECTED, FailureKind.NETWORK_ERROR, FailureKind.PROTECTION_FAILED)
_ACTION_FAILURES = (FailureKind.ORDER_REJECTED, FailureKind.NETWORK_ERROR, FailureKind.MODIFICATION_FAILED)


@dataclass(frozen=True)
class InjectedFailure:
    """An entry failure names ``candidate_id``; an action failure names the position's ``candidate_id`` and the
    ``action_kind`` (the first such action of that position fails). ``filled_on_venue`` is the venue truth behind a
    NETWORK_ERROR on an entry."""

    kind: FailureKind
    candidate_id: str
    action_kind: ActionKind | None = None
    filled_on_venue: bool = False

    def __post_init__(self) -> None:
        allowed = _ENTRY_FAILURES if self.action_kind is None else _ACTION_FAILURES
        if self.kind not in allowed or self.action_kind is ActionKind.HOLD:
            raise ContractError('h3_sim_failure_invalid')


@dataclass(frozen=True)
class FailurePlan:
    failures: tuple[InjectedFailure, ...] = ()


def _wire(value: Any) -> Any:
    if isinstance(value, Decimal):
        return wire_decimal(value)
    if isinstance(value, enum.Enum):
        return value.value
    if isinstance(value, (tuple, list)):
        return [_wire(v) for v in value]
    if dataclasses.is_dataclass(value) and not isinstance(value, type):
        return {f.name: _wire(getattr(value, f.name)) for f in dataclasses.fields(value)}
    return value


@dataclass(frozen=True)
class ExitFill:
    time_ms: int
    reason: str
    price: Decimal
    quantity: Decimal
    gross_pnl: Decimal
    fee: Decimal
    spread: Decimal
    slippage: Decimal
    gap_loss: Decimal
    intrabar: bool


@dataclass(frozen=True)
class Trade:
    position_id: str
    candidate_id: str
    inst_id: str
    side: Side
    admission_decision_id: str
    fill_decision_id: str
    entry_ms: int
    entry_price: Decimal
    quantity: Decimal
    initial_stop: Decimal
    final_stop: Decimal
    initial_target: Decimal | None
    final_target: Decimal | None
    exit_ms: int
    exit_reason: str
    exits: tuple[ExitFill, ...]
    gross_pnl: Decimal
    fees: Decimal
    spread_cost: Decimal
    slippage_cost: Decimal
    funding: Decimal
    net_pnl: Decimal
    nominal_risk: Decimal
    realized_r: Decimal
    loss_beyond_nominal: Decimal
    gap_loss: Decimal
    gap_through_stop: bool
    liquidated: bool
    actions_applied: tuple[str, ...]
    flags: tuple[str, ...]

    def to_wire(self) -> dict[str, Any]:
        result: dict[str, Any] = _wire(self)
        return result


@dataclass(frozen=True)
class EquityPoint:
    time_ms: int
    equity: Decimal
    cash: Decimal
    open_risk: Decimal
    open_notional: Decimal
    open_positions: int


@dataclass(frozen=True)
class Refusal:
    time_ms: int
    stage: str
    subject_id: str
    reasons: tuple[str, ...]


@dataclass(frozen=True)
class VariantRunResult:
    experiment_id: str
    variant: Variant
    portfolio_id: str
    initial_equity: Decimal
    final_cash: Decimal
    trades: tuple[Trade, ...]
    equity_curve: tuple[EquityPoint, ...]
    refusals: tuple[Refusal, ...]
    risk_decisions: tuple[RiskDecision, ...]
    audit: AuditJournal
    kill_switch_ms: int | None
    max_open_risk_fraction: Decimal
    max_leverage: Decimal

    @property
    def net_pnl(self) -> Decimal:
        return sum((t.net_pnl for t in self.trades), ZERO)

    @property
    def gap_loss_total(self) -> Decimal:
        return sum((t.gap_loss for t in self.trades), ZERO)

    @property
    def loss_beyond_nominal_total(self) -> Decimal:
        return sum((t.loss_beyond_nominal for t in self.trades), ZERO)

    def summary(self) -> dict[str, Any]:
        """JSON-ready summary (Decimals as strings). Synthetic runs are never evidence."""
        trades = self.trades
        return {
            'schema': 'h3.integration.variant_summary/1', 'experiment_id': self.experiment_id,
            'variant': self.variant.value, 'portfolio_id': self.portfolio_id,
            'initial_equity': wire_decimal(self.initial_equity), 'final_cash': wire_decimal(self.final_cash),
            'net_pnl': wire_decimal(self.net_pnl), 'trades': len(trades),
            'fees': wire_decimal(sum((t.fees for t in trades), ZERO)),
            'spread_cost': wire_decimal(sum((t.spread_cost for t in trades), ZERO)),
            'slippage_cost': wire_decimal(sum((t.slippage_cost for t in trades), ZERO)),
            'funding': wire_decimal(sum((t.funding for t in trades), ZERO)),
            'gap_loss_total': wire_decimal(self.gap_loss_total),
            'loss_beyond_nominal_total': wire_decimal(self.loss_beyond_nominal_total),
            'trades_beyond_nominal': sum(1 for t in trades if t.loss_beyond_nominal > 0),
            'gap_exits': sum(1 for t in trades if t.gap_through_stop),
            'liquidations': sum(1 for t in trades if t.liquidated),
            'refusals': len(self.refusals), 'max_open_risk_fraction': wire_decimal(self.max_open_risk_fraction),
            'max_leverage': wire_decimal(self.max_leverage), 'kill_switch_ms': self.kill_switch_ms,
            'audit_records': len(self.audit), 'audit_head': self.audit.head_fingerprint(),
            'execution_authority': 'none',
        }


@dataclass
class _Position:
    position_id: str
    candidate: OpportunityCandidate
    admission: RiskDecision
    fill: RiskDecision
    entry_ms: int
    entry_price: Decimal
    initial_quantity: Decimal
    open_quantity: Decimal
    stop: Decimal
    target: Decimal | None
    nominal_risk: Decimal
    protected: bool
    mfe: Decimal
    mae: Decimal
    gross: Decimal = ZERO
    fees: Decimal = ZERO
    spread: Decimal = ZERO
    slippage: Decimal = ZERO
    funding: Decimal = ZERO
    exits: list[ExitFill] = field(default_factory=list)
    quantity_changes: list[tuple[int, Decimal, Decimal]] = field(default_factory=list)
    actions_applied: list[str] = field(default_factory=list)
    flags: list[str] = field(default_factory=list)
    pending_close: str | None = None
    closed_ms: int | None = None
    exit_intrabar: bool = False
    exit_reason: str | None = None
    liquidated: bool = False
    finalized: bool = False

    @property
    def inst(self) -> InstrumentRef:
        return self.candidate.header.instrument

    @property
    def side(self) -> Side:
        side = self.candidate.header.side
        assert side is not None
        return side

    def flag(self, name: str) -> None:
        if name not in self.flags:
            self.flags.append(name)


@dataclass
class _PendingEntry:
    candidate: OpportunityCandidate
    admission: RiskDecision
    decision_ms: int


@dataclass
class _PendingAction:
    action: AdaptiveAction
    decision: RiskDecision
    position_id: str
    decision_ms: int


def _bars_by_instrument(bars: Sequence[Bar]) -> tuple[dict[str, list[Bar]], int]:
    if not bars:
        raise ContractError('h3_sim_no_bars')
    intervals = {bar.interval_ms for bar in bars}
    if len(intervals) != 1:
        raise ContractError('h3_sim_interval_mismatch')
    interval = intervals.pop()
    grouped: dict[str, list[Bar]] = {}
    for bar in bars:
        if not bar.is_closed:
            raise ContractError('h3_sim_bar_not_closed')
        if bar.available_ms - bar.close_ms >= interval:  # a decision always sees the bar before the next one ends
            raise ContractError('h3_sim_latency_exceeds_interval')
        grouped.setdefault(bar.inst_id, []).append(bar)
    phase = bars[0].open_ms % interval
    for inst_id, rows in grouped.items():
        rows.sort(key=lambda b: b.open_ms)
        for previous, current in zip(rows, rows[1:]):
            if current.open_ms < previous.close_ms:
                raise ContractError('h3_sim_bars_overlap')
        if any(b.open_ms % interval != phase for b in rows):
            raise ContractError('h3_sim_bars_misaligned')
    return grouped, interval


class _VariantSimulator:
    def __init__(self, *, experiment_id: str, variant: Variant, portfolio_id: str, bars: Sequence[Bar],
                 candidates: Sequence[OpportunityCandidate], policy: ManagementPolicy, gate: H3RiskGate,
                 scores: Mapping[str, OpportunityScore] | None, funding: Sequence[FundingObservation],
                 initial_equity: Decimal, quote_ccy: str, failures: FailurePlan, kill_switch_at_ms: int | None,
                 flatten_on_kill_switch: bool, audit: AuditJournal | None) -> None:
        if initial_equity <= 0:
            raise ContractError('h3_sim_initial_equity_invalid')
        self.experiment_id, self.variant, self.portfolio_id = experiment_id, variant, check_id(portfolio_id)
        self.policy, self.gate = policy, gate
        self.rates = gate.rates
        self.assumptions = gate.config.assumptions
        self.scores = dict(scores or {})
        self.initial_equity, self.cash, self.quote_ccy = initial_equity, initial_equity, quote_ccy
        self.failures = list(failures.failures)
        self.kill_switch_at_ms, self.flatten_on_kill_switch = kill_switch_at_ms, flatten_on_kill_switch
        self.kill_switch_ms: int | None = None
        self.audit = audit if audit is not None else AuditJournal(experiment_id, variant)
        if self.audit.experiment_id != experiment_id or self.audit.variant != variant:
            raise ContractError('h3_audit_scope_mismatch')
        self.bars, self.interval = _bars_by_instrument(bars)
        self.instruments: dict[str, InstrumentRef] = {}
        for candidate in candidates:
            inst = candidate.header.instrument
            if self.instruments.setdefault(inst.inst_id, inst) != inst:
                raise ContractError('h3_sim_instrument_conflict')
        self.candidates = sorted(candidates, key=lambda c: (c.header.clock.decision_ms, c.candidate_id))
        seen: set[tuple[str, int]] = set()
        for observation in funding:
            key = (observation.inst_id, observation.funding_ms)
            if key in seen:
                raise ContractError('h3_sim_funding_duplicate')
            seen.add(key)
        self.funding = sorted(funding, key=lambda f: (f.funding_ms, f.inst_id))
        self.positions: dict[str, _Position] = {}
        self.pending_entries: list[_PendingEntry] = []
        self.pending_actions: list[_PendingAction] = []
        self.consumed_decisions: set[str] = set()
        self.marks: dict[str, Decimal] = {}
        self.last_bar: dict[str, Bar] = {}
        self.trades: list[Trade] = []
        self.curve: list[EquityPoint] = []
        self.refusals: list[Refusal] = []
        self.decisions: list[RiskDecision] = []
        self.max_risk_fraction = ZERO
        self.max_leverage = ZERO
        self._boundary = -1

    # ------------------------------------------------------------------------------------------------ views
    def _open(self) -> list[_Position]:
        return [p for _, p in sorted(self.positions.items()) if p.closed_ms is None]

    def _mark(self, p: _Position) -> Decimal:
        return self.marks.get(p.inst.inst_id, p.entry_price)

    def _risk(self, p: _Position) -> Decimal:
        """Open risk of a position; an unprotected one (stop attachment failed) counts its whole entry notional
        until the fail-safe close, so nothing new is admitted while it is open."""
        if not p.protected:
            return p.open_quantity * p.inst.contract_value * p.entry_price
        return self.gate.risk_of(p.side, p.entry_price, p.stop, p.open_quantity, p.inst.contract_value,
                                 p.candidate.max_holding_ms)

    def _view(self, clock: Clock, *, exclude: _PendingEntry | None = None) -> PortfolioView:
        open_positions = self._open()
        pending = [e for e in self.pending_entries if e is not exclude]
        unrealized = sum((gross_pnl(p.side, p.entry_price, self._mark(p), p.open_quantity * p.inst.contract_value)
                          for p in open_positions), ZERO)
        open_risk = sum((self._risk(p) for p in open_positions), ZERO) \
            + sum((e.admission.risk_amount for e in pending), ZERO)
        notional = sum((p.open_quantity * p.inst.contract_value * p.entry_price for p in open_positions), ZERO) \
            + sum((e.admission.notional_after - e.admission.notional_before for e in pending), ZERO)
        return PortfolioView(portfolio_id=self.portfolio_id, variant=self.variant, quote_ccy=self.quote_ccy,
                             clock=clock, equity=self.cash + unrealized, cash=self.cash, open_risk=open_risk,
                             open_notional=notional, open_position_ids=tuple(p.position_id for p in open_positions),
                             kill_switch=self.kill_switch_ms is not None)

    def _occupied(self, exclude: _PendingEntry | None = None) -> frozenset[str]:
        return frozenset([p.inst.inst_id for p in self._open()]
                         + [e.candidate.header.instrument.inst_id for e in self.pending_entries if e is not exclude])

    def _header(self, p: _Position, clock: Clock) -> Header:
        return dataclasses.replace(p.candidate.header, clock=clock, portfolio_id=self.portfolio_id)

    def _candidate_header(self, candidate: OpportunityCandidate) -> Header:
        return dataclasses.replace(candidate.header, portfolio_id=self.portfolio_id)

    def _refuse(self, time_ms: int, stage: str, subject_id: str, reasons: Sequence[str], header: Header) -> None:
        unique = tuple(dict.fromkeys(reasons))
        self.refusals.append(Refusal(time_ms=time_ms, stage=stage, subject_id=subject_id, reasons=unique))
        self.audit.record_private(header=header, event=AuditEvent.REJECT, kind='refusal', subject_id=subject_id,
                                  document={'time_ms': time_ms, 'stage': stage, 'subject_id': subject_id,
                                            'reasons': list(unique)}, reasons=unique)

    def _record_decision(self, decision: RiskDecision) -> None:
        self.decisions.append(decision)
        self.audit.record_contract(header=decision.header, event=AuditEvent.RISK, subject=decision,
                                   subject_id=decision.decision_id, policy=decision.policy, reasons=decision.reasons)

    def _take_failure(self, candidate_id: str, action_kind: ActionKind | None) -> InjectedFailure | None:
        for index, failure in enumerate(self.failures):
            if failure.candidate_id == candidate_id and failure.action_kind == action_kind:
                return self.failures.pop(index)
        return None

    # ------------------------------------------------------------------------------------------ cash flows
    def _exit(self, p: _Position, quantity: Decimal, price: Decimal, reason: str, time_ms: int, leg: LegRates,
              *, intrabar: bool, extra_fee_rate: Decimal = ZERO) -> None:
        units = quantity * p.inst.contract_value
        gross = gross_pnl(p.side, p.entry_price, price, units)
        costs = leg_costs(units, price, leg)
        fee = costs['fee'] + units * price * extra_fee_rate
        beyond = ZERO
        if reason in ('stop', 'stop_gap', 'liquidation_proxy'):
            beyond = max(ZERO, (p.stop - price) * direction(p.side)) * units
        p.gross += gross
        p.fees += fee
        p.spread += costs['spread']
        p.slippage += costs['slippage']
        self.cash += gross - fee - costs['spread'] - costs['slippage']
        p.open_quantity -= quantity
        p.quantity_changes.append((time_ms, quantity + p.open_quantity, p.open_quantity))
        fill = ExitFill(time_ms=time_ms, reason=reason, price=price, quantity=quantity, gross_pnl=gross, fee=fee,
                        spread=costs['spread'], slippage=costs['slippage'], gap_loss=beyond, intrabar=intrabar)
        p.exits.append(fill)
        if p.open_quantity == 0:
            p.closed_ms, p.exit_intrabar, p.exit_reason = time_ms, intrabar, reason
        clock = Clock(observed_ms=time_ms, available_ms=time_ms, decision_ms=time_ms)
        self.audit.record_private(header=self._header(p, clock), event=AuditEvent.EXIT, kind='exit',
                                  subject_id=p.position_id,
                                  document={'position_id': p.position_id, 'fill': _wire(fill)},
                                  reasons=(reason,))

    def _finalize(self, p: _Position) -> None:
        net = p.gross - p.fees - p.spread - p.slippage + p.funding
        assert p.closed_ms is not None and p.exit_reason is not None and not p.finalized
        p.finalized = True
        gap = sum((e.gap_loss for e in p.exits), ZERO)
        trade = Trade(position_id=p.position_id, candidate_id=p.candidate.candidate_id, inst_id=p.inst.inst_id,
                      side=p.side, admission_decision_id=p.admission.decision_id, fill_decision_id=p.fill.decision_id,
                      entry_ms=p.entry_ms, entry_price=p.entry_price, quantity=p.initial_quantity,
                      initial_stop=p.candidate.initial_stop_price, final_stop=p.stop,
                      initial_target=p.candidate.initial_target_price, final_target=p.target, exit_ms=p.closed_ms,
                      exit_reason=p.exit_reason, exits=tuple(p.exits), gross_pnl=p.gross, fees=p.fees,
                      spread_cost=p.spread, slippage_cost=p.slippage, funding=p.funding, net_pnl=net,
                      nominal_risk=p.nominal_risk, realized_r=net / p.nominal_risk,
                      loss_beyond_nominal=max(ZERO, -net - p.nominal_risk), gap_loss=gap,
                      gap_through_stop=gap > 0, liquidated=p.liquidated, actions_applied=tuple(p.actions_applied),
                      flags=tuple(p.flags))
        self.trades.append(trade)

    # -------------------------------------------------------------------------------------------- the open
    def _protective_at_open(self, p: _Position, bar: Bar) -> None:
        if p.closed_ms is not None or not p.protected:
            return
        side, sign = p.side, direction(p.side)
        liquidation = self._liquidation(p)
        stop_hit = (bar.open - p.stop) * sign <= 0
        liq_hit = liquidation > 0 and (bar.open - liquidation) * sign <= 0
        if liq_hit:
            p.liquidated = True
            p.flag('liquidation_proxy_reached')
            self._exit(p, p.open_quantity, bar.open, 'liquidation_proxy', bar.open_ms, self.rates.stop,
                       intrabar=False, extra_fee_rate=self.assumptions.liquidation_fee_rate)
        elif stop_hit:
            self._exit(p, p.open_quantity, bar.open, 'stop_gap' if bar.open != p.stop else 'stop', bar.open_ms,
                       self.rates.stop, intrabar=False)
        elif p.target is not None and self._target_crossed(p, bar.open, bar.open):
            self._exit(p, p.open_quantity, p.target, 'target', bar.open_ms, self.rates.target, intrabar=False)

    def _target_crossed(self, p: _Position, high: Decimal, low: Decimal) -> bool:
        assert p.target is not None
        touch = self.assumptions.target_fill == 'touch'
        if p.side is Side.LONG:
            return high >= p.target if touch else high > p.target
        return low <= p.target if touch else low < p.target

    def _apply_action(self, pending: _PendingAction, bar: Bar) -> None:
        action, p = pending.action, self.positions[pending.position_id]
        clock = Clock(observed_ms=bar.open_ms, available_ms=bar.open_ms, decision_ms=bar.open_ms)
        header = self._header(p, clock)
        if p.closed_ms is not None:
            self._refuse(bar.open_ms, 'action_execution', action.action_id, ['position_already_closed'], header)
            return
        if action.action_id in p.actions_applied:
            self._refuse(bar.open_ms, 'action_execution', action.action_id, ['action_already_applied'], header)
            return
        failure = self._take_failure(p.candidate.candidate_id, action.kind)
        if failure is not None:
            reason = {FailureKind.MODIFICATION_FAILED: 'modification_failed_previous_order_kept',
                      FailureKind.NETWORK_ERROR: 'network_error_previous_state_kept',
                      FailureKind.ORDER_REJECTED: 'close_order_rejected_position_kept'}[failure.kind]
            p.flag(reason)
            self._refuse(bar.open_ms, 'action_execution', action.action_id, [reason], header)
            return
        p.actions_applied.append(action.action_id)
        self.audit.record_private(header=header, event=AuditEvent.ACTION, kind='action_execution',
                                  subject_id=action.action_id,
                                  document={'action_id': action.action_id, 'kind': action.kind.value,
                                            'position_id': p.position_id, 'time_ms': bar.open_ms},
                                  policy=action.policy, reasons=('applied',))
        if action.kind is ActionKind.TIGHTEN_STOP and action.new_stop is not None:
            p.stop = action.new_stop
            if (bar.open - p.stop) * direction(p.side) <= 0:  # the new stop is already through the open
                self._exit(p, p.open_quantity, bar.open, 'stop_gap' if bar.open != p.stop else 'stop', bar.open_ms,
                           self.rates.stop, intrabar=False)
        elif action.kind is ActionKind.ADJUST_TARGET and action.new_target is not None:
            p.target = action.new_target
        elif action.kind is ActionKind.PARTIAL_CLOSE and action.close_quantity is not None:
            self._exit(p, action.close_quantity, bar.open, 'partial_close', bar.open_ms, self.rates.close,
                       intrabar=False)
        elif action.kind is ActionKind.FULL_CLOSE:
            self._exit(p, p.open_quantity, bar.open, 'full_close', bar.open_ms, self.rates.close, intrabar=False)

    def _market_close_at_open(self, p: _Position, bar: Bar) -> None:
        if p.closed_ms is not None:
            return
        reason = p.pending_close
        if reason is None and bar.open_ms - p.entry_ms >= p.candidate.max_holding_ms:
            reason = 'max_holding'
        if reason is not None:
            self._exit(p, p.open_quantity, bar.open, reason, bar.open_ms, self.rates.close, intrabar=False)

    def _fill_entry(self, pending: _PendingEntry, bar: Bar) -> None:
        candidate, admission = pending.candidate, pending.admission
        clock = Clock(observed_ms=bar.open_ms, available_ms=bar.open_ms, decision_ms=bar.open_ms)
        header = dataclasses.replace(candidate.header, clock=clock, portfolio_id=self.portfolio_id)
        order = {'candidate_id': candidate.candidate_id, 'client_order_id': admission.decision_id,
                 'side': candidate.header.side.value if candidate.header.side else None,
                 'quantity': wire_decimal(admission.quantity or ZERO), 'type': 'ioc_limit',
                 'limit_price': wire_decimal(admission.price_reference), 'time_ms': bar.open_ms,
                 'attached_stop': wire_decimal(candidate.initial_stop_price)}
        failure = self._take_failure(candidate.candidate_id, None)
        if failure is not None and failure.kind is FailureKind.ORDER_REJECTED:
            self.audit.record_private(header=header, event=AuditEvent.ORDER, kind='order', document=order,
                                      subject_id=admission.decision_id, reasons=('entry_order_rejected',))
            self._refuse(bar.open_ms, 'order', candidate.candidate_id, ['entry_order_rejected'], header)
            return
        if failure is not None and failure.kind is FailureKind.NETWORK_ERROR and not failure.filled_on_venue:
            self.audit.record_private(header=header, event=AuditEvent.ORDER, kind='order', document=order,
                                      subject_id=admission.decision_id,
                                      reasons=('entry_network_error', 'reconciled_not_filled'))
            self._refuse(bar.open_ms, 'order', candidate.candidate_id,
                         ['entry_network_error', 'reconciled_not_filled'], header)
            return
        view = self._view(clock, exclude=pending)
        fill = self.gate.admit_fill(admission, candidate, bar.open, view)
        self._record_decision(fill)
        if fill.verdict is RiskVerdict.REJECT:
            self._refuse(bar.open_ms, 'fill', candidate.candidate_id, fill.reasons, header)
            return
        assert fill.quantity is not None
        order_reasons = ['entry_order_placed']
        if failure is not None and failure.kind is FailureKind.NETWORK_ERROR:
            order_reasons += ['entry_network_error', 'reconciled_filled_on_venue']
        self.audit.record_private(header=header, event=AuditEvent.ORDER, kind='order', document=order,
                                  subject_id=admission.decision_id, reasons=tuple(order_reasons))
        p = _Position(position_id=derived_id('pos', self.portfolio_id, candidate.fingerprint()), candidate=candidate,
                      admission=admission, fill=fill, entry_ms=bar.open_ms, entry_price=bar.open,
                      initial_quantity=fill.quantity, open_quantity=fill.quantity,
                      stop=candidate.initial_stop_price, target=candidate.initial_target_price,
                      nominal_risk=fill.risk_amount, protected=True, mfe=bar.open, mae=bar.open)
        if failure is not None and failure.kind is FailureKind.NETWORK_ERROR:
            p.flag('reconciled_after_network_error')
        if failure is not None and failure.kind is FailureKind.PROTECTION_FAILED:
            p.protected, p.pending_close = False, 'fail_safe_unprotected'
            p.flag('protection_failed')
        costs = leg_costs(fill.quantity * p.inst.contract_value, bar.open, self.rates.entry)
        p.fees, p.spread, p.slippage = costs['fee'], costs['spread'], costs['slippage']
        self.cash -= costs['fee'] + costs['spread'] + costs['slippage']
        self.positions[p.position_id] = p
        self.audit.record_private(header=header, event=AuditEvent.FILL, kind='fill', subject_id=p.position_id,
                                  document={**order, 'position_id': p.position_id, 'price': wire_decimal(bar.open),
                                            'fee': wire_decimal(costs['fee']), 'protected': p.protected},
                                  reasons=tuple(p.flags) or ('filled',))

    # ------------------------------------------------------------------------------------------ the bar
    def _intrabar(self, p: _Position, bar: Bar) -> None:
        if p.closed_ms is not None:
            return
        side, sign = p.side, direction(p.side)
        extreme_adverse = bar.low if side is Side.LONG else bar.high
        if p.protected:
            stop_hit = (extreme_adverse - p.stop) * sign <= 0
            liquidation = self._liquidation(p)
            liq_hit = liquidation > 0 and (extreme_adverse - liquidation) * sign <= 0
            target_hit = p.target is not None and self._target_crossed(p, bar.high, bar.low)
            if (stop_hit or liq_hit) and target_hit:
                p.flag('stop_and_target_same_bar_stop_assumed')
            if liq_hit and self._liquidation_worse(p, liquidation, stop_hit):
                p.liquidated = True
                p.flag('liquidation_proxy_reached')
                self._exit(p, p.open_quantity, liquidation, 'liquidation_proxy', bar.close_ms, self.rates.stop,
                           intrabar=True, extra_fee_rate=self.assumptions.liquidation_fee_rate)
                return
            if stop_hit:
                self._exit(p, p.open_quantity, p.stop, 'stop', bar.close_ms, self.rates.stop, intrabar=True)
                return
            if target_hit and p.target is not None:
                self._exit(p, p.open_quantity, p.target, 'target', bar.close_ms, self.rates.target, intrabar=True)
                return
        p.mfe = max(p.mfe, bar.high) if side is Side.LONG else min(p.mfe, bar.low)
        p.mae = min(p.mae, bar.low) if side is Side.LONG else max(p.mae, bar.high)

    def _liquidation(self, p: _Position) -> Decimal:
        """Isolated 1x proxy rounded on the tick grid against the trader, so every cash amount stays a terminating
        decimal and the wallet reconciles exactly (the unrounded proxy is non-terminating)."""
        raw = liquidation_price(p.side, p.entry_price, H3_LIMITS.leverage_max, self.assumptions.mmr_proxy_rate)
        return round_stop_away(p.side, raw, p.inst.tick_size)

    def _liquidation_worse(self, p: _Position, liquidation: Decimal, stop_hit: bool) -> bool:
        if not stop_hit:
            return True
        stop_rate = self.rates.stop.total
        liq_rate = stop_rate + self.assumptions.liquidation_fee_rate
        if p.side is Side.LONG:  # cash received per unit: price x (1 - rate)
            return liquidation * (ONE - liq_rate) <= p.stop * (ONE - stop_rate)
        return liquidation * (ONE + liq_rate) >= p.stop * (ONE + stop_rate)

    def _funding_exposure(self, p: _Position, funding_ms: int) -> str:
        if funding_ms < p.entry_ms:
            return 'none'
        if funding_ms == p.entry_ms:
            return 'ambiguous'
        if p.closed_ms is None:
            return 'certain'
        if p.exit_intrabar:
            bar_open = p.closed_ms - self.interval
            if funding_ms <= bar_open:
                return 'certain'
            return 'ambiguous' if funding_ms < p.closed_ms else 'none'
        if funding_ms < p.closed_ms:
            return 'certain'
        return 'ambiguous' if funding_ms == p.closed_ms else 'none'

    def _quantity_range(self, p: _Position, funding_ms: int) -> tuple[Decimal, Decimal]:
        """Open quantity at ``funding_ms`` as (low, high); a change at that exact instant leaves both possible.
        An intrabar exit is recorded at the bar close, so the pre-exit quantity applies inside the bar."""
        quantity = p.initial_quantity
        for time_ms, before, after in p.quantity_changes:
            if time_ms < funding_ms:
                quantity = after
            elif time_ms == funding_ms:
                return min(before, after), max(before, after)
        return quantity, quantity

    def _settle_funding(self, observation: FundingObservation, bar_open: Decimal | None) -> None:
        for _, p in sorted(self.positions.items()):
            if p.inst.inst_id != observation.inst_id or p.finalized:
                continue
            exposure = self._funding_exposure(p, observation.funding_ms)
            if exposure == 'none':
                continue
            low, high = self._quantity_range(p, observation.funding_ms)
            if p.closed_ms is not None and p.exit_intrabar and exposure == 'ambiguous':
                low = ZERO
            mark = self.marks.get(observation.inst_id, bar_open if bar_open is not None else p.entry_price)
            per_unit = funding_cashflow(p.side, ONE, mark, observation.rate)
            cv = p.inst.contract_value
            if per_unit < 0:
                amount = per_unit * high * cv
            else:
                amount = per_unit * low * cv if exposure == 'certain' else ZERO
            if exposure == 'ambiguous' or low != high:
                p.flag('funding_path_ambiguous')
            p.funding += amount
            self.cash += amount

    # ------------------------------------------------------------------------------------------ decisions
    def _state(self, p: _Position, decision_ms: int, view: PortfolioView) -> PositionState:
        last = self.last_bar[p.inst.inst_id]
        clock = Clock(observed_ms=last.close_ms, available_ms=last.available_ms, decision_ms=decision_ms)
        current = last.close_ms == self._boundary
        return PositionState(
            header=self._header(p, clock), position_id=p.position_id, candidate_id=p.candidate.candidate_id,
            status=PositionStatus.OPEN, entry_price=p.entry_price, entry_ms=p.entry_ms,
            initial_quantity=p.initial_quantity, open_quantity=p.open_quantity,
            initial_stop=p.candidate.initial_stop_price, current_stop=p.stop, current_target=p.target,
            last_price=last.close, mfe_price=p.mfe, mae_price=p.mae, holding_ms=decision_ms - p.entry_ms,
            max_holding_ms=p.candidate.max_holding_ms, volatility=None, liquidity=LiquidityStatus.UNKNOWN,
            funding_paid=-p.funding, realized_pnl=p.gross - p.fees - p.spread - p.slippage,
            initial_risk_amount=p.nominal_risk, equity=view.equity, open_risk_total=view.open_risk,
            risk_budget_remaining=H3_LIMITS.total_risk_max * view.equity - view.open_risk,
            actions_applied=tuple(p.actions_applied), policy=self.policy.policy,
            data_status=DataStatus.COMPLETE if current else DataStatus.STALE, kill_switch=view.kill_switch)

    def _manage(self, decision_ms: int, clock: Clock) -> None:
        busy = {a.position_id for a in self.pending_actions}
        for p in self._open():
            if p.pending_close is not None or p.position_id in busy or not p.protected:
                continue
            view = self._view(clock)
            state = self._state(p, decision_ms, view)
            try:
                action = self.policy.decide(state)
            except (ContractError, ValueError, ArithmeticError) as error:
                code = str(error) if isinstance(error, ContractError) else 'policy_error'
                self._refuse(decision_ms, 'policy', p.position_id, ['policy_error', code], self._header(p, clock))
                continue
            self.audit.record_contract(header=action.header, event=AuditEvent.ACTION, subject=action,
                                       subject_id=action.action_id, policy=action.policy, reasons=action.reasons)
            decision = self.gate.validate_action(action, state, view)
            self._record_decision(decision)
            if decision.verdict is RiskVerdict.REJECT:
                self._refuse(decision_ms, 'action', action.action_id, decision.reasons, action.header)
            elif action.kind is not ActionKind.HOLD:
                self.pending_actions.append(_PendingAction(action=action, decision=decision,
                                                           position_id=p.position_id, decision_ms=decision_ms))

    def _admit(self, candidate: OpportunityCandidate, decision_ms: int, clock: Clock) -> None:
        header = self._candidate_header(candidate)
        self.audit.record_contract(header=header, event=AuditEvent.CANDIDATE, subject=candidate,
                                   subject_id=candidate.candidate_id)
        score = self.scores.get(candidate.candidate_id)
        if score is not None:
            self.audit.record_contract(header=dataclasses.replace(score.header, portfolio_id=self.portfolio_id),
                                       event=AuditEvent.SCORE, subject=score, subject_id=candidate.candidate_id,
                                       model=score.model, reasons=score.reasons)
        if candidate.header.instrument.inst_id not in self.bars:
            self._refuse(decision_ms, 'entry', candidate.candidate_id, ['no_market_data'], header)
            return
        decision = self.gate.admit_entry(candidate, score, self._view(clock), open_instruments=self._occupied())
        self._record_decision(decision)
        if decision.verdict is RiskVerdict.REJECT:
            self._refuse(decision_ms, 'entry', candidate.candidate_id, decision.reasons, header)
        elif decision.decision_id in self.consumed_decisions:
            self._refuse(decision_ms, 'entry', candidate.candidate_id, ['decision_already_applied'], header)
        else:
            self.consumed_decisions.add(decision.decision_id)
            self.pending_entries.append(_PendingEntry(candidate=candidate, admission=decision,
                                                      decision_ms=decision_ms))

    def _trigger_kill_switch(self, decision_ms: int) -> None:
        self.kill_switch_ms = decision_ms
        for p in self._open():
            if self.flatten_on_kill_switch and p.pending_close is None:
                p.pending_close = 'kill_switch_flatten'
        if self.instruments:
            inst = self.instruments[sorted(self.instruments)[0]]
            source = next(c for c in self.candidates if c.header.instrument == inst).header
            clock = Clock(observed_ms=decision_ms, available_ms=decision_ms, decision_ms=decision_ms)
            self.audit.record_private(header=dataclasses.replace(source, clock=clock, portfolio_id=self.portfolio_id),
                                      event=AuditEvent.KILL_SWITCH, kind='kill_switch', subject_id=self.portfolio_id,
                                      document={'time_ms': decision_ms, 'flatten': self.flatten_on_kill_switch},
                                      reasons=('kill_switch_active',))

    def _record_point(self, time_ms: int) -> None:
        clock = Clock(observed_ms=time_ms, available_ms=time_ms, decision_ms=time_ms)
        view = self._view(clock)
        self.curve.append(EquityPoint(time_ms=time_ms, equity=view.equity, cash=view.cash, open_risk=view.open_risk,
                                      open_notional=view.open_notional, open_positions=len(view.open_position_ids)))
        if view.equity > 0:
            self.max_risk_fraction = max(self.max_risk_fraction, view.open_risk / view.equity)
            self.max_leverage = max(self.max_leverage, view.open_notional / view.equity)

    # ---------------------------------------------------------------------------------------------- run
    def run(self) -> VariantRunResult:
        index = {(bar.open_ms, inst): bar for inst, rows in self.bars.items() for bar in rows}
        steps = sorted({open_ms for open_ms, _ in index})
        cursor, funding_cursor, previous_decision = 0, 0, -1
        for t in steps:
            boundary = t + self.interval
            self._boundary = boundary
            bars_now = {inst: index[(t, inst)] for inst in sorted(self.bars) if (t, inst) in index}
            decision_ms = max(bar.available_ms for bar in bars_now.values())
            for inst, bar in bars_now.items():
                for p in [q for q in self._open() if q.inst.inst_id == inst]:
                    self._protective_at_open(p, bar)
                for pending in [a for a in self.pending_actions
                                if self.positions[a.position_id].inst.inst_id == inst and a.decision_ms <= t]:
                    self.pending_actions.remove(pending)
                    self._apply_action(pending, bar)
                for p in [q for q in self._open() if q.inst.inst_id == inst]:
                    self._market_close_at_open(p, bar)
                for pending_entry in [e for e in self.pending_entries
                                      if e.candidate.header.instrument.inst_id == inst and e.decision_ms <= t]:
                    self.pending_entries.remove(pending_entry)
                    self._fill_entry(pending_entry, bar)
            for inst, bar in bars_now.items():
                for p in [q for q in self._open() if q.inst.inst_id == inst]:
                    self._intrabar(p, bar)
            while funding_cursor < len(self.funding) and self.funding[funding_cursor].funding_ms < boundary:
                observation = self.funding[funding_cursor]
                funding_cursor += 1
                bar = bars_now.get(observation.inst_id)
                self._settle_funding(observation, bar.open if bar is not None else None)
            for inst, bar in bars_now.items():
                self.marks[inst] = bar.close
                self.last_bar[inst] = bar
            for p in sorted(self.positions.values(), key=lambda q: q.position_id):
                if p.closed_ms is not None and not p.finalized:
                    self._finalize(p)
            self._record_point(boundary)
            clock = Clock(observed_ms=boundary, available_ms=decision_ms, decision_ms=decision_ms)
            if self.kill_switch_at_ms is not None and self.kill_switch_ms is None \
                    and decision_ms >= self.kill_switch_at_ms:
                self._trigger_kill_switch(decision_ms)
            self._manage(decision_ms, clock)
            while cursor < len(self.candidates) and self.candidates[cursor].header.clock.decision_ms <= decision_ms:
                self._admit(self.candidates[cursor], decision_ms, clock)
                cursor += 1
            previous_decision = decision_ms
        return self._finish(previous_decision)

    def _finish(self, end_ms: int) -> VariantRunResult:
        for candidate in self.candidates:
            if candidate.header.clock.decision_ms > end_ms:
                self._refuse(end_ms, 'entry', candidate.candidate_id, ['after_end_of_data'],
                             self._candidate_header(candidate))
        for pending in self.pending_entries:
            self._refuse(end_ms, 'order', pending.candidate.candidate_id, ['end_of_data_pending_entry'],
                         self._candidate_header(pending.candidate))
        for pending_action in self.pending_actions:
            self._refuse(end_ms, 'action_execution', pending_action.action.action_id, ['end_of_data_pending_action'],
                         pending_action.action.header)
        self.pending_entries, self.pending_actions = [], []
        for p in self._open():
            p.flag('end_of_data_valuation')
            self._exit(p, p.open_quantity, self._mark(p), 'end_of_data', self.last_bar[p.inst.inst_id].close_ms,
                       self.rates.close, intrabar=False)
            self._finalize(p)
        reconciled = self.initial_equity + sum((t.net_pnl for t in self.trades), ZERO)
        if reconciled != self.cash:
            raise ContractError('h3_sim_wallet_unreconciled')
        return VariantRunResult(experiment_id=self.experiment_id, variant=self.variant, portfolio_id=self.portfolio_id,
                                initial_equity=self.initial_equity, final_cash=self.cash, trades=tuple(self.trades),
                                equity_curve=tuple(self.curve), refusals=tuple(self.refusals),
                                risk_decisions=tuple(self.decisions), audit=self.audit,
                                kill_switch_ms=self.kill_switch_ms, max_open_risk_fraction=self.max_risk_fraction,
                                max_leverage=self.max_leverage)


def run_variant(*, experiment_id: str, variant: Variant, portfolio_id: str, bars: Sequence[Bar],
                candidates: Sequence[OpportunityCandidate], policy: ManagementPolicy, gate: H3RiskGate,
                scores: Mapping[str, OpportunityScore] | None = None, funding: Sequence[FundingObservation] = (),
                initial_equity: Decimal = H3_LIMITS.reference_equity, quote_ccy: str = 'USDC',
                failures: FailurePlan = FailurePlan(), kill_switch_at_ms: int | None = None,
                flatten_on_kill_switch: bool = False, audit: AuditJournal | None = None) -> VariantRunResult:
    """Replay one variant portfolio. The gate's cost model and assumptions are the simulator's: one source of truth
    for the costs charged and the costs sized for. ``candidates`` are that variant's (already selected) candidates;
    ``scores`` (by candidate id) are passed to the gate, which refuses a NO_TRADE score."""
    return _VariantSimulator(experiment_id=experiment_id, variant=variant, portfolio_id=portfolio_id, bars=bars,
                             candidates=candidates, policy=policy, gate=gate, scores=scores, funding=funding,
                             initial_equity=initial_equity, quote_ccy=quote_ccy, failures=failures,
                             kill_switch_at_ms=kill_switch_at_ms, flatten_on_kill_switch=flatten_on_kill_switch,
                             audit=audit).run()
