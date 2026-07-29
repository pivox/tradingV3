# Hyperliquid Paper Public History Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a credential-free, network-separated Hyperliquid historical Paper source using public candles and a declared deterministic top-of-book model.

**Architecture:** Add a dedicated Hyperliquid Paper stack under `Trading/Paper/Hyperliquid`; it shares only the venue-neutral Paper event, dataset, and replay contracts. A network-bound HTTP client fetches bounded `candleSnapshot` pages, a durable checkpoint store stages and authenticates them, and an acknowledged event stream emits canonical candle and modelled-book events without synthesizing trades.

**Tech Stack:** PHP 8.4, Symfony HttpClient/RateLimiter/Clock, Brick Math, PHPUnit 11, PHPStan, Symfony DI, MkDocs.

---

## File Structure

Create:

- `trading-app/src/Trading/Paper/Hyperliquid/HyperliquidPaperPublicConfig.php` — exact network/URI policy.
- `trading-app/src/Trading/Paper/Hyperliquid/HyperliquidPaperPublicConfigFactory.php` — maps the selected public network to its constant URI.
- `trading-app/src/Trading/Paper/Hyperliquid/HyperliquidPaperInstrumentMap.php` — BTC/ETH and interval normalization.
- `trading-app/src/Trading/Paper/Hyperliquid/Http/HyperliquidPaperPublicRateLimiter.php` — bounded public-info rate limiting.
- `trading-app/src/Trading/Paper/Hyperliquid/Http/HyperliquidPaperPublicRestClientInterface.php` — candle-only acquisition contract.
- `trading-app/src/Trading/Paper/Hyperliquid/Http/HyperliquidPaperPublicRestClient.php` — bounded POST `/info` client.
- `trading-app/src/Trading/Paper/Hyperliquid/Historical/HyperliquidHistoricalRequest.php` — immutable, network-bound request and hash.
- `trading-app/src/Trading/Paper/Hyperliquid/Historical/HyperliquidHistoricalIntegrityException.php` — stable integrity failure.
- `trading-app/src/Trading/Paper/Hyperliquid/Historical/HyperliquidHistoricalCheckpointStore.php` — atomic checkpoint/page store.
- `trading-app/src/Trading/Paper/Hyperliquid/Historical/HyperliquidHistoricalEventStream.php` — pagination, validation, merge, acknowledgement.
- `trading-app/src/Trading/Paper/Hyperliquid/Normalization/HyperliquidCandle.php` — validated candle value object.
- `trading-app/src/Trading/Paper/Hyperliquid/Normalization/HyperliquidPrudentBookModel.php` — ATR14 model `hl_candle_atr_top_v1`.
- `trading-app/src/Trading/Paper/Hyperliquid/Normalization/HyperliquidPaperSourceOrdinal.php` — restart-safe dense event sequences.
- `trading-app/src/Trading/Paper/Hyperliquid/Normalization/HyperliquidPaperMarketEventNormalizer.php` — common Paper event normalization.
- corresponding PHPUnit files under `trading-app/tests/Trading/Paper/Hyperliquid/`.
- JSON fixtures under `trading-app/tests/Fixtures/HyperliquidPaperPublic/`.

Modify:

- `trading-app/src/Trading/Paper/MarketData/PaperMarketDataQuality.php`
- `trading-app/src/Trading/Paper/Dataset/PaperDatasetManifest.php`
- `trading-app/src/Trading/Paper/Dataset/PaperDatasetVerifier.php`
- `trading-app/config/packages/rate_limiter.yaml`
- `trading-app/config/services.yaml`
- `trading-app/.env.dist`
- `TradingV3_OKX_Hyperliquid_demo_testnet_prompts_canoniques_UNIQUE.md`

Do not modify any class under `src/Exchange/Hyperliquid` or any BitMart file.

### Task 1: Dataset Quality and Model Contract

**Files:**

- Modify: `trading-app/src/Trading/Paper/MarketData/PaperMarketDataQuality.php`
- Modify: `trading-app/src/Trading/Paper/Dataset/PaperDatasetManifest.php`
- Modify: `trading-app/src/Trading/Paper/Dataset/PaperDatasetVerifier.php`
- Test: `trading-app/tests/Trading/Paper/Dataset/PaperDatasetManifestTest.php`
- Test: `trading-app/tests/Trading/Paper/Dataset/PaperDatasetVerifierTest.php`

