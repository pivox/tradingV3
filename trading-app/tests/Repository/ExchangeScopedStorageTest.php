<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Common\Enum\Timeframe;
use App\Entity\FuturesOrder;
use App\Entity\IndicatorSnapshot;
use App\Entity\MtfState;
use App\Entity\OrderIntent;
use App\Entity\OrderProtection;
use App\Entity\Position;
use App\Entity\SymbolExecutionLock;
use App\MtfValidator\Entity\MtfAudit;
use App\MtfValidator\Repository\MtfAuditRepository;
use App\Provider\Context\ExchangeContext;
use App\Provider\Entity\Contract;
use App\Provider\Entity\Kline;
use App\Provider\Repository\ContractRepository;
use App\Provider\Repository\KlineRepository;
use App\Repository\FuturesOrderRepository;
use App\Repository\IndicatorSnapshotRepository;
use App\Repository\MtfStateRepository;
use App\Repository\OrderIntentRepository;
use App\Repository\PositionRepository;
use App\Service\OrderIntentManager;
use App\Service\SymbolExecutionLockManager;
use App\TradeEntry\Idempotency\DecisionKeyFactory;
use App\Trading\Storage\FuturesOrderOrderStateRepository;
use App\Trading\Storage\PositionPositionStateRepository;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversNothing]
final class ExchangeScopedStorageTest extends KernelTestCase
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
            [
                Contract::class,
                FuturesOrder::class,
                Kline::class,
                Position::class,
                OrderIntent::class,
                OrderProtection::class,
                SymbolExecutionLock::class,
                IndicatorSnapshot::class,
                MtfState::class,
                MtfAudit::class,
            ],
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
                [
                    Contract::class,
                    FuturesOrder::class,
                    Kline::class,
                    Position::class,
                    OrderIntent::class,
                    OrderProtection::class,
                    SymbolExecutionLock::class,
                    IndicatorSnapshot::class,
                    MtfState::class,
                    MtfAudit::class,
                ],
            );
            (new SchemaTool($this->em))->dropSchema($metadata);
            $this->em->close();
        }

        parent::tearDown();
    }

    public function testContractRepositoryDefaultsToLegacyContextAndCanSelectAnotherExchange(): void
    {
        $bitmart = (new Contract())
            ->setExchange(Exchange::BITMART)
            ->setMarketType(MarketType::PERPETUAL)
            ->setSymbol('BTCUSDT')
            ->setName('Bitmart BTC');

        $binance = (new Contract())
            ->setExchange(Exchange::BINANCE)
            ->setMarketType(MarketType::PERPETUAL)
            ->setSymbol('BTCUSDT')
            ->setName('Binance BTC');

        $this->em->persist($bitmart);
        $this->em->persist($binance);
        $this->em->flush();

        /** @var ContractRepository $repository */
        $repository = $this->em->getRepository(Contract::class);

        self::assertSame('Bitmart BTC', $repository->findBySymbol('BTCUSDT')?->getName());
        self::assertSame(
            'Binance BTC',
            $repository->findBySymbol(
                'BTCUSDT',
                new ExchangeContext(Exchange::BINANCE, MarketType::PERPETUAL),
            )?->getName(),
        );
    }

    public function testKlineRepositorySeparatesSameSymbolTimeframeAndOpenTime(): void
    {
        $openTime = new \DateTimeImmutable('2026-05-31 00:00:00', new \DateTimeZone('UTC'));

        $this->em->persist($this->newKline('bitmart', '100', $openTime));
        $this->em->persist($this->newKline('binance', '200', $openTime));
        $this->em->flush();

        /** @var KlineRepository $repository */
        $repository = $this->em->getRepository(Kline::class);

        self::assertSame('100.000000000000', $repository->getKlines('BTCUSDT', Timeframe::TF_1M, 1)[0]->getClosePrice()->__toString());
        self::assertSame(
            '200.000000000000',
            $repository->getKlines(
                'BTCUSDT',
                Timeframe::TF_1M,
                1,
                new ExchangeContext(Exchange::BINANCE, MarketType::PERPETUAL),
            )[0]->getClosePrice()->__toString(),
        );
    }

    public function testIndicatorSnapshotUpsertUsesExchangeAndMarketType(): void
    {
        $klineTime = new \DateTimeImmutable('2026-05-31 00:00:00', new \DateTimeZone('UTC'));

        /** @var IndicatorSnapshotRepository $repository */
        $repository = $this->em->getRepository(IndicatorSnapshot::class);

        $repository->upsert($this->newSnapshot('bitmart', $klineTime, ['rsi' => 51]));
        $repository->upsert(
            $this->newSnapshot('binance', $klineTime, ['rsi' => 61]),
            new ExchangeContext(Exchange::BINANCE, MarketType::PERPETUAL),
        );
        $repository->upsert($this->newSnapshot('bitmart', $klineTime, ['rsi' => 52]));

        self::assertSame(52, $repository->findLastBySymbolAndTimeframe('BTCUSDT', Timeframe::TF_1M)?->getValue('rsi'));
        self::assertSame(
            61,
            $repository->findLastBySymbolAndTimeframe(
                'BTCUSDT',
                Timeframe::TF_1M,
                new ExchangeContext(Exchange::BINANCE, MarketType::PERPETUAL),
            )?->getValue('rsi'),
        );
    }

    public function testPositionAndOrderIntentRepositoriesFallbackToBitmartOnly(): void
    {
        $this->em->persist((new Position('BTCUSDT', 'LONG'))->setSize('1'));
        $this->em->persist((new Position('BTCUSDT', 'LONG', Exchange::BINANCE, MarketType::PERPETUAL))->setSize('2'));

        $bitmartIntent = $this->newIntent('bitmart', 'shared-client');
        $binanceIntent = $this->newIntent('binance', 'shared-client');
        $this->em->persist($bitmartIntent);
        $this->em->persist($binanceIntent);
        $this->em->flush();

        /** @var PositionRepository $positionRepository */
        $positionRepository = $this->em->getRepository(Position::class);
        /** @var OrderIntentRepository $intentRepository */
        $intentRepository = $this->em->getRepository(OrderIntent::class);
        $binanceContext = new ExchangeContext(Exchange::BINANCE, MarketType::PERPETUAL);

        self::assertSame('1', $positionRepository->findOneBySymbolSide('BTCUSDT', 'LONG')?->getSize());
        self::assertSame('2', $positionRepository->findOneBySymbolSide('BTCUSDT', 'LONG', $binanceContext)?->getSize());
        self::assertSame('bitmart', $intentRepository->findOneByClientOrderId('shared-client')?->getExchange());
        self::assertSame('binance', $intentRepository->findOneByClientOrderId('shared-client', $binanceContext)?->getExchange());
    }

    public function testOrderIntentReservationBlocksReplayAfterFirstReservationWithoutMutatingIntent(): void
    {
        $manager = $this->orderIntentManager();
        $params = $this->orderIntentParams();
        $replayParams = $this->orderIntentParams(
            decisionKey: (string) $params['decision_key'],
            clientOrderId: 'cid-replay-mutated',
            price: '200',
            size: 2,
        );

        $first = $manager->reserveIntent($params);
        $second = $manager->reserveIntent($replayParams);

        self::assertTrue($first->created);
        self::assertFalse($first->blocked);
        self::assertFalse($second->created);
        self::assertTrue($second->blocked);
        self::assertSame('idempotent_in_flight', $second->reason);
        self::assertSame($first->intent->getId(), $second->intent->getId());
        self::assertSame('cid-reservation', $second->intent->getClientOrderId());
        self::assertSame('100', $second->intent->getPrice());
        self::assertSame(1, $second->intent->getSize());
        self::assertCount(1, $this->em->getRepository(OrderIntent::class)->findBy([
            'exchange' => 'bitmart',
            'marketType' => 'perpetual',
            'decisionKey' => $params['decision_key'],
        ]));
    }

    public function testOrderIntentReservationReportsInFlightReasonForDraftValidatedAndReadyStates(): void
    {
        $manager = $this->orderIntentManager();
        $cases = [
            OrderIntent::STATUS_DRAFT,
            OrderIntent::STATUS_VALIDATED,
            OrderIntent::STATUS_READY_TO_SEND,
        ];

        foreach ($cases as $index => $status) {
            $symbol = ['BTCUSDT', 'ETHUSDT', 'SOLUSDT'][$index];
            $params = $this->orderIntentParams(
                decisionKey: sprintf('bitmart:perpetual:%s:1m:%d:long:scalper_micro:v1', $symbol, 1764161200 + ($index * 60)),
                clientOrderId: 'cid-' . strtolower($status),
                symbol: $symbol,
            );
            $first = $manager->reserveIntent($params);

            if ($status === OrderIntent::STATUS_VALIDATED) {
                self::assertTrue($manager->validateIntent($first->intent));
            } elseif ($status === OrderIntent::STATUS_READY_TO_SEND) {
                self::assertTrue($manager->validateIntent($first->intent));
                $manager->markReadyToSend($first->intent);
            }

            $second = $manager->reserveIntent($params);

            self::assertTrue($second->blocked);
            self::assertSame('idempotent_in_flight', $second->reason);
            self::assertSame($first->intent->getId(), $second->intent->getId());
        }
    }

    public function testOrderIntentReservationReportsReplayBlockReasonsByStatus(): void
    {
        $manager = $this->orderIntentManager();
        $cases = [
            OrderIntent::STATUS_SENT => 'idempotent_sent_replay',
            OrderIntent::STATUS_FAILED => 'idempotent_failed_not_replayed',
            OrderIntent::STATUS_CANCELLED => 'idempotent_cancelled_not_replayed',
        ];

        $index = 1;
        foreach ($cases as $status => $expectedReason) {
            $symbol = match ($status) {
                OrderIntent::STATUS_SENT => 'BTCUSDT',
                OrderIntent::STATUS_FAILED => 'ETHUSDT',
                OrderIntent::STATUS_CANCELLED => 'SOLUSDT',
                default => 'XRPUSDT',
            };
            $params = $this->orderIntentParams(
                decisionKey: sprintf('bitmart:perpetual:%s:1m:%d:long:scalper_micro:v1', $symbol, 1764160800 + ($index * 60)),
                clientOrderId: 'cid-' . strtolower($status),
                symbol: $symbol,
            );
            ++$index;
            $first = $manager->reserveIntent($params);

            if ($status === OrderIntent::STATUS_SENT) {
                $manager->markAsSent($first->intent, 'exchange-' . strtolower($status));
            } elseif ($status === OrderIntent::STATUS_FAILED) {
                $manager->markAsFailed($first->intent, 'forced failure');
            } elseif ($status === OrderIntent::STATUS_CANCELLED) {
                $manager->markAsCancelled($first->intent);
            }

            $second = $manager->reserveIntent($params);

            self::assertTrue($second->blocked);
            self::assertSame($expectedReason, $second->reason);
            self::assertSame($first->intent->getId(), $second->intent->getId());
        }
    }

    public function testOrderIntentReservationScopesSameDecisionKeyByExchangeAndMarket(): void
    {
        $manager = $this->orderIntentManager();
        $decisionKey = 'shared:perpetual:BTCUSDT:1m:1764160800:long:scalper_micro:v1';

        $bitmart = $manager->reserveIntent($this->orderIntentParams(
            exchange: 'bitmart',
            decisionKey: $decisionKey,
            clientOrderId: 'cid-bitmart-shared-decision',
        ));
        $binance = $manager->reserveIntent($this->orderIntentParams(
            exchange: 'binance',
            decisionKey: $decisionKey,
            clientOrderId: 'cid-binance-shared-decision',
        ));
        $bitmartSpot = $manager->reserveIntent($this->orderIntentParams(
            exchange: 'bitmart',
            marketType: 'spot',
            decisionKey: $decisionKey,
            clientOrderId: 'cid-bitmart-spot-shared-decision',
        ));

        self::assertTrue($bitmart->created);
        self::assertTrue($binance->created);
        self::assertTrue($bitmartSpot->created);
        self::assertNotSame($bitmart->intent->getId(), $binance->intent->getId());
        self::assertNotSame($bitmart->intent->getId(), $bitmartSpot->intent->getId());
        self::assertSame('bitmart', $bitmart->intent->getExchange());
        self::assertSame('binance', $binance->intent->getExchange());
        self::assertSame('spot', $bitmartSpot->intent->getMarketType());
    }

    public function testOrderIntentReservationBlocksDifferentProfileOnSameExchangeMarketSymbol(): void
    {
        $manager = $this->orderIntentManager();

        $scalper = $manager->reserveIntent($this->orderIntentParams(
            decisionKey: 'bitmart:perpetual:BTCUSDT:1m:1764160800:long:scalper:v1',
            clientOrderId: 'cid-scalper',
            strategyProfile: 'scalper',
        ));
        $scalperMicro = $manager->reserveIntent($this->orderIntentParams(
            decisionKey: 'bitmart:perpetual:BTCUSDT:1m:1764160860:long:scalper_micro:v1',
            clientOrderId: 'cid-scalper-micro',
            strategyProfile: 'scalper_micro',
        ));

        self::assertTrue($scalper->created);
        self::assertFalse($scalper->blocked);
        self::assertFalse($scalperMicro->created);
        self::assertTrue($scalperMicro->blocked);
        self::assertSame('cross_profile_symbol_locked', $scalperMicro->reason);
        self::assertSame([
            'exchange' => 'bitmart',
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'current_profile' => 'scalper_micro',
            'blocking_profile' => 'scalper',
            'blocking_order_intent_id' => $scalper->intent->getId(),
            'blocking_decision_key' => $scalper->intent->getDecisionKey(),
        ], $scalperMicro->metadata['lock'] ?? null);
    }

    public function testOrderIntentReservationWithoutDecisionKeyStillUsesGlobalSymbolLock(): void
    {
        $manager = $this->orderIntentManager();

        $first = $manager->reserveIntent($this->orderIntentParams(
            decisionKey: '',
            clientOrderId: 'cid-no-decision-first',
            strategyProfile: 'scalper',
        ));
        $second = $manager->reserveIntent($this->orderIntentParams(
            decisionKey: '',
            clientOrderId: 'cid-no-decision-second',
            strategyProfile: 'scalper_micro',
        ));

        self::assertTrue($first->created);
        self::assertTrue($second->blocked);
        self::assertSame('cross_profile_symbol_locked', $second->reason);
        self::assertSame($first->intent->getId(), $second->intent->getId());
    }

    public function testOrderIntentReservationAllowsSameSymbolOnDifferentExchangeMarketOrSymbol(): void
    {
        $manager = $this->orderIntentManager();

        $bitmart = $manager->reserveIntent($this->orderIntentParams(
            exchange: 'bitmart',
            marketType: 'perpetual',
            decisionKey: 'bitmart:perpetual:BTCUSDT:1m:1764160800:long:scalper:v1',
            clientOrderId: 'cid-bitmart-btc',
            strategyProfile: 'scalper',
        ));
        $okx = $manager->reserveIntent($this->orderIntentParams(
            exchange: 'okx',
            marketType: 'perpetual',
            decisionKey: 'okx:perpetual:BTCUSDT:1m:1764160800:long:scalper_micro:v1',
            clientOrderId: 'cid-okx-btc',
            strategyProfile: 'scalper_micro',
        ));
        $spot = $manager->reserveIntent($this->orderIntentParams(
            exchange: 'bitmart',
            marketType: 'spot',
            decisionKey: 'bitmart:spot:BTCUSDT:1m:1764160800:long:scalper_micro:v1',
            clientOrderId: 'cid-bitmart-spot-btc',
            strategyProfile: 'scalper_micro',
        ));
        $eth = $manager->reserveIntent($this->orderIntentParams(
            exchange: 'bitmart',
            marketType: 'perpetual',
            decisionKey: 'bitmart:perpetual:ETHUSDT:1m:1764160800:long:scalper_micro:v1',
            clientOrderId: 'cid-bitmart-eth',
            symbol: 'ETHUSDT',
            strategyProfile: 'scalper_micro',
        ));

        self::assertTrue($bitmart->created);
        self::assertTrue($okx->created);
        self::assertTrue($spot->created);
        self::assertTrue($eth->created);
    }

    public function testOrderIntentFailureAndCancellationReleaseSymbolLock(): void
    {
        $manager = $this->orderIntentManager();

        $first = $manager->reserveIntent($this->orderIntentParams(
            decisionKey: 'bitmart:perpetual:BTCUSDT:1m:1764160800:long:scalper:v1',
            clientOrderId: 'cid-first-failed',
            strategyProfile: 'scalper',
        ));
        $manager->markAsFailed($first->intent, 'exchange rejected');

        $afterFailure = $manager->reserveIntent($this->orderIntentParams(
            decisionKey: 'bitmart:perpetual:BTCUSDT:1m:1764160860:long:scalper_micro:v1',
            clientOrderId: 'cid-after-failure',
            strategyProfile: 'scalper_micro',
        ));
        self::assertTrue($afterFailure->created);

        $manager->markAsCancelled($afterFailure->intent);
        $afterCancel = $manager->reserveIntent($this->orderIntentParams(
            decisionKey: 'bitmart:perpetual:BTCUSDT:1m:1764160920:long:regular:v1',
            clientOrderId: 'cid-after-cancel',
            strategyProfile: 'regular',
        ));

        self::assertTrue($afterCancel->created);
    }

    public function testOrderIntentValidationFailureReleasesSymbolLock(): void
    {
        $manager = $this->orderIntentManager();
        $invalidParams = $this->orderIntentParams(
            decisionKey: 'bitmart:perpetual:BTCUSDT:1m:1764160800:long:scalper:v1',
            clientOrderId: 'cid-validation-failed',
            size: 0,
            strategyProfile: 'scalper',
        );

        $reservation = $manager->reserveIntent($invalidParams);
        $errors = $manager->validateOrderParams($invalidParams);

        self::assertTrue($reservation->created);
        self::assertNotNull($errors);
        self::assertFalse($manager->validateIntent($reservation->intent, $errors));

        $afterValidationFailure = $manager->reserveIntent($this->orderIntentParams(
            decisionKey: 'bitmart:perpetual:BTCUSDT:1m:1764160860:long:scalper_micro:v1',
            clientOrderId: 'cid-after-validation-failure',
            strategyProfile: 'scalper_micro',
        ));

        self::assertTrue($afterValidationFailure->created);
    }

    public function testOrderIntentCancellationReleasesLockDespiteStaleOpenOrderRow(): void
    {
        $manager = $this->orderIntentManager();

        $first = $manager->reserveIntent($this->orderIntentParams(
            decisionKey: 'bitmart:perpetual:BTCUSDT:1m:1764160800:long:scalper:v1',
            clientOrderId: 'cid-cancelled-stale-order',
            strategyProfile: 'scalper',
        ));
        $manager->markAsSent($first->intent, 'exchange-cancelled-stale-order');
        $this->em->persist(
            $this->newFuturesOrder('bitmart', 'exchange-cancelled-stale-order', '1', 'BTCUSDT')
                ->setClientOrderId($first->intent->getClientOrderId())
                ->setStatus('pending')
        );
        $this->em->flush();

        $manager->markAsCancelled($first->intent);

        $afterCancellation = $manager->reserveIntent($this->orderIntentParams(
            decisionKey: 'bitmart:perpetual:BTCUSDT:1m:1764160860:long:scalper_micro:v1',
            clientOrderId: 'cid-after-cancelled-stale-order',
            strategyProfile: 'scalper_micro',
        ));

        self::assertTrue($afterCancellation->created);
    }

    public function testOrderIntentCancellationKeepsLockWhenPositionIsOpen(): void
    {
        $manager = $this->orderIntentManager();

        $first = $manager->reserveIntent($this->orderIntentParams(
            decisionKey: 'bitmart:perpetual:BTCUSDT:1m:1764160800:long:scalper:v1',
            clientOrderId: 'cid-cancelled-open-position',
            strategyProfile: 'scalper',
        ));
        $this->em->persist((new Position('BTCUSDT', 'LONG'))->setSize('1'));
        $this->em->flush();

        $manager->markAsCancelled($first->intent);
        $afterCancellation = $manager->reserveIntent($this->orderIntentParams(
            decisionKey: 'bitmart:perpetual:BTCUSDT:1m:1764160860:long:scalper_micro:v1',
            clientOrderId: 'cid-after-cancelled-open-position',
            strategyProfile: 'scalper_micro',
        ));

        self::assertTrue($afterCancellation->blocked);
        self::assertSame('cross_profile_symbol_locked', $afterCancellation->reason);
    }

    public function testOrderIntentReservationBlocksExistingOpenExposureWithoutActiveLock(): void
    {
        $manager = $this->orderIntentManager();
        $this->em->persist(
            $this->newFuturesOrder('bitmart', 'orphan-open-order', '1', 'BTCUSDT')
                ->setClientOrderId('orphan-open-order-client')
                ->setStatus('pending')
        );
        $this->em->flush();

        $reservation = $manager->reserveIntent($this->orderIntentParams(
            decisionKey: 'bitmart:perpetual:BTCUSDT:1m:1764160800:long:scalper:v1',
            clientOrderId: 'cid-existing-open-exposure',
            strategyProfile: 'scalper',
        ));

        /** @var \App\Repository\SymbolExecutionLockRepository $lockRepository */
        $lockRepository = $this->em->getRepository(SymbolExecutionLock::class);
        $lock = $lockRepository->findActive('bitmart', 'perpetual', 'BTCUSDT');

        self::assertTrue($reservation->blocked);
        self::assertSame('cross_profile_symbol_locked', $reservation->reason);
        self::assertSame('existing_open_exposure', $reservation->metadata['lock']['blocking_reason'] ?? null);
        self::assertNotNull($lock);
        self::assertNull($lock->getOwnerOrderIntentId());
    }

    public function testBlockedReservationDetachesCascadedProtections(): void
    {
        $manager = $this->orderIntentManager();

        $manager->reserveIntent($this->orderIntentParams(
            decisionKey: 'bitmart:perpetual:BTCUSDT:1m:1764160800:long:scalper:v1',
            clientOrderId: 'cid-protection-owner',
            strategyProfile: 'scalper',
        ));
        $blockedParams = $this->orderIntentParams(
            decisionKey: 'bitmart:perpetual:BTCUSDT:1m:1764160860:long:scalper_micro:v1',
            clientOrderId: 'cid-protection-blocked',
            strategyProfile: 'scalper_micro',
        );
        $blockedParams['preset_take_profit_price'] = '110';
        $blockedParams['preset_stop_loss_price'] = '90';

        $blocked = $manager->reserveIntent($blockedParams);
        $this->em->flush();

        self::assertTrue($blocked->blocked);
        self::assertSame(0, $this->em->getRepository(OrderProtection::class)->count([]));
    }

    public function testExpiredSymbolLockIsNotReclaimedWhileOpenOrderExists(): void
    {
        $manager = $this->orderIntentManager();
        $owner = $this->expiredLockOwner('BTCUSDT');
        $lock = new SymbolExecutionLock(
            exchange: Exchange::BITMART,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            ownerOrderIntent: $owner,
            expiresAt: new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC')),
        );
        $this->em->persist($owner);
        $this->em->persist($lock);
        $this->em->persist($this->newFuturesOrder('bitmart', 'open-order-btc', '1', 'BTCUSDT')->setStatus('pending'));
        $this->em->flush();

        $reservation = $manager->reserveIntent($this->orderIntentParams(
            decisionKey: 'bitmart:perpetual:BTCUSDT:1m:1764160980:long:scalper_micro:v1',
            clientOrderId: 'cid-expired-open-order',
            strategyProfile: 'scalper_micro',
        ));

        self::assertTrue($reservation->blocked);
        self::assertSame('cross_profile_symbol_locked', $reservation->reason);
        self::assertNull($lock->getReleasedAt());
    }

    public function testExpiredSymbolLockCanBeReclaimedWithoutOpenExposure(): void
    {
        $manager = $this->orderIntentManager();
        $owner = $this->expiredLockOwner('BTCUSDT');
        $lock = new SymbolExecutionLock(
            exchange: Exchange::BITMART,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            ownerOrderIntent: $owner,
            expiresAt: new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC')),
        );
        $this->em->persist($owner);
        $this->em->persist($lock);
        $this->em->flush();

        $reservation = $manager->reserveIntent($this->orderIntentParams(
            decisionKey: 'bitmart:perpetual:BTCUSDT:1m:1764160980:long:scalper_micro:v1',
            clientOrderId: 'cid-expired-reclaimed',
            strategyProfile: 'scalper_micro',
        ));

        self::assertTrue($reservation->created);
        self::assertSame('expired_reclaimed', $lock->getReleaseReason());
        self::assertNotNull($lock->getReleasedAt());
    }

    public function testTradingStateReadsCanSelectExplicitExchangeContext(): void
    {
        $this->em->persist(
            (new Position('ETHUSDT', 'LONG'))
                ->setSize('1')
                ->setAvgEntryPrice('100')
                ->setUnrealizedPnl('0')
        );
        $this->em->persist(
            (new Position('ETHUSDT', 'LONG', Exchange::BINANCE, MarketType::PERPETUAL))
                ->setSize('2')
                ->setAvgEntryPrice('200')
                ->setUnrealizedPnl('0')
                ->mergePayload(['exchange' => 'binance', 'market_type' => 'perpetual'])
        );
        $this->em->persist($this->newFuturesOrder('bitmart', 'shared-order', '1'));
        $this->em->persist($this->newFuturesOrder('binance', 'shared-order', '2'));
        $this->em->flush();

        $binanceContext = new ExchangeContext(Exchange::BINANCE, MarketType::PERPETUAL);
        $positionState = new PositionPositionStateRepository(
            $this->em->getRepository(Position::class),
            $this->em,
        );
        $orderState = new FuturesOrderOrderStateRepository(
            $this->em->getRepository(FuturesOrder::class),
            $this->em,
        );

        self::assertSame('1', $positionState->findLocalOpenPosition('ETHUSDT', 'LONG')?->size->__toString());
        self::assertSame('2', $positionState->findLocalOpenPosition('ETHUSDT', 'LONG', $binanceContext)?->size->__toString());
        self::assertCount(1, $positionState->findLocalOpenPositions(['ETHUSDT'], $binanceContext));

        self::assertSame('1', $orderState->findLocalOrder('ETHUSDT', 'shared-order')?->quantity->__toString());
        self::assertSame('2', $orderState->findLocalOrder('ETHUSDT', 'shared-order', $binanceContext)?->quantity->__toString());
        self::assertCount(1, $orderState->findLocalOpenOrders(['ETHUSDT'], $binanceContext));
    }

    public function testMtfStateRepositoryScopesStateByExchangeAndMarketType(): void
    {
        /** @var MtfStateRepository $repository */
        $repository = $this->em->getRepository(MtfState::class);
        $binanceContext = new ExchangeContext(Exchange::BINANCE, MarketType::PERPETUAL);

        $bitmart = $repository->getOrCreateForSymbol('BTCUSDT');
        $bitmart->set4hSide('long');

        $binance = $repository->getOrCreateForSymbol('BTCUSDT', $binanceContext);
        $binance->set4hSide('short');
        $this->em->flush();

        self::assertNotSame($bitmart->getId(), $binance->getId());
        self::assertSame('bitmart', $repository->getOrCreateForSymbol('BTCUSDT')->getExchange());
        self::assertSame('long', $repository->getOrCreateForSymbol('BTCUSDT')->get4hSide());
        self::assertSame('binance', $repository->getOrCreateForSymbol('BTCUSDT', $binanceContext)->getExchange());
        self::assertSame('short', $repository->getOrCreateForSymbol('BTCUSDT', $binanceContext)->get4hSide());
    }

    public function testLatestValidationFailuresKeepFailedTimeframeAndExchangeScope(): void
    {
        if (!$this->em->getConnection()->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            self::markTestSkipped('MtfAuditRepository uses PostgreSQL-specific SQL.');
        }

        $openTime = new \DateTimeImmutable('2026-05-31 00:00:00', new \DateTimeZone('UTC'));
        $this->em->persist($this->newMtfAudit('binance', '1M_VALIDATION_FAILED', $openTime, 'rsi_rejected'));
        $this->em->flush();

        /** @var MtfAuditRepository $repository */
        $repository = $this->em->getRepository(MtfAudit::class);

        $rows = $repository->getLatestValidationSuccessesPerSymbol('BTCUSDT', ['1m']);

        self::assertCount(1, $rows);
        self::assertSame('BTCUSDT', $rows[0]['symbol']);
        self::assertSame('binance', $rows[0]['exchange']);
        self::assertSame('perpetual', $rows[0]['market_type']);
        self::assertSame('failed', $rows[0]['timeframes']['1m']['status']);
        self::assertSame('rsi_rejected', $rows[0]['timeframes']['1m']['cause']);
    }

    private function newKline(string $exchange, string $close, \DateTimeImmutable $openTime): Kline
    {
        return (new Kline())
            ->setExchange($exchange)
            ->setMarketType(MarketType::PERPETUAL)
            ->setSymbol('BTCUSDT')
            ->setTimeframe(Timeframe::TF_1M)
            ->setOpenTime($openTime)
            ->setOpenPrice(BigDecimal::of('100'))
            ->setHighPrice(BigDecimal::of('210'))
            ->setLowPrice(BigDecimal::of('90'))
            ->setClosePrice(BigDecimal::of($close))
            ->setVolume(BigDecimal::of('1'));
    }

    /**
     * @param array<string,mixed> $values
     */
    private function newSnapshot(string $exchange, \DateTimeImmutable $klineTime, array $values): IndicatorSnapshot
    {
        return (new IndicatorSnapshot())
            ->setExchange($exchange)
            ->setMarketType(MarketType::PERPETUAL)
            ->setSymbol('BTCUSDT')
            ->setTimeframe(Timeframe::TF_1M)
            ->setKlineTime($klineTime)
            ->setValues($values);
    }

    private function newIntent(string $exchange, string $clientOrderId): OrderIntent
    {
        return (new OrderIntent())
            ->setExchange($exchange)
            ->setMarketType(MarketType::PERPETUAL)
            ->setSymbol('BTCUSDT')
            ->setSide(1)
            ->setType(OrderIntent::TYPE_LIMIT)
            ->setOpenType(OrderIntent::OPEN_TYPE_ISOLATED)
            ->setPositionMode(OrderIntent::POSITION_MODE_ONE_WAY)
            ->setPrice('100')
            ->setSize(1)
            ->setClientOrderId($clientOrderId)
            ->setPresetMode(OrderIntent::PRESET_MODE_NONE)
            ->setQuantization([])
            ->setStatus(OrderIntent::STATUS_DRAFT);
    }

    private function orderIntentManager(): OrderIntentManager
    {
        /** @var OrderIntentRepository $repository */
        $repository = $this->em->getRepository(OrderIntent::class);
        /** @var \App\Repository\SymbolExecutionLockRepository $lockRepository */
        $lockRepository = $this->em->getRepository(SymbolExecutionLock::class);
        /** @var PositionRepository $positionRepository */
        $positionRepository = $this->em->getRepository(Position::class);
        /** @var FuturesOrderRepository $orderRepository */
        $orderRepository = $this->em->getRepository(FuturesOrder::class);

        return new OrderIntentManager(
            $repository,
            $this->em,
            new NullLogger(),
            new DecisionKeyFactory(),
            new SymbolExecutionLockManager(
                $lockRepository,
                $positionRepository,
                $orderRepository,
                $this->em,
                new NullLogger(),
            ),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function orderIntentParams(
        string $exchange = 'bitmart',
        string $marketType = 'perpetual',
        string $decisionKey = 'bitmart:perpetual:BTCUSDT:1m:1764160800:long:scalper_micro:v1',
        string $clientOrderId = 'cid-reservation',
        string $price = '100',
        int $size = 1,
        string $symbol = 'BTCUSDT',
        string $strategyProfile = 'scalper_micro',
    ): array {
        return [
            'exchange' => $exchange,
            'market_type' => $marketType,
            'decision_key' => $decisionKey,
            'symbol' => $symbol,
            'timeframe' => '1m',
            'candle_open_ts' => '1764160800',
            'strategy_profile' => $strategyProfile,
            'strategy_version' => 'v1',
            'side' => 1,
            'type' => OrderIntent::TYPE_LIMIT,
            'open_type' => OrderIntent::OPEN_TYPE_ISOLATED,
            'position_mode' => OrderIntent::POSITION_MODE_ONE_WAY,
            'price' => $price,
            'size' => $size,
            'client_order_id' => $clientOrderId,
            'preset_mode' => OrderIntent::PRESET_MODE_NONE,
        ];
    }

    private function expiredLockOwner(string $symbol): OrderIntent
    {
        return $this->newIntent('bitmart', 'cid-expired-owner-' . strtolower($symbol))
            ->setDecisionKey(sprintf('bitmart:perpetual:%s:1m:1764160800:long:scalper:v1', $symbol))
            ->setStrategyProfile('scalper')
            ->setStrategyVersion('v1')
            ->setSymbol($symbol)
            ->setStatus(OrderIntent::STATUS_FAILED);
    }

    private function newFuturesOrder(
        string $exchange,
        string $orderId,
        string $size,
        string $symbol = 'ETHUSDT',
    ): FuturesOrder
    {
        return (new FuturesOrder())
            ->setExchange($exchange)
            ->setMarketType(MarketType::PERPETUAL)
            ->setSymbol($symbol)
            ->setOrderId($orderId)
            ->setClientOrderId($exchange . '-client')
            ->setStatus('new')
            ->setSide(1)
            ->setType('market')
            ->setPrice('100')
            ->setSize((int) $size)
            ->setFilledSize(0)
            ->setRawData(['exchange' => $exchange, 'market_type' => 'perpetual']);
    }

    private function newMtfAudit(
        string $exchange,
        string $step,
        \DateTimeImmutable $candleOpenTs,
        string $cause,
    ): MtfAudit {
        return (new MtfAudit())
            ->setSymbol('BTCUSDT')
            ->setRunId(Uuid::uuid4())
            ->setStep($step)
            ->setTimeframe(Timeframe::TF_1M)
            ->setCandleOpenTs($candleOpenTs)
            ->setCreatedAt($candleOpenTs->modify('+30 seconds'))
            ->setCause($cause)
            ->setDetails([
                'exchange' => $exchange,
                'market_type' => 'perpetual',
            ]);
    }
}
