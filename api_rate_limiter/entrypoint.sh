#!/usr/bin/env sh
set -eu

echo "[worker] Démarrage du Temporal worker..."
echo "[worker] TEMPORAL_ADDRESS: ${TEMPORAL_ADDRESS}"
echo "[worker] TASK_QUEUE_NAME: ${TASK_QUEUE_NAME}"

# Lancement direct du worker sans condition
exec python worker.py
