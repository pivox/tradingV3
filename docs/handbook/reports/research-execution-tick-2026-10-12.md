# Execution primitives and tick-data scalping screen (2026-10-12)

## Result

**No candidate.** Five pre-registered protocols on fill-capable data, all frozen before their
results, all negative under their own rules:

| Protocol | Data | Configurations | Training passes | Validation | Outcome |
|---|---|---:|---:|---|---|
| A — passive-order mark-outs | OKX + Hyperliquid captures, 24 h each | — | — | — | no unconditional maker edge; a passive fill costs 3.1–3.5 bp vs 5 bp taker |
| r1 — tick signals, 1–5 min holds | captures, first / last 12 h | 368 | 0 | not run | `no_eligible_candidate` |
| r1 — same | Binance tape, 10 pairs, March + July 2025 / November | 304 | 0 | not run | `no_eligible_candidate` |
| r2 — same signals, 15–60 min holds | Binance tape | 152 | 0 | not run | `no_eligible_candidate` |
| r3 — reversion families, 4 windows | Binance tape, + May + September | 64 | 2 | 2 run once, 0 pass | `no_eligible_candidate` |
| r4 — two-sided band reversion | Binance tape, 4 windows | 4 | 0 (one fails only the trade minimum) | not run | `no_eligible_candidate` |
| r5 — that configuration, 9 windows | Binance tape, + February, April, June, August, October | 2 | 0 | not run | `no_eligible_candidate` |

What the day establishes, with numbers in the sections below:

- At holds of one to five minutes every family loses the fees: medians −6 to −11 bp per trade
  on four venues-days of order-book data and on 100 symbol-days of tape, signals and
  random-timing controls alike. No parameter changes that; the gross per trade is near zero.
- At holds of 15 to 60 minutes one rule has content: the reversion to the 15-minute EMA at
  two volatilities with confirming flow beats regime-matched random timing by about 20 bp per
  trade on both sides and in every phase it was run on. Its absolute expectancy is +26 bp on
  the four windows that found it and **+2.5 bp on nine windows** (260 trades, half the series
  negative): the sample, not the rule.
- Passive entries are the cheaper way in (about 2 bp), with one fill in two within 45 s;
  there is no maker edge for a participant without queue priority.

The November and December 2025 windows remain unread by r4 and r5; the December holdout has
never been read. The 2026 candle holdout is untouched since the swing campaign.

## Why

Fourth negative result in a row on candle data ([scalping screen](research-scalping-2026-10-12.md))
and the owner's correction of 2026-10-12: a negative screen is not a finished mission; the
standing objective is a scalping / micro-scalping candidate, and it has to be looked for on
data that can show maker fills. Two questions, one pre-registration
(`tradingV3-private/research/20261012T060000Z-execution-r1/PREREGISTRATION.md`, frozen
2026-10-12T06:18:05Z, sha256 `a8feedc4…`, before any PnL or mark-out was computed):

- **Part A.** Without any signal, does a passive limit order at the touch earn a positive net
  mark-out on OKX and Hyperliquid, and what does a passive fill cost (fill probability,
  adverse selection)?
- **Part B.** Do signal families built on the tape and the book (order-flow imbalance,
  flow exhaustion, book imbalance, micro breakouts, VWAP reversion, bursts) with maker entries
  and short exits survive costs on fill-capable data, in training and once in validation?

Data: the two frozen 24 h public captures (OKX `run14`, Hyperliquid `run15`; BTC and ETH;
read-only, events file re-hashed against the manifest) and, for a longer history, the Binance
USD-M aggregated-trade tape of ten pairs on three five-weekday windows of 2025 (March, July,
November). Fees: the project's policy, maker 2 bp and taker 5 bp. Tooling:
`scripts/research_exec` ([runbook](../runbooks/research-execution-study.md)).

## Part A — a passive fill has no free edge, but it is the cheaper entry

Every 5 s a buy and a sell rest at the touch for 45 s (live after 250 ms, cancelled if they
would cross). Under the pessimistic **through** rule (a print strictly beyond the price, no
queue priority assumed):

