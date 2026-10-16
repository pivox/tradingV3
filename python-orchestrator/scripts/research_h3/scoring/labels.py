"""Label populations, exclusion census and an executability-free reference label (H3-A testability, agent C).

Why. The capacity-free replay (``integration.label_replay``, review R1-F3) labels a candidate only when the candidate,
replayed alone, is actually FILLED by the simulator. With the bar-resolution timing (the entry fills at the first open
after the decision, one bar after the reference price) and the one-sided IOC collar (``entry_collar_rate``, 10 bp
DRAFT), a candidate is refused exactly when the price moved more than the collar IN ITS FAVOUR before the fill (a
long whose next open is above reference x 1.001). Executability is then a function of the post-decision price path,
so "labelled by the replay" is an outcome-dependent selection. Removing the portfolio (capacity) does not remove it.
In the synthetic e2e example: 273 candidates, 149 labelled by the replay, 124 refused for ``fill_beyond_collar``
alone, 52 labelled by B0 (``H3-LABELS-FEATURES-UNCERTAINTY-AUDIT.md``).

What. Four nested populations, named as in the H3-A mission brief:

- ``ALL_CANDIDATES``: every candidate the common generator emitted (snapshots refused for data before generation are
  counted separately as ``pre_candidate_refusals``);
- ``TECHNICALLY_ELIGIBLE``: candidates that pass every check decidable from information at the decision instant T
  alone, in a fresh 500-USDT portfolio: a usable bar at T, a protective on-tick stop, the portfolio's settlement
  currency, a non-zero size at the minimum size / notional under the H3 limits (the risk gate's ``admit_entry`` on
  an empty portfolio), and, for a retrospective dataset, a holding horizon inside the data window (a calendar fact:
  reference instant + ``max_holding_ms`` + one bar <= end of the window, whatever the path);
- ``LABELABLE``: technically eligible candidates with a reference label (below): their whole static-exit path is
  covered by contiguous bars;
- ``EXECUTABLE``: technically eligible candidates the capacity-free replay fills (the old ``traded`` flag).

**Target population of the scoring: ``TECHNICALLY_ELIGIBLE``.** The scorer is applied at T to every technically
eligible candidate, before anything about its fill is known, so its labels must cover that population. Exclusions
from it to ``LABELABLE`` must be outcome-independent (missing bars, calendar censoring), and the census checks it.
Executability is a separate, downstream quantity (a fill probability under a timing rule), reported next to the
label and never used as a training filter.

Reference label ``h3.reference_static_exit_label/1``. A virtual position opened at the candidate's
``entry_reference_price`` at the reference instant t0 (the close of the last bar usable at T), with the candidate's
initial stop, target and ``max_holding_ms``, managed by the static rule only: at each bar of the path, an open through
the stop exits at the open (gap, the label is then below -1), an open through the target exits at the target; then
intrabar the stop is assumed before the target when both are touched (pessimistic, flagged ``ambiguous_bar``); a
target fills on a trade-through (``high > target`` for a long), as the simulator's default; the time exit is the
open of the first bar whose open is at or after t0 + ``max_holding_ms``. The label is GROSS, in R = |reference -
stop| (the unit of the scorer's ``gross_r`` head): costs and funding are deterministic and subtracted by the engine
(``cost_r``, ``funding_cost_r``), so they are never inside the training label (no double deduction; see
``training_target``). Missing bars inside the path (``price_path_gap``) or a path that ends before the exit
(``horizon_not_covered``) leave the candidate unlabelled, never with a truncated label.

Leakage. The label reads only bars opening at or after t0; at bar resolution the decision T = t0 + latency, and no
snapshot feature can use a bar that closes after T (``Bar.usable_at``), so no feature at T depends on the label path.
The label does not read the collar, the fill, the portfolio, other candidates or any score. The sub-bar interval
[t0, T] (the latency) cannot be separated at bar resolution: the label is the idealised "fill at the reference"
outcome, not a claim that such a fill is achievable; achievability is ``EXECUTABLE``.

Research prototype: synthetic data only; no market data file, no order, no exchange.
"""
from __future__ import annotations

