"""H3 end-to-end synthetic example and integration seams (integrator).

Technical properties only: identical inputs for the four variants, risk caps, stops never widened, audit chains,
determinism, refusals of bad data, conventions at the seams between the four components. Nothing here asserts or
reports a profitability: every PnL in the example is synthetic and is never evidence.
Run: python -m pytest --noconftest tests/test_research_h3_e2e.py
"""
from __future__ import annotations

import dataclasses
import functools
import json
from decimal import Decimal as D

import pytest

from scripts.research_h3 import contracts as c
from scripts.research_h3 import e2e
from scripts.research_h3 import synthetic as s
from scripts.research_h3.adaptive import AdaptivePolicyV1, GuardParams, check_action
from scripts.research_h3.integration import AuditJournal, ExecutionAssumptions, H3RiskConfig, H3RiskGate
from scripts.research_h3.integration.simulator import run_variant
from scripts.research_h3.integration.validation_adapter import EXIT_REASONS, RunVariantSimulator
from scripts.research_h3.validation import metrics as mt
from scripts.research_h3.validation.compare import VERDICTS
from scripts.research_h3.validation.factorial import VariantSpec, plan_entries, portfolio_id_for
from scripts.research_h3.validation.records import CostScenario, RefusalStage

FORBIDDEN_STATUSES = ('H3_GATE1_PASS', 'STRATEGY_VALIDATED', 'PROFITABLE', 'TRADING_GO')


@pytest.fixture(scope='module')
def result() -> e2e.E2EResult:
    return e2e.run_e2e()


def _all_raw(result):
    for path in result.paths.values():
        for raws in (path.raw, path.pessimistic_raw):
            yield from raws.values()


def _all_runs(result):
    for path in result.paths.values():
        for runs in (path.runs, path.pessimistic_runs):
            yield from runs.values()


# ---------------------------------------------------------------------------------------------------- labelling

def test_the_example_is_labelled_synthetic_and_claims_nothing(result):
    report = result.report
    assert report['label'] == 'SYNTHETIC_NOT_EVIDENCE'
    assert 'no profitability is claimed' in report['statement']
    text = json.dumps(report, default=str)
    for status in FORBIDDEN_STATUSES:
        assert status not in text
    fixture = report['paths']['fixture_model']['comparison']
    assert fixture['evidence'] == 'synthetic_not_evidence'
    assert fixture['plan_status'] == 'DRAFT'
    assert fixture['verdict'] in VERDICTS
    # eight daily blocks are below the DRAFT minimum information (26 blocks): the plan says so by itself
    assert fixture['verdict'] == 'INSUFFICIENT_EVIDENCE'
    assert set(fixture['hypotheses'].values()) == {'not_confirmatory'}
    for path in report['paths'].values():
        for summary in list(path['variants'].values()) + list(path['pessimistic_variants'].values()):
            assert summary['label'] == 'SYNTHETIC_NOT_EVIDENCE'
    for path in result.paths.values():
        assert {p.label for p in path.manifest.datasets} == {c.DataLabel.SYNTHETIC}
        assert path.manifest.protocol.status is c.ProtocolStatus.DRAFT
        assert path.manifest.hypothesis_id == 'synthetic-no-economic-claim'


def test_code_digest_method_and_manifest(result):
    digest = e2e.code_digest()
    assert digest.startswith('sha256:') and len(digest) == 71
    assert all(path.manifest.code_digest == digest for path in result.paths.values())


# ------------------------------------------------------------------------------------------------ data and inputs

def test_insufficient_data_is_refused_with_reason_codes_before_any_variant(result):
    refusals = result.data_refusals
    first = {r.reasons[0] for r in refusals}
    assert {'snapshot_data_partial', 'snapshot_data_stale'} <= first
    assert any('feature_missing.funding_rate_hourly' in r.reasons for r in refusals)
    assert any('feature_stale.spread_bp' in r.reasons for r in refusals)
    refused = {r.snapshot.snapshot_id for r in refusals}
    assert refused.isdisjoint({cand.snapshot.snapshot_id for cand in result.book.candidates})
    assert all(snap.data_status is c.DataStatus.COMPLETE for snap in result.book.snapshots)
    for path in result.paths.values():
        rejects = [a for a in path.book_journal.records if a.event is c.AuditEvent.REJECT]
        assert len(rejects) == len(refusals)
        assert all(a.reasons and a.reasons[0].startswith('snapshot_data_') for a in rejects)


