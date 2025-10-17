<?php

/**
 * Démonstration du système de backtesting avec heure fixe
 * 
 * Ce script montre comment utiliser le système pour simuler
 * l'exécution de stratégies de trading à une heure fixe.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Service\BacktestClockService;
use Symfony\Component\Clock\Clock;

echo "=== Démonstration du système de backtesting ===\n\n";

// Créer une instance du service
$clock = new Clock();
$backtestClockService = new BacktestClockService($clock);

// Simuler une stratégie de trading
function simulateTradingStrategy(BacktestClockService $clockService, string $symbol): array
{
    $currentTime = $clockService->now();
    
    echo "  [{$currentTime->format('Y-m-d H:i:s')}] Analyse de {$symbol}...\n";
    
    // Simuler des calculs d'indicateurs
    usleep(100000); // 100ms de simulation
    
    // Simuler une décision de trading
    $signal = rand(0, 1) ? 'BUY' : 'SELL';
    $price = 50000 + rand(-1000, 1000);
    
    echo "  [{$currentTime->format('Y-m-d H:i:s')}] Signal: {$signal} à {$price} USD\n";
    
    return [
        'symbol' => $symbol,
        'signal' => $signal,
        'price' => $price,
        'timestamp' => $currentTime->format('Y-m-d H:i:s')
    ];
}

// 1. Test en mode temps réel
echo "1. Test en mode temps réel:\n";
$results = [];
$symbols = ['BTCUSDT', 'ETHUSDT', 'ADAUSDT'];

foreach ($symbols as $symbol) {
    $results[] = simulateTradingStrategy($backtestClockService, $symbol);
}

echo "\n2. Test avec heure fixe (backtesting):\n";
$backtestClockService->setFixedTime(new \DateTimeImmutable('2024-01-15 10:30:00', new \DateTimeZone('UTC')));

$backtestResults = [];
foreach ($symbols as $symbol) {
    $backtestResults[] = simulateTradingStrategy($backtestClockService, $symbol);
    
    // Avancer l'heure de 15 minutes pour simuler le passage du temps
    $backtestClockService->advanceFixedTimeMinutes(15);
}

echo "\n3. Comparaison des résultats:\n";
echo "Mode temps réel:\n";
foreach ($results as $result) {
    echo "  {$result['symbol']}: {$result['signal']} à {$result['price']} USD ({$result['timestamp']})\n";
}

echo "\nMode backtesting:\n";
foreach ($backtestResults as $result) {
    echo "  {$result['symbol']}: {$result['signal']} à {$result['price']} USD ({$result['timestamp']})\n";
}

echo "\n4. Avantages du backtesting avec heure fixe:\n";
echo "  - Reproducibilité: Mêmes conditions à chaque exécution\n";
echo "  - Contrôle du temps: Simulation de différents moments\n";
echo "  - Tests cohérents: Validation des stratégies\n";
echo "  - Débogage facilité: Conditions prévisibles\n";

echo "\n=== Fin de la démonstration ===\n";


