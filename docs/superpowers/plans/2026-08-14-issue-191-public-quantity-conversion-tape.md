# #191 Public Quantity Conversion Tape Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans
> inline. The user requested no subagents after the preceding merge.

**Goal:** Produce a deterministic, dataset-bound tape that converts every
authenticated public trade and L1 book quantity to base-asset units using only
the instrument metadata effective at that event's immutable source position.

**Architecture:** Preserve the raw public trade/book v1 tapes. Extend the PHP
Paper adapter with strict metadata, trade-conversion and book-conversion value
objects that retain source positions and exact cross-references. Add a Python
serializer/verifier that independently rechecks complete raw-tape coverage,
metadata ordering and venue formulas before emitting canonical artifacts.

**Tech Stack:** PHP 8.4, Brick Math, PHPUnit 11, Python 3, Pydantic v2,
`decimal.Decimal`, pytest, canonical JSON and SHA-256.

---

### Task 1: PHP metadata projection contract

**Files:**
- Create: `trading-app/src/Trading/Paper/Backtesting/NormalizedBacktestInstrumentMetadata.php`
- Modify: `trading-app/src/Trading/Paper/Backtesting/PaperBacktestDataset.php`
- Modify: `trading-app/src/Trading/Paper/Backtesting/PaperBacktestDatasetAdapter.php`
- Test: `trading-app/tests/Trading/Paper/Backtesting/PaperBacktestDatasetAdapterTest.php`

- [ ] **Step 1: Write failing metadata projection tests**

Add OKX and Hyperliquid `instrument_metadata` fixture helpers and assert the
wished-for `instrumentMetadata` dataset list. The normalized row must expose
`backtest-instrument-metadata.v1`, source record/checksum/network/venue/symbol,
zero-based `source_event_position`, canonical `available_at`, `source_epoch`,
quantity unit, contract value, multiplier and value unit.

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```bash
cd trading-app
php -d memory_limit=512M bin/phpunit \
  tests/Trading/Paper/Backtesting/PaperBacktestDatasetAdapterTest.php
```

Expected: FAIL because the metadata value object and dataset property do not
exist.

- [ ] **Step 3: Implement the strict immutable metadata row**

Create `NormalizedBacktestInstrumentMetadata` with constructor validation for
the exact two venue contracts. Require positive canonical decimals, an earlier
non-negative event position, strict timestamp, positive epoch, OKX
`contracts`/base-value-unit semantics and Hyperliquid `base_asset`/factor-one
semantics. Its `toArray()` must return the exact cross-runtime schema.

- [ ] **Step 4: Project metadata while preserving source position**

Enumerate `VerifiedPaperDatasetSnapshot::events` with its zero-based index.
Validate exact venue payload keys independently of the dataset verifier, create
metadata rows, retain the latest row per symbol only for later observations,
and pass the complete ordered metadata list into `PaperBacktestDataset`.

- [ ] **Step 5: Run the focused test to GREEN and commit**

```bash
cd trading-app
php -d memory_limit=512M bin/phpunit \
  tests/Trading/Paper/Backtesting/PaperBacktestDatasetAdapterTest.php
git add src/Trading/Paper/Backtesting tests/Trading/Paper/Backtesting
git commit -m "feat(#191): project instrument metadata for backtests"
```

### Task 2: PHP event-time quantity conversions

**Files:**
- Create: `trading-app/src/Trading/Paper/Backtesting/NormalizedBacktestTradeQuantityConversion.php`
- Create: `trading-app/src/Trading/Paper/Backtesting/NormalizedBacktestBookQuantityConversion.php`
- Modify: `trading-app/src/Trading/Paper/Backtesting/PaperBacktestDataset.php`
- Modify: `trading-app/src/Trading/Paper/Backtesting/PaperBacktestDatasetAdapter.php`
- Test: `trading-app/tests/Trading/Paper/Backtesting/PaperBacktestDatasetAdapterTest.php`

- [ ] **Step 1: Write failing exact-conversion tests**

