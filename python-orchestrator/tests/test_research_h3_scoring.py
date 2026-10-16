"""H3-A opportunity scoring engine: arithmetic, features, manifests, engine, ranking, readiness and golden vectors.

Synthetic data only (scripts.research_h3.scoring.fixtures). The "calibrated" manifests are SYNTHETIC TEST FIXTURES
with hand-written coefficients; nothing here is evidence about any market.
Run: python -m pytest --noconftest tests/test_research_h3_scoring.py
"""
from __future__ import annotations

import ast
import dataclasses
import json
import random
import re
from decimal import Decimal as D
from pathlib import Path

import pytest

from scripts.research_h3 import contracts as c
from scripts.research_h3 import synthetic as s
from scripts.research_h3.scoring import arith as ar
from scripts.research_h3.scoring import features as ft
from scripts.research_h3.scoring import fixtures as fx
from scripts.research_h3.scoring import model as md
from scripts.research_h3.scoring import parity
from scripts.research_h3.scoring.engine import ScoringEngine, ScoringError, interpolate, quadratic_form

ROOT = Path(__file__).resolve().parents[1]
PACKAGE = ROOT / 'scripts' / 'research_h3' / 'scoring'
DOC = ROOT.parent / 'docs' / 'handbook' / 'research' / 'H3-OPPORTUNITY-SCORING.md'
CATALOGUE_FINGERPRINT = 'sha256:806891dc2ac8cafed3ce6670a92e8f563569bf99e8a425f54a5d948d124d44b1'
H = s.HOUR_MS
ENGINE = ScoringEngine(fx.COST_MODEL)
MODEL = fx.synthetic_fixture_manifest()


def snap(base='AAA', **kwargs):
    return fx.synthetic_snapshot(base, **kwargs)


def cand(snapshot, side=c.Side.LONG, **kwargs):
    return fx.synthetic_candidate(snapshot, side=side, **kwargs)


def score(base='AAA', side=c.Side.LONG, model=MODEL, snapshot=None, **kwargs):
    snapshot = snapshot or snap(base)
    return ENGINE.score(cand(snapshot, side, **kwargs), snapshot, model)


def feature(snapshot, name):
    return snapshot.value(name)


def bars_of(base, closes, *, start_ms=s.T0_MS, latency_ms=fx.LATENCY_MS, volume='1000'):
    out = []
    for k, close in enumerate(closes):
        close = D(str(close))
        open_ = D(str(closes[k - 1])) if k else close
        open_ms = start_ms + k * H
        out.append(c.Bar(inst_id=fx.inst_id(base), interval_ms=H, open_ms=open_ms, close_ms=open_ms + H,
                         available_ms=open_ms + H + latency_ms, is_closed=True, open=open_, high=max(open_, close),
                         low=min(open_, close), close=close, volume_quote=D(volume)))
    return tuple(out)


# ------------------------------------------------------------------------------------------------- arithmetic

def test_arithmetic_truncates_toward_zero_like_bcmath():
    assert ar.div(1, 3) == D('0.333333333333333333')
    assert ar.div(-1, 3) == D('-0.333333333333333333')
    assert ar.div(2, 3) == D('0.666666666666666666')           # truncated, not rounded
    assert ar.mul(D('0.0000000001'), D('0.0000000001')) == 0     # 1e-20 truncates to 0, never "-0"
    assert str(ar.mul(D('-0.0000000001'), D('0.0000000001'))) == '0E-18'
    assert ar.q('1.0000000000000000019') == D('1.000000000000000001')
    assert ar.median([3, 1, 2, 4]) == D('2.5') and ar.median([5, 1, 3]) == 3


def test_square_root_is_the_exact_floor():
    assert ar.sqrt(2) == D('1.414213562373095048')               # floor of 1.41421356237309504880...
    assert ar.sqrt(D('0.0001')) == D('0.01')
    assert ar.sqrt(0) == 0
    with pytest.raises(ar.ArithmeticDomainError, match='arith_sqrt_negative'):
        ar.sqrt(-1)


@pytest.mark.parametrize('value', [float('nan'), float('inf'), 'NaN', '-Infinity', None, True, D('1e30')])
def test_arithmetic_refuses_non_finite_and_out_of_range(value):
    with pytest.raises(ar.ArithmeticDomainError):
        ar.q(value)
    with pytest.raises(ar.ArithmeticDomainError, match='arith_division_by_zero'):
        ar.div(1, D('0.0000000000000000001'))                      # truncates to 0 first


# ------------------------------------------------------------------------------------------------- catalogue

def test_catalogue_is_frozen_documented_and_limited():
    assert ft.CATALOGUE.fingerprint() == CATALOGUE_FINGERPRINT, 'catalogue changed: bump spec_version'
    assert ft.CATALOGUE.snapshot_names == tuple(sorted(ft.CATALOGUE.snapshot_names))
    for spec in ft.CATALOGUE.specs:
        assert c.check_id(spec.name) and c.check_id(spec.unit)
        assert all([spec.formula, spec.availability, spec.provenance, spec.rationale, spec.leakage_risks,
                    spec.correlation_notes])
    predictive = ft.CATALOGUE.names_with(roles=(ft.Role.PREDICTIVE,))
    assert len(predictive) == 7
    assert 'portfolio_corr_lb' not in predictive and 'data_age_ms' not in predictive


def test_doc_lists_every_feature_and_the_catalogue_fingerprint():
    if not DOC.exists():
        pytest.skip('documentation not in this checkout')
    text = DOC.read_text(encoding='utf-8')
    assert CATALOGUE_FINGERPRINT in text
    for name in ft.CATALOGUE.names:
        assert f'`{name}`' in text, name


