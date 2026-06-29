# API-first Exchange Runbook

Ce runbook decrit les commandes minimales pour travailler sur les adapters
API-first sans contexte oral. Les examples partent du repertoire
`trading-app/`.

## FakeExchange

FakeExchange sert de banc de contrat local: pas de reseau, pas de secrets, et
des fills deterministes.

```bash
php -d error_reporting='E_ALL & ~E_DEPRECATED' ./vendor/bin/phpunit tests/Exchange/Contract/FakeExchangeAdapterContractTest.php
php -d error_reporting='E_ALL & ~E_DEPRECATED' ./vendor/bin/phpunit tests/Exchange/Contract/BitmartExchangeAdapterContractTest.php
php -d error_reporting='E_ALL & ~E_DEPRECATED' ./vendor/bin/phpunit tests/Exchange/Contract/HyperliquidExchangeAdapterContractTest.php
php -d error_reporting='E_ALL & ~E_DEPRECATED' ./vendor/bin/phpunit tests/Exchange/Contract/OkxExchangeAdapterContractTest.php
php -d error_reporting='E_ALL & ~E_DEPRECATED' ./vendor/bin/phpunit tests/Exchange/Adapter/FakeExchangeAdapterTest.php
```

Pour remettre l'etat fake a zero dans un environnement Symfony, supprimer le
fichier `var/fake_exchange_state.dat` ou reconstruire le service
`FakeExchangeStateStore`.

Pour forcer un flux API-first local, le plan doit porter un contexte explicite
`ExchangeContext(Exchange::FAKE, MarketType::PERPETUAL)`. Sans contexte
explicite, le chemin legacy Bitmart peut rester le fallback selon le service
appelant. Les tests `ExchangeExecutionServiceTest` montrent le cablage attendu
avec `OrderPlanModel::exchangeContext`.

## Tests De Contrat

`ExchangeAdapterContractTestCase` contient les invariants communs:

- identite exchange/marketType et coherence des capabilities;
- placement, listing, lookup et cancel d'un limit order;
- fill market local et creation de position quand l'adapter le permet;
- partial fill local et mise a jour de la position quand l'adapter expose un
  hook de fill deterministe;
- close reduce-only local et disparition de la position quand l'adapter execute
  les market orders immediatement;
- idempotence par `clientOrderId`, y compris replay d'un ordre deja fill;
- placement, listing et cancel d'un stop reduce-only separe quand
  `supportsTriggerOrders=true`;
- placement, listing et cancel d'un take-profit reduce-only separe quand
  `supportsTriggerOrders=true`;
- confirmation de protections SL/TP attachees apres fill local quand l'adapter
  annonce `supportsAttachedStopLossOnEntry` ou
  `supportsAttachedTakeProfitOnEntry`;
- confirmation d'un stop reduce-only separe quand `supportsTriggerOrders=true`;
- snapshots REST positions/fills quand l'adapter implemente
  `ExchangeRestSnapshotProviderInterface`.

Les tests d'ingestion WS Fake couvrent aussi la projection d'un fill d'ordre,
l'ouverture de position et la mise a jour de position apres reduce-only partiel.

Commande large recommandee:

```bash
php -d error_reporting='E_ALL & ~E_DEPRECATED' ./vendor/bin/phpunit tests/Exchange tests/TradeEntry/Execution/ExchangeExecutionServiceTest.php
```

## Idempotence, Retry Et Concurrence

Cible contractuelle pour les adapters:

- un `decisionKey` non vide produit toujours le meme `clientOrderId` via
  `IdempotencyPolicy`;
- un retry avec le meme `clientOrderId` et la meme intention doit retourner
  l'ordre existant au lieu de creer une nouvelle entree;
- un retry avec le meme `clientOrderId` mais une intention differente doit etre
  rejete avec `duplicate_client_order_id_intent_mismatch` quand l'adapter sait
  comparer l'intention stockee;
- les terminaux acceptes (`FILLED`, `EXPIRED`, `CANCELLED`) doivent conserver
  leur statut exchange au replay quand l'adapter a acces a l'historique;
