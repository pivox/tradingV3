<?php
/**
 * Script de test pour vérifier les données WebSocket BitMart
 */

// Test simple pour voir les données brutes
echo "=== Test des données WebSocket BitMart ===\n";

// Simuler un message BitMart typique
$testMessage = [
    'group' => 'futures/klineBin1m:BTCUSDT',
    'data' => [
        'symbol' => 'BTCUSDT',
        'o' => '50000.0',  // Open
        'h' => '50100.0',  // High  
        'l' => '49900.0',  // Low
        'c' => '50050.0',  // Close
        'v' => '1000',     // Volume
        'ts' => time() * 1000 // Timestamp en millisecondes
    ]
];

echo "Message de test :\n";
echo json_encode($testMessage, JSON_PRETTY_PRINT) . "\n\n";

// Test avec des valeurs à 0 (ce que nous recevons actuellement)
$zeroMessage = [
    'group' => 'futures/klineBin1m:BTCUSDT',
    'data' => [
        'symbol' => 'BTCUSDT',
        'o' => '0',
        'h' => '0', 
        'l' => '0',
        'c' => '0',
        'v' => '0',
        'ts' => time() * 1000
    ]
];

echo "Message avec valeurs à 0 (problème actuel) :\n";
echo json_encode($zeroMessage, JSON_PRETTY_PRINT) . "\n\n";

echo "=== Diagnostic ===\n";
echo "1. Les valeurs à 0 peuvent indiquer :\n";
echo "   - Marché fermé ou pas d'activité récente\n";
echo "   - Problème d'authentification\n";
echo "   - Format de données incorrect\n";
echo "   - Problème de connexion WebSocket\n\n";

echo "2. Solutions possibles :\n";
echo "   - Vérifier les clés API BitMart\n";
echo "   - Tester avec un symbole plus actif (ETHUSDT, BNBUSDT)\n";
echo "   - Vérifier l'heure (marché peut être fermé)\n";
echo "   - Tester la connexion WebSocket directement\n\n";

echo "3. Pour visualiser les dump() et print_r :\n";
echo "   - Utiliser error_log() au lieu de fwrite(STDOUT)\n";
echo "   - Écrire dans un fichier de log\n";
echo "   - Utiliser var_dump() avec output buffering\n";
