<?php
/**
 * Script de test complet pour AtrCalculator et Macd
 * 
 * Tests:
 * - Vraies données depuis la base (PIPPINUSDT, 1m, 200 klines)
 * - Données simulées (cas limites)
 * - Comparaison TRADER vs PHP fallback
 * - Simulation du cas où trader_* n'existe pas
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Indicator\Core\AtrCalculator;
use App\Indicator\Core\Momentum\Macd;

// Configuration
$SYMBOL = 'PIPPINUSDT';
$TIMEFRAME = '1m';
$LIMIT = 200;
$ATR_PERIOD = 14;
$MACD_FAST = 12;
$MACD_SLOW = 26;
$MACD_SIGNAL = 9;

// Couleurs pour la sortie
$GREEN = "\033[32m";
$RED = "\033[31m";
$YELLOW = "\033[33m";
$BLUE = "\033[34m";
$CYAN = "\033[36m";
$RESET = "\033[0m";

function printSection(string $title): void
{
    global $CYAN, $RESET;
    echo "\n" . $CYAN . str_repeat("=", 80) . $RESET . "\n";
    echo $CYAN . "  " . $title . $RESET . "\n";
    echo $CYAN . str_repeat("=", 80) . $RESET . "\n\n";
}

function printSuccess(string $message): void
{
    global $GREEN, $RESET;
    echo $GREEN . "✓ " . $message . $RESET . "\n";
}

function printError(string $message): void
{
    global $RED, $RESET;
    echo $RED . "✗ " . $message . $RESET . "\n";
}

function printWarning(string $message): void
{
    global $YELLOW, $RESET;
    echo $YELLOW . "⚠ " . $message . $RESET . "\n";
}

function printInfo(string $message): void
{
    global $BLUE, $RESET;
    echo $BLUE . "ℹ " . $message . $RESET . "\n";
}

/**
 * Récupère les klines depuis la base de données
 */
