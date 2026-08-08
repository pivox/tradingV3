<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Policy;

use App\MtfValidator\Policy\CanonicalMtfPolicyPreflight;
use App\Trading\Lineage\LineageContext;
use App\Tests\Trading\Lineage\CanonicalSnapshotFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalMtfPolicyPreflight::class)]
final class CanonicalMtfPolicyPreflightTest extends TestCase
{
    public function testReturnsNullForLegacyLineage(): void
    {
        self::assertNull((new CanonicalMtfPolicyPreflight())->reject(LineageContext::legacy('BTCUSDT', 'fake', 'perpetual')));
    }

    public function testRejectsExecutableModernSnapshotWithOrderedRuntimePolicyBlockers(): void
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

    public function testRejectsNonExecutableModernSnapshotAtEffectiveConfigPath(): void
    {
        $payload = CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config())->toArray();
        $payload['effective_config_snapshot']['executable'] = false;
        $payload['effective_config_snapshot']['blockers'] = ['mode.status:draft'];

        $rejection = (new CanonicalMtfPolicyPreflight())->reject(LineageContext::fromArray($payload));

        self::assertSame('canonical_contract_not_executable', $rejection?->reason);
        self::assertSame([['code' => 'canonical_contract_not_executable', 'path' => 'effective_config_snapshot']], $rejection?->blockers);
    }
}
