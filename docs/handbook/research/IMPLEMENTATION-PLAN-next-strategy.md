# Implementation plan — post-listing drift study (H1)

> **Updated 2026-10-14 for `PREREGISTRATION-H1.md` (version 1).** Done:
> - the metadata audit (`scripts/research_listing/audit.py`);
> - the conventions for returns, funding sign, stop and sizing (`scripts/research_listing/conventions.py`);
> - 9 synthetic tests (`tests/test_research_listing.py`).
>
> Changes to the original plan below:
> - **S_B decides Gate 1**; S_A is a screen (§9 of the protocol);
> - matching uses hourly σ over 24 h;
> - delisting: last close plus an unfavourable sensitivity;
> - the prospective test lasts **12 months**.
>
> New steps, in order:
> 1. `events.py` reads the frozen `events-classified.json`.
> 2. `benchmark.py`: OKX index from the on-disk tape; Binance index from hourly klines downloaded at step 4.
> 3. `study.py`: diagnostics and exclusions written before outcomes; month and 2-month block bootstraps; Holm.
>    Tests on synthetic clustered events.
> 4. With the owner's authorisation, the S_A screen.
> 5. With the owner's authorisation, the S_B download and the decisive Gate 1 run.
> 6. `strategy.py` (Gate 2, S_A ∪ S_B, conditional).
> 7. S_C: the prospective recorder (metadata and specifications at listing, a daily public-data capture of the
>    first 31 days, funding) as a separate systemd user timer, never touching `fwd-okx-*`.
>
> Estimates:
> - S_B: 0.4–0.9 GB of zips transient, about 0.1 GB of hourly data;
> - RAM < 2 GB;
> - code: about 1 day for step 3, 1 day for step 6, 1 day for step 7.

This plan becomes work only after the owner approves the draft protocol and it is frozen.

## 1. Reusable modules

