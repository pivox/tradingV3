# Handoff IA – Contexte Exchange/Market + Prochaines étapes

Ce document sert de guide pour un(e) agent IA qui reprend la suite.
Il résume l’état actuel, la structure à connaître, et propose une checklist
concrète (et priorisée) pour poursuivre l’intégration multi‑exchange/market.

## État actuel (résumé)

- Contexte et registre
  - Enums: `App\Common\Enum\Exchange` (bitmart), `App\Common\Enum\MarketType` (perpetual|spot)
  - VO: `App\Provider\Context\ExchangeContext`
  - Registre: `App\Provider\Registry\ExchangeProviderRegistry` qui résout un `ExchangeProviderBundle`
  - Facade: `App\Provider\MainProvider` supporte `forContext(?ExchangeContext)`
  - Défaut global: Bitmart + Perpetual

- Bundles déclarés (services.yaml)
  - `bitmart_perpetual` (providers Bitmart actuels)
  - `bitmart_spot` (réutilise les mêmes providers en attendant des providers Spot dédiés)

- API/CLI/WebSocket/Temporal
  - HTTP `/api/mtf/run` et `/api/mtf/sync-contracts` acceptent `exchange` et `market_type` (défaut Bitmart/Perpetual)
  - CLI MTF: `mtf:run`, `mtf:run-worker`, `mtf:list-open-positions-orders` prennent `--exchange`/`--market-type`
  - CLI Indicateurs: `app:indicator:*` harmonisées avec les mêmes options
  - WS (public klines): subscribe/unsubscribe acceptent le contexte via CLI et HTTP (fallback sur topics futures)
  - Temporal: le modèle de job supporte `exchange`/`market_type` dans le payload

## Fichiers clés (où regarder/modifier)

- Enums + Contexte
  - `trading-app/src/Common/Enum/Exchange.php`
  - `trading-app/src/Common/Enum/MarketType.php`
  - `trading-app/src/Provider/Context/ExchangeContext.php`

- Registre + Facade
  - `trading-app/src/Provider/Registry/ExchangeProviderRegistry.php`
  - `trading-app/src/Provider/Registry/ExchangeProviderBundle.php`
  - `trading-app/src/Provider/MainProvider.php`

- MTF
  - `trading-app/src/MtfValidator/Controller/MtfController.php`
  - `trading-app/src/MtfValidator/Command/MtfRunCommand.php`
  - `trading-app/src/MtfValidator/Command/MtfRunWorkerCommand.php`
  - DTO: `trading-app/src/Contract/MtfValidator/Dto/MtfRunRequestDto.php`

- WebSocket
  - `trading-app/src/WebSocket/Service/WsDispatcher.php`
  - `trading-app/src/WebSocket/Service/WsPublicKlinesService.php`
  - `trading-app/src/WebSocket/Command/WsSubscribeCommand.php`
  - `trading-app/src/WebSocket/Command/WsUnsubscribeCommand.php`
  - `trading-app/src/Controller/Web/WebSocketController.php`
  - Bitmart WS builder: `trading-app/src/Provider/Bitmart/WebSocket/BitmartWebsocketPublic.php`

- Services
  - Wiring principal: `trading-app/config/services.yaml`

- Docs
  - `trading-app/docs/EXCHANGE_REGISTRY.md`

## TODO – Priorités & critères d’acceptation

1) Providers Spot dédiés (Bitmart)
- Objectif: dissocier clairement Spot et Futures (Perpetual) côté providers.
- Actions:
  - Créer les classes Spot (ex: `BitmartSpotKlineProvider`, `BitmartSpotOrderProvider`, etc.)
  - Adapter les clients HTTP (Spot vs Futures) si nécessaire (endpoints, auth, params)
  - Brancher le bundle `bitmart_spot` sur ces nouveaux providers (services.yaml)
- Tests rapides:
  - `php bin/console app:indicators:get BTCUSDT 1h --exchange=bitmart --market-type=spot`
  - `php bin/console mtf:run --symbols=BTCUSDT --dry-run=1 --exchange=bitmart --market-type=spot`