def test_same_candidates_and_market_for_every_variant(result):
    book_fp, market_fp = result.book.fingerprint(), result.market.fingerprint()
    for run in _all_runs(result):
        assert run.candidate_ids == result.book.ids
        assert run.candidate_book_fingerprint == book_fp and run.market_fingerprint == market_fp
        assert run.initial_equity == D('500') and run.quote_ccy == 'USDT'
    assert result.market.fingerprint() == result.market.fingerprint_at_construction
    assert result.book.hypothesis_id == 'synthetic-no-economic-claim'
    # B0/B2 entries are score-blind: identical across the two scoring models
    null, fixture = result.paths['null_model'], result.paths['fixture_model']
    for variant in (c.Variant.B0, c.Variant.B2):
        a, b = null.runs[variant], fixture.runs[variant]
        assert [(t.candidate_id, t.entry_ms, t.exit_ms, t.net_pnl) for t in a.trades] == \
            [(t.candidate_id, t.entry_ms, t.exit_ms, t.net_pnl) for t in b.trades]


def test_portfolios_are_independent(result):
    for path in result.paths.values():
        ids = {run.portfolio_id for run in path.runs.values()}
        assert len(ids) == len(path.runs)
        for variant, run in path.runs.items():
            assert run.portfolio_id == portfolio_id_for(path.manifest.experiment_id, variant)
            assert all(t.portfolio_id == run.portfolio_id for t in run.trades)


# ---------------------------------------------------------------------------------------------- the two models

def test_uncalibrated_model_gives_unestimated_scores_and_b0_b2_only(result):
    path = result.paths['null_model']
    assert {'model_reference_null', 'model_uncalibrated'} <= set(path.readiness)
    assert path.not_runnable == (c.Variant.B1, c.Variant.B3)
    assert set(path.runs) == {c.Variant.B0, c.Variant.B2}
    assert all(sc.status is c.ScoreStatus.UNESTIMATED and sc.verdict is c.ScoreVerdict.NO_TRADE for sc in path.scores)
    assert all(sc.expected_net_r is None and sc.p_stop is None and sc.utility is None for sc in path.scores)
    assert path.comparison is None
    assert result.report['paths']['null_model']['comparison']['status'] == 'not_computed_b1_b3_not_runnable'


def test_fixture_model_runs_the_full_factorial_with_score_refusals(result):
    path = result.paths['fixture_model']
    assert path.readiness == ()
    assert path.model.notes.startswith('SYNTHETIC TEST FIXTURE')
    assert set(path.runs) == set(path.pessimistic_runs) == {c.Variant.B0, c.Variant.B1, c.Variant.B2, c.Variant.B3}
    assert all(sc.status is c.ScoreStatus.ESTIMATED for sc in path.scores)
    for variant in (c.Variant.B1, c.Variant.B3):
        stages = {r.stage for r in path.runs[variant].refusals}
        assert RefusalStage.SCORE in stages
    for variant in (c.Variant.B0, c.Variant.B2):
        assert RefusalStage.SCORE not in {r.stage for r in path.runs[variant].refusals}
    assert path.runs[c.Variant.B1].refusals[0].variant is c.Variant.B1


def _is_admission(decision, decided) -> bool:
    """An entry admission (not a fill re-check, which is stamped at the fill bar's open)."""
    return decision.subject is c.RiskSubject.ENTRY and decision.header.clock.decision_ms == decided[decision.subject_id]


def test_scored_entries_reach_the_gate_in_rank_order(result):
    path = result.paths['fixture_model']
    rank = {sc.candidate_id: sc.rank for sc in path.scores}
    decided = {cand.candidate_id: cand.header.clock.decision_ms for cand in result.book.candidates}
    for variant in (c.Variant.B1, c.Variant.B3):
        order: dict[int, list[int]] = {}
        for decision in path.raw[variant].risk_decisions:
            if _is_admission(decision, decided):
                order.setdefault(decided[decision.subject_id], []).append(rank[decision.subject_id])
        assert order and all(ranks == sorted(ranks) for ranks in order.values())
    # B0/B2: the seeded lottery of the harness, not the candidate id
    entries, _ = plan_entries(c.Variant.B0, result.book, (), seed=path.manifest.seed, portfolio_id='pf')
    priority = {e.candidate.candidate_id: e.priority for e in entries}
    seen: dict[int, list[int]] = {}
    for decision in path.raw[c.Variant.B0].risk_decisions:
        if _is_admission(decision, decided):
            seen.setdefault(decided[decision.subject_id], []).append(priority[decision.subject_id])
    assert all(p == sorted(p) for p in seen.values())


