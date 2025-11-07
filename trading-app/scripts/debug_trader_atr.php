<?php
/**
 * Script de debug pour comprendre pourquoi trader_atr retourne 0.0
 */

require __DIR__ . '/../vendor/autoload.php';

// Configuration
$SYMBOL = 'PIPPINUSDT';
$TIMEFRAME = '1m';
$LIMIT = 200;
$PERIOD = 14;

// Récupérer les données
$databaseUrl = getenv('DATABASE_URL');
if ($databaseUrl && preg_match('#postgresql://([^:]+):([^@]+)@([^:]+):(\d+)/([^?]+)#', $databaseUrl, $matches)) {
    $user = $matches[1];
    $password = $matches[2];
    $host = $matches[3];
    $port = $matches[4];
    $dbname = $matches[5];
} else {
    $host = 'trading-app-db';
    $port = '5432';
    $dbname = 'trading_app';
    $user = 'postgres';
    $password = 'password';
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
    $stmt->bindValue(':symbol', $SYMBOL);
    $stmt->bindValue(':timeframe', $TIMEFRAME);
    $stmt->bindValue(':limit', $LIMIT, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rows = array_reverse($rows);

    $high = [];
    $low = [];
    $close = [];
    $ohlc = [];
    
    foreach ($rows as $row) {
        $high[] = (float)$row['high_price'];
        $low[] = (float)$row['low_price'];
        $close[] = (float)$row['close_price'];
        $ohlc[] = [
            'high' => (float)$row['high_price'],
            'low' => (float)$row['low_price'],
            'close' => (float)$row['close_price'],
            'open' => (float)$row['open_price'],
        ];
    }

    echo "=== DEBUG trader_atr ===\n\n";
    echo "Données récupérées: " . count($high) . " klines\n";
    echo "Prix min: " . min($close) . ", max: " . max($close) . "\n";
    echo "High min: " . min($high) . ", max: " . max($high) . "\n";
    echo "Low min: " . min($low) . ", max: " . max($low) . "\n\n";

    // Vérifier si trader_atr existe
    if (!function_exists('trader_atr')) {
        echo "❌ trader_atr n'existe pas!\n";
        exit(1);
    }

    echo "✅ trader_atr existe\n\n";

    // Test 1: Appel direct
    echo "=== Test 1: Appel direct trader_atr ===\n";
    /** @phpstan-ignore-next-line */
    $result = trader_atr($high, $low, $close, $PERIOD);
    
    if ($result === false) {
        echo "❌ trader_atr retourne false\n";
        $error = trader_get_error();
        echo "Erreur: " . ($error ?: 'Aucune erreur') . "\n";
    } elseif (!is_array($result)) {
        echo "❌ trader_atr ne retourne pas un tableau: " . gettype($result) . "\n";
    } else {
        echo "✅ trader_atr retourne un tableau de " . count($result) . " éléments\n";
        
        // Analyser les valeurs
        $nonZero = array_filter($result, fn($v) => $v != 0.0 && is_finite($v));
        $zeroCount = count($result) - count($nonZero);
        echo "Valeurs à 0.0: $zeroCount / " . count($result) . "\n";
        
        // Afficher les 10 dernières valeurs
        echo "\n10 dernières valeurs:\n";
        $last10 = array_slice($result, -10);
        foreach ($last10 as $idx => $val) {
            $realIdx = count($result) - 10 + $idx;
            echo "  [$realIdx] = $val\n";
        }
        
        // Dernière valeur
        $lastValue = end($result);
        echo "\nDernière valeur (end): $lastValue\n";
        echo "Dernière valeur (array access): " . ($result[count($result)-1] ?? 'N/A') . "\n";
        
        if (!empty($nonZero)) {
            echo "\nPremière valeur non-zéro: " . reset($nonZero) . " (index: " . array_key_first($nonZero) . ")\n";
            echo "Dernière valeur non-zéro: " . end($nonZero) . " (index: " . array_key_last($nonZero) . ")\n";
        }
        
        // Vérifier les NaN/Inf
        $finite = array_filter($result, fn($v) => is_finite($v));
        $nonFinite = count($result) - count($finite);
        if ($nonFinite > 0) {
            echo "⚠️  Valeurs non-finies (NaN/Inf): $nonFinite\n";
        }
        
        // Vérifier les valeurs très petites mais non-nulles
        $verySmall = array_filter($result, fn($v) => $v > 0.0 && $v < 0.0001);
        if (!empty($verySmall)) {
            echo "Valeurs très petites (>0 mais <0.0001): " . count($verySmall) . "\n";
            echo "Exemple: " . reset($verySmall) . "\n";
        }
    }

    echo "\n=== Test 2: Vérification des données ===\n";
    
    // Vérifier s'il y a des bougies plates (high == low)
    $flatCandles = 0;
    for ($i = 0; $i < count($high); $i++) {
        if ($high[$i] == $low[$i]) {
            $flatCandles++;
        }
    }
    echo "Bougies plates (high == low): $flatCandles / " . count($high) . "\n";
    
    // Calculer True Range manuellement pour les 5 premières
    echo "\n=== Test 3: Calcul True Range manuel (5 premiers) ===\n";
    for ($i = 1; $i < min(6, count($high)); $i++) {
        $tr = max(
            $high[$i] - $low[$i],
            abs($high[$i] - $close[$i-1]),
            abs($low[$i] - $close[$i-1])
        );
        echo "TR[$i] = max(" . ($high[$i] - $low[$i]) . ", " . abs($high[$i] - $close[$i-1]) . ", " . abs($low[$i] - $close[$i-1]) . ") = $tr\n";
    }
    
    // Test avec un subset plus petit
    echo "\n=== Test 4: Test avec subset (20 premières klines) ===\n";
    $highSub = array_slice($high, 0, 20);
    $lowSub = array_slice($low, 0, 20);
    $closeSub = array_slice($close, 0, 20);
    
    /** @phpstan-ignore-next-line */
    $resultSub = trader_atr($highSub, $lowSub, $closeSub, $PERIOD);
    if (is_array($resultSub) && !empty($resultSub)) {
        echo "Résultat avec subset: " . end($resultSub) . "\n";
    } else {
        echo "❌ Pas de résultat avec subset\n";
    }
    
    // Test avec period plus petit
    echo "\n=== Test 5: Test avec period=5 ===\n";
    /** @phpstan-ignore-next-line */
    $result5 = trader_atr($high, $low, $close, 5);
    if (is_array($result5) && !empty($result5)) {
        echo "Résultat avec period=5: " . end($result5) . "\n";
    } else {
        echo "❌ Pas de résultat avec period=5\n";
    }

} catch (PDOException $e) {
    echo "Erreur DB: " . $e->getMessage() . "\n";
    exit(1);
} catch (\Throwable $e) {
    echo "Erreur: " . $e->getMessage() . "\n";
    exit(1);
}

