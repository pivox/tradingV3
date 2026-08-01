# Modern Risk Policy Blockers Design

## Scope

Modern trade preparation must stop before providers, sizing, leverage, planning, or order execution whenever a required risk or order policy remains unowned by the canonical contract. This change does not define #304 risk formulas and does not alter the explicit legacy path.

## Architecture

A single modern preflight policy validator consumes the typed `TradeEntryConfig` produced from the effective snapshot. It returns blockers in a stable order and throws before any provider or planner call. Builder and leverage retain local guards for direct callers that bypass preparation.

The ordered blockers are:

1. `canonical_risk_pct_pending_304` when canonical per-trade risk is absent.
2. `canonical_daily_loss_policy_pending_304` for the compound daily cap whose percent, absolute quote, and unrealized semantics are not yet implemented.
3. `canonical_end_of_zone_fallback_pending_304` when a modern LIMIT-to-MARKET fallback is requested without canonical ownership.
4. Structured runtime blockers for `max_concurrent_positions`, `mode_exposure_cap`, and `minimum_net_r`, which are carried in the typed config but have no modern consumers yet.

Published unresolved canonical decisions continue to fail during snapshot/config construction before this validator.

## End-of-zone behavior

Modern configuration never inherits `TradeEntryConfig` defaults for end-of-zone fallback. The effective modern value is disabled. Any attempted LIMIT-to-MARKET rewrite raises `canonical_end_of_zone_fallback_pending_304`. Legacy fallback behavior remains unchanged.

## Testing

Tests establish each stable local blocker, aggregate blocker ordering, and that modern preparation reaches none of the provider, preflight, planner, or order boundaries. Existing legacy tests remain the regression proof for unchanged legacy semantics.

## Lot2B and #304 boundary

This work only propagates declared values and exposes missing consumers. #304 owns per-trade risk semantics, compound daily-loss enforcement, concurrent-position and exposure enforcement, minimum-net-R enforcement, and canonical ownership of order fallback. Lot2B remains out of scope.
