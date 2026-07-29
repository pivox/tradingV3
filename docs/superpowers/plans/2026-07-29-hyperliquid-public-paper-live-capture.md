# Hyperliquid Public Paper Live Capture Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a credential-free, network-isolated Hyperliquid public WebSocket source that durably captures and exactly replays BTC/ETH trades, real top-of-book snapshots, and closed candles for Paper #132.

**Architecture:** Add a dedicated single-socket `Hyperliquid\Live` stack around the existing exchange-neutral Paper live capture, recorder, verifier, and replay contracts. Bind configuration, manifest, checkpoint, transport, normalization, and event identity to one explicit network; fail closed on private/action protocol shapes, continuity loss, corrupt state, and backpressure.

**Tech Stack:** PHP 8.4, Symfony 7 Clock/DependencyInjection, ReactPHP event loop, Ratchet Pawl, PHPUnit 11, PHPStan, JSON/NDJSON atomic filesystem checkpoints, MkDocs.

---

## File map

Create these focused production files:

- `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperLivePolicy.php`: finite resource and timing bounds.
- `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperLiveIntegrityException.php`: stable redacted failure reasons.
- `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicSubscriptionSet.php`: exact twelve subscriptions and acknowledgement state.
- `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicFrameQueue.php`: bounded raw-frame queue.
- `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicFrameDecoder.php`: strict public-only inbound protocol.
- `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicWebSocketTransportInterface.php`: loop-bound socket port.
- `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicWebSocketTransportFactoryInterface.php`: fresh transport factory port.
- `trading-app/src/Trading/Paper/Hyperliquid/Live/PawlHyperliquidPaperPublicWebSocketTransport.php`: bounded Pawl adapter.
- `trading-app/src/Trading/Paper/Hyperliquid/Live/PawlHyperliquidPaperPublicWebSocketTransportFactory.php`: no-cache adapter factory.
- `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperLiveCheckpoint.php`: typed authoritative restart state.
- `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperLiveCheckpointStore.php`: atomic checkpoint persistence and transitions.
- `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicLiveSource.php`: acknowledged single-socket state machine.
- `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicLiveSourceFactory.php`: pinned-manifest/network/config construction boundary.

Modify these existing production files:

- `trading-app/src/Trading/Paper/Hyperliquid/HyperliquidPaperPublicConfig.php`: canonical WebSocket endpoints.
- `trading-app/src/Trading/Paper/Hyperliquid/HyperliquidPaperPublicConfigFactory.php`: derive the matching endpoint from explicit network.
- `trading-app/src/Trading/Paper/Hyperliquid/Normalization/HyperliquidPaperMarketEventNormalizer.php`: live trade, book, closed-candle, connection, and boundary methods.
- `trading-app/src/Trading/Paper/Dataset/PaperDatasetVerifier.php`: Hyperliquid live certification rules.
- `trading-app/config/services.yaml`: public-only transport and source factory wiring.
- `docs/handbook/technical/paper-market-data-datasets.md`: new operational contract for Paper datasets and Hyperliquid public live capture.
- `TradingV3_OKX_Hyperliquid_demo_testnet_prompts_canoniques_UNIQUE.md`: record PR3 status only; do not alter BitMart #305.

Create these tests:

- `trading-app/tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicSubscriptionSetTest.php`
- `trading-app/tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicWebSocketTransportTest.php`
- `trading-app/tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicFrameDecoderTest.php`
- `trading-app/tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperMarketEventLiveNormalizerTest.php`
- `trading-app/tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperLiveCheckpointStoreTest.php`
- `trading-app/tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicLiveSourceTest.php`
- `trading-app/tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicLiveSourceFactoryTest.php`
- `trading-app/tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperLiveCaptureReplayEqualityTest.php`
- `trading-app/tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicServiceWiringTest.php`

## Task 1: Bind live configuration to one canonical network endpoint

**Files:**
- Modify: `trading-app/src/Trading/Paper/Hyperliquid/HyperliquidPaperPublicConfig.php`
- Modify: `trading-app/src/Trading/Paper/Hyperliquid/HyperliquidPaperPublicConfigFactory.php`
- Modify: all constructor call sites returned by `rg -l "new HyperliquidPaperPublicConfig" trading-app`
- Test: `trading-app/tests/Trading/Paper/Hyperliquid/HyperliquidPaperPublicConfigTest.php`

