# Paper Canonical Execution-Cost Source Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build an unwired, deterministic and fail-closed source for complete canonical Paper execution-cost snapshots.

**Architecture:** A focused source composes the existing canonical order-book and funding sources with the compiled execution policy and the exact `FakeFillCostModel`. It returns the existing `CanonicalExecutionCostSnapshot`; canonical risk and net-R engines remain the sole fee and PnL arithmetic authorities.

**Tech Stack:** PHP 8.4, PHPUnit 11, Symfony services, `CanonicalJson`, Brick Math through existing domain types.

---

## File map

- Create `trading-app/src/Trading/Paper/Execution/Strategy/PaperCanonicalExecutionCostSource.php`: validate identity and policy, source public evidence, map model rates and build the canonical snapshot/hash.
- Create `trading-app/tests/Trading/Paper/Execution/Strategy/PaperCanonicalExecutionCostSourceTest.php`: happy-path, determinism and fail-closed contract tests using real Paper market projectors/sources.
- Keep `trading-app/src/TradingCore/OrderPlan/Canonical/CanonicalExecutionCostSnapshot.php` unchanged: it remains the shared domain contract.
- Keep `trading-app/src/Exchange/Fake/FakeFillCostModel.php` unchanged: it remains the exact execution-model authority.

### Task 1: Specify the complete canonical cost snapshot

**Files:**
- Create: `trading-app/tests/Trading/Paper/Execution/Strategy/PaperCanonicalExecutionCostSourceTest.php`

- [ ] **Step 1: Write the failing happy-path test**

Create a PHPUnit test that resolves the published Paper policy and uses real
book/funding events:

```php
public function testBuildsCompleteCostSnapshotFromCanonicalEvidence(): void
{
    [$source, $cell, $trigger, $policy] = $this->context();

    $costs = $source->snapshotFor($cell, $trigger, $policy);

    self::assertNotNull($costs);
    self::assertSame('okx', $costs->exchange);
    self::assertSame('mainnet', $costs->environment);
    self::assertSame('BTCUSDT', $costs->symbol);
    self::assertSame('perpetual', $costs->marketType);
    self::assertSame($policy->configHash, $costs->configHash);
    self::assertSame('maker', $costs->entryLiquidityRole);
    self::assertSame('taker', $costs->stopLiquidityRole);
    self::assertSame('order_book', $costs->entrySpreadSource);
    self::assertEqualsWithDelta(0.0002, $costs->entrySpreadRate, 1.0e-12);
    self::assertSame('execution_model', $costs->entrySlippageSource);
    self::assertSame(0.0, $costs->entrySlippageRate);
    self::assertSame(0.0005, $costs->stopSlippageRate);
    self::assertSame('venue_schedule', $costs->fundingSource);
    self::assertSame(0.0001, $costs->fundingRate);
    self::assertSame(['tp1', 'tp2'], array_map(
        static fn (CanonicalTargetCostSnapshot $target): string => $target->targetId,
        $costs->targets,
    ));
    self::assertSame('2026-08-01T10:00:58.000000Z', $costs->observedAt->format('Y-m-d\\TH:i:s.u\\Z'));
    self::assertMatchesRegularExpression('/\\Asha256:[a-f0-9]{64}\\z/D', $costs->inputHash);
}
```

The `context()` helper must:

1. resolve `day_trading@1.1.0 / day_trading.trend_continuation.long@1.1.0 / okx / mainnet / Paper`;
2. compile the policy;
3. create a modern cell with that exact config hash;
4. restore one strict OKX top-of-book event, one strict
   `paper-funding-rate.v1` event and the current 15m candle trigger;
