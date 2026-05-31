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

## Tests De Contrat

`ExchangeAdapterContractTestCase` contient les invariants communs:

- identite exchange/marketType et coherence des capabilities;
- placement, listing, lookup et cancel d'un limit order;
- fill market local et creation de position quand l'adapter le permet;
- idempotence par `clientOrderId`;
- placement, listing et cancel d'un stop reduce-only separe quand
  `supportsTriggerOrders=true`;
- confirmation d'un stop reduce-only separe quand `supportsTriggerOrders=true`;
- snapshots REST positions/fills quand l'adapter implemente
  `ExchangeRestSnapshotProviderInterface`.

Commande large recommandee:

```bash
php -d error_reporting='E_ALL & ~E_DEPRECATED' ./vendor/bin/phpunit tests/Exchange tests/TradeEntry/Execution/ExchangeExecutionServiceTest.php
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
OKX_API_KEY=
OKX_API_SECRET=
OKX_API_PASSPHRASE=
OKX_API_BASE_URI=https://www.okx.com
OKX_WS_PUBLIC_URI=wss://ws.okx.com:8443/ws/v5/public
OKX_WS_PRIVATE_URI=wss://wspap.okx.com:8443/ws/v5/private?brokerId=9999
OKX_DEMO_TRADING_ENABLED=0
OKX_LIVE_ENABLED=0
```

Les requetes privees OKX sont signees avec `OK-ACCESS-*`; en demo le header
`x-simulated-trading: 1` est ajoute. Les ordres demo restent bloques tant que
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

- `exchange_execution.entry_submitted`
- `exchange_execution.entry_filled`
- `protection.confirmed`
- `protection.failed`
- `emergency_close.*`

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
