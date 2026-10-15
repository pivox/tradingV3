"""Feature catalogue ``h3-scoring-features`` 0.1.0 and the pure functions that compute it.

A small, deliberately limited catalogue. Each ``FeatureSpec`` documents its unit, formula, inputs, availability rule,
maximum age, provenance, economic rationale, leakage risks and correlation notes; ``docs/handbook/research/
H3-OPPORTUNITY-SCORING.md`` renders the same table. Every number in the catalogue (bar interval, lookback, minimum
universe, maximum ages) is a DRAFT a-priori choice, explained in its spec, to be frozen by the owner; none was chosen
from known performance.

Roles. ``predictive`` features may be model inputs; ``cost`` features are deterministic cost accounting, subtracted
by the engine and never learned; ``quality`` features drive abstention only; ``risk_gate`` features depend on a
variant's portfolio and are for the risk gate only, never for the score (a portfolio-dependent score would differ
between B1 and B3 and confound the factorial design).

Time rules (anti-lookahead). Only bars with ``Bar.usable_at(decision_ms)`` (closed AND received) are used; the
currently forming bar and any bar received after the decision are ignored, so adding or changing them never changes a
feature. Quotes and funding settlements are used only when ``available_ms <= decision_ms``; funding uses settled rates
only, never a predicted or current-period rate. Each FeatureValue carries ``observed_ms`` (end of the last
observation used) and ``available_ms`` (latest receipt time of the data used). A feature whose newest input is older
than its ``max_age_ms`` is STALE (no value); an incomplete window is MISSING; a zero denominator is NON_FINITE. Input
order never matters: bars are keyed by ``open_ms`` and conflicting duplicates make the feature MISSING.

Arithmetic: ``scoring.arith`` (18 decimals, truncation toward zero), so a PHP port can reproduce the values.
"""
from __future__ import annotations

import enum
import re
from collections.abc import Mapping, Sequence
from dataclasses import dataclass
from decimal import Decimal
from typing import Any

from ..contracts import (
    Bar, Clock, CostModel, FeatureSnapshot, FeatureStatus, FeatureValue, FundingObservation, Header, InstrumentRef,
    OpportunityCandidate, Product, ProtocolRef, Provenance, Quote, Side, canonical_json, derive_data_status,
    sha256_text,
)
from . import arith as ar

HOUR_MS = 3_600_000
BP = Decimal(10_000)


class FeatureInputError(ValueError):
    """The caller passed structurally wrong inputs (programming error, not a data-quality event)."""


_REASON_CHAR = re.compile(r'[^a-z0-9_.-]')


def reason(code: str, subject: str) -> str:
    """Stable reason code ``<code>:<subject>``, the subject lower-cased, restricted to [a-z0-9_.-] and cut so that the
    whole code fits the contracts' 96-character reason format."""
    clean = _REASON_CHAR.sub('-', subject.lower())
    return (code + ':' + clean)[:96]


class Role(str, enum.Enum):
    PREDICTIVE = 'predictive'
    COST = 'cost'
    QUALITY = 'quality'
    RISK_GATE = 'risk_gate'


class Source(str, enum.Enum):
    INSTRUMENT_BARS = 'instrument_bars'
    UNIVERSE_BARS = 'universe_bars'
    QUOTES = 'quotes'
    FUNDING = 'funding'
    CANDIDATE = 'candidate'            # derived at scoring time from the candidate and the snapshot
    PORTFOLIO_BARS = 'portfolio_bars'  # held instruments of one variant portfolio


SNAPSHOT_SOURCES = (Source.INSTRUMENT_BARS, Source.UNIVERSE_BARS, Source.QUOTES, Source.FUNDING)


@dataclass(frozen=True)
class FeatureSpec:
    name: str
    unit: str
    role: Role
    source: Source
    directional: bool          # True: the engine multiplies the value by +1 (long) / -1 (short) before use
    formula: str
    inputs: tuple[str, ...]
    availability: str
    max_age_ms: int | None     # newest input older than this at the decision -> STALE (None: not applicable)
    provenance: str
    rationale: str
    leakage_risks: str
    correlation_notes: str

    def to_document(self) -> dict[str, Any]:
        return {'name': self.name, 'unit': self.unit, 'role': self.role.value, 'source': self.source.value,
                'directional': self.directional, 'formula': self.formula, 'inputs': list(self.inputs),
                'availability': self.availability, 'max_age_ms': self.max_age_ms, 'provenance': self.provenance,
                'rationale': self.rationale, 'leakage_risks': self.leakage_risks,
                'correlation_notes': self.correlation_notes}