5. share one replay clock across the real book, funding and cost sources.

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
cd trading-app
./vendor/bin/phpunit tests/Trading/Paper/Execution/Strategy/PaperCanonicalExecutionCostSourceTest.php
```

Expected: FAIL because `PaperCanonicalExecutionCostSource` does not exist.

- [ ] **Step 3: Commit the red test**

```bash
git add trading-app/tests/Trading/Paper/Execution/Strategy/PaperCanonicalExecutionCostSourceTest.php
git commit -m "test(paper): specify canonical execution costs"
```

### Task 2: Implement the minimal composer

**Files:**
- Create: `trading-app/src/Trading/Paper/Execution/Strategy/PaperCanonicalExecutionCostSource.php`

- [ ] **Step 1: Implement identity, evidence and rate composition**

Use this public contract and keep all arithmetic inside the source limited to
unit conversion from basis points to rates:

```php
final readonly class PaperCanonicalExecutionCostSource
{
    public function __construct(
        private PaperCanonicalOrderBookSource $books,
        private PaperCanonicalFundingSource $funding,
        private PaperReplayClock $clock,
        private FakeFillCostModel $fillCosts = new FakeFillCostModel(),
    ) {
    }

    public function snapshotFor(
        PaperExecutionCell $cell,
        PaperMarketEvent $trigger,
        CanonicalExecutionPolicy $policy,
    ): ?CanonicalExecutionCostSnapshot {
        $this->assertIdentity($cell, $trigger, $policy);
        $this->assertContract($policy);

        $book = $this->books->snapshotFor($cell, $trigger);
        if ($book === null) {
            return null;
        }
        $funding = $this->funding->snapshotFor(
            $cell,
            $trigger,
            $policy->costContract->fundingIntervalSeconds,
        );
        if ($funding === null) {
            return null;
        }
        $this->assertBook($cell, $trigger, $policy, $book);

        $spreadRate = $book->spreadBps / 10_000.0;
        $entrySlippage = $this->slippageRate((string) $policy->costContract->entryLiquidityRole);
        $stopSlippage = $this->slippageRate((string) $policy->costContract->stopLiquidityRole);
        $targets = array_map(
            fn (CanonicalTargetPolicy $target): CanonicalTargetCostSnapshot =>
                new CanonicalTargetCostSnapshot(
                    $target->id,
                    $policy->costContract->targetSpreadSource,
                    $spreadRate,
                    $policy->costContract->targetSlippageSource,
                    $this->slippageRate($target->liquidityRole),
                ),
            $policy->targets,
        );

        $hashPayload = $this->hashPayload(
            $cell,
            $trigger,
            $policy,
            $book,
            $funding,
            $spreadRate,
            $entrySlippage,
            $stopSlippage,
            $targets,
        );

        return new CanonicalExecutionCostSnapshot(
            $book->exchange,
            $book->environment,
            $book->symbol,
            $book->marketType,
            $policy->configHash,
            $policy->costContract->entryLiquidityRole,
            $policy->costContract->stopLiquidityRole,
            $policy->costContract->entrySpreadSource,
            $spreadRate,
            $policy->costContract->entrySlippageSource,
            $entrySlippage,
            $policy->costContract->stopSpreadSource,
            $spreadRate,
            $policy->costContract->stopSlippageSource,
            $stopSlippage,
            $policy->costContract->fundingSource,
            $funding->rate,
            $targets,
            $book->observedAt,
            'sha256:' . hash('sha256', CanonicalJson::encode($hashPayload)),
        );
    }
}
```

`assertIdentity()` must compare every risk-policy identity field with the modern
cell, config hash, network/venue and trigger symbol. `assertContract()` must
require the frozen source tuple, a non-null order policy, matching entry role
and non-empty unique targets. `assertBook()` must require exact scope/source,
non-future evidence and `maximumInputAgeSeconds` freshness using
`CanonicalOrderPlanTime::isOlderThan()`.

`slippageRate()` must validate `maker|taker`, call
`FakeFillCostModel::forFill(1.0, 1.0, 1.0, $role === 'maker')`, and return the
resulting slippage USDT as the unit-notional rate. `hashPayload()` must include
the exact fields enumerated in the design, including both model versions and
the ordered target cost rows.

- [ ] **Step 2: Run the focused test to verify it passes**

```bash
cd trading-app
./vendor/bin/phpunit tests/Trading/Paper/Execution/Strategy/PaperCanonicalExecutionCostSourceTest.php
```

Expected: PASS with no errors or failures.

- [ ] **Step 3: Commit the minimal implementation**

```bash
git add trading-app/src/Trading/Paper/Execution/Strategy/PaperCanonicalExecutionCostSource.php
git commit -m "feat(paper): compose canonical execution costs"
```

### Task 3: Prove fail-closed and lineage behavior

**Files:**
- Modify: `trading-app/tests/Trading/Paper/Execution/Strategy/PaperCanonicalExecutionCostSourceTest.php`
- Modify: `trading-app/src/Trading/Paper/Execution/Strategy/PaperCanonicalExecutionCostSource.php`

- [ ] **Step 1: Add failing edge-case tests**

Add explicit tests with these exact expectations:

```php
public function testReturnsNullWithoutBookOrFunding(): void
{
    foreach ([['book' => false, 'funding' => true], ['book' => true, 'funding' => false]] as $facts) {
        [$source, $cell, $trigger, $policy] = $this->context($facts['book'], $facts['funding']);
        self::assertNull($source->snapshotFor($cell, $trigger, $policy));
    }
}

public function testRejectsPolicyIdentityAndContractDrift(): void
{
    [$source, $cell, $trigger] = $this->context();
    $foreign = $this->policy('scalping', 'scalping.trend_continuation.long');
    $this->expectExceptionMessage('paper_canonical_execution_cost_identity_mismatch');
    $source->snapshotFor($cell, $trigger, $foreign);
}

public function testRejectsStaleBookEvidence(): void
{
    [$source, $cell, $trigger, $policy] = $this->context(bookAt: '2026-08-01T09:59:00Z');
    $this->expectExceptionMessage('paper_canonical_execution_cost_book_stale');
    $source->snapshotFor($cell, $trigger, $policy);
}

