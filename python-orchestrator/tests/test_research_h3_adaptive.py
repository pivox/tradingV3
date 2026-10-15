"""H3-B adaptive position management: state machine, DRAFT policy V1, guards and the owner's B6 failure scenarios.

Synthetic values only (no market data, no network, no order). Fills (gap through a stop, stop and target in the same
bar, partial fills) are simulated by the integration package; here the policy side of those scenarios is tested: the
action proposed for a snapshot that already shows them.
Run: python -m pytest --noconftest tests/test_research_h3_adaptive.py tests/test_research_h3_contracts.py
"""
from __future__ import annotations

import ast
import dataclasses
import pathlib
import random
from decimal import Decimal as D

import pytest

from scripts.research_h3 import contracts as c
from scripts.research_h3 import synthetic as s
from scripts.research_h3.adaptive import guards as g
from scripts.research_h3.adaptive import params as pm
from scripts.research_h3.adaptive import policy as pol
from scripts.research_h3.adaptive import states as st

H = s.HOUR_MS
LONG, SHORT = c.Side.LONG, c.Side.SHORT
SIDES = [LONG, SHORT]
V1 = pol.AdaptivePolicyV1()
STATIC = pol.StaticPolicy()
K = c.ActionKind


def px(side, offset_r, *, entry='100', r='1'):
    """Price at ``offset_r`` R in the position's favour (negative: adverse)."""
    sign = 1 if side is LONG else -1
    return D(entry) + sign * D(str(offset_r)) * D(r)


def pos(side=LONG, *, last=0.5, mfe=0.8, mae=0.4, stop=-1, target=None, held_ms=2 * H, max_ms=24 * H,
        open_qty='250', initial_qty='250', inst=None, policy=None, data_status=c.DataStatus.COMPLETE,
        observed_ms=None, kill=False, liquidity=c.LiquidityStatus.NORMAL, actions=(), budget='2.5',
        volatility=None, open_risk=None):
    """A PositionState with entry 100 and R = 1 (initial stop 99 long / 101 short). Offsets are in R, favourable > 0;
    ``mae`` is the adverse excursion as a positive number of R."""
    decision = s.T0_MS + held_ms
    inst = inst or s.instrument()
    open_q, init_q = D(open_qty), D(initial_qty)
    stop_price = px(side, stop)
    risk = g.risk_at_stop(side, D('100'), stop_price, open_q, inst.contract_value)
    return c.PositionState(
        header=s.header(side=side, portfolio_id='pf-B2', decision_ms=decision, inst=inst, observed_ms=observed_ms),
        position_id='pos-1', candidate_id='cand-1',
        status=c.PositionStatus.CLOSED if open_q == 0 else c.PositionStatus.OPEN,
        entry_price=D('100'), entry_ms=s.T0_MS, initial_quantity=init_q, open_quantity=open_q,
        initial_stop=px(side, -1), current_stop=stop_price,
        current_target=None if target is None else px(side, target),
        last_price=px(side, last), mfe_price=px(side, mfe), mae_price=px(side, -mae),
        holding_ms=held_ms, max_holding_ms=max_ms, volatility=volatility, liquidity=liquidity,
        funding_paid=D('0'), realized_pnl=D('0'), initial_risk_amount=D('2.5'), equity=D('500'),
        open_risk_total=risk if open_risk is None else D(open_risk), risk_budget_remaining=D(budget),
        actions_applied=tuple(actions), policy=policy or V1.policy, data_status=data_status, kill_switch=kill)


def make(state, kind, policy=None, **values):
    return c.AdaptiveAction.create(header=state.header, position_id=state.position_id, kind=kind, state=state.ref(),
                                   policy=policy or state.policy, reasons=('test',), **values)


def risk_decision(action, state):
    """The ACTION risk decision the integration gate would build; constructing it as ADMIT enforces the contracts'
    rule that an admitted action never increases open risk or notional."""
    e = g.action_effect(action, state)
    return c.RiskDecision(
        header=state.header, decision_id='risk-' + action.action_id[4:], subject=c.RiskSubject.ACTION,
        subject_id=action.action_id, verdict=c.RiskVerdict.ADMIT, reasons=(), policy=s.policy_ref('h3-risk'),
        limits=c.H3_LIMITS, equity=state.equity, quantity=None, price_reference=state.last_price,
        stop_price=e.stop_after, risk_amount=e.risk_after, open_risk_before=state.open_risk_total,
        open_risk_after=state.open_risk_total - (e.risk_before - e.risk_after), notional_before=e.notional_before,
        notional_after=e.notional_after, leverage_after=e.notional_after / state.equity)


# ------------------------------------------------------------------------------------------------------ parameters

