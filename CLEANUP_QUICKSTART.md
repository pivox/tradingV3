# 🧹 Database Cleanup - Guide de Démarrage Rapide

## Vue d'ensemble

Système complet de nettoyage automatique de la base de données avec :
- ✅ API REST (`/api/maintenance/cleanup`)
- ✅ Workflow Temporal automatisé
- ✅ Mode dry-run par défaut (sécurité)
- ✅ Statistiques détaillées

## 🚀 Démarrage en 3 étapes

### Étape 1 : Tester l'API manuellement (Dry-Run)

```bash
curl -X POST http://localhost:8000/api/maintenance/cleanup \
  -H "Content-Type: application/json" \
  -d '{"dry_run": true}'
```

**Résultat attendu :** JSON avec statistiques détaillées sans suppression réelle.

---

### Étape 2 : Vérifier les statistiques

Analysez la réponse pour comprendre ce qui sera supprimé :

```json
{
  "dry_run": true,
  "klines": {
    "total_to_delete": 1300
  },
  "mtf_audit": {
    "to_delete": 4500
  },
  "signals": {
    "to_delete": 1800
  },
  "summary": {
    "total_to_delete": 7600
  }
}
```

---

### Étape 3 : Automatiser avec Temporal

```bash
cd cron_symfony_mtf_workers

# 1. Prévisualiser le schedule
python3 scripts/new/manage_cleanup_schedule.py create --dry-run

# 2. Créer le schedule (Dimanche 3h UTC, exécution réelle)
python3 scripts/new/manage_cleanup_schedule.py create

# 3. Vérifier le statut
python3 scripts/new/manage_cleanup_schedule.py status
```

**✅ C'est tout ! Le nettoyage s'exécutera automatiquement chaque dimanche à 3h UTC.**

---

## 📋 Commandes Utiles

### API REST

```bash
# Prévisualisation globale
curl -X POST http://localhost:8000/api/maintenance/cleanup \
  -d '{"dry_run": true}'

# Exécution réelle
curl -X POST http://localhost:8000/api/maintenance/cleanup \
  -d '{"dry_run": false}'

# Nettoyage ciblé sur BTCUSDT
curl -X POST http://localhost:8000/api/maintenance/cleanup \
  -d '{"symbol": "BTCUSDT", "dry_run": false}'

# Paramètres personnalisés
curl -X POST http://localhost:8000/api/maintenance/cleanup \
  -d '{
    "dry_run": false,
    "klines_limit": 1000,
    "audit_days": 7,
    "signal_days": 7
  }'

# Obtenir les valeurs par défaut
curl http://localhost:8000/api/maintenance/cleanup/defaults
```

### Workflow Temporal

```bash
cd cron_symfony_mtf_workers

# Créer le schedule
python3 scripts/new/manage_cleanup_schedule.py create

# Vérifier le statut
python3 scripts/new/manage_cleanup_schedule.py status

# Déclencher immédiatement (une fois)
python3 scripts/new/manage_cleanup_schedule.py trigger

# Mettre en pause
python3 scripts/new/manage_cleanup_schedule.py pause

# Reprendre
python3 scripts/new/manage_cleanup_schedule.py resume

# Supprimer
python3 scripts/new/manage_cleanup_schedule.py delete
```

---

## ⚙️ Configuration Avancée

### Modifier les Paramètres par Défaut

**Dans le code :**
Éditez `trading-app/src/Provider/CleanupProvider.php` :
```php
public const int KLINES_KEEP_LIMIT = 500;       // Modifier ici
public const int MTF_AUDIT_DAYS_KEEP = 3;       // Modifier ici
public const int SIGNALS_DAYS_KEEP = 3;         // Modifier ici
```

**Pour le workflow Temporal :**
```bash
export CLEANUP_CRON="0 2 * * *"              # Tous les jours à 2h
export CLEANUP_DRY_RUN="false"               # Exécution réelle
export CLEANUP_KLINES_LIMIT="1000"           # Garder 1000 klines
export CLEANUP_AUDIT_DAYS="7"                # Garder 7 jours
export CLEANUP_SIGNAL_DAYS="7"               # Garder 7 jours
export CLEANUP_SYMBOL=""                     # Tous les symboles

python3 scripts/new/manage_cleanup_schedule.py create
```

