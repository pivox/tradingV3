# Canonical Runner Preflight Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reject every modern MTF request before exchange-capable or worker-capable runner activity, while preserving direct-core protection and the historical profile-less legacy default.

**Architecture:** A small stateless `CanonicalMtfPolicyPreflight` converts a modern `LineageContext` into one immutable rejection value and returns `null` for legacy lineage. `MtfRunnerService` consumes it at the top of `run()`, while `MtfValidatorCoreService` keeps the same guard as defense in depth; `RunnerController` resolves the first enabled legacy mode only when no canonical identity or explicit profile exists.

**Tech Stack:** PHP 8.2+, Symfony dependency injection and HTTP controller, PHPUnit 10, PHPStan, GitHub CLI.

---

### Task 1: Shared canonical preflight decision

**Files:**
- Create: `trading-app/src/MtfValidator/Policy/CanonicalMtfPolicyRejection.php`
- Create: `trading-app/src/MtfValidator/Policy/CanonicalMtfPolicyPreflight.php`
- Create: `trading-app/tests/MtfValidator/Policy/CanonicalMtfPolicyPreflightTest.php`

- [ ] **Step 1: Write tests for legacy lineage, ordered #304 blockers, and invalid snapshots**

```php
final class CanonicalMtfPolicyPreflightTest extends TestCase
{
    public function testLegacyLineageDoesNotReject(): void
    {
        self::assertNull((new CanonicalMtfPolicyPreflight())->reject(
            LineageContext::legacy(symbol: 'BTCUSDT', exchange: 'fake', marketType: 'perpetual', mtfProfile: 'scalper'),
        ));
    }

    public function testExecutableSnapshotPreservesOrderedRuntimeBlockers(): void
    {
        $rejection = (new CanonicalMtfPolicyPreflight())->reject(
            CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config()),
        );

        self::assertSame('canonical_risk_pct_pending_304', $rejection?->reason);
        self::assertSame([
            'canonical_risk_pct_pending_304',
            'canonical_daily_loss_policy_pending_304',
            'canonical_end_of_zone_fallback_pending_304',
            'canonical_max_concurrent_positions_pending_304',
            'canonical_mode_exposure_cap_pending_304',
            'canonical_minimum_net_r_pending_304',
        ], array_column($rejection?->blockers ?? [], 'code'));
    }

    public function testNonExecutableSnapshotKeepsTypedLineageFailure(): void
    {
        $payload = CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config())->toArray();
        $payload['effective_config_snapshot']['executable'] = false;
        $payload['effective_config_snapshot']['blockers'] = ['mode.status:draft'];

        $rejection = (new CanonicalMtfPolicyPreflight())->reject(LineageContext::fromOrchestratorPayload($payload));

        self::assertSame('canonical_contract_not_executable', $rejection?->reason);
        self::assertSame('effective_config_snapshot', $rejection?->blockers[0]['path'] ?? null);
    }
}
```

The implementation retains `canonical_mtf_evaluator_pending_303` after `assertReady()` for the future point where #304 can return no blockers. That branch is intentionally not forced with a test double in Lot 2A because the current final static #304 validator always emits unresolved policy blockers.

- [ ] **Step 2: Run the new test and verify RED**

Run:

```bash
cd trading-app
php vendor/bin/phpunit tests/MtfValidator/Policy/CanonicalMtfPolicyPreflightTest.php
```

Expected: FAIL because `CanonicalMtfPolicyPreflight` and `CanonicalMtfPolicyRejection` do not exist.

- [ ] **Step 3: Implement the immutable rejection and shared evaluator**

```php
<?php

declare(strict_types=1);

namespace App\MtfValidator\Policy;

final readonly class CanonicalMtfPolicyRejection
{
    /** @param list<array{code:string,path:string}> $blockers */
    public function __construct(
        public string $reason,
        public array $blockers,
    ) {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\MtfValidator\Policy;

use App\TradeEntry\Policy\CanonicalTradeRuntimePolicyValidator;
use App\Trading\Lineage\CanonicalRuntimePolicyException;
use App\Trading\Lineage\CanonicalTradeEntryConfigFactory;
use App\Trading\Lineage\LineageContext;
use App\Trading\Lineage\LineageContextException;

final class CanonicalMtfPolicyPreflight
{
    public function reject(LineageContext $identity): ?CanonicalMtfPolicyRejection
    {
        if (!$identity->isModern()) {
            return null;
        }

        try {
            $config = CanonicalTradeEntryConfigFactory::fromLineage($identity);
            CanonicalTradeRuntimePolicyValidator::assertReady($config);
            $blockers = [[
                'code' => 'canonical_mtf_evaluator_pending_303',
                'path' => 'runtime.mtf.condition_evaluator',
            ]];
        } catch (CanonicalRuntimePolicyException $exception) {
            $blockers = $exception->blockers;
        } catch (LineageContextException $exception) {
            $blockers = [[
                'code' => $exception->getMessage(),
                'path' => 'effective_config_snapshot',
            ]];
        }

        return new CanonicalMtfPolicyRejection($blockers[0]['code'], $blockers);
    }
}
```

