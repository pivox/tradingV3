"""H3-A matched random-abstention control (BR), review R1-F1. Technical properties only, synthetic data only.

Nothing here asserts or reports a profitability: every PnL is synthetic and is never evidence.
Run: python -m pytest --noconftest tests/test_research_h3_abstention.py
"""
from __future__ import annotations

import dataclasses
import random

import pytest

from scripts.research_h3.contracts import ScoreVerdict, Variant
from scripts.research_h3.validation import abstention as ab
from scripts.research_h3.validation import abstention_sim as sim
from scripts.research_h3.validation.factorial import FactorialError
from scripts.research_h3.validation.records import RefusalStage


# ------------------------------------------------------------------------------------------- shared interface

def test_shared_interface_field_names_match_common_brief():
    assert [f.name for f in dataclasses.fields(ab.RandomControlSpec)] == [
        'control_id', 'method', 'strata_keys', 'seeds', 'budget_source']
    assert [f.name for f in dataclasses.fields(ab.ActivityBudget)] == [
        'budget_id', 'strata_keys', 'per_stratum', 'derived_from_variant', 'fingerprint']
    assert ab.METHODS == ('global_budget', 'stratified')
    assert ab.BUDGET_SOURCES == ('b1_acceptance',)


@pytest.mark.parametrize('kwargs, code', [
    (dict(control_id='x', method='bernoulli', strata_keys=(), seeds=(1,)), 'abstention_method_unknown'),
    (dict(control_id='x', method='global_budget', strata_keys=('side',), seeds=(1,)),
     'abstention_global_budget_takes_no_strata'),
    (dict(control_id='x', method='stratified', strata_keys=(), seeds=(1,)), 'abstention_stratified_needs_strata'),
    (dict(control_id='x', method='stratified', strata_keys=('realised_r',), seeds=(1,)),
     'abstention_stratum_key_not_known_at_decision'),
    (dict(control_id='x', method='stratified', strata_keys=('score_value',), seeds=(1,)),
     'abstention_stratum_key_not_known_at_decision'),
    (dict(control_id='x', method='global_budget', strata_keys=(), seeds=()), 'abstention_seeds_invalid'),
    (dict(control_id='x', method='global_budget', strata_keys=(), seeds=(1, 1)), 'abstention_seeds_invalid'),
    (dict(control_id='x', method='global_budget', strata_keys=(), seeds=(1,), budget_source='b1_outcomes'),
     'abstention_budget_source_invalid'),
    (dict(control_id='bad id', method='global_budget', strata_keys=(), seeds=(1,)), 'abstention_control_id_invalid'),
])
def test_random_control_spec_refuses_invalid_definitions(kwargs, code):
    with pytest.raises(ab.AbstentionError, match=code):
        ab.RandomControlSpec(**kwargs)


def test_spec_fingerprint_is_stable_and_covers_seeds():
    a = ab.RandomControlSpec('day', 'stratified', ('decision_day',), (1, 2, 3))
    b = ab.RandomControlSpec('day', 'stratified', ('decision_day',), (1, 2, 3))
    c = ab.RandomControlSpec('day', 'stratified', ('decision_day',), (1, 2, 4))
    assert a.fingerprint() == b.fingerprint() != c.fingerprint()


# ------------------------------------------------------------------------------------------------ budget

def _items(n_days=3, per_day=10):
    return [ab.DecisionItem(f'c{d}-{i}', {'decision_day': str(d), 'decision_ms': str(d * 100 + i),
                                          'inst_id': f'I{i % 3}', 'side': 'long' if i % 2 else 'short',
                                          'score_status': 'estimated'})
            for d in range(n_days) for i in range(per_day)]


def test_budget_counts_b1_decisions_per_stratum_and_is_fingerprinted():
    items = _items()
    accepted = {'c0-0', 'c0-1', 'c2-5'}
    budget = ab.activity_budget(items, accepted, ('decision_day',), 'b')
    assert budget.per_stratum == {'decision_day=0': (10, 2), 'decision_day=1': (10, 0), 'decision_day=2': (10, 1)}
    assert (budget.candidate_count, budget.accepted_count, budget.derived_from_variant) == (30, 3, 'B1')
    assert budget.fingerprint.startswith('sha256:')
    with pytest.raises(ab.AbstentionError, match='abstention_budget_fingerprint_mismatch'):
        ab.ActivityBudget('b', ('decision_day',), {'decision_day=0': (10, 3)}, fingerprint=budget.fingerprint)
    with pytest.raises(ab.AbstentionError, match='abstention_budget_not_from_b1'):
        ab.ActivityBudget('b', (), {'*': (10, 3)}, derived_from_variant='B0')
    with pytest.raises(ab.AbstentionError, match='abstention_accepted_outside_candidates'):
        ab.activity_budget(items, {'zz'}, (), 'b')
    glob = ab.activity_budget(items, accepted, (), 'g')
    assert glob.per_stratum == {'*': (30, 3)}


