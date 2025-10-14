#!/bin/bash

# Script de démonstration de l'interface web des indicateurs
# Usage: ./scripts/demo-web-interface.sh

set -e

# Couleurs pour l'affichage
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
NC='\033[0m' # No Color

# Configuration
BASE_URL="http://localhost:8000"
INDICATORS_TEST_URL="$BASE_URL/indicators/test"

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

# Fonction pour afficher l'en-tête
show_header() {
    clear
    echo -e "${BLUE}"
    echo "╔══════════════════════════════════════════════════════════════════════════════╗"
    echo "║                    🎯 DÉMONSTRATION INTERFACE WEB INDICATEURS                ║"
    echo "║                                                                              ║"
    echo "║  Interface de test et validation des indicateurs de trading                 ║"
    echo "║                                                                              ║"
    echo "╚══════════════════════════════════════════════════════════════════════════════╝"
    echo -e "${NC}"
}

# Fonction pour afficher les étapes
show_step() {
    local step=$1
    local description=$2
    
    echo -e "\n${PURPLE}=== ÉTAPE $step: $description ===${NC}"
}

# Fonction pour tester l'API
test_api_demo() {
    local url=$1
    local description=$2
    local method=${3:-GET}
    local data=${4:-""}
    
    log_demo "Test de $description"
    echo "URL: $url"
    echo "Méthode: $method"
    
    if [ "$method" = "POST" ] && [ -n "$data" ]; then
        echo "Données: $data"
        local response=$(curl -s -X POST -H "Content-Type: application/json" -d "$data" "$url")
    else
        local response=$(curl -s "$url")
    fi
    
    if echo "$response" | jq . > /dev/null 2>&1; then
        log_success "Réponse JSON valide reçue"
        echo "Résultat:"
        echo "$response" | jq . | head -20
        if [ $(echo "$response" | jq . | wc -l) -gt 20 ]; then
            echo "... (tronqué)"
        fi
    else
        log_error "Réponse JSON invalide"
        echo "Réponse brute: $response"
    fi
    
    echo ""
    read -p "Appuyez sur Entrée pour continuer..."
}

