# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

---

## Repository overview

This is a Symfony 7.1 / PHP 8.2 trading platform for Bitmart perpetual futures. It runs an MTF (Multi-TimeFrame) validation loop that evaluates 100+ symbols every minute and—when conditions are met—places limit/market orders with dynamic leverage, stop-loss, and take-profit.

Three sub-projects live here:

| Directory | Stack | Role |
|---|---|---|
| `trading-app/` | PHP 8.2 + Symfony 7.1 + PostgreSQL + Redis | Core platform: API, validation, trade execution |
| `cron_symfony_mtf_workers/` | Python 3 + Temporal SDK | Minute-cron that calls `/api/mtf/run` every 60 s |
| `frontend/` | React | Thin dashboard (mostly read-only) |

---

## Commands

All PHP commands run **inside Docker** (`docker-compose exec trading-app-php`). The `Makefile` in the root wraps most of them.

### Start the stack

```bash
docker compose up -d trading-app-db trading-app-redis trading-app-php trading-app-nginx
docker compose exec trading-app-php composer install
docker compose exec trading-app-php php bin/console doctrine:migrations:migrate
```

### Common Symfony commands

```bash
# Trigger a full MTF run (parallel, 8 workers, scalper_micro profile)
docker-compose exec trading-app-php php bin/console mtf:run --workers=8 --dry-run=0

# Equivalent via HTTP
curl -X POST http://localhost:8082/api/mtf/run \
  -H 'Content-Type: application/json' \
  -d '{"dry_run":false,"workers":8,"mtf_profile":"scalper_micro"}'

# Sync contracts from Bitmart
php bin/console bitmart:fetch-contracts [--symbol=BTCUSDT]

# Fetch klines
php bin/console bitmart:fetch-klines BTCUSDT --timeframe=1h --limit=200

# Start Messenger workers (required for order watchers and async projections)
php bin/console messenger:consume order_timeout
php bin/console messenger:consume mtf_projection
php bin/console messenger:consume mtf_decision

# Diagnose conditions for a symbol
php bin/console app:indicator:conditions:diagnose <symbol> <tf>

# Export all data for a symbol at a specific UTC time (investigation)
php bin/console app:export-symbol-data <SYMBOL> "Y-m-d H:i" [--show-sql] [--show-logs]
```

### Tests

```bash
# Run full test suite (from inside trading-app/)
docker-compose exec trading-app-php php bin/phpunit

# Run a single test file
docker-compose exec trading-app-php php bin/phpunit tests/TradeEntry/TradeEntryBoxTest.php

# Run a single test method
docker-compose exec trading-app-php php bin/phpunit --filter testMethodName tests/Path/To/Test.php
```

### Static analysis

```bash
docker-compose exec trading-app-php vendor/bin/phpstan analyse
```
PHPStan runs at level 6 and covers `bin/`, `config/`, `public/`, `src/`, `tests/`.

### MTF audit / health

```bash
make mtf-audit-summary              # calibration + health check + failures by TF
make mtf-health-check PERIOD=24h    # system health
make mtf-audit-full                 # all seven audit reports
make calibrate-atr SINCE="7 days" ATR_TFS=15m,5m,1m
make validate-contracts TF=15m LIMIT=100
```

### Temporal workers (Python)

```bash
cd cron_symfony_mtf_workers
python -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
export TEMPORAL_ADDRESS=temporal:7233 TEMPORAL_NAMESPACE=default TASK_QUEUE_NAME=cron_symfony_mtf_workers
python worker.py

# Create/update Temporal schedules
python scripts/manage_mtf_workers_schedule.py create --dry-run
python scripts/manage_scalper_micro_schedule.py create
```

---

## Architecture

### Request flow