def test_selection_matches_the_budget_exactly_and_depends_only_on_the_seed():
    items = _items()
    accepted = {'c0-0', 'c0-1', 'c1-3', 'c1-4', 'c1-9', 'c2-5'}
    budget = ab.activity_budget(items, accepted, ('decision_day',), 'b')
    draws = []
    for seed in (1, 2, 3):
        chosen = ab.select_random(items, budget, lambda cid, s=seed: ab.lottery_key('day', s, cid))
        per_day = {d: sum(1 for cid in chosen if cid.startswith(f'c{d}-')) for d in range(3)}
        assert per_day == {0: 2, 1: 3, 2: 1}
        assert chosen == ab.select_random(items, budget, lambda cid, s=seed: ab.lottery_key('day', s, cid))
        draws.append(chosen)
    assert len(set(draws)) > 1
    # the key sees the candidate id only: no score, no feature, no outcome can enter the draw
    other = [dataclasses.replace(i, values=dict(i.values, side='long')) for i in items]
    assert ab.select_random(other, budget, lambda cid: ab.lottery_key('day', 1, cid)) == draws[0]


def test_selection_refuses_a_budget_from_other_candidates():
    items = _items()
    budget = ab.activity_budget(items[:-1], set(), ('decision_day',), 'b')
    with pytest.raises(ab.AbstentionError, match='abstention_stratum_count_mismatch'):
        ab.select_random(items, budget, str)
    budget = ab.activity_budget(_items(n_days=4), set(), ('decision_day',), 'b')
    with pytest.raises(ab.AbstentionError, match='abstention_strata_do_not_match_budget'):
        ab.select_random(items, budget, str)


# ------------------------------------------------------------------------------------------- comparisons

def test_randomisation_p_and_block_excess():
    assert ab.randomisation_p(5.0, [1.0, 2.0, 3.0]) == 0.25
    assert ab.randomisation_p(2.0, [1.0, 2.0, 3.0]) == 0.75
    assert ab.block_excess_series([1.0, 2.0], [[0.0, 1.0], [2.0, 3.0]]) == [0.0, 0.0]
    with pytest.raises(ab.AbstentionError, match='abstention_block_series_mismatch'):
        ab.block_excess_series([1.0], [[0.0, 1.0]])
    with pytest.raises(ab.AbstentionError, match='abstention_no_control_draws'):
        ab.randomisation_p(1.0, [])


def test_h3a_status_never_reads_abstention_as_skill():
    assert ab.h3a_status(rank_ic_rejects=False, br_excess_rejects=False, b1_beats_b0=True,
                         ic_halves_positive=False, powered=True) == 'h3a_abstention_only_no_skill_shown'
    assert ab.h3a_status(rank_ic_rejects=True, br_excess_rejects=True, b1_beats_b0=False,
                         ic_halves_positive=False) != 'h3a_ranking_and_selection_beyond_abstention_draft'
    assert ab.h3a_status(rank_ic_rejects=True, br_excess_rejects=True, b1_beats_b0=False,
                         ic_halves_positive=True) == 'h3a_ranking_and_selection_beyond_abstention_draft'
    assert ab.h3a_status(rank_ic_rejects=True, br_excess_rejects=False, b1_beats_b0=True,
                         ic_halves_positive=True, powered=True) == 'h3a_ranking_without_selection_value'
    assert ab.h3a_status(rank_ic_rejects=None, br_excess_rejects=True, b1_beats_b0=True,
                         ic_halves_positive=True) == 'h3a_insufficient_information'
    for status in ab.H3A_STATUSES:
        assert 'validated' not in status and 'profitable' not in status


# ------------------------------------------------------------------------------------- light simulation

