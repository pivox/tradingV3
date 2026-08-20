<?php

declare(strict_types=1);

namespace App\Tests\Trading\Lineage;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Common\Enum\PositionSide;
use App\Common\Enum\Timeframe;
use App\Contract\Provider\AccountProviderInterface;
use App\Contract\Provider\ContractProviderInterface;
use App\Contract\Provider\Dto\KlineDto;
use App\Contract\Provider\KlineProviderInterface;
use App\Contract\Provider\MainProviderInterface;
use App\Contract\Provider\OrderProviderInterface;
use App\Contract\Provider\SystemProviderInterface;
use App\Entity\OrderIntent;
use App\Entity\TradeLifecycleEvent;
use App\Entity\TradeLineage;
use App\Logging\TradeLifecycleLogger;
use App\Provider\Context\ExchangeContext;
use App\Repository\TradeLifecycleEventRepository;
use App\Repository\TradeLineageRepository;
use App\Trading\Dto\PositionHistoryEntryDto;
use App\Trading\Dto\PositionDto;
use App\Trading\Event\PositionClosedEvent;
use App\Trading\Event\PositionOpenedEvent;
use App\Trading\Lineage\TradeLineageManager;
use App\Trading\Listener\TradeLifecycleLoggerListener;
use App\Trading\Pnl\CanonicalTradeFillWindow;
use App\Trading\Pnl\CanonicalTradeFillWindowResolverInterface;
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

    public function testClosedPositionRecordsMfeMaeSourceWindowAndQuality(): void
    {
        $listener = new TradeLifecycleLoggerListener(
            new TradeLifecycleLogger($this->em, $this->fixedClock()),
            $this->tradeLifecycleRepository(),
            $this->mainProviderWithKlines([
                new KlineDto('BTCUSDT', Timeframe::TF_1M, new \DateTimeImmutable('2026-06-23 10:00:00 UTC'), BigDecimal::of('100'), BigDecimal::of('105'), BigDecimal::of('98'), BigDecimal::of('101'), BigDecimal::of('1')),
                new KlineDto('BTCUSDT', Timeframe::TF_1M, new \DateTimeImmutable('2026-06-23 10:01:00 UTC'), BigDecimal::of('101'), BigDecimal::of('108'), BigDecimal::of('99'), BigDecimal::of('107'), BigDecimal::of('1')),
                new KlineDto('BTCUSDT', Timeframe::TF_1M, new \DateTimeImmutable('2026-06-23 10:02:00 UTC'), BigDecimal::of('107'), BigDecimal::of('107'), BigDecimal::of('97'), BigDecimal::of('100'), BigDecimal::of('1')),
            ]),
            null,
        );

        $listener->onPositionClosed(new PositionClosedEvent(
            positionHistory: new PositionHistoryEntryDto(
                symbol: 'BTCUSDT',
                side: PositionSide::LONG,
                size: BigDecimal::of('1'),
                entryPrice: BigDecimal::of('100'),
                exitPrice: BigDecimal::of('104'),
                realizedPnl: BigDecimal::of('4'),
                fees: null,
                openedAt: new \DateTimeImmutable('2026-06-23 10:00:00 UTC'),
                closedAt: new \DateTimeImmutable('2026-06-23 10:03:00 UTC'),
                raw: ['position_id' => 'pos-mfe-mae'],
            ),
            runId: 'run-mfe-mae',
            exchange: Exchange::FAKE->value,
            extra: ['market_type' => MarketType::PERPETUAL->value],
        ));

        /** @var TradeLifecycleEvent|null $closed */
        $closed = $this->em->getRepository(TradeLifecycleEvent::class)->findOneBy([
            'eventType' => 'position_closed',
            'positionId' => 'pos-mfe-mae',
        ]);

        self::assertNotNull($closed);
        $extra = $closed->getExtra();
        self::assertSame('kline_1m_high_low', $extra['mfe_mae_source'] ?? null);
        self::assertSame('1m', $extra['mfe_mae_timeframe'] ?? null);
        self::assertSame('complete', $extra['mfe_mae_data_quality'] ?? null);
        self::assertSame(3, $extra['mfe_mae_sample_count'] ?? null);
        self::assertSame(3, $extra['mfe_mae_expected_sample_count'] ?? null);
        self::assertSame('2026-06-23T10:00:00.000000+00:00', $extra['mfe_mae_window_start'] ?? null);
        self::assertSame('2026-06-23T10:03:00.000000+00:00', $extra['mfe_mae_window_end'] ?? null);
        self::assertSame('2026-06-23T10:01:00+00:00', $extra['mfe_at'] ?? null);
        self::assertSame('2026-06-23T10:02:00+00:00', $extra['mae_at'] ?? null);
        self::assertSame(108.0, $extra['max_favorable_price'] ?? null);
        self::assertSame(97.0, $extra['max_adverse_price'] ?? null);
    }

    public function testClosedPositionUsesExactLedgerFillWindowForHoldingTimeAndMfeMae(): void
    {
        $lineage = $this->persistLineageWithPosition();
        $fillWindowResolver = new class($lineage->getInternalTradeId()) implements CanonicalTradeFillWindowResolverInterface {
            public function __construct(private readonly string $internalTradeId)
            {
            }

            public function resolve(string $internalTradeId, string $exchange, string $marketType): ?CanonicalTradeFillWindow
            {
                if ($internalTradeId !== $this->internalTradeId || $exchange !== 'fake' || $marketType !== 'perpetual') {
                    return null;
                }

                return new CanonicalTradeFillWindow(
                    entryFirstFillAt: new \DateTimeImmutable('2026-06-23 10:01:00 UTC'),
                    exitLastFillAt: new \DateTimeImmutable('2026-06-23 10:04:31 UTC'),
                    entryVwap: 101.0,
                );
            }
        };
        $listener = new TradeLifecycleLoggerListener(
            new TradeLifecycleLogger($this->em, $this->fixedClock()),
            $this->tradeLifecycleRepository(),
            $this->mainProviderWithKlines([
                new KlineDto('BTCUSDT', Timeframe::TF_1M, new \DateTimeImmutable('2026-06-23 10:00:00 UTC'), BigDecimal::of('100'), BigDecimal::of('999'), BigDecimal::of('1'), BigDecimal::of('101'), BigDecimal::of('1')),
                new KlineDto('BTCUSDT', Timeframe::TF_1M, new \DateTimeImmutable('2026-06-23 10:01:00 UTC'), BigDecimal::of('101'), BigDecimal::of('106'), BigDecimal::of('99'), BigDecimal::of('105'), BigDecimal::of('1')),
                new KlineDto('BTCUSDT', Timeframe::TF_1M, new \DateTimeImmutable('2026-06-23 10:02:00 UTC'), BigDecimal::of('105'), BigDecimal::of('111'), BigDecimal::of('103'), BigDecimal::of('109'), BigDecimal::of('1')),
                new KlineDto('BTCUSDT', Timeframe::TF_1M, new \DateTimeImmutable('2026-06-23 10:03:00 UTC'), BigDecimal::of('109'), BigDecimal::of('110'), BigDecimal::of('98'), BigDecimal::of('100'), BigDecimal::of('1')),
                new KlineDto('BTCUSDT', Timeframe::TF_1M, new \DateTimeImmutable('2026-06-23 10:04:00 UTC'), BigDecimal::of('100'), BigDecimal::of('999'), BigDecimal::of('1'), BigDecimal::of('101'), BigDecimal::of('1')),
            ]),
            $this->tradeLineageManager(),
            $fillWindowResolver,
        );

        $listener->onPositionClosed(new PositionClosedEvent(
            positionHistory: new PositionHistoryEntryDto(
                symbol: 'BTCUSDT',
                side: PositionSide::LONG,
                size: BigDecimal::of('1'),
                entryPrice: BigDecimal::of('100'),
                exitPrice: BigDecimal::of('104'),
                realizedPnl: BigDecimal::of('4'),
                fees: null,
                openedAt: new \DateTimeImmutable('2026-06-23 10:00:00 UTC'),
                closedAt: new \DateTimeImmutable('2026-06-23 10:05:00 UTC'),
                raw: ['position_id' => 'pos-real'],
            ),
            runId: null,
            exchange: Exchange::FAKE->value,
            extra: [
                'market_type' => MarketType::PERPETUAL->value,
                'holding_time_sec' => 999,
                'holding_time_source' => 'spoofed_provider_extra',
                'mfe_mae_window_source' => 'spoofed_provider_extra',
            ],
        ));

        /** @var TradeLifecycleEvent|null $closed */
        $closed = $this->em->getRepository(TradeLifecycleEvent::class)->findOneBy([
            'eventType' => 'position_closed',
            'positionId' => 'pos-real',
        ]);

        self::assertNotNull($closed);
        $extra = $closed->getExtra();
        self::assertSame(211, $extra['holding_time_sec'] ?? null);
        self::assertSame('fill_cost_ledger_v1', $extra['holding_time_source'] ?? null);
        self::assertSame('2026-06-23T10:01:00.000000+00:00', $extra['mfe_mae_window_start'] ?? null);
        self::assertSame('2026-06-23T10:04:31.000000+00:00', $extra['mfe_mae_window_end'] ?? null);
        self::assertSame('fill_cost_ledger_v1', $extra['mfe_mae_window_source'] ?? null);
        self::assertSame('fill_cost_ledger_v1', $extra['mfe_mae_entry_price_source'] ?? null);
        self::assertSame(101.0, $extra['mfe_mae_entry_price'] ?? null);
        self::assertEqualsWithDelta((111.0 - 101.0) / 101.0, $extra['mfe_pct'] ?? 0.0, 1e-12);
        self::assertEqualsWithDelta((101.0 - 98.0) / 101.0, $extra['mae_pct'] ?? 0.0, 1e-12);
        self::assertSame('partial', $extra['mfe_mae_data_quality'] ?? null);
    }

    public function testClosedPositionPreservesSubsecondLedgerFillWindowEvidence(): void
    {
        $lineage = $this->persistLineageWithPosition();
        $fillWindowResolver = new class($lineage->getInternalTradeId()) implements CanonicalTradeFillWindowResolverInterface {
            public function __construct(private readonly string $internalTradeId)
            {
            }

            public function resolve(string $internalTradeId, string $exchange, string $marketType): ?CanonicalTradeFillWindow
            {
                if ($internalTradeId !== $this->internalTradeId || $exchange !== 'fake' || $marketType !== 'perpetual') {
                    return null;
                }

                return new CanonicalTradeFillWindow(
                    entryFirstFillAt: new \DateTimeImmutable('2026-06-23T10:01:00.123456+00:00'),
                    exitLastFillAt: new \DateTimeImmutable('2026-06-23T10:04:00.654321+00:00'),
                    entryVwap: 101.0,
                );
            }
        };
        $listener = new TradeLifecycleLoggerListener(
            new TradeLifecycleLogger($this->em, $this->fixedClock()),
            $this->tradeLifecycleRepository(),
            null,
            $this->tradeLineageManager(),
            $fillWindowResolver,
        );

        $listener->onPositionClosed(new PositionClosedEvent(
            positionHistory: new PositionHistoryEntryDto(
                symbol: 'BTCUSDT',
                side: PositionSide::LONG,
                size: BigDecimal::of('1'),
                entryPrice: BigDecimal::of('100'),
                exitPrice: BigDecimal::of('104'),
                realizedPnl: BigDecimal::of('4'),
                fees: null,
                openedAt: new \DateTimeImmutable('2026-06-23 10:00:00 UTC'),
                closedAt: new \DateTimeImmutable('2026-06-23 10:05:00 UTC'),
                raw: ['position_id' => 'pos-real'],
            ),
            runId: null,
            exchange: Exchange::FAKE->value,
            extra: ['market_type' => MarketType::PERPETUAL->value],
        ));

        /** @var TradeLifecycleEvent|null $closed */
        $closed = $this->em->getRepository(TradeLifecycleEvent::class)->findOneBy([
            'eventType' => 'position_closed',
            'positionId' => 'pos-real',
        ]);

        self::assertNotNull($closed);
        $extra = $closed->getExtra();
        self::assertSame('2026-06-23T10:01:00.123456+00:00', $extra['mfe_mae_window_start'] ?? null);
        self::assertSame('2026-06-23T10:04:00.654321+00:00', $extra['mfe_mae_window_end'] ?? null);
        self::assertEqualsWithDelta(180.530865, (float) ($extra['holding_time_sec'] ?? 0.0), 1e-6);
        self::assertSame('fill_cost_ledger_v1', $extra['holding_time_source'] ?? null);
    }

    public function testLateCompleteFillWindowRefreshesExistingClosedPositionEvidence(): void
    {
        $lineage = $this->persistLineageWithPosition();
        $fillWindowResolver = new class($lineage->getInternalTradeId()) implements CanonicalTradeFillWindowResolverInterface {
            public ?CanonicalTradeFillWindow $window = null;

            public function __construct(private readonly string $internalTradeId)
            {
            }

            public function resolve(string $internalTradeId, string $exchange, string $marketType): ?CanonicalTradeFillWindow
            {
                if ($internalTradeId !== $this->internalTradeId || $exchange !== 'fake' || $marketType !== 'perpetual') {
                    return null;
                }

                return $this->window;
            }
        };
        $listener = new TradeLifecycleLoggerListener(
            new TradeLifecycleLogger($this->em, $this->fixedClock()),
            $this->tradeLifecycleRepository(),
            $this->mainProviderWithKlines([
                new KlineDto('BTCUSDT', Timeframe::TF_1M, new \DateTimeImmutable('2026-06-23 10:00:00 UTC'), BigDecimal::of('100'), BigDecimal::of('999'), BigDecimal::of('1'), BigDecimal::of('101'), BigDecimal::of('1')),
                new KlineDto('BTCUSDT', Timeframe::TF_1M, new \DateTimeImmutable('2026-06-23 10:01:00 UTC'), BigDecimal::of('101'), BigDecimal::of('106'), BigDecimal::of('99'), BigDecimal::of('105'), BigDecimal::of('1')),
                new KlineDto('BTCUSDT', Timeframe::TF_1M, new \DateTimeImmutable('2026-06-23 10:02:00 UTC'), BigDecimal::of('105'), BigDecimal::of('111'), BigDecimal::of('103'), BigDecimal::of('109'), BigDecimal::of('1')),
                new KlineDto('BTCUSDT', Timeframe::TF_1M, new \DateTimeImmutable('2026-06-23 10:03:00 UTC'), BigDecimal::of('109'), BigDecimal::of('110'), BigDecimal::of('98'), BigDecimal::of('100'), BigDecimal::of('1')),
                new KlineDto('BTCUSDT', Timeframe::TF_1M, new \DateTimeImmutable('2026-06-23 10:04:00 UTC'), BigDecimal::of('100'), BigDecimal::of('999'), BigDecimal::of('1'), BigDecimal::of('101'), BigDecimal::of('1')),
            ]),
            $this->tradeLineageManager(),
            $fillWindowResolver,
        );

        $listener->onPositionClosed(new PositionClosedEvent(
            positionHistory: new PositionHistoryEntryDto(
                symbol: 'BTCUSDT',
                side: PositionSide::LONG,
                size: BigDecimal::of('1'),
                entryPrice: BigDecimal::of('100'),
                exitPrice: BigDecimal::of('104'),
                realizedPnl: BigDecimal::of('4'),
                fees: null,
                openedAt: new \DateTimeImmutable('2026-06-23 10:00:00 UTC'),
                closedAt: new \DateTimeImmutable('2026-06-23 10:05:00 UTC'),
                raw: ['position_id' => 'pos-real'],
            ),
            runId: null,
            exchange: Exchange::FAKE->value,
            extra: ['market_type' => MarketType::PERPETUAL->value],
        ));

        /** @var TradeLifecycleEvent|null $closed */
        $closed = $this->em->getRepository(TradeLifecycleEvent::class)->findOneBy([
            'eventType' => 'position_closed',
            'positionId' => 'pos-real',
        ]);
        self::assertNotNull($closed);
        self::assertSame('provider_position_history', $closed->getExtra()['holding_time_source'] ?? null);

        $fillWindowResolver->window = new CanonicalTradeFillWindow(
            entryFirstFillAt: new \DateTimeImmutable('2026-06-23 10:01:00 UTC'),
            exitLastFillAt: new \DateTimeImmutable('2026-06-23 10:04:00 UTC'),
            entryVwap: 101.0,
        );

        $listener->refreshAfterFill($lineage->getInternalTradeId(), 'fake', 'perpetual');

        $extra = $closed->getExtra();
        self::assertSame(180, $extra['holding_time_sec'] ?? null);
        self::assertSame('fill_cost_ledger_v1', $extra['holding_time_source']);
        self::assertSame('2026-06-23T10:01:00.000000+00:00', $extra['mfe_mae_window_start'] ?? null);
        self::assertSame('2026-06-23T10:04:00.000000+00:00', $extra['mfe_mae_window_end'] ?? null);
        self::assertSame('fill_cost_ledger_v1', $extra['mfe_mae_window_source'] ?? null);
        self::assertSame('fill_cost_ledger_v1', $extra['mfe_mae_entry_price_source'] ?? null);
        self::assertSame(101.0, $extra['mfe_mae_entry_price'] ?? null);
        self::assertSame(111.0, $extra['max_favorable_price'] ?? null);
        self::assertSame(98.0, $extra['max_adverse_price'] ?? null);
        self::assertSame('complete', $extra['mfe_mae_data_quality'] ?? null);
    }

    public function testRefreshPreservesExistingCompleteExcursionEvidenceWhenReplacementIsNotComplete(): void
    {
        $internalTradeId = 'itd-refresh-provider-error';
        $window = new CanonicalTradeFillWindow(
            entryFirstFillAt: new \DateTimeImmutable('2026-06-23 10:01:00 UTC'),
            exitLastFillAt: new \DateTimeImmutable('2026-06-23 10:04:00 UTC'),
            entryVwap: 101.0,
        );
        $resolver = new class($internalTradeId, $window) implements CanonicalTradeFillWindowResolverInterface {
            public function __construct(
                private readonly string $internalTradeId,
                public CanonicalTradeFillWindow $window,
            ) {
            }

            public function resolve(string $internalTradeId, string $exchange, string $marketType): ?CanonicalTradeFillWindow
            {
                return $internalTradeId === $this->internalTradeId && $exchange === 'fake' && $marketType === 'perpetual'
                    ? $this->window
                    : null;
            }
        };
        $originalEvidence = [
            'holding_time_sec' => 180,
            'holding_time_source' => 'fill_cost_ledger_v1',
            'max_favorable_price' => 111.0,
            'max_adverse_price' => 98.0,
            'mfe_pct' => (111.0 - 101.0) / 101.0,
            'mae_pct' => (101.0 - 98.0) / 101.0,
            'mfe_at' => '2026-06-23T10:02:00+00:00',
            'mae_at' => '2026-06-23T10:03:00+00:00',
            'mfe_mae_source' => 'kline_1m_high_low',
            'mfe_mae_timeframe' => '1m',
            'mfe_mae_window_start' => '2026-06-23T10:01:00.000000+00:00',
            'mfe_mae_window_end' => '2026-06-23T10:04:00.000000+00:00',
            'mfe_mae_window_source' => 'fill_cost_ledger_v1',
            'mfe_mae_entry_price_source' => 'fill_cost_ledger_v1',
            'mfe_mae_entry_price' => 101.0,
            'mfe_mae_sample_count' => 3,
            'mfe_mae_expected_sample_count' => 3,
            'mfe_mae_limit' => 500,
            'mfe_mae_data_quality' => 'complete',
        ];
        $closed = (new TradeLifecycleEvent('BTCUSDT', 'position_closed'))
            ->setInternalTradeId($internalTradeId)
            ->setSide('LONG')
            ->setExchange(Exchange::FAKE)
            ->setMarketType(MarketType::PERPETUAL)
            ->setExtra($originalEvidence);
        $this->em->persist($closed);
        $this->em->flush();

        $listener = new TradeLifecycleLoggerListener(
            new TradeLifecycleLogger($this->em, $this->fixedClock()),
            $this->tradeLifecycleRepository(),
            $this->mainProviderWithKlines([], fail: true),
            null,
            $resolver,
        );

        $listener->refreshAfterFill($internalTradeId, 'fake', 'perpetual');

        self::assertSame($originalEvidence, $closed->getExtra());

        $partialListener = new TradeLifecycleLoggerListener(
            new TradeLifecycleLogger($this->em, $this->fixedClock()),
            $this->tradeLifecycleRepository(),
            $this->mainProviderWithKlines([
                new KlineDto('BTCUSDT', Timeframe::TF_1M, new \DateTimeImmutable('2026-06-23 10:01:00 UTC'), BigDecimal::of('101'), BigDecimal::of('106'), BigDecimal::of('99'), BigDecimal::of('105'), BigDecimal::of('1')),
            ]),
            null,
            $resolver,
        );

        $partialListener->refreshAfterFill($internalTradeId, 'fake', 'perpetual');

        self::assertSame($originalEvidence, $closed->getExtra());

        $resolver->window = new CanonicalTradeFillWindow(
            entryFirstFillAt: new \DateTimeImmutable('2026-06-23 10:01:00 UTC'),
            exitLastFillAt: new \DateTimeImmutable('2026-06-23 10:04:00 UTC'),
            entryVwap: 102.0,
        );
        $listener->refreshAfterFill($internalTradeId, 'fake', 'perpetual');

        self::assertSame('partial', $closed->getExtra()['mfe_mae_data_quality']);
        self::assertSame(101.0, $closed->getExtra()['mfe_mae_entry_price']);
    }

    public function testClosedPositionMarksMfeMaePartialWhenWindowHasMissingKlines(): void
    {
        $listener = new TradeLifecycleLoggerListener(
            new TradeLifecycleLogger($this->em, $this->fixedClock()),
            $this->tradeLifecycleRepository(),
            $this->mainProviderWithKlines([
                new KlineDto('BTCUSDT', Timeframe::TF_1M, new \DateTimeImmutable('2026-06-23 10:00:00 UTC'), BigDecimal::of('100'), BigDecimal::of('105'), BigDecimal::of('98'), BigDecimal::of('101'), BigDecimal::of('1')),
                new KlineDto('BTCUSDT', Timeframe::TF_1M, new \DateTimeImmutable('2026-06-23 10:01:00 UTC'), BigDecimal::of('101'), BigDecimal::of('108'), BigDecimal::of('99'), BigDecimal::of('107'), BigDecimal::of('1')),
                new KlineDto('BTCUSDT', Timeframe::TF_1M, new \DateTimeImmutable('2026-06-23 10:02:00 UTC'), BigDecimal::of('107'), BigDecimal::of('107'), BigDecimal::of('97'), BigDecimal::of('100'), BigDecimal::of('1')),
            ]),
            null,
        );

        $listener->onPositionClosed(new PositionClosedEvent(
            positionHistory: new PositionHistoryEntryDto(
                symbol: 'BTCUSDT',
                side: PositionSide::LONG,
                size: BigDecimal::of('1'),
                entryPrice: BigDecimal::of('100'),
                exitPrice: BigDecimal::of('104'),
                realizedPnl: BigDecimal::of('4'),
                fees: null,
                openedAt: new \DateTimeImmutable('2026-06-23 10:00:00 UTC'),
                closedAt: new \DateTimeImmutable('2026-06-23 10:05:00 UTC'),
                raw: ['position_id' => 'pos-mfe-mae-partial'],
            ),
            runId: 'run-mfe-mae-partial',
            exchange: Exchange::FAKE->value,
            extra: ['market_type' => MarketType::PERPETUAL->value],
        ));

        /** @var TradeLifecycleEvent|null $closed */
        $closed = $this->em->getRepository(TradeLifecycleEvent::class)->findOneBy([
            'eventType' => 'position_closed',
            'positionId' => 'pos-mfe-mae-partial',
        ]);

        self::assertNotNull($closed);
        $extra = $closed->getExtra();
        self::assertSame('partial', $extra['mfe_mae_data_quality'] ?? null);
        self::assertSame(3, $extra['mfe_mae_sample_count'] ?? null);
        self::assertSame(5, $extra['mfe_mae_expected_sample_count'] ?? null);
        self::assertSame(108.0, $extra['max_favorable_price'] ?? null);
        self::assertSame(97.0, $extra['max_adverse_price'] ?? null);
    }

    public function testClosedPositionDoesNotUseCloseBoundaryCandleForMfeMae(): void
    {
        $listener = new TradeLifecycleLoggerListener(
            new TradeLifecycleLogger($this->em, $this->fixedClock()),
            $this->tradeLifecycleRepository(),
            $this->mainProviderWithKlines([
                new KlineDto('BTCUSDT', Timeframe::TF_1M, new \DateTimeImmutable('2026-06-23 10:00:00 UTC'), BigDecimal::of('100'), BigDecimal::of('105'), BigDecimal::of('98'), BigDecimal::of('101'), BigDecimal::of('1')),
                new KlineDto('BTCUSDT', Timeframe::TF_1M, new \DateTimeImmutable('2026-06-23 10:01:00 UTC'), BigDecimal::of('101'), BigDecimal::of('103'), BigDecimal::of('99'), BigDecimal::of('102'), BigDecimal::of('1')),
                new KlineDto('BTCUSDT', Timeframe::TF_1M, new \DateTimeImmutable('2026-06-23 10:02:00 UTC'), BigDecimal::of('102'), BigDecimal::of('120'), BigDecimal::of('80'), BigDecimal::of('110'), BigDecimal::of('1')),
            ]),
            null,
        );

        $listener->onPositionClosed(new PositionClosedEvent(
            positionHistory: new PositionHistoryEntryDto(
                symbol: 'BTCUSDT',
                side: PositionSide::LONG,
                size: BigDecimal::of('1'),
                entryPrice: BigDecimal::of('100'),
                exitPrice: BigDecimal::of('104'),
                realizedPnl: BigDecimal::of('4'),
                fees: null,
                openedAt: new \DateTimeImmutable('2026-06-23 10:00:00 UTC'),
                closedAt: new \DateTimeImmutable('2026-06-23 10:02:00 UTC'),
                raw: ['position_id' => 'pos-mfe-mae-close-boundary'],
            ),
            runId: 'run-mfe-mae-close-boundary',
            exchange: Exchange::FAKE->value,
            extra: ['market_type' => MarketType::PERPETUAL->value],
        ));

        /** @var TradeLifecycleEvent|null $closed */
        $closed = $this->em->getRepository(TradeLifecycleEvent::class)->findOneBy([
            'eventType' => 'position_closed',
            'positionId' => 'pos-mfe-mae-close-boundary',
        ]);

        self::assertNotNull($closed);
        $extra = $closed->getExtra();
        self::assertSame('complete', $extra['mfe_mae_data_quality'] ?? null);
        self::assertSame(2, $extra['mfe_mae_sample_count'] ?? null);
        self::assertSame(2, $extra['mfe_mae_expected_sample_count'] ?? null);
        self::assertSame(105.0, $extra['max_favorable_price'] ?? null);
        self::assertSame(98.0, $extra['max_adverse_price'] ?? null);
        self::assertSame('2026-06-23T10:00:00+00:00', $extra['mfe_at'] ?? null);
        self::assertSame('2026-06-23T10:00:00+00:00', $extra['mae_at'] ?? null);
    }

    public function testClosedPositionRejectsCandlesThatExtendBeyondNonAlignedWindow(): void
    {
        $listener = new TradeLifecycleLoggerListener(
            new TradeLifecycleLogger($this->em, $this->fixedClock()),
            $this->tradeLifecycleRepository(),
            $this->mainProviderWithKlines([
                new KlineDto('BTCUSDT', Timeframe::TF_1M, new \DateTimeImmutable('2026-06-23 10:01:00 UTC'), BigDecimal::of('101'), BigDecimal::of('108'), BigDecimal::of('99'), BigDecimal::of('107'), BigDecimal::of('1')),
            ]),
            null,
        );

        $listener->onPositionClosed(new PositionClosedEvent(
            positionHistory: new PositionHistoryEntryDto(
                symbol: 'BTCUSDT',
                side: PositionSide::LONG,
                size: BigDecimal::of('1'),
                entryPrice: BigDecimal::of('100'),
                exitPrice: BigDecimal::of('104'),
                realizedPnl: BigDecimal::of('4'),
                fees: null,
                openedAt: new \DateTimeImmutable('2026-06-23 10:00:30 UTC'),
                closedAt: new \DateTimeImmutable('2026-06-23 10:01:29 UTC'),
                raw: ['position_id' => 'pos-mfe-mae-mid-minute'],
            ),
            runId: 'run-mfe-mae-mid-minute',
            exchange: Exchange::FAKE->value,
            extra: ['market_type' => MarketType::PERPETUAL->value],
        ));

        /** @var TradeLifecycleEvent|null $closed */
        $closed = $this->em->getRepository(TradeLifecycleEvent::class)->findOneBy([
            'eventType' => 'position_closed',
            'positionId' => 'pos-mfe-mae-mid-minute',
        ]);

        self::assertNotNull($closed);
        $extra = $closed->getExtra();
        self::assertSame('missing_price_data', $extra['mfe_mae_data_quality'] ?? null);
        self::assertSame(0, $extra['mfe_mae_sample_count'] ?? null);
        self::assertSame(1, $extra['mfe_mae_expected_sample_count'] ?? null);
        self::assertSame('2026-06-23T10:00:30.000000+00:00', $extra['mfe_mae_window_start'] ?? null);
        self::assertSame('2026-06-23T10:01:29.000000+00:00', $extra['mfe_mae_window_end'] ?? null);
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

    /**
     * @param list<KlineDto> $klines
     */
    private function mainProviderWithKlines(array $klines, bool $fail = false): MainProviderInterface
    {
        $klineProvider = new class($klines, $fail) implements KlineProviderInterface {
            /**
             * @param list<KlineDto> $klines
             */
            public function __construct(
                private readonly array $klines,
                private readonly bool $fail,
            ) {
            }

            /**
             * @return list<KlineDto>
             */
            public function getKlines(string $symbol, Timeframe $timeframe, int $limit = 490, ?ExchangeContext $context = null): array
            {
                return array_slice($this->klines, 0, $limit);
            }

            /**
             * @return list<KlineDto>
             */
            public function getKlinesInWindow(string $symbol, Timeframe $timeframe, \DateTimeImmutable $start, \DateTimeImmutable $end, int $limit = 500, ?ExchangeContext $context = null): array
            {
                if ($this->fail) {
                    throw new \RuntimeException('Synthetic kline provider outage.');
                }

                return array_slice($this->klines, 0, $limit);
            }

            public function getLastKline(string $symbol, Timeframe $timeframe, ?ExchangeContext $context = null): ?KlineDto
            {
                return $this->klines[0] ?? null;
            }

            public function saveKline(KlineDto $kline, ?ExchangeContext $context = null): void
            {
            }

            /**
             * @param list<KlineDto> $klines
             */
            public function saveKlines(array $klines, string $symbol, Timeframe $timeframe, ?ExchangeContext $context = null): void
            {
            }

            public function hasGaps(string $symbol, Timeframe $timeframe, ?ExchangeContext $context = null): bool
            {
                return false;
            }

            /**
             * @return list<mixed>
             */
            public function getGaps(string $symbol, Timeframe $timeframe, ?ExchangeContext $context = null): array
            {
                return [];
            }
        };

        return new class($klineProvider) implements MainProviderInterface {
            public function __construct(private readonly KlineProviderInterface $klineProvider)
            {
            }

            public function getKlineProvider(): KlineProviderInterface
            {
                return $this->klineProvider;
            }

            public function getContractProvider(): ContractProviderInterface
            {
                throw new \LogicException('Contract provider is not used by this test.');
            }

            public function getOrderProvider(): ?OrderProviderInterface
            {
                return null;
            }

            public function getAccountProvider(): ?AccountProviderInterface
            {
                return null;
            }

            public function getSystemProvider(): SystemProviderInterface
            {
                throw new \LogicException('System provider is not used by this test.');
            }

            public function forContext(?ExchangeContext $context = null): self
            {
                return $this;
            }
        };
    }
}
