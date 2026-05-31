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
use App\Exchange\Okx\OkxActionFactory;
use App\Exchange\Okx\OkxConfig;
use App\Exchange\Okx\OkxFillId;
use App\Exchange\Okx\OkxInstrumentResolver;
use App\Exchange\Okx\OkxRestClientInterface;
use App\Exchange\Reconciliation\ExchangeRestSnapshotProviderInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.exchange_adapter')]
final readonly class OkxExchangeAdapter implements ExchangeAdapterInterface, ExchangeRestSnapshotProviderInterface
{
    public function __construct(
        private OkxRestClientInterface $client,
        private OkxInstrumentResolver $instruments,
        private OkxActionFactory $actions,
        private OkxConfig $config,
        private ClockInterface $clock,
    ) {
    }

    public function exchange(): Exchange
    {
        return Exchange::OKX;
    }

    public function marketType(): MarketType
    {
        return MarketType::PERPETUAL;
    }

    public function capabilities(): ExchangeCapabilities
    {
        return new ExchangeCapabilities(
            supportsTestnet: true,
            supportsWebSocketPrivate: false,
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
        $payload = $this->client->privateGet('/api/v5/account/balance');
        $account = $this->firstDataRow($payload);
        $balances = [];
        foreach (($account['details'] ?? []) as $detail) {
            if (!\is_array($detail)) {
                continue;
            }
            $currency = strtoupper((string)($detail['ccy'] ?? ''));
            if ($currency === '') {
                continue;
            }
            $balances[] = new ExchangeBalanceDto(
                exchange: $this->exchange(),
                marketType: $this->marketType(),
                currency: $currency,
                available: $this->float($detail['availEq'] ?? $detail['availBal'] ?? null),
                total: $this->float($detail['eq'] ?? null),
                equity: $this->float($detail['eq'] ?? null),
                unrealizedPnl: $this->float($detail['upl'] ?? null),
                metadata: $detail,
            );
        }

        return $balances;
    }

    public function getOpenPositions(?string $symbol = null): array
    {
        $query = ['instType' => 'SWAP'];
        if ($symbol !== null) {
            $query['instId'] = $this->instruments->instId($symbol);
        }
        $payload = $this->client->privateGet('/api/v5/account/positions', $query);
        $positions = [];
        foreach ($this->dataRows($payload) as $row) {
            $size = $this->float($row['pos'] ?? null);
            if (abs($size) <= 0.00000001) {
                continue;
            }
            $side = $this->positionSide($row['posSide'] ?? null, $size);
            $positions[] = new ExchangePositionDto(
                exchange: $this->exchange(),
                marketType: $this->marketType(),
                symbol: $this->instruments->symbol((string)($row['instId'] ?? '')),
                side: $side,
                size: abs($size),
                entryPrice: $this->float($row['avgPx'] ?? null),
                markPrice: $this->float($row['markPx'] ?? null),
                unrealizedPnl: $this->float($row['upl'] ?? null),
                realizedPnl: $this->float($row['realizedPnl'] ?? null),
                margin: $this->float($row['margin'] ?? $row['imr'] ?? null),
                leverage: $this->float($row['lever'] ?? null),
                updatedAt: $this->time($row['uTime'] ?? null),
                metadata: $row,
            );
        }

        return $positions;
    }

    public function getOpenOrders(?string $symbol = null): array
    {
        $query = ['instType' => 'SWAP'];
        if ($symbol !== null) {
            $query['instId'] = $this->instruments->instId($symbol);
        }

        $orders = [];
        foreach ($this->dataRows($this->client->privateGet('/api/v5/trade/orders-pending', $query)) as $row) {
            $orders[] = $this->mapOrder($row, false);
        }

        $algoQuery = $query + ['ordType' => 'conditional'];
        foreach ($this->dataRows($this->client->privateGet('/api/v5/trade/orders-algo-pending', $algoQuery)) as $row) {
            $orders[] = $this->mapOrder($row, true);
        }

        return $orders;
    }

    public function placeOrder(PlaceOrderRequest $request): PlaceOrderResult
    {
        $this->assertContext($request->exchange, $request->marketType);
        $this->config->assertTradingConfigured();
        $instId = $this->instruments->instId($request->symbol);
        $isAlgo = $this->actions->isTriggerOrder($request);
        $response = $isAlgo
            ? $this->client->privatePost('/api/v5/trade/order-algo', $this->actions->algoOrder($instId, $request))
            : $this->client->privatePost('/api/v5/trade/order', $this->actions->order($instId, $request));
        $status = $this->responseStatus($response);
        $responseOrderId = $this->responseOrderId($response, $isAlgo);
        $accepted = $status !== ExchangeOrderStatus::REJECTED;
        if (!$accepted) {
            $existing = $this->findOpenOrderByClientOrderId($request->symbol, $this->actions->clientOrderId($request->clientOrderId));
            if ($existing instanceof ExchangeOrderDto) {
                return new PlaceOrderResult(
                    accepted: true,
                    symbol: $request->symbol,
                    clientOrderId: $request->clientOrderId,
                    exchangeOrderId: $existing->exchangeOrderId,
                    status: $existing->status,
                    submittedAt: $this->clock->now(),
                    order: $existing,
                    metadata: [
                        'idempotent_replay_after_reject' => true,
                        'source_response' => $response,
                    ],
                );
            }
        }
        $exchangeOrderId = $accepted ? ($responseOrderId ?? $request->clientOrderId) : null;

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
                exchangeOrderId: $exchangeOrderId ?? $request->clientOrderId,
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
                metadata: ['source' => $isAlgo ? 'okx_algo_order' : 'okx_order'] + $response,
            ),
            metadata: $response,
        );
    }

    public function cancelOrder(CancelOrderRequest $request): CancelOrderResult
    {
        $this->assertContext($request->exchange, $request->marketType);
        $this->config->assertTradingConfigured();
        $instId = $this->instruments->instId($request->symbol);
        $isAlgo = $this->isAlgoCancelRequest($instId, $request);
        $response = $isAlgo
            ? $this->client->privatePost('/api/v5/trade/cancel-algos', [$this->actions->cancelAlgo($instId, $request)])
            : $this->client->privatePost('/api/v5/trade/cancel-order', $this->actions->cancelOrder($instId, $request));
        $status = $this->responseStatus($response);
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

    private function isAlgoCancelRequest(string $instId, CancelOrderRequest $request): bool
    {
        if ($request->exchangeOrderId !== null && str_starts_with($request->exchangeOrderId, 'algo:')) {
            return true;
        }
        if ($request->exchangeOrderId !== null && trim($request->exchangeOrderId) !== '') {
            return false;
        }
        if ($request->clientOrderId === null || trim($request->clientOrderId) === '') {
            return false;
        }

        $clientOrderId = $this->actions->clientOrderId($request->clientOrderId);
        $query = [
            'instType' => 'SWAP',
            'instId' => $instId,
            'ordType' => 'conditional',
        ];
        foreach ($this->dataRows($this->client->privateGet('/api/v5/trade/orders-algo-pending', $query)) as $row) {
            if ((string)($row['algoClOrdId'] ?? '') === $clientOrderId) {
                return true;
            }
        }

        return false;
    }

    private function findOpenOrderByClientOrderId(string $symbol, string $clientOrderId): ?ExchangeOrderDto
    {
        foreach ($this->getOpenOrders($symbol) as $order) {
            if ($order->clientOrderId === $clientOrderId) {
                return $order;
            }
        }

        return null;
    }

    public function getOrderBookTop(string $symbol): SymbolBidAskDto
    {
        $payload = $this->client->publicGet('/api/v5/market/books', [
            'instId' => $this->instruments->instId($symbol),
            'sz' => 1,
        ]);
        $book = $this->firstDataRow($payload);
        $bids = \is_array($book['bids'] ?? null) ? $book['bids'] : [];
        $asks = \is_array($book['asks'] ?? null) ? $book['asks'] : [];

        return new SymbolBidAskDto(
            symbol: strtoupper($symbol),
            bid: $this->float($bids[0][0] ?? null),
            ask: $this->float($asks[0][0] ?? null),
            timestamp: $this->clock->now(),
        );
    }

    public function setLeverage(string $symbol, int $leverage, string $marginMode): bool
    {
        $this->config->assertTradingConfigured();
        foreach ($this->actions->setLeverageRequests($this->instruments->instId($symbol), $leverage, $marginMode) as $body) {
            $response = $this->client->privatePost('/api/v5/account/set-leverage', $body);
            if ($this->responseStatus($response) === ExchangeOrderStatus::REJECTED) {
                return false;
            }
        }

        return true;
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
            metadata: ['source' => 'okx_rest_snapshot'],
        );
    }

    public function getOrdersSnapshot(?string $symbol = null): array
    {
        return $this->getOpenOrders($symbol);
    }

    public function getFillsSnapshot(?string $symbol = null): array
    {
        $query = ['instType' => 'SWAP'];
        if ($symbol !== null) {
            $query['instId'] = $this->instruments->instId($symbol);
        }

        $fills = [];
        foreach ($this->dataRows($this->client->privateGet('/api/v5/trade/fills', $query)) as $row) {
            $fills[] = new ExchangeFillDto(
                exchange: $this->exchange(),
                marketType: $this->marketType(),
                symbol: $this->instruments->symbol((string)($row['instId'] ?? '')),
                exchangeOrderId: (string)($row['ordId'] ?? ''),
                clientOrderId: isset($row['clOrdId']) ? (string) $row['clOrdId'] : null,
                fillId: OkxFillId::fromTradeId($row['instId'] ?? '', $row['tradeId'] ?? null),
                side: $this->orderSide($row['side'] ?? null),
                positionSide: $this->nullablePositionSide($row['posSide'] ?? null),
                quantity: $this->float($row['fillSz'] ?? null),
                price: $this->float($row['fillPx'] ?? null),
                fee: $this->float($row['fee'] ?? null),
                feeCurrency: isset($row['feeCcy']) ? (string) $row['feeCcy'] : null,
                filledAt: $this->time($row['ts'] ?? null),
                metadata: $row,
            );
        }

        return $fills;
    }

    public function hasAuthoritativePositionSnapshot(?string $symbol = null): bool
    {
        return trim($this->config->apiKey) !== ''
            && trim($this->config->apiSecret) !== ''
            && trim($this->config->apiPassphrase) !== '';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function mapOrder(array $row, bool $algo): ExchangeOrderDto
    {
        $side = $this->orderSide($row['side'] ?? null);
        $orderType = $this->orderType($row, $algo);
        $quantity = $this->float($row['sz'] ?? null);
        $filled = $this->float($row['accFillSz'] ?? null);
        $exchangeOrderId = $algo
            ? 'algo:' . (string)($row['algoId'] ?? '')
            : (string)($row['ordId'] ?? '');

        return new ExchangeOrderDto(
            exchange: $this->exchange(),
            marketType: $this->marketType(),
            symbol: $this->instruments->symbol((string)($row['instId'] ?? '')),
            exchangeOrderId: $exchangeOrderId,
            clientOrderId: isset($row[$algo ? 'algoClOrdId' : 'clOrdId']) ? (string) $row[$algo ? 'algoClOrdId' : 'clOrdId'] : null,
            side: $side,
            positionSide: $this->nullablePositionSide($row['posSide'] ?? null),
            orderType: $orderType,
            status: $this->orderStatus($row['state'] ?? null),
            quantity: $quantity,
            filledQuantity: $filled,
            remainingQuantity: max(0.0, $quantity - $filled),
            price: $this->floatOrNull($row['px'] ?? null),
            averagePrice: $this->floatOrNull($row['avgPx'] ?? null),
            stopPrice: $this->stopPrice($row, $orderType),
            reduceOnly: $this->bool($row['reduceOnly'] ?? false),
            postOnly: strtolower((string)($row['ordType'] ?? '')) === 'post_only',
            timeInForce: $this->timeInForce($row['ordType'] ?? null),
            createdAt: $this->time($row['cTime'] ?? $row['uTime'] ?? null),
            updatedAt: $this->time($row['uTime'] ?? null),
            metadata: $row,
        );
    }

    private function orderType(array $row, bool $algo): ExchangeOrderType
    {
        if ($algo) {
            return isset($row['tpTriggerPx']) && (string) $row['tpTriggerPx'] !== ''
                ? ExchangeOrderType::TAKE_PROFIT
                : ExchangeOrderType::STOP_LOSS;
        }

        return strtolower((string)($row['ordType'] ?? '')) === 'market'
            ? ExchangeOrderType::MARKET
            : ExchangeOrderType::LIMIT;
    }

    private function stopPrice(array $row, ExchangeOrderType $orderType): ?float
    {
        if ($orderType === ExchangeOrderType::TAKE_PROFIT) {
            return $this->floatOrNull($row['tpTriggerPx'] ?? null);
        }
        if ($orderType === ExchangeOrderType::STOP_LOSS) {
            return $this->floatOrNull($row['slTriggerPx'] ?? null);
        }

        return null;
    }

    private function orderSide(mixed $side): ExchangeOrderSide
    {
        return strtolower((string) $side) === 'sell' ? ExchangeOrderSide::SELL : ExchangeOrderSide::BUY;
    }

    private function positionSide(mixed $side, float $size): ExchangePositionSide
    {
        $normalized = strtolower((string) $side);
        if ($normalized === 'short') {
            return ExchangePositionSide::SHORT;
        }
        if ($normalized === 'long') {
            return ExchangePositionSide::LONG;
        }

        return $size < 0 ? ExchangePositionSide::SHORT : ExchangePositionSide::LONG;
    }

    private function nullablePositionSide(mixed $side): ?ExchangePositionSide
    {
        $normalized = strtolower((string) $side);

        return match ($normalized) {
            'long' => ExchangePositionSide::LONG,
            'short' => ExchangePositionSide::SHORT,
            default => null,
        };
    }

    private function timeInForce(mixed $ordType): ExchangeTimeInForce
    {
        return match (strtolower((string) $ordType)) {
            'ioc' => ExchangeTimeInForce::IOC,
            'fok' => ExchangeTimeInForce::FOK,
            default => ExchangeTimeInForce::GTC,
        };
    }

    private function orderStatus(mixed $state): ExchangeOrderStatus
    {
        return match (strtolower((string) $state)) {
            'filled' => ExchangeOrderStatus::FILLED,
            'partially_filled' => ExchangeOrderStatus::PARTIALLY_FILLED,
            'canceled', 'cancelled' => ExchangeOrderStatus::CANCELLED,
            'rejected' => ExchangeOrderStatus::REJECTED,
            'live', 'effective' => ExchangeOrderStatus::OPEN,
            default => ExchangeOrderStatus::PENDING,
        };
    }

    private function responseStatus(array $response): ExchangeOrderStatus
    {
        if ((string)($response['code'] ?? '') !== '0') {
            return ExchangeOrderStatus::REJECTED;
        }
        $row = $this->firstDataRow($response);
        if ($row !== [] && (string)($row['sCode'] ?? '0') !== '0') {
            return ExchangeOrderStatus::REJECTED;
        }

        return ExchangeOrderStatus::PENDING;
    }

    private function responseOrderId(array $response, bool $algo): ?string
    {
        $row = $this->firstDataRow($response);
        if ($algo && isset($row['algoId'])) {
            return 'algo:' . (string) $row['algoId'];
        }
        if (!$algo && isset($row['ordId'])) {
            return (string) $row['ordId'];
        }

        return null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function dataRows(array $response): array
    {
        $data = $response['data'] ?? [];
        if (!\is_array($data)) {
            return [];
        }

        return array_values(array_filter($data, \is_array(...)));
    }

    /**
     * @return array<string,mixed>
     */
    private function firstDataRow(array $response): array
    {
        $rows = $this->dataRows($response);

        return $rows[0] ?? [];
    }

    private function assertContext(Exchange $exchange, MarketType $marketType): void
    {
        if ($exchange !== $this->exchange() || $marketType !== $this->marketType()) {
            throw new \InvalidArgumentException(sprintf('OKX adapter cannot handle "%s::%s"', $exchange->value, $marketType->value));
        }
    }

    private function time(mixed $milliseconds): \DateTimeImmutable
    {
        if (is_numeric($milliseconds)) {
            return (new \DateTimeImmutable('@' . ((int) floor(((float) $milliseconds) / 1000))))->setTimezone(new \DateTimeZone('UTC'));
        }

        return $this->clock->now();
    }

    private function bool(mixed $value): bool
    {
        return \filter_var($value, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }

    private function float(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
