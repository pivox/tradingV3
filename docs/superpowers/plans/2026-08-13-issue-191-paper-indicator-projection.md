# #191 Paper Candle Indicator Projection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn verified Paper v2 candle windows into deterministic, evidence-bound PHP indicator snapshots that can be passed unchanged to the canonical TradingCore rule bridge.

**Architecture:** A pure PHP boundary validates native candle windows, derives UTC `4h` bars, and delegates calculations to an explicit PHP-fallback indicator facade. A strict Symfony JSON command exposes that boundary, while a frozen Python adapter verifies complete dataset artifacts, selects exact bounded windows, and invokes the command without reproducing indicator logic.

**Tech Stack:** PHP 8.3/Symfony Console/Brick Math/PHPUnit/PHPStan, Python 3.11+/Pydantic/subprocess/pytest.

---

### Task 1: Strict Paper candle windows and UTC 4h aggregation

**Files:**
- Create: `trading-app/src/TradingCore/Backtesting/Indicator/CanonicalIndicatorProjectionException.php`
- Create: `trading-app/src/TradingCore/Backtesting/Indicator/CanonicalIndicatorCandle.php`
- Create: `trading-app/src/TradingCore/Backtesting/Indicator/CanonicalIndicatorWindow.php`
- Create: `trading-app/src/TradingCore/Backtesting/Indicator/CanonicalFourHourAggregator.php`
- Test: `trading-app/tests/TradingCore/Backtesting/Indicator/CanonicalIndicatorWindowTest.php`
- Test: `trading-app/tests/TradingCore/Backtesting/Indicator/CanonicalFourHourAggregatorTest.php`

- [ ] **Step 1: Write failing exact-window tests.** Cover exact `backtest-candle.v1` keys/types, canonical decimal geometry, matching source binding/symbol/timeframe, oldest-first order, UTC grid, closed/available at `evaluated_at`, duplicate/gap/reversal rejection, 249 rejection, and exactly 250 acceptance. Assert stable `canonical_indicator_*` reason codes.
- [ ] **Step 2: Run the focused tests and verify RED.**

  ```bash
  cd trading-app
  php bin/phpunit tests/TradingCore/Backtesting/Indicator/CanonicalIndicatorWindowTest.php
  ```

  Expected: failure because the window classes do not exist.

- [ ] **Step 3: Implement immutable candle/window validation.** Parse timestamps only with `Y-m-d\TH:i:s.u\Z`, use `BigDecimal` for OHLCV, require exact native durations, and expose `toArray()`, `openTimestamp()`, and exact canonical `windowHash()` methods. Do not accept `4h` source records.
- [ ] **Step 4: Write failing 4h aggregation tests.** Cover 1,000 exact `1h` bars, UTC 00/04/08/12/16/20 bins, first-open/max-high/min-low/fourth-close/summed-volume/max-availability, 999 rejection, misaligned first hour, and missing/duplicated components.
- [ ] **Step 5: Implement the minimal aggregator.** Return exactly 250 immutable derived `4h` candles whose provenance contains the four component record IDs and a deterministic derived record hash; never backfill or substitute.
- [ ] **Step 6: Run both test classes, diff check, and commit.**

  ```bash
  cd trading-app
  php bin/phpunit tests/TradingCore/Backtesting/Indicator/CanonicalIndicatorWindowTest.php tests/TradingCore/Backtesting/Indicator/CanonicalFourHourAggregatorTest.php
  vendor/bin/phpstan analyse src/TradingCore/Backtesting/Indicator tests/TradingCore/Backtesting/Indicator --memory-limit=1G
  cd .. && git diff --check
  git add trading-app/src/TradingCore/Backtesting/Indicator trading-app/tests/TradingCore/Backtesting/Indicator
  git commit -m "feat(#191): validate canonical Paper indicator windows"
  ```

### Task 2: Deterministic PHP indicator context and projection protocol