| Venue | Symbol | Fill rate (45 s) | Median delay | Half-spread | Net mark-out 60 s (LB90) | Round trip 60 s, taker exit | Mid drift 60 s |
|---|---|---:|---:|---:|---:|---:|---:|
| OKX | BTCUSDT | 45 % | 8.5 s | 0.006 bp | −3.10 bp (−3.17) | −8.1 bp | −0.17 bp |
| OKX | ETHUSDT | 58 % | 7.6 s | 0.019 bp | −3.05 bp (−3.11) | −8.1 bp | −0.25 bp |
| Hyperliquid | BTCUSDT | 52 % | 8.5 s | 0.065 bp | −3.36 bp (−3.44) | −8.4 bp | −0.14 bp |
| Hyperliquid | ETHUSDT | 55 % | 9.7 s | 0.193 bp | −3.49 bp (−3.58) | −8.7 bp | −0.16 bp |

Net mark-out = mark-out to the mid 60 s after the fill minus the 2 bp maker fee. The verdict
rule (both symbols positive with a positive lower bound, at 60 s and at one other horizon) is
**not met on either venue**: there is no unconditional maker edge. Sensitivities (latency 0 or
1,000 ms, TTL 300 s, every session, each side) move the numbers by at most 0.1 bp. With the
optimistic **touch** rule (front of the queue, fills on a print at the price) the fill rate
rises to 89–93 % and the net mark-out to −2.09 … −2.18 bp: at the front of the queue a fill is
almost free of adverse selection, at the back it costs 1.1–1.5 bp.

What part A gives part B: with a one-tick spread on all four series the spread capture is
nothing (0.006–0.19 bp), so a passive entry costs **2 bp of fee plus 1.1–1.5 bp of adverse
selection ≈ 3.1–3.5 bp**, against 5.0–5.2 bp for a market entry, with about one fill in two
within 45 s. Passive entries are the cheaper way in, by about 2 bp, and that is the only
execution edge these books offer to a participant without queue priority.

## Part B — tick-data scalping screen

Method (frozen before any result): 1-second bars on the exchange clock; features `r_W`
(mid return), `σ_W` (realized volatility), `FI_W` (signed taker volume share), `A_W`
(activity vs the trailing hour), `QI_5` (book imbalance, captures only), the session VWAP
deviation, an EMA band and a print-count burst flag. Six families, 23 long rules mirrored for
the shorts: S1 flow momentum, S2 flow exhaustion fade, S3 book imbalance, S4 micro breakout,
S5 VWAP / band reversion, S6 bursts (continuation and fade). Entries are maker-only (E1 at the
touch, E2 one tick inside when the spread allows), live after 250 ms, cancelled if they would
cross, filled under the through rule, 30 s TTL, one position per series. Exits: X1 taker at
60 s, X2 taker at 300 s, X3 stop 1 σ_60 / passive target 1.5 σ_60 / 300 s, X4 passive exit at
60 s then taker at 90 s. 368 configurations on the captures (304 on the Binance tape, which
has no book). Controls: random decision seconds matched to each configuration's trades per
hour of the day, same policy (20 seeds on the captures, 10 on the tape). Training gates G1–G8
(trades, mean net ≥ +1 bp with a positive block-bootstrap lower bound, PF ≥ 1.15, above the
control's 95th percentile and 2 bp above its median, 75 % of series positive, positive
without the best hour / day, positive with fees × 1.5, fill rate ≥ 20 %), at most three
survivors per family, one validation pass (last 12 h of each capture; November 2025 on the
tape).

### Captures (OKX + Hyperliquid, first 12 h training): 0 of 368 pass

