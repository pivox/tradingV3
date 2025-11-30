<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand(
    name: 'app:export-execution-data',
    description: 'Export persisted data for a trace_id and run_id'
)]
final class ExportExecutionDataCommand extends Command
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
            ->addArgument('trace_id', InputArgument::REQUIRED, 'Trace ID')
            ->addArgument('run_id', InputArgument::REQUIRED, 'Run ID')
            ->addArgument('output_dir', InputArgument::OPTIONAL, 'Output directory (default: investigation/ in project root)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $traceId = $input->getArgument('trace_id');
        $runId = $input->getArgument('run_id');
        $outputDir = $input->getArgument('output_dir');

        // Si aucun répertoire spécifié, utiliser investigation/ monté dans le container
        if (!$outputDir) {
            $outputDir = '/var/www/investigation';
        }

        // Créer le répertoire s'il n'existe pas
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $exportData = [
            'metadata' => [
                'trace_id' => $traceId,
                'run_id' => $runId,
                'exported_at' => date('Y-m-d H:i:s'),
            ],
            'data' => []
        ];

        $io->title("Export des données pour trace_id: $traceId, run_id: $runId");

        // 1. Indicator snapshots
        $io->section('Indicator Snapshots');
        $indicators = $this->connection->fetchAllAssociative(
            "SELECT * FROM indicator_snapshots WHERE trace_id = ? ORDER BY kline_time",
            [$traceId]
        );
        $exportData['data']['indicator_snapshots'] = $indicators;
        $io->success(count($indicators) . ' enregistrements');

        // 2. MTF tables
        $io->section('Tables MTF');
        
        // mtf_audit
        $mtfAudit = $this->connection->fetchAllAssociative(
            "SELECT * FROM mtf_audit WHERE run_id = ?::uuid OR trace_id = ? ORDER BY created_at",
            [$runId, $traceId]
        );
        $exportData['data']['mtf_audit'] = $mtfAudit;
        $io->text('mtf_audit: ' . count($mtfAudit) . ' enregistrements');

        // mtf_run
        $mtfRun = $this->connection->fetchAllAssociative(
            "SELECT * FROM mtf_run WHERE run_id = ?::uuid",
            [$runId]
        );
        $exportData['data']['mtf_run'] = $mtfRun;
        $io->text('mtf_run: ' . count($mtfRun) . ' enregistrements');

        // mtf_run_symbol
        $mtfRunSymbol = $this->connection->fetchAllAssociative(
            "SELECT * FROM mtf_run_symbol WHERE run_id = ?::uuid",
            [$runId]
        );
        $exportData['data']['mtf_run_symbol'] = $mtfRunSymbol;
        $io->text('mtf_run_symbol: ' . count($mtfRunSymbol) . ' enregistrements');

        // mtf_run_metric
        $mtfRunMetric = $this->connection->fetchAllAssociative(
            "SELECT * FROM mtf_run_metric WHERE run_id = ?::uuid",
            [$runId]
        );
        $exportData['data']['mtf_run_metric'] = $mtfRunMetric;
        $io->text('mtf_run_metric: ' . count($mtfRunMetric) . ' enregistrements');

        // mtf_switch
        $symbol = $this->connection->fetchOne(
            "SELECT symbol FROM mtf_run_symbol WHERE run_id = ?::uuid LIMIT 1",
            [$runId]
        );
        if ($symbol) {
            $mtfSwitch = $this->connection->fetchAllAssociative(
                "SELECT * FROM mtf_switch WHERE symbol = ? ORDER BY created_at DESC LIMIT 10",
                [$symbol]
            );
            $exportData['data']['mtf_switch'] = $mtfSwitch;
            $io->text('mtf_switch: ' . count($mtfSwitch) . ' enregistrements');
        }

        // mtf_lock - récupérer par run_id ou contract_symbol
        if (!$symbol) {
            $symbol = $this->connection->fetchOne(
                "SELECT symbol FROM trade_lifecycle_event WHERE run_id = ? LIMIT 1",
                [$runId]
            );
        }
        $mtfLock = [];
        if ($symbol) {
            // Essayer avec contract_symbol d'abord
            try {
                $mtfLock = $this->connection->fetchAllAssociative(
                    "SELECT * FROM mtf_lock WHERE contract_symbol = ? ORDER BY created_at DESC LIMIT 10",
                    [$symbol]
                );
            } catch (\Exception $e) {
                // Si contract_symbol n'existe pas, essayer sans filtre
                try {
                    $mtfLock = $this->connection->fetchAllAssociative(
                        "SELECT * FROM mtf_lock ORDER BY created_at DESC LIMIT 10"
                    );
                } catch (\Exception $e2) {
                    // Table peut ne pas exister
                }
            }
        }
        $exportData['data']['mtf_lock'] = $mtfLock;
        $io->text('mtf_lock: ' . count($mtfLock) . ' enregistrements');

        // mtf_state
        if ($symbol) {
            $mtfState = $this->connection->fetchAllAssociative(
                "SELECT * FROM mtf_state WHERE symbol = ? ORDER BY updated_at DESC LIMIT 10",
                [$symbol]
            );
            $exportData['data']['mtf_state'] = $mtfState;
            $io->text('mtf_state: ' . count($mtfState) . ' enregistrements');
        }

        // 3. Order Intent
        $io->section('Order Intent');
        $clientOrderId = $this->connection->fetchOne(
            "SELECT client_order_id FROM trade_lifecycle_event WHERE run_id = ? LIMIT 1",
            [$runId]
        );

        $orderIntent = [];
        if ($clientOrderId) {
            $orderIntent = $this->connection->fetchAllAssociative(
                "SELECT * FROM order_intent WHERE client_order_id = ?",
                [$clientOrderId]
            );
        } elseif ($symbol) {
            $orderIntent = $this->connection->fetchAllAssociative(
                "SELECT * FROM order_intent WHERE symbol = ? AND created_at >= (SELECT MIN(happened_at) FROM trade_lifecycle_event WHERE run_id = ?) - INTERVAL '1 hour' ORDER BY created_at DESC LIMIT 10",
                [$symbol, $runId]
            );
        }
        $exportData['data']['order_intent'] = $orderIntent;
        $io->success(count($orderIntent) . ' enregistrements');

        // 4. Futures Order tables
        $io->section('Futures Order Tables');
        
        $futuresOrder = [];
        if ($clientOrderId) {
            $futuresOrder = $this->connection->fetchAllAssociative(
                "SELECT * FROM futures_order WHERE client_order_id = ?",
                [$clientOrderId]
            );
        } else {
            $orderId = $this->connection->fetchOne(
                "SELECT order_id FROM trade_lifecycle_event WHERE run_id = ? AND order_id IS NOT NULL LIMIT 1",
                [$runId]
            );
            if ($orderId) {
                $futuresOrder = $this->connection->fetchAllAssociative(
                    "SELECT * FROM futures_order WHERE order_id = ?",
                    [$orderId]
                );
            }
        }
        $exportData['data']['futures_order'] = $futuresOrder;
        $io->text('futures_order: ' . count($futuresOrder) . ' enregistrements');

        // futures_order_trade
        $futuresOrderTrade = [];
        if (!empty($futuresOrder)) {
            $orderIds = array_column($futuresOrder, 'id');
            if (!empty($orderIds)) {
                $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
                $futuresOrderTrade = $this->connection->fetchAllAssociative(
                    "SELECT * FROM futures_order_trade WHERE futures_order_id IN ($placeholders)",
                    $orderIds
                );
            }
        }
        $exportData['data']['futures_order_trade'] = $futuresOrderTrade;
        $io->text('futures_order_trade: ' . count($futuresOrderTrade) . ' enregistrements');

        // futures_plan_order
        $futuresPlanOrder = [];
        if ($clientOrderId) {
            $futuresPlanOrder = $this->connection->fetchAllAssociative(
                "SELECT * FROM futures_plan_order WHERE client_order_id = ?",
                [$clientOrderId]
            );
        }
        $exportData['data']['futures_plan_order'] = $futuresPlanOrder;
        $io->text('futures_plan_order: ' . count($futuresPlanOrder) . ' enregistrements');

        // 5. Trade Lifecycle Event
        $io->section('Trade Lifecycle Event');
        $tradeLifecycleEvents = $this->connection->fetchAllAssociative(
            "SELECT * FROM trade_lifecycle_event WHERE run_id = ? OR client_order_id IN (SELECT client_order_id FROM trade_lifecycle_event WHERE run_id = ?) ORDER BY happened_at",
            [$runId, $runId]
        );
        $exportData['data']['trade_lifecycle_event'] = $tradeLifecycleEvents;
        $io->success(count($tradeLifecycleEvents) . ' enregistrements');

        // 6. Trade Zone Events
        $io->section('Trade Zone Events');
        if (!$symbol) {
            $symbol = $this->connection->fetchOne(
                "SELECT symbol FROM trade_lifecycle_event WHERE run_id = ? LIMIT 1",
                [$runId]
            );
        }
        $decisionKey = $this->connection->fetchOne(
            "SELECT plan_id FROM trade_lifecycle_event WHERE run_id = ? AND plan_id IS NOT NULL LIMIT 1",
            [$runId]
        );

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
            
            $tradeZoneEvents = $this->connection->fetchAllAssociative($query, $params);
        }
        $exportData['data']['trade_zone_events'] = $tradeZoneEvents;
        $io->success(count($tradeZoneEvents) . ' enregistrements');

        // Sauvegarder en JSON
        $filename = $outputDir . '/execution_data_' . str_replace(':', '-', $traceId) . '_' . substr($runId, 0, 8) . '.json';
        file_put_contents($filename, json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $total = array_sum(array_map('count', $exportData['data']));
        $io->success([
            "Export terminé!",
            "Fichier: $filename",
            "Total enregistrements: $total"
        ]);

        return Command::SUCCESS;
    }
}

