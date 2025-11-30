#!/usr/bin/env bash

# Analyse des symboles pour les runs MTF
# Usage: ./mtf_symbols_report.sh [YYYY-MM-DD]
#
# Le script agrège les infos de la table mtf_run_symbol
# (et mtf_run) pour un jour donné :
# - volumes et statuts par symbole
# - répartition par timeframe d'exécution / blocage
# - symboles les plus problématiques (échecs / erreurs)

set -u

DATE="${1:-$(date +%F)}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! command -v docker >/dev/null 2>&1 || ! docker info >/dev/null 2>&1; then
  echo "Docker indisponible ou injoignable, impossible d'interroger la base (trading-app-db)." >&2
  exit 1
fi

PSQL_BASE=(docker compose -f "$ROOT_DIR/docker-compose.yml" exec -T trading-app-db \
  psql -U postgres -d trading_app -t -A -F $'\t' -v ON_ERROR_STOP=0)

echo "==== Synthèse symboles MTF pour $DATE ===="
echo

echo "== Volumétrie globale =="
"${PSQL_BASE[@]}" -c "
  SELECT 'rows_total' AS metric, count(*)::text AS value
  FROM mtf_run_symbol s
  JOIN mtf_run r ON r.run_id = s.run_id
  WHERE r.started_at::date = '$DATE'
  UNION ALL
  SELECT 'symbols_distinct', count(DISTINCT s.symbol)::text
  FROM mtf_run_symbol s
  JOIN mtf_run r ON r.run_id = s.run_id
  WHERE r.started_at::date = '$DATE'
;" 2>/dev/null | sed 's/\t/ : /'
echo

echo "== Statuts des symboles (tous runs du jour) =="
"${PSQL_BASE[@]}" -c "
  SELECT s.status, count(*) AS c
  FROM mtf_run_symbol s
  JOIN mtf_run r ON r.run_id = s.run_id
  WHERE r.started_at::date = '$DATE'
  GROUP BY s.status
  ORDER BY c DESC, s.status;
"
echo

echo "== Répartition par timeframe d'exécution (execution_tf) =="
"${PSQL_BASE[@]}" -c "
  SELECT COALESCE(s.execution_tf, '<NULL>') AS execution_tf, count(*) AS c
  FROM mtf_run_symbol s
  JOIN mtf_run r ON r.run_id = s.run_id
  WHERE r.started_at::date = '$DATE'
  GROUP BY COALESCE(s.execution_tf, '<NULL>')
  ORDER BY c DESC, execution_tf;
"
echo

echo "== Répartition par timeframe de blocage (blocking_tf) =="
"${PSQL_BASE[@]}" -c "
  SELECT COALESCE(s.blocking_tf, '<NULL>') AS blocking_tf, count(*) AS c
  FROM mtf_run_symbol s
  JOIN mtf_run r ON r.run_id = s.run_id
  WHERE r.started_at::date = '$DATE'
  GROUP BY COALESCE(s.blocking_tf, '<NULL>')
  ORDER BY c DESC, blocking_tf;
"
echo

echo "== Répartition par signal_side =="
"${PSQL_BASE[@]}" -c "
  SELECT COALESCE(s.signal_side, '<NULL>') AS signal_side, count(*) AS c
  FROM mtf_run_symbol s
  JOIN mtf_run r ON r.run_id = s.run_id
  WHERE r.started_at::date = '$DATE'
  GROUP BY COALESCE(s.signal_side, '<NULL>')
  ORDER BY c DESC, signal_side;
"
echo

echo "== Top 50 symboles par nombre de lignes (tous statuts) =="
"${PSQL_BASE[@]}" -c "
  SELECT s.symbol,
         count(*) AS total,
         sum(CASE WHEN s.status = 'SUCCESS' THEN 1 ELSE 0 END) AS success,
         sum(CASE WHEN s.status <> 'SUCCESS' THEN 1 ELSE 0 END) AS not_success
  FROM mtf_run_symbol s
  JOIN mtf_run r ON r.run_id = s.run_id
  WHERE r.started_at::date = '$DATE'
  GROUP BY s.symbol
  ORDER BY total DESC, s.symbol
  LIMIT 50;
"
echo

echo "== Top 50 symboles problématiques (statut <> SUCCESS) =="
"${PSQL_BASE[@]}" -c "
  SELECT s.symbol,
         count(*) AS total_rows,
         sum(CASE WHEN s.status <> 'SUCCESS' THEN 1 ELSE 0 END) AS problematic_rows
  FROM mtf_run_symbol s
  JOIN mtf_run r ON r.run_id = s.run_id
  WHERE r.started_at::date = '$DATE'
  GROUP BY s.symbol
  HAVING sum(CASE WHEN s.status <> 'SUCCESS' THEN 1 ELSE 0 END) > 0
  ORDER BY problematic_rows DESC, total_rows DESC, s.symbol
  LIMIT 50;
"
echo

echo "== Principales erreurs (JSON error) pour le jour =="
"${PSQL_BASE[@]}" -c "
  SELECT s.symbol,
         s.status,
         left(error::text, 200) AS error_snippet,
         count(*) AS c
  FROM mtf_run_symbol s
  JOIN mtf_run r ON r.run_id = s.run_id
  WHERE r.started_at::date = '$DATE'
    AND s.error IS NOT NULL
  GROUP BY s.symbol, s.status, left(error::text, 200)
  ORDER BY c DESC
  LIMIT 20;
"
echo

echo "Rapport symboles terminé."

