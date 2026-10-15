"""Pure invariant checker for adaptive management actions (H3-B), shared by the policy and the risk gate.

``check_action(action, state)`` answers one question: may this ``AdaptiveAction`` be applied to this
``PositionState``? It returns the tuple of violated invariants as stable reason codes; an empty tuple means the
action is admissible as far as position management is concerned. It never mutates anything and never sends an
order. The adaptive policy runs it on its own output (a refused candidate becomes HOLD) and the integration risk gate
(``contracts.RiskGate.validate_action``) is expected to run it again before the simulator applies the action. The
gate stays the authority on portfolio risk; this module only covers the position-level invariants below.

Invariants (codes in brackets):
- identity: the action targets this position, instrument, side, portfolio and management policy, and was decided on
  this exact snapshot (``action_*_mismatch``);
- idempotency: an action already listed in ``state.actions_applied`` is never applied twice
  (``action_already_applied``);
- a closed position accepts only HOLD (``position_closed``);
- kill switch: only HOLD and FULL_CLOSE (``kill_switch_active``);
- price-dependent actions (TIGHTEN_STOP, ADJUST_TARGET, PARTIAL_CLOSE) need complete and fresh data
  (``data_partial``, ``data_missing``, ``data_stale``, ``snapshot_stale``); a FULL_CLOSE is always admissible on an
  open position because it brings exposure and risk to zero;
- the stop only ever tightens and stays strictly protective with respect to the last observed price by at least
  ``protective_margin_ticks`` ticks, on the tick grid (``stop_widened``, ``stop_unchanged``, ``stop_not_protective``,
  ``stop_not_on_tick``, ``stop_wider_than_initial``);
- a target stays beyond the last observed price by the same margin, on the tick grid (``target_not_beyond_price``,
  ``target_unchanged``, ``target_not_on_tick``);
- a partial close is a lot multiple, at least the minimum size, strictly smaller than the open quantity, and leaves a
  remainder of at least the minimum size and notional (``close_*``, ``remaining_*``, ``partial_close_is_full_close``);
- exposure and risk never increase, and the side never reverses (``exposure_increased``, ``risk_increased``,
  ``position_side_reversal``).

Every numeric parameter here is DRAFT (see ``GuardParams``).
"""
from __future__ import annotations

from dataclasses import dataclass
from decimal import ROUND_CEILING, ROUND_FLOOR, Decimal

from ..contracts import (
    ZERO, ActionKind, AdaptiveAction, DataStatus, PositionState, PositionStatus, Side, stop_not_wider,
    stop_tightens,
)

#: Kinds whose correctness depends on the observed price, the book or the instrument minima.
PRICE_DEPENDENT_KINDS = frozenset({ActionKind.TIGHTEN_STOP, ActionKind.ADJUST_TARGET, ActionKind.PARTIAL_CLOSE})
#: Kinds admissible while the kill switch is set: doing nothing, or bringing exposure to zero.
KILL_SWITCH_KINDS = frozenset({ActionKind.HOLD, ActionKind.FULL_CLOSE})

_DATA_CODES = {DataStatus.PARTIAL: 'data_partial', DataStatus.MISSING: 'data_missing', DataStatus.STALE: 'data_stale'}


@dataclass(frozen=True)
class GuardParams:
    """Position-level guard parameters. DRAFT values, each with its a-priori reason; to be frozen by the owner.

    - ``protective_margin_ticks`` = 1: a stop equal to the last price is not protective (contracts use a strict
      inequality); one tick is the smallest distance that keeps it strictly protective on the venue's price grid.
    - ``max_snapshot_age_ms`` = 3_600_000: one bar at the DRAFT 1 h decision cadence of the synthetic fixtures. A
      snapshot older than one bar interval has missed at least one bar. Set it to exactly one bar of the frozen
      cadence.
    """

    protective_margin_ticks: int = 1
    max_snapshot_age_ms: int = 3_600_000

    def __post_init__(self) -> None:
        for value in (self.protective_margin_ticks, self.max_snapshot_age_ms):
            if isinstance(value, bool) or not isinstance(value, int) or value < 1:
                raise ValueError('h3_adaptive_guard_params_invalid')


DEFAULT_GUARD_PARAMS = GuardParams()


# ------------------------------------------------------------------------------------------------------- grid math

def _sign(side: Side) -> int:
    return 1 if side is Side.LONG else -1


def on_grid(value: Decimal, step: Decimal) -> bool:
    ratio = value / step
    return ratio == ratio.to_integral_value()


