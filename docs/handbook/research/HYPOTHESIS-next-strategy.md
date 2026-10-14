# Retained hypothesis — post-listing drift of newly listed perpetuals (H1)

Status: **proposed for the owner's review.** No market data has been analysed for this hypothesis. The choice
below uses no simulated or historical return.

## The hypothesis in one paragraph

When a venue lists a new USDT perpetual, the token enters weeks in which identifiable sellers arrive:
airdrop recipients, early investors, unlocks and market makers lending inventory. Shorting also becomes possible
for the first time, through the perpetual itself. Meanwhile the attention that brought buyers at the listing
fades. We expect the token to **underperform the market over the following days to weeks**. If that drift
exists, is larger than its costs (funding above all) and survives a stop that protects against squeezes, a
low-frequency short strategy could exploit it: a few new listings per week across venues, holds of days.

## Why this one, and not H2 or H3

| Criterion | Assessment |
|---|---|
| Economic mechanism | Three independent, documented forces push the same way: supply overhang (token unlock and airdrop economics), the release of a short-sale constraint (Miller 1977) and fading attention (new-issue underperformance, Ritter 1991). The mechanism names the participants on each side. |
| Distinct from everything tested | TradingV3 never used an event trigger and never traded young instruments. Every universe excluded them by construction (42–200 days of history). The nearest tested mechanism, open-interest build-up (r13/r14), was a 15-minute positioning signal on established pairs. |
| Testable now | About 255 OKX listings (2023-09 → 2026-10) already have 1-minute candles and real funding on disk. Their post-listing returns were never measured. Binance-only listings and all future listings were never read. |
| Pertinent control | Natural and strict: established coins on the same dates with matched volatility and size, plus the same coin later in its life. The market-relative construction removes the bull / bear exposure that explained most past "edges". |
| Executable stop | The squeeze risk is real, and the protocol confronts it first. Gate 1 measures the adverse-excursion distribution before any strategy is designed. |
| Events | About 7 listings per month on OKX, more across venues. That is enough for an event study on the archive and for a prospective test of a few months. Events cluster in time, which the statistics must respect (month blocks). |
| Cost of research | Low. The event study needs no new engine and no new download for the first set. |

**H2** (idiosyncratic reversal) is a good second hypothesis. It needs a broad hourly dataset to download and a β
model, and its "information vs liquidity" ambiguity is harder to control. **H3** (rejected breakouts) is too
close to the breakout and reversion families already invalidated to justify the next campaign.

## What would make us drop it

- No negative market-relative drift beyond the matched control on the archive set, with the pre-declared
  precision (Gate 1). In that case no strategy engine is developed.
- A drift that exists but is matched by funding paid by shorts, or erased by the upside tail. Gate 1 measures
  both; Gate 2 decides.
- A drift present on the OKX archive set but absent from the never-read Binance-only set.

## What it is not

- **Not a funding strategy.** Funding is a cost to measure, not the signal.
- **Not market making**, not a parameter grid, not a variant of Q3 or of the swing breakouts.
- **Not a promise of returns.** The first step only asks whether the phenomenon exists.

## References in the repository

- Landscape: `docs/handbook/reports/research-strategy-landscape-2026-10.md`
- Hypotheses: `docs/handbook/research/research-new-market-hypotheses.md`
- Draft protocol: `docs/handbook/research/DRAFT-PREREGISTRATION-next-strategy.md`
- Implementation plan: `docs/handbook/research/IMPLEMENTATION-PLAN-next-strategy.md`
- OKX archive tooling (swing v2 OKX, PR #500):
  - `python-orchestrator/scripts/research_swing2/okx.py`, `okx_universe.py`, `okx_audit.py`
  - instrument audit and listing segments in `tradingV3-private/research/20261014T110000Z-swing-v2-okx/`
