# Fake/Paper Liquidation v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> `superpowers:executing-plans` to implement this plan inline task-by-task. Do
> not delegate: this worktree is assigned to the `worker_critical` process.

**Goal:** Add a deterministic, exact-decimal, isolated-margin liquidation
model and preventive guard to Fake/Paper with dedicated costs, atomic state,
and exact-once restart behavior.

**Architecture:** A pure versioned calculator derives isolated liquidation and
guard thresholds from exact instrument, margin, and position facts. The
existing Fake matching transaction persists explicit controlled marks,
preflights every exposure increase, alerts on the guard zone, and atomically
creates a dedicated liquidation fill, cancels protections, closes the position,
updates the balance, and appends lifecycle/ledger facts.

**Tech Stack:** PHP 8.4, Symfony 7.1, PHPUnit 11, Brick Math, Psr Clock, existing
Fake/Paper state/matching/event pipeline, Doctrine fill-cost ledger, YAML, and
MkDocs.

---

### Task 1: Freeze the Pure Liquidation Contract

**Files:**

- Create: `trading-app/src/Exchange/Fake/FakeLiquidationPolicy.php`
- Create: `trading-app/src/Exchange/Fake/FakeLiquidationInput.php`
- Create: `trading-app/src/Exchange/Fake/FakeLiquidationResult.php`
- Create: `trading-app/src/Exchange/Fake/FakeLiquidationCalculator.php`
- Create: `trading-app/tests/Exchange/Fake/FakeLiquidationCalculatorTest.php`

- [ ] **Step 1: Write RED long/short threshold tests**

  Add exact-decimal assertions for these fixtures:

  ```text
  q=1, c=1, E=25000, M=2500, r=0.005, buffer=0.01
  long liquidation = 22613.065326633166, guard = 22863.065326633166
  short liquidation = 27363.184079601990, guard = 27113.184079601990
  ```

  Assert state classification is safe, guard, exact-threshold liquidation, and
  gap liquidation for both sides. Assert 1x long produces a valid zero
  threshold and positive distinct guard.

- [ ] **Step 2: Write RED fail-closed metadata tests**

  Cover cross margin (`unsupported`), null/malformed/non-positive quantity,
  entry, margin, contract size and mark, maintenance rate outside `(0,1)`,
  invalid guard rate, guard outside entry/threshold, and inconsistent side.
  Assert stable reason codes and no inferred zero.

- [ ] **Step 3: Write RED liquidation-fee tests**

  Assert `1 * 22000 * 1 * 0.005 = 110.000000000000`, non-zero fee, USDT,
  exact model version, and fail-closed invalid quantity/mark/contract/rate.

- [ ] **Step 4: Run RED**

  Run:

  ```bash
  vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php \
    --do-not-cache-result tests/Exchange/Fake/FakeLiquidationCalculatorTest.php
  ```

  Expected: FAIL because the liquidation v1 classes do not exist.

- [ ] **Step 5: Implement the minimal pure model**

  Use `BigDecimal`, scale 12, and `RoundingMode::HALF_EVEN`. Implement the
  isolated formulas and policy constants from the design. The result must carry
  status/reason, threshold, guard price/amount, mark classification, and
  redacted version/rate metadata.

- [ ] **Step 6: Run GREEN and refactor**

  Run the Task 1 test file until all paths pass without warnings, then remove
  duplication while keeping the same assertions green.

- [ ] **Step 7: Commit**

  Stage only Task 1 files and commit `feat(fake): add isolated liquidation calculator`.

### Task 2: Persist Explicit Marks and Enforce Preflight

**Files:**

- Modify: `trading-app/src/Exchange/Fake/FakeExchangeStateStore.php`
- Modify: `trading-app/src/Exchange/Fake/FakeExchangeOrderBook.php`
- Modify: `trading-app/src/Exchange/Fake/FakeExchangeMatchingEngine.php`
- Modify: `trading-app/src/Exchange/Adapter/FakeExchangeAdapter.php`
- Modify: `trading-app/tests/Exchange/Adapter/FakeExchangeAdapterTest.php`
- Modify: `trading-app/tests/Provider/Fake/FakeProvidersTest.php`
- Create: `trading-app/tests/Exchange/Fake/FakeLiquidationTest.php`

