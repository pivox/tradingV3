# Scalping and micro-scalping screen on 1-minute history (2026-10-12)

## Result

**No eligible candidate; the cycle ends at the training screen.** Of 40 pre-registered
configurations on 2023–2024, none passes the gates: every one of the 36 five-minute
configurations and the four 1-minute configurations has a negative mean net R under the
baseline costs (medians −0.30 R at 5m, −0.62 R at 1m), and their regime-matched random
controls lose as much. Costs explain the whole picture: across the 5-minute screen the gross
PnL sums to −30,000 USDT against 97,000 USDT of fees and 71,000 USDT of spread and slippage;
at 1 minute the gross is −3,600 USDT against 97,000 and 114,000. The only setups with some
timing content, the US and Asia opening drives, beat their controls by about 0.1 R and still
lose −0.14 to −0.29 R per trade. Doubling the spreads (gate G8) fails all 40.

Per the pre-registered stop rule no validation pass was run and 2025 stays unread by this
screen (`scalping/validation-verdict.json`). What the screen does establish: on these ten
pairs, with candle-visible setups, taker exits and a 7–27 bp round trip, scalping has no edge
to find, and a historical screen cannot see the order-book edge the project's scalping
contracts are built on. The next step for scalping, if any, is live public-data observation
(Paper replay on the OKX / Hyperliquid captures or a shadow run), not more history.

With this branch, the mandate of 2026-10-12 is exhausted: swing holdout failed, v2 selection
failed, scalping failed. The honest state of the research is in the closing section.

## Why and what the candles cannot show

Third branch of the owner's mandate of 2026-10-12, opened after the swing holdout failed and
the v2 candidate-selection cycle ended with `no_eligible_candidate`
([report](research-v2-selection-2026-10-12.md)). The question: do candle-visible scalping
setups (holds of minutes to an hour) or micro-scalping setups (under fifteen minutes) carry an
edge net of costs on the ten pairs in 2023–2025?

The history has no order book and no trade tape, which the project's own scalping contracts
require. So this screen only tests what candles can show, under rules chosen against the
screen:

- a resting limit fills only when the price trades **through** it by one tick, never on a touch
  (queue position is unknown);
- the 1-minute setups enter at market on the next candle's open and pay the taker fee plus the
  stop leg's spread and slippage on the entry;
- a stop and a target touched inside one candle settle as a stop; a target touched in the fill
  candle is not credited; a stop fills at the worse of the stop and the candle open;
- every result is also computed with every spread rate doubled, and that figure is a gate.

Pre-registration `tradingV3-private/research/20261012T003000Z-explore-v2/PREREGISTRATION-scalping.md`,
frozen 2026-10-12T01:33:24Z (sha256 `14aca390…`) before any PnL computation; only signal
counts and fill mechanics were inspected on two pairs before the freeze.

## Method

- **Data.** The v2 cache (official Binance archives, sha256 verified, cut at 2026-01-01), with
  the taker-buy volume of each candle. Training 2023–2024, validation 2025, 2026 never read.
- **Setups** (long side; shorts mirrored; 15-minute context from the last closed bar):

  | Id | Cadence | Signal |
  |---|---|---|
  | S1 momentum burst | 5m | close above the previous 12 bars' high, volume > 2 × its 20-bar average, 15m close > EMA50 |
  | S2 VWAP pullback | 5m | 15m EMA50 > EMA200 and close > EMA50; a low touched the daily VWAP within 3 bars; close back above VWAP |
  | S3 opening drive | 5m | first bar after 13:30 UTC (US) or 00:00 UTC (Asia); break of its high within 30 minutes; stop at its low |
  | S4 band fade | 5m | close below VWAP − 2 σ with the 15m trend up; exit at VWAP or at the time stop |
  | S5 taker-flow proxy | 5m, 1m | taker-buy share of the last 5 bars' volume > 65 %, close > VWAP, 15m trend up |
  | S6 micro reversal | 1m | three down closes ending below VWAP − 1.5 σ with the 15m trend up; exit at VWAP or 15 minutes |

