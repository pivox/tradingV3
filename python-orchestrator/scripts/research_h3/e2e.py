"""End-to-end synthetic example of H3: every component wired together on invented data. Not evidence of anything.

Run: ``python -m scripts.research_h3.e2e`` from ``python-orchestrator`` prints a short JSON summary. Everything here is
SYNTHETIC: six invented instruments (USDT-settled, shaped like perpetuals), invented hourly candles, quotes and
funding, a coin-flip candidate generator with no economic hypothesis, and a fictitious 500-USDT portfolio per
variant. Any PnL it prints is a synthetic number produced to exercise the code; it says nothing about any market and
no profitability is claimed.

Flow (H3-ARCHITECTURE.md, D2):

1. market: ``synthetic_market`` builds one ``validation.factorial.MarketStream`` (bars, funding, quotes). Two data
   defects are planted on purpose: FFF has no funding settlement during the first two days (PARTIAL snapshots) and
   DDD's quote is ten minutes old at one decision (STALE snapshot);
2. features: ``scoring.features.build_snapshot`` (agent A) builds one side-agnostic snapshot per instrument and
   decision instant, from data available at the decision only;
3. candidates: ``DataCheckedCandidateProvider`` refuses every snapshot that is not COMPLETE (reason codes, journalled)
   and delegates generation to ``integration.SyntheticCandidateProvider`` (agent D, hypothesis
   ``synthetic-no-economic-claim``) on the last visible close; the candidate is bound to the scoring snapshot. C's
   ``build_candidate_book`` calls it once per decision instant, before any variant and without any score;
4. scoring: ``scoring.ScoringEngine`` (agent A) on two models:
   (a) the reference null model: every score is UNESTIMATED, ``scored_variant_readiness`` is not empty, so B1/B3 are
       NOT_RUNNABLE and the experiment runs B0/B2 only and says so (no comparison verdict is computed);
   (b) A's labelled SYNTHETIC TEST FIXTURE calibrated manifest, which lets B1/B3 run technically;
5. risk, execution, management: each variant gets a fresh ``integration.H3RiskGate`` (with B's
   ``adaptive.guards.check_action`` injected as a position guard) and a fresh ``integration.validation_adapter.
   RunVariantSimulator`` (D's simulator behind C's ``VariantSimulator`` protocol); management is B's
   ``StaticPolicy`` (B0/B1) or ``AdaptivePolicyV1`` (B2/B3); every decision is journalled in D's hash-chained
   ``AuditJournal``;
6. comparison: C's ``run_factorial`` (base and pessimistic costs) and ``compare.evaluate``; its evidence label is
   ``synthetic_not_evidence``.

Research prototype: no market data file, no download, no exchange call, no order, no secret, no Temporal or Symfony
dependency, no production configuration.
"""
from __future__ import annotations

import functools
import hashlib
import json
from collections.abc import Mapping, Sequence
from dataclasses import dataclass, field, replace
from decimal import ROUND_CEILING, ROUND_FLOOR, Decimal
from pathlib import Path
from typing import Any

from .adaptive import AdaptivePolicyV1, GuardParams, StaticPolicy, check_action
from .contracts import (
    H3_LIMITS, ZERO, AuditEvent, Bar, Clock, CostModel, DataStatus, ExperimentManifest, FeatureSnapshot,
    FeatureStatus, FundingObservation, InstrumentRef, ModelManifest, OpportunityCandidate, Product, Quote,
    RiskSubject, RiskVerdict, Variant, canonical_json, check_reasons, sha256_text, stop_not_wider,
    stop_tightens, wire_decimal,
)
from .integration import (
    AuditJournal, ExecutionAssumptions, H3RiskConfig, H3RiskGate, SyntheticCandidateProvider, VariantRunResult,
    synthetic_snapshot,
)
from .integration.label_replay import CandidateLabel, capacity_free_labels, label_mapping
from .integration.validation_adapter import RunVariantSimulator, gross_r_label
from .scoring import ScoringEngine, build_snapshot, reference_null_manifest, scored_variant_readiness
from .scoring import labels as ref_labels
from .scoring.features import derived_features
from .scoring.fixtures import synthetic_fixture_manifest
from .synthetic import DRAFT_PROTOCOL, HOUR_MS, SYNTHETIC_PROVENANCE, T0_MS
from .validation import adaptive_checks
from .validation import metrics as mt
from .validation import scoring_checks
from .validation.compare import evaluate
from .validation.factorial import (
    ADAPTIVE, CandidateBook, DecisionInput, FactorialResult, MarketStream, VariantSpec, build_candidate_book,
    plan_entries, portfolio_id_for, run_factorial, score_book,
)
from .validation.plan import DAY_MS, StatisticalPlan
from .validation.records import CostScenario, VariantRun

