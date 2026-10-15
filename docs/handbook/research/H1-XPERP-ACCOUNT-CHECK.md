# H1 on OKX Europe X-Perps — owner's account check, interface level (2026-10-15)

A follow-up to `H1-XPERP-MARKET-ACCESS.md` §3. The frozen H1 documents and the five X-Perp audit notes are left
unchanged.

**Source.** The owner looked at the X-Perp order panel of his account (OKB-USD UM X-Perp, 5-year expiry, on
my.okx.com/fr-fr) and commented: « OKBUSD UM X-Perp c'est ok, donc compte okx c'est ok ». The peer session read the
screenshot and relayed what it shows. Claude made no call and placed no order.

## Verified at interface level

| Item (§3 step) | Observed |
|---|---|
| X-Perps unlocked on the account (1) | **Yes**: the order panel is available. `ACCOUNT_ELIGIBILITY` at interface level passes. |
| Margin mode (2) | Isolated margin is available. |
| Leverage selector (2) | 1× / 2× / 3× / 5× / 10× / 50× offered on OKB. 1× is available. |
| Short (2) | "Vendre (Short)" is offered. |
| Order types (4) | Limit, market, TP / SL; Reduce Only checkbox. |
| Fees (3) | Maker 0.0200 %, taker 0.0500 %, as documented. |
| Margin currency (2, 7) | Prices in USD; **margin and balance in USDC**. Neither USDT nor USD is used as collateral. |

## Still unverified

- **The API.** A Read-only key, `GET /api/v5/account/config` (position mode, net or long / short; TradingV3 always
  sends long / short) and `GET /api/v5/account/instruments?instType=FUTURES`.
- **Limits.** The maximum leverage and per-instrument limits, especially on new listings.
- **Demo.** Whether X-Perps are listed in Demo Trading.
- **Funding the account.** The USDT → USDC conversion path and its minimums.
- **Stops in a gap.** The `EXECUTION_RISK_UNVERIFIED` items of `H1-XPERP-500USDT-FEASIBILITY.md` are not affected
  by this check.

## Effect

- The H1 verdict stays **`H1_XPERP_CONDITIONAL`**.
- Account eligibility moves from unverified to verified at interface level. API trading eligibility stays
  unverified.
- Accounting in USDC confirms the technical gap: TradingV3 assumes USDT for the account, fees and PnL.
- **The historical Gate 2 and amendment A3 are not authorised** and are not started.
