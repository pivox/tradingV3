<?php

declare(strict_types=1);

namespace App\Command;

use App\Trading\Paper\Capture\PaperPublicCaptureSupervisor;
use App\Trading\Paper\MarketData\CanonicalJson;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:paper-market:public-capture-supervise',
    description: 'Run bounded fresh public Paper capture attempts until one completes.',
)]
final class PaperPublicCaptureSupervisorCommand extends Command
{
    public function __construct(private readonly PaperPublicCaptureSupervisor $supervisor)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('venue', null, InputOption::VALUE_REQUIRED, 'Public venue: okx or hyperliquid')
            ->addOption('dataset-prefix', null, InputOption::VALUE_REQUIRED, 'Dataset prefix without attempt or network suffix')
            ->addOption('duration-sec', null, InputOption::VALUE_REQUIRED, 'Capture duration from 300 to 604800 seconds')
            ->addOption('attempts', null, InputOption::VALUE_REQUIRED, 'Maximum fresh attempts from 1 to 99');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $duration = $this->positiveIntegerOption($input, 'duration-sec');
            $attempts = $this->positiveIntegerOption($input, 'attempts');
            $result = $this->supervisor->run(
                $this->requiredOption($input, 'venue'),
                $this->requiredOption($input, 'dataset-prefix'),
                $duration,
                $attempts,
            );
            $payload = $result->toArray();
            $status = $result->ok ? Command::SUCCESS : Command::FAILURE;
        } catch (\Throwable) {
            $payload = [
                'schema_version' => 'paper-public-capture-supervision-result-v1',
                'ok' => false,
                'blocker' => 'paper_public_capture_supervision_failed',
            ];
            $status = Command::FAILURE;
        }

        $output->writeln(CanonicalJson::encode($payload));

        return $status;
    }

    private function positiveIntegerOption(InputInterface $input, string $name): int
    {
        $value = $this->requiredOption($input, $name);
        if (preg_match('/\A[1-9][0-9]{0,5}\z/D', $value) !== 1) {
            throw new \InvalidArgumentException('paper_public_capture_option_invalid');
        }

        return (int) $value;
    }

    private function requiredOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);
        if (!\is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException('paper_public_capture_option_invalid');
        }

        return trim($value);
    }
}