**Files:**
- Create: `trading-app/src/TradingCore/Backtesting/Indicator/CanonicalPhpIndicatorCalculator.php`
- Create: `trading-app/src/TradingCore/Backtesting/Indicator/CanonicalIndicatorProjection.php`
- Create: `trading-app/src/TradingCore/Backtesting/Indicator/CanonicalIndicatorProjectorInterface.php`
- Create: `trading-app/src/TradingCore/Backtesting/Indicator/CanonicalIndicatorProjector.php`
- Modify: `trading-app/src/Indicator/Core/Momentum/Rsi.php`
- Modify: `trading-app/src/Indicator/Core/Momentum/Macd.php`
- Modify: `trading-app/src/Indicator/Core/Trend/Ema.php`
- Modify: `trading-app/src/Indicator/Core/Trend/Adx.php`
- Modify: `trading-app/src/Indicator/Core/Trend/Sma.php`
- Modify: `trading-app/src/Indicator/Core/AtrCalculator.php`
- Test: `trading-app/tests/TradingCore/Backtesting/Indicator/CanonicalPhpIndicatorCalculatorTest.php`
- Test: `trading-app/tests/TradingCore/Backtesting/Indicator/CanonicalIndicatorProjectorTest.php`

- [ ] **Step 1: Write failing pure-backend tests.** Use a fixed 250-bar fixture and assert the complete condition context: close, EMA current/previous/slope/series, RSI, MACD scalar/gap tails and timestamps, VWAP, ATR, ADX 14/15, MA21 bands, volume ratio, pullback age, high/low tails, series order, and only finite values. Assert repeated canonical JSON bytes are identical.
- [ ] **Step 2: Run the calculator test and verify RED.**

  ```bash
  cd trading-app
  php bin/phpunit tests/TradingCore/Backtesting/Indicator/CanonicalPhpIndicatorCalculatorTest.php
  ```

  Expected: failure because `CanonicalPhpIndicatorCalculator` does not exist.

- [ ] **Step 3: Expose explicit pure-PHP Core methods.** Refactor the existing fallback branches into public methods named `calculateFullPhp`, `calculateSeriesPhp`, `calculatePhp`, or `computePhp` as appropriate. Existing public methods retain their current opportunistic `trader_*` behavior; the canonical facade calls only the explicit PHP methods. Do not change formulas or condition semantics; `macd_line_signal_series` remains the signed MACD-minus-signal gap.
- [ ] **Step 4: Implement the immutable calculator facade.** Accept one validated 250-candle window, convert canonical decimals once, invoke only explicit PHP Core methods plus `Vwap` and `CanonicalPullbackAgeCalculator`, align every series to candle-open timestamps, and recursively reject `NaN`/`INF`/missing required outputs.
- [ ] **Step 5: Write failing projector protocol tests.** Assert exact request keys, `php_fallback_v1`, local/test only, fake snapshot identity, dataset binding, unique duration-ordered requested timeframes, native window selection, derived `4h`, no partial success, per-window/input/result hashes, and byte-identical replay. Reject forged/extra fields and source/execution identity confusion.
- [ ] **Step 6: Implement `CanonicalIndicatorProjector`.** Normalize the exact request, instantiate native windows, derive `4h` only from 1,000 `1h` bars, calculate each snapshot, add `snapshot_identity`, `kline_time`, engine/window evidence, then hash the normalized input and result using canonical JSON.
- [ ] **Step 7: Run focused tests, targeted PHPStan, diff check, and commit.**

  ```bash
  cd trading-app
  php bin/phpunit tests/TradingCore/Backtesting/Indicator
  vendor/bin/phpstan analyse src/TradingCore/Backtesting/Indicator src/Indicator/Core tests/TradingCore/Backtesting/Indicator --memory-limit=1G
  cd .. && git diff --check
  git add trading-app/src/TradingCore/Backtesting/Indicator trading-app/src/Indicator/Core trading-app/tests/TradingCore/Backtesting/Indicator
  git commit -m "feat(#191): project deterministic PHP indicator contexts"
  ```

