<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Contract;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderStatus;
use App\Common\Enum\OrderType;
use App\Contract\Provider\AccountProviderInterface;
use App\Contract\Provider\ContractProviderInterface;
use App\Contract\Provider\Dto\OrderDto;
use App\Contract\Provider\ExchangeProviderRegistryInterface;
use App\Contract\Provider\KlineProviderInterface;
use App\Contract\Provider\OrderProviderInterface;
use App\Contract\Provider\SystemProviderInterface;
use App\Contract\Provider\Dto\SymbolBidAskDto;
use App\Exchange\Adapter\BitmartExchangeAdapter;
use App\Exchange\Contract\ExchangeAdapterInterface;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Provider\Context\ExchangeContext;
use App\Provider\Registry\ExchangeProviderBundle;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Clock\ClockInterface;

#[CoversClass(BitmartExchangeAdapter::class)]
final class BitmartExchangeAdapterContractTest extends ExchangeAdapterContractTestCase
{
    private BitmartExchangeAdapter $adapter;

    private ContractBitmartOrderProvider $orderProvider;

    protected function setUp(): void
    {
        $this->orderProvider = new ContractBitmartOrderProvider($this->fixedClock());
        $bundle = new ExchangeProviderBundle(
            new ExchangeContext(Exchange::BITMART, MarketType::PERPETUAL),
            $this->createMock(KlineProviderInterface::class),
            $this->createMock(ContractProviderInterface::class),
            $this->orderProvider,
            $this->createMock(AccountProviderInterface::class),
            $this->createMock(SystemProviderInterface::class),
        );

        $registry = $this->createMock(ExchangeProviderRegistryInterface::class);
        $registry->method('get')->willReturn($bundle);

        $this->adapter = new BitmartExchangeAdapter($registry, $this->fixedClock());
    }

    protected function adapter(): ExchangeAdapterInterface
    {
        return $this->adapter;
    }

    protected function exchange(): Exchange
    {
        return Exchange::BITMART;
    }

    protected function marketType(): MarketType
    {
        return MarketType::PERPETUAL;
    }

    public function testDuplicateClientOrderIdReconcilesFilledOrderHistory(): void
    {
        $request = $this->placeRequest(
            clientOrderId: $this->clientOrderId('history-fill'),
            orderType: ExchangeOrderType::LIMIT,
            price: $this->restingLimitPrice(),
            postOnly: true,
        );

        $first = $this->adapter->placeOrder($request);
        self::assertNotNull($first->exchangeOrderId);

        $this->orderProvider->markFilled((string)$first->exchangeOrderId);
        $second = $this->adapter->placeOrder($request);

        self::assertTrue($second->accepted);
        self::assertSame($first->exchangeOrderId, $second->exchangeOrderId);
        self::assertSame(ExchangeOrderStatus::FILLED, $second->status);
        self::assertTrue($second->metadata['idempotent_replay_after_reject'] ?? false);
    }

    public function testDuplicateClientOrderIdWithChangedIntentIsRejected(): void
    {
        $clientOrderId = $this->clientOrderId('changed-intent');
        $first = $this->adapter->placeOrder($this->placeRequest(
            clientOrderId: $clientOrderId,
            orderType: ExchangeOrderType::LIMIT,
            price: $this->restingLimitPrice(),
            postOnly: true,
        ));
        self::assertTrue($first->accepted);

        $changed = $this->adapter->placeOrder($this->placeRequest(
            clientOrderId: $clientOrderId,
            orderType: ExchangeOrderType::LIMIT,
            price: $this->restingLimitPrice() - 10.0,
            postOnly: true,
        ));

        self::assertFalse($changed->accepted);
        self::assertSame(ExchangeOrderStatus::REJECTED, $changed->status);
        self::assertSame('duplicate_client_order_id_intent_mismatch', $changed->metadata['reason'] ?? null);
    }

