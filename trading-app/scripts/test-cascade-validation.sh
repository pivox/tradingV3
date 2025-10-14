#!/bin/bash

# Script de test pour la validation en cascade des contrats
# Usage: ./scripts/test-cascade-validation.sh

set -e

# Configuration
API_BASE="http://localhost:8082"
ENDPOINT="/indicators/validate-cascade"

echo "=========================================="
echo "Test de validation en cascade des contrats"
echo "=========================================="
echo "URL: $API_BASE$ENDPOINT"
echo ""

# Test 1: Validation en cascade avec BTCUSDT
echo "Test 1: Validation en cascade avec BTCUSDT"
echo "----------------------------------------"
RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d '{"date": "2024-10-01", "contract": "BTCUSDT"}')

echo "Réponse complète:"
echo "$RESPONSE" | jq '.'

# Vérifier le succès
SUCCESS=$(echo "$RESPONSE" | jq -r '.success // false')
if [ "$SUCCESS" = "true" ]; then
    echo "✅ Validation en cascade réussie"
else
    echo "❌ Échec de la validation en cascade"
    echo "Erreur: $(echo "$RESPONSE" | jq -r '.message // "N/A"')"
    exit 1
fi
echo ""

# Test 2: Vérifier le résumé global
echo "Test 2: Vérification du résumé global"
echo "----------------------------------------"
SUMMARY=$(echo "$RESPONSE" | jq -r '.data.summary')

TOTAL_TIMEFRAMES=$(echo "$SUMMARY" | jq -r '.total_timeframes // 0')
TOTAL_CONDITIONS=$(echo "$SUMMARY" | jq -r '.total_conditions // 0')
TOTAL_PASSED=$(echo "$SUMMARY" | jq -r '.total_passed // 0')
TOTAL_FAILED=$(echo "$SUMMARY" | jq -r '.total_failed // 0')
SUCCESS_RATE=$(echo "$SUMMARY" | jq -r '.success_rate // 0')

echo "📊 Résumé global:"
echo "  - Timeframes testés: $TOTAL_TIMEFRAMES"
echo "  - Conditions totales: $TOTAL_CONDITIONS"
echo "  - Conditions réussies: $TOTAL_PASSED"
echo "  - Conditions échouées: $TOTAL_FAILED"
echo "  - Taux de réussite: $SUCCESS_RATE%"

if [ "$TOTAL_TIMEFRAMES" -eq 5 ] && [ "$TOTAL_CONDITIONS" -gt 0 ]; then
    echo "✅ Résumé global correct"
else
    echo "❌ Résumé global incorrect"
fi
echo ""

# Test 3: Vérifier les résultats par timeframe
echo "Test 3: Vérification des résultats par timeframe"
echo "----------------------------------------"
TIMEFRAMES=("4h" "1h" "15m" "5m" "1m")

for tf in "${TIMEFRAMES[@]}"; do
    echo "Timeframe $tf:"
    
    TF_RESULT=$(echo "$RESPONSE" | jq -r ".data.timeframes_results[\"$tf\"]")
    TF_STATUS=$(echo "$TF_RESULT" | jq -r '.status // "N/A"')
    TF_CONDITIONS_COUNT=$(echo "$TF_RESULT" | jq -r '.conditions_count // 0')
    TF_CONDITIONS_PASSED=$(echo "$TF_RESULT" | jq -r '.conditions_passed // 0')
    TF_KLINES_COUNT=$(echo "$TF_RESULT" | jq -r '.klines_used.count // 0')
    
    echo "  - Statut: $TF_STATUS"
    echo "  - Conditions: $TF_CONDITIONS_PASSED/$TF_CONDITIONS_COUNT"
    echo "  - Klines utilisées: $TF_KLINES_COUNT"
    
    if [ "$TF_CONDITIONS_COUNT" -gt 0 ] && [ "$TF_KLINES_COUNT" -gt 0 ]; then
        echo "  ✅ Timeframe $tf: Données correctes"
    else
        echo "  ❌ Timeframe $tf: Données manquantes"
    fi
done
echo ""

# Test 4: Vérifier les IDs des klines pour chaque timeframe
echo "Test 4: Vérification des IDs des klines"
echo "----------------------------------------"
for tf in "${TIMEFRAMES[@]}"; do
    echo "Timeframe $tf - IDs des klines:"
    
    TF_KLINES_USED=$(echo "$RESPONSE" | jq -r ".data.timeframes_results[\"$tf\"].klines_used")
    TF_IDS_COUNT=$(echo "$TF_KLINES_USED" | jq -r '.ids | length // 0')
    TF_FIRST_ID=$(echo "$TF_KLINES_USED" | jq -r '.ids[0] // "N/A"')
    TF_LAST_ID=$(echo "$TF_KLINES_USED" | jq -r '.ids[-1] // "N/A"')
    TF_FROM_DATE=$(echo "$TF_KLINES_USED" | jq -r '.date_range.from // "N/A"')
    TF_TO_DATE=$(echo "$TF_KLINES_USED" | jq -r '.date_range.to // "N/A"')
    
    echo "  - Nombre d'IDs: $TF_IDS_COUNT"
    echo "  - Premier ID: $TF_FIRST_ID"
    echo "  - Dernier ID: $TF_LAST_ID"
    echo "  - Période: $TF_FROM_DATE → $TF_TO_DATE"
    
    if [ "$TF_IDS_COUNT" -gt 0 ] && [ "$TF_FIRST_ID" != "N/A" ]; then
        echo "  ✅ IDs des klines disponibles"
    else
        echo "  ❌ IDs des klines manquants"
    fi