- un ordre `CANCELLED` avec `filledQuantity > 0` reste un fill metier: le flux
  doit confirmer ou recreer la protection au lieu de traiter le retry comme un
  ordre jamais execute;
- un ordre originellement `REJECTED` reste rejete au replay. Ne pas supposer que
  la raison exchange originale est conservee: Bitmart expose
  `duplicate_client_order_id_original_rejected`, tandis que Fake conserve les
  metadata locales disponibles.

Etat actuel: Fake et Bitmart couvrent les replays d'historique et les mismatches
d'intention dans les tests de contrat applicables. OKX et Hyperliquid ne
rejouent aujourd'hui que les doublons encore visibles en open orders; si
l'ordre original est deja fill/cancelled/expired, traiter le duplicate comme un
cas a verifier manuellement dans l'historique REST de l'exchange avant toute
nouvelle decision.

Quand `OrderIntentManager` est disponible, `ExecuteOrderPlan` reserve le
`decisionKey` avant l'envoi. Sur PostgreSQL, la reservation prend un advisory
lock scope par `exchange::market_type` + `decision_key`, ce qui evite que deux
workers creent deux intentions concurrentes. Le second worker doit observer:

- `idempotent_in_flight` pour `DRAFT`, `VALIDATED` ou `READY_TO_SEND`;
- `idempotent_sent_replay` pour `SENT`;
- `idempotent_failed_not_replayed` pour `FAILED`;
- `idempotent_cancelled_not_replayed` pour `CANCELLED`.

Pour un retry apres timeout, ne pas generer de nouveau `clientOrderId` a la
main. Rejouer le meme `decisionKey`, verifier l'`OrderIntent` existant, puis
comparer l'etat exchange REST:

1. si l'ordre est fill ou partiellement fill, confirmer la protection;
2. si l'ordre est ouvert, verifier le watcher/cancel timeout;
3. si l'ordre est cancelled/expired sans fill, ne pas resoumettre sans nouvelle
   decision metier;
4. si l'ordre est rejected, conserver la cause et corriger l'intention avant un
   nouveau `decisionKey`.

Tests a lancer pour cette zone:

```bash
php -d error_reporting='E_ALL & ~E_DEPRECATED' ./vendor/bin/phpunit tests/Exchange/Adapter/FakeExchangeAdapterTest.php tests/Exchange/Contract/FakeExchangeAdapterContractTest.php tests/TradeEntry/Execution/ExchangeExecutionServiceTest.php tests/TradeEntry/Idempotency/DecisionKeyFactoryTest.php
```

## Hyperliquid Testnet

Variables:

```dotenv
HYPERLIQUID_ENV=testnet
HYPERLIQUID_PRIVATE_KEY=
HYPERLIQUID_ACCOUNT_ADDRESS=
HYPERLIQUID_API_BASE_URI=https://api.hyperliquid-testnet.xyz
HYPERLIQUID_WS_URI=wss://api.hyperliquid-testnet.xyz/ws
HYPERLIQUID_MAINNET_ENABLED=0
```

Le client REST par defaut lit `/info`, mais ne signe pas `/exchange`.
Injecter une implementation signee de `HyperliquidRestClientInterface` avant
tout ordre testnet reel. Mainnet reste bloque tant que
`HYPERLIQUID_MAINNET_ENABLED=1` n'est pas explicite.

## OKX Demo

Variables:

```dotenv
OKX_ENV=demo
OKX_DEMO_API_KEY=
OKX_DEMO_API_SECRET=
OKX_DEMO_API_PASSPHRASE=
OKX_API_BASE_URI=https://eea.okx.com
OKX_WS_PUBLIC_URI=wss://wseeapap.okx.com:8443/ws/v5/public
OKX_WS_PRIVATE_URI=wss://wspap.okx.com:8443/ws/v5/private?brokerId=9999
OKX_SIMULATED_TRADING=1
OKX_DEMO_TRADING_ENABLED=0
OKX_LIVE_ENABLED=0
```

