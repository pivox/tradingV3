# TradeEntry Module

Module d'orchestration de l'entrée en position. Sequence:

1. `Workflow\BuildPreOrder` → vérifications marché & récupération specs via `Policy\PreTradeChecks`.
2. `Workflow\BuildOrderPlan` → construit un `OrderPlanModel` (pricing, sizing, SL/TP, levier) puis applique zones/guards.
3. `Workflow\ExecuteOrderPlan` → soumission levier + ordre via `Execution\ExecutionBox`.

## Arborescence

```
TradeEntry/
├── Adapter/MainProviderAdapter.php
├── Dto/
│   ├── ExecutionResult.php
│   ├── EntryZone.php
│   ├── OrderPlanModel.php
│   ├── PreflightReport.php
│   ├── RiskDecision.php
│   └── TradeEntryRequest.php
├── EntryZone/
│   ├── EntryZoneCalculator.php
│   └── EntryZoneFilters.php
├── Execution/
│   ├── ExecutionBox.php
│   ├── ExecutionLogger.php
│   └── TpSlAttacher.php
├── OrderPlan/
│   ├── OrderPlanBox.php
│   └── OrderPlanBuilder.php
├── Policy/
│   ├── IdempotencyPolicy.php
│   ├── LiquidationGuard.php
│   ├── MakerOnlyPolicy.php
│   └── PreTradeChecks.php
├── Pricing/TickQuantizer.php
├── RiskSizer/
│   ├── PositionSizer.php
│   ├── StopLossCalculator.php
│   └── TakeProfitCalculator.php
├── Service/
│   ├── Leverage/DefaultLeverageService.php
│   ├── TradeEntryAlertService.php
│   ├── TradeEntryBacktestService.php
│   ├── TradeEntryMetricsService.php
│   └── TradeEntryService.php
├── Types/Side.php
└── Workflow/
    ├── AttachTpSl.php
    ├── BuildOrderPlan.php
    ├── BuildPreOrder.php
    └── ExecuteOrderPlan.php
```

## Contrat

- `App\Contract\Provider\MainProviderInterface` alimente l'adapter.
- `App\Contract\EntryTrade\LeverageServiceInterface` (implémentation par défaut sous `Service\Leverage`).

## Usage

Injecter `Service\TradeEntryService` et appeler `buildAndExecute()` avec un `Dto\TradeEntryRequest`.

```php
$result = $tradeEntryService->buildAndExecute(new TradeEntryRequest(
    symbol: 'BTCUSDT',
    side: Side::Long,
));

if ($result->status !== 'submitted') {
    // gérer l'erreur
}
```

Les presets SL/TP sont envoyés lors de la création de l'ordre; les ordres MARKET réutilisent un prix estimé (best bid/ask) pour calibrer SL/TP mais soumettent un `mode` compatible.