import enum
import math
import random
from collections.abc import Callable, Mapping, Sequence
from dataclasses import dataclass, field
from decimal import Decimal
from typing import Any

from ..contracts import (
    H3_LIMITS, Bar, Clock, ContractError, OpportunityCandidate, PortfolioView, RiskVerdict, Side, Variant,
    stop_is_protective,
)

LABEL_DEFINITION = 'h3.reference_static_exit_label/1'
CENSUS_SCHEMA = 'h3.label_population_census/1'


class Population(str, enum.Enum):
    ALL_CANDIDATES = 'ALL_CANDIDATES'
    TECHNICALLY_ELIGIBLE = 'TECHNICALLY_ELIGIBLE'
    LABELABLE = 'LABELABLE'
    EXECUTABLE = 'EXECUTABLE'


#: The population the scorer is applied to, and therefore the one its labels must represent.
TARGET_POPULATION = Population.TECHNICALLY_ELIGIBLE


class Timing(str, enum.Enum):
    """When the information behind an exclusion exists, which decides whether the exclusion can select on the
    outcome."""

    EX_ANTE = 'ex_ante'                      # known at the decision instant T from data available at T
    CALENDAR = 'calendar'                    # depends on the position of T in the data window only, not on prices
    DATA_COVERAGE = 'data_coverage'          # bars missing after T: independent of the price only if the feed's
                                             # outages are; to be checked on real data
    POST_DECISION_PRICE = 'post_decision_price'   # depends on prices after T: outcome-dependent selection
    PORTFOLIO = 'portfolio'                  # depends on other trades (capacity): path-dependent selection
    OPERATIONAL = 'operational'              # injected venue failures, protocol violations


class Category(str, enum.Enum):
    """The owner's exclusion categories (H3-A brief, C1), plus the other reasons the code really emits."""

    DATA_MISSING = 'data_missing'
    STOP_INVALID = 'stop_invalid'
    INSTRUMENT_ABSENT = 'instrument_absent'
    MIN_SIZE = 'min_size'
    MARKET_CONSTRAINT = 'market_constraint'
    HOLDING_NOT_COVERED = 'holding_not_covered'
    PRICE_UNAVAILABLE = 'price_unavailable'
    PORTFOLIO_STATE = 'portfolio_state'
    PROTOCOL = 'protocol'
    OPERATIONAL_FAILURE = 'operational_failure'
    UNCLASSIFIED = 'unclassified'


_CATEGORY_TIMING = {
    Category.DATA_MISSING: Timing.EX_ANTE, Category.STOP_INVALID: Timing.EX_ANTE,
    Category.INSTRUMENT_ABSENT: Timing.EX_ANTE, Category.MIN_SIZE: Timing.EX_ANTE,
    Category.MARKET_CONSTRAINT: Timing.POST_DECISION_PRICE, Category.HOLDING_NOT_COVERED: Timing.CALENDAR,
    Category.PRICE_UNAVAILABLE: Timing.DATA_COVERAGE, Category.PORTFOLIO_STATE: Timing.PORTFOLIO,
    Category.PROTOCOL: Timing.EX_ANTE, Category.OPERATIONAL_FAILURE: Timing.OPERATIONAL,
    Category.UNCLASSIFIED: Timing.POST_DECISION_PRICE,   # unknown: assume the worst (outcome-dependent)
}