### Task 3: Strict Symfony stdin/stdout command

**Files:**
- Create: `trading-app/src/TradingCore/Backtesting/Json/StrictJsonObjectDecoder.php`
- Create: `trading-app/src/Command/BacktestProjectCanonicalIndicatorsCommand.php`
- Modify: `trading-app/src/Command/BacktestEvaluateCanonicalRulesCommand.php`
- Test: `trading-app/tests/TradingCore/Backtesting/Json/StrictJsonObjectDecoderTest.php`
- Test: `trading-app/tests/Command/BacktestProjectCanonicalIndicatorsCommandTest.php`
- Modify: `trading-app/tests/Command/BacktestEvaluateCanonicalRulesCommandTest.php`

- [ ] **Step 1: Write failing shared-decoder tests.** Assert one object only, valid UTF-8, no duplicate keys/trailing JSON, depth at most 128, at most 20,000 structural tokens, and an 8 MiB input limit with stable reason codes.
- [ ] **Step 2: Extract and test `StrictJsonObjectDecoder`.** Move the already-proven scanner behavior from `BacktestEvaluateCanonicalRulesCommand` into one final service and make the existing rule command delegate to it without changing stdout, stderr, or exit codes.
- [ ] **Step 3: Write failing projection-command tests.** Assert exact one-line successful stdout, deterministic repeat, blank/malformed/oversized/multiple/non-object inputs, projector failures, empty stdout on failure, sanitized stable stderr, and `Command::INVALID` failures.
- [ ] **Step 4: Implement `app:backtest:indicators:project`.** Read at most 8 MiB plus one byte from stdin, decode through the shared strict service, call only `CanonicalIndicatorProjectorInterface`, encode compact canonical result plus one newline, and place diagnostics only on stderr.
- [ ] **Step 5: Run both command suites, Symfony lint, PHPStan, and commit.**

  ```bash
  cd trading-app
  php bin/phpunit tests/TradingCore/Backtesting/Json tests/Command/BacktestProjectCanonicalIndicatorsCommandTest.php tests/Command/BacktestEvaluateCanonicalRulesCommandTest.php
  php bin/console lint:container
  vendor/bin/phpstan analyse src/Command src/TradingCore/Backtesting tests/Command tests/TradingCore/Backtesting --memory-limit=1G
  cd .. && git diff --check
  git add trading-app/src/Command trading-app/src/TradingCore/Backtesting/Json trading-app/tests/Command trading-app/tests/TradingCore/Backtesting/Json
  git commit -m "feat(#191): expose canonical indicator projection CLI"
  ```

### Task 4: Verified Python dataset window and subprocess bridge

**Files:**
- Create: `python-orchestrator/app/backtesting/indicator_bridge.py`
- Create: `python-orchestrator/tests/test_backtesting_indicator_bridge.py`
- Modify: `python-orchestrator/app/backtesting/__init__.py`

- [ ] **Step 1: Write failing frozen-model/window tests.** Construct OKX and Hyperliquid `DatasetArtifacts`, call `DatasetSerializer.verify`, and assert exact source binding, unique requested indicator timeframes, native 250-bar suffixes, 1,000 `1h` bars for derived `4h`, `1h`/`4h` coexistence, insufficient coverage rejection, and no `4h` source record generation.
- [ ] **Step 2: Run focused pytest and verify RED.**

  ```bash
  cd python-orchestrator
  pytest -q tests/test_backtesting_indicator_bridge.py
  ```

  Expected: collection failure because `indicator_bridge` does not exist.