def quantize_stop(side: Side, price: Decimal, tick: Decimal) -> Decimal:
    """Round a stop to the tick grid away from the market (down for a long, up for a short).

    A level that was protective before rounding stays protective after it; the price may become no tighter than the
    current stop, in which case the caller must not emit a tightening.
    """
    rounding = ROUND_FLOOR if side is Side.LONG else ROUND_CEILING
    return (price / tick).to_integral_value(rounding=rounding) * tick


def quantize_target(side: Side, price: Decimal, tick: Decimal) -> Decimal:
    """Round a target to the tick grid away from the market (up for a long, down for a short)."""
    rounding = ROUND_CEILING if side is Side.LONG else ROUND_FLOOR
    return (price / tick).to_integral_value(rounding=rounding) * tick


def floor_to_lot(quantity: Decimal, lot: Decimal) -> Decimal:
    """Round a quantity down to a lot multiple: never close more than intended."""
    return (quantity / lot).to_integral_value(rounding=ROUND_FLOOR) * lot


def protective_limit(state: PositionState, params: GuardParams = DEFAULT_GUARD_PARAMS) -> Decimal:
    """The tightest admissible stop: ``margin`` ticks on the loss side of the last observed price."""
    margin = params.protective_margin_ticks * state.header.instrument.tick_size
    return state.last_price - _sign(_side(state)) * margin


def stop_breached(state: PositionState) -> bool:
    """The last observed price is at or beyond the current stop (a gap, or a stop that has not executed).

    Only ``last_price`` is used. ``mae_price`` may lie beyond a stop that was tightened after that excursion, which is
    not a breach.
    """
    if _side(state) is Side.LONG:
        return state.last_price <= state.current_stop
    return state.last_price >= state.current_stop


def snapshot_age_ms(state: PositionState) -> int:
    clock = state.header.clock
    return clock.decision_ms - clock.observed_ms


def _side(state: PositionState) -> Side:
    side = state.header.side
    assert side is not None  # PositionState refuses a missing side at construction
    return side


# ----------------------------------------------------------------------------------------------------- risk effect

def risk_at_stop(side: Side, entry: Decimal, stop: Decimal, quantity: Decimal, contract_value: Decimal) -> Decimal:
    """Nominal loss at the stop measured from the entry price (0 once the stop locks the entry or better)."""
    per_unit = (entry - stop) if side is Side.LONG else (stop - entry)
    return max(ZERO, per_unit) * quantity * contract_value


@dataclass(frozen=True)
class ActionEffect:
    """Position-level quantities before and after an action, as if it were applied in full at ``last_price``.

    ``risk_*`` is the loss at the stop from entry; ``mark_risk_*`` the loss at the stop from the last price (what an
    open position can still give back). Notional is valued at the last observed price.
    """

    quantity_before: Decimal
    quantity_after: Decimal
    stop_before: Decimal
    stop_after: Decimal
    risk_before: Decimal
    risk_after: Decimal
    mark_risk_before: Decimal
    mark_risk_after: Decimal
    notional_before: Decimal
    notional_after: Decimal


def action_effect(action: AdaptiveAction, state: PositionState) -> ActionEffect:
    side = _side(state)
    cv = state.header.instrument.contract_value
    q0 = state.open_quantity
    if action.kind is ActionKind.FULL_CLOSE:
        q1 = ZERO
    elif action.kind is ActionKind.PARTIAL_CLOSE and action.close_quantity is not None:
        q1 = q0 - action.close_quantity
    else:
        q1 = q0
    s0 = state.current_stop
    s1 = action.new_stop if action.kind is ActionKind.TIGHTEN_STOP and action.new_stop is not None else s0
    return ActionEffect(
        quantity_before=q0, quantity_after=q1, stop_before=s0, stop_after=s1,
        risk_before=risk_at_stop(side, state.entry_price, s0, q0, cv),
        risk_after=risk_at_stop(side, state.entry_price, s1, abs(q1), cv),
        mark_risk_before=risk_at_stop(side, state.last_price, s0, q0, cv),
        mark_risk_after=risk_at_stop(side, state.last_price, s1, abs(q1), cv),
        notional_before=q0 * cv * state.last_price, notional_after=abs(q1) * cv * state.last_price)


# ---------------------------------------------------------------------------------------------------- partial size

def partial_close_violations(state: PositionState, quantity: Decimal) -> tuple[str, ...]:
    """Executability of a reduce-only partial close of ``quantity`` contracts at the last observed price."""
    inst = state.header.instrument
    out: list[str] = []
    if quantity <= 0:
        return ('close_quantity_invalid',)
    if quantity > state.open_quantity:
        out.append('close_exceeds_open_quantity')
    elif quantity == state.open_quantity:
        out.append('partial_close_is_full_close')
    if not on_grid(quantity, inst.lot_size):
        out.append('close_not_lot_multiple')
    if quantity < inst.min_size:
        out.append('close_below_min_size')
    remaining = state.open_quantity - quantity
    if remaining > 0:
        if not on_grid(remaining, inst.lot_size):
            out.append('remaining_not_lot_multiple')
        if remaining < inst.min_size:
            out.append('remaining_below_min_size')
    if inst.min_notional is not None:
        unit = inst.contract_value * state.last_price
        if quantity * unit < inst.min_notional:
            out.append('close_below_min_notional')
        if remaining > 0 and remaining * unit < inst.min_notional:
            out.append('remaining_below_min_notional')
    return tuple(out)


