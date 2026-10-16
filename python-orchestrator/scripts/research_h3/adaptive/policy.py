"""Management policies (H3-B): the static reference and the DRAFT adaptive policy V1.

Both implement ``contracts.ManagementPolicy``: ``decide(state) -> AdaptiveAction``. A policy returns one action per
snapshot and nothing else: it never mutates a portfolio or a position and never sends an order. The integration
risk gate validates every action (``contracts.RiskGate.validate_action``, expected to call
``guards.check_action``) before the simulator applies it; a refused action leaves the position as it was, stop
included.

``StaticPolicy`` always returns HOLD: B0/B1 positions exit only through their stop, target or maximum holding time,
which the simulator executes.

``AdaptivePolicyV1`` (DRAFT) maps the discrete state of ``states.classify`` to one action:

===========  =======================================================================================================
state        action
===========  =======================================================================================================
CLOSED       HOLD (``position_closed``)
EXPIRY       FULL_CLOSE (``max_holding_reached``), whatever the data status
BREACHED     FULL_CLOSE (``stop_breached_exit``): the observed price is beyond the stop and the position is still open,
             so its protection has not worked; a reduce-only close is the only safe write
KILL_SWITCH  HOLD (``kill_switch_hold``), or FULL_CLOSE (``kill_switch_flatten``) when ``kill_switch_flatten``
DEGRADED     HOLD: no price-dependent action on partial, missing, stale or late data
INITIAL      HOLD (``initial_phase``)
FAVOURABLE   1. TIGHTEN_STOP to the trail level ``best price - trail_r x R`` (long; mirrored for a short), capped at
                one margin inside the last price and rounded away from the market, when that is strictly tighter;
             2. otherwise, once, PARTIAL_CLOSE of ``partial_close_fraction`` of the initial quantity (lot-floored),
                when liquidity is NORMAL and the order minima allow it; below the minima: HOLD, or FULL_CLOSE only if
                ``partial_to_full_below_minimum`` (an explicit rule the owner must validate);
             3. otherwise HOLD
ADVERSE      HOLD (the initial stop is the loss limit; no discretionary loss-cut in V1)
NEUTRAL      HOLD
===========  =======================================================================================================

V1 never emits ADJUST_TARGET: no a-priori rule for moving a target was found that is not a performance choice. The
guards still validate it, for any other policy.

Every candidate goes through ``guards.check_action`` before it is returned; a refused candidate becomes HOLD with
``guard_refused`` and the violation codes. ``evaluate`` returns the full trace (``AdaptiveDecision``) for the journal.
"""
from __future__ import annotations

from dataclasses import dataclass
from decimal import Decimal
from typing import Any

from ..contracts import (
    ActionKind, AdaptiveAction, LiquidityStatus, PolicyRef, PositionState, Side, canonical_json, sha256_text,
    stop_tightens,
)
from .guards import check_action, floor_to_lot, partial_close_violations, protective_limit, quantize_stop
from .params import DEFAULT_PARAMS_V1, AdaptiveParamsV1
from .states import Classification, ManagementState, classify

STATIC_POLICY_ID = 'h3-static'
STATIC_POLICY_VERSION = '0.1.0-draft'
TRACE_SCHEMA = 'h3.adaptive_decision_trace/1'


def static_policy_document() -> dict[str, Any]:
    return {'schema': 'h3.static_policy/1', 'policy_id': STATIC_POLICY_ID, 'policy_version': STATIC_POLICY_VERSION,
            'status': 'DRAFT', 'rule': 'hold_only_exits_by_stop_target_or_max_holding'}


STATIC_POLICY_REF = PolicyRef(policy_id=STATIC_POLICY_ID, policy_version=STATIC_POLICY_VERSION,
                              policy_fingerprint=sha256_text(canonical_json(static_policy_document())))


def _action(state: PositionState, policy: PolicyRef, kind: ActionKind, reasons: tuple[str, ...],
            **values: Any) -> AdaptiveAction:
    return AdaptiveAction.create(header=state.header, position_id=state.position_id, kind=kind, state=state.ref(),
                                 policy=policy, reasons=tuple(dict.fromkeys(reasons)), **values)


class StaticPolicy:
    """Reference management: always HOLD. Exits come from the stop, the target and the maximum holding time."""

    def __init__(self, policy: PolicyRef = STATIC_POLICY_REF) -> None:
        self.policy = policy

    def decide(self, state: PositionState) -> AdaptiveAction:
        if state.policy != self.policy:
            return _action(state, self.policy, ActionKind.HOLD, ('policy_mismatch',))
        return _action(state, self.policy, ActionKind.HOLD, ('static_hold',))


