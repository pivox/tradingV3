#!/usr/bin/env bash

# codex resume 019ac03c-0cd2-7421-9034-b19f930cc423
# Intentionally no "set -e" to avoid aborting on partial failures
set -u

# Simple MTF diagnostics report
# Usage: ./mtf_report.sh [YYYY-MM-DD]

DATE="${1:-$(date +%F)}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOG_DIR="$ROOT_DIR/var/log"
DEV_LOG="$LOG_DIR/dev-$DATE.log"
MTF_LOG="$LOG_DIR/mtf-$DATE.log"

if [[ ! -f "$DEV_LOG" ]]; then
  echo "ERROR: dev log not found: $DEV_LOG" >&2
  exit 1
fi

if [[ ! -f "$MTF_LOG" ]]; then
  echo "ERROR: mtf log not found: $MTF_LOG" >&2
  exit 1
fi

rg_cmd() {
  if command -v rg >/dev/null 2>&1; then
    rg "$@"
  else
    # Rough fallback; caller should keep patterns simple
    grep -n "$1" "${@:2}"
  fi
}

echo "==== MTF Report for $DATE ===="
echo

echo "== HTTP /api/mtf/run =="
RUN_COUNT=$(rg_cmd "/api/mtf/run" "$DEV_LOG" 2>/dev/null | wc -l | tr -d ' ')
if [[ "$RUN_COUNT" -gt 0 ]]; then
  FIRST_RUN_TS=$(rg_cmd "/api/mtf/run" "$DEV_LOG" | head -n1 | sed -E 's/^\[([^]]+)\].*/\1/')
  LAST_RUN_TS=$(rg_cmd "/api/mtf/run" "$DEV_LOG" | tail -n1 | sed -E 's/^\[([^]]+)\].*/\1/')
else
  FIRST_RUN_TS="N/A"
  LAST_RUN_TS="N/A"
fi
echo "Total /api/mtf/run calls : $RUN_COUNT"
echo "First run                : $FIRST_RUN_TS"
echo "Last run                 : $LAST_RUN_TS"
echo

echo "== Runner executions (dev log) =="
RUNNER_START_LINES=$(rg_cmd "\\[MTF Runner\\] Starting execution" "$DEV_LOG" 2>/dev/null || true)
RUNNER_END_LINES=$(rg_cmd "\\[MTF Runner\\] Execution completed" "$DEV_LOG" 2>/dev/null || true)
RUNNER_START_COUNT=$(echo "$RUNNER_START_LINES" | grep -v '^$' | wc -l | tr -d ' ' || echo "0")
RUNNER_END_COUNT=$(echo "$RUNNER_END_LINES" | grep -v '^$' | wc -l | tr -d ' ' || echo "0")
echo "Runner starts            : $RUNNER_START_COUNT"
echo "Runner completions       : $RUNNER_END_COUNT"

if [[ "$RUNNER_END_COUNT" -gt 0 ]]; then
  echo
  echo "Execution time stats (seconds):"
  echo "$RUNNER_END_LINES" | LC_ALL=C awk '
    match($0, /execution_time=[0-9.]+/) {
      s = substr($0, RSTART, RLENGTH)
      sub(/^execution_time=/, "", s)
      t = s + 0
      if (count == 0 || t < min) min = t
      if (count == 0 || t > max) max = t
      sum += t
      count += 1
    }
    END {
      if (count > 0) {
        printf("count=%d avg=%.3f min=%.3f max=%.3f\n", count, sum/count, min, max)
      }
    }
  ' || true
fi

if [[ "$RUNNER_START_COUNT" -gt "$RUNNER_END_COUNT" ]]; then
  START_IDS=$(echo "$RUNNER_START_LINES" | sed -E 's/.*run_id=([^ ]+).*/\1/' | sort -u)
  END_IDS=$(echo "$RUNNER_END_LINES" | sed -E 's/.*run_id=([^ ]+).*/\1/' | sort -u)
  MISSING_IDS=$(comm -23 <(echo "$START_IDS") <(echo "$END_IDS") 2>/dev/null || true)
  if [[ -n "$MISSING_IDS" ]]; then
    echo
    echo "Runs started without completion (run_id):"
    echo "$MISSING_IDS"
  fi
