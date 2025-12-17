#!/usr/bin/env bash

# Analyse complète des runs MTF depuis une date/heure donnée (horodatage lu directement depuis les logs).
# Usage:
#   ./analyze_mtf_runs_since.sh [HH:MM]                 # date = aujourd'hui (UTC), heure = HH:MM
#   ./analyze_mtf_runs_since.sh [YYYY-MM-DD] [HH:MM]    # date = YYYY-MM-DD, heure = HH:MM
# Exemples:
#   ./analyze_mtf_runs_since.sh 19:00
#   ./analyze_mtf_runs_since.sh 2025-12-14 16:00

set -u

ARG1="${1:-}"
ARG2="${2:-}"

if [[ -z "$ARG1" ]]; then
    DATE="$(date -u +%F)"
    SINCE_HOUR="19:00"
elif [[ "$ARG1" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]]; then
    DATE="$ARG1"
    SINCE_HOUR="${ARG2:-19:00}"
else
    DATE="$(date -u +%F)"
    SINCE_HOUR="$ARG1"
fi

if [[ ! "$SINCE_HOUR" =~ ^[0-9]{2}:[0-9]{2}$ ]]; then
    echo "Invalid time format: \"$SINCE_HOUR\" (expected HH:MM)" >&2
    echo "Usage: $0 [HH:MM]  OR  $0 [YYYY-MM-DD] [HH:MM]" >&2
    exit 2
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOG_DIR="$ROOT_DIR/var/log"
DEV_LOG="$LOG_DIR/dev-$DATE.log"
MTF_LOG="$LOG_DIR/mtf-$DATE.log"

