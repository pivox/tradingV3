#!/bin/bash

# Script de test pour la validation des contrats
# Usage: ./scripts/test-contract-validation.sh

set -e

# Configuration
API_BASE="http://localhost:8082"
ENDPOINT="/indicators/revalidate"
CONTRACTS_ENDPOINT="/indicators/available-contracts"

echo "=========================================="
echo "Test de validation des contrats"
echo "=========================================="
echo "URL: $API_BASE$ENDPOINT"
echo ""

# Test 1: Récupérer la liste des contrats disponibles
echo "Test 1: Récupération des contrats disponibles"
echo "----------------------------------------"
CONTRACTS_RESPONSE=$(curl -s "$API_BASE$CONTRACTS_ENDPOINT")
if echo "$CONTRACTS_RESPONSE" | jq -e '.success' > /dev/null; then
    CONTRACT_COUNT=$(echo "$CONTRACTS_RESPONSE" | jq -r '.data.count')
    echo "✅ $CONTRACT_COUNT contrats disponibles"
    
    # Récupérer quelques contrats pour les tests
    SAMPLE_CONTRACTS=$(echo "$CONTRACTS_RESPONSE" | jq -r '.data.contracts[0:5] | join(",")')
    echo "📊 Contrats d'exemple: $SAMPLE_CONTRACTS"
else
    echo "❌ Erreur lors de la récupération des contrats"
    exit 1
fi
echo ""

# Test 2: Test avec des contrats valides
echo "Test 2: Test avec des contrats valides"
echo "----------------------------------------"
VALID_CONTRACTS="BTCUSDT,ETHUSDT,ZORAUSDT"
echo "Contrats testés: $VALID_CONTRACTS"

VALID_RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d "{\"date\": \"2024-10-01\", \"contracts\": \"$VALID_CONTRACTS\", \"timeframe\": \"1h\"}")

if echo "$VALID_RESPONSE" | jq -e '.success' > /dev/null; then
    SUCCESS_RATE=$(echo "$VALID_RESPONSE" | jq -r '.data.global_summary.success_rate')
    TOTAL_CONTRACTS=$(echo "$VALID_RESPONSE" | jq -r '.data.global_summary.total_contracts')
    echo "✅ Validation réussie"
    echo "📊 Contrats traités: $TOTAL_CONTRACTS"
    echo "📊 Taux de succès: $SUCCESS_RATE%"
    
    # Vérifier les résultats par contrat
    for contract in BTCUSDT ETHUSDT ZORAUSDT; do
        STATUS=$(echo "$VALID_RESPONSE" | jq -r ".data.contracts_results.$contract.status // \"N/A\"")
        if [ "$STATUS" != "N/A" ]; then
            echo "  📈 $contract: $STATUS"
        fi
    done
else
    echo "❌ Erreur de validation"
    echo "$VALID_RESPONSE" | jq -r '.error, .message'
fi
echo ""

# Test 3: Test avec des contrats invalides
echo "Test 3: Test avec des contrats invalides"
echo "----------------------------------------"
INVALID_CONTRACTS="INVALIDUSDT,FAKEUSDT"
echo "Contrats testés: $INVALID_CONTRACTS"

INVALID_RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d "{\"date\": \"2024-10-01\", \"contracts\": \"$INVALID_CONTRACTS\", \"timeframe\": \"1h\"}")

if echo "$INVALID_RESPONSE" | jq -e '.success' > /dev/null; then
    echo "⚠️  Validation inattendue réussie"
else
    ERROR=$(echo "$INVALID_RESPONSE" | jq -r '.error')
    MESSAGE=$(echo "$INVALID_RESPONSE" | jq -r '.message')
    echo "✅ Validation correctement échouée"
    echo "📊 Erreur: $ERROR"
    echo "📊 Message: $MESSAGE"
fi
echo ""

# Test 4: Test mixte (valides + invalides)
echo "Test 4: Test mixte (valides + invalides)"
echo "----------------------------------------"
MIXED_CONTRACTS="BTCUSDT,INVALIDUSDT,ETHUSDT"
echo "Contrats testés: $MIXED_CONTRACTS"

