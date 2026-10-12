# Execution primitives and tick-data scalping screen (2026-10-12)

## Result

<!-- RESULT -->

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

<!-- PART-B-BINANCE -->

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

<!-- PART-B-R2 -->

## Budgets

<!-- BUDGETS -->

## Limits

- One day per venue on the captures (2026-10-02/03), two symbols; training and validation are
  the two halves of the same day, so validation checks stability within a day, not across
  regimes. The Binance tape adds fifteen weekdays of 2025 on ten pairs but has no book: its
  quotes are proxies from the prints and the book-imbalance family is absent there.
- Fills follow the tape (through rule), not a queue model; the touch rule bounds the other
  side. Market exits take the touch after 250 ms with no depth consumption.
- Fees are the project's policy (2 / 5 bp); exchange tiers differ, hence gate G7 at fees × 1.5.
- No funding (holds under five minutes). No position sizing beyond the budget table.
- The 2026 candle holdout stays untouched (one use by the swing campaign); nothing of 2026 is
  read from the Binance archives.
