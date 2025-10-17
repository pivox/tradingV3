<?php
/**
 * Script de test pour vérifier que la correction fonctionne
 */

echo "=== Test de la correction WebSocket ===\n";

// Test de la requête vers l'endpoint Symfony
$testData = [
    'symbol' => 'BTCUSDT',
    'tfs' => ['1m', '5m', '15m']
];

echo "1. Test de la requête vers /ws/subscribe\n";
echo "Données: " . json_encode($testData, JSON_PRETTY_PRINT) . "\n\n";

// Simuler une requête cURL vers l'endpoint Symfony
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost:8082/ws/subscribe');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen(json_encode($testData))
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo "❌ Erreur cURL: $error\n";
} else {
    echo "✅ Réponse HTTP $httpCode: $response\n";
    
    $responseData = json_decode($response, true);
    if ($responseData && $responseData['ok']) {
        echo "✅ Abonnement réussi!\n";
    } else {
        echo "❌ Échec de l'abonnement: " . ($responseData['error'] ?? 'Erreur inconnue') . "\n";
    }
}

echo "\n2. Test de la requête vers /ws/unsubscribe\n";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost:8082/ws/unsubscribe');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen(json_encode($testData))
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo "❌ Erreur cURL: $error\n";
} else {
    echo "✅ Réponse HTTP $httpCode: $response\n";
    
    $responseData = json_decode($response, true);
    if ($responseData && $responseData['ok']) {
        echo "✅ Désabonnement réussi!\n";
    } else {
        echo "❌ Échec du désabonnement: " . ($responseData['error'] ?? 'Erreur inconnue') . "\n";
    }
}

echo "\n=== Résumé des corrections apportées ===\n";
echo "1. ✅ WsController utilise maintenant ContractDispatcher\n";
echo "2. ✅ ContractDispatcher.postSubscribe/postUnsubscribe sont publics\n";
echo "3. ✅ Ws-worker écoute sur /subscribe et /unsubscribe\n";
echo "4. ✅ Compatibilité maintenue avec /klines/subscribe et /klines/unsubscribe\n";
echo "\n=== Instructions ===\n";
echo "1. Redémarrer l'application Symfony\n";
echo "2. Redémarrer le ws-worker\n";
echo "3. Tester sur http://localhost:8082/websocket?symbol=BTCUSDT\n";

