#!/usr/bin/env php
<?php
/**
 * Script de surveillance des logs pour détecter automatiquement les problèmes de trading
 * 
 * Détecte :
 * - Positions qui touchent le SL rapidement (< 5 minutes)
 * - Ordres multiples sur le même symbole dans un court délai (< 2 minutes)
 * - Distances SL trop serrées (< 0.3%)
 * - Patterns de pertes répétées
 * 
 * Usage:
 *   php scripts/monitor_trading_issues.php [--watch] [--last-hours=24] [--log-dir=var/log]
 */

declare(strict_types=1);

$logDir = getenv('APP_LOG_DIR') ?: __DIR__ . '/../var/log';
$watchMode = false;
$lastHours = 24;
$thresholdSlDistance = 0.003; // 0.3% minimum
$thresholdRapidClose = 300; // 5 minutes en secondes
$thresholdMultipleOrders = 120; // 2 minutes en secondes

// Parse arguments
$args = array_slice($argv, 1);
foreach ($args as $arg) {
    if ($arg === '--watch') {
        $watchMode = true;
    } elseif (str_starts_with($arg, '--last-hours=')) {
        $lastHours = (int) substr($arg, 14);
    } elseif (str_starts_with($arg, '--log-dir=')) {
        $logDir = substr($arg, 10);
    }
}

if (!is_dir($logDir)) {
    fwrite(STDERR, "Erreur: Répertoire de logs introuvable: {$logDir}\n");
    exit(1);
}

class TradingIssueDetector
{
    private array $positions = []; // symbol => [open_time, entry_price, stop_loss, ...]
    private array $orders = []; // symbol => [timestamp, ...]
    private array $issues = [];
    private float $thresholdSlDistance;
    private int $thresholdRapidClose;
    private int $thresholdMultipleOrders;

    public function __construct(
        float $thresholdSlDistance,
        int $thresholdRapidClose,
        int $thresholdMultipleOrders
    ) {
        $this->thresholdSlDistance = $thresholdSlDistance;
        $this->thresholdRapidClose = $thresholdRapidClose;
        $this->thresholdMultipleOrders = $thresholdMultipleOrders;
    }

