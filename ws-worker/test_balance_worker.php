<?php

declare(strict_types=1);

/**
 * Script de test pour le BalanceWorker
 * 
 * Ce script teste les fonctionnalités du BalanceWorker en envoyant des requêtes
 * à l'API de contrôle HTTP.
 * 
 * Usage:
 *   php test_balance_worker.php
 */

$baseUrl = 'http://localhost:8089';

function makeRequest(string $url, string $method = 'GET', ?array $data = null): array
{
    $ch = curl_init($url);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
    }
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'status_code' => $statusCode,
        'body' => $response ? json_decode($response, true) : null,
    ];
}

echo "=== Test du BalanceWorker ===\n\n";

// Test 1: Vérifier le statut initial
echo "1. Vérification du statut initial...\n";
$result = makeRequest("$baseUrl/status");
echo "   Statut: {$result['status_code']}\n";
if ($result['body']) {
    echo "   Worker running: " . ($result['body']['is_running'] ? 'Oui' : 'Non') . "\n";
    echo "   Balance subscribed: " . ($result['body']['balance_subscribed'] ?? false ? 'Oui' : 'Non') . "\n";
    if (isset($result['body']['balance'])) {
        echo "   Balance actuelle: " . json_encode($result['body']['balance']) . "\n";
    }
}
echo "\n";

// Test 2: S'abonner au balance
echo "2. Souscription au solde USDT...\n";
$result = makeRequest("$baseUrl/balance/subscribe", 'POST');
echo "   Statut: {$result['status_code']}\n";
if ($result['body']) {
    echo "   Message: {$result['body']['message']}\n";
}
echo "\n";

// Attendre un peu pour permettre la souscription
sleep(2);

// Test 3: Vérifier le statut après souscription
echo "3. Vérification du statut après souscription...\n";
$result = makeRequest("$baseUrl/status");
echo "   Statut: {$result['status_code']}\n";
if ($result['body']) {
    echo "   Balance subscribed: " . ($result['body']['balance_subscribed'] ?? false ? 'Oui' : 'Non') . "\n";
    if (isset($result['body']['balance'])) {
        echo "   Balance actuelle:\n";
        echo "     - Currency: " . ($result['body']['balance']['currency'] ?? 'N/A') . "\n";
        echo "     - Available: " . ($result['body']['balance']['available_balance'] ?? 'N/A') . "\n";
        echo "     - Frozen: " . ($result['body']['balance']['frozen_balance'] ?? 'N/A') . "\n";
        echo "     - Equity: " . ($result['body']['balance']['equity'] ?? 'N/A') . "\n";
    } else {
        echo "   Aucune donnée de balance reçue (attendre les mises à jour...)\n";
    }
}
echo "\n";

// Test 4: Attendre quelques secondes pour observer les updates
echo "4. Attente de 10 secondes pour observer les updates de balance...\n";
echo "   (Vérifiez les logs du worker pour voir les mises à jour)\n";
sleep(10);

// Test 5: Vérifier à nouveau le statut
echo "5. Vérification finale du statut...\n";
$result = makeRequest("$baseUrl/status");
echo "   Statut: {$result['status_code']}\n";
if ($result['body'] && isset($result['body']['balance'])) {
    echo "   Balance finale:\n";
    echo "     - Currency: " . ($result['body']['balance']['currency'] ?? 'N/A') . "\n";
    echo "     - Available: " . ($result['body']['balance']['available_balance'] ?? 'N/A') . "\n";
    echo "     - Frozen: " . ($result['body']['balance']['frozen_balance'] ?? 'N/A') . "\n";
    echo "     - Equity: " . ($result['body']['balance']['equity'] ?? 'N/A') . "\n";
}
echo "\n";

// Test 6: Se désabonner
echo "6. Désabonnement du solde USDT...\n";
$result = makeRequest("$baseUrl/balance/unsubscribe", 'POST');
echo "   Statut: {$result['status_code']}\n";
if ($result['body']) {
    echo "   Message: {$result['body']['message']}\n";
}
echo "\n";

// Test 7: Vérifier le statut final
echo "7. Vérification du statut après désabonnement...\n";
$result = makeRequest("$baseUrl/status");
echo "   Statut: {$result['status_code']}\n";
if ($result['body']) {
    echo "   Balance subscribed: " . ($result['body']['balance_subscribed'] ?? false ? 'Oui' : 'Non') . "\n";
}
echo "\n";

echo "=== Tests terminés ===\n";
echo "\nNOTE: Ce script teste uniquement l'API de contrôle HTTP.\n";
echo "Pour vérifier que les signaux sont bien envoyés vers trading-app,\n";
echo "consultez les logs du worker et vérifiez l'endpoint /api/ws-worker/balance sur trading-app.\n";

