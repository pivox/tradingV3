#!/usr/bin/env sh
set -eu
echo "[mtf-workers] starting Temporal worker"
exec python worker.py
