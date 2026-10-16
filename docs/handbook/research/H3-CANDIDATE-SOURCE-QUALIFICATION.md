# H3-A — candidate-source qualification (2026-10-16)

**Verdict: `SOURCE_CONDITIONAL`.** None of the three existing sources (CS-FUND, CS-UNLOCK, CS-XMOM) can feed a
scoring test. One new source, **CS-EARN** (earnings announcements on the equity X-Perps), passes the desk checks that
metadata can settle. It is not qualified: market access, liquidity, the data licence and the persistence of its
mechanism are all unverified. If the market-access check (M1) or the liquidity check (M2) fails, the verdict falls to
`NO_DEFENSIBLE_SOURCE`.

**Scope and rules.** This is a desk study. No price, return, candle, ticker, funding history, order book or trade
was read, and no model, simulation or backtest was run. The only network reads were:

- one unauthenticated instrument list from `eea.okx.com` (`public/instruments?instType=FUTURES`, 2026-10-16);
- official OKX help pages;
- public literature pages.

Every frequency below comes from instrument metadata, regulation or public event counts, never from returns. Any
quantity not established is marked UNKNOWN. Nothing here is a performance forecast, and no edge is claimed.

Private notes, the instrument snapshot and the sample-size script, with `SHA256SUMS`, are in
`tradingV3-private/research/20261016T0500Z-h3a-testability/a-sources/`.

## 1. What a scoring test needs (a-priori arithmetic, stated for reconciliation with agent B)

The owner's indicative target of 5 candidates a week is checked here against the number of independent labelled
candidates a scoring test needs. This is closed-form arithmetic. It runs no simulation and has no seed.

**Assumptions** (each is a convention, not a measurement):

- A1. One-sided α = 0.05 and power 0.80, so K = (1.645 + 0.842)² = 6.18.
- A2. The labels are capacity-free: each candidate is replayed alone (`integration/label_replay.py`). The sample is
  therefore the **labelable candidates, not the trades**.
- A3. Score and outcome R are roughly bivariate normal with correlation IC. Stop-bounded R is not normal, so this is a
  first-order approximation.
- A4. Dependence enters through a design effect DE = 1 + (m − 1)ρ:
  - m is the number of candidates sharing a time block;
  - ρ is their residual outcome correlation;
  - n_nominal = DE × n_eff.
  - DE ∈ {1, 1.5, 2} is shown. Its real value is UNKNOWN.
- A5. A plausible IC is UNKNOWN for every source. The active-management convention, under which an IC of about 0.05
  is already useful (Grinold & Kahn, *Active Portfolio Management*, 2nd ed., 2000), is only a scale. It is not
  evidence for any source.
- A6. The score is **pre-specified and not fitted**. If a model has to be trained, the training sample comes on top,
  and on X-Perps it can only be prospective too (`H3-DATA-ELIGIBILITY.md`). Every duration below would then roughly
  double.

**Rank-IC test** (H0: IC = 0, with SE ≈ 1/√(n − 1)): n_eff = K / IC² + 1.

**B1 against BR.** BR is random abstention at B1's acceptance share a, averaged over the seeds, so its expectation is
the pool mean. The test compares B1's accepted mean with the pool mean on the same candidates:

- n_eff = K · a(1 − a) / (IC² · φ(z_a)²);
- B1's gain per accepted candidate over BR = IC · σ_R · φ(z_a) / a.

| IC (assumed) | rank-IC n_eff | B1 vs BR, a = 0.5 | a = 0.3 | a = 0.2 |
|---:|---:|---:|---:|---:|
| 0.05 | 2,475 | 3,885 | 4,296 | 5,049 |
| 0.10 | 620 | 972 | 1,074 | 1,263 |
| 0.15 | 276 | 432 | 478 | 561 |
| 0.20 | 156 | 243 | 269 | 316 |
| 0.30 | 70 | 108 | 120 | 141 |

The B1 gain per accepted candidate is 0.80, 1.16 and 1.40 × IC · σ_R for a = 0.5, 0.3 and 0.2. With σ_R ≈ 1 R, an
IC of 0.10 is therefore about +0.08 to +0.14 R per accepted trade over random abstention.

These figures are lower bounds:

- B1 and BR run as capacity-limited portfolios see fewer trades than this.
- The economic test against cash is separate.

**Years of prospective capture needed for the rank-IC test** = n_eff × DE / (candidates a year):

