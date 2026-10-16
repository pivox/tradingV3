"""Parameters of the adaptive management policy V1 (H3-B). Every value is DRAFT.

All numeric choices of the policy live in one frozen object, ``AdaptiveParamsV1``. Its canonical JSON (keys sorted,
Decimals as plain strings, the policy id, version and DRAFT status included) is hashed into the
``PolicyRef.policy_fingerprint``: changing any value changes the policy identity, so two runs with different values
can never be reported under the same policy.

No value was chosen from a performance measurement: no data has been run through this policy. Each value has an
a-priori reason in ``PARAMETER_RATIONALE``. The owner freezes them, or replaces them, before any non-synthetic run;
a frozen set gets a new ``policy_version``.
"""
from __future__ import annotations

import dataclasses
from dataclasses import dataclass
from decimal import Decimal
from typing import Any

from ..contracts import (
    ONE, ZERO, ContractError, PolicyRef, canonical_json, check_id, dec, sha256_text, wire_decimal,
)
from .guards import GuardParams

PARAMS_SCHEMA = 'h3.adaptive_policy_params/1'
ADAPTIVE_POLICY_ID = 'h3-adaptive-v1'
ADAPTIVE_POLICY_VERSION = '0.1.0-draft'
PARAMS_STATUS = 'DRAFT'


@dataclass(frozen=True)
class AdaptiveParamsV1:
    """DRAFT parameters of ``AdaptivePolicyV1``. R is the initial risk per unit, |entry price - initial stop|."""

    favourable_mfe_r: Decimal = Decimal('1')
    trail_r: Decimal = Decimal('1')
    adverse_r: Decimal = Decimal('0.5')
    partial_close_fraction: Decimal = Decimal('0.5')
    partial_to_full_below_minimum: bool = False
    defer_partial_unless_liquidity_normal: bool = True
    kill_switch_flatten: bool = False
    initial_phase_ms: int = 3_600_000
    max_snapshot_age_ms: int = 3_600_000
    protective_margin_ticks: int = 1
    policy_id: str = ADAPTIVE_POLICY_ID
    policy_version: str = ADAPTIVE_POLICY_VERSION

    def __post_init__(self) -> None:
        for name in ('favourable_mfe_r', 'trail_r', 'adverse_r', 'partial_close_fraction'):
            value = getattr(self, name)
            if not isinstance(value, (Decimal, int, str)) or isinstance(value, bool):
                raise ValueError('h3_adaptive_params_invalid')  # no float: the fingerprint must not depend on repr
            try:
                object.__setattr__(self, name, dec(value))
            except ContractError as error:
                raise ValueError('h3_adaptive_params_invalid') from error
        for name in ('partial_to_full_below_minimum', 'defer_partial_unless_liquidity_normal', 'kill_switch_flatten'):
            if not isinstance(getattr(self, name), bool):
                raise ValueError('h3_adaptive_params_invalid')
        for name in ('initial_phase_ms', 'max_snapshot_age_ms', 'protective_margin_ticks'):
            value = getattr(self, name)
            if isinstance(value, bool) or not isinstance(value, int) or value < 0:
                raise ValueError('h3_adaptive_params_invalid')
        if self.favourable_mfe_r <= 0 or self.trail_r <= 0:
            raise ValueError('h3_adaptive_params_invalid')
        if not ZERO < self.adverse_r <= ONE:              # an adverse marker beyond the stop would never be seen
            raise ValueError('h3_adaptive_params_invalid')
        if not ZERO < self.partial_close_fraction < ONE:  # 0 is no action, 1 is a full close
            raise ValueError('h3_adaptive_params_invalid')
        if self.protective_margin_ticks < 1 or self.max_snapshot_age_ms < 1:
            raise ValueError('h3_adaptive_params_invalid')
        check_id(self.policy_id)
        check_id(self.policy_version)

    def guard_params(self) -> GuardParams:
        return GuardParams(protective_margin_ticks=self.protective_margin_ticks,
                           max_snapshot_age_ms=self.max_snapshot_age_ms)

    def document(self) -> dict[str, Any]:
        """Canonical, language-neutral form of the policy definition (the fingerprinted body)."""
        values: dict[str, Any] = {}
        for f in dataclasses.fields(self):
            if f.name in ('policy_id', 'policy_version'):
                continue
            value = getattr(self, f.name)
            values[f.name] = wire_decimal(value) if isinstance(value, Decimal) else value
        return {'schema': PARAMS_SCHEMA, 'policy_id': self.policy_id, 'policy_version': self.policy_version,
                'status': PARAMS_STATUS, 'parameters': values}

    def canonical_json(self) -> str:
        return canonical_json(self.document())

    def fingerprint(self) -> str:
        return sha256_text(self.canonical_json())

    def policy_ref(self) -> PolicyRef:
        return PolicyRef(policy_id=self.policy_id, policy_version=self.policy_version,
                         policy_fingerprint=self.fingerprint())


