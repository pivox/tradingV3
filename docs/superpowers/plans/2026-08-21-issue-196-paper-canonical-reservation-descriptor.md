# Paper Canonical Reservation Descriptor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Persist a strict, restart-safe canonical portfolio reservation descriptor on every modern Paper Fake order, protection and position.

**Architecture:** A dedicated immutable descriptor validates a complete `PaperCanonicalPreparedEffect`, encodes only canonical scalar evidence, and hashes its exact versioned payload. The dispatcher attaches it before submission; the existing Fake lineage propagation copies it to downstream private records, and idempotent replay verifies exact equality.

**Tech Stack:** PHP 8.4, PHPUnit 11, Symfony, Brick Math, canonical JSON, existing Fake/Paper runtime.

---

## File map

- Create `trading-app/src/Trading/Paper/Execution/Fake/PaperCanonicalFakeReservationDescriptor.php`: exact v1 descriptor construction, decoding, hashing and identity assertions.
- Create `trading-app/tests/Trading/Paper/Execution/Fake/PaperCanonicalFakeReservationDescriptorTest.php`: deterministic wire contract and fail-closed validation.
- Modify `trading-app/src/Trading/Paper/Execution/Fake/PaperCanonicalFakeEffectDispatcher.php`: attach and replay-check the descriptor before mutation.
- Modify `trading-app/src/Exchange/Fake/FakeExchangeMatchingEngine.php`: include the scalar descriptor in the trusted lineage propagation allowlist.
- Modify `trading-app/tests/Trading/Paper/Execution/Fake/PaperCanonicalFakeEffectDispatcherTest.php`: prove persistence, propagation and restart idempotence.

### Task 1: Specify and implement the immutable descriptor

**Files:**
- Create: `trading-app/tests/Trading/Paper/Execution/Fake/PaperCanonicalFakeReservationDescriptorTest.php`
- Create: `trading-app/src/Trading/Paper/Execution/Fake/PaperCanonicalFakeReservationDescriptor.php`

- [ ] **Step 1: Write the failing happy-path and round-trip tests**

Build an effect with `PaperCanonicalPreparedEffectCodecTest::fixture()`, reconstruct its exact modern cell from `effect->provenance`, and assert:

```php
$descriptor = PaperCanonicalFakeReservationDescriptor::fromEffect($cell, $effect);
$decoded = PaperCanonicalFakeReservationDescriptor::decode($descriptor->encoded());

self::assertSame($descriptor->encoded(), $decoded->encoded());
self::assertSame($cell->id, $decoded->cellId());
self::assertSame($effect->decisionKey, $decoded->decisionKey());
self::assertSame($effect->reservation->reservedRiskQuote, $decoded->reservedRiskQuote());
self::assertSame($effect->reservation->reservedNotionalQuote, $decoded->reservedNotionalQuote());
self::assertMatchesRegularExpression('/\\Asha256:[a-f0-9]{64}\\z/D', $decoded->identityHash());
$decoded->assertCell($cell)->assertEffect($effect);
```

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```bash
cd trading-app
php bin/phpunit tests/Trading/Paper/Execution/Fake/PaperCanonicalFakeReservationDescriptorTest.php
```

Expected: failure because `PaperCanonicalFakeReservationDescriptor` does not exist.

- [ ] **Step 3: Implement the exact v1 contract**

Create a final readonly class with this public surface:

```php
final readonly class PaperCanonicalFakeReservationDescriptor
{
    public const METADATA_KEY = 'paper_canonical_reservation_descriptor';

    public static function fromEffect(
        PaperExecutionCell $cell,
        PaperCanonicalPreparedEffect $effect,
    ): self;

    public static function decode(string $encoded): self;
    public function encoded(): string;
    public function identityHash(): string;
    public function cellId(): string;
    public function decisionKey(): string;
    public function reservedRiskQuote(): float;
    public function reservedNotionalQuote(): float;
    public function assertCell(PaperExecutionCell $cell): self;
    public function assertEffect(PaperCanonicalPreparedEffect $effect): self;
}
```

Use `CanonicalJson::encode()` and canonical decimal strings produced with
`CanonicalOrderPlanDecimal::fromFloat()->stripTrailingZeros()`. Require the
exact payload fields listed by the design plus `descriptor_hash`. Validate
hashes with `/\\Asha256:[a-f0-9]{64}\\z/D`, the modern cell identity, exact
scope equality, `reservation->assertCanonicalOpeningState($effect->plan)`,
`effect->assertValid()`, reservation version `1`, positive reserved values and
a canonical UTC-offset timestamp formatted as `Y-m-d\\TH:i:s.uP`. Convert every
failure to `LogicException('paper_canonical_fake_reservation_descriptor_invalid')`.

- [ ] **Step 4: Run the focused test and verify GREEN**

Run the Task 1 command. Expected: all tests pass.

- [ ] **Step 5: Commit the descriptor contract**

```bash
git add trading-app/src/Trading/Paper/Execution/Fake/PaperCanonicalFakeReservationDescriptor.php trading-app/tests/Trading/Paper/Execution/Fake/PaperCanonicalFakeReservationDescriptorTest.php
git commit -m "feat(paper): describe canonical Fake reservations"
```

### Task 2: Prove strict decoding and identity failures

**Files:**
- Modify: `trading-app/tests/Trading/Paper/Execution/Fake/PaperCanonicalFakeReservationDescriptorTest.php`
- Modify: `trading-app/src/Trading/Paper/Execution/Fake/PaperCanonicalFakeReservationDescriptor.php`

