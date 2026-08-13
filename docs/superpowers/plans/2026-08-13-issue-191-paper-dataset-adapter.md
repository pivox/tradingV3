# #191 Paper Dataset Adapter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert one verifier-owned, certifiable PHP Paper dataset v2 snapshot into strict `backtest-candle.v1` records consumable by the deterministic Python dataset builder.

**Architecture:** Extend `PaperDatasetVerifier` with one immutable snapshot result built from the events already parsed by its pinned verification scan. A focused Paper backtesting adapter normalizes only confirmed candle events and a canonical encoder emits the cross-language source/candle contract; Python validates a checked-in golden fixture through the existing strict models.

**Tech Stack:** PHP 8.3, PHPUnit, Brick Math, Python 3/Pydantic/pytest, canonical JSON/NDJSON.

---

### Task 1: Verifier-owned event snapshot

**Files:**
- Create: `trading-app/src/Trading/Paper/Dataset/VerifiedPaperDatasetSnapshot.php`
- Modify: `trading-app/src/Trading/Paper/Dataset/PaperDatasetVerifier.php`
- Test: `trading-app/tests/Trading/Paper/Dataset/PaperDatasetVerifierTest.php`

- [x] **Step 1: Write a failing snapshot contract test**

Add a test that builds a complete v2 dataset with one candle and one non-candle event, calls `verifyBaselineSnapshot()`, and asserts the manifest plus the exact two ordered `PaperMarketEvent` objects. Also assert the snapshot arrays cannot be changed through caller aliases.

- [x] **Step 2: Run the focused test and verify RED**

Run:

```bash
cd trading-app
php bin/phpunit tests/Trading/Paper/Dataset/PaperDatasetVerifierTest.php --filter BaselineSnapshot
```

Expected: failure because `verifyBaselineSnapshot()` and `VerifiedPaperDatasetSnapshot` do not exist.

- [x] **Step 3: Add the frozen snapshot and collect events inside `scan()`**

Create a `final readonly` result with:

```php
public function __construct(
    public PaperDatasetManifest $manifest,
    public array $events,
) {}
```

Validate that every item is a `PaperMarketEvent`, copy with `array_values`, and expose no mutator. Extend the existing private scan result with `events`, appending each already parsed event exactly once. Factor the current verification body into a private method returning the manifest and events so `verify()`, `verifyForBaseline()` and `verifyBaselineSnapshot()` share one pinned read and final stability sequence. Apply the existing baseline provenance/model checks before returning the snapshot.

- [x] **Step 4: Run the verifier tests and verify GREEN**

```bash
cd trading-app
php bin/phpunit tests/Trading/Paper/Dataset/PaperDatasetVerifierTest.php
```

Expected: all tests pass with no warning.

- [x] **Step 5: Commit Task 1**

```bash
git add trading-app/src/Trading/Paper/Dataset/VerifiedPaperDatasetSnapshot.php \
  trading-app/src/Trading/Paper/Dataset/PaperDatasetVerifier.php \
  trading-app/tests/Trading/Paper/Dataset/PaperDatasetVerifierTest.php
git commit -m "feat(#191): expose verified Paper event snapshots"
```

### Task 2: Strict candle adapter

**Files:**
- Create: `trading-app/src/Trading/Paper/Backtesting/PaperBacktestAdapterException.php`
- Create: `trading-app/src/Trading/Paper/Backtesting/NormalizedBacktestCandle.php`
- Create: `trading-app/src/Trading/Paper/Backtesting/PaperBacktestDataset.php`
- Create: `trading-app/src/Trading/Paper/Backtesting/PaperBacktestDatasetAdapter.php`
- Test: `trading-app/tests/Trading/Paper/Backtesting/PaperBacktestDatasetAdapterTest.php`

- [x] **Step 1: Write failing OKX and Hyperliquid golden tests**

Build verified snapshots with canonical v2 events and assert exact normalized maps. Required assertions include `source_record_id === event_id`, `market_type === perpetual`, exclusive close time, canonical decimal strings, and `available_at === max(received_timestamp, close_at)`.

- [x] **Step 2: Run the adapter test and verify RED**

```bash
cd trading-app
php bin/phpunit tests/Trading/Paper/Backtesting/PaperBacktestDatasetAdapterTest.php
```

Expected: failure because the adapter classes do not exist.

- [x] **Step 3: Implement the minimal typed normalization**

Implement immutable values whose constructors reject extra/invalid facts. Map channels with the fixed duration table `1m=60`, `5m=300`, `15m=900`, `1h=3600`. Normalize decimals with `BigDecimal::of($value)->stripTrailingZeros()` while rejecting non-string, exponent notation, negative zero, non-positive prices, negative volume and invalid OHLC geometry. Require event schema v2, event/manifest network and venue equality, symbol presence in the manifest map, exact venue payload keys, `confirmed === true`, UTC grid alignment, and exact venue close semantics. Sort output by venue, symbol, duration, open time and event id.

Return source facts exactly as specified in the design, including `sha256:` prefix over the manifest events checksum. Throw `PaperBacktestAdapterException` with stable reason codes only.

- [x] **Step 4: Add fail-closed parameterized tests**

Cover legacy schema, mismatch network/venue/symbol, unsupported or channel/payload timeframe mismatch, false confirmation, malformed/missing volume, decimal exponent/negative zero, geometry, grid, duration and checksum absence. Assert non-candle events are ignored and an all-non-candle snapshot is rejected as `paper_backtest_candles_empty`.

- [x] **Step 5: Run the adapter suite and verify GREEN**

