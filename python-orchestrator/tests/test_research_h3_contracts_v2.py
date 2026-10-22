"""H3 contracts schema 2 (prototype): V1 compatibility, migrations, identities, controlled comparison, risk.

Synthetic values only; no market data, no exchange, no order, nothing read from disk except the frozen V1 sources
(hashed). Run: python -m pytest --noconftest tests/test_research_h3_contracts_v2.py
"""
from __future__ import annotations

import ast
import dataclasses
import hashlib
import subprocess
import sys
from decimal import Decimal as D
from pathlib import Path

import pytest

from scripts.research_h3 import contracts as c
from scripts.research_h3 import contracts_v2 as v2
from scripts.research_h3 import migrate_v1_v2 as m
from scripts.research_h3 import synthetic as s
from scripts.research_h3.integration import costs as k
from scripts.research_h3.integration.risk_gate import H3RiskConfig, H3RiskGate

PKG = Path(__file__).resolve().parents[1] / 'scripts' / 'research_h3'
H, T0 = s.HOUR_MS, s.T0_MS
DAY = 24 * H


def sha(text: str) -> str:
    return c.sha256_text(text)


# ------------------------------------------------------------------------------------------------- builders

def provenance_v2(dataset_id='synthetic-fixture', label=c.DataLabel.SYNTHETIC, underlyings=('SYN',),
                  start=T0 - 30 * DAY, end=T0 + 30 * DAY, venue='synthetic'):
    synthetic = label is c.DataLabel.SYNTHETIC
    return v2.ProvenanceV2(source='synthetic' if synthetic else 'okx-public', dataset_id=dataset_id,
                           dataset_sha256=None if synthetic else sha(dataset_id), label=label,
                           license='synthetic' if synthetic else 'public-terms', venue=venue,
                           product=c.Product.SYNTHETIC if synthetic else c.Product.XPERP,
                           underlyings=tuple(underlyings), window_start_ms=start, window_end_ms=end)


GEN = v2.GeneratorRef(generator_id='gen-syn', generator_version='0.1.0', hypothesis_id='hyp-synthetic',
                      generator_fingerprint=sha('gen-syn-params'))
CAT = v2.FeatureCatalogueRef(catalogue_id='h3-features', catalogue_version='0.1.0',
                             catalogue_fingerprint=sha('catalogue'))
LABEL = v2.LabelSpecRef(label_id='reference-static-exit', label_version='0.1.0', unit=v2.LabelUnit.GROSS_R,
                        label_fingerprint=sha('label-spec'), population=v2.Population.TECHNICALLY_ELIGIBLE)
#: the capacity-free replay label: exists only where an order filled (independent review CTO-02)
REPLAY_LABEL = v2.LabelSpecRef(label_id='capacity-free-b0', label_version='0.1.0', unit=v2.LabelUnit.GROSS_R,
                               label_fingerprint=sha('replay-label-spec'), population=v2.Population.EXECUTABLE)
NULL_MODEL = v2.ModelRefV2(model_id='h3-null', model_version='0.1.0', model_fingerprint=None,
                           calibration=c.CalibrationStatus.UNCALIBRATED, available_ms=None)
CAL_MODEL = v2.ModelRefV2(model_id='h3-fixture', model_version='0.1.0', model_fingerprint=sha('fixture-model'),
                          calibration=c.CalibrationStatus.CALIBRATED, available_ms=T0 - DAY)


def snapshot_v1():
    return c.FeatureSnapshot(
        header=s.header(side=None), snapshot_id='snap-1', feature_spec_id='h3-features', feature_spec_version='0.1.0',
        features=(c.FeatureValue(name='ret_24h', value=D('0.012'), status=c.FeatureStatus.OK, observed_ms=T0,
                                 available_ms=T0, unit='log_return'),
                  c.FeatureValue(name='spread_bp', value=None, status=c.FeatureStatus.MISSING, observed_ms=T0,
                                 available_ms=T0, unit='bp')),
        data_status=c.DataStatus.PARTIAL)


def candidate_v1(stop='99', target='103', cid='cand-1', side=c.Side.LONG, inst=None, entry='100', hold=DAY,
                 decision_ms=T0):
    return c.OpportunityCandidate(
        header=s.header(side=side, inst=inst, decision_ms=decision_ms), candidate_id=cid, generator_id='gen-syn',
        generator_version='0.1.0', hypothesis_id='hyp-synthetic', snapshot=snapshot_v1().ref(),
        entry_reference_price=D(entry), initial_stop_price=D(stop),
        initial_target_price=None if target is None else D(target), max_holding_ms=hold)


def candidate_v2(**kw):
    return m.candidate_to_v2(candidate_v1(**kw), generator_fingerprint=GEN.generator_fingerprint)


def score_v2(**overrides):
    cand = candidate_v2()
    base = dict(header=cand.header, candidate_id=cand.candidate_id, candidate_fingerprint=cand.fingerprint(),
                snapshot=cand.snapshot, model=NULL_MODEL, status=c.ScoreStatus.UNESTIMATED,
                verdict=c.ScoreVerdict.NO_TRADE, expected_net_r=None, p_stop=None, tail_loss_r=None,
                uncertainty=None, data_quality=D('1'), utility=None, rank=None, reasons=('model_reference_null',))
    base.update(overrides)
    return v2.OpportunityScoreV2(**base)


def estimated(**overrides):
    base = dict(model=CAL_MODEL, status=c.ScoreStatus.ESTIMATED, verdict=c.ScoreVerdict.TRADE_CANDIDATE,
                expected_net_r=D('0.1'), p_stop=D('0.4'), tail_loss_r=D('1.2'), uncertainty=D('0.3'),
                utility=D('0.05'), rank=1, reasons=())
    base.update(overrides)
    return score_v2(**base)


COST_V2 = v2.CostModelV2(maker_fee_rate=D('0.0002'), taker_fee_rate=D('0.0005'), slippage_rate=D('0.0005'),
                         half_spread_rate=D('0.0002'), funding_provision_rate=D('0.0001'),
                         funding_interval_ms=8 * H, spread_model='half-spread-fixed',
                         funding_model='observed-or-zero', pessimistic_multiplier=D('2'))


