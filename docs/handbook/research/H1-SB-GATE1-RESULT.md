# H1 S_B (Binance) decisive Gate 1 — result (2026-10-15)

**Verdict: `GATE1_PASS`.** All 25 criteria of v1 §9.2 and A2 §3.2 hold, on the main series and under the
delisting sensitivity.

A pass only authorises further research. It authorises no trading engine, no order and no live use: Binance is a
statistical data source only (Binance Vision licence, research use), never an execution venue for a French account.

## Record

| Item | Value |
|---|---|
| Protocol | v1 `10d4f0c3…` + A1 `5c04b1c6…` + A2 `b77d6fed…`, unchanged |
| Implementation note | `H1-SB-IMPLEMENTATION-NOTE.md`, commit `ff7b77b9` |
| One-read claim | `gate1-sb/claim.json`, 2026-10-14T22:09:47Z, sha256 `e18ad524…`, written before any download |
| Fix after the claim, before any price read | `971a6f5f`: `volumes()` takes the listing date of a symbol missing from exchangeInfo from the archive's first day, the same rule as `plan()` |
| Outputs | `prepare.json` `f8dafb09…`, `outcomes.json` `73814e9e…`, `verdict.json` `3a2181c4…` |
| Data | 501 1-minute, 10,246 daily and 2,164 hourly monthly archives, each sha256-checked; 512 MB kept, zips deleted |

All files are in `tradingV3-private/research/20261014T210000Z-h1-listing/gate1-sb/`.

The 404 answers are archive months outside a contract's life: 7 one-minute months before a listing and 697
daily months. They are recorded in the manifests.

## Sample

- **Events.** 240 primary-eligible Binance events, 2023-09 to 2026-07.
  - 2 excluded for data: GAIB never traded (PENDING_TRADING), and ARK has no σ at T.
  - **238 usable**; none was excluded for missing minutes.
- **Independence from S_A.** No token is shared with the 83 S_A events: the audit gives each token to a single
  venue. S_B is an independent event set.
- **Controls.**
  - C1-A2 coverage is 100 %: every event has 20 controls, and the index has 30 members.
  - Under the v1 volatility-matched control, 31.9 % of events lack comparable controls. That control stays a
    diagnostic (A2).
  - A new listing is a median 4.6 times more volatile than its controls, and its median daily σ is 20.0 %.
- **Delisting sensitivity.** No event was delisted within 14 days, so the sensitivity series equals the main series.

## Main numbers (14 days, primary horizon)

| Measure | Value |
|---|---|
| Mean paired surplus S14 vs C1-A2 | **−16.1 %** (median −18.1 %) |
| 90 % CI, 1 / 2 / 3-month blocks | [−20.9, −11.6] / [−20.3, −12.0] / [−21.0, −11.6] % |
| P(S14 < 0) | 71.4 % |
| Risk-normalised Z14 | mean −0.26; CI 1-month [−0.31, −0.21], 2-month [−0.30, −0.22] |
| AR14 vs the top-30 index | mean −15.7 %, median −18.4 % |
| Absolute log return r14 | mean −16.2 %, median −17.1 % |
| v1 volatility-matched surplus (diagnostic, n = 162) | mean −12.7 % |

Horizons: mean S1 −3.1 %, S3 −6.2 %, S7 −11.9 %, S14 −16.1 %, S30 −22.9 %. The drift builds steadily over the
month.

### Stability and concentration

| Half-year | Events | Mean S14 |
|---|---|---|
| 2023 H2 | 22 | −17.9 % |
| 2024 H1 | 16 | −4.8 % |
| 2024 H2 | 55 | −12.8 % |
| 2025 H1 | 54 | −25.5 % |
| 2025 H2 | 68 | −13.1 % |
| 2026 H1 | 22 | −18.2 % |
| 2026 H2 | 1 | +11.8 % (below 15 events, not counted) |

- S14 is negative in every half-year with at least 15 events (6 of 6).
- The largest month carries 14.3 % of the summed S14, the largest event 3.3 %.
- Without the 5 % most negative events, the mean is −12.1 %.

### Short-side risk (not part of Gate 1)

- Adverse move against a short within 14 days: median +18.3 %, mean +27.2 %.
- P(MAE > 25 %) = 41 %; P(MAE > 50 %) = 24 %.
- No costs and no funding are applied at Gate 1.

## Reading

S_B confirms on an independent event set, with a stronger and steadier effect, what S_A only screened. New
Binance perpetuals under-perform established perpetuals by about 16 % over 14 days, also after normalising for
their much higher risk, in every half-year.

The S_A weaknesses are not repeated here: there is no time instability (S_A was positive in 2023–24) and no
concentration. The squeeze tail stays heavy. Any trading rule must survive it: about one short in four would
see the price rise by more than 50 % at some point within 14 days.

## Status of H1 after this result (follow-up note; frozen documents unchanged)

- **Science:** Gate 1 passed on S_B. The effect exists on historical USD-M perpetuals.
- **Market (M1):** the owner validated OKX Europe for himself ("okx ok", 2026-10-15). H1 therefore has an
  **identified execution market, OKX Europe X-Perps, subject to the API check** of the account: X-Perps enabled
  and API trading permitted.
- **Data (M3):** neither S_A (OKX Global USDT swaps) nor S_B (Binance) is the tradable product. X-Perps have no
  history before 2026-03-30. **The only valid continuation is prospective on X-Perps** (`H1-EXECUTABILITY-REVIEW.md`
  §4), with X-Perp funding and squeezes measured on the product itself.
- **Next step, only on the owner's go:**
  1. a pre-registered Gate 2 (rule, stop, costs, the 500-USDT portfolio) developed on historical data, labelled
     non-executable;
  2. then the prospective X-Perp test of §16, redefined to X-Perp listings of new tokens.

  Nothing is launched.
