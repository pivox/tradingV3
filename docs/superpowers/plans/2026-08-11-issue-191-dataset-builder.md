# Issue #191 Deterministic Dataset Builder Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build and atomically publish deterministic, fail-closed candle datasets with a typed quality report and reproducible checksums.

**Architecture:** Accept only normalized `CandleRecord` values from an already verified source, validate and order them in a pure builder, then serialize exact NDJSON/report/manifest bytes through a separate publisher. Dataset construction remains independent of all legacy and modern strategy identities.

**Tech Stack:** Python 3.11+, existing Pydantic 2, `decimal`, `datetime`, `json`,
`hashlib`, `pathlib`, `os`, `ctypes`, `secrets`, pytest.

---

### Task 1: Lock the normalized candle and quality contracts

**Files:**
- Create: `python-orchestrator/app/backtesting/dataset.py`
- Create: `python-orchestrator/tests/test_backtesting_dataset.py`
- Modify: `python-orchestrator/app/backtesting/__init__.py`

- [x] **Step 1: Write failing contract tests**

Add table-driven tests for the five exact timeframes, frozen/unknown-field
rejection, UTC-only timestamps, exact timeframe duration, `available_at >=
close_at`, `complete=true`, canonical decimal grammar, positive OHLC,
non-negative volume, and `low <= open, close <= high`. Assert the public record
and report schemas expose no `profile`, mode, setup, or alias field.

- [x] **Step 2: Verify RED**

Run:

```bash
cd python-orchestrator
python3 -m pytest tests/test_backtesting_dataset.py -q
```

Expected: collection fails because `app.backtesting.dataset` does not exist.

- [x] **Step 3: Implement immutable contracts**

In `dataset.py`, add:

```text
CandleRecord
DatasetSourceIdentity
MissingRange
DatasetStreamQuality
DatasetQualityReport
DatasetBuildResult
DatasetBuildRejected
```

Use `ConfigDict(frozen=True, extra="forbid")`, strict UTC validators, Decimal
parsing from the canonical string grammar, and serializers that preserve the
validated strings. Represent timeframes with their exact duration rather than
lexicographic order. `DatasetBuildRejected` must expose only its stable reason
code and typed report, never raw source content.

- [x] **Step 4: Verify GREEN**

Run:

```bash
cd python-orchestrator
python3 -m pytest tests/test_backtesting_dataset.py -q
python3 -m py_compile app/backtesting/dataset.py
```

Expected: all new contract tests pass and compilation exits zero.

- [x] **Step 5: Commit contracts**

```bash
git add python-orchestrator/app/backtesting/dataset.py python-orchestrator/app/backtesting/__init__.py python-orchestrator/tests/test_backtesting_dataset.py
git commit -m "feat(#191): define normalized backtest candle contracts"
```

### Task 2: Build the fail-closed quality report

**Files:**
- Modify: `python-orchestrator/app/backtesting/dataset.py`
- Modify: `python-orchestrator/tests/test_backtesting_dataset.py`

- [x] **Step 1: Write failing quality tests**

Cover empty input, mixed network/venue/market type, one complete stream,
multi-symbol/multi-timeframe streams, exact duplicate, conflicting duplicate,
one and multiple contiguous missing ranges, and input permutations. Assert gaps
are derived only inside observed bounds and all report collections have stable
canonical ordering.

- [x] **Step 2: Verify RED**

```bash
cd python-orchestrator
python3 -m pytest tests/test_backtesting_dataset.py -q
```

Expected: quality tests fail because `DatasetBuilder` is absent.

- [x] **Step 3: Implement `DatasetBuilder.analyze()` and `build()`**

Materialize the sequence once. Group by `(market_data_venue, market_type,
symbol, timeframe)`, sort by numeric timeframe duration and UTC `open_at`, and
detect identities by `(venue, market_type, symbol, timeframe, open_at)`.
`analyze()` always returns the typed report for normalized records. `build()`
raises `DatasetBuildRejected` for any flag and otherwise derives exact bounds,
counts, symbols, timeframes, and ordered records. Never deduplicate, fill, or
select a duplicate winner.

- [x] **Step 4: Verify GREEN and determinism**

```bash
cd python-orchestrator
PYTHONHASHSEED=1 python3 -m pytest tests/test_backtesting_dataset.py -q
PYTHONHASHSEED=987654 python3 -m pytest tests/test_backtesting_dataset.py -q
```

Expected: both runs pass with identical golden report assertions.

- [x] **Step 5: Commit quality analysis**

```bash
git add python-orchestrator/app/backtesting/dataset.py python-orchestrator/tests/test_backtesting_dataset.py
git commit -m "feat(#191): reject dataset gaps and duplicates"
```

