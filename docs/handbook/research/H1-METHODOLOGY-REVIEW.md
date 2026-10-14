# H1 — Methodology review (2026-10-14)

This review covers the weaknesses of the draft (`DRAFT-PREREGISTRATION-next-strategy.md`, kept as version 0) and
the corrections carried into `PREREGISTRATION-H1.md` (version 1). Numbers come from the metadata audit
(`H1-LISTING-AUDIT.md`); no return was read.

## 1. Relative under-performance is not a short profit (B1, C4)

The −5 % threshold concerns the **paired surplus**: the event's market-relative return minus that of its
matched controls. A coin can under-perform a rising market while its price rises. Example: market +15 %, coin
+10 %, surplus about −5 points, unhedged short −10 % before costs.

The protocol therefore reports, separately and always:

1. the instrument's own log return;
2. its return relative to the index;
3. the paired surplus over the matched controls;
4. the gross return of an unhedged short;
5. transaction costs;
6. the funding actually paid or received by the short (sign convention tested: a positive rate pays the short);
7. the stopped short;
8. the portfolio.

**Gate 2 is judged on 6–8, never on 1–3.**

**Statistical reading of the threshold.** "Mean surplus ≤ −5 % with the 90 % CI upper bound < 0" demonstrates a
negative effect that is statistically supported, with a point estimate beyond −5 %. It does **not** demonstrate an
effect of at least 5 % with the same confidence; that would need the upper bound ≤ −5 %. The owner's criterion is
kept as written, and the stronger reading is reported for information.

## 2. Matched control (C1)

New listings are more volatile than established perpetuals, so a 20-nearest-volatility rule can pick
non-comparable controls. Fixed before any read:

- **Candidate controls at T.** Established perpetuals of the same venue: listed ≥ 180 days, in the venue's monthly
  top-50 by previous-month quote volume.
- **Matching variable.** σ24, from the 1-minute log returns over [t0, T] for the event and [T−24 h, T] for the
  controls. Data are known at T; no control is chosen on any future value.
- **Tolerance.** |ln(σ_control / σ_event)| ≤ ln 3 (a ratio within 1/3 to 3).
- **Number of controls.** Up to 20 nearest within tolerance; at least 3.
- **Events without comparable controls.** An event with fewer than 3 is excluded from the C1 analysis and kept in
  the index-relative analysis (reported).
- **Generalisability rule.** If more than 30 % of a set's events lack comparable controls, the C1 result of that
  set is declared **`NOT_GENERALIZABLE`**, and Gate 1 cannot pass on it.
- **Diagnostics reported before any outcome is computed** (the code computes and writes them first):
  - the distribution of the volatility ratio;
  - the number of admissible controls per event;
  - the share of events excluded;
  - the overlap of the σ24 distributions;
  - the previous-month volume rank of the controls.
- **Two comparisons kept distinct.** (a) Against the general index (equal-weight top-30 established perpetuals);
  (b) against the matched controls. The threshold applies to (b); (a) is reported.

## 3. Survivorship and delistings (A2, C2)

- **Binance.** Resolved: the archive bucket lists every symbol ever archived, delisted included.
- **OKX.** Bounded (≤ ~10 possibly missing events, under 5 %; see the audit).
- **Delisted OKX contracts** (13 first observed) have no official listing date. They are excluded from the primary
  analysis, but Gate 1 must also pass when they are added (sensitivity analysis on S_A).
- **Delisting during a horizon.** Official settlement prices are not available (Binance gives the delivery date
  only). The draft's "mean of the last 60 closes" is replaced by:
  - **Gate 1 main value:** the last traded close before delisting.
  - **Sensitivity unfavourable to H1:** the highest close of the last 24 h before delisting. The gate must hold
    in both.
  - **Gate 2 short:** settlement at the worse, for the short, of the last close and the last-hour mean, plus taker
    costs. In the conservative scenario the trade is booked at no better than −1 R.
- **No event is removed for disappearing**, whether it was a winner or a loser.