#: Every refusal / exclusion reason code the H3 code emits on the candidate path (``e2e.DataCheckedCandidateProvider``,
#: ``integration.risk_gate``, ``integration.simulator``, ``integration.label_replay`` and this module), mapped to a
#: category. A code not listed is UNCLASSIFIED (treated as outcome-dependent).
REASON_CATEGORY: dict[str, Category] = {
    # data refused before candidate generation (prefixes, see ``category_of``)
    'snapshot_data_partial': Category.DATA_MISSING, 'snapshot_data_stale': Category.DATA_MISSING,
    'snapshot_data_missing': Category.DATA_MISSING, 'no_market_data_at_decision': Category.DATA_MISSING,
    # instrument
    'no_market_data': Category.INSTRUMENT_ABSENT, 'settlement_currency_mismatch': Category.INSTRUMENT_ABSENT,
    # stop / target
    'stop_missing': Category.STOP_INVALID, 'stop_not_protective': Category.STOP_INVALID,
    'stop_not_on_tick': Category.STOP_INVALID, 'target_not_on_tick': Category.STOP_INVALID,
    # sizing under the H3 limits at 500 USDT (decided at admission, from the collar price known at T)
    'quantity_below_min_size': Category.MIN_SIZE, 'notional_below_min_notional': Category.MIN_SIZE,
    'post_quantization_risk_breach': Category.MIN_SIZE,
    # execution at the fill bar: depends on the price after T
    'fill_beyond_collar': Category.MARKET_CONSTRAINT, 'ioc_not_filled_beyond_collar': Category.MARKET_CONSTRAINT,
    'fill_beyond_stop': Category.MARKET_CONSTRAINT, 'fill_risk_above_per_trade': Category.MARKET_CONSTRAINT,
    'fill_total_risk_exceeded': Category.MARKET_CONSTRAINT, 'fill_leverage_exceeded': Category.MARKET_CONSTRAINT,
    'fill_margin_insufficient': Category.MARKET_CONSTRAINT, 'fill_without_admission': Category.PROTOCOL,
    # horizon
    'horizon_not_covered': Category.HOLDING_NOT_COVERED, 'after_end_of_data': Category.HOLDING_NOT_COVERED,
    'end_of_data_pending_entry': Category.HOLDING_NOT_COVERED, 'end_of_data_exit': Category.HOLDING_NOT_COVERED,
    # price path
    'no_market_data_after_decision': Category.PRICE_UNAVAILABLE, 'price_path_gap': Category.PRICE_UNAVAILABLE,
    # portfolio state (cannot occur for a candidate alone in a fresh portfolio, except through a failure)
    'total_risk_budget_exhausted': Category.PORTFOLIO_STATE, 'leverage_cap_exhausted': Category.PORTFOLIO_STATE,
    'margin_insufficient': Category.PORTFOLIO_STATE, 'instrument_already_open': Category.PORTFOLIO_STATE,
    'portfolio_occupancy_unknown': Category.PORTFOLIO_STATE, 'kill_switch_active': Category.PORTFOLIO_STATE,
    'equity_non_positive': Category.PORTFOLIO_STATE, 'order_not_sent': Category.PORTFOLIO_STATE,
    'cap_total_risk': Category.PORTFOLIO_STATE, 'cap_leverage': Category.PORTFOLIO_STATE,
    'cap_margin': Category.PORTFOLIO_STATE,
    # protocol
    'candidate_from_future': Category.PROTOCOL, 'candidate_stale': Category.PROTOCOL,
    'score_missing': Category.PROTOCOL, 'score_not_expected': Category.PROTOCOL,
    'score_candidate_mismatch': Category.PROTOCOL, 'score_verdict_no_trade': Category.PROTOCOL,
    'candidate_id_conflict': Category.PROTOCOL, 'decision_already_applied': Category.PROTOCOL,
    'not_traded': Category.UNCLASSIFIED,
    # injected failures
    'entry_order_rejected': Category.OPERATIONAL_FAILURE, 'entry_network_error': Category.OPERATIONAL_FAILURE,
    'reconciled_not_filled': Category.OPERATIONAL_FAILURE,
}
_PREFIX_CATEGORY = (('feature_', Category.DATA_MISSING), ('snapshot_data_', Category.DATA_MISSING))


