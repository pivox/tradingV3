# #132 Public Paper Capture Command Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a credential-free mono-venue CLI that creates or crash-resumes a bounded OKX or Hyperliquid mainnet public dataset for the exact #132 Paper campaign.

**Architecture:** Reuse the existing venue live-source factories and durable recorder behind a small common factory port. A dedicated dataset-only capture service performs durable append before source acknowledgement; a runner owns canonical manifest creation, venue selection and healthy-stop scheduling; a thin Symfony command emits only redacted canonical JSON.

**Tech Stack:** PHP 8.4, Symfony 7.1 Console/DI, ReactPHP event loop, existing Paper NDJSON/manifest/checkpoint contracts, PHPUnit 11, PHPStan, MkDocs.

---

### Task 1: Canonical public-live manifest factory

**Files:**
- Create: `trading-app/src/Trading/Paper/Capture/PaperPublicLiveManifestFactory.php`
- Test: `trading-app/tests/Trading/Paper/Capture/PaperPublicLiveManifestFactoryTest.php`

- [ ] **Step 1: Write the failing identity tests**

Pin OKX and Hyperliquid mainnet manifests and invalid IDs:

```php
$factory = new PaperPublicLiveManifestFactory();
$okx = $factory->create(PaperMarketDataVenue::OKX, 'baseline-okx-mainnet');
self::assertSame(PaperDatasetManifest::SCHEMA_VERSION, $okx->schemaVersion);
self::assertSame('paper-recorder.v2', $okx->recorderVersion);
self::assertSame(PaperMarketDataNetwork::MAINNET, $okx->network);
self::assertSame(['BTCUSDT' => 'BTC-USDT-SWAP', 'ETHUSDT' => 'ETH-USDT-SWAP'], $okx->symbols);
self::assertSame(PaperDatasetState::RECORDING, $okx->state);
self::assertSame(PaperMarketDataQuality::RECORDED_PUBLIC_BOOK_AND_TRADES, $okx->quality);

$hyperliquid = $factory->create(PaperMarketDataVenue::HYPERLIQUID, 'baseline-hl-mainnet');
self::assertSame(['BTCUSDT' => 'BTC', 'ETHUSDT' => 'ETH'], $hyperliquid->symbols);
```

Use a data provider to reject IDs without `-mainnet` and invalid venue strings before construction.

- [ ] **Step 2: Run the test and verify RED**

```bash
cd trading-app
vendor/bin/phpunit tests/Trading/Paper/Capture/PaperPublicLiveManifestFactoryTest.php
```

Expected: class-not-found failure.

- [ ] **Step 3: Implement the exact factory**

Expose only:

```php
final readonly class PaperPublicLiveManifestFactory
{
    public const RECORDER_VERSION = 'paper-recorder.v2';

    public function create(PaperMarketDataVenue $venue, string $datasetId): PaperDatasetManifest;
}
```

Require `preg_match('/\A[a-z0-9][a-z0-9._-]{2,119}-mainnet\z/D', $datasetId) === 1` and construct the zero-event schema-v2 manifest with the venue-specific fixed symbol map, mainnet network, recorded public quality, null model/checksum/timestamps/last event, and recording state.

- [ ] **Step 4: Run the test and verify GREEN**

Run the Task 1 test. Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Trading/Paper/Capture/PaperPublicLiveManifestFactory.php \
  trading-app/tests/Trading/Paper/Capture/PaperPublicLiveManifestFactoryTest.php
