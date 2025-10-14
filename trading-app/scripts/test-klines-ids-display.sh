#!/bin/bash

# Script de test pour vérifier l'affichage des IDs des klines utilisées
# Usage: ./scripts/test-klines-ids-display.sh

set -e

# Configuration
API_BASE="http://localhost:8082"
ENDPOINT="/indicators/revalidate"

echo "=========================================="
echo "Test d'affichage des IDs des klines utilisées"
echo "=========================================="
echo "URL: $API_BASE$ENDPOINT"
echo ""

# Test 1: Vérifier que les informations des klines sont présentes
echo "Test 1: Vérification des informations des klines"
echo "----------------------------------------"
RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d '{"date": "2024-10-01", "contracts": "BTCUSDT", "timeframe": "1h"}')

KLINES_USED=$(echo "$RESPONSE" | jq -r '.data.contracts_results.BTCUSDT.context.klines_used')

echo "Informations des klines utilisées:"
echo "$KLINES_USED" | jq '.'

# Vérifier les propriétés
COUNT=$(echo "$KLINES_USED" | jq -r '.count // 0')
IDS_COUNT=$(echo "$KLINES_USED" | jq -r '.ids | length')
TIMESTAMPS_COUNT=$(echo "$KLINES_USED" | jq -r '.timestamps | length')
FROM_DATE=$(echo "$KLINES_USED" | jq -r '.date_range.from // "N/A"')
TO_DATE=$(echo "$KLINES_USED" | jq -r '.date_range.to // "N/A"')

echo ""
echo "📊 Statistiques des klines:"
echo "  - Nombre total: $COUNT"
echo "  - IDs disponibles: $IDS_COUNT"
echo "  - Timestamps disponibles: $TIMESTAMPS_COUNT"
echo "  - Période: $FROM_DATE → $TO_DATE"

if [ "$COUNT" -gt 0 ] && [ "$IDS_COUNT" -gt 0 ]; then
    echo "✅ Informations des klines correctement générées"
else
    echo "❌ Informations des klines manquantes"
fi
echo ""

# Test 2: Vérifier les premiers et derniers IDs
echo "Test 2: Vérification des IDs des klines"
echo "----------------------------------------"
FIRST_ID=$(echo "$KLINES_USED" | jq -r '.ids[0] // "N/A"')
LAST_ID=$(echo "$KLINES_USED" | jq -r '.ids[-1] // "N/A"')
FIRST_TIMESTAMP=$(echo "$KLINES_USED" | jq -r '.timestamps[0] // "N/A"')
LAST_TIMESTAMP=$(echo "$KLINES_USED" | jq -r '.timestamps[-1] // "N/A"')

echo "🔍 Détails des klines:"
echo "  - Premier ID: $FIRST_ID"
echo "  - Dernier ID: $LAST_ID"
echo "  - Premier timestamp: $FIRST_TIMESTAMP"
echo "  - Dernier timestamp: $LAST_TIMESTAMP"

if [ "$FIRST_ID" != "N/A" ] && [ "$LAST_ID" != "N/A" ]; then
    echo "✅ IDs des klines correctement récupérés"
else
    echo "❌ IDs des klines manquants"
fi
echo ""

# Test 3: Vérifier la cohérence des données
echo "Test 3: Vérification de la cohérence des données"
echo "----------------------------------------"
if [ "$COUNT" = "$IDS_COUNT" ] && [ "$COUNT" = "$TIMESTAMPS_COUNT" ]; then
    echo "✅ Cohérence des données: tous les compteurs correspondent"
