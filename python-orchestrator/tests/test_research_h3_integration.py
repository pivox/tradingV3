"""H3 integration (agent D): risk gate, simulator, audit, synthetic candidates and the TradingCore map.

Synthetic values only; no market data, no exchange, no order. Synthetic results are never evidence.
Run: python -m pytest --noconftest tests/test_research_h3_integration.py
"""
from __future__ import annotations

import dataclasses
import random
import re
import types
from decimal import Decimal as D
from pathlib import Path

import pytest

from scripts.research_h3 import contracts as c
from scripts.research_h3 import synthetic as s
from scripts.research_h3.integration import costs as k
from scripts.research_h3.integration import tradingcore_map as tm
from scripts.research_h3.integration.audit import AuditJournal
from scripts.research_h3.integration.candidates import (
    HYPOTHESIS_ID, SyntheticCandidateProvider, synthetic_snapshot,
)
from scripts.research_h3.integration.risk_gate import H3RiskConfig, H3RiskGate
from scripts.research_h3.integration.simulator import (
    FailureKind, FailurePlan, InjectedFailure, run_variant,
)

H = s.HOUR_MS
T0 = s.T0_MS
DAY = 24 * H
COST = c.CostModel(maker_fee_rate=D('0.0002'), taker_fee_rate=D('0.0005'), slippage_rate=D('0.0005'),
                   spread_model='half-spread-fixed', funding_model='observed-or-zero', pessimistic_multiplier=D('2'))
INST = s.instrument(contract_value='0.01', tick_size='0.01', lot_size='1', min_size='1')
LONG, SHORT = c.Side.LONG, c.Side.SHORT


def gate(**overrides) -> H3RiskGate:
    return H3RiskGate(H3RiskConfig(cost_model=overrides.pop('cost_model', COST), **overrides))


def cand(entry='100', stop='99', target=None, *, side=LONG, decision_ms=T0 + H, inst=INST, cid='cand-1', hold=DAY):
    return c.OpportunityCandidate(
        header=s.header(decision_ms=decision_ms, side=side, inst=inst), candidate_id=cid, generator_id='gen-test',
        generator_version='0.1.0', hypothesis_id=HYPOTHESIS_ID,
        snapshot=c.SnapshotRef(snapshot_id='snap-test', fingerprint='sha256:' + '0' * 64),
        entry_reference_price=D(entry), initial_stop_price=D(stop),
        initial_target_price=None if target is None else D(target), max_holding_ms=hold)


def view(equity='500', cash=None, open_risk='0', open_notional='0', ids=(), kill=False, decision_ms=T0 + H,
         pid='pf-B0', quote='USDC'):
    return c.PortfolioView(portfolio_id=pid, variant=c.Variant.B0, quote_ccy=quote, clock=s.clock(decision_ms),
                           equity=D(equity), cash=D(cash or equity), open_risk=D(open_risk),
                           open_notional=D(open_notional), open_position_ids=tuple(ids), kill_switch=kill)


def bars(rows, *, inst=INST, start=T0, interval=H, latency=0):
    return [c.Bar(inst_id=inst.inst_id, interval_ms=interval, open_ms=start + i * interval,
                  close_ms=start + (i + 1) * interval, available_ms=start + (i + 1) * interval + latency,
                  is_closed=True, open=D(o), high=D(h), low=D(lo), close=D(cl), volume_quote=D('1000'))
            for i, (o, h, lo, cl) in enumerate(rows)]


FLAT = ('100', '100.5', '99.5', '100')


class Scripted:
    """Management policy for tests: ``rule(state)`` returns (kind, params) or None for HOLD."""

    def __init__(self, rule=None, policy_id='scripted'):
        self.policy = s.policy_ref(policy_id)
        self.rule = rule or (lambda state: None)
        self.states: list[c.PositionState] = []

    def decide(self, state):
        self.states.append(state)
        kind, params = self.rule(state) or (c.ActionKind.HOLD, {})
        return c.AdaptiveAction.create(header=state.header, position_id=state.position_id, kind=kind,
                                       state=state.ref(), policy=self.policy, reasons=('scripted',), **params)


def run(rows, candidates, *, policy=None, g=None, **kwargs):
    return run_variant(experiment_id='exp-test', variant=c.Variant.B0, portfolio_id='pf-B0',
                       bars=kwargs.pop('bar_list', None) or bars(rows), candidates=candidates,
                       policy=policy or Scripted(), gate=g or gate(), **kwargs)


def one_lot_risk(g, entry='100', stop='99', side=LONG, inst=INST, hold=DAY):
    return g.risk_of(side, D(entry), D(stop), D('1'), inst.contract_value, hold)


# --------------------------------------------------------------------------------------------- entry admission

def test_per_trade_budget_is_half_a_percent_of_equity_cost_inclusive():
    no_collar = gate(assumptions=k.ExecutionAssumptions(entry_collar_rate=D('0')))
    decision = no_collar.admit_entry(cand(), None, view())
    assert decision.verdict is c.RiskVerdict.ADMIT and decision.price_reference == D('100')
    # cost-inclusive loss of one contract (CanonicalRiskEngine components): gross 0.01 + entry/stop fees and
    # slippage + funding provision 0.0001 x 3 intervals of 8 h -> 0.01229; 2.5 / 0.01229 -> 203 contracts.
    assert one_lot_risk(no_collar) == D('0.01229')
    assert decision.quantity == D('203')
    assert decision.risk_amount == D('203') * D('0.01229') <= D('2.5') < decision.risk_amount + D('0.01229')
    assert decision.open_risk_after == decision.risk_amount and decision.leverage_after <= 1
    parts = k.stop_loss_components(LONG, D('100'), D('99'), D('2.03'), no_collar.rates, 3)
    assert parts['total'] == decision.risk_amount and parts['gross'] == D('2.03')
    # default: sized at the IOC collar 100.1 (10 bp DRAFT), so any fill up to the collar keeps risk <= 2.5
    g = gate()
    collared = g.admit_entry(cand(), None, view())
    assert collared.price_reference == D('100.1') and collared.quantity == D('188')
    assert collared.risk_amount == one_lot_risk(g, entry='100.1') * 188 <= D('2.5')
    short = gate().admit_entry(cand(stop='101', side=SHORT), None, view())
    assert short.price_reference == D('99.9') and short.risk_amount <= D('2.5')


