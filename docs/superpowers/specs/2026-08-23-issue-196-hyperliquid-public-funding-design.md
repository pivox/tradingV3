# #196 Hyperliquid public funding design

## Goal

Make the current public Hyperliquid funding rate available to canonical modern
Paper execution without treating a missing rate as zero or reusing an OKX
schedule shape.

## Source contract

Capture `metaAndAssetCtxs` from the public, credential-free Info endpoint once
for each live source epoch. The response binds universe indexes to asset
contexts and exposes the current funding rate for BTC and ETH. New normalized
events use `paper-funding-rate.v2` with the exact current rate, receipt time,
the documented one-hour interval, `current_asset_context` method,
`metaAndAssetCtxsFunding` formula provenance, `processing` settlement state,
source epoch, and `rest_public_meta_and_asset_contexts` origin.

Unlike the OKX v1 schedule, the Hyperliquid event does not invent current or
next settlement timestamps. `fundingHistory` remains appropriate for settled
historical cash flows, not the current cost input for a new order plan.

## Replay and certification

The live source emits metadata and funding before the snapshot boundary on
initial connection and every reconnect. Dataset verification authenticates the
exact v2 keys, canonical signed decimal, one-hour interval, receipt timestamp,
event identity, and source epoch. Funding remains optional at the generic
dataset-format level for backwards-readable captures, but canonical
Hyperliquid execution returns no cost evidence when it is absent.

`PaperCanonicalFundingSource` accepts OKX v1 unchanged and Hyperliquid v2 only
when the latest funding epoch matches the current snapshot epoch. It applies
receipt-time no-lookahead, exact interval matching, and one-interval freshness.

The setup cost contract continues to select `venue_schedule`; the authenticated
exchange layer owns the effective cadence. Policy compilation therefore reads
`exchange.funding.interval` and rejects a missing, disabled, malformed, or
extended schedule. Hyperliquid publishes `PT1H`, while OKX and Fake retain
`PT8H`. The setup's legacy numeric interval is not used as a runtime fallback.

## Safety

- public Info endpoint only;
- no address, credential, private read, exchange write, or mainnet execution;
- BTC and ETH must each occur exactly once and match their context index;
- unknown, malformed, future, stale, cross-network, cross-symbol, or
  cross-epoch evidence fails closed;
- missing evidence never becomes an implicit zero.
