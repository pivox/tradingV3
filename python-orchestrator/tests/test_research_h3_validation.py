"""H3 validation framework (agent C): factorial harness, metrics, comparisons, scoring and adaptive checks,
walk-forward rules, power and the data registry.

SYNTHETIC data only: invented bars, candidates, scores and trades. Nothing here is evidence about any market.
Run: python -m pytest --noconftest tests/test_research_h3_validation.py
"""
from __future__ import annotations

import dataclasses
import random
import re
from decimal import ROUND_CEILING, ROUND_DOWN, ROUND_FLOOR, ROUND_UP
from decimal import Decimal as D
from pathlib import Path

import pytest

from scripts.research_h3 import contracts as c
from scripts.research_h3 import synthetic as s
from scripts.research_h3.validation import adaptive_checks as ac
from scripts.research_h3.validation import compare as cp
from scripts.research_h3.validation import comparators as cm
from scripts.research_h3.validation import factorial as fx
from scripts.research_h3.validation import metrics as mt
from scripts.research_h3.validation import plan as pl
from scripts.research_h3.validation import power as pw
from scripts.research_h3.validation import registry as rg
from scripts.research_h3.validation import scoring_checks as sc
from scripts.research_h3.validation import stats as st
from scripts.research_h3.validation import walkforward as wf
from scripts.research_h3.validation.records import (
    CostScenario, EquityPoint, ExitReason, RefusalRecord, RefusalStage, TradeRecord, VariantRun,
)

H = s.HOUR_MS
DAY = 24 * H
WEEK = 7 * DAY
START = s.T0_MS
FP_BOOK = 'sha256:' + 'b' * 64
FP_MARKET = 'sha256:' + 'c' * 64
REPO = Path(__file__).resolve().parents[2]


# ======================================================================================== synthetic trade runs

def trade(variant, cid, *, net, entry_ms, inst='SYN-USD-SYNTH', fee=D('0.25'), reason=None, portfolio=None,
          gap=D('0'), funding=D('0'), funding_events=0, final_stop=D('99'), exit_ms=None):
    gross = net + fee + funding
    return TradeRecord(candidate_id=cid, variant=variant, portfolio_id=portfolio or f'exp-synthetic-{variant.value}',
                       inst_id=inst, side=c.Side.LONG, decision_ms=entry_ms, entry_ms=entry_ms,
                       exit_ms=exit_ms if exit_ms is not None else entry_ms + H // 2, quantity=D('250'),
                       contract_value=D('0.01'), entry_price=D('100'), exit_price=D('100') + gross / D('2.5'),
                       initial_stop_price=D('99'), final_stop_price=final_stop, gross_pnl=gross, fees=fee,
                       spread_cost=D('0'), slippage=D('0'), funding=funding, funding_events=funding_events,
                       net_pnl=net, initial_risk=D('2.5'), gap_excess=gap,
                       exit_reason=reason or (ExitReason.STOP if net < 0 else ExitReason.TARGET), partial_exits=0,
                       actions_applied=0)


def synthetic_run(variant, blocks, *, block_ms=WEEK, scenario=CostScenario.BASE, refused=(), book=FP_BOOK,
                  market=FP_MARKET, experiment='exp-synthetic', equity=D('500')):
    """``blocks``: one list of trade nets per block; trade k of block b is candidate c-bbb-k on SYN or ALT."""
    portfolio = f'{experiment}-{variant.value}'
    trades, points, value = [], [EquityPoint(ts_ms=START, equity=equity, open_risk=D('0'), open_positions=0)], equity
    for b, nets in enumerate(blocks):
        for k, net in enumerate(nets):
            cid = f'c-{b:03d}-{k}'
            if cid in refused:
                continue
            entry_ms = START + b * block_ms + (k + 1) * H
            t = trade(variant, cid, net=D(net), entry_ms=entry_ms, portfolio=portfolio,
                      inst='SYN-USD-SYNTH' if k % 2 == 0 else 'ALT-USD-SYNTH')
            trades.append(t)
            value += t.net_pnl
            points.append(EquityPoint(ts_ms=t.exit_ms, equity=value, open_risk=D('0'), open_positions=0))
    end = START + len(blocks) * block_ms
    points.append(EquityPoint(ts_ms=end, equity=value, open_risk=D('0'), open_positions=0))
    ids = sorted(f'c-{b:03d}-{k}' for b, nets in enumerate(blocks) for k in range(len(nets)))
    refusals = tuple(RefusalRecord(candidate_id=cid, variant=variant, portfolio_id=portfolio, inst_id='SYN-USD-SYNTH',
                                   decision_ms=START, stage=RefusalStage.SCORE, reasons=('expected_net_r_not_positive',))
                     for cid in refused)
    return VariantRun(experiment_id=experiment, variant=variant, portfolio_id=portfolio, quote_ccy='USDC',
                      cost_scenario=scenario, initial_equity=equity, start_ms=START, end_ms=end,
                      candidate_book_fingerprint=book, market_fingerprint=market, candidate_ids=tuple(ids),
                      trades=tuple(trades), refusals=refusals, equity_curve=tuple(points))


def noise_blocks(seed, n_blocks=30, per_block=4, sd=2.5):
    rng = random.Random(seed)
    return [[D(str(round(rng.gauss(0, sd), 2))) for _ in range(per_block)] for _ in range(n_blocks)]


def shifted(blocks, shift):
    return [[v + D(str(shift)) for v in nets] for nets in blocks]


def factorial_runs(effects, *, seed=11, scenario=CostScenario.BASE, n_blocks=30):
    """B0 = noise; Bk = B0 + effects[k] per trade (same candidates, paired)."""
    base = noise_blocks(seed, n_blocks)
    return {v: synthetic_run(v, shifted(base, effects.get(v, 0)), scenario=scenario)
            for v in (c.Variant.B0, c.Variant.B1, c.Variant.B2, c.Variant.B3)}


FAST_PLAN = pl.StatisticalPlan(test_procedure='cbb_percentile', resamples=999)


# ================================================================================================ records

def test_trade_net_identity_and_r_multiple():
    t = trade(c.Variant.B0, 'c-1', net=D('5'), entry_ms=START, funding=D('0.1'), funding_events=1)
    assert t.r_multiple == D('2') and t.execution_cost == D('0.25')
    with pytest.raises(c.ContractError, match='validation_trade_net_identity_broken'):
        TradeRecord(**{**t.__dict__, 'net_pnl': D('5.01')})
    with pytest.raises(c.ContractError, match='validation_trade_stop_widened'):
        trade(c.Variant.B0, 'c-1', net=D('1'), entry_ms=START, final_stop=D('98.5'))
    with pytest.raises(c.ContractError, match='validation_trade_time_invalid'):
        TradeRecord(**{**t.__dict__, 'entry_ms': START - 1})
    assert TradeRecord.from_json(t.canonical_json()) == t


def test_run_candidate_book_must_close_and_portfolios_stay_separate():
    run = synthetic_run(c.Variant.B1, [[D('1'), D('-1')]], refused=('c-000-1',))
    assert len(run.trades) == 1 and len(run.refusals) == 1
    with pytest.raises(c.ContractError, match='validation_run_candidate_book_not_closed'):
        VariantRun(**{**run.__dict__, 'refusals': ()})
    foreign = trade(c.Variant.B1, 'c-000-1', net=D('1'), entry_ms=START + H, portfolio='exp-synthetic-B0')
    with pytest.raises(c.ContractError, match='validation_run_record_foreign_portfolio'):
        VariantRun(**{**run.__dict__, 'trades': run.trades + (foreign,), 'refusals': ()})
    bad_curve = run.equity_curve[:-1] + (EquityPoint(ts_ms=run.end_ms, equity=D('999'), open_risk=D('0'),
                                                     open_positions=0),)
    with pytest.raises(c.ContractError, match='validation_run_equity_end_mismatch'):
        VariantRun(**{**run.__dict__, 'equity_curve': bad_curve})


# ================================================================================================== stats

def test_holm_matches_the_existing_research_lev_helper():
    pytest.importorskip('numpy')
    from scripts.research_lev.stats import holm as lev_holm
    pvalues = {'a': 0.01, 'b': 0.04, 'c': 0.03, 'd': None}
    assert st.holm(pvalues) == lev_holm(pvalues)


def test_distribution_helpers_against_known_values():
    assert st.normal_ppf(0.975) == pytest.approx(1.959964, abs=1e-6)
    assert st.normal_ppf(0.01) == pytest.approx(-2.326348, abs=1e-6)
    assert st.chi2_sf(3.841459, 1) == pytest.approx(0.05, abs=1e-6)
    assert st.chi2_sf(18.307038, 10) == pytest.approx(0.05, abs=1e-6)
    assert st.chi2_sf(0.5, 4) == pytest.approx(0.973500, abs=1e-6)


