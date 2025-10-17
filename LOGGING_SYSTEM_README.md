# Système de Logging Multi-Canaux - Trading App

## 🎯 Vue d'ensemble

Ce système implémente un logging centralisé et performant pour l'application trading-app, avec 7 canaux métier spécialisés et intégration native Grafana/Loki pour la visualisation temps réel.

## 🏗️ Architecture

```
+-----------------------+
|     Symfony App       |---\
|  (Monolog Channels)   |    \
| validation, signals,  |     \
| indicators, etc.      |      \
+-----------------------+       \
                                  \
                                   ---> Promtail ---> Loki ---> Grafana
                                  /
+-----------------------+        /
| Symfony Worker Logs   |<-----/
| (async Messenger opt.)|
+-----------------------+
```

## 📋 Canaux de Logging

| Canal | Fichier | Objectif | Rétention |
|-------|---------|----------|-----------|
| `validation` | `validation.log` | Validation des règles MTF et conditions YAML | 14 jours |
| `signals` | `signals.log` | Détails des signaux longs/shorts | 14 jours |
| `positions` | `positions.log` | Suivi des positions ouvertes/SL/TP | 14 jours |
| `indicators` | `indicators.log` | Calculs d'indicateurs techniques | 14 jours |
| `highconviction` | `highconviction.log` | Stratégies High Conviction validées | 30 jours |
| `pipeline_exec` | `pipeline-exec.log` | Journalisation du moteur d'exécution | 30 jours |
| `deprecation` | `stderr` | Messages de dépréciation internes | - |
| `global-severity` | `global-severity.log` | Consolidation d'erreurs ≥ notice | 30 jours |

## 🚀 Démarrage Rapide

### 1. Démarrage de la stack complète

```bash
# Démarrage automatique avec le script
./scripts/start-logging-stack.sh

# Ou démarrage manuel
docker-compose up -d loki promtail grafana
```

### 2. Accès aux interfaces

- **Grafana Dashboard**: http://localhost:3000 (admin/admin)
- **Loki API**: http://localhost:3100
- **Trading App**: http://localhost:8082

### 3. Test du système

```bash
cd trading-app
php bin/console app:test-logging --count=5
```

## 💻 Utilisation en Code

### Injection du LoggerHelper

```php
use App\Logging\LoggerHelper;

class YourService
{
    public function __construct(
        private LoggerHelper $logger
    ) {}
}
```

### Exemples d'utilisation

#### Logging de validation
```php
$this->logger->validation('MTF rule validation started', [
    'rule_id' => 'mtf_001',
    'symbol' => 'BTCUSDT',
    'timeframe' => '15m',
    'conditions' => ['macd_bullish' => true]
]);
```

#### Logging de signaux
```php
$this->logger->logSignal(
    'BTCUSDT',
    '15m',
    'long',
    'MACD bullish crossover confirmed',
    ['macd' => ['hist' => 0.0009], 'rsi' => 54.2]
);
```

#### Logging de positions
```php
$this->logger->logPosition(
    'BTCUSDT',
    'open',
    'long',
    [
        'entry_price' => 43250.50,
        'quantity' => 0.1,
        'stop_loss' => 42500.0,
        'take_profit' => 44500.0
    ]
);
```

#### Logging d'indicateurs
```php
$this->logger->logIndicator(
    'BTCUSDT',
    '15m',
    'MACD',
    [
        'macd_line' => 0.0009,
        'signal_line' => 0.0005,
        'histogram' => 0.0004
    ],
    'MACD calculated with bullish crossover'
);
```

## 📊 Format des Logs

### Structure standardisée
```
[2025-10-14 11:04:33.472][signals.INFO]: [BTCUSDT][15m][long] MACD bullish crossover confirmed {"macd":{"hist":0.0009},"rsi":54.2}
```

### Composants
- **Timestamp**: Format ISO précis `Y-m-d H:i:s.v`
- **Canal**: `validation`, `signals`, `positions`, etc.
- **Niveau**: `debug`, `info`, `warning`, `error`
- **Métadonnées**: `[symbol][timeframe][side]`
- **Message**: Description de l'événement
- **Contexte**: JSON encodé compact

## 🔍 Requêtes Grafana

### Logs par canal
```logql
{job="symfony", channel="signals"}
```

### Signaux par symbole
```logql
{job="symfony", channel="signals", symbol="BTCUSDT"}
```

