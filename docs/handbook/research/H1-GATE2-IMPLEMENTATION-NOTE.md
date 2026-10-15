# H1 historical Gate 2 — implementation note (2026-10-15)

Written and committed **before any Gate 2 result was computed** and before the funding rates were downloaded. It
records how v1 §11–§15 (with A1, A2, A3) are executed. It changes no threshold, horizon, control, population or
criterion. Code: `python-orchestrator/scripts/research_listing/gate2.py`; tests: `tests/test_research_listing_gate2.py`.

Every output is labelled **"historical, not executable on X-Perps"**.

## 1. Population and data

- **Events.** The 321 usable primary events of the two Gate 1 reads, with the same exclusions:
  - S_A: 83 events, `gate1-sa-a2/prepare.json`;
  - S_B: 238 events, `gate1-sb/prepare.json`.
- **One portfolio** over S_A ∪ S_B, in the order of T.
- **Event prices.** One-minute candles: the OKX swing v2 tape for S_A, the S_B tape for Binance.
- **C1-A2 controls** (20 per event, from the same prepare files):
  - S_A: OKX one-minute candles;
  - S_B: Binance **hourly** candles, the only control resolution downloaded for S_B (`H1-SB-IMPLEMENTATION-NOTE.md`).
- **Specifications (§14).**
  - OKX: the audit's contract value, lot and minimum (`audit/okx-events.json`, archive-observed for delisted
    contracts), else the 2026-10-14 instruments snapshot.
  - Binance: the `MARKET_LOT_SIZE` step and minimum and the `MIN_NOTIONAL` filter of the 2026-10-14 exchangeInfo
    snapshot; contract value 1.
- **Funding (§12).**
  - The archive rates of the OKX tape, and of the OKX and Binance monthly funding archives downloaded **after the
    claim**, for [T − 3 d, T + 15 d] of each event and control.
  - Rates only, with a manifest and sha256.
  - Binance zips are checked against the published checksums. OKX publishes none, so the sha256 of each OKX zip is
    recorded instead.

## 2. One trade (§13)

1. **σ24.** Standard deviation of the log returns of the closes in [t0, T] × √1440 on one-minute candles; × √24 on
   hourly candles (S_B controls).
2. **Stop distance.** d = clamp(3 σ24, 15 %, 60 %).
3. **Entry.** The open of the first candle at or after T, within one hour. The taker sells at open × (1 − entry
   slippage).
4. **Sizing.** `conventions.size_short`:
   - equity 500, risk 0.5 %, leverage 1×;
   - the funding provision is inside the planned loss;
   - quantity rounded down to the lot;
   - below the minimum → skipped, never rounded up.
5. **Stop.** The first candle whose high reaches open × (1 + d).
   - Fill: the worse (higher) of the stop level and that candle's open (a gapped open), and on one-minute candles
     also the next minute's open; then × (1 + stop slippage) and the taker fee.
   - On hourly control candles the next-candle term is dropped (an hour is not "the next minute").
6. **Exit.** If not stopped, at T + 14 d: the close of the last candle ending by then, × (1 + exit slippage), taker
   fee.
   - A series ending earlier (delisting) exits at its last close.
   - A3 D4 uses the highest close of the last 24 h instead.
7. **Funding.**
   - Every recorded settlement in (T, exit] is credited to the short at rate × mark / entry reference.
   - The mark is the last close.
   - A gap longer than 1.5 × the previous cadence hides settlements at that cadence; each is booked against the short
     at the largest |rate| of the 3 days before the gap (§12).
8. **Net return** (a fraction of the entry notional) = gross short return − fees + funding.
   - PnL = notional × net.
   - **R = PnL / 2.50 USDT**, the 0.5 % risk unit.

## 3. Portfolio

- Events are taken in the order of T.
- At most **two** positions are open at once (1 % total risk), one per instrument (tokens are distinct).
- An event arriving on a full budget is skipped and counted.
- The drawdown is computed on realised PnL in exit order.

## 4. Costs

| Profile | Fees | Slippage (entry / exit / stop) | Funding provision in sizing |
|---|---|---|---|
| Baseline | 5 bp per side | 20 / 20 / 50 bp | 0.03 % per 8 h |
| Pessimistic | 5 bp per side | 60 / 60 / 150 bp | 0.1 % per 8 h |

The stress doubles the stop slippage. It is applied to each profile: 100 bp on baseline, 300 bp on pessimistic.

## 5. Criteria (§15), on baseline and pessimistic

1. At least 60 executed trades.
2. Mean net R per trade > 0, and > 0 under that profile's stress.
3. Mean absolute net short return (fraction of notional) > 0.
4. Surplus over the control strategy > 0 with p ≤ 0.10.
   - The control strategy: in each of 1,000 draws (seed 20261015), one C1-A2 control is drawn uniformly per event,
     and the same rule and the same portfolio are run.
   - Statistic: the mean net R per executed trade.
   - Surplus = H1 mean R − the mean over draws.
   - p = the share of draws whose mean R ≥ H1's.
5. Profit factor ≥ 1.2 (baseline) / ≥ 1.0 (pessimistic).
6. Portfolio PnL > 0.
7. Maximum drawdown ≤ 75 USDT (15 %).
8. Worst trade ≥ −3 R.
9. Portfolio PnL positive in at least 2/3 of the half-years with ≥ 15 executed trades (the Gate 1 convention). All
   half-years are reported.
10. Mean R still > 0 without the top 5 % of trades.

## 6. Verdict

| Outcome | Verdict |
|---|---|
| Every criterion holds on both profiles, stresses included | `GATE2_PASS` |
| Baseline passes, but the pessimistic profile or a stress fails | `INSUFFICIENT_EXECUTION_DATA` |
| Otherwise | `GATE2_FAIL` |

`GATE2_FAIL` and `INSUFFICIENT_EXECUTION_DATA` close the step without a pass. **A fail closes H1** (§17).

## 7. Funding coverage gate (before the run)

After the download, `gate2 coverage` lists the events with **no funding settlement inside [T, T + 14 d]**. No frozen
rule covers a series that is missing entirely (§12 covers gaps inside a series). If any H1 event is in that list,
the run **stops before execution**, and the count goes to the owner. Control legs without funding are counted and
reported in the same way.

The run refuses to start if `gate2.json` exists.