Cover OKX trade and bid/ask multiplication with non-trivial `ctVal` and
`ctMult`, Hyperliquid identity conversion, metadata supersession, equal receipt
timestamps resolved by event position, omission of derived rows for events
before metadata or metadata received too late, and continued raw-v1 projection
for legacy datasets without metadata.

- [ ] **Step 2: Run the focused test and verify RED**

Use the Task 1 PHPUnit command. Expected: FAIL because conversion properties
and value objects do not exist.

- [ ] **Step 3: Implement channel-specific immutable rows**

Both rows must bind source and metadata record IDs/positions, source checksum,
identity and timestamps. The trade row contains one source/base quantity; the
book row contains exact bid and ask source/base quantities. Require canonical
positive decimals, `metadata_event_position < source_event_position`,
`metadata_available_at <= available_at`, `base_asset` output and exact venue
source units.

- [ ] **Step 4: Implement fail-closed adapter conversion**

While walking source events, activate metadata only after projecting its row.
For a trade or book, emit a derived row only with active same-symbol metadata
that is available by receipt time; retain the raw v1 row otherwise so legacy
datasets stay readable but non-certifiable. Compute OKX with Brick Math as
`quantity * contractValue * contractMultiplier`; preserve Hyperliquid size
exactly. Canonicalize results without float conversion, rounding or quantizing.

- [ ] **Step 5: Re-run focused tests to GREEN and commit**

```bash
cd trading-app
php -d memory_limit=512M bin/phpunit \
  tests/Trading/Paper/Backtesting/PaperBacktestDatasetAdapterTest.php
git add src/Trading/Paper/Backtesting tests/Trading/Paper/Backtesting
git commit -m "feat(#191): convert public quantities with event-time metadata"
```

### Task 3: Canonical PHP fixtures and encoder parity

**Files:**
- Modify: `trading-app/src/Trading/Paper/Backtesting/PaperBacktestDatasetEncoder.php`
- Modify: `trading-app/tests/Trading/Paper/Backtesting/PaperBacktestDatasetAdapterTest.php`
- Create: `trading-app/tests/Fixtures/paper-backtesting/instrument-metadata.ndjson`
- Create: `trading-app/tests/Fixtures/paper-backtesting/quantity-conversions.ndjson`
- Modify: existing files under `trading-app/tests/Fixtures/paper-backtesting/`

- [ ] **Step 1: Write failing encoder/fixture assertions**

Assert byte-identical canonical NDJSON for metadata and the union of trade/book
conversion rows. Assert stable ordering by source event position and forbid
mode/setup/profile/strategy keys in both outputs.

- [ ] **Step 2: Run the focused test and verify RED**

Use the Task 1 PHPUnit command. Expected: FAIL because encoder methods and
checked-in bytes are absent.

- [ ] **Step 3: Add bounded canonical encoder methods and fixtures**

Encode metadata and conversions with `CanonicalJson`, one newline-terminated
record per row. Update the existing fixture snapshot to place metadata before
the candle/trade/book observations, then update every fixture from the public
encoder output.

- [ ] **Step 4: Run focused PHP tests to GREEN and commit**

```bash
cd trading-app
php -d memory_limit=512M bin/phpunit \
  tests/Trading/Paper/Backtesting/PaperBacktestDatasetAdapterTest.php
git add src/Trading/Paper/Backtesting tests/Trading/Paper/Backtesting \
  tests/Fixtures/paper-backtesting
git commit -m "test(#191): publish quantity conversion fixtures"
```

### Task 4: Python conversion tape verifier

**Files:**
- Create: `python-orchestrator/app/backtesting/public_quantity_conversion_tape.py`
- Create: `python-orchestrator/tests/test_backtesting_public_quantity_conversion_tape.py`

- [ ] **Step 1: Write failing strict contract tests**

Define wished-for frozen models for instrument metadata, trade conversion, book
conversion and artifact bytes. Tests must cover fixture parsing, deterministic
serialization, exact OKX arithmetic, Hyperliquid identity, strict extra-field
rejection, timestamps, canonical decimals and event-position ordering.

- [ ] **Step 2: Run the new Python test and verify RED**

