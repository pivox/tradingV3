<?php

declare(strict_types=1);

namespace App\Application\Runner;

use App\Contract\Provider\MainProviderInterface;
use App\MtfValidator\Repository\MtfSwitchRepository;
use App\Provider\Context\ExchangeContext;
use Psr\Log\LoggerInterface;

final class OpenActivityFilter
{
    public function __construct(
        private readonly MainProviderInterface $mainProvider,
        private readonly MtfSwitchRepository $mtfSwitchRepository,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $mtfLogger,
    ) {
    }

    /**
     * @param array<string> $symbols Liste des symboles à filtrer
     * @param array<string> $excludedSymbols Référence pour retourner les symboles exclus
     * @param array<int, object|array<string, mixed>>|null $openPositions Positions ouvertes préchargées
     * @param array<int, object|array<string, mixed>>|null $openOrders Ordres ouverts préchargés
     * @return array<string> Liste des symboles à traiter
     */
    public function filter(
        array $symbols,
        string $runId,
        ExchangeContext $context,
        array &$excludedSymbols = [],
        ?array $openPositions = null,
        ?array $openOrders = null,
    ): array {
        $excludedSymbols = [];
        $provider = $this->mainProvider->forContext($context);
        $accountProvider = $provider->getAccountProvider();
        $orderProvider = $provider->getOrderProvider();

        if (empty($symbols)) {
            return $symbols;
        }

        $symbolsToProcess = [];

        $openPositionSymbols = [];
        try {
            if ($openPositions === null) {
                $openPositions = $accountProvider->getOpenPositions();
                $this->mtfLogger->info('[MTF Runner] Fetched open positions', [
                    'run_id' => $runId,
                    'count' => count($openPositions),
                ]);
            } else {
                $this->mtfLogger->info('[MTF Runner] Reusing prefetched open positions', [
                    'run_id' => $runId,
                    'count' => count($openPositions),
                ]);
            }

            foreach ($openPositions as $position) {
                $positionSymbol = strtoupper($this->extractSymbol($position));
                if ($positionSymbol !== '' && !in_array($positionSymbol, $openPositionSymbols, true)) {
                    $openPositionSymbols[] = $positionSymbol;
                }
            }
        } catch (\Throwable $e) {
            $this->mtfLogger->warning('[MTF Runner] Failed to fetch open positions from exchange', [
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ]);
        }

        $openOrderSymbols = [];
        try {
            if ($openOrders === null) {
                $openOrders = $orderProvider->getOpenOrders();
                $this->mtfLogger->info('[MTF Runner] Fetched open orders', [
                    'run_id' => $runId,
                    'count' => count($openOrders),
                ]);
            } else {
                $this->mtfLogger->info('[MTF Runner] Reusing prefetched open orders', [
                    'run_id' => $runId,
                    'count' => count($openOrders),
                ]);
            }

            foreach ($openOrders as $order) {
                $orderSymbol = strtoupper($this->extractSymbol($order));
                if ($orderSymbol !== '' && !in_array($orderSymbol, $openOrderSymbols, true)) {
                    $openOrderSymbols[] = $orderSymbol;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[MTF Runner] Failed to fetch open orders from exchange', [
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ]);
        }

        $symbolsWithActivity = array_unique(array_merge($openPositionSymbols, $openOrderSymbols));

        try {
            $reactivatedCount = $this->mtfSwitchRepository->reactivateSwitchesForInactiveSymbols($symbolsWithActivity);
            if ($reactivatedCount > 0) {
                $this->mtfLogger->info('[MTF Runner] Reactivated switches for inactive symbols', [
                    'run_id' => $runId,
                    'reactivated_count' => $reactivatedCount,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[MTF Runner] Failed to reactivate switches for inactive symbols', [
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ]);
        }

        foreach ($symbols as $symbol) {
            $symbolUpper = strtoupper($symbol);

            if (in_array($symbolUpper, $symbolsWithActivity, true)) {
                $excludedSymbols[] = $symbolUpper;
            } else {
                $symbolsToProcess[] = $symbol;
            }
        }

        if (!empty($excludedSymbols)) {
            $this->logger->info('[MTF Runner] Filtered symbols with open orders/positions', [
                'run_id' => $runId,
                'excluded_count' => count($excludedSymbols),
                'excluded_symbols' => array_slice($excludedSymbols, 0, 10),
                'remaining_count' => count($symbolsToProcess),
            ]);
        }

        return $symbolsToProcess;
    }

    /**
     * @param object|array<string, mixed> $payload
     */
    private function extractSymbol(object|array $payload): string
    {
        if (is_array($payload)) {
            return is_string($payload['symbol'] ?? null) ? $payload['symbol'] : '';
        }

        return is_string($payload->symbol ?? null) ? $payload->symbol : '';
    }
}