def risk_policy(pessimistic=False):
    return H3RiskConfig(cost_model=COST_V2.to_v1(),
                        assumptions=m.execution_assumptions(COST_V2, pessimistic=pessimistic)).policy_ref()


CONTROL = v2.RandomControlSpec(control_id='br-global', method=v2.ControlMethod.GLOBAL_BUDGET, strata_keys=(),
                               seeds=(11, 12, 13), budget_source=v2.BudgetSource.B1_ACCEPTANCE)


def manifest(**overrides):
    base = dict(experiment_id='exp-syn', protocol=s.DRAFT_PROTOCOL, statistical_plan_fingerprint=None,
                variants=v2.DESIGN_H3A_CONTROLLED, generator=GEN, catalogue=CAT, scoring_model=CAL_MODEL,
                label_spec=LABEL, target_population=v2.Population.LABELABLE, random_control=CONTROL,
                reference_lottery_seeds=(1, 2), static_policy=s.policy_ref('static'), adaptive_policy=None,
                risk_policy=risk_policy(), pessimistic_risk_policy=risk_policy(True), limits=c.H3_LIMITS,
                cost_model=COST_V2, initial_equity=D('500'), quote_ccy='USDC', datasets=(provenance_v2(),),
                trial_ledger_head=None, code_digest=sha('code'), created_ms=T0)
    base.update(overrides)
    return v2.ExperimentManifestV2(**base)


# ------------------------------------------------------------------------------------------- V1 stays frozen

V1_CONTRACTS_SHA256 = '97559a2bfbb46b8134a1a016e9cdc6bc33c34297a4e19eaca6962c34c00d8cfd'
GOLDEN_V1_SHA256 = 'c0616e1a9fbcd1ae938d33f4ce52a0c75aad942a83c542885a3b56f0d737c82b'
V1_FINGERPRINTS = {
    'header': 'sha256:d5908fdf2b44c66786416a5ca41f12f2d5148478617673b4c4fef3bd6271c43f',
    'provenance': 'sha256:52b025009ad8c27b3c0c87d4a02d420bff2615756e810b0079573cf6cc6e4fef',
    'limits': 'sha256:0b712e4d986688218a92321ea7fee318c1eebcafa10454e8de73090804551060',
}


def test_v1_sources_and_golden_vectors_are_untouched():
    assert hashlib.sha256((PKG / 'contracts.py').read_bytes()).hexdigest() == V1_CONTRACTS_SHA256
    assert hashlib.sha256((PKG / 'scoring' / 'parity' / 'golden_v1.json').read_bytes()).hexdigest() \
        == GOLDEN_V1_SHA256


def test_v1_fingerprints_are_unchanged_after_importing_v2():
    assert s.header().fingerprint() == V1_FINGERPRINTS['header']
    assert s.SYNTHETIC_PROVENANCE.fingerprint() == V1_FINGERPRINTS['provenance']
    assert c.H3_LIMITS.fingerprint() == V1_FINGERPRINTS['limits']
    assert c.SCHEMA_VERSION == 1 and all(t.SCHEMA.endswith('/1') for t in c.CONTRACTS)


def test_v2_schema_ids_are_versioned_unique_and_disjoint_from_v1():
    ids = [t.SCHEMA for t in v2.CONTRACTS_V2]
    assert all(i.startswith('h3.') and i.endswith('/2') for i in ids)
    assert len(set(ids)) == len(ids)
    assert not set(ids) & {t.SCHEMA for t in c.CONTRACTS}


# --------------------------------------------------------------------------------------- serialisation / hashes

BUILDERS = [provenance_v2, candidate_v2, score_v2, estimated, manifest,
            lambda: m.snapshot_to_v2(snapshot_v1(), catalogue_fingerprint=CAT.catalogue_fingerprint),
            lambda: v2.ActivityBudget.from_mapping('budget-1', {'decision_day=0': (10, 3), 'decision_day=1': (8, 0)},
                                                     ('decision_day',)),
            lambda: COST_V2, lambda: CONTROL]


@pytest.mark.parametrize('build', BUILDERS)
def test_round_trip_and_reproducible_fingerprints(build):
    value = build()
    again = type(value).from_json(value.canonical_json())
    assert again == value and again.fingerprint() == value.fingerprint() == build().fingerprint()
    assert value.to_wire()['schema'].endswith('/2')


def test_wire_refuses_floats_unknown_fields_and_cross_version_documents():
    cand = candidate_v2()
    text = cand.canonical_json()
    with pytest.raises(c.ContractError):
        v2.OpportunityCandidateV2.from_json(text.replace('"entry_reference_price":"100"',
                                                         '"entry_reference_price":100.0'))
    doc = cand.to_wire()
    doc['extra'] = 1
    with pytest.raises(c.ContractError, match='h3_wire_fields_mismatch'):
        v2.OpportunityCandidateV2.from_wire(doc)
    with pytest.raises(c.ContractError, match='h3_schema_mismatch'):
        v2.OpportunityCandidateV2.from_wire(candidate_v1().to_wire())
    with pytest.raises(c.ContractError, match='h3_schema_mismatch'):
        c.OpportunityCandidate.from_wire(cand.to_wire())


def test_equal_decimals_share_a_fingerprint_and_identity_fields_change_it():
    assert candidate_v2(stop='99.00').fingerprint() == candidate_v2(stop='99').fingerprint()
    other = dataclasses.replace(candidate_v2(), generator=dataclasses.replace(
        GEN, generator_fingerprint=sha('other-params')))
    assert other.fingerprint() != candidate_v2().fingerprint()
    assert other.to_v1() == candidate_v2().to_v1()   # V1 could not tell the two generators apart


# ---------------------------------------------------------------------------------------- V1 <-> V2 migrations