# Fonction pour extraire les lignes depuis une heure donnée (format UTC: YYYY-MM-DD HH:MM:SS)
filter_since() {
    local hour_min="$1"
    local file="$2"
    if [[ ! -f "$file" ]]; then
        return 1
    fi
    # Extraire l'heure et la minute
    local hour=$(echo "$hour_min" | cut -d: -f1)
    local minute=$(echo "$hour_min" | cut -d: -f2)
    
    # Utiliser awk pour filtrer par timestamp
    awk -v date="$DATE" -v target_hour="$hour" -v target_min="$minute" '
        /^\[[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{3}\]/ {
            # Extraire le timestamp de la ligne
            ts_str = substr($0, 2, 23)  # Extrait "[YYYY-MM-DD HH:MM:SS.mmm"
            gsub(/\[|\]/, "", ts_str)
            split(ts_str, parts, /[- :.]/)
            line_date = parts[1] "-" parts[2] "-" parts[3]
            line_hour = int(parts[4])
            line_min = int(parts[5])
            
            # Comparer: même date et (heure > target OU (heure == target ET minute >= target))
            if (line_date == date) {
                if (line_hour > target_hour || (line_hour == target_hour && line_min >= target_min)) {
                    print
                }
            }
        }
    ' "$file"
}

rg_cmd() {
    if command -v rg >/dev/null 2>&1; then
        rg "$@"
    else
        grep -n "$1" "${@:2}"
    fi
}

echo "=========================================="
echo "Analyse MTF depuis ${SINCE_HOUR} UTC le ${DATE}"
echo "=========================================="
echo

# Vérification des fichiers de logs
if [[ ! -f "$DEV_LOG" ]]; then
    echo "⚠️  WARNING: dev log not found: $DEV_LOG" >&2
    DEV_LOG_EXISTS=false
else
    DEV_LOG_EXISTS=true
fi

if [[ ! -f "$MTF_LOG" ]]; then
    echo "⚠️  WARNING: mtf log not found: $MTF_LOG" >&2
    MTF_LOG_EXISTS=false
else
    MTF_LOG_EXISTS=true
fi

# ==========================================
# 1. ANALYSE HTTP /api/mtf/run
# ==========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "📊 HTTP /api/mtf/run"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
if [[ "$DEV_LOG_EXISTS" == "true" ]]; then
    RUN_LINES=$(filter_since "$SINCE_HOUR" "$DEV_LOG" | rg_cmd "/api/mtf/run" 2>/dev/null || true)
    RUN_COUNT=$(echo "$RUN_LINES" | grep -v '^$' | wc -l | tr -d ' ' || echo "0")
    
    if [[ -n "$RUN_LINES" && "$RUN_COUNT" -gt 0 ]]; then
        FIRST_RUN_TS=$(echo "$RUN_LINES" | head -n1 | sed -E 's/^\[([^]]+)\].*/\1/' | cut -d'.' -f1)
        LAST_RUN_TS=$(echo "$RUN_LINES" | tail -n1 | sed -E 's/^\[([^]]+)\].*/\1/' | cut -d'.' -f1)
        
        # Extraire les run_id et durées
        echo "Total appels /api/mtf/run : $RUN_COUNT"
        echo "Premier run               : $FIRST_RUN_TS"
        echo "Dernier run               : $LAST_RUN_TS"
        echo
        echo "Détails HTTP (routes):"
        echo "$RUN_LINES" | head -20
        if [[ $(echo "$RUN_LINES" | wc -l) -gt 20 ]]; then echo "... (et $(($(echo "$RUN_LINES" | wc -l) - 20)) autres)"; fi
    else
        echo "Aucun appel /api/mtf/run trouvé depuis ${SINCE_HOUR} UTC"
    fi
else
    echo "Log dev indisponible"
fi
echo

# ==========================================
# 1bis. RUNNER EXECUTION (run_id / durée)
# ==========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "🏃 MTF Runner (run_id / execution_time)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
if [[ "$DEV_LOG_EXISTS" == "true" ]]; then
    RUNNER_START_LINES=$(filter_since "$SINCE_HOUR" "$DEV_LOG" | rg_cmd "\\[MTF Runner\\] Starting execution" 2>/dev/null || true)
    RUNNER_END_LINES=$(filter_since "$SINCE_HOUR" "$DEV_LOG" | rg_cmd "\\[MTF Runner\\] Execution completed" 2>/dev/null || true)
    RUNNER_START_COUNT=$(echo "$RUNNER_START_LINES" | grep -v '^$' | wc -l | tr -d ' ' || echo "0")
    RUNNER_END_COUNT=$(echo "$RUNNER_END_LINES" | grep -v '^$' | wc -l | tr -d ' ' || echo "0")

    echo "Starts (runner)           : $RUNNER_START_COUNT"
    echo "Completions (runner)      : $RUNNER_END_COUNT"
    echo

    if [[ -n "$RUNNER_END_LINES" && "$RUNNER_END_COUNT" -gt 0 ]]; then
        echo "Dernières completions (max 20):"
        echo "$RUNNER_END_LINES" \
            | sed -E 's/^\[([^]]+)\].*run_id=([^ ]+).*execution_time=([0-9.]+).*/\1  run_id=\2  execution_time=\3s/' \
            | tail -20
    else
        echo "Aucune completion runner trouvée depuis ${SINCE_HOUR} UTC"
    fi
    echo

    if [[ -n "$RUNNER_START_LINES" ]]; then
        START_IDS=$(echo "$RUNNER_START_LINES" | sed -E 's/.*run_id=([^ ]+).*/\1/' | sort -u)
        END_IDS=$(echo "$RUNNER_END_LINES" | sed -E 's/.*run_id=([^ ]+).*/\1/' | sort -u)
        MISSING_IDS=$(comm -23 <(echo "$START_IDS") <(echo "$END_IDS") 2>/dev/null || true)
        if [[ -n "$MISSING_IDS" ]]; then
            echo "⚠️  Runs démarrés sans completion dans les logs:"
            echo "$MISSING_IDS" | sed 's/^/ - /'
        fi
    fi
else
    echo "Log dev indisponible"
fi
echo

# ==========================================
# 2. ANALYSE MTF CONTEXT (raisons)
# ==========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "📋 MTF Context (raisons d'invalidation)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
if [[ "$MTF_LOG_EXISTS" == "true" ]]; then
    CONTEXT_LINES=$(filter_since "$SINCE_HOUR" "$MTF_LOG" | rg_cmd "reason=" 2>/dev/null || true)
    TOTAL_CONTEXT=$(echo "$CONTEXT_LINES" | grep -v '^$' | wc -l | tr -d ' ' || echo "0")
    
    if [[ -n "$CONTEXT_LINES" && "$TOTAL_CONTEXT" -gt 0 ]]; then
        CONTEXT_REASON_COUNTS=$(echo "$CONTEXT_LINES" | \
            sed -E 's/.*reason=([^ ]*).*/\1/' | \
            sort | uniq -c | sort -nr | head -20)
        echo "Total lignes avec reason= : $TOTAL_CONTEXT"
        echo
        echo "Top raisons:"
        echo "$CONTEXT_REASON_COUNTS"
    else
        echo "Aucune ligne avec reason= trouvée depuis ${SINCE_HOUR} UTC"
    fi
else
    echo "Log MTF indisponible"
fi
echo

# ==========================================
# 3. ANALYSE CONTEXT TIMEFRAME INVALID
# ==========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "❌ Context Timeframe Invalid"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
if [[ "$DEV_LOG_EXISTS" == "true" ]]; then
    CTX_INVALID_LINES=$(filter_since "$SINCE_HOUR" "$DEV_LOG" | rg_cmd "\[MTF\] Context timeframe invalid" 2>/dev/null || true)
    CTX_INVALID_COUNT=$(echo "$CTX_INVALID_LINES" | grep -v '^$' | wc -l | tr -d ' ' || echo "0")
    
    if [[ -n "$CTX_INVALID_LINES" && "$CTX_INVALID_COUNT" -gt 0 ]]; then
        CTX_INVALID_REASON_COUNTS=$(echo "$CTX_INVALID_LINES" | \
            sed -E 's/.*invalid_reason=([^ ]*).*/\1/' | \
            sort | uniq -c | sort -nr | head -15)
        CTX_INVALID_TF_COUNTS=$(echo "$CTX_INVALID_LINES" | \
            sed -E 's/.*tf=([^ ]*).*/\1/' | \
            sort | uniq -c | sort -nr | head -15)
        
        echo "Total invalidités : $CTX_INVALID_COUNT"
        echo
        echo "Par raison:"
        echo "$CTX_INVALID_REASON_COUNTS"
        echo
        echo "Par timeframe:"
        echo "$CTX_INVALID_TF_COUNTS"
    else
        echo "Aucune invalidité de contexte timeframe trouvée depuis ${SINCE_HOUR} UTC"
    fi
else
    echo "Log dev indisponible"
fi
echo

# ==========================================
# 4. ANALYSE FILTRES DE CONTEXTE
# ==========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "🔍 Context Filter Check"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
if [[ "$DEV_LOG_EXISTS" == "true" ]]; then
    FILTER_LINES=$(filter_since "$SINCE_HOUR" "$DEV_LOG" | rg_cmd "\[MTF\] Context filter check" 2>/dev/null || true)
    FILTER_COUNT=$(echo "$FILTER_LINES" | grep -v '^$' | wc -l | tr -d ' ' || echo "0")
    
    if [[ -n "$FILTER_LINES" && "$FILTER_COUNT" -gt 0 ]]; then
        FILTER_COUNTS=$(echo "$FILTER_LINES" | \
            sed -E 's/.*filter=([^ ]*) passed=([^ ]*).*/\1 \2/' | \
            sort | uniq -c | sort -nr | head -20)
        echo "Total checks de filtres : $FILTER_COUNT"
        echo
        echo "Top filtres (pass/fail):"
        echo "$FILTER_COUNTS"
    else
        echo "Aucun check de filtre trouvé depuis ${SINCE_HOUR} UTC"
    fi
else
    echo "Log dev indisponible"
fi
echo

# ==========================================
# 5. ANALYSE BDD - RUNS
# ==========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "💾 Base de données - Runs MTF"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
    SINCE_TIMESTAMP="${DATE} ${SINCE_HOUR}:00"
    
    # Résumé des runs depuis 19:00
    RUNS_SUMMARY=$(docker compose -f "$ROOT_DIR/docker-compose.yml" exec -T trading-app-db \
        psql -U postgres -d trading_app -t -A -F $'\t' -v ON_ERROR_STOP=0 -c "
        SELECT 
            COUNT(*) AS total_runs,
            COUNT(*) FILTER (WHERE status = 'completed') AS completed,
            COUNT(*) FILTER (WHERE status = 'running') AS running,
            COUNT(*) FILTER (WHERE status = 'failed') AS failed,
            ROUND(AVG(execution_time_seconds)::numeric, 2) AS avg_duration,
            ROUND(MAX(execution_time_seconds)::numeric, 2) AS max_duration,
            SUM(symbols_requested) AS total_symbols_requested,
            SUM(symbols_processed) AS total_symbols_processed,
            SUM(symbols_successful) AS total_symbols_successful,
            SUM(symbols_failed) AS total_symbols_failed,
            SUM(symbols_skipped) AS total_symbols_skipped,
            ROUND(AVG(success_rate::numeric), 2) AS avg_success_rate
        FROM mtf_run
        WHERE started_at >= '$SINCE_TIMESTAMP'::timestamptz;
    " 2>/dev/null | tr '\t' '|' || echo "ERROR|ERROR|ERROR|ERROR|ERROR|ERROR|ERROR|ERROR|ERROR|ERROR|ERROR|ERROR")
    
    if [[ "$RUNS_SUMMARY" != *"ERROR"* ]]; then
        IFS='|' read -r total completed running failed avg_dur max_dur req proc succ fail skip avg_rate <<< "$RUNS_SUMMARY"
        echo "Total runs                    : $total"
        echo "  - Completed                 : $completed"
        echo "  - Running                   : $running"
        echo "  - Failed                    : $failed"
        echo "Durée moyenne                : ${avg_dur}s"
        echo "Durée max                    : ${max_dur}s"
        echo "Symboles demandés (total)     : $req"
        echo "Symboles traités (total)      : $proc"
        echo "Symboles réussis (total)      : $succ"
        echo "Symboles échoués (total)     : $fail"
        echo "Symboles ignorés (total)     : $skip"
        echo "Taux de succès moyen         : ${avg_rate}%"
        echo
        
        # Détails des runs
        echo "Détails des runs (derniers 10):"
        docker compose -f "$ROOT_DIR/docker-compose.yml" exec -T trading-app-db \
            psql -U postgres -d trading_app -t -A -F $'\t' -v ON_ERROR_STOP=0 -c "
            SELECT 
                run_id::text,
                started_at::text,
                status,
                execution_time_seconds,
                symbols_requested,
                symbols_processed,
                symbols_successful,
                symbols_failed,
                symbols_skipped,
                success_rate,
                workers
            FROM mtf_run
            WHERE started_at >= '$SINCE_TIMESTAMP'::timestamptz
            ORDER BY started_at DESC
            LIMIT 10;
        " 2>/dev/null | while IFS=$'\t' read -r run_id started status exec_time req proc succ fail skip rate workers; do
            echo "  $started | $status | ${exec_time}s | req=$req proc=$proc succ=$succ fail=$fail skip=$skip | rate=${rate}% | workers=${workers:-N/A}"
        done
    else
        echo "Erreur lors de la requête BDD"
    fi
else
    echo "Docker indisponible ou injoignable"
fi
echo

# ==========================================
# 6. ANALYSE BDD - SYMBOLES PAR RUN
# ==========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "💾 Base de données - Symboles par Run"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
    SINCE_TIMESTAMP="${DATE} ${SINCE_HOUR}:00"
    
    # Statuts des symboles
    echo "Statuts des symboles:"
    docker compose -f "$ROOT_DIR/docker-compose.yml" exec -T trading-app-db \
        psql -U postgres -d trading_app -t -A -F $'\t' -v ON_ERROR_STOP=0 -c "
        SELECT 
            COALESCE(status, '<NULL>') AS status,
            COUNT(*) AS count
        FROM mtf_run_symbol s
        JOIN mtf_run r ON r.run_id = s.run_id
        WHERE r.started_at >= '$SINCE_TIMESTAMP'::timestamptz
        GROUP BY COALESCE(status, '<NULL>')
        ORDER BY count DESC;
    " 2>/dev/null | while IFS=$'\t' read -r status count; do
        echo "  $status : $count"
    done
    echo
    
    # Répartition par timeframe d'exécution
    echo "Répartition par timeframe d'exécution:"
    docker compose -f "$ROOT_DIR/docker-compose.yml" exec -T trading-app-db \
        psql -U postgres -d trading_app -t -A -F $'\t' -v ON_ERROR_STOP=0 -c "
        SELECT 
            COALESCE(execution_tf, '<NULL>') AS execution_tf,
            COUNT(*) AS count
        FROM mtf_run_symbol s
        JOIN mtf_run r ON r.run_id = s.run_id
        WHERE r.started_at >= '$SINCE_TIMESTAMP'::timestamptz
        GROUP BY COALESCE(execution_tf, '<NULL>')
        ORDER BY count DESC;
    " 2>/dev/null | while IFS=$'\t' read -r tf count; do
        echo "  $tf : $count"
    done
    echo
    
    # Répartition par timeframe de blocage
    echo "Répartition par timeframe de blocage:"
    docker compose -f "$ROOT_DIR/docker-compose.yml" exec -T trading-app-db \
        psql -U postgres -d trading_app -t -A -F $'\t' -v ON_ERROR_STOP=0 -c "
        SELECT 
            COALESCE(blocking_tf, '<NULL>') AS blocking_tf,
            COUNT(*) AS count
        FROM mtf_run_symbol s
        JOIN mtf_run r ON r.run_id = s.run_id
        WHERE r.started_at >= '$SINCE_TIMESTAMP'::timestamptz
        GROUP BY COALESCE(blocking_tf, '<NULL>')
        ORDER BY count DESC;
    " 2>/dev/null | while IFS=$'\t' read -r tf count; do
        echo "  $tf : $count"
    done
    echo
    
    # Top symboles les plus traités
    echo "Top 10 symboles les plus traités:"
    docker compose -f "$ROOT_DIR/docker-compose.yml" exec -T trading-app-db \
        psql -U postgres -d trading_app -t -A -F $'\t' -v ON_ERROR_STOP=0 -c "
        SELECT 
            s.symbol,
            COUNT(*) AS count,
            COUNT(*) FILTER (WHERE s.status = 'success') AS success,
            COUNT(*) FILTER (WHERE s.status = 'failed') AS failed,
            COUNT(*) FILTER (WHERE s.status = 'skipped') AS skipped
        FROM mtf_run_symbol s
        JOIN mtf_run r ON r.run_id = s.run_id
        WHERE r.started_at >= '$SINCE_TIMESTAMP'::timestamptz
        GROUP BY s.symbol
        ORDER BY count DESC
        LIMIT 10;
    " 2>/dev/null | while IFS=$'\t' read -r symbol count success failed skipped; do
        echo "  $symbol : total=$count (succ=$success, fail=$failed, skip=$skipped)"
    done
else
    echo "Docker indisponible ou injoignable"
fi
echo

# ==========================================
# 7. ANALYSE BDD - AUDIT
# ==========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "💾 Base de données - Audit MTF"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
    SINCE_TIMESTAMP="${DATE} ${SINCE_HOUR}:00"
    
    # Total d'audit
    AUDIT_TOTAL=$(docker compose -f "$ROOT_DIR/docker-compose.yml" exec -T trading-app-db \
        psql -U postgres -d trading_app -t -c "
        SELECT COUNT(*) FROM mtf_audit
        WHERE created_at >= '$SINCE_TIMESTAMP'::timestamptz;
    " 2>/dev/null | tr -d ' ' || echo "0")
    
    echo "Total entrées d'audit : $AUDIT_TOTAL"
    echo
    
    # Top causes
    echo "Top 15 causes d'audit:"
    docker compose -f "$ROOT_DIR/docker-compose.yml" exec -T trading-app-db \
        psql -U postgres -d trading_app -t -A -F $'\t' -v ON_ERROR_STOP=0 -c "
        SELECT 
            COALESCE(cause, '<NULL>') AS cause,
            COUNT(*) AS count
        FROM mtf_audit
        WHERE created_at >= '$SINCE_TIMESTAMP'::timestamptz
        GROUP BY COALESCE(cause, '<NULL>')
        ORDER BY count DESC
        LIMIT 15;
    " 2>/dev/null | while IFS=$'\t' read -r cause count; do
        echo "  $cause : $count"
    done
    echo
    
    # Top steps
    echo "Top 15 steps d'audit:"
    docker compose -f "$ROOT_DIR/docker-compose.yml" exec -T trading-app-db \
        psql -U postgres -d trading_app -t -A -F $'\t' -v ON_ERROR_STOP=0 -c "
        SELECT 
            step,
            COUNT(*) AS count
        FROM mtf_audit
        WHERE created_at >= '$SINCE_TIMESTAMP'::timestamptz
        GROUP BY step
        ORDER BY count DESC
        LIMIT 15;
    " 2>/dev/null | while IFS=$'\t' read -r step count; do
        echo "  $step : $count"
    done
    echo
    
    # Répartition par timeframe
    echo "Répartition par timeframe:"
    docker compose -f "$ROOT_DIR/docker-compose.yml" exec -T trading-app-db \
        psql -U postgres -d trading_app -t -A -F $'\t' -v ON_ERROR_STOP=0 -c "
        SELECT 
            COALESCE(timeframe::text, '<NULL>') AS timeframe,
            COUNT(*) AS count
        FROM mtf_audit
        WHERE created_at >= '$SINCE_TIMESTAMP'::timestamptz
        GROUP BY COALESCE(timeframe::text, '<NULL>')
        ORDER BY count DESC;
    " 2>/dev/null | while IFS=$'\t' read -r tf count; do
        echo "  $tf : $count"
    done
else
    echo "Docker indisponible ou injoignable"
fi
echo

# ==========================================
# 8. ANALYSE BDD - ZONE EVENTS
# ==========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "💾 Base de données - Trade Zone Events"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
    SINCE_TIMESTAMP="${DATE} ${SINCE_HOUR}:00"
    
    # Total d'événements
    ZONE_TOTAL=$(docker compose -f "$ROOT_DIR/docker-compose.yml" exec -T trading-app-db \
        psql -U postgres -d trading_app -t -c "
        SELECT COUNT(*) FROM trade_zone_events
        WHERE happened_at >= '$SINCE_TIMESTAMP'::timestamptz;
    " 2>/dev/null | tr -d ' ' || echo "0")
    
    echo "Total événements de zone : $ZONE_TOTAL"
    echo
    
    # Répartition par raison
    echo "Répartition par raison:"
    docker compose -f "$ROOT_DIR/docker-compose.yml" exec -T trading-app-db \
        psql -U postgres -d trading_app -t -A -F $'\t' -v ON_ERROR_STOP=0 -c "
        SELECT 
            COALESCE(reason, '<NULL>') AS reason,
            COUNT(*) AS count
        FROM trade_zone_events
        WHERE happened_at >= '$SINCE_TIMESTAMP'::timestamptz
        GROUP BY COALESCE(reason, '<NULL>')
        ORDER BY count DESC
        LIMIT 15;
    " 2>/dev/null | while IFS=$'\t' read -r reason count; do
        echo "  $reason : $count"
    done
    echo
    
    # Répartition par catégorie
    echo "Répartition par catégorie:"
    docker compose -f "$ROOT_DIR/docker-compose.yml" exec -T trading-app-db \
        psql -U postgres -d trading_app -t -A -F $'\t' -v ON_ERROR_STOP=0 -c "
        SELECT 
            COALESCE(category, '<NULL>') AS category,
            COUNT(*) AS count
        FROM trade_zone_events
        WHERE happened_at >= '$SINCE_TIMESTAMP'::timestamptz
        GROUP BY COALESCE(category, '<NULL>')
        ORDER BY count DESC;
    " 2>/dev/null | while IFS=$'\t' read -r category count; do
        echo "  $category : $count"
    done
    echo
    
    # Stats sur zone_dev_pct pour skipped_out_of_zone
    echo "Stats zone_dev_pct (skipped_out_of_zone):"
    docker compose -f "$ROOT_DIR/docker-compose.yml" exec -T trading-app-db \
        psql -U postgres -d trading_app -t -A -F $'\t' -v ON_ERROR_STOP=0 -c "
        SELECT 
            COUNT(*) AS count,
            ROUND(MIN(zone_dev_pct)::numeric, 4) AS min_dev,
            ROUND(PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY zone_dev_pct)::numeric, 4) AS p25,
            ROUND(PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY zone_dev_pct)::numeric, 4) AS median,
            ROUND(PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY zone_dev_pct)::numeric, 4) AS p75,
            ROUND(MAX(zone_dev_pct)::numeric, 4) AS max_dev
        FROM trade_zone_events
        WHERE happened_at >= '$SINCE_TIMESTAMP'::timestamptz
          AND reason = 'skipped_out_of_zone';
    " 2>/dev/null | while IFS=$'\t' read -r count min_dev p25 median p75 max_dev; do
        if [[ -n "$count" && "$count" != "0" ]]; then
            echo "  count=$count | min=$min_dev | p25=$p25 | median=$median | p75=$p75 | max=$max_dev"
        else
            echo "  Aucun événement skipped_out_of_zone"
        fi
    done
else
    echo "Docker indisponible ou injoignable"
fi
echo

# ==========================================
# 9. ANALYSE BDD - LIFECYCLE EVENTS
# ==========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "💾 Base de données - Trade Lifecycle Events"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
    SINCE_TIMESTAMP="${DATE} ${SINCE_HOUR}:00"
    
    # Total d'événements
    LIFECYCLE_TOTAL=$(docker compose -f "$ROOT_DIR/docker-compose.yml" exec -T trading-app-db \
        psql -U postgres -d trading_app -t -c "
        SELECT COUNT(*) FROM trade_lifecycle_event
        WHERE happened_at >= '$SINCE_TIMESTAMP'::timestamptz;
    " 2>/dev/null | tr -d ' ' || echo "0")
    
    echo "Total événements lifecycle : $LIFECYCLE_TOTAL"
    echo
    
    # Répartition par event_type
    echo "Répartition par event_type:"
    docker compose -f "$ROOT_DIR/docker-compose.yml" exec -T trading-app-db \
        psql -U postgres -d trading_app -t -A -F $'\t' -v ON_ERROR_STOP=0 -c "
        SELECT 
            COALESCE(event_type, '<NULL>') AS event_type,
            COUNT(*) AS count
        FROM trade_lifecycle_event
        WHERE happened_at >= '$SINCE_TIMESTAMP'::timestamptz
        GROUP BY COALESCE(event_type, '<NULL>')
        ORDER BY count DESC;
    " 2>/dev/null | while IFS=$'\t' read -r event_type count; do
        echo "  $event_type : $count"
    done
    echo
    
    # Répartition par reason_code
    echo "Top 15 reason_code:"
    docker compose -f "$ROOT_DIR/docker-compose.yml" exec -T trading-app-db \
        psql -U postgres -d trading_app -t -A -F $'\t' -v ON_ERROR_STOP=0 -c "
        SELECT 
            COALESCE(reason_code, '<NULL>') AS reason_code,
            COUNT(*) AS count
        FROM trade_lifecycle_event
        WHERE happened_at >= '$SINCE_TIMESTAMP'::timestamptz
        GROUP BY COALESCE(reason_code, '<NULL>')
        ORDER BY count DESC
        LIMIT 15;
    " 2>/dev/null | while IFS=$'\t' read -r reason_code count; do
        echo "  $reason_code : $count"
    done
else
    echo "Docker indisponible ou injoignable"
fi
echo

echo "=========================================="
echo "✅ Analyse terminée"
echo "=========================================="
