<?php

declare(strict_types=1);

namespace App\Tests\Trading\Backfill;

use App\Trading\Backfill\BackfillDivergenceCriteria;
use App\Trading\Backfill\PositionTradeAnalysisBackfillDivergenceReaderInterface;
use App\Trading\Backfill\PositionTradeAnalysisBackfillDivergenceReportService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PositionTradeAnalysisBackfillDivergenceReportService::class)]
#[CoversClass(BackfillDivergenceCriteria::class)]
final class PositionTradeAnalysisBackfillDivergenceReportServiceTest extends TestCase
{
    public function testClassifiesCertifiedPartialLegacyAndDivergenceRowsWithoutMutatingSource(): void
    {
        $reader = new RecordingDivergenceReader([
            $this->row(101, [
                'v1_present' => true,
                'v2_present' => true,
                'v1_pnl_usdt' => '12.50000000',
                'v2_recorded_pnl_usdt' => '12.50000000',
                'v2_net_pnl_usdt' => '12.50000000',
                'v2_cost_completeness' => 'complete',
                'v2_close_match_status' => 'matched',
                'v2_analysis_status' => 'matched_closed',
                'v2_position_fully_closed' => true,
            ]),
            $this->row(102, [
                'v1_present' => true,
                'v2_present' => true,
                'v1_pnl_usdt' => '-4.00000000',
                'v2_recorded_pnl_usdt' => '-4.00000000',
                'v2_cost_completeness' => 'unknown',
                'v2_close_match_status' => 'matched',
                'v2_analysis_status' => 'matched_closed',
            ]),
            $this->row(103, [
                'v1_present' => true,
                'v2_present' => false,
            ]),
            $this->row(104, [
                'v1_present' => true,
                'v2_present' => true,
                'v1_pnl_usdt' => '10.00000000',
                'v2_recorded_pnl_usdt' => '7.50000000',
                'v2_cost_completeness' => 'partial',
                'v2_close_match_status' => 'matched',
                'v2_analysis_status' => 'matched_closed',
            ]),
            $this->row(105, [
                'v1_present' => true,
                'v2_present' => true,
                'v2_cost_completeness' => 'partial',
                'v2_close_match_status' => 'matched',
                'v2_pnl_quality_flags' => ['identifier_conflict'],
            ]),
        ]);

        $service = new PositionTradeAnalysisBackfillDivergenceReportService($reader);
        $criteria = new BackfillDivergenceCriteria(
            from: new \DateTimeImmutable('2026-06-01 00:00:00 UTC'),
            to: new \DateTimeImmutable('2026-06-30 23:59:59 UTC'),
            profile: 'scalper',
            exchange: 'fake',
            marketType: 'perpetual',
            symbol: 'BTCUSDT',
            limit: 5,
            batchSize: 2,
            resumeCursor: 100,
            dryRun: true,
        );

        $report = $service->buildReport($criteria);

        self::assertCount(3, $reader->criteria);
        self::assertSame(100, $reader->criteria[0]->resumeCursor);
        self::assertSame(2, $reader->criteria[0]->limit);
        self::assertSame(102, $reader->criteria[1]->resumeCursor);
        self::assertSame(2, $reader->criteria[1]->limit);
        self::assertSame(104, $reader->criteria[2]->resumeCursor);
        self::assertSame(2, $reader->criteria[2]->limit);
        self::assertSame(5, $report['summary']['v1_rows']);
        self::assertSame(4, $report['summary']['v2_rows']);
        self::assertSame(4, $report['summary']['common_rows']);
        self::assertSame(1, $report['summary']['certified_rows']);
        self::assertSame(4, $report['summary']['excluded_rows']);
        self::assertSame(1, $report['summary']['divergent_rows']);
        self::assertSame([
            'certified' => 1,
            'unknown' => 1,
            'v1_only' => 1,
            'pnl_divergence' => 1,
            'identifier_conflict' => 1,
        ], $report['summary']['classification_counts']);
        self::assertFalse($report['proposal']['ready_for_backfill']);
        self::assertSame('divergence_or_incomplete_data', $report['proposal']['blocking_reason']);
        self::assertSame(105, $report['pagination']['next_cursor']);
        self::assertTrue($report['metadata']['dry_run']);
        self::assertTrue($report['metadata']['read_only']);
        self::assertSame('entry_event_id', $report['metadata']['comparison_key']);
    }

    public function testPaginationTruncatesToLimitAndKeepsDeterministicCursor(): void
    {
        $reader = new RecordingDivergenceReader([
            $this->row(201),
            $this->row(202),
            $this->row(203),
            $this->row(204),
        ]);

        $service = new PositionTradeAnalysisBackfillDivergenceReportService($reader);
        $report = $service->buildReport(new BackfillDivergenceCriteria(limit: 3, batchSize: 2));

        self::assertTrue($report['pagination']['truncated']);
        self::assertSame(203, $report['pagination']['next_cursor']);
        self::assertSame([201, 202, 203], array_column($report['rows'], 'entry_event_id'));
    }