def test_policy_fingerprint_is_the_hash_of_the_frozen_parameters():
    assert V1.policy.policy_fingerprint == c.sha256_text(pm.DEFAULT_PARAMS_V1.canonical_json())
    assert V1.policy.policy_id == 'h3-adaptive-v1'
    assert pm.AdaptiveParamsV1(trail_r='1.0').fingerprint() == pm.DEFAULT_PARAMS_V1.fingerprint()
    assert pm.AdaptiveParamsV1(trail_r='1.5').fingerprint() != pm.DEFAULT_PARAMS_V1.fingerprint()
    assert pm.AdaptiveParamsV1(kill_switch_flatten=True).policy_ref() != V1.policy
    assert pm.DEFAULT_PARAMS_V1.document()['status'] == 'DRAFT'


def test_every_parameter_has_a_draft_a_priori_rationale():
    names = {f.name for f in dataclasses.fields(pm.AdaptiveParamsV1)} - {'policy_id', 'policy_version'}
    assert set(pm.PARAMETER_RATIONALE) == names
    assert all(text.startswith('DRAFT. ') for text in pm.PARAMETER_RATIONALE.values())
    assert set(pm.DEFAULT_PARAMS_V1.document()['parameters']) == names


@pytest.mark.parametrize('bad', [
    {'favourable_mfe_r': 0}, {'trail_r': '-1'}, {'adverse_r': '1.5'}, {'adverse_r': 0},
    {'partial_close_fraction': 1}, {'partial_close_fraction': 0}, {'protective_margin_ticks': 0},
    {'max_snapshot_age_ms': 0}, {'initial_phase_ms': -1}, {'favourable_mfe_r': 1.0}, {'trail_r': 'NaN'},
    {'kill_switch_flatten': 1}, {'policy_id': 'bad id'}, {'initial_phase_ms': True},
])
def test_parameters_refuse_invalid_values(bad):
    with pytest.raises(ValueError):
        pm.AdaptiveParamsV1(**bad)


def test_guard_defaults_are_the_policy_defaults():
    assert pm.DEFAULT_PARAMS_V1.guard_params() == g.DEFAULT_GUARD_PARAMS
    with pytest.raises(ValueError):
        g.GuardParams(protective_margin_ticks=0)


def test_static_and_adaptive_policies_have_distinct_identities():
    assert pol.STATIC_POLICY_REF != V1.policy
    assert pol.STATIC_POLICY_REF.policy_fingerprint == c.sha256_text(c.canonical_json(pol.static_policy_document()))


# ---------------------------------------------------------------------------------------------- static reference

@pytest.mark.parametrize('side', SIDES)
@pytest.mark.parametrize('case', [dict(mfe=3, last=2.5), dict(last=-1.2, mae=1.2), dict(held_ms=24 * H),
                                  dict(kill=True), dict(open_qty='0', last=-1, mae=1)])
def test_static_policy_always_holds(side, case):
    state = pos(side, policy=pol.STATIC_POLICY_REF, **case)
    action = STATIC.decide(state)
    assert action.kind is K.HOLD and action.reasons == ('static_hold',)
    assert g.check_action(action, state) == ()


def test_a_policy_never_manages_a_position_of_another_policy():
    state = pos(mfe=3, last=2.5, policy=pol.STATIC_POLICY_REF)
    action = V1.decide(state)
    assert action.kind is K.HOLD and action.reasons == ('policy_mismatch',)
    assert 'action_policy_mismatch' in g.check_action(action, state)
    assert STATIC.decide(pos()).reasons == ('policy_mismatch',)


# ------------------------------------------------------------------------------------------------- classification

@pytest.mark.parametrize('side', SIDES)
@pytest.mark.parametrize('case, expected', [
    (dict(open_qty='0', last=-1, mae=1), 'closed'),
    (dict(held_ms=24 * H), 'expiry'),
    (dict(held_ms=24 * H, last=-1.5, mae=1.5, kill=True, data_status=c.DataStatus.STALE), 'expiry'),
    (dict(last=-1, mae=1), 'breached'),
    (dict(last=-1.3, mae=1.3, mfe=2, kill=True), 'breached'),
    (dict(kill=True, mfe=3, last=2.5), 'kill_switch'),
    (dict(data_status=c.DataStatus.MISSING, mfe=3, last=2.5), 'degraded'),
    (dict(held_ms=30 * 60 * 1000, mfe=1.5, last=1.2), 'initial'),
    (dict(mfe=1, last=0.2), 'favourable'),
    (dict(mfe=0.99, last=0.2), 'neutral'),
    (dict(mfe=0.2, last=-0.5, mae=0.5), 'adverse'),
    (dict(mfe=0.2, last=-0.49, mae=0.5), 'neutral'),
])
def test_state_priority_table(side, case, expected):
    result = st.classify(pos(side, **case))
    assert result.state.value == expected
    assert result.reasons[0] == 'state_' + expected


