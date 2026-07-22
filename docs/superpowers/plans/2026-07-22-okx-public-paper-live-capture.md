# OKX Public Paper Live Capture Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a deterministic, credential-free OKX public-market WebSocket source that warms BTC/ETH from the existing public REST client, receives trades/books through the OKX Public socket and candles through the OKX Business socket, durably records every accepted normalized event before downstream use, recovers boundedly from book gaps and disconnects, and proves live-fixture/replay event-sequence equality.

**Architecture:** Keep the existing exchange-neutral `PaperMarketEvent`, acknowledged-source, ordinal, recorder, verifier, and replay contracts as the durable core. Add a small OKX-public live stack split into two fresh per-source transports, explicit Public/Business subscription routing, strict socket-aware protocol validation, one bounded raw-frame queue per socket, full-book materialization, an authoritative durable state-machine checkpoint, acknowledged stream frontiers, and orchestration. The Public socket is fixed to `wss://ws.okx.com:8443/ws/v5/public` for trades/books; the Business socket is fixed to `wss://ws.okx.com:8443/ws/v5/business` solely for credential-free public candles. Copy the proven generation/timer shape of the private Pawl worker without sharing any private endpoint, login, credential, header, status-store, or mutation dependency. A generic `PaperLiveDatasetCapture` owns the record-before-idempotent-effect boundary and is the only component allowed to complete or mark the manifest incomplete.

**Tech Stack:** PHP 8.2, Symfony 7.1 Clock/Dependency Injection/HTTP Client, ReactPHP event loop, Ratchet Pawl 0.4, Brick Math, PHPUnit 11, PHPStan, NDJSON/SHA-256 Paper datasets.

---

## Scope Boundaries

This is the remaining live-capture sub-plan of the approved OKX public-source lot. It builds on commits through `9b30db96` and reuses:

- `PaperMarketEvent` and `PaperMarketEventRedactor` for the normalized, redacted event contract;
- `AcknowledgedPaperMarketDataSourceInterface` for pending-event acknowledgement;
- `PaperDatasetRecorder` for durable append, exact replay detection, identity conflict detection, sequence-gap accounting, crash-tail recovery, incomplete state, and explicit completion;
- `PaperReplayReader` for the equality proof;
- `OkxPaperPublicConfig` for the exact REST, Public WebSocket, and credential-free Business WebSocket URI allowlists;
- `OkxPaperPublicRestClientInterface` for current candles, recent trades, and REST order-book snapshots;
- `OkxPaperMarketEventNormalizer`, `OkxPaperSourceOrdinal`, `OkxMaterializedBookState`, and `OkxPaperInstrumentMap` for all accepted market events;
- the callback-generation, delayed-connection close, deterministic timer, heartbeat, and reconnect patterns from `PawlOkxPrivateWebSocketTransport` and `OkxPrivateWebSocketWorker`, but none of their authenticated dependencies.

The implementation is limited to public OKX BTC/ETH acquisition and durable local recording. It does not add an operator command, web endpoint, controller, Messenger handler, execution coordinator, provider integration, database write, exchange account read, credential, login/signature, simulated-trading header, private WebSocket path, or exchange mutation. The Business WebSocket path is allowed only at the exact canonical URI and only as the credential-free public candles transport; no authenticated or private Business operation enters scope. It does not change strategy, MTF, indicators, EntryZone, sizing, leverage, Risk, SL/TP, live guards, Fake execution, or any YAML strategy profile. CI uses only fakes and checked-in fixtures; a real public-network smoke test remains opt-in and is not part of the required gates.

## Existing Contracts That Must Remain Stable

- Keep `PaperMarketDataSourceInterface::events(): iterable` and `AcknowledgedPaperMarketDataSourceInterface::{acknowledge(),stop(),isComplete()}` unchanged.
- Keep `PaperDatasetRecorder::append()` as the authority for `APPENDED`, `REPLAYED`, `market_event_identity_conflict`, `market_event_out_of_order`, and manifest sequence-gap counts.
- Keep `OkxPaperPublicConfig::WEB_SOCKET_URI` equal to `wss://ws.okx.com:8443/ws/v5/public` and `OkxPaperPublicConfig::BUSINESS_WEB_SOCKET_URI` equal to `wss://ws.okx.com:8443/ws/v5/business`. The Public transport receives only `$config->webSocketUri`; the Business transport receives only `$config->businessWebSocketUri`; both values reach a transport only after exact constructor allowlist validation.
- Keep `OkxPaperPublicRestClientInterface` credential-free and restricted to its five existing `GET` methods. Initial capture uses `currentCandles()`, `recentTrades()`, and `orderBook()`; reconnect may additionally use the existing `historyCandles()`/`historyTrades()` pagination solely to prove bounded exact overlap when the acknowledged frontier is absent from current/recent results.
- Preserve the existing public normalizer constructor named arguments (`clock`, `instruments`, `ordinals`) and existing normalized payload fields. New live methods must use the same injected `OkxPaperSourceOrdinal` instance restored from the live checkpoint.
- Keep `PaperLiveMarketDataSourceInterface` as a dedicated live-only contract; do not fold healthy-stop or failure state into the historical or generic source interfaces.
- A source `stop()` is an unhealthy/emergency stop and must leave the dataset incomplete. Only the new explicit `requestHealthyOperatorStop()` method may make `isComplete()` true, and only after both socket queues are drained, no event is pending acknowledgement, Public readiness is 4/4, Business readiness is 8/8, both sockets are fresh, and the checkpointed stop continuation has acknowledged both final symbol events.
- Detect a sequential gap only for the `books` stream, where OKX `seqId`/`prevSeqId` proves continuity. Trades and candles have no invented sequence: recovery is allowed only from exact acknowledged `{source_identity,natural_identity,canonical_digest}` overlap, and failure to prove that overlap is fatal `market_data_gap_unresolved`.

## Proposed Public Signatures

These signatures lock the types used throughout the tasks:

```php
interface OkxPaperPublicWebSocketTransportInterface
{
    public function connect(
        string $uri,
        callable $onOpen,
        callable $onMessage,
        callable $onClose,
        callable $onError,
    ): void;

    /** @param array<string, mixed> $message */
    public function send(array $message): void;

    public function close(): void;
}

interface OkxPaperPublicWebSocketTransportFactoryInterface
{
    public function create(LoopInterface $loop): OkxPaperPublicWebSocketTransportInterface;
}

final readonly class OkxPaperPublicConfig
{
    public const REST_BASE_URI = 'https://www.okx.com';
    public const WEB_SOCKET_URI = 'wss://ws.okx.com:8443/ws/v5/public';
    public const BUSINESS_WEB_SOCKET_URI = 'wss://ws.okx.com:8443/ws/v5/business';

    public function __construct(
        public bool $acquisitionEnabled,
        public string $restBaseUri,
        public string $webSocketUri,
        public string $dataRoot,
        public string $businessWebSocketUri = self::BUSINESS_WEB_SOCKET_URI,
    );
}

final class OkxPaperPublicSubscriptionSet
{
    /** @return list<array{channel: string, instId: string}> */
    public function publicArguments(): array;
    /** @return list<array{channel: string, instId: string}> */
    public function businessArguments(): array;
    /** @param array<array-key, mixed> $arg */
    public function acknowledgePublic(array $arg): void;
    /** @param array<array-key, mixed> $arg */
    public function acknowledgeBusiness(array $arg): void;
    public function isPublicRequired(string $channel, string $instrumentId): bool;
    public function isBusinessRequired(string $channel, string $instrumentId): bool;
    public function isPublicReady(): bool;
    public function isBusinessReady(): bool;
    public function isReady(): bool;
    public function reset(): void;
}

final readonly class OkxPaperPublicFrameDecoder
{
    /** @return array<string, mixed> */
    public function decodePublic(#[\SensitiveParameter] string $frame): array;
    /** @return array<string, mixed> */
    public function decodeBusiness(#[\SensitiveParameter] string $frame): array;
}

final readonly class OkxPaperLivePolicy
{
    public const RECONNECT_DELAYS_SECONDS = [1.0, 2.0, 4.0, 8.0, 15.0, 30.0];
    public const HEARTBEAT_IDLE_SECONDS = 20.0;
    public const PONG_TIMEOUT_SECONDS = 10.0;
    public const MAX_FRAME_BYTES = 1_048_576;
    public const MAX_QUEUED_FRAMES = 256;
    public const MAX_QUEUED_BYTES = 2_097_152;
    public const MAX_RESYNC_ATTEMPTS = 3;
    public const RESYNC_ATTEMPT_TIMEOUT_SECONDS = 10.0;
    public const MAX_OVERLAP_HISTORY_PAGES = 10;
    public const RECONNECT_STABLE_SECONDS = 30.0;
    public const RECONNECT_STABLE_ACCEPTED_EVENTS = 12;
}

enum OkxPaperBookDeltaStatus: string
{
    case APPLIED = 'applied';
    case REPLAYED = 'replayed';
}

final readonly class OkxPaperBookDeltaResult
{
    public static function applied(OkxMaterializedBookState $state): self;
    public static function replayed(): self;
    public function status(): OkxPaperBookDeltaStatus;
    public function materializedState(): OkxMaterializedBookState;
}

final class OkxPaperOrderBookMaterializer
{
    /** @param array<array-key, mixed> $snapshot */
    public function replaceSnapshot(array $snapshot): OkxMaterializedBookState;

    /** @param array<array-key, mixed> $delta */
    public function applyDelta(array $delta): OkxPaperBookDeltaResult;

    public function sourceSequence(): ?string;
}

interface PaperLiveMarketDataSourceInterface extends AcknowledgedPaperMarketDataSourceInterface
{
    public function requestHealthyOperatorStop(): void;
    public function failureReason(): ?string;
}

interface PaperLiveEventConsumerInterface
{
    public function consume(string $datasetId, PaperMarketEvent $event): void;
}

final class OkxPaperPublicLiveSource implements PaperLiveMarketDataSourceInterface
{
    public function venue(): PaperMarketDataVenue;
    /** @return iterable<PaperMarketEvent> */
    public function events(): iterable;
    public function acknowledge(string $eventId): void;
    public function stop(): void;
    public function requestHealthyOperatorStop(): void;
    public function isComplete(): bool;
    public function failureReason(): ?string;
}

final readonly class OkxPaperPublicLiveSourceFactory
{
    public function create(
        string $datasetDirectory,
        ?LoopInterface $loop = null,
    ): OkxPaperPublicLiveSource;
}

final class PaperLiveDatasetCapture
{
    public function run(
        PaperDatasetRecorder $recorder,
        PaperLiveMarketDataSourceInterface $source,
        PaperLiveEventConsumerInterface $consumer,
    ): PaperDatasetManifest;
}
```

`PaperLiveDatasetCapture` invokes `consume()` after both `APPENDED` and `REPLAYED`, after the NDJSON line/recording manifest are durable and before source acknowledgement. The consumer contract is idempotent: the authoritative downstream key is `(dataset_id,event_id)`, and the consumer must transactionally persist that key with `payload_hash` and its effect before returning. An exact key/hash retry is a no-op success; the same key with another hash is `market_event_identity_conflict`. Therefore a crash after append but before effect re-enters the consumer through recorder `REPLAYED`, while a crash after committed effect but before source acknowledgement re-enters the same key and cannot apply the effect twice. This sub-plan adds only this generic port and deterministic fake consumer; the later Paper-execution lot supplies the business implementation and its checkpoint, so no strategy or database effect enters this scope.

## File Map

Create focused runtime units under `trading-app/src/Trading/Paper/Okx/Live/`:

- `OkxPaperPublicWebSocketTransportInterface.php`: injectable credential-free callback transport port used only with a config-validated Public or Business URI.
- `OkxPaperPublicWebSocketTransportFactoryInterface.php`: creates a fresh credential-free transport bound to one supplied `LoopInterface`; the live-source factory calls it twice per source.
- `PawlOkxPaperPublicWebSocketTransportFactory.php`: constructs one fresh Pawl connector/transport per `create()` with the exact loop used by its source.
- `PawlOkxPaperPublicWebSocketTransport.php`: per-session Pawl adapter with stale-generation suppression, literal ping frames, bounded frame admission, and delayed-connection close.
- `OkxPaperLivePolicy.php`: one location for finite reconnect, heartbeat, frame/queue, and resync limits.
- `OkxPaperPublicSubscriptionSet.php`: deterministic split allowlist and acknowledgement validation: four Public trades/books pairs plus eight Business candle pairs, with total readiness only at 4+8 exact ACKs.
- `OkxPaperPublicFrameDecoder.php`: bounded strict socket-aware decoding of `pong`, realistic subscribe/error controls, Public trades/books, and Business candles.
- `OkxPaperPublicFrameQueue.php`: FIFO count/byte backpressure boundary; the source owns one fresh instance per socket so raw origin cannot be lost.
- `OkxPaperBookDeltaStatus.php` and `OkxPaperBookDeltaResult.php`: explicit `APPLIED`/`REPLAYED` delta outcome without a nullable book state.
- `OkxPaperOrderBookMaterializer.php`: full four-field raw bid/ask state, zero-size deletion, REST/WS snapshot replacement, and strict `prevSeqId` continuity.
- `OkxPaperStreamFrontier.php`: validated acknowledged `{source_identity,natural_identity,canonical_digest}` evidence shared by REST/WS recovery.
- `OkxPaperLiveCheckpoint.php`: typed authoritative checkpoint containing dataset/config identity, phase and pending continuation, remaining symbols/boundaries, acknowledged stream frontiers, per-symbol source epochs, ordinal state, exact pending event/frontier, healthy-stop progress, reconnect stability/budget, and per-symbol resync budget/deadline/evidence.
- `OkxPaperLiveCheckpointStore.php`: private atomic checkpoint persistence below the dataset's existing `checkpoints/` directory.
- `OkxPaperLiveIntegrityException.php`: stable public-source failure codes without raw payload/error text.
- `OkxPaperPublicLiveSource.php`: acknowledged source state machine coordinating warm-up, two transports/queues, exact 4+8 subscription readiness, normalization, resync, heartbeat, reconnect, and clean stop.
- `OkxPaperPublicLiveSourceFactory.php`: runtime factory that validates the stored manifest, computes the canonical config hash, loads the matching checkpoint, and creates two fresh loop-bound transport sessions plus one source for a dataset directory without making scalar-dependent runtime objects autowired singletons.

Create one generic durable bridge:

- `trading-app/src/Trading/Paper/MarketData/PaperLiveMarketDataSourceInterface.php`: live-only healthy-stop and terminal-failure contract extending the unchanged acknowledged-source interface.
- `trading-app/src/Trading/Paper/Dataset/PaperLiveEventConsumerInterface.php`: idempotent downstream port keyed authoritatively by dataset/event identity.
- `trading-app/src/Trading/Paper/Dataset/PaperLiveDatasetCapture.php`: append-before-idempotent-consume for both append outcomes, acknowledgement, cleanup-safe incomplete-on-error/abnormal-end, and healthy-operator-only completion.

Modify existing code only where the established public contract needs a live entry point:

- `trading-app/src/Trading/Paper/Okx/Normalization/OkxPaperMarketEventNormalizer.php`: add WS candle/control-event methods and an explicit allowlisted top-of-book origin while retaining existing signatures.
- `trading-app/config/services.yaml`: public transport/factory wiring and exclusion of runtime-created scalar-dependent source/store classes from autowiring.

Create deterministic tests and small public fixtures:

- `trading-app/tests/Trading/Paper/Okx/Live/FakeOkxPaperPublicWebSocketTransport.php`
- `trading-app/tests/Trading/Paper/Okx/Live/DeterministicLoop.php`
- `trading-app/tests/Trading/Paper/Okx/Live/OkxPaperPublicWebSocketTransportTest.php`
- `trading-app/tests/Trading/Paper/Okx/Live/OkxPaperPublicProtocolTest.php`
- `trading-app/tests/Trading/Paper/Okx/Live/OkxPaperOrderBookMaterializerTest.php`
- `trading-app/tests/Trading/Paper/Okx/Live/OkxPaperLiveCheckpointStoreTest.php`
- `trading-app/tests/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceTest.php`
- `trading-app/tests/Trading/Paper/Okx/Live/OkxPaperLiveCaptureReplayEqualityTest.php`
- `trading-app/tests/Trading/Paper/Dataset/PaperLiveDatasetCaptureTest.php`
- `trading-app/tests/Fixtures/OkxPaperPublic/ws-candle-1m.json`
- `trading-app/tests/Fixtures/OkxPaperPublic/ws-candle-5m.json`
- `trading-app/tests/Fixtures/OkxPaperPublic/ws-candle-15m.json`
- `trading-app/tests/Fixtures/OkxPaperPublic/ws-candle-1H.json`
- `trading-app/tests/Fixtures/OkxPaperPublic/ws-books-gap.json`

Modify these existing contract tests rather than adding overlapping scanners:

- `trading-app/tests/Trading/Paper/Okx/Normalization/OkxPaperMarketEventNormalizerTest.php`
- `trading-app/tests/Trading/Paper/Okx/Http/OkxPaperPublicServiceWiringTest.php`
- `trading-app/tests/Trading/Paper/PaperFixtureContractTest.php`

### Task 1: Credential-Free Public-Market Pawl Transport and Deterministic CI Fake

**Files:**
- Create: `trading-app/src/Trading/Paper/Okx/Live/OkxPaperPublicWebSocketTransportInterface.php`
- Create: `trading-app/src/Trading/Paper/Okx/Live/OkxPaperPublicWebSocketTransportFactoryInterface.php`
- Create: `trading-app/src/Trading/Paper/Okx/Live/PawlOkxPaperPublicWebSocketTransportFactory.php`
- Create: `trading-app/src/Trading/Paper/Okx/Live/PawlOkxPaperPublicWebSocketTransport.php`
- Create: `trading-app/src/Trading/Paper/Okx/Live/OkxPaperLivePolicy.php`
- Create: `trading-app/src/Trading/Paper/Okx/Live/OkxPaperLiveIntegrityException.php`
- Create: `trading-app/tests/Trading/Paper/Okx/Live/FakeOkxPaperPublicWebSocketTransport.php`
- Create: `trading-app/tests/Trading/Paper/Okx/Live/DeterministicLoop.php`
- Test: `trading-app/tests/Trading/Paper/Okx/Live/OkxPaperPublicWebSocketTransportTest.php`

- [ ] **Step 1: Write the failing transport contract tests**

Cover these exact cases:

```php
$connection = new FakePawlPublicConnection();
$transport = new PawlOkxPaperPublicWebSocketTransport(
    loop: $loop,
    connector: static fn (string $uri): PromiseInterface => resolve($connection),
);
$opened = false;
$transport->connect(
    OkxPaperPublicConfig::WEB_SOCKET_URI,
    static function () use (&$opened): void { $opened = true; },
    static function (string $frame): void {},
    static function (?int $code): void {},
    static function (\Throwable $error): void {},
);
$transport->send(['op' => 'subscribe', 'args' => [['channel' => 'trades', 'instId' => 'BTC-USDT-SWAP']]]);
$transport->send(['op' => 'ping']);

self::assertTrue($opened);
self::assertSame(
    ['{"op":"subscribe","args":[{"channel":"trades","instId":"BTC-USDT-SWAP"}]}', 'ping'],
    $connection->sent,
);
```

Also prove that the connector closure receives exactly one config-validated string URI and no headers/options, `send()` before open fails with `okx_paper_public_ws_not_connected`, a frame above `MAX_FRAME_BYTES` closes and reports only `okx_paper_public_ws_frame_too_large`, a delayed connection resolved after `close()` is immediately closed without calling `onOpen`, and callbacks from an old generation are ignored after reconnect. Test `PawlOkxPaperPublicWebSocketTransportFactory::create(LoopInterface $loop)` twice: each call returns a distinct transport and distinct Pawl connector, each connector uses the exact supplied loop, and callbacks/connections from one instance never appear in the other. Reflect the interfaces, factory, and Pawl constructor to prove they expose no credential, auth, header, private-path, simulated-trading, or free-form endpoint-kind selector; the same credential-free adapter is instantiated independently for the allowlisted Public and Business URIs.

The deterministic fake retains callback sets by connection attempt and exposes:

```php
public function open(?int $attempt = null): void;
public function message(array|string $message, ?int $attempt = null): void;
public function disconnect(?int $code = null, ?int $attempt = null): void;
public function fail(\Throwable $error, ?int $attempt = null): void;
```

Copy the minimal `LoopInterface` timer/signal test helper shape from `OkxPrivateWebSocketWorkerTest`, including `fireNextTimer()`, `fireTimerInterval()`, `firePeriodicInterval()`, and cancellation removal. Do not import the private fake or private transport interface.

- [ ] **Step 2: Run the focused tests and verify the red state**

```bash
cd trading-app
php vendor/bin/phpunit tests/Trading/Paper/Okx/Live/OkxPaperPublicWebSocketTransportTest.php
```

Expected: PHPUnit fails because the public transport/factory interfaces, Pawl factory/adapter, policy, integrity exception, and fake do not exist.

- [ ] **Step 3: Implement the minimal transport and policy**

Mirror the private Pawl generation pattern, but keep the namespace and exception codes public-specific. `send(['op' => 'ping'])` emits literal `ping`; every other message uses `json_encode(..., JSON_THROW_ON_ERROR)`. The adapter accepts one `LoopInterface`, no headers/options, and never constructs an auth payload. The factory creates a new Pawl connector and adapter on every `create($loop)` and passes that same loop through; it owns no cached connector, transport, callbacks, timers, or generation. Before invoking `$onMessage`, reject a string whose byte length exceeds `OkxPaperLivePolicy::MAX_FRAME_BYTES`, close the active generation, and call `$onError(new OkxPaperLiveIntegrityException('okx_paper_public_ws_frame_too_large'))`.

Define the exact policy constants shown in **Proposed Public Signatures**, including the 10-second per-resync-attempt timeout, ten-page historical-overlap bound, and reconnect stability thresholds. There is no jitter, infinite final delay, environment override, or retry loop in the transport; reconnect and resync ownership belongs to the source.

- [ ] **Step 4: Run transport tests and static analysis**

```bash
cd trading-app
php vendor/bin/phpunit tests/Trading/Paper/Okx/Live/OkxPaperPublicWebSocketTransportTest.php
php vendor/bin/phpstan analyse \
  src/Trading/Paper/Okx/Live/OkxPaperPublicWebSocketTransportInterface.php \
  src/Trading/Paper/Okx/Live/OkxPaperPublicWebSocketTransportFactoryInterface.php \
  src/Trading/Paper/Okx/Live/PawlOkxPaperPublicWebSocketTransportFactory.php \
  src/Trading/Paper/Okx/Live/PawlOkxPaperPublicWebSocketTransport.php \
  src/Trading/Paper/Okx/Live/OkxPaperLivePolicy.php \
  src/Trading/Paper/Okx/Live/OkxPaperLiveIntegrityException.php \
  tests/Trading/Paper/Okx/Live/OkxPaperPublicWebSocketTransportTest.php \
  tests/Trading/Paper/Okx/Live/FakeOkxPaperPublicWebSocketTransport.php \
  tests/Trading/Paper/Okx/Live/DeterministicLoop.php \
  --memory-limit=1G
```

Expected: all focused tests pass and PHPStan reports no errors.

- [ ] **Step 5: Commit the public transport boundary**

```bash
git add \
  trading-app/src/Trading/Paper/Okx/Live/OkxPaperPublicWebSocketTransportInterface.php \
  trading-app/src/Trading/Paper/Okx/Live/OkxPaperPublicWebSocketTransportFactoryInterface.php \
  trading-app/src/Trading/Paper/Okx/Live/PawlOkxPaperPublicWebSocketTransportFactory.php \
  trading-app/src/Trading/Paper/Okx/Live/PawlOkxPaperPublicWebSocketTransport.php \
  trading-app/src/Trading/Paper/Okx/Live/OkxPaperLivePolicy.php \
  trading-app/src/Trading/Paper/Okx/Live/OkxPaperLiveIntegrityException.php \
  trading-app/tests/Trading/Paper/Okx/Live/FakeOkxPaperPublicWebSocketTransport.php \
  trading-app/tests/Trading/Paper/Okx/Live/DeterministicLoop.php \
  trading-app/tests/Trading/Paper/Okx/Live/OkxPaperPublicWebSocketTransportTest.php
git commit -m "feat(paper): add injectable OKX public websocket transport"
```

### Task 2: Exact Public Subscription Set, Strict Decoder, and Bounded Queue

**Files:**
- Create: `trading-app/src/Trading/Paper/Okx/Live/OkxPaperPublicSubscriptionSet.php`
- Create: `trading-app/src/Trading/Paper/Okx/Live/OkxPaperPublicFrameDecoder.php`
- Create: `trading-app/src/Trading/Paper/Okx/Live/OkxPaperPublicFrameQueue.php`
- Test: `trading-app/tests/Trading/Paper/Okx/Live/OkxPaperPublicProtocolTest.php`
- Modify: `trading-app/src/Trading/Paper/Okx/OkxPaperPublicConfig.php`
- Modify: `trading-app/tests/Trading/Paper/Okx/Http/OkxPaperPublicConfigTest.php`
- Modify: `trading-app/tests/Trading/Paper/Okx/Http/OkxPaperPublicServiceWiringTest.php`
- Modify: `trading-app/config/services.yaml`

- [ ] **Step 1: Write failing subscription, decoding, and backpressure tests**

