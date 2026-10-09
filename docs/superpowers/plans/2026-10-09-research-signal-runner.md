# Verified Research Signal Runner Implementation Plan

**Goal:** consume the verified acquisition manifest and measure the real B1 worker on a training-only BTC/ETH window, retaining decisions/provenance and honest throughput. No trades, profit metrics, parameter search or holdout evaluation in this lot.

**Architecture:** Python CLI module `app.backtesting.research.signals` reads verified source archives in order and feeds a persistent PHP `app:research:signals` process per symbol. Producer/consumer I/O runs concurrently with bounded buffers so stdin/stdout/stderr cannot deadlock. The worker remains the only indicator/rule calculator.

**Dependencies:** phase A collector, and B1 protocol as implemented in `trading-app/src/Command/ResearchSignalWorkerCommand.php` and `TradingCore/Backtesting/Research/ResearchSignalSession.php`. Read the actual protocol rather than inventing result/summary keys. During implementation B1 is in the separate `research-canonical-signals` worktree; do not modify that worktree. Integration occurs when its PR is merged.

## Scope

- Create `python-orchestrator/app/backtesting/research/signals.py` and focused unit/integration tests under `python-orchestrator/tests/test_research_signals.py`. Split into one additional focused module if necessary; do not grow an untestable script or exclude it from the coverage gate.
- CLI requires explicit dataset root, private output root outside any repository, Symfony app directory, source start/end, score start and selected approved symbols. Freeze all inputs/hashes and fail on unsupported flags or ranges. Default maximum scored end is2026-01-01: this runner must not expose final2026 holdout before a future frozen-selection mechanism exists.
- The first real benchmark is source range2022-11-01T00:00:00Z through2023-02-01T00:00:00Z, score start2023-01-01T00:00:00Z, BTCUSDT and ETHUSDT. Pre-2023 decisions are not included in scored totals.

## TDD tasks

- [ ] Test source selection over monthly/daily ZIP and REST manifest entries. Check exact source identity, symbol, expected window coverage/count/order, no gaps, path escape or symlinks. A global acquisition may be partial because unrelated funding/current-month sources are missing: accept a selected candle subset only if its own coverage is independently complete; bind that precise subset rather than trusting global complete=true.
- [ ] Revalidate official ZIP/checksum/hash/CSV evidence through existing collector primitives, or locally hashed REST pages with their fixed provenance and strict rows. Bound all stored reads before allocation. Replay the same validated bytes when producing candle records; do not reread an unverified replacement after hashing.
- [ ] Stream normalized minute records with exact decimal fields and deterministic source_record_id binding source digest and row index. Do not forward-fill, drop errors, convert OHLC math into Python floats, or silently ingest observations after cutoff.
- [ ] Compute a reproducible subset identity from exact source hashes/ranges/order plus requested source/scored windows. Persist the captured acquisition-manifest hash separately, so subsequent progress elsewhere in that manifest cannot alter an already-frozen subset identity.
- [ ] Construct strict open/batch/close frames according to B1, at most1440 records and2MiB per line. Run one subprocess per symbol sequentially, argv without shell, explicit nonsecret Symfony environment with SYMFONY_DOTENV_PATH=/dev/null; never load user dotenv or contact DB/exchanges. No subprocess boot per tick.
- [ ] Use bounded concurrent stdin producer/stdout consumer/stderr drain. Enforce wall timeout, maximum record/line/output/stderr sizes, check every worker result schema/session/symbol/source identity and chronological availability, and reap/terminate only the child this runner started on failure. Preserve structured failure evidence, not an apparently complete empty result.
- [ ] Write result NDJSON and run report atomically/private, explicit fresh output directory outside repositories; reject broad existing directories, symlinks and collisions before changes. Retain partial artifacts under their distinct status on failure; never overwrite another run. This bounded benchmark runner need not resume; do not falsely advertise resume.
- [ ] Completion requires explicit matching worker summary, full source end/count, exact expected15m scored evaluation count after warmup, no structured error, exit0 and output hash finalization. A lock/PID/EOF alone is not completion.
- [ ] Record input/code/protocol/baseline hashes, source and scored counts, rule pass/reject counts (not trades), elapsed time, child peak RSS with accurately stated measurement semantics, candles/sec and evaluations/sec. Allow undefined rates for zero elapsed/count; do not call PHPUnit timing the real-data throughput.
- [ ] Tests cover malformed manifest/source/output, oversized/bad hash/gap data, early worker death/EOF/nonzero, timeout, stderr flood, stdout backpressure, differing batch partitions, partial data roots, warmup exclusion and holdout boundary. Fake-process fixtures test orchestration; one real-worker training benchmark is mandatory before full historical scheduling.
- [ ] Run collector+runner focused tests, full coverage/CI with unchanged95% gate, one consolidated review/correction cycle, merge; then independently verify real benchmark counts/hash/coverage before extrapolating.

## Continuation

Use measured throughput to schedule bounded training signal generation on the ten-pair dataset, preserving final holdout. Later B2/C consumes the retained signals for geometry variants and portfolio execution; this runner alone does not satisfy the requested statistics or Paper validation.
