# H1 historical Gate 2 — result (2026-10-15)

**Verdict: `GATE2_FAIL`. Under v1 §17, H1 is closed.**

Historical, not executable on X-Perps. One read, under v1 + A1 + A2 + A3, with nothing changed after the read.

The Gate 1 effect is real, a −16 % relative drift at 14 days on S_B. It is **not capturable** by the frozen short:

- under the 500-USDT portfolio, the 3σ stop and the costs, the edge is thin;
- it rests on a few trades;
- it fails in time.

## Record

| Item | Value |
|---|---|
| Protocol | v1 `10d4f0c3…`, A1 `5c04b1c6…`, A2 `b77d6fed…`, **A3 `41a8b614…`** (frozen 2026-10-15T09:03:56Z, diagnostics only) |
| Code and implementation note | commit `4215cd91` (`gate2.py`, `H1-GATE2-IMPLEMENTATION-NOTE.md`); 30 tests pass |
| One-read claim | `gate2/claim.json`, 2026-10-15T09:04:19Z, sha256 `50602f21…`, written before the funding download |
| Funding (after the claim, rates only) | Binance: 1,208 monthly files, 1.21 MB, checksums verified. OKX: 55 files, 72 KB, sha256 recorded. The files not found are months before a listing. |
| Funding coverage | 321 / 321 events, 6,420 / 6,420 control legs; the stop rule was not triggered |
| Output | `gate2/gate2.json`, sha256 `1a00a39d…` |

All paths are under `tradingV3-private/research/20261014T210000Z-h1-listing/`.

**One disclosed tooling defect.** The first Binance funding download stopped before writing any file, because its
output folder did not exist. The folder was created and the download run once. No result was read in between.

## Criteria (§15), S_A ∪ S_B

| Criterion | Baseline | Pessimistic |
|---|---|---|
| 1. ≥ 60 executed trades | ✔ 70 | ✔ 64 |
| 2. Mean net R > 0 | ✔ +0.027 R | ✔ +0.023 R |
| 2. … and under the stress (stop slippage × 2) | ✔ +0.024 R | **✘ −0.007 R** |
| 3. Mean absolute net short return > 0 | ✔ +2.6 % | ✔ +4.1 % |
| 4. Surplus over the control strategy > 0, p ≤ 0.10 | ✔ +0.121 R, p 0.005 (control −0.094 R) | ✔ +0.139 R, p 0.000 |
| 5. Profit factor ≥ 1.2 / ≥ 1.0 | **✘ 1.10** | ✔ 1.09 |
| 6. Portfolio PnL > 0 | ✔ +4.67 USDT | ✔ +3.64 USDT |
| 7. Max drawdown ≤ 75 USDT | ✔ 23.7 | ✔ 22.2 |
| 8. Worst trade ≥ −3 R | ✔ −1.05 R | ✔ −0.98 R |
| 9. PnL positive in ≥ 2/3 of half-years (≥ 15 trades) | **✘** | **✘** |
| 10. Mean R > 0 without the top 5 % | **✘ −0.023 R** | **✘ −0.028 R** |

**Criterion 9.** No half-year reaches 15 trades, so the criterion fails by construction under the convention declared
before the read. It fails under any other reading too:

| Half-year PnL (baseline) | 2023 H2 | 2024 H1 | 2024 H2 | 2025 H1 | 2025 H2 | 2026 H1 | 2026 H2 |
|---|---:|---:|---:|---:|---:|---:|---:|
| Trades | 12 | 10 | 11 | 14 | 9 | 11 | 3 |
| PnL (USDT) | −16.9 | −3.0 | −2.3 | +17.8 | +1.3 | +6.6 | +1.2 |

- With all half-years counted, 4 of 7 are positive, short of 2/3.
- With half-years of ≥ 10 trades, 2 of 5 are positive.

**The verdict does not depend on criterion 9.** Baseline criteria 5 and 10, and the pessimistic stress, fail on their
own.

## What happened to the 321 events (baseline)

| | Count |
|---|---:|
| Events | 321 |
| Skipped: below Binance's 5-USDT minimum notional | 205 |
| Skipped: below the minimum contract size | 22 |
| Skipped: risk budget full (two positions open) | 24 |
| **Executed** | **70** (52 S_A, 18 S_B) |

- **Stop distance.** Median 60 %, the cap; 53 % of trades are at the cap.
- **Notional.** Median 3.9 USDT.
- **Outcomes.** 27 % of trades are stopped out. The mean gross short return is +3.6 %, and funding costs −0.9 % on
  average.

**Most of S_B cannot be traded at all.** At a 60 % stop the allowed notional is about 4 USDT, below Binance's 5-USDT
minimum. The S_B events that are executed are the low-volatility ones (tighter stops). A2 §4 anticipated this
constraint. It is part of the frozen rule, not a defect.

## A3 diagnostics (never decisive)

- **D1, subsets** (each its own portfolio):

  | Subset | Profile | Trades | Mean R | Profit factor | PnL (USDT) | Without top 5 % |
  |---|---|---:|---:|---:|---:|---:|
  | **S_A, OKX-first** (the X-Perp-relevant population) | baseline | 52 | +0.092 | 1.43 | +11.9 | +0.055 R |
  | | pessimistic | 51 | +0.103 | 1.54 | | |
  | S_B, Binance-first | baseline | 28 | −0.138 | 0.61 | −9.7 | |

  - S_A's half-years are negative in 2023–24 and positive from 2025, the same instability as its Gate 1 screen.
  - S_A was the screen set, already read. Its 52 trades are under the 60-trade minimum.
  - **It cannot rescue H1, and no new protocol should be built on it from this read.**
- **D2, X-Perp funding structure** (4 h, ±1 %, escalation): mean R +0.028 baseline, profit factor 1.11. The recorded
  funding of these events rarely reached the X-Perp cap. The X-Perp structure alone would not change the verdict.
- **D3, break-even funding.** An extra **0.041 % per 8 h** paid by the short removes the whole baseline edge. That is
  below the protocol's pessimistic provision of 0.1 % per 8 h.
- **D4, unfavourable delisting exit:** identical. No executed trade exited by delisting.
- **D5, execution:** 10 / 50 / 90 % stop distances are 29 / 60 / 60 %; notionals are 3.0 / 3.9 / 7.4 USDT.

## Reading

- **H1's drift is a statistical property of new perpetuals,** not a trade that survives a mandatory 3σ stop, 1×
  sizing at 0.5 % risk, costs and funding.
- **The squeeze tail and the stop.** The tail that Gate 1 measured (P(MAE > 50 %) = 24 %) forces wide stops, hence
  4-USDT positions. A quarter of the trades are stopped out, which costs most of the drift.
- **What remains.** About +0.03 R per trade overall. It disappears without the five best trades, under the
  pessimistic stress, or with 0.04 % per 8 h more funding.
- **The control strategy does worse (−0.09 R).** The short rule therefore extracts something relative to
  established perpetuals, but not enough to pass the economic criteria.

## Consequences

1. **H1 is closed** (v1 §17: "Gate 2 → Closed").
   - No trading engine is developed for H1.
   - No prospective S_C is started.
   - The X-Perp execution path (`H1-XPERP-TECHNICAL-GAP.md`) is not built for H1.
2. **The scientific record stays.** S_A screen pass, S_B Gate 1 pass, Gate 2 fail. The frozen documents are
   unchanged.
3. **A new hypothesis must not be tuned on this read.** That includes a tighter stop, a different horizon, or S_A
   only. Any new idea needs its own pre-registration and data not read here.
