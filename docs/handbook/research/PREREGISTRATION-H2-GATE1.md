# H2 Gate 1 — pre-registration (status `FROZEN`, re-frozen with Amendment A1, not executed)

**This text is frozen (§15) and authorises nothing by itself.** It was first frozen on 2026-10-15T13:56:41Z (v1,
kept as history in §15) with the owner's C2 decision (first-crossing candidates, §6, §14). Before any read of H2
data, the owner amended C2 (**Amendment A1**, 2026-10-15: matching pool of three calendar months M−1, M, M+1, see
the section of that name before §15). The amendment passed its pre-declared synthetic acceptance rule (ACCEPT) and
the amended text was re-frozen: its body sha256 and the code digest of `python-orchestrator/scripts/research_h2/`
are written on the new `FROZEN` line (§15) and in `PREREGISTRATION-H2-GATE1.sha256`. The single Gate 1 run needs a
**separate, explicit go** from the owner (the claim refuses to start without it); at the re-freeze that go has not
been given.

It replaces `H2-GATE1-PREREGISTRATION-DRAFT.md` (the draft, kept unchanged). The owner's four choices of the draft are
kept unchanged: **k = 3, primary horizon 3 d, market = leave-one-out equal weight of the monthly top 30, G3 = 40 bp**,
on the OKX 2023–2026 archive labelled **development**. Every change from the draft is listed in §10.

Written on 2026-10-15 **without reading any H2 return, outcome, residual, z-score, event count or event list**. The
candles of the data step were only validated (headers, timestamps, sizes, sha256). Every power or caliper number below
comes from **synthetic** series.

## 1. Question

When a coin's 24-hour return, net of the market, is unusually large while the market itself moved little, does part
of that residual move reverse over the next days? Is the reversal larger than after a move of the same size that the
market shared?

Gate 1 is an **effect study**: it measures a phenomenon, not a strategy. No stop, sizing or portfolio is used here;
those belong to Gate 2.

## 2. Data and labelling

- **Source.** OKX official historical 1-minute candles (last price) of USDT-margined linear swaps. No funding is read
  in Gate 1, and none was downloaded.
  - The 20 programme bases (BTC ETH SOL XRP DOGE BNB ADA LTC LINK AVAX OP ARB ATOM INJ NEAR TIA SEI APT FIL SUI): the
    H2 data step of 2026-10-15, `tradingV3-private/research/20261015T1200Z-tape-okx-h2/`, raw zips from
    `static.okx.com` kept unconverted. Manifest `manifest-h2-okx-candles.json`, **sha256
    `95794fdd3395d31b7adc138e318a0df5669738505e1fae524646e0df486f0d00`**: 1,016 files (896 monthly 2022-09 to 2026-09,
    120 daily 2026-10-01 to 2026-10-06), 796,419,552 bytes, every file checked (zip CRC, Content-Length, ETag = MD5,
    one CSV with the expected header, open times inside the file's UTC+8 period, no duplicate, sorted, no foreign
    instrument). 84 monthly files are absent, all before the instrument's first archived month (SEI 38, INJ 14,
    TIA 14, SUI 8, ARB 6, BNB 3, APT 1); no hole after the first archived month. Kept as data-quality notes, not
    failures: `confirm ≠ 1` on up to 372,185 rows per instrument (6,855,592 in total over the 20; the known OKX
    flag of the 2023-09..11 and 2024-02..07 files; archived
    history is final), and some files with fewer minutes than their period (listing months).
  - Every other family: the swing v2 monthly archive `20261014T113000Z-tape-okx-swing-v2-monthly/` (converted by
    `research_swing2.okx_universe`): `manifest-candles.json` sha256 `516e7183…5d01`, `daily-quote-volume.json`
    sha256 `7c262b44…beedd`, and the 9,665 npz files with a period up to 2026-10-06 (digest of their sha256 list
    `b99f42f4…cc22`). The 1,602 npz files dated 2026-10-07 to 2026-10-12 are never opened. The one
    `daily-quote-volume.json` file (days up to 2026-10-12) is read whole; its days on or after 2026-10-06 are
    discarded in memory before use.
  - The full hashes are constants of `research_h2/protocol.py` and are re-checked by every claim.
- **Period.** Decision times t (hour closes, UTC) in [2023-01-01T00:00, 2026-10-01T00:00). Candles from 2022-09 serve
  as warm-up. Candles opening at or after **2026-10-06T16:00Z** are dropped and no file with a later period is opened;
  the last outcome needs at most 2026-10-06T00:05Z. The forward OKX windows (from 2026-10-13) are never read.
