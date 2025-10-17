#!/bin/bash

# Script de test pour l'endpoint de revalidation des contrats
# Usage: ./scripts/test-revalidation.sh [test_name]

set -e

# Configuration
API_BASE="http://localhost:8082"
ENDPOINT="/indicators/revalidate"

# Couleurs pour l'affichage
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Fonction d'affichage
print_header() {
    echo -e "${BLUE}========================================${NC}"
    echo -e "${BLUE}$1${NC}"
    echo -e "${BLUE}========================================${NC}"
}

print_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

print_info() {
    echo -e "${BLUE}ℹ️  $1${NC}"
}

# Fonction pour tester l'endpoint de revalidation
test_revalidation() {
    local test_name="$1"
    local date="$2"
    local contracts="$3"
    local timeframe="$4"
    local description="$5"
    
    print_header "Test: $test_name"
    print_info "Description: $description"
    print_info "Date: $date"
    print_info "Contrats: $contracts"
    print_info "Timeframe: $timeframe"
    
    # Préparer les données JSON
    local json_data=$(cat <<EOF
{
    "date": "$date",
    "contracts": "$contracts",
    "timeframe": "$timeframe"
}
EOF
)
    
    echo -e "\n${YELLOW}Requête envoyée:${NC}"
    echo "$json_data" | jq .
    
    echo -e "\n${YELLOW}Réponse reçue:${NC}"
    
    # Envoyer la requête
    local response=$(curl -s -X POST "$API_BASE$ENDPOINT" \
        -H "Content-Type: application/json" \
        -d "$json_data")
    
    # Vérifier si la requête a réussi
    if [ $? -eq 0 ]; then
        echo "$response" | jq .
        
        # Vérifier le statut de la réponse
        local success=$(echo "$response" | jq -r '.success // false')
        if [ "$success" = "true" ]; then
            print_success "Test réussi: $test_name"
            
            # Afficher le résumé global
            local global_summary=$(echo "$response" | jq -r '.data.global_summary // {}')
            if [ "$global_summary" != "{}" ]; then
                echo -e "\n${GREEN}Résumé global:${NC}"
                echo "$global_summary" | jq .
            fi
            
            # Afficher les résultats par contrat
            local contracts_results=$(echo "$response" | jq -r '.data.contracts_results // {}')
            if [ "$contracts_results" != "{}" ]; then
                echo -e "\n${GREEN}Résultats par contrat:${NC}"
                echo "$contracts_results" | jq .
            fi
            
        else
            print_error "Test échoué: $test_name"
            local error=$(echo "$response" | jq -r '.error // "Erreur inconnue"')
            local message=$(echo "$response" | jq -r '.message // "Message d\'erreur non disponible"')
            echo -e "${RED}Erreur: $error${NC}"
            echo -e "${RED}Message: $message${NC}"
        fi
    else
        print_error "Erreur de connexion pour le test: $test_name"
    fi
    
    echo -e "\n"
}

# Fonction pour tester les cas d'erreur
test_error_cases() {
    print_header "Tests des cas d'erreur"
    
    # Test 1: Date manquante
    test_revalidation "Date manquante" "" "BTCUSDT,ETHUSDT" "1h" "Test avec date manquante"
    
    # Test 2: Contrats manquants
    test_revalidation "Contrats manquants" "2024-01-15" "" "1h" "Test avec contrats manquants"
    
    # Test 3: Format de date invalide
    test_revalidation "Format de date invalide" "15/01/2024" "BTCUSDT" "1h" "Test avec format de date invalide"
    
    # Test 4: Contrats invalides
    test_revalidation "Contrats invalides" "2024-01-15" "INVALIDUSDT,FAKEUSDT" "1h" "Test avec contrats invalides"
    
    # Test 5: Timeframe invalide
    test_revalidation "Timeframe invalide" "2024-01-15" "BTCUSDT" "2h" "Test avec timeframe invalide"
}

# Fonction pour tester les cas de succès
test_success_cases() {
    print_header "Tests des cas de succès"
    
    # Test 1: Un seul contrat
    test_revalidation "Un seul contrat" "2024-01-15" "BTCUSDT" "1h" "Test avec un seul contrat"
    
    # Test 2: Plusieurs contrats
    test_revalidation "Plusieurs contrats" "2024-01-15" "BTCUSDT,ETHUSDT,ADAUSDT" "1h" "Test avec plusieurs contrats"
    
    # Test 3: Différents timeframes
    test_revalidation "Timeframe 5m" "2024-01-15" "BTCUSDT,ETHUSDT" "5m" "Test avec timeframe 5m"
    test_revalidation "Timeframe 15m" "2024-01-15" "BTCUSDT,ETHUSDT" "15m" "Test avec timeframe 15m"
    test_revalidation "Timeframe 1h" "2024-01-15" "BTCUSDT,ETHUSDT" "1h" "Test avec timeframe 1h"
    test_revalidation "Timeframe 4h" "2024-01-15" "BTCUSDT,ETHUSDT" "4h" "Test avec timeframe 4h"
    
    # Test 4: Date différente
    test_revalidation "Date différente" "2024-02-20" "SOLUSDT,MATICUSDT" "1h" "Test avec une date différente"
    
    # Test 5: Tous les contrats disponibles
    test_revalidation "Tous les contrats" "2024-01-15" "BTCUSDT,ETHUSDT,ADAUSDT,DOTUSDT,LINKUSDT,SOLUSDT,MATICUSDT,AVAXUSDT" "1h" "Test avec tous les contrats disponibles"
}

# Fonction pour tester les performances
test_performance() {
    print_header "Tests de performance"
    
    # Test avec beaucoup de contrats
    test_revalidation "Performance - Beaucoup de contrats" "2024-01-15" "BTCUSDT,ETHUSDT,ADAUSDT,DOTUSDT,LINKUSDT,SOLUSDT,MATICUSDT,AVAXUSDT,BTCUSDT,ETHUSDT" "1h" "Test de performance avec 10 contrats"
    
    # Test avec timeframe complexe
    test_revalidation "Performance - Timeframe complexe" "2024-01-15" "BTCUSDT,ETHUSDT,ADAUSDT" "4h" "Test de performance avec timeframe 4h"
}

# Fonction principale
main() {
    local test_type="${1:-all}"
    
    print_header "Tests de l'endpoint de revalidation des contrats"
    print_info "URL: $API_BASE$ENDPOINT"
    print_info "Type de test: $test_type"
    
    case "$test_type" in
        "error")
            test_error_cases
            ;;
        "success")
            test_success_cases
            ;;
        "performance")
            test_performance
            ;;
        "all")
            test_error_cases
            test_success_cases
            test_performance
            ;;
        *)
            print_error "Type de test invalide: $test_type"
            print_info "Types disponibles: error, success, performance, all"
            exit 1
            ;;
    esac
    
    print_header "Tests terminés"
    print_success "Tous les tests ont été exécutés"
}

# Vérifier que jq est installé
if ! command -v jq &> /dev/null; then
    print_error "jq n'est pas installé. Veuillez l'installer pour utiliser ce script."
    exit 1
fi

# Vérifier que curl est installé
if ! command -v curl &> /dev/null; then
    print_error "curl n'est pas installé. Veuillez l'installer pour utiliser ce script."
    exit 1
fi

# Exécuter le script principal
main "$@"

