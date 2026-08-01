# Canonical effective trading configuration

Issue #133 replaces the COMMON-002 preparatory deep merge with a strict runtime
boundary. A request is identified by all of these exact values:

- `mode_id` and semantic `mode_version`;
- `setup_id` and semantic `setup_version`;
- `exchange`, `environment`, and `side`.

Aliases, version ranges, inferred defaults, and fallback profiles are rejected.
In particular, `regular`, `scalper`, `scalper_micro`, BitMart, and the historical
`scalping -> scalper` mapping are not modern effective-config identities.

## Contract and layer order

The resolver first loads and validates the immutable mode and setup contracts. It
requires bidirectional compatibility at the exact versions, identical setup and
request sides, an executable mode/setup, a publishable compiled setup snapshot,
and a resolved condition-catalog SHA-256.

Only then may it compose these six mandatory layers, in this exact order:

```text
base < mode < setup < exchange < mode_exchange < environment
```

There are no optional runtime layers and no `missing_optional_layers` result.
The mode layer is the validated mode contract. The setup layer is the canonical
compiler snapshot: it carries the full recursive AST (including confirmations,
filters, no-trade rules, and every execution decision), missing-data and typed
condition contracts, exact versions and hashes, source pins, contract provenance,
and blockers. It does not reconstruct a lossy subset from the raw YAML.
Historical compatibility pointers are provenance only and are never imports. The remaining
files are loaded from `config/trading`. A future executable pair file is named
`mode_exchange/{mode_id}.{mode_version}.{exchange}.yaml`, so an override cannot
float across mode versions.

Composition is ownership-aware rather than a generic deep merge:

- base owns schema, units, and ultimate safety guards;
- mode owns the mode envelope and risk decisions;
- setup owns hypothesis, side, regime/context/trigger, invalidation, entry zone,
  stop, and targets;
- exchange owns capabilities, fees, funding, precision, and limits;
- mode/exchange owns only a finite allowlist of typed override paths;
- environment owns allowlists, notional, dry-run/write gates, and kill switch.

Wrong-owner and unknown keys, duplicate ownership, missing targets, and
list/scalar type changes fail closed. The resulting readonly
`EffectiveTradingConfigSnapshot` exposes normalized JSON-compatible data, a
stable SHA-256, exact identities, condition-catalog hash, ordered source files,
and leaf provenance. Array accessors return copies, so callers cannot mutate the
snapshot.

## Current execution status and safety

The published #300/#301/#310 contracts remain draft or blocked and unresolved.
They therefore produce a structured non-executable result; no strategy values
were invented to make this boundary runnable. `crash_short@1.1.0` has no
compatible modern mode and is rejected before execution.

Supported modern venue targets are fake local/test, OKX demo, and Hyperliquid
testnet. Mainnet may remain public/read-only, but effective execution keeps
`mainnet_write_enabled=false`. Every #133 environment, including demo/testnet,
must declare `write_enabled=false`, `require_stop_loss=true`, and an active kill
switch. Every exchange layer must declare `capabilities.stop_loss=true`. Base
safety also requires `demo_testnet_write_enabled=false`,
`require_stop_loss=true`, and `kill_switch_enabled=true`. Activation belongs to
a later issue; #133 never enables writes. No secrets belong in any layer.

`GET /api/trading/config/effective` requires all seven identity query fields.
Known blocked contracts return HTTP 422 with `executable=false`, blockers, and no
config/hash. Unknown or mismatched identity returns a structured HTTP 400. A
successful future response includes the hash, ordered layers/files, and
provenance.

## Legacy quarantine and migration

`TradeEntryConfigProvider` and `MtfValidationConfigProvider` remain available
only for explicitly historical IDs. They reject modern IDs before opening a
legacy YAML file. `CanonicalTradingConfigRuntimeAdapter` is the immediate shared
MTF/TradeEntry request boundary and returns the same snapshot/hash to both sides;
full outcome lineage remains #302.

Callers that currently possess only a legacy profile cannot manufacture missing
mode/setup versions or side. They remain fail closed until their request DTO is
migrated. This is intentional and prevents `trade_entry.yaml`, generic MTF YAML,
or BitMart from becoming an implicit runtime fallback.

Rollback is a code rollback: revert the #133 wiring commit and redeploy. Do not
restore service by adding runtime fallback, aliases, optional layers, or by
editing published contract status/thresholds.
