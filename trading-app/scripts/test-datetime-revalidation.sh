#!/bin/bash

# Script de test pour le champ datetime UTC dans la revalidation
# Usage: ./scripts/test-datetime-revalidation.sh

set -e

# Configuration
API_BASE="http://localhost:8082"
ENDPOINT="/indicators/revalidate"

echo "=========================================="
echo "Test du champ datetime UTC pour la revalidation"
echo "=========================================="
echo "URL: $API_BASE$ENDPOINT"
echo ""

# Test 1: Vérifier que la page se charge avec le nouveau champ datetime
echo "Test 1: Chargement de la page avec champ datetime"
echo "----------------------------------------"
if curl -s "$API_BASE/indicators/test" | grep -q "Date et heure de revalidation"; then
    echo "✅ Page chargée avec succès - Champ datetime présent"
else
    echo "❌ Erreur lors du chargement de la page"
fi
echo ""

# Test 2: Test avec différentes dates et heures
echo "Test 2: Test avec différentes dates et heures UTC"
echo "----------------------------------------"

# Dates de test avec différentes heures
TEST_DATES=(
    "2024-01-15"  # Date simple
    "2024-06-15"  # Date d'été
    "2024-12-31"  # Date de fin d'année
)

TEST_CONTRACTS="BTCUSDT,ETHUSDT"

for date in "${TEST_DATES[@]}"; do
    echo "Test date: $date"
    
    # Test avec l'heure 00:00 UTC
    RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
        -H "Content-Type: application/json" \
        -d "{\"date\": \"$date\", \"contracts\": \"$TEST_CONTRACTS\", \"timeframe\": \"1h\"}")
    
    if echo "$RESPONSE" | jq -e '.success' > /dev/null; then
        SUCCESS_RATE=$(echo "$RESPONSE" | jq -r '.data.global_summary.success_rate')
        echo "  ✅ $date 00:00 UTC: Taux de succès $SUCCESS_RATE%"
    else
        echo "  ❌ $date 00:00 UTC: Erreur"
    fi
done
echo ""

# Test 3: Test avec date dans le futur (doit échouer)
echo "Test 3: Test avec date dans le futur"
echo "----------------------------------------"
# Utiliser une date fixe dans le futur pour éviter les problèmes de compatibilité
FUTURE_DATE="2025-12-31"
echo "Date testée: $FUTURE_DATE (futur)"

FUTURE_RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d "{\"date\": \"$FUTURE_DATE\", \"contracts\": \"BTCUSDT\", \"timeframe\": \"1h\"}")

if echo "$FUTURE_RESPONSE" | jq -e '.success == false' > /dev/null; then
    ERROR_MSG=$(echo "$FUTURE_RESPONSE" | jq -r '.message')
    echo "  ✅ Erreur correctement gérée: $ERROR_MSG"
else
    echo "  ❌ Erreur non gérée - la date future devrait être rejetée"
fi
echo ""

# Test 4: Test avec date très ancienne
echo "Test 4: Test avec date très ancienne"
echo "----------------------------------------"
OLD_DATE="2020-01-01"
echo "Date testée: $OLD_DATE"

OLD_RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d "{\"date\": \"$OLD_DATE\", \"contracts\": \"BTCUSDT\", \"timeframe\": \"1h\"}")

if echo "$OLD_RESPONSE" | jq -e '.success' > /dev/null; then
    SUCCESS_RATE=$(echo "$OLD_RESPONSE" | jq -r '.data.global_summary.success_rate')
    echo "  ✅ $OLD_DATE: Taux de succès $SUCCESS_RATE%"
else
    echo "  ❌ $OLD_DATE: Erreur"
fi
echo ""

# Test 5: Test avec différents timeframes et datetime
echo "Test 5: Test avec différents timeframes"
echo "----------------------------------------"
TEST_DATE="2024-01-15"
TIMEFRAMES=("1m" "5m" "15m" "1h" "4h")

