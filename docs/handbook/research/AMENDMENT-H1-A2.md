# Amendment A2 to PREREGISTRATION-H1 — primary control and risk-normalised test (2026-10-14)

**Owner's decision:** `APPROVE_A2_CONDITIONAL_OKX_SCREEN`, option 2, 2026-10-14. Written and frozen **before any
post-listing return was computed**. Versions v1 (sha256 `10d4f0c3…`) and A1 (sha256 `5c04b1c6…`) are kept
unchanged.

## 1. Why

The pre-outcome step of the S_A screen found a structural problem
(`tradingV3-private/research/20261014T210000Z-h1-listing/gate1-sa/prepare.json`, prices up to T only). Under the
v1 volatility-matched control, **33 of the 83 S_A events (39.8 %) had fewer than 3 controls** within a volatility
factor of 3. That exceeds the 30 % rule, so the v1 C1 result on S_A is **`NOT_GENERALIZABLE`**. This failure
stays in the record: v1's prepare file is kept, and the v1 result is reported in every later document.

The median daily volatility of new listings is 24.6 %, far above established perpetuals. **Dropping the
volatility filter does not make the controls comparable.** It changes the method and, in part, the question:

- the raw surplus compares a new listing with established perpetuals of lower risk;
- the risk-normalised surplus asks whether the under-performance is larger than the extra risk explains.

A2 therefore requires both.

## 2. Changes

1. **Primary control (C1-A2).** At T, the established perpetuals of the same venue:
   - listed ≥ 180 days;
   - ranked by median reliable daily quote volume of the previous month, top 50;
   - **the first 20 in that ranking with a price and a volatility known at T**.

   There is no volatility tolerance. Nothing after T and no return is used to select them
   (`conventions.select_established_controls`). At least 3 controls are required; if more than 30 % of a set's
   events lack them, the set is `NOT_GENERALIZABLE` (as in v1).
2. **Raw paired surplus (primary, economic threshold unchanged):** S_h = AR_h(event) − mean AR_h(C1-A2 controls).
3. **Risk-normalised paired surplus (mandatory):**
   - Z_h = AR_h(event) / (σ_e √h) − mean over controls of AR_h(control) / (σ_c √h);
   - σ_e and σ_c are the daily volatilities **known at T** (the A1 hourly estimate over the 23 hours before T);
   - `conventions.normalized_return`; never a volatility observed after T.
4. **The v1 volatility-matched control** is kept as a secondary diagnostic, labelled `NOT_GENERALIZABLE` on S_A,
   and computed on the 50 events that had controls.

## 3. Criteria (replace v1 §9)

### 3.1 S_A screen (OKX; run once with a new claim; does not reuse the v1 / A1 claim)

All required, on the primary events and again with the 13 delisted archive-dated events added (main delisting
rule):

- mean S14 < 0 and median S14 < 0;
- mean Z14 < 0 and median Z14 < 0;
- C1-A2 coverage: at least 70 % of events have 3 or more controls.

If any fails, H1 is closed. If all pass, the result is reported and **the owner reviews its quality before
authorising any Binance download** (S_B).

### 3.2 S_B decisive Gate 1 (unchanged otherwise)

All of v1 §9.2, with S computed against C1-A2, **plus**:

- mean Z14 < 0;
- the 90 % CI upper bound of Z14 < 0, with 1-month and 2-month blocks.

No economic threshold applies to Z: it is a risk check, not a size criterion. The −5 % threshold stays on the raw
surplus S14. S_B is never used to adjust any parameter.

## 4. What does not change

- Events, horizons and the −5 % threshold.
- Gate 2, the prospective test and the costs.
- The 500-USDT portfolio (0.5 % / 1 %), with real trading forbidden.

A Gate 1 pass does not validate a setup. Gate 2 must still show that the drift can be captured with legal
position sizes, fees, funding and squeeze risk. With a +60 % stop, a position is limited to about 4 USDT of
notional, and some contracts will not be executable.

## Freeze

The body above this line is hashed (sha256 over the file content up to and including the line "## Freeze" and the
following blank line).

FROZEN 2026-10-14T21:26:54Z body sha256 b77d6fed36838e8e25b4e82621990a1b547b6f46befbfc5d0c38e273f58e8d3c