def test_total_open_risk_is_capped_at_one_percent():
    g = gate()
    capped = g.admit_entry(cand(), None, view(open_risk='4', ids=('pos-x',)), open_instruments=('OTHER',))
    assert capped.verdict is c.RiskVerdict.ADMIT and 'cap_total_risk' in capped.reasons
    assert capped.risk_amount <= D('1') and capped.open_risk_after <= D('5')
    full = gate().admit_entry(cand(), None, view(open_risk='5', ids=('pos-x',)), open_instruments=('OTHER',))
    assert full.verdict is c.RiskVerdict.REJECT and full.reasons == ('total_risk_budget_exhausted',)


def test_leverage_one_x_caps_portfolio_notional_at_equity():
    tight = gate().admit_entry(cand(stop='99.99'), None, view())
    assert tight.verdict is c.RiskVerdict.ADMIT and 'cap_leverage' in tight.reasons
    assert tight.notional_after <= D('500') and tight.leverage_after <= 1
    assert tight.risk_amount < D('2.5')
    exhausted = gate().admit_entry(cand(), None, view(open_notional='500', ids=('p',)), open_instruments=())
    assert exhausted.reasons == ('leverage_cap_exhausted',)


def test_margin_uses_cash_not_unrealized_gains():
    capped = gate().admit_entry(cand(stop='99.99'), None, view(equity='500', cash='100'))
    assert 'cap_margin' in capped.reasons
    assert capped.quantity * D('0.01') * D('100') * (1 + D('0.0005') + D('0.0005')) <= D('100')
    none = gate().admit_entry(cand(), None, view(cash='200', open_notional='200', ids=('p',)), open_instruments=())
    assert none.reasons == ('margin_insufficient',)


def test_quantity_is_floored_to_the_lot_grid_and_minimum_size_refused():
    lots = s.instrument(contract_value='0.01', tick_size='0.01', lot_size='10', min_size='20')
    decision = gate().admit_entry(cand(inst=lots), None, view())
    assert decision.quantity == D('180') and decision.quantity % D('10') == 0
    small = gate().admit_entry(cand(inst=lots), None, view(equity='20'))
    assert small.verdict is c.RiskVerdict.REJECT and small.reasons[0] == 'quantity_below_min_size'


def test_minimum_notional_is_refused():
    inst = s.instrument(contract_value='0.01', min_notional='1000')
    assert gate().admit_entry(cand(inst=inst), None, view()).reasons == ('notional_below_min_notional',)


def test_stop_is_mandatory_and_protective():
    with pytest.raises(c.ContractError, match='h3_candidate_stop_not_protective'):
        cand(stop='101')
    stub = types.SimpleNamespace(header=cand().header, candidate_id='cand-stub', entry_reference_price=D('100'),
                                 initial_stop_price=None, max_holding_ms=DAY,
                                 fingerprint=lambda: 'sha256:' + '1' * 64)
    decision = gate().admit_entry(stub, None, view())
    assert decision.verdict is c.RiskVerdict.REJECT and decision.reasons == ('stop_missing',)
    with pytest.raises(c.ContractError, match='h3_risk_admit'):
        dataclasses.replace(decision, verdict=c.RiskVerdict.ADMIT, reasons=(), quantity=D('1'), stop_price=None,
                            risk_amount=D('1'))


def test_repeated_admission_is_idempotent_and_conflicting_ids_refused():
    g = gate()
    first = g.admit_entry(cand(), None, view())
    again = g.admit_entry(cand(), None, view(open_risk='4.9', ids=('p',)), open_instruments=(INST.inst_id,))
    assert again is first and again.verdict is c.RiskVerdict.ADMIT
    other = g.admit_entry(cand(stop='98'), None, view())
    assert other.reasons == ('candidate_id_conflict',) and other.decision_id != first.decision_id
    result = run([FLAT] * 4, [cand(), cand()])
    assert len(result.trades) == 1
    assert [r.reasons for r in result.refusals] == [('decision_already_applied',)]


def test_instrument_already_open_and_unknown_occupancy_fail_closed():
    assert gate().admit_entry(cand(), None, view(), open_instruments=(INST.inst_id,)).reasons == (
        'instrument_already_open',)
    assert gate().admit_entry(cand(), None, view(ids=('pos-a',))).reasons == ('portfolio_occupancy_unknown',)


def test_score_gating_and_structural_refusals():
    def score(verdict, cid='cand-1', reasons=('model_uncalibrated',)):
        return c.OpportunityScore(header=s.header(), candidate_id=cid, snapshot=cand().snapshot,
                                  model=c.ModelRef(model_id='null', model_version='0', model_fingerprint=None),
                                  status=c.ScoreStatus.UNESTIMATED, verdict=verdict, expected_net_r=None,
                                  p_stop=None, tail_loss_r=None, uncertainty=D('1'), data_quality=D('1'),
                                  utility=None, rank=None, reasons=reasons)
    assert gate().admit_entry(cand(), score(c.ScoreVerdict.NO_TRADE), view()).reasons == ('score_verdict_no_trade',)
    assert gate().admit_entry(cand(), score(c.ScoreVerdict.NO_TRADE, cid='x'), view()).reasons == (
        'score_candidate_mismatch',)
    scored = gate().admit_entry(cand(), score(c.ScoreVerdict.TRADE_CANDIDATE, reasons=()), view())
    assert scored.quantity == gate().admit_entry(cand(), None, view()).quantity  # a score never changes the size
    assert gate().admit_entry(cand(decision_ms=T0 + 2 * H), None, view()).reasons == ('candidate_from_future',)
    assert gate(max_candidate_age_ms=0).admit_entry(cand(decision_ms=T0), None, view()).reasons == (
        'candidate_stale',)
    assert gate().admit_entry(cand(), None, view(quote='USDT')).reasons == ('settlement_currency_mismatch',)
    assert 'equity_non_positive' in gate().admit_entry(cand(), None, view(equity='0', cash='1')).reasons


