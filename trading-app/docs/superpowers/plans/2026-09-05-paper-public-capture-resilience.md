# Paper Public Capture Resilience Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make OKX recover the observed public-trade outage exactly and make unrecoverable Hyperliquid capture failures automatically start a fresh bounded attempt.

**Architecture:** Keep the existing single-attempt capture command as the only dataset writer. Expand OKX's finite exact-overlap envelope, then add a small supervisor service whose injected process executor launches isolated single-attempt commands with unique dataset IDs.

**Tech Stack:** PHP 8.3, Symfony Console/Process/DI, ReactPHP, PHPUnit 10, PHPStan.

---

### Task 1: Expand the finite OKX recovery envelope

**Files:**
- Modify: `src/Trading/Paper/Okx/Live/OkxPaperLivePolicy.php`
- Modify: `tests/Trading/Paper/Okx/Live/OkxPaperPublicWebSocketTransportTest.php`
- Modify: `tests/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceTest.php`

- [ ] **Step 1: Pin the desired policy in a failing test**

Change the existing policy assertions to expect `RESYNC_ATTEMPT_TIMEOUT_SECONDS = 900.0`, `MAX_OVERLAP_HISTORY_PAGES = 250`, `MAX_RETAINED_RECOVERY_ROWS = 25_500`, and `MAX_CHECKPOINT_BYTES = 4_194_304`.

- [ ] **Step 2: Prove the old page ceiling fails**

Add a focused live-source test whose fake `historyTrades()` needs 51 pages before returning the exact durable frontier. Assert recovery reaches the frontier, emits the missing trades chronologically once, and does not set `failure_reason`.

- [ ] **Step 3: Run the focused tests and observe RED**

Run:

```bash
php bin/phpunit tests/Trading/Paper/Okx/Live/OkxPaperPublicWebSocketTransportTest.php tests/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceTest.php --filter '/(Pins|BeyondFormerPageBound)/'
```

Expected: failures showing the old 240/50/5,500/2 MiB policy or terminal `market_data_gap_unresolved` at page 50.

- [ ] **Step 4: Apply the minimal policy change**

Set only these constants:

```php
public const RESYNC_ATTEMPT_TIMEOUT_SECONDS = 900.0;
public const MAX_OVERLAP_HISTORY_PAGES = 250;
public const MAX_RETAINED_RECOVERY_ROWS = 25_500;
public const MAX_CHECKPOINT_BYTES = 4_194_304;
```

- [ ] **Step 5: Run focused and checkpoint/replay tests**

Run:

```bash
php bin/phpunit tests/Trading/Paper/Okx/Live/OkxPaperPublicWebSocketTransportTest.php tests/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceTest.php tests/Trading/Paper/Okx/Live/OkxPaperLiveCheckpointStoreTest.php tests/Trading/Paper/Okx/Live/OkxPaperLiveCaptureReplayEqualityTest.php
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add src/Trading/Paper/Okx/Live/OkxPaperLivePolicy.php tests/Trading/Paper/Okx/Live/
git commit -m "fix(paper): widen exact OKX recovery envelope"
```

### Task 2: Add an isolated capture-attempt executor

**Files:**
- Create: `src/Trading/Paper/Capture/PaperPublicCaptureAttemptExecutorInterface.php`
- Create: `src/Trading/Paper/Capture/PaperPublicCaptureAttemptResult.php`
- Create: `src/Trading/Paper/Capture/SymfonyPaperPublicCaptureAttemptExecutor.php`
- Create: `tests/Trading/Paper/Capture/SymfonyPaperPublicCaptureAttemptExecutorTest.php`

- [ ] **Step 1: Write executor tests first**

Test that the production executor builds this argument vector and forces execution off:

```php
[
    PHP_BINARY,
    $projectDirectory . '/bin/console',
    'app:paper-market:public-capture',
    '--venue=' . $venue,
    '--dataset-id=' . $datasetId,
    '--duration-sec=' . $durationSeconds,
    '--no-interaction',
]
```

The child environment must contain `PAPER_EXECUTION_ENABLED=0`. The result exposes only the integer exit code; child stdout/stderr are not propagated into supervisor evidence.

- [ ] **Step 2: Run the new test and observe RED**

Run `php bin/phpunit tests/Trading/Paper/Capture/SymfonyPaperPublicCaptureAttemptExecutorTest.php`.

Expected: class/interface not found.

- [ ] **Step 3: Implement the interface, result, and Symfony Process adapter**

Define `execute(string $venue, string $datasetId, int $durationSeconds): PaperPublicCaptureAttemptResult`. Build a Symfony `Process` in `%kernel.project_dir%`, inherit the environment while overriding `PAPER_EXECUTION_ENABLED` to `0`, disable the process timeout because the capture duration is already bounded, and map launch exceptions to exit code 127.