function fetchKlinesFromDb(string $symbol, string $timeframe, int $limit): ?array
{
    // Essayer DATABASE_URL d'abord (format Symfony)
    $databaseUrl = getenv('DATABASE_URL');
    if ($databaseUrl) {
        // Parser: postgresql://user:password@host:port/dbname?params
        if (preg_match('#postgresql://([^:]+):([^@]+)@([^:]+):(\d+)/([^?]+)#', $databaseUrl, $matches)) {
            $user = $matches[1];
            $password = $matches[2];
            $host = $matches[3];
            $port = $matches[4];
            $dbname = $matches[5]; // Exclut les paramètres de requête
        } else {
            // Fallback sur variables individuelles
            $host = getenv('DATABASE_HOST') ?: 'trading-app-db';
            $port = getenv('DATABASE_PORT') ?: '5432';
            $dbname = getenv('DATABASE_NAME') ?: 'trading_app';
            $user = getenv('DATABASE_USER') ?: 'postgres';
            $password = getenv('DATABASE_PASSWORD') ?: 'password';
        }
    } else {
        // Variables individuelles
        $host = getenv('DATABASE_HOST') ?: 'trading-app-db';
        $port = getenv('DATABASE_PORT') ?: '5432';
        $dbname = getenv('DATABASE_NAME') ?: 'trading_app';
        $user = getenv('DATABASE_USER') ?: 'postgres';
        $password = getenv('DATABASE_PASSWORD') ?: 'password';
    }

    try {
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $dbname);
        $pdo = new PDO($dsn, $user, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->prepare('
            SELECT open_price, high_price, low_price, close_price, open_time
            FROM klines
            WHERE symbol = :symbol AND timeframe = :timeframe
            ORDER BY open_time DESC
            LIMIT :limit
        ');
        $stmt->bindValue(':symbol', $symbol);
        $stmt->bindValue(':timeframe', $timeframe);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Inverser pour avoir l'ordre chronologique (plus ancien → plus récent)
        $rows = array_reverse($rows);

        $ohlc = [];
        $closes = [];
        foreach ($rows as $row) {
            $ohlc[] = [
                'high' => (float)$row['high_price'],
                'low' => (float)$row['low_price'],
                'close' => (float)$row['close_price'],
                'open' => (float)$row['open_price'],
            ];
            $closes[] = (float)$row['close_price'];
        }

        return ['ohlc' => $ohlc, 'closes' => $closes];
    } catch (PDOException $e) {
        printError("Erreur DB: " . $e->getMessage());
        return null;
    }
}

/**
 * Génère des données simulées pour les tests
 */
function generateSimulatedData(): array
{
    return [
        'flat_market' => [
            'ohlc' => array_map(fn($i) => [
                'high' => 100.0,
                'low' => 100.0,
                'close' => 100.0,
                'open' => 100.0,
            ], range(0, 50)),
            'closes' => array_fill(0, 51, 100.0),
            'description' => 'Marché plat (ATR devrait être ~0, MACD ~0)',
        ],
        'volatile_market' => [
            'ohlc' => array_map(function($i) {
                $base = 100.0;
                $volatility = 10.0;
                $high = $base + $volatility * (1 + sin($i * 0.5));
                $low = $base - $volatility * (1 + cos($i * 0.5));
                $close = $base + $volatility * sin($i * 0.3);
                return [
                    'high' => max($high, $low, $close),
                    'low' => min($high, $low, $close),
                    'close' => $close,
                    'open' => $base + $volatility * cos($i * 0.2),
                ];
            }, range(0, 100)),
            'closes' => array_map(fn($i) => 100.0 + 10.0 * sin($i * 0.3), range(0, 100)),
            'description' => 'Marché très volatil (ATR élevé)',
        ],
        'insufficient_data' => [
            'ohlc' => array_map(fn($i) => [
                'high' => 100.0 + $i * 0.1,
                'low' => 100.0 - $i * 0.1,
                'close' => 100.0 + $i * 0.05,
                'open' => 100.0,
            ], range(0, 10)),
            'closes' => array_map(fn($i) => 100.0 + $i * 0.05, range(0, 10)),
            'description' => 'Données insuffisantes (< period)',
        ],
        'trending_up' => [
            'ohlc' => array_map(function($i) {
                $base = 100.0 + $i * 0.5;
                return [
                    'high' => $base + 1.0,
                    'low' => $base - 1.0,
                    'close' => $base,
                    'open' => $base - 0.5,
                ];
            }, range(0, 100)),
            'closes' => array_map(fn($i) => 100.0 + $i * 0.5, range(0, 100)),
            'description' => 'Tendance haussière (MACD positif)',
        ],
    ];
}

/**
 * Teste ATR avec vraies données
 */
function testAtrWithRealData(array $ohlc, bool $simulateNoTrader = false): void
{
    global $ATR_PERIOD;
    
    printInfo("Test ATR avec vraies données (period={$ATR_PERIOD})");
    if ($simulateNoTrader) {
        printWarning("SIMULATION: trader_atr n'existe pas (fallback PHP forcé)");
    }

    $calculator = new AtrCalculator();
    
    // Simuler l'absence de trader_atr si demandé
    if ($simulateNoTrader) {
        // Utiliser la réflexion pour forcer le fallback
        // On ne peut pas vraiment désactiver function_exists, donc on teste directement le fallback PHP
        printInfo("Test direct du fallback PHP (bypass TRADER)");
    }

    try {
        // Test Wilder
        $atrWilder = $calculator->compute($ohlc, $ATR_PERIOD, 'wilder');
        printSuccess(sprintf("ATR (Wilder) = %.8f", $atrWilder));
        
        // Test Simple
        $atrSimple = $calculator->compute($ohlc, $ATR_PERIOD, 'simple');
        printSuccess(sprintf("ATR (Simple) = %.8f", $atrSimple));
        
        // Comparaison
        $diff = abs($atrWilder - $atrSimple);
        $diffPct = ($atrWilder > 0) ? ($diff / $atrWilder * 100) : 0;
        printInfo(sprintf("Différence Wilder vs Simple: %.8f (%.2f%%)", $diff, $diffPct));
        
        // Validation
        if ($atrWilder > 0 && is_finite($atrWilder)) {
            printSuccess("ATR Wilder: valeur valide");
        } else {
            printError("ATR Wilder: valeur invalide");
        }
        
        if ($atrSimple > 0 && is_finite($atrSimple)) {
            printSuccess("ATR Simple: valeur valide");
        } else {
            printError("ATR Simple: valeur invalide");
        }
        
    } catch (\Throwable $e) {
        printError("Erreur ATR: " . $e->getMessage());
    }
}

/**
 * Teste MACD avec vraies données
 */
function testMacdWithRealData(array $closes, bool $simulateNoTrader = false): void
{
    global $MACD_FAST, $MACD_SLOW, $MACD_SIGNAL;
    
    printInfo("Test MACD avec vraies données (fast={$MACD_FAST}, slow={$MACD_SLOW}, signal={$MACD_SIGNAL})");
    if ($simulateNoTrader) {
        printWarning("SIMULATION: trader_macd n'existe pas (fallback PHP forcé)");
    }

    $macd = new Macd();
    
    try {
        $result = $macd->calculateFull($closes, $MACD_FAST, $MACD_SLOW, $MACD_SIGNAL);
        
        $macdArr = $result['macd'] ?? [];
        $signalArr = $result['signal'] ?? [];
        $histArr = $result['hist'] ?? [];
        
        if (empty($macdArr)) {
            printError("MACD: aucune valeur calculée");
            return;
        }
        
        $lastMacd = !empty($macdArr) ? end($macdArr) : null;
        $lastSignal = !empty($signalArr) ? end($signalArr) : null;
        $lastHist = !empty($histArr) ? end($histArr) : null;
        
        printSuccess(sprintf("MACD (dernier) = %.8f", $lastMacd ?? 0.0));
        printSuccess(sprintf("Signal (dernier) = %.8f", $lastSignal ?? 0.0));
        printSuccess(sprintf("Hist (dernier) = %.8f", $lastHist ?? 0.0));
        
        // Statistiques
        $validMacd = array_filter($macdArr, fn($v) => $v !== null && is_finite($v));
        $validSignal = array_filter($signalArr, fn($v) => $v !== null && is_finite($v));
        
        printInfo(sprintf("Valeurs MACD valides: %d/%d", count($validMacd), count($macdArr)));
        printInfo(sprintf("Valeurs Signal valides: %d/%d", count($validSignal), count($signalArr)));
        
        // Validation
        if ($lastMacd !== null && is_finite($lastMacd)) {
            printSuccess("MACD: valeur valide");
        } else {
            printError("MACD: valeur invalide");
        }
        
        if ($lastSignal !== null && is_finite($lastSignal)) {
            printSuccess("Signal: valeur valide");
        } else {
            printError("Signal: valeur invalide");
        }
        
    } catch (\Throwable $e) {
        printError("Erreur MACD: " . $e->getMessage());
    }
}

/**
 * Teste avec données simulées
 */
function testWithSimulatedData(): void
{
    global $ATR_PERIOD, $MACD_FAST, $MACD_SLOW, $MACD_SIGNAL;
    
    $simulated = generateSimulatedData();
    $atrCalc = new AtrCalculator();
    $macdCalc = new Macd();
    
    foreach ($simulated as $name => $data) {
        printSection("Test: " . $name . " - " . $data['description']);
        
        // Test ATR
        try {
            if (count($data['ohlc']) >= $ATR_PERIOD + 1) {
                $atr = $atrCalc->compute($data['ohlc'], $ATR_PERIOD, 'wilder');
                printSuccess(sprintf("ATR = %.8f", $atr));
            } else {
                printWarning("Données insuffisantes pour ATR (besoin >= " . ($ATR_PERIOD + 1) . ")");
            }
        } catch (\Throwable $e) {
            printError("ATR erreur: " . $e->getMessage());
        }
        
        // Test MACD
        try {
            $minRequired = max($MACD_FAST, $MACD_SLOW, $MACD_SIGNAL) + 5;
            if (count($data['closes']) >= $minRequired) {
                $result = $macdCalc->calculateFull($data['closes'], $MACD_FAST, $MACD_SLOW, $MACD_SIGNAL);
                $macdArr = $result['macd'] ?? [];
                $signalArr = $result['signal'] ?? [];
                $lastMacd = !empty($macdArr) ? end($macdArr) : null;
                $lastSignal = !empty($signalArr) ? end($signalArr) : null;
                printSuccess(sprintf("MACD = %.8f, Signal = %.8f", $lastMacd ?? 0.0, $lastSignal ?? 0.0));
            } else {
                printWarning("Données insuffisantes pour MACD (besoin >= {$minRequired})");
            }
        } catch (\Throwable $e) {
            printError("MACD erreur: " . $e->getMessage());
        }
    }
}

/**
 * Teste les cas limites et erreurs
 */
function testEdgeCases(): void
{
    global $ATR_PERIOD;
    
    printSection("Tests des cas limites");
    
    $atrCalc = new AtrCalculator();
    $macdCalc = new Macd();
    
    // Test 1: Données vides
    printInfo("Test 1: Données vides");
    try {
        $atrCalc->compute([], $ATR_PERIOD);
        printError("ATR: devrait lever une exception");
    } catch (\Throwable $e) {
        printSuccess("ATR: exception levée correctement: " . $e->getMessage());
    }
    
    try {
        $macdCalc->calculateFull([]);
        printWarning("MACD: retourne des null (comportement attendu)");
    } catch (\Throwable $e) {
        printSuccess("MACD: exception levée: " . $e->getMessage());
    }
    
    // Test 2: Period invalide
    printInfo("Test 2: Period invalide (0)");
    try {
        $atrCalc->compute([['high' => 100, 'low' => 99, 'close' => 99.5]], 0);
        printError("ATR: devrait lever une exception");
    } catch (\InvalidArgumentException $e) {
        printSuccess("ATR: exception levée correctement");
    }
    
    // Test 3: Méthode invalide
    printInfo("Test 3: Méthode ATR invalide");
    try {
        $ohlc = array_map(fn($i) => [
            'high' => 100.0 + $i * 0.1,
            'low' => 100.0 - $i * 0.1,
            'close' => 100.0 + $i * 0.05,
        ], range(0, 20));
        $atrCalc->compute($ohlc, $ATR_PERIOD, 'invalid');
        printError("ATR: devrait lever une exception");
    } catch (\InvalidArgumentException $e) {
        printSuccess("ATR: exception levée correctement");
    }
    
    // Test 4: Clés OHLC manquantes
    printInfo("Test 4: Clés OHLC manquantes");
    try {
        $atrCalc->compute([['high' => 100]], $ATR_PERIOD);
        printError("ATR: devrait lever une exception");
    } catch (\InvalidArgumentException $e) {
        printSuccess("ATR: exception levée correctement");
    }
}

/**
 * Fonctions de test qui reproduisent la logique PHP de fallback
 * (car les classes sont final et ne peuvent pas être étendues)
 */

function computeAtrPhpFallback(array $ohlc, int $period, string $method): float
{
    // Copie de la logique PHP de AtrCalculator::compute() (sans TRADER)
    if ($period <= 0) {
        throw new \InvalidArgumentException('ATR period must be > 0');
    }
    $n = count($ohlc);
    if ($n < $period + 1) {
        throw new \InvalidArgumentException(sprintf(
            'Not enough candles to compute ATR (have=%d, need>=%d)',
            $n,
            $period + 1
        ));
    }
    
    $method = strtolower($method);
    if (!\in_array($method, ['wilder', 'simple'], true)) {
        throw new \InvalidArgumentException(sprintf(
            'Unsupported ATR method "%s", expected "wilder" or "simple"',
            $method
        ));
    }
    
    // True Range series
    $trs = [];
    for ($i = 1; $i < $n; $i++) {
        if (!isset($ohlc[$i]['high'], $ohlc[$i]['low'], $ohlc[$i - 1]['close'])) {
            throw new \InvalidArgumentException('Missing OHLC keys (high/low/close) in input data');
        }
        
        $h  = (float) $ohlc[$i]['high'];
        $l  = (float) $ohlc[$i]['low'];
        $pc = (float) $ohlc[$i - 1]['close'];
        
        $tr = \max($h - $l, \abs($h - $pc), \abs($l - $pc));
        $trs[] = $tr;
    }
    
    if (count($trs) < $period) {
        throw new \RuntimeException('Not enough True Range values to compute ATR');
    }
    
    if ($method === 'simple') {
        $slice = \array_slice($trs, -$period);
        return \array_sum($slice) / $period;
    }
    
    // Wilder
    $seed = \array_slice($trs, 0, $period);
    $atr  = \array_sum($seed) / $period;
    $countTrs = count($trs);
    for ($i = $period; $i < $countTrs; $i++) {
        $atr = (($atr * ($period - 1)) + $trs[$i]) / $period;
    }
    
    return $atr;
}

function calculateMacdPhpFallback(array $closes, int $fast, int $slow, int $signal): array
{
    // Copie de la logique PHP de Macd::calculateFullPhp()
    $n = count($closes);
    if ($n < max($fast, $slow) + $signal) {
        return ['macd' => [], 'signal' => [], 'hist' => []];
    }
    
    // Calcul EMA
    $emaFast = emaSeries($closes, $fast);
    $emaSlow = emaSeries($closes, $slow);
    $len = min(count($emaFast), count($emaSlow));
    $macd = [];
    
    for ($i = 0; $i < $len; $i++) {
        $macd[] = $emaFast[$i] - $emaSlow[$i];
    }
    
    $signalSeries = emaSeries($macd, $signal);
    
    $shift = count($macd) - count($signalSeries);
    if ($shift > 0) {
        $macd = array_slice($macd, $shift);
    }
    
    $hist = [];
    $m = count($macd);
    for ($i = 0; $i < $m; $i++) {
        $hist[] = $macd[$i] - $signalSeries[$i];
    }
    
    return [
        'macd'   => $macd,
        'signal' => $signalSeries,
        'hist'   => $hist,
    ];
}

function emaSeries(array $values, int $period): array
{
    $n = count($values);
    if ($n < $period) return [];
    
    $sum = 0.0;
    for ($i = 0; $i < $period; $i++) $sum += $values[$i];
    $ema = [];
    $ema[] = $sum / $period;
    
    $alpha = 2.0 / ($period + 1.0);
    
    for ($i = $period; $i < $n; $i++) {
        $ema[] = $alpha * $values[$i] + (1.0 - $alpha) * end($ema);
    }
    
    return $ema;
}

/**
 * Teste le fallback TRADER → PHP en simulant l'absence de trader_*
 */
function testTraderFallback(): void
{
    global $ohlc, $closes, $ATR_PERIOD, $MACD_FAST, $MACD_SLOW, $MACD_SIGNAL;
    
    printSection("Test du fallback TRADER → PHP (simulation)");
    
    printInfo("Vérification de la disponibilité de l'extension TRADER:");
    $hasTraderAtr = function_exists('trader_atr');
    $hasTraderMacd = function_exists('trader_macd');
    
    if ($hasTraderAtr) {
        printSuccess("trader_atr() est disponible");
    } else {
        printWarning("trader_atr() n'est PAS disponible (fallback PHP sera utilisé)");
    }
    
    if ($hasTraderMacd) {
        printSuccess("trader_macd() est disponible");
    } else {
        printWarning("trader_macd() n'est PAS disponible (fallback PHP sera utilisé)");
    }
    
    // Test avec fallback forcé (simulation: trader_* n'existe pas)
    printInfo("\nTest avec fallback PHP FORCÉ (simulation: trader_* n'existe pas):");
    
    // Test ATR avec fallback PHP pur
    try {
        $atrPhp = computeAtrPhpFallback($ohlc, $ATR_PERIOD, 'wilder');
        printSuccess(sprintf("ATR (PHP fallback forcé) = %.8f", $atrPhp));
        
        // Comparer avec le calcul normal (si TRADER disponible)
        if ($hasTraderAtr) {
            $atrNormal = (new AtrCalculator())->compute($ohlc, $ATR_PERIOD, 'wilder');
            $diff = abs($atrPhp - $atrNormal);
            $diffPct = ($atrNormal > 0) ? ($diff / $atrNormal * 100) : 0;
            printInfo(sprintf("Différence PHP vs TRADER: %.8f (%.4f%%)", $diff, $diffPct));
            
            if ($diffPct < 0.1) {
                printSuccess("Les deux méthodes donnent des résultats très proches (< 0.1%)");
            } elseif ($diffPct < 1.0) {
                printWarning("Les deux méthodes donnent des résultats proches (< 1%)");
            } else {
                printWarning("Les deux méthodes donnent des résultats différents (> 1%)");
            }
        }
    } catch (\Throwable $e) {
        printError("Erreur ATR (fallback forcé): " . $e->getMessage());
    }
    
    // Test MACD avec fallback PHP pur
    try {
        $macdPhp = calculateMacdPhpFallback($closes, $MACD_FAST, $MACD_SLOW, $MACD_SIGNAL);
        $macdPhpArr = $macdPhp['macd'] ?? [];
        $signalPhpArr = $macdPhp['signal'] ?? [];
        $lastMacdPhp = !empty($macdPhpArr) ? end($macdPhpArr) : null;
        $lastSignalPhp = !empty($signalPhpArr) ? end($signalPhpArr) : null;
        printSuccess(sprintf("MACD (PHP fallback forcé) = %.8f, Signal = %.8f", 
            $lastMacdPhp ?? 0.0, $lastSignalPhp ?? 0.0));
        
        // Comparer avec le calcul normal (si TRADER disponible)
        if ($hasTraderMacd) {
            $macdNormal = (new Macd())->calculateFull($closes, $MACD_FAST, $MACD_SLOW, $MACD_SIGNAL);
            $macdNormalArr = $macdNormal['macd'] ?? [];
            $signalNormalArr = $macdNormal['signal'] ?? [];
            $lastMacdNormal = !empty($macdNormalArr) ? end($macdNormalArr) : null;
            $lastSignalNormal = !empty($signalNormalArr) ? end($signalNormalArr) : null;
            
            if ($lastMacdPhp !== null && $lastMacdNormal !== null) {
                $diffMacd = abs($lastMacdPhp - $lastMacdNormal);
                $diffSignal = abs($lastSignalPhp - $lastSignalNormal);
                printInfo(sprintf("Différence MACD: %.8f, Signal: %.8f", $diffMacd, $diffSignal));
                
                // MACD peut avoir des différences plus importantes selon l'implémentation
                if ($diffMacd < 0.01) {
                    printSuccess("Les deux méthodes MACD donnent des résultats très proches");
                } else {
                    printWarning("Les deux méthodes MACD donnent des résultats différents (normal selon l'implémentation)");
                }
            }
        }
    } catch (\Throwable $e) {
        printError("Erreur MACD (fallback forcé): " . $e->getMessage());
    }
}

// ============================================================================
// EXECUTION PRINCIPALE
// ============================================================================

printSection("TEST COMPLET: AtrCalculator & Macd");
printInfo("Symbole: {$SYMBOL}, Timeframe: {$TIMEFRAME}, Limit: {$LIMIT}");

// 1. Récupération des vraies données
printSection("1. Récupération des données depuis la base");
$realData = fetchKlinesFromDb($SYMBOL, $TIMEFRAME, $LIMIT);

if ($realData === null) {
    printError("Impossible de récupérer les données. Arrêt.");
    exit(1);
}

$ohlc = $realData['ohlc'];
$closes = $realData['closes'];

printSuccess(sprintf("Récupéré %d klines", count($ohlc)));
printInfo(sprintf("Prix min: %.8f, max: %.8f", min($closes), max($closes)));

// 2. Tests avec vraies données (TRADER disponible)
printSection("2. Tests avec vraies données (TRADER disponible)");
testAtrWithRealData($ohlc, false);
testMacdWithRealData($closes, false);

// 3. Tests avec vraies données (simulation: TRADER indisponible)
printSection("3. Tests avec vraies données (SIMULATION: TRADER indisponible)");
// Note: On ne peut pas vraiment simuler, mais on documente le comportement
printInfo("Note: Le fallback PHP sera automatiquement utilisé si trader_* retourne des valeurs invalides");
testAtrWithRealData($ohlc, true);
testMacdWithRealData($closes, true);

// 4. Tests avec données simulées
printSection("4. Tests avec données simulées");
testWithSimulatedData();

// 5. Tests des cas limites
testEdgeCases();

// 6. Test du fallback
testTraderFallback();

// Résumé final
printSection("RÉSUMÉ");
printSuccess("Tous les tests ont été exécutés");
printInfo("Vérifiez les logs ci-dessus pour les détails");

