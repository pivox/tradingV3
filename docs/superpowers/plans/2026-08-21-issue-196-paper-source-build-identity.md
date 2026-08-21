# Issue #196 durable Paper source-build identity implementation plan

1. Add a migration test proving the new source-build column is nullable,
   constrained and never backfilled.
2. Add failing store tests for exact persistence/restart, idempotence,
   conflict rejection, legacy compatibility and modern fail-closed behavior.
3. Extend the store contract and both Doctrine/in-memory implementations with
   the optional legacy-compatible source-build argument and exact modern rules.
4. Pass the verified manifest recorder version from the replay command and
   require it when the modern coordinator reloads dataset identity.
5. Run focused store/command/coordinator tests, migration tests, targeted
   PHPStan, container lint and diff checks.
6. Publish a ready PR, request Codex review, address actionable feedback, and
   merge only the exact reviewed green SHA.