### Erreurs récentes
```logql
{job="symfony", level="error"} |= "error"
```

### Positions ouvertes
```logql
{job="symfony", channel="positions"} |= "open"
```

### Indicateurs MACD
```logql
{job="symfony", channel="indicators"} |= "MACD"
```

## ⚙️ Configuration

### Variables d'environnement

```bash
# Niveaux de log par canal
LOG_LEVEL_VALIDATION=info
LOG_LEVEL_SIGNALS=info
LOG_LEVEL_POSITIONS=info
LOG_LEVEL_INDICATORS=info
LOG_LEVEL_HIGHCONVICTION=info
LOG_LEVEL_PIPELINE_EXEC=info
LOG_LEVEL_MAIN=info
```

### Rotation des fichiers

- **Canaux fréquents** (validation, signals, indicators, positions): 14 fichiers
- **Canaux critiques** (highconviction, pipeline_exec, global-severity): 30 fichiers

## 🛠️ Maintenance

### Commandes utiles

```bash
# Voir les logs en temps réel
docker-compose logs -f loki
docker-compose logs -f promtail
docker-compose logs -f grafana

# Redémarrer un service
docker-compose restart loki

# Voir l'état des services
docker-compose ps

# Nettoyer les volumes
docker-compose down -v
```

### Surveillance

- **Loki**: http://localhost:3100/ready
- **Grafana**: http://localhost:3000/api/health
- **Promtail**: http://localhost:9080/targets

## 🔒 Sécurité

### Données sensibles masquées
- `api_key`, `secret`, `password`, `token`, `memo`, `credentials`
- Remplacement automatique par `***MASKED***`

### Accès
- Grafana authentifié (admin/admin)
- Promtail en lecture seule
- Logs stockés localement

## 📈 Performance

### Optimisations
- **Bufferisation**: Écriture groupée des logs
- **Rotation automatique**: Suppression des anciens fichiers
- **Format JSON compact**: Réduction de la taille
- **Parsing Promtail**: Extraction des labels pour l'indexation

### Métriques
- **Débit d'ingestion**: 16 MB/s
- **Burst**: 32 MB
- **Rétention**: 30 jours
- **Chunks**: 5 MB max

## 🚨 Alertes Grafana

### Alertes configurées
- **10 erreurs en 5 minutes** → Alerte critique
- **Warnings massifs** → Alerte warning
- **Absence de logs** → Alerte de disponibilité

### Configuration des alertes
```yaml
# Dans Grafana > Alerting > Alert Rules
- alert: HighErrorRate
  expr: sum(rate({job="symfony", level="error"}[5m])) > 10
  for: 5m
  labels:
    severity: critical
  annotations:
    summary: "High error rate detected"
```

## 🔧 Dépannage

### Problèmes courants

#### Promtail ne collecte pas les logs
```bash
# Vérifier la configuration
docker-compose exec promtail cat /etc/promtail/config.yml

# Vérifier les permissions
ls -la trading-app/var/log/
```

#### Grafana ne trouve pas Loki
```bash
# Vérifier la connectivité
docker-compose exec grafana wget -qO- http://loki:3100/ready
```

#### Logs non formatés
```bash
# Vérifier le formateur personnalisé
docker-compose exec trading-app-php php bin/console debug:container App\\Logging\\CustomLineFormatter
```

### Logs de débogage

```bash
# Logs Promtail
docker-compose logs promtail | grep -i error

# Logs Loki
docker-compose logs loki | grep -i error

# Logs Grafana
docker-compose logs grafana | grep -i error
```

## 📚 Ressources

- [Documentation Monolog](https://github.com/Seldaek/monolog)
- [Documentation Loki](https://grafana.com/docs/loki/)
- [Documentation Promtail](https://grafana.com/docs/loki/latest/clients/promtail/)
- [Documentation Grafana](https://grafana.com/docs/grafana/)

## 🤝 Contribution

Pour ajouter un nouveau canal de logging :

1. Ajouter le canal dans `config/packages/monolog.yaml`
2. Créer le handler correspondant
3. Ajouter la méthode dans `LoggerHelper`
4. Mettre à jour la configuration Promtail
5. Ajouter les panels Grafana si nécessaire
6. Documenter l'utilisation

---

**Système implémenté selon les spécifications du cahier de charges - Trading App v3**