D = Decimal
LABEL = 'SYNTHETIC_NOT_EVIDENCE'
BASES = ('AAA', 'BBB', 'CCC', 'DDD', 'EEE', 'FFF')
TICK = D('0.01')
QUOTE_CCY = 'USDT'
WARMUP_BARS = 24                     # the scoring catalogue needs 24 + 1 closed bars
DAYS = 8                             # simulated days after the warm-up day
N_BARS = WARMUP_BARS + DAYS * 24
DECISION_EVERY_BARS = 4
LATENCY_MS = 2_000                   # a bar is received 2 s after its close
FUNDING_INTERVAL_MS = 8 * HOUR_MS
FUNDING_OFFSET_MS = 30 * 60_000      # settlements at :30, never on a bar boundary
GAP_INSTRUMENT, GAP_BAR = 'CCC', 100   # one planted -4 % opening gap
NO_EARLY_FUNDING = 'FFF'             # no settlement during the first two days: PARTIAL snapshots
STALE_QUOTE = ('DDD', 5)             # (instrument, decision index) with a ten-minute-old quote: STALE snapshot
STOP_FRACTION = D('0.015')
MAX_HOLDING_MS = 12 * HOUR_MS
TARGET_R = D('2')
SEED = 20_261_015
INITIAL_EQUITY = H3_LIMITS.reference_equity   # 500, the H3 reference capital

# Fees: maker 0.02 % / taker 0.05 % as in the H3 mission brief; slippage and the flat simulator half-spread are
# invented synthetic values (DRAFT), not measurements.
COST_MODEL = CostModel(maker_fee_rate=D('0.0002'), taker_fee_rate=D('0.0005'), slippage_rate=D('0.0005'),
                       spread_model='observed-top-of-book', funding_model='settled-last-two',
                       pessimistic_multiplier=D('2'))
HALF_SPREAD_RATE = D('0.0002')
# DRAFT plan for the synthetic run only: daily blocks so that a 8-day example has blocks at all; few resamples.
E2E_PLAN = StatisticalPlan(block_ms=DAY_MS, resamples=199)


# ----------------------------------------------------------------------------------------------- code digest

def code_digest(package_dir: Path | None = None) -> str:
    """sha256 over the sorted list of [relative path, file sha256] of every file of ``scripts/research_h3`` (paths
    relative to ``python-orchestrator``, ``__pycache__`` excluded), as canonical JSON."""
    root = (package_dir or Path(__file__).resolve().parent)
    base = root.parent.parent
    entries = []
    for path in sorted(root.rglob('*')):
        if not path.is_file() or '__pycache__' in path.parts or path.suffix == '.pyc':
            continue
        entries.append([path.relative_to(base).as_posix(), hashlib.sha256(path.read_bytes()).hexdigest()])
    entries.sort()
    return sha256_text(canonical_json(entries))


# ---------------------------------------------------------------------------------------------- synthetic data

def _draw(*parts: Any) -> int:
    """Deterministic 32-bit draw from a hash (language-neutral; no random module)."""
    return int(hashlib.sha256(canonical_json([SEED, *parts]).encode()).hexdigest()[:8], 16)


def _tick(value: Decimal, rounding: str) -> Decimal:
    return (value / TICK).to_integral_value(rounding=rounding) * TICK


def instrument(base: str) -> InstrumentRef:
    """Invented specification (USDT-settled, contract value 0.01, tick 0.01, lot 1): not a venue's."""
    return InstrumentRef(venue='synthetic', product=Product.SYNTHETIC, inst_id=f'{base}-USDT-SYNTH', base=base,
                         quote=QUOTE_CCY, settle_ccy=QUOTE_CCY, contract_value=D('0.01'), tick_size=TICK,
                         lot_size=D('1'), min_size=D('1'), min_notional=None)


def decision_times() -> tuple[int, ...]:
    """Decision instants: 2 s after a bar boundary (when the bar is received), every 4 bars after the warm-up."""
    return tuple(T0_MS + k * HOUR_MS + LATENCY_MS
                 for k in range(WARMUP_BARS + 1, N_BARS - DECISION_EVERY_BARS + 1, DECISION_EVERY_BARS))


def _bars(j: int, base: str) -> list[Bar]:
    inst_id = instrument(base).inst_id
    previous = D(100 + 10 * j)
    out = []
    for k in range(N_BARS):
        open_ = previous
        if base == GAP_INSTRUMENT and k == GAP_BAR:
            open_ = _tick(previous * D('0.96'), ROUND_FLOOR)
        step = D((_draw('ret', base, k) % 241) - 120) / 10_000          # -1.20 % .. +1.20 %
        close = max(TICK, _tick(open_ * (1 + step), ROUND_FLOOR))
        wick = D(_draw('wick', base, k) % 40) / 10_000                   # 0 .. 0.39 %
        high = _tick(max(open_, close) * (1 + wick), ROUND_CEILING)
        low = max(TICK, _tick(min(open_, close) * (1 - wick), ROUND_FLOOR))
        open_ms = T0_MS + k * HOUR_MS
        out.append(Bar(inst_id=inst_id, interval_ms=HOUR_MS, open_ms=open_ms, close_ms=open_ms + HOUR_MS,
                       available_ms=open_ms + HOUR_MS + LATENCY_MS, is_closed=True, open=open_, high=high, low=low,
                       close=close, volume_quote=D(10_000 * (j + 1) + _draw('vol', base, k) % 5_000)))
        previous = close
    return out


