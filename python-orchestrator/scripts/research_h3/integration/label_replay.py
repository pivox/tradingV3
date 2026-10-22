"""Capacity-free label replay (added at review R1-F3): one outcome per candidate, independent of every other one.

Why. A portfolio run is capacity-limited: under the H3 limits (0.5 % per trade, 1 % total, one position per
instrument) only about two positions are open at a time, so B0 trades a small, path-dependent subset of the
candidate book. Labels taken from B0 (``validation.scoring_checks.join_scores_outcomes``) leave most candidates
unlabelled (222 of 273 in the synthetic example) and select the labelled ones by when earlier trades happened to
close. A scorer trained or checked on them learns on a selected sample and is applied to all candidates.

What. ``capacity_free_labels`` replays every candidate alone, in its own fresh 500-USDT portfolio, with a fresh risk
gate and the same simulator, execution model, costs and management policy as the portfolio runs (the static policy
of B0 by default; the adaptive policy for adaptive-exit labels). Each replay sees only the candidate's instrument and
the bars from the first bar available at its decision to the end of its validity (plus two bars to close it). The
outcome therefore depends on the candidate and the market only, never on the other candidates, the capacity or the
order of the book. A candidate the gate or the execution refuses even alone (minimum size, collar, ...) keeps its
refusal reasons and no label.

Two label units are returned, because the components use two conventions (H3-ARCHITECTURE.md, backlog #17):
``net_r`` is the net PnL over the trade's nominal cost-inclusive risk fixed at admission (C's ``r_multiple``), and
``gross_r`` is the gross PnL over the gross stop distance at the reference price (A's training label).

Audit additions (H3-A testability, agent C; additive, the existing fields and the ``traded`` rule are unchanged so
the e2e numbers 273 / 149 / 124 stay reproducible):

- ``horizon_covered``: False when the trade was closed by the end of the replay window (``end_of_data``) instead of
  its own exit rule, i.e. a truncated label. Such a label must not be used as a full-horizon outcome;
- ``price_path_gaps``: number of missing bars inside the replay window (the simulator cannot evaluate a stop in a
  missing bar; the next open then acts as a gap). A label with gaps is flagged, not silently trusted;
- ``net_gross_r``: net PnL over the GROSS stop distance at the reference (units x |reference - stop|), the unit of the
  scorer's ``expected_net_r``. ``net_r`` (over the cost-inclusive nominal risk) is about 20 % smaller in magnitude
  with the e2e costs (mean ratio 0.81 on the synthetic example), so calibration checks of ``expected_net_r`` must use ``net_gross_r``, never ``net_r``;
- ``exit_ms`` (integration, change request CR-V2-3): the exit instant of the replayed trade, the earliest instant the
  outcome is known; ``migrate_v1_v2.label_to_v2`` uses it as ``LabelOutcome.available_ms``. None when not traded.

Labels from this replay are EXECUTABILITY-CONDITIONED: a candidate is labelled only when its fill happens, and with
the one-sided collar a fill depends on the price move between the reference and the fill bar (a long is refused when
the price rose by more than the collar). They are therefore not representative of the technically eligible
candidates the scorer is applied to; ``scoring.labels`` defines the target population and a reference label that does
not depend on executability (``H3-LABELS-FEATURES-UNCERTAINTY-AUDIT.md``).

What it does not fix. The execution-timing convention (an entry fills at the first open after the decision, one bar
after its reference price with a latency > 0) applies to the labels exactly as to the portfolio runs; it remains a
documented blocker (reviews R1-F6 and R2-F5).

Research prototype: synthetic data only, no order, no exchange.
"""
from __future__ import annotations

from collections.abc import Callable, Mapping, Sequence
from dataclasses import dataclass
from decimal import Decimal

from ..contracts import (
    H3_LIMITS, Bar, ContractError, FundingObservation, ManagementPolicy, OpportunityCandidate, Variant,
)
from .costs import derived_id
from .risk_gate import H3RiskGate
from .simulator import run_variant

LABEL_VARIANTS = (Variant.B0, Variant.B2)   # score-blind: the gate refuses a score for them, and none is passed
STOP_REASONS = ('stop', 'stop_gap', 'liquidation_proxy')


@dataclass(frozen=True)
class CandidateLabel:
    candidate_id: str
    decision_ms: int
    traded: bool
    net_r: Decimal | None
    gross_r: Decimal | None
    stopped: bool | None
    exit_reason: str | None
    refusal_reasons: tuple[str, ...]
    horizon_covered: bool | None = None      # audit addition: False = truncated by the end of the replay window
    price_path_gaps: int = 0                 # audit addition: missing bars inside the replay window
    net_gross_r: Decimal | None = None       # audit addition: net PnL / gross stop distance (expected_net_r's unit)
    exit_ms: int | None = None               # integration addition (CR-V2-3): exit instant of the replayed trade


