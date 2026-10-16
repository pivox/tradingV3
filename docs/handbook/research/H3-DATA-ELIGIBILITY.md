# H3 — data registry and eligibility (C7)

**Conclusion: `INDEPENDENT_DATA_NOT_AVAILABLE` for any historical validation of H3.** No unread historical dataset
exists on the only execution target, OKX Europe X-Perps. Independent validation of H3 can only be **prospective**:
X-Perp data captured after the protocol is frozen, starting no earlier than 2026-11-02 (DRAFT), and never inside the
fwd-okx-r1 windows.

How this registry was built: from repository documentation only (the documents listed per row, read 2026-10-15). No
data file was opened. Where documents disagree, or an overlap is unknown, the window is counted as consumed
(conservative). "Unread" means "unread according to the documents"; the documents themselves say this is not proven
exhaustively (`RESEARCH-CAMPAIGN-STATUS.md` §3).

The machine-readable copy is `python-orchestrator/scripts/research_h3/validation/registry.py`. A test
(`tests/test_research_h3_validation.py::test_registry_matches_the_eligibility_document`) checks that every row of the
table below has the same id, status and H3 use as the code.

## 1. Rules applied to H3

- **Consumed data = development only, never independent confirmation.** "Une donnée consommée peut encore servir au
  développement, jamais à une validation indépendante" (`RESEARCH-CAMPAIGN-STATUS.md` §3); same rule in
  `H2-CLOSURE-DECISION.md`, `H2-PREREQUISITES-REPORT.md` §4, `H1-NEXT-DECISION.md`. For H3, even development use of
  consumed data requires the **owner's explicit consent** (mission rule); `registry.check_use` refuses it otherwise.
- **Consumed-equivalent.** Data never read itself, but whose underlying price paths over the same period were read on
  another product, count as consumed. This covers X-Perp history up to 2026-10-12: "the same period on the same
  underlyings was read through OKX global swaps (up to 2026-10-12), and the paths are tied by the index"
  (`H2-PREREQUISITES-REPORT.md` §4).
- **Reserved = never read.** fwd-okx-r1 W0, W1 and W2 ("réservées, à ne jamais recycler",
  `RESEARCH-CAMPAIGN-STATUS.md`; `docs/handbook/runbooks/research-forward-okx.md`). For H3 the quarantine is widened
  to **any OKX price data (every product) dated 2026-10-14 to 2026-10-31**: an X-Perp of the same base in those days
  follows the same price path as the reserved USDT swaps, so reading it would peek at the reserved windows. At the
  independent review (R1-F7, 2026-10-16) the same logic was applied across venues: **any crypto price data of any
  venue** over those days is reserved (`any-venue-2026-10-14-to-2026-10-31`), because Binance, Hyperliquid or Kraken
  prices of the same underlyings follow the same paths. The owner confirms or narrows this quarantine
  ([H3-DECISION.md](H3-DECISION.md)).
- **Cross-venue consumed-equivalence (review R1-F7).** Off-target windows that cover the 2023 → 2026-10-13 period are
  `consumed_equivalent`: the same underlyings over the same period were read through OKX USDT swaps (193 series) and
  the Binance lists below. Only windows before 2023 (and outside the lists) stay `possibly_independent`.
- **Limitation (review R1-F7, documented blocker).** The code checks these rules by dataset id only: `Provenance`
  carries no underlying set and no time window, and `ModelManifest` no training window, so two datasets with
  different ids over the same paths pass as independent. Schema v2 must add them, with an (underlying x time)
  overlap rule in `registry.check_use` and in the B1/B3 readiness, before any non-synthetic run.
- **Off-target data cannot confirm an X-Perp claim.** Binance is blocked from France since 2026-07-01 and its data
  licence (CC BY-NC-SA 4.0, §4.2) forbids grounding live trading on it; Hyperliquid has no EU authorisation; Kraken's
  licence and book identity are unverified (`FRANCE-EXCHANGE-ELIGIBILITY.md`, `TRADABLE-INSTRUMENT-UNIVERSE.md`,
  `H2-FEASIBILITY-REVIEW.md`). Unread windows there are at most development or transferability statistics.
- **Gate M3** asks for "a never-read period on the target product, or a prospective test"
  (`RESEARCH-ELIGIBILITY-GATES.md`). Only the prospective branch is open for H3.

## 2. Registry

Status: `consumed`, `consumed_equivalent`, `reserved`, `possibly_independent`, `missing`, `prospective`.
H3 use: `development_after_owner_consent`, `statistics_only_off_target`, `never`, `not_available`,
`prospective_confirmation`.

### Binance USD-M (blocked from France; CC BY-NC-SA 4.0, research only, no live-trading use)