def category_of(code: str) -> Category:
    if code in REASON_CATEGORY:
        return REASON_CATEGORY[code]
    for prefix, category in _PREFIX_CATEGORY:
        if code.startswith(prefix):
            return category
    return Category.UNCLASSIFIED


def timing_of(category: Category) -> Timing:
    return _CATEGORY_TIMING[category]


def outcome_dependent(timing: Timing) -> bool:
    """An exclusion of this timing can select candidates on their outcome (directly or through other trades)."""
    return timing in (Timing.POST_DECISION_PRICE, Timing.PORTFOLIO)


def primary_category(reasons: Sequence[str]) -> Category:
    """The category of the first reason code, as the simulator lists the binding reason first."""
    return category_of(reasons[0]) if reasons else Category.UNCLASSIFIED


# --------------------------------------------------------------------------------------------- reference labels

class LabelStatus(str, enum.Enum):
    LABELLED = 'LABELLED'
    EXCLUDED = 'EXCLUDED'


@dataclass(frozen=True)
class ReferenceLabel:
    """``h3.reference_static_exit_label/1`` for one candidate (module docstring). ``gross_r`` is None unless
    LABELLED."""

    candidate_id: str
    decision_ms: int
    status: LabelStatus
    gross_r: Decimal | None
    exit_reason: str | None
    exit_ms: int | None
    stopped: bool | None
    gap_through_stop: bool | None
    ambiguous_bar: bool | None
    reference_ms: int | None
    reasons: tuple[str, ...] = ()
    definition: str = LABEL_DEFINITION


def _rows(bars: Sequence[Bar], inst_id: str) -> list[Bar]:
    rows = sorted((b for b in bars if b.inst_id == inst_id and b.is_closed), key=lambda b: b.open_ms)
    for a, b in zip(rows, rows[1:]):
        if a.open_ms == b.open_ms and a != b:
            raise ContractError('h3_label_duplicate_bar_conflict')
    return [b for k, b in enumerate(rows) if k == 0 or rows[k - 1].open_ms != b.open_ms]


def _excluded(candidate: OpportunityCandidate, code: str, reference_ms: int | None = None) -> ReferenceLabel:
    return ReferenceLabel(candidate.candidate_id, candidate.header.clock.decision_ms, LabelStatus.EXCLUDED, None,
                          None, None, None, None, None, reference_ms, (code,))


def _crossed_stop(side: Side, price: Decimal, stop: Decimal) -> bool:
    return price <= stop if side is Side.LONG else price >= stop


def _target_through(side: Side, high: Decimal, low: Decimal, target: Decimal) -> bool:
    return high > target if side is Side.LONG else low < target


