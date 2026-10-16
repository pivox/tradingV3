"""H3-A testability: cross-agent interfaces after integration. Technical properties only, synthetic data only.

The abstention harness (agent B), the V2 contracts (agent D), the labels audit (agent C) and the factorial comparison
must speak the same names: strata keys, the random-control spec and the activity budget (round trip both ways, same
fingerprint), the population names, the label units, the label exit instant, and the H3-A attribution evidence that
``compare.evaluate`` accepts without ever reporting H3-A ``supported``.
Run: python -m pytest --noconftest tests/test_research_h3_a_interfaces.py
"""
from __future__ import annotations

import dataclasses
from decimal import Decimal as D

import pytest

from scripts.research_h3 import contracts as c
from scripts.research_h3 import contracts_v2 as v2
from scripts.research_h3 import migrate_v1_v2 as m
from scripts.research_h3.integration.label_replay import CandidateLabel
from scripts.research_h3.scoring import labels as L
from scripts.research_h3.validation import abstention as ab
from scripts.research_h3.validation import compare as cp
from tests import test_research_h3_contracts_v2 as CV2
from tests import test_research_h3_validation as V


# ------------------------------------------------------------------------------------------------ strata keys

def test_v2_strata_whitelist_is_exactly_the_harness_keys_sorted():
    assert v2.DECISION_TIME_STRATA == ab.STRATA_KEYS == tuple(sorted(ab.STRATA_KEYS))
    assert v2.GLOBAL_STRATUM == ab.GLOBAL_STRATUM


def test_harness_spec_is_canonical_like_the_v2_contract():
    with pytest.raises(ab.AbstentionError, match='abstention_strata_keys_not_sorted'):
        ab.RandomControlSpec('x', 'stratified', ('side', 'decision_day'), (1,))
    with pytest.raises(ab.AbstentionError, match='abstention_seeds_not_sorted'):
        ab.RandomControlSpec('x', 'global_budget', (), (2, 1))


# --------------------------------------------------------------------------------- spec and budget round trip

@pytest.mark.parametrize('method,keys', [('global_budget', ()), ('stratified', ('decision_day',)),
                                         ('stratified', ('decision_day', 'inst_id', 'side'))])
def test_random_control_spec_round_trips_between_harness_and_v2(method, keys):
    spec = ab.RandomControlSpec('br-1', method, keys, (3, 7, 11))
    contract = ab.spec_to_v2(spec)
    assert isinstance(contract, v2.RandomControlSpec)
    assert {k: v for k, v in contract.to_wire().items() if k != 'schema'} == spec.to_wire()
    again = v2.RandomControlSpec.from_json(contract.canonical_json())
    assert again == contract and ab.spec_from_v2(again) == spec
    assert ab.spec_from_v2(again).fingerprint() == spec.fingerprint()


def _items(n_days=3, per_day=4):
    out = []
    for d in range(n_days):
        for i in range(per_day):
            out.append(ab.DecisionItem(f'cand-{d}-{i}', {
                'decision_day': str(d), 'decision_ms': str(1000 * d + i), 'inst_id': f'SYN{i % 2}-USDT',
                'side': 'long' if i % 3 else 'short', 'score_status': 'ESTIMATED'}))
    return out


@pytest.mark.parametrize('keys', [(), ('decision_day',), ('decision_day', 'inst_id', 'side')])
def test_activity_budget_round_trips_with_the_same_fingerprints(keys):
    items = _items()
    accepted = {i.candidate_id for k, i in enumerate(items) if k % 3 == 0}
    budget = ab.activity_budget(items, accepted, keys, 'exp-1:br-1')
    contract = ab.budget_to_v2(budget)
    assert contract.strata_keys == budget.strata_keys and contract.as_mapping() == budget.per_stratum
    assert (contract.candidate_count, contract.accepted_count) == (budget.candidate_count, budget.accepted_count)
    again = v2.ActivityBudget.from_json(contract.canonical_json())
    assert again == contract and again.fingerprint() == contract.fingerprint()
    back = ab.budget_from_v2(again)
    assert back == budget and back.fingerprint == budget.fingerprint


