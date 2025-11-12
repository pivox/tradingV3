#!/usr/bin/env bash

set -euo pipefail

# Configuration DB (identique à mtf_list_ready_symbols.sh)
DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-5433}"
DB_USER="${DB_USER:-postgres}"
DB_NAME="${DB_NAME:-trading_app}"
export PGPASSWORD="${PGPASSWORD:-password}"

# Symboles à investiguer
SYMBOLS=("GLMUSDT" "VELODROMEUSDT" "LTCUSDT" "MELANIAUSDT" "FILUSDT")

# Couleurs pour l'affichage
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo "=========================================="
echo "Investigation des symboles sans ordre"
echo "=========================================="
echo ""

for SYMBOL in "${SYMBOLS[@]}"; do
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${BLUE}🔍 SYMBOLE: ${SYMBOL}${NC}"
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""
    
    # 1. Vérifier les kill switches
    echo -e "${YELLOW}[1] Kill Switches:${NC}"
    SWITCHES=$(psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" "$DB_NAME" -t -c "
        SELECT switch_key, is_on, expires_at 
        FROM mtf_switch 
        WHERE switch_key IN ('GLOBAL', 'SYMBOL:${SYMBOL}', 'SYMBOL_TF:${SYMBOL}:1m', 'SYMBOL_TF:${SYMBOL}:5m', 'SYMBOL_TF:${SYMBOL}:15m', 'SYMBOL_TF:${SYMBOL}:1h', 'SYMBOL_TF:${SYMBOL}:4h')
        ORDER BY switch_key;
    " 2>/dev/null || echo "")
    
    if [ -n "$SWITCHES" ] && [ "$(echo "$SWITCHES" | grep -v '^$' | wc -l)" -gt 0 ]; then
        echo "$SWITCHES" | while IFS='|' read -r key on expires; do
            key=$(echo "$key" | xargs)
            on=$(echo "$on" | xargs)
            expires=$(echo "$expires" | xargs)
            if [ "$on" = "f" ] || [ "$on" = "false" ]; then
                echo -e "  ${RED}❌ $key: OFF${NC}${expires:+ (expire: $expires)}"
            else
                echo -e "  ${GREEN}✅ $key: ON${NC}"
            fi
        done
    else
        echo -e "  ${GREEN}✅ Aucun kill switch actif${NC}"
    fi
    echo ""
    
    # 2. Vérifier les audits récents (24h)
    echo -e "${YELLOW}[2] Audits récents (24h):${NC}"
    AUDITS=$(psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" "$DB_NAME" -t -c "
        SELECT 
            step, 
            COALESCE(timeframe, details->>'timeframe', 'N/A') as tf,
            details->>'signal_side' as side,
            details->>'kline_time' as kt,
            created_at
        FROM mtf_audit 
        WHERE symbol='${SYMBOL}' 
          AND created_at > now() - interval '24 hours'
        ORDER BY created_at DESC 
        LIMIT 20;
    " 2>/dev/null || echo "")
    
    if [ -n "$AUDITS" ] && [ "$(echo "$AUDITS" | grep -v '^$' | wc -l)" -gt 0 ]; then
        echo "$AUDITS" | while IFS='|' read -r step tf side kt created; do
            step=$(echo "$step" | xargs)
            tf=$(echo "$tf" | xargs)
            side=$(echo "$side" | xargs)
            kt=$(echo "$kt" | xargs)
            created=$(echo "$created" | xargs)
            
            if echo "$step" | grep -qi "SUCCESS\|VALIDATED"; then
                echo -e "  ${GREEN}✅${NC} $step | TF: $tf | Side: $side | $created"
            elif echo "$step" | grep -qi "FAILED\|ERROR\|EXCEPTION"; then
                echo -e "  ${RED}❌${NC} $step | TF: $tf | Side: $side | $created"
            elif echo "$step" | grep -qi "ALIGNMENT_FAILED"; then
                echo -e "  ${RED}⚠️${NC} $step | TF: $tf | Side: $side | $created"
            elif echo "$step" | grep -qi "TRADE_ENTRY"; then
                echo -e "  ${YELLOW}📋${NC} $step | TF: $tf | Side: $side | $created"
            else
                echo -e "  ℹ️  $step | TF: $tf | Side: $side | $created"
            fi
        done
    else
        echo -e "  ${YELLOW}⚠️  Aucun audit trouvé dans les 24h${NC}"
    fi
    echo ""
    
    # 3. Vérifier les order plans
    echo -e "${YELLOW}[3] Order Plans:${NC}"
    PLANS=$(psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" "$DB_NAME" -t -c "
        SELECT id, side, status, plan_time
        FROM order_plan 
        WHERE symbol='${SYMBOL}' 
        ORDER BY plan_time DESC 
        LIMIT 10;
    " 2>/dev/null || echo "")
    
    if [ -n "$PLANS" ] && [ "$(echo "$PLANS" | grep -v '^$' | wc -l)" -gt 0 ]; then
        echo "$PLANS" | while IFS='|' read -r id side status plan_time; do
            id=$(echo "$id" | xargs)
            side=$(echo "$side" | xargs)
            status=$(echo "$status" | xargs)
            plan_time=$(echo "$plan_time" | xargs)
            if [ "$status" = "executed" ] || [ "$status" = "submitted" ]; then
                echo -e "  ${GREEN}✅${NC} Plan #$id | $side | $status | $plan_time"
            else
                echo -e "  ${YELLOW}⚠️${NC} Plan #$id | $side | $status | $plan_time"
            fi
        done
    else
        echo -e "  ${RED}❌ Aucun order plan trouvé${NC}"
    fi
    echo ""
    
    # 4. Vérifier le statut du dernier run (via API si disponible, sinon via DB)
    echo -e "${YELLOW}[4] Dernier statut MTF:${NC}"
    LAST_RUN=$(psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" "$DB_NAME" -t -c "
        SELECT 
            run_id,
            status,
            blocking_tf,
            trading_decision->>'status' as decision_status,
            created_at
        FROM mtf_run_result 
        WHERE symbol='${SYMBOL}' 
        ORDER BY created_at DESC 
        LIMIT 1;
    " 2>/dev/null || echo "")
    
    if [ -n "$LAST_RUN" ] && [ "$(echo "$LAST_RUN" | grep -v '^$' | wc -l)" -gt 0 ]; then
        echo "$LAST_RUN" | while IFS='|' read -r run_id status blocking_tf decision_status created; do
            status=$(echo "$status" | xargs)
            blocking_tf=$(echo "$blocking_tf" | xargs)
            decision_status=$(echo "$decision_status" | xargs)
            created=$(echo "$created" | xargs)
            
            if [ "$status" = "READY" ]; then
                echo -e "  ${GREEN}✅ Status: $status${NC}"
            else
                echo -e "  ${RED}❌ Status: $status${NC}"
            fi
            [ -n "$blocking_tf" ] && echo -e "  Blocking TF: $blocking_tf"
            [ -n "$decision_status" ] && echo -e "  Decision: $decision_status"
            echo -e "  Created: $created"
        done
    else
        echo -e "  ${YELLOW}⚠️  Aucun run result trouvé${NC}"
    fi
    echo ""
    
    # 5. Vérifier les validations sur la dernière bougie fermée
    echo -e "${YELLOW}[5] Validations sur dernière bougie fermée:${NC}"
    VALIDATIONS=$(psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" "$DB_NAME" -t -c "
        WITH tf(tf, secs) AS (
            VALUES ('1m',60),('5m',300),('15m',900),('1h',3600),('4h',14400)
        ),
        targets AS (
            SELECT tf,
                   to_char(
                     to_timestamp(floor(extract(epoch from now())/secs)*secs - secs),
                     'YYYY-MM-DD HH24:MI:SS'
                   ) AS target_open_str
            FROM tf
        ),
        ok AS (
            SELECT a.symbol, t.tf, a.step, a.created_at
            FROM targets t
            JOIN mtf_audit a
              ON a.details->>'timeframe' = t.tf
             AND a.details->>'kline_time' = t.target_open_str
             AND UPPER(a.step) LIKE '%VALIDATION_SUCCESS%'
            WHERE a.symbol = '${SYMBOL}'
        )
        SELECT tf, step, created_at
        FROM ok
        ORDER BY tf;
    " 2>/dev/null || echo "")
    
    if [ -n "$VALIDATIONS" ] && [ "$(echo "$VALIDATIONS" | grep -v '^$' | wc -l)" -gt 0 ]; then
        COUNT=$(echo "$VALIDATIONS" | grep -v '^$' | wc -l)
        echo "$VALIDATIONS" | while IFS='|' read -r tf step created; do
            tf=$(echo "$tf" | xargs)
            step=$(echo "$step" | xargs)
            created=$(echo "$created" | xargs)
            echo -e "  ${GREEN}✅${NC} $tf: $step"
        done
        echo -e "  ${GREEN}Total: $COUNT/5 validations${NC}"
    else
        echo -e "  ${RED}❌ Aucune validation sur la dernière bougie fermée${NC}"
    fi
    echo ""
    
    # 6. Vérifier les erreurs dans les logs récents (si accessible)
    echo -e "${YELLOW}[6] Recherche d'erreurs dans les logs (dernières 50 lignes):${NC}"
    if command -v docker &> /dev/null; then
        LOGS=$(docker compose logs --tail 50 trading-app-php 2>/dev/null | grep -i "${SYMBOL}" | grep -iE "error|exception|failed|skip" | tail -5 || echo "")
        if [ -n "$LOGS" ]; then
            echo "$LOGS" | while IFS= read -r line; do
                echo -e "  ${RED}⚠️${NC} $line"
            done
        else
            echo -e "  ${GREEN}✅ Aucune erreur trouvée dans les logs récents${NC}"
        fi
    else
        echo -e "  ${YELLOW}⚠️  Docker non disponible, logs non vérifiés${NC}"
    fi
    echo ""
    
    # 7. Résumé et cause probable
    echo -e "${YELLOW}[7] Cause probable:${NC}"
    
    # Vérifier si kill switch OFF
    SWITCH_OFF=$(psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" "$DB_NAME" -t -c "
        SELECT COUNT(*) 
        FROM mtf_switch 
        WHERE switch_key LIKE '%${SYMBOL}%' 
          AND is_on = false
          AND (expires_at IS NULL OR expires_at > now());
    " 2>/dev/null | xargs || echo "0")
    
    if [ "$SWITCH_OFF" != "0" ] && [ "$SWITCH_OFF" != "" ]; then
        echo -e "  ${RED}❌ Kill switch OFF détecté${NC}"
    fi
    
    # Vérifier si pas de validations récentes
    VALID_COUNT=$(echo "$VALIDATIONS" | grep -v '^$' | wc -l | xargs || echo "0")
    if [ "$VALID_COUNT" -lt 5 ]; then
        echo -e "  ${YELLOW}⚠️  Validations incomplètes ($VALID_COUNT/5)${NC}"
    fi
    
    # Vérifier si pas d'order plan
    PLAN_COUNT=$(echo "$PLANS" | grep -v '^$' | wc -l | xargs || echo "0")
    if [ "$PLAN_COUNT" = "0" ]; then
        echo -e "  ${RED}❌ Aucun order plan créé${NC}"
    fi
    
    # Vérifier le statut
    if echo "$LAST_RUN" | grep -qi "READY"; then
        if [ "$PLAN_COUNT" = "0" ]; then
            echo -e "  ${YELLOW}⚠️  Statut READY mais pas d'order plan → problème dans TradeEntry${NC}"
        fi
    elif echo "$LAST_RUN" | grep -qiE "INVALID|ERROR|SKIPPED"; then
        echo -e "  ${RED}❌ Statut non-READY: $(echo "$LAST_RUN" | head -1 | cut -d'|' -f2 | xargs)${NC}"
    fi
    
    echo ""
    echo ""
done

echo -e "${BLUE}==========================================${NC}"
echo -e "${BLUE}Investigation terminée${NC}"
echo -e "${BLUE}==========================================${NC}"