| Family | Configurations | Median trades | Median mean net bp | Best configuration (mean net, trades) | Control median at the best |
|---|---:|---:|---:|---|---:|
| S1 flow momentum | 96 | 160 | −7.8 | `S1-W60-T0.5\|short\|E1\|X2` −6.2 bp (n = 236) | −6.1 |
| S2 flow exhaustion fade | 64 | 63 | −7.7 | `S2-W300-K2.5\|short\|E1\|X2` +0.8 bp (n = 16, LB −2.2) | −8.6 |
| S3 book imbalance | 64 | 976 | −7.9 | `S3-Q0.3-F\|short\|E2\|X2` −5.8 bp (n = 1,090) | −6.2 |
| S4 micro breakout | 64 | 10 | −9.5 | `S4-W60-K3\|long\|E1\|X2` +6.7 bp (n = 2) | +2.7 |
| S5 VWAP / band reversion | 48 | 31 | −8.4 | `S5-K3\|short\|E1\|X3` −6.4 bp (n = 29) | −8.4 |
| S6 bursts | 32 | 17 | −10.3 | `S6-C\|long\|E1\|X4` −3.4 bp (n = 25) | −6.7 |

The picture is the one part A predicted: with round trips of 4–7 bp and holds of 1 to
5 minutes, the gross per trade sits near zero and the net at −6 to −10 bp, for the signals
and for their random-timing controls alike. The dense families (S1, S3) trade hundreds of
times and lose the fees; the rare ones (S4, S6) have too few trades in 12 hours for any
statement. No configuration passes G2 (348 fail), G3 (350) or G4 (342); 185 fail the trade
minimum. Per the stop rule no validation was run on the captures
(`part-b/captures/verdict.json`: `no_eligible_candidate`).

### Binance tape (ten pairs, March + July 2025 training, November validation): 0 of 304 pass

Same r1 protocol on the twenty training series (ten pairs × two five-weekday windows,
85 million prints, quotes proxied from the prints, no book family), 817 s:

| Family | Configurations | Median trades | Median mean net bp | Best configuration (mean net, trades, LB90) | Control median at the best |
|---|---:|---:|---:|---|---:|
| S1 flow momentum | 96 | 4,047 | −7.5 | `S1-W300-T0.7\|long\|E1\|X2` +5.7 bp (n = 21, LB −4.7) | −4.9 |
| S2 flow exhaustion fade | 64 | 1,058 | −7.4 | `S2-W300-K2.5\|long\|E1\|X2` −3.4 bp (n = 139) | −6.2 |
| S4 micro breakout | 64 | 584 | −7.9 | `S4-W300-K3\|long\|E2\|X4` −3.0 bp (n = 95) | −7.2 |
| S5 VWAP / band reversion | 48 | 14,837 | −7.4 | `S5-B\|long\|E1\|X2` +27.1 bp (n = 6, LB +12.3) | −13.5 |
| S6 bursts | 32 | 220 | −7.3 | `S6-F\|long\|E2\|X3` −0.8 bp (n = 159) | −7.2 |

Fifteen weekdays of 2025 on ten pairs say the same as one day on OKX and Hyperliquid: at
1–5 minute holds every family sits at −7 to −8 bp per trade, the fees, with the random-timing
controls at the same level. The only positive rows are six trades of the band reversion
(`S5-B`, the mid two volatilities under its 5-minute EMA with buying flow): +27 bp each with a
5-minute hold, the same six signals that r2 holds for an hour below. Gates: 302 of 304 fail
G2, 267 fail G4 (no signal beats its control by 2 bp), 108 fail the trade minimum. No
validation was run (`part-b/binance/verdict.json`: `no_eligible_candidate`).

### r2 — intraday holds (15–60 min) with the same tick signals: 0 of 152 pass

Second pre-registration of the day (`PREREGISTRATION-r2.md`, frozen 06:54:38Z, sha256
`fb747d61…`, before any Binance result): the r1 rules without the book family, entry at the
touch, exits Y1 taker at 15 min, Y2 taker at 60 min, Y3 stop 1.5 σ_300 / passive target
3 σ_300 / 60 min, Y4 passive exit at 15 min; gates G1 ≥ 200 trades, G2 mean ≥ +2 bp with a
positive lower bound, G4 ≥ 3 bp above the control median; validation November once, then a
**December 2025 holdout** (2025-12-08..12, acquired today, read once for validated
configurations only). Training on the twenty March + July series (269 s):