def test_spearman_with_ties_and_monotone_transform():
    x = [1.0, 2.0, 2.0, 3.0, 4.0]
    assert st.average_ranks(x) == [1.0, 2.5, 2.5, 4.0, 5.0]
    assert st.spearman(x, [v ** 3 for v in x]) == pytest.approx(1.0)
    assert st.spearman([1, 2, 3, 4], [4, 3, 2, 1]) == pytest.approx(-1.0)
    assert st.spearman([1, 2], [1, 2]) is None


def test_bootstrap_is_deterministic_and_behaves():
    rng = random.Random(3)
    noise = [rng.gauss(0, 1) for _ in range(40)]
    a = st.one_sided_bootstrap(noise, alpha=0.05, resamples=999, seed=5)
    assert a == st.one_sided_bootstrap(noise, alpha=0.05, resamples=999, seed=5)
    strong = st.one_sided_bootstrap([v + 2 for v in noise], alpha=0.05, resamples=999, seed=5)
    assert strong.rejected and strong.lower_bound > 0
    negative = st.one_sided_bootstrap([v - 1 for v in noise], alpha=0.05, resamples=999, seed=5)
    assert not negative.rejected
    few = st.one_sided_bootstrap([1.0], alpha=0.05, resamples=99, seed=1)
    assert few.reason == 'stats_insufficient_observations' and not few.rejected


def test_student_t_and_batch_means_against_known_values():
    assert st.student_t_sf(2.015048, 5) == pytest.approx(0.05, abs=1e-6)
    assert st.student_t_sf(1.782288, 12) == pytest.approx(0.05, abs=1e-6)
    assert st.student_t_sf(-2.015048, 5) == pytest.approx(0.95, abs=1e-6)
    assert st.student_t_ppf(0.975, 10) == pytest.approx(2.228139, abs=1e-5)
    assert st.batch_means([1, 2, 3, 4, 5, 6, 7], 3) == [1.5, 3.5, 6.0]
    assert st.batch_count_rule(13) == 4 and st.batch_count_rule(52) == 7 and st.batch_count_rule(4) == 3


def test_self_normalised_and_batch_tests_behave():
    rng = random.Random(21)
    noise = [rng.gauss(0, 1) for _ in range(26)]
    t, scale = st.sn_statistic(noise)
    t_scaled, _ = st.sn_statistic([3.0 * v for v in noise])
    assert t == pytest.approx(t_scaled)                     # scale-free
    assert 4.8 < st._upper_quantile(st.sn_null_sample(26), 0.95) < 6.0   # near the large-n value 5.32
    for procedure in ('sn_gaussian', 'batch_t'):
        strong = st.one_sided_test([v + 2 for v in noise], procedure=procedure, alpha=0.05)
        assert strong.rejected and strong.lower_bound > 0 and strong.procedure == procedure
        assert not st.one_sided_test([v - 1 for v in noise], procedure=procedure, alpha=0.05).rejected
        low, high = st.two_sided_interval(noise, procedure=procedure, level=0.9)
        assert low < sum(noise) / len(noise) < high
    with pytest.raises(st.StatsError, match='stats_procedure_unknown'):
        st.one_sided_test(noise, procedure='t_test_on_trades', alpha=0.05)
    with pytest.raises(ValueError, match='plan_test_procedure_unknown'):
        pl.StatisticalPlan(test_procedure='t_test_on_trades')


@pytest.mark.parametrize('procedure', ['sn_gaussian', 'batch_t'])
def test_primary_logic_with_dependence_robust_procedures(procedure):
    plan = pl.StatisticalPlan(test_procedure=procedure)
    met = cp.evaluate(factorial_runs({c.Variant.B3: 3.0}), plan,
                      pessimistic=factorial_runs({c.Variant.B3: 2.5}, scenario=CostScenario.PESSIMISTIC))
    assert met['verdict'] == 'CRITERION_MET'
    assert met['primary']['components']['global_vs_reference']['procedure'] == procedure
    assert cp.evaluate(factorial_runs({}), plan)['verdict'] != 'CRITERION_MET'


