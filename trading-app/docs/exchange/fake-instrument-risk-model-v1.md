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
| Additional slippage model | `explicit_zero_additional_slippage_v1` |

`FakeExchangeAdapter::runtimeModelMetadata()` publishes the catalog,
precision, fee, fill, and slippage versions. The margin version is published on
the canonical USDT balance metadata.

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

Prices and quantities are converted to decimal text and checked with Brick Math
exact remainder operations. A price must be an exact multiple of `priceTick`; a
quantity must be an exact multiple of `quantityStep`. The adapter never rounds,
truncates, snaps, or otherwise quantizes an invalid request. The original price
and quantity are retained on the persisted rejected order.

Validation is ordered and returns one stable reason. Relevant reasons for the
canonical golden scenarios are:

- `price_not_quantized` or `quantity_not_quantized` for exact precision failure;
- `leverage_above_maximum` when request leverage exceeds the instrument cap;
- `insufficient_balance` when required initial margin exceeds available margin.

A rejected request produces one persisted order with status `rejected` and one
redacted `order.rejected` event. It creates no active order and no position.
Rejected requests still have deterministic local order identity, which makes
same-state audit and client-order replay unambiguous without exposing raw
payloads or credentials.

## Margin and collateral

For a new non-reduce-only entry:

```text
notional = quantity * reference_price * contract_size
initial_margin = notional / leverage
```

A limit order uses its submitted limit price. A market order uses the
deterministic top of book for its side. Reduce-only and protection orders do not
reserve new initial margin, but they still pass instrument and precision checks.

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
remains supported because the Fake adapter does not require a separate leverage
submission; it still must respect the catalog cap.

Order idempotence remains keyed by symbol and `clientOrderId`. Replaying the same
intent returns the existing local order identity with `idempotent_replay`; reusing
the identifier for a different intent fails closed instead of creating a second
active order.

## Providers and runtime readiness

The Fake contract provider reads the canonical instrument catalog and local
order book. The account provider exposes the derived USDT balance and positions.
The order provider delegates local placement, reads, cancellation, and leverage
updates to `FakeExchangeAdapter`. These providers do not contact an exchange or
expose serialized state payloads.

Runtime readiness recognizes non-empty catalog and precision versions as loaded
metadata. Unconfigured persistence adds the
`fake_paper_persistence_not_configured` warning but is not a blocking
prerequisite: an in-memory Fake runtime can still reach local dry-run readiness.
When persistence is configured, it must be writable and recovery-ready or the
runtime reports a blocking error. Market/replay source connectivity, a controlled
clock, readable state, and the other evaluator inputs still determine the overall
gate. A residual local order book alone does not prove market-source readiness.
Zero additional slippage remains a warning.

The runtime contract accepts only `fake/perpetual`. Fake/spot requests fail
closed: the runtime check rejects that context and canonical order validation
returns `market_type_not_supported`. Regardless of supplied readiness input,
Fake runtime evaluation forces `permissionsTrade=false`, `dryRun=true`, and the
kill switch on. It cannot enable mainnet, demo, or testnet writes.

## State creation and persistence

Constructing a file-backed `FakeExchangeStateStore` for an absent path initializes
defaults in memory and does not create the active state file. Balance, catalog,
provider, and readiness reads do not create orders, positions, or events. The
persistence readiness probe uses and deletes a separate temporary file; it does
not rewrite active state or consume queued faults.

Explicit mutation persists when a state path is configured. Examples include
`reset()`, setting a book top, placing or cancelling an order, filling an order,
queueing a fault, and accepting a leverage update. In-memory stores keep the same
deterministic behavior without filesystem persistence.

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
5. Restore scenario classifications 6-8 to explicit gaps if their executable
   runners are rolled back.

Do not use rollback as a path to live, demo, or testnet trading. No credential,
exchange endpoint, or network operation is part of this model.

## Remaining issue #196 gaps

The following remain unsupported or only partial and must stay explicit in the
golden catalog and readiness evidence:

- daily loss cap;
- liquidation guard and liquidation model;
- maker-timeout fallback taker behavior;
- non-zero slippage;
- funding accrual;
- one-way position conflict handling;
- TP1-to-trailing-stop behavior;
- stop-attachment compensation integration;
- duplicate/out-of-order event injection coverage;
- consolidated multi-profile Fake/Paper recipe and exposure behavior.

Trade history completeness and any other cataloged partial behavior also remain
outside this slice. Therefore this document and scenarios 6-8 are evidence for a
narrow risk-model increment, not evidence that issue #196 is complete.
