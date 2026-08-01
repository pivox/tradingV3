<?php

declare(strict_types=1);

namespace App\Tests\Trading\Lineage;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Entity\OrderIntent;
use App\Entity\TradeLineage;
use App\Provider\Context\ExchangeContext;
use App\Repository\OrderIntentRepository;
use App\Repository\TradeLineageRepository;
use App\Service\OrderIntentManager;
use App\TradeEntry\Idempotency\DecisionKeyFactory;
use App\Trading\Lineage\TradeLineageManager;
use App\Trading\Lineage\LineageContext;
use App\Trading\Lineage\LineageContextException;
use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(TradeLineageManager::class)]
#[CoversClass(TradeLineageRepository::class)]
final class TradeLineageManagerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TradeLineageManager $manager;

    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::$kernel->getContainer()->get('doctrine.orm.entity_manager');

        $metadata = array_map(
            fn (string $class) => $this->em->getClassMetadata($class),
            [OrderIntent::class, TradeLineage::class],
        );

        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        /** @var TradeLineageRepository $repository */
        $repository = $this->em->getRepository(TradeLineage::class);
        $this->manager = new TradeLineageManager($repository, $this->em, new NullLogger());
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $metadata = array_map(
                fn (string $class) => $this->em->getClassMetadata($class),
                [OrderIntent::class, TradeLineage::class],
            );
            (new SchemaTool($this->em))->dropSchema($metadata);
            $this->em->close();
        }

        parent::tearDown();
    }

    public function testCreatesOneStableInternalTradeIdForAnOrderIntent(): void
    {
        $intent = $this->persistIntent('cid-1', 'BTCUSDT', Exchange::BITMART, MarketType::PERPETUAL);

        $lineage = $this->manager->ensureForIntent($intent, [
            'internal_trade_id' => 'itd-stable-1',
            'trade_id' => 'legacy-trade-ignored',
            'run_id' => 'run-1',
            'orchestration_set_id' => 'set-1',
            'orchestration_dashboard_id' => 'dash-1',
            'profile' => 'scalper_micro',
        ]);
        $again = $this->manager->ensureForIntent($intent, [
            'internal_trade_id' => 'itd-would-be-wrong',
        ]);

        self::assertSame('itd-stable-1', $lineage->getInternalTradeId());
        self::assertSame($lineage->getId(), $again->getId());
        self::assertSame('itd-stable-1', $again->getInternalTradeId());
        self::assertSame('run-1', $again->getRunId());
        self::assertSame('set-1', $again->getOrchestrationSetId());
        self::assertSame('dash-1', $again->getOrchestrationDashboardId());
        self::assertSame('scalper_micro', $again->getProfile());
    }

    public function testPersistsExplicitLineageContextColumnsForAuditAndReplay(): void
    {
        $intent = $this->persistIntent('cid-lineage', 'BTCUSDT', Exchange::FAKE, MarketType::PERPETUAL);
        $longOriginalRunId = 'run-original-' . str_repeat('x', 140);
        $longReplayRunId = 'run-source-' . str_repeat('y', 140);

        $payload = array_replace($this->canonicalLineagePayload('itd-lineage'), [
            'trade_id' => 'itd-lineage',
            'run_id' => 'corr-run',
            'correlation_run_id' => 'corr-run',
            'orchestration_run_id' => $longOriginalRunId,
            'orchestration_set_id' => 'set-a',
            'orchestration_dashboard_id' => 'dash-a',
            'origin' => 'replay',
            'replay_of_run_id' => $longReplayRunId,
            'replay_of_correlation_id' => 'source-corr',
            'attempt_number' => 2,
            'decision_id' => '018f47a2-4f42-7e1b-8d3a-4dc9571bb11b',
            'decision_key' => 'decision-key-1',
            'effective_config_reference' => 'effective-config:cfg-1',
        ]);
        $lineage = $this->manager->ensureForIntent($intent, LineageContext::fromOrchestratorPayload($payload));
        $this->em->clear();

        /** @var TradeLineage $reloaded */
        $reloaded = $this->em->getRepository(TradeLineage::class)->find($lineage->getId());

        self::assertSame('itd-lineage', $reloaded->getInternalTradeId());
        self::assertSame('replay', $reloaded->getOrigin());
        self::assertSame($longOriginalRunId, $reloaded->getOrchestrationRunId());
        self::assertSame($longReplayRunId, $reloaded->getReplayOfRunId());
        self::assertSame('source-corr', $reloaded->getReplayOfCorrelationId());
        self::assertSame(2, $reloaded->getAttemptNumber());
        self::assertSame($payload['config_hash'], $reloaded->getConfigHash());
        self::assertSame('sha256:' . str_repeat('b', 64), $reloaded->getConditionCatalogHash());
        self::assertSame('scalping', $reloaded->getModeId());
        self::assertSame('1.0.0', $reloaded->getModeVersion());
        self::assertSame('scalping.pullback.long', $reloaded->getSetupId());
        self::assertSame('1.0.0', $reloaded->getSetupVersion());
        self::assertSame('018f47a2-4f42-7e1b-8d3a-4dc9571bb11b', $reloaded->getDecisionId());
        self::assertSame('decision-key-1', $reloaded->getDecisionKey());
        self::assertSame('effective-config:cfg-1', $reloaded->getEffectiveConfigReference());
    }

    /** @dataProvider rawModernFieldProvider */
    public function testRejectsRawModernContextInsteadOfPersistingUncheckedDictionary(string $field, mixed $value): void
    {
        $intent = $this->persistIntent('cid-raw-modern-' . str_replace('_', '-', $field), 'BTCUSDT', Exchange::FAKE, MarketType::PERPETUAL);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical_identity_typed_context_required');

        $this->manager->ensureForIntent($intent, [$field => $value]);
    }

    /** @return iterable<string, array{string,mixed}> */
    public static function rawModernFieldProvider(): iterable
    {
        yield 'mode identity' => ['mode_id', 'scalping'];
        yield 'mode version' => ['mode_version', '1.0.0'];
        yield 'setup identity' => ['setup_id', 'scalping.pullback.long'];
        yield 'setup version' => ['setup_version', '1.0.0'];
        yield 'condition catalog hash' => ['condition_catalog_hash', 'sha256:' . str_repeat('b', 64)];
        yield 'decision UUID' => ['decision_id', '018f47a2-4f42-7e1b-8d3a-4dc9571bb11b'];
        yield 'decision key' => ['decision_key', 'decision-key-raw'];
        yield 'effective config reference' => ['effective_config_reference', 'effective-config:cfg-1'];
        yield 'effective config snapshot' => ['effective_config_snapshot', ['snapshot_id' => 'cfg-1']];
    }

    public function testIdempotentRetryRejectsCanonicalIdentityMismatch(): void
    {
        $intent = $this->persistIntent('cid-retry-modern', 'BTCUSDT', Exchange::FAKE, MarketType::PERPETUAL);
        $payload = $this->canonicalLineagePayload('cid-retry-modern');
        $payload['intent_id'] = 'intent-structured-retry';
        $identity = LineageContext::fromOrchestratorPayload($payload);
        $intent->applyLineageContext($identity);
        $this->em->flush();
        $first = $this->manager->ensureForIntent($intent, $identity);
        $same = $this->manager->ensureForIntent($intent, $identity);
        self::assertSame($first->getId(), $same->getId());
        self::assertNotSame((string) $intent->getId(), $identity->intentId);

        $payload['config_hash'] = 'sha256:' . str_repeat('c', 64);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical_identity_mismatch:config_hash');

        $this->manager->ensureForIntent($intent, LineageContext::fromOrchestratorPayload($payload));
    }

    public function testLegacyLineageConfigHashDoesNotTurnExactDuplicateRetryIntoModernContract(): void
    {
        $intent = (new OrderIntent())
            ->setExchange(Exchange::FAKE)
            ->setMarketType(MarketType::PERPETUAL)
            ->setSymbol('BTCUSDT')
            ->setSide(1)
            ->setType(OrderIntent::TYPE_LIMIT)
            ->setOpenType(OrderIntent::OPEN_TYPE_ISOLATED)
            ->setPositionMode(OrderIntent::POSITION_MODE_ONE_WAY)
            ->setSize(1)
            ->setClientOrderId('cid-legacy-lineage-retry')
            ->setPresetMode(OrderIntent::PRESET_MODE_NONE);
        $this->em->persist($intent);
        $this->em->flush();
        $this->manager->ensureForIntent($intent, ['config_hash' => 'legacy-config-v1']);

        /** @var OrderIntentRepository $repository */
        $repository = $this->em->getRepository(OrderIntent::class);
        $intents = new OrderIntentManager($repository, $this->em, new NullLogger(), new DecisionKeyFactory());
        $retry = $intents->reserveIntent([
            'exchange' => 'fake',
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'side' => 1,
            'type' => OrderIntent::TYPE_LIMIT,
            'open_type' => OrderIntent::OPEN_TYPE_ISOLATED,
            'position_mode' => OrderIntent::POSITION_MODE_ONE_WAY,
            'size' => 1,
            'client_order_id' => 'cid-legacy-lineage-retry',
            'preset_mode' => OrderIntent::PRESET_MODE_NONE,
        ]);

        self::assertSame('legacy-config-v1', $intent->getConfigHash());
        self::assertFalse($intent->hasAnyCanonicalIdentity());
        self::assertTrue($retry->blocked);
        self::assertSame('idempotent_client_order_id_replay', $retry->reason);
        self::assertSame($intent->getId(), $retry->intent->getId());
    }

    public function testModernRetryRejectsMissingPersistedStructuredIntentId(): void
    {
        $intent = $this->persistIntent('cid-retry-missing-persisted', 'BTCUSDT', Exchange::FAKE, MarketType::PERPETUAL);
        $payload = $this->canonicalLineagePayload('trade-missing-persisted');
        $payload['intent_id'] = 'intent-required';
        $identity = LineageContext::fromOrchestratorPayload($payload);
        $this->manager->ensureForIntent($intent, $identity);

        $this->expectException(LineageContextException::class);
        $this->expectExceptionMessage('canonical_identity_incomplete:intent_id');
        $this->manager->ensureForIntent($intent, $identity);
    }

    public function testModernRetryRejectsMissingRequestedStructuredIntentId(): void
    {
        $intent = $this->persistIntent('cid-retry-missing-requested', 'BTCUSDT', Exchange::FAKE, MarketType::PERPETUAL);
        $payload = $this->canonicalLineagePayload('trade-missing-requested');
        $payload['intent_id'] = 'intent-persisted';
        $identity = LineageContext::fromOrchestratorPayload($payload);
        $intent->applyLineageContext($identity);
        $this->em->flush();
        $this->manager->ensureForIntent($intent, $identity);
        unset($payload['intent_id']);

        $this->expectException(LineageContextException::class);
        $this->expectExceptionMessage('canonical_identity_incomplete:intent_id');
        $this->manager->ensureForIntent($intent, LineageContext::fromOrchestratorPayload($payload));
    }

    public function testModernRetryRejectsMismatchedStructuredIntentId(): void
    {
        $intent = $this->persistIntent('cid-retry-intent-mismatch', 'BTCUSDT', Exchange::FAKE, MarketType::PERPETUAL);
        $payload = $this->canonicalLineagePayload('trade-intent-mismatch');
        $payload['intent_id'] = 'intent-persisted';
        $identity = LineageContext::fromOrchestratorPayload($payload);
        $intent->applyLineageContext($identity);
        $this->em->flush();
        $this->manager->ensureForIntent($intent, $identity);
        $payload['intent_id'] = 'intent-other';

        $this->expectException(LineageContextException::class);
        $this->expectExceptionMessage('canonical_identity_mismatch:intent_id');
        $this->manager->ensureForIntent($intent, LineageContext::fromOrchestratorPayload($payload));
    }

    public function testResolvesOnlyByExactPersistedIdentifiersWithinVenue(): void
    {
        $bitmart = $this->persistIntent('shared-cid', 'BTCUSDT', Exchange::BITMART, MarketType::PERPETUAL);
        $okx = $this->persistIntent('shared-cid', 'BTCUSDT', Exchange::OKX, MarketType::PERPETUAL);

        $bitmartLineage = $this->manager->ensureForIntent($bitmart, ['internal_trade_id' => 'itd-bitmart']);
        $okxLineage = $this->manager->ensureForIntent($okx, ['internal_trade_id' => 'itd-okx']);

        $this->manager->attachExchangeOrderId($bitmartLineage, 'ex-shared');
        $this->manager->attachExchangeOrderId($okxLineage, 'ex-shared');
        $this->manager->attachPositionId($bitmartLineage, 'pos-shared');
        $this->manager->attachPositionId($okxLineage, 'pos-shared');

        self::assertSame(
            'itd-bitmart',
            $this->manager->resolve(
                new ExchangeContext(Exchange::BITMART, MarketType::PERPETUAL),
                clientOrderId: 'shared-cid',
            )?->getInternalTradeId(),
        );
        self::assertSame(
            'itd-okx',
            $this->manager->resolve(
                new ExchangeContext(Exchange::OKX, MarketType::PERPETUAL),
                exchangeOrderId: 'ex-shared',
            )?->getInternalTradeId(),
        );
        self::assertSame(
            'itd-bitmart',
            $this->manager->resolve(
                new ExchangeContext(Exchange::BITMART, MarketType::PERPETUAL),
                positionId: 'pos-shared',
            )?->getInternalTradeId(),
        );
    }

    public function testDoesNotResolveFromSymbolSideOrTimestamp(): void
    {
        $intent = $this->persistIntent('cid-a', 'SOLUSDT', Exchange::BITMART, MarketType::PERPETUAL);
        $this->manager->ensureForIntent($intent, ['internal_trade_id' => 'itd-sol']);

        $resolved = $this->manager->resolve(
            new ExchangeContext(Exchange::BITMART, MarketType::PERPETUAL),
            symbol: 'SOLUSDT',
            side: 'LONG',
        );

        self::assertNull($resolved);
    }

    public function testResolveFallsBackAfterUnmatchedHigherPriorityIdentifier(): void
    {
        $intent = $this->persistIntent('cid-real', 'BTCUSDT', Exchange::BITMART, MarketType::PERPETUAL);
        $lineage = $this->manager->ensureForIntent($intent, ['internal_trade_id' => 'itd-fallback']);
        $this->manager->attachExchangeOrderId($lineage, 'ex-fallback');

        $resolved = $this->manager->resolve(
            new ExchangeContext(Exchange::BITMART, MarketType::PERPETUAL),
            clientOrderId: 'stale-client-id',
            exchangeOrderId: 'ex-fallback',
        );

        self::assertSame('itd-fallback', $resolved?->getInternalTradeId());
    }

    public function testAmbiguousExchangeOrPositionIdentifierStaysUnmatched(): void
    {
        $first = $this->persistIntent('cid-first', 'BTCUSDT', Exchange::BITMART, MarketType::PERPETUAL);
        $second = $this->persistIntent('cid-second', 'BTCUSDT', Exchange::BITMART, MarketType::PERPETUAL);

        $firstLineage = $this->manager->ensureForIntent($first, ['internal_trade_id' => 'itd-first']);
        $secondLineage = $this->manager->ensureForIntent($second, ['internal_trade_id' => 'itd-second']);

        $this->manager->attachExchangeOrderId($firstLineage, 'ambiguous-order');
        $this->manager->attachExchangeOrderId($secondLineage, 'ambiguous-order');
        $this->manager->attachPositionId($firstLineage, 'ambiguous-position');
        $this->manager->attachPositionId($secondLineage, 'ambiguous-position');

        $context = new ExchangeContext(Exchange::BITMART, MarketType::PERPETUAL);

        self::assertNull($this->manager->resolve($context, exchangeOrderId: 'ambiguous-order'));
        self::assertNull($this->manager->resolve($context, positionId: 'ambiguous-position'));
        self::assertSame('itd-first', $this->manager->resolve($context, clientOrderId: 'cid-first')?->getInternalTradeId());
    }

    private function persistIntent(
        string $clientOrderId,
        string $symbol,
        Exchange $exchange,
        MarketType $marketType,
    ): OrderIntent {
        $intent = (new OrderIntent())
            ->setExchange($exchange)
            ->setMarketType($marketType)
            ->setSymbol($symbol)
            ->setSide(1)
            ->setType(OrderIntent::TYPE_LIMIT)
            ->setOpenType(OrderIntent::OPEN_TYPE_ISOLATED)
            ->setPositionMode(OrderIntent::POSITION_MODE_ONE_WAY)
            ->setSize(1)
            ->setClientOrderId($clientOrderId)
            ->setPresetMode(OrderIntent::PRESET_MODE_NONE)
            ->setDecisionKey(sprintf('%s:%s:%s:%s:1m:1764161200:long:scalper:v1', $exchange->value, $marketType->value, $symbol, $clientOrderId));

        $this->em->persist($intent);
        $this->em->flush();

        return $intent;
    }

    /** @return array<string,mixed> */
    private function canonicalLineagePayload(string $tradeId): array
    {
        $catalogHash = 'sha256:' . str_repeat('b', 64);
        $config = ['trade_entry' => ['defaults' => [], 'entry' => [], 'risk' => [], 'leverage' => [], 'decision' => [], 'fees' => []]];
        $configHash = CanonicalEffectiveConfigSnapshot::calculateConfigHash($config, $catalogHash);
        return [
            'origin' => 'orchestrator',
            'orchestration_run_id' => 'run-retry',
            'correlation_run_id' => 'run-retry',
            'orchestration_set_id' => 'set-retry',
            'mode_id' => 'scalping',
            'mode_version' => '1.0.0',
            'setup_id' => 'scalping.pullback.long',
            'setup_version' => '1.0.0',
            'config_hash' => $configHash,
            'condition_catalog_hash' => $catalogHash,
            'side' => 'LONG',
            'exchange' => 'fake',
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'decision_id' => '018f47a2-4f42-7e1b-8d3a-4dc9571bb11b',
            'decision_key' => 'decision-key-retry',
            'trade_id' => $tradeId,
            'effective_config_reference' => 'effective-config:cfg-retry',
            'effective_config_snapshot' => CanonicalSnapshotMetadataFixture::enrich([
                'request' => ['mode_id' => 'scalping', 'mode_version' => '1.0.0', 'setup_id' => 'scalping.pullback.long', 'setup_version' => '1.0.0', 'exchange' => 'fake', 'environment' => 'test', 'side' => 'long'],
                'config' => $config, 'config_hash' => $configHash, 'condition_catalog_hash' => $catalogHash,
                'executable' => true, 'blockers' => [],
            ]),
        ];
    }
}
