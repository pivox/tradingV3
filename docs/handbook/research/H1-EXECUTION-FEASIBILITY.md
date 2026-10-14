# H1 — Execution feasibility at 500 USDT (metadata only, 2026-10-14)

No post-listing price was read. Feasibility is expressed as the **highest entry price at which the venue's
minimum order still fits the risk budget**. Whether an event is executable is decided at its decision time T,
with the price known then; this document can only bound it.

Code: `python-orchestrator/scripts/research_listing/conventions.py` (`size_short`, `max_entry_price`), tested in
`tests/test_research_listing.py`.

## 1. Fixed parameters (owner, 2026-10-14)

- **Capital and leverage.** 500 USDT, leverage at most 1×, one position per instrument.
- **Risk.** At most 0.5 % per trade (2.50 USDT) and 1 % in total.
- **Stop.** `entry × (1 + d)` with `d = clamp(3 × σ24, 15 %, 60 %)`. No other distance.
- **Sizing.** Rounded **down** to the lot. A plan below the minimum is refused, never rounded up. Leverage and risk
  are never raised to reach a minimum.
- **Costs counted in the planned loss** (nothing counted twice):

  | Item | Baseline | Pessimistic |
  |---|---:|---:|
  | Taker fee, entry and stop | 5 bp each | 5 bp each |
  | Entry slippage | 20 bp | 60 bp |
  | Stop slippage | 50 bp | 150 bp |
  | Funding provision over 14 days | 1.26 % (0.03 % per 8 h) | 4.2 % (0.1 % per 8 h) |

  These are **conventional** assumptions: there is no order-book history for new listings.

Allowed notional `N = 2.50 / (d + costs)`:

| Stop distance d | Baseline | Pessimistic |
|---|---:|---:|
| 15 % | 14.65 USDT | 11.68 USDT |
| 60 % | 4.03 USDT | 3.77 USDT |

## 2. Venue constraints (current specifications)

### Binance

- Every primary event has `MIN_NOTIONAL` = **5 USDT** (240 of 240).
- **A stop wider than 47.9 % (baseline) or 43.5 % (pessimistic) can never be executed at 500 USDT**: the allowed
  notional falls under 5 USDT. With σ24 above about 14.5–16 %, the 3σ stop exceeds those distances.
- `LOT_SIZE.minQty` adds a price ceiling. For the median contract (minQty 1 coin), the entry price must be at most
  14.65 USDT (stop 15 %, baseline).

### OKX

- The minimum order is `minSz × ctVal` coins; there is no notional minimum.
- Ceiling on the entry price across the 83 primary OKX contracts:

  | Stop | Median contract | 10th percentile |
  |---|---:|---:|
  | 15 %, baseline | 1.47 USDT | 0.147 USDT |
  | 60 %, baseline | 0.40 USDT | 0.040 USDT |

- Contracts with a large `ctVal × minSz` cannot be traded at 500 USDT when the token is priced above a few tenths
  of a USDT.

### Margin and leverage

At 1× the margin equals the notional (≤ 14.65 USDT per position). Initial leverage caps of new listings do not
bind. **A short can still be liquidated** before its stop if the venue's maintenance margin and a gap above the
stop combine. At 1× isolated margin this requires roughly a +100 % move, which is possible on new listings. It is
modelled as a gap through the stop (the fill at the next price) plus the stress of §4.

## 3. Classification

| Class | Meaning | Current status |
|---|---|---|
| Theoretical feasibility | With current specifications, and the price at T, the minimum order fits the budget | Computable only at T (Gate 2); bounds above |
| Historical feasibility demonstrated | Specifications at the listing date known | **None**: OKX and Binance publish current specifications only |
| Uncertain feasibility | Current specification applied to a past listing | **All historical events** (S_A, S_B) |
| Not executable under the limits | Binance stop > 43.5–47.9 %; any venue when the price at T exceeds the ceiling | Determined at T; excluded and counted, never resized |

Historical specifications cannot be reconstructed. For delisted OKX contracts, the archive-observed lot and
`ctVal` are used and flagged; for live contracts, the current ones. **Gate 2 on historical sets therefore
measures executability under current specifications, not reproduced past conditions.** Only the prospective set
S_C (specifications recorded at each listing) can demonstrate it.

## 4. Can the stop be simulated credibly?

- **Partially.** The 1-minute candles show gaps, so a stop is filled at the worse of its level and the next
  minute's open, plus slippage.
- **What candles cannot show.** The spread, the book depth during the first weeks, and price-limit or
  trading-halt rules.
- **Required stress.** Stop slippage × 2 (300 bp pessimistic).
- **Rule.** If Gate 2 passes under the baseline but fails under the pessimistic costs or the stress, the verdict
  is **`INSUFFICIENT_EXECUTION_DATA`**, not a pass. Profitability on assumed costs alone is never declared
  exploitable.
- **A stop does not guarantee a loss of 0.5 %.** The worst trade, in R, is a Gate 2 criterion.

## 5. Missing data that matters

- Historical contract specifications (minimum size, lot, contract value, price limits) at each listing.
- Order-book or top-of-book history for new listings (spread, depth in the first weeks).
- Official delisting settlement prices. Binance gives the delivery date, not the price; OKX gives neither.
- Real-time listing announcements, needed for the prospective set; they are to be recorded from the freeze on.
