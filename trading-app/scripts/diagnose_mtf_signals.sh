#!/bin/bash

# Script de diagnostic MTF - Analyse des signaux
# Usage: ./scripts/diagnose_mtf_signals.sh [symbol] [timeframe] [limit]

set -e

# Configuration par défaut
DEFAULT_SYMBOL="BTCUSDT"
DEFAULT_TIMEFRAME="5m"
DEFAULT_LIMIT=10

# Variables d'environnement ou valeurs par défaut
SYMBOL=${1:-$DEFAULT_SYMBOL}
TIMEFRAME=${2:-$DEFAULT_TIMEFRAME}
LIMIT=${3:-$DEFAULT_LIMIT}

echo "🔍 Diagnostic MTF - Analyse des signaux"
echo "📊 Symbole: $SYMBOL"
echo "⏰ Timeframe: $TIMEFRAME"
echo "📈 Limite: $LIMIT signaux"
echo ""

# Vérifier que nous sommes dans le bon répertoire
if [ ! -f "bin/console" ]; then
    echo "❌ Erreur: Ce script doit être exécuté depuis la racine du projet trading-app"
    exit 1
fi

# Exécuter la commande de diagnostic
echo "🚀 Lancement du diagnostic..."
php bin/console app:diagnose-mtf-signals \
    --symbol="$SYMBOL" \
    --timeframe="$TIMEFRAME" \
    --limit="$LIMIT" \
    --output-format=table

echo ""
echo "✅ Diagnostic terminé"
echo ""
echo "💡 Conseils d'utilisation:"
echo "  - Pour analyser un autre symbole: ./scripts/diagnose_mtf_signals.sh ETHUSDT 5m 5"
echo "  - Pour exporter en JSON: php bin/console app:diagnose-mtf-signals --symbol=BTCUSDT --output-format=json > diagnostic.json"
echo "  - Pour analyser les 1m: ./scripts/diagnose_mtf_signals.sh BTCUSDT 1m 20"

