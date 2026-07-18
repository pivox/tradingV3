# Fake Exchange Adapter

`FakeExchangeAdapter` is a local exchange implementation for API-first tests. It is registered in the exchange adapter registry as `fake/perpetual` and never calls external networks.

Supported scenarios:

- place a maker limit order and fill it later with `FakeExchangeScenarioService::movePrice()`;
- place a market order and fill it immediately;
- partially fill and then complete an order with `fillOrder()`;
- convert the exact remainder of an end-of-zone maker order to a deterministic
  market fallback with `fallbackTaker()`;
- reduce an opted-in position at TP1 and trail the exact Fake/Paper remainder;
- create local positions after entry fills;
- create or reject attached SL/TP protection orders;
- close a fully filled entry with an immediate reduce-only market order when
  attached protection is rejected;
- cancel by exchange order id or client order id;
- replay the same active `clientOrderId` without creating a second active order;
- inspect simulated events such as `order.created`, `order.filled`, `position.opened`, `protection_order.created`, and `protection_order.rejected`.

Useful local endpoints:

```bash
curl -X POST http://localhost:8082/fake-exchange/reset
curl -X POST http://localhost:8082/fake-exchange/orders -H 'Content-Type: application/json' -d '{"symbol":"BTCUSDT","side":"buy","position_side":"long","order_type":"limit","quantity":1,"price":24950,"client_order_id":"demo-1","post_only":true}'
curl -X POST http://localhost:8082/fake-exchange/move-price -H 'Content-Type: application/json' -d '{"symbol":"BTCUSDT","price":24949}'
curl http://localhost:8082/fake-exchange/open-orders?symbol=BTCUSDT
curl http://localhost:8082/fake-exchange/positions?symbol=BTCUSDT
curl http://localhost:8082/fake-exchange/events
```

The HTTP service state is stored in `var/fake_exchange_state.dat` so multi-step curl scenarios survive PHP-FPM request boundaries. Tests can instantiate `FakeExchangeStateStore` without a file path for isolated in-memory state, and should call `reset()` before each scenario when sharing a store.

## Deterministic end-of-zone taker fallback

The local scenario API can arm a post-only maker `LIMIT` order with
`FakeFallbackTakerPolicy::toMetadata()`, then explicitly trigger
`FakeExchangeScenarioService::fallbackTaker()` when the entry zone ends. The
policy persists a version, an enabled flag, the valid zone bounds, and the
maximum adverse slippage in basis points. Missing, malformed, or disabled policy
metadata fails closed. Only those typed policy fields may cross the ordinary
request-metadata boundary; parent IDs, exact remainder, aggregate protection,
trigger, and measured slippage are injected by the engine as trusted derived
metadata.

The trigger runs atomically with the persisted Fake state. An active maker is
expired exactly once; a maker already expired before a process restart resumes
without creating another expiration event. Any maker fill remains recorded, and
only the exact decimal `quantity - filledQuantity` is submitted as the child
`MARKET` order. Its
`clientOrderId` is derived from the parent identity, so replay returns the same
child only after its complete immutable intent and the parent's terminal audit
metadata match. A mismatched persisted child fails as an ID conflict. A
cancelled parent and a zero remainder never create a child.

The current executable top-of-book price must remain inside the persisted zone
and within the policy's total adverse-slippage limit. That limit includes both
the displacement from the maker limit to top of book and the fixed 5 bps taker
cost from `fixed_adverse_slippage_bps_v1`. A guard rejection is audited on the
parent and is itself replay-idempotent. Once the guards pass, the child goes
through the ordinary `submit()` and `fillOrder()` path: precision, margin, fee,
fixed taker slippage, position updates, and rejection persistence are not
bypassed. Maker and taker costs therefore stay separate.

Attached SL/TP metadata and lineage are copied to the child. Protection uses the
logical entry quantity, equal to the maker fill plus the fallback remainder, so
a partial maker fill followed by a fallback is protected for the complete
position. When the parent also carries `fake-tp1-trailing-v1`, that policy is
copied to the child and its TP1 quantity is validated against the logical total
exposure rather than only the fallback remainder. If protection is rejected,
the deterministic reduce-only fail-safe
also closes that complete logical quantity. If a zone/slippage/margin/validation
rejection or prior cancellation leaves only a partial maker fill, that exact
maker exposure is protected; a rejected protection compensates that partial
exposure. Scenario reset, price movement, direct fill, fault injection, and
protection-rejection fixtures use the same persisted-state transaction boundary,
so stale scenario instances reload before mutation. No network client,
credential, or exchange adapter write path is involved.

## Deterministic TP1 then trailing stop

