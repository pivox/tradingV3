#!/bin/bash

# Script de démonstration des indicateurs techniques
# Usage: ./scripts/demo_indicators.sh [symbol]

set -e

DEFAULT_SYMBOL="BTCUSDT"
SYMBOL=${1:-$DEFAULT_SYMBOL}

echo "📊 Démonstration des indicateurs techniques pour $SYMBOL"
echo "=================================================="

# Fonction pour afficher un indicateur
show_indicator() {
    local view_name="$1"
    local description="$2"
    local query="$3"
    
    echo ""
    echo "🔍 $description"
    echo "----------------------------------------"
    
    docker exec trading_app_postgres psql -U postgres -d trading_app -c "$query" 2>/dev/null || echo "  ❌ Erreur lors de l'affichage"
}

# 1. RSI (Relative Strength Index)
show_indicator "mv_rsi14_5m" "RSI (14 périodes) - Momentum" "
SELECT 
    bucket,
    ROUND(rsi::numeric, 2) as rsi,
    CASE 
        WHEN rsi > 70 THEN '🔴 SURACHAT'
        WHEN rsi < 30 THEN '🟢 SURVENTE'
        ELSE '⚪ NEUTRE'
    END as signal
FROM mv_rsi14_5m 
WHERE symbol = '$SYMBOL' 
ORDER BY bucket DESC 
LIMIT 5;
"

# 2. MACD (Moving Average Convergence Divergence)
show_indicator "mv_macd_5m" "MACD - Momentum" "
SELECT 
    bucket,
    ROUND(macd::numeric, 2) as macd,
    ROUND(signal::numeric, 2) as signal_line,
    ROUND(histogram::numeric, 2) as histogram,
    CASE 
        WHEN macd > signal AND histogram > 0 THEN '🟢 HAUSSIER'
        WHEN macd < signal AND histogram < 0 THEN '🔴 BAISSIER'
        ELSE '⚪ NEUTRE'
    END as trend
FROM mv_macd_5m 
WHERE symbol = '$SYMBOL' 
ORDER BY bucket DESC 
LIMIT 5;
"

# 3. Bollinger Bands
show_indicator "mv_boll20_5m" "Bollinger Bands (20 périodes) - Volatilité" "
SELECT 
    bucket,
    ROUND(sma::numeric, 2) as sma,
    ROUND(upper::numeric, 2) as upper_band,
    ROUND(lower::numeric, 2) as lower_band,
    ROUND(sd::numeric, 2) as std_dev
FROM mv_boll20_5m 
WHERE symbol = '$SYMBOL' 
ORDER BY bucket DESC 
LIMIT 5;
"

# 4. Donchian Channels
show_indicator "mv_donchian20_5m" "Donchian Channels (20 périodes) - Breakout" "
SELECT 
    bucket,
    ROUND(upper::numeric, 2) as upper_channel,
    ROUND(lower::numeric, 2) as lower_channel,
    ROUND((upper - lower)::numeric, 2) as range
FROM mv_donchian20_5m 
WHERE symbol = '$SYMBOL' 
ORDER BY bucket DESC 
LIMIT 5;
"

# 5. OBV (On-Balance Volume)
show_indicator "mv_obv_5m" "OBV - Volume" "
SELECT 
    bucket,
    ROUND(obv::numeric, 2) as obv,
    ROUND((obv - LAG(obv) OVER (ORDER BY bucket))::numeric, 2) as obv_change
FROM mv_obv_5m 
WHERE symbol = '$SYMBOL' 
ORDER BY bucket DESC 
LIMIT 5;
"

# 6. VWAP (Volume Weighted Average Price)
show_indicator "mv_vwap_5m" "VWAP - Prix moyen pondéré" "
SELECT 
    bucket,
    ROUND(vwap::numeric, 2) as vwap
FROM mv_vwap_5m 
WHERE symbol = '$SYMBOL' 
ORDER BY bucket DESC 
LIMIT 5;
"

# 7. StochRSI
show_indicator "mv_stochrsi_5m" "StochRSI - RSI normalisé" "
SELECT 
    bucket,
    ROUND(stoch_rsi::numeric, 3) as stoch_rsi,
    ROUND(stoch_rsi_d::numeric, 3) as stoch_rsi_d,
    CASE 
        WHEN stoch_rsi > 0.8 THEN '🔴 SURACHAT'
        WHEN stoch_rsi < 0.2 THEN '🟢 SURVENTE'
        ELSE '⚪ NEUTRE'
    END as signal
FROM mv_stochrsi_5m 
WHERE symbol = '$SYMBOL' 
ORDER BY bucket DESC 
LIMIT 5;
"

