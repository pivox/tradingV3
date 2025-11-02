# BitMart WebSocket Worker

Ce worker WebSocket permet de recevoir en temps réel les données de trading BitMart pour les ordres, positions et klines.

## Fonctionnalités

- **Klines** : Données de bougies en temps réel (1m, 5m, 15m, 30m, 1h, 2h, 4h, 1d, 1w)
- **Ordres** : Mise à jour des ordres en temps réel (soumission, exécution, annulation)
- **Positions** : Suivi des positions ouvertes et de leurs changements
- **Balance** : Suivi du solde USDT disponible en temps réel
- **Authentification** : Support des canaux privés avec authentification API
- **Reconnexion automatique** : Gestion des déconnexions et reconnexions
- **API de contrôle HTTP** : Interface REST pour contrôler les souscriptions
- **Signaux vers trading-app** : Envoi automatique des updates d'ordres et de balance vers trading-app

## Installation

### 1. Installer les dépendances

```bash
cd ws-worker
composer install
```

### 2. Configurer les variables d'environnement

Les variables d'environnement sont configurées dans le fichier `.env` à la **racine du projet** (tradingV3/.env).

Docker-compose charge automatiquement ce fichier et injecte les variables dans le container.

```bash
# À la racine du projet (tradingV3/)
nano .env
```

**Variables obligatoires dans le .env racine :**

```env
# Clés API BitMart
BITMART_API_KEY=votre_cle_api
BITMART_SECRET_KEY=votre_secret_api    # ⚠️ Note: SECRET_KEY (pas API_SECRET)
BITMART_API_MEMO=votre_memo

# Secret partagé pour l'authentification HMAC
WS_WORKER_SHARED_SECRET=un_secret_securise

# URLs (optionnel, valeurs par défaut disponibles)
BITMART_BASE_URL=https://api-cloud.bitmart.com
BITMART_PUBLIC_API_URL=https://api-cloud.bitmart.com
TRADING_APP_BASE_URI=http://trading-app-nginx
```

⚠️ **Important** : 
- Dans le .env racine, utilisez `BITMART_SECRET_KEY` (docker-compose le mappe vers `BITMART_API_SECRET` dans le container)
- Le `WS_WORKER_SHARED_SECRET` doit être identique dans trading-app pour l'authentification HMAC
- Générer un secret sécurisé : `openssl rand -hex 32`

📚 **Pour plus de détails** : Consultez `ENV_CONFIGURATION.md` à la racine du projet.

## Configuration

### Variables d'environnement

