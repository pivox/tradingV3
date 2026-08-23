<?php

declare(strict_types=1);

namespace App\Command;

use App\Trading\Paper\Certification\PaperCertificationMatrixBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:paper-market:certification-matrix',
    description: 'Export the exact expected #132 Paper certification cells from versioned contracts.',
)]
final class PaperCertificationMatrixCommand extends Command
{
    private const MAX_SPEC_BYTES = 1_048_576;

    public function __construct(
        private readonly BoundedDuplicateAwareJsonDecoder $decoder,
        private readonly PaperCertificationMatrixBuilder $builder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('spec', null, InputOption::VALUE_REQUIRED, 'Versioned certification matrix input JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $path = $input->getOption('spec');
            if (!is_string($path) || trim($path) === '') {
                throw new \InvalidArgumentException('--spec is required');
            }
            $size = @filesize($path);
            if (!is_file($path) || !is_readable($path) || !is_int($size) || $size > self::MAX_SPEC_BYTES) {
                throw new \RuntimeException('paper_certification_spec_unreadable');
            }
            $json = @file_get_contents($path);
            if (!is_string($json)) {
                throw new \RuntimeException('paper_certification_spec_unreadable');
            }
            $matrix = $this->builder->build($this->decoder->decode($json));
            $output->writeln(json_encode(
                $matrix,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return Command::SUCCESS;
        } catch (\InvalidArgumentException|\RuntimeException|\JsonException $failure) {
            $output->writeln('<error>' . $failure->getMessage() . '</error>');

            return Command::INVALID;
        }
    }
}
