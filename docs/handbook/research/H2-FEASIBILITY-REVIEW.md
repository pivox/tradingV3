# H2 (reversal of idiosyncratic shocks) — feasibility on markets accessible from France (2026-10-14)

Feasibility only. No strategy is written, no return computed, no parameter chosen from data.

**Hypothesis** (`research-new-market-hypotheses.md`). When a coin moves unusually and the market does not share
the move, part of it is a demand for liquidity, not information, and it partly reverses over the next days.

## Answers

1. **Are the accessible instruments enough?** Yes for the cross-section.
   - OKX Europe has 160 crypto X-Perps, **29 above 1 M USD a day** and 6 above 10 M (2026-10-14). They are
     established tokens (BTC, ETH, SOL, XRP, NEAR, ZEC, HYPE, ONDO, SUI, TAO, INJ, LINK, AVAX, DOT…).
   - A cross-section of 20–30 liquid contracts is enough to define shocks and controls. H2 does not need new
     listings, unlike H1.
2. **Can market-relative returns be observed?** Yes, from candles of the same venue. The market factor is BTC, or
   an equal-weight index of the liquid X-Perps. β is estimated on a past window only.
3. **Is the historical data precise enough?**
   - **On the tradable product, no.** X-Perps exist only since 2026-03-30, and most since June–September 2026.
   - **On the same underlyings, yes.** OKX global USDT-swap 1-minute candles 2022–2026 are on disk (363 families),
     with real funding.
   - Price paths of the same token across OKX products are tied by index and arbitrage. Liquidity, spread and
     funding are not; they must be modelled per product.
4. **Can a shock be defined without the future?** Yes. A residual over a fixed window ending at the decision:
   - the 24 h return minus β × the market's 24 h return, with β from the previous 60 days;
   - standardised by the residual's own past volatility;
   - condition: |z| ≥ k and a small market move.
   All inputs are known at the decision time, on closed hourly bars.
5. **Long and short?** Yes, on X-Perps, Kraken EEA and Coinbase EEA. A reversal needs both sides; the protocol must
   report them separately.
6. **Can the expected moves exceed costs?**
   - Plausibly. Residual shocks of several percent against a 14 bp round trip (2 / 5 bp fees plus slippage) and
     8 h funding over 1–5 days.
   - Not demonstrated. Gate 2 decides, with pessimistic costs and the thinness of X-Perp books.
7. **Is 500 USDT compatible?** Yes on the liquid X-Perps. At 0.5 % risk and a 5 % stop the allowed notional is
   about 43 USDT; minimum orders are 0.1–20 USD. Two positions at full risk fit the 1 % total.
8. **Is H2 different from the mean reversion already rejected?** Yes, on three axes:

   | | Rejected reversion families | H2 |
   |---|---|---|
   | Studies | r3–r8b, Q3; swing RSI / oversold | — |
   | Measure | Absolute: distance to an EMA or an RSI level | Market-relative residual |
   | Horizon | Intraday (≤ 1 h) or 4h | Days |
   | Universe | One pair against its own past | Cross-section, condition that the market did not move |

   The economic mechanism (liquidity provision for idiosyncratic flow) was never the object of a test. The risk
   of overlap is real: H2 must show its effect **beyond** a control of absolute reversion with the same size of
   move.
9. **Credibly independent validation?**
   - Not historically on X-Perps (too short).
   - The OKX global archive 2023–2026 was read by swing v2 OKX (a different hypothesis). It can serve for
     development and a first effect study, labelled as such.
   - **Independent validation is prospective on X-Perps**, live candles and books, over a fixed period. The Kraken
     Futures public history is a candidate second historical set (its data licence and its identity with the EEA
     book are unverified).
   - Binance data cannot validate a strategy meant for live trading (dataset licence).
10. **Controls against market exposure:**
    - **Same-size shocks shared with the market:** non-idiosyncratic moves of the same absolute size. This
      separates H2 from absolute reversion.
    - **Random dates** of the same coin with the same volatility regime.
    - **β-neutral measurement:** residual returns after the event, not raw returns.
    - **Matched on the sign of the market move**, so that a market rebound is not counted as reversal.
    - **Month-block bootstrap** (shocks cluster on stress days).

## Proposed falsifiable experiment (outline; not a protocol)

1. **Gate M1 first.** Verify on the owner's account that X-Perps are open and API-tradable.
2. **Step 1, effect study, exploratory, on the OKX global archive 2023–2026** (on disk, labelled consumed at candle
   level):
   - the 30 most liquid established tokens that also exist as X-Perps today;
   - shock definition, β, k and horizons (1, 3, 5 days) fixed **before** any read;
   - distribution of the post-shock residual return against the controls above;
   - Gate 1: the effect exists beyond the shared-shock control with month-block CIs below zero, stable by
     half-year, not concentrated.
3. **Step 2, only if Step 1 passes:**
   - a pre-registered prospective test **on X-Perps** over a fixed calendar period (proposal: 6–12 months), with
     real X-Perp quotes, fees and 8 h funding;
   - the 500-USDT portfolio, 1×, mandatory stops;
   - no intermediate performance look.

**Feasibility verdict:** H2 is **feasible in the accessible scope** if M1 passes on OKX Europe. It is better suited
than H1 to a French account: established liquid contracts, two sides, no dependence on new listings. Its main
limits are a historical record on another product of the same underlyings, and the closeness to the reversion
families already rejected, which the shared-shock control must settle.
