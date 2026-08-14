<?php

declare(strict_types=1);

namespace App\Command;

use App\TradingCore\Backtesting\Execution\CanonicalPartialFillCostSettlement;
use App\TradingCore\Backtesting\Json\StrictJsonObjectDecoder;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanDecimal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:backtest:partial-fill-cost:settle')]
final class BacktestSettlePartialFillCostCommand extends Command
{
    private const MAX_STRUCTURE_TOKENS = 4096;

    public function __construct(
        private readonly CanonicalPartialFillCostSettlement $settlement,
        private readonly StrictJsonObjectDecoder $decoder,
        private readonly ?\Closure $stdinReader = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $request = $this->decoder->decode($this->readInput(), self::MAX_STRUCTURE_TOKENS);
            $encoded = CanonicalOrderPlanDecimal::encodeCanonicalJson(
                $this->settlement->settle($request),
                'canonical_partial_fill_cost_encoding_invalid',
            );
        } catch (\Throwable $exception) {
            $reason = $exception->getMessage();
            if (preg_match('/\A(?:canonical_(?:partial_fill_cost|order_plan)_[a-z0-9_]+|[a-z_]+)\z/D', $reason) !== 1) {
                $reason = 'settlement_failed';
            }
            $error = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $error->writeln('canonical_partial_fill_cost_command_invalid:' . $reason);

            return Command::INVALID;
        }
        $output->write($encoded . "\n");

        return Command::SUCCESS;
    }

    private function readInput(): string
    {
        if ($this->stdinReader !== null) {
            return ($this->stdinReader)();
        }
        $stream = fopen('php://stdin', 'rb');
        if (!\is_resource($stream)) {
            throw new \RuntimeException('input_read_failed');
        }
        try {
            $payload = stream_get_contents($stream, StrictJsonObjectDecoder::MAX_INPUT_BYTES + 1);
        } finally {
            fclose($stream);
        }
        if (!\is_string($payload)) {
            throw new \RuntimeException('input_read_failed');
        }

        return $payload;
    }
}