@dataclass(frozen=True)
class FeatureSpecSet:
    """A versioned catalogue. Its order is the canonical order of model features. Any change needs a new version."""

    spec_id: str
    spec_version: str
    interval_ms: int
    lookback_bars: int
    min_universe: int
    specs: tuple[FeatureSpec, ...]

    def __post_init__(self) -> None:
        names = [s.name for s in self.specs]
        if len(set(names)) != len(names) or self.lookback_bars < 2 or self.min_universe < 2 or self.interval_ms <= 0:
            raise FeatureInputError('feature_spec_set_invalid')

    def get(self, name: str) -> FeatureSpec | None:
        return next((s for s in self.specs if s.name == name), None)

    @property
    def names(self) -> tuple[str, ...]:
        return tuple(s.name for s in self.specs)

    def names_with(self, *, roles: Sequence[Role] | None = None,
                   sources: Sequence[Source] | None = None) -> tuple[str, ...]:
        return tuple(s.name for s in self.specs if (roles is None or s.role in roles)
                     and (sources is None or s.source in sources))

    @property
    def snapshot_names(self) -> tuple[str, ...]:
        """Names a FeatureSnapshot of this catalogue carries, sorted (the contract's order)."""
        return tuple(sorted(self.names_with(sources=SNAPSHOT_SOURCES)))

    def to_document(self) -> dict[str, Any]:
        return {'spec_id': self.spec_id, 'spec_version': self.spec_version, 'interval_ms': self.interval_ms,
                'lookback_bars': self.lookback_bars, 'min_universe': self.min_universe,
                'specs': [s.to_document() for s in self.specs]}

    def fingerprint(self) -> str:
        return sha256_text(canonical_json(self.to_document()))


_BARS = ('Bars of the candidate instrument (h3.bar/1), interval 1 h, the last N+1 = 25 closed bars usable at the '
         'decision. Venue public candles (OKX Europe X-Perp for execution studies).')
_UNIVERSE = ('Bars of every instrument of the point-in-time universe at the decision (caller-supplied mapping '
             'inst_id -> bars), aligned on the candidate window end.')
_BAR_AVAIL = 'Closed bars with available_ms <= decision_ms only; observed_ms = close_ms of the last bar used.'
_BAR_AGE = 7_200_000  # DRAFT: 2 intervals; a priori one late bar tolerated, two means the feed stalled

