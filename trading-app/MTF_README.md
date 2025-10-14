# Système MTF (Multi-Timeframe) Trading

## Vue d'ensemble

Le système MTF est un système de trading automatisé qui analyse les marchés à terme BitMart en utilisant une approche multi-timeframe (4h, 1h, 15m, 5m, 1m) pour valider des setups et déclencher des ordres de trading.

## Architecture

### Composants principaux

1. **Workflow Temporal** : Orchestre l'exécution du cycle MTF chaque minute
2. **Services MTF** : Logique métier pour la validation et l'analyse
3. **Rate Limiter** : Gère les limites de l'API BitMart
4. **Kill Switches** : Système de sécurité pour arrêter le trading
5. **Cache de validation** : Stocke les états de validation des timeframes
6. **Audit** : Traçabilité complète des opérations

### Flux de données

```
Temporal Schedule (chaque minute)
    ↓
MtfMinuteWorkflow
    ↓
MtfService.executeMtfCycle()
    ↓
Pour chaque symbole:
    ├─ Vérification kill switches
    ├─ Validation 4h (REST)
    ├─ Validation 1h (REST)
    ├─ Validation 15m (REST)
    ├─ Validation 5m (REST)
    ├─ Validation 1m (REST)
    ├─ Vérification cohérence MTF
    ├─ Application des filtres
    └─ Création order_plan
```

## Configuration

### Variables d'environnement

```bash
# Temporal
TEMPORAL_ADDRESS=temporal:7233
TEMPORAL_NAMESPACE=default

# BitMart API
BITMART_API_KEY=your-api-key
BITMART_SECRET_KEY=your-secret-key
BITMART_BASE_URL=https://api-cloud-v2.bitmart.com

# Rate Limiting
MTF_RATE_LIMIT_CAPACITY=6
MTF_RATE_LIMIT_REFILL_RATE=6
MTF_RATE_LIMIT_REFILL_INTERVAL=1000

# MTF
MTF_SYMBOLS_TO_WATCH=BTCUSDT,ETHUSDT,ADAUSDT,SOLUSDT,DOTUSDT
MTF_GRACE_WINDOW_MINUTES=4
MTF_MAX_CANDLES_PER_REQUEST=500
```

## Utilisation

### Démarrage du système

1. **Démarrer le worker Temporal** :
```bash
php bin/console mtf:worker --daemon
```

2. **Démarrer le workflow** :
```bash
php bin/console mtf:workflow start
```

3. **Vérifier le statut** :
```bash
php bin/console mtf:workflow status
```

### API REST

#### Statut du système
```bash
GET /api/mtf/status
```

#### Contrôle du workflow
```bash
POST /api/mtf/start
POST /api/mtf/pause
POST /api/mtf/resume
POST /api/mtf/stop
POST /api/mtf/restart
```

#### Gestion des kill switches
```bash
GET /api/mtf/switches
POST /api/mtf/switches/{id}/toggle
```

#### État MTF
```bash
GET /api/mtf/states
```

#### Audit
```bash
GET /api/mtf/audit?limit=100&symbol=BTCUSDT
```

#### Order Plans
```bash
GET /api/mtf/order-plans?limit=50&status=PLANNED
```

## Logique de validation

### Timeframes

1. **4h** : Validation des tendances principales
2. **1h** : Validation des tendances intermédiaires
3. **15m** : Validation des entrées
4. **5m** : Confirmation d'entrée
5. **1m** : Déclencheur final

### Règles de validation

#### 4h
- RSI < 80 et > 20
- Volume minimum
- Tendance claire

#### 1h
- RSI < 75 et > 25
- Cohérence avec 4h
- EMA20/EMA50 alignées

#### 15m
- RSI < 70 et > 30
- Distance prix-MA21 ≤ 2×ATR
- Pullback confirmé

#### 5m
- RSI < 70 et > 30
- Prix proche de MA9
- Confirmation d'entrée

