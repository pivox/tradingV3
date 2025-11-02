# Temporal Workflow Schedules

Ce répertoire contient les scripts Python pour gérer les workflows Temporal automatisés.

## Workflows Disponibles

### 1. Contract Sync (Synchronisation des Contrats)

**Script :** `manage_contract_sync_schedule.py`

**Description :** Synchronise quotidiennement les contrats depuis l'exchange BitMart.

**Schedule par défaut :** Tous les jours à 9h UTC (`0 9 * * *`)

**Endpoint appelé :** `POST /api/mtf/sync-contracts`

**Utilisation :**
```bash
# Créer le schedule
python manage_contract_sync_schedule.py create

# Vérifier le statut
python manage_contract_sync_schedule.py status

# Pause/Resume/Delete
python manage_contract_sync_schedule.py pause
python manage_contract_sync_schedule.py resume
python manage_contract_sync_schedule.py delete
```

---

### 2. MTF Workers (Validation Multi-Timeframe)

**Script :** `manage_mtf_workers_schedule.py`

**Description :** Exécute la validation MTF (Multi-TimeFrame) à intervalles réguliers.

**Schedule par défaut :** Toutes les minutes (`*/1 * * * *`)

**Endpoint appelé :** `POST /api/mtf/run`

**Variables d'environnement :**
- `MTF_WORKERS_SCHEDULE_ID` : ID du schedule (défaut: `cron-symfony-mtf-workers-1m`)
- `MTF_WORKERS_WORKFLOW_ID` : ID du workflow (défaut: `cron-symfony-mtf-workers-runner`)
- `MTF_WORKERS_CRON` : Expression cron (défaut: `*/1 * * * *`)
- `MTF_WORKERS_URL` : URL de l'endpoint (défaut: `http://trading-app-nginx:80/api/mtf/run`)
- `MTF_WORKERS_COUNT` : Nombre de workers (défaut: `5`)
- `MTF_WORKERS_DRY_RUN` : Mode dry-run (défaut: `true`)

**Utilisation :**
```bash
# Créer avec configuration personnalisée
export MTF_WORKERS_CRON="*/5 * * * *"
export MTF_WORKERS_DRY_RUN="false"
python manage_mtf_workers_schedule.py create

# Vérifier le statut
python manage_mtf_workers_schedule.py status
```

---

### 3. Database Cleanup (Nettoyage de Base de Données) 🆕

**Script :** `manage_cleanup_schedule.py`

**Description :** Nettoie automatiquement les anciennes données de la base pour optimiser les performances et l'espace disque.

**Tables nettoyées :**
- `klines` : Garde les 500 dernières klines par (symbol, timeframe)
- `mtf_audit` : Garde les audits des 3 derniers jours
- `signals` : Garde les signaux des 3 derniers jours

**Schedule par défaut :** Tous les dimanches à 3h UTC (`0 3 * * 0`)

**Endpoint appelé :** `POST /api/maintenance/cleanup`

**Variables d'environnement :**

| Variable | Défaut | Description |
|----------|--------|-------------|
| `CLEANUP_SCHEDULE_ID` | `cron-db-cleanup-weekly` | ID du schedule Temporal |
| `CLEANUP_WORKFLOW_ID` | `db-cleanup-runner` | ID du workflow |
| `CLEANUP_CRON` | `0 3 * * 0` | Expression cron (Dimanche 3h) |
| `CLEANUP_URL` | `http://trading-app-nginx:80/api/maintenance/cleanup` | URL de l'endpoint |
| `CLEANUP_DRY_RUN` | `false` | Mode prévisualisation (true/false) |
| `CLEANUP_SYMBOL` | _(vide)_ | Filtrer par symbole (vide = tous) |
| `CLEANUP_KLINES_LIMIT` | `500` | Klines à garder par (symbol, tf) |
| `CLEANUP_AUDIT_DAYS` | `3` | Jours d'audits MTF à garder |
| `CLEANUP_SIGNAL_DAYS` | `3` | Jours de signaux à garder |
| `CLEANUP_TIMEOUT_MINUTES` | `30` | Timeout de la requête HTTP |

**Utilisation :**

```bash
# 1. Prévisualisation avant création
python manage_cleanup_schedule.py create --dry-run

# 2. Créer le schedule avec config par défaut (Dimanche 3h, dry_run=false)
python manage_cleanup_schedule.py create

# 3. Créer avec configuration personnalisée
export CLEANUP_CRON="0 2 * * *"              # Tous les jours à 2h
export CLEANUP_DRY_RUN="false"               # Exécution réelle
export CLEANUP_KLINES_LIMIT="1000"           # Garder 1000 klines
export CLEANUP_AUDIT_DAYS="7"                # Garder 7 jours
python manage_cleanup_schedule.py create

# 4. Vérifier le statut
python manage_cleanup_schedule.py status

# 5. Déclencher immédiatement (hors planning)
python manage_cleanup_schedule.py trigger

# 6. Mettre en pause
python manage_cleanup_schedule.py pause

# 7. Reprendre
python manage_cleanup_schedule.py resume

# 8. Supprimer le schedule
python manage_cleanup_schedule.py delete
```

