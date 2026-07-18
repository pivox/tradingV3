# Fake/Paper Funding Model v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add deterministic, exchange-neutral, persistent Fake/Paper funding with exact deadline idempotence and executable golden scenario 18.

**Architecture:** A pure Fake funding model calculates signed adjustments from explicit schedules and controlled position snapshots. The Fake state store persists each settlement once; the Fake normalizer emits a typed exchange-neutral funding event, and the existing Doctrine projection writes a cost-only row to `fill_cost_ledger` without creating a fill.

**Tech Stack:** PHP 8.4, Symfony 7.1, PHPUnit 11, Brick Math, Doctrine ORM, existing Fake/Paper state/event pipeline and fill-cost ledger.

---

### Task 1: Pure Funding Contract and Calculation

**Files:**
- Create: `trading-app/src/Exchange/Dto/ExchangeFundingDto.php`
- Create: `trading-app/src/Exchange/Fake/FakeFundingModelConfig.php`
- Create: `trading-app/src/Exchange/Fake/FakeFundingSchedule.php`
- Create: `trading-app/src/Exchange/Fake/FakeFundingResult.php`
- Create: `trading-app/src/Exchange/Fake/FakeFundingModel.php`
- Create: `trading-app/tests/Exchange/Fake/FakeFundingModelTest.php`

- [ ] **Step 1: Write the failing calculation tests**

  Add isolated tests that build `ExchangePositionDto` snapshots and assert:
  positive-rate long `-2.000000000000`, positive-rate short
  `2.000000000000`, negative-rate roles reversed, half-size/half-interval
  produces one quarter of the full-interval amount, missing rate is `unknown`,
  no matching position is `no_position`, unknown currency has native amount but
  null `amountUsdt`, and a future exact deadline throws
  `fake_funding_deadline_not_reached`.

- [ ] **Step 2: Run RED**

  Run: `vendor/bin/phpunit tests/Exchange/Fake/FakeFundingModelTest.php`

  Expected: FAIL because the funding classes do not exist.

- [ ] **Step 3: Implement the minimal pure model**

  `FakeFundingModel::calculate(FakeFundingSchedule $schedule, iterable $positions)`
  must use `ClockInterface`, `BigDecimal`, scale 12/HALF_EVEN, and:

  ```text
  amount = abs(size) * mark * contract_size * rate
           * applied_interval_seconds / rate_interval_seconds
  signed long = -amount; signed short = amount
  ```

  `ExchangeFundingDto` carries position identity/side, optional lineage,
  notional/rate/intervals, signed native amount/currency, nullable USDT amount,
  exact deadline, source and model version.

- [ ] **Step 4: Run GREEN**

  Run the Task 1 PHPUnit file and require all assertions to pass without
  warnings or risky tests.

### Task 2: Persistent Exact-Once Fake Settlement

**Files:**
- Modify: `trading-app/src/Exchange/Fake/FakeExchangeStateStore.php`
- Modify: `trading-app/src/Exchange/Fake/FakeFundingModel.php`
- Modify: `trading-app/tests/Exchange/Fake/FakeFundingModelTest.php`

- [ ] **Step 1: Write failing persistence tests**

  Cover same `position_identity + due_at + model_version` twice, an older
  deadline after a newer one, filesystem restart/replay, same key with a
  conflicting payload, and no extra event sequence for a duplicate.

- [ ] **Step 2: Run RED**

  Expected: FAIL because state has no atomic funding check-and-append API.

- [ ] **Step 3: Add atomic state append**

  Add a public state-store operation that executes under the existing
  transaction/file lock, compares canonical payload hashes for
  `funding.accrued`, appends once, returns replay for an identical event, and
  throws `fake_funding_idempotency_conflict` for a changed payload.

- [ ] **Step 4: Run GREEN**

  Run the funding model test and existing state-persistence tests.

### Task 3: Exchange-Neutral Normalization Without Fills

**Files:**
- Create: `trading-app/src/Exchange/Event/ExchangeFundingReceived.php`
- Modify: `trading-app/src/Exchange/Fake/FakeExchangeEventNormalizer.php`
- Modify: `trading-app/tests/Exchange/Event/FakeExchangeEventNormalizerTest.php`

- [ ] **Step 1: Write failing normalizer tests**

  Assert one `funding.accrued` event becomes exactly one
  `ExchangeFundingReceived`, preserves signed amount/deadline/model/lineage,
  and produces zero `ExchangeFillReceived` events.

- [ ] **Step 2: Run RED**

  Expected: FAIL because `ExchangeFundingReceived` and the funding normalizer
  branch do not exist.

- [ ] **Step 3: Implement DTO reconstruction and typed event**

  Reject malformed funding payloads by returning no normalized monetary event;
  never infer missing amounts, rates, currencies, deadlines, or intervals.

- [ ] **Step 4: Run GREEN**

  Run the normalizer and model test files.

### Task 4: Cost-Only Ledger Projection

