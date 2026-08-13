# #191 Modern Backtest Identity Design

## Objective

Replace the temporary `regular` / `scalper` / `scalper_micro` backtest boundary with the exact modern identity and immutable effective configuration already owned by #300, #301, #133, and #303. Old payloads must fail validation; this lot provides no aliases or migration fallback.

## Scope

This lot changes Python contracts only. It covers the shared modern identity, the canonical effective-config snapshot, `BacktestRunRequest`, and executed-trade ledger identity. It does not implement Backtrader execution, make a mode executable, tune trading rules, or enable any private mainnet action.

## Architecture

Create `app/modern_trading_contracts.py` as the dependency-neutral owner of:

- the published mode/setup/version matrix;
- exact `long` / `short` setup compatibility;
- exchange/environment compatibility;
- immutable six-layer #133 snapshots;
- canonical PHP-compatible config and snapshot hashing.

Both `app.schemas` and `app.backtesting.contracts` import this module. This removes the current cycle where `schemas.py` imports `FrozenDict` from backtesting while backtesting consumers would otherwise need orchestration schemas.

The backtest request embeds one exact identity plus a canonical snapshot. It rejects any mismatch among the request, snapshot request, effective config roots, config hash, condition catalog hash, and snapshot hash. Market-data venue remains independent from the simulated exchange: a `fake` run may replay verified OKX or Hyperliquid data.

## Published identities

Accepted modes are exact published versions only:

- `day_trading`: `1.0.0`, `1.1.0`;
- `scalping`: `1.0.0`, `1.1.0`;
- `micro_scalping`: `1.0.0`.

Accepted setups are the eight #301 entries, including `crash_short`. Each setup version must exist on disk and its side/mode compatibility must match the frozen catalogue. `crash_short@1.1.0` follows the current #310 distinct-envelope decision and is not accepted as a run identity until it has a compatible modern mode; its presence remains catalogue-level only.

No enum or validator accepts `regular`, `scalper`, `scalper_micro`, `latest`, ranges, case folding, or whitespace normalization.

## Effective configuration snapshot

The snapshot is a frozen, `extra="forbid"` Pydantic model with the exact PHP fields:

`request`, `config`, `config_hash`, `condition_catalog_hash`, `ordered_layers`, `ordered_files`, `provenance`, `executable`, `blockers`, and `snapshot_hash`.

The six required layers are exactly `base`, `mode`, `setup`, `exchange`, `mode_exchange`, and `environment`. Their paths must equal `ordered_files`; every provenance value must be one of those exact layer objects.

`config_hash` is SHA-256 over canonical JSON containing `config` and `condition_catalog_hash`. `snapshot_hash` is SHA-256 over the whole snapshot excluding `snapshot_hash`. Canonical encoding sorts mapping keys, preserves Unicode and slashes, rejects non-finite floats and ambiguous objects, and normalizes integral floats to integers to match PHP.

Backtest snapshots must be executable and have no blockers. This is stricter than general orchestration lineage, where a non-executable snapshot may be retained as evidence.

## Run and ledger contracts

`BacktestRunRequest` contains `identity` and `config`; the legacy `profile` field is removed. Its reproducibility fingerprint binds the full identity, both hashes, snapshot hash, dataset, period, code version, seed, cost model, and intrabar policy.

`BacktestTradeLedgerEntry` replaces `profile` with the seven exact identity fields and both configuration hashes. Its direction must match setup side. Existing cost and mandatory-stop invariants remain unchanged. This avoids producing new artifacts that could still be grouped by legacy profile.

All modern models deeply freeze mappings and sequences and reject extra fields. Historical JSON containing `profile` becomes deliberately invalid.

## Verification

Tests cover the published matrix, aliases and unpublished versions, side and environment mismatches, deep immutability, exact layer order, config/snapshot hash tampering, PHP golden snapshot compatibility, non-executable snapshots, request identity divergence, ledger identity divergence, and fingerprint sensitivity. The existing full Python suite and coverage gate must remain green.

## Deferred work

- Invoke the canonical PHP TradingCore rule path from the Python runtime.
- Implement deterministic Backtrader execution, conservative intrabar handling, costs, funding, fills, and canonical results.
- PostgreSQL replay/certification and the 50-trade-per-cell evidence requirement.
