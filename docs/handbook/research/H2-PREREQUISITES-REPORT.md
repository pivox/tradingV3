# H2 setup mission — prerequisites check (2026-10-15)

**Verdict: `PREREQUISITES_NOT_MET`.** The mission ("H2: build a profitable setup and validate it independently")
stops at its §1. No parameter search, no simulation and no return of any kind was computed. Gate 1 of H2 was **not**
launched. None of the nine deliverables of the mission's §13 is written: no phase took place, so there is nothing
to report in them.

What this document does:

- records, prerequisite by prerequisite, what is established and what is not, with its source;
- lists what is needed to satisfy each missing one;
- points to a **draft** H2 Gate 1 pre-registration, `H2-GATE1-PREREGISTRATION-DRAFT.md`, status
  `DRAFT_NOT_FROZEN`. It was written without reading any return.

## 1. What was read

| Subject | Document |
|---|---|
| Markets accessible from France | `FRANCE-EXCHANGE-ELIGIBILITY.md` |
| Instruments really tradable | `TRADABLE-INSTRUMENT-UNIVERSE.md`, `TRADINGV3-EXCHANGE-COMPATIBILITY.md` |
| Eligibility gates M1–M5 | `RESEARCH-ELIGIBILITY-GATES.md` |
| H2 hypothesis | `research-new-market-hypotheses.md` §H2 |
| H2 "scientific study" | `H2-FEASIBILITY-REVIEW.md`: a feasibility review only, explicitly "no return computed, no parameter chosen" |
| H2 pre-registration and Gate 1 criteria | **None exists** (see §2) |
| Data already consumed | `../reports/research-strategy-landscape-2026-10.md`, "Data already consumed" |
| Past swing, scalping, day-trading reports | `../reports/research-*.md`, summarised in the landscape matrix |
| Engines and tooling | `python-orchestrator/scripts/research_swing2/` (1-minute engine with stops through gaps, real funding instants, sizing, month-block CI), `okx.py` / `okx_universe.py` (OKX archive acquisition, point-in-time monthly universe), `scripts/research_exec/prereg/` (claims, frozen protocols) |

## 2. Is there an H2 pre-registration or a Gate 1 result?

No. Checked on 2026-10-15 against `origin/main` at `e2bbe02a`:

- no file named or describing an H2 pre-registration in the repository (`docs/`, `python-orchestrator/`,
  `scripts/`); the only H2 documents are the hypothesis (draft), the feasibility review and the M1–M5 gates table;
