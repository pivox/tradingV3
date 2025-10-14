#!/bin/bash

# Script de test avancé pour l'endpoint de revalidation des contrats
# Usage: ./scripts/test-revalidation-advanced.sh [scenario]

set -e

# Configuration
API_BASE="http://localhost:8082"
ENDPOINT="/indicators/revalidate"

# Couleurs pour l'affichage
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
CYAN='\033[0;36m'
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

print_result() {
    echo -e "${PURPLE}📊 $1${NC}"
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
    local json_data="{\"date\": \"$date\", \"contracts\": \"$contracts\", \"timeframe\": \"$timeframe\"}"
    
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
                
                # Extraire les métriques
                local total_contracts=$(echo "$global_summary" | jq -r '.total_contracts')
                local successful_validations=$(echo "$global_summary" | jq -r '.successful_validations')
                local success_rate=$(echo "$global_summary" | jq -r '.success_rate')
                
                print_result "Contrats analysés: $total_contracts"
                print_result "Validations réussies: $successful_validations"
                print_result "Taux de succès: $success_rate%"
            fi
            
            # Afficher les résultats par contrat
            local contracts_results=$(echo "$response" | jq -r '.data.contracts_results // {}')
            if [ "$contracts_results" != "{}" ]; then
                echo -e "\n${GREEN}Résultats par contrat:${NC}"
                echo "$contracts_results" | jq -r 'to_entries[] | "\(.key): \(.value.status) (succès: \(.value.summary.success_rate)%)"'
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

# Fonction pour analyser les résultats
analyze_results() {
    local response="$1"
    local test_name="$2"
    
    print_header "Analyse des résultats: $test_name"
    
    # Analyser le résumé global
    local global_summary=$(echo "$response" | jq -r '.data.global_summary // {}')
    if [ "$global_summary" != "{}" ]; then
        local success_rate=$(echo "$global_summary" | jq -r '.success_rate')
        local total_contracts=$(echo "$global_summary" | jq -r '.total_contracts')
        local successful_validations=$(echo "$global_summary" | jq -r '.successful_validations')
        
        print_result "Taux de succès global: $success_rate%"
        print_result "Contrats analysés: $total_contracts"
        print_result "Validations réussies: $successful_validations"
        
        # Évaluer la performance
        if [ "$success_rate" -ge 70 ]; then
            print_success "Performance excellente (≥70%)"
        elif [ "$success_rate" -ge 50 ]; then
            print_warning "Performance moyenne (50-69%)"
        else
            print_error "Performance faible (<50%)"
        fi
    fi
    
    # Analyser les résultats par contrat
    local contracts_results=$(echo "$response" | jq -r '.data.contracts_results // {}')
    if [ "$contracts_results" != "{}" ]; then
        echo -e "\n${CYAN}Analyse par contrat:${NC}"
        
        # Compter les statuts
        local valid_count=$(echo "$contracts_results" | jq -r 'to_entries[] | select(.value.status == "valid") | .key' | wc -l)
        local invalid_count=$(echo "$contracts_results" | jq -r 'to_entries[] | select(.value.status == "invalid") | .key' | wc -l)
        local partial_count=$(echo "$contracts_results" | jq -r 'to_entries[] | select(.value.status == "partial") | .key' | wc -l)
        local error_count=$(echo "$contracts_results" | jq -r 'to_entries[] | select(.value.status == "error") | .key' | wc -l)
        
        print_result "Contrats valides: $valid_count"
        print_result "Contrats invalides: $invalid_count"
        print_result "Contrats partiels: $partial_count"
        print_result "Contrats en erreur: $error_count"
        
        # Afficher les contrats valides
        if [ "$valid_count" -gt 0 ]; then
            echo -e "\n${GREEN}Contrats valides:${NC}"
            echo "$contracts_results" | jq -r 'to_entries[] | select(.value.status == "valid") | "  ✅ \(.key): \(.value.summary.success_rate)%"'
        fi
        
        # Afficher les contrats invalides
        if [ "$invalid_count" -gt 0 ]; then
            echo -e "\n${RED}Contrats invalides:${NC}"
            echo "$contracts_results" | jq -r 'to_entries[] | select(.value.status == "invalid") | "  ❌ \(.key): \(.value.summary.success_rate)%"'
        fi
    fi
    
    echo -e "\n"
}

# Scénario 1: Test de base
scenario_basic() {
    print_header "Scénario 1: Tests de base"
    
    # Test 1: Un seul contrat
    test_revalidation "Un seul contrat" "2024-01-15" "BTCUSDT" "1h" "Test avec un seul contrat"
    
    # Test 2: Plusieurs contrats
    test_revalidation "Plusieurs contrats" "2024-01-15" "BTCUSDT,ETHUSDT,ADAUSDT" "1h" "Test avec plusieurs contrats"
    
    # Test 3: Timeframe différent
    test_revalidation "Timeframe 5m" "2024-01-15" "BTCUSDT,ETHUSDT" "5m" "Test avec timeframe 5m"
}

# Scénario 2: Tests de performance
scenario_performance() {
    print_header "Scénario 2: Tests de performance"
    
    # Test avec beaucoup de contrats
    test_revalidation "Performance - Beaucoup de contrats" "2024-01-15" "BTCUSDT,ETHUSDT,ADAUSDT,DOTUSDT,LINKUSDT,SOLUSDT,MATICUSDT,AVAXUSDT" "1h" "Test de performance avec 8 contrats"
    
    # Test avec timeframe complexe
    test_revalidation "Performance - Timeframe 4h" "2024-01-15" "BTCUSDT,ETHUSDT,ADAUSDT" "4h" "Test de performance avec timeframe 4h"
}

# Scénario 3: Tests de dates
scenario_dates() {
    print_header "Scénario 3: Tests de dates"
    
    # Test avec différentes dates
    test_revalidation "Date 2024-01-01" "2024-01-01" "BTCUSDT,ETHUSDT" "1h" "Test avec date du 1er janvier"
    test_revalidation "Date 2024-06-15" "2024-06-15" "BTCUSDT,ETHUSDT" "1h" "Test avec date du 15 juin"
    test_revalidation "Date 2024-12-31" "2024-12-31" "BTCUSDT,ETHUSDT" "1h" "Test avec date du 31 décembre"
}

# Scénario 4: Tests d'erreur
scenario_errors() {
    print_header "Scénario 4: Tests d'erreur"
    
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

# Scénario 5: Tests de timeframes
scenario_timeframes() {
    print_header "Scénario 5: Tests de timeframes"
    
    # Test avec différents timeframes
    test_revalidation "Timeframe 1m" "2024-01-15" "BTCUSDT,ETHUSDT" "1m" "Test avec timeframe 1m"
    test_revalidation "Timeframe 15m" "2024-01-15" "BTCUSDT,ETHUSDT" "15m" "Test avec timeframe 15m"
    test_revalidation "Timeframe 30m" "2024-01-15" "BTCUSDT,ETHUSDT" "30m" "Test avec timeframe 30m"
    test_revalidation "Timeframe 1d" "2024-01-15" "BTCUSDT,ETHUSDT" "1d" "Test avec timeframe 1d"
}

# Scénario 6: Tests de contrats spécifiques
scenario_contracts() {
    print_header "Scénario 6: Tests de contrats spécifiques"
    
    # Test avec des contrats spécifiques
    test_revalidation "Contrats crypto majeurs" "2024-01-15" "BTCUSDT,ETHUSDT" "1h" "Test avec les deux principales cryptos"
    test_revalidation "Contrats altcoins" "2024-01-15" "ADAUSDT,DOTUSDT,LINKUSDT" "1h" "Test avec des altcoins"
    test_revalidation "Contrats DeFi" "2024-01-15" "SOLUSDT,MATICUSDT,AVAXUSDT" "1h" "Test avec des tokens DeFi"
}

# Scénario 7: Tests de comparaison
scenario_comparison() {
    print_header "Scénario 7: Tests de comparaison"
    
    # Test avec la même date mais différents timeframes
    local date="2024-01-15"
    local contracts="BTCUSDT,ETHUSDT"
    
    test_revalidation "Comparaison 1h vs 5m" "$date" "$contracts" "1h" "Test de comparaison timeframe 1h"
    test_revalidation "Comparaison 1h vs 5m" "$date" "$contracts" "5m" "Test de comparaison timeframe 5m"
    
    # Test avec la même date mais différents contrats
    test_revalidation "Comparaison contrats" "$date" "BTCUSDT" "1h" "Test avec BTCUSDT uniquement"
    test_revalidation "Comparaison contrats" "$date" "ETHUSDT" "1h" "Test avec ETHUSDT uniquement"
}

# Scénario 8: Tests de stress
scenario_stress() {
    print_header "Scénario 8: Tests de stress"
    
    # Test avec tous les contrats disponibles
    test_revalidation "Stress - Tous les contrats" "2024-01-15" "BTCUSDT,ETHUSDT,ADAUSDT,DOTUSDT,LINKUSDT,SOLUSDT,MATICUSDT,AVAXUSDT" "1h" "Test de stress avec tous les contrats"
    
    # Test avec timeframe le plus complexe
    test_revalidation "Stress - Timeframe 1d" "2024-01-15" "BTCUSDT,ETHUSDT,ADAUSDT,DOTUSDT,LINKUSDT" "1d" "Test de stress avec timeframe 1d"
}

# Fonction principale
main() {
    local scenario="${1:-all}"
    
    print_header "Tests avancés de l'endpoint de revalidation des contrats"
    print_info "URL: $API_BASE$ENDPOINT"
    print_info "Scénario: $scenario"
    
    case "$scenario" in
        "basic")
            scenario_basic
            ;;
        "performance")
            scenario_performance
            ;;
        "dates")
            scenario_dates
            ;;
        "errors")
            scenario_errors
            ;;
        "timeframes")
            scenario_timeframes
            ;;
        "contracts")
            scenario_contracts
            ;;
        "comparison")
            scenario_comparison
            ;;
        "stress")
            scenario_stress
            ;;
        "all")
            scenario_basic
            scenario_performance
            scenario_dates
            scenario_errors
            scenario_timeframes
            scenario_contracts
            scenario_comparison
            scenario_stress
            ;;
        *)
            print_error "Scénario invalide: $scenario"
            print_info "Scénarios disponibles: basic, performance, dates, errors, timeframes, contracts, comparison, stress, all"
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