def test_metrics_are_in_r_and_signed_in_the_position_favour():
    for side in SIDES:
        m = st.metrics(pos(side, last=0.5, mfe=0.8, mae=0.4))
        assert (m.unrealised_r, m.mfe_r, m.mae_r, m.locked_r) == (D('0.5'), D('0.8'), D('0.4'), D('-1'))
        assert m.risk_at_stop == D('2.5') and m.holding_fraction == D(2) / D(24) and not m.stop_breached
        assert m.unrealised_pnl == D('1.25')


def test_risk_budget_excess_is_journaled_but_never_adds_risk():
    state = pos(budget='-0.5', mfe=0.5, last=0.2)
    decision = V1.evaluate(state)
    assert 'risk_budget_exceeded' in decision.action.reasons and decision.action.kind is K.HOLD


# ---------------------------------------------------------------------------------------------- the V1 table

@pytest.mark.parametrize('side', SIDES)
def test_first_favourable_tightening_is_break_even(side):
    action = V1.decide(pos(side, mfe=1, last=0.8))
    assert action.kind is K.TIGHTEN_STOP and action.new_stop == D('100')
    assert action.reasons == ('state_favourable', 'trail_stop')


@pytest.mark.parametrize('side', SIDES)
def test_stop_trails_the_best_price_at_one_initial_risk(side):
    action = V1.decide(pos(side, mfe=2.5, last=2.2, stop=0))
    assert action.kind is K.TIGHTEN_STOP and action.new_stop == px(side, 1.5)


@pytest.mark.parametrize('side', SIDES)
def test_trail_is_capped_one_tick_inside_the_last_price(side):
    state = pos(side, mfe=2.5, last=1.0, stop=0)
    action = V1.decide(state)
    assert action.kind is K.TIGHTEN_STOP and action.new_stop == px(side, '0.99')
    assert c.stop_is_protective(side, state.last_price, action.new_stop)


@pytest.mark.parametrize('side', SIDES)
def test_quantisation_rounds_away_from_the_market_and_never_rounds_into_a_tightening(side):
    coarse = s.instrument(tick_size='0.05')
    first = V1.decide(pos(side, mfe='1.03', last='0.9', inst=coarse))
    assert first.kind is K.TIGHTEN_STOP and first.new_stop == D('100')   # 100.03 / 99.97 rounded away to 100
    again = V1.decide(pos(side, mfe='1.04', last='0.9', stop=0, inst=coarse))
    assert again.kind is K.PARTIAL_CLOSE                                  # 100.04 rounds back to 100: no tightening
    assert g.quantize_stop(side, D('100.03') if side is LONG else D('99.97'), D('0.05')) == D('100')
    assert g.quantize_target(LONG, D('101.01'), D('0.05')) == D('101.05')
    assert g.quantize_target(SHORT, D('98.99'), D('0.05')) == D('98.95')
    assert g.floor_to_lot(D('125.9'), D('10')) == D('120')


@pytest.mark.parametrize('side', SIDES)
def test_partial_close_once_the_stop_is_at_its_trail_level(side):
    action = V1.decide(pos(side, mfe=1, last=0.8, stop=0))
    assert action.kind is K.PARTIAL_CLOSE and action.close_quantity == D('125')
    assert action.reasons == ('state_favourable', 'stop_at_trail_level', 'partial_close')
    lots = V1.decide(pos(side, mfe=1, last=0.8, stop=0, inst=s.instrument(lot_size='10', min_size='10')))
    assert lots.close_quantity == D('120')                                # 125 floored to the 10-contract lot


def test_only_one_partial_close():
    action = V1.decide(pos(mfe=1, last=0.8, stop=0, open_qty='125'))
    assert action.kind is K.HOLD and 'partial_already_taken' in action.reasons


@pytest.mark.parametrize('liquidity', [c.LiquidityStatus.THIN, c.LiquidityStatus.UNKNOWN])
def test_thin_or_unknown_liquidity_defers_the_partial_but_not_the_stop(liquidity):
    assert 'partial_close_deferred_liquidity' in V1.decide(pos(mfe=1, last=0.8, stop=0, liquidity=liquidity)).reasons
    assert V1.decide(pos(mfe=1, last=0.8, liquidity=liquidity)).kind is K.TIGHTEN_STOP
    lenient = pol.AdaptivePolicyV1(pm.AdaptiveParamsV1(defer_partial_unless_liquidity_normal=False))
    state = pos(mfe=1, last=0.8, stop=0, liquidity=liquidity, policy=lenient.policy)
    assert lenient.decide(state).kind is K.PARTIAL_CLOSE