Assert that `OkxPaperPublicSubscriptionSet::publicArguments()` returns exactly four Public-socket subscriptions in instrument-map order BTC then ETH:

```php
[
    ['channel' => 'trades', 'instId' => 'BTC-USDT-SWAP'],
    ['channel' => 'books', 'instId' => 'BTC-USDT-SWAP'],
    ['channel' => 'trades', 'instId' => 'ETH-USDT-SWAP'],
    ['channel' => 'books', 'instId' => 'ETH-USDT-SWAP'],
]
```

Assert that `businessArguments()` returns exactly eight credential-free Business-socket candle subscriptions:

```php
[
    ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
    ['channel' => 'candle5m', 'instId' => 'BTC-USDT-SWAP'],
    ['channel' => 'candle15m', 'instId' => 'BTC-USDT-SWAP'],
    ['channel' => 'candle1H', 'instId' => 'BTC-USDT-SWAP'],
    ['channel' => 'candle1m', 'instId' => 'ETH-USDT-SWAP'],
    ['channel' => 'candle5m', 'instId' => 'ETH-USDT-SWAP'],
    ['channel' => 'candle15m', 'instId' => 'ETH-USDT-SWAP'],
    ['channel' => 'candle1H', 'instId' => 'ETH-USDT-SWAP'],
]
```

Use only the explicit `acknowledgePublic()` and `acknowledgeBusiness()` methods. Prove that acknowledgements are idempotent only for an exact allowlisted `(channel, instId)` pair on the correct socket; a Public candle ACK, Business trades/books ACK, unknown symbol, `tickers`, `books-l2-tbt`, `trades-all`, private/account channel, missing field, additional arg field, or error acknowledgement fails with `okx_paper_public_subscription_invalid`. `isPublicReady()` becomes true only after its four ACKs, `isBusinessReady()` only after its eight ACKs, and total `isReady()` only after all 12 exact ACKs.

For the explicit `decodePublic()` and `decodeBusiness()` entry points, accept only:

- literal `pong`;
- realistic OKX subscribe ACKs with exact `event`, exact socket-routed `arg`, mandatory `connId`, and optional `id`;
- realistic OKX `error` frames with exact `event`, `code`, `msg`, mandatory `connId`, and optional `id`/exact socket-routed `arg`, always reduced to stable code `okx_paper_public_protocol_error` without returning metadata or message;
- Public data frames only for `trades`/`books`, Business data frames only for the four candle channels, with an exact `arg`, list `data`, and channel-specific `action` rules (`books` requires `snapshot|update`; trades/candles reject `action`).

Validate `id` as `^[A-Za-z0-9]{1,32}$` as required by OKX. Validate `connId` conservatively as the bounded canonical identifier `^[A-Za-z0-9]{1,64}$`. Both fields are transport metadata: after validation, remove them from the returned subscribe control and never use them to choose a channel, instrument, or socket. Keep every `arg` strict and exact. Reject missing/empty/non-string/overlong/non-canonical metadata, extra root metadata, malformed JSON, list roots, blank/oversized frames, login controls, wrong-socket channels, unknown instruments/channels, and non-list `data` with `okx_paper_public_message_invalid`. Inline realistic fixtures for subscribe with `connId`, subscribe with `id`+`connId`, error with `connId`, error with optional `id`/`arg`, and every invalid metadata class. No exception may contain raw frame bytes, OKX `msg`, `id`, or `connId`.

Extend `OkxPaperPublicConfig` with the exact `BUSINESS_WEB_SOCKET_URI = 'wss://ws.okx.com:8443/ws/v5/business'` and a dedicated `businessWebSocketUri` value supplied by `OKX_PAPER_PUBLIC_BUSINESS_WS_URI`. Prove that the Public and Business properties each accept only their one exact URI and reject cross-routed paths, `/private`, wrong schemes/hosts/ports, userinfo, suffixes, query, fragment, slash, and blank values. The Business socket remains a public, credential-free candle channel: add no key, secret, passphrase, header, login, signature, or simulated-trading option.

For `OkxPaperPublicFrameQueue`, enqueue raw strings FIFO while tracking count and bytes. The 257th frame or any enqueue above 2 MiB aggregate throws `market_data_backpressure_exhausted`; `dequeue()` subtracts exact bytes; `clear()` returns count/bytes to zero.

- [ ] **Step 2: Run the protocol tests and verify the red state**

```bash
cd trading-app
php vendor/bin/phpunit \
  tests/Trading/Paper/Okx/Live/OkxPaperPublicProtocolTest.php \
  tests/Trading/Paper/Okx/Http/OkxPaperPublicConfigTest.php \
  tests/Trading/Paper/Okx/Http/OkxPaperPublicServiceWiringTest.php
```

Expected: failures identify the missing explicit Public/Business subscription/decoder methods, realistic control metadata handling, Business URI config value, and dedicated service wiring while the bounded queue cases remain red/green according to their current implementation state.

- [ ] **Step 3: Implement the allowlisted protocol components**

Use `OkxPaperInstrumentMap::nativeInstrumentIds()` and the fixed per-socket channel orders above; do not accept caller-supplied channels, symbols, endpoint strings, or route selectors. Expose these methods:

```php
/** @return list<array{channel: string, instId: string}> */
public function publicArguments(): array;
/** @return list<array{channel: string, instId: string}> */
public function businessArguments(): array;
/** @param array<array-key, mixed> $arg */
public function acknowledgePublic(array $arg): void;
/** @param array<array-key, mixed> $arg */
public function acknowledgeBusiness(array $arg): void;
public function isPublicRequired(string $channel, string $instrumentId): bool;
public function isBusinessRequired(string $channel, string $instrumentId): bool;
public function isPublicReady(): bool;
public function isBusinessReady(): bool;
public function isReady(): bool;
public function reset(): void;

/** @return array<string, mixed> */
public function decodePublic(#[\SensitiveParameter] string $frame): array;
/** @return array<string, mixed> */
public function decodeBusiness(#[\SensitiveParameter] string $frame): array;

public function enqueue(#[\SensitiveParameter] string $frame): void;
public function dequeue(): ?string;
public function count(): int;
public function bytes(): int;
public function clear(): void;
```

Mark raw frame parameters `#[\SensitiveParameter]`. Both decoder methods share only private structural logic and select membership through their explicit method, never a caller-supplied endpoint string. Convert every `JsonException`, shape failure, or protocol error to a stable `OkxPaperLiveIntegrityException`; never concatenate frame contents, metadata, or OKX `msg` values.

- [ ] **Step 4: Run focused tests and PHPStan**

```bash
cd trading-app
php vendor/bin/phpunit tests/Trading/Paper/Okx/Live/OkxPaperPublicProtocolTest.php
php vendor/bin/phpunit \
  tests/Trading/Paper/Okx/Http/OkxPaperPublicConfigTest.php \
  tests/Trading/Paper/Okx/Http/OkxPaperPublicServiceWiringTest.php
php vendor/bin/phpstan analyse \
  src/Trading/Paper/Okx/OkxPaperPublicConfig.php \
  src/Trading/Paper/Okx/Live/OkxPaperPublicSubscriptionSet.php \
  src/Trading/Paper/Okx/Live/OkxPaperPublicFrameDecoder.php \
  src/Trading/Paper/Okx/Live/OkxPaperPublicFrameQueue.php \
  tests/Trading/Paper/Okx/Http/OkxPaperPublicConfigTest.php \
  tests/Trading/Paper/Okx/Http/OkxPaperPublicServiceWiringTest.php \
  tests/Trading/Paper/Okx/Live/OkxPaperPublicProtocolTest.php \
  --memory-limit=1G
```

Expected: tests pass and PHPStan reports no errors.

- [ ] **Step 5: Commit the public protocol boundary**

```bash
git add \
  trading-app/src/Trading/Paper/Okx/Live/OkxPaperPublicSubscriptionSet.php \
  trading-app/src/Trading/Paper/Okx/Live/OkxPaperPublicFrameDecoder.php \
  trading-app/src/Trading/Paper/Okx/Live/OkxPaperPublicFrameQueue.php \
  trading-app/tests/Trading/Paper/Okx/Live/OkxPaperPublicProtocolTest.php \
  trading-app/src/Trading/Paper/Okx/OkxPaperPublicConfig.php \
  trading-app/tests/Trading/Paper/Okx/Http/OkxPaperPublicConfigTest.php \
  trading-app/tests/Trading/Paper/Okx/Http/OkxPaperPublicServiceWiringTest.php \
  trading-app/config/services.yaml
git commit -m "feat(paper): constrain OKX public subscriptions and frames"
```

### Task 3: Extend the Existing Normalizer for Live Candles and Recovery Evidence

**Files:**
- Modify: `trading-app/src/Trading/Paper/Okx/Normalization/OkxPaperMarketEventNormalizer.php`
- Modify: `trading-app/tests/Trading/Paper/Okx/Normalization/OkxPaperMarketEventNormalizerTest.php`
- Create: `trading-app/tests/Fixtures/OkxPaperPublic/ws-candle-1m.json`
- Create: `trading-app/tests/Fixtures/OkxPaperPublic/ws-candle-5m.json`
- Create: `trading-app/tests/Fixtures/OkxPaperPublic/ws-candle-15m.json`
- Create: `trading-app/tests/Fixtures/OkxPaperPublic/ws-candle-1H.json`

- [ ] **Step 1: Write failing live-normalization tests**

Add these public methods without changing existing named constructor parameters:

```php
/** @param array<array-key, mixed> $row */
public function webSocketCandle(string $instrumentId, string $bar, array $row): ?PaperMarketEvent;

public function connectionState(
    string $instrumentId,
    string $state,
    int $connectionEpoch,
): PaperMarketEvent;

public function snapshotBoundary(
    string $instrumentId,
    string $reason,
    int $sourceEpoch,
    string $sourceSequence,
): PaperMarketEvent;
```

Each candle fixture uses the existing nine-string OKX candle row and the exact WS arg channel. Prove mappings `candle1m -> candle_1m`, `candle5m -> candle_5m`, `candle15m -> candle_15m`, and `candle1H -> candle_1h`; confirmed rows use `origin=ws_candle` and the injected receipt clock, while an unconfirmed row returns `null` without consuming an ordinal.

Allow only these control values:

```text
connection state: connected | subscribed | reconnecting | stopped
snapshot reason: initial | reconnect | sequence_gap
top-of-book origin: rest_initial_snapshot | rest_resync_snapshot | ws_books
```

Assert that `connection_state` and `snapshot_boundary` events use the normalized symbol, injected UTC clock for both timestamps, positive epochs, stable natural identities, redactor-safe payloads, and the same ordinal object as trades/candles/books. Invalid state/reason/origin/epoch/sequence fails with a stable `okx_paper_*_invalid` code before ordinal commit.

Change `materializedTopOfBook()` compatibly:

```php
public function materializedTopOfBook(
    string $instrumentId,
    OkxMaterializedBookState $materializedBookState,
    int $sourceEpoch,
    string $origin = 'ws_books',
): PaperMarketEvent;
```

- [ ] **Step 2: Run normalizer tests and verify the new cases fail**

```bash
cd trading-app
php vendor/bin/phpunit tests/Trading/Paper/Okx/Normalization/OkxPaperMarketEventNormalizerTest.php
```

Expected: existing cases pass; new cases fail because the live methods and origin parameter do not exist.

- [ ] **Step 3: Implement live normalization through the existing ordinal transaction**

Refactor the private candle helper to take exact origin and receipt-time mode instead of duplicating parsing. All new events must still pass through the existing private `event()` method so `OkxPaperSourceOrdinal::preview()` occurs before `PaperMarketEvent::create()` and `commit()` occurs only after validation. Control payloads are exactly:

```php
['native_symbol' => $instrumentId, 'state' => $state, 'connection_epoch' => $connectionEpoch]
['native_symbol' => $instrumentId, 'reason' => $reason, 'source_epoch' => $sourceEpoch, 'source_seq_id' => $sourceSequence]
```

Do not add raw frame, URI, error message, endpoint, header, credential, checksum, or auth metadata to normalized payloads.

- [ ] **Step 4: Run normalizer, fixture-safety, and static tests**

```bash
cd trading-app
php vendor/bin/phpunit \
  tests/Trading/Paper/Okx/Normalization/OkxPaperMarketEventNormalizerTest.php \
  tests/Trading/Paper/MarketData/PaperMarketEventRedactorTest.php
php vendor/bin/phpstan analyse \
  src/Trading/Paper/Okx/Normalization \
  tests/Trading/Paper/Okx/Normalization \
  --memory-limit=1G
```

Expected: all tests pass and PHPStan reports no errors.

- [ ] **Step 5: Commit live normalizer support**

