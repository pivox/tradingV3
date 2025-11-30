#!/usr/bin/env bash

set -euo pipefail

# Configuration DB
DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-5433}"
DB_USER="${DB_USER:-postgres}"
DB_NAME="${DB_NAME:-trading_app}"
export PGPASSWORD="${PGPASSWORD:-password}"

SYMBOLS=("GLMUSDT" "VELODROMEUSDT" "LTCUSDT" "MELANIAUSDT" "FILUSDT")

echo "=========================================="
echo "Vérification des filters_mandatory"
echo "=========================================="
echo ""
echo "Les filters_mandatory requis sont :"
echo "  - rsi_lt_70 (RSI < 73 pour regular mode)"
echo "  - adx_min_for_trend"
echo "  - pullback_confirmed_ma9_21"
echo "  - pullback_confirmed_vwap"
echo "  - price_lte_ma21_plus_k_atr"
echo ""
echo "Si un seul de ces filtres échoue, aucun order plan n'est créé."
echo ""

for SYMBOL in "${SYMBOLS[@]}"; do
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "🔍 SYMBOLE: ${SYMBOL}"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""

    # Vérifier les audits récents pour voir si filters_mandatory a échoué
    echo "📋 Audits liés aux filtres (24h):"
    FILTER_AUDITS=$(psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" "$DB_NAME" -t -c "
        SELECT step, details, created_at
        FROM mtf_audit
        WHERE symbol='${SYMBOL}'
          AND created_at > now() - interval '24 hours'
          AND (step ILIKE '%FILTER%' OR step ILIKE '%EXECUTION%' OR details::text ILIKE '%filters%')
        ORDER BY created_at DESC
        LIMIT 10;
    " 2>/dev/null || echo "")

    if [ -n "$FILTER_AUDITS" ] && [ "$(echo "$FILTER_AUDITS" | grep -v '^$' | wc -l)" -gt 0 ]; then
        echo "$FILTER_AUDITS"
    else
        echo "  ⚠️  Aucun audit de filtre trouvé"
    fi
    echo ""

    # Vérifier les logs d'ExecutionSelector dans les logs Docker
    echo "📋 Logs ExecutionSelector (dernières 100 lignes):"
    if command -v docker &> /dev/null; then
        EXEC_LOGS=$(docker compose logs --tail 100 trading-app-php 2>/dev/null | grep -i "${SYMBOL}" | grep -iE "executionselector|filters_mandatory|execution_tf.*NONE" | tail -10 || echo "")
        if [ -n "$EXEC_LOGS" ]; then
            echo "$EXEC_LOGS"
        else
            echo "  ⚠️  Aucun log ExecutionSelector trouvé"
        fi
    else
        echo "  ⚠️  Docker non disponible"
    fi
    echo ""

    # Vérifier les order_journey logs dans les audits
    echo "📋 Order Journey (24h):"
    JOURNEY=$(psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" "$DB_NAME" -t -c "
        SELECT step, details->>'reason' as reason, details->>'execution_tf' as tf, created_at
        FROM mtf_audit
        WHERE symbol='${SYMBOL}'
          AND created_at > now() - interval '24 hours'
          AND (step ILIKE '%order_journey%' OR step ILIKE '%TRADE_ENTRY%' OR step ILIKE '%TRADING_DECISION%')
        ORDER BY created_at DESC
        LIMIT 20;
    " 2>/dev/null || echo "")

    if [ -n "$JOURNEY" ] && [ "$(echo "$JOURNEY" | grep -v '^$' | wc -l)" -gt 0 ]; then
        echo "$JOURNEY" | while IFS='|' read -r step reason tf created; do
            step=$(echo "$step" | xargs)
            reason=$(echo "$reason" | xargs)
            tf=$(echo "$tf" | xargs)
            created=$(echo "$created" | xargs)

            if echo "$reason" | grep -qiE "blocked|skip|failed|filters"; then
                echo "  ❌ $step | Reason: $reason | TF: $tf | $created"
            else
                echo "  ℹ️  $step | Reason: $reason | TF: $tf | $created"
            fi
        done
    else
        echo "  ⚠️  Aucun order journey trouvé"
    fi
    echo ""

    # Vérifier le statut READY et trading_decision
    echo "📋 Statut MTF et Trading Decision:"
    STATUS=$(psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" "$DB_NAME" -t -c "
        SELECT
            status,
            trading_decision->>'status' as decision_status,
            trading_decision->>'reason' as decision_reason,
            blocking_tf,
            created_at
        FROM mtf_run_result
        WHERE symbol='${SYMBOL}'
        ORDER BY created_at DESC
        LIMIT 1;
    " 2>/dev/null || echo "")

    if [ -n "$STATUS" ] && [ "$(echo "$STATUS" | grep -v '^$' | wc -l)" -gt 0 ]; then
        echo "$STATUS" | while IFS='|' read -r status decision_status decision_reason blocking_tf created; do
            status=$(echo "$status" | xargs)
            decision_status=$(echo "$decision_status" | xargs)
            decision_reason=$(echo "$decision_reason" | xargs)
            blocking_tf=$(echo "$blocking_tf" | xargs)
            created=$(echo "$created" | xargs)

            echo "  Status: $status"
            echo "  Decision: $decision_status"
            [ -n "$decision_reason" ] && echo "  Reason: $decision_reason"
            [ -n "$blocking_tf" ] && echo "  Blocking TF: $blocking_tf"
            echo "  Created: $created"
        done
    else
        echo "  ⚠️  Aucun run result trouvé"
    fi
    echo ""
    echo ""
done

echo "=========================================="
echo "Résumé"
echo "=========================================="
echo ""
echo "Si vous voyez 'filters_mandatory failed' ou 'execution_tf: NONE',"
echo "cela signifie qu'un des filtres obligatoires a échoué :"
echo ""
echo "  - rsi_lt_70 : RSI doit être < 73 (regular mode)"
echo "  - adx_min_for_trend : ADX 1h doit être suffisant"
echo "  - pullback_confirmed_ma9_21 : MA9 doit croiser MA21"
echo "  - pullback_confirmed_vwap : Prix doit être proche du VWAP"
echo "  - price_lte_ma21_plus_k_atr : Prix <= MA21 + 2×ATR"
echo ""
echo "Pour vérifier les valeurs exactes, utilisez l'API MTF run avec dry_run=true"
echo "ou examinez les indicateurs techniques dans la base de données."
echo ""


