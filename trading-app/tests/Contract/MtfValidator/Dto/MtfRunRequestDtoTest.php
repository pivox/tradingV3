<?php

declare(strict_types=1);

namespace App\Tests\Contract\MtfValidator\Dto;

use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * OBS-003 — Le DTO de requête MTF porte le run_id de corrélation (`requestId`) et le
 * lineage d'orchestration jusqu'au dispatcher (puis l'`extra` de `order_submitted`).
 */
#[CoversClass(MtfRunRequestDto::class)]
final class MtfRunRequestDtoTest extends TestCase
{
    public function testParsesRequestIdAndOrchestrationLineage(): void
    {
        $dto = MtfRunRequestDto::fromArray([
            'symbols' => ['BTCUSDT'],
            'request_id' => 'run_dashA',
            'orchestration_run_id' => 'run_dashA_full_original',
            'dashboard_id' => 'dashA',
            'set_id' => 's1',
        ]);

        self::assertSame('run_dashA', $dto->requestId);
        self::assertSame('run_dashA_full_original', $dto->orchestrationRunId);
        self::assertSame('dashA', $dto->dashboardId);
        self::assertSame('s1', $dto->setId);
    }

    public function testAcceptsOrchestrationDashboardAndSetAliases(): void
    {
        $dto = MtfRunRequestDto::fromArray([
            'symbols' => ['BTCUSDT'],
            'orchestration_dashboard_id' => 'dashB',
            'orchestration_set_id' => 's2',
        ]);

        self::assertSame('dashB', $dto->dashboardId);
        self::assertSame('s2', $dto->setId);
    }

    public function testLegacyRequestHasNullLineage(): void
    {
        $dto = MtfRunRequestDto::fromArray(['symbols' => ['BTCUSDT']]);

        self::assertNull($dto->requestId);
        self::assertNull($dto->orchestrationRunId);
        self::assertNull($dto->dashboardId);
        self::assertNull($dto->setId);
    }

    public function testBlankValuesAreNull(): void
    {
        $dto = MtfRunRequestDto::fromArray([
            'symbols' => ['BTCUSDT'],
            'request_id' => '  ',
            'set_id' => '',
        ]);

        self::assertNull($dto->requestId);
        self::assertNull($dto->setId);
    }

    public function testCarriesTypedLineageContextThroughMessengerBoundary(): void
    {
        $dto = MtfRunRequestDto::fromArray([
            'symbols' => ['BTCUSDT'],
            'request_id' => 'run-a',
            'orchestration_run_id' => 'run-a',
            'dashboard_id' => 'dash-a',
            'set_id' => 'set-a',
            'profile' => 'scalper',
            'exchange' => 'bitmart',
            'market_type' => 'perpetual',
            'attempt_number' => 2,
        ]);

        self::assertSame('orchestrator', $dto->lineageContext->origin);
        self::assertSame('run-a', $dto->lineageContext->correlationRunId);
        self::assertSame('set-a', $dto->lineageContext->orchestrationSetId);
        self::assertSame('scalper', $dto->lineageContext->mtfProfile);
        self::assertSame(2, $dto->lineageContext->attemptNumber);
    }
}
