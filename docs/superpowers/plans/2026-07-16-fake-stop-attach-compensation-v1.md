# Fake Stop-Attach Compensation v1 Implementation Plan

**Goal:** Integrate a deterministic reduce-only market close after Fake attached
SL rejection and make golden scenario 12 executable.

**Architecture:** `FakeExchangeMatchingEngine` owns the local compensation
because it already owns the entry fill, position mutation, protection creation,
event ledger, and atomic state transaction. The compensation reuses `submit()`
and `fillOrder()` rather than duplicating fill or PnL logic.

## Task 1: Lock the adapter compensation contract

**Files:**
- Modify: `trading-app/tests/Exchange/Adapter/FakeExchangeAdapterTest.php`
- Modify: `trading-app/src/Exchange/Fake/FakeExchangeMatchingEngine.php`

1. Change the attached-protection rejection test to require a flat position,
   one filled reduce-only market compensation order, normal close evidence, and
   explicit compensation metadata on the entry.
2. Add replay coverage proving the original client order does not create a
   second compensation.
3. Run the focused adapter tests and verify RED.
4. Implement the minimal deterministic compensation branch.
5. Re-run adapter tests and PHPStan.
6. Commit.

## Task 2: Prove persistence and normalized lifecycle

**Files:**
- Modify: `trading-app/tests/Exchange/Event/FakeExchangeEventNormalizerTest.php`
- Modify: `trading-app/tests/Exchange/Adapter/FakeExchangeAdapterTest.php`
- Modify production only if the existing normalizer or snapshots lose evidence.

1. Prove the protection rejection remains normalized.
2. Prove the compensation fill and position close survive a file-backed restart.
3. Verify no raw secret or arbitrary request payload is added.
4. Run focused tests and PHPStan.
5. Commit.

## Task 3: Promote golden scenario 12

**Files:**
- Modify: `trading-app/tests/fixtures/fake-paper/golden-scenarios-v1.json`
- Modify: `trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioCatalogTest.php`
- Modify: `trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioRunner.php`
- Modify: `trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioExecutionTest.php`

1. Change `stop_loss_attach_failure` to `executable`.
2. Add a runner that rejects the attached SL and returns normalized compensation
   facts.
3. Require 14 executable scenarios and deterministic expected facts.
4. Run golden tests and PHPStan.
5. Commit.

## Task 4: Documentation and final verification

**Files:**
- Modify: `trading-app/src/Exchange/Fake/README.md`
- Modify: `docs/handbook/technical/fake-paper-gateway.md`
- Modify: `trading-app/docs/exchange/fake-instrument-risk-model-v1.md`

Document the compensation sequence, invariant failure, replay behavior, rollback,
and reduced gap list.

Run:

```bash
cd trading-app
php vendor/bin/phpunit \
  tests/Exchange/Fake \
  tests/Exchange/Adapter/FakeExchangeAdapterTest.php \
  tests/Exchange/Event/FakeExchangeEventNormalizerTest.php
php vendor/bin/phpunit tests/Exchange tests/Provider/Fake tests/TradeEntry/Execution
php vendor/bin/phpstan analyse --memory-limit=1G <all touched PHP files>
php -d error_reporting=8191 bin/console lint:container --no-debug
php -d error_reporting=8191 bin/console lint:yaml config
cd ..
python3 -m mkdocs build --strict
git diff --check
```

Open a PR with `Part of #196`, reference #280 and #278, request one Codex review
after CI starts, address every thread, and merge only with green CI and an
explicit Codex approval.
