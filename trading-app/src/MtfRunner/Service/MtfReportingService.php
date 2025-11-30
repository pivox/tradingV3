<?php

declare(strict_types=1);

namespace App\MtfRunner\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\HttpKernel\KernelInterface;

final class MtfReportingService
{
    public function __construct(
        private readonly Connection $connection,
        KernelInterface $kernel,
    ) {
        $this->projectDir = $kernel->getProjectDir();
    }

    private readonly string $projectDir;

    public function getMtfReportData(string $date): array
    {
        $devLog = $this->projectDir . "/var/log/dev-$date.log";
        $mtfLog = $this->projectDir . "/var/log/mtf-$date.log";

        $errors = [];
        if (!is_file($devLog)) {
            $errors[] = sprintf('Fichier introuvable: %s', basename($devLog));
        }
        if (!is_file($mtfLog)) {
            $errors[] = sprintf('Fichier introuvable: %s', basename($mtfLog));
        }

        $httpStats = ['count' => 0, 'first' => null, 'last' => null];
        $contextReasons = [];
        $ctxInvalidReasons = [];
        $ctxInvalidByTf = [];
        $filterStats = [];

        if (is_file($devLog)) {
            $this->iterateLogFile($devLog, function (string $line) use (&$httpStats, &$ctxInvalidReasons, &$ctxInvalidByTf, &$filterStats): void {
                if (str_contains($line, '/api/mtf/run')) {
                    $httpStats['count']++;
                    $timestamp = $this->extractTimestamp($line);
                    if ($httpStats['first'] === null) {
                        $httpStats['first'] = $timestamp;
                    }
                    $httpStats['last'] = $timestamp;
                }

                if (str_contains($line, '[MTF] Context timeframe invalid')) {
                    if (preg_match('/invalid_reason=([^ ]+)/', $line, $matches)) {
                        $ctxInvalidReasons[$matches[1]] = ($ctxInvalidReasons[$matches[1]] ?? 0) + 1;
                    }
                    if (preg_match('/\btf=([^ ]+)/', $line, $matches) || preg_match('/timeframe=([^ ]+)/', $line, $matches)) {
                        $idx = $matches[1];
                        $ctxInvalidByTf[$idx] = ($ctxInvalidByTf[$idx] ?? 0) + 1;
                    }
                }

                if (str_contains($line, '[MTF] Context filter check')) {
                    $filter = null;
                    $passed = null;
                    if (preg_match('/filter=([^ ]+)/', $line, $matches)) {
                        $filter = $matches[1];
                    }
                    if (preg_match('/passed=([^ ]+)/', $line, $matches)) {
                        $passed = $matches[1] === 'true';
                    }
                    if ($filter !== null && $passed !== null) {
                        if (!isset($filterStats[$filter])) {
                            $filterStats[$filter] = ['pass' => 0, 'fail' => 0];
                        }
                        if ($passed) {
                            $filterStats[$filter]['pass']++;
                        } else {
                            $filterStats[$filter]['fail']++;
                        }
                    }
                }
            });
        }

        if (is_file($mtfLog)) {
            $this->iterateLogFile($mtfLog, function (string $line) use (&$contextReasons): void {
                if (str_contains($line, 'reason=')) {
                    if (preg_match('/reason=([^ ]+)/', $line, $matches)) {
                        $reason = $matches[1];
                        $contextReasons[$reason] = ($contextReasons[$reason] ?? 0) + 1;
                    }
                }
            });
        }

        arsort($contextReasons);
        arsort($ctxInvalidReasons);
        arsort($ctxInvalidByTf);

        uasort($filterStats, function ($a, $b) {
            $aTotal = ($a['pass'] ?? 0) + ($a['fail'] ?? 0);
            $bTotal = ($b['pass'] ?? 0) + ($b['fail'] ?? 0);
            return $bTotal <=> $aTotal;
        });

        return [
            'date' => $date,
            'errors' => $errors,
            'http_stats' => $httpStats,
            'context_reasons' => $contextReasons,
            'ctx_invalid_reasons' => $ctxInvalidReasons,
            'ctx_invalid_by_tf' => $ctxInvalidByTf,
            'filter_stats' => $filterStats,
        ];
    }