def test_budget_labels_must_be_the_labels_of_its_keys():
    budget = ab.activity_budget(_items(), set(), ('decision_day',), 'b')
    with pytest.raises(c.ContractError, match='h3_budget_stratum_label_not_the_keys'):
        v2.ActivityBudget.from_mapping('b', budget.per_stratum, ('side',))
    with pytest.raises(c.ContractError, match='h3_budget_stratum_label_not_the_keys'):
        v2.ActivityBudget.from_mapping('b', budget.per_stratum, ())
    with pytest.raises(c.ContractError, match='h3_budget_not_from_b1'):
        dataclasses.replace(ab.budget_to_v2(budget), derived_from_variant=v2.VariantV2.BR)


def test_br_run_reference_carries_the_v2_budget_and_readiness_checks_its_strata():
    man = CV2.manifest()   # global control 'br-global', seeds (11, 12, 13)
    spec = ab.spec_from_v2(man.random_control)
    budget = ab.activity_budget(_items(), {'cand-0-0', 'cand-1-1'}, spec.strata_keys, 'exp-syn:br-global')
    contract = ab.budget_to_v2(budget, source_run_fingerprint=CV2.B1_RUN_FP)
    runs = CV2.both_scenarios(man, budget=contract)
    assert v2.comparison_readiness(man, runs, contract) == ()
    by_day = ab.budget_to_v2(ab.activity_budget(_items(), {'cand-0-0'}, ('decision_day',), 'exp-syn:br-day'),
                             source_run_fingerprint=CV2.B1_RUN_FP)
    reasons = v2.comparison_readiness(man, CV2.both_scenarios(man, budget=by_day), by_day)
    assert reasons == ('activity_budget_strata_not_the_control_strata',)


def test_abstention_budget_validates_like_its_v2_mirror():
    """Independent review CTO-08: the harness budget refuses what the V2 budget refuses, and never truncates."""
    with pytest.raises(ab.AbstentionError, match='abstention_budget_stratum_label_not_the_keys'):
        ab.ActivityBudget('b', ('decision_day',), {'inst_id=BTC': (3, 1)})
    with pytest.raises(ab.AbstentionError, match='abstention_strata_keys_not_sorted'):
        ab.ActivityBudget('b', ('side', 'decision_day'), {'side=long|decision_day=0': (3, 1)})
    with pytest.raises(ab.AbstentionError, match='abstention_stratum_key_not_known_at_decision'):
        ab.ActivityBudget('b', ('net_r',), {'net_r=1': (3, 1)})
    for counts in ((3.7, 1.2), (True, 0), (3, False), (3,)):
        with pytest.raises(ab.AbstentionError, match='abstention_budget_counts_invalid'):
            ab.ActivityBudget('b', (), {'*': counts})
    assert ab.ActivityBudget('b', (), {'*': (3, 1)}).per_stratum == {'*': (3, 1)}


# ------------------------------------------------------------------------------------- populations and labels

def test_one_population_enum_and_the_target_is_technically_eligible():
    assert L.Population is v2.Population
    assert L.TARGET_POPULATION is v2.Population.TECHNICALLY_ELIGIBLE
    assert CV2.manifest(target_population=v2.Population.TECHNICALLY_ELIGIBLE)
    with pytest.raises(c.ContractError, match='h3_experiment_target_population_outcome_selected'):
        CV2.manifest(target_population=v2.Population.EXECUTABLE)


