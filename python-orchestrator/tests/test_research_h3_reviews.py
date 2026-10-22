"""H3 responses to the independent reviews (R1 quant/methodology, R2 CTO/risk/security), 2026-10-16.

One test (or more) per finding that was fixed in code; the finding id is in the test name. Synthetic values only:
no market data, no exchange, no order. Synthetic results are never evidence.
Run: python -m pytest --noconftest tests/test_research_h3_reviews.py
"""
from __future__ import annotations

import dataclasses
import random
from decimal import Decimal as D

import pytest

from scripts.research_h3 import contracts as c
from scripts.research_h3 import synthetic as s
from scripts.research_h3.adaptive import AdaptivePolicyV1, StaticPolicy
from scripts.research_h3.integration import costs as k
from scripts.research_h3.integration.candidates import SyntheticCandidateProvider
from scripts.research_h3.integration.label_replay import capacity_free_labels, label_mapping
from scripts.research_h3.integration.risk_gate import H3RiskConfig, H3RiskGate
from scripts.research_h3.integration.simulator import FailureKind, FailurePlan, InjectedFailure
from scripts.research_h3.integration.validation_adapter import EXIT_REASONS, RunVariantSimulator
from scripts.research_h3.validation import adaptive_checks as ac
from scripts.research_h3.validation import compare as cp
from scripts.research_h3.validation import factorial as fx
from scripts.research_h3.validation import scoring_checks as sc
from scripts.research_h3.validation.records import CostScenario, ExitReason, TradeRecord
from tests import test_research_h3_integration as T
from tests import test_research_h3_validation as V

H, T0, FLAT, INST, LONG = T.H, T.T0, T.FLAT, T.INST, T.LONG
LAT = 1_000
NETWORK_FILLED = FailurePlan((InjectedFailure(FailureKind.NETWORK_ERROR, 'cand-1', filled_on_venue=True),))


# ------------------------------------------------------------------------------------------------ R2-F1 venue truth

@pytest.mark.parametrize('failures', [FailurePlan(), NETWORK_FILLED])
def test_r2_f1_and_f6_order_journalled_before_the_recheck_and_kill_switch_never_acts_before_its_decision(failures):
    # admission at T0+H+1s, fill at the open T0+2H; the kill switch is decided at T0+2H+1s, after the fill
    result = T.run(None, [T.cand(decision_ms=T0 + H + LAT)], bar_list=T.bars([FLAT] * 6, latency=LAT),
                   failures=failures, kill_switch_at_ms=T0 + 2 * H + LAT, flatten_on_kill_switch=True)
    (trade,) = result.trades
    assert trade.entry_ms == T0 + 2 * H and result.kill_switch_ms == T0 + 2 * H + LAT
    assert trade.exit_reason == 'kill_switch_flatten' and trade.exit_ms >= result.kill_switch_ms  # R2-F6
    events = [r.event for r in result.audit.records]
    assert events.index(c.AuditEvent.ORDER) < events.index(c.AuditEvent.FILL)
    assert result.audit.verify() == ()


@pytest.mark.parametrize('failures', [FailurePlan(), NETWORK_FILLED])
def test_r2_f1_an_entry_filled_through_its_stop_is_kept_and_closed_at_once(failures):
    result = T.run(None, [T.cand(stop='98')], bar_list=T.bars([FLAT, ('97', '97.5', '96.5', '97'), FLAT]),
                   failures=failures)
    (trade,) = result.trades
    assert trade.exit_reason == 'fill_recheck_failed' and trade.entry_ms == trade.exit_ms == T0 + H
    assert 'fill_recheck.fill_beyond_stop' in trade.flags and not result.refusals
    assert trade.gross_pnl == 0 and trade.net_pnl == -(trade.fees + trade.spread_cost + trade.slippage_cost)
    assert result.final_cash == result.initial_equity + trade.net_pnl
    # C's record accepts it only as a fail-safe closed at its fill instant
    assert EXIT_REASONS['fill_recheck_failed'] is ExitReason.FAIL_SAFE
    record = dict(candidate_id='cand-1', variant=c.Variant.B0, portfolio_id='pf-B0', inst_id=INST.inst_id, side=LONG,
                  decision_ms=T0 + H, entry_ms=T0 + H, exit_ms=T0 + H, quantity=trade.quantity,
                  contract_value=INST.contract_value, entry_price=D('97'), exit_price=D('97'),
                  initial_stop_price=D('98'), final_stop_price=D('98'), gross_pnl=D('0'), fees=trade.fees,
                  spread_cost=trade.spread_cost, slippage=trade.slippage_cost, funding=D('0'), funding_events=0,
                  net_pnl=trade.net_pnl, initial_risk=trade.nominal_risk, gap_excess=D('0'),
                  exit_reason=ExitReason.FAIL_SAFE, partial_exits=0, actions_applied=0)
    TradeRecord(**record)
    with pytest.raises(c.ContractError, match='validation_trade_stop_not_protective'):
        TradeRecord(**{**record, 'exit_reason': ExitReason.STOP})
    with pytest.raises(c.ContractError, match='validation_trade_stop_not_protective'):
        TradeRecord(**{**record, 'exit_ms': T0 + 2 * H})


