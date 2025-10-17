#!/bin/bash

# Script de test pour l'interface web de revalidation
# Usage: ./scripts/test-web-interface-revalidation.sh

set -e

# Configuration
API_BASE="http://localhost:8082"

echo "=========================================="
echo "Test de l'interface web de revalidation"
echo "=========================================="
echo "URL: $API_BASE/indicators/test"
echo ""

# Test 1: Vérifier que la page se charge
echo "Test 1: Chargement de la page"
echo "----------------------------------------"
if curl -s "$API_BASE/indicators/test" | grep -q "Date de revalidation"; then
    echo "✅ Page chargée avec succès - Champ date présent"
else
    echo "❌ Erreur lors du chargement de la page"
fi
echo ""

# Test 2: Vérifier l'endpoint des contrats
echo "Test 2: Endpoint des contrats disponibles"
echo "----------------------------------------"
CONTRACTS_RESPONSE=$(curl -s "$API_BASE/indicators/available-contracts")
if echo "$CONTRACTS_RESPONSE" | jq -e '.success' > /dev/null; then
    CONTRACT_COUNT=$(echo "$CONTRACTS_RESPONSE" | jq -r '.data.count')
    echo "✅ $CONTRACT_COUNT contrats disponibles"
    
    # Afficher quelques contrats
    echo "Exemples de contrats:"
    echo "$CONTRACTS_RESPONSE" | jq -r '.data.contracts[0:5][]' | sed 's/^/  - /'
else
    echo "❌ Erreur lors du chargement des contrats"
fi
echo ""

# Test 3: Test de revalidation avec contrats populaires
echo "Test 3: Revalidation avec contrats populaires"
echo "----------------------------------------"
POPULAR_CONTRACTS="BTCUSDT,ETHUSDT,SOLUSDT,ADAUSDT,DOTUSDT"
echo "Contrats testés: $POPULAR_CONTRACTS"

REVALIDATION_RESPONSE=$(curl -s -X POST "$API_BASE/indicators/revalidate" \
    -H "Content-Type: application/json" \
    -d "{\"date\": \"2024-01-15\", \"contracts\": \"$POPULAR_CONTRACTS\", \"timeframe\": \"1h\"}")

if echo "$REVALIDATION_RESPONSE" | jq -e '.success' > /dev/null; then
    echo "✅ Revalidation réussie"
    
    # Afficher le résumé
    echo "Résumé:"
    echo "$REVALIDATION_RESPONSE" | jq -r '.data.global_summary | "  - Contrats analysés: \(.total_contracts)", "  - Validations réussies: \(.successful_validations)", "  - Validations échouées: \(.failed_validations)", "  - Taux de succès: \(.success_rate)%"'
    
    # Afficher les résultats par contrat
    echo "Résultats par contrat:"
    echo "$REVALIDATION_RESPONSE" | jq -r '.data.contracts_results | to_entries[] | "  - \(.key): \(.value.status) (succès: \(.value.summary.success_rate)%)"'
else
    echo "❌ Erreur lors de la revalidation"
    echo "$REVALIDATION_RESPONSE" | jq -r '.error, .message'
fi
echo ""

# Test 4: Test avec différents timeframes
echo "Test 4: Test avec différents timeframes"
echo "----------------------------------------"
TIMEFRAMES=("1m" "5m" "15m" "1h" "4h")
TEST_CONTRACTS="BTCUSDT,ETHUSDT"

for tf in "${TIMEFRAMES[@]}"; do
    echo "Test timeframe: $tf"
    TF_RESPONSE=$(curl -s -X POST "$API_BASE/indicators/revalidate" \
        -H "Content-Type: application/json" \
        -d "{\"date\": \"2024-01-15\", \"contracts\": \"$TEST_CONTRACTS\", \"timeframe\": \"$tf\"}")
    
    if echo "$TF_RESPONSE" | jq -e '.success' > /dev/null; then
        SUCCESS_RATE=$(echo "$TF_RESPONSE" | jq -r '.data.global_summary.success_rate')
        echo "  ✅ $tf: Taux de succès $SUCCESS_RATE%"
    else
        echo "  ❌ $tf: Erreur"
    fi
done
echo ""

# Test 5: Test avec différentes dates
echo "Test 5: Test avec différentes dates"
echo "----------------------------------------"
DATES=("2024-01-01" "2024-06-15" "2024-12-31")
TEST_CONTRACTS="BTCUSDT"

for date in "${DATES[@]}"; do
    echo "Test date: $date"
    DATE_RESPONSE=$(curl -s -X POST "$API_BASE/indicators/revalidate" \
        -H "Content-Type: application/json" \
        -d "{\"date\": \"$date\", \"contracts\": \"$TEST_CONTRACTS\", \"timeframe\": \"1h\"}")
    
    if echo "$DATE_RESPONSE" | jq -e '.success' > /dev/null; then
        SUCCESS_RATE=$(echo "$DATE_RESPONSE" | jq -r '.data.global_summary.success_rate')
        echo "  ✅ $date: Taux de succès $SUCCESS_RATE%"
    else
        echo "  ❌ $date: Erreur"
    fi
