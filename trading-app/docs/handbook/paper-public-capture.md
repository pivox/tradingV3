# Resilient public Paper capture

The public capture runtime is read-only. Keep `PAPER_EXECUTION_ENABLED=0`; the commands below never authorize an authenticated mainnet write.

## Why a capture restarts

OKX exposes public trade history. After a WebSocket interruption, the runtime pages backward until it proves an exact overlap with the last durable frontier. It remains fail-closed if the overlap cannot be proven within the finite recovery envelope.

Hyperliquid does not expose credential-free historical public trades. Any live trade gap is therefore unrecoverable for certification. The supervisor preserves the failed dataset as incomplete and starts a new dataset from zero. It never stitches attempts together.

## Detached macOS launch

Create an operator log directory outside `PAPER_MARKET_DATA_ROOT`, then launch one bounded supervisor per venue from `trading-app`:

```bash
install -d -m 700 "$PWD/var/log/paper-capture"

nohup caffeinate -dimsu env \
  APP_ENV=prod APP_DEBUG=0 APP_SECRET=paper-local \
  REDIS_URL=redis://127.0.0.1:6379 \
  REDIS_ORDER_WATCH_CHANNEL=watcher:order:watch \
  DEFAULT_URI=http://localhost XDEBUG_MODE=off LOCK_DSN=flock \
  PAPER_EXECUTION_ENABLED=0 \
  PAPER_MARKET_ACQUISITION_ENABLED=1 \
  HYPERLIQUID_PAPER_PUBLIC_ACQUISITION_ENABLED=0 \
  PAPER_MARKET_DATA_ROOT=/absolute/private/paper-market-data \
  php bin/console app:paper-market:public-capture-supervise \
    --venue=okx \
    --dataset-prefix=representative-okx-YYYYMMDD \
    --duration-sec=86400 \
    --attempts=8 \
    --no-interaction \
  >"$PWD/var/log/paper-capture/okx-YYYYMMDD.log" 2>&1 </dev/null &

nohup caffeinate -dimsu env \
  APP_ENV=prod APP_DEBUG=0 APP_SECRET=paper-local \
  REDIS_URL=redis://127.0.0.1:6379 \
  REDIS_ORDER_WATCH_CHANNEL=watcher:order:watch \
  DEFAULT_URI=http://localhost XDEBUG_MODE=off LOCK_DSN=flock \
  PAPER_EXECUTION_ENABLED=0 \
  PAPER_MARKET_ACQUISITION_ENABLED=1 \
  HYPERLIQUID_PAPER_PUBLIC_ACQUISITION_ENABLED=1 \
  PAPER_MARKET_DATA_ROOT=/absolute/private/paper-market-data \
  php bin/console app:paper-market:public-capture-supervise \
    --venue=hyperliquid \
    --dataset-prefix=representative-hyperliquid-YYYYMMDD \
    --duration-sec=86400 \
    --attempts=8 \
    --no-interaction \
  >"$PWD/var/log/paper-capture/hyperliquid-YYYYMMDD.log" 2>&1 </dev/null &
```

Replace the date and the absolute data root before launch. Each supervisor invocation adds a cryptographically random run scope before the attempt number, preventing collisions when a prefix is reused or two supervisors overlap. The attempt bound limits disk growth, and the supervisor never deletes failed evidence.

`SIGINT` and `SIGTERM` are abnormal stops: they leave the active dataset incomplete and make the supervisor start a fresh attempt. Only expiration of the requested duration can initiate healthy completion.

## Health checks

Confirm that the processes are detached, sleep prevention is active, event files advance, and checkpoints remain non-terminal:

```bash
ps -axo ppid,pid,etime,state,command | rg 'public-capture(-supervise)?|caffeinate'
pmset -g assertions | rg 'PreventSystemSleep|PreventUserIdleSystemSleep|caffeinate'
stat -f 'bytes=%z modified=%Sm' /absolute/private/paper-market-data/representative-*/events.ndjson
jq '{phase,failure_reason,continuity}' /absolute/private/paper-market-data/representative-*/checkpoints/*/*.json
```

Only a completed dataset that passes the canonical verifier may enter the 24-hour Paper certification campaign. Failed or interrupted attempts remain excluded.
