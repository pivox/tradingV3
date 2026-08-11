<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Service;

use App\Config\MtfValidationConfigProvider;
use App\Contract\Indicator\IndicatorProviderInterface;
use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\Runtime\AuditLoggerInterface;
use App\MtfValidator\Policy\CanonicalMtfPolicyPreflight;
use App\MtfValidator\Service\ContextValidationService;
use App\MtfValidator\Service\ExecutionSelectionService;
use App\MtfValidator\Service\MtfTimeframeResolver;
use App\MtfValidator\Service\MtfValidatorCoreService;
use App\Tests\Trading\Lineage\CanonicalSnapshotFixture;
use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\Trading\Lineage\LineageContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

#[CoversClass(MtfValidatorCoreService::class)]
final class MtfValidatorCoreCanonicalRejectionTest extends TestCase
{
    public function testModernRunRejectsCanonicalPolicyBeforeLegacyConfigOrProviders(): void
    {
        $indicatorProvider = $this->createMock(IndicatorProviderInterface::class);
        $indicatorProvider->expects(self::never())->method(self::anything());
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects(self::once())->method('logAction')->with(
            'MTF_CANONICAL_POLICY_REJECTED',
            'MTF_VALIDATION',
            'BTCUSDT',
            self::callback(static fn (array $data): bool =>
                ($data['final_reason'] ?? null) === 'canonical_risk_pct_pending_304'
                && array_column($data['extra']['canonical_policy_blockers'] ?? [], 'code') === [
                    'canonical_risk_pct_pending_304',
                    'canonical_daily_loss_policy_pending_304',
                    'canonical_end_of_zone_fallback_pending_304',
                    'canonical_max_concurrent_positions_pending_304',
                    'canonical_mode_exposure_cap_pending_304',
                    'canonical_minimum_net_r_pending_304',
                ],
            ),
        );
        $clock = new MockClock('2026-08-08T06:00:00+00:00');
        $service = $this->service($indicatorProvider, $audit, $clock);

        $result = $service->validate(new MtfRunDto(
            symbol: 'BTCUSDT',
            profile: 'scalping',
            now: $clock->now(),
            dryRun: true,
            options: ['exchange' => 'fake', 'market_type' => 'perpetual'],
            lineageContext: CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config()),
        ));

        self::assertFalse($result->isTradable);
        self::assertSame('canonical_risk_pct_pending_304', $result->finalReason);
        self::assertSame('canonical_policy_rejected', $result->extra['canonical_status'] ?? null);
        self::assertSame([
            ['code' => 'canonical_risk_pct_pending_304', 'path' => 'runtime.trade_entry.risk_pct'],
            ['code' => 'canonical_daily_loss_policy_pending_304', 'path' => 'mode.risk.daily_loss_cap'],
            ['code' => 'canonical_end_of_zone_fallback_pending_304', 'path' => 'runtime.trade_entry.fallback_end_of_zone'],
            ['code' => 'canonical_max_concurrent_positions_pending_304', 'path' => 'mode.risk.max_concurrent_positions'],
            ['code' => 'canonical_mode_exposure_cap_pending_304', 'path' => 'mode.risk.mode_exposure_cap'],
            ['code' => 'canonical_minimum_net_r_pending_304', 'path' => 'setup.ast.execution.minimum_net_r'],
        ], $result->extra['canonical_policy_blockers'] ?? null);
    }

    public function testNonExecutableCanonicalSnapshotIsAStableRejectionBeforeLegacyConfig(): void
    {
        $payload = CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config())->toArray();
        $payload['effective_config_snapshot']['executable'] = false;
        $payload['effective_config_snapshot']['blockers'] = ['mode.status:draft'];
        $payload['effective_config_snapshot']['snapshot_hash'] = CanonicalEffectiveConfigSnapshot::calculateSnapshotHash(
            $payload['effective_config_snapshot'],
        );
        $identity = LineageContext::fromOrchestratorPayload($payload);
        $indicatorProvider = $this->createMock(IndicatorProviderInterface::class);
        $indicatorProvider->expects(self::never())->method(self::anything());
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects(self::once())->method('logAction');
        $clock = new MockClock('2026-08-08T06:00:00+00:00');

        $result = $this->service($indicatorProvider, $audit, $clock)->validate(new MtfRunDto(
            symbol: 'BTCUSDT',
            profile: 'scalping',
            now: $clock->now(),
            dryRun: true,
            options: ['exchange' => 'fake', 'market_type' => 'perpetual'],
            lineageContext: $identity,
        ));

        self::assertFalse($result->isTradable);
        self::assertSame('canonical_contract_not_executable', $result->finalReason);
        self::assertSame('canonical_policy_rejected', $result->extra['canonical_status'] ?? null);
        self::assertSame([
            ['code' => 'canonical_contract_not_executable', 'path' => 'effective_config_snapshot'],
        ], $result->extra['canonical_policy_blockers'] ?? null);
    }

    private function service(
        IndicatorProviderInterface $indicatorProvider,
        AuditLoggerInterface $audit,
        MockClock $clock,
    ): MtfValidatorCoreService {
        $contextValidation = $this->createMock(ContextValidationService::class);
        $contextValidation->expects(self::never())->method(self::anything());
        $executionSelection = $this->createMock(ExecutionSelectionService::class);
        $executionSelection->expects(self::never())->method(self::anything());

        return new MtfValidatorCoreService(
            new CanonicalMtfPolicyPreflight(),
            new MtfValidationConfigProvider(new ParameterBag([
                'kernel.project_dir' => dirname(__DIR__, 3),
                'mode' => [],
            ])),
            $indicatorProvider,
            $contextValidation,
            $executionSelection,
            $audit,
            $clock,
            new NullLogger(),
            new MtfTimeframeResolver(),
        );
    }
}