    /**
     * Analyse une ligne de log
     */
    public function analyzeLine(string $line, string $logFile): void
    {
        // Parser la ligne de log (format: [timestamp][channel.level]: message {json})
        if (!preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{3})?)\][^:]+: (.+)/', $line, $matches)) {
            return;
        }

        $timestamp = $matches[1];
        $message = $matches[2];
        $timestampObj = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.v', $timestamp)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $timestamp);

        if (!$timestampObj) {
            return;
        }

        // Extraire JSON si présent
        $jsonData = null;
        if (preg_match('/\{.*\}/', $message, $jsonMatch)) {
            try {
                $jsonData = json_decode($jsonMatch[0], true);
            } catch (\Throwable) {
                // Ignore
            }
        }

        // Détecter ouverture de position
        if (strpos($message, 'execute_order_plan.submitted') !== false || 
            strpos($message, 'order_journey.trade_entry.submitted') !== false) {
            $this->detectOrderSubmission($timestampObj, $jsonData, $logFile);
        }

        // Détecter fermeture de position (SL)
        if (strpos($message, 'Clôturer la position') !== false || 
            strpos($message, 'close_long') !== false || 
            strpos($message, 'close_short') !== false ||
            (strpos($message, 'Profits et pertes') !== false && strpos($message, '-') !== false)) {
            $this->detectPositionClose($timestampObj, $jsonData, $message, $logFile);
        }

        // Détecter calcul de SL
        if (strpos($message, 'order_plan.sizing') !== false || 
            strpos($message, 'stop_min_distance') !== false ||
            strpos($message, 'pivot_stop') !== false) {
            $this->detectStopLossCalculation($timestampObj, $jsonData, $logFile);
        }

        // Détecter ordres multiples
        if (strpos($message, 'order.submit.attempt') !== false || 
            strpos($message, 'order_journey.execution.attempt') !== false) {
            $this->detectMultipleOrders($timestampObj, $jsonData, $logFile);
        }
    }

    private function detectOrderSubmission(\DateTimeImmutable $timestamp, ?array $data, string $logFile): void
    {
        if (!$data || !isset($data['symbol'])) {
            return;
        }

        $symbol = strtoupper($data['symbol'] ?? '');
        if ($symbol === '') {
            return;
        }

        // Enregistrer l'ordre
        if (!isset($this->orders[$symbol])) {
            $this->orders[$symbol] = [];
        }
        $this->orders[$symbol][] = $timestamp->getTimestamp();

        // Vérifier si plusieurs ordres récents sur le même symbole
        $recentOrders = array_filter(
            $this->orders[$symbol],
            fn($ts) => ($timestamp->getTimestamp() - $ts) <= $this->thresholdMultipleOrders
        );

        if (count($recentOrders) >= 2) {
            $count = count($recentOrders);
            $this->addIssue('multiple_orders', [
                'symbol' => $symbol,
                'count' => $count,
                'timeframe' => $this->thresholdMultipleOrders . 's',
                'timestamp' => $timestamp->format('Y-m-d H:i:s'),
                'log_file' => basename($logFile),
                'severity' => 'high',
                'message' => "⚠️  ORDRES MULTIPLES: {$symbol} a {$count} ordres dans les {$this->thresholdMultipleOrders}s",
            ]);
        }

        // Enregistrer position ouverte si c'est un ordre d'entrée
        $side = $data['side'] ?? null;
        if (in_array($side, ['1', '4', 'LONG', 'SHORT'], true)) {
            $this->positions[$symbol] = [
                'open_time' => $timestamp,
                'entry_price' => $data['entry'] ?? $data['price'] ?? null,
                'symbol' => $symbol,
                'side' => $side,
                'size' => $data['size'] ?? null,
            ];
        }
    }

    private function detectPositionClose(\DateTimeImmutable $timestamp, ?array $data, string $message, string $logFile): void
    {
        // Extraire symbole et P&L depuis le message
        $symbol = null;
        $pnl = null;

        if ($data) {
            $symbol = strtoupper($data['symbol'] ?? '');
            $pnl = $data['pnl'] ?? $data['profits_et_pertes'] ?? null;
        }

        // Extraire depuis le message si pas dans JSON
        if (!$symbol && preg_match('/\[([A-Z0-9]+)\]/', $message, $m)) {
            $symbol = $m[1];
        }
        if ($pnl === null && preg_match('/(-?\d+\.?\d*)\s*USDT/', $message, $m)) {
            $pnl = (float) $m[1];
        }

        if (!$symbol || !isset($this->positions[$symbol])) {
            return;
        }

        $position = $this->positions[$symbol];
        $openTime = $position['open_time'];
        $duration = $timestamp->getTimestamp() - $openTime->getTimestamp();

        // Détecter fermeture rapide avec perte
        if ($duration < $this->thresholdRapidClose && ($pnl === null || $pnl < 0)) {
            $this->addIssue('rapid_sl_hit', [
                'symbol' => $symbol,
                'duration_seconds' => $duration,
                'duration_formatted' => gmdate('H:i:s', $duration),
                'pnl' => $pnl,
                'open_time' => $openTime->format('Y-m-d H:i:s'),
                'close_time' => $timestamp->format('Y-m-d H:i:s'),
                'log_file' => basename($logFile),
                'severity' => 'critical',
                'message' => "🚨 SL RAPIDE: {$symbol} fermé en " . gmdate('H:i:s', $duration) . 
                            ($pnl !== null ? " avec perte de {$pnl} USDT" : ''),
            ]);
        }

        // Supprimer la position après fermeture
        unset($this->positions[$symbol]);
    }

    private function detectStopLossCalculation(\DateTimeImmutable $timestamp, ?array $data, string $logFile): void
    {
        if (!$data || !isset($data['symbol'])) {
            return;
        }

        $symbol = strtoupper($data['symbol'] ?? '');
        $entry = $data['entry'] ?? $data['entry_price'] ?? null;
        $stop = $data['stop'] ?? $data['stop_loss'] ?? $data['stop_after'] ?? null;

        if (!$symbol || !$entry || !$stop) {
            return;
        }

        $entryFloat = (float) $entry;
        $stopFloat = (float) $stop;
        $distance = abs($entryFloat - $stopFloat) / max($entryFloat, 0.0001);
        $distancePct = $distance * 100;

        // Détecter SL trop serré
        if ($distance < $this->thresholdSlDistance) {
            $this->addIssue('tight_stop_loss', [
                'symbol' => $symbol,
                'entry' => $entryFloat,
                'stop' => $stopFloat,
                'distance_pct' => round($distancePct, 4),
                'threshold_pct' => $this->thresholdSlDistance * 100,
                'timestamp' => $timestamp->format('Y-m-d H:i:s'),
                'log_file' => basename($logFile),
                'severity' => 'high',
                'message' => "⚠️  SL TROP SERRÉ: {$symbol} SL à {$distancePct}% (seuil: " . ($this->thresholdSlDistance * 100) . "%)",
            ]);
        }
    }

    private function detectMultipleOrders(\DateTimeImmutable $timestamp, ?array $data, string $logFile): void
    {
        // Déjà détecté dans detectOrderSubmission, mais on peut ajouter plus de détails ici
        if (!$data || !isset($data['symbol'])) {
            return;
        }

        $symbol = strtoupper($data['symbol'] ?? '');
        if ($symbol === '' || !isset($this->orders[$symbol])) {
            return;
        }

        // Compter les ordres dans la fenêtre de temps
        $recentCount = count(array_filter(
            $this->orders[$symbol],
            fn($ts) => ($timestamp->getTimestamp() - $ts) <= $this->thresholdMultipleOrders
        ));

        if ($recentCount >= 3) {
            $this->addIssue('multiple_orders_critical', [
                'symbol' => $symbol,
                'count' => $recentCount,
                'timeframe' => $this->thresholdMultipleOrders . 's',
                'timestamp' => $timestamp->format('Y-m-d H:i:s'),
                'log_file' => basename($logFile),
                'severity' => 'critical',
                'message' => "🚨 ORDRES MULTIPLES CRITIQUE: {$symbol} a {$recentCount} ordres dans les {$this->thresholdMultipleOrders}s",
            ]);
        }
    }

    private function addIssue(string $type, array $details): void
    {
        $key = $type . '_' . ($details['symbol'] ?? 'unknown') . '_' . ($details['timestamp'] ?? time());
        
        // Éviter les doublons dans les 5 dernières secondes
        if (isset($this->issues[$key])) {
            $existing = $this->issues[$key];
            $existingTime = strtotime($existing['timestamp'] ?? '0');
            $newTime = strtotime($details['timestamp'] ?? '0');
            if (abs($newTime - $existingTime) < 5) {
                return; // Doublon
            }
        }

        $this->issues[$key] = array_merge(['type' => $type], $details);
    }

    public function getIssues(): array
    {
        return $this->issues;
    }

    public function clearOldData(int $maxAgeSeconds): void
    {
        $now = time();
        
        // Nettoyer les positions anciennes
        foreach ($this->positions as $symbol => $position) {
            $age = $now - $position['open_time']->getTimestamp();
            if ($age > $maxAgeSeconds) {
                unset($this->positions[$symbol]);
            }
        }

        // Nettoyer les ordres anciens
        foreach ($this->orders as $symbol => $timestamps) {
            $this->orders[$symbol] = array_filter(
                $timestamps,
                fn($ts) => ($now - $ts) <= $maxAgeSeconds
            );
            
            if (empty($this->orders[$symbol])) {
                unset($this->orders[$symbol]);
            }
        }
    }
}