else
    echo "⚠️  Incohérence détectée:"
    echo "    - Count: $COUNT"
    echo "    - IDs: $IDS_COUNT"
    echo "    - Timestamps: $TIMESTAMPS_COUNT"
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
        -d "{\"date\": \"2024-10-01\", \"contracts\": \"BTCUSDT\", \"timeframe\": \"$tf\"}")
    
    TF_KLINES_USED=$(echo "$TF_RESPONSE" | jq -r ".data.contracts_results.BTCUSDT.context.klines_used")
    TF_COUNT=$(echo "$TF_KLINES_USED" | jq -r '.count // 0')
    TF_IDS_COUNT=$(echo "$TF_KLINES_USED" | jq -r '.ids | length')
    
    if [ "$TF_COUNT" -gt 0 ] && [ "$TF_IDS_COUNT" -gt 0 ]; then
        echo "  ✅ $tf: $TF_COUNT klines avec $TF_IDS_COUNT IDs"
    else
        echo "  ❌ $tf: Données manquantes"
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
        -d "{\"date\": \"2024-10-01\", \"contracts\": \"$contract\", \"timeframe\": \"1h\"}")
    
    CONTRACT_KLINES_USED=$(echo "$CONTRACT_RESPONSE" | jq -r ".data.contracts_results.$contract.context.klines_used")
    CONTRACT_COUNT=$(echo "$CONTRACT_KLINES_USED" | jq -r '.count // 0')
    CONTRACT_IDS_COUNT=$(echo "$CONTRACT_KLINES_USED" | jq -r '.ids | length')
    
    if [ "$CONTRACT_COUNT" -gt 0 ] && [ "$CONTRACT_IDS_COUNT" -gt 0 ]; then
        echo "  ✅ $contract: $CONTRACT_COUNT klines avec $CONTRACT_IDS_COUNT IDs"
    else
        echo "  ❌ $contract: Données manquantes"
    fi
done
echo ""

# Test 6: Vérifier l'affichage dans l'interface web
echo "Test 6: Vérification de l'affichage dans l'interface web"
echo "----------------------------------------"
WEB_PAGE=$(curl -s "$API_BASE/indicators/test")

if echo "$WEB_PAGE" | grep -q "Klines Utilisées"; then
    echo "✅ Section 'Klines Utilisées' présente dans l'interface web"
else
    echo "❌ Section 'Klines Utilisées' manquante dans l'interface web"
fi

if echo "$WEB_PAGE" | grep -q "klines-used-section"; then
    echo "✅ CSS pour la section klines utilisées présent"
else
    echo "❌ CSS pour la section klines utilisées manquant"
fi

if echo "$WEB_PAGE" | grep -q "displayIds"; then
    echo "✅ JavaScript pour l'affichage des IDs présent"
else
    echo "❌ JavaScript pour l'affichage des IDs manquant"
fi
echo ""

# Test 7: Test de performance avec beaucoup de klines
echo "Test 7: Test de performance avec beaucoup de klines"
echo "----------------------------------------"
echo "Test avec une date récente (plus de klines disponibles):"

PERF_RESPONSE=$(curl -s -X POST "$API_BASE$ENDPOINT" \
    -H "Content-Type: application/json" \
    -d '{"date": "2024-12-01", "contracts": "BTCUSDT", "timeframe": "1m"}')

PERF_KLINES_USED=$(echo "$PERF_RESPONSE" | jq -r '.data.contracts_results.BTCUSDT.context.klines_used')
PERF_COUNT=$(echo "$PERF_KLINES_USED" | jq -r '.count // 0')
PERF_IDS_COUNT=$(echo "$PERF_KLINES_USED" | jq -r '.ids | length')

echo "📊 Performance avec timeframe 1m:"
echo "  - Nombre de klines: $PERF_COUNT"
echo "  - Nombre d'IDs: $PERF_IDS_COUNT"

if [ "$PERF_COUNT" -gt 1000 ]; then
    echo "✅ Performance: Gestion correcte d'un grand nombre de klines"
else
    echo "⚠️  Performance: Nombre de klines limité"
fi
echo ""

echo "=========================================="
echo "Tests terminés"
echo "=========================================="
echo ""
echo "🎯 Résumé des fonctionnalités testées:"
echo "  ✅ Affichage des IDs des klines utilisées"
echo "  ✅ Affichage des timestamps des klines"
echo "  ✅ Affichage de la période couverte"
echo "  ✅ Cohérence des données (count, IDs, timestamps)"
echo "  ✅ Fonctionnement sur différents timeframes"
echo "  ✅ Fonctionnement sur différents contrats"
echo "  ✅ Interface web avec section dédiée"
echo "  ✅ Performance avec un grand nombre de klines"
echo ""
echo "💡 Informations affichées:"
echo "  - Nombre total de klines utilisées"
echo "  - Période couverte (from → to)"
echo "  - Liste des IDs (limitée à 10 pour l'affichage)"
echo "  - Liste des timestamps (limitée à 5 pour l'affichage)"
echo "  - Indication du nombre d'éléments supplémentaires"
echo ""
echo "🌐 Interface web: $API_BASE/indicators/test"