@pytest.mark.parametrize('side', SIDES)
@pytest.mark.parametrize('case', [dict(mfe=0.5, last=0.2), dict(mfe=0.2, last=-0.8, mae=0.8),
                                  dict(held_ms=30 * 60 * 1000, mfe=2, last=1.5)])
def test_neutral_adverse_and_initial_states_hold(side, case):
    assert V1.decide(pos(side, **case)).kind is K.HOLD


def test_v1_never_emits_a_target_adjustment():
    state = pos(mfe=3, last=2.5, target=4)
    assert all(V1.decide(pos(mfe=m, last=l, target=4)).kind is not K.ADJUST_TARGET
               for m, l in [(3, 2.5), (1, 0.8), (0.5, 0.1)])
    assert V1.decide(state).kind is K.TIGHTEN_STOP


# ------------------------------------------------------------------------- B6 scenarios: stop never widened

@pytest.mark.parametrize('side', SIDES)
def test_guard_refuses_a_wider_equal_off_grid_or_non_protective_stop(side):
    state = pos(side, mfe=2, last=1.5, stop=0)
    assert g.check_action(make(state, K.TIGHTEN_STOP, new_stop=px(side, '0.5')), state) == ()
    assert 'stop_widened' in g.check_action(make(state, K.TIGHTEN_STOP, new_stop=px(side, '-0.5')), state)
    assert 'stop_wider_than_initial' in g.check_action(make(state, K.TIGHTEN_STOP, new_stop=px(side, -2)), state)
    assert 'stop_unchanged' in g.check_action(make(state, K.TIGHTEN_STOP, new_stop=px(side, 0)), state)
    assert 'stop_not_on_tick' in g.check_action(make(state, K.TIGHTEN_STOP, new_stop=px(side, '0.505')), state)
    assert 'stop_not_protective' in g.check_action(make(state, K.TIGHTEN_STOP, new_stop=px(side, '1.5')), state)
    assert 'stop_not_protective' in g.check_action(make(state, K.TIGHTEN_STOP, new_stop=px(side, '1.495')), state)
    assert g.check_action(make(state, K.TIGHTEN_STOP, new_stop=px(side, '1.49')), state) == ()


def test_a_widened_stop_cannot_even_be_represented_in_a_position_state():
    with pytest.raises(c.ContractError, match='h3_position_stop_widened'):
        pos(stop=-1.5)


# --------------------------------------------------------------------------- B6: risk budget never increased

def test_no_action_kind_can_add_exposure():
    assert {k.value for k in K} == {'HOLD', 'TIGHTEN_STOP', 'ADJUST_TARGET', 'PARTIAL_CLOSE', 'FULL_CLOSE'}
    state = pos(mfe=1, last=0.8, stop=0)
    reversal = g.check_action(make(state, K.PARTIAL_CLOSE, close_quantity='600'), state)
    assert {'close_exceeds_open_quantity', 'position_side_reversal', 'exposure_increased'} <= set(reversal)
    assert 'partial_close_is_full_close' in g.check_action(make(state, K.PARTIAL_CLOSE, close_quantity='250'), state)


@pytest.mark.parametrize('side', SIDES)
def test_every_v1_action_is_admissible_under_the_contract_action_rule(side):
    for case in [dict(mfe=1, last=0.8), dict(mfe=1, last=0.8, stop=0), dict(held_ms=24 * H),
                 dict(last=-1.2, mae=1.2), dict(mfe=0.5, last=0.1)]:
        state = pos(side, **case)
        action = V1.decide(state)
        assert risk_decision(action, state).verdict is c.RiskVerdict.ADMIT
        effect = g.action_effect(action, state)
        assert effect.risk_after <= effect.risk_before and effect.notional_after <= effect.notional_before


# ------------------------------------------------------------------------------------- B6: partial orders

def test_partial_below_minimum_is_refused_by_default():
    inst = s.instrument(min_size='2')
    action = V1.decide(pos(mfe=1, last=0.8, stop=0, initial_qty='3', open_qty='3', inst=inst))
    assert action.kind is K.HOLD
    assert {'partial_close_not_executable', 'close_below_min_size'} <= set(action.reasons)
    tiny = V1.decide(pos(mfe=1, last=0.8, stop=0, initial_qty='1', open_qty='1'))
    assert tiny.kind is K.HOLD and 'close_quantity_invalid' in tiny.reasons