CATALOGUE = FeatureSpecSet(
    spec_id='h3-scoring-features',
    spec_version='0.1.0',
    interval_ms=HOUR_MS,   # DRAFT a priori: the candidate sources under study (CS-*) act over hours to days
    lookback_bars=24,      # DRAFT a priori: one day of 1 h bars, one funding/attention cycle; not tuned
    min_universe=5,        # DRAFT a priori: below 5 instruments a cross-sectional median is not meaningful
    specs=(
        FeatureSpec(
            name='mkt_ret_lb', unit='fraction', role=Role.PREDICTIVE, source=Source.UNIVERSE_BARS, directional=True,
            formula='median over the aligned universe of (close[t] / close[t-24] - 1)',
            inputs=('universe_bars',), availability=_BAR_AVAIL, max_age_ms=_BAR_AGE, provenance=_UNIVERSE,
            rationale='Market component of the recent return: crypto returns share a strong common factor; the '
                      'market trend is the regime a directional trade is exposed to.',
            leakage_risks='Survivorship if the universe is today\'s listing instead of the point-in-time one; '
                          'lookahead if a bar of any instrument is not yet received at the decision.',
            correlation_notes='Return = market + relative component: never add an own-return feature next to '
                              'mkt_ret_lb and xs_rel_ret_lb (exact collinearity up to the median).'),
        FeatureSpec(
            name='xs_rel_ret_lb', unit='fraction', role=Role.PREDICTIVE, source=Source.UNIVERSE_BARS,
            directional=True,
            formula='(close[t] / close[t-24] - 1) - mkt_ret_lb',
            inputs=('universe_bars',), availability=_BAR_AVAIL, max_age_ms=_BAR_AGE, provenance=_UNIVERSE,
            rationale='Relative (cross-sectional) momentum: the documented short-horizon continuation or reversal '
                      'of idiosyncratic moves; it is the coin-specific part of the move.',
            leakage_risks='Same as mkt_ret_lb; delisted coins must stay in the cross-section until their delisting.',
            correlation_notes='Correlated with rvol_lb in absolute value (large moves happen in volatile coins); '
                              'sign-oriented, so not with it in sign.'),
        FeatureSpec(
            name='xs_dispersion_lb', unit='fraction', role=Role.PREDICTIVE, source=Source.UNIVERSE_BARS,
            directional=False,
            formula='median over the aligned universe of |ret_j - mkt_ret_lb| (cross-sectional MAD of 24-bar '
                    'returns)',
            inputs=('universe_bars',), availability=_BAR_AVAIL, max_age_ms=_BAR_AGE, provenance=_UNIVERSE,
            rationale='Dispersion between instruments: a high dispersion regime offers more room for '
                      'coin-specific opportunities and more selection risk; low dispersion means one common '
                      'factor drives everything.',
            leakage_risks='Survivorship (dead coins have extreme returns: dropping them lowers dispersion).',
            correlation_notes='Rises with market volatility; expected moderate correlation with rvol_lb.'),
        FeatureSpec(
            name='rvol_lb', unit='fraction_per_bar', role=Role.PREDICTIVE, source=Source.INSTRUMENT_BARS,
            directional=False,
            formula='sqrt(mean over the last 24 bars of r_i^2), r_i = close[i] / close[i-1] - 1 (zero-mean RMS)',
            inputs=('instrument_bars',), availability=_BAR_AVAIL, max_age_ms=_BAR_AGE, provenance=_BARS,
            rationale='Recent volatility: sets how far a stop is in noise terms and how large outcomes are; '
                      'volatility clusters, so recent volatility is informative about the holding period.',
            leakage_risks='Lookahead if the forming bar is included (excluded by Bar.usable_at).',
            correlation_notes='A range-based (Parkinson) volatility would duplicate it and is excluded.'),
        FeatureSpec(
            name='efficiency_lb', unit='ratio', role=Role.PREDICTIVE, source=Source.INSTRUMENT_BARS,
            directional=False,
            formula='|close[t] - close[t-24]| / sum over the last 24 bars of |close[i] - close[i-1]| (Kaufman '
                    'efficiency ratio, in [0, 1]); a zero path length is NON_FINITE',
            inputs=('instrument_bars',), availability=_BAR_AVAIL, max_age_ms=_BAR_AGE, provenance=_BARS,
            rationale='Market structure: a trending (efficient) path versus a choppy, mean-reverting one; stops '
                      'and targets behave differently in each.',
            leakage_risks='None beyond the bar rule.',
            correlation_notes='Partly a function of |ret| / (rvol x 24): to be dropped if its label-free rank '
                              'correlation with those exceeds the DRAFT pruning bound on development data.'),
        FeatureSpec(
            name='liq_turnover_pct', unit='percentile', role=Role.PREDICTIVE, source=Source.UNIVERSE_BARS,
            directional=False,
            formula='percentile rank, among the aligned universe, of the median per-bar quote turnover over the '
                    'last 24 bars: (#{j != i: T_j < T_i} + #{j != i: T_j = T_i} / 2) / (N - 1)',
            inputs=('universe_bars',), availability=_BAR_AVAIL, max_age_ms=_BAR_AGE, provenance=_UNIVERSE,
            rationale='Liquidity level, scale-free: liquid instruments have tighter real execution and are more '
                      'efficiently priced; a rank avoids the heavy tail of raw turnover (no log in bcmath).',
            leakage_risks='Survivorship (illiquid coins are the ones that disappear).',
            correlation_notes='Expected negative correlation with spread_bp (a cost, not a model input).'),
        FeatureSpec(
            name='stop_vol_units', unit='vol_units', role=Role.PREDICTIVE, source=Source.CANDIDATE,
            directional=False,
            formula='(|entry_reference_price - initial_stop_price| / entry_reference_price) / rvol_lb',
            inputs=('candidate', 'rvol_lb'),
            availability='Known at the candidate decision; inherits the times of rvol_lb.', max_age_ms=None,
            provenance='The candidate (h3.opportunity_candidate/1) and the snapshot feature rvol_lb.',
            rationale='Distance to the stop in volatility units: the a-priori driver of the stop-out probability '
                      'over a fixed holding period.',
            leakage_risks='The stop must come from the generator\'s rule at the decision, never from a later '
                          'price (e.g. a stop placed under a low that formed after the decision).',
            correlation_notes='Contains rvol_lb in the denominator: expected negative correlation with it.'),
        FeatureSpec(
            name='spread_bp', unit='bp', role=Role.COST, source=Source.QUOTES, directional=False,
            formula='(ask - bid) / ((ask + bid) / 2) x 10000 on the latest quote with available_ms <= decision',
            inputs=('quotes',),
            availability='Latest quote received at or before the decision; observed_ms = quote observed_ms.',
            max_age_ms=60_000,  # DRAFT a priori: a top of book older than one minute is not the current book
            provenance='Top-of-book quotes (h3.quote/1) of the candidate instrument.',
            rationale='Predictable cost: crossing the spread is paid on entry and exit.',
            leakage_risks='Using a quote observed after the decision; using a daily average spread computed over '
                          'a period that includes the trade.',
            correlation_notes='Cost input, not learned: it enters cost_r deterministically.'),
        FeatureSpec(
            name='funding_rate_hourly', unit='fraction_per_hour', role=Role.COST, source=Source.FUNDING,
            directional=True,
            formula='last settled rate x 3600000 / (last funding_ms - previous funding_ms); sign: paid by longs',
            inputs=('funding',),
            availability='Settled rates with available_ms <= decision only; observed_ms = last funding_ms.',
            max_age_ms=86_400_000,  # DRAFT a priori: one day, i.e. three 8 h settlement periods
            provenance='Settled funding observations (h3.funding_observation/1) of the candidate instrument.',
            rationale='Predictable carrying cost of a perpetual position over the holding period.',
            leakage_risks='The current-period rate is only known at settlement: an archive keyed by settlement '
                          'time leaks it if read before; predicted rates are revised and are not used.',
            correlation_notes='Cost input, not learned: it enters funding_cost_r deterministically.'),
        FeatureSpec(
            name='cost_r', unit='r_multiple', role=Role.COST, source=Source.CANDIDATE, directional=False,
            formula='(2 x taker_fee_rate + spread_bp / 10000 + 2 x slippage_rate) / stop_fraction',
            inputs=('candidate', 'spread_bp', 'cost_model'),
            availability='Known at the candidate decision; inherits the times of spread_bp.', max_age_ms=None,
            provenance='The experiment CostModel (h3.cost_model/1, identical for B0-B3), the candidate and '
                       'spread_bp.',
            rationale='Round-trip predictable cost in R: with a tight stop, costs are a large share of R.',
            leakage_risks='Costs taken from realised fills of the evaluated trades.',
            correlation_notes='Inversely proportional to the stop fraction; subtracted, never learned.'),
        FeatureSpec(
            name='funding_cost_r', unit='r_multiple', role=Role.COST, source=Source.CANDIDATE, directional=False,
            formula='max(0, side_sign x funding_rate_hourly) x (max_holding_ms / 3600000) / stop_fraction; 0 for '
                    'spot',
            inputs=('candidate', 'funding_rate_hourly'),
            availability='Known at the candidate decision; inherits the times of funding_rate_hourly.',
            max_age_ms=None,
            provenance='The candidate and funding_rate_hourly.',
            rationale='Funding paid over the maximum holding period, in R; credits are ignored (DRAFT, a priori '
                      'conservative: the holding time and the rate are uncertain).',
            leakage_risks='Using the realised holding time of the trade instead of the ex-ante maximum.',
            correlation_notes='Subtracted, never learned. A funding-carry candidate source (CS-FUND) must revisit '
                              'the credit rule in its protocol.'),
        FeatureSpec(
            name='data_age_ms', unit='ms', role=Role.QUALITY, source=Source.INSTRUMENT_BARS, directional=False,
            formula='decision_ms - close_ms of the latest usable bar of the candidate instrument',
            inputs=('instrument_bars',), availability=_BAR_AVAIL, max_age_ms=None, provenance=_BARS,
            rationale='Freshness of the data pipeline; drives abstention, measures our pipeline rather than the '
                      'market, so it is never a predictive input.',
            leakage_risks='None.',
            correlation_notes='Not a model input.'),
        FeatureSpec(
            name='portfolio_corr_lb', unit='correlation', role=Role.RISK_GATE, source=Source.PORTFOLIO_BARS,
            directional=False,
            formula='max over held instruments j of side_c x side_j x pearson(r_c, r_j) over the last 24 aligned '
                    '1-bar returns; 0 for an empty portfolio',
            inputs=('instrument_bars', 'portfolio_bars'), availability=_BAR_AVAIL, max_age_ms=_BAR_AGE,
            provenance='Bars of the candidate and of the instruments held by one variant portfolio.',
            rationale='Concentration: a new position that duplicates an existing exposure adds risk without '
                      'diversification.',
            leakage_risks='Each variant holds different positions: as a score input it would make B1 and B3 '
                          'scores differ and confound the factorial design, hence risk gate only.',
            correlation_notes='Not a score input.'),
    ),
)