### Task 3: Serialize deterministic artifacts and descriptor

**Files:**
- Modify: `python-orchestrator/app/backtesting/contracts.py`
- Modify: `python-orchestrator/app/backtesting/dataset.py`
- Modify: `python-orchestrator/tests/test_backtesting_contracts.py`
- Modify: `python-orchestrator/tests/test_backtesting_dataset.py`
- Create: `python-orchestrator/tests/fixtures/backtesting/candles-v1.ndjson`
- Create: `python-orchestrator/tests/fixtures/backtesting/quality-report-v1.json`
- Create: `python-orchestrator/tests/fixtures/backtesting/manifest-v1.json`

- [x] **Step 1: Write failing golden-byte and descriptor tests**

Assert that equivalent record permutations produce byte-identical files and
checksums. Recompute every checksum from checked-in fixture bytes. Assert the
descriptor derives schema/build versions, source checksum, network/venue,
exact canonical stream coverage, symbols, timeframes, bounds, record count,
report checksum, quality flags, and dataset checksum; mutation of any artifact
must fail cross-verification. Assert a run cannot infer an absent
symbol/timeframe combination from flattened lists and must remain inside every
requested stream's own bounds.

- [x] **Step 2: Verify RED**

```bash
cd python-orchestrator
python3 -m pytest tests/test_backtesting_contracts.py tests/test_backtesting_dataset.py -q
```

Expected: serialization and descriptor assertions fail because no artifacts are
emitted and the descriptor lacks the derived fields.

- [x] **Step 3: Implement canonical bytes and checksum graph**

Serialize compact sorted-key UTF-8 JSON with one newline per record/file.
Compute `candles.ndjson`, then its SHA-256; serialize the typed report and its
SHA-256; serialize a manifest core binding source identity/checksum, derived
coverage, counts, schemas, build version, and both hashes; derive the dataset
checksum from that core plus the two artifact hashes; finally serialize the
manifest containing that checksum. No generated timestamp or caller-provided
derived fact is permitted.

Extend `DatasetDescriptor` without adding a strategy identity. Validate that a
descriptor reconstructed from the manifest agrees with every derived fact,
including canonical unique per-stream coverage from which aggregate facts are
derived. Bind each eligible stream's UTC grid alignment and exact gapless
duration/count equation into the same validation boundary.

- [x] **Step 4: Generate and verify golden fixtures**

Generate fixtures through the implementation's public serializer, review the
exact bytes, then run:

```bash
cd python-orchestrator
python3 -m pytest tests/test_backtesting_contracts.py tests/test_backtesting_dataset.py -q
python3 -m py_compile app/backtesting/contracts.py app/backtesting/dataset.py
```

Expected: all contract, golden-byte, mutation, and checksum tests pass.

- [x] **Step 5: Commit deterministic serialization**

```bash
git add python-orchestrator/app/backtesting/contracts.py python-orchestrator/app/backtesting/dataset.py python-orchestrator/tests/test_backtesting_contracts.py python-orchestrator/tests/test_backtesting_dataset.py python-orchestrator/tests/fixtures/backtesting
git commit -m "feat(#191): serialize checksummed backtest datasets"
```

### Task 4: Publish atomically and idempotently

**Files:**
- Create: `python-orchestrator/app/backtesting/dataset_store.py`
- Create: `python-orchestrator/tests/test_backtesting_dataset_store.py`
- Modify: `python-orchestrator/app/backtesting/__init__.py`

- [x] **Step 1: Write failing filesystem tests**

Using `tmp_path`, cover successful private publication, exact repeat as a
no-op, same `dataset_id` with different bytes, changed/missing/extra artifact,
symlinked target/artifact and injected failure before rename. Assert conflicts
preserve all pre-existing bytes, that failure cleanup never removes any
filesystem entry, and that recoverable private staging directories remain
empty, partial or complete according to the failure boundary.

- [x] **Step 2: Verify RED**

```bash
cd python-orchestrator
python3 -m pytest tests/test_backtesting_dataset_store.py -q
```

Expected: collection fails because `dataset_store.py` does not exist.

- [x] **Step 3: Implement `DatasetPublisher`**

Create a mode-`0700` sibling staging directory, write only the three approved
mode-`0600` artifacts, flush and `fsync` files and directories, and publish with
one atomic rename. Verify an existing target without following symlinks. Return
an idempotent status only when all names and bytes match exactly; otherwise
raise a stable conflict without replacement. Accept only `DatasetArtifacts`
whose build, ordering, report, manifest and checksum graph were cross-verified
by `DatasetSerializer`.

- [x] **Step 4: Verify GREEN**

```bash
cd python-orchestrator
python3 -m pytest tests/test_backtesting_dataset_store.py -q
python3 -m py_compile app/backtesting/dataset_store.py
```

