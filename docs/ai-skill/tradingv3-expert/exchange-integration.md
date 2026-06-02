# Exchange Integration - TradingV3 Expert

## Objectif

Garder la strategie independante des exchanges. Les contraintes BitMart, OKX et Hyperliquid doivent rester dans une couche adapter.

## Frontiere obligatoire

La strategie peut produire :

- Signal.
- Side.
- Entry zone.
- Stop plan.
- Take-profit plan.
- Risk budget.
- Contraintes souhaitables.

L'adapter exchange doit gerer :

- Symbol mapping.
- Precision prix/quantite.
- Min notional et lot size.
- Margin mode.
- Leverage max.
- Order type supporte.
- Rate limit.
- Idempotence client order id.
- Reconciliation.

## ExchangeAdapterInterface

Tout nouveau provider doit couvrir :

- Market data.
- Account/balance.
- Open positions.
- Leverage.
- Order placement.
- Protection orders.
- Cancel.
- Reconcile.
- Constraints.

Ne pas exposer les payloads bruts exchange aux services strategie.

## BitMart

Points d'attention :

- Symbol format et precision futures.
- Plan orders pour SL/TP.
- Endpoint de modification protection.
- Rate limits et 429 avec backoff.
- Reconciliation des plan_order_id vs order_id.

Verifier :

- SL/TP pose apres fill.
- Modification protection idempotente.
- Logs provider suffisants sans secrets.

## OKX

Points d'attention :

- Unified account.
- Hedge mode vs one-way mode.
- Cross vs isolated margin.
- instId mapping.
- Position side obligatoire selon mode.

Verifier :

- Mode compte detecte avant trade.
- Leverage applique au bon instrument/margin mode.
- Reduce-only coherent.

## Hyperliquid

Points d'attention :

- Precision et tick size.
- Mode on-chain/off-chain selon API.
- Liquidation model.
- Nonce/signature si applicable.
- Latence et reconciliation.

Verifier :

- Client order id stable.
- Fill monitoring fiable.
- Stop/TP equivalent fonctionnel si API differente.

## Reconciliation

Une execution robuste doit pouvoir reconstruire :

- Intent local.
- Order plan.
- Exchange request.
- Exchange response.
- Fill event.
- Protection order.
- Final position.

Toute divergence doit produire un event audit et une action corrective explicite.

## Tests attendus

- Unit tests pour mapping symbols et precision.
- Contract tests adapter avec fixtures payload.
- Tests idempotence order/client id.
- Tests rejection min size et leverage max.
- Tests protection order mandatory.

## PR checklist

- Aucune logique strategie dans l'adapter.
- Aucun payload exchange dans le domaine strategie.
- Logs redigent les secrets.
- Reconciliation documentee.
- Fallbacks explicites et audites.
