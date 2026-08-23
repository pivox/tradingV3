# #196 modern Paper baseline eligibility implementation plan

1. Add failing readiness, replay, persistence, entity, migration and reporting
   tests for the `baseline_eligible` boundary.
2. Mark only successfully resolved modern Paper identities baseline eligible;
   keep every legacy profile reference-only.
3. Extend database constraints additively without updating historical rows.
4. Require the eligibility in both #132 SQL and Python certification gates.
5. Run focused and adjacent verification, then deliver through a reviewed PR.
