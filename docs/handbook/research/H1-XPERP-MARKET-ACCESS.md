# H1 on OKX Europe X-Perps — market access (2026-10-15)

Public official sources only: OKX help and legal pages, the OKX v5 API docs and changelog, the ESMA register, and
two unauthenticated GETs. No login, no authenticated call, no order. It updates `FRANCE-EXCHANGE-ELIGIBILITY.md`,
which is left unchanged.

## 1. Eligibility, by layer

| Layer | Status | Evidence |
|---|---|---|
| **REGULATORY_ELIGIBILITY** | **Verified for the firm; France passport not verified** | ESMA register: *OKX Europe Markets Limited*, MiFID investment firm, MFSA (Malta), active, authorised 2020-12-17, LEI 984500368874BC14E281, services "execution of orders" and "dealing on own account". The France passport entry was not reached (AMF REGAFI not queried). |
| **PLATFORM_ELIGIBILITY** | **Partial** | X-Perps are "supported across the EEA", but "access may not be enabled at the same time in every EEA country". No country list was found, and France is not named either way (`okx-x-perps-eea-regional-availability-eligibility`). |
| **ACCOUNT_ELIGIBILITY** | **`ACCOUNT_ELIGIBILITY_UNVERIFIED`** | The owner said "okx ok" (2026-10-15). The appropriateness result, the X-Perps account mode and the effective leverage are not visible from public sources. |
| **API_TRADING_ELIGIBILITY** | **Documented, account unverified** | X-Perps are offered "on API, desktop and mobile". EEA users must use `eea.okx.com`, otherwise requests fail. API keys have Read / Trade / Withdraw permissions. Whether a key on the owner's X-Perps account carries Trade is unverified. |

The contract terms are with **OKX Europe Markets Ltd** (Malta, C 95813, licence OEML-15905). The entity's address
differs between its Terms of Service (Sliema) and the ESMA register (Valletta); this is noted, not resolved.

## 2. The ten questions

1. **Available to a French retail resident?** Plausibly yes, under MiFID with an EEA passport. France is not
   confirmed by name, and a country can be enabled later than others.
2. **Classification and appropriateness.** A test is mandatory before access (about 5 minutes; a failed test
   imposes a wait). It classifies the client as retail or professional, and the product offering is the same for
   both.
   - **Negative balance protection** applies to retail clients (Terms §1.10.3.4).
   - Retail leverage is **up to 10×** since 2026-06-09. The ESMA 2:1 CFD cap does not apply, since these are
     futures.
   - No "% of accounts losing money" figure and no 50 % close-out rule were found.
3. **Shorts:** allowed ("long and short positions").
4. **Margin.**
   - Modes: isolated, "selected margin", and cross on some pages; one-way and hedge position modes.
   - Margin and settlement currency: **USD, USDC or USDG**. Listed margin assets include BTC, ETH, SOL, USDC, USDG,
     DOGE and EUR / USD with haircuts. **USDT is not accepted.**
   - Liquidation is at the maintenance level, on the mark price. Auto-deleveraging can apply in extreme conditions.
5. **Stop orders.**
   - In the app: TP / SL at market, triggered on last, mark or index price; reduce-only exists.
   - Through the API: `order-algo` conditional / oco / trigger orders take FUTURES ids. No page explicitly confirms
     or excludes X-Perps for algo orders. **Verify on demo.**
6. **Automated API trading:** documented. Production REST `https://eea.okx.com`, WebSocket `wss://wseea.okx.com`;
   demo through the `x-simulated-trading: 1` header.
7. **Differences from the OKX Global API:**
   - `instType FUTURES` with `ruleType xperp`, versus `pre_market` before conversion (`preMktSwTime`);
   - specific fee groups ("XPERP group");
   - **funding endpoints serve X-Perp ids since 2026-03-31**;
   - USD settlement;
   - ids `<BASE>-USD_UM_XPERP-<YYMMDD>`.
8. **Fees and funding.**
   - Fees: 2 bp maker / 5 bp taker for standard accounts.
   - Funding = clamp(average premium + clamp(interest − premium, ±0.05 %), cap, floor), with interest 0.01 % per
     8 h.
   - **The interval varies per contract.** BTC is 8 h with a ±0.375 % cap. **The 12 new-token X-Perps live at T or
     T + 48 h pay every 4 h with a ±1 % cap; QUANT pays every 8 h with ±1.5 %** (public `funding-rate`,
     2026-10-15).
   - Since 2026-04-14 the interval can **shorten automatically** (to 2 h or 1 h) when the cap is hit, and reverts
     after 12 h back inside ±0.20 %.
9. **Restrictions on new contracts.**
   - Price-limit bands: `initPxLmtPct` during the **first 10 minutes** after listing (0.04 on new tokens);
     `floatPxLmtPct` in normal trading; `maxPxLmtPct` is a hard cap on an order's deviation from the index (0.30
     on new tokens).
   - Position limit = max(`posLmtAmt` 250,000 USD, OI × `posLmtPct`).
   - No call auction for X-Perps. No launch-specific leverage cap or reduce-only period was found.
   - Pre-market / pre-IPO X-Perps have their own rules (rebase suspension, post-only phase); H1 excludes
     pre-market events anyway.
10. **500 USDT.**
    - USDT must be converted one way to USDC (manually, since 2026-07-17) and moved to the trading account.
    - No minimum deposit was found.
    - Size feasibility: `H1-XPERP-500USDT-FEASIBILITY.md`.

**Discrepancy.** The FAQ states "a minimum lot of 0.01 contracts". The public API gives `minSz = lotSz = 1` for
every new-token X-Perp. The API value governs API orders and is used below.

## 3. What only the owner can verify (read-only, nothing to share)

1. **X-Perps on the account.** On my.okx.com: Trade → "Unlock X-Perps". Check that a France-resident account is
   offered the flow, that the appropriateness test is passed, and the resulting classification.
2. **Account limits.** In the X-Perp order panel, note:
   - the maximum leverage;
   - the margin modes (isolated, selected, cross);
   - the position mode, net or long / short (TradingV3 always sends long / short);
   - the accepted margin assets.
3. **Fees.** The fee-tier page: X-Perp maker / taker at the actual tier.
4. **Order types**, without submitting: stop / trigger / OCO / reduce-only in the X-Perp panel.
5. **API key.** Create a key for the X-Perps account, with Read only first (Trade later, never Withdraw), bound to
   an IP. Run read-only `GET /api/v5/account/config` (position mode) and
   `GET /api/v5/account/instruments?instType=FUTURES` (per-account leverage and limits) locally. Share the results,
   never the key.
6. **Demo.** In Demo Trading, check that `ruleType xperp` instruments are listed. Whether the demo accepts an X-Perp
   algo stop can then be checked there by the owner. No order is placed by Claude.
7. **USDT → USDC.** The convert path and its minimums, and USDC accepted as X-Perp margin.

Until these are done, the account and API layers stay **unverified**.