# -------------------------------------------------------------------------------------------- risk and stops

def test_risk_caps_are_never_exceeded(result):
    for raw in _all_raw(result):
        checks = e2e.risk_checks(raw)
        assert checks['respected'], checks['breaches']
        assert checks['admitted_entries'] > 0
        for decision in raw.risk_decisions:
            if decision.verdict is c.RiskVerdict.ADMIT and decision.subject is c.RiskSubject.ENTRY:
                assert decision.risk_amount <= D('0.005') * decision.equity
                assert decision.open_risk_after <= D('0.01') * decision.equity
                assert decision.leverage_after <= 1
        for trade in raw.trades:
            fill = next(d for d in raw.risk_decisions if d.decision_id == trade.fill_decision_id)
            assert trade.nominal_risk == fill.risk_amount <= D('0.005') * fill.equity


def test_stops_are_never_widened_and_only_adaptive_variants_move_them(result):
    for path in result.paths.values():
        for variant, raw in path.raw.items():
            checks = e2e.stop_checks(raw)
            assert checks['never_widened']
            if variant in (c.Variant.B0, c.Variant.B1):
                assert checks['stops_tightened'] == 0
        assert e2e.stop_checks(path.raw[c.Variant.B2])['stops_tightened'] > 0
    for run in _all_runs(result):
        for trade in run.trades:
            assert c.stop_not_wider(trade.side, trade.initial_stop_price, trade.final_stop_price)


def test_actions_applied_lists_only_applied_ids(result):
    raw = result.paths['fixture_model'].raw[c.Variant.B2]
    applied = {a.subject_id for a in raw.audit.records if a.event is c.AuditEvent.ACTION and a.reasons == ('applied',)}
    for trade in raw.trades:
        assert set(trade.actions_applied) <= applied
    refused_late = [r for r in raw.refusals if r.stage == 'action_execution']
    assert all(r.subject_id not in {i for t in raw.trades for i in t.actions_applied} for r in refused_late)


# ---------------------------------------------------------------------------------------------------- audit

def test_audit_chains_are_valid_and_tamper_evident(result):
    for raw in _all_raw(result):
        assert raw.audit.verify() == ()
        assert len(raw.audit) > 0
    for path in result.paths.values():
        assert path.book_journal.verify() == ()
    raw = result.paths['fixture_model'].raw[c.Variant.B3]
    text = raw.audit.dump_jsonl()
    journal, problems = AuditJournal.load_jsonl(text, raw.experiment_id, c.Variant.B3)
    assert problems == () and journal.head_fingerprint() == raw.audit.head_fingerprint()
    lines = text.splitlines()
    del lines[3]
    _, problems = AuditJournal.load_jsonl('\n'.join(lines), raw.experiment_id, c.Variant.B3)
    assert 'h3_audit_chain_broken' in problems


# ------------------------------------------------------------------------------------------ seams / conventions

def test_equity_is_marked_at_every_block_edge(result):
    run = result.paths['fixture_model'].runs[c.Variant.B2]
    stamps = {p.ts_ms for p in run.equity_curve}
    edges = mt.block_edges(run.start_ms, run.end_ms, e2e.E2E_PLAN.block_ms)
    assert set(edges) <= stamps
    assert run.equity_curve[-1].open_positions == 0 and run.equity_curve[-1].open_risk == 0


def test_trade_translation_conventions(result):
    path = result.paths['fixture_model']
    for variant, run in path.runs.items():
        raw_trades = {t.candidate_id: t for t in path.raw[variant].trades}
        for trade in run.trades:
            raw = raw_trades[trade.candidate_id]
            assert trade.funding == -raw.funding                  # C: paid positive; D: cash flow
            assert trade.gap_excess == raw.loss_beyond_nominal
            assert trade.initial_risk == raw.nominal_risk
            assert trade.exit_reason is EXIT_REASONS[raw.exit_reason]
            assert trade.decision_ms < trade.entry_ms               # fills at a bar open after the decision