@dataclass(frozen=True)
class AdaptiveDecision:
    """Journal trace of one decision: the state, its metrics, the candidate, the guard verdict and the final action."""

    classification: Classification
    candidate_kind: ActionKind
    candidate_reasons: tuple[str, ...]
    guard_violations: tuple[str, ...]
    action: AdaptiveAction

    def to_wire(self) -> dict[str, Any]:
        return {'schema': TRACE_SCHEMA, 'policy': self.action.policy.to_wire(),
                'position_id': self.action.position_id, 'position_state': self.action.state.to_wire(),
                'management_state': self.classification.state.value,
                'metrics': self.classification.metrics.to_wire(),
                'classification_reasons': list(self.classification.reasons),
                'candidate_kind': self.candidate_kind.value, 'candidate_reasons': list(self.candidate_reasons),
                'guard_violations': list(self.guard_violations), 'action': self.action.to_wire()}

    def canonical_json(self) -> str:
        return canonical_json(self.to_wire())

    def fingerprint(self) -> str:
        return sha256_text(self.canonical_json())


class AdaptivePolicyV1:
    """DRAFT deterministic state -> action table. Same snapshot, same parameters: same action, same id."""

    def __init__(self, params: AdaptiveParamsV1 = DEFAULT_PARAMS_V1) -> None:
        self.params = params
        self.policy = params.policy_ref()
        self.guard_params = params.guard_params()

    # ---------------------------------------------------------------------------------------------- public API

    def decide(self, state: PositionState) -> AdaptiveAction:
        return self.evaluate(state).action

    def evaluate(self, state: PositionState) -> AdaptiveDecision:
        cls = classify(state, self.params)
        if state.policy != self.policy:
            hold = _action(state, self.policy, ActionKind.HOLD, ('policy_mismatch',))
            return AdaptiveDecision(cls, ActionKind.HOLD, ('policy_mismatch',), (), hold)
        kind, reasons, values = self._candidate(state, cls)
        candidate = _action(state, self.policy, kind, cls.reasons + reasons, **values)
        violations = check_action(candidate, state, self.guard_params)
        if not violations or kind is ActionKind.HOLD:
            return AdaptiveDecision(cls, kind, reasons, violations, candidate)
        hold = _action(state, self.policy, ActionKind.HOLD, cls.reasons + ('guard_refused',) + violations)
        return AdaptiveDecision(cls, kind, reasons, violations, hold)

    # ---------------------------------------------------------------------------------------------- the table

    def _candidate(self, state: PositionState,
                   cls: Classification) -> tuple[ActionKind, tuple[str, ...], dict[str, Decimal]]:
        s = cls.state
        if s is ManagementState.CLOSED:
            return ActionKind.HOLD, ('position_closed',), {}
        if s is ManagementState.EXPIRY:
            return ActionKind.FULL_CLOSE, ('max_holding_reached',), {}
        if s is ManagementState.BREACHED:
            return ActionKind.FULL_CLOSE, ('stop_breached_exit',), {}
        if s is ManagementState.KILL_SWITCH:
            if self.params.kill_switch_flatten:
                return ActionKind.FULL_CLOSE, ('kill_switch_flatten',), {}
            return ActionKind.HOLD, ('kill_switch_hold',), {}
        if s is ManagementState.FAVOURABLE:
            return self._favourable(state)
        return ActionKind.HOLD, (), {}

    def trail_stop(self, state: PositionState) -> Decimal | None:
        """Quantised trail level if it is strictly tighter than the current stop, else None."""
        side = state.header.side
        assert side is not None
        sign = 1 if side is Side.LONG else -1
        r_unit = abs(state.entry_price - state.initial_stop)
        trail = state.mfe_price - sign * self.params.trail_r * r_unit
        cap = protective_limit(state, self.guard_params)
        level = min(trail, cap) if side is Side.LONG else max(trail, cap)
        stop = quantize_stop(side, level, state.header.instrument.tick_size)
        if stop <= 0 or not stop_tightens(side, state.current_stop, stop):
            return None
        return stop

    def partial_quantity(self, state: PositionState) -> Decimal:
        lot = state.header.instrument.lot_size
        return floor_to_lot(state.initial_quantity * self.params.partial_close_fraction, lot)

    def _favourable(self, state: PositionState) -> tuple[ActionKind, tuple[str, ...], dict[str, Decimal]]:
        stop = self.trail_stop(state)
        if stop is not None:
            return ActionKind.TIGHTEN_STOP, ('trail_stop',), {'new_stop': stop}
        if state.open_quantity < state.initial_quantity:
            return ActionKind.HOLD, ('stop_at_trail_level', 'partial_already_taken'), {}
        if self.params.defer_partial_unless_liquidity_normal and state.liquidity is not LiquidityStatus.NORMAL:
            return ActionKind.HOLD, ('stop_at_trail_level', 'partial_close_deferred_liquidity'), {}
        quantity = self.partial_quantity(state)
        problems = ('close_quantity_invalid',) if quantity <= 0 else partial_close_violations(state, quantity)
        if not problems:
            return ActionKind.PARTIAL_CLOSE, ('stop_at_trail_level', 'partial_close'), {'close_quantity': quantity}
        if self.params.partial_to_full_below_minimum:
            return ActionKind.FULL_CLOSE, ('partial_close_not_executable', 'partial_promoted_to_full_close',
                                           *problems), {}
        return ActionKind.HOLD, ('partial_close_not_executable', *problems), {}