    public function testLegacyPnlComparesAgainstRecordedBeforeGross(): void
    {
        $reader = new RecordingDivergenceReader([
            $this->row(250, [
                'v1_pnl_usdt' => '9.44000000',
                'v2_recorded_pnl_usdt' => '9.44000000',
                'v2_gross_realized_pnl_usdt' => '9.60000000',
                'v2_net_pnl_usdt' => null,
                'v2_cost_completeness' => 'partial',
            ]),
        ]);

        $report = (new PositionTradeAnalysisBackfillDivergenceReportService($reader))
            ->buildReport(new BackfillDivergenceCriteria());

        self::assertSame('costs_incomplete', $report['rows'][0]['classification']);
        self::assertSame(0.0, $report['rows'][0]['pnl_delta_usdt']);
        self::assertSame(0, $report['summary']['divergent_rows']);
    }

    public function testCertifiedPnlComparesAgainstNetBeforeRecorded(): void
    {
        $reader = new RecordingDivergenceReader([
            $this->row(260, [
                'v1_pnl_usdt' => '9.44000000',
                'v2_recorded_pnl_usdt' => '9.44000000',
                'v2_gross_realized_pnl_usdt' => '9.60000000',
                'v2_net_pnl_usdt' => '9.10000000',
                'v2_cost_completeness' => 'complete',
            ]),
        ]);

        $report = (new PositionTradeAnalysisBackfillDivergenceReportService($reader))
            ->buildReport(new BackfillDivergenceCriteria());

        self::assertSame('pnl_divergence', $report['rows'][0]['classification']);
        self::assertSame(-0.34, $report['rows'][0]['pnl_delta_usdt']);
        self::assertSame(1, $report['summary']['divergent_rows']);
        self::assertSame(0, $report['summary']['certified_rows']);
    }

    public function testEmptyDatasetIsReadOnlyAndBackfillReady(): void
    {
        $service = new PositionTradeAnalysisBackfillDivergenceReportService(new RecordingDivergenceReader([]));

        $report = $service->buildReport(new BackfillDivergenceCriteria());

        self::assertSame(0, $report['summary']['v1_rows']);
        self::assertSame(0, $report['summary']['v2_rows']);
        self::assertSame([], $report['rows']);
        self::assertTrue($report['proposal']['ready_for_backfill']);
        self::assertTrue($report['metadata']['dry_run']);
    }

    public function testUnknownRawPayloadColumnsAreNotExported(): void
    {
        $service = new PositionTradeAnalysisBackfillDivergenceReportService(new RecordingDivergenceReader([
            $this->row(301, [
                'api_secret' => 'should-not-leak',
                'raw_payload' => ['token' => 'should-not-leak'],
            ]),
        ]));

        $report = $service->buildReport(new BackfillDivergenceCriteria());
        $json = json_encode($report, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('should-not-leak', $json);
        self::assertStringNotContainsString('raw_payload', $json);
        self::assertStringNotContainsString('api_secret', $json);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function row(int $entryEventId, array $overrides = []): array
    {
        return $overrides + [
            'entry_event_id' => $entryEventId,
            'v1_present' => true,
            'v2_present' => true,
            'symbol' => 'BTCUSDT',
            'exchange' => 'fake',
            'market_type' => 'perpetual',
            'profile' => 'scalper',
            'entry_time' => '2026-06-15 10:00:00+00',
            'v1_pnl_usdt' => '1.00000000',
            'v1_holding_time_sec' => '60',
            'v1_mfe_pct' => '0.01',
            'v1_mae_pct' => '-0.005',
            'v2_recorded_pnl_usdt' => '1.00000000',
            'v2_net_pnl_usdt' => null,
            'v2_holding_time_sec' => '60',
            'v2_mfe_pct' => '0.01',
            'v2_mae_pct' => '-0.005',
            'v2_close_match_status' => 'matched',
            'v2_analysis_status' => 'matched_closed',
            'v2_cost_completeness' => 'partial',
            'v2_position_fully_closed' => true,
            'v2_pnl_quality_flags' => [],
        ];
    }
}

final class RecordingDivergenceReader implements PositionTradeAnalysisBackfillDivergenceReaderInterface
{
    /** @var list<BackfillDivergenceCriteria> */
    public array $criteria = [];

    /**
     * @param list<array<string,mixed>> $rows
     */
    public function __construct(private readonly array $rows)
    {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function fetchRows(BackfillDivergenceCriteria $criteria): array
    {
        $this->criteria[] = $criteria;

        $rows = array_values(array_filter(
            $this->rows,
            static fn (array $row): bool => $criteria->resumeCursor === null || (int) $row['entry_event_id'] > $criteria->resumeCursor,
        ));

        return array_slice($rows, 0, $criteria->limit);
    }
}