- [ ] **Step 4: Run the policy tests and verify GREEN**

Run the command from Step 2.

Expected: all `CanonicalMtfPolicyPreflightTest` tests PASS.

- [ ] **Step 5: Commit the shared policy**

```bash
git add trading-app/src/MtfValidator/Policy trading-app/tests/MtfValidator/Policy
git commit -m "refactor(mtf): centralize canonical preflight"
```

### Task 2: Reject modern requests at the runner boundary

**Files:**
- Create: `trading-app/tests/MtfRunner/Service/MtfRunnerServiceCanonicalPreflightTest.php`
- Modify: `trading-app/src/MtfRunner/Service/MtfRunnerService.php`
- Modify: `trading-app/tests/MtfRunner/Service/MtfRunnerServiceSyncTablesTest.php`
- Modify: `trading-app/tests/Controller/RunnerControllerTest.php`

- [ ] **Step 1: Write runner tests proving no peripheral work is reachable**

Build `MtfRunnerServiceCanonicalPreflightTest` with leaf mocks/counters following `MtfRunnerServiceSyncTablesTest::buildService()`. Supply a modern `MtfRunnerRequestDto` with the canonical fixture, `workers: 8`, `syncTables: true`, `skipOpenStateFilter: false`, and `processTpSl: true`. Assert:

```php
self::assertSame('rejected', $result['summary']['status']);
self::assertSame('canonical_policy_rejected', $result['summary']['canonical_status']);
self::assertSame('canonical_risk_pct_pending_304', $result['summary']['reason']);
self::assertSame($expectedBlockers, $result['summary']['canonical_policy_blockers']);
self::assertSame($identity->redacted(), $result['summary']['lineage']);
self::assertSame(0, $contextCalls);
self::assertSame(0, $symbolResolutionCalls);
self::assertSame(0, $syncCalls);
self::assertSame(0, $filterCalls);
self::assertSame(0, $validatorCalls);
self::assertSame(0, $projectionDispatches);
self::assertSame(0, $tpSlCalls);
```

Use expectations on the real collaborators' leaf interfaces to prove no provider or message-bus access. The `workers: 8` assertion is the regression proof that `runParallel()` cannot spawn `mtf:run-worker` for a blocked modern request. Add a second request with a non-executable snapshot and assert `canonical_contract_not_executable`.

- [ ] **Step 2: Run the runner test and verify RED**

Run:

```bash
cd trading-app
php vendor/bin/phpunit tests/MtfRunner/Service/MtfRunnerServiceCanonicalPreflightTest.php
```

Expected: FAIL because the current runner reaches context/symbol/provider setup instead of returning the canonical rejection.

- [ ] **Step 3: Inject the preflight and return before runner peripherals**

Add `CanonicalMtfPolicyPreflight` and `AuditLoggerInterface` to the `MtfRunnerService` constructor. Immediately after run-id creation, before the start log and the open-state guard, evaluate `$request->lineageContext`:

```php
$canonicalRejection = $this->canonicalMtfPolicyPreflight->reject($request->lineageContext);
if ($canonicalRejection !== null) {
    $identity = $request->lineageContext;
    $context = [
        'run_id' => $runId,
        'reason' => $canonicalRejection->reason,
        'blockers' => $canonicalRejection->blockers,
        'identity' => $identity->redacted(),
    ];
    $this->mtfLogger->warning('mtf.runner.canonical_policy_rejected', $context);
    $this->auditLogger->logAction(
        action: 'MTF_CANONICAL_POLICY_REJECTED',
        entity: 'MTF_RUN',
        entityId: $runId,
        data: $context,
        userId: $request->userId,
        ipAddress: $request->ipAddress,
    );

    return $this->buildRejectedRun(
        runId: $runId,
        message: 'Canonical MTF request rejected by runtime policy.',
        reason: $canonicalRejection->reason,
        symbolsRequested: count($request->symbols),
        extraSummary: [
            'canonical_status' => 'canonical_policy_rejected',
            'canonical_policy_blockers' => $canonicalRejection->blockers,
            'lineage' => $identity->redacted(),
        ],
    );
}
```

Extend `buildRejectedRun()` with `array $extraSummary = []` and merge it after the fixed summary keys so the canonical metadata is returned without altering existing rejection callers:

```php
'summary' => [
    'run_id' => $runId,
    'status' => 'rejected',
    'reason' => $reason,
    'message' => $message,
    'symbols_requested' => $symbolsRequested,
    'symbols_processed' => 0,
    'timestamp' => date('Y-m-d H:i:s'),
] + $extraSummary,
```

Update every direct `new MtfRunnerService(...)` in the focused test files with the preflight and audit mock.

- [ ] **Step 4: Run focused runner/controller tests and verify GREEN**

Run:

```bash
cd trading-app
php vendor/bin/phpunit \
  tests/MtfRunner/Service/MtfRunnerServiceCanonicalPreflightTest.php \
  tests/MtfRunner/Service/MtfRunnerServiceSyncTablesTest.php \
  tests/Controller/RunnerControllerTest.php
```

Expected: all focused tests PASS and existing legacy runner behavior remains unchanged.

- [ ] **Step 5: Commit the runner boundary**

```bash
git add trading-app/src/MtfRunner/Service/MtfRunnerService.php \
  trading-app/tests/MtfRunner/Service/MtfRunnerServiceCanonicalPreflightTest.php \
  trading-app/tests/MtfRunner/Service/MtfRunnerServiceSyncTablesTest.php \
  trading-app/tests/Controller/RunnerControllerTest.php
git commit -m "fix(mtf): preflight modern runs before exchange work"
```

### Task 3: Make the core guard consume the shared preflight

**Files:**
- Modify: `trading-app/src/MtfValidator/Service/MtfValidatorCoreService.php`
- Modify: `trading-app/tests/MtfValidator/Service/MtfValidatorCoreCanonicalRejectionTest.php`

- [ ] **Step 1: Extend the core test to assert the shared rejection contract**

Inject a real `CanonicalMtfPolicyPreflight` in the test service factory and assert both canonical cases keep:

```php
self::assertFalse($result->isTradable);
self::assertSame($expectedReason, $result->finalReason);
self::assertSame('canonical_policy_rejected', $result->extra['canonical_status']);
self::assertSame($expectedBlockers, $result->extra['canonical_policy_blockers']);
```

- [ ] **Step 2: Temporarily switch the constructor to the new dependency and verify RED**

Run:

```bash
cd trading-app
php vendor/bin/phpunit tests/MtfValidator/Service/MtfValidatorCoreCanonicalRejectionTest.php
```

Expected: FAIL until `rejectBlockedCanonicalRun()` uses the injected service.

- [ ] **Step 3: Replace duplicated exception mapping with the shared decision**

Inject `CanonicalMtfPolicyPreflight` and replace the factory/validator/catch block with:

```php
$identity = $input->lineageContext;
if ($identity === null) {
    return null;
}
$rejection = $this->canonicalMtfPolicyPreflight->reject($identity);
if ($rejection === null) {
    return null;
}
$blockers = $rejection->blockers;
$reason = $rejection->reason;
```

Keep the existing core warning, empty `MtfResultDto`, and `MTF_CANONICAL_POLICY_REJECTED` audit unchanged.

- [ ] **Step 4: Run the shared-policy and core tests and verify GREEN**

Run:

```bash
cd trading-app
php vendor/bin/phpunit \
  tests/MtfValidator/Policy/CanonicalMtfPolicyPreflightTest.php \
  tests/MtfValidator/Service/MtfValidatorCoreCanonicalRejectionTest.php
```

Expected: all tests PASS with identical blocker ordering at runner and core boundaries.

- [ ] **Step 5: Commit the core reuse**

```bash
git add trading-app/src/MtfValidator/Service/MtfValidatorCoreService.php \
  trading-app/tests/MtfValidator/Service/MtfValidatorCoreCanonicalRejectionTest.php
git commit -m "refactor(mtf): reuse canonical preflight in core"
```

### Task 4: Restore the profile-less legacy HTTP default

**Files:**
- Modify: `trading-app/src/Controller/RunnerController.php`
- Modify: `trading-app/tests/Controller/RunnerControllerTest.php`

- [ ] **Step 1: Write three controller routing tests**

Use a validator spy to capture `MtfRunRequestDto::profile`. Configure `TradeEntryModeContext` with enabled modes ordered as `scalper`, then `regular`. Assert:

```php
self::assertSame('scalper', $profilelessLegacyRequest->profile);
self::assertSame('regular', $explicitLegacyRequest->profile);
self::assertSame('scalping', $canonicalRequest->profile);
```

The profile-less payload omits `trading_identity`, `profile`, and `mtf_profile`; the explicit legacy payload sends `mtf_profile: regular`; the canonical payload sends the canonical identity fixture and no legacy profile.

- [ ] **Step 2: Run the controller tests and verify RED**

Run:

```bash
cd trading-app
php vendor/bin/phpunit tests/Controller/RunnerControllerTest.php
```

