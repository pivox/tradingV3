"""H3 shared contracts: canonical wire form, fingerprints and the invariants every component relies on.

Synthetic values only. Run: python -m pytest --noconftest tests/test_research_h3_contracts.py
"""
from __future__ import annotations

from decimal import Decimal as D

import pytest

from scripts.research_h3 import contracts as c
from scripts.research_h3 import synthetic as s


def snapshot(**overrides):
    base = dict(header=s.header(side=None), snapshot_id='snap-1', feature_spec_id='h3-features',
                feature_spec_version='0.1.0',
                features=(c.FeatureValue(name='ret_24h', value=D('0.012'), status=c.FeatureStatus.OK,
                                         observed_ms=s.T0_MS, available_ms=s.T0_MS, unit='log_return'),
                          c.FeatureValue(name='spread_bp', value=None, status=c.FeatureStatus.MISSING,
                                         observed_ms=s.T0_MS, available_ms=s.T0_MS, unit='bp')),
                data_status=c.DataStatus.PARTIAL)
    base.update(overrides)
    return c.FeatureSnapshot(**base)


def candidate(side=c.Side.LONG, stop='99', target='103'):
    return c.OpportunityCandidate(header=s.header(side=side), candidate_id='cand-1', generator_id='gen',
                                  generator_version='0.1.0', hypothesis_id='hyp-synthetic',
                                  snapshot=snapshot().ref(), entry_reference_price=D('100'),
                                  initial_stop_price=D(stop),
                                  initial_target_price=None if target is None else D(target),
                                  max_holding_ms=24 * s.HOUR_MS)


def position(side=c.Side.LONG, **overrides):
    long = side is c.Side.LONG
    base = dict(header=s.header(side=side, portfolio_id='pf-B0', decision_ms=s.T0_MS + s.HOUR_MS),
                position_id='pos-1', candidate_id='cand-1', status=c.PositionStatus.OPEN, entry_price=D('100'),
                entry_ms=s.T0_MS, initial_quantity=D('250'), open_quantity=D('250'),
                initial_stop=D('99') if long else D('101'), current_stop=D('99') if long else D('101'),
                current_target=None, last_price=D('100.5') if long else D('99.5'),
                mfe_price=D('100.8') if long else D('99.2'), mae_price=D('99.6') if long else D('100.4'),
                holding_ms=s.HOUR_MS, max_holding_ms=24 * s.HOUR_MS, volatility=None,
                liquidity=c.LiquidityStatus.NORMAL, funding_paid=D('0'), realized_pnl=D('0'),
                initial_risk_amount=D('2.5'), equity=D('500'), open_risk_total=D('2.5'),
                risk_budget_remaining=D('2.5'), actions_applied=(), policy=s.policy_ref('static'),
                data_status=c.DataStatus.COMPLETE, kill_switch=False)
    base.update(overrides)
    return c.PositionState(**base)


def risk(**overrides):
    base = dict(header=s.header(portfolio_id='pf-B0'), decision_id='risk-1', subject=c.RiskSubject.ENTRY,
                subject_id='cand-1', verdict=c.RiskVerdict.ADMIT, reasons=(), policy=s.policy_ref('h3-risk'),
                limits=c.H3_LIMITS, equity=D('500'), quantity=D('250'), price_reference=D('100'),
                stop_price=D('99'), risk_amount=D('2.5'), open_risk_before=D('0'), open_risk_after=D('2.5'),
                notional_before=D('0'), notional_after=D('250'), leverage_after=D('0.5'))
    base.update(overrides)
    return c.RiskDecision(**base)


# ---------------------------------------------------------------------------------------------------- wire form

def test_decimal_wire_form_is_normalized():
    assert c.wire_decimal(D('1.50')) == '1.5'
    assert c.wire_decimal(D('1E+2')) == '100'
    assert c.wire_decimal(D('-0.000')) == '0'
    assert c.wire_decimal(D('0.00012300')) == '0.000123'


