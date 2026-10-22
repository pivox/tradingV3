"""H3-A testability, agent C: label populations, reference labels, label-replay audit additions, the explicit
uncertainty report and the V2 catalogue structure. Synthetic data only; no market data, no exchange, no order.
Synthetic results are never evidence.

Run: python -m pytest --noconftest tests/test_research_h3_labels.py
"""
from __future__ import annotations

import dataclasses
import json
import math
import random
from decimal import Decimal as D

import pytest

from scripts.research_h3 import contracts as c
from scripts.research_h3 import e2e
from scripts.research_h3 import synthetic as s
from scripts.research_h3.adaptive import StaticPolicy
from scripts.research_h3.integration import costs as k
from scripts.research_h3.integration.label_replay import capacity_free_labels, label_mapping
from scripts.research_h3.scoring import catalogue_v2 as cv2
from scripts.research_h3.scoring import fixtures as fx
from scripts.research_h3.scoring import labels as L
from scripts.research_h3.scoring import uncertainty as U
from scripts.research_h3.scoring.engine import ScoringEngine
from scripts.research_h3.scoring.features import CATALOGUE, build_snapshot
from scripts.research_h3.scoring.model import ScoringPolicy, decode_manifest, reference_null_manifest
from scripts.research_h3.scoring.parity import GOLDEN_FILE, verify_golden
from scripts.research_h3.validation.records import CostScenario
from tests import test_research_h3_integration as T

H, T0, FLAT, LONG, SHORT = T.H, T.T0, T.FLAT, T.LONG, T.SHORT
LAT = 1_000
DECISION = T0 + H + LAT          # bar 0 [T0, T0+H) is usable; the reference instant t0 is T0 + H


def _bars(rows):
    return T.bars(rows, latency=LAT)


def _cand(**kw):
    kw.setdefault('decision_ms', DECISION)
    kw.setdefault('hold', 3 * H)
    return T.cand(**kw)


# ------------------------------------------------------------------------------ C1: census of the e2e example

@pytest.fixture(scope='module')
def example():
    result = e2e.run_e2e()
    cands, bars = result.book.candidates, result.market.bars
    make_gate = e2e.gate_factory(CostScenario.BASE, e2e.AdaptivePolicyV1().guard_params)
    end = e2e.T0_MS + e2e.N_BARS * e2e.HOUR_MS
    eligibility = {x.candidate_id: L.technical_eligibility(x, bars, make_gate, quote_ccy=e2e.QUOTE_CCY,
                                                           data_end_ms=end) for x in cands}
    reference = L.reference_labels(cands, bars)
    census = L.population_census(cands, eligibility=eligibility, reference=reference, executability=result.labels,
                                 pre_candidate_refusals=[r.reasons for r in result.data_refusals])
    return result, census, reference


def test_c1_census_reproduces_the_synthetic_273_149_52_and_explains_the_124(example):
    result, census, _ = example
    counts = census.counts
    assert counts['ALL_CANDIDATES'] == 273 and counts['pre_candidate_refusals'] == 9
    assert counts['replay_traded_including_truncated'] == 149
    assert len(result.paths['fixture_model'].runs[c.Variant.B0].trades) == 52
    refused = [x for x in result.labels.values() if not x.traded]
    assert len(refused) == 124 and all(x.refusal_reasons == ('fill_beyond_collar',) for x in refused)
    # target population: every eligibility exclusion is ex ante or calendar, and the reference label covers it all
    assert counts['TECHNICALLY_ELIGIBLE'] == 261 and counts['LABELABLE'] == 261
    report = census.to_report()
    assert report['target_population'] == 'TECHNICALLY_ELIGIBLE'
    assert report['target_label_selection_outcome_dependent'] is False
    assert report['target_label_data_coverage_exclusions_untested'] == 0
    assert report['target_label_outcome_independence_established'] is True
    by_step = {(row['step'], row['category']): row for row in report['exclusions']}
    assert by_step[('generation', 'data_missing')]['count'] == 9
    assert by_step[('eligibility', 'holding_not_covered')]['timing'] == 'calendar'
    collar = by_step[('execution', 'market_constraint')]
    assert collar['outcome_dependent'] is True and collar['reason_codes'] == {'fill_beyond_collar': 119}


