# Hyperliquid Public Paper Live Capture Design

**Date:** 2026-07-29

**Issue:** #132

**Scope:** Paper market data only; no account, order, execution, wallet, or exchange action

## Goal

Add a credential-free Hyperliquid WebSocket source that records deterministic
BTC and ETH public market data on either public mainnet or public testnet. A
dataset has exactly one network and can be replayed byte-for-byte through the
existing Paper recorder and replay contracts.

This work does not enable Hyperliquid trading live. All existing runtime,
schedule, orchestrator, and execution guards remain unchanged.

## Fixed protocol surface

The only accepted WebSocket endpoints are:

- mainnet: `wss://api.hyperliquid.xyz/ws`;
- testnet: `wss://api.hyperliquid-testnet.xyz/ws`.

The source subscribes to exactly twelve streams:

- `trades` for `BTC` and `ETH`;
- `l2Book` for `BTC` and `ETH`;
- `candle` for `BTC` and `ETH`, each at `1m`, `5m`, `15m`, and `1h`.

The only outbound message shapes are:

- `{"method":"subscribe","subscription":{...}}`;
- `{"method":"unsubscribe","subscription":{...}}`;
- `{"method":"ping"}`.

The source rejects every other method, subscription type, or field set before
writing to the socket. In particular, it rejects `post`, `action`, `info`,
wallet/user fields, notifications, orders, fills, funding streams, and all
account-specific subscriptions.

The accepted protocol follows the official Hyperliquid WebSocket,
subscriptions, and heartbeat documentation:

- <https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/websocket>
- <https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/websocket/subscriptions>
- <https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/websocket/timeouts-and-heartbeats>

## Architecture

The implementation adds a dedicated
`App\Trading\Paper\Hyperliquid\Live` stack. It reuses only the exchange-neutral
Paper live source, recorder, consumer, manifest, verifier, and replay
contracts, plus the existing Hyperliquid instrument map, network provenance,
canonical JSON, ordinal state, and event normalizer.

It does not reuse or depend on any Hyperliquid account, signer, wallet,
execution, order, fill, private WebSocket, or exchange-action client.

The stack is split into focused components:

1. a fixed policy containing frame, queue, heartbeat, reconnect, checkpoint,
   and identity-history bounds;
2. a subscription set that produces and acknowledges the exact twelve public
   subscriptions;
3. a strict frame decoder that returns typed public trade, book, candle,
   subscription-response, and pong messages;
4. one bounded raw-frame queue;
5. a fresh loop-bound public WebSocket transport per source;
6. a typed durable checkpoint and atomic checkpoint store;
7. a live normalizer extension for public trades, real top-of-book snapshots,
   closed candles, connection states, and snapshot boundaries;
8. an acknowledged source state machine;
9. a factory that pins and validates the recording dataset directory, manifest,
   network, symbols, quality, configuration hash, and transport graph before
   constructing the source.

No generic OKX/Hyperliquid WebSocket refactor enters this PR. The OKX live
implementation remains unchanged.

## Network and dataset isolation

Network is selected explicitly when the live source is created. The factory
derives the one allowed WebSocket URI from that network; callers cannot supply
or override an endpoint.

The manifest network, checkpoint network, configuration hash, source
normalizer, transport endpoint, and every event network must match. Any
mismatch fails before connecting.

A dataset directory contains exactly one network. There is no alias, implicit
default, cross-network resume, fallback endpoint, or mixed-network replay.
Physical dataset identifiers remain network-scoped using the convention
introduced by the historical Hyperliquid lot.

The existing acquisition-enabled flag is checked before creating or connecting
the transport.

## Normalization and identities

### Trades

Each `WsTrade` becomes a `PUBLIC_TRADE` event. The natural identity is exactly:

`network + coin + block_time + tid`

The payload includes the public coin, side, price, size, transaction hash,
block time, and `tid`. The public `users` field is not retained because it is
not required for Paper execution and increases unnecessary identity data.

Duplicate identity with identical canonical payload is replayed
idempotently. Duplicate identity with another payload fails with an identity
conflict.

### Books

Each `l2Book` message is treated as a real current book snapshot, never as
historical depth. All levels are structurally and numerically validated, but
the common Paper contract emits only `TOP_OF_BOOK`: maximum bid and minimum
ask, their sizes, source time, level counts, `origin=ws_l2_book`, and
`synthetic=false`.

An empty side, crossed book, invalid number, oversized depth, or inconsistent
coin fails closed. Reconnect and initial subscription emit explicit
`SNAPSHOT_BOUNDARY` events before the next top-of-book event.

### Candles

Hyperliquid candle messages do not contain a confirmation flag. The source
therefore holds the latest update per `network + coin + interval + open_time`.
It emits a candle only after observing a later open time for the same stream,
which proves the prior candle closed. The current still-forming candle is never
emitted on operator stop or disconnect.

