#!/bin/bash

# Script de démarrage du système MTF
# Usage: ./scripts/start-mtf-system.sh [options]

set -e

# Couleurs pour les messages
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Fonction pour afficher les messages
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
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

# Fonction d'aide
show_help() {
    echo "Usage: $0 [OPTIONS]"
    echo ""
    echo "Options:"
    echo "  -h, --help              Afficher cette aide"
    echo "  -w, --worker            Démarrer seulement le worker"
    echo "  -f, --workflow          Démarrer seulement le workflow"
    echo "  -a, --all               Démarrer le système complet (défaut)"
    echo "  -d, --daemon            Démarrer en mode daemon"
    echo "  -v, --verbose           Mode verbeux"
    echo "  -c, --check             Vérifier la configuration"
    echo "  -s, --status            Afficher le statut"
    echo "  -t, --test              Tester la connectivité"
    echo ""
    echo "Exemples:"
    echo "  $0                      # Démarrer le système complet"
    echo "  $0 --worker --daemon    # Démarrer le worker en daemon"
    echo "  $0 --check              # Vérifier la configuration"
    echo "  $0 --status             # Afficher le statut"
}

# Variables par défaut
START_WORKER=false
START_WORKFLOW=false
START_ALL=true
DAEMON_MODE=false
VERBOSE=false
CHECK_CONFIG=false
SHOW_STATUS=false
TEST_CONNECTIVITY=false

# Parse des arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -h|--help)
            show_help
            exit 0
            ;;
        -w|--worker)
            START_WORKER=true
            START_ALL=false
            shift
            ;;
        -f|--workflow)
            START_WORKFLOW=true
            START_ALL=false
            shift
            ;;
        -a|--all)
            START_ALL=true
            shift
            ;;
        -d|--daemon)
            DAEMON_MODE=true
            shift
            ;;
        -v|--verbose)
            VERBOSE=true
            shift
            ;;
        -c|--check)
            CHECK_CONFIG=true
            shift
            ;;
        -s|--status)
            SHOW_STATUS=true
            shift
            ;;
        -t|--test)
            TEST_CONNECTIVITY=true
            shift
            ;;
        *)
            log_error "Option inconnue: $1"
            show_help
            exit 1
            ;;
    esac
done

# Vérifier que nous sommes dans le bon répertoire
if [ ! -f "composer.json" ] || [ ! -d "src" ]; then
    log_error "Ce script doit être exécuté depuis la racine du projet trading-app"
    exit 1
fi

