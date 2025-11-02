# BitMart WebSocket Worker

Ce worker WebSocket permet de recevoir en temps réel les données de trading BitMart pour les ordres, positions et klines.

## Fonctionnalités

- **Klines** : Données de bougies en temps réel (1m, 5m, 15m, 30m, 1h, 2h, 4h, 1d, 1w)
- **Ordres** : Mise à jour des ordres en temps réel (soumission, exécution, annulation)
- **Positions** : Suivi des positions ouvertes et de leurs changements
- **Placement d'ordres intelligent** : Monitoring d'entry zone et placement automatique
- **Stop-Loss / Take-Profit automatique** : Surveillance en temps réel et clôture automatique
- **Authentification** : Support des canaux privés avec authentification API
- **Reconnexion automatique** : Gestion des déconnexions et reconnexions
- **API de contrôle HTTP** : Interface REST pour contrôler les souscriptions et les ordres

## Installation

1. Installer les dépendances :
```bash
composer install
```

2. Configurer les variables d'environnement :
```bash
cp .env.example .env
# Éditer .env avec vos clés API BitMart
```

## Configuration

### Variables d'environnement

- `BITMART_PUBLIC_WS_URI` : URL WebSocket publique (par défaut: wss://openapi-ws-v2.bitmart.com/api?protocol=1.1)
- `BITMART_PRIVATE_WS_URI` : URL WebSocket privée (par défaut: wss://openapi-ws-v2.bitmart.com/user?protocol=1.1)
- `BITMART_API_KEY` : Clé API BitMart
- `BITMART_API_SECRET` : Secret API BitMart
- `BITMART_API_MEMO` : Mémo API BitMart
- `CTRL_ADDR` : Adresse du serveur de contrôle HTTP (par défaut: 0.0.0.0:8089)
- `SUBSCRIBE_BATCH` : Nombre de souscriptions par lot (par défaut: 20)
- `SUBSCRIBE_DELAY_MS` : Délai entre les lots de souscriptions (par défaut: 200ms)
- `PING_INTERVAL_S` : Intervalle de ping (par défaut: 15s)
- `RECONNECT_DELAY_S` : Délai de reconnexion (par défaut: 5s)

## Utilisation

### Démarrer le worker

```bash
php bin/console ws:run
```

### API de contrôle HTTP

Le worker expose une API REST sur le port configuré (par défaut 8089).

#### Endpoints disponibles

**Monitoring WebSocket :**
- `GET /status` - Obtenir le statut du worker
- `GET /help` - Afficher l'aide et les exemples
- `POST /klines/subscribe` - S'abonner aux klines
- `POST /klines/unsubscribe` - Se désabonner des klines
- `POST /orders/subscribe` - S'abonner aux ordres
- `POST /orders/unsubscribe` - Se désabonner des ordres
- `POST /positions/subscribe` - S'abonner aux positions
- `POST /positions/unsubscribe` - Se désabonner des positions
- `POST /stop` - Arrêter le worker

**Placement d'ordres :**
- `POST /api/place-order` - Placer un ordre avec entry zone monitoring
- `POST /api/monitor-position` - Monitorer une position pour SL/TP
- `GET /api/orders/status` - Obtenir le statut des ordres en attente

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

**Obtenir le statut :**
```bash
curl http://localhost:8089/status
```

**Voir l'aide :**
```bash
curl http://localhost:8089/help
```

**Placer un ordre avec entry zone monitoring :**
```bash
curl -X POST http://localhost:8089/api/place-order \
  -H "Content-Type: application/json" \
  -d '{
    "id": "order-123",
    "symbol": "BTCUSDT",
    "side": "long",
    "entry_zone_min": 65000,
    "entry_zone_max": 65500,
    "quantity": 0.01,
    "leverage": 10,
    "stop_loss": 64000,
    "take_profit": 68000,
    "timeout_seconds": 300
  }'
```

**Monitorer une position pour SL/TP :**
```bash
curl -X POST http://localhost:8089/api/monitor-position \
  -H "Content-Type: application/json" \
  -d '{
    "id": "position-123",
    "symbol": "BTCUSDT",
    "order_id": "order-123",
    "stop_loss": 64000,
    "take_profit": 68000
  }'
```

**Obtenir le statut des ordres :**
```bash
curl http://localhost:8089/api/orders/status
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

## Architecture

Le worker utilise une architecture modulaire :

**Workers WebSocket :**
- **MainWorker** : Orchestrateur principal
- **BitmartWsClient** : Client WebSocket avec support authentification
- **AuthHandler** : Gestionnaire d'authentification
- **KlineWorker** : Gestionnaire des klines
- **OrderWorker** : Gestionnaire des ordres
- **PositionWorker** : Gestionnaire des positions
- **HttpControlServer** : Serveur de contrôle HTTP

**Services de Trading :**
- **OrderPlacementService** : Placement d'ordres via API REST Bitmart
- **EntryZoneMonitor** : Surveillance des zones d'entrée et placement automatique
- **StopLossTakeProfitMonitor** : Surveillance SL/TP et clôture automatique
- **OrderQueue** : File d'attente des ordres en attente et positions monitorées

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

## Flux de Placement d'Ordre

Pour une documentation complète du flux de placement d'ordres, voir [ORDER_PLACEMENT_FLOW.md](../trading-app/docs/ORDER_PLACEMENT_FLOW.md).

### Résumé du flux :

1. **trading-app** génère un signal de trading avec entry zone
2. Dispatch un `PlaceOrderMessage` via Symfony Messenger
3. **ws-worker** reçoit la demande via `/api/place-order`
4. `EntryZoneMonitor` surveille le prix en temps réel
5. Dès que le prix entre dans l'entry zone, `OrderPlacementService` place l'ordre
6. **ws-worker** notifie **trading-app** via callback HTTP
7. Si SL/TP définis, `StopLossTakeProfitMonitor` prend le relai
8. Clôture automatique dès que SL ou TP atteint

## Limitations

- Maximum 500 connexions par IP
- Connexions inactives fermées après 5 secondes
- Longueur totale des canaux limitée à 4096 bytes
- Latence de placement d'ordre : < 500ms après entrée dans entry zone
- Latence de déclenchement SL : < 500ms après trigger



