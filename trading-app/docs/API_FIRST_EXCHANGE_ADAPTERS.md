# API-first exchange adapters

## Objectif

La couche `App\Exchange` definit le contrat applicatif commun entre le moteur de trading et les exchanges. Le code metier doit parler en intentions typpees (`PlaceOrderRequest`, `CancelOrderRequest`, positions, ordres, soldes) et non en payloads Bitmart, Hyperliquid ou OKX.

Cette premiere etape garde Bitmart comme implementation legacy. Elle ajoute le contrat, les DTOs et un adapter Bitmart qui encapsule les providers existants sans changer le flux de production.

## Frontiere de responsabilite

Flux cible:

```text
TradeEntry / execution service
  -> ExchangeAdapterRegistryInterface
  -> ExchangeAdapterInterface
  -> BitmartExchangeAdapter / future HyperliquidExchangeAdapter / future OkxExchangeAdapter
  -> providers exchange-specifiques
```

Regles:

- `App\Common\Enum\Exchange` et `App\Common\Enum\MarketType` restent les identifiants communs.
- Les enums `App\Exchange\Enum` decrivent les ordres et positions au niveau metier, pas le dialecte REST d'un exchange.
- Les DTOs `App\Exchange\Dto` ne doivent pas exposer de champ specifique Bitmart/OKX/Hyperliquid.
- Le champ `metadata` est reserve au debug, a l'audit ou a la conservation du payload brut. Il ne doit pas devenir une API metier.
- Les conversions exchange-specifiques restent dans l'adapter de l'exchange.

## Contrat commun

`ExchangeAdapterInterface` expose:

- `exchange()` et `marketType()` pour identifier le contexte.
- `capabilities()` pour annoncer les features supportees.
- `getBalances()`, `getOpenPositions()`, `getOpenOrders()` et `getOrder()` pour lire l'etat exchange.
- `placeOrder()` et `cancelOrder()` pour les mutations d'ordres.
- `getOrderBookTop()` pour garder la compatibilite avec le flux d'execution actuel.
- `setLeverage()` pour les exchanges qui exigent un appel separe.
- `reconcile()` comme point d'entree futur pour la reconciliation ordres/positions/fills.

Le registry cle les adapters par paire `exchange::marketType`. Un consommateur doit demander explicitement le contexte voulu au lieu d'injecter un provider Bitmart concret.

## Capabilities

`ExchangeCapabilities` decrit les differences de surface entre exchanges:

- support testnet et WebSocket prive.
- client order id et annulation par client order id.
- `postOnly`, `IOC`, `reduceOnly`.
- SL/TP attaches a l'entree.
- trigger orders, modification d'ordre.
- levier separe et levier par symbole.

Les services d'execution doivent utiliser ces flags pour choisir un chemin compatible au lieu de supposer que tous les exchanges se comportent comme Bitmart.

## Adapter Bitmart

`BitmartExchangeAdapter` wrappe `ExchangeProviderRegistryInterface` avec le contexte `BITMART::PERPETUAL`.

La traduction actuelle conserve le comportement legacy:

- `PlaceOrderRequest.side` est mappe vers `App\Common\Enum\OrderSide`.
- `PlaceOrderRequest.positionSide` et `reduceOnly` sont transformes en code Bitmart legacy:
  - open long: `1`
  - close short: `2`
  - close long: `3`
  - open short: `4`
- `postOnly`, `FOK` et `IOC` alimentent le champ legacy `mode`.
- `clientOrderId`, SL/TP attaches et levier passent par `options`; les pseudo-flags `reduceOnly` et `postOnly` ne sont pas forwards tels quels au payload Bitmart.
- `cancelOrder()` annule par exchange order id. L'annulation par client order id est annoncee comme non supportee dans les capabilities.

La methode `reconcile()` est volontairement un placeholder. La reconciliation complete doit etre branchee quand les fills et l'etat local auront leur service dedie.

## Exemple

```php
$adapter = $registry->get(Exchange::BITMART, MarketType::PERPETUAL);

$result = $adapter->placeOrder(new PlaceOrderRequest(
    exchange: Exchange::BITMART,
    marketType: MarketType::PERPETUAL,
    symbol: 'BTCUSDT',
    side: ExchangeOrderSide::BUY,
    positionSide: ExchangePositionSide::LONG,
    orderType: ExchangeOrderType::LIMIT,
    timeInForce: ExchangeTimeInForce::GTC,
    quantity: 10.0,
    price: 25000.0,
    stopPrice: null,
    reduceOnly: false,
    postOnly: true,
    leverage: 3,
    marginMode: 'isolated',
    clientOrderId: 'trade-entry-...',
));
```

## Prochaines etapes

- Brancher le service d'execution TradeEntry sur `ExchangeAdapterRegistryInterface`.
- Ajouter des adapters paper/fake pour tester le flux sans exchange live.
- Ajouter Hyperliquid et OKX seulement apres stabilisation du contrat commun.
- Remplacer progressivement les appels directs aux providers Bitmart dans les services metier.
