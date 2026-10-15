"""H3 risk gate: entry admission and adaptive-action validation under the H3 experimental limits.

``H3RiskGate`` implements ``contracts.RiskGate``. It is the only component allowed to turn a candidate into a
position size or to let an adaptive action change a position. Scoring never reaches an order without it, and an
adaptive action never changes a position without it.

Entry admission (``admit_entry``), in order:
1. structural refusals: missing or non-protective stop, score for another candidate or a NO_TRADE score, candidate
   from the future or older than ``max_candidate_age_ms``, settlement currency different from the portfolio's,
   kill switch, non-positive equity, instrument already open or pending (or occupancy unknown: fail closed);
2. sizing, aligned with ``CanonicalRiskEngine``: the budget is ``min(0.5 % x equity, 1 % x equity - open risk)``,
   divided by the cost-inclusive loss of one contract at the stop (gross + entry and stop fees, spreads, slippage +
   adverse funding provision), floored to the lot size; then capped by leverage 1x (portfolio notional <= equity)
   and by margin (cash - margin in use must pay notional / leverage + entry costs). Every amount is computed at
   the entry collar price (the IOC limit, ``ExecutionAssumptions.entry_collar_rate``), which is the admitted
   ``price_reference``: any fill inside the collar respects the limits;
3. a post-quantisation check that steps down one lot if the cost-inclusive risk still exceeds the budget (as PHP);
4. refusal below ``min_size`` or ``min_notional``.

Costs are inside the nominal risk, exactly as PHP's ``totalStopLoss``: H3's "0.5 % per trade" is therefore a cap on
the cost-inclusive loss at the stop, not on the gross price distance. A gap can still make the realised loss exceed
it; the simulator measures that excess separately and never clips it.

Fill re-check (``admit_fill``): the entry quantity is fixed at admission; a fill beyond the collar is refused (the
IOC limit would not fill), and at the actual fill price the limits are checked again against the current equity.
A refused fill opens no position.

Action validation (``validate_action``): the action must be for this exact ``PositionState`` (state fingerprint),
for an open position, not already applied; a stop only tightens (strictly, on the tick grid, still protective
against the last price); a target stays beyond the last price on the profit side; a partial close is on the lot grid,
>= min size, strictly partial and leaves >= min size; the portfolio's open risk and notional never increase. With the
kill switch on, only HOLD, TIGHTEN_STOP, PARTIAL_CLOSE and FULL_CLOSE are admitted.

Idempotency: the same candidate (same fingerprint) for the same portfolio always returns the same decision object
(same ``decision_id``); a different candidate reusing a candidate id is refused. The same action (same idempotency
key) returns the same decision. The caller must still refuse to apply a decision id twice (the simulator does).

Research prototype only: these are the H3 experimental limits, not a production policy. Production sizing stays in
TradingCore (``CanonicalRiskEngine``, ``CanonicalPortfolioAdmissionEngine``); see H3-RISK-AND-EXECUTION.md.
"""
from __future__ import annotations

import dataclasses
from collections.abc import Iterable
from dataclasses import dataclass, field
from decimal import Decimal
from typing import Any

from ..contracts import (
    H3_LIMITS, ONE, ZERO, ActionKind, AdaptiveAction, ContractError, CostModel, H3Limits, Header,
    OpportunityCandidate, OpportunityScore, PolicyRef, PortfolioView, PositionState, PositionStatus, RiskDecision,
    RiskSubject, RiskVerdict, ScoreVerdict, Side, stop_is_protective, stop_tightens,
)
from .costs import (
    CostRates, ExecutionAssumptions, config_fingerprint, cost_rates, derived_id, direction, entry_collar_price,
    floor_to_step, on_grid, position_risk, provision_intervals,
)

RISK_DEFINITION = 'cost_inclusive_stop_loss.v1'
_RISK_REDUCING = (ActionKind.HOLD, ActionKind.TIGHTEN_STOP, ActionKind.PARTIAL_CLOSE, ActionKind.FULL_CLOSE)


