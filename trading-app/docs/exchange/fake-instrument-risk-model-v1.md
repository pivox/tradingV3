# Fake instrument and risk model v1

This document defines the deterministic instrument, precision, margin, and
leverage slice of the local Fake/Paper exchange. It does not complete issue
#196 and does not authorize any live, demo, or testnet exchange mutation.

## Versioned contracts

| Contract | Version |
|---|---|
| Instrument/risk model | `fake-instrument-risk-model-v1` |
| Instrument catalog fixture | `fake-instrument-catalog-v1` |
| Precision model | `brick-math-exact-multiple-v1` |
| Derived margin model | `fake-derived-initial-margin-v1` |
| Persistent state engine | `fake-paper-state-v1` (format `1`) |
| Golden scenario catalog | `fake-paper-golden-v1` |
| Fee model | `fixed_notional_fee_v1` at `0.0005` |
| Fill model | `top_of_book_v1` |
| Additional slippage model | `fixed_adverse_slippage_bps_v1` at `5` bps for taker fills |
| Spread model | `top_of_book_embedded_spread_v1` |

`FakeExchangeAdapter::runtimeModelMetadata()` publishes the catalog,
precision, fee, fill, and slippage versions. The margin version is published on
the canonical USDT balance metadata.

The execution price remains the deterministic top-of-book price. Spread is
therefore already embedded in that price and is reported as an explicit
additional `spread_cost_usdt=0`. Post-only fills are classified as maker and
report `slippage_cost_usdt=0`; every other Fake fill is classified as taker and
reports:

```text
slippage_cost_usdt = quantity * fill_price * contract_size * 5 / 10000
```

The matching engine records these values once in the fill event. REST and
private-WS normalization copy them without recomputation. Ledger ingestion
persists finite non-negative explicit values, leaves absent values `NULL`, and
flags invalid values as `spread_cost_invalid` or `slippage_cost_invalid`.

## Instrument fixture

Both instruments are canonical uppercase USDT-settled perpetuals. Their allowed
order types are `limit`, `market`, `stop_loss`, and `take_profit`.

| Field | BTCUSDT | ETHUSDT |
|---|---:|---:|
| Base asset | `BTC` | `ETH` |
| Quote asset | `USDT` | `USDT` |
| Settle asset | `USDT` | `USDT` |
| Market type | `perpetual` | `perpetual` |
| Price tick | `0.10` | `0.01` |
| Quantity step | `0.001` | `0.01` |
| Minimum quantity | `0.001` | `0.01` |
| Minimum notional | `5` | `5` |
| Contract size | `1` | `1` |
| Maximum leverage | `100` | `75` |
| Maintenance margin rate | `0.005` | `0.005` |

Unknown symbols, non-canonical symbols, unsupported market types, and
unsupported order types are not inferred from another instrument.

## Precision and rejection behavior

Prices and quantities are checked with Brick Math exact remainder operations. A
price must be an exact multiple of `priceTick`; a quantity must be an exact
multiple of `quantityStep`. `PlaceOrderRequest` can carry the exact decimal text
alongside its float projection, and the Fake HTTP placement endpoint requires
precision-sensitive fields (`quantity`, `price`, stop prices) as positive plain
decimal JSON strings. JSON numbers are rejected at that boundary because their
lexical precision cannot be recovered after decoding. Internal callers that only
have a float use its canonical JSON decimal projection.

The adapter never rounds, truncates, snaps, or otherwise quantizes an invalid
request. The original exact decimal is retained in redacted order metadata for
validation and replay comparison.

Example:

```bash
curl -X POST http://localhost:8082/fake-exchange/orders \
  -H 'Content-Type: application/json' \
  -d '{
    "symbol": "BTCUSDT",
    "side": "buy",
    "position_side": "long",
    "order_type": "limit",
    "quantity": "0.001",
    "price": "24950.0",
    "client_order_id": "fake-risk-example-1",
    "post_only": true,
    "leverage": 3
  }'
```

Validation is ordered and returns one stable reason. Relevant reasons for the
canonical golden scenarios are:

- `price_not_quantized` or `quantity_not_quantized` for exact precision failure;
- `leverage_above_maximum` when request leverage exceeds the instrument cap;
- `insufficient_balance` when required initial margin exceeds available margin.

A rejected request produces one persisted order with status `rejected` and one
redacted `order.rejected` event. It creates no active order and no position.
Only explicitly allowed scalar lineage keys are copied from request metadata;
credentials, authorization headers, nested raw payloads, and arbitrary metadata
are not persisted or emitted. Rejected requests still have deterministic local
order identity, which makes same-state audit and client-order replay unambiguous.

