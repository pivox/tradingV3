"""Chronological validation (C6): walk-forward folds, purge, embargo, train-only transforms, point-in-time universe
and the ledger of development trials.

Purge rule. A model fitted at ``train_end_ms`` may only use labels that were fully realised by then: a training label
whose horizon ends after ``train_end_ms`` is purged (its outcome was not known at fit time, and it overlaps the
embargo or the test period). Embargo: ``test_start_ms = train_end_ms + embargo_ms``; nothing decided inside the gap
is used, so serially correlated features cannot carry the training labels into the test period. A test label whose
horizon ends after ``test_end_ms`` is flagged ``crossing``; it is evaluated (or dropped, by option) in its own fold,
and every later fold purges it from training by the same rule.

Train-only transforms. ``TrainOnlyScaler`` and ``TrainOnlyQuantileEdges`` refuse at fit time any row available at or
after their cutoff, and record the cutoff, so their output can be checked against the rows it is applied to.

Point-in-time universe. An instrument belongs to the universe of a decision only when it was listed and its listing
was known (``known_ms``) at the decision, and it was not delisted before. Candidates on instruments listed after the
decision are lookahead; a universe built from today's list of live instruments silently drops the delisted ones
(survivorship): ``survivorship_findings`` detects it by comparing with the full listing history.
"""
from __future__ import annotations

import math
from collections.abc import Iterable, Sequence
from dataclasses import dataclass, field

from ..contracts import DataLabel
from .stats import sidak_alpha


class WalkForwardError(ValueError):
    """A chronological rule is broken. The message is a stable reason code."""


# --------------------------------------------------------------------------------------------------------- folds

@dataclass(frozen=True)
class Fold:
    index: int
    train_start_ms: int
    train_end_ms: int
    test_start_ms: int
    test_end_ms: int
    embargo_ms: int

    def __post_init__(self) -> None:
        if not (0 <= self.train_start_ms < self.train_end_ms <= self.test_start_ms < self.test_end_ms) \
                or self.test_start_ms - self.train_end_ms != self.embargo_ms or self.embargo_ms < 0:
            raise WalkForwardError('walkforward_fold_invalid')


def walk_forward_folds(start_ms: int, end_ms: int, *, train_ms: int, test_ms: int, embargo_ms: int,
                       expanding: bool = True) -> tuple[Fold, ...]:
    """Consecutive, non-overlapping test periods; training always strictly before its test period."""
    if min(train_ms, test_ms) <= 0 or embargo_ms < 0 or end_ms <= start_ms:
        raise WalkForwardError('walkforward_parameters_invalid')
    folds = []
    train_start, train_end = start_ms, start_ms + train_ms
    while True:
        test_start = train_end + embargo_ms
        test_end = test_start + test_ms
        if test_end > end_ms:
            break
        folds.append(Fold(len(folds), train_start, train_end, test_start, test_end, embargo_ms))
        train_end += test_ms
        if not expanding:
            train_start += test_ms
    if not folds:
        raise WalkForwardError('walkforward_no_fold_fits')
    return tuple(folds)


@dataclass(frozen=True)
class Label:
    """A supervised label: the outcome of a decision at ``decision_ms``, realised at ``horizon_end_ms``."""

    label_id: str
    decision_ms: int
    horizon_end_ms: int

    def __post_init__(self) -> None:
        if self.horizon_end_ms < self.decision_ms or self.decision_ms < 0:
            raise WalkForwardError('walkforward_label_invalid')


@dataclass(frozen=True)
class FoldAssignment:
    fold: Fold
    train: tuple[str, ...]
    test: tuple[str, ...]
    purged: tuple[str, ...]           # training-period labels not realised by train_end
    embargoed: tuple[str, ...]        # decided inside the embargo gap
    test_crossing: tuple[str, ...]    # test labels realised after test_end (kept unless dropped)


def assign(labels: Iterable[Label], fold: Fold, *, drop_test_crossing: bool = False) -> FoldAssignment:
    train, test, purged, embargoed, crossing = [], [], [], [], []
    for label in sorted(labels, key=lambda x: (x.decision_ms, x.label_id)):
        if fold.train_start_ms <= label.decision_ms < fold.train_end_ms:
            (purged if label.horizon_end_ms > fold.train_end_ms else train).append(label.label_id)
        elif fold.train_end_ms <= label.decision_ms < fold.test_start_ms:
            embargoed.append(label.label_id)
        elif fold.test_start_ms <= label.decision_ms < fold.test_end_ms:
            if label.horizon_end_ms > fold.test_end_ms:
                crossing.append(label.label_id)
                if drop_test_crossing:
                    continue
            test.append(label.label_id)
    return FoldAssignment(fold, tuple(train), tuple(test), tuple(purged), tuple(embargoed), tuple(crossing))