# ------------------------------------------------------------------------------------------------- value builders

@dataclass(frozen=True)
class _Raw:
    """A feature before its placeholder times are resolved (MISSING features take the snapshot as-of time)."""

    name: str
    value: Decimal | None
    status: FeatureStatus
    observed_ms: int | None
    available_ms: int | None


def _ok(name: str, value: Decimal, observed_ms: int, available_ms: int) -> _Raw:
    return _Raw(name, ar.q(value), FeatureStatus.OK, observed_ms, available_ms)


def _missing(name: str) -> _Raw:
    return _Raw(name, None, FeatureStatus.MISSING, None, None)


def _status(name: str, status: FeatureStatus, observed_ms: int, available_ms: int) -> _Raw:
    return _Raw(name, None, status, observed_ms, available_ms)


def _finalize(raw: _Raw, unit: str, as_of_ms: int) -> FeatureValue:
    observed = as_of_ms if raw.observed_ms is None else raw.observed_ms
    available = as_of_ms if raw.available_ms is None else raw.available_ms
    return FeatureValue(name=raw.name, value=raw.value, status=raw.status, observed_ms=observed,
                        available_ms=available, unit=unit)


# ------------------------------------------------------------------------------------------------------- windows

@dataclass(frozen=True)
class BarWindow:
    """``count`` contiguous closed bars usable at the decision, oldest first."""

    bars: tuple[Bar, ...]

    @property
    def observed_ms(self) -> int:
        return self.bars[-1].close_ms

    @property
    def available_ms(self) -> int:
        return max(b.available_ms for b in self.bars)

    @property
    def closes(self) -> tuple[Decimal, ...]:
        return tuple(b.close for b in self.bars)


def usable_bars(inst_id: str, bars: Sequence[Bar], decision_ms: int, interval_ms: int) -> tuple[Bar, ...] | None:
    """Usable bars of one instrument keyed by open_ms, oldest first; None when two usable bars conflict.

    Unclosed bars, bars received after the decision and bars of another interval are ignored. Bars of another
    instrument are a caller error.
    """
    by_open: dict[int, Bar] = {}
    for bar in bars:
        if not isinstance(bar, Bar):
            raise FeatureInputError('feature_input_not_a_bar')
        if bar.inst_id != inst_id:
            raise FeatureInputError('feature_bars_instrument_mismatch')
        if bar.interval_ms != interval_ms or not bar.usable_at(decision_ms):
            continue
        known = by_open.get(bar.open_ms)
        if known is not None and known != bar:
            return None
        by_open[bar.open_ms] = bar
    return tuple(by_open[k] for k in sorted(by_open))


