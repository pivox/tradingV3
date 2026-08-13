# #191 Authenticated Public Book Tape Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Produce an immutable dataset-bound tape of verified non-synthetic public level-one book facts without inferring fills or queue position.

**Architecture:** PHP extends the existing verified Paper snapshot adapter with one strict cross-venue normalized book value object and deterministic NDJSON output. Python mirrors the record contract and binds canonical tape artifacts to the exact normalized candle dataset and Paper source checksum.

**Tech Stack:** PHP 8.3, PHPUnit, Brick Math, Python 3.12, Pydantic v2, pytest, canonical JSON and SHA-256.

---

### Task 1: PHP normalized public-book contract

**Files:**
- Create: `trading-app/src/Trading/Paper/Backtesting/NormalizedBacktestPublicBook.php`
- Modify: `trading-app/src/Trading/Paper/Backtesting/PaperBacktestDataset.php`
- Test: `trading-app/tests/Trading/Paper/Backtesting/PaperBacktestDatasetAdapterTest.php`

- [ ] **Step 1: Write failing value-contract and dataset tests**

Add assertions that `backtest-public-book.v1` exposes strict source identity,
timestamps, crossed-book rejection, venue-specific quantity units and nullable
order-count semantics. Add duplicate source-record and foreign-checksum dataset
rejections.

- [ ] **Step 2: Run the focused PHP test and verify RED**

Run: `docker compose exec -T trading-app-php php bin/phpunit tests/Trading/Paper/Backtesting/PaperBacktestDatasetAdapterTest.php`
Expected: FAIL because `NormalizedBacktestPublicBook` and `publicBooks` do not exist.

- [ ] **Step 3: Implement the minimal immutable value and dataset collection**

Create the strict readonly value with `toArray()` and add a validated,
source-bound `publicBooks` list to `PaperBacktestDataset`.

- [ ] **Step 4: Re-run focused PHP tests and verify GREEN**

Run the command from Step 2.
Expected: PASS.

### Task 2: PHP Paper projection and encoder

**Files:**
- Modify: `trading-app/src/Trading/Paper/Backtesting/PaperBacktestDatasetAdapter.php`
- Modify: `trading-app/src/Trading/Paper/Backtesting/PaperBacktestDatasetEncoder.php`
- Modify: `trading-app/tests/Trading/Paper/Backtesting/PaperBacktestDatasetAdapterTest.php`
- Create: `trading-app/tests/Fixtures/paper-backtesting/public-books.ndjson`

- [ ] **Step 1: Write failing OKX, Hyperliquid, exclusion and encoder tests**

Cover exact admitted live payloads, deterministic ordering, explicit units,
OKX order counts, Hyperliquid null order counts, invalid shape/origin/spread,
historical synthetic exclusion, non-live-quality exclusion and canonical fixture
bytes.

- [ ] **Step 2: Run the focused test and verify RED**

Run the Task 1 PHPUnit command.
Expected: FAIL because the adapter still ignores public books and the encoder has
no `publicBooks()` output.

- [ ] **Step 3: Implement minimal strict projection and encoding**

Normalize only certified public origins, require exact payload keys, canonicalize
prices/quantities/counts, sort by availability/event/source ID and emit NDJSON
through the existing forbidden-strategy guard.

- [ ] **Step 4: Generate the checked-in fixture from the public encoder**

Use the exact bytes asserted by the PHPUnit golden test and commit only the
canonical one-record NDJSON fixture ending in a newline.

- [ ] **Step 5: Re-run focused PHP tests and verify GREEN**

Run the Task 1 PHPUnit command.
Expected: PASS.

### Task 3: Python immutable public-book tape

**Files:**
- Create: `python-orchestrator/app/backtesting/public_book_tape.py`
- Create: `python-orchestrator/tests/test_backtesting_public_book_tape.py`

- [ ] **Step 1: Write failing record and tape tests**

Cover strict venue semantics, crossed books, look-ahead, dataset/source binding,
per-symbol stream coverage, deterministic ordering, duplicates, artifact
tampering, the 30,000-record bound and PHP fixture parsing.

- [ ] **Step 2: Run the Python test and verify RED**

Run: `docker compose exec -T python-orchestrator pytest -q tests/test_backtesting_public_book_tape.py`
Expected: FAIL because `app.backtesting.public_book_tape` does not exist.

- [ ] **Step 3: Implement the minimal frozen record, artifacts and verifier**

Mirror `public_execution_tape.py` while keeping a distinct
`backtest-public-book-tape.v1` manifest, `books_checksum`, immutable records and
no execution behavior.

- [ ] **Step 4: Re-run the Python test and verify GREEN**

Run the command from Step 2.
Expected: PASS.

### Task 4: Documentation and verification

**Files:**
- Modify: `docs/backtesting.md`

- [ ] **Step 1: Add strict usage and non-claim documentation**

Document the PHP `publicBooks()` encoder, Python verification call, explicit
venue units and the prohibition on treating L1 evidence as queue/fill proof.

- [ ] **Step 2: Run focused static and test verification**

Run PHP CS/style checks for changed PHP files, targeted PHPUnit, targeted pytest
and Python lint/type checks used by the repository.
Expected: all commands exit 0.

- [ ] **Step 3: Run broader regression suites**

Run the backtesting/Paper PHP suite, the full Python suite, PHPStan and strict
documentation build.
Expected: all commands exit 0.

- [ ] **Step 4: Review and commit the implementation**

Inspect `git diff --check`, forbidden identity strings, artifact size proof and
the complete diff. Commit the scoped implementation with an issue-referencing
message.

### Task 5: GitHub delivery

**Files:** no repository files.

- [ ] **Step 1: Push and open a ready PR linked to #191**

Include scope, explicit non-goals and exact verification evidence.

- [ ] **Step 2: Request Codex review and wait for real feedback**

Tag `@codex`, inspect thread-level state and checks, and do not create artificial
review cycles when approval is already explicit.

- [ ] **Step 3: Address actionable findings with TDD**

For each real defect, reproduce RED, implement the smallest fix, verify GREEN,
push and re-check threads/checks.

- [ ] **Step 4: Merge only after Codex approval and green required checks**

A Codex thumbs-up or explicit no-major-issues comment counts as approval. Pull
the merged main before selecting the next #191 slice.
