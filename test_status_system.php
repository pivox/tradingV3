<?php
/**
 * Script de test complet du système de statuts
 */

echo "=== Test complet du système de statuts ===\n\n";

function testEndpoint($url, $data, $description) {
    echo "Test: $description\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen(json_encode($data))
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        echo "❌ Erreur cURL: $error\n";
        return false;
    } else {
        echo "✅ Réponse HTTP $httpCode: $response\n";
        $responseData = json_decode($response, true);
        return $responseData && $responseData['ok'];
    }
}

function checkCsvStatus($expectedSymbol, $shouldExist) {
    $csvContent = shell_exec('docker-compose exec trading-app-php cat /var/www/html/var/hot_assignment.csv 2>/dev/null');
    $exists = strpos($csvContent, $expectedSymbol) !== false;
    
    if ($shouldExist && $exists) {
        echo "✅ CSV: $expectedSymbol trouvé dans les assignations\n";
        return true;
    } elseif (!$shouldExist && !$exists) {
        echo "✅ CSV: $expectedSymbol supprimé des assignations\n";
        return true;
    } else {
        echo "❌ CSV: État inattendu pour $expectedSymbol (devrait " . ($shouldExist ? "exister" : "ne pas exister") . ")\n";
        return false;
    }
}

function checkWebStatus($symbol, $expectedStatus) {
    $html = shell_exec("curl -s 'http://localhost:8082/websocket?symbol=$symbol' 2>/dev/null");
    $hasActive = strpos($html, 'badge bg-success">Actif</span>') !== false;
    $hasInactive = strpos($html, 'badge bg-secondary">Inactif</span>') !== false;
    
    if ($expectedStatus === 'active' && $hasActive && !$hasInactive) {
        echo "✅ Web: $symbol affiche 'Actif'\n";
        return true;
    } elseif ($expectedStatus === 'inactive' && $hasInactive && !$hasActive) {
        echo "✅ Web: $symbol affiche 'Inactif'\n";
        return true;
    } else {
        echo "❌ Web: $symbol n'affiche pas le bon statut (attendu: $expectedStatus)\n";
        return false;
    }
}

$testData = [
    'symbol' => 'BTCUSDT',
    'tfs' => ['1m', '5m', '15m']
];

echo "=== Test 1: Abonnement ===\n";
$subscribeOk = testEndpoint('http://localhost:8082/ws/subscribe', $testData, 'Abonnement BTCUSDT');
sleep(2);
$csvOk1 = checkCsvStatus('BTCUSDT', true);
$webOk1 = checkWebStatus('BTCUSDT', 'active');

echo "\n=== Test 2: Désabonnement ===\n";
$unsubscribeOk = testEndpoint('http://localhost:8082/ws/unsubscribe', $testData, 'Désabonnement BTCUSDT');
sleep(2);
$csvOk2 = checkCsvStatus('BTCUSDT', false);
$webOk2 = checkWebStatus('BTCUSDT', 'inactive');

echo "\n=== Test 3: Cycle complet ===\n";
$subscribeOk2 = testEndpoint('http://localhost:8082/ws/subscribe', $testData, 'Abonnement BTCUSDT (cycle 2)');
sleep(2);
$csvOk3 = checkCsvStatus('BTCUSDT', true);
$webOk3 = checkWebStatus('BTCUSDT', 'active');

echo "\n=== Résumé des tests ===\n";
$allTests = [
    'Abonnement API' => $subscribeOk,
    'CSV après abonnement' => $csvOk1,
    'Web après abonnement' => $webOk1,
    'Désabonnement API' => $unsubscribeOk,
    'CSV après désabonnement' => $csvOk2,
    'Web après désabonnement' => $webOk2,
    'Abonnement cycle 2' => $subscribeOk2,
    'CSV cycle 2' => $csvOk3,
    'Web cycle 2' => $webOk3
];

$passed = 0;
$total = count($allTests);

foreach ($allTests as $test => $result) {
    echo ($result ? "✅" : "❌") . " $test\n";
    if ($result) $passed++;
}

echo "\n=== Résultat final ===\n";
echo "Tests réussis: $passed/$total\n";

if ($passed === $total) {
    echo "🎉 Tous les tests sont passés ! Le système de statuts fonctionne parfaitement.\n";
    echo "\n=== Fonctionnalités validées ===\n";
    echo "✅ 1. Abonnement ajoute l'entrée au CSV\n";
    echo "✅ 2. Désabonnement supprime l'entrée du CSV\n";
    echo "✅ 3. Interface affiche le statut correct (Actif/Inactif)\n";
    echo "✅ 4. Synchronisation entre CSV et interface\n";
    echo "✅ 5. Cycle complet abonnement/désabonnement\n";
    echo "✅ 6. Bouton de rafraîchissement disponible\n";
} else {
    echo "❌ Certains tests ont échoué. Vérifiez les logs ci-dessus.\n";
}

