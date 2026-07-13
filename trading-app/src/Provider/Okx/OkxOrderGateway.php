<?php

declare(strict_types=1);

namespace App\Provider\Okx;

use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderType;
use App\Contract\Provider\Dto\OrderDto;
use App\Contract\Provider\Dto\SymbolBidAskDto;
use App\Contract\Provider\OrderProviderInterface;
use App\Exchange\Okx\OkxInstrumentResolver;
use App\Exchange\Okx\OkxRestClientInterface;

final class OkxOrderGateway implements OrderProviderInterface
{
    private const PRIVATE_PAGE_SIZE = 100;
    private const PRIVATE_SNAPSHOT_MAX_ITEMS = 1_000;
    private const TERMINAL_ALGO_HISTORY_STATES = ['effective', 'canceled', 'order_failed'];

    private OkxPublicReadMapper $mapper;
    private OkxPrivateReadMapper $privateMapper;

    public function __construct(
        private readonly ?OkxRestClientInterface $client = null,
        private readonly ?OkxInstrumentResolver $instruments = null,
    ) {
        $this->mapper = new OkxPublicReadMapper($this->resolver());
        $this->privateMapper = new OkxPrivateReadMapper($this->resolver());
    }

    /**
     * @param array<string, mixed> $options
     */
    public function placeOrder(
        string $symbol,
        OrderSide $side,
        OrderType $type,
        float $quantity,
        ?float $price = null,
        ?float $stopPrice = null,
        array $options = [],
    ): ?OrderDto {
        throw $this->writeNotImplemented(__METHOD__);
    }

    public function cancelOrder(string $symbol, string $orderId): bool
    {
        throw $this->writeNotImplemented(__METHOD__);
    }

    public function getOrder(string $symbol, string $orderId): ?OrderDto
    {
        foreach ($this->getOpenOrdersOrFail($symbol) as $order) {
            if ($order->orderId === $orderId || (string) ($order->metadata['client_order_id'] ?? '') === $orderId) {
                return $order;
            }
        }

        return $this->fetchOrderDetail($symbol, $orderId);
    }

    private function fetchOrderDetail(string $symbol, string $orderId): ?OrderDto
    {
        if (str_starts_with($orderId, 'algo:')) {
            return $this->fetchAlgoOrderDetail($symbol, ['algoId' => substr($orderId, 5)]);
        }

        $baseQuery = ['instId' => $this->resolver()->instId($symbol)];
        foreach (['ordId', 'clOrdId'] as $key) {
            $row = $this->firstOrderDetailRow($this->privateGet('/api/v5/trade/order', $baseQuery + [$key => $orderId], __METHOD__), __METHOD__);
            if ($row !== []) {
                return $this->privateMapper->order($row, false);
            }
        }

        $order = $this->fetchStandardOrderHistory($symbol, $orderId);
        if ($order instanceof OrderDto) {
            return $order;
        }

        return $this->fetchAlgoOrderDetail($symbol, ['algoClOrdId' => $orderId]);
    }

    private function fetchStandardOrderHistory(string $symbol, string $orderId): ?OrderDto
    {
        foreach (['/api/v5/trade/orders-history', '/api/v5/trade/orders-history-archive'] as $path) {
            $order = $this->fetchStandardOrderHistoryFromPath($path, $symbol, $orderId);
            if ($order instanceof OrderDto) {
                return $order;
            }
        }

        return null;
    }

    private function fetchStandardOrderHistoryFromPath(string $path, string $symbol, string $orderId): ?OrderDto
    {
        $baseQuery = [
            'instType' => 'SWAP',
            'instId' => $this->resolver()->instId($symbol),
            'limit' => 100,
        ];
        $after = null;

        do {
            $query = $after === null ? $baseQuery : $baseQuery + ['after' => $after];
            $rows = $this->dataRows($this->privateGet($path, $query, __METHOD__), __METHOD__);
            if ($rows === []) {
                return null;
            }

            foreach ($rows as $row) {
                if ((string) ($row['ordId'] ?? '') === $orderId || (string) ($row['clOrdId'] ?? '') === $orderId) {
                    return $this->privateMapper->order($row, false);
                }
            }

            $lastRow = $rows[\count($rows) - 1];
            $nextAfter = (string) ($lastRow['ordId'] ?? '');
            if ($nextAfter === '' || $nextAfter === $after) {
                return null;
            }

            $after = $nextAfter;
        } while (true);
    }

