# Checklist — running the platform on OKX demo (operator view, 2026-10-12)

Owner's item 2 of 2026-10-12: what has to be true before the platform places its first OKX
demo order, and what the owner has to provide. Everything below was read from the repository
on 2026-10-12 (branch `claude/research-execution-markout`, same code as `main` for these
paths); no flag was changed, no value is reproduced. The companion runbooks remain the
authority for the procedures: `okx-private-ws-observability.md`, `demo-testnet-operations.md`,
`demo-readiness-okx-hl.md`, `okx-demo-readiness.md`, `demo-testnet-kill-switch.md`.

## 0. Five facts that change the plan

1. **The versioned (1.1.0) contracts cannot trade on OKX demo today.** The live MTF path
   rejects every modern `trading_identity` before any order: `CanonicalTradeRuntimePolicyValidator`
   returns the `canonical_*_pending_304` blockers and `CanonicalMtfPolicyPreflight` refuses the
   run (`src/TradeEntry/Policy/CanonicalTradeRuntimePolicyValidator.php`,
   `src/MtfValidator/Policy/CanonicalMtfPolicyPreflight.php`). A demo run can only use the
   legacy profiles (`scalper_micro`, `scalper`, `regular`, `crash`), which were never backtested
   on the captures. Any research candidate therefore needs issue #304 closed (or an explicit
   legacy translation) before it can reach demo.
2. **The YAML kill switch does not stop OKX writes.** `OkxDemoWriteGate` hard-codes
   `effectiveKillSwitchEnabled: false`; the `kill_switch_enabled` keys of
   `config/trading/env/demo.yaml` and `config/trading/runtime/env/demo.yaml` are never read by
   the OKX write path. The stops that work: the five env flags and the quarantine file
   `trading-app/var/okx-demo-execution.quarantine` (its mere existence trips every write kind,
   including protective ones; an operator creates and removes it by hand; the adapter creates
   it itself on `audit_after_failed`).
3. **`trading-app/.env.local` does not reach the containers by default.** `docker-compose.yml`
   injects the `x-exchange-env` block (every `*_ENABLED` at 0, keys empty) into
   `trading-app-php` and the three messenger containers, and Symfony's dotenv never overrides
   a variable that is already set. Use `docker compose --env-file trading-app/.env.local …`
   for every command that creates those containers, and `up -d --force-recreate` after any
   change (`restart` does not re-inject variables). Check that `TRADING_APP_DATABASE_URL` in
   that file points at the docker database host before using it as `--env-file`.
4. **Two legacy Temporal schedules would start placing OKX demo orders as soon as both demo
   flags are 1**: `cron-symfony-mtf-workers-scalper-micro-1m` sends `dry_run=false` with no
   exchange (OKX is the default) and `cron-symfony-mtf-workers-1m` takes `dry_run` from
   `MTF_WORKERS_DRY_RUN`, which compose sets to false. Pause both (`manage_*_schedule.py pause`)
   before flipping any flag, and resume on purpose.
5. **The private WebSocket worker has never been proven against real OKX demo** (the recorded
   OKX-010 attempt was cut by OKX at login). Entry, take-profit, leverage and non-protective
   cancels all require its status to be fresh (Redis key
   `tradingv3:okx:demo:private-observability:v1`, 10 s TTL, connected + authenticated +
   streams ready + snapshot loaded + reconciliation fresh). Without it, only protective
   writes pass the gate.

## 1. Owner-side inputs (never typed by an agent)

| Variable (names only) | Required state for demo | Where read |
|---|---|---|
| `OKX_ENV` | `demo` | `OkxDemoWriteGate`, `OkxPrivateWebSocketCommand` |
| `OKX_SIMULATED_TRADING` | on (adds the `x-simulated-trading: 1` header) | `OkxConfig`, `OkxRestClient` |
| `OKX_DEMO_TRADING_ENABLED` | 1 — owner's decision | gate, `DemoTradingKillSwitchService` |
| `DEMO_TRADING_ENABLED` | 1 — owner's decision | gate, kill switch |
| `OKX_LIVE_ENABLED` | 0, always | gate (`mainnet_write_enabled_must_remain_false`) |
| `OKX_DEMO_API_KEY`, `OKX_DEMO_API_SECRET`, `OKX_DEMO_API_PASSPHRASE` | present, demo-mode keys | `OkxConfig`, runtime check |
| `OKX_API_BASE_URI` | empty or the EEA REST host accepted by `OkxRestEndpointGuard` | `OkxConfig` |
| `OKX_WS_PRIVATE_URI`, `OKX_WS_BUSINESS_URI` | exactly the private / business endpoints accepted by `OkxPrivateWebSocketEndpointGuard` | the private-WS worker |
| `app.okx_demo_max_notional` (`config/services.yaml`) | owner's value (the recap calls the current one provisional) | `DemoTradingSafetyPolicyEvaluator` (`max_notional_exceeded`) |

Note: the main checkout's `trading-app/.env` still carries `OKX_API_KEY` / `OKX_API_SECRET` /
`OKX_API_PASSPHRASE`, names the code never reads; the demo names above are the ones that
matter. `.env.example` is the reference list.

## 2. Sequence