| Labelable candidates a year | DE | IC 0.05 | IC 0.10 | IC 0.15 | IC 0.20 |
|---:|---:|---:|---:|---:|---:|
| 60 (CS-UNLOCK if E = 5 a month) | 1.0 / 1.5 | 41 / 62 | 10.3 / 15.5 | 4.6 / 6.9 | 2.6 / 3.9 |
| 176–208 (CS-EARN, §3) | 1.0 / 1.5 | 12–14 / 18–21 | 3.0–3.5 / 4.5–5.3 | 1.3–1.6 / 2.0–2.4 | 0.7–0.9 / 1.1–1.3 |
| 260 (5 a week) | 1.0 / 1.5 | 9.5 / 14.3 | 2.4 / 3.6 | 1.1 / 1.6 | 0.6 / 0.9 |

**Consequence.** Five candidates a week is enough only if the owner accepts a minimum detectable IC of about 0.15 over
1 to 2 years. A target of IC 0.10 needs 2.4 to 3.6 years even at 5 a week. Frequency is a necessary condition, not a
sufficient one. A frequent source with no mechanism only adds dependent noise, so it raises DE and buys nothing.

## 2. The three existing sources (A1)

### 2.1 CS-FUND (hedged funding carry): incompatible with directional scoring. Confirmed.

| Check | Finding | Evidence |
|---|---|---|
| Contract fit | `h3.opportunity_candidate/1` carries one header side, one snapshot (one instrument), a protective `initial_stop_price` and a `max_holding_ms` (`contracts.py`, `OpportunityCandidate._validate`). CS-FUND is a short X-Perp plus a long spot of the same asset: two legs that cannot be separated. | code read |
| Stop | A price stop on one leg would unhedge the position and turn it into a directional bet. The only stop CS-FUND has is a cumulative-PnL cut, which is not a price stop. | CS-FUND draft §3 |
| Count | 2 positions (BTC, ETH) for 12 months: 2 candidates in total, so a rank-IC is undefined. The 27-asset arm B is descriptive. | CS-FUND draft §2, §3 |
| Reframing per settlement (about 1,095 "hold or not" decisions a year per asset at 8 h) | This would not score the carry. It would be a new funding-timing hypothesis, close to the closed regime detector D4 ("contrarian funding", regime-r2) and to OI positioning (r13/r14). Its decisions are serially dependent, because funding regimes persist. | landscape rows 14, 17 |
| Nature | The flow is mostly contractual: F = I whenever the premium stays in the dead band. A PASS would measure a yield, not an edge. | CS-FUND draft §1.2 |

**Status for H3-A: NOT A SCORING SOURCE.** It can be measured on its own, outside the factorial, as the dossiers
proposed. It must not be turned into a single directional candidate.

### 2.2 CS-UNLOCK (insider cliffs): funnel separated as the owner asked; fails M3 and fails the scoring frequency

| Stage | Definition | Value | Source / status |
|---|---|---|---|
| Calendar events | every scheduled unlock (all categories, linear and cliff) on any token | UNKNOWN | no licensed point-in-time calendar was read (M3). Vendor research cites about 16,000 events over 40 tokens over several years, not a rate for our universe |
| Raw candidates | insider cliff ≥ 1 % of circulating supply, exact date, not "inferred" (draft §3) | UNKNOWN | needs the provider's cliff/linear field (P0.2) |
| Distinct candidates | at most one per token per 60 days (draft §3) | ≤ 6 per token a year (rule ceiling) | arithmetic |
| On the universe | crypto X-Perp, ≥ 60 days since `listTime`, ADV30 ≥ 100 k USD | bases ≥ 60 days: 86 (2026-10-15), 155 (2026-12-01), 160 (2027-01-01). ADV coverage UNKNOWN | `listTime` metadata (dossier H3-B). Of 9 tokens named in selection-biased vendor digests, 5 are X-Perp bases |
| Labelable | in the snapshot ≤ T − 14 d, with complete prospective data T − 37 d → T + 2 d after a run-in of ≥ 45 days, intention to treat | = eligible events E a month; **E UNKNOWN** | draft §2, §8 |
| Executable | sizing at d = clamp(8 %, 20 %) above the venue minimum (ENA excluded at 20 %) | E minus a few minimum-size exclusions | draft §6 |
| Possible trades | 2 slots × 9-day holds | ≤ 81 a year (ceiling); ≈ 40 a year at E = 5 (Erlang upper bound, clustering ignored) | draft §9.2 |
| Independent observations | clustered by ISO week and by start of month / anniversaries | < labelable count; DE UNKNOWN | draft §9.2 |

