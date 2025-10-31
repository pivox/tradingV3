<?php

namespace App\Command\Audit;

use App\Repository\MtfAuditRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'stats:mtf-audit',
    description: <<<DESC
Statistiques des conditions bloquantes depuis la table mtf_audit.

Cette commande agrège les conditions ayant échoué (JSONB: details.conditions_failed,
details.failed_conditions_long, details.failed_conditions_short) et propose 7 rapports :

  • all-sides     : top des conditions bloquantes (tous sides confondus)
  • by-side       : top ventilé par side (long/short)
  • weights       : poids (%) de chaque condition dans les échecs d'un timeframe
  • rollup        : agrégation multi-niveaux (condition → timeframe → side) avec sous-totaux
  • by-timeframe  : agrégation des échecs par timeframe (détecter où ça bloque)
  • success       : liste des dernières validations réussies
  • calibration   : calcule le fail_pct moyen et évalue la qualité du système

Filtres disponibles : symboles (IN), timeframes (IN), dates (since OU bien from/to), limite de lignes.
Sorties : table (par défaut), json, csv (avec option --output pour écrire un fichier).
DESC
)]
class StatsMtfAuditCommand extends Command
{
    public function __construct(
        private readonly MtfAuditRepository $repo
    ) {
        parent::__construct();
    }

    protected static $defaultName = 'stats:mtf-audit';

    protected static $defaultDescription = 'Statistiques des conditions bloquantes depuis mtf_audit (filtres: symboles, timeframes, dates).';