- [ ] **Step 1: Write RED explicit-mark persistence tests**

  Prove fresh BTC/ETH marks are explicit, `movePrice()` changes the named mark,
  mark state survives filesystem restart, and a legacy state payload without
  `markPrices` restores with no mark instead of deriving bid/ask.

- [ ] **Step 2: Write RED preflight tests**

  Assert an isolated entry persists threshold/guard/model metadata; a cross
  entry is rejected with `liquidation_cross_margin_unsupported`; `setLeverage`
  refuses cross; a missing mark or legacy same-side position metadata rejects
  before an active order, margin reservation, or fill; a client replay returns
  the original result.

- [ ] **Step 3: Write RED fill-boundary tests**

  Place a resting order while safe, move the mark into its resulting guard,
  then attempt a fill. Assert a zero-fill order becomes rejected, a partially
  filled parent cancels only its remainder and protects existing exposure, and
  no second order/fill/PnL/cost appears on replay.

- [ ] **Step 4: Run RED**

  Run the new liquidation integration test and the exact cross/provider tests.
  Expected failures must show the missing mark/preflight contract, not fixture
  or syntax errors.

- [ ] **Step 5: Add additive mark state**

  Add `markPrices` to initialization, persistence, hydration, runtime snapshots,
  rollback snapshots, and shape validation. Missing legacy fields hydrate to an
  empty map. Add exact `setMarkPrice()`, `getMarkPrice()`, and `hasMarkPrice()`;
  never read an order book or trade from those methods.

- [ ] **Step 6: Add preflight helpers**

  After exact replay/Daily loss/One-Way validation and before order creation,
  calculate the prospective isolated position from exact request and persisted
  position metadata. Store safe model inputs/results in whitelisted order
  metadata. Re-evaluate non-reduce-only fills with actual quantity/price before
  mutation. Reuse the established partial-remainder protection behavior.

- [ ] **Step 7: Make cross explicitly unsupported**

  Keep direct validator parsing compatible, but reject cross exposure in
  liquidation preflight and return `false` from adapter `setLeverage(...,
  'cross')`. Update provider expectations to assert the stable unsupported
  behavior and unchanged isolated path.

- [ ] **Step 8: Run GREEN and regressions**

  Run Task 2 files, `FakeOrderValidatorTest`, `FakeOneWayConflictGuardTest`, and
  `FakeDailyLossCapGuardTest` until green.

- [ ] **Step 9: Commit**

  Stage Task 2 files only and commit `feat(fake): fail closed on liquidation preflight`.

### Task 3: Liquidate Atomically on Controlled Mark

**Files:**

- Modify: `trading-app/src/Exchange/Fake/FakeExchangeMatchingEngine.php`
- Modify: `trading-app/src/Exchange/Fake/FakeExchangeScenarioService.php`
- Modify: `trading-app/src/Exchange/Fake/FakeExchangeStateStore.php`
- Modify: `trading-app/tests/Exchange/Fake/FakeLiquidationTest.php`

- [ ] **Step 1: Write RED guard-alert tests**

  Open protected long and short positions, move only to the guard zone, and
  assert one `liquidation.guard_entered`, updated mark/unrealized PnL, no
  liquidation order/fill, no protection cancellation, and no duplicate alert
  on repeated mark or restart.

- [ ] **Step 2: Write RED threshold/gap tests**

  Cover long/short exact threshold and a gap beyond it. Assert execution at the
  observed mark rather than threshold, a deterministic filled reduce-only
  MARKET order, `liquidation.triggered`, `liquidation.filled`, and
  `position.closed` with `close_reason=liquidation`.

- [ ] **Step 3: Write RED fee/balance/net-PnL tests**

  Assert ordinary exit fee, spread/slippage, and the separate non-zero
  liquidation fee each appear once. Verify the certified close net is gross
  minus all known components once and the USDT total/equity delta equals that
  net result without crediting released margin.

- [ ] **Step 4: Write RED cleanup/atomicity/restart tests**

  Assert all active SL/TP/TRIGGER/trailing protections for the exact position
  side are cancelled with `position_liquidated`, unrelated scopes remain, an
  injected exception rolls back every mutation, and a filesystem restart plus
  repeated mark creates no second liquidation, fee, balance delta, or PnL.

- [ ] **Step 5: Run RED**

  Run `FakeLiquidationTest.php`; expected failures must identify missing
  guard/liquidation transitions.