```bash
cd trading-app
php bin/phpunit tests/Trading/Paper/Backtesting/PaperBacktestDatasetAdapterTest.php
```

Expected: all adapter tests pass.

- [x] **Step 6: Commit Task 2**

```bash
git add trading-app/src/Trading/Paper/Backtesting \
  trading-app/tests/Trading/Paper/Backtesting/PaperBacktestDatasetAdapterTest.php
git commit -m "feat(#191): normalize verified Paper candles"
```

### Task 3: Canonical cross-language encoding

**Files:**
- Create: `trading-app/src/Trading/Paper/Backtesting/PaperBacktestDatasetEncoder.php`
- Create: `trading-app/tests/fixtures/paper-backtesting/source-identity.json`
- Create: `trading-app/tests/fixtures/paper-backtesting/candles.ndjson`
- Modify: `trading-app/tests/Trading/Paper/Backtesting/PaperBacktestDatasetAdapterTest.php`
- Create: `python-orchestrator/tests/test_backtesting_paper_adapter_contract.py`

- [x] **Step 1: Write a failing canonical encoder test**

Assert byte-for-byte canonical JSON with sorted keys, no escaped slashes, UTF-8, one final newline, and no `mode`, `setup`, `profile` or `strategy` key recursively.

- [x] **Step 2: Verify RED**

```bash
cd trading-app
php bin/phpunit tests/Trading/Paper/Backtesting/PaperBacktestDatasetAdapterTest.php --filter Encoder
```

Expected: failure because `PaperBacktestDatasetEncoder` does not exist.

- [x] **Step 3: Implement the encoder and generate golden fixtures**

Reuse `CanonicalJson` for each map. Emit one source identity JSON document and one NDJSON line per candle. Generate fixture bytes through the public adapter/encoder path in the test fixture builder; do not hand-edit hashes or identifiers.

- [x] **Step 4: Write and run the Python consumer test**

The test must load the two fixtures, validate with `DatasetSourceIdentity` and `CandleRecord`, call `DatasetBuilder(source).build(records)`, and assert eligible exact streams. It must also recursively assert that no legacy/strategy identity key exists.

```bash
cd python-orchestrator
python3 -m pytest -q tests/test_backtesting_paper_adapter_contract.py
```

Expected after fixture implementation: pass.

- [x] **Step 5: Commit Task 3**

```bash
git add trading-app/src/Trading/Paper/Backtesting/PaperBacktestDatasetEncoder.php \
  trading-app/tests/fixtures/paper-backtesting \
  trading-app/tests/Trading/Paper/Backtesting/PaperBacktestDatasetAdapterTest.php \
  python-orchestrator/tests/test_backtesting_paper_adapter_contract.py
git commit -m "test(#191): bind Paper candles across runtimes"
```

### Task 4: Documentation and full verification

**Files:**
- Modify: `docs/handbook/technical/backtesting-engine.md`
- Modify: `docs/superpowers/plans/2026-08-13-issue-191-paper-dataset-adapter.md`

- [x] **Step 1: Document the authority boundary**

Describe verifier-owned snapshotting, venue-specific candle normalization,
`available_at`, provenance fields, and the explicit deferral of modern run
identity and Backtrader runtime.

- [x] **Step 2: Run PHP verification**

```bash
cd trading-app
php bin/phpunit tests/Trading/Paper/Dataset/PaperDatasetVerifierTest.php \
  tests/Trading/Paper/Backtesting/PaperBacktestDatasetAdapterTest.php
```

Expected: all focused PHP tests pass.

- [x] **Step 3: Run Python verification and coverage**

```bash
cd python-orchestrator
PYTHONHASHSEED=1 python3 -m pytest -q tests/test_backtesting_contracts.py \
  tests/test_backtesting_dataset.py tests/test_backtesting_dataset_store.py \
  tests/test_backtesting_paper_adapter_contract.py
PYTHONHASHSEED=987654 python3 -m pytest -q tests/test_backtesting_contracts.py \
  tests/test_backtesting_dataset.py tests/test_backtesting_dataset_store.py \
  tests/test_backtesting_paper_adapter_contract.py
python3 -m pytest --cov=app --cov-report=term-missing --cov-report=xml --cov-fail-under=95 -q
```

Expected: both focused seeds pass; full suite reaches at least 95% coverage.

- [x] **Step 4: Run static hygiene**

```bash
php -l trading-app/src/Trading/Paper/Backtesting/*.php
python3 -m py_compile python-orchestrator/tests/test_backtesting_paper_adapter_contract.py
git diff --check origin/main...HEAD
git status --short --branch
```

Expected: no syntax/diff error and a clean worktree after the final commit.

- [x] **Step 5: Update this checklist and commit docs**

Mark completed steps, record commit ids and verification totals, then:

```bash
git add docs/handbook/technical/backtesting-engine.md \
  docs/superpowers/plans/2026-08-13-issue-191-paper-dataset-adapter.md
git commit -m "docs(#191): document verified Paper adaptation"
```

- [x] **Step 6: Request local review before PR**

Review `origin/main...HEAD` for contract drift, look-ahead, provenance loss,
TOCTOU, leaked paths/payloads and any legacy alias. Resolve all blockers before
opening the PR.

Verification finale du lot : PHP 72 tests / 281 assertions; Python 164 tests
sous `PYTHONHASHSEED=1` et `987654`; suite Python complete 722 tests passes,
3 skips et couverture 95.81%. Revues locales specification et qualite
approuvees apres correction des contrats de provenance et des decimales
sub-unitaires inter-runtime.
