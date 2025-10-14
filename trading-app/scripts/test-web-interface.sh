#!/bin/bash

# Script de test pour l'interface web des indicateurs
# Usage: ./scripts/test-web-interface.sh

set -e

# Couleurs pour l'affichage
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
BASE_URL="http://localhost:8000"
INDICATORS_TEST_URL="$BASE_URL/indicators/test"
INDICATORS_EVALUATE_URL="$BASE_URL/indicators/evaluate"
INDICATORS_CONDITIONS_URL="$BASE_URL/indicators/available-conditions"

# Fonction de logging
log() {
    echo -e "${BLUE}[$(date '+%Y-%m-%d %H:%M:%S')]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Fonction pour tester une URL
test_url() {
    local url=$1
    local description=$2
    local expected_status=${3:-200}
    
    log "Test de $description: $url"
    
    local response=$(curl -s -o /dev/null -w "%{http_code}" "$url")
    
    if [ "$response" -eq "$expected_status" ]; then
        log_success "$description: OK (HTTP $response)"
        return 0
    else
        log_error "$description: ÉCHEC (HTTP $response, attendu $expected_status)"
        return 1
    fi
}

# Fonction pour tester l'API JSON
test_api() {
    local url=$1
    local description=$2
    local method=${3:-GET}
    local data=${4:-""}
    
    log "Test API $description: $url"
    
    if [ "$method" = "POST" ] && [ -n "$data" ]; then
        local response=$(curl -s -X POST -H "Content-Type: application/json" -d "$data" "$url")
    else
        local response=$(curl -s "$url")
    fi
    
    # Vérifier si la réponse contient du JSON valide
    if echo "$response" | jq . > /dev/null 2>&1; then
        log_success "$description: JSON valide reçu"
        echo "$response" | jq .
        return 0
    else
        log_error "$description: Réponse JSON invalide"
        echo "Réponse: $response"
        return 1
    fi
}

# Fonction principale
main() {
    log "Démarrage des tests de l'interface web des indicateurs"
    
    local failed_tests=0
    
    # Test 1: Page principale de test des indicateurs
    log "=== TEST 1: Page principale ==="
    if ! test_url "$INDICATORS_TEST_URL" "Page de test des indicateurs"; then
        ((failed_tests++))
    fi
    
    # Test 2: API des conditions disponibles
    log "=== TEST 2: API Conditions disponibles ==="
    if ! test_api "$INDICATORS_CONDITIONS_URL" "Conditions disponibles"; then
        ((failed_tests++))
    fi
    
    # Test 3: API d'évaluation des indicateurs
    log "=== TEST 3: API Évaluation des indicateurs ==="
    local test_data='{
        "symbol": "BTCUSDT",
        "timeframe": "1h"
    }'
    if ! test_api "$INDICATORS_EVALUATE_URL" "Évaluation des indicateurs" "POST" "$test_data"; then
        ((failed_tests++))
    fi
    
    # Test 4: API de replay
    log "=== TEST 4: API Test de replay ==="
    local replay_data='{
        "symbol": "ETHUSDT",
        "timeframe": "4h",
        "iterations": 3
    }'
    if ! test_api "$BASE_URL/indicators/replay" "Test de replay" "POST" "$replay_data"; then
        ((failed_tests++))
    fi
    
    # Test 5: API de détail d'une condition
    log "=== TEST 5: API Détail d'une condition ==="
    if ! test_api "$BASE_URL/indicators/condition/macd_hist_lt_0" "Détail condition MACD"; then
        ((failed_tests++))
    fi
    
    # Résumé final
    log "=== RÉSUMÉ FINAL ==="
    if [ $failed_tests -eq 0 ]; then
        log_success "Tous les tests de l'interface web sont passés avec succès !"
        log_success "L'interface est accessible à: $INDICATORS_TEST_URL"
        exit 0
    else
        log_error "$failed_tests test(s) ont échoué"
        log_warning "Vérifiez que le serveur de développement est démarré:"
        log_warning "  php bin/console server:start"
        exit 1
    fi
}

# Vérifier que curl est disponible
if ! command -v curl &> /dev/null; then
    log_error "curl n'est pas installé ou n'est pas dans le PATH"
    exit 1
fi

# Vérifier que jq est disponible
if ! command -v jq &> /dev/null; then
    log_warning "jq n'est pas installé - les tests JSON seront limités"
fi

# Afficher l'aide si demandé
if [ "$1" = "-h" ] || [ "$1" = "--help" ]; then
    echo "Script de test pour l'interface web des indicateurs"
    echo ""
    echo "Usage: $0 [options]"
    echo ""
    echo "Options:"
    echo "  -h, --help     Afficher cette aide"
    echo ""
    echo "Prérequis:"
    echo "  - Serveur de développement démarré (php bin/console server:start)"
    echo "  - curl installé"
    echo "  - jq installé (optionnel, pour la validation JSON)"
    echo ""
    echo "Exemples:"
    echo "  $0                    # Tester l'interface"
    echo "  $0 --help            # Afficher l'aide"
    exit 0
fi

# Exécuter la fonction principale
main

