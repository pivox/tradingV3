# 📊 Position Logging System

## Vue d'ensemble

Le système de **Position Logging** suit le parcours complet d'ouverture de position depuis la validation 1m jusqu'à l'ouverture effective. Il fournit une traçabilité complète et détaillée de chaque étape du processus Post-Validation.

## 🎯 Objectifs

- **Traçabilité complète** : Suivre chaque étape depuis la validation MTF jusqu'à l'ouverture effective
- **Debugging facilité** : Identifier rapidement les points de blocage ou d'échec
- **Audit et conformité** : Conserver un historique détaillé des décisions et actions
- **Performance monitoring** : Analyser les temps d'exécution et les métriques
- **Alertes et monitoring** : Détecter les anomalies et problèmes

## 🔄 Parcours de Logging

### 1. **Validation 1m (Signal Validé)**
```
[POST-VALIDATION START] === POST-VALIDATION START ===
- Symbol, side, MTF context, wallet equity
- Timestamp de début du processus
```

### 2. **Récupération Données Marché**
```
[MARKET_DATA] Retrieved
- Last price, bid/ask, spread, depth
- VWAP, ATR(1m), ATR(5m)
- Statut stale, âge des données
```

### 3. **Sélection Timeframe d'Exécution**
```
[TIMEFRAME_SELECTION] Selected
- Timeframe sélectionné (1m ou 5m)
- Critères de sélection (ATR, spread, depth)
- Justification de la décision
```

### 4. **Calcul Zone d'Entrée**
```
[ENTRY_ZONE] Calculated
- Entry min/max, zone width, mid price
- VWAP anchor, ATR value
- Quality filters, evidence
```

### 5. **Création Plan d'Ordres**
```
[ORDER_PLAN] Created
- Quantity, leverage, notional
- Entry price, risk amount
- Stop loss, take profit
- Maker/fallback/TP-SL orders count
```

### 6. **Exécution Garde-fous**
```
[GUARDS] Execution completed
- All passed, failed count
- Détail de chaque garde-fou
- Raisons d'échec si applicable
```

### 7. **Machine d'États**
```
[STATE_MACHINE] Starting sequence
[STATE_MACHINE] Transition
- États et transitions
- Actions exécutées
- Données de contexte
```

### 8. **Soumission Ordres**
```
[MAKER_ORDER] Submitted
[MAKER_ORDER] Waiting for fill
[MAKER_ORDER] Fill result
- Client order ID, response
- Status, filled qty, avg price
```

### 9. **Fallback Taker (si nécessaire)**
```
[TAKER_ORDER] Submitted (fallback)
- Ordre taker en cas de timeout maker
- Slippage et prix d'exécution
```

### 10. **Attachement TP/SL**
```
[TP_SL] Attached
- Résultats de chaque ordre TP/SL
- Status de soumission
```

### 11. **Ouverture Effective Position**
```
[POSITION] Opened successfully
- Symbol, side, quantity, leverage
- Entry price, notional
- Données de position complètes
```

### 12. **Démarrage Monitoring**
```
[MONITORING] Started
- Données de monitoring
- Configuration de suivi
```

### 13. **Décision Finale**
```
[FINAL_DECISION] Post-validation completed
- Decision (OPEN/SKIP)
- Raison, evidence complète
- Métriques de performance
```

## 📁 Structure des Logs

### Fichiers de Log

```
var/log/
├── positions.log          # Logs spécifiques aux positions
├── post_validation.log    # Logs Post-Validation généraux
├── mtf_dev.log           # Logs MTF
└── dev.log               # Logs généraux
```

### Format des Logs

```json
{
  "timestamp": "2024-01-15T14:30:25+00:00",
  "level": "INFO",
  "channel": "positions",
  "message": "[ENTRY_ZONE] Calculated",
  "context": {
    "symbol": "BTCUSDT",
    "side": "LONG",
    "entry_min": 43250.0,
    "entry_max": 43280.0,
    "zone_width_bps": 6.9,
    "quality_passed": true
  }
}
```

## 🛠️ Configuration

### Monolog Configuration

```yaml
# config/packages/monolog.yaml
monolog:
  channels: ['positions', 'post_validation']
  handlers:
    positions_rotating:
      type: rotating_file
      path: '%kernel.project_dir%/var/log/positions.log'
      max_files: 14
      level: info
      channels: ['positions']
    post_validation_rotating:
      type: rotating_file
      path: '%kernel.project_dir%/var/log/post_validation.log'
      max_files: 14
      level: info
      channels: ['post_validation']
```

### Services Configuration

```yaml
# config/services.yaml
App\Logging\PositionLogger:
  arguments:
    $positionsLogger: '@monolog.logger.positions'
    $postValidationLogger: '@monolog.logger.post_validation'
```

## 🚀 Utilisation

### 1. Test du Système de Logging

```bash
# Test complet du logging
./scripts/test-position-logging.sh

# Afficher les logs récents
./scripts/test-position-logging.sh show

# Analyser les performances
./scripts/test-position-logging.sh analyze

# Surveiller en temps réel
./scripts/test-position-logging.sh monitor
```