```
POST /api/mtf/run  (RunnerController)
         │
         ▼
   MtfRunnerService
         ├─ resolveSymbols()          → active contracts + MtfSwitch queue
         ├─ syncTables()              → sync Bitmart positions/orders (FuturesOrderSyncService)
         ├─ filterSymbols*()          → skip symbols with open positions/orders
         ├─ runSequential|Parallel()  → spawns mtf:run-worker processes
         ├─ dispatchIndicatorSnapshotPersistence()   → async via Redis mtf_projection
         ├─ processTpSlRecalculation()
         └─ enrichResults()           → summary_by_tf, rejected_by, orders_placed
                  │
                  ▼
         MtfValidatorService  →  MtfValidatorCoreService
                  ├─ ContextValidationService  (context TFs: 5m, sometimes 15m)
                  ├─ TimeframeValidationService (execution TFs: 1m)
                  ├─ ExecutionSelectionService  (choose final TF)
                  └─ TradingDecisionHandler    → dispatch MtfTradingDecisionMessage
                            │
                            ▼ (Messenger: mtf_decision)
                  TradeEntryService
                            ├─ BuildPreOrder     (PreflightReport: book, spread, balance, pivots)
                            ├─ BuildOrderPlan    (EntryZoneCalculator, OrderPlanBuilder)
                            ├─ ExecuteOrderPlan  (ExecutionBox: maker/taker + watchers)
                            └─ AttachTpSl        (TpSlTwoTargetsService)
```

### Profiles / modes

The active profile is set in `config/services.yaml` (`app.trade_entry_default_mode`, default `scalper_micro`). Each profile has two YAML files that must be kept in sync:

| Config file | Purpose |
|---|---|
| `trading-app/src/MtfValidator/config/validations.<mode>.yaml` | MTF validation rules (context/execution TFs, conditions, execution selector) |
| `trading-app/config/app/trade_entry.<mode>.yaml` | Risk sizing, leverage, stop policy, entry zone, market entry |

Current profiles: `scalper_micro` (active), `scalper`, `regular`, `crash`.

### Indicator conditions

Compiled conditions live in `src/Indicator/Condition/`. Each class extends `AbstractCondition`, implements `ConditionInterface`, and is tagged with:

```php
#[AsIndicatorCondition(timeframes: ['5m','1m'], side: 'long', name: 'my_condition')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'my_condition')]
```

The `IndicatorCompilerPass` auto-wires them into `ConditionRegistry` ServiceLocators keyed by `{timeframe}.{side}`. Conditions referenced in YAML validation files by name must have a matching compiled condition registered.

### Messenger transports

Three PostgreSQL-backed queues (defined in `config/packages/messenger.yaml`):

| Transport | Consumer container | Purpose |
|---|---|---|
| `order_timeout` | `trading-app-messenger-order-timeout` | Limit fill watchers, cancel orders, out-of-zone watchers |
| `mtf_projection` | `trading-app-messenger-projection` | Async indicator snapshot persistence, MTF result projection |
| `mtf_decision` | `trading-app-messenger-trading` | Trading decisions dispatched after MTF validation |

Both `order_timeout` and `mtf_decision` workers **must** be running in production; `mtf_projection` handles async persistence.

### Provider / exchange abstraction

All exchange calls go through `MainProviderInterface`. The concrete implementation selects a bundle at runtime via `ExchangeContext(exchange, marketType)`:

```php
$provider = $this->mainProvider->forContext(new ExchangeContext(Exchange::BITMART, MarketType::PERPETUAL));
$contracts = $provider->getContractProvider()->syncContracts();
```

Adding a new exchange = create an `ExchangeProviderBundle` and register it in `ExchangeProviderRegistry`.

### Key interfaces (src/Contract/)

| Interface | Implementation |
|---|---|
| `MainProviderInterface` | `Provider\MainProvider` |
| `IndicatorMainProviderInterface` | `Indicator\Provider\IndicatorMainProvider` |
| `IndicatorProviderInterface` | `Indicator\Provider\IndicatorProviderService` |
| `MtfValidatorInterface` | `MtfValidator\Service\MtfValidatorService` |

---

## Key configuration files

| File | What it controls |
|---|---|
| `trading-app/config/app/mtf_contracts.yaml` | Which contracts to trade (liquidity filters, top_n) |
| `trading-app/config/app/indicator.yaml` | EMA/MACD/RSI/ATR periods, pct thresholds per TF |
| `trading-app/config/app/signal.yaml` | MTF timeframe list, min_bars per TF |
| `trading-app/config/app/trade_entry.yaml` | Shared defaults (risk %, leverage, stop policy, entry zone) |
| `trading-app/config/app/trade_entry.<mode>.yaml` | Profile-specific overrides |
| `trading-app/src/MtfValidator/config/validations.<mode>.yaml` | YAML validation rules per profile |
| `trading-app/config/packages/messenger.yaml` | Messenger transports and routing |
| `trading-app/config/services.yaml` | Active mode (`app.trade_entry_default_mode`), log levels |