@pytest.mark.parametrize('value', [float('nan'), float('inf'), 'NaN', 'Infinity', True, None, 'abc', object()])
def test_dec_refuses_non_finite_and_non_numbers(value):
    with pytest.raises(c.ContractError):
        c.dec(value)


def test_dec_converts_float_through_repr():
    assert c.dec(0.1) == D('0.1')


@pytest.mark.parametrize('factory', [snapshot, candidate, position, risk])
def test_round_trip_and_fingerprint_are_stable(factory):
    value = factory()
    again = type(value).from_json(value.canonical_json())
    assert again == value
    assert again.fingerprint() == value.fingerprint()
    assert value.fingerprint().startswith('sha256:')


def test_equal_decimals_with_different_scales_share_a_fingerprint():
    assert candidate(stop='99.0').fingerprint() == candidate(stop='99').fingerprint()


def test_wire_refuses_floats_and_unknown_fields():
    text = candidate().canonical_json()
    with pytest.raises(c.ContractError):
        c.OpportunityCandidate.from_json(text.replace('"entry_reference_price":"100"', '"entry_reference_price":100.0'))
    document = candidate().to_wire()
    document['extra'] = 1
    with pytest.raises(c.ContractError):
        c.OpportunityCandidate.from_wire(document)
    document = candidate().to_wire()
    document['schema'] = 'h3.opportunity_candidate/2'
    with pytest.raises(c.ContractError):
        c.OpportunityCandidate.from_wire(document)


# ------------------------------------------------------------------------------------------------ time / lookahead

def test_clock_refuses_lookahead_order():
    with pytest.raises(c.ContractError, match='h3_clock_lookahead'):
        c.Clock(observed_ms=10, available_ms=5, decision_ms=20)
    with pytest.raises(c.ContractError, match='h3_clock_lookahead'):
        c.Clock(observed_ms=10, available_ms=30, decision_ms=20)


def test_feature_available_after_decision_is_refused():
    late = c.FeatureValue(name='ret_24h', value=D('0.01'), status=c.FeatureStatus.OK, observed_ms=s.T0_MS,
                          available_ms=s.T0_MS + 1, unit='log_return')
    with pytest.raises(c.ContractError, match='h3_feature_after_decision'):
        snapshot(features=(late,), data_status=c.DataStatus.COMPLETE)


def test_feature_status_and_data_status_must_agree():
    with pytest.raises(c.ContractError, match='h3_feature_status_value_mismatch'):
        c.FeatureValue(name='x', value=None, status=c.FeatureStatus.OK, observed_ms=0, available_ms=0, unit='u')
    with pytest.raises(c.ContractError, match='h3_feature_data_status_mismatch'):
        snapshot(data_status=c.DataStatus.COMPLETE)
    assert c.derive_data_status(()) is c.DataStatus.MISSING


def test_features_must_be_sorted_and_unique():
    a = c.FeatureValue(name='b', value=D(1), status=c.FeatureStatus.OK, observed_ms=0, available_ms=0, unit='u')
    b = c.FeatureValue(name='a', value=D(1), status=c.FeatureStatus.OK, observed_ms=0, available_ms=0, unit='u')
    with pytest.raises(c.ContractError, match='h3_feature_names_not_sorted_unique'):
        snapshot(features=(a, b), data_status=c.DataStatus.COMPLETE)


# ------------------------------------------------------------------------------------------- candidates / scores

def test_candidate_stop_is_mandatory_and_protective():
    with pytest.raises(c.ContractError, match='h3_candidate_stop_not_protective'):
        candidate(stop='101')
    with pytest.raises(c.ContractError, match='h3_candidate_stop_not_protective'):
        candidate(side=c.Side.SHORT, stop='99', target=None)
    with pytest.raises(c.ContractError, match='h3_candidate_target_wrong_side'):
        candidate(target='98')
    assert candidate(side=c.Side.SHORT, stop='101', target='97').stop_distance == D('1')


