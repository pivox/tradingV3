# Fake Multi-Profile Recipe v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Fake/Paper golden scenario 20 executable through one deterministic dry-run recipe for regular, scalper, and scalper_micro on the same symbol.

**Architecture:** Extend the #188 runner with a dedicated fixture and R12 handler, derive stable per-set configuration hashes in the canonical payload builder, and normalize runtime evidence into JSON and Markdown. Promote the PHP golden catalogue by reading the same fixture, keeping the proof network-free.

**Tech Stack:** Python 3.12, FastAPI/httpx/SQLAlchemy, Pytest, PHP 8.3, PHPUnit, Symfony 7, JSON fixtures, MkDocs.

---

### Task 1: Freeze the missing runtime contract in red tests

**Files:**
- Modify: `python-orchestrator/tests/test_runtime_recipe_fixtures.py`
- Modify: `python-orchestrator/tests/test_runtime_recipe_runner.py`
- Modify: `python-orchestrator/tests/test_integration_orchestrator_e2e.py`
- Modify: `python-orchestrator/tests/test_symfony_client.py`

- [x] Add fixture tests requiring three enabled Fake/demo/dry-run sets on `BTCUSDT`, distinct profile identities, and one disabled control.
- [x] Add runner tests requiring R12, deterministic JSON/Markdown, replay across two runner instances, lock-layer separation, explicit business-lock non-exercise, redaction, and zero target-exchange calls.
- [x] Add wire/integration tests requiring distinct `config_hash` values and bounded concurrency for same-symbol profiles.
- [x] Run the focused Pytest selection and record the expected missing-fixture/R12/hash failures.

### Task 2: Add the fixture, canonical hash, and R12 report

**Files:**
- Create: `python-orchestrator/fixtures/runtime-recipe/fake_multi_profile_same_symbol.json`
- Modify: `python-orchestrator/app/services/symfony_client.py`
- Modify: `python-orchestrator/scripts/runtime_recipe_runner.py`

- [x] Add the versioned scenario-20 fixture with `regular`, `scalper`, and `scalper_micro` enabled on `BTCUSDT`, plus a disabled set.
- [x] Canonicalize the effective set payload with sorted compact JSON and add `config_hash="sha256:<digest>"` before runtime overlays.
- [x] Load the fixture and implement R12 validation of set preservation, unique lineage/config hashes, disabled exclusion, replay, observed orchestration contention, unexercised business-lock evidence, and Fake-only proof (OKX/Hyperliquid HTTP guards plus the Fake provider boundary for Bitmart).
- [x] Export `fake-multi-profile-recipe-report.json` and `.md` from one normalized redacted object.
- [x] Run the focused Python tests until green.

### Task 3: Promote golden scenario 20 through the shared fixture

**Files:**
- Modify: `trading-app/tests/fixtures/fake-paper/golden-scenarios-v1.json`
- Modify: `trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioCatalogTest.php`
- Modify: `trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioExecutionTest.php`
- Modify: `trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioRunner.php`

- [x] First change catalogue expectations to 20 executable scenarios and run PHPUnit to observe the runner mismatch.
- [x] Add `dry_run_multi_profiles_same_symbol` to the golden runner and derive deterministic facts from the shared Python fixture.
- [x] Assert the exact normalized facts, redaction, unique hashes, lock scopes, business-lock evidence versus contract, replay/restart contract, and the per-exchange proof methods without Bitmart instrumentation.
- [x] Run the focused golden catalogue/execution tests until green.

### Task 4: Document contention and the single command

**Files:**
- Modify: `docs/handbook/runbooks/orchestrator-runtime-recipe-r1-r16.md`
- Modify: `docs/handbook/technical/fake-paper-gateway.md`
- Modify: `python-orchestrator/README.md`

- [x] Document the exact `--scenario R12` command and both report paths.
- [x] Document orchestration-lock versus business-lock scopes, allowed dry-run coexistence, skip/block classifications, replay after runner restart, and rollback.
- [x] State that OKX, Hyperliquid, and Bitmart clients/endpoints are forbidden for this recipe.

### Task 5: Full verification and delivery

**Files:** all modified files above plus the canonical prompt registry.

- [x] Run focused and broader Python tests.
- [x] Run focused and broader Exchange/Fake PHPUnit tests in the container.
- [x] Run PHPStan on every touched PHP file, Symfony container lint, and YAML lint if applicable.
- [x] Run MkDocs strict, JSON parsing, secret/redaction/network scans, compile checks, and `git diff --check`.
- [x] Review the complete diff against Prompt 6 and confirm no strategy/MTF/EntryZone/frequency changes.
- [ ] Commit atomically, run `codex status` in a TTY, check the five-hour quota, push, open a PR with `Part of #196` and `Related to #188`, wait about 90 seconds, request `@codex review`, and stop at the first useful CI/review state without merging.
