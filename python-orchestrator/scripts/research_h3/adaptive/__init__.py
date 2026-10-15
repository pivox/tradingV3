"""H3-B adaptive position management (research prototype, synthetic data only, DRAFT).

Question: does dynamic management, using only what is observable during the trade, improve net performance against
a static exit? Nothing here answers it: this package only provides a deterministic, guarded policy that can be
tested against the static reference inside the B0-B3 design.

Modules:
- ``guards``: pure invariant checker, ``check_action(action, state) -> violation codes``, shared with the risk gate;
- ``params``: the frozen DRAFT parameter object whose canonical hash is the adaptive ``PolicyRef`` fingerprint;
- ``states``: discrete state classifier over ``contracts.PositionState`` only;
- ``policy``: ``StaticPolicy`` (always HOLD) and ``AdaptivePolicyV1`` (DRAFT state -> action table).

The engine returns actions; it never mutates a position or a portfolio and never sends an order. It is
exchange-independent: the instrument grid (tick, lot, minimum size and notional) comes from the snapshot.
Doc: docs/handbook/research/H3-ADAPTIVE-MANAGEMENT.md.
"""
from .guards import (
    DEFAULT_GUARD_PARAMS, KILL_SWITCH_KINDS, PRICE_DEPENDENT_KINDS, ActionEffect, GuardParams, action_effect,
    check_action, floor_to_lot, partial_close_violations, quantize_stop, quantize_target, risk_at_stop, stop_breached,
)
from .params import DEFAULT_PARAMS_V1, PARAMETER_RATIONALE, AdaptiveParamsV1
from .policy import STATIC_POLICY_REF, AdaptiveDecision, AdaptivePolicyV1, StaticPolicy
from .states import Classification, ManagementState, PositionMetrics, classify, metrics

__all__ = [
    'DEFAULT_GUARD_PARAMS', 'KILL_SWITCH_KINDS', 'PRICE_DEPENDENT_KINDS', 'ActionEffect', 'GuardParams',
    'action_effect', 'check_action', 'floor_to_lot', 'partial_close_violations', 'quantize_stop', 'quantize_target',
    'risk_at_stop', 'stop_breached',
    'DEFAULT_PARAMS_V1', 'PARAMETER_RATIONALE', 'AdaptiveParamsV1',
    'STATIC_POLICY_REF', 'AdaptiveDecision', 'AdaptivePolicyV1', 'StaticPolicy',
    'Classification', 'ManagementState', 'PositionMetrics', 'classify', 'metrics',
]
