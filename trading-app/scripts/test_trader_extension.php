<?php
/**
 * Script de test de l'extension trader
 * Vérifie si trader_atr() et trader_macd() fonctionnent correctement
 */

require __DIR__ . '/../vendor/autoload.php';

use Doctrine\DBAL\DriverManager;

echo "=== Test de l'extension trader ===\n\n";

// 1. Vérifier si l'extension est chargée
echo "1. Vérification de l'extension:\n";
$loaded = extension_loaded('trader');
echo "   Extension chargée: " . ($loaded ? "✅ OUI" : "❌ NON") . "\n";

if (!$loaded) {
    echo "\n❌ L'extension trader n'est pas chargée!\n";
    echo "   Installez-la avec: pecl install trader\n";
    exit(1);
}

// 2. Vérifier les fonctions disponibles
echo "\n2. Fonctions disponibles:\n";
$functions = [
    'trader_atr' => function_exists('trader_atr'),
    'trader_macd' => function_exists('trader_macd'),
    'trader_ema' => function_exists('trader_ema'),
    'trader_rsi' => function_exists('trader_rsi'),
];

foreach ($functions as $func => $exists) {
    echo "   $func: " . ($exists ? "✅" : "❌") . "\n";
}

// 3. Récupérer les vraies données de la base
echo "\n3. Récupération des vraies données:\n";
try {
    // Utiliser les variables d'environnement du conteneur Docker
    $connectionParams = [
        'dbname' => getenv('DATABASE_NAME') ?: 'trading_app',
        'user' => getenv('DATABASE_USER') ?: 'postgres',
        'password' => getenv('DATABASE_PASSWORD') ?: 'postgres',
        'host' => getenv('DATABASE_HOST') ?: 'trading-app-db',
        'port' => (int)(getenv('DATABASE_PORT') ?: 5432),
        'driver' => 'pdo_pgsql',
    ];

    $conn = DriverManager::getConnection($connectionParams);
    
    $sql = "SELECT open_time, open_price, high_price, low_price, close_price, volume 
            FROM klines 
            WHERE symbol = 'PIPPINUSDT' AND timeframe = '1m' 
            ORDER BY open_time DESC 
            LIMIT 200";

    $klines = $conn->fetchAllAssociative($sql);
    
    if (empty($klines)) {
        echo "   ⚠️  Aucune kline trouvée, utilisation de données de test\n";
        // Générer des données de test
        $basePrice = 0.037;
        for ($i = 0; $i < 200; $i++) {
            $variation = (rand(-100, 100) / 10000);
            $close = $basePrice + $variation;
            $high = $close + (rand(0, 50) / 100000);
            $low = $close - (rand(0, 50) / 100000);
            $closes[] = (float)$close;
            $highs[] = (float)$high;
            $lows[] = (float)$low;
        }
    } else {
        // Inverser pour avoir les plus anciennes en premier
        $klines = array_reverse($klines);
        $closes = array_map('floatval', array_column($klines, 'close_price'));
        $highs = array_map('floatval', array_column($klines, 'high_price'));
        $lows = array_map('floatval', array_column($klines, 'low_price'));
        echo "   ✅ " . count($klines) . " klines récupérées de la base\n";
    }
    
    echo "   Première close: " . $closes[0] . "\n";
    echo "   Dernière close: " . end($closes) . "\n";
    $ranges = array_map(fn($h, $l) => $h - $l, $highs, $lows);
    echo "   Range moyen: " . (array_sum($ranges) / count($ranges)) . "\n";
    echo "   Range min: " . min($ranges) . "\n";
    echo "   Range max: " . max($ranges) . "\n";
    
} catch (\Exception $e) {
    echo "   ⚠️  Erreur de connexion: " . $e->getMessage() . "\n";
    echo "   Utilisation de données de test\n";
    // Générer des données de test en fallback
    $basePrice = 0.037;
    for ($i = 0; $i < 200; $i++) {
        $variation = (rand(-100, 100) / 10000);
        $close = $basePrice + $variation;
        $high = $close + (rand(0, 50) / 100000);
        $low = $close - (rand(0, 50) / 100000);
        $closes[] = (float)$close;
        $highs[] = (float)$high;
        $lows[] = (float)$low;
    }
}

// 5. Test ATR
echo "\n5. Test trader_atr():\n";
try {
    $atrResult = trader_atr($highs, $lows, $closes, 14);
    
    if ($atrResult === false) {
        echo "   ❌ trader_atr() a retourné false\n";
    } elseif (!is_array($atrResult)) {
        echo "   ❌ trader_atr() n'a pas retourné un array\n";
        var_dump($atrResult);
    } else {
        $atrSeries = array_values($atrResult);
        $atrCount = count($atrSeries);
        $atr = !empty($atrSeries) ? end($atrSeries) : null;
        
        echo "   ✅ Résultat: array avec $atrCount valeurs\n";
        echo "   ATR final: " . ($atr !== null ? $atr : "NULL") . "\n";
        
        if ($atr === 0.0) {
            echo "   ⚠️  ATR = 0.0!\n";
            echo "   Dernières 10 valeurs: " . implode(', ', array_slice($atrSeries, -10)) . "\n";
            
            // Compter les zéros
            $zeroCount = count(array_filter($atrSeries, fn($v) => $v == 0.0));
            echo "   Nombre de zéros dans la série: $zeroCount / $atrCount\n";
            
            // Vérifier les valeurs non-nulles
            $nonZero = array_filter($atrSeries, fn($v) => $v != 0.0);
            if (!empty($nonZero)) {
                echo "   Première valeur non-nulle: " . reset($nonZero) . "\n";
                echo "   Dernière valeur non-nulle: " . end($nonZero) . "\n";
            } else {
                echo "   ⚠️  Toutes les valeurs sont à 0.0!\n";
            }
        } else {
            echo "   ✅ ATR valide: $atr\n";
        }
    }
} catch (\Throwable $e) {
    echo "   ❌ Erreur: " . $e->getMessage() . "\n";
    echo "   Trace: " . $e->getTraceAsString() . "\n";
}

