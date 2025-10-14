#!/bin/bash

# Script de test pour le système de logging des positions
# Teste le suivi complet depuis la validation 1m jusqu'à l'ouverture effective

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

# Fonction pour tester le logging des positions
test_position_logging() {
    local symbol=$1
    local side=$2
    local scenario=$3
    
    log "Testing position logging: $scenario ($symbol $side)"
    
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
        "dry_run": true
    }'
    
    # Exécuter Post-Validation
    local response=$(curl -s -X POST -H "Content-Type: application/json" -d "$data" "$API_BASE/execute")
    local http_code=$(curl -s -w "%{http_code}" -X POST -H "Content-Type: application/json" -d "$data" "$API_BASE/execute" -o /dev/null)
    
    if [ "$http_code" = "200" ]; then
        success "Post-Validation executed successfully"
        
        # Vérifier les logs
        check_position_logs "$symbol" "$scenario"
    else
        error "Post-Validation failed with HTTP $http_code"
        echo "$response"
        return 1
    fi
}

# Fonction pour vérifier les logs de position
check_position_logs() {
    local symbol=$1
    local scenario=$2
    
    log "Checking position logs for $symbol ($scenario)"
    
    # Vérifier que le fichier de log existe
    local log_file="/Users/haythem.mabrouk/workspace/perso/tradingV3/trading-app/var/log/positions.log"
    
    if [ ! -f "$log_file" ]; then
        warning "Position log file not found: $log_file"
        return 1
    fi
    
    # Vérifier les logs récents (dernières 2 minutes)
    local recent_logs=$(tail -n 100 "$log_file" | grep -E "\[$(date +%Y-%m-%d).*\]" | tail -n 20)
    
    if [ -z "$recent_logs" ]; then
        warning "No recent position logs found"
        return 1
    fi
    
    # Vérifier les étapes clés du logging
    local steps=(
        "POST-VALIDATION START"
        "MARKET_DATA.*Retrieved"
        "TIMEFRAME_SELECTION.*Selected"
        "ENTRY_ZONE.*Calculated"
        "ORDER_PLAN.*Created"
        "GUARDS.*Execution completed"
        "STATE_MACHINE.*Starting sequence"
        "MAKER_ORDER.*Submitted"
        "MAKER_ORDER.*Waiting for fill"
        "MAKER_ORDER.*Fill result"
        "TP_SL.*Attached"
        "POSITION.*Opened successfully"
        "MONITORING.*Started"
        "FINAL_DECISION.*Post-validation completed"
    )
    
    local found_steps=0
    local total_steps=${#steps[@]}
    
    for step in "${steps[@]}"; do
        if echo "$recent_logs" | grep -q "$step"; then
            success "Found log step: $step"
            ((found_steps++))
        else
            warning "Missing log step: $step"
        fi
    done
    
    local success_rate=$((found_steps * 100 / total_steps))
    
    if [ $success_rate -ge 80 ]; then
        success "Position logging test passed ($found_steps/$total_steps steps found, $success_rate%)"
    else
        error "Position logging test failed ($found_steps/$total_steps steps found, $success_rate%)"
        return 1
    fi
}

# Fonction pour afficher les logs récents
show_recent_logs() {
    local log_file="/Users/haythem.mabrouk/workspace/perso/tradingV3/trading-app/var/log/positions.log"
    
    if [ -f "$log_file" ]; then
        log "Recent position logs:"
        echo "----------------------------------------"
        tail -n 50 "$log_file" | grep -E "\[$(date +%Y-%m-%d).*\]" | tail -n 20
        echo "----------------------------------------"
    else
        warning "Position log file not found: $log_file"
    fi
}

# Fonction pour nettoyer les logs
clean_logs() {
    local log_file="/Users/haythem.mabrouk/workspace/perso/tradingV3/trading-app/var/log/positions.log"
    
    if [ -f "$log_file" ]; then
        log "Cleaning position logs..."
        > "$log_file"
        success "Position logs cleaned"
    fi
}

# Fonction pour surveiller les logs en temps réel
monitor_logs() {
    local log_file="/Users/haythem.mabrouk/workspace/perso/tradingV3/trading-app/var/log/positions.log"
    
    if [ -f "$log_file" ]; then
        log "Monitoring position logs in real-time (Ctrl+C to stop)..."
        tail -f "$log_file" | grep -E "\[$(date +%Y-%m-%d).*\]"
    else
        error "Position log file not found: $log_file"
    fi
}

# Fonction pour analyser les performances de logging
analyze_logging_performance() {
    local log_file="/Users/haythem.mabrouk/workspace/perso/tradingV3/trading-app/var/log/positions.log"
    
    if [ ! -f "$log_file" ]; then
        error "Position log file not found: $log_file"
        return 1
    fi
    
    log "Analyzing logging performance..."
    
    # Compter les logs par type
    local total_logs=$(wc -l < "$log_file")
    local error_logs=$(grep -c "ERROR" "$log_file" || echo "0")
    local warning_logs=$(grep -c "WARNING" "$log_file" || echo "0")
    local info_logs=$(grep -c "INFO" "$log_file" || echo "0")
    local debug_logs=$(grep -c "DEBUG" "$log_file" || echo "0")
    
    echo "Log Statistics:"
    echo "  Total logs: $total_logs"
    echo "  INFO: $info_logs"
    echo "  WARNING: $warning_logs"
    echo "  ERROR: $error_logs"
    echo "  DEBUG: $debug_logs"
    
    # Analyser les performances par symbole
    echo ""
    echo "Performance by Symbol:"
    grep "symbol.*BTCUSDT" "$log_file" | wc -l | xargs echo "  BTCUSDT:"
    grep "symbol.*ETHUSDT" "$log_file" | wc -l | xargs echo "  ETHUSDT:"
    grep "symbol.*ADAUSDT" "$log_file" | wc -l | xargs echo "  ADAUSDT:"
    
    # Analyser les étapes les plus fréquentes
    echo ""
    echo "Most Frequent Steps:"
    grep -o "\[[A-Z_]*\]" "$log_file" | sort | uniq -c | sort -nr | head -10
}

# Menu principal
case "${1:-test}" in
    "test")
        echo "=========================================="
        log "Testing Position Logging System"
        echo "=========================================="
        
        # Test avec différents scénarios
        test_position_logging "BTCUSDT" "LONG" "Basic Long Position"
        test_position_logging "ETHUSDT" "SHORT" "Basic Short Position"
        test_position_logging "ADAUSDT" "LONG" "Altcoin Long Position"
        
        echo ""
        log "Position logging tests completed"
        ;;
    
    "show")
        show_recent_logs
        ;;
    
    "clean")
        clean_logs
        ;;
    
    "monitor")
        monitor_logs
        ;;
    
    "analyze")
        analyze_logging_performance
        ;;
    
    "help")
        echo "Usage: $0 [command]"
        echo ""
        echo "Commands:"
        echo "  test     - Run position logging tests (default)"
        echo "  show     - Show recent position logs"
        echo "  clean    - Clean position logs"
        echo "  monitor  - Monitor logs in real-time"
        echo "  analyze  - Analyze logging performance"
        echo "  help     - Show this help"
        ;;
    
    *)
        error "Unknown command: $1"
        echo "Use '$0 help' for available commands"
        exit 1
        ;;
esac

