# Exchange Context & Provider Registry

This module introduces an exchange/market context for all provider access and a registry that maps a context to a bundle of providers.

## Concepts

- `Exchange` enum: currently `bitmart`
- `MarketType` enum: `perpetual` or `spot`
- `ExchangeContext`: value object holding `Exchange` + `MarketType`
- `ExchangeProviderBundle`: aggregates providers (kline, contract, order, account, system) for a given context
- `ExchangeProviderRegistry`: resolves a bundle by context and provides a default

Default context: Bitmart + Perpetual

## Service Wiring

The registry and default bundles are configured in `config/services.yaml`.
Two bundles are registered by default:

- Bitmart/Perpetual: `App\Provider\Registry\ExchangeProviderBundle.bitmart_perpetual`
- Bitmart/Spot: `App\Provider\Registry\ExchangeProviderBundle.bitmart_spot` (reuses same providers until spot-specific ones are available)

For autowiring convenience, the service id `App\Provider\Context\ExchangeContext` points to the default context (bitmart/perpetual).

## Using Context in Code

Use the facade `MainProviderInterface` and switch context dynamically:

```php
$bundleAware = $mainProvider->forContext(new ExchangeContext(Exchange::BITMART, MarketType::PERPETUAL));
$orders = $bundleAware->getOrderProvider()->getOpenOrders();
```

## HTTP API

`POST /api/mtf/run` (and `GET /api/mtf/run`) accepts optional parameters:

- `exchange`: e.g. `bitmart`
- `market_type`: `perpetual` or `spot`

When omitted, defaults to Bitmart/Perpetual.

## CLI

Both commands accept `--exchange` and `--market-type` (optional, default to Bitmart/Perpetual):

```
php bin/console mtf:run --symbols=BTCUSDT --dry-run=1 --exchange=bitmart --market-type=perpetual
php bin/console mtf:run-worker --symbols=BTCUSDT --dry-run=1 --exchange=bitmart --market-type=perpetual
php bin/console mtf:list-open-positions-orders --symbol=BTCUSDT --exchange=bitmart --market-type=perpetual
```

## Temporal Integration

The worker schedule and workflow can pass context to the API by including these keys in the JSON payload:

- `exchange`: e.g. `bitmart`
- `market_type`: `perpetual` or `spot`

When omitted, the API runs with Bitmart/Perpetual.

## Extending Registry

To add a new exchange or market type:

1. Implement provider classes if needed (kline, contract, order, account, system)
2. Register a new `ExchangeContext` service and `ExchangeProviderBundle` in `config/services.yaml`
3. Add the new bundle to `App\Provider\Registry\ExchangeProviderRegistry` `$bundles`

