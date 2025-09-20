#!/usr/bin/env sh
set -eu
echo "[worker] Starting Symfony Cron  worker..."
exec python worker.py
