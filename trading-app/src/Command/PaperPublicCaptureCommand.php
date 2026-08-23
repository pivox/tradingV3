<?php

declare(strict_types=1);

namespace App\Command;

use App\Trading\Paper\Capture\PaperPublicCaptureRunner;
use App\Trading\Paper\MarketData\CanonicalJson;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:paper-market:public-capture',
    description: 'Capture one credential-free public mainnet dataset for Paper replay.',
)]
final class PaperPublicCaptureCommand extends Command
{
    public function __construct(private readonly PaperPublicCaptureRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('venue', null, InputOption::VALUE_REQUIRED, 'Public venue: okx or hyperliquid')
            ->addOption('dataset-id', null, InputOption::VALUE_REQUIRED, 'Canonical dataset ID ending in -mainnet')
            ->addOption('duration-sec', null, InputOption::VALUE_REQUIRED, 'Capture duration from 300 to 604800 seconds');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $duration = $this->requiredOption($input, 'duration-sec');
            if (preg_match('/\A[1-9][0-9]{2,5}\z/D', $duration) !== 1) {
                throw new \InvalidArgumentException('paper_public_capture_duration_invalid');
            }
            $result = $this->runner->run(
                $this->requiredOption($input, 'venue'),
                $this->requiredOption($input, 'dataset-id'),
                (int) $duration,
            );
            $payload = $result->toArray();
            $status = Command::SUCCESS;
        } catch (\Throwable) {
            $payload = [
                'schema_version' => 'paper-public-capture-result-v1',
                'ok' => false,
                'blocker' => 'paper_public_capture_failed',
            ];
            $status = Command::FAILURE;
        }

        $output->writeln(CanonicalJson::encode($payload));

        return $status;
    }

    private function requiredOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException('paper_public_capture_option_invalid');
        }

        return trim($value);
    }
}
