# Configuration des variables d'environnement

Ce fichier documente les variables d'environnement nécessaires pour le projet TradingV3 avec Docker.

## 📁 Emplacement du fichier .env

Le fichier `.env` doit être à la **racine du projet** :
```
tradingV3/
├── .env                    ← ICI
├── docker-compose.yml
├── ws-worker/
├── trading-app/
└── ...
```

## 🔑 Variables obligatoires

### BitMart API

```env
# Clés API BitMart (obtenir sur https://www.bitmart.com/api/en-US)
BITMART_API_KEY=your_api_key_here
BITMART_SECRET_KEY=your_api_secret_here    # ⚠️ Note: SECRET_KEY, pas API_SECRET
BITMART_API_MEMO=your_api_memo_here

# URLs de l'API BitMart
BITMART_BASE_URL=https://api-cloud.bitmart.com
BITMART_PUBLIC_API_URL=https://api-cloud.bitmart.com
BITMART_PRIVATE_API_URL=https://api-cloud.bitmart.com

# URLs WebSocket BitMart
BITMART_WS_PRIVATE_URL=wss://openapi-ws-v2.bitmart.com/user?protocol=1.1
BITMART_WS_DEVICE=web
```

### WS Worker & Trading App

```env
# Secret partagé pour l'authentification HMAC entre ws-worker et trading-app
# ⚠️ DOIT être identique des deux côtés
# Générer avec : openssl rand -hex 32
WS_WORKER_SHARED_SECRET=your_secure_shared_secret_here

# URL de trading-app (pour les signaux du ws-worker)
TRADING_APP_BASE_URI=http://trading-app-nginx

# Configuration optionnelle des signaux
TRADING_APP_ORDER_SIGNAL_PATH=/api/ws-worker/orders
TRADING_APP_BALANCE_SIGNAL_PATH=/api/ws-worker/balance
TRADING_APP_REQUEST_TIMEOUT=2.0
TRADING_APP_SIGNAL_MAX_RETRIES=5
```

### Base de données Trading App

```env
# PostgreSQL pour trading-app
TRADING_APP_DATABASE_URL=postgresql://postgres:password@trading-app-db:5432/trading_app?serverVersion=15&charset=utf8
```

## 📋 Exemple de fichier .env

```env
# ============================================================================
# BitMart API Configuration
# ============================================================================
BITMART_API_KEY=abc123def456ghi789
BITMART_SECRET_KEY=your_secret_key_here
BITMART_API_MEMO=your_memo_here
BITMART_BASE_URL=https://api-cloud.bitmart.com
BITMART_PUBLIC_API_URL=https://api-cloud.bitmart.com
BITMART_PRIVATE_API_URL=https://api-cloud.bitmart.com
BITMART_WS_PRIVATE_URL=wss://openapi-ws-v2.bitmart.com/user?protocol=1.1
BITMART_WS_DEVICE=web

# ============================================================================
# WS Worker & Trading App Integration
# ============================================================================
WS_WORKER_SHARED_SECRET=a1b2c3d4e5f6789abc123def456ghi789abc123def456ghi789abc123def456
TRADING_APP_BASE_URI=http://trading-app-nginx
TRADING_APP_ORDER_SIGNAL_PATH=/api/ws-worker/orders
TRADING_APP_BALANCE_SIGNAL_PATH=/api/ws-worker/balance
TRADING_APP_REQUEST_TIMEOUT=2.0
TRADING_APP_SIGNAL_MAX_RETRIES=5

# ============================================================================
# Database
# ============================================================================
TRADING_APP_DATABASE_URL=postgresql://postgres:password@trading-app-db:5432/trading_app?serverVersion=15&charset=utf8
```

## 🔐 Sécurité

### Générer un secret sécurisé

```bash
openssl rand -hex 32
```

Ce secret doit être utilisé pour `WS_WORKER_SHARED_SECRET`.

### Permissions du fichier .env

```bash
chmod 600 .env
```

### ⚠️ Important

- **Ne JAMAIS commiter le fichier .env dans git**
- Le fichier `.env` est déjà dans `.gitignore`
- Utiliser des secrets différents pour dev/staging/production
- Renouveler les secrets régulièrement

## 🔄 Mapping des variables Docker

### Différence entre .env racine et containers

Docker-compose mappe certaines variables différemment (voir `docker-compose.yml` lignes 264-295) :

| Variable .env racine | Variable dans container | Service |
|---------------------|------------------------|---------|
| `BITMART_SECRET_KEY` | `BITMART_API_SECRET` | ws-worker |
| `WS_WORKER_SHARED_SECRET` | `TRADING_APP_SHARED_SECRET` | ws-worker |
| `WS_WORKER_SHARED_SECRET` | `WS_WORKER_SHARED_SECRET` | trading-app |

⚠️ **Attention** : 
- Dans le .env racine, utilisez `BITMART_SECRET_KEY` (docker-compose le mappe vers `BITMART_API_SECRET`)
- Le ws-worker fonctionne UNIQUEMENT en mode Docker (pas de mode standalone)

## 🧪 Vérification

### Vérifier que les variables sont chargées

```bash
# Lister les variables d'un container
docker-compose exec ws-worker env | grep BITMART
docker-compose exec ws-worker env | grep TRADING_APP

# Vérifier dans trading-app
docker-compose exec trading-app-php env | grep WS_WORKER
```

### Tester la connexion au ws-worker

```bash
# Vérifier que le worker est démarré
docker-compose ps ws-worker

# Tester l'API de contrôle
curl http://localhost:8089/status | jq

# S'abonner au balance
curl -X POST http://localhost:8089/balance/subscribe
```

### Tester l'endpoint trading-app

```bash
cd trading-app
./scripts/test_balance_endpoint.sh
```

## 📚 Références

- **WS Worker** : `ws-worker/README_BITMART.md`
- **Trading App** : `trading-app/docs/WS_WORKER_BALANCE_INTEGRATION.md`
- **Docker Compose** : `docker-compose.yml` (lignes 264-295 pour ws-worker)
- **Variables détaillées** : `ws-worker/env.example`

## 🆘 Troubleshooting

### Le ws-worker ne démarre pas

```bash
# Voir les logs
docker-compose logs ws-worker

# Vérifier les variables
docker-compose exec ws-worker env | grep -E "(BITMART|TRADING_APP)"
```

### Erreur "Authentication failed"

- Vérifier que `BITMART_API_KEY`, `BITMART_SECRET_KEY` et `BITMART_API_MEMO` sont corrects
- Vérifier que les clés API ont les bonnes permissions sur BitMart
- Vérifier l'IP whitelist si configurée

### Erreur "Invalid signature" dans trading-app

- Vérifier que `WS_WORKER_SHARED_SECRET` est identique dans le .env racine
- Le secret est utilisé pour :
  - `TRADING_APP_SHARED_SECRET` dans ws-worker
  - `WS_WORKER_SHARED_SECRET` dans trading-app

### Les signaux ne sont pas reçus

- Vérifier que trading-app est accessible depuis ws-worker :
  ```bash
  docker-compose exec ws-worker curl http://trading-app-nginx/api/ws-worker/balance
  ```
- Vérifier les logs de trading-app :
  ```bash
  docker-compose logs trading-app-php | grep BalanceSignal
  ```