public function testHashIsDeterministicAndSensitiveToEveryEvidenceRoot(): void
{
    [$source, $cell, $trigger, $policy] = $this->context();
    $first = $source->snapshotFor($cell, $trigger, $policy);
    $second = $source->snapshotFor($cell, $trigger, $policy);
    self::assertSame($first?->inputHash, $second?->inputHash);

    [$changedSource, $changedCell, $changedTrigger, $changedPolicy] = $this->context(fundingRate: '-0.0001');
    self::assertNotSame(
        $first?->inputHash,
        $changedSource->snapshotFor($changedCell, $changedTrigger, $changedPolicy)?->inputHash,
    );
}
```

Also cover legacy/cross-scope cells, current-trigger enforcement inherited from
both child sources, funding interval mismatch, non-approved source strings and
target order/role sensitivity.

- [ ] **Step 2: Run the new tests and observe the failures**

```bash
cd trading-app
./vendor/bin/phpunit tests/Trading/Paper/Execution/Strategy/PaperCanonicalExecutionCostSourceTest.php
```

Expected: at least one new test fails until every guard and hash field is
implemented.

- [ ] **Step 3: Complete the guards and canonical hash graph**

Use stable messages:

```php
throw new \LogicException('paper_canonical_execution_cost_identity_mismatch');
throw new \LogicException('paper_canonical_execution_cost_contract_mismatch');
throw new \LogicException('paper_canonical_execution_cost_book_invalid');
throw new \LogicException('paper_canonical_execution_cost_book_future');
throw new \LogicException('paper_canonical_execution_cost_book_stale');
```

Do not catch or rewrite the existing child-source reason codes for missing,
malformed funding, interval mismatch or stale trigger.

- [ ] **Step 4: Run focused and adjacent tests**

```bash
cd trading-app
./vendor/bin/phpunit \
  tests/Trading/Paper/Execution/Strategy/PaperCanonicalExecutionCostSourceTest.php \
  tests/Trading/Paper/Execution/Strategy/PaperCanonicalOrderBookSourceTest.php \
  tests/Trading/Paper/Execution/Strategy/PaperCanonicalFundingSourceTest.php \
  tests/TradingCore/OrderPlan/Canonical/CanonicalNetREngineTest.php \
  tests/Exchange/Fake/FakeFillCostModelTest.php
```

Expected: all tests pass with zero errors and failures.

- [ ] **Step 5: Commit the completed contract**

```bash
git add trading-app/src/Trading/Paper/Execution/Strategy/PaperCanonicalExecutionCostSource.php \
  trading-app/tests/Trading/Paper/Execution/Strategy/PaperCanonicalExecutionCostSourceTest.php
git commit -m "test(paper): enforce execution cost evidence"
```

### Task 4: Verify and deliver the atomic slice

**Files:**
- Verify: all files in this plan and the design spec.

- [ ] **Step 1: Run static and framework checks**

```bash
cd trading-app
./vendor/bin/phpstan analyse --memory-limit=1G \
  src/Trading/Paper/Execution/Strategy/PaperCanonicalExecutionCostSource.php \
  tests/Trading/Paper/Execution/Strategy/PaperCanonicalExecutionCostSourceTest.php
php bin/console lint:container
php bin/console lint:yaml config
cd ..
git diff --check origin/main...HEAD
```

Expected: every command exits zero.

- [ ] **Step 2: Run the broader Paper strategy suite**

```bash
cd trading-app
./vendor/bin/phpunit tests/Trading/Paper/Execution/Strategy tests/TradingCore/OrderPlan/Canonical
```

Expected: all tests pass with zero errors and failures.

- [ ] **Step 3: Review the exact diff**

```bash
git status --short --branch
git diff --stat origin/main...HEAD
git diff --check origin/main...HEAD
git log --oneline origin/main..HEAD
```

Expected: only the design, plan, source and focused tests are present; the
worktree is clean.

- [ ] **Step 4: Push and open a ready PR**

```bash
git push -u origin codex/issue-196-execution-cost-source
gh pr create --base main --head codex/issue-196-execution-cost-source \
  --title "feat(paper): compose canonical execution costs" \
  --body-file /tmp/issue-196-execution-cost-source-pr.md
```

The PR body must say `Part of #196`, list exact evidence and failure semantics,
state that the source remains unwired, and state that no private endpoint or
mainnet write is introduced.

- [ ] **Step 5: Complete the normal review/CI/merge loop**

Request one real Codex review if repository quota permits. Address every
actionable thread, rerun targeted verification, require all mandatory checks
green, confirm zero unresolved threads, then squash-merge and record the exact
merge SHA in #196. Do not manufacture extra review cycles when there is no
technical feedback.