Fake/Paper entries can opt into `fake-tp1-trailing-v1` through
`FakeTp1TrailingPolicy::toMetadata()`. The policy is a fixture contract, not a
strategy profile. It contains an explicit enabled flag, an exact TP1 quantity,
and a fixed absolute trailing offset in quote-price units. An entry requesting
the capability must also provide attached SL and TP prices, and its TP1 quantity
must be strictly smaller than its total quantity. A missing field, unsupported
version, disabled capability, non-decimal quantity, non-positive offset, or
incoherent protection request fails explicitly. When none of the policy keys is
present, existing full-size attached-protection behavior is unchanged.

Only the four typed policy fields and the existing scalar lineage whitelist are
persisted from request metadata. Watermark, derived stop, parent IDs, activation
ID, state status, and deterministic trailing client ID are engine-derived.
Arbitrary metadata, credentials, and raw payloads are not copied.

The attached SL initially covers the complete filled exposure. The attached TP1
covers exactly the configured partial quantity. A partial execution of the TP1
order leaves the initial SL active. Once TP1 is completely executed and exposure
remains, one existing Fake state transaction:

1. books the reduce-only TP1 fill and costs;
2. updates the position to the exact decimal remainder;
3. cancels the initial SL with `tp1_replaced_by_trailing`;
4. creates one reduce-only `TRIGGER` for that remainder;
5. initializes its watermark from the TP1 execution price;
6. appends `trailing_stop.armed`.

For a long, the active stop is `watermark - absolute_offset`; only a higher mid
price can advance the watermark and stop. For a short, it is
`watermark + absolute_offset`; only a lower mid price can advance them. Each
favorable change is persisted on the `TRIGGER` order and emits one
`trailing_stop.updated`. An adverse or duplicate price is a no-op and can never
loosen the stop.

A gap through the stop uses ordinary trigger matching and the ordinary fill
path, so the next available simulated bid/ask is the execution price rather than
the stop price. The order becomes `triggered`, emits one
`trailing_stop.triggered`, and closes only its reduce-only remainder. Full
closure cancels remaining sibling protection. TP1 and trailing fills retain the
ordinary fee, spread/slippage, quantity, PnL, and cost-model ledger; no missing
cost is converted to zero by this capability.

The active `TRIGGER` order is the versioned persistent state. Its metadata stores
the current watermark, fixed offset, derived stop decimal, activation lineage,
and `active`/`triggered` status inside the existing `fake-paper-state-v1`
envelope. Restart therefore resumes the same watermark and event sequence without
a migration. Exact entry replay returns the existing order; a different policy
on the same client ID is an intent mismatch. Replayed TP1 fills, prices, gaps,
and terminal fills create no duplicate order, lifecycle, fee, or PnL booking.

The SL/TP race is deterministic under the state lock. SL-first closes the
position and cancels TP1, making a later TP1 fill a no-op. TP1-first atomically
replaces SL with the trailing trigger, making the stale SL a no-op. Any exception
during replacement restores position, orders, events, and ledgers together.

The versioned long and short inputs live in
`tests/fixtures/fake-paper/tp1-trailing-v1.json`; golden scenario 13 executes
both. This path uses no network client, credential, demo/testnet adapter, or live
write permission.

Rollback removes the policy and matching branches and restores scenario 13 to
`partial` with `trailing_stop_not_implemented`. Archive or quarantine every Fake
state file containing `fake-tp1-trailing-v1` before running a revision that does
not understand the additive order metadata. Never silently reuse such a file or
turn rollback into an exchange write activation.

## Attached-protection fail-safe

When the one-shot scenario fixture rejects attached protection after an entry
has filled, the entry remains recorded as filled and
`protection_status=rejected`. The matching engine then submits a deterministic
market reduce-only order for the failed entry's exact filled quantity through
its normal `submit()` and `fillOrder()` path. The entry metadata records
`fail_safe_action=reduce_only_market_close`, the compensation order identifiers,
`compensation_status=completed`, the compensation quantity, and position sizes
before and after the close.

This sequence preserves normal fill costs, lineage, `order.filled`, and the
ordinary `position.updated` or `position.closed` evidence. Replaying the
original `clientOrderId` returns the same entry and compensation identifiers
without another close. The rejection, compensation, and exposure invariant run
inside the existing state transaction.
A standalone entry becomes flat. If the fill increased an older protected
position, only the failed increase is removed and the residual position must
still have full active stop coverage. A wrong close quantity or unprotected
residual raises an exception and restores the whole local operation. No
credential, raw request payload, or external exchange mutation is involved.

## Private WS disconnect/resync fixture