One public count bounds the optimism. Kim (SSRN 6632838, preliminary, read through a search summary only) uses
**52 Binance unlock events over 2023–2025**, about 1.4 a month, under selection criteria we do not know. E = 5 a
month on a smaller and younger X-Perp universe is therefore an optimistic assumption, not a verified one.

**For scoring, the count that matters is labelable candidates**, not the "40 trades". Even at E = 5 that is 60 a year,
and the rank-IC test needs 2.6 years at IC 0.20 and 10 years at IC 0.10, before any dependence (§1). The source also
fails M3: no licensed point-in-time calendar exists, storage needs the provider's written consent (Tokenomist API
terms cl. 7.3(c)), and the data cost of at least 828 USD a year is above the capital.

**Status for H3-A: NOT QUALIFIED** (M3 fail; frequency far below §1 at any plausible E).

### 2.3 CS-XMOM: abandonment confirmed

The review verdict was `unsound`, and the dossier itself recommends abandonment. It needs 6 legs without a stop,
which is 3 % risk against a 1 % cap. Under the risk policy that makes it incompatible with the candidate contract.
Detecting an annualised Sharpe of 1.44 to 1.76 takes 104 to 156 weeks. Its idea was chosen after seeing H2.

It cannot be recovered under another name. The following would all be the same family:

- a "relative-strength rank";
- a "leader/laggard" filter;
- `xs_rel_ret_lb` used as a score input;
- a single-leg version that trades only the top or bottom rank.

**Status: ABANDONED.** Agent C's feature catalogue should drop `xs_rel_ret_lb`, or justify it under M4 (review R1-F8).

## 3. New sources (A2): three examined, one retained as conditional

The owner allowed at most three new sources. Each was screened against the closed families in
`RESEARCH-CAMPAIGN-STATUS.md` and `../reports/research-strategy-landscape-2026-10.md`:

- day-trading pullbacks;
- swing breakouts;
- candle scalping;
- tick, flow and book microstructure (r1–r14, Q3);
- OI positioning;
- regime detectors;
- post-listing drift (H1);
- idiosyncratic shock reversal (H2);
- the leverage study;
- cross-sectional momentum.

### 3.1 Retained: CS-EARN, post-announcement continuation on equity X-Perps (`SOURCE_CONDITIONAL`)