- **Label: development, consumed at the candle level.** The swing v2 OKX test (PR #500) read the non-programme
  families for another hypothesis; the programme bases were read on Binance. **No result of Gate 1 can count as an
  independent validation.** In addition:
  - the 60-day β windows read the first 42 days of each series, which no earlier result had used; they become
    consumed;
  - a future prospective validation on X-Perps starts after Gate 1 and reuses none of the windows W0 to W2 reserved
    for the forward OKX test.

## 3. Universe (point in time)

- **Rule.** On the 1st of each month M (M = 2023-01 … 2026-09), the 30 OKX USDT swaps with the highest **median
  daily quote volume over M−1**. Ties: instrument id.
- **Eligibility at that date:**
  - the instrument's first archived 1-minute candle is at least 60 days before the 1st of M (the β window);
  - at least 15 **reliable** UTC days in M−1: a day with at least 720 minutes, every one of them carrying a quote
    volume. Only reliable days enter the median.
- **Volumes, from the downloaded archives only.** Swing v2 families: the archive's `daily-quote-volume.json` as is.
  Programme bases: computed from their zips by the same per-minute rule (archived quote volume; else base volume ×
  close; else contracts × contract value × close), the contract value being **inferred** from the minutes that carry
  both quote volume and contracts. No REST call and no instruments request is made by H2. The rule is the same but
  not the contract value: when it built that JSON on 2026-10-14, `research_swing2.okx_universe.volumes` used the live
  `ctVal` of the REST instruments list for minutes carrying contracts only, so the swing v2 volumes embed that
  2026-10-14 value where the programme bases use the inferred one.
- **Excluded:** stablecoins and gold/fiat tokens (`research_swing2.okx.STABLE` plus XAU, XAG, USDG, EUR, GBP).
  Programme bases are **not** excluded.
- **Delisted instruments can enter.** A position-free outcome is truncated at the coin's last close and counted (§5).
- **Code path:** `research_h2/universe.py` (`build`, `gather`): the rule of
  `research_swing2.okx_universe.monthly_universe` with the warm-up set to 60 days and the programme bases admitted.
- **Secondary universe (reported, never decisive):** the events of the primary run whose base is one of the **160 live
  crypto X-Perps** (`instCategory` 1, `ruleType` xperp) of the OKX Europe instruments list fetched on
  2026-10-15T05:35Z (`tradingV3-private/research/20261015T0100Z-h1-xperp-audit/raw/eea-futures.json`, sha256
  `732044a8a7ce9dd31f1025f1a501c72ee0b268ef87c30d229e444beac9c44f8d`). The 160 bases are written in
  `protocol.XPERP_BASES`. The market factor is unchanged. It is exposed to survivorship (chosen with today's
  knowledge). The match is on today's base symbol, with no alias map: renamed or differently spelled assets are
  left out of the secondary universe (for example RNDR, now RENDER; MATIC, now POL; FTM, now S; the X-Perp `QUANT`
  does not match OKX `QNT`).

## 4. Event (all inputs known at the decision time t)

- **Bars.** Hourly bars on a UTC grid from 2022-09-01T00:00, built from 1-minute candles. Bar b covers candle open
  times [h, h + 60 min) and closes at h + 60 min. It is valid if at least 54 of its 60 minutes exist; its close is the
  close of its last existing minute. Decisions are made at each hour close t, on bars closed at or before t.
- **Hourly returns.** r(b) = ln C(b) − ln C(b − 1), defined when both bars are valid.
- **Market factor.** For a decision in month M: the equal-weight mean of the hourly log returns of **the members of
  M**, **excluding the coin itself**, among those with a defined return at that hour. It needs at least 20 such
  members; otherwise the market return of that hour is undefined. The same membership (that of the decision month)
  is used for every hour of the decision: the β window, the shock window and the outcome.
  - *Sensitivity, reported only:* BTC-USDT-SWAP as the market (BTC itself has no decision).
- **β.** OLS slope without intercept of the coin's hourly returns on the market's, over the 1,440 hourly returns
  ending at t − 24 h (bars b_t − 1,463 … b_t − 24, where b_t is the bar closing at t). The shock window is excluded
  from the estimate. At least 80 % of the 1,440 hours must have both returns defined (1,152). β is not clipped.
- **Residual.**
  - R_i = ln(C_i(t) / C_i(t − 24 h)), both bars valid.
  - R_m = the sum of the 24 hourly market returns of (t − 24 h, t]; all 24 must be defined.
  - e = R_i − β · R_m.
  - σ_e = √24 × the sample standard deviation (n − 1) of the hourly residuals r_i − β · r_m over the β window.
  - z = e / σ_e.
- **Market calm.** z_m = R_m / σ_m, with σ_m = √24 × the sample standard deviation of r_m over the same hours.
- **Trigger.** |z| ≥ **3** and |z_m| ≤ **1**.
  - k = 3 is chosen a priori: a tail move large enough to plausibly exceed costs. The 3σ threshold is **nominal**:
    the √24 scaling assumes no autocorrelation of hourly returns, so "about 1 in 370 under a normal law" is not an
    event rate.
  - k = 2.5 is a sensitivity, reported and never selectable.
- **Refractory period.** After an event on a coin, no new event for that coin during 120 h: a trigger less than 120 h
  after the coin's last event is suppressed (and listed). The first crossing counts.
- **Exclusions, counted per month and written with the events:** coin bar invalid at t or t − 24 h; market shock window
  incomplete; β window below 80 %; degenerate variance. A coin is a candidate only in the months it is a member.
- **Simultaneous events** on several coins at the same hour are all kept. Their number per hour is reported, and
  inference is by month blocks (§7).
- **Direction.** Reversal: long after e < 0, short after e > 0.
- **No news filter.** The risk that a shock carries information (hacks, unlocks, delistings) is part of what is
  measured, not filtered out with hindsight.

## 5. Outcome

- **Entry reference (latency).** t_e = t + 5 min. The entry price is the close of the 1-minute candle ending at
  t + 5 min; if it is missing, the first 1-minute close in [t + 5 min, t + 20 min]. No close in that interval: the
  observation has no outcome (counted).
- **Horizons:** h ∈ {1 d, **3 d (primary)**, 5 d}; 1 d and 5 d are reported and never selectable.
- **Exit price.** T = t_e + h. The first 1-minute close in [T, T + 60 min]. For the coin itself only, when there is
  none (a delisting or a gap), the last close after the entry and before T is used and the outcome is flagged
  `truncated` and counted. Its close time T' then replaces T for the market of that observation (below), so the
  residual compares the same interval.
- **Market price P_m and market outcome.** P_m is not an index level: the market log return over [t_e, T] is the
  **equal-weight mean, over the other members of the decision month M(t), of each member's log return between its
  entry close and its exit close**, both taken with the same bounded delays as above and **without** truncation; it
  needs at least 20 members, otherwise the event has no residual outcome (counted). For the BTC sensitivity it is
  BTC's return. When the coin's own exit is truncated at T', every member's (and BTC's) exit is the first close in
  [T', T' + 60 min] instead: no market move after the coin's last close enters its residual.
- **Residual outcome**, with β frozen at t:
  - ε_h = ln(P_i(exit) / P_i(entry)) − β · (market log return over [t_e, T]);
  - y = −sign(e) × ε_h.
- **Raw outcome**, what a single-leg trade would earn before costs: y_raw = −sign(e) × ln(P_i(exit) / P_i(entry)).
- **Units.** Log returns; 1 bp = 10⁻⁴.
- **Order enforced by the code:**
  1. The event module computes and writes the event list, every exclusion, and the C1 and C2 control hours, in
     `h2-events.json` with its sha256 beside it. No outcome is computed there. Each control candidate's own z, z_m
     and σ_e are decision-time quantities, and whether an hour is a C2 candidate (a first crossing, §6) depends only
     on that hour and the coin's earlier hours. The selection is not: the C2 matching is done after the fact over the
     calendar months M−1, M and M+1 around the event's month M (Amendment A1), so which control an event gets depends
     on candidate hours up to the end of the following month (§9.2), and C1 excludes hours near the coin's later
     events (§6).
  2. Only then does the outcome module read that file; it refuses to run unless the file's sha256 equals the one
     recorded when the event stage finished.

## 6. Controls (same universe, horizons and measurement; C2 pool of three calendar months, Amendment A1)

- **C2: shared shock** (the decisive control against absolute reversion).
  - Candidate hours: **first crossings** (owner decision of 2026-10-15, §14). The qualifying hours are the evaluable
    coin-hours of a member coin with |z| < 1 and |z_m| ≥ 2 (the coin's move is the market's). Per coin, in
    chronological order, a qualifying hour is a candidate unless it falls less than **120 h** after the coin's last
    candidate: then it is skipped and does not restart the period; one exactly 120 h after is allowed. An "episode"
    is delimited by this refractory period, not by a contiguous run of qualifying hours: a run that starts inside the
    120 h gets its candidate at its first hour at or after 120 h, and a run longer than 120 h gets a second
    candidate. This is the event rule of §4
    (same code path, `events.first_crossings`), for symmetry with the event definition. The period is per coin, runs
    across calendar months and across the sign of R_i. The kept and suppressed candidate counts are written in the
    event file (`candidates_kept`, `candidates_suppressed_by_refractory`).
  - **Matching pool: three calendar months (Amendment A1, owner, 2026-10-15, before any read).** The pool of an event
    whose decision hour falls in calendar month M holds the candidates of the calendar months **M−1, M and M+1**
    (instead of M only). The window is **clipped to the decision period** (2023-01 … 2026-09): for an event of
    2023-01 the pool is 2023-01 and 2023-02; for an event of 2026-09 it is 2026-08 and 2026-09. Candidates are
    decision hours only (§2), so no hour outside the decision period enters a pool.
  - Unchanged from the draft: each event gets the candidate with the nearest |R_i|, with the same sign of R_i, from
    its pool, on any universe coin. Events are matched greedily in chronological order (ties: instrument id); ties
    between candidates go to the earliest hour, then the lowest instrument id. Matching is without replacement where
    possible: an unused candidate first, else an already used one (a reuse, counted). A candidate used by one event
    counts as used for every later event whose window holds it. The control's own month (its market membership, §4,
    §5) is the month of its own hour; the number of controls taken outside the event's month is written in the
    event file (`matched_outside_event_month`).
  - **Size caliper: none** (§10.2): any candidate of the pool may be matched, as in the draft.
  - The C2 control's outcome uses the event's direction: y_raw(C2) = −sign(e_event) × ln(P_j(exit) / P_j(entry)) at
    the control hour, with the same latency and bounded delays.
  - Surplus S2 = mean y_raw(events) − mean y_raw(matched C2 controls with an outcome).
  - **Reported decomposition:** the mean y_raw of the C2 controls split into its market part
    (−sign(e) × β_j × market return) and its residual part, and the same split for the events. Each split is
    computed on one sample (the controls, or events, with both a raw outcome and a market return); the C2 mean over
    every matched control with an outcome is reported beside it. A market that keeps moving after shared shocks
    inflates S2; one that reverts deflates it.
- **C1: same context, no event.**
  - The same coin, decision hours of the same calendar month at which the coin is evaluable, with **no event of that
    coin within ±5 days** (±120 h) of the hour — the draft's rule, kept (§10.4).
  - Matched on the σ_e tercile (terciles of σ_e over the coin's evaluable hours of that month) and on the sign of R_m.
  - Same direction as the matched event. Up to 20 controls per event, drawn without replacement; the generator of
    event i is seeded with (20261015, i), where i is the event id: events are numbered in (instrument id, hour)
    order, the detection order. Fewer than 20 eligible hours: all of them; none: the event has no C1 (counted).
  - Surplus S1 = mean over events with at least one C1 outcome of (y_raw(event) − mean y_raw(its C1 controls)).
  - Declared limit: the ±5 d rule conditions on the absence of the coin's other shocks, including later ones.
  - Declared limit: only the hours around **events** are excluded, not those around triggers suppressed by the
    refractory period. A C1 hour can be eligible within 120 h before such a suppressed trigger, and its 3-day
    outcome then contains that shock's reversal (under the alternative this lowers S1: a bias against a G5 PASS).
    The number of affected C1 draws is written in the event file (`draws_within_120h_of_a_suppressed_trigger`).
- **C3: directional exposure.**
  - Long and short events are reported separately, each with its C1 surplus.
  - The β-neutral residual outcome y is the primary effect measure, so a market rebound is not counted as reversal.
- **Comparability.** The C2 match rate is the share of the G1 sample (events with a 3-d y and y_raw) whose C2 control
  has a 3-d outcome. If it is below **80 %**, Gate 1 is **`STATISTICAL_BLOCKER`**: the control is not loosened
  afterwards.

## 7. Statistics

- **95 %** CIs by **month-block bootstrap**: blocks are the calendar months (UTC) of the decision hour, resampled with
  replacement; **10,000 draws, seed 20261015** (one generator per interval). The existing
  `research_swing2.stats.month_block_ci` returns a fixed 90 % interval, so `research_h2/stats.py` implements the same
  bootstrap with the level as a parameter (set to 0.95).
- The interval of a surplus resamples the events and their controls with the same draw of months (a control belongs to
  its event's month).
- A draw whose resample holds no observation of a sample (no C1 or no C2 control) gives no value and is dropped. The
  number of finite draws behind each interval is reported, and an interval resting on fewer than 10,000 is flagged
  (`ci_incomplete`). This is not a gate.
- Half-years: 2023H1 … 2026H1, plus 2026Q3 joined to 2026H1. That makes 7 periods.

## 8. Gate 1 criteria (primary horizon 3 d, pooled sides, primary universe)

The sample is the events with a defined 3-d y and y_raw.

| # | Criterion |
|---|---|
| G1 | ≥ 200 events, ≥ 15 distinct bases, events in ≥ 24 calendar months. Otherwise **`INSUFFICIENT_EVIDENCE`** (k is not lowered) |
| G2 | mean y > 0 and its CI lower bound > 0 |
| G3 | mean y ≥ **40 bp**, before funding (§9.3) |
| G4 | **S2 > 0 as a point estimate** (owner amendment of 2026-10-15). Its 95 % CI is computed and published but is **not decisive** |
| G5 | S1 > 0 and its CI lower bound > 0 |
| G6 | mean y > 0 in ≥ 5 of the half-years that have ≥ 10 events (7 periods) |
| G7 | mean y > 0 after removing the top 5 % of events by y (⌈0.05 n⌉ events), **and** after removing the single base with the largest sum of y |

- **Verdict order:** G1 fails → `INSUFFICIENT_EVIDENCE`; else C2 match rate < 80 % → `STATISTICAL_BLOCKER`; else
  **PASS** when G2–G7 all pass, **FAIL** otherwise.
- **Every criterion is reported**, with its value, and the report states whether a FAIL is **due to G4 alone**
  (`fail_due_to_G4_alone`), as the owner asked.
- **Sides.** The long side and the short side are reported with G2–G7 computed separately. A side whose point mean y
  is ≤ 0 is excluded from Gate 2 by this rule, written in advance.
- **Reported, never decisive:** horizons 1 d and 5 d; the secondary universe (pooled and per side); the k = 2.5 and
  BTC-market sensitivities (no controls: mean y, CI, half-years, G7 trims); the C2 decomposition.
- **A PASS authorises only a Gate 2 proposal.** Gate 2 is a separate pre-registration with stops, sizing, the
  500-USDT portfolio, X-Perp costs and its own net criteria on y_raw with the real funding payments. Independent
  validation would be prospective on X-Perps.

## 9. Power, costs and what a result means (synthetic, describes the design, changes nothing)

> **Note (Amendment A1).** §9.1 and §9.2 describe the v1 month-only C2 pool and are kept as the record of the v1
> design; the C2 pool is superseded by Amendment A1 (calendar months M−1, M, M+1), whose synthetic results are in
> the section "Amendment A1".

### 9.1 Power of the frozen gate (amended C2)

Synthetic panels only. Generator of the arbitration's verifier (`tradingV3-private/research/20261015T1200Z-h2-gate1/sim/`,
`sim_h2.py` sha256 `474a94194046c79f3fefdbeabc163ea6affcbedde50abf4a77cb4deada732980`): one common market factor of
hourly sd 0.7 %, 30 coins, β ~ U(0.7, 1.5), the decision period of §2, the 60-day window ending at t − 24 h, k = 3,
120 h refractory. Six scenarios: normal, Student t4 and stochastic volatility + t4 innovations, each with coin hourly
R² ~ U(0.35, 0.75) and U(0.20, 0.50); 100 panels per scenario. A constant true effect μ is added to y and y_raw of
every event; the residual outcome is rescaled to sd(y). **C2 first crossings** (§6), caliper none, amended G4, C1
±5 d, 95 % month-block CIs with 10,000 draws, `STATISTICAL_BLOCKER` below 80 %.

The amended-C2 run is `sim/c2-first-crossing/` (`SHA256SUMS` sha256
`9740863e45353a97ee855f71522192e7b2db5cbf513d54008161fdea9c3b0405`; `power_fc.py`
`092898bef07567b71a83a0d2b3dcc25e25196ebfd338523b5e53facedfc1da71`, `power-results.json`
`310e2b8c67d0a2d0084b7b7be78a93c732ba93b3c8e385db9d65c701aea358e1`, `summary.md`
`0917faf54cab640fdcba7674a303dbe15bf0772d2b3303cbf17779d93ecfce05`). It runs `sim_h2.power_run` unchanged with one
substitution, the C2 pool restricted to first crossings, on the **same seeds** as the drafted-C2 run
(`sim/power-results.json` sha256 `6d4b63a2…f6b983`, listed in `sim/SHA256SUMS` sha256 `fc5d9ffa…039cc8`): panels,
events and C1 draws are identical, the comparison is paired. No scipy is installed in the pinned runtime, so the one
scipy call of `sim_h2.py` (an AR(1) `lfilter`) is replaced by an exact numpy recursion (`scipy_shim.py`);
`check_reproduction.py` reproduces 12 drafted-C2 panels of `sim/power-results.json` exactly (every gate flag and
every value).

P(PASS), pooled mean over the six scenarios [min–max across scenarios]:

| True effect μ | sd(y) 500 bp | sd(y) 800 bp | sd(y) 1,200 bp |
|---:|---:|---:|---:|
| 0 bp | 0.00 [0.00–0.00] | 0.00 [0.00–0.00] | 0.00 [0.00–0.00] |
| 40 bp | 0.05 [0.00–0.09] | 0.02 [0.00–0.04] | 0.01 [0.00–0.03] |
| 80 bp | 0.36 [0.16–0.49] | 0.18 [0.06–0.24] | 0.07 [0.01–0.12] |
| 150 bp | 0.78 [0.54–0.89] | 0.70 [0.50–0.81] | 0.42 [0.27–0.49] |
| 300 bp | 0.99 [0.97–1.00] | 0.99 [0.97–1.00] | 0.97 [0.94–0.99] |

P(FAIL due to G4 alone), same layout:

| True effect μ | sd(y) 500 bp | sd(y) 800 bp | sd(y) 1,200 bp |
|---:|---:|---:|---:|
| 0 bp | 0.00 [0.00–0.00] | 0.00 [0.00–0.00] | 0.00 [0.00–0.00] |
| 40 bp | 0.02 [0.00–0.04] | 0.00 [0.00–0.01] | 0.00 [0.00–0.01] |
| 80 bp | 0.10 [0.03–0.25] | 0.03 [0.00–0.07] | 0.00 [0.00–0.01] |
| 150 bp | 0.14 [0.03–0.35] | 0.11 [0.01–0.31] | 0.04 [0.01–0.13] |
| 300 bp | 0.01 [0.00–0.03] | 0.01 [0.00–0.03] | 0.02 [0.00–0.06] |

For comparison, the drafted C2 (all qualifying hours) on the same panels gave P(PASS) 0.13 / 0.08 / 0.04 at 80 bp,
0.45 / 0.42 / 0.27 at 150 bp and 0.89 / 0.88 / 0.84 at 300 bp (sd 500 / 800 / 1,200 bp, pooled), with P(FAIL due to
G4 alone) up to 0.47 pooled (0.82 in one scenario) at 150 bp.

Per scenario, at sd(y) 800 bp (amended / drafted C2): normal R² 0.35–0.75: 0.24 / 0.15 at 80 bp, 0.78 / 0.62 at
150 bp; normal R² 0.20–0.50: 0.24 / 0.10, 0.69 / 0.47; t4 R² 0.35–0.75: 0.18 / 0.09, 0.78 / 0.64; t4 R² 0.20–0.50:
0.24 / 0.08, 0.81 / 0.53; SV + t4 R² 0.35–0.75: 0.10 / 0.04, 0.64 / 0.21; SV + t4 R² 0.20–0.50: 0.06 / 0.00,
0.50 / 0.07. At 300 bp: 0.97–1.00 in every scenario (drafted: 0.60–1.00).

Stated accurately:

- **The gate is valid:** P(PASS | μ = 0) = 0.00 in every cell. A PASS is informative.
- **Power is still low for small effects.** An effect of 80 bp is detected with probability 0.07–0.36 pooled (at
  most 0.49 in one scenario), 150 bp with 0.42–0.78 pooled (0.27–0.89 across scenarios), 300 bp with 0.97–0.99.
  Under clustered volatility 150 bp is detected 0.50–0.64 of the time at sd(y) 800 bp.
- **A FAIL reads "no effect large enough to beat the shared-shock control and the robustness gates", not "no
  effect".**
- **P(FAIL due to G4 alone)** falls to at most 0.14 pooled (0.35 in one scenario) at 150 bp and 0.01–0.02 at 300 bp.
- **Other gates** do not depend on C2 and are unchanged from the drafted-C2 run (same panels): at small effects G7
  binds first: at sd 800, P(G7) = 0.03 at 40 bp and 0.32 at 80 bp; P(G2) = 0.33 and 0.80; P(G5) = 0.15 and 0.36.
  Under the null: P(G2) ≈ 0.04, P(G5) ≈ 0.05; S1 averages −5 to +9 bp across the scenario and sd(y) cells.
- **C2 match rate and the 80 % rule.** Over the 600 panels, the first-crossing match rate is 0.803 at its minimum (one
  t4 run), its 1st percentile 0.838, 31 runs below 0.85 and **none below 0.80** (P(`STATISTICAL_BLOCKER`) = 0.00 in
  every cell). The pool is 8 to 13 times smaller (§9.2), so the margin above 80 % is **thinner** than with the
  drafted C2 (minimum 0.820, 1st percentile 0.847, 8 runs below 0.85, same panels); the first-crossing rate is never
  higher than the drafted one in any of the 600 paired runs (its pool is a subset). The module check of §9.2 gives a
  minimum of 0.841 over 600 more panels. On real data (bar gaps, listings, delistings, coins absent in some months)
  the rate may be lower: a `STATISTICAL_BLOCKER` is a plausible outcome, not a negligible one.
- **The match rate is sensitive to the real scale of z_m.** With caliper `none` and reuse allowed, an event goes
  unmatched only when its (month, sign) pool is empty, so the rate depends almost entirely on how often the market
  shows |z_m| ≥ 2, and the √24 scaling of z_m is nominal (§4). A stress run on fresh synthetic panels
  (`sim/c2-first-crossing/stress/`, `SHA256SUMS` sha256
  `f6e42143616b3e397c6e339100a5323b8bbdf8b0c84ea4800a1eb34703cd3a91`; 100 panels per row, match rate only) gives
  P(match rate < 80 %):
  - at the nominal scale: 0.00 in five scenarios and 0.02 under SV + t4 R² 0.35–0.75 (minimum 0.780);
  - with z_m 10 % smaller than nominal (24 h variance ratio 0.8): **0.26 to 0.56 per scenario** (all-hours pool on
    the same panels: 0.12 to 0.47);
  - with a variance ratio of 0.64: 0.90 to 1.00;
  - with heavier Student-t3 tails at the nominal scale: 0.00 to 0.01 (p10 of the rate 0.83–0.84).
  This risk comes mostly from the (month, sign) pool and the 80 % rule, both kept by the owner; the rule is not
  changed.
- **The caliper stays `none`.** The caliper rule (§10.2) was run on the all-hours pool, on its own 36 fixed seeds.
  The first-crossing pool is a subset of the all-hours pool, so in each of those runs the first-crossing match rate
  with any caliper is at most the all-hours rate with `none`; that rate was already below 90 % in 9 of the 36 runs,
  so no caliper can reach 90 % in every run and the rule's mechanical choice remains `none`. (In the 600 power runs,
  first crossings with `none` fall below 90 % in 242.)
- **Limits of the simulation:** the simulator reimplements the rules; it is not the `research_h2` code (one common
  factor stands in for the leave-one-out top 30; the true β and the true σ are used; C1 is drawn over all hours of
  the month); bar gaps and delistings are not modelled; the effect is constant across events; the dispersion sd(y)
  is assumed, not measured; with 100 panels per scenario the Monte Carlo standard error is ≤ 0.05.

### 9.2 C2 under the null: first crossings reduce the bias against a PASS, they do not remove it

**Drafted C2 (all qualifying hours).** The simulation found it **biased against a PASS** under the null: in a pure
random walk S2 averaged −112 to −284 bp across the six scenarios (sd 79–110 bp), P(S2 > 0 | μ = 0) 0.04–0.08
pooled. Mechanism (`sim/diag-pool-weighting.json`, `sim/diag-c2-null.json`): weighted equally per shock hour, the
forward market move after shared-shock hours averages about 0; weighted equally per (calendar month, sign) pool it
shows +100 to +181 bp of spurious "reversal". A pool's size depends on the future path: a continuation lengthens the
episode and fills the pool with its hours, so matching each event inside its month's pool over-weights short
episodes, which tend to revert. A review confirmed it on the module code (`events.compute` on random-walk panels:
S2 = −117 bp on 90 normal panels).

**Amended C2 (first crossings, owner decision of 2026-10-15).** One candidate per episode removes the episode-length
weighting at its source. Re-checked on the **module code**: `sim/c2-first-crossing/null_module.py` runs the amended
`research_h2.events.compute` (code digest `d52c03848928cd1717385b641d1cb0dc667efb5f4376a20938d6e006f29839b1`, the
digest of the code committed with this text) on random-walk hourly closes laid on the protocol's hourly time grid (no
market data; 45 decision months,
30 coins, the six generator scenarios of §9.1, 100 panels each, seeds 50261015 + 1000 × scenario + k) and scores
forward 72 h hourly returns, with no effect. The drafted C2 is matched on the same panels for a paired comparison.
Results (`null-module-results.json` sha256 `458a463caf4dd99559c67ea92dcd0ddf13b0cab82f6e44cc0b0c97d8cebd3bdb`), S2
mean ± sd over panels:

| Scenario | Events (median) | Candidates kept / suppressed | S2 first crossings | P(S2 > 0) | C2 y_raw (market part) | Match min / mean | S2 drafted C2 | S1 |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| normal, R² 0.35–0.75 | 522 | 3,745 / 26,923 | −50 ± 90 bp | 0.25 | +50 (+52) bp | 0.889 / 0.946 | −130 ± 85 bp | −3 ± 52 bp |
| normal, R² 0.20–0.50 | 522 | 3,781 / 27,447 | −53 ± 119 bp | 0.33 | +56 (+53) bp | 0.887 / 0.950 | −164 ± 117 bp | +1 ± 63 bp |
| t4, R² 0.35–0.75 | 677 | 3,613 / 29,110 | −54 ± 73 bp | 0.21 | +54 (+49) bp | 0.865 / 0.924 | −132 ± 75 bp | −7 ± 41 bp |
| t4, R² 0.20–0.50 | 684 | 3,616 / 28,980 | −57 ± 107 bp | 0.30 | +59 (+52) bp | 0.848 / 0.934 | −155 ± 112 bp | +3 ± 57 bp |
| SV + t4, R² 0.35–0.75 | 1,182 | 2,904 / 35,725 | −95 ± 123 bp | 0.21 | +95 (+91) bp | 0.841 / 0.894 | −241 ± 120 bp | −0 ± 57 bp |
| SV + t4, R² 0.20–0.50 | 1,180 | 2,903 / 35,288 | −91 ± 133 bp | 0.25 | +90 (+81) bp | 0.855 / 0.908 | −302 ± 133 bp | −2 ± 77 bp |
| All 600 panels | | | −67 ± 111 bp | 0.26 | | 0.841 min | −187 ± 125 bp | |