    /**
     * @param array{algoId?: string, algoClOrdId?: string} $identifier
     */
    private function fetchAlgoOrderDetail(string $symbol, array $identifier): ?OrderDto
    {
        $identifier = array_filter($identifier, static fn (string $value): bool => $value !== '');
        if ($identifier === []) {
            return null;
        }

        $baseQuery = $identifier + [
            'instId' => $this->resolver()->instId($symbol),
        ];

        $row = $this->firstOrderDetailRow($this->privateGet('/api/v5/trade/order-algo', $baseQuery, __METHOD__), __METHOD__);
        if ($row !== []) {
            return $this->privateMapper->order($row, true);
        }

        if (isset($identifier['algoId'])) {
            $row = $this->firstRow($this->privateGet(
                '/api/v5/trade/orders-algo-history',
                $baseQuery + ['ordType' => 'conditional'],
                __METHOD__,
            ), __METHOD__);
            if ($row !== []) {
                return $this->privateMapper->order($row, true);
            }
        }

        if (isset($identifier['algoClOrdId'])) {
            return $this->fetchAlgoHistoryByClientOrderId((string) $baseQuery['instId'], $identifier['algoClOrdId']);
        }

        return null;
    }

    private function fetchAlgoHistoryByClientOrderId(string $instId, string $clientOrderId): ?OrderDto
    {
        foreach (self::TERMINAL_ALGO_HISTORY_STATES as $state) {
            $after = null;
            do {
                $query = [
                    'instId' => $instId,
                    'ordType' => 'conditional',
                    'state' => $state,
                    'limit' => 100,
                ];
                if ($after !== null) {
                    $query['after'] = $after;
                }

                $rows = $this->dataRows($this->privateGet('/api/v5/trade/orders-algo-history', $query, __METHOD__), __METHOD__);
                if ($rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    if ((string) ($row['algoClOrdId'] ?? '') === $clientOrderId) {
                        return $this->privateMapper->order($row, true);
                    }
                }

                $lastRow = $rows[\count($rows) - 1];
                $nextAfter = (string) ($lastRow['algoId'] ?? '');
                if ($nextAfter === '' || $nextAfter === $after) {
                    break;
                }

                $after = $nextAfter;
            } while (true);
        }

        return null;
    }

