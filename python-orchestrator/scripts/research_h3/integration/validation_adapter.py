"""Adapter: D's bar-replay simulator (``simulator.run_variant``) as C's ``validation.factorial.VariantSimulator``.

``RunVariantSimulator().run(spec, market) -> VariantRun`` replays one variant of the B0-B3 factorial with D's risk gate
and simulator and translates the result into C's records. It is created fresh for every variant by the harness's
``simulator_factory`` (``run_factorial`` refuses a simulator or a gate shared between variants).

Conventions the two sides assumed, checked or translated here (H3-ARCHITECTURE.md, "Conventions at the seams"):

- costs are single-sourced in D's gate: ``spec.risk_gate`` must be an ``H3RiskGate`` whose ``CostModel`` is exactly
  ``spec.cost_model``; the simulator charges what the gate sized for;
- cost scenario: under ``CostScenario.PESSIMISTIC`` the gate's ``ExecutionAssumptions.pessimistic`` must be true (and
  false under BASE), so ``CostModel.pessimistic_multiplier`` scales fees, spread, slippage and adverse funding in the
  simulator and in the sizing alike, as the factorial docstring and the pre-registration state (aligned at review
  R1-F10; before, D left fees unscaled);
- entry order: ``spec.entries`` priorities (scorer rank for B1/B3, seeded lottery for B0/B2) are passed to the
  simulator as ``entry_priority``, so the risk budget is spent in that order;
- the period: bars with ``start_ms <= open_ms`` and ``close_ms <= end_ms``, settlements with
  ``start_ms <= funding_ms < end_ms``; ``start_ms`` must be a bar open and ``end_ms`` the last bar close;
- equity: D marks every open position to the last close at every bar boundary, which covers every block edge on the
  bar grid. The curve gets a first point (``start_ms``, initial equity) and its last point (``end_ms``) is the
  wallet after the end-of-data closes, with no open position;
- trades: funding sign flips (D: cash flow, positive received; C: positive paid); ``gap_excess`` is D's
  ``loss_beyond_nominal``; ``initial_risk`` is D's nominal risk, the admission's cost-inclusive risk at the collar
  (fixed before the fill, review R2-F4); ``exit_price`` is the last
  exit fill; ``funding_events`` counts settlements with ``entry_ms < funding_ms <= exit_ms`` (C's coverage rule);
- refusals: one record per candidate that did not trade (entry refusal -> RISK, order or fill refusal -> EXECUTION,
  end of data -> EXPIRED), plus the harness's score-stage refusals unchanged. Action-level refusals stay in D's
  result (``last_result``) and audit journal; they are not candidate outcomes;
- MFE/MAE are post-fill (D fills at a bar open, so the whole entry bar is after the fill) and ``actions_applied``
  lists applied action ids only (a failed modification is not listed): both hold in D's simulator as written.

Research prototype: synthetic data only, no order, no exchange, no Temporal or Symfony dependency.
"""
from __future__ import annotations

from collections.abc import Sequence
from decimal import Decimal

from ..contracts import ZERO, ContractError, FundingObservation
from ..validation.factorial import MarketStream, VariantSpec
from ..validation.records import (
    CostScenario, EquityPoint, ExitReason, RefusalRecord, RefusalStage, TradeRecord, VariantRun,
)
from .risk_gate import H3RiskGate
from .simulator import FailurePlan, Refusal, Trade, VariantRunResult, run_variant