def closed_window(inst_id: str, bars: Sequence[Bar], decision_ms: int, interval_ms: int,
                  count: int) -> tuple[BarWindow | None, str]:
    """The last ``count`` usable bars if they are contiguous. Reason: ok, conflict, insufficient or gap."""
    usable = usable_bars(inst_id, bars, decision_ms, interval_ms)
    if usable is None:
        return None, 'conflict'
    if len(usable) < count:
        return None, 'insufficient'
    tail = usable[-count:]
    if any(tail[k + 1].open_ms != tail[k].close_ms for k in range(count - 1)):
        return None, 'gap'
    return BarWindow(tail), 'ok'


def _returns(closes: Sequence[Decimal]) -> list[Decimal]:
    return [ar.sub(ar.div(closes[k], closes[k - 1]), ar.ONE) for k in range(1, len(closes))]


def window_return(window: BarWindow) -> Decimal:
    closes = window.closes
    return ar.sub(ar.div(closes[-1], closes[0]), ar.ONE)


def _is_stale(observed_ms: int, decision_ms: int, max_age_ms: int | None) -> bool:
    return max_age_ms is not None and decision_ms - observed_ms > max_age_ms


# ---------------------------------------------------------------------------------------------- feature functions

def instrument_features(inst_id: str, bars: Sequence[Bar], decision_ms: int,
                        spec: FeatureSpecSet = CATALOGUE) -> dict[str, _Raw]:
    """rvol_lb, efficiency_lb and data_age_ms of one instrument."""
    usable = usable_bars(inst_id, bars, decision_ms, spec.interval_ms)
    out: dict[str, _Raw] = {}
    if not usable:
        out['data_age_ms'] = _missing('data_age_ms')
    else:
        last = usable[-1]
        out['data_age_ms'] = _ok('data_age_ms', Decimal(decision_ms - last.close_ms), last.close_ms,
                                 last.available_ms)
    window, _ = closed_window(inst_id, bars, decision_ms, spec.interval_ms, spec.lookback_bars + 1)
    if window is None:
        out['rvol_lb'] = _missing('rvol_lb')
        out['efficiency_lb'] = _missing('efficiency_lb')
        return out
    obs, avail = window.observed_ms, window.available_ms
    if _is_stale(obs, decision_ms, spec.get('rvol_lb').max_age_ms):  # type: ignore[union-attr]
        out['rvol_lb'] = _status('rvol_lb', FeatureStatus.STALE, obs, avail)
        out['efficiency_lb'] = _status('efficiency_lb', FeatureStatus.STALE, obs, avail)
        return out
    returns = _returns(window.closes)
    out['rvol_lb'] = _ok('rvol_lb', ar.sqrt(ar.mean([ar.mul(r, r) for r in returns])), obs, avail)
    closes = window.closes
    path = ar.total(ar.absolute(ar.sub(closes[k], closes[k - 1])) for k in range(1, len(closes)))
    if path == 0:
        out['efficiency_lb'] = _status('efficiency_lb', FeatureStatus.NON_FINITE, obs, avail)
    else:
        out['efficiency_lb'] = _ok('efficiency_lb', ar.div(ar.absolute(ar.sub(closes[-1], closes[0])), path),
                                   obs, avail)
    return out


UNIVERSE_FEATURES = ('mkt_ret_lb', 'xs_rel_ret_lb', 'xs_dispersion_lb', 'liq_turnover_pct')


def percentile_rank(target: Decimal, others: Sequence[Decimal]) -> Decimal:
    """(#{x < target} + #{x == target} / 2) / len(others), over the other instruments only."""
    below = sum(1 for x in others if x < target)
    equal = sum(1 for x in others if x == target)
    return ar.div(ar.add(below, ar.div(equal, ar.TWO)), len(others))


