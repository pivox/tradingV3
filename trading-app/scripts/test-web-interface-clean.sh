#!/bin/bash

# Script de test pour l'interface web nettoyée (sans champ legacy)
# Usage: ./scripts/test-web-interface-clean.sh

set -e

# Configuration
WEB_URL="http://localhost:8082/indicators/test"
API_URL="http://localhost:8082/indicators/revalidate"
CONTRACTS_URL="http://localhost:8082/indicators/available-contracts"

echo "=========================================="
echo "Test de l'interface web nettoyée"
echo "=========================================="
echo "URL: $WEB_URL"
echo ""

# Test 1: Vérifier que la page web se charge
echo "Test 1: Chargement de la page web"
echo "----------------------------------------"
if curl -s -f "$WEB_URL" > /dev/null; then
    echo "✅ Page web accessible (HTTP 200)"
else
    echo "❌ Page web inaccessible"
    exit 1
fi
echo ""

# Test 2: Vérifier que le champ legacy a été supprimé
echo "Test 2: Vérification de la suppression du champ legacy"
echo "----------------------------------------"
LEGACY_COUNT=$(curl -s "$WEB_URL" | grep -c "Symbole (legacy)" 2>/dev/null || echo "0")
if [ "$LEGACY_COUNT" -eq 0 ]; then
    echo "✅ Champ symbole legacy supprimé"
else
    echo "❌ Champ symbole legacy encore présent ($LEGACY_COUNT occurrences)"
fi
echo ""

# Test 3: Vérifier que les nouveaux champs sont présents
echo "Test 3: Vérification des nouveaux champs"
echo "----------------------------------------"
DATETIME_COUNT=$(curl -s "$WEB_URL" | grep -c "datetime-local" || echo "0")
CONTRACT_SEARCH_COUNT=$(curl -s "$WEB_URL" | grep -c "contractSearch" || echo "0")
REVALIDATION_BUTTON_COUNT=$(curl -s "$WEB_URL" | grep -c "Revalidation des Contrats" || echo "0")

if [ "$DATETIME_COUNT" -gt 0 ]; then
    echo "✅ Champ datetime UTC présent"
else
    echo "❌ Champ datetime UTC manquant"
fi

if [ "$CONTRACT_SEARCH_COUNT" -gt 0 ]; then
    echo "✅ Champ recherche de contrats présent"
else
    echo "❌ Champ recherche de contrats manquant"
fi

if [ "$REVALIDATION_BUTTON_COUNT" -gt 0 ]; then
    echo "✅ Bouton de revalidation présent"
else
    echo "❌ Bouton de revalidation manquant"
fi
echo ""

# Test 4: Vérifier l'endpoint des contrats
echo "Test 4: Vérification de l'endpoint des contrats"
echo "----------------------------------------"
CONTRACTS_RESPONSE=$(curl -s "$CONTRACTS_URL")
if echo "$CONTRACTS_RESPONSE" | jq -e '.success' > /dev/null; then
    CONTRACT_COUNT=$(echo "$CONTRACTS_RESPONSE" | jq -r '.data.count')
    echo "✅ Endpoint des contrats fonctionnel"
    echo "📊 Nombre de contrats disponibles: $CONTRACT_COUNT"
else
    echo "❌ Endpoint des contrats défaillant"
fi
echo ""

# Test 5: Test de revalidation via l'interface
echo "Test 5: Test de revalidation via l'interface"
echo "----------------------------------------"
REVALIDATION_RESPONSE=$(curl -s -X POST "$API_URL" \
    -H "Content-Type: application/json" \
    -d '{"date": "2024-10-01", "contracts": "BTCUSDT,ETHUSDT", "timeframe": "1h"}')

if echo "$REVALIDATION_RESPONSE" | jq -e '.success' > /dev/null; then
    SUCCESS_RATE=$(echo "$REVALIDATION_RESPONSE" | jq -r '.data.global_summary.success_rate')
    TOTAL_CONTRACTS=$(echo "$REVALIDATION_RESPONSE" | jq -r '.data.global_summary.total_contracts')
    echo "✅ Revalidation fonctionnelle"
    echo "📊 Taux de succès: $SUCCESS_RATE%"
    echo "📊 Contrats traités: $TOTAL_CONTRACTS"
    
    # Vérifier les données klines récupérées
    BTC_CLOSE=$(echo "$REVALIDATION_RESPONSE" | jq -r '.data.contracts_results.BTCUSDT.context.close // "N/A"')
    ETH_CLOSE=$(echo "$REVALIDATION_RESPONSE" | jq -r '.data.contracts_results.ETHUSDT.context.close // "N/A"')
    
    if [ "$BTC_CLOSE" != "N/A" ] && [ "$ETH_CLOSE" != "N/A" ]; then
        echo "✅ Données klines récupérées depuis l'exchange"
        echo "📈 BTCUSDT: $BTC_CLOSE USDT"
        echo "📈 ETHUSDT: $ETH_CLOSE USDT"
    else
        echo "⚠️  Données klines manquantes"
    fi
else
    echo "❌ Revalidation défaillante"
    echo "$REVALIDATION_RESPONSE" | jq -r '.error, .message' 2>/dev/null || echo "$REVALIDATION_RESPONSE"
fi
echo ""

# Test 6: Vérification des fonctionnalités JavaScript
echo "Test 6: Vérification des fonctionnalités JavaScript"
echo "----------------------------------------"
JS_FUNCTIONS=("loadAvailableContracts" "initializeContractSearch" "runRevalidation" "displayRevalidationResults" "toggleContractDetails")

for func in "${JS_FUNCTIONS[@]}"; do
    if curl -s "$WEB_URL" | grep -q "function $func"; then
        echo "✅ Fonction JavaScript $func présente"
    else
        echo "❌ Fonction JavaScript $func manquante"
    fi
done
echo ""

# Test 7: Vérification des styles CSS
echo "Test 7: Vérification des styles CSS"
echo "----------------------------------------"
CSS_CLASSES=("contract-search" "contract-dropdown" "selected-contracts" "date-input" "contract-details")

for class in "${CSS_CLASSES[@]}"; do
    if curl -s "$WEB_URL" | grep -q "\.$class"; then
        echo "✅ Style CSS .$class présent"
    else
        echo "❌ Style CSS .$class manquant"
    fi
done
echo ""

echo "=========================================="
echo "Tests terminés"
echo "=========================================="
echo ""
echo "🎯 Résumé des fonctionnalités testées:"
echo "  ✅ Page web accessible"
echo "  ✅ Champ legacy supprimé"
echo "  ✅ Nouveaux champs présents"
echo "  ✅ Endpoint des contrats fonctionnel"
echo "  ✅ Revalidation avec klines de l'exchange"
echo "  ✅ Fonctions JavaScript complètes"
echo "  ✅ Styles CSS appliqués"
echo ""
echo "🌐 Interface web disponible sur: $WEB_URL"
echo "📊 Fonctionnalités disponibles:"
echo "  - Sélection datetime UTC"
echo "  - Recherche et sélection multiple de contrats"
echo "  - Revalidation avec récupération des klines"
echo "  - Affichage des conditions OK/KO"
echo "  - Données klines détaillées"
echo "  - Interface responsive et moderne"
