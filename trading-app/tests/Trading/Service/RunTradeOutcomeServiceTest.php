<?php

declare(strict_types=1);

namespace App\Tests\Trading\Service;

use App\Trading\Entity\PositionTradeAnalysis;
use App\Trading\Service\PositionTradeAnalysisReaderInterface;
use App\Trading\Service\PositionTradeOutcomeSourceException;
use App\Trading\Service\RunTradeOutcomeService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * OBS-003 — Agrégation OUTCOME : couvre run avec trades (count/PnL/MFE-MAE/win-rate +
 * ventilations), run sans trade (agrégat vide explicite), source indisponible (fail-safe,
 * exception jamais agrégat vide), filtre set_id, sémantique recorded/net PnL.
 */
#[CoversClass(RunTradeOutcomeService::class)]
final class RunTradeOutcomeServiceTest extends TestCase
{
    public function testRunWithTradesAggregatesAndVentilates(): void
    {
        // Run = "run_dashA" (court, sûr) -> correlation == identité.
        $rows = [
            // BTC, set s1, profil scalper, exchange bitmart : gagnant clôturé+rapproché, net complet
            $this->row([
                'symbol' => 'BTCUSDT', 'setId' => 's1', 'mtfProfile' => 'scalper', 'exchange' => 'bitmart',
                'closeEventId' => 10, 'closeMatchStatus' => 'matched', 'closeMatchedBy' => 'matched_trade_id',
                'recordedPnlUsdt' => 12.0, 'pnlR' => 1.5, 'mfePct' => 2.0, 'maePct' => -0.5, 'holdingTimeSec' => 100.0,
                'feesUsdt' => 1.0, 'fundingUsdt' => 0.2, 'slippageUsdt' => 0.1, 'netPnlUsdt' => 10.7, 'netPnlComplete' => true,
            ]),
            // BTC, set s2, profil regular : perdant clôturé+rapproché, net complet
            $this->row([
                'symbol' => 'BTCUSDT', 'setId' => 's2', 'mtfProfile' => 'regular', 'exchange' => 'bitmart',
                'closeEventId' => 11, 'closeMatchStatus' => 'matched', 'closeMatchedBy' => 'matched_position_id',
                'recordedPnlUsdt' => -4.0, 'pnlR' => -1.0, 'mfePct' => 0.5, 'maePct' => -1.5, 'holdingTimeSec' => 200.0,
                'feesUsdt' => 1.0, 'fundingUsdt' => 0.0, 'slippageUsdt' => 0.0, 'netPnlUsdt' => -5.0, 'netPnlComplete' => true,
            ]),
            // ETH, set s1, profil scalper : trade ouvert (pas de clôture) -> exclu du winrate
            $this->row([
                'symbol' => 'ETHUSDT', 'setId' => 's1', 'mtfProfile' => 'scalper', 'exchange' => 'bitmart',
                'closeEventId' => null, 'closeMatchStatus' => 'unmatched', 'closeMatchedBy' => 'unmatched',
                'recordedPnlUsdt' => null, 'pnlR' => null, 'mfePct' => 1.0, 'maePct' => -0.2, 'holdingTimeSec' => null,
                'netPnlComplete' => false,
            ]),
        ];

        $service = new RunTradeOutcomeService($this->reader($rows));
        $out = $service->buildOutcome('run_dashA');

        self::assertSame('run_dashA', $out['run_id']);
        self::assertSame('run_dashA', $out['correlation_run_id']);
        self::assertTrue($out['source_available']);
        // un trade ouvert sans net complet n'invalide pas data_complete (seuls les clôturés comptent)
        self::assertTrue($out['data_complete']);

        $s = $out['summary'];
        self::assertSame(3, $s['trade_count']);
        self::assertSame(2, $s['closed_count']);
        self::assertSame(1, $s['open_count']);
        self::assertSame(2, $s['matched_count']);
        self::assertSame(1, $s['unmatched_count']);
        self::assertSame(1, $s['win_count']);
        self::assertSame(1, $s['loss_count']);
        self::assertSame(0.5, $s['win_rate_closed']);
        self::assertSame(8.0, $s['recorded_pnl_usdt']); // 12 - 4
        self::assertSame(0.5, $s['pnl_r']);              // 1.5 - 1.0
        self::assertTrue($s['net_pnl_complete']);
        self::assertEqualsWithDelta(5.7, $s['net_pnl_usdt'], 1e-9); // 10.7 - 5.0
        self::assertEqualsWithDelta((2.0 + 0.5 + 1.0) / 3, $s['mfe_pct_avg'], 1e-6);
        self::assertSame(1.0, $s['mfe_pct_median']);

        // Ventilations
        $bySymbol = $this->byKey($out['by_symbol']);
        self::assertSame(2, $bySymbol['BTCUSDT']['trade_count']);
        self::assertSame(1, $bySymbol['ETHUSDT']['trade_count']);

        $bySet = $this->byKey($out['by_set']);
        self::assertSame(2, $bySet['s1']['trade_count']);
        self::assertSame(1, $bySet['s2']['trade_count']);

        $byProfile = $this->byKey($out['by_profile']);
        self::assertSame(2, $byProfile['scalper']['trade_count']);
        self::assertSame(1, $byProfile['regular']['trade_count']);

        $byExchange = $this->byKey($out['by_exchange']);
        self::assertSame(3, $byExchange['bitmart']['trade_count']);
    }