| Item | Content |
|---|---|
| **Mechanism (falsifiable)** | Investors under-react to scheduled earnings news, so part of the price adjustment comes after the announcement (Bernard & Thomas 1989, *J. Accounting Research*; Chan, Jegadeesh & Lakonishok 1996, *J. Finance*). Falsified if, among the events, the post-T return in the direction of the announcement reaction is not above that of a matched non-event control. |
| **Possible cause of an advantage** | Limited attention and gradual diffusion of information. 24/7 X-Perp trading outside the cash session adds a thin, retail-dominated venue, and its ±10 % index band can hold back a reaction until the reopen. The band is an OKX rule: the index is limited to ±10 % of the last stock price, and off-hours it relies more on OKX's own contract price (`okx.com/en-eu/help/tradfi-x-perps-eea`, updated 2026-08-05). |
| **Why it might persist after fees** | **Contested.** Martineau (2022, *Critical Finance Review* 11, 613–646, doi 10.1561/104.00000122) finds the drift **absent in large stocks since 2006**, and the X-Perp underlyings are large, high-attention names. Two 2025 papers dispute this, according to a UCLA Anderson research brief. The only argument for persistence specific to this venue is the off-hours band and segmentation, and it is a hypothesis, not evidence. For scoring, the hypothesis would be that features at T pick out the subset where adjustment is incomplete. |
| **Why it is distinct from the closed families** | It is anchored on an **exogenous, scheduled information event** on an **equity** underlying, which no closed family uses: the landscape matrix contains no calendar event. H1 is about listing age. H2 traded the *reversal* of unanchored residual crypto moves; CS-EARN trades *continuation* after a dated fundamental event. **Declared proximity:** the side comes from the sign of the announcement reaction, which resembles short-horizon momentum and is the mirror image of H2. H2's long-side continuation result was seen by the author. No H2 parameter is reused. |
| **Market, products** | OKX Europe TradFi X-Perps: 76 live `instCategory = 3` contracts on 2026-10-16, all listed between 2026-06 and 2026-10. By the author's classification (not verified against EDGAR): 44 US domestic operating companies, 8 non-US companies, 9 ETFs or indices, 15 unclassified. Settled in USD; `ctVal` 1; `minSz` 0.0001–1; platform `lever` 10–50. |
| **Candidate at T (DRAFT)** | T = close of the first regular US session that fully follows the announcement (day 0 close). Side = sign of the X-Perp's market-adjusted return from the last pre-announcement regular close to T (market = SPY/QQQ X-Perp). Candidate = every event on a single-company underlying; there is no magnitude threshold, because filtering is the scorer's job. One candidate per event. |
| **Holding** | DRAFT 5 regular sessions, exit at a close. It is above the "≤ 1 day" criterion 3 of `H3-DECISION.md` §2.1, which serves adaptive management (H3-B) more than scoring. |
| **Long / short** | Both. |
| **Stop** | Mandatory price stop, DRAFT d = clamp(2 × σ_daily × √5, 4 %, 15 %), with σ from prospective data only. Gap risk at the US reopen and through the ±10 % band is declared: stops fill at the worse of the stop and the first available price. |
| **Size and risk** | 0.5 % risk = 2.50 USD. At d = 8 %, notional is about 31 USD. With `minSz` 0.01 share, the minimum stays under about 31 USD for any share price below about 3,100 USD; the exact per-instrument check is P-E4. Leverage 1×, 2 slots, 1 % in total. |
| **Frequency (metadata and regulation, not returns)** | US domestic issuers file quarterly reports (Exchange Act Rule 13a-13) and an annual report, and earnings releases come with them, so there are about 4 events a year per issuer. 44 issuers × 4 = **176 a year (3.4 a week)**; adding the 8 non-US companies, if quarterly, gives **≤ 208 a year (4.0 a week)**. The events are strongly clustered in reporting seasons: several a week in season, close to 0 out of season. The universe changes (SKDD was reported delisted on 2026-09-03 and now appears live under a new instrument id). **Not verified:** the actual count of announcement dates. |
| **Data needed** | Announcement timestamps; X-Perp 1-minute bars, funding and quotes (prospective); the regular-session calendar; for development only, history of the underlying stocks. |
| **Temporal provenance** | SEC EDGAR filings carry an acceptance timestamp, which makes them a genuinely point-in-time event record (8-K Item 2.02 for results). Earnings dates are not revised after the fact the way token-unlock schedules are. X-Perp data: prospective only (same rule as every H3 source). |
| **Data cost** | EDGAR: free; its fair-access policy and terms still have to be checked (P-E3). X-Perp public data: 0, subject to the OKX market-data licence (P6, common to every source). Historical stock prices for development: licence and cost UNKNOWN. |
| **Independent validation** | Prospective X-Perp capture after a freeze. A distinctive point: the history of the **underlying stocks** has not been read by this programme, as far as its documents show. Through transferability (during the cash session the index is fed by the stock price), it could serve as **development and training** data. It does not give an independent X-Perp confirmation. It is also "consumed" by the academic literature (PEAD is a heavily studied anomaly), which is a form of data snooping to declare. |
| **Selection-bias risks** | OKX listed popular, high-attention names (AI, crypto-related), where under-reaction should be weakest. The author knows the PEAD literature, including that it has decayed. The idea was chosen after H2. The universe is young: at most one or two reporting seasons since listing. |

**Gates (A3), in order:**

| Gate | Status | Why |
|---|---|---|
| M1 market | **CONDITIONAL** | OKX Europe Markets Ltd (MiFID) offers TradFi X-Perps in the EEA (press, 2026-06-09). The OKX page warns that access "may be restricted or prohibited in certain jurisdictions". The account's access to **TradFi** X-Perps (as opposed to crypto X-Perps) is unverified, as is the effect of the ESMA statement of 2026-02-24 on CFDs. The API is unverified. |
| M2 instruments | **CONDITIONAL / UNKNOWN** | Specs are compatible (§3.1 size). **Liquidity is UNKNOWN**: reading tickers is not allowed in this mission, and the crypto snapshot does not cover equities. TradFi fees and funding intervals vary by product and are unverified. |
| M3 data | **CONDITIONAL** | Event timestamps: EDGAR, point-in-time; terms to be checked. X-Perp: prospective only, under the OKX licence (P6). The licence for development stock history is UNKNOWN. |
| M4 hypothesis | **CONDITIONAL** | The mechanism is distinct and falsifiable. Declared: proximity to momentum and to H2 through the side rule; prior exposure to the literature; contested persistence. |
| M5 economic validation | **UNKNOWN** | Feasible only prospectively; see the scoring checks below. |