    public function testDuplicateClientOrderIdWithChangedTimeInForceIsRejectedWhenStoredModeIsImplicitGtc(): void
    {
        $clientOrderId = $this->clientOrderId('changed-tif');
        $first = $this->adapter->placeOrder($this->placeRequest(
            clientOrderId: $clientOrderId,
            orderType: ExchangeOrderType::LIMIT,
            price: $this->restingLimitPrice(),
            postOnly: false,
            timeInForce: ExchangeTimeInForce::GTC,
        ));
        self::assertTrue($first->accepted);

        $changed = $this->adapter->placeOrder($this->placeRequest(
            clientOrderId: $clientOrderId,
            orderType: ExchangeOrderType::LIMIT,
            price: $this->restingLimitPrice(),
            postOnly: false,
            timeInForce: ExchangeTimeInForce::IOC,
        ));

        self::assertFalse($changed->accepted);
        self::assertSame(ExchangeOrderStatus::REJECTED, $changed->status);
        self::assertSame('duplicate_client_order_id_intent_mismatch', $changed->metadata['reason'] ?? null);
    }

    public function testDuplicateClientOrderIdWithReturnedExistingOrderStillValidatesIntent(): void
    {
        $clientOrderId = $this->clientOrderId('provider-existing');
        $this->orderProvider->returnExistingOnDuplicate();
        $first = $this->adapter->placeOrder($this->placeRequest(
            clientOrderId: $clientOrderId,
            orderType: ExchangeOrderType::LIMIT,
            price: $this->restingLimitPrice(),
            postOnly: true,
        ));
        self::assertTrue($first->accepted);

        $changed = $this->adapter->placeOrder($this->placeRequest(
            clientOrderId: $clientOrderId,
            orderType: ExchangeOrderType::LIMIT,
            price: $this->restingLimitPrice() - 10.0,
            postOnly: true,
        ));

        self::assertFalse($changed->accepted);
        self::assertSame(ExchangeOrderStatus::REJECTED, $changed->status);
        self::assertSame('duplicate_client_order_id_intent_mismatch', $changed->metadata['reason'] ?? null);
    }

    public function testMarketDuplicateReplayTreatsBitmartZeroPricePlaceholderAsAbsent(): void
    {
        $request = $this->placeRequest(
            clientOrderId: $this->clientOrderId('market-zero-price'),
            orderType: ExchangeOrderType::MARKET,
            price: null,
            postOnly: false,
        );

        $first = $this->adapter->placeOrder($request);
        self::assertNotNull($first->exchangeOrderId);

        $this->orderProvider->markFilledAsMarketHistoryPlaceholder((string)$first->exchangeOrderId);
        $second = $this->adapter->placeOrder($request);

        self::assertTrue($second->accepted);
        self::assertSame($first->exchangeOrderId, $second->exchangeOrderId);
        self::assertSame(ExchangeOrderStatus::FILLED, $second->status);
    }

    public function testDuplicateClientOrderIdWithRejectedHistoryStaysRejected(): void
    {
        $request = $this->placeRequest(
            clientOrderId: $this->clientOrderId('history-rejected'),
            orderType: ExchangeOrderType::LIMIT,
            price: $this->restingLimitPrice(),
            postOnly: true,
        );

        $first = $this->adapter->placeOrder($request);
        self::assertNotNull($first->exchangeOrderId);

        $this->orderProvider->markRejected((string)$first->exchangeOrderId);
        $second = $this->adapter->placeOrder($request);

        self::assertFalse($second->accepted);
        self::assertSame(ExchangeOrderStatus::REJECTED, $second->status);
        self::assertSame('duplicate_client_order_id_original_rejected', $second->metadata['reason'] ?? null);
    }

    public function testPostOnlyDuplicateReplayToleratesMissingBitmartModeMetadata(): void
    {
        $request = $this->placeRequest(
            clientOrderId: $this->clientOrderId('post-only-missing-mode'),
            orderType: ExchangeOrderType::LIMIT,
            price: $this->restingLimitPrice(),
            postOnly: true,
        );

        $first = $this->adapter->placeOrder($request);
        self::assertNotNull($first->exchangeOrderId);

        $this->orderProvider->stripPostOnlyIntentMetadata((string)$first->exchangeOrderId);
        $second = $this->adapter->placeOrder($request);

        self::assertTrue($second->accepted);
        self::assertSame($first->exchangeOrderId, $second->exchangeOrderId);
    }

