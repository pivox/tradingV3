# #196 — Canonical Paper instrument source

## Goal

Produce a `CanonicalInstrumentSnapshot` for a modern Paper cell only when the
authenticated applied replay prefix contains complete, current, public OKX
instrument constraints. Never manufacture a venue cap and never fall back to
the Fake catalog or a legacy profile.

## Decision

OKX public instrument metadata is upgraded to
`paper-instrument-metadata.v2`. Version 2 adds the positive canonical
`maximum_leverage` value read from the public `lever` response field. Existing
v1 replay events remain valid backtest/conversion evidence, but they are not
complete enough for a canonical order plan.

The backtest adapter remains the single strict normalizer for replay
instrument metadata. Its normalized value retains all sizing constraints and
exposes a standalone prefix adapter, ordered by availability, for the Paper
strategy boundary.

`PaperCanonicalInstrumentSource`:

- accepts modern cells only;
- binds network, venue, symbol and the exact current trigger;
- applies exchange- and receipt-time no-lookahead;
- selects the latest metadata by canonical availability order;
- returns a snapshot only for complete OKX v2 metadata settled in USDT;
- uses `contract_value * contract_multiplier` as contract size;
- uses the public limit and market quantity caps and the public leverage cap;
- binds lineage as `sha256:<metadata event id>`;
- returns `null` for missing or explicitly incomplete evidence;
- propagates malformed metadata as a strict adapter failure.

Hyperliquid remains non-executable at this boundary: its current public event
does not contain an absolute maximum quantity and settles in USDC while the
modern risk contracts currently account in USDT. Those facts must be resolved
explicitly in a later contract version.

## Safety

This slice is read-only and remains unwired. It neither enables the modern
strategy bridge nor contacts any private or mutative endpoint. Mainnet remains
public and read-only.

