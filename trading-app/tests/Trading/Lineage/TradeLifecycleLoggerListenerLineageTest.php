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
use App\Trading\Dto\PositionDto;
use App\Trading\Event\PositionClosedEvent;
use App\Trading\Event\PositionOpenedEvent;
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
        $this->persistOrderSubmitted($lineage->getRunId(), '1', new \DateTimeImmutable('2026-06-23 10:02:00 UTC'), 'itd-other');
        $this->persistOrderSubmitted($lineage->getRunId(), '50', new \DateTimeImmutable('2026-06-23 10:01:00 UTC'), $lineage->getInternalTradeId());

        $listener = new TradeLifecycleLoggerListener(
            new TradeLifecycleLogger($this->em, $this->fixedClock()),
            $this->tradeLifecycleRepository(),
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

    public function testClosedPositionResolvesLineageFromNestedRawHistoryPositionId(): void
    {
        $lineage = $this->persistLineageWithPosition();
        $this->persistOrderSubmitted($lineage->getRunId(), '25', new \DateTimeImmutable('2026-06-23 10:01:00 UTC'), $lineage->getInternalTradeId());

        $listener = new TradeLifecycleLoggerListener(
            new TradeLifecycleLogger($this->em, $this->fixedClock()),
            $this->tradeLifecycleRepository(),
            null,
            $this->tradeLineageManager(),
        );

        $listener->onPositionClosed(new PositionClosedEvent(
            positionHistory: new PositionHistoryEntryDto(
                symbol: 'BTCUSDT',
                side: PositionSide::LONG,
                size: BigDecimal::of('1'),
                entryPrice: BigDecimal::of('100'),
                exitPrice: BigDecimal::of('150'),
                realizedPnl: BigDecimal::of('50'),
                fees: null,
                openedAt: new \DateTimeImmutable('2026-06-23 10:00:00 UTC'),
                closedAt: new \DateTimeImmutable('2026-06-23 10:05:00 UTC'),
                raw: ['raw_history' => ['position_id' => 'pos-real']],
            ),
            runId: null,
            exchange: Exchange::BITMART->value,
            extra: ['market_type' => MarketType::PERPETUAL->value],
        ));

        /** @var TradeLifecycleEvent|null $closed */
        $closed = $this->em->getRepository(TradeLifecycleEvent::class)->findOneBy([
            'eventType' => 'position_closed',
            'positionId' => 'pos-real',
        ]);

        self::assertNotNull($closed);
        self::assertSame('run-real', $closed->getRunId());
        self::assertSame(2.0, $closed->getExtra()['pnl_R'] ?? null);
    }

    public function testClosedPositionPromotesCertifiedFakePnlPayloadToLifecycleExtra(): void
    {
        $listener = new TradeLifecycleLoggerListener(
            new TradeLifecycleLogger($this->em, $this->fixedClock()),
            $this->tradeLifecycleRepository(),
            null,
            null,
        );

        $listener->onPositionClosed(new PositionClosedEvent(
            positionHistory: new PositionHistoryEntryDto(
                symbol: 'BTCUSDT',
                side: PositionSide::LONG,
                size: BigDecimal::of('1'),
                entryPrice: BigDecimal::of('100'),
                exitPrice: BigDecimal::of('110'),
                realizedPnl: BigDecimal::of('10'),
                fees: BigDecimal::of('0.105'),
                openedAt: new \DateTimeImmutable('2026-06-23 10:00:00 UTC'),
                closedAt: new \DateTimeImmutable('2026-06-23 10:05:00 UTC'),
                raw: [
                    'position_id' => 'fake-pos-1',
                    'payload' => [
                        'gross_realized_pnl_usdt' => 10.0,
                        'recorded_pnl_usdt' => 9.895,
                        'entry_fee_usdt' => 0.05,
                        'exit_fee_usdt' => 0.055,
                        'other_trading_fees_usdt' => 0.0,
                        'funding_usdt' => 0.0,
                        'spread_cost_usdt' => 0.0,
                        'slippage_cost_usdt' => 0.0,
                        'borrow_cost_usdt' => 0.0,
                        'liquidation_fee_usdt' => 0.0,
                        'entry_qty' => 1.0,
                        'exit_qty' => 1.0,
                        'remaining_qty' => 0.0,
                        'position_fully_closed' => true,
                        'fills_complete' => true,
                        'quantity_coherent' => true,
                        'lineage_sufficient' => true,
                        'identifier_conflict' => false,
                        'pnl_source' => 'fake_paper_fill_ledger_v1',
                        'cost_completeness' => 'complete',
                    ],
                ],
            ),
            exchange: Exchange::FAKE->value,
            extra: ['market_type' => MarketType::PERPETUAL->value],
        ));

        /** @var TradeLifecycleEvent|null $closed */
        $closed = $this->em->getRepository(TradeLifecycleEvent::class)->findOneBy([
            'eventType' => 'position_closed',
            'positionId' => 'fake-pos-1',
        ]);

        self::assertNotNull($closed);
        $extra = $closed->getExtra();
        self::assertSame('fake_paper_fill_ledger_v1', $extra['pnl_source'] ?? null);
        self::assertSame(10.0, $extra['gross_realized_pnl_usdt'] ?? null);
        self::assertSame(0.05, $extra['entry_fee_usdt'] ?? null);
        self::assertSame(0.055, $extra['exit_fee_usdt'] ?? null);
        self::assertSame(true, $extra['fills_complete'] ?? null);
        self::assertSame(true, $extra['position_fully_closed'] ?? null);
        self::assertSame('complete', $extra['cost_completeness'] ?? null);
        self::assertArrayHasKey('raw', $extra);
    }

    public function testClosedPositionPromotesFakePayloadLineageToLifecycleExtra(): void
    {
        $this->persistLineageWithPosition();

        $listener = new TradeLifecycleLoggerListener(
            new TradeLifecycleLogger($this->em, $this->fixedClock()),
            $this->tradeLifecycleRepository(),
            null,
            $this->tradeLineageManager(),
        );

        $listener->onPositionClosed(new PositionClosedEvent(
            positionHistory: new PositionHistoryEntryDto(
                symbol: 'BTCUSDT',
                side: PositionSide::LONG,
                size: BigDecimal::of('1'),
                entryPrice: BigDecimal::of('100'),
                exitPrice: BigDecimal::of('110'),
                realizedPnl: BigDecimal::of('10'),
                fees: BigDecimal::of('0.105'),
                openedAt: new \DateTimeImmutable('2026-06-23 10:00:00 UTC'),
                closedAt: new \DateTimeImmutable('2026-06-23 10:05:00 UTC'),
                raw: [
                    'payload' => [
                        'internal_trade_id' => 'itd-real',
                        'position_id' => 'pos-real',
                        'entry_qty' => 1.0,
                        'exit_qty' => 1.0,
                        'remaining_qty' => 0.0,
                        'position_fully_closed' => true,
                        'fills_complete' => true,
                        'quantity_coherent' => true,
                        'lineage_sufficient' => true,
                        'identifier_conflict' => false,
                        'pnl_source' => 'fake_paper_fill_ledger_v1',
                        'cost_completeness' => 'complete',
                    ],
                ],
            ),
            runId: null,
            exchange: Exchange::FAKE->value,
            extra: ['market_type' => MarketType::PERPETUAL->value],
        ));

        /** @var TradeLifecycleEvent|null $closed */
        $closed = $this->em->getRepository(TradeLifecycleEvent::class)->findOneBy([
            'eventType' => 'position_closed',
            'internalTradeId' => 'itd-real',
        ]);

        self::assertNotNull($closed);
        self::assertSame('pos-real', $closed->getPositionId());
        self::assertSame('itd-real', $closed->getExtra()['internal_trade_id'] ?? null);
        self::assertSame('pos-real', $closed->getExtra()['position_id'] ?? null);
        self::assertSame('run-real', $closed->getRunId());
        self::assertSame('complete', $closed->getExtra()['cost_completeness'] ?? null);
    }

    public function testOpenedPositionLifecycleIsLoggedWhenLineageTableIsMissing(): void
    {
        (new SchemaTool($this->em))->dropSchema([
            $this->em->getClassMetadata(TradeLineage::class),
        ]);

        $listener = new TradeLifecycleLoggerListener(
            new TradeLifecycleLogger($this->em, $this->fixedClock()),
            $this->tradeLifecycleRepository(),
            null,
            $this->tradeLineageManager(),
        );

        $listener->onPositionOpened(new PositionOpenedEvent(
            position: new PositionDto(
                symbol: 'ETHUSDT',
                side: PositionSide::LONG,
                size: BigDecimal::of('2'),
                entryPrice: BigDecimal::of('1000'),
                markPrice: BigDecimal::of('1001'),
                unrealizedPnl: BigDecimal::of('0'),
                leverage: BigDecimal::of('5'),
                openedAt: new \DateTimeImmutable('2026-06-23 11:00:00 UTC'),
                raw: ['position_id' => 'pos-missing-lineage'],
            ),
            exchange: Exchange::BITMART->value,
            extra: ['market_type' => MarketType::PERPETUAL->value],
        ));

        /** @var TradeLifecycleEvent|null $opened */
        $opened = $this->em->getRepository(TradeLifecycleEvent::class)->findOneBy([
            'eventType' => 'position_opened',
            'positionId' => 'pos-missing-lineage',
        ]);

        self::assertNotNull($opened);
        self::assertSame('ETHUSDT', $opened->getSymbol());
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

    private function persistOrderSubmitted(string $runId, string $riskUsdt, \DateTimeImmutable $happenedAt, ?string $internalTradeId = null): void
    {
        $event = (new TradeLifecycleEvent('BTCUSDT', 'order_submitted', $happenedAt))
            ->setRunId($runId)
            ->setExchange(Exchange::BITMART)
            ->setMarketType(MarketType::PERPETUAL)
            ->setInternalTradeId($internalTradeId)
            ->setExtra(array_filter([
                'risk_usdt' => $riskUsdt,
                'internal_trade_id' => $internalTradeId,
            ], static fn (mixed $value): bool => $value !== null));

        $this->em->persist($event);
        $this->em->flush();
    }

    private function tradeLineageManager(): TradeLineageManager
    {
        /** @var TradeLineageRepository $repository */
        $repository = $this->em->getRepository(TradeLineage::class);

        return new TradeLineageManager($repository, $this->em, new NullLogger());
    }

    private function tradeLifecycleRepository(): TradeLifecycleEventRepository
    {
        /** @var TradeLifecycleEventRepository $repository */
        $repository = $this->em->getRepository(TradeLifecycleEvent::class);

        return $repository;
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