# --------------------------------------------------------------------------------------------------- features

def test_snapshot_is_complete_and_respects_the_time_rule():
    snapshot = snap()
    assert snapshot.data_status is c.DataStatus.COMPLETE
    assert snapshot.header.side is None and snapshot.header.portfolio_id is None
    clock = snapshot.header.clock
    for f in snapshot.features:
        assert f.observed_ms <= f.available_ms <= clock.decision_ms
        assert f.observed_ms <= clock.observed_ms
    assert feature(snapshot, 'rvol_lb').observed_ms == s.T0_MS + fx.N_BARS * H       # last closed bar end
    assert feature(snapshot, 'data_age_ms').value == 5000
    assert c.FeatureSnapshot.from_json(snapshot.canonical_json()) == snapshot


def test_feature_values_match_hand_computations():
    closes = ['100'] + ['101'] * 24   # one 1 % move then flat
    universe = {fx.inst_id(b): bars_of(b, [D(x) * (k + 1) for x in closes]) for k, b in enumerate(fx.BASES)}
    raws = ft.instrument_features(fx.inst_id('AAA'), universe[fx.inst_id('AAA')], fx.T0_MS + 25 * H + 5000)
    assert raws['efficiency_lb'].value == 1                                # a straight path
    assert raws['rvol_lb'].value == ar.sqrt(ar.div(D('0.0001'), 24))      # RMS of one 1 % return over 24
    xs = ft.universe_features(fx.inst_id('CCC'), universe, fx.T0_MS + 25 * H + 5000)
    assert xs['mkt_ret_lb'].value == D('0.01') and xs['xs_rel_ret_lb'].value == 0
    assert xs['xs_dispersion_lb'].value == 0
    assert xs['liq_turnover_pct'].value == D('0.5')                      # all turnovers equal
    quote = c.Quote(inst_id=fx.inst_id('AAA'), observed_ms=10, available_ms=20, bid=D('99.99'), ask=D('100.01'),
                    bid_size=D('1'), ask_size=D('1'))
    assert ft.quote_features(fx.inst_id('AAA'), [quote], 30)['spread_bp'].value == 2
    funding = [c.FundingObservation(inst_id=fx.inst_id('AAA'), funding_ms=t, available_ms=t + 1, rate=D('0.0008'))
               for t in (0, 8 * H)]
    assert ft.funding_features(fx.inst_id('AAA'), funding, 8 * H + 1)['funding_rate_hourly'].value == D('0.0001')


def test_input_order_never_changes_a_feature():
    universe = fx.synthetic_universe()
    rng = random.Random(7)
    shuffled = {}
    for key in rng.sample(list(universe), len(universe)):
        bars = list(universe[key])
        rng.shuffle(bars)
        shuffled[key] = tuple(bars)
    quotes = list(fx.synthetic_quotes('AAA', 0)) + list(fx.synthetic_quotes('AAA', 0, observed_ms=fx.DECISION_MS - 9000))
    funding = list(fx.synthetic_funding('AAA', 0))
    base = snap(quotes=quotes, funding=funding)
    again = snap(universe=shuffled, quotes=quotes[::-1], funding=funding[::-1])
    assert again == base and again.fingerprint() == base.fingerprint()


def test_unclosed_and_late_bars_never_change_a_feature():
    base = snap()
    universe = {k: list(v) for k, v in fx.synthetic_universe().items()}
    aaa = fx.inst_id('AAA')
    universe[aaa].append(fx.forming_bar('AAA', close='5000'))                     # still forming
    late = dataclasses.replace(universe[aaa][-2], available_ms=fx.DECISION_MS + 1, close=D('1'), low=D('1'),
                               open=D('1'), high=D('1'))
    universe[aaa][-2] = late                                                       # last bar not yet received
    late_snapshot = snap(universe=universe)
    assert feature(late_snapshot, 'rvol_lb').value != feature(base, 'rvol_lb').value  # the window moved back...
    assert feature(late_snapshot, 'rvol_lb').observed_ms == s.T0_MS + (fx.N_BARS - 1) * H  # ...one closed bar
    universe = {k: list(v) for k, v in fx.synthetic_universe().items()}
    universe[aaa].append(fx.forming_bar('AAA', close='5000'))
    future = c.Bar(inst_id=aaa, interval_ms=H, open_ms=s.T0_MS + 31 * H, close_ms=s.T0_MS + 32 * H,
                   available_ms=s.T0_MS + 32 * H + 1, is_closed=True, open=D('1'), high=D('1'), low=D('1'),
                   close=D('1'), volume_quote=D('1'))
    universe[aaa].append(future)
    assert snap(universe=universe) == base


def test_future_quotes_and_funding_are_ignored():
    base = snap()
    later_quote = c.Quote(inst_id=fx.inst_id('AAA'), observed_ms=fx.DECISION_MS - 1, available_ms=fx.DECISION_MS + 1,
                          bid=D('1'), ask=D('2'), bid_size=D('1'), ask_size=D('1'))
    quotes = fx.synthetic_quotes('AAA', 0) + (later_quote,)
    funding = fx.synthetic_funding('AAA', 0, until_ms=fx.DECISION_MS + 24 * H)
    assert snap(quotes=quotes, funding=funding) == base