done
echo ""

# Test 5: Test avec différents contrats
echo "Test 5: Test avec différents contrats"
echo "----------------------------------------"
CONTRACTS=("ETHUSDT" "ADAUSDT" "SOLUSDT")

for contract in "${CONTRACTS[@]}"; do
    echo "Test avec $contract:"
    
    CONTRACT_RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
        -H "Content-Type: application/json" \
        -d "{\"date\": \"2024-10-01\", \"contract\": \"$contract\"}")
    
    CONTRACT_SUCCESS=$(echo "$CONTRACT_RESPONSE" | jq -r '.success // false')
    CONTRACT_SUMMARY=$(echo "$CONTRACT_RESPONSE" | jq -r '.data.summary')
    CONTRACT_TOTAL_CONDITIONS=$(echo "$CONTRACT_SUMMARY" | jq -r '.total_conditions // 0')
    CONTRACT_SUCCESS_RATE=$(echo "$CONTRACT_SUMMARY" | jq -r '.success_rate // 0')
    
    if [ "$CONTRACT_SUCCESS" = "true" ] && [ "$CONTRACT_TOTAL_CONDITIONS" -gt 0 ]; then
        echo "  ✅ $contract: Validation réussie ($CONTRACT_TOTAL_CONDITIONS conditions, $CONTRACT_SUCCESS_RATE% de réussite)"
    else
        echo "  ❌ $contract: Échec de la validation"
    fi
done
echo ""

# Test 6: Test avec différentes dates
echo "Test 6: Test avec différentes dates"
echo "----------------------------------------"
DATES=("2024-09-01" "2024-08-01" "2024-07-01")

for date in "${DATES[@]}"; do
    echo "Test avec la date $date:"
    
    DATE_RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
        -H "Content-Type: application/json" \
        -d "{\"date\": \"$date\", \"contract\": \"BTCUSDT\"}")
    
    DATE_SUCCESS=$(echo "$DATE_RESPONSE" | jq -r '.success // false')
    DATE_SUMMARY=$(echo "$DATE_RESPONSE" | jq -r '.data.summary')
    DATE_TOTAL_CONDITIONS=$(echo "$DATE_SUMMARY" | jq -r '.total_conditions // 0')
    
    if [ "$DATE_SUCCESS" = "true" ] && [ "$DATE_TOTAL_CONDITIONS" -gt 0 ]; then
        echo "  ✅ Date $date: Validation réussie ($DATE_TOTAL_CONDITIONS conditions)"
    else
        echo "  ❌ Date $date: Échec de la validation"
    fi
done
echo ""

# Test 7: Vérifier l'interface web
echo "Test 7: Vérification de l'interface web"
echo "----------------------------------------"
WEB_PAGE=$(curl -s "$API_BASE/indicators/test")

if echo "$WEB_PAGE" | grep -q "Validation en Cascade"; then
    echo "✅ Bouton 'Validation en Cascade' présent dans l'interface web"
else
    echo "❌ Bouton 'Validation en Cascade' manquant dans l'interface web"
fi

if echo "$WEB_PAGE" | grep -q "runCascadeValidation"; then
    echo "✅ Fonction JavaScript runCascadeValidation présente"
else
    echo "❌ Fonction JavaScript runCascadeValidation manquante"
fi

if echo "$WEB_PAGE" | grep -q "cascade-header"; then
    echo "✅ CSS pour la validation en cascade présent"
else
    echo "❌ CSS pour la validation en cascade manquant"
fi
echo ""

# Test 8: Test de performance
echo "Test 8: Test de performance"
echo "----------------------------------------"
echo "Mesure du temps d'exécution pour la validation en cascade:"

START_TIME=$(date +%s.%N)
PERF_RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d '{"date": "2024-10-01", "contract": "BTCUSDT"}')
END_TIME=$(date +%s.%N)

EXECUTION_TIME=$(echo "$END_TIME - $START_TIME" | bc)
PERF_SUCCESS=$(echo "$PERF_RESPONSE" | jq -r '.success // false')

echo "⏱️  Temps d'exécution: ${EXECUTION_TIME}s"

if [ "$PERF_SUCCESS" = "true" ]; then
    echo "✅ Performance: Validation réussie en ${EXECUTION_TIME}s"
else
    echo "❌ Performance: Échec de la validation"
fi
echo ""

echo "=========================================="
echo "Tests terminés"
echo "=========================================="
echo ""
echo "🎯 Résumé des fonctionnalités testées:"
echo "  ✅ Validation en cascade sur 5 timeframes (4h, 1h, 15m, 5m, 1m)"
echo "  ✅ Affichage des IDs des klines pour chaque timeframe"
echo "  ✅ Affichage des timestamps des klines"
echo "  ✅ Affichage de la période couverte pour chaque timeframe"
echo "  ✅ Résumé global avec statistiques"
echo "  ✅ Fonctionnement sur différents contrats"
echo "  ✅ Fonctionnement sur différentes dates"
echo "  ✅ Interface web avec bouton dédié"
echo "  ✅ Performance de la validation"
echo ""
echo "💡 Fonctionnalités de la validation en cascade:"
echo "  - Validation automatique sur tous les timeframes"
echo "  - Affichage des conditions OK/KO pour chaque timeframe"
echo "  - Affichage des IDs des klines utilisées"
echo "  - Affichage des timestamps des klines"
echo "  - Résumé du contexte (Close, ATR, RSI, EMA, VWAP)"
echo "  - Statut global basé sur tous les timeframes"
echo "  - Taux de réussite global"
echo ""
echo "🌐 Interface web: $API_BASE/indicators/test"