def universe_features(inst_id: str, universe_bars: Mapping[str, Sequence[Bar]], decision_ms: int,
                      spec: FeatureSpecSet = CATALOGUE) -> dict[str, _Raw]:
    """Cross-sectional features of ``inst_id`` against the caller's point-in-time universe (which must contain it).

    Instruments whose window is incomplete, conflicting, stale or not aligned on the candidate's window end are left
    out of the cross-section; fewer than ``spec.min_universe`` remaining instruments make every feature MISSING.
    """
    if inst_id not in universe_bars:
        raise FeatureInputError('feature_target_not_in_universe')
    max_age = spec.get('mkt_ret_lb').max_age_ms  # type: ignore[union-attr]
    target, _ = closed_window(inst_id, universe_bars[inst_id], decision_ms, spec.interval_ms, spec.lookback_bars + 1)
    if target is None:
        return {name: _missing(name) for name in UNIVERSE_FEATURES}
    if _is_stale(target.observed_ms, decision_ms, max_age):
        return {name: _status(name, FeatureStatus.STALE, target.observed_ms, target.available_ms)
                for name in UNIVERSE_FEATURES}
    aligned: dict[str, BarWindow] = {}
    for other in sorted(universe_bars):
        window, _ = closed_window(other, universe_bars[other], decision_ms, spec.interval_ms, spec.lookback_bars + 1)
        if window is not None and window.observed_ms == target.observed_ms:
            aligned[other] = window
    if len(aligned) < spec.min_universe:
        return {name: _missing(name) for name in UNIVERSE_FEATURES}
    obs = target.observed_ms
    avail = max(w.available_ms for w in aligned.values())
    returns = {j: window_return(w) for j, w in aligned.items()}
    market = ar.median(list(returns.values()))
    dispersion = ar.median([ar.absolute(ar.sub(r, market)) for r in returns.values()])
    turnover = {j: ar.median([b.volume_quote for b in w.bars[1:]]) for j, w in aligned.items()}
    others = [turnover[j] for j in aligned if j != inst_id]
    return {
        'mkt_ret_lb': _ok('mkt_ret_lb', market, obs, avail),
        'xs_rel_ret_lb': _ok('xs_rel_ret_lb', ar.sub(returns[inst_id], market), obs, avail),
        'xs_dispersion_lb': _ok('xs_dispersion_lb', dispersion, obs, avail),
        'liq_turnover_pct': _ok('liq_turnover_pct', percentile_rank(turnover[inst_id], others), obs, avail),
    }


def quote_features(inst_id: str, quotes: Sequence[Quote], decision_ms: int,
                   spec: FeatureSpecSet = CATALOGUE) -> dict[str, _Raw]:
    """spread_bp from the latest quote received at or before the decision; conflicting latest quotes -> MISSING."""
    usable = []
    for quote in quotes:
        if not isinstance(quote, Quote) or quote.inst_id != inst_id:
            raise FeatureInputError('feature_quotes_instrument_mismatch')
        if quote.available_ms <= decision_ms:
            usable.append(quote)
    if not usable:
        return {'spread_bp': _missing('spread_bp')}
    key = max((qt.observed_ms, qt.available_ms) for qt in usable)
    latest = {qt for qt in usable if (qt.observed_ms, qt.available_ms) == key}
    if len(latest) != 1:
        return {'spread_bp': _missing('spread_bp')}
    quote = latest.pop()
    if _is_stale(quote.observed_ms, decision_ms, spec.get('spread_bp').max_age_ms):  # type: ignore[union-attr]
        return {'spread_bp': _status('spread_bp', FeatureStatus.STALE, quote.observed_ms, quote.available_ms)}
    mid = ar.div(ar.add(quote.ask, quote.bid), ar.TWO)
    spread = ar.mul(ar.div(ar.sub(quote.ask, quote.bid), mid), BP)
    return {'spread_bp': _ok('spread_bp', spread, quote.observed_ms, quote.available_ms)}


def funding_features(inst_id: str, funding: Sequence[FundingObservation], decision_ms: int,
                     spec: FeatureSpecSet = CATALOGUE) -> dict[str, _Raw]:
    """funding_rate_hourly from the last two settlements known at the decision."""
    by_time: dict[int, FundingObservation] = {}
    for item in funding:
        if not isinstance(item, FundingObservation) or item.inst_id != inst_id:
            raise FeatureInputError('feature_funding_instrument_mismatch')
        if item.available_ms > decision_ms:
            continue
        known = by_time.get(item.funding_ms)
        if known is not None and known != item:
            return {'funding_rate_hourly': _missing('funding_rate_hourly')}
        by_time[item.funding_ms] = item
    if len(by_time) < 2:
        return {'funding_rate_hourly': _missing('funding_rate_hourly')}
    times = sorted(by_time)
    last, previous = by_time[times[-1]], by_time[times[-2]]
    obs, avail = last.funding_ms, max(last.available_ms, previous.available_ms)
    if _is_stale(obs, decision_ms, spec.get('funding_rate_hourly').max_age_ms):  # type: ignore[union-attr]
        return {'funding_rate_hourly': _status('funding_rate_hourly', FeatureStatus.STALE, obs, avail)}
    hourly = ar.div(ar.mul(last.rate, HOUR_MS), last.funding_ms - previous.funding_ms)
    return {'funding_rate_hourly': _ok('funding_rate_hourly', hourly, obs, avail)}


# -------------------------------------------------------------------------------------------------------- snapshot