def test_bar_received_exactly_at_decision_is_usable():
    universe = fx.synthetic_universe()
    last = universe[fx.inst_id('AAA')][-1]
    at = ft.instrument_features(fx.inst_id('AAA'), universe[fx.inst_id('AAA')], last.available_ms)
    before = ft.instrument_features(fx.inst_id('AAA'), universe[fx.inst_id('AAA')], last.available_ms - 1)
    assert at['rvol_lb'].observed_ms == last.close_ms
    assert before['rvol_lb'].observed_ms == last.close_ms - H


def test_gaps_conflicts_staleness_and_zero_denominators():
    aaa = fx.inst_id('AAA')
    bars = list(fx.synthetic_universe()[aaa])
    gap = bars[:20] + bars[21:]
    assert ft.closed_window(aaa, gap, fx.DECISION_MS, H, 25)[1] == 'gap'
    assert ft.instrument_features(aaa, gap, fx.DECISION_MS)['rvol_lb'].status is c.FeatureStatus.MISSING
    conflict = bars + [dataclasses.replace(bars[-1], volume_quote=D('1'))]
    assert ft.closed_window(aaa, conflict, fx.DECISION_MS, H, 25)[1] == 'conflict'
    assert ft.closed_window(aaa, bars + [bars[-1]], fx.DECISION_MS, H, 25)[1] == 'ok'   # identical duplicate
    stale = ft.instrument_features(aaa, bars, fx.DECISION_MS + 3 * H)
    assert stale['rvol_lb'].status is c.FeatureStatus.STALE and stale['rvol_lb'].value is None
    flat = bars_of('AAA', ['100'] * 25)
    raws = ft.instrument_features(aaa, flat, s.T0_MS + 25 * H + 5000)
    assert raws['efficiency_lb'].status is c.FeatureStatus.NON_FINITE
    assert raws['rvol_lb'].value == 0
    with pytest.raises(ft.FeatureInputError, match='feature_bars_instrument_mismatch'):
        ft.instrument_features('OTHER', bars, fx.DECISION_MS)
    with pytest.raises(ft.FeatureInputError, match='feature_target_not_in_universe'):
        ft.universe_features('OTHER', fx.synthetic_universe(), fx.DECISION_MS)


def test_cross_section_needs_the_minimum_aligned_universe():
    universe = fx.synthetic_universe()
    small = {k: v for k, v in universe.items() if k in {fx.inst_id(b) for b in fx.BASES[:4]}}
    snapshot = snap(universe=small)
    assert snapshot.data_status is c.DataStatus.PARTIAL
    for name in ft.UNIVERSE_FEATURES:
        assert feature(snapshot, name).status is c.FeatureStatus.MISSING
    lagging = dict(universe)
    lagging[fx.inst_id('FFF')] = universe[fx.inst_id('FFF')][:-1]     # one bar behind: excluded, not mixed in
    xs = ft.universe_features(fx.inst_id('AAA'), lagging, fx.DECISION_MS)
    assert xs['mkt_ret_lb'].status is c.FeatureStatus.OK
    assert xs['liq_turnover_pct'].value == 0                          # AAA is the least liquid of 5


def test_stale_and_missing_quotes_and_funding():
    stale = snap(quotes=fx.synthetic_quotes('AAA', 0, observed_ms=fx.DECISION_MS - 61_000))
    assert feature(stale, 'spread_bp').status is c.FeatureStatus.STALE
    assert feature(snap(quotes=()), 'spread_bp').status is c.FeatureStatus.MISSING
    one = fx.synthetic_funding('AAA', 0)[:1]
    assert feature(snap(funding=one), 'funding_rate_hourly').status is c.FeatureStatus.MISSING


def test_portfolio_correlation_is_side_adjusted_and_risk_gate_only():
    universe = fx.synthetic_universe()
    aaa, bbb = fx.inst_id('AAA'), fx.inst_id('BBB')
    kw = dict(inst_id=aaa, side=c.Side.LONG, inst_bars=universe[aaa], decision_ms=fx.DECISION_MS)
    assert ft.portfolio_correlation(held={}, **kw).value == 0
    assert ft.portfolio_correlation(held={aaa: (c.Side.LONG, universe[aaa])}, **kw).value == 1
    assert ft.portfolio_correlation(held={aaa: (c.Side.SHORT, universe[aaa])}, **kw).value == -1
    mixed = ft.portfolio_correlation(held={bbb: (c.Side.LONG, universe[bbb])}, **kw).value
    assert -1 <= mixed <= 1
    missing = ft.portfolio_correlation(held={bbb: (c.Side.LONG, universe[bbb][:10])}, **kw)
    assert missing.status is c.FeatureStatus.MISSING
    reasons = md.validate_manifest(c.ModelManifest(**{
        **MODEL.__dict__, 'feature_names': MODEL.feature_names + ('portfolio_corr_lb',)}))
    assert 'model_feature_not_predictive:portfolio_corr_lb' in reasons


def test_correlated_features_are_pruned_without_labels():
    x = [D(k) for k in range(10)]
    columns = {'rvol_lb': x, 'mkt_ret_lb': [v * 2 for v in x],              # a monotone copy of rvol_lb
               'efficiency_lb': [D((k * 7) % 10) for k in range(10)]}
    result = ft.prune_correlated(columns, D('0.8'))
    assert result.kept == ('mkt_ret_lb', 'efficiency_lb')                    # catalogue order decides
    assert result.dropped == (('rvol_lb', 'mkt_ret_lb', D('1')),)
    assert ft.average_ranks([D('3'), D('1'), D('3')]) == [D('2.5'), D('1'), D('2.5')]
    with pytest.raises(ft.FeatureInputError, match='feature_prune_unknown_feature'):
        ft.prune_correlated({'label': x}, D('0.8'))
    with pytest.raises(TypeError):
        ft.prune_correlated(columns)  # type: ignore[call-arg]  # no default bound


