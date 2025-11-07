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
 
# Optional: Rate-limit overrides per endpoint bucket (limit/windowSec)
# Example: slow down private positions and open-orders to reduce 429
# BITMART_RATE_PRIVATE_POSITION="3/2"
# BITMART_RATE_PRIVATE_GET_OPEN_ORDERS="20/2"
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

#### Lancer un run MTF contractuel

```bash
# Dry-run multi-symboles avec verrous globaux
docker-compose exec trading-app-php php bin/console mtf:run \
  --symbols="BTCUSDT,ETHUSDT" \
  --dry-run=1 \
  --force-timeframe-check

# Exécution ciblée 1h avec verrou par symbole et métadonnées utilisateur
docker-compose exec trading-app-php php bin/console mtf:run \
  --tf=1h \
  --lock-per-symbol \
  --user-id="ops-squad" \
  --ip-address="192.168.0.15"
```

## 🏗️ Architecture

L'application implémente désormais explicitement le découpage **Application / Domaine / Infrastructure** introduit par le nouveau contrat MTF :

```mermaid
flowchart LR
    subgraph Application
        facade(MtfRunService / MtfValidatorInterface)
        orchestrator(MtfRunOrchestrator)
        decision(TradingDecisionHandler)
    end

    subgraph Domaine
        strategies(HighConvictionValidation)
        dto(DTO internes)
    end

    subgraph Infrastructure
        repo(Repositories Doctrine)
        provider(Providers BitMart)
        cache(Caches Redis/DB)
    end

    facade --> orchestrator --> decision
    orchestrator --> repo
    orchestrator --> provider
    decision --> provider
    decision --> repo
    orchestrator --> strategies
```

- **Application** : façade `MtfValidatorInterface`, orchestrateur, gestion des verrous et des switches.
- **Domaine** : stratégies, DTOs, règles métier (ex : alignement multi-timeframe).
- **Infrastructure** : bases de données, clients HTTP/WebSocket, caches.
- **Présentation** : contrôleurs REST, commandes CLI (ex. `mtf:run`).

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

### Stop-loss pivot & garde minimale

- Politique configurable : `pivot_sl_policy` supporte désormais `nearest_below`, `strongest_below`, ainsi que les clés explicites `s1` / `s2` (et `r1` / `r2` côté short). L’algorithme choisit en priorité le niveau demandé et retombe sur un pivot cohérent s’il est manquant.
- Pivots absents : si l’indicateur ne renvoie aucun pivot journalier valide, le système bascule automatiquement sur le stop `risk` (calcul distance-risk).
- Garde 0,5 % universelle : après toute bascule (pivot → risk, ou risque budgeté), le builder impose une distance minimale de 0,5 % (≥ 1 tick). Si le stop calculé est trop serré, il est repoussé jusqu’à ce seuil puis la taille est recalculée avant le levier, ce qui évite les expositions à 50x pour un simple tick.
- Journaux : les ajustements sont visibles via `order_plan.stop_min_distance_adjusted` (risk) ou `order_plan.stop_min_distance_adjusted_pivot` (pivot conservé et repoussé).
- Impact sur sizing : l’ajustement est effectué **avant** la quantification finale du volume, garantissant que le levier reflète bien la distance étendue.
codex resume 019a49f3-50e7-7552-8cd6-4ad3b3fad83b

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