// 6. Test MACD
echo "\n6. Test trader_macd():\n";
try {
    $macdResult = trader_macd($closes, 12, 26, 9);
    
    if ($macdResult === false) {
        echo "   ❌ trader_macd() a retourné false\n";
    } elseif (!is_array($macdResult)) {
        echo "   ❌ trader_macd() n'a pas retourné un array\n";
        var_dump($macdResult);
    } else {
        if (!isset($macdResult[0], $macdResult[1], $macdResult[2])) {
            echo "   ❌ Format invalide (attendu [macd, signal, hist])\n";
            var_dump($macdResult);
        } else {
            $macdSeries = array_values(array_map('floatval', (array)$macdResult[0]));
            $signalSeries = array_values(array_map('floatval', (array)$macdResult[1]));
            $histSeries = array_values(array_map('floatval', (array)$macdResult[2]));
            
            $macd = !empty($macdSeries) ? end($macdSeries) : null;
            $signal = !empty($signalSeries) ? end($signalSeries) : null;
            $hist = !empty($histSeries) ? end($histSeries) : null;
            
            echo "   ✅ Résultat: 3 séries\n";
            echo "   MACD: " . ($macd !== null ? $macd : "NULL") . "\n";
            echo "   Signal: " . ($signal !== null ? $signal : "NULL") . "\n";
            echo "   Hist: " . ($hist !== null ? $hist : "NULL") . "\n";
            
            if ($macd === 0.0 && $signal === 0.0 && $hist === 0.0) {
                echo "   ⚠️  Toutes les valeurs MACD = 0.0!\n";
                
                // Vérifier les séries
                $macdNonZero = count(array_filter($macdSeries, fn($v) => $v != 0.0));
                $signalNonZero = count(array_filter($signalSeries, fn($v) => $v != 0.0));
                $histNonZero = count(array_filter($histSeries, fn($v) => $v != 0.0));
                
                echo "   Valeurs non-nulles MACD: $macdNonZero / " . count($macdSeries) . "\n";
                echo "   Valeurs non-nulles Signal: $signalNonZero / " . count($signalSeries) . "\n";
                echo "   Valeurs non-nulles Hist: $histNonZero / " . count($histSeries) . "\n";
                
                if ($macdNonZero > 0) {
                    echo "   Dernières 5 MACD: " . implode(', ', array_slice($macdSeries, -5)) . "\n";
                }
            } else {
                echo "   ✅ MACD valide\n";
            }
        }
    }
} catch (\Throwable $e) {
    echo "   ❌ Erreur: " . $e->getMessage() . "\n";
    echo "   Trace: " . $e->getTraceAsString() . "\n";
}

// 7. Test EMA pour comparaison
echo "\n7. Test trader_ema() (pour comparaison):\n";
try {
    $ema12 = trader_ema($closes, 12);
    $ema26 = trader_ema($closes, 26);
    
    if ($ema12 !== false && $ema26 !== false) {
        $ema12Series = array_values(array_map('floatval', (array)$ema12));
        $ema26Series = array_values(array_map('floatval', (array)$ema26));
        
        $ema12Val = !empty($ema12Series) ? end($ema12Series) : null;
        $ema26Val = !empty($ema26Series) ? end($ema26Series) : null;
        
        echo "   ✅ EMA(12): " . ($ema12Val !== null ? $ema12Val : "NULL") . "\n";
        echo "   ✅ EMA(26): " . ($ema26Val !== null ? $ema26Val : "NULL") . "\n";
        
        if ($ema12Val !== null && $ema26Val !== null) {
            $macdManual = $ema12Val - $ema26Val;
            echo "   MACD manuel (EMA12 - EMA26): $macdManual\n";
            
            if (abs($macdManual) < 1e-10) {
                echo "   ⚠️  EMA12 ≈ EMA26, donc MACD ≈ 0 (normal pour marché stable)\n";
            }
        }
    } else {
        echo "   ❌ Erreur dans trader_ema()\n";
    }
} catch (\Throwable $e) {
    echo "   ❌ Erreur: " . $e->getMessage() . "\n";
}

// 8. Résumé
echo "\n=== Résumé ===\n";
echo "Extension trader: " . ($loaded ? "✅ Chargée" : "❌ Non chargée") . "\n";
echo "Fonctions disponibles: " . count(array_filter($functions)) . " / " . count($functions) . "\n";
echo "Données de test: " . count($closes) . " valeurs\n";

echo "\n=== Fin du test ===\n";