/**
 * Lit les lignes récentes d'un fichier de log
 */
function readRecentLogLines(string $filePath, int $hours): array
{
    if (!file_exists($filePath)) {
        return [];
    }

    $cutoffTime = time() - ($hours * 3600);
    $lines = [];
    $handle = fopen($filePath, 'r');
    
    if (!$handle) {
        return [];
    }

    // Lire depuis la fin (plus efficace pour les gros fichiers)
    fseek($handle, -1, SEEK_END);
    $pos = ftell($handle);
    $currentLine = '';
    
    while ($pos >= 0) {
        $char = fgetc($handle);
        if ($char === "\n") {
            if ($currentLine !== '') {
                // Extraire timestamp et vérifier
                if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $currentLine, $m)) {
                    $lineTime = strtotime($m[1]);
                    if ($lineTime >= $cutoffTime) {
                        array_unshift($lines, $currentLine);
                    } else {
                        break; // Trop ancien
                    }
                }
                $currentLine = '';
            }
        } else {
            $currentLine = $char . $currentLine;
        }
        $pos--;
        fseek($handle, $pos, SEEK_SET);
    }
    
    fclose($handle);
    return $lines;
}

/**
 * Surveille un fichier en temps réel (tail -f style)
 */
function watchLogFile(string $filePath, callable $callback): void
{
    if (!file_exists($filePath)) {
        fwrite(STDERR, "Fichier introuvable: {$filePath}\n");
        return;
    }

    $handle = fopen($filePath, 'r');
    if (!$handle) {
        return;
    }

    // Aller à la fin du fichier
    fseek($handle, 0, SEEK_END);

    while (true) {
        $line = fgets($handle);
        if ($line !== false) {
            $callback(trim($line));
        } else {
            usleep(100000); // 100ms
            clearstatcache();
            $currentSize = filesize($filePath);
            if ($currentSize < ftell($handle)) {
                // Fichier a été tronqué (rotation), rouvrir
                fclose($handle);
                $handle = fopen($filePath, 'r');
                fseek($handle, 0, SEEK_END);
            }
        }
    }
}

