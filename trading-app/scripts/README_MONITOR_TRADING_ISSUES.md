# Script de Surveillance des Problèmes de Trading

## Description

Le script `monitor_trading_issues.php` surveille automatiquement les logs pour détecter les problèmes similaires à ceux identifiés :

- **Positions qui touchent le SL rapidement** (< 5 minutes)
- **Ordres multiples sur le même symbole** (< 2 minutes)
- **Stop-loss trop serrés** (< 0.3% de distance)
- **Patterns de pertes répétées**

## Utilisation

### Mode Analyse (une fois)

Analyser les logs des dernières 24 heures :

```bash
php scripts/monitor_trading_issues.php
```

Analyser les logs des dernières 6 heures :

```bash
php scripts/monitor_trading_issues.php --last-hours=6
```

Spécifier un répertoire de logs différent :

```bash
php scripts/monitor_trading_issues.php --log-dir=/var/log/trading-app
```

### Mode Surveillance (temps réel)

Surveiller les logs en temps réel :

```bash
php scripts/monitor_trading_issues.php --watch
```

Le script affichera immédiatement les problèmes détectés au fur et à mesure qu'ils apparaissent dans les logs.

## Problèmes Détectés

### 1. SL Rapide (`rapid_sl_hit`)
- **Critère** : Position fermée en moins de 5 minutes avec perte
- **Sévérité** : CRITICAL
- **Exemple** : `🚨 SL RAPIDE: ZENUSDT fermé en 00:02:15 avec perte de -1.41 USDT`

### 2. Ordres Multiples (`multiple_orders`)
- **Critère** : 2+ ordres sur le même symbole dans les 2 minutes
- **Sévérité** : HIGH
- **Exemple** : `⚠️ ORDRES MULTIPLES: ZENUSDT a 3 ordres dans les 120s`

### 3. SL Trop Serré (`tight_stop_loss`)
- **Critère** : Distance SL < 0.3% du prix d'entrée
- **Sévérité** : HIGH
- **Exemple** : `⚠️ SL TROP SERRÉ: ZENUSDT SL à 0.15% (seuil: 0.3%)`

## Intégration dans le Système

### Utiliser comme Commande Symfony

Ajouter dans `src/Command/Monitor/` :

```php
<?php
namespace App\Command\Monitor;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MonitorTradingIssuesCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scriptPath = __DIR__ . '/../../../scripts/monitor_trading_issues.php';
        passthru("php {$scriptPath}", $exitCode);
        return $exitCode;
    }
}
```

### Utiliser avec Cron

Surveiller toutes les heures :

```cron
0 * * * * cd /path/to/trading-app && php scripts/monitor_trading_issues.php --last-hours=1 >> /var/log/monitor-trading-issues.log 2>&1
```

### Utiliser comme Service Systemd

Créer `/etc/systemd/system/trading-issues-monitor.service` :

```ini
[Unit]
Description=Trading Issues Monitor
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/path/to/trading-app
ExecStart=/usr/bin/php /path/to/trading-app/scripts/monitor_trading_issues.php --watch
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

## Fichiers de Logs Surveillés

- `var/log/positions.log` - Positions ouvertes/fermées
- `var/log/positions-flow.log` - Flux de positions
- `var/log/order.log` - Ordres soumis
- `var/log/order-journey.log` - Parcours complet des ordres

## Configuration

Les seuils peuvent être modifiés dans le script :

```php
$thresholdSlDistance = 0.003;      // 0.3% minimum pour SL
$thresholdRapidClose = 300;        // 5 minutes en secondes
$thresholdMultipleOrders = 120;   // 2 minutes en secondes
```

## Sortie

### Mode Analyse

Le script affiche :
- Liste des problèmes groupés par type
- Détails pour chaque problème (symbole, timestamp, log source)
- Résumé total

### Mode Surveillance

Le script affiche immédiatement chaque problème détecté :
```
[2025-01-15 22:01:35] 🚨 SL RAPIDE: ZENUSDT fermé en 00:02:15 avec perte de -1.41 USDT
[2025-01-15 22:01:18] ⚠️ ORDRES MULTIPLES: ICPUSDT a 2 ordres dans les 120s
```

## Codes de Sortie

- `0` : Aucun problème détecté (mode analyse uniquement)
- `1` : Problèmes détectés
- `>1` : Erreur d'exécution

## Exemple de Sortie

```
🔍 Analyse des logs des dernières 24h...

⚠️  PROBLÈMES DÉTECTÉS:
================================================================================

🚨 rapid_sl_hit: 8 occurrence(s)
--------------------------------------------------------------------------------
  • 🚨 SL RAPIDE: ZENUSDT fermé en 00:02:15 avec perte de -1.41 USDT
    Timestamp: 2025-01-15 22:01:35
    Log: positions.log

  • 🚨 SL RAPIDE: ICPUSDT fermé en 00:03:42 avec perte de -1.56 USDT
    Timestamp: 2025-01-15 22:01:18
    Log: positions.log

⚠️ multiple_orders: 3 occurrence(s)
--------------------------------------------------------------------------------
  • ⚠️ ORDRES MULTIPLES: ZENUSDT a 3 ordres dans les 120s
    Timestamp: 2025-01-15 22:01:35
    Log: order-journey.log

================================================================================
Total: 11 problème(s) détecté(s)
```

## Détection Automatique

Le script détecte automatiquement :
- Patterns de logs correspondant aux problèmes connus
- Répétitions dans le temps
- Agrégations par symbole

Il peut être utilisé comme **première ligne de défense** pour détecter les problèmes avant qu'ils ne causent trop de pertes.

