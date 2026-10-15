# Leverage > 2x — E0a census (run once, 2026-10-15)

**Trigger computed by the frozen code (`census.trigger`, `e0a-trigger.json`):**

- **L_eng (primary definition): E1 runs nothing.** ]1,2] is triggered (8,093 distinct signals), but no
  studied band is: ]2,3] has 237 distinct signals (237 × 0.3 = 71.1 < 400), ]3,5] has 1 and ]5,10] has 0.
- **L_stop (secondary definition): E1 runs ]1,2] and ]2,3].** ]1,2] has 11,474 distinct signals (3,442.2 ≥ 400)
  and ]2,3] has 1,380 (414.0 ≥ 400, a margin of 14 expected fills); ]3,5] (210 → 63.0) and ]5,10] (6 → 1.8) are
  not triggered.

E0a is a pre-fill census: every signal second is classified from its decision-time inputs only. No order was
simulated and no fill, exit, PnL or later print was read. **No outcome of this study has been read.**

## Provenance

| Item | Value |
|---|---|
| Protocol | [`PREREGISTRATION-leverage-gt2.md`](PREREGISTRATION-leverage-gt2.md), FROZEN 2026-10-15T10:07:48Z, body sha256 `10eaf6cb36a37ce5057f09324acee80696abda38eed6fce17f31fe7760f37d97` (commit `79747bf2`) |
| Code | digest `6e20c4ebb7f70dba257a57ebd6f2f1753c609368b8569b78cb1ba9a50e17ddeb`, the digest on the FROZEN line, checked at claim and at consumption |
| Owner decisions | D1 = `distinct_pair_day`, D2 = `drop` (30 configurations, `R1|both` removed), D3 = 1, D4 = `not_charged` |
| Claim | `E0a-claim.json`, claimed 2026-10-15T10:13:16Z (sha256 `a3178a340fed8ed2…`) |
| Run | `cli census --workers 2` under `nice -n 15`; consumed 10:13:21Z, complete 10:16:43Z (202.3 s); memory guard (floor 4,000 MB) never tripped (MemAvailable 7.9 GB during the run, 8.4 GB after); **no resumption used** (the D3 budget of 1 is untouched) |
| Inputs | instruments `cd940cff…`, `manifest-aggtrades.json` `e0f1a68f…`, `manifest-klines.json` `a2876b08…`, equal to the frozen values; 76 pair-week series (19 pairs × 4 windows, 380 pair-days), no missing day |
| Outputs | `tradingV3-private/research/20261015T090000Z-leverage-gt2/e0a-census.json` (sha256 `c00673aa90cdc463a986834a5111464d6a76c31b1c8260266cab998591fa1b9d`), `e0a-trigger.json` (sha256 `fe7e7fa4c64fec10e376287c26ddbabae3d0da5505f997b99adbaefeb92891dc`), both recorded in `E0a-consumed.json` |

## Pooled census by band

Distinct = each (series, side, decision second) once (the D1 count, used by the trigger). Nominal = one count per
configuration copy (published, not used by the trigger). Nominal / distinct = 2.01.

| Band | L_eng distinct | L_eng nominal | L_stop distinct | L_stop nominal |
|---|---:|---:|---:|---:|
| ]0,1] (counted, not studied) | 26,298 (75.9 %) | 52,913 | 21,559 (62.3 %) | 43,337 |
| ]1,2] (reference) | 8,093 (23.4 %) | 16,296 | 11,474 (33.1 %) | 23,028 |
| ]2,3] | 237 (0.68 %) | 477 | 1,380 (3.99 %) | 2,880 |
| ]3,5] | 1 | 2 | 210 (0.61 %) | 431 |
| ]5,10] | 0 | 0 | 6 | 12 |
| > 10 (counted, not studied) | 0 | 0 | 0 | 0 |
| **All determinable** | **34,629** | **69,688** | **34,629** | **69,688** |
| of which > 2x | 238 (0.69 %) | 479 | 1,596 (4.61 %) | 3,323 |

- **Floor strata (two-tick stop floor):** 0 in every band, under both definitions, distinct and nominal. On these
  pairs 1.5 × ATR5 always exceeds two ticks, so the floor splits and S9 have nothing to remove.
- **Indeterminables:** 0 for every reason (`atr_missing`, `quote_stale`, `spread_missing`, `nonpositive_distance`;
  `no_quote` is an E1 reason), distinct and nominal.
- **BTC excluded:** BTCUSDT is removed by name before any computation (`P2_SYMBOLS`), so no BTC signal was counted:
  its 4 pair-week series (20 pair-days) were neither planned nor read, and the census has 0 BTC series and 0 BTC
  rows.
- **Upper bound of L_eng:** the largest L_eng band reached is ]3,5] (one distinct signal, ETH, w2406), consistent
  with the bound L_eng < 7.14 of §3.

## By pair (nominal, the frozen `by_pair` output)

