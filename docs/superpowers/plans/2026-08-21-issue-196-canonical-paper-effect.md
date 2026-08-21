# #196 Canonical Paper Effect Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a strict durable codec for modern canonical Paper decisions without converting canonical plans or reservations through the legacy TradeEntry model.

**Architecture:** Keep legacy prepared effects unchanged. Authenticate modern Paper provenance as a distinct all-or-none v2 shape, reconstruct the initial portfolio reservation from its admission proof and lineage-derived policy, then encode plan, proof, lineage, durable intent identity, and provenance in a checksummed canonical envelope. Modern replay remains blocked until a later strategy and Fake-dispatch integration.

**Tech Stack:** PHP 8.4, Symfony 7, PHPUnit 11, PHPStan, canonical Paper JSON, TradingCore canonical plan/portfolio/lineage contracts.

---

## File map

- Modify `trading-app/src/TradingCore/Risk/Canonical/Portfolio/CanonicalPortfolioAdmissionProof.php`: expose verified reconstruction of the initial reservation.
- Modify `trading-app/tests/TradingCore/Risk/Canonical/Portfolio/CanonicalPortfolioAdmissionEngineTest.php`: reconstruction and mismatch tests.
- Modify `trading-app/src/Trading/Paper/Execution/Identity/PaperModernStrategyIdentity.php`: strict durable identity rehydration.
- Modify `trading-app/src/Trading/Paper/Execution/Identity/PaperExecutionCell.php`: emit modern v2 provenance while preserving legacy bytes.
- Modify `trading-app/src/Trading/Paper/Execution/Persistence/PaperExecutionProvenance.php`: validate exact legacy or modern provenance shapes.
- Create `trading-app/tests/Trading/Paper/Execution/Persistence/PaperModernExecutionProvenanceTest.php`: v2 round-trip and mixed-shape rejection.
- Create `trading-app/src/Trading/Paper/Execution/Strategy/PaperCanonicalPreparedEffect.php`: validated modern durable effect value.
- Create `trading-app/src/Trading/Paper/Execution/Strategy/PaperCanonicalPreparedEffectCodec.php`: strict versioned codec.
- Create `trading-app/tests/Trading/Paper/Execution/Strategy/PaperCanonicalPreparedEffectCodecTest.php`: round-trip, cross-binding, tamper, and dependency tests.
- Modify `docs/handbook/technical/paper-market-data-datasets.md`: document the non-runnable durable boundary.

## Task 1: Reconstruct the authenticated opening reservation

**Files:**
- Modify: `trading-app/src/TradingCore/Risk/Canonical/Portfolio/CanonicalPortfolioAdmissionProof.php`
- Modify: `trading-app/tests/TradingCore/Risk/Canonical/Portfolio/CanonicalPortfolioAdmissionEngineTest.php`

- [ ] **Step 1: Write the failing reconstruction test**

Build an accepted canonical plan, policy, scope, snapshot, and admission request. Assert the wished-for API recreates the exact opening reservation and state hash:

```php
$proof = CanonicalPortfolioAdmissionProof::fromRequest($request);
$actual = $proof->openReservation($plan, $policy);

self::assertEquals($expectedReservation, $actual);
self::assertSame($expectedReservation->stateHash, $actual->stateHash);
self::assertSame($actual->expectedStateHash(), $actual->stateHash);
```

Add cases for a policy from another config and a plan with another decision/scope identity; both must throw the existing admission-proof mismatch reason.

- [ ] **Step 2: Run the test and verify RED**

```bash
cd trading-app
XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/TradingCore/Risk/Canonical/Portfolio/CanonicalPortfolioAdmissionEngineTest.php
```

Expected: failure because `openReservation()` does not exist.

- [ ] **Step 3: Implement the minimal verified reconstruction API**

Extract the reconstruction already embedded in `verify()`:

```php
public function openReservation(
    CanonicalOrderPlan $plan,
    CanonicalPortfolioPolicy $expectedPolicy,
): CanonicalPortfolioReservation {
    if ($this->policy->toAdmissionProofArray() !== $expectedPolicy->toAdmissionProofArray()) {
        throw new CanonicalPortfolioException('canonical_portfolio_admission_proof_policy_mismatch');
    }

    $request = new CanonicalPortfolioAdmissionRequest(
        $expectedPolicy,
        $plan,
        $this->scope,
        $this->snapshot,
        $this->decisionKey,
    );
    $decision = (new CanonicalPortfolioAdmissionEngine(new MockClock($plan->createdAt)))
        ->admit($request);
    $reservation = CanonicalPortfolioReservation::open($decision, $plan);
    $reservation->assertCanonicalOpeningState($plan);

    return $reservation;
}
```

Keep the established stable verification reason by wrapping reconstruction failures, and make `verify()` call this method before comparing the supplied reservation.

- [ ] **Step 4: Run the focused portfolio tests and verify GREEN**