- [ ] **Step 6: Implement mark evaluation inside the state transaction**

  Evaluate positions before ordinary stop/limit matching. Persist exact mark
  state, update position mark/unrealized values, and emit one alert per
  unchanged liquidation calculation.

- [ ] **Step 7: Implement the dedicated liquidation close**

  Derive the client ID from immutable position identity and model version,
  build/save the filled order, compute fees with exact decimals, append the
  dedicated events, cancel protections, remove the position, and adjust the
  USDT balance in the existing transaction. Return the liquidation order in the
  scenario's matched-order list.

- [ ] **Step 8: Generalize certified close arithmetic**

  Make the close payload accept an explicit known liquidation fee and use exact
  decimal addition/subtraction. Normal closes retain an explicit not-applicable
  zero; liquidation closes must pass the policy-calculated non-zero amount.

- [ ] **Step 9: Run GREEN and matching regressions**

  Run the liquidation test, full adapter test, TP1/trailing, fallback,
  protection compensation, funding, One-Way, and state-persistence suites.

- [ ] **Step 10: Commit**

  Commit Task 3 as `feat(fake): liquidate positions atomically on mark`.

### Task 4: Project the Dedicated Fill and Count Costs Once

**Files:**

- Modify: `trading-app/src/Exchange/Fake/FakeExchangeEventNormalizer.php`
- Modify: `trading-app/src/Exchange/Adapter/FakeExchangeAdapter.php`
- Modify: `trading-app/src/Trading/Pnl/FillCostLedgerIngestionService.php`
- Modify: `trading-app/src/Exchange/Fake/FakeDailyLossCapGuard.php`
- Modify: `trading-app/tests/Exchange/Event/FakeExchangeEventNormalizerTest.php`
- Modify: `trading-app/tests/Trading/Pnl/FillCostLedgerIngestionServiceTest.php`
- Modify: `trading-app/tests/Exchange/Fake/FakeDailyLossCapGuardTest.php`
- Modify: `trading-app/tests/Exchange/Fake/FakeLiquidationTest.php`

- [ ] **Step 1: Write RED normalization tests**

  Assert `liquidation.filled` normalizes to one filled-order lifecycle event and
  one `ExchangeFillReceived`; REST and WS snapshots share the same deterministic
  fill ID and expose liquidation fee/rate/model, ordinary costs, exact quantity,
  and redacted lineage.

- [ ] **Step 2: Write RED ledger tests**

  Ingest the liquidation fill and assert an exit row with separate exact
  `liquidation_fee_usdt`, one idempotency key, replay without insertion, conflict
  on a changed fee, invalid fee flagged/null, and ordinary fill remains null
  rather than invented zero.

- [ ] **Step 3: Write RED Daily loss tests**

  Assert one `liquidation.filled` contributes realized gross minus fill fee,
  spread, slippage, and liquidation fee exactly once. Repeated snapshot replay
  must not add an event; a missing/invalid liquidation fee makes the cap
  `not_computable`.

- [ ] **Step 4: Run RED**

  Run the three focused files and retain failures for the missing event/cost
  branches.

- [ ] **Step 5: Implement minimal projection and cost ingestion**

  Treat `liquidation.filled` as a dedicated filled order/fill in both normalizer
  and REST snapshot. Whitelist liquidation metadata. Read the non-negative fee
  from metadata/payload into the existing ledger column; invalid is a quality
  flag and null.

- [ ] **Step 6: Extend the Daily loss monetary fold**

  Accept the dedicated event and require its fee/model/currency fields. Subtract
  the liquidation fee in the same fill delta; do not fold `position.closed`.

- [ ] **Step 7: Run GREEN and PnL regressions**

  Run event, ledger, aggregation, certification, Daily loss, and liquidation
  tests. If the kernel ledger test selects a real PostgreSQL connection, use
  only the isolated test schema and never load an environment test file.

- [ ] **Step 8: Commit**

  Commit Task 4 as `feat(fake): project liquidation fees exactly once`.

### Task 5: Runtime Readiness, Config, and Operator Documentation

**Files:**

- Modify: `trading-app/src/Provider/Fake/FakeRuntimeCheck.php`
- Modify: `trading-app/src/Exchange/Adapter/FakeExchangeAdapter.php`
- Modify: `trading-app/config/services.yaml`
- Modify: `trading-app/config/trading/exchange/fake.yaml`
- Modify: `trading-app/src/Exchange/Fake/README.md`
- Modify: `docs/handbook/technical/fake-paper-gateway.md`
- Modify: `trading-app/tests/Exchange/Readiness/FakeRuntimeCheckTest.php`
- Modify: `trading-app/tests/Command/FakeRuntimeCheckContainerTest.php`