The simulator gives the same picture on its own panels (§9.1 run, μ = 0, sd(y) 800 bp): S2 −20 ± 87 to −118 ± 104 bp
per scenario (−55 ± 101 bp over the 600 panels; drafted C2 −112 to −284 bp), P(S2 > 0) 0.14–0.44 per scenario and
0.31 pooled (drafted C2 at the same sd: 0.00–0.10 per scenario, 0.06 pooled).

- **The bias is reduced by about two thirds, not removed.** Under the simulated null S2 still averages −50 to −95 bp
  on the module code, almost entirely through the market part of the C2 outcome (+49 to +91 bp). A plausible
  mechanism is that matching inside (calendar month, sign) pools with the nearest |R_i| still selects shared shocks
  that revert; this was **not decomposed** (no random-pick or per-shock-weighted variant was run on the
  first-crossing pool to separate pool weighting from size matching). The events' own y_raw
  averages −1 to +3 bp; S1 shows no bias (−7 to +3 bp).
- **Consequence.** C2 remains **conservative under the simulated null**: it pushes against a PASS, now by about
  50–100 bp instead of 110–300 bp. Its market-dynamics component has an unknown sign on real data (a market that
  keeps moving after shared shocks inflates S2, one that reverts deflates it); the C2 decomposition (§6) reports it.
