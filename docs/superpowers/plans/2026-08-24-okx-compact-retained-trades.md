# OKX Compact Retained Trades Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep every valid OKX retained-trade suffix inside the canonical checkpoint budget without weakening overlap or identity validation.

**Architecture:** Add one focused codec that converts the exact seven-key OKX REST trade map to a seven-element checkpoint list and expands either that list or the legacy map. The live source decodes at every runtime boundary and compacts at every pagination write; the checkpoint contract validates both shapes fail-closed.

**Tech Stack:** PHP 8.4, Symfony 7.1, PHPUnit 11, PHPStan, existing canonical JSON and OKX checkpoint contracts.

---

### Task 1: Strict retained-trade row codec

**Files:**
- Create: `trading-app/src/Trading/Paper/Okx/Live/OkxPaperRetainedTradeRow.php`
- Create: `trading-app/tests/Trading/Paper/Okx/Live/OkxPaperRetainedTradeRowTest.php`

- [ ] **Step 1: Write failing compact/legacy/malformed tests**

Create tests that pin:

```php
$map = [
    'instId' => 'BTC-USDT-SWAP',
    'tradeId' => '42',
    'px' => '100.5',
    'sz' => '2',
    'side' => 'buy',
    'source' => '0',
    'ts' => '1784970100000',
];
$compact = ['BTC-USDT-SWAP', '42', '100.5', '2', 'buy', '0', '1784970100000'];
self::assertSame($compact, OkxPaperRetainedTradeRow::compact($map));
self::assertSame($map, OkxPaperRetainedTradeRow::expand($compact));
self::assertSame($map, OkxPaperRetainedTradeRow::expand($map));
```

Use a data provider to reject a short list, non-string member, missing map key,
extra map key and numeric non-list map with `okx_paper_retained_trade_row_invalid`.

- [ ] **Step 2: Run the codec test and verify RED**

Run:

```bash
cd trading-app
php vendor/bin/phpunit tests/Trading/Paper/Okx/Live/OkxPaperRetainedTradeRowTest.php
```

Expected: failure because `OkxPaperRetainedTradeRow` does not exist.

- [ ] **Step 3: Implement the minimal strict codec**

Create a final class with this public contract:

```php
final class OkxPaperRetainedTradeRow
{
    /** @return list<string> */
    public static function compact(array $row): array;

    /** @return array{instId: string, tradeId: string, px: string, sz: string, side: string, source: string, ts: string} */
    public static function expand(array $row): array;
}
```

Require exactly `instId, tradeId, px, sz, side, source, ts`, require every
value to be a string, and throw
`InvalidArgumentException('okx_paper_retained_trade_row_invalid')` for every
other shape.

- [ ] **Step 4: Run the codec test and verify GREEN**

Run the Task 1 PHPUnit command. Expected: all codec cases pass.

### Task 2: Compact every trade pagination checkpoint

**Files:**
- Modify: `trading-app/src/Trading/Paper/Okx/Live/OkxPaperLiveCheckpoint.php`
- Modify: `trading-app/src/Trading/Paper/Okx/Live/OkxPaperPublicLiveSource.php`
- Modify: `trading-app/tests/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceTest.php`
- Test: `trading-app/tests/Trading/Paper/Okx/Live/OkxPaperLiveCheckpointStoreTest.php`

- [ ] **Step 1: Strengthen the saturated-ledger regression and verify RED**

Move the durable frontier in
`testReconnectRecentTradeSuffixFitsTheCanonicalCheckpointBudget()` from row 497
to row 0 so 499 accepted trades must survive. Assert the first emitted trade is
`1001`, retained row count is 499, and the first/last persisted rows are exact
seven-element lists for trades `1001` and `1499`.

Run:

```bash
php vendor/bin/phpunit tests/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceTest.php \
  --filter testReconnectRecentTradeSuffixFitsTheCanonicalCheckpointBudget
```

Expected: `okx_paper_live_checkpoint_invalid` caused by
`paper_canonical_json_keys_exceeded`.

- [ ] **Step 2: Validate compact and legacy retained rows at checkpoint load**

In `OkxPaperLiveCheckpoint::pagination()`, when the stream ends in
`/public_trade`, call `OkxPaperRetainedTradeRow::expand($row)` for validation.
Keep the original row shape in the checkpoint object so a legacy map remains
readable until the next live-source write. Candle pagination keeps its existing
list validation.

- [ ] **Step 3: Decode on runtime read and compact on every runtime write**

Add private source helpers:

```php
/** @return list<array<string, string>> */
private function expandedRetainedTradeRows(array $rows): array;

/** @return list<list<string>> */
private function compactRetainedTradeRows(array $rows): array;
```

Use expansion in `pendingReconnectContinuation()`, the `history_trades` branch
of `reconnectFrontierEvents()`, and before combining saved rows with a new
history page. Use compaction in `persistRecentTradeSuffix()` and both initial
and subsequent `recoverTradesThroughHistory()` writes. Convert codec validation
errors to the existing fail-closed recovery/checkpoint reason at the boundary.

- [ ] **Step 4: Run focused restart, history and conflict regressions**

Run:

```bash
php vendor/bin/phpunit tests/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceTest.php \
  --filter 'testReconnectRecentTradeSuffixFitsTheCanonicalCheckpointBudget|testReconnectCurrentResponseSuffixSurvivesCrashAfterFirstPendingEvent|testHistoryTradePaginationRestartCallsSavedCursorAndEmitsDurableSuffix|testSavedPaginationFailsTerminallyWithoutGrantingFreshBudget'
```

Expected: all focused cases pass, including legacy associative restart rows.

- [ ] **Step 5: Run broad verification**

Run:

```bash
php vendor/bin/phpunit tests/Trading/Paper/Okx/Live
php vendor/bin/phpstan analyse --no-progress --memory-limit=1G \
  src/Trading/Paper/Okx/Live/OkxPaperRetainedTradeRow.php \
  src/Trading/Paper/Okx/Live/OkxPaperLiveCheckpoint.php \
  src/Trading/Paper/Okx/Live/OkxPaperPublicLiveSource.php \
  tests/Trading/Paper/Okx/Live/OkxPaperRetainedTradeRowTest.php \
  tests/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceTest.php
git diff --check
```

Expected: PHPUnit and PHPStan exit zero, and the diff check prints nothing.

- [ ] **Step 6: Commit and request review**

```bash
git add trading-app/src/Trading/Paper/Okx/Live/OkxPaperRetainedTradeRow.php \
  trading-app/src/Trading/Paper/Okx/Live/OkxPaperLiveCheckpoint.php \
  trading-app/src/Trading/Paper/Okx/Live/OkxPaperPublicLiveSource.php \
  trading-app/tests/Trading/Paper/Okx/Live/OkxPaperRetainedTradeRowTest.php \
  trading-app/tests/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceTest.php
git commit -m "fix(paper): compact OKX retained trades"
```

Push the branch, reply in the P1 thread with the exact test evidence, resolve it,
and request a fresh Codex review before marking PR #409 ready.
