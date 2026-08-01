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
    private const CONFIG_HASH = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CATALOG_HASH = 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testCanonicalModernIdentityRoundTripsWithoutMutableAliases(): void
    {
        $identity = LineageContext::fromOrchestratorPayload($this->canonicalPayload());

        self::assertEquals($identity, LineageContext::fromArray($identity->toArray()));
        self::assertSame('scalping', $identity->modeId);
        self::assertSame('scalping.pullback.long', $identity->setupId);
        self::assertSame('LONG', $identity->side);
        self::assertSame(self::CATALOG_HASH, $identity->conditionCatalogHash);
        self::assertArrayNotHasKey('profile', $identity->toArray());
        self::assertArrayNotHasKey('mtf_profile', $identity->toArray());
        self::assertArrayNotHasKey('config_effective_version', $identity->toArray());
    }

    public function testModernIdentityFailsClosedWhenRequiredFieldIsMissing(): void
    {
        $payload = $this->canonicalPayload();
        unset($payload['setup_version']);

        $this->expectException(LineageContextException::class);
        $this->expectExceptionMessage('canonical_identity_missing:setup_version');

        LineageContext::fromOrchestratorPayload($payload);
    }

    public function testModernIdentityRejectsContradictorySideAndHash(): void
    {
        $payload = $this->canonicalPayload();
        $payload['context_side'] = 'SHORT';

        $this->expectException(LineageContextException::class);
        $this->expectExceptionMessage('canonical_identity_mismatch:side');

        LineageContext::fromOrchestratorPayload($payload);
    }

    public function testStageIdsAreAddedWithoutChangingCanonicalIdentity(): void
    {
        $base = LineageContext::fromOrchestratorPayload($this->canonicalPayload());
        $decision = $base->withDecision('decision-1', 'decision-key-1');
        $intent = $decision->withIntent('intent-1');
        $executed = $intent->withExecution('order-1', 'position-1', 'trade-1');

        self::assertNull($base->decisionId);
        self::assertSame('decision-1', $executed->decisionId);
        self::assertSame('decision-key-1', $executed->decisionKey);
        self::assertSame('intent-1', $executed->intentId);
        self::assertSame('order-1', $executed->orderId);
        self::assertSame('position-1', $executed->positionId);
        self::assertSame('trade-1', $executed->tradeId);
        self::assertSame($base->configHash, $executed->configHash);
        self::assertSame($base->conditionCatalogHash, $executed->conditionCatalogHash);
        self::assertSame($base->effectiveConfigReference, $executed->effectiveConfigReference);
    }

    public function testStageIdCannotBeReplacedAfterCreation(): void
    {
        $decision = LineageContext::fromOrchestratorPayload($this->canonicalPayload())
            ->withDecision('decision-1', 'decision-key-1');

        $this->expectException(LineageContextException::class);
        $this->expectExceptionMessage('canonical_identity_mismatch:decisionId');

        $decision->withDecision('decision-2', 'decision-key-1');
    }

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

    /** @return array<string, mixed> */
    private function canonicalPayload(): array
    {
        return [
            'origin' => 'orchestrator',
            'orchestration_run_id' => 'run-1',
            'correlation_run_id' => 'run-1',
            'orchestration_set_id' => 'set-1',
            'mode_id' => 'scalping',
            'mode_version' => '1.0.0',
            'setup_id' => 'scalping.pullback.long',
            'setup_version' => '1.0.0',
            'config_hash' => self::CONFIG_HASH,
            'condition_catalog_hash' => self::CATALOG_HASH,
            'side' => 'LONG',
            'context_side' => 'LONG',
            'exchange' => 'fake',
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'effective_config_reference' => 'effective-config:cfg-1',
            'effective_config_snapshot' => ['schema_version' => '1.0.0', 'snapshot_id' => 'cfg-1'],
            'dry_run' => true,
        ];
    }
}
