<?php

declare(strict_types=1);

namespace App\Tests\Trading\Lineage;

use App\Trading\Lineage\LineageContext;
use App\Trading\Lineage\LineageContextException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LineageContext::class)]
final class LineageContextTest extends TestCase
{
    public function testBuildsOrchestratorContextFromValidatedPayload(): void
    {
        $context = LineageContext::fromOrchestratorPayload([
            'run_id' => 'run-original',
            'correlation_run_id' => 'run-original',
            'orchestration_set_id' => 'set-a',
            'orchestration_dashboard_id' => 'dash-a',
            'mtf_profile' => 'scalper_micro',
            'exchange' => 'BITMART',
            'market_type' => 'PERP',
            'symbol' => 'btcusdt',
            'dry_run' => true,
            'config_hash' => 'cfg-sha',
        ]);

        self::assertSame('orchestrator', $context->origin);
        self::assertSame(1, $context->attemptNumber);
        self::assertSame('run-original', $context->orchestrationRunId);
        self::assertSame('run-original', $context->correlationRunId);
        self::assertSame('set-a', $context->orchestrationSetId);
        self::assertSame('dash-a', $context->orchestrationDashboardId);
        self::assertSame('scalper_micro', $context->mtfProfile);
        self::assertSame('bitmart', $context->exchange);
        self::assertSame('perpetual', $context->marketType);
        self::assertSame('BTCUSDT', $context->symbol);
        self::assertTrue($context->dryRun);
        self::assertSame('cfg-sha', $context->configHash);
    }

    public function testBuildsExplicitLegacyContextWithoutFakeSetOrDashboard(): void
    {
        $context = LineageContext::legacy(symbol: 'ethusdt', exchange: 'fake', marketType: 'spot', mtfProfile: 'regular');

        self::assertSame('legacy', $context->origin);
        self::assertSame('ETHUSDT', $context->symbol);
        self::assertSame('fake', $context->exchange);
        self::assertSame('spot', $context->marketType);
        self::assertSame('regular', $context->mtfProfile);
        self::assertNull($context->orchestrationSetId);
        self::assertNull($context->orchestrationDashboardId);
    }

    public function testRejectsContradictoryPayloadAliases(): void
    {
        $this->expectException(LineageContextException::class);
        $this->expectExceptionMessage('orchestration_set_id');

        LineageContext::fromOrchestratorPayload([
            'run_id' => 'run-a',
            'set_id' => 'set-a',
            'orchestration_set_id' => 'set-b',
        ]);
    }

    public function testReplayCarriesOriginReferencesAndAttemptNumber(): void
    {
        $base = LineageContext::fromOrchestratorPayload([
            'run_id' => 'run-original',
            'correlation_run_id' => 'run-original',
            'set_id' => 'set-a',
            'dashboard_id' => 'dash-a',
            'profile' => 'scalper',
            'exchange' => 'bitmart',
            'market_type' => 'perpetual',
            'symbol' => 'SOLUSDT',
        ]);

        $replay = $base->asReplay('run-replay', 'run-original', 'run-original', 2);

        self::assertSame('replay', $replay->origin);
        self::assertSame('run-replay', $replay->orchestrationRunId);
        self::assertSame('run-original', $replay->replayOfRunId);
        self::assertSame('run-original', $replay->replayOfCorrelationId);
        self::assertSame(2, $replay->attemptNumber);
        self::assertSame('set-a', $replay->orchestrationSetId);
    }

    public function testMessengerSerializationRoundTripsAndRedactsSensitiveFields(): void
    {
        $context = LineageContext::fromOrchestratorPayload([
            'run_id' => 'run-a',
            'set_id' => 'set-a',
            'dashboard_id' => 'dash-a',
            'profile' => 'scalper',
            'exchange' => 'bitmart',
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'token' => 'secret-token',
            'api_key' => 'secret-key',
        ]);

        $roundTrip = LineageContext::fromArray($context->toArray());

        self::assertEquals($context, $roundTrip);
        self::assertArrayNotHasKey('token', $context->redacted());
        self::assertArrayNotHasKey('api_key', $context->redacted());
        self::assertSame('run-a', $context->redacted()['orchestration_run_id']);
    }
}
