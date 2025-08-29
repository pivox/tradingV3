#!/usr/bin/env bash
set -euo pipefail

: "${UVICORN_HOST:=0.0.0.0}"
: "${UVICORN_PORT:=8000}"
: "${UVICORN_WORKERS:=1}"
: "${FASTAPI_RELOAD:=0}"   # 0 = prod, 1 = dev reload

if [ "$FASTAPI_RELOAD" = "1" ]; then
  echo "🚀 Lancement FastAPI en mode DEV (reload)"
  # On restreint les dossiers surveillés et on exclut .venv
  exec python -m uvicorn app.main:app \
    --host "$UVICORN_HOST" --port "$UVICORN_PORT" \
    --reload --reload-dir /app/app --reload-dir /app/indicators \
    --reload-exclude "/app/.venv/*" --reload-exclude "/app/temporal/*"
else
  echo "🚀 Lancement FastAPI en mode PROD (sans reload)"
  exec python -m uvicorn app.main:app \
    --host "$UVICORN_HOST" --port "$UVICORN_PORT" --workers "$UVICORN_WORKERS"
fi
