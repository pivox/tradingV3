<?php

declare(strict_types=1);

namespace App\Tests\Trading\Lineage;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Entity\OrderIntent;
use App\Entity\TradeLineage;
use App\Provider\Context\ExchangeContext;
use App\Repository\TradeLineageRepository;
use App\Trading\Lineage\TradeLineageManager;
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
        $intent = $this->persistIntent('cid-lineage', 'BTCUSDT', Exchange::BITMART, MarketType::PERPETUAL);
        $longOriginalRunId = 'run-original-' . str_repeat('x', 140);
        $longReplayRunId = 'run-source-' . str_repeat('y', 140);

        $lineage = $this->manager->ensureForIntent($intent, [
            'internal_trade_id' => 'itd-lineage',
            'run_id' => 'corr-run',
            'correlation_run_id' => 'corr-run',
            'orchestration_run_id' => $longOriginalRunId,
            'orchestration_set_id' => 'set-a',
            'orchestration_dashboard_id' => 'dash-a',
            'profile' => 'scalper_micro',
            'origin' => 'replay',
            'replay_of_run_id' => $longReplayRunId,
            'replay_of_correlation_id' => 'source-corr',
            'attempt_number' => 2,
            'config_hash' => 'cfg-123',
        ]);
        $this->em->clear();

        /** @var TradeLineage $reloaded */
        $reloaded = $this->em->getRepository(TradeLineage::class)->find($lineage->getId());

        self::assertSame('itd-lineage', $reloaded->getInternalTradeId());
        self::assertSame('replay', $reloaded->getOrigin());
        self::assertSame($longOriginalRunId, $reloaded->getOrchestrationRunId());
        self::assertSame($longReplayRunId, $reloaded->getReplayOfRunId());
        self::assertSame('source-corr', $reloaded->getReplayOfCorrelationId());
        self::assertSame(2, $reloaded->getAttemptNumber());
        self::assertSame('cfg-123', $reloaded->getConfigHash());
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
}