def score(**overrides):
    base = dict(header=s.header(), candidate_id='cand-1', snapshot=snapshot().ref(),
                model=c.ModelRef(model_id='null', model_version='0', model_fingerprint=None),
                status=c.ScoreStatus.UNESTIMATED, verdict=c.ScoreVerdict.NO_TRADE, expected_net_r=None, p_stop=None,
                tail_loss_r=None, uncertainty=D('1'), data_quality=D('0.5'), utility=None, rank=None,
                reasons=('model_uncalibrated',))
    base.update(overrides)
    return c.OpportunityScore(**base)


def test_score_without_model_cannot_carry_numbers():
    assert score().status is c.ScoreStatus.UNESTIMATED
    with pytest.raises(c.ContractError, match='h3_score_estimate_without_model'):
        score(expected_net_r=D('0.2'))
    with pytest.raises(c.ContractError, match='h3_score_estimate_incomplete'):
        score(status=c.ScoreStatus.ESTIMATED, expected_net_r=D('0.2'), p_stop=D('0.4'), tail_loss_r=D('1'))


def test_invalid_scores_abstain_with_a_reason():
    with pytest.raises(c.ContractError, match='h3_score_invalid_must_abstain'):
        score(status=c.ScoreStatus.INVALID_INPUT, verdict=c.ScoreVerdict.TRADE_CANDIDATE)
    with pytest.raises(c.ContractError, match='h3_score_no_trade_needs_reason'):
        score(reasons=())


# ---------------------------------------------------------------------------------------------------- positions

@pytest.mark.parametrize('side', [c.Side.LONG, c.Side.SHORT])
def test_position_state_is_valid_both_sides(side):
    assert position(side).ref().fingerprint == position(side).fingerprint()


def test_position_stop_can_never_be_wider_than_initial():
    with pytest.raises(c.ContractError, match='h3_position_stop_widened'):
        position(current_stop=D('98.5'))
    with pytest.raises(c.ContractError, match='h3_position_stop_widened'):
        position(c.Side.SHORT, current_stop=D('101.5'))


def test_position_holding_time_and_quantities_are_consistent():
    with pytest.raises(c.ContractError, match='h3_position_time_invalid'):
        position(holding_ms=1)
    with pytest.raises(c.ContractError, match='h3_position_quantity_invalid'):
        position(open_quantity=D('300'))
    with pytest.raises(c.ContractError, match='h3_position_status_quantity_mismatch'):
        position(status=c.PositionStatus.CLOSED)


def test_adaptive_action_ids_are_derived_and_shapes_enforced():
    state = position()
    h = s.header(portfolio_id='pf-B2', decision_ms=s.T0_MS + s.HOUR_MS)
    pol = s.policy_ref('adaptive')
    a = c.AdaptiveAction.create(header=h, position_id='pos-1', kind=c.ActionKind.TIGHTEN_STOP, state=state.ref(),
                                policy=pol, reasons=('favourable',), new_stop='99.5')
    b = c.AdaptiveAction.create(header=h, position_id='pos-1', kind=c.ActionKind.TIGHTEN_STOP, state=state.ref(),
                                policy=pol, reasons=('favourable',), new_stop=D('99.50'))
    assert a == b and a.idempotency_key == b.idempotency_key
    with pytest.raises(c.ContractError, match='h3_action_shape_invalid'):
        c.AdaptiveAction.create(header=h, position_id='pos-1', kind=c.ActionKind.HOLD, state=state.ref(),
                                policy=pol, reasons=(), new_stop='99.5')
    forged = a.to_wire()
    forged['new_stop'] = '99.9'
    with pytest.raises(c.ContractError, match='h3_action_id_not_derived'):
        c.AdaptiveAction.from_wire(forged)


def test_stop_helpers():
    assert c.stop_tightens(c.Side.LONG, D('99'), D('99.5'))
    assert not c.stop_tightens(c.Side.LONG, D('99'), D('99'))
    assert c.stop_tightens(c.Side.SHORT, D('101'), D('100.5'))
    assert not c.stop_not_wider(c.Side.SHORT, D('101'), D('101.1'))