# Fonction principale
main() {
    show_header
    
    log "Démarrage de la démonstration de l'interface web des indicateurs"
    echo ""
    
    # Vérifier que le serveur est démarré
    log "Vérification du serveur de développement..."
    if ! curl -s "$BASE_URL" > /dev/null; then
        log_error "Le serveur de développement n'est pas démarré"
        log_warning "Démarrez le serveur avec: php bin/console server:start"
        exit 1
    fi
    log_success "Serveur de développement accessible"
    
    # Étape 1: Accès à l'interface
    show_step 1 "Accès à l'interface web"
    log_demo "L'interface est accessible à: $INDICATORS_TEST_URL"
    log_demo "Ou via le menu: Outils > Test Indicateurs"
    echo ""
    read -p "Appuyez sur Entrée pour continuer..."
    
    # Étape 2: Test des conditions disponibles
    show_step 2 "Test des conditions disponibles"
    test_api_demo "$BASE_URL/indicators/available-conditions" "Liste des conditions disponibles"
    
    # Étape 3: Test d'évaluation avec données par défaut
    show_step 3 "Test d'évaluation avec données par défaut"
    local test_data_default='{
        "symbol": "BTCUSDT",
        "timeframe": "1h"
    }'
    test_api_demo "$BASE_URL/indicators/evaluate" "Évaluation avec données par défaut" "POST" "$test_data_default"
    
    # Étape 4: Test d'évaluation avec données personnalisées
    show_step 4 "Test d'évaluation avec données personnalisées"
    local test_data_custom='{
        "symbol": "ETHUSDT",
        "timeframe": "4h",
        "custom_data": {
            "closes": [3000, 3010, 3020, 3030, 3040, 3050, 3060, 3070, 3080, 3090, 3100, 3110, 3120, 3130, 3140, 3150, 3160, 3170, 3180, 3190, 3200, 3210, 3220, 3230, 3240, 3250, 3260, 3270, 3280, 3290, 3300, 3310, 3320, 3330, 3340, 3350, 3360, 3370, 3380, 3390, 3400, 3410, 3420, 3430, 3440, 3450, 3460, 3470, 3480, 3490, 3500],
            "highs": [3010, 3020, 3030, 3040, 3050, 3060, 3070, 3080, 3090, 3100, 3110, 3120, 3130, 3140, 3150, 3160, 3170, 3180, 3190, 3200, 3210, 3220, 3230, 3240, 3250, 3260, 3270, 3280, 3290, 3300, 3310, 3320, 3330, 3340, 3350, 3360, 3370, 3380, 3390, 3400, 3410, 3420, 3430, 3440, 3450, 3460, 3470, 3480, 3490, 3500, 3510],
            "lows": [2990, 3000, 3010, 3020, 3030, 3040, 3050, 3060, 3070, 3080, 3090, 3100, 3110, 3120, 3130, 3140, 3150, 3160, 3170, 3180, 3190, 3200, 3210, 3220, 3230, 3240, 3250, 3260, 3270, 3280, 3290, 3300, 3310, 3320, 3330, 3340, 3350, 3360, 3370, 3380, 3390, 3400, 3410, 3420, 3430, 3440, 3450, 3460, 3470, 3480, 3490],
            "volumes": [500, 550, 600, 650, 700, 750, 800, 850, 900, 950, 1000, 1050, 1100, 1150, 1200, 1250, 1300, 1350, 1400, 1450, 1500, 1550, 1600, 1650, 1700, 1750, 1800, 1850, 1900, 1950, 2000, 2050, 2100, 2150, 2200, 2250, 2300, 2350, 2400, 2450, 2500, 2550, 2600, 2650, 2700, 2750, 2800, 2850, 2900, 2950, 3000]
        }
    }'
    test_api_demo "$BASE_URL/indicators/evaluate" "Évaluation avec données personnalisées" "POST" "$test_data_custom"
    
    # Étape 5: Test de replay
    show_step 5 "Test de replay (stabilité)"
    local replay_data='{
        "symbol": "ADAUSDT",
        "timeframe": "1d",
        "iterations": 5
    }'
    test_api_demo "$BASE_URL/indicators/replay" "Test de replay pour la stabilité" "POST" "$replay_data"
    
    # Étape 6: Test d'une condition spécifique
    show_step 6 "Test d'une condition spécifique"
    test_api_demo "$BASE_URL/indicators/condition/rsi_lt_70" "Détail de la condition RSI < 70"
    
    # Résumé final
    show_step 7 "Résumé et prochaines étapes"
    log_success "Démonstration terminée avec succès !"
    echo ""
    log_demo "🎯 Fonctionnalités démontrées:"
    echo "  ✅ Accès à l'interface web"
    echo "  ✅ Liste des conditions disponibles"
    echo "  ✅ Évaluation avec données par défaut"
    echo "  ✅ Évaluation avec données personnalisées"
    echo "  ✅ Test de replay pour la stabilité"
    echo "  ✅ Détail d'une condition spécifique"
    echo ""
    log_demo "🚀 Prochaines étapes:"
    echo "  1. Ouvrez votre navigateur sur: $INDICATORS_TEST_URL"
    echo "  2. Testez l'interface interactive"
    echo "  3. Explorez les différentes fonctionnalités"
    echo "  4. Consultez la documentation: docs/WEB_INTERFACE_GUIDE.md"
    echo ""
    log_demo "📚 Ressources:"
    echo "  - Guide d'utilisation: docs/WEB_INTERFACE_GUIDE.md"
    echo "  - Tests automatisés: scripts/test-indicators.sh"
    echo "  - Tests web: scripts/test-web-interface.sh"
    echo ""
    log_success "Merci d'avoir suivi cette démonstration !"
}

# Vérifier les prérequis
check_prerequisites() {
    local missing=0
    
    if ! command -v curl &> /dev/null; then
        log_error "curl n'est pas installé"
        ((missing++))
    fi
    
    if ! command -v jq &> /dev/null; then
        log_warning "jq n'est pas installé - la démonstration sera limitée"
    fi
    
    if [ $missing -gt 0 ]; then
        log_error "Prérequis manquants. Veuillez les installer avant de continuer."
        exit 1
    fi
}

# Afficher l'aide
show_help() {
    echo "Script de démonstration de l'interface web des indicateurs"
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
    echo "  $0                    # Lancer la démonstration"
    echo "  $0 --help            # Afficher l'aide"
    exit 0
}

# Traitement des arguments
if [ "$1" = "-h" ] || [ "$1" = "--help" ]; then
    show_help
fi

# Vérifier les prérequis
check_prerequisites

# Exécuter la démonstration
main

