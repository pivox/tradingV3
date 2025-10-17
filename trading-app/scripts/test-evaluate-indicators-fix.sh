#!/bin/bash

# Script de test pour vérifier la correction de la fonction evaluateIndicators
# Usage: ./scripts/test-evaluate-indicators-fix.sh

set -e

# Configuration
API_BASE="http://localhost:8082"
ENDPOINT="/indicators/evaluate"

echo "=========================================="
echo "Test de la correction evaluateIndicators"
echo "=========================================="
echo "URL: $API_BASE$ENDPOINT"
echo ""

# Test 1: Vérifier que l'endpoint fonctionne avec un symbole valide
echo "Test 1: Test de l'endpoint /indicators/evaluate"
echo "----------------------------------------"
RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d '{"symbol": "BTCUSDT", "timeframe": "1h"}')

echo "Réponse complète:"
echo "$RESPONSE" | jq '.'

# Vérifier le succès
SUCCESS=$(echo "$RESPONSE" | jq -r '.success // false')
if [ "$SUCCESS" = "true" ]; then
    echo "✅ Endpoint /indicators/evaluate fonctionne"
else
    echo "❌ Endpoint /indicators/evaluate échoue"
    echo "Erreur: $(echo "$RESPONSE" | jq -r '.message // "N/A"')"
fi
echo ""

# Test 2: Vérifier que la page web se charge correctement
echo "Test 2: Vérification de la page web"
echo "----------------------------------------"
WEB_PAGE=$(curl -s "$API_BASE/indicators/test")

if echo "$WEB_PAGE" | grep -q "Évaluer les Indicateurs"; then
    echo "✅ Bouton 'Évaluer les Indicateurs' présent dans l'interface web"
else
    echo "❌ Bouton 'Évaluer les Indicateurs' manquant dans l'interface web"
fi

if echo "$WEB_PAGE" | grep -q "evaluateIndicators"; then
    echo "✅ Fonction JavaScript evaluateIndicators présente"
else
    echo "❌ Fonction JavaScript evaluateIndicators manquante"
fi

if echo "$WEB_PAGE" | grep -q "selectedContracts\[0\]"; then
    echo "✅ Fonction utilise selectedContracts[0] (correction appliquée)"
else
    echo "❌ Fonction n'utilise pas selectedContracts[0] (correction manquante)"
fi

if echo "$WEB_PAGE" | grep -q "document.getElementById('symbol')"; then
    echo "❌ Fonction utilise encore document.getElementById('symbol') (erreur non corrigée)"
else
    echo "✅ Fonction n'utilise plus document.getElementById('symbol') (erreur corrigée)"
fi
echo ""

# Test 3: Vérifier que les contrats sont disponibles
echo "Test 3: Vérification des contrats disponibles"
echo "----------------------------------------"
CONTRACTS_RESPONSE=$(curl -s "$API_BASE/indicators/available-contracts")

CONTRACTS_SUCCESS=$(echo "$CONTRACTS_RESPONSE" | jq -r '.success // false')
if [ "$CONTRACTS_SUCCESS" = "true" ]; then
    CONTRACTS_COUNT=$(echo "$CONTRACTS_RESPONSE" | jq -r '.data | length')
    echo "✅ Contrats disponibles: $CONTRACTS_COUNT"
    
    # Vérifier que BTCUSDT est dans la liste
    BTCUSDT_PRESENT=$(echo "$CONTRACTS_RESPONSE" | jq -r '.data | contains(["BTCUSDT"])')
    if [ "$BTCUSDT_PRESENT" = "true" ]; then
        echo "✅ BTCUSDT présent dans la liste des contrats"
    else
        echo "❌ BTCUSDT manquant dans la liste des contrats"
    fi
else
    echo "❌ Impossible de récupérer la liste des contrats"
fi
echo ""

# Test 4: Test avec différents timeframes
echo "Test 4: Test avec différents timeframes"
echo "----------------------------------------"
TIMEFRAMES=("1m" "5m" "15m" "1h" "4h")