def check_assignment(assignment: FoldAssignment, labels: Iterable[Label]) -> None:
    """Re-verifies an assignment produced elsewhere: no training label realised after the fit, none in the test."""
    by_id = {label.label_id: label for label in labels}
    fold = assignment.fold
    if set(assignment.train) & set(assignment.test):
        raise WalkForwardError('walkforward_train_test_overlap')
    for label_id in assignment.train:
        label = by_id[label_id]
        if label.horizon_end_ms > fold.train_end_ms or label.decision_ms >= fold.train_end_ms:
            raise WalkForwardError('walkforward_training_label_not_realised_at_fit')
    for label_id in assignment.test:
        if not fold.test_start_ms <= by_id[label_id].decision_ms < fold.test_end_ms:
            raise WalkForwardError('walkforward_test_label_outside_test')


# ------------------------------------------------------------------------------------------ train-only transforms

@dataclass(frozen=True)
class Row:
    """One observation available to the process at ``available_ms``."""

    available_ms: int
    value: float


def _fit_rows(rows: Sequence[Row], cutoff_ms: int) -> list[float]:
    if not rows:
        raise WalkForwardError('walkforward_fit_empty')
    for row in rows:
        if row.available_ms >= cutoff_ms:
            raise WalkForwardError('walkforward_fit_uses_data_after_cutoff')
        if not math.isfinite(row.value):
            raise WalkForwardError('walkforward_fit_non_finite')
    return [row.value for row in rows]


@dataclass(frozen=True)
class FittedScaler:
    mean: float
    scale: float
    fitted_until_ms: int
    fitted_rows: int

    def transform(self, value: float) -> float:
        return (value - self.mean) / self.scale


class TrainOnlyScaler:
    """Standardisation whose mean and scale come from rows available strictly before ``cutoff_ms`` only."""

    @staticmethod
    def fit(rows: Sequence[Row], cutoff_ms: int) -> FittedScaler:
        values = _fit_rows(rows, cutoff_ms)
        m = sum(values) / len(values)
        sd = math.sqrt(sum((v - m) ** 2 for v in values) / (len(values) - 1)) if len(values) > 1 else 0.0
        return FittedScaler(m, sd if sd > 0 else 1.0, cutoff_ms, len(values))


@dataclass(frozen=True)
class FrozenEdges:
    """Bucket edges fitted before ``fitted_until_ms``; applicable only to rows decided at or after it."""

    edges: tuple[float, ...]
    fitted_until_ms: int
    source: str

    def __post_init__(self) -> None:
        if any(b <= a for a, b in zip(self.edges, self.edges[1:])) or not self.edges:
            raise WalkForwardError('walkforward_edges_not_increasing')

    def check_out_of_sample(self, decision_ms: int) -> None:
        if decision_ms < self.fitted_until_ms:
            raise WalkForwardError('walkforward_edges_applied_in_sample')

    def bucket(self, value: float) -> int:
        """0 .. len(edges): number of edges <= value."""
        return sum(1 for e in self.edges if value >= e)


class TrainOnlyQuantileEdges:
    @staticmethod
    def fit(rows: Sequence[Row], cutoff_ms: int, quantiles: Sequence[float]) -> FrozenEdges:
        values = sorted(_fit_rows(rows, cutoff_ms))
        edges = []
        for q in quantiles:
            if not 0 < q < 1:
                raise WalkForwardError('walkforward_quantile_invalid')
            position = q * (len(values) - 1)
            lo = int(math.floor(position))
            hi = min(lo + 1, len(values) - 1)
            edges.append(values[lo] + (values[hi] - values[lo]) * (position - lo))
        return FrozenEdges(tuple(edges), cutoff_ms, 'train_quantiles')


def a_priori_edges(edges: Sequence[float]) -> FrozenEdges:
    """Edges fixed in the protocol before any data (e.g. expected net R cut points): valid from time 0."""
    return FrozenEdges(tuple(float(e) for e in edges), 0, 'a_priori')


# ---------------------------------------------------------------------------------------- point-in-time universe

