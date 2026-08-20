<?php

declare(strict_types=1);

namespace App\Tests\Trading\Backfill;

use App\Trading\Backfill\PositionTradeAnalysisBackfillDivergenceExporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PositionTradeAnalysisBackfillDivergenceExporter::class)]
final class PositionTradeAnalysisBackfillDivergenceExporterTest extends TestCase
{
    public function testJsonAndCsvExposeVenueAfterExchangeWithoutInferringNullProvenance(): void
    {
        $jsonPath = tempnam(sys_get_temp_dir(), 'venue-provenance-json-');
        $csvPath = tempnam(sys_get_temp_dir(), 'venue-provenance-csv-');
        self::assertIsString($jsonPath);
        self::assertIsString($csvPath);

        $report = [
            'rows' => [
                [
                    'entry_event_id' => 1,
                    'classification' => 'certified',
                    'symbol' => 'BTCUSDT',
                    'exchange' => 'fake',
                    'market_data_venue' => 'hyperliquid',
                    'market_type' => 'perpetual',
                    'profile' => 'scalper',
                    'mode_id' => 'scalping',
                    'mode_version' => '1.1.0',
                    'setup_id' => 'scalping.pullback.long',
                    'setup_version' => '1.1.0',
                    'side' => 'LONG',
                    'config_hash' => 'sha256:canonical',
                    'lineage_classification' => 'canonical',
                    'holding_time_source' => 'fill_cost_ledger_v1',
                    'mfe_mae_window_source' => 'fill_cost_ledger_v1',
                    'mfe_mae_entry_price_source' => 'fill_cost_ledger_v1',
                    'mfe_mae_data_quality' => 'complete',
                ],
                [
                    'entry_event_id' => 2,
                    'classification' => 'v1_only',
                    'symbol' => 'ETHUSDT',
                    'exchange' => 'okx',
                    'market_data_venue' => null,
                    'market_type' => 'perpetual',
                    'profile' => 'legacy',
                ],
                [
                    'entry_event_id' => 3,
                    'classification' => 'v1_only',
                    'symbol' => 'SOLUSDT',
                    'exchange' => 'hyperliquid',
                    'market_type' => 'perpetual',
                    'profile' => 'legacy',
                ],
            ],
        ];

        try {
            $exporter = new PositionTradeAnalysisBackfillDivergenceExporter();
            $exporter->exportJson($report, $jsonPath);
            $exporter->exportCsv($report, $csvPath);

            $decoded = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('hyperliquid', $decoded['rows'][0]['market_data_venue']);
            self::assertNull($decoded['rows'][1]['market_data_venue']);
            self::assertArrayNotHasKey('market_data_venue', $decoded['rows'][2]);

            $lines = file($csvPath, FILE_IGNORE_NEW_LINES);
            self::assertIsArray($lines);
            self::assertCount(4, $lines);

            $header = str_getcsv($lines[0], ',', '"', '\\');
            self::assertSame([
                'entry_event_id',
                'classification',
                'symbol',
                'exchange',
                'market_data_venue',
                'market_type',
                'profile',
                'mode_id',
                'mode_version',
                'setup_id',
                'setup_version',
                'side',
                'config_hash',
                'lineage_classification',
                'entry_time',
                'v1_present',
                'v2_present',
                'close_match_status',
                'close_matched_by',
                'cost_completeness',
                'holding_time_source',
                'mfe_mae_window_source',
                'mfe_mae_entry_price_source',
                'mfe_mae_data_quality',
                'v1_pnl_usdt',
                'v2_pnl_usdt',
                'pnl_delta_usdt',
                'holding_time_delta_sec',
                'mfe_delta_pct',
                'mae_delta_pct',
            ], $header);

            $venueRow = str_getcsv($lines[1], ',', '"', '\\');
            $legacyNullRow = str_getcsv($lines[2], ',', '"', '\\');
            $legacyMissingRow = str_getcsv($lines[3], ',', '"', '\\');
            self::assertSame('hyperliquid', $venueRow[4]);
            self::assertSame('', $legacyNullRow[4]);
            self::assertSame('', $legacyMissingRow[4]);
            self::assertFalse(in_array($legacyNullRow[4], ['okx', 'hyperliquid', 'fake', 'unknown', '0'], true));
            self::assertFalse(in_array($legacyMissingRow[4], ['okx', 'hyperliquid', 'fake', 'unknown', '0'], true));
        } finally {
            @unlink($jsonPath);
            @unlink($csvPath);
        }
    }
}
