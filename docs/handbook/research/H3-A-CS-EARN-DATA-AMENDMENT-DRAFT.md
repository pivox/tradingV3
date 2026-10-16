# H3-A / CS-EARN — US-equity data amendment (status `DRAFT_NOT_FROZEN`)

**Status: `DRAFT_NOT_FROZEN`. No sha256. Nothing in this page is final, and nothing is authorised by it.**

This page answers the owner's limited GO of 2026-10-16 (quoted in §0). It prepares the documents only:

- the registry rows;
- this DRAFT amendment;
- the research plan.

**What was done for this page.** Public terms, documentation and pricing pages were read on 2026-10-16, along with
the SEC's own rule and specification documents (Form 8-K, the EDGAR dissemination specification). The repository
documents listed in §12 were also read.

**What was not done.** Nothing below was done for this page:

- no price, return, volume or filing content was read or downloaded, for any venue;
- no API returning market or filing data was called;
- no account was created and nothing was bought;
- nothing was calibrated, optimised or simulated, and nothing was traded.

Every figure is either sourced or marked UNKNOWN. Where a figure comes from a secondary source (a vendor comparison
or an aggregator) and not from the vendor's own page, this page says so. Such a figure is not verified.

Companions:

- [H3-A-NEXT-DECISION.md](H3-A-NEXT-DECISION.md) (status and the owner's decision);
- [H3-DATA-ELIGIBILITY.md](H3-DATA-ELIGIBILITY.md) (registry);
- [H3-CANDIDATE-SOURCE-QUALIFICATION.md](H3-CANDIDATE-SOURCE-QUALIFICATION.md) §3.1 (CS-EARN);
- [H3-A-STATISTICAL-VALIDATION-DRAFT.md](H3-A-STATISTICAL-VALIDATION-DRAFT.md);
- [H3-LABELS-FEATURES-UNCERTAINTY-AUDIT.md](H3-LABELS-FEATURES-UNCERTAINTY-AUDIT.md);
- [H3-A-INDEPENDENT-REVIEWS.md](H3-A-INDEPENDENT-REVIEWS.md) (QR-1, QR-2, QR-3, QR-9).

## Summary

| Owner item | Answer in this DRAFT |
|---|---|
| 1. Registry, with licence, temporal provenance, earnings events and selection bias | Three rows were added: `us-equity-underlying-history`, `us-equity-underlying-evaluation-window` and `edgar-filing-metadata`. Nothing is acquired, and the code refuses every use (§1). **No free price source passes all four checks** (terms, delisted tickers, corporate actions, point-in-time ids). The survivorship-free sources are paid, or reserved to institutions (§2). EDGAR metadata is usable within the SEC's fair-access policy (§3). |
| 2. A reserved, chronologically distinct evaluation window | **Development** covers decision dates 2007-01-01 → 2019-03-31. An **embargo** follows, 2019-04-01 → 2019-06-30. The **evaluation** window, 2019-07-01 → 2025-12-31, is reserved and never read. These windows were chosen by rule (§5). The evaluation window can only test the mechanism on equities. It is **not** an independent validation of the X-Perp strategy. |
| 3. Periods, eligible issuers, anti-lookahead, EDGAR, labels, transferability | §3 to §9 |
| 4. Distinct economic justification after T | **M4 FAIL as currently specified** (§10). CS-EARN uses a distinct trigger. After T, though, it has no mechanism that holds for large caps, and its side rule is the mirror of H2. |
| 5. Equity history does not fix the X-Perp power problem | Stated in §11. No criterion is changed. |
| 6. No status change from the authorisation alone | **`SCORING_UNTESTABLE` unchanged** ([H3-A-NEXT-DECISION.md](H3-A-NEXT-DECISION.md), dated section of 2026-10-16) |

## 0. Owner decision (verbatim, 2026-10-16)

> GO limité pour la préparation documentaire des données historiques des actions américaines sous-jacentes aux
> X-Perps.
> 1. J'autorise leur inscription au registre comme données potentielles de développement, avec vérification de la
> licence, de la provenance temporelle, des événements de résultats financiers et des biais de sélection.
> 2. Je demande de réserver une fenêtre historique chronologiquement distincte, non consultée, pour une éventuelle
> évaluation hors entraînement du mécanisme sur actions américaines. Elle ne constitue PAS une validation
> indépendante de la stratégie X-Perps.
> 3. Avant toute lecture de prix ou de rendements, préparer un amendement DRAFT précisant les périodes de
> développement et d'évaluation, les émetteurs éligibles à chaque date, les règles anti-lookahead, les données EDGAR,
> les labels et les limites de transférabilité.
> 4. Vérifier que CS-EARN présente une justification économique distincte des familles H1, H2 et momentum déjà
> clôturées, particulièrement après l'instant de décision T.
> 5. Documenter explicitement que l'historique actions ne résout pas le problème de puissance statistique du test
> prospectif X-Perps. Ne pas modifier les critères simplement pour obtenir un PASS.
> 6. Ne pas déclarer SCORING_TESTABLE ou SCORING_CONDITIONAL du seul fait de cette autorisation. Réévaluer le statut
> uniquement sur la base des autres prérequis effectivement satisfaits.
> Ce GO autorise uniquement le registre, l'amendement et le plan de recherche. Aucune lecture de rendement, collecte,
> acquisition payante, calibration, optimisation, simulation historique ou opération de trading n'est autorisée à
> cette étape. Prépare les documents, puis arrête-toi pour ma validation.

## 1. Registry rows (machine-readable in `validation/registry.py`)

| Id | Status | H3 use | What `check_use` does today |
|---|---|---|---|
| `us-equity-underlying-history` | `potential_not_acquired` (new) | `development_after_licence_and_frozen_amendment` (new) | refuses every label, even with owner consent: `registry_dataset_not_acquired` |
| `us-equity-underlying-evaluation-window` | `reserved` | `mechanism_evaluation_off_target_not_xperp_validation` (new) | refuses: `registry_dataset_reserved` |
| `edgar-filing-metadata` | `potential_not_acquired` | `development_after_licence_and_frozen_amendment` | refuses: `registry_dataset_not_acquired` |

The existing semantics are kept. `independent_historical_validation()` still returns `INDEPENDENT_DATA_NOT_AVAILABLE`,
because the new rows are off the target market (`us_equity_underlying`, `sec_edgar`). Opening a row for development
needs three things this module cannot see: a chosen licence, a FROZEN version of this amendment, and the owner's go.
Opening it therefore takes a code change, reviewed like any other. The test
`test_us_equity_rows_cannot_be_read_under_the_limited_go` pins this behaviour.

**Known limit (R1-F7, still open).** The registry checks dataset **ids**. It does not check underlying × time
overlap. The window rule of §5 is therefore enforced in this document and in the claim discipline of §5.4. Code does
not enforce it until schema v2 carries time windows.

## 2. Licence review of candidate US-equity price sources (public pages only)

**What is required.** The development use needs all of the following, at least for the rule-based universe of §4:

- daily OHLC at the regular-session close;
- split and corporate-action records with their ex-dates;
- **delisted securities** with their delisting price;
- a **permanent identifier** that survives ticker changes and ticker reuse;
- a licence that allows personal research, storage of the raw files for the life of the protocol (needed for
  sha256 claims), and no redistribution;
- a cost compatible with a 500 USDT research programme.

Raw data is never committed to this public repository. Only hashes and aggregate results may appear here.

| Source | Research use (terms) | Storage / redistribution | Cost | Point-in-time quality | Acceptable? |
|---|---|---|---|---|---|
| **Yahoo Finance** (including unofficial scrapers such as yfinance) | Yahoo Terms §2(d)(ix) forbid accessing or collecting data "using any automated means", explicitly naming "robots, spiders, scrapers, data mining tools", "for any purpose without our express, prior permission". §2(e) forbids commercial reuse. | No dataset licence; reuse to build a database is forbidden (§2(d)(x)) | 0 | Delisted tickers are generally absent (secondary: r-bloggers 2021). Adjusted series are revised in place. | **No.** The terms forbid the collection, and the data is survivorship-biased. |
| **Stooq** | Own terms **not found** on a public page (search, 2026-10-16) | UNKNOWN | 0. An API key obtained through a CAPTCHA reportedly applies, with a daily quota (secondary) | UNKNOWN (delisted coverage, adjustment method) | **No**: terms and quality unknown |
| **Alpha Vantage** | EULA §2(a): a licence "for personal, non-commercial use". Use beyond "investment analysis, research, testing, monitoring" that is "private and individual" is commercial. | Storage not addressed in the clauses read. A sign-up is required to get an API key. | Free key: 25 requests a day (secondary: macroption). Premium from 49.99 USD a month (secondary, competitor page) | Delisted symbols can be listed through `LISTING_STATUS&state=delisted` (secondary). Delisted price history and the corporate-action quality are UNKNOWN. Whether the adjusted daily series is free is UNKNOWN. | **No as of today**: point-in-time quality unverified, and 25 requests a day is impractical for a universe of about 500 issuers over 19 years |
| **Tiingo** | Starter and Power plans: "Internal Use Only … you may only use the data for your own personal use and you may not display or share the data with another person or organization" (pricing page). | Personal internal use only, no sharing. Sign-up required. | Starter 0 USD: 500 unique symbols a month, 1,000 requests a day. Power: 30 USD a month or 300 USD a year (individual) | "30+ years" of price history. **Delisted coverage is not stated** on the pricing page. Corporate-action point-in-time quality UNKNOWN. | **Conditional**: the licence fits personal research. Delisting coverage is unverified, and the free tier's 500 symbols a month does not fit a rule-based universe that changes over time. |
| **Massive (formerly Polygon.io)** | Terms not read. Individual, non-professional plans (secondary). | UNKNOWN | Free Basic: end-of-day data, **2 years** of history, 5 calls a minute. Paid from 29 USD a month (secondary, checked 2026-08-21 by the aggregator) | Two years does not cover the 2007-2025 windows | **No** on the free tier. Paid tiers: terms unread. |
| **EODHD** | Terms not read. The free tier is described as personal use (secondary). | UNKNOWN | About 20 calls a day free. "All World" about 19.99 EUR a month (G2, updated 2026-02-04, secondary) | Delisted tickers are kept (vendor documentation via secondary). A **reused ticker returns the new company's data** (secondary). The permanent id is unclear. | **No as of today**: terms unread, and ticker reuse breaks point-in-time ids |
| **Sharadar (via Nasdaq Data Link)** | Terms not read. The vendor page says the data is "point-in-time ready". | UNKNOWN | **UNKNOWN** officially. About 39 USD a month personal (secondary, FitGap) | "Active and delisted coverage extends back to the 90s"; "nearly completely free of survivorship bias" (sharadar.com) | **Paid**: plausible on quality, licence unread. Needs an owner decision on acquisition. |
| **Norgate Data** | Order terms: "No commercial use - the data can only be licensed for personal use by individuals" | Personal licence. The EULA was not read. | Prices not on the page (calculator only). About 270-788 USD a year (secondary) | Platinum: daily history back to 1990, **delisted securities and historical index constituents**. Diamond: back to 1950 (norgatedata.com/subscribe). Silver and Gold exclude delisted securities. | **Paid**: plausible on quality. Needs an owner decision. |
| **CRSP** (US Stock databases, now Morningstar) | Licensed to "academic institutions, government agencies, and investment practitioners". Individual purchase is not stated. | Institutional licence | UNKNOWN (subscription) | "more than 36,000 active and inactive securities", PERMNO and PERMCO permanent ids | **Not available** to the owner as an individual, as far as the public page shows |
| **SEC EDGAR** (events, not prices) | "Information presented on sec.gov is considered public information and may be copied or further distributed … without SEC permission". The SEC asks to be cited. Its seal and logos may not be used. | Free to store and redistribute, with citation | 0 | Acceptance timestamp recorded at acceptance; amendments are separate filings (§3) | **Yes for event metadata**, within the fair-access policy (§3) |

**Finding.** For prices, **no acceptable free source exists** on the evidence of public pages:

- the free sources either forbid automated collection (Yahoo), or have unknown terms (Stooq);
- or they lack verified delisted coverage, corporate actions and permanent ids (Alpha Vantage, Tiingo free tier,
  EODHD);
- or they are too short (Massive free tier).

The survivorship-free candidates are paid personal licences or institutional ones: Sharadar, Norgate, CRSP, and
perhaps paid Tiingo after verification. A paid acquisition is **not authorised** at this step.

Cost relative to the capital: at about 300 USD a year (secondary figures), the data alone would be about 60 % of the
500 USDT reference capital. CS-UNLOCK was rejected partly because its data cost (≥ 828 USD a year) exceeded the
capital. Whether to buy is the owner's decision (§12, D-2).

Restriction applying to any source: a licence that allows "personal use" does not, by itself, allow grounding live
trading on the data. Here the data would only be used for development and mechanism evaluation, never to trade
equities. The licence text must still be read in full, the EULA included, before any acquisition (P-L1).

## 3. EDGAR: event timestamps (documented, nothing executed)

| Item | Finding | Source |
|---|---|---|
| Earnings-release form | **8-K Item 2.02** "Results of Operations and Financial Condition". It is triggered when the registrant makes "any public announcement or release … disclosing material non-public information regarding … results of operations … for a completed quarterly or annual fiscal period". The 8-K gives "the date of the announcement or release" and includes its text as an exhibit. | Form 8-K, Item 2.02(a) (current form, OMB expiry 2027-11-30) |
| Delay allowed | "Unless otherwise specified, a report is to be filed or furnished within **four business days** after occurrence of the event" | Form 8-K, General Instruction B.1 |
| Oral or webcast disclosure | No 8-K is needed for a complementary presentation held within 48 hours after a written release that was furnished on Form 8-K **before** the presentation | Item 2.02(b)(1) |
| Results first in a 10-Q or 10-K | Item 2.02 "does not apply" to disclosures made in a 10-Q or 10-K. The event is then the periodic report itself. | Item 2.02, Instruction 4 |
| Item codes in the metadata | The `<ITEMS>` header tag "identifies 1 or more items declared in 8-K filings", format `#.##` | EDGAR Public Dissemination Service specification (2014), tag definitions |
| Acceptance timestamp | "Acceptance Date … YYYYMMDD" and "Acceptance Time … Time (**EST**) at which the submission was accepted by EDGAR. Format is HHMMSS." Whether "EST" means Eastern local time, with daylight saving, or fixed UTC−5 is **UNVERIFIED**. It must be settled from the current filer manual before freeze (P-T1). | PDS specification (2014) |
| Filing date vs acceptance | EDGAR accepts filings on weekdays from 06:00 to 22:00 ET. A submission begun after **17:30 ET** is deemed filed on the next business day (Regulation S-T Rule 13(a)(2)), so the *filing date* is not the event date. **Only the acceptance date-time is used** as a timestamp. | SEC filing-hours pages and Rule 33-11159 (2023), via search; the EDGAR "filing date" field |
| Machine-readable index | `data.sec.gov/submissions/CIK##########.json`. It requires no authentication or key. It holds "at least one year's of filing or to 1,000 … of the most recent filings", with older ones in linked files. A bulk `submission.zip` is republished nightly around 03:00 ET. The per-filing field names (for example `acceptanceDateTime`, `items`) were **not verified** on a documented schema. | SEC "EDGAR Application Programming Interfaces" (last reviewed 2025-04-08) |
| Fair access | "Current max request rate: **10 requests/second**". Users must "declare your user agent in request headers" (company name and an admin contact e-mail in the SEC's example). "The SEC does not allow botnets or automated tools to crawl the site". Out-of-policy requests are managed. | SEC "Accessing EDGAR Data" (last reviewed 2024-06-26) |
| Tickers | The submissions index carries **current** tickers only, so mapping CIK to price id at date d needs the price vendor's permanent id history | SEC API page ("current name, former name … ticker symbols") |

**Timing classes (DRAFT).** Every time below is ET.

- Acceptance at or after 16:00 on session day s, or on a non-session day: **after-hours**. Day 0 is the next regular
  session.
- Acceptance before 09:30 on session day s: **pre-market**. Day 0 is s.
- Acceptance in [09:30, 16:00): **intraday**. Day 0 is s. The day-0 return then mixes pre-event and post-event
  prices. These events form a separate stratum, excluded from the primary (DRAFT).
- Early-close sessions (13:00) follow the exchange calendar.

**Acceptance date-time vs press-release time.**

- The release (newswire or website) is the first public moment.
- The 8-K may follow it by up to four business days (B.1). Its acceptance date-time is therefore an **upper bound** on
  the public time.
- Firms often furnish the 8-K the same day as the release, but the size of the gap is **UNKNOWN**. Measuring it needs
  the release date, which sits in the 8-K text and the exhibit, that is, in filing **content**. Reading that content
  is not authorised (P-T2).

**Consequence.** If the 8-K came after the release:

- using the 8-K's acceptance time can never create look-ahead, since T is later than the true public time;
- but it can place the "pre-announcement" reference close **after** the release. The side rule (the sign of the day-0
  reaction) is then mis-measured and the event is mis-timed.

DRAFT rule:

- the event time is the **earliest** acceptance date-time among the 8-K Item 2.02, 10-Q and 10-K filings for the same
  fiscal period;
- an event is "timing-uncertain" when the earliest filing is an 8-K whose acceptance falls on a later session than
  the period's first press coverage. That case can only be detected with content, so for now it is an unmeasured
  error, declared as such.

**EDGAR access rules for any later collection** (not authorised now):

- metadata endpoints only;
- at most 1 request a second (a tenth of the SEC maximum);
- a User-Agent naming the project and a role contact address, kept out of the public repository (P-E3);
- raw responses stored with a sha256 and never redistributed with personal data;
- no full-text or exhibit downloads unless a later decision allows it.

## 4. Eligible issuers at each date (point-in-time universe)

**Why not today's X-Perp list.** The 76 equity X-Perps (`instCategory = 3`) were all listed between 2026-06 and
2026-10: 20 in June, 10 in July, 14 in August, 22 in September and 10 in October (agent A's metadata snapshot,
2026-10-16). Using those underlyings historically conditions on three things:

1. **Survival to 2026.** 0 of the 44 US domestic operating companies is delisted, by construction (100 %
   survivors).
2. **2026 popularity.** OKX chose high-attention names (AI, semiconductors, crypto-linked), and a name usually becomes
   popular after large past returns. That is a look-ahead on the cross-section.
3. **Age.** By the author's knowledge, which is **not verified** (to be checked against EDGAR and exchange records,
   P-E5), about 23 of the 44 were not publicly listed for the whole proposed development window:
   - AAOI, APP, ASTS, AVGO, BE, COIN, CRCL, CRDO, CRWV, DELL (relisted 2018), FLNC, HOOD, IONQ, LITE, META, MRNA,
     OKTA, PLTR, RKLB, SNDK, TSLA, USAR, and possibly BMNR;
   - about 5 of them (CRCL, CRWV, SNDK, USAR, BMNR) only listed in 2025.

   A historical panel of "X-Perp underlyings" would therefore be thin and young early on, and made of later winners.

The bias cannot be measured without reading returns. It is declared as **high** and handled by design rather than by
correction.

**DRAFT rule-based universe U(d)** (defined independently of the X-Perp list). An issuer is eligible for an event with
day 0 = d only if every condition holds with information available at the close of d − 1:

1. **Filer.** The issuer is a US domestic filer of 10-Q and 10-K, as recorded in EDGAR metadata with acceptance before
   d. Foreign private issuers (20-F / 6-K) are excluded, since their results cadence differs. The 8 non-US X-Perp
   underlyings therefore fall out by rule, not by choice.
2. **Security.** The security is common stock on NYSE or Nasdaq, per the vendor's security master as of d. ETFs,
   ADRs, funds, SPAC shells before their business combination, preferreds and warrants are excluded.
3. **History.** There are at least 252 regular sessions of price history before d. This also removes the
   IPO-age effect, which is the equity analogue of H1's listing age.
4. **Liquidity rank.** The issuer is in the top **N = 500** by median daily dollar volume (close × volume,
   unadjusted) over the 63 sessions ending at the last session of the previous calendar quarter. The rank is
   recomputed each quarter and uses only data dated before the quarter starts.
   - N = 500 is chosen a priori to approximate the large-cap, high-attention segment the X-Perp underlyings belong
     to.
   - It is not tuned. A sensitivity run at N = 1,000 is reported, never decisive.
   - Computing the rank is a read of prices, so it happens only inside the development window after the freeze. In
     the evaluation window it happens only under the evaluation claim (§5.4).
5. **Identity.** A permanent id is resolvable at d. A reused ticker never links two companies.
6. **Crypto-coupled stratum** (open, owner decision D-4). The BTC price paths of 2017-2026 are consumed
   (`binance-daily-2017-2026`, OKX swaps 2023-2026). The issuers whose returns are dominated by crypto (for example
   miners, treasury companies, exchanges) therefore partly share consumed paths. DRAFT: a list or rule, declared
   **before** any read, excludes them from the primary in both windows.

**The X-Perp list is used only later**, as a reported subset: the events of the issuers in today's 44 US domestic
X-Perp underlyings that are also in U(d). That subset is labelled "hindsight-selected", reported and never decisive.

Expected scale, from arithmetic and not from data (an upper bound from the issuer count, events not counted):

- 500 issuers × 4 events a year ≈ **2,000 events a year**;
- about 24,500 in development (12.25 years);
- about 13,000 in evaluation (6.5 years).

This is an equity sample. It says nothing about X-Perp frequency (§11).

## 5. Development and evaluation periods

### 5.1 Rule (stated before any read)

| Element | Rule | Value (DRAFT) |
|---|---|---|
| Start of the eligible span | First full calendar year after the regime break that Martineau (2022) reports for large stocks ("non-existent since 2006"). It is also after the 8-K Item 2.02 numbering (2004, author's knowledge). **Declared:** this rule comes from the literature, not from our data. It conditions the test on the regime where the literature finds no large-cap drift, which works against a PASS. | 2007-01-01 |
| End of the eligible span | The last full calendar year before the first equity X-Perp listing (2026-06). Later equity paths are the target product's own history, and from 2026-11-02 they are the prospective confirmation's paths. | 2025-12-31 |
| Evaluation share | The last third of the span (19 years → 6.33 years), with its start rounded down to a calendar-quarter start | 2019-07-01 → 2025-12-31 (6.5 years) |
| Embargo | The calendar quarter just before the evaluation start: about 63 sessions, more than the 5-session holding and more than any label horizon | 2019-04-01 → 2019-06-30, in neither window |
| Development | From the start of the span to the start of the embargo | 2007-01-01 → 2019-03-31 (12.25 years) |
| Purge | A development event whose label horizon (T + 5 sessions) ends after 2019-03-31 is dropped. No parameter, normalisation or universe rank fitted on development may use a bar dated after 2019-03-31. | rule |
| Window membership | An event belongs to the window that contains its **day 0**. Features may read earlier bars, from the past window. Labels may never cross into the next window. | rule |

### 5.2 Caveats declared now

- **Same issuers in both windows.** Equities are a fixed population, so the windows are distinct in time, not in
  issuers. Issuer-level effects estimated in development carry over. The evaluation must cluster by issuer
  (DRAFT §5 of the validation draft).
- **The literature has consumed both windows.** PEAD is among the most studied anomalies. Martineau's sample covers
  the development era. Subrahmanyam's replication (2025 working paper) runs from February 2001 to **December 2024**,
  across almost all of the evaluation window. The evaluation window is unread **by this programme**, not unknown to
  research. A result there is an out-of-training evaluation of a pre-specified mechanism, **not** a discovery and not
  an independent confirmation.
- **Regime contents.** The evaluation window contains 2020 (COVID) and the 2021 retail and crypto episode. No window
  was moved to include or avoid them: the rule above fixes the windows.

### 5.3 What "consulted" means

The evaluation window is **consulted** by any operation that, for any decision date in [2019-07-01, 2025-12-31],
loads into memory, prints, plots, summarises or tests any of the following:

- a price, a return, a volume, a dollar-volume rank or a volatility;
- any statistic that depends on them, including data-quality checks that compute returns, universe counts after the
  liquidity rank, and gap counts.

The same applies to any equity price dated from 2026-06-01, which no window covers.

**Not** consulting:

- vendor file names, file sizes and the sha256 of archives as received;
- the EDGAR event metadata (timestamps, form types, item codes), once collection is approved. That metadata carries
  no outcome and may serve event counts and power studies, but not outcome-conditioned counts.

Where a vendor allows it, the evaluation period is delivered as separate files. They are hashed on arrival and never
opened until the claim. If a vendor cannot split by date, the split script reads the **date column only**. That
script is part of the frozen code digest, and its log is kept.

### 5.4 Claim / consume discipline (same as H1 Gate 2 and H2 Gate 1)

1. **Freeze.** Freeze this amendment and the mechanism protocol, with the body sha256 and the code digest, before any
   development read. Write a development claim (protocol sha256, code digest, dataset sha256, window) before the
   first development read.
2. **Evaluation claim.** It is written **once**, after a separate owner go. It records the protocol sha256, the frozen
   model or score manifest sha256 (trained on development only), the code digest and the sha256 of the sealed
   evaluation files. The claim file is created exclusively (never overwritten), and its sha256 goes into the
   consumption record.
3. **Trial ledger.** The ledger records the evaluation read as confirmatory on that `dataset_sha256`
   (`trial_ledger`, CTO-01). Any second read, under any label or protocol, is refused (`ledger_confirmatory_data_already_used`).
   A failed or aborted run consumes the window anyway. There is no rerun after an outcome has been seen.
4. **No tuning after the read.** After the read, the window becomes `consumed` in the registry, whatever the result.
   No amendment written after the read can be confirmatory on it (same rule as `H2-CLOSURE-DECISION.md` §0 and §4).
5. **Anchor.** The ledger head is anchored outside the file (an open CTO-01 item) before the evaluation claim.

## 6. Anti-lookahead rules (DRAFT)

1. **Decision time.** T is the regular-session close of day 0 (§3 timing classes), with day 0 taken from the
   **earliest acceptance date-time** of the period's filings. No information time-stamped after T enters a feature,
   a filter or the side.
2. **Closed bars only.** Features read only daily bars of sessions that closed at or before T. The day-0 bar is used
   only through its close at T.
3. **Side rule input.** The reaction is the market-adjusted return from the last regular close **before** the event
   time to T. The market is a broad index ETF series of the same vendor; the CS-EARN X-Perp spec uses the SPY or QQQ
   X-Perp. The beta is estimated only on sessions ending before day 0.
4. **Corporate actions.** Only actions with an ex-date ≤ the bar's date adjust that bar. Labels use split-adjusted
   prices and **no dividend adjustment**, because the X-Perp pays no dividends (OKX X-Perp page). Unadjusted closes
   are kept for the liquidity rank.
5. **Fundamentals.** No restated or vendor "as-latest" fundamental is used. If an earnings-surprise feature is ever
   proposed, it must come from a point-in-time source with an as-of timestamp ≤ T. There is none today, so such
   features are excluded.
6. **Universe.** U(d) is computed only from data dated before the quarter start (liquidity) and EDGAR metadata
   accepted before d (§4).
7. **Delistings and halts** are known only when they happen (§7). No event is filtered on a later delisting.
8. **Calendars.** The exchange holiday and early-close calendar is fixed and versioned before the freeze. EDGAR's
   "EST" label is resolved first (P-T1).

## 7. Labels (equity adaptation of C's reference label)

**DRAFT id `h3.reference_static_exit_label/1-equity-daily`.** It is a new version, never mixed with the X-Perp label.

| Element | Equity daily version |
|---|---|
| Reference | t0 = T (the close of day 0). Reference price = the official close at T, unadjusted. |
| Side | From the side rule (§6.3). The label itself reads no score and no fill. |
| Stop | d = clamp(2 · σ_daily · √5, 4 %, 15 %), as in the CS-EARN spec. σ comes from a fixed number of sessions ending at day −1 (DRAFT 60), never from bars after the event time. |
| Path | Daily bars of sessions 1 to 5 after day 0 |
| Gap at the open | An open through the stop exits at the open (R < −1), as in C's label. Overnight and weekend gaps are the norm here. On the 24/7 X-Perp they are replaced by off-hours trading, the ±10 % band and funding (§8). |
| Intrabar | Stop first when both stop and target are touched (`ambiguous_bar`). There is no target in the DRAFT CS-EARN spec, so only the stop and the time exit apply. |
| Time exit | The close of session 5 |
| Unit | Gross R = side · (exit − reference) / (stop distance × reference). There are no costs in the label; costs enter only the portfolio comparisons. |

**Unlabelled reasons**, each reported in the census (QR-8). An event is unlabelled in four cases:

1. `no_price_at_T`: no regular-session close on day 0 (a halt over the whole session). The cause is known at T, but
   it is data coverage.
2. `price_path_gap`: a session with no bar inside 1-5. This is data coverage, and it may be price-correlated: halts
   follow news. It is counted apart and makes outcome independence unestablished.
3. **Delisting within the horizon.** If the vendor provides a delisting price, the event exits there and stays
   labelled. Otherwise it is an **outcome-dependent** exclusion, which disqualifies the source (§2).
4. **Merger completion or symbol change within the horizon.** It is followed through the permanent id. If the id
   cannot be followed, the event is unlabelled and counted.

A path that ends before the horizon (the window end) is a calendar rule. It is decided by T plus 5 sessions against
the purge rule of §5.1, never by the path.

LULD intraday halts and sub-day ordering are invisible in daily bars. The label is the idealised outcome of a fill at
the close of T. It is not a claim that such a fill can be achieved on an X-Perp.

## 8. The transferability gap to OKX Europe X-Perps

| Dimension | US equity (development data) | Equity X-Perp (target) | Effect on transfer |
|---|---|---|---|
| Trading hours | Regular session 09:30-16:00 ET; overnight and weekend gaps | 24/7. Off-hours the index "leans on OKX's own contract price" and on other venues' stock-perp indices (OKX X-Perp page, updated 2026-08-05) | Path between closes differs. A stop can fire off-hours on the X-Perp where the equity would gap through it at the open. |
| Price limit | LULD bands, halts | Index limited to **±10 %** of the last stock price; bands are adjustable | A large post-T move can be held back, or released at the reopen. The X-Perp path differs from the stock's. |
| Funding | none | Funding interval "varies by product"; rates UNKNOWN (P-E4) | A carry cost or gain absent from the equity label |
| Fees | commissions and spread (not modelled) | 2 bp maker / 5 bp taker (owner's account interface, `H1-XPERP-ACCOUNT-CHECK.md`) | Only the portfolio comparison sees it |
| Margin and accounting | USD cash equity | **USDC** margin. TradingV3 assumes USDT (technical gap). | Execution-side only |
| Dividends | Ex-dividend drop in the price | "No dividends" on the X-Perp | Price-return label (§6.4) |
| Shorting | Borrow constraints and costs; some names hard to borrow | Symmetric | The equity short side is not implementable in the same way, so the literature's short-side returns may not transfer |
| Size | none at this scale | 500 USDT, 0.5 % risk = 2.50 USDT, `minSz` per instrument (P-E4) | Minimum-size exclusions appear only on X-Perps |
| Universe | 500 rule-based issuers | 44 US domestic underlyings, listed 2026 | The X-Perp set is a hindsight subset of the largest, most-covered names: the segment where the literature finds the weakest drift |
| Liquidity | deep | UNKNOWN (M2, no ticker read) | Fill and slippage assumptions do not transfer |

**What an equity result can say:**

- whether, on US large caps after 2006, the post-T 5-session return in the direction of the day-0 reaction differs
  from a matched control, under a frozen protocol (the mechanism);
- whether a scorer trained on development events ranks evaluation events (A1 on the equity label).

**What it cannot say:**

- anything about the X-Perp's off-hours path, its band, funding, fills, fees or liquidity;
- whether B1 beats BR on X-Perps (A2), and whether a portfolio is profitable at 500 USDT;
- anything that satisfies M3's "independent validation data" or M5's "forward or Paper on new data of the target
  product".

An equity result is **never** an independent validation of the X-Perp strategy, and never confirmatory for H3-A on
the target.

## 9. Research plan (nothing launched; each step needs the owner's go)

| Step | Content | Needs |
|---|---|---|
| E0 | This DRAFT reviewed; owner decisions D-1 to D-8 (§12) | owner |
| E1 | Licence: read the full EULA of the chosen vendor; record the price and terms | owner choice of vendor (D-2) |
| E2 | Freeze this amendment and a mechanism protocol (E-M4: primary statistic, matched control, gates, power on equity event counts); sha256 | owner review |
| E3 | EDGAR metadata collection under fair access (§3), for both windows' events. Metadata only. | collection approval (D-5) |
| E4 | Development acquisition and claim, then a development read: universe, labels, E-M4 on development, an optional scorer trained on development | paid acquisition (D-2), go |
| E5 | Evaluation claim and a single read (§5.4) | a separate owner go after E4 |
| E6 | Whatever E5 shows, the X-Perp questions (M1/M2/M5, A2, power) remain open and prospective only | — |

Expected value, stated before any read: given the large-cap literature (§10), the most likely E-M4 outcome on
development is **no drift**. If that happens, CS-EARN closes, and the evaluation window stays unread and reusable for
another hypothesis, with this programme's exposure declared.

## 10. M4 — economic justification of CS-EARN after T (owner item 4)

**Mechanisms proposed for after T:**

- under-reaction to earnings news (Bernard & Thomas 1989; Chan, Jegadeesh & Lakonishok 1996, which also separates
  earnings momentum from price momentum);
- limited attention: slower response on high-distraction days and on Fridays. This is the author's knowledge of
  Hirshleifer, Lim & Teoh 2009 and DellaVigna & Pollet 2009, **not re-read** for this page;
- gradual analyst revisions;
- institutional constraints that delay rebalancing (a hypothesis with no source).

**Evidence for persistence in the relevant segment:**

- Martineau (2022, *Critical Finance Review* 11, 613-646): prices now absorb earnings surprises on the announcement
  day; "for large stocks, PEAD have been non-existent since 2006".
- Two 2025 papers dispute this (UCLA Anderson Review brief, 2026-01-21):
  - Dickerson, Julliard & Mueller, *JFE*, in press: an earnings factor that is not standard, built from abnormal
    returns around announcements;
  - Hirshleifer, Peng & Wang, *RFS* 38(3), 2025: drift with t ≈ 14, weaker under higher social connectedness.
- Subrahmanyam (2025 working paper) replicates the earnings factor on US data from 2001-02 to 2024-12: t = 2.18 with
  all stocks, **t = 1.43 without microcaps** (below the 20th NYSE size percentile). He reads the second paper's
  effect as a size effect.
- The X-Perp underlyings are among the largest and most-covered US names: the segment where the evidence for drift
  after T is weakest.

**Venue-specific argument.** The ±10 % band and off-hours price discovery act **between the announcement and T**,
since T is the day-0 close. They are therefore spent before the trade starts (QR-3). Moving T earlier, into the
off-hours window, would turn CS-EARN into CS-REOPEN, which was rejected at M4 (circular off-hours reference, close to
H2 and the reversion families).

**Distinction from the closed families:**

| Family | Overlap | Distinct? |
|---|---|---|
| H1, post-listing drift (crypto, short, t0 → t0 + 31 d) | Different trigger: a scheduled fundamental event, not listing age. The equity universe needs ≥ 252 sessions of history, which removes listing-age effects. On X-Perps, though, every first event falls within months of the X-Perp listing. DRAFT: X-Perp events within 31 days of `listTime` are excluded from the prospective candidates, or kept as a separate stratum. | **Yes**, by trigger and by rule |
| H2, idiosyncratic residual-shock **reversal** over 3 days (crypto, closed `H2_GATE1_FAIL_CLOSED`) | CS-EARN's side rule (the sign of the market-adjusted day-0 move, then hold 5 sessions) is short-horizon **continuation** of a residual move: H2's mirror. **Declared:** the authors of CS-EARN and of this page saw H2's results, including the long side at −177.3 bp [−376.6; −4.9]. H2 §4 forbids reading that side inverted as continuation or momentum. No H2 number, horizon, threshold or side is used here, and H2's outputs never count as support. | **Only through the trigger.** The trade, conditional on the trigger, is the closed family's mirror. |
| Momentum: XMOM (abandoned), swing breakouts (closed), `xs_rel_ret_lb` | Continuation of a recent move. CJL 1996 separate earnings momentum from price momentum, but at 6-12-month horizons, not 5 sessions. The plausible CS-EARN features at T (pre-event drift, reaction size, same-day market move, volume surprise) meet closed families (QR-9). | **Partly**, by trigger only |

**Verdict: M4 FAIL for CS-EARN as currently specified.** As specified, CS-EARN means T at the day-0 close, an
unconditional sign rule, a 5-session hold and the large-cap X-Perp underlyings. The reasons:

1. No mechanism specific to the venue acts after T. The only one proposed is spent before T.
2. The generic mechanism, for the target's size segment and period, rests on contested evidence. The most direct
   large-cap tests are negative: Martineau since 2006, and Subrahmanyam t = 1.43 without microcaps through 2024. The
   positive 2025 results come from samples or factor constructions that include microcaps.
3. Gate M4 asks for "a real difference from the invalidated families". The trigger is different. The trade
   conditional on it is short-horizon residual continuation, the mirror of the closed H2, proposed after H2 was seen.

**What this verdict does not say:**

- that no earnings effect exists anywhere: microcaps, other horizons and other markets are untested and out of scope;
- that it was tuned on H2: nothing was taken from H2's numbers.

**What could move it.** Only the empirical route QR-3 named would move it to CONDITIONAL: a frozen, development-only
E-M4 check on US large caps after 2006 (§9, E2-E4) showing drift against a matched control, with a negative prior
declared. That route needs a paid licence and the owner's go. Reformulating CS-EARN to escape this verdict, for
example by changing T, the side rule or the size segment after reading this page, would be a new hypothesis with its
own M4 dossier and its own declaration of what was seen.

## 11. Power: equity history does not fix the X-Perp problem (owner item 5)

The H3-A confirmation can only be prospective, on X-Perps captured after a freeze (`H3-DATA-ELIGIBILITY.md` §3;
gate M3). Its power is set by the X-Perp event rate, not by the size of any historical sample:

- **Stream.** At most 176-208 events a year, from the issuer count, not counted (agent A, §3.1).
- **Primary.** On a CS-EARN-like synthetic stream at IC 0.15, the A1 ∧ A2 ∧ A3 primary has power **0.155 at 2 years
  and 0.23 at 4 years** (QR-1, reproduced bit-identically for cell E15_104).
- **Components.** A1 alone has power 0.49 / 0.745. It reaches 0.8 at about **4.7 years**. A2 is at 0.23 / 0.30 and
  reaches 0.8 at about **20 years**, because B1 executes about 28 trades a year (53 % of its acceptances) with
  2 slots.
- **At IC 0.10.** The primary has power 0.17 at 4 years.

**What equity history can change:**

- the **training** obstacle (QR-2): a scorer can be fitted and frozen on development equities instead of on a first
  prospective window. That removes the "about twice the horizon" option, not the horizon itself;
- the **M4 evidence**: a mechanism check (§10).

**What equity history cannot change:**

- the number of X-Perp events a year;
- capacity (2 slots, 500 USDT);
- the dependence structure of the X-Perp stream;
- therefore the horizon of A1, of A2 and of the primary on the target.

The roughly 13,000 equity evaluation events of §4 measure the mechanism on equities. They add **zero** effective
observations to the X-Perp test.

**Criteria are unchanged.** None of these may be relaxed to reach a PASS:

- the DRAFT primary, the power requirement of 0.8 at the owner's minimum IC, and the stability guard;
- `h3a_non_rejection_underpowered`;
- the gates M1-M5.

An equity result may not substitute for any X-Perp criterion, and may not be pooled with X-Perp events.

## 12. Open items and owner decisions

Prerequisites created or carried by this page (none satisfied):

| # | Prerequisite |
|---|---|
| P-L1 | The chosen vendor's full licence and EULA read: research use, storage for the life of the protocol, no redistribution, price |
| P-T1 | EDGAR "EST" acceptance time: Eastern local time with daylight saving, or fixed? Resolved from the current filer manual |
| P-T2 | Whether the 8-K text and exhibit (release date) may be read, to measure the release-to-acceptance gap |
| P-E3 | EDGAR fair access: User-Agent identity (a role contact, kept out of the public repository), ≤ 1 request a second, metadata endpoints only |
| P-E5 | Issuer classification (44 / 8 / 9 / 15) and listing dates checked against EDGAR and exchange records |
| P-U1 | The crypto-coupled exclusion list or rule, fixed before any read |
| P-F1 | Freeze of this amendment and of E-M4 (sha256) |

Owner decisions:

- **D-1.** Given M4 FAIL as specified (§10), choose one:
  - (a) stop CS-EARN here (H3-A stays `SCORING_UNTESTABLE`; route R-3 or stop);
  - (b) keep the E-M4 check as the only route to CONDITIONAL, accepting a negative prior and a paid licence;
  - (c) ask for a new, separately declared hypothesis.
- **D-2.** Whether to authorise a paid acquisition: which vendor (Sharadar, Norgate or paid Tiingo after P-L1), and
  what budget. Context: about 300 USD a year is about 60 % of the 500 USDT capital (secondary figures).
- **D-3.** Approve the window rule (§5.1) and the universe rule (§4: N = 500, liquidity metric, 252-session history).
- **D-4.** The crypto-coupled stratum (§4, item 6).
- **D-5.** EDGAR metadata collection (E3), under fair access.
- **D-6.** The timing rule (§3; P-T2).
- **D-7.** Freeze E-M4 and this amendment before any read (E2).
- **D-8.** Unchanged from before:
  - M1 (TradFi X-Perp API);
  - M2 (a liquidity screen after 2026-10-31);
  - P-E6 (minimum useful IC and an accepted horizon, knowing §11).

## 13. Sources (read 2026-10-16; public pages only)

**SEC:**

- "Accessing EDGAR Data", sec.gov/search-filings/edgar-search-assistance/accessing-edgar-data (last reviewed
  2024-06-26);
- "Privacy and information security", sec.gov/about/privacy-information (website dissemination, last reviewed
  2023-11-29);
- "EDGAR Application Programming Interfaces", sec.gov/search-filings/edgar-application-programming-interfaces (last
  reviewed 2025-04-08);
- Form 8-K and its General Instructions, sec.gov/files/form8-k.pdf (OMB expiry 2027-11-30);
- EDGAR PDS Dissemination Specification, sec.gov/files/info/edgar/specifications/pds-dissemination-spec070114.pdf;
- Rule 33-11159 (2023) and the SEC filing-hours pages, for 06:00-22:00 ET and the 17:30 rule (through search).

**Vendors:**

- Yahoo Terms of Service, legal.yahoo.com/us/en/yahoo/terms/otos (§2(d)(ix), §2(d)(x), §2(e));
- Yahoo Developer API terms (§ storage, 24 h);
- Alpha Vantage terms of service (EULA §2(a));
- Tiingo pricing, tiingo.com/about/pricing;
- Norgate Data subscribe page, norgatedata.com/subscribe;
- sharadar.com;
- CRSP US Stock databases (indexes.morningstar.com/research-data-products/crsp-us-stock-databases).

**Secondary, not verified:** macroption (Alpha Vantage limits), the qveris and daytradingz aggregators (Massive),
G2 and the incoherency.co.uk notes (EODHD), FitGap and MarketParquet (Sharadar, Norgate), r-bloggers 2021 (Yahoo
delisted tickers).

**OKX:** okx.com/en-eu/help/tradfi-x-perps-eea (updated 2026-08-05), as cited in agent A's qualification.

**Literature:**

- Martineau (2022), *CFR* 11:613-646, doi 10.1561/104.00000122 (abstract via RePEc);
- UCLA Anderson Review, "Is post-earnings announcement drift a thing again?" (2026-01-21), for Dickerson, Julliard &
  Mueller (*JFE*, in press), Hirshleifer, Peng & Wang (*RFS* 38(3), 2025) and Subrahmanyam (2025 working paper);
- Bernard & Thomas (1989);
- Chan, Jegadeesh & Lakonishok (1996).

**Repository:**

- `H3-A-NEXT-DECISION.md`, `H3-A-INDEPENDENT-REVIEWS.md`, `H3-CANDIDATE-SOURCE-QUALIFICATION.md`,
  `H3-A-STATISTICAL-VALIDATION-DRAFT.md`, `H3-LABELS-FEATURES-UNCERTAINTY-AUDIT.md`;
- `H3-DATA-ELIGIBILITY.md`, `H3-CONTRACTS-V2-DESIGN.md`, `RESEARCH-ELIGIBILITY-GATES.md`;
- `H2-CLOSURE-DECISION.md`, `H1-GATE2-RESULT.md`, `RESEARCH-CAMPAIGN-STATUS.md`, `H1-XPERP-ACCOUNT-CHECK.md`.

**Private notes:** `tradingV3-private/research/20261016T0900Z-h3a-cs-earn-plan/` (the list of pages read and the SEC
rule and specification PDFs, with `SHA256SUMS`; no market or filing data).
