# Market Entry – Agent Playbook (Logs + Adjustments)

Purpose: help an agent investigate why orders didn’t place, validate market-entry decisions, and apply safe adjustments (zone, RSI, fallback TF, slippage caps) with measurable impact.

## 1) Pre‑Run Log Checks
- App logs (quick scan):
  - `docker compose logs --tail 300 trading-app-php`
- Focused search:
  - `docker compose logs trading-app-php | rg -i "error|exception|entitymanager is closed|filters_mandatory failed|skipped_out_of_zone|order_journey|market_entry\.|BITMART_.*not set"`
- Look for:
  - Missing env vars (Bitmart), DB errors, repeated “skipped_out_of_zone”, “filters_mandatory failed”, market_entry decisions.

## 2) Run Targeted Cycle
- Single or small set:
  - `curl -sS -X POST http://localhost:8082/api/mtf/run -H 'Content-Type: application/json' -d '{"symbols":["SYMBOL"],"dry_run":false,"workers":1}' | jq .`
- Inspect for each symbol:
  - `status`, `blocking_tf`, `trading_decision` (status, reason, entry info)

## 3) DB Queries (Audits / Plans / Switches)
- Last audits:
  - `psql -h localhost -p 5433 -U postgres trading_app -c "select step,severity,details->>'timeframe' tf,details->>'signal_side' side,details->>'kline_time' kt,created_at from mtf_audit where symbol='SYMBOL' order by id desc limit 50;"`
- Order plans:
  - `psql -h localhost -p 5433 -U postgres trading_app -c "select id,symbol,side,status,plan_time from order_plan where symbol='SYMBOL' order by plan_time desc limit 10;"`
- Switches/blacklist:
  - `psql -h localhost -p 5433 -U postgres trading_app -c "select switch_key,is_on,expires_at from mtf_switch where switch_key like 'SYMBOL:%';"`
  - `psql -h localhost -p 5433 -U postgres trading_app -c "select * from blacklisted_contract where symbol='SYMBOL' and (expires_at is null or expires_at>now());"`

## 4) Where Market Entry Is Decided (Code)
- Metrics logging (ADX/ATR%):
  - `trading-app/src/MtfValidator/Service/TradingDecisionHandler.php`
  - Log key: `order_journey.market_entry.metrics`
- Decision in builder (limit vs market):
  - `trading-app/src/TradeEntry/Builder/TradeEntryRequestBuilder.php`
  - Log key: `market_entry.decision` (accepted/refused + reasons)
- Config access:
  - `trading-app/src/Config/TradeEntryConfig.php` (`getMarketEntry()`)

## 5) Config Keys (Per Profile)
- Scalper: `trading-app/config/app/trade_entry.scalper.yaml`
- Regular: `trading-app/config/app/trade_entry.regular.yaml`
- Keys:
  - `market_entry.enabled: true|false`
  - `market_entry.allowed_execution_timeframes: ['1m','5m']`
  - `market_entry.max_slippage_bps: 20..40` (cap nominal pour IOC-limit au provider)
  - `market_entry.adx_min_1h: 15..25` (gating “trend”)
  - Zone controls: `post_validation.entry_zone.k_atr`, `w_min`, `w_max`
  - Zone tolerance: `defaults.zone_max_deviation_pct` (utiliser prudemment)

## 6) Typical Findings → Adjustments
- Case: `skipped_out_of_zone`
  - Prefer widening zone (`k_atr` / `w_max`) before raising tolerance.
  - If enabling market, keep a slippage cap and require ADX/zone proximity.
- Case: `blocking_tf=1m`
  - Use fallback `current_tf=5m` or relax 5m validation slightly (scalper/regular validations YAML) with caution.
- Case: RSI gating rejects often
  - Small threshold change only (e.g., 74→73), monitor win rate/PF; don’t remove entirely.
- Case: leverage bounds missing
  - We removed `lev_bounds` from filters_mandatory; clamps remain enforced in TradeEntry config.

## 7) Market Entry With IOC‑Limit (Why/How)
- Why (vs pure market):
  - Bound worst‑case fills (holes in the book) and avoid catastrophic slippage.
  - Keep order maker‐friendly by default; escalate to marketable IOC only under strict conditions.
- How (provider/submit level):
  - For LONG: `cap = best_ask * (1 + max_slippage_bps/10000)` (IOC limit)
  - For SHORT: `cap = best_bid * (1 - max_slippage_bps/10000)`
  - If IOC unfilled → either keep maker or skip (depending on window/attempts).
- Optional gating: `zone_proximity_pct_max` (only allow market if within X% of zone_mid), reduces late/poor entries.

### Rationale (Quality/Risk)
- Market orders improve fill probability/speed but degrade entry quality (slippage + taker fees), hurting R:R and win rate on late entries.
- IOC‑limit caps the executable price to contain slippage in volatile or thin books (prevents catastrophic fills).
- Gating by zone proximity (e.g., `zone_dev_pct <= 1.0%`) preserves the “in‑zone” edge and avoids chasing.
- Combining ADX threshold with proximity ensures market is used only in favorable trend contexts.

### Practical Thresholds (starting points)
- Scalper: `zone_proximity_pct_max 1.0%`, `max_slippage_bps 20–30`, `adx_min_1h ≥ 15`.
- Regular: `zone_proximity_pct_max 1.2–1.5%`, `max_slippage_bps 15–25`, `adx_min_1h ≥ 20`.
- Tighten for low‑liquidity symbols; relax slightly for top caps with deep books.

### Provider Enforcement (sketch)
- Compute cap price from best bid/ask + `max_slippage_bps`.
- Submit IOC‑limit. If partially filled or unfilled within the time window:
  - Option A: leave remaining as maker limit inside zone.
  - Option B: cancel remaining (skip), log reason + metrics.

### Metrics/Logs To Watch
- `order_journey.market_entry.metrics` → ADX 1h, ATR% 15m context at decision time.
- `market_entry.decision` → whether market was accepted/refused + reasons.
- Fill stats (provider): price avg vs cap, slippage bps, partial fills, fees (taker vs maker).
- Outcome: changes in skip rate, win rate, PF, R net, MAE/MFE.

## 8) Logging To Verify After Change
- Metrics:
  - `order_journey.market_entry.metrics` (ADX 1h, ATR% 15m)
- Decision:
  - `market_entry.decision` (accepted_condition / conditions_not_met)
- Trade request built / dispatch:
  - `order_journey.trade_request.built`
  - `order_journey.trade_entry.dispatch`
- Skips:
  - `skipped_out_of_zone`, `trading_conditions_not_met` (number should drop if changes are effective)

## 9) Safe Rollout Steps
- Pilot on subset (top caps) → compare: skipped rate, win rate, PF, R net, MAE/MFE, slippage bps.
- Keep clamps (leverage caps, budget, spread/slippage guards) active in TradeEntry.
- Remove/rollback config if adverse metrics ↑.

## 10) File Pointers
- Market entry config:
  - `trading-app/config/app/trade_entry.scalper.yaml`
  - `trading-app/config/app/trade_entry.regular.yaml`
- MTF validations:
  - `trading-app/src/MtfValidator/config/validations.*.yaml`
- Decision logic:
  - `trading-app/src/MtfValidator/Service/TradingDecisionHandler.php`
  - `trading-app/src/TradeEntry/Builder/TradeEntryRequestBuilder.php`

---
This playbook aims to keep entries safe (bounded slippage, close to zone) while reducing unnecessary skips. Always validate via logs + metrics before generalizing changes.