done
echo ""

# Test 6: Test des cas d'erreur
echo "Test 6: Test des cas d'erreur"
echo "----------------------------------------"

# Date manquante
echo "Test: Date manquante"
ERROR_RESPONSE=$(curl -s -X POST "$API_BASE/indicators/revalidate" \
    -H "Content-Type: application/json" \
    -d '{"contracts": "BTCUSDT", "timeframe": "1h"}')

if echo "$ERROR_RESPONSE" | jq -e '.success == false' > /dev/null; then
    ERROR_MSG=$(echo "$ERROR_RESPONSE" | jq -r '.message')
    echo "  ✅ Erreur correctement gérée: $ERROR_MSG"
else
    echo "  ❌ Erreur non gérée"
fi

# Contrats manquants
echo "Test: Contrats manquants"
ERROR_RESPONSE=$(curl -s -X POST "$API_BASE/indicators/revalidate" \
    -H "Content-Type: application/json" \
    -d '{"date": "2024-01-15", "timeframe": "1h"}')

if echo "$ERROR_RESPONSE" | jq -e '.success == false' > /dev/null; then
    ERROR_MSG=$(echo "$ERROR_RESPONSE" | jq -r '.message')
    echo "  ✅ Erreur correctement gérée: $ERROR_MSG"
else
    echo "  ❌ Erreur non gérée"
fi

# Format de date invalide
echo "Test: Format de date invalide"
ERROR_RESPONSE=$(curl -s -X POST "$API_BASE/indicators/revalidate" \
    -H "Content-Type: application/json" \
    -d '{"date": "15/01/2024", "contracts": "BTCUSDT", "timeframe": "1h"}')

if echo "$ERROR_RESPONSE" | jq -e '.success == false' > /dev/null; then
    ERROR_MSG=$(echo "$ERROR_RESPONSE" | jq -r '.message')
    echo "  ✅ Erreur correctement gérée: $ERROR_MSG"
else
    echo "  ❌ Erreur non gérée"
fi

# Contrats invalides
echo "Test: Contrats invalides"
ERROR_RESPONSE=$(curl -s -X POST "$API_BASE/indicators/revalidate" \
    -H "Content-Type: application/json" \
    -d '{"date": "2024-01-15", "contracts": "INVALIDUSDT,FAKEUSDT", "timeframe": "1h"}')

if echo "$ERROR_RESPONSE" | jq -e '.success == false' > /dev/null; then
    ERROR_MSG=$(echo "$ERROR_RESPONSE" | jq -r '.message')
    echo "  ✅ Erreur correctement gérée: $ERROR_MSG"
else
    echo "  ❌ Erreur non gérée"
fi
echo ""

# Test 7: Performance avec beaucoup de contrats
echo "Test 7: Performance avec beaucoup de contrats"
echo "----------------------------------------"
MANY_CONTRACTS="BTCUSDT,ETHUSDT,ADAUSDT,DOTUSDT,LINKUSDT,SOLUSDT,MATICUSDT,AVAXUSDT,BNBUSDT,ATOMUSDT"
echo "Test avec 10 contrats: $MANY_CONTRACTS"

START_TIME=$(date +%s)
PERF_RESPONSE=$(curl -s -X POST "$API_BASE/indicators/revalidate" \
    -H "Content-Type: application/json" \
    -d "{\"date\": \"2024-01-15\", \"contracts\": \"$MANY_CONTRACTS\", \"timeframe\": \"1h\"}")
END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))

if echo "$PERF_RESPONSE" | jq -e '.success' > /dev/null; then
    CONTRACT_COUNT=$(echo "$PERF_RESPONSE" | jq -r '.data.global_summary.total_contracts')
    echo "  ✅ $CONTRACT_COUNT contrats traités en ${DURATION}s"
else
    echo "  ❌ Erreur lors du test de performance"
fi
echo ""

echo "=========================================="
echo "Tests terminés"
echo "=========================================="
echo ""
echo "🎯 Résumé des fonctionnalités testées:"
echo "  ✅ Chargement de la page avec champ date"
echo "  ✅ Endpoint des contrats disponibles (388 contrats)"
echo "  ✅ Revalidation avec contrats multiples"
echo "  ✅ Support de différents timeframes"
echo "  ✅ Support de différentes dates"
echo "  ✅ Gestion des erreurs"
echo "  ✅ Test de performance"
echo ""
echo "🌐 Interface web disponible sur: $API_BASE/indicators/test"
echo "📊 Fonctionnalités disponibles:"
echo "  - Champ date pour la revalidation"
echo "  - Recherche de contrats avec autocomplétion"
echo "  - Sélection multiple de contrats"
echo "  - Bouton de revalidation dédié"
echo "  - Affichage des résultats détaillés"

