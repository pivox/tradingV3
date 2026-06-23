<?php

declare(strict_types=1);

namespace App\Tests\Trading\Lineage;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Common\Enum\PositionSide;
use App\Entity\OrderIntent;
use App\Entity\TradeLifecycleEvent;
use App\Entity\TradeLineage;
use App\Logging\TradeLifecycleLogger;
use App\Repository\TradeLifecycleEventRepository;
use App\Repository\TradeLineageRepository;
use App\Trading\Dto\PositionHistoryEntryDto;
use App\Trading\Event\PositionClosedEvent;
use App\Trading\Lineage\TradeLineageManager;
use App\Trading\Listener\TradeLifecycleLoggerListener;
use Brick\Math\BigDecimal;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(TradeLifecycleLoggerListener::class)]
final class TradeLifecycleLoggerListenerLineageTest extends KernelTestCase
{
    private EntityManagerInterface $em;

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
            [OrderIntent::class, TradeLineage::class, TradeLifecycleEvent::class],
        );
        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $metadata = array_map(
                fn (string $class) => $this->em->getClassMetadata($class),
                [OrderIntent::class, TradeLineage::class, TradeLifecycleEvent::class],
            );
            (new SchemaTool($this->em))->dropSchema($metadata);
            $this->em->close();
        }

        parent::tearDown();
    }

    public function testClosedPositionRiskLookupUsesResolvedLineageRunId(): void
    {
        $lineage = $this->persistLineageWithPosition();
        $this->persistOrderSubmitted('run-other', '1', new \DateTimeImmutable('2026-06-23 10:02:00 UTC'));
        $this->persistOrderSubmitted($lineage->getRunId(), '50', new \DateTimeImmutable('2026-06-23 10:01:00 UTC'));

        /** @var TradeLifecycleEventRepository $tradeLifecycleRepository */
        $tradeLifecycleRepository = $this->em->getRepository(TradeLifecycleEvent::class);

        $listener = new TradeLifecycleLoggerListener(
            new TradeLifecycleLogger($this->em, $this->fixedClock()),
            $tradeLifecycleRepository,
            null,
            $this->tradeLineageManager(),
        );

        $listener->onPositionClosed(new PositionClosedEvent(
            positionHistory: new PositionHistoryEntryDto(
                symbol: 'BTCUSDT',
                side: PositionSide::LONG,
                size: BigDecimal::of('1'),
                entryPrice: BigDecimal::of('100'),
                exitPrice: BigDecimal::of('200'),
                realizedPnl: BigDecimal::of('100'),
                fees: null,
                openedAt: new \DateTimeImmutable('2026-06-23 10:00:00 UTC'),
                closedAt: new \DateTimeImmutable('2026-06-23 10:05:00 UTC'),
                raw: ['position_id' => 'pos-real'],
            ),
            runId: null,
            exchange: Exchange::BITMART->value,
            extra: ['market_type' => MarketType::PERPETUAL->value],
        ));

        /** @var TradeLifecycleEvent $closed */
        $closed = $this->em->getRepository(TradeLifecycleEvent::class)->findOneBy([
            'eventType' => 'position_closed',
            'positionId' => 'pos-real',
        ]);

        self::assertNotNull($closed);
        self::assertSame('run-real', $closed->getRunId());
        self::assertSame(2.0, $closed->getExtra()['pnl_R'] ?? null);
    }

    private function persistLineageWithPosition(): TradeLineage
    {
        $intent = (new OrderIntent())
            ->setExchange(Exchange::BITMART)
            ->setMarketType(MarketType::PERPETUAL)
            ->setSymbol('BTCUSDT')
            ->setSide(1)
            ->setType(OrderIntent::TYPE_LIMIT)
            ->setOpenType(OrderIntent::OPEN_TYPE_ISOLATED)
            ->setPositionMode(OrderIntent::POSITION_MODE_ONE_WAY)
            ->setSize(1)
            ->setClientOrderId('cid-real')
            ->setPresetMode(OrderIntent::PRESET_MODE_NONE)
            ->setDecisionKey('bitmart:perpetual:BTCUSDT:1m:1764161200:long:scalper:v1');

        $this->em->persist($intent);
        $this->em->flush();

        $lineage = $this->tradeLineageManager()->ensureForIntent($intent, [
            'internal_trade_id' => 'itd-real',
            'run_id' => 'run-real',
        ]);
        $this->tradeLineageManager()->attachPositionId($lineage, 'pos-real');

        return $lineage;
    }

    private function persistOrderSubmitted(string $runId, string $riskUsdt, \DateTimeImmutable $happenedAt): void
    {
        $event = (new TradeLifecycleEvent('BTCUSDT', 'order_submitted', $happenedAt))
            ->setRunId($runId)
            ->setExchange(Exchange::BITMART)
            ->setMarketType(MarketType::PERPETUAL)
            ->setExtra(['risk_usdt' => $riskUsdt]);

        $this->em->persist($event);
        $this->em->flush();
    }

    private function tradeLineageManager(): TradeLineageManager
    {
        /** @var TradeLineageRepository $repository */
        $repository = $this->em->getRepository(TradeLineage::class);

        return new TradeLineageManager($repository, $this->em, new NullLogger());
    }

    private function fixedClock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-06-23 10:06:00 UTC');
            }
        };
    }
}
