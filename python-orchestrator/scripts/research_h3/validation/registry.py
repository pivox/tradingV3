"""Machine-readable data registry of H3 (C7), mirroring docs/handbook/research/H3-DATA-ELIGIBILITY.md.

Built from the repository documentation only (no data file was opened). Each entry records what the documents say
was read, by whom, and what H3 may do with it. Ambiguities are resolved conservatively: when documents disagree or
an overlap is unknown, the window counts as consumed.

Rules (``check_use``):
- consumed (or consumed-equivalent: same underlying price paths over the same period read on another product) data
  may serve development only, and only after the owner's explicit consent; never validation, holdout or
  prospective confirmation;
- reserved data (fwd-okx-r1 W0-W2 and, for H3, any OKX price data of 2026-10-14 to 2026-10-31) are never read;
- missing data cannot be used;
- prospective data (collected on the target product after a freeze) is the only admissible confirmation;
- possibly-independent data exist only off the target market (Binance, Hyperliquid, Kraken): at most development or
  transferability statistics, never a confirmation of an OKX Europe X-Perp claim.
Conclusion: ``independent_historical_validation()`` returns INDEPENDENT_DATA_NOT_AVAILABLE.
"""
from __future__ import annotations

import enum
from dataclasses import dataclass

from ..contracts import DataLabel, Provenance

INDEPENDENT_DATA_NOT_AVAILABLE = 'INDEPENDENT_DATA_NOT_AVAILABLE'
TARGET_MARKET = 'okx_europe_xperp'
PROSPECTIVE_EARLIEST_START_DRAFT = '2026-11-02'   # DRAFT: first Monday after the fwd-okx-r1 verdict (31 Oct)

BINANCE_LICENCE = 'CC BY-NC-SA 4.0; personal non-production research only; no live trading (sec. 4.2)'


class DatasetStatus(str, enum.Enum):
    CONSUMED = 'consumed'
    CONSUMED_EQUIVALENT = 'consumed_equivalent'
    RESERVED = 'reserved'
    POSSIBLY_INDEPENDENT = 'possibly_independent'
    MISSING = 'missing'
    PROSPECTIVE = 'prospective'


class H3Use(str, enum.Enum):
    DEVELOPMENT_AFTER_OWNER_CONSENT = 'development_after_owner_consent'
    STATISTICS_ONLY_OFF_TARGET = 'statistics_only_off_target'
    NEVER = 'never'
    NOT_AVAILABLE = 'not_available'
    PROSPECTIVE_CONFIRMATION = 'prospective_confirmation'


class RegistryError(ValueError):
    """A dataset use the registry forbids. The message is a stable reason code."""


@dataclass(frozen=True)
class DatasetEntry:
    dataset_id: str
    market: str           # venue_product
    kind: str
    scope: str
    window: str
    read_by: str
    status: DatasetStatus
    h3_use: H3Use
    licence: str
    sources: tuple[str, ...]


R = 'docs/handbook/research/'
P = 'docs/handbook/reports/'
C = DatasetStatus.CONSUMED
DEV = H3Use.DEVELOPMENT_AFTER_OWNER_CONSENT


def _binance(dataset_id: str, kind: str, scope: str, window: str, read_by: str, sources: tuple[str, ...],
             status: DatasetStatus = C) -> DatasetEntry:
    use = H3Use.STATISTICS_ONLY_OFF_TARGET if status is DatasetStatus.POSSIBLY_INDEPENDENT else DEV
    return DatasetEntry(dataset_id, 'binance_usdm', kind, scope, window, read_by, status, use, BINANCE_LICENCE,
                        sources)


