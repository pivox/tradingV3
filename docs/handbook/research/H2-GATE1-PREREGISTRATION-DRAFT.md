# H2 Gate 1 — draft pre-registration (status `DRAFT_NOT_FROZEN`)

**This draft is not frozen and authorises nothing.** It has no sha256. Gate 1 may only run after:

1. the owner amends or accepts it;
2. a frozen version is committed with its sha256;
3. the owner explicitly authorises the run.

It was written on 2026-10-15 **without reading any return, event count or price** of any H2 data. Every number
below is chosen a priori, and its reason is given. Context: `H2-PREREQUISITES-REPORT.md`.

## 1. Question

When a coin's 24-hour return, net of the market, is unusually large while the market itself moved little, does part
of that residual move reverse over the next days? Is the reversal larger than after a move of the same size that the
market shared?

Gate 1 is an **effect study**: it measures a phenomenon, not a strategy. No stop, sizing or portfolio is used here;
those belong to Gate 2.

## 2. Data and labelling

- **Source.** OKX official historical files: 1-minute candles of USDT-margined linear swaps, and funding. Funding
  is not used in Gate 1. Tooling: `python-orchestrator/scripts/research_swing2/okx.py` and `okx_universe.py`.
- **Period.** Decision times in [2023-01-01, 2026-10-01) UTC. Candles from 2022-09 serve as warm-up.
- **Label: development, consumed at the candle level.** The swing v2 OKX test (PR #500) read the non-programme
  families for another hypothesis. The programme bases were read on Binance. **No result of Gate 1 can count as an
  independent validation.**
- **Data step (before any outcome).** Complete the archive for the bases the swing v2 archive excluded (the 20
  programme bases) and for their daily quote volumes. Estimate: ≤ 0.6 GB. Run with nice ≥ 10 and ≤ 2 workers. The
  manifests and checksums are written and committed before the event module runs.

## 3. Universe (point in time)

- On the 1st of each month M: the 30 OKX USDT swaps with the highest median daily quote volume over M−1.
- **Eligibility at that date:**
  - at least 60 days of 1-minute candles before the 1st of M (the β window);
  - at least 15 days with candles in M−1.
- **Excluded:** stablecoins and gold tokens (the `STABLE` set and `XAU`, `XAG`, `USDG`, `EUR`, `GBP`). Programme
  bases are **not** excluded: they are the most liquid X-Perps.
- **Delisted instruments can enter.** A position-free outcome is truncated at the last candle and counted.
- **Secondary universe (reported, never decisive):** the same rule restricted to bases that are X-Perps on
  2026-10-14. It is exposed to survivorship (chosen with today's knowledge).

## 4. Event (all inputs known at the decision time t)

- **Bars.** Hourly bars built from 1-minute candles. A bar is valid if at least 54 of its 60 minutes exist.
  Decisions are made at each hour close t (UTC), on closed bars only.
- **Market factor.** Equal-weight mean of the hourly log returns of the month's universe members, **excluding the
  coin itself**. It needs at least 20 members with a valid bar; otherwise no decision is made at that hour.
  - *Sensitivity, reported only:* BTC as the market.
- **β.** OLS slope without intercept of the coin's hourly log returns on the market's, over [t − 24 h − 60 d,
  t − 24 h). The shock window is excluded from the estimate. At least 80 % valid bars are required. β is not
  clipped.
- **Residual.**
  - R_i = ln(P_i(t) / P_i(t − 24 h)), and R_m likewise for the market.
  - e = R_i − β · R_m.
  - σ_e = √24 × the standard deviation of the hourly residuals r_i − β · r_m over the β window.
  - z = e / σ_e.
- **Market calm.** z_m = R_m / (√24 × the standard deviation of r_m over the same window).
- **Trigger.** |z| ≥ **3** and |z_m| ≤ **1**.
  - k = 3 is chosen a priori: a rare tail move (about 1 in 370 under a normal law, far more often under fat
    tails), large enough to plausibly exceed costs.
  - k = 2.5 is a sensitivity, reported and never selectable.
- **Refractory period.** After an event on a coin, no new event for that coin during 120 h (the longest horizon).
  The first crossing counts.
- **Simultaneous events** on several coins at the same hour are all kept. Their number per hour is reported, and
  inference is by month blocks (§7).
- **Direction.** Reversal: long after e < 0, short after e > 0.
- **No news filter.** The risk that a shock carries information (hacks, unlocks, delistings) is part of what is
  measured, not filtered out with hindsight.

## 5. Outcome

- **Entry reference.** The close of the 1-minute candle ending at t + 5 min (latency).
- **Horizons:** h ∈ {1 d, **3 d (primary)**, 5 d}.
  - 3 d is the middle of the hypothesis' 1–5-day range.
  - 1 d and 5 d are reported and never selectable.
- **Residual outcome**, with β frozen at t:
  - ε_h = ln(P_i(t_e + h) / P_i(t_e)) − β · ln(P_m(t_e + h) / P_m(t_e));
  - y = −sign(e) × ε_h.
- **Raw outcome**, which is what a single-leg trade would earn: y_raw = −sign(e) × ln(P_i(t_e + h) / P_i(t_e)).
- **Order enforced by the code:**
  1. The event list and all exclusions (data gaps, universe, refractory period) are computed and written, with
     their sha256.
  2. Only then does the outcome module read that file.

## 6. Controls (same universe, months, horizons and measurement)

- **C2: shared shock** (the decisive control against absolute reversion).
  - Candidate hours where the coin moved with |z| < 1 and |z_m| ≥ 2: the coin's move is the market's.
  - Each event gets its nearest match on |R_i|, with the same sign of R_i, in the same calendar month and on any
    universe coin. Matching is without replacement where possible; a reused match is counted.
  - Surplus S2 = mean y_raw(events) − mean y_raw(C2).
- **C1: same context, no event.**
  - The same coin, hours drawn at random in the same month, with no event within ±5 days.
  - Matched on the σ_e tercile and on the sign of R_m.
  - Same direction as the matched event: 20 controls per event, seed 20261015.
  - Surplus S1 = mean y_raw(events) − mean of the C1 means.
- **C3: directional exposure.**
  - Long and short events are reported separately, each with its C1 surplus.
  - The β-neutral residual outcome y is the primary effect measure, so a market rebound is not counted as
    reversal.
- **Comparability.** If C2 finds a match for fewer than 80 % of events, Gate 1 is **`STATISTICAL_BLOCKER`**: the
  control is not loosened afterwards.

## 7. Statistics

- 95 % CIs by **month-block bootstrap**: 10,000 draws, seed 20261015. The existing `stats.month_block_ci` is
  reused, with the number of draws set here.
- Half-years: 2023H1 … 2026H1, plus 2026Q3 joined to 2026H1. That makes 7 periods.

## 8. Gate 1 criteria (primary horizon 3 d, pooled sides, primary universe)

| # | Criterion |
|---|---|
| G1 | ≥ 200 events, ≥ 15 distinct bases, events in ≥ 24 calendar months. Otherwise **`INSUFFICIENT_EVIDENCE`** (k is not lowered) |
| G2 | mean y > 0 and its CI lower bound > 0 |
| G3 | mean y ≥ **40 bp**: twice a pessimistic X-Perp round trip of about 20 bp (2 × 5 bp taker + slippage), before funding |
| G4 | S2 > 0 and its CI lower bound > 0 |
| G5 | S1 > 0 and its CI lower bound > 0 |
| G6 | mean y > 0 in ≥ 5 of the 7 half-years that have ≥ 10 events |
| G7 | mean y > 0 after removing the top 5 % of events by y, **and** after removing the single base with the largest contribution |

- **PASS:** G1–G7 all pass.
- **FAIL:** G1 passes and any of G2–G7 fails.
- **Sides.** The long side and the short side are reported with G2–G7 computed separately. A side whose point
  mean y is ≤ 0 is excluded from Gate 2 by this rule, written in advance.
- **A PASS authorises only a Gate 2 proposal.** Gate 2 is a separate pre-registration with stops, sizing, the
  500-USDT portfolio and X-Perp costs. Independent validation would be prospective on X-Perps.

## 9. Code to write (after the freeze, before the run)

- A new module `python-orchestrator/scripts/research_h2/`, reusing the OKX conversion, the universe builder and the
  statistics of `research_swing2`.
- Tests, without real data:
  - no lookahead: the β window ends at t − 24 h, and the event uses closed bars only;
  - residual and β on synthetic series with a known β;
  - z thresholds and the refractory period;
  - bar validity and gaps;
  - determinism: same inputs and seed give the same event file hash;
  - the C2 / C1 matching rules;
  - the module order (outcomes refuse to run without the event file hash).

## 10. Decisions left to the owner before freezing

1. Accept the use of the OKX archive 2023–2026, labelled development and consumed at the candle level.
2. k = 3, primary horizon 3 d, market = leave-one-out equal weight, universe top 30 monthly.
3. The economic floor G3 = 40 bp.
4. Authorise the data step (≤ 0.6 GB), then the single Gate 1 run as a separate decision.
