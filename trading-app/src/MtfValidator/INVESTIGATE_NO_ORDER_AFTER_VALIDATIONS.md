# Investigation Playbook — 5 Validations But No Order Placed

Goal: quickly determine why a symbol shows multiple validations in “Synthèse validations” but no order was placed.

Use the checklist below from fastest signal → root‑cause. Copy/paste commands as needed.

## 0) Check Logs Before Running Anything
- App logs help spot configuration/env issues early.
- Commands:
  - `docker compose logs --tail 300 trading-app-php`
  - Optional focused search:
    - `docker compose logs trading-app-php | rg -i "error|exception|entitymanager is closed|filters_mandatory failed|skipped_out_of_zone|order_journey|BITMART_.*not set"`
- Look for:
  - Missing env vars (Bitmart keys/URLs), DB connectivity errors, JSON decode errors in workers.
  - Repeated skips like `filters_mandatory failed` or `skipped_out_of_zone` that indicate guard/config issues.

## 1) Confirm Run Outcome For The Symbol
- API result: `blocking_tf`, `status`, `trading_decision` show the first blocker.
- Command:
  - `curl -sS -X POST http://localhost:8082/api/mtf/run -H 'Content-Type: application/json' -d '{"symbols":["SYMBOL"],"dry_run":false,"workers":1}' | jq .`
- Observe for `SYMBOL`:
  - `status`: `READY` → candidate for trade entry; `INVALID/ERROR/SKIPPED/GRACE_WINDOW` → investigate why.
  - `blocking_tf`: first timeframe that failed or blocked (e.g. `5m`).
  - `trading_decision`: presence + `status` (`skipped` etc.) points to TradeEntry guards.

## 2) Check Kill Switches / Filters (Symbol Excluded)
- If symbol absent from results, it may be excluded by switches or open activity filter.
- Switches:
  - `psql -h localhost -p 5433 -U postgres trading_app -c "select switch_key,is_on,expires_at from mtf_switch where switch_key in ('GLOBAL','SYMBOL:SYMBOL','SYMBOL_TF:SYMBOL:1m','SYMBOL_TF:SYMBOL:5m');"`
  - OFF entries block processing; remove or turn ON. Note: repository now removes stale OFF switches when symbol has no open orders/positions.
- Blacklist:
  - `psql -h localhost -p 5433 -U postgres trading_app -c "select * from blacklisted_contract where symbol='SYMBOL' and (expires_at is null or expires_at>now());"`
- Open orders / positions filter (controller):
  - Look for logs: `[MTF Controller] Filtered symbols with open orders/positions` and which symbols were excluded.
  - If symbol is NOT returned by exchange activity AND switch is OFF, repository will delete the switch (reactivation by removal).

## 3) Timeframe Validation & Alignment
- Latest audits (last 24h):
  - `psql -h localhost -p 5433 -U postgres trading_app -c "select step, severity, cause, details->>'timeframe' tf, details->>'signal_side' side, details->>'kline_time' kt, created_at from mtf_audit where symbol='SYMBOL' and created_at>now()-interval '24 hours' order by id desc limit 100;"`
- Look for:
  - `*_VALIDATION_SUCCESS/FAILED` per TF → root timeframe that failed.
  - `ALIGNMENT_FAILED` entries (e.g., `1m vs 5m`) → sides mismatch blocks execution.
  - `*_EXCEPTION` or `*_KILL_SWITCH_OFF` → infra/config issues.
- Code references:
  - `trading-app/src/MtfValidator/Service/Timeframe/BaseTimeframeService.php:60` (auditStep usage)
  - `trading-app/src/MtfValidator/Service/MtfService.php:520` (per‑TF processing, updateState, alignment)

## 4) Grace Window / Guards / Min Bars
- If `status` is `GRACE_WINDOW`, the run skipped near candle boundary.
- Guards come from MTF config YAML:
  - `trading-app/src/MtfValidator/config/validations.yaml`
  - `trading-app/src/MtfValidator/config/validations.regular.yaml`
  - `trading-app/src/MtfValidator/config/validations.scalper.yaml`
- Typical blocks:
  - Not enough bars (`min_bars`), stale upstream TF, or per‑TF kill switch.

