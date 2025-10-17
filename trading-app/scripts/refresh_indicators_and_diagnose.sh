#!/bin/bash

# Script complet : Rafraîchissement des indicateurs + Diagnostic MTF
# Usage: ./scripts/refresh_indicators_and_diagnose.sh [symbol] [timeframe] [limit]

set -e

# Configuration par défaut
DEFAULT_SYMBOL="BTCUSDT"
DEFAULT_TIMEFRAME="5m"
DEFAULT_LIMIT=10

# Variables d'environnement ou valeurs par défaut
SYMBOL=${1:-$DEFAULT_SYMBOL}
TIMEFRAME=${2:-$DEFAULT_TIMEFRAME}
LIMIT=${3:-$DEFAULT_LIMIT}

echo "🔄 Rafraîchissement des indicateurs + Diagnostic MTF"
echo "📊 Symbole: $SYMBOL"
echo "⏰ Timeframe: $TIMEFRAME"
echo "📈 Limite: $LIMIT signaux"
echo ""

# Vérifier que nous sommes dans le bon répertoire
if [ ! -f "bin/console" ]; then
    echo "❌ Erreur: Ce script doit être exécuté depuis la racine du projet trading-app"
    exit 1
fi

# Étape 1: Rafraîchir les vues matérialisées
echo "🗄️ Rafraîchissement des vues matérialisées..."
php bin/console app:refresh-indicators 2>/dev/null || {
    echo "⚠️ Commande de rafraîchissement non disponible, utilisation du script SQL direct"
    ./scripts/refresh_indicators.sh "$SYMBOL" "$TIMEFRAME" 2>/dev/null || {
        echo "⚠️ Script de rafraîchissement non disponible, continuation sans rafraîchissement"
    }
}

echo ""

# Étape 2: Diagnostic MTF
echo "🔍 Lancement du diagnostic MTF..."
php bin/console app:diagnose-mtf-signals \
    --symbol="$SYMBOL" \
    --timeframe="$TIMEFRAME" \
    --limit="$LIMIT" \
    --output-format=table

echo ""
echo "✅ Processus terminé"
echo ""
echo "💡 Conseils d'utilisation:"
echo "  - Pour un diagnostic complet: ./scripts/refresh_indicators_and_diagnose.sh BTCUSDT 5m 20"
echo "  - Pour analyser les 1m: ./scripts/refresh_indicators_and_diagnose.sh BTCUSDT 1m 50"
echo "  - Pour exporter en JSON: php bin/console app:diagnose-mtf-signals --symbol=BTCUSDT --output-format=json > diagnostic.json"