- [ ] **Step 1: Write failing manifest tests**

Add tests that construct a complete Hyperliquid/mainnet manifest with
`PUBLIC_HISTORICAL_CANDLES_MODELLED_BOOK`,
`hl_candle_atr_top_v1`, and `1.0.0`, then assert round-trip success. Add
rejections for a missing model, wrong model, OKX with the new quality, and
Hyperliquid with `PUBLIC_HISTORICAL_CANDLES_AND_TRADES`.

```php
$manifest = self::recordingManifest(
    venue: PaperMarketDataVenue::HYPERLIQUID,
    network: PaperMarketDataNetwork::MAINNET,
    quality: PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_MODELLED_BOOK,
    modelName: 'hl_candle_atr_top_v1',
    modelVersion: '1.0.0',
);
self::assertSame('public_historical_candles_modelled_book', $manifest->quality->value);
```

- [ ] **Step 2: Run tests and verify the enum case is absent**

Run:

```bash
cd trading-app
php -d memory_limit=512M vendor/bin/phpunit \
  tests/Trading/Paper/Dataset/PaperDatasetManifestTest.php \
  tests/Trading/Paper/Dataset/PaperDatasetVerifierTest.php
```

Expected: failure naming the missing enum case.

- [ ] **Step 3: Add the quality and exact venue/model invariants**

Add:

```php
case PUBLIC_HISTORICAL_CANDLES_MODELLED_BOOK =
    'public_historical_candles_modelled_book';
```

Change `PaperDatasetManifest::assertModel()` so:

```php
if ($quality === PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_MODELLED_BOOK) {
    if ($modelName !== 'hl_candle_atr_top_v1' || $modelVersion !== '1.0.0') {
        throw new \InvalidArgumentException('paper_dataset_hyperliquid_model_invalid');
    }
}
```

Add a venue/quality invariant in the constructor: the new quality requires
`HYPERLIQUID`; the existing historical-candles-and-trades quality requires
`OKX`. Extend baseline verification to accept the new quality only with
certifiable network provenance and the exact model declaration.

- [ ] **Step 4: Run the dataset tests**

Expected: all manifest and verifier tests pass.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Trading/Paper/MarketData/PaperMarketDataQuality.php \
  trading-app/src/Trading/Paper/Dataset/PaperDatasetManifest.php \
  trading-app/src/Trading/Paper/Dataset/PaperDatasetVerifier.php \
  trading-app/tests/Trading/Paper/Dataset
