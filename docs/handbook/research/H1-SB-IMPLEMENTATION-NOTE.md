# H1 S_B (Binance) decisive Gate 1 — implementation note (2026-10-15)

Written and committed **before any Binance price was downloaded**. It changes nothing in the frozen protocol
(`PREREGISTRATION-H1.md` v1 sha256 `10d4f0c3…`, `AMENDMENT-H1-A1.md` `5c04b1c6…`, `AMENDMENT-H1-A2.md`
`b77d6fed…`). It only records how the protocol is executed on Binance data.

**Owner's go (relayed, 2026-10-15):** read S_B once, exactly under v1 + A1 + A2, no change of threshold, horizon,
control or population. **Binance is a statistical data source only** (Binance Vision, CC BY-NC-SA 4.0, research
use). It is never an execution venue for a French account (`FRANCE-EXCHANGE-ELIGIBILITY.md`). A pass authorises
further research only, never trading.

## 1. Events

- The 240 Binance rows that the frozen audit (`audit/events-classified.json`, PR #502) marks primary eligible.
- t0 = exchangeInfo `onboardDate` (`audit/binance-events.json`); T = ceil_hour(t0 + 24 h), as in v1.
- From 2023-09-16 to 2026-07-03. No event needs October 2026 data, so the daily archives are not used.

## 2. Data downloaded (only what the protocol needs)

All from `data.binance.vision`, each zip checked against its published sha256, converted, then deleted; one
manifest per kind (`scripts/research_exec/acquire.py --pairs-file`, disk guard 15 GB, 2 workers, nice 10).

| Kind | Use | Files |
|---|---|---|
| 1-minute monthly klines of each event symbol, months t0 − 1 d … t0 + 33 d | event price, σ at T, outcomes, MAE | 508 |
| Daily monthly klines (`1d`) of every USDT perpetual, **only** the month before some event's T, and only for symbols listed ≥ 180 days before that T and not delisted before the month | the established ranking | ≈ 10,900 |
| Hourly monthly klines (`1h`) of the top-50 established candidates of each event, months T − 2 d … T + 31 d | C1-A2 controls, v1 control diagnostic, index | computed after the ranking |

## 3. Execution choices (disclosed)

1. **Daily quote volume** comes from the `1d` candle's quote volume. A daily candle carries no minute count, so
   every day counts as reliable (the v1 rule "≥ 720 minutes" cannot be checked). Instruments listed ≥ 180 days
   rarely have partial days; the delisting day may count as a full day.
2. **Listing date** for the 180-day rule: exchangeInfo `onboardDate`, else the archive's first day.
3. **Universe** of established instruments: USDT-margined perpetuals (`contractType = PERPETUAL`,
   `underlyingType = COIN` when exchangeInfo lists them), stablecoin bases excluded, delisted symbols included
   (archive).
4. **Index:** the top 30 of the same ranking, programme pairs included (v1 default; the index cancels in S).
5. **Control and index prices** are hourly closes (`1h` archive). They are read only at hour marks, which is all
   that the protocol uses for them. Event prices are 1-minute closes, as on S_A.
6. Everything else is `study.py` unchanged (σ from 23 hourly returns per A1, C1-A2 = first 20 of the top-50 with a
   price and σ known at T, Z per A2, the delisting sensitivity, month-block bootstrap with the S_A seed).
   `outcomes` refuses to run if its output exists: one read.

## 4. Decisive verdict (`sb.py verdict`), all required

v1 §9.2, computed on the main series **and** again under the delisting sensitivity (§9.2.6):

1. mean S14 ≤ −5 %, and the upper bound of its 90 % CI < 0 with 1-month and 2-month blocks;
2. median event AR14 < median control AR14, and P(S14 < 0) ≥ 55 %;
3. mean S14 < 0 in at least 2/3 of the half-years that have ≥ 15 events;
4. mean S14 still < 0 without the 5 % most negative events; no month > 25 % of the summed S14; no event > 5 %;
5. C1-A2 coverage: ≥ 70 % of the usable events have ≥ 3 controls.

A2 §3.2, on both series: mean Z14 < 0, and its 90 % CI upper bound < 0 with 1-month and 2-month blocks.

The verdict is reported whatever it is. **If Gate 1 fails, H1 is closed** (v1 §9.2).