def _funding(j: int, base: str) -> list[FundingObservation]:
    out = []
    end = T0_MS + N_BARS * HOUR_MS
    m = 0
    while T0_MS + m * FUNDING_INTERVAL_MS + FUNDING_OFFSET_MS < end:
        funding_ms = T0_MS + m * FUNDING_INTERVAL_MS + FUNDING_OFFSET_MS
        if not (base == NO_EARLY_FUNDING and funding_ms < T0_MS + 2 * DAY_MS):
            rate = D(j - 2) * D('0.00005') + D(_draw('fund', base, m) % 5) * D('0.00001')
            out.append(FundingObservation(inst_id=instrument(base).inst_id, funding_ms=funding_ms,
                                          available_ms=funding_ms + 1_000, rate=rate))
        m += 1
    return out


def _quotes(j: int, base: str, closes: Mapping[int, Decimal]) -> list[Quote]:
    out = []
    for index, decision_ms in enumerate(decision_times()):
        last = closes[decision_ms - LATENCY_MS]       # close of the bar ending at the boundary
        half = max(TICK, _tick(last * D('0.0001') * (j + 1), ROUND_FLOOR))   # half-spread (j + 1) bp
        observed = decision_ms - 1_000
        if (base, index) == STALE_QUOTE:
            observed = decision_ms - 600_000
        out.append(Quote(inst_id=instrument(base).inst_id, observed_ms=observed, available_ms=observed + 500,
                         bid=last - half, ask=last + half, bid_size=D('100'), ask_size=D('100')))
    return out


def synthetic_market() -> MarketStream:
    bars: list[Bar] = []
    funding: list[FundingObservation] = []
    quotes: list[Quote] = []
    for j, base in enumerate(BASES):
        rows = _bars(j, base)
        bars += rows
        funding += _funding(j, base)
        quotes += _quotes(j, base, {b.close_ms: b.close for b in rows})
    return MarketStream(bars=tuple(sorted(bars, key=lambda b: (b.open_ms, b.inst_id, b.interval_ms))),
                        funding=tuple(sorted(funding, key=lambda f: (f.funding_ms, f.inst_id))),
                        quotes=tuple(sorted(quotes, key=lambda q: (q.observed_ms, q.inst_id))))


def snapshots_at(market: MarketStream, decision_ms: int) -> tuple[FeatureSnapshot, ...]:
    """A's snapshots of every instrument, from data available at ``decision_ms`` only."""
    universe = {instrument(b).inst_id: market.bars_visible_at(decision_ms, instrument(b).inst_id)[-30:]
                for b in BASES}
    return tuple(build_snapshot(instrument=instrument(base), decision_ms=decision_ms,
                                provenance=SYNTHETIC_PROVENANCE, protocol=DRAFT_PROTOCOL,
                                snapshot_id=f'snap-{base.lower()}-{decision_ms}', universe_bars=universe,
                                quotes=market.quotes_visible_at(decision_ms, instrument(base).inst_id),
                                funding=market.funding_visible_at(decision_ms, instrument(base).inst_id))
                 for base in BASES)


# ------------------------------------------------------------------------------------------------- candidates

@dataclass(frozen=True)
class DataRefusal:
    """A snapshot refused before candidate generation because its data is not complete and fresh."""

    snapshot: FeatureSnapshot
    reasons: tuple[str, ...]


@dataclass
class DataCheckedCandidateProvider:
    """``contracts.CandidateProvider``: refuses incomplete or stale snapshots, then lets D's generator decide.

    The refusal is common to the four variants (it happens before any variant exists), so the factorial stays fair.
    D's ``SyntheticCandidateProvider`` reads a private price snapshot (last visible close, stop distance =
    ``stop_fraction`` x close); the candidate it returns keeps D's id, side, prices and holding time, and is bound to
    the scoring snapshot (its reference and clock) so that A's engine accepts it.
    """

    inner: SyntheticCandidateProvider
    market: MarketStream
    stop_fraction: Decimal = STOP_FRACTION
    refusals: list[DataRefusal] = field(default_factory=list)

    @property
    def generator_id(self) -> str:
        return self.inner.generator_id

    @property
    def generator_version(self) -> str:
        return self.inner.generator_version

    @property
    def hypothesis_id(self) -> str:
        return self.inner.hypothesis_id

    def candidates(self, snapshots: Sequence[FeatureSnapshot], decision_ms: int) -> tuple[OpportunityCandidate, ...]:
        out: list[OpportunityCandidate] = []
        for snapshot in sorted(snapshots, key=lambda s: s.snapshot_id):
            if snapshot.data_status is not DataStatus.COMPLETE:
                reasons = ('snapshot_data_' + snapshot.data_status.value,) + tuple(
                    f'feature_{f.status.value}.{f.name}' for f in snapshot.features if f.status is not FeatureStatus.OK)
                check_reasons(reasons)
                self.refusals.append(DataRefusal(snapshot, reasons))
                continue
            inst = snapshot.header.instrument
            last = self.market.bars_visible_at(decision_ms, inst.inst_id)[-1]
            price_snapshot = synthetic_snapshot(
                inst=inst, decision_ms=decision_ms, reference_price=last.close,
                stop_distance=_tick(last.close * self.stop_fraction, ROUND_CEILING),
                provenance=snapshot.header.provenance, protocol=snapshot.header.protocol,
                snapshot_id=snapshot.snapshot_id + '.price', observed_ms=last.close_ms)
            for candidate in self.inner.candidates((price_snapshot,), decision_ms):
                clock = snapshot.header.clock
                header = replace(candidate.header, clock=Clock(observed_ms=clock.observed_ms,
                                                               available_ms=clock.available_ms,
                                                               decision_ms=decision_ms))
                out.append(replace(candidate, header=header, snapshot=snapshot.ref()))
        return tuple(out)