- [ ] **Step 1: Write failing endpoint-binding tests**

Add assertions that factory-created configurations expose only:

```php
self::assertSame(
    'wss://api.hyperliquid.xyz/ws',
    $factory->create('mainnet')->webSocketUri,
);
self::assertSame(
    'wss://api.hyperliquid-testnet.xyz/ws',
    $factory->create('testnet')->webSocketUri,
);
```

Add constructor rejection for a crossed network/URI pair:

```php
$this->expectExceptionMessage('hyperliquid_paper_websocket_uri_not_allowed');
new HyperliquidPaperPublicConfig(
    PaperMarketDataNetwork::MAINNET,
    true,
    HyperliquidPaperPublicConfig::MAINNET_INFO_URI,
    HyperliquidPaperPublicConfig::TESTNET_WEBSOCKET_URI,
    '/tmp/paper',
);
```

- [ ] **Step 2: Run the tests and verify RED**

Run:

```bash
cd trading-app
XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Trading/Paper/Hyperliquid/HyperliquidPaperPublicConfigTest.php
```

Expected: failure because `webSocketUri` and WebSocket constants do not exist.

- [ ] **Step 3: Implement canonical endpoint derivation**

Add:

```php
public const MAINNET_WEBSOCKET_URI = 'wss://api.hyperliquid.xyz/ws';
public const TESTNET_WEBSOCKET_URI = 'wss://api.hyperliquid-testnet.xyz/ws';
```

Add `public string $webSocketUri` to the config constructor. Validate both
`infoUri` and `webSocketUri` in the same network `match`. Update the factory:

```php
[$paperNetwork, $infoUri, $webSocketUri] = match ($network) {
    'mainnet' => [
        PaperMarketDataNetwork::MAINNET,
        HyperliquidPaperPublicConfig::MAINNET_INFO_URI,
        HyperliquidPaperPublicConfig::MAINNET_WEBSOCKET_URI,
    ],
    'testnet' => [
        PaperMarketDataNetwork::TESTNET,
        HyperliquidPaperPublicConfig::TESTNET_INFO_URI,
        HyperliquidPaperPublicConfig::TESTNET_WEBSOCKET_URI,
    ],
    default => throw new \InvalidArgumentException('hyperliquid_paper_network_invalid'),
};
```

- [ ] **Step 4: Run config plus historical regression tests**

Run:

```bash
XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Trading/Paper/Hyperliquid/HyperliquidPaperPublicConfigTest.php \
  tests/Trading/Paper/Hyperliquid/Http
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Trading/Paper/Hyperliquid \
  trading-app/tests/Trading/Paper/Hyperliquid
git commit -m "feat(paper): bind Hyperliquid public websocket networks"
```

## Task 2: Define the finite public subscription and outbound surface

**Files:**
- Create: `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperLivePolicy.php`
- Create: `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperLiveIntegrityException.php`
- Create: `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicSubscriptionSet.php`
- Test: `trading-app/tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicSubscriptionSetTest.php`

- [ ] **Step 1: Write failing tests for exact subscriptions and rejection**

Pin the exact canonical list:

```php
$set = new HyperliquidPaperPublicSubscriptionSet();
self::assertCount(12, $set->subscriptions());
self::assertSame(
    ['method' => 'subscribe', 'subscription' => ['type' => 'trades', 'coin' => 'BTC']],
    $set->subscriptions()[0],
);
self::assertSame(
    ['method' => 'subscribe', 'subscription' => [
        'type' => 'candle', 'coin' => 'ETH', 'interval' => '1h',
    ]],
    $set->subscriptions()[11],
);
```

For every forbidden type
`notification`, `webData3`, `openOrders`, `orderUpdates`, `userEvents`,
`userFills`, `userFundings`, `activeAssetData`, assert:

```php
$this->expectExceptionMessage('hyperliquid_paper_public_subscription_invalid');
$set->acknowledge([
    'method' => 'subscribe',
    'subscription' => ['type' => $type, 'user' => '0xsecret'],
]);
```

Also assert encoder rejection for methods `post`, `action`, `info`, unknown
keys, and wallet/user fields.

- [ ] **Step 2: Run and verify RED**

Run:

```bash
XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicSubscriptionSetTest.php
```

