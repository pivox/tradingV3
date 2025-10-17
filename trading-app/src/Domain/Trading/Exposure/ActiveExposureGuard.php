<?php

declare(strict_types=1);

namespace App\Domain\Trading\Exposure;

use App\Domain\Common\Enum\SignalSide;
use App\Domain\Ports\Out\TradingProviderPort;
use App\Domain\Trading\Exposure\Exception\ActiveExposureException;
use App\Config\TradingParameters;
use Psr\Log\LoggerInterface;
use Throwable;

class ActiveExposureGuard
{
    public function __construct(
        private readonly TradingProviderPort $tradingProvider,
        private readonly ContractCooldownService $cooldownService,
        private readonly LoggerInterface $logger,
        private readonly TradingParameters $config,
    ) {
    }

    public function assertEligible(string $symbol, SignalSide $side): void
    {
        $canonicalSymbol = strtoupper($symbol);

        $cooldown = $this->cooldownService->getActiveCooldown($canonicalSymbol);
        if ($cooldown !== null) {
            throw ActiveExposureException::forCooldown($canonicalSymbol, $cooldown->getActiveUntil()->format(DATE_ATOM));
        }

        if ($this->findActivePosition($canonicalSymbol) !== null) {
            throw ActiveExposureException::forPosition($canonicalSymbol);
        }

        if ($this->findPendingOrder($canonicalSymbol) !== null) {
            throw ActiveExposureException::forOrder($canonicalSymbol);
        }

        // Limite globale de positions ouvertes
        $maxOpen = (int)($this->config->getTradingConf('risk')['max_concurrent_positions'] ?? 0);
        if ($maxOpen > 0) {
            $globalOpen = $this->countAllOpenPositions();
            if ($globalOpen >= $maxOpen) {
                throw ActiveExposureException::forGlobalLimit($maxOpen);
            }
        }

        $this->logger->debug('[ExposureGuard] Symbol eligible for opening', [
            'symbol' => $canonicalSymbol,
            'side' => $side->value,
        ]);
    }

    private function countAllOpenPositions(): int
    {
        try {
            $response = $this->tradingProvider->getPositions(null);
        } catch (Throwable $exception) {
            $this->logger->warning('[ExposureGuard] Failed to fetch all positions', [
                'error' => $exception->getMessage(),
            ]);
            return 0;
        }

        $rows = $this->normalizeList($response['data'] ?? $response ?? []);
        $count = 0;
        foreach ($rows as $row) {
            if (!\is_array($row)) { continue; }
            if ($this->extractSize($row) > 0.0) { $count++; }
        }
        return $count;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findActivePosition(string $symbol): ?array
    {
        try {
            $response = $this->tradingProvider->getPositions($symbol);
        } catch (Throwable $exception) {
            $this->logger->warning('[ExposureGuard] Failed to fetch positions', [
                'symbol' => $symbol,
                'error' => $exception->getMessage(),
            ]);
            return null;
        }

        $rows = $this->normalizeList($response['data'] ?? $response ?? []);
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $sym = strtoupper((string)($row['symbol'] ?? $row['contract_symbol'] ?? ''));
            if ($sym !== $symbol) {
                continue;
            }

            if ($this->extractSize($row) > 0.0) {
                $this->logger->debug('[ExposureGuard] Active position detected', [
                    'symbol' => $symbol,
                    'position' => $row,
                ]);
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findPendingOrder(string $symbol): ?array
    {
        try {
            $response = $this->tradingProvider->getOpenOrders($symbol);
        } catch (Throwable $exception) {
            $this->logger->warning('[ExposureGuard] Failed to fetch open orders', [
                'symbol' => $symbol,
                'error' => $exception->getMessage(),
            ]);
            return null;
        }

        $orders = $this->normalizeList($response['orders'] ?? []);
        if ($orders !== []) {
            $this->logger->debug('[ExposureGuard] Pending order detected', [
                'symbol' => $symbol,
                'order' => $orders[0],
            ]);
            return $orders[0];
        }

        $planOrders = $this->normalizeList($response['plan_orders'] ?? []);
        if ($planOrders !== []) {
            $this->logger->debug('[ExposureGuard] Pending plan order detected', [
                'symbol' => $symbol,
                'order' => $planOrders[0],
            ]);
            return $planOrders[0];
        }

        return null;
    }

    /**
     * @param mixed $payload
     * @return array<int,array<string,mixed>>
     */
    private function normalizeList(mixed $payload): array
    {
        if (!\is_array($payload)) {
            return [];
        }

        if (isset($payload['data'])) {
            return $this->normalizeList($payload['data']);
        }

        if ($this->isList($payload)) {
            return array_values(array_filter($payload, static fn($row) => \is_array($row)));
        }

        return array_values(array_filter(
            $payload,
            static fn($row) => \is_array($row) && (
                isset($row['symbol']) || isset($row['order_id']) || isset($row['client_order_id'])
            )
        ));
    }

    private function extractSize(array $row): float
    {
        foreach (['size', 'hold_volume', 'volume', 'position'] as $key) {
            if (!isset($row[$key])) {
                continue;
            }
            $value = (float) $row[$key];
            if ($value > 0.0) {
                return $value;
            }
        }

        return 0.0;
    }

    private function isList(array $array): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($array);
        }

        $expectedKey = 0;
        foreach ($array as $key => $_) {
            if ($key !== $expectedKey) {
                return false;
            }
            $expectedKey++;
        }

        return true;
    }
}