    public function getMtfSymbolsReportData(string $date): array
    {
        $baseWhere = 'FROM mtf_run_symbol s JOIN mtf_run r ON r.run_id = s.run_id WHERE r.started_at::date = :date';
        $params = ['date' => $date];

        $totalRows = (int) $this->connection->fetchOne("SELECT COUNT(*) $baseWhere", $params);
        $distinctSymbols = (int) $this->connection->fetchOne("SELECT COUNT(DISTINCT s.symbol) $baseWhere", $params);

        $statuses = $this->connection->executeQuery(
            "SELECT COALESCE(s.status, '<NULL>') AS status, COUNT(*) AS c $baseWhere GROUP BY COALESCE(s.status, '<NULL>') ORDER BY c DESC, status",
            $params
        )->fetchAllAssociative();

        $executionTf = $this->connection->executeQuery(
            "SELECT COALESCE(s.execution_tf, '<NULL>') AS execution_tf, COUNT(*) AS c $baseWhere GROUP BY COALESCE(s.execution_tf, '<NULL>') ORDER BY c DESC, execution_tf",
            $params
        )->fetchAllAssociative();

        $blockingTf = $this->connection->executeQuery(
            "SELECT COALESCE(s.blocking_tf, '<NULL>') AS blocking_tf, COUNT(*) AS c $baseWhere GROUP BY COALESCE(s.blocking_tf, '<NULL>') ORDER BY c DESC, blocking_tf",
            $params
        )->fetchAllAssociative();

        $signalSide = $this->connection->executeQuery(
            "SELECT COALESCE(s.signal_side, '<NULL>') AS signal_side, COUNT(*) AS c $baseWhere GROUP BY COALESCE(s.signal_side, '<NULL>') ORDER BY c DESC, signal_side",
            $params
        )->fetchAllAssociative();

        $topSymbols = $this->connection->executeQuery(
            "SELECT s.symbol,
                    COUNT(*) AS total,
                    SUM(CASE WHEN s.status = 'SUCCESS' THEN 1 ELSE 0 END) AS success,
                    SUM(CASE WHEN s.status <> 'SUCCESS' THEN 1 ELSE 0 END) AS not_success
             $baseWhere
             GROUP BY s.symbol
             ORDER BY total DESC, s.symbol
             LIMIT 50",
            $params
        )->fetchAllAssociative();

        $topProblematic = $this->connection->executeQuery(
            "SELECT s.symbol,
                    COUNT(*) AS total_rows,
                    SUM(CASE WHEN s.status <> 'SUCCESS' THEN 1 ELSE 0 END) AS problematic_rows
             $baseWhere
             GROUP BY s.symbol
             HAVING SUM(CASE WHEN s.status <> 'SUCCESS' THEN 1 ELSE 0 END) > 0
             ORDER BY problematic_rows DESC, total_rows DESC, s.symbol
             LIMIT 50",
            $params
        )->fetchAllAssociative();

        $topErrors = $this->connection->executeQuery(
            "SELECT s.symbol,
                    COALESCE(s.status, '<NULL>') AS status,
                    LEFT(s.error::text, 200) AS error_snippet,
                    COUNT(*) AS c
             $baseWhere
             AND s.error IS NOT NULL
             GROUP BY s.symbol, COALESCE(s.status, '<NULL>'), LEFT(s.error::text, 200)
             ORDER BY c DESC
             LIMIT 20",
            $params
        )->fetchAllAssociative();

        return [
            'date' => $date,
            'total_rows' => $totalRows,
            'distinct_symbols' => $distinctSymbols,
            'statuses' => $statuses,
            'execution_tf' => $executionTf,
            'blocking_tf' => $blockingTf,
            'signal_side' => $signalSide,
            'top_symbols' => $topSymbols,
            'top_problematic' => $topProblematic,
            'top_errors' => $topErrors,
        ];
    }

    /**
     * On garde le script PHP pour les blockers (analyse complète).
     */
    public function getMtfBlockersReport(string $date, ?string $timeFilter = null): array
    {
        $script = $this->projectDir . '/scripts/analyze_mtf_blockers.php';
        if (!is_file($script)) {
            return [
                'output' => '',
                'error' => sprintf('Script introuvable: %s', $script),
                'exitCode' => 1,
            ];
        }

        $command = ['php', $script, $date];
        if ($timeFilter !== null && $timeFilter !== '') {
            $command[] = $timeFilter;
        }

        $process = new Process($command, $this->projectDir);
        $process->run();

        return [
            'output' => $process->getOutput(),
            'error' => $process->getErrorOutput(),
            'exitCode' => $process->getExitCode() ?? 0,
        ];
    }

    private function iterateLogFile(string $path, callable $callback): void
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return;
        }

        while (($line = fgets($handle)) !== false) {
            $callback($line);
        }

        fclose($handle);
    }

    private function extractTimestamp(string $line): ?string
    {
        if (preg_match('/\[(.*?)\]/', $line, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