def test_r2_f1_a_fill_that_breaks_the_limits_at_the_current_equity_is_closed_not_dropped():
    # AAA gaps through its stop at T0+2H before ZZZ's fill at the same open: equity falls between ZZZ's admission
    # and its fill at the collar, so the re-check refuses a fill the venue already made.
    first, second = s.instrument('AAA'), s.instrument('ZZZ')
    bar_list = (T.bars([FLAT, FLAT, ('90', '90.5', '89.5', '90'), FLAT], inst=first)
                + T.bars([FLAT, FLAT, ('100.1', '100.5', '99.5', '100'), FLAT], inst=second))
    result = T.run(None, [T.cand(cid='cand-a', inst=first), T.cand(cid='cand-b', inst=second,
                                                                    decision_ms=T0 + 2 * H)], bar_list=bar_list)
    by_id = {t.candidate_id: t for t in result.trades}
    assert by_id['cand-a'].exit_reason == 'stop_gap'
    late = by_id['cand-b']
    assert late.exit_reason == 'fill_recheck_failed' and 'fill_recheck.fill_risk_above_per_trade' in late.flags
    assert late.nominal_risk <= D('0.005') * D('500')


# ------------------------------------------------------------------------------------- R2-F2 action venue truth

def _partial_once(st):
    if st.open_quantity == st.initial_quantity and st.holding_ms >= H:
        return c.ActionKind.PARTIAL_CLOSE, {'close_quantity': str(st.open_quantity // 2)}
    return None


def test_r2_f2_an_action_applied_on_the_venue_is_applied_once():
    rows = [FLAT, FLAT, ('100', '101', '99.8', '100.8'), ('100.8', '101', '100.5', '100.9'), FLAT, FLAT, FLAT]
    plain = T.run(None, [T.cand(stop='98')], bar_list=T.bars(rows), policy=T.Scripted(_partial_once))
    policy = T.Scripted(_partial_once)
    venue = T.run(None, [T.cand(stop='98')], bar_list=T.bars(rows), policy=policy, failures=FailurePlan((
        InjectedFailure(FailureKind.NETWORK_ERROR, 'cand-1', action_kind=c.ActionKind.PARTIAL_CLOSE,
                        filled_on_venue=True),)))
    (trade,) = venue.trades
    assert [e.reason for e in trade.exits] == ['partial_close', 'end_of_data']
    assert [e.quantity for e in trade.exits] == [e.quantity for e in plain.trades[0].exits]
    assert 'action_reconciled_after_network_error' in trade.flags
    assert sum(1 for st in policy.states if st.open_quantity == st.initial_quantity and st.holding_ms >= H) == 1
    for kind in (FailureKind.MODIFICATION_FAILED, FailureKind.ORDER_REJECTED, FailureKind.PROTECTION_FAILED):
        with pytest.raises(c.ContractError, match='h3_sim_failure_invalid'):
            InjectedFailure(kind, 'cand-1', action_kind=None if kind is FailureKind.PROTECTION_FAILED
                            else c.ActionKind.TIGHTEN_STOP, filled_on_venue=True)


# ------------------------------------------------------------------------------- R2-F12 non-atomic stop replacement

def test_r2_f12_a_cancelled_stop_whose_replacement_failed_leaves_the_position_unprotected_until_the_fail_safe():
    rows = [FLAT, ('100', '100.8', '99.8', '100.5'), ('100.5', '100.6', '98.4', '98.6'), ('98.6', '98.7', '98', '98.2'),
            FLAT]
    alt = s.instrument('ALT')
    ok = T.run(rows, [T.cand()], policy=T.Scripted(T.tighten_once('99.5')))
    assert ok.trades[0].exit_reason == 'stop' and ok.trades[0].exits[0].price == D('99.5')
    failed = T.run(None, [T.cand(), T.cand(cid='cand-2', inst=alt, decision_ms=T0 + 3 * H)],
                   bar_list=T.bars(rows) + T.bars([FLAT] * 5, inst=alt), policy=T.Scripted(T.tighten_once('99.5')),
                   failures=FailurePlan((InjectedFailure(FailureKind.REPLACE_CANCELLED_PLACE_FAILED, 'cand-1',
                                                         action_kind=c.ActionKind.TIGHTEN_STOP),)))
    trade = next(t for t in failed.trades if t.candidate_id == 'cand-1')
    # no stop during the bar (both stops were gone), closed at the next open at 98.6, below the old stop 99
    assert trade.exit_reason == 'fail_safe_unprotected' and trade.exit_ms == T0 + 3 * H
    assert trade.exits[0].price == D('98.6') and 'stop_replacement_failed_unprotected' in trade.flags
    assert EXIT_REASONS['fail_safe_unprotected'] is ExitReason.FAIL_SAFE
    point = next(p for p in failed.equity_curve if p.time_ms == T0 + 3 * H)
    assert point.open_risk == trade.quantity * INST.contract_value * trade.entry_price  # full notional at risk
    assert ('total_risk_budget_exhausted',) in [r.reasons for r in failed.refusals]
    with pytest.raises(c.ContractError, match='h3_sim_failure_invalid'):
        InjectedFailure(FailureKind.REPLACE_CANCELLED_PLACE_FAILED, 'cand-1', action_kind=c.ActionKind.FULL_CLOSE)


# ----------------------------------------------------------------------------- R2-F5 action-induced gap exits

def test_r2_f5_a_stop_tightened_through_the_open_at_application_is_counted():
    rows = [FLAT, ('100', '100.8', '99.8', '100.5'), ('99.8', '100', '99.5', '99.9'), FLAT]
    result = T.run(rows, [T.cand()], policy=T.Scripted(T.tighten_once('99.9')))
    (trade,) = result.trades
    assert trade.exit_reason == 'stop_gap' and trade.exit_ms == T0 + 2 * H
    assert 'stop_through_open_at_action_application' in trade.flags
    assert result.summary()['action_application_gap_exits'] == 1


# ------------------------------------------------------------------------------------------- R2-F4 R at decision

def test_r2_f4_r_is_fixed_at_admission_and_does_not_depend_on_the_fill_price():
    rows = [FLAT, ('98.6', '98.7', '98.55', '98.65'), ('98.65', '103.6', '98.6', '103.5'), FLAT, FLAT]
    near_stop = T.run(rows, [T.cand(stop='98.5', target='103')])
    at_reference = T.run([FLAT, FLAT, ('100', '103.6', '99.9', '103.5'), FLAT, FLAT],
                         [T.cand(stop='98.5', target='103')])
    a, b = near_stop.trades[0], at_reference.trades[0]
    assert a.quantity == b.quantity and a.nominal_risk == b.nominal_risk     # same admission, same R
    assert a.fill_risk < a.nominal_risk and b.fill_risk <= b.nominal_risk
    admission = next(d for d in near_stop.risk_decisions if d.decision_id == a.admission_decision_id)
    assert a.nominal_risk == admission.risk_amount and a.realized_r == a.net_pnl / admission.risk_amount
    assert a.realized_r < 3  # measured at the fill, the same trade showed about +13 R (review R2-F4)


# ---------------------------------------------------------------------------------------- R2-F7 tick grid

def test_r2_f7_off_tick_stop_or_target_is_refused():
    assert T.gate().admit_entry(T.cand(stop='98.505'), None, T.view()).reasons == ('stop_not_on_tick',)
    assert T.gate().admit_entry(T.cand(target='103.333'), None, T.view()).reasons == ('target_not_on_tick',)
    assert T.gate().admit_entry(T.cand(stop='98.5', target='103.33'), None, T.view()).verdict is c.RiskVerdict.ADMIT


# ---------------------------------------------------------------------- R1-F10 pessimistic funding and fees

@pytest.mark.parametrize('rate', ['0.001', '-0.001'])
def test_r1_f10_pessimistic_scales_fees_and_adverse_funding_only(rate):
    funding = [c.FundingObservation(inst_id=INST.inst_id, funding_ms=T0 + 2 * H + 1, available_ms=T0 + 2 * H + 1,
                                    rate=D(rate))]
    rows = [FLAT, FLAT, ('100', '100.5', '99.5', '100.2'), ('100.2', '100.6', '99.9', '100.4')]
    no_collar = k.ExecutionAssumptions(entry_collar_rate=D('0'))
    base = T.run(rows, [T.cand()], funding=funding, g=T.gate(assumptions=no_collar)).trades[0]
    worse = T.run(rows, [T.cand()], funding=funding,
                  g=T.gate(assumptions=dataclasses.replace(no_collar, pessimistic=True))).trades[0]
    per_unit = lambda t, amount: amount / (t.quantity * INST.contract_value)  # noqa: E731
    if D(rate) > 0:   # a long pays: adverse, doubled
        assert per_unit(worse, worse.funding) == 2 * per_unit(base, base.funding) < 0
    else:             # a credit is not scaled
        assert per_unit(worse, worse.funding) == per_unit(base, base.funding) > 0
    assert per_unit(worse, worse.fees) == 2 * per_unit(base, base.fees)


# -------------------------------------------------------------------- R1-F11 fill realism and future invariance

def test_r1_f11_exit_on_a_bar_boundary_belongs_to_either_adjacent_bar():
    bars = T.bars([FLAT, ('100', '100.4', '98.5', '99'), ('97', '97.5', '96.5', '97')])
    record = dict(candidate_id='cand-1', variant=c.Variant.B0, portfolio_id='pf-B0', inst_id=INST.inst_id, side=LONG,
                  decision_ms=T0 + H, entry_ms=T0 + H, exit_ms=T0 + 2 * H, quantity=D('1'),
                  contract_value=INST.contract_value, entry_price=D('100'), exit_price=D('99'),
                  initial_stop_price=D('99'), final_stop_price=D('99'), gross_pnl=D('-1'), fees=D('0'),
                  spread_cost=D('0'), slippage=D('0'), funding=D('0'), funding_events=0, net_pnl=D('-1'),
                  initial_risk=D('1'), gap_excess=D('0'), exit_reason=ExitReason.STOP, partial_exits=0,
                  actions_applied=0)
    intrabar = TradeRecord(**record)                      # stop at 99 inside the bar ending at T0+2H
    at_open = TradeRecord(**{**record, 'exit_price': D('97'), 'gross_pnl': D('-3'), 'net_pnl': D('-3'),
                             'gap_excess': D('2')})       # gap at the open of the bar starting at T0+2H
    outside = TradeRecord(**{**record, 'exit_price': D('98'), 'gross_pnl': D('-2'), 'net_pnl': D('-2'),
                             'gap_excess': D('1')})       # 98 is in neither bar's range
    by_inst = {INST.inst_id: bars}
    assert ac.fill_realism_findings([intrabar, at_open], by_inst) == []
    assert [f['reason'] for f in ac.fill_realism_findings([outside], by_inst)] == ['fill_exit_outside_bar_range']


def test_r1_f11_decisions_before_a_cut_do_not_change_when_the_future_is_perturbed():
    rng = random.Random(20261016)
    insts = [s.instrument(name) for name in ('AAA', 'BBB', 'CCC')]
    walks = {i.inst_id: T.random_walk(rng, 90) for i in insts}
    original = [b for i in insts for b in T.bars(walks[i.inst_id], inst=i, latency=LAT)]
    cut = T0 + 50 * H

    def perturb(bar):
        if bar.open_ms < cut:
            return bar
        f = D('1.03')
        return dataclasses.replace(bar, open=bar.open * f, high=bar.high * f, low=bar.low * f, close=bar.close * f)

    provider = SyntheticCandidateProvider(seed=3, max_holding_ms=12 * H, target_r_multiple=D('2'))
    candidates = []
    for step in range(2, 88, 4):
        decision = T0 + step * H + LAT
        for i in insts:
            close = [b for b in original if b.inst_id == i.inst_id and b.available_ms <= decision][-1].close
            candidates += provider.candidates((T.synthetic_snapshot(
                inst=i, decision_ms=decision, reference_price=close, stop_distance=(close * D('0.01')).quantize(
                    D('0.01')), provenance=s.SYNTHETIC_PROVENANCE, protocol=s.DRAFT_PROTOCOL,
                snapshot_id=f'snap-{i.inst_id}-{step}'),), decision)
    runs = [T.run(None, candidates, bar_list=bars, policy=AdaptivePolicyV1())
            for bars in (original, [perturb(b) for b in original])]
    before = [[(d.decision_id, d.verdict, d.reasons) for d in r.risk_decisions if d.header.clock.decision_ms < cut]
              for r in runs]
    after = [[(d.decision_id, d.verdict, d.reasons) for d in r.risk_decisions if d.header.clock.decision_ms >= cut]
             for r in runs]
    assert before[0] == before[1]
    assert any(d.subject is c.RiskSubject.ACTION and d.header.clock.decision_ms < cut for d in runs[0].risk_decisions)
    assert after[0] != after[1]      # the perturbation is real: decisions after the cut do change


# -------------------------------------------------------------------------- R1-F3 capacity-free label replay

def test_r1_f3_capacity_free_labels_do_not_depend_on_capacity_or_on_other_candidates():
    insts = [s.instrument(name) for name in ('AAA', 'BBB', 'CCC')]
    rows = [FLAT, FLAT, ('100', '100.5', '99.5', '100.2'), ('100.2', '100.6', '99.9', '100.4')] + [FLAT] * 4
    bar_list = [b for i in insts for b in T.bars(rows, inst=i)]
    candidates = [T.cand(cid=f'cand-{i.base.lower()}', inst=i) for i in insts]
    portfolio = T.run(None, candidates, bar_list=bar_list)
    assert len(portfolio.trades) == 2   # 1 % total risk: the third candidate finds no capacity
    gate = lambda: T.gate()  # noqa: E731
    labels = capacity_free_labels(candidates, bar_list, policy=StaticPolicy(), gate_factory=gate)
    assert all(label.traded for label in labels.values()) and len(labels) == 3
    alone = capacity_free_labels(candidates[2:], bar_list, policy=StaticPolicy(), gate_factory=gate)
    assert alone['cand-ccc'] == labels['cand-ccc']
    rows_out = label_mapping(labels)
    assert set(rows_out) == {x.candidate_id for x in candidates}
    assert all(r is not None for r, _ in rows_out.values())
    with pytest.raises(c.ContractError, match='h3_label_variant_not_score_blind'):
        capacity_free_labels(candidates, bar_list, policy=StaticPolicy(), gate_factory=gate, variant=c.Variant.B1)


# ------------------------------------------------------------------------- R2-F9 partial close through the adapter

def test_r2_f9_a_scripted_partial_close_is_translated_by_the_adapter():
    rows = [FLAT, FLAT, ('100', '100.6', '99.6', '100.3'), ('100.3', '100.7', '100', '100.5')]
    bars = tuple(T.bars(rows))
    candidate = T.cand()
    market = fx.MarketStream(bars=bars)

    def rule(st):
        if st.open_quantity == st.initial_quantity:
            return c.ActionKind.PARTIAL_CLOSE, {'close_quantity': str(st.open_quantity // 2)}
        return None
    spec = fx.VariantSpec(
        experiment_id='exp-test', variant=c.Variant.B2, portfolio_id='exp-test-B2', quote_ccy='USDC',
        initial_equity=D('500'), start_ms=T0, end_ms=T0 + 4 * H, limits=c.H3_LIMITS, cost_model=T.COST,
        cost_scenario=CostScenario.BASE, candidate_book_fingerprint='sha256:' + 'b' * 64,
        market_fingerprint=market.fingerprint(), candidate_ids=(candidate.candidate_id,),
        entries=(fx.EntryIntent(candidate, None, 0),), pre_refusals=(), policy=T.Scripted(rule), risk_gate=T.gate())
    simulator = RunVariantSimulator()
    run = simulator.run(spec, market)
    (record,) = run.trades
    raw = simulator.last_result.trades[0]
    assert record.partial_exits == 1 and [e.reason for e in raw.exits] == ['partial_close', 'end_of_data']
    assert record.exit_price == raw.exits[-1].price and record.net_pnl == raw.net_pnl
    assert record.initial_risk == raw.nominal_risk


# -------------------------------------------------------------------------- R2-F3 factorial input checks

def _world():
    market = fx.MarketStream(bars=tuple(V.make_bars()))
    provider = V.StubProvider()
    decisions = V.make_decisions(market)
    return market, fx.build_candidate_book(provider, decisions, start_ms=V.START, end_ms=V.END), provider, decisions


class _LooserGate(V.StubRiskGate):
    policy = s.policy_ref('risk-stub-looser')


def test_r2_f3_the_harness_refuses_a_gate_policy_readiness_or_provenance_that_does_not_match():
    world = _world()
    market, book = world[0], world[1]
    assert V.run(world).risk_policy == V.RISK_REF
    with pytest.raises(fx.FactorialError, match='factorial_gate_risk_policy_mismatch'):
        V.run(world, risk_gate_factory=_LooserGate)
    with pytest.raises(fx.FactorialError, match='factorial_pessimistic_risk_policy_required'):
        fx.run_factorial(manifest=V.manifest(), book=book, market=market, scorer=V.StubScorer(), model=V.MODEL,
                         static_policy=V.StaticPolicy(), adaptive_policy=V.AdaptivePolicy(),
                         risk_gate_factory=V.StubRiskGate, simulator_factory=V.StubSimulator, start_ms=V.START,
                         end_ms=V.END, cost_scenario=CostScenario.PESSIMISTIC)
    common = dict(book=book, market=market, scorer=V.StubScorer(), model=V.MODEL, static_policy=V.StaticPolicy(),
                  adaptive_policy=V.AdaptivePolicy(), risk_gate_factory=V.StubRiskGate,
                  simulator_factory=V.StubSimulator, start_ms=V.START, end_ms=V.END)
    with pytest.raises(fx.FactorialError, match='factorial_scored_variants_not_ready'):
        fx.run_factorial(manifest=V.manifest(), scored_readiness=('model_uncalibrated',), **common)
    real = c.Provenance(source='okx_public', dataset_id='okx-xperp-prospective', dataset_sha256='sha256:' + '1' * 64,
                        label=c.DataLabel.PROSPECTIVE, license='okx-terms')
    frozen = dataclasses.replace(s.DRAFT_PROTOCOL, status=c.ProtocolStatus.FROZEN)
    real_manifest = V.manifest(datasets=(real,), protocol=frozen)
    with pytest.raises(fx.FactorialError, match='factorial_scored_readiness_required'):
        fx.run_factorial(manifest=real_manifest, **common)
    with pytest.raises(fx.FactorialError, match='factorial_candidate_provenance_not_in_manifest'):
        fx.run_factorial(manifest=real_manifest, scored_readiness=(), **common)


# ------------------------------------------------------------------- R1-F1 / R1-F9 attribution and information

def test_r1_f9_minimum_information_also_guards_the_binding_p2_series():
    rng = random.Random(5)
    n, level, b0, b3 = 40, 0.0, [], []
    for _ in range(n):
        level = 0.97 * level + rng.gauss(0, 3)            # a persistent regime shared by B0 and B3
        common = [level / 4 + rng.gauss(0, 0.3) for _ in range(4)]
        b0.append([D(str(round(x, 2))) for x in common])
        b3.append([D(str(round(x + 1.0 + rng.gauss(0, 0.3), 2))) for x in common])
    runs = {v: V.synthetic_run(v, b3 if v is c.Variant.B3 else b0) for v in V.cp.FACTORIAL}
    result = cp.evaluate(runs, V.FAST_PLAN)
    info = result['guards']['minimum_information']
    assert info['effective_blocks'] >= V.FAST_PLAN.guards.min_effective_blocks      # P1 alone would pass
    assert info['effective_blocks_absolute'] < V.FAST_PLAN.guards.min_effective_blocks
    assert not info['passed'] and result['verdict'] == 'INSUFFICIENT_EVIDENCE'


def test_r1_f1_no_hypothesis_is_ever_reported_supported_without_matched_controls():
    assert set(cp.ATTRIBUTION_BLOCKERS) == {'H3-A_scoring', 'H3-B_adaptive', 'H3-C_combination'}
    assert all(cp.ATTRIBUTION_BLOCKERS.values())


def test_r1_f3_capacity_free_rows_join_every_scored_candidate():
    score = c.OpportunityScore(
        header=s.header(), candidate_id='cand-1', snapshot=T.cand().snapshot,
        model=c.ModelRef(model_id='null', model_version='0', model_fingerprint=None), status=c.ScoreStatus.UNESTIMATED,
        verdict=c.ScoreVerdict.NO_TRADE, expected_net_r=None, p_stop=None, tail_loss_r=None, uncertainty=D('1'),
        data_quality=D('1'), utility=None, rank=None, reasons=('model_uncalibrated',))
    (row,) = sc.join_scores_labels([score], {'cand-1': (0.5, False)})
    assert row.realised_r == 0.5 and row.stopped is False
    with pytest.raises(sc.ScoringCheckError, match='scoring_label_missing_for_candidate'):
        sc.join_scores_labels([score], {})


def test_r2_gate_policy_version_records_the_review_changes():
    config = H3RiskConfig(cost_model=T.COST)
    assert config.policy_version == '0.2.0' and H3RiskGate(config).policy.policy_version == '0.2.0'