def test_census_never_calls_a_data_coverage_exclusion_outcome_independent():
    """Independent review QR-8: gaps can be price-correlated; their count is reported and blocks the claim."""
    row = dict(decision_ms=0, inst_id='SYN-USDT', side='long', eligible=True, executable=False,
               eligibility_reasons=(), execution_reasons=('fill_beyond_collar',))
    census = L.PopulationCensus(rows=(L.CandidateCensus('a', labelable=True, label_reasons=(), **row),
                                      L.CandidateCensus('b', labelable=False, label_reasons=('price_path_gap',), **row)))
    report = census.to_report()
    assert report['target_label_selection_outcome_dependent'] is False      # by category
    assert report['target_label_data_coverage_exclusions_untested'] == 1
    assert report['target_label_outcome_independence_established'] is False


def test_c1_collar_refusal_is_outcome_dependent_selection_by_construction(example):
    result, _, reference = example
    audit = L.collar_selection_audit(result.book.candidates, result.market.bars, reference=reference,
                                     executability=result.labels, collar_rate=k.ExecutionAssumptions().entry_collar_rate)
    assert audit['executable_n'] == 149 and audit['collar_excluded_n'] == 124
    assert audit['selection_by_construction'] is True        # every refused candidate moved > 10 bp in its favour
    assert audit['fill_move_min']['X'] > 0.001 >= audit['fill_move_max']['E']
    assert audit['mean_difference_x_minus_e'] > 0
    assert audit['verdict'] == 'OUTCOME_DEPENDENT_SELECTION_PRESENT'


def test_c1_horizon_censoring_must_be_calendar_not_path(example):
    _, census, reference = example
    # censoring at the label step only drops candidates whose stop/target did NOT fire early (path-dependent);
    # the calendar rule at eligibility drops all candidates of the last instants, whatever their path
    late = [r for r in census.rows if 'horizon_not_covered' in r.eligibility_reasons]
    assert len(late) == 12
    labelled_late = [r for r in late if reference[r.candidate_id].status is L.LabelStatus.LABELLED]
    assert 0 < len(labelled_late) < len(late)
    assert all(reference[r.candidate_id].exit_reason in ('stop', 'stop_gap', 'target') for r in labelled_late)


def test_c1_every_reason_code_is_classified_and_unknown_codes_count_as_outcome_dependent():
    for code in ('fill_beyond_collar', 'quantity_below_min_size', 'stop_not_protective', 'no_market_data',
                 'horizon_not_covered', 'price_path_gap', 'total_risk_budget_exhausted', 'entry_order_rejected',
                 'snapshot_data_partial', 'feature_stale.spread_bp', 'end_of_data_exit'):
        assert L.category_of(code) is not L.Category.UNCLASSIFIED, code
    assert L.timing_of(L.category_of('quantity_below_min_size')) is L.Timing.EX_ANTE
    assert L.outcome_dependent(L.timing_of(L.category_of('fill_beyond_stop')))
    assert L.outcome_dependent(L.timing_of(L.category_of('instrument_already_open')))
    assert L.outcome_dependent(L.timing_of(L.category_of('some_new_code')))


def test_c1_min_size_and_missing_data_are_ex_ante_eligibility_exclusions():
    big = s.instrument(min_size='100000')
    bars = _bars([FLAT] * 5)
    assert L.technical_eligibility(_cand(inst=big), [dataclasses.replace(b, inst_id=big.inst_id) for b in bars],
                                   T.gate, quote_ccy='USDC') == ('quantity_below_min_size',)
    assert L.technical_eligibility(_cand(), [], T.gate, quote_ccy='USDC') == ('no_market_data',)
    assert L.technical_eligibility(_cand(decision_ms=T0 + 1), bars, T.gate, quote_ccy='USDC') == (
        'no_market_data_at_decision',)
    assert L.technical_eligibility(_cand(), bars, T.gate, quote_ccy='USDC') == ()
    assert L.technical_eligibility(_cand(), bars, T.gate, quote_ccy='USDC', data_end_ms=T0 + 4 * H) == (
        'horizon_not_covered',)