REGISTRY: tuple[DatasetEntry, ...] = (
    _binance('binance-um-majors-2023-2024', 'klines 1m-4h + funding', '10 majors', '2023-01 to 2024-12',
             'day trading, swing r1/r2, canonical campaign, v2 selection, candle scalping, swing v2',
             (R + 'RESEARCH-CAMPAIGN-STATUS.md', P + 'research-strategy-landscape-2026-10.md')),
    _binance('binance-um-majors-2025', 'klines 1m-4h + funding', '10 majors', '2025',
             'day trading, swing, campaign, v2 (validation)', (R + 'RESEARCH-CAMPAIGN-STATUS.md',)),
    _binance('binance-um-majors-4h-2026', 'klines 4h + funding', '10 majors', '2026-01-01 to 2026-10-09T06Z',
             'swing campaign holdout (claim 1)', (P + 'research-swing-campaign-2026-10-12.md',)),
    _binance('binance-um-midcaps-2023-2025', 'klines 1m-4h + funding', '10 mid-caps', '2023-01 to 2026-01-01',
             'swing v2 validation', (R + 'RESEARCH-CAMPAIGN-STATUS.md',)),
    _binance('binance-um-midcaps-4h-2026', 'klines 4h + funding', '10 mid-caps', '2026-01-01 to 2026-10-09T06Z',
             'swing v2 post-hoc read (claim 2)', (P + 'research-swing-v2-2026-10-14.md',)),
    _binance('binance-tick-2025-9w', 'aggTrades + bookDepth + funding', '20 r8 pairs', '9 weeks of 2025 (Feb-Oct)',
             'r1-r8, q3x, Q3 V2 training', (R + 'RESEARCH-CAMPAIGN-STATUS.md',)),
    _binance('binance-aggtrades-2025-11-12', 'aggTrades', 'majors and mid-caps', '2025-11-10..14, 2025-12-08..12',
             'r3, r7 validation/holdout; majors December tick level ambiguous across documents, counted consumed',
             (R + 'RESEARCH-CAMPAIGN-STATUS.md', P + 'research-midcap-tick-2026-10-13.md',
              P + 'research-execution-tick-2026-10-12.md')),
    _binance('binance-r8b-2024-12-2025-01', 'aggTrades + bookDepth', '20 pairs', '2024-12-09..13, 2025-01-13..17',
             'r8b holdout and validation', (P + 'research-book-families-2026-10-13.md',)),
    _binance('binance-bookticker-r9-train', 'bookTicker + aggTrades', '18 pairs',
             'weeks of 2023-07, 2023-09, 2023-11, 2024-01', 'r9 training',
             (P + 'research-book-families-2026-10-13.md',)),
    _binance('binance-bookticker-r9-2024-02-03', 'bookTicker', '18 pairs (candles of the same pairs consumed)',
             '2024-02-12..16, 2024-03-25..29', 'never read (r9 validation/holdout not run)',
             (P + 'research-book-families-2026-10-13.md',), DatasetStatus.POSSIBLY_INDEPENDENT),
    _binance('binance-tick-2024-4w', 'aggTrades + klines 1m + OI metrics', '20 pairs',
             'weeks of 2024-04, 05, 06, 08', 'r10-r13, leverage > 2x E0a/E1',
             (R + 'PREREGISTRATION-leverage-gt2.md', R + 'RESEARCH-CAMPAIGN-STATUS.md')),
    _binance('binance-midcaps-2024-09-10', 'aggTrades + OI metrics', 'mid-caps', '2024-09, 2024-10',
             'r12 and r14 validation', (P + 'research-zone-only-2026-10-13.md', P + 'research-oi-buildup-2026-10-13.md')),
    _binance('binance-q3-2024-11', 'aggTrades + bookDepth + funding', '20 Q3 pairs', '2024-11-04..29',
             'Q3 V2 validation (#499)', (P + 'research-scalp-q3-r2-validation-2026-11-2024.md',)),
    _binance('binance-daily-2017-2026', 'daily spot, perps + funding', 'top 50',
             'spot 2017-08 to 2023-01, perps 2020-01 to 2023-01 (read); 2023-2026 partially read by H1 S_B, '
             'overlap unknown, counted consumed', 'regime-r2 training, H1 S_B index and controls',
             (P + 'research-regime-r2-2026-10-14.md', R + 'RESEARCH-CAMPAIGN-STATUS.md')),
    _binance('binance-h1-sb-listings', 'klines 1m/1h/1d + funding', '240 listings, index, controls',
             'events 2023-09-16 to 2026-07-03', 'H1 S_B Gate 1 and Gate 2',
             (R + 'H1-SB-GATE1-RESULT.md', R + 'H1-GATE2-RESULT.md')),
    _binance('binance-other-intraday', 'intraday klines, ticks, OI metrics',
             'symbols and weeks outside every list above', '2019 onwards',
             'unread per the documents (not exhaustively proven)', (R + 'RESEARCH-CAMPAIGN-STATUS.md',),
             DatasetStatus.POSSIBLY_INDEPENDENT),
    DatasetEntry('okx-swap-swingv2-2023-2026', 'okx_global_usdt_swap', 'klines 1m + funding', '193 series (363 families)',
                 '2023-01 to 2026-10-12T16Z', 'swing v2 OKX (#500)', C, DEV, 'OKX market-data terms not documented',
                 (P + 'research-swing-v2-okx-2026-10-14.md', R + 'RESEARCH-CAMPAIGN-STATUS.md')),
    DatasetEntry('okx-swap-h1-sa-listings', 'okx_global_usdt_swap', 'klines 1m + funding', '83 (+13) listings',
                 't0 to t0 + 31 d, t0 in [2023-09-01, 2026-09-01)', 'H1 S_A screen, Gate 2', C, DEV,
                 'OKX market-data terms not documented', (R + 'H1-DATA-MANIFEST.md',)),
    DatasetEntry('okx-swap-h2-2022-2026', 'okx_global_usdt_swap', 'klines 1m last price',
                 '20 programme bases + other families (incl. first 42 days via 60-day beta windows)',
                 '2022-09 to 2026-10-06T16Z', 'H2 Gate 1', C, DEV, 'OKX market-data terms not documented',
                 (R + 'PREREGISTRATION-H2-GATE1.md', R + 'H2-CLOSURE-DECISION.md')),
    DatasetEntry('okx-hl-captures-run14-run15', 'okx_global_usdt_swap+hyperliquid', 'public 24 h captures',
                 'BTC, ETH', '2026-10-02/03', 'execution study r1 (exploratory)', C, DEV,
                 'not documented', (R + 'RESEARCH-CAMPAIGN-STATUS.md',)),
    DatasetEntry('fwd-okx-r1-w0', 'okx_global_usdt_swap', 'live public capture (quotes, depth, trades)', '20 r8 pairs',
                 '2026-10-14..16', 'fwd-okx-r1 shakedown', DatasetStatus.RESERVED, H3Use.NEVER, 'not documented',
                 ('docs/handbook/runbooks/research-forward-okx.md', R + 'RESEARCH-CAMPAIGN-STATUS.md')),
    DatasetEntry('fwd-okx-r1-w1', 'okx_global_usdt_swap', 'live public capture (quotes, depth, trades)', '20 r8 pairs',
                 '2026-10-19..23', 'fwd-okx-r1 validation', DatasetStatus.RESERVED, H3Use.NEVER, 'not documented',
                 ('docs/handbook/runbooks/research-forward-okx.md',)),
    DatasetEntry('fwd-okx-r1-w2', 'okx_global_usdt_swap', 'live public capture (quotes, depth, trades)', '20 r8 pairs',
                 '2026-10-26..30', 'fwd-okx-r1 holdout', DatasetStatus.RESERVED, H3Use.NEVER, 'not documented',
                 ('docs/handbook/runbooks/research-forward-okx.md',)),
    DatasetEntry('okx-any-2026-10-14-to-2026-10-31', 'okx_all_products', 'any price data', 'all instruments',
                 '2026-10-14 to 2026-10-31', 'H3 calendar quarantine around fwd-okx-r1 (same price paths)',
                 DatasetStatus.RESERVED, H3Use.NEVER, 'not documented', (R + 'RESEARCH-CAMPAIGN-STATUS.md',)),
    DatasetEntry('okx-xperp-metadata', TARGET_MARKET, 'instrument specs, tickers, one funding and book snapshot',
                 '160 crypto X-Perps', '2026-10-14/15 snapshots', 'H1 X-Perp audit, H2 prerequisites (no returns)',
                 C, DEV, 'not documented',
                 (R + 'H1-XPERP-LISTING-INVENTORY.md', R + 'TRADABLE-INSTRUMENT-UNIVERSE.md')),
    DatasetEntry('okx-xperp-history-2026-03-30-to-2026-10-12', TARGET_MARKET,
                 'klines 1m (history-candles) + funding history', '160 crypto X-Perps (29 >= 1 M USD/day)',
                 '2026-03-30 to 2026-10-12T16Z',
                 'never read itself; same underlyings and period read through OKX USDT swaps',
                 DatasetStatus.CONSUMED_EQUIVALENT, DEV, 'not documented',
                 (R + 'H2-PREREQUISITES-REPORT.md', R + 'H1-NEXT-DECISION.md')),
    DatasetEntry('okx-xperp-book-history', TARGET_MARKET, 'order-book history', 'X-Perps', 'none',
                 'does not exist (live capture only)', DatasetStatus.MISSING, H3Use.NOT_AVAILABLE, 'n/a',
                 (R + 'TRADINGV3-EXCHANGE-COMPATIBILITY.md', R + 'H1-EXECUTABILITY-REVIEW.md')),
    DatasetEntry('okx-xperp-prospective', TARGET_MARKET, 'klines, funding, quotes captured after the H3 freeze',
                 'X-Perps of the frozen universe', f'from {PROSPECTIVE_EARLIEST_START_DRAFT} (DRAFT), after the freeze',
                 'nobody (does not exist yet)', DatasetStatus.PROSPECTIVE, H3Use.PROSPECTIVE_CONFIRMATION,
                 'OKX terms to be checked before capture', (R + 'H1-NEXT-DECISION.md', R + 'RESEARCH-ELIGIBILITY-GATES.md')),
    DatasetEntry('okx-europe-spot', 'okx_europe_spot', 'any', 'EUR/USDC pairs', 'unknown', 'nobody',
                 DatasetStatus.MISSING, H3Use.NOT_AVAILABLE, 'not verified', (R + 'TRADABLE-INSTRUMENT-UNIVERSE.md',)),
    DatasetEntry('hyperliquid-public', 'hyperliquid_perp', 'API candles, S3 books and fills', 'USDC perps',
                 'archive', 'unread except run15', DatasetStatus.POSSIBLY_INDEPENDENT, H3Use.STATISTICS_ONLY_OFF_TARGET,
                 'not documented', (R + 'FRANCE-EXCHANGE-ELIGIBILITY.md', R + 'TRADABLE-INSTRUMENT-UNIVERSE.md')),
    DatasetEntry('kraken-futures-public', 'kraken_futures', 'public history', 'perpetuals',
                 'EEA product since 2025-05-20', 'unread', DatasetStatus.POSSIBLY_INDEPENDENT,
                 H3Use.STATISTICS_ONLY_OFF_TARGET, 'not verified', (R + 'H2-FEASIBILITY-REVIEW.md',)),
    DatasetEntry('coinbase-eea-history', 'coinbase_eea', 'history', 'perpetual-style futures', 'since 2026-03',
                 'unread, availability unverified', DatasetStatus.MISSING, H3Use.NOT_AVAILABLE, 'not verified',
                 (R + 'TRADABLE-INSTRUMENT-UNIVERSE.md',)),
    DatasetEntry('token-unlock-calendar', 'third_party', 'unlock calendar', 'tokens', 'unknown',
                 'nobody; source not chosen', DatasetStatus.MISSING, H3Use.NOT_AVAILABLE, 'unknown',
                 (R + 'H1-NEXT-DECISION.md',)),
    DatasetEntry('historical-specs-and-delistings', 'okx_all_products',
                 'historical contract specs, delisting settlement prices, listing-day books', 'all', 'past',
                 'not observed by H1', DatasetStatus.MISSING, H3Use.NOT_AVAILABLE, 'n/a', (R + 'H1-DATA-MANIFEST.md',)),
)

