"""Synthetic fixtures of the scoring package, for its tests and golden vectors only.

Nothing here is market data: prices follow an invented deterministic pattern, and the "calibrated" manifest is a
SYNTHETIC TEST FIXTURE whose coefficients were written by hand to exercise every code path; they were not estimated
from any data and say nothing about any market. ``scored_variant_readiness`` refuses that manifest for any
non-synthetic experiment (``model_trained_on_synthetic_only``).
"""
from __future__ import annotations

import functools
from collections.abc import Mapping
from decimal import Decimal
from typing import Any

from ..contracts import (
    Bar, CalibrationStatus, Clock, CostModel, FeatureSnapshot, FundingObservation, Header, ModelKind, ModelManifest,
    NamedValue, OpportunityCandidate, Quote, Side,
)
from ..synthetic import DRAFT_PROTOCOL, HOUR_MS, SYNTHETIC_PROVENANCE, T0_MS, instrument
from .features import CATALOGUE, build_snapshot
from .model import ScoringPolicy, build_linear_manifest

D = Decimal
N_BARS = 30
LATENCY_MS = 2_000
DECISION_MS = T0_MS + N_BARS * HOUR_MS + 5_000     # 5 s after the 30th bar closed, 3 s after it was received
BASES = ('AAA', 'BBB', 'CCC', 'DDD', 'EEE', 'FFF')
PRICE_STEP = D('0.0001')

# Fees: OKX Europe X-Perp maker 0.02 % / taker 0.05 % as given in the H3 mission brief; slippage 0.05 % is an invented
# synthetic value, not a measurement.
COST_MODEL = CostModel(maker_fee_rate=D('0.0002'), taker_fee_rate=D('0.0005'), slippage_rate=D('0.0005'),
                       spread_model='observed-top-of-book', funding_model='settled-last-two', pessimistic_multiplier=D('2'))


def inst_id(base: str) -> str:
    return instrument(base).inst_id


def synthetic_closes(j: int, n: int = N_BARS) -> list[Decimal]:
    base = D(100 + 10 * j)
    out = []
    for k in range(n):
        wiggle = D(((k * 7 + j * 3) % 11) - 5) / 1000
        drift = D(j - 2) * D('0.0006') * k
        out.append((base * (1 + drift + wiggle)).quantize(PRICE_STEP))
    return out


def synthetic_bars(base: str, j: int, n: int = N_BARS, *, start_ms: int = T0_MS) -> tuple[Bar, ...]:
    closes = synthetic_closes(j, n)
    bars = []
    for k, close in enumerate(closes):
        open_ = closes[k - 1] if k else (close * D('0.9995')).quantize(PRICE_STEP)
        high = (max(open_, close) * D('1.002')).quantize(PRICE_STEP)
        low = (min(open_, close) * D('0.998')).quantize(PRICE_STEP)
        open_ms = start_ms + k * HOUR_MS
        bars.append(Bar(inst_id=inst_id(base), interval_ms=HOUR_MS, open_ms=open_ms, close_ms=open_ms + HOUR_MS,
                        available_ms=open_ms + HOUR_MS + LATENCY_MS, is_closed=True, open=open_, high=high, low=low,
                        close=close, volume_quote=D(1000 * (j + 1) + 37 * ((k * 5 + j) % 7))))
    return tuple(bars)


def forming_bar(base: str, *, close: Any = '1000', n: int = N_BARS) -> Bar:
    """The bar that is still forming at DECISION_MS (not closed): it must never change a feature."""
    open_ms = T0_MS + n * HOUR_MS
    price = D(str(close))
    return Bar(inst_id=inst_id(base), interval_ms=HOUR_MS, open_ms=open_ms, close_ms=open_ms + HOUR_MS,
               available_ms=DECISION_MS, is_closed=False, open=price, high=price, low=price, close=price,
               volume_quote=D('1'))


@functools.lru_cache(maxsize=None)
def _universe(bases: tuple[str, ...]) -> tuple[tuple[str, tuple[Bar, ...]], ...]:
    return tuple((inst_id(b), synthetic_bars(b, BASES.index(b))) for b in bases)


def synthetic_universe(bases: tuple[str, ...] = BASES) -> dict[str, tuple[Bar, ...]]:
    """A fresh mapping each call (the bars themselves are immutable and cached)."""
    return dict(_universe(tuple(bases)))


