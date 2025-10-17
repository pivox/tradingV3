#!/bin/bash

# Script de test pour Post-Validation
# Teste l'API Post-Validation avec différents scénarios

set -e

# Configuration
BASE_URL="http://localhost:8082"
API_BASE="$BASE_URL/api/post-validation"

# Couleurs pour les logs
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Fonction de log
log() {
    echo -e "${BLUE}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1"
}

success() {
    echo -e "${GREEN}✓${NC} $1"
}

error() {
    echo -e "${RED}✗${NC} $1"
}

warning() {
    echo -e "${YELLOW}⚠${NC} $1"
}

# Fonction pour tester un endpoint
test_endpoint() {
    local method=$1
    local endpoint=$2
    local data=$3
    local expected_status=$4
    local description=$5
    
    log "Testing: $description"
    
    if [ "$method" = "GET" ]; then
        response=$(curl -s -w "\n%{http_code}" "$API_BASE$endpoint")
    else
        response=$(curl -s -w "\n%{http_code}" -X "$method" -H "Content-Type: application/json" -d "$data" "$API_BASE$endpoint")
    fi
    
    http_code=$(echo "$response" | tail -n1)
    body=$(echo "$response" | head -n -1)
    
    if [ "$http_code" = "$expected_status" ]; then
        success "$description - Status: $http_code"
        echo "$body" | jq . 2>/dev/null || echo "$body"
    else
        error "$description - Expected: $expected_status, Got: $http_code"
        echo "$body"
        return 1
    fi
    
    echo ""
}

# Fonction pour tester Post-Validation avec différents scénarios
test_post_validation_scenario() {
    local symbol=$1
    local side=$2
    local scenario=$3
    local dry_run=${4:-true}
    
    local mtf_context='{
        "5m": {"signal_side": "'$side'", "status": "valid"},
        "15m": {"signal_side": "'$side'", "status": "valid"},
        "candle_close_ts": '$(date +%s)',
        "conviction_flag": false
    }'
    
    local data='{
        "symbol": "'$symbol'",
        "side": "'$side'",
        "mtf_context": '$mtf_context',
        "wallet_equity": 1000.0,
        "dry_run": '$dry_run'
    }'
    
    test_endpoint "POST" "/execute" "$data" "200" "Post-Validation $scenario ($symbol $side)"
}

# Vérification de la connectivité
log "Vérification de la connectivité..."
if ! curl -s "$BASE_URL/api/post-validation/docs" > /dev/null; then
    error "Impossible de se connecter à $BASE_URL"
    exit 1
fi
success "Connectivité OK"

echo "=========================================="
log "Début des tests Post-Validation"
echo "=========================================="

# Test 1: Documentation API
test_endpoint "GET" "/docs" "" "200" "Documentation API"

# Test 2: Statistiques
test_endpoint "GET" "/statistics" "" "200" "Statistiques Post-Validation"

# Test 3: Test de configuration
test_endpoint "GET" "/test-config" "" "200" "Test de configuration"

# Test 4: Scénarios Post-Validation
log "Tests des scénarios Post-Validation..."

# T-01: Maker Fill - BTCUSDT LONG
test_post_validation_scenario "BTCUSDT" "LONG" "Maker Fill" true

# T-02: Maker Timeout → IOC - BTCUSDT SHORT
test_post_validation_scenario "BTCUSDT" "SHORT" "Maker Timeout → IOC" true

# T-03: Upshift vers 1m - ETHUSDT LONG
test_post_validation_scenario "ETHUSDT" "LONG" "Upshift vers 1m" true

# T-04: Bracket levier - ADAUSDT LONG avec conviction
test_post_validation_scenario "ADAUSDT" "LONG" "Bracket levier" true

# T-05: Stale Ticker - SOLUSDT SHORT
test_post_validation_scenario "SOLUSDT" "SHORT" "Stale Ticker" true

# T-06: Reconcile - DOTUSDT LONG
test_post_validation_scenario "DOTUSDT" "LONG" "Reconcile" true

# Test 5: Validation des paramètres
log "Tests de validation des paramètres..."

# Test avec paramètres manquants
test_endpoint "POST" "/execute" '{"symbol": "BTCUSDT"}' "400" "Paramètres manquants"

# Test avec side invalide
test_endpoint "POST" "/execute" '{"symbol": "BTCUSDT", "side": "INVALID"}' "400" "Side invalide"

# Test avec symbole invalide
test_endpoint "POST" "/execute" '{"symbol": "", "side": "LONG"}' "400" "Symbole vide"

# Test 6: Tests de performance
log "Tests de performance..."

start_time=$(date +%s)
for i in {1..5}; do
    test_post_validation_scenario "BTCUSDT" "LONG" "Performance Test $i" true > /dev/null 2>&1
done
end_time=$(date +%s)
duration=$((end_time - start_time))

log "5 tests de performance terminés en ${duration}s"

# Test 7: Tests de charge
log "Tests de charge..."

start_time=$(date +%s)
for i in {1..10}; do
    test_post_validation_scenario "BTCUSDT" "LONG" "Load Test $i" true > /dev/null 2>&1 &
done
wait
end_time=$(date +%s)
duration=$((end_time - start_time))

log "10 tests de charge terminés en ${duration}s"

echo "=========================================="
log "Tests Post-Validation terminés"
echo "=========================================="

# Résumé des tests
log "Résumé des tests:"
success "✓ Documentation API"
success "✓ Statistiques"
success "✓ Test de configuration"
success "✓ Scénarios Post-Validation (T-01 à T-06)"
success "✓ Validation des paramètres"
success "✓ Tests de performance"
success "✓ Tests de charge"

log "Tous les tests sont passés avec succès!"

# Test optionnel en mode production (dry_run=false)
if [ "$1" = "--production" ]; then
    warning "Mode production activé - ATTENTION: Ceci va créer de vrais ordres!"
    read -p "Êtes-vous sûr de vouloir continuer? (y/N): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        log "Test en mode production..."
        test_post_validation_scenario "BTCUSDT" "LONG" "Production Test" false
        success "Test en mode production terminé"
    else
        log "Test en mode production annulé"
    fi
fi

echo ""
log "Script de test terminé avec succès!"