def reference_label(candidate: OpportunityCandidate, bars: Sequence[Bar]) -> ReferenceLabel:
    """The reference label of one candidate (module docstring). Pure: depends on the candidate and the bars of its
    instrument only."""
    side, inst = candidate.header.side, candidate.header.instrument
    decision = candidate.header.clock.decision_ms
    ref, stop, target = candidate.entry_reference_price, candidate.initial_stop_price, candidate.initial_target_price
    if side is None or stop is None or not stop_is_protective(side, ref, stop):
        return _excluded(candidate, 'stop_not_protective')
    rows = _rows(bars, inst.inst_id)
    usable = [b for b in rows if b.usable_at(decision)]
    if not usable:
        return _excluded(candidate, 'no_market_data_at_decision')
    t0 = usable[-1].close_ms
    path = [b for b in rows if b.open_ms >= t0]
    if not path:
        return _excluded(candidate, 'no_market_data_after_decision', t0)
    exit_open_ms = t0 + candidate.max_holding_ms
    distance = abs(ref - stop)
    sign = Decimal(1) if side is Side.LONG else Decimal(-1)
    expected_open, ambiguous = t0, False
    for bar in path:
        if bar.open_ms != expected_open:
            return _excluded(candidate, 'price_path_gap', t0)
        expected_open = bar.close_ms
        # at the open, in the simulator's order: resting stop, resting target, then the time exit
        if _crossed_stop(side, bar.open, stop):                  # gap through the stop at the open
            return _labelled(candidate, t0, sign * (bar.open - ref) / distance,
                             'stop' if bar.open == stop else 'stop_gap', bar.open_ms, True, bar.open != stop,
                             ambiguous)
        if target is not None and _target_through(side, bar.open, bar.open, target):
            return _labelled(candidate, t0, sign * (target - ref) / distance, 'target', bar.open_ms, False, False,
                             ambiguous)
        if bar.open_ms >= exit_open_ms:                          # time exit at this open
            return _labelled(candidate, t0, sign * (bar.open - ref) / distance, 'max_holding', bar.open_ms,
                             False, False, ambiguous)
        adverse = bar.low if side is Side.LONG else bar.high
        stop_hit = _crossed_stop(side, adverse, stop)
        target_hit = target is not None and _target_through(side, bar.high, bar.low, target)
        if stop_hit:
            return _labelled(candidate, t0, sign * (stop - ref) / distance, 'stop', bar.close_ms, True, False,
                             target_hit)
        if target_hit:
            assert target is not None
            return _labelled(candidate, t0, sign * (target - ref) / distance, 'target', bar.close_ms, False, False,
                             False)
    return _excluded(candidate, 'horizon_not_covered', t0)


def _labelled(candidate: OpportunityCandidate, t0: int, gross_r: Decimal, reason: str, exit_ms: int, stopped: bool,
              gap: bool, ambiguous: bool) -> ReferenceLabel:
    return ReferenceLabel(candidate.candidate_id, candidate.header.clock.decision_ms, LabelStatus.LABELLED, gross_r,
                          reason, exit_ms, stopped, gap, ambiguous, t0, ())


def reference_labels(candidates: Sequence[OpportunityCandidate], bars: Sequence[Bar]) -> dict[str, ReferenceLabel]:
    out: dict[str, ReferenceLabel] = {}
    for candidate in candidates:
        if candidate.candidate_id in out:
            raise ContractError('h3_label_candidate_duplicate')
        out[candidate.candidate_id] = reference_label(candidate, bars)
    return out


#: Label units accepted as the training target of each scorer head. Net units already contain realised costs: the
#: engine subtracts ``cost_r`` and ``funding_cost_r`` from the gross head, so training the gross head on a net unit
#: deducts the costs twice.
HEAD_TARGET_UNITS = {'gross_r': ('reference_gross_r', 'gross_r')}
NET_UNITS = ('net_r', 'net_gross_r')


def training_target(head: str, unit: str) -> str:
    """Refuse a label unit that does not match a head (``h3_label_unit_double_cost_deduction`` for a net unit on the
    gross head). Returns the unit when accepted."""
    if head not in HEAD_TARGET_UNITS:
        raise ContractError('h3_label_head_unknown')
    if unit in NET_UNITS:
        raise ContractError('h3_label_unit_double_cost_deduction')
    if unit not in HEAD_TARGET_UNITS[head]:
        raise ContractError('h3_label_unit_unknown')
    return unit


def net_reference_r(label: ReferenceLabel, cost_r: Decimal, funding_cost_r: Decimal) -> Decimal | None:
    """The reference label net of the scorer's deterministic ex-ante costs, in the unit of ``expected_net_r``. For
    calibration checks of ``expected_net_r`` only, never as a training target (``training_target``)."""
    if label.gross_r is None:
        return None
    return label.gross_r - cost_r - funding_cost_r


# ------------------------------------------------------------------------------------- technical eligibility

