<?php

declare(strict_types=1);

namespace App\MtfRunner\Application\Result;

use App\MtfValidator\Service\Helper\OrdersExtractor;

final class MtfRunResultEnricher
{
    /**
     * @param array<string, array<string, mixed>> $results
     * @return array{
     *     summary_by_tf: array<string, array<string>>,
     *     rejected_by: array<string>,
     *     last_validated: array<int, array{symbol: string, side: mixed, timeframe: string|null}>,
     *     orders_placed: array{count: array{total: int, submitted: int, simulated: int}, orders: array}
     * }
     */
    public function enrich(array $results): array
    {
        $summaryByTfRaw = $this->buildSummaryByTimeframe($results);
        $summaryByTf = [];
        foreach (['1m', '5m', '15m', '1h', '4h'] as $tf) {
            $summaryByTf[$tf] = $summaryByTfRaw[$tf] ?? [];
        }

        $rejectedBy = [];
        $lastValidated = [];

        foreach ($results as $symbol => $symbolResult) {
            if ($symbol === 'FINAL' || !\is_string($symbol) || $symbol === '' || !\is_array($symbolResult)) {
                continue;
            }

            $resultStatus = strtoupper((string) ($symbolResult['status'] ?? ''));
            if (!\in_array($resultStatus, ['SUCCESS', 'COMPLETED', 'READY'], true)) {
                $rejectedBy[] = $symbol;
            }

            if ($resultStatus === 'SUCCESS' || $resultStatus === 'COMPLETED') {
                $executionTf = $symbolResult['execution_tf'] ?? null;
                $signalSide = $symbolResult['signal_side'] ?? null;

                $lastValidated[] = [
                    'symbol' => $symbol,
                    'side' => $signalSide,
                    'timeframe' => $this->getPreviousTimeframe(\is_string($executionTf) ? $executionTf : null),
                ];
            }
        }

        sort($rejectedBy);
        usort($lastValidated, static function (array $a, array $b): int {
            return strcmp((string) ($a['symbol'] ?? ''), (string) ($b['symbol'] ?? ''));
        });

        return [
            'summary_by_tf' => $summaryByTf,
            'rejected_by' => $rejectedBy,
            'last_validated' => $lastValidated,
            'orders_placed' => [
                'count' => OrdersExtractor::countOrdersByStatus($results),
                'orders' => OrdersExtractor::extractPlacedOrders($results),
            ],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $results
     * @return array<string, array<string>>
     */
    private function buildSummaryByTimeframe(array $results): array
    {
        $groups = [];
        foreach ($results as $symbol => $info) {
            if (!\is_array($info)) {
                continue;
            }

            $lastTf = $info['blocking_tf'] ?? $info['failed_timeframe'] ?? ($info['execution_tf'] ?? null);
            $key = \is_string($lastTf) && $lastTf !== '' ? $lastTf : 'N/A';
            $groups[$key] ??= [];
            $groups[$key][] = (string) $symbol;
        }

        $order = ['4h' => 5, '1h' => 4, '15m' => 3, '5m' => 2, '1m' => 1, 'N/A' => 0];
        uksort($groups, static function (string $a, string $b) use ($order): int {
            return ($order[$b] ?? 0) - ($order[$a] ?? 0);
        });

        return $groups;
    }

    private function getPreviousTimeframe(?string $timeframe): ?string
    {
        if ($timeframe === null || $timeframe === '') {
            return null;
        }

        return match (strtolower(trim($timeframe))) {
            '15m' => '1h',
            '5m' => '15m',
            '1m' => 'READY',
            '1h' => '4h',
            '4h' => null,
            default => null,
        };
    }
}
