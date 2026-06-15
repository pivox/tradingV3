<?php

declare(strict_types=1);

namespace App\Tests\Application\Runner;

use App\Application\Runner\RunResultAssembler;
use App\MtfRunner\Application\Result\MtfRunResultEnricher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RunResultAssembler::class)]
final class RunResultAssemblerTest extends TestCase
{
    public function testAssemblesFinalResponseWithExistingShape(): void
    {
        $assembler = new RunResultAssembler(new MtfRunResultEnricher());

        $runnerResult = [
            'summary' => ['status' => 'completed'],
            'results' => [
                'BTCUSDT' => ['status' => 'SUCCESS', 'execution_tf' => '1m', 'signal_side' => 'LONG'],
                'ETHUSDT' => ['status' => 'INVALID', 'failed_timeframe' => '5m'],
            ],
            'errors' => ['worker warning'],
        ];
        $results = $runnerResult['results'];
        $enriched = $assembler->enrich($results);
        $performanceReport = ['runner' => ['mtf_execution' => 1.25]];

        self::assertSame(
            [
                'summary' => ['status' => 'completed'],
                'results' => $results,
                'errors' => ['worker warning'],
                'summary_by_tf' => [
                    '1m' => ['BTCUSDT'],
                    '5m' => ['ETHUSDT'],
                    '15m' => [],
                    '1h' => [],
                    '4h' => [],
                ],
                'rejected_by' => ['ETHUSDT'],
                'last_validated' => [
                    ['symbol' => 'BTCUSDT', 'side' => 'LONG', 'timeframe' => 'READY'],
                ],
                'orders_placed' => [
                    'count' => ['total' => 0, 'submitted' => 0, 'pending' => 0, 'protection_failed' => 0, 'simulated' => 0],
                    'orders' => [],
                ],
                'performance' => $performanceReport,
            ],
            $assembler->assemble($runnerResult, $results, $enriched, $performanceReport),
        );
    }
}
