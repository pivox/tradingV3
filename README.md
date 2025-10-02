# Trading Signal Detection Platform

Ce projet est une plateforme complète de détection de signaux de trading basée sur un stack multi-services comprenant :
- **Symfony 7 (PHP 8.3)** pour l'API principale
- **React + Vite** pour le frontend utilisateur
- **FastAPI (Python)** pour l'analyse des indicateurs techniques
- **MySQL 8** pour la base de données
- **Nginx** comme serveur web
- **Docker Compose** pour l'orchestration

## 📦 Structure du projet

```
.
├── symfony-app/       # Symfony 7 + API Platform
├── frontend/          # React + Vite
├── python-api/        # FastAPI pour les indicateurs
├── bitmart_positions_sync/  # Worker Python de synchronisation positions Bitmart
├── nginx/             # Config Nginx
├── docker-compose.yml
└── README.md
```

## 🚀 Lancer le projet

### Prérequis
- Docker & Docker Compose installés

### Étapes
1. Clonez le dépôt :
   ```bash
   git clone https://github.com/votre-utilisateur/votre-projet.git
   cd votre-projet
   ```

2. Lancez les containers :
   ```bash
   docker-compose up --build
   ```

3. Accédez aux services :
- Frontend : http://localhost:3000
- API Symfony : http://localhost:8080
- API Python (indicateurs) : http://localhost:8888

## 🔧 Services & Ports

| Service      | URL                   | Port local |
|-------------|-----------------------|------------|
| Frontend    | http://localhost:3000  | 3000       |
| API Symfony | http://localhost:8080  | 8080       |
| API Python  | http://localhost:8888  | 8888       |
| Bitmart Position Sync | http://localhost:9000  | 9000       |
| MySQL       | localhost              | 3306       |

## 🧩 Indicateurs disponibles (Python FastAPI)
- RSI
- MACD
- ADX
- Bollinger Bands
- EMA
- Candle Patterns
- Stochastic RSI
- Supertrend
- VWAP
- Volume

Chaque indicateur est exposé via une route REST dédiée.

## 🔄 Bitmart Position Sync
- Service Docker Compose : `bitmart-position-sync`
- Fonction : ouvre un flux WebSocket privé Bitmart (futures) et met à jour régulièrement la table `positions` de Symfony.
- Mise à jour de secours : effectue un poll REST (`/contract/private/position-v2`) pour garder la base alignée si le flux temps réel manque un évènement.
- Variables d'environnement utiles :
- `BITMART_API_KEY`, `BITMART_SECRET_KEY`, `BITMART_API_MEMO`
- `BITMART_WS_URL`, `BITMART_WS_CHANNELS` (défaut `wss://openapi-ws-v2.bitmart.com/api?protocol=1.1` + `futures/position`)
- `BITMART_POLL_SECONDS` (défaut 120s)
- `BITMART_SYNC_HOST`, `BITMART_SYNC_PORT` (défaut `0.0.0.0:9000`)
- `BITMART_AUTO_START` (défaut `true`)

### Endpoints de contrôle (port 9000)
- `GET /status` → état courant (`running`, `channels`)
- `POST /control/start` → démarre l'écoute WebSocket + poll REST
- `POST /control/stop` → arrête l'écoute et ferme la connexion Bitmart
- `POST /subscriptions/{SYMBOL}` → souscrit à une position privée (ex : `BTCUSDT`)
- `DELETE /subscriptions/{SYMBOL}` → se désinscrit du flux correspondant

## 📝 Notes importantes
- Le système permet de lancer des analyses par timeframe (1m, 5m, 15m, 1h, 4h) depuis `/api/frame{timeframe}/run` sur l'API Python.
- Les résultats sont unifiés pour un traitement plus simple côté Symfony ou Frontend.

---

Développé par Haythem 🚀
