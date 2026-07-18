# Fake/Paper Daily Loss Cap v1 Implementation Plan

> **Execution profile:** `worker_critical`, strict TDD, no subagent delegation.

**Goal:** Enforce an auditable, persistent, fail-closed UTC Daily loss cap for
Fake/Paper exposure increases while preserving every risk-reducing path.

**Architecture:** Add a pure exact-decimal evaluator over the existing
persistent Fake event journal, emit signed realized gross PnL on every fill,
and invoke the guard in the ordinary matching-engine preflight after exact
client replay but before any new order, margin, or fill side effect. Surface
versioned redacted status through runtime readiness and existing rejection
events.

**Tech stack:** PHP 8.4, Symfony 7.1 Clock, PHPUnit 11, Brick Math, existing
Fake/Paper state/event/order pipeline, YAML configuration, MkDocs.

---

### Task 1: Freeze the Orchestration and Design Contract

**Files:**

- Modify: `TradingV3_OKX_Hyperliquid_demo_testnet_prompts_canoniques_UNIQUE.md`
- Create: `docs/superpowers/specs/2026-07-18-fake-paper-daily-loss-cap-v1-design.md`
- Create: `docs/superpowers/plans/2026-07-18-fake-paper-daily-loss-cap-v1.md`

- [x] Read the worktree instructions, master prompt, canonical prompt, issue
  #196, Prompt 6 PR #287, and real monetary-history references.
- [x] Verify branch/main anchor and absence of a concurrent open PR.
- [x] Audit monetary contracts, funding, clocks, persistence/replay, runtime,
  exposure guards, audit, and metrics.
- [x] Record the criterion matrix, selected event-sourced design, risks, and
  rollback.
- [x] Change only Prompt 7 to `in_progress` with branch and worker profile.

### Task 2: Define the Daily-Loss Contract in RED Tests

**Files:**

- Create: `trading-app/tests/Exchange/Fake/FakeDailyLossCapGuardTest.php`
- Modify: `trading-app/tests/Exchange/Fake/FakeRuntimeCheckTest.php`
- Modify: `trading-app/tests/Exchange/Fake/FakeExchangeMatchingEngineTest.php`

- [ ] Write isolated evaluator tests for below cap, exact cap, realized-PnL
  exceedance, fees/funding exceedance, unknown required cost, UTC midnight,
  restart, exact duplicate, conflicting duplicate, signs, exact decimals,
  future facts, and invalid limits.
- [ ] Write matching-engine tests proving the rejection occurs before margin
  and order creation, is structured/redacted/idempotent, and permits
  reduce-only, SL, TP, trigger/emergency close paths.
- [ ] Write fill-accounting tests for signed partial long/short realization.
- [ ] Write runtime-check tests for ready, reached, invalid, and
  `not_computable` states.
- [ ] Run the focused tests and retain the expected missing-contract failures
  as RED evidence.

### Task 3: Implement the Pure Versioned Policy and Evaluator

**Files:**

- Create: `trading-app/src/Exchange/Fake/FakeDailyLossCapPolicy.php`
- Create: `trading-app/src/Exchange/Fake/FakeDailyLossCapStatus.php`
- Create: `trading-app/src/Exchange/Fake/FakeDailyLossCapGuard.php`

- [ ] Validate the configured limit as a positive, bounded, plain decimal.
- [ ] Derive the current day exclusively from the injected controlled clock.
- [ ] Fold only in-window fill/funding facts with `BigDecimal`, scale 12, and
  `HALF_EVEN`.
- [ ] Require complete USDT costs and realized gross for reductions; preserve
  funding sign; reject malformed, future, or ambiguous facts.
- [ ] Deduplicate identical event sequences, reject conflicting duplicates,
  and derive metrics from persistent events.
- [ ] Return stable safe status/audit metadata without mutable counters.

### Task 4: Enforce Before Exposure and Persist Realized Fill Facts

**Files:**

- Modify: `trading-app/src/Exchange/Fake/FakeExchangeMatchingEngine.php`
- Modify: `trading-app/src/Exchange/Fake/FakeExchangeAdapter.php`
- Modify: `trading-app/src/Exchange/Fake/FakeRuntimeCheck.php`

- [ ] Calculate signed realized gross PnL for every reduction fill from entry
  price, execution price, reduced quantity, side, and contract size.
- [ ] Persist the scale-12 value on the fill event; never infer it from a later
  full-close event.
- [ ] Evaluate after exact client replay and re-evaluate accepted resting orders
  at the fill boundary before order/fill/position side effects for every
  exposure increase.
- [ ] Reuse the established reduction/protection classification to bypass only
  genuine risk-reducing paths.
- [ ] Persist `daily_loss_cap_reached` or
  `daily_loss_cap_not_computable` through the existing rejected-order/event
  path with redacted structured metadata.
