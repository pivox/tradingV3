# Tick-signal scalping on ten mid-cap perpetuals (2026-10-13)

## Result

<!-- RESULT -->

## Why

The programme of 2026-10-12 ([report](research-execution-tick-2026-10-12.md)) ended with no
candidate on the ten majors: at short holds every family lost the fees, and the one rule with
content at hourly holds (band reversion) had no absolute expectancy once nine windows were
seen. The owner's standing objective is a scalping / micro-scalping candidate; the owner's
"go" of 2026-10-13 continues it. The one hypothesis the majors could not test is the **fee
hurdle itself**: with ticks of 0.01–0.4 bp a passive fill captures nothing and an hourly move
of 10–25 bp is of the order of the 4–7 bp round trip. Mid-cap perpetuals have ticks of
0.15–5 bp and two to three times the volatility, so the same signals and the same fees meet a
different arithmetic. r6 asks whether that changes the verdict, under the programme's
mechanics, controls and gates, with a validation and a holdout the rules never saw.

Pre-registration `tradingV3-private/research/20261012T060000Z-execution-r1/PREREGISTRATION-r6.md`,
frozen 2026-10-13T02:18:55Z (sha256 `b7ddad02…`), before any mid-cap archive was read.

## Method

- **Universe (fixed before the data).** ARBUSDT, OPUSDT, APTUSDT, SUIUSDT, NEARUSDT, TIAUSDT,
  SEIUSDT, INJUSDT, ATOMUSDT, FILUSDT: Binance USD-M perpetuals outside the ten majors, listed
  before 2024, ticks 0.00001–0.001 USDT, minimum notional 5 USDT (instrument file from the
  exchange's current metadata, sha256 recorded).
- **Data.** Aggregated-trade tape, sha256-checked at download, ten five-weekday windows of
  2025: nine for training (February to October), November for one validation pass, December
  as a holdout in a separate directory read only for validated configurations. Quotes proxied
  from the prints; no book.
- **Rules and policies.** The programme's S1 flow momentum, S2 flow exhaustion fade, S4 micro
  breakout, S5 VWAP / band reversion, S6 bursts and S7 band reversion (EMA 300 / 900 s), both
  sides, plus the four S7 rules two-sided; entry at the touch (250 ms, 30 s TTL, through
  rule); exits X2 taker at 5 min, Y1 taker at 15 min, Y3 bracket 1.5 / 3 σ_300 with a 60 min
  cap, Z3 passive at 60 min then taker at 65 min; 200 configurations.
- **Controls and gates.** Regime-matched random timing, 10 seeds; G1 ≥ 300 trades, G2 mean
  net ≥ +2 bp with a positive block-bootstrap lower bound, G3 PF ≥ 1.15, G4 above the
  control's 95th percentile and ≥ 3 bp above its median, G5 ≥ 70 % of the ninety series
  positive, G6 positive without the best day, G7 positive with fees × 1.5, G8 fill rate
  ≥ 20 %; at most three per family to validation (≥ 100 trades, net > 0, PF ≥ 1.05, above the
  control median, ≥ 50 % of series positive); holdout with the same checks.

## Training

<!-- TRAINING -->

## Validation and holdout

<!-- VALIDATION -->

## Budgets

<!-- BUDGETS -->

## Limits

- Binance tape only: OKX and Hyperliquid books for these names are thinner, and a candidate
  found here needs a confirmation on those venues (captures or demo) before any contract work.
- Proxy quotes understate a spread wider than the last two prints; fills stay pessimistic.
- Fees are the project's policy (2 / 5 bp); the gate at fees × 1.5 covers a tier change.
- No funding, no position sizing beyond the budget table.
- 2026 is never read; the December 2025 mid-cap window is read at most once.