def technical_eligibility(candidate: OpportunityCandidate, bars: Sequence[Bar], gate_factory: Callable[[], Any], *,
                          quote_ccy: str, initial_equity: Decimal = H3_LIMITS.reference_equity,
                          data_end_ms: int | None = None) -> tuple[str, ...]:
    """Reasons why the candidate is NOT technically eligible (empty tuple: eligible). Uses information at T only
    (plus, retrospectively, the calendar end of the data window): a usable bar at T, then the risk gate's own
    admission on an empty fresh portfolio (stop, tick grid, settlement currency, minimum size / notional at the
    collar price). Never reads a bar after T."""
    decision = candidate.header.clock.decision_ms
    usable = [b for b in bars if b.inst_id == candidate.header.instrument.inst_id and b.usable_at(decision)]
    if not usable:
        known = any(b.inst_id == candidate.header.instrument.inst_id for b in bars)
        return ('no_market_data_at_decision' if known else 'no_market_data',)
    last = max(usable, key=lambda b: b.close_ms)
    gate = gate_factory()
    view = PortfolioView(portfolio_id='h3-eligibility', variant=Variant.B0, quote_ccy=quote_ccy,
                         clock=Clock(observed_ms=decision, available_ms=decision, decision_ms=decision),
                         equity=initial_equity, cash=initial_equity, open_risk=Decimal(0), open_notional=Decimal(0),
                         open_position_ids=(), kill_switch=False)
    decision_obj = gate.admit_entry(candidate, None, view, open_instruments=())
    reasons = [] if decision_obj.verdict is RiskVerdict.ADMIT else list(decision_obj.reasons)
    # calendar rule: the bar holding the time exit (opening at reference + max holding) must end inside the window.
    # Decided from T and the window end only; censoring at the label step instead would keep the candidates whose
    # stop or target was hit early and drop the others, an outcome-dependent selection.
    if data_end_ms is not None and last.close_ms + candidate.max_holding_ms + last.interval_ms > data_end_ms:
        reasons.append('horizon_not_covered')
    return tuple(dict.fromkeys(reasons))


# --------------------------------------------------------------------------------------------- the census

@dataclass(frozen=True)
class CandidateCensus:
    candidate_id: str
    decision_ms: int
    inst_id: str
    side: str
    eligible: bool
    labelable: bool
    executable: bool
    eligibility_reasons: tuple[str, ...]
    label_reasons: tuple[str, ...]
    execution_reasons: tuple[str, ...]


@dataclass(frozen=True)
class PopulationCensus:
    rows: tuple[CandidateCensus, ...]
    pre_candidate_refusals: tuple[tuple[str, ...], ...] = ()
    counts: dict[str, int] = field(default_factory=dict)

    def population(self, population: Population) -> tuple[str, ...]:
        if population is Population.ALL_CANDIDATES:
            return tuple(r.candidate_id for r in self.rows)
        if population is Population.TECHNICALLY_ELIGIBLE:
            return tuple(r.candidate_id for r in self.rows if r.eligible)
        if population is Population.LABELABLE:
            return tuple(r.candidate_id for r in self.rows if r.eligible and r.labelable)
        return tuple(r.candidate_id for r in self.rows if r.eligible and r.executable)

    def exclusion_table(self) -> list[dict[str, Any]]:
        """One row per (step, category): step ``generation`` (snapshots refused before a candidate exists),
        ``eligibility`` (ALL -> TECHNICALLY_ELIGIBLE), ``label`` (TECHNICALLY_ELIGIBLE -> LABELABLE), ``execution``
        (TECHNICALLY_ELIGIBLE -> EXECUTABLE, the old replay labels). Each row gives the timing and whether the
        exclusion can depend on the outcome."""
        steps: list[tuple[str, list[tuple[str, ...]]]] = [
            ('generation', [r for r in self.pre_candidate_refusals]),
            ('eligibility', [r.eligibility_reasons for r in self.rows if not r.eligible]),
            ('label', [r.label_reasons for r in self.rows if r.eligible and not r.labelable]),
            ('execution', [r.execution_reasons for r in self.rows if r.eligible and not r.executable]),
        ]
        table = []
        for step, reason_lists in steps:
            grouped: dict[Category, dict[str, int]] = {}
            for reasons in reason_lists:
                category = primary_category(reasons)
                codes = grouped.setdefault(category, {})
                key = reasons[0] if reasons else 'none'
                codes[key] = codes.get(key, 0) + 1
            for category in sorted(grouped, key=lambda x: x.value):
                timing = timing_of(category)
                table.append({'step': step, 'category': category.value, 'timing': timing.value,
                              'outcome_dependent': outcome_dependent(timing),
                              'count': sum(grouped[category].values()),
                              'reason_codes': dict(sorted(grouped[category].items()))})
        return table

    def to_report(self) -> dict[str, Any]:
        return {'schema': CENSUS_SCHEMA, 'label': 'SYNTHETIC_NOT_EVIDENCE_WHEN_FED_SYNTHETIC_DATA',
                'target_population': TARGET_POPULATION.value, 'label_definition': LABEL_DEFINITION,
                'counts': dict(self.counts), 'exclusions': self.exclusion_table(),
                'target_label_selection_outcome_dependent': any(
                    row['outcome_dependent'] for row in self.exclusion_table() if row['step'] == 'label')}


