# #191 Modern Backtest Identity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the legacy profile boundary from #191 and bind every backtest request and ledger row to an exact modern identity and verified #133/#303 snapshot.

**Architecture:** A dependency-neutral modern-contract module owns the published identity matrix, frozen JSON values, and PHP-compatible effective-config snapshot. Orchestration schemas and backtesting contracts consume it, so neither subsystem imports the other.

**Tech Stack:** Python 3.11+, Pydantic v2, pytest, hashlib/JSON canonicalization.

---

### Task 1: Shared modern identity and canonical snapshot

**Files:**
- Create: `python-orchestrator/app/modern_trading_contracts.py`
- Create: `python-orchestrator/tests/test_backtesting_modern_identity.py`
- Modify: `python-orchestrator/app/schemas.py`
- Modify: `python-orchestrator/tests/test_schemas.py`

- [ ] Write failing tests that construct every published mode/setup/version/side combination and reject legacy aliases, unpublished versions, invalid mode/setup pairs, side mismatches, exchange/environment mismatches, extra keys, and whitespace/case variants.
- [ ] Run `pytest -q tests/test_backtesting_modern_identity.py tests/test_schemas.py` from `python-orchestrator` and confirm failures are caused by the missing shared module/new fields.
- [ ] Move frozen JSON support and canonical hashing into `modern_trading_contracts.py`; implement `ModernTradingIdentity`, `CanonicalEffectiveConfigRequest`, `CanonicalEffectiveConfigLayer`, and `CanonicalEffectiveConfigSnapshot` with exact matrix validation and deep immutability.
- [ ] Add `snapshot_hash`; validate exact layer order/files/provenance, config identity, `config_hash`, and `snapshot_hash`. Keep the general snapshot capable of representing blocked evidence.
- [ ] Re-export/import the shared types from `schemas.py` without changing the public orchestration names; update orchestration tests to exact published `1.1.0` examples and snapshot hashes.
- [ ] Re-run the two test modules until green, then commit the shared contract.

### Task 2: Modernize run and ledger boundaries

**Files:**
- Modify: `python-orchestrator/app/backtesting/contracts.py`
- Modify: `python-orchestrator/tests/test_backtesting_contracts.py`

- [ ] Write failing tests proving `profile` is forbidden, exact identity/snapshot equality is required, blocked snapshots cannot run, data venue is independent from simulated exchange, ledger direction must match identity side, and every identity/hash mutation changes the reproducibility fingerprint.
- [ ] Run `pytest -q tests/test_backtesting_contracts.py` and confirm the new tests fail against the legacy API.
- [ ] Remove `Profile` and the temporary backtest `EffectiveConfigSnapshot`; use `ModernTradingIdentity` and the canonical snapshot in `BacktestRunRequest`.
- [ ] Replace ledger `profile` with exact identity plus `condition_catalog_hash` and `snapshot_hash`; preserve stop and net-cost validation.
- [ ] Reject all extra fields with `ConfigDict(frozen=True, extra="forbid")` on changed boundary models.
- [ ] Re-run the contract tests until green and commit the migration.

### Task 3: PHP/Python golden parity and documentation

**Files:**
- Create: `python-orchestrator/tests/fixtures/backtesting/php-effective-config-snapshot.json`
- Modify: `python-orchestrator/tests/test_backtesting_modern_identity.py`
- Modify: `docs/handbook/technical/backtesting-engine.md`

- [ ] Create a deterministic snapshot fixture matching `CanonicalEffectiveConfigSnapshot::calculateConfigHash()` and `calculateSnapshotHash()`, including Unicode, an unescaped slash, and an integral float case.
- [ ] Write a failing golden test that loads the fixture, validates both hashes, and proves one-byte semantic mutations fail closed.
- [ ] Implement only the canonicalization corrections needed for byte parity, then make the golden test green.
- [ ] Document the modern identity boundary, intentional rejection of old profile JSON, venue/exchange independence, reproduction command, and remaining Backtrader work.
- [ ] Run `pytest -q tests/test_backtesting_modern_identity.py tests/test_backtesting_contracts.py tests/test_schemas.py tests/test_symfony_client.py` and commit.

### Task 4: Full verification and delivery

**Files:**
- Verify all changed files and repository state.

- [ ] Run `pytest -q` twice with the repository's two deterministic seeds and run the coverage gate.
- [ ] Run `python -m compileall -q app tests`, `git diff --check`, and inspect the full diff for legacy `Profile` leakage.
- [ ] Request one code review. If it produces no actionable feedback, do not create artificial extra cycles; if it produces findings, fix them and request follow-up only for the corrected areas.
- [ ] Open a focused PR linked to #191, wait for all required CI, resolve every thread, and merge without enabling real-mainnet execution.