# ------------------------------------------------------------------------------------------------ risk + policy

def position_guard_identity(params: GuardParams) -> dict[str, Any]:
    return {'guard': 'h3.adaptive.guards.check_action/1', 'protective_margin_ticks': params.protective_margin_ticks,
            'max_snapshot_age_ms': params.max_snapshot_age_ms}


def risk_config(scenario: CostScenario) -> H3RiskConfig:
    return H3RiskConfig(cost_model=COST_MODEL,
                        assumptions=ExecutionAssumptions(half_spread_rate=HALF_SPREAD_RATE,
                                                         pessimistic=scenario is CostScenario.PESSIMISTIC))


def gate_factory(scenario: CostScenario, guard_params: GuardParams) -> Any:
    """A fresh gate per call (one per variant), with B's position guard injected as defence in depth."""
    config = risk_config(scenario)

    def make() -> H3RiskGate:
        return H3RiskGate(config, action_guard=functools.partial(check_action, params=guard_params),
                          action_guard_identity=position_guard_identity(guard_params))
    return make


class _SimulatorFactory:
    """Fresh adapter per variant; keeps them to read D's raw results (audit journals) afterwards."""

    def __init__(self) -> None:
        self.created: list[RunVariantSimulator] = []

    def __call__(self) -> RunVariantSimulator:
        simulator = RunVariantSimulator()
        self.created.append(simulator)
        return simulator

    def results(self) -> dict[Variant, VariantRunResult]:
        out = {}
        for simulator in self.created:
            assert simulator.last_result is not None
            out[simulator.last_result.variant] = simulator.last_result
        return out


# -------------------------------------------------------------------------------------------- the experiment

@dataclass
class PathResult:
    """One experiment of the example (one scoring model)."""

    name: str
    manifest: ExperimentManifest
    model: ModelManifest
    readiness: tuple[str, ...]
    scores: tuple[Any, ...]
    runs: dict[Variant, VariantRun]
    raw: dict[Variant, VariantRunResult]
    pessimistic_runs: dict[Variant, VariantRun]
    pessimistic_raw: dict[Variant, VariantRunResult]
    book_journal: AuditJournal
    comparison: dict[str, Any] | None
    not_runnable: tuple[Variant, ...]


@dataclass
class E2EResult:
    market: MarketStream
    book: CandidateBook
    data_refusals: tuple[DataRefusal, ...]
    paths: dict[str, PathResult]
    report: dict[str, Any]
    labels: dict[str, CandidateLabel] = field(default_factory=dict)


def _manifest(experiment_id: str, book: CandidateBook, model: ModelManifest, static: StaticPolicy,
              adaptive: AdaptivePolicyV1, risk_policy: Any, digest: str) -> ExperimentManifest:
    return ExperimentManifest(
        experiment_id=experiment_id, protocol=DRAFT_PROTOCOL, variants=(Variant.B0, Variant.B1, Variant.B2, Variant.B3),
        candidate_generator_id=book.generator_id, candidate_generator_version=book.generator_version,
        hypothesis_id=book.hypothesis_id, scoring_model=model.ref(), static_policy=static.policy,
        adaptive_policy=adaptive.policy, risk_policy=risk_policy, limits=H3_LIMITS, cost_model=COST_MODEL,
        initial_equity=INITIAL_EQUITY, quote_ccy=QUOTE_CCY, datasets=(SYNTHETIC_PROVENANCE,), seed=SEED,
        code_digest=digest, created_ms=T0_MS)