### 2. API Post-Validation

```bash
# Exécuter Post-Validation avec logging
curl -X POST http://localhost:8082/api/post-validation/execute \
  -H "Content-Type: application/json" \
  -d '{
    "symbol": "BTCUSDT",
    "side": "LONG",
    "mtf_context": {
      "5m": {"signal_side": "LONG", "status": "valid"},
      "15m": {"signal_side": "LONG", "status": "valid"},
      "candle_close_ts": 1704067200,
      "conviction_flag": false
    },
    "wallet_equity": 1000.0,
    "dry_run": true
  }'
```

### 3. Surveillance des Logs

```bash
# Logs en temps réel
tail -f var/log/positions.log

# Filtrer par symbole
tail -f var/log/positions.log | grep "BTCUSDT"

# Filtrer par étape
tail -f var/log/positions.log | grep "ENTRY_ZONE"

# Filtrer les erreurs
tail -f var/log/positions.log | grep "ERROR"
```

## 📊 Métriques et Analytics

### Métriques de Performance

- **Temps d'exécution** par étape
- **Taux de succès** par symbole/timeframe
- **Fréquence des échecs** par garde-fou
- **Slippage moyen** par type d'ordre
- **Latence** des appels API

### Analytics par Symbole

```bash
# Statistiques par symbole
grep "symbol.*BTCUSDT" var/log/positions.log | wc -l
grep "symbol.*ETHUSDT" var/log/positions.log | wc -l

# Étapes les plus fréquentes
grep -o "\[[A-Z_]*\]" var/log/positions.log | sort | uniq -c | sort -nr
```

### Détection d'Anomalies

```bash
# Erreurs récentes
grep "ERROR" var/log/positions.log | tail -10

# Warnings récents
grep "WARNING" var/log/positions.log | tail -10

# Échecs de garde-fous
grep "GUARDS.*failed" var/log/positions.log | tail -10
```

## 🔍 Debugging et Troubleshooting

### 1. **Problème : Pas de logs générés**

```bash
# Vérifier la configuration Monolog
php bin/console debug:container monolog.logger.positions

# Vérifier les permissions
ls -la var/log/positions.log

# Tester le logger
php bin/console app:test-position-logging
```

### 2. **Problème : Logs incomplets**

```bash
# Vérifier les étapes manquantes
./scripts/test-position-logging.sh analyze

# Comparer avec un test de référence
./scripts/test-position-logging.sh test
```

### 3. **Problème : Performance dégradée**

```bash
# Analyser la fréquence des logs
grep -c "INFO" var/log/positions.log

# Vérifier la taille des fichiers
du -h var/log/positions.log*

# Nettoyer les anciens logs
./scripts/test-position-logging.sh clean
```

## 📈 Monitoring et Alertes

### 1. **Alertes Critiques**

- **Erreurs de soumission d'ordres**
- **Échecs de garde-fous répétés**
- **Timeouts de fill fréquents**
- **Slippage excessif**

### 2. **Métriques de Santé**

- **Taux de succès global**
- **Temps d'exécution moyen**
- **Fréquence des erreurs**
- **Utilisation des fallbacks**

### 3. **Dashboards**

```bash
# Créer un dashboard simple
echo "=== Position Logging Dashboard ==="
echo "Total logs: $(wc -l < var/log/positions.log)"
echo "Errors: $(grep -c ERROR var/log/positions.log)"
echo "Success rate: $(grep -c "FINAL_DECISION.*OPEN" var/log/positions.log)"
echo "Last activity: $(tail -1 var/log/positions.log | cut -d' ' -f1-2)"
```

## 🔒 Sécurité et Conformité

### 1. **Données Sensibles**

- **Pas de clés API** dans les logs
- **Prix et quantités** masqués si nécessaire
- **Wallet equity** anonymisé
- **Client order IDs** hashés

### 2. **Rétention des Logs**

- **Rotation automatique** (14 jours)
- **Compression** des anciens logs
- **Archivage** pour audit
- **Suppression sécurisée**

### 3. **Audit Trail**

- **Traçabilité complète** des décisions
- **Evidence** conservée pour chaque étape
- **Timestamps** précis
- **Corrélation** avec les ordres exchange

## 🚀 Évolutions Futures

### 1. **Intégrations**

- **ELK Stack** (Elasticsearch, Logstash, Kibana)
- **Prometheus** pour métriques
- **Grafana** pour dashboards
- **AlertManager** pour notifications

### 2. **Fonctionnalités Avancées**

- **Machine Learning** pour détection d'anomalies
- **Corrélation** avec les données de marché
- **Prédiction** des échecs
- **Optimisation** automatique des paramètres

### 3. **API de Logging**

- **Endpoint REST** pour requêtes de logs
- **Filtrage** par symbole, timeframe, status
- **Export** en JSON/CSV
- **Webhooks** pour intégrations externes

---

**Note** : Le système de Position Logging est conçu pour être performant, sécurisé et évolutif. Il fournit une visibilité complète sur le processus d'ouverture de positions tout en respectant les contraintes de performance et de sécurité.