    public function testRunWithoutTradeReturnsExplicitEmptyAggregate(): void
    {
        $service = new RunTradeOutcomeService($this->reader([]));
        $out = $service->buildOutcome('run_empty');

        self::assertTrue($out['source_available']);
        self::assertTrue($out['data_complete']);
        self::assertSame(0, $out['summary']['trade_count']);
        self::assertNull($out['summary']['win_rate_closed']);
        self::assertNull($out['summary']['recorded_pnl_usdt']);
        self::assertNull($out['summary']['net_pnl_usdt']);
        self::assertSame([], $out['by_symbol']);
    }

    public function testSourceUnavailableThrowsAndIsNeverAnEmptyAggregate(): void
    {
        $reader = new class implements PositionTradeAnalysisReaderInterface {
            public function findByCorrelationRunId(string $correlationRunId, ?string $setId = null, int $limit = 2000): array
            {
                throw new \RuntimeException('view missing');
            }
        };

        $service = new RunTradeOutcomeService($reader);
        $this->expectException(PositionTradeOutcomeSourceException::class);
        $service->buildOutcome('run_x');
    }

    public function testSetIdFilterIsForwardedToReader(): void
    {
        $reader = new class implements PositionTradeAnalysisReaderInterface {
            public ?string $seenSetId = 'NOT_SET';
            public function findByCorrelationRunId(string $correlationRunId, ?string $setId = null, int $limit = 2000): array
            {
                $this->seenSetId = $setId;
                return [];
            }
        };

        $service = new RunTradeOutcomeService($reader);
        $out = $service->buildOutcome('run_x', 's42');

        self::assertSame('s42', $reader->seenSetId);
        self::assertSame('s42', $out['set_id']);
    }

    public function testNetPnlIncompleteWhenAClosedTradeMissesCosts(): void
    {
        $rows = [
            $this->row([
                'symbol' => 'BTCUSDT', 'closeEventId' => 1, 'closeMatchStatus' => 'matched',
                'closeMatchedBy' => 'matched_trade_id', 'recordedPnlUsdt' => 5.0, 'netPnlComplete' => false,
            ]),
        ];

        $service = new RunTradeOutcomeService($this->reader($rows));
        $out = $service->buildOutcome('run_x');

        self::assertFalse($out['data_complete']);
        self::assertFalse($out['summary']['net_pnl_complete']);
        self::assertNull($out['summary']['net_pnl_usdt']);
        self::assertSame(5.0, $out['summary']['recorded_pnl_usdt']);
    }

    /**
     * @param array<string,mixed> $rows
     */
    private function reader(array $rows): PositionTradeAnalysisReaderInterface
    {
        return new class($rows) implements PositionTradeAnalysisReaderInterface {
            /** @param PositionTradeAnalysis[] $rows */
            public function __construct(private readonly array $rows)
            {
            }

            public function findByCorrelationRunId(string $correlationRunId, ?string $setId = null, int $limit = 2000): array
            {
                if ($setId === null) {
                    return $this->rows;
                }

                return array_values(array_filter(
                    $this->rows,
                    static fn (PositionTradeAnalysis $r): bool => $r->getSetId() === $setId
                ));
            }
        };
    }

    /**
     * @param list<array<string,mixed>> $groups
     * @return array<string,array<string,mixed>>
     */
    private function byKey(array $groups): array
    {
        $out = [];
        foreach ($groups as $g) {
            $out[$g['key']] = $g;
        }

        return $out;
    }

    /**
     * Construit une ligne de vue (entité readOnly) via réflexion.
     *
     * @param array<string,mixed> $overrides
     */
    private function row(array $overrides): PositionTradeAnalysis
    {
        static $autoId = 1;
        $defaults = [
            'entryEventId' => $autoId++,
            'symbol' => 'BTCUSDT',
            'entryTime' => new \DateTimeImmutable('2026-06-17T08:30:00+00:00'),
            'closeMatchStatus' => 'unmatched',
            'closeMatchedBy' => 'unmatched',
            'netPnlComplete' => false,
        ];
        $data = array_merge($defaults, $overrides);

        $entity = (new \ReflectionClass(PositionTradeAnalysis::class))->newInstanceWithoutConstructor();
        $ref = new \ReflectionObject($entity);
        foreach ($data as $prop => $value) {
            if (!$ref->hasProperty($prop)) {
                continue;
            }
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($entity, $value);
        }

        return $entity;
    }
}