| Family | Configurations | Median trades | Median mean net bp | Best configuration (mean net, trades, LB90) | Control median at the best |
|---|---:|---:|---:|---|---:|
| S1 flow momentum | 48 | 1,668 | −7.7 | `S1-W300-T0.7\|long\|E1\|Y3` +20.4 bp (n = 21, LB −2.7) | +9.7 |
| S2 flow exhaustion fade | 32 | 568 | −6.5 | `S2-W60-K2.5\|long\|E1\|Y2` +2.0 bp (n = 1,008, LB −1.7, PF 1.06) | −4.4 |
| S4 micro breakout | 32 | 460 | −11.1 | `S4-W60-K2\|long\|E1\|Y2` −4.8 bp (n = 1,222) | −4.0 |
| S5 VWAP / band reversion | 24 | 1,738 | −7.4 | `S5-B\|long\|E1\|Y2` +133.9 bp (n = 6, LB +67.3) | −34.3 |
| S6 bursts | 16 | 190 | −7.6 | `S6-F\|long\|E1\|Y2` +10.1 bp (n = 118, LB −3.7) | +3.0 |

Longer holds change the arithmetic but not the verdict. The dense families still lose the
fees (medians −6 to −11 bp). Three leads carry positive means and fail the pre-registered
gates for reasons that are exactly the ones the gates exist for:

- the band reversion `S5-B` (mid below the 300-s EMA by two volatilities with buying flow,
  held 60 minutes) earns +134 bp on 6 long trades and +45 bp on 19 short trades in 100
  symbol-days — a signal that fires once every two weeks per pair cannot be told from luck,
  and its short side sits inside its control's 95th percentile;
- the 60-second sell-exhaustion fade held 60 minutes (`S2-W60-K2.5|long|E1|Y2`) earns
  +2.0 bp on 1,008 trades (PF 1.06) against a control median of −4.4 bp: real content, far
  too small to pay a fee tier change (fees × 1.5 turns it negative) and positive on half of
  the series only;
- the burst fade `S6-F` held 60 minutes earns +10 bp on 118 trades with a control median at
  +3 bp and a negative lower bound.

Nothing was sent to validation; the November window and the December holdout stay unread by
r2 (`part-b/binance-r2/verdict.json`: `no_eligible_candidate`, validation not run).

### r3 — focused reversion families on four training windows: one training pass, no validation pass

Third pre-registration (`PREREGISTRATION-r3.md`, frozen 07:36:02Z, sha256 `2dd51c44…`, after
the r2 training numbers and before any r3 computation): the band reversion generalised
(`S7`: mid below its EMA of 300 or 900 s by 1.5 or 2 volatilities, with buying flow) and the
exhaustion fade with larger moves (`S2`, k 2.5 / 3.5), both sides, entry at the touch, exits
Z1 taker at 60 min, Z2 passive at 30 min, Z3 passive at 60 min, Z4 2–4 σ bracket with a
120 min cap; 64 configurations; training on **four** windows (March, May, July, September
2025, forty series, the two new windows acquired and checksum-verified before the freeze was
read by anything), validation November, holdout December.

| Family | Configurations | Median trades | Median mean net bp | Best configuration (mean net, trades, LB90) | Control median at the best | Passes |
|---|---:|---:|---:|---|---:|---:|
| S2 exhaustion fade | 32 | 312 | −6.1 | `S2-W300-K3.5\|long\|E1\|Z2` +1.3 bp (n = 269, LB −4.3) | −10.0 | 0 |
| S7 band reversion | 32 | 173 | +3.2 | `S7-W900-K2.0\|short\|E1\|Z3` +30.7 bp (n = 66, LB +14.6) | −16.1 | 2 |