@dataclass(frozen=True)
class H3RiskConfig:
    """Everything the gate's decisions depend on; its fingerprint is the risk ``PolicyRef`` fingerprint."""

    cost_model: CostModel
    assumptions: ExecutionAssumptions = field(default_factory=ExecutionAssumptions)
    limits: H3Limits = H3_LIMITS
    max_candidate_age_ms: int | None = None
    policy_id: str = 'h3-risk-gate'
    policy_version: str = '0.1.0'

    def __post_init__(self) -> None:
        if self.limits != H3_LIMITS:
            raise ContractError('h3_risk_config_limits_not_h3')
        if self.max_candidate_age_ms is not None and (isinstance(self.max_candidate_age_ms, bool)
                                                      or self.max_candidate_age_ms < 0):
            raise ContractError('h3_risk_config_invalid')

    def to_wire(self) -> dict[str, Any]:
        return {'cost_model': self.cost_model.to_wire(), 'assumptions': self.assumptions.to_wire(),
                'limits': self.limits.to_wire(), 'max_candidate_age_ms': self.max_candidate_age_ms,
                'risk_definition': RISK_DEFINITION, 'policy_id': self.policy_id,
                'policy_version': self.policy_version}

    def policy_ref(self) -> PolicyRef:
        return PolicyRef(policy_id=self.policy_id, policy_version=self.policy_version,
                         policy_fingerprint=config_fingerprint(self.to_wire()))

    @property
    def rates(self) -> CostRates:
        return cost_rates(self.cost_model, self.assumptions)