git commit -m "feat(paper): build public capture manifests"
```

### Task 2: Dataset-only record-before-ack service

**Files:**
- Create: `trading-app/src/Trading/Paper/Capture/PaperPublicDatasetCapture.php`
- Test: `trading-app/tests/Trading/Paper/Capture/PaperPublicDatasetCaptureTest.php`

- [ ] **Step 1: Write failing ordering and terminal-state tests**

Use an in-memory `PaperLiveMarketDataSourceInterface` spy plus a real recorder in a private temporary root. Assert the sequence for two events is:

```text
yield:event-1 -> durable manifest event_count=1 -> acknowledge:event-1
yield:event-2 -> durable manifest event_count=2 -> acknowledge:event-2
```

Also assert:

```php
self::assertSame(PaperDatasetState::COMPLETE, $capture->run($recorder, $healthy)->state);
self::assertSame(PaperDatasetState::INCOMPLETE, $capture->run($recorder, $abnormal)->state);
```

Cover a replayed append, acknowledgement failure, source exception, stop failure and incomplete-persistence failure. The original stable source failure remains the cause unless incomplete persistence itself cannot be proven, which must throw `paper_public_capture_incomplete_persist_failed`.

- [ ] **Step 2: Run the test and verify RED**

```bash
vendor/bin/phpunit tests/Trading/Paper/Capture/PaperPublicDatasetCaptureTest.php
```

Expected: class-not-found failure.

- [ ] **Step 3: Implement the capture state machine**

Use the existing recorder as the sole durable effect:

```php
public function run(
    PaperDatasetRecorder $recorder,
    PaperLiveMarketDataSourceInterface $source,
): PaperDatasetManifest {
    try {
        foreach ($source->events() as $event) {
            $result = $recorder->append($event);
            assert($result === PaperDatasetAppendResult::APPENDED
                || $result === PaperDatasetAppendResult::REPLAYED);
            $source->acknowledge($event->eventId);
        }
        if ($source->isComplete()) {
            return $recorder->complete();
        }
    } catch (\Throwable $failure) {
        return $this->stopAndMarkIncomplete($recorder, $source, $failure);
    }

    return $this->stopAndMarkIncomplete($recorder, $source, null);
}
```

Keep stop and incomplete-persistence failures private properties for tests, matching the proven `PaperLiveDatasetCapture` cleanup semantics without using a no-op consumer.

- [ ] **Step 4: Run Task 2 plus recorder regressions**

```bash
vendor/bin/phpunit \
  tests/Trading/Paper/Capture/PaperPublicDatasetCaptureTest.php \
  tests/Trading/Paper/Dataset/PaperDatasetRecorderTest.php \
  tests/Trading/Paper/Dataset/PaperLiveDatasetCaptureTest.php
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Trading/Paper/Capture/PaperPublicDatasetCapture.php \
  trading-app/tests/Trading/Paper/Capture/PaperPublicDatasetCaptureTest.php
git commit -m "feat(paper): capture public datasets without effects"
```

### Task 3: Common source port and bounded stop controller

**Files:**
- Create: `trading-app/src/Trading/Paper/Capture/PaperPublicLiveSourceFactoryInterface.php`
- Create: `trading-app/src/Trading/Paper/Capture/PaperPublicCaptureStopController.php`
- Modify: `trading-app/src/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceFactory.php`
- Modify: `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicLiveSourceFactory.php`
- Test: `trading-app/tests/Trading/Paper/Capture/PaperPublicCaptureStopControllerTest.php`

- [ ] **Step 1: Write failing port and controller tests**

Pin the common factory signature:

```php
interface PaperPublicLiveSourceFactoryInterface
{
    public function create(
        string $datasetDirectory,
        ?LoopInterface $loop = null,
    ): PaperLiveMarketDataSourceInterface;
}
```

With a deterministic loop and source spy, assert `start(300)` registers one timer and supported SIGINT/SIGTERM handlers, firing any of them calls `requestHealthyOperatorStop()` idempotently, and `close()` cancels/removes every registration. Reject durations outside 300..604800 with `paper_public_capture_duration_invalid`.

- [ ] **Step 2: Run and verify RED**

```bash
vendor/bin/phpunit tests/Trading/Paper/Capture/PaperPublicCaptureStopControllerTest.php
```

Expected: missing classes/interfaces.

- [ ] **Step 3: Implement the port and controller**

Make both existing venue factories implement the common interface; their concrete return types remain covariant. The controller owns `LoopInterface`, `PaperLiveMarketDataSourceInterface`, one timer and registered signal IDs. Use `function_exists('pcntl_signal')` before `addSignal()`, make the stop closure idempotent, and always remove/cancel registrations in `close()`.

- [ ] **Step 4: Run source factory and controller tests**

```bash
vendor/bin/phpunit \
  tests/Trading/Paper/Capture/PaperPublicCaptureStopControllerTest.php \
  tests/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceFactoryTest.php \
  tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicLiveSourceFactoryTest.php
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Trading/Paper/Capture \
  trading-app/src/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceFactory.php \
  trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicLiveSourceFactory.php \
  trading-app/tests/Trading/Paper/Capture/PaperPublicCaptureStopControllerTest.php
