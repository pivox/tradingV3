# #132 Hyperliquid Live Indicator Warmup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Warm every Hyperliquid public live Paper dataset with exact canonical native indicator windows before its initial snapshot boundaries.

**Architecture:** Inject the existing public REST client into the live source, freeze exact closed range ends in the durable checkpoint, and use a focused warmup component to fetch and validate bounded ascending pages. Feed its normalized, globally ordered candles through the existing pending-event acknowledgement protocol before the initial boundaries.

**Tech Stack:** PHP 8.4, Symfony DI/Clock, Hyperliquid public REST, PHPUnit, PHPStan.

---

### Task 1: Durable warmup range contract

**Files:**
- Modify: `trading-app/tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperLiveCheckpointTest.php`
- Modify: `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperLiveCheckpoint.php`
- Modify: `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperLivePolicy.php`

- [ ] Add a failing checkpoint test asserting fresh exact state contains `initial_candle_window_ends` as `['BTC' => null, 'ETH' => null]`, and that only exact non-negative millisecond strings or null are accepted.
- [ ] Run `php bin/phpunit tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperLiveCheckpointTest.php --filter InitialCandleWindow` and confirm schema v2 rejects the new key.
- [ ] Bump checkpoint/policy versions and add immutable `withInitialCandleWindowEnds()` state that refuses changing an already pinned end.
- [ ] Re-run the checkpoint suite and commit the green contract.

### Task 2: Bounded REST warmup component

**Files:**
- Create: `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperLiveCandleWarmup.php`
- Create: `trading-app/tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperLiveCandleWarmupTest.php`

- [ ] Add a failing success test with a recording `HyperliquidPaperPublicRestClientInterface`: for each coin assert one 250-row request for 1m/5m/15m, two adjacent 500-row requests for 1h, exact ascending starts and an aligned 1h base.
- [ ] Run the focused test and confirm failure because `HyperliquidPaperLiveCandleWarmup` does not exist.
- [ ] Implement `events(array $pinnedEnds): array` using interval steps from `HyperliquidPaperInstrumentMap`, `HyperliquidCandle::fromPayload()` validation, exact expected start equality and `HyperliquidPaperMarketEventNormalizer::candle()`.
- [ ] Add separate red/green tests for empty, missing, duplicate, conflicting and out-of-range rows, all mapped to `hyperliquid_paper_public_candle_warmup_invalid`.
- [ ] Sort output by close time, normalized symbol and interval duration; rerun the full warmup test file and commit.

### Task 3: Live source, resume and service wiring

**Files:**
- Modify: `trading-app/tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicLiveSourceTest.php`
- Modify: `trading-app/tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicLiveSourceFactoryTest.php`
- Modify: `trading-app/tests/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicServiceWiringTest.php`
- Modify: `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicLiveSource.php`
- Modify: `trading-app/src/Trading/Paper/Hyperliquid/Live/HyperliquidPaperPublicLiveSourceFactory.php`
- Modify: `trading-app/config/services.yaml`

- [ ] Add a failing source test proving warmup candles precede metadata/funding/boundaries and the pinned end survives recreation after partial acknowledgement.
- [ ] Add a failing factory/wiring assertion for `HyperliquidPaperPublicRestClientInterface $restClient`.
- [ ] Pin ends from `ClockInterface` immediately after subscription readiness, instantiate the warmup component, and prepend its events to the existing initial candidates. On resume, recompute rows only from pinned ends and let acknowledged identities skip the durable prefix.
- [ ] Re-run source, factory, service-wiring, capture/replay equality and canonical indicator projection suites; commit when green.

### Task 4: Operator documentation and delivery

**Files:**
- Modify: `docs/handbook/runbooks/paper-market-replay.md`

- [ ] Document exact Hyperliquid live warmup coverage, public-only safety, restart semantics and stable integrity failure.
- [ ] Run focused and adjacent Paper PHPUnit suites, targeted PHPStan, Symfony container/YAML lint, MkDocs strict, PHP syntax and `git diff --check`.
- [ ] Review the complete diff for private exchange access, tuning, fallback or unrelated edits.
- [ ] Push, open a ready PR linked to #132, request substantive Codex review, resolve actionable threads and merge only with green CI and zero blocking threads.