Closed candle events use `origin=ws_candle`, `confirmed=true`, and the existing
four Paper candle channels. Older duplicate updates replay idempotently;
out-of-order or conflicting finalized candles fail closed.

### Operational events

Connection state and snapshot boundary events include network, connection
epoch, source epoch, and stable reason codes. Receipt-time events use the
injected clock. Market events use the exchange timestamp supplied by
Hyperliquid.

The network remains part of the Paper event identity. Source ordinal scope also
contains network, venue, symbol, and channel.

## Durability, acknowledgement, and restart

The checkpoint is authoritative and contains:

- schema and policy versions;
- dataset ID, network, and canonical configuration hash;
- phase and terminal failure reason;
- connection and source epochs;
- subscription acknowledgement state;
- bounded raw-frame queue state when a frame has entered processing;
- ordinal state;
- finalized candle frontiers and current pending candle updates;
- bounded acknowledged trade/candle identity histories;
- exact pending normalized event and its continuation;
- reconnect budget, heartbeat timestamps, and healthy-stop progress;
- a continuity flag.

Before yielding an event, the source persists the event, ordinal state, and
continuation as pending. `PaperLiveDatasetCapture` then durably appends the
event, applies the idempotent consumer effect, and acknowledges the source.
Acknowledgement advances the checkpoint and clears pending state.

After a crash with a pending event, restart yields exactly the same event.
After a crash or disconnect without a recoverable pending event, the source
may reconnect to obtain fresh book and candle snapshots, but public trades
missed during the gap cannot be invented. The continuity flag becomes false,
and the dataset can only finish as incomplete. It is never certifiable as a
continuous modern baseline.

## Heartbeat, reconnect, and backpressure

The source sends `{"method":"ping"}` before the documented 60-second idle
timeout and accepts only `{"channel":"pong"}` as its heartbeat response.
Heartbeat and reconnect timers use the injected clock and event loop.

Reconnect delay and attempt count are finite and checkpointed. Each transport
generation is isolated so stale callbacks cannot mutate a newer generation.
All twelve subscription acknowledgements are required before the generation is
ready.

The raw-frame queue is bounded by frame count and total bytes. Oversized frames
or exhausted backpressure terminate with stable redacted reasons. No frame,
URL, wallet-like value, transport exception text, or server error text enters
logs, checkpoints, manifests, or public exceptions.

## Completion and certification

The source completes only after an explicit healthy operator stop, all pending
events are acknowledged, the queue is empty, the current generation is fully
subscribed, and continuity remains true.

A complete dataset uses
`recorded_public_book_and_trades`. Any protocol failure, corrupt checkpoint,
subscription gap, exhausted reconnect, abnormal stop, or unrecoverable
continuity loss remains `incomplete`.

Verification requires:

- manifest/checkpoint/event network equality;
- Hyperliquid venue and exact BTC/ETH mapping;
- only the allowed channels and live origins;
- real, non-synthetic book payloads;
- trade identity recomputation from network, coin, time, and `tid`;
- closed-candle monotonicity;
- no duplicate conflicts, unexplained gaps, or pending continuation;
- event file checksum and capture/replay equality.

Mainnet and testnet datasets are verified independently and are never
implicitly aggregated.

## Testing and delivery

All HTTP/WebSocket behavior uses local deterministic fixtures. CI performs no
real network call.

TDD covers:

- endpoint and network binding;
- exact twelve subscriptions and acknowledgement readiness;
- rejection of private/user/action/post surfaces;
- trade, book, closed-candle, connection, and snapshot normalization;
- duplicate, conflict, out-of-order, gap, oversized frame, corrupt checkpoint,
  queue exhaustion, and stale generation;
- heartbeat, pong timeout, reconnect budget, healthy stop, and abnormal stop;
- crash before/after append, consumer effect, and acknowledgement;
- restart with an exact pending event;
- continuity loss producing an incomplete dataset;
- single-network capture and capture/replay equality;
- runtime dependency-graph audit proving zero credential, wallet, signer,
  private client, execution adapter, exchange action, or trading-live gate
  dependency.

Final validation runs targeted PHPUnit during development, one complete Paper
suite, PHPStan over changed PHP files, Symfony container/YAML lint, MkDocs
strict, and `git diff --check`.

Delivery uses one branch and one PR. Codex review is requested once after the
full local validation; all actionable threads are fixed and resolved before
merge, without requesting a second review.

## Explicit non-goals

- No order, action, execution, wallet, account, private fill, or funding API.
- No `post` request over WebSocket.
- No live-trading readiness or removal of existing Hyperliquid live guards.
- No historical trade invention or use of current L2 as historical depth.
- No BitMart change.
- No Paper execution coordinator, strategy, PostgreSQL execution effect,
  certified population generation, or final #132 export in this PR.
