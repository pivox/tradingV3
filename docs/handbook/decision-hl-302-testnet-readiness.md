# Decision HL-302: Hyperliquid testnet readiness envelope

Status: DRAFT. Not in force until the owner validates it. Once validated, it replaces the `blocked` state of DEMO-005 with this versioned decision.

Scope: Hyperliquid testnet only. Mainnet is out of scope and stays forbidden.

## Decision

The 28-condition readiness gate (27 gate conditions plus the report's `blocking_errors` check) is evaluated against an identity-keyed, versioned envelope instead of a hard-coded fail-closed config. The envelope is `trading-app/config/trading/env/testnet.yaml`.

Nothing in this decision enables trading by itself. The runtime flags stay the owner's to set: `DEMO_TRADING_ENABLED`, `HYPERLIQUID_TESTNET_TRADING_ENABLED` and `HYPERLIQUID_MAINNET_ENABLED` keep their current values and are only read by code.

## The envelope file

Schema `hyperliquid-testnet-readiness-envelope.v1`, version `1.0.0`. All keys are required, unknown keys are rejected.

| Key | Value in 1.0.0 |
|---|---|
| `eligible_identities` | the six identities below |
| `allowed_symbols` | `[BTCUSDT]` |
| `allowed_markets` | `[perpetual]` |
| `max_notional` | `25.0` (quote units, tightest value that still lets the gate evaluate; exchange minimum notional is 5.0) |
| `execution.dry_run` | `false` |
| `execution.live_enabled` | `false` |
| `execution.mainnet_write_enabled` | `false` |
| `execution.demo_testnet_write_enabled` | `true` |
| `execution.kill_switch_enabled` | `false` |
| `execution.require_stop_loss` | `true` |
| `envelope_hash` | sha256 of the canonical document without this key |

These values are placeholders chosen to be conservative. The owner tunes them.

## Versioning and hashing

- `envelope_version` is an exact semantic version. Any change to the file is a reviewed commit that bumps it.
- `envelope_hash` is the sha256 of the canonical JSON (keys sorted recursively, `envelope_hash` removed). A file whose hash does not match is ignored and the source returns fail-closed. Recompute it with:
  `php -r 'require "vendor/autoload.php"; echo App\Provider\Hyperliquid\EffectiveTradingHyperliquidMutationReadinessConfigSource::envelopeHash(Symfony\Component\Yaml\Yaml::parseFile("config/trading/env/testnet.yaml"));'`
- The config hash carried by the readiness report and compared with the order plan is `sha256(snapshot.config_hash ":" envelope_hash)`. It binds the resolver snapshot of the exact identity (mode, setup, exchange, environment, side, compiled setup, condition catalog) and the envelope. Changing either changes the hash, so plans built against an older version are rejected by the port (`effective_config_hash_mismatch`).
- The profile string is `mode@version/setup@version/side`.

## Eligible identities (all 1.1.0)

- `day_trading` / `day_trading.trend_continuation.long` / long
- `micro_scalping` / `micro_scalping.momentum_ofi.long` / long
- `micro_scalping` / `micro_scalping.momentum_ofi.short` / short
- `scalping` / `scalping.pullback.long` / long
- `scalping` / `scalping.trend_continuation.long` / long
- `scalping` / `scalping.trend_momentum.short` / short

An identity must be in this list and resolve to an executable snapshot through `EffectiveTradingConfigResolver`. Otherwise the config is fail-closed. Any exchange/environment other than `hyperliquid/testnet` is fail-closed regardless of the file content. The resolver currently only accepts `ShadowExecutionCapability::Paper` for these 1.1.0 modes on Hyperliquid, so the smoke command requests that capability (see open questions).

## Hard gates that remain

All 27 gate conditions plus `blocking_errors == []`: testnet environment, network and endpoint, `DEMO_TRADING_ENABLED=1`, `HYPERLIQUID_TESTNET_TRADING_ENABLED=1`, `HYPERLIQUID_MAINNET_ENABLED=0`, `demo_testnet_candidate` readiness level, readable account, read and trade permissions, readable collateral, private observability, polling, stop-loss capability, signer configured and bound to the account, nonce store, mainnet write guard, no kill switch (envelope flag and durable marker `var/hyperliquid-testnet-execution.quarantine`), profile, hash, allow-list, positive max notional.

Also unchanged: attempt store, execution lock, nonce manager, margin and liquidation proof, compensation, operator confirmation of the smoke command, signer sidecar with broadcast disabled by default.

The automatic MTF flow is not wired to the testnet port in this lot. `HyperliquidExchangeAdapter` write methods (`placeOrder`, `cancelOrder`, `setLeverage`) throw `hyperliquid_mutation_requires_testnet_port` unless the adapter was given a `HyperliquidMutationReadinessProof`, which only `HyperliquidMutationReadinessGate::issueProof()` issues, and only for a fully cleared gate. No production code issues a proof yet.

## Rollback to fail-closed by config alone

Any one of these returns the source to fail-closed, with no code change:

1. Set `execution.kill_switch_enabled: true`, `execution.demo_testnet_write_enabled: false`, `execution.dry_run: true` and recompute `envelope_hash` (config stays loadable but cannot authorize a mutation).
2. Edit any value without recomputing `envelope_hash`, or delete the file (the source returns fail-closed).
3. Empty or reduce `eligible_identities`.

Independent of the envelope, `HYPERLIQUID_TESTNET_TRADING_ENABLED=0` blocks the gate, and the quarantine marker keeps its existing semantics (never removed by hand, `app:hyperliquid:testnet:quarantine-recover` only).

## Operator smoke command

`app:hyperliquid:testnet:smoke` now requires `--mode-id --mode-version --setup-id --setup-version --side` in addition to `--confirm` and `--readiness-decision`. After resolving the readiness for that identity it prints one line per condition, `verdict pass|fail <reason>`, then either refuses (`Smoke execution refused: mutation readiness blocked.`) or proceeds to the port. With `HYPERLIQUID_TESTNET_TRADING_ENABLED=0` it always refuses and places nothing. `exchange:runtime-check` has no identity and therefore reports the effective config as fail-closed (`canonical_identity_required`).

## What the owner must still provide

- A funded testnet master account and a dedicated approved agent wallet: `HYPERLIQUID_TESTNET_ACCOUNT_ADDRESS`, `HYPERLIQUID_TESTNET_AGENT_ADDRESS`, `HYPERLIQUID_API_BASE_URI=https://api.hyperliquid-testnet.xyz`.
- The signer sidecar image and `HYPERLIQUID_SIGNER_BASE_URI` / `HYPERLIQUID_SIGNER_AUTH_TOKEN`; the agent private key lives only in the sidecar. None of these are in `.env` today and none are in Git.
- Migrations `Version20260712120000` (kill switch state) and `Version20260712230000` (execution attempts) applied.
- A review of the envelope values and of the HL-012 prerequisites (replayed #188, R14-R16, proven rollback) before DEMO-005 is lifted.
- The decision to flip the runtime flags, taken by the owner outside this change.

## Open questions

1. Is `ShadowExecutionCapability::Paper` acceptable as the capability for a live testnet identity, or does the resolver need a dedicated testnet capability?
2. Final `max_notional` and `allowed_symbols`.
3. Which of the six identities are actually eligible for the first window.
4. Do we route the MTF flow through the testnet port (separate lot) or keep the smoke command as the only mutation path.