#: D exit reasons -> C exit reasons. The safety closes ``fail_safe_unprotected`` (stop not attached, or lost in a
#: cancel-then-place replacement) and ``fill_recheck_failed`` (a filled entry refused by the re-check at its fill
#: price) map to FAIL_SAFE, added at review (until then the first mapped to KILL_SWITCH). Stop, gap and liquidation
#: proxy still share STOP (schema v2 backlog #13).
EXIT_REASONS: dict[str, ExitReason] = {
    'stop': ExitReason.STOP, 'stop_gap': ExitReason.STOP, 'liquidation_proxy': ExitReason.STOP,
    'target': ExitReason.TARGET, 'max_holding': ExitReason.TIME_EXPIRY, 'full_close': ExitReason.ADAPTIVE_CLOSE,
    'kill_switch_flatten': ExitReason.KILL_SWITCH, 'fail_safe_unprotected': ExitReason.FAIL_SAFE,
    'fill_recheck_failed': ExitReason.FAIL_SAFE, 'end_of_data': ExitReason.END_OF_DATA,
}
_EXPIRY_REASONS = ('after_end_of_data', 'end_of_data_pending_entry')
_CANDIDATE_STAGES = {'entry': RefusalStage.RISK, 'order': RefusalStage.EXECUTION, 'fill': RefusalStage.EXECUTION}


def _stage(refusal: Refusal) -> RefusalStage:
    if any(reason in _EXPIRY_REASONS for reason in refusal.reasons):
        return RefusalStage.EXPIRED
    return _CANDIDATE_STAGES[refusal.stage]


def funding_events(trade: Trade, funding: Sequence[FundingObservation]) -> int:
    """Settlements crossed while the position was open, C's rule: ``entry_ms < funding_ms <= exit_ms``."""
    return sum(1 for f in funding if f.inst_id == trade.inst_id and trade.entry_ms < f.funding_ms <= trade.exit_ms)


