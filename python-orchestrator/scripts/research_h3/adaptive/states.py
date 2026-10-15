"""Discrete management states of an open position (H3-B), computed from ``contracts.PositionState`` only.

The classifier reads nothing but the snapshot it is given: the observed price, entry, side, current and initial
stop, current target, holding time, MFE/MAE up to ``observed_ms``, volatility, liquidity, funding paid, equity and
risk budget, the applied-action history, data status and kill switch. There is no other input, so no future
observation can enter a decision.

Priority order (the first state that applies wins):

==============  ============================================================================  ===================
state           condition                                                                     needs fresh data?
==============  ============================================================================  ===================
CLOSED          status CLOSED (open quantity 0)                                               no
EXPIRY          holding_ms >= max_holding_ms                                                  no (time only)
BREACHED        last observed price at or beyond the current stop (gap, or stop not executed)  no (exit only)
KILL_SWITCH     kill switch set                                                               no
DEGRADED        data status not COMPLETE, or snapshot older than ``max_snapshot_age_ms``      --
INITIAL         holding_ms < ``initial_phase_ms`` (the entry bar: MFE/MAE ambiguous)          yes
FAVOURABLE      MFE >= ``favourable_mfe_r`` R                                                 yes
ADVERSE         unrealised <= -``adverse_r`` R                                                yes
NEUTRAL         otherwise                                                                     yes
==============  ============================================================================  ===================

R is the initial risk per unit, |entry price - initial stop|. It is anchored on the initial stop and never on the
current one, so the initial plan is never rewritten. Thresholds come from ``AdaptiveParamsV1`` and are all DRAFT.
Decisions compare prices (``mfe - entry >= k x R``), never rounded ratios; the R ratios in ``PositionMetrics`` are for
the journal.
"""
from __future__ import annotations

import enum
from dataclasses import dataclass
from decimal import Decimal
from typing import Any

from ..contracts import (
    ZERO, DataStatus, LiquidityStatus, PositionState, PositionStatus, Side, wire_decimal,
)
from .guards import risk_at_stop, snapshot_age_ms, stop_breached
from .params import DEFAULT_PARAMS_V1, AdaptiveParamsV1


class ManagementState(str, enum.Enum):
    CLOSED = 'closed'
    EXPIRY = 'expiry'
    BREACHED = 'breached'
    KILL_SWITCH = 'kill_switch'
    DEGRADED = 'degraded'
    INITIAL = 'initial'
    FAVOURABLE = 'favourable'
    ADVERSE = 'adverse'
    NEUTRAL = 'neutral'


@dataclass(frozen=True)
class PositionMetrics:
    """Observable metrics at ``decision_ms``. R ratios are signed so that positive is in the position's favour."""

    side: Side
    r_unit: Decimal
    unrealised_r: Decimal
    mfe_r: Decimal
    mae_r: Decimal
    locked_r: Decimal
    holding_fraction: Decimal
    open_fraction: Decimal
    funding_r: Decimal
    unrealised_pnl: Decimal
    risk_at_stop: Decimal
    snapshot_age_ms: int
    stop_breached: bool
    risk_budget_exceeded: bool
    liquidity: LiquidityStatus
    volatility_known: bool

    def to_wire(self) -> dict[str, Any]:
        out: dict[str, Any] = {}
        for name, value in self.__dict__.items():
            if isinstance(value, Decimal):
                out[name] = wire_decimal(value)
            elif isinstance(value, enum.Enum):
                out[name] = value.value
            else:
                out[name] = value
        return out


def metrics(state: PositionState) -> PositionMetrics:
    side = state.header.side
    assert side is not None  # PositionState refuses a missing side at construction
    sign = 1 if side is Side.LONG else -1
    entry = state.entry_price
    r_unit = abs(entry - state.initial_stop)          # > 0: the initial stop is protective by contract
    cv = state.header.instrument.contract_value
    return PositionMetrics(
        side=side, r_unit=r_unit,
        unrealised_r=sign * (state.last_price - entry) / r_unit,
        mfe_r=sign * (state.mfe_price - entry) / r_unit,
        mae_r=sign * (entry - state.mae_price) / r_unit,
        locked_r=sign * (state.current_stop - entry) / r_unit,
        holding_fraction=Decimal(state.holding_ms) / Decimal(state.max_holding_ms),
        open_fraction=state.open_quantity / state.initial_quantity,
        funding_r=state.funding_paid / state.initial_risk_amount,
        unrealised_pnl=sign * (state.last_price - entry) * state.open_quantity * cv,
        risk_at_stop=risk_at_stop(side, entry, state.current_stop, state.open_quantity, cv),
        snapshot_age_ms=snapshot_age_ms(state),
        stop_breached=state.open_quantity > 0 and stop_breached(state),
        risk_budget_exceeded=state.risk_budget_remaining < ZERO,
        liquidity=state.liquidity,
        volatility_known=state.volatility is not None,
    )


@dataclass(frozen=True)
class Classification:
    """The state, the metrics it was derived from, and ordered, unique reason codes for the journal."""

    state: ManagementState
    metrics: PositionMetrics
    reasons: tuple[str, ...]


_DATA_REASON = {DataStatus.PARTIAL: 'data_partial', DataStatus.MISSING: 'data_missing', DataStatus.STALE: 'data_stale'}


def degradation_reasons(state: PositionState, params: AdaptiveParamsV1 = DEFAULT_PARAMS_V1) -> tuple[str, ...]:
    out: list[str] = []
    if state.data_status is not DataStatus.COMPLETE:
        out.append(_DATA_REASON[state.data_status])
    if snapshot_age_ms(state) > params.max_snapshot_age_ms:
        out.append('snapshot_stale')
    return tuple(out)


def classify(state: PositionState, params: AdaptiveParamsV1 = DEFAULT_PARAMS_V1) -> Classification:
    m = metrics(state)
    sign = 1 if m.side is Side.LONG else -1
    flags: list[str] = []
    if m.risk_budget_exceeded:
        flags.append('risk_budget_exceeded')       # journaled; V1 never adds risk, the risk gate owns de-risking
    if state.kill_switch:
        flags.append('kill_switch_active')
    degraded = degradation_reasons(state, params)

    if state.status is PositionStatus.CLOSED or state.open_quantity == 0:
        chosen = ManagementState.CLOSED
    elif state.holding_ms >= state.max_holding_ms:
        chosen = ManagementState.EXPIRY
    elif m.stop_breached:
        chosen = ManagementState.BREACHED
    elif state.kill_switch:
        chosen = ManagementState.KILL_SWITCH
    elif degraded:
        chosen = ManagementState.DEGRADED
    elif state.holding_ms < params.initial_phase_ms:
        chosen = ManagementState.INITIAL
    elif sign * (state.mfe_price - state.entry_price) >= params.favourable_mfe_r * m.r_unit:
        chosen = ManagementState.FAVOURABLE
    elif sign * (state.last_price - state.entry_price) <= -params.adverse_r * m.r_unit:
        chosen = ManagementState.ADVERSE
    else:
        chosen = ManagementState.NEUTRAL
    reasons = ['state_' + chosen.value, *degraded, *flags]
    return Classification(state=chosen, metrics=m, reasons=tuple(dict.fromkeys(reasons)))