Expected: class-not-found failure.

- [ ] **Step 3: Implement the exact set**

Use:

```php
private const COINS = ['BTC', 'ETH'];
private const CANDLE_INTERVALS = ['1m', '5m', '15m', '1h'];
private const SIMPLE_TYPES = ['trades', 'l2Book'];
```

Build two simple subscriptions and four candle subscriptions per coin. Sort by
coin, then `trades`, `l2Book`, `1m`, `5m`, `15m`, `1h`. Require exact key sets
on acknowledgement and store canonical hashes in an acknowledged map.

Expose only:

```php
/** @return list<array<string, mixed>> */
public function subscriptions(): array;
/** @param array<array-key, mixed> $response */
public function acknowledge(array $response): void;
/** @param array<array-key, mixed> $message */
public static function assertOutbound(array $message): void;
public function isReady(): bool;
public function reset(): void;
```

Define policy constants:

```php
public const RECONNECT_DELAYS_SECONDS = [1.0, 2.0, 4.0, 8.0, 15.0, 30.0];
public const HEARTBEAT_IDLE_SECONDS = 45.0;
public const PONG_TIMEOUT_SECONDS = 10.0;
public const MAX_FRAME_BYTES = 1_048_576;
public const MAX_QUEUED_FRAMES = 256;
public const MAX_QUEUED_BYTES = 2_097_152;
public const MAX_BOOK_LEVELS_PER_SIDE = 500;
public const MAX_CHECKPOINT_BYTES = 1_048_576;
public const MAX_ACKNOWLEDGED_IDENTITIES_PER_STREAM = 500;
```

- [ ] **Step 4: Run and verify GREEN**

Run the Task 2 test file. Expected: all tests pass and every forbidden payload
fails without its contents appearing in the exception message.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Trading/Paper/Hyperliquid/Live \
  trading-app/tests/Trading/Paper/Hyperliquid/Live
git commit -m "feat(paper): restrict Hyperliquid public subscriptions"
```

## Task 3: Add the bounded transport, frame queue, and strict decoder

**Files:**
- Create: `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicWebSocketTransportInterface.php`
- Create: `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicWebSocketTransportFactoryInterface.php`
- Create: `trading-app/src/Trading/Paper/Hyperliquid/Live/PawlHyperliquidPaperPublicWebSocketTransport.php`
- Create: `trading-app/src/Trading/Paper/Hyperliquid/Live/PawlHyperliquidPaperPublicWebSocketTransportFactory.php`
- Create: `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicFrameQueue.php`
- Create: `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicFrameDecoder.php`
- Test: `trading-app/tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicWebSocketTransportTest.php`
- Test: `trading-app/tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicFrameDecoderTest.php`

- [ ] **Step 1: Write failing transport and decoder tests**

Assert one fresh transport per `create($loop, $config)`, exact URI binding,
no headers, no cached connector, stale-generation callback rejection, and
frame-size closure.

Pin decoder results for:

```json
{"channel":"subscriptionResponse","data":{"method":"subscribe","subscription":{"type":"trades","coin":"BTC"}}}
{"channel":"pong"}
{"channel":"trades","data":[{"coin":"BTC","side":"B","px":"65000","sz":"0.01","hash":"0xabc","time":1000,"tid":42,"users":["0xa","0xb"]}]}
{"channel":"l2Book","data":{"coin":"BTC","levels":[[{"px":"64999","sz":"1","n":2}],[{"px":"65001","sz":"2","n":3}]],"time":1001}}
{"channel":"candle","data":{"t":0,"T":59999,"s":"BTC","i":"1m","o":"1","c":"2","h":"3","l":"0.5","v":"4","n":5}}
```

Reject unknown channels, extra keys, user channels, malformed numeric strings,
crossed/empty books, more than 500 levels per side, oversized arrays, and any
raw secret value without reflecting it in errors.

- [ ] **Step 2: Run both tests and verify RED**

Run:

```bash
XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicWebSocketTransportTest.php \
  tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicFrameDecoderTest.php