def _book_journal(experiment_id: str, book: CandidateBook, refusals: Sequence[DataRefusal],
                  scores: Sequence[Any]) -> AuditJournal:
    """Variant-independent journal: data refusals, candidates and scores, in decision order."""
    journal = AuditJournal(experiment_id, None)
    events: list[tuple[int, int, str, Any]] = []
    for refusal in refusals:
        events.append((refusal.snapshot.header.clock.decision_ms, 0, refusal.snapshot.snapshot_id, refusal))
    for candidate in book.candidates:
        events.append((candidate.header.clock.decision_ms, 1, candidate.candidate_id, candidate))
    for score in scores:
        events.append((score.header.clock.decision_ms, 2, score.candidate_id, score))
    for _, kind, subject_id, item in sorted(events, key=lambda e: e[:3]):
        if kind == 0:
            snapshot = item.snapshot
            journal.record_private(header=snapshot.header, event=AuditEvent.REJECT, kind='data_refusal',
                                   subject_id=subject_id,
                                   document={'snapshot': snapshot.ref().to_wire(), 'reasons': list(item.reasons)},
                                   reasons=item.reasons)
        elif kind == 1:
            journal.record_contract(header=item.header, event=AuditEvent.CANDIDATE, subject=item,
                                    subject_id=subject_id)
        else:
            journal.record_contract(header=item.header, event=AuditEvent.SCORE, subject=item, subject_id=subject_id,
                                    model=item.model, reasons=item.reasons)
    return journal


def _run_reference_only(manifest: ExperimentManifest, book: CandidateBook, market: MarketStream,
                        static: StaticPolicy, adaptive: AdaptivePolicyV1, make_gate: Any, start_ms: int, end_ms: int,
                        scenario: CostScenario) -> tuple[dict[Variant, VariantRun], dict[Variant, VariantRunResult]]:
    """B0 and B2 only, with C's entry planning and the same adapter: what runs when B1/B3 are NOT_RUNNABLE."""
    runs: dict[Variant, VariantRun] = {}
    raws: dict[Variant, VariantRunResult] = {}
    book_fp, market_fp = book.fingerprint(), market.fingerprint()
    for variant in (Variant.B0, Variant.B2):
        portfolio_id = portfolio_id_for(manifest.experiment_id, variant)
        entries, refusals = plan_entries(variant, book, (), seed=manifest.seed, portfolio_id=portfolio_id)
        spec = VariantSpec(experiment_id=manifest.experiment_id, variant=variant, portfolio_id=portfolio_id,
                           quote_ccy=manifest.quote_ccy, initial_equity=manifest.initial_equity, start_ms=start_ms,
                           end_ms=end_ms, limits=H3_LIMITS, cost_model=manifest.cost_model, cost_scenario=scenario,
                           candidate_book_fingerprint=book_fp, market_fingerprint=market_fp, candidate_ids=book.ids,
                           entries=entries, pre_refusals=refusals,
                           policy=adaptive if variant in ADAPTIVE else static, risk_gate=make_gate())
        simulator = RunVariantSimulator()
        runs[variant] = simulator.run(spec, market)
        assert simulator.last_result is not None
        raws[variant] = simulator.last_result
    return runs, raws


def experiment_period() -> tuple[int, int]:
    """[start, end): from the open of the bar before the first decision to the last bar close."""
    return decision_times()[0] - LATENCY_MS - HOUR_MS, T0_MS + N_BARS * HOUR_MS