2) WebSocket Spot (public)
- Objectif: gérer les topics publics Spot dans le builder WS et le service public klines.
- Actions:
  - Ajouter un mapping de topics Spot (Bitmart Spot WS) dans `BitmartWebsocketPublic`
  - En fonction de `ExchangeContext.marketType`, générer les bons topics (spot vs futures)
  - Ajuster `WsPublicKlinesService` pour appeler le bon builder selon le contexte
- Tests rapides:
  - `php bin/console ws:subscribe BTCUSDT 1m 5m --exchange=bitmart --market-type=spot` (voir logs/echo)

3) Orchestrateur MTF (context partout)
- Objectif: s’assurer que tout le flux MTF (orchestrator, symbolProcessor, decisionHandler) exploite le provider contextuel (et pas seulement filtrage/TP-SL).
- Actions:
  - Passer un provider contextuel (ou un `ExchangeContext`) plus bas dans la stack
  - Vérifier/adapter les points d’accès providers (kline, order, account) au contexte choisi
- Critères:
  - Les providers utilisés par l’orchestrator changent bien quand on change `exchange/market_type`

4) Tests & validation
- Objectif: éviter les régressions et valider le contexte.
- Actions:
  - Tests manuels CLI/HTTP + logs (déjà listés dans les fichiers)
  - Si possible, tests d’intégration légers (e.g. services.yaml instanciant le registre, résolutions des bundles)

5) Extensibilité – Nouveaux exchanges
- Objectif: ajouter un nouvel exchange de manière simple.
- Actions:
  - Créer les providers et les clients HTTP/WS
  - Ajouter `Exchange::<NEW>` dans l’enum + services (context + bundle) + registre
  - Adapter MTF/Indicator/WS si le schéma diffère

## Pièges / Notes pratiques

- DI du `ExchangeContext`: un alias pointe vers le contexte par défaut
  (`App\Provider\Context\ExchangeContext: '@App\Provider\Context\ExchangeContext.bitmart_perpetual'`).
  Si vous ajoutez d’autres contextes par défaut, adaptez cet alias.

- WebSocket: la version actuelle loggue et dump les messages (sans persistance). Les topics Spot
  doivent être ajoutés (documentation Bitmart Spot WS nécessaire). En attendant, le fallback reste sur futures.

- Temporal: pour piloter un autre contexte, inclure `exchange` et `market_type` dans le payload job
  (sinon défaut Bitmart/Perpetual).

## Commandes & Endpoints utiles (sanity checks)

- MTF HTTP: `GET/POST /api/mtf/run?symbols=BTCUSDT&dry_run=1&exchange=bitmart&market_type=perpetual`
- MTF CLI:
  - `php bin/console mtf:run --symbols=BTCUSDT --dry-run=1 --exchange=bitmart --market-type=perpetual`
  - `php bin/console mtf:run-worker --symbols=BTCUSDT --exchange=bitmart --market-type=spot`
  - `php bin/console mtf:list-open-positions-orders --symbol=BTCUSDT --exchange=bitmart --market-type=perpetual`
- Indicateurs CLI:
  - `php bin/console app:indicator:contracts:validate 1h --exchange=bitmart --market-type=perpetual`
  - `php bin/console app:indicator:conditions:diagnose BTCUSDT 1h --exchange=bitmart --market-type=spot`
  - `php bin/console app:indicators:get BTCUSDT 1h --exchange=bitmart --market-type=perpetual`
- WS HTTP:
  - `POST /ws/subscribe {"symbol":"BTCUSDT","tfs":["1m"],"exchange":"bitmart","market_type":"perpetual"}`
  - `POST /ws/unsubscribe {"symbol":"BTCUSDT","tfs":["1m"],"exchange":"bitmart","market_type":"spot"}`

## Style & conventions

- Garder le câblage minimal dans `services.yaml` (privilégier autowire/autoconfigure)
- Préférer l’ajout incrémental de bundles (`ExchangeProviderBundle`) dans le registre
- Journaliser le contexte (`exchange`, `market_type`) sur les points de décision

Bon courage pour la suite !