# ------------------------------------------------------------------------------------------------------ the check

def _identity_violations(action: AdaptiveAction, state: PositionState, require_same_snapshot: bool) -> list[str]:
    out: list[str] = []
    if action.position_id != state.position_id:
        out.append('action_position_mismatch')
    if action.header.instrument != state.header.instrument:
        out.append('action_instrument_mismatch')
    if action.header.side is not state.header.side:
        out.append('action_side_mismatch')
    if action.header.portfolio_id != state.header.portfolio_id:
        out.append('action_portfolio_mismatch')
    if action.policy != state.policy:
        out.append('action_policy_mismatch')
    if require_same_snapshot and action.state != state.ref():
        out.append('action_snapshot_mismatch')
    if action.action_id in state.actions_applied or action.idempotency_key in state.actions_applied:
        out.append('action_already_applied')
    return out


def _stop_violations(state: PositionState, new_stop: Decimal, params: GuardParams) -> list[str]:
    side = _side(state)
    out: list[str] = []
    if not on_grid(new_stop, state.header.instrument.tick_size):
        out.append('stop_not_on_tick')
    if new_stop == state.current_stop:
        out.append('stop_unchanged')
    elif not stop_tightens(side, state.current_stop, new_stop):
        out.append('stop_widened')
    if not stop_not_wider(side, state.initial_stop, new_stop):
        out.append('stop_wider_than_initial')
    limit = protective_limit(state, params)
    if (new_stop > limit) if side is Side.LONG else (new_stop < limit):
        out.append('stop_not_protective')
    return out


def _target_violations(state: PositionState, new_target: Decimal, params: GuardParams) -> list[str]:
    side = _side(state)
    out: list[str] = []
    if not on_grid(new_target, state.header.instrument.tick_size):
        out.append('target_not_on_tick')
    if state.current_target is not None and new_target == state.current_target:
        out.append('target_unchanged')
    margin = params.protective_margin_ticks * state.header.instrument.tick_size
    bound = state.last_price + _sign(side) * margin
    if (new_target < bound) if side is Side.LONG else (new_target > bound):
        out.append('target_not_beyond_price')
    return out


def check_action(action: AdaptiveAction, state: PositionState, params: GuardParams = DEFAULT_GUARD_PARAMS, *,
                 require_same_snapshot: bool = True) -> tuple[str, ...]:
    """Violated invariants of ``action`` against ``state`` (empty: admissible at position level).

    ``require_same_snapshot=False`` lets a caller re-validate an action decided on an earlier snapshot against the
    current state (every other invariant is then checked against the current state).
    """
    out = _identity_violations(action, state, require_same_snapshot)
    kind = action.kind
    if kind is ActionKind.HOLD:
        return tuple(out)
    if state.status is PositionStatus.CLOSED or state.open_quantity == 0:
        out.append('position_closed')
        return tuple(out)
    if state.kill_switch and kind not in KILL_SWITCH_KINDS:
        out.append('kill_switch_active')
    if kind in PRICE_DEPENDENT_KINDS:
        if state.data_status is not DataStatus.COMPLETE:
            out.append(_DATA_CODES[state.data_status])
        if snapshot_age_ms(state) > params.max_snapshot_age_ms:
            out.append('snapshot_stale')
    if kind is ActionKind.TIGHTEN_STOP and action.new_stop is not None:
        out.extend(_stop_violations(state, action.new_stop, params))
    elif kind is ActionKind.ADJUST_TARGET and action.new_target is not None:
        out.extend(_target_violations(state, action.new_target, params))
    elif kind is ActionKind.PARTIAL_CLOSE and action.close_quantity is not None:
        out.extend(partial_close_violations(state, action.close_quantity))
    effect = action_effect(action, state)
    if effect.quantity_after < 0:
        out.append('position_side_reversal')
    if effect.notional_after > effect.notional_before or abs(effect.quantity_after) > effect.quantity_before:
        out.append('exposure_increased')
    if effect.risk_after > effect.risk_before or effect.mark_risk_after > effect.mark_risk_before:
        out.append('risk_increased')
    return tuple(dict.fromkeys(out))