#### 1m
- RSI < 70 et > 30
- MACD confirmé
- Déclencheur final

### Filtres d'exécution

- RSI < 70
- Distance prix-MA21 ≤ 2×ATR
- Pullback confirmé (MA9/21 ou VWAP)
- Pas de divergence bloquante

## Kill Switches

### Types de kill switches

1. **GLOBAL** : Arrête tout le système
2. **SYMBOL:{SYMBOL}** : Arrête un symbole spécifique
3. **SYMBOL_TF:{SYMBOL}:{TF}** : Arrête un timeframe spécifique

### Utilisation

```bash
# Arrêter globalement
curl -X POST /api/mtf/switches/1/toggle

# Arrêter BTCUSDT
curl -X POST /api/mtf/switches/2/toggle

# Arrêter BTCUSDT 4h
curl -X POST /api/mtf/switches/7/toggle
```

## Rate Limiting

### Token Bucket

- **Capacité** : 6 tokens
- **Refill** : 6 tokens/seconde
- **Intervalle** : 1000ms

### Gestion des erreurs

- **HTTP 429** : Attente de 2 secondes
- **Timeout** : 3 retries avec backoff exponentiel
- **Erreurs** : Journalisation complète

## Monitoring

### Métriques

- Nombre d'exécutions par minute
- Taux d'erreur
- Latence des requêtes
- Utilisation du rate limiter

### Logs

- Niveau INFO pour les opérations normales
- Niveau WARNING pour les erreurs récupérables
- Niveau ERROR pour les erreurs critiques

### Audit

Chaque opération est tracée dans `mtf_audit` :
- Symbol
- Run ID
- Step
- Timeframe
- Cause
- Détails

## Sécurité

### Authentification BitMart

- API Key
- Timestamp
- Signature HMAC-SHA256
- Dérive d'horloge max : 3 secondes

### Validation des données

- Tous les timestamps en UTC
- Alignement sur les bornes de timeframe
- Validation des prix et volumes
- Contrôles de cohérence

## Développement

### Structure des fichiers

```
src/
├── Domain/Mtf/Service/          # Services métier
├── Application/                 # Workflows et activités
├── Infrastructure/              # Clients externes
├── Controller/                  # Endpoints REST
├── Command/                     # Commandes CLI
└── Entity/                      # Entités de données
```

### Tests

```bash
# Tests unitaires
php bin/phpunit tests/Unit/

# Tests d'intégration
php bin/phpunit tests/Integration/

# Tests MTF spécifiques
php bin/phpunit tests/Mtf/
```

### Débogage

```bash
# Logs en temps réel
tail -f var/log/mtf.log

# Statut détaillé
php bin/console mtf:workflow status

# Audit récent
curl /api/mtf/audit?limit=50
```

## Maintenance

### Nettoyage

- Klines anciennes (> 30 jours)
- Audits anciens (> 30 jours)
- Cache expiré
- Order plans exécutés

### Sauvegarde

- Base de données quotidienne
- Configuration
- Logs importants

### Mise à jour

1. Arrêter le workflow
2. Mettre à jour le code
3. Exécuter les migrations
4. Redémarrer le système
5. Vérifier le fonctionnement

## Support

Pour toute question ou problème :

1. Vérifier les logs
2. Consulter l'audit
3. Vérifier les kill switches
4. Tester la connectivité BitMart
5. Vérifier Temporal

## Roadmap

### Phase 1 (Actuelle)
- [x] Système MTF de base
- [x] Workflow Temporal
- [x] Rate limiting
- [x] Kill switches
- [x] Audit

### Phase 2
- [ ] WebSocket en temps réel
- [ ] Indicateurs techniques avancés
- [ ] Machine learning
- [ ] Interface web

### Phase 3
- [ ] Multi-exchange
- [ ] Stratégies avancées
- [ ] Gestion des risques
- [ ] Reporting avancé