- [ ] **Step 4: Run the executor test and observe GREEN**

Run `php bin/phpunit tests/Trading/Paper/Capture/SymfonyPaperPublicCaptureAttemptExecutorTest.php`.

Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/Trading/Paper/Capture/PaperPublicCaptureAttempt* src/Trading/Paper/Capture/SymfonyPaperPublicCaptureAttemptExecutor.php tests/Trading/Paper/Capture/SymfonyPaperPublicCaptureAttemptExecutorTest.php
git commit -m "feat(paper): isolate public capture attempts"
```

### Task 3: Add the bounded supervisor and command

**Files:**
- Create: `src/Trading/Paper/Capture/PaperPublicCaptureSupervisor.php`
- Create: `src/Trading/Paper/Capture/PaperPublicCaptureSupervisorResult.php`
- Create: `src/Command/PaperPublicCaptureSupervisorCommand.php`
- Create: `tests/Trading/Paper/Capture/PaperPublicCaptureSupervisorTest.php`
- Create: `tests/Command/PaperPublicCaptureSupervisorCommandTest.php`

- [ ] **Step 1: Write failing supervisor tests**

Use a fake executor returning `[1, 1, 0]`. Assert the supervisor derives:

```text
representative-hyperliquid-20260905-attempt-001-mainnet
representative-hyperliquid-20260905-attempt-002-mainnet
representative-hyperliquid-20260905-attempt-003-mainnet
```

Assert it stops after success, and a fake returning only failures stops exactly at the configured attempt count.

- [ ] **Step 2: Write failing command tests**

Pin options `--venue`, `--dataset-prefix`, `--duration-sec`, and `--attempts`. Assert invalid venue/duration/attempt counts are rejected before executor use. Assert output is canonical JSON containing only schema version, `ok`, venue, attempts used, and successful dataset ID or generic blocker.

- [ ] **Step 3: Run tests and observe RED**

Run:

```bash
php bin/phpunit tests/Trading/Paper/Capture/PaperPublicCaptureSupervisorTest.php tests/Command/PaperPublicCaptureSupervisorCommandTest.php
```

Expected: missing classes.

- [ ] **Step 4: Implement minimal supervisor behavior**

Validate venue through `PaperMarketDataVenue`, require duration 300..604800, attempts 1..99, validate every derived ID with `PaperDatasetManifest::assertDatasetId()`, execute sequentially, and return immediately on exit code zero. Never delete a failed dataset and never reuse an ID.

- [ ] **Step 5: Implement the redacted command adapter**

Register `app:paper-market:public-capture-supervise`. Catch every throwable and emit the generic blocker `paper_public_capture_supervision_failed` without exception details or filesystem paths.

- [ ] **Step 6: Run tests and observe GREEN**

Run the two tests from Step 3. Expected: all tests pass.

- [ ] **Step 7: Commit**

```bash
git add src/Trading/Paper/Capture/PaperPublicCaptureSupervisor* src/Command/PaperPublicCaptureSupervisorCommand.php tests/Trading/Paper/Capture/PaperPublicCaptureSupervisorTest.php tests/Command/PaperPublicCaptureSupervisorCommandTest.php
git commit -m "feat(paper): supervise bounded public captures"
```

### Task 4: Document and verify the operational launch

**Files:**
- Create: `docs/handbook/paper-public-capture.md`

- [ ] **Step 1: Document the safe macOS invocation**

Document a detached invocation that sets `PAPER_EXECUTION_ENABLED=0`, enables public acquisition only, uses `caffeinate -dimsu`, gives each venue an explicit bounded attempt count, and writes operator logs outside dataset directories. State that Hyperliquid attempts cannot survive a public-trade gap and are intentionally restarted from zero.

- [ ] **Step 2: Run the full relevant verification**

Run:

```bash
php bin/phpunit tests/Trading/Paper/Okx tests/Trading/Paper/Hyperliquid tests/Trading/Paper/Capture tests/Command/PaperPublicCaptureCommandTest.php tests/Command/PaperPublicCaptureSupervisorCommandTest.php
composer phpstan
composer cs-check
git diff --check origin/main...HEAD
```

Expected: zero failures and a clean diff check.

- [ ] **Step 3: Commit**

```bash
git add docs/handbook
git commit -m "docs(paper): document resilient public capture"
```

### Task 5: Deliver and relaunch

**Files:** none

- [ ] **Step 1: Push and open a PR linked to #132 and #190**

- [ ] **Step 2: Request Codex review and address only substantive feedback**

- [ ] **Step 3: Merge when required checks and blocking threads are clear**

- [ ] **Step 4: Update from `origin/main` and launch supervised OKX/Hyperliquid captures with execution disabled**

- [ ] **Step 5: Verify process independence, `caffeinate` assertions, advancing event files, and non-terminal checkpoints**