class RunVariantSimulator:
    """One variant run of D's simulator, satisfying ``validation.factorial.VariantSimulator``.

    ``failures`` injects D's execution failures (empty by default). ``last_result`` keeps D's raw result (trades with
    every exit fill, action refusals, risk decisions and the hash-chained audit journal) for inspection.
    """

    def __init__(self, *, failures: FailurePlan = FailurePlan()) -> None:
        self.failures = failures
        self.last_result: VariantRunResult | None = None
        self._used = False

    def run(self, spec: VariantSpec, market: MarketStream) -> VariantRun:
        if self._used:
            raise ContractError('h3_adapter_simulator_reused')
        self._used = True
        gate = spec.risk_gate
        if not isinstance(gate, H3RiskGate):
            raise ContractError('h3_adapter_gate_not_h3')
        if gate.config.cost_model != spec.cost_model:
            raise ContractError('h3_adapter_cost_model_not_single_sourced')
        if gate.config.limits != spec.limits:
            raise ContractError('h3_adapter_limits_mismatch')
        if gate.config.assumptions.pessimistic != (spec.cost_scenario is CostScenario.PESSIMISTIC):
            raise ContractError('h3_adapter_cost_scenario_mismatch')
        bars = tuple(b for b in market.bars if b.open_ms >= spec.start_ms and b.close_ms <= spec.end_ms)
        if not bars or min(b.open_ms for b in bars) != spec.start_ms or max(b.close_ms for b in bars) != spec.end_ms:
            raise ContractError('h3_adapter_period_not_on_bar_grid')
        funding = tuple(f for f in market.funding if spec.start_ms <= f.funding_ms < spec.end_ms)
        candidates = [entry.candidate for entry in spec.entries]
        result = run_variant(
            experiment_id=spec.experiment_id, variant=spec.variant, portfolio_id=spec.portfolio_id, bars=bars,
            candidates=candidates, policy=spec.policy, gate=gate,
            scores={e.candidate.candidate_id: e.score for e in spec.entries if e.score is not None},
            funding=funding, initial_equity=spec.initial_equity, quote_ccy=spec.quote_ccy, failures=self.failures,
            entry_priority={e.candidate.candidate_id: e.priority for e in spec.entries})
        self.last_result = result
        decided = {c.candidate_id: c.header.clock.decision_ms for c in candidates}
        trades = tuple(self._trade(spec, t, decided[t.candidate_id], funding) for t in result.trades)
        refusals = list(spec.pre_refusals)
        by_candidate = {c.candidate_id: c for c in candidates}
        for refusal in result.refusals:
            if refusal.stage not in _CANDIDATE_STAGES:
                continue  # action-level refusal: not a candidate outcome
            candidate = by_candidate[refusal.subject_id]
            refusals.append(RefusalRecord(candidate_id=candidate.candidate_id, variant=spec.variant,
                                          portfolio_id=spec.portfolio_id, inst_id=candidate.header.instrument.inst_id,
                                          decision_ms=candidate.header.clock.decision_ms, stage=_stage(refusal),
                                          reasons=refusal.reasons))
        curve = [EquityPoint(ts_ms=spec.start_ms, equity=spec.initial_equity, open_risk=ZERO, open_positions=0)]
        curve += [EquityPoint(ts_ms=p.time_ms, equity=p.equity, open_risk=p.open_risk, open_positions=p.open_positions)
                  for p in result.equity_curve if spec.start_ms < p.time_ms < spec.end_ms]
        curve.append(EquityPoint(ts_ms=spec.end_ms, equity=result.final_cash, open_risk=ZERO, open_positions=0))
        return VariantRun(
            experiment_id=spec.experiment_id, variant=spec.variant, portfolio_id=spec.portfolio_id,
            quote_ccy=spec.quote_ccy, cost_scenario=spec.cost_scenario, initial_equity=spec.initial_equity,
            start_ms=spec.start_ms, end_ms=spec.end_ms, candidate_book_fingerprint=spec.candidate_book_fingerprint,
            market_fingerprint=spec.market_fingerprint, candidate_ids=spec.candidate_ids,
            trades=tuple(sorted(trades, key=lambda t: (t.entry_ms, t.candidate_id))),
            refusals=tuple(sorted(refusals, key=lambda r: (r.decision_ms, r.candidate_id))),
            equity_curve=tuple(curve))

    @staticmethod
    def _trade(spec: VariantSpec, trade: Trade, decision_ms: int,
               funding: Sequence[FundingObservation]) -> TradeRecord:
        reason = EXIT_REASONS.get(trade.exit_reason)
        if reason is None:
            raise ContractError('h3_adapter_exit_reason_unmapped')
        inst_cv = next(e.candidate.header.instrument.contract_value for e in spec.entries
                       if e.candidate.candidate_id == trade.candidate_id)
        return TradeRecord(
            candidate_id=trade.candidate_id, variant=spec.variant, portfolio_id=spec.portfolio_id,
            inst_id=trade.inst_id, side=trade.side, decision_ms=decision_ms, entry_ms=trade.entry_ms,
            exit_ms=trade.exit_ms, quantity=trade.quantity, contract_value=inst_cv, entry_price=trade.entry_price,
            exit_price=trade.exits[-1].price, initial_stop_price=trade.initial_stop, final_stop_price=trade.final_stop,
            gross_pnl=trade.gross_pnl, fees=trade.fees, spread_cost=trade.spread_cost,
            slippage=trade.slippage_cost, funding=-trade.funding, funding_events=funding_events(trade, funding),
            net_pnl=trade.net_pnl, initial_risk=trade.nominal_risk, gap_excess=trade.loss_beyond_nominal,
            exit_reason=reason, partial_exits=sum(1 for e in trade.exits if e.reason == 'partial_close'),
            actions_applied=len(trade.actions_applied))


def gross_r_label(trade: TradeRecord, entry_reference_price: Decimal, initial_stop_price: Decimal) -> Decimal:
    """The scorer's training label convention (H3-OPPORTUNITY-SCORING.md): GROSS PnL in units of the candidate's
    gross stop distance, ``gross_pnl / (|entry_reference - initial_stop| x quantity x contract_value)``. It is not
    ``TradeRecord.r_multiple``, which is NET PnL over the simulator's cost-inclusive nominal risk."""
    distance = abs(entry_reference_price - initial_stop_price) * trade.quantity * trade.contract_value
    return trade.gross_pnl / distance