// Fichiers de logs à surveiller
$logFiles = [
    'positions.log',
    'positions-flow.log',
    'order.log',
    'order-journey.log',
];

$detector = new TradingIssueDetector($thresholdSlDistance, $thresholdRapidClose, $thresholdMultipleOrders);

if ($watchMode) {
    echo "🔍 Surveillance en temps réel des logs...\n";
    echo "Appuyez sur Ctrl+C pour arrêter\n\n";

    $callbacks = [];
    foreach ($logFiles as $logFile) {
        $fullPath = $logDir . '/' . $logFile;
        if (file_exists($fullPath)) {
            $callbacks[] = function($line) use ($detector, $fullPath) {
                $detector->analyzeLine($line, $fullPath);
                
                // Afficher les nouveaux problèmes immédiatement
                $issues = $detector->getIssues();
                foreach ($issues as $issue) {
                    echo "[" . date('Y-m-d H:i:s') . "] " . $issue['message'] . "\n";
                }
            };
        }
    }

    // Surveiller tous les fichiers en parallèle (simplifié: un seul fichier à la fois)
    if (!empty($callbacks)) {
        watchLogFile($logDir . '/' . $logFiles[0], $callbacks[0]);
    }
} else {
    echo "🔍 Analyse des logs des dernières {$lastHours}h...\n\n";

    foreach ($logFiles as $logFile) {
        $fullPath = $logDir . '/' . $logFile;
        if (file_exists($fullPath)) {
            $lines = readRecentLogLines($fullPath, $lastHours);
            foreach ($lines as $line) {
                $detector->analyzeLine($line, $fullPath);
            }
        }
    }

    $detector->clearOldData($lastHours * 3600);

    // Afficher les résultats
    $issues = $detector->getIssues();
    
    if (empty($issues)) {
        echo "✅ Aucun problème détecté\n";
        exit(0);
    }

    echo "⚠️  PROBLÈMES DÉTECTÉS:\n";
    echo str_repeat('=', 80) . "\n\n";

    // Grouper par type
    $byType = [];
    foreach ($issues as $issue) {
        $type = $issue['type'];
        if (!isset($byType[$type])) {
            $byType[$type] = [];
        }
        $byType[$type][] = $issue;
    }

    foreach ($byType as $type => $typeIssues) {
        $count = count($typeIssues);
        $severity = $typeIssues[0]['severity'] ?? 'medium';
        $icon = $severity === 'critical' ? '🚨' : '⚠️';
        
        echo "{$icon} {$type}: {$count} occurrence(s)\n";
        echo str_repeat('-', 80) . "\n";
        
        foreach ($typeIssues as $issue) {
            echo "  • " . $issue['message'] . "\n";
            if (isset($issue['timestamp'])) {
                echo "    Timestamp: {$issue['timestamp']}\n";
            }
            if (isset($issue['log_file'])) {
                echo "    Log: {$issue['log_file']}\n";
            }
            echo "\n";
        }
        echo "\n";
    }

    echo str_repeat('=', 80) . "\n";
    echo "Total: " . count($issues) . " problème(s) détecté(s)\n";
    
    exit(count($issues) > 0 ? 1 : 0);
}

