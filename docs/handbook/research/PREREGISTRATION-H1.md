# Pre-registration H1 — post-listing drift of new perpetuals (version 1)

Version 0 is `DRAFT-PREREGISTRATION-next-strategy.md`, kept unchanged. The changes from version 0 are listed in
§21. Inputs:

- `H1-LISTING-AUDIT.md`
- `H1-EXECUTION-FEASIBILITY.md`
- `H1-METHODOLOGY-REVIEW.md`
- `H1-DATA-MANIFEST.md`

The owner's decisions of 2026-10-14 are integrated:

- effect threshold −5 % at 14 days;
- slippage 20 / 60 bp, stops 50 / 150 bp;
- 500 USDT at 0.5 % risk per trade;
- a prospective test of 12 calendar months.

**Freezing this document authorises nothing.** Gate 1, Gate 2 and the prospective test each need a new explicit
authorisation from the owner.

## 1. Question

After the first listing of a new USDT perpetual on OKX or Binance, does the coin under-perform comparable
established perpetuals over the next 14 days? If so, can an unhedged short with a stop, sized for a 500-USDT
account, earn a net profit that holds on new data?

## 2. Eligible universe and events

- **Instruments.** USDT-margined linear perpetuals on OKX (`instCategory` = 1) and Binance USD-M (`PERPETUAL`,
  underlying `COIN`).
- **Excluded bases.** The 20 programme pairs, stablecoins, gold / commodity tokens and non-crypto categories.
- **Event.** The venue's listing of a base, at **t0** = OKX `listTime` or Binance `onboardDate` (official
  metadata), classified by `scripts/research_listing/audit.py` (frozen code, §20).
- **Primary sample.** Every event with all of the following:
  - class `FIRST_OBSERVED_PERP_LISTING` (first among OKX and Binance; a co-listing within 24 h counts once, as
    the OKX event);
  - a verified date;
  - not a re-listing;
  - archive coverage starting within 24 h of t0;
  - funding files present (needed for Gate 2 only);
  - t0 in [2023-09-01, 2026-09-01) for S_A and S_B.
- **Analysed apart, never primary:**
  - `FOLLOW_ON_VENUE_LISTING`;
  - `PREMARKET` (OKX, measured from the normal-trading switch);
  - `UNKNOWN_ORDER`;
  - delisted OKX contracts with archive-dated listings. These enter the mandatory survivorship sensitivity
    (§10.4).
- **Not observed.** Earlier perpetuals on other venues, token creation and first spot listing. This is a stated
  coverage limit.

## 3. Data quality criteria (checked before any outcome)

- **Candles** (1 minute):
  - continuous over [t0, T + 30 d], with gaps over 60 minutes counted;
  - an event with more than 6 cumulative hours missing in [T, T + 14 d] is excluded from the outcome and
    reported.
- **Prices** at T and T + h must exist; otherwise the event is excluded from that horizon and counted.
- **Funding.** Settlement files present for Gate 2; a missing settlement is never zero (§12).
- **Order of analysis.** All exclusions are computed and written **before** the outcome module runs, which reads
  the exclusion file. The code enforces this order.

## 4. Return conventions (`conventions.py`, tested)

- **Decision time T** = t0 + 24 h, at the close of that hour. Reference price P(T) = the close of the last
  one-minute candle before T.
- **Horizons** h ∈ {1, 3, 7, **14**, 30} days; **14 is primary**, the others secondary (Holm-adjusted).
- **Instrument return** r = ln(P(T+h) / P(T)).
- **Index return** r_M = ln(M(T+h) / M(T)), where M is the venue's equal-weight index of the top 30
  established perpetuals:
  - listed ≥ 180 days;
  - ranked by median daily quote volume of the previous month;
  - rebalanced monthly;
  - hourly closes.
- **Relative return** AR = r − r_M.
- **Paired surplus** S = AR(event) − mean AR(matched controls at the same T).
- **Unhedged short return** (Gate 2): (P_entry − P_exit) / P_entry, before costs.
- **Delisting during the horizon:**
  - main: the last traded close before delisting;
  - sensitivity unfavourable to H1: the highest close of the last 24 h.

## 5. Controls and matching