- [ ] **Step 1: Write RED runtime metadata/readiness tests**

  Assert exact model, fee, buffer, mark-source, isolated support, and cross
  unsupported metadata. Runtime must block invalid policy/version, missing mark,
  invalid instrument metadata, persisted cross leverage/position, and malformed
  open-position liquidation metadata while preserving local dry-run safety.

- [ ] **Step 2: Run RED**

  Run runtime and container tests; expected failures must be missing liquidation
  checks only.

- [ ] **Step 3: Wire the fixed v1 policy and readiness**

  Configure plain decimal policy arguments, bump the Fake config version, and
  add blocking reason codes. Readiness remains credential-free,
  `permissions_trade=false`, kill switch on, and no network probe.

- [ ] **Step 4: Document model and rollback**

  Add formulas, mark-only semantics, guard versus threshold, isolated/cross
  limitation, fee convention, event order, exact-once guarantees, runtime
  checks, operator command, limitations, and quarantine/revert rollback. Do not
  edit the canonical Prompt 8 registry line.

- [ ] **Step 5: Run GREEN and docs checks**

  Run runtime tests, `php bin/console lint:container --no-debug`,
  `php bin/console lint:yaml config`, and `python3 -m mkdocs build --strict`.

- [ ] **Step 6: Commit**

  Commit Task 5 as `docs(fake): document liquidation v1 runtime safety`.

### Task 6: Fresh Verification and Delivery

**Files:**

- Preserve unchanged:
  `TradingV3_OKX_Hyperliquid_demo_testnet_prompts_canoniques_UNIQUE.md`
- Verify every PHP/YAML/Markdown file touched by Tasks 1-5.

- [ ] **Step 1: Run focused PHPUnit**

  Run calculator, liquidation integration, adapter, event normalizer, ledger,
  Daily loss, runtime, provider, funding, trailing, One-Way, and state recovery
  tests with `--no-configuration --bootstrap vendor/autoload.php
  --do-not-cache-result`.

- [ ] **Step 2: Run proportional suites**

  Run all `tests/Exchange/Fake`, `tests/Exchange/Adapter/Fake*`,
  `tests/Exchange/Event/Fake*`, `tests/Exchange/Readiness/Fake*`, provider Fake,
  fill-ledger/PnL, and relevant TradingCore execution tests. Record unrelated
  pre-existing failures factually; do not hide them.

- [ ] **Step 3: Run static/syntax/container/YAML/docs validation**

  Run `php -l` on every touched PHP, PHPStan on every touched PHP,
  `lint:container --no-debug`, `lint:yaml config`, and MkDocs strict. Run an
  isolated PostgreSQL/schema test only if implementation changes or directly
  depends on real schema behavior beyond the existing ledger column.

- [ ] **Step 4: Run safety and scope scans**

  Run `git diff --check`, a changed-path audit excluding the prohibited
  environment-test path, secret/token/payload scans, transport/exchange-call
  scans, and verify no strategy/MTF/EntryZone/frequency/OKX/Hyperliquid/Bitmart
  or mutative activation change.

- [ ] **Step 5: Review requirements line by line**

  Map long, short, guard, gap, fee, cross unsupported, unknown metadata,
  protection cleanup, restart/replay, and net PnL/no-double-counting to fresh
  test evidence. Confirm model/mark/atomicity/runtime/docs/rollback requirements
  and the unchanged Prompt 8 registry line.

- [ ] **Step 6: Push and open the PR**

  Push `issue/196-fake-liquidation-v1` normally without force. Open one PR to
  `main` with a factual title and body containing `Part of #196`, delivered
  criteria, exact tests, risks/rollback, and explicit absence of exchange orders
  or calls. Never use `Closes #196`.

- [ ] **Step 7: Verify delivery identity and scope**

  Check local `HEAD`, `origin/issue/196-fake-liquidation-v1`, and PR
  `headRefOid` are identical. Re-read the PR diff and open-PR list. Do not ask
  `@codex review`, merge, resolve future threads, comment/close #196, or modify
  the Prompt 8 registry.