```

Expected: class-not-found failures.

- [ ] **Step 3: Implement ports, adapter, queue, and decoder**

The transport interface is:

```php
interface HyperliquidPaperPublicWebSocketTransportInterface
{
    public function connect(
        \Closure $onOpen,
        \Closure $onMessage,
        \Closure $onClose,
        \Closure $onError,
    ): void;
    /** @param array<string, mixed> $message */
    public function send(array $message): void;
    public function close(): void;
}
```

The factory binds a validated config:

```php
public function create(
    LoopInterface $loop,
    HyperliquidPaperPublicConfig $config,
): HyperliquidPaperPublicWebSocketTransportInterface;
```

`send()` invokes `HyperliquidPaperPublicSubscriptionSet::assertOutbound()` and
accepts only an exact canonical subscription/unsubscription or the exact ping
map. Serialize with `CanonicalJson`; never accept a caller-supplied URI.

The queue copies the OKX bounds behavior with Hyperliquid policy constants and
the stable error `market_data_backpressure_exhausted`.

The decoder returns tagged arrays:

```php
['kind' => 'subscription', 'data' => $data]
['kind' => 'pong']
['kind' => 'trades', 'data' => $rows]
['kind' => 'book', 'data' => $book]
['kind' => 'candle', 'data' => $candle]
```

Use exact-key validators, bounded list traversal, and decimal-string
validation. Convert every JSON/shape failure to
`hyperliquid_paper_public_message_invalid`.

- [ ] **Step 4: Run Task 3 tests and PHPStan**

Run:

```bash
XDEBUG_MODE=off php vendor/bin/phpunit tests/Trading/Paper/Hyperliquid/Live \
  --filter 'SubscriptionSet|WebSocketTransport|FrameDecoder'
vendor/bin/phpstan analyze --no-progress --memory-limit=2G \
  src/Trading/Paper/Hyperliquid/Live \
  tests/Trading/Paper/Hyperliquid/Live
```

Expected: all selected tests pass and PHPStan reports no errors.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Trading/Paper/Hyperliquid/Live \
  trading-app/tests/Trading/Paper/Hyperliquid/Live
git commit -m "feat(paper): decode Hyperliquid public websocket frames"
```

## Task 4: Normalize deterministic live trades, books, and closed candles

**Files:**
- Modify: `trading-app/src/Trading/Paper/Hyperliquid/Normalization/HyperliquidPaperMarketEventNormalizer.php`
- Test: `trading-app/tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperMarketEventLiveNormalizerTest.php`

- [ ] **Step 1: Write failing normalization tests**

For mainnet and testnet, assert:

```php
$event = $normalizer->liveTrade([
    'coin' => 'BTC', 'side' => 'B', 'px' => '65000', 'sz' => '0.01',
    'hash' => '0xabc', 'time' => 1_000, 'tid' => 42,
    'users' => ['0xa', '0xb'],
]);
self::assertSame(PaperMarketDataChannel::PUBLIC_TRADE, $event->channel);
self::assertSame('42', $event->payload['trade_id']);
self::assertArrayNotHasKey('users', $event->payload);
```

Recreate with a fresh normalizer and assert the same `eventId`; change only
network and assert a different `eventId`.

For books, pass unsorted valid levels and assert maximum bid/minimum ask,
`synthetic=false`, and `origin=ws_l2_book`. For candles, assert only an
explicit `closedLiveCandle()` method exists and emits
`origin=ws_candle`, `confirmed=true`. Pin connection and snapshot boundary
payloads with epochs and injected-clock receipt time.

- [ ] **Step 2: Run and verify RED**

Run the new normalizer test. Expected: undefined-method failures.

- [ ] **Step 3: Implement the live methods**

Add constructor injection of an optional `ClockInterface`, defaulting only for
historical callers to exchange timestamp behavior. The live factory must pass
its injected clock.

Use these natural identities:

```php
implode('|', [$this->network->value, $coin, (string) $time, (string) $tid]);
implode('|', [$this->network->value, $coin, 'book', (string) $time, $bookHash]);
implode('|', [
    $this->network->value, $coin, $interval,
    (string) $startTime, (string) $closeTime,
]);
```

Expose:

```php
public function liveTrade(array $row): PaperMarketEvent;
public function liveTopOfBook(array $book, int $sourceEpoch): PaperMarketEvent;
public function closedLiveCandle(HyperliquidCandle $candle): PaperMarketEvent;
public function connectionState(string $coin, string $state, int $epoch): PaperMarketEvent;
public function snapshotBoundary(string $coin, string $reason, int $epoch): PaperMarketEvent;
```

