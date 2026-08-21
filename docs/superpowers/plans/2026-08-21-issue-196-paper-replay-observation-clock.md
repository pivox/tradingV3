# Paper replay observation clock implementation plan

1. Add failing replay-reader tests for the receipt-aware monotone watermark while retaining the initial regression guard.
2. Implement the smallest clock advancement helper in `PaperReplayReader` without changing event sort order or checkpoint identity.
3. Run focused replay and canonical indicator source tests, then the adjacent Paper suite, static analysis, and container lint.
4. Rebase onto merged `main`, request review, require green CI and no blocking thread, then merge and record the #196 milestone.
