<?php

declare(strict_types=1);

namespace App\Application\Runner;

use App\MtfRunner\Application\Result\MtfRunResultEnricher;

final class RunResultAssembler
{
    public function __construct(
        private readonly MtfRunResultEnricher $resultEnricher,
    ) {
    }

    /**
     * @param array<string, array<string, mixed>> $results
     * @return array{
     *     summary_by_tf: array<string, array<string>>,
     *     rejected_by: array<string>,
     *     last_validated: array<int, array{symbol: string, side: mixed, timeframe: string|null}>,
     *     orders_placed: array{count: array<string, int>, orders: array<int|string, mixed>}
     * }
     */
    public function enrich(array $results): array
    {
        return $this->resultEnricher->enrich($results);
    }

    /**
     * @param array<string, mixed> $runnerResult
     * @param array<string, mixed> $results
     * @param array{
     *     summary_by_tf: array<string, array<string>>,
     *     rejected_by: array<string>,
     *     last_validated: array<int, array{symbol: string, side: mixed, timeframe: string|null}>,
     *     orders_placed: array{count: array<string, int>, orders: array<int|string, mixed>}
     * } $enriched
     * @param array<string, mixed> $performanceReport
     * @return array{
     *     summary: mixed,
     *     results: array<string, mixed>,
     *     errors: mixed,
     *     summary_by_tf: array<string, array<string>>,
     *     rejected_by: array<string>,
     *     last_validated: array<int, array{symbol: string, side: mixed, timeframe: string|null}>,
     *     orders_placed: array{count: array<string, int>, orders: array<int|string, mixed>},
     *     performance: array<string, mixed>
     * }
     */
    public function assemble(array $runnerResult, array $results, array $enriched, array $performanceReport): array
    {
        return [
            'summary' => $runnerResult['summary'] ?? [],
            'results' => $results,
            'errors' => $runnerResult['errors'] ?? [],
            'summary_by_tf' => $enriched['summary_by_tf'],
            'rejected_by' => $enriched['rejected_by'],
            'last_validated' => $enriched['last_validated'],
            'orders_placed' => $enriched['orders_placed'],
            'performance' => $performanceReport,
        ];
    }
}