def _window(bars: Sequence[Bar], candidate: OpportunityCandidate) -> list[Bar]:
    rows = sorted((b for b in bars if b.inst_id == candidate.header.instrument.inst_id), key=lambda b: b.open_ms)
    decision = candidate.header.clock.decision_ms
    first = next((i for i, b in enumerate(rows) if b.available_ms >= decision), None)
    if first is None:
        return []
    interval = rows[first].interval_ms
    horizon = decision + candidate.max_holding_ms + 2 * interval
    return [b for b in rows[first:] if b.open_ms <= horizon]


def _gaps(window: Sequence[Bar]) -> int:
    """Missing bars between consecutive bars of the window (bars are assumed to share one interval)."""
    missing = 0
    for a, b in zip(window, window[1:]):
        if b.open_ms > a.close_ms:
            missing += (b.open_ms - a.close_ms) // a.interval_ms
    return missing


def capacity_free_labels(candidates: Sequence[OpportunityCandidate], bars: Sequence[Bar], *,
                         policy: ManagementPolicy, gate_factory: Callable[[], H3RiskGate],
                         funding: Sequence[FundingObservation] = (), variant: Variant = Variant.B0,
                         initial_equity: Decimal = H3_LIMITS.reference_equity, quote_ccy: str = 'USDC',
                         experiment_id: str = 'h3-label-replay') -> dict[str, CandidateLabel]:
    """One label per candidate, each from its own replay (see the module docstring)."""
    if variant not in LABEL_VARIANTS:
        raise ContractError('h3_label_variant_not_score_blind')
    labels: dict[str, CandidateLabel] = {}
    for candidate in candidates:
        if candidate.candidate_id in labels:
            raise ContractError('h3_label_candidate_duplicate')
        window = _window(bars, candidate)
        decision = candidate.header.clock.decision_ms
        if not window:
            labels[candidate.candidate_id] = CandidateLabel(candidate.candidate_id, decision, False, None, None,
                                                            None, None, ('no_market_data_after_decision',))
            continue
        start, end = window[0].open_ms, window[-1].close_ms
        inst_id = candidate.header.instrument.inst_id
        result = run_variant(
            experiment_id=experiment_id, variant=variant,
            portfolio_id=derived_id('label', experiment_id, candidate.candidate_id), bars=window,
            candidates=[candidate], policy=policy, gate=gate_factory(),
            funding=[f for f in funding if f.inst_id == inst_id and start <= f.funding_ms < end],
            initial_equity=initial_equity, quote_ccy=quote_ccy)
        if result.trades:
            (trade,) = result.trades
            units = trade.quantity * candidate.header.instrument.contract_value
            distance = abs(candidate.entry_reference_price - candidate.initial_stop_price) * units
            labels[candidate.candidate_id] = CandidateLabel(
                candidate.candidate_id, decision, True, trade.realized_r, trade.gross_pnl / distance,
                trade.exit_reason in STOP_REASONS, trade.exit_reason, (),
                horizon_covered=trade.exit_reason != 'end_of_data', price_path_gaps=_gaps(window),
                net_gross_r=trade.net_pnl / distance, exit_ms=trade.exit_ms)
        else:
            reasons = tuple(dict.fromkeys(r for refusal in result.refusals for r in refusal.reasons))
            labels[candidate.candidate_id] = CandidateLabel(candidate.candidate_id, decision, False, None, None,
                                                            None, None, reasons or ('not_traded',),
                                                            price_path_gaps=_gaps(window))
    return labels


def label_mapping(labels: Mapping[str, CandidateLabel], unit: str = 'net_r', *,
                  full_horizon_only: bool = False) -> dict[str, tuple[float | None, bool | None]]:
    """``{candidate_id: (realised R, stopped)}`` for ``validation.scoring_checks.join_scores_labels``.

    ``unit``: ``net_r`` (default, unchanged), ``gross_r``, or ``net_gross_r`` (the unit of ``expected_net_r``; use it
    for calibration checks). With ``full_horizon_only`` a truncated label (``horizon_covered`` False) or a label
    with missing bars maps to (None, None)."""
    if unit not in ('net_r', 'gross_r', 'net_gross_r'):
        raise ContractError('h3_label_unit_unknown')
    out: dict[str, tuple[float | None, bool | None]] = {}
    for cid, label in labels.items():
        value = getattr(label, unit)
        if full_horizon_only and (label.horizon_covered is False or label.price_path_gaps):
            out[cid] = (None, None)
            continue
        out[cid] = (None if value is None else float(value), label.stopped)
    return out
