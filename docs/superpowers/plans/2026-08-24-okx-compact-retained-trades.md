# OKX Compact Retained Trades Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep every valid OKX retained-trade suffix inside the canonical checkpoint budget without weakening overlap or identity validation.

**Architecture:** Add one focused codec that converts the exact legacy seven-key or modern nine-key OKX REST trade map to a canonical JSON string and expands that string, a transitional list or the legacy map. The live source decodes at every runtime boundary, compacts at every pagination write, and reserves the budget of the complete enclosing checkpoint before persistence; the checkpoint contract validates all supported read shapes fail-closed.

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
$compact = CanonicalJson::encode([
    'BTC-USDT-SWAP', '42', '100.5', '2', 'buy', '0', '1784970100000',
]);
self::assertSame($compact, OkxPaperRetainedTradeRow::compact($map));
self::assertSame($map, OkxPaperRetainedTradeRow::expand($compact));
self::assertSame($map, OkxPaperRetainedTradeRow::expand(json_decode(
    $compact,
    true,
    16,
    JSON_THROW_ON_ERROR,
)));
self::assertSame($map, OkxPaperRetainedTradeRow::expand($map));
```

Use a data provider to reject malformed JSON, a short list, non-string member,
missing map key, extra map key and numeric non-list map with
`okx_paper_retained_trade_row_invalid`.

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
    public static function compact(array $row): string;

    /** @return array{instId: string, tradeId: string, px: string, sz: string, side: string, source: string, ts: string} */
    public static function expand(array|string $row): array;
}
```

Require either the legacy `instId, tradeId, px, sz, side, source, ts` shape or
the modern shape adding `count` and `seqId`. Require string values except for
the supported integer-or-string `seqId`, and throw
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
canonical strings for trades `1001` and `1499`.

Run:

```bash
php vendor/bin/phpunit tests/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceTest.php \
  --filter testReconnectRecentTradeSuffixFitsTheCanonicalCheckpointBudget
```

Expected: `okx_paper_live_checkpoint_invalid` caused by
`paper_canonical_json_keys_exceeded`.

- [ ] **Step 2: Validate compact and legacy retained rows at checkpoint load**

In `OkxPaperLiveCheckpoint::pagination()`, when the stream ends in
`/public_trade`, require a string or array and call
`OkxPaperRetainedTradeRow::expand($row)` for validation. Keep the original row
shape in the checkpoint object so a legacy map/list remains readable until the
next live-source write. Candle pagination keeps its existing list validation.

- [ ] **Step 3: Decode on runtime read and compact on every runtime write**

Add private source helpers:

```php
/** @return list<array<string, string>> */
private function expandedRetainedTradeRows(array $rows): array;

/** @return list<string> */
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

### Task 3: Reserve the enclosing checkpoint budget

- [x] Add a padded, structurally valid 500-trade regression beside the saturated
  acknowledged-identity ledger and verify the old code leaks
  `okx_paper_live_checkpoint_invalid`.
- [x] Preflight the complete candidate checkpoint before every retained trade or
  candle pagination write, including canonical nodes, keys, scalar bytes and the
  final one-MiB storage size.
- [x] Never truncate a suffix: persist all rows or durably fail with
  `market_data_gap_unresolved`.
- [x] Prevent a terminal budget failure from being swallowed by the normal
  overlap-history fallback.

### Task 4: Preserve strict identity across OKX aggregate trade representations

- [x] Reproduce the r16 conflict with a WebSocket aggregate trade and its
  public REST constituent using the same `tradeId`.
- [x] Add canonical and cross-origin overlap digests; exclude only
  trade size from the latter.
- [x] Keep same-origin observations strict and partitioned by actual REST/WS
  origin.
- [x] Store compact bounded per-origin canonical history and reject earlier
  checkpoint contracts fail-closed.
- [x] Cover different REST/WS sizes, strict price conflict, reconnect overlap,
  restart origin, compaction and checkpoint-budget regressions.

### Task 5: Stream durable checkpoint identity verification

- [x] Reproduce the r17 128-MiB failure at a 737,653-byte saturated checkpoint
  during streaming queue persistence.
- [x] Replace the pre-write full checkpoint copy with incremental SHA-256 reads
  under the existing pinned-file and TOCTOU guards.
- [x] Pin the exact digest and a bounded memory delta on a checkpoint larger
  than 700 KiB.

### Task 6: Preserve both origin-specific canonical digests durably

- [x] Reproduce the review P1 where a skipped WebSocket copy remained only an
  in-memory observation after a REST acknowledgement.
- [x] Persist the missing REST or WebSocket canonical digest as soon as its
  cross-origin overlap is proven.
- [x] Restart and reject a changed canonical size for that same origin.

### Task 7: Reserve the missing-origin digest bytes

- [x] Reproduce the review P2 where filling a null origin slot grows a valid
  near-limit checkpoint after pagination preflight.
- [x] Move to schema v6 with fixed-width reserved digest slots, so filling the
  opposite origin is byte-size neutral.
- [x] Keep the saturated retained-suffix and one-MiB budget regressions green.

### Task 8: Drain an already-admitted frame before healthy stop

- [x] Reproduce the r19 timer race where a WebSocket frame is durably queued in
  the same event-loop iteration as the operator-stop callback.
- [x] Quiesce subsequent callback generations, drain and acknowledge the
  already-admitted queue, then persist the `stopping` transition.
- [x] Preserve fail-closed checks for stale sockets, reconnect/resync state,
  pending events and interrupted cleanup.
- [x] Let an in-flight heartbeat prove socket freshness before quiescing, while
  keeping pong timeout and reconnect terminal for the requested healthy stop.
- [x] Treat the operator request as the admission boundary while awaiting that
  proof: validate later frames for liveness but never queue them, preventing a
  hot public socket from exhausting backpressure before the quiet socket pongs.

### Task 9: Prove a complete public capture on the final boundary

- [x] Complete r22 for 450 seconds with execution forced disabled, schema v6,
  epoch 1 and no reconnect or resync.
- [x] Verify all 3,454 events independently with `PaperDatasetVerifier` baseline
  checks and an external SHA-256 recalculation.
- [x] Keep the result scoped as technical capture evidence, not a 24-hour
  representative baseline or a certified-trade claim.