1. **Database.** `docker compose exec trading-app-php php bin/console doctrine:migrations:list`
   then `doctrine:migrations:migrate`. Must be applied: `Version20261008120000` (exchange
   column defaults to `okx`), `Version20260712120000` / `Version20260712230000` (Hyperliquid
   kill switch and attempts), `Version20260713150000` / `Version20260715150000` (exact
   quantities the private-WS projection writes), and the August 2026 lineage / identity
   migrations. `doctrine:migrations:migrate` applies everything pending; the orchestrator, if
   used, has its own `alembic upgrade head`.
2. **Containers with the demo env.** `docker compose --env-file trading-app/.env.local up -d --force-recreate trading-app-db redis trading-app-php trading-app-nginx trading-app-messenger-trading trading-app-messenger-order-timeout trading-app-messenger-projection`.
   Both `mtf_decision` and `order_timeout` consumers must run (entry placement; fill watcher
   that attaches SL then TPs, cancels on TTL).
3. **Contracts.** `php bin/console provider:fetch-contracts` (the notional check needs the
   contract size `ctVal`).
4. **Private WebSocket worker.** `docker compose --env-file trading-app/.env.local --profile okx-observability up --build -d trading-app-okx-private-ws`
   (the image bakes the code: always `--build`), then `logs --tail=100`; it refuses to start
   with `status=refused code=okx_private_ws_*` when env, simulated trading, live flag, keys or
   endpoints are wrong. Check the Redis status and its TTL as in the observability runbook.
5. **Pre-flight, nothing placed.** `php bin/console app:exchange:runtime-check okx perpetual --json`:
   `write_ready` must be true and `blocking_reasons` empty (flags, credentials present,
   endpoint guard, no trip, kill-switch decision including WS freshness). It makes no exchange
   write, reads Redis and appends one line to `var/log/demo-trading-audit*.log`; with
   credentials present the text mode also makes one signed read-only balance call. Known
   cosmetic drift of the text mode: it always prints `Dry-run only: yes`, `Live allowed: no`
   and a 25.0 max notional whatever the state; trust the JSON.
6. **Schedules.** Confirm both legacy schedules are paused (`manage_scalper_micro_schedule.py status`,
   `manage_mtf_workers_schedule.py status`). The DEMO-004 demo schedule cannot be activated
   today (it requires `Schedule ready: yes` on Hyperliquid too, impossible without an identity).
7. **Flip the flags — owner only.** Then `up -d --force-recreate` the PHP and messenger
   containers, re-run step 5.
8. **First run, bounded.** One explicit profile and one symbol:
   `php bin/console mtf:run --workers=1 --dry-run=1 --trade-profile=scalper_micro --symbols=BTCUSDT`
   first, then `--dry-run=0` when the owner says so. The write gate has no symbol allow-list,
   so the `--symbols` restriction is the only universe limit. Watch
   `var/log/order-journey*.log`, `var/log/demo-trading-audit*.log`, `var/log/okx-private-ws*.log`.
9. **Stop.** Create `trading-app/var/okx-demo-execution.quarantine` (immediate, all write
   kinds), set both demo flags to 0 in the env file, `up -d --force-recreate`, pause the
   schedules. The quarantine file is removed by hand once the cause is understood.

## 3. What this means for a research candidate

A configuration found by the research screens (candle or tick) reaches demo only through a
published contract version (mode and setup YAML plus the validator version lists, the schema
pins, the resolver's mode list, a `runtime/mode_exchange/<mode>.<version>.okx.yaml` layer, and
for Hyperliquid the envelope hash), a `scripts/backtest-profile.sh` run and the certification
campaign — and then only once the canonical preflight (#304) lets modern identities execute.
Until then the demo path runs legacy profiles only. This is the gap between "candidate found"
and "candidate in demo", and it is code work, not configuration.

## 4. Hyperliquid testnet, in brief

Readiness is identity-keyed and hashed (`config/trading/env/testnet.yaml`: six 1.1.0
identities, BTCUSDT, max notional 25); `HyperliquidMutationReadinessGate` runs 27 checks and
issues 60 s HMAC proofs; DI injects no proof, so the MTF flow cannot mutate Hyperliquid; the
only path is `app:hyperliquid:testnet:smoke <plan-file> --confirm=CONFIRM_HYPERLIQUID_TESTNET_ONLY --readiness-decision=ready_for_demo_testnet_trading_attempt --mode-id … --mode-version … --setup-id … --setup-version … --side …`,
which really sends the order when every gate passes and the flag is 1. Owner inputs:
`HYPERLIQUID_TESTNET_ACCOUNT_ADDRESS`, `HYPERLIQUID_TESTNET_AGENT_ADDRESS`, the agent private
key (signer only), `HYPERLIQUID_SIGNER_AUTH_TOKEN`; none is present in any local env file
today. Migrations `Version20260712120000` and `Version20260712230000` are required.

## 5. Drift noticed while reading (to fix separately)

- `demo-testnet-kill-switch.md` suggests `restart`; variables need `up --force-recreate`.
- `okx-demo-readiness.md` still says demo writes are unsupported; `operations.md` still uses
  the `bitmart` runtime check; the roadmap leaves OKX-010 unchecked.
- `config/trading/env/demo.yaml` (the older tree) is read by no source code; the resolver reads
  `config/trading/runtime/env/demo.yaml`.
- `APP_SECRET` is hard-coded in `docker-compose.yml`; there is no `trading-app/.dockerignore`,
  so `COPY . .` can bake local `.env*` files into the private-WS image when built from a
  checkout that holds them.
