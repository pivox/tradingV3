# #196 Paper certification campaign runner implementation plan

1. Add failing unit/command tests for exact dataset-scope mapping, deterministic
   run IDs, input-digest resume guards and twelve-cell process isolation.
2. Add failing tests for fail-closed readiness identity/safety checks, timeout,
   replay failure, first-failure stopping and atomic state transitions.
3. Implement a small process executor abstraction backed by Symfony Process and
   a campaign state store that never exposes private inputs.
4. Implement the campaign service and CLI on top of the existing canonical
   matrix builder and Paper runtime/replay commands.
5. Document the operator invocation, resume semantics and explicit separation
   between replay completion and #132 trade certification.
6. Run focused tests, adjacent Paper regressions, static analysis and lints,
   then deliver through a ready PR and address only real review feedback.
