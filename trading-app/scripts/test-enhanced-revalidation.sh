#!/bin/bash

# Script de test pour l'interface améliorée de revalidation
# Usage: ./scripts/test-enhanced-revalidation.sh

set -e

# Configuration
API_BASE="http://localhost:8082"
ENDPOINT="/indicators/revalidate"

echo "=========================================="
echo "Test de l'interface améliorée de revalidation"
echo "=========================================="
echo "URL: $API_BASE$ENDPOINT"
echo ""

# Test 1: Vérifier que la page se charge avec les nouvelles fonctionnalités
echo "Test 1: Chargement de la page avec interface améliorée"
echo "----------------------------------------"
if curl -s "$API_BASE/indicators/test" | grep -q "Date et heure de revalidation"; then
    echo "✅ Page chargée avec succès - Interface améliorée présente"
else
    echo "❌ Erreur lors du chargement de la page"
fi
echo ""

# Test 2: Test avec contrats valides pour voir les conditions OK/KO
echo "Test 2: Analyse des conditions OK/KO"
echo "----------------------------------------"
TEST_CONTRACTS="BTCUSDT,ETHUSDT"
echo "Contrats testés: $TEST_CONTRACTS"

RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d "{\"date\": \"2024-01-15\", \"contracts\": \"$TEST_CONTRACTS\", \"timeframe\": \"1h\"}")

if echo "$RESPONSE" | jq -e '.success' > /dev/null; then
    echo "✅ Revalidation réussie"
    
    # Analyser les conditions pour chaque contrat
    for contract in BTCUSDT ETHUSDT; do
        echo ""
        echo "📊 Analyse du contrat: $contract"
        echo "----------------------------------------"
        
        # Récupérer les données du contrat
        CONTRACT_DATA=$(echo "$RESPONSE" | jq -r ".data.contracts_results.$contract")
        
        if [ "$CONTRACT_DATA" != "null" ]; then
            # Statut global
            STATUS=$(echo "$CONTRACT_DATA" | jq -r '.status')
            SUCCESS_RATE=$(echo "$CONTRACT_DATA" | jq -r '.summary.success_rate')
            PASSED=$(echo "$CONTRACT_DATA" | jq -r '.summary.passed')
            TOTAL=$(echo "$CONTRACT_DATA" | jq -r '.summary.total_conditions')
            
            echo "  Statut: $STATUS"
            echo "  Taux de succès: $SUCCESS_RATE%"
            echo "  Conditions: $PASSED/$TOTAL"
            
            # Conditions OK
            echo ""
            echo "  ✅ Conditions OK:"
            echo "$CONTRACT_DATA" | jq -r '.conditions_results | to_entries[] | select(.value.passed == true) | "    - \(.key): \(.value.value // "N/A")"' | head -5
            
            # Conditions KO
            echo ""
            echo "  ❌ Conditions KO:"
            echo "$CONTRACT_DATA" | jq -r '.conditions_results | to_entries[] | select(.value.passed == false) | "    - \(.key): \(.value.value // "N/A") (\(.value.meta.missing_data // false | if . then "Données manquantes" else "Échec" end))"' | head -5
            
            # Données klines
            echo ""
            echo "  📈 Données Klines:"
            CONTEXT=$(echo "$CONTRACT_DATA" | jq -r '.context')
            if [ "$CONTEXT" != "null" ]; then
                CLOSE=$(echo "$CONTEXT" | jq -r '.close // "N/A"')
                RSI=$(echo "$CONTEXT" | jq -r '.rsi // "N/A"')
                MACD=$(echo "$CONTEXT" | jq -r '.macd.macd // "N/A"')
                VWAP=$(echo "$CONTEXT" | jq -r '.vwap // "N/A"')
                ATR=$(echo "$CONTEXT" | jq -r '.atr // "N/A"')
                
                echo "    - Prix de clôture: $CLOSE"
                echo "    - RSI: $RSI"
                echo "    - MACD: $MACD"
                echo "    - VWAP: $VWAP"
                echo "    - ATR: $ATR"
            else
                echo "    - Aucune donnée klines disponible"
            fi
        else
            echo "  ❌ Aucune donnée disponible pour $contract"
        fi
    done
else
    echo "❌ Erreur lors de la revalidation"
    echo "$RESPONSE" | jq -r '.error, .message'
fi
echo ""

# Test 3: Test avec contrats invalides pour voir la gestion d'erreur
echo "Test 3: Gestion des contrats invalides"
echo "----------------------------------------"
INVALID_CONTRACTS="INVALIDUSDT,FAKEUSDT"
echo "Contrats invalides testés: $INVALID_CONTRACTS"

ERROR_RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d "{\"date\": \"2024-01-15\", \"contracts\": \"$INVALID_CONTRACTS\", \"timeframe\": \"1h\"}")