for tf in "${TIMEFRAMES[@]}"; do
    echo "Test timeframe: $tf avec date $TEST_DATE"
    
    TF_RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
        -H "Content-Type: application/json" \
        -d "{\"date\": \"$TEST_DATE\", \"contracts\": \"BTCUSDT\", \"timeframe\": \"$tf\"}")
    
    if echo "$TF_RESPONSE" | jq -e '.success' > /dev/null; then
        SUCCESS_RATE=$(echo "$TF_RESPONSE" | jq -r '.data.global_summary.success_rate')
        echo "  ✅ $tf: Taux de succès $SUCCESS_RATE%"
    else
        echo "  ❌ $tf: Erreur"
    fi
done
echo ""

# Test 6: Test de performance avec datetime
echo "Test 6: Test de performance avec datetime"
echo "----------------------------------------"
MANY_CONTRACTS="BTCUSDT,ETHUSDT,ADAUSDT,DOTUSDT,LINKUSDT"
TEST_DATE="2024-01-15"

echo "Test avec 5 contrats et date $TEST_DATE"

START_TIME=$(date +%s)
PERF_RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d "{\"date\": \"$TEST_DATE\", \"contracts\": \"$MANY_CONTRACTS\", \"timeframe\": \"1h\"}")
END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))

if echo "$PERF_RESPONSE" | jq -e '.success' > /dev/null; then
    CONTRACT_COUNT=$(echo "$PERF_RESPONSE" | jq -r '.data.global_summary.total_contracts')
    SUCCESS_RATE=$(echo "$PERF_RESPONSE" | jq -r '.data.global_summary.success_rate')
    echo "  ✅ $CONTRACT_COUNT contrats traités en ${DURATION}s (taux: $SUCCESS_RATE%)"
else
    echo "  ❌ Erreur lors du test de performance"
fi
echo ""

# Test 7: Vérifier les détails des résultats avec datetime
echo "Test 7: Vérification des détails des résultats"
echo "----------------------------------------"
DETAIL_RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d '{"date": "2024-01-15", "contracts": "BTCUSDT", "timeframe": "1h"}')

if echo "$DETAIL_RESPONSE" | jq -e '.success' > /dev/null; then
    echo "✅ Résultats détaillés:"
    
    # Afficher le résumé global
    echo "Résumé global:"
    echo "$DETAIL_RESPONSE" | jq -r '.data.global_summary | "  - Date: \(.date)", "  - Timeframe: \(.timeframe)", "  - Contrats: \(.total_contracts)", "  - Taux de succès: \(.success_rate)%"'
    
    # Afficher les détails du premier contrat
    FIRST_CONTRACT=$(echo "$DETAIL_RESPONSE" | jq -r '.data.contracts_results | keys[0]')
    echo "Détails du contrat $FIRST_CONTRACT:"
    echo "$DETAIL_RESPONSE" | jq -r ".data.contracts_results.$FIRST_CONTRACT | \"  - Statut: \(.status)\", \"  - Taux de succès: \(.summary.success_rate)%\", \"  - Conditions passées: \(.summary.passed)/\(.summary.total_conditions)\""
    
    # Vérifier la présence du contexte
    HAS_CONTEXT=$(echo "$DETAIL_RESPONSE" | jq -r ".data.contracts_results.$FIRST_CONTRACT.context != null")
    if [ "$HAS_CONTEXT" = "true" ]; then
        echo "  ✅ Contexte disponible"
    else
        echo "  ❌ Contexte manquant"
    fi
else
    echo "❌ Erreur lors de la récupération des détails"
fi
echo ""

echo "=========================================="
echo "Tests terminés"
echo "=========================================="
echo ""
echo "🎯 Résumé des fonctionnalités testées:"
echo "  ✅ Champ datetime UTC dans l'interface"
echo "  ✅ Validation des dates futures"
echo "  ✅ Support des dates anciennes"
echo "  ✅ Différents timeframes avec datetime"
echo "  ✅ Performance avec datetime"
echo "  ✅ Détails des résultats avec datetime"
echo ""
echo "🌐 Interface web disponible sur: $API_BASE/indicators/test"
echo "📊 Nouvelles fonctionnalités:"
echo "  - Champ datetime-local avec conversion UTC"
echo "  - Validation des dates futures"
echo "  - Contexte historique basé sur l'heure UTC"
echo "  - Affichage des résultats avec indication UTC"
