<?php

declare(strict_types=1);

namespace App\Exchange\Adapter;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Contract\Provider\Dto\SymbolBidAskDto;
use App\Exchange\Contract\ExchangeAdapterInterface;
use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\CancelOrderResult;
use App\Exchange\Dto\ExchangeBalanceDto;
use App\Exchange\Dto\ExchangeCapabilities;
use App\Exchange\Dto\ExchangeFillDto;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Dto\ExchangeReconciliationResult;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Dto\PlaceOrderResult;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Hyperliquid\HyperliquidActionFactory;
use App\Exchange\Hyperliquid\HyperliquidAssetResolver;
use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Hyperliquid\HyperliquidRestClientInterface;
use App\Exchange\Reconciliation\ExchangeRestSnapshotProviderInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.exchange_adapter')]
final readonly class HyperliquidExchangeAdapter implements ExchangeAdapterInterface, ExchangeRestSnapshotProviderInterface
{
    private const DEFAULT_MARKET_SLIPPAGE = 0.05;

    public function __construct(
        private HyperliquidRestClientInterface $client,
        private HyperliquidAssetResolver $assets,
        private HyperliquidActionFactory $actions,
        private HyperliquidConfig $config,
        private ClockInterface $clock,
    ) {
    }

    public function exchange(): Exchange
    {
        return Exchange::HYPERLIQUID;
    }

    public function marketType(): MarketType
    {
        return MarketType::PERPETUAL;
    }

    public function capabilities(): ExchangeCapabilities
    {
        return new ExchangeCapabilities(
            supportsTestnet: true,
            supportsWebSocketPrivate: true,
            supportsClientOrderId: true,
            supportsCancelByClientOrderId: true,
            supportsPostOnly: true,
            supportsIoc: true,
            supportsReduceOnly: true,
            supportsAttachedStopLossOnEntry: false,
            supportsAttachedTakeProfitOnEntry: false,
            supportsTriggerOrders: true,
            supportsModifyOrder: false,
            requiresSeparateLeverageSubmit: true,
            supportsPerSymbolLeverage: true,
        );
    }

    public function getBalances(): array
    {
        $state = $this->userState();
        $margin = \is_array($state['marginSummary'] ?? null) ? $state['marginSummary'] : [];

        return [
            new ExchangeBalanceDto(
                exchange: $this->exchange(),
                marketType: $this->marketType(),
                currency: 'USDC',
                available: $this->float($state['withdrawable'] ?? null),
                total: $this->float($margin['accountValue'] ?? null),
                equity: $this->float($margin['accountValue'] ?? null),
                unrealizedPnl: $this->unrealizedPnl($state),
                metadata: ['source' => 'hyperliquid_clearinghouse_state'] + $margin,
            ),
        ];
    }

    public function getOpenPositions(?string $symbol = null): array
    {
        $target = $symbol !== null ? $this->assets->coin($symbol) : null;
        $positions = [];
        foreach (($this->userState()['assetPositions'] ?? []) as $row) {
            if (!\is_array($row) || !\is_array($row['position'] ?? null)) {
                continue;
            }
            $position = $row['position'];
            $coin = strtoupper((string)($position['coin'] ?? ''));
            if ($target !== null && $coin !== $target) {
                continue;
            }
            $size = $this->float($position['szi'] ?? null);
            if (abs($size) <= 0.00000001) {
                continue;
            }
            $positions[] = new ExchangePositionDto(
                exchange: $this->exchange(),
                marketType: $this->marketType(),
                symbol: $coin . 'USDC',
                side: $size < 0 ? ExchangePositionSide::SHORT : ExchangePositionSide::LONG,
                size: abs($size),
                entryPrice: $this->float($position['entryPx'] ?? null),
                markPrice: $this->float($position['markPx'] ?? null),
                unrealizedPnl: $this->float($position['unrealizedPnl'] ?? null),
                realizedPnl: null,
                margin: $this->float($position['marginUsed'] ?? null),
                leverage: $this->float($position['leverage']['value'] ?? null),
                metadata: $position,
            );
        }

        return $positions;
    }

    public function getOpenOrders(?string $symbol = null): array
    {
        $orders = $this->client->info([
            'type' => 'frontendOpenOrders',
            'user' => $this->accountAddress(),
        ]);
        $target = $symbol !== null ? $this->assets->coin($symbol) : null;
        $mapped = [];
        foreach ($orders as $order) {
            if (!\is_array($order)) {
                continue;
            }
            $coin = strtoupper((string)($order['coin'] ?? ''));
            if ($target !== null && $coin !== $target) {
                continue;
            }
            $mapped[] = $this->mapOrder($order, ExchangeOrderStatus::OPEN);
        }

        return $mapped;
    }

    public function placeOrder(PlaceOrderRequest $request): PlaceOrderResult
    {
        $this->assertContext($request->exchange, $request->marketType);
        $this->config->assertTradingConfigured();
        $request = $this->withHyperliquidExecutionPrice($request);
        $assetId = $this->assets->assetId($request->symbol);
        $response = $this->client->exchange($this->actions->order($assetId, $request));
        $status = $this->extractOrderStatus($response);
        $exchangeOrderId = $this->extractOrderId($response) ?? $request->clientOrderId;
        $accepted = $status !== ExchangeOrderStatus::REJECTED;

        return new PlaceOrderResult(
            accepted: $accepted,
            symbol: $request->symbol,
            clientOrderId: $request->clientOrderId,
            exchangeOrderId: $exchangeOrderId,
            status: $status,
            submittedAt: $this->clock->now(),
            order: new ExchangeOrderDto(
                exchange: $this->exchange(),
                marketType: $this->marketType(),
                symbol: strtoupper($request->symbol),
                exchangeOrderId: $exchangeOrderId,
                clientOrderId: $request->clientOrderId,
                side: $request->side,
                positionSide: $request->positionSide,
                orderType: $request->orderType,
                status: $status,
                quantity: $request->quantity,
                filledQuantity: 0.0,
                remainingQuantity: $request->quantity,
                price: $request->price,
                averagePrice: null,
                stopPrice: $request->stopPrice,
                reduceOnly: $request->reduceOnly,
                postOnly: $request->postOnly,
                timeInForce: $request->timeInForce,
                createdAt: $this->clock->now(),
                metadata: ['source' => 'hyperliquid_exchange'] + $response,
            ),
            metadata: $response,
        );
    }

    public function cancelOrder(CancelOrderRequest $request): CancelOrderResult
    {
        $this->assertContext($request->exchange, $request->marketType);
        $this->config->assertTradingConfigured();
        $assetId = $this->assets->assetId($request->symbol);
        $response = $this->client->exchange($this->actions->cancel($assetId, $request));
        $status = $this->extractOrderStatus($response);
        $cancelled = $status !== ExchangeOrderStatus::REJECTED;

        return new CancelOrderResult(
            cancelled: $cancelled,
            symbol: $request->symbol,
            exchangeOrderId: $request->exchangeOrderId,
            clientOrderId: $request->clientOrderId,
            status: $cancelled ? ExchangeOrderStatus::CANCELLED : ExchangeOrderStatus::REJECTED,
            metadata: $response,
        );
    }

    public function getOrder(string $symbol, string $exchangeOrderId): ?ExchangeOrderDto
    {
        foreach ($this->getOpenOrders($symbol) as $order) {
            if ($order->exchangeOrderId === $exchangeOrderId || $order->clientOrderId === $exchangeOrderId) {
                return $order;
            }
        }

        return null;
    }

    public function getOrderBookTop(string $symbol): SymbolBidAskDto
    {
        $book = $this->client->info([
            'type' => 'l2Book',
            'coin' => $this->assets->coin($symbol),
        ]);
        $levels = $book['levels'] ?? [];
        $bids = \is_array($levels[0] ?? null) ? $levels[0] : [];
        $asks = \is_array($levels[1] ?? null) ? $levels[1] : [];

        return new SymbolBidAskDto(
            symbol: strtoupper($symbol),
            bid: $this->float($bids[0]['px'] ?? null),
            ask: $this->float($asks[0]['px'] ?? null),
            timestamp: $this->clock->now(),
        );
    }

    public function setLeverage(string $symbol, int $leverage, string $marginMode): bool
    {
        $this->config->assertTradingConfigured();
        $response = $this->client->exchange($this->actions->updateLeverage(
            $this->assets->assetId($symbol),
            $leverage,
            $marginMode,
        ));

        return $this->extractOrderStatus($response) !== ExchangeOrderStatus::REJECTED;
    }

    public function reconcile(?string $symbol = null): ExchangeReconciliationResult
    {
        $now = $this->clock->now();

        return new ExchangeReconciliationResult(
            exchange: $this->exchange(),
            marketType: $this->marketType(),
            symbol: $symbol !== null ? strtoupper($symbol) : null,
            startedAt: $now,
            completedAt: $now,
            ordersChecked: \count($this->getOrdersSnapshot($symbol)),
            positionsChecked: \count($this->getOpenPositions($symbol)),
            fillsImported: \count($this->getFillsSnapshot($symbol)),
            metadata: ['source' => 'hyperliquid_rest_snapshot'],
        );
    }

    public function getOrdersSnapshot(?string $symbol = null): array
    {
        return $this->getOpenOrders($symbol);
    }

    public function getFillsSnapshot(?string $symbol = null): array
    {
        $fills = $this->client->info([
            'type' => 'userFills',
            'user' => $this->accountAddress(),
        ]);
        $target = $symbol !== null ? $this->assets->coin($symbol) : null;
        $mapped = [];
        foreach ($fills as $fill) {
            if (!\is_array($fill)) {
                continue;
            }
            $coin = strtoupper((string)($fill['coin'] ?? ''));
            if ($target !== null && $coin !== $target) {
                continue;
            }
            $side = strtolower((string)($fill['side'] ?? '')) === 'b' ? ExchangeOrderSide::BUY : ExchangeOrderSide::SELL;
            $mapped[] = new ExchangeFillDto(
                exchange: $this->exchange(),
                marketType: $this->marketType(),
                symbol: $coin . 'USDC',
                exchangeOrderId: (string)($fill['oid'] ?? ''),
                clientOrderId: isset($fill['cloid']) ? (string) $fill['cloid'] : null,
                fillId: isset($fill['hash']) ? (string) $fill['hash'] : null,
                side: $side,
                positionSide: null,
                quantity: $this->float($fill['sz'] ?? null),
                price: $this->float($fill['px'] ?? null),
                fee: $this->float($fill['fee'] ?? null),
                feeCurrency: 'USDC',
                filledAt: $this->time($fill['time'] ?? null),
                metadata: $fill,
            );
        }

        return $mapped;
    }

    public function hasAuthoritativePositionSnapshot(?string $symbol = null): bool
    {
        return trim($this->config->accountAddress) !== '';
    }

    private function userState(): array
    {
        return $this->client->info([
            'type' => 'clearinghouseState',
            'user' => $this->accountAddress(),
        ]);
    }

    private function accountAddress(): string
    {
        $address = trim($this->config->accountAddress);
        if ($address === '') {
            throw new \RuntimeException('HYPERLIQUID_ACCOUNT_ADDRESS is required for account snapshots.');
        }

        return $address;
    }

    /**
     * @param array<string,mixed> $order
     */
    private function mapOrder(array $order, ExchangeOrderStatus $status): ExchangeOrderDto
    {
        $side = strtolower((string)($order['side'] ?? '')) === 'b' ? ExchangeOrderSide::BUY : ExchangeOrderSide::SELL;
        $orderType = $this->mapOrderType($order);
        $timeInForce = $this->mapTimeInForce($order);
        $reduceOnly = (bool)($order['reduceOnly'] ?? false);

        return new ExchangeOrderDto(
            exchange: $this->exchange(),
            marketType: $this->marketType(),
            symbol: strtoupper((string)($order['coin'] ?? '')) . 'USDC',
            exchangeOrderId: (string)($order['oid'] ?? ''),
            clientOrderId: isset($order['cloid']) ? (string) $order['cloid'] : null,
            side: $side,
            positionSide: $this->positionSide($side, $reduceOnly),
            orderType: $orderType,
            status: $status,
            quantity: $this->float($order['sz'] ?? null),
            filledQuantity: max(0.0, $this->float($order['origSz'] ?? null) - $this->float($order['sz'] ?? null)),
            remainingQuantity: $this->float($order['sz'] ?? null),
            price: $this->float($order['limitPx'] ?? $order['px'] ?? null),
            averagePrice: null,
            stopPrice: $this->triggerPrice($orderType, $order),
            reduceOnly: $reduceOnly,
            postOnly: $timeInForce === ExchangeTimeInForce::GTC && strtolower((string)($order['tif'] ?? $order['orderType'] ?? '')) === 'alo',
            timeInForce: $timeInForce,
            createdAt: $this->time($order['timestamp'] ?? null),
            metadata: $order,
        );
    }

    private function withHyperliquidExecutionPrice(PlaceOrderRequest $request): PlaceOrderRequest
    {
        if ($request->orderType === ExchangeOrderType::MARKET && $request->price === null) {
            return $this->copyRequestWithPrice($request, $this->marketOrderPriceCap($request->symbol, $request->side));
        }

        return $request;
    }

    private function marketOrderPriceCap(string $symbol, ExchangeOrderSide $side): float
    {
        $top = $this->getOrderBookTop($symbol);
        $bid = $top->bid;
        $ask = $top->ask;
        if ($bid > 0.0 && $ask > 0.0) {
            $reference = ($bid + $ask) / 2.0;
        } else {
            $reference = $side === ExchangeOrderSide::BUY ? $ask : $bid;
        }
        if ($reference <= 0.0 || !\is_finite($reference)) {
            throw new \RuntimeException(sprintf('Cannot derive Hyperliquid market slippage cap for "%s"', $symbol));
        }

        return $this->slippageCap($reference, $side);
    }

    private function slippageCap(float $reference, ExchangeOrderSide $side): float
    {
        $cap = $side === ExchangeOrderSide::BUY
            ? $reference * (1.0 + self::DEFAULT_MARKET_SLIPPAGE)
            : $reference * (1.0 - self::DEFAULT_MARKET_SLIPPAGE);

        return max(0.00000001, $cap);
    }

    private function copyRequestWithPrice(PlaceOrderRequest $request, float $price): PlaceOrderRequest
    {
        return new PlaceOrderRequest(
            exchange: $request->exchange,
            marketType: $request->marketType,
            symbol: $request->symbol,
            side: $request->side,
            positionSide: $request->positionSide,
            orderType: $request->orderType,
            timeInForce: $request->timeInForce,
            quantity: $request->quantity,
            price: $price,
            stopPrice: $request->stopPrice,
            reduceOnly: $request->reduceOnly,
            postOnly: $request->postOnly,
            leverage: $request->leverage,
            marginMode: $request->marginMode,
            clientOrderId: $request->clientOrderId,
            attachedStopLossPrice: $request->attachedStopLossPrice,
            attachedTakeProfitPrice: $request->attachedTakeProfitPrice,
            metadata: $request->metadata,
        );
    }

    private function mapOrderType(array $order): ExchangeOrderType
    {
        $orderType = strtolower((string)($order['orderType'] ?? ''));
        $triggerCondition = strtolower((string)($order['triggerCondition'] ?? ''));
        $tpsl = strtolower((string)($order['tpsl'] ?? ''));
        $isTrigger = ($order['isTrigger'] ?? false) === true || isset($order['triggerPx']);

        if ($isTrigger) {
            if ($tpsl === 'tp' || str_contains($orderType, 'take') || str_contains($triggerCondition, 'take profit')) {
                return ExchangeOrderType::TAKE_PROFIT;
            }

            return ExchangeOrderType::STOP_LOSS;
        }

        return ExchangeOrderType::LIMIT;
    }

    private function mapTimeInForce(array $order): ExchangeTimeInForce
    {
        return match (strtolower((string)($order['tif'] ?? $order['orderType'] ?? ''))) {
            'ioc' => ExchangeTimeInForce::IOC,
            default => ExchangeTimeInForce::GTC,
        };
    }

    private function triggerPrice(ExchangeOrderType $orderType, array $order): ?float
    {
        if (!\in_array($orderType, [ExchangeOrderType::STOP_LOSS, ExchangeOrderType::TAKE_PROFIT, ExchangeOrderType::TRIGGER], true)) {
            return null;
        }

        return $this->float($order['triggerPx'] ?? null);
    }

    private function positionSide(ExchangeOrderSide $side, bool $reduceOnly): ExchangePositionSide
    {
        if ($reduceOnly) {
            return $side === ExchangeOrderSide::SELL ? ExchangePositionSide::LONG : ExchangePositionSide::SHORT;
        }

        return $side === ExchangeOrderSide::BUY ? ExchangePositionSide::LONG : ExchangePositionSide::SHORT;
    }

    private function extractOrderStatus(array $response): ExchangeOrderStatus
    {
        $status = strtolower((string)($response['status'] ?? ''));
        if ($status === 'err' || $status === 'error') {
            return ExchangeOrderStatus::REJECTED;
        }

        $statuses = $response['response']['data']['statuses'] ?? null;
        if (\is_array($statuses) && isset($statuses[0]['error'])) {
            return ExchangeOrderStatus::REJECTED;
        }
        if (\is_array($statuses) && isset($statuses[0]['filled'])) {
            return ExchangeOrderStatus::FILLED;
        }

        return ExchangeOrderStatus::PENDING;
    }

    private function extractOrderId(array $response): ?string
    {
        $statuses = $response['response']['data']['statuses'] ?? null;
        if (\is_array($statuses)) {
            $resting = $statuses[0]['resting'] ?? null;
            $filled = $statuses[0]['filled'] ?? null;
            if (\is_array($resting) && isset($resting['oid'])) {
                return (string) $resting['oid'];
            }
            if (\is_array($filled) && isset($filled['oid'])) {
                return (string) $filled['oid'];
            }
        }

        return null;
    }

    private function assertContext(Exchange $exchange, MarketType $marketType): void
    {
        if ($exchange !== $this->exchange() || $marketType !== $this->marketType()) {
            throw new \InvalidArgumentException(sprintf('Hyperliquid adapter cannot handle "%s::%s"', $exchange->value, $marketType->value));
        }
    }

    private function time(mixed $milliseconds): \DateTimeImmutable
    {
        if (is_numeric($milliseconds)) {
            return (new \DateTimeImmutable('@' . ((int) floor(((float) $milliseconds) / 1000))))->setTimezone(new \DateTimeZone('UTC'));
        }

        return $this->clock->now();
    }

    /**
     * @param array<string,mixed> $state
     */
    private function unrealizedPnl(array $state): float
    {
        $total = 0.0;
        foreach (($state['assetPositions'] ?? []) as $row) {
            if (!\is_array($row) || !\is_array($row['position'] ?? null)) {
                continue;
            }

            $total += $this->float($row['position']['unrealizedPnl'] ?? null);
        }

        return $total;
    }

    private function float(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
