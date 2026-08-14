<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\BacktestSettlePartialFillCostCommand;
use App\TradingCore\Backtesting\Execution\CanonicalPartialFillCostSettlement;
use App\TradingCore\Backtesting\Json\StrictJsonObjectDecoder;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuildRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuilder;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanValidator;
use App\Tests\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanPipelineFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(BacktestSettlePartialFillCostCommand::class)]
final class BacktestSettlePartialFillCostCommandTest extends TestCase
{
    public function testItEmitsOneCanonicalPartialSettlement(): void
    {
        $plan = $this->plan();
        $tester = $this->runCommand(json_encode(
            $this->request($plan),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES,
        ));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertSame('', $tester->getErrorOutput());
        self::assertStringContainsString('"schema_version":"canonical-partial-fill-cost-result.v1"', $tester->getDisplay());
        self::assertStringContainsString('"filled_quantity_base":"1"', $tester->getDisplay());
        self::assertSame(1, substr_count($tester->getDisplay(), "\n"));
    }

    public function testMalformedDuplicateAndTamperedInputFailOnStderrOnly(): void
    {
        $plan = $this->plan();
        $tampered = $this->request($plan);
        $tampered['plan']['entryFee'] += 1;
        foreach ([
            '{"a":1,"a":2}',
            '{}',
            json_encode($tampered, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
        ] as $payload) {
            $tester = $this->runCommand($payload);
            self::assertSame(Command::INVALID, $tester->getStatusCode());
            self::assertSame('', $tester->getDisplay());
            self::assertStringStartsWith(
                'canonical_partial_fill_cost_command_invalid:',
                $tester->getErrorOutput(),
            );
        }
    }

    private function runCommand(string $payload): CommandTester
    {
        $tester = new CommandTester(new BacktestSettlePartialFillCostCommand(
            new CanonicalPartialFillCostSettlement(),
            new StrictJsonObjectDecoder(),
            static fn (): string => $payload,
        ));
        $tester->execute([], ['capture_stderr_separately' => true]);

        return $tester;
    }

    private function plan(): CanonicalOrderPlan
    {
        $clock = new MockClock('2026-08-10T12:00:00+00:00');

        return (new CanonicalOrderPlanBuilder($clock, new CanonicalOrderPlanValidator($clock)))
            ->build(new CanonicalOrderPlanBuildRequest(...CanonicalOrderPlanPipelineFixture::accepted()));
    }

    /** @return array<string, mixed> */
    private function request(CanonicalOrderPlan $plan): array
    {
        return [
            'schema_version' => 'canonical-partial-fill-cost-request.v1',
            'dataset_id' => 'backtest-dataset-' . str_repeat('a', 64),
            'dataset_checksum' => 'sha256:' . str_repeat('a', 64),
            'plan' => $plan->toArray(),
            'maker_fill_result_hash' => 'sha256:' . str_repeat('b', 64),
            'maker_fill_trace_hash' => 'sha256:' . str_repeat('c', 64),
            'filled_quantity_base' => '1',
            'terminal_kind' => 'target_filled',
            'target_id' => $plan->targets[0]->id,
        ];
    }
}

