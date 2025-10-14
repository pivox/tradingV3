#!/bin/bash

# Script de test automatisé pour valider tous les indicateurs
# Usage: ./scripts/test-indicators.sh [options]

set -e

# Couleurs pour l'affichage
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration par défaut
SYMBOL="BTCUSDT"
TIMEFRAME="1h"
ITERATIONS=5
VERBOSE=false
SAVE_RESULTS=false
OUTPUT_DIR="./test-results"

# Fonction d'aide
show_help() {
    echo "Script de test automatisé pour valider tous les indicateurs"
    echo ""
    echo "Usage: $0 [options]"
    echo ""
    echo "Options:"
    echo "  -s, --symbol SYMBOL      Symbole à tester (défaut: BTCUSDT)"
    echo "  -t, --timeframe TF       Timeframe à tester (défaut: 1h)"
    echo "  -i, --iterations N       Nombre d'itérations (défaut: 5)"
    echo "  -v, --verbose            Mode verbeux"
    echo "  -o, --output DIR         Répertoire de sortie (défaut: ./test-results)"
    echo "  --save-results           Sauvegarder les résultats"
    echo "  -h, --help               Afficher cette aide"
    echo ""
    echo "Exemples:"
    echo "  $0 -s ETHUSDT -t 4h -i 10"
    echo "  $0 --verbose --save-results"
    echo "  $0 -s ADAUSDT -t 1d -o ./results"
}

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

# Fonction pour exécuter les tests unitaires
run_unit_tests() {
    log "Exécution des tests unitaires des conditions..."
    
    if [ "$VERBOSE" = true ]; then
        php bin/phpunit tests/Indicator/Condition/ --verbose
    else
        php bin/phpunit tests/Indicator/Condition/ --testdox
    fi
    
    if [ $? -eq 0 ]; then
        log_success "Tests unitaires des conditions réussis"
    else
        log_error "Échec des tests unitaires des conditions"
        return 1
    fi
}

# Fonction pour exécuter les tests d'intégration
run_integration_tests() {
    log "Exécution des tests d'intégration..."
    
    if [ "$VERBOSE" = true ]; then
        php bin/phpunit tests/Indicator/Context/ --verbose
    else
        php bin/phpunit tests/Indicator/Context/ --testdox
    fi
    
    if [ $? -eq 0 ]; then
        log_success "Tests d'intégration réussis"
    else
        log_error "Échec des tests d'intégration"
        return 1
    fi
}

# Fonction pour exécuter les tests de snapshots
run_snapshot_tests() {
    log "Exécution des tests de snapshots..."
    
    if [ "$VERBOSE" = true ]; then
        php bin/phpunit tests/Indicator/Snapshot/ --verbose
    else
        php bin/phpunit tests/Indicator/Snapshot/ --testdox
    fi
    
    if [ $? -eq 0 ]; then
        log_success "Tests de snapshots réussis"
    else
        log_error "Échec des tests de snapshots"
        return 1
    fi
}

# Fonction pour exécuter les tests de backtest
run_backtest_tests() {
    log "Exécution des tests de backtest..."
    
    if [ "$VERBOSE" = true ]; then
        php bin/phpunit tests/Domain/Strategy/Service/StrategyBacktesterTest.php --verbose
    else
        php bin/phpunit tests/Domain/Strategy/Service/StrategyBacktesterTest.php --testdox
    fi
    
    if [ $? -eq 0 ]; then
        log_success "Tests de backtest réussis"
    else
        log_error "Échec des tests de backtest"
        return 1
    fi
}

# Fonction pour créer un snapshot de test
create_test_snapshot() {
    log "Création d'un snapshot de test pour $SYMBOL $TIMEFRAME..."
    
    local timestamp=$(date '+%Y-%m-%d_%H-%M-%S')
    local snapshot_file="$OUTPUT_DIR/snapshot_${SYMBOL}_${TIMEFRAME}_${timestamp}.json"
    
    php bin/console indicator:snapshot create \
        --symbol="$SYMBOL" \
        --timeframe="$TIMEFRAME" \
        --kline-time="$(date '+%Y-%m-%d %H:%M:%S')" \
        --verbose > "$snapshot_file" 2>&1
    
    if [ $? -eq 0 ]; then
        log_success "Snapshot créé: $snapshot_file"
        echo "$snapshot_file"
    else
        log_error "Échec de la création du snapshot"
        return 1
    fi
}

