#!/bin/bash

# Script de rafraîchissement des vues matérialisées des indicateurs techniques
# Usage: ./scripts/refresh_indicators.sh [symbol] [timeframe]

set -e

# Configuration par défaut
DEFAULT_SYMBOL="BTCUSDT"
DEFAULT_TIMEFRAME="1m"
DEFAULT_DB_HOST="localhost"
DEFAULT_DB_PORT="5433"
DEFAULT_DB_NAME="trading_app"
DEFAULT_DB_USER="postgres"

# Variables d'environnement ou valeurs par défaut
SYMBOL=${1:-$DEFAULT_SYMBOL}
TIMEFRAME=${2:-$DEFAULT_TIMEFRAME}
DB_HOST=${DB_HOST:-$DEFAULT_DB_HOST}
DB_PORT=${DB_PORT:-$DEFAULT_DB_PORT}
DB_NAME=${DB_NAME:-$DEFAULT_DB_NAME}
DB_USER=${DB_USER:-$DEFAULT_DB_USER}

echo "🔄 Rafraîchissement des indicateurs techniques..."
echo "📊 Symbole: $SYMBOL"
echo "⏰ Timeframe: $TIMEFRAME"
echo "🗄️  Base de données: $DB_HOST:$DB_PORT/$DB_NAME"

# Fonction pour exécuter une requête SQL
execute_sql() {
    local query="$1"
    local description="$2"
    
    echo "  📈 $description..."
    
    if command -v psql >/dev/null 2>&1; then
        PGPASSWORD=password psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -c "$query"
    else
        # Utiliser Docker si psql n'est pas disponible localement
        docker exec trading_app_postgres psql -U "$DB_USER" -d "$DB_NAME" -c "$query"
    fi
    
    if [ $? -eq 0 ]; then
        echo "  ✅ $description - Succès"
    else
        echo "  ❌ $description - Erreur"
        return 1
    fi
}

# Liste des vues matérialisées à rafraîchir (5m timeframe)
MATERIALIZED_VIEWS=(
    "mv_rsi14_5m:RSI (14 périodes)"
    "mv_macd_5m:MACD"
    "mv_boll20_5m:Bollinger Bands (20 périodes)"
    "mv_donchian20_5m:Donchian Channels (20 périodes)"
    "mv_obv_5m:OBV (On-Balance Volume)"
    "mv_vwap_5m:VWAP"
    "mv_stochrsi_5m:StochRSI"
    "mv_adx14_5m:ADX (14 périodes)"
    "mv_ichimoku_5m:Ichimoku Kinko Hyo"
    "mv_ema_5m:EMA (9, 21, 50, 200)"
)

echo ""
echo "🚀 Début du rafraîchissement des vues matérialisées..."

# Rafraîchir chaque vue matérialisée
for view_info in "${MATERIALIZED_VIEWS[@]}"; do
    IFS=':' read -r view_name view_description <<< "$view_info"
    
    # Rafraîchir la vue matérialisée
    execute_sql "REFRESH MATERIALIZED VIEW CONCURRENTLY $view_name;" "$view_description"
    
    # Afficher les statistiques
    execute_sql "
        SELECT 
            symbol,
            COUNT(*) as total_records,
            MIN(bucket) as earliest_data,
            MAX(bucket) as latest_data
        FROM $view_name 
        WHERE symbol = '$SYMBOL'
        GROUP BY symbol;
    " "Statistiques pour $view_description"
    
    echo ""
done

echo "🎉 Rafraîchissement terminé avec succès!"
echo ""
echo "📊 Résumé des indicateurs disponibles:"
echo "   • RSI (14) - Momentum, surachat/survente"
echo "   • MACD - Momentum, croisements de signaux"
echo "   • Bollinger Bands (20) - Volatilité, support/résistance"
echo "   • Donchian Channels (20) - Volatilité, breakout"
echo "   • OBV - Volume, confirmation de tendance"
echo "   • ADX (14) - Force de tendance"
echo "   • Ichimoku - Système complet de tendance"
echo "   • StochRSI - RSI normalisé, signaux rapides"
echo "   • Choppiness Index (14) - Marché directionnel vs range"
echo "   • VWAP - Prix moyen pondéré par volume"
echo ""
echo "💡 Pour consulter les données:"
echo "   SELECT * FROM mv_rsi14_5m WHERE symbol = '$SYMBOL' ORDER BY bucket DESC LIMIT 10;"
