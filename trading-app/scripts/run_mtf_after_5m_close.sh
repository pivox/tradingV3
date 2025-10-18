#!/bin/bash

set -euo pipefail

# Script: Exécuter mtf:run juste après chaque clôture 5m
# - Attend la clôture de la bougie 5m
# - Lance la commande avec --force-timeframe-check pour éviter les arrêts "TOO_RECENT"/grace
#
# Usage:
#   scripts/run_mtf_after_5m_close.sh [--symbols "BTCUSDT,ETHUSDT"] [--workers 1] [--dry-run 1|0] [--force-run]

cd "$(dirname "$0")/.."

SYMBOLS=""
WORKERS="1"
DRY_RUN="1"
FORCE_RUN="0"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --symbols)
      SYMBOLS="$2"; shift 2;;
    --workers)
      WORKERS="$2"; shift 2;;
    --dry-run)
      DRY_RUN="$2"; shift 2;;
    --force-run)
      FORCE_RUN="1"; shift 1;;
    *) echo "Arg inconnu: $1"; exit 1;;
  esac
done

wait_until_next_5m_close() {
  # Calcule seconds jusqu'à la prochaine borne 5m
  local now next remainder wait
  now=$(date +%s)
  remainder=$(( now % 300 ))
  if [[ $remainder -eq 0 ]]; then
    wait=1
  else
    wait=$(( 300 - remainder + 1 ))
  fi
  sleep "$wait"
}

run_once_after_close() {
  local cmd=(php bin/console mtf:run --dry-run="$DRY_RUN" --workers="$WORKERS" --force-timeframe-check)
  if [[ -n "$SYMBOLS" ]]; then
    cmd+=(--symbols="$SYMBOLS")
  fi
  if [[ "$FORCE_RUN" == "1" ]]; then
    cmd+=(--force-run)
  fi
  "${cmd[@]}"
}

wait_until_next_5m_close
run_once_after_close

exit $?



