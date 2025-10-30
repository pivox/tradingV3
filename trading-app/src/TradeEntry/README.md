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

## Entry Zone

- EntryZoneCalculator calcule une zone d'entrée centrée sur un pivot (VWAP ou SMA21) et bornée par l'ATR.
- Signature: `compute(string $symbol, ?Types\Side $side = null, ?int $pricePrecision = null): Dto\EntryZone`.
- Sélection du pivot via config:
  - `post_validation.entry_zone.vwap_anchor: true` → priorité VWAP puis SMA21
  - sinon → priorité SMA21 puis VWAP
- Largeur (demi‑écart) = `clamp(k_atr × ATR, pivot × w_min, pivot × w_max)`
  - `post_validation.entry_zone.k_atr` (ex: 0.35)
  - `post_validation.entry_zone.w_min` (ex: 0.0005 = 0.05%)
  - `post_validation.entry_zone.w_max` (ex: 0.0100 = 1.00%)
- Asymétrie optionnelle selon le side (`post_validation.entry_zone.asym_bias`):
  - Long → zone élargie sous le pivot, réduite au‑dessus
  - Short → zone réduite sous le pivot, élargie au‑dessus
- Quantification des bornes si `post_validation.entry_zone.quantize_to_exchange_step: true` et `pricePrecision` fourni:
  - min = arrondi vers le bas au tick, max = arrondi vers le haut au tick
- Rationale: inclut la source du pivot, le timeframe, le pourcentage bas/haut, et les paramètres.

Dépendances et overrides
- Utilise `Contract\Indicator\IndicatorProviderInterface` pour `getAtr()` et `getListPivot()`.
- Lit `config/trading.yml` via `Service\TradingConfigService`:
  - `post_validation.execution_timeframe.default` pour le timeframe d'évaluation
  - `post_validation.entry_zone.*` pour les paramètres
- Pour les tests, le constructeur accepte des overrides facultatifs: timeframe, `k_atr`, `w_min`, `w_max`, `asym_bias`.

Exemple d'usage dans le workflow
```php
// BuildOrderPlan::__invoke
$zone = $this->zones->compute($req->symbol, $req->side, $pre->pricePrecision);
if (!$zone->contains($candidate)) {
    throw new \RuntimeException('Prix d\'entrée hors zone calculée');
}
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

## Configuration

- Paramètres MTF et décision: `config/app/mtf_validations.yaml`.
  - `mtf_validation.defaults` contient désormais les paramètres par défaut de Trade Entry (risk_pct_percent, initial_margin_usdt, r_multiple, order_type, open_type, order_mode, stop_from, market_max_spread_pct, timeframe_multipliers, etc.).
- Paramètres EntryZone (pivot/ATR): `config/trading.yml` via `Service\\TradingConfigService`.
