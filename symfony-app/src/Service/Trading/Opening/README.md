# Trading Opening Module

This package isolates the orchestration required to open derivatives positions on BitMart. It replaces the monolithic `PositionOpener` by composing small services that each follow a single responsibility and can be tested independently.

## Overview

The entry point is `OpenMarketService`, which exposes a single `open(OpenMarketRequest $request): OpenMarketResult` method. The service wires the following collaborators:

| Concern | Class | Responsibility |
|---------|-------|----------------|
| Exposure guard | `Exposure/ActiveExposureGuard` | Ensure no position or pending order already exists before spending risk budget |
| Config | `Config/TradingConfigResolver` | Resolve runtime configuration overrides (budget, risk, TP) from `TradingParameters` |
| Market state | `Market/MarketSnapshotFactory` | Load contract metadata, fetch ATR-ready OHLC, compute ATR & mark price |
| Leverage | `Leverage/LeverageService` | Plan and apply leverage adjustments with BitMart fallbacks |
| Sizing | `Sizing/PositionSizer` | Decide contract size, SL/TP levels based on risk constraints |
| Order building | `Order/MarketOrderBuilder` | Build the BitMart market payload and client order id |
| Submission | `OrdersService` | Call BitMart private API |
| Post-submit | `Order/OrderScheduler`, `Order/OrderJournal` | Optional cancel timers + persist order id in MTF state |

## Portability & prerequisites

The whole `Opening` directory is designed to be dropped into another Symfony application. To keep it portable:

- **Namespace** – All classes live under `App\Service\Trading\Opening`. Adjust the root namespace if your project is different.
- **Dependencies** – Wire the following concrete services (or compatible adapters) through DI:
  - `App\Service\Config\TradingParameters` (must expose `all(): array`).
  - `App\Repository\ContractRepository` with a `find(string $symbol)` method returning an entity exposing the getters used in `MarketSnapshotFactory` (`getPricePrecision()`, `getVolPrecision()`, etc.).
  - `App\Repository\KlineRepository` with `findLastKlines(string $symbol, string $timeframe, int $limit): array`.
  - `App\Service\Indicator\AtrCalculator` providing `compute()` and `stopFromAtr()`.
  - `App\Bitmart\Http\BitmartHttpClientPublic` exposing `getMarkPriceKline()`.
  - `App\Service\Bitmart\Private\PositionsService` (`list()`, `setLeverage()`).
  - `App\Service\Bitmart\Private\OrdersService` (`create()`, `open()`).
  - `App\Service\Bitmart\Private\TrailOrdersService` (`cancelAllAfter()`) if you need cancel timers.
  - `App\Service\Pipeline\MtfStateService` (`recordOrder()`) or a no-op adapter.
  - PSR-3 loggers for diagnostic output (you can re-use the same channel for every class).
- **Exception** – The leverage planner throws `App\Service\Exception\Trade\Position\LeverageLowException`. Either copy that class across or swap the exception in `LeverageService`.
- **Configuration** – Ensure your service container registers `OpenMarketService` and its dependencies; copying the directory plus adding aliases/bindings is usually enough.
- **Testing** – Provide fake implementations of the dependencies above to run unit tests in isolation.

If your target project exposes these dependencies through different namespaces or method signatures, create thin adapter classes in the target project and inject them instead of editing the module.

## Data Flow

1. `OpenMarketService` guards exposure (`ActiveExposureGuard::assertNone`).
2. Resolve the trading configuration (`TradingConfigResolver`).
3. Build a fresh market snapshot (`MarketSnapshotFactory`) combining contract info, ATR-ready OHLC, and live mark price.
4. Plan leverage (`LeverageService::plan` + `apply`).
5. Size the position (`PositionSizer::decide`) to honour budget, risk and exchange limits.
6. Build the market order payload (`MarketOrderBuilder::build`).
7. Submit the order (`OrdersService::create`).
8. Optionally schedule cancel-all and persist the order id (`OrderScheduler`, `OrderJournal`).
9. Return a typed `OpenMarketResult` for callers.

## Extending

- **Limit orders**: add a new service (e.g. `OpenLimitService`) reusing the common components. Do not extend `OpenMarketService`; instead inject any additional builders/sizers you need.
- **Additional guards**: add new guard classes and compose them from `OpenMarketService` (or decorate `ActiveExposureGuard`).
- **Alternative ATR sources**: swap `MarketSnapshotFactory` dependencies (e.g. inject a feature-flagged OHLC provider).

## Testing

Each class is deliberately small; favour unit tests targeting individual responsibilities. For end-to-end coverage, write an application/service test that boots the Symfony kernel, wires fake BitMart clients, and exercises `OpenMarketService` with a controlled fixture.

## Migration from `PositionOpener`

1. Adapt callers to build `OpenMarketRequest` instead of calling `PositionOpener::openMarketWithTpSl`.
2. Configure Symfony DI to inject `OpenMarketService` where needed.
3. Once all flows migrate, retire duplicate logic in `PositionOpener`.