def test_rank_ic_test_uses_the_declared_procedure():
    rows = [sc.ScoredOutcome(f'c{k}', START + (k // 3) * DAY, 'SYN', c.ScoreStatus.ESTIMATED,
                             c.ScoreVerdict.TRADE_CANDIDATE, float(k % 3), 0.5, float(k % 3) + 0.1 * (k % 7), False)
            for k in range(90)]
    result = sc.rank_ic_test(rows, start_ms=START, block_ms=DAY, alpha=0.05, procedure='sn_gaussian')
    assert result['procedure'] == 'sn_gaussian' and result['n'] == 30


def test_effective_sample_size_and_dependence_diagnostics():
    rng = random.Random(9)
    iid = [rng.gauss(0, 1) for _ in range(200)]
    ar, x = [], 0.0
    for _ in range(200):
        x = 0.8 * x + rng.gauss(0, 1)
        ar.append(x)
    assert st.effective_sample_size(iid) > 120
    assert st.effective_sample_size(ar) < 60
    assert st.ljung_box(ar, 4)[1] < 0.01 < st.ljung_box(iid, 4)[1]
    clustered = [[rng.gauss(m, 0.3) for _ in range(10)] for m in (rng.gauss(0, 1) for _ in range(20))]
    icc = st.intraclass_correlation(clustered)
    assert icc > 0.5 and st.design_effect(10, icc) > 5
    assert st.runs_test([True, False] * 20)[0] > 3  # alternating outcomes: too many runs


def test_constant_series_has_no_autocorrelation_whatever_the_float_summation():
    # Python 3.11 sums floats naively, 3.12 with compensation: the mean of a constant series can then be off by one
    # ulp, and the residuals' rounding noise must not read as autocorrelation (it once turned CI's verdicts into
    # INSUFFICIENT_EVIDENCE on 3.11 only).
    constant = [12 / 500] * 30
    assert st.autocorrelation(constant, 1) is None
    assert st.effective_sample_size(constant) == 30.0
    naive = 0.0
    for v in constant:
        naive += v
    residuals = [v - naive / 30 for v in constant]
    assert st.effective_sample_size([1.0 + r for r in residuals]) == 30.0


# ================================================================================================ metrics

def test_metrics_drawdown_profit_factor_blocks_and_concentration():
    run = synthetic_run(c.Variant.B0, [[D('5'), D('-2.5')], [D('-4'), D('1.5')]])
    summary = mt.summarize(run, block_ms=WEEK)
    assert summary['net_pnl'] == '0' and summary['trades'] == 4
    assert summary['profit_factor'] == '1'
    assert summary['drawdown']['max_drawdown_quote'] == '6.5'
    assert mt.block_pnl(run, WEEK) == [D('2.5'), D('-2.5')]
    assert summary['concentration']['top_instrument'] == 'SYN-USD-SYNTH'
    assert summary['concentration']['net_without_top_instrument'] == '-1'
    assert summary['extreme_losses']['worst_trade_r'] == '-1.6'
    assert summary['extreme_losses']['trades_below_minus_1r'] == 1
    stressed = mt.cost_stress(run.trades, D('2'))
    assert D(stressed['net_pnl']) == D('-1')  # four trades, 0.25 more fee each
    assert summary['temporal_stability']['positive_periods'] == 1


def test_metrics_agree_with_the_existing_outcome_statistics():
    from app.backtesting.research.statistics import OutcomeStatistics
    run = synthetic_run(c.Variant.B0, noise_blocks(4, n_blocks=6))
    reference = OutcomeStatistics()
    for t in run.trades:
        reference.add(t.net_pnl, t.initial_risk)
    expected = reference.result()
    summary = mt.summarize(run, block_ms=WEEK)
    assert D(summary['net_pnl']) == D(expected['net_pnl_quote'])
    assert D(summary['expectancy_net_r']) == D(expected['mean_realized_net_r'])
    assert D(summary['profit_factor']) == D(expected['net_profit_factor'])


def test_block_pnl_uses_marked_to_market_equity_at_edges():
    run = synthetic_run(c.Variant.B0, [[D('1')], [D('2')], [D('-1')]], block_ms=DAY)
    assert mt.block_pnl(run, DAY) == [D('1'), D('2'), D('-1')]
    assert mt.block_edges(0, 10, 4) == (0, 4, 8, 10)


# ================================================================================================ compare

def test_runs_must_share_inputs_to_be_compared():
    a = synthetic_run(c.Variant.B0, [[D('1')]])
    with pytest.raises(cp.CompareError, match='candidate_book_fingerprint'):
        cp.check_comparable(a, synthetic_run(c.Variant.B1, [[D('1')]], book='sha256:' + 'd' * 64))
    with pytest.raises(cp.CompareError, match='market_fingerprint'):
        cp.check_comparable(a, synthetic_run(c.Variant.B1, [[D('1')]], market='sha256:' + 'e' * 64))
    with pytest.raises(cp.CompareError, match='cost_scenario'):
        cp.check_comparable(a, synthetic_run(c.Variant.B1, [[D('1')]], scenario=CostScenario.PESSIMISTIC))
    with pytest.raises(cp.CompareError, match='compare_portfolios_not_independent'):
        cp.check_comparable(a, a)


def test_strong_combined_effect_meets_the_primary_criterion_with_guards():
    effects = {c.Variant.B1: 0.3, c.Variant.B2: 0.3, c.Variant.B3: 3.0}
    runs = factorial_runs(effects)
    pessimistic = factorial_runs({k: v - 0.5 if k is c.Variant.B3 else v for k, v in effects.items()},
                                 scenario=CostScenario.PESSIMISTIC)
    result = cp.evaluate(runs, FAST_PLAN, pessimistic=pessimistic)
    assert result['primary']['met'] and result['verdict'] == 'CRITERION_MET', result['guards']
    assert result['secondary']['tests']['adaptation_after_scoring']['status'] == 'secondary_rejects_null'
    # review R1-F1: every test rejects, yet the effect cannot be attributed without matched controls
    assert result['hypotheses']['H3-C_combination'] == 'rejects_null_attribution_not_identified'
    assert 'supported' not in result['hypotheses'].values()
    assert result['attribution_blockers']['H3-A_scoring'] == ['R1-F1_no_matched_random_abstention_control']
    assert result['evidence'] == 'unlabelled_not_evidence'


def test_no_effect_does_not_meet_and_secondaries_stay_exploratory():
    runs = factorial_runs({})
    result = cp.evaluate(runs, FAST_PLAN, pessimistic=factorial_runs({}, scenario=CostScenario.PESSIMISTIC))
    assert result['verdict'] in ('CRITERION_NOT_MET', 'INSUFFICIENT_EVIDENCE')
    assert not result['primary']['met']
    assert all(t['status'] == 'secondary_exploratory_only_primary_not_met'
               for t in result['secondary']['tests'].values())
    assert set(result['hypotheses'].values()) == {'not_confirmatory'}


def test_improvement_without_net_gain_fails_the_intersection_union():
    # B0 loses 3.0 per trade, B3 improves it by 2.5: better than the reference, but still a net loss.
    base = shifted(noise_blocks(11), -3.0)
    runs = {v: synthetic_run(v, shifted(base, 2.5 if v is c.Variant.B3 else 0)) for v in cp.FACTORIAL}
    result = cp.evaluate(runs, FAST_PLAN)
    assert result['primary']['components']['global_vs_reference']['rejected']
    assert not result['primary']['components']['absolute_vs_cash']['rejected']
    assert result['verdict'] == 'CRITERION_NOT_MET'


def test_missing_pessimistic_replay_or_few_blocks_block_a_claim():
    effects = {c.Variant.B3: 3.0}
    assert cp.evaluate(factorial_runs(effects), FAST_PLAN)['verdict'] == 'CRITERION_MET_GUARDS_FAILED'
    short = factorial_runs(effects, n_blocks=8)
    assert cp.evaluate(short, FAST_PLAN)['verdict'] == 'INSUFFICIENT_EVIDENCE'


def test_results_do_not_depend_on_call_order():
    runs = factorial_runs({c.Variant.B3: 0.5})
    first = cp.evaluate(runs, FAST_PLAN)
    reordered = {v: runs[v] for v in reversed(cp.FACTORIAL)}
    assert cp.evaluate(reordered, FAST_PLAN) == first


def test_concentrated_gain_fails_the_concentration_guard():
    base = noise_blocks(11)
    b3 = [list(nets) for nets in base]
    b3[0][0] = b3[0][0] + D('400')  # one trade carries everything
    runs = {v: synthetic_run(v, b3 if v is c.Variant.B3 else base) for v in cp.FACTORIAL}
    guards = cp.guards(runs, FAST_PLAN, cp.comparison_series(runs, pl.PRIMARY_COMPONENTS[0], WEEK), None)
    assert not guards['concentration']['passed']


def test_plan_is_draft_and_declares_one_primary_and_four_secondaries():
    document = pl.DRAFT_PLAN.to_document()
    assert document['status'] == 'DRAFT' and document['procedure_validated'] is False
    with pytest.raises(ValueError, match='plan_procedure_not_validated_freeze_blocked'):
        pl.StatisticalPlan(status=c.ProtocolStatus.FROZEN)
    assert document['primary'] == ['global_vs_reference', 'absolute_vs_cash']
    assert len(document['secondary']) == 4
    assert {(x.treatment, x.reference) for x in pl.SECONDARY_FAMILY} == {
        (c.Variant.B1, c.Variant.B0), (c.Variant.B2, c.Variant.B0), (c.Variant.B3, c.Variant.B1),
        (c.Variant.B3, c.Variant.B2)}


# ================================================================================================ walk-forward

def test_walk_forward_is_chronological_with_embargo():
    folds = wf.walk_forward_folds(0, 100, train_ms=40, test_ms=20, embargo_ms=5)
    assert [(f.train_start_ms, f.train_end_ms, f.test_start_ms, f.test_end_ms) for f in folds] == [
        (0, 40, 45, 65), (0, 60, 65, 85)]
    rolling = wf.walk_forward_folds(0, 100, train_ms=40, test_ms=20, embargo_ms=5, expanding=False)
    assert rolling[1].train_start_ms == 20
    for f in folds:
        assert f.train_end_ms < f.test_start_ms


def test_labels_overlapping_the_boundary_are_purged_and_embargoed():
    fold = wf.walk_forward_folds(0, 100, train_ms=40, test_ms=20, embargo_ms=5)[0]
    labels = [wf.Label('early', 10, 30), wf.Label('crosses_fit', 35, 50), wf.Label('in_gap', 42, 44),
              wf.Label('test', 50, 60), wf.Label('test_crossing', 60, 80), wf.Label('later', 70, 75)]
    a = wf.assign(labels, fold)
    assert a.train == ('early',) and a.purged == ('crosses_fit',) and a.embargoed == ('in_gap',)
    assert a.test == ('test', 'test_crossing') and a.test_crossing == ('test_crossing',)
    assert wf.assign(labels, fold, drop_test_crossing=True).test == ('test',)
    wf.check_assignment(a, labels)
    forged = dataclasses.replace(a, train=('early', 'crosses_fit'))
    with pytest.raises(wf.WalkForwardError, match='walkforward_training_label_not_realised_at_fit'):
        wf.check_assignment(forged, labels)


def test_transforms_are_fitted_on_training_rows_only():
    rows = [wf.Row(available_ms=t, value=float(t)) for t in range(10)]
    scaler = wf.TrainOnlyScaler.fit(rows[:5], cutoff_ms=5)
    assert scaler.fitted_until_ms == 5 and scaler.mean == 2.0
    with pytest.raises(wf.WalkForwardError, match='walkforward_fit_uses_data_after_cutoff'):
        wf.TrainOnlyScaler.fit(rows, cutoff_ms=5)
    edges = wf.TrainOnlyQuantileEdges.fit(rows[:5], 5, (0.5,))
    edges.check_out_of_sample(5)
    with pytest.raises(wf.WalkForwardError, match='walkforward_edges_applied_in_sample'):
        edges.check_out_of_sample(4)


def test_point_in_time_universe_refuses_lookahead_and_detects_survivorship():
    records = [wf.ListingRecord('OLD', 0, 0, delisted_ms=50), wf.ListingRecord('LIVE', 0, 0),
               wf.ListingRecord('NEW', 60, 58)]
    assert wf.universe_at(records, 40) == ('LIVE', 'OLD')
    assert wf.universe_at(records, 59) == ('LIVE',)
    assert wf.universe_at(records, 60) == ('LIVE', 'NEW')
    findings = wf.audit_candidate_universe([('NEW', 40), ('OLD', 55), ('LIVE', 40), ('GHOST', 1)], records)
    assert [f['reason'] for f in findings] == ['universe_listed_after_decision', 'universe_delisted_before_decision',
                                               'universe_instrument_unknown']
    today_list = [r for r in records if r.delisted_ms is None]
    gaps = wf.survivorship_findings(today_list, records, 0, 100)
    assert gaps == [{'inst_id': 'OLD', 'delisted_ms': 50, 'reason': 'universe_survivorship_gap'}]


def test_trial_ledger_counts_development_trials_and_refuses_confirmatory_data():
    ledger = wf.TrialLedger()
    for k in range(5):
        ledger.record(wf.Trial(f't{k}', 'model', f'sha256:{k}', c.DataLabel.DEVELOPMENT, START))
    with pytest.raises(wf.WalkForwardError, match='walkforward_trial_on_confirmatory_data'):
        ledger.record(wf.Trial('peek', 'model', 'sha256:x', c.DataLabel.VALIDATION, START))
    assert ledger.summary()['trials'] == 5
    assert ledger.selection_alpha(0.05, 'model') == pytest.approx(1 - 0.95 ** 0.2)
    with pytest.raises(wf.WalkForwardError, match='walkforward_selected_configuration_not_recorded'):
        ledger.check_selected(['sha256:never-tried'])


# ======================================================================================= factorial with stubs

INSTRUMENTS = {base: s.instrument(base) for base in ('ALT', 'SYN')}
N_BARS = 14 * 24
END = START + N_BARS * H
STATIC = s.policy_ref('static-stub')
ADAPTIVE_REF = s.policy_ref('adaptive-stub')
RISK_REF = s.policy_ref('risk-stub')
CENT = D('0.01')


def make_bars(seed=7):
    rng = random.Random(seed)
    bars = []
    for base, inst in INSTRUMENTS.items():
        price = D('100')
        for k in range(N_BARS):
            o = price
            close = (o * (1 + D(str(round(rng.gauss(0, 0.006), 6))))).quantize(CENT)
            high = (max(o, close) * (1 + D(str(round(abs(rng.gauss(0, 0.003)), 6))))).quantize(CENT, ROUND_CEILING)
            low = (min(o, close) * (1 - D(str(round(abs(rng.gauss(0, 0.003)), 6))))).quantize(CENT, ROUND_FLOOR)
            bars.append(c.Bar(inst_id=inst.inst_id, interval_ms=H, open_ms=START + k * H, close_ms=START + (k + 1) * H,
                              available_ms=START + (k + 1) * H, is_closed=True, open=o, high=high, low=low,
                              close=close, volume_quote=D('1000000')))
            price = close
    return sorted(bars, key=lambda b: (b.open_ms, b.inst_id, b.interval_ms))


def make_decisions(market, seed=8):
    rng = random.Random(seed)
    decisions = []
    for decision_ms in range(START + 6 * H, END - 12 * H, 6 * H):
        snapshots = []
        for base, inst in INSTRUMENTS.items():
            last = market.bars_visible_at(decision_ms, inst.inst_id)[-1]
            features = (c.FeatureValue(name='last_close', value=last.close, status=c.FeatureStatus.OK,
                                       observed_ms=last.close_ms, available_ms=last.available_ms, unit='usd'),
                        c.FeatureValue(name='signal', value=D(str(round(rng.uniform(-1, 1), 3))),
                                       status=c.FeatureStatus.OK, observed_ms=decision_ms, available_ms=decision_ms,
                                       unit='score'))
            snapshots.append(c.FeatureSnapshot(header=s.header(side=None, decision_ms=decision_ms, inst=inst),
                                               snapshot_id=f'snap-{base}-{decision_ms}', feature_spec_id='stub',
                                               feature_spec_version='0.1.0', features=features,
                                               data_status=c.DataStatus.COMPLETE))
        decisions.append(fx.DecisionInput(decision_ms, tuple(snapshots)))
    return decisions


class StubProvider:
    generator_id, generator_version, hypothesis_id = 'gen-stub', '0.1.0', 'hyp-synthetic-stub'

    def __init__(self):
        self.calls = 0

    def candidates(self, snapshots, decision_ms):
        self.calls += 1
        out = []
        for snap in snapshots:
            entry = snap.value('last_close').value
            out.append(c.OpportunityCandidate(
                header=s.header(side=c.Side.LONG, decision_ms=decision_ms, inst=snap.header.instrument),
                candidate_id=f'c-{snap.header.instrument.base}-{decision_ms}', generator_id=self.generator_id,
                generator_version=self.generator_version, hypothesis_id=self.hypothesis_id, snapshot=snap.ref(),
                entry_reference_price=entry, initial_stop_price=(entry * D('0.99')).quantize(CENT, ROUND_DOWN),
                initial_target_price=(entry * D('1.02')).quantize(CENT, ROUND_UP), max_holding_ms=12 * H))
        return tuple(out)


MODEL = c.ModelManifest(model_id='stub-linear', model_version='0.1.0', kind=c.ModelKind.LINEAR,
                        feature_spec_id='stub', feature_spec_version='0.1.0', feature_names=('signal',),
                        parameters=(c.NamedValue(name='w_signal', value=D('1')),),
                        calibration=c.CalibrationStatus.CALIBRATED, training_provenance=(s.SYNTHETIC_PROVENANCE,),
                        protocol=s.DRAFT_PROTOCOL, created_ms=START, notes='synthetic stub')


class StubScorer:
    def __init__(self, sign=1):
        self.sign = sign
        self.calls = 0

    def score(self, candidate, snapshot, model):
        self.calls += 1
        value = self.sign * snapshot.value('signal').value
        trade_ok = value > 0
        return c.OpportunityScore(header=candidate.header, candidate_id=candidate.candidate_id,
                                  snapshot=candidate.snapshot, model=model.ref(), status=c.ScoreStatus.ESTIMATED,
                                  verdict=c.ScoreVerdict.TRADE_CANDIDATE if trade_ok else c.ScoreVerdict.NO_TRADE,
                                  expected_net_r=value, p_stop=D('0.5'), tail_loss_r=D('1'), uncertainty=D('0.5'),
                                  data_quality=D('1'), utility=None, rank=None,
                                  reasons=() if trade_ok else ('expected_net_r_not_positive',))

    def rank(self, scores):
        ordered = sorted(scores, key=lambda x: (-x.expected_net_r, x.candidate_id))
        return tuple(dataclasses.replace(x, rank=i + 1) for i, x in enumerate(ordered))


class StaticPolicy:
    policy = STATIC

    def decide(self, state):
        return c.AdaptiveAction.create(header=state.header, position_id=state.position_id, kind=c.ActionKind.HOLD,
                                       state=state.ref(), policy=self.policy, reasons=('static_hold',))


class AdaptivePolicy:
    policy = ADAPTIVE_REF

    def decide(self, state):
        one_r = state.entry_price - state.initial_stop
        if state.current_stop < state.entry_price and state.mfe_price >= state.entry_price + one_r:
            return c.AdaptiveAction.create(header=state.header, position_id=state.position_id,
                                           kind=c.ActionKind.TIGHTEN_STOP, state=state.ref(), policy=self.policy,
                                           reasons=('mfe_one_r_breakeven',), new_stop=state.entry_price)
        if state.holding_ms >= 9 * H and state.last_price < state.entry_price:
            return c.AdaptiveAction.create(header=state.header, position_id=state.position_id,
                                           kind=c.ActionKind.FULL_CLOSE, state=state.ref(), policy=self.policy,
                                           reasons=('time_decay_close',))
        return c.AdaptiveAction.create(header=state.header, position_id=state.position_id, kind=c.ActionKind.HOLD,
                                       state=state.ref(), policy=self.policy, reasons=('hold',))


class StubRiskGate:
    policy = RISK_REF

    def admit_entry(self, candidate, score, portfolio):
        inst, entry, equity = candidate.header.instrument, candidate.entry_reference_price, portfolio.equity
        per_contract = candidate.stop_distance * inst.contract_value
        qty = ((c.H3_LIMITS.per_trade_risk_max * equity) / per_contract).to_integral_value(ROUND_DOWN)
        room = ((equity * c.H3_LIMITS.leverage_max - portfolio.open_notional) / (inst.contract_value * entry))
        qty = max(D('0'), min(qty, room.to_integral_value(ROUND_DOWN)))
        risk = qty * per_contract
        reasons = []
        if qty < inst.min_size:
            reasons.append('size_below_minimum')
        if portfolio.open_risk + risk > c.H3_LIMITS.total_risk_max * equity:
            reasons.append('total_risk_budget_exceeded')
        admit = not reasons
        notional_after = portfolio.open_notional + (qty * inst.contract_value * entry if admit else 0)
        return c.RiskDecision(
            header=dataclasses.replace(candidate.header, portfolio_id=portfolio.portfolio_id),
            decision_id=f'risk-{candidate.candidate_id}', subject=c.RiskSubject.ENTRY,
            subject_id=candidate.candidate_id, verdict=c.RiskVerdict.ADMIT if admit else c.RiskVerdict.REJECT,
            reasons=tuple(reasons), policy=RISK_REF, limits=c.H3_LIMITS, equity=equity, quantity=qty,
            price_reference=entry, stop_price=candidate.initial_stop_price, risk_amount=risk,
            open_risk_before=portfolio.open_risk, open_risk_after=portfolio.open_risk + (risk if admit else 0),
            notional_before=portfolio.open_notional, notional_after=notional_after,
            leverage_after=notional_after / equity)

    def validate_action(self, action, state, portfolio):
        cv = state.header.instrument.contract_value
        old_risk = state.open_quantity * cv * (state.entry_price - state.current_stop)
        position_notional = state.open_quantity * cv * state.entry_price
        if action.kind is c.ActionKind.TIGHTEN_STOP:
            new_risk = state.open_quantity * cv * max(D('0'), state.entry_price - action.new_stop)
            notional_after, stop = portfolio.open_notional, action.new_stop
        else:
            new_risk, notional_after, stop = D('0'), portfolio.open_notional - position_notional, None
        after = portfolio.open_risk - old_risk + new_risk
        return c.RiskDecision(
            header=action.header, decision_id=f'risk-{action.action_id}', subject=c.RiskSubject.ACTION,
            subject_id=action.action_id, verdict=c.RiskVerdict.ADMIT, reasons=(), policy=RISK_REF,
            limits=c.H3_LIMITS, equity=portfolio.equity, quantity=None, price_reference=state.last_price,
            stop_price=stop, risk_amount=new_risk, open_risk_before=portfolio.open_risk, open_risk_after=after,
            notional_before=portfolio.open_notional, notional_after=notional_after,
            leverage_after=notional_after / portfolio.equity)


class StubSimulator:
    """Minimal hourly bar replay: entry at the decision bar's open, stop before target inside a bar, expiry and
    adaptive closes at the bar close, taker fees both sides. Long only. Test scaffolding, not agent D's simulator."""

    def run(self, spec, market):
        multiplier = spec.cost_model.pessimistic_multiplier if spec.cost_scenario is CostScenario.PESSIMISTIC else 1
        fee_rate = spec.cost_model.taker_fee_rate * multiplier
        bars = {(b.inst_id, b.open_ms): b for b in market.bars}
        intents = {}
        for e in spec.entries:
            intents.setdefault(e.candidate.header.clock.decision_ms, []).append(e)
        realised, open_positions, trades = D('0'), [], []
        refusals = list(spec.pre_refusals)
        curve = [EquityPoint(ts_ms=spec.start_ms, equity=spec.initial_equity, open_risk=D('0'), open_positions=0)]

        def risk_of(p):
            return p['qty'] * p['cv'] * max(D('0'), p['entry'] - p['stop'])

        def unrealised(price_of):
            return sum((p['qty'] * p['cv'] * (price_of(p) - p['entry']) - p['fee_in'] for p in open_positions), D('0'))

        def view(ms, price_of):
            return c.PortfolioView(portfolio_id=spec.portfolio_id, variant=spec.variant, quote_ccy=spec.quote_ccy,
                                   clock=s.clock(ms), equity=spec.initial_equity + realised + unrealised(price_of),
                                   cash=spec.initial_equity + realised,
                                   open_risk=sum((risk_of(p) for p in open_positions), D('0')),
                                   open_notional=sum((p['qty'] * p['cv'] * p['entry'] for p in open_positions), D('0')),
                                   open_position_ids=tuple(sorted(p['id'] for p in open_positions)), kill_switch=False)

        def close(p, ms, price, reason):
            nonlocal realised
            gross = p['qty'] * p['cv'] * (price - p['entry'])
            fees = p['fee_in'] + p['qty'] * p['cv'] * price * fee_rate
            gap = max(D('0'), (p['initial_stop'] - price) * p['qty'] * p['cv'])
            t = TradeRecord(candidate_id=p['id'], variant=spec.variant, portfolio_id=spec.portfolio_id,
                            inst_id=p['inst'], side=c.Side.LONG, decision_ms=p['entry_ms'], entry_ms=p['entry_ms'],
                            exit_ms=ms, quantity=p['qty'], contract_value=p['cv'], entry_price=p['entry'],
                            exit_price=price, initial_stop_price=p['initial_stop'], final_stop_price=p['stop'],
                            gross_pnl=gross, fees=fees, spread_cost=D('0'), slippage=D('0'), funding=D('0'),
                            funding_events=0, net_pnl=gross - fees, initial_risk=p['risk0'], gap_excess=gap,
                            exit_reason=reason, partial_exits=0, actions_applied=len(p['actions']))
            trades.append(t)
            realised += t.net_pnl
            open_positions.remove(p)

        last_close = {}
        for t0 in range(spec.start_ms, spec.end_ms, H):
            t1 = t0 + H
            for intent in sorted(intents.get(t0, []), key=lambda e: e.priority):
                cand = intent.candidate
                decision = spec.risk_gate.admit_entry(cand, intent.score, view(t0, lambda p: last_close[p['inst']]))
                if decision.verdict is c.RiskVerdict.REJECT:
                    refusals.append(RefusalRecord(candidate_id=cand.candidate_id, variant=spec.variant,
                                                  portfolio_id=spec.portfolio_id, inst_id=cand.header.instrument.inst_id,
                                                  decision_ms=t0, stage=RefusalStage.RISK, reasons=decision.reasons))
                    continue
                inst = cand.header.instrument
                fill = bars[(inst.inst_id, t0)].open
                qty, cv = decision.quantity, inst.contract_value
                open_positions.append(dict(
                    id=cand.candidate_id, inst=inst.inst_id, instrument=inst, qty=qty, cv=cv, entry=fill, entry_ms=t0,
                    stop=cand.initial_stop_price, initial_stop=cand.initial_stop_price,
                    target=cand.initial_target_price, mfe=fill, mae=fill, fee_in=qty * cv * fill * fee_rate,
                    risk0=qty * cv * (fill - cand.initial_stop_price), max_ms=cand.max_holding_ms, actions=[]))
            for p in list(open_positions):
                bar = bars[(p['inst'], t0)]
                p['mfe'], p['mae'] = max(p['mfe'], bar.high), min(p['mae'], bar.low)
                if bar.low <= p['stop']:
                    close(p, t0 + H // 2, min(bar.open, p['stop']), ExitReason.STOP)
                elif p['target'] is not None and bar.high >= p['target']:
                    close(p, t0 + H // 2, p['target'], ExitReason.TARGET)
            for b in (bars[(i.inst_id, t0)] for i in INSTRUMENTS.values()):
                last_close[b.inst_id] = b.close
            for p in list(open_positions):
                price = last_close[p['inst']]
                if t1 == spec.end_ms:
                    close(p, t1, price, ExitReason.END_OF_DATA)
                    continue
                if t1 - p['entry_ms'] >= p['max_ms']:
                    close(p, t1, price, ExitReason.TIME_EXPIRY)
                    continue
                portfolio = view(t1, lambda q: last_close[q['inst']])
                state = c.PositionState(
                    header=s.header(decision_ms=t1, portfolio_id=spec.portfolio_id, inst=p['instrument']),
                    position_id=p['id'], candidate_id=p['id'], status=c.PositionStatus.OPEN, entry_price=p['entry'],
                    entry_ms=p['entry_ms'], initial_quantity=p['qty'], open_quantity=p['qty'],
                    initial_stop=p['initial_stop'], current_stop=p['stop'], current_target=p['target'],
                    last_price=price, mfe_price=p['mfe'], mae_price=p['mae'], holding_ms=t1 - p['entry_ms'],
                    max_holding_ms=p['max_ms'], volatility=None, liquidity=c.LiquidityStatus.NORMAL,
                    funding_paid=D('0'), realized_pnl=D('0'), initial_risk_amount=p['risk0'],
                    equity=portfolio.equity, open_risk_total=portfolio.open_risk,
                    risk_budget_remaining=c.H3_LIMITS.total_risk_max * portfolio.equity - portfolio.open_risk,
                    actions_applied=tuple(p['actions']), policy=spec.policy.policy,
                    data_status=c.DataStatus.COMPLETE, kill_switch=False)
                action = spec.policy.decide(state)
                if action.kind is c.ActionKind.HOLD:
                    continue
                verdict = spec.risk_gate.validate_action(action, state, portfolio)
                if verdict.verdict is not c.RiskVerdict.ADMIT:
                    continue
                p['actions'].append(action.action_id)
                if action.kind is c.ActionKind.TIGHTEN_STOP:
                    p['stop'] = action.new_stop
                elif action.kind is c.ActionKind.FULL_CLOSE:
                    close(p, t1, price, ExitReason.ADAPTIVE_CLOSE)
            open_risk = sum((risk_of(p) for p in open_positions), D('0'))
            curve.append(EquityPoint(ts_ms=t1, equity=spec.initial_equity + realised
                                     + unrealised(lambda q: last_close[q['inst']]),
                                     open_risk=open_risk, open_positions=len(open_positions)))
        return VariantRun(experiment_id=spec.experiment_id, variant=spec.variant, portfolio_id=spec.portfolio_id,
                          quote_ccy=spec.quote_ccy, cost_scenario=spec.cost_scenario,
                          initial_equity=spec.initial_equity, start_ms=spec.start_ms, end_ms=spec.end_ms,
                          candidate_book_fingerprint=spec.candidate_book_fingerprint,
                          market_fingerprint=spec.market_fingerprint, candidate_ids=spec.candidate_ids,
                          trades=tuple(trades), refusals=tuple(refusals), equity_curve=tuple(curve))


def manifest(**overrides):
    base = dict(experiment_id='exp-stub', protocol=s.DRAFT_PROTOCOL, variants=cp.FACTORIAL,
                candidate_generator_id='gen-stub', candidate_generator_version='0.1.0',
                hypothesis_id='hyp-synthetic-stub', scoring_model=MODEL.ref(), static_policy=STATIC,
                adaptive_policy=ADAPTIVE_REF, risk_policy=RISK_REF, limits=c.H3_LIMITS,
                cost_model=c.CostModel(maker_fee_rate=D('0.0002'), taker_fee_rate=D('0.0005'), slippage_rate=D('0'),
                                       spread_model='none', funding_model='none', pessimistic_multiplier=D('2')),
                initial_equity=D('500'), quote_ccy='USDC', datasets=(s.SYNTHETIC_PROVENANCE,), seed=20261015,
                code_digest='sha256:' + 'a' * 64, created_ms=START)
    base.update(overrides)
    return c.ExperimentManifest(**base)


@pytest.fixture(scope='module')
def world():
    market = fx.MarketStream(bars=tuple(make_bars()))
    provider = StubProvider()
    decisions = make_decisions(market)
    book = fx.build_candidate_book(provider, decisions, start_ms=START, end_ms=END)
    return market, book, provider, decisions


@pytest.fixture(scope='module')
def base(world):
    """One base-cost factorial run shared by the read-only tests (and the scorer that produced it)."""
    scorer = StubScorer()
    return run(world, scorer=scorer), scorer


def run(world, *, scorer=None, order=cp.FACTORIAL, scenario=CostScenario.BASE, simulator_factory=StubSimulator,
        risk_gate_factory=StubRiskGate, **manifest_overrides):
    market, book, _, _ = world
    return fx.run_factorial(manifest=manifest(**manifest_overrides), book=book, market=market,
                            scorer=scorer or StubScorer(), model=MODEL, static_policy=StaticPolicy(),
                            adaptive_policy=AdaptivePolicy(), risk_gate_factory=risk_gate_factory,
                            simulator_factory=simulator_factory, start_ms=START, end_ms=END, cost_scenario=scenario,
                            order=order, risk_policy=RISK_REF)


def test_generator_is_called_once_per_decision_before_any_variant(world, base):
    market, book, provider, decisions = world
    assert provider.calls == len(decisions)
    assert len(book.candidates) == 2 * len(decisions)
    result, scorer = base
    assert scorer.calls == len(book.candidates)  # once per candidate, shared by B1 and B3
    assert provider.calls == len(decisions)      # running the variants did not call the generator again


def test_four_variants_receive_identical_candidates_and_market(world, base):
    market, book, _, _ = world
    result = base[0]
    specs = result.specs
    assert {sp.candidate_book_fingerprint for sp in specs.values()} == {book.fingerprint()}
    assert {sp.market_fingerprint for sp in specs.values()} == {market.fingerprint()}
    assert {sp.candidate_ids for sp in specs.values()} == {book.ids}
    assert len({sp.portfolio_id for sp in specs.values()}) == 4
    assert {(sp.initial_equity, sp.cost_model, sp.limits, sp.start_ms, sp.end_ms) for sp in specs.values()} == {
        (D('500'), manifest().cost_model, c.H3_LIMITS, START, END)}
    entries = {v: [(e.candidate.candidate_id, e.priority) for e in specs[v].entries] for v in cp.FACTORIAL}
    assert entries[c.Variant.B0] == entries[c.Variant.B2]
    assert entries[c.Variant.B1] == entries[c.Variant.B3]
    assert len(entries[c.Variant.B0]) == len(book.candidates)  # the reference offers every candidate
    assert specs[c.Variant.B1].pre_refusals == tuple(
        dataclasses.replace(r, variant=c.Variant.B1, portfolio_id=specs[c.Variant.B1].portfolio_id)
        for r in specs[c.Variant.B3].pre_refusals)
    for variant, r in result.runs.items():
        assert r.candidate_book_fingerprint == book.fingerprint() and r.market_fingerprint == market.fingerprint()
        assert all(t.portfolio_id == r.portfolio_id for t in r.trades)
    assert result.runs[c.Variant.B0].trades and result.runs[c.Variant.B3].trades
    assert any(t.exit_reason is ExitReason.ADAPTIVE_CLOSE for t in result.runs[c.Variant.B2].trades)
    assert all(t.actions_applied == 0 for t in result.runs[c.Variant.B0].trades)


def test_portfolios_are_independent_of_order_and_of_other_variants(world, base):
    first = base[0]
    reordered = run(world, order=tuple(reversed(cp.FACTORIAL)))
    assert {v: r.fingerprint() for v, r in first.runs.items()} == {v: r.fingerprint() for v, r in reordered.runs.items()}
    flipped = run(world, scorer=StubScorer(sign=-1))   # changes only the scored entries
    for v in (c.Variant.B0, c.Variant.B2):
        assert flipped.runs[v].fingerprint() == first.runs[v].fingerprint()
    for v in (c.Variant.B1, c.Variant.B3):
        assert flipped.runs[v].fingerprint() != first.runs[v].fingerprint()


def test_reference_order_is_blind_to_scores_and_features(world):
    _, book, _, _ = world
    group = book.by_decision()[0][1]
    order = [x.candidate_id for x in fx.reference_order(group, 20261015)]
    rescored = [dataclasses.replace(x, initial_target_price=None) for x in group]
    assert [x.candidate_id for x in fx.reference_order(rescored, 20261015)] == order


def test_shared_components_and_identity_mismatches_are_refused(world):
    shared = StubSimulator()
    with pytest.raises(fx.FactorialError, match='factorial_component_shared_between_variants'):
        run(world, simulator_factory=lambda: shared)

    class LyingSimulator(StubSimulator):
        def run(self, spec, market):
            honest = super().run(spec, market)
            return VariantRun(**{**honest.__dict__, 'market_fingerprint': 'sha256:' + 'f' * 64})

    with pytest.raises(fx.FactorialError, match='factorial_run_identity_mismatch'):
        run(world, simulator_factory=LyingSimulator)
    with pytest.raises(fx.FactorialError, match='factorial_manifest_policy_mismatch'):
        run(world, adaptive_policy=s.policy_ref('other'))
    with pytest.raises(fx.FactorialError, match='factorial_manifest_generator_mismatch'):
        run(world, hypothesis_id='hyp-other')


def test_candidates_outside_the_point_in_time_universe_are_refused(world):
    market, _, _, decisions = world
    later = decisions[3].decision_ms
    records = [wf.ListingRecord(INSTRUMENTS['SYN'].inst_id, 0, 0),
               wf.ListingRecord(INSTRUMENTS['ALT'].inst_id, later, later)]
    with pytest.raises(fx.FactorialError, match='factorial_candidate_outside_point_in_time_universe'):
        fx.build_candidate_book(StubProvider(), decisions, start_ms=START, end_ms=END,
                                universe=lambda ms: wf.universe_at(records, ms))


def test_generator_cannot_use_a_later_snapshot_or_decision(world):
    _, _, _, decisions = world
    first, second = decisions[0], decisions[1]

    class PeekingProvider(StubProvider):
        def candidates(self, snapshots, decision_ms):
            if decision_ms == first.decision_ms:   # builds the first instant's candidates from the next instant
                return super().candidates(second.snapshots, second.decision_ms)
            return super().candidates(snapshots, decision_ms)

    with pytest.raises(fx.FactorialError, match='factorial_candidate_decision_mismatch'):
        fx.build_candidate_book(PeekingProvider(), decisions, start_ms=START, end_ms=END)

    class ForeignSnapshotProvider(StubProvider):
        def candidates(self, snapshots, decision_ms):
            out = super().candidates(snapshots, decision_ms)
            if decision_ms == first.decision_ms:
                out = (dataclasses.replace(out[0], snapshot=second.snapshots[0].ref()),) + out[1:]
            return out

    with pytest.raises(fx.FactorialError, match='factorial_candidate_snapshot_not_from_this_decision'):
        fx.build_candidate_book(ForeignSnapshotProvider(), decisions, start_ms=START, end_ms=END)


def test_market_view_for_decisions_hides_unclosed_or_unavailable_bars(world):
    market, _, _, _ = world
    visible = market.bars_visible_at(START + 2 * H)
    assert visible and max(b.close_ms for b in visible) == START + 2 * H
    late = c.Bar(inst_id='SYN-USD-SYNTH', interval_ms=H, open_ms=START, close_ms=START + H,
                 available_ms=START + H + 5_000, is_closed=True, open=D('100'), high=D('101'), low=D('99'),
                 close=D('100'), volume_quote=D('1'))
    stream = fx.MarketStream(bars=(late,))
    assert stream.bars_visible_at(START + H) == ()
    assert stream.bars_visible_at(START + H + 5_000) == (late,)


def test_end_to_end_synthetic_evaluation_is_labelled_and_insufficient(world, base):
    base_result = base[0]
    pessimistic = run(world, scenario=CostScenario.PESSIMISTIC)
    result = cp.evaluate(base_result.runs, pl.StatisticalPlan(block_ms=DAY, resamples=499), manifest=manifest(),
                         pessimistic=pessimistic.runs)
    assert result['evidence'] == 'synthetic_not_evidence'
    assert result['verdict'] == 'INSUFFICIENT_EVIDENCE'  # 14 synthetic days cannot reach the minimum information
    assert result['metrics']['B0']['cost_scenario'] == 'base'
    fees_base = D(result['metrics']['B0']['costs']['fees'])
    fees_pess = sum((t.fees for t in pessimistic.runs[c.Variant.B0].trades), D('0'))
    assert fees_pess > fees_base


# ================================================================================================ scoring checks

def test_scoring_checks_on_the_stub_world(world, base):
    result = base[0]
    rows = sc.join_scores_outcomes(result.scores, result.runs[c.Variant.B0])
    assert len(rows) == len(result.scores)
    quality = sc.rank_quality(rows)
    assert quality['n'] == sum(1 for r in rows if r.realised_r is not None)
    report = sc.abstention(rows)
    assert report['abstention_rate'] == pytest.approx(
        sum(1 for x in result.scores if x.verdict is c.ScoreVerdict.NO_TRADE) / len(rows))
    table = sc.bucket_returns(rows, wf.a_priori_edges((-0.5, 0.0, 0.5)))
    assert sum(b['n'] for b in table['buckets']) == quality['n']
    with pytest.raises(sc.ScoringCheckError, match='scoring_labels_must_come_from_static_reference'):
        sc.join_scores_outcomes(result.scores, result.runs[c.Variant.B2])


def test_perfect_ranking_calibration_and_in_sample_edges_refusal():
    rows = [sc.ScoredOutcome(f'c{k}', START + (k // 3) * H, 'SYN', c.ScoreStatus.ESTIMATED,
                             c.ScoreVerdict.TRADE_CANDIDATE, float(k), 0.5, float(k), k % 2 == 0) for k in range(30)]
    quality = sc.rank_quality(rows)
    assert quality['spearman_pooled'] == pytest.approx(1.0) and quality['mean_cross_sectional_ic'] == pytest.approx(1.0)
    regression = sc.calibration_regression(rows)
    assert regression['slope'] == pytest.approx(1.0) and regression['intercept'] == pytest.approx(0.0)
    fitted = wf.FrozenEdges((10.0,), START + 5 * H, 'train_quantiles')
    with pytest.raises(wf.WalkForwardError, match='walkforward_edges_applied_in_sample'):
        sc.bucket_returns(rows, fitted)
    assert sc.brier(rows)['brier'] == pytest.approx(0.25)
    assert sc.population_stability_index([0.1, 0.2, 0.9], [0.1, 0.2, 0.9], wf.a_priori_edges((0.5,))) == 0.0


def test_scoring_verdict_requires_economic_value():
    good_rank = {'rejected': True}
    assert sc.scoring_verdict(good_rank, {'status': 'secondary_does_not_reject_null'}) == \
        'scoring_ranking_without_economic_value'
    assert sc.scoring_verdict(good_rank, {'status': 'secondary_rejects_null'}) == 'scoring_ranking_and_economic_value'
    assert sc.scoring_verdict({'rejected': False}, {'status': 'secondary_exploratory_only_primary_not_met'}) == \
        'scoring_economic_not_confirmatory_ranking_not_shown'


def test_score_decided_after_entry_is_refused(world, base):
    result = base[0]
    b0 = result.runs[c.Variant.B0]
    traded = b0.trades[0]
    score = next(x for x in result.scores if x.candidate_id == traded.candidate_id)
    late_header = dataclasses.replace(score.header, clock=s.clock(traded.entry_ms + H))
    with pytest.raises(sc.ScoringCheckError, match='scoring_score_after_entry'):
        sc.join_scores_outcomes([dataclasses.replace(score, header=late_header)], b0)


# ================================================================================================ adaptive checks

def test_paired_exits_pair_same_entry_rule(world, base):
    result = base[0]
    report = ac.paired_exits(result.runs[c.Variant.B0], result.runs[c.Variant.B2])
    assert report['common_candidates'] > 0
    assert sum(n for row in report['exit_reason_crosstab'].values() for n in row.values()) == \
        report['common_candidates']
    assert 'adaptive_close' in {k for row in report['exit_reason_crosstab'].values() for k in row}
    with pytest.raises(ac.AdaptiveCheckError, match='adaptive_pair_not_same_entry_rule'):
        ac.paired_exits(result.runs[c.Variant.B0], result.runs[c.Variant.B3])


def test_stub_replay_has_no_oracle_or_fill_findings(world, base):
    market, _, _, _ = world
    result = base[0]
    bars = {}
    for b in market.bars:
        bars.setdefault(b.inst_id, []).append(b)
    for r in result.runs.values():
        assert ac.fill_realism_findings(r.trades, bars) == []
        assert ac.funding_coverage_findings(r.trades, ()) == []
    windows = {t.candidate_id: t.entry_ms + 12 * H for t in result.runs[c.Variant.B2].trades}
    ticks = {i.inst_id: i.tick_size for i in INSTRUMENTS.values()}
    report = ac.oracle_exit_findings(result.runs[c.Variant.B2].trades, bars, windows, ticks)
    assert report['reason'] == 'adaptive_no_oracle_signature'


def test_hindsight_exits_and_impossible_fills_are_detected(world):
    market, _, _, _ = world
    bars = {}
    for b in market.bars:
        bars.setdefault(b.inst_id, []).append(b)
    inst = INSTRUMENTS['SYN'].inst_id
    oracle = []
    for k in range(10):
        entry_ms = START + (10 + 12 * k) * H
        window = [b for b in bars[inst] if entry_ms <= b.open_ms < entry_ms + 12 * H]
        best = max(window, key=lambda b: b.high)
        entry_bar = window[0]
        gross = (best.high - entry_bar.open) * D('2.5')
        oracle.append(TradeRecord(
            candidate_id=f'o{k}', variant=c.Variant.B2, portfolio_id='p', inst_id=inst, side=c.Side.LONG,
            decision_ms=entry_ms, entry_ms=entry_ms, exit_ms=best.open_ms, quantity=D('250'),
            contract_value=D('0.01'), entry_price=entry_bar.open, exit_price=best.high,
            initial_stop_price=entry_bar.open - 1, final_stop_price=entry_bar.open - 1, gross_pnl=gross,
            fees=D('0'), spread_cost=D('0'), slippage=D('0'), funding=D('0'), funding_events=0, net_pnl=gross,
            initial_risk=D('2.5'), gap_excess=D('0'), exit_reason=ExitReason.ADAPTIVE_CLOSE, partial_exits=0,
            actions_applied=1))
    report = ac.oracle_exit_findings(oracle, bars, {t.candidate_id: t.entry_ms + 12 * H for t in oracle},
                                     {inst: D('0.01')})
    assert report['share'] == 1.0 and report['reason'] == 'adaptive_oracle_exit_suspected'
    lucky_stop = dataclasses.replace(oracle[0], exit_reason=ExitReason.STOP)
    findings = ac.fill_realism_findings([lucky_stop], bars)
    assert {'candidate_id': 'o0', 'reason': 'fill_stop_better_than_stop'} in findings
    funding = (c.FundingObservation(inst_id=inst, funding_ms=oracle[0].entry_ms + 1, available_ms=oracle[0].entry_ms + 2,
                                    rate=D('0.0001')),)
    assert ac.funding_coverage_findings(oracle[:1], funding)[0]['reason'] == 'funding_events_mismatch'


# ================================================================================================ comparators

def test_passive_exposure_comparator(world):
    market, book, _, _ = world
    assert cm.passive_exposure_applicability(book.candidates) == (True, 'comparator_passive_applicable')
    two_sided = book.candidates[:1] + (dataclasses.replace(
        book.candidates[1], header=dataclasses.replace(book.candidates[1].header, side=c.Side.SHORT),
        initial_stop_price=book.candidates[1].entry_reference_price + 1, initial_target_price=None),)
    assert cm.passive_exposure_applicability(two_sided)[0] is False
    inst = INSTRUMENTS['SYN'].inst_id
    bars = [b for b in market.bars if b.inst_id == inst]
    path = cm.passive_pnl_path(bars, (), side=c.Side.LONG, notional=D('250'), fee_rate=D('0.0005'), start_ms=START,
                               end_ms=END)
    expected = D('250') / bars[0].open * (bars[-1].close - bars[0].open) - D('250') * D('0.0005') \
        - D('250') / bars[0].open * bars[-1].close * D('0.0005')
    assert path[-1][1] == expected
    blocks = cm.path_block_returns(path, start_ms=START, end_ms=END, block_ms=WEEK, initial_equity=D('500'))
    assert sum(blocks) == pytest.approx(float(expected / D('500')))
    assert cm.cash_block_returns(3) == [0.0, 0.0, 0.0]


# ================================================================================================ power

def test_power_cells_are_deterministic_and_respond_to_the_effect():
    scenario = pw.SCENARIOS[1]
    null = pw.Cell(scenario, 0.0, 0.0, 5, 26, panels=40, alpha=0.05, resamples=199, base_seed=1)
    strong = dataclasses.replace(null, delta_r=0.6, mu0_r=0.0)
    a, b = pw.run_cell(null), pw.run_cell(null)
    assert a == b and a['synthetic'] is True
    assert pw.run_cell(strong)['rate_p1_b3_vs_b0'] > 0.8 > a['rate_p1_b3_vs_b0']
    rows = [a, pw.run_cell(strong)]
    mde = pw.minimal_detectable_effect(rows, rate='rate_p1_b3_vs_b0')
    assert mde[0]['mde_grid_delta_r'] == 0.6


# ================================================================================================ registry

def test_registry_rules_and_conclusion():
    assert rg.independent_historical_validation() == rg.INDEPENDENT_DATA_NOT_AVAILABLE
    for window in ('fwd-okx-r1-w0', 'fwd-okx-r1-w1', 'fwd-okx-r1-w2', 'okx-any-2026-10-14-to-2026-10-31',
                   'any-venue-2026-10-14-to-2026-10-31', 'okx-xperp-metadata'):
        with pytest.raises(rg.RegistryError, match='registry_dataset_reserved'):
            rg.check_use(window, c.DataLabel.DEVELOPMENT, owner_consent=True)
    with pytest.raises(rg.RegistryError, match='registry_development_only'):
        rg.check_use('okx-swap-h2-2022-2026', c.DataLabel.VALIDATION)
    with pytest.raises(rg.RegistryError, match='registry_development_only'):
        rg.check_use('okx-xperp-history-2026-03-30-to-2026-10-12', c.DataLabel.HOLDOUT)
    with pytest.raises(rg.RegistryError, match='registry_development_needs_owner_consent'):
        rg.check_use('okx-swap-h2-2022-2026', c.DataLabel.DEVELOPMENT)
    assert rg.check_use('okx-swap-h2-2022-2026', c.DataLabel.DEVELOPMENT, owner_consent=True) == 'registry_use_allowed'
    with pytest.raises(rg.RegistryError, match='registry_not_target_market'):
        rg.check_use('binance-other-intraday-2019-2022', c.DataLabel.VALIDATION)
    # review R1-F7: off-target windows over the consumed 2023-2026 paths are consumed-equivalent
    for consumed in ('binance-other-intraday-2023-to-2026-10-13', 'hyperliquid-public-to-2026-10-13',
                     'kraken-futures-public-to-2026-10-13'):
        assert rg.entry(consumed).status is rg.DatasetStatus.CONSUMED_EQUIVALENT
        with pytest.raises(rg.RegistryError, match='registry_development_only'):
            rg.check_use(consumed, c.DataLabel.VALIDATION)
    with pytest.raises(rg.RegistryError, match='registry_prospective_only'):
        rg.check_use('okx-xperp-prospective', c.DataLabel.VALIDATION)
    assert rg.check_use('okx-xperp-prospective', c.DataLabel.PROSPECTIVE) == 'registry_use_allowed'
    real = c.Provenance(source='okx_public', dataset_id='okx-swap-h2-2022-2026', dataset_sha256='sha256:' + '0' * 64,
                        label=c.DataLabel.VALIDATION, license='okx-terms')
    with pytest.raises(rg.RegistryError):
        rg.check_provenance(real)
    assert rg.check_provenance(s.SYNTHETIC_PROVENANCE) == 'registry_synthetic'


def test_us_equity_rows_cannot_be_read_under_the_limited_go():
    """Owner decision 2026-10-16: registry, amendment and plan only; nothing acquired, nothing read."""
    for dataset_id in ('us-equity-underlying-history', 'edgar-filing-metadata'):
        e = rg.entry(dataset_id)
        assert e.status is rg.DatasetStatus.POTENTIAL_NOT_ACQUIRED
        assert e.h3_use is rg.H3Use.DEVELOPMENT_AFTER_LICENCE_AND_FROZEN_AMENDMENT
        assert e.market != rg.TARGET_MARKET
        for label in (c.DataLabel.DEVELOPMENT, c.DataLabel.VALIDATION, c.DataLabel.HOLDOUT, c.DataLabel.PROSPECTIVE):
            for consent in (False, True):
                with pytest.raises(rg.RegistryError, match='registry_dataset_not_acquired'):
                    rg.check_use(dataset_id, label, owner_consent=consent)
    window = rg.entry('us-equity-underlying-evaluation-window')
    assert window.status is rg.DatasetStatus.RESERVED
    assert window.h3_use is rg.H3Use.MECHANISM_EVALUATION_OFF_TARGET_NOT_XPERP_VALIDATION
    assert 'NOT an independent validation' in window.read_by
    for label in (c.DataLabel.DEVELOPMENT, c.DataLabel.HOLDOUT):
        with pytest.raises(rg.RegistryError, match='registry_dataset_reserved'):
            rg.check_use('us-equity-underlying-evaluation-window', label, owner_consent=True)
    # development and evaluation windows are chronologically distinct, with the embargo in between
    assert rg.US_EQUITY_DEVELOPMENT_END < rg.US_EQUITY_EMBARGO[:10] < rg.US_EQUITY_EVALUATION_START
    assert rg.US_EQUITY_HISTORY_END < '2026-06-01' < rg.PROSPECTIVE_EARLIEST_START_DRAFT
    # equity history is never independent historical validation of the X-Perp target
    assert rg.independent_historical_validation() == rg.INDEPENDENT_DATA_NOT_AVAILABLE


def test_registry_matches_the_eligibility_document():
    text = (REPO / 'docs/handbook/research/H3-DATA-ELIGIBILITY.md').read_text(encoding='utf-8')
    rows = {}
    for line in text.splitlines():
        match = re.match(r'\|\s*`([a-z0-9.-]+)`\s*\|(.*)\|\s*$', line)
        if match:
            cells = [x.strip() for x in match.group(2).split('|')]
            rows[match.group(1)] = cells
    assert set(rows) == {e.dataset_id for e in rg.REGISTRY}
    for e in rg.REGISTRY:
        assert f'`{e.status.value}`' in rows[e.dataset_id], e.dataset_id
        assert f'`{e.h3_use.value}`' in rows[e.dataset_id], e.dataset_id
    assert 'INDEPENDENT_DATA_NOT_AVAILABLE' in text
