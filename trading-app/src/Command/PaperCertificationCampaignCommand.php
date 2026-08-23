<?php

declare(strict_types=1);

namespace App\Command;

use App\Trading\Paper\Certification\Campaign\PaperCertificationCampaignRunner;
use App\Trading\Paper\Certification\PaperCertificationMatrixBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:paper-market:certification-campaign',
    description: 'Run every exact #132 matrix cell in isolated Fake/Paper processes without claiming certification.',
)]
final class PaperCertificationCampaignCommand extends Command
{
    private const MAX_SPEC_BYTES = 1_048_576;

    public function __construct(
        private readonly BoundedDuplicateAwareJsonDecoder $decoder,
        private readonly PaperCertificationMatrixBuilder $matrixBuilder,
        private readonly PaperCertificationCampaignRunner $campaign,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('spec', null, InputOption::VALUE_REQUIRED, 'Versioned certification matrix input JSON')
            ->addOption('configuration', null, InputOption::VALUE_REQUIRED, 'Absolute private Paper configuration JSON')
            ->addOption(
                'dataset',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Exact scope mapping network/venue=/absolute/dataset (repeat once per scope)',
            )
            ->addOption('campaign-id', null, InputOption::VALUE_REQUIRED, 'Stable lowercase campaign identifier')
            ->addOption('state', null, InputOption::VALUE_REQUIRED, 'Absolute private campaign state JSON')
            ->addOption('cell-timeout-sec', null, InputOption::VALUE_REQUIRED, 'Timeout for each child process', '3600');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $matrix = $this->matrix($this->requiredOption($input, 'spec'));
            $timeout = filter_var($this->requiredOption($input, 'cell-timeout-sec'), FILTER_VALIDATE_INT);
            if (!is_int($timeout)) {
                throw new \InvalidArgumentException('paper_campaign_timeout_invalid');
            }
            $state = $this->campaign->run(
                $matrix,
                $this->requiredOption($input, 'campaign-id'),
                $this->requiredOption($input, 'configuration'),
                $this->datasetMappings($input),
                $this->requiredOption($input, 'state'),
                $timeout,
            );
            $status = $state['status'] === 'completed' ? Command::SUCCESS : Command::FAILURE;
            $payload = $state;
        } catch (\InvalidArgumentException|\LogicException|\RuntimeException|\JsonException $failure) {
            $payload = [
                'schema_version' => PaperCertificationCampaignRunner::STATE_SCHEMA,
                'status' => 'failed',
                'blocker' => $failure->getMessage(),
            ];
            $status = Command::INVALID;
        }
        $output->writeln(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $status;
    }

    /** @return array<string, mixed> */
    private function matrix(string $path): array
    {
        $size = @filesize($path);
        if (!is_file($path) || !is_readable($path) || !is_int($size) || $size < 2 || $size > self::MAX_SPEC_BYTES) {
            throw new \RuntimeException('paper_certification_spec_unreadable');
        }
        $contents = @file_get_contents($path);
        if (!is_string($contents) || strlen($contents) !== $size) {
            throw new \RuntimeException('paper_certification_spec_unreadable');
        }

        return $this->matrixBuilder->build($this->decoder->decode($contents));
    }

    /** @return array<string, string> */
    private function datasetMappings(InputInterface $input): array
    {
        $values = $input->getOption('dataset');
        if (!is_array($values) || $values === []) {
            throw new \InvalidArgumentException('paper_campaign_dataset_mapping_invalid');
        }
        $mappings = [];
        foreach ($values as $value) {
            if (!is_string($value) || !str_contains($value, '=')) {
                throw new \InvalidArgumentException('paper_campaign_dataset_mapping_invalid');
            }
            [$scope, $path] = explode('=', $value, 2);
            if (preg_match('/\A(?:mainnet|testnet)\/(?:okx|hyperliquid)\z/D', $scope) !== 1
                || !str_starts_with($path, DIRECTORY_SEPARATOR)
                || isset($mappings[$scope])
            ) {
                throw new \InvalidArgumentException('paper_campaign_dataset_mapping_invalid');
            }
            $mappings[$scope] = $path;
        }

        return $mappings;
    }

    private function requiredOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException('--' . $name . ' is required');
        }

        return trim($value);
    }
}