def test_fill_recheck_refuses_a_fill_beyond_the_limits():
    g = gate()
    admitted = g.admit_entry(cand(), None, view())
    worse = g.admit_fill(admitted, cand(), D('100.5'), view())
    assert worse.verdict is c.RiskVerdict.REJECT and worse.reasons == ('fill_beyond_collar',)
    at_collar = g.admit_fill(admitted, cand(), D('100.1'), view())
    assert at_collar.verdict is c.RiskVerdict.ADMIT and at_collar.risk_amount == admitted.risk_amount
    assert g.admit_fill(admitted, cand(), D('100.1'), view(equity='450', cash='450')) is at_collar  # replay
    fresh = gate()
    poorer = fresh.admit_fill(fresh.admit_entry(cand(), None, view()), cand(), D('100.1'),
                              view(equity='450', cash='450'))
    assert 'fill_risk_above_per_trade' in poorer.reasons  # equity fell between decision and fill
    through = g.admit_fill(admitted, cand(), D('98.9'), view())
    assert through.reasons == ('fill_beyond_stop',)
    better = g.admit_fill(admitted, cand(), D('99.9'), view())
    assert better.verdict is c.RiskVerdict.ADMIT and better.risk_amount < admitted.risk_amount
    assert g.admit_fill(admitted, cand(), D('99.9'), view()) is better
    assert gate().admit_fill(admitted, cand(), D('100'), view()).reasons == ('fill_without_admission',)


# ------------------------------------------------------------------------------------------- action validation

POLICY = s.policy_ref('adaptive-test')


def state(g, *, stop='99', qty='200', last='100.5', status=c.PositionStatus.OPEN, applied=(), kill=False,
          pid='pf-B0'):
    quantity = D(qty) if status is c.PositionStatus.OPEN else D('0')
    risk = g.risk_of(LONG, D('100'), D(stop), quantity, INST.contract_value, DAY)
    return c.PositionState(
        header=s.header(side=LONG, portfolio_id=pid, decision_ms=T0 + 2 * H, inst=INST), position_id='pos-1',
        candidate_id='cand-1', status=status, entry_price=D('100'), entry_ms=T0 + H, initial_quantity=D('200'),
        open_quantity=quantity, initial_stop=D('99'), current_stop=D(stop), current_target=None,
        last_price=D(last), mfe_price=max(D(last), D('100')), mae_price=D('99.8'), holding_ms=H,
        max_holding_ms=DAY, volatility=None, liquidity=c.LiquidityStatus.UNKNOWN, funding_paid=D('0'),
        realized_pnl=D('0'), initial_risk_amount=g.risk_of(LONG, D('100'), D('99'), D('200'), D('0.01'), DAY),
        equity=D('500'), open_risk_total=risk, risk_budget_remaining=D('5') - risk, actions_applied=applied,
        policy=POLICY, data_status=c.DataStatus.COMPLETE, kill_switch=kill)


def act(st, kind, **params):
    return c.AdaptiveAction.create(header=st.header, position_id=st.position_id, kind=kind, state=st.ref(),
                                   policy=st.policy, reasons=('test',), **params)


def pview(st, kill=False):
    return view(open_risk=str(st.open_risk_total), open_notional=str(st.open_quantity * D('0.01') * D('100')),
                ids=('pos-1',) if st.status is c.PositionStatus.OPEN else (), kill=kill, decision_ms=T0 + 2 * H)


@pytest.mark.parametrize('kind,params,expected', [
    (c.ActionKind.HOLD, {}, ()),
    (c.ActionKind.TIGHTEN_STOP, {'new_stop': '99.5'}, ()),
    (c.ActionKind.TIGHTEN_STOP, {'new_stop': '98.5'}, ('stop_not_tightened',)),
    (c.ActionKind.TIGHTEN_STOP, {'new_stop': '99'}, ('stop_not_tightened',)),
    (c.ActionKind.TIGHTEN_STOP, {'new_stop': '100.6'}, ('stop_crosses_last_price',)),
    (c.ActionKind.TIGHTEN_STOP, {'new_stop': '99.505'}, ('stop_not_on_tick',)),
    (c.ActionKind.ADJUST_TARGET, {'new_target': '101'}, ()),
    (c.ActionKind.ADJUST_TARGET, {'new_target': '100.4'}, ('target_not_beyond_last_price',)),
    (c.ActionKind.PARTIAL_CLOSE, {'close_quantity': '100'}, ()),
    (c.ActionKind.PARTIAL_CLOSE, {'close_quantity': '200'}, ('partial_close_not_partial',)),
    (c.ActionKind.PARTIAL_CLOSE, {'close_quantity': '100.5'}, ('quantity_not_on_lot',)),
    (c.ActionKind.FULL_CLOSE, {}, ()),
])
def test_actions_are_checked_and_never_increase_risk_or_notional(kind, params, expected):
    g = gate()
    st = state(g)
    decision = g.validate_action(act(st, kind, **params), st, pview(st))
    assert decision.reasons == expected
    assert decision.verdict is (c.RiskVerdict.REJECT if expected else c.RiskVerdict.ADMIT)
    assert decision.open_risk_after <= decision.open_risk_before
    assert decision.notional_after <= decision.notional_before
    if kind is c.ActionKind.FULL_CLOSE:
        assert decision.risk_amount == 0 and decision.open_risk_after == 0
    if kind is c.ActionKind.TIGHTEN_STOP and not expected:
        assert decision.open_risk_after < decision.open_risk_before