git commit -m "feat(paper): declare Hyperliquid historical quality"
```

### Task 2: Network-Bound Public Configuration and Request

**Files:**

- Create: `trading-app/src/Trading/Paper/Hyperliquid/HyperliquidPaperPublicConfig.php`
- Create: `trading-app/src/Trading/Paper/Hyperliquid/HyperliquidPaperInstrumentMap.php`
- Create: `trading-app/src/Trading/Paper/Hyperliquid/Historical/HyperliquidHistoricalRequest.php`
- Test: `trading-app/tests/Trading/Paper/Hyperliquid/HyperliquidPaperPublicConfigTest.php`
- Test: `trading-app/tests/Trading/Paper/Hyperliquid/HyperliquidPaperInstrumentMapTest.php`
- Test: `trading-app/tests/Trading/Paper/Hyperliquid/Historical/HyperliquidHistoricalRequestTest.php`

- [ ] **Step 1: Write failing endpoint and constructor-surface tests**

Test the exact pairs:

```php
yield 'mainnet' => [
    PaperMarketDataNetwork::MAINNET,
    'https://api.hyperliquid.xyz/info',
];
yield 'testnet' => [
    PaperMarketDataNetwork::TESTNET,
    'https://api.hyperliquid-testnet.xyz/info',
];
```

Reject `legacy_unknown`, `/exchange`, `/info/`, query strings, fragments,
userinfo, HTTP, explicit ports, host suffixes, and a mainnet URI paired with
testnet. Reflect the constructor and assert it has no key, secret, wallet,
address, signer, signature, account, header, or action parameter.

- [ ] **Step 2: Run the three tests**

Expected: class-not-found failures.

- [ ] **Step 3: Implement the exact configuration**

```php
final readonly class HyperliquidPaperPublicConfig
{
    public const MAINNET_INFO_URI = 'https://api.hyperliquid.xyz/info';
    public const TESTNET_INFO_URI = 'https://api.hyperliquid-testnet.xyz/info';

    public function __construct(
        public PaperMarketDataNetwork $network,
        public bool $acquisitionEnabled,
        public string $infoUri,
        public string $dataRoot,
    ) {
        $expected = match ($network) {
            PaperMarketDataNetwork::MAINNET => self::MAINNET_INFO_URI,
            PaperMarketDataNetwork::TESTNET => self::TESTNET_INFO_URI,
            PaperMarketDataNetwork::LEGACY_UNKNOWN =>
                throw new \InvalidArgumentException('hyperliquid_paper_network_invalid'),
        };
        if ($infoUri !== $expected) {
            throw new \InvalidArgumentException('hyperliquid_paper_info_uri_not_allowed');
        }
    }
}
```

The instrument map accepts only normalized `BTCUSDT`/`ETHUSDT`, maps them to
`BTC`/`ETH`, and accepts only `1m`, `5m`, `15m`, `1h`. It exposes
`intervalMilliseconds()`.

- [ ] **Step 4: Implement the immutable historical request**

Use:

```php
public function __construct(
    public string $datasetId,
    public PaperMarketDataNetwork $network,
    array $symbols,
    \DateTimeImmutable $from,
    \DateTimeImmutable $to,
    public int $maximumEvents = 1_000_000,
    public int $maximumPages = 100_000,
    public int $maximumResponseBytes = 1_048_576,
    public int $maximumRetries = 5,
)
```

Normalize UTC, sort/deduplicate symbols, fix intervals to the four allowed
values, reject `from >= to`, legacy network, event/page/byte bounds outside
`1..default`, and retry bounds outside `0..5`.
The request hash is SHA-256 over canonical JSON containing schema version 1,
dataset, network, venue, symbols, intervals, UTC bounds, and all four limits.

- [ ] **Step 5: Run tests and commit**

```bash
php -d memory_limit=512M vendor/bin/phpunit tests/Trading/Paper/Hyperliquid
git add trading-app/src/Trading/Paper/Hyperliquid \
  trading-app/tests/Trading/Paper/Hyperliquid
git commit -m "feat(paper): bind Hyperliquid history requests to network"
```

### Task 3: Credential-Free Candle Snapshot HTTP Client

**Files:**

- Create: `trading-app/src/Trading/Paper/Hyperliquid/Http/HyperliquidPaperPublicRateLimiter.php`
- Create: `trading-app/src/Trading/Paper/Hyperliquid/Http/HyperliquidPaperPublicRestClientInterface.php`
- Create: `trading-app/src/Trading/Paper/Hyperliquid/Http/HyperliquidPaperPublicRestClient.php`
- Test: `trading-app/tests/Trading/Paper/Hyperliquid/Http/HyperliquidPaperPublicRateLimiterTest.php`
- Test: `trading-app/tests/Trading/Paper/Hyperliquid/Http/HyperliquidPaperPublicRestClientTest.php`

- [ ] **Step 1: Write the failing exact-request test**

Call:

```php
$rows = $client->candleSnapshot(
    coin: 'BTC',
    interval: '1m',
    startTime: 1760000000000,
    endTime: 1760002999999,
);
```

Assert one `POST` to the configured `/info` URI with canonical JSON:

```php
[
    'type' => 'candleSnapshot',
    'req' => [
        'coin' => 'BTC',
        'interval' => '1m',
        'startTime' => 1760000000000,
        'endTime' => 1760002999999,
    ],
]
```

Assert only `Accept` and `Content-Type` headers, timeout/max-duration 10 seconds,
zero redirects, unbuffered response, and absence of credential/wallet/signature
tokens in options and reflected properties.

- [ ] **Step 2: Add failing boundary tests**

Cover a 501-row response, body over 1 MiB, invalid JSON, associative root,
malformed candle row, wrong coin/interval, HTTP 4xx, bounded 429/5xx retries,
transport failures, and upstream body containing a sentinel that must not
appear in exceptions.

- [ ] **Step 3: Run tests and observe missing types**

Expected: class-not-found failures.

- [ ] **Step 4: Implement the one-method interface and client**

```php
interface HyperliquidPaperPublicRestClientInterface
{
    /** @return list<array<string, mixed>> */
    public function candleSnapshot(
        string $coin,
        string $interval,
        int $startTime,
        int $endTime,
        int $maximumResponseBytes = 1_048_576,
        int $maximumRetries = 5,
    ): array;
}
```

The client validates `startTime >= 0`, `endTime >= startTime`, uses
`json => $payload`, reserves one limiter token per attempt, reads at most
`maximumResponseBytes` (bounded to `1..1,048,576`), validates at most 500 list
rows, charges the limiter once before the request and an additional
`ceil(row_count / 60)` after a successful response, and uses at most
`maximumRetries` entries from delays
`[0.25, 0.5, 1.0, 2.0, 4.0]`. Errors are stable
`hyperliquid_paper_public_*` reason codes.

- [ ] **Step 5: Run tests and commit**

```bash
php -d memory_limit=512M vendor/bin/phpunit \
  tests/Trading/Paper/Hyperliquid/Http
