# Commandes MTF Audit & Health Check

Ce document décrit les nouvelles options de diagnostic ajoutées aux commandes MTF.

## Table des matières
1. [stats:mtf-audit - Nouveaux rapports](#statsmt-audit---nouveaux-rapports)
2. [mtf:health-check - Nouvelle commande](#mtfhealth-check---nouvelle-commande)
3. [stats:mtf-audit --report=calibration - Rapport de calibration](#rapport-calibration---évaluation-de-la-qualité)

---

## stats:mtf-audit - Nouveaux rapports

La commande `stats:mtf-audit` dispose maintenant de **7 types de rapports** (au lieu de 4) :

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

## Rapport Calibration - Évaluation de la qualité

### Objectif
Le rapport `calibration` calcule le **fail_pct_moyen** du système pour évaluer la qualité de la calibration des règles MTF.

**Formule** : `fail_pct_moyen = (∑ fail_count) / (∑ total_fails) × 100`

Cette métrique indique le degré de concentration des échecs sur certaines conditions.

### Utilisation

```bash
# Rapport de calibration global
docker-compose exec trading-app-php bin/console stats:mtf-audit --report=calibration

# Calibration pour un timeframe spécifique
docker-compose exec trading-app-php bin/console stats:mtf-audit --report=calibration -t 1h

# Export JSON
docker-compose exec trading-app-php bin/console stats:mtf-audit --report=calibration \
  --format=json --output=/tmp/calibration.json

# Analyse sur période spécifique
docker-compose exec trading-app-php bin/console stats:mtf-audit --report=calibration \
  --since="now -7 days"
```

### Grille d'interprétation

| fail_pct_moyen | Statut | Diagnostic | Action recommandée |
|----------------|--------|------------|-------------------|
| **0 – 5%** | ✅ EXCELLENT | Bon équilibre | Stable |
| **6 – 9%** | ✅ GOOD | Marché neutre / cohérent | OK |
| **10 – 15%** | ⚠️ WARNING | Règles trop strictes | Assouplir les tolérances EMA / MACD |
| **> 20%** | ❌ CRITICAL | Très mauvais calibrage | Règles mal conçues ou non pertinentes |
| **= 0% stable plusieurs heures** | 🚫 BLOCKED | Données ou process figés | Blocage pipeline |

### Informations fournies

#### 1. Résumé Global
- **fail_pct_moyen** : Pourcentage moyen de concentration des échecs
- **∑ fail_count** : Somme de tous les fail_count de toutes les conditions
- **∑ total_fails** : Somme des échecs totaux par timeframe
- **Statut** : Évaluation automatique (EXCELLENT/GOOD/WARNING/CRITICAL/BLOCKED)
- **Diagnostic** : Explication du statut
- **Action recommandée** : Conseil d'action

#### 2. Grille d'interprétation
Tableau de référence avec tous les seuils et leurs significations.

#### 3. Détail par Timeframe
Pour chaque timeframe :
- Somme des fail_count
- Total des échecs
- Pourcentage fail_pct

#### 4. Top Conditions par Timeframe
Les 5 conditions les plus bloquantes par timeframe avec :
- Nom de la condition
- Nombre d'échecs
- Pourcentage dans les échecs du timeframe

### Exemples de résultats

#### Exemple 1 : Système sain (6.88%)
```
📊 Résumé Global
fail_pct_moyen: 6.88%
Statut: GOOD
Diagnostic: Marché neutre / cohérent
Action: ⚙️ OK

✅ SYSTÈME SAIN : Marché neutre / cohérent
```
**Interprétation** : Les échecs sont bien répartis entre les conditions. Le système fonctionne normalement.

#### Exemple 2 : Règles trop strictes (12.5%)
```
📊 Résumé Global
fail_pct_moyen: 12.5%
Statut: WARNING
Diagnostic: Règles trop strictes
Action: 🔹 Assouplir les tolérances EMA / MACD

⚠️ ATTENTION : Règles trop strictes
```
**Interprétation** : Les échecs sont trop concentrés. Envisagez d'assouplir les tolérances.

#### Exemple 3 : Calibration critique (25%)
```
📊 Résumé Global
fail_pct_moyen: 25.0%
Statut: CRITICAL
Diagnostic: Très mauvais calibrage
Action: 🔸 Règles mal conçues ou non pertinentes

❌ CALIBRATION CRITIQUE : Très mauvais calibrage
```
**Interprétation** : Les règles bloquent massivement. Révision complète nécessaire.

### Format de sortie JSON

```json
{
    "fail_pct_moyen": 6.88,
    "sum_fail_count": 311520,
    "sum_total_fails": 4530300,
    "interpretation": {
        "status": "GOOD",
        "diagnostic": "Marché neutre / cohérent",
        "action": "⚙️ OK",
        "color": "green"
    },
    "by_timeframe": [
        {
            "timeframe": "1h",
            "fail_count_sum": 283020,
            "total_fails": 283020,
            "fail_pct": 100,
            "top_conditions": [
                {
                    "condition": "macd_line_cross_up_with_hysteresis",
                    "fail_count": 30404,
                    "fail_pct": 10.74
                }
            ]
        }
    ]
}
```

### Cas d'usage

#### 1. Audit quotidien
```bash
# Vérifier la calibration des dernières 24h
docker-compose exec trading-app-php bin/console stats:mtf-audit \
  --report=calibration --since="now -24 hours"
```

#### 2. Comparaison avant/après ajustement
```bash
# Avant ajustement
docker-compose exec trading-app-php bin/console stats:mtf-audit \
  --report=calibration --from="2025-10-29 00:00:00+00" --to="2025-10-30 00:00:00+00"

# Après ajustement
docker-compose exec trading-app-php bin/console stats:mtf-audit \
  --report=calibration --from="2025-10-30 00:00:00+00" --to="2025-10-31 00:00:00+00"
```

#### 3. Analyse par timeframe
```bash
# Comparer la calibration de chaque timeframe
docker-compose exec trading-app-php bin/console stats:mtf-audit --report=calibration -t 1m
docker-compose exec trading-app-php bin/console stats:mtf-audit --report=calibration -t 5m
docker-compose exec trading-app-php bin/console stats:mtf-audit --report=calibration -t 15m
docker-compose exec trading-app-php bin/console stats:mtf-audit --report=calibration -t 1h
docker-compose exec trading-app-php bin/console stats:mtf-audit --report=calibration -t 4h
```

#### 4. Monitoring automatisé
```bash
# Script cron quotidien
#!/bin/bash
RESULT=$(docker-compose exec -T trading-app-php bin/console stats:mtf-audit \
  --report=calibration --format=json)

FAIL_PCT=$(echo "$RESULT" | jq -r '.fail_pct_moyen')
STATUS=$(echo "$RESULT" | jq -r '.interpretation.status')

if [ "$STATUS" = "CRITICAL" ] || [ "$STATUS" = "BLOCKED" ]; then
    # Envoyer alerte (Slack, email, etc.)
    echo "ALERTE MTF: Calibration $STATUS (fail_pct=$FAIL_PCT%)"
fi
```

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