**Exemples de configuration :**

```bash
# Nettoyage quotidien à 3h (tous symboles)
export CLEANUP_CRON="0 3 * * *"
export CLEANUP_DRY_RUN="false"
python manage_cleanup_schedule.py create

# Nettoyage hebdomadaire en mode dry-run (test)
export CLEANUP_CRON="0 3 * * 0"   # Dimanche 3h
export CLEANUP_DRY_RUN="true"
python manage_cleanup_schedule.py create

# Nettoyage ciblé sur BTCUSDT uniquement
export CLEANUP_SYMBOL="BTCUSDT"
export CLEANUP_DRY_RUN="false"
python manage_cleanup_schedule.py create

# Nettoyage conservant plus de données
export CLEANUP_KLINES_LIMIT="2000"
export CLEANUP_AUDIT_DAYS="14"
export CLEANUP_SIGNAL_DAYS="14"
python manage_cleanup_schedule.py create
```

---

## Architecture Commune

Tous les scripts suivent la même structure :

1. **Configuration via environnement** : Variables d'environnement pour tous les paramètres
2. **Support Temporal moderne et legacy** : Compatible avec SDK Temporal Python >= 1.0
3. **Commandes standardisées** : `create`, `pause`, `resume`, `delete`, `status`, (`trigger` pour cleanup)
4. **Dry-run** : Prévisualisation avant création du schedule
5. **Overlap policy** : `BUFFER_ONE` pour éviter les exécutions concurrentes

## Prérequis

- Python 3.8+
- Temporalio SDK : `pip install temporalio`
- Temporal Server accessible (défaut: `temporal-grpc:7233`)

## Configuration Globale

Variables d'environnement communes à tous les workflows :

- `TEMPORAL_ADDRESS` : Adresse du serveur Temporal (défaut: `temporal-grpc:7233`)
- `TEMPORAL_NAMESPACE` : Namespace Temporal (défaut: `default`)
- `TASK_QUEUE_NAME` : Nom de la task queue (défaut: `cron_symfony_mtf_workers`)
- `TZ` : Timezone (défaut: `UTC`)

## Monitoring

Pour surveiller les schedules via Temporal UI :

1. Accéder à l'interface Temporal : `http://localhost:8080` (ou votre URL Temporal UI)
2. Onglet "Schedules"
3. Rechercher par ID : `cron-contract-sync-daily-9am`, `cron-symfony-mtf-workers-1m`, `cron-db-cleanup-weekly`

## Troubleshooting

### Erreur : "schedule already exists"

**Solution :**
```bash
# Vérifier le statut
python manage_<workflow>_schedule.py status

# Supprimer et recréer
python manage_<workflow>_schedule.py delete
python manage_<workflow>_schedule.py create
```

### Erreur : "Connection refused" (Temporal)

**Cause :** Temporal Server inaccessible

**Solution :**
1. Vérifier que Temporal est démarré : `docker-compose ps temporal`
2. Vérifier la variable `TEMPORAL_ADDRESS`

### Schedule ne s'exécute pas

**Diagnostic :**
```bash
# 1. Vérifier le statut
python manage_<workflow>_schedule.py status

# 2. Vérifier si en pause
# Si "paused: true" → reprendre
python manage_<workflow>_schedule.py resume

# 3. Déclencher manuellement pour tester
python manage_cleanup_schedule.py trigger  # (uniquement pour cleanup)
```

---

## Bonnes Pratiques

1. **Toujours tester en dry-run d'abord**
   ```bash
   python manage_cleanup_schedule.py create --dry-run
   ```

2. **Utiliser des variables d'environnement pour la configuration**
   ```bash
   export CLEANUP_DRY_RUN="true"
   python manage_cleanup_schedule.py create
   ```

3. **Monitorer via Temporal UI** après création

4. **Documenter les modifications de configuration** dans un fichier `.env` ou documentation

5. **Tester les endpoints manuellement** avant de créer le schedule
   ```bash
   curl -X POST http://localhost:8000/api/maintenance/cleanup \
     -H "Content-Type: application/json" \
     -d '{"dry_run": true}'
   ```

---

## Maintenance

### Mise à jour d'un Schedule

Pour modifier la configuration d'un schedule existant :

```bash
# 1. Supprimer l'ancien
python manage_cleanup_schedule.py delete

# 2. Modifier les variables d'environnement
export CLEANUP_CRON="0 4 * * *"  # Nouvelle heure

# 3. Recréer
python manage_cleanup_schedule.py create
```

### Logs

Les logs des workflows sont disponibles :
- **Temporal UI** : Logs détaillés de chaque exécution
- **Application Symfony** : Logs dans `var/log/` avec tag `[CleanupProvider]`

---

Pour plus d'informations sur l'API de nettoyage, consultez : `trading-app/docs/MAINTENANCE.md`