git add trading-app/src/Trading/Paper/Hyperliquid/Http \
  trading-app/tests/Trading/Paper/Hyperliquid/Http
git commit -m "feat(paper): add Hyperliquid candle snapshot client"
```

### Task 4: Validated Candle and Prudent Book Model

**Files:**

- Create: `trading-app/src/Trading/Paper/Hyperliquid/Normalization/HyperliquidCandle.php`
- Create: `trading-app/src/Trading/Paper/Hyperliquid/Normalization/HyperliquidPrudentBookModel.php`
- Test: `trading-app/tests/Trading/Paper/Hyperliquid/Normalization/HyperliquidCandleTest.php`
- Test: `trading-app/tests/Trading/Paper/Hyperliquid/Normalization/HyperliquidPrudentBookModelTest.php`

- [ ] **Step 1: Write failing candle-shape tests**

Use the public API shape:

```php
[
    'T' => 1681924499999,
    'c' => '29258.0',
    'h' => '29309.0',
    'i' => '15m',
    'l' => '29250.0',
    'n' => 189,
    'o' => '29295.0',
    's' => 'BTC',
    't' => 1681923600000,
    'v' => '0.98639',
]
```

Reject extra/missing keys, non-canonical nonnegative integers, exponent/NaN/INF
decimals, negative volume, nonpositive OHLC, high below OHLC, low above OHLC,
`T != t + interval_ms - 1`, wrong symbol/interval, and unsafe size.

- [ ] **Step 2: Write failing model-vector tests**

For a fixed 15-candle sequence, assert exact ATR window, 2 bps lower clamp,
50 bps upper clamp, bid/ask decimal strings, one level each side, and
`volume / n` size. Assert `null` model output for zero volume or zero trades and
byte-identical output after reconstructing state from the same candle sequence.

- [ ] **Step 3: Implement candle parsing with Brick Math**

Store original canonical decimal strings plus `BigDecimal` accessors. Expose:

```php
public static function fromApiRow(array $row, string $coin, string $interval): self;
public function trueRange(?self $previous): BigDecimal;
public function range(): BigDecimal;
```

Normalize decimals with `BigDecimal::of($value)->stripTrailingZeros()` and
render zero as `"0"`.

- [ ] **Step 4: Implement `hl_candle_atr_top_v1`**

Expose constants:

```php
public const NAME = 'hl_candle_atr_top_v1';
public const VERSION = '1.0.0';
private const ATR_PERIOD = 14;
private const MIN_SPREAD_BPS = '2';
private const MAX_SPREAD_BPS = '50';
private const VOLATILITY_MULTIPLIER = '0.15';
```

Maintain at most 14 true ranges per stream. Calculate with
`RoundingMode::HALF_EVEN` at 18 decimal places, then canonicalize. Return a
value object/array containing bid, ask, size, spread bps, ATR, name, and
version.

- [ ] **Step 5: Run tests and commit**

```bash
php -d memory_limit=512M vendor/bin/phpunit \
  tests/Trading/Paper/Hyperliquid/Normalization