Si `OKX_API_BASE_URI` ou `OKX_WS_PUBLIC_URI` sont vides, le mode demo applique
ces valeurs EEA par defaut. OKX-003 utilise le polling REST public pour
instruments, ticker, candles et order book; aucun client WebSocket public runtime
n'est demarre dans cette PR.

Les requetes privees OKX sont signees avec `OK-ACCESS-*`; en demo le header
`x-simulated-trading: 1` est ajoute pour les requetes privees demo lorsque
`OKX_SIMULATED_TRADING=1` est explicite. Les ordres demo restent bloques tant que
`OKX_DEMO_TRADING_ENABLED=1` n'est pas explicite. Le live reste bloque tant que
`OKX_ENV=live` et `OKX_LIVE_ENABLED=1` ne sont pas tous les deux presents.

## Verification SL

Avant tout run reel, verifier que le chemin d'execution ne peut pas retourner
`submitted` ou `protected` sans stop confirme:

```bash
php -d error_reporting='E_ALL & ~E_DEPRECATED' ./vendor/bin/phpunit tests/TradeEntry/Execution/ExchangeExecutionServiceTest.php
```

Sur les donnees persistantes, chercher les positions ouvertes sans ordre de
protection actif via les tables `futures_order`, `futures_plan_order`,
`order_protection` et `trade_lifecycle_event`. Les events utiles a lire:

La reconciliation REST ne considere une position couverte que si un SL actif
reduce-only couvre la quantite ouverte avec le bon side de sortie et la bonne
position side. Un TP seul, un mauvais side ou une quantite restante insuffisante
doit rester visible dans `unprotected_positions`.

- `exchange_execution.entry_submitted`
- `exchange_execution.entry_filled`
- `protection.confirmed`
- `protection.failed`
- `emergency_close.*`

Audit rapide apres incident:

```bash
rg "decision_key=<DECISION_KEY>|client_order_id=<CLIENT_ORDER_ID>" var/log/positions-*.log var/log/order-journey-*.log
```

Il n'existe pas encore de commande console de reconciliation live. Ne pas
utiliser PHPUnit comme preuve d'audit incident: les tests ne lisent que des
fixtures. En production/demo, comparer l'etat REST de l'exchange pour le symbole
affecte:

1. positions ouvertes;
2. open orders SL/TRIGGER reduce-only;
3. side de sortie et position side;
4. quantite restante de SL versus taille de position.

Si cette verification remonte l'equivalent de `unprotected_positions`, traiter
le run comme critique tant qu'un SL actif reduce-only ne couvre pas toute la
quantite. Avant mainnet, ajouter une commande console qui wrappe
`ExchangeReconciliationService` sur un adapter/symbol reel.

## Emergency Close

Si `protection.failed` apparait, verifier immediatement:

1. l'event `emergency_close` associe au meme `client_order_id`;
2. la position exchange via l'adapter REST;
3. les ordres stop stale encore ouverts;
4. les lifecycle events lies au `decision_key`.

Ne relancer un exchange reel qu'apres confirmation que la position est fermee ou
qu'un stop reduce-only actif couvre toute la position.

## Desactivation Immediate

Pour couper les exchanges externes:

- remettre `OKX_DEMO_TRADING_ENABLED=0`;
- remettre `OKX_LIVE_ENABLED=0`;
- remettre `HYPERLIQUID_MAINNET_ENABLED=0`;
- retirer les secrets API des workers;
- redemarrer les containers workers qui consomment les decisions trading.

## Checklist Avant Mainnet

- tests `tests/Exchange` et `ExchangeExecutionServiceTest` verts;
- `lint:container --no-debug` vert;
- `doctrine:schema:validate --skip-sync` vert;
- review locale des payloads signer/order/cancel/protection;
- aucun log ne contient private key, secret, passphrase ou signature complete;
- mainnet active par flag explicite et revue manuelle;
- taille minimale et leverage testes en environnement demo/testnet;
- SL reduce-only confirme avant tout statut utilisateur "protected";
- procedure emergency close testee sur rejet de protection;
- rollback documente pour desactiver l'exchange.
