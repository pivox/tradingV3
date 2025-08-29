#!/usr/bin/env sh
set -eu
echo "[worker] Starting SymfonyCron4h worker..."
exec python worker.py
