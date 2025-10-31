# Commandes MTF Audit & Health Check

Ce document décrit les nouvelles options de diagnostic ajoutées aux commandes MTF.

## Table des matières
1. [stats:mtf-audit - Nouveaux rapports](#statsmt-audit---nouveaux-rapports)
2. [mtf:health-check - Nouvelle commande](#mtfhealth-check---nouvelle-commande)

---

## stats:mtf-audit - Nouveaux rapports

La commande `stats:mtf-audit` dispose maintenant de **6 types de rapports** (au lieu de 4) :

### Rapport 5 : `by-timeframe`
**Objectif** : Détecter quel timeframe est le plus problématique en agrégeant les échecs.

```bash
# Rapport global par timeframe
docker-compose exec trading-app-php bin/console stats:mtf-audit --report=by-timeframe

# Avec filtres (dernières 48h, timeframes spécifiques)
docker-compose exec trading-app-php bin/console stats:mtf-audit --report=by-timeframe \
  --since="now -48 hours" -t 1h,4h
```

**Colonnes retournées** :
- `timeframe` : Timeframe analysé (1h, 4h, etc.)
- `total_failures` : Nombre total d'échecs
- `nb_symbols` : Nombre de symboles distincts affectés
- `last_failure_candle` : Date de la dernière bougie en échec
- `last_failure_ts` : Timestamp de la dernière entrée
- `first_failure_ts` : Timestamp de la première entrée

### Rapport 6 : `success`
**Objectif** : Lister les dernières validations réussies pour vérifier que le système fonctionne.

```bash
# Liste les 100 dernières validations réussies (défaut)
docker-compose exec trading-app-php bin/console stats:mtf-audit --report=success

# Avec filtres (dernières 24h, symboles spécifiques, limite 50)
docker-compose exec trading-app-php bin/console stats:mtf-audit --report=success \
  --since="now -24 hours" --symbols=BTCUSDT,ETHUSDT -l 50

# Export CSV
docker-compose exec trading-app-php bin/console stats:mtf-audit --report=success \
  --format=csv --output=/tmp/mtf_success.csv
```

**Colonnes retournées** :
- `symbol` : Symbole validé
- `timeframe` : Timeframe validé
- `step` : Étape de succès (ex: VALIDATION_SUCCESS)
- `side` : Direction (long/short/n/a)
- `created_at` : Date de la validation
- `candle_ts` : Timestamp de la bougie concernée
- `run_id` : ID du run MTF

---

## mtf:health-check - Nouvelle commande

**Objectif** : Analyser la santé globale du système MTF sur une période donnée.

### Utilisation de base

```bash
# Analyse des dernières 24h (défaut)
docker-compose exec trading-app-php bin/console mtf:health-check

# Analyse sur 7 jours
docker-compose exec trading-app-php bin/console mtf:health-check --period=7d

# Analyse sur 48 heures pour des timeframes spécifiques
docker-compose exec trading-app-php bin/console mtf:health-check --period=48h -t 1h,4h
```

### Options disponibles

| Option | Alias | Description | Défaut |
|--------|-------|-------------|--------|
| `--period` | `-p` | Période d'analyse (format: Xh, Xd, Xw) | 24h |
| `--symbols` | - | Liste de symboles (CSV) | Tous |
| `--timeframes` | `-t` | Liste de timeframes (CSV) | Tous |
| `--format` | `-f` | Format de sortie (table, json, csv) | table |
| `--output` | `-o` | Fichier de sortie (pour json/csv) | stdout |

### Métriques fournies

#### 1. Résumé global
- **Total validations** : Nombre total de validations (succès + échecs)
- **Succès** : Nombre de validations réussies
- **Échecs** : Nombre de validations échouées
- **Taux de succès** : Pourcentage de réussite
- **Statut** : État de santé (HEALTHY / WARNING / CRITICAL)

#### 2. Santé par timeframe
Pour chaque timeframe :
- Nombre de succès et d'échecs
- Taux de succès en %
- Nombre de symboles affectés
- Statut de santé

#### 3. Données auxiliaires
- **Klines** : Total, récentes (<10min), dernière mise à jour
- **Indicator snapshots** : Total, récents (<10min), dernière mise à jour

### Seuils de santé

| Statut | Taux de succès | Signification |
|--------|----------------|---------------|
| ✅ **HEALTHY** | ≥ 80% | Système en bonne santé |
| ⚠️ **WARNING** | 60-79% | Attention, dégradation possible |
| ❌ **CRITICAL** | < 60% | Problème majeur détecté |

### Export et automatisation

```bash
# Export JSON pour monitoring automatique
docker-compose exec trading-app-php bin/console mtf:health-check \
  --format=json --output=/var/log/mtf-health.json

# Export CSV pour analyse
docker-compose exec trading-app-php bin/console mtf:health-check \
  --period=7d --format=csv --output=/tmp/health-7days.csv

# Monitoring cron (exemple)
*/15 * * * * docker-compose exec trading-app-php bin/console mtf:health-check --format=json >> /var/log/mtf-health.log
```

---

## Exemples de flux de diagnostic

### Scénario 1 : Identifier un problème général

```bash
# 1. Vue d'ensemble de la santé
docker-compose exec trading-app-php bin/console mtf:health-check

# 2. Si CRITICAL/WARNING, identifier le timeframe problématique
docker-compose exec trading-app-php bin/console stats:mtf-audit --report=by-timeframe

# 3. Analyser les conditions bloquantes du timeframe
docker-compose exec trading-app-php bin/console stats:mtf-audit --report=all-sides -t 1h
```

### Scénario 2 : Vérifier après un déploiement

```bash
# 1. Vérifier que les validations réussies existent
docker-compose exec trading-app-php bin/console stats:mtf-audit --report=success -l 20

# 2. Vérifier la santé sur la dernière heure
docker-compose exec trading-app-php bin/console mtf:health-check --period=1h

# 3. Comparer avec la période précédente
docker-compose exec trading-app-php bin/console mtf:health-check --period=24h
```

### Scénario 3 : Analyse d'un symbole spécifique

```bash
# 1. Santé pour un symbole
docker-compose exec trading-app-php bin/console mtf:health-check --symbols=BTCUSDT

# 2. Conditions bloquantes pour ce symbole
docker-compose exec trading-app-php bin/console stats:mtf-audit \
  --report=all-sides --symbols=BTCUSDT

# 3. Succès récents pour ce symbole
docker-compose exec trading-app-php bin/console stats:mtf-audit \
  --report=success --symbols=BTCUSDT
```

---

## Intégration avec Grafana/Prometheus

Les commandes peuvent être intégrées dans un système de monitoring :

```bash
# Script à exécuter périodiquement
#!/bin/bash
HEALTH_JSON=$(docker-compose exec -T trading-app-php bin/console mtf:health-check --format=json)
echo "$HEALTH_JSON" | jq -r '.global_summary.success_rate' | sed 's/%//' > /var/lib/prometheus/mtf_success_rate.prom
```

---

## Notes importantes

1. **Performance** : Les requêtes sur de grandes périodes peuvent être lentes. Utilisez des filtres pour améliorer les performances.
2. **Filtres combinables** : Tous les filtres (`--symbols`, `--timeframes`, `--since`, `--from/to`) sont combinables.
3. **Format des dates** : Accepte les formats relatifs (`now -7 days`) et absolus (`2025-10-01 00:00:00+00`).
4. **Limite par défaut** : 100 lignes pour les rapports avec classement.

---

## Dépannage

### Aucune donnée retournée
- Vérifiez que la période contient des données : `--since="now -7 days"`
- Vérifiez les filtres appliqués (symboles, timeframes)
- Consultez directement la table `mtf_audit` en base

### Erreur SQL
- Vérifiez la syntaxe des dates
- Assurez-vous que les timeframes sont valides (1m, 5m, 15m, 1h, 4h)
- Consultez les logs Symfony pour plus de détails

### Performance lente
- Réduisez la période analysée
- Ajoutez des filtres sur symboles/timeframes
- Vérifiez les index sur la table `mtf_audit`

---

**Date de création** : 2025-10-31  
**Version** : 1.0