def test_partial_below_minimum_becomes_a_full_close_only_under_the_explicit_rule():
    promoting = pol.AdaptivePolicyV1(pm.AdaptiveParamsV1(partial_to_full_below_minimum=True))
    state = pos(mfe=1, last=0.8, stop=0, initial_qty='3', open_qty='3', inst=s.instrument(min_size='2'),
                policy=promoting.policy)
    action = promoting.decide(state)
    assert action.kind is K.FULL_CLOSE and 'partial_promoted_to_full_close' in action.reasons
    assert g.check_action(action, state) == ()


def test_partial_respects_remaining_size_and_minimum_notional():
    wide = pol.AdaptivePolicyV1(pm.AdaptiveParamsV1(partial_close_fraction='0.6'))
    state = pos(mfe=1, last=0.8, stop=0, initial_qty='5', open_qty='5', inst=s.instrument(min_size='3'),
                policy=wide.policy)
    assert 'remaining_below_min_size' in wide.decide(state).reasons
    notional = pos(mfe=1, last=0.8, stop=0, inst=s.instrument(min_notional='200'))
    assert {'close_below_min_notional', 'remaining_below_min_notional'} <= set(V1.decide(notional).reasons)
    lots = pos(mfe=1, last=0.8, stop=0, inst=s.instrument(lot_size='10', min_size='10'))
    assert 'close_not_lot_multiple' in g.check_action(make(lots, K.PARTIAL_CLOSE, close_quantity='125'), lots)


# ------------------------------------------------------------------------------------ B6: gap through the stop

@pytest.mark.parametrize('side', SIDES)
def test_gap_through_the_stop_closes_and_never_tightens_beyond_the_price(side):
    state = pos(side, mfe=2, last=-1.4, mae=1.4)                       # favourable earlier, now beyond the stop
    action = V1.decide(state)
    assert action.kind is K.FULL_CLOSE and 'stop_breached_exit' in action.reasons
    assert g.check_action(action, state) == ()
    for level in ('0', '-0.5', '-0.9'):                               # any tighter stop is above the price
        assert 'stop_not_protective' in g.check_action(make(state, K.TIGHTEN_STOP, new_stop=px(side, level)), state)


@pytest.mark.parametrize('side', SIDES)
def test_an_excursion_beyond_a_stop_tightened_later_is_not_a_breach(side):
    state = pos(side, mfe=2, last=1.5, mae=0.6, stop=0)                # MAE -0.6 R happened before break-even
    assert st.classify(state).state is st.ManagementState.FAVOURABLE
    assert V1.decide(state).kind is K.TIGHTEN_STOP


# ---------------------------------------------------------------------------- B6: stop and target in one bar

@pytest.mark.parametrize('side', SIDES)
def test_after_an_ambiguous_bar_a_closed_position_only_holds(side):
    state = pos(side, open_qty='0', target=2, mfe=2.2, mae=1.1, last=-1)  # the simulator settled it: closed
    action = V1.decide(state)
    assert action.kind is K.HOLD and 'position_closed' in action.reasons
    for kind, values in [(K.TIGHTEN_STOP, {'new_stop': px(side, -0.5)}), (K.FULL_CLOSE, {}),
                         (K.PARTIAL_CLOSE, {'close_quantity': '1'}), (K.ADJUST_TARGET, {'new_target': px(side, 3)})]:
        assert 'position_closed' in g.check_action(make(state, kind, **values), state)


@pytest.mark.parametrize('side', SIDES)
def test_a_target_crossed_but_unfilled_does_not_change_the_stop_logic(side):
    state = pos(side, target=2, mfe=2.3, last=2.1, stop=0)
    action = V1.decide(state)
    assert action.kind is K.TIGHTEN_STOP and action.new_stop == px(side, 1.3)


@pytest.mark.parametrize('side', SIDES)
def test_guard_rules_for_a_target_from_another_policy(side):
    state = pos(side, target=2, mfe=1.2, last=1.0, stop=0)
    assert g.check_action(make(state, K.ADJUST_TARGET, new_target=px(side, 3)), state) == ()
    assert 'target_not_beyond_price' in g.check_action(make(state, K.ADJUST_TARGET, new_target=px(side, 1)), state)
    assert 'target_unchanged' in g.check_action(make(state, K.ADJUST_TARGET, new_target=px(side, 2)), state)
    assert 'target_not_on_tick' in g.check_action(make(state, K.ADJUST_TARGET, new_target=px(side, '3.001')), state)


# ---------------------------------------------------------------------------- B6: missing data, stale snapshot

@pytest.mark.parametrize('status, code', [(c.DataStatus.MISSING, 'data_missing'), (c.DataStatus.STALE, 'data_stale'),
                                          (c.DataStatus.PARTIAL, 'data_partial')])