def test_light_simulation_reproduces_the_r1_f1_artefact_on_a_losing_generator():
    """Losing generator, uninformative score: B1 loses less than B0 because it trades less, and its matched random
    abstention (same budget per day) loses about as little. Deterministic seed; synthetic."""
    pop = sim.population('p4_losing_generator')
    rng = random.Random(7)
    b0 = b1 = br = 0.0
    for _ in range(12):
        cands = sim.simulate_panel(pop, n_blocks=26, rng=rng)
        r = sim.evaluate_panel(cands, n_blocks=26, k_controls=9, alpha=0.05, rng=rng, with_inst=False)
        assert r['b1_trades'] < r['b0_trades']
        b0, b1, br = b0 + r['b0_net'], b1 + r['b1_net'], br + r['br_mean_net']['day']
        assert r['decisions']['perm_inst'] is None
    assert b1 > b0 and br > b0
    assert abs(b1 - br) < 0.25 * abs(b0 - br)


def test_light_simulation_controls_keep_their_own_trajectory():
    cands = sim.simulate_panel(sim.population('p1_no_signal'), n_blocks=8, rng=random.Random(3))
    items = sim._items(cands)
    accepted = {c.cid for c in cands if c.score > sim.ACCEPT_Z}
    budget = ab.activity_budget(items, accepted, ('decision_day',), 'b')
    rng = random.Random(5)
    chosen = ab.select_random(items, budget, {c.cid: rng.random() for c in cands}.__getitem__)
    assert len(chosen) == len(accepted)
    b1 = sim.replay(cands, accepted, {c.cid: -c.score for c in cands}, 8)
    br = sim.replay(cands, chosen, {c.cid: rng.random() for c in cands}, 8)
    assert b1['accepted'] == br['accepted']
    assert b1['trades'] <= len(accepted) and br['trades'] <= len(chosen)


def test_wilson_interval_and_cell_seed():
    lo, hi = sim.wilson(20, 400)
    assert lo < 0.05 < hi
    cell = sim.StudyCell('p1_no_signal', 52, 10, 19, 0.05, 1)
    assert cell.seed() == sim.StudyCell('p1_no_signal', 52, 99, 19, 0.05, 1).seed()   # panels do not change it
    assert cell.seed() != sim.StudyCell('p2_weak_signal', 52, 10, 19, 0.05, 1).seed()


def test_populations_are_named_and_cover_the_brief():
    names = [p.name for p in sim.POPULATIONS]
    for prefix in ('p1_', 'p2_', 'p3_', 'p4_', 'p5_', 'p6_', 'p7_', 'p8_'):
        assert any(n.startswith(prefix) for n in names)
    assert len(set(names)) == len(names)


# ------------------------------------------------------------------------------------ end to end (real path)

@pytest.fixture(scope='module')
def e2e_controls():
    return sim.run_e2e_random_control(seeds=(101, 102))


def test_br_runs_end_to_end_with_its_own_gate_and_trajectory(e2e_controls):
    factorial = e2e_controls['factorial']
    b1 = factorial.runs[Variant.B1]
    b1_accepted = ab.b1_accepted_ids(factorial.scores)
    assert len(b1_accepted) == len(b1.candidate_ids) - sum(
        1 for r in b1.refusals if r.stage is RefusalStage.SCORE)
    for control_id, result in e2e_controls['results'].items():
        assert result.candidate_book_fingerprint == factorial.candidate_book_fingerprint
        assert result.market_fingerprint == factorial.market_fingerprint
        assert result.budget.accepted_count == len(b1_accepted)
        ids = set()
        for control in result.runs:
            run = control.run
            assert control.label == ab.BR_LABEL
            assert run.portfolio_id == ab.control_portfolio_id('h3a-br-e2e', control_id, control.seed)
            assert run.portfolio_id not in ids
            ids.add(run.portfolio_id)
            assert run.candidate_ids == b1.candidate_ids
            assert run.initial_equity == b1.initial_equity and (run.start_ms, run.end_ms) == (b1.start_ms, b1.end_ms)
            assert len(control.accepted_ids) == len(b1_accepted)
            assert {t.candidate_id for t in run.trades} <= control.accepted_ids
            score_refused = {r.candidate_id for r in run.refusals if r.stage is RefusalStage.SCORE}
            assert score_refused == set(run.candidate_ids) - control.accepted_ids
            assert all(r.reasons == (ab.CONTROL_REFUSAL_REASON,) for r in run.refusals
                       if r.stage is RefusalStage.SCORE)
        if result.spec.method == 'stratified':
            day_of = {c.candidate_id: ab.decision_item(c, None, start_ms=b1.start_ms).values['decision_day']
                      for c in _book(e2e_controls).candidates}
            for control in result.runs:
                for label, (n, k) in result.budget.per_stratum.items():
                    day = label.split('=')[1]
                    members = [s for s in factorial.scores if day_of[s.candidate_id] == day]
                    assert len(members) == n
                    assert sum(1 for s in members if s.candidate_id in control.accepted_ids) == k
                    assert sum(1 for s in members if s.verdict is ScoreVerdict.TRADE_CANDIDATE) == k