git add trading-app/src/Trading/Paper/Hyperliquid/Normalization \
  trading-app/tests/Trading/Paper/Hyperliquid/Normalization
git commit -m "feat(paper): model prudent Hyperliquid historical book"
```

### Task 5: Canonical Hyperliquid Event Normalization

**Files:**

- Create: `trading-app/src/Trading/Paper/Hyperliquid/Normalization/HyperliquidPaperMarketEventNormalizer.php`
- Create: `trading-app/src/Trading/Paper/Hyperliquid/Normalization/HyperliquidPaperSourceOrdinal.php`
- Test: `trading-app/tests/Trading/Paper/Hyperliquid/Normalization/HyperliquidPaperMarketEventNormalizerTest.php`
- Test: `trading-app/tests/Trading/Paper/Hyperliquid/Normalization/HyperliquidPaperSourceOrdinalTest.php`

- [ ] **Step 1: Write failing candle and book event tests**

Assert:

```php
self::assertSame(PaperMarketDataVenue::HYPERLIQUID, $event->sourceVenue);
self::assertSame($network, $event->sourceNetwork);
self::assertSame('BTCUSDT', $event->symbol);
self::assertSame(PaperMarketDataChannel::CANDLE_1M, $candle->channel);
self::assertSame(PaperMarketDataChannel::TOP_OF_BOOK, $book->channel);
self::assertSame($candle->exchangeTimestamp, $book->exchangeTimestamp);
self::assertSame('hl_candle_atr_top_v1', $book->payload['model_name']);
self::assertTrue($book->payload['synthetic']);
```

Assert no method named `trade`, `historyTrade`, `fills`, or `l2Book` exists.
Verify event IDs differ by network and are stable after reconstruction. Verify
dense sequences restart from a validated ordinal snapshot without gaps.

- [ ] **Step 2: Run and observe the missing normalizer**

- [ ] **Step 3: Implement normalization**

The constructor takes `PaperMarketDataNetwork` and
`HyperliquidPaperSourceOrdinal`. `candle()` creates one candle event with a
dense per-symbol/channel sequence, received timestamp equal to `T`, canonical
payload, and identity `coin|interval|t|T`. `modelledTopOfBook()` creates an
optional top-of-book event with its dense top-of-book sequence, identity
including model name/version and originating candle identity, and payload:

```php
[
    'bid_price' => $book['bid'],
    'bid_size' => $book['size'],
    'ask_price' => $book['ask'],
    'ask_size' => $book['size'],
    'model_name' => HyperliquidPrudentBookModel::NAME,
    'model_version' => HyperliquidPrudentBookModel::VERSION,
    'origin' => 'historical_candle_model',
    'source_candle_start' => (string) $candle->startTime,
    'synthetic' => true,
]
```

- [ ] **Step 4: Run normalization plus common event tests**

```bash
php -d memory_limit=512M vendor/bin/phpunit \
  tests/Trading/Paper/Hyperliquid/Normalization \
  tests/Trading/Paper/MarketData/PaperMarketEventTest.php
```

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Trading/Paper/Hyperliquid/Normalization \
  trading-app/tests/Trading/Paper/Hyperliquid/Normalization
git commit -m "feat(paper): normalize Hyperliquid historical events"
```

### Task 6: Durable Network-Bound Acquisition Checkpoint

**Files:**

- Create: `trading-app/src/Trading/Paper/Hyperliquid/Historical/HyperliquidHistoricalIntegrityException.php`
- Create: `trading-app/src/Trading/Paper/Hyperliquid/Historical/HyperliquidHistoricalCheckpointStore.php`
- Test: `trading-app/tests/Trading/Paper/Hyperliquid/Historical/HyperliquidHistoricalCheckpointStoreTest.php`

- [ ] **Step 1: Write failing create/resume tests**

The initial checkpoint must be:

```php
[
    'schema_version' => 1,
    'network' => 'mainnet',
    'dataset_id' => 'hl-mainnet-001',
    'request_sha256' => $request->requestSha256(),
    'phase' => 'fetching',
    'streams' => [],
    'page_count' => 0,
    'event_count' => 0,
    'emit_index' => 0,
    'ordinal_state' => ['schema_version' => 1, 'scopes' => []],
    'pending_event' => null,
]
```

Assert directories `0700`, files `0600`, canonical JSON, staged NDJSON page
checksums, and identical reload.

- [ ] **Step 2: Add failing corruption and restart tests**

Cover network/request mismatch, page checksum mismatch, truncated page,
symlinks/hardlinks, oversized checkpoint/page, invalid modes, concurrent
writer lock, pre/post-rename sync failures, stale temporary files, pending
event replay, and no replacement of the last durable checkpoint on failure.

- [ ] **Step 3: Run and observe class-not-found failures**

- [ ] **Step 4: Implement the store**

Follow `OkxHistoricalCheckpointStore` filesystem pinning and publication
sequence, but use `checkpoints/hyperliquid-acquisition/<network>/`. Restrict
page names to:

```text
BTC-candle_1m-000001.ndjson
ETH-candle_1h-000042.ndjson
```

Public methods are:

```php
public function loadOrCreate(): array;
public function save(#[\SensitiveParameter] array $state): void;
public function writePage(string $filename, array $records): array;
public function readPage(string $filename): array;
public function verifyPages(array $state): void;
```

Every saved state must match request network, dataset ID, and request hash.

- [ ] **Step 5: Run tests and commit**

```bash
php -d memory_limit=512M vendor/bin/phpunit \
  tests/Trading/Paper/Hyperliquid/Historical/HyperliquidHistoricalCheckpointStoreTest.php
git add trading-app/src/Trading/Paper/Hyperliquid/Historical \
  trading-app/tests/Trading/Paper/Hyperliquid/Historical
git commit -m "feat(paper): checkpoint Hyperliquid history durably"
```

### Task 7: Paginated Acknowledged Historical Stream

**Files:**

- Create: `trading-app/src/Trading/Paper/Hyperliquid/Historical/HyperliquidHistoricalEventStream.php`
- Test: `trading-app/tests/Trading/Paper/Hyperliquid/Historical/HyperliquidHistoricalEventStreamTest.php`
- Create fixtures: `trading-app/tests/Fixtures/HyperliquidPaperPublic/candles-*.json`

- [ ] **Step 1: Write failing happy-path pagination test**

Use a scripted client returning two inclusive pages for every
`BTC/ETH × 1m/5m/15m/1h` stream. Assert the next call starts at
`last.t + intervalMilliseconds`, each window spans no more than 500 intervals,
and all emitted events are globally ordered by timestamp, symbol, interval,
channel, then event ID.

- [ ] **Step 2: Add failing integrity tests**

Cover duplicate/decreasing rows, page overlap, non-progressing cursor, missing
grid candle, stale retention range, wrong coin/interval, inconsistent `T`,
501 rows, maximum pages/events, stop, missing acknowledgement, restart before
and after every page write, restart with pending event, and corruption.

Assert the client is called only through `candleSnapshot()` and emitted
channels never include `PUBLIC_TRADE`.

- [ ] **Step 3: Run the stream test**

Expected: missing stream class.

- [ ] **Step 4: Implement fetch, validation, merge, and acknowledgement**

Implement `AcknowledgedPaperMarketDataSourceInterface`:

```php
public function venue(): PaperMarketDataVenue
{
    return PaperMarketDataVenue::HYPERLIQUID;
}

public function acknowledge(string $eventId): void;
public function stop(): void;
public function isComplete(): bool;
```

For every stream, request forward windows of at most 500 intervals. Validate
the full requested grid before switching checkpoint phase from `fetching` to
`emitting`. Pass `maximumResponseBytes` and `maximumRetries` from the immutable
request to every client call. Reconstruct a fresh model per stream from staged
candles. Restore `HyperliquidPaperSourceOrdinal` from `ordinal_state`; before
yielding an event, persist both the updated ordinal snapshot and the event as
`pending_event`. Acknowledgement increments `emit_index`, clears pending, and
persists. A restarted stream yields the pending event first without allocating
a second sequence.