- [ ] **Step 3: Implement strict request/result models and verified window builder.** Use frozen strict Pydantic models, `DatasetSerializer.verify(artifacts)` before parsing/slicing, exact descriptor/checksum binding, canonical oldest-first records, and a distinction between native source timeframes and requested derived indicator timeframes. Never calculate or aggregate an indicator in Python.
- [ ] **Step 4: Write failing subprocess tests.** Cover fixed shell-free argv/stdin, 15-second default timeout, finite bounds, missing executable, timeout cleanup, non-zero status, malformed/multiple/duplicate/oversized/non-UTF-8 output, identity drift, and forged window/input/result hashes.
- [ ] **Step 5: Implement the bounded subprocess bridge.** Reuse the proven `Popen` reader/writer pattern from `tradingcore_bridge.py`, cap stdout and stderr independently, kill and reap on every failing path, never retry, and validate every echoed identity and hash.
- [ ] **Step 6: Add real cross-runtime goldens.** Invoke Symfony with verified OKX and Hyperliquid fixtures, repeat each request twice, and assert byte-identical canonical result payloads whose snapshots validate as inputs to `CanonicalBacktestRuleRequest`.
- [ ] **Step 7: Run focused/full Python tests, compile, and commit.**

  ```bash
  cd python-orchestrator
  pytest -q tests/test_backtesting_indicator_bridge.py
  PYTHONHASHSEED=987654 pytest -q
  python -m compileall -q app tests
  cd .. && git diff --check
  git add python-orchestrator/app/backtesting/indicator_bridge.py python-orchestrator/app/backtesting/__init__.py python-orchestrator/tests/test_backtesting_indicator_bridge.py
  git commit -m "feat(#191): bridge verified Paper candles to PHP indicators"
  ```

### Task 5: Documentation, full verification, and delivery

**Files:**
- Modify: `docs/handbook/technical/backtesting-engine.md`

- [ ] **Step 1: Document the boundary.** Describe dataset verification, exact source windows, derived `4h`, PHP-only engine version, fake execution identity versus market-data provenance, CLI reproduction, hashes, limits, stable failures, and explicit exclusions.
- [ ] **Step 2: Run fresh PHP verification.**

  ```bash
  cd trading-app
  php bin/phpunit tests/TradingCore/Backtesting/Indicator tests/TradingCore/Backtesting/Json tests/Command/BacktestProjectCanonicalIndicatorsCommandTest.php tests/Command/BacktestEvaluateCanonicalRulesCommandTest.php tests/MtfValidator/Policy/CanonicalSetupRuleRuntimeTest.php
  vendor/bin/phpstan analyse src/TradingCore/Backtesting src/Indicator/Core src/Command tests/TradingCore/Backtesting tests/Command --memory-limit=1G
  php bin/console lint:container
  ```

  Expected: zero failed tests, zero PHPStan errors, and a valid container.

- [ ] **Step 3: Run fresh Python and documentation verification.**

  ```bash
  cd python-orchestrator
  PYTHONHASHSEED=1 pytest -q
  PYTHONHASHSEED=987654 pytest -q
  python -m compileall -q app tests
  cd ..
  mkdocs build --strict
  git diff --check
  ```

  Expected: both suites pass the repository coverage gate, compile succeeds, MkDocs is strict-clean, and the diff has no whitespace errors.

- [ ] **Step 4: Perform independent review and fix concrete findings.** Review the complete diff against the written spec, then repeat only the affected verification plus the full final gates. Do not create artificial review cycles if there is no actionable feedback.
- [ ] **Step 5: Commit documentation and deliver the PR.**

  ```bash
  git add docs/handbook/technical/backtesting-engine.md
  git commit -m "docs(#191): document Paper indicator projection"
  git push -u origin codex/issue-191-paper-indicator-projection
  gh pr create --draft --base main --head codex/issue-191-paper-indicator-projection --title "feat(#191): project Paper candles into canonical PHP indicators" --body-file /tmp/issue-191-indicator-pr.md
  ```

  Mark ready after local evidence is posted. Request one real review, address every concrete thread, wait for CI, and merge only when all checks are green and no unresolved blocking thread remains.