Keep historical payloads and event IDs unchanged.

- [ ] **Step 4: Run normalizer plus historical dataset regressions**

Run:

```bash
XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperMarketEventLiveNormalizerTest.php \
  tests/Trading/Paper/Hyperliquid/Normalization \
  tests/Trading/Paper/Hyperliquid/HyperliquidHistoricalDatasetTest.php
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Trading/Paper/Hyperliquid/Normalization \
  trading-app/tests/Trading/Paper/Hyperliquid
git commit -m "feat(paper): normalize Hyperliquid public live events"
```

## Task 5: Persist authoritative checkpoint and candle finalization state

**Files:**
- Create: `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperLiveCheckpoint.php`
- Create: `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperLiveCheckpointStore.php`
- Test: `trading-app/tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperLiveCheckpointStoreTest.php`

- [ ] **Step 1: Write failing checkpoint tests**

Test fresh round-trip, atomic save/reload, exact pending-event replay,
acknowledgement, current-candle replacement, finalized frontier advancement,
network/config mismatch, duplicate conflict, truncated JSON, checksum
corruption, symlink/non-regular files, oversized state, invalid phase, invalid
epoch, excessive identity history, and stale temp-file cleanup.

Pin the top-level state keys:

```php
self::assertSame([
    'schema_version', 'policy_version', 'dataset_id', 'network',
    'configuration_sha256', 'phase', 'failure_reason', 'continuity',
    'connection_epoch', 'source_epoch', 'subscriptions',
    'ordinal_state', 'pending_event', 'pending_continuation',
    'current_candles', 'finalized_candle_frontiers',
    'acknowledged_identities', 'reconnect_attempt',
    'heartbeat', 'healthy_stop',
], array_keys($checkpoint->toArray()));
```

- [ ] **Step 2: Run and verify RED**

Run the checkpoint test. Expected: class-not-found failure.

- [ ] **Step 3: Implement typed state and atomic store**

Use:

```php
public const SCHEMA_VERSION = 1;
public const POLICY_VERSION = 1;
```

`fresh()` requires dataset ID, certifiable network, and 64-character
configuration hash. `fromArray()` enforces exact keys and all finite bounds.
The store writes canonical JSON plus SHA-256 checksum to
`checkpoints/hyperliquid-live.json` using the existing
`PaperDatasetRecorderFilesystem` atomic-write primitives and rejects symlinks,
directories, non-regular files, and states over 1 MiB.

Implement immutable transitions:

```php
public function withPending(PaperMarketEvent $event, array $continuation): self;
public function acknowledge(string $eventId): self;
public function withCurrentCandle(string $stream, array $candle): self;
public function finalizeCandle(string $stream, int $startTime): self;
public function loseContinuity(string $reason): self;
```

- [ ] **Step 4: Run checkpoint tests and corruption matrix**

Run the full checkpoint test file. Expected: all cases pass, stable reasons do
not expose fixture contents, and no temp file remains.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Trading/Paper/Hyperliquid/Live \
  trading-app/tests/Trading/Paper/Hyperliquid/Live
git commit -m "feat(paper): checkpoint Hyperliquid public live capture"
```

## Task 6: Build the acknowledged source happy path

**Files:**
- Create: `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicLiveSource.php`
- Test: `trading-app/tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicLiveSourceTest.php`

- [ ] **Step 1: Write failing source happy-path tests**

With a deterministic fake transport and loop, assert:

1. acquisition disabled fails before `connect()`;
2. network/config/checkpoint mismatch fails before `connect()`;
3. open sends exactly twelve subscriptions;
4. no market event is accepted before all twelve acknowledgements;
5. trade arrays yield one event per row in server order;
6. each book snapshot yields one real `TOP_OF_BOOK`;
7. first candle update is held; a later start emits the previous closed candle;
8. a yielded event is persisted as pending before yield;
9. failure to acknowledge before generator advance raises
   `hyperliquid_acquisition_pending_event_not_acknowledged`;
10. correct acknowledgement clears pending and advances the frontier;
11. healthy stop emits no still-forming candle and completes only with an empty
    queue and no pending event.

- [ ] **Step 2: Run and verify RED**

Run:

```bash
XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicLiveSourceTest.php \
  --filter 'Happy|Subscription|Trade|Book|Candle|Acknowledge|Healthy'