def _book(e2e_controls):
    return e2e_controls['book']


def test_e2e_comparison_reports_activity_and_never_claims_profit(e2e_controls):
    for report in e2e_controls['controls'].values():
        for key in ('accepted', 'executable_orders', 'trades_executed', 'exposure_days', 'mean_open_risk',
                    'net_pnl', 'net_per_risk_day'):
            assert key in report['b1'] and key in report['br_distribution'] and key in report['b0']
        assert report['br_distribution']['accepted']['min'] == report['b1']['accepted']
        assert 0 < report['p_net_pnl_vs_br'] <= 1
        assert 'not a validation' in report['reading']
    assert e2e_controls['label'] == 'SYNTHETIC_NOT_EVIDENCE'


def test_integration_e2e_controls_serialise_as_v2_and_feed_compare_evaluate(e2e_controls):
    from scripts.research_h3 import contracts_v2 as v2
    from scripts.research_h3 import e2e
    from scripts.research_h3.validation import compare as cp
    accepted_day_control = False
    for control_id, result in e2e_controls['results'].items():
        spec, budget = ab.spec_to_v2(result.spec), ab.budget_to_v2(result.budget)
        assert v2.RandomControlSpec.from_json(spec.canonical_json()) == spec
        assert ab.budget_from_v2(v2.ActivityBudget.from_json(budget.canonical_json())) == result.budget
        assert budget.strata_keys == spec.strata_keys
        block = e2e_controls['controls'][control_id]
        if result.spec.method != 'stratified' or 'decision_day' not in result.spec.strata_keys:
            # independent review QR-10: only the DRAFT primary control (BR stratified by day) is accepted
            with pytest.raises(cp.CompareError, match='compare_random_control_not_the_draft_primary_control'):
                cp.evaluate(e2e_controls['factorial'].runs, e2e.E2E_PLAN, random_control=block)
            continue
        report = cp.evaluate(e2e_controls['factorial'].runs, e2e.E2E_PLAN, random_control=block)
        assert report['h3a_random_control']['control']['control_id'] == control_id
        assert report['attribution_blockers']['H3-A_scoring'] == list(cp.H3A_BLOCKERS_WITH_RANDOM_CONTROL)
        assert 'supported' not in report['hypotheses'].values()
        accepted_day_control = True
    assert accepted_day_control


def test_run_random_control_refuses_a_shared_or_wrong_gate(e2e_controls):
    from scripts.research_h3 import e2e
    from scripts.research_h3.adaptive import AdaptivePolicyV1, StaticPolicy
    from scripts.research_h3.integration.validation_adapter import RunVariantSimulator
    from scripts.research_h3.validation.records import CostScenario
    factorial, book, market = e2e_controls['factorial'], e2e_controls['book'], e2e_controls['market']
    manifest, make_gate = e2e_controls['manifest'], e2e_controls['make_gate']
    start_ms, end_ms = e2e_controls['period']
    shared = make_gate()
    spec = ab.RandomControlSpec('glob', 'global_budget', (), (1, 2))
    with pytest.raises(FactorialError, match='factorial_component_shared_between_variants'):
        ab.run_random_control(manifest=manifest, book=book, scores=factorial.scores, market=market, spec=spec,
                              static_policy=StaticPolicy(), risk_gate_factory=lambda: shared,
                              simulator_factory=RunVariantSimulator, start_ms=start_ms, end_ms=end_ms)
    pessimistic = e2e.gate_factory(CostScenario.PESSIMISTIC, AdaptivePolicyV1().guard_params)
    if pessimistic().policy != manifest.risk_policy:
        with pytest.raises(FactorialError, match='factorial_gate_risk_policy_mismatch'):
            ab.run_random_control(manifest=manifest, book=book, scores=factorial.scores, market=market, spec=spec,
                                  static_policy=StaticPolicy(), risk_gate_factory=pessimistic,
                                  simulator_factory=RunVariantSimulator, start_ms=start_ms, end_ms=end_ms)
    with pytest.raises(ab.AbstentionError, match='abstention_scores_do_not_cover_the_book'):
        ab.run_random_control(manifest=manifest, book=book, scores=factorial.scores[1:], market=market, spec=spec,
                              static_policy=StaticPolicy(), risk_gate_factory=make_gate,
                              simulator_factory=RunVariantSimulator, start_ms=start_ms, end_ms=end_ms)