git commit -m "feat(paper): bound public capture lifecycle"
```

### Task 4: Venue-isolated capture runner

**Files:**
- Create: `trading-app/src/Trading/Paper/Capture/PaperPublicCaptureResult.php`
- Create: `trading-app/src/Trading/Paper/Capture/PaperPublicCaptureRunner.php`
- Test: `trading-app/tests/Trading/Paper/Capture/PaperPublicCaptureRunnerTest.php`

- [ ] **Step 1: Write failing runner tests**

Construct the runner with two fake common factories, a private temporary data root, real manifest/recorder services and a deterministic loop. Assert:

- `okx` calls only the OKX factory and `hyperliquid` calls only the Hyperliquid factory;
- the source receives `<root>/<dataset-id>` and the same loop as the stop controller;
- a new dataset completes and an exact recording dataset resumes;
- terminal, venue-conflicting and identity-conflicting datasets fail before source creation;
- invalid venue/duration/ID fail with stable reasons;
- the result contains no root path and `certification_status=not_evaluated`.

Pin the result payload:

```php
[
    'schema_version' => 'paper-public-capture-result-v1',
    'dataset_id' => 'baseline-okx-mainnet',
    'source_network' => 'mainnet',
    'source_venue' => 'okx',
    'state' => 'complete',
    'quality' => 'recorded_public_book_and_trades',
    'event_count' => 2,
    'start_exchange_timestamp' => '...',
    'end_exchange_timestamp' => '...',
    'channels' => [...],
    'events_file_sha256' => '<64 lowercase hex>',
    'certification_status' => 'not_evaluated',
]
```

- [ ] **Step 2: Run and verify RED**

```bash
vendor/bin/phpunit tests/Trading/Paper/Capture/PaperPublicCaptureRunnerTest.php
```

Expected: missing runner/result classes.

- [ ] **Step 3: Implement selection, recorder construction and result**

Constructor dependencies:

```php
public function __construct(
    private PaperPublicLiveManifestFactory $manifests,
    private PaperPublicDatasetCapture $capture,
    private PaperPublicLiveSourceFactoryInterface $okxSourceFactory,
    private PaperPublicLiveSourceFactoryInterface $hyperliquidSourceFactory,
    #[Autowire('%env(resolve:PAPER_MARKET_DATA_ROOT)%')]
    private string $dataRoot,
) {}
```

`run(string $venue, string $datasetId, int $durationSeconds, ?LoopInterface $loop = null)` converts the exact venue string to the enum, builds a manifest and recorder, rejects the loaded recorder manifest unless it is still `recording`, selects with an exhaustive enum match, creates the source from `recorder->datasetDirectory()`, starts the stop controller, runs capture, closes the controller in `finally`, and returns `PaperPublicCaptureResult::fromManifest()`.

The result validates a complete manifest and serializes only the fields pinned by the test.

- [ ] **Step 4: Run runner and adjacent capture tests**

```bash
vendor/bin/phpunit tests/Trading/Paper/Capture
vendor/bin/phpstan analyse --no-progress --memory-limit=1G \
  src/Trading/Paper/Capture tests/Trading/Paper/Capture
```

Expected: all tests pass and PHPStan reports no errors.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Trading/Paper/Capture \
  trading-app/tests/Trading/Paper/Capture/PaperPublicCaptureRunnerTest.php
git commit -m "feat(paper): run isolated public captures"
```

### Task 5: Symfony command, wiring and redacted output

**Files:**
- Create: `trading-app/src/Command/PaperPublicCaptureCommand.php`
- Modify: `trading-app/config/services.yaml`
- Create: `trading-app/tests/Command/PaperPublicCaptureCommandTest.php`
- Create: `trading-app/tests/Trading/Paper/Capture/PaperPublicCaptureServiceWiringTest.php`

- [ ] **Step 1: Write failing command and graph tests**

Use `CommandTester` around a runner backed by fakes. Assert required options, exact success JSON, exit 0, and generic failure JSON:

```json
{"schema_version":"paper-public-capture-result-v1","ok":false,"blocker":"paper_public_capture_failed"}
```

The failure test injects a private path, frame-like string and nested exception message and asserts none appears in stdout/stderr. The wiring test resolves the command and recursively audits its graph for forbidden namespaces/types: private exchange clients, credentials, signers, account services, `ExecutionPortInterface`, `FakeExchangeAdapter`, OrderIntent and Doctrine/database services.