```bash
XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/TradingCore/Risk/Canonical/Portfolio/CanonicalPortfolioAdmissionEngineTest.php
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/TradingCore/Risk/Canonical/Portfolio/CanonicalPortfolioAdmissionProof.php \
  trading-app/tests/TradingCore/Risk/Canonical/Portfolio/CanonicalPortfolioAdmissionEngineTest.php
git commit -m "feat(trading-core): rehydrate canonical admission state"
```

## Task 2: Add exact modern Paper provenance

**Files:**
- Modify: `trading-app/src/Trading/Paper/Execution/Identity/PaperModernStrategyIdentity.php`
- Modify: `trading-app/src/Trading/Paper/Execution/Identity/PaperExecutionCell.php`
- Modify: `trading-app/src/Trading/Paper/Execution/Persistence/PaperExecutionProvenance.php`
- Create: `trading-app/tests/Trading/Paper/Execution/Persistence/PaperModernExecutionProvenanceTest.php`

- [ ] **Step 1: Write failing v2 provenance tests**

Resolve a real Paper effective config, construct its modern identity and v2 cell, then require `provenance()` to return the legacy eight fields followed by:

```php
[
    'mode_id', 'mode_version', 'setup_id', 'setup_version', 'side',
    'config_hash', 'condition_catalog_hash',
]
```

Assert `PaperExecutionProvenance::validate()` returns the byte-identical array. Mutate every modern identity field in turn, remove one, add an unknown key, reorder keys, or mix modern fields into legacy provenance; every case must fail with `paper_execution_provenance_invalid`. Re-run an existing legacy fixture and assert its exact array remains unchanged.

- [ ] **Step 2: Run tests and verify RED**

```bash
XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Trading/Paper/Execution/Persistence/PaperModernExecutionProvenanceTest.php \
  tests/Trading/Paper/Execution/Identity/PaperExecutionCellTest.php
```

Expected: modern `PaperExecutionCell::provenance()` still throws the bridge blocker.

- [ ] **Step 3: Implement strict v2 rehydration and validation**

Add a named durable rehydration factory to `PaperModernStrategyIdentity` that validates network/venue, identifiers, semantic versions, lowercase side, and SHA-256 hashes. It must not resolve aliases or configuration.

Change `PaperExecutionCell::provenance()` so legacy follows the current registry branch exactly, while modern returns the existing fields plus the seven exact identity fields from `modernIdentity`.

In `PaperExecutionProvenance`, retain `KEYS` as the legacy contract and add `MODERN_KEYS`. Select a shape only when its exact ordered keys match. For modern input, rehydrate the identity and cell and compare the complete v2 cell digest. Require `exchange=fake`; require legacy eligibility to match the legacy registry; parse but do not promote modern eligibility.

- [ ] **Step 4: Run provenance and identity regressions**

```bash
XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Trading/Paper/Execution/Persistence/PaperModernExecutionProvenanceTest.php \
  tests/Trading/Paper/Execution/Identity \
  tests/Trading/Paper/Execution/Persistence/PaperTradeProvenancePropagationTest.php
```

Expected: all tests pass and legacy provenance remains unchanged.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Trading/Paper/Execution/Identity \
  trading-app/src/Trading/Paper/Execution/Persistence/PaperExecutionProvenance.php \
  trading-app/tests/Trading/Paper/Execution
git commit -m "feat(paper): authenticate modern cell provenance"
```

## Task 3: Add the strict canonical prepared-effect codec

**Files:**
- Create: `trading-app/src/Trading/Paper/Execution/Strategy/PaperCanonicalPreparedEffect.php`
- Create: `trading-app/src/Trading/Paper/Execution/Strategy/PaperCanonicalPreparedEffectCodec.php`
- Create: `trading-app/tests/Trading/Paper/Execution/Strategy/PaperCanonicalPreparedEffectCodecTest.php`

- [ ] **Step 1: Write the failing round-trip and tamper tests**

Construct a real Paper-capability effective snapshot, modern lineage, canonical plan, admission proof, reservation, v2 provenance, and durable intent identity. Require:

```php
$encoded = $codec->encode($effect);
$decoded = $codec->decode($encoded);