def population_census(candidates: Sequence[OpportunityCandidate], *, eligibility: Mapping[str, Sequence[str]],
                      reference: Mapping[str, ReferenceLabel], executability: Mapping[str, Any],
                      pre_candidate_refusals: Sequence[Sequence[str]] = ()) -> PopulationCensus:
    """Assign each candidate to the four populations. ``eligibility``: candidate id -> reasons from
    ``technical_eligibility`` (empty when eligible); ``reference``: from ``reference_labels``; ``executability``:
    candidate id -> ``integration.label_replay.CandidateLabel`` (``traded``, ``refusal_reasons``,
    ``horizon_covered``)."""
    rows = []
    for candidate in candidates:
        cid = candidate.candidate_id
        if cid not in eligibility or cid not in reference or cid not in executability:
            raise ContractError('h3_label_census_incomplete')
        label, replay = reference[cid], executability[cid]
        execution_reasons: tuple[str, ...] = tuple(replay.refusal_reasons)
        executable = bool(replay.traded)
        if executable and getattr(replay, 'horizon_covered', True) is False:
            execution_reasons = ('end_of_data_exit',)
            executable = False
        side = candidate.header.side
        rows.append(CandidateCensus(
            candidate_id=cid, decision_ms=candidate.header.clock.decision_ms,
            inst_id=candidate.header.instrument.inst_id, side=side.value if side is not None else 'none',
            eligible=not eligibility[cid], labelable=label.status is LabelStatus.LABELLED, executable=executable,
            eligibility_reasons=tuple(eligibility[cid]), label_reasons=label.reasons,
            execution_reasons=execution_reasons))
    census = PopulationCensus(rows=tuple(rows),
                              pre_candidate_refusals=tuple(tuple(r) for r in pre_candidate_refusals))
    counts = {'pre_candidate_refusals': len(census.pre_candidate_refusals)}
    counts.update({p.value: len(census.population(p)) for p in Population})
    counts['EXECUTABLE_AND_LABELABLE'] = sum(1 for r in rows if r.eligible and r.executable and r.labelable)
    counts['replay_traded_including_truncated'] = sum(1 for c in candidates if executability[c.candidate_id].traded)
    return PopulationCensus(rows=census.rows, pre_candidate_refusals=census.pre_candidate_refusals, counts=counts)


# ---------------------------------------------------------------------------------------- selection audit

def _mean(values: Sequence[float]) -> float | None:
    return math.fsum(values) / len(values) if values else None