# ------------------------------------------------------------------------- C2: the reference label

@pytest.mark.parametrize('rows, kw, expected', [
    ([FLAT, ('100', '100.5', '98.9', '99.5'), FLAT, FLAT, FLAT], {}, (D('-1'), 'stop', True, False)),
    ([FLAT, ('98', '98.5', '97.5', '98'), FLAT, FLAT, FLAT], {}, (D('-2'), 'stop_gap', True, True)),
    ([FLAT, ('100', '102', '99.5', '101'), ('101', '102.5', '100.5', '102'), FLAT, FLAT], {'target': '102'},
     (D('2'), 'target', False, False)),                                  # 102 touched, not traded through
    ([FLAT, FLAT, FLAT, FLAT, FLAT], {}, (D('0'), 'max_holding', False, False)),
    ([FLAT, ('100', '101.2', '99.5', '100'), FLAT, FLAT, FLAT], {'side': SHORT, 'stop': '101'},
     (D('-1'), 'stop', True, False)),
])
def test_c2_reference_label_static_exit_rules(rows, kw, expected):
    label = L.reference_label(_cand(**kw), _bars(rows))
    assert label.status is L.LabelStatus.LABELLED and label.reference_ms == T0 + H
    assert (label.gross_r, label.exit_reason, label.stopped, label.gap_through_stop) == expected


def test_c2_ambiguous_bar_assumes_the_stop_and_is_flagged():
    label = L.reference_label(_cand(target='102'), _bars([FLAT, ('100', '102.5', '98.9', '100'), FLAT, FLAT, FLAT]))
    assert label.exit_reason == 'stop' and label.gross_r == -1 and label.ambiguous_bar is True


def test_c2_gaps_and_short_paths_leave_the_candidate_unlabelled_never_truncated():
    rows = _bars([FLAT] * 5)
    gap = L.reference_label(_cand(), rows[:2] + rows[3:])
    assert gap.status is L.LabelStatus.EXCLUDED and gap.reasons == ('price_path_gap',) and gap.gross_r is None
    short = L.reference_label(_cand(), rows[:3])
    assert short.reasons == ('horizon_not_covered',) and short.gross_r is None
    assert L.reference_label(_cand(), []).reasons == ('no_market_data_at_decision',)


def test_c2_reference_label_ignores_executability_costs_and_other_candidates():
    rows = _bars([FLAT, FLAT, ('100.5', '101', '99.5', '100.6'), FLAT, FLAT])  # fill-bar open +0.5 %: beyond collar
    candidate = _cand()
    replay = capacity_free_labels([candidate], rows, policy=StaticPolicy(), gate_factory=T.gate)
    assert replay[candidate.candidate_id].refusal_reasons == ('fill_beyond_collar',)
    label = L.reference_label(candidate, rows)
    assert label.status is L.LabelStatus.LABELLED                      # labelled although not executable
    other = _cand(cid='cand-2', inst=s.instrument('OTH'))
    both = L.reference_labels([candidate, other], rows)
    assert both[candidate.candidate_id] == label                        # no dependence on other candidates


def test_c2_no_feature_at_t_depends_on_the_label_path_and_the_label_on_nothing_before_t0():
    universe = fx.synthetic_universe()
    target = fx.inst_id('AAA')
    future = []
    for kk in range(4):     # bars opening at or after the decision's reference instant: the label path
        open_ms = T0 + (fx.N_BARS + kk) * H
        future.append(c.Bar(inst_id=target, interval_ms=H, open_ms=open_ms, close_ms=open_ms + H,
                            available_ms=open_ms + H + fx.LATENCY_MS, is_closed=True, open=D('90'), high=D('200'),
                            low=D('1'), close=D('150'), volume_quote=D('1')))
    snap = fx.synthetic_snapshot('AAA')
    with_future = fx.synthetic_snapshot('AAA', universe={**universe, target: universe[target] + tuple(future)})
    assert with_future.features == snap.features
    candidate = fx.synthetic_candidate(snap, max_holding_ms=2 * H)
    path = list(universe[target]) + future
    label = L.reference_label(candidate, path)
    assert label.status is L.LabelStatus.LABELLED and label.reference_ms == T0 + fx.N_BARS * H
    past_changed = [dataclasses.replace(b, high=b.high * 2, close=b.close) if b.close_ms < label.reference_ms else b
                    for b in path]
    assert L.reference_label(candidate, past_changed) == label