# Fonction pour vérifier la configuration
check_config() {
    log_info "Vérification de la configuration..."
    
    # Vérifier les variables d'environnement
    local required_vars=(
        "TEMPORAL_ADDRESS"
        "BITMART_API_KEY"
        "BITMART_SECRET_KEY"
        "DATABASE_URL"
    )
    
    local missing_vars=()
    for var in "${required_vars[@]}"; do
        if [ -z "${!var}" ]; then
            missing_vars+=("$var")
        fi
    done
    
    if [ ${#missing_vars[@]} -gt 0 ]; then
        log_error "Variables d'environnement manquantes:"
        for var in "${missing_vars[@]}"; do
            echo "  - $var"
        done
        return 1
    fi
    
    # Vérifier la base de données
    log_info "Vérification de la base de données..."
    if ! php bin/console doctrine:database:create --if-not-exists > /dev/null 2>&1; then
        log_error "Impossible de créer/vérifier la base de données"
        return 1
    fi
    
    # Vérifier les migrations
    log_info "Vérification des migrations..."
    if ! php bin/console doctrine:migrations:status > /dev/null 2>&1; then
        log_error "Problème avec les migrations"
        return 1
    fi
    
    log_success "Configuration OK"
    return 0
}

# Fonction pour tester la connectivité
test_connectivity() {
    log_info "Test de connectivité..."
    
    # Test Temporal
    log_info "Test de connectivité Temporal..."
    if ! php bin/console mtf:workflow status > /dev/null 2>&1; then
        log_warning "Impossible de se connecter à Temporal"
    else
        log_success "Temporal: OK"
    fi
    
    # Test BitMart
    log_info "Test de connectivité BitMart..."
    if ! php bin/console app:test-bitmart > /dev/null 2>&1; then
        log_warning "Impossible de se connecter à BitMart"
    else
        log_success "BitMart: OK"
    fi
    
    # Test base de données
    log_info "Test de connectivité base de données..."
    if ! php bin/console doctrine:query:sql "SELECT 1" > /dev/null 2>&1; then
        log_error "Impossible de se connecter à la base de données"
        return 1
    else
        log_success "Base de données: OK"
    fi
}

# Fonction pour afficher le statut
show_status() {
    log_info "Statut du système MTF..."
    
    # Statut du workflow
    log_info "Statut du workflow:"
    php bin/console mtf:workflow status
    
    # Statut des kill switches
    log_info "Kill switches:"
    curl -s http://localhost:8082/api/mtf/switches | jq '.data[] | {key: .key, is_on: .is_on}' 2>/dev/null || echo "API non disponible"
    
    # Statut des états MTF
    log_info "États MTF:"
    curl -s http://localhost:8082/api/mtf/states | jq '.data[] | {symbol: .symbol, k4h_time: .k4h_time, k1h_time: .k1h_time}' 2>/dev/null || echo "API non disponible"
}

# Fonction pour démarrer le worker
start_worker() {
    log_info "Démarrage du worker MTF..."
    
    local cmd="php bin/console mtf:worker"
    if [ "$DAEMON_MODE" = true ]; then
        cmd="$cmd --daemon"
    fi
    if [ "$VERBOSE" = true ]; then
        cmd="$cmd --verbose"
    fi
    
    if [ "$DAEMON_MODE" = true ]; then
        log_info "Démarrage en mode daemon..."
        nohup $cmd > var/log/mtf-worker.log 2>&1 &
        echo $! > var/run/mtf-worker.pid
        log_success "Worker démarré en arrière-plan (PID: $(cat var/run/mtf-worker.pid))"
    else
        log_info "Démarrage en mode interactif..."
        $cmd
    fi
}

# Fonction pour démarrer le workflow
start_workflow() {
    log_info "Démarrage du workflow MTF..."
    
    local cmd="php bin/console mtf:workflow start"
    if [ "$VERBOSE" = true ]; then
        cmd="$cmd --verbose"
    fi
    
    $cmd
    log_success "Workflow démarré"
}

# Fonction pour démarrer le système complet
start_all() {
    log_info "Démarrage du système MTF complet..."
    
    # Créer les répertoires nécessaires
    mkdir -p var/log var/run
    
    # Démarrer le worker en arrière-plan
    start_worker
    
    # Attendre un peu que le worker démarre
    sleep 2
    
    # Démarrer le workflow
    start_workflow
    
    log_success "Système MTF démarré avec succès"
    log_info "Worker PID: $(cat var/run/mtf-worker.pid 2>/dev/null || echo 'N/A')"
    log_info "Logs: tail -f var/log/mtf-worker.log"
    log_info "Statut: php bin/console mtf:workflow status"
}

# Fonction pour arrêter le système
stop_system() {
    log_info "Arrêt du système MTF..."
    
    # Arrêter le workflow
    php bin/console mtf:workflow stop 2>/dev/null || true
    
    # Arrêter le worker
    if [ -f var/run/mtf-worker.pid ]; then
        local pid=$(cat var/run/mtf-worker.pid)
        if kill -0 $pid 2>/dev/null; then
            kill $pid
            log_success "Worker arrêté (PID: $pid)"
        fi
        rm -f var/run/mtf-worker.pid
    fi
    
    log_success "Système MTF arrêté"
}

# Gestion des signaux
trap 'log_info "Signal reçu, arrêt du système..."; stop_system; exit 0' INT TERM

# Exécution principale
main() {
    log_info "Système MTF Trading - Script de démarrage"
    
    if [ "$CHECK_CONFIG" = true ]; then
        check_config
        exit $?
    fi
    
    if [ "$TEST_CONNECTIVITY" = true ]; then
        test_connectivity
        exit $?
    fi
    
    if [ "$SHOW_STATUS" = true ]; then
        show_status
        exit 0
    fi
    
    # Vérifier la configuration avant de démarrer
    if ! check_config; then
        log_error "Configuration invalide, arrêt"
        exit 1
    fi
    
    # Exécuter les migrations si nécessaire
    log_info "Vérification des migrations..."
    php bin/console doctrine:migrations:migrate --no-interaction
    
    # Démarrer selon les options
    if [ "$START_ALL" = true ]; then
        start_all
    elif [ "$START_WORKER" = true ]; then
        start_worker
    elif [ "$START_WORKFLOW" = true ]; then
        start_workflow
    fi
    
    # Attendre si en mode interactif
    if [ "$DAEMON_MODE" = false ] && [ "$START_ALL" = true ]; then
        log_info "Système en cours d'exécution. Appuyez sur Ctrl+C pour arrêter."
        wait
    fi
}

# Exécuter la fonction principale
main "$@"




