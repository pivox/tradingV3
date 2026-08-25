# Paper Durable Capture Batches Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Persist each Hyperliquid trade chunk of up to 256 events with one crash-safe dataset commit so public capture can keep up with live traffic.

**Architecture:** A small optional source capability exposes the remaining events protected by the current durable checkpoint. The capture loop buffers only those events, atomically appends the group through a versioned recorder intent, then acknowledges the boundary event; all existing sources retain append-before-ack behavior.

**Tech Stack:** PHP 8.4, Symfony Console, ReactPHP, PHPUnit 11, PHPStan, canonical NDJSON and authenticated filesystem intents.

---

### Task 1: Declare and exercise source batch boundaries

**Files:**
- Create: `trading-app/src/Trading/Paper/MarketData/PaperDurableBatchSourceInterface.php`
- Modify: `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicLiveSource.php`
- Test: `trading-app/tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicLiveSourceTest.php`

- [ ] Add a failing source test asserting `pendingDurableBatchSize()` counts down to 1 for a trade frame and returns 1 for snapshot events.
- [ ] Run `vendor/bin/phpunit --filter testExposesCurrentDurableTradeBatchBoundary tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicLiveSourceTest.php`; expect failure because the method does not exist.
- [ ] Define `pendingDurableBatchSize(): int` and return `count($checkpoint->pendingContinuation['remaining_trade_rows']) + 1` only for a valid trade continuation; return 1 otherwise.
- [ ] Coalesce consecutive queued trade frames in FIFO order up to 256 rows, accept pongs immediately, and defer the first non-trade frame for the next loop iteration.
- [ ] Re-run the filtered test; expect PASS.

### Task 2: Add atomic recorder batches

**Files:**
- Modify: `trading-app/src/Trading/Paper/Dataset/PaperDatasetRecorder.php`
- Test: `trading-app/tests/Trading/Paper/Dataset/PaperDatasetRecorderTest.php`

- [ ] Add failing tests asserting `appendBatch([$first, $second])` returns two `APPENDED` results, publishes ordered NDJSON and manifest facts, and uses one events flush plus one v2 append intent containing both event IDs and their concatenated canonical suffix.
- [ ] Add a failing restart test that stages a partial v2 suffix and asserts construction truncates it to the authenticated prefix; add the complete-suffix counterpart asserting both events become `REPLAYED`.
- [ ] Run the new recorder tests; expect method-not-found failures.
- [ ] Implement `appendBatch(array $events): array`: reject empty/oversized/non-event lists, recover and reload once under the dataset lock, validate identities and sequence state in order, accept only a replayed prefix followed by a new suffix, write one v2 intent and one durable concatenated suffix, then update manifest and in-memory facts event by event.
- [ ] Extend intent decoding and recovery to accept v1 unchanged and v2 `{event_ids, canonical_suffix_base64, canonical_suffix_sha256}`; parse every bounded NDJSON line canonically before trusting the intent.
- [ ] Re-run the new tests and the complete `PaperDatasetRecorderTest`; expect PASS.

### Task 3: Batch the capture loop without weakening acknowledgements

**Files:**
- Modify: `trading-app/src/Trading/Paper/Capture/PaperPublicDatasetCapture.php`
- Test: `trading-app/tests/Trading/Paper/Capture/PaperPublicDatasetCaptureTest.php`

- [ ] Add a batch-capable fake source and a failing test proving intermediate events may be acknowledged under the same durable source checkpoint, the recorder contains the full batch before its boundary is acknowledged, and observers run only after the durable commit.
- [ ] Keep the existing ordinary-source test as the explicit regression proving per-event append-before-ack.
- [ ] Implement a bounded buffer: acknowledge intermediate events only when `pendingDurableBatchSize() > 1`; at size 1 call `appendBatch`, acknowledge the boundary, notify observers in event order, and clear the buffer. Fail closed if the reported countdown is inconsistent.
- [ ] Run `PaperPublicDatasetCaptureTest` and `PaperPublicCaptureRunnerTest`; expect PASS.

### Task 4: Verify crash safety and live throughput

**Files:**
- Modify only if a failing invariant requires a scoped fix.

- [ ] Run `vendor/bin/phpunit tests/Trading/Paper/Dataset/PaperDatasetRecorderTest.php tests/Trading/Paper/Capture tests/Trading/Paper/Hyperliquid`; expect all tests PASS.
- [ ] Run targeted PHPStan with `--memory-limit=512M` over every changed PHP file; expect no errors.
- [ ] Run `git diff --check`; expect no output.
- [ ] Start a unique five-minute Hyperliquid public mainnet dataset with both acquisition flags enabled; never use private channels or execution endpoints.
- [ ] Verify manifest `complete`, `sequence_gaps=[]`, non-null matching SHA-256, checkpoint continuity true, one source epoch, zero reconnects, and healthy stop requested/completed.
- [ ] Push the branch, update PR #423 with exact test and dataset evidence, mark ready, request Codex review, resolve genuine feedback, and merge only when checks and review threads are clean.