```bash
git add \
  trading-app/src/Trading/Paper/Okx/Normalization/OkxPaperMarketEventNormalizer.php \
  trading-app/tests/Trading/Paper/Okx/Normalization/OkxPaperMarketEventNormalizerTest.php \
  trading-app/tests/Fixtures/OkxPaperPublic/ws-candle-1m.json \
  trading-app/tests/Fixtures/OkxPaperPublic/ws-candle-5m.json \
  trading-app/tests/Fixtures/OkxPaperPublic/ws-candle-15m.json \
  trading-app/tests/Fixtures/OkxPaperPublic/ws-candle-1H.json
git commit -m "feat(paper): normalize OKX live candles and recovery events"
```

### Task 4: Full-Book Materialization and Strict Sequence Gaps

**Files:**
- Create: `trading-app/src/Trading/Paper/Okx/Live/OkxPaperBookDeltaStatus.php`
- Create: `trading-app/src/Trading/Paper/Okx/Live/OkxPaperBookDeltaResult.php`
- Create: `trading-app/src/Trading/Paper/Okx/Live/OkxPaperOrderBookMaterializer.php`
- Test: `trading-app/tests/Trading/Paper/Okx/Live/OkxPaperOrderBookMaterializerTest.php`
- Create: `trading-app/tests/Fixtures/OkxPaperPublic/ws-books-gap.json`

- [ ] **Step 1: Write failing snapshot/delta/gap tests**

Start from `order-book.json`, require `replaceSnapshot()` before `applyDelta()`, then apply the existing `ws-books-update.json` semantics to a complete ETH state. Prove:

- REST and WS snapshots replace the complete state, validate both sides through `OkxMaterializedBookState::fromSnapshot()`, and expose their `seqId`;
- a delta requires `prevSeqId === current seqId` and `seqId > prevSeqId` using `BigInteger`, never native integer arithmetic;
- a positive-size level upserts by exact decimal price; a zero-size level deletes; all four raw OKX strings `[price,size,raw_field_3,order_count]` remain intact in candidate/full state until the complete four-field rows cross `OkxMaterializedBookState::fromAppliedDelta()`; bids sort descending and asks ascending before that call;
- deletion of the final level on either side, crossed book, malformed level, negative sequence, sequence regression, or update before snapshot fails closed;
- `ws-books-gap.json` has `prevSeqId` different from the current `seqId` and raises exactly `okx_paper_book_sequence_gap` without mutating the prior materialized state;
- replay of the same already-applied `(prevSeqId,seqId)` update returns status `REPLAYED` and does not emit a second state; the same sequence pair with another canonical row hash raises `market_event_identity_conflict`.

Use the explicit result types from **Proposed Public Signatures** and keep the public API exact:

```php
public function replaceSnapshot(array $snapshot): OkxMaterializedBookState;
public function applyDelta(array $delta): OkxPaperBookDeltaResult;
public function sourceSequence(): ?string;
```

`OkxPaperBookDeltaResult::materializedState()` returns the non-null materialized state only for `APPLIED`; calling it for `REPLAYED` fails with `okx_paper_book_delta_state_unavailable`. Tests branch on `status()` first, so no nullable state or `null means replay` convention remains anywhere.

- [ ] **Step 2: Run the materializer test and verify the red state**

```bash
cd trading-app
php vendor/bin/phpunit tests/Trading/Paper/Okx/Live/OkxPaperOrderBookMaterializerTest.php
```

Expected: PHPUnit fails because `OkxPaperOrderBookMaterializer` does not exist.

- [ ] **Step 3: Implement transactional book updates**

Keep internal levels as `array<string, array{price:string,size:string,raw_field_3:string,order_count:string}>` keyed by exact price. Reconstruct four-string list rows without coercion for `OkxMaterializedBookState`; do not drop, reinterpret, or rename the raw third value before that boundary. Parse and apply to local candidate arrays, construct `OkxMaterializedBookState`, and publish the candidate state/hash only after all validation passes. Return `OkxPaperBookDeltaResult::applied($state)` or `::replayed()` explicitly. Use `CanonicalJson::encode()` for the last raw update hash. Do not turn missing values into zero and do not emit a top-of-book event from this class; the existing normalizer owns that conversion.

- [ ] **Step 4: Run focused tests and PHPStan**

```bash
cd trading-app
php vendor/bin/phpunit \
  tests/Trading/Paper/Okx/Live/OkxPaperOrderBookMaterializerTest.php \
  tests/Trading/Paper/Okx/Normalization/OkxPaperMarketEventNormalizerTest.php
php vendor/bin/phpstan analyse \
  src/Trading/Paper/Okx/Live/OkxPaperBookDeltaStatus.php \
  src/Trading/Paper/Okx/Live/OkxPaperBookDeltaResult.php \
  src/Trading/Paper/Okx/Live/OkxPaperOrderBookMaterializer.php \
  tests/Trading/Paper/Okx/Live/OkxPaperOrderBookMaterializerTest.php \
  --memory-limit=1G
```

Expected: tests pass and PHPStan reports no errors.

- [ ] **Step 5: Commit book materialization**

```bash
git add \
  trading-app/src/Trading/Paper/Okx/Live/OkxPaperBookDeltaStatus.php \
  trading-app/src/Trading/Paper/Okx/Live/OkxPaperBookDeltaResult.php \
  trading-app/src/Trading/Paper/Okx/Live/OkxPaperOrderBookMaterializer.php \
  trading-app/tests/Trading/Paper/Okx/Live/OkxPaperOrderBookMaterializerTest.php \
  trading-app/tests/Fixtures/OkxPaperPublic/ws-books-gap.json
git commit -m "feat(paper): materialize sequenced OKX public books"
```

### Task 5: Durable Live Checkpoint and Pending-Event Recovery

**Files:**
- Create: `trading-app/src/Trading/Paper/Okx/Live/OkxPaperStreamFrontier.php`
- Create: `trading-app/src/Trading/Paper/Okx/Live/OkxPaperLiveCheckpoint.php`
- Create: `trading-app/src/Trading/Paper/Okx/Live/OkxPaperLiveCheckpointStore.php`
- Test: `trading-app/tests/Trading/Paper/Okx/Live/OkxPaperLiveCheckpointStoreTest.php`

- [ ] **Step 1: Write failing authoritative state-machine checkpoint tests**

Define schema version `2` with these exact logical fields and no open-ended metadata map:

```text
schema_version
dataset_id
configuration_sha256
phase                       # warming|connecting|subscribing|streaming|resyncing|reconnecting|stopping|complete|failed
failure_reason              # null except failed; one allowlisted stable code
pending_transition          # null or exact {kind,symbol:?string,stream:?string,stage}; next action to repeat
remaining_symbols           # ordered subset of BTCUSDT,ETHUSDT
remaining_boundaries        # ordered list of {symbol,reason:initial|reconnect|sequence_gap}
connection_epoch
source_epochs              # BTCUSDT and ETHUSDT positive integers
stream_frontiers           # exact finite REST/WS/control stream keys -> null or frontier triple
ordinal_state              # OkxPaperSourceOrdinal::snapshot()
last_acknowledged_event_id
pending_event              # null or exact PaperMarketEvent::toArray()
pending_frontier           # null or {stream,frontier}; committed only by acknowledge()
healthy_stop               # {requested:bool,remaining_symbols:list<string>}
reconnect                  # {attempt:int,deadline_at:?UTC,stable_since:?UTC,accepted_events:int}
resync_by_symbol           # BTCUSDT/ETHUSDT -> null or {attempt,frontier,source_sequence,deadline_at,policy}
overlap_pagination_by_stream # exact candle/trade stream keys -> null or bounded pagination state
```

Allow only transition kinds `rest_fetch`, `transport_connect`, `transport_close`, `subscription_send`, `timer_schedule`, `timer_cancel`, `emit_boundary`, `healthy_stop`, and `loop_stop`. `stage` is the concrete idempotent action (`current_candles`, `recent_trades`, `history_candles`, `history_trades`, `order_book`, `connect`, `close`, `subscribe`, `reconnect_delay`, `resync_timeout`, `cancel_reconnect_timer`, `cancel_resync_timer`, `initial|reconnect|sequence_gap`, `emit_stopped`, `finalize`, `stop_loop`). Validate the exact kind/stage matrix and which combinations require a symbol/stream or require `null`; every allowed kind has at least one representable stage. Resync policy is exactly `book_seq_overlap_v1` or `frontier_overlap_v1`; `source_sequence` is non-null only for the former. These finite values, phase/pending pairing, and remaining lists define the one next transition after restart.

For each candle/trade stream, `overlap_pagination_by_stream` is either `null` or exactly:

```text
endpoint                    # history_candles|history_trades
pagination_type             # null for candles; 1|2 for trades
next_cursor                 # null or bounded validated timestamp/tradeId string
pages_consumed              # 0..MAX_OVERLAP_HISTORY_PAGES
pages_remaining             # MAX_OVERLAP_HISTORY_PAGES-pages_consumed
target_frontier             # exact OkxPaperStreamFrontier sought by overlap recovery
deadline_at                 # original absolute UTC deadline, never recomputed on restart
```

The first historical trade request uses type `2`; after its response, the saved continuation switches to type `1` with the validated oldest `tradeId`, matching `OkxHistoricalEventStream`. Persist this pagination state before every history call and after every validated page so a crash cannot reset the ten-page budget, cursor contract, target frontier, or deadline.

The finite `stream_frontiers` keys are, for both symbols, `rest/candle_{1m,5m,15m,1H}`, `rest/public_trade`, `rest/top_of_book`, `ws/candle_{1m,5m,15m,1H}`, `ws/public_trade`, `ws/top_of_book`, `control/connection_state`, and `control/snapshot_boundary`. `OkxPaperStreamFrontier` contains exactly:

```text
source_identity             # OKX-verifiable row identity: tradeId; bar+opening ts; book seqId; stable control identity
natural_identity            # venue/native symbol/channel plus the same exchange identity, excluding receipt/transport
canonical_digest            # SHA-256 of canonical source fields, excluding receipt time and REST-vs-WS origin labels
```

Prove every value is a bounded non-empty identity or lowercase SHA-256, and that the same trade/candle row observed over REST and WS derives the same natural identity/digest. No trade/candle sequence counter may be synthesized. Prove fresh creation, strict round-trip, sorted canonical JSON, file mode `0600`, directories `0700`, final newline, same-directory atomic replacement, fsync of file and directory through `PaperDatasetRecorderFilesystem`, and rejection of symlinks/hardlinks/replaced pinned directories. The checkpoint lives at:

```text
$datasetDirectory/checkpoints/okx-live/checkpoint.json
```

Reject unknown/missing keys, wrong dataset/config hash, invalid phase/failure/continuation pairing, a kind/stage mismatch, duplicate/out-of-order remaining symbols or boundaries, an ordinal state outside finite OKX scopes, non-positive epochs, unknown frontier or pagination keys, malformed frontier/event/hash, pending frontier without pending event, a pending event whose natural identity/digest does not match its frontier, event venue/symbol outside OKX BTC/ETH, reconnect/resync attempts outside policy, non-UTC deadlines, invalid pagination endpoint/type/cursor/budget/target/deadline combinations, a resync `source_sequence` on a trade/candle stream, or checkpoint above 1 MiB with `okx_paper_live_checkpoint_invalid`.

Exercise checkpoint-first transitions and crash windows:

1. `saveTransition()` persists phase, exact next transition, remaining symbols/boundaries, reconnect/resync attempt, original deadline, and deterministic policy before REST fetch, transport connect/close, timer schedule, resubscribe, healthy stop, or resync action;
2. reopening returns the same pending transition and does not increment an attempt, recompute a deadline, reset a budget, skip a boundary, or move to the following phase;
3. `savePending()` before recorder append preserves the exact event including original receipt timestamp, ordinal snapshot, and optional frontier;
4. reopening before `acknowledge()` returns that exact pending event first;
5. `acknowledge($eventId)` clears pending state, advances that stream frontier for every REST/WS/control event, and persists the post-normalization ordinal snapshot; a frame with several trades advances exactly one row per acknowledgement;
6. healthy-stop `remaining_symbols`, reconnect stability counters, each symbol's resync attempt/frontier/source-sequence/deadline, and each active overlap pagination endpoint/type/cursor/page budget/target/deadline survive restart byte-for-byte;
7. a wrong event ID or non-monotonic/conflicting frontier fails with `okx_paper_live_acknowledgement_invalid` or `market_event_identity_conflict` and leaves bytes unchanged.

Use these public operations:

```php
public function loadOrCreate(string $datasetId, string $configurationSha256): OkxPaperLiveCheckpoint;
public function save(OkxPaperLiveCheckpoint $checkpoint): void;
public function saveTransition(
    OkxPaperLiveCheckpoint $checkpoint,
    string $phase,
    ?array $pendingTransition,
): OkxPaperLiveCheckpoint;
public function savePending(
    OkxPaperLiveCheckpoint $checkpoint,
    PaperMarketEvent $event,
    array $ordinalState,
    ?array $pendingFrontier,
): OkxPaperLiveCheckpoint;
public function acknowledge(OkxPaperLiveCheckpoint $checkpoint, string $eventId): OkxPaperLiveCheckpoint;
```

