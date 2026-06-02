# Architecture Cible TradingV3

## 1. Principe fondamental

Stratégie indépendante de l'exchange.

Exchange = couche d'adaptation uniquement.

---

## 2. Structure cible

```text
src/Trading/
  Strategy/
  Risk/
  Execution/
  Backtest/
  Exchange/
```

---

## 3. Interface centrale

```php
interface ExchangeAdapterInterface
{
    public function getName(): string;

    public function getMarketData(string $symbol, string $timeframe): array;

    public function getBalance(string $asset): array;

    public function getOpenPositions(): array;

    public function setLeverage(string $symbol, float $leverage): void;

    public function placeOrder(OrderPlan $orderPlan): ExchangeOrderResult;

    public function placeStopLoss(StopLossPlan $stopLossPlan): ExchangeOrderResult;

    public function cancelOrder(string $exchangeOrderId): void;

    public function reconcileOrder(string $clientOrderId): OrderReconciliationResult;

    public function getExchangeConstraints(string $symbol): ExchangeConstraints;
}
```

---

## 4. Implémentations

- BitMartExchangeAdapter
- OkxExchangeAdapter
- HyperliquidExchangeAdapter

Chaque adapter doit :
- Mapper symboles
- Mapper ordres
- Gérer precision
- Vérifier leverage max
- Appliquer rate limits
- Journaliser requêtes/réponses