Catch `HyperliquidHistoricalIntegrityException`, set phase `failed` with only
the stable reason code, clear pending, persist, and rethrow.

- [ ] **Step 5: Run stream and historical tests**

```bash
php -d memory_limit=512M vendor/bin/phpunit \
  tests/Trading/Paper/Hyperliquid/Historical
```

- [ ] **Step 6: Commit**

```bash
git add trading-app/src/Trading/Paper/Hyperliquid/Historical \
  trading-app/tests/Trading/Paper/Hyperliquid/Historical \
  trading-app/tests/Fixtures/HyperliquidPaperPublic
git commit -m "feat(paper): stream Hyperliquid candle history"
```

### Task 8: Dataset Build, Replay Equality, and Baseline Certification

**Files:**

- Test: `trading-app/tests/Trading/Paper/Hyperliquid/HyperliquidHistoricalDatasetTest.php`
- Modify: `trading-app/tests/Trading/Paper/PaperFixtureContractTest.php`
- Modify: `trading-app/src/Trading/Paper/Dataset/PaperDatasetRecorder.php`
- Modify: `trading-app/src/Trading/Paper/Dataset/PaperDatasetVerifier.php`

- [ ] **Step 1: Write the failing end-to-end dataset test**

Build a mainnet dataset with `PaperHistoricalDatasetBuilder`, verify it for a
baseline, replay it with `PaperReplayReader`, and assert:

```php
self::assertSame(
    array_map(static fn (PaperMarketEvent $e): array => $e->toArray(), $captured),
    array_map(static fn (PaperMarketEvent $e): array => $e->toArray(), $replayed),
);
self::assertSame(
    PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_MODELLED_BOOK,
    $manifest->quality,
);
self::assertSame('hl_candle_atr_top_v1', $manifest->modelName);
self::assertSame('1.0.0', $manifest->modelVersion);
self::assertNotContains('public_trade', $manifest->channels);
```

Build the same rows on testnet and assert distinct event IDs, manifest
identities, and directories. Attempt to append a testnet event to mainnet and
assert rejection.

- [ ] **Step 2: Add failure-state tests**

Corrupt a staged page and assert the builder marks the dataset incomplete.
Assert baseline verification rejects incomplete, legacy network, wrong model,
trade channel, current-L2 origin, mixed venue, and mixed network datasets.

- [ ] **Step 3: Implement the exact common recorder/verifier invariants**

In both the recorder append guard and verifier event scan, add a branch scoped
to `HYPERLIQUID + PUBLIC_HISTORICAL_CANDLES_MODELLED_BOOK` that:

```php
if ($event->channel === PaperMarketDataChannel::PUBLIC_TRADE) {
    throw new \RuntimeException('paper_dataset_hyperliquid_historical_trade_forbidden');
}
if ($event->channel === PaperMarketDataChannel::TOP_OF_BOOK
    && (($event->payload['model_name'] ?? null) !== 'hl_candle_atr_top_v1'
        || ($event->payload['model_version'] ?? null) !== '1.0.0'
        || ($event->payload['origin'] ?? null) !== 'historical_candle_model'
        || ($event->payload['synthetic'] ?? null) !== true)
) {
    throw new \RuntimeException('paper_dataset_hyperliquid_model_event_invalid');
}
if (!\in_array($event->channel, [
    PaperMarketDataChannel::CANDLE_1M,
    PaperMarketDataChannel::CANDLE_5M,
    PaperMarketDataChannel::CANDLE_15M,
    PaperMarketDataChannel::CANDLE_1H,
    PaperMarketDataChannel::TOP_OF_BOOK,
], true)) {
    throw new \RuntimeException('paper_dataset_hyperliquid_channel_invalid');
}
```

Mirror the same reason codes during verification. Do not change the OKX path.

- [ ] **Step 4: Run all Paper core and Hyperliquid tests**

```bash
php -d memory_limit=512M vendor/bin/phpunit \
  tests/Trading/Paper/MarketData \
  tests/Trading/Paper/Dataset \
  tests/Trading/Paper/Replay \
  tests/Trading/Paper/Hyperliquid \
  tests/Trading/Paper/PaperFixtureContractTest.php
```

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Trading/Paper/Dataset \
  trading-app/tests/Trading/Paper \
  trading-app/tests/Fixtures/HyperliquidPaperPublic