Expected: the profile-less legacy assertion FAILS because the controller currently passes `null`.

- [ ] **Step 3: Restore defaulting only on the legacy path**

Inject `TradeEntryModeContext` into `RunnerController`. Resolve the profile before constructing the runner DTO:

```php
$tradingIdentity = is_array($data['trading_identity'] ?? null) ? $data['trading_identity'] : null;
$explicitLegacyProfile = $data['profile'] ?? $data['mtf_profile'] ?? null;
$resolvedProfile = $tradingIdentity['mode_id'] ?? $explicitLegacyProfile;

if (
    $tradingIdentity === null
    && !array_key_exists('profile', $data)
    && !array_key_exists('mtf_profile', $data)
) {
    $enabledModes = $this->modeContext->getEnabledModes();
    $resolvedProfile = $enabledModes[0]['name'] ?? null;
}
```

Pass `$resolvedProfile` as `profile`. Do not copy it into `trading_identity`, and do not alias canonical mode IDs.

- [ ] **Step 4: Run controller and DTO tests and verify GREEN**

Run:

```bash
cd trading-app
php vendor/bin/phpunit \
  tests/Controller/RunnerControllerTest.php \
  tests/MtfRunner/Dto/MtfRunnerRequestDtoTest.php \
  tests/Contract/Runner/Dto/MtfRunnerRequestDtoTest.php
```

Expected: all tests PASS.

- [ ] **Step 5: Commit the compatibility fix**

```bash
git add trading-app/src/Controller/RunnerController.php trading-app/tests/Controller/RunnerControllerTest.php
git commit -m "fix(mtf): restore legacy enabled-profile default"
```

### Task 5: Full verification, review closure, and merge readiness

**Files:**
- Verify all files changed in Tasks 1–4
- Update: PR #332 review threads and status through GitHub

- [ ] **Step 1: Run focused behavior tests**

```bash
cd trading-app
php vendor/bin/phpunit \
  tests/MtfValidator/Policy/CanonicalMtfPolicyPreflightTest.php \
  tests/MtfValidator/Service/MtfValidatorCoreCanonicalRejectionTest.php \
  tests/MtfRunner/Service/MtfRunnerServiceCanonicalPreflightTest.php \
  tests/MtfRunner/Service/MtfRunnerServiceSyncTablesTest.php \
  tests/Controller/RunnerControllerTest.php \
  tests/MtfRunner/Dto/MtfRunnerRequestDtoTest.php \
  tests/Contract/Runner/Dto/MtfRunnerRequestDtoTest.php
```

Expected: PASS with zero failures and zero errors.

- [ ] **Step 2: Run static and syntax checks**

```bash
cd trading-app
php vendor/bin/phpstan analyse --no-progress \
  src/MtfValidator/Policy \
  src/MtfValidator/Service/MtfValidatorCoreService.php \
  src/MtfRunner/Service/MtfRunnerService.php \
  src/Controller/RunnerController.php \
  tests/MtfValidator/Policy \
  tests/MtfValidator/Service/MtfValidatorCoreCanonicalRejectionTest.php \
  tests/MtfRunner/Service/MtfRunnerServiceCanonicalPreflightTest.php \
  tests/Controller/RunnerControllerTest.php
php -l src/MtfValidator/Policy/CanonicalMtfPolicyRejection.php
php -l src/MtfValidator/Policy/CanonicalMtfPolicyPreflight.php
php -l src/MtfValidator/Service/MtfValidatorCoreService.php
php -l src/MtfRunner/Service/MtfRunnerService.php
php -l src/Controller/RunnerController.php
cd ..
git diff --check
```

Expected: PHPStan reports no errors, every lint reports no syntax errors, and `git diff --check` is silent.

- [ ] **Step 3: Push and close the three cycle-2 review threads with evidence**

```bash
git push origin codex/paper-132-modern-contracts
```

Reply to inline comments `3740178405`, `3740178407`, and `3740178408` with the exact tests and commit references. Resolve GraphQL threads `PRRT_kwDOPH0yO86Xcx2x`, `PRRT_kwDOPH0yO86Xcx2y`, and `PRRT_kwDOPH0yO86Xcx2z` only after the replies are posted.

- [ ] **Step 4: Wait for required checks and perform review cycle 3**

Wait until all PR checks are green, post the exact comment `@codex review`, and wait until the review reaction disappears and the resulting threads or approval are visible. Address every actionable blocking finding with a failing regression test, a minimal fix, verification, push, reply, and thread resolution.

- [ ] **Step 5: Merge only from a clean, review-complete state**

Confirm PR #332 is Ready, mergeable, green, has no unresolved review threads, and has no review in progress. Merge with the repository's established merge method, fetch `origin/main`, and leave the user's dirty original worktree untouched.