### Entry zone key parameters

These YAML keys control entry zone behavior in `trade_entry.<mode>.yaml`:

- `trade_entry.defaults.{max_deviation_pct, implausible_pct, zone_max_deviation_pct}`
- `trade_entry.entry.entry_zone.{from, offset_atr_tf, k_atr, w_min, w_max, max_deviation_pct, asym_bias, ttl_sec}`
- `post_validation.entry_zone.{vwap_anchor, k_atr, w_min, w_max, ttl_sec}`

---

## Development checklists

### Adding a new MTF profile

1. Create `src/MtfValidator/config/validations.<mode>.yaml`.
2. Create `config/app/trade_entry.<mode>.yaml`.
3. Register the mode in `config/services.yaml` (`parameters.mode` list).
4. Update `MtfValidationConfigProvider` and `TradeEntryModeContext` if needed.

### Adding a new indicator condition

1. Create `src/Indicator/Condition/MyCondition.php` extending `AbstractCondition`.
2. Add `#[AsIndicatorCondition(...)]`, `#[AutoconfigureTag('app.indicator.condition')]`, `#[AsTaggedItem(index: 'name')]`.
3. Reference the condition name in the relevant `validations.<mode>.yaml`.
4. Add tests in `tests/Indicator/Condition/`.

### Adding a new Runner feature

1. Modify `src/MtfRunner/Service/MtfRunnerService.php`.
2. Add tests in `tests/MtfRunner/` (or closest equivalent).
3. Update `src/MtfRunner/README.md`.

---

## Logs and observability

| Log file | Monolog channel | Content |
|---|---|---|
| `var/log/mtf-runner.log` | `mtf` | Symbol resolution, filters, MTF execution, snapshots |
| `var/log/order-journey*.log` | `positions` | TradeEntry details (prices, watchers, leverage) |
| `var/log/bitmart-http.log` | `bitmart` / `provider` | Bitmart HTTP calls, rate-limit backoffs |

Useful diagnostic API endpoints (prefix `/api`):
- `GET /mtf/status`, `/mtf/lock/status`, `/mtf/audit`
- `GET /provider/health`

```bash
# Condition failures grouped by rule
rg "reason=" var/log/mtf-YYYY-MM-DD.log | sed 's/.*reason=\([^ ]*\).*/\1/' | sort | uniq -c | sort -nr

# Condition report CSV (requires running the Python script inside the container)
trading-app/scripts/mtf_condition_report.py --log var/log/mtf-YYYY-MM-DD.log \
  --since "YYYY-MM-DD HH:MM" --csv-prefix /tmp/mtf-summary
```

---

## Important known constraints

- **`php-trader` extension**: ATR computation falls back to a pure-PHP implementation when `trader_atr()` returns 0. This generates ~30k warnings/day in `var/log/indicators-*.log` — expected behavior until the extension is fixed.
- **ATR required for `stop_from: atr`**: orders are silently rejected (`atr_required_but_invalid`) if ATR is absent. Check the `order-journey` log for this key.
- **EntityManager closed guard**: `BaseTimeframeService::persistAudit()` and `MtfResultProjector::project()` test `EntityManager::isOpen()` and degrade gracefully. Root cause was a DBAL error during kline upserts (see `trading-app/codex.md`).
- **Messenger restart after config changes**: after any change to `config/packages/messenger.yaml`, restart `trading-app-messenger-trading` and `trading-app-messenger-order-timeout` containers to pick up new Redis queues.
- **`zone_max_deviation_pct` must be set explicitly** in `trade_entry.scalper_micro.yaml`; otherwise `BuildOrderPlan` falls back to 0.7% regardless of `entry_zone.max_deviation_pct`.
- **Signal persistence**: `SignalValidationService` is currently disconnected — the `signals` table is empty. If you need signal data, reconnect `SignalPersistenceService` directly in the timeframe services or add a dedicated Messenger handler.