def test_derived_features_costs_and_funding_rule():
    snapshot = snap('FFF')        # funding positive: longs pay, shorts receive
    long_c, short_c = cand(snapshot, c.Side.LONG), cand(snapshot, c.Side.SHORT)
    long_d = ft.derived_features(long_c, snapshot, fx.COST_MODEL)
    short_d = ft.derived_features(short_c, snapshot, fx.COST_MODEL)
    stop = ft.stop_fraction(long_c)
    spread = feature(snapshot, 'spread_bp').value
    expected_cost = ar.div(ar.add(ar.add(D('0.001'), ar.div(spread, 10000)), D('0.001')), stop)
    assert long_d['cost_r'].value == expected_cost
    assert long_d['funding_cost_r'].value > 0
    assert short_d['funding_cost_r'].value == 0                      # a credit is ignored (DRAFT rule)
    assert long_d['stop_vol_units'].value == ar.div(stop, feature(snapshot, 'rvol_lb').value)
    spot = snapshot.__class__(**{**snapshot.__dict__, 'header': dataclasses.replace(
        snapshot.header, instrument=dataclasses.replace(snapshot.header.instrument, product=c.Product.SPOT))})
    spot_c = cand(spot)
    assert ft.derived_features(spot_c, spot, fx.COST_MODEL)['funding_cost_r'].value == 0


# --------------------------------------------------------------------------------------------------- manifests

def test_manifests_round_trip_and_validate():
    for manifest in (MODEL, fx.synthetic_fixture_manifest(kind=c.ModelKind.RIDGE, cinv=True),
                     md.reference_null_manifest(protocol=s.DRAFT_PROTOCOL, created_ms=s.T0_MS)):
        again = c.ModelManifest.from_json(manifest.canonical_json())
        assert again == manifest and again.fingerprint() == manifest.fingerprint()
    assert md.validate_manifest(MODEL) == ()
    decoded, _ = md.decode_manifest(MODEL)
    assert decoded.feature_names == fx.FIXTURE_FEATURES and decoded.ridge_alpha is None
    assert md.validate_manifest(None) == ('model_absent',)
    assert md.validate_manifest(md.reference_null_manifest(protocol=s.DRAFT_PROTOCOL, created_ms=0)) == (
        'model_reference_null',)


@pytest.mark.parametrize('overrides, expected', [
    ({'std.rvol_lb.scale': '0'}, 'model_parameter_invalid:std.rvol_lb.scale'),
    ({'std.rvol_lb.scale': '-1'}, 'model_parameter_invalid:std.rvol_lb.scale'),
    ({'head.gross_r.w.rvol_lb': None}, 'model_parameter_missing:head.gross_r.w.rvol_lb'),
    ({'head.gross_r.w.other': '1'}, 'model_parameter_unexpected:head.gross_r.w.other'),
    ({'head.stop.calib.01.x': '-3'}, 'model_calibration_table_invalid'),
    ({'head.stop.calib.02.p': '0.1'}, 'model_calibration_table_invalid'),
    ({'head.stop.calib.02.p': '1.5'}, 'model_parameter_invalid:head.stop.calib.02.p'),
    ({'unc.n_train': '1'}, 'model_parameter_invalid:unc.n_train'),
    ({'unc.n_train': '10.5'}, 'model_parameter_invalid:unc.n_train'),
    ({'policy.uncertainty_max': '0'}, 'model_parameter_invalid:policy.uncertainty_max'),
    ({'policy.impute_missing': '2'}, 'model_parameter_invalid:policy.impute_missing'),
    ({'policy.lambda_tail': '-0.1'}, 'model_parameter_invalid:policy.lambda_tail'),
    ({'policy.gamma_uncertainty': None}, 'model_parameter_missing:policy.gamma_uncertainty'),
    ({'unc.residual_sd_r': '0.0000000000000000001'}, 'model_parameter_invalid:unc.residual_sd_r'),
    ({'train.ridge_alpha': '1'}, 'model_parameter_unexpected:train.ridge_alpha'),
])
def test_invalid_parameters_are_named(overrides, expected):
    assert expected in md.validate_manifest(fx.synthetic_fixture_manifest(overrides=overrides))


def test_manifest_must_match_the_catalogue():
    other = c.ModelManifest(**{**MODEL.__dict__, 'feature_spec_version': '0.2.0'})
    assert 'model_feature_spec_mismatch' in md.validate_manifest(other)
    reordered = c.ModelManifest(**{**MODEL.__dict__, 'feature_names': tuple(reversed(MODEL.feature_names))})
    assert 'model_feature_order_invalid' in md.validate_manifest(reordered)
    unknown = c.ModelManifest(**{**MODEL.__dict__, 'feature_names': ('Ret_24H',)})
    assert 'model_feature_unknown:ret_24h' in md.validate_manifest(unknown)
    cost = c.ModelManifest(**{**MODEL.__dict__, 'feature_names': ('cost_r',)})
    assert 'model_feature_not_predictive:cost_r' in md.validate_manifest(cost)
    ridge = fx.synthetic_fixture_manifest(kind=c.ModelKind.RIDGE, overrides={'train.ridge_alpha': None})
    assert 'model_parameter_missing:train.ridge_alpha' in md.validate_manifest(ridge)
    nonlinear = c.ModelManifest(**{**MODEL.__dict__, 'kind': c.ModelKind.SIMPLE_NONLINEAR})
    assert md.validate_manifest(nonlinear) == ('model_kind_not_supported',)
    cinv = fx.synthetic_fixture_manifest(cinv=True, overrides={'unc.cinv.rvol_lb.rvol_lb': '0'})
    assert 'model_parameter_invalid:unc.cinv.rvol_lb.rvol_lb' in md.validate_manifest(cinv)
    partial = fx.synthetic_fixture_manifest(cinv=True, overrides={'unc.cinv.mkt_ret_lb.rvol_lb': None})
    assert 'model_parameter_missing:unc.cinv.mkt_ret_lb.rvol_lb' in md.validate_manifest(partial)