class H3RiskGate:
    """Stateful only for idempotency (decisions already given, per portfolio); otherwise a pure function."""

    def __init__(self, config: H3RiskConfig) -> None:
        self.config = config
        self.policy: PolicyRef = config.policy_ref()
        self.rates: CostRates = config.rates
        self._entries: dict[tuple[str, str], tuple[str, RiskDecision]] = {}
        self._fills: dict[tuple[str, str, Decimal], RiskDecision] = {}
        self._actions: dict[tuple[str, str], RiskDecision] = {}

    # ------------------------------------------------------------------------------------------------- helpers
    def intervals(self, max_holding_ms: int) -> int:
        return provision_intervals(max_holding_ms, self.rates.funding_interval_ms)

    def risk_of(self, side: Side, entry: Decimal, stop: Decimal, quantity: Decimal, contract_value: Decimal,
                max_holding_ms: int) -> Decimal:
        """Cost-inclusive nominal risk of ``quantity`` contracts (the R unit)."""
        return position_risk(side, entry, stop, quantity * contract_value, self.rates, self.intervals(max_holding_ms))

    def _header(self, source: Header, portfolio: PortfolioView) -> Header:
        return dataclasses.replace(source, clock=portfolio.clock, portfolio_id=portfolio.portfolio_id)

    @staticmethod
    def _occupied(portfolio: PortfolioView, open_instruments: Iterable[str] | None) -> frozenset[str] | None:
        if open_instruments is not None:
            return frozenset(open_instruments)
        return frozenset() if not portfolio.open_position_ids else None

    def _decision(self, *, header: Header, decision_id: str, subject: RiskSubject, subject_id: str,
                  verdict: RiskVerdict, reasons: tuple[str, ...], equity: Decimal, quantity: Decimal | None,
                  price: Decimal, stop: Decimal | None, risk: Decimal, risk_before: Decimal, risk_after: Decimal,
                  notional_before: Decimal, notional_after: Decimal) -> RiskDecision:
        leverage = notional_after / equity if equity > 0 else ZERO
        return RiskDecision(header=header, decision_id=decision_id, subject=subject, subject_id=subject_id,
                            verdict=verdict, reasons=reasons, policy=self.policy, limits=self.config.limits,
                            equity=equity, quantity=quantity, price_reference=price, stop_price=stop,
                            risk_amount=risk, open_risk_before=risk_before, open_risk_after=risk_after,
                            notional_before=notional_before, notional_after=notional_after, leverage_after=leverage)

    def _reject_entry(self, candidate: OpportunityCandidate, portfolio: PortfolioView, reasons: list[str],
                      decision_id: str, price: Decimal | None = None) -> RiskDecision:
        unique = tuple(dict.fromkeys(reasons))
        return self._decision(header=self._header(candidate.header, portfolio), decision_id=decision_id,
                              subject=RiskSubject.ENTRY, subject_id=candidate.candidate_id,
                              verdict=RiskVerdict.REJECT, reasons=unique, equity=portfolio.equity, quantity=None,
                              price=price or candidate.entry_reference_price, stop=candidate.initial_stop_price,
                              risk=ZERO, risk_before=portfolio.open_risk, risk_after=portfolio.open_risk,
                              notional_before=portfolio.open_notional, notional_after=portfolio.open_notional)

    # --------------------------------------------------------------------------------------------------- entry
    def admit_entry(self, candidate: OpportunityCandidate, score: OpportunityScore | None,
                    portfolio: PortfolioView, *, open_instruments: Iterable[str] | None = None) -> RiskDecision:
        """Size and admit (or refuse) one candidate. ``open_instruments``: instruments open or pending in the
        portfolio (PortfolioView does not carry them, contract change request CR-2). Without it and with open
        positions, the gate refuses (``portfolio_occupancy_unknown``)."""
        key = (portfolio.portfolio_id, candidate.candidate_id)
        fingerprint = candidate.fingerprint()
        decision_id = derived_id('risk', 'entry', portfolio.portfolio_id, fingerprint, self.policy.policy_fingerprint)
        known = self._entries.get(key)
        if known is not None:
            if known[0] == fingerprint:
                return known[1]
            return self._reject_entry(candidate, portfolio, ['candidate_id_conflict'], decision_id)
        decision = self._decide_entry(candidate, score, portfolio, open_instruments, decision_id)
        self._entries[key] = (fingerprint, decision)
        return decision

    def _decide_entry(self, candidate: OpportunityCandidate, score: OpportunityScore | None,
                      portfolio: PortfolioView, open_instruments: Iterable[str] | None,
                      decision_id: str) -> RiskDecision:
        header = candidate.header
        side, inst = header.side, header.instrument
        entry, stop = candidate.entry_reference_price, candidate.initial_stop_price
        reasons: list[str] = []
        if stop is None:
            reasons.append('stop_missing')
        elif side is None or not stop_is_protective(side, entry, stop):
            reasons.append('stop_not_protective')
        if score is not None:
            if score.candidate_id != candidate.candidate_id:
                reasons.append('score_candidate_mismatch')
            elif score.verdict is ScoreVerdict.NO_TRADE:
                reasons.append('score_verdict_no_trade')
        age = portfolio.clock.decision_ms - header.clock.decision_ms
        if age < 0:
            reasons.append('candidate_from_future')
        elif self.config.max_candidate_age_ms is not None and age > self.config.max_candidate_age_ms:
            reasons.append('candidate_stale')
        if inst.settle_ccy != portfolio.quote_ccy:
            reasons.append('settlement_currency_mismatch')
        if portfolio.kill_switch:
            reasons.append('kill_switch_active')
        if portfolio.equity <= 0:
            reasons.append('equity_non_positive')
        occupied = self._occupied(portfolio, open_instruments)
        if occupied is None:
            reasons.append('portfolio_occupancy_unknown')
        elif inst.inst_id in occupied:
            reasons.append('instrument_already_open')
        if reasons:
            return self._reject_entry(candidate, portfolio, reasons, decision_id)
        assert side is not None and stop is not None

        limits, equity = self.config.limits, portfolio.equity
        cv, lot = inst.contract_value, inst.lot_size
        entry = entry_collar_price(side, entry, self.config.assumptions.entry_collar_rate, inst.tick_size)
        per_trade = limits.per_trade_risk_max * equity
        total_left = limits.total_risk_max * equity - portfolio.open_risk
        exposure_left = limits.leverage_max * equity - portfolio.open_notional
        available = portfolio.cash - portfolio.open_notional / limits.leverage_max
        if total_left <= 0:
            return self._reject_entry(candidate, portfolio, ['total_risk_budget_exhausted'], decision_id)
        if exposure_left <= 0:
            return self._reject_entry(candidate, portfolio, ['leverage_cap_exhausted'], decision_id)
        if available <= 0:
            return self._reject_entry(candidate, portfolio, ['margin_insufficient'], decision_id)

        budget = min(per_trade, total_left)
        caps: list[str] = ['cap_total_risk'] if total_left < per_trade else []
        loss_per_contract = self.risk_of(side, entry, stop, ONE, cv, candidate.max_holding_ms)
        q_risk = floor_to_step(budget / loss_per_contract, lot)
        q_leverage = floor_to_step(exposure_left / (cv * entry), lot)
        q_margin = floor_to_step(available / (cv * entry * (ONE / limits.leverage_max + self.rates.entry.total)), lot)
        quantity = min(q_risk, q_leverage, q_margin)
        if q_leverage < q_risk:
            caps.append('cap_leverage')
        if q_margin < min(q_risk, q_leverage):
            caps.append('cap_margin')
        risk = self.risk_of(side, entry, stop, quantity, cv, candidate.max_holding_ms)
        if quantity > 0 and risk > budget:  # post-quantisation breach, as CanonicalRiskEngine: one lot down
            quantity -= lot
            risk = self.risk_of(side, entry, stop, quantity, cv, candidate.max_holding_ms)
        if quantity <= 0 or quantity < inst.min_size:
            return self._reject_entry(candidate, portfolio, ['quantity_below_min_size', *caps], decision_id)
        if risk > budget:
            return self._reject_entry(candidate, portfolio, ['post_quantization_risk_breach'], decision_id)
        notional = quantity * cv * entry
        if inst.min_notional is not None and notional < inst.min_notional:
            return self._reject_entry(candidate, portfolio, ['notional_below_min_notional'], decision_id)
        return self._decision(header=self._header(header, portfolio), decision_id=decision_id,
                              subject=RiskSubject.ENTRY, subject_id=candidate.candidate_id,
                              verdict=RiskVerdict.ADMIT, reasons=tuple(caps), equity=equity, quantity=quantity,
                              price=entry, stop=stop, risk=risk, risk_before=portfolio.open_risk,
                              risk_after=portfolio.open_risk + risk, notional_before=portfolio.open_notional,
                              notional_after=portfolio.open_notional + notional)

    # ---------------------------------------------------------------------------------------------------- fill
    def admit_fill(self, admission: RiskDecision, candidate: OpportunityCandidate, fill_price: Decimal,
                   portfolio: PortfolioView) -> RiskDecision:
        """Re-check an admitted entry at its actual fill price. ``portfolio`` must exclude this entry's own
        reservation. Same candidate and price: same decision (idempotent)."""
        key = (portfolio.portfolio_id, candidate.candidate_id, fill_price)
        known = self._fills.get(key)
        if known is not None:
            return known
        decision_id = derived_id('risk', 'fill', portfolio.portfolio_id, candidate.fingerprint(),
                                 str(fill_price), self.policy.policy_fingerprint)
        decision = self._decide_fill(admission, candidate, fill_price, portfolio, decision_id)
        self._fills[key] = decision
        return decision

    def _decide_fill(self, admission: RiskDecision, candidate: OpportunityCandidate, fill_price: Decimal,
                     portfolio: PortfolioView, decision_id: str) -> RiskDecision:
        side, inst = candidate.header.side, candidate.header.instrument
        stop, limits, equity = candidate.initial_stop_price, self.config.limits, portfolio.equity
        reasons: list[str] = []
        if (admission.verdict is not RiskVerdict.ADMIT or admission.subject is not RiskSubject.ENTRY
                or admission.subject_id != candidate.candidate_id
                or admission.header.portfolio_id != portfolio.portfolio_id
                or self._entries.get((portfolio.portfolio_id, candidate.candidate_id), ('', None))[1] != admission):
            reasons.append('fill_without_admission')
        if portfolio.kill_switch:
            reasons.append('kill_switch_active')
        if equity <= 0:
            reasons.append('equity_non_positive')
        if side is None or fill_price <= 0 or not stop_is_protective(side, fill_price, stop):
            reasons.append('fill_beyond_stop')
        elif (fill_price - admission.price_reference) * direction(side) > 0:
            reasons.append('fill_beyond_collar')
        if reasons:
            return self._reject_entry(candidate, portfolio, reasons, decision_id, price=fill_price)
        assert side is not None and admission.quantity is not None
        quantity, cv = admission.quantity, inst.contract_value
        risk = self.risk_of(side, fill_price, stop, quantity, cv, candidate.max_holding_ms)
        notional = quantity * cv * fill_price
        if risk > limits.per_trade_risk_max * equity:
            reasons.append('fill_risk_above_per_trade')
        if portfolio.open_risk + risk > limits.total_risk_max * equity:
            reasons.append('fill_total_risk_exceeded')
        if portfolio.open_notional + notional > limits.leverage_max * equity:
            reasons.append('fill_leverage_exceeded')
        if notional * (ONE / limits.leverage_max + self.rates.entry.total) \
                > portfolio.cash - portfolio.open_notional / limits.leverage_max:
            reasons.append('fill_margin_insufficient')
        if reasons:
            return self._reject_entry(candidate, portfolio, reasons, decision_id, price=fill_price)
        return self._decision(header=self._header(candidate.header, portfolio), decision_id=decision_id,
                              subject=RiskSubject.ENTRY, subject_id=candidate.candidate_id,
                              verdict=RiskVerdict.ADMIT, reasons=('fill_recheck',), equity=equity,
                              quantity=quantity, price=fill_price, stop=stop, risk=risk,
                              risk_before=portfolio.open_risk, risk_after=portfolio.open_risk + risk,
                              notional_before=portfolio.open_notional,
                              notional_after=portfolio.open_notional + notional)

    # -------------------------------------------------------------------------------------------------- action
    def validate_action(self, action: AdaptiveAction, state: PositionState, portfolio: PortfolioView) -> RiskDecision:
        key = (portfolio.portfolio_id, action.idempotency_key)
        known = self._actions.get(key)
        if known is not None:
            return known
        decision = self._decide_action(action, state, portfolio)
        self._actions[key] = decision
        return decision

    def _action_reasons(self, action: AdaptiveAction, state: PositionState, portfolio: PortfolioView) -> list[str]:
        reasons: list[str] = []
        side, inst = state.header.side, state.header.instrument
        if action.position_id != state.position_id:
            reasons.append('action_position_mismatch')
        if action.state.fingerprint != state.fingerprint():
            reasons.append('action_state_mismatch')
        if action.policy != state.policy:
            reasons.append('action_policy_mismatch')
        if portfolio.portfolio_id != action.header.portfolio_id or portfolio.portfolio_id != state.header.portfolio_id:
            reasons.append('portfolio_mismatch')
        if state.status is PositionStatus.CLOSED or state.position_id not in portfolio.open_position_ids:
            reasons.append('position_not_open')
        if action.action_id in state.actions_applied:
            reasons.append('action_already_applied')
        if portfolio.kill_switch and action.kind not in _RISK_REDUCING:
            reasons.append('kill_switch_active')
        if portfolio.equity <= 0:
            reasons.append('equity_non_positive')
        assert side is not None
        if action.kind is ActionKind.TIGHTEN_STOP and action.new_stop is not None:
            if not on_grid(action.new_stop, inst.tick_size):
                reasons.append('stop_not_on_tick')
            if not stop_tightens(side, state.current_stop, action.new_stop):
                reasons.append('stop_not_tightened')
            if not stop_is_protective(side, state.last_price, action.new_stop):
                reasons.append('stop_crosses_last_price')
        elif action.kind is ActionKind.ADJUST_TARGET and action.new_target is not None:
            if not on_grid(action.new_target, inst.tick_size):
                reasons.append('target_not_on_tick')
            if stop_is_protective(side, state.last_price, action.new_target) or action.new_target == state.last_price:
                reasons.append('target_not_beyond_last_price')
        elif action.kind is ActionKind.PARTIAL_CLOSE and action.close_quantity is not None:
            quantity = action.close_quantity
            if not on_grid(quantity, inst.lot_size):
                reasons.append('quantity_not_on_lot')
            if quantity < inst.min_size:
                reasons.append('close_below_min_size')
            if quantity >= state.open_quantity:
                reasons.append('partial_close_not_partial')
            elif state.open_quantity - quantity < inst.min_size:
                reasons.append('remaining_below_min_size')
        return reasons

    def _decide_action(self, action: AdaptiveAction, state: PositionState, portfolio: PortfolioView) -> RiskDecision:
        side, inst = state.header.side, state.header.instrument
        assert side is not None
        cv = inst.contract_value
        risk_before = self.risk_of(side, state.entry_price, state.current_stop, state.open_quantity, cv,
                                   state.max_holding_ms) if state.status is PositionStatus.OPEN else ZERO
        notional_pos_before = state.open_quantity * cv * state.entry_price
        stop_after, quantity_after = state.current_stop, state.open_quantity
        reasons = self._action_reasons(action, state, portfolio)
        if not reasons:
            if action.kind is ActionKind.TIGHTEN_STOP and action.new_stop is not None:
                stop_after = action.new_stop
            elif action.kind is ActionKind.PARTIAL_CLOSE and action.close_quantity is not None:
                quantity_after = state.open_quantity - action.close_quantity
            elif action.kind is ActionKind.FULL_CLOSE:
                quantity_after = ZERO
        risk_after = self.risk_of(side, state.entry_price, stop_after, quantity_after, cv, state.max_holding_ms)
        open_risk_after = max(ZERO, portfolio.open_risk - risk_before + risk_after)
        notional_after = max(ZERO, portfolio.open_notional - notional_pos_before + quantity_after * cv * state.entry_price)
        if not reasons and (open_risk_after > portfolio.open_risk or notional_after > portfolio.open_notional):
            reasons.append('action_increases_risk')
        decision_id = derived_id('risk', 'action', portfolio.portfolio_id, action.idempotency_key,
                                 self.policy.policy_fingerprint)
        verdict = RiskVerdict.REJECT if reasons else RiskVerdict.ADMIT
        if reasons:
            open_risk_after, notional_after = portfolio.open_risk, portfolio.open_notional
            risk_after, stop_after, quantity_after = risk_before, state.current_stop, state.open_quantity
        return self._decision(header=action.header, decision_id=decision_id, subject=RiskSubject.ACTION,
                              subject_id=action.action_id, verdict=verdict, reasons=tuple(dict.fromkeys(reasons)),
                              equity=portfolio.equity, quantity=quantity_after, price=state.last_price,
                              stop=stop_after, risk=risk_after, risk_before=portfolio.open_risk,
                              risk_after=open_risk_after, notional_before=portfolio.open_notional,
                              notional_after=notional_after)
