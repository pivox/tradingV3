<?php

declare(strict_types=1);

namespace App\Command\Audit;

use App\Repository\MtfAuditRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'mtf:health-check',
    description: 'Compare la santé des indicateurs MTF sur une période donnée (défaut: 24h)'
)]
class MtfHealthCheckCommand extends Command
{
    public function __construct(
        private readonly MtfAuditRepository $mtfAuditRepo,
        private readonly Connection $conn
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('period', 'p', InputOption::VALUE_OPTIONAL, 'Période d\'analyse (ex: 24h, 7d, 1w)', '24h')
            ->addOption('symbols', null, InputOption::VALUE_OPTIONAL, 'Liste de symboles séparés par des virgules')
            ->addOption('timeframes', 't', InputOption::VALUE_OPTIONAL, 'Liste de TF séparés par des virgules')
            ->addOption('format', 'f', InputOption::VALUE_OPTIONAL, 'Format de sortie: table|json|csv', 'table')
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Chemin fichier de sortie (pour csv/json)');

        $this->setHelp(<<<'HELP'
Cette commande analyse la santé globale du système MTF sur une période donnée.

Métriques fournies par timeframe :
  - Taux de succès (%)
  - Nombre total de validations (succès + échecs)
  - Nombre de symboles affectés
  - Statut de santé (HEALTHY / WARNING / CRITICAL)

Exemples :

# Santé sur les dernières 24h (défaut)
bin/console mtf:health-check

# Santé sur la dernière semaine
bin/console mtf:health-check --period=7d

# Santé pour des timeframes spécifiques
bin/console mtf:health-check -t 1h,4h --period=48h

# Export JSON
bin/console mtf:health-check --format=json --output=/tmp/health.json

HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Parse des options
        $periodStr = (string)$input->getOption('period');
        $format    = strtolower((string)$input->getOption('format'));
        $outputFile= $input->getOption('output');
        $symbols   = $this->parseCSV($input->getOption('symbols'));
        $timeframes= $this->parseCSV($input->getOption('timeframes'));

        // Conversion période -> DateTimeImmutable
        $since = $this->parsePeriod($periodStr);
        if (!$since) {
            $io->error("Format de période invalide: $periodStr. Exemples: 24h, 7d, 1w");
            return Command::INVALID;
        }

        $io->title("MTF Health Check — Analyse depuis " . $since->format('Y-m-d H:i:s T'));

        // 1. Métriques MTF (succès/échecs par timeframe)
        $mtfMetrics = $this->mtfAuditRepo->healthMetrics($since, $symbols, $timeframes);

        // 2. Métriques additionnelles (klines obsolètes, snapshots récents)
        $klinesHealth = $this->checkKlinesHealth();
        $snapshotsHealth = $this->checkSnapshotsHealth();

        // Construire le tableau de santé global
        $healthReport = $this->buildHealthReport($mtfMetrics, $klinesHealth, $snapshotsHealth);

        // Sortie selon format
        if ($format === 'json') {
            return $this->outputJson($healthReport, $outputFile, $io);
        }

        if ($format === 'csv') {
            return $this->outputCsv($healthReport, $outputFile, $io);
        }

        // Format table (par défaut)
        return $this->outputTable($healthReport, $io, $periodStr);
    }

    /**
     * Parse une période comme "24h", "7d", "1w" en DateTimeImmutable.
     */
    private function parsePeriod(string $period): ?\DateTimeImmutable
    {
        $pattern = '/^(\d+)([hdw])$/i';
        if (!preg_match($pattern, $period, $matches)) {
            return null;
        }

        $value = (int)$matches[1];
        $unit  = strtolower($matches[2]);

        $interval = match ($unit) {
            'h' => "PT{$value}H",
            'd' => "P{$value}D",
            'w' => "P" . ($value * 7) . "D",
            default => null,
        };

        if (!$interval) {
            return null;
        }

        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            return $now->sub(new \DateInterval($interval));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Parse CSV "A,B,C" -> ["A","B","C"].
     */
    private function parseCSV(?string $csv): ?array
    {
        if (!$csv) {
            return null;
        }
        $arr = array_values(array_filter(array_map('trim', explode(',', $csv)), fn($v) => $v !== ''));
        return $arr ?: null;
    }

    /**
     * Vérifie la santé des klines (dernière mise à jour).
     */
    private function checkKlinesHealth(): array
    {
        $sql = <<<SQL
SELECT
  COUNT(*) AS total_klines,
  COUNT(*) FILTER (WHERE updated_at > NOW() - INTERVAL '10 minutes') AS recent_klines,
  MAX(updated_at) AS last_update
FROM klines
LIMIT 1
SQL;

        $result = $this->conn->executeQuery($sql)->fetchAssociative();
        return $result ?: ['total_klines' => 0, 'recent_klines' => 0, 'last_update' => null];
    }

    /**
     * Vérifie la santé des snapshots d'indicateurs.
     */
    private function checkSnapshotsHealth(): array
    {
        $sql = <<<SQL
SELECT
  COUNT(*) AS total_snapshots,
  COUNT(*) FILTER (WHERE updated_at > NOW() - INTERVAL '10 minutes') AS recent_snapshots,
  MAX(updated_at) AS last_update
FROM indicator_snapshots
LIMIT 1
SQL;

        $result = $this->conn->executeQuery($sql)->fetchAssociative();
        return $result ?: ['total_snapshots' => 0, 'recent_snapshots' => 0, 'last_update' => null];
    }

    /**
     * Construit le rapport de santé combinant toutes les métriques.
     */
    private function buildHealthReport(array $mtfMetrics, array $klinesHealth, array $snapshotsHealth): array
    {
        $report = [
            'mtf_by_timeframe' => [],
            'global_summary' => [],
            'klines' => $klinesHealth,
            'indicator_snapshots' => $snapshotsHealth,
        ];

        $totalSuccess = 0;
        $totalFailed  = 0;

        foreach ($mtfMetrics as $row) {
            $tf = $row['timeframe'] ?? 'unknown';
            $successCount = (int)($row['success_count'] ?? 0);
            $failedCount  = (int)($row['failed_count'] ?? 0);
            $successRate  = (float)($row['success_rate_pct'] ?? 0.0);

            $totalSuccess += $successCount;
            $totalFailed  += $failedCount;

            $status = $this->determineStatus($successRate);

            $report['mtf_by_timeframe'][] = [
                'timeframe'      => $tf,
                'success_count'  => $successCount,
                'failed_count'   => $failedCount,
                'success_rate'   => $successRate . '%',
                'symbols_count'  => $row['symbols_count'] ?? 0,
                'status'         => $status,
            ];
        }

        $globalTotal = $totalSuccess + $totalFailed;
        $globalRate = $globalTotal > 0 ? round(100.0 * $totalSuccess / $globalTotal, 2) : 0.0;
        $globalStatus = $this->determineStatus($globalRate);

        $report['global_summary'] = [
            'total_validations' => $globalTotal,
            'total_success'     => $totalSuccess,
            'total_failed'      => $totalFailed,
            'success_rate'      => $globalRate . '%',
            'status'            => $globalStatus,
        ];

        return $report;
    }

    /**
     * Détermine le statut de santé basé sur le taux de succès.
     */
    private function determineStatus(float $successRate): string
    {
        if ($successRate >= 80.0) {
            return 'HEALTHY';
        }
        if ($successRate >= 60.0) {
            return 'WARNING';
        }
        return 'CRITICAL';
    }

    /**
     * Sortie en format JSON.
     */
    private function outputJson(array $report, ?string $outputFile, SymfonyStyle $io): int
    {
        $payload = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($outputFile) {
            file_put_contents($outputFile, $payload . PHP_EOL);
            $io->success("Rapport JSON écrit dans: $outputFile");
        } else {
            $io->writeln($payload);
        }

        return Command::SUCCESS;
    }

    /**
     * Sortie en format CSV (aplatissement des données MTF).
     */
    private function outputCsv(array $report, ?string $outputFile, SymfonyStyle $io): int
    {
        $rows = $report['mtf_by_timeframe'] ?? [];
        if (empty($rows)) {
            $io->warning('Aucune donnée à exporter.');
            return Command::SUCCESS;
        }

        $csv = $this->arrayToCsv($rows);

        if ($outputFile) {
            file_put_contents($outputFile, $csv);
            $io->success("Rapport CSV écrit dans: $outputFile");
        } else {
            $io->writeln($csv);
        }

        return Command::SUCCESS;
    }

    /**
     * Sortie en format table (défaut).
     */
    private function outputTable(array $report, SymfonyStyle $io, string $period): int
    {
        $io->section('Résumé global (période: ' . $period . ')');

        $summary = $report['global_summary'];
        $io->table(
            ['Métrique', 'Valeur'],
            [
                ['Total validations', $summary['total_validations']],
                ['Succès', $summary['total_success']],
                ['Échecs', $summary['total_failed']],
                ['Taux de succès', $summary['success_rate']],
                ['Statut', $this->colorizeStatus($summary['status'])],
            ]
        );

        $io->section('Santé par Timeframe');

        $tfData = $report['mtf_by_timeframe'];
        if (empty($tfData)) {
            $io->warning('Aucune donnée MTF disponible pour cette période.');
        } else {
            $headers = ['Timeframe', 'Succès', 'Échecs', 'Taux (%)', 'Symboles', 'Statut'];
            $rows = array_map(fn($r) => [
                $r['timeframe'],
                $r['success_count'],
                $r['failed_count'],
                $r['success_rate'],
                $r['symbols_count'],
                $this->colorizeStatus($r['status']),
            ], $tfData);

            $io->table($headers, $rows);
        }

        $io->section('Données auxiliaires');

        $klines = $report['klines'];
        $snapshots = $report['indicator_snapshots'];

        $io->table(
            ['Métriques Klines', 'Valeur'],
            [
                ['Total klines', $klines['total_klines']],
                ['Klines récentes (<10min)', $klines['recent_klines']],
                ['Dernière màj', $klines['last_update'] ?? 'N/A'],
            ]
        );

        $io->table(
            ['Métriques Snapshots', 'Valeur'],
            [
                ['Total snapshots', $snapshots['total_snapshots']],
                ['Snapshots récents (<10min)', $snapshots['recent_snapshots']],
                ['Dernière màj', $snapshots['last_update'] ?? 'N/A'],
            ]
        );

        // Alertes si nécessaire
        if ($summary['status'] === 'CRITICAL') {
            $io->error('⚠️  Statut CRITICAL détecté ! Taux de succès inférieur à 60%.');
        } elseif ($summary['status'] === 'WARNING') {
            $io->warning('⚠️  Statut WARNING détecté. Taux de succès entre 60% et 80%.');
        } else {
            $io->success('✅ Système en bonne santé (taux de succès >= 80%).');
        }

        return Command::SUCCESS;
    }

    /**
     * Colorise le statut pour l'affichage console.
     */
    private function colorizeStatus(string $status): string
    {
        return match ($status) {
            'HEALTHY'  => '<fg=green>✅ HEALTHY</>',
            'WARNING'  => '<fg=yellow>⚠️  WARNING</>',
            'CRITICAL' => '<fg=red>❌ CRITICAL</>',
            default    => $status,
        };
    }

    /**
     * Convertit un array associatif en CSV.
     */
    private function arrayToCsv(array $rows): string
    {
        if (empty($rows)) {
            return '';
        }

        $headers = array_keys($rows[0]);
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $headers);

        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $h) {
                $v = $row[$h] ?? '';
                if (is_array($v) || is_object($v)) {
                    $v = json_encode($v, JSON_UNESCAPED_SLASHES);
                }
                $line[] = $v;
            }
            fputcsv($fh, $line);
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return (string)$csv;
    }
}

