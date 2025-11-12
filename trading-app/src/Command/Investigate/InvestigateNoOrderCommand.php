<?php

declare(strict_types=1);

namespace App\Command\Investigate;

use App\MtfValidator\Repository\MtfAuditRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'investigate:no-order',
    description: 'Diagnostique pourquoi un ou plusieurs symboles n\'ont pas abouti à un ordre exécuté (soumission, skip, MTF non READY).'
)]
class InvestigateNoOrderCommand extends Command
{
    public function __construct(
        private readonly MtfAuditRepository $mtfAuditRepository,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir
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

        $positionsLogs = $this->findRecentPositionLogs($maxLogFiles);
        if ($positionsLogs === []) {
            $io->warning('Aucun fichier positions-*.log trouvé dans var/log.');
        }

        $results = [];
        foreach ($symbols as $symbol) {
            $results[$symbol] = $this->analyzeSymbol($symbol, $positionsLogs, $since);
        }

        if ($format === 'json') {
            $io->writeln(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        // Affichage table
        $rows = [];
        foreach ($results as $symbol => $r) {
            $rows[] = [
                $symbol,
                $r['status'] ?? 'unknown',
                $r['reason'] ?? ($r['details']['cause'] ?? ''),
                $r['details']['order_id'] ?? '',
                $r['details']['decision_key'] ?? '',
                $r['details']['timeframe'] ?? '',
                $r['details']['kline_time'] ?? '',
            ];
        }
        $io->table(
            ['Symbol', 'Status', 'Reason/Cause', 'Order ID', 'Decision Key', 'TF', 'Kline Time'],
            $rows
        );

        $io->success('Investigation terminée');
        return Command::SUCCESS;
    }

    /**
     * @param list<string> $logFiles
     * @return array<string,mixed>
     */
    private function analyzeSymbol(string $symbol, array $logFiles, \DateTimeImmutable $since): array
    {
        // 1) Scanner les logs positions pour déduire: submitted / skipped / errors
        $logScan = $this->scanPositionsLogs($symbol, $logFiles, $since);
        if ($logScan['status'] !== null) {
            return $logScan;
        }

        // 2) Sinon, chercher la cause côté MTF (validations/alignment/kill switch)
        $audit = $this->findLastBlockingAudit($symbol, $since);
        if ($audit !== null) {
            return [
                'status' => 'mtf_not_ready',
                'reason' => $audit['step'] ?? 'MTF_BLOCKER',
                'details' => [
                    'cause' => $audit['cause'] ?? null,
                    'timeframe' => $audit['timeframe'] ?? ($audit['details']['timeframe'] ?? null),
                    'kline_time' => $audit['details']['kline_time'] ?? null,
                    'created_at' => $audit['created_at'] ?? null,
                ],
            ];
        }

        return [
            'status' => 'unknown',
            'reason' => 'no_traces',
            'details' => [],
        ];
    }

    /**
     * @param list<string> $logFiles
     * @return array{status:?string, reason:?string, details:array<string,mixed>}
     */
    private function scanPositionsLogs(string $symbol, array $logFiles, \DateTimeImmutable $since): array
    {
        $status = null;
        $reason = null;
        $details = [];

        $symbolNeedle1 = 'symbol=' . $symbol;
        $symbolNeedle2 = 'payload.symbol=' . $symbol;

        foreach ($logFiles as $file) {
            try {
                $fh = new \SplFileObject($file, 'r');
                while (!$fh->eof()) {
                    $line = (string)$fh->fgets();
                    if ($line === '') { continue; }
                    if (!str_contains($line, $symbolNeedle1) && !str_contains($line, $symbolNeedle2)) { continue; }

                    $ts = $this->extractTimestamp($line);
                    if ($ts !== null && $ts < $since) { continue; }

                    // Submitted success
                    if (str_contains($line, 'positions.order_submit.success')) {
                        $status = 'submitted';
                        $details['order_id'] = $this->extractToken($line, 'order_id');
                        $details['client_order_id'] = $this->extractToken($line, 'client_order_id');
                        $details['decision_key'] = $this->extractToken($line, 'decision_key');
                        // status found -> we can early return with strongest outcome
                        return ['status' => $status, 'reason' => null, 'details' => $details];
                    }

                    // Trade entry skipped with explicit reason
                    if (str_contains($line, 'order_journey.trade_entry.skipped')) {
                        $status = 'skipped';
                        $reason = $this->extractToken($line, 'reason') ?? 'skipped';
                        $details['decision_key'] = $this->extractToken($line, 'decision_key');
                        // Context: zone min/max/dev
                        foreach (['candidate','zone_min','zone_max','zone_dev_pct','zone_max_dev_pct'] as $k) {
                            $v = $this->extractContextToken($line, $k);
                            if ($v !== null) { $details[$k] = is_numeric($v) ? (float)$v : $v; }
                        }
                        // keep scanning to see if later submitted shows up; otherwise return at the end
                    }

                    // Zone skipped (builder-level)
                    if (str_contains($line, 'build_order_plan.zone_skipped_for_execution')) {
                        $status = 'skipped';
                        $reason = 'zone_far_from_market';
                        foreach (['candidate','zone_min','zone_max','zone_dev_pct','zone_max_dev_pct'] as $k) {
                            $v = $this->extractToken($line, $k);
                            if ($v !== null) { $details[$k] = is_numeric($v) ? (float)$v : $v; }
                        }
                    }

                    if ($status === null && (str_contains($line, 'positions.order_submit.fail') || str_contains($line, 'positions.order_submit.error'))) {
                        $status = 'error';
                        $reason = $this->extractToken($line, 'reason') ?? 'submit_failed';
                        $details['client_order_id'] = $this->extractToken($line, 'client_order_id');
                        $details['decision_key'] = $this->extractToken($line, 'decision_key');
                    }
                }
            } catch (\Throwable) {
                // ignore file errors
            }
        }

        return [
            'status' => $status,
            'reason' => $reason,
            'details' => $details,
        ];
    }

    private function findRecentPositionLogs(int $maxFiles): array
    {
        $dir = rtrim($this->projectDir ?: dirname(__DIR__, 3), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log';
        if (!is_dir($dir)) {
            return [];
        }

        $files = array_values(array_filter(scandir($dir) ?: [], static fn(string $f) => str_starts_with($f, 'positions-') && str_ends_with($f, '.log')));
        // sort by date in filename descending
        usort($files, static function (string $a, string $b): int {
            return strcmp($b, $a);
        });

        $files = array_slice($files, 0, $maxFiles);
        return array_map(static fn(string $f) => $dir . DIRECTORY_SEPARATOR . $f, $files);
    }

    private function extractTimestamp(string $line): ?\DateTimeImmutable
    {
        // pattern: [YYYY-MM-DD HH:MM:SS.mmm]
        if (preg_match('/\[(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})/u', $line, $m) === 1) {
            try {
                return new \DateTimeImmutable($m[1] . ' ' . $m[2], new \DateTimeZone('UTC'));
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }

    private function extractToken(string $line, string $key): ?string
    {
        // matches key=value or key="..."
        $pattern = sprintf('/%s=([^\s\"]+|\"([^\"]*)\")/u', preg_quote($key, '/'));
        if (preg_match($pattern, $line, $m) === 1) {
            $val = $m[1];
            if (strlen($val) > 1 && $val[0] === '"' && str_ends_with($val, '"')) {
                return substr($val, 1, -1);
            }
            return $val;
        }
        return null;
    }

    private function extractContextToken(string $line, string $key): ?string
    {
        // matches context.key=value
        $pattern = sprintf('/context\\.%s=([^\s\"]+|\"([^\"]*)\")/u', preg_quote($key, '/'));
        if (preg_match($pattern, $line, $m) === 1) {
            $val = $m[1];
            if (strlen($val) > 1 && $val[0] === '"' && str_ends_with($val, '"')) {
                return substr($val, 1, -1);
            }
            return $val;
        }
        return null;
    }

    /**
     * Retourne la dernière ligne d'audit bloquante (VALIDATION_FAILED, ALIGNMENT_FAILED, KILL_SWITCH_OFF) pour le symbole.
     * @return array<string,mixed>|null
     */
    private function findLastBlockingAudit(string $symbol, \DateTimeImmutable $since): ?array
    {
        try {
            $qb = $this->mtfAuditRepository->createQueryBuilder('m');
            $qb
                ->where('m.symbol = :symbol')
                ->andWhere('m.createdAt >= :since')
                ->andWhere($qb->expr()->orX(
                    $qb->expr()->like('m.step', ':failed'),
                    $qb->expr()->eq('m.step', ':alignment'),
                    $qb->expr()->eq('m.step', ':kswitch'),
                ))
                ->setParameter('symbol', $symbol)
                ->setParameter('since', $since)
                ->setParameter('failed', '%VALIDATION_FAILED%')
                ->setParameter('alignment', 'ALIGNMENT_FAILED')
                ->setParameter('kswitch', 'KILL_SWITCH_OFF')
                ->orderBy('m.createdAt', 'DESC')
                ->addOrderBy('m.id', 'DESC')
                ->setMaxResults(1);

            /** @var object|null $row */
            $row = $qb->getQuery()->getOneOrNullResult();
            if ($row === null) { return null; }

            // Convertir l'entité en tableau minimal pour sortie
            $get = static function(string $prop) use ($row) {
                $m = 'get' . ucfirst($prop);
                return method_exists($row, $m) ? $row->$m() : null;
            };

            $details = $get('details') ?? [];
            return [
                'id' => $get('id'),
                'symbol' => $get('symbol'),
                'step' => $get('step'),
                'cause' => $get('cause'),
                'details' => is_array($details) ? $details : [],
                'timeframe' => ($tf = $get('timeframe')) ? (string)$tf->value : null,
                'created_at' => ($dt = $get('createdAt')) ? $dt->format('Y-m-d H:i:sP') : null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