def test_candidate_snapshot_score_cost_migrations_are_lossless():
    cand1 = candidate_v1()
    cand2 = m.candidate_to_v2(cand1, generator_fingerprint=GEN.generator_fingerprint)
    assert cand2.to_v1() == cand1 and cand2.to_v1().fingerprint() == cand1.fingerprint()
    snap1 = snapshot_v1()
    assert m.snapshot_to_v2(snap1, catalogue_fingerprint=CAT.catalogue_fingerprint).to_v1() == snap1
    score1 = c.OpportunityScore(header=cand1.header, candidate_id='cand-1', snapshot=cand1.snapshot,
                                model=c.ModelRef(model_id='h3-null', model_version='0.1.0', model_fingerprint=None),
                                status=c.ScoreStatus.UNESTIMATED, verdict=c.ScoreVerdict.NO_TRADE,
                                expected_net_r=None, p_stop=None, tail_loss_r=None, uncertainty=None,
                                data_quality=D('1'), utility=None, rank=None, reasons=('model_reference_null',))
    score2 = m.score_to_v2(score1, candidate=cand2, model_manifest=None)
    assert score2.to_v1() == score1 and score2.scores(cand2)
    assumptions = k.ExecutionAssumptions(half_spread_rate=D('0.0002'))
    cost2 = m.cost_model_to_v2(COST_V2.to_v1(), assumptions)
    assert cost2 == COST_V2 and cost2.to_v1() == COST_V2.to_v1()
    prov = m.provenance_to_v2(s.SYNTHETIC_PROVENANCE, venue='synthetic', product=c.Product.SYNTHETIC,
                              underlyings=('SYN',), window_start_ms=T0, window_end_ms=T0 + DAY)
    assert prov.to_v1() == s.SYNTHETIC_PROVENANCE and prov.matches_v1(s.SYNTHETIC_PROVENANCE)
    assert [m.variant_to_v1(m.variant_to_v2(v)) for v in c.Variant] == list(c.Variant)


@pytest.mark.parametrize('call, field', [
    (lambda: m.candidate_to_v2(candidate_v1(), generator_fingerprint=None), 'generator_fingerprint'),
    (lambda: m.snapshot_to_v2(snapshot_v1(), catalogue_fingerprint=None), 'catalogue_fingerprint'),
    (lambda: m.provenance_to_v2(s.SYNTHETIC_PROVENANCE, venue='synthetic', product=c.Product.SYNTHETIC,
                                underlyings=None, window_start_ms=T0, window_end_ms=T0 + 1), 'underlyings'),
    (lambda: m.cost_model_to_v2(COST_V2.to_v1(), None), 'assumptions'),
    (lambda: m.model_ref_to_v2(c.ModelRef(model_id='x', model_version='1', model_fingerprint=sha('x')), None),
     'manifest'),
])
def test_migrations_refuse_missing_information(call, field):
    with pytest.raises(m.MigrationError, match=f'migration_missing.{field}'):
        call()


def test_migrations_refuse_inconsistent_or_lossy_conversions():
    with pytest.raises(m.MigrationError, match='migration_variant_br_has_no_v1'):
        m.variant_to_v1(v2.VariantV2.BR)
    assert m.gate_variant(v2.VariantV2.BR) is c.Variant.B0 and m.gate_variant(v2.VariantV2.B1) is c.Variant.B1
    with pytest.raises(m.MigrationError, match='migration_cost_assumptions_pessimistic'):
        m.cost_model_to_v2(COST_V2.to_v1(), k.ExecutionAssumptions(pessimistic=True))
    with pytest.raises(m.MigrationError, match='migration_cost_source_duplicated'):
        m.execution_assumptions(COST_V2, half_spread_rate=D('0'))
    other = c.OpportunityScore(header=candidate_v1().header, candidate_id='cand-other', snapshot=snapshot_v1().ref(),
                               model=c.ModelRef(model_id='n', model_version='1', model_fingerprint=None),
                               status=c.ScoreStatus.UNESTIMATED, verdict=c.ScoreVerdict.NO_TRADE,
                               expected_net_r=None, p_stop=None, tail_loss_r=None, uncertainty=None,
                               data_quality=D('1'), utility=None, rank=None, reasons=('x',))
    with pytest.raises(m.MigrationError, match='migration_score_candidate_mismatch'):
        m.score_to_v2(other, candidate=candidate_v2(), model_manifest=None)


def v1_model_manifest(calibrated=True):
    return c.ModelManifest(model_id='h3-fixture', model_version='0.1.0', kind=c.ModelKind.LINEAR,
                           feature_spec_id='h3-features', feature_spec_version='0.1.0', feature_names=('ret_24h',),
                           parameters=(c.NamedValue(name='w_ret_24h', value=D('0.5')),),
                           calibration=c.CalibrationStatus.CALIBRATED if calibrated
                           else c.CalibrationStatus.UNCALIBRATED,
                           training_provenance=(s.SYNTHETIC_PROVENANCE,) if calibrated else (),
                           protocol=s.DRAFT_PROTOCOL, created_ms=T0 - DAY, notes='synthetic fixture')


def test_model_manifest_and_model_ref_migrations():
    v1 = v1_model_manifest()
    ref = m.model_ref_to_v2(v1.ref(), v1)
    assert ref.to_v1() == v1.ref() and ref.calibration is c.CalibrationStatus.CALIBRATED
    assert ref.available_ms == v1.created_ms
    with pytest.raises(m.MigrationError, match='migration_manifest_not_the_referenced_model'):
        m.model_ref_to_v2(v1.ref(), v1_model_manifest(calibrated=False))
    kwargs = dict(catalogue_fingerprint=CAT.catalogue_fingerprint, side=None, label_spec=LABEL,
                  training_provenance=(provenance_v2(),), training_start_ms=T0 - 20 * DAY,
                  training_end_ms=T0 - 2 * DAY, standardisation_cutoff_ms=T0 - 2 * DAY)
    upgraded = m.model_manifest_to_v2(v1, **kwargs)
    assert upgraded.ref().calibration is c.CalibrationStatus.CALIBRATED and upgraded.available_ms == v1.created_ms
    with pytest.raises(m.MigrationError, match='migration_missing.label_spec'):
        m.model_manifest_to_v2(v1, **{**kwargs, 'label_spec': None})
    with pytest.raises(m.MigrationError, match='migration_datasets_not_the_v1_datasets'):
        m.model_manifest_to_v2(v1, **{**kwargs, 'training_provenance': (
            provenance_v2('dev', c.DataLabel.DEVELOPMENT),)})
    # independent review CTO-02: an outcome-selected (replay) label never trains or calibrates a model
    with pytest.raises(c.ContractError, match='h3_model_label_population_outcome_selected'):
        m.model_manifest_to_v2(v1, **{**kwargs, 'label_spec': REPLAY_LABEL})


