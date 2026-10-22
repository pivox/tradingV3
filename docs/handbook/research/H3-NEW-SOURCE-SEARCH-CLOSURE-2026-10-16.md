# New candidate-source search — closure (2026-10-16)

**Status: search suspended. No defensible new source today (`NO_DEFENSIBLE_SOURCE`).** H3-A stays
`SCORING_UNTESTABLE`. Nothing was collected, simulated, optimised, trained or traded.

`NO_DEFENSIBLE_SOURCE` means that the evidence available now does not justify a source well enough. It is **not a
proof that no market effect exists**.

## What was searched (owner GO 2026-10-16)

The search was desk research only:

- public instrument metadata, official documentation, terms of use and literature;
- no price, return, volume, funding, open-interest or on-chain series read.

The target was a source that is:

- frequent enough to be validated in a reasonable time;
- tradable from France with 500 USDT, which today means OKX Europe X-Perps;
- independent of the closed H1, H2, CS-XMOM and CS-EARN, and of the earlier closed families.

| Domain | Sources examined | Verdict |
|---|---|---|
| Crypto-native X-Perp structure | funding-settlement pressure; funding-interval escalation; options-expiry pinning; contract-rule events | all rejected: the gain is bounded by the funding rate (≈ 1 bp in the base state) against ≥ 10 bp of costs, they overlap closed families (funding detector D4, OI positioning, microstructure), or they occur too rarely |
| TradFi X-Perps (4 commodities, 9 ETFs/indices, no FX) | end-of-session intraday momentum; oil index roll; ex-dividend dates; EIA reports; index rebalances; overnight premium | all rejected: the only frequent effect is ≈ 2.5 bp gross per trade (published), against ≥ 10 bp of costs, and the rest occur 12–80 times a year. Account access to TradFi X-Perps is unverified, and the regulator states they are likely in CFD scope |
| Cross-product flows | US spot crypto ETF flow persistence; Deribit/CME expiries; index reconstitutions, ETF launches, buybacks | all rejected after an adversarial review. ETF flows: the information is very probably priced before the decision time; causality runs from price to flows; the published evidence points to reversal, not persistence; issuer terms forbid automated capture; there is no point-in-time history |
| Stablecoin mint/burn (CS-STABLE) | full reading of Ante, Fiedler & Strehle (2020/2021), Lyons & Viswanath-Natraj (2020/2023), Saggu (2022) | `NO_DEFENSIBLE_SOURCE`, closed. Details below |

## CS-STABLE in short

**What the studies show:**

- The 24 h post-issuance "abnormal return" mostly reflects the benchmark: raw post-event returns are not significant.
- The only minute-level study shows about +12 bp gross between 5 and 30 min after a USDT mint (about 7 bp with a robust
  estimate), on data from 2014–2021. Costs are 10 bp taker plus the spread.

**What changed since:**

- Since 2022, mints are mostly treasury inventory refills, confounded with chain swaps.
- The effect has been public since 2022.
- Data access under third-party terms is restricted or paid.

**The reviewer's dissent.** The independent reviewer argued for a conditional variant: large mints (≥ 500 M$), data
from a personal node, a 30 min hold, and a post-2021 sample. It rests on extrapolating a size slope to the edge of a
single 2014–2021 sample with a low R² and no out-of-sample evidence. The owner declined it.

## Next milestone

- The search for new sources is suspended.
- The next decision point is the verdict of the forward test fwd-okx-r1, due 31 October 2026.
- The forward test is not modified, and its reserved data are not consulted before then.

Records (private, not published here): the literature log with sha256 fingerprints, the papers, the archived terms of
use and the full reports.
