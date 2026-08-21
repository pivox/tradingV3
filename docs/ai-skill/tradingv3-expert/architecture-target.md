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

`App\Exchange\Contract\ExchangeAdapterInterface` — interface réelle dans `trading-app/src/Exchange/Contract/ExchangeAdapterInterface.php`.

```php
interface ExchangeAdapterInterface
{
    public function exchange(): Exchange;

    public function marketType(): MarketType;

    public function capabilities(): ExchangeCapabilities;

    /** @return ExchangeBalanceDto[] */
    public function getBalances(): array;

    /** @return ExchangePositionDto[] */
    public function getOpenPositions(?string $symbol = null): array;

    /** @return ExchangeOrderDto[] */
    public function getOpenOrders(?string $symbol = null): array;

    public function placeOrder(PlaceOrderRequest $request): PlaceOrderResult;

    public function cancelOrder(CancelOrderRequest $request): CancelOrderResult;

    public function getOrder(string $symbol, string $exchangeOrderId): ?ExchangeOrderDto;

    public function getOrderBookTop(string $symbol): SymbolBidAskDto;

    public function setLeverage(string $symbol, int $leverage, string $marginMode): bool;

    public function reconcile(?string $symbol = null): ExchangeReconciliationResult;
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