def build_snapshot(*, instrument: InstrumentRef, decision_ms: int, provenance: Provenance, protocol: ProtocolRef,
                   snapshot_id: str, universe_bars: Mapping[str, Sequence[Bar]], quotes: Sequence[Quote] = (),
                   funding: Sequence[FundingObservation] = (), spec: FeatureSpecSet = CATALOGUE) -> FeatureSnapshot:
    """Side-agnostic, portfolio-agnostic FeatureSnapshot of one instrument at one decision instant.

    The header clock is observed = the latest observed_ms of the features that have data, available = the latest
    available_ms (decision_ms when no feature has data). MISSING features carry that observed time as both times.
    """
    inst_id = instrument.inst_id
    raws: dict[str, _Raw] = {}
    raws.update(instrument_features(inst_id, universe_bars.get(inst_id, ()), decision_ms, spec))
    raws.update(universe_features(inst_id, universe_bars, decision_ms, spec))
    raws.update(quote_features(inst_id, quotes, decision_ms, spec))
    raws.update(funding_features(inst_id, funding, decision_ms, spec))
    if set(raws) != set(spec.snapshot_names):
        raise FeatureInputError('feature_spec_set_not_supported')
    timed = [r for r in raws.values() if r.observed_ms is not None]
    observed = max((r.observed_ms for r in timed), default=decision_ms)  # type: ignore[type-var]
    available = max((r.available_ms for r in timed), default=decision_ms)  # type: ignore[type-var]
    features = tuple(_finalize(raws[name], spec.get(name).unit, observed)  # type: ignore[union-attr]
                     for name in spec.snapshot_names)
    header = Header(instrument=instrument, side=None,
                    clock=Clock(observed_ms=observed, available_ms=available, decision_ms=decision_ms),
                    provenance=provenance, protocol=protocol, portfolio_id=None)
    return FeatureSnapshot(header=header, snapshot_id=snapshot_id, feature_spec_id=spec.spec_id,
                           feature_spec_version=spec.spec_version, features=features,
                           data_status=derive_data_status(features))


# ------------------------------------------------------------------------------------------- candidate-derived

def side_sign(side: Side) -> Decimal:
    return ar.ONE if side is Side.LONG else -ar.ONE


def orient(value: Decimal, side: Side) -> Decimal:
    """Directional features are oriented so that a positive value favours the candidate's side."""
    return ar.mul(value, side_sign(side))


def stop_fraction(candidate: OpportunityCandidate) -> Decimal:
    return ar.div(candidate.stop_distance, candidate.entry_reference_price)


def _derived(name: str, unit: str, source: FeatureValue | None, compute: Any) -> FeatureValue:
    if source is None:
        return FeatureValue(name=name, value=None, status=FeatureStatus.MISSING, observed_ms=0, available_ms=0,
                            unit=unit)
    if source.status is not FeatureStatus.OK:
        return FeatureValue(name=name, value=None, status=source.status, observed_ms=source.observed_ms,
                            available_ms=source.available_ms, unit=unit)
    try:
        value = compute(source.value)
    except ar.ArithmeticDomainError:
        value = None
    status = FeatureStatus.OK if value is not None else FeatureStatus.NON_FINITE
    return FeatureValue(name=name, value=value, status=status, observed_ms=source.observed_ms,
                        available_ms=source.available_ms, unit=unit)


def derived_features(candidate: OpportunityCandidate, snapshot: FeatureSnapshot, cost_model: CostModel,
                     spec: FeatureSpecSet = CATALOGUE) -> dict[str, FeatureValue]:
    """stop_vol_units, cost_r and funding_cost_r. Status and times propagate from the snapshot input; a zero
    denominator (rvol_lb = 0) gives NON_FINITE. A missing input feature gives MISSING with times 0."""
    side = candidate.header.side
    assert side is not None  # guaranteed by the OpportunityCandidate contract
    stop = stop_fraction(candidate)
    out = {
        'stop_vol_units': _derived('stop_vol_units', spec.get('stop_vol_units').unit,  # type: ignore[union-attr]
                                   snapshot.value('rvol_lb'), lambda rvol: ar.div(stop, rvol)),
        'cost_r': _derived('cost_r', spec.get('cost_r').unit, snapshot.value('spread_bp'),  # type: ignore[union-attr]
                           lambda spread: ar.div(
                               ar.add(ar.add(ar.mul(ar.TWO, cost_model.taker_fee_rate), ar.div(spread, BP)),
                                      ar.mul(ar.TWO, cost_model.slippage_rate)), stop)),
    }
    hours = ar.div(candidate.max_holding_ms, HOUR_MS)
    unit = spec.get('funding_cost_r').unit  # type: ignore[union-attr]
    if candidate.header.instrument.product is Product.SPOT:
        clock = candidate.header.clock
        out['funding_cost_r'] = FeatureValue(name='funding_cost_r', value=ar.ZERO, status=FeatureStatus.OK,
                                             observed_ms=clock.observed_ms, available_ms=clock.available_ms,
                                             unit=unit)
    else:
        out['funding_cost_r'] = _derived(
            'funding_cost_r', unit, snapshot.value('funding_rate_hourly'),
            lambda rate: ar.div(ar.mul(_cost_part(orient(rate, side)), hours), stop))
    return out


def _cost_part(oriented_rate: Decimal) -> Decimal:
    """Funding paid by the candidate's side; a credit counts as 0 (DRAFT conservative rule)."""
    return oriented_rate if oriented_rate > 0 else ar.ZERO


# --------------------------------------------------------------------------------------- correlated-feature pruning