### 5.1 C1, primary: matched established perpetuals

- **Candidates at T.** Same venue, listed ≥ 180 days, top-50 by previous-month median daily quote volume.
- **Matching variable.** σ_h = standard deviation of the 24 hourly log returns over [T − 24 h, T] × √24. For the
  event this is its first 24 h.
- **Selection.**
  - tolerance |ln(σ_c / σ_e)| ≤ ln 3;
  - up to 20 nearest, at least 3;
  - no control is chosen on any value after T.
- **Coverage rule.** An event with fewer than 3 admissible controls leaves the C1 analysis (counted). If a set's
  share of such events exceeds **30 %**, its C1 result is `NOT_GENERALIZABLE`.

### 5.2 Other controls (reported)

- **Index-relative AR**, without matching.
- **C2: the same coin later** (T' = t0 + 90 d + U(0, 30 d)).
- **C3: event dates shifted** by U(30, 90) days, 1,000 draws.

### 5.3 Diagnostics written before outcomes

- volatility ratios;
- admissible controls per event;
- excluded share;
- σ overlap;
- control volume ranks.

## 6. Effect threshold

The minimum relevant effect is a **mean 14-day paired surplus of −5 %** (relative to matched controls, not an
absolute fall).

The criterion "mean ≤ −5 % and 90 % CI upper bound < 0" demonstrates a statistically supported negative effect
with a point estimate beyond −5 %. It does not demonstrate an effect of 5 % or more at that confidence. The
stronger reading (upper bound ≤ −5 %) is reported, not required.

## 7. Data sets and their roles

| Set | Content | Role |
|---|---|---|
| **S_A** | OKX primary events of the window: 83, plus 13 delisted archive-dated for sensitivity | **Development screen.** The OKX 1-minute candles of all 363 archived families are on disk. No strategy result exists inside [t0, t0 + 31 d] (swing v2 OKX excluded the first 42 days). |
| **S_B** | Binance primary events of the window: 240. Downloaded only after the Gate 1 authorisation: 1-minute klines of each event's first two months, hourly klines of the index and control candidates, funding. | **Decisive Gate 1**, read once |
| **S_C** | All primary listings on OKX and Binance during the 12 prospective months (§16) | **Only independent validation** (Gate 2) |

Gate 2's historical development uses S_A ∪ S_B. Its result is conditional on S_B's Gate 1 read and is not an
independent validation.

## 8. Statistics

- **Unit.** One event, one observation.
- **Intervals.**
  - Bootstrap by calendar month of T (10,000 draws, 90 % two-sided). Seeds fixed: 20261014 + set index.
  - The same with 2-month blocks, required for Gate 1.
  - 3-month blocks, reported.
- **Distributions reported:**
  - mean, median, quantiles 1 / 5 / 25 / 75 / 95 / 99 %, skewness;
  - P(S < 0), P(AR < −10 %), P(MAE > +25 %), P(MAE > +50 %);
  - funding paid by a short.
- **Effective sample.** Effective number of independent observations (design effect from the month blocks); a
  one-event-per-7-days thinning is reported.
- **Multiplicity.** One primary test (14-day paired surplus on S_B). Everything else is labelled secondary with
  Holm-adjusted p.
- **Splits reported, never selected:** venue, year, market phase (sign of the 30-day index return at T), price
  ceiling (§13).

## 9. Gate 1 — the effect exists

### 9.1 S_A screen (run first; on-disk data)

- mean and median of S at 14 days < 0 on the primary S_A sample;
- also < 0 with the 13 delisted archive-dated events added.

If the screen fails: stop; S_B is not downloaded; H1 is closed.

### 9.2 S_B decision (read once, after the screen passes and the owner authorises)

All required:

1. Mean S14 ≤ **−5 %**, and the upper bound of its 90 % CI < 0 with 1-month **and** 2-month blocks.
2. Median event AR14 < median control AR14, and P(S14 < 0) ≥ 55 %.
3. S negative in at least 2/3 of the half-years with ≥ 15 events.
4. Concentration:
   - the mean S is still < 0 without the 5 % most negative events;
   - no month carries more than 25 % of the summed S;
   - no event carries more than 5 %.
5. C1 coverage: no more than 30 % of the events lack comparable controls.
6. Criteria 1–4 still hold with the delisting sensitivity (§4).

The index-relative result and C2 / C3 are reported.

**If Gate 1 fails, no trading engine is developed and H1 is closed.**

## 10. Survivorship

1. Binance: complete inventory (archive bucket).
2. OKX: bounded (≤ ~10 possibly missing events), stated in every report.
3. No event is removed because it was delisted.
4. Mandatory sensitivity analysis: S_A with the delisted archive-dated events added (§9.1).

## 11. Costs (conventional, not measured)

| Item | Baseline | Pessimistic |
|---|---:|---:|
| Taker fee | 5 bp per side | 5 bp per side |
| Entry slippage | 20 bp | 60 bp |
| Exit slippage | 20 bp | 60 bp |
| Stop-market slippage | 50 bp | 150 bp |

- Stress: stop slippage × 2.
- Fees and slippage are counted once each.

## 12. Funding of the short

- Real settlements at their recorded instants and rates. A short receives a positive rate and pays a negative
  one (`conventions.funding_cashflow`, tested).
- **Sizing provision** (decided at T, using no future funding): 14 days at 0.03 % per 8 h (baseline) or 0.1 % per
  8 h (pessimistic).
- **A missing settlement** (an interval longer than 1.5 × the last cadence known) is booked against the short at
  the largest absolute rate recorded in the 3 days before, and counted.

## 13. Squeeze, stop and execution

- **Entry.** At T: a taker short at the open of the first one-minute candle after T.
- **Stop distance.** d = clamp(3 × σ24, 15 %, 60 %), with σ24 from the 1-minute log returns of [t0, T] × √1440.
- **Stop execution.** The stop-market fills at the worse of the stop and the next minute's open, plus slippage.
- **Exit.** At T + 14 d, taker. No target.
- **Margin.** At 1× isolated margin, liquidation is modelled as a gap through the stop.
- **Path statistics reported.** MAE / MFE, and moves of +50 % to +200 % within the horizon.
- **Execution verdict.** If a Gate 2 pass under baseline costs fails under the pessimistic costs or the stress,
  the verdict is **`INSUFFICIENT_EXECUTION_DATA`**.

## 14. Minimum sizes and capital

- **Portfolio.** 500 USDT, leverage ≤ 1×; risk 0.5 % per trade (2.50 USDT) and 1 % in total, i.e. at most two
  positions at full risk; one per instrument.
- **Sizing** (`conventions.size_short`, tested):
  - quantity rounded down to the lot;
  - a plan below the venue minimum (contracts, or Binance's 5-USDT notional) is skipped and counted, never rounded
    up;
  - leverage and risk are never raised.
- **Specifications.** Current specifications for listed contracts; archive-observed ones for delisted OKX
  contracts (flagged). Historical executability is therefore uncertain, and only S_C demonstrates it.
- **An event arriving when the risk budget is used** is skipped and counted.

## 15. Gate 2 — exploitability (historical development, S_A ∪ S_B; conditional)

All required, under both cost profiles unless stated:

1. At least 60 executed trades.
2. Mean net return per trade > 0, and > 0 under the stress (else `INSUFFICIENT_EXECUTION_DATA`).
3. The mean **absolute** short return net of costs and funding is > 0; a relative under-performance alone never
   passes.
4. Surplus over the control strategy > 0 with p ≤ 0.10. The control strategy is the same rule on one C1 control
   per event, 1,000 draws, same portfolio.
5. PF ≥ 1.2 (baseline) / ≥ 1.0 (pessimistic).
6. Portfolio PnL > 0.
7. Max drawdown ≤ 15 % of capital.
8. Worst trade ≥ −3 R.
9. Positive in at least 2/3 of half-years.
10. Still > 0 without the top 5 % of trades.

**If Gate 2 fails, H1 is closed.**

## 16. Prospective test S_C (12 calendar months)

- **Start.** The 1st of the month following the owner's authorisation, written in a claim file before any S_C
  listing is observed.
- **End.** Exactly 12 calendar months later. Never extended or shortened.
- **What is recorded.** Every primary listing of OKX and Binance during the period, with its metadata and
  specifications at the time, a daily public-data capture of its first 31 days, and funding.
- **No parameter changes.** Only technical health is monitored, never intermediate performance.
- **Criteria.**
  - At least 30 executed trades; otherwise **`INCONCLUSIVE`**.
  - Then Gate 2 criteria 2–8, with PF ≥ 1.1 / ≥ 1.0.
  - The Gate 1 statistic on S_C is reported.
- **A pass authorises only a Paper / demo design discussion.** Never real trading.

## 17. Stop rules

| Failure | Consequence |
|---|---|
| S_A screen | Closed; S_B never downloaded |
| S_B Gate 1 | Closed |
| Gate 2 | Closed |
| S_C | Closed |

- `INSUFFICIENT_EXECUTION_DATA` or `INCONCLUSIVE` closes the current step without a pass.
- No set is re-read; no parameter is changed after a read.
- A defect stopping a run before any output is fixed, disclosed, and the run started once.

## 18. Statistical limits (declared)

- Power: see the methodology review. S_A cannot decide; S_B has the power to detect −5 % only if σ(AR14) is
  near 25 %; S_C detects about 7–13 %.
- S_A and S_B share calendar time.
- Execution specifications are current, not historical; there is no book history.
- Delisting settlement uses proxies.
- Only OKX and Binance are observed.

## 19. Infrastructure

- **S_A.** No download.
- **S_B** (after authorisation):
  - about 240 × 2–3 one-minute monthly files (≈ 0.4–0.9 GB of zips, deleted after verified conversion);
  - index and control candidates as hourly monthly klines (≈ 50–100 MB);
  - funding (≈ 10 MB).
- **Guards.** The disk guard stops at 15 GB free, the RAM guard at 1.5 GB; nice 10, 1–2 workers.
- **Isolation.** The forward OKX services (`fwd-okx-*`) and their windows W0 / W1 / W2 are never read or
  touched.

## 20. Reproducibility

- **Code.** `python-orchestrator/scripts/research_listing/` (`audit.py`, `conventions.py`) and the tests
  `tests/test_research_listing.py`, at the commit recorded in `PREREGISTRATION-H1.sha256`.
- **Data.** Metadata snapshots and their sha256 in `H1-DATA-MANIFEST.md`. The audit outputs are reproducible from
  them.
- **Environment.** Python 3.12, numpy / pandas as in the research runtime `20261014T090000Z-swing-v2-runtime`
  (versions recorded with every run).
- **Seeds.** Fixed (§8).

## 21. Changes from version 0 (the draft)

1. Decisive Gate 1 moved from S_A to S_B. The audit gives 83 primary events on S_A (not ≈ 255) and 240 on S_B;
   power in §18. S_A becomes a screen.
2. S_B is not presented as an independent validation of Gate 2. S_C is the only independent validation;
   Gate 2's historical development (S_A ∪ S_B) is declared conditional.
3. Prospective duration: 12 calendar months, fixed (previously 6).
4. Listing classes added: follow-on, pre-market, unknown order, re-listing; verified vs archive dates.
5. Matching: σ from hourly returns over 24 h; tolerance ×3; 3 to 20 controls; 30 % coverage rule; diagnostics
   before outcomes.
6. Delisting: last close (main) and the highest close of the last 24 h (unfavourable sensitivity), replacing the
   60-close mean.
7. Absolute short return, funding, stopped short and portfolio reported separately; Gate 2 requires the absolute
   net short return > 0.
8. Execution:
   - Binance's 5-USDT minimum notional and current-specification uncertainty are declared;
   - the `INSUFFICIENT_EXECUTION_DATA` verdict is added;
   - exit slippage 20 / 60 bp is added;
   - the funding provision is fixed;
   - liquidation is treated as a gap through the stop.
9. Dependence: 2-month blocks required, 3-month blocks reported; concentration limits per event and per month.
10. Survivorship: Binance resolved, OKX bounded, mandatory sensitivity analysis.

## 22. Freeze

The body above this line is hashed (sha256 over the file content up to and including the line "## 22. Freeze"
and the following blank line). The hash, the time and the git commit go to `PREREGISTRATION-H1.sha256`. Status:
**FROZEN_NOT_EXECUTED.**

