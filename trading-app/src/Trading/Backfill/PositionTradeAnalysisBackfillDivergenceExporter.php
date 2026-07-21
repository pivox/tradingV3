<?php

declare(strict_types=1);

namespace App\Trading\Backfill;

final class PositionTradeAnalysisBackfillDivergenceExporter
{
    /**
     * @param array<string,mixed> $report
     */
    public function exportJson(array $report, string $path): void
    {
        $this->ensureDirectory($path);
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents($path, $json . "\n");
    }

    /**
     * @param array<string,mixed> $report
     */
    public function exportCsv(array $report, string $path): void
    {
        $this->ensureDirectory($path);
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Unable to open CSV export path "%s".', $path));
        }

        $columns = [
            'entry_event_id',
            'classification',
            'symbol',
            'exchange',
            'market_data_venue',
            'market_type',
            'profile',
            'entry_time',
            'v1_present',
            'v2_present',
            'close_match_status',
            'close_matched_by',
            'cost_completeness',
            'v1_pnl_usdt',
            'v2_pnl_usdt',
            'pnl_delta_usdt',
            'holding_time_delta_sec',
            'mfe_delta_pct',
            'mae_delta_pct',
        ];
        fputcsv($handle, $columns, ',', '"', '\\');

        /** @var list<array<string,mixed>> $rows */
        $rows = is_array($report['rows'] ?? null) ? $report['rows'] : [];
        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                static fn (string $column): mixed => $row[$column] ?? null,
                $columns,
            ), ',', '"', '\\');
        }

        fclose($handle);
    }

    private function ensureDirectory(string $path): void
    {
        $directory = dirname($path);
        if ($directory === '' || $directory === '.') {
            return;
        }
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create export directory "%s".', $directory));
        }
    }
}
