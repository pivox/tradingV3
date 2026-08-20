<?php

declare(strict_types=1);

namespace App\Tests\Trading\Backfill;

use App\Trading\Backfill\BackfillDivergenceCriteria;
use App\Trading\Backfill\PositionTradeAnalysisBackfillDivergenceReader;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PositionTradeAnalysisBackfillDivergenceReader::class)]
final class PositionTradeAnalysisBackfillDivergenceReaderSqlTest extends TestCase
{
    public function testReaderUsesOnlyCanonicalCertificationAndTimingColumns(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(self::callback(static function (string $sql): bool {
                self::assertStringContainsString("to_jsonb(v2)->> 'canonical_net_pnl_usdt'", $sql);
                self::assertStringContainsString("to_jsonb(v2)->> 'canonical_cost_completeness'", $sql);
                self::assertStringContainsString("to_jsonb(v2)-> 'canonical_pnl_quality_flags'", $sql);
                self::assertStringContainsString("to_jsonb(v2)->> 'canonical_holding_time_sec'", $sql);
                self::assertStringContainsString("to_jsonb(v2)->> 'canonical_mfe_mae_data_quality'", $sql);
                self::assertStringContainsString("to_jsonb(v2)->> 'mode_id'", $sql);
                self::assertStringContainsString("to_jsonb(v2)->> 'setup_id'", $sql);
                self::assertStringContainsString("to_jsonb(v2)->> 'canonical_side'", $sql);
                self::assertStringContainsString("to_jsonb(v2)->> 'canonical_config_hash'", $sql);
                self::assertStringNotContainsString('v2.net_pnl_usdt AS v2_net_pnl_usdt', $sql);
                self::assertStringNotContainsString('v2.cost_completeness AS v2_cost_completeness', $sql);
                self::assertStringNotContainsString('v2.holding_time_sec AS v2_holding_time_sec', $sql);

                return true;
            }), self::isType('array'))
            ->willReturn([]);

        self::assertSame([], (new PositionTradeAnalysisBackfillDivergenceReader($connection))->fetchRows(
            new BackfillDivergenceCriteria(),
        ));
    }
}