```

Expected: class-not-found failure.

- [ ] **Step 3: Implement the minimal state machine**

Implement `PaperLiveMarketDataSourceInterface` with phases:

```text
fresh -> connecting -> subscribing -> streaming -> stopping -> complete
                                      \-> failed
```

Processing order is:

```php
$queue->enqueue($rawFrame);
$decoded = $decoder->decode($queue->peek());
$candidateEvents = $this->normalize($decoded);
$checkpoint = $store->savePending($checkpoint, $candidateEvent, $continuation);
yield $candidateEvent;
// acknowledge() persists continuation, then dequeue/advance is allowed
```

When a newer candle start arrives, persist the newer candle as current and the
older candle as pending in one checkpoint transition before yielding the older
event. Never emit the current candle during stop.

Expose only the inherited source methods plus:

```php
public function requestHealthyOperatorStop(): void;
public function failureReason(): ?string;
```

- [ ] **Step 4: Run the happy-path tests**

Run the selected source tests and all Hyperliquid live tests. Expected: pass.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Trading/Paper/Hyperliquid/Live \
  trading-app/tests/Trading/Paper/Hyperliquid/Live
git commit -m "feat(paper): capture Hyperliquid public live events"
```

## Task 7: Add heartbeat, reconnect, backpressure, restart, and fail-closed continuity

**Files:**
- Modify: `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicLiveSource.php`
- Modify: `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperLiveCheckpoint.php`
- Modify: `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperLiveCheckpointStore.php`
- Test: `trading-app/tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicLiveSourceTest.php`

- [ ] **Step 1: Write failing resilience tests**

Cover:

- exact ping at 45 seconds of outbound idle and exact pong acceptance;
- pong timeout at 10 seconds;
- reconnect delays `1,2,4,8,15,30`;
- finite exhaustion with `hyperliquid_paper_public_reconnect_exhausted`;
- stale callbacks from prior generations ignored;
- resubscription requires all twelve new acknowledgements;
- initial/reconnect snapshot boundary before book data;
- oversized frame and queue byte/count exhaustion;
- crash with pending event re-yields identical `eventId` and payload;
- crash after acknowledgement never re-yields the event;
- out-of-order/conflicting trade and finalized-candle identities fail closed;
- disconnect or crash after streaming sets `continuity=false`;
- a continuity-lost dataset reconnects for diagnostics but cannot return
  `isComplete=true`;
- stable public exception/checkpoint reasons contain no URI, frame, hash,
  wallet-like value, or transport message.

- [ ] **Step 2: Run the resilience filter and verify RED**

Run:

```bash
XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicLiveSourceTest.php \
  --filter 'Heartbeat|Reconnect|Generation|Backpressure|Restart|Continuity|Redact'
```

Expected: failing assertions for missing timers/transitions.

- [ ] **Step 3: Implement bounded resilience**

Track generation IDs in every callback:

```php
if ($generation !== $this->activeGeneration) {
    return;
}
```

Checkpoint reconnect attempt before scheduling its delay. On any disconnect
after entering `streaming`, call:

```php
$this->checkpoint = $this->checkpointStore->save(
    $this->checkpoint->loseContinuity('hyperliquid_public_trade_gap_unrecoverable'),
);
```

Ping only with `['method' => 'ping']`. A non-pong response does not satisfy the
deadline. Cancel all generation timers before reconnect/stop. On frame/queue
failure, close the active generation, persist the stable terminal reason, and
throw `HyperliquidPaperLiveIntegrityException`.

- [ ] **Step 4: Run the entire source and checkpoint suites**

Run:

```bash
XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicLiveSourceTest.php \
  tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperLiveCheckpointStoreTest.php
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Trading/Paper/Hyperliquid/Live \
  trading-app/tests/Trading/Paper/Hyperliquid/Live
git commit -m "feat(paper): recover Hyperliquid public live capture"
```

## Task 8: Add the pinned factory and public-only dependency graph

**Files:**
- Create: `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicLiveSourceFactory.php`
- Modify: `trading-app/config/services.yaml`
- Test: `trading-app/tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicLiveSourceFactoryTest.php`
- Test: `trading-app/tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicServiceWiringTest.php`

- [ ] **Step 1: Write failing factory and graph tests**

For both networks, create local recording manifests and assert the factory:

- derives config from manifest network;
- selects the exact corresponding WebSocket URI;
- verifies venue Hyperliquid, quality
  `recorded_public_book_and_trades`, exact symbol mapping, recording state,
  network-scoped directory name, and canonical manifest bytes;
- rejects cross-network checkpoint/config and symlink swaps;
- creates a fresh transport/queue/subscription set per call.

Recursively audit the resolved runtime graph and fail if a class/property/method
matches:

```php
$forbidden = '/credential|api.?key|secret|wallet|sign|private|account|order|fill|funding|execution|exchange.?action|fakeexchange/i';
```

Also assert source resolution and factory creation with acquisition disabled
make zero connection attempts.

- [ ] **Step 2: Run and verify RED**

Run the factory and wiring tests. Expected: missing class/service failures.

- [ ] **Step 3: Implement factory and services**

The factory constructor receives only:

```php
HyperliquidPaperPublicConfigFactory
HyperliquidPaperPublicWebSocketTransportFactoryInterface
ClockInterface
PaperDatasetManifestCodec
PaperDatasetRecorderFilesystem
```

`create(datasetDirectory, ?LoopInterface)` pins the directory and manifest,
derives the validated network config, computes a canonical configuration hash
over schema/policy versions, network, endpoint, exact subscriptions, symbols,
and finite limits, loads the matching checkpoint, creates a fresh transport,
then constructs the source.

Wire only the public interfaces in `services.yaml`; do not expose the source as
an autowired singleton.

- [ ] **Step 4: Run factory, wiring, and container lint**

Run:

```bash
XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicLiveSourceFactoryTest.php \
  tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicServiceWiringTest.php
php -d 'error_reporting=E_ALL & ~E_DEPRECATED' bin/console lint:container
php bin/console lint:yaml config --parse-tags
```

Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Trading/Paper/Hyperliquid/Live \
  trading-app/tests/Trading/Paper/Hyperliquid/Live \
  trading-app/config/services.yaml
git commit -m "feat(paper): wire Hyperliquid public live source"
```

## Task 9: Certify complete captures and prove capture/replay equality

**Files:**
- Modify: `trading-app/src/Trading/Paper/Dataset/PaperDatasetVerifier.php`
- Create: `trading-app/tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperLiveCaptureReplayEqualityTest.php`
- Modify: `trading-app/tests/Trading/Paper/Dataset/PaperDatasetVerifierTest.php`

- [ ] **Step 1: Write failing end-to-end tests**

Build deterministic mainnet and testnet fixture captures through
`PaperLiveDatasetCapture`, then assert:

```php
self::assertSame(PaperDatasetState::COMPLETE, $manifest->state);
self::assertSame(
    PaperMarketDataQuality::RECORDED_PUBLIC_BOOK_AND_TRADES,
    $manifest->quality,
);
self::assertSame($capturedArrays, $replayedArrays);
self::assertSame($capturedIds, $replayedIds);
```

Mutate one fact at a time and require verifier rejection:

- manifest/event/checkpoint network mismatch;
- mixed networks in one event file;
- synthetic or historical-model book;
- trade `tid`, coin, time, or recomputed identity mismatch;
- mutable/unconfirmed candle;
- candle frontier regression;
- missing snapshot boundary;
- pending continuation;
- continuity false;
- duplicate payload conflict;
- checksum corruption;
- incomplete dataset requested as baseline.

Add crash harnesses at append, consumer effect, and acknowledgement boundaries;
assert exactly-once consumer effects and final capture/replay equality.

- [ ] **Step 2: Run and verify RED**

Run:

```bash
DATABASE_URL='postgresql://postgres:password@127.0.0.1:5436/tradingv3_paper_test?serverVersion=15&charset=utf8' \
XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperLiveCaptureReplayEqualityTest.php \
  tests/Trading/Paper/Dataset/PaperDatasetVerifierTest.php \
  --filter 'Hyperliquid.*Live'