def test_experiment_refuses_an_outcome_selected_label():
    with pytest.raises(c.ContractError, match='h3_experiment_label_population_outcome_selected'):
        manifest(label_spec=REPLAY_LABEL)


def test_v1_experiment_manifest_migrates_to_the_v2_factorial():
    v1_model = v1_model_manifest()
    v1 = c.ExperimentManifest(
        experiment_id='exp-syn', protocol=s.DRAFT_PROTOCOL, variants=tuple(c.Variant),
        candidate_generator_id='gen-syn', candidate_generator_version='0.1.0', hypothesis_id='hyp-synthetic',
        scoring_model=v1_model.ref(), static_policy=s.policy_ref('static'), adaptive_policy=s.policy_ref('adaptive'),
        risk_policy=risk_policy(), limits=c.H3_LIMITS, cost_model=COST_V2.to_v1(), initial_equity=D('500'),
        quote_ccy='USDC', datasets=(s.SYNTHETIC_PROVENANCE,), seed=7, code_digest=sha('code'), created_ms=T0)
    kwargs = dict(generator_fingerprint=GEN.generator_fingerprint, catalogue=CAT, scoring_model_manifest=v1_model,
                  label_spec=LABEL, target_population=v2.Population.LABELABLE,
                  pessimistic_risk_policy=risk_policy(True),
                  cost_assumptions=k.ExecutionAssumptions(half_spread_rate=D('0.0002')),
                  datasets=(provenance_v2(),), statistical_plan_fingerprint=None, trial_ledger_head=None)
    up = m.experiment_to_v2(v1, **kwargs)
    assert up.variants == v2.DESIGN_FACTORIAL and up.reference_lottery_seeds == (7,) and up.random_control is None
    assert up.cost_model.to_v1() == v1.cost_model and up.scoring_model.to_v1() == v1.scoring_model
    with pytest.raises(m.MigrationError, match='migration_missing.pessimistic_risk_policy'):
        m.experiment_to_v2(v1, **{**kwargs, 'pessimistic_risk_policy': None})
    with pytest.raises(c.ContractError, match='h3_experiment_equity_not_h3'):
        m.experiment_to_v2(dataclasses.replace(v1, initial_equity=D('1000')), **kwargs)


# ------------------------------------------------------------------------------------- abstention / missing data

def test_v2_scores_keep_v1_abstention_rules_and_add_model_identity_rules():
    assert score_v2().verdict is c.ScoreVerdict.NO_TRADE
    with pytest.raises(c.ContractError, match='h3_score_no_trade_needs_reason'):
        score_v2(reasons=())
    with pytest.raises(c.ContractError, match='h3_score_estimate_without_model'):
        score_v2(expected_net_r=D('0.1'))
    with pytest.raises(c.ContractError, match='h3_score_invalid_must_abstain'):
        score_v2(status=c.ScoreStatus.INVALID_INPUT, verdict=c.ScoreVerdict.TRADE_CANDIDATE)
    assert estimated().status is c.ScoreStatus.ESTIMATED
    uncalibrated = dataclasses.replace(CAL_MODEL, calibration=c.CalibrationStatus.UNCALIBRATED)
    with pytest.raises(c.ContractError, match='h3_score_estimate_model_uncalibrated'):
        estimated(model=uncalibrated)
    with pytest.raises(c.ContractError, match='h3_score_model_from_future'):
        estimated(model=dataclasses.replace(CAL_MODEL, available_ms=T0 + 1))
    with pytest.raises(c.ContractError, match='h3_model_ref_null_model_invalid'):
        dataclasses.replace(NULL_MODEL, calibration=c.CalibrationStatus.CALIBRATED)
    assert not estimated().scores(candidate_v2(stop='98'))


def test_snapshot_missing_data_and_label_absence():
    snap = m.snapshot_to_v2(snapshot_v1(), catalogue_fingerprint=CAT.catalogue_fingerprint)
    assert snap.data_status is c.DataStatus.PARTIAL
    with pytest.raises(c.ContractError, match='h3_feature_data_status_mismatch'):
        dataclasses.replace(snap, data_status=c.DataStatus.COMPLETE)
    cand = candidate_v2()
    absent = v2.LabelOutcome(candidate_id='cand-1', candidate_fingerprint=cand.fingerprint(), label_spec=LABEL,
                             decision_ms=T0, available_ms=None, status=v2.LabelStatus.ABSENT, value=None,
                             stopped=None, absence_reasons=('fill_beyond_collar',))
    with pytest.raises(c.ContractError, match='h3_label_absent_needs_reason_and_no_value'):
        dataclasses.replace(absent, absence_reasons=())
    with pytest.raises(c.ContractError, match='h3_label_labelled_incomplete'):
        dataclasses.replace(absent, status=v2.LabelStatus.LABELLED, absence_reasons=(), value=D('1'), stopped=False)
    with pytest.raises(c.ContractError, match='h3_label_available_before_outcome'):
        dataclasses.replace(absent, available_ms=T0)