def test_incomplete_data_blocks_price_dependent_actions(status, code):
    state = pos(mfe=2, last=1.5, data_status=status)
    action = V1.decide(state)
    assert action.kind is K.HOLD and code in action.reasons
    assert code in g.check_action(make(state, K.TIGHTEN_STOP, new_stop=px(LONG, 0.5)), state)
    assert code in g.check_action(make(state, K.PARTIAL_CLOSE, close_quantity='100'), state)
    assert g.check_action(make(state, K.FULL_CLOSE), state) == ()     # exposure to zero stays admissible
    assert V1.decide(pos(held_ms=24 * H, data_status=status)).kind is K.FULL_CLOSE
    assert V1.decide(pos(last=-1.2, mae=1.2, data_status=status)).kind is K.FULL_CLOSE


def test_unknown_volatility_does_not_change_the_decision():
    known, unknown = V1.decide(pos(mfe=2, last=1.5, volatility='0.8')), V1.decide(pos(mfe=2, last=1.5))
    assert (known.kind, known.new_stop) == (unknown.kind, unknown.new_stop)
    assert known.action_id != unknown.action_id                        # different snapshots, different ids


def test_a_stale_snapshot_holds_and_an_old_decision_cannot_be_replayed_on_a_new_snapshot():
    late = pos(mfe=2, last=1.5, held_ms=4 * H, observed_ms=s.T0_MS + 2 * H)
    assert st.metrics(late).snapshot_age_ms == 2 * H
    action = V1.decide(late)
    assert action.kind is K.HOLD and 'snapshot_stale' in action.reasons
    assert 'snapshot_stale' in g.check_action(make(late, K.TIGHTEN_STOP, new_stop=px(LONG, 0.5)), late)
    earlier = pos(mfe=2, last=1.5)
    old = V1.decide(earlier)
    fresh = pos(mfe=2, last=1.5, held_ms=3 * H)
    assert 'action_snapshot_mismatch' in g.check_action(old, fresh)
    assert g.check_action(old, fresh, require_same_snapshot=False) == ()


# ------------------------------------------------------------------------------------ B6: repeated decisions

def apply(state, action, *, held_ms, last, mfe, mae):
    """Test-only stand-in for the integration simulator: apply an admitted action, then move to the next snapshot."""
    stop, qty, applied = state.current_stop, state.open_quantity, state.actions_applied
    if action.kind is not K.HOLD and not g.check_action(action, state):
        applied = applied + (action.action_id,)
        if action.kind is K.TIGHTEN_STOP:
            stop = action.new_stop
        elif action.kind is K.PARTIAL_CLOSE:
            qty = qty - action.close_quantity
        elif action.kind is K.FULL_CLOSE:
            qty = D('0')
    cv = state.header.instrument.contract_value
    return dataclasses.replace(
        state, header=s.header(side=state.header.side, portfolio_id='pf-B2', decision_ms=s.T0_MS + held_ms,
                               inst=state.header.instrument),
        status=c.PositionStatus.CLOSED if qty == 0 else c.PositionStatus.OPEN, open_quantity=qty,
        current_stop=stop, holding_ms=held_ms, last_price=last, mfe_price=mfe, mae_price=mae,
        open_risk_total=g.risk_at_stop(state.header.side, state.entry_price, stop, qty, cv), actions_applied=applied)


@pytest.mark.parametrize('side', SIDES)
def test_repeated_decisions_on_one_snapshot_are_identical_and_applied_once(side):
    state = pos(side, mfe=1.6, last=1.4)
    first, second = V1.decide(state), V1.decide(state)
    assert first == second and first.action_id == second.action_id
    assert V1.decide(c.PositionState.from_json(state.canonical_json())) == first
    assert V1.evaluate(state).fingerprint() == V1.evaluate(state).fingerprint()
    after = apply(state, first, held_ms=3 * H, last=state.last_price, mfe=state.mfe_price, mae=state.mae_price)
    assert after.current_stop == first.new_stop
    assert {'action_already_applied', 'action_snapshot_mismatch'} <= set(g.check_action(first, after))
    following = V1.decide(after)
    assert following.kind is not K.TIGHTEN_STOP                        # same best price: nothing left to tighten


