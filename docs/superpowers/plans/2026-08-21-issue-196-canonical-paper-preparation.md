# Issue #196 canonical Paper preparation implementation plan

1. Add focused failing tests for missing evidence, no-trade outcomes, exact
   identity policy flags, successful planned conversion and malformed
   admission-proof rejection.
2. Implement `PaperCanonicalStrategyPreparation` with the strict identity
   policy and verified planned-outcome conversion.
3. Prove the service container still leaves the coordinator's canonical
   strategy dependency unset while the evidence provider is unavailable.
4. Run focused tests, targeted PHPStan, container lint and the relevant Paper
   execution regression suite.
5. Commit, publish a ready PR, request review, address actionable feedback and
   merge only the exact green reviewed SHA.