def test_policy_coefficients_have_no_default():
    with pytest.raises(TypeError):
        md.ScoringPolicy(lambda_tail=D('0.1'))  # type: ignore[call-arg]


# ------------------------------------------------------------------------------------------------- the engine

def test_identical_inputs_give_identical_scores():
    first = score('FFF')
    second = ScoringEngine(fx.COST_MODEL).score(cand(snap('FFF')), snap('FFF'), fx.synthetic_fixture_manifest())
    assert first == second and first.canonical_json() == second.canonical_json()
    assert c.OpportunityScore.from_json(first.canonical_json()) == first


def test_estimated_score_shape():
    result = score('FFF')
    assert result.status is c.ScoreStatus.ESTIMATED and result.verdict is c.ScoreVerdict.TRADE_CANDIDATE
    assert result.model == MODEL.ref() and result.snapshot == snap('FFF').ref() and result.rank is None
    assert 0 <= result.p_stop <= 1 and 0 < result.uncertainty <= 1 and result.data_quality == 1
    assert result.tail_loss_r > 0 and result.reasons == ()
    trace = ENGINE.evaluate(cand(snap('FFF')), snap('FFF'), MODEL).values
    lam, gamma = fx.FIXTURE_POLICY.lambda_tail, fx.FIXTURE_POLICY.gamma_uncertainty
    assert result.utility == ar.sub(ar.sub(result.expected_net_r, ar.mul(lam, result.tail_loss_r)),
                                    ar.mul(gamma, result.uncertainty))
    assert D(trace['expected_net_r']) == ar.sub(ar.sub(D(trace['gross_r']), D(trace['cost_r'])),
                                                D(trace['funding_cost_r']))
    se = D(trace['se'])
    assert result.uncertainty == ar.div(se, ar.add(se, abs(result.expected_net_r)))


def test_directional_features_are_oriented_by_side():
    trace_long = ENGINE.evaluate(cand(snap('FFF')), snap('FFF'), MODEL).values
    trace_short = ENGINE.evaluate(cand(snap('FFF'), c.Side.SHORT), snap('FFF'), MODEL).values
    for name in ('mkt_ret_lb', 'xs_rel_ret_lb'):
        assert D(trace_long['z.' + name]) == -D(trace_short['z.' + name])
    for name in ('rvol_lb', 'efficiency_lb', 'liq_turnover_pct'):
        assert trace_long['z.' + name] == trace_short['z.' + name]


@pytest.mark.parametrize('model, status, reasons', [
    (None, c.ScoreStatus.UNESTIMATED, ('model_absent',)),
    ('null', c.ScoreStatus.UNESTIMATED, ('model_reference_null', 'model_uncalibrated')),
    ('uncalibrated', c.ScoreStatus.UNESTIMATED, ('model_uncalibrated',)),
    ('nonlinear', c.ScoreStatus.MODEL_INVALID, ('model_kind_not_supported',)),
    ('zero-scale', c.ScoreStatus.MODEL_INVALID, ('model_parameter_invalid:std.rvol_lb.scale',)),
])
def test_no_number_is_fabricated_without_a_usable_calibrated_model(model, status, reasons):
    manifest = {None: None, 'null': md.reference_null_manifest(protocol=s.DRAFT_PROTOCOL, created_ms=s.T0_MS),
                'uncalibrated': fx.synthetic_fixture_manifest(calibration=c.CalibrationStatus.UNCALIBRATED),
                'nonlinear': c.ModelManifest(**{**MODEL.__dict__, 'kind': c.ModelKind.SIMPLE_NONLINEAR}),
                'zero-scale': fx.synthetic_fixture_manifest(overrides={'std.rvol_lb.scale': '0'})}[model]
    result = score('FFF', model=manifest)
    assert (result.status, result.verdict, result.reasons) == (status, c.ScoreVerdict.NO_TRADE, reasons)
    assert (result.expected_net_r, result.p_stop, result.tail_loss_r, result.utility) == (None,) * 4
    assert result.uncertainty == 1 and result.data_quality == 1
    assert result.model.model_fingerprint == (None if manifest is None else manifest.fingerprint())


def test_missing_stale_partial_and_non_finite_data_abstain():
    small = {k: v for k, v in fx.synthetic_universe().items() if k in {fx.inst_id(b) for b in fx.BASES[:4]}}
    cases = {
        'partial': (snap(universe=small), ('feature_missing:liq_turnover_pct', 'feature_missing:mkt_ret_lb',
                                           'feature_missing:xs_dispersion_lb', 'feature_missing:xs_rel_ret_lb')),
        'stale_quote': (snap(quotes=fx.synthetic_quotes('AAA', 0, observed_ms=fx.DECISION_MS - 61_000)),
                        ('feature_stale:spread_bp',)),
        'no_quote': (snap(quotes=()), ('feature_missing:spread_bp',)),
        'no_funding': (snap(funding=()), ('feature_missing:funding_rate_hourly',)),
    }
    for name, (snapshot, expected) in cases.items():
        result = ENGINE.score(cand(snapshot), snapshot, MODEL)
        assert result.status is c.ScoreStatus.INVALID_INPUT and result.verdict is c.ScoreVerdict.NO_TRADE, name
        assert result.reasons == expected and result.expected_net_r is None, name
        assert result.data_quality < 1, name