_BY_ID = {e.dataset_id: e for e in REGISTRY}


def entry(dataset_id: str) -> DatasetEntry:
    try:
        return _BY_ID[dataset_id]
    except KeyError as error:
        raise RegistryError('registry_dataset_unknown') from error


def check_use(dataset_id: str, label: DataLabel, *, owner_consent: bool = False) -> str:
    """Return 'registry_use_allowed' or raise with the reason the registry forbids this use."""
    if label is DataLabel.SYNTHETIC:
        raise RegistryError('registry_synthetic_is_not_a_dataset')
    e = entry(dataset_id)
    if e.status is DatasetStatus.RESERVED:
        raise RegistryError('registry_dataset_reserved')
    if e.status is DatasetStatus.MISSING:
        raise RegistryError('registry_dataset_missing')
    if e.status is DatasetStatus.PROSPECTIVE:
        if label is not DataLabel.PROSPECTIVE:
            raise RegistryError('registry_prospective_only')
        return 'registry_use_allowed'
    if label is not DataLabel.DEVELOPMENT:
        raise RegistryError('registry_development_only' if e.market == TARGET_MARKET or e.status in (
            DatasetStatus.CONSUMED, DatasetStatus.CONSUMED_EQUIVALENT) else 'registry_not_target_market')
    if not owner_consent:
        raise RegistryError('registry_development_needs_owner_consent')
    return 'registry_use_allowed'


def check_provenance(provenance: Provenance, *, owner_consent: bool = False) -> str:
    if provenance.label is DataLabel.SYNTHETIC:
        return 'registry_synthetic'
    return check_use(provenance.dataset_id, provenance.label, owner_consent=owner_consent)


def independent_historical_validation() -> str:
    """INDEPENDENT_DATA_NOT_AVAILABLE unless an unread historical dataset exists on the target market."""
    usable = [e for e in REGISTRY if e.market == TARGET_MARKET and e.status is DatasetStatus.POSSIBLY_INDEPENDENT]
    return 'INDEPENDENT_HISTORICAL_DATA_AVAILABLE' if usable else INDEPENDENT_DATA_NOT_AVAILABLE
