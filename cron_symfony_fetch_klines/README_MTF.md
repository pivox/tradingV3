# Workflow MTF - Documentation

## Description

Le workflow MTF (Multi-Timeframe) est un nouveau workflow Temporal qui exécute l'endpoint `/api/mtf/run` de l'application trading-app toutes les minutes.

## Configuration

- **Schedule ID**: `cron-symfony-mtf-1m`
- **Workflow Type**: `CronSymfonyMtfWorkflow`
- **Fréquence**: Toutes les minutes (`*/1 * * * *`)
- **URL cible**: `http://trading-app-nginx:80/api/mtf/run`
- **Timeout**: 5 minutes (300 secondes)

## Fichiers créés

### Workflow
- `workflows/cron_symfony_mtf.py` - Définition du workflow MTF

### Scripts de gestion
- `scripts/new/create_mtf_schedule.py` - Créer la schedule
- `scripts/new/pause_mtf_schedule.py` - Mettre en pause
- `scripts/new/resume_mtf_schedule.py` - Reprendre
- `scripts/new/delete_mtf_schedule.py` - Supprimer
- `scripts/new/manage_mtf_schedule.py` - Gestionnaire global
- `scripts/new/mtf.sh` - Script shell pour faciliter l'utilisation

### Modifications
- `activities/symfony_http.py` - Ajout du timeout de 5 minutes pour les URLs contenant "mtf"
- `worker.py` - Ajout du workflow MTF au worker

## Utilisation

### Via le script shell (recommandé)

```bash
# Créer la schedule
./scripts/new/mtf.sh create

# Mettre en pause
./scripts/new/mtf.sh pause

# Reprendre
./scripts/new/mtf.sh resume

# Supprimer
./scripts/new/mtf.sh delete

# Voir le statut
./scripts/new/mtf.sh status

# Mode dry-run (voir ce qui serait fait sans exécuter)
./scripts/new/mtf.sh create --dry-run
```

### Via les scripts Python individuels

```bash
# Créer la schedule
python3 scripts/new/create_mtf_schedule.py

# Mettre en pause
python3 scripts/new/pause_mtf_schedule.py

# Reprendre
python3 scripts/new/resume_mtf_schedule.py

# Supprimer
python3 scripts/new/delete_mtf_schedule.py

# Gestionnaire global
python3 scripts/new/manage_mtf_schedule.py create
python3 scripts/new/manage_mtf_schedule.py pause
python3 scripts/new/manage_mtf_schedule.py resume
python3 scripts/new/manage_mtf_schedule.py delete
python3 scripts/new/manage_mtf_schedule.py status
```

## Variables d'environnement

Les scripts utilisent les variables d'environnement suivantes :

- `TEMPORAL_ADDRESS` (défaut: `temporal:7233`)
- `TEMPORAL_NAMESPACE` (défaut: `default`)
- `TASK_QUEUE_NAME` (défaut: `cron_symfony_refresh`)
- `TZ` (défaut: `Africa/Tunis`)

## Architecture réseau

Le workflow utilise l'URL `http://trading-app-nginx:80/api/mtf/run` qui correspond au service `trading-app-nginx` dans le docker-compose, accessible sur le port 8082 depuis l'extérieur.

## Timeout

L'endpoint MTF peut prendre beaucoup de temps à s'exécuter, c'est pourquoi :
- Le timeout de l'activité est configuré à 5 minutes (300 secondes)
- L'activité `call_symfony_endpoint` détecte automatiquement les URLs contenant "mtf" et applique le timeout approprié

## Monitoring

Vous pouvez surveiller l'exécution du workflow via :
- L'interface Temporal UI (http://localhost:8233)
- Les logs du worker `cron-symfony-fetch-klines-worker`
- Les logs de l'application trading-app
