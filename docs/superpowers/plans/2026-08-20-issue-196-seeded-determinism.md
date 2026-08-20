# #196 Seeded Fake/Paper determinism implementation plan

1. Add failing PHP tests for strict seed validation, domain-separated identity
   derivation, byte-identical fresh persisted states and seed-mismatch restart.
2. Implement the versioned PHP seed value object; replace private-WS random
   identities and bind the seed fingerprint to persistence/recovery metadata.
3. Wire the configured seed into the global Fake store and cell-scoped Paper
   runtimes; expose fail-closed readiness for non-certified legacy state.
4. Add failing Python tests for explicit seed validation, deterministic recipe
   evidence IDs, unique dispatch nonces and different-seed divergence; implement
   CLI/config support.
5. Extend Golden 20 to pass and attest one fixed seed, then compare normalized
   reports and full relevant state evidence from fresh stacks.
6. Update #196 audit and operator docs, explicitly excluding ephemeral atomic
   temp filenames from the deterministic contract.
7. Run focused and broad PHP/Python verification, PHPStan, Symfony/YAML/MkDocs
   lints and safety scans; open a ready PR, address actionable Codex threads and
   merge only on green.