def test_c2_no_double_cost_deduction():
    for unit in ('net_r', 'net_gross_r'):
        with pytest.raises(c.ContractError, match='h3_label_unit_double_cost_deduction'):
            L.training_target('gross_r', unit)
    assert L.training_target('gross_r', 'reference_gross_r') == 'reference_gross_r'
    trace = ScoringEngine(fx.COST_MODEL).evaluate(*_scored(), fx.synthetic_fixture_manifest()).values
    assert D(trace['expected_net_r']) == D(trace['gross_r']) - D(trace['cost_r']) - D(trace['funding_cost_r'])
    label = L.ReferenceLabel('x', 0, L.LabelStatus.LABELLED, D('1'), 'target', 0, False, False, False, 0)
    assert L.net_reference_r(label, D('0.2'), D('0.05')) == D('0.75')


def test_c2_label_replay_audit_fields_truncation_gaps_and_units():
    candidate = _cand(hold=T.DAY)
    rows = _bars([FLAT, FLAT, ('100', '100.5', '99.5', '100.3'), FLAT])
    (label,) = capacity_free_labels([candidate], rows, policy=StaticPolicy(), gate_factory=T.gate).values()
    assert label.traded and label.exit_reason == 'end_of_data' and label.horizon_covered is False
    assert label_mapping({'x': label}, full_horizon_only=True) == {'x': (None, None)}
    assert label_mapping({'x': label})['x'][0] is not None               # default behaviour unchanged
    assert abs(label.net_gross_r) > abs(label.net_r)                    # cost-inclusive R is the larger unit
    assert label_mapping({'x': label}, 'net_gross_r')['x'][0] == float(label.net_gross_r)
    gappy = rows[:2] + rows[3:] + _bars([FLAT] * 6)[4:]
    (label2,) = capacity_free_labels([candidate], gappy, policy=StaticPolicy(), gate_factory=T.gate).values()
    assert label2.price_path_gaps == 1


# ----------------------------------------------------------------------------------- C4/C5: uncertainty

def _scored(base='AAA'):
    snap = fx.synthetic_snapshot(base)
    return fx.synthetic_candidate(snap), snap


def _report(manifest, **kw):
    candidate, snap = _scored()
    trace = ScoringEngine(fx.COST_MODEL).evaluate(candidate, snap, manifest)
    model, _ = decode_manifest(manifest)
    return trace, U.decompose(trace.score, trace.values, model, **kw)


def test_c5_no_model_no_calibration_or_invalid_model_gives_no_number():
    candidate, snap = _scored()
    engine = ScoringEngine(fx.COST_MODEL)
    null = reference_null_manifest(protocol=s.DRAFT_PROTOCOL, created_ms=s.T0_MS)
    cases = {
        'absent': (None, c.ScoreStatus.UNESTIMATED),
        'null': (null, c.ScoreStatus.UNESTIMATED),
        'uncalibrated': (fx.synthetic_fixture_manifest(calibration=c.CalibrationStatus.UNCALIBRATED),
                         c.ScoreStatus.UNESTIMATED),
        'calibration_table_absent': (fx.synthetic_fixture_manifest(overrides={
            f'head.stop.calib.0{i}.{f}': None for i in range(3) for f in 'xp'}), c.ScoreStatus.MODEL_INVALID),
        'residual_sd_zero': (fx.synthetic_fixture_manifest(overrides={'unc.residual_sd_r': '0'}),
                             c.ScoreStatus.MODEL_INVALID),
    }
    for name, (manifest, status) in cases.items():
        trace = engine.evaluate(candidate, snap, manifest)
        assert trace.score.status is status, name
        assert trace.score.expected_net_r is None and trace.score.p_stop is None and not trace.values, name
        model = decode_manifest(manifest)[0] if manifest is not None else None
        report = U.decompose(trace.score, trace.values, model)
        assert report.overall == 'UNESTIMATED' and report.v1_heuristic is None, name
        assert U.abstention_reasons(report) == ('uncertainty_unestimated',)


