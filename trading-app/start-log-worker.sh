#!/bin/bash

# Script de démarrage du worker de logs Temporal
# Usage: ./start-log-worker.sh [--daemon]

set -e

echo "[LOG-WORKER] Starting Log Worker..."

# Vérifier que Temporal est accessible
echo "[LOG-WORKER] Checking Temporal connection..."
if ! php bin/console app:test-temporal > /dev/null 2>&1; then
    echo "[LOG-WORKER] ERROR: Cannot connect to Temporal server"
    echo "[LOG-WORKER] Make sure Temporal is running on ${TEMPORAL_ADDRESS:-temporal-grpc:7233}"
    exit 1
fi

echo "[LOG-WORKER] Temporal connection OK"

# Démarrer le worker
if [ "$1" = "--daemon" ]; then
    echo "[LOG-WORKER] Starting in daemon mode..."
    exec php bin/console app:log-worker --daemon
else
    echo "[LOG-WORKER] Starting in normal mode..."
    exec php bin/console app:log-worker
fi