**Configuration WebSocket :**
- `BITMART_PUBLIC_WS_URI` : URL WebSocket publique (par défaut: wss://ws-pro.bitmart.com/api?protocol=1.1)
- `BITMART_PRIVATE_WS_URI` : URL WebSocket privée (par défaut: wss://ws-pro.bitmart.com/user?protocol=1.1)
- `BITMART_API_KEY` : Clé API BitMart
- `BITMART_API_SECRET` : Secret API BitMart
- `BITMART_API_MEMO` : Mémo API BitMart
- `CTRL_ADDR` : Adresse du serveur de contrôle HTTP (par défaut: 0.0.0.0:8089)
- `SUBSCRIBE_BATCH` : Nombre de souscriptions par lot (par défaut: 20)
- `SUBSCRIBE_DELAY_MS` : Délai entre les lots de souscriptions (par défaut: 200ms)
- `PING_INTERVAL_S` : Intervalle de ping (par défaut: 15s)
- `RECONNECT_DELAY_S` : Délai de reconnexion (par défaut: 5s)

**Intégration avec trading-app :**
- `TRADING_APP_BASE_URI` : URL de base de trading-app (ex: http://localhost:8080)
- `TRADING_APP_ORDER_SIGNAL_PATH` : Endpoint pour les signaux d'ordres (par défaut: /api/ws-worker/orders)
- `TRADING_APP_BALANCE_SIGNAL_PATH` : Endpoint pour les signaux de balance (par défaut: /api/ws-worker/balance)
- `TRADING_APP_SHARED_SECRET` : Secret partagé pour l'authentification HMAC
- `TRADING_APP_REQUEST_TIMEOUT` : Timeout des requêtes HTTP (par défaut: 2.0s)
- `TRADING_APP_SIGNAL_MAX_RETRIES` : Nombre maximum de tentatives (par défaut: 5)
- `TRADING_APP_SIGNAL_FAILURE_LOG` : Fichier de log des échecs d'ordres (par défaut: var/order-signal-failures.log)
- `TRADING_APP_BALANCE_FAILURE_LOG` : Fichier de log des échecs de balance (par défaut: var/balance-signal-failures.log)

## Utilisation

### Démarrer le worker

```bash
# À la racine du projet
docker-compose up -d ws-worker

# Voir les logs
docker-compose logs -f ws-worker
```

### API de contrôle HTTP

Le worker expose une API REST sur le port configuré (par défaut 8089).

#### Endpoints disponibles

- `GET /status` - Obtenir le statut du worker
- `GET /help` - Afficher l'aide et les exemples
- `POST /klines/subscribe` - S'abonner aux klines
- `POST /klines/unsubscribe` - Se désabonner des klines
- `POST /orders/subscribe` - S'abonner aux ordres
- `POST /orders/unsubscribe` - Se désabonner des ordres
- `POST /positions/subscribe` - S'abonner aux positions
- `POST /positions/unsubscribe` - Se désabonner des positions
- `POST /balance/subscribe` - S'abonner au solde USDT
- `POST /balance/unsubscribe` - Se désabonner du solde USDT
- `POST /stop` - Arrêter le worker

#### Exemples d'utilisation

**S'abonner aux klines BTCUSDT (1m et 5m) :**
```bash
curl -X POST http://localhost:8089/klines/subscribe \
  -H "Content-Type: application/json" \
  -d '{"symbol": "BTCUSDT", "tfs": ["1m", "5m"]}'
```

**S'abonner aux ordres :**
```bash
curl -X POST http://localhost:8089/orders/subscribe
```

**S'abonner aux positions :**
```bash
curl -X POST http://localhost:8089/positions/subscribe
```

**S'abonner au solde USDT :**
```bash
curl -X POST http://localhost:8089/balance/subscribe
```

**Obtenir le statut :**
```bash
curl http://localhost:8089/status
```

**Voir l'aide :**
```bash
curl http://localhost:8089/help
```

## Canaux BitMart supportés

### Canaux publics (pas d'authentification requise)
- `futures/klineBin1m:BTCUSDT` - Klines 1 minute
- `futures/klineBin5m:BTCUSDT` - Klines 5 minutes
- `futures/klineBin15m:BTCUSDT` - Klines 15 minutes
- `futures/klineBin30m:BTCUSDT` - Klines 30 minutes
- `futures/klineBin1H:BTCUSDT` - Klines 1 heure
- `futures/klineBin2H:BTCUSDT` - Klines 2 heures
- `futures/klineBin4H:BTCUSDT` - Klines 4 heures
- `futures/klineBin1D:BTCUSDT` - Klines 1 jour
- `futures/klineBin1W:BTCUSDT` - Klines 1 semaine

### Canaux privés (authentification requise)
- `futures/order` - Mise à jour des ordres
- `futures/position` - Mise à jour des positions
- `futures/asset:USDT` - Mise à jour du solde USDT

## Format des données

### Klines
```json
{
  "group": "futures/klineBin1m:BTCUSDT",
  "data": {
    "symbol": "BTCUSDT",
    "o": "50000.0",
    "h": "50100.0",
    "l": "49900.0",
    "c": "50050.0",
    "v": "1000",
    "ts": 1640995200
  }
}
```

### Ordres
```json
{
  "group": "futures/order",
  "data": [{
    "action": 2,
    "order": {
      "order_id": "123456789",
      "symbol": "BTCUSDT",
      "side": 1,
      "type": "limit",
      "price": "50000.0",
      "size": "0.1",
      "state": 2,
      "deal_size": "0.05",
      "deal_avg_price": "50025.0"
    }
  }]
}
```

### Positions
```json
{
  "group": "futures/position",
  "data": [{
    "symbol": "BTCUSDT",
    "hold_volume": "0.1",
    "position_type": 1,
    "open_type": 1,
    "hold_avg_price": "50000.0",
    "liquidate_price": "45000.0",
    "position_mode": "hedge_mode"
  }]
}
```

### Balance
```json
{
  "group": "futures/asset:USDT",
  "data": {
    "currency": "USDT",
    "available_balance": "10000.50",
    "frozen_balance": "500.00",
    "equity": "10500.50",
    "unrealized_value": "100.00",
    "position_deposit": "400.00",
    "bonus": "0.00"
  }
}
```

## Architecture

Le worker utilise une architecture modulaire :

- **MainWorker** : Orchestrateur principal
- **BitmartWsClient** : Client WebSocket avec support authentification
- **AuthHandler** : Gestionnaire d'authentification
- **KlineWorker** : Gestionnaire des klines
- **OrderWorker** : Gestionnaire des ordres avec envoi de signaux vers trading-app
- **PositionWorker** : Gestionnaire des positions
- **BalanceWorker** : Gestionnaire du solde USDT avec envoi de signaux vers trading-app
- **HttpControlServer** : Serveur de contrôle HTTP
- **OrderSignalDispatcher** / **BalanceSignalDispatcher** : Envoi HTTP avec retry automatique

## Gestion des erreurs

- Reconnexion automatique en cas de déconnexion
- Gestion des erreurs d'authentification
- Retry automatique des souscriptions
- Logs détaillés pour le debugging

## Détails d'implémentation (soumissions REST)

- Soumission d'ordre (submit-order): l'API Bitmart peut retourner `200` (code `1000`) alors que l'endpoint `order-detail` renvoie encore `404` pendant quelques centaines de millisecondes (consistance éventuelle côté exchange).
- Comportement côté trading-app: après un `200` de `submit-order`, le provider tente jusqu'à 3 lectures de `order-detail` (250 ms d'intervalle). Si l'ordre n'est toujours pas disponible, il renvoie un OrderDto minimal avec:
  - `status=pending`,
  - `order_id` issu de la réponse `submit-order`,
  - champs connus (symbol, side, type, quantity, price si LIMIT),
  - `metadata.submit_only=true`.
- Conséquence: la phase de synchronisation doit enrichir ensuite l'ordre (via WS ou polling) pour récupérer `state`, `deal_size`, `deal_avg_price`, etc.
- Timeout automatique: trading-app programme une annulation après 2 minutes si Bitmart n'a pas rempli l'ordre (annule les ordres restés `pending`). Le worker doit donc considérer qu'un ordre peut être annulé automatiquement au-delà de ce délai.

### Recommandations worker

- Écouter le canal WS `futures/order` pour recevoir rapidement l'état (`state`) et les fills (`deal_size`, `deal_avg_price`).
- En l'absence d'event WS dans un court délai (ex. 1–2 s), effectuer un polling `order-detail` avec backoff (ex. 0.25s, 0.5s, 1s, 2s). Arrêter après N tentatives ou au premier `code=1000`.
- À la réception de données enrichies, publier un signal d'ordre vers `trading-app` (endpoint `/api/ws-worker/orders`) pour consolider:
  - `kind`, `status`, `order_id`, `client_order_id`,
  - `price`, `size`, `deal_size`, `deal_avg_price`,
  - contexte (plan, position) si disponible.
- Si le worker détecte qu'un ordre est toujours `pending` après 2 minutes (ou reçoit `trade_entry.timeout.cancel_attempt` dans les logs), il peut ignorer les notifications de fill ultérieurs (l'ordre aura été annulé côté REST).

## Limitations

- Maximum 500 connexions par IP
- Connexions inactives fermées après 5 secondes
- Longueur totale des canaux limitée à 4096 bytes