| Pair | L_eng ]0,1] | ]1,2] | ]2,3] | ]3,5] | L_stop ]0,1] | ]1,2] | ]2,3] | ]3,5] | ]5,10] |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| ETHUSDT | 1,486 | 1,473 | 343 | 2 | 1,069 | 1,430 | 518 | 275 | 12 |
| BNBUSDT | 1,713 | 1,819 | 124 | 0 | 1,169 | 2,072 | 325 | 90 | 0 |
| LTCUSDT | 1,563 | 1,683 | 10 | 0 | 1,057 | 1,377 | 762 | 60 | 0 |
| XRPUSDT | 2,250 | 1,751 | 0 | 0 | 1,625 | 1,755 | 617 | 4 | 0 |
| ADAUSDT | 2,829 | 954 | 0 | 0 | 1,979 | 1,663 | 139 | 2 | 0 |
| ATOMUSDT | 2,786 | 1,298 | 0 | 0 | 1,772 | 2,141 | 171 | 0 | 0 |
| DOGEUSDT | 2,806 | 1,012 | 0 | 0 | 2,436 | 1,255 | 127 | 0 | 0 |
| SOLUSDT | 3,223 | 572 | 0 | 0 | 2,779 | 913 | 103 | 0 | 0 |
| FILUSDT | 2,942 | 903 | 0 | 0 | 2,469 | 1,332 | 44 | 0 | 0 |
| LINKUSDT | 2,777 | 988 | 0 | 0 | 2,091 | 1,655 | 19 | 0 | 0 |
| AVAXUSDT | 2,908 | 998 | 0 | 0 | 2,235 | 1,655 | 16 | 0 | 0 |
| APTUSDT | 2,859 | 808 | 0 | 0 | 2,477 | 1,176 | 14 | 0 | 0 |
| NEARUSDT | 2,804 | 217 | 0 | 0 | 2,496 | 511 | 14 | 0 | 0 |
| ARBUSDT | 2,375 | 905 | 0 | 0 | 1,961 | 1,310 | 9 | 0 | 0 |
| OPUSDT | 2,479 | 525 | 0 | 0 | 2,103 | 899 | 2 | 0 | 0 |
| SUIUSDT | 3,304 | 108 | 0 | 0 | 2,880 | 532 | 0 | 0 | 0 |
| TIAUSDT | 3,869 | 155 | 0 | 0 | 3,550 | 474 | 0 | 0 | 0 |
| SEIUSDT | 3,757 | 61 | 0 | 0 | 3,326 | 492 | 0 | 0 | 0 |
| INJUSDT | 4,183 | 66 | 0 | 0 | 3,863 | 386 | 0 | 0 | 0 |

No pair has a signal in > 10, and no pair other than ETH has one in L_stop ]5,10]. Descriptive reading (no gate at
E0a): under L_eng the > 2x signals sit on three majors only (ETH 72 % of the nominal ]2,3], BNB 26 %, LTC 2 %);
under L_stop ]2,3] spans 15 of 19 pairs (largest share LTC 26.5 %, then XRP 21.4 %, ETH 18.0 %, BNB 11.3 %).

## By window (nominal, the frozen `by_window` output)

| Window | Signals | L_eng ]0,1] | ]1,2] | ]2,3] | ]3,5] | L_stop ]0,1] | ]1,2] | ]2,3] | ]3,5] | ]5,10] |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| w2404 | 12,375 | 11,855 | 520 | 0 | 0 | 11,269 | 1,106 | 0 | 0 | 0 |
| w2405 | 21,109 | 14,483 | 6,460 | 166 | 0 | 10,831 | 8,794 | 1,312 | 172 | 0 |
| w2406 | 19,838 | 13,143 | 6,382 | 311 | 2 | 10,196 | 7,993 | 1,378 | 259 | 12 |
| w2408 | 16,366 | 13,432 | 2,934 | 0 | 0 | 11,041 | 5,135 | 190 | 0 | 0 |

Descriptive: w2404 has no > 2x signal under either definition; under L_eng the > 2x signals are in w2405 and w2406
only.

## By configuration and side (nominal)

The two largest streams dominate: `P3-A3-K0|short` (20,732 signals) and its out-of-zone part `P3-A3-K0-Z0|short`
(20,171), then `P2-A3-K0|long` (8,079) and `P2-A3-K0-Z0|long` (7,916). Long signals: 23,290 nominal (L_eng ]2,3]
178, L_stop ]2,3] 1,067); short signals: 46,398 (L_eng ]2,3] 299, L_stop ]2,3] 1,813). `R1|long` and `R1|short`
each have 3,162 signals with identical band counts under both definitions. The per-configuration counts are in
`e0a-census.json` (`by_config`, `rows`).

## C2 tercile cut points (frozen for the analysis)

Computed over the 34,629 distinct determinable signals, outcome-free: ATR5 31.13 / 48.89 bp; tick 0.188 /
0.771 bp; `stale_900` 0.161 / 0.401.

## What the frozen rules imply next

- The E1 command recomputes the trigger from `e0a-census.json` and refuses to run if it differs from
  `e0a-trigger.json` (§7). Under this trigger it replays two arms, `stop:1-2` and `stop:2-3`, for the 30
  configurations; nothing is replayed under L_eng.
- The primary contrast (Δ ]2,3] − ]1,2] under L_eng) and S1, S2, S3, S7, S8 and S9 have no replayed arm, so §9
  makes them NOT_INFORMATIVE with p = 1 in the Holm family; S5 and S6 (L_stop ]3,5], ]5,10]) likewise. Only S4
  (Δ ]2,3] − ]1,2] under L_stop) can be tested, and only if both arms reach 400 distinct filled trades in E1; the
  ]2,3] arm passes the trigger by 14 expected fills at f_min = 0.3. The verdicts are written by the analysis stage,
  not here.
- E1 (`claim --stage E1`, then `replay`) was not run in this step.