```bash
cd python-orchestrator
python3 -m pytest \
  tests/test_backtesting_public_quantity_conversion_tape.py -q
```

Expected: collection FAIL because the module does not exist.

- [ ] **Step 3: Implement frozen strict record models**

Use Pydantic `extra="forbid"`, `strict=True` and `frozen=True`. Use
`decimal.Decimal` solely for verification; retain canonical strings in emitted
bytes. Model the two conversion record shapes as a discriminated union on
`source_channel` and reject non-positive or non-canonical numeric input.

- [ ] **Step 4: Implement complete cross-tape verification**

The serializer accepts the verified candle dataset, optional verified public
execution/book tapes with at least one present, metadata tuple and conversions.
Require exact dataset identity, source checksum, unique record IDs, one
conversion for every raw observation, no extras, exact symbol/timestamp/unit
parity, valid metadata references and positions, and independently recomputed
base quantities.

- [ ] **Step 5: Implement canonical bounded artifacts**

Emit manifest, metadata NDJSON and conversion NDJSON. Bind the manifest to the
dataset checksum, source checksum, nullable exact raw tape checksums, section
checksums, counts and final tape checksum. Enforce record and byte limits before
publication, then reconstruct and verify the artifact before returning it.

- [ ] **Step 6: Run new and adjacent Python tests to GREEN and commit**

```bash
cd python-orchestrator
python3 -m pytest \
  tests/test_backtesting_public_quantity_conversion_tape.py \
  tests/test_backtesting_public_execution_tape.py \
  tests/test_backtesting_public_book_tape.py -q
git add app/backtesting/public_quantity_conversion_tape.py \
  tests/test_backtesting_public_quantity_conversion_tape.py
git commit -m "feat(#191): verify public quantity conversion tape"
```

### Task 5: Adversarial coverage and documentation

**Files:**
- Modify: `python-orchestrator/tests/test_backtesting_public_quantity_conversion_tape.py`
- Modify: `trading-app/tests/Trading/Paper/Backtesting/PaperBacktestDatasetAdapterTest.php`
- Modify: `docs/handbook/technical/backtesting-engine.md`

- [ ] **Step 1: Add failing adversarial tests**

Cover source-tape substitution, metadata substitution, duplicate source and
metadata IDs, missing/extra conversions, wrong source channel, changed raw
quantity, metadata formula tampering, out-of-order records, unbounded rows and
legacy datasets with public observations but no dated metadata.

- [ ] **Step 2: Run tests and verify each new case fails for its intended reason**

Use the Task 2 PHP command and Task 4 Python command. No test may fail due to a
fixture typo or unrelated import error.

- [ ] **Step 3: Add minimal fail-closed guards and document the contract**

Document the event-time authority, official venue formulas, artifact binding,
legacy non-convertibility and explicit exclusion of fill/queue inference. Keep
mainnet public/read-only and do not introduce HTTP or execution services.

- [ ] **Step 4: Run targeted and broad verification**

```bash
cd trading-app
php -d memory_limit=512M bin/phpunit tests/Trading/Paper/Backtesting
php -d memory_limit=512M bin/phpunit tests/Trading/Paper/Dataset
php -d memory_limit=512M bin/phpunit tests/Trading/Paper/Okx
php -d memory_limit=512M bin/phpunit tests/Trading/Paper/Hyperliquid
vendor/bin/phpstan analyse --no-progress \
  src/Trading/Paper/Backtesting tests/Trading/Paper/Backtesting
cd ../python-orchestrator
python3 -m pytest -q
cd ..
python3 -m mkdocs build --strict
git diff --check
```

Expected: all changed-scope tests and checks pass. Any unrelated environment
failure must be reported separately and not described as a passing suite.

- [ ] **Step 5: Review, commit and publish**

Inspect the complete diff for secret/private/order paths and confirm only
public read-only evidence code changed. Commit documentation/final hardening,
push the branch and open a ready PR linked to #191. Request Codex review once;
a Codex thumbs-up is approval, quota messages are not reviews, and no artificial
review cycle is needed without actionable feedback. Merge only with green CI
and no unresolved blocking thread.
