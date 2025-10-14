#!/bin/bash

# Script de démonstration du système EMA (Exponential Moving Average)
# Montre l'utilisation pratique des EMA pour l'analyse technique

echo "📈 Démonstration du système EMA (Exponential Moving Average)"
echo "=========================================================="

# Configuration
DB_HOST="localhost"
DB_PORT="5432"
DB_NAME="trading_app"
DB_USER="postgres"

# Fonction pour exécuter une requête SQL
run_sql() {
    docker exec trading_app_postgres psql -U postgres -d trading_app -c "$1"
}

echo ""
echo "🎯 Analyse technique avec EMA - BTCUSDT"
echo "======================================="

echo ""
echo "1️⃣ Situation actuelle des EMA"
echo "-----------------------------"
run_sql "
-- Dernières valeurs EMA avec interprétation
WITH latest_ema AS (
    SELECT 
        symbol,
        bucket,
        ema9,
        ema21,
        ema50,
        ema200,
        -- Calcul des distances entre EMA
        ema9 - ema21 as distance_9_21,
        ema21 - ema50 as distance_21_50,
        ema50 - ema200 as distance_50_200,
        -- Pourcentage de distance
        ROUND(((ema9 - ema21) / ema21 * 100)::numeric, 4) as pct_9_21,
        ROUND(((ema21 - ema50) / ema50 * 100)::numeric, 4) as pct_21_50,
        ROUND(((ema50 - ema200) / ema200 * 100)::numeric, 4) as pct_50_200
    FROM mv_ema_5m 
    WHERE symbol = 'BTCUSDT' 
    ORDER BY bucket DESC 
    LIMIT 1
)
SELECT 
    symbol,
    bucket as timestamp,
    ROUND(ema9::numeric, 2) as ema9,
    ROUND(ema21::numeric, 2) as ema21,
    ROUND(ema50::numeric, 2) as ema50,
    ROUND(ema200::numeric, 2) as ema200,
    CASE 
        WHEN ema9 > ema21 AND ema21 > ema50 AND ema50 > ema200 THEN '🟢 Tendance haussière forte'
        WHEN ema9 > ema21 AND ema21 > ema50 THEN '🟡 Tendance haussière modérée'
        WHEN ema9 < ema21 AND ema21 < ema50 AND ema50 < ema200 THEN '🔴 Tendance baissière forte'
        WHEN ema9 < ema21 AND ema21 < ema50 THEN '🟠 Tendance baissière modérée'
        ELSE '⚪ Tendance mixte/consolidation'
    END as tendance,
    pct_9_21 || '%' as distance_9_21,
    pct_21_50 || '%' as distance_21_50,
    pct_50_200 || '%' as distance_50_200
FROM latest_ema;
"

echo ""
echo "2️⃣ Signaux de trading EMA"
echo "-------------------------"
run_sql "
-- Signaux basés sur les croisements EMA
WITH ema_signals AS (
    SELECT 
        symbol,
        bucket,
        ema9,
        ema21,
        ema50,
        ema200,
        LAG(ema9) OVER (ORDER BY bucket) as prev_ema9,
        LAG(ema21) OVER (ORDER BY bucket) as prev_ema21,
        LAG(ema50) OVER (ORDER BY bucket) as prev_ema50
    FROM mv_ema_5m 
    WHERE symbol = 'BTCUSDT' 
    ORDER BY bucket DESC 
    LIMIT 10
)
SELECT 
    bucket as timestamp,
    ROUND(ema9::numeric, 2) as ema9,
    ROUND(ema21::numeric, 2) as ema21,
    ROUND(ema50::numeric, 2) as ema50,
    ROUND(ema200::numeric, 2) as ema200,
    CASE 
        WHEN ema9 > ema21 AND prev_ema9 <= prev_ema21 THEN '🟢 Signal ACHAT (EMA9 > EMA21)'
        WHEN ema9 < ema21 AND prev_ema9 >= prev_ema21 THEN '🔴 Signal VENTE (EMA9 < EMA21)'
        WHEN ema21 > ema50 AND prev_ema21 <= prev_ema50 THEN '🟢 Signal ACHAT fort (EMA21 > EMA50)'
        WHEN ema21 < ema50 AND prev_ema21 >= prev_ema50 THEN '🔴 Signal VENTE fort (EMA21 < EMA50)'
        ELSE '⚪ Pas de signal'
    END as signal