@pytest.mark.parametrize('side', SIDES)
def test_a_path_never_widens_the_stop_never_adds_quantity_and_applies_each_action_once(side):
    path = [(1.0, 0.6), (1.5, 1.2), (1.5, 1.4), (2.5, 2.1), (2.5, 1.0), (2.5, 0.4), (2.5, -0.2)]   # (best, last) R
    state, mae, kinds = pos(side, mfe=0.4, last=0.3), 0.3, []
    for hour, (best, last) in enumerate(path, start=3):
        action = V1.decide(state)
        kinds.append(action.kind)
        nxt = apply(state, action, held_ms=hour * H, last=px(side, last), mfe=px(side, best), mae=px(side, -mae))
        assert c.stop_not_wider(side, state.current_stop, nxt.current_stop)
        assert nxt.open_quantity <= state.open_quantity
        assert len(set(nxt.actions_applied)) == len(nxt.actions_applied)
        state = nxt
    # hold (2 h), break-even, trail, partial, trail to +1.5 R; the price falls to +1.0 R beyond the trailed stop (this
    # stand-in fills no stop inside a bar): breach -> reduce-only full close; the closed position then only holds
    assert kinds == [K.HOLD, K.TIGHTEN_STOP, K.TIGHTEN_STOP, K.PARTIAL_CLOSE, K.TIGHTEN_STOP, K.FULL_CLOSE, K.HOLD]
    assert state.status is c.PositionStatus.CLOSED


# -------------------------------------------------------------------------------------------- B6: expiry

@pytest.mark.parametrize('side', SIDES)
def test_expiry_closes_at_the_maximum_holding_time_only(side):
    assert V1.decide(pos(side, held_ms=24 * H - 1, mfe=0.5, last=0.2)).kind is K.HOLD
    action = V1.decide(pos(side, held_ms=24 * H, mfe=0.5, last=0.2))
    assert action.kind is K.FULL_CLOSE and 'max_holding_reached' in action.reasons
    assert V1.decide(pos(side, held_ms=25 * H, kill=True, data_status=c.DataStatus.MISSING)).kind is K.FULL_CLOSE


# ------------------------------------------------------------------------- B6: failed stop modification

@pytest.mark.parametrize('side', SIDES)
def test_a_failed_stop_modification_keeps_the_old_stop_and_is_retried_consistently(side):
    before = pos(side, mfe=1.6, last=1.4)
    proposed = V1.decide(before)
    failed = apply(before, make(before, K.HOLD), held_ms=3 * H, last=before.last_price, mfe=before.mfe_price,
                   mae=before.mae_price)                               # the modification did not happen
    assert failed.current_stop == before.current_stop and proposed.action_id not in failed.actions_applied
    retry = V1.decide(failed)
    assert retry.kind is K.TIGHTEN_STOP and retry.new_stop == proposed.new_stop
    assert retry.action_id != proposed.action_id and g.check_action(retry, failed) == ()
    assert 'action_snapshot_mismatch' in g.check_action(proposed, failed)


# ------------------------------------------------------------------------------- B6: already closed position

def test_already_closed_position_holds_with_both_policies():
    closed = pos(open_qty='0', last=-1, mae=1)
    assert V1.decide(closed).kind is K.HOLD
    assert 'position_closed' in g.check_action(make(closed, K.FULL_CLOSE), closed)
    static_closed = pos(open_qty='0', last=-1, mae=1, policy=pol.STATIC_POLICY_REF)
    assert STATIC.decide(static_closed).kind is K.HOLD


# -------------------------------------------------------------------------------------------- B6: kill switch

@pytest.mark.parametrize('side', SIDES)
def test_kill_switch_holds_by_default_and_flattens_only_when_configured(side):
    state = pos(side, kill=True, mfe=2, last=1.5)
    action = V1.decide(state)
    assert action.kind is K.HOLD and 'kill_switch_hold' in action.reasons
    for kind, values in [(K.TIGHTEN_STOP, {'new_stop': px(side, 0.5)}), (K.PARTIAL_CLOSE, {'close_quantity': '100'}),
                         (K.ADJUST_TARGET, {'new_target': px(side, 3)})]:
        assert 'kill_switch_active' in g.check_action(make(state, kind, **values), state)
    assert g.check_action(make(state, K.FULL_CLOSE), state) == ()
    flatten = pol.AdaptivePolicyV1(pm.AdaptiveParamsV1(kill_switch_flatten=True))
    flat = flatten.decide(pos(side, kill=True, mfe=2, last=1.5, policy=flatten.policy))
    assert flat.kind is K.FULL_CLOSE and 'kill_switch_flatten' in flat.reasons


# ------------------------------------------------------------------------------------------- identity checks

def test_guard_refuses_an_action_aimed_at_another_position_side_or_portfolio():
    state = pos(mfe=2, last=1.5)
    other = s.header(side=SHORT, portfolio_id='pf-B3', decision_ms=state.header.clock.decision_ms)
    forged = c.AdaptiveAction.create(header=other, position_id='pos-2', kind=K.FULL_CLOSE, state=state.ref(),
                                     policy=pol.STATIC_POLICY_REF, reasons=())
    assert {'action_position_mismatch', 'action_side_mismatch', 'action_portfolio_mismatch',
            'action_policy_mismatch'} <= set(g.check_action(forged, state))
    moved = c.AdaptiveAction.create(header=s.header(portfolio_id='pf-B2', inst=s.instrument('ALT'),
                                                    decision_ms=state.header.clock.decision_ms),
                                    position_id='pos-1', kind=K.HOLD, state=state.ref(), policy=V1.policy, reasons=())
    assert g.check_action(moved, state) == ('action_instrument_mismatch',)