```

Expected: verifier accepts unsupported mutations or lacks live rules.

- [ ] **Step 3: Implement independent live verification**

For `HYPERLIQUID + RECORDED_PUBLIC_BOOK_AND_TRADES`, independently recompute:

- allowed network and channels;
- exact native symbol mapping;
- real book constraints and best bid/ask;
- trade natural identity using network/coin/time/`tid`;
- closed-candle identity and monotonic frontier;
- operational epoch/boundary ordering;
- absence of historical model fields and `synthetic=true`;
- no unexplained sequence gap or duplicate conflict.

Do not trust checkpoint-derived hashes as the only witness. Compare checkpoint
terminal facts with independently scanned events and reject any pending state
or continuity loss.

- [ ] **Step 4: Run full Hyperliquid and generic live suites**

Run:

```bash
DATABASE_URL='postgresql://postgres:password@127.0.0.1:5436/tradingv3_paper_test?serverVersion=15&charset=utf8' \
XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Trading/Paper/Hyperliquid \
  tests/Trading/Paper/Dataset/PaperLiveDatasetCaptureTest.php \
  tests/Trading/Paper/Replay
```

Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Trading/Paper/Dataset/PaperDatasetVerifier.php \
  trading-app/tests/Trading/Paper
git commit -m "test(paper): certify Hyperliquid public live replay"
```

## Task 10: Update canonical documentation and deliver one reviewed PR

**Files:**
- Modify: `TradingV3_OKX_Hyperliquid_demo_testnet_prompts_canoniques_UNIQUE.md`
- Create: `docs/handbook/technical/paper-market-data-datasets.md`

- [ ] **Step 1: Update documentation without changing live-trading readiness**

Record:

- PR2 historical and PR3 public live delivery state;
- exact mainnet/testnet public WebSocket endpoints;
- exact twelve subscriptions;
- continuity-loss/incomplete rule;
- acquisition disabled by default;
- no credentials, actions, trading-live readiness, or BitMart #305 change;
- next lot remains the Fake-only Paper execution coordinator.

Run `rg` to prove permanent Hyperliquid trading-live guards are unchanged.

- [ ] **Step 2: Run the single full local validation pass**

Run:

```bash
cd trading-app
DATABASE_URL='postgresql://postgres:password@127.0.0.1:5436/tradingv3_paper_test?serverVersion=15&charset=utf8' \
XDEBUG_MODE=off php -d memory_limit=2G vendor/bin/phpunit \
  tests/Trading/Paper --stop-on-error --stop-on-failure

git diff --name-only origin/main...HEAD |
  rg '^trading-app/(src|tests)/.*\.php$' |
  sed 's#^trading-app/##' |
  xargs vendor/bin/phpstan analyze --no-progress --memory-limit=2G

php -d 'error_reporting=E_ALL & ~E_DEPRECATED' bin/console lint:container
php bin/console lint:yaml config --parse-tags
cd ..
python3 -m mkdocs build --strict
git diff --check
```

Expected: PHPUnit has zero failures/errors, PHPStan has no errors, all lints
pass, MkDocs strict builds, and `git diff --check` emits nothing.

- [ ] **Step 3: Perform the safety and scope audit**

Run:

```bash
git diff --name-only origin/main...HEAD
git diff origin/main...HEAD -- trading-app/src/Trading/Paper/Hyperliquid
rg -n -i 'credential|api.?key|secret|wallet|sign|private|post|action|order|fill|funding|execution' \
  trading-app/src/Trading/Paper/Hyperliquid/Live \
  trading-app/config/services.yaml
git status --short
```

Expected: forbidden terms occur only in explicit rejection/audit tests or
stable documentation; no execution/account dependency or BitMart file changed;
the only untracked pre-existing path is `trading-app/vendor`.

- [ ] **Step 4: Commit documentation and push once**

```bash
git add TradingV3_OKX_Hyperliquid_demo_testnet_prompts_canoniques_UNIQUE.md \
  docs/handbook \
  docs/superpowers
git commit -m "docs(paper): document Hyperliquid public live capture"
git push -u origin issue/132-hyperliquid-public-live
```

- [ ] **Step 5: Open one PR and request one Codex review**

Create a PR titled:

```text
feat(paper): add Hyperliquid public live capture
```

The body must summarize network isolation, public-only protocol surface,
continuity fail-closed behavior, capture/replay equality, validation counts,
and unchanged trading-live/BitMart guards. Post exactly one comment:

```text
@codex review
```

Address every actionable inline thread, reply in-thread, resolve it, push the
fixes, wait for CI on the final exact HEAD, verify zero unresolved
non-outdated threads, and merge without requesting another review.
