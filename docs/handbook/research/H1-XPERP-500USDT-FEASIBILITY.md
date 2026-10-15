# H1 on OKX Europe X-Perps — 500 USDT feasibility (2026-10-15)

No return, no PnL, no simulation. The parameters are the frozen ones (protocol §13–14) and are not optimised.
Prices are **today's public quotes**, used only to express minimum orders in USD. They are not the prices at
listing.

## 1. Fixed parameters

- Capital: 500 USDT, converted to USDC / USD; USDT itself is not X-Perp margin.
- Leverage ≤ 1×.
- Risk 0.5 % = **2.50 USD per position**, 1 % in total: at most two positions at full risk, one per instrument.
- Stop d = clamp(3 × σ24, 15 %, 60 %). The minimum is never rounded up, and risk is never raised.

## 2. Allowed notional

Notional = 2.50 / (d + costs), with taker 5 bp per side and a pessimistic 50 bp slippage on each side.

| Stop d | Allowed notional (USD) |
|---|---:|
| 15 % (floor) | ≈ 15.6 |
| 30 % | ≈ 8.1 |
| 60 % (cap) | ≈ 4.1 |

**New listings sit mostly at the cap.** The median σ24 of S_B new listings was 20 % a day, so 3σ = 60 %. The
typical H1 position on an X-Perp is therefore **about 4 USD of notional**.

## 3. Minimum order on the 13 new-token X-Perps (live at T or T + 48 h)

Every one has `minSz = lotSz = 1`. Minimum order = 1 × ctVal × today's last price.

| X-Perp | Live at T | Min. order (USD) | 24 h value today (USD) | Executable at d = 60 % (≈ 4.1 USD) | at d = 15 % |
|---|---|---:|---:|---|---|
| GRAM | yes | 1.34 | 132 k | yes (3 contracts) | yes |
| O | yes | 4.25 | 3.7 k | **no** (skipped) | yes |
| NES | yes | 1.48 | 493 k | yes | yes |
| AEON | yes | 0.42 | 76 k | yes | yes |
| GRVT | yes | 2.09 | 13 k | yes (1 contract, 51 % of the budget) | yes |
| KII | yes | 8.64 | no trades reported | **no** | yes |
| XDP | yes | 1.83 | 145 k | yes | yes |
| CT | yes | 3.52 | 1.4 M | yes (1 contract, 86 %) | yes |
| QUANT | yes | 1.94 | 4.0 M | yes | yes |
| RE, ARX, DOS, CP | T + 48 h only | 0.91–3.96 | 17–109 k | (not H1-eligible) | — |

- At today's prices, **2 of the 9 eligible events (O, KII) would be skipped** at a 60 % stop under the
  never-round-up rule.
- One-contract granularity uses only 50–90 % of the risk budget on several contracts.
- Prices at listing were different, so these counts are indicative only.

## 4. Squeeze and execution risk

The historical S_B record: P(adverse move > 25 %) = 41 %, P(> 50 %) = 24 %. A stop does **not** cap the loss at
2.50 USD.

| Mechanism | What is known | Status |
|---|---|---|
| Stop-market | App TP / SL at market on last / mark / index. API `order-algo` takes FUTURES ids; not explicitly confirmed for X-Perps. | To be checked on demo |
| Gap through the stop | A futures market order fills at the most aggressive price the price-limit mechanism allows. Price-band behaviour of a triggered stop during a gap is **not documented**. | **Unverified** |
| Price bands | ±4 % of the index in the first 10 minutes. Then a floating band, capped at **30 % from the index** (`maxPxLmtPct`). The band moves with the index; a fast squeeze may leave a stop partly filled. | Partly documented |
| Suspension | Only general terms ("may change or suspend services", cancellation in extraordinary conditions). No circuit-breaker rule for crypto X-Perps. | **Unverified** |
| Liquidation | At 1× isolated, a short is liquidated near a +100 % move. Negative balance protection caps the loss at the margin (≈ 4 USD, about 1.6 R at d = 60 %). | Documented |
| Auto-deleveraging | Possible in extreme conditions; it acts on profitable positions. | Documented |
| **Funding** | New-token X-Perps: **every 4 h, cap ±1 %**, interval shortened automatically when the cap is hit. Crowded shorts on a new token can pay up to 6 % a day at the cap, more after escalation. Protocol §12 provisions 0.03 % (baseline) and 0.1 % (pessimistic) per 8 h, i.e. at most 4.2 % over 14 days. **The X-Perp cap allows funding far above the protocol's pessimistic case.** The level actually paid on new X-Perps is unmeasured. | **Unverified** |
| Depth | Today's 24 h value ranges from 0 (KII) to 4 M USD (QUANT). Order books at listing are unmeasured; spread and slippage on 4 USD orders are probably small, but the stop's fill in a squeeze is not. | **Unverified** |
| Forced close | Delisting with notice is possible (SKDD, an equity X-Perp, was delisted on 2026-09-03). The 5-year expiry is irrelevant for a 14-day hold. | Documented |

**Verdict for this layer: `EXECUTION_RISK_UNVERIFIED`.** Sizing is feasible, but at about 4 USD of notional per
trade. Stop fills in a gap and the real funding paid on new X-Perps are unknown, and the funding cap is well
beyond the protocol's pessimistic assumption.