- [ ] **Step 2: Run and verify RED**

```bash
vendor/bin/phpunit \
  tests/Command/PaperPublicCaptureCommandTest.php \
  tests/Trading/Paper/Capture/PaperPublicCaptureServiceWiringTest.php
```

Expected: command/service not found.

- [ ] **Step 3: Implement the thin command and explicit factory aliases**

Declare:

```php
#[AsCommand(
    name: 'app:paper-market:public-capture',
    description: 'Capture one credential-free public mainnet dataset for Paper replay.',
)]
```

Add required `--venue`, `--dataset-id`, `--duration-sec` options. Convert the duration only after strict decimal validation, call the runner, and output `CanonicalJson::encode($result->toArray())`. Catch every throwable at the command boundary and emit only the generic failure object with `Command::FAILURE`.

Wire the runner's two common-interface arguments explicitly to the OKX and Hyperliquid concrete factories. Do not add a global interface alias because two implementations intentionally exist.

- [ ] **Step 4: Run command, graph and container tests**

```bash
vendor/bin/phpunit \
  tests/Command/PaperPublicCaptureCommandTest.php \
  tests/Trading/Paper/Capture/PaperPublicCaptureServiceWiringTest.php
php bin/console lint:container
php bin/console lint:yaml config
```

Expected: all tests and lints pass.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Command/PaperPublicCaptureCommand.php \
  trading-app/config/services.yaml \
  trading-app/tests/Command/PaperPublicCaptureCommandTest.php \
  trading-app/tests/Trading/Paper/Capture/PaperPublicCaptureServiceWiringTest.php
git commit -m "feat(paper): expose public capture command"
```

### Task 6: Operator documentation and complete verification

**Files:**
- Modify: `docs/handbook/runbooks/paper-market-replay.md`
- Modify: `docs/handbook/technical/interfaces.md`
- Modify: `docs/handbook/reports/bad-trades-baseline.md`
- Modify: `docs/site/**` via MkDocs build

- [ ] **Step 1: Add exact independent launch examples**

Document private-root preparation and the two processes:

```bash
install -d -m 0700 /absolute/private/paper-market-data

PAPER_MARKET_ACQUISITION_ENABLED=1 \
PAPER_MARKET_DATA_ROOT=/absolute/private/paper-market-data \
php bin/console app:paper-market:public-capture \
  --venue=okx \
  --dataset-id=first-baseline-okx-20260823-mainnet \
  --duration-sec=86400

HYPERLIQUID_PAPER_PUBLIC_ACQUISITION_ENABLED=1 \
PAPER_MARKET_DATA_ROOT=/absolute/private/paper-market-data \
php bin/console app:paper-market:public-capture \
  --venue=hyperliquid \
  --dataset-id=first-baseline-hyperliquid-20260823-mainnet \
  --duration-sec=86400
```

State explicitly that operators may run them concurrently as separate processes, that terminal datasets are immutable, and that completion is followed by #407 campaign replay and the independent 50-trade gate. No private credential or `PAPER_EXECUTION_ENABLED` is used for capture.

- [ ] **Step 2: Run the complete relevant regression set**

```bash
cd trading-app
vendor/bin/phpunit \
  tests/Command/PaperPublicCaptureCommandTest.php \
  tests/Trading/Paper/Capture \
  tests/Trading/Paper/Dataset \
  tests/Trading/Paper/Okx/Live \
  tests/Trading/Paper/Hyperliquid/Live
vendor/bin/phpstan analyse --no-progress --memory-limit=2G \
  src/Command/PaperPublicCaptureCommand.php \
  src/Trading/Paper/Capture \
  src/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceFactory.php \
  src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicLiveSourceFactory.php
php bin/console lint:container
php bin/console lint:yaml config
cd ..
python3 -m mkdocs build --strict
git diff --check
```

Expected: all suites, analysis and lints pass. No real network request occurs.

- [ ] **Step 3: Build docs and commit**

```bash
git add docs/handbook docs/site
git commit -m "docs(paper): operate public baseline captures"
```

- [ ] **Step 4: Final branch verification**

Run the repository's full Paper and Paper Execution suites against an isolated `*_paper_test` database when Docker is available, verify `git status --short`, then open a ready PR referencing #132/#196. Request one Codex review, address only actionable feedback, and merge only with every required check green and no unresolved blocking thread.