## 4. Reuse of data (C3)

| Set | Content | Role |
|---|---|---|
| **S_A** | OKX, 83 primary events, candles on disk. Loaded by swing v2 OKX, but no strategy result exists inside [t0, t0 + 31 d]: swing excluded the first 42 days. | Development: Gate 1 screen and Gate 2 development |
| **S_B** | Binance, 240 primary events, never downloaded | **Decisive Gate 1**, read once |
| **S_C** | All listings over 12 months from a fixed start | The only independent economic validation (Gate 2) |

- **Why S_B decides Gate 1.** With its size it is the only historical set able to resolve a −5 % effect (see §6).
  S_A is too small to decide and is used as a pre-specified screen.
- **S_B and Gate 2.** Gate 2's development uses S_A ∪ S_B. That result is **conditional on S_B's Gate 1 read** and
  is not an independent validation. Only S_C validates.
- No window is recycled because "it was not used to optimise an indicator".

## 5. Dependence between events (C5)

- **Same window, same shocks.** Listings cluster in hot markets: up to 18 primary Binance listings in one month.
  S_A and S_B share calendar time and therefore market shocks.
- **Primary intervals.** Bootstrap over **calendar months of T** (all events of a month resampled together),
  10,000 draws.
- **Required robustness.** The CI upper bound must also be < 0 with **2-month blocks**. 3-month blocks are
  reported.
- **Month boundaries.** A horizon crossing a month boundary belongs to the month of T. The 2-month blocks absorb
  the boundary dependence. A one-event-per-7-days thinning (the earliest) is reported.
- **Effective sample.** Months with events: S_A 32, S_B 34. The effective number of independent observations is
  reported (design effect estimated from the month blocks). **240 events are not 240 independent observations.**
- **Concentration.**
  - No single event may carry more than 5 % of the summed surplus, and no month more than 25 %.
  - The result must hold without the 5 % most negative events.
  - Results are reported by venue, by year and by market phase (sign of the 30-day index return at T), without
    selection.

## 6. Power and precision (no return read)

Assumptions, declared:

- σ(AR14) between 25 % and 45 % (typical two-week dispersion of small-cap crypto relative returns; to be measured
  at Gate 1);
- design effect 1.5 (clustering by month).

The minimum detectable mean surplus at 80 % power, one-sided 5 % (the 90 % CI upper bound < 0), is
2.49 × σ × √(1.5 / n):

| Set | n (primary) | MDE, σ 25 % | MDE, σ 35 % | MDE, σ 45 % |
|---|---:|---:|---:|---:|
| S_A (OKX) | 83 | 8.3 % | 11.7 % | 15.0 % |
| S_B (Binance) | 240 | 4.9 % | 6.9 % | 8.8 % |
| S_C (12 months, both venues, ≈ 9 primary events / month) | ≈ 108 | 7.3 % | 10.3 % | 13.2 % |

- **S_A alone cannot decide a −5 % effect.** It is a screen (direction and plausibility), not the decision.
- **S_B can decide it** only if the dispersion is at the low end. With σ ≈ 35 %, a true −5 % effect passes with
  well under 80 % power. A failure of S_B at −5 % therefore means "no effect of the required size demonstrated",
  not "no effect".
- **S_C (12 months).**
  - It can confirm only an effect of about 7–13 %.
  - Executability at 500 USDT will remove part of its events (Binance stops above 44–48 %; price ceilings).
  - The expected number of executable trades is of the order of 40–80. With fewer than 30 executable trades the
    prospective verdict is `INCONCLUSIVE`, pre-declared.

## 7. Remaining limits

- **Venues covered.** Only OKX and Binance; earlier perpetuals elsewhere are unobserved.
- **Historical execution.**
  - Contract specifications are current, not historical.
  - There is no book history.
  - Delisting settlement prices are proxies.
- **Same calendar for S_A and S_B.**
- **σ(AR14) is assumed** for the power analysis, not measured.
