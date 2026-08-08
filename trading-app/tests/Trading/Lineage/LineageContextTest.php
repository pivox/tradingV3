<?php

declare(strict_types=1);

namespace App\Tests\Trading\Lineage;

use App\Trading\Lineage\LineageContext;
use App\Trading\Lineage\LineageContextException;
use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;

#[CoversClass(LineageContext::class)]
final class LineageContextTest extends TestCase
{
    public function testCanonicalHashMatchesPythonForFullPhpShapedUnicodeSnapshot(): void
    {
        $config = [
            'schema_version' => 'effective-trading-config.v2',
            'units' => ['percent' => 'percentage_points', 'duration' => 'iso8601', 'price' => 'quote_price', 'notional' => 'quote_notional'],
            'safety' => ['mainnet_write_enabled' => false, 'demo_testnet_write_enabled' => false, 'require_stop_loss' => true, 'kill_switch_enabled' => true],
            'mode' => ['mode_id' => 'scalping', 'mode_version' => '1.0.0'],
            'setup' => ['setup_id' => 'scalping.pullback.long', 'setup_version' => '1.0.0', 'side' => 'long'],
            'exchange' => ['id' => 'fake'],
            'environment' => ['id' => 'demo', 'note' => 'café/path'],
        ];
        self::assertSame(
            'sha256:06f3de28b7b0269688c30ccc4b88bedd9888bf33c360c463ed19717c3aa2cca7',
            CanonicalEffectiveConfigSnapshot::calculateConfigHash($config, self::CATALOG_HASH),
        );
    }
    public function testContractKindExplicitlyDiscriminatesLegacyAndModernContexts(): void
    {
        self::assertFalse(LineageContext::legacy('BTCUSDT', 'bitmart', 'perpetual')->isModern());
        self::assertTrue(LineageContext::fromOrchestratorPayload($this->canonicalPayload())->isModern());

        $payload = $this->canonicalPayload();
        $payload['contract_kind'] = 'legacy';
        $this->expectExceptionMessage('canonical_identity_mismatch:contract_kind');
        LineageContext::fromOrchestratorPayload($payload);
    }
    private const CONFIG_HASH = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CATALOG_HASH = 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** @dataProvider invalidModernIdentityProvider */
    public function testRejectsInvalidModernCanonicalFields(string $field, mixed $value, string $reason): void
    {
        $payload = $this->canonicalPayload();
        $payload[$field] = $value;

        $this->expectException(LineageContextException::class);
        $this->expectExceptionMessage($reason);

        LineageContext::fromOrchestratorPayload($payload);
    }

    /** @return iterable<string, array{string,mixed,string}> */
    public static function invalidModernIdentityProvider(): iterable
    {
        yield 'legacy mode alias' => ['mode_id', 'scalper', 'canonical_identity_invalid:mode_id'];
        yield 'unknown setup' => ['setup_id', 'scalping.unknown.long', 'canonical_identity_invalid:setup_id'];
        yield 'mode setup mismatch' => ['mode_id', 'day_trading', 'canonical_identity_mismatch:mode_id'];
        yield 'side setup mismatch' => ['side', 'SHORT', 'canonical_identity_mismatch:side'];
        yield 'latest mode version' => ['mode_version', 'latest', 'canonical_identity_invalid:mode_version'];
        yield 'range setup version' => ['setup_version', '^1.0', 'canonical_identity_invalid:setup_version'];
        yield 'unpublished setup version' => ['setup_version', '1.0.1', 'canonical_identity_invalid:setup_version'];
        yield 'uppercase config hash' => ['config_hash', 'sha256:' . str_repeat('A', 64), 'canonical_identity_invalid:config_hash'];
        yield 'bare catalog digest' => ['condition_catalog_hash', str_repeat('b', 64), 'canonical_identity_invalid:condition_catalog_hash'];
        yield 'legacy exchange' => ['exchange', 'bitmart', 'canonical_identity_invalid:exchange'];
        yield 'market alias' => ['market_type', 'perp', 'canonical_identity_invalid:market_type'];
        yield 'unsafe symbol' => ['symbol', 'BTC/USDT', 'canonical_identity_invalid:symbol'];
        yield 'unsafe run id' => ['orchestration_run_id', '../run', 'canonical_identity_invalid:orchestration_run_id'];
        yield 'unsafe set id' => ['orchestration_set_id', 'set id', 'canonical_identity_invalid:orchestration_set_id'];
        yield 'non uuid decision id' => ['decision_id', 'decision-1', 'canonical_identity_invalid:decision_id'];
        yield 'unsafe decision key' => ['decision_key', "decision\nkey", 'canonical_identity_invalid:decision_key'];
    }

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

