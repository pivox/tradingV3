#!/bin/bash

# Script de test des indicateurs techniques
# Usage: ./scripts/test_indicators.sh [symbol]

set -e

DEFAULT_SYMBOL="BTCUSDT"
SYMBOL=${1:-$DEFAULT_SYMBOL}

echo "🧪 Test des indicateurs techniques pour $SYMBOL..."

# Test des vues matérialisées
test_view() {
    local view_name="$1"
    local description="$2"
    
    echo "  📊 Test: $description"
    
    result=$(docker exec trading_app_postgres psql -U postgres -d trading_app -t -c "
        SELECT COUNT(*) FROM $view_name WHERE symbol = '$SYMBOL';
    " 2>/dev/null | tr -d ' ')
    
    if [ "$result" -gt 0 ]; then
        echo "  ✅ $description - $result enregistrements trouvés"
    else
        echo "  ❌ $description - Aucun enregistrement trouvé"
    fi
}

# Tests des indicateurs (5m timeframe)
test_view "mv_rsi14_5m" "RSI (14 périodes)"
test_view "mv_macd_5m" "MACD"
test_view "mv_boll20_5m" "Bollinger Bands"
test_view "mv_donchian20_5m" "Donchian Channels"
test_view "mv_obv_5m" "OBV"
test_view "mv_vwap_5m" "VWAP"
test_view "mv_stochrsi_5m" "StochRSI"
test_view "mv_adx14_5m" "ADX (14 périodes)"
test_view "mv_ichimoku_5m" "Ichimoku"
test_view "mv_ema_5m" "EMA (9, 21, 50, 200)"

echo "🎉 Tests terminés!"
