# Book families on Binance depth and top-of-book archives (r8, r9, 2026-10-13)

## Result

_Pending: r8 (depth families, bookDepth 2025) and r9 (queue and microprice families, bookTicker
2023-24) were pre-registered and launched on 2026-10-13; this section is written from their
outputs once the chains end._

## Why

After seven protocols on twenty pairs ([2026-10-12](research-execution-tick-2026-10-12.md),
[mid-caps](research-midcap-tick-2026-10-13.md)) the price and flow families lose the fees at
every hold of fifteen minutes or less, and the only rule with content at hourly holds has a sign
that follows the month. The families of the owner's list that remained untested at scale are the
**book families**: depth imbalance and the pullback confirmed by the book (r8), queue imbalance
and microprice (r9). The captures had tested them on two symbols, one day, two venues.

Two public archives make the test possible without new captures:

| Archive | Content | Coverage | Per symbol-day |
|---|---|---|---|
| `bookDepth` | cumulative notional within ±1, 2, 3, 4, 5 % of the mid, one snapshot every 30 s on average | 2023 → today | 0.5 MB |
| `bookTicker` | every change of the best bid or ask, with the displayed quantities | 2023-05-16 → 2024-03-30 | 25–200 MB |

Pre-registrations: `PREREGISTRATION-r8.md` (frozen 2026-10-13T06:18:52Z, sha256 `16fc120a…`) and
`PREREGISTRATION-r9.md` (frozen 2026-10-13T06:30:51Z, sha256 `807bc3a6…`), both in
`tradingV3-private/research/20261012T060000Z-execution-r1/`, both before any archive was read
for its content (one file of each kind had been opened to read the column layout; disclosed in
the texts).

## r8 — depth families at the platform's cadence (bookDepth, 2025)

- **Universe:** the ten majors and the ten mid-caps of r6 (twenty pairs). **Training:** the nine
  2025 windows already on disk (February to October, 180 series). **Validation:** 2025-01-13..17.
  **Holdout:** 2024-12-09..13, separate directory. Neither had been read by anything.
- **Features:** `di_b` = bid-minus-ask share of the notional within `b` % (1 and 2) of the last
  snapshot stamped at or before the bar (NaN when older than 120 s); `ddi_b` = its change since
  the last snapshot at least 300 s earlier; the flow and band features of r1–r7.
- **Rules (28):** Q1 depth momentum (`sgn · di_b > t`), Q4 depth contrarian (`sgn · di_b < −t`),
  both with and without flow confirmation, `t` ∈ {0.25, 0.4}; Q2 build-up (`sgn · ddi_b > d`,
  `d` ∈ {0.15, 0.3}); Q3 band reversion with depth support (the S7 condition and `sgn · di_1 > t`,
  `t` ∈ {0.1, 0.25}, EMA 300 / 900 s, 1.5 / 2 σ).
- **Policies:** entry E1 at the touch (through rule); exits Y1 taker at 15 min, Y3 bracket
  1.5 / 3 σ_300 (60 min cap), Z3 passive at 60 min then taker at 65 min. The 5-minute taker exit
  was dropped before the run (it lost in every protocol so far). Long and short streams for every
  rule, the pooled two-sided stream for Q3: **192 configurations**.
- **Gates, controls, selection:** as r6 (G1 ≥ 300 trades … G8 fill ≥ 20 %; regime-matched random
  timing, 10 seeds; at most three per family to validation; validation and holdout checks
  trades ≥ 100, net > 0, PF ≥ 1.05, above the control median, ≥ 50 % of series positive).

### r8 training

_Pending._

### r8 validation and holdout

_Pending._

## r9 — queue and microprice families on event-level top of book (bookTicker, 2023-24)

- **Universe:** eighteen pairs (the ten majors and ARB, OP, APT, SUI, NEAR, INJ, ATOM, FIL; TIA
  and SEI were listed after the first window). **Training:** 2023-07-10..14, 2023-09-11..15,
  2023-11-13..17, 2024-01-15..19 (72 series). **Validation:** 2024-02-12..16. **Holdout:**
  2024-03-25..29, the last full week of the archive, separate directory.
- **Data:** the bookTicker stream reduced to one record per second (the last update at or before
  the end of the second with its displayed quantities); the bars' quotes are these records, not
  the print proxies of r1–r8; the aggTrades of the same days give fills and flow.
- **Features:** `qi` queue imbalance of the bar's quote and `qi_5` its 5-second mean; microprice
  `mp = (ask · bq + bid · aq) / (bq + aq)`, offset `mpo = (mp − mid) / spread`, momentum
  `dmp_W = ln(mp_s / mp_{s−W})` for 5 and 30 s; `fi_10`, `fi_60`, `a_60`, `sigma_60`.
- **Rules (16):** S3 queue imbalance (`sgn · qi_5 > q`, `q` ∈ {0.3, 0.6}, with and without
  `sgn · fi_10 ≥ 0`); M1 microprice offset (`sgn · mpo > t`, `t` ∈ {0.2, 0.35}, with and without
  flow); M2 microprice momentum (`sgn · dmp_W > k · sigma_60 · sqrt(W / 60)` and `a_60 ≥ 1`,
  `k` ∈ {1, 2}); M3 queue persistence (`sgn · qi > q` on each of the last `n` bars, `q` ∈ {0.3, 0.5},
  `n` ∈ {3, 10}).
- **Entries:** E1 at the touch with the through rule; **E3 at the touch with the queue rule**:
  the order joins the queue behind the displayed quantity of its side at the decision second
  (own size 250 USDT / price) and fills when the prints at its price taken by the opposite side
  since activation exhaust that quantity plus its own, or when the price trades through.
- **Exits:** X1 taker at 60 s, X3 bracket 1 / 1.5 σ_60 (300 s cap), X4 passive at 60 s then taker
  at 90 s, Y1 taker at 15 min. **256 configurations.** Gates, controls and checks as r8.
- Stated before the result: a signal at 1–60 s is not executable by the platform's minute loop;
  a positive r9 calls for a dedicated execution path, a negative r9 closes the owner's list of
  families on fill-capable data.

### r9 training

_Pending._

### r9 validation and holdout

_Pending._

## Budgets

_Pending._

## Limits

- Binance archives only; a candidate would need confirmation on the OKX and Hyperliquid books.
- bookDepth: the ±1 % band is wide for the majors (100 bp against ticks of 0.01–0.4 bp); the
  snapshot cadence (30 s) bounds the reaction time.
- bookTicker: the per-second reduction keeps the last state of each second; the queue rule
  ignores cancellations ahead of the order (pessimistic) and queue priority inside the second
  (optimistic); 2026 tick sizes and the 2026 fee policy applied to 2023–24 data.
- Exit fills keep the through rule in both protocols.
