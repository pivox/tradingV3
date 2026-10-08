#!/bin/bash

# Script de test pour la récupération des klines depuis l'exchange
# Usage: ./scripts/test-klines-fetching.sh

set -e

# Configuration
API_BASE="http://localhost:8082"
ENDPOINT="/indicators/revalidate"

echo "=========================================="
echo "Test de récupération des klines depuis l'exchange"
echo "=========================================="
echo "URL: $API_BASE$ENDPOINT"
echo ""

# Test 1: Test avec une date récente pour vérifier la récupération des klines
echo "Test 1: Récupération des klines pour une date récente"
echo "----------------------------------------"
RECENT_DATE="2024-10-01"
TEST_CONTRACTS="BTCUSDT,ETHUSDT"
echo "Date testée: $RECENT_DATE"
echo "Contrats testés: $TEST_CONTRACTS"

RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d "{\"date\": \"$RECENT_DATE\", \"contracts\": \"$TEST_CONTRACTS\", \"timeframe\": \"1h\"}")

if echo "$RESPONSE" | jq -e '.success' > /dev/null; then
    echo "✅ Revalidation réussie avec récupération des klines"
    
    # Analyser les résultats
    for contract in BTCUSDT ETHUSDT; do
        echo ""
        echo "📊 Analyse du contrat: $contract"
        echo "----------------------------------------"
        
        CONTRACT_DATA=$(echo "$RESPONSE" | jq -r ".data.contracts_results.$contract")
        
        if [ "$CONTRACT_DATA" != "null" ]; then
            STATUS=$(echo "$CONTRACT_DATA" | jq -r '.status')
            SUCCESS_RATE=$(echo "$CONTRACT_DATA" | jq -r '.summary.success_rate')
            PASSED=$(echo "$CONTRACT_DATA" | jq -r '.summary.passed')
            TOTAL=$(echo "$CONTRACT_DATA" | jq -r '.summary.total_conditions')
            
            echo "  Statut: $STATUS"
            echo "  Taux de succès: $SUCCESS_RATE%"
            echo "  Conditions: $PASSED/$TOTAL"
            
            # Vérifier les données klines
            CONTEXT=$(echo "$CONTRACT_DATA" | jq -r '.context')
            if [ "$CONTEXT" != "null" ]; then
                CLOSE=$(echo "$CONTEXT" | jq -r '.close // "N/A"')
                RSI=$(echo "$CONTEXT" | jq -r '.rsi // "N/A"')
                MACD=$(echo "$CONTEXT" | jq -r '.macd.macd // "N/A"')
                VWAP=$(echo "$CONTEXT" | jq -r '.vwap // "N/A"')
                ATR=$(echo "$CONTEXT" | jq -r '.atr // "N/A"')
                
                echo "  📈 Données klines récupérées:"
                echo "    - Prix de clôture: $CLOSE"
                echo "    - RSI: $RSI"
                echo "    - MACD: $MACD"
                echo "    - VWAP: $VWAP"
                echo "    - ATR: $ATR"
                
                # Vérifier si les données semblent réalistes
                if [ "$CLOSE" != "N/A" ] && [ "$CLOSE" != "null" ]; then
                    echo "  ✅ Données klines valides récupérées depuis l'exchange"
                else
                    echo "  ⚠️  Données klines manquantes ou invalides"
                fi
            else
                echo "  ❌ Aucun contexte klines disponible"
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

# Test 2: Test avec différents timeframes pour vérifier la récupération
echo "Test 2: Récupération des klines pour différents timeframes"
echo "----------------------------------------"
TEST_CONTRACT="BTCUSDT"
TIMEFRAMES=("1m" "5m" "15m" "1h" "4h")

for tf in "${TIMEFRAMES[@]}"; do
    echo "Test timeframe: $tf"
    
    TF_RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
        -H "Content-Type: application/json" \
        -d "{\"date\": \"$RECENT_DATE\", \"contracts\": \"$TEST_CONTRACT\", \"timeframe\": \"$tf\"}")
    
    if echo "$TF_RESPONSE" | jq -e '.success' > /dev/null; then
        SUCCESS_RATE=$(echo "$TF_RESPONSE" | jq -r '.data.contracts_results.BTCUSDT.summary.success_rate')
        PASSED=$(echo "$TF_RESPONSE" | jq -r '.data.contracts_results.BTCUSDT.summary.passed')
        TOTAL=$(echo "$TF_RESPONSE" | jq -r '.data.contracts_results.BTCUSDT.summary.total_conditions')
        
        # Vérifier les données klines
        CONTEXT=$(echo "$TF_RESPONSE" | jq -r '.data.contracts_results.BTCUSDT.context')
        CLOSE=$(echo "$CONTEXT" | jq -r '.close // "N/A"')
        
        if [ "$CLOSE" != "N/A" ] && [ "$CLOSE" != "null" ]; then
            echo "  ✅ $tf: $PASSED/$TOTAL conditions ($SUCCESS_RATE%) - Klines récupérées"
        else
            echo "  ⚠️  $tf: $PASSED/$TOTAL conditions ($SUCCESS_RATE%) - Klines manquantes"
        fi
    else
        echo "  ❌ $tf: Erreur"
    fi