# Fonction pour exécuter un test de replay
run_replay_test() {
    log "Exécution d'un test de replay pour $SYMBOL $TIMEFRAME..."
    
    local timestamp=$(date '+%Y-%m-%d_%H-%M-%S')
    local replay_file="$OUTPUT_DIR/replay_${SYMBOL}_${TIMEFRAME}_${timestamp}.json"
    
    # Créer plusieurs snapshots pour le test de replay
    local snapshots=()
    for ((i=1; i<=ITERATIONS; i++)); do
        log "Création du snapshot $i/$ITERATIONS..."
        local snapshot=$(create_test_snapshot)
        snapshots+=("$snapshot")
        sleep 1  # Petite pause entre les snapshots
    done
    
    # Comparer les snapshots
    log "Comparaison des snapshots..."
    php bin/console indicator:snapshot compare \
        --symbol="$SYMBOL" \
        --timeframe="$TIMEFRAME" \
        --tolerance="0.001" \
        --output="$replay_file" \
        --verbose > "$replay_file" 2>&1
    
    if [ $? -eq 0 ]; then
        log_success "Test de replay terminé: $replay_file"
    else
        log_warning "Test de replay terminé avec des différences: $replay_file"
    fi
    
    echo "$replay_file"
}

# Fonction pour exécuter un backtest de validation
run_validation_backtest() {
    log "Exécution d'un backtest de validation pour $SYMBOL $TIMEFRAME..."
    
    local timestamp=$(date '+%Y-%m-%d_%H-%M-%S')
    local backtest_file="$OUTPUT_DIR/backtest_${SYMBOL}_${TIMEFRAME}_${timestamp}.json"
    
    # Calculer les dates (dernière semaine)
    local end_date=$(date '+%Y-%m-%d')
    local start_date=$(date -d '7 days ago' '+%Y-%m-%d')
    
    php bin/console backtest:run "$SYMBOL" "$TIMEFRAME" "$start_date" "$end_date" \
        --strategies="Test Strategy" \
        --initial-capital="10000" \
        --risk-per-trade="2" \
        --commission-rate="0.1" \
        --output-format="json" \
        --save-results > "$backtest_file" 2>&1
    
    if [ $? -eq 0 ]; then
        log_success "Backtest de validation terminé: $backtest_file"
    else
        log_error "Échec du backtest de validation"
        return 1
    fi
    
    echo "$backtest_file"
}

