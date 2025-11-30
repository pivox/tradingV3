<?php

declare(strict_types=1);

/**
 * Script d'export des données persistées pour un trace_id et run_id
 * 
 * Usage: php scripts/export_execution_data.php <trace_id> <run_id> [output_dir]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

// Charger les variables d'environnement
$dotenv = new Dotenv();
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $dotenv->loadEnv($envFile);
} elseif (file_exists($envFile . '.local')) {
    $dotenv->loadEnv($envFile . '.local');
}

use Doctrine\DBAL\DriverManager;

$traceId = $argv[1] ?? null;
$runId = $argv[2] ?? null;
$outputDir = $argv[3] ?? '/tmp';

if (!$traceId || !$runId) {
    echo "Usage: php scripts/export_execution_data.php <trace_id> <run_id> [output_dir]\n";
    echo "Example: php scripts/export_execution_data.php PEOPLEUSDT-104323 9ae327c7-1939-4221-9d24-9698ff1d3039\n";
    exit(1);
}

// Connexion à la base de données
$connectionParams = [
    'url' => $_ENV['DATABASE_URL'] ?? 'postgresql://postgres:password@localhost:5432/trading_app',
];

$conn = DriverManager::getConnection($connectionParams);

$exportData = [
    'metadata' => [
        'trace_id' => $traceId,
        'run_id' => $runId,
        'exported_at' => date('Y-m-d H:i:s'),
    ],
    'data' => []
];

echo "🔍 Export des données pour trace_id: $traceId, run_id: $runId\n\n";

// 1. Indicator snapshots (par trace_id)
echo "📊 Export indicator_snapshots...\n";
$indicators = $conn->fetchAllAssociative(
    "SELECT * FROM indicator_snapshots WHERE trace_id = ? ORDER BY kline_time",
    [$traceId]
);
$exportData['data']['indicator_snapshots'] = $indicators;
echo "   ✅ " . count($indicators) . " enregistrements\n";

// 2. MTF tables (par run_id ou trace_id)
echo "📊 Export tables MTF...\n";

// mtf_audit
$mtfAudit = $conn->fetchAllAssociative(
    "SELECT * FROM mtf_audit WHERE run_id = ? OR trace_id = ? ORDER BY created_at",
    [$runId, $traceId]
);
$exportData['data']['mtf_audit'] = $mtfAudit;
echo "   ✅ mtf_audit: " . count($mtfAudit) . " enregistrements\n";

// mtf_run
$mtfRun = $conn->fetchAllAssociative(
    "SELECT * FROM mtf_run WHERE id = ?::uuid",
    [$runId]
);
$exportData['data']['mtf_run'] = $mtfRun;
echo "   ✅ mtf_run: " . count($mtfRun) . " enregistrements\n";

// mtf_run_symbol
$mtfRunSymbol = $conn->fetchAllAssociative(
    "SELECT * FROM mtf_run_symbol WHERE run_id = ?::uuid",
    [$runId]
);
$exportData['data']['mtf_run_symbol'] = $mtfRunSymbol;
echo "   ✅ mtf_run_symbol: " . count($mtfRunSymbol) . " enregistrements\n";

// mtf_run_metric
$mtfRunMetric = $conn->fetchAllAssociative(
    "SELECT * FROM mtf_run_metric WHERE run_id = ?::uuid",
    [$runId]
);
$exportData['data']['mtf_run_metric'] = $mtfRunMetric;
echo "   ✅ mtf_run_metric: " . count($mtfRunMetric) . " enregistrements\n";

// mtf_switch
$mtfSwitch = $conn->fetchAllAssociative(
    "SELECT * FROM mtf_switch WHERE symbol = (SELECT symbol FROM mtf_run WHERE id = ?::uuid LIMIT 1) ORDER BY created_at DESC LIMIT 10",
    [$runId]
);
$exportData['data']['mtf_switch'] = $mtfSwitch;
echo "   ✅ mtf_switch: " . count($mtfSwitch) . " enregistrements\n";

// mtf_lock
$mtfLock = $conn->fetchAllAssociative(
    "SELECT * FROM mtf_lock WHERE symbol = (SELECT symbol FROM mtf_run WHERE id = ?::uuid LIMIT 1) ORDER BY created_at DESC LIMIT 10",
    [$runId]
);
$exportData['data']['mtf_lock'] = $mtfLock;
echo "   ✅ mtf_lock: " . count($mtfLock) . " enregistrements\n";

// mtf_state
$mtfState = $conn->fetchAllAssociative(
    "SELECT * FROM mtf_state WHERE symbol = (SELECT symbol FROM mtf_run WHERE id = ?::uuid LIMIT 1) ORDER BY updated_at DESC LIMIT 10",
    [$runId]
);
$exportData['data']['mtf_state'] = $mtfState;
echo "   ✅ mtf_state: " . count($mtfState) . " enregistrements\n";

// 3. Order Intent (par client_order_id ou symbol)
echo "📊 Export order_intent...\n";
// Récupérer le client_order_id depuis trade_lifecycle_event
$clientOrderId = $conn->fetchOne(
    "SELECT client_order_id FROM trade_lifecycle_event WHERE run_id = ? LIMIT 1",
    [$runId]
);

if ($clientOrderId) {
    $orderIntent = $conn->fetchAllAssociative(
        "SELECT * FROM order_intent WHERE client_order_id = ?",
        [$clientOrderId]
    );
} else {
    // Fallback: chercher par symbol et date
    $symbol = $conn->fetchOne(
        "SELECT symbol FROM trade_lifecycle_event WHERE run_id = ? LIMIT 1",
        [$runId]
    );
    if ($symbol) {
        $orderIntent = $conn->fetchAllAssociative(
            "SELECT * FROM order_intent WHERE symbol = ? AND created_at >= (SELECT MIN(happened_at) FROM trade_lifecycle_event WHERE run_id = ?) - INTERVAL '1 hour' ORDER BY created_at DESC LIMIT 10",
            [$symbol, $runId]
        );
    } else {
        $orderIntent = [];
    }
}
$exportData['data']['order_intent'] = $orderIntent;
echo "   ✅ order_intent: " . count($orderIntent) . " enregistrements\n";

// 4. Futures Order tables
echo "📊 Export futures_order tables...\n";

// futures_order
$futuresOrder = [];
if ($clientOrderId) {
    $futuresOrder = $conn->fetchAllAssociative(
        "SELECT * FROM futures_order WHERE client_order_id = ?",
        [$clientOrderId]
    );
} else {
    // Chercher par order_id depuis trade_lifecycle_event
    $orderId = $conn->fetchOne(
        "SELECT order_id FROM trade_lifecycle_event WHERE run_id = ? AND order_id IS NOT NULL LIMIT 1",
        [$runId]
    );
    if ($orderId) {
        $futuresOrder = $conn->fetchAllAssociative(
            "SELECT * FROM futures_order WHERE order_id = ?",
            [$orderId]
        );
    }
}
$exportData['data']['futures_order'] = $futuresOrder;
echo "   ✅ futures_order: " . count($futuresOrder) . " enregistrements\n";

// futures_order_trade
$futuresOrderTrade = [];
if (!empty($futuresOrder)) {
    $orderIds = array_column($futuresOrder, 'id');
    if (!empty($orderIds)) {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $futuresOrderTrade = $conn->fetchAllAssociative(
            "SELECT * FROM futures_order_trade WHERE futures_order_id IN ($placeholders)",
            $orderIds
        );
    }
}
$exportData['data']['futures_order_trade'] = $futuresOrderTrade;
echo "   ✅ futures_order_trade: " . count($futuresOrderTrade) . " enregistrements\n";

// futures_plan_order
$futuresPlanOrder = [];
if ($clientOrderId) {
    $futuresPlanOrder = $conn->fetchAllAssociative(
        "SELECT * FROM futures_plan_order WHERE client_order_id = ?",
        [$clientOrderId]
    );
}
$exportData['data']['futures_plan_order'] = $futuresPlanOrder;
echo "   ✅ futures_plan_order: " . count($futuresPlanOrder) . " enregistrements\n";

// 5. Trade Lifecycle Event
echo "📊 Export trade_lifecycle_event...\n";
$tradeLifecycleEvents = $conn->fetchAllAssociative(
    "SELECT * FROM trade_lifecycle_event WHERE run_id = ? OR client_order_id IN (SELECT client_order_id FROM trade_lifecycle_event WHERE run_id = ?) ORDER BY happened_at",
    [$runId, $runId]
);
$exportData['data']['trade_lifecycle_event'] = $tradeLifecycleEvents;
echo "   ✅ trade_lifecycle_event: " . count($tradeLifecycleEvents) . " enregistrements\n";

// 6. Trade Zone Events
echo "📊 Export trade_zone_events...\n";
$symbol = $conn->fetchOne(
    "SELECT symbol FROM trade_lifecycle_event WHERE run_id = ? LIMIT 1",
    [$runId]
);
$decisionKey = $conn->fetchOne(
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
    
    $tradeZoneEvents = $conn->fetchAllAssociative($query, $params);
}
$exportData['data']['trade_zone_events'] = $tradeZoneEvents;
echo "   ✅ trade_zone_events: " . count($tradeZoneEvents) . " enregistrements\n";

// Sauvegarder en JSON
$filename = $outputDir . '/execution_data_' . str_replace(':', '-', $traceId) . '_' . substr($runId, 0, 8) . '.json';
file_put_contents($filename, json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "\n✅ Export terminé!\n";
echo "📄 Fichier: $filename\n";
echo "📊 Total enregistrements: " . array_sum(array_map('count', $exportData['data'])) . "\n";