- [ ] **Step 1: Add failing negative tests**

Add cases that mutate one field without updating the hash, recompute a hash for
a non-canonical decimal, remove a required field, add an unknown field, use a
legacy cell, use a foreign modern cell and compare the descriptor to a different
prepared effect. Each case must reject with the single stable reason.

- [ ] **Step 2: Run the focused test and confirm the new cases fail**

Run the Task 1 command. Expected: the newly added negative cases expose missing
validation.

- [ ] **Step 3: Complete strict validation**

Sort and compare the exact field set, require canonical re-encoding equality,
validate semantic versions and bounded Paper identities, and make
`assertCell()`/`assertEffect()` compare exact hashes and scalar identities with
`hash_equals()` where applicable.

- [ ] **Step 4: Run the focused test and verify GREEN**

Run the Task 1 command. Expected: all descriptor cases pass.

- [ ] **Step 5: Commit fail-closed validation**

```bash
git add trading-app/src/Trading/Paper/Execution/Fake/PaperCanonicalFakeReservationDescriptor.php trading-app/tests/Trading/Paper/Execution/Fake/PaperCanonicalFakeReservationDescriptorTest.php
git commit -m "test(paper): reject forged reservation descriptors"
```

### Task 3: Propagate the descriptor through Fake execution

**Files:**
- Modify: `trading-app/src/Trading/Paper/Execution/Fake/PaperCanonicalFakeEffectDispatcher.php`
- Modify: `trading-app/src/Exchange/Fake/FakeExchangeMatchingEngine.php`
- Modify: `trading-app/tests/Trading/Paper/Execution/Fake/PaperCanonicalFakeEffectDispatcherTest.php`

- [ ] **Step 1: Write failing propagation and replay tests**

Extend the dispatcher tests to assert that a canonical dispatch stores
`PaperCanonicalFakeReservationDescriptor::METADATA_KEY`, that decoding it
matches the original effect, that a fill copies the same encoded value to the
position and attached protections, and that a runtime recreated from the same
private state accepts an identical dispatch as idempotent. Tampering the stored
descriptor before replay must fail closed and must not create another order.

- [ ] **Step 2: Run dispatcher tests and verify RED**

```bash
cd trading-app
php bin/phpunit tests/Trading/Paper/Execution/Fake/PaperCanonicalFakeEffectDispatcherTest.php
```

Expected: metadata is absent and replay does not yet validate it.

- [ ] **Step 3: Attach and propagate the descriptor**

In `dispatch()`, build the descriptor after scope/deadline/duplicate discovery
but before leverage or order mutation. Pass its encoded value into `request()`
and add it to request metadata. Add the metadata key to
`FakeExchangeMatchingEngine::LINEAGE_METADATA_KEYS` so all derived records
preserve the exact scalar string.

For existing-order replay, decode the persisted descriptor and call
`assertCell($runtime->cell)->assertEffect($effect)`. Return the existing stable
intent-mismatch rejection when it is absent or invalid; do not repair state or
fall back to plan inference.

- [ ] **Step 4: Run descriptor and dispatcher tests and verify GREEN**

```bash
cd trading-app
php bin/phpunit tests/Trading/Paper/Execution/Fake/PaperCanonicalFakeReservationDescriptorTest.php tests/Trading/Paper/Execution/Fake/PaperCanonicalFakeEffectDispatcherTest.php
```

Expected: all tests pass.

- [ ] **Step 5: Commit runtime propagation**

```bash
git add trading-app/src/Trading/Paper/Execution/Fake/PaperCanonicalFakeEffectDispatcher.php trading-app/src/Exchange/Fake/FakeExchangeMatchingEngine.php trading-app/tests/Trading/Paper/Execution/Fake/PaperCanonicalFakeEffectDispatcherTest.php
git commit -m "feat(paper): persist canonical Fake reservations"
```

### Task 4: Review and verify the slice

**Files:**
- Modify only files found defective by the checks above.

- [ ] **Step 1: Review the complete diff**

Run `git diff origin/main...HEAD` and inspect exact wire shape, exception
normalization, metadata propagation, legacy isolation, credential strings,
network clients and accidental tuning.

- [ ] **Step 2: Run the adjacent suite**

```bash
cd trading-app
php bin/phpunit tests/Trading/Paper/Execution/Fake tests/Trading/Paper/Execution/Strategy tests/Exchange/Adapter/FakeExchangeAdapterTest.php tests/Exchange/Fake/FakeInstrumentCatalogTest.php tests/Exchange/Fake/FakeLiquidationIntegrationTest.php
```

Expected: all tests pass; only repository-defined skips are allowed.

- [ ] **Step 3: Run static and configuration checks**

```bash
cd trading-app
vendor/bin/phpstan analyse --no-progress --memory-limit=1G src/Trading/Paper/Execution/Fake src/Exchange/Fake/FakeExchangeMatchingEngine.php tests/Trading/Paper/Execution/Fake
php bin/console lint:container
php bin/console lint:yaml config
git diff --check origin/main...HEAD
```

Expected: no errors.

- [ ] **Step 4: Commit any review correction**

Stage only the intended files and use a focused `fix(paper): ...` commit. If no
correction was needed, do not create an empty commit.

- [ ] **Step 5: Deliver through the established PR cycle**

Push the branch, open a ready PR referencing #196, monitor required checks,
address only real review threads, merge when clean and green, record the merge
in #196, synchronize `origin/main`, and continue with the aggregate private
portfolio source.