- [ ] **Step 2: Run checkpoint tests and verify the red state**

```bash
cd trading-app
php vendor/bin/phpunit tests/Trading/Paper/Okx/Live/OkxPaperLiveCheckpointStoreTest.php
```

Expected: failures identify the missing stream frontier, authoritative checkpoint, and store while using the Task 1 integrity exception.

- [ ] **Step 3: Implement typed validation and atomic persistence**

Reuse `PaperDatasetManifest::assertDatasetId()`, `CanonicalJson`, `PaperMarketEvent::fromArray()`, `OkxPaperSourceOrdinal::restore()`, and `PaperDatasetRecorderFilesystem`; do not invent another event format. `configuration_sha256` is the canonical hash of venue `okx`, the exact accepted REST/Public-WS/Business-WS URIs from the validated config instance, both native symbols, the ordered four Public subscription args, the ordered eight Business subscription args, and all deterministic `OkxPaperLivePolicy` values. It contains no filesystem path or environment value. The runtime factory, not the source, computes this hash and supplies the already-loaded checkpoint.

Each transition is write-ahead: persist the exact next external transition and its attempt/deadline/boundaries before executing it; after success, persist the next continuation before issuing the next external action. On restart, repeat the persisted transition idempotently with the same budget and absolute UTC deadline. A deadline already elapsed fails/advances the same saved attempt according to policy instead of granting a fresh timeout.

Pin the existing dataset and checkpoint directories following the recorder/checkpoint-store patterns. Never expose the dataset path, raw pending event, or decoded checkpoint value in an exception message or logger context.

- [ ] **Step 4: Run checkpoint, recorder, and PHPStan tests**

```bash
cd trading-app
php vendor/bin/phpunit \
  tests/Trading/Paper/Okx/Live/OkxPaperLiveCheckpointStoreTest.php \
  tests/Trading/Paper/Dataset/PaperDatasetRecorderTest.php
php vendor/bin/phpstan analyse \
  src/Trading/Paper/Okx/Live/OkxPaperStreamFrontier.php \
  src/Trading/Paper/Okx/Live/OkxPaperLiveCheckpoint.php \
  src/Trading/Paper/Okx/Live/OkxPaperLiveCheckpointStore.php \
  tests/Trading/Paper/Okx/Live/OkxPaperLiveCheckpointStoreTest.php \
  --memory-limit=1G
```

Expected: tests pass and PHPStan reports no errors.

- [ ] **Step 5: Commit durable live checkpoints**

```bash
git add \
  trading-app/src/Trading/Paper/Okx/Live/OkxPaperStreamFrontier.php \
  trading-app/src/Trading/Paper/Okx/Live/OkxPaperLiveCheckpoint.php \
  trading-app/src/Trading/Paper/Okx/Live/OkxPaperLiveCheckpointStore.php \
  trading-app/tests/Trading/Paper/Okx/Live/OkxPaperLiveCheckpointStoreTest.php
git commit -m "feat(paper): checkpoint OKX live capture durably"
```

### Task 6: Generic Record-Before-Effect Capture Boundary

**Files:**
- Create: `trading-app/src/Trading/Paper/MarketData/PaperLiveMarketDataSourceInterface.php`
- Create: `trading-app/src/Trading/Paper/Dataset/PaperLiveEventConsumerInterface.php`
- Create: `trading-app/src/Trading/Paper/Dataset/PaperLiveDatasetCapture.php`
- Test: `trading-app/tests/Trading/Paper/Dataset/PaperLiveDatasetCaptureTest.php`

- [ ] **Step 1: Write failing durable ordering, idempotent-consumer, and terminal-state tests**

Use an in-memory acknowledged source, a real temporary `PaperDatasetRecorder`, and a deterministic consumer whose authoritative checkpoint map is keyed by `dataset_id . '/' . event_id` and stores `payload_hash` plus an effect counter. In `consume()`, reopen `events.ndjson` and `manifest.json` to prove the yielded event is already durable before atomically adding its key/hash/effect:

```php
$manifest = $capture->run(
    $recorder,
    $source,
    $idempotentConsumer,
);
```

Prove the order `append durable -> consume -> acknowledge` separately for recorder results `APPENDED` and `REPLAYED`. An exact exchange retry therefore still invokes the consumer, whose authoritative key/hash makes it a no-op, then acknowledges the yielded event and leaves recorder count/checksum and effect count unchanged. A conflicting recorder identity never invokes the consumer; a conflicting consumer key/hash is rethrown as `market_event_identity_conflict`; both stop the source and leave the manifest `INCOMPLETE` with quality `INCOMPLETE`.

Use a process-crash harness (no PHP exception cleanup) and reopen fresh recorder/source/consumer objects to prove both required windows:

1. crash after durable `APPENDED` and before consumer effect: restart gets recorder `REPLAYED`, invokes the consumer, commits the one effect, then acknowledges;
2. crash after the consumer has atomically committed key/hash/effect and before source acknowledgement: restart gets `REPLAYED`, invokes the consumer, observes the exact key/hash as already committed, does not repeat the effect, then acknowledges.

Also prove:

- source exception, consumer exception, acknowledgement exception, abnormal generator end, and emergency `stop()` all call `markIncomplete()`;
- a failed/incomplete source can never call `complete()` afterward;
- only `source->isComplete() === true` after an explicit healthy stop invokes `complete()`;
- when handling an original source/append/consumer/acknowledgement failure, a throwing `source->stop()` is captured as cleanup failure, `markIncomplete()` is still attempted exactly once, and the original exception object is rethrown if incomplete persistence succeeds;
- if `markIncomplete()` also fails, `paper_live_capture_incomplete_persist_failed` has the original failure as `previous`; the stop failure is secondary test-visible cleanup context and never replaces that original;
- recorder append occurs before every consumer call for every event, including control and snapshot-boundary events.

- [ ] **Step 2: Run the capture test and verify the red state**

```bash
cd trading-app
php vendor/bin/phpunit tests/Trading/Paper/Dataset/PaperLiveDatasetCaptureTest.php
```

Expected: PHPUnit fails because `PaperLiveDatasetCapture` does not exist.

- [ ] **Step 3: Implement the minimal capture loop**

Use this exact success-loop ordering:

```php
foreach ($source->events() as $event) {
    $appendResult = $recorder->append($event);
    assert($appendResult === PaperDatasetAppendResult::APPENDED
        || $appendResult === PaperDatasetAppendResult::REPLAYED);
    $consumer->consume($recorder->manifest()->datasetId, $event);
    $source->acknowledge($event->eventId);
}

return $source->isComplete()
    ? $recorder->complete()
    : $this->stopAndMarkIncomplete($recorder, $source, null);
```

Wrap the loop in one catch that calls a private `stopAndMarkIncomplete($recorder, $source, $originalFailure)`. That helper independently catches `stop()` and then always attempts `markIncomplete()`. With an original failure and successful incomplete persistence, rethrow that same object regardless of a stop failure. If incomplete persistence fails, throw stable `paper_live_capture_incomplete_persist_failed` with the original as `previous` (or the stop failure as `previous` only when there was no earlier failure); retain cleanup failures only in private test-visible fields and never interpolate their messages. Never catch-and-complete any failure. Do not branch consumer invocation on `$appendResult`.

- [ ] **Step 4: Run dataset tests and static analysis**

```bash
cd trading-app
php vendor/bin/phpunit tests/Trading/Paper/Dataset
php vendor/bin/phpstan analyse \
  src/Trading/Paper/MarketData/PaperLiveMarketDataSourceInterface.php \
  src/Trading/Paper/Dataset/PaperLiveEventConsumerInterface.php \
  src/Trading/Paper/Dataset/PaperLiveDatasetCapture.php \
  tests/Trading/Paper/Dataset/PaperLiveDatasetCaptureTest.php \
  --memory-limit=1G
```

Expected: all dataset tests pass and PHPStan reports no errors.

- [ ] **Step 5: Commit the capture boundary**

```bash
git add \
  trading-app/src/Trading/Paper/MarketData/PaperLiveMarketDataSourceInterface.php \
  trading-app/src/Trading/Paper/Dataset/PaperLiveEventConsumerInterface.php \
  trading-app/src/Trading/Paper/Dataset/PaperLiveDatasetCapture.php \
  trading-app/tests/Trading/Paper/Dataset/PaperLiveDatasetCaptureTest.php
git commit -m "feat(paper): record live events before downstream effects"
```

### Task 7: REST Warm-Up, Initial Snapshot, Subscriptions, and Live Event Flow

**Files:**
- Create: `trading-app/src/Trading/Paper/Okx/Live/OkxPaperPublicLiveSource.php`
- Test: `trading-app/tests/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceTest.php`

- [ ] **Step 1: Write failing initial-flow tests with only fake REST and fake WebSocket**

Build a recording `OkxPaperPublicRestClientInterface` fake and the deterministic transport. With `acquisitionEnabled=true`, assert this strict order before any book update is consumed:

```text
BTC currentCandles 1m,5m,15m,1H
BTC recentTrades
BTC orderBook
ETH currentCandles 1m,5m,15m,1H
ETH recentTrades
ETH orderBook
connect Public transport to wss://ws.okx.com:8443/ws/v5/public
send Public subscribe command containing only the exact 4 trades/books args
connect Business transport to wss://ws.okx.com:8443/ws/v5/business
send Business subscribe command containing only the exact 8 candle args
```

For current candles, request limit `300`, sort confirmed rows by timestamp ascending, compare each row to its durable acknowledged REST frontier, skip only hash-verified rows through that exact frontier, and normalize later rows with `warmupCandle()`. Request recent trades with limit `500`, sort by `(ts, numeric tradeId)` ascending, and apply the same exact frontier rule before `recoveryTrade()`. A fresh stream with a null frontier emits the validated response; a restarted warm-up with a non-null frontier must find exact overlap in current/recent data or use the bounded historical-overlap algorithm specified in Task 8, otherwise it fails `market_data_gap_unresolved`. Every accepted row receives an `OkxPaperStreamFrontier`; advance it only with the event acknowledgement, including every row of a multi-row response. Request order-book depth `400`, require exactly the existing single response row, checkpoint the pending transition/source epoch/remaining boundary before the REST call and before each yield, materialize it before connecting, then emit in order:

```text
top_of_book(origin=rest_initial_snapshot)
snapshot_boundary(reason=initial)
```

The test transport emits a books update synchronously from `connect()`. Assert that its normalized `top_of_book(origin=ws_books)` appears only after both REST snapshot/boundary pairs have been yielded and acknowledged.

Feed the four exact Public subscribe acknowledgements on the Public fake, the eight exact Business acknowledgements on the Business fake, the four candle fixtures only through Business, and `ws-trades.json`, `ws-books-snapshot.json`, and `ws-books-update.json` only through Public. Assert each data row is routed to the existing normalizer, unconfirmed candles are skipped, every accepted REST/WS/control event advances its exact stream frontier only after acknowledgement, all events use only BTCUSDT/ETHUSDT and supported channels, and no raw frame or header reaches the payload. Prove that Public candle controls/data and Business trades/books controls/data fail closed before normalization. Replay a multi-row trades frame after acknowledging only its first row: the acknowledged row is hash-verified and skipped, while each remaining row is yielded exactly once in original frame order; a crash before an acknowledgement re-yields that exact pending event first.

Also prove:

- acquisition disabled fails before REST or transport with `okx_paper_public_acquisition_disabled`;
- any config URI outside the exact REST, Public WS, and Business WS constants is rejected by `OkxPaperPublicConfig` construction before the source can be created;
- no data frame is consumed until Public readiness is 4/4, Business readiness is 8/8, and combined readiness is 12/12; frames may wait only in their socket's bounded queue;
- one yielded event must be acknowledged before another event is yielded; a wrong/missing acknowledgement raises `okx_paper_live_acknowledgement_invalid`;
- pending checkpoint event is yielded before any REST call on restart;
- restarting from `phase`, `pending_transition`, `remaining_symbols`, or `remaining_boundaries` repeats that exact next transition with the saved source epoch/budget, and never skips to the next symbol or boundary.

- [ ] **Step 2: Run the source test and verify the red state**

```bash
cd trading-app
php vendor/bin/phpunit tests/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceTest.php --filter 'Initial|Warmup|Subscription|Pending|Frontier|MultiRow|Continuation'
```

Expected: failures identify the missing source and initial-flow behavior.

- [ ] **Step 3: Implement the acknowledged pull/event-loop bridge**

Construct the source with explicit dependencies and no credentials:

```php
public function __construct(
    private OkxPaperPublicRestClientInterface $restClient,
    private OkxPaperPublicWebSocketTransportInterface $publicTransport,
    private OkxPaperPublicWebSocketTransportInterface $businessTransport,
    private OkxPaperPublicConfig $config,
    private ClockInterface $clock,
    private OkxPaperLiveCheckpointStore $checkpointStore,
    private OkxPaperLiveCheckpoint $checkpoint,
    private LoopInterface $loop,
    ?OkxPaperInstrumentMap $instruments = null,
    ?OkxPaperPublicSubscriptionSet $subscriptions = null,
    ?OkxPaperPublicFrameDecoder $decoder = null,
    ?OkxPaperPublicFrameQueue $publicQueue = null,
    ?OkxPaperPublicFrameQueue $businessQueue = null,
);
```

The runtime factory passes an already manifest/config-validated checkpoint. At construction, restore one `OkxPaperSourceOrdinal` from it and inject that same instance into `OkxPaperMarketEventNormalizer`. `events()` first yields the exact checkpoint pending event, then executes the saved `pending_transition`, otherwise continues the saved phase. Before every REST call, connection/subscription action, boundary change, timer schedule, resync step, or healthy-stop step, call `saveTransition()` with the exact action, remaining symbols/boundaries, attempt, and deadline; only then perform it. Before every yield, call `savePending()` with the post-normalization ordinal snapshot and optional `{stream,frontier}`. Refuse to advance while pending remains. `acknowledge()` delegates to the checkpoint store and updates the in-memory checkpoint only after the atomic save succeeds.

Derive frontiers from raw validated source rows before normalization. `source_identity` is `tradeId` for trades, `<bar>/<opening-ts>` for candles, and `seqId` for books; `natural_identity` includes OKX/native instrument/channel plus that identity; `canonical_digest` hashes the canonical market fields and excludes receipt timestamp and REST/WS origin. Control/boundary identities are stable from state/reason plus persisted epoch/sequence. Never use arrival index, ordinal, local time, or a fabricated `prev/seq` for trades/candles.

Use `LoopInterface::run()`/`stop()` as the pull bridge: stop the loop when at least one normalized event is ready or the source reaches a terminal state, yield one event, and resume the loop only after acknowledgement. Public callbacks enqueue only into `$publicQueue` and drain only through `decodePublic()`; Business callbacks enqueue only into `$businessQueue` and drain only through `decodeBusiness()`. Raw transport callbacks never call the recorder or downstream consumer. For `OkxPaperBookDeltaResult`, emit only `APPLIED.materializedState()` and silently hash-verify `REPLAYED`; never test a nullable state.

- [ ] **Step 4: Run the initial-flow suite and PHPStan**

```bash
cd trading-app
php vendor/bin/phpunit tests/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceTest.php --filter 'Initial|Warmup|Subscription|Pending|Frontier|MultiRow|Continuation'
php vendor/bin/phpstan analyse \
  src/Trading/Paper/Okx/Live/OkxPaperPublicLiveSource.php \
  tests/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceTest.php \
  --memory-limit=1G
```

Expected: selected tests pass and PHPStan reports no errors.

- [ ] **Step 5: Commit the initial live source flow**

```bash
git add \
  trading-app/src/Trading/Paper/Okx/Live/OkxPaperPublicLiveSource.php \
  trading-app/tests/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceTest.php
git commit -m "feat(paper): stream warmed OKX public market events"
```

### Task 8: Gap Resync, Bounded Reconnect, Heartbeat, Backpressure, and Stop

**Files:**
- Modify: `trading-app/src/Trading/Paper/Okx/Live/OkxPaperPublicLiveSource.php`
- Modify: `trading-app/tests/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceTest.php`

- [ ] **Step 1: Write failing sequence-gap recovery tests**

After a valid books snapshot/update on the Public transport, deliver `ws-books-gap.json`. Assert the source:

1. does not emit/apply the gapped delta;
2. pauses frame draining for that instrument while leaving frames in the Public bounded queue;
3. checkpoints `phase=resyncing`, the exact pending transition, remaining boundary, and that symbol's `{attempt:1,frontier:<last acknowledged ws/books frontier>,source_sequence:<current seqId>,deadline_at:<now+10s>,policy:'book_seq_overlap_v1'}` before scheduling a timeout or calling REST;
4. calls `OkxPaperSourceOrdinal::reserveGap('okx/<SYMBOL>/top_of_book')` exactly once for the whole recovery cycle, including restart/retry;
5. increments and durably saves the symbol source epoch before the external transition;
6. calls only `OkxPaperPublicRestClientInterface::orderBook($native, 400)`;
7. accepts the response only before the saved absolute deadline, replaces the full materializer snapshot, and requires a queued update chain whose first retained `prevSeqId` equals the REST `seqId`; it drops only updates proven obsolete by `seqId <= snapshot seqId`;
8. yields/acknowledges `top_of_book(origin=rest_resync_snapshot)` and `snapshot_boundary(reason=sequence_gap)` from the saved remaining boundary;
9. clears that symbol's resync state only after both acknowledgements and verified book overlap, then resumes FIFO processing;
10. causes `PaperDatasetRecorder` to count the reserved normalized ordinal as one visible top-of-book sequence gap.

Expire attempt one exactly at `RESYNC_ATTEMPT_TIMEOUT_SECONDS`, ignore its late REST result/timer callback by generation, persist attempt two with a new absolute deadline before starting it, and preserve the same behavior across restart. Make three consecutive REST failures, timeouts, or snapshots that cannot connect to the first retained delta terminate with exactly `market_data_gap_unresolved`. Assert no fourth REST call, no fresh timeout budget after restart, no fallback venue/client/path, both queues stop draining, both transports close, and `PaperLiveDatasetCapture` leaves the manifest incomplete. Explicitly assert that this `seqId`/`prevSeqId` gap path is reachable only for `books` on Public; trade/candle frames never enter it or receive a fabricated source sequence.

- [ ] **Step 2: Write failing reconnect, heartbeat, freshness, and backpressure tests**

Use `MockClock` and `DeterministicLoop` to prove:

- close/error on either socket closes both sessions, clears both acknowledgement subsets, schedules exact delays `1,2,4,8,15,30` seconds for the pair, and attempt seven fails with `okx_paper_public_reconnect_exhausted` rather than looping forever;
- reconnect attempt/deadline/pending-connect transitions for Public then Business are checkpointed before timer/connect, and restart repeats that same next transition/delay/budget rather than granting another attempt or skipping one socket;
- a full exact resubscription of four Public plus eight Business pairs, subscription ACK, pong, control-event ACK, or replayed data ACK does not reset reconnect attempts;
- after streaming resumes, checkpoint `stable_since` and increment `accepted_events` only when a newly accepted REST/WS market-data event advances its acknowledged frontier; reset reconnect attempt/deadline to zero/null only after both 30 uninterrupted seconds and 12 such accepted events, persist the reset, and prove a disconnect before either threshold continues with the next retry delay;
- stale callbacks from earlier generations of either transport cannot enqueue, acknowledge, resync, or complete;
- reconnect creates/opens Public and Business sessions, sends only their exact 4/8 sets, performs exact-frontier recovery for all candle/trade logical streams and sequenced recovery for books, increments source epochs, emits `connection_state=reconnecting`, then recovered rows, `top_of_book`, `snapshot_boundary=reconnect`, and only then resumes the two queued WS flows;
- each socket has independent inbound freshness: after 20 seconds without an inbound frame on that socket, the source sends literal ping on that transport; `pong` within 10 seconds refreshes only that socket and is never normalized/recorded; a missing pong on either socket triggers paired reconnect;
- a non-pong valid socket-routed control/data frame refreshes only that socket's inbound freshness, and combined subscription readiness does not mask either stale socket;
- frame 257 or aggregate queued bytes above 2 MiB on either queue fails with `market_data_backpressure_exhausted`, clears both queues, closes both transports, cancels all timers, and leaves the capture incomplete;
- both transport closes, timer cancellation, and loop stop are idempotent.

For each candle/trade stream, use its last acknowledged frontier as the reconnect anchor. First query `currentCandles($native,$bar,null,null,300)` or `recentTrades($native,500)`. Accept an exact frontier anywhere in the validated/sorted response, not only adjacent to the newest row, then emit only later rows in chronological order. If absent, persist `overlap_pagination_by_stream` before each call and follow the existing credential-free pagination contracts: candles use `historyCandles($native,$bar,$after,300)`; trades start with `historyTrades($native,2,$after,100)`, then persist the validated oldest `tradeId` and continue with `historyTrades($native,1,$tradeId,100)` exactly as `OkxHistoricalEventStream` does. Continue until the exact target frontier is found, the saved `pages_remaining` reaches zero, or the original saved deadline expires. A restart resumes the same endpoint, pagination type, cursor, target, remaining budget, and deadline. Test all of these cases:

- a non-adjacent exact overlap is accepted and all post-frontier rows are emitted once;
- the same source/natural identity with another canonical digest fails immediately with `market_event_identity_conflict`;
- a trade frontier absent from the first 500 results is found through bounded `historyTrades()` pagination and resumes without inventing missing trade IDs;
- a frontier absent from all bounded pages, or a candle/trade continuation that otherwise cannot be proven, fails `market_data_gap_unresolved` and leaves the manifest incomplete.

For queued WS data after REST recovery, require an exact raw-row frontier overlap for trades/candles before accepting newer rows; exact duplicates are hash-verified and skipped. For books only, require `prevSeqId ===` the current materialized `seqId`. Never infer trade/candle continuity from numeric-looking `tradeId`, timestamp adjacency, row count, REST page order alone, or a local ordinal.

For stopping, prove `stop()` always ends incomplete. Prove `requestHealthyOperatorStop()` is explicit and succeeds only when Public is 4/4 ready, Business is 8/8 ready, both socket freshness values are within policy, no reconnect/resync is active, both raw queues and the normalized queue are drained, and no event awaits acknowledgement. It checkpoints `phase=stopping`, `healthy_stop.requested=true`, and ordered `remaining_symbols` before stopping callback admission; yields one final `connection_state=stopped` per saved symbol; removes a symbol only on acknowledgement; and checkpoints both closes/cancel/loop-stop transitions before executing them. Restart resumes the same next stopped event/transition. Only after both final acknowledgements and cleanup may it persist `phase=complete` and make `isComplete()` true. If any precondition becomes false, it ends incomplete with stable `okx_paper_public_healthy_stop_invalid`.

- [ ] **Step 3: Run recovery tests and verify the red state**

```bash
cd trading-app
php vendor/bin/phpunit tests/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceTest.php --filter 'Gap|Resync|Reconnect|Overlap|Timeout|Stability|Heartbeat|Freshness|Backpressure|Stop'
```

Expected: new recovery/lifecycle cases fail while Task 7 initial-flow cases remain green.

- [ ] **Step 4: Implement finite recovery state transitions**

Use the exact checkpointed phases `warming`, `connecting`, `subscribing`, `streaming`, `resyncing`, `reconnecting`, `stopping`, `complete`, and `failed`; do not encode state in loosely related booleans. Every external transition has a persisted `pending_transition`, and every callback/timer captures the connection/resync generation and returns immediately when stale. Maintain one materializer, source epoch, acknowledged frontier set, and resync state per normalized symbol.

Catch all raw book gap/conflict exceptions at the source boundary. Convert raw/frontier/source-ordinal/recorder-facing identity conflicts to the single fatal public code `market_event_identity_conflict`. Never reserve a second ordinal while one resync is active. Start one deterministic 10-second timeout per persisted resync attempt, reject late completion, and increment attempts only through a persisted transition. On any terminal failure, persist `phase=failed` and a stable `failureReason()`, prevent later healthy completion, stop timers/transport/loop, and allow the capture bridge to mark the recorder incomplete.

On reconnect, do not clear the retry budget when subscriptions become ready. Persist `stable_since` when verified recovery reaches streaming, count only newly accepted and acknowledged market-data frontiers, and persist a zeroed reconnect budget only when both stability thresholds are satisfied. A simple acknowledgement, duplicate replay, pong, subscription/control frame, or restart never counts toward stability.

Reconnect and resync code must call only the existing public REST methods and exact mapped instruments. There is no fallback host, secondary venue, private `OkxRestClient`, `OkxConfig`, account gateway, order gateway, auth signer, or simulated-trading header.

- [ ] **Step 5: Run the complete live source suite and PHPStan**

```bash
cd trading-app
php vendor/bin/phpunit tests/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceTest.php
php vendor/bin/phpstan analyse \
  src/Trading/Paper/Okx/Live \
  tests/Trading/Paper/Okx/Live \
  --memory-limit=1G
```

Expected: all live source tests pass and PHPStan reports no errors.

- [ ] **Step 6: Commit bounded recovery and lifecycle control**