# ------------------------------------------------------------------------------- property sweep (seeded)

def random_state(rng, policy, trail_r):
    side = rng.choice(SIDES)
    tick = rng.choice(['0.01', '0.05', '0.1'])
    inst = s.instrument(tick_size=tick, lot_size=rng.choice(['1', '5']), min_size=rng.choice(['1', '5', '50']),
                        min_notional=rng.choice([None, None, '5', '150']))
    mfe = D(rng.randint(0, 400)) / 100
    mae = D(rng.randint(0, 150)) / 100
    last = mfe if rng.random() < 0.3 else D(rng.randint(int(-mae * 100), int(mfe * 100))) / 100
    draw = rng.random()
    if draw < 0.3:
        stop = mfe - trail_r                                           # already at its trail level
    elif draw < 0.7:
        stop = min(D(rng.randint(-100, 300)) / 100, mfe)
    else:
        stop = D(-1)
    stop = max(D(-1), (stop / D(tick)).to_integral_value() * D(tick))
    initial = D(rng.choice([1, 3, 10, 250, 255]))
    open_qty = rng.choice([initial] * 4 + [(initial / 2).to_integral_value(), D(0)])
    held = rng.choice([0, 20 * 60 * 1000, 2 * H, 3 * H, 6 * H, 12 * H, 23 * H, 24 * H, 30 * H])
    stale = rng.random() < 0.15 and held >= 3 * H
    return pos(side, last=last, mfe=mfe, mae=mae, stop=stop, inst=inst, initial_qty=str(initial),
               open_qty=str(open_qty), held_ms=held, observed_ms=s.T0_MS + held - 2 * H if stale else None,
               data_status=rng.choice([c.DataStatus.COMPLETE] * 5 + list(c.DataStatus)),
               liquidity=rng.choice(list(c.LiquidityStatus)), kill=rng.random() < 0.1,
               target=rng.choice([None, mfe + 1]), policy=policy)


@pytest.mark.parametrize('params', [pm.DEFAULT_PARAMS_V1,
                                    pm.AdaptiveParamsV1(partial_to_full_below_minimum=True, kill_switch_flatten=True,
                                                        trail_r='0.5', favourable_mfe_r='0.5')])
def test_property_every_v1_decision_is_safe_deterministic_and_admissible(params):
    policy = pol.AdaptivePolicyV1(params)
    rng = random.Random(20261015)
    kinds = set()
    for _ in range(600):
        state = random_state(rng, policy.policy, params.trail_r)
        side = state.header.side
        action = policy.decide(state)
        kinds.add(action.kind)
        assert policy.decide(state) == action                         # deterministic on an identical snapshot
        assert g.check_action(action, state) == ()                    # the policy never emits a refused action
        assert risk_decision(action, state).verdict is c.RiskVerdict.ADMIT
        if action.kind is K.TIGHTEN_STOP:
            assert c.stop_tightens(side, state.current_stop, action.new_stop)
            assert c.stop_is_protective(side, state.last_price, action.new_stop)
            assert g.on_grid(action.new_stop, state.header.instrument.tick_size)
        if action.kind is K.PARTIAL_CLOSE:
            assert action.close_quantity < state.open_quantity
        if state.status is c.PositionStatus.CLOSED:
            assert action.kind is K.HOLD
        if state.status is c.PositionStatus.OPEN and state.holding_ms >= state.max_holding_ms:
            assert action.kind is K.FULL_CLOSE
        if state.kill_switch:
            assert action.kind in g.KILL_SWITCH_KINDS
        if state.data_status is not c.DataStatus.COMPLETE or st.metrics(state).snapshot_age_ms > H:
            assert action.kind in (K.HOLD, K.FULL_CLOSE)
        assert action.kind is not K.ADJUST_TARGET
        assert STATIC.decide(dataclasses.replace(state, policy=pol.STATIC_POLICY_REF)).kind is K.HOLD
    assert {K.HOLD, K.TIGHTEN_STOP, K.PARTIAL_CLOSE, K.FULL_CLOSE} <= kinds


# ------------------------------------------------------------------------------------------- python 3.11

def test_adaptive_sources_parse_as_python_3_11():
    root = pathlib.Path(pol.__file__).parent
    for path in sorted(root.glob('*.py')):
        ast.parse(path.read_text(encoding='utf-8'), filename=str(path), feature_version=(3, 11))