FROM ema_signals
WHERE prev_ema9 IS NOT NULL
ORDER BY bucket DESC;
"

echo ""
echo "3️⃣ Support et résistance EMA"
echo "----------------------------"
run_sql "
-- EMA comme support/résistance
WITH price_ema AS (
    SELECT 
        k.symbol,
        k.open_time,
        k.close_price,
        e.ema9,
        e.ema21,
        e.ema50,
        e.ema200,
        -- Distance du prix aux EMA
        ROUND(((k.close_price - e.ema9) / e.ema9 * 100)::numeric, 2) as dist_ema9,
        ROUND(((k.close_price - e.ema21) / e.ema21 * 100)::numeric, 2) as dist_ema21,
        ROUND(((k.close_price - e.ema50) / e.ema50 * 100)::numeric, 2) as dist_ema50,
        ROUND(((k.close_price - e.ema200) / e.ema200 * 100)::numeric, 2) as dist_ema200
    FROM klines k
    JOIN mv_ema_5m e ON k.symbol = e.symbol 
        AND DATE_TRUNC('minute', k.open_time) = e.bucket
    WHERE k.symbol = 'BTCUSDT' 
        AND k.timeframe = '5m'
    ORDER BY k.open_time DESC 
    LIMIT 5
)
SELECT 
    open_time as timestamp,
    ROUND(close_price::numeric, 2) as prix,
    ROUND(ema9::numeric, 2) as ema9,
    ROUND(ema21::numeric, 2) as ema21,
    ROUND(ema50::numeric, 2) as ema50,
    ROUND(ema200::numeric, 2) as ema200,
    CASE 
        WHEN close_price > ema9 THEN '🟢 Au-dessus EMA9'
        ELSE '🔴 En-dessous EMA9'
    END as position_ema9,
    CASE 
        WHEN close_price > ema21 THEN '🟢 Au-dessus EMA21'
        ELSE '🔴 En-dessous EMA21'
    END as position_ema21,
    CASE 
        WHEN close_price > ema50 THEN '🟢 Au-dessus EMA50'
        ELSE '🔴 En-dessous EMA50'
    END as position_ema50,
    CASE 
        WHEN close_price > ema200 THEN '🟢 Au-dessus EMA200'
        ELSE '🔴 En-dessous EMA200'
    END as position_ema200
FROM price_ema;
"

echo ""
echo "4️⃣ Comparaison EMA temps réel vs backtest"
echo "----------------------------------------"
run_sql "
-- Comparaison des calculs EMA
WITH real_time AS (
    SELECT 
        bucket,
        ema9,
        ema21,
        ema50,
        ema200
    FROM mv_ema_5m 
    WHERE symbol = 'BTCUSDT' 
    ORDER BY bucket DESC 
    LIMIT 1
),
backtest AS (
    SELECT 
        open_time,
        ema20,
        ema50
    FROM v_ema_strict_5m 
    WHERE symbol = 'BTCUSDT' 
    ORDER BY open_time DESC 
    LIMIT 1
)
SELECT 
    'Comparaison EMA' as type,
    rt.bucket as real_time_bucket,
    bt.open_time as backtest_time,
    ROUND(rt.ema9::numeric, 2) as real_ema9,
    ROUND(rt.ema21::numeric, 2) as real_ema21,
    ROUND(rt.ema50::numeric, 2) as real_ema50,
    ROUND(rt.ema200::numeric, 2) as real_ema200,
    ROUND(bt.ema20::numeric, 2) as backtest_ema20,
    ROUND(bt.ema50::numeric, 2) as backtest_ema50,
    ROUND(ABS(rt.ema50 - bt.ema50)::numeric, 4) as difference_ema50
FROM real_time rt
CROSS JOIN backtest bt;
"

