# Paper Dataset Chunked Line Reader Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent public Paper capture memory exhaustion by making NDJSON line-read allocation proportional to the actual line while preserving the exact format limit.

**Architecture:** Keep `PaperDatasetRecorderFilesystem::readLine()` as the filesystem boundary and change only `PaperDatasetLineReader` to call it repeatedly with a 64 KiB chunk bound. The shared reader continues to serve both recorder and verifier, so one regression locks both paths.

**Tech Stack:** PHP 8.4 streams, PHPUnit 11, PHPStan, existing Paper dataset recorder/verifier.

---

### Task 1: Bounded chunked line reads

**Files:**
- Modify: `trading-app/src/Trading/Paper/Dataset/PaperDatasetLineReader.php`
- Modify: `trading-app/tests/Trading/Paper/Dataset/PaperDatasetVerifierTest.php`

- [ ] **Step 1: Write the failing allocation-bound test**

Add a verifier test that writes a valid line larger than two 65,536-byte
chunks, reads it through `PaperDatasetLineReader` and asserts exact equality,
at least three filesystem reads, and:

```php
self::assertLessThanOrEqual(
    PaperDatasetLineReader::READ_CHUNK_BYTES + 1,
    max($filesystem->lineReadLengths),
);
```

Run:

```bash
cd trading-app
php vendor/bin/phpunit tests/Trading/Paper/Dataset/PaperDatasetVerifierTest.php \
  --filter testBoundedLineReaderStreamsLongLinesInBoundedChunks
```

Expected: failure because the current request is
`MAX_CANONICAL_EVENT_LINE_BYTES + 1`.

- [ ] **Step 2: Implement the chunk loop**

Expose `public const READ_CHUNK_BYTES = 65_536`. Repeatedly call
`readLine($handle, min(READ_CHUNK_BYTES, remaining) + 1, $operation)`, retain
the returned chunks, and stop only on newline. Throw `$invalidLineError` on
partial EOF, a maximum-sized unterminated line or any overflow. Join and return
the chunks only after a valid newline.

- [ ] **Step 3: Update the shared-bound assertion**

Change `testVerifierReadsEventLinesThroughTheSharedBoundContract()` to expect
`PaperDatasetLineReader::READ_CHUNK_BYTES + 1` as the only requested size for
ordinary fixture lines.

- [ ] **Step 4: Run focused boundary tests**

Run:

```bash
php vendor/bin/phpunit tests/Trading/Paper/Dataset/PaperDatasetVerifierTest.php \
  --filter 'testBoundedLineReader|testVerifierReadsEventLinesThroughTheSharedBound|testVerifierRejectsAnEventLineExceedingTheSharedBound|testVerifierRejectsAMaximumLengthFragmentWithoutNewline'
```

Expected: all line-boundary cases pass.

- [ ] **Step 5: Run broad verification**

Run:

```bash
php vendor/bin/phpunit \
  tests/Trading/Paper/Dataset/PaperDatasetRecorderTest.php \
  tests/Trading/Paper/Dataset/PaperDatasetVerifierTest.php \
  tests/Trading/Paper/Capture/PaperPublicDatasetCaptureTest.php
php vendor/bin/phpstan analyse --no-progress --memory-limit=1G \
  src/Trading/Paper/Dataset/PaperDatasetLineReader.php \
  tests/Trading/Paper/Dataset/PaperDatasetVerifierTest.php
git diff --check
```

Expected: PHPUnit and PHPStan exit zero; diff check prints nothing.

- [ ] **Step 6: Commit, push and re-review**

Stage only the design, plan, reader and verifier test. Commit as
`fix(paper): stream bounded dataset lines`, push PR #409, post the live r14
memory evidence and request a new Codex review.