fi
echo

echo "== MTF context (mtf log) =="
CONTEXT_REASON_COUNTS=$(rg_cmd "reason=" "$MTF_LOG" 2>/dev/null \
  | sed -E 's/.*reason=([^ ]*).*/\1/' \
  | sort | uniq -c | sort -nr || true)
TOTAL_CONTEXT_LINES=$(rg_cmd "reason=" "$MTF_LOG" 2>/dev/null | wc -l | tr -d ' ')
echo "Total context lines with reason= : $TOTAL_CONTEXT_LINES"
echo "Reason breakdown:"
echo "$CONTEXT_REASON_COUNTS"
echo

echo "== Context timeframe invalid (dev log) =="
CTX_INVALID_REASON_COUNTS=$(rg_cmd "\[MTF\] Context timeframe invalid" "$DEV_LOG" 2>/dev/null \
  | sed -E 's/.*invalid_reason=([^ ]*).*/\1/' \
  | sort | uniq -c | sort -nr || true)
CTX_INVALID_TF_COUNTS=$(rg_cmd "\[MTF\] Context timeframe invalid" "$DEV_LOG" 2>/dev/null \
  | sed -E 's/.*tf=([^ ]*).*/\1/' \
  | sort | uniq -c | sort -nr || true)
echo "Invalid context timeframe reasons:"
echo "$CTX_INVALID_REASON_COUNTS"
echo
echo "Invalid context by timeframe:"
echo "$CTX_INVALID_TF_COUNTS"
echo

echo "== Filters mandatory (context phase) =="
FILTER_COUNTS=$(rg_cmd "\[MTF\] Context filter check" "$DEV_LOG" 2>/dev/null \
  | sed -E 's/.*filter=([^ ]*) passed=([^ ]*).*/\1 \2/' \
  | sort | uniq -c | sort -nr || true)
echo "Filter pass/fail counts:"
echo "$FILTER_COUNTS"
echo

echo "== Database summary (mtf_audit / mtf_run / mtf_run_symbol) =="
if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
  DB_COUNTS=$(docker compose -f "$ROOT_DIR/docker-compose.yml" exec -T trading-app-db \
    psql -U postgres -d trading_app -t -c "
      SELECT 'mtf_run' AS table, count(*) AS c FROM mtf_run
      UNION ALL
      SELECT 'mtf_run_symbol', count(*) FROM mtf_run_symbol
      UNION ALL
      SELECT 'mtf_run_metric', count(*) FROM mtf_run_metric
      UNION ALL
      SELECT 'mtf_audit', count(*) FROM mtf_audit;
    " 2>/dev/null | sed 's/^ *//' || true)

  AUDIT_CAUSE_COUNTS=$(docker compose -f "$ROOT_DIR/docker-compose.yml" exec -T trading-app-db \
    psql -U postgres -d trading_app -t -c "
      SELECT cause, count(*) FROM mtf_audit
      GROUP BY cause
      ORDER BY count(*) DESC;
    " 2>/dev/null | sed 's/^ *//' || true)

  AUDIT_SYMBOL_DISTINCT=$(docker compose -f "$ROOT_DIR/docker-compose.yml" exec -T trading-app-db \
    psql -U postgres -d trading_app -t -c "
      SELECT count(DISTINCT symbol) FROM mtf_audit;
    " 2>/dev/null | tr -d ' ' || true)

  echo "Row counts:"
  echo "$DB_COUNTS"
  echo
  echo "mtf_audit causes:"
  echo "$AUDIT_CAUSE_COUNTS"
  echo
  echo "Distinct symbols in mtf_audit: $AUDIT_SYMBOL_DISTINCT"
else
  echo "Docker not available or not accessible; skipping DB summary."
fi

echo
echo "Report completed."
