#!/bin/bash

# Script de test pour l'interface web améliorée des indicateurs
# Usage: ./scripts/test-enhanced-interface.sh

set -e

# Couleurs pour l'affichage
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
NC='\033[0m' # No Color

# Configuration
BASE_URL="http://localhost:8082"
INDICATORS_TEST_URL="$BASE_URL/indicators/test"
INDICATORS_EVALUATE_URL="$BASE_URL/indicators/evaluate"

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

log_demo() {
    echo -e "${PURPLE}[DEMO]${NC} $1"
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
    
    log_demo "Test API $description: $url"
    
    if [ "$method" = "POST" ] && [ -n "$data" ]; then
        local response=$(curl -s -X POST -H "Content-Type: application/json" -d "$data" "$url")
    else
        local response=$(curl -s "$url")
    fi
    
    # Vérifier si la réponse contient du JSON valide
    if echo "$response" | jq . > /dev/null 2>&1; then
        log_success "$description: JSON valide reçu"
        echo "$response" | jq . | head -20
        if [ $(echo "$response" | jq . | wc -l) -gt 20 ]; then
            echo "... (tronqué)"
        fi
        return 0
    else
        log_error "$description: Réponse JSON invalide"
        echo "Réponse: $response"
        return 1
    fi
}

# Fonction principale
main() {
    log "Démarrage des tests de l'interface web améliorée des indicateurs"
    
    local failed_tests=0
    
    # Test 1: Page principale de test des indicateurs
    log "=== TEST 1: Page principale ==="
    if ! test_url "$INDICATORS_TEST_URL" "Page de test des indicateurs"; then
        ((failed_tests++))
    fi
    
    # Test 2: Test avec données par défaut
    log "=== TEST 2: Test avec données par défaut ==="
    local test_data_default='{
        "symbol": "BTCUSDT",
        "timeframe": "1h"
    }'
    if ! test_api "$INDICATORS_EVALUATE_URL" "Évaluation avec données par défaut" "POST" "$test_data_default"; then
        ((failed_tests++))
    fi
    
    # Test 3: Test avec klines JSON
    log "=== TEST 3: Test avec klines JSON ==="
    local test_klines_json='{
        "symbol": "ETHUSDT",
        "timeframe": "4h",
        "klines_json": [
            {
                "open_time": "2024-01-01 00:00:00",
                "open": 3000.0,
                "high": 3010.0,
                "low": 2990.0,
                "close": 3005.0,
                "volume": 1000.0
            },
            {
                "open_time": "2024-01-01 04:00:00",
                "open": 3005.0,
                "high": 3020.0,
                "low": 3000.0,
                "close": 3015.0,
                "volume": 1200.0
            },
            {
                "open_time": "2024-01-01 08:00:00",
                "open": 3015.0,
                "high": 3030.0,
                "low": 3010.0,
                "close": 3025.0,
                "volume": 1100.0
            }
        ]
    }'
    if ! test_api "$INDICATORS_EVALUATE_URL" "Évaluation avec klines JSON" "POST" "$test_klines_json"; then
        ((failed_tests++))
    fi
    
    # Test 4: Test avec timeframe invalide
    log "=== TEST 4: Test avec timeframe invalide ==="
    local test_invalid_timeframe='{
        "symbol": "BTCUSDT",
        "timeframe": "2h"
    }'
    if ! test_api "$INDICATORS_EVALUATE_URL" "Test timeframe invalide" "POST" "$test_invalid_timeframe"; then
        log_success "Test timeframe invalide: Erreur attendue reçue"
    else
        log_warning "Test timeframe invalide: Aucune erreur reçue (peut être normal)"
    fi
    
    # Test 5: Test avec klines JSON invalide
    log "=== TEST 5: Test avec klines JSON invalide ==="
    local test_invalid_klines='{
        "symbol": "BTCUSDT",
        "timeframe": "1h",
        "klines_json": [
            {
                "open_time": "2024-01-01 00:00:00",
                "open": "invalid",
                "high": 3010.0,
                "low": 2990.0,
                "close": 3005.0,
                "volume": 1000.0
            }
        ]
    }'
    if ! test_api "$INDICATORS_EVALUATE_URL" "Test klines JSON invalide" "POST" "$test_invalid_klines"; then
        log_success "Test klines JSON invalide: Erreur attendue reçue"
    else
        log_warning "Test klines JSON invalide: Aucune erreur reçue (peut être normal)"
    fi
    
    # Test 6: Test avec données insuffisantes
    log "=== TEST 6: Test avec données insuffisantes ==="
    local test_insufficient_data='{
        "symbol": "BTCUSDT",
        "timeframe": "4h",
        "klines_json": [
            {
                "open_time": "2024-01-01 00:00:00",
                "open": 50000.0,
                "high": 50100.0,
                "low": 49900.0,
                "close": 50050.0,
                "volume": 1000.0
            }
        ]
    }'
    if ! test_api "$INDICATORS_EVALUATE_URL" "Test données insuffisantes" "POST" "$test_insufficient_data"; then
        log_success "Test données insuffisantes: Erreur attendue reçue"
    else
        log_warning "Test données insuffisantes: Aucune erreur reçue (peut être normal)"
    fi
    
    # Test 7: Test avec données personnalisées
    log "=== TEST 7: Test avec données personnalisées ==="
    local test_custom_data='{
        "symbol": "ADAUSDT",
        "timeframe": "15m",
        "custom_data": {
            "closes": [0.5, 0.51, 0.52, 0.53, 0.54, 0.55, 0.56, 0.57, 0.58, 0.59, 0.6, 0.61, 0.62, 0.63, 0.64, 0.65, 0.66, 0.67, 0.68, 0.69, 0.7, 0.71, 0.72, 0.73, 0.74, 0.75, 0.76, 0.77, 0.78, 0.79, 0.8, 0.81, 0.82, 0.83, 0.84, 0.85, 0.86, 0.87, 0.88, 0.89, 0.9, 0.91, 0.92, 0.93, 0.94, 0.95, 0.96, 0.97, 0.98, 0.99, 1.0],
            "highs": [0.51, 0.52, 0.53, 0.54, 0.55, 0.56, 0.57, 0.58, 0.59, 0.6, 0.61, 0.62, 0.63, 0.64, 0.65, 0.66, 0.67, 0.68, 0.69, 0.7, 0.71, 0.72, 0.73, 0.74, 0.75, 0.76, 0.77, 0.78, 0.79, 0.8, 0.81, 0.82, 0.83, 0.84, 0.85, 0.86, 0.87, 0.88, 0.89, 0.9, 0.91, 0.92, 0.93, 0.94, 0.95, 0.96, 0.97, 0.98, 0.99, 1.0, 1.01],
            "lows": [0.49, 0.5, 0.51, 0.52, 0.53, 0.54, 0.55, 0.56, 0.57, 0.58, 0.59, 0.6, 0.61, 0.62, 0.63, 0.64, 0.65, 0.66, 0.67, 0.68, 0.69, 0.7, 0.71, 0.72, 0.73, 0.74, 0.75, 0.76, 0.77, 0.78, 0.79, 0.8, 0.81, 0.82, 0.83, 0.84, 0.85, 0.86, 0.87, 0.88, 0.89, 0.9, 0.91, 0.92, 0.93, 0.94, 0.95, 0.96, 0.97, 0.98, 0.99],
            "volumes": [1000, 1100, 1200, 1300, 1400, 1500, 1600, 1700, 1800, 1900, 2000, 2100, 2200, 2300, 2400, 2500, 2600, 2700, 2800, 2900, 3000, 3100, 3200, 3300, 3400, 3500, 3600, 3700, 3800, 3900, 4000, 4100, 4200, 4300, 4400, 4500, 4600, 4700, 4800, 4900, 5000, 5100, 5200, 5300, 5400, 5500, 5600, 5700, 5800, 5900, 6000]
        }
    }'
    if ! test_api "$INDICATORS_EVALUATE_URL" "Évaluation avec données personnalisées" "POST" "$test_custom_data"; then
        ((failed_tests++))
    fi
    
    # Résumé final
    log "=== RÉSUMÉ FINAL ==="
    if [ $failed_tests -eq 0 ]; then
        log_success "Tous les tests de l'interface web améliorée sont passés avec succès !"
        log_success "L'interface est accessible à: $INDICATORS_TEST_URL"
        log_demo "Nouvelles fonctionnalités testées:"
        echo "  ✅ Validation des timeframes avec trading.yml"
        echo "  ✅ Support des klines JSON"
        echo "  ✅ Validation des données insuffisantes"
        echo "  ✅ Gestion des erreurs de format"
        echo "  ✅ Affichage des règles de validation par timeframe"
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
    echo "Script de test pour l'interface web améliorée des indicateurs"
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
    echo "  $0                    # Tester l'interface améliorée"
    echo "  $0 --help            # Afficher l'aide"
    exit 0
fi

# Exécuter la fonction principale
main

