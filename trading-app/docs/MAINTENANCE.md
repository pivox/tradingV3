# API de Maintenance - Nettoyage de Base de Données

## Vue d'ensemble

Cette API permet de nettoyer automatiquement les données anciennes de la base de données pour optimiser les performances et l'espace disque.

**Tables concernées :**
- `klines` : Garde les **500 dernières klines** par combinaison (symbol, timeframe)
- `mtf_audit` : Garde les audits des **3 derniers jours**
- `signals` : Garde les signaux des **3 derniers jours**

**Mode Dry-Run :** Par défaut, tous les endpoints fonctionnent en mode prévisualisation (`dry_run: true`) pour éviter les suppressions accidentelles.

---

## Endpoints

### 1. Nettoyage Complet

**URL :** `POST /api/maintenance/cleanup`

**Description :** Nettoie toutes les tables en une seule opération.

**Paramètres (JSON body ou query string) :**
| Paramètre | Type | Défaut | Description |
|-----------|------|--------|-------------|
| `symbol` | string | null | Filtrer par symbole (ex: `BTCUSDT`). Si omis, traite tous les symboles |
| `dry_run` | boolean | `true` | Mode prévisualisation. Mettre à `false` pour exécuter réellement |
| `klines_limit` | int | `500` | Nombre de klines à garder par (symbol, timeframe) |
| `audit_days` | int | `3` | Nombre de jours d'audits MTF à garder |
| `signal_days` | int | `3` | Nombre de jours de signaux à garder |

**Exemples :**

```bash
# Prévisualisation (dry-run) pour tous les symboles
curl -X POST http://localhost:8000/api/maintenance/cleanup \
  -H "Content-Type: application/json" \
  -d '{"dry_run": true}'

# Prévisualisation pour un symbole spécifique
curl -X POST http://localhost:8000/api/maintenance/cleanup \
  -H "Content-Type: application/json" \
  -d '{"symbol": "BTCUSDT", "dry_run": true}'

# Exécution réelle pour tous les symboles
curl -X POST http://localhost:8000/api/maintenance/cleanup \
  -H "Content-Type: application/json" \
  -d '{"dry_run": false}'

# Exécution réelle avec paramètres personnalisés
curl -X POST http://localhost:8000/api/maintenance/cleanup \
  -H "Content-Type: application/json" \
  -d '{
    "symbol": "ETHUSDT",
    "dry_run": false,
    "klines_limit": 1000,
    "audit_days": 7,
    "signal_days": 7
  }'

# Via query string (GET)
curl "http://localhost:8000/api/maintenance/cleanup?dry_run=true&symbol=BTCUSDT"
```

**Réponse (dry-run) :**
```json
{
  "dry_run": true,
  "symbol": "BTCUSDT",
  "timestamp": "2025-11-02 14:30:00",
  "klines": {
    "timeframes": {
      "1m": {
        "total": 1500,
        "to_keep": 500,
        "to_delete": 1000,
        "symbols": [
          {"symbol": "BTCUSDT", "total": 1500, "to_delete": 1000}
        ]
      },
      "5m": {
        "total": 800,
        "to_keep": 500,
        "to_delete": 300,
        "symbols": [
          {"symbol": "BTCUSDT", "total": 800, "to_delete": 300}
        ]
      }
    },
    "total_to_delete": 1300,
    "dry_run": true
  },
  "mtf_audit": {
    "total": 5000,
    "to_delete": 4500,
    "to_keep": 500,
    "cutoff_date": "2025-10-30 14:30:00",
    "symbols_affected": {
      "BTCUSDT": 4500
    },
    "dry_run": true
  },
  "signals": {
    "total": 2000,
    "to_delete": 1800,
    "to_keep": 200,
    "cutoff_date": "2025-10-30 14:30:00",
    "by_timeframe": {
      "1m": 500,
      "5m": 400,
      "15m": 300
    },
    "symbols_affected": {
      "BTCUSDT": 1800
    },
    "dry_run": true
  },
  "summary": {
    "total_to_delete": 7600,
    "klines_to_delete": 1300,
    "mtf_audit_to_delete": 4500,
    "signals_to_delete": 1800,
    "execution_time_ms": 245,
    "has_errors": false
  },
  "errors": []
}
```

---

### 2. Nettoyage Ciblé - Klines

**URL :** `POST /api/maintenance/cleanup/klines`