@pytest.mark.parametrize('unit', list(v2.LabelUnit))
def test_label_units_agree_between_the_labels_audit_and_the_model_manifest(unit):
    """C's ``training_target`` and D's calibrated ``ModelManifestV2`` accept and refuse the same units."""
    label = v2.LabelSpecRef(label_id='h3.reference_static_exit_label', label_version='1', unit=unit,
                            label_fingerprint=CV2.sha('reference-label'),
                            population=v2.Population.TECHNICALLY_ELIGIBLE)
    try:
        L.training_target('gross_r', unit.value)
        c_accepts = True
    except c.ContractError as exc:
        assert str(exc) == 'h3_label_unit_double_cost_deduction'
        c_accepts = False
    dev = CV2.provenance_v2('dev', c.DataLabel.DEVELOPMENT, ('BTC',), CV2.T0 - 60 * CV2.DAY, CV2.T0 - 10 * CV2.DAY)
    frozen = dataclasses.replace(CV2.s.DRAFT_PROTOCOL, status=c.ProtocolStatus.FROZEN)

    def build():
        return v2.ModelManifestV2(
            model_id='h3-m', model_version='1.0.0', kind=c.ModelKind.LINEAR, catalogue=CV2.CAT, side=c.Side.LONG,
            feature_names=('ret_24h',), parameters=(), calibration=c.CalibrationStatus.CALIBRATED, label_spec=label,
            training_provenance=(dev,), training_start_ms=CV2.T0 - 60 * CV2.DAY,
            training_end_ms=CV2.T0 - 10 * CV2.DAY, standardisation_cutoff_ms=CV2.T0 - 10 * CV2.DAY,
            protocol=frozen, created_ms=CV2.T0 - 9 * CV2.DAY, available_ms=CV2.T0 - 9 * CV2.DAY, notes='')
    if c_accepts:
        assert build().label_spec.unit is unit
    else:
        with pytest.raises(c.ContractError, match='h3_label_unit_double_cost_deduction'):
            build()
    assert set(L.NET_UNITS) == {u.value for u in v2.NET_LABEL_UNITS}


def test_replay_label_exit_instant_becomes_the_v2_availability():
    cand = CV2.candidate_v2()
    t0 = cand.header.clock.decision_ms
    traded = CandidateLabel('cand-1', t0, True, D('-1.1'), D('-1'), True, 'stop', (), horizon_covered=True,
                            net_gross_r=D('-1.05'), exit_ms=t0 + 5 * CV2.H)
    out = m.label_to_v2(traded, candidate=cand, label_spec=CV2.REPLAY_LABEL)
    assert out.available_ms == t0 + 5 * CV2.H and out.value == D('-1')
    assert m.label_to_v2(traded, candidate=cand, label_spec=CV2.REPLAY_LABEL, available_ms=t0 + 5 * CV2.H) == out
    with pytest.raises(m.MigrationError, match='migration_label_exit_time_mismatch'):
        m.label_to_v2(traded, candidate=cand, label_spec=CV2.REPLAY_LABEL, available_ms=t0 + 6 * CV2.H)
    net_gross = dataclasses.replace(CV2.REPLAY_LABEL, unit=v2.LabelUnit.NET_GROSS_R)
    assert m.label_to_v2(traded, candidate=cand, label_spec=net_gross).value == D('-1.05')


# ------------------------------------------------------------------------------- H3-A attribution evidence

def _br_block(run, rejected, plan=None):
    plan = plan or V.FAST_PLAN
    spec = ab.RandomControlSpec('br-day', 'stratified', ('decision_day',), (1, 2, 3))
    return {'control': spec.to_wire(), 'control_fingerprint': spec.fingerprint(), 'seeds_run': [1, 2, 3],
            'block_ms': plan.block_ms,
            'block_excess_test': {'rejected': rejected, 'p_value': 0.01 if rejected else 0.4,
                                  'alpha': plan.alpha_primary, 'procedure': plan.test_procedure},
            'b1': {'net_pnl': float(run.net_pnl), 'trades_executed': len(run.trades)},
            'p_net_pnl_vs_br': 0.25}


