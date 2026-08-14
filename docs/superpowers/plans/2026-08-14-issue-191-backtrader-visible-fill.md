# #191 Backtrader visible-fill integration plan

1. Add failing runtime tests for the v2 evidence boundary, non-fill, complete
   fill, partial-fill rejection and temporal same-bar exclusion.
2. Add an evidence-aware execution entry point that consumes only a strictly
   revalidated visible-queue result and reuses the existing exit state machine.
3. Bind queue/tape hashes into runtime input and result v2, and extend net
   outcome replay to authenticate the public-trade entry separately from the
   OHLCV terminal event.
4. Update the handbook/specification and run focused, full Python, static/docs
   and repository verification before opening the PR.