**Paramètres :**
| Paramètre | Type | Défaut | Description |
|-----------|------|--------|-------------|
| `symbol` | string | null | Filtrer par symbole |
| `dry_run` | boolean | `true` | Mode prévisualisation |
| `keep_limit` | int | `500` | Nombre de klines à garder |

**Exemple :**
```bash
curl -X POST http://localhost:8000/api/maintenance/cleanup/klines \
  -H "Content-Type: application/json" \
  -d '{"symbol": "BTCUSDT", "dry_run": false, "keep_limit": 1000}'
```

---

### 3. Nettoyage Ciblé - Audits MTF

**URL :** `POST /api/maintenance/cleanup/mtf-audit`

**Paramètres :**
| Paramètre | Type | Défaut | Description |
|-----------|------|--------|-------------|
| `symbol` | string | null | Filtrer par symbole |
| `dry_run` | boolean | `true` | Mode prévisualisation |
| `days_keep` | int | `3` | Nombre de jours à garder |

**Exemple :**
```bash
curl -X POST http://localhost:8000/api/maintenance/cleanup/mtf-audit \
  -H "Content-Type: application/json" \
  -d '{"dry_run": false, "days_keep": 7}'
```

---

### 4. Nettoyage Ciblé - Signaux

**URL :** `POST /api/maintenance/cleanup/signals`

**Paramètres :**
| Paramètre | Type | Défaut | Description |
|-----------|------|--------|-------------|
| `symbol` | string | null | Filtrer par symbole |
| `dry_run` | boolean | `true` | Mode prévisualisation |
| `days_keep` | int | `3` | Nombre de jours à garder |

**Exemple :**
```bash
curl -X POST http://localhost:8000/api/maintenance/cleanup/signals \
  -H "Content-Type: application/json" \
  -d '{"symbol": "ETHUSDT", "dry_run": false}'
```

---

### 5. Obtenir les Valeurs par Défaut

**URL :** `GET /api/maintenance/cleanup/defaults`

**Description :** Retourne les valeurs de configuration par défaut.

**Exemple :**
```bash
curl http://localhost:8000/api/maintenance/cleanup/defaults
```

**Réponse :**
```json
{
  "defaults": {
    "klines_keep_limit": 500,
    "mtf_audit_days_keep": 3,
    "signals_days_keep": 3
  },
  "description": {
    "klines_keep_limit": "Nombre de klines à conserver par (symbol, timeframe)",
    "mtf_audit_days_keep": "Nombre de jours d'audits MTF à conserver",
    "signals_days_keep": "Nombre de jours de signaux à conserver"
  }
}
```

---

## Workflow Recommandé

### Étape 1 : Prévisualisation (Dry-Run)

Toujours commencer par un dry-run pour voir ce qui sera supprimé :

```bash
curl -X POST http://localhost:8000/api/maintenance/cleanup \
  -H "Content-Type: application/json" \
  -d '{"dry_run": true}'
```

### Étape 2 : Vérification des Statistiques

Analyser la réponse pour comprendre l'impact :
- `total_to_delete` : nombre total de lignes à supprimer
- `klines.timeframes` : détail par timeframe
- `symbols_affected` : symboles impactés

### Étape 3 : Exécution Réelle

Si le rapport est satisfaisant, exécuter avec `dry_run: false` :

```bash
curl -X POST http://localhost:8000/api/maintenance/cleanup \
  -H "Content-Type: application/json" \
  -d '{"dry_run": false}'
```

---

## Sécurité et Transactions

- **Transactions :** En mode non-dry-run, toutes les opérations sont exécutées dans une transaction. En cas d'erreur, un rollback automatique est effectué.
- **Logs :** Toutes les opérations sont loggées dans les logs Symfony avec le tag `[CleanupProvider]`.
- **Aucune authentification :** L'endpoint est ouvert. Ajoutez une couche de sécurité si nécessaire (firewall, IP whitelist, etc.).

---

## Automatisation

### Option 1 : Workflow Temporal (Recommandé)

Un workflow Temporal est disponible pour automatiser le nettoyage de manière robuste avec retry et monitoring.

**Localisation :** `cron_symfony_mtf_workers/scripts/new/manage_cleanup_schedule.py`

#### Créer le Schedule

