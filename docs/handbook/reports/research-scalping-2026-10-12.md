# Scalping and micro-scalping screen on 1-minute history (2026-10-12)

## Result

SCALP_RESULT_PLACEHOLDER

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

SCALP_TRAINING_PLACEHOLDER

## Validation

SCALP_VALIDATION_PLACEHOLDER

## Budgets

SCALP_BUDGETS_PLACEHOLDER

## Limits

- Candle fills, no order book, no queue, no intra-candle path; the rules above are conservative
  for limit entries and market exits, but a real scalper's edge or loss lives inside the candle.
- The fee basis is the Fake/local policy (maker 2 bp, taker 5 bp); exchange tiers differ.
- The taker-buy share is a coarse proxy of order flow, not the trade tape the micro-scalping
  contract reads.
- The ten pairs were chosen in 2026; 2025 has been used by the earlier cycles as a validation
  period; 2026 stays untouched (one use by the swing holdout).