echo ""
echo "5️⃣ Statistiques EMA sur 24h"
echo "---------------------------"
run_sql "
-- Statistiques EMA sur les dernières 24h
WITH ema_stats AS (
    SELECT 
        symbol,
        bucket,
        ema9,
        ema21,
        ema50,
        ema200,
        -- Volatilité des EMA
        ABS(ema9 - ema21) as volatility_9_21,
        ABS(ema21 - ema50) as volatility_21_50,
        ABS(ema50 - ema200) as volatility_50_200
    FROM mv_ema_5m 
    WHERE symbol = 'BTCUSDT' 
        AND bucket >= NOW() - INTERVAL '24 hours'
    ORDER BY bucket DESC
)
SELECT 
    'Statistiques 24h' as periode,
    COUNT(*) as nb_bougies,
    ROUND(AVG(ema9)::numeric, 2) as avg_ema9,
    ROUND(AVG(ema21)::numeric, 2) as avg_ema21,
    ROUND(AVG(ema50)::numeric, 2) as avg_ema50,
    ROUND(AVG(ema200)::numeric, 2) as avg_ema200,
    ROUND(AVG(volatility_9_21)::numeric, 2) as avg_vol_9_21,
    ROUND(AVG(volatility_21_50)::numeric, 2) as avg_vol_21_50,
    ROUND(AVG(volatility_50_200)::numeric, 2) as avg_vol_50_200,
    ROUND(MIN(ema9)::numeric, 2) as min_ema9,
    ROUND(MAX(ema9)::numeric, 2) as max_ema9,
    ROUND((MAX(ema9) - MIN(ema9))::numeric, 2) as range_ema9
FROM ema_stats;
"

echo ""
echo "6️⃣ Recommandations de trading"
echo "-----------------------------"
run_sql "
-- Recommandations basées sur les EMA
WITH latest_data AS (
    SELECT 
        e.symbol,
        e.bucket,
        e.ema9,
        e.ema21,
        e.ema50,
        e.ema200,
        k.close_price,
        -- Tendances
        CASE WHEN e.ema9 > e.ema21 THEN 1 ELSE -1 END as trend_short,
        CASE WHEN e.ema21 > e.ema50 THEN 1 ELSE -1 END as trend_medium,
        CASE WHEN e.ema50 > e.ema200 THEN 1 ELSE -1 END as trend_long
    FROM mv_ema_5m e
    JOIN klines k ON e.symbol = k.symbol 
        AND DATE_TRUNC('minute', k.open_time) = e.bucket
    WHERE e.symbol = 'BTCUSDT' 
        AND k.timeframe = '5m'
    ORDER BY e.bucket DESC 
    LIMIT 1
)
SELECT 
    symbol,
    bucket as timestamp,
    ROUND(close_price::numeric, 2) as prix_actuel,
    ROUND(ema9::numeric, 2) as ema9,
    ROUND(ema21::numeric, 2) as ema21,
    ROUND(ema50::numeric, 2) as ema50,
    ROUND(ema200::numeric, 2) as ema200,
    CASE 
        WHEN trend_short + trend_medium + trend_long = 3 THEN '🟢 ACHAT FORT - Toutes EMA alignées haussière'
        WHEN trend_short + trend_medium + trend_long = 2 THEN '🟡 ACHAT MODÉRÉ - 2/3 EMA haussières'
        WHEN trend_short + trend_medium + trend_long = 1 THEN '⚪ NEUTRE - EMA mixtes'
        WHEN trend_short + trend_medium + trend_long = -1 THEN '⚪ NEUTRE - EMA mixtes'
        WHEN trend_short + trend_medium + trend_long = -2 THEN '🟠 VENTE MODÉRÉE - 2/3 EMA baissières'
        WHEN trend_short + trend_medium + trend_long = -3 THEN '🔴 VENTE FORTE - Toutes EMA alignées baissière'
    END as recommandation,
    CASE 
        WHEN close_price > ema9 THEN 'Prix au-dessus EMA9 (support court terme)'
        ELSE 'Prix en-dessous EMA9 (résistance court terme)'
    END as niveau_technique
FROM latest_data;
"

echo ""
echo "✅ Démonstration du système EMA terminée !"
echo ""
echo "📊 Résumé des fonctionnalités démontrées :"
echo "   • Analyse de tendance avec EMA multiples"
echo "   • Signaux de trading basés sur les croisements"
echo "   • Support/résistance dynamiques"
echo "   • Comparaison temps réel vs backtest"
echo "   • Statistiques et volatilité"
echo "   • Recommandations automatisées"
echo ""
echo "🎯 Le système EMA est prêt pour :"
echo "   • Trading automatisé"
echo "   • Analyse technique en temps réel"
echo "   • Backtesting avec précision"
echo "   • Intégration dans les stratégies"