```bash
git add \
  trading-app/src/Trading/Paper/Okx/Live/OkxPaperPublicLiveSource.php \
  trading-app/tests/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceTest.php
git commit -m "feat(paper): recover OKX public capture deterministically"
```

### Task 9: Crash/Restart Idempotence and Live-Fixture-to-Replay Equality

**Files:**
- Create: `trading-app/tests/Trading/Paper/Okx/Live/OkxPaperLiveCaptureReplayEqualityTest.php`
- Modify: `trading-app/tests/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceTest.php`

- [ ] **Step 1: Write the failing crash/restart integration matrix**

Use one real temporary dataset, real recorder/checkpoint store, authoritative fake downstream consumer, fake REST, fake transport factory, `MockClock`, and deterministic loop. Reopen fresh recorder/source/consumer/transport instances at each exact crash point:

```text
pending checkpoint before recorder append
event append before source acknowledgement
event append before downstream effect
downstream key/hash/effect committed before source acknowledgement
acknowledgement before next raw frame
multi-row WS trades frame after first row acknowledgement
REST initial snapshot before snapshot_boundary acknowledgement
book gap detected before REST resync
resync attempt/deadline persisted before REST call and before timeout callback
REST resync top_of_book before snapshot_boundary acknowledgement
disconnect after reconnect timer scheduled
reconnect overlap pagination after page budget/deadline persisted
streaming before reconnect stability thresholds/reset
healthy stop after first stopped control event
```

For each restart, assert the pending normalized event retains its original `received_timestamp`, `payload_hash`, and `event_id`; recorder append returns `REPLAYED` when the line was already durable; the consumer is invoked for that replay and its `(dataset_id,event_id,payload_hash)` checkpoint keeps the effect count at one; the event file contains one line per event ID; the post-restart ordinal is strictly the next durable ordinal; acknowledged REST/WS/control frontiers skip only hash-verified rows; source epochs never regress; and no transition, boundary, attempt, deadline, historical page budget, reconnect stability counter, or healthy-stop symbol is skipped/reset.

For the two downstream crash windows, assert explicitly: append-before-effect restarts through `REPLAYED -> consume -> effect -> acknowledge`; committed-effect-before-ack restarts through `REPLAYED -> consume idempotent no-op -> acknowledge`. For the multi-row trades replay, assert the already acknowledged prefix is verified/skipped and the unacknowledged suffix is emitted once. For resync/reconnect timers, advance the clock beyond the stored absolute deadline and prove restart does not receive a fresh timeout or attempt.

Add conflicting restart fixtures for recorder identity, downstream key/hash, and stream frontier natural identity/canonical digest. Assert immediate `market_event_identity_conflict`, incomplete manifest, no extra NDJSON line, no acknowledgement, and no second business effect.

- [ ] **Step 2: Write the failing live-fixture/replay equality test**

Drive a healthy capture containing, for both BTC and ETH:

- four REST warm-up candle channels;
- recent public trade recovery;
- initial REST top-of-book and `snapshot_boundary`;
- all exact subscription acknowledgements;
- WS trades and all four candle channels;
- WS book snapshot/update;
- one disconnect, reconnect REST snapshot, and reconnect boundary;
- a non-adjacent trade/candle overlap plus one frontier found only through bounded historical pagination;
- one exact duplicate frame;
- a healthy explicit stop and final connection-state events.

Complete through `PaperLiveDatasetCapture` with the idempotent consumer, verify with `PaperDatasetVerifier`, replay from a fresh `PaperReplayClock`/`PaperReplayReader`, and assert exact sequence equality:

```php
self::assertSame(
    array_map(static fn (PaperMarketEvent $event): array => $event->toArray(), $capturedEvents),
    array_map(static fn (PaperMarketEvent $event): array => $event->toArray(), $replayedEvents),
);
```

Feed fixture timestamps in the existing replay comparator order `(exchange_timestamp, channel, numeric sequence, event_id)` so this comparison proves full normalized event equality, including identity, payload, receipt timestamp, control boundaries, and source epochs. Assert the duplicate is absent, manifest state is `COMPLETE`, quality is `RECORDED_PUBLIC_BOOK_AND_TRADES`, channels include all eight Paper channel enum values, and `events_file_sha256` verifies.

- [ ] **Step 3: Run integration tests and verify the red state**

```bash
cd trading-app
php vendor/bin/phpunit \
  tests/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceTest.php \
  tests/Trading/Paper/Okx/Live/OkxPaperLiveCaptureReplayEqualityTest.php
```

Expected: equality/crash cases expose any missing state transition, frontier overlap, idempotent effect, ordering, duplicate, retry budget, or stop behavior.

- [ ] **Step 4: Make only the minimal source/checkpoint fixes required by the matrix**

Keep restart correction inside `OkxPaperStreamFrontier`, `OkxPaperLiveCheckpoint`, its store, the source transitions, or the generic capture bridge. Do not add a second dataset identity index, alter `PaperDatasetRecorder` replay semantics, exclude receipt timestamps from equality, or add a concrete database/business consumer to this lot.

- [ ] **Step 5: Run Paper live, recorder, verifier, and replay suites**

```bash
cd trading-app
php vendor/bin/phpunit \
  tests/Trading/Paper/Okx/Live \
  tests/Trading/Paper/Dataset \
  tests/Trading/Paper/Replay
php vendor/bin/phpstan analyse \
  src/Trading/Paper/Okx/Live \
  src/Trading/Paper/Dataset/PaperLiveEventConsumerInterface.php \
  src/Trading/Paper/Dataset/PaperLiveDatasetCapture.php \
  tests/Trading/Paper/Okx/Live \
  tests/Trading/Paper/Dataset/PaperLiveDatasetCaptureTest.php \
  --memory-limit=1G
```

Expected: all selected tests pass and PHPStan reports no errors.

- [ ] **Step 6: Commit restart and equality proof**

```bash
git add \
  trading-app/src/Trading/Paper/Okx/Live \
  trading-app/tests/Trading/Paper/Okx/Live \
  trading-app/src/Trading/Paper/Dataset/PaperLiveEventConsumerInterface.php \
  trading-app/src/Trading/Paper/Dataset/PaperLiveDatasetCapture.php \
  trading-app/tests/Trading/Paper/Dataset/PaperLiveDatasetCaptureTest.php
git commit -m "test(paper): prove OKX live capture replay equality"
```

### Task 10: Public Service Wiring, Fixture Redaction, and Targeted Gates

**Files:**
- Create: `trading-app/src/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceFactory.php`
- Modify: `trading-app/config/services.yaml`
- Modify: `trading-app/tests/Trading/Paper/Okx/Http/OkxPaperPublicServiceWiringTest.php`
- Modify: `trading-app/tests/Trading/Paper/PaperFixtureContractTest.php`

- [ ] **Step 1: Write failing service-boundary and public-fixture tests**

Boot the test kernel and prove:

- `OkxPaperPublicWebSocketTransportFactoryInterface` resolves to `PawlOkxPaperPublicWebSocketTransportFactory`; no shared `OkxPaperPublicWebSocketTransportInterface` service exists;
- `OkxPaperPublicLiveSourceFactory` receives only the public REST interface, public transport factory interface, `OkxPaperPublicConfig`, clock, `PaperDatasetManifestCodec`, and `PaperDatasetRecorderFilesystem`; its constructor has no credential/private/mutation dependency;
- `create(string $datasetDirectory, ?LoopInterface $loop = null)` safely reads and decodes that directory's `manifest.json`, rejects symlink/replacement/non-recording/wrong-venue/wrong-BTC-ETH-symbol manifests, verifies the path dataset identity, computes the canonical configuration SHA-256 defined in Task 5, calls `loadOrCreate($manifest->datasetId,$configurationSha256)`, and passes the returned checkpoint into the source constructor;
- each `create()` builds a fresh checkpoint store, split subscriptions, socket-aware decoder, Public queue, Business queue, materializers, source, two Pawl connectors, and two transports; it selects `$sessionLoop = $loop ?? Loop::get()` once, calls the transport factory twice, and passes that exact object to both transports and the source;
- each source's Public and Business transports have distinct connectors/callback generations and no shared mutable state while both reference that source's one loop; two sources created with one loop therefore have four distinct transports/connectors, sources made with different loops never cross timers/callbacks, and a checkpoint pending in dataset A never appears in dataset B;
- runtime-created `OkxPaperPublicLiveSource`, `OkxPaperLiveCheckpointStore`, and `PawlOkxPaperPublicWebSocketTransport` are excluded from automatic service construction, so no scalar dataset path/loop or singleton session is guessed by the container;
- the config still defaults acquisition to false and to the exact canonical REST, Public WS, and Business WS URIs;
- no service alias crosses into `App\Exchange\Okx\PrivateWebSocket`, `App\Exchange\Okx\OkxRestClient`, any gateway, signer, account, order, or mutation client.

Extend `PaperFixtureContractTest` discovery to include `tests/Fixtures/OkxPaperPublic/**/*.json` in its size/header/secret/private-key scan while preserving the existing complete-dataset verification. Parse every new fixture as JSON, enforce 16 KiB maximum per fixture, and reject raw HTTP headers, credential fragments, login payloads, `/private`, account IDs, wallets, and mutation paths. Do not treat the exact `/ws/v5/business` URI as private: if any endpoint URI appears, allow only the two exact canonical Public/Business values. Allow public fixture channel values such as `books`, `trades`, and `candle1m`.

- [ ] **Step 2: Run wiring and fixture tests and verify the red state**

```bash
cd trading-app
php vendor/bin/phpunit \
  tests/Trading/Paper/Okx/Http/OkxPaperPublicServiceWiringTest.php \
  tests/Trading/Paper/PaperFixtureContractTest.php
```

Expected: new assertions fail until the public transport/factory are wired and OKX public fixture discovery is extended.

- [ ] **Step 3: Wire only public acquisition services**

Add explicit service definitions:

```yaml
okx.paper_public.ws_transport_factory:
    class: App\Trading\Paper\Okx\Live\PawlOkxPaperPublicWebSocketTransportFactory

App\Trading\Paper\Okx\Live\OkxPaperPublicWebSocketTransportFactoryInterface:
    alias: okx.paper_public.ws_transport_factory

App\Trading\Paper\Okx\Live\OkxPaperPublicLiveSourceFactory: ~
```

Add the exact source, checkpoint-store, and Pawl transport paths to the existing `App\` resource `exclude` list because the factories own their dataset/loop-scoped construction. In `create()`, pin/read `manifest.json` through the existing filesystem protections, validate it before constructing any transport, derive the config hash from the validated config/instruments/split subscriptions/policy, load the checkpoint, choose one loop, create two fresh transports with it, then pass that same loop, both transports, both queues, and the loaded checkpoint to the fresh source. Add only the dedicated `OKX_PAPER_PUBLIC_BUSINESS_WS_URI` environment value with exact default `wss://ws.okx.com:8443/ws/v5/business`; together with `PAPER_MARKET_ACQUISITION_ENABLED`, `OKX_PAPER_PUBLIC_REST_BASE_URI`, `OKX_PAPER_PUBLIC_WS_URI`, and `PAPER_MARKET_DATA_ROOT`, this remains the entire credential-free public configuration surface. Do not expose the source or transports as a controller/command/public container service.

- [ ] **Step 4: Run focused tests, container lint, YAML lint, and static analysis**

```bash
cd trading-app
php vendor/bin/phpunit \
  tests/Trading/Paper/Okx \
  tests/Trading/Paper/Dataset/PaperLiveDatasetCaptureTest.php \
  tests/Trading/Paper/PaperFixtureContractTest.php
php bin/console lint:container --no-debug
php bin/console lint:yaml config
php vendor/bin/phpstan analyse \
  src/Trading/Paper/Okx \
  src/Trading/Paper/Dataset/PaperLiveEventConsumerInterface.php \
  src/Trading/Paper/Dataset/PaperLiveDatasetCapture.php \
  tests/Trading/Paper/Okx \
  tests/Trading/Paper/Dataset/PaperLiveDatasetCaptureTest.php \
  tests/Trading/Paper/PaperFixtureContractTest.php \
  --memory-limit=1G
```

Expected: tests and lints pass; PHPStan reports no errors.

- [ ] **Step 5: Run production-path credential/private/mutation scans**

