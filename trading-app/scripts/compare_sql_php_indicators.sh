#!/bin/bash

# Script de comparaison SQL vs PHP pour les indicateurs MTF
# Usage: ./scripts/compare_sql_php_indicators.sh [symbol] [timeframe] [limit]

set -e

# Configuration par défaut
DEFAULT_SYMBOL="BTCUSDT"
DEFAULT_TIMEFRAME="5m"
DEFAULT_LIMIT=5

# Variables d'environnement ou valeurs par défaut
SYMBOL=${1:-$DEFAULT_SYMBOL}
TIMEFRAME=${2:-$DEFAULT_TIMEFRAME}
LIMIT=${3:-$DEFAULT_LIMIT}

echo "🔍 Comparaison SQL vs PHP - Indicateurs MTF"
echo "📊 Symbole: $SYMBOL"
echo "⏰ Timeframe: $TIMEFRAME"
echo "📈 Limite: $LIMIT signaux"
echo ""

# Vérifier que nous sommes dans le bon répertoire
if [ ! -f "bin/console" ]; then
    echo "❌ Erreur: Ce script doit être exécuté depuis la racine du projet trading-app"
    exit 1
fi

# Rafraîchir les vues matérialisées avant la comparaison
echo "🗄️ Rafraîchissement des vues matérialisées..."
php bin/console app:refresh-indicators 2>/dev/null || {
    echo "⚠️ Commande de rafraîchissement non disponible, utilisation du script SQL direct"
    ./scripts/refresh_indicators.sh "$SYMBOL" "$TIMEFRAME" 2>/dev/null || {
        echo "⚠️ Script de rafraîchissement non disponible, continuation sans rafraîchissement"
    }
}

echo ""

# Exécuter la commande de diagnostic avec comparaison
echo "🚀 Lancement de la comparaison SQL vs PHP..."
php bin/console app:diagnose-mtf-signals \
    --symbol="$SYMBOL" \
    --timeframe="$TIMEFRAME" \
    --limit="$LIMIT" \
    --output-format=table

echo ""
echo "✅ Comparaison terminée"
echo ""
echo "💡 Interprétation des résultats:"
echo "  ✅ OK = Correspondance parfaite entre SQL et PHP"
echo "  ❌ Diff = Différence détectée (vérifiez la tolérance)"
echo "  ⚠️ Partiel = Une seule méthode a des données"
echo "  ⚠️ Non-num = Valeurs non numériques"
echo ""
echo "🎯 Objectif: Taux de correspondance > 95%"
echo "📊 Si < 80%: Vérifiez la configuration des vues matérialisées"

