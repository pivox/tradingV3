# Issue #196 modern Paper operator implementation plan

1. Add failing tests for exclusive legacy/modern operator selection.
2. Add a failing runtime-check test proving exact canonical config resolution,
   redacted v2 identity output and the stable bridge blocker.
3. Add a failing replay test proving the modern path writes no Paper state.
4. Implement the selection value object and dual-path readiness preparation.
5. Make blocked preparations non-runnable and preserve the legacy v1 payload.
6. Update the runbook, run focused and broad Paper verification, then request
   review and merge only with green checks and no blocking feedback.