- **Match rate.** The first-crossing pool holds about 2,900–3,800 candidates per panel instead of 30,700–38,600
  qualifying hours (8.2 times fewer under normal innovations, 13.3 times under SV + t4); no run fell below the 80 %
  `STATISTICAL_BLOCKER` line (0 of 600 module panels, minimum 0.841; 0 of 600 simulator panels, minimum 0.803), with
  a thinner margin than the drafted C2 and a strong sensitivity to the scale of z_m (§9.1).
- The arbitration note's statement that "the control is unbiased" stays withdrawn.

### 9.3 Costs and the floor G3

Public X-Perp order books (snapshot 2026-10-15 ≈ 10:05 UTC, 29 liquid contracts, 50 USD orders, taker fees 5 bp per
side, book walked): round trip **16.6 bp median, 26.2 bp p90, 40.6 bp worst**. The draft's "pessimistic 20 bp" is
close to the **median**. The 40 bp floor covers the median 2.4 times, the p90 1.5 times and the worst contract once.
G3 is a relevance threshold above measured execution cost, **excluding funding** (about ±9 bp over 72 h when neutral;
H1's provisions give 27 to 90 bp; its sign for a contrarian trade is not measurable before the freeze). It does not
measure profitability. G7 binds before G3 (removing the top 5 % amounts to requiring about 55–145 bp for sd(y)
500–1,200 bp).

## 10. Changes from the draft

> **Note (Amendment A1).** This list records the v1 design, with the month-only C2 pool; the C2 pool is superseded
> by Amendment A1 (calendar months M−1, M, M+1, see the section of that name).

1. **G4 amended (owner, 2026-10-15).** G4 becomes "S2 > 0 as a point estimate" and is decisive; its 95 % CI is
   published, not decisive. G2, G5, G6 and G7 remain decisive as drafted. A FAIL due to G4 alone is flagged in the
   report. (The events reviewer of the arbitration had classified the drafted G4 as blocking: P(PASS) ≤ 0.03 at
   80 bp and ≤ 0.13 at 150 bp.)
2. **C2 size caliper: none, set by synthetic simulation with a rule written first.** The rule
   (`sim/CALIPER-RULE.md`, **sha256 `3753964e537cf5ebda76f425e4b3e5b0651807674b33b4f6e9d1a1aaec5ce372`**) was hashed
   at 2026-10-15T10:50:28Z and is unchanged; the caliper run ended at 10:51:58Z. The simulator was later edited for
   the power step (file created 11:02:42Z), so the exact version that ran the caliper step was not kept; its current
   version (`sim_h2.py` sha256 `474a94194046c79f3fefdbeabc163ea6affcbedde50abf4a77cb4deada732980`, listed in
   `sim/SHA256SUMS`, sha256 `fc5d9ffa…039cc8`) reproduces `caliper-results.json` (sha256
   `15b450b0b81e594bd4f9e6f103dd7b8df3e1740746dd040f42667efdd89efccd`) and `caliper-run.log` byte for byte (checked
   twice by the reviews). The rule fixed the candidates (none, ±50 %,
   ±35 %, ±25 % on q = |R_c| / |R_e|), the pool and the greedy matching of §6, six scenarios × six fixed seeds, and
   the mechanical choice: the tightest caliper with a match rate ≥ 90 % in **every** run, else `none` with its
   size-gap bias reported. Result (match rate min / mean per scenario, [runs below 90 %]):

   | Scenario | none | ±50 % | ±35 % | ±25 % |
   |---|---|---|---|---|
   | normal, R² 0.35–0.75 | 0.927 / 0.945 [0/6] | 0.927 / 0.945 [0/6] | 0.927 / 0.942 [0/6] | 0.917 / 0.930 [0/6] |
   | normal, R² 0.20–0.50 | 0.939 / 0.955 [0/6] | 0.938 / 0.953 [0/6] | 0.903 / 0.922 [0/6] | 0.844 / 0.870 [6/6] |
   | t4, R² 0.35–0.75 | 0.892 / 0.924 [1/6] | 0.890 / 0.923 [1/6] | 0.878 / 0.915 [1/6] | 0.854 / 0.899 [3/6] |
   | t4, R² 0.20–0.50 | 0.880 / 0.931 [1/6] | 0.877 / 0.923 [1/6] | 0.831 / 0.887 [3/6] | 0.748 / 0.830 [6/6] |
   | SV + t4, R² 0.35–0.75 | 0.833 / 0.880 [5/6] | 0.826 / 0.873 [5/6] | 0.809 / 0.861 [6/6] | 0.800 / 0.847 [6/6] |
   | SV + t4, R² 0.20–0.50 | 0.878 / 0.906 [2/6] | 0.831 / 0.876 [4/6] | 0.801 / 0.842 [6/6] | 0.774 / 0.803 [6/6] |
   | Minimum over the 36 runs | 0.833 | 0.826 | 0.801 | 0.748 |

   No caliper reaches 90 % in every run, so **`none`** (the draft's rule) is kept mechanically. Its size-gap bias,
   reported: matches are on average 37–277 bp smaller than their events (median q 0.996–1.000, p10 of q down to
   0.62); if shared shocks revert by a fraction ρ of their size, S2 is biased toward a PASS by about ρ × that gap
   (4–28 bp at ρ = 10 %), far smaller than the opposite pool-weighting bias of §9.2. Unmatched events come from
   (month, sign) cells with no shared-shock hour, so even `none` can fall below 90 % (minimum 0.833; 0.820 in the
   power runs); no synthetic run fell below the 80 % `STATISTICAL_BLOCKER` line (0 of 36 + 600), with a small
   margin under clustered volatility. The **80 % rule is counted with this caliper** (§6). This step ran on the
   drafted all-hours pool, before the C2 amendment (§10.14); the first-crossing pool is a subset of it, so on the
   rule's 36 runs no caliper can exceed the all-hours `none` rate, already below 90 % in 9 of them: the rule's choice
   stays `none` (§9.1).
3. **Corrected power statements (§9.1).** The arbitration note's "detects 150 to 300 bp with good probability" and
   "0.55 to 0.78 at 300 bp without strong volatility clustering" are replaced by the table of §9.1, computed with the
   amended G4 and the amended C2.
4. **C1 keeps the draft's ±5 d rule.** The arbitration's clarification "past-only exclusion (no event in
   (h − 120 h, h])" is **not** adopted: it re-admits the 72 h before the event, whose 3-day outcome contains the
   event's own shock signed against the reversal, and the verifier's synthetic null (`sim_controls.py`) shows it biases
   S1 by +200 to +650 bp toward a G5 PASS, against −90 to +57 bp for the ±5 d rule. The ±5 d rule's conditioning on
   the coin's later shocks is declared as a limit (§6).
5. **CI level.** 95 %, by a bootstrap with the level as a parameter (§7); the reused function returned 90 %.
6. **Universe pinned to the archives** (§3): the exact rule and code path, the volume sources and the reliable-day
   definition are written down; no REST endpoint and no instruments request is used (the verifier noted that
   `okx_audit` calls REST endpoints up to 2026-10-13; it is not used).
7. **Secondary universe defined** without any forbidden directory: the 160 crypto X-Perp bases of a hashed metadata
   snapshot (§3), applied as a subset of the primary events.
8. **P_m and the market outcome defined** (§5), with the membership of the decision month for every hour (§4).
9. **Bounded delays given numbers:** entry within 15 min after t + 5 min, exit within 60 min after T; the coin's own
   exit falls back to its last close (truncation, counted), the market's never does (§5).
10. **G3 justification corrected** (§9.3): 20 bp is close to the median measured round trip, not pessimistic; G3
    excludes funding; Gate 2 will have its own net criteria with real funding.
11. **Data step as executed** (§2): 20 bases, 0.796 GB downloaded (above the draft's "≤ 0.6 GB" estimate, which
    matches the size on disk), no funding, nothing after 2026-10-06; the owner authorised it on 2026-10-15.
12. **Review corrections (2026-10-15), no owner decision changed:** a truncated own exit measures the market to the
    same close time (§5); the C2 decomposition uses one sample per split (§6); C1 near suppressed triggers declared
    and counted (§6); finite bootstrap draws reported (§7); the runtime pinned, one owner go for both stages, input
    hashes re-checked at consumption, a crash leaves the stage consumed (§12); the swing v2 volume `ctVal`, the
    secondary-universe aliases and the volume JSON's later days declared (§2, §3); the C2 selection's dependence on
    later hours stated (§5, §9.2).
13. **Exact definitions added** where the draft was silent: R_m as the sum of 24 hourly market returns; the β window
    bars; sample standard deviations; the refractory boundary (a trigger exactly 120 h after an event is allowed);
    S1 over events with at least one C1 outcome; the C2 match rate over the G1 sample; G6 counts positive half-years
    among those with ≥ 10 events; G7's base removal uses the largest sum of y.
14. **C2 candidates amended to first crossings (owner, 2026-10-15).** A C2 candidate is, per coin, the first hour
    meeting |z| < 1 and |z_m| ≥ 2, then no candidate during 120 h, as for the events (§6; an episode is delimited by
    this refractory period, not by a contiguous run of qualifying hours). Everything else of C2 is unchanged: same calendar month (widened to M−1, M, M+1 by Amendment A1, see the section of that name), same sign of R_i, nearest |R_i|, no caliper
    (§10.2), greedy chronological matching without replacement where possible, reuse counted, the 80 %
    `STATISTICAL_BLOCKER` rule. Reason: the drafted all-hours pool biased S2 against a PASS by −112 to −302 bp under
    synthetic nulls (simulator and module code); first crossings bring it to about −50 to −95 bp on the module code (§9.2) and raise P(PASS) at
    150 bp from 0.27–0.45 to 0.42–0.78 pooled (§9.1). Code: `protocol.C2_CANDIDATES = "first_crossing"`,
    `C2_REFRACTORY_H = REFRACTORY_H`, `events.c2_candidates` through the event helper `events.first_crossings`;
    claims refuse another candidate rule and record it.

## 11. Arbitration clarifications: integrated and not integrated

Integrated (corrected per the arbitration's "Vérification contradictoire"): accurate power statements (§9.1); the
secondary universe without forbidden directories (§3); P_m and the market outcome (§5); the 95 % CI level (§7);
bounded delays with numbers (§5); the C2 decomposition reported (§6); the development label's consumed β windows and
the W0–W2 exclusion (§2); the nominal reading of 3σ (§4); the G3 justification (§9.3); the withdrawal of "the control
is unbiased" (§9.2).

Not integrated: the C1 past-only exclusion (owner decision, §10.4); the ±25 % caliper (replaced by the synthetic
rule of §10.2, which chose `none`); the `okx_audit` segmentation (REST endpoints, §10.6). The arbitration's
first-hour C2 candidates were at first not integrated; the owner adopted first crossings on 2026-10-15 (§10.14).

## 12. Code and order of execution

`python-orchestrator/scripts/research_h2/` (written before the freeze, pinned by the freeze):

| Module | Role |
|---|---|
| `protocol.py` | every constant of this text, the input hashes, the frozen X-Perp list |
| `data.py` | archive reading (H2 zips through `research_swing2.okx.convert_candles`; swing v2 npz), the data-end cut, hourly bars, daily quote volume, bounded-delay prices |
| `universe.py` | the monthly top 30 from the archives (§3), the secondary list |
| `events.py` | β, residual, z, trigger, refractory, exclusions, C2 first-crossing candidates, three-month pool (Amendment A1) and matching, C1 draws; writes `h2-events.json` + sha256 |
| `outcomes.py` | refuses without the recorded event-file hash; outcomes, controls, decomposition; the report |
| `stats.py` | month-block bootstrap (level parameter), G1–G7, the verdict, `fail_due_to_G4_alone` |
| `claim.py` | single-use claims and the code digest (model: `research_lev/claim.py`) |
| `cli.py` | `digest`, `claim`, `events`, `outcomes`; fixed locations only |

- **Claims.** Each stage (`events`, then `outcomes`) needs a claim, refused unless: the decisions are set (G4 rule, C2
  caliper, C2 first-crossing candidates, C2 three-month pool of Amendment A1; recorded in the claim); an owner go is given (`--owner-go`, recorded); the
  protocol's body sha256 equals the expected hash and the `FROZEN` line; the `FROZEN` line carries `code sha256 <digest>` equal to the digest of every `research_h2` module plus
  `research_swing2/okx.py` and `research_swing2/okx_universe.py`; the input hashes of §2 match (every H2 zip against
  its manifest). `outcomes` also needs the `events` stage complete with its event file unchanged and the same code.
  `consume` is the first call of a run; it re-checks the protocol, the code, the runtime, the prerequisites and the
  input hashes, then creates its record exclusively: **a second run is refused**. Only an `events` run stopped by
  the memory/disk guard before writing anything may be resumed once; `outcomes` never. **Any other failure** (an
  exception, an interruption) is recorded as `crashed` and leaves the stage consumed: a rerun needs a written
  amendment, reported as such.
- **One owner go covers both stages.** The outcomes claim must carry the same `--owner-go` reference as the events
  claim. Once the events stage completes, the outcomes stage is run with no further decision: nothing in
  `h2-events.json` (the event count, the control hours, the coverage) may be used to decide whether to continue.
  Stopping after the events stage is reported as such, with the reason.
- **Runtime.** `tradingV3-private/research/20261014T090000Z-swing-v2-runtime/bin/python`: Python 3.12.3, numpy
  2.5.4 (pytest 9.1.1 for the tests, run with `--noconftest` because the repository conftest needs sqlalchemy). The
  C1 draws (`Generator.choice`), the bootstrap draws (`Generator.integers`) and `np.quantile` depend on numpy. Each
  claim records `sys.version` and `numpy.__version__`; the outcomes claim, and every consumption, are refused when
  they differ from the claim (and from the events claim).
- **Tests** (`python-orchestrator/tests/test_research_h2.py`, synthetic data only): no lookahead (the β window ends at
  t − 24 h; bars after t change nothing at t); β, residual and z against a direct OLS and a known β; the leave-one-out
  market's 20-member rule and the 80 % β window; z thresholds and the refractory period (per coin, the 120 h boundary);
  closed-bar decisions and the 54-minute bar rule; latency t + 5 min, the bounded entry and exit delays and the
  truncation rule; C1 ±5 d, tercile and sign; C2 first-crossing candidates (per coin, 120 h refractory across months
  and signs, a suppression across a month boundary, independent of row order, spaced and deterministic on a
  synthetic panel, recorded as a decision and written in the claim with the G4 rule and the caliper); C2
  nearest size, month and sign, reuse and caliper; the Amendment A1 window (M−1 … M+1 by calendar month, clipped at
  the first and last month and at a calendar gap, same sign, without replacement across months, reuse counted,
  month-only reproduced with half-width 0, deterministic, controls within ±1 month on a synthetic three-month
  `compute`, the pool recorded as a decision checked by `unresolved_decisions()` and written in the claim); the 80 % rule; the amended G4 and the G4-only flag; G1, G6, G7; the CI level parameter; the module order (no hash, wrong hash, write
  once); single-use claims, resumption and the code digest; the runtime and the single owner go; the input re-check at
  consumption; a crash leaving the stage consumed; the market measured to a truncated exit; the one-sample C2
  decomposition; the finite-draw count; determinism of the event file; the universe rule; the volume rule against
  `research_swing2.okx_universe.volumes`; the archive date cut; the secondary list; a file-based end-to-end run
  (raw zips, npz files and a volume JSON written as a synthetic tape, through `events.run` and `outcomes.run`).
- **Runs** (after the freeze and the owner's go only): `nice -n 15`, one process; the guard stops the run when the
  available memory falls below 4,000 MB or the free disk below 20 GB. Outputs go to
  `tradingV3-private/research/20261015T1200Z-h2-gate1/run/`.

## 13. Limits

- Development data: no independent validation (§2).
- The X-Perp secondary list is today's list (survivorship), matched without aliases (renamed assets left out, §3).
- Hourly β estimates are biased low against daily ones (Epps effect): the residual keeps a small market exposure.
- C2 with first-crossing candidates still pushes against a PASS under the simulated null: about −50 to −95 bp on
  the module code with the month-only pool (§9.2), about −25 bp over 700 module panels with the three-month pool of
  Amendment A1 (−78 to +10 bp per scenario); its market-dynamics part has an unknown sign on real data. The gate has low power below
  about 150 bp (§9.1).
- The first-crossing C2 pool is 8 to 13 times smaller than the all-hours pool. With the month-only pool of v1 the
  match rate depended strongly on the real scale of z_m (P(match rate < 80 %) 0.26–0.56 per synthetic scenario with
  z_m 10 % smaller than nominal, §9.1). Amendment A1 widens the pool to three calendar months: in its acceptance run
  no panel fell below 80 % (minimum 0.909 over 1,400 simulator panels and 0.928 over 700 module panels, VR 0.8
  included). Real data (bar gaps, listings, coins absent in some months) may still give a lower rate.
- With Amendment A1 about 69 % of the matched controls come from a month adjacent to the event's month: the control
  is a shared shock of the same sign and size up to two calendar months away, not of the same month.
- C1's ±5 d exclusion conditions on the absence of the coin's later shocks, and does not exclude the hours near
  suppressed triggers (§6).
- The contract value of programme bases is inferred for any minutes without quote and base volume; the swing v2
  volumes use the 2026-10-14 live value instead (§3).
- Delistings are handled by truncation; their share is reported.

## 14. Decisions taken

- Taken (owner, 2026-10-15): the four draft choices unchanged (k = 3, primary horizon 3 d, leave-one-out top-30
  market, G3 = 40 bp); development label; G4 amended (S2 > 0 as a point estimate is decisive, its CI published and
  not decisive); C2 caliper set by the synthetic rule (`none`); C1 ±5 d kept; data step authorised.
- **C2 amended (owner, 2026-10-15, answer to the C2 null bias of §9.2):** C2 candidates are first crossings — per
  coin, the first hour meeting |z| < 1 and |z_m| ≥ 2, then no candidate during the same 120 h refractory period as
  the events (symmetry with the event definition; an episode is delimited by this period, §6). Everything else of C2 unchanged (same
  calendar month (widened by Amendment A1 below), same sign of R_i, nearest |R_i|, no caliper as chosen by the frozen rule, greedy chronological
  matching without replacement where possible, reuse counted, 80 % `STATISTICAL_BLOCKER` rule). G4 stays as amended.
  All other criteria unchanged. The owner authorised the freeze after the re-simulation and check, done on synthetic
  panels only (§9.1, §9.2: `sim/c2-first-crossing/`); **the single Gate 1 run is not authorised**.
- Freeze review corrections (2026-10-15, no decision changed): exact hashes of the cited files; the match rate's
  sensitivity to the scale of z_m stated (§9.1, §13); the episode wording made exact (§6); pool sizes, like-for-like
  null comparisons and the undecomposed bias mechanism (§9.2); the caliper argument on the rule's own runs (§9.1,
  §10.2); two test assertions (§12). The `research_h2` code is unchanged, so the code digest frozen below is the one
  that ran the module null check of §9.2 (its `events.py` docstring keeps the short "first hour of each episode",
  which §6 now defines).
- **Amendment A1 (owner, 2026-10-15, before any read):** the C2 matching pool becomes the calendar months M−1, M and
  M+1 around the event's month M, clipped to the decision period; everything else unchanged. Accepted by its
  pre-declared synthetic rule (see the section "Amendment A1").
- Remaining step: the owner's separate go for the single Gate 1 run (this amended text is re-frozen, §15).

## Amendment A1 (owner, 2026-10-15, before any read)

**Decision.** To reduce the risk of a `STATISTICAL_BLOCKER` (the month-only pool fell below the 80 % match rate in
26–56 % of synthetic panels when z_m is 10 % smaller than nominal, §9.1), the owner amended C2 on 2026-10-15,
**after the v1 freeze and before any read of H2 data** (no price, return, residual, z-score, event count or event
list of H2 had been read; no claim, event file or outcome exists):

- The C2 matching pool of an event whose decision hour falls in calendar month M is the first-crossing candidates
  of the calendar months **M−1, M and M+1** (instead of M only).
- The window is **clipped to the decision period** 2023-01 … 2026-09 (declared): for an event of 2023-01 the pool is
  2023-01 and 2023-02; for an event of 2026-09, 2026-08 and 2026-09.
- **Unchanged:** first-crossing candidates (|z| < 1, |z_m| ≥ 2, 120 h per-coin refractory period), same sign of
  R_i, nearest |R_i|, caliper none, greedy chronological matching without replacement where possible (a candidate
  used by one event is used for every later event whose window holds it), reuse counted, the 80 %
  `STATISTICAL_BLOCKER` rule, G4 = S2 > 0 as a point estimate (decisive, CI published only), every other gate and
  criterion, and the owner's four choices (k = 3, horizon 3 d, leave-one-out top-30 market, G3 = 40 bp).

**Code.** `protocol.C2_POOL = "three_months"` (`C2_POOLS = {"month": 0, "three_months": 1}`, the half-width in
calendar months); `events.pool_months` builds the window by calendar month (a month absent from the decision months
is left out); `events.match_c2(..., half_width)` joins the (month, sign) pools of the window in (hour, instrument)
order; the event file records `pool`, `pool_half_width_months` and `matched_outside_event_month`.
`protocol.unresolved_decisions()` refuses any other pool, and each claim records `c2_pool` with the G4 rule, the
caliper and the candidate rule. `protocol.C2_POOL_RULE_SHA256` pins the acceptance rule. Commit `a4844111`
(code digest `e8c087a1a1ee105c81d2f9f06d79b966f1db52a43ffd293860e1a8f6c8c41904`; the digest of the committed code
is the one to write on the re-freeze line). Tests (synthetic only, 41 green): the window and its clipping at the
first and last month and at a calendar gap, same sign, matching without replacement across months with a counted
reuse, half-width 0 reproducing the month-only pool, determinism, controls within ±1 calendar month on a synthetic
three-month `compute`, the decision checked by `unresolved_decisions()` and written in the claim.

**Acceptance rule, written first.** `tradingV3-private/research/20261015T1200Z-h2-gate1/sim/c2-pool-3m/ACCEPTANCE-RULE.md`,
sha256 **`192beca2577808eb1bcb98d1c4b9363c83ea35f2a46671700df11e7668bdc893`**, hashed at 2026-10-15T14:03:23Z before
any code or simulation of the amended pool. 14 synthetic scenarios (normal, t4 and SV + t4 innovations with both
coin R² ranges at VR 1.0 and at VR 0.8, where z_m × √VR for the trigger and the C2 qualifying hours; Student t3 with
both R² ranges at VR 1.0). ACCEPT if and only if (a) P(PASS | μ = 0) ≤ 0.01 in every scenario × sd(y) ∈ {500, 800,
1,200} bp cell (≤ 1 PASS in 100 panels), (b) at most 5 of 100 gate-simulator panels and at most 2 of 50 module
panels below an 80 % match rate in every scenario, and (c) the null bias of S2 reported (no threshold). Seeds and
script sha256 recorded before the runs (`PRE-RUN.sha256`, 2026-10-15T14:11:44Z); no bug was found, nothing was
re-run, the rule was not revised.

**Runs** (`sim/c2-pool-3m/`, `SHA256SUMS` sha256 `5d4b78041618e6b111b5496ab0d7a4f670c48095252a474cc8daee2aa8bfb275`;
`power_3m.py` `03e449e6…c355b`, `null_module_3m.py` `82aa538f…0dfa`, `summarize_3m.py` `fb09fd51…6299`,
`gate-sim-results.json` `54bb39b8…e756`, `null-module-results.json` `4b4027bd…613b`, `summary.md` `8a7e676f…0096`,
`decision.json` `8b4ca75e…a2c4`). Gate simulator: `sim_h2.power_run` unchanged with the first-crossing pool and
the three-month window, 100 panels per scenario, each panel also run with the month-only pool on the same seed
(paired). Module: the real `research_h2.events.compute` on random-walk closes over the protocol's hourly grid, 50
panels per scenario, the month-only pool matched on the same table. The month-only results reproduce the earlier
runs exactly on the shared seeds (600 simulator panels of §9.1, 800 stress match rates, 300 module panels of §9.2:
0 differences).

**Result: ACCEPT.**

| | Three-month pool (A1) | Month-only pool (v1), same panels |
|---|---|---|
| (a) PASS at μ = 0, max per scenario × sd cell | 1 / 100 (scenario t4 R² 0.20–0.50 VR 0.8, sd 1,200); 0 in the 41 other cells | 0 / 100 |
| (b) simulator panels < 80 %, max per scenario | 0 / 100 (min 0.909, p1 0.962) | 56 / 100 (233 of 1,400; min 0.684) |
| (b) module panels < 80 %, max per scenario | 0 / 50 (min 0.928, p1 0.969) | 15 / 50 (54 of 700; min 0.702) |
| Match rate, VR 1.0, scenarios 1–6 (simulator min / module min) | 0.968 / 0.980 | 0.803 / 0.847 |
| Match rate, VR 1.0, Student t3 scenarios 13–14 (simulator min / module min) | 0.963 / 0.977 | 0.793 / 0.772 (1 panel < 80 % in each run) |
| Match rate, VR 0.8 (simulator min / module min) | 0.909 / 0.928 | 0.684 / 0.702 |
| (c) S2 at μ = 0, module, 700 panels | −25 ± 110 bp, P(S2 > 0) 0.41 | −62 ± 109 bp, 0.28 |
| (c) S2 at μ = 0, simulator, sd 800, 1,400 panels | −26 ± 105 bp, P(S2 > 0) 0.41 | −50 ± 104 bp, 0.32 |
| Matched controls outside the event's month (module) | 69 % | 0 % |

(c) per scenario, module (mean ± sd, P(S2 > 0)): VR 1.0 normal −22 ± 79 (0.40) and +3 ± 119 (0.50), t4 −26 ± 85
(0.32) and −22 ± 102 (0.46), SV + t4 −69 ± 122 (0.28) and −37 ± 134 (0.44); VR 0.8 normal −28 ± 95 (0.38) and
+2 ± 114 (0.54), t4 −35 ± 87 (0.28) and −12 ± 103 (0.44), SV + t4 −78 ± 138 (0.22) and −44 ± 140 (0.38); t3 +10 ± 94
(0.60) and +3 ± 81 (0.50) bp (R² 0.35–0.75 first). Simulator at sd 800: −2 to −65 bp per scenario, P(S2 > 0)
0.29–0.51. The null bias stays negative (against a PASS) on average and is about 40–50 % of the month-only one.
The module VR 0.8 panels reuse the seeds of the VR 1.0 panels (`PRE-RUN.sha256`): they run on the same
random-walk closes with z_m rescaled, so the 700 pooled module panels come from 400 distinct generated series.
Over those 400 distinct series (scenarios 1–6 and 13–14) the module S2 at μ = 0 is −20 ± 106 bp, P(S2 > 0) 0.44
(month-only −56 ± 105 bp, 0.29). The per-scenario checks of (b) are unaffected. G4 is therefore less
conservative than under v1 (null bias about halved, mean positive in 4 of 14 module scenarios); a null in which
the shared shocks themselves revert was not simulated. The single μ = 0 PASS (seed 70264074, scenario 10, sd
1,200) was a `STATISTICAL_BLOCKER` under the month-only pool (match 0.773).

P(PASS) of the gate with the three-month pool, pooled mean [min–max over scenarios]:

| True effect μ | VR 1.0, sd 500 / 800 / 1,200 bp | VR 0.8, sd 500 / 800 / 1,200 bp | t3, sd 500 / 800 / 1,200 bp |
|---:|---|---|---|
| 0 bp | 0.00 / 0.00 / 0.00 | 0.00 / 0.00 / 0.00 [max 0.01] | 0.00 / 0.00 / 0.00 |
| 40 bp | 0.05 / 0.02 / 0.01 | 0.04 / 0.02 / 0.01 | 0.06 / 0.01 / 0.00 |
| 80 bp | 0.39 / 0.18 / 0.07 | 0.35 / 0.15 / 0.04 | 0.45 / 0.16 / 0.06 |
| 150 bp | 0.84 [0.70–0.93] / 0.74 [0.66–0.83] / 0.44 [0.32–0.51] | 0.82 / 0.74 [0.71–0.77] / 0.41 | 0.94 / 0.86 / 0.51 |
| 300 bp | 1.00 / 1.00 / 0.98 | 1.00 / 0.99 / 0.98 | 1.00 / 1.00 / 0.99 |

Month-only on the same panels: at VR 1.0, 0.78 / 0.70 / 0.42 at 150 bp and 0.99 / 0.99 / 0.97 at 300 bp; at VR 0.8,
0.47 / 0.43 / 0.24 at 150 bp and 0.61 / 0.60 / 0.59 at 300 bp (P(`STATISTICAL_BLOCKER`) 0.39 pooled, 0.26–0.56 per
scenario, against 0.00 with the three-month pool). P(FAIL due to G4 alone) with the three-month pool: at most 0.11
pooled (0.21 in one scenario) at 150 bp and 0.00–0.01 at 300 bp (month-only: 0.14 pooled, 0.35 in one scenario, at
150 bp).

**What it changes and what it does not.** The amendment removes most of the match-rate risk in the synthetic designs
and halves the simulated null bias of S2; it keeps C2 conservative on average. It does not change the events, C1,
the outcomes or any gate. The control is now drawn from up to three calendar months, so a regime change between
adjacent months enters the comparison; this is declared as a limit (§13). The month-block bootstrap still attaches
each control to its event's month (§7). §9.1, §9.2 and §10 describe the month-only pool of v1 and are kept as the
record of the v1 design.

## 15. Freeze

The body above this line is hashed (sha256 over the file content up to and including the line "## 15. Freeze" and
the following blank line). At the freeze, the hash, the time, the commit and the code digest
(`python -m scripts.research_h2.cli digest`) are written on a `FROZEN` line below and in
`PREREGISTRATION-H2-GATE1.sha256`.

Status: **FROZEN (re-frozen with Amendment A1), not executed** — Amendment A1 (owner, 2026-10-15, before any read)
is written in (§6, §13, §14 and the section "Amendment A1") and passed its pre-declared synthetic acceptance rule
(ACCEPT, rule sha256 `192beca2577808eb1bcb98d1c4b9363c83ea35f2a46671700df11e7668bdc893`); no claim, event file or
outcome exists; the single Gate 1 run is not authorised.

FROZEN 2026-10-15T15:57:04Z body sha256 0ec71c563a6247464765fd0696ceb193d60d980e97d93d6f53d0565940d8bf20 code sha256 e8c087a1a1ee105c81d2f9f06d79b966f1db52a43ffd293860e1a8f6c8c41904 runtime python 3.12.3 numpy 2.5.4 (tradingV3-private/research/20261014T090000Z-swing-v2-runtime/bin/python) (Amendment A1; protocol body at commit ada68770b776e9ee4a2574fd238fc67cd38abb04, code at commit a4844111b394398238f2f86a9dcc6884b0820449, unchanged since; not executed: no claim, no event file, no outcome; the single Gate 1 run needs the owner's separate go)

History (v1, superseded by Amendment A1; written so that no claim reads it):

- v1: `FROZEN 2026-10-15T13:56:41Z body sha256 c4f24f98d256b6ad5bb9043e9b1e66f8500963c120b2587d002f169b9e54aa9c code sha256 d52c03848928cd1717385b641d1cb0dc667efb5f4376a20938d6e006f29839b1 runtime python 3.12.3 numpy 2.5.4 (tradingV3-private/research/20261014T090000Z-swing-v2-runtime/bin/python) (protocol body and code at commit f7c04507ecb3b215af3055098b22012ffaac5e42; not executed: no claim, no event file, no outcome; the single Gate 1 run needs the owner's separate go)`