def test_pessimistic_scenario_scales_spread_and_slippage_in_gate_and_simulator(result):
    base = e2e.gate_factory(CostScenario.BASE, GuardParams())()
    pessimistic = e2e.gate_factory(CostScenario.PESSIMISTIC, GuardParams())()
    m = e2e.COST_MODEL.pessimistic_multiplier
    assert pessimistic.rates.entry.slippage == base.rates.entry.slippage * m
    assert pessimistic.rates.stop.spread == base.rates.stop.spread * m
    assert pessimistic.rates.entry.fee == base.rates.entry.fee     # fees are contractual (D's rule)
    path = result.paths['fixture_model']
    for variant in path.runs:
        assert path.pessimistic_runs[variant].cost_scenario is CostScenario.PESSIMISTIC


def test_adapter_refuses_inconsistent_wiring(result):
    book, market = result.book, result.market
    start, end = e2e.experiment_period()
    entries, refusals = plan_entries(c.Variant.B0, book, (), seed=1, portfolio_id='pf-x')

    def spec(gate, scenario=CostScenario.BASE):
        return VariantSpec(experiment_id='x', variant=c.Variant.B0, portfolio_id='pf-x', quote_ccy='USDT',
                           initial_equity=D('500'), start_ms=start, end_ms=end, limits=c.H3_LIMITS,
                           cost_model=e2e.COST_MODEL, cost_scenario=scenario,
                           candidate_book_fingerprint=book.fingerprint(), market_fingerprint=market.fingerprint(),
                           candidate_ids=book.ids, entries=entries, pre_refusals=refusals,
                           policy=e2e.StaticPolicy(), risk_gate=gate)

    other_costs = dataclasses.replace(e2e.COST_MODEL, slippage_rate=D('0.001'))
    with pytest.raises(c.ContractError, match='h3_adapter_cost_model_not_single_sourced'):
        RunVariantSimulator().run(spec(H3RiskGate(H3RiskConfig(cost_model=other_costs))), market)
    with pytest.raises(c.ContractError, match='h3_adapter_cost_scenario_mismatch'):
        RunVariantSimulator().run(spec(H3RiskGate(H3RiskConfig(cost_model=e2e.COST_MODEL)),
                                       CostScenario.PESSIMISTIC), market)
    with pytest.raises(c.ContractError, match='h3_adapter_gate_not_h3'):
        RunVariantSimulator().run(spec(object()), market)
    simulator = RunVariantSimulator()
    with pytest.raises(c.ContractError):
        simulator.run(spec(object()), market)
    with pytest.raises(c.ContractError, match='h3_adapter_simulator_reused'):
        simulator.run(spec(object()), market)


def test_entry_priority_must_cover_the_candidates():
    inst = s.instrument()
    bar = c.Bar(inst_id=inst.inst_id, interval_ms=s.HOUR_MS, open_ms=s.T0_MS, close_ms=s.T0_MS + s.HOUR_MS,
                available_ms=s.T0_MS + s.HOUR_MS, is_closed=True, open=D('100'), high=D('101'), low=D('99'),
                close=D('100'), volume_quote=D('1000'))
    cand = c.OpportunityCandidate(
        header=s.header(decision_ms=s.T0_MS, inst=inst), candidate_id='cand-1', generator_id='g',
        generator_version='0.1.0', hypothesis_id='synthetic-no-economic-claim',
        snapshot=c.SnapshotRef(snapshot_id='snap', fingerprint='sha256:' + '0' * 64),
        entry_reference_price=D('100'), initial_stop_price=D('99'), initial_target_price=None,
        max_holding_ms=s.HOUR_MS)
    gate = H3RiskGate(H3RiskConfig(cost_model=e2e.COST_MODEL))
    with pytest.raises(c.ContractError, match='h3_sim_entry_priority_invalid'):
        run_variant(experiment_id='x', variant=c.Variant.B0, portfolio_id='pf', bars=[bar], candidates=[cand],
                    policy=e2e.StaticPolicy(), gate=gate, entry_priority={'other': 0})


# ------------------------------------------------------------------------------------- guard wired into the gate

