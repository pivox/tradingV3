# WS Worker - Bitmart WebSocket Client

Worker WebSocket minimal pour Bitmart avec serveur HTTP de contrôle.

## Structure

```
ws-worker/
├─ bin/console                 # Point d'entrée Symfony Console
├─ config/
│  ├─ packages/framework.yaml # Configuration Symfony
│  └─ services.yaml           # Services et auto-wiring
├─ src/
│  ├─ Command/WsWorkerCommand.php    # Commande principale
│  ├─ Infra/BitmartWsClient.php      # Client WebSocket Bitmart
│  ├─ Infra/HttpControlServer.php    # Serveur HTTP de contrôle
│  ├─ Order/                         # DTO + dispatcher des signaux ordre
│  └─ Worker/                        # Workers Kline / Orders / Positions
├─ .env                       # Variables d'environnement
└─ Dockerfile                 # Image Docker
```

## Configuration (.env)

```env
APP_ENV=prod
BITMART_WS_URI=wss://ws-pro.bitmart.com/api?protocol=1.1
CTRL_ADDR=0.0.0.0:8089
SUBSCRIBE_BATCH=20
SUBSCRIBE_DELAY_MS=200
PING_INTERVAL_S=15
TRADING_APP_BASE_URI=http://localhost:8080
TRADING_APP_ORDER_SIGNAL_PATH=/api/ws-worker/orders
TRADING_APP_SHARED_SECRET=change-me
TRADING_APP_REQUEST_TIMEOUT=2.0
TRADING_APP_SIGNAL_MAX_RETRIES=5
TRADING_APP_SIGNAL_FAILURE_LOG=var/order-signal-failures.log
```

## Utilisation

### Démarrer le worker
```bash
php bin/console ws:run
```

### API HTTP de contrôle

Le worker expose un serveur HTTP sur le port 8089 pour contrôler les abonnements.

#### S'abonner à des klines
```bash
curl -X POST http://localhost:8089/subscribe \
  -H "Content-Type: application/json" \
  -d '{"symbol": "BTCUSDT", "tfs": ["1m", "5m", "15m"]}'
```

#### Se désabonner
```bash
curl -X POST http://localhost:8089/unsubscribe \
  -H "Content-Type: application/json" \
  -d '{"symbol": "BTCUSDT", "tfs": ["1m"]}'
```

### Test de l'API
```bash
php test_api.php
```

## Docker

```bash
docker build -t ws-worker .
docker run -p 8089:8089 ws-worker
```

## Fonctionnalités

- ✅ Connexion WebSocket à Bitmart
- ✅ Abonnement/désabonnement dynamique via HTTP
- ✅ Gestion des souscriptions par lots (anti-burst)
- ✅ Ping/pong automatique
- ✅ Traitement des messages klines
- ✅ Emission d’un signal REST vers trading-app à chaque nouvel ordre détecté
- ✅ Configuration via variables d'environnement
- ✅ Docker ready

## Notes

- Le worker traite les messages klines mais ne les persiste pas (à implémenter selon vos besoins)
- Les signaux d’ordres sont envoyés vers trading-app si les variables `TRADING_APP_*` sont renseignées. Un fichier `var/order-signal-failures.log` garde la trace des envois échoués après retries.
- Les abonnements sont gérés par lots pour éviter de surcharger l'API Bitmart
- Le serveur HTTP de contrôle permet de gérer dynamiquement les abonnements sans redémarrer





