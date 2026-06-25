<?php

declare(strict_types=1);

namespace App\Tests\Trading\Service;

use App\Trading\Entity\PositionTradeAnalysisV2;
use App\Trading\Service\PositionTradeAnalysisReaderInterface;
use App\Trading\Service\PositionTradeOutcomeSourceException;
use App\Trading\Service\RunTradeOutcomeService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * OBS-003 — Agrégation OUTCOME : couvre run avec trades (compteurs matched/unmatched,
 * recorded vs estimated PnL, win-rate sur matched_closed), run sans trade, source
 * indisponible (fail-safe), filtre set_id, distinction des états et complétude des coûts.
 */
#[CoversClass(RunTradeOutcomeService::class)]
final class RunTradeOutcomeServiceTest extends TestCase
{
    public function testRunWithTradesAggregatesAndVentilates(): void
    {
        $rows = [
            // BTC / s1 / scalper : gagnant clôturé+rapproché, coûts partiels, net estimé.
            $this->row([
                'symbol' => 'BTCUSDT', 'setId' => 's1', 'mtfProfile' => 'scalper', 'exchange' => 'bitmart',
                'closeEventId' => 10, 'closeMatchStatus' => 'matched', 'closeMatchedBy' => 'matched_trade_id',
                'analysisStatus' => 'matched_closed', 'recordedPnlUsdt' => 12.0, 'pnlR' => 1.5,
                'mfePct' => 2.0, 'maePct' => -0.5, 'holdingTimeSec' => 100.0,
                'feesUsdt' => 1.0, 'fundingUsdt' => 0.2, 'slippageUsdt' => 0.1,
                'estimatedNetPnlUsdt' => 10.7, 'costCompleteness' => 'partial',
            ]),
            // BTC / s2 / regular : perdant clôturé+rapproché, aucun coût (unknown).
            $this->row([
                'symbol' => 'BTCUSDT', 'setId' => 's2', 'mtfProfile' => 'regular', 'exchange' => 'bitmart',
                'closeEventId' => 11, 'closeMatchStatus' => 'matched', 'closeMatchedBy' => 'matched_position_id',
                'analysisStatus' => 'matched_closed', 'recordedPnlUsdt' => -4.0, 'pnlR' => -1.0,
                'mfePct' => 0.5, 'maePct' => -1.5, 'holdingTimeSec' => 200.0,
                'costCompleteness' => 'unknown',
            ]),
            // ETH / s1 / scalper : non rapproché -> état réel INCONNU (jamais "open confirmé").
            $this->row([
                'symbol' => 'ETHUSDT', 'setId' => 's1', 'mtfProfile' => 'scalper', 'exchange' => 'bitmart',
                'closeEventId' => null, 'closeMatchStatus' => 'unmatched', 'closeMatchedBy' => 'unmatched',
                'analysisStatus' => 'unmatched', 'recordedPnlUsdt' => null, 'pnlR' => null,
                'mfePct' => 1.0, 'maePct' => -0.2, 'costCompleteness' => 'not_applicable',
            ]),
        ];

        $service = new RunTradeOutcomeService($this->reader($rows));
        $out = $service->buildOutcome('run_dashA');

        self::assertSame('run_dashA', $out['run_id']);
        self::assertSame('run_dashA', $out['correlation_run_id']);
        self::assertTrue($out['source_available']);
        self::assertFalse($out['truncated']);
        // Une ligne unmatched + des coûts incomplets => données non complètes.
        self::assertFalse($out['data_complete']);

        $s = $out['summary'];
        self::assertSame(3, $s['trade_count']);
        self::assertSame(2, $s['matched_closed_count']);
        self::assertSame(1, $s['unmatched_count']);
        self::assertSame(0, $s['confirmed_open_count']);
        self::assertSame(1, $s['unknown_state_count']);
        self::assertSame(1, $s['win_count']);
        self::assertSame(1, $s['loss_count']);
        self::assertSame(0.5, $s['win_rate_closed']);
        self::assertSame(8.0, $s['recorded_pnl_usdt']);
        self::assertSame(0.5, $s['pnl_r']);
        self::assertEqualsWithDelta(10.7, $s['estimated_net_pnl_usdt'], 1e-9);
        self::assertSame('mixed', $s['cost_completeness']); // partial + unknown
        self::assertFalse($s['data_complete']);
        self::assertEqualsWithDelta((2.0 + 0.5 + 1.0) / 3, $s['mfe_pct_avg'], 1e-6);
        self::assertSame(1.0, $s['mfe_pct_median']);

        $bySymbol = $this->byKey($out['by_symbol']);
        self::assertSame(2, $bySymbol['BTCUSDT']['trade_count']);
        self::assertSame(1, $bySymbol['ETHUSDT']['trade_count']);

        $bySet = $this->byKey($out['by_set']);
        self::assertSame(2, $bySet['s1']['trade_count']);
        self::assertSame(1, $bySet['s1']['matched_closed_count']); // R1 matched, R3 unmatched
        self::assertSame(1, $bySet['s2']['trade_count']);

        $byProfile = $this->byKey($out['by_profile']);
        self::assertSame(2, $byProfile['scalper']['trade_count']);
        self::assertSame(1, $byProfile['regular']['trade_count']);

        $byExchange = $this->byKey($out['by_exchange']);
        self::assertSame(3, $byExchange['bitmart']['trade_count']);
    }

