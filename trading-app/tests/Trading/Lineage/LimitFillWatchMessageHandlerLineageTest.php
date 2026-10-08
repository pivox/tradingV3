<?php

declare(strict_types=1);

namespace App\Tests\Trading\Lineage;

use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Registry\ExchangeAdapterRegistry;
use App\Tests\TradeEntry\Support\ScriptedExchangeAdapter;
use App\TradeEntry\Execution\EmergencyCloseService;
use App\TradeEntry\Execution\ProtectionEnforcer;
use App\TradeEntry\Execution\RestingEntryWatcher;
use App\TradeEntry\Service\TradeEntryMetricsService;
use App\Entity\TradeLifecycleEvent;
use App\Entity\TradeLineage;
use App\Logging\TradeLifecycleLogger;
use App\Repository\TradeLineageRepository;
use App\TradeEntry\Message\LimitFillWatchMessage;
use App\TradeEntry\MessageHandler\LimitFillWatchMessageHandler;
use App\Trading\Lineage\TradeLineageManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(LimitFillWatchMessageHandler::class)]
final class LimitFillWatchMessageHandlerLineageTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private ScriptedExchangeAdapter $adapter;

    /** @var list<Envelope> */
    private array $dispatched = [];

    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->adapter = new ScriptedExchangeAdapter();
        $this->dispatched = [];
        $this->em = self::$kernel->getContainer()->get('doctrine.orm.entity_manager');

        $metadata = [
            $this->em->getClassMetadata(TradeLifecycleEvent::class),
            $this->em->getClassMetadata(TradeLineage::class),
        ];
        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema([
            $this->em->getClassMetadata(TradeLifecycleEvent::class),
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            (new SchemaTool($this->em))->dropSchema([
                $this->em->getClassMetadata(TradeLifecycleEvent::class),
            ]);
            $this->em->close();
        }

        parent::tearDown();
    }

    public function testLimitFillLifecycleIsLoggedWhenLineageTableIsMissing(): void
    {
        $handler = $this->handler();

        $method = new \ReflectionMethod(LimitFillWatchMessageHandler::class, 'logPositionOpenedLifecycle');
        $method->invoke(
            $handler,
            new LimitFillWatchMessage(
                symbol: 'BTCUSDT',
                exchangeOrderId: 'exchange-limit-1',
                clientOrderId: 'client-limit-1',
                side: 'BUY',
                cancelAfterSec: 30,
                decisionKey: 'okx:perpetual:BTCUSDT:1m:1764161200:long:scalper:v1',
                lifecycleContext: [
                    'exchange' => 'okx',
                    'market_type' => 'perpetual',
                    'run_id' => 'run-limit',
                ],
            ),
            $this->adapter->order('exchange-limit-1', ExchangeOrderStatus::FILLED, 3.0, 0.0, 'client-limit-1', metadata: ['position_id' => 'pos-limit-1']),
        );

        /** @var TradeLifecycleEvent|null $opened */
        $opened = $this->em->getRepository(TradeLifecycleEvent::class)->findOneBy([
            'eventType' => 'position_opened',
            'positionId' => 'pos-limit-1',
        ]);

        self::assertNotNull($opened);
        self::assertSame('run-limit', $opened->getRunId());
    }

    public function testTerminalCancelledOrderWithFillLogsPositionOpenedInsteadOfExpired(): void
    {
        $this->adapter->openOrders[] = $this->adapter->order('exchange-limit-partial-1', ExchangeOrderStatus::CANCELLED, 0.4, 0.6, 'client-limit-partial-1', metadata: ['position_id' => 'pos-limit-partial-1']);
        $handler = $this->handler();

        $handler(new LimitFillWatchMessage(
            symbol: 'BTCUSDT',
            exchangeOrderId: 'exchange-limit-partial-1',
            clientOrderId: 'client-limit-partial-1',
            side: 'BUY',
            cancelAfterSec: 30,
            decisionKey: 'okx:perpetual:BTCUSDT:1m:1764161200:long:scalper:v1',
            lifecycleContext: [
                'exchange' => 'okx',
                'market_type' => 'perpetual',
                'run_id' => 'run-limit-partial',
            ],
            plan: self::planSnapshot(),
        ));

        /** @var TradeLifecycleEvent|null $opened */
        $opened = $this->em->getRepository(TradeLifecycleEvent::class)->findOneBy([
            'eventType' => 'position_opened',
            'positionId' => 'pos-limit-partial-1',
        ]);
        /** @var TradeLifecycleEvent|null $expired */
        $expired = $this->em->getRepository(TradeLifecycleEvent::class)->findOneBy([
            'eventType' => 'order_expired',
        ]);

        self::assertNotNull($opened);
        self::assertSame(0.4, (float) $opened->getQty());
        self::assertSame('run-limit-partial', $opened->getRunId());
        self::assertNull($expired);
    }

    private function tradeLineageManager(): TradeLineageManager
    {
        /** @var TradeLineageRepository $repository */
        $repository = $this->em->getRepository(TradeLineage::class);

        return new TradeLineageManager($repository, $this->em, new NullLogger());
    }

    private function firstPlaced(): \App\Exchange\Dto\PlaceOrderRequest
    {
        return $this->adapter->placed[0];
    }

    private function handler(?\App\TradeEntry\Service\TakeProfitPlacerInterface $takeProfits = null): LimitFillWatchMessageHandler
    {
        $logger = new NullLogger();
        $metrics = new TradeEntryMetricsService();

        return new LimitFillWatchMessageHandler(
            new ExchangeAdapterRegistry([$this->adapter]),
            new RestingEntryWatcher(new ProtectionEnforcer(new EmergencyCloseService($metrics, $logger), $metrics, $logger), $this->fixedClock()),
            $logger,
            $this->messageBus(),
            new TradeLifecycleLogger($this->em, $this->fixedClock()),
            $this->tradeLineageManager(),
            $takeProfits,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function planSnapshot(): array
    {
        return [
            'symbol' => 'BTCUSDT', 'side' => 'long', 'order_type' => 'limit', 'open_type' => 'isolated', 'order_mode' => 1,
            'entry' => 100.0, 'stop' => 95.0, 'take_profit' => 110.0, 'size' => 3, 'leverage' => 3, 'price_precision' => 2, 'contract_size' => 1.0,
        ];
    }

    /**
     * @param array<string,mixed>|null $plan
     */
    private function message(int $tries = 0, bool $cancelIssued = false, int $cancelAfterSec = 30, ?array $plan = null, ?float $positionBaseline = 0.0): LimitFillWatchMessage
    {
        return new LimitFillWatchMessage(
            symbol: 'BTCUSDT',
            exchangeOrderId: 'ord-w',
            clientOrderId: 'client-w',
            side: 'BUY',
            cancelAfterSec: $cancelAfterSec,
            tries: $tries,
            decisionKey: 'okx:perpetual:BTCUSDT:1m:1764161200:long:scalper:v1',
            lifecycleContext: ['exchange' => 'okx', 'market_type' => 'perpetual', 'run_id' => 'run-w'],
            cancelIssued: $cancelIssued,
            plan: $plan ?? self::planSnapshot(),
            positionBaseline: $positionBaseline,
        );
    }

    public function testFilledRestingEntryPlacesTheStopThroughTheAdapterAndLogsPositionOpened(): void
    {
        $this->adapter->openOrders[] = $this->adapter->order('ord-w', ExchangeOrderStatus::FILLED, 3.0, 0.0, 'client-w');
        $this->adapter->positions[] = $this->adapter->position(3.0);
        $this->adapter->placeStatus = ExchangeOrderStatus::OPEN;

        $this->handler()($this->message());

        self::assertCount(1, $this->adapter->placed);
        $stop = $this->adapter->placed[0];
        self::assertSame(ExchangeOrderType::STOP_LOSS, $stop->orderType);
        self::assertTrue($stop->reduceOnly);
        self::assertSame(95.0, $stop->stopPrice);
        self::assertSame([], $this->dispatched);
        self::assertNotNull($this->em->getRepository(TradeLifecycleEvent::class)->findOneBy(['eventType' => 'position_opened']));
    }

    public function testTakeProfitsAreRequestedAfterTheStopWithoutTouchingIt(): void
    {
        $this->adapter->openOrders[] = $this->adapter->order('ord-w', ExchangeOrderStatus::FILLED, 3.0, 0.0, 'client-w');
        $this->adapter->positions[] = $this->adapter->position(3.0);
        $this->adapter->placeStatus = ExchangeOrderStatus::OPEN;
        $placer = new RecordingTakeProfitPlacer();

        $this->handler($placer)($this->message());

        self::assertCount(1, $this->adapter->placed);
        self::assertCount(1, $placer->requests);
        $request = $placer->requests[0];
        self::assertSame('BTCUSDT', $request->symbol);
        self::assertSame(3, $request->size);
        self::assertSame(100.5, $request->entryPrice);
        self::assertFalse($request->cancelExistingStopLossIfDifferent);
        self::assertFalse($request->cancelExistingTakeProfits);
        self::assertFalse($request->slFullSize);
        self::assertSame([], $this->adapter->cancelled);
    }

    public function testTakeProfitFailureKeepsTheStop(): void
    {
        $this->adapter->openOrders[] = $this->adapter->order('ord-w', ExchangeOrderStatus::FILLED, 3.0, 0.0, 'client-w');
        $this->adapter->positions[] = $this->adapter->position(3.0);
        $this->adapter->placeStatus = ExchangeOrderStatus::OPEN;
        $placer = new RecordingTakeProfitPlacer();
        $placer->fail = true;

        $this->handler($placer)($this->message());

        self::assertCount(1, $placer->requests);
        self::assertCount(1, $this->adapter->placed);
        self::assertSame([], $this->adapter->cancelled);
        self::assertNotNull($this->em->getRepository(TradeLifecycleEvent::class)->findOneBy(['eventType' => 'position_opened']));
    }

    public function testNoTakeProfitWhenProtectionFailed(): void
    {
        $this->adapter->openOrders[] = $this->adapter->order('ord-w', ExchangeOrderStatus::FILLED, 3.0, 0.0, 'client-w');
        $this->adapter->positions[] = $this->adapter->position(3.0);
        $this->adapter->placeStatus = ExchangeOrderStatus::REJECTED;
        $placer = new RecordingTakeProfitPlacer();

        $this->handler($placer)($this->message());

        self::assertSame([], $placer->requests);
    }

    public function testPartialFillWithActiveRemainderCancelsTheRemainderThenProtects(): void
    {
        $this->adapter->openOrders[] = $this->adapter->order('ord-w', ExchangeOrderStatus::PARTIALLY_FILLED, 1.0, 2.0, 'client-w');
        $this->adapter->positions[] = $this->adapter->position(1.0);
        $this->adapter->placeStatus = ExchangeOrderStatus::OPEN;

        $this->handler()($this->message());

        self::assertCount(1, $this->adapter->cancelled);
        self::assertSame('protective', $this->adapter->cancelled[0]->metadata['write_kind']);
        self::assertSame('ord-w', $this->adapter->cancelled[0]->exchangeOrderId);
        self::assertCount(1, $this->adapter->placed);
        self::assertSame(ExchangeOrderType::STOP_LOSS, $this->adapter->placed[0]->orderType);
        self::assertSame([], $this->dispatched);
    }

    public function testRejectedRemainderCancelDoesNotFinalizeAndKeepsWatching(): void
    {
        $this->adapter->openOrders[] = $this->adapter->order('ord-w', ExchangeOrderStatus::PARTIALLY_FILLED, 1.0, 2.0, 'client-w');
        $this->adapter->positions[] = $this->adapter->position(1.0);
        $this->adapter->cancelAccepted = false;

        $this->handler()($this->message(positionBaseline: 0.0));

        self::assertSame([], $this->adapter->placed);
        self::assertCount(1, $this->dispatched);
        $next = $this->dispatched[0]->getMessage();
        self::assertSame(1, $next->tries);
        self::assertTrue($next->cancelIssued);
        self::assertSame(0.0, $next->positionBaseline);
        self::assertNull($this->em->getRepository(TradeLifecycleEvent::class)->findOneBy(['eventType' => 'position_opened']));
    }

    public function testRemainderCancelPendingThenConfirmedProtectsTheFinalPositionSize(): void
    {
        $this->adapter->openOrders[] = $this->adapter->order('ord-w', ExchangeOrderStatus::PARTIALLY_FILLED, 1.0, 2.0, 'client-w');
        $this->adapter->positions[] = $this->adapter->position(1.0);
        $this->adapter->placeStatus = ExchangeOrderStatus::OPEN;
        $this->adapter->cancelEffectiveOnAttempt = 2;
        $this->adapter->onCancelEffective = function (): void {
            $this->adapter->positions = [$this->adapter->position(2.0)];
        };
        $handler = $this->handler();

        $handler($this->message());
        self::assertSame([], $this->adapter->placed);
        self::assertCount(1, $this->dispatched);

        $handler($this->dispatched[0]->getMessage());

        self::assertCount(1, $this->adapter->placed);
        self::assertEqualsWithDelta(2.0, $this->firstPlaced()->quantity, 0.000001);
        self::assertCount(1, $this->dispatched);
    }

    public function testUnconfirmedRemainderAfterTheRetryBudgetRunsResidualRiskProtection(): void
    {
        $this->adapter->openOrders[] = $this->adapter->order('ord-w', ExchangeOrderStatus::PARTIALLY_FILLED, 1.0, 2.0, 'client-w');
        $this->adapter->positions[] = $this->adapter->position(1.0);
        $this->adapter->placeStatus = ExchangeOrderStatus::OPEN;
        $this->adapter->cancelAccepted = false;

        $this->handler()($this->message(tries: 99, cancelIssued: true));

        self::assertSame([], $this->dispatched);
        self::assertCount(1, $this->adapter->placed);
        self::assertEqualsWithDelta(1.0, $this->adapter->placed[0]->quantity, 0.000001);
    }

    public function testBaselineFillReportsOnlyTheMeasuredIncrease(): void
    {
        $this->adapter->positions[] = $this->adapter->position(3.0);
        $this->adapter->placeStatus = ExchangeOrderStatus::OPEN;
        $watcher = new RestingEntryWatcher(new ProtectionEnforcer(new EmergencyCloseService(new TradeEntryMetricsService(), new NullLogger()), new TradeEntryMetricsService(), new NullLogger()), $this->fixedClock());

        $state = $watcher->inspect($this->adapter, 'BTCUSDT', 'ord-w', 'client-w', \App\Exchange\Enum\ExchangePositionSide::LONG, 2.0, 1.0);

        self::assertSame('filled', $state->status);
        self::assertEqualsWithDelta(1.0, $state->filledQuantity, 0.000001);
    }

    public function testFillSeenOnlyInTheFillsSnapshotStillTriggersProtection(): void
    {
        $this->adapter->fills[] = new \App\Exchange\Dto\ExchangeFillDto(
            \App\Common\Enum\Exchange::OKX, \App\Common\Enum\MarketType::PERPETUAL, 'BTCUSDT', 'ord-w', 'client-w', 'f1',
            \App\Exchange\Enum\ExchangeOrderSide::BUY, null, 3.0, 100.0, null, null, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
        $this->adapter->positions[] = $this->adapter->position(3.0);
        $this->adapter->placeStatus = ExchangeOrderStatus::OPEN;

        $this->handler()($this->message());

        self::assertCount(1, $this->adapter->placed);
    }

    public function testPositionIncreaseOverBaselineByTheOrderQuantityIsProtectedRatherThanReportedExpired(): void
    {
        $this->adapter->positions[] = $this->adapter->position(3.0);
        $this->adapter->placeStatus = ExchangeOrderStatus::OPEN;

        $this->handler()($this->message());

        self::assertCount(1, $this->adapter->placed);
        self::assertNull($this->em->getRepository(TradeLifecycleEvent::class)->findOneBy(['eventType' => 'order_expired']));
    }

    public function testPreExistingPositionDoesNotMakeACancelledOrderLookFilled(): void
    {
        $this->adapter->positions[] = $this->adapter->position(5.0);
        $this->adapter->placeStatus = ExchangeOrderStatus::OPEN;

        $this->handler()($this->message(tries: 9, cancelIssued: true, positionBaseline: 5.0));

        self::assertSame([], $this->adapter->placed);
        self::assertNotNull($this->em->getRepository(TradeLifecycleEvent::class)->findOneBy(['eventType' => 'order_expired']));
        self::assertNull($this->em->getRepository(TradeLifecycleEvent::class)->findOneBy(['eventType' => 'position_opened']));
    }

    public function testPositionEvidenceNeedsAnIncreaseOfTheOrderQuantityOverTheBaseline(): void
    {
        $this->adapter->positions[] = $this->adapter->position(6.0);
        $this->adapter->placeStatus = ExchangeOrderStatus::OPEN;

        $this->handler()($this->message(positionBaseline: 5.0));
        self::assertSame([], $this->adapter->placed);

        $this->adapter->positions = [$this->adapter->position(8.0)];
        $this->handler()($this->message(positionBaseline: 5.0));
        self::assertCount(1, $this->adapter->placed);
    }

    public function testMessagesWithoutABaselineNeverUsePositionEvidence(): void
    {
        $this->adapter->positions[] = $this->adapter->position(3.0);
        $this->adapter->placeStatus = ExchangeOrderStatus::OPEN;

        $this->handler()($this->message(tries: 9, cancelIssued: true, positionBaseline: null));

        self::assertSame([], $this->adapter->placed);
    }

    public function testStillRestingInsideTheWindowIsRescheduledWithoutCancel(): void
    {
        $this->adapter->openOrders[] = $this->adapter->order('ord-w', ExchangeOrderStatus::OPEN, 0.0, 3.0, 'client-w');

        $this->handler()($this->message(tries: 0));

        self::assertSame([], $this->adapter->cancelled);
        self::assertCount(1, $this->dispatched);
        $next = $this->dispatched[0]->getMessage();
        self::assertSame(1, $next->tries);
        self::assertFalse($next->cancelIssued);
        self::assertSame(95.0, $next->plan['stop']);
    }

    public function testTimeoutCancelsThroughTheAdapterAsProtectiveAndReschedulesForConfirmation(): void
    {
        $this->adapter->openOrders[] = $this->adapter->order('ord-w', ExchangeOrderStatus::OPEN, 0.0, 3.0, 'client-w');

        $this->handler()($this->message(tries: 8, cancelAfterSec: 30));

        self::assertCount(1, $this->adapter->cancelled);
        self::assertSame('protective', $this->adapter->cancelled[0]->metadata['write_kind']);
        self::assertSame('ord-w', $this->adapter->cancelled[0]->exchangeOrderId);
        self::assertCount(1, $this->dispatched);
        self::assertTrue($this->dispatched[0]->getMessage()->cancelIssued);
    }

    public function testClosedWithoutFillLogsExpiredAndStops(): void
    {
        $this->handler()($this->message(tries: 9, cancelIssued: true));

        self::assertSame([], $this->adapter->placed);
        self::assertSame([], $this->dispatched);
        self::assertNotNull($this->em->getRepository(TradeLifecycleEvent::class)->findOneBy(['eventType' => 'order_expired']));
    }

    public function testExchangeFailureKeepsWatching(): void
    {
        $this->adapter->throwOnGetOrder = true;

        $this->handler()($this->message(tries: 0));

        self::assertSame([], $this->adapter->cancelled);
        self::assertCount(1, $this->dispatched);
    }

    private function messageBus(): MessageBusInterface
    {
        $test = $this;

        return new class($test) implements MessageBusInterface {
            public function __construct(private readonly LimitFillWatchMessageHandlerLineageTest $test)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $envelope = new Envelope($message, $stamps);
                $this->test->record($envelope);

                return $envelope;
            }
        };
    }

    public function record(Envelope $envelope): void
    {
        $this->dispatched[] = $envelope;
    }

    private function fixedClock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-06-23 12:01:00 UTC');
            }
        };
    }
}

final class RecordingTakeProfitPlacer implements \App\TradeEntry\Service\TakeProfitPlacerInterface
{
    /** @var list<\App\TradeEntry\Dto\TpSlTwoTargetsRequest> */
    public array $requests = [];

    public bool $fail = false;

    public function __invoke(\App\TradeEntry\Dto\TpSlTwoTargetsRequest $req, ?string $decisionKey = null, ?string $mode = null): array
    {
        $this->requests[] = $req;
        if ($this->fail) {
            throw new \RuntimeException('tp failed');
        }

        return ['submitted' => [['order_id' => 'tp-1']]];
    }
}