# --------------------------------------------------------------------------------------------------------- risk

def test_h3_limits_are_the_mission_values():
    assert (c.H3_LIMITS.per_trade_risk_max, c.H3_LIMITS.total_risk_max, c.H3_LIMITS.leverage_max) == (
        D('0.005'), D('0.01'), D('1'))
    with pytest.raises(c.ContractError):
        c.H3Limits(per_trade_risk_max=D('0.005'), total_risk_max=D('0.01'), leverage_max=D('1'),
                   reference_equity=D('500'), stop_required=True, adaptation_may_increase_risk=True)


def test_admit_entry_cannot_break_h3_limits():
    assert risk().verdict is c.RiskVerdict.ADMIT
    with pytest.raises(c.ContractError, match='h3_risk_admit_per_trade_exceeded'):
        risk(risk_amount=D('2.51'))
    with pytest.raises(c.ContractError, match='h3_risk_admit_total_exceeded'):
        risk(open_risk_before=D('2.6'), open_risk_after=D('5.1'))
    with pytest.raises(c.ContractError, match='h3_risk_admit_leverage_exceeded'):
        risk(leverage_after=D('1.01'))
    with pytest.raises(c.ContractError, match='h3_risk_admit_entry_incomplete'):
        risk(stop_price=None, risk_amount=D('0'))
    loose = c.H3Limits(per_trade_risk_max=D('0.01'), total_risk_max=D('0.02'), leverage_max=D('2'),
                       reference_equity=D('500'), stop_required=True, adaptation_may_increase_risk=False)
    with pytest.raises(c.ContractError, match='h3_risk_limits_not_h3'):
        risk(limits=loose)
    assert risk(verdict=c.RiskVerdict.REJECT, reasons=('per_trade_risk_exceeded',), risk_amount=D('9'))


def test_admit_action_may_reduce_but_never_increase_risk():
    action = dict(subject=c.RiskSubject.ACTION, subject_id='act-1', quantity=None, equity=D('400'),
                  risk_amount=D('2.4'), open_risk_before=D('2.5'), open_risk_after=D('2.4'),
                  notional_before=D('250'), notional_after=D('250'), leverage_after=D('0.625'))
    assert risk(**action).verdict is c.RiskVerdict.ADMIT  # equity fell: still admissible because it reduces risk
    with pytest.raises(c.ContractError, match='h3_risk_admit_action_increases_risk'):
        risk(**{**action, 'open_risk_after': D('2.6')})
    with pytest.raises(c.ContractError, match='h3_risk_admit_action_increases_risk'):
        risk(**{**action, 'notional_after': D('251')})


# ----------------------------------------------------------------------------------------------------- manifests

def manifest(**overrides):
    base = dict(model_id='h3-null', model_version='0.1.0', kind=c.ModelKind.REFERENCE_NULL,
                feature_spec_id='h3-features', feature_spec_version='0.1.0', feature_names=('ret_24h',),
                parameters=(), calibration=c.CalibrationStatus.UNCALIBRATED, training_provenance=(),
                protocol=s.DRAFT_PROTOCOL, created_ms=s.T0_MS, notes='reference')
    base.update(overrides)
    return c.ModelManifest(**base)


def test_model_calibration_rules():
    assert manifest().ref().model_fingerprint == manifest().fingerprint()
    with pytest.raises(c.ContractError, match='h3_model_calibration_without_training'):
        manifest(kind=c.ModelKind.LINEAR, calibration=c.CalibrationStatus.CALIBRATED)
    real = c.Provenance(source='okx_public_archive', dataset_id='okx-1m-2024', dataset_sha256='sha256:' + '0' * 64,
                        label=c.DataLabel.DEVELOPMENT, license='okx-terms')
    with pytest.raises(c.ContractError, match='h3_model_real_training_needs_frozen_protocol'):
        manifest(kind=c.ModelKind.LINEAR, calibration=c.CalibrationStatus.CALIBRATED, training_provenance=(real,))
    synthetic = manifest(kind=c.ModelKind.LINEAR, calibration=c.CalibrationStatus.CALIBRATED,
                         training_provenance=(s.SYNTHETIC_PROVENANCE,))
    assert synthetic.calibration is c.CalibrationStatus.CALIBRATED