    public function testAggregatesCertifiedNetOnlyWithoutMixingIncompleteRows(): void
    {
        $rows = [
            $this->row([
                'symbol' => 'BTCUSDT',
                'closeEventId' => 10,
                'closeMatchStatus' => 'matched',
                'closeMatchedBy' => 'matched_internal_trade_id',
                'analysisStatus' => 'matched_closed',
                'recordedPnlUsdt' => 10.0,
                'costCompleteness' => 'complete',
                'netPnlUsdt' => 8.5,
            ]),
            $this->row([
                'symbol' => 'ETHUSDT',
                'closeEventId' => 11,
                'closeMatchStatus' => 'matched',
                'closeMatchedBy' => 'matched_internal_trade_id',
                'analysisStatus' => 'matched_closed',
                'recordedPnlUsdt' => -3.0,
                'costCompleteness' => 'partial',
                'netPnlUsdt' => null,
            ]),
            $this->row([
                'symbol' => 'SOLUSDT',
                'closeEventId' => null,
                'closeMatchStatus' => 'unmatched',
                'closeMatchedBy' => 'unmatched',
                'analysisStatus' => 'unmatched',
                'recordedPnlUsdt' => null,
                'costCompleteness' => 'not_applicable',
                'netPnlUsdt' => null,
            ]),
        ];

        $service = new RunTradeOutcomeService($this->reader($rows));
        $summary = $service->buildOutcome('run-1')['summary'];

        self::assertSame(1, $summary['net_certified_count']);
        self::assertSame(2, $summary['excluded_count']);
        self::assertSame(1, $summary['incomplete_cost_count']);
        self::assertSame(1, $summary['unmatched_count']);
        self::assertEqualsWithDelta(8.5, $summary['net_pnl_usdt'], 1e-9);
        self::assertSame(1.0, $summary['win_rate_net_certified']);
        self::assertEqualsWithDelta(8.5, $summary['expectancy_net_pnl_usdt'], 1e-9);
        self::assertSame('certified_net_v1', $summary['pnl_definition']);
    }