def test_action_identity_and_lifecycle_checks():
    g = gate()
    st, other = state(g), state(g, last='100.4')
    assert g.validate_action(act(other, c.ActionKind.FULL_CLOSE), st, pview(st)).reasons == ('action_state_mismatch',)
    closed = state(g, status=c.PositionStatus.CLOSED)
    assert 'position_not_open' in g.validate_action(act(closed, c.ActionKind.FULL_CLOSE), closed,
                                                    pview(closed)).reasons
    action = act(st, c.ActionKind.TIGHTEN_STOP, new_stop='99.5')
    applied = state(g, applied=(action.action_id,))  # the same action submitted again after it was applied
    assert {'action_already_applied', 'action_state_mismatch'} <= set(
        g.validate_action(action, applied, pview(applied)).reasons)
    first = g.validate_action(action, st, pview(st))
    assert g.validate_action(action, st, pview(st)) is first  # same idempotency key: same decision
    small = s.instrument(contract_value='0.01', lot_size='1', min_size='150')
    st_small = dataclasses.replace(st, header=dataclasses.replace(st.header, instrument=small))
    assert 'remaining_below_min_size' in g.validate_action(
        act(st_small, c.ActionKind.PARTIAL_CLOSE, close_quantity='100'), st_small, pview(st_small)).reasons
    assert 'portfolio_mismatch' in g.validate_action(act(st, c.ActionKind.HOLD), st,
                                                     dataclasses.replace(pview(st), portfolio_id='pf-B1')).reasons


def test_kill_switch_allows_only_risk_reducing_actions():
    g = gate()
    st = state(g, kill=True)
    for kind, params in ((c.ActionKind.TIGHTEN_STOP, {'new_stop': '99.5'}),
                         (c.ActionKind.PARTIAL_CLOSE, {'close_quantity': '100'}), (c.ActionKind.FULL_CLOSE, {})):
        assert g.validate_action(act(st, kind, **params), st, pview(st, kill=True)).verdict is c.RiskVerdict.ADMIT
    target = g.validate_action(act(st, c.ActionKind.ADJUST_TARGET, new_target='101'), st, pview(st, kill=True))
    assert target.reasons == ('kill_switch_active',)
    assert gate().admit_entry(cand(), None, view(kill=True)).reasons == ('kill_switch_active',)


def test_risk_reduction_stays_admissible_after_equity_fell():
    g = gate()
    st = state(g)
    fallen = dataclasses.replace(pview(st), equity=D('200'), cash=D('200'))
    assert st.open_risk_total > D('0.01') * D('200')
    assert g.validate_action(act(st, c.ActionKind.TIGHTEN_STOP, new_stop='99.5'), st, fallen).verdict \
        is c.RiskVerdict.ADMIT
    assert gate().admit_entry(cand(inst=s.instrument('ALT')), None, fallen,
                              open_instruments=(INST.inst_id,)).reasons == ('total_risk_budget_exhausted',)


# ------------------------------------------------------------------------------------------------- simulator

def test_entry_fills_at_the_first_open_after_the_decision_and_stop_exits_inside_the_bar():
    result = run([FLAT, FLAT, ('100', '100.4', '98.5', '99')], [cand()])
    (trade,) = result.trades
    assert trade.entry_ms == T0 + H and trade.entry_price == D('100') and trade.exit_reason == 'stop'
    assert trade.exits[0].price == D('99') and trade.loss_beyond_nominal == 0 and not trade.gap_through_stop
    assert trade.net_pnl == trade.gross_pnl - trade.fees - trade.spread_cost - trade.slippage_cost + trade.funding
    assert -1 < trade.realized_r < 0  # the funding provision is part of R but is never charged


def test_gap_through_stop_loss_is_measured_separately_and_never_clipped():
    result = run([FLAT, FLAT, ('95', '95.5', '94', '95')], [cand()])
    (trade,) = result.trades
    assert trade.exit_reason == 'stop_gap' and trade.exits[0].price == D('95') and trade.gap_through_stop
    units = trade.quantity * INST.contract_value
    assert trade.gap_loss == units * D('4')
    assert -trade.net_pnl > trade.nominal_risk
    assert trade.loss_beyond_nominal == -trade.net_pnl - trade.nominal_risk > 0
    assert trade.realized_r < -1
    summary = result.summary()
    assert summary['trades_beyond_nominal'] == 1 and D(summary['loss_beyond_nominal_total']) == trade.loss_beyond_nominal


def test_stop_wins_when_stop_and_target_are_touched_in_one_bar():
    result = run([FLAT, FLAT, ('100', '103', '98.9', '101')], [cand(target='102')])
    (trade,) = result.trades
    assert trade.exit_reason == 'stop' and 'stop_and_target_same_bar_stop_assumed' in trade.flags


def test_target_needs_a_trade_through_unless_touch_is_declared():
    rows = [FLAT, FLAT, ('100', '102', '99.9', '101'), ('101', '102.5', '100.5', '102')]
    assert run(rows, [cand(target='102')]).trades[0].exits[0].time_ms == T0 + 4 * H
    touch = gate(assumptions=k.ExecutionAssumptions(target_fill='touch'))
    (trade,) = run(rows, [cand(target='102')], g=touch).trades
    assert trade.exit_reason == 'target' and trade.exits[0].time_ms == T0 + 3 * H
    assert trade.exits[0].fee == trade.quantity * INST.contract_value * D('102') * D('0.0002')  # maker
    assert trade.exits[0].slippage == 0


def test_fees_and_funding_are_booked_once_and_the_wallet_reconciles():
    funding = [c.FundingObservation(inst_id=INST.inst_id, funding_ms=T0 + 2 * H + 1, available_ms=T0 + 2 * H + 1,
                                    rate=D('0.001')),
               c.FundingObservation(inst_id=INST.inst_id, funding_ms=T0 + 3 * H, available_ms=T0 + 3 * H,
                                    rate=D('-0.0005'))]
    rows = [FLAT, FLAT, ('100', '100.5', '99.5', '100.2'), ('100.2', '100.6', '99.9', '100.4')]
    result = run(rows, [cand()], funding=funding)
    (trade,) = result.trades
    units = trade.quantity * INST.contract_value
    # long pays a positive rate at the last known close (100), receives a negative one at 100.2
    assert trade.funding == -units * D('100') * D('0.001') + units * D('100.2') * D('0.0005')
    assert trade.fees == units * D('100') * D('0.0005') + units * D('100.4') * D('0.0005')
    assert trade.exit_reason == 'end_of_data'
    assert result.final_cash == result.initial_equity + trade.net_pnl
    assert trade.net_pnl == trade.gross_pnl - trade.fees - trade.slippage_cost - trade.spread_cost + trade.funding
    short = run(rows, [cand(stop='101', side=SHORT)], funding=funding[:1]).trades[0]
    assert short.funding == short.quantity * INST.contract_value * D('100') * D('0.001') > 0