def test_zero_volatility_makes_the_stop_distance_non_finite():
    universe = dict(fx.synthetic_universe())
    flat = bars_of('AAA', ['100'] * fx.N_BARS)
    universe[fx.inst_id('AAA')] = flat
    snapshot = snap(universe=universe)
    assert feature(snapshot, 'rvol_lb').value == 0
    result = ENGINE.score(cand(snapshot, entry='100'), snapshot, MODEL)
    assert result.status is c.ScoreStatus.INVALID_INPUT
    assert 'feature_non_finite:stop_vol_units' in result.reasons
    assert 'feature_non_finite:efficiency_lb' in result.reasons


def test_values_beyond_the_arithmetic_domain_abstain_instead_of_raising():
    snapshot = snap('FFF')
    huge = c.FeatureSnapshot(**{**snapshot.__dict__, 'features': tuple(
        dataclasses.replace(f, value=D('1e29')) if f.name == 'mkt_ret_lb' else f for f in snapshot.features)})
    result = ENGINE.score(cand(huge), huge, MODEL)                           # z = 1e29 / 0.02 = 5e30 > 1e30
    assert result.status is c.ScoreStatus.INVALID_INPUT
    assert result.reasons == ('arithmetic_out_of_domain:arith_magnitude_exceeded',)


def test_freshness_is_rechecked_at_the_candidate_decision():
    snapshot = snap()
    late_decision = fx.DECISION_MS + 3 * H
    header = dataclasses.replace(snapshot.header, clock=c.Clock(
        observed_ms=snapshot.header.clock.observed_ms, available_ms=snapshot.header.clock.available_ms,
        decision_ms=late_decision))
    old = c.FeatureSnapshot(**{**snapshot.__dict__, 'header': header})   # features carried forward 3 h
    result = ENGINE.score(cand(old), old, MODEL)
    assert result.status is c.ScoreStatus.INVALID_INPUT
    assert {'feature_stale:rvol_lb', 'feature_stale:spread_bp'} <= set(result.reasons)


def test_snapshot_candidate_mismatches_are_invalid_input():
    snapshot = snap()
    good = cand(snapshot)
    other = snap('BBB')
    assert ENGINE.score(cand(other), snapshot, MODEL).reasons == ('snapshot_instrument_mismatch',
                                                                  'snapshot_ref_mismatch')
    later = snap(decision_ms=fx.DECISION_MS + 1000)
    mismatch = ENGINE.score(good, later, MODEL)
    assert mismatch.status is c.ScoreStatus.INVALID_INPUT and mismatch.data_quality == 0
    assert 'snapshot_decision_time_mismatch' in mismatch.reasons and 'snapshot_ref_mismatch' in mismatch.reasons
    sided = c.FeatureSnapshot(**{**snapshot.__dict__, 'header': dataclasses.replace(snapshot.header,
                                                                                     side=c.Side.SHORT)})
    assert 'snapshot_side_mismatch' in ENGINE.score(cand(sided), sided, MODEL).reasons
    real = c.Provenance(source='okx_public_archive', dataset_id='dev-x', dataset_sha256='sha256:' + '0' * 64,
                        label=c.DataLabel.DEVELOPMENT, license='okx-terms')
    relabelled = c.FeatureSnapshot(**{**snapshot.__dict__, 'header': dataclasses.replace(snapshot.header,
                                                                                          provenance=real)})
    assert 'snapshot_provenance_mismatch' in ENGINE.score(good, relabelled, MODEL).reasons
    spec = c.FeatureSnapshot(**{**snapshot.__dict__, 'feature_spec_version': '9.9.9'})
    assert 'feature_spec_mismatch' in ENGINE.score(cand(spec), spec, MODEL).reasons
    renamed = c.FeatureSnapshot(**{**snapshot.__dict__, 'features': tuple(
        dataclasses.replace(f, unit='pct') if f.name == 'spread_bp' else f for f in snapshot.features)})
    assert ENGINE.score(cand(renamed), renamed, MODEL).reasons == ('feature_unit_mismatch:spread_bp',)
    extra = c.FeatureValue(name='zzz_unknown', value=D('1'), status=c.FeatureStatus.OK, observed_ms=0,
                           available_ms=0, unit='u')
    added = c.FeatureSnapshot(**{**snapshot.__dict__, 'features': snapshot.features + (extra,)})
    assert ENGINE.score(cand(added), added, MODEL).reasons == ('feature_unknown:zzz_unknown',)


def test_out_of_support_keeps_numbers_but_abstains():
    narrow = fx.synthetic_fixture_manifest(policy=dataclasses.replace(fx.FIXTURE_POLICY, z_support_max=D('1')))
    wide = score('FFF')
    result = score('FFF', model=narrow)
    assert result.status is c.ScoreStatus.ESTIMATED and result.verdict is c.ScoreVerdict.NO_TRADE
    assert result.utility == wide.utility                    # numbers kept for audit only
    assert 'feature_outside_training_support:xs_rel_ret_lb' in result.reasons


