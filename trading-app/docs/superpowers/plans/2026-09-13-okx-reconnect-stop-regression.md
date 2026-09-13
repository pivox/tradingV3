# OKX Reconnect Stop Regression Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore the complete OKX capture regression slice while preserving the first fail-closed reason when a bounded capture expires during book recovery.

**Architecture:** Keep the strict reconnect and healthy-stop contracts unchanged. Add one focused source regression around an operator stop during the reconnect book-overlap wait, preserve an already-terminal exception in the recovery dispatcher, and update the replay-equality fixture to supply the fresh ETH book authority required by the latest admission policy.

**Tech Stack:** PHP 8.4, Symfony Console, ReactPHP event loop, PHPUnit 11.

---

### Task 1: Preserve the first terminal failure

**Files:**
- Modify: `tests/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceTest.php`
- Modify: `src/Trading/Paper/Okx/Live/OkxPaperPublicLiveSource.php:3030-3040`

- [ ] **Step 1: Write the failing regression**

Build a normal streaming fixture, disconnect the public socket, complete the
paired reconnect acknowledgements, and invoke `requestHealthyOperatorStop()`
from the deterministic loop while `reconnectBookEvents()` is waiting for fresh
book authority. Assert that both the thrown exception and
`$source->failureReason()` are exactly
`okx_paper_public_healthy_stop_invalid`.

- [ ] **Step 2: Verify RED**

Run:

```bash
php bin/phpunit tests/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceTest.php --filter HealthyStopDuringReconnectBookWait
```

Expected: failure because the outer recovery catch currently replaces the
terminal reason with `okx_paper_live_checkpoint_invalid`.

- [ ] **Step 3: Implement the minimal dispatcher guard**

In the `reconnectBookEvents()` catch, rethrow immediately when `$this->stopped`
is already true. Keep identity-conflict translation and the normal
`market_data_gap_unresolved` fallback unchanged.

- [ ] **Step 4: Verify GREEN**

Run the exact focused command from Step 2. Expected: one passing test and no
error.

### Task 2: Repair replay-equality fixture authority

**Files:**
- Modify: `tests/Trading/Paper/Okx/Live/OkxPaperLiveCaptureReplayEqualityTest.php:1244-1246`

- [ ] **Step 1: Supply the required fresh ETH book frame**

On the first replay-loop run, send an ETH websocket book update whose
`prevSeqId` is the REST snapshot sequence `9400` and whose `seqId` is `9401`.
On the subsequent run, request the healthy operator stop. This preserves the
test's exact replay and healthy completion assertions while matching the new
bounded admission contract.

- [ ] **Step 2: Verify the end-to-end scenario**

Run:

```bash
php bin/phpunit tests/Trading/Paper/Okx/Live/OkxPaperLiveCaptureReplayEqualityTest.php --filter testDisconnectRestartHistoricalOverlapAndHealthyStopReplayExactly
```

Expected: one passing test.

### Task 3: Preflight the 24-hour launch

**Files:**
- Verify: all changed capture files and local runtime state

- [ ] **Step 1: Run the complete changed capture slice**

Run the command covering the two commands, capture layer, dataset recorder,
OKX recovery/equality and Hyperliquid source tests. Expected: 660 tests, zero
failures and zero errors.

- [ ] **Step 2: Verify static and runtime boundaries**

Run `git diff --check`, verify the worktree commit and clean status, confirm
`PAPER_EXECUTION_ENABLED=0` in the launch environment, validate NTP and disk,
and probe only the credential-free OKX and Hyperliquid public endpoints.

- [ ] **Step 3: Commit and push the regression fix**

Commit only the design, plan, source guard and tests to
`fix/paper-capture-throughput`, then push the branch. Do not request another
review.

- [ ] **Step 4: Provide Linux detached launch and monitoring commands**

Use `systemd-inhibit`, `nohup`, absolute paths, separate supervisors/logs, a
private data root, unique UTC prefixes, eight bounded attempts and explicit
execution-disable variables. Do not launch on the user's behalf.
