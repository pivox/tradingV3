# #191 Public execution tape design

## Goal

Add the missing authenticated boundary required before partial-fill or maker
simulation. A backtest must never derive executable quantity, queue priority or
aggressor direction from OHLCV volume.

## Boundary

The already verified Paper v2 snapshot remains the source authority. PHP
projects only `public_trade` events into canonical records containing source
identity, venue trade identity, exact event/availability timestamps, aggressor
side, price, quantity and the venue-owned quantity unit. Candles and trades are
separate outputs of the same source checksum.

Python validates the PHP records, binds them to the exact normalized candle
`dataset_id`/checksum and emits an immutable canonical tape manifest plus
NDJSON. The tape checksum binds all source facts and exact trade bytes.

## Fail-closed rules

- only mainnet/testnet public OKX or Hyperliquid data;
- only perpetual BTCUSDT/ETHUSDT records already admitted by Paper v2;
- `available_at >= happened_at`; consumers may only see a trade at
  `available_at`;
- event time belongs to `[dataset.start_at, dataset.end_at)`; reception may be
  later and remains explicit as the only consumer visibility boundary;
- unique source record and venue trade identities;
- strict canonical decimals and explicit `contracts` versus `base_asset` unit;
- OKX identity is the numeric trade ID; Hyperliquid identity is the venue's
  block-time plus trade-ID pair;
- deterministic ordering by availability, event time and source identity;
- at most 40,000 records, keeping worst-case canonical records below the
  immutable 64 MiB artifact cap;
- no strategy/mode/setup/profile identity in source artifacts;
- no fill, partial-fill, queue or fallback decision in this lot.

## Non-goals

This lot does not claim that public traded volume was available to our resting
order, does not translate contract quantities, and does not activate market
fallback. Those decisions require a separately versioned execution model and
canonical policy inputs in the next lot.
