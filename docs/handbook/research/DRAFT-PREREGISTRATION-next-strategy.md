# DRAFT_NOT_FROZEN — Pre-registration proposal: post-listing drift of new perpetuals (H1)

> **Version 0, superseded by `PREREGISTRATION-H1.md` (version 1, 2026-10-14).** Kept unchanged below for history.
> Changes are listed in version 1, §21.

> **Status: DRAFT_NOT_FROZEN.** This is a proposal for the owner's review. No data may be analysed under it
> until it is reviewed, amended if needed, and frozen (sha256) in a separate, explicit step. Every number below
> is a proposal, open to the review; none was chosen from data.

Hypothesis: `HYPOTHESIS-next-strategy.md`.
Background: `../reports/research-strategy-landscape-2026-10.md`.

## 1. Question

After a new USDT-margined linear perpetual is listed, does its market-relative return over the following days
differ from that of comparable established instruments on the same dates? Specifically, is it negative, by
more than its costs?

The study runs in two steps:

1. **Step 1, effect study:** distributions only, no strategy. It ends at **Gate 1**.
2. **Step 2, exploitability:** a short strategy with a stop, a portfolio and costs. It runs only if Gate 1 passes,
   and ends at **Gate 2**.

## 2. Event definition (objective, known at decision time)

- **Instrument.** A USDT-margined linear perpetual on OKX or Binance USD-M:
  - crypto category only (OKX `instCategory` = 1; Binance `contractType` = PERPETUAL, quote USDT);
  - excluding stablecoin, gold and stock bases;
  - excluding the 20 programme pairs.
- **Listing time t0.** The first one-minute candle with a trade, at the first listing of that base as a
  perpetual on that venue. A re-listing after an interruption of more than 7 days is not an event; the series
  segments come from the swing v2 OKX audit.
- **First-venue event (primary).** The earliest listing of the base as a perpetual across OKX and Binance. A
  later listing on the other venue is a **follow-on event**, reported separately (secondary).
- **Decision time T** = t0 + 24 h, at the close of that hour. The opening auction and the first day's price
  discovery are excluded.
- **Eligibility.** At least 20 trading hours in the first 24 h. Instruments delisted later remain in the sample
  (no survivorship filter). If delisted before T + h, the return runs to the delisting settlement proxy (the mean
  of the last 60 one-minute closes).
- **Classification at T** (each class fixed now, reported, never used to select):
  - spot-first vs perp-first: spot trading on a major venue more than 3 days before t0;
  - venue;
  - market phase: sign of the 30-day market return at T.

## 3. Measured quantities

- **Horizons.** h ∈ {1, 3, 7, **14**, 30} days; **14 days is primary**.
- **Market-relative log return.** AR_h = ln(P(T+h)/P(T)) − ln(M(T+h)/M(T)), where:
  - M is the equal-weight index of the venue's established perpetuals: the top 30 by median daily quote volume
    of the previous month, listed ≥ 180 days (the monthly universe rule of swing v2 OKX);
  - a secondary benchmark is BTC.
- **Path measures over [T, T+14 d]:**
  - maximum adverse excursion for a short (MAE = max of relative log rise);
  - maximum favourable excursion;
  - time to MAE.
- **Funding paid by a short** over [T, T+h]: the sum of the real settlements (sign: a short receives positive
  rates).

## 4. Controls

- **C1, primary: same-date matched established instruments.** For each event, 20 instruments of the
  benchmark's eligible set at T, nearest in realised 24-h volatility before T. The event's surplus is
  AR_h(event) − mean AR_h(its 20 controls), paired by date. This matches calendar time, market phase and,
  roughly, volatility. Young instruments are more volatile than any established control; the mismatch is
  reported (volatility ratio per event).
- **C2: same coin later in life.** AR_h of the same instrument from T' = t0 + 90 d + U(0, 30 d) (age control).
  It separates "young instrument" from "this coin".
- **C3: shuffled-date events (sanity).** The same events with T moved by a random 30–90 days, 1,000 draws.

## 5. Data sets and their status

| Set | Content | Status | Role |
|---|---|---|---|
| **S_A** | OKX listings 2023-09-01 → 2026-08-31 (≈ 240 events, counted from metadata only), 1-minute candles and real funding already on disk (`tradingV3-private/research/20261014T113000Z-tape-okx-swing-v2-monthly`) | Prices loaded by swing v2 OKX for a different hypothesis. The first 42 days of every series were excluded from trading there, so no strategy result exists for [t0, t0 + 31 d]. Post-listing returns never computed. | Gate 1 (one read) and Gate 2 development |
| **S_B** | Binance USD-M listings, same period, of bases **never listed as an OKX perpetual** before the Binance listing | Never downloaded, never read | Gate 1 confirmation and Gate 2 validation (one read each) |
| **S_C** | Every listing on OKX and Binance after the freeze date, observed prospectively | Future | Final test |
| — | The 20 programme pairs, November 2024 ticks, the forward OKX windows W0 / W1 / W2 | Consumed or reserved | **Not used** |

S_A and S_B share calendar time, so common market shocks link them. The market-relative construction and the
month-block statistics address this; S_C is the only fully independent set.

## 6. Statistics

- **Unit.** One event = one observation. Events in the same calendar month share market conditions, so all
  intervals use a **month-block bootstrap** (resampling calendar months of t0; 10,000 draws; 90 % two-sided).
  The effective number of independent months is reported.
- **For each horizon and set:**
  - mean, median, quantiles 1 / 5 / 25 / 75 / 95 / 99 %, skewness;
  - P(AR < 0), P(AR < −10 %), P(MAE > +25 %), P(MAE > +50 %);
  - the paired surplus over C1 and C2 with its 90 % CI;
  - funding paid by a short (mean, quantiles).