---

## 📊 Règles de Nettoyage

| Table | Règle | Configurable |
|-------|-------|--------------|
| **klines** | Garde les **500 plus récentes** par (symbol, timeframe) | `klines_limit` |
| **mtf_audit** | Garde les **3 derniers jours** | `audit_days` |
| **signals** | Garde les **3 derniers jours** | `signal_days` |

---

## 🔒 Sécurité

- ✅ **Mode dry-run par défaut** sur l'API
- ✅ **Transactions SQL** avec rollback automatique en cas d'erreur
- ✅ **Logs détaillés** de toutes les opérations
- ⚠️ **Endpoints ouverts** : Ajoutez une authentification si nécessaire

---

## 🐛 Dépannage

### Problème : "Temps d'exécution trop long"

**Solution :** Nettoyer par symbole ou table par table
```bash
# Par symbole
curl -X POST http://localhost:8000/api/maintenance/cleanup \
  -d '{"symbol": "BTCUSDT", "dry_run": false}'

# Table par table
curl -X POST http://localhost:8000/api/maintenance/cleanup/klines \
  -d '{"dry_run": false}'
```

### Problème : "Schedule already exists"

**Solution :**
```bash
python3 scripts/new/manage_cleanup_schedule.py delete
python3 scripts/new/manage_cleanup_schedule.py create
```

### Problème : "Connection refused" (Temporal)

**Solution :** Vérifier que Temporal est démarré
```bash
docker-compose ps temporal
```

---

## 📚 Documentation Complète

- **API détaillée :** `trading-app/docs/MAINTENANCE.md`
- **Workflow Temporal :** `cron_symfony_mtf_workers/scripts/new/README.md`

---

## 🎯 Cas d'Usage Courants

### 1. Nettoyage hebdomadaire automatisé (Production)

```bash
export CLEANUP_CRON="0 3 * * 0"   # Dimanche 3h
export CLEANUP_DRY_RUN="false"
python3 scripts/new/manage_cleanup_schedule.py create
```

### 2. Nettoyage quotidien conservatif

```bash
export CLEANUP_CRON="0 2 * * *"
export CLEANUP_KLINES_LIMIT="2000"
export CLEANUP_AUDIT_DAYS="14"
export CLEANUP_SIGNAL_DAYS="14"
python3 scripts/new/manage_cleanup_schedule.py create
```

### 3. Nettoyage urgent ponctuel

```bash
# Test en dry-run d'abord
curl -X POST http://localhost:8000/api/maintenance/cleanup \
  -d '{"dry_run": true}'

# Exécution réelle après vérification
curl -X POST http://localhost:8000/api/maintenance/cleanup \
  -d '{"dry_run": false}'
```

### 4. Nettoyage d'un symbole spécifique

```bash
# Via API
curl -X POST http://localhost:8000/api/maintenance/cleanup \
  -d '{"symbol": "ETHUSDT", "dry_run": false}'

# Via Temporal (schedule dédié)
export CLEANUP_SYMBOL="ETHUSDT"
export CLEANUP_SCHEDULE_ID="cron-db-cleanup-ethusdt"
python3 scripts/new/manage_cleanup_schedule.py create
```

---

## ✅ Checklist Mise en Production

- [ ] Tester l'API en dry-run
- [ ] Vérifier les statistiques retournées
- [ ] Exécuter manuellement une fois en mode réel
- [ ] Vérifier les logs Symfony (`var/log/`)
- [ ] Créer le schedule Temporal
- [ ] Vérifier dans Temporal UI que le schedule est actif
- [ ] Monitorer la première exécution automatique
- [ ] Documenter la configuration choisie

---

**Besoin d'aide ?** Consultez la documentation complète dans :
- `trading-app/docs/MAINTENANCE.md`
- `cron_symfony_mtf_workers/scripts/new/README.md`