def test_label_replay_records_migrate_with_explicit_exit_time():
    from scripts.research_h3.integration.label_replay import CandidateLabel
    cand = candidate_v2()
    traded = CandidateLabel('cand-1', T0, True, D('-1.1'), D('-1'), True, 'stop', ())
    with pytest.raises(m.MigrationError, match='migration_missing.available_ms'):
        m.label_to_v2(traded, candidate=cand, label_spec=REPLAY_LABEL, available_ms=None)
    label = m.label_to_v2(traded, candidate=cand, label_spec=REPLAY_LABEL, available_ms=T0 + 5 * H)
    assert label.value == D('-1') and label.status is v2.LabelStatus.LABELLED   # gross R: REPLAY_LABEL unit
    refused = CandidateLabel('cand-1', T0, False, None, None, None, None, ('fill_beyond_collar',))
    absent = m.label_to_v2(refused, candidate=cand, label_spec=REPLAY_LABEL, available_ms=None)
    assert absent.absence_reasons == ('fill_beyond_collar',)


def test_replay_labels_are_executable_conditioned_and_never_labelled_when_truncated_or_gappy():
    """Independent review CTO-02: a replay label can only carry an EXECUTABLE spec (which no calibrated model or
    experiment accepts), and a truncated or gappy replay label is ABSENT, never a LABELLED value."""
    from scripts.research_h3.integration.label_replay import CandidateLabel
    cand = candidate_v2()
    traded = CandidateLabel('cand-1', T0, True, D('-1.1'), D('-1'), True, 'stop', ())
    with pytest.raises(m.MigrationError, match='migration_replay_label_is_executable_conditioned'):
        m.label_to_v2(traded, candidate=cand, label_spec=LABEL, available_ms=T0 + 5 * H)
    truncated = CandidateLabel('cand-1', T0, True, D('0.5'), D('0.4'), False, 'end_of_data', (),
                               horizon_covered=False, price_path_gaps=3)
    out = m.label_to_v2(truncated, candidate=cand, label_spec=REPLAY_LABEL, available_ms=T0 + 5 * H)
    assert out.status is v2.LabelStatus.ABSENT and out.value is None
    assert out.absence_reasons == ('label_horizon_not_covered', 'label_price_path_gap')
    gappy = CandidateLabel('cand-1', T0, True, D('0.5'), D('0.4'), False, 'take', (), horizon_covered=True,
                           price_path_gaps=1)
    assert m.label_to_v2(gappy, candidate=cand, label_spec=REPLAY_LABEL,
                         available_ms=T0 + 5 * H).absence_reasons == ('label_price_path_gap',)


# ---------------------------------------------------------------------------------- random control and budget

def test_random_control_spec_rules():
    with pytest.raises(c.ContractError, match='h3_control_strata_method_mismatch'):
        dataclasses.replace(CONTROL, method=v2.ControlMethod.STRATIFIED)
    with pytest.raises(c.ContractError, match='h3_control_strata_method_mismatch'):
        dataclasses.replace(CONTROL, strata_keys=('decision_day',))
    stratified = dataclasses.replace(CONTROL, method=v2.ControlMethod.STRATIFIED,
                                     strata_keys=('decision_day', 'side'))
    assert stratified.strata_keys == ('decision_day', 'side')
    for key in ('net_r', 'score', 'expected_net_r'):
        with pytest.raises(c.ContractError, match='h3_control_stratum_not_decision_time'):
            dataclasses.replace(stratified, strata_keys=(key,))
    for seeds in ((), (2, 1), (1, 1), (-1,)):
        with pytest.raises(c.ContractError):
            dataclasses.replace(CONTROL, seeds=seeds)
    with pytest.raises(c.ContractError, match='h3_enum_invalid'):
        dataclasses.replace(CONTROL, budget_source='outcomes')


def test_activity_budget_mirrors_the_shared_mapping():
    counts = {'decision_day=1|side=long': (8, 0), 'decision_day=0|side=long': (10, 3)}
    budget = v2.ActivityBudget.from_mapping('budget-1', counts, ('decision_day', 'side'))
    assert budget.as_mapping() == counts and (budget.candidate_count, budget.accepted_count) == (18, 3)
    with pytest.raises(c.ContractError, match='h3_budget_count_invalid'):
        v2.ActivityBudget.from_mapping('b', {'*': (2, 3)})
    with pytest.raises(c.ContractError, match='h3_budget_not_from_b1'):
        dataclasses.replace(budget, derived_from_variant=v2.VariantV2.B0)
    with pytest.raises(c.ContractError, match='h3_budget_stratum_key_invalid'):
        v2.ActivityBudget.from_mapping('b', {'decision_day=with space': (1, 0)}, ('decision_day',))
    # integration (H3-A testability): labels follow the harness's keys; keys are decision-time, sorted, unique
    with pytest.raises(c.ContractError, match='h3_budget_stratum_label_not_the_keys'):
        v2.ActivityBudget.from_mapping('b', {'2026-01-01|long': (1, 0)}, ('decision_day', 'side'))
    with pytest.raises(c.ContractError, match='h3_budget_stratum_label_not_the_keys'):
        v2.ActivityBudget.from_mapping('b', {'all': (1, 0)})
    with pytest.raises(c.ContractError, match='h3_budget_stratum_not_decision_time'):
        v2.ActivityBudget.from_mapping('b', {'net_r=1': (1, 0)}, ('net_r',))
    with pytest.raises(c.ContractError, match='h3_budget_strata_keys_not_sorted_unique'):
        v2.ActivityBudget.from_mapping('b', {'side=long|decision_day=0': (1, 0)}, ('side', 'decision_day'))


# ----------------------------------------------------------------------------------------- experiment manifest

