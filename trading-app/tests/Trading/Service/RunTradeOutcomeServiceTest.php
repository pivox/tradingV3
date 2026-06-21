<?php

declare(strict_types=1);

namespace App\Tests\Trading\Service;

use App\Trading\Service\PositionTradeAnalysisReaderInterface;
use App\Trading\Entity\PositionTradeAnalysis;
use App\Trading\Service\RunTradeOutcomeService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(RunTradeOutcomeService::class)]
final class RunTradeOutcomeServiceTest extends TestCase
{
    /**
     * Construit une ligne de vue `position_trade_analysis` (entité readOnly sans
     * setters) via réflexion, pour piloter les agrégats du service.
     *
     * @param array<string,float|null> $metrics
     */
    private function makeRow(string $symbol, array $metrics): PositionTradeAnalysis
    {
        $row = (new \ReflectionClass(PositionTradeAnalysis::class))->newInstanceWithoutConstructor();
        $set = static function (string $prop, mixed $value) use ($row): void {
            $ref = new \ReflectionProperty(PositionTradeAnalysis::class, $prop);
            $ref->setValue($row, $value);
        };
        $set('symbol', $symbol);
        $set('pnlUsdt', $metrics['pnl_usdt'] ?? null);
        $set('pnlR', $metrics['pnl_r'] ?? null);
        $set('mfePct', $metrics['mfe_pct'] ?? null);
        $set('maePct', $metrics['mae_pct'] ?? null);
        $set('holdingTimeSec', $metrics['holding_time_sec'] ?? null);

        return $row;
    }

    private function service(PositionTradeAnalysisReaderInterface $repo): RunTradeOutcomeService
    {
        return new RunTradeOutcomeService($repo, new NullLogger());
    }

    public function testAggregatesTradesByRunId(): void
    {
        $rows = [
            $this->makeRow('BTCUSDT', [
                'pnl_usdt' => 10.0, 'pnl_r' => 1.0, 'mfe_pct' => 2.0, 'mae_pct' => -1.0, 'holding_time_sec' => 100.0,
            ]),
            $this->makeRow('BTCUSDT', [
                'pnl_usdt' => -4.0, 'pnl_r' => -0.5, 'mfe_pct' => 1.0, 'mae_pct' => -3.0, 'holding_time_sec' => 300.0,
            ]),
            $this->makeRow('ETHUSDT', [
                'pnl_usdt' => 6.0, 'pnl_r' => 0.5, 'mfe_pct' => 4.0, 'mae_pct' => -2.0, 'holding_time_sec' => 200.0,
            ]),
        ];

        $repo = $this->createMock(PositionTradeAnalysisReaderInterface::class);
        $repo->expects(self::once())
            ->method('findByRunId')
            ->with('run_42')
            ->willReturn($rows);

        $outcome = $this->service($repo)->aggregateByRunId('run_42');

        self::assertTrue($outcome['available']);
        self::assertSame('run_42', $outcome['run_id']);
        self::assertSame(3, $outcome['trade_count']);
        self::assertSame(3, $outcome['closed_count']);
        self::assertSame(2, $outcome['win_count']);
        self::assertSame(1, $outcome['loss_count']);
        self::assertSame(round(2 / 3, 4), $outcome['win_rate']);
        // PnL net = somme exacte des valeurs de la vue (jamais recalculé).
        self::assertSame(12.0, $outcome['pnl_usdt']);
        self::assertSame(1.0, $outcome['pnl_r']);
        // MFE moyen = (2 + 1 + 4) / 3 ; médian = 2.
        self::assertEqualsWithDelta(7 / 3, $outcome['mfe_pct_avg'], 1e-6);
        self::assertSame(2.0, $outcome['mfe_pct_median']);
        self::assertSame(200.0, $outcome['holding_time_sec_avg']);

        // Ventilation par symbole, triée.
        $symbols = array_column($outcome['by_symbol'], 'symbol');
        self::assertSame(['BTCUSDT', 'ETHUSDT'], $symbols);
        $btc = $outcome['by_symbol'][0];
        self::assertSame(2, $btc['trade_count']);
        self::assertSame(6.0, $btc['pnl_usdt']);
        self::assertSame(0.5, $btc['win_rate']);
    }

    public function testRunWithoutTradesReturnsEmptyAvailableAggregate(): void
    {
        $repo = $this->createMock(PositionTradeAnalysisReaderInterface::class);
        $repo->method('findByRunId')->willReturn([]);

        $outcome = $this->service($repo)->aggregateByRunId('run_empty');

        self::assertTrue($outcome['available']);
        self::assertSame(0, $outcome['trade_count']);
        self::assertNull($outcome['win_rate']);
        self::assertNull($outcome['pnl_usdt']);
        self::assertSame([], $outcome['by_symbol']);
    }

    public function testUnavailableViewIsFailSafe(): void
    {
        $repo = $this->createMock(PositionTradeAnalysisReaderInterface::class);
        $repo->method('findByRunId')->willThrowException(new \RuntimeException('relation does not exist'));

        $outcome = $this->service($repo)->aggregateByRunId('run_boom');

        // Pas d'exception propagée : agrégat vide explicite, marqué non disponible.
        self::assertFalse($outcome['available']);
        self::assertSame(0, $outcome['trade_count']);
        self::assertSame([], $outcome['by_symbol']);
    }

    public function testRunIdIsTruncatedTo64CharsBeforeQuery(): void
    {
        $longRunId = 'run_' . str_repeat('z', 80);
        $expected = mb_substr($longRunId, 0, 64);

        $repo = $this->createMock(PositionTradeAnalysisReaderInterface::class);
        $repo->expects(self::once())
            ->method('findByRunId')
            ->with($expected)
            ->willReturn([]);

        $outcome = $this->service($repo)->aggregateByRunId($longRunId);

        self::assertSame($expected, $outcome['run_id']);
        self::assertSame(64, mb_strlen($outcome['run_id']));
    }

    public function testBlankRunIdReturnsEmptyAggregate(): void
    {
        $repo = $this->createMock(PositionTradeAnalysisReaderInterface::class);
        $repo->expects(self::never())->method('findByRunId');

        $outcome = $this->service($repo)->aggregateByRunId('   ');

        self::assertTrue($outcome['available']);
        self::assertSame(0, $outcome['trade_count']);
    }

    public function testOpenTradesAreCountedButNotClosed(): void
    {
        $rows = [
            $this->makeRow('BTCUSDT', ['pnl_usdt' => 5.0]),   // clôturé gagnant
            $this->makeRow('BTCUSDT', []),                    // encore ouvert (pnl null)
        ];
        $repo = $this->createMock(PositionTradeAnalysisReaderInterface::class);
        $repo->method('findByRunId')->willReturn($rows);

        $outcome = $this->service($repo)->aggregateByRunId('run_open');

        self::assertSame(2, $outcome['trade_count']);
        self::assertSame(1, $outcome['closed_count']);
        self::assertSame(1, $outcome['open_count']);
        self::assertSame(1.0, $outcome['win_rate']); // 1 gagnant / 1 clôturé
        self::assertSame(5.0, $outcome['pnl_usdt']);
    }
}
