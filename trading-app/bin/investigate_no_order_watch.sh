#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
CONSOLE="php $PROJECT_ROOT/bin/console"

usage() {
  cat <<EOF
Usage: $(basename "$0") --symbols=SYM1,SYM2 [--since-minutes=N] [--format=table|json] [--interval=120]

Runs investigate:no-order in a loop every N seconds (default 120s).

Examples:
  $(basename "$0") --symbols=GIGGLEUSDT,VELODROMEUSDT --since-minutes=30 --format=table --interval=120
EOF
}

SYMBOLS=""
SINCE_MINUTES="10"
FORMAT="table"
INTERVAL_SEC="120"

for arg in "$@"; do
  case "$arg" in
    --symbols=*) SYMBOLS="${arg#*=}" ;;
    --since-minutes=*) SINCE_MINUTES="${arg#*=}" ;;
    --format=*) FORMAT="${arg#*=}" ;;
    --interval=*) INTERVAL_SEC="${arg#*=}" ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown arg: $arg" >&2; usage; exit 1 ;;
  esac
done

if [[ -z "$SYMBOLS" ]]; then
  echo "Error: --symbols=SYM1,SYM2 is required" >&2
  usage
  exit 1
fi

while true; do
  clear || true
  echo "[investigate:no-order] $(date -u +"%Y-%m-%d %H:%M:%S") UTC"
  echo "Symbols: $SYMBOLS | Since: ${SINCE_MINUTES}m | Format: $FORMAT"
  echo
  $CONSOLE investigate:no-order --symbols="$SYMBOLS" --since-minutes="$SINCE_MINUTES" --format="$FORMAT" || true
  echo
  echo "Next run in ${INTERVAL_SEC}s... (Ctrl+C to stop)"
  sleep "$INTERVAL_SEC"
done