git commit -m "test(paper): certify Hyperliquid historical datasets"
```

### Task 9: Service Wiring, Security Audit, Documentation, and Delivery

**Files:**

- Modify: `trading-app/config/packages/rate_limiter.yaml`
- Modify: `trading-app/config/services.yaml`
- Modify: `trading-app/.env.dist`
- Create: `trading-app/src/Trading/Paper/Hyperliquid/HyperliquidPaperPublicConfigFactory.php`
- Create: `trading-app/tests/Trading/Paper/Hyperliquid/Http/HyperliquidPaperPublicServiceWiringTest.php`
- Modify: `TradingV3_OKX_Hyperliquid_demo_testnet_prompts_canoniques_UNIQUE.md`

- [ ] **Step 1: Write failing container/security tests**

Assert `HyperliquidPaperPublicConfigFactory` and
`HyperliquidPaperPublicRestClientInterface` are public services, the interface
resolves to `HyperliquidPaperPublicRestClient`, the limiter is dedicated,
acquisition defaults false, and reflection over the dependency graph finds no
service/class containing account, execution, wallet, signer, private key,
exchange endpoint, or action transport. Assert the source files contain
neither `/exchange` nor `"type":"action"`.

- [ ] **Step 2: Add wiring**

Add a `hyperliquid_paper_history` limiter and Paper-only services. Keep canonical
URIs in PHP constants; environment supplies only:

```dotenv
HYPERLIQUID_PAPER_PUBLIC_NETWORK=mainnet
HYPERLIQUID_PAPER_PUBLIC_ACQUISITION_ENABLED=0
```

Implement `HyperliquidPaperPublicConfigFactory::create(string $network)` with
an exact `match` from `mainnet`/`testnet` to `PaperMarketDataNetwork` and the
corresponding URI constants. Any other string throws
`hyperliquid_paper_network_invalid`. Do not accept an endpoint URI from the
environment.

- [ ] **Step 3: Update the canonical registry**

Record PR2 as the current #132 lot after merged PR #328, list its branch and
security boundary, keep PR3 live capture and PR4 coordinator pending, and leave
BitMart #305 deferred.

- [ ] **Step 4: Run full verification**

```bash
cd trading-app
php -d memory_limit=512M vendor/bin/phpunit tests/Trading/Paper/Hyperliquid
php -d memory_limit=512M vendor/bin/phpunit \
  tests/Trading/Paper/MarketData \
  tests/Trading/Paper/Dataset \
  tests/Trading/Paper/Replay \
  tests/Trading/Paper/PaperFixtureContractTest.php
php -d memory_limit=512M vendor/bin/phpstan analyse --memory-limit=512M \
  src/Trading/Paper/Hyperliquid \
  src/Trading/Paper/MarketData/PaperMarketDataQuality.php \
  src/Trading/Paper/Dataset
php bin/console lint:container --env=test --no-debug
php bin/console lint:yaml config --env=test
cd ..
python3 -m mkdocs build --strict
git diff --check
```

Expected: every command exits zero. Search changed sources and compiled
container output for credential, wallet, signer, signature, `/exchange`, and
action-request patterns; only explicit negative tests/documentation may match.

- [ ] **Step 5: Request local code review and fix findings**

Use `superpowers:requesting-code-review`, apply each technically valid finding
with a regression test, then rerun Step 4.

- [ ] **Step 6: Commit final wiring/docs**

```bash
git add trading-app/config trading-app/.env.dist \
  trading-app/tests/Trading/Paper/Hyperliquid \
  TradingV3_OKX_Hyperliquid_demo_testnet_prompts_canoniques_UNIQUE.md
git commit -m "chore(paper): wire Hyperliquid public history"
```

- [ ] **Step 7: Publish and merge**

Push `issue/132-hyperliquid-public-history`, open a draft PR linked to #132,
mark it ready, wait at least 90 seconds, and post exactly one `@codex review`.
Resolve every actionable thread with tests and commits. Do not request another
review. Require CI success on the final HEAD, verify zero unresolved active
threads, then merge with `--match-head-commit`.