def test_c5_absent_labels_leave_the_effective_n_unestimated():
    assert U.effective_n_bartlett([]).status is U.ComponentStatus.UNESTIMATED
    assert U.effective_n_clusters([], []).status is U.ComponentStatus.UNESTIMATED
    assert U.conservative(U.effective_n_bartlett([1.0])).status is U.ComponentStatus.UNESTIMATED
    _, report = _report(fx.synthetic_fixture_manifest())
    assert 'effective_n_not_verified' in report.conditional_mean.reasons
    assert report.conditional_mean.status is U.ComponentStatus.FLAGGED


def _ar1(phi, seed, n=400):
    rng, x, out = random.Random(seed), 0.0, []
    for _ in range(n):
        x = phi * x + rng.gauss(0, 1)
        out.append(x)
    return out


def test_c5_strong_temporal_dependence_is_not_hidden_prereg_a3():
    """Pre-registered (c-labels/PREREG-C-SYNTHETIC-AUDIT.md, A3): seeds 1..20, phi 0.8 / 0, n 400, lag 5."""
    assert U.newey_west_lag(400) == 5
    dependent = [U.effective_n_bartlett(_ar1(0.8, seed)) for seed in range(1, 21)]
    independent = [U.effective_n_bartlett(_ar1(0.0, seed)) for seed in range(1, 21)]
    assert all(e.n_effective <= e.n_nominal for e in dependent + independent)
    assert math.fsum(e.n_effective / e.n_nominal for e in dependent) / 20 < 0.5
    assert math.fsum(e.n_effective / e.n_nominal for e in independent) / 20 > 0.7
    # the report inflates the SE when the declared n_train (400) exceeds the effective n; never deflates
    sample = U.EffectiveSample(400, 40, 'test', 10.0, U.ComponentStatus.ESTIMATED)
    _, base = _report(fx.synthetic_fixture_manifest(), dependence=U.EffectiveSample(
        400, 400, 'test', 1.0, U.ComponentStatus.ESTIMATED))
    _, adjusted = _report(fx.synthetic_fixture_manifest(), dependence=sample)
    se0 = float(base.conditional_mean.values['se_mean_dependence_adjusted'])
    se1 = float(adjusted.conditional_mean.values['se_mean_dependence_adjusted'])
    assert se1 == pytest.approx(se0 * math.sqrt(10)) and 'n_train_exceeds_effective_n' in adjusted.conditional_mean.reasons
    assert base.conditional_mean.status is U.ComponentStatus.ESTIMATED
    _, more = _report(fx.synthetic_fixture_manifest(), dependence=U.EffectiveSample(
        400, 4000, 'test', 1.0, U.ComponentStatus.ESTIMATED))
    assert more.conditional_mean.values['se_mean_dependence_adjusted'] == base.conditional_mean.values[
        'se_mean_dependence_adjusted']
    assert adjusted.v1_heuristic == base.v1_heuristic            # V1's number is unchanged; only the report moves


def test_c5_clustered_outcomes_reduce_the_effective_n():
    values = [float(g) for g in range(10) for _ in range(5)]
    clusters = [str(g) for g in range(10) for _ in range(5)]
    sample = U.effective_n_clusters(values, clusters)
    assert sample.design_effect == pytest.approx(5.0) and sample.n_effective == 10
    singles = U.effective_n_clusters([1.0, 2.0, 3.0], ['a', 'b', 'c'])
    assert singles.n_effective == 3
    assert U.conservative(sample, singles).n_effective == 3