## Margin and collateral

For a new non-reduce-only entry:

```text
notional = quantity * reference_price * contract_size
initial_margin = notional / leverage
```

A limit BUY uses its submitted limit price because execution cannot occur above
that cap. A limit SELL uses the greater of its submitted limit and the current
best bid, so a deeply crossing sell is checked against the actual higher
executable price. A market order uses the deterministic top of book for its
side. Reduce-only and protection orders do not reserve new initial margin, but
they still pass instrument and precision checks.

Used margin is derived rather than maintained as a second reservation balance:

```text
used_margin = sum(persisted open-position margin)
            + sum(active non-reduce-only remaining notional / order leverage)
total = balance.total ?? balance.equity ?? balance.available
collateral = equity is present ? min(total, equity) : total
available_margin = max(collateral - used_margin, 0)
```

`balance.equity` is optional. When present, collateral uses the lower of the
selected total and equity; otherwise it uses the selected total. Only a selected
total, or a present equity, that is negative or non-finite fails closed. Missing
`balance.total` falls back to equity and then available balance; it is not itself
a failure. Cancelling an active entry removes its remaining derived reservation,
while persisted position margin survives state recovery.

## Leverage and idempotence

`setLeverage(symbol, leverage, marginMode)` accepts only a catalog symbol,
positive leverage at or below that symbol's cap, and `isolated` or `cross` mode.
An accepted setting is stored per symbol in the versioned Fake state and emits a
redacted `leverage.updated` event. Repeating the identical setting returns
success without adding another update event. Replacing it explicitly persists
the new value. Invalid settings return failure without changing the leverage map,
orders, positions, or event ledger.

Version-1 state payloads that predate `leverageSettings` restore with an empty
map and are upgraded on the next explicit write. Request-provided order leverage
remains supported and takes priority because the Fake adapter does not require a
separate leverage submission; it still must respect the catalog cap. When an
order omits leverage, the persisted symbol leverage and margin mode are applied
before validation, persistence, and position-margin accounting. Without either
an order leverage or a persisted symbol setting, the deterministic fallback is
1x.

Order idempotence remains keyed by symbol and `clientOrderId`. Replaying the same
numeric intent returns the existing local order identity with
`idempotent_replay=true`; equivalent decimal scales such as `0.001` and `0.0010`
match. Reusing the identifier for a different exact decimal or another changed
intent fails closed with `duplicate_client_order_id_intent_mismatch` and
`idempotent_replay=false` instead of creating a second active order.

## Attached-protection compensation

An attached stop rejection after a full entry fill is never represented as a
protected position. The entry keeps `protection_status=rejected` and the stable
rejection reason. The matching engine immediately submits a deterministic
market reduce-only close for the failed entry's exact filled quantity through
the same validation, fill-cost, position, event, and lineage path as any other
Fake order.

Successful compensation records the close order identifiers,
`fail_safe_action=reduce_only_market_close`,
`compensation_status=completed`, the compensation quantity, the position sizes
before/after, and proof that the failed entry exposure was removed. A standalone
entry records `compensation_outcome=position_closed`; a position increase records
`entry_exposure_closed` and preserves the prior size only when active stop orders
still cover it fully. Replaying the entry `clientOrderId` returns the same
identifiers and does not create another fill. If the close is rejected, removes
the wrong quantity, or leaves an unprotected residual, the file-backed
transaction rolls back to its pre-request snapshot.

## Providers and runtime readiness

The Fake contract provider reads the canonical instrument catalog and local
order book. The account provider exposes the derived USDT balance and positions.
The order provider delegates local placement, reads, cancellation, and leverage
updates to `FakeExchangeAdapter`. These providers do not contact an exchange or
expose serialized state payloads.

The contextual provider path accepts only the injected `exchange=fake` and
`market_type=perpetual` options. Legacy `LIMIT` protection submissions carrying
a non-null `stopPrice` are mapped to a triggered stop-loss order, preserving
stop-limit semantics instead of crossing the book immediately.

Runtime readiness recognizes non-empty catalog and precision versions as loaded
metadata. Unconfigured persistence adds the
`fake_paper_persistence_not_configured` warning but is not a blocking
prerequisite: an in-memory Fake runtime can still reach local dry-run readiness.
When persistence is configured, it must be writable and recovery-ready or the
runtime reports a blocking error. Market/replay source connectivity, a controlled
clock, readable state, and the other evaluator inputs still determine the overall
gate. A residual local order book alone does not prove market-source readiness.
The slippage gate requires the exact versioned 5 bps model and embedded-spread
version; a missing, zero, malformed, or unsupported model reports the blocking
error `fake_paper_slippage_model_not_ready`.