def run_e2e() -> E2EResult:
    market = synthetic_market()
    start_ms, end_ms = experiment_period()
    provider = DataCheckedCandidateProvider(
        inner=SyntheticCandidateProvider(seed=SEED, max_holding_ms=MAX_HOLDING_MS, target_r_multiple=TARGET_R),
        market=market)
    decisions = [DecisionInput(decision_ms=d, snapshots=snapshots_at(market, d)) for d in decision_times()]
    book = build_candidate_book(provider, decisions, start_ms=start_ms, end_ms=end_ms)
    refusals = tuple(provider.refusals)
    static, adaptive = StaticPolicy(), AdaptivePolicyV1()
    scorer = ScoringEngine(COST_MODEL)
    digest = code_digest()
    paths: dict[str, PathResult] = {}

    # (a) reference null model: UNESTIMATED everywhere, B1/B3 NOT_RUNNABLE, B0/B2 only.
    null_model = reference_null_manifest(protocol=DRAFT_PROTOCOL, created_ms=T0_MS)
    base_gate = gate_factory(CostScenario.BASE, adaptive.guard_params)
    manifest = _manifest('h3-e2e-null-model', book, null_model, static, adaptive, base_gate().policy, digest)
    readiness = scored_variant_readiness(null_model, manifest.datasets, manifest.protocol)
    scores = score_book(book, scorer, null_model)
    runs, raws = _run_reference_only(manifest, book, market, static, adaptive, base_gate, start_ms, end_ms,
                                     CostScenario.BASE)
    p_runs, p_raws = _run_reference_only(manifest, book, market, static, adaptive,
                                         gate_factory(CostScenario.PESSIMISTIC, adaptive.guard_params), start_ms,
                                         end_ms, CostScenario.PESSIMISTIC)
    paths['null_model'] = PathResult('null_model', manifest, null_model, readiness, scores, runs, raws, p_runs, p_raws,
                                     _book_journal(manifest.experiment_id, book, refusals, scores), None,
                                     (Variant.B1, Variant.B3) if readiness else ())

    # (b) SYNTHETIC TEST FIXTURE calibrated manifest: the full factorial runs technically.
    fixture = synthetic_fixture_manifest()
    manifest = _manifest('h3-e2e-fixture-model', book, fixture, static, adaptive, base_gate().policy, digest)
    readiness = scored_variant_readiness(fixture, manifest.datasets, manifest.protocol)
    if readiness:
        raise RuntimeError('h3_e2e_fixture_not_runnable')
    results: dict[CostScenario, FactorialResult] = {}
    raw_by_scenario: dict[CostScenario, dict[Variant, VariantRunResult]] = {}
    for scenario in (CostScenario.BASE, CostScenario.PESSIMISTIC):
        simulators = _SimulatorFactory()
        make_gate = gate_factory(scenario, adaptive.guard_params)
        # review R2-F3: the harness checks every gate against the declared risk policy (the manifest's under BASE
        # costs, the pessimistic gate's own under PESSIMISTIC) and needs the B1/B3 readiness verdict
        results[scenario] = run_factorial(
            manifest=manifest, book=book, market=market, scorer=scorer, model=fixture, static_policy=static,
            adaptive_policy=adaptive, risk_gate_factory=make_gate, simulator_factory=simulators,
            start_ms=start_ms, end_ms=end_ms, cost_scenario=scenario, risk_policy=make_gate().policy,
            scored_readiness=readiness)
        raw_by_scenario[scenario] = simulators.results()
    base, pessimistic = results[CostScenario.BASE], results[CostScenario.PESSIMISTIC]
    comparison = evaluate(base.runs, E2E_PLAN, manifest=manifest, pessimistic=pessimistic.runs)
    paths['fixture_model'] = PathResult('fixture_model', manifest, fixture, readiness, base.scores, dict(base.runs),
                                        raw_by_scenario[CostScenario.BASE], dict(pessimistic.runs),
                                        raw_by_scenario[CostScenario.PESSIMISTIC],
                                        _book_journal(manifest.experiment_id, book, refusals, base.scores),
                                        comparison, ())
    # review R1-F3: capacity-free labels, every candidate replayed alone under the static (B0) policy
    labels = capacity_free_labels(book.candidates, market.bars, policy=static, funding=market.funding,
                                  gate_factory=gate_factory(CostScenario.BASE, adaptive.guard_params),
                                  quote_ccy=QUOTE_CCY, experiment_id='h3-e2e-label-replay')
    result = E2EResult(market=market, book=book, data_refusals=refusals, paths=paths, report={}, labels=labels)
    result.report = build_report(result)
    return result


# ------------------------------------------------------------------------------------------- technical checks

def risk_checks(raw: VariantRunResult) -> dict[str, Any]:
    """Every ADMIT entry within 0.5 % / 1 % / 1x of the equity it was sized on; no ADMIT action adds risk."""
    entries = actions = 0
    breaches: list[str] = []
    for decision in raw.risk_decisions:
        if decision.verdict is not RiskVerdict.ADMIT:
            continue
        if decision.subject is RiskSubject.ENTRY:
            entries += 1
            if (decision.risk_amount > H3_LIMITS.per_trade_risk_max * decision.equity
                    or decision.open_risk_after > H3_LIMITS.total_risk_max * decision.equity
                    or decision.leverage_after > H3_LIMITS.leverage_max):
                breaches.append(decision.decision_id)
        else:
            actions += 1
            if decision.open_risk_after > decision.open_risk_before \
                    or decision.notional_after > decision.notional_before:
                breaches.append(decision.decision_id)
    return {'admitted_entries': entries, 'admitted_actions': actions, 'breaches': breaches,
            'respected': not breaches and raw.max_leverage <= H3_LIMITS.leverage_max}


def stop_checks(raw: VariantRunResult) -> dict[str, Any]:
    widened = [t.position_id for t in raw.trades if not stop_not_wider(t.side, t.initial_stop, t.final_stop)]
    tightened = [t.position_id for t in raw.trades if t.final_stop != t.initial_stop]
    bad_tightening = [t.position_id for t in raw.trades
                      if t.final_stop != t.initial_stop and not stop_tightens(t.side, t.initial_stop, t.final_stop)]
    return {'trades': len(raw.trades), 'stops_tightened': len(tightened), 'widened': widened + bad_tightening,
            'never_widened': not widened and not bad_tightening}


def _status_counts(values: Sequence[str]) -> dict[str, int]:
    out: dict[str, int] = {}
    for value in values:
        out[value] = out.get(value, 0) + 1
    return dict(sorted(out.items()))