DEFAULT_PARAMS_V1 = AdaptiveParamsV1()

#: A-priori reason for every parameter. All DRAFT: none comes from a measured performance.
PARAMETER_RATIONALE: dict[str, str] = {
    'favourable_mfe_r': (
        'DRAFT. 1 R: the risk unit fixed at entry. A trade that has shown at least its own risk in its favour is the '
        'first point where the initial risk can be removed (stop to entry) without asking more than the trade has '
        'already shown. No other level is implied by the entry plan.'),
    'trail_r': (
        'DRAFT. 1 R: once favourable, the stop trails the best observed price at the same distance as the initial '
        'stop. The position keeps the loss tolerance it was admitted with, re-anchored on the best price; no new '
        'width is invented. With favourable_mfe_r = trail_r the first tightening is exactly break-even.'),
    'adverse_r': (
        'DRAFT. 0.5 R: half-way between entry and the initial stop. Used only to label the ADVERSE state for the '
        'journal; V1 takes no action on it (the initial stop is the loss limit and an earlier loss-cut would be a '
        'separate, untested hypothesis).'),
    'partial_close_fraction': (
        'DRAFT. 0.5 of the initial quantity, floored to the lot: an equal split, the only split that does not '
        'presume which half of the position is better. Taken once, when favourable and the stop is already at its '
        'trail level.'),
    'partial_to_full_below_minimum': (
        'DRAFT. False: the owner brief allows turning a non-executable partial close into a full close only under an '
        'explicit, validated rule. Until the owner validates it, a partial close below the order minima is refused '
        '(HOLD) and the position keeps its stop and target.'),
    'defer_partial_unless_liquidity_normal': (
        'DRAFT. True: a partial close is optional and pays the spread and slippage immediately; with THIN or '
        'UNKNOWN liquidity that cost is unknown, so the optional action waits. Protective exits (expiry, breach) '
        'and stop tightening (a resting order, no immediate cost) are never deferred by liquidity.'),
    'kill_switch_flatten': (
        'DRAFT. False: in TradingV3 a tripped kill switch freezes writes (OkxDemoWriteGate refuses them, protective '
        'ones included unless the only reasons are private-stream observability or the notional cap). V1 mirrors '
        'that: HOLD, the existing stop and target stay in force. True would flatten instead (FULL_CLOSE); the owner '
        'decides.'),
    'initial_phase_ms': (
        'DRAFT. 3 600 000 ms, one bar at the DRAFT 1 h cadence of the synthetic fixtures. Inside the entry bar the '
        'high and low cannot be ordered against the fill time, so MFE/MAE there are ambiguous: V1 does not act '
        'before one full bar has elapsed. Set it to exactly one bar of the frozen cadence.'),
    'max_snapshot_age_ms': (
        'DRAFT. 3 600 000 ms, one bar at the DRAFT 1 h cadence: a snapshot whose last observation is older than one '
        'bar interval has missed at least one bar. Price-dependent actions are refused on it.'),
    'protective_margin_ticks': (
        'DRAFT. 1 tick: a stop equal to the last price is not protective (strict inequality in the contracts); one '
        'tick is the smallest distance that keeps it strictly protective on the price grid.'),
}