def experiment(**overrides):
    base = dict(experiment_id='exp-synthetic', protocol=s.DRAFT_PROTOCOL,
                variants=(c.Variant.B0, c.Variant.B1, c.Variant.B2, c.Variant.B3), candidate_generator_id='gen',
                candidate_generator_version='0.1.0', hypothesis_id='hyp-synthetic', scoring_model=manifest().ref(),
                static_policy=s.policy_ref('static'), adaptive_policy=s.policy_ref('adaptive'),
                risk_policy=s.policy_ref('h3-risk'), limits=c.H3_LIMITS,
                cost_model=c.CostModel(maker_fee_rate=D('0.0002'), taker_fee_rate=D('0.0005'),
                                       slippage_rate=D('0.0005'), spread_model='half-spread-fixed',
                                       funding_model='observed-or-zero', pessimistic_multiplier=D('2')),
                initial_equity=D('500'), quote_ccy='USDC', datasets=(s.SYNTHETIC_PROVENANCE,), seed=20261015,
                code_digest='sha256:' + 'a' * 64, created_ms=s.T0_MS)
    base.update(overrides)
    return c.ExperimentManifest(**base)


def test_experiment_rules():
    assert c.ExperimentManifest.from_json(experiment().canonical_json()) == experiment()
    with pytest.raises(c.ContractError, match='h3_experiment_variants_not_factorial'):
        experiment(variants=(c.Variant.B0, c.Variant.B3))
    real = c.Provenance(source='okx_public_archive', dataset_id='okx-1m-2024', dataset_sha256='sha256:' + '0' * 64,
                        label=c.DataLabel.DEVELOPMENT, license='okx-terms')
    with pytest.raises(c.ContractError, match='h3_experiment_real_data_needs_frozen_protocol'):
        experiment(datasets=(real,))
    with pytest.raises(c.ContractError, match='h3_experiment_policies_identical'):
        experiment(adaptive_policy=s.policy_ref('static'))


def test_provenance_labels():
    with pytest.raises(c.ContractError, match='h3_provenance_synthetic_mismatch'):
        c.Provenance(source='synthetic', dataset_id='x', dataset_sha256=None, label=c.DataLabel.DEVELOPMENT,
                     license='synthetic')
    with pytest.raises(c.ContractError, match='h3_provenance_real_data_needs_sha256'):
        c.Provenance(source='okx', dataset_id='x', dataset_sha256=None, label=c.DataLabel.DEVELOPMENT, license='l')


def test_audit_chain_shape():
    first = c.DecisionAudit(header=s.header(portfolio_id='pf-B0'), audit_id='aud-0', sequence=0,
                            experiment_id='exp', variant=c.Variant.B0, event=c.AuditEvent.CANDIDATE,
                            subject_schema='h3.opportunity_candidate/1', subject_id='cand-1',
                            subject_fingerprint=candidate().fingerprint(), previous_audit_fingerprint=None,
                            model=None, policy=None, reasons=())
    with pytest.raises(c.ContractError, match='h3_audit_chain_invalid'):
        c.DecisionAudit(**{**first.__dict__, 'sequence': 1})
    second = c.DecisionAudit(**{**first.__dict__, 'audit_id': 'aud-1', 'sequence': 1,
                                'previous_audit_fingerprint': first.fingerprint()})
    assert second.previous_audit_fingerprint == first.fingerprint()


def test_every_contract_has_a_unique_versioned_schema():
    schemas = [k.SCHEMA for k in c.CONTRACTS]
    assert len(set(schemas)) == len(schemas)
    assert all(x.startswith('h3.') and x.endswith('/1') for x in schemas)