    public function testDuplicateReplayTreatsZeroPresetProtectionPricesAsAbsent(): void
    {
        $request = $this->placeRequest(
            clientOrderId: $this->clientOrderId('zero-presets'),
            orderType: ExchangeOrderType::LIMIT,
            price: $this->restingLimitPrice(),
            postOnly: true,
        );

        $first = $this->adapter->placeOrder($request);
        self::assertNotNull($first->exchangeOrderId);

        $this->orderProvider->setZeroPresetProtectionPlaceholders((string)$first->exchangeOrderId);
        $this->orderProvider->markFilled((string)$first->exchangeOrderId);
        $second = $this->adapter->placeOrder($request);

        self::assertTrue($second->accepted);
        self::assertSame($first->exchangeOrderId, $second->exchangeOrderId);
    }

    public function testEntryReplayIgnoresOpenPlanOrderWithSameClientOrderIdAndUsesHistory(): void
    {
        $request = $this->placeRequest(
            clientOrderId: $this->clientOrderId('entry-with-plan'),
            orderType: ExchangeOrderType::LIMIT,
            price: $this->restingLimitPrice(),
            postOnly: true,
        );

        $first = $this->adapter->placeOrder($request);
        self::assertNotNull($first->exchangeOrderId);

        $this->orderProvider->markFilled((string)$first->exchangeOrderId);
        $this->orderProvider->addPlanOrderForClientOrderId($request->symbol, $request->clientOrderId);
        $second = $this->adapter->placeOrder($request);

        self::assertTrue($second->accepted);
        self::assertSame($first->exchangeOrderId, $second->exchangeOrderId);
        self::assertSame(ExchangeOrderStatus::FILLED, $second->status);
    }

    private function fixedClock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
            }
        };
    }
}

final class ContractBitmartOrderProvider implements OrderProviderInterface
{
    private int $nextOrderId = 60000;

    /** @var array<string,OrderDto> */
    private array $orders = [];

    /** @var array<string,string> */
    private array $clientOrderIndex = [];

    /** @var array<int,array<string,mixed>> */
    private array $planOrders = [];

    private bool $returnExistingOnDuplicate = false;

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function placeOrder(
        string $symbol,
        OrderSide $side,
        OrderType $type,
        float $quantity,
        ?float $price = null,
        ?float $stopPrice = null,
        array $options = []
    ): ?OrderDto {
        $clientOrderId = (string)($options['client_order_id'] ?? '');
        if ($clientOrderId !== '' && isset($this->clientOrderIndex[$this->clientOrderKey($symbol, $clientOrderId)])) {
            if ($this->returnExistingOnDuplicate) {
                return $this->orders[$this->clientOrderIndex[$this->clientOrderKey($symbol, $clientOrderId)]] ?? null;
            }

            return null;
        }

        $orderId = 'bitmart-' . $this->nextOrderId++;
        $order = new OrderDto(
            orderId: $orderId,
            symbol: strtoupper($symbol),
            side: $side,
            type: $type,
            status: OrderStatus::PENDING,
            quantity: BigDecimal::of((string)$quantity),
            price: $price !== null ? BigDecimal::of((string)$price) : null,
            stopPrice: $stopPrice !== null ? BigDecimal::of((string)$stopPrice) : null,
            filledQuantity: BigDecimal::of('0'),
            remainingQuantity: BigDecimal::of((string)$quantity),
            averagePrice: null,
            createdAt: $this->clock->now(),
            metadata: $options,
        );

        $this->orders[$orderId] = $order;
        if ($clientOrderId !== '') {
            $this->clientOrderIndex[$this->clientOrderKey($symbol, $clientOrderId)] = $orderId;
        }

        return $order;
    }

