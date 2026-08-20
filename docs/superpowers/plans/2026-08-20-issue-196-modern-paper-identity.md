# Issue #196 modern Paper identity implementation plan

1. Add failing unit tests for an immutable exact modern identity, network/venue
   matching, complete hashes and rejection of aliases or partial tuples.
2. Add failing cell tests proving legacy v1 IDs stay byte-stable while modern v2
   IDs differ for every certification-cell dimension.
3. Implement the modern identity value object and the additive cell factory.
4. Add an additive PostgreSQL migration and store tests for exact registration,
   inspection and conflict detection without historical backfill.
5. Add the coordinator fail-closed boundary preventing any modern mutation until
   the canonical strategy/effect bridge exists.
6. Update the Paper audit/runbook, run focused PHPUnit/PHPStan/container checks,
   request review and merge only with no unresolved blocking feedback.