    public function testRunWithoutTradeReturnsExplicitEmptyAggregate(): void
    {
        $service = new RunTradeOutcomeService($this->reader([]));
        $out = $service->buildOutcome('run_empty');

        self::assertTrue($out['source_available']);
        self::assertTrue($out['data_complete']); // aucune ligne => vacuously complet
        $s = $out['summary'];
        self::assertSame(0, $s['trade_count']);
        self::assertSame(0, $s['matched_closed_count']);
        self::assertSame(0, $s['unmatched_count']);
        self::assertNull($s['win_rate_closed']);
        self::assertNull($s['recorded_pnl_usdt']);
        self::assertNull($s['estimated_net_pnl_usdt']);
        self::assertSame('not_applicable', $s['cost_completeness']);
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

    public function testUnmatchedIsNeverCountedAsConfirmedOpen(): void
    {
        $rows = [
            $this->row([
                'symbol' => 'BTCUSDT', 'closeEventId' => null, 'closeMatchStatus' => 'unmatched',
                'closeMatchedBy' => 'unmatched', 'analysisStatus' => 'unmatched',
                'recordedPnlUsdt' => null, 'costCompleteness' => 'not_applicable',
            ]),
        ];

        $service = new RunTradeOutcomeService($this->reader($rows));
        $s = $service->buildOutcome('run_x')['summary'];

        self::assertSame(1, $s['trade_count']);
        self::assertSame(0, $s['matched_closed_count']);
        self::assertSame(1, $s['unmatched_count']);
        self::assertSame(0, $s['confirmed_open_count']);
        self::assertSame(1, $s['unknown_state_count']);
        self::assertNull($s['win_rate_closed']);
    }

    public function testCostCompletenessAndEstimatedNetAreNotCertified(): void
    {
        $rows = [
            $this->row([
                'symbol' => 'BTCUSDT', 'closeEventId' => 1, 'closeMatchStatus' => 'matched',
                'closeMatchedBy' => 'matched_trade_id', 'analysisStatus' => 'matched_closed',
                'recordedPnlUsdt' => 5.0, 'estimatedNetPnlUsdt' => null, 'costCompleteness' => 'partial',
            ]),
        ];

        $service = new RunTradeOutcomeService($this->reader($rows));
        $out = $service->buildOutcome('run_x');

        self::assertFalse($out['data_complete']); // partial != complete
        self::assertSame('partial', $out['summary']['cost_completeness']);
        self::assertNull($out['summary']['estimated_net_pnl_usdt']);
        self::assertSame(5.0, $out['summary']['recorded_pnl_usdt']);
    }

    public function testRowCapTruncationIsFlaggedNotSilentlyUndercounted(): void
    {
        $rows = [
            $this->row(['symbol' => 'BTCUSDT', 'closeEventId' => 1, 'closeMatchStatus' => 'matched', 'closeMatchedBy' => 'matched_trade_id', 'analysisStatus' => 'matched_closed', 'recordedPnlUsdt' => 1.0, 'costCompleteness' => 'partial']),
            $this->row(['symbol' => 'ETHUSDT', 'closeEventId' => 2, 'closeMatchStatus' => 'matched', 'closeMatchedBy' => 'matched_trade_id', 'analysisStatus' => 'matched_closed', 'recordedPnlUsdt' => 2.0, 'costCompleteness' => 'partial']),
            $this->row(['symbol' => 'SOLUSDT', 'closeEventId' => 3, 'closeMatchStatus' => 'matched', 'closeMatchedBy' => 'matched_trade_id', 'analysisStatus' => 'matched_closed', 'recordedPnlUsdt' => 3.0, 'costCompleteness' => 'partial']),
        ];

        // Plafond bas (2) pour 3 lignes => troncature signalée, jamais sous-comptée en silence.
        $service = new RunTradeOutcomeService($this->reader($rows), null, 2);
        $out = $service->buildOutcome('run_big');

        self::assertTrue($out['truncated']);
        self::assertSame(2, $out['row_cap']);
        self::assertFalse($out['data_complete']);
        // Agrégat calculé sur les lignes capées (2), pas faussement présenté comme complet.
        self::assertSame(2, $out['summary']['trade_count']);
    }

    public function testTruncationForcesNestedDataCompleteFalse(): void
    {
        // Lignes qui SERAIENT complètes (matched_closed + cost 'complete') : sans propagation,
        // `summary.data_complete` et les `data_complete` de groupe seraient vrais sur les
        // lignes capées, présentant des KPI partiels comme complets malgré la troncature.
        $rows = [
            $this->row(['symbol' => 'BTCUSDT', 'setId' => 's1', 'closeEventId' => 1, 'closeMatchStatus' => 'matched', 'closeMatchedBy' => 'matched_trade_id', 'analysisStatus' => 'matched_closed', 'recordedPnlUsdt' => 1.0, 'costCompleteness' => 'complete']),
            $this->row(['symbol' => 'ETHUSDT', 'setId' => 's2', 'closeEventId' => 2, 'closeMatchStatus' => 'matched', 'closeMatchedBy' => 'matched_trade_id', 'analysisStatus' => 'matched_closed', 'recordedPnlUsdt' => 2.0, 'costCompleteness' => 'complete']),
            $this->row(['symbol' => 'SOLUSDT', 'setId' => 's3', 'closeEventId' => 3, 'closeMatchStatus' => 'matched', 'closeMatchedBy' => 'matched_trade_id', 'analysisStatus' => 'matched_closed', 'recordedPnlUsdt' => 3.0, 'costCompleteness' => 'complete']),
        ];

        $service = new RunTradeOutcomeService($this->reader($rows), null, 2);
        $out = $service->buildOutcome('run_big');

        self::assertTrue($out['truncated']);
        self::assertFalse($out['data_complete']);
        // Propagation : summary ET chaque agrégat groupé signalent l'incomplétude.
        self::assertFalse($out['summary']['data_complete']);
        foreach (['by_set', 'by_profile', 'by_exchange', 'by_symbol'] as $group) {
            foreach ($out[$group] as $bucket) {
                self::assertFalse($bucket['data_complete'], "data_complete propagé sur {$group}");
            }
        }
    }

    public function testWithinRowCapIsNotTruncated(): void
    {
        $service = new RunTradeOutcomeService($this->reader([]), null, 2);
        $out = $service->buildOutcome('run_small');

        self::assertFalse($out['truncated']);
        self::assertSame(0, $out['summary']['trade_count']);
    }

    /**
     * @param PositionTradeAnalysisV2[] $rows
     */
    private function reader(array $rows): PositionTradeAnalysisReaderInterface
    {
        return new class($rows) implements PositionTradeAnalysisReaderInterface {
            /** @param PositionTradeAnalysisV2[] $rows */
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
                    static fn (PositionTradeAnalysisV2 $r): bool => $r->getSetId() === $setId
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
     * @param array<string,mixed> $overrides
     */
    private function row(array $overrides): PositionTradeAnalysisV2
    {
        static $autoId = 1;
        $defaults = [
            'entryEventId' => $autoId++,
            'symbol' => 'BTCUSDT',
            'entryTime' => new \DateTimeImmutable('2026-06-17T08:30:00+00:00'),
            'closeMatchStatus' => 'unmatched',
            'closeMatchedBy' => 'unmatched',
            'analysisStatus' => 'unmatched',
            'costCompleteness' => 'not_applicable',
        ];
        $data = array_merge($defaults, $overrides);

        $entity = (new \ReflectionClass(PositionTradeAnalysisV2::class))->newInstanceWithoutConstructor();
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