@pytest.mark.parametrize('rate,charged', [('0.001', True), ('-0.001', False)])
def test_funding_at_the_entry_instant_is_adverse_possible_credit_dropped(rate, charged):
    funding = [c.FundingObservation(inst_id=INST.inst_id, funding_ms=T0 + H, available_ms=T0 + H, rate=D(rate))]
    (trade,) = run([FLAT, FLAT, FLAT], [cand()], funding=funding).trades
    assert (trade.funding != 0) is charged and 'funding_path_ambiguous' in trade.flags
    if charged:
        assert trade.funding < 0


def test_liquidation_is_unreachable_for_a_long_at_one_x_and_needs_a_doubling_for_a_short():
    assert k.liquidation_price(LONG, D('100'), D('1'), D('0.005')) == 0
    assert k.liquidation_price(SHORT, D('100'), D('1'), D('0.005')) == D('200') / D('1.005')
    crash = run([FLAT, FLAT, ('1', '1', '0.5', '0.6')], [cand()])
    assert crash.trades[0].exit_reason == 'stop_gap' and not crash.trades[0].liquidated
    squeeze = run([FLAT, FLAT, ('210', '215', '205', '212')], [cand(stop='101', side=SHORT)])
    (trade,) = squeeze.trades
    assert trade.liquidated and trade.exit_reason == 'liquidation_proxy'
    assert trade.loss_beyond_nominal > 0 and squeeze.summary()['liquidations'] == 1


def test_injected_entry_failures():
    rejected = run([FLAT] * 4, [cand(), cand(cid='cand-2', decision_ms=T0 + 2 * H)],
                   failures=FailurePlan((InjectedFailure(FailureKind.ORDER_REJECTED, 'cand-1'),)))
    assert [t.candidate_id for t in rejected.trades] == ['cand-2']  # reservation released, instrument free again
    assert ('entry_order_rejected',) in [r.reasons for r in rejected.refusals]
    lost = run([FLAT] * 3, [cand()], failures=FailurePlan((InjectedFailure(FailureKind.NETWORK_ERROR, 'cand-1'),)))
    assert not lost.trades and lost.refusals[0].reasons == ('entry_network_error', 'reconciled_not_filled')
    venue = run([FLAT] * 3, [cand()], failures=FailurePlan((
        InjectedFailure(FailureKind.NETWORK_ERROR, 'cand-1', filled_on_venue=True),)))
    assert len(venue.trades) == 1 and 'reconciled_after_network_error' in venue.trades[0].flags


def test_failed_stop_attachment_is_closed_by_the_fail_safe_and_its_loss_measured():
    rows = [FLAT, ('100', '100.2', '97', '97.5'), ('97', '97.2', '96.8', '97')]
    alt = s.instrument('ALT')
    result = run(None, [cand(), cand(cid='cand-2', inst=alt, decision_ms=T0 + 2 * H)],
                 bar_list=bars(rows) + bars([FLAT] * 3, inst=alt),
                 failures=FailurePlan((InjectedFailure(FailureKind.PROTECTION_FAILED, 'cand-1'),)))
    assert [r.reasons for r in result.refusals] == [('total_risk_budget_exhausted',)]  # unprotected = full notional
    (trade,) = result.trades
    assert trade.exit_reason == 'fail_safe_unprotected' and trade.exits[0].price == D('97')
    assert 'protection_failed' in trade.flags and trade.loss_beyond_nominal > 0


def tighten_once(new_stop):
    calls: list[int] = []

    def rule(st):
        calls.append(1)
        return (c.ActionKind.TIGHTEN_STOP, {'new_stop': new_stop}) if len(calls) == 1 else None
    return rule


def test_stop_modification_failure_keeps_the_previous_stop():
    rows = [FLAT, ('100', '100.8', '99.8', '100.5'), ('100.5', '100.6', '99.4', '99.6'), ('99.6', '99.7', '98.8', '99')]
    ok = run(rows, [cand()], policy=Scripted(tighten_once('99.5')))
    assert ok.trades[0].exits[0].price == D('99.5') and ok.trades[0].final_stop == D('99.5')
    failed = run(rows, [cand()], policy=Scripted(tighten_once('99.5')), failures=FailurePlan((
        InjectedFailure(FailureKind.MODIFICATION_FAILED, 'cand-1', action_kind=c.ActionKind.TIGHTEN_STOP),)))
    (trade,) = failed.trades
    assert trade.final_stop == D('99') and trade.exits[0].price == D('99')
    assert ('modification_failed_previous_order_kept',) in [r.reasons for r in failed.refusals]


def test_action_arriving_after_the_stop_filled_is_refused():
    policy = Scripted(lambda st: (c.ActionKind.FULL_CLOSE, {}))
    result = run([FLAT, FLAT, ('98', '98.5', '97.5', '98')], [cand()], policy=policy)
    assert result.trades[0].exit_reason == 'stop_gap'
    assert ('position_already_closed',) in [r.reasons for r in result.refusals]


