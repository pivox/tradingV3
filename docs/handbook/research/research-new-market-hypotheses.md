# New market hypotheses (2026-10, design phase — no data analysed)

Three hypotheses at most, each about a precise market behaviour rather than a parameter grid. They are ranked
by scientific value and feasibility (§4), not by any historical PnL: none was computed.

The [research landscape](../reports/research-strategy-landscape-2026-10.md) gives the background. Every past
study was a time-series signal on an established pair against its own history. The three hypotheses below
leave that frame in different ways:

- **H1** uses an instrument life-cycle event.
- **H2** uses a market-relative quantity.
- **H3** uses the failure of an event that past studies only used in its successful form.

---

## H1 — Post-listing drift of newly listed perpetuals

### A. Economic mechanism

When a venue lists a new USDT perpetual, the instrument enters a period in which several structural sellers
and a newly unconstrained short side meet a temporary, attention-driven demand:

- **Supply overhang.** Recent tokens often launch with a low float and a high fully diluted value. Airdrop
  recipients, early investors, market makers lending inventory and scheduled unlocks are all sellers whose
  supply arrives in the first weeks.
- **Short-sale constraint released.** Before the perpetual exists, pessimists can hardly short the token. Spot
  borrow is scarce or absent. A perpetual lets them express a negative view. Miller (1977) argued that prices
  under short-sale constraints reflect the optimists; relaxing the constraint should lower prices.
- **Attention.** Listing announcements concentrate retail attention and buying at the listing, which decays
  afterwards. This mirrors the long-run underperformance of new equity issues (Ritter 1991).

Participants on the other side are retail buyers attracted by the listing, and the market makers who must
inventory the token.

The hypothesis is economic, not statistical: a predictable seller (supply), a released constraint (shorting)
and a fading buyer (attention).

### B. Trigger, observable at decision time

- **Event:** the first trading minute of a new USDT-margined linear perpetual on the venue (OKX: `listTime`,
  and the first archived candle; Binance: `onboardDate`). It is announced publicly beforehand, so nothing about
  the event is in the future.
- **Classification fixed in advance and observable at the event:**
  - whether the token already traded spot on a major venue before the perpetual (perp-first vs spot-first);
  - the venue;
  - market phase, by the sign of the market's 30-day return at the event.

### C. Expected direction

A negative return relative to the market over the following days to weeks (short side). The effect should be
stronger:

- when the perpetual is the first venue (pre-market tokens);
- when the float is low (only observable indirectly).

### D. Horizon

From 1 to 30 days after the listing, with 14 days as the primary horizon. The first hours (the opening auction
and price discovery) are excluded from the measured window. The reference price is the first full hour after
24 h of trading.

### E. Risk — when it must fail

- **Squeezes.** New listings are thin and crowded with shorts. Upward jumps of 50–200 % in hours happen. That
  makes the stop mandatory, and the stop may be slipped through.
- **Bull phases with strong rotations** into new tokens: the drift may vanish or reverse.
- **Funding.** If shorts crowd in, they pay funding, possibly at the venue's cap, every 1–8 h.
- **Delistings and price limits** on young instruments.
- **Survivorship in the archive** (§ data in the draft protocol).

### F. Costs

The expected magnitudes, to be measured, are moves of several percent to tens of percent over days. A 14-bp
round trip is negligible. **Funding is the cost that can decide:** it must be measured per event from the
venue's real settlements. The minimum order size of new contracts against a 500-USDT account at 0.5 % risk is
a feasibility question, answered in the protocol.

### G. Novelty

No TradingV3 study used a listing as an event. Every universe excluded young instruments:

| Study | Exclusion of young instruments |
|---|---|
| Swing v2 OKX | 42-day warm-up; `PREREGISTRATION-okx.md` |
| regime-r2 | ≥ 200 days of history |
| The 20 programme pairs | all listed for years |

The closest mechanism tested is the open-interest build-up (r13/r14). That was a positioning signal at a
15-minute horizon on established pairs, not a supply / constraint event.

### H. Feasibility

- **Already on disk.** The OKX archive downloaded for swing v2 OKX already holds the 1-minute candles and
  funding of about 255 OKX listings, September 2023 → October 2026 (counted from metadata only, no return
  read: about 20 / 76 / 107 / 52 by year). Swing v2 OKX loaded these prices, but never traded the first 42 days
  of any series and never measured post-listing returns.
- **Never read.** Binance listings of coins not on OKX, plus all future listings on both venues (about 7 per
  month on OKX).
- **Infrastructure.** The engine's 1-minute simulation, funding settlement, stop logic, OKX acquisition and
  monthly universe code are reusable.

### Falsification

H1 is falsified if the 14-day market-relative return after listing is not negative beyond the matched control,
with the pre-declared precision, on the archive set and the never-read set (Gate 1 in the draft protocol).

---

## H2 — Reversal of market-relative (idiosyncratic) shocks

### A. Mechanism

