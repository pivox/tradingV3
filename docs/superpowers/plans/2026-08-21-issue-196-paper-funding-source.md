# Paper canonical funding source implementation plan

1. Add failing REST and normalizer tests for the allowlisted public endpoint, exact response shape, canonical signed rates, timestamps and interval.
2. Add failing live/checkpoint/verifier tests proving resumable capture, deterministic frontier identity and authenticated dataset acceptance/rejection.
3. Implement the public funding client, event channel, normalization and live capture plumbing.
4. Add failing source tests for exact cell/current-trigger binding, no-lookahead, availability ordering, policy interval and event lineage.
5. Implement the unwired canonical funding DTO/source.
6. Run focused and adjacent Paper tests, targeted PHPStan, container lint and a full diff review.
7. Publish a ready PR, request review when repository review capacity permits, address actionable feedback, require green CI, merge and record the #196 milestone.
