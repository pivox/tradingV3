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
    private OkxPublicReadMapper $mapper;

    public function __construct(
        private readonly ?OkxRestClientInterface $client = null,
        private readonly ?OkxInstrumentResolver $instruments = null,
    ) {
        $this->mapper = new OkxPublicReadMapper($this->resolver());
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
        throw $this->readNotImplemented(__METHOD__);
    }

    /**
     * @return OrderDto[]
     */
    public function getOpenOrders(?string $symbol = null): array
    {
        throw $this->readNotImplemented(__METHOD__);
    }

    /**
     * @return OrderDto[]
     */
    public function getOpenOrdersOrFail(?string $symbol = null): array
    {
        throw $this->readNotImplemented(__METHOD__);
    }

    /**
     * @return array<int, mixed>
     */
    public function getOrderHistory(string $symbol, int $limit = 100): array
    {
        throw $this->readNotImplemented(__METHOD__);
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
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function firstRow(array $payload, string $operation): array
    {
        $code = (string) ($payload['code'] ?? '');
        if ($code !== '0') {
            $reason = $code === '50011' ? 'okx_public_rate_limited' : 'okx_public_api_error';

            throw new OkxProviderUnavailableException($reason, $operation);
        }

        $data = $payload['data'] ?? [];
        if (!\is_array($data)) {
            return [];
        }

        $row = array_values($data)[0] ?? [];

        return \is_array($row) ? $row : [];
    }

    private function resolver(): OkxInstrumentResolver
    {
        return $this->instruments ?? new OkxInstrumentResolver();
    }

    private function reason(\Throwable $exception): string
    {
        return str_contains($exception->getMessage(), '429')
            || str_contains(strtolower($exception->getMessage()), 'rate')
            ? 'okx_public_rate_limited'
            : 'okx_public_network_error';
    }
}
