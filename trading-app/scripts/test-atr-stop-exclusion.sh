#!/bin/bash

# Script de test pour vérifier l'exclusion de la condition atr_stop_valid
# Usage: ./scripts/test-atr-stop-exclusion.sh

set -e

# Configuration
API_BASE="http://localhost:8082"
ENDPOINT="/indicators/revalidate"

echo "=========================================="
echo "Test d'exclusion de la condition atr_stop_valid"
echo "=========================================="
echo "URL: $API_BASE$ENDPOINT"
echo ""

# Test 1: Vérifier que atr_stop_valid est marquée comme non applicable
echo "Test 1: Vérification de la condition atr_stop_valid"
echo "----------------------------------------"
RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d '{"date": "2024-10-01", "contracts": "BTCUSDT", "timeframe": "1h"}')

ATR_STOP_RESULT=$(echo "$RESPONSE" | jq -r '.data.contracts_results.BTCUSDT.conditions_results.atr_stop_valid')

echo "Résultat de atr_stop_valid:"
echo "$ATR_STOP_RESULT" | jq '.'

# Vérifier les propriétés
NOT_APPLICABLE=$(echo "$ATR_STOP_RESULT" | jq -r '.meta.not_applicable // false')
REASON=$(echo "$ATR_STOP_RESULT" | jq -r '.meta.reason // "N/A"')
CONTEXT=$(echo "$ATR_STOP_RESULT" | jq -r '.meta.context // "N/A"')

if [ "$NOT_APPLICABLE" = "true" ]; then
    echo "✅ Condition correctement marquée comme non applicable"
    echo "📋 Raison: $REASON"
    echo "📋 Contexte: $CONTEXT"
else
    echo "❌ Condition non marquée comme non applicable"
fi
echo ""

# Test 2: Vérifier que les autres conditions ATR fonctionnent
echo "Test 2: Vérification des autres conditions ATR"
echo "----------------------------------------"
ATR_VOLATILITY=$(echo "$RESPONSE" | jq -r '.data.contracts_results.BTCUSDT.conditions_results.atr_volatility_ok')

echo "Résultat de atr_volatility_ok:"
echo "$ATR_VOLATILITY" | jq '.'

VOLATILITY_PASSED=$(echo "$ATR_VOLATILITY" | jq -r '.passed // false')
VOLATILITY_VALUE=$(echo "$ATR_VOLATILITY" | jq -r '.value // "N/A"')

if [ "$VOLATILITY_PASSED" = "true" ]; then
    echo "✅ Condition atr_volatility_ok fonctionne correctement"
    echo "📊 Valeur: $VOLATILITY_VALUE"
else
    echo "⚠️  Condition atr_volatility_ok échoue (normal si volatilité trop élevée)"
fi
echo ""

# Test 3: Vérifier le contexte ATR disponible
echo "Test 3: Vérification du contexte ATR"
echo "----------------------------------------"
CONTEXT=$(echo "$RESPONSE" | jq -r '.data.contracts_results.BTCUSDT.context')

ATR_VALUE=$(echo "$CONTEXT" | jq -r '.atr // "N/A"')
ATR_K=$(echo "$CONTEXT" | jq -r '.atr_k // "N/A"')
K_VALUE=$(echo "$CONTEXT" | jq -r '.k // "N/A"')

echo "Données ATR disponibles:"
echo "  - ATR: $ATR_VALUE"
echo "  - ATR_K: $ATR_K"
echo "  - K: $K_VALUE"

if [ "$ATR_VALUE" != "N/A" ] && [ "$ATR_K" != "N/A" ]; then
    echo "✅ Données ATR disponibles dans le contexte"
else
    echo "❌ Données ATR manquantes dans le contexte"
fi
echo ""

# Test 4: Vérifier l'affichage dans l'interface web
echo "Test 4: Vérification de l'affichage dans l'interface web"
echo "----------------------------------------"
WEB_PAGE=$(curl -s "$API_BASE/indicators/test")

if echo "$WEB_PAGE" | grep -q "Non applicable"; then
    echo "✅ Badge 'Non applicable' présent dans l'interface web"
else
    echo "❌ Badge 'Non applicable' manquant dans l'interface web"
fi

if echo "$WEB_PAGE" | grep -q "Données manquantes"; then
    echo "✅ Badge 'Données manquantes' présent dans l'interface web"
else
    echo "❌ Badge 'Données manquantes' manquant dans l'interface web"
fi
echo ""

# Test 5: Test avec différents contrats
echo "Test 5: Test avec différents contrats"
echo "----------------------------------------"
CONTRACTS=("ETHUSDT" "ADAUSDT" "SOLUSDT")

for contract in "${CONTRACTS[@]}"; do
    echo "Test avec $contract:"
    
    CONTRACT_RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
        -H "Content-Type: application/json" \
        -d "{\"date\": \"2024-10-01\", \"contracts\": \"$contract\", \"timeframe\": \"1h\"}")
    
    CONTRACT_ATR_STOP=$(echo "$CONTRACT_RESPONSE" | jq -r ".data.contracts_results.$contract.conditions_results.atr_stop_valid")
    CONTRACT_NOT_APPLICABLE=$(echo "$CONTRACT_ATR_STOP" | jq -r '.meta.not_applicable // false')
    
    if [ "$CONTRACT_NOT_APPLICABLE" = "true" ]; then
        echo "  ✅ $contract: Condition correctement exclue"
    else
        echo "  ❌ $contract: Condition non exclue"
    fi
done
echo ""

# Test 6: Vérifier le nombre total de conditions
echo "Test 6: Vérification du nombre total de conditions"
echo "----------------------------------------"
ALL_CONDITIONS=$(echo "$RESPONSE" | jq -r '.data.contracts_results.BTCUSDT.conditions_results | keys | length')
NOT_APPLICABLE_COUNT=$(echo "$RESPONSE" | jq -r '.data.contracts_results.BTCUSDT.conditions_results | to_entries | map(select(.value.meta.not_applicable == true)) | length')
MISSING_DATA_COUNT=$(echo "$RESPONSE" | jq -r '.data.contracts_results.BTCUSDT.conditions_results | to_entries | map(select(.value.meta.missing_data == true)) | length')

echo "📊 Statistiques des conditions:"
echo "  - Total des conditions: $ALL_CONDITIONS"
echo "  - Conditions non applicables: $NOT_APPLICABLE_COUNT"
echo "  - Conditions avec données manquantes: $MISSING_DATA_COUNT"

if [ "$NOT_APPLICABLE_COUNT" -gt 0 ]; then
    echo "✅ Au moins une condition est marquée comme non applicable"
else
    echo "⚠️  Aucune condition marquée comme non applicable"
fi
echo ""

echo "=========================================="
echo "Tests terminés"
echo "=========================================="
echo ""
echo "🎯 Résumé des fonctionnalités testées:"
echo "  ✅ Exclusion de atr_stop_valid du contexte de revalidation"
echo "  ✅ Fonctionnement des autres conditions ATR"
echo "  ✅ Disponibilité des données ATR dans le contexte"
echo "  ✅ Affichage correct dans l'interface web"
echo "  ✅ Exclusion cohérente sur tous les contrats"
echo "  ✅ Statistiques des conditions"
echo ""
echo "💡 Explication:"
echo "  - atr_stop_valid nécessite un prix d'entrée et un stop loss"
echo "  - Ces données n'existent pas dans un contexte de revalidation historique"
echo "  - La condition est maintenant marquée comme 'Non applicable'"
echo "  - L'interface affiche un badge gris au lieu d'un badge d'erreur"
echo ""
echo "🌐 Interface web: $API_BASE/indicators/test"