```bash
cd trading-app
set -euo pipefail
require_no_match() {
  set +e
  rg "$@"
  rc=$?
  set -e
  test "$rc" -eq 1
}
php vendor/bin/phpunit tests/Trading/Paper/Okx/Http/OkxPaperPublicConfigTest.php
rg -n \
  'wss://ws\.okx\.com:8443/ws/v5/(public|business)' \
  src/Trading/Paper/Okx/OkxPaperPublicConfig.php config/services.yaml
require_no_match -n -i \
  'authorization|authentication|authenticator|auth[_-]?header|headers?|ok-access|api[_-]?key|api[_-]?secret|passphrase|private[_-]?key|signature|simulated[_-]?trading|login|App\\Exchange\\Okx\\PrivateWebSocket' \
  src/Trading/Paper/Okx/Live
require_no_match -n \
  '/ws/v5/private|/api/v5/(trade|account|asset)|place-order|cancel-order|amend-order' \
  src/Trading/Paper/Okx/Live
require_no_match -n -i \
  'authorization|authentication|authenticator|auth[_-]?header|headers?|ok-access|api[_-]?key|api[_-]?secret|passphrase|private[_-]?key|signature|wallet|mnemonic|/private|App\\Exchange\\Okx\\PrivateWebSocket' \
  tests/Fixtures/OkxPaperPublic
```

Expected: the config contract test proves exact rejection of cross-routed, private, wrong-host/port/query values; the positive scan finds both and only the intended canonical defaults in the OKX Paper config/wiring. `require_no_match` succeeds only when `rg` returns exactly `1`; a match (`0`) or scan error (`>=2`) fails the gate. These scans cover credentials, authorization/authentication helpers, headers, the private WebSocket namespace, private URI and mutation paths. They deliberately target production live code and public fixtures so guard assertions in tests do not create false positives. `/ws/v5/business` is no longer globally forbidden because its one exact credential-free candle endpoint is required.

- [ ] **Step 6: Commit wiring and public safety gates**

```bash
git add \
  trading-app/src/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceFactory.php \
  trading-app/config/services.yaml \
  trading-app/tests/Trading/Paper/Okx/Http/OkxPaperPublicServiceWiringTest.php \
  trading-app/tests/Trading/Paper/PaperFixtureContractTest.php
git commit -m "test(paper): gate OKX live capture to public services"
```

### Task 11: Full Verification and Scope Audit

**Files:**
- Modify only if a gate exposes a defect: files already listed in Tasks 1-10

- [ ] **Step 1: Run the complete Paper and existing private-Pawl regression suites**

```bash
cd trading-app
php vendor/bin/phpunit tests/Trading/Paper
php vendor/bin/phpunit \
  tests/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketWorkerTest.php \
  tests/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketEndpointGuardTest.php \
  tests/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketSessionTest.php
```

Expected: all tests pass. The private regression proves the new public types did not alter existing authenticated transport/session behavior.

- [ ] **Step 2: Run touched-scope PHPStan and Symfony gates**

```bash
cd trading-app
php vendor/bin/phpstan analyse \
  src/Trading/Paper \
  tests/Trading/Paper \
  --memory-limit=1G
php bin/console lint:container --no-debug
php bin/console lint:yaml config
```

Expected: PHPStan reports no errors and both Symfony lints pass.

- [ ] **Step 3: Audit exact public endpoints and absence of forbidden scope**

```bash
cd trading-app
set -euo pipefail
require_no_match() {
  set +e
  rg "$@"
  rc=$?
  set -e
  test "$rc" -eq 1
}
rg -n \
  'https://www\.okx\.com|wss://ws\.okx\.com:8443/ws/v5/(public|business)|/api/v5/market/(history-candles|candles|history-trades|trades|books)' \
  src/Trading/Paper/Okx config/services.yaml
php vendor/bin/phpunit tests/Trading/Paper/Okx/Http/OkxPaperPublicConfigTest.php
require_no_match -n -i \
  'authorization|authentication|authenticator|auth[_-]?header|headers?|ok-access|api[_-]?key|api[_-]?secret|passphrase|private[_-]?key|signature|simulated[_-]?trading|login|App\\Exchange\\Okx\\PrivateWebSocket' \
  src/Trading/Paper/Okx/Live
require_no_match -n \
  '/ws/v5/private|/api/v5/(trade|account|asset)|place-order|cancel-order|amend-order' \
  src/Trading/Paper/Okx/Live
git diff --name-only 9b30db96...HEAD
git diff --check 9b30db96...HEAD
git diff --name-only
git diff --cached --name-only
git ls-files --others --exclude-standard
git diff --check
git diff --cached --check
```

Expected: positive hits include exactly the canonical REST, Public WS, Business WS, and public market endpoints; `OkxPaperPublicConfigTest` proves each socket property rejects the other path and every non-canonical value; both negative scans return no matches; the union of committed, unstaged, staged, and untracked name lists contains only files listed in this plan and contains no command, controller, route, strategy/MTF/EntryZone/Risk/SLTP/live-guard file; all diff checks exit `0`.

- [ ] **Step 4: Prove no real network is required and no large dataset is tracked**

```bash
cd trading-app
rg -n 'MockHttpClient|OkxPaperPublicRestClientInterface|FakeOkxPaperPublicWebSocketTransport|DeterministicLoop' \
  tests/Trading/Paper/Okx/Live
test -z "$(git ls-files 'var/paper-market-data/**')"
test -z "$(find tests/Fixtures/OkxPaperPublic -type f -size +16k -print -quit)"
```

Expected: tests visibly use fake REST/WS/loop seams; both `test -z` gates exit `0`, the tracked-dataset query is empty, and `find -print -quit` finds no oversized fixture.

- [ ] **Step 5: Review current-HEAD behavior against every required failure code**

```bash
cd trading-app
rg -n \
  'market_event_identity_conflict|market_data_gap_unresolved|market_data_backpressure_exhausted|okx_paper_public_reconnect_exhausted|okx_paper_public_healthy_stop_invalid|paper_live_capture_incomplete_persist_failed|okx_paper_book_delta_state_unavailable' \
  src/Trading/Paper tests/Trading/Paper
```

Expected: each code has a production transition and at least one focused assertion; `market_event_identity_conflict` and `market_data_gap_unresolved` are fatal and end with an incomplete manifest.

- [ ] **Step 6: Commit only gate-driven corrections if Step 1-5 required changes**

```bash
git status --short
git add \
  trading-app/src/Trading/Paper/Okx \
  trading-app/src/Trading/Paper/Dataset/PaperLiveEventConsumerInterface.php \
  trading-app/src/Trading/Paper/Dataset/PaperLiveDatasetCapture.php \
  trading-app/tests/Trading/Paper/Okx \
  trading-app/tests/Trading/Paper/Dataset/PaperLiveDatasetCaptureTest.php \
  trading-app/tests/Trading/Paper/PaperFixtureContractTest.php \
  trading-app/tests/Fixtures/OkxPaperPublic \
  trading-app/config/services.yaml
git commit -m "fix(paper): satisfy OKX live capture validation gates"
```

Expected: create this commit only when verification required an implementation/test correction. Do not create an empty commit, amend unrelated work, push, merge, or add an operator command.

- [ ] **Step 7: Re-run final gates on the committed current HEAD**

```bash
cd trading-app
set -euo pipefail
require_no_match() {
  set +e
  rg "$@"
  rc=$?
  set -e
  test "$rc" -eq 1
}
php vendor/bin/phpunit tests/Trading/Paper
php vendor/bin/phpunit \
  tests/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketWorkerTest.php \
  tests/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketEndpointGuardTest.php \
  tests/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketSessionTest.php
php vendor/bin/phpstan analyse src/Trading/Paper tests/Trading/Paper --memory-limit=1G
php bin/console lint:container --no-debug
php bin/console lint:yaml config
php vendor/bin/phpunit tests/Trading/Paper/Okx/Http/OkxPaperPublicConfigTest.php
rg -n 'https://www\.okx\.com|wss://ws\.okx\.com:8443/ws/v5/(public|business)|/api/v5/market/(history-candles|candles|history-trades|trades|books)' src/Trading/Paper/Okx config/services.yaml
require_no_match -n -i 'authorization|authentication|authenticator|auth[_-]?header|headers?|ok-access|api[_-]?key|api[_-]?secret|passphrase|private[_-]?key|signature|simulated[_-]?trading|login|App\\Exchange\\Okx\\PrivateWebSocket' src/Trading/Paper/Okx/Live
require_no_match -n '/ws/v5/private|/api/v5/(trade|account|asset)|place-order|cancel-order|amend-order' src/Trading/Paper/Okx/Live
rg -n 'MockHttpClient|OkxPaperPublicRestClientInterface|FakeOkxPaperPublicWebSocketTransport|DeterministicLoop' tests/Trading/Paper/Okx/Live
rg -n 'market_event_identity_conflict|market_data_gap_unresolved|market_data_backpressure_exhausted|okx_paper_public_reconnect_exhausted|okx_paper_public_healthy_stop_invalid|paper_live_capture_incomplete_persist_failed|okx_paper_book_delta_state_unavailable' src/Trading/Paper tests/Trading/Paper
test -z "$(git ls-files 'var/paper-market-data/**')"
test -z "$(find tests/Fixtures/OkxPaperPublic -type f -size +16k -print -quit)"
git diff --name-only 9b30db96...HEAD
git diff --name-only
git diff --cached --name-only
git ls-files --others --exclude-standard
git diff --check 9b30db96...HEAD
git diff --check
git diff --cached --check
git status --short
```

Expected: all tests, PHPStan, lints, endpoint/seam/failure-code audits and negative scans pass on the final committed `HEAD`; the union of committed, unstaged, staged, and untracked file lists remains inside this plan's scope; the tracked-dataset and oversized-fixture checks are empty; every diff check exits `0`; `git status --short` is empty. Any correction creates a new commit and requires this entire Step 7 to run again from the new `HEAD` before push or review.

## Completion Checklist

- The credential-free transport is injectable, deterministic under CI, separate from all private WebSocket types, and freshly constructed twice per source with the exact same `LoopInterface`.
- `OkxPaperPublicConfig` remains the exact REST/Public-WS/Business-WS URI authority; Business is permitted only at `wss://ws.okx.com:8443/ws/v5/business` for public candles, and no auth/header/private/mutative surface enters the live namespace.
- The subscription set is routed exactly as Public = BTC/ETH × trades/books (4) and Business = BTC/ETH × 1m/5m/15m/1H candles (8); combined readiness requires all 12 exact ACKs and wrong-socket controls/data fail closed.
- Each symbol receives a REST order-book snapshot and durable boundary before any delta is consumed.
- The runtime factory validates the stored manifest, computes the canonical configuration hash, loads the matching checkpoint, and passes it into a fresh source/session without shared mutable state.
- Every accepted event is normalized through the existing Paper/OKX classes and durably appended before the idempotent consumer for both `APPENDED` and `REPLAYED`.
- The authoritative downstream `(dataset_id,event_id,payload_hash)` checkpoint makes append-before-effect and effect-before-ack crash windows exactly-once at the effect boundary.
- A throwing source `stop()` never prevents `markIncomplete()` and never replaces the original source/append/consumer/acknowledgement failure.
- Exact raw/frame duplicates are hash-verified and ignored; recorder `REPLAYED` events still cross the idempotent consumer; identity conflicts are visible, fatal, and incomplete.
- `applyDelta()` has an explicit `APPLIED`/`REPLAYED` result, and every raw level retains all four OKX strings through `OkxMaterializedBookState` validation.
- The authoritative checkpoint persists phase, pending continuation, remaining symbols/boundaries, all acknowledged stream frontiers, healthy-stop progress, reconnect stability/budget, and per-symbol resync attempt/frontier/source-sequence/deadline before external transitions.
- Every REST/WS/control event advances its `{source_identity,natural_identity,canonical_digest}` frontier only on acknowledgement; multi-row frame replay skips only the acknowledged exact prefix.
- Book gaps alone use proven `seqId`/`prevSeqId`, pause, REST-resnapshot with a 10-second timeout per persisted attempt, reserve one visible normalized gap, emit `snapshot_boundary`, and resume only on verified sequence overlap; exhaustion ends `market_data_gap_unresolved`.
- Trades/candles never receive an invented sequence: reconnect requires exact non-adjacent overlap, uses bounded existing history pagination when current/recent pages omit the frontier, and fails `market_data_gap_unresolved` when continuity cannot be proved.
- Paired reconnect, per-socket heartbeat/freshness, resync attempts/timeouts, overlap pages, frame size, per-socket queued count, and queued bytes are finite and deterministic; subscription/simple ACK never resets reconnect budget, which resets only after 30 stable seconds and 12 newly accepted acknowledged data events.
- Emergency/error stop is incomplete; only checkpointed explicit healthy operator stop can complete after both final symbol events and cleanup, with no operator command in this sub-plan.
- Pending-event/transition/frontier recovery and recorder restart do not duplicate durable events, downstream effects, attempts, deadlines, boundaries, or accepted rows.
- A complete fake-live fixture capture replays to the exact same `PaperMarketEvent::toArray()` sequence.
- Targeted PHPUnit, PHPStan, Symfony lint, redaction, private-path, mutation-path, diff, fixture-size, and tracked-dataset gates pass.
- No strategy, MTF, EntryZone, Risk, SL/TP, live guard, command, controller, endpoint, credential, private call, mutation call, or real-network CI dependency is present.