| Need | Existing code | State |
|---|---|---|
| OKX historical files (1m candles, funding) and REST history | `python-orchestrator/scripts/research_swing2/okx.py`, `okx_universe.py` | Built for swing v2 OKX (PR #500, unmerged) |
| Listing segments, re-listings, contract values, tick and lot | `research_swing2/okx_audit.py`, `okx_root.py` | Audit of 363 instruments on disk |
| Monthly established-perpetual universe (benchmark index) | `okx_audit.universe` | Reusable; benchmark = its top 30 |
| 1-minute path simulation, stops through gaps, funding at real instants, delisting settlement, censoring | `research_swing2/engine.py` | Tested (41 tests); Step 2 only |
| Binance archives (1m klines monthly / daily, funding monthly) with sha256 | `python-orchestrator/scripts/research_exec/acquire.py` (kinds `klines`, `klinesdaily`, `fundingrate`) | Reusable for S_B |
| Month-block bootstrap | `research_swing2/stats.month_block_ci` | Reuse; extend to paired surpluses |
| Paired block bootstrap and multiple controls | `python-orchestrator/scripts/research_regime/evaluate.py` | Pattern to reuse |

## 2. New code

New package `python-orchestrator/scripts/research_listing/`.

1. **`events.py`**
   - Builds the event list from metadata only: OKX `listTime` and first archived candle, Binance
     `onboardDate`, segment audit.
   - Covers first-venue vs follow-on, classes, eligibility and exclusions.
   - **Reads no return.** It writes `events-S_A.json` and `events-S_B.json` with sha256.
2. **`benchmark.py`**
   - The established-perpetual equal-weight index per venue at hourly resolution.
   - BTC as the secondary benchmark.
3. **`study.py`** (Step 1)
   - Reference prices at T.
   - AR_h for the five horizons, MAE / MFE, funding paid by a short.
   - Controls C1 (volatility-matched, 20 per event), C2 (age) and C3 (shuffled dates, 1,000 draws).
   - Distributions, month-block CIs, Holm adjustments and the Gate 1 verdict.
   - **One frozen run per set.** The output is refused if it exists (claim file, as in swing v2).
4. **`strategy.py`** (Step 2, only after Gate 1)
   - The fixed short rule on the swing v2 engine, with the σ24-scaled stop, the time exit and real funding.
   - The 500-USDT portfolio (0.5 % / 1 %, two slots, skipped events counted, venue minimums).
   - The control strategy and the Gate 2 verdict.
5. **`acquire_listings.py`**
   - S_B metadata, then targeted downloads of the first two months after each listing.
   - Wraps `research_exec.acquire`, with the disk guard.

## 3. Tests (before any frozen run)

- **Anti-lookahead:**
  - event, class and eligibility computed from data ≤ T only;
  - benchmark membership from the previous month;
  - σ24 from [t0, T] only;
  - a test that perturbs prices after T and checks every decision-time quantity is unchanged.
- **Event logic:**
  - first-venue vs follow-on;
  - re-listing exclusion;
  - delisting before T + h (settlement proxy);
  - instruments with < 20 trading hours on day 1.
- **Controls:**
  - C1 picks only established instruments at T, with nearest volatility;
  - C2 dates are inside the instrument's life;
  - C3 shifts stay within the data.
- **Statistics:**
  - month-block bootstrap on synthetic clustered events: the CI widens with clustering;
  - Holm adjustment;
  - gate evaluation on synthetic pass / fail cases.
- **Step 2:**
  - stop through a gap at the next open plus slippage;
  - funding sign for shorts;
  - the portfolio refuses a third concurrent risk unit;
  - skip below the minimum size.

## 4. Resources (estimated)

| Item | Estimate |
|---|---|
| Disk, S_A | 0 GB new (tape on disk) |
| Disk, S_B | 0.3–0.9 GB of zips transient, 0.2–0.6 GB kept |
| Disk, benchmark daily data | < 0.1 GB |
| RAM | < 2 GB (event windows of 31 days, not full series) |
| CPU, Step 1 | minutes per set (≈ 250 events × 20 controls × 5 horizons) |
| CPU, Step 2 | tens of minutes (1,000 control draws) |
| Code | Step 1 ≈ 1 day with tests; Step 2 ≈ 1 day |
| Prospective S_C | a daily timer (metadata + new listings), like the forward OKX services but separate; to be designed only after S_B |

The forward OKX services (`fwd-okx-*`) keep priority and are never touched. Concurrency: nice 10, 1–2 workers.

## 5. Steps, each independent and reviewable

1. Owner review of the draft protocol; amendments; **freeze** (sha256).
2. `events.py` + tests. Produce S_A and S_B event lists from **metadata only**; publish counts (no returns).
3. `benchmark.py` + `study.py` + tests (synthetic data only).
4. **Gate 1 on S_A** (one run). Stop if it fails.
5. Acquire S_B (estimate first), then **Gate 1 confirmation on S_B** (one run). Stop if it fails.
6. `strategy.py` + tests, then **Gate 2 on S_A**, then on S_B. Stop at the first failure.
7. Prospective S_C protocol (fixed duration), owner's go, timer.

## 6. Risks

| Risk | Mitigation |
|---|---|
| Lookahead in "first listing" (using later knowledge of the other venue) | Event list built from listing times only; follow-on status uses only earlier listings |
| Survivorship (delisted instruments missing from archives) | Count the instruments without archive files per listing year; report; S_B from Binance archives (delisted symbols included) |
| Squeezes gapping through the stop | Next-open execution plus pessimistic slippage; worst-trade gate |
| Funding caps and changes of cadence | Real settlements; the funding paid by shorts is reported as its own distribution |
| Clustering of listings in hot markets | Month-block bootstrap, effective number of months, half-year robustness |
| Thin first-month liquidity (no book history) | Slippage assumptions stated; prospective set with real quotes if Gate 2 passes |
| Contamination | S_A's event windows were never part of any strategy result; S_B never read; S_C prospective |