def fill_bar_move(candidate: OpportunityCandidate, bars: Sequence[Bar]) -> float | None:
    """AUDIT ONLY (reads a price after T): side-signed move from the reference to the open of the replay's fill bar
    (the first bar opening at or after the decision). Positive = favourable to the candidate. Never a feature."""
    decision, side = candidate.header.clock.decision_ms, candidate.header.side
    rows = _rows(bars, candidate.header.instrument.inst_id)
    fill = next((b for b in rows if b.open_ms >= decision), None)
    if fill is None or side is None:
        return None
    move = float(fill.open / candidate.entry_reference_price - 1)
    return move if side is Side.LONG else -move


def collar_selection_audit(candidates: Sequence[OpportunityCandidate], bars: Sequence[Bar], *,
                           reference: Mapping[str, ReferenceLabel], executability: Mapping[str, Any],
                           collar_rate: Decimal, permutations: int = 2000, seed: int = 1) -> dict[str, Any]:
    """Pre-registered audit A2 (private PREREG-C-SYNTHETIC-AUDIT.md): does refusal at the collar depend on the
    post-decision path and on the outcome? Compares the replay-executable set E with the collar-excluded set X on
    the fill-bar move and on the reference gross label. Synthetic data only; the result is a property of the
    mechanism and of the example, not evidence about a market."""
    collar_codes = ('fill_beyond_collar', 'ioc_not_filled_beyond_collar')
    groups: dict[str, list[OpportunityCandidate]] = {'E': [], 'X': []}
    for candidate in candidates:
        replay = executability[candidate.candidate_id]
        if replay.traded:
            groups['E'].append(candidate)
        elif any(code in collar_codes for code in replay.refusal_reasons):
            groups['X'].append(candidate)
    moves = {k: [m for m in (fill_bar_move(c, bars) for c in v) if m is not None] for k, v in groups.items()}
    labels = {k: [float(reference[c.candidate_id].gross_r) for c in v  # type: ignore[arg-type]
                  if reference[c.candidate_id].gross_r is not None] for k, v in groups.items()}
    rate = float(collar_rate)
    by_construction = all(m > rate for m in moves['X']) and all(m <= rate + 1e-12 for m in moves['E'])
    diff = None if not labels['X'] or not labels['E'] else _mean(labels['X']) - _mean(labels['E'])  # type: ignore
    p_value = None
    if diff is not None:
        pooled, n_x = labels['X'] + labels['E'], len(labels['X'])
        rng, hits = random.Random(seed), 0
        for _ in range(permutations):
            rng.shuffle(pooled)
            if math.fsum(pooled[:n_x]) / n_x - math.fsum(pooled[n_x:]) / (len(pooled) - n_x) >= diff:
                hits += 1
        p_value = (hits + 1) / (permutations + 1)
    if not by_construction:
        verdict = 'SELECTION_RULE_NOT_REPRODUCED'
    elif diff is not None and diff > 0 and p_value is not None and p_value < 0.05:
        verdict = 'OUTCOME_DEPENDENT_SELECTION_PRESENT'
    else:
        verdict = 'NOT_DETECTED_IN_THIS_SYNTHETIC_EXAMPLE'
    return {'label': 'SYNTHETIC_NOT_EVIDENCE', 'collar_rate': rate,
            'executable_n': len(groups['E']), 'collar_excluded_n': len(groups['X']),
            'fill_move_mean': {k: _mean(v) for k, v in moves.items()},
            'fill_move_min': {k: (min(v) if v else None) for k, v in moves.items()},
            'fill_move_max': {k: (max(v) if v else None) for k, v in moves.items()},
            'reference_gross_r_n': {k: len(v) for k, v in labels.items()},
            'reference_gross_r_mean': {k: _mean(v) for k, v in labels.items()},
            'mean_difference_x_minus_e': diff, 'permutation_p_one_sided': p_value,
            'permutations': permutations, 'seed': seed, 'selection_by_construction': by_construction,
            'verdict': verdict}