Expected: all publication, failure-injection, and preservation tests pass.

- [x] **Step 5: Commit publication**

```bash
git add python-orchestrator/app/backtesting/dataset_store.py python-orchestrator/app/backtesting/__init__.py python-orchestrator/tests/test_backtesting_dataset_store.py
git commit -m "feat(#191): publish immutable backtest datasets"
```

### Task 5: Document the boundary and run regression gates

**Files:**
- Modify: `docs/handbook/technical/backtesting-engine.md`
- Modify: `docs/superpowers/plans/2026-08-11-issue-191-dataset-builder.md`

- [x] **Step 1: Document the delivered contract**

Describe normalized pre-verified input, exact CandleRecord grammar, availability
and look-ahead boundary, quality rejection policy, canonical ordering/checksum
graph, artifact layout, publication behavior, and reproduction commands. State
that raw PHP Paper files require a later verified adapter and that no modern
mode is runnable before PR3 replaces the legacy `Profile` boundary with exact
mode/setup identities and the #133/#303 snapshot.

- [x] **Step 2: Run focused and full Python verification**

```bash
cd python-orchestrator
python3 -m pytest tests/test_backtesting_contracts.py tests/test_backtesting_dataset.py tests/test_backtesting_dataset_store.py -q
python3 -m pytest -q
python3 -m py_compile app/backtesting/contracts.py app/backtesting/dataset.py app/backtesting/dataset_store.py
cd ..
git diff --check
```

Expected: every command exits zero with no test failure, compile error, or
whitespace error.

- [x] **Step 3: Confirm the legacy isolation guard**

```bash
rg -n "regular|scalper|scalper_micro|profile|mode_id|setup_id" \
  python-orchestrator/app/backtesting/dataset.py \
  python-orchestrator/app/backtesting/dataset_store.py \
  python-orchestrator/tests/test_backtesting_dataset.py \
  python-orchestrator/tests/test_backtesting_dataset_store.py
```

Expected: no production dataset field or alias mapping; documentation/test
assertion text may mention the forbidden names.

- [x] **Step 4: Commit documentation and completed checklist**

```bash
git add docs/handbook/technical/backtesting-engine.md docs/superpowers/plans/2026-08-11-issue-191-dataset-builder.md
git commit -m "docs(#191): document deterministic dataset reproduction"
```

## Delivery evidence

- Task 1 contracts: `bdc78652`.
- Task 2 quality analysis: `b4b88876`; strict/non-forgeable boundary fixes:
  `82b0a919`.
- Task 3 canonical serialization: `8f3b139d`; review fixes for canonical order,
  strict manifest facts and descriptor checksum binding: `03cdaa9c`, `83bfffe9`.
- Task 4 publication: `b9876be6`; review fixes for no-replace publication,
  anchored root `dirfd`, path identity, symlink/hardlink races and staging cleanup:
  `ad94a026`, `db4822a2`, `378bc58a`, `a43fc5a4`, `982efb8c`, `bb76611e`,
  `f52e2240`. Failure cleanup only closes descriptors and preserves the private
  staging, including its contents, for a separately governed non-concurrent
  janitor. A `PUBLISHED` result renames the staging directory and leaves no
  leak; a concurrent loser returning `ALREADY_PUBLISHED` preserves its complete
  staging for that janitor. Root traversal is anchored from `/` through retained
  no-follow fds; missing path components use durable atomic `.dataset-root-*`
  publication and any losing/interrupted private component is also preserved
  for the janitor. Existing artifact equality is accepted only after a
  collective retained-fd identity and size/mtime/ctime stability pass around a
  second content read, followed by a root-directory flush on every
  `ALREADY_PUBLISHED` path. This finite snapshot detects concurrent validation
  mutations; storage ownership/coordination remains required against hostile
  same-UID writes after the last observation. Concurrently won root components
  are adopted only after their parent directory is flushed.
- Atomic no-replace uses `renameatx_np(RENAME_EXCL)` on macOS and
  `renameat2(RENAME_NOREPLACE)` on Linux. Unsupported platforms fail closed.
- Golden artifacts are checked in under
  `python-orchestrator/tests/fixtures/backtesting/` and are generated through
  the public serializer.
- Focused deterministic gate after Task 4: 92 tests passed with
  `PYTHONHASHSEED=1` and `PYTHONHASHSEED=987654`.
- Task 5 final gate: 92 focused tests passed; the full Python suite passed with
  652 tests, 3 environment-dependent skips and 2 dependency deprecation
  warnings. `py_compile` and `git diff --check` exited zero.
- The legacy isolation grep returned only negative test fixtures/assertions;
  `dataset.py` and `dataset_store.py` contain no strategy identity or alias.
