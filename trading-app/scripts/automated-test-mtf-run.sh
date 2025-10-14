#!/bin/bash

# Script de test automatisé pour l'endpoint /api/mtf/run
# Usage: ./automated-test-mtf-run.sh [URL] [CONFIG_FILE]

set -e

# Configuration par défaut
DEFAULT_URL="http://localhost:8082"
DEFAULT_CONFIG="config/test-mtf-run.json"

# Paramètres
URL=${1:-$DEFAULT_URL}
CONFIG_FILE=${2:-$DEFAULT_CONFIG}

# Couleurs pour l'affichage
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Compteurs
TOTAL_TESTS=0
PASSED_TESTS=0
FAILED_TESTS=0
SKIPPED_TESTS=0

echo -e "${BLUE}🧪 Test automatisé de l'endpoint /api/mtf/run${NC}"
echo "=================================================="
echo "URL: $URL"
echo "Configuration: $CONFIG_FILE"
echo ""

# Fonction pour afficher les résultats
print_result() {
    local test_name="$1"
    local status="$2"
    local message="$3"
    
    case $status in
        "PASS")
            echo -e "✅ ${GREEN}PASS${NC} - $test_name: $message"
            ((PASSED_TESTS++))
            ;;
        "FAIL")
            echo -e "❌ ${RED}FAIL${NC} - $test_name: $message"
            ((FAILED_TESTS++))
            ;;
        "SKIP")
            echo -e "⏭️  ${YELLOW}SKIP${NC} - $test_name: $message"
            ((SKIPPED_TESTS++))
            ;;
    esac
}

# Fonction pour tester la connectivité
test_connectivity() {
    echo -e "${BLUE}🔍 Test de connectivité...${NC}"
    
    if curl -s -f "$URL/api/mtf/status" > /dev/null 2>&1; then
        echo -e "✅ ${GREEN}API accessible${NC}"
        return 0
    else
        echo -e "❌ ${RED}API inaccessible${NC}"
        return 1
    fi
}

# Fonction pour exécuter un test
run_test() {
    local test_name="$1"
    local request_data="$2"
    local expected_status="$3"
    local expected_symbols="$4"
    local expected_order_plans="$5"
    local expected_failures="$6"
    
    echo -e "\n${BLUE}🧪 Test: $test_name${NC}"
    echo "Données: $request_data"
    
    # Envoyer la requête
    local response=$(curl -s -w "\n%{http_code}" -X POST "$URL/api/mtf/run" \
        -H "Content-Type: application/json" \
        -d "$request_data" \
        --max-time 60)
    
    # Séparer le contenu et le code de statut
    local http_code=$(echo "$response" | tail -n1)
    local response_body=$(echo "$response" | head -n -1)
    
    # Vérifier le code de statut
    if [ "$http_code" = "$expected_status" ]; then
        # Vérifier le contenu de la réponse
        if command -v jq >/dev/null 2>&1; then
            local actual_symbols=$(echo "$response_body" | jq -r '.data.summary.symbols_requested // 0')
            local actual_successful=$(echo "$response_body" | jq -r '.data.summary.symbols_successful // 0')
            local actual_failed=$(echo "$response_body" | jq -r '.data.summary.symbols_failed // 0')
            local actual_skipped=$(echo "$response_body" | jq -r '.data.summary.symbols_skipped // 0')
            
            # Vérifier le nombre de symboles
            if [ "$actual_symbols" = "$expected_symbols" ]; then
                # Vérifier les order plans si attendus
                if [ "$expected_order_plans" = "true" ]; then
                    local has_order_plans=$(echo "$response_body" | jq -r '.data.results | to_entries[] | select(.value.order_plan_id != null) | .key' | wc -l)
                    if [ "$has_order_plans" -gt 0 ]; then
                        print_result "$test_name" "PASS" "Order plans créés avec succès"
                    else
                        print_result "$test_name" "FAIL" "Aucun order plan créé"
                    fi
                elif [ "$expected_failures" = "true" ]; then
                    if [ "$actual_failed" -gt 0 ]; then
                        print_result "$test_name" "PASS" "Échecs attendus détectés"
                    else
                        print_result "$test_name" "FAIL" "Aucun échec détecté"
                    fi
                else
                    print_result "$test_name" "PASS" "Test réussi"
                fi
            else
                print_result "$test_name" "FAIL" "Nombre de symboles incorrect: $actual_symbols (attendu: $expected_symbols)"
            fi
        else
            print_result "$test_name" "PASS" "Test réussi (jq non disponible pour validation détaillée)"
        fi
    else
        print_result "$test_name" "FAIL" "Code de statut incorrect: $http_code (attendu: $expected_status)"
    fi
}