def test_manifest_designs_and_h3_policy():
    assert manifest().variants == v2.DESIGN_H3A_CONTROLLED
    factorial = manifest(variants=v2.DESIGN_FACTORIAL, random_control=None,
                         adaptive_policy=s.policy_ref('adaptive'))
    assert v2.VariantV2.BR not in factorial.variants
    full = manifest(variants=v2.DESIGN_FACTORIAL_CONTROLLED, adaptive_policy=s.policy_ref('adaptive'))
    assert len(full.variants) == 5
    cases = [
        (dict(variants=(v2.VariantV2.B1, v2.VariantV2.B0)), 'h3_experiment_design_invalid'),
        (dict(random_control=None), 'h3_experiment_random_control_mismatch'),
        (dict(adaptive_policy=s.policy_ref('adaptive')), 'h3_experiment_adaptive_policy_mismatch'),
        (dict(initial_equity=D('1000')), 'h3_experiment_equity_not_h3'),
        (dict(limits=dataclasses.replace(c.H3_LIMITS, leverage_max=D('2'))), 'h3_experiment_limits_not_h3'),
        (dict(pessimistic_risk_policy=risk_policy()), 'h3_experiment_pessimistic_policy_not_distinct'),
        (dict(reference_lottery_seeds=()), 'h3_experiment_seeds_invalid'),
        (dict(reference_lottery_seeds=(2, 1)), 'h3_experiment_seeds_not_sorted_unique'),
        (dict(label_spec=None), 'h3_experiment_calibrated_model_needs_label_spec'),
        (dict(scoring_model=dataclasses.replace(CAL_MODEL, available_ms=T0 + 1)),
         'h3_experiment_model_after_manifest'),
        (dict(protocol=dataclasses.replace(s.DRAFT_PROTOCOL, status=c.ProtocolStatus.FROZEN)),
         'h3_experiment_frozen_needs_plan_and_ledger'),
        (dict(datasets=(provenance_v2('okx-dev', c.DataLabel.DEVELOPMENT),)),
         'h3_experiment_real_data_needs_frozen_protocol'),
    ]
    for overrides, code in cases:
        with pytest.raises(c.ContractError, match=code):
            manifest(**overrides)


def test_dataset_overlap_is_underlying_by_time_across_venues():
    frozen = dataclasses.replace(s.DRAFT_PROTOCOL, status=c.ProtocolStatus.FROZEN)
    dev = provenance_v2('binance-dev', c.DataLabel.DEVELOPMENT, ('BTC', 'ETH'), T0 - 60 * DAY, T0, venue='binance')
    hold = provenance_v2('okx-holdout', c.DataLabel.HOLDOUT, ('BTC',), T0 - DAY, T0 + 30 * DAY, venue='okx')
    assert dev.overlaps(hold) and hold.overlaps(dev)
    assert not dev.overlaps(dataclasses.replace(hold, underlyings=('SOL',)))
    assert not dev.overlaps(dataclasses.replace(hold, window_start_ms=T0))         # [start, end) windows
    assert not provenance_v2().overlaps(hold)                                       # synthetic never overlaps
    common = dict(protocol=frozen, statistical_plan_fingerprint=sha('plan'), trial_ledger_head=sha('head'))
    with pytest.raises(c.ContractError, match='h3_experiment_dataset_overlap'):
        manifest(datasets=(dev, hold), **common)
    assert manifest(datasets=(dev, dataclasses.replace(hold, window_start_ms=T0)), **common)


def test_model_for_experiment_refuses_training_on_evaluation_data():
    frozen = dataclasses.replace(s.DRAFT_PROTOCOL, status=c.ProtocolStatus.FROZEN)
    dev = provenance_v2('binance-dev', c.DataLabel.DEVELOPMENT, ('BTC',), T0 - 60 * DAY, T0 - 10 * DAY)
    model = v2.ModelManifestV2(
        model_id='h3-m', model_version='1.0.0', kind=c.ModelKind.LINEAR, catalogue=CAT, side=c.Side.LONG,
        feature_names=('ret_24h',), parameters=(), calibration=c.CalibrationStatus.CALIBRATED, label_spec=LABEL,
        training_provenance=(dev,), training_start_ms=T0 - 60 * DAY, training_end_ms=T0 - 10 * DAY,
        standardisation_cutoff_ms=T0 - 10 * DAY, protocol=frozen, created_ms=T0 - 9 * DAY,
        available_ms=T0 - 9 * DAY, notes='')
    validation = provenance_v2('okx-val', c.DataLabel.VALIDATION, ('BTC',), T0 - 5 * DAY, T0 + 30 * DAY)
    common = dict(protocol=frozen, statistical_plan_fingerprint=sha('plan'), trial_ledger_head=sha('head'),
                  scoring_model=model.ref())
    assert v2.check_model_for_experiment(manifest(datasets=(validation,), **common), model) == ()
    overlapping = dataclasses.replace(validation, window_start_ms=T0 - 20 * DAY)
    assert v2.check_model_for_experiment(manifest(datasets=(overlapping,), **common), model) \
        == ('model_training_overlaps_evaluation_data',)
    assert 'model_catalogue_mismatch' in v2.check_model_for_experiment(
        manifest(datasets=(validation,), catalogue=dataclasses.replace(CAT, catalogue_version='0.2.0'), **common),
        model)
    with pytest.raises(c.ContractError, match='h3_model_trained_on_confirmatory_data'):
        dataclasses.replace(model, training_provenance=(validation,), training_start_ms=T0 - 5 * DAY,
                            training_end_ms=T0 - 4 * DAY, standardisation_cutoff_ms=T0 - 4 * DAY,
                            created_ms=T0 - 3 * DAY, available_ms=T0 - 3 * DAY)
    with pytest.raises(c.ContractError, match='h3_model_training_window_invalid'):
        dataclasses.replace(model, standardisation_cutoff_ms=T0 - 9 * DAY)
    with pytest.raises(c.ContractError, match='h3_model_uncalibrated_with_training'):
        dataclasses.replace(model, calibration=c.CalibrationStatus.UNCALIBRATED)


# ----------------------------------------------------------------------------- controlled comparison B0/BR/B1

def runs_for(man, *, scenario=v2.CostScenarioV2.BASE, budget=None, br_seeds=None, book='book', **overrides):
    fp = man.fingerprint()
    policy = man.risk_policy if scenario is v2.CostScenarioV2.BASE else man.pessimistic_risk_policy

    def run(variant, seed=None, budget_fp=None):
        values = dict(experiment_id=man.experiment_id, manifest_fingerprint=fp, variant=variant,
                      portfolio_id=f'pf-{variant.value}-{seed}', cost_scenario=scenario, seed=seed,
                      candidate_book_fingerprint=sha(book), market_fingerprint=sha('market'), risk_policy=policy,
                      activity_budget_fingerprint=budget_fp, run_fingerprint=sha(f'{variant.value}{seed}'))
        values.update(overrides)
        return v2.VariantRunRef(**values)

    out = [run(v2.VariantV2.B0, seed) for seed in man.reference_lottery_seeds]
    out += [run(v2.VariantV2.BR, seed, budget.fingerprint()) for seed in
            (man.random_control.seeds if br_seeds is None else br_seeds)]
    out.append(run(v2.VariantV2.B1))
    return out