    /**
     * @return OrderDto[]
     */
    public function getOpenOrders(?string $symbol = null): array
    {
        try {
            return $this->fetchOpenOrders($symbol, __METHOD__);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return OrderDto[]
     */
    public function getOpenOrdersOrFail(?string $symbol = null): array
    {
        return $this->fetchOpenOrders($symbol, __METHOD__);
    }

    /**
     * @return array<int, mixed>
     */
    public function getOrderHistory(string $symbol, int $limit = 100): array
    {
        $query = [
            'instType' => 'SWAP',
            'instId' => $this->resolver()->instId($symbol),
            'limit' => max(1, min($limit, 100)),
        ];

        return $this->dataRows($this->privateGet('/api/v5/trade/orders-history', $query, __METHOD__), __METHOD__);
    }

    public function cancelAllOrders(string $symbol): bool
    {
        throw $this->writeNotImplemented(__METHOD__);
    }

    public function getOrderBookTop(string $symbol): SymbolBidAskDto
    {
        if (!$this->client instanceof OkxRestClientInterface) {
            throw $this->readNotImplemented(__METHOD__);
        }

        try {
            $payload = $this->client->publicGet('/api/v5/market/books', [
                'instId' => $this->resolver()->instId($symbol),
                'sz' => 1,
            ]);
        } catch (\Throwable $exception) {
            throw new OkxProviderUnavailableException($this->reason($exception), __METHOD__, $exception);
        }

        $row = $this->firstRow($payload, __METHOD__);
        $bids = $this->mapper->orderBookLevels(\is_array($row['bids'] ?? null) ? $row['bids'] : []);
        $asks = $this->mapper->orderBookLevels(\is_array($row['asks'] ?? null) ? $row['asks'] : []);

        if ($bids === [] || $asks === []) {
            throw new OkxProviderUnavailableException('okx_public_order_book_empty', __METHOD__);
        }

        return new SymbolBidAskDto(
            symbol: strtoupper($symbol),
            bid: $bids[0]['price'],
            ask: $asks[0]['price'],
            timestamp: $this->mapper->time($row['ts'] ?? null),
        );
    }

    public function submitLeverage(string $symbol, int $leverage, string $openType = 'isolated'): bool
    {
        throw $this->writeNotImplemented(__METHOD__);
    }

    public function getProviderName(): string
    {
        return 'OKX';
    }

    private function readNotImplemented(string $operation): OkxProviderNotReadyException
    {
        return new OkxProviderNotReadyException('okx_order_read_not_implemented', $operation);
    }

    private function writeNotImplemented(string $operation): OkxProviderNotReadyException
    {
        return new OkxProviderNotReadyException('okx_order_write_not_implemented', $operation);
    }

    /**
     * @return OrderDto[]
     */
    private function fetchOpenOrders(?string $symbol, string $operation): array
    {
        $query = ['instType' => 'SWAP', 'limit' => self::PRIVATE_PAGE_SIZE];
        if ($symbol !== null) {
            $query['instId'] = $this->resolver()->instId($symbol);
        }

        $orders = [];
        $algoQuery = $query + ['ordType' => 'conditional'];
        $this->appendPaginatedOpenOrders(
            $orders,
            '/api/v5/trade/orders-pending',
            $query,
            'ordId',
            false,
            $operation,
        );
        $this->appendPaginatedOpenOrders(
            $orders,
            '/api/v5/trade/orders-algo-pending',
            $algoQuery,
            'algoId',
            true,
            $operation,
        );

        return $orders;
    }

    /**
     * @param list<OrderDto>          $orders
     * @param array<string, mixed>    $baseQuery
     */
    private function appendPaginatedOpenOrders(
        array &$orders,
        string $path,
        array $baseQuery,
        string $cursorField,
        bool $algo,
        string $operation,
    ): void {
        $after = null;
        $seenCursors = [];

        do {
            $query = $after === null ? $baseQuery : $baseQuery + ['after' => $after];
            $rows = $this->dataRows($this->privateGet($path, $query, $operation), $operation);
            if (\count($orders) + \count($rows) > self::PRIVATE_SNAPSHOT_MAX_ITEMS) {
                throw new OkxProviderUnavailableException('okx_private_pagination_limit_exceeded', $operation);
            }

            foreach ($rows as $row) {
                $orders[] = $this->privateMapper->order($row, $algo);
            }

            if (\count($rows) < self::PRIVATE_PAGE_SIZE) {
                return;
            }
            if (\count($orders) >= self::PRIVATE_SNAPSHOT_MAX_ITEMS) {
                throw new OkxProviderUnavailableException('okx_private_pagination_limit_exceeded', $operation);
            }

            $nextAfter = trim((string) ($rows[\count($rows) - 1][$cursorField] ?? ''));
            if ($nextAfter === '') {
                throw new OkxProviderUnavailableException('okx_private_pagination_cursor_invalid', $operation);
            }
            if (isset($seenCursors[$nextAfter])) {
                throw new OkxProviderUnavailableException('okx_private_pagination_cursor_repeated', $operation);
            }

            $seenCursors[$nextAfter] = true;
            $after = $nextAfter;
        } while (true);
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function privateGet(string $path, array $query, string $operation): array
    {
        if (!$this->client instanceof OkxRestClientInterface) {
            throw $this->readNotImplemented($operation);
        }

        try {
            return $this->client->privateGet($path, $query);
        } catch (\Throwable $exception) {
            throw new OkxProviderUnavailableException($this->reason($exception, private: true), $operation, $exception);
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function firstRow(array $payload, string $operation): array
    {
        return $this->dataRows($payload, $operation)[0] ?? [];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function firstOrderDetailRow(array $payload, string $operation): array
    {
        if ((string) ($payload['code'] ?? '') === '51603') {
            return [];
        }

        return $this->firstRow($payload, $operation);
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<array<string,mixed>>
     */
    private function dataRows(array $payload, string $operation): array
    {
        $code = (string) ($payload['code'] ?? '');
        if ($code !== '0') {
            $prefix = str_starts_with($operation, __CLASS__) && str_contains($operation, 'getOrderBookTop') ? 'public' : 'private';
            $reason = $code === '50011' ? sprintf('okx_%s_rate_limited', $prefix) : sprintf('okx_%s_api_error', $prefix);

            throw new OkxProviderUnavailableException($reason, $operation);
        }

        $data = $payload['data'] ?? [];
        if (!\is_array($data)) {
            return [];
        }

        return array_values(array_filter($data, \is_array(...)));
    }

    private function resolver(): OkxInstrumentResolver
    {
        return $this->instruments ?? new OkxInstrumentResolver();
    }

    private function reason(\Throwable $exception, bool $private = false): string
    {
        $prefix = $private ? 'private' : 'public';

        return str_contains($exception->getMessage(), '429')
            || str_contains(strtolower($exception->getMessage()), 'rate')
            ? sprintf('okx_%s_rate_limited', $prefix)
            : sprintf('okx_%s_network_error', $prefix);
    }
}
