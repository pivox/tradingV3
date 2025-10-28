# Indicator Refactor – Next Steps

This document captures the plan so the pending refactor can be resumed quickly.

## 1. Restore Condition Sources
- Several condition classes are missing or empty in `src/Indicator/Condition` (`CloseAboveVwapOrMa9Condition.php`, `Ema200SlopeNegCondition.php`, `MacdLineCrossUpWithHysteresisCondition.php`, etc.).
- Recuperate their original content (from `main` or backups) before making further changes.

## 2. Annotate Conditions
- For each concrete `ConditionInterface` implementation, add `#[AsIndicatorCondition(...)]`.
- Use the MTF validation config (`config/app/mtf_validations.yaml`) to determine:
  - `timeframes`: list of frames where the rule is referenced.
  - `side`: `"long"`, `"short"`, or `null` when both.
  - `name`: reuse the `self::NAME` constant.
- Once attributes exist, drop the legacy `_instanceof`/tag entries that become redundant.

## 3. Rework the Compiler Pass
- Finish `src/Indicator/Compiler/IndicatorCompilerPass.php` so it:
  - Scans tagged/attributed conditions.
  - Builds service locators by timeframe and timeframe+side.
  - Injects those locators into the new registry (see below).

## 4. Update the Registry
- Adapt `src/Indicator/Registry/ConditionRegistry.php` to accept the locators produced by the compiler pass (O(1) lookups by timeframe/side).
- Keep backwards compatibility helpers (`names()`, `get()`, `evaluate()`), but rely on the locators instead of the generic iterator.

## 5. Expose a Provider
- Create a dedicated provider interface under `src/Contract/Indicator/` exposing the required operations (snapshot persistence already handled).
- Implement the provider in `src/Indicator/Provider/IndicatorProviderService.php` and register it in the container.

## 6. Diagnostic Command
- Add a Symfony command (e.g. `app:indicator:conditions:test`) that:
  - Lists the available conditions and their metadata.
  - Fetches klines via `MainProvider->getKlineProvider()->getKlines()` for a given symbol/timeframe.
  - Evaluates the relevant conditions and prints the results.

## 7. Validation / QA
- After the above steps, run targeted linters (`php -l`) and functional tests touching indicators/conditions.
- Ensure `bin/console cache:clear` succeeds to validate DI wiring.

Keeping this order should make the refactor deterministic and reduce the risk of conflicting changes.
