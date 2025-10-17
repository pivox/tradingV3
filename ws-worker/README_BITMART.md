# BitMart WebSocket Worker

Ce worker WebSocket permet de recevoir en temps réel les données de trading BitMart pour les ordres, positions et klines.

## Fonctionnalités

- **Klines** : Données de bougies en temps réel (1m, 5m, 15m, 30m, 1h, 2h, 4h, 1d, 1w)
- **Ordres** : Mise à jour des ordres en temps réel (soumission, exécution, annulation)
- **Positions** : Suivi des positions ouvertes et de leurs changements
- **Authentification** : Support des canaux privés avec authentification API
- **Reconnexion automatique** : Gestion des déconnexions et reconnexions
- **API de contrôle HTTP** : Interface REST pour contrôler les souscriptions

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

- `GET /status` - Obtenir le statut du worker
- `GET /help` - Afficher l'aide et les exemples
- `POST /klines/subscribe` - S'abonner aux klines
- `POST /klines/unsubscribe` - Se désabonner des klines
- `POST /orders/subscribe` - S'abonner aux ordres
- `POST /orders/unsubscribe` - Se désabonner des ordres
- `POST /positions/subscribe` - S'abonner aux positions
- `POST /positions/unsubscribe` - Se désabonner des positions
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

- **MainWorker** : Orchestrateur principal
- **BitmartWsClient** : Client WebSocket avec support authentification
- **AuthHandler** : Gestionnaire d'authentification
- **KlineWorker** : Gestionnaire des klines
- **OrderWorker** : Gestionnaire des ordres
- **PositionWorker** : Gestionnaire des positions
- **HttpControlServer** : Serveur de contrôle HTTP

## Gestion des erreurs

- Reconnexion automatique en cas de déconnexion
- Gestion des erreurs d'authentification
- Retry automatique des souscriptions
- Logs détaillés pour le debugging

## Limitations

- Maximum 500 connexions par IP
- Connexions inactives fermées après 5 secondes
- Longueur totale des canaux limitée à 4096 bytes