Two configurations pass all eight training gates, the first of the day: the long reversion
to the 15-minute EMA at 1.5 volatilities, held one hour, with a passive exit
(`S7-W900-K1.5|long|E1|Z3`: 361 trades, +12.7 bp, lower bound +5.6, PF 1.43, 28 of 40 series
positive, control median −10.7 bp, still +38 bp per trade without the best day and positive at
fees × 1.5) and the same with a taker exit (`…|Z1`: +11.8 bp). Their short mirror is negative
in training (−6.4 bp on 408 trades) against a control at −17.4 bp; the stricter `K2.0` versions
earn +19 to +31 bp per trade but on 44–66 trades.

Validation (November 2025, once):

| Configuration | Training mean / LB90 | Validation trades | Mean net bp | Gross bp | PF | Series positive | Control median | Passes |
|---|---|---:|---:|---:|---:|---:|---:|---|
| `S7-W900-K1.5\|long\|E1\|Z1` | +11.8 / +4.8 | 94 | −5.3 | +1.7 | 0.87 | 3 of 10 | −24.6 | no |
| `S7-W900-K1.5\|long\|E1\|Z3` | +12.7 / +5.6 | 94 | −1.8 | +2.4 | 0.95 | 3 of 10 | −22.4 | no |

Outcome `no_eligible_candidate`; the December holdout stays unread by r3. The reading is
precise: in a falling week the long entries break even before fees while random long entries
at the same hours lose 22–25 bp — the rule beats random timing by about 20 bp per trade in
training and in validation, on both sides, and its absolute result follows the market's
hourly drift. That is the first signal of the day with content; it is not a candidate,
because a one-sided one-hour hold is a bet on the hour's direction.

### r4 — the same rule two-sided: the 2 σ version passes seven gates of eight

Fourth pre-registration (`PREREGISTRATION-r4.md`, frozen 08:00:42Z, sha256 `f19f152e…`,
disclosing that the November numbers of the long 1.5 σ configurations were known): the long and
the short streams of `S7-W900` run independently on each series and their trades are pooled,
so that the hour's market drift largely cancels; thresholds 1.5 σ and 2 σ, exits Z1 and Z3,
four configurations, same gates, same forty training series:

| Configuration | Trades | Mean net bp | LB90 | Median | PF | Win | Control median / p95 | Series positive | Without best day | Fees × 1.5 | Gates failed |
|---|---:|---:|---:|---:|---:|---:|---|---:|---:|---:|---|
| `S7-W900-K1.5\|both\|E1\|Z1` | 769 | +0.8 | −3.5 | −3.2 | 1.03 | 48 % | −15.2 / −12.4 | 48 % | −258 | −2,052 | G2 G3 G5 G6 G7 |
| `S7-W900-K1.5\|both\|E1\|Z3` | 769 | +2.6 | −1.8 | −1.6 | 1.08 | 49 % | −13.3 / −10.7 | 48 % | +1,055 | +350 | G2 G3 G5 |
| `S7-W900-K2.0\|both\|E1\|Z1` | 110 | +22.9 | +10.7 | +16.1 | 2.05 | 58 % | −16.8 / −9.5 | 70 % | +1,957 | +2,137 | G1 G5 |
| `S7-W900-K2.0\|both\|E1\|Z3` | 110 | +25.9 | +13.7 | +19.7 | 2.24 | 59 % | −15.7 / −7.8 | 76 % | +2,277 | +2,619 | G1 |

The 1.5 σ pooled version is what its halves predicted: +2.6 bp, half the series positive,
nothing to trade. The 2 σ pooled version with the passive exit has every property the gates
ask for — mean, lower bound, profit factor, 31 of 40 series positive (the forty series are
ten pairs × four windows), robust to the best day and to a fee tier change, 40 bp above its
regime-matched control — except the sample: 110 trades, one signal every two symbol-days,
against a minimum of 200 set before anything was run. Nothing was selected; November and
December stay unread by r4.

### r5 — the 2 σ two-sided configuration on nine training windows

