# #196 Hyperliquid instrument v2 implementation plan

1. Add failing normalizer, source-ordinal, dataset-verifier, adapter, and Python
   mirror tests for the exact v2/v3 metadata contracts and leverage-tier
   notionals.
2. Implement the versioned public metadata payload while retaining strict v1
   historical reads and refusing v1 canonical execution evidence.
3. Add failing canonical-instrument tests for book-bound dynamic ticks,
   quantity caps, lineage hashes, and fail-closed mismatches.
4. Implement the reusable Hyperliquid price-step rule and compose authenticated
   book evidence before instrument evidence.
5. Preserve USDC settlement in the Hyperliquid Fake instrument descriptor while
   keeping the risk ledger in the USDT contract quote currency.
6. Run targeted PHP/Python tests, adjacent Paper/TradingCore suites, static
   analysis, container/YAML checks, and whitespace validation.
7. Commit, push a ready PR, request review, resolve real feedback, merge when CI
   and review are clear, then continue the global chantier with public
   Hyperliquid funding evidence.
