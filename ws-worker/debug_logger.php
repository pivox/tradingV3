<?php
/**
 * Script de logging pour capturer les données WebSocket
 */

function debugLog($message, $data = null) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}";
    
    if ($data !== null) {
        $logMessage .= "\n" . json_encode($data, JSON_PRETTY_PRINT);
    }
    
    // Écrire dans un fichier de log
    file_put_contents('/tmp/websocket_debug.log', $logMessage . "\n\n", FILE_APPEND | LOCK_EX);
    
    // Également dans error_log pour Docker
    error_log($logMessage);
}

// Test du logger
debugLog("=== Test du système de logging ===");
debugLog("Test avec des données simulées", [
    'group' => 'futures/klineBin1m:BTCUSDT',
    'data' => [
        'symbol' => 'BTCUSDT',
        'o' => '50000.0',
        'h' => '50100.0',
        'l' => '49900.0',
        'c' => '50050.0',
        'v' => '1000',
        'ts' => time() * 1000
    ]
]);

echo "Logger de debug créé. Vérifiez /tmp/websocket_debug.log\n";