**Files:**
- Modify: `trading-app/src/Trading/Pnl/FillCostLedgerIngestionService.php`
- Modify: `trading-app/src/Exchange/Event/DoctrineExchangeLocalProjectionStore.php`
- Modify: `trading-app/tests/Trading/Pnl/FillCostLedgerIngestionServiceTest.php`
- Modify: `trading-app/tests/Exchange/Event/DoctrineExchangeLocalProjectionStoreTest.php`
- Modify: `trading-app/tests/Trading/Pnl/NetPnlCertificationServiceTest.php`

- [ ] **Step 1: Write failing projection tests**

  Assert exact ledger idempotency uses position/deadline/model (not arrival
  order), known USDT preserves positive/negative signs, unknown currency writes
  null `funding_usdt` plus `funding_currency_not_normalized`, optional
  `internal_trade_id` is retained/resolved, replay is not inserted twice, and
  the Doctrine store never calls trade-fill synchronization for funding.

- [ ] **Step 2: Write failing PnL duplicate test**

  Aggregate one funding row plus its replay and assert the credit/debit changes
  certified net PnL exactly once. Missing funding must keep certification
  incomplete rather than becoming zero.

- [ ] **Step 3: Run RED**

  Run the targeted ledger/projection/PnL tests. Expected: FAIL because typed
  funding ingestion and projection are absent.

- [ ] **Step 4: Implement minimal ingestion/projection**

  Add `ingestFunding(ExchangeFundingReceived $event)`, canonical key/hash,
  redacted audit fields, lineage lookup by exact IDs, null order/fill fields,
  `fill_role=funding`, and a projection branch before position projection.

- [ ] **Step 5: Run GREEN**

  Run all four targeted files. If PostgreSQL is unavailable locally, execute
  pure/mocked coverage locally and record the guarded integration limitation;
  CI must run the real Doctrine coverage.

### Task 5: Fixtures, Golden Scenario 18, and Monetary Documentation

**Files:**
- Create: `trading-app/tests/fixtures/fake-paper/funding-model-v1.json`
- Modify: `trading-app/tests/fixtures/fake-paper/golden-scenarios-v1.json`
- Modify: `trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioCatalogTest.php`
- Modify: `trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioRunner.php`
- Modify: `trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioExecutionTest.php`
- Modify: `docs/handbook/technical/fill-cost-ledger.md`
- Modify: `docs/handbook/technical/fake-paper-gateway.md`
- Modify: `trading-app/src/Exchange/Fake/README.md`

- [ ] **Step 1: Write the failing golden/catalog expectation**

  Change scenario 18 to executable and add deterministic expected facts for
  long/short pay/receive, partial notional, absent/no-position results,
  normalized event/fill counts, duplicate/restart/late replay, currency state,
  lineage, and model/fixture versions.

- [ ] **Step 2: Run RED**

  Expected: catalog/runner tests fail because `funding` is unsupported.

- [ ] **Step 3: Add versioned fixture and runner**

  Parse only `fake-funding-fixtures-v1`, execute schedules with controlled
  clocks, persist/reload a temporary state file, and return canonical facts.

- [ ] **Step 4: Document monetary semantics and rollback**

  State positive-credit/negative-debit, long/short rate direction, native vs
  normalized currency, null unknown funding, exact deadline identity, no fill
  semantics, PnL formula, no exchange writes, and code-only rollback.

- [ ] **Step 5: Run GREEN**

  Run funding, normalizer, golden catalog/execution, ledger, aggregation and PnL
  tests.

### Task 6: Proportional Validation and Delivery

**Files:**
- Preserve: `TradingV3_OKX_Hyperliquid_demo_testnet_prompts_canoniques_UNIQUE.md`
- Add all Task 1-5 files to the atomic PR.

- [ ] **Step 1: Run static/runtime validation**

  Run targeted PHPUnit, broader Fake/Event/PnL PHPUnit, PHPStan on touched PHP,
  `php bin/console lint:container --no-debug`, `php bin/console lint:yaml config`,
  `python3 -m mkdocs build --strict`, `git diff --check`, and a secret-pattern
  scan. Use no mainnet/demo/testnet write command.

- [ ] **Step 2: Review the diff**

  Verify no strategy/MTF/EntryZone/frequency changes, no secret, no migration,
  no synthetic entry/exit fill, no silent zero, and the pre-existing registry
  edit is unchanged.

- [ ] **Step 3: Commit and inspect quota**

  Commit the implementation atomically, then run Codex in TTY with profile
  `worker_complex` and confirm usage is not exhausted or within the 3% gate.

- [ ] **Step 4: Push and open PR**

  Push `issue/196-funding-v1`, open a PR against `main` with `Part of #196` and
  `Related to #190`, report validation/rollback/no-exchange-write evidence, wait
  about 90 seconds, then comment `@codex review` on the pushed HEAD.

- [ ] **Step 5: Observe only**

  Report available CI/review state and blockers. Do not merge.