A large move in one coin that the market does not share is more likely to be a demand for liquidity than news
about value. Typical causes are a large holder selling, a forced liquidation concentrated on one contract, or an
exchange flow. Liquidity providers absorb it and are paid by a partial reversal, as in the price-pressure
literature (Grossman-Miller 1988; Campbell-Grossman-Wang 1993; Nagel 2012 on short-term reversal as the return
to liquidity provision).

### B. Trigger

- The coin's 24-h return minus β × the market return (β estimated on the previous 60 days; market = BTC or an
  equal-weight basket) is beyond k standard deviations of its own residual distribution, while the market's
  move is small.
- Decided on closed hourly candles.

### C. Direction

Reversal (long after a negative shock, short after a positive one).

### D. Horizon

1 to 5 days.

### E. Risk

The shock carries information (hack, exploit, delisting, unlock, regulatory news), so the move continues. Fat
tails. Crowded altcoin rotations can produce momentum, not reversal.

### F. Costs

Residual shocks of several percent against a 14-bp round trip, with funding over days. A hedged version (BTC
leg) doubles the costs; a single-leg version keeps the market risk.

### G. Novelty

Every reversion study in TradingV3 was absolute and intraday:

| Study | Reversion signal |
|---|---|
| r3–r8b, Q3 | 15-minute EMA band, ≤ 1 h |
| swing v1, v2 selection | RSI / oversold at 4h, absolute |

None conditioned on the move being idiosyncratic, used a multi-day horizon or ranked across many coins.
Regime-r2 was market-wide (the opposite construction). v2 named cross-sectional strategies as out of scope.

### H. Feasibility

- Needs hourly candles for a broad universe and a market index.
- The OKX archive is on disk (consumed by swing v2 OKX at the strategy level).
- Binance hourly candles for 2023–2026 outside the 20 programme pairs were never downloaded. regime-r2's
  reserved daily data for 2023–2026 was never read.
- Events are numerous (hundreds per year), but they cluster on market-stress days.

### Falsification

H2 is falsified if the 1–5-day residual return after the shock does not reverse beyond a matched control:
shocks of the same size that coincide with market-wide moves, and random dates.

---

## H3 — Rejected breakouts of multi-day levels (stop-run exhaustion)

### A. Mechanism

Stop orders cluster just beyond obvious levels: round numbers and prior multi-day highs and lows (Osler 2003,
2005, on stop clustering and cascades in FX). A move that runs those stops and then closes back inside the range
has absorbed the forced orders without attracting follow-through. The liquidity that pushed it is spent, and
price may revert.

### B. Trigger

- A 1h bar trades beyond the prior N-day high (low) by at least x ATR.
- It then closes back inside the range, within the same bar or the next.
- The close back inside is the decision point.

### C. Direction

Reversal into the range.

### D. Horizon

Hours to 2–3 days.

### E. Risk

A rejected breakout is often re-tested and broken. In strong trends the "rejection" is a pause. The mechanism
is close to generic mean reversion, which TradingV3 found regime-dependent.

### F. Costs

The move from the level back into the range is typically one to a few ATR. That is compatible with costs at
1h–1d holds, but tighter than H1 and H2.

### G. Novelty: weakest of the three

Swing r1/r2 and v2 tested breakouts that close beyond the level (continuation). The unfilled continuation
orders of swing v2 are the closest observation of failed follow-through; that was an execution effect, not a
tested signal. The reversion families (rows 9–10 of the landscape) are the same direction at a shorter horizon.

### H. Feasibility

- Candles only; all data are available.
- But every candle of the 20 programme pairs is consumed, so only other coins or prospective data would be
  independent.

### Falsification

H3 is falsified if returns after a rejection do not differ from those after level touches without
penetration, or after random same-regime bars.

---

## 4. Ranking (scientific value × feasibility; no PnL used)

| Criterion | H1 post-listing drift | H2 idiosyncratic reversal | H3 rejected breakout |
|---|---|---|---|
| Economic plausibility | High: identifiable sellers, released constraint, decaying attention | Medium-high: liquidity provision, documented in equities | Medium: stop clustering is documented, but exhaustion vs continuation is ambiguous |
| Distinct from past TradingV3 work | High: no event, no young instruments ever | High: no market-relative signal ever | Low-medium: neighbour of breakouts and reversion |
| Testability with available data | Good: about 255 OKX events on disk; never-read Binance-only and prospective events | Good, but data mostly to download (hourly, broad universe) | Good, but independent data only outside the consumed pairs |
| Pertinent control | Clear: same-date established coins with matched volatility and size; the same coin later in life | Clear: same-size shocks shared with the market | Harder: touches vs penetrations |
| Executable stop | Hard but decisive (squeezes): the protocol must measure MAE first | Feasible | Feasible |
| Events for statistics | ~255 historical + ~7/month prospective; clustered in time | Many, clustered on stress days | Many |
| Research cost | Low (data on disk; event study first) | Medium (downloads, β estimation) | Low |

H1 is retained (see `HYPOTHESIS-next-strategy.md`). H2 is the alternative if Gate 1 of H1 fails, under its own
protocol. H3 is not recommended as a next step because it is too close to the families already invalidated.
