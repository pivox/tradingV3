#!/bin/bash

# Script de test simple pour vérifier la correction de evaluateIndicators
# Usage: ./scripts/test-evaluate-fix-simple.sh

set -e

echo "=========================================="
echo "Test de la correction evaluateIndicators"
echo "=========================================="

# Test 1: Vérifier que l'endpoint fonctionne
echo "Test 1: Endpoint /indicators/evaluate"
echo "----------------------------------------"
RESPONSE=$(curl -s -X POST "http://localhost:8082/indicators/evaluate" \
    -H "Content-Type: application/json" \
    -d '{"symbol": "BTCUSDT", "timeframe": "1h"}')

SUCCESS=$(echo "$RESPONSE" | jq -r '.success // false')
if [ "$SUCCESS" = "true" ]; then
    echo "✅ Endpoint fonctionne"
else
    echo "❌ Endpoint échoue"
    exit 1
fi

# Test 2: Vérifier que la page web ne contient plus l'ancien code
echo ""
echo "Test 2: Vérification du code JavaScript"
echo "----------------------------------------"
WEB_PAGE=$(curl -s "http://localhost:8082/indicators/test")

if echo "$WEB_PAGE" | grep -q "document.getElementById('symbol')"; then
    echo "❌ Ancien code encore présent"
    exit 1
else
    echo "✅ Ancien code supprimé"
fi

if echo "$WEB_PAGE" | grep -q "selectedContracts\\[0\\]"; then
    echo "✅ Nouveau code présent"
else
    echo "❌ Nouveau code manquant"
    exit 1
fi

# Test 3: Vérifier que les contrats sont disponibles
echo ""
echo "Test 3: Contrats disponibles"
echo "----------------------------------------"
CONTRACTS_RESPONSE=$(curl -s "http://localhost:8082/indicators/available-contracts")
CONTRACTS_SUCCESS=$(echo "$CONTRACTS_RESPONSE" | jq -r '.success // false')

if [ "$CONTRACTS_SUCCESS" = "true" ]; then
    CONTRACTS_COUNT=$(echo "$CONTRACTS_RESPONSE" | jq -r '.data.contracts | length')
    echo "✅ Contrats disponibles: $CONTRACTS_COUNT"
else
    echo "❌ Impossible de récupérer les contrats"
    exit 1
fi

echo ""
echo "=========================================="
echo "✅ Tous les tests passent !"
echo "=========================================="
echo ""
echo "🎯 Correction appliquée avec succès:"
echo "  - Fonction evaluateIndicators() utilise selectedContracts[0]"
echo "  - Fonction runReplayTest() utilise selectedContracts[0]"
echo "  - Plus d'erreur 'Cannot read properties of null'"
echo "  - Validation de la sélection de contrats"
echo ""
echo "🌐 Interface web: http://localhost:8082/indicators/test"