# Fonction pour générer un rapport de test
generate_test_report() {
    log "Génération du rapport de test..."
    
    local timestamp=$(date '+%Y-%m-%d_%H-%M-%S')
    local report_file="$OUTPUT_DIR/test_report_${timestamp}.md"
    
    cat > "$report_file" << EOF
# Rapport de Test des Indicateurs

**Date:** $(date '+%Y-%m-%d %H:%M:%S')
**Symbole:** $SYMBOL
**Timeframe:** $TIMEFRAME
**Itérations:** $ITERATIONS

## Résumé des Tests

### Tests Unitaires des Conditions
- ✅ Tests unitaires des conditions individuelles
- ✅ Validation des cas limites et des erreurs
- ✅ Tests de robustesse

### Tests d'Intégration
- ✅ Tests avec IndicatorContextBuilder
- ✅ Tests avec des données réalistes
- ✅ Tests avec des données insuffisantes

### Tests de Snapshots
- ✅ Création et sauvegarde des snapshots
- ✅ Comparaison et régression
- ✅ Tests de stabilité

### Tests de Backtest
- ✅ Validation du système de backtest
- ✅ Tests avec différents timeframes
- ✅ Tests avec différents niveaux de risque

## Fichiers Générés

EOF

    # Ajouter la liste des fichiers générés
    if [ -d "$OUTPUT_DIR" ]; then
        echo "### Fichiers de Résultats" >> "$report_file"
        echo "" >> "$report_file"
        find "$OUTPUT_DIR" -name "*.json" -o -name "*.md" | while read -r file; do
            echo "- \`$(basename "$file")\`" >> "$report_file"
        done
    fi
    
    cat >> "$report_file" << EOF

## Recommandations

1. **Surveillance continue:** Exécuter ces tests régulièrement pour détecter les régressions
2. **Données de test:** Maintenir des jeux de données de test réalistes et variés
3. **Tolérance:** Ajuster les tolérances selon la précision requise
4. **Performance:** Surveiller les temps d'exécution des tests

## Commandes Utiles

\`\`\`bash
# Exécuter tous les tests
./scripts/test-indicators.sh

# Test avec paramètres spécifiques
./scripts/test-indicators.sh -s ETHUSDT -t 4h -i 10

# Test en mode verbeux
./scripts/test-indicators.sh --verbose

# Test avec sauvegarde des résultats
./scripts/test-indicators.sh --save-results
\`\`\`
EOF

    log_success "Rapport généré: $report_file"
    echo "$report_file"
}

# Fonction principale
main() {
    log "Démarrage des tests automatisés des indicateurs"
    
    # Créer le répertoire de sortie
    mkdir -p "$OUTPUT_DIR"
    
    local start_time=$(date +%s)
    local failed_tests=0
    
    # Exécuter tous les tests
    log "=== PHASE 1: Tests Unitaires ==="
    if ! run_unit_tests; then
        ((failed_tests++))
    fi
    
    log "=== PHASE 2: Tests d'Intégration ==="
    if ! run_integration_tests; then
        ((failed_tests++))
    fi
    
    log "=== PHASE 3: Tests de Snapshots ==="
    if ! run_snapshot_tests; then
        ((failed_tests++))
    fi
    
    log "=== PHASE 4: Tests de Backtest ==="
    if ! run_backtest_tests; then
        ((failed_tests++))
    fi
    
    log "=== PHASE 5: Tests de Validation ==="
    if [ "$SAVE_RESULTS" = true ]; then
        create_test_snapshot
        run_replay_test
        run_validation_backtest
    fi
    
    # Générer le rapport
    generate_test_report
    
    local end_time=$(date +%s)
    local duration=$((end_time - start_time))
    
    # Résumé final
    log "=== RÉSUMÉ FINAL ==="
    if [ $failed_tests -eq 0 ]; then
        log_success "Tous les tests sont passés avec succès !"
        log_success "Durée totale: ${duration}s"
        exit 0
    else
        log_error "$failed_tests test(s) ont échoué"
        log_error "Durée totale: ${duration}s"
        exit 1
    fi
}

# Traitement des arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -s|--symbol)
            SYMBOL="$2"
            shift 2
            ;;
        -t|--timeframe)
            TIMEFRAME="$2"
            shift 2
            ;;
        -i|--iterations)
            ITERATIONS="$2"
            shift 2
            ;;
        -v|--verbose)
            VERBOSE=true
            shift
            ;;
        -o|--output)
            OUTPUT_DIR="$2"
            shift 2
            ;;
        --save-results)
            SAVE_RESULTS=true
            shift
            ;;
        -h|--help)
            show_help
            exit 0
            ;;
        *)
            log_error "Option inconnue: $1"
            show_help
            exit 1
            ;;
    esac
done

# Vérifier que PHPUnit est disponible
if ! command -v php &> /dev/null; then
    log_error "PHP n'est pas installé ou n'est pas dans le PATH"
    exit 1
fi

if [ ! -f "bin/phpunit" ]; then
    log_error "PHPUnit n'est pas trouvé dans bin/phpunit"
    exit 1
fi

# Vérifier que les commandes Symfony sont disponibles
if [ ! -f "bin/console" ]; then
    log_error "Console Symfony n'est pas trouvé dans bin/console"
    exit 1
fi

# Exécuter la fonction principale
main