- **Multiplicity.** One primary test (14-day surplus over C1 on S_A). The other horizons, benchmarks and classes
  are reported with Holm-adjusted p-values and labelled secondary. No class may be promoted to primary after
  the read.
- **Overlap.** Windows of events listed within h of each other overlap. The month blocks cover it. A
  sensitivity analysis keeps one event per 7-day window (the earliest).

## 7. Gate 1 — the effect exists (fixed before any read)

On **S_A**, all required:

1. Mean paired 14-day surplus over C1 ≤ **−5 %** (economic minimum, chosen to exceed plausible costs and funding
   with a margin), and the **upper bound of its 90 % month-block CI < 0**.
2. Median event AR14 < median control AR14, and P(AR14 < 0) ≥ 55 %.
3. **Time robustness.** The surplus is negative in at least 2/3 of the half-years with ≥ 15 events.
4. **Concentration.** Without the 5 % most negative events, the mean surplus is still < 0. No calendar month
   carries more than 25 % of the total surplus. No single event carries more than 5 %.
5. **Confirmation on S_B** (read once, after S_A passes): mean surplus < 0, with the 90 % CI upper bound < 0, or,
   if S_B has fewer than 60 events, one-sided p ≤ 0.10.
6. **Information, not gated** (feeds Step 2 design limits): the MAE distribution and the funding paid by shorts.

**Stop rule.** If any of 1–5 fails, H1 is closed, no trading engine is developed, and the result is reported.
Nothing is re-run on other horizons or classes.

## 8. Step 2 — exploitability (only if Gate 1 passes)

The rule is **fixed at the freeze of this protocol, not after Gate 1**. Gate 1's information may justify
abandoning it, never tuning it.

- **Entry.** Short at T: a taker order at the open of the first one-minute candle after T.
  - Slippage provision: baseline 20 bp, pessimistic 60 bp. No book history exists for these instruments, so this
    is an assumption, stated.
  - Fees: OKX / Binance taker 5 bp.
- **Stop (mandatory).** A stop-market order at **entry × (1 + 3 × σ24)**, where σ24 is the standard deviation of
  1-minute log returns over [t0, T] × √1440; floor +15 %, cap +60 %.
  - The stop executes at the worse of the stop price and the next one-minute open, plus slippage (baseline
    50 bp, pessimistic 150 bp).
  - A second stop rule, fixed +30 %, is reported for information only.
- **Exit.** Time exit at T + 14 days, taker. No profit target (the hypothesis is a drift, not a level).
- **Funding.** Real settlements, at their instants (1–8 h cadences).
- **Portfolio (owner's reference).**
  - 500 USDT, leverage 1, no compounding beyond realised PnL.
  - **Risk 0.5 % per trade** at the stop distance including the slippage provision, **1 % in total**, so at most
    two positions at full risk.
  - An event arriving while the risk budget is used is skipped and counted.
  - A plan below the venue minimum (`minSz` × `ctVal` × price) is skipped and counted. The feasibility at
    500 USDT is reported.
- **Control strategy.** The same rule applied to C1 control instruments at the same dates (1,000 draws of one
  control per event), same portfolio.

### Gate 2 (all required; no criterion relaxed after a read)

- **On S_A (development set, read once with the fixed rule):**
  - at least 60 trades;
  - net expectancy per trade > 0 under baseline and pessimistic costs;
  - surplus over the control strategy > 0 with p ≤ 0.10;
  - PF ≥ 1.2 (baseline) / ≥ 1.0 (pessimistic);
  - portfolio PnL > 0;
  - max drawdown ≤ 15 % of capital;
  - worst trade ≥ −3 R (squeeze slippage bounded);
  - positive in ≥ 2/3 of half-years;
  - not dependent on the top 5 % of trades (still > 0 without them).
- **On S_B (read once):** net > 0 under both cost profiles, PF ≥ 1.1 / 1.0, surplus > 0.
- **On S_C (prospective):**
  - a fixed calendar duration (proposal: 6 months from the freeze, about 40–60 events), not a trade count, so the
    end does not depend on results;
  - no look at intermediate results beyond operational health;
  - criteria as for S_B plus portfolio PnL > 0.

**Stop rules.** Failure on S_A means no S_B read and closure. Failure on S_B means closure. Failure on S_C means
closure. A pass on S_C authorises **only** a Paper / demo design discussion, never real trading.

## 9. Execution constraints to check before Step 2

- Leverage and position caps of new listings.
- Pre-market and launch phases: OKX pre-market perpetuals with price limits are classified apart.
- Minimum sizes against a 0.5 %-risk plan on 500 USDT.
- Funding caps per venue.
- Availability of real-time listing announcements, for the prospective set.

## 10. Data management

- **S_A:** no download. Read from the existing OKX tape (4.4 GB on disk) with the swing v2 OKX segment audit.
- **S_B:** a metadata list first (Binance `exchangeInfo` `onboardDate`, OKX instruments), then the one-minute
  monthly archives of **only the first two months** after each listing, plus funding. Estimate: 100–200 events
  × 2–3 files × 1.5 MB ≈ **0.3–0.9 GB of zips**, deleted after verified conversion (≈ 0.2–0.6 GB kept).
- **Index:** the established-perpetual index for Binance needs the top-30 monthly series. Daily candles suffice
  for the benchmark (≈ 50 MB).
- **Manifests and checksums** for every file. The disk guard stops at 15 GB free.
- **Exploration** (code tests, mechanics) may use S_A's instruments *outside* [t0, t0 + 31 d], or the data of
  other studies. Results on S_A inside the event windows are read only through the frozen Gate 1 run.

## 11. Technical needs

See `IMPLEMENTATION-PLAN-next-strategy.md`.
