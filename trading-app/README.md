# Trading App - Système MTF BitMart

Application de trading automatisé basée sur l'analyse multi-timeframe (MTF) pour BitMart Futures.

## 🚀 Démarrage rapide

### 1. Configuration de l'environnement

Créez un fichier `.env.local` avec vos clés BitMart :

```bash
# Database configuration
DATABASE_URL="postgresql://postgres:password@localhost:5433/trading_app?serverVersion=15&charset=utf8"

# BitMart API configuration
BITMART_API_KEY="your_api_key_here"
BITMART_SECRET_KEY="your_secret_key_here"
BITMART_BASE_URL="https://api-cloud-v2.bitmart.com"

# WebSocket configuration
BITMART_WS_URL="wss://ws-manager-compress.bitmart.com/api?protocol=1.1"
```

### 2. Démarrage avec Docker

```bash
# Démarrer l'application
docker-compose up -d trading-app-php trading-app-nginx trading-app-db

# Exécuter les migrations
docker-compose exec trading-app-php php bin/console doctrine:migrations:migrate
```

### 3. Commandes disponibles

#### Récupérer la liste des contrats

```bash
# Tous les contrats
docker-compose exec trading-app-php php bin/console bitmart:fetch-contracts

# Contrat spécifique
docker-compose exec trading-app-php php bin/console bitmart:fetch-contracts --symbol=BTCUSDT

# Format JSON
docker-compose exec trading-app-php php bin/console bitmart:fetch-contracts --output=json
```

#### Récupérer les klines

```bash
# Klines 1h pour BTCUSDT
docker-compose exec trading-app-php php bin/console bitmart:fetch-klines BTCUSDT

# Klines 4h avec limite
docker-compose exec trading-app-php php bin/console bitmart:fetch-klines BTCUSDT --timeframe=4h --limit=50

# Format JSON
docker-compose exec trading-app-php php bin/console bitmart:fetch-klines BTCUSDT --output=json

# Période spécifique
docker-compose exec trading-app-php php bin/console bitmart:fetch-klines BTCUSDT --from="2024-01-01 00:00:00" --to="2024-01-02 00:00:00"
```

## 🏗️ Architecture

L'application suit une architecture hexagonale avec :

- **Domain** : Logique métier pure (DTOs, Enums, Services)
- **Application** : Workflows et orchestration
- **Infrastructure** : Adaptateurs externes (BitMart API, Base de données)
- **Presentation** : Contrôleurs et commandes CLI

## 📊 Fonctionnalités

### ✅ Implémentées

- [x] Récupération des contrats BitMart
- [x] Récupération des klines (4h, 1h, 15m, 5m, 1m)
- [x] Architecture hexagonale
- [x] Base de données PostgreSQL
- [x] Conteneurisation Docker
- [x] Commandes CLI

### 🚧 En cours

- [ ] Calcul des indicateurs techniques
- [ ] Génération de signaux
- [ ] Validation MTF
- [ ] Planification d'ordres
- [ ] WebSocket en temps réel

### 📋 À venir

- [ ] Workflows Temporal
- [ ] Exécution d'ordres
- [ ] Gestion des risques
- [ ] Interface web
- [ ] Tests unitaires

## ⚙️ Exécution des ordres

- Entrée maker par défaut : les plans LIMIT sont envoyés en `mode=4` (post-only) pour tenter une exécution maker.
- Positionnement rapproché : l'entrée LIMIT se cale 1 tick à l'intérieur du carnet (recalibrée si l'écart au mark dépasse ~0.5%).
- Fallback automatique : si Bitmart rejette la soumission maker, la même intention est renvoyée immédiatement en ordre `market` (taker) avec le même `client_order_id`.
- Timeout de 2 minutes : chaque ordre accepté programme une annulation différée via Messenger (`CancelOrderMessage`), afin d'éviter les LIMIT qui stagnent.
- TP/SL préconfigurés : les prix `preset_*` (stop loss / take profit) sont envoyés autant pour le maker initial que pour le fallback taker, garantissant la couverture dès le fill.
- TP hybride : le take-profit final combine le k·R théorique (ex. 2R) et les pivots intraday (PP/R1/S1...).
- Traçage fin : `var/log/order-journey*.log` rejoue l’intégralité du pipeline (signal READY → plan → exécution). Voir `docs/ORDER_FLOW_README.md` pour le détail des étapes et paramètres (buffers, politiques TP).
- Transport Messenger : un container `trading-app-messenger` lance `php bin/console messenger:consume order_timeout` en continu (s'appuie sur le service `redis` embarqué). Si vous faites tourner l'app sans Docker, exécutez la même commande manuellement.
- Logs utiles : `execution.order_attempt_failed`, `execution.timeout_scheduled`, `trade_entry.timeout.cancel_attempt` documentent les étapes maker → taker et l'annulation différée.

## 🔧 Développement

### Structure des fichiers

```
src/
├── Domain/                 # Logique métier
│   ├── Common/
│   │   ├── Dto/           # Objets de transfert
│   │   └── Enum/          # Énumérations
│   ├── Kline/Service/     # Services klines
│   ├── Indicator/Service/ # Services indicateurs
│   ├── Mtf/Service/       # Services MTF
│   └── Trade/Service/     # Services trading
├── Application/           # Workflows et orchestration
├── Infrastructure/        # Adaptateurs externes
│   ├── Http/             # Client REST BitMart
│   ├── WebSocket/        # Client WebSocket
│   ├── Persistence/      # Repositories
│   └── Cache/            # Cache de validation
└── Presentation/         # Contrôleurs et CLI
    └── Command/          # Commandes console
```

### Tests

```bash
# Tests unitaires
docker-compose exec trading-app-php php bin/phpunit

# Tests d'intégration
docker-compose exec trading-app-php php bin/phpunit --testsuite=integration
```

## 📝 Logs

Les logs sont disponibles dans le conteneur :

```bash
# Voir les logs de l'application
docker-compose logs -f trading-app-php

# Voir les logs de la base de données
docker-compose logs -f trading-app-db
```

- Traçage complet placement d'ordre : `var/log/order-journey*.log` (toutes les étapes depuis le signal MTF jusqu'à l'ID d'ordre Bitmart).

## 🔒 Sécurité

- Clés API stockées dans les variables d'environnement
- Validation des entrées utilisateur
- Headers de sécurité HTTP
- Isolation des conteneurs Docker

## 📚 Documentation

- [Documentation BitMart Futures V2](https://developer-pro.bitmart.com/en/futuresv2/)
- [Architecture hexagonale Symfony](https://symfony.com/doc/current/best_practices/hexagonal_architecture.html)
- [Doctrine ORM](https://www.doctrine-project.org/projects/orm.html)
- Documentation interne : `docs/ORDER_FLOW_README.md` (parcours order_journey et règles TP/pivots), `docs/WS_WORKER_BALANCE_INTEGRATION.md`.