- **Exits.** 5m: stop 1 or 1.5 × ATR14, target 1.5 R or 2 R, time stop 60 minutes; 1m: stop
  1 × ATR14 (floor 2 ticks), target 1.5 R, time stop 15 minutes; S3 structural stop; S4 and S6
  VWAP exit. 40 configurations (36 at 5m, 4 at 1m).
- **Controls.** Regime-matched random timing (same 15m filter, exits, entry rule, admission),
  matched to each configuration's trade count per pair and year, 10 seeds.
- **Gates** (training, both cost profiles): trades ≥ 1,000 (5m) / 2,000 (1m) with yearly
  minima; net > 0 each year; mean net R ≥ 0.05 baseline with a positive bootstrap 90 % lower
  bound; PF ≥ 1.20 / 1.00; above the control's 95th percentile and ≥ +0.05 R over its median;
  ≥ 6 pairs positive; positive without the best month; drawdown ≤ 6,000 USDT; **net positive
  with the spreads doubled**. At most three survivors per cadence; one validation pass on 2025.
- **Budgets.** As in v2 (1 % of equity per trade, 2 × leverage cap, exchange minimums).

Tooling: `scripts/research_v2/scalping.py` and the engine's entry-mode, structural-stop and
doubled-spread extensions ([runbook](../runbooks/research-v2-screen.md)).

## Training screen

Execution note: the first run (three forked workers holding the 1-minute features of ten
pairs) exhausted the host's memory and was stopped before any configuration finished; nothing
was read from it. The screen then ran in two parts with the same frozen grid and gates: the 36
five-minute configurations through the pooled screen (975 s, two workers,
`scalping/screen-training-5m.json`) and the four 1-minute configurations through a sequential
per-pair driver (`scalping/screen-training-1m.json`).

### Five-minute configurations: 0 of 36 pass

Every configuration loses under the baseline costs, and its regime-matched random control
loses as much:

| Setup | Configurations | Median trades | Median mean R (baseline / adverse) | Control median | Net positive with doubled spreads |
|---|---:|---:|---|---:|---:|
| S1 momentum burst | 8 | 30,142 | −0.296 / −0.336 | −0.292 | 0 |
| S2 VWAP pullback | 8 | 29,678 | −0.294 / −0.340 | −0.285 | 0 |
| S3.US opening drive | 4 | 1,941 | −0.225 / −0.279 | −0.312 | 0 |
| S3.ASIA opening drive | 4 | 1,883 | −0.285 / −0.329 | −0.378 | 0 |
| S4 band fade | 4 | 7,109 | −0.328 / −0.415 | −0.335 | 0 |
| S5 taker-flow proxy | 8 | 4,866 | −0.391 / −0.416 | −0.304 | 0 |

Longs and shorts are alike (median −0.32 and −0.30 R). The costs decide: over the 36
configurations the gross PnL sums to −30,000 USDT while fees take 97,000 and spread plus
slippage 71,000 (250 USDT per trade, 1.5 to 30 thousand trades per configuration). The least
bad configuration, the US opening drive short with a 1.5 R target (2,090 trades), earns
306 USDT gross and pays 359 USDT of fees and 238 USDT of friction: net −292 USDT, −0.14 R per
trade, profit factor 0.83. The opening drives are the only setups that beat their random
controls (by about 0.1 R), which says that the timing has some content at the session open,
not enough to pay a 14 bp round trip on a 0.4 % stop. The gates fail in bulk: G2 (a losing
year) 144 times, G8 (doubled spreads) on all 36.

### One-minute configurations: 0 of 4 pass