- [ ] Expose versioned daily status through Fake adapter metadata and make
  runtime readiness blocking for reached, invalid, or not-computable states.

### Task 5: Wire Configuration and Operator Documentation

**Files:**

- Modify: `trading-app/config/services.yaml`
- Modify: `trading-app/config/trading/exchange/fake.yaml`
- Modify: `trading-app/src/Exchange/Fake/README.md`
- Modify: `docs/handbook/technical/fake-paper-gateway.md`

- [ ] Register the policy/guard with a conservative positive USDT limit.
- [ ] Bump and document the Fake model/policy version without adding a live or
  transport setting.
- [ ] Document formula, signs, exact-cap semantics, `not_computable`, day
  rollover, audit fields, readiness, metrics, operating checks, and rollback.
- [ ] Confirm no migration is needed and no PostgreSQL query is introduced.

### Task 6: GREEN and Proportional Verification

- [ ] Run the focused Daily loss cap tests until GREEN.
- [ ] Run related Fake matching, funding, persistence/replay, runtime,
  certification, and container suites.
- [ ] Run the repository PHP baseline proportionally.
- [ ] Run PHPStan over every touched PHP file.
- [ ] Run container lint with `--no-debug`, YAML lint, and MkDocs strict.
- [ ] Run secret/redaction scans, `git diff --check`, and a complete changed
  path/transport audit.
- [ ] Record any proven pre-existing failure without hiding it. PostgreSQL is
  not applicable unless implementation unexpectedly adds a schema dependency.

### Task 7: Deliver Without Review or Merge

- [ ] Audit the complete diff against Prompt 7 and confirm there is no exchange
  call/order and no unrelated strategy/live change.
- [ ] Create coherent commits and push the existing branch without force.
- [ ] Open one atomic PR with `Part of #196`, objective, behavior, tests,
  risks, rollback, gaps, and an explicit zero-exchange-call/order statement.
- [ ] Update only Prompt 7 with PR and candidate HEAD while keeping it
  `in_progress`; push the registry-only successor commit without force.
- [ ] Do not request `@codex` review, merge, close #196, mark Prompt 7 done, or
  comment that #196 is delivered.
- [ ] Capture one final CI/thread snapshot and report local SHA, remote SHA, PR,
  exact files, verification, limits, and rollback.

### Task 8: Address PR #288 P2 Partial-Fill Remainder Finding

**Files:**

- Modify: `trading-app/tests/Exchange/Fake/FakeDailyLossCapGuardTest.php`
- Modify: `trading-app/tests/Trading/Lineage/LimitFillWatchMessageHandlerLineageTest.php`
- Modify: `trading-app/src/Exchange/Fake/FakeExchangeMatchingEngine.php`
- Modify: `trading-app/src/TradeEntry/MessageHandler/LimitFillWatchMessageHandler.php`
- Modify: `docs/superpowers/specs/2026-07-18-fake-paper-daily-loss-cap-v1-design.md`

- [ ] Add a direct-fill regression that partially fills a persistent resting
  entry, reaches the cap, and expects one `CANCELLED` remainder with the fill
  preserved, exact attached protection, redacted audit metadata, and stable
  replay after filesystem restart.
- [ ] Add a `movePrice()` matching regression that partially fills an entry,
  makes the cap not computable, injects one attached-protection rejection, and
  expects the existing reduce-only compensation to flatten only the acquired
  exposure without duplicate matching or terminal events.
- [ ] Add a watcher regression where a terminal `CANCELLED` order with a
  positive filled quantity logs `position_opened` for the actual fill and never
  logs `order_expired`.
- [ ] Run only the three new tests and retain RED evidence: the matching tests
  must observe `REJECTED` instead of `CANCELLED`, and the watcher test must
  observe `order_expired` instead of `position_opened`.
- [ ] Change the shared cap terminalization helper so zero-fill orders keep the
  existing `REJECTED` transition, while partially filled orders use one
  `CANCELLED` transition and immediately reuse attached protection/compensation.
- [ ] Make the watcher test filled quantity before classifying
  cancelled/rejected/expired as a no-fill terminal order; do not special-case an
  exchange or transport.
- [ ] Re-run the three tests GREEN, then the Daily cap, Fake adapter/runtime,
  execution/lifecycle, and proportional repository suites.
- [ ] Run PHPStan on every touched PHP file, PHP syntax, Symfony container lint
  with `--no-debug`, all YAML lint, MkDocs strict, `git diff --check`, and
  secret/payload/transport scans before the targeted commit and non-force push.
- [ ] Reply to review comment `3609250661` with the pushed SHA and evidence,
  resolve GraphQL thread `PRRT_kwDOPH0yO86SBjol`, verify local/remote/PR SHA and
  CI, and do not request review, merge, close #196, or mark Prompt 7 done.
