<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\BacktestSettleHistoricalFundingCommand;
use App\TradingCore\Backtesting\Funding\CanonicalHistoricalFundingSettlement;
use App\TradingCore\Backtesting\Json\StrictJsonObjectDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(BacktestSettleHistoricalFundingCommand::class)]
final class BacktestSettleHistoricalFundingCommandTest extends TestCase
{
    public function testItEmitsOneCanonicalSettlement(): void
    {
        $payload = json_encode($this->request(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $tester = $this->runCommand($payload);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('', $tester->getErrorOutput());
        self::assertStringContainsString('"funding_cashflow_quote":"-0.01"', $tester->getDisplay());
        self::assertSame(1, substr_count($tester->getDisplay(), "\n"));
    }

    public function testMalformedOrInvalidInputFailsOnStderrOnly(): void
    {
        foreach (['{"a":1,"a":2}', '{}'] as $payload) {
            $tester = $this->runCommand($payload);
            self::assertSame(Command::INVALID, $tester->getStatusCode());
            self::assertSame('', $tester->getDisplay());
            self::assertStringStartsWith('canonical_historical_funding_command_invalid:', $tester->getErrorOutput());
        }
    }

    public function testItAcceptsARequestBeyondTheSharedDecoderDefaultTokenLimit(): void
    {
        $request = $this->request();
        $start = new \DateTimeImmutable('2026-08-10T00:00:00.000000Z');
        $request['coverage_start'] = $start->format('Y-m-d\TH:i:s.u\Z');
        $request['entry_at'] = $request['coverage_start'];
        $request['records'] = [];
        for ($index = 1; $index <= 5000; ++$index) {
            $at = $start->modify('+' . $index . ' seconds')->format('Y-m-d\TH:i:s.u\Z');
            $request['records'][] = [
                'source_record_id' => 'funding-' . $index,
                'funding_at' => $at,
                'available_at' => $at,
                'funding_rate' => '0.0001',
                'mark_price' => '100',
                'interval_seconds' => 1,
            ];
        }
        $request['coverage_end'] = $request['records'][4999]['funding_at'];
        $request['exit_at'] = $request['coverage_end'];

        $tester = $this->runCommand(json_encode($request, JSON_THROW_ON_ERROR));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertStringContainsString('"applied_record_count":5000', $tester->getDisplay());
    }

    private function runCommand(string $payload): CommandTester
    {
        $tester = new CommandTester(new BacktestSettleHistoricalFundingCommand(
            new CanonicalHistoricalFundingSettlement(),
            new StrictJsonObjectDecoder(),
            static fn (): string => $payload,
        ));
        $tester->execute([], ['capture_stderr_separately' => true]);
        return $tester;
    }

    /** @return array<string, mixed> */
    private function request(): array
    {
        return [
            'schema_version' => 'canonical-historical-funding-request.v1',
            'dataset_id' => 'backtest-dataset-' . str_repeat('a', 64),
            'dataset_checksum' => 'sha256:' . str_repeat('a', 64),
            'schedule_checksum' => 'sha256:' . str_repeat('b', 64),
            'plan_hash' => 'sha256:' . str_repeat('c', 64),
            'config_hash' => 'sha256:' . str_repeat('d', 64),
            'cost_input_hash' => 'sha256:' . str_repeat('e', 64),
            'symbol' => 'BTCUSDT', 'side' => 'long', 'quantity' => '1', 'contract_size' => '1',
            'entry_at' => '2026-08-10T07:00:00.000000Z', 'exit_at' => '2026-08-10T08:00:00.000000Z',
            'coverage_start' => '2026-08-10T00:00:00.000000Z', 'coverage_end' => '2026-08-10T08:00:00.000000Z',
            'records' => [[
                'source_record_id' => 'funding-1', 'funding_at' => '2026-08-10T08:00:00.000000Z',
                'available_at' => '2026-08-10T08:00:00.000000Z', 'funding_rate' => '0.0001',
                'mark_price' => '100', 'interval_seconds' => 28800,
            ]],
        ];
    }
}
