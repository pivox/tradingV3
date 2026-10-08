#!/usr/bin/env bash
# One profile cell x one frozen public dataset -> Paper replay -> certified trade export.
#
#   scripts/backtest-profile.sh --venue=okx|hyperliquid \
#       --mode-id=day_trading --mode-version=1.1.0 \
#       --setup-id=day_trading.trend_continuation.long --setup-version=1.1.0 --side=long \
#       [--configuration=/abs/override.json]  # Paper configuration snapshot (default: {"strategy":{}})
#       [--dataset=/abs/dataset-dir]           # default: the frozen 24h capture of the venue
#       [--out=/abs/result-dir]                # default: $PRIVATE_ROOT/backtests/<utc>-<venue>-<setup>-<side>
#       [--port=5460] [--keep-db] [--refresh-receipt] [--cpus=2] [--memory=2g]
#
# Engine: the same path as the #132 certification campaign, one cell at a time:
#   dataset receipt (cached per dataset) -> app:paper-market:runtime-check -> app:paper-market:replay
#   -> docs/handbook/reports/queries/bad-trades-baseline-v2.sql (position_trade_analysis_v2 is the
#   sole trade authority) -> trading-app/scripts/bad_trades_baseline.py.
# The replay runs in a throw-away postgres:15 container (backtest-pg-*) that this script creates
# and removes; it never touches cc_postgres, the live trading_paper or any trading-app database.
# Strategy behaviour comes from the config layers under trading-app/config/trading/ of THIS tree;
# edit them, re-run, compare the export. Nothing here writes to the dataset.
set -euo pipefail

PHP="${PHP:-/home/haythem/.config/herd-lite/bin/php}"
PRIVATE_ROOT="${PRIVATE_ROOT:-/home/haythem/workspace/perso/tradingV3-private}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
APP="$ROOT/trading-app"
SQL="$ROOT/docs/handbook/reports/queries/bad-trades-baseline-v2.sql"
REPORT="$APP/scripts/bad_trades_baseline.py"
DATASET_OKX="$PRIVATE_ROOT/paper-market-data/representative-okx-20261002t072026z-run-44d4cc5631f0d5f85d322fe6275663bb-attempt-002-mainnet"
DATASET_HL="$PRIVATE_ROOT/paper-market-data/representative-hyperliquid-20261002t081009z-run-b56b22b7a88070e36fbae1bb34ffe09b-attempt-001-mainnet"
RECEIPTS="$PRIVATE_ROOT/backtest-receipts"
CHILD_MEMORY=3072M

VENUE="" MODE_ID="" MODE_VERSION="" SETUP_ID="" SETUP_VERSION="" SIDE=""
CONFIGURATION="" DATASET="" OUT="" PORT=5460 KEEP_DB=0 REFRESH_RECEIPT=0 CPUS=2 MEMORY=2g
for arg in "$@"; do
  case "$arg" in
    --venue=*) VENUE="${arg#*=}" ;;
    --mode-id=*) MODE_ID="${arg#*=}" ;;
    --mode-version=*) MODE_VERSION="${arg#*=}" ;;
    --setup-id=*) SETUP_ID="${arg#*=}" ;;
    --setup-version=*) SETUP_VERSION="${arg#*=}" ;;
    --side=*) SIDE="${arg#*=}" ;;
    --configuration=*) CONFIGURATION="${arg#*=}" ;;
    --dataset=*) DATASET="${arg#*=}" ;;
    --out=*) OUT="${arg#*=}" ;;
    --port=*) PORT="${arg#*=}" ;;
    --keep-db) KEEP_DB=1 ;;
    --refresh-receipt) REFRESH_RECEIPT=1 ;;
    --cpus=*) CPUS="${arg#*=}" ;;
    --memory=*) MEMORY="${arg#*=}" ;;
    -h|--help) sed -n '2,19p' "$0"; exit 0 ;;
    *) echo "unknown option: $arg" >&2; exit 2 ;;
  esac
