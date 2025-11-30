<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand(
    name: 'app:export-symbol-data',
    description: 'Export all persisted data for a given symbol at a specific date/time (UTC)'
)]
final class ExportSymbolDataCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ParameterBagInterface $parameterBag
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbol to export (e.g. LINKUSDT)')
            ->addArgument('datetime', InputArgument::REQUIRED, 'Date and time in UTC format: Y-m-d H:i (e.g. 2025-11-30 13:02)')
            ->addOption('output-dir', 'o', InputOption::VALUE_OPTIONAL, 'Output directory (default: investigation/ in project root)')
            ->addOption('show-sql', null, InputOption::VALUE_NONE, 'Display SQL queries executed')
            ->addOption('show-logs', null, InputOption::VALUE_NONE, 'Display logs in console output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $symbol = strtoupper($input->getArgument('symbol'));
        $datetimeStr = $input->getArgument('datetime');
        $outputDir = $input->getOption('output-dir');
        $showSql = $input->getOption('show-sql');
        $showLogs = $input->getOption('show-logs');

        // Parser la date/heure
        try {
            $targetDateTime = new \DateTimeImmutable($datetimeStr, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            $io->error("Format de date invalide. Utilisez: Y-m-d H:i (ex: 2025-11-30 13:02)");
            return Command::FAILURE;
        }

        // Fenêtre de recherche: ±1 heure autour de la date cible
        $startTime = $targetDateTime->modify('-1 hour');
        $endTime = $targetDateTime->modify('+1 hour');

        // Si aucun répertoire spécifié, utiliser investigation/ monté dans le container
        if (!$outputDir) {
            $outputDir = '/var/www/investigation';
        }

        // Créer le répertoire s'il n'existe pas
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $io->title("Export des données pour le symbole: $symbol à {$targetDateTime->format('Y-m-d H:i')} UTC");

        // 1. Trouver tous les run_id et trace_id associés au symbole dans la fenêtre de temps
        $io->section('Recherche des exécutions');
        
        $sql = "SELECT DISTINCT 
                run_id,
                MIN(happened_at) as first_event,
                MAX(happened_at) as last_event,
                COUNT(*) as event_count
            FROM trade_lifecycle_event 
            WHERE symbol = ? 
            AND happened_at >= ? 
            AND happened_at <= ?
            AND run_id IS NOT NULL
            GROUP BY run_id
            ORDER BY first_event DESC";
        
        if ($showSql) {
            $io->text('<fg=cyan>SQL:</> ' . str_replace(['?'], ['\'' . $symbol . '\'', '\'' . $startTime->format('Y-m-d H:i:s') . '\'', '\'' . $endTime->format('Y-m-d H:i:s') . '\''], $sql));
        }
        
        $runs = $this->connection->fetchAllAssociative(
            $sql,
            [$symbol, $startTime->format('Y-m-d H:i:s'), $endTime->format('Y-m-d H:i:s')]
        );

        $io->text(sprintf('Trouvé %d exécution(s)', count($runs)));

        // Même si aucun run trouvé, on continue pour exporter les logs et autres données

        // 2. Récupérer les trace_id depuis les logs/events dans la fenêtre de temps
        $sqlTraceIds = "SELECT DISTINCT trace_id 
            FROM mtf_audit 
            WHERE symbol = ? AND created_at >= ? AND created_at <= ?
            UNION
            SELECT DISTINCT trace_id 
            FROM indicator_snapshots 
            WHERE symbol = ? AND inserted_at >= ? AND inserted_at <= ?";
        
        if ($showSql) {
            $io->text('<fg=cyan>SQL:</> ' . str_replace(['?'], ['\'' . $symbol . '\'', '\'' . $startTime->format('Y-m-d H:i:s') . '\'', '\'' . $endTime->format('Y-m-d H:i:s') . '\'', '\'' . $symbol . '\'', '\'' . $startTime->format('Y-m-d H:i:s') . '\'', '\'' . $endTime->format('Y-m-d H:i:s') . '\''], $sqlTraceIds));
        }
        
        $allTraceIds = $this->connection->fetchFirstColumn(
            $sqlTraceIds,
            [
                $symbol, $startTime->format('Y-m-d H:i:s'), $endTime->format('Y-m-d H:i:s'),
                $symbol, $startTime->format('Y-m-d H:i:s'), $endTime->format('Y-m-d H:i:s')
            ]
        );

        $io->text(sprintf('Trouvé %d trace_id(s)', count($allTraceIds)));

        // 3. Exporter les données pour chaque run
        $exportData = [
            'metadata' => [
                'symbol' => $symbol,
                'target_datetime' => $targetDateTime->format('Y-m-d H:i:s'),
                'time_window_start' => $startTime->format('Y-m-d H:i:s'),
                'time_window_end' => $endTime->format('Y-m-d H:i:s'),
                'exported_at' => date('Y-m-d H:i:s'),
                'total_runs' => count($runs),
                'total_trace_ids' => count($allTraceIds),
            ],
            'runs' => []
        ];

        $progressBar = $io->createProgressBar(count($runs));
        $progressBar->start();

        foreach ($runs as $run) {
            $runId = $run['run_id'];
            $runData = $this->exportRunData($symbol, $runId, $allTraceIds, $showSql, $io);
            $exportData['runs'][] = [
                'run_id' => $runId,
                'first_event' => $run['first_event'],
                'last_event' => $run['last_event'],
                'event_count' => $run['event_count'],
                'data' => $runData
            ];
            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine(2);

        // 4. Exporter les données globales du symbole dans la fenêtre de temps
        $io->section('Export des données globales du symbole');
        $globalData = $this->exportGlobalSymbolData($symbol, $startTime, $endTime, $showSql, $io);
        $exportData['global_data'] = $globalData;

        // 5. Exporter les logs pour cette date/heure
        $io->section('Export des logs');
        $logData = $this->exportLogsForDateTime($symbol, $targetDateTime, $showLogs, $io);
        $exportData['logs'] = $logData;
        $totalLogLines = array_sum(array_map('count', $logData));
        $io->text(sprintf('Logs exportés: %d lignes', $totalLogLines));
        
        if ($showLogs && !empty($logData)) {
            $io->newLine();
            $io->section('Aperçu des logs');
            foreach ($logData as $logType => $lines) {
                if (!empty($lines)) {
                    $io->text("<fg=yellow>$logType:</> " . count($lines) . " lignes");
                    // Afficher les 5 premières lignes de chaque type de log
                    foreach (array_slice($lines, 0, 5) as $line) {
                        $io->text('  ' . substr($line, 0, 150) . (strlen($line) > 150 ? '...' : ''));
                    }
                    if (count($lines) > 5) {
                        $io->text('  ... (' . (count($lines) - 5) . ' lignes supplémentaires)');
                    }
                    $io->newLine();
                }
            }
        }

        // 6. Sauvegarder
        $dateStr = $targetDateTime->format('Y-m-d_H-i');
        $filename = $outputDir . '/symbol_data_' . $symbol . '_' . $dateStr . '.json';
        file_put_contents($filename, json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $totalRecords = array_sum(array_map(function($run) {
            return array_sum(array_map('count', $run['data']));
        }, $exportData['runs'])) + array_sum(array_map('count', $globalData));

        $io->success([
            "Export terminé!",
            "Fichier: $filename",
            "Total runs: " . count($runs),
            "Total enregistrements: $totalRecords"
        ]);

        return Command::SUCCESS;
    }

    private function exportRunData(string $symbol, string $runId, array $traceIds, bool $showSql = false, ?SymfonyStyle $io = null): array
    {
        $data = [];

        // Trade Lifecycle Events
        $sql = "SELECT * FROM trade_lifecycle_event WHERE run_id = ? ORDER BY happened_at";
        if ($showSql && $io) {
            $io->text('<fg=cyan>SQL:</> ' . str_replace('?', "'$runId'", $sql));
        }
        $data['trade_lifecycle_event'] = $this->connection->fetchAllAssociative($sql, [$runId]);

        // Récupérer client_order_id et order_id depuis trade_lifecycle_event
        $sqlClientOrderId = "SELECT client_order_id FROM trade_lifecycle_event WHERE run_id = ? AND client_order_id IS NOT NULL LIMIT 1";
        if ($showSql && $io) {
            $io->text('<fg=cyan>SQL:</> ' . str_replace('?', "'$runId'", $sqlClientOrderId));
        }
        $clientOrderId = $this->connection->fetchOne($sqlClientOrderId, [$runId]);
        
        $sqlOrderId = "SELECT order_id FROM trade_lifecycle_event WHERE run_id = ? AND order_id IS NOT NULL LIMIT 1";
        if ($showSql && $io) {
            $io->text('<fg=cyan>SQL:</> ' . str_replace('?', "'$runId'", $sqlOrderId));
        }
        $orderId = $this->connection->fetchOne($sqlOrderId, [$runId]);

        // Order Intent
        $orderIntent = [];
        if ($clientOrderId) {
            $sql = "SELECT * FROM order_intent WHERE client_order_id = ?";
            if ($showSql && $io) {
                $io->text('<fg=cyan>SQL:</> ' . str_replace('?', "'$clientOrderId'", $sql));
            }
            $orderIntent = $this->connection->fetchAllAssociative($sql, [$clientOrderId]);
        } elseif ($symbol) {
            $sql = "SELECT * FROM order_intent WHERE symbol = ? AND created_at >= (SELECT MIN(happened_at) FROM trade_lifecycle_event WHERE run_id = ?) - INTERVAL '1 hour' ORDER BY created_at DESC LIMIT 10";
            if ($showSql && $io) {
                $io->text('<fg=cyan>SQL:</> ' . str_replace(['?', '?'], ["'$symbol'", "'$runId'"], $sql));
            }
            $orderIntent = $this->connection->fetchAllAssociative($sql, [$symbol, $runId]);
        }
        $data['order_intent'] = $orderIntent;

        // Futures Order
        $futuresOrder = [];
        if ($clientOrderId) {
            $sql = "SELECT * FROM futures_order WHERE client_order_id = ?";
            if ($showSql && $io) {
                $io->text('<fg=cyan>SQL:</> ' . str_replace('?', "'$clientOrderId'", $sql));
            }
            $futuresOrder = $this->connection->fetchAllAssociative($sql, [$clientOrderId]);
        } elseif ($orderId) {
            $sql = "SELECT * FROM futures_order WHERE order_id = ?";
            if ($showSql && $io) {
                $io->text('<fg=cyan>SQL:</> ' . str_replace('?', "'$orderId'", $sql));
            }
            $futuresOrder = $this->connection->fetchAllAssociative($sql, [$orderId]);
        }
        $data['futures_order'] = $futuresOrder;

        // Futures Order Trade
        $futuresOrderTrade = [];
        if (!empty($futuresOrder)) {
            $orderIds = array_column($futuresOrder, 'id');
            if (!empty($orderIds)) {
                $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
                $sql = "SELECT * FROM futures_order_trade WHERE futures_order_id IN ($placeholders)";
                if ($showSql && $io) {
                    $idsStr = implode("', '", $orderIds);
                    $io->text('<fg=cyan>SQL:</> ' . str_replace($placeholders, "'$idsStr'", $sql));
                }
                $futuresOrderTrade = $this->connection->fetchAllAssociative($sql, $orderIds);
            }
        }
        $data['futures_order_trade'] = $futuresOrderTrade;

        // Futures Plan Order
        $futuresPlanOrder = [];
        if ($clientOrderId) {
            $sql = "SELECT * FROM futures_plan_order WHERE client_order_id = ?";
            if ($showSql && $io) {
                $io->text('<fg=cyan>SQL:</> ' . str_replace('?', "'$clientOrderId'", $sql));
            }
            $futuresPlanOrder = $this->connection->fetchAllAssociative($sql, [$clientOrderId]);
        }
        $data['futures_plan_order'] = $futuresPlanOrder;

        // MTF Audit
        $sql = "SELECT * FROM mtf_audit WHERE run_id = ?::uuid OR (symbol = ? AND trace_id = ANY(?::text[])) ORDER BY created_at";
        $traceIdsStr = '{' . implode(',', $traceIds) . '}';
        if ($showSql && $io) {
            $io->text('<fg=cyan>SQL:</> ' . str_replace(['?', '?', '?'], ["'$runId'", "'$symbol'", "'$traceIdsStr'"], $sql));
        }
        $data['mtf_audit'] = $this->connection->fetchAllAssociative($sql, [$runId, $symbol, $traceIdsStr]);

        // MTF Run
        $sql = "SELECT * FROM mtf_run WHERE run_id = ?::uuid";
        if ($showSql && $io) {
            $io->text('<fg=cyan>SQL:</> ' . str_replace('?', "'$runId'", $sql));
        }
        $data['mtf_run'] = $this->connection->fetchAllAssociative($sql, [$runId]);

        // MTF Run Symbol
        $sql = "SELECT * FROM mtf_run_symbol WHERE run_id = ?::uuid";
        if ($showSql && $io) {
            $io->text('<fg=cyan>SQL:</> ' . str_replace('?', "'$runId'", $sql));
        }
        $data['mtf_run_symbol'] = $this->connection->fetchAllAssociative($sql, [$runId]);

        // MTF Run Metric
        $sql = "SELECT * FROM mtf_run_metric WHERE run_id = ?::uuid";
        if ($showSql && $io) {
            $io->text('<fg=cyan>SQL:</> ' . str_replace('?', "'$runId'", $sql));
        }
        $data['mtf_run_metric'] = $this->connection->fetchAllAssociative($sql, [$runId]);

        // Trade Zone Events
        $sqlDecisionKey = "SELECT plan_id FROM trade_lifecycle_event WHERE run_id = ? AND plan_id IS NOT NULL LIMIT 1";
        if ($showSql && $io) {
            $io->text('<fg=cyan>SQL:</> ' . str_replace('?', "'$runId'", $sqlDecisionKey));
        }
        $decisionKey = $this->connection->fetchOne($sqlDecisionKey, [$runId]);

        $tradeZoneEvents = [];
        if ($symbol) {
            $query = "SELECT * FROM trade_zone_events WHERE symbol = ?";
            $params = [$symbol];
            
            if ($decisionKey) {
                $query .= " AND (decision_key = ? OR decision_key LIKE ?)";
                $params[] = $decisionKey;
                $params[] = "%$decisionKey%";
            }
            
            $query .= " AND happened_at >= (SELECT MIN(happened_at) FROM trade_lifecycle_event WHERE run_id = ?) - INTERVAL '1 hour'";
            $query .= " AND happened_at <= (SELECT MAX(happened_at) FROM trade_lifecycle_event WHERE run_id = ?) + INTERVAL '1 hour'";
            $query .= " ORDER BY happened_at";
            
            $params[] = $runId;
            $params[] = $runId;
            
            if ($showSql && $io) {
                $displayQuery = $query;
                foreach ($params as $param) {
                    $displayQuery = preg_replace('/\?/', "'$param'", $displayQuery, 1);
                }
                $io->text('<fg=cyan>SQL:</> ' . $displayQuery);
            }
            
            $tradeZoneEvents = $this->connection->fetchAllAssociative($query, $params);
        }
        $data['trade_zone_events'] = $tradeZoneEvents;

        // Indicator Snapshots (pour tous les trace_id)
        $indicators = [];
        if (!empty($traceIds)) {
            $placeholders = implode(',', array_fill(0, count($traceIds), '?'));
            $sql = "SELECT * FROM indicator_snapshots WHERE trace_id IN ($placeholders) ORDER BY kline_time";
            if ($showSql && $io) {
                $traceIdsStr = implode("', '", $traceIds);
                $io->text('<fg=cyan>SQL:</> ' . str_replace($placeholders, "'$traceIdsStr'", $sql));
            }
            $indicators = $this->connection->fetchAllAssociative($sql, $traceIds);
        }
        $data['indicator_snapshots'] = $indicators;

        return $data;
    }

    private function exportGlobalSymbolData(string $symbol, \DateTimeImmutable $startTime, \DateTimeImmutable $endTime, bool $showSql = false, ?SymfonyStyle $io = null): array
    {
        $data = [];

        // MTF State
        $sql = "SELECT * FROM mtf_state WHERE symbol = ? AND updated_at >= ? AND updated_at <= ? ORDER BY updated_at DESC";
        if ($showSql && $io) {
            $io->text('<fg=cyan>SQL:</> ' . str_replace(['?'], ["'$symbol'", "'" . $startTime->format('Y-m-d H:i:s') . "'", "'" . $endTime->format('Y-m-d H:i:s') . "'"], $sql));
        }
        $data['mtf_state'] = $this->connection->fetchAllAssociative(
            $sql,
            [$symbol, $startTime->format('Y-m-d H:i:s'), $endTime->format('Y-m-d H:i:s')]
        );

        // MTF Switch (dans la fenêtre de temps)
        try {
            $sql = "SELECT * FROM mtf_switch WHERE contract_symbol = ? AND created_at >= ? AND created_at <= ? ORDER BY created_at DESC";
            if ($showSql && $io) {
                $io->text('<fg=cyan>SQL:</> ' . str_replace(['?', '?', '?'], ["'$symbol'", "'" . $startTime->format('Y-m-d H:i:s') . "'", "'" . $endTime->format('Y-m-d H:i:s') . "'"], $sql));
            }
            $data['mtf_switch'] = $this->connection->fetchAllAssociative($sql, [$symbol, $startTime->format('Y-m-d H:i:s'), $endTime->format('Y-m-d H:i:s')]);
        } catch (\Exception $e) {
            $data['mtf_switch'] = [];
        }

        // MTF Lock (dans la fenêtre de temps)
        try {
            $sql = "SELECT * FROM mtf_lock WHERE lock_key LIKE ? AND acquired_at >= ? AND acquired_at <= ? ORDER BY acquired_at DESC";
            $lockKeyPattern = "%$symbol%";
            if ($showSql && $io) {
                $io->text('<fg=cyan>SQL:</> ' . str_replace(['?', '?', '?'], ["'$lockKeyPattern'", "'" . $startTime->format('Y-m-d H:i:s') . "'", "'" . $endTime->format('Y-m-d H:i:s') . "'"], $sql));
            }
            $data['mtf_lock'] = $this->connection->fetchAllAssociative($sql, [$lockKeyPattern, $startTime->format('Y-m-d H:i:s'), $endTime->format('Y-m-d H:i:s')]);
        } catch (\Exception $e) {
            $data['mtf_lock'] = [];
        }

        // Tous les Order Intent dans la fenêtre de temps
        $sql = "SELECT * FROM order_intent WHERE symbol = ? AND created_at >= ? AND created_at <= ? ORDER BY created_at DESC";
        if ($showSql && $io) {
            $io->text('<fg=cyan>SQL:</> ' . str_replace(['?', '?', '?'], ["'$symbol'", "'" . $startTime->format('Y-m-d H:i:s') . "'", "'" . $endTime->format('Y-m-d H:i:s') . "'"], $sql));
        }
        $data['order_intent_all'] = $this->connection->fetchAllAssociative($sql, [$symbol, $startTime->format('Y-m-d H:i:s'), $endTime->format('Y-m-d H:i:s')]);

        // Tous les Futures Order dans la fenêtre de temps
        $sql = "SELECT * FROM futures_order WHERE symbol = ? AND created_at >= ? AND created_at <= ? ORDER BY created_at DESC";
        if ($showSql && $io) {
            $io->text('<fg=cyan>SQL:</> ' . str_replace(['?', '?', '?'], ["'$symbol'", "'" . $startTime->format('Y-m-d H:i:s') . "'", "'" . $endTime->format('Y-m-d H:i:s') . "'"], $sql));
        }
        $data['futures_order_all'] = $this->connection->fetchAllAssociative($sql, [$symbol, $startTime->format('Y-m-d H:i:s'), $endTime->format('Y-m-d H:i:s')]);

        // Tous les Trade Lifecycle Events dans la fenêtre de temps
        $sql = "SELECT * FROM trade_lifecycle_event WHERE symbol = ? AND happened_at >= ? AND happened_at <= ? ORDER BY happened_at DESC";
        if ($showSql && $io) {
            $io->text('<fg=cyan>SQL:</> ' . str_replace(['?', '?', '?'], ["'$symbol'", "'" . $startTime->format('Y-m-d H:i:s') . "'", "'" . $endTime->format('Y-m-d H:i:s') . "'"], $sql));
        }
        $data['trade_lifecycle_event_all'] = $this->connection->fetchAllAssociative($sql, [$symbol, $startTime->format('Y-m-d H:i:s'), $endTime->format('Y-m-d H:i:s')]);

        // Tous les Trade Zone Events dans la fenêtre de temps
        $sql = "SELECT * FROM trade_zone_events WHERE symbol = ? AND happened_at >= ? AND happened_at <= ? ORDER BY happened_at DESC";
        if ($showSql && $io) {
            $io->text('<fg=cyan>SQL:</> ' . str_replace(['?', '?', '?'], ["'$symbol'", "'" . $startTime->format('Y-m-d H:i:s') . "'", "'" . $endTime->format('Y-m-d H:i:s') . "'"], $sql));
        }
        $data['trade_zone_events_all'] = $this->connection->fetchAllAssociative($sql, [$symbol, $startTime->format('Y-m-d H:i:s'), $endTime->format('Y-m-d H:i:s')]);

        // Tous les Indicator Snapshots dans la fenêtre de temps
        $sql = "SELECT * FROM indicator_snapshots WHERE symbol = ? AND inserted_at >= ? AND inserted_at <= ? ORDER BY kline_time DESC";
        if ($showSql && $io) {
            $io->text('<fg=cyan>SQL:</> ' . str_replace(['?', '?', '?'], ["'$symbol'", "'" . $startTime->format('Y-m-d H:i:s') . "'", "'" . $endTime->format('Y-m-d H:i:s') . "'"], $sql));
        }
        $data['indicator_snapshots_all'] = $this->connection->fetchAllAssociative($sql, [$symbol, $startTime->format('Y-m-d H:i:s'), $endTime->format('Y-m-d H:i:s')]);

        return $data;
    }

    private function exportLogsForDateTime(string $symbol, \DateTimeImmutable $targetDateTime, bool $showLogs = false, ?SymfonyStyle $io = null): array
    {
        $logData = [];
        $logDir = '/var/www/html/var/log';
        $dateStr = $targetDateTime->format('Y-m-d');
        
        // Fenêtre de ±5 minutes autour de la date cible
        $startTime = $targetDateTime->modify('-5 minutes');
        $endTime = $targetDateTime->modify('+5 minutes');
        
        $logFiles = [
            'positions' => $logDir . '/positions-' . $dateStr . '.log',
            'mtf' => $logDir . '/mtf-' . $dateStr . '.log',
            'signals' => $logDir . '/signals-' . $dateStr . '.log',
            'bitmart' => $logDir . '/bitmart-' . $dateStr . '.log',
            'provider' => $logDir . '/provider-' . $dateStr . '.log',
            'indicators' => $logDir . '/indicators-' . $dateStr . '.log',
            'dev' => $logDir . '/dev-' . $dateStr . '.log',
        ];

        foreach ($logFiles as $logType => $logFile) {
            if (!file_exists($logFile)) {
                continue;
            }

            $matchingLines = [];
            $handle = fopen($logFile, 'r');
            
            if ($handle === false) {
                continue;
            }

            // Lire ligne par ligne pour éviter de charger tout en mémoire
            while (($line = fgets($handle)) !== false) {
                // Vérifier d'abord si la ligne contient le symbole (plus rapide)
                if (stripos($line, $symbol) === false) {
                    continue;
                }

                // Extraire le timestamp de la ligne de log
                if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d+)\]/', $line, $matches)) {
                    try {
                        $lineTime = new \DateTimeImmutable($matches[1], new \DateTimeZone('UTC'));
                        
                        // Vérifier si la ligne est dans la fenêtre de temps
                        if ($lineTime >= $startTime && $lineTime <= $endTime) {
                            $matchingLines[] = rtrim($line);
                            
                            // Limiter à 1000 lignes par fichier pour éviter les fichiers trop volumineux
                            if (count($matchingLines) >= 1000) {
                                break;
                            }
                        }
                    } catch (\Exception $e) {
                        // Ignorer les lignes avec timestamp invalide
                    }
                }
            }

            fclose($handle);

            if (!empty($matchingLines)) {
                $logData[$logType] = $matchingLines;
            }
        }

        return $logData;
    }
}

