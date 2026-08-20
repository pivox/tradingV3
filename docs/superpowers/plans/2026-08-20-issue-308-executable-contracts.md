# Issue #308 executable contracts implementation plan

1. Add failing loader/schema tests for the exact mode and setup 1.1.0 decisions while preserving published 1.0.0 hashes.
2. Publish the mode contract and freeze its PHP and JSON Schema validation.
3. Publish both setup contracts, pin catalog 1.2.0, and freeze source origins, execution values, provenance, and semantic hashes.
4. Add OKX and Hyperliquid runtime overlays only; verify Fake and unsupported paths remain fail-closed.
5. Exercise the strict compiler and canonical runtime with fresh authenticated microstructure evidence.
6. Run focused tests, the Trading Core suite, PHPStan, container lint, documentation checks, and immutable-hash checks before review.