def _run_summary(run: VariantRun, raw: VariantRunResult) -> dict[str, Any]:
    summary = mt.summarize(run, block_ms=E2E_PLAN.block_ms, top_trades_share=E2E_PLAN.guards.top_trades_share,
                           tail_quantile=E2E_PLAN.guards.tail_quantile,
                           stability_periods=E2E_PLAN.guards.stability_periods)
    raw_summary = raw.summary()
    return {'label': LABEL, 'run_fingerprint': run.fingerprint(), 'trades': len(run.trades),
            'refusals_by_stage': _status_counts([r.stage.value for r in run.refusals]),
            # reviews R1-F6 / R2-F5: refusal reasons (collar refusals included) and action-induced gap exits are
            # published per variant, because the execution-timing convention can select trades
            'refusal_reasons': _status_counts([x for r in run.refusals for x in r.reasons]),
            'action_application_gap_exits': raw_summary['action_application_gap_exits'],
            'fail_safe_closes': raw_summary['fail_safe_closes'],
            'exit_reasons': _status_counts([t.exit_reason.value for t in run.trades]),
            'synthetic_net_pnl': wire_decimal(run.net_pnl), 'synthetic_metrics': summary,
            'risk': risk_checks(raw), 'stops': stop_checks(raw),
            'audit': {'records': len(raw.audit), 'head': raw.audit.head_fingerprint(),
                      'violations': list(raw.audit.verify())}}


def cost_convention_check(path: PathResult, book: CandidateBook) -> dict[str, Any]:
    """Scorer cost in R (A: taker both legs + observed spread + slippage, over the gross stop distance) against the
    simulator's charged execution cost in the same unit, for the trades of B1. Descriptive; synthetic."""
    run = path.runs.get(Variant.B1)
    if run is None:
        return {'status': 'not_runnable'}
    candidates = {c.candidate_id: c for c in book.candidates}
    predicted, charged = ZERO, ZERO
    for trade in run.trades:
        candidate = candidates[trade.candidate_id]
        cost = derived_features(candidate, book.snapshot_for(candidate), COST_MODEL)['cost_r'].value
        assert cost is not None
        distance = abs(candidate.entry_reference_price - candidate.initial_stop_price) * trade.quantity \
            * trade.contract_value
        predicted += cost
        charged += trade.execution_cost / distance
    n = len(run.trades)
    labels = [gross_r_label(t, candidates[t.candidate_id].entry_reference_price,
                            candidates[t.candidate_id].initial_stop_price) for t in run.trades]
    return {'label': LABEL, 'trades': n,
            'mean_scorer_cost_r': wire_decimal((predicted / n).quantize(D('0.0001'))) if n else None,
            'mean_charged_cost_r': wire_decimal((charged / n).quantize(D('0.0001'))) if n else None,
            'gross_r_labels': n,
            'note': 'scorer: taker both legs + observed quote spread; simulator: taker entry/stop/close, maker target, '
                    'flat half-spread; R = gross stop distance in both columns'}


def population_census(result: E2EResult) -> dict[str, Any]:
    """The labels audit's population census on this example (``scoring.labels``): ALL_CANDIDATES,
    TECHNICALLY_ELIGIBLE (the scoring's target), LABELABLE (reference static-exit label) and EXECUTABLE (the replay's
    fills), with the exclusion table. Descriptive, synthetic. Not part of the default report (integration)."""
    candidates, bars = result.book.candidates, result.market.bars
    make_gate = gate_factory(CostScenario.BASE, AdaptivePolicyV1().guard_params)
    data_end_ms = T0_MS + N_BARS * HOUR_MS
    eligibility = {c.candidate_id: ref_labels.technical_eligibility(c, bars, make_gate, quote_ccy=QUOTE_CCY,
                                                                   data_end_ms=data_end_ms) for c in candidates}
    census = ref_labels.population_census(
        candidates, eligibility=eligibility, reference=ref_labels.reference_labels(candidates, bars),
        executability=result.labels, pre_candidate_refusals=[r.reasons for r in result.data_refusals])
    return census.to_report()


def label_check(result: E2EResult, *, census: bool = False) -> dict[str, Any]:
    """Capacity-free labels against B0 labels (review R1-F3). Descriptive, synthetic. With ``census`` (integration,
    labels audit change request) the population census is added under ``population_census``; the default output,
    which the report and its tests pin, is unchanged."""
    labels = result.labels
    if not labels:
        return {'status': 'not_computed'}
    per_instant: dict[int, int] = {}
    for label in labels.values():
        if label.traded:
            per_instant[label.decision_ms] = per_instant.get(label.decision_ms, 0) + 1
    out: dict[str, Any] = {
        'label': LABEL, 'candidates': len(labels), 'labelled': sum(1 for x in labels.values() if x.traded),
        'refused_alone': _status_counts([x.refusal_reasons[0] for x in labels.values() if not x.traded]),
        'instants_with_3_or_more_labels': sum(1 for n in per_instant.values() if n >= 3)}
    path = result.paths.get('fixture_model')
    if path is not None:
        start, _ = experiment_period()
        rows = scoring_checks.join_scores_labels(path.scores, label_mapping(labels))
        b0_rows = scoring_checks.join_scores_outcomes(path.scores, path.runs[Variant.B0])
        rank = scoring_checks.rank_ic_test(rows, start_ms=start, block_ms=E2E_PLAN.block_ms, alpha=0.05,
                                           procedure=E2E_PLAN.test_procedure, resamples=E2E_PLAN.resamples)
        b0_rank = scoring_checks.rank_ic_test(b0_rows, start_ms=start, block_ms=E2E_PLAN.block_ms, alpha=0.05,
                                              procedure=E2E_PLAN.test_procedure, resamples=E2E_PLAN.resamples)
        out['rank_ic_blocks'] = {'capacity_free': rank['n'], 'b0_labels': b0_rank['n']}
        out['b0_unlabelled'] = scoring_checks.abstention(b0_rows)['unlabelled']
    if census:
        out['population_census'] = population_census(result)
    return out