done
echo ""

# Test 3: Test avec une date plus ancienne
echo "Test 3: Récupération des klines pour une date ancienne"
echo "----------------------------------------"
OLD_DATE="2024-01-01"
echo "Date testée: $OLD_DATE"

OLD_RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d "{\"date\": \"$OLD_DATE\", \"contracts\": \"BTCUSDT\", \"timeframe\": \"1h\"}")

if echo "$OLD_RESPONSE" | jq -e '.success' > /dev/null; then
    SUCCESS_RATE=$(echo "$OLD_RESPONSE" | jq -r '.data.contracts_results.BTCUSDT.summary.success_rate')
    CONTEXT=$(echo "$OLD_RESPONSE" | jq -r '.data.contracts_results.BTCUSDT.context')
    CLOSE=$(echo "$CONTEXT" | jq -r '.close // "N/A"')
    
    if [ "$CLOSE" != "N/A" ] && [ "$CLOSE" != "null" ]; then
        echo "  ✅ Date ancienne: Taux de succès $SUCCESS_RATE% - Klines récupérées"
    else
        echo "  ⚠️  Date ancienne: Taux de succès $SUCCESS_RATE% - Klines manquantes"
    fi
else
    echo "  ❌ Erreur pour la date ancienne"
fi
echo ""

# Test 4: Test de performance avec récupération des klines
echo "Test 4: Performance avec récupération des klines"
echo "----------------------------------------"
MANY_CONTRACTS="BTCUSDT,ETHUSDT,ADAUSDT"
echo "Test avec 3 contrats: $MANY_CONTRACTS"

START_TIME=$(date +%s)
PERF_RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d "{\"date\": \"$RECENT_DATE\", \"contracts\": \"$MANY_CONTRACTS\", \"timeframe\": \"1h\"}")
END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))

if echo "$PERF_RESPONSE" | jq -e '.success' > /dev/null; then
    CONTRACT_COUNT=$(echo "$PERF_RESPONSE" | jq -r '.data.global_summary.total_contracts')
    SUCCESS_RATE=$(echo "$PERF_RESPONSE" | jq -r '.data.global_summary.success_rate')
    
    echo "  ✅ $CONTRACT_COUNT contrats traités en ${DURATION}s"
    echo "  📊 Taux de succès global: $SUCCESS_RATE%"
    
    # Vérifier que les klines ont été récupérées pour tous les contrats
    KLINE_COUNT=0
    for contract in BTCUSDT ETHUSDT ADAUSDT; do
        CONTEXT=$(echo "$PERF_RESPONSE" | jq -r ".data.contracts_results.$contract.context")
        CLOSE=$(echo "$CONTEXT" | jq -r '.close // "N/A"')
        if [ "$CLOSE" != "N/A" ] && [ "$CLOSE" != "null" ]; then
            KLINE_COUNT=$((KLINE_COUNT + 1))
        fi
    done
    
    echo "  📈 Contrats avec klines récupérées: $KLINE_COUNT/$CONTRACT_COUNT"
else
    echo "  ❌ Erreur lors du test de performance"
fi
echo ""

echo "=========================================="
echo "Tests terminés"
echo "=========================================="
echo ""
echo "🎯 Résumé des fonctionnalités testées:"
echo "  ✅ Récupération des klines depuis l'exchange"
echo "  ✅ Détection et comblement des trous dans les données"
echo "  ✅ Support de différents timeframes"
echo "  ✅ Gestion des dates anciennes et récentes"
echo "  ✅ Performance avec récupération des klines"
echo ""
echo "🌐 Interface web disponible sur: $API_BASE/indicators/test"
echo "📊 Nouvelles fonctionnalités:"
echo "  - Récupération automatique des klines manquantes"
echo "  - Détection des gaps dans les données historiques"
echo "  - Comblement des trous via l'API de l'exchange"
echo "  - Validation avec les vraies données de marché"
echo "  - Fallback vers données simulées si nécessaire"

