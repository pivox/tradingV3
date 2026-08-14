# Issue #308 Authenticated Microstructure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Derive a deterministic spread and aggressor-volume OFI snapshot from authenticated public L1/trade evidence in PHP and Python.

**Architecture:** A pure PHP authority consumes the normalized Paper records, while a pure Python mirror consumes the verified #191 tapes. Both enforce the same policy, chronology, identity, freshness and decimal rules and emit the same canonical v1 payload/hash.

**Tech Stack:** PHP 8.4, Brick Math, PHPUnit 11, Python 3.12, Pydantic v2, `Decimal`, pytest, canonical JSON and SHA-256.

---

### Task 1: PHP microstructure authority

**Files:**
- Create: `trading-app/src/TradingCore/Microstructure/CanonicalMicrostructureException.php`
- Create: `trading-app/src/TradingCore/Microstructure/CanonicalMicrostructurePolicy.php`
- Create: `trading-app/src/TradingCore/Microstructure/CanonicalMicrostructureSnapshot.php`
- Create: `trading-app/src/TradingCore/Microstructure/CanonicalMicrostructureEngine.php`
- Test: `trading-app/tests/TradingCore/Microstructure/CanonicalMicrostructureEngineTest.php`

- [ ] **Step 1: Write failing policy and golden-path tests**

Construct normalized OKX L1/trade records over a 60-second window. Assert the
exact spread, positive and negative OFI, canonical quantities, record lineage,
policy serialization and one pinned snapshot hash.

- [ ] **Step 2: Run the focused test and verify RED**

Run: `php bin/phpunit tests/TradingCore/Microstructure/CanonicalMicrostructureEngineTest.php`
Expected: FAIL because the microstructure classes do not exist.

- [ ] **Step 3: Implement the minimal immutable policy, snapshot and engine**

Use Brick `BigDecimal` for sums and ratios. Select only evidence available at
the evaluation instant, require one homogeneous identity/unit/checksum, enforce
canonical ordering and gaps, then hash recursively key-sorted canonical JSON.
Expose only `build(policy, evaluatedAt, books, trades): snapshot`.

- [ ] **Step 4: Run the focused test and verify GREEN**

Run the command from Step 2.
Expected: PASS with the pinned payload and hash.

### Task 2: PHP fail-closed boundaries

**Files:**
- Modify: `trading-app/tests/TradingCore/Microstructure/CanonicalMicrostructureEngineTest.php`
- Modify: `trading-app/src/TradingCore/Microstructure/CanonicalMicrostructureEngine.php`

- [ ] **Step 1: Add failing rejection cases**

Cover invalid policy values, empty evidence, duplicate/reordered IDs, crossed
network/venue/symbol/checksum/unit, crossed book, evidence only available in the
future, stale book/latest trade, insufficient trade count and every boundary or
inter-trade gap above policy.

- [ ] **Step 2: Run the focused test and verify RED**

Expected: each new case fails because its stable reason is not yet enforced.

- [ ] **Step 3: Implement minimal strict guards**

Reject with one stable `canonical_microstructure_*` reason per boundary. Do not
sort, coerce identities, infer defaults or accept legacy scalar spread/OFI.

- [ ] **Step 4: Re-run focused tests and verify GREEN**

Expected: all microstructure PHPUnit cases pass.

### Task 3: Python verified-tape mirror

**Files:**
- Create: `python-orchestrator/app/backtesting/microstructure_snapshot.py`
- Create: `python-orchestrator/tests/test_backtesting_microstructure_snapshot.py`

- [ ] **Step 1: Write failing mirror and golden parity tests**

Build verified `PublicBookTape` and `PublicExecutionTape` fixtures and assert the
same canonical payload/hash as PHP. Add tamper, identity, chronology, freshness,
gap, minimum-count and mixed-unit cases.

- [ ] **Step 2: Run the focused test and verify RED**

Run: `python3 -m pytest tests/test_backtesting_microstructure_snapshot.py -q`
Expected: FAIL because `microstructure_snapshot` does not exist.

- [ ] **Step 3: Implement the frozen Python mirror**

Validate that both verified tapes bind to the same dataset. Use `Decimal` with
HALF_EVEN at 12 decimal places, canonical sorted JSON and the identical schema.
Return a frozen Pydantic snapshot; never accept raw unverified record lists.

- [ ] **Step 4: Re-run the focused test and verify GREEN**

Expected: all Python cases pass and the golden hash equals PHP.

### Task 4: Documentation and verification

**Files:**
- Modify: `docs/handbook/technical/backtesting-engine.md`
- Modify: `docs/handbook/technical/risk-and-leverage-module.md`

- [ ] **Step 1: Document the metric and non-claims**

Document the exact formula/window/freshness contract, authenticated inputs,
failure behavior and that this lot alone does not make `micro_scalping`
executable.

- [ ] **Step 2: Run focused and broad verification**

Run the focused PHPUnit and pytest files, relevant Paper/backtest suites,
PHPStan for the new PHP namespace, the full Python suite with its repository
coverage threshold, strict docs build, PHP lint and `git diff --check`.

- [ ] **Step 3: Review scope and commit**

Inspect the full diff for legacy scalar fallbacks, private mainnet ports,
unrelated changes, placeholders and unbound identities. Commit only the files
listed by this plan.

### Task 5: GitHub delivery

**Files:** no repository files.

- [ ] **Step 1: Push and open a ready PR linked to #308**

State that this is the data-authority lot and list the later activation work.

- [ ] **Step 2: Request Codex review**

Ask for actionable review of metric semantics, look-ahead, decimal parity,
lineage and fail-closed boundaries. A Codex thumbs-up counts as approval.

- [ ] **Step 3: Address real feedback and merge**

Use TDD for each actionable finding. Merge when CI is green, no blocking thread
remains and Codex has approved; do not manufacture extra review cycles.