def test_partial_close_reduces_open_risk_and_every_admitted_action_reduces_or_keeps_it():
    def rule(st):
        if st.open_quantity == st.initial_quantity:
            return c.ActionKind.PARTIAL_CLOSE, {'close_quantity': str(st.open_quantity // 2)}
        return None
    result = run([FLAT, FLAT, ('100', '100.6', '99.6', '100.3'), ('100.3', '100.7', '100', '100.5')], [cand()],
                 policy=Scripted(rule))
    (trade,) = result.trades
    assert [e.reason for e in trade.exits] == ['partial_close', 'end_of_data']
    assert sum(e.quantity for e in trade.exits) == trade.quantity
    actions = [d for d in result.risk_decisions if d.subject is c.RiskSubject.ACTION]
    assert actions and all(d.open_risk_after <= d.open_risk_before and d.notional_after <= d.notional_before
                           for d in actions)
    risk = [p.open_risk for p in result.equity_curve]
    assert risk[2] < risk[1]


def test_policy_sees_only_data_available_at_the_decision():
    rows = [FLAT, ('100', '101', '99.6', '100.6'), ('100', '103', '99.9', '102.5'), FLAT]
    policy = Scripted()
    result = run(None, [cand(decision_ms=T0 + H + 60_000)], policy=policy, bar_list=bars(rows, latency=60_000))
    assert result.trades[0].entry_ms == T0 + 2 * H  # available at +1 min: the order meets the next open
    for st in policy.states:
        clock = st.header.clock
        usable = [b for b in bars(rows, latency=60_000) if b.available_ms <= clock.decision_ms]
        assert st.last_price == usable[-1].close and clock.observed_ms == usable[-1].close_ms
        assert st.mfe_price <= max(b.high for b in usable)


def test_kill_switch_blocks_new_entries_and_can_flatten():
    rows = [FLAT] * 5
    later = cand(cid='cand-2', inst=s.instrument('ALT'), decision_ms=T0 + 3 * H)
    alt_bars = bars(rows) + bars(rows, inst=s.instrument('ALT'))
    kept = run(None, [cand(), later], bar_list=alt_bars, kill_switch_at_ms=T0 + 2 * H)
    assert [r.reasons for r in kept.refusals] == [('kill_switch_active',)]
    assert kept.trades[0].exit_reason == 'end_of_data'
    flat = run(None, [cand(), later], bar_list=alt_bars, kill_switch_at_ms=T0 + 2 * H, flatten_on_kill_switch=True)
    assert flat.trades[0].exit_reason == 'kill_switch_flatten' and flat.trades[0].exit_ms == T0 + 2 * H
    assert any(r.event is c.AuditEvent.KILL_SWITCH for r in flat.audit.records)


def test_max_holding_closes_at_the_first_open_after_expiry():
    (trade,) = run([FLAT] * 6, [cand(hold=2 * H)]).trades
    assert trade.exit_reason == 'max_holding' and trade.exit_ms == T0 + 3 * H


def random_walk(rng, n, start='100'):
    price, rows = D(start), []
    for _ in range(n):
        step = D(rng.randint(-20, 20)) / D('10000')
        close = (price * (1 + step)).quantize(D('0.01'))
        rows.append((str(price), str(max(price, close) + D('0.05')), str(min(price, close) - D('0.05')), str(close)))
        price = close
    return rows


def test_synthetic_portfolio_invariants_without_gaps():
    rng = random.Random(20261015)
    insts = [s.instrument(name) for name in ('AAA', 'BBB', 'CCC', 'DDD')]
    walks = {i.inst_id: random_walk(rng, 120) for i in insts}
    all_bars = [b for i in insts for b in bars(walks[i.inst_id], inst=i)]
    provider = SyntheticCandidateProvider(seed=7, max_holding_ms=12 * H, target_r_multiple=D('2'))
    candidates = []
    for step in range(1, 120, 6):
        for i in insts:
            close = D(walks[i.inst_id][step - 1][3])
            snap = synthetic_snapshot(inst=i, decision_ms=T0 + step * H, reference_price=close,
                                      stop_distance=close / D('100'), provenance=s.SYNTHETIC_PROVENANCE,
                                      protocol=s.DRAFT_PROTOCOL)
            candidates.extend(provider.candidates([snap], T0 + step * H))
    trail = Scripted(lambda st: (c.ActionKind.TIGHTEN_STOP, {'new_stop': str(st.entry_price)})
                     if (st.last_price - st.entry_price) * k.direction(st.header.side) > abs(
                         st.entry_price - st.initial_stop) and st.current_stop != st.entry_price else None)
    result = run(None, candidates, bar_list=all_bars, policy=trail)
    assert len(result.trades) > 10
    entries = [d for d in result.risk_decisions if d.subject is c.RiskSubject.ENTRY and d.verdict is c.RiskVerdict.ADMIT]
    assert all(d.risk_amount <= D('0.005') * d.equity and d.open_risk_after <= D('0.01') * d.equity
               and d.leverage_after <= 1 for d in entries)
    assert result.gap_loss_total == 0 and result.loss_beyond_nominal_total == 0  # no gap: R is never exceeded
    assert any(d.subject is c.RiskSubject.ACTION and d.verdict is c.RiskVerdict.ADMIT for d in result.risk_decisions)
    # leverage <= 1 is enforced at admission; between admissions it moves with marks (reported, not capped)
    assert result.max_leverage > 0
    assert result.final_cash == result.initial_equity + result.net_pnl
    assert not result.audit.verify()


def test_runs_are_deterministic_and_the_audit_chain_detects_tampering():
    def once():
        return run([FLAT, FLAT, ('95', '95.5', '94', '95')], [cand()])
    first, second = once(), once()
    assert first.summary() == second.summary() and first.audit.head_fingerprint() == second.audit.head_fingerprint()
    text = first.audit.dump_jsonl()
    rebuilt, problems = AuditJournal.load_jsonl(text, 'exp-test', c.Variant.B0)
    assert not problems and rebuilt.head_fingerprint() == first.audit.head_fingerprint()
    lines = text.splitlines()
    assert AuditJournal.load_jsonl('\n'.join(lines[:2] + lines[3:]), 'exp-test', c.Variant.B0)[1]
    assert AuditJournal.load_jsonl('\n'.join([lines[1], lines[0]] + lines[2:]), 'exp-test', c.Variant.B0)[1]
    edited = lines[2].replace('"subject_id":"', '"subject_id":"x', 1)
    assert AuditJournal.load_jsonl('\n'.join(lines[:2] + [edited] + lines[3:]), 'exp-test', c.Variant.B0)[1]
    assert AuditJournal.load_jsonl(text, 'exp-test', c.Variant.B1)[1] == ('h3_audit_scope_mismatch',)
    events = [r.event for r in first.audit.records]
    assert events[:2] == [c.AuditEvent.CANDIDATE, c.AuditEvent.RISK] and c.AuditEvent.EXIT in events


def test_audit_journal_is_append_only():
    journal = AuditJournal('exp-test', c.Variant.B2)
    record = journal.record_contract(header=s.header(portfolio_id='pf-B2'), event=c.AuditEvent.CANDIDATE,
                                     subject=cand(), subject_id='cand-1')
    with pytest.raises(c.ContractError, match='h3_audit_append_out_of_order'):
        journal.append(record)
    with pytest.raises(c.ContractError, match='h3_audit_scope_mismatch'):
        AuditJournal('exp-test', c.Variant.B3).append(record)
    assert journal.verify() == () and len(journal) == 1


def test_bar_inputs_are_validated():
    with pytest.raises(c.ContractError, match='h3_sim_latency_exceeds_interval'):
        run(None, [cand()], bar_list=bars([FLAT], latency=H))
    with pytest.raises(c.ContractError, match='h3_sim_bar_not_closed'):
        run(None, [cand()], bar_list=[dataclasses.replace(bars([FLAT])[0], is_closed=False)])
    with pytest.raises(c.ContractError, match='h3_sim_interval_mismatch'):
        run(None, [cand()], bar_list=bars([FLAT]) + bars([FLAT], inst=s.instrument('ALT'), interval=2 * H))
    result = run(None, [cand(inst=s.instrument('NODATA'))], bar_list=bars([FLAT, FLAT]))
    assert result.refusals[0].reasons == ('no_market_data',)


# -------------------------------------------------------------------------------------------- candidates / map

def test_synthetic_candidate_provider_is_deterministic_and_makes_no_claim():
    snaps = [synthetic_snapshot(inst=s.instrument(name), decision_ms=T0, reference_price='100.004',
                                stop_distance='0.333', provenance=s.SYNTHETIC_PROVENANCE, protocol=s.DRAFT_PROTOCOL)
             for name in ('AAA', 'BBB', 'CCC', 'DDD', 'EEE', 'FFF')]
    provider = SyntheticCandidateProvider(seed=3)
    out = provider.candidates(snaps, T0)
    assert out == provider.candidates(list(reversed(snaps)), T0)
    assert {x.hypothesis_id for x in out} == {'synthetic-no-economic-claim'} and len(out) == 6
    assert {x.header.side for x in out} == {LONG, SHORT}
    for x in out:
        assert x.entry_reference_price == D('100') and x.stop_distance >= D('0.33')
        assert x.initial_stop_price % D('0.01') == 0 and x.header.portfolio_id is None
    assert provider.candidates(snaps, T0 - 1) == ()


def test_tradingcore_map_matches_the_contracts_and_the_php_sources():
    contracts = {k_.__name__: {f.name for f in dataclasses.fields(k_)} for k_ in c.CONTRACTS}
    for row in tm.FIELD_MAP:
        assert row.h3_field in contracts[row.h3_contract], row
        assert (row.php_class is None) == (row.php_field is None), row
    root = Path(__file__).resolve().parents[2]
    if not (root / 'trading-app').is_dir():
        pytest.skip('trading-app checkout absent')
    for row in tm.FIELD_MAP:
        if row.php_class is not None:
            source = (root / tm.PHP_CLASSES[row.php_class]).read_text()
            assert re.search(r'\$' + row.php_field + r'\b', source), row
    for gap in tm.BLOCKING_GAPS:
        assert all((root / path).exists() for path in gap.evidence), gap.gap_id
    assert {'GAP-XPERP', 'GAP-STOP-REPLACE', 'GAP-ADAPTIVE-TRANSITIONS'} <= set(tm.gap_ids())


# ---------------------------------------------- cross-tests of conventions with the research portfolio simulator

def _research_simulator():
    pytest.importorskip('pydantic')
    from app.backtesting.research import portfolio_simulator as ps
    return ps


def _research_run(ps, rows, *, leverage=1, funding=(), finish=True):
    """Drive app/backtesting/research/portfolio_simulator.py with one long plan (entry 100, stop 95, target 110)."""
    start, hash_ = 1672531200000, 'sha256:' + 'a' * 64
    symbol = ps.SYMBOLS[0]
    identity = {key: hash_ for key in ps.IDENTITY_FIELDS if key != 'variant_id'}
    identity['variant_id'] = 'h3-cross-test'
    source = {'dataset_id': 'synthetic', 'dataset_sha256': 'b' * 64, 'signal_run_id': 'synthetic',
              'signal_output_sha256': 'c' * 64}
    instrument = ps.InstrumentAssumptions(D('.01'), D('.001'), D('.001'), D('100000'), D('5'), D('1'), D('2'),
                                          D('.005'), D('.001'), '2026-10-09T00:00:00Z', 'd' * 64)
    cost = ps.CostAssumptions(D('.0002'), D('.0005'), D('.0002'), *([D('0')] * 6), D('.0001'), 28800, 1)
    config = ps.RunAssumptions(start, start + len(rows) * 60000, 'training', (symbol,), ((symbol, instrument),), cost,
                               tuple(identity.items()), ((symbol, tuple(source.items())),),
                               ps.FundingCoverage('verified_complete', hash_, ((symbol, len(funding)),)),
                               'adverse_possible_credit_certain.v1', 'last_known_close.v1')

    def signal(t):
        payload = {'schema_version': 'research-signal-result.v1', 'symbol': symbol, 'evaluated_ms': t, 'passed': True,
                   'baseline': {'setup_hash': hash_, 'config_hash': hash_, 'condition_catalog_hash': hash_,
                                'snapshot_hash': hash_},
                   'source': {'dataset_id': 'synthetic', 'dataset_sha256': 'b' * 64, 'source_venue': 'binance_usdm',
                              'source_network': 'mainnet', 'market_type': 'perpetual'}}
        payload['result_hash'] = ps.canonical_hash(payload)
        return ps.Signal(0, payload)

    def plan(sig, portfolio):
        q, entry, stop, target = D('2'), D('100'), D('95'), D('110')
        parts = {'gross_stop_loss': (entry - stop) * q, 'entry_fee': entry * q * cost.entry_fee_rate,
                 'stop_exit_fee': stop * q * cost.stop_fee_rate, 'entry_spread': D('0'), 'stop_spread': D('0'),
                 'entry_slippage': D('0'), 'stop_slippage': D('0'),
                 'funding_provision': entry * q * cost.funding_provision_rate}
        parts['total_stop_loss'] = sum(parts.values())
        reward = (target - entry) * q - parts['entry_fee'] - target * q * cost.target_fee_rate - parts['funding_provision']
        net_r = (reward / parts['total_stop_loss']).quantize(D('1e-18'), rounding='ROUND_DOWN')
        document = {'schema_version': 'research-plan.v1', 'research_only': True, 'execution_authority': 'none',
                    **identity, **source, 'source_venue': 'binance_usdm', 'source_network': 'mainnet',
                    'market_type': 'perpetual', 'signal_result_hash': sig.payload['result_hash'],
                    'signal_index': sig.index, 'symbol': symbol, 'evaluated_ms': sig.payload['evaluated_ms'],
                    'portfolio_hash': portfolio['portfolio_hash'], 'entry_price': 100, 'stop_price': 95.0,
                    'quantity': 2.0, 'position_notional_quote': 200.0, 'final_leverage': leverage,
                    'effective_leverage_cap': 2, 'risk_budget_quote': float(D(str(portfolio['equity_quote'])) * D('.05')),
                    'risk_components_quote': {key: float(v) for key, v in parts.items()},
                    'targets': [{'id': 't1', 'price': 110.0, 'net_r': float(net_r), 'net_reward_quote': float(reward),
                                 'net_risk_quote': float(parts['total_stop_loss'])}],
                    'entry_ttl_seconds': 90, 'cancel_after_seconds': 120,
                    'holding_deadline_ms': sig.payload['evaluated_ms'] + 28800000, 'holding_deadline_exclusive': True,
                    'instrument_math': instrument.wire(), 'cost_model': cost.wire()}
        document['plan_hash'] = ps.canonical_hash(document)
        return document

    ledger = {'trades': [], 'cashflows': []}
    sim = ps.PortfolioSimulator(config, plan, event_sink=lambda e: None, trade_sink=ledger['trades'].append,
                                cashflow_sink=ledger['cashflows'].append, rejection_sink=lambda r: None)
    sim.prime((ps.Candle(symbol, start - 60000, D('100'), D('101'), D('99'), D('100')),))
    for i, (o, h, lo, cl) in enumerate(rows):
        t = start + i * 60000
        events = tuple(ps.FundingEvent(symbol, t + offset, D(rate)) for at, offset, rate in funding if at == i)
        sim.advance((ps.Candle(symbol, t, D(o), D(h), D(lo), D(cl)),), funding=events,
                    signals=(signal(t + 60000),) if i == 0 else ())
    if finish:
        sim.finish()
    return ledger


def _h3_run(rows, *, funding=()):
    start = 1672531200000
    inst = s.instrument(contract_value='1', tick_size='0.01', lot_size='0.001', min_size='0.001')
    candidate = c.OpportunityCandidate(
        header=s.header(decision_ms=start + 60000, side=LONG, inst=inst), candidate_id='cand-x', generator_id='gen',
        generator_version='0.1.0', hypothesis_id=HYPOTHESIS_ID, snapshot=cand().snapshot,
        entry_reference_price=D('100'), initial_stop_price=D('95'), initial_target_price=D('110'), max_holding_ms=8 * H)
    observations = [c.FundingObservation(inst_id=inst.inst_id, funding_ms=start + at * 60000 + offset,
                                         available_ms=start + at * 60000 + offset, rate=D(rate))
                    for at, offset, rate in funding]
    return run(None, [candidate], bar_list=bars(rows, inst=inst, start=start, interval=60000),
               funding=observations).trades[0]


def test_cross_gap_through_stop_fills_at_the_open_in_both_simulators():
    ps = _research_simulator()
    rows = [FLAT, ('100', '101', '99', '100'), ('90', '91', '89', '90')]
    theirs = _research_run(ps, rows)['trades'][0]
    mine = _h3_run(rows)
    assert theirs['exit_reason'] == 'stop' and mine.exit_reason == 'stop_gap'
    assert mine.exits[0].price == theirs['exit_price'] == D('90')
    assert mine.gross_pnl / (mine.quantity * 1) == theirs['gross_pnl_quote'] / theirs['quantity'] == D('-10')


def test_cross_stop_before_target_in_the_same_bar_in_both_simulators():
    ps = _research_simulator()
    rows = [FLAT, ('100', '101', '99', '100'), ('100', '111', '94', '100')]
    theirs = _research_run(ps, rows)['trades'][0]
    mine = _h3_run(rows)
    assert theirs['exit_reason'] == mine.exit_reason == 'stop' and theirs['ambiguous']
    assert mine.exits[0].price == theirs['exit_price'] == D('95')


def test_cross_funding_sign_and_mark_in_both_simulators():
    ps = _research_simulator()
    rows = [FLAT, ('100', '101', '99', '100'), ('100', '101', '99', '100'), ('100', '101', '99', '100')]
    funding = ((2, 30001, '0.001'),)
    flows = [x for x in _research_run(ps, rows, funding=funding)['cashflows'] if x['kind'] == 'funding']
    mine = _h3_run(rows, funding=funding)
    assert flows[0]['amount_quote'] / 2 == mine.funding / mine.quantity == D('-0.1')


def test_cross_isolated_liquidation_proxy_formula():
    ps = _research_simulator()
    rows = [FLAT, ('100', '101', '99', '100'), ('100', '101', '40', '60')]
    # finish() is skipped: after a liquidation-proxy exit at the non-terminating proxy price the research simulator's
    # wallet check fails by 1e-23 (28-digit Decimal context); see H3-RISK-AND-EXECUTION.md, divergences.
    theirs = _research_run(ps, rows, leverage=2, finish=False)['trades'][0]
    assert theirs['exit_reason'] == 'liquidation_proxy'
    assert theirs['exit_price'] == k.liquidation_price(LONG, D('100'), D('2'), D('.005'))
