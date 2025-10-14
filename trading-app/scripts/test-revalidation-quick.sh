#!/bin/bash

# Script de test rapide pour l'endpoint de revalidation des contrats
# Usage: ./scripts/test-revalidation-quick.sh [test_name]

set -e

# Configuration
API_BASE="http://localhost:8082"
ENDPOINT="/indicators/revalidate"

echo "=========================================="
echo "Test rapide de l'endpoint de revalidation"
echo "=========================================="
echo "URL: $API_BASE$ENDPOINT"
echo ""

# Fonction pour tester l'endpoint
test_endpoint() {
    local test_name="$1"
    local date="$2"
    local contracts="$3"
    local timeframe="$4"
    
    echo "Test: $test_name"
    echo "Date: $date"
    echo "Contrats: $contracts"
    echo "Timeframe: $timeframe"
    echo "----------------------------------------"
    
    # Envoyer la requête
    local response=$(curl -s -X POST "$API_BASE$ENDPOINT" \
        -H "Content-Type: application/json" \
        -d "{\"date\": \"$date\", \"contracts\": \"$contracts\", \"timeframe\": \"$timeframe\"}")
    
    # Vérifier le statut
    local success=$(echo "$response" | jq -r '.success // false')
    if [ "$success" = "true" ]; then
        echo "✅ Test réussi"
        
        # Afficher le résumé global
        local total_contracts=$(echo "$response" | jq -r '.data.global_summary.total_contracts')
        local successful_validations=$(echo "$response" | jq -r '.data.global_summary.successful_validations')
        local success_rate=$(echo "$response" | jq -r '.data.global_summary.success_rate')
        
        echo "📊 Résumé global:"
        echo "  - Contrats analysés: $total_contracts"
        echo "  - Validations réussies: $successful_validations"
        echo "  - Taux de succès: $success_rate%"
        
        # Afficher les résultats par contrat
        echo "📋 Résultats par contrat:"
        echo "$response" | jq -r '.data.contracts_results | to_entries[] | "  - \(.key): \(.value.status) (succès: \(.value.summary.success_rate)%)"'
        
    else
        echo "❌ Test échoué"
        local error=$(echo "$response" | jq -r '.error // "Erreur inconnue"')
        local message=$(echo "$response" | jq -r '.message // "Message d\'erreur non disponible"')
        echo "  Erreur: $error"
        echo "  Message: $message"
    fi
    
    echo ""
}

# Tests selon le paramètre
case "${1:-all}" in
    "single")
        test_endpoint "Un seul contrat" "2024-01-15" "BTCUSDT" "1h"
        ;;
    "multiple")
        test_endpoint "Plusieurs contrats" "2024-01-15" "BTCUSDT,ETHUSDT,ADAUSDT" "1h"
        ;;
    "timeframe")
        test_endpoint "Timeframe 5m" "2024-01-15" "BTCUSDT,ETHUSDT" "5m"
        ;;
    "date")
        test_endpoint "Date différente" "2024-02-20" "SOLUSDT,MATICUSDT" "1h"
        ;;
    "error")
        test_endpoint "Cas d'erreur - Date manquante" "" "BTCUSDT" "1h"
        ;;
    "all")
        test_endpoint "Un seul contrat" "2024-01-15" "BTCUSDT" "1h"
        test_endpoint "Plusieurs contrats" "2024-01-15" "BTCUSDT,ETHUSDT,ADAUSDT" "1h"
        test_endpoint "Timeframe 5m" "2024-01-15" "BTCUSDT,ETHUSDT" "5m"
        test_endpoint "Date différente" "2024-02-20" "SOLUSDT,MATICUSDT" "1h"
        test_endpoint "Cas d'erreur - Date manquante" "" "BTCUSDT" "1h"
        ;;
    *)
        echo "Usage: $0 [single|multiple|timeframe|date|error|all]"
        echo ""
        echo "Tests disponibles:"
        echo "  single    - Test avec un seul contrat"
        echo "  multiple  - Test avec plusieurs contrats"
        echo "  timeframe - Test avec timeframe différent"
        echo "  date      - Test avec date différente"
        echo "  error     - Test de cas d'erreur"
        echo "  all       - Tous les tests (défaut)"
        exit 1
        ;;
esac

echo "=========================================="
echo "Tests terminés"
echo "=========================================="