def synthetic_quotes(base: str, j: int, *, observed_ms: int = DECISION_MS - 1_000,
                     available_ms: int | None = None) -> tuple[Quote, ...]:
    last = synthetic_closes(j)[-1]
    half = (last * D('0.0001') * (j + 1)).quantize(PRICE_STEP)   # half-spread 1 bp x (j + 1)
    return (Quote(inst_id=inst_id(base), observed_ms=observed_ms,
                  available_ms=observed_ms + 200 if available_ms is None else available_ms, bid=last - half,
                  ask=last + half, bid_size=D('100'), ask_size=D('120')),)


def synthetic_funding(base: str, j: int, *, until_ms: int = DECISION_MS) -> tuple[FundingObservation, ...]:
    """8-hourly settlements known before ``until_ms``; rate (j - 2) x 0.5 bp per 8 h (positive: longs pay)."""
    out = []
    for m in range(0, 10):
        funding_ms = T0_MS + m * 8 * HOUR_MS
        if funding_ms + 1_000 > until_ms:
            break
        out.append(FundingObservation(inst_id=inst_id(base), funding_ms=funding_ms, available_ms=funding_ms + 1_000,
                                      rate=D(j - 2) * D('0.00005')))
    return tuple(out)


def synthetic_snapshot(base: str = 'AAA', *, decision_ms: int = DECISION_MS,
                       universe: Mapping[str, tuple[Bar, ...]] | None = None, quotes: Any = None,
                       funding: Any = None) -> FeatureSnapshot:
    if universe is None and quotes is None and funding is None:
        return _default_snapshot(base, decision_ms)
    return _build_snapshot(base, decision_ms, universe, quotes, funding)


@functools.lru_cache(maxsize=None)
def _default_snapshot(base: str, decision_ms: int) -> FeatureSnapshot:
    return _build_snapshot(base, decision_ms, None, None, None)


def _build_snapshot(base: str, decision_ms: int, universe: Mapping[str, tuple[Bar, ...]] | None, quotes: Any,
                    funding: Any) -> FeatureSnapshot:
    j = BASES.index(base)
    return build_snapshot(instrument=instrument(base), decision_ms=decision_ms, provenance=SYNTHETIC_PROVENANCE,
                          protocol=DRAFT_PROTOCOL, snapshot_id=f'snap-{base.lower()}-{decision_ms}',
                          universe_bars=synthetic_universe() if universe is None else universe,
                          quotes=synthetic_quotes(base, j) if quotes is None else quotes,
                          funding=synthetic_funding(base, j) if funding is None else funding)


def synthetic_candidate(snapshot: FeatureSnapshot, *, side: Side = Side.LONG, stop_fraction: Any = '0.02',
                        candidate_id: str | None = None, max_holding_ms: int = 24 * HOUR_MS,
                        entry: Any = None) -> OpportunityCandidate:
    """A candidate on the snapshot's instrument and instant. The synthetic generator here is a test stub; the real
    common generator belongs to the experiment (agent D) and is independent of any score."""
    sh = snapshot.header
    j = BASES.index(sh.instrument.base)
    price = synthetic_closes(j)[-1] if entry is None else D(str(entry))
    distance = (price * D(str(stop_fraction))).quantize(PRICE_STEP)
    stop = price - distance if side is Side.LONG else price + distance
    clock = Clock(observed_ms=sh.clock.observed_ms, available_ms=sh.clock.available_ms,
                  decision_ms=sh.clock.decision_ms)
    header = Header(instrument=sh.instrument, side=side, clock=clock, provenance=sh.provenance, protocol=sh.protocol,
                    portfolio_id=None)
    return OpportunityCandidate(header=header, candidate_id=candidate_id or f'cand-{sh.instrument.base.lower()}-'
                                f'{side.value}', generator_id='h3-scoring-test-stub', generator_version='0.1.0',
                                hypothesis_id='synthetic-test-stub', snapshot=snapshot.ref(),
                                entry_reference_price=price, initial_stop_price=stop, initial_target_price=None,
                                max_holding_ms=max_holding_ms)


FIXTURE_FEATURES = ('mkt_ret_lb', 'xs_rel_ret_lb', 'xs_dispersion_lb', 'rvol_lb', 'efficiency_lb', 'liq_turnover_pct',
                    'stop_vol_units')
