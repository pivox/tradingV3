# Issue #196 modern Paper operator contract

## Scope

Expose the exact modern strategy identity to both Paper commands and resolve it
through the canonical Effective Config resolver. This slice does not translate
a canonical order plan into the legacy Paper prepared-effect format.

## Selection contract

Legacy usage keeps the single exact `--strategy-profile` option. Modern usage requires
all of `--mode-id`, `--mode-version`, `--setup-id`, `--setup-version` and
`--side`, and forbids `--strategy-profile`. The verified dataset supplies the public
venue and network environment; the capability is always exactly `paper`.
Missing, partial or mixed selection fails closed without aliases or fallback.
Identity errors and resolver failures are normalized to stable blocker codes;
underlying YAML and filesystem paths are never returned by either command.

## Readiness result

The service resolves the six canonical config layers, validates the config and
condition-catalog hashes, and constructs the v2 Paper cell identity. The
read-only check returns that exact redacted identity but remains not ready with
`paper_modern_strategy_bridge_unavailable`. The replay command enforces the same
preparation and rejects before snapshot/cell registration or dataset binding.

## Safety boundary

Legacy behavior and readiness payloads remain unchanged. A modern identity is
never marked baseline-eligible, and no private client or real exchange adapter
is introduced. Canonical effect persistence and Fake execution will be added in
a later slice without converting through `OrderPlanModel`.