@pytest.mark.parametrize('rejected', [True, False])
def test_evaluate_accepts_br_evidence_but_never_reports_h3a_supported(rejected):
    effects = {c.Variant.B1: 3.0, c.Variant.B2: 0.3, c.Variant.B3: 3.0}
    runs = V.factorial_runs(effects)
    pessimistic = V.factorial_runs({k: v - 0.5 if k is c.Variant.B3 else v for k, v in effects.items()},
                                   scenario=V.CostScenario.PESSIMISTIC)
    plain = cp.evaluate(runs, V.FAST_PLAN, pessimistic=pessimistic)
    assert plain['h3a_random_control'] == {'status': 'not_supplied'}
    assert plain['attribution_blockers']['H3-A_scoring'] == ['R1-F1_no_matched_random_abstention_control']
    result = cp.evaluate(runs, V.FAST_PLAN, pessimistic=pessimistic,
                         random_control=_br_block(runs[c.Variant.B1], rejected))
    assert result['verdict'] == plain['verdict'] and result['primary'] == plain['primary']
    assert result['attribution_blockers']['H3-A_scoring'] == list(cp.H3A_BLOCKERS_WITH_RANDOM_CONTROL)
    assert result['attribution_blockers']['H3-B_adaptive'] == plain['attribution_blockers']['H3-B_adaptive']
    assert result['h3a_random_control']['excess_over_br_rejects_null'] is rejected
    assert 'supported' not in result['hypotheses'].values()
    # independent review QR-5: the A2 reading is never gated behind the B3 primary or S1 = B1 - B0
    assert result['hypotheses']['H3-A_scoring'] == (
        'a2_rejects_null_beyond_random_abstention_criterion_not_frozen' if rejected
        else 'a2_does_not_reject_abstention_not_excluded')
    assert result['h3a_random_control']['s1_b1_minus_b0'] == 'descriptive_only_abstention_confounded'
    assert cp.H3A_BLOCKERS_WITH_RANDOM_CONTROL


def test_evaluate_reads_a2_even_when_the_b3_primary_fails():
    runs = V.factorial_runs({c.Variant.B1: 3.0, c.Variant.B2: 0.0, c.Variant.B3: -3.0})
    plain = cp.evaluate(runs, V.FAST_PLAN)
    assert plain['verdict'] != 'CRITERION_MET' and plain['hypotheses']['H3-A_scoring'] == 'not_confirmatory'
    result = cp.evaluate(runs, V.FAST_PLAN, random_control=_br_block(runs[c.Variant.B1], True))
    assert result['hypotheses']['H3-A_scoring'] == 'a2_rejects_null_beyond_random_abstention_criterion_not_frozen'


def test_evaluate_pins_the_br_evidence_to_the_plan_and_the_draft_control():
    """Independent reviews QR-10 / CTO-03: forged or off-plan BR blocks are refused."""
    runs = V.factorial_runs({c.Variant.B3: 1.0})
    b1 = runs[c.Variant.B1]
    cases = []
    forged = _br_block(b1, True)
    forged['control']['seeds'] = [999]
    cases.append((forged, 'compare_random_control_fingerprint_mismatch'))
    best_seed = _br_block(b1, True)
    best_seed['seeds_run'] = [2]
    cases.append((best_seed, 'compare_random_control_seeds_not_all_run_once'))
    glob = ab.RandomControlSpec('br-glob', 'global_budget', (), (1, 2, 3))
    global_block = _br_block(b1, True)
    global_block.update(control=glob.to_wire(), control_fingerprint=glob.fingerprint())
    cases.append((global_block, 'compare_random_control_not_the_draft_primary_control'))
    wrong_alpha = _br_block(b1, True)
    wrong_alpha['block_excess_test']['alpha'] = 0.2
    cases.append((wrong_alpha, 'compare_random_control_not_the_plan'))
    wrong_block = _br_block(b1, True)
    wrong_block['block_ms'] = V.FAST_PLAN.block_ms + 1
    cases.append((wrong_block, 'compare_random_control_not_the_plan'))
    made_up = {'control': {'control_id': 'made-up', 'seeds': [1]}, 'block_excess_test': {'rejected': True},
               'b1': {'net_pnl': float(b1.net_pnl), 'trades_executed': len(b1.trades)}}
    cases.append((made_up, 'compare_random_control_incomplete'))
    for block, code in cases:
        with pytest.raises(cp.CompareError, match=code):
            cp.evaluate(runs, V.FAST_PLAN, random_control=block)