def test_uncertainty_shrinks_with_sample_size_and_grows_with_noise_and_extrapolation():
    base = score('FFF')
    bigger_n = score('FFF', model=fx.synthetic_fixture_manifest(overrides={'unc.n_train': '4000'}))
    noisier = score('FFF', model=fx.synthetic_fixture_manifest(overrides={'unc.residual_sd_r': '3'}))
    assert bigger_n.uncertainty < base.uncertainty < noisier.uncertainty
    assert noisier.utility < base.utility < bigger_n.utility
    z_far = ENGINE.evaluate(cand(snap('FFF')), snap('FFF'), MODEL).values
    shrunk = fx.synthetic_fixture_manifest(overrides={'std.xs_rel_ret_lb.scale': '0.005'})
    z_near = ENGINE.evaluate(cand(snap('FFF')), snap('FFF'), shrunk).values
    assert D(z_near['quadratic_form']) > D(z_far['quadratic_form']) and D(z_near['se']) > D(z_far['se'])
    tight = fx.synthetic_fixture_manifest(policy=dataclasses.replace(fx.FIXTURE_POLICY, uncertainty_max=D('0.3')))
    assert 'uncertainty_above_max' in score('FFF', model=tight).reasons


def test_inverse_correlation_matrix_enters_the_quadratic_form():
    z = [D('1'), D('2')]
    assert quadratic_form(z, None) == 5
    assert quadratic_form(z, ((D('1'), D('0.5')), (D('0.5'), D('2')))) == D('11')     # 1 + 1 + 1 + 8
    with_cinv = ENGINE.evaluate(cand(snap('EEE')), snap('EEE'), fx.synthetic_fixture_manifest(cinv=True)).values
    without = ENGINE.evaluate(cand(snap('EEE')), snap('EEE'), MODEL).values
    assert with_cinv['quadratic_form'] != without['quadratic_form']


def test_imputation_is_explicit_bounded_and_costs_uncertainty():
    small = {k: v for k, v in fx.synthetic_universe().items() if k in {fx.inst_id(b) for b in fx.BASES[:4]}}
    snapshot = snap(universe=small)
    impute4 = fx.synthetic_fixture_manifest(policy=dataclasses.replace(fx.FIXTURE_POLICY, impute_missing=True,
                                                                      max_imputed=4))
    result = ENGINE.score(cand(snapshot), snapshot, impute4)
    assert result.status is c.ScoreStatus.ESTIMATED and result.data_quality == D('0.5')
    assert 'feature_imputed:mkt_ret_lb' in result.reasons
    trace = ENGINE.evaluate(cand(snapshot), snapshot, impute4).values
    weights = [D('0.08'), D('0.12'), D('-0.02'), D('0.02')]          # gross_r weights of the imputed features
    floor = ar.total(ar.mul(w, w) for w in weights)
    assert D(trace['variance']) > floor
    impute3 = fx.synthetic_fixture_manifest(policy=dataclasses.replace(fx.FIXTURE_POLICY, impute_missing=True,
                                                                      max_imputed=3))
    refused = ENGINE.score(cand(snapshot), snapshot, impute3)
    assert refused.status is c.ScoreStatus.INVALID_INPUT and 'too_many_imputed' in refused.reasons
    no_quote = snap(quotes=())
    assert ENGINE.score(cand(no_quote), no_quote, impute4).reasons == ('feature_missing:spread_bp',)  # costs never


def test_calibration_is_interpolated_and_clamped():
    table = ((D('-1'), D('0.2')), (D('0'), D('0.4')), (D('1'), D('0.9')))
    assert interpolate(table, D('-2')) == (D('0.2'), True)
    assert interpolate(table, D('0.5')) == (D('0.65'), False)
    assert interpolate(table, D('1')) == (D('0.9'), False)
    assert interpolate(table, D('0')) == (D('0.4'), False)
    narrow = fx.synthetic_fixture_manifest(overrides={'head.stop.calib.00.x': '-0.01', 'head.stop.calib.01.x': '0',
                                                      'head.stop.calib.02.x': '0.01'})
    assert 'p_stop_index_clamped' in score('FFF', model=narrow).reasons


def test_negative_tail_prediction_is_a_model_failure():
    result = score('AAA', model=fx.synthetic_fixture_manifest(overrides={'head.tail_r.intercept': '-5'}))
    assert result.status is c.ScoreStatus.MODEL_INVALID and result.reasons == ('model_tail_prediction_negative',)


def test_engine_scores_only():
    public = {n for n in dir(ScoringEngine) if not n.startswith('_')}
    assert public == {'score', 'evaluate', 'rank'}


# ---------------------------------------------------------------------------------------------------- ranking

def batch_scores(model=MODEL):
    out = []
    for base in fx.BASES:
        for side in (c.Side.LONG, c.Side.SHORT):
            out.append(score(base, side, model=model))
    return out


def test_ranking_is_order_independent_idempotent_and_coherent():
    scores = batch_scores()
    ranked = ENGINE.rank(scores)
    for seed in range(5):
        shuffled = list(scores)
        random.Random(seed).shuffle(shuffled)
        assert ENGINE.rank(shuffled) == ranked
    assert ENGINE.rank(ranked) == ranked
    ranked_only = [x for x in ranked if x.rank is not None]
    assert [x.rank for x in ranked_only] == list(range(1, len(ranked_only) + 1))
    assert all(x.verdict is c.ScoreVerdict.TRADE_CANDIDATE for x in ranked_only)
    assert [x.utility for x in ranked_only] == sorted((x.utility for x in ranked_only), reverse=True)
    rest = ranked[len(ranked_only):]
    assert all(x.rank is None for x in rest) and [x.candidate_id for x in rest] == sorted(x.candidate_id for x in rest)
    assert len(ranked_only) >= 2 and len(rest) >= 2