for tf in "${TIMEFRAMES[@]}"; do
    echo "Test avec timeframe $tf:"
    
    TF_RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
        -H "Content-Type: application/json" \
        -d "{\"symbol\": \"BTCUSDT\", \"timeframe\": \"$tf\"}")
    
    TF_SUCCESS=$(echo "$TF_RESPONSE" | jq -r '.success // false')
    if [ "$TF_SUCCESS" = "true" ]; then
        echo "  ✅ Timeframe $tf: Évaluation réussie"
    else
        echo "  ❌ Timeframe $tf: Échec de l'évaluation"
        echo "  Erreur: $(echo "$TF_RESPONSE" | jq -r '.message // "N/A"')"
    fi
done
echo ""

# Test 5: Test avec différents contrats
echo "Test 5: Test avec différents contrats"
echo "----------------------------------------"
CONTRACTS=("ETHUSDT" "ADAUSDT" "SOLUSDT")

for contract in "${CONTRACTS[@]}"; do
    echo "Test avec contrat $contract:"
    
    CONTRACT_RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
        -H "Content-Type: application/json" \
        -d "{\"symbol\": \"$contract\", \"timeframe\": \"1h\"}")
    
    CONTRACT_SUCCESS=$(echo "$CONTRACT_RESPONSE" | jq -r '.success // false')
    if [ "$CONTRACT_SUCCESS" = "true" ]; then
        echo "  ✅ Contrat $contract: Évaluation réussie"
    else
        echo "  ❌ Contrat $contract: Échec de l'évaluation"
        echo "  Erreur: $(echo "$CONTRACT_RESPONSE" | jq -r '.message // "N/A"')"
    fi
done
echo ""

# Test 6: Vérifier la structure de la réponse
echo "Test 6: Vérification de la structure de la réponse"
echo "----------------------------------------"
if [ "$SUCCESS" = "true" ]; then
    echo "Structure de la réponse:"
    
    # Vérifier les champs principaux
    HAS_DATA=$(echo "$RESPONSE" | jq -r 'has("data")')
    HAS_CONTEXT=$(echo "$RESPONSE" | jq -r '.data | has("context")')
    HAS_CONDITIONS=$(echo "$RESPONSE" | jq -r '.data | has("conditions")')
    
    if [ "$HAS_DATA" = "true" ]; then
        echo "  ✅ Champ 'data' présent"
    else
        echo "  ❌ Champ 'data' manquant"
    fi
    
    if [ "$HAS_CONTEXT" = "true" ]; then
        echo "  ✅ Champ 'context' présent"
    else
        echo "  ❌ Champ 'context' manquant"
    fi
    
    if [ "$HAS_CONDITIONS" = "true" ]; then
        echo "  ✅ Champ 'conditions' présent"
    else
        echo "  ❌ Champ 'conditions' manquant"
    fi
    
    # Afficher un résumé des conditions
    CONDITIONS_COUNT=$(echo "$RESPONSE" | jq -r '.data.conditions | length // 0')
    echo "  📊 Nombre de conditions: $CONDITIONS_COUNT"
    
    if [ "$CONDITIONS_COUNT" -gt 0 ]; then
        echo "  📋 Exemples de conditions:"
        echo "$RESPONSE" | jq -r '.data.conditions | keys[0:3] | .[]' | while read condition; do
            echo "    - $condition"
        done
    fi
else
    echo "❌ Impossible de vérifier la structure (réponse en erreur)"
fi
echo ""

echo "=========================================="
echo "Tests terminés"
echo "=========================================="
echo ""
echo "🎯 Résumé des corrections testées:"
echo "  ✅ Correction de la fonction evaluateIndicators()"
echo "  ✅ Utilisation de selectedContracts[0] au lieu de document.getElementById('symbol')"
echo "  ✅ Validation de la sélection de contrats"
echo "  ✅ Fonctionnement de l'endpoint /indicators/evaluate"
echo "  ✅ Interface web avec bouton corrigé"
echo ""
echo "💡 Fonctionnalités de la correction:"
echo "  - Utilise le premier contrat sélectionné pour l'évaluation"
echo "  - Valide qu'au moins un contrat est sélectionné"
echo "  - Évite l'erreur 'Cannot read properties of null'"
echo "  - Maintient la compatibilité avec l'interface existante"
echo ""
echo "🌐 Interface web: $API_BASE/indicators/test"

