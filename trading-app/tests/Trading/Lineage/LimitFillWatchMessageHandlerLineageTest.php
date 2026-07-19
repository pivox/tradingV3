<?php

declare(strict_types=1);

namespace App\Tests\Trading\Lineage;

use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderStatus;
use App\Common\Enum\OrderType;
use App\Contract\Provider\AccountProviderInterface;
use App\Contract\Provider\ContractProviderInterface;
use App\Contract\Provider\Dto\OrderDto;
use App\Contract\Provider\KlineProviderInterface;
use App\Contract\Provider\MainProviderInterface;
use App\Contract\Provider\OrderProviderInterface;
use App\Contract\Provider\SystemProviderInterface;
use App\Entity\TradeLifecycleEvent;
use App\Entity\TradeLineage;
use App\Logging\TradeLifecycleLogger;
use App\Provider\Context\ExchangeContext;
use App\Repository\TradeLineageRepository;
use App\TradeEntry\Message\LimitFillWatchMessage;
use App\TradeEntry\MessageHandler\LimitFillWatchMessageHandler;
use App\Trading\Lineage\TradeLineageManager;
use Brick\Math\BigDecimal;
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

    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
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
        $handler = new LimitFillWatchMessageHandler(
            $this->mainProvider(),
            new NullLogger(),
            $this->messageBus(),
            new TradeLifecycleLogger($this->em, $this->fixedClock()),
            $this->tradeLineageManager(),
        );

        $method = new \ReflectionMethod(LimitFillWatchMessageHandler::class, 'logPositionOpenedLifecycle');
        $method->invoke(
            $handler,
            new LimitFillWatchMessage(
                symbol: 'BTCUSDT',
                exchangeOrderId: 'exchange-limit-1',
                clientOrderId: 'client-limit-1',
                side: 'BUY',
                cancelAfterSec: 30,
                decisionKey: 'bitmart:perpetual:BTCUSDT:1m:1764161200:long:scalper:v1',
                lifecycleContext: [
                    'exchange' => 'bitmart',
                    'market_type' => 'perpetual',
                    'run_id' => 'run-limit',
                ],
            ),
            new OrderDto(
                orderId: 'exchange-limit-1',
                symbol: 'BTCUSDT',
                side: OrderSide::BUY,
                type: OrderType::LIMIT,
                status: OrderStatus::FILLED,
                quantity: BigDecimal::of('3'),
                price: BigDecimal::of('100'),
                stopPrice: null,
                filledQuantity: BigDecimal::of('3'),
                remainingQuantity: BigDecimal::of('0'),
                averagePrice: BigDecimal::of('101'),
                createdAt: new \DateTimeImmutable('2026-06-23 12:00:00 UTC'),
                metadata: ['position_id' => 'pos-limit-1'],
            ),
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
        $order = new OrderDto(
            orderId: 'exchange-limit-partial-1',
            symbol: 'BTCUSDT',
            side: OrderSide::BUY,
            type: OrderType::LIMIT,
            status: OrderStatus::CANCELLED,
            quantity: BigDecimal::of('1'),
            price: BigDecimal::of('100'),
            stopPrice: null,
            filledQuantity: BigDecimal::of('0.4'),
            remainingQuantity: BigDecimal::of('0.6'),
            averagePrice: BigDecimal::of('101'),
            createdAt: new \DateTimeImmutable('2026-06-23 12:00:00 UTC'),
            updatedAt: new \DateTimeImmutable('2026-06-23 12:00:30 UTC'),
            metadata: ['position_id' => 'pos-limit-partial-1'],
        );
        $orderProvider = $this->createMock(OrderProviderInterface::class);
        $orderProvider->expects(self::once())
            ->method('getOrder')
            ->with('BTCUSDT', 'exchange-limit-partial-1')
            ->willReturn($order);
        $handler = new LimitFillWatchMessageHandler(
            $this->mainProvider($orderProvider),
            new NullLogger(),
            $this->messageBus(),
            new TradeLifecycleLogger($this->em, $this->fixedClock()),
            $this->tradeLineageManager(),
        );

        $handler(new LimitFillWatchMessage(
            symbol: 'BTCUSDT',
            exchangeOrderId: 'exchange-limit-partial-1',
            clientOrderId: 'client-limit-partial-1',
            side: 'BUY',
            cancelAfterSec: 30,
            decisionKey: 'fake:perpetual:BTCUSDT:1m:1764161200:long:scalper:v1',
            lifecycleContext: [
                'exchange' => 'fake',
                'market_type' => 'perpetual',
                'run_id' => 'run-limit-partial',
            ],
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

    private function mainProvider(?OrderProviderInterface $orderProvider = null): MainProviderInterface
    {
        return new class($orderProvider) implements MainProviderInterface {
            public function __construct(private readonly ?OrderProviderInterface $orderProvider) {}
            public function getKlineProvider(): KlineProviderInterface { throw new \LogicException('unused'); }
            public function getContractProvider(): ContractProviderInterface { throw new \LogicException('unused'); }
            public function getOrderProvider(): ?OrderProviderInterface { return $this->orderProvider; }
            public function getAccountProvider(): ?AccountProviderInterface { throw new \LogicException('unused'); }
            public function getSystemProvider(): SystemProviderInterface { throw new \LogicException('unused'); }
            public function forContext(?ExchangeContext $context = null): self { return $this; }
        };
    }

    private function messageBus(): MessageBusInterface
    {
        return new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return new Envelope($message, $stamps);
            }
        };
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