def build_report(result: E2EResult) -> dict[str, Any]:
    report: dict[str, Any] = {
        'schema': 'h3.e2e_report/1', 'label': LABEL,
        'statement': 'Synthetic data only. Every PnL figure is synthetic and exercises the code; no profitability '
                     'is claimed and nothing here is evidence about any market.',
        'market_fingerprint': result.market.fingerprint(), 'candidate_book_fingerprint': result.book.fingerprint(),
        'instruments': len(BASES), 'bars': len(result.market.bars), 'decision_instants': len(decision_times()),
        'candidates': len(result.book.candidates),
        'data_refusals': {'count': len(result.data_refusals),
                          'reasons': _status_counts([r for x in result.data_refusals for r in x.reasons[:1]])},
        'paths': {},
        'capacity_free_labels': label_check(result),
    }
    for name, path in result.paths.items():
        entry: dict[str, Any] = {
            'experiment_id': path.manifest.experiment_id, 'manifest_fingerprint': path.manifest.fingerprint(),
            'model': path.model.ref().to_wire(), 'readiness_reasons': list(path.readiness),
            'not_runnable': [v.value for v in path.not_runnable],
            'score_status': _status_counts([s.status.value for s in path.scores]),
            'score_verdict': _status_counts([s.verdict.value for s in path.scores]),
            'scores_with_numbers': sum(1 for s in path.scores if s.expected_net_r is not None),
            'book_journal': {'records': len(path.book_journal), 'head': path.book_journal.head_fingerprint(),
                             'violations': list(path.book_journal.verify())},
            'variants': {v.value: _run_summary(path.runs[v], path.raw[v])
                         for v in sorted(path.runs, key=lambda x: x.value)},
            'pessimistic_variants': {v.value: _run_summary(path.pessimistic_runs[v], path.pessimistic_raw[v])
                                     for v in sorted(path.pessimistic_runs, key=lambda x: x.value)},
            'convention_checks': {
                'fill_realism_findings': {v.value: _status_counts([f['reason'] for f in adaptive_checks.
                                          fill_realism_findings(path.runs[v].trades, _bars_by_inst(result.market))])
                                          for v in sorted(path.runs, key=lambda x: x.value)},
                'funding_coverage_findings': {v.value: len(adaptive_checks.funding_coverage_findings(
                    path.runs[v].trades, result.market.funding)) for v in sorted(path.runs, key=lambda x: x.value)},
                'scorer_vs_simulator_costs': cost_convention_check(path, result.book),
            },
        }
        if path.comparison is None:
            entry['comparison'] = {'status': 'not_computed_b1_b3_not_runnable',
                                   'reason': 'compare.evaluate needs the full B0-B3 factorial; with an uncalibrated '
                                             'model only B0 and B2 run, reported descriptively'}
        else:
            c = path.comparison
            entry['comparison'] = {
                'label': LABEL, 'evidence': c['evidence'], 'verdict': c['verdict'], 'plan_status': c['plan_status'],
                'plan_fingerprint': c['plan_fingerprint'], 'primary_met': c['primary']['met'],
                'guards': {k: {'passed': g['passed'], 'reason': g['reason']} for k, g in c['guards'].items()},
                'hypotheses': c['hypotheses']}
        report['paths'][name] = entry
    return report


def _bars_by_inst(market: MarketStream) -> dict[str, list[Bar]]:
    out: dict[str, list[Bar]] = {}
    for bar in market.bars:
        out.setdefault(bar.inst_id, []).append(bar)
    return out


def report_fingerprint(report: Mapping[str, Any]) -> str:
    """Fingerprint of the report (floats as repr, NaN allowed) for determinism checks."""
    return sha256_text(json.dumps(report, sort_keys=True, separators=(',', ':'), default=str))


def main() -> None:
    result = run_e2e()
    report = result.report
    short = {'label': report['label'], 'statement': report['statement'], 'candidates': report['candidates'],
             'data_refusals': report['data_refusals'], 'report_fingerprint': report_fingerprint(report), 'paths': {}}
    for name, entry in report['paths'].items():
        short['paths'][name] = {
            'readiness_reasons': entry['readiness_reasons'], 'not_runnable': entry['not_runnable'],
            'score_status': entry['score_status'], 'comparison': entry['comparison'],
            'variants': {v: {'trades': s['trades'], 'synthetic_net_pnl': s['synthetic_net_pnl'],
                             'risk_respected': s['risk']['respected'],
                             'stops_never_widened': s['stops']['never_widened'],
                             'audit_violations': s['audit']['violations']} for v, s in entry['variants'].items()}}
    print(json.dumps(short, indent=2, sort_keys=True, default=str))


if __name__ == '__main__':
    main()
