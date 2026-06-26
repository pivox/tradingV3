<?php

declare(strict_types=1);

namespace App\Command;

use App\Trading\Backfill\BackfillDivergenceCriteria;
use App\Trading\Backfill\PositionTradeAnalysisBackfillDivergenceExporter;
use App\Trading\Backfill\PositionTradeAnalysisBackfillDivergenceReportServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:position-trade-analysis:backfill-divergence',
    description: 'Dry-run read-only audit comparing position_trade_analysis v1 and v2 for #190 backfill readiness.'
)]
final class PositionTradeAnalysisBackfillDivergenceCommand extends Command
{
    private const MAX_LIMIT = 5000;
    private const MAX_BATCH_SIZE = 1000;

    public function __construct(
        private readonly PositionTradeAnalysisBackfillDivergenceReportServiceInterface $reportService,
        private readonly PositionTradeAnalysisBackfillDivergenceExporter $exporter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Inclusive period start, UTC-compatible datetime.')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Inclusive period end, UTC-compatible datetime.')
            ->addOption('mtf-profile', null, InputOption::VALUE_REQUIRED, 'MTF profile filter using v2 lineage only.')
            ->addOption('exchange', null, InputOption::VALUE_REQUIRED, 'Exchange filter using v2 venue context only.')
            ->addOption('market-type', null, InputOption::VALUE_REQUIRED, 'Market type filter using v2 venue context only.')
            ->addOption('symbol', null, InputOption::VALUE_REQUIRED, 'Symbol filter. Used only as a filter, never as a matching key.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, sprintf('Maximum rows to report, 1-%d.', self::MAX_LIMIT), '500')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, sprintf('Read batch hint, 1-%d.', self::MAX_BATCH_SIZE), '100')
            ->addOption('resume-cursor', null, InputOption::VALUE_REQUIRED, 'Exclusive entry_event_id cursor for deterministic resume.')
            ->addOption('dry-run', null, InputOption::VALUE_OPTIONAL, 'Must stay true in this PR.', '1')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Explicit apply mode placeholder; disabled in this PR.')
            ->addOption('export-json', null, InputOption::VALUE_REQUIRED, 'Write the full report as JSON.')
            ->addOption('export-csv', null, InputOption::VALUE_REQUIRED, 'Write report rows as CSV.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ((bool) $input->getOption('apply')) {
            $io->error('Apply mode is intentionally disabled in this PR. No writes were performed.');

            return Command::FAILURE;
        }

        $dryRun = filter_var($input->getOption('dry-run'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($dryRun !== true) {
            $io->error('Only dry-run mode is allowed in this PR. Use --dry-run=1 and do not use --apply.');

            return Command::FAILURE;
        }

        $limit = $this->intOption($input, 'limit');
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            $io->error(sprintf('Limit must be between 1 and %d.', self::MAX_LIMIT));

            return Command::FAILURE;
        }

        $batchSize = $this->intOption($input, 'batch-size');
        if ($batchSize < 1 || $batchSize > self::MAX_BATCH_SIZE) {
            $io->error(sprintf('Batch size must be between 1 and %d.', self::MAX_BATCH_SIZE));

            return Command::FAILURE;
        }

        try {
            $criteria = new BackfillDivergenceCriteria(
                from: $this->dateOption($input, 'from'),
                to: $this->dateOption($input, 'to'),
                profile: $this->stringOption($input, 'mtf-profile'),
                exchange: $this->stringOption($input, 'exchange'),
                marketType: $this->stringOption($input, 'market-type'),
                symbol: $this->normalizeSymbol($this->stringOption($input, 'symbol')),
                limit: $limit,
                batchSize: $batchSize,
                resumeCursor: $this->nullableIntOption($input, 'resume-cursor'),
                dryRun: true,
            );
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $report = $this->reportService->buildReport($criteria);

        $jsonPath = $this->stringOption($input, 'export-json');
        if ($jsonPath !== null) {
            $this->exporter->exportJson($report, $jsonPath);
        }

        $csvPath = $this->stringOption($input, 'export-csv');
        if ($csvPath !== null) {
            $this->exporter->exportCsv($report, $csvPath);
        }

        $io->title('Position trade analysis backfill divergence dry-run');
        $io->text('Dry-run: yes');
        $io->text('Read-only: yes');
        $io->text('Comparison key: entry_event_id');
        $io->text('No symbol-only or time-window matching is used.');
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $pagination = is_array($report['pagination'] ?? null) ? $report['pagination'] : [];
        $proposal = is_array($report['proposal'] ?? null) ? $report['proposal'] : [];

        $io->table(['Metric', 'Value'], [
            ['v1 rows', (string) ($summary['v1_rows'] ?? 0)],
            ['v2 rows', (string) ($summary['v2_rows'] ?? 0)],
            ['common rows', (string) ($summary['common_rows'] ?? 0)],
            ['certified rows', (string) ($summary['certified_rows'] ?? 0)],
            ['excluded rows', (string) ($summary['excluded_rows'] ?? 0)],
            ['divergent rows', (string) ($summary['divergent_rows'] ?? 0)],
            ['truncated', ($pagination['truncated'] ?? false) ? 'yes' : 'no'],
            ['next cursor', (string) ($pagination['next_cursor'] ?? '')],
            ['ready for backfill', ($proposal['ready_for_backfill'] ?? false) ? 'yes' : 'no'],
            ['blocking reason', (string) ($proposal['blocking_reason'] ?? '')],
        ]);

        if ($jsonPath !== null) {
            $io->text(sprintf('JSON export: %s', $jsonPath));
        }
        if ($csvPath !== null) {
            $io->text(sprintf('CSV export: %s', $csvPath));
        }

        return Command::SUCCESS;
    }

    private function intOption(InputInterface $input, string $name): int
    {
        $value = $input->getOption($name);
        if (!is_scalar($value) || !is_numeric((string) $value)) {
            return 0;
        }

        return (int) $value;
    }

    private function nullableIntOption(InputInterface $input, string $name): ?int
    {
        $value = $input->getOption($name);
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_scalar($value) || !is_numeric((string) $value)) {
            throw new \InvalidArgumentException(sprintf('Option --%s must be an integer.', $name));
        }

        return (int) $value;
    }

    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);
        if ($value === null || $value === '') {
            return null;
        }

        return trim((string) $value);
    }

    private function normalizeSymbol(?string $symbol): ?string
    {
        return $symbol === null ? null : strtoupper($symbol);
    }

    private function dateOption(InputInterface $input, string $name): ?\DateTimeImmutable
    {
        $value = $this->stringOption($input, $name);
        if ($value === null) {
            return null;
        }

        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            $errors = \DateTimeImmutable::getLastErrors();
            if (is_array($errors) && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0)) {
                throw new \InvalidArgumentException(sprintf('Option --%s must be a valid datetime.', $name));
            }
            if ($name === 'to' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                return $date->setTime(23, 59, 59, 999999);
            }

            return $date;
        } catch (\Throwable $e) {
            if ($e instanceof \InvalidArgumentException) {
                throw $e;
            }

            throw new \InvalidArgumentException(sprintf('Option --%s must be a valid datetime.', $name));
        }
    }
}