`FakeExchangeWsClient` can inject one deterministic disconnect after a configured
number of acknowledged raw events. The client then reports
`fake_private_ws_disconnected` with state `resync_required`. A yielded sequence is
acknowledged only after its normalization and projection complete, so a projection
failure leaves that raw event available to the next drain. Numeric sequence gaps
report `fake_private_ws_sequence_gap` and also require resync.

After an injected disconnect, `reconnect()` resumes unacknowledged events. A
sequence gap fails closed: plain reconnect is rejected until the caller reconciles
orders, fills and positions from the Fake REST snapshots through
`ExchangeReconciliationService`, then confirms that snapshot with
`completeSnapshotResync()`. Events covered by that snapshot are not replayed and
the next contiguous sequence is consumed normally. The one-shot disconnect is not
reinjected. This fixture remains local, performs no network request and never
sends an exchange order.

### Explicit duplicate/out-of-order delivery plan

An explicit `FakePrivateWsScenario` opts a client into a finite delivery plan.
Each `FakePrivateWsDelivery` contains a stable fixture entry ID, its declared
sequence, the complete raw `FakeExchangeEvent`, and a SHA-256 fingerprint. The
fingerprint covers event type, upper-case symbol, ISO-8601 occurrence time, and
the complete payload. Associative payload keys are sorted recursively while
list order is preserved. Fixture list order is the only delivery order:
`occurredAt` is evidence and is never used to sort or repair the stream.

The plan, cursor, acknowledged fingerprints, sequence watermarks, connection
state, counters, and at most 100 structured audit records are persisted inside
the existing checksummed `fake-paper-state-v1` envelope. Audit records contain
only stable kinds/codes, sequences, fixture IDs, and 12-character fingerprint
prefixes. They never contain raw payloads, credentials, URLs, headers, or
requests. An older envelope without `privateWs` restores as an inactive,
connected scenario. A present malformed block fails with
`fake_exchange_state_shape_invalid`.

Delivery semantics are fail-closed:

- the raw event is acknowledged and the cursor advanced only when the generator
  resumes after normalization and the complete normalized projection batch
  succeeds;
- destroying the client, a projection exception, or a DB rollback before that
  point leaves the delivery pending for the next client or process;
- one non-blocking consumption lease is held across each yielded delivery; a
  concurrent consumer receives `fake_private_ws_consumer_busy` before reading
  or projecting it, whether it shares the in-memory store or only the persisted
  state file;
- a repeated sequence with the same fingerprint is skipped before
  normalization, increments `duplicate_total`, and produces one redacted audit
  record;
- a repeated sequence with a different fingerprint persists
  `resync_required`, increments `conflict_total`, and raises
  `fake_private_ws_sequence_conflict`;
- a future numeric sequence persists `resync_required`, increments `gap_total`,
  and raises `fake_private_ws_sequence_gap`;
- a symbol-filtered drain never acknowledges or advances a delivery for another
  symbol.

While a gap or conflict is active, every drain raises
`fake_private_ws_snapshot_resync_required`; plain `reconnect()` cannot clear it.
The operator must first run `ExchangeReconciliationService` globally against
the local Fake REST snapshots. Scenario-mode `completeSnapshotResync()` requires
that successful `ExchangeReconciliationResult`: it must target Fake/Perpetual,
have `symbol === null`, and contain no errors. A missing, failed, or
symbol-scoped result leaves the cursor and `resync_required` state unchanged.
Only the validated global result may persist the maximum numeric canonical
event sequence as the snapshot watermark. It advances over every covered
fixture entry in its declared order (for example both `3` then `2`), records
their fingerprints, increments `resync_total` once, and reconnects the stream.
A canonical event appended later extends the active finite plan and must be
contiguous with the rebuilt watermark.

When no scenario is configured, the per-client traversal, independent replay,
disconnect injection, reconnect, and gap behavior delivered by #274 are
unchanged. The versioned golden input is
`tests/fixtures/fake-paper/private-ws-out-of-order-v1.json`; golden scenario 16
executes the duplicate, `1,3,2`, restart/resync, contiguous resume, and conflict
paths twice from fresh local files.

This capability is local Fake/Paper only. It does not create an HTTP exchange
client, read a credential, enable demo/testnet writes, or change strategy, MTF,
EntryZone, sizing, leverage, SL/TP, fees, or slippage. Rollback is the atomic
revert of the out-of-order change and restoration of golden scenario 16 to
`partial` with `out_of_order_event_injection_not_implemented`. The additive
`privateWs` field is ignored by the older hydrate path; no exchange-side cleanup
exists or is required.

## Deterministic perpetual funding

`FakeFundingModel` applies the versioned
`fake-funding-notional-rate-interval-v1` contract to an explicit
`FakeFundingSchedule` and a position snapshot observed by a controlled clock.
It uses decimal arithmetic at scale 12:

```text
notional = abs(size) * mark_price * contract_size
amount = notional * rate * applied_interval_seconds / rate_interval_seconds
LONG = -amount; SHORT = +amount
```

Positive normalized amounts are credits and negative amounts are debits. An
absent rate returns `unknown` and emits nothing; no position returns
`no_position`. Unknown currencies preserve their native signed amount while
`amountUsdt` remains `null`.

`settle()` persists one `funding.accrued` event per
`position_id + due_at + model_version`. Exact replay, including after restart,
is a no-op; a changed payload for that identity fails closed. Older deadlines
may arrive after newer ones and are appended once under their own identity. The
normalizer emits `ExchangeFundingReceived`, and the Doctrine projection writes
only a `fill_role=funding` ledger row—never an entry/exit fill or legacy order.

The golden fixture is `tests/fixtures/fake-paper/funding-model-v1.json`; golden
scenario 18 covers both sides, positive/negative/absent rates, a partial
interval, unknown currency, restart replay, and out-of-order settlement. This
path is local-only and cannot enable demo, testnet, or mainnet writes.

## Persistent recovery contract

The file-backed store writes a versioned `fake-paper-state-v1` envelope containing the engine version, a deterministic scenario configuration hash, a payload checksum, and the next event sequence. Writes use a temporary file followed by an atomic replacement.

On restart, orders, the `client_order_id` index, positions, balances, order books, protection orders, versioned trailing-order metadata, events, and the pending protection-failure fixture are restored together. A legacy unversioned state file is accepted and upgraded on the next write. A present but unreadable, unsupported, or checksum-invalid file raises `FakeExchangeStateCorruptedException`; it is never silently replaced with an empty state.

`FakeExchangeStateStore::recoveryMetadata()` exposes the effective format, engine/config identity, whether the instance restored persisted state, whether that state used the legacy format, and the next event sequence. This is local Paper evidence only and does not certify exchange reconciliation or enable any demo/live write path.

## Deterministic adapter faults

`FakeExchangeScenarioService::failNext()` queues a typed one-shot fault for one
adapter operation. The supported kinds are `network_timeout`, `transport_error`,
`http_429`, and `http_500`. A 429 fixture must provide a positive normalized
`retry_after_seconds`; no raw transport response or request payload is retained.

Faults default to `not_applied` and are consumed before the matching engine or
read store is called. `place_order` and `cancel_order` also support
`applied_response_lost` for timeout/transport failures: the mutation is committed,
then `FakeExchangeInjectedException` reports an ambiguous outcome. Retrying the
same client order or cancel request returns the existing result without a second
order, fill, protection, or cancellation event.

```php
$scenario->failNext(new FakeExchangeFault(
    FakeExchangeOperation::PlaceOrder,
    FakeExchangeFaultKind::NetworkTimeout,
    FakeExchangeFaultOutcome::AppliedResponseLost,
));
```

The queue is FIFO per operation and survives a Paper restart. For an
`applied_response_lost` fixture, the matching mutation and fault removal are
committed in the same atomic state-file replacement. If the operation is a no-op
or fails, the transaction restores the state and keeps the fault queued. No delay
or `sleep` is used: timeout behavior is deterministic and does not contact a
network. Faults run at the adapter boundary, so a `not_applied` fixture may reject
a request before the matching engine performs its normal request validation.

## Runtime check

`app:exchange:runtime-check fake perpetual` evaluates the local Fake adapter
without credentials or network access. The adapter check probes an explicitly
loaded order book, balances, a controlled clock, the fee model, stop-loss
capability, and local persistence/recovery. The persistence probe uses a separate
temporary state file in the same directory and never mutates the active orders,
events, positions, balances, or queued faults. Persistence alone does not promote
the runtime to Paper: a real/replay market source must be configured separately.
When the active state file is absent, the check probes the directory without
creating that file.

The contract is intentionally dry-run only: trade permission is always false,
the kill switch remains active, and no demo/testnet or live write path can be
enabled. Taker fills report a deterministic adverse cost of 5 bps under
`fixed_adverse_slippage_bps_v1`; post-only maker fills report explicit zero.
Execution prices remain at top of book, so the separate additional spread cost
is zero under `top_of_book_embedded_spread_v1`. A missing, zero, malformed, or
unsupported runtime model adds `fake_paper_slippage_model_not_ready`. The
application runtime also reports a non-controlled clock and a missing explicit
market source as blockers. Until an operational Fake provider bundle, versioned
instrument metadata, precision fixtures, and those runtime inputs are available,
`Schedule ready` remains `no`; the adapter must not be presented as a complete
Paper runtime.