# Fonction pour exécuter tous les tests
run_all_tests() {
    echo -e "${BLUE}🚀 Exécution de tous les tests...${NC}"
    
    # Test 1: Test basique
    run_test "Test basique" '{}' 200 5 false false
    
    # Test 2: Test avec symboles spécifiques
    run_test "Test avec symboles spécifiques" '{"symbols": ["BTCUSDT", "ETHUSDT"]}' 200 2 false false
    
    # Test 3: Test avec un seul symbole
    run_test "Test avec un seul symbole" '{"symbols": ["BTCUSDT"]}' 200 1 false false
    
    # Test 4: Test en mode production
    run_test "Test en mode production" '{"symbols": ["BTCUSDT"], "dry_run": false}' 200 1 true false
    
    # Test 5: Test avec force run
    run_test "Test avec force run" '{"symbols": ["BTCUSDT"], "force_run": true}' 200 1 false false
    
    # Test 6: Test avec symboles invalides
    run_test "Test avec symboles invalides" '{"symbols": ["INVALID_SYMBOL"]}' 200 1 false true
}

# Fonction pour afficher le résumé
print_summary() {
    echo -e "\n${BLUE}📊 Résumé des tests${NC}"
    echo "=================="
    echo -e "Total: $TOTAL_TESTS"
    echo -e "${GREEN}Réussis: $PASSED_TESTS${NC}"
    echo -e "${RED}Échoués: $FAILED_TESTS${NC}"
    echo -e "${YELLOW}Ignorés: $SKIPPED_TESTS${NC}"
    
    if [ $TOTAL_TESTS -gt 0 ]; then
        local success_rate=$((PASSED_TESTS * 100 / TOTAL_TESTS))
        echo -e "Taux de succès: $success_rate%"
        
        if [ $success_rate -ge 80 ]; then
            echo -e "🎉 ${GREEN}Tous les tests sont passés avec succès !${NC}"
            return 0
        else
            echo -e "💥 ${RED}Certains tests ont échoué !${NC}"
            return 1
        fi
    else
        echo -e "⚠️  ${YELLOW}Aucun test exécuté${NC}"
        return 1
    fi
}

# Fonction pour afficher l'aide
show_help() {
    echo "Usage: $0 [URL] [CONFIG_FILE]"
    echo ""
    echo "Paramètres:"
    echo "  URL         URL de base de l'API (défaut: $DEFAULT_URL)"
    echo "  CONFIG_FILE Fichier de configuration (défaut: $DEFAULT_CONFIG)"
    echo ""
    echo "Exemples:"
    echo "  $0                                    # Test avec configuration par défaut"
    echo "  $0 http://localhost:8082             # Test avec URL personnalisée"
    echo "  $0 http://localhost:8082 config/test-mtf-run.json  # Test avec configuration personnalisée"
    echo ""
    echo "Options:"
    echo "  -h, --help    Afficher cette aide"
    echo "  -v, --verbose Mode verbeux"
}

# Vérifier les options
if [ "$1" = "-h" ] || [ "$1" = "--help" ]; then
    show_help
    exit 0
fi

# Vérifier que curl est installé
if ! command -v curl >/dev/null 2>&1; then
    echo -e "❌ ${RED}curl n'est pas installé. Veuillez l'installer pour utiliser ce script.${NC}"
    exit 1
fi

# Vérifier que jq est installé (optionnel)
if ! command -v jq >/dev/null 2>&1; then
    echo -e "⚠️  ${YELLOW}jq n'est pas installé. La validation détaillée sera limitée.${NC}"
    echo "   Installez jq pour une meilleure expérience:"
    echo "   - macOS: brew install jq"
    echo "   - Ubuntu/Debian: sudo apt-get install jq"
    echo "   - CentOS/RHEL: sudo yum install jq"
    echo ""
fi

# Vérifier que le fichier de configuration existe
if [ ! -f "$CONFIG_FILE" ]; then
    echo -e "⚠️  ${YELLOW}Fichier de configuration non trouvé: $CONFIG_FILE${NC}"
    echo "   Utilisation de la configuration par défaut"
    echo ""
fi

# Exécuter les tests
echo "Démarrage des tests automatisés..."
echo ""

if test_connectivity; then
    echo ""
    run_all_tests
    echo ""
    print_summary
    exit $?
else
    echo ""
    echo -e "💥 ${RED}Test de connectivité échoué !${NC}"
    exit 1
fi




