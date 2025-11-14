<?php

declare(strict_types=1);

namespace App\Command\Investigate;

use App\Service\NoOrderInvestigationResult;
use App\Service\NoOrderInvestigationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'investigate:no-order',
    description: 'Diagnostique pourquoi un ou plusieurs symboles n\'ont pas abouti à un ordre exécuté (soumission, skip, MTF non READY).',
    aliases: ['investigate:no', 'ino']
)]
class InvestigateNoOrderCommand extends Command
{
    public function __construct(
        private readonly NoOrderInvestigationService $investigationService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('symbols', 's', InputOption::VALUE_REQUIRED, 'Liste de symboles séparés par des virgules (ex: GLMUSDT,LTCUSDT)')
            ->addOption('since-hours', null, InputOption::VALUE_REQUIRED, 'Fenêtre de recherche dans les logs (heures)', '24')
            ->addOption('since-minutes', null, InputOption::VALUE_REQUIRED, 'Fenêtre de recherche dans les logs (minutes). Si défini, prioritaire sur --since-hours', null)
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Format de sortie (table|json)', 'table')
            ->addOption('max-log-files', null, InputOption::VALUE_REQUIRED, 'Nombre max de fichiers positions-*.log à inspecter (du plus récent au plus ancien)', '2');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $symbolsOpt = (string)($input->getOption('symbols') ?? '');
        $sinceHours = max(1, (int)$input->getOption('since-hours'));
        $sinceMinutesOpt = $input->getOption('since-minutes');
        $format = (string)($input->getOption('format') ?? 'table');
        $maxLogFiles = max(1, (int)$input->getOption('max-log-files'));

        if (!in_array($format, ['table', 'json'], true)) {
            $io->error("Format invalide. Utilisez 'table' ou 'json'.");
            return Command::FAILURE;
        }

        if ($symbolsOpt === '') {
            $io->error("Veuillez fournir au moins un symbole via --symbols=SYM1,SYM2");
            return Command::FAILURE;
        }

        $symbols = array_values(array_filter(array_map(static fn(string $s) => strtoupper(trim($s)), explode(',', $symbolsOpt))));
        if ($symbols === []) {
            $io->error('Liste de symboles vide après parsing.');
            return Command::FAILURE;
        }

        if ($sinceMinutesOpt !== null && $sinceMinutesOpt !== '') {
            $sinceMinutes = max(1, (int)$sinceMinutesOpt);
            $since = (new \DateTimeImmutable(sprintf('-%d minutes', $sinceMinutes)))->setTimezone(new \DateTimeZone('UTC'));
        } else {
            $since = (new \DateTimeImmutable(sprintf('-%d hours', $sinceHours)))->setTimezone(new \DateTimeZone('UTC'));
        }

        $investigationResults = $this->investigationService->investigate($symbols, $since, $maxLogFiles);
        $results = array_map(static fn(NoOrderInvestigationResult $r) => $r->toArray(), $investigationResults);

        if ($format === 'json') {
            $io->writeln(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        // Affichage table avec détails zone et proposition en cas de skipped_out_of_zone
        $rows = [];
        foreach ($results as $symbol => $r) {
            $details = is_array($r['details'] ?? null) ? $r['details'] : [];
            $reason  = (string)($r['reason'] ?? ($details['cause'] ?? ''));

            $zoneDev = isset($details['zone_dev_pct']) && is_numeric($details['zone_dev_pct']) ? (float)$details['zone_dev_pct'] : null;
            $zoneMax = isset($details['zone_max_dev_pct']) && is_numeric($details['zone_max_dev_pct']) ? (float)$details['zone_max_dev_pct'] : null;

            $proposal = '';
            if ($r['status'] === 'skipped' && ($reason === 'skipped_out_of_zone' || $reason === 'zone_far_from_market')) {
                $proposal = $this->buildZoneSkipProposal($zoneDev, $zoneMax);
            }

            $rows[] = [
                $symbol,
                $r['status'] ?? 'unknown',
                $reason,
                $details['order_id'] ?? '',
                $details['decision_key'] ?? '',
                $details['timeframe'] ?? '',
                $details['kline_time'] ?? '',
                $this->fmtPct($zoneDev),
                $this->fmtPct($zoneMax),
                $proposal,
            ];
        }
        $io->table(
            ['Symbol', 'Status', 'Reason/Cause', 'Order ID', 'Decision Key', 'TF', 'Kline Time', 'Zone Dev %', 'Max Dev %', 'Proposal'],
            $rows
        );

        $io->success('Investigation terminée');
        return Command::SUCCESS;
    }

    private function fmtPct(?float $v): string
    {
        if ($v === null || !is_finite($v)) { return ''; }
        return number_format($v * 100.0, 2) . '%';
    }

    private function buildZoneSkipProposal(?float $zoneDev, ?float $zoneMax): string
    {
        if ($zoneDev === null || $zoneMax === null || $zoneDev <= 0.0 || $zoneMax <= 0.0) {
            return 'Check logs: zone_dev_pct/max missing';
        }

        $ratio = $zoneDev / max(1e-9, $zoneMax);

        // Close to threshold: small relaxation or market-entry with cap
        if ($ratio <= 1.05) {
            $needed = max($zoneDev, $zoneMax * 1.02); // minimal safe bump
            return sprintf('Near threshold: raise zone_max_deviation_pct to ~%.2f%% or allow market-entry with slippage cap (e.g. 20–25 bps); also consider 5m fallback.', $needed * 100.0);
        }

        // Moderately outside: consider fallback TF or temporary relaxation
        if ($ratio <= 1.5) {
            $needed = $zoneDev;
            return sprintf('Moderate gap: test 5m execution fallback; if acceptable, temporarily set zone_max_deviation_pct to ~%.2f%%; else wait for price to re-enter zone.', $needed * 100.0);
        }

        // Far outside: do nothing risky
        return 'Far outside zone: avoid relaxing; wait for price or review zone width/logic.';
    }
}