done

die() { echo "backtest-profile: $*" >&2; exit 1; }
log() { printf '%s backtest-profile: %s\n' "$(date -u +%H:%M:%S)" "$*"; }
elapsed() { local s=$1; printf '%dm%02ds' $(( (SECONDS - s) / 60 )) $(( (SECONDS - s) % 60 )); }

case "$VENUE" in
  okx) DATASET="${DATASET:-$DATASET_OKX}" ;;
  hyperliquid) DATASET="${DATASET:-$DATASET_HL}" ;;
  *) die "--venue must be okx or hyperliquid" ;;
esac
[[ "$MODE_ID" =~ ^[a-z_]+$ && "$MODE_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "--mode-id and --mode-version are required"
[[ "$SETUP_ID" =~ ^[a-z_]+\.[a-z_]+\.(long|short)$ && "$SETUP_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "--setup-id and --setup-version are required"
[[ "$SIDE" = long || "$SIDE" = short ]] || die "--side must be long or short"
[[ "$PORT" =~ ^[0-9]{4,5}$ ]] || die "invalid --port"
[[ "$DATASET" = /* && -f "$DATASET/manifest.json" && -f "$DATASET/events.ndjson" ]] || die "dataset not found: $DATASET"
[[ -x "$PHP" ]] || die "php not found: $PHP"
[[ -f "$APP/bin/console" && -f "$SQL" && -f "$REPORT" ]] || die "run from the worktree that holds trading-app/, docs/ and scripts/"
command -v docker >/dev/null || die "docker is required"
grep -q '"state": *"complete"' "$DATASET/manifest.json" || grep -q '"state":"complete"' "$DATASET/manifest.json" || die "dataset manifest is not complete: $DATASET"
DATASET_ID="$(basename "$DATASET")"

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
SLUG="$(openssl rand -hex 3)"
RUN_ID="bt-$STAMP-$VENUE-$SLUG"
OUT="${OUT:-$PRIVATE_ROOT/backtests/$STAMP-$VENUE-$SETUP_ID-$SIDE}"
[[ "$OUT" = /* ]] || die "--out must be absolute"
[[ ! -e "$OUT" ]] || die "output directory already exists: $OUT"
umask 077
mkdir -p "$OUT" "$RECEIPTS"
chmod 700 "$OUT" "$RECEIPTS"

# Paper configuration snapshot: private copy, 0600, no symlink component (replay preparation rules).
if [[ -n "$CONFIGURATION" ]]; then
  [[ "$CONFIGURATION" = /* && -f "$CONFIGURATION" ]] || die "--configuration must be an absolute existing file"
  python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); assert isinstance(d,dict) and d, "root object required"' "$CONFIGURATION" \
    || die "--configuration is not a JSON object"
  cp "$CONFIGURATION" "$OUT/configuration.json"
else
  printf '{"strategy":{}}\n' >"$OUT/configuration.json"
fi
chmod 600 "$OUT/configuration.json"

# One throw-away database for this run.
while (exec 3<>"/dev/tcp/127.0.0.1/$PORT") 2>/dev/null; do PORT=$(( PORT + 1 )); (( PORT < 65535 )) || die "no free port"; done
DB_NAME="backtest-pg-$SLUG"
DB_VOLUME="$DB_NAME-data"
docker inspect "$DB_NAME" >/dev/null 2>&1 && die "container $DB_NAME already exists"
docker volume inspect "$DB_VOLUME" >/dev/null 2>&1 && die "volume $DB_VOLUME already exists"
PW_FILE="$OUT/pg.pw"
openssl rand -hex 24 >"$PW_FILE"
chmod 600 "$PW_FILE"
DATABASE_URL="postgresql://postgres:$(cat "$PW_FILE")@127.0.0.1:$PORT/trading_paper?serverVersion=15&charset=utf8"

cleanup() {
  local status=$?
  if [[ "$KEEP_DB" = 1 ]]; then
    log "database kept: container=$DB_NAME port=127.0.0.1:$PORT (password: $PW_FILE) — remove with: docker rm -f $DB_NAME && docker volume rm $DB_VOLUME"
  else
    docker rm -f "$DB_NAME" >/dev/null 2>&1 || true
    docker volume rm "$DB_VOLUME" >/dev/null 2>&1 || true
    rm -f "$PW_FILE"
    log "database $DB_NAME removed"
  fi
  (( status == 0 )) || log "FAILED (exit $status) — logs in $OUT"
}
trap cleanup EXIT

T0=$SECONDS
log "run_id=$RUN_ID venue=$VENUE cell=$MODE_ID@$MODE_VERSION/$SETUP_ID@$SETUP_VERSION/$SIDE"
log "dataset=$DATASET_ID"
log "results -> $OUT"

docker volume create "$DB_VOLUME" >/dev/null
docker run -d --name "$DB_NAME" -p "127.0.0.1:$PORT:5432" --cpus="$CPUS" --memory="$MEMORY" \
  -v "$DB_VOLUME:/var/lib/postgresql/data" \
  -e POSTGRES_PASSWORD_FILE=/run/secrets/pg.pw -v "$PW_FILE:/run/secrets/pg.pw:ro" \
  -e POSTGRES_DB=trading_paper postgres:15 \
  -c shared_buffers=512MB -c max_wal_size=4GB -c checkpoint_timeout=15min \
  -c fsync=on -c synchronous_commit=on -c full_page_writes=on >/dev/null
for _ in $(seq 1 180); do
  docker exec "$DB_NAME" pg_isready -h 127.0.0.1 -U postgres -d trading_paper >/dev/null 2>&1 && break
  sleep 1
done
docker exec "$DB_NAME" pg_isready -h 127.0.0.1 -U postgres -d trading_paper >/dev/null 2>&1 || die "database $DB_NAME not ready"
(
  cd "$APP"
  DATABASE_URL="$DATABASE_URL" PAPER_EXECUTION_ENABLED=0 \
    "$PHP" -d memory_limit=1G bin/console doctrine:migrations:migrate --env=prod --no-interaction >"$OUT/migrations.log" 2>&1
) || die "migrations failed, see $OUT/migrations.log"
log "database ready on 127.0.0.1:$PORT ($(elapsed "$T0"))"

# Dataset receipt: the one full baseline verification, cached per dataset. It binds the dataset
# bytes, its directory identity and the replay code tree (src/Trading/Paper/{Dataset,MarketData,
# Hyperliquid,Okx}); config edits do not invalidate it, code edits there do.
RECEIPT="$RECEIPTS/$DATASET_ID.json"
issue_receipt() {
  local t=$SECONDS
  rm -f "$RECEIPT"
  log "verifying dataset (receipt) — ~20 min per GB of events"
  ( cd "$APP" && PAPER_EXECUTION_ENABLED=0 "$PHP" -d memory_limit=$CHILD_MEMORY bin/console app:paper-market:dataset-receipt \
      --env=prod --no-interaction --dataset="$DATASET" --receipt="$RECEIPT" ) | tee "$OUT/receipt.out" \
    | grep -q '"issued":true' || die "dataset receipt failed, see $OUT/receipt.out"
  log "receipt issued ($(elapsed "$t"))"
}
[[ "$REFRESH_RECEIPT" = 1 ]] && rm -f "$RECEIPT"
[[ -f "$RECEIPT" ]] && log "receipt cached: $RECEIPT" || issue_receipt

CELL_ARGS=(
  --dataset="$DATASET"
  --configuration="$OUT/configuration.json"
  --mode-id="$MODE_ID" --mode-version="$MODE_VERSION"
  --setup-id="$SETUP_ID" --setup-version="$SETUP_VERSION"
  --side="$SIDE"
  --run-id="$RUN_ID"
  --no-interaction --env=prod
  --dataset-receipt="$RECEIPT"
)
paper_cell() {  # <command> <log>: PAPER_EXECUTION_ENABLED=1 only for these two commands, as the campaign executor does
  ( cd "$APP" && DATABASE_URL="$DATABASE_URL" PAPER_EXECUTION_ENABLED=1 \
      "$PHP" -d memory_limit=$CHILD_MEMORY bin/console "$1" "${CELL_ARGS[@]}" ) >"$OUT/$2" 2>&1
}

T1=$SECONDS
if ! paper_cell app:paper-market:runtime-check runtime-check.out; then
  if grep -q 'receipt' "$OUT/runtime-check.out"; then
    log "receipt rejected ($(grep -o '"blocker":"[a-z_]*"' "$OUT/runtime-check.out" || true)) — reissuing once"
    issue_receipt
    paper_cell app:paper-market:runtime-check runtime-check.out || die "readiness check failed: $(cat "$OUT/runtime-check.out")"
  else
    die "readiness check failed: $(cat "$OUT/runtime-check.out")"
  fi
fi
grep -q '"ready":true' "$OUT/runtime-check.out" || die "not ready: $(cat "$OUT/runtime-check.out")"
grep -q '"baseline_eligible":true' "$OUT/runtime-check.out" || log "WARNING: cell is not baseline_eligible (reference only)"
log "readiness ok ($(elapsed "$T1"))"

T2=$SECONDS
log "replaying $(grep -o '"event_count": *[0-9]*' "$DATASET/manifest.json" | grep -o '[0-9]*$') events — this is the long part"
paper_cell app:paper-market:replay replay.out || die "replay failed: $(tail -n 3 "$OUT/replay.out")"
grep -q '"completed":true' "$OUT/replay.out" || die "replay did not complete: $(tail -n 3 "$OUT/replay.out")"
log "replay completed ($(elapsed "$T2")): $(grep -o '"timings":{[^}]*}' "$OUT/replay.out")"

# Trade export: the certification query, unchanged.
docker exec -i "$DB_NAME" psql -U postgres -d trading_paper -q \
  -v from_ts='1970-01-01 00:00:00+00' -v to_ts='9999-12-31 23:59:59+00' -f - <"$SQL" >"$OUT/export.csv"
TRADES=$(( $(wc -l <"$OUT/export.csv") - 1 ))
if python3 "$REPORT" --input "$OUT/export.csv" --output-md "$OUT/export.md" --output-json "$OUT/export.json" >"$OUT/report.log" 2>&1; then
  log "report: $OUT/export.md"
else
  log "report script exited $? (normal with 0 trades) — see $OUT/report.log"
fi
# Non-authoritative: what the replay wrote, to explain a zero.
docker exec "$DB_NAME" psql -U postgres -d trading_paper -At -F ' ' \
  -c "select relname, n_live_tup from pg_stat_user_tables where n_live_tup > 0 order by n_live_tup desc limit 20" \
  >"$OUT/table-rows.txt" 2>/dev/null || true

{
  echo "run_id=$RUN_ID"
  echo "venue=$VENUE dataset=$DATASET_ID"
  echo "cell=$MODE_ID@$MODE_VERSION $SETUP_ID@$SETUP_VERSION $SIDE"
  echo "configuration_sha256=$(sha256sum "$OUT/configuration.json" | cut -d' ' -f1)"
  echo "config_hash=$(grep -o '"config_hash":"[^"]*"' "$OUT/runtime-check.out" | head -1 | cut -d'"' -f4)"
  echo "condition_catalog_hash=$(grep -o '"condition_catalog_hash":"[^"]*"' "$OUT/runtime-check.out" | head -1 | cut -d'"' -f4)"
  echo "trades=$TRADES"
  echo "wall_clock=$(elapsed "$T0")"
} >"$OUT/summary.txt"
log "certified trades (position_trade_analysis_v2): $TRADES — export: $OUT/export.csv"
log "done in $(elapsed "$T0"); summary: $OUT/summary.txt"
