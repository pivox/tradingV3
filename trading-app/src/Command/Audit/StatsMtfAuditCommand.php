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
details.failed_conditions_long, details.failed_conditions_short) et propose 4 rapports :

  • all-sides : top des conditions bloquantes (tous sides confondus)
  • by-side   : top ventilé par side (long/short)
  • weights   : poids (%) de chaque condition dans les échecs d’un timeframe
  • rollup    : agrégation multi-niveaux (condition → timeframe → side) avec sous-totaux

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
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'Type de rapport: all-sides|by-side|weights|rollup', 'all-sides')
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

        // Help détaillé avec tes 4 exemples exacts
        $this->setHelp(<<<'HELP'
Exemples d’utilisation :

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
                default     => throw new \InvalidArgumentException("report invalide: $report"),
            };
        } catch (\Throwable $e) {
            $io->error('Erreur SQL: '.$e->getMessage());
            return Command::FAILURE;
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
}