def average_ranks(values: Sequence[Decimal]) -> list[Decimal]:
    """1-based ranks, ties sharing their average rank (a multiple of 0.5, exact)."""
    order = sorted(range(len(values)), key=lambda k: (values[k], k))
    ranks = [ar.ZERO] * len(values)
    start = 0
    while start < len(order):
        end = start
        while end + 1 < len(order) and values[order[end + 1]] == values[order[start]]:
            end += 1
        shared = ar.div(start + end + 2, ar.TWO)
        for k in order[start:end + 1]:
            ranks[k] = shared
        start = end + 1
    return ranks


@dataclass(frozen=True)
class PruneResult:
    kept: tuple[str, ...]
    dropped: tuple[tuple[str, str, Decimal], ...]   # (dropped feature, kept partner, Spearman rho)


def prune_correlated(columns: Mapping[str, Sequence[Decimal]], bound: Decimal,
                     spec: FeatureSpecSet = CATALOGUE) -> PruneResult:
    """Label-free pruning of redundant predictive features, for an authorised development protocol.

    ``columns`` holds complete feature rows (no outcome, no label: the rule cannot select on performance). Features
    are visited in catalogue order; one whose |Spearman rho| with an already kept feature exceeds ``bound`` is
    dropped (partner = the kept feature with the largest |rho|, first in catalogue order on ties). ``bound`` has no
    default: the DRAFT value (0.8, an a-priori collinearity rule of thumb) must be frozen by the owner.
    """
    names = [n for n in spec.names if n in columns]
    if set(names) != set(columns):
        raise FeatureInputError('feature_prune_unknown_feature')
    lengths = {len(columns[n]) for n in names}
    if len(lengths) != 1 or lengths.pop() < 3:
        raise FeatureInputError('feature_prune_columns_invalid')
    ranks = {n: average_ranks([ar.q(v) for v in columns[n]]) for n in names}
    kept: list[str] = []
    dropped: list[tuple[str, str, Decimal]] = []
    for name in names:
        worst: tuple[Decimal, str] | None = None
        for other in kept:
            rho = pearson(ranks[name], ranks[other])
            if rho is not None and (worst is None or ar.absolute(rho) > ar.absolute(worst[0])):
                worst = (rho, other)
        if worst is not None and ar.absolute(worst[0]) > ar.q(bound):
            dropped.append((name, worst[1], worst[0]))
        else:
            kept.append(name)
    return PruneResult(tuple(kept), tuple(dropped))


# ------------------------------------------------------------------------------------------------- risk gate only

def pearson(xs: Sequence[Decimal], ys: Sequence[Decimal]) -> Decimal | None:
    """sxy / sqrt(sxx) / sqrt(syy) on mean-centred values; None when a variance is zero."""
    mx, my = ar.mean(xs), ar.mean(ys)
    dx = [ar.sub(x, mx) for x in xs]
    dy = [ar.sub(y, my) for y in ys]
    sxx = ar.total(ar.mul(a, a) for a in dx)
    syy = ar.total(ar.mul(b, b) for b in dy)
    if sxx == 0 or syy == 0:
        return None
    sxy = ar.total(ar.mul(a, b) for a, b in zip(dx, dy))
    rho = ar.div(ar.div(sxy, ar.sqrt(sxx)), ar.sqrt(syy))
    return ar.clamp(rho, -ar.ONE, ar.ONE)


def portfolio_correlation(*, inst_id: str, side: Side, inst_bars: Sequence[Bar],
                          held: Mapping[str, tuple[Side, Sequence[Bar]]], decision_ms: int,
                          spec: FeatureSpecSet = CATALOGUE) -> FeatureValue:
    """portfolio_corr_lb, for the risk gate of one variant portfolio (never a score input).

    ``held`` maps each held instrument to (position side, its bars). An empty portfolio gives 0 by definition. Any
    held instrument without a complete aligned window makes the feature MISSING (unknown concentration).
    """
    name, unit = 'portfolio_corr_lb', spec.get('portfolio_corr_lb').unit  # type: ignore[union-attr]
    count = spec.lookback_bars + 1
    window, _ = closed_window(inst_id, inst_bars, decision_ms, spec.interval_ms, count)
    if window is None:
        return _finalize(_missing(name), unit, decision_ms)
    obs, avail = window.observed_ms, window.available_ms
    if _is_stale(obs, decision_ms, spec.get(name).max_age_ms):  # type: ignore[union-attr]
        return _finalize(_status(name, FeatureStatus.STALE, obs, avail), unit, obs)
    if not held:
        return _finalize(_ok(name, ar.ZERO, obs, avail), unit, obs)
    own = _returns(window.closes)
    best: Decimal | None = None
    for other in sorted(held):
        held_side, bars = held[other]
        other_window, _ = closed_window(other, bars, decision_ms, spec.interval_ms, count)
        if other_window is None or other_window.observed_ms != obs:
            return _finalize(_missing(name), unit, obs)
        avail = max(avail, other_window.available_ms)
        rho = pearson(own, _returns(other_window.closes))
        if rho is None:
            return _finalize(_status(name, FeatureStatus.NON_FINITE, obs, avail), unit, obs)
        signed = ar.mul(ar.mul(rho, side_sign(side)), side_sign(held_side))
        best = signed if best is None or signed > best else best
    return _finalize(_ok(name, best, obs, avail), unit, obs)  # type: ignore[arg-type]