def test_ties_are_broken_by_candidate_id_and_unestimated_never_ranked():
    snapshot = snap('FFF')
    a = ENGINE.score(cand(snapshot, candidate_id='cand-b'), snapshot, MODEL)
    b = ENGINE.score(cand(snapshot, candidate_id='cand-a'), snapshot, MODEL)
    assert a.utility == b.utility
    assert [x.candidate_id for x in ENGINE.rank([a, b])] == ['cand-a', 'cand-b']
    null = md.reference_null_manifest(protocol=s.DRAFT_PROTOCOL, created_ms=s.T0_MS)
    assert all(x.rank is None for x in ENGINE.rank(batch_scores(null)))
    assert ENGINE.rank([]) == ()


def test_ranking_refuses_mixed_batches():
    scores = batch_scores()
    with pytest.raises(ScoringError, match='scoring_rank_duplicate_candidate'):
        ENGINE.rank(scores + scores[:1])
    with pytest.raises(ScoringError, match='scoring_rank_mixed_models'):
        ENGINE.rank(scores[:1] + [score('BBB', model=fx.synthetic_fixture_manifest(model_version='0.1.1'))])
    later = snap(decision_ms=fx.DECISION_MS + H)
    with pytest.raises(ScoringError, match='scoring_rank_mixed_decision_times'):
        ENGINE.rank(scores[:1] + [ENGINE.score(cand(later, candidate_id='cand-later'), later, MODEL)])


# -------------------------------------------------------------------------------------------- B1/B3 readiness

def real_provenance(dataset_id='dev-2025', sha='1'):
    return c.Provenance(source='okx_public_archive', dataset_id=dataset_id, dataset_sha256='sha256:' + sha * 64,
                        label=c.DataLabel.DEVELOPMENT, license='okx-terms')


FROZEN = c.ProtocolRef(protocol_id='h3-dev', protocol_version='1.0.0', status=c.ProtocolStatus.FROZEN)


def test_scored_variants_need_a_calibrated_model():
    synthetic = (s.SYNTHETIC_PROVENANCE,)
    assert md.scored_variant_readiness(MODEL, synthetic) == ()            # synthetic plumbing runs only
    assert md.scored_variant_readiness(None, synthetic) == ('model_absent',)
    null = md.reference_null_manifest(protocol=s.DRAFT_PROTOCOL, created_ms=0)
    assert md.scored_variant_readiness(null, synthetic) == ('model_reference_null', 'model_uncalibrated')
    uncal = fx.synthetic_fixture_manifest(calibration=c.CalibrationStatus.UNCALIBRATED)
    assert md.scored_variant_readiness(uncal, synthetic) == ('model_uncalibrated',)


def test_synthetic_fixture_never_scores_real_data_and_training_must_not_overlap():
    evaluation = (real_provenance('val-2026', '2'),)
    reasons = md.scored_variant_readiness(MODEL, evaluation)
    assert {'model_trained_on_synthetic_only', 'model_protocol_not_frozen', 'experiment_protocol_not_frozen'} <= set(
        reasons)
    trained = c.ModelManifest(**{**MODEL.__dict__, 'training_provenance': (real_provenance(),), 'protocol': FROZEN})
    assert md.scored_variant_readiness(trained, evaluation, FROZEN) == ()
    overlap = md.scored_variant_readiness(trained, (real_provenance('other-name', '1'),), FROZEN)
    assert overlap == ('model_training_overlaps_evaluation:dev-2025',)


# ----------------------------------------------------------------------------------------------- golden / parity

def test_golden_vectors_are_reproduced_from_their_stored_inputs():
    document = parity.load_golden()
    assert parity.verify_golden(document) == []
    assert document['feature_spec']['fingerprint'] == CATALOGUE_FINGERPRINT
    statuses = {case['expected_score']['status'] for case in document['score_cases']}
    assert statuses == {'estimated', 'unestimated', 'invalid_input', 'model_invalid'}


def test_golden_file_is_byte_identical_to_a_fresh_build():
    assert parity.GOLDEN_FILE.read_text(encoding='utf-8') == parity.dumps(parity.build_golden())


def test_golden_detects_a_divergence():
    document = parity.load_golden()
    case = document['score_cases'][3]
    case['expected_score'] = {**case['expected_score'], 'utility': '0.1'}
    assert parity.verify_golden(document) == ['score:' + case['case_id']]


def test_golden_wire_has_no_float_and_only_plain_decimals():
    text = parity.GOLDEN_FILE.read_text(encoding='utf-8')

    def refuse(_):
        raise AssertionError('JSON float in golden file')

    json.loads(text, parse_float=refuse, parse_constant=refuse)
    assert re.search(r'"-?[0-9]+(\.[0-9]+)?[eE][-+]?[0-9]+"', text) is None      # no exponent notation
    assert re.search(r'"-0"|"-?[0-9]+\.[0-9]*0"', text) is None                  # no "-0", no trailing zero
    for case in parity.load_golden()['score_cases']:
        c.OpportunityScore.from_json(c.canonical_json(case['expected_score']))


# ------------------------------------------------------------------------------------------------- portability

def test_every_module_parses_as_python_311():
    files = sorted(PACKAGE.rglob('*.py'))
    assert len(files) >= 7
    for path in files:
        ast.parse(path.read_text(encoding='utf-8'), feature_version=(3, 11))