    public function testDirectModernConstructionUsedByMessagesIsAlsoStrict(): void
    {
        $this->expectException(LineageContextException::class);
        $this->expectExceptionMessage('canonical_identity_invalid:mode_id');

        new LineageContext(
            origin: 'orchestrator',
            orchestrationRunId: 'run-1',
            orchestrationSetId: 'set-1',
            exchange: 'fake',
            marketType: 'perpetual',
            symbol: 'BTCUSDT',
            configHash: self::CONFIG_HASH,
            modeId: 'scalper',
            modeVersion: '1.0.0',
            setupId: 'scalping.pullback.long',
            setupVersion: '1.0.0',
            conditionCatalogHash: self::CATALOG_HASH,
            side: 'LONG',
            effectiveConfigReference: 'effective-config:cfg-1',
        );
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
        $decision = $base->withDecision('018f47a2-4f42-7e1b-8d3a-4dc9571bb11b', 'decision-key-1');
        $intent = $decision->withIntent('intent-1');
        $executed = $intent->withExecution('order-1', 'position-1', 'trade-1');

        self::assertNull($base->decisionId);
        self::assertSame('018f47a2-4f42-7e1b-8d3a-4dc9571bb11b', $executed->decisionId);
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
            ->withDecision('018f47a2-4f42-7e1b-8d3a-4dc9571bb11b', 'decision-key-1');

        $this->expectException(LineageContextException::class);
        $this->expectExceptionMessage('canonical_identity_mismatch:decisionId');

        $decision->withDecision('018f47a2-4f42-7e1b-8d3a-4dc9571bb22c', 'decision-key-1');
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

    public function testRedactedCanonicalIdentityOmitsTheEffectiveConfigSnapshot(): void
    {
        $secret = 'TOP-SECRET';
        $config = CanonicalSnapshotFixture::config();
        $config['exchange']['api_key'] = $secret;
        $identity = CanonicalSnapshotFixture::lineage($config);

        self::assertSame(
            $secret,
            $identity->toArray()['effective_config_snapshot']['config']['exchange']['api_key'] ?? null,
            'The lossless transport representation must retain the validated snapshot.',
        );

        $redacted = $identity->redacted();

        self::assertArrayNotHasKey('effective_config_snapshot', $redacted);
        self::assertSame($identity->effectiveConfigReference, $redacted['effective_config_reference'] ?? null);
        self::assertSame($identity->configHash, $redacted['config_hash'] ?? null);
        self::assertSame($identity->conditionCatalogHash, $redacted['condition_catalog_hash'] ?? null);
        self::assertStringNotContainsString($secret, json_encode($redacted, JSON_THROW_ON_ERROR));
    }

    public function testCanonicalHashSurvivesNormalJsonRoundTripAcrossIntegralFloatUnicodeAndSlash(): void
    {
        $config = ['leverage' => 3.0, 'note' => 'café/path'];
        $response = new JsonResponse(['config' => $config], json: false);
        $roundTripped = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['config'];

        self::assertSame(3, $roundTripped['leverage']);
        self::assertSame('café/path', $roundTripped['note']);
        self::assertSame(
            'sha256:1f55b0a0080a7c32b97ab8ff2907485ac3ebcb0dd4f1efb391b4c4b5f90c1418',
            CanonicalEffectiveConfigSnapshot::calculateConfigHash($roundTripped, self::CATALOG_HASH),
        );
        self::assertSame(
            CanonicalEffectiveConfigSnapshot::calculateConfigHash($config, self::CATALOG_HASH),
            CanonicalEffectiveConfigSnapshot::calculateConfigHash($roundTripped, self::CATALOG_HASH),
        );
    }

    /** @return array<string, mixed> */
    private function canonicalPayload(): array
    {
        $config = ['trade_entry' => ['defaults' => [], 'entry' => [], 'risk' => [], 'leverage' => [], 'decision' => [], 'fees' => []]];
        $configHash = CanonicalEffectiveConfigSnapshot::calculateConfigHash($config, self::CATALOG_HASH);
        return [
            'origin' => 'orchestrator',
            'orchestration_run_id' => 'run-1',
            'correlation_run_id' => 'run-1',
            'orchestration_set_id' => 'set-1',
            'mode_id' => 'scalping',
            'mode_version' => '1.0.0',
            'setup_id' => 'scalping.pullback.long',
            'setup_version' => '1.0.0',
            'config_hash' => $configHash,
            'condition_catalog_hash' => self::CATALOG_HASH,
            'side' => 'LONG',
            'context_side' => 'LONG',
            'exchange' => 'fake',
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'effective_config_reference' => 'effective-config:cfg-1',
            'effective_config_snapshot' => CanonicalSnapshotMetadataFixture::enrich([
                'request' => ['mode_id' => 'scalping', 'mode_version' => '1.0.0', 'setup_id' => 'scalping.pullback.long', 'setup_version' => '1.0.0', 'exchange' => 'fake', 'environment' => 'test', 'side' => 'long'],
                'config' => $config,
                'config_hash' => $configHash,
                'condition_catalog_hash' => self::CATALOG_HASH,
                'executable' => true,
                'blockers' => [],
            ]),
            'dry_run' => true,
        ];
    }
}