- no commit message on `main` mentions an H2 protocol or result, only the feasibility review (PR #503);
- no remote branch named after H2 or idiosyncratic shocks;
- no H2 directory in `tradingV3-private/research/`;
- none of the last 30 pull requests concerns H2 beyond the feasibility review (#503).

## 3. Prerequisites

| Prerequisite | Status | Evidence | What is missing |
|---|---|---|---|
| **M1_PASS** — market accessible to a French resident, authorisations, product access confirmed | **PARTIAL (not passed)** | OKX Europe Markets Ltd is a MiFID firm (MFSA), X-Perps open to EEA retail with an appropriateness test (`FRANCE-EXCHANGE-ELIGIBILITY.md`). On 2026-10-15 around 00:10 (Paris) the owner answered "Oui okx ok" to the account check: the French account has the product, **by the owner's statement**. | **API access to the X-Perps for that account** is not verified, and M1 requires it. Also unchecked: the MFSA register entry of OKX Europe Markets Ltd (the review cites the OEM terms, not the register). |
| **M2_PASS** — instruments tradable, including by API, with the planned capital | **NOT ESTABLISHED** | Public snapshot of 2026-10-14: 29 crypto X-Perps ≥ 1 M USD a day; minimum orders compatible with 500 USDT at stops ≤ 15 % for 159 of 160 (`TRADABLE-INSTRUMENT-UNIVERSE.md` §3). | No **InstrumentSpec** recorded for the H2 universe (`TRADINGV3-EXCHANGE-COMPATIBILITY.md` §3). The account's **fee tier** and **API trading permission** on X-Perps are unknown. The liquidity threshold rests on **one day's** snapshot; no threshold over a period was declared. TradingV3 has no X-Perp instrument resolver (`instType=FUTURES`, `_UM_XPERP-` ids); this blocks execution, not research. |
| **M3_PASS** — historical data compatible with the target instruments | **NOT ESTABLISHED** | X-Perp history starts on 2026-03-30 (BTC); most contracts since June–September 2026. The feasibility review proposes the OKX global USDT-swap archive 2023–2026 for development, as the same underlyings on another product. | (a) No written **transferability justification** for H2 (price path tied by index and arbitrage; book, spread, funding and liquidity modelled per product). (b) **The archive on disk does not cover the H2 universe.** It was built for swing v2 OKX, which excluded the 20 programme bases. **14 of the 29 liquid X-Perp bases are missing**: BTC, ETH, SOL, NEAR, XRP, SUI, INJ, ARB, ADA, DOGE, LINK, AVAX, OP and QUANT. BTC, the proposed market factor, is among them (checked 2026-10-15 in `20261014T113000Z-tape-okx-swing-v2-monthly/klines`, 363 families). (c) **No independent historical validation set exists** (§4). (d) The **licence** of OKX public market data for live-trading use is not documented in the eligibility review (Binance's is, and forbids it). |
| **H2_GATE1_PASS** — statistical effect shown under the pre-registered protocol | **NOT RUN** | No pre-registration exists (§2). Gate M4 also requires one "frozen (sha256) before any read". | A frozen protocol, a separate authorisation from the owner to run Gate 1, the data step, then the single run. |

**Any one missing prerequisite stops the mission** (§1). Here three are not established and one never ran.

## 4. Data independence (bears on the whole H2 programme, not only on Gate 1)

| Data | Status for H2 | Possible role |
|---|---|---|
| OKX global USDT swaps 2023-01 → 2026-10-12, the 363 families on disk | Consumed at the candle level by swing v2 OKX (PR #500), another hypothesis. H2 never read them. | Gate 1 and development, labelled "consumed at candle level". **Never an independent validation.** |
| OKX global swaps of the 20 programme bases (not on disk) | Never read on OKX. Their Binance candles 2023–2026 are heavily consumed, and the price paths are tied across venues by arbitrage. | Development only, for the same reason. |
| OKX X-Perps since 2026-03-30 | Never read. The same period on the same underlyings was read through OKX global swaps (up to 2026-10-12), and the paths are tied by the index. | Execution realism (spread, book, funding of the target product); **not** an independent validation of the effect. |
| Binance intraday of non-programme symbols | Not read for results (H1 is reading part of it now, for new listings). | Personal non-production replication at most: the licence (CC BY-NC-SA 4.0, §4.2) forbids grounding live trading on it. |
| `fwd-okx-r1` W0 / W1 / W2 | Reserved | **Never**, by rule. |

Consequence: **no historical holdout can be built for H2**, on X-Perps or on any data whose licence would allow a
live decision. If H2 one day passes Gate 1 and Gate 2, its independent validation can only be **prospective on
X-Perps**, over a calendar window fixed before it starts. This is what the feasibility review already said; the
present check confirms it. Under the mission's §9, no holdout is fabricated.

## 5. What would satisfy the prerequisites, in order

1. **M1 (owner, read-only).** Confirm that the OKX Europe account's API keys can access X-Perps. A read-only key
   check by the owner is enough: the API management page shows the derivatives permission, or a read-only private
   call on `eea.okx.com` (`GET /api/v5/account/config`, `GET /api/v5/account/instruments?instType=FUTURES`) returns
   the X-Perps. TradingV3 sessions place no authenticated call; the owner runs it or reports the result.
   Optionally record the MFSA register entry of OKX Europe Markets Ltd.
2. **M2 (session, public data only).** Write the InstrumentSpec of the candidate universe from public metadata
   (`/api/v5/public/instruments?instType=FUTURES` on `eea.okx.com`): contract value, lot, minimum, tick, price
   limits, expiry, funding interval and cap. Declare a liquidity threshold **over a period** (e.g. the 30-day
   median daily value ≥ 1 M USD, from public tickers and candles). The owner reports the account's fee tier
   (or the base tier is assumed, 2 / 5 bp, as a stated assumption).
3. **M3 (session).**
   - Write the transferability note: what is shared between OKX global swaps and X-Perps (underlying, index) and
     what is modelled per product (spread, depth, funding cadence and cap, minimums).
   - Complete the archive for the 14 missing bases. Estimate: the on-disk families take about 26 MB for 50 months
     of 1-minute candles (DOT), so **14 bases × ~26 MB ≈ 0.4 GB**, plus their funding (the whole funding
     directory is 24 MB today). That is under 1 % of the free disk (57 GB on 2026-10-15). The download stays
     subject to the owner's go and to the H1 session's resource use (nice, ≤ 2 workers).
   - Document the OKX market-data terms.
4. **Gate 1 (owner's decision, then session).** Review, amend and **freeze** the draft pre-registration
   (`H2-GATE1-PREREGISTRATION-DRAFT.md`) with a sha256, write the claim, then run Gate 1 once. Only if Gate 1
   passes does the setup mission (§3–§8) become admissible, with Gate 2 on development data and a prospective
   validation on X-Perps.

## 6. What this check did not do

- No return, PnL, hit rate or event count on any data.
- No download. The only data access was **listing directory names** in the swing v2 OKX archive to check coverage.
  No file content was read.
- No authenticated call, no order, no change to the trading stack, its flags or the `fwd-okx-*` services and data.
- No file, branch or data of the H1 session was touched.
