# Tick-signal scalping on ten mid-cap perpetuals (2026-10-13)

## Result

**No candidate.** On ten mid-cap perpetuals (ticks of 0.15–5 bp, two to three times the
majors' volatility), 200 pre-registered configurations on nine training windows of 2025 give
0 passes; the dense families lose more than on the majors (medians −10 bp per trade, the wider
spreads paid on taker exits). One configuration, the two-sided reversion to the 15-minute EMA
with a passive exit at 60 minutes, earns +10 bp on 1,698 training trades and fails only the
series-consistency gate (62 % of series positive vs 70 %). Tested out of sample under a
further pre-registration (r7, no gate relaxed), it earns **+12.8 bp on 206 trades in
November, every pair positive**, then **−20.9 bp on 145 trades in December, one pair positive
and worse than random timing**. The holdout decides: no candidate.

Programme total since 2026-10-12: seven pre-registered protocols (A, r1 twice, r2–r7),
1,300 configurations, four venues-days of order-book data and 1,100 symbol-days of tape on
twenty pairs. Every signal with content at hourly holds was a reversion to a short EMA with
confirming flow; it beat random timing by 15–30 bp per trade in every training set and in two
validations, and lost in the one window that was never used for anything (December 2025,
majors in r5's extended training; mid-caps in r7's holdout). What survives is a description,
not a strategy: these books mean-revert over an hour after a 1.5–2 σ excursion more often
than not, and the hour's drift and the month's regime decide the sign of the result.

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

## Training: 0 of 200 pass; one configuration fails a single gate

Ninety series (ten pairs × nine windows, 96 million prints), 1,505 s with the series-by-series
driver:

| Family | Configurations | Median trades | Median mean net bp | Best configuration (mean net, trades, LB90) | Control median at the best |
|---|---:|---:|---:|---|---:|
| S1 flow momentum | 48 | 10,428 | −10.5 | `S1-W60-T0.5\|short\|E1\|Y3` −6.2 bp (n = 13,626) | −6.2 |
| S2 flow exhaustion fade | 32 | 3,543 | −9.9 | `S2-W300-K1.5\|short\|E1\|Y3` −6.1 bp (n = 3,151) | −6.7 |
| S4 micro breakout | 32 | 1,826 | −11.0 | `S4-W300-K3\|long\|E1\|Y3` −3.6 bp (n = 541) | −6.4 |
| S5 VWAP / band reversion | 24 | 8,824 | −9.4 | `S5-B\|long\|E1\|Z3` +27.9 bp (n = 55, LB +13.3) | −32.0 |
| S6 bursts | 16 | 948 | −10.0 | `S6-F\|long\|E1\|Z3` +7.3 bp (n = 837, LB +1.9) | −14.9 |
| S7 band reversion | 48 | 532 | −4.5 | `S7-W900-K1.5\|long\|E1\|Z3` +14.8 bp (n = 755, LB +9.5) | −17.3 |

By exit, the medians say where the fees go on these names: taker exits at 5 and 15 minutes
−10.9 and −10.7 bp, the bracket −6.6 bp, the passive exit −8.2 bp; every configuration with a
positive mean has the passive exit or the bracket. By side, long −9.8, short −10.0, two-sided
−4.5. The dense families lose more than on the majors (wider spreads paid on the way out),
the breakout and burst families are not rescued by the larger moves, and the VWAP and
band-reversion rules at 5-minute windows stay negative in bulk.

The 15-minute-EMA band reversion is the exception, as it was on the majors, and this time
with the sample size the majors never gave:

| Configuration | Trades | Fill | Mean net bp | LB90 | Median | PF | Win | Control median / p95 | Series positive | Without best day | Fees × 1.5 | Gates failed |
|---|---:|---:|---:|---:|---:|---:|---:|---|---:|---:|---:|---|
| `S7-W900-K1.5\|both\|E1\|Z3` | 1,698 | 72 % | +10.2 | +6.3 | +13.0 | 1.25 | 55 % | −14.5 / −10.8 | 62 % | +16,043 | +13,693 | G5 |
| `S7-W900-K1.5\|long\|E1\|Z3` | 755 | 72 % | +14.8 | +9.5 | +15.5 | 1.41 | 57 % | −17.3 / −13.3 | 62 % | +10,569 | +9,575 | G5 |
| `S7-W900-K1.5\|short\|E1\|Z3` | 943 | 72 % | +6.5 | +1.1 | +11.2 | 1.15 | 53 % | −13.5 / −4.7 | 54 % | +5,090 | +4,118 | G3 G5 |
| `S6-F\|long\|E1\|Z3` | 837 | 78 % | +7.3 | +1.9 | +13.9 | 1.17 | 55 % | −14.9 / −8.3 | 60 % | +5,449 | +4,235 | G5 |
| `S7-W900-K2.0\|both\|E1\|Z3` | 260 | 75 % | +11.8 | +1.8 | +23.4 | 1.28 | 58 % | −17.7 / −3.3 | 65 % | +2,603 | +2,484 | G1 G5 |

The two-sided 1.5 σ configuration with the passive exit has 1,698 trades over 450
symbol-days, a mean of +10 bp with a lower bound of +6 bp, a profit factor of 1.25, 25 bp of
advantage over regime-matched random timing, and keeps +16,000 bp of its +17,400 without its
best day and +13,700 at fees × 1.5. It fails G5: 56 of the 90 series (62 %) are positive, the
gate asks 63. Its long stream alone is stronger per trade (+14.8 bp on 755) and fails the same
gate at the same share. Under the pre-registered rule nothing was selected and the November
and December mid-cap windows stay unread by r6 (`part-b/binance-r6/`).

(Eight configurations appear twice in the grid: `S5-B` is by definition `S7-W300-K2.0`; the
duplicates are identical rows and change no count above.)

## Validation and holdout (r7): passes November, fails December

Seventh pre-registration (`PREREGISTRATION-r7.md`, frozen 2026-10-13T03:11:57Z, sha256
`c663001b…`, before the November and December mid-cap windows were read): no gate relaxed;
the two configurations that failed only G5 are tested on the two windows nothing had read,
with the validation and holdout checks of r6 (trades ≥ 100, net > 0, PF ≥ 1.05, above the
control median, ≥ 50 % of series positive).

| Configuration | Phase | Trades | Fill | Mean net bp | Median | PF | Win | LB90 | Control median / p95 | Pairs positive | Result |
|---|---|---:|---:|---:|---:|---:|---:|---:|---|---:|---|
| `S7-W900-K1.5\|both\|E1\|Z3` | training (r6, 9 windows) | 1,698 | 72 % | +10.2 | +13.0 | 1.25 | 55 % | +6.3 | −14.5 / −10.8 | 56 of 90 | one gate short |
| `S7-W900-K1.5\|both\|E1\|Z3` | **validation, November** | 206 | 75 % | **+12.8** | +18.6 | 1.33 | 56 % | +2.1 | −16.7 / −6.1 | **10 of 10** | passes |
| `S7-W900-K1.5\|both\|E1\|Z3` | **holdout, December** | 145 | 60 % | **−20.9** | −23.3 | 0.65 | 40 % | −35.5 | −15.8 / −2.5 | **1 of 10** | fails every check |
| `S7-W900-K1.5\|long\|E1\|Z3` | validation, November | 74 | 67 % | −21.3 | −40.3 | 0.61 | 41 % | −37.8 | −23.1 / −10.8 | 3 of 10 | fails; holdout not read |

Per pair in November the two-sided configuration is positive everywhere (ARB +533 bp on 22
trades, TIA +598 on 23, ATOM +548 on 10, FIL +400 on 8, APT +301 on 30, down to OP +3 on 32);
in December it is negative everywhere but SEI (OP −877 on 26, SUI −817 on 18, APT −678 on 27).
Gross per trade: +17.1 bp in November, −16.5 bp in December, with the same 4.3–4.4 bp of fees
and the same exit mix (nine passive exits in ten). In December the rule did worse than random
timing at the same hours (−20.9 against −15.8): the signal's content, positive in ten training
windows and in November, was negative in the twelfth.

Outcome `no_eligible_candidate` (`part-b/binance-r7/verdict-holdout.json`). The mid-cap
December window has now been read once, for this one configuration.

## Budgets

All ten names are tradable at 500 USDT at 1× (minimum notional 5 USDT). For the two-sided
configuration, at the training rate of 3.8 trades per day across the ten pairs and fees of
4.4 bp per round trip (0.22 USDT at 500 USDT 1×, 0.44 at 2×): the November numbers project
+2.4 USDT per day at 500 USDT 1× and +960 USDT per day at 100,000 USDT 2×; the December
numbers project −4 and −1,570 USDT per day for the same budgets. The sign of the month, not the
size of the account, is the whole question, and that is why there is no candidate.

## Limits

- Binance tape only: OKX and Hyperliquid books for these names are thinner, and a candidate
  found here needs a confirmation on those venues (captures or demo) before any contract work.
- Proxy quotes understate a spread wider than the last two prints; fills stay pessimistic.
- Fees are the project's policy (2 / 5 bp); the gate at fees × 1.5 covers a tier change.
- No funding, no position sizing beyond the budget table.
- 2026 is never read; the December 2025 mid-cap window was read once (r7, one configuration).
- Lineage: r6 chose nothing; r7 chose two configurations after the r6 training result, so the
  November pass is a selected configuration's pass and the December failure is the only
  untouched test; the pre-registrations say so.