The runtime contract accepts only `fake/perpetual`. Fake/spot requests fail
closed: the runtime check rejects that context and canonical order validation
returns `market_type_not_supported`. Regardless of supplied readiness input,
Fake runtime evaluation forces `permissionsTrade=false`, `dryRun=true`, and the
kill switch on. It cannot enable mainnet, demo, or testnet writes.

## State creation and persistence

Constructing a file-backed `FakeExchangeStateStore` for an absent path initializes
defaults in memory and does not create the active state file. Balance, catalog,
provider, and readiness reads do not create orders, positions, events, or the
active state file. Fault lookup transactions persist only when they consume a
queued fault or another mutation changes runtime state. The persistence readiness
probe uses and deletes a separate temporary file; it does not rewrite active
state or consume queued faults.

Explicit mutation persists when a state path is configured. Examples include
`reset()`, setting a book top, placing or cancelling an order, filling an order,
queueing a fault, and accepting a leverage update. In-memory stores keep the same
deterministic behavior without filesystem persistence.

## End-of-zone fallback taker

`fake-fallback-taker-v1` is an opt-in policy persisted in the parent post-only
maker order metadata. It records the valid entry-zone bounds and maximum adverse
slippage. The local scenario trigger expires an active parent once, accepts an
already expired parent after restart, and converts only its exact unfilled
decimal remainder to a deterministic child `MARKET` order. The bounded adverse
total combines maker-limit-to-book displacement with the versioned 5 bps taker
cost.

The child uses the normal validation, margin, fill, cost, position, and
protection paths. A partial maker fill retains explicit zero maker slippage while
the fallback fill retains the versioned 5 bps taker cost. The attached protection
and compensation invariant covers the aggregate parent-plus-child quantity.
Deterministic parent/child identifiers and terminal audit metadata make success,
guard rejection, and persisted restart replay idempotent.

The trigger rejects or no-ops for a missing/disabled policy, invalid zone,
excessive adverse slippage, cancelled parent, or zero remainder. Any partial
maker exposure left by cancellation or a fallback rejection is protected for its
actual filled quantity; a protection-fixture rejection compensates that exact
partial exposure. Generic request metadata can persist only the five typed
policy fields. Derived parent, remainder, trigger, measured-slippage, and
aggregate-protection metadata is engine-owned, and replay validates the complete
persisted child intent before accepting it. This capability exists only inside
the Fake/Paper matching engine and introduces no live, demo, testnet, provider,
or network write path.

## Rollback

This model has no external exchange side effects. To roll back a local Fake/Paper
runtime:

1. Stop local Fake/Paper writers and archive `var/fake_exchange_state.dat` for
   investigation.
2. Deploy the application revision from before the instrument/risk model changes.
3. Remove or quarantine the v1 state file unless the target revision has been
   explicitly tested against its additive leverage field; do not silently reuse
   an incompatible serialized payload.
4. Clear the application cache, restart local workers, and run
   `app:exchange:runtime-check fake perpetual` before resuming local scenarios.
5. Restore golden scenario 12 to an explicit gap if the attached-protection
   compensation runner is rolled back.
6. Restore golden scenario 4 to `unsupported` with
   `fallback_taker_not_implemented` if the fallback policy and trigger are rolled
   back.

Do not use rollback as a path to live, demo, or testnet trading. No credential,
exchange endpoint, or network operation is part of this model.

## Issue #196 scope boundary

All twenty Fake/Paper golden v1 rows are executable. Scenario 20 covers the
consolidated multi-profile dry-run recipe and proves orchestration/config
isolation plus the existing global exposure-lock contract without creating an
order or contacting an exchange. The v2 safety evidence measures OKX and
Hyperliquid through HTTP guards and proves the Bitmart zero through the Fake
provider boundary; this is not a Bitmart HTTP measurement. Fake indicator and
ConditionRegistry timeframe kline reads inject `FakeKlineProvider` directly
with the Fake context, without a global registry or bundle on those routes, and
the recipe does not decorate, modify, or call a Bitmart provider.

This closes the catalog gap only. Trade-history completeness and later
operational acceptance remain outside this slice, so the evidence does not by
itself close issue #196 or authorize demo, testnet, or live mutation.