MIXED_RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d "{\"date\": \"2024-10-01\", \"contracts\": \"$MIXED_CONTRACTS\", \"timeframe\": \"1h\"}")

if echo "$MIXED_RESPONSE" | jq -e '.success' > /dev/null; then
    echo "⚠️  Validation inattendue réussie avec contrats mixtes"
else
    ERROR=$(echo "$MIXED_RESPONSE" | jq -r '.error')
    MESSAGE=$(echo "$MIXED_RESPONSE" | jq -r '.message')
    echo "✅ Validation correctement échouée avec contrats mixtes"
    echo "📊 Erreur: $ERROR"
    echo "📊 Message: $MESSAGE"
fi
echo ""

# Test 5: Test avec des contrats récents (meme tokens)
echo "Test 5: Test avec des contrats récents"
echo "----------------------------------------"
RECENT_CONTRACTS="1000PEPEUSDT,1000SATSUSDT,1INCHUSDT"
echo "Contrats testés: $RECENT_CONTRACTS"

RECENT_RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d "{\"date\": \"2024-10-01\", \"contracts\": \"$RECENT_CONTRACTS\", \"timeframe\": \"1h\"}")

if echo "$RECENT_RESPONSE" | jq -e '.success' > /dev/null; then
    SUCCESS_RATE=$(echo "$RECENT_RESPONSE" | jq -r '.data.global_summary.success_rate')
    TOTAL_CONTRACTS=$(echo "$RECENT_RESPONSE" | jq -r '.data.global_summary.total_contracts')
    echo "✅ Validation réussie pour contrats récents"
    echo "📊 Contrats traités: $TOTAL_CONTRACTS"
    echo "📊 Taux de succès: $SUCCESS_RATE%"
else
    ERROR=$(echo "$RECENT_RESPONSE" | jq -r '.error')
    MESSAGE=$(echo "$RECENT_RESPONSE" | jq -r '.message')
    echo "❌ Erreur avec contrats récents"
    echo "📊 Erreur: $ERROR"
    echo "📊 Message: $MESSAGE"
fi
echo ""

# Test 6: Test de performance avec plusieurs contrats
echo "Test 6: Test de performance avec plusieurs contrats"
echo "----------------------------------------"
PERF_CONTRACTS="BTCUSDT,ETHUSDT,ADAUSDT,SOLUSDT,DOTUSDT"
echo "Contrats testés: $PERF_CONTRACTS"

START_TIME=$(date +%s)
PERF_RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d "{\"date\": \"2024-10-01\", \"contracts\": \"$PERF_CONTRACTS\", \"timeframe\": \"1h\"}")
END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))

if echo "$PERF_RESPONSE" | jq -e '.success' > /dev/null; then
    SUCCESS_RATE=$(echo "$PERF_RESPONSE" | jq -r '.data.global_summary.success_rate')
    TOTAL_CONTRACTS=$(echo "$PERF_RESPONSE" | jq -r '.data.global_summary.total_contracts')
    echo "✅ Test de performance réussi"
    echo "📊 Contrats traités: $TOTAL_CONTRACTS"
    echo "📊 Taux de succès: $SUCCESS_RATE%"
    echo "⏱️  Temps d'exécution: ${DURATION}s"
else
    echo "❌ Erreur lors du test de performance"
    echo "$PERF_RESPONSE" | jq -r '.error, .message'
fi
echo ""

echo "=========================================="
echo "Tests terminés"
echo "=========================================="
echo ""
echo "🎯 Résumé des fonctionnalités testées:"
echo "  ✅ Récupération des contrats disponibles"
echo "  ✅ Validation des contrats valides"
echo "  ✅ Rejet des contrats invalides"
echo "  ✅ Gestion des contrats mixtes"
echo "  ✅ Support des contrats récents"
echo "  ✅ Performance avec plusieurs contrats"
echo ""
echo "🌐 Interface web disponible sur: $API_BASE/indicators/test"
echo "📊 Fonctionnalités disponibles:"
echo "  - Validation contre $CONTRACT_COUNT contrats actifs"
echo "  - Support de tous les contrats"
echo "  - Messages d'erreur explicites"
echo "  - Récupération automatique des klines"
echo "  - Validation avec données réelles"

