<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderType;
use App\Contract\Provider\Dto\OrderDto;
use App\Contract\Provider\Dto\SymbolBidAskDto;
use App\Contract\Provider\OrderProviderInterface;
use App\Exchange\Hyperliquid\HyperliquidAssetResolver;
use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Hyperliquid\HyperliquidRestClientInterface;

final class HyperliquidExecutionGateway implements OrderProviderInterface
{
    private HyperliquidPrivateReadMapper $mapper;

    public function __construct(
        private readonly ?HyperliquidRestClientInterface $client = null,
        private readonly ?HyperliquidAssetResolver $assets = null,
        private readonly ?HyperliquidConfig $config = null,
    ) {
        $this->mapper = new HyperliquidPrivateReadMapper();
    }

    /**
     * @param array<string,mixed> $options
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
        throw $this->notReady(__METHOD__);
    }

    public function cancelOrder(string $symbol, string $orderId): bool
    {
        throw $this->notReady(__METHOD__);
    }

    public function getOrder(string $symbol, string $orderId): ?OrderDto
    {
        foreach ($this->getOpenOrdersOrFail($symbol) as $order) {
            if ($order->orderId === $orderId || (string) ($order->metadata['client_order_id'] ?? '') === $orderId) {
                return $order;
            }
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
     * @return OrderDto[]
     */
    public function getOrderHistory(string $symbol, int $limit = 100): array
    {
        throw $this->notReady(__METHOD__);
    }

    public function cancelAllOrders(string $symbol): bool
    {
        throw $this->notReady(__METHOD__);
    }

    public function getOrderBookTop(string $symbol): SymbolBidAskDto
    {
        throw $this->notReady(__METHOD__);
    }

    public function submitLeverage(string $symbol, int $leverage, string $openType = 'isolated'): bool
    {
        throw $this->notReady(__METHOD__);
    }

    public function healthCheck(): bool
    {
        try {
            $this->fetchOpenOrders(null, __METHOD__);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function getProviderName(): string
    {
        return 'Hyperliquid';
    }

    private function notReady(string $operation): HyperliquidProviderNotReadyException
    {
        return new HyperliquidProviderNotReadyException('hyperliquid_execution_not_ready', $operation);
    }

    /**
     * @return OrderDto[]
     */
    private function fetchOpenOrders(?string $symbol, string $operation): array
    {
        $target = $symbol !== null ? $this->coin($symbol) : null;
        $orders = [];
        foreach ($this->infoRows([
            'type' => 'frontendOpenOrders',
            'user' => $this->accountAddress($operation),
        ], $operation) as $row) {
            $coin = strtoupper((string) ($row['coin'] ?? ''));
            if ($target !== null && $coin !== $target) {
                continue;
            }

            $orders[] = $this->mapper->order($row);
        }

        return $orders;
    }

    /**
     * @param array<string,mixed> $request
     * @return list<array<string,mixed>>
     */
    private function infoRows(array $request, string $operation): array
    {
        if (!$this->client instanceof HyperliquidRestClientInterface) {
            throw new HyperliquidProviderNotReadyException('hyperliquid_order_read_not_ready', $operation);
        }

        try {
            return array_values(array_filter($this->client->info($request), \is_array(...)));
        } catch (\Throwable $exception) {
            throw new HyperliquidProviderUnavailableException($this->reason($exception), $operation, $exception);
        }
    }

    private function accountAddress(string $operation): string
    {
        $config = $this->config ?? HyperliquidConfig::fromEnv();
        if ($config->configuredEnvironment() !== 'testnet') {
            throw new HyperliquidProviderNotReadyException('hyperliquid_account_environment_not_testnet', $operation);
        }
        if ($config->normalizedNetwork() !== 'testnet') {
            throw new HyperliquidProviderNotReadyException('hyperliquid_account_network_not_testnet', $operation);
        }

        $accountAddress = $config->signingAccountAddress();
        $signerAddress = $config->signerAddress();
        if ($accountAddress === '') {
            $reason = $signerAddress !== ''
                ? 'hyperliquid_account_address_missing_for_signer'
                : 'hyperliquid_account_address_missing';

            throw new HyperliquidProviderNotReadyException($reason, $operation);
        }
        if ($signerAddress !== '' && $accountAddress === $signerAddress) {
            throw new HyperliquidProviderNotReadyException('hyperliquid_account_address_matches_agent', $operation);
        }

        return $accountAddress;
    }

    private function coin(string $symbol): string
    {
        return $this->assets instanceof HyperliquidAssetResolver
            ? $this->assets->coin($symbol)
            : $this->fallbackCoin($symbol);
    }

    private function fallbackCoin(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        foreach (['-PERP', 'PERP', '/USDC', '-USDC', 'USDC', '/USDT', '-USDT', 'USDT'] as $suffix) {
            if (str_ends_with($symbol, $suffix)) {
                return substr($symbol, 0, -strlen($suffix));
            }
        }

        return $symbol;
    }

    private function reason(\Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, '429') || str_contains($message, 'rate')) {
            return 'hyperliquid_private_rate_limited';
        }
        if (str_contains($message, 'network')) {
            return 'hyperliquid_private_network_error';
        }

        return 'hyperliquid_private_api_error';
    }
}