**Scoring checks:**

| Check | Status |
|---|---|
| Sufficient frequency | **Marginal.** 176–208 labelable a year. The rank-IC test needs 1.3–1.6 years at IC 0.15 and 3.0–3.5 years at IC 0.10 with DE = 1, or 2.0–2.4 and 4.5–5.3 years with DE = 1.5 (§1). The in-season clustering makes DE > 1 likely. |
| Variability of features at T | Plausible from definitions, without data: reaction size in σ units, volume surprise, pre-event drift, same-day market move, whether the band was hit, announcement timing (before the open or after the close), funding, liquidity rank. |
| Reproducible definition | Yes, given EDGAR timestamps and a fixed session calendar; one candidate per event. |
| Labels computable in theory | Yes, on prospective X-Perp bars (A2). Corrected after the independent review CTO-02: the training and A1 label is the reference static-exit label on the technically eligible population (H3-A-STATISTICAL-VALIDATION-DRAFT.md §2), not the capacity-free replay, which is executability-conditioned. Bars inside the band and gaps at the reopen need the R1-F6 timing rule. |
| Relevant control | Yes. For scoring: BR at B1's acceptance share. For the source itself: matched non-event windows (same underlying in a non-reporting week, or another underlying the same day). |
| Realistic power study | Yes, in the form of §1 above; it is to be re-run by agent B's procedure with a design effect for season clustering. |

**Independent review QR-3 (2026-10-16), open.** T is the close of the first full regular session after the
announcement, so the ±10 % band and the off-hours argument act before T. After T the X-Perp index follows the
underlying large-cap stock, where Martineau (2022) finds post-earnings drift absent since 2006; the counter-evidence
was read only through a secondary brief. The side rule (sign of the market-adjusted day-0 reaction) is short-horizon
continuation, the mirror of H2, chosen after H2's results were seen. M1, M2 and the event count therefore cannot
qualify CS-EARN on their own: an M4 criterion is required before any capture (a mechanism argument that holds after
T, or a pre-registered development-only check on the listed issuers' underlying history after 2006 at 5 sessions, if
the owner admits that history), and the side rule must be classified against the momentum and H2 families with a
`JUSTIFIED` decision. The power figures in the "Sufficient frequency" row are for A1 alone; the full H3-A primary on
a CS-EARN-like synthetic stream needs far longer (H3-A-STATISTICAL-VALIDATION-DRAFT.md §6).

### 3.2 Examined and rejected: CS-MACRO, scheduled macro announcements (FOMC, CPI) on BTC, ETH, SPY and QQQ X-Perps

- **Mechanism:** a risk premium on scheduled announcement days (Savor & Wilson 2013, *JFQA* 48(2)) and the pre-FOMC
  drift (Lucca & Moench 2015, *J. Finance* 70(1)). It is distinct from the closed families.
- **Frequency:** 8 FOMC decisions (published schedule) plus 12 CPI releases give about 20 events a year. Every
  instrument shares the same event, so there is about **1 independent observation per event** (≈ 0.4 a week). The side
  is long only, and the candidates have little variability at T.
- **Verdict: NOT QUALIFIED (frequency).** The rank-IC test would need more than 7 years even at IC 0.20 (156 / 20).
  At most it is a standalone event study, not a scoring source.

### 3.3 Examined and rejected: CS-REOPEN, off-hours dislocation of equity X-Perps at the US reopen

- **Idea:** the off-hours price of the X-Perp, set on a thin 24/7 venue whose index relies on OKX's own contract
  price, would converge to the underlying at the reopen.
- **High nominal frequency** (each night or weekend × about 50 underlyings), but it is dominated by one common factor.
- **Rejected at M4:**
  - it is a premium-reversion rule, close to H2 (reversal of a residual move) and to the closed reversion
    microstructure families (r3–r7);
  - its "fair value" reference off-hours is circular, because the index uses the contract's own price;
  - an independent reference such as CME index futures needs a licensed feed (M3 UNKNOWN).
- This is exactly the "frequency without a mechanism" case the owner warned against.

## 4. Summary table

