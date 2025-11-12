#!/usr/bin/env bash

set -euo pipefail

# Fixed connection settings (as requested)
DB_HOST="localhost"
DB_PORT="5433"
DB_USER="postgres"
DB_NAME="trading_app"
export PGPASSWORD="password"

# Only display symbols that have 5/5 validations (1m,5m,15m,1h,4h) on the latest closed candle per TF (UTC aligned)
echo "[INFO] Symbols READY across ALL TFs (5/5 validations on last closed candles)"

# SQL reproduced from MtfAuditRepository::getValidationSummaryWithInWindow (UTC aligned expected last open time)
read -r -d '' SQL_ALL <<'SQL'
WITH success_events AS (
  SELECT
    symbol,
    CASE
      WHEN UPPER(step) = '4H_VALIDATION_SUCCESS' THEN '4h'
      WHEN UPPER(step) = '1H_VALIDATION_SUCCESS' THEN '1h'
      WHEN UPPER(step) = '15M_VALIDATION_SUCCESS' THEN '15m'
      WHEN UPPER(step) = '5M_VALIDATION_SUCCESS' THEN '5m'
      WHEN UPPER(step) = '1M_VALIDATION_SUCCESS' THEN '1m'
      ELSE NULL
    END AS timeframe,
    -- event_ts = close time of the kline (prefer candle_open_ts + interval, fallback to details.kline_time)
    COALESCE(
      CASE
        WHEN UPPER(step) = '4H_VALIDATION_SUCCESS' AND candle_open_ts IS NOT NULL THEN candle_open_ts + INTERVAL '4 hours'
        WHEN UPPER(step) = '1H_VALIDATION_SUCCESS' AND candle_open_ts IS NOT NULL THEN candle_open_ts + INTERVAL '1 hour'
        WHEN UPPER(step) = '15M_VALIDATION_SUCCESS' AND candle_open_ts IS NOT NULL THEN candle_open_ts + INTERVAL '15 minutes'
        WHEN UPPER(step) = '5M_VALIDATION_SUCCESS'  AND candle_open_ts IS NOT NULL THEN candle_open_ts + INTERVAL '5 minutes'
        WHEN UPPER(step) = '1M_VALIDATION_SUCCESS'  AND candle_open_ts IS NOT NULL THEN candle_open_ts + INTERVAL '1 minute'
        ELSE NULL
      END,
      NULLIF(details->>'kline_time','')::timestamp AT TIME ZONE 'UTC'
    ) AS event_ts,
    created_at
  FROM mtf_audit
  WHERE UPPER(step) IN (
    '4H_VALIDATION_SUCCESS','1H_VALIDATION_SUCCESS','15M_VALIDATION_SUCCESS','5M_VALIDATION_SUCCESS','1M_VALIDATION_SUCCESS'
  )
), ranked AS (
  SELECT *, ROW_NUMBER() OVER (PARTITION BY symbol, timeframe ORDER BY event_ts DESC NULLS LAST, created_at DESC) AS rn
  FROM success_events
  WHERE timeframe IS NOT NULL AND event_ts IS NOT NULL
), agg AS (
  SELECT
    symbol,
    MAX(CASE WHEN timeframe='4h'  AND rn=1 THEN CASE WHEN event_ts = (to_timestamp(floor(extract(epoch from timezone('UTC', now()))/14400)*14400) AT TIME ZONE 'UTC') THEN 1 ELSE 0 END ELSE 0 END) AS in_window_4h,
    MAX(CASE WHEN timeframe='1h'  AND rn=1 THEN CASE WHEN event_ts = (to_timestamp(floor(extract(epoch from timezone('UTC', now()))/3600)*3600) AT TIME ZONE 'UTC')  THEN 1 ELSE 0 END ELSE 0 END) AS in_window_1h,
    MAX(CASE WHEN timeframe='15m' AND rn=1 THEN CASE WHEN event_ts = (to_timestamp(floor(extract(epoch from timezone('UTC', now()))/900)*900) AT TIME ZONE 'UTC')   THEN 1 ELSE 0 END ELSE 0 END) AS in_window_15m,
    MAX(CASE WHEN timeframe='5m'  AND rn=1 THEN CASE WHEN event_ts = (to_timestamp(floor(extract(epoch from timezone('UTC', now()))/300)*300) AT TIME ZONE 'UTC')   THEN 1 ELSE 0 END ELSE 0 END) AS in_window_5m,
    MAX(CASE WHEN timeframe='1m'  AND rn=1 THEN CASE WHEN event_ts = (to_timestamp(floor(extract(epoch from timezone('UTC', now()))/60)*60) AT TIME ZONE 'UTC')     THEN 1 ELSE 0 END ELSE 0 END) AS in_window_1m
  FROM ranked
  GROUP BY symbol
)
SELECT symbol
FROM agg
WHERE in_window_4h=1 AND in_window_1h=1 AND in_window_15m=1 AND in_window_5m=1 AND in_window_1m=1
ORDER BY symbol;
SQL

SYMS=$(psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" "$DB_NAME" -At -c "$SQL_ALL" 2>/dev/null || true)

# Print symbols on a single line, comma-separated
if [ -n "$SYMS" ]; then
  echo "$SYMS" | paste -sd, -
else
  echo ""
fi
