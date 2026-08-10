<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Policy;

use App\MtfValidator\Policy\CanonicalRulePolicyPreflight;
use App\Tests\Trading\Lineage\CanonicalSnapshotFixture;
use App\Trading\Lineage\LineageContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalRulePolicyPreflight::class)]
final class CanonicalRulePolicyPreflightTest extends TestCase
{
    public function testLegacyHasNoCanonicalRuleBlocker(): void
    {
        self::assertSame([], (new CanonicalRulePolicyPreflight())->blockers(LineageContext::legacy()));
    }

    public function testExactModernIdentityCompilesButRetainsPublishedSetupBlockers(): void
    {
        $identity = CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config());
        $blockers = (new CanonicalRulePolicyPreflight())->blockers($identity);

        self::assertContains(['code' => 'canonical_setup_contract_not_executable', 'path' => 'setup.status'], $blockers);
        self::assertNotContains(['code' => 'canonical_condition_catalog_mismatch', 'path' => 'condition_catalog_hash'], $blockers);
    }

    public function testCatalogMismatchFailsClosed(): void
    {
        $mismatch = CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config(), str_repeat('b', 64));
        self::assertSame(
            [['code' => 'canonical_condition_catalog_mismatch', 'path' => 'condition_catalog_hash']],
            (new CanonicalRulePolicyPreflight())->blockers($mismatch),
        );
    }
}
