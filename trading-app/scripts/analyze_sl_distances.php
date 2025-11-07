<?php

declare(strict_types=1);

/**
 * Script d'analyse des distances SL depuis les logs
 */

$logFile = $argv[1] ?? '/var/www/html/var/log/positions-flow-debug-2025-11-05.log';
$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$lines = array_slice($lines, -2000); // Dernières 2000 lignes

echo "=== ANALYSE DES DISTANCES SL ===\n\n";

$pivotStops = [];
$riskStops = [];
$sequentialOrders = [];

foreach ($lines as $line) {
    // Extraire les SL basés sur pivot
    if (preg_match('/order_plan\.stop_and_tp.*?symbol=(\w+).*?entry=([0-9.]+).*?stop_from=pivot.*?stop_pivot=([0-9.]+).*?stop=([0-9.]+)/', $line, $matches)) {
        $symbol = $matches[1];
        $entry = (float)$matches[2];
        $stopPivot = (float)$matches[3];
        $stopFinal = (float)$matches[4];
        
        $distancePct = abs($entry - $stopFinal) / $entry * 100;
        
        $pivotStops[] = [
            'symbol' => $symbol,
            'entry' => $entry,
            'stop_pivot' => $stopPivot,
            'stop_final' => $stopFinal,
            'distance_pct' => $distancePct,
            'line' => $line
        ];
    }
    
    // Extraire les ajustements de distance minimale
    if (preg_match('/order_plan\.stop_min_distance_adjusted.*?symbol=(\w+).*?entry=([0-9.]+).*?stop_before=([0-9.]+).*?stop_after=([0-9.]+).*?reason=(\w+)/', $line, $matches)) {
        $symbol = $matches[1];
        $entry = (float)$matches[2];
        $stopBefore = (float)$matches[3];
        $stopAfter = (float)$matches[4];
        $reason = $matches[5];
        
        $distanceBefore = abs($entry - $stopBefore) / $entry * 100;
        $distanceAfter = abs($entry - $stopAfter) / $entry * 100;
        
        $riskStops[] = [
            'symbol' => $symbol,
            'entry' => $entry,
            'stop_before' => $stopBefore,
            'stop_after' => $stopAfter,
            'distance_before_pct' => $distanceBefore,
            'distance_after_pct' => $distanceAfter,
            'reason' => $reason,
            'line' => $line
        ];
    }
    
    // Extraire les ordres soumis
    if (preg_match('/Trade entry submitted.*?symbol=(\w+).*?status=(\w+).*?client_order_id=(\w+)/', $line, $matches)) {
        $timestamp = substr($line, 0, 19);
        $sequentialOrders[] = [
            'timestamp' => $timestamp,
            'symbol' => $matches[1],
            'status' => $matches[2],
            'client_order_id' => $matches[3]
        ];
    }
}

// Analyse des SL pivot
echo "1. SL BASÉS SUR PIVOT (derniers 20):\n";
echo str_repeat("-", 80) . "\n";
$pivotStops = array_slice($pivotStops, -20);
foreach ($pivotStops as $stop) {
    $status = $stop['distance_pct'] >= 0.5 ? '✅' : '⚠️';
    printf("%s %-15s Entry=%-10.6f Stop=%-10.6f Distance=%.4f%%\n",
        $status,
        $stop['symbol'],
        $stop['entry'],
        $stop['stop_final'],
        $stop['distance_pct']
    );
}

// Statistiques SL pivot
$pivotTooClose = array_filter($pivotStops, fn($s) => $s['distance_pct'] < 0.5);
echo "\n📊 Statistiques SL Pivot:\n";
echo "  - Total: " . count($pivotStops) . "\n";
echo "  - < 0.5%: " . count($pivotTooClose) . " ⚠️\n";
echo "  - >= 0.5%: " . (count($pivotStops) - count($pivotTooClose)) . " ✅\n";

// Analyse des ajustements
echo "\n\n2. AJUSTEMENTS DE DISTANCE MINIMALE (derniers 10):\n";
echo str_repeat("-", 80) . "\n";
$riskStops = array_slice($riskStops, -10);
foreach ($riskStops as $stop) {
    printf("%-15s Entry=%-10.6f Before=%-10.6f (%.4f%%) → After=%-10.6f (%.4f%%) [%s]\n",
        $stop['symbol'],
        $stop['entry'],
        $stop['stop_before'],
        $stop['distance_before_pct'],
        $stop['stop_after'],
        $stop['distance_after_pct'],
        $stop['reason']
    );
}

// Analyse des ordres séquentiels
echo "\n\n3. ORDRES SÉQUENTIELS (même seconde):\n";
echo str_repeat("-", 80) . "\n";
$bySecond = [];
foreach ($sequentialOrders as $order) {
    $second = substr($order['timestamp'], 0, 17); // jusqu'à la seconde
    if (!isset($bySecond[$second])) {
        $bySecond[$second] = [];
    }
    $bySecond[$second][] = $order;
}

$sequential = array_filter($bySecond, fn($orders) => count($orders) > 1);
foreach (array_slice($sequential, -10, null, true) as $second => $orders) {
    echo "$second: " . count($orders) . " ordres\n";
    foreach ($orders as $order) {
        echo "  - {$order['symbol']} ({$order['status']})\n";
    }
}

echo "\n✅ Analyse terminée\n";