self::assertSame($effect->plan->toArray(), $decoded->plan->toArray());
self::assertSame($effect->reservation->stateHash, $decoded->reservation->stateHash);
self::assertSame($effect->lineage->toArray(), $decoded->lineage->toArray());
self::assertSame($effect->provenance, $decoded->provenance);
```

Mutate checksum, schema, plan hash, proof policy, lineage snapshot/hash, decision key, timeframe, intent identity, provenance cell, network, venue, mode/setup version, side, config hash, and field order. Each decode must throw only `paper_canonical_prepared_effect_payload_invalid`.

Add a source dependency assertion that neither new production class imports `PreparedTradeEntry` nor `OrderPlanModel`.

- [ ] **Step 2: Run tests and verify RED**

```bash
XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Trading/Paper/Execution/Strategy/PaperCanonicalPreparedEffectCodecTest.php
```

Expected: class-not-found failures.

- [ ] **Step 3: Implement the effect value and exact codec**

Use this value boundary:

```php
final readonly class PaperCanonicalPreparedEffect
{
    public function __construct(
        public CanonicalOrderPlan $plan,
        public CanonicalPortfolioAdmissionProof $admissionProof,
        public CanonicalPortfolioReservation $reservation,
        public LineageContext $lineage,
        public string $decisionKey,
        public string $executionTimeframe,
        public array $orderIntentIdentity,
        public array $provenance,
    ) {}
}
```

The constructor validates modern/executable lineage, plan hash, reservation opening state, proof/policy verification, decision identity, plan identity against lineage, v2 provenance, `exchange=fake`, public venue equality, environment/network equality, cell/hash identity, exact intent key order, and allowed timeframe.

The codec envelope is exactly:

```php
[
    'schema_version' => 'paper-canonical-prepared-effect.v1',
    'payload' => [
        'plan' => $effect->plan->toArray(),
        'admission_proof' => $effect->admissionProof->toArray(),
        'lineage' => $effect->lineage->toArray(),
        'decision_key' => $effect->decisionKey,
        'execution_timeframe' => $effect->executionTimeframe,
        'order_intent_identity' => $effect->orderIntentIdentity,
        'cell_provenance' => $effect->provenance,
    ],
    'payload_checksum' => hash('sha256', CanonicalJson::encode($payload)),
]
```

Decode uses `CanonicalOrderPlan::fromArray()`, `CanonicalPortfolioAdmissionProof::fromArray()`, and `LineageContext::fromArray()`, compiles the portfolio policy with `CanonicalPortfolioPolicy::fromLineageSnapshot()`, reconstructs the reservation with `openReservation()`, and passes everything through the value constructor. Catch every lower-level failure and expose only the stable Paper codec reason.

- [ ] **Step 4: Run focused codec and canonical contract regressions**

```bash
XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Trading/Paper/Execution/Strategy \
  tests/TradingCore/OrderPlan/Canonical/CanonicalOrderPlanRehydrationTest.php \
  tests/TradingCore/Risk/Canonical/Portfolio/CanonicalPortfolioAdmissionEngineTest.php
```

Expected: all tests pass; the legacy codec tests remain green.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Trading/Paper/Execution/Strategy \
  trading-app/tests/Trading/Paper/Execution/Strategy
git commit -m "feat(paper): encode canonical durable effects"
```

## Task 4: Document and verify the non-runnable boundary

**Files:**
- Modify: `docs/handbook/technical/paper-market-data-datasets.md`

- [ ] **Step 1: Document the v2 durable effect and remaining blocker**

State that plan venue is the verified public/config venue, execution target is Fake, the admission proof recreates rather than serializes reservation state, and modern replay remains blocked until strategy assembly, durable intent reservation, Fake dispatch, recovery, and partial-fill transitions share this contract.

- [ ] **Step 2: Run the complete targeted verification gate**

```bash
cd trading-app
XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Trading/Paper/Execution \
  tests/Trading/Paper/Runtime \
  tests/Command/PaperExecutionReplayCommandTest.php \
  tests/Command/PaperReplayRuntimeCheckCommandTest.php \
  tests/TradingCore/Risk/Canonical/Portfolio \
  tests/TradingCore/OrderPlan/Canonical/CanonicalOrderPlanRehydrationTest.php
XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpstan analyse \
  src/Trading/Paper/Execution \
  src/TradingCore/Risk/Canonical/Portfolio \
  tests/Trading/Paper/Execution/Strategy/PaperCanonicalPreparedEffectCodecTest.php
APP_ENV=test APP_DEBUG=0 DATABASE_URL=sqlite:///:memory: DEFAULT_URI=http://localhost \
  php bin/console lint:container --no-debug
cd ..
git diff --check
```

Expected: zero failures/errors, PHPStan errors, or container wiring errors. Existing PostgreSQL-only skips remain expected.

- [ ] **Step 3: Run explicit safety and scope scans**

```bash
rg -n "OrderPlanModel|PreparedTradeEntry|Bitmart|private.*client|mainnet.*write" \
  trading-app/src/Trading/Paper/Execution/Strategy/PaperCanonicalPreparedEffect*.php
git status --short
```

Expected: the source scan returns no matches and status lists only the planned files.

- [ ] **Step 4: Commit documentation**

```bash
git add docs/handbook/technical/paper-market-data-datasets.md
git commit -m "docs(paper): describe canonical effect boundary"
```

- [ ] **Step 5: Prepare the PR**

Push the branch, open a draft PR linked to #196, then mark ready after local verification. Request one Codex review, address every actionable thread, and merge once current-HEAD checks and review are clear. The PR must explicitly state that modern readiness remains blocked and that it creates no certified trades.
