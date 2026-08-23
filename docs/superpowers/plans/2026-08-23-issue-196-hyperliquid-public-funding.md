# #196 Hyperliquid public funding implementation plan

1. Add failing REST and normalization tests for the exact credential-free
   `metaAndAssetCtxs` BTC/ETH funding contract.
2. Add failing live-source and dataset-verifier tests for initial/reconnect
   capture, exact source ordinals, and epoch binding.
3. Add failing canonical funding-source and modern replay tests proving
   one-hour Hyperliquid evidence and fail-closed missing/stale/mixed epochs.
4. Implement the public client interface, v2 normalizer, live capture, dataset
   authentication, service wiring, and dual-venue canonical source.
5. Run targeted and adjacent Paper/Hyperliquid suites, static analysis,
   container/YAML checks, and whitespace validation.
6. Commit, push a ready PR, request review, address real feedback, merge when
   CI and review are clear, then continue #196 with representative corpus
   capture and #132 exports.