def _position(gate: H3RiskGate, policy, *, kill: bool) -> c.PositionState:
    inst = s.instrument()
    day = 24 * s.HOUR_MS
    risk = gate.risk_of(c.Side.LONG, D('100'), D('99'), D('200'), inst.contract_value, day)
    return c.PositionState(
        header=s.header(side=c.Side.LONG, portfolio_id='pf-B2', decision_ms=s.T0_MS + 2 * s.HOUR_MS, inst=inst),
        position_id='pos-1', candidate_id='cand-1', status=c.PositionStatus.OPEN, entry_price=D('100'),
        entry_ms=s.T0_MS + s.HOUR_MS, initial_quantity=D('200'), open_quantity=D('200'), initial_stop=D('99'),
        current_stop=D('99'), current_target=None, last_price=D('100.5'), mfe_price=D('100.5'), mae_price=D('99.8'),
        holding_ms=s.HOUR_MS, max_holding_ms=day, volatility=None, liquidity=c.LiquidityStatus.UNKNOWN,
        funding_paid=D('0'), realized_pnl=D('0'), initial_risk_amount=risk, equity=D('500'), open_risk_total=risk,
        risk_budget_remaining=D('5') - risk, actions_applied=(), policy=policy.policy,
        data_status=c.DataStatus.COMPLETE, kill_switch=kill)


def _view(state: c.PositionState) -> c.PortfolioView:
    return c.PortfolioView(portfolio_id='pf-B2', variant=c.Variant.B2, quote_ccy='USDC', clock=state.header.clock,
                           equity=D('500'), cash=D('500'), open_risk=state.open_risk_total,
                           open_notional=D('200') * D('0.01') * D('100'), open_position_ids=('pos-1',),
                           kill_switch=state.kill_switch)


def test_position_guard_is_defence_in_depth_inside_the_gate():
    policy = AdaptivePolicyV1()
    config = H3RiskConfig(cost_model=e2e.COST_MODEL, assumptions=ExecutionAssumptions())
    plain = H3RiskGate(config)
    guarded = H3RiskGate(config, action_guard=functools.partial(check_action, params=policy.guard_params),
                         action_guard_identity=e2e.position_guard_identity(policy.guard_params))
    assert plain.policy == config.policy_ref()
    assert guarded.policy != plain.policy                     # the guard is part of the risk policy identity
    with pytest.raises(c.ContractError, match='h3_risk_action_guard_identity_required'):
        H3RiskGate(config, action_guard=check_action)
    for kill in (False, True):
        state = _position(plain, policy, kill=kill)
        action = c.AdaptiveAction.create(header=state.header, position_id=state.position_id,
                                         kind=c.ActionKind.TIGHTEN_STOP, state=state.ref(), policy=state.policy,
                                         reasons=('test',), new_stop='99.5')
        a, b = plain.validate_action(action, state, _view(state)), guarded.validate_action(action, state, _view(state))
        if not kill:
            assert a.verdict is b.verdict is c.RiskVerdict.ADMIT
        else:  # the gate alone admits a tightening under the kill switch; B's guard does not (stricter wins)
            assert a.verdict is c.RiskVerdict.ADMIT
            assert b.verdict is c.RiskVerdict.REJECT and 'guard.kill_switch_active' in b.reasons
    state = _position(plain, policy, kill=False)
    stale = dataclasses.replace(state, data_status=c.DataStatus.STALE)
    action = c.AdaptiveAction.create(header=stale.header, position_id=stale.position_id,
                                     kind=c.ActionKind.TIGHTEN_STOP, state=stale.ref(), policy=stale.policy,
                                     reasons=('test',), new_stop='99.5')
    assert 'guard.data_stale' in guarded.validate_action(action, stale, _view(stale)).reasons
    assert plain.validate_action(action, stale, _view(stale)).verdict is c.RiskVerdict.ADMIT


# ---------------------------------------------------------------------------------------------- determinism

def test_two_runs_are_identical(result):
    again = e2e.run_e2e()
    assert e2e.report_fingerprint(again.report) == e2e.report_fingerprint(result.report)
    for name, path in result.paths.items():
        other = again.paths[name]
        for variant, run in path.runs.items():
            assert other.runs[variant].fingerprint() == run.fingerprint()
            assert other.raw[variant].audit.head_fingerprint() == path.raw[variant].audit.head_fingerprint()
        assert other.book_journal.head_fingerprint() == path.book_journal.head_fingerprint()