def both_scenarios(man, *, budget):
    return runs_for(man, budget=budget) + runs_for(man, budget=budget, scenario=v2.CostScenarioV2.PESSIMISTIC)


B1_RUN_FP = sha('B1None')   # run_fingerprint of runs_for's B1 run


def test_controlled_comparison_is_ready_only_with_every_preregistered_seed():
    man = manifest()
    budget = v2.ActivityBudget.from_mapping('budget-1', {'*': (40, 12)}, source_run_fingerprint=B1_RUN_FP)
    assert v2.comparison_readiness(man, both_scenarios(man, budget=budget), budget) == ()
    # independent review CTO-04: a base-only (or pessimistic-only) run set is not a comparison
    assert 'cost_scenario_missing' in v2.comparison_readiness(man, runs_for(man, budget=budget), budget)
    pess = runs_for(man, budget=budget, scenario=v2.CostScenarioV2.PESSIMISTIC)
    assert 'cost_scenario_missing' in v2.comparison_readiness(man, pess, budget)
    # independent reviews QR-15 / CTO-03: the budget names the B1 run it was derived from
    unbound = v2.ActivityBudget.from_mapping('budget-1', {'*': (40, 12)})
    assert 'activity_budget_not_bound_to_b1_run' in v2.comparison_readiness(
        man, both_scenarios(man, budget=unbound), unbound)
    foreign = v2.ActivityBudget.from_mapping('budget-1', {'*': (40, 12)}, source_run_fingerprint=sha('other-b1'))
    assert 'activity_budget_not_from_this_b1_run' in v2.comparison_readiness(
        man, both_scenarios(man, budget=foreign), foreign)
    # publishing only the best control seed, or adding one, is refused
    assert 'control_seeds_not_exactly_preregistered' in v2.comparison_readiness(
        man, runs_for(man, budget=budget, br_seeds=(12,)), budget)
    assert 'control_seeds_not_exactly_preregistered' in v2.comparison_readiness(
        man, runs_for(man, budget=budget, br_seeds=(11, 12, 13, 14)), budget)
    other = v2.ActivityBudget.from_mapping('budget-2', {'*': (40, 13)})
    assert 'activity_budget_mismatch' in v2.comparison_readiness(man, runs_for(man, budget=budget), other)
    assert 'activity_budget_missing' in v2.comparison_readiness(man, runs_for(man, budget=budget), None)
    mixed = runs_for(man, budget=budget)
    mixed[-1] = dataclasses.replace(mixed[-1], candidate_book_fingerprint=sha('other-book'))
    assert 'candidate_book_not_shared' in v2.comparison_readiness(man, mixed, budget)
    wrong = runs_for(man, budget=budget, scenario=v2.CostScenarioV2.PESSIMISTIC, risk_policy=man.risk_policy)
    assert 'risk_policy_not_anchored' in v2.comparison_readiness(man, wrong, budget)
    assert 'run_not_from_manifest' in v2.comparison_readiness(
        manifest(reference_lottery_seeds=(1, 2, 3)), runs_for(man, budget=budget), budget)
    assert 'variant_set_mismatch' in v2.comparison_readiness(man, runs_for(man, budget=budget)[:-1], budget)
    with pytest.raises(c.ContractError, match='h3_run_seed_mismatch'):
        runs_for(man, budget=budget)[-1].__class__(**{**dataclasses.asdict(runs_for(man, budget=budget)[0]),
                                                     'seed': None, 'risk_policy': man.risk_policy})


def test_br_audit_records_carry_their_own_variant():
    record = v2.DecisionAuditV2(header=s.header(portfolio_id='pf-BR-11'), audit_id='aud-0', sequence=0,
                                experiment_id='exp-syn', variant=v2.VariantV2.BR, event=c.AuditEvent.RISK,
                                subject_schema='h3.risk_decision/1', subject_id='risk-1',
                                subject_fingerprint=sha('x'), previous_audit_fingerprint=None, model=None,
                                policy=None, reasons=())
    assert v2.DecisionAuditV2.from_json(record.canonical_json()) == record
    with pytest.raises(c.ContractError, match='h3_audit_chain_invalid'):
        dataclasses.replace(record, sequence=1)


# ------------------------------------------------------------------------------ H3 risk rules after migration

INSTRUMENTS = {   # synthetic, invented specs covering fine and coarse quantisation
    'fine': (s.instrument('FIN', contract_value='0.01', tick_size='0.01', lot_size='1', min_size='1'), '100'),
    'coarse_lot': (s.instrument('CRS', contract_value='0.1', tick_size='0.1', lot_size='0.1', min_size='0.1'),
                   '3000'),
    'low_price': (s.instrument('LOW', contract_value='10', tick_size='0.0001', lot_size='1', min_size='1',
                               min_notional='5'), '0.5'),
    'big_contract': (s.instrument('BIG', contract_value='0.001', tick_size='0.5', lot_size='1', min_size='1'),
                     '60000'),
}
STOP_FRACTIONS = ('0.003', '0.01', '0.03', '0.08')


def _stop(price: D, fraction: str, tick: D, side) -> D:
    raw = price * (1 - D(fraction)) if side is c.Side.LONG else price * (1 + D(fraction))
    return k.round_stop_away(side, raw, tick)


