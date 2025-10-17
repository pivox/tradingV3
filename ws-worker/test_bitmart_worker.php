<?php
/**
 * Script de test pour le BitMart WebSocket Worker
 * 
 * Ce script permet de tester les différentes fonctionnalités du worker
 * sans avoir besoin de démarrer le worker complet.
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Infra\BitmartWsClient;
use App\Infra\AuthHandler;
use App\Worker\KlineWorker;
use App\Worker\OrderWorker;
use App\Worker\PositionWorker;
use React\EventLoop\Loop;

// Configuration de test
$publicWsUri = 'wss://openapi-ws-v2.bitmart.com/api?protocol=1.1';
$privateWsUri = 'wss://openapi-ws-v2.bitmart.com/user?protocol=1.1';

// Clés API (remplacer par vos vraies clés pour tester les canaux privés)
$apiKey = $_ENV['BITMART_API_KEY'] ?? 'test_key';
$apiSecret = $_ENV['BITMART_API_SECRET'] ?? 'test_secret';
$apiMemo = $_ENV['BITMART_API_MEMO'] ?? 'test_memo';

echo "=== Test BitMart WebSocket Worker ===\n\n";

// Test 1: Client WebSocket public
echo "1. Test connexion WebSocket publique...\n";
$publicClient = new BitmartWsClient($publicWsUri);

$publicClient->onOpen(function() {
    echo "✓ Connexion WebSocket publique établie\n";
});

$publicClient->onMessage(function(string $message) {
    $data = json_decode($message, true);
    if (isset($data['group']) && str_starts_with($data['group'], 'futures/klineBin')) {
        echo "✓ Message kline reçu: " . $data['group'] . "\n";
    }
});

$publicClient->onError(function(\Throwable $e) {
    echo "✗ Erreur WebSocket publique: " . $e->getMessage() . "\n";
});

$publicClient->connect();

// Test 2: Client WebSocket privé avec authentification
echo "\n2. Test connexion WebSocket privée avec authentification...\n";
$privateClient = new BitmartWsClient($privateWsUri, $apiKey, $apiSecret, $apiMemo);
$authHandler = new AuthHandler($privateClient);

$privateClient->onOpen(function() use ($authHandler) {
    echo "✓ Connexion WebSocket privée établie\n";
    echo "→ Tentative d'authentification...\n";
    $authHandler->authenticate();
});

$privateClient->onMessage(function(string $message) {
    $data = json_decode($message, true);
    if (isset($data['action']) && $data['action'] === 'access') {
        if ($data['success'] ?? false) {
            echo "✓ Authentification réussie\n";
        } else {
            echo "✗ Authentification échouée: " . ($data['error'] ?? 'Erreur inconnue') . "\n";
        }
    }
});

$privateClient->onError(function(\Throwable $e) {
    echo "✗ Erreur WebSocket privée: " . $e->getMessage() . "\n";
});

$privateClient->connect();

// Test 3: Worker Klines
echo "\n3. Test KlineWorker...\n";
$klineWorker = new KlineWorker($publicClient, 5, 100, 10);

$klineWorker->subscribe('BTCUSDT', ['1m', '5m']);
echo "→ Souscription aux klines BTCUSDT (1m, 5m) demandée\n";

// Test 4: Workers privés (si authentification réussie)
echo "\n4. Test OrderWorker et PositionWorker...\n";
$orderWorker = new OrderWorker($privateClient, $authHandler, 5, 100);
$positionWorker = new PositionWorker($privateClient, $authHandler, 5, 100);

// Démarrer les workers
$klineWorker->run();
$orderWorker->run();
$positionWorker->run();

// Timer pour tester les souscriptions privées après authentification
Loop::addTimer(3, function() use ($orderWorker, $positionWorker, $authHandler) {
    if ($authHandler->isAuthenticated()) {
        echo "→ Souscription aux ordres demandée\n";
        $orderWorker->subscribeToOrders();
        
        echo "→ Souscription aux positions demandée\n";
        $positionWorker->subscribeToPositions();
    } else {
        echo "→ Authentification non réussie, souscriptions privées ignorées\n";
    }
});

// Timer pour afficher le statut
Loop::addTimer(10, function() use ($klineWorker, $orderWorker, $positionWorker, $authHandler) {
    echo "\n=== Statut après 10 secondes ===\n";
    echo "Klines actives: " . implode(', ', $klineWorker->getSubscribedChannels()) . "\n";
    echo "Ordres actifs: " . ($orderWorker->isSubscribedToOrders() ? 'Oui' : 'Non') . "\n";
    echo "Positions actives: " . ($positionWorker->isSubscribedToPositions() ? 'Oui' : 'Non') . "\n";
    echo "Authentifié: " . ($authHandler->isAuthenticated() ? 'Oui' : 'Non') . "\n";
});

// Arrêt après 30 secondes
Loop::addTimer(30, function() {
    echo "\n=== Test terminé ===\n";
    Loop::stop();
});

echo "\nTest en cours... (30 secondes)\n";
echo "Appuyez sur Ctrl+C pour arrêter\n\n";

// Gestion des signaux
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, function() {
        echo "\nArrêt du test...\n";
        Loop::stop();
    });
}

// Démarrer la boucle d'événements
Loop::run();