# 8. ADX (Average Directional Index)
show_indicator "mv_adx14_5m" "ADX - Force de tendance" "
SELECT 
    bucket,
    ROUND(plus_di::numeric, 2) as plus_di,
    ROUND(minus_di::numeric, 2) as minus_di,
    ROUND(adx::numeric, 2) as adx,
    CASE 
        WHEN adx > 25 THEN '🟢 TENDANCE FORTE'
        WHEN adx > 20 THEN '🟡 TENDANCE MODÉRÉE'
        ELSE '🔴 TENDANCE FAIBLE'
    END as trend_strength
FROM mv_adx14_5m 
WHERE symbol = '$SYMBOL' 
ORDER BY bucket DESC 
LIMIT 5;
"

# 9. Ichimoku Kinko Hyo
show_indicator "mv_ichimoku_5m" "Ichimoku - Système complet" "
SELECT 
    bucket,
    ROUND(tenkan::numeric, 2) as tenkan,
    ROUND(kijun::numeric, 2) as kijun,
    ROUND(senkou_a::numeric, 2) as senkou_a,
    ROUND(chikou::numeric, 2) as chikou,
    CASE 
        WHEN tenkan > kijun THEN '🟢 HAUSSIER'
        WHEN tenkan < kijun THEN '🔴 BAISSIER'
        ELSE '⚪ NEUTRE'
    END as trend
FROM mv_ichimoku_5m 
WHERE symbol = '$SYMBOL' 
ORDER BY bucket DESC 
LIMIT 5;
"

echo ""
echo "🎯 Résumé des signaux pour $SYMBOL"
echo "=================================="

# Analyse combinée des signaux
docker exec trading_app_postgres psql -U postgres -d trading_app -c "
WITH latest_signals AS (
    SELECT 
        r.bucket,
        r.rsi,
        m.macd,
        m.signal as macd_signal,
        b.upper as bb_upper,
        b.lower as bb_lower,
        b.sma as bb_sma,
        v.vwap
    FROM mv_rsi14_5m r
    LEFT JOIN mv_macd_5m m ON r.symbol = m.symbol AND r.bucket = m.bucket
    LEFT JOIN mv_boll20_5m b ON r.symbol = b.symbol AND r.bucket = b.bucket
    LEFT JOIN mv_vwap_5m v ON r.symbol = v.symbol AND r.bucket = v.bucket
    WHERE r.symbol = '$SYMBOL'
    ORDER BY r.bucket DESC
    LIMIT 1
)
SELECT 
    'RSI: ' || ROUND(rsi::numeric, 1) || 
    CASE WHEN rsi > 70 THEN ' (SURACHAT)' 
         WHEN rsi < 30 THEN ' (SURVENTE)' 
         ELSE ' (NEUTRE)' END as rsi_signal,
    'MACD: ' || ROUND(macd::numeric, 2) || 
    CASE WHEN macd > macd_signal THEN ' (HAUSSIER)' 
         WHEN macd < macd_signal THEN ' (BAISSIER)' 
         ELSE ' (NEUTRE)' END as macd_signal,
    'BB: ' || ROUND(bb_sma::numeric, 0) || ' ±' || ROUND((bb_upper - bb_sma)::numeric, 0) as bollinger_info,
    'VWAP: ' || ROUND(vwap::numeric, 0) as vwap_info
FROM latest_signals;
" 2>/dev/null || echo "  ❌ Erreur lors de l'analyse combinée"

# 10. EMA (Exponential Moving Average)
show_indicator "mv_ema_5m" "EMA (9, 21, 50, 200) - Tendance" "
SELECT 
    bucket,
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
    END as tendance
FROM mv_ema_5m 
WHERE symbol = '$SYMBOL' 
ORDER BY bucket DESC 
LIMIT 5;
"

echo ""
echo "📈 Indicateurs disponibles:"
echo "   • RSI (14) - Détection surachat/survente"
echo "   • MACD - Croisements de signaux"
echo "   • Bollinger Bands - Support/résistance dynamique"
echo "   • Donchian Channels - Niveaux de breakout"
echo "   • OBV - Confirmation de tendance par volume"
echo "   • VWAP - Prix de référence institutionnel"
echo "   • StochRSI - RSI normalisé, signaux rapides"
echo "   • ADX (14) - Force de tendance"
echo "   • Ichimoku - Système complet de tendance"
echo "   • EMA (9,21,50,200) - Tendance, support/résistance"
echo ""
echo "🔄 Pour rafraîchir les données: ./scripts/refresh_indicators.sh"
echo "🧪 Pour tester les indicateurs: ./scripts/test_indicators.sh"
