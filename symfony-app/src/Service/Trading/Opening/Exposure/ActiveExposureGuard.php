<?php

declare(strict_types=1);

namespace App\Service\Trading\Opening\Exposure;

use App\Service\Bitmart\Private\OrdersService;
use App\Service\Bitmart\Private\PositionsService as BitmartPositionsService;
use App\Service\Trading\Opening\Exception\ActiveExposureException;
use Psr\Log\LoggerInterface;
use Throwable;

final class ActiveExposureGuard
{
    public function __construct(
        private readonly BitmartPositionsService $positionsService,
        private readonly OrdersService $ordersService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function assertNone(string $symbol, string $side): void
    {
        $position = $this->findActivePosition($symbol);
        if ($position !== null) {
            $this->logger->warning('[Opening] Active position detected', [
                'symbol' => $symbol,
                'side' => $side,
                'position' => $position,
            ]);
            throw new ActiveExposureException(sprintf('Une position est déjà ouverte sur %s', $symbol));
        }

        $order = $this->findOpenOrder($symbol);
        if ($order !== null) {
            $this->logger->warning('[Opening] Pending order detected', [
                'symbol' => $symbol,
                'side' => $side,
                'order' => $order,
            ]);
            throw new ActiveExposureException(sprintf('Un ordre est déjà en attente sur %s', $symbol));
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findActivePosition(string $symbol): ?array
    {
        try {
            $response = $this->positionsService->list(['symbol' => $symbol]);
        } catch (Throwable $e) {
            $this->logger->warning('[Opening] Position lookup failed, assume none', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $rows = $this->normalizeList($response['data'] ?? []);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sym = strtoupper((string)($row['symbol'] ?? $row['contract_symbol'] ?? ''));
            if ($sym !== strtoupper($symbol)) {
                continue;
            }
            if ($this->extractSize($row) > 0.0) {
                return $row;
            }
        }

        return null;
    }

    private function findOpenOrder(string $symbol): ?array
    {
        try {
            $response = $this->ordersService->open(['symbol' => $symbol]);
        } catch (Throwable $e) {
            $this->logger->warning('[Opening] Orders lookup failed, assume none', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $orders = $this->normalizeList($response['orders'] ?? []);
        if ($orders !== []) {
            return $orders[0];
        }

        $planOrders = $this->normalizeList($response['plan_orders'] ?? []);
        if ($planOrders !== []) {
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
        if (!is_array($payload)) {
            return [];
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            return $this->normalizeList($payload['data']);
        }

        if (isset($payload['positions']) && is_array($payload['positions'])) {
            return $this->normalizeList($payload['positions']);
        }

        if (isset($payload['orders']) && is_array($payload['orders'])) {
            return $this->normalizeList($payload['orders']);
        }

        if (isset($payload['order_list']) && is_array($payload['order_list'])) {
            return $this->normalizeList($payload['order_list']);
        }

        if (isset($payload['list']) && is_array($payload['list'])) {
            return $this->normalizeList($payload['list']);
        }

        if ($this->isList($payload)) {
            return array_values(array_filter($payload, static fn($row) => is_array($row)));
        }

        return array_values(array_filter(
            $payload,
            static fn($row) => is_array($row) && (
                isset($row['symbol']) || isset($row['order_id']) || isset($row['client_order_id'])
            )
        ));
    }

    private function extractSize(array $row): float
    {
        foreach (['size', 'hold_volume', 'volume'] as $key) {
            if (!isset($row[$key])) {
                continue;
            }
            $value = (float)$row[$key];
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

        $expected = 0;
        foreach ($array as $key => $_) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }

        return true;
    }
}
