<?php

declare(strict_types=1);

namespace App\Command\TradeQuality;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'stats:entry-zone',
    description: 'Calcule les stats de zone_dev_pct (moderate_gap) et propose un nouveau zone_max_dev_pct'
)]
final class EntryZoneStatsCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('timeframe', InputArgument::REQUIRED, 'Timeframe (ex: 1m, 15m)')
            ->addOption('days', null, InputOption::VALUE_OPTIONAL, 'Nombre de jours à analyser', '3')
            ->addOption('current-max-dev', null, InputOption::VALUE_REQUIRED, 'Valeur actuelle de zone_max_dev_pct (ex: 0.015)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tf = (string) $input->getArgument('timeframe');
        $days = (int) $input->getOption('days');
        $currentMaxDev = (float) $input->getOption('current-max-dev');

        $io->title(sprintf('Stats EntryZone pour timeframe %s (sur %d jours)', $tf, $days));

        $startTs = (new \DateTimeImmutable(sprintf('-%d days', $days)))->format('Y-m-d H:i:sP');

        $sql = <<<SQL
SELECT
    timeframe,
    total_moderate_gap,
    avg_dev,
    p50_dev,
    p80_dev,
    p90_dev,
    max_dev,
    suggested_zone_max_dev_pct,
    decision_note
FROM zone_stats_and_suggestion(:tf, :start_ts::timestamptz, :current_max_dev)
SQL;

        $row = $this->connection->fetchAssociative($sql, [
            'tf' => $tf,
            'start_ts' => $startTs,
            'current_max_dev' => $currentMaxDev,
        ]);

        if (!$row) {
            $io->warning('Aucune donnée moderate_gap pour ce timeframe et cette période.');
            return Command::SUCCESS;
        }

        $io->section('Stats brutes');
        $io->table(
            ['timeframe', 'total_moderate_gap', 'avg_dev', 'p50', 'p80', 'p90', 'max'],
            [[
                $row['timeframe'],
                $row['total_moderate_gap'],
                sprintf('%.6f', $row['avg_dev']),
                sprintf('%.6f', $row['p50_dev']),
                sprintf('%.6f', $row['p80_dev']),
                sprintf('%.6f', $row['p90_dev']),
                sprintf('%.6f', $row['max_dev']),
            ]]
        );

        $io->section('Suggestion');

        $io->writeln(sprintf(
            'zone_max_dev_pct actuel : <info>%.6f</info>',
            $currentMaxDev
        ));
        $io->writeln(sprintf(
            'zone_max_dev_pct suggéré : <comment>%.6f</comment>',
            $row['suggested_zone_max_dev_pct']
        ));
        $io->writeln(sprintf(
            'Décision : %s',
            $row['decision_note']
        ));

        $io->newLine();
        $io->section('Proposition YAML');

        $io->writeln('À copier dans ton fichier de config (exemple) :');
        $io->newLine();
        $io->writeln(sprintf(
            "entry_zone:\n  zone_max_dev_pct_%s: %.6f  # %s",
            $tf,
            $row['suggested_zone_max_dev_pct'],
            $row['decision_note']
        ));

        return Command::SUCCESS;
    }
}