def test_h3a_status_needs_stability_and_power_before_any_non_rejection_reading():
    """Independent review QR-10."""
    both = dict(rank_ic_rejects=True, br_excess_rejects=True, b1_beats_b0=True)
    assert ab.h3a_status(**both, ic_halves_positive=False) == 'h3a_unstable_ranking'
    assert ab.h3a_status(**both, ic_halves_positive=None) == 'h3a_unstable_ranking'
    for a1, a2, b0 in ((True, False, True), (False, True, True), (False, False, True), (False, False, False)):
        assert ab.h3a_status(rank_ic_rejects=a1, br_excess_rejects=a2, b1_beats_b0=b0,
                             ic_halves_positive=True) == 'h3a_non_rejection_underpowered'
    assert ab.h3a_status(rank_ic_rejects=True, br_excess_rejects=False, b1_beats_b0=True, ic_halves_positive=True,
                         powered=True) == 'h3a_ranking_without_selection_value'
    assert set(ab.H3A_STATUSES) >= {'h3a_unstable_ranking', 'h3a_non_rejection_underpowered'}


def test_evaluate_refuses_br_evidence_about_another_b1():
    runs = V.factorial_runs({c.Variant.B3: 1.0})
    block = _br_block(runs[c.Variant.B1], True)
    block['b1']['trades_executed'] += 1
    with pytest.raises(cp.CompareError, match='compare_random_control_not_this_b1'):
        cp.evaluate(runs, V.FAST_PLAN, random_control=block)
    with pytest.raises(cp.CompareError, match='compare_random_control_incomplete'):
        cp.evaluate(runs, V.FAST_PLAN, random_control={'b1': {}})


# ------------------------------------------------------------------------------------- Python 3.11 compatibility

def _pep701_only_fstrings(path):
    """f-strings valid only on Python 3.12+ (PEP 701): a replacement field that reuses the outer quote, or holds a
    backslash or a comment. ``ast.parse(feature_version=(3, 11))`` on 3.12 does not catch them (review CTO-07)."""
    import ast
    src = path.read_text(encoding='utf-8')
    bad = []
    for node in ast.walk(ast.parse(src)):
        if not isinstance(node, ast.JoinedStr):
            continue
        seg = ast.get_source_segment(src, node)
        if seg is None:
            continue
        i = 0
        while i < len(seg) and seg[i] not in '\'"':
            i += 1
        if i == len(seg):   # a nested format spec, not a string literal
            continue
        quote = seg[i:i + 3] if seg[i:i + 3] in ('"""', "'''") else seg[i]
        for value in node.values:
            if isinstance(value, ast.FormattedValue):
                expr = ast.get_source_segment(src, value.value) or ''
                if quote in expr or '\\' in expr or '#' in expr:
                    bad.append(f'{path.name}:{node.lineno}')
    return bad


def test_no_python_312_only_fstrings_in_the_h3_research_code():
    from pathlib import Path
    root = Path(__file__).resolve().parents[1]
    files = sorted((root / 'scripts' / 'research_h3').rglob('*.py')) + sorted(
        (root / 'tests').glob('test_research_h3_*.py'))
    assert files
    bad = [hit for path in files for hit in _pep701_only_fstrings(path)]
    assert bad == []