## 5) Trade Entry Decision (READY → No Order)
- If `status: READY` but no order:
  - Check the Trade Entry decision pipeline:
    - `trading-app/src/MtfValidator/Service/TradingDecisionHandler.php:24` (class entry point)
    - It validates execution eligibility, selects TF via `executionSelector`, builds a TradeEntry request, and posts orders via `TradeEntryService`.
  - Common reasons for skip:
    - `allowed_execution_timeframes` mismatch (config)
    - `require_price_or_atr: true` but missing current price or ATR for exec TF
    - Spread/deviation/zone guards from TradeEntry config
  - TradeEntry YAMLs:
    - `trading-app/config/app/trade_entry.yaml`
    - `trading-app/config/app/trade_entry.regular.yaml`
    - `trading-app/config/app/trade_entry.scalper.yaml`
- Order planning tables:
  - `psql -h localhost -p 5433 -U postgres trading_app -c "select id,symbol,side,status,plan_time from order_plan where symbol='SYMBOL' order by plan_time desc limit 10;"`
  - If planning exists but no exchange orders: check `futures_order` / `futures_plan_order` if present in your schema.

## 6) Current Price / ATR Availability
- The decision may require at least one of price or ATR.
- From API result JSON for the symbol, inspect `current_price` and `atr` fields.
- Snapshot persistence (if enabled): check indicator snapshots persist for symbol/TF.

## 7) Contract Eligibility (Selection Pool)
- Symbol might not be in the selected set depending on liquidity filters:
  - Contract filters YAML: `trading-app/config/app/mtf_contracts.yaml`
  - Repository logic: `trading-app/src/Provider/Repository/ContractRepository.php:434` (`findSymbolsMixedLiquidity`)
- DB sanity:
  - `psql -h localhost -p 5433 -U postgres trading_app -c "select symbol,status,quote_currency,turnover_24h from contracts where symbol='SYMBOL';"`

## 8) Environment / Provider Health
- Ensure Bitmart credentials set in Docker env (warnings in logs indicate blank values).
- Logs:
  - App: `docker compose logs trading-app-php`
  - Workers/messenger: `docker compose logs trading-app-messenger`

## 9) Quick Triage Flow (Copy/Paste)
1. API run one symbol
   - `curl -sS -X POST http://localhost:8082/api/mtf/run -H 'Content-Type: application/json' -d '{"symbols":["SYMBOL"],"dry_run":false,"workers":1}' | jq .`
2. If absent → switches:
   - `psql -h localhost -p 5433 -U postgres trading_app -c "select * from mtf_switch where switch_key like 'SYMBOL:SYMBOL%';"`
3. If present but not READY → audits:
   - `psql -h localhost -p 5433 -U postgres trading_app -c "select step,details->>'timeframe' tf,details->>'signal_side' side,created_at from mtf_audit where symbol='SYMBOL' order by id desc limit 50;"`
4. If READY but no order → trade entry/config:
   - Check `TradingDecisionHandler.php:24`, `/config/app/trade_entry*.yaml`, and `order_plan` rows.

## 10) Common Root Causes & Remedies
- Kill switch still OFF for symbol/TF → turn on or let auto‑reactivation delete stale switch when no open activity.
- Alignment failed (e.g. 1m vs 5m) → wait for consistent sides or revisit `execution_selector` config.
- Grace window skip → re‑run just after candle close or enable force flags.
- Guards too strict (spread, deviation, zone width) → adjust `trade_entry.*` guards.
- Missing price/ATR → ensure price provider/ATR fetching is healthy.
- Contract filtered out (liquidity/age) → tune `mtf_contracts.yaml` thresholds.

## Useful Endpoints
- `GET /api/mtf/audit?symbol=SYMBOL&limit=100` — recent audits
- `GET /api/mtf/order-plans?symbol=SYMBOL&limit=20` — recent order plans
- `POST /api/mtf/run` — executes MTF (supports `symbols`, `dry_run`, `workers`, `force_run`, `current_tf`, `force_timeframe_check`)

---
This playbook targets rapid triage for “validations seen, no order placed”. Use it to guide an agent through logs, DB state, config, and code paths to a clear root cause.