```bash
# Mode prévisualisation
cd cron_symfony_mtf_workers
python scripts/new/manage_cleanup_schedule.py create --dry-run

# Créer le schedule (Dimanche 3h par défaut)
python scripts/new/manage_cleanup_schedule.py create
```

#### Configuration via Variables d'Environnement

```bash
# Personnaliser la configuration
export CLEANUP_CRON="0 2 * * *"              # Tous les jours à 2h
export CLEANUP_DRY_RUN="false"               # Exécution réelle
export CLEANUP_KLINES_LIMIT="1000"           # Garder 1000 klines
export CLEANUP_AUDIT_DAYS="7"                # Garder 7 jours d'audits
export CLEANUP_SIGNAL_DAYS="7"               # Garder 7 jours de signaux
export CLEANUP_SYMBOL=""                     # Tous les symboles (vide)
export CLEANUP_TIMEOUT_MINUTES="30"          # Timeout de 30 minutes

python scripts/new/manage_cleanup_schedule.py create
```

#### Commandes Disponibles

```bash
# Créer le schedule
python scripts/new/manage_cleanup_schedule.py create

# Vérifier le statut
python scripts/new/manage_cleanup_schedule.py status

# Mettre en pause
python scripts/new/manage_cleanup_schedule.py pause

# Reprendre
python scripts/new/manage_cleanup_schedule.py resume

# Déclencher immédiatement (une fois, hors planning)
python scripts/new/manage_cleanup_schedule.py trigger

# Supprimer le schedule
python scripts/new/manage_cleanup_schedule.py delete
```

#### Exemples de Configuration

**Nettoyage quotidien à 3h (tous symboles, exécution réelle) :**
```bash
export CLEANUP_CRON="0 3 * * *"
export CLEANUP_DRY_RUN="false"
python scripts/new/manage_cleanup_schedule.py create
```

**Nettoyage hebdomadaire en mode dry-run :**
```bash
export CLEANUP_CRON="0 3 * * 0"   # Dimanche 3h
export CLEANUP_DRY_RUN="true"
python scripts/new/manage_cleanup_schedule.py create
```

**Nettoyage ciblé sur un symbole :**
```bash
export CLEANUP_SYMBOL="BTCUSDT"
export CLEANUP_DRY_RUN="false"
python scripts/new/manage_cleanup_schedule.py create
```

### Option 2 : Cron Classique

Pour automatiser le nettoyage avec cron :

```bash
# Tous les dimanches à 3h du matin
0 3 * * 0 curl -X POST http://localhost:8000/api/maintenance/cleanup -H "Content-Type: application/json" -d '{"dry_run": false}'
```

---

## Dépannage

### Erreur : "Klines cleanup failed"

**Cause possible :** Problème de connexion à la base de données ou requête SQL invalide.

**Solution :**
1. Vérifier les logs Symfony (`var/log/dev.log`)
2. Vérifier que PostgreSQL est accessible
3. Exécuter en mode dry-run pour identifier le problème

### Temps d'exécution trop long

**Cause :** Volume important de données à supprimer.

**Solution :**
- Nettoyer par symbole : `{"symbol": "BTCUSDT", "dry_run": false}`
- Augmenter le timeout de la requête HTTP
- Nettoyer table par table avec les endpoints ciblés

### Transaction rollback

**Cause :** Erreur pendant la suppression (contrainte de clé étrangère, etc.).

**Solution :**
1. Vérifier les contraintes de base de données
2. Vérifier les logs pour identifier l'erreur exacte
3. Contacter l'administrateur si nécessaire

---

## Architecture Technique

### Repositories
- `KlineRepository::cleanupOldKlines()` : Suppression intelligente avec ROW_NUMBER()
- `MtfAuditRepository::cleanupOldAudits()` : Suppression par date avec statistiques
- `SignalRepository::cleanupOldSignals()` : Suppression par date avec QueryBuilder

### Provider
- `CleanupProvider` : Orchestrateur central avec gestion de transactions

### Controller
- `MaintenanceController` : Exposition REST API avec validation des paramètres

---

## Modification des Valeurs par Défaut

Les constantes par défaut sont définies dans `CleanupProvider` :

```php
public const int KLINES_KEEP_LIMIT = 500;
public const int MTF_AUDIT_DAYS_KEEP = 3;
public const int SIGNALS_DAYS_KEEP = 3;
```

Pour modifier les valeurs par défaut, éditez ces constantes dans le fichier :
`src/Provider/CleanupProvider.php`