    protected function configure(): void
    {
        $this
            // Quel rapport ?
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'Type de rapport: all-sides|by-side|weights|rollup|by-timeframe|success|calibration', 'all-sides')
            // Filtres
            ->addOption('symbols', null, InputOption::VALUE_OPTIONAL, 'Liste de symboles séparés par des virgules (ex: BTCUSDT,ETHUSDT)')
            ->addOption('timeframes', 't', InputOption::VALUE_OPTIONAL, 'Liste de TF séparés par des virgules (ex: 1m,5m,15m,1h,4h)')
            ->addOption('since', null, InputOption::VALUE_OPTIONAL, "created_at > :since (ex: '2025-10-01 00:00:00+00')")
            ->addOption('from', null, InputOption::VALUE_OPTIONAL, "created_at >= :from (ex: '2025-10-01 00:00:00+00')")
            ->addOption('to', null, InputOption::VALUE_OPTIONAL, "created_at <= :to   (ex: '2025-10-20 23:59:59+00')")
            // Autres
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Limite de lignes (si applicable)', '100')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Format de sortie: table|json|csv', 'table')
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Chemin fichier de sortie (pour csv/json). Laisse vide pour STDOUT.')
        ;

        // Help détaillé avec exemples
        $this->setHelp(<<<'HELP'
Exemples d'utilisation :

# 1) Top global (tous sides), dernière semaine, TF 1h/4h, 100 lignes, table
bin/console stats:mtf-audit --report=all-sides -t 1h,4h --since="now -7 days" -l 100

# 2) Par side, fenêtre BETWEEN, symboles ciblés, CSV vers fichier
bin/console stats:mtf-audit --report=by-side \
  --from="2025-10-01 00:00:00+00" --to="2025-10-20 23:59:59+00" \
  --symbols=ICNTUSDT,USTCUSDT --format=csv --output=/tmp/mtf_by_side.csv

# 3) Poids par TF (pour un TF), JSON sur stdout
bin/console stats:mtf-audit --report=weights -t 1h --since="2025-10-15 00:00:00+00" --format=json

# 4) Rollup (condition→TF→side), sans filtres, table
bin/console stats:mtf-audit --report=rollup

# 5) Par timeframe → détecter où ça bloque
bin/console stats:mtf-audit --report=by-timeframe

# 6) Liste les dernières validations réussies
bin/console stats:mtf-audit --report=success

# 7) Calcule le fail_pct moyen et évalue la calibration du système
bin/console stats:mtf-audit --report=calibration

Notes :
- Utilisez soit --since, soit le couple --from/--to (pas les deux).
- Les timeframes et symboles acceptent une liste séparée par des virgules.
- --format=csv|json peut être redirigé vers un fichier avec --output=...
HELP);
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $io = new SymfonyStyle($in, $out);

        $report    = strtolower((string)$in->getOption('report'));
        $format    = strtolower((string)$in->getOption('format'));
        $limit     = (int)$in->getOption('limit');
        $symbols   = $this->csvToArray($in->getOption('symbols'));
        $timeframes= $this->csvToArray($in->getOption('timeframes'));
        $sinceStr  = $in->getOption('since');
        $fromStr   = $in->getOption('from');
        $toStr     = $in->getOption('to');
        $output    = $in->getOption('output');

        // Validation dates : BETWEEN (from,to) ou bien since, mais pas les deux
        if ($sinceStr && ($fromStr || $toStr)) {
            $io->error("Utilisez soit --since, soit le couple --from/--to, mais pas les deux.");
            return Command::INVALID;
        }
        if (($fromStr && !$toStr) || (!$fromStr && $toStr)) {
            $io->error("Pour BETWEEN, fournissez à la fois --from ET --to.");
            return Command::INVALID;
        }

        // Parsing dates
        $since = $sinceStr ? $this->toDateTime($sinceStr) : null;
        $from  = $fromStr  ? $this->toDateTime($fromStr)   : null;
        $to    = $toStr    ? $this->toDateTime($toStr)     : null;
        if (($sinceStr && !$since) || ($fromStr && !$from) || ($toStr && !$to)) {
            $io->error("Format de date invalide. Exemple: 2025-10-01 00:00:00+00");
            return Command::INVALID;
        }

        // Récupération des données
        try {
            $rows = match ($report) {
                'all-sides' => $this->repo->topBlockingConditionsAllSides(
                    $symbols, $timeframes, $since, $from, $to, $limit
                ),
                'by-side'   => $this->repo->topBlockingConditionsBySide(
                    $symbols, $timeframes, $since, $from, $to, $limit
                ),
                'weights'   => $this->repo->conditionWeightsPerTimeframe(
                    $symbols, $timeframes, $since, $from, $to
                ),
                'rollup'    => $this->repo->rollupByConditionTimeframeSide(
                    $symbols, $timeframes, $since, $from, $to
                ),
                'by-timeframe' => $this->repo->blockingByTimeframe(
                    $symbols, $timeframes, $since, $from, $to, $limit
                ),
                'success'   => $this->repo->recentSuccessfulValidations(
                    $symbols, $timeframes, $since, $from, $to, $limit
                ),
                'calibration' => $this->repo->calibrationReport(
                    $symbols, $timeframes, $since, $from, $to
                ),
                default     => throw new \InvalidArgumentException("report invalide: $report"),
            };
        } catch (\Throwable $e) {
            $io->error('Erreur SQL: '.$e->getMessage());
            return Command::FAILURE;
        }

        // Traitement spécial pour le rapport calibration
        if ($report === 'calibration') {
            return $this->handleCalibrationReport($rows, $format, $output, $io);
        }

        // Sortie
        if ($format === 'json') {
            $payload = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($output) {
                file_put_contents($output, $payload.PHP_EOL);
                $io->success("JSON écrit dans: $output");
            } else {
                $io->writeln($payload);
            }
            return Command::SUCCESS;
        }

        if ($format === 'csv') {
            $csv = $this->toCsv($rows);
            if ($output) {
                file_put_contents($output, $csv);
                $io->success("CSV écrit dans: $output");
            } else {
                $io->writeln($csv);
            }
            return Command::SUCCESS;
        }

        // format = table (par défaut)
        if (empty($rows)) {
            $io->warning('Aucune ligne.');
            return Command::SUCCESS;
        }

        $headers = array_keys($rows[0]);
        $io->title(sprintf(
            'mtf_audit stats — report=%s, symbols=%s, tf=%s, since=%s, from=%s, to=%s',
            $report,
            $symbols ? implode(',',$symbols) : 'ALL',
            $timeframes ? implode(',',$timeframes) : 'ALL',
            $since? $since->format(DATE_ATOM) : '—',
            $from?  $from->format(DATE_ATOM)  : '—',
            $to?    $to->format(DATE_ATOM)    : '—'
        ));
        $io->table($headers, array_map('array_values', $rows));

        return Command::SUCCESS;
    }

    /** Convertit "A,B,C" -> ["A","B","C"] ; null/"" -> null */
    private function csvToArray(?string $csv): ?array
    {
        if (!$csv) return null;
        $arr = array_values(array_filter(array_map('trim', explode(',', $csv)), fn($v) => $v !== ''));
        return $arr ?: null;
    }

    /** Parse une date "Y-m-d H:i:sP" ou ISO. Retourne DateTimeImmutable|null. */
    private function toDateTime(string $str): ?\DateTimeImmutable
    {
        // Essaye format commun + ISO
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:sP', $str)
            ?: \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $str)
                ?: new \DateTimeImmutable($str);
        return $dt ?: null;
    }

    /** Sérialise un array de lignes associatives en CSV. */
    private function toCsv(array $rows): string
    {
        $headers = array_keys($rows[0]);
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $headers);
        foreach ($rows as $r) {
            $line = [];
            foreach ($headers as $h) {
                $v = $r[$h] ?? '';
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

    /**
     * Traitement spécifique du rapport calibration avec calcul du fail_pct_moyen.
     */
    private function handleCalibrationReport(
        array $rows,
        string $format,
        ?string $output,
        SymfonyStyle $io
    ): int {
        if (empty($rows)) {
            $io->warning('Aucune donnée disponible pour le rapport de calibration.');
            return Command::SUCCESS;
        }

        // Calcul du fail_pct_moyen : (∑ fail_count) / (∑ total_fails) × 100
        $sumFailCount = 0;
        $sumTotalFails = 0;
        $timeframeData = [];

        foreach ($rows as $row) {
            $tf = $row['timeframe'] ?? 'unknown';
            $failCount = (int)($row['fail_count'] ?? 0);
            $totalFails = (int)($row['total_fails'] ?? 0);

            $sumFailCount += $failCount;
            $sumTotalFails += $totalFails;

            if (!isset($timeframeData[$tf])) {
                $timeframeData[$tf] = [
                    'fail_count_sum' => 0,
                    'total_fails' => $totalFails,
                    'conditions' => []
                ];
            }

            $timeframeData[$tf]['fail_count_sum'] += $failCount;
            $timeframeData[$tf]['conditions'][] = [
                'condition' => $row['condition_name'] ?? 'unknown',
                'fail_count' => $failCount,
                'fail_pct' => (float)($row['fail_pct'] ?? 0.0),
            ];
        }

        // Calcul du fail_pct_moyen global
        $failPctMoyen = $sumTotalFails > 0 ? round(($sumFailCount / $sumTotalFails) * 100, 2) : 0.0;

        // Interprétation
        $interpretation = $this->interpretCalibration($failPctMoyen, $sumTotalFails);

        // Construction du résultat enrichi
        $result = [
            'fail_pct_moyen' => $failPctMoyen,
            'sum_fail_count' => $sumFailCount,
            'sum_total_fails' => $sumTotalFails,
            'interpretation' => $interpretation,
            'by_timeframe' => [],
        ];

        foreach ($timeframeData as $tf => $data) {
            $tfFailPct = $data['total_fails'] > 0
                ? round(($data['fail_count_sum'] / $data['total_fails']) * 100, 2)
                : 0.0;

            $result['by_timeframe'][] = [
                'timeframe' => $tf,
                'fail_count_sum' => $data['fail_count_sum'],
                'total_fails' => $data['total_fails'],
                'fail_pct' => $tfFailPct,
                'top_conditions' => array_slice($data['conditions'], 0, 5), // Top 5 conditions
            ];
        }

        // Sortie selon format
        if ($format === 'json') {
            $payload = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($output) {
                file_put_contents($output, $payload . PHP_EOL);
                $io->success("Rapport calibration JSON écrit dans: $output");
            } else {
                $io->writeln($payload);
            }
            return Command::SUCCESS;
        }

        if ($format === 'csv') {
            // Pour CSV, on aplatit les données par timeframe
            $csvRows = [];
            foreach ($result['by_timeframe'] as $tfData) {
                $csvRows[] = [
                    'timeframe' => $tfData['timeframe'],
                    'fail_count_sum' => $tfData['fail_count_sum'],
                    'total_fails' => $tfData['total_fails'],
                    'fail_pct' => $tfData['fail_pct'] . '%',
                ];
            }
            $csv = $this->toCsv($csvRows);
            if ($output) {
                file_put_contents($output, $csv);
                $io->success("Rapport calibration CSV écrit dans: $output");
            } else {
                $io->writeln($csv);
            }
            return Command::SUCCESS;
        }

        // Format table (par défaut)
        $this->displayCalibrationTable($result, $io);
        return Command::SUCCESS;
    }

    /**
     * Interprète le fail_pct_moyen selon les seuils définis.
     */
    private function interpretCalibration(float $failPctMoyen, int $totalFails): array
    {
        // Détection du blocage stable (0% sur plusieurs heures)
        if ($failPctMoyen === 0.0 && $totalFails === 0) {
            return [
                'status' => 'BLOCKED',
                'diagnostic' => 'Données ou process figés',
                'action' => '❌ Blocage pipeline',
                'color' => 'red',
            ];
        }

        if ($failPctMoyen >= 0.0 && $failPctMoyen <= 5.0) {
            return [
                'status' => 'EXCELLENT',
                'diagnostic' => 'Bon équilibre',
                'action' => '✅ Stable',
                'color' => 'green',
            ];
        }

        if ($failPctMoyen > 5.0 && $failPctMoyen <= 9.0) {
            return [
                'status' => 'GOOD',
                'diagnostic' => 'Marché neutre / cohérent',
                'action' => '⚙️ OK',
                'color' => 'green',
            ];
        }

        if ($failPctMoyen > 9.0 && $failPctMoyen <= 15.0) {
            return [
                'status' => 'WARNING',
                'diagnostic' => 'Règles trop strictes',
                'action' => '🔹 Assouplir les tolérances EMA / MACD',
                'color' => 'yellow',
            ];
        }

        if ($failPctMoyen > 15.0 && $failPctMoyen <= 20.0) {
            return [
                'status' => 'CRITICAL',
                'diagnostic' => 'Mauvaise calibration',
                'action' => '🔸 Règles mal conçues ou non pertinentes',
                'color' => 'red',
            ];
        }

        // > 20%
        return [
            'status' => 'CRITICAL',
            'diagnostic' => 'Très mauvais calibrage',
            'action' => '🔸 Règles mal conçues ou non pertinentes',
            'color' => 'red',
        ];
    }

    /**
     * Affiche le rapport de calibration en format table.
     */
    private function displayCalibrationTable(array $result, SymfonyStyle $io): void
    {
        $failPctMoyen = $result['fail_pct_moyen'];
        $interp = $result['interpretation'];

        $io->title('Rapport de Calibration MTF');

        // Résumé global
        $io->section('📊 Résumé Global');
        $statusColor = match ($interp['color']) {
            'green' => '<fg=green>%s</>',
            'yellow' => '<fg=yellow>%s</>',
            'red' => '<fg=red>%s</>',
            default => '%s',
        };

        $io->table(
            ['Métrique', 'Valeur'],
            [
                ['fail_pct_moyen', sprintf('%.2f%%', $failPctMoyen)],
                ['∑ fail_count', $result['sum_fail_count']],
                ['∑ total_fails', $result['sum_total_fails']],
                ['Statut', sprintf($statusColor, $interp['status'])],
                ['Diagnostic', $interp['diagnostic']],
                ['Action recommandée', $interp['action']],
            ]
        );

        // Interprétation détaillée
        $io->section('📖 Grille d\'Interprétation');
        $io->table(
            ['fail_pct moyen', 'Diagnostic', 'Action'],
            [
                ['0 – 5%', 'Bon équilibre', '✅ Stable'],
                ['6 – 9%', 'Marché neutre / cohérent', '⚙️ OK'],
                ['10 – 15%', 'Règles trop strictes', '🔹 Assouplir les tolérances EMA / MACD'],
                ['> 20%', 'Très mauvais calibrage', '🔸 Règles mal conçues ou non pertinentes'],
                ['= 0% stable plusieurs heures', 'Données ou process figés', '❌ Blocage pipeline'],
            ]
        );

        // Détail par timeframe
        $io->section('⏱️ Détail par Timeframe');
        $tfRows = [];
        foreach ($result['by_timeframe'] as $tfData) {
            $tfRows[] = [
                $tfData['timeframe'],
                $tfData['fail_count_sum'],
                $tfData['total_fails'],
                sprintf('%.2f%%', $tfData['fail_pct']),
            ];
        }
        $io->table(['Timeframe', '∑ fail_count', '∑ total_fails', 'fail_pct'], $tfRows);

        // Top conditions par timeframe
        $io->section('🔝 Top Conditions par Timeframe (max 5)');
        foreach ($result['by_timeframe'] as $tfData) {
            $io->writeln(sprintf('<comment>Timeframe: %s</comment>', $tfData['timeframe']));
            $condRows = [];
            foreach ($tfData['top_conditions'] as $cond) {
                $condRows[] = [
                    $cond['condition'],
                    $cond['fail_count'],
                    sprintf('%.2f%%', $cond['fail_pct']),
                ];
            }
            if (!empty($condRows)) {
                $io->table(['Condition', 'Fail Count', 'Fail %'], $condRows);
            } else {
                $io->writeln('  <info>Aucune condition</info>');
            }
        }

        // Message final selon le statut
        if ($interp['status'] === 'BLOCKED') {
            $io->error('⚠️ BLOCAGE DÉTECTÉ : Aucune validation en échec. Vérifiez le pipeline MTF !');
        } elseif ($interp['status'] === 'CRITICAL') {
            $io->error(sprintf('⚠️ CALIBRATION CRITIQUE : %s', $interp['diagnostic']));
        } elseif ($interp['status'] === 'WARNING') {
            $io->warning(sprintf('⚠️ ATTENTION : %s', $interp['diagnostic']));
        } else {
            $io->success(sprintf('✅ SYSTÈME SAIN : %s', $interp['diagnostic']));
        }
    }
}
