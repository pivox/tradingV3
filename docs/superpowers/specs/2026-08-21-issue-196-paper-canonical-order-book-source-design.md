# Paper canonical order-book source design

## Problem

The modern Paper evidence contract requires a `CanonicalOrderBookSnapshot`, but the real provider does not yet have a deterministic bridge from the authenticated, applied public replay prefix. Reusing only the projector's bid/ask cache would lose the source event identity and could disagree with the existing canonical microstructure engine when receipt order differs from exchange order.

## Decision

Add an unwired `PaperCanonicalOrderBookSource` that receives the modern execution cell and current trigger event. It will:

1. verify the trigger is the exact current event for the cell/network/venue/symbol prefix;
2. select only applied top-of-book events whose exchange and receipt timestamps are observable at the replay clock;
3. reuse `PaperBacktestDatasetAdapter::adaptMicrostructureEvents()` so OKX and Hyperliquid payload validation and canonical availability ordering stay identical to the existing microstructure path;
4. choose the latest canonical available book and convert it to `CanonicalOrderBookSnapshot`;
5. use the runtime cost-contract source `order_book` and bind `inputHash` exactly to `sha256:<book event_id>`, as required by the microstructure/order-plan proof guard.

The snapshot's `observedAt` is the canonical book `happened_at` (exchange time). Receipt time controls availability, while runtime freshness remains measured from the market observation.

## Contract

- legacy cells and cross-scope triggers fail closed;
- a not-yet-received current trigger or absence of an observable book returns no evidence;
- an older/forged trigger against a newer applied prefix fails closed;
- book selection uses `(available_at, happened_at, source_record_id)` ordering, not insertion order;
- malformed venue payloads fail closed through the canonical adapter;
- no private exchange endpoint or write path is introduced.

## Scope

This slice only creates and tests the public order-book evidence source. It does not build the full strategy evidence provider, instrument/risk/portfolio snapshots, costs, or activate modern Paper execution.
