# H1 — S_A screen result (OKX, amendment A2, 2026-10-14)

**Verdict of the pre-registered screen: `S_A_SCREEN_PASS`** (all eight sign checks). It is a screen, not a Gate 1
decision. The decision rests on S_B (Binance), which is **not downloaded** and needs the owner's authorisation after
this quality review.

## Provenance

| Item | Value |
|---|---|
| Protocol v1 | sha256 `10d4f0c3…` |
| Amendment A1 | sha256 `5c04b1c6…` |
| Amendment A2 | frozen 2026-10-14T21:26:54Z, sha256 `b77d6fed…` |
| Code | commit `f7744d0b` |
| Claim | `gate1-sa-a2/claim.json`; the v1 / A1 claim and its `NOT_GENERALIZABLE` prepare are kept in `gate1-sa/` |
| Outputs | `/home/haythem/workspace/perso/tradingV3-private/research/20261014T210000Z-h1-listing/gate1-sa-a2/{prepare,outcomes}.json` (sha256 `f8c3f95118305d34…`) |

The returns were read once.

## Sample

- 83 primary events; no data-quality exclusion; 31 calendar months.
- Every event has 20 A2 controls.
- The **new listing is a median 4.3 times more volatile than its controls**; this gap is declared.

## Results (14 days after T = t0 + 24 h; log returns)

| Statistic | Primary (83) | + 13 delisted, archive-dated (96) |
|---|---:|---:|
| Mean paired surplus S14 (vs C1-A2) | **−11.4 %** | −9.8 % |
| Median S14 | −19.7 % | −10.4 % |
| 90 % CI of mean S14, 1-month blocks | [−21.0 %, −2.2 %] | [−18.7 %, −1.1 %] |
| 90 % CI, 2-month blocks | [−20.8 %, −1.7 %] | [−19.2 %, −0.3 %] |
| P(S14 < 0) | 57.8 % | 55.2 % |
| Mean risk-normalised surplus Z14 | −0.17 | −0.10 |
| Median Z14 | −0.30 | −0.25 |
| 90 % CI of Z14, 1-month blocks | [−0.33, −0.011] | [−0.28, +0.10] |
| 90 % CI of Z14, 2-month blocks | **[−0.35, +0.015]** | [−0.30, +0.12] |
| v1 volatility-matched surplus (secondary, `NOT_GENERALIZABLE`; 50 / 60 events) | mean −2.0 %, median −7.6 % | mean −0.5 %, median −3.4 % |
| Index-relative AR14 (A1 index) | mean −11.4 %, median −18.2 % | mean −9.7 % |
| **Absolute 14-day log return of the coin** (what an unhedged short sees) | mean −16.6 %, median −16.7 %, P(< 0) 63.9 % | mean −13.7 %, P(< 0) 61.5 % |

### Secondary horizons (mean / median S)

| Horizon | Mean | Median |
|---|---:|---:|
| 1 d | −4.5 % | −5.2 % |
| 3 d | −7.8 % | −12.8 % |
| 7 d | −11.5 % | −20.8 % |
| 14 d | −11.4 % | −19.7 % |
| 30 d | −11.0 % | −17.4 % |

## Quality review: what would fail the decisive criteria if applied to S_A

1. **Time robustness fails.** S14 is positive in 2023 (+19.7 %, 7 events) and 2024 (+9.4 %, 23 events) and
   strongly negative in 2025 (−24.6 %, 33) and 2026 (−24.5 %, 20). By half-year it is negative in 4 of 7,
   against the ≥ 2/3 required at S_B. The effect looks like a 2025–26 phenomenon, perhaps the era of
   low-float / high-FDV launches, rather than a constant.
2. **Concentration fails on one criterion.**
   - One event carries 14 % of the summed surplus; the S_B limit is 5 %.
   - No month exceeds 20.4 %; the limit is 25 %.
   - Without the 5 % most negative events, the mean S14 is still negative (−7.2 %).
3. **The risk-normalised test is borderline.** Its 2-month-block CI crosses zero (+0.015). With the delisted events
   added it crosses zero with both block lengths.
4. **The volatility-matched comparison (v1) is much weaker** (median −7.6 %, mean −2.0 %). Part of the A2 surplus may
   be the excess risk of new listings against calmer controls. That is exactly why Z is required.
5. **The tail is hostile to a short.**
   - Maximum adverse excursion over 14 days: median +20 % (log), 95th percentile +112 %.
   - **P(MAE > +25 %) = 44.6 %, P(MAE > +50 %) = 27.7 %.**
   - A stop between +15 % and +60 % would therefore be hit often, before the drift plays out. Gate 2 will decide.

## Reading

The sign of the effect is clear on S_A: new OKX listings fall in absolute terms and relative to established
perpetuals over 14 days. Its stability is not. It is absent in 2023–24, it is borderline once risk-normalised, and
it is fragile when one event or the archive-dated delisted events are included. The decisive test on S_B can
confirm or refute it: Binance, 240 events, 2023-09 → 2026-08, read once, with the full v1 + A2 criteria.

The squeeze tail already says that even a confirmed drift may not survive a stop. Gate 2 is not implied.

No Binance data has been downloaded. The next step needs the owner's authorisation.
