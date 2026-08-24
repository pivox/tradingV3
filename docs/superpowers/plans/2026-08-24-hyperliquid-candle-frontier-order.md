# Hyperliquid Candle Frontier Order Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Certify an otherwise exact Hyperliquid live checkpoint regardless of associative-map insertion order.

**Architecture:** Keep the checkpoint's existing canonical map and normalize only the verifier's locally reconstructed frontier map before its strict comparison. Exercise the real recorder, checkpoint store, and baseline verifier with multiple candle intervals arriving in non-lexical order.

**Tech Stack:** PHP 8.2+, PHPUnit 11, canonical Paper dataset/checkpoint contracts.

---

### Task 1: Reproduce the live ordering mismatch

**Files:**
- Modify: `trading-app/tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperLiveCaptureReplayEqualityTest.php`

- [ ] **Step 1: Write the failing test**

Add a `multipleCandleFrontiers` option to `completeDataset()`. When enabled,
append valid BTC `1m`, `5m`, then `15m` closed-candle events and finalize the
same three streams in the checkpoint. Add this assertion:

```php
public function testCandleFrontierMapOrderDoesNotInvalidateExactCheckpoint(): void
{
    [$directory] = $this->completeDataset(
        PaperMarketDataNetwork::MAINNET,
        multipleCandleFrontiers: true,
    );

    self::assertSame(
        PaperDatasetState::COMPLETE,
        (new PaperDatasetVerifier())->verifyForBaseline($directory)->state,
    );
}
```

- [ ] **Step 2: Run the test and verify RED**

Run:

```bash
cd trading-app
php bin/phpunit tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperLiveCaptureReplayEqualityTest.php --filter CandleFrontierMapOrder
```

Expected: `ERROR` caused by `paper_dataset_complete_failed`, whose previous
failure is `paper_dataset_hyperliquid_live_checkpoint_invalid`.

### Task 2: Normalize the reconstructed map

**Files:**
- Modify: `trading-app/src/Trading/Paper/Dataset/PaperDatasetVerifier.php`
- Test: `trading-app/tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperLiveCaptureReplayEqualityTest.php`

- [ ] **Step 1: Implement the minimal correction**

Immediately before the terminal checkpoint predicate, sort a local expected
map and compare it strictly:

```php
$expectedCandleFrontiers = $candleFrontiers;
ksort($expectedCandleFrontiers, \SORT_STRING);
```

Replace the order-sensitive predicate with:

```php
|| $checkpoint->finalizedCandleFrontiers !== $expectedCandleFrontiers
```

- [ ] **Step 2: Run the focused test and verify GREEN**

Run the command from Task 1. Expected: one passing test.

- [ ] **Step 3: Run adjacent Hyperliquid and verifier regressions**

Run:

```bash
cd trading-app
php bin/phpunit tests/Trading/Paper/Hyperliquid/Live tests/Trading/Paper/Dataset/PaperDatasetVerifierTest.php
```

Expected: all tests pass, including the existing fail-closed checkpoint cases.

### Task 3: Verify the real immutable evidence and deliver

**Files:**
- No private dataset files are modified or committed.

- [ ] **Step 1: Verify r11 through `PaperDatasetVerifier::verifyForBaseline()`**

Run a read-only PHP invocation against the private r11 directory. Expected:
state `complete`, venue `hyperliquid`, quality
`recorded_public_book_and_trades`, and 1,617 events.

- [ ] **Step 2: Recalculate the event checksum independently**

Run:

```bash
shasum -a 256 /absolute/private/r11/events.ndjson
```

Expected: `76d4c8e84adb3afa2f71c944a58745ef6447a626e7510c3fa8cf38b8a39e6045`.

- [ ] **Step 3: Run static and whitespace checks**

Run targeted PHPStan on the production and test files, then `git diff --check`.
Expected: no errors.

- [ ] **Step 4: Commit, push, open a ready PR, and request meaningful review**

Commit the test and correction together after GREEN. Do not include private
paths or datasets in Git. Merge only after required CI is green and no blocking
review thread remains.
