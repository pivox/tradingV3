#!/usr/bin/env bash
set -euo pipefail

base="${1:-http://localhost:8000}"

echo "[*] GET /"
curl -s "$base/" && echo

echo "[*] GET /health"
curl -s "$base/health" && echo

echo "[*] POST /rsi"
cat <<JSON | curl -s -X POST "$base/rsi" -H "Content-Type: application/json" -d @-
{
  "contract": "BTCUSDT",
  "timeframe": "5m",
  "length": 14,
  "n": 50,
  "klines": [
    {"timestamp": 1700000000,"open":1,"high":2,"low":0.5,"close":1.1,"volume":10},
    {"timestamp": 1700000300,"open":1.1,"high":2,"low":0.5,"close":1.2,"volume":10},
    {"timestamp": 1700000600,"open":1.2,"high":2,"low":0.5,"close":1.3,"volume":10},
    {"timestamp": 1700000900,"open":1.3,"high":2,"low":0.5,"close":1.35,"volume":10},
    {"timestamp": 1700001200,"open":1.35,"high":2,"low":0.5,"close":1.34,"volume":10},
    {"timestamp": 1700001500,"open":1.34,"high":2,"low":0.5,"close":1.36,"volume":10},
    {"timestamp": 1700001800,"open":1.36,"high":2,"low":0.5,"close":1.40,"volume":10},
    {"timestamp": 1700002100,"open":1.40,"high":2,"low":0.5,"close":1.41,"volume":10},
    {"timestamp": 1700002400,"open":1.41,"high":2,"low":0.5,"close":1.39,"volume":10},
    {"timestamp": 1700002700,"open":1.39,"high":2,"low":0.5,"close":1.45,"volume":10},
    {"timestamp": 1700003000,"open":1.45,"high":2,"low":0.5,"close":1.44,"volume":10},
    {"timestamp": 1700003300,"open":1.44,"high":2,"low":0.5,"close":1.46,"volume":10},
    {"timestamp": 1700003600,"open":1.46,"high":2,"low":0.5,"close":1.48,"volume":10},
    {"timestamp": 1700003900,"open":1.48,"high":2,"low":0.5,"close":1.47,"volume":10},
    {"timestamp": 1700004200,"open":1.47,"high":2,"low":0.5,"close":1.49,"volume":10}
  ]
}
JSON
echo

echo "[*] POST /validate"
cat <<JSON | curl -s -X POST "$base/validate" -H "Content-Type: application/json" -d @-
{
  "contract": "BTCUSDT",
  "timeframe": "5m",
  "klines": [
    {"timestamp": 1700000000,"open":1,"high":2,"low":0.5,"close":1.1,"volume":10},
    {"timestamp": 1700000300,"open":1.1,"high":2,"low":0.5,"close":1.2,"volume":10},
    {"timestamp": 1700000600,"open":1.2,"high":2,"low":0.5,"close":1.3,"volume":10},
    {"timestamp": 1700000900,"open":1.3,"high":2,"low":0.5,"close":1.35,"volume":10},
    {"timestamp": 1700001200,"open":1.35,"high":2,"low":0.5,"close":1.34,"volume":10},
    {"timestamp": 1700001500,"open":1.34,"high":2,"low":0.5,"close":1.36,"volume":10},
    {"timestamp": 1700001800,"open":1.36,"high":2,"low":0.5,"close":1.40,"volume":10},
    {"timestamp": 1700002100,"open":1.40,"high":2,"low":0.5,"close":1.41,"volume":10},
    {"timestamp": 1700002400,"open":1.41,"high":2,"low":0.5,"close":1.39,"volume":10},
    {"timestamp": 1700002700,"open":1.39,"high":2,"low":0.5,"close":1.45,"volume":10},
    {"timestamp": 1700003000,"open":1.45,"high":2,"low":0.5,"close":1.44,"volume":10},
    {"timestamp": 1700003300,"open":1.44,"high":2,"low":0.5,"close":1.46,"volume":10},
    {"timestamp": 1700003600,"open":1.46,"high":2,"low":0.5,"close":1.48,"volume":10},
    {"timestamp": 1700003900,"open":1.48,"high":2,"low":0.5,"close":1.47,"volume":10},
    {"timestamp": 1700004200,"open":1.47,"high":2,"low":0.5,"close":1.49,"volume":10}
  ]
}
JSON
echo