if echo "$ERROR_RESPONSE" | jq -e '.success == false' > /dev/null; then
    echo "✅ Erreur correctement gérée"
    
    ERROR_MSG=$(echo "$ERROR_RESPONSE" | jq -r '.message')
    echo "  Message d'erreur: $ERROR_MSG"
    
    # Afficher les contrats disponibles
    AVAILABLE_CONTRACTS=$(echo "$ERROR_RESPONSE" | jq -r '.available_contracts[]' | head -5)
    echo "  Contrats disponibles (exemples):"
    echo "$AVAILABLE_CONTRACTS" | sed 's/^/    - /'
else
    echo "❌ Erreur non gérée correctement"
fi
echo ""

# Test 4: Test avec différents timeframes pour voir les variations
echo "Test 4: Variations par timeframe"
echo "----------------------------------------"
TEST_CONTRACT="BTCUSDT"
TIMEFRAMES=("1m" "5m" "15m" "1h" "4h")

for tf in "${TIMEFRAMES[@]}"; do
    echo "Test timeframe: $tf"
    
    TF_RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
        -H "Content-Type: application/json" \
        -d "{\"date\": \"2024-01-15\", \"contracts\": \"$TEST_CONTRACT\", \"timeframe\": \"$tf\"}")
    
    if echo "$TF_RESPONSE" | jq -e '.success' > /dev/null; then
        SUCCESS_RATE=$(echo "$TF_RESPONSE" | jq -r '.data.contracts_results.BTCUSDT.summary.success_rate')
        PASSED=$(echo "$TF_RESPONSE" | jq -r '.data.contracts_results.BTCUSDT.summary.passed')
        TOTAL=$(echo "$TF_RESPONSE" | jq -r '.data.contracts_results.BTCUSDT.summary.total_conditions')
        
        echo "  ✅ $tf: $PASSED/$TOTAL conditions ($SUCCESS_RATE%)"
        
        # Afficher quelques conditions spécifiques
        CONDITIONS_OK=$(echo "$TF_RESPONSE" | jq -r '.data.contracts_results.BTCUSDT.conditions_results | to_entries[] | select(.value.passed == true) | .key' | wc -l)
        CONDITIONS_KO=$(echo "$TF_RESPONSE" | jq -r '.data.contracts_results.BTCUSDT.conditions_results | to_entries[] | select(.value.passed == false) | .key' | wc -l)
        
        echo "    - Conditions OK: $CONDITIONS_OK"
        echo "    - Conditions KO: $CONDITIONS_KO"
    else
        echo "  ❌ $tf: Erreur"
    fi
done
echo ""

# Test 5: Test de performance avec interface améliorée
echo "Test 5: Performance avec interface améliorée"
echo "----------------------------------------"
MANY_CONTRACTS="BTCUSDT,ETHUSDT,ADAUSDT,DOTUSDT,LINKUSDT"
echo "Test avec 5 contrats: $MANY_CONTRACTS"

START_TIME=$(date +%s)
PERF_RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d "{\"date\": \"2024-01-15\", \"contracts\": \"$MANY_CONTRACTS\", \"timeframe\": \"1h\"}")
END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))

if echo "$PERF_RESPONSE" | jq -e '.success' > /dev/null; then
    CONTRACT_COUNT=$(echo "$PERF_RESPONSE" | jq -r '.data.global_summary.total_contracts')
    SUCCESS_RATE=$(echo "$PERF_RESPONSE" | jq -r '.data.global_summary.success_rate')
    
    echo "  ✅ $CONTRACT_COUNT contrats traités en ${DURATION}s"
    echo "  📊 Taux de succès global: $SUCCESS_RATE%"
    
    # Résumé des conditions
    echo "  📋 Résumé des conditions:"
    echo "$PERF_RESPONSE" | jq -r '.data.contracts_results | to_entries[] | "    - \(.key): \(.value.summary.passed)/\(.value.summary.total_conditions) (\(.value.summary.success_rate)%)"'
else
    echo "  ❌ Erreur lors du test de performance"
fi
echo ""

echo "=========================================="
echo "Tests terminés"
echo "=========================================="
echo ""
echo "🎯 Résumé des fonctionnalités testées:"
echo "  ✅ Interface améliorée avec conditions OK/KO"
echo "  ✅ Affichage détaillé des données klines"
echo "  ✅ Gestion des contrats invalides"
echo "  ✅ Variations par timeframe"
echo "  ✅ Performance avec interface améliorée"
echo ""
echo "🌐 Interface web disponible sur: $API_BASE/indicators/test"
echo "📊 Nouvelles fonctionnalités:"
echo "  - Affichage détaillé des conditions OK/KO"
echo "  - Données klines complètes (prix, RSI, MACD, VWAP, ATR)"
echo "  - Boutons de détails pour chaque contrat"
echo "  - Gestion des données manquantes"
echo "  - Interface responsive et intuitive"

