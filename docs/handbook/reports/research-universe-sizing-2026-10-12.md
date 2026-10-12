# Sizing note — data for a wider universe (2026-10-12)

Owner's item 3 of 2026-10-12: cost the data acquisition for a wider-universe research scope
before writing any code. Facts measured today, projections marked as such.

## What exists on the public Binance archive

The USD-M futures archive (`data.binance.vision/data/futures/um/daily/…`) lists more than
1,000 symbol folders for `aggTrades`, of which 863 USDT-margined perpetuals on the first index
page alone (listed and delisted contracts; survivorship therefore controllable by using the
listing date of each archive). The same tree holds `klines` at every interval, `fundingRate`,
`metrics` (open interest, long/short ratios, since 2022), `bookTicker` and `bookDepth`.

## Measured today (aggregated trade tape, ten majors)

| Item | Value |
|---|---|
| Files | 150 (10 pairs × 15 weekdays of 2025, three windows) |
| Downloaded | 1,707 MB of zips, every sha256 equal to the published CHECKSUM |
| Stored | 858 MB of compressed NumPy files (`ts_us, price, size, taker_buy, agg_trade_id`) |
| Rows | 131.2 million prints |
| Time | 18 min 46 s wall clock, two parallel downloads at about 8 MB/s, 2–20 s of conversion per file |
| Largest day | ETHUSDT 2025-11-14, 70 MB zipped; BTC/ETH days 23–70 MB, the other eight pairs 2–15 MB |
| Anomaly | one duplicated `agg_trade_id` on SOLUSDT 2025-07-16 (kept, keyed by position) |

Average 11.4 MB of zip per symbol-day on the majors, 5.7 MB stored.

## Projections

| Scope | Data | Size (zip → stored) | Acquisition time | Fits the host? |
|---|---|---|---|---|
| Cross-sectional momentum / carry, 100 USDT perps, 2023–2025 | 1h klines + funding + metrics | ≈ 0.6 GB → 0.3 GB (projected from 10-pair candle sets: 764 MB for 1m data of 10 pairs since 2022-11) | ≈ 1 h | yes (41 GB free) |
| Same, 300 perps, daily bars | 1d klines + funding | < 0.2 GB | < 1 h | yes |
| Tape scalping, ten majors, 90 weekdays of 2025 | aggTrades | ≈ 10 GB → 5 GB | ≈ 2 h | yes, after the stale research artifacts are removed |
| Tape scalping, 100 pairs, 30 days | aggTrades | ≈ 15–35 GB → 8–17 GB (small pairs are 3–10× lighter than BTC/ETH) | 4–8 h | only after cleanup (41 GB free today) |
| Book-level (bookTicker), BTC/ETH, 30 days | bookTicker | ≈ 30–60 GB zipped (projected from the update rates seen in the captures: 0.8 M top-of-book changes per day on OKX BTC) | 6–12 h | no, without a dedicated disk |

## What a wider-universe study needs that the platform does not have

- A universe file per rebalance date (listing dates from the archive index, volume filters),
  so that delisted contracts are included when they traded.
- A portfolio engine (cross-sectional ranks, volatility targeting, weekly or daily rebalance,
  funding accrual per 8 h) — the swing and v2 engines are per-pair plan replays.
- Costs: taker fees on rebalances, spread from the tick size, funding from the archive.

## Recommendation

If the owner opens that scope: start with the cheapest layer (1h klines, funding, metrics for
the top 100 USDT perps by rolling 30-day volume, 2023–2025), pre-register the families
(time-series momentum, cross-sectional momentum, funding carry, low-volatility), and keep 2026
untouched. The tape layer stays reserved for the scalping work on the ten majors.