Fifth pre-registration (`PREREGISTRATION-r5.md`, frozen 08:05:10Z, sha256 `4f633afb…`, before
the five new windows were read by anything): the single configuration that r4 had left short of
the trade minimum, `S7-W900-K2.0|both|E1|Z3` (primary) and its taker-exit twin `…|Z1`
(secondary), on **nine** five-weekday training windows of 2025 (February, March, April, May,
June, July, August, September, October; ninety series, 366 million prints, every archive
checksum-verified), validation November with a trade minimum of 50, holdout December. The
run needed a series-by-series driver (one series in memory per worker), which reproduces the
pooled driver exactly on synthetic data.

| Configuration | Trades | Mean net bp | LB90 | Median | PF | Win | Control median / p95 | Series positive | Without best day | Fees × 1.5 | Gates failed |
|---|---:|---:|---:|---:|---:|---:|---|---:|---:|---:|---|
| `S7-W900-K2.0\|both\|E1\|Z3` | 260 | +2.5 | −5.1 | +6.4 | 1.08 | 56 % | −17.4 / −9.0 | 55 % | +64 | +93 | G2 G3 G5 |
| `S7-W900-K2.0\|both\|E1\|Z1` | 260 | +0.1 | −7.2 | +4.2 | 1.00 | 54 % | −19.2 / −11.2 | 53 % | −534 | −879 | G2 G3 G5 G6 G7 |

Per window, mean net bp of the primary (trades): March +66 (25), April +48 (20), May +32 (20),
September +9 (42), July +7 (23), October −8 (13), June −20 (45), August −28 (28), February
−31 (44). The four windows r3 and r4 had seen are the four best of the nine; the five windows
added afterwards average about −20 bp. With 260 trades the configuration keeps a positive
median and still beats random timing by 20 bp per trade, and it has no expectancy a trader
could use: +2.5 bp on average, half the series negative, a lower bound at −5 bp. Nothing was
selected; November and December stay unread by r5.

This closes the band-reversion lead: what looked like +26 bp on four windows was the sample,
not the rule.

## Budgets

Every screen restates its best three configurations (by lower bound) for budgets of 500 to
100,000 USDT at 1× and 2× (`part-b/<source>/budgets.json`): all are tradable at 500 USDT on
every venue (OKX contracts of 0.01 BTC ≈ 870 USDT need the 2× leverage at 500 USDT; ETH
contracts, Hyperliquid's 10 USDT minimum and Binance steps are met at 1×), and none projects
a net worth sizing: the r5 primary at +2.5 bp and 1.3 trades per day per budget of 500 USDT at
2× makes about +0.03 USDT per day; the r3 long reversion at its validation numbers loses
0.2–1 USDT per day at 500 USDT and 69–200 USDT per day at 100,000 USDT. Fees per round trip
are 0.35–0.70 USDT at 500 USDT (1× / 2×) for taker exits and 0.20–0.40 USDT for passive ones.

## Limits

- One day per venue on the captures (2026-10-02/03), two symbols; training and validation are
  the two halves of the same day, so validation checks stability within a day, not across
  regimes. The Binance tape adds fifteen weekdays of 2025 on ten pairs but has no book: its
  quotes are proxies from the prints and the book-imbalance family is absent there.
- Fills follow the tape (through rule), not a queue model; the touch rule bounds the other
  side. Market exits take the touch after 250 ms with no depth consumption.
- Fees are the project's policy (2 / 5 bp); exchange tiers differ, hence gate G7 at fees × 1.5.
- No funding (holds under five minutes in r1; up to two hours in r3–r5, where funding at
  ±0.01 % per 8 h would move the results by less than 0.3 bp per trade). No position sizing
  beyond the budget table.
- Five pre-registrations in one day form a lineage (r3 → r4 → r5 on one family); each was
  frozen before its own result, the thresholds never moved, and r5's nine windows are the
  answer to the multiplicity that lineage creates.
- The 2026 candle holdout stays untouched (one use by the swing campaign); nothing of 2026 is
  read from the Binance archives.
