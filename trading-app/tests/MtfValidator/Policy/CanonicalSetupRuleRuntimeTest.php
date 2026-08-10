<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Policy;

use App\MtfValidator\Policy\CanonicalSetupRuleRuntime;
use App\MtfValidator\Policy\CanonicalSetupRuleRuntimeResult;
use App\Tests\Trading\Lineage\CanonicalSnapshotFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalSetupRuleRuntime::class)]
#[CoversClass(CanonicalSetupRuleRuntimeResult::class)]
final class CanonicalSetupRuleRuntimeTest extends TestCase
{
    public function testRealCanonicalSetupUsesStrictPlanAndNeverReportsLegacyFallback(): void
    {
        $now = new \DateTimeImmutable('2026-08-10T10:00:00+00:00');
        $result = (new CanonicalSetupRuleRuntime([]))->evaluate(
            CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config()),
            [
                '15m' => ['kline_time' => '2026-08-10T09:45:00+00:00'],
                '5m' => ['kline_time' => '2026-08-10T09:55:00+00:00'],
                '1m' => ['kline_time' => '2026-08-10T09:59:00+00:00'],
            ],
            $now,
        );

        self::assertFalse($result->passed);
        self::assertSame('compiled_plan_blocked', $result->reasonCode);
        self::assertSame('canonical-setup-rule-runtime.v1', $result->trace['schema_version']);
        self::assertSame('scalping.pullback.long', $result->trace['setup_id']);
        self::assertSame('1.0.0', $result->trace['setup_version']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result->trace['setup_hash']);
        self::assertArrayHasKey('regime', $result->trace['sections']);
        self::assertStringNotContainsString('fallback', json_encode($result->trace, JSON_THROW_ON_ERROR));
    }

    public function testLegacyAndCatalogMismatchRejectBeforeEvaluation(): void
    {
        $runtime = new CanonicalSetupRuleRuntime([]);
        $now = new \DateTimeImmutable('2026-08-10T10:00:00+00:00');

        self::assertSame('canonical_identity_required', $runtime->evaluate(
            \App\Trading\Lineage\LineageContext::legacy(),
            [],
            $now,
        )->reasonCode);
        self::assertSame('canonical_condition_catalog_mismatch', $runtime->evaluate(
            CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config(), str_repeat('b', 64)),
            [],
            $now,
        )->reasonCode);
    }

    public function testCompiledPlanCacheIsBoundToExactCatalogSetupAndConfigHashes(): void
    {
        $runtime = new CanonicalSetupRuleRuntime([]);
        $identity = CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config());
        $now = new \DateTimeImmutable('2026-08-10T10:00:00+00:00');
        $inputs = ['15m' => ['kline_time' => '2026-08-10T09:45:00+00:00']];

        $first = $runtime->evaluate($identity, $inputs, $now);
        $second = $runtime->evaluate($identity, $inputs, $now);

        self::assertFalse($first->trace['plan_cache_hit']);
        self::assertTrue($second->trace['plan_cache_hit']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first->trace['plan_cache_key']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first->trace['setup_hash']);
        self::assertSame($first->trace['plan_cache_key'], $second->trace['plan_cache_key']);
    }
}