def test_c5_out_of_domain_is_an_explicit_status():
    narrow = fx.synthetic_fixture_manifest(policy=ScoringPolicy(**{**fx.FIXTURE_POLICY.__dict__,
                                                                   'z_support_max': D('0.01')}))
    trace, report = _report(narrow)
    assert any(r.startswith('feature_outside_training_support') for r in trace.score.reasons)
    assert report.domain_status is U.DomainStatus.OUT_OF_DOMAIN and 'out_of_domain' in U.abstention_reasons(report)
    _, unknown = _report(fx.synthetic_fixture_manifest())
    assert unknown.domain_status is U.DomainStatus.UNKNOWN          # no declared domain: never IN_DOMAIN
    assert 'joint_domain_unknown_no_cinv' in unknown.domain.reasons
    _, inside = _report(fx.synthetic_fixture_manifest(cinv=True), domain=U.TrainingDomain(
        'synthetic', mahalanobis_q_max=D('1000'), label_range_r=(D('-3'), D('5'))))
    assert inside.domain_status is U.DomainStatus.IN_DOMAIN


def test_c5_extreme_predictions_get_no_excess_confidence():
    extreme = fx.synthetic_fixture_manifest(overrides={'head.gross_r.intercept': '50'})
    trace, report = _report(extreme, domain=U.TrainingDomain('synthetic', label_range_r=(D('-3'), D('5'))))
    assert trace.score.verdict is c.ScoreVerdict.TRADE_CANDIDATE and trace.score.uncertainty < D('0.01')
    assert report.domain_status is U.DomainStatus.OUT_OF_DOMAIN
    assert 'prediction_outside_training_label_range' in report.domain.reasons
    assert report.is_calibrated_probability is False and report.overall == 'DESCRIPTIVE_NOT_CALIBRATED'
    assert report.stop_probability.status is U.ComponentStatus.NOT_MODELLED
    assert report.tail_loss.status is U.ComponentStatus.NOT_MODELLED
    assert report.regime.status is U.ComponentStatus.NOT_ASSESSED
    # a single trade's spread dwarfs the SE of the mean
    assert float(report.single_trade.values['predictive_sd_r']) > 5 * float(
        report.conditional_mean.values['se_total'])


def test_c5_missing_features_documented_behaviour():
    candidate, _ = _scored()
    universe = fx.synthetic_universe()
    stale = fx.synthetic_snapshot('AAA', quotes=fx.synthetic_quotes('AAA', 0), funding=(), universe=universe)
    candidate = dataclasses.replace(candidate, snapshot=stale.ref())
    engine = ScoringEngine(fx.COST_MODEL)
    trace = engine.evaluate(candidate, stale, fx.synthetic_fixture_manifest())
    assert trace.score.status is c.ScoreStatus.INVALID_INPUT          # missing cost input: abstain, never impute
    small = {key: v for key, v in universe.items() if key in {fx.inst_id(b) for b in fx.BASES[:4]}}
    partial = fx.synthetic_snapshot('AAA', universe=small)             # below min_universe: universe features MISSING
    candidate = dataclasses.replace(candidate, snapshot=partial.ref())
    strict = engine.evaluate(candidate, partial, fx.synthetic_fixture_manifest())
    assert strict.score.status is c.ScoreStatus.INVALID_INPUT
    impute = fx.synthetic_fixture_manifest(policy=ScoringPolicy(**{**fx.FIXTURE_POLICY.__dict__,
                                                                   'impute_missing': True, 'max_imputed': 4}))
    imputed = engine.evaluate(candidate, partial, impute)
    assert imputed.score.status is c.ScoreStatus.ESTIMATED
    assert any(r.startswith('feature_imputed') for r in imputed.score.reasons)
    report = U.decompose(imputed.score, imputed.values, decode_manifest(impute)[0])
    assert float(report.conditional_mean.values['imputation_variance']) > 0
    assert 'imputed_features_mean_substitution' in report.misspecification.reasons