    public function cancelOrder(string $symbol, string $orderId): bool
    {
        $order = $this->orders[$orderId] ?? null;
        if (!$order instanceof OrderDto || $order->symbol !== strtoupper($symbol) || !$this->isActive($order)) {
            return false;
        }

        $this->orders[$orderId] = $this->withStatus($order, OrderStatus::CANCELLED);

        return true;
    }

    public function getOrder(string $symbol, string $orderId): ?OrderDto
    {
        $order = $this->orders[$orderId] ?? null;
        if (!$order instanceof OrderDto || $order->symbol !== strtoupper($symbol)) {
            return null;
        }

        return $order;
    }

    public function getOpenOrders(?string $symbol = null): array
    {
        $symbol = $symbol !== null ? strtoupper($symbol) : null;

        return array_values(array_filter(
            $this->orders,
            fn (OrderDto $order): bool => $this->isActive($order)
                && ($symbol === null || $order->symbol === $symbol),
        ));
    }

    public function getOpenOrdersOrFail(?string $symbol = null): array
    {
        return $this->getOpenOrders($symbol);
    }

    public function getOrderHistory(string $symbol, int $limit = 100): array
    {
        return array_slice(array_values($this->orders), 0, $limit);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getPlanOrders(?string $symbol = null): array
    {
        $symbol = $symbol !== null ? strtoupper($symbol) : null;

        return array_values(array_filter(
            $this->planOrders,
            static fn (array $planOrder): bool => $symbol === null || strtoupper((string)($planOrder['symbol'] ?? '')) === $symbol,
        ));
    }

    public function cancelAllOrders(string $symbol): bool
    {
        foreach ($this->getOpenOrders($symbol) as $order) {
            $this->orders[$order->orderId] = $this->withStatus($order, OrderStatus::CANCELLED);
        }

        return true;
    }

    public function getOrderBookTop(string $symbol): SymbolBidAskDto
    {
        return new SymbolBidAskDto(
            symbol: strtoupper($symbol),
            bid: 24999.5,
            ask: 25000.5,
            timestamp: $this->clock->now(),
        );
    }

    public function submitLeverage(string $symbol, int $leverage, string $openType = 'isolated'): bool
    {
        return trim($symbol) !== '' && $leverage > 0 && trim($openType) !== '';
    }

    public function returnExistingOnDuplicate(): void
    {
        $this->returnExistingOnDuplicate = true;
    }

    public function markFilled(string $orderId, ?BigDecimal $priceOverride = null): void
    {
        $order = $this->orders[$orderId] ?? null;
        if (!$order instanceof OrderDto) {
            throw new \InvalidArgumentException(sprintf('Unknown BitMart contract order "%s"', $orderId));
        }

        $this->orders[$orderId] = new OrderDto(
            orderId: $order->orderId,
            symbol: $order->symbol,
            side: $order->side,
            type: $order->type,
            status: OrderStatus::FILLED,
            quantity: $order->quantity,
            price: $priceOverride ?? $order->price,
            stopPrice: $order->stopPrice,
            filledQuantity: $order->quantity,
            remainingQuantity: BigDecimal::of('0'),
            averagePrice: $priceOverride ?? $order->price,
            createdAt: $order->createdAt,
            updatedAt: $this->clock->now(),
            filledAt: $this->clock->now(),
            metadata: $order->metadata,
        );
    }

    public function markFilledAsMarketHistoryPlaceholder(string $orderId): void
    {
        $order = $this->orders[$orderId] ?? null;
        if (!$order instanceof OrderDto) {
            throw new \InvalidArgumentException(sprintf('Unknown BitMart contract order "%s"', $orderId));
        }

        $metadata = $order->metadata;
        unset($metadata['mode'], $metadata['post_only']);

        $this->orders[$orderId] = new OrderDto(
            orderId: $order->orderId,
            symbol: $order->symbol,
            side: $order->side,
            type: $order->type,
            status: OrderStatus::FILLED,
            quantity: $order->quantity,
            price: BigDecimal::of('0'),
            stopPrice: BigDecimal::of('0'),
            filledQuantity: $order->quantity,
            remainingQuantity: BigDecimal::of('0'),
            averagePrice: BigDecimal::of('0'),
            createdAt: $order->createdAt,
            updatedAt: $this->clock->now(),
            filledAt: $this->clock->now(),
            metadata: $metadata,
        );
    }

    public function markRejected(string $orderId): void
    {
        $order = $this->orders[$orderId] ?? null;
        if (!$order instanceof OrderDto) {
            throw new \InvalidArgumentException(sprintf('Unknown BitMart contract order "%s"', $orderId));
        }

        $this->orders[$orderId] = $this->withStatus($order, OrderStatus::REJECTED);
    }

    public function stripPostOnlyIntentMetadata(string $orderId): void
    {
        $order = $this->orders[$orderId] ?? null;
        if (!$order instanceof OrderDto) {
            throw new \InvalidArgumentException(sprintf('Unknown BitMart contract order "%s"', $orderId));
        }

        $metadata = $order->metadata;
        unset($metadata['mode'], $metadata['post_only']);

        $this->orders[$orderId] = new OrderDto(
            orderId: $order->orderId,
            symbol: $order->symbol,
            side: $order->side,
            type: $order->type,
            status: $order->status,
            quantity: $order->quantity,
            price: $order->price,
            stopPrice: $order->stopPrice,
            filledQuantity: $order->filledQuantity,
            remainingQuantity: $order->remainingQuantity,
            averagePrice: $order->averagePrice,
            createdAt: $order->createdAt,
            updatedAt: $this->clock->now(),
            filledAt: $order->filledAt,
            metadata: $metadata,
        );
    }

    public function setZeroPresetProtectionPlaceholders(string $orderId): void
    {
        $order = $this->orders[$orderId] ?? null;
        if (!$order instanceof OrderDto) {
            throw new \InvalidArgumentException(sprintf('Unknown BitMart contract order "%s"', $orderId));
        }

        $metadata = array_replace($order->metadata, [
            'preset_stop_loss_price' => '0',
            'preset_take_profit_price' => '0',
        ]);

        $this->orders[$orderId] = new OrderDto(
            orderId: $order->orderId,
            symbol: $order->symbol,
            side: $order->side,
            type: $order->type,
            status: $order->status,
            quantity: $order->quantity,
            price: $order->price,
            stopPrice: $order->stopPrice,
            filledQuantity: $order->filledQuantity,
            remainingQuantity: $order->remainingQuantity,
            averagePrice: $order->averagePrice,
            createdAt: $order->createdAt,
            updatedAt: $this->clock->now(),
            filledAt: $order->filledAt,
            metadata: $metadata,
        );
    }

    public function addPlanOrderForClientOrderId(string $symbol, string $clientOrderId): void
    {
        $this->planOrders[] = [
            'plan_order_id' => 'plan-' . substr(hash('sha256', $symbol . ':' . $clientOrderId), 0, 16),
            'symbol' => strtoupper($symbol),
            'client_order_id' => $clientOrderId,
            'side' => 3,
            'state' => 1,
            'size' => '1',
            'trigger_price' => '24800',
        ];
    }

    private function clientOrderKey(string $symbol, string $clientOrderId): string
    {
        return strtoupper($symbol) . ':' . $clientOrderId;
    }

    private function isActive(OrderDto $order): bool
    {
        return $order->status === OrderStatus::PENDING || $order->status === OrderStatus::PARTIALLY_FILLED;
    }

    private function withStatus(OrderDto $order, OrderStatus $status): OrderDto
    {
        return new OrderDto(
            orderId: $order->orderId,
            symbol: $order->symbol,
            side: $order->side,
            type: $order->type,
            status: $status,
            quantity: $order->quantity,
            price: $order->price,
            stopPrice: $order->stopPrice,
            filledQuantity: $order->filledQuantity,
            remainingQuantity: $order->remainingQuantity,
            averagePrice: $order->averagePrice,
            createdAt: $order->createdAt,
            updatedAt: $this->clock->now(),
            metadata: $order->metadata,
        );
    }
}
