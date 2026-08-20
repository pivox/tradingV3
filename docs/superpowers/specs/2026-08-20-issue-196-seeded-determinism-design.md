# #196 Seeded Fake/Paper determinism design

## Scope

Close the explicit-seed acceptance gap for fresh deterministic Fake runs. The
contract covers business-visible and persisted identities, state, events and
cost evidence. Filesystem names used only for atomic temporary writes are
excluded because they are neither persisted nor exported.

## Contract

`fake-deterministic-seed-v1` accepts one explicit 8–128 character seed. Raw seed
material is never persisted or logged; evidence exposes only its SHA-256
fingerprint. Identity derivation uses HMAC-SHA256 with versioned domain names
and canonical components.

The PHP Fake state store derives private-WS resync cycle, snapshot proof and
attestation identities from the seed plus their exact scenario/state context.
The persisted envelope records the schema and seed fingerprint. Restoring that
envelope under a different seed fails closed. Legacy state remains identifiable
as non-certified and must not silently become seeded proof while a resync is
active.

The Python recipe runner accepts the same explicit seed contract and derives
its invocation identity from the seed, selected scenarios and target. The
runtime report and R12 standalone report expose the schema and fingerprint,
never the raw seed. Child demo runners receive domain-separated derived seeds.

## Acceptance proof

- identical operations and controlled clocks under the same seed produce
  byte-identical persisted state, events, costs and reports from fresh stores;
- a different seed changes the derived identities and fingerprint;
- restart with the same seed preserves state exactly; restart with another seed
  fails with a stable typed reason;
- no `random_bytes`, UUID or ambient PRNG remains in the certified Fake identity
  paths;
- runtime readiness reports whether the persisted state is seed-certified;
- all Golden scenarios remain Fake-only and no exchange write is enabled.

## Compatibility and safety

The default Symfony wiring names a canonical local Fake seed so existing direct
tests remain deterministic, while recipe CLI/config surfaces allow an explicit
override. Raw seeds are treated as local test configuration, not credentials.
Paper state with an older envelope is never claimed as certified merely because
a seed is now configured. No mainnet, demo or testnet execution capability is
introduced.
