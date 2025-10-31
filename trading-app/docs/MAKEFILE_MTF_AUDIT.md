# Commandes Makefile MTF Audit

Ce document décrit les commandes Makefile disponibles pour faciliter l'exécution des audits MTF.

## 🚀 Quick Start

```bash
# Afficher l'aide
make mtf-audit-help

# Résumé complet (recommandé pour démarrer)
make mtf-audit-summary

# Rapport de calibration rapide
make mtf-audit-calibration

# Vérification de santé
make mtf-health-check
```

---

## 📋 Table des matières

1. [Commandes principales](#commandes-principales)
2. [Rapports individuels](#rapports-individuels)
3. [Options et filtres](#options-et-filtres)
4. [Exemples d'utilisation](#exemples-dutilisation)
5. [Export et automatisation](#export-et-automatisation)

---

## Commandes principales

### `make mtf-audit-help`
Affiche l'aide complète des commandes d'audit MTF.

```bash
make mtf-audit-help
```

### `make mtf-audit-summary`
🎯 **Recommandé pour un aperçu rapide**

Lance les 3 rapports clés en une seule commande :
1. Rapport de calibration (fail_pct_moyen)
2. Health check (santé du système)
3. Échecs agrégés par timeframe

```bash
# Résumé complet par défaut
make mtf-audit-summary

# Résumé pour un timeframe spécifique
make mtf-audit-summary TF=1h

# Résumé avec période custom pour health-check
make mtf-audit-summary PERIOD=7d
```

**Sortie** : Affichage formaté en 3 sections séparées.

---

### `make mtf-audit-full`
Lance **tous les 7 rapports** stats:mtf-audit en séquence.

Rapports inclus :
1. all-sides
2. by-side
3. weights
4. rollup
5. by-timeframe
6. success
7. calibration

```bash
# Tous les rapports avec paramètres par défaut
make mtf-audit-full

# Tous les rapports filtrés
make mtf-audit-full TF=1h,4h SYMBOLS=BTCUSDT,ETHUSDT LIMIT=50
```

⚠️ **Note** : Cette commande peut être longue si vous avez beaucoup de données.

---

### `make mtf-audit-calibration`
🎯 Rapport de calibration avec calcul du `fail_pct_moyen`.

Fournit :
- fail_pct_moyen global
- Statut (EXCELLENT/GOOD/WARNING/CRITICAL/BLOCKED)
- Diagnostic et action recommandée
- Détail par timeframe
- Top 5 conditions bloquantes par timeframe

```bash
# Calibration globale
make mtf-audit-calibration

# Calibration pour un timeframe
make mtf-audit-calibration TF=1h
```

**Interprétation** :
- 0-5% → ✅ Excellent
- 6-9% → ✅ Good
- 10-15% → ⚠️ Warning (règles trop strictes)
- >20% → ❌ Critical (mauvaise calibration)

---

### `make mtf-health-check`
🏥 Vérification de santé du système MTF.

Métriques fournies :
- Taux de succès par timeframe
- Nombre total de validations
- Statut de santé
- Fraîcheur des klines et snapshots

```bash
# Health check sur 24h (défaut)
make mtf-health-check

# Health check sur 7 jours
make mtf-health-check PERIOD=7d

# Health check sur timeframes spécifiques
make mtf-health-check TF=1h,4h PERIOD=48h
```

---

### `make mtf-audit-export`
💾 Exporte **tous les rapports** en JSON dans un répertoire.

Fichiers générés :
- `all-sides.json`
- `by-side.json`
- `weights.json`
- `rollup.json`
- `by-timeframe.json`
- `success.json`
- `calibration.json`
- `health-check.json`

```bash
# Export par défaut dans /var/www/html/var (dans le conteneur)
make mtf-audit-export

# Export dans un répertoire custom
make mtf-audit-export OUTPUT_DIR=/var/www/html/var/mtf-export

# Export avec filtres
make mtf-audit-export TF=1h LIMIT=20 OUTPUT_DIR=/var/www/html/var/reports
```

⚠️ **Important** : Le répertoire `OUTPUT_DIR` doit être accessible depuis le conteneur Docker.

---

## Rapports individuels

### `make mtf-audit-all-sides`
Top conditions bloquantes (tous sides confondus).

```bash
make mtf-audit-all-sides
make mtf-audit-all-sides LIMIT=50
```

### `make mtf-audit-by-side`
Top conditions ventilées par side (long/short).

```bash
make mtf-audit-by-side
make mtf-audit-by-side TF=1h,4h
```

### `make mtf-audit-by-timeframe`
Échecs agrégés par timeframe (détecter où ça bloque).

```bash
make mtf-audit-by-timeframe
make mtf-audit-by-timeframe SYMBOLS=BTCUSDT,ETHUSDT
```

### `make mtf-audit-weights`
Poids (%) de chaque condition dans les échecs par timeframe.

```bash
make mtf-audit-weights
make mtf-audit-weights TF=1h
```

### `make mtf-audit-rollup`
Agrégation multi-niveaux (condition → timeframe → side).

```bash
make mtf-audit-rollup
```

### `make mtf-audit-success`
Liste les dernières validations réussies.

```bash
make mtf-audit-success
make mtf-audit-success LIMIT=20
```

---

## Options et filtres

Toutes les commandes supportent les options suivantes :

### `PERIOD`
**Défaut** : `24h`  
**Commande** : `mtf-health-check` uniquement

Format : `Xh` (heures), `Xd` (jours), `Xw` (semaines)

```bash
make mtf-health-check PERIOD=1h   # Dernière heure
make mtf-health-check PERIOD=7d   # 7 jours
make mtf-health-check PERIOD=2w   # 2 semaines
```

---

### `TF`
**Défaut** : (vide = tous les timeframes)  
**Format** : Liste séparée par des virgules

Timeframes valides : `1m`, `5m`, `15m`, `1h`, `4h`

```bash
make mtf-audit-calibration TF=1h
make mtf-audit-summary TF=1h,4h
make mtf-audit-all-sides TF=15m,1h,4h
```

---

### `SYMBOLS`
**Défaut** : (vide = tous les symboles)  
**Format** : Liste séparée par des virgules

```bash
make mtf-audit-calibration SYMBOLS=BTCUSDT
make mtf-audit-by-side SYMBOLS=BTCUSDT,ETHUSDT
make mtf-health-check SYMBOLS=BTCUSDT,ETHUSDT,BNBUSDT
```

---

### `LIMIT`
**Défaut** : `100`  
**Applicable à** : Rapports avec classement (all-sides, by-side, by-timeframe, success)

```bash
make mtf-audit-all-sides LIMIT=20
make mtf-audit-success LIMIT=50
```

---

### `OUTPUT_DIR`
**Défaut** : `/tmp/mtf-audit`  
**Applicable à** : `mtf-audit-export` uniquement

⚠️ Le chemin doit être accessible depuis le conteneur Docker.

```bash
make mtf-audit-export OUTPUT_DIR=/var/www/html/var/reports
```

---

## Exemples d'utilisation

### Scénario 1 : Audit quotidien rapide

```bash
# Résumé complet des dernières 24h
make mtf-audit-summary
```

**Résultat** :
- fail_pct_moyen (calibration)
- Taux de succès global
- Timeframes les plus problématiques

---

### Scénario 2 : Diagnostic approfondi d'un timeframe

```bash
# 1. Calibration du timeframe 1h
make mtf-audit-calibration TF=1h

# 2. Top conditions bloquantes sur 1h
make mtf-audit-all-sides TF=1h LIMIT=20

# 3. Poids des conditions
make mtf-audit-weights TF=1h
```

---

### Scénario 3 : Analyse d'un symbole spécifique

```bash
# 1. Health check pour BTCUSDT
make mtf-health-check SYMBOLS=BTCUSDT PERIOD=7d

# 2. Conditions bloquantes pour BTCUSDT
make mtf-audit-by-side SYMBOLS=BTCUSDT

# 3. Validations réussies de BTCUSDT
make mtf-audit-success SYMBOLS=BTCUSDT LIMIT=30
```

---

### Scénario 4 : Comparaison avant/après ajustement

```bash
# Avant ajustement
make mtf-audit-calibration > /tmp/calibration-before.txt

# ... Faire des ajustements ...

# Après ajustement (quelques heures plus tard)
make mtf-audit-calibration > /tmp/calibration-after.txt

# Comparer les deux fichiers
diff /tmp/calibration-before.txt /tmp/calibration-after.txt
```

---

### Scénario 5 : Monitoring hebdomadaire

```bash
# Health check sur 7 jours
make mtf-health-check PERIOD=7d

# Rapport complet (tous sides) des 7 derniers jours
# Note: Nécessite de modifier les commandes Symfony pour supporter --since
```

---

## Export et automatisation

### Export complet pour analyse

```bash
# Export tous les rapports en JSON
make mtf-audit-export OUTPUT_DIR=/var/www/html/var/weekly-report

# Copier les fichiers hors du conteneur
docker cp trading-app-php:/var/www/html/var/weekly-report ./reports/$(date +%Y-%m-%d)
```

---

### Script cron quotidien

Créez un fichier `scripts/daily-mtf-audit.sh` :

```bash
#!/bin/bash

DATE=$(date +%Y-%m-%d)
REPORT_DIR="/var/www/html/var/daily-reports/$DATE"

echo "📊 MTF Audit quotidien - $DATE"

# Résumé console
make mtf-audit-summary | tee "/tmp/mtf-summary-$DATE.txt"

# Export JSON
make mtf-audit-export OUTPUT_DIR="$REPORT_DIR"

# Vérifier la calibration
CALIB_STATUS=$(make mtf-audit-calibration | grep "Statut" | awk '{print $2}')

if [[ "$CALIB_STATUS" == "CRITICAL" ]]; then
    echo "⚠️ ALERTE: Calibration critique détectée!"
    # Envoyer notification (Slack, email, etc.)
fi

echo "✅ Audit terminé - Rapports sauvegardés dans $REPORT_DIR"
```

Ajoutez au crontab :

```bash
0 8 * * * cd /path/to/tradingV3 && ./scripts/daily-mtf-audit.sh >> /var/log/mtf-audit.log 2>&1
```

---

### Intégration CI/CD

Dans votre pipeline GitLab CI / GitHub Actions :

```yaml
mtf-audit:
  stage: test
  script:
    - make mtf-audit-calibration
    - make mtf-health-check PERIOD=1h
  artifacts:
    paths:
      - trading-app/var/mtf-audit/*.json
    expire_in: 7 days
```

---

## Dépannage

### Commande non reconnue

```bash
make: *** No rule to make target 'mtf-audit-summary'. Stop.
```

**Solution** : Assurez-vous d'être à la racine du projet où se trouve le `makefile`.

```bash
cd /path/to/tradingV3
make mtf-audit-summary
```

---

### Erreur d'export (No such file or directory)

```bash
Warning: file_put_contents(/tmp/mtf-test/all-sides.json): Failed to open stream
```

**Solution** : Utilisez un répertoire accessible dans le conteneur Docker :

```bash
make mtf-audit-export OUTPUT_DIR=/var/www/html/var/mtf-audit
```

---

### Conteneur non démarré

```bash
Error: No such service: trading-app-php
```

**Solution** : Démarrez les conteneurs Docker :

```bash
docker-compose up -d
make mtf-audit-summary
```

---

### Commande trop lente

**Solution** : Ajoutez des filtres pour réduire le scope :

```bash
# Au lieu de
make mtf-audit-full

# Utilisez
make mtf-audit-summary TF=1h LIMIT=20
```

---

## Aide et support

### Afficher l'aide intégrée

```bash
make mtf-audit-help
```

### Voir toutes les commandes disponibles

```bash
make help
# ou
grep -E '^[a-zA-Z_-]+:.*?## ' makefile
```

---

## Changelog

### Version 1.0 (2025-10-31)
- ✅ Ajout de toutes les commandes d'audit MTF
- ✅ Support des filtres (TF, SYMBOLS, LIMIT, PERIOD)
- ✅ Commande d'export JSON
- ✅ Résumé complet (mtf-audit-summary)
- ✅ Documentation complète

---

**Auteur** : Équipe Trading  
**Dernière mise à jour** : 2025-10-31