def _independent_risk(side, entry, stop, units, cost, pessimistic, intervals):
    """Cost-inclusive loss at the stop recomputed from the V2 cost numbers (not from the gate's rates)."""
    scale = cost.pessimistic_multiplier if pessimistic else D('1')
    gross = (entry - stop) * units * (1 if side is c.Side.LONG else -1)
    per_leg = (cost.taker_fee_rate + cost.half_spread_rate + cost.slippage_rate) * scale
    return gross + (entry + stop) * units * per_leg + entry * units * cost.funding_provision_rate * scale * intervals


@pytest.mark.parametrize('pessimistic', [False, True])
@pytest.mark.parametrize('side', [c.Side.LONG, c.Side.SHORT])
def test_v2_entries_respect_h3_limits_after_quantisation_and_realistic_fees(side, pessimistic):
    gate = H3RiskGate(H3RiskConfig(cost_model=COST_V2.to_v1(),
                                   assumptions=m.execution_assumptions(COST_V2, pessimistic=pessimistic)))
    equity, admitted, refused = D('500'), 0, 0
    for name, (inst, price) in INSTRUMENTS.items():
        for fraction in STOP_FRACTIONS:
            entry = D(price)
            stop = _stop(entry, fraction, inst.tick_size, side)
            cand = candidate_v2(cid=f'c-{name}-{fraction}', side=side, inst=inst, entry=price, stop=str(stop),
                                target=None)
            view = c.PortfolioView(portfolio_id='pf-BR-11', variant=m.gate_variant(v2.VariantV2.BR),
                                   quote_ccy='USDC', clock=s.clock(T0), equity=equity, cash=equity,
                                   open_risk=D('0'), open_notional=D('0'), open_position_ids=(), kill_switch=False)
            decision = gate.admit_entry(cand.to_v1(), None, view)
            if decision.verdict is c.RiskVerdict.REJECT:
                refused += 1
                assert decision.reasons
                continue
            admitted += 1
            q, cv = decision.quantity, inst.contract_value
            assert q % inst.lot_size == 0 and q >= inst.min_size
            assert decision.stop_price == stop and c.stop_is_protective(side, decision.price_reference, stop)
            assert decision.risk_amount <= c.H3_LIMITS.per_trade_risk_max * equity       # 0.5 % = 2.5
            assert decision.open_risk_after <= c.H3_LIMITS.total_risk_max * equity       # 1 % = 5
            assert decision.leverage_after <= c.H3_LIMITS.leverage_max                  # 1x
            assert decision.notional_after == q * cv * decision.price_reference <= equity
            intervals = k.provision_intervals(cand.max_holding_ms, COST_V2.funding_interval_ms)
            assert decision.risk_amount == _independent_risk(side, decision.price_reference, stop, q * cv, COST_V2,
                                                             pessimistic, intervals)
            # one more lot would break a cap: the quantity is the largest admissible on the grid
            more = _independent_risk(side, decision.price_reference, stop, (q + inst.lot_size) * cv, COST_V2,
                                     pessimistic, intervals)
            assert more > D('2.5') or (q + inst.lot_size) * cv * decision.price_reference > equity
    assert admitted >= 10 and refused >= 1   # coverage: both outcomes exercised on this synthetic grid


def test_total_risk_cap_binds_across_positions_and_br_is_score_blind():
    gate = H3RiskGate(H3RiskConfig(cost_model=COST_V2.to_v1(), assumptions=m.execution_assumptions(COST_V2)))
    inst = INSTRUMENTS['fine'][0]
    view = c.PortfolioView(portfolio_id='pf-BR-11', variant=m.gate_variant(v2.VariantV2.BR), quote_ccy='USDC',
                           clock=s.clock(T0), equity=D('500'), cash=D('500'), open_risk=D('4'),
                           open_notional=D('200'), open_position_ids=('pos-a',), kill_switch=False)
    cand = candidate_v2(cid='c-total', inst=inst, target=None, stop='97')
    decision = gate.admit_entry(cand.to_v1(), None, view, open_instruments=('OTHER-USD-SYNTH',))
    assert decision.verdict is c.RiskVerdict.ADMIT and 'cap_total_risk' in decision.reasons
    assert decision.risk_amount <= D('1') and decision.open_risk_after <= D('5')
    # BR is score-blind: handing it a score is refused, as for B0
    refused = gate.admit_entry(candidate_v2(cid='c-scored', inst=inst, target=None).to_v1(),
                               estimated().to_v1(), view, open_instruments=())
    assert 'score_not_expected' in refused.reasons
    # a candidate without a protective stop cannot even be built in V2
    with pytest.raises(c.ContractError, match='h3_candidate_stop_not_protective'):
        candidate_v2(stop='101')


# --------------------------------------------------------------------------------- no production dependency

V2_FILES = ('contracts_v2.py', 'trial_ledger.py', 'migrate_v1_v2.py')
ALLOWED_TOP = {'__future__', 'collections', 'dataclasses', 'decimal', 'enum', 'hashlib', 'json', 'os', 'pathlib',
               're', 'typing'}


@pytest.mark.parametrize('name', V2_FILES)
def test_v2_modules_import_only_stdlib_and_research_h3_and_parse_on_311(name):
    tree = ast.parse((PKG / name).read_text(), feature_version=(3, 11))
    for node in ast.walk(tree):
        if isinstance(node, ast.Import):
            assert {a.name.split('.')[0] for a in node.names} <= ALLOWED_TOP
        elif isinstance(node, ast.ImportFrom):
            assert node.level >= 1 or node.module.split('.')[0] in ALLOWED_TOP, node.module


def test_importing_v2_loads_no_runtime_package():
    code = ('import sys; import scripts.research_h3.contracts_v2, scripts.research_h3.trial_ledger, '
            'scripts.research_h3.migrate_v1_v2; '
            'bad = [n for n in sys.modules if n.split(".")[0] in {"app", "temporalio", "fastapi", "httpx", '
            '"requests", "sqlalchemy", "psycopg", "psycopg2", "numpy", "redis"} '
            'or n.startswith("scripts.research_h3.integration")]; print(",".join(bad))')
    out = subprocess.run([sys.executable, '-c', code], cwd=PKG.parents[1], capture_output=True, text=True,
                         check=True, timeout=60)
    assert out.stdout.strip() == ''
