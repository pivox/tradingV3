<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PositionTradeAnalysisBackfillDivergenceCommand;
use App\Trading\Backfill\BackfillDivergenceCriteria;
use App\Trading\Backfill\PositionTradeAnalysisBackfillDivergenceExporter;
use App\Trading\Backfill\PositionTradeAnalysisBackfillDivergenceReportServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(PositionTradeAnalysisBackfillDivergenceCommand::class)]
#[CoversClass(PositionTradeAnalysisBackfillDivergenceExporter::class)]
#[CoversClass(BackfillDivergenceCriteria::class)]
final class PositionTradeAnalysisBackfillDivergenceCommandTest extends TestCase
{
    public function testDryRunCommandBuildsBoundedReportAndExportsJsonAndCsv(): void
    {
        $tmpDir = sys_get_temp_dir() . '/pta-backfill-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($tmpDir));

        $service = new StubBackfillReportService([
            'metadata' => [
                'dry_run' => true,
                'read_only' => true,
                'comparison_key' => 'entry_event_id',
            ],
            'summary' => [
                'v1_rows' => 1,
                'v2_rows' => 1,
                'common_rows' => 1,
                'certified_rows' => 1,
                'excluded_rows' => 0,
                'classification_counts' => ['certified' => 1],
            ],
            'pagination' => [
                'limit' => 100,
                'batch_size' => 50,
                'resume_cursor' => null,
                'next_cursor' => 42,
                'truncated' => false,
            ],
            'proposal' => [
                'ready_for_backfill' => true,
                'blocking_reason' => null,
            ],
            'rows' => [[
                'entry_event_id' => 42,
                'classification' => 'certified',
                'symbol' => 'BTCUSDT',
                'exchange' => 'fake',
                'market_type' => 'perpetual',
                'pnl_delta_usdt' => 0.0,
            ]],
        ]);
        $command = new PositionTradeAnalysisBackfillDivergenceCommand(
            $service,
            new PositionTradeAnalysisBackfillDivergenceExporter(),
        );
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--from' => '2026-06-01',
            '--to' => '2026-06-30',
            '--mtf-profile' => 'scalper',
            '--exchange' => 'fake',
            '--market-type' => 'perpetual',
            '--symbol' => 'btcusdt',
            '--limit' => '100',
            '--batch-size' => '50',
            '--export-json' => $tmpDir . '/report.json',
            '--export-csv' => $tmpDir . '/report.csv',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Dry-run: yes', $tester->getDisplay());
        self::assertStringContainsString('Read-only: yes', $tester->getDisplay());
        self::assertNotNull($service->criteria);
        self::assertSame('BTCUSDT', $service->criteria->symbol);
        self::assertSame('fake', $service->criteria->exchange);
        self::assertSame(100, $service->criteria->limit);
        self::assertSame(50, $service->criteria->batchSize);
        self::assertTrue($service->criteria->dryRun);
        self::assertSame('2026-06-30 23:59:59.999999', $service->criteria->to?->format('Y-m-d H:i:s.u'));
        self::assertFileExists($tmpDir . '/report.json');
        self::assertFileExists($tmpDir . '/report.csv');
        self::assertStringContainsString('"comparison_key": "entry_event_id"', (string) file_get_contents($tmpDir . '/report.json'));
        self::assertStringContainsString('entry_event_id,classification,symbol,exchange,market_data_venue,market_type', (string) file_get_contents($tmpDir . '/report.csv'));
    }

    public function testApplyModeIsRejectedAndDoesNotCallReportService(): void
    {
        $service = new StubBackfillReportService(['rows' => []]);
        $command = new PositionTradeAnalysisBackfillDivergenceCommand(
            $service,
            new PositionTradeAnalysisBackfillDivergenceExporter(),
        );
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--apply' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertNull($service->criteria);
        self::assertStringContainsString('Apply mode is intentionally disabled', $tester->getDisplay());
    }

    public function testRejectsLimitAboveMaximumBeforeQuerying(): void
    {
        $service = new StubBackfillReportService(['rows' => []]);
        $command = new PositionTradeAnalysisBackfillDivergenceCommand(
            $service,
            new PositionTradeAnalysisBackfillDivergenceExporter(),
        );
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--limit' => '50001']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertNull($service->criteria);
        self::assertStringContainsString('Limit must be between 1 and 5000', $tester->getDisplay());
    }

    public function testRejectsParserWarningsForInvalidDateBounds(): void
    {
        $service = new StubBackfillReportService(['rows' => []]);
        $command = new PositionTradeAnalysisBackfillDivergenceCommand(
            $service,
            new PositionTradeAnalysisBackfillDivergenceExporter(),
        );
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--to' => '2026-06-31']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertNull($service->criteria);
        self::assertStringContainsString('Option --to must be a valid datetime.', $tester->getDisplay());
    }
}

final class StubBackfillReportService implements PositionTradeAnalysisBackfillDivergenceReportServiceInterface
{
    public ?BackfillDivergenceCriteria $criteria = null;

    /**
     * @param array<string,mixed> $report
     */
    public function __construct(private readonly array $report)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function buildReport(BackfillDivergenceCriteria $criteria): array
    {
        $this->criteria = $criteria;

        return $this->report;
    }
}