| Id | Kind / scope | Window | Read by | Status | H3 use | Source |
|---|---|---|---|---|---|---|
| `binance-um-majors-2023-2024` | klines 1m-4h + funding, 10 majors | 2023-01 → 2024-12 | day trading, swing r1/r2, canonical campaign, v2, candle scalping, swing v2 | `consumed` | `development_after_owner_consent` | `RESEARCH-CAMPAIGN-STATUS.md` §3 |
| `binance-um-majors-2025` | same, 10 majors | 2025 | day trading, swing, campaign, v2 (validation) | `consumed` | `development_after_owner_consent` | `RESEARCH-CAMPAIGN-STATUS.md` §3 |
| `binance-um-majors-4h-2026` | 4h + funding, 10 majors | 2026-01-01 → 2026-10-09T06Z | swing campaign holdout (claim 1) | `consumed` | `development_after_owner_consent` | `../reports/research-swing-campaign-2026-10-12.md` |
| `binance-um-midcaps-2023-2025` | 1m-4h + funding, 10 mid-caps | 2023-01 → 2026-01-01 | swing v2 validation | `consumed` | `development_after_owner_consent` | `RESEARCH-CAMPAIGN-STATUS.md` §3 |
| `binance-um-midcaps-4h-2026` | 4h + funding, 10 mid-caps | 2026-01-01 → 2026-10-09T06Z | swing v2 post-hoc read (claim 2) | `consumed` | `development_after_owner_consent` | `../reports/research-swing-v2-2026-10-14.md` |
| `binance-tick-2025-9w` | aggTrades + bookDepth + funding, 20 r8 pairs | 9 weeks of 2025 (Feb → Oct) | r1-r8, q3x, Q3 V2 training | `consumed` | `development_after_owner_consent` | `RESEARCH-CAMPAIGN-STATUS.md` §3 |
| `binance-aggtrades-2025-11-12` | aggTrades, majors and mid-caps | 2025-11-10..14, 2025-12-08..12 | r3, r7 (majors' December ticks: documents disagree, counted consumed) | `consumed` | `development_after_owner_consent` | `../reports/research-midcap-tick-2026-10-13.md`, `../reports/research-execution-tick-2026-10-12.md` |
| `binance-r8b-2024-12-2025-01` | aggTrades + bookDepth, 20 pairs | 2024-12-09..13, 2025-01-13..17 | r8b holdout, validation | `consumed` | `development_after_owner_consent` | `../reports/research-book-families-2026-10-13.md` |
| `binance-bookticker-r9-train` | bookTicker + aggTrades, 18 pairs | weeks of 2023-07, 09, 11, 2024-01 | r9 training | `consumed` | `development_after_owner_consent` | `../reports/research-book-families-2026-10-13.md` |
| `binance-bookticker-r9-2024-02-03` | bookTicker, 18 pairs (their candles are consumed) | 2024-02-12..16, 2024-03-25..29 | never read (r9 stopped at training) | `possibly_independent` | `statistics_only_off_target` | `../reports/research-book-families-2026-10-13.md` |
| `binance-tick-2024-4w` | aggTrades + 1m klines + OI metrics, 20 pairs | weeks of 2024-04, 05, 06, 08 | r10-r13, leverage > 2x E0a/E1 | `consumed` | `development_after_owner_consent` | `PREREGISTRATION-leverage-gt2.md` |
| `binance-midcaps-2024-09-10` | aggTrades + OI metrics, mid-caps | 2024-09, 2024-10 | r12, r14 validation | `consumed` | `development_after_owner_consent` | `../reports/research-zone-only-2026-10-13.md`, `../reports/research-oi-buildup-2026-10-13.md` |
| `binance-q3-2024-11` | aggTrades + bookDepth + funding, 20 Q3 pairs | 2024-11-04..29 | Q3 V2 validation (#499) | `consumed` | `development_after_owner_consent` | `../reports/research-scalp-q3-r2-validation-2026-11-2024.md` |
| `binance-daily-2017-2026` | daily spot, perps + funding, top 50 | spot 2017-08 → 2023-01, perps 2020-01 → 2023-01; 2023-2026 partly read by H1 S_B (overlap unknown) | regime-r2, H1 S_B | `consumed` | `development_after_owner_consent` | `../reports/research-regime-r2-2026-10-14.md`, `RESEARCH-CAMPAIGN-STATUS.md` |
| `binance-h1-sb-listings` | 1m/1h/1d + funding, 240 listings, index, controls | events 2023-09-16 → 2026-07-03 | H1 S_B Gate 1, Gate 2 | `consumed` | `development_after_owner_consent` | `H1-SB-GATE1-RESULT.md`, `H1-GATE2-RESULT.md` |
| `binance-other-intraday-2019-2022` | intraday klines, ticks, OI outside every list above (top-50 daily paths 2020-2023 read by regime-r2) | 2019 → 2022-12-31 | unread per the documents (not exhaustive) | `possibly_independent` | `statistics_only_off_target` | `RESEARCH-CAMPAIGN-STATUS.md` §3 |
| `binance-other-intraday-2023-to-2026-10-13` | intraday klines, ticks, OI outside every list above | 2023-01-01 → 2026-10-13 | never read itself; same underlyings and period read on OKX swaps and the lists above (review R1-F7) | `consumed_equivalent` | `development_after_owner_consent` | `RESEARCH-CAMPAIGN-STATUS.md` §3, `../reports/research-swing-v2-okx-2026-10-14.md` |

### OKX global USDT swaps (not available to EEA retail; OKX market-data terms not documented)

| Id | Kind / scope | Window | Read by | Status | H3 use | Source |
|---|---|---|---|---|---|---|
| `okx-swap-swingv2-2023-2026` | 1m + funding, 193 series (363 families) | 2023-01 → 2026-10-12T16Z | swing v2 OKX (#500) | `consumed` | `development_after_owner_consent` | `../reports/research-swing-v2-okx-2026-10-14.md` |
| `okx-swap-h1-sa-listings` | 1m + funding, 83 (+13) listings | t0 → t0 + 31 d, t0 in [2023-09-01, 2026-09-01) | H1 S_A, Gate 2 | `consumed` | `development_after_owner_consent` | `H1-DATA-MANIFEST.md` |
| `okx-swap-h2-2022-2026` | 1m last price, 20 programme bases + other families (incl. first 42 days) | 2022-09 → 2026-10-06T16Z | H2 Gate 1 | `consumed` | `development_after_owner_consent` | `PREREGISTRATION-H2-GATE1.md`, `H2-CLOSURE-DECISION.md` |
| `okx-hl-captures-run14-run15` | public 24 h captures, BTC and ETH (OKX and Hyperliquid) | 2026-10-02/03 | execution study r1 | `consumed` | `development_after_owner_consent` | `RESEARCH-CAMPAIGN-STATUS.md` |
| `fwd-okx-r1-w0` | live public capture, 20 r8 pairs | 2026-10-14..16 | fwd-okx-r1 shakedown | `reserved` | `never` | `../runbooks/research-forward-okx.md` |
| `fwd-okx-r1-w1` | live public capture, 20 r8 pairs | 2026-10-19..23 | fwd-okx-r1 validation | `reserved` | `never` | `../runbooks/research-forward-okx.md` |
| `fwd-okx-r1-w2` | live public capture, 20 r8 pairs | 2026-10-26..30 | fwd-okx-r1 holdout | `reserved` | `never` | `../runbooks/research-forward-okx.md` |
| `okx-any-2026-10-14-to-2026-10-31` | any OKX price data, every product | 2026-10-14 → 2026-10-31 | H3 calendar quarantine (same paths as W0-W2) | `reserved` | `never` | this document §1 |
| `any-venue-2026-10-14-to-2026-10-31` | any crypto price data (klines, ticks, books, funding), every venue | 2026-10-14 → 2026-10-31 | quarantine widened across venues (review R1-F7) | `reserved` | `never` | this document §1, `H3-INDEPENDENT-REVIEWS.md` |

### OKX Europe X-Perps (the only execution target; M1 conditional, API access unverified)

| Id | Kind / scope | Window | Read by | Status | H3 use | Source |
|---|---|---|---|---|---|---|
| `okx-xperp-metadata` | instrument specs, tickers, one funding and one book snapshot, 160 crypto X-Perps | snapshots of 2026-10-14/15 | H1 X-Perp audit, H2 prerequisites (no returns); its prices sit inside the quarantine (review R1-F7): never used by H3, specs to be re-fetched after 2026-10-31 | `reserved` | `never` | `H1-XPERP-LISTING-INVENTORY.md`, `TRADABLE-INSTRUMENT-UNIVERSE.md` |
| `okx-xperp-history-2026-03-30-to-2026-10-12` | 1m klines (`history-candles`) + funding history, 160 crypto (29 ≥ 1 M USD/day) | 2026-03-30 (BTC; most since June-September) → 2026-10-12T16Z | never read itself; same underlyings and period read on OKX USDT swaps | `consumed_equivalent` | `development_after_owner_consent` | `H2-PREREQUISITES-REPORT.md` §4, `H1-NEXT-DECISION.md` |
| `okx-xperp-book-history` | order-book history | none | does not exist (live capture only) | `missing` | `not_available` | `TRADINGV3-EXCHANGE-COMPATIBILITY.md`, `H1-EXECUTABILITY-REVIEW.md` |
| `okx-xperp-prospective` | klines, funding, quotes captured after the H3 freeze | from 2026-11-02 (DRAFT), after the freeze | nobody (does not exist yet) | `prospective` | `prospective_confirmation` | `H1-NEXT-DECISION.md`, `RESEARCH-ELIGIBILITY-GATES.md` M3 |

### Other venues and sources

| Id | Kind / scope | Window | Read by | Status | H3 use | Source |
|---|---|---|---|---|---|---|
| `okx-europe-spot` | EUR/USDC spot pairs | unknown | nobody; access and fees unverified | `missing` | `not_available` | `TRADABLE-INSTRUMENT-UNIVERSE.md` |
| `hyperliquid-public-to-2026-10-13` | API candles, S3 books and fills | archive → 2026-10-13 | unread except run15; same underlyings and period read on OKX swaps and Binance (review R1-F7) | `consumed_equivalent` | `development_after_owner_consent` | `FRANCE-EXCHANGE-ELIGIBILITY.md` |
| `kraken-futures-public-to-2026-10-13` | public perpetuals history (EEA product since 2025-05-20) | 2025-05-20 → 2026-10-13 | unread; same underlyings and period read on OKX swaps and Binance (review R1-F7); licence and book identity unverified | `consumed_equivalent` | `development_after_owner_consent` | `H2-FEASIBILITY-REVIEW.md` |
| `coinbase-eea-history` | perpetual-style futures | since 2026-03 | unread; availability unverified | `missing` | `not_available` | `TRADABLE-INSTRUMENT-UNIVERSE.md` |
| `token-unlock-calendar` | unlock calendar (needed by CS-UNLOCK) | unknown | nobody; source and licence not chosen | `missing` | `not_available` | `H1-NEXT-DECISION.md` |
| `historical-specs-and-delistings` | historical contract specs, delisting settlement prices, listing-day books | past | not observed by H1 | `missing` | `not_available` | `H1-DATA-MANIFEST.md` |

Notes:

- The swing v2 paper forward test, H1 S_C, the Q3 V2 OKX capture and the r10 "track F" replay were planned and never
  started, or their start is unknown. None of them produced data H3 could use (`RESEARCH-CAMPAIGN-STATUS.md`).
- Missing data that matter for anti-survivorship: historical contract specifications and delisting settlement prices
  (`historical-specs-and-delistings`). Any H3 universe must be built point-in-time from listing and delisting dates
  (`walkforward.universe_at`, `survivorship_findings`).

## 3. What a prospective validation needs

The only admissible confirmation of H3 is a prospective test on X-Perps. It needs, before it starts:

1. **A frozen pre-registration** (`H3-PREREGISTRATION-DRAFT.md` made final, with its sha256), naming the candidate
   generator and its economic hypothesis, the scoring model manifest, both management policies and the risk policy,
   all fingerprinted. Nothing is fitted on prospective data.
2. **A fixed calendar window** declared before it starts: from 2026-11-02 at the earliest (DRAFT; after the
   fwd-okx-r1 verdict of 31 October), never inside 2026-10-14 → 2026-10-31.
3. **A duration set by the power studies**, not by the results.
   - The DRAFT floor is 26 weekly blocks (about six months).
   - The synthetic studies (`H3-PREREGISTRATION-DRAFT.md` §8) found no procedure that passed the acceptance rule in
     every scenario. The rule takes the maximum over many 400-panel cells, so it is itself noisy (review R1-F5): the
     104-week strong-clustering figure 0.080 has a 95 % interval of about [0.053, 0.107], and a procedure of exact
     size 0.05 fails the rule in 13 to 34 % of studies. The studies therefore do **not** establish that "about 104
     weeks" is needed; they must be re-run with Monte Carlo precision and a realistic data-generating process
     (review R1-F4) before a window is chosen. A six-month window detects only large effects.
   - From 2026-11-02, 26 weeks end in early May 2027 and 104 weeks in late October 2028.
4. **A capture pipeline** for X-Perp 1m candles, funding and top-of-book quotes, with gap and quality checks, the
   point-in-time listing and delisting record, and a dataset sha256 per capture day. It does not exist yet; the
   X-Perp instrument resolver is missing in TradingV3 (`H2-PREREQUISITES-REPORT.md` §3).
5. **Gates M1 and M2 settled** for the account: API access to X-Perps, fee tier, minimum sizes compatible with
   500 USDT at 0.5 % risk.
6. **The data licence** of OKX public market data checked for the intended use (not documented today).
7. **Paper only.** The prospective test replays the four variants on captured data in simulation. It places no
   order, demo included; any later trading decision is the owner's, separately (M5).
