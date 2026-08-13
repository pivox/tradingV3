<?php

declare(strict_types=1);

namespace App\Command;

use App\TradingCore\Backtesting\CanonicalBacktestRuleEvaluator;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorProjectorInterface;
use App\TradingCore\Backtesting\Json\StrictJsonObjectDecoder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:backtest:indicators:project')]
final class BacktestProjectCanonicalIndicatorsCommand extends Command
{
    public function __construct(
        private readonly CanonicalIndicatorProjectorInterface $projector,
        private readonly StrictJsonObjectDecoder $decoder,
        private readonly ?\Closure $stdinReader = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $payload = $this->readInput();
        } catch (\Throwable) {
            return $this->invalid($output, 'input_read_failed');
        }

        try {
            $request = $this->decoder->decode($payload);
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($output, $exception->getMessage());
        }

        try {
            $projection = $this->projector->project($request);
            $encoded = CanonicalBacktestRuleEvaluator::canonicalJson($projection->toArray());
        } catch (\Throwable $exception) {
            $reason = $exception->getMessage();
            if (preg_match('/\Acanonical_indicator_[a-z0-9_:.\-]{1,160}\z/D', $reason) !== 1) {
                $reason = 'projection_failed';
            }

            return $this->invalid($output, $reason);
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
            throw new \RuntimeException('Unable to open stdin.');
        }
        try {
            $payload = stream_get_contents($stream, StrictJsonObjectDecoder::MAX_INPUT_BYTES + 1);
        } finally {
            fclose($stream);
        }
        if (!\is_string($payload)) {
            throw new \RuntimeException('Unable to read stdin.');
        }

        return $payload;
    }

    private function invalid(OutputInterface $output, string $reason): int
    {
        $error = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $error->writeln('canonical_indicator_projection_command_invalid:' . $reason);

        return Command::INVALID;
    }
}