| Source | M1 | M2 | M3 | M4 | M5 | Labelable candidates a year | Fits the contract | H3-A status |
|---|---|---|---|---|---|---|---|---|
| CS-FUND | COND. (spot unverified) | COND. | COND. (prospective) | COND. (yield, not edge) | UNKNOWN | 2 in total | no (two legs, no price stop) | not a scoring source |
| CS-UNLOCK | COND. | COND. | **FAIL** | COND. | UNKNOWN | E × 12, E UNKNOWN (≈ 60 if E = 5) | yes | not qualified |
| CS-XMOM | COND. | COND. | COND. | COND. (closed family) | UNKNOWN | 52 rebalances | no (6 legs, 3 % risk) | abandoned |
| **CS-EARN** | COND. (TradFi access unverified) | COND. / liquidity UNKNOWN | COND. | COND. | UNKNOWN | 176–208 (from issuer count) | yes | **conditional** |
| CS-MACRO | COND. | COND. | COND. | COND. | UNKNOWN | ≈ 20 | yes | not qualified (frequency) |
| CS-REOPEN | COND. | UNKNOWN | UNKNOWN | **FAIL** (proximity, circular reference) | UNKNOWN | high nominal, low effective | yes | rejected |

## 5. Decisive unknown and single deciding check, per source

| Source | Decisive unknown | Single check that decides it (none is launched) |
|---|---|---|
| CS-FUND | none for H3-A: it is structurally not a scoring source | none needed. As a yield measure: the owner checks in his account that the spot leg exists and that USDC can move to X-Perp margin (dossier P1/P2) |
| CS-UNLOCK | the monthly count E of eligible insider cliffs on the X-Perp universe | a count on calendar metadata only, under a provider's **written** licence (dossier P0.1/P0.5). If E × 12 is below §1's n at the owner's minimum IC, close it for H3-A |
| CS-XMOM | none: abandoned | none |
| CS-EARN | whether the owner's account can trade **TradFi** X-Perps (M1); then their liquidity (M2) | the owner checks in his account interface, without placing an order, that TradFi X-Perps (for example `NVDA-USD_UM_XPERP-…`) can be opened. If yes, the next checks are a liquidity screen on post-quarantine metadata (after 2026-10-31) and a count of past announcement dates from EDGAR filing metadata, after its terms are checked |
| CS-MACRO | none: the frequency is structural | none |
| CS-REOPEN | none: fails M4 | none |

## 6. Prerequisites for CS-EARN (none met on 2026-10-16)

| # | Prerequisite | Gate |
|---|---|---|
| P-E1 | The owner's account can trade TradFi X-Perps (appropriateness test, jurisdiction); the API scope is checked later | M1 |
| P-E2 | Liquidity screen of the equity X-Perps on metadata captured after 2026-10-31, with a threshold declared beforehand | M2 |
| P-E3 | SEC EDGAR fair-access policy and terms read; access only within them, with an honest declared User-Agent | M3 |
| P-E4 | InstrumentSpec for the retained underlyings; minimum-size check at 0.5 % risk; TradFi fees and funding intervals | M2 |
| P-E5 | The issuer classification (44 / 8 / 9 / 15) verified against EDGAR filer data, not the author's knowledge | M2 |
| P-E6 | Owner decision: minimum detectable IC and duration (§1), knowing that IC 0.10 means 3 to 5 years | M5 |
| P-E7 | Whether underlying-stock history may serve as **development or training** data (licence, cost, transferability) | M3 |
| P6 | OKX market-data licence (common to every source) | M3 |

## 7. Sources

- `eea.okx.com/api/v5/public/instruments?instType=FUTURES` (2026-10-16, private snapshot `a-sources/raw/`).
- `okx.com/en-eu/help/tradfi-x-perps-eea` (updated 2026-08-05).
- ledgerinsights.com, "OKX launches X-Perps on US stocks in EU despite ESMA pushback" (2026-06).
- Martineau (2022), CFR 11:613–646, doi 10.1561/104.00000122.
- Bernard & Thomas (1989), JAR 27 suppl.
- Chan, Jegadeesh & Lakonishok (1996), JF 51(5).
- Savor & Wilson (2013), JFQA 48(2).
- Lucca & Moench (2015), JF 70(1).
- Grinold & Kahn (2000), *Active Portfolio Management*.
- Kim, SSRN 6632838 (summary only).
- The repository documents cited inline. The dossier notes are in `tradingV3-private/research/20261015T2200Z-h3-dossiers/`;
  H3-C's quarantined tickers file was not opened.
