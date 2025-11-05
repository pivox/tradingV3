<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Application;

use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\Provider\Dto\OrderDto;
use App\Contract\Provider\Dto\PositionDto;
use App\Contract\Provider\MainProviderInterface;
use App\Repository\MtfSwitchRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PositionsSnapshotService
{
    public function __construct(
        private readonly MainProviderInterface $providers,
        private readonly MtfSwitchRepository $switchRepository,
        #[Autowire(service: 'monolog.logger.positions_flow')] private readonly LoggerInterface $logger,
    ) {
    }

    public function buildSnapshot(MtfRunDto $runDto): PositionsSnapshot
    {
        $symbols = array_map('strtoupper', $runDto->symbols);

        $positions = $this->collectPositions($symbols);
        $orders = $this->collectOrders($symbols);

        $adjustmentRequests = [];
        foreach ($symbols as $symbol) {
            $position = $positions[$symbol] ?? null;
            $ordersForSymbol = $orders[$symbol] ?? [];
            $adjustmentRequests[$symbol] = $this->shouldRequestAdjustment($position, $ordersForSymbol);
        }

        return new PositionsSnapshot($positions, $orders, $adjustmentRequests);
    }

    public function filterSymbols(MtfRunDto $runDto, PositionsSnapshot $snapshot): array
    {
        if ($runDto->symbols === []) {
            return [];
        }

        $requested = array_map('strtoupper', $runDto->symbols);
        $known = $snapshot->getSymbols();

        if ($known === []) {
            return $requested;
        }

        $filtered = array_values(array_intersect($requested, $known));
        return $filtered !== [] ? $filtered : $requested;
    }

    public function applySymbolOutcome(string $symbol, array $context, array $result): void
    {
        $symbol = strtoupper($symbol);
        $this->logger->info('[PositionsSnapshot] Applying outcome', [
            'symbol' => $symbol,
            'context' => $this->normalizeContext($context),
            'result_status' => $result['status'] ?? 'UNKNOWN',
            'result_execution_tf' => $result['execution_tf'] ?? null,
        ]);

        try {
            $this->switchRepository->turnOnSymbol($symbol);
        } catch (\Throwable $exception) {
            $this->logger->warning('[PositionsSnapshot] Failed to release symbol switch', [
                'symbol' => $symbol,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function refreshAfterRun(): void
    {
        try {
            $positions = $this->providers->getAccountProvider()->getOpenPositions();
            $orders = $this->providers->getOrderProvider()->getOpenOrders();

            $this->logger->info('[PositionsSnapshot] Refresh after run completed', [
                'positions_count' => is_countable($positions) ? count($positions) : 0,
                'orders_count' => is_countable($orders) ? count($orders) : 0,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->warning('[PositionsSnapshot] Unable to refresh CP/CO after run', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param list<string> $symbols
     * @return array<string, PositionDto>
     */
    private function collectPositions(array $symbols): array
    {
        try {
            $positions = $this->providers->getAccountProvider()->getOpenPositions();
        } catch (\Throwable $exception) {
            $this->logger->warning('[PositionsSnapshot] Failed to collect positions', [
                'error' => $exception->getMessage(),
            ]);
            $positions = [];
        }

        $indexed = [];
        foreach ($positions as $position) {
            if (!$position instanceof PositionDto) {
                continue;
            }
            $symbol = strtoupper($position->symbol);
            if ($symbols !== [] && !in_array($symbol, $symbols, true)) {
                continue;
            }
            $indexed[$symbol] = $position;
        }

        return $indexed;
    }

    /**
     * @param list<string> $symbols
     * @return array<string, list<OrderDto>>
     */
    private function collectOrders(array $symbols): array
    {
        try {
            $orders = $this->providers->getOrderProvider()->getOpenOrders();
        } catch (\Throwable $exception) {
            $this->logger->warning('[PositionsSnapshot] Failed to collect open orders', [
                'error' => $exception->getMessage(),
            ]);
            $orders = [];
        }

        $indexed = [];
        foreach ($orders as $order) {
            if (!$order instanceof OrderDto) {
                continue;
            }
            $symbol = strtoupper($order->symbol);
            if ($symbols !== [] && !in_array($symbol, $symbols, true)) {
                continue;
            }
            $indexed[$symbol] ??= [];
            $indexed[$symbol][] = $order;
        }

        return $indexed;
    }

    /**
     * @param list<OrderDto> $orders
     */
    private function shouldRequestAdjustment(?PositionDto $position, array $orders): bool
    {
        $preSubmit = $this->extractPreSubmitMetadata($orders);
        $tpOrder = $this->findOrderByKind($orders, ['tp', 'tp1', 'tp2']);
        $slOrder = $this->findOrderByKind($orders, ['sl', 'stop', 'stop_loss']);

        $currentTp = $tpOrder?->price?->toFloat();
        $currentSl = $slOrder?->stopPrice?->toFloat() ?? $slOrder?->price?->toFloat();

        $plannedTp = $preSubmit['tp'] ?? null;
        $plannedSl = $preSubmit['sl'] ?? null;

        if ($position instanceof PositionDto && $plannedTp === null && $plannedSl === null) {
            return false;
        }

        return $this->hasAdjustmentGap($plannedTp, $currentTp) || $this->hasAdjustmentGap($plannedSl, $currentSl);
    }

    /**
     * @param list<OrderDto> $orders
     * @return array<string, float|null>
     */
    private function extractPreSubmitMetadata(array $orders): array
    {
        foreach ($orders as $order) {
            if (!$order instanceof OrderDto) {
                continue;
            }
            $metadata = $order->metadata['pre_submit'] ?? null;
            if (is_array($metadata)) {
                return [
                    'tp' => isset($metadata['tp']) ? (float) $metadata['tp'] : null,
                    'sl' => isset($metadata['sl']) ? (float) $metadata['sl'] : null,
                ];
            }
        }

        return [];
    }

    /**
     * @param list<OrderDto> $orders
     * @param list<string> $kinds
     */
    private function findOrderByKind(array $orders, array $kinds): ?OrderDto
    {
        $normalizedKinds = array_map(static fn(string $kind): string => strtolower($kind), $kinds);
        foreach ($orders as $order) {
            if (!$order instanceof OrderDto) {
                continue;
            }
            $kind = strtolower((string)($order->metadata['kind'] ?? ''));
            if ($kind !== '' && in_array($kind, $normalizedKinds, true)) {
                return $order;
            }
            $intentKind = strtolower((string)($order->metadata['intent']['kind'] ?? ''));
            if ($intentKind !== '' && in_array($intentKind, $normalizedKinds, true)) {
                return $order;
            }
        }

        return null;
    }

    private function hasAdjustmentGap(?float $planned, ?float $actual): bool
    {
        if ($planned === null) {
            return false;
        }

        if ($actual === null) {
            return true;
        }

        return abs($actual - $planned) > 1e-9;
    }

    private function normalizeContext(array $context): array
    {
        $normalized = [];
        if (isset($context['position']) && $context['position'] instanceof PositionDto) {
            $normalized['position'] = $context['position']->toArray();
        }
        if (isset($context['orders']) && is_iterable($context['orders'])) {
            $normalized['orders'] = [];
            foreach ($context['orders'] as $order) {
                if ($order instanceof OrderDto) {
                    $normalized['orders'][] = $order->toArray();
                }
            }
        }
        if (isset($context['adjustment_requested'])) {
            $normalized['adjustment_requested'] = (bool) $context['adjustment_requested'];
        }

        return $normalized;
    }
}
