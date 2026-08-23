# #132 certification matrix design

## Goal

Make the certification population complete and auditable before generating the
final Markdown/JSON/CSV baseline. A cell that has zero certified trades must be
visible and must never disappear from the sampling gate.

## Contract

The certification matrix is the Cartesian product of:

- explicitly versioned public Paper scopes (`mainnet/okx` and
  `mainnet/hyperliquid` for the first baseline);
- exact executable modern mode contracts;
- exact executable setup contracts that declare compatibility with the selected
  mode version;
- the canonical setup side.

The resulting identity is
`paper_network × market_data_venue × mode_id × setup_id × canonical_side`.
Mode and setup versions remain mandatory provenance in the manifest even though
the historical #132 aggregation key does not include them.

`crash_short` and `day_trading.trend_continuation.short` are absent because their
exact contracts are not executable. Legacy profiles and aliases are never
accepted.

## Components

1. A small versioned JSON input pins scopes and exact mode versions. There is no
   `latest` lookup or filesystem-version fallback.
2. A PHP builder loads and validates the canonical mode/setup contracts and
   emits a deterministic manifest with stable ordering and a SHA-256 digest.
3. A console command writes the manifest as JSON for the #132 operator flow.
4. `bad_trades_baseline.py` optionally consumes the manifest. It reports every
   expected cell, including count zero, rejects duplicate/malformed manifest
   cells, exposes unexpected certified cells separately, and aggregates only
   expected cells meeting the global minimum of 50.

## Failure and safety semantics

- Missing, malformed, duplicate, unsupported, incompatible, or non-executable
  contract identities fail closed.
- A certified CSV row outside the expected matrix is not silently aggregated.
- The minimum remains globally bounded at 50 and cannot be lowered.
- The manifest does not create trades, replay datasets, PnL, or exchange effects.
- All market scopes are public/read-only; private mainnet execution remains
  forbidden.

## Verification

Unit tests cover the exact 12-cell first-baseline matrix, blocked setup exclusion,
stable ordering/hash, malformed scope rejection, zero-count reporting,
unexpected-cell exclusion, and CLI output. Existing baseline regression tests
remain green.