def test_c5_costs_are_a_scenario_and_ridge_is_flagged():
    _, report = _report(fx.synthetic_fixture_manifest(kind=c.ModelKind.RIDGE, cinv=True), cost_multiplier=D('2'))
    values = report.costs.values
    assert report.costs.status is U.ComponentStatus.SCENARIO
    assert D(values['expected_net_r_pessimistic']) < D(values['expected_net_r_base'])
    assert 'ridge_se_ols_approximation_shrinkage_bias_ignored' in report.misspecification.reasons
    json.dumps(report.to_wire())


def test_c5_parity_vectors_and_v1_catalogue_are_preserved():
    document = json.loads(GOLDEN_FILE.read_text())
    assert verify_golden(document) == []
    assert document['feature_spec']['fingerprint'] == CATALOGUE.fingerprint()


# ----------------------------------------------------------------------------------------- C3: catalogue V2

def test_c3_v2_draft_maps_v1_unchanged_and_cannot_be_frozen():
    draft = cv2.draft_from_v1()
    assert cv2.validate(draft) == ()
    assert [f.name for f in draft.features] == list(CATALOGUE.names) and len(draft.features) == 13
    xs = draft.get('xs_rel_ret_lb')
    assert xs.m4.decision is cv2.Decision.PENDING and any('CS-XMOM' in f for f in xs.m4.closed_families)
    reasons = cv2.freeze_reasons(draft)
    assert 'catalogue_not_bound_to_source' in reasons and 'm4_decision_pending:xs_rel_ret_lb' in reasons
    assert any(r.startswith('independence_check_not_passed:xs_rel_ret_lb') for r in reasons)
    assert draft.get('portfolio_corr_lb').role is cv2.RoleV2.RISK_GATE


def test_c3_every_change_is_versioned_never_silent():
    draft = cv2.draft_from_v1()
    without = [f for f in draft.features if f.name != 'xs_rel_ret_lb']
    silent = cv2.with_changes(draft, spec_version='2.0.0-draft', source_binding=None, features=without, changes=())
    assert 'unversioned_change:removed:xs_rel_ret_lb' in cv2.validate(silent)
    recorded = cv2.with_changes(draft, spec_version='2.0.0-draft', source_binding=None, features=without,
                                changes=(cv2.ChangeEntry('xs_rel_ret_lb', 'removed', 'M4: closed CS-XMOM family'),))
    assert cv2.validate(recorded) == ()
    same_version = cv2.with_changes(draft, spec_version=CATALOGUE.spec_version, source_binding=None,
                                    features=draft.features, changes=())
    assert 'catalogue_version_not_bumped' in cv2.validate(same_version)
    changed = [dataclasses.replace(f, formula='other') if f.name == 'rvol_lb' else f for f in draft.features]
    assert 'unversioned_change:modified:rvol_lb' in cv2.validate(dataclasses.replace(draft, features=tuple(changed)))


def test_c3_structural_rules():
    draft = cv2.draft_from_v1()
    rvol = draft.get('rvol_lb')
    bad = [
        dataclasses.replace(rvol, name='a', portfolio_dependent=True),
        dataclasses.replace(rvol, name='b', inputs=('realised_pnl',)),
        dataclasses.replace(rvol, name='c', availability=cv2.Availability('x', None, uses_post_decision_data=True)),
        dataclasses.replace(rvol, name='d', m4=dataclasses.replace(rvol.m4, closed_families=('x',),
                                                                   decision=cv2.Decision.NO_OVERLAP)),
        dataclasses.replace(rvol, name='e', m4=None),
    ]
    catalogue = dataclasses.replace(draft, features=draft.features + tuple(bad),
                                    changes=tuple(cv2.ChangeEntry(f.name, 'added', 'test') for f in bad))
    reasons = cv2.validate(catalogue)
    for expected in ('predictive_feature_portfolio_dependent:a', 'feature_input_outcome_derived:b',
                     'feature_uses_post_decision_data:c', 'closed_family_overlap_contradiction:d',
                     'm4_justification_missing:e'):
        assert expected in reasons
    bound = dataclasses.replace(draft, source_binding='CS-FUND',
                                features=(dataclasses.replace(rvol, source_binding='CS-UNLOCK'),))
    assert 'feature_source_binding_mismatch:rvol_lb' in cv2.validate(bound, parent=None)
