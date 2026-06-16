#!/usr/bin/env sh
set -eu
PORT="${ORCHESTRATOR_PORT:-8099}"
echo "[python-orchestrator] starting API on port ${PORT}"
exec uvicorn app.main:app --host 0.0.0.0 --port "${PORT}"