| Configuration | Trades | Win rate | Gross | Fees | Spread + slippage | Net | Mean R (baseline / adverse) | PF | Control median |
|---|---:|---:|---:|---:|---:|---:|---|---:|---:|
| `S5\|1m\|long\|S1.T1.5` | 163,560 | 9 % | −1,890 | 40,064 | 45,799 | −87,776 | −0.621 / −0.630 | 0.03 | −0.574 |
| `S5\|1m\|short\|S1.T1.5` | 180,049 | 11 % | −2,115 | 44,187 | 50,377 | −96,659 | −0.608 / −0.621 | 0.04 | −0.564 |
| `S6\|1m\|long\|S1.X` | 25,725 | 14 % | +430 | 6,300 | 8,820 | −14,693 | −0.616 / −0.688 | 0.14 | −0.635 |
| `S6\|1m\|short\|S1.X` | 25,755 | 13 % | −39 | 6,316 | 8,842 | −15,195 | −0.632 / −0.700 | 0.11 | −0.628 |

Micro-scalping on candles fails by arithmetic: a 1 × ATR14(1m) stop is 0.06 % of the price
while a market entry and a market exit cost about 24 bp with the stop leg's spread and
slippage, so a stop loses −0.6 R net and even the median winner does not cover the round trip.
The gross PnL is flat (the taker-flow proxy and the three-down-close reversal have no
directional content), and the controls land on the same −0.6 R. Nothing here can be rescued by
a parameter; only a maker-only execution with a visible order book would change the cost side,
and that is exactly what candles cannot simulate.

## Validation

Not run: the pre-registered selection (`select first` over both training screens, at most
three per cadence) found no configuration passing the training gates, and the stop rule ends
the cycle there. 2025 was not read by the scalping screen; 2026 remains untouched since the
swing holdout (one use).

## Closing the mandate of 2026-10-12

Three branches, three negative results, each under a rule fixed before the result:

| Branch | Period tested | Result |
|---|---|---|
| Swing candidate `B30_2s × S3.T5.H14d` (canonical) | 2026 holdout, one claim | PF 1.01 / 0.97, closed |
| Candidate selection v2, 436 configurations | 2023–24 screen, 2025 once | 8 training passes, all 4h long trend; all four selected variants lose in 2025 |
| Scalping and micro-scalping, 40 configurations | 2023–24 screen | 0 passes, costs dominate |

What the three results have in common: whenever a setup looked good in 2023–2024, a
regime-matched random entry looked as good, and the following period removed the surplus. On
ten major pairs with candle-based execution, the project has not found a timing edge that
survives costs and a change of regime, at any horizon from one minute to fourteen days.

What this does not say: that no edge exists in execution (order-book, maker fills, funding
capture), in portfolio construction (cross-sectional momentum, volatility targeting across
assets), or in horizons longer than two weeks; those were out of the mandate's scope and need
different data or a different engine.

## Budgets

The budget study (1 % of equity risked per trade, open notional ≤ 2 × equity, exchange
minimums) says something independent of the verdict: **the sizing rule itself does not fit
scalping.** A 1 % risk on a 0.2–0.4 % stop asks for 2.5 to 5 times the equity in notional per
position; under a 2 × leverage cap the first position fills the cap and the rest is skipped.
Across the 36 five-minute configurations the median tradable share is 28 % at 500 USDT and
still 43 % at 100,000 USDT, with the leverage cap the reason in both cases, so the result does
not improve with the account size. A scalper on these pairs would have to risk 0.2 % or less
per trade, or run one pair, or accept more leverage; none of that changes the sign of the
edge, which is set by the costs above. No pair is untradable at 500 USDT on its own (the
minimum notionals of 5–50 USDT are met), the constraint is the aggregate cap.

At 1 minute the same arithmetic is extreme: with 0.06 % stops the 1 % risk asks for 16 times
the equity per position; 3 % of the trades are tradable at 500 USDT and 6 % at 100,000 USDT.

## Limits

- Candle fills, no order book, no queue, no intra-candle path; the rules above are conservative
  for limit entries and market exits, but a real scalper's edge or loss lives inside the candle.
- The fee basis is the Fake/local policy (maker 2 bp, taker 5 bp); exchange tiers differ.
- The taker-buy share is a coarse proxy of order flow, not the trade tape the micro-scalping
  contract reads.
- The ten pairs were chosen in 2026; 2025 has been used by the earlier cycles as a validation
  period; 2026 stays untouched (one use by the swing holdout).