FIXTURE_POLICY = ScoringPolicy(lambda_tail=D('0.1'), gamma_uncertainty=D('0.2'), utility_min=D('0'),
                               uncertainty_max=D('0.9'), z_support_max=D('4'), impute_missing=False, max_imputed=0)


def synthetic_fixture_manifest(*, kind: ModelKind = ModelKind.LINEAR, policy: ScoringPolicy = FIXTURE_POLICY,
                               calibration: CalibrationStatus = CalibrationStatus.CALIBRATED,
                               cinv: bool = False, model_version: str = '0.1.0',
                               overrides: Mapping[str, Any] | None = None) -> ModelManifest:
    """SYNTHETIC TEST FIXTURE. Hand-written coefficients, labelled synthetic; never evidence of anything."""
    standardisation = {'mkt_ret_lb': ('0', '0.02'), 'xs_rel_ret_lb': ('0', '0.02'), 'xs_dispersion_lb': ('0.01', '0.01'),
                       'rvol_lb': ('0.004', '0.002'), 'efficiency_lb': ('0.3', '0.2'),
                       'liq_turnover_pct': ('0.5', '0.3'), 'stop_vol_units': ('5', '2')}
    heads = {
        'gross_r': {'intercept': '0.3', 'mkt_ret_lb': '0.08', 'xs_rel_ret_lb': '0.12', 'xs_dispersion_lb': '-0.02',
                    'rvol_lb': '-0.03', 'efficiency_lb': '0.04', 'liq_turnover_pct': '0.02',
                    'stop_vol_units': '0.01'},
        'stop': {'intercept': '0', 'mkt_ret_lb': '-0.3', 'xs_rel_ret_lb': '-0.2', 'xs_dispersion_lb': '0.1',
                 'rvol_lb': '0.2', 'efficiency_lb': '-0.1', 'liq_turnover_pct': '0', 'stop_vol_units': '-0.5'},
        'tail_r': {'intercept': '1.1', 'mkt_ret_lb': '0', 'xs_rel_ret_lb': '0', 'xs_dispersion_lb': '0.02',
                   'rvol_lb': '0.05', 'efficiency_lb': '0', 'liq_turnover_pct': '-0.02', 'stop_vol_units': '0'},
    }
    matrix = None
    if cinv:
        matrix = {(a, b): ('1.5' if a == b else '0') for i, a in enumerate(FIXTURE_FEATURES)
                  for b in FIXTURE_FEATURES[i:]}
        matrix[('mkt_ret_lb', 'xs_dispersion_lb')] = '-0.3'
    manifest = build_linear_manifest(
        model_id='h3-score-synthetic-fixture', model_version=model_version, kind=kind,
        feature_names=FIXTURE_FEATURES, standardisation=standardisation, heads=heads,
        stop_calibration=(('-2', '0.2'), ('0', '0.45'), ('2', '0.7')), residual_sd_r=D('1.2'), n_train=400,
        policy=policy, calibration=calibration,
        training_provenance=(SYNTHETIC_PROVENANCE,) if calibration is CalibrationStatus.CALIBRATED else (),
        protocol=DRAFT_PROTOCOL, created_ms=T0_MS, cinv=matrix,
        ridge_alpha=D('1') if kind is ModelKind.RIDGE else None,
        notes='SYNTHETIC TEST FIXTURE: hand-written coefficients, not estimated from any data; never evidence.')
    if overrides:
        params = {p.name: p.value for p in manifest.parameters}
        for name, value in overrides.items():
            if value is None:
                params.pop(name, None)
            else:
                params[name] = D(str(value))
        manifest = ModelManifest(**{**manifest.__dict__,
                                    'parameters': tuple(NamedValue(name=k, value=params[k]) for k in sorted(params))})
    return manifest


__all__ = ['COST_MODEL', 'DECISION_MS', 'BASES', 'FIXTURE_FEATURES', 'FIXTURE_POLICY', 'CATALOGUE', 'inst_id',
           'synthetic_closes', 'synthetic_bars', 'forming_bar', 'synthetic_universe', 'synthetic_quotes',
           'synthetic_funding', 'synthetic_snapshot', 'synthetic_candidate', 'synthetic_fixture_manifest']