@dataclass(frozen=True)
class ListingRecord:
    inst_id: str
    listed_ms: int
    known_ms: int                  # when the listing was public (announcement or first available data)
    delisted_ms: int | None = None

    def __post_init__(self) -> None:
        if self.listed_ms < 0 or self.known_ms < 0 or (self.delisted_ms is not None
                                                       and self.delisted_ms <= self.listed_ms):
            raise WalkForwardError('walkforward_listing_invalid')

    def alive_at(self, ms: int) -> bool:
        return self.listed_ms <= ms and (self.delisted_ms is None or ms < self.delisted_ms)


def universe_at(records: Iterable[ListingRecord], decision_ms: int) -> tuple[str, ...]:
    return tuple(sorted(r.inst_id for r in records if r.alive_at(decision_ms) and r.known_ms <= decision_ms))


def audit_candidate_universe(candidates: Iterable[tuple[str, int]], records: Iterable[ListingRecord]) -> list[dict]:
    """Findings for (inst_id, decision_ms) pairs outside the point-in-time universe of their decision."""
    by_id = {r.inst_id: r for r in records}
    findings = []
    for inst_id, decision_ms in candidates:
        record = by_id.get(inst_id)
        if record is None:
            reason = 'universe_instrument_unknown'
        elif record.listed_ms > decision_ms or record.known_ms > decision_ms:
            reason = 'universe_listed_after_decision'
        elif record.delisted_ms is not None and decision_ms >= record.delisted_ms:
            reason = 'universe_delisted_before_decision'
        else:
            continue
        findings.append({'inst_id': inst_id, 'decision_ms': decision_ms, 'reason': reason})
    return findings


def survivorship_findings(used: Iterable[ListingRecord], full_history: Iterable[ListingRecord], start_ms: int,
                          end_ms: int) -> list[dict]:
    """Instruments alive at some point of [start_ms, end_ms) in the full history but absent from ``used``."""
    used_ids = {r.inst_id for r in used}
    findings = []
    for r in sorted(full_history, key=lambda x: x.inst_id):
        alive = r.listed_ms < end_ms and (r.delisted_ms is None or r.delisted_ms > start_ms)
        if alive and r.inst_id not in used_ids:
            findings.append({'inst_id': r.inst_id, 'delisted_ms': r.delisted_ms,
                             'reason': 'universe_survivorship_gap' if r.delisted_ms is not None
                             else 'universe_live_instrument_missing'})
    return findings


# --------------------------------------------------------------------------------------------------- trial ledger

@dataclass(frozen=True)
class Trial:
    trial_id: str
    kind: str              # generator | model | policy | threshold | feature_set
    fingerprint: str
    data_label: DataLabel
    recorded_ms: int


@dataclass
class TrialLedger:
    """Every configuration evaluated during development is recorded, so the number of models and comparisons tried
    before the freeze is known and disclosed. Trials on validation, holdout or prospective data are refused: those
    data are read once, by the frozen protocol, never to choose a configuration."""

    trials: list[Trial] = field(default_factory=list)

    def record(self, trial: Trial) -> None:
        if trial.data_label not in (DataLabel.SYNTHETIC, DataLabel.DEVELOPMENT):
            raise WalkForwardError('walkforward_trial_on_confirmatory_data')
        if any(t.trial_id == trial.trial_id for t in self.trials):
            raise WalkForwardError('walkforward_trial_duplicate')
        self.trials.append(trial)

    def count(self, kind: str | None = None, label: DataLabel | None = None) -> int:
        return sum(1 for t in self.trials if (kind is None or t.kind == kind) and (label is None or t.data_label is label))

    def selection_alpha(self, alpha: float, kind: str) -> float:
        """Per-trial level that would keep the family-wise error at ``alpha`` across the recorded trials of a kind
        (Sidak). Disclosed with the result; the confirmatory test itself uses one frozen configuration."""
        return sidak_alpha(alpha, max(1, self.count(kind)))

    def check_selected(self, fingerprints: Iterable[str]) -> None:
        known = {t.fingerprint for t in self.trials}
        for fingerprint in fingerprints:
            if fingerprint not in known:
                raise WalkForwardError('walkforward_selected_configuration_not_recorded')

    def summary(self) -> dict:
        kinds: dict[str, int] = {}
        for t in self.trials:
            kinds[t.kind] = kinds.get(t.kind, 0) + 1
        return {'trials': len(self.trials), 'by_kind': dict(sorted(kinds.items())),
                'development_trials': self.count(label=DataLabel.DEVELOPMENT),
                'synthetic_trials': self.count(label=DataLabel.SYNTHETIC)}
