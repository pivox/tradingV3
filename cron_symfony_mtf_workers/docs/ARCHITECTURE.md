# Architecture du Worker MTF

## Vue d'ensemble

```
┌─────────────────────────────────────────────────────────────┐
│                      Temporal Server                         │
│                     (Schedule: 1m cron)                      │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│           CronSymfonyMtfWorkersWorkflow                      │
│                                                              │
│  1. Normalise les jobs (URL, workers, dry_run)              │
│  2. Appelle l'activité mtf_api_call                          │
│  3. Formate et log le résumé                                 │
│  4. Retourne résultat structuré                              │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                 Activity: mtf_api_call                       │
│                                                              │
│  1. POST vers Symfony /api/mtf/run                           │
│  2. Parse la réponse JSON                                    │
│  3. Appelle format_mtf_response()                            │
│  4. Retourne résultat formaté                                │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│          Utils: response_formatter.py                        │
│                                                              │
│  • Extrait SUCCESS contracts par timeframe                   │
│  • Compte INVALID par timeframe                              │
│  • Génère résumé concis                                      │
│  • Préserve réponse complète                                 │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                   Symfony MTF Endpoint                       │
│              /api/mtf/run (POST)                             │
│                                                              │
│  • Traite 150+ symboles avec 5 workers                       │
│  • Valide MTF pour chaque timeframe                          │
│  • Retourne résultats détaillés                              │
└─────────────────────────────────────────────────────────────┘
```

## Flux de données

### 1. Input (Temporal Schedule)

```json
{
  "url": "http://trading-app-nginx:80/api/mtf/run",
  "workers": 5,
  "dry_run": false
}
```

### 2. Raw Response (Symfony API)

```json
{
  "status": "success",
  "message": "MTF run completed",
  "data": {
    "summary": {
      "execution_time_seconds": 31.4,
      "symbols_processed": 148,
      "success_rate": 2
    },
    "results": {
      "BTCUSDT": {"status": "SUCCESS", "execution_tf": "5m"},
      "ETHUSDT": {"status": "INVALID", "failed_timeframe": "15m"},
      ...
    }
  }
}
```

### 3. Formatted Output (response_formatter)

```python
{
  "summary": """
    ✅ MTF Run Completed (31.4s)
    📊 Symbols: 148 processed | Success Rate: 2%
    🎯 SUCCESS (5m): BTCUSDT, SOLUSDT
    📉 INVALID by timeframe:
      • 15m: 42 symbols
      • 1h: 92 symbols
  """,
  "success_contracts": {
    "5m": ["BTCUSDT", "SOLUSDT"],
    "1m": []
  },
  "metrics": {
    "execution_time_seconds": 31.4,
    "symbols_processed": 148,
    "success_rate": 2
  },
  "full_response": {...}  # JSON complet préservé
}
```

### 4. Temporal UI Display

Affiche uniquement le champ `summary` dans les logs du workflow :

```
[CronMtfWorkers] ✅ Result:
✅ MTF Run Completed (31.4s)
📊 Symbols: 148 processed | Success Rate: 2%
🎯 SUCCESS (5m): BTCUSDT, SOLUSDT
📉 INVALID by timeframe:
  • 15m: 42 symbols
  • 1h: 92 symbols
```

## Structure des fichiers

```
cron_symfony_mtf_workers/
├── workflows/
│   └── mtf_workers.py           # Workflow principal
├── activities/
│   └── mtf_http.py              # Activité HTTP + formatting
├── utils/
│   ├── __init__.py
│   └── response_formatter.py    # Module de formattage
├── models/
│   └── mtf_job.py               # Model de job
├── tests/
│   ├── __init__.py
│   └── test_response_formatter.py  # Tests unitaires
├── scripts/
│   └── new/
│       └── manage_mtf_workers_schedule.py  # Gestion schedule
├── docs/
│   └── ARCHITECTURE.md          # Ce fichier
├── worker.py                     # Worker Temporal
├── deploy.sh                     # Script de déploiement
├── DEPLOYMENT.md                 # Guide de déploiement
├── CHANGELOG.md                  # Historique des changements
└── README.md                     # Documentation principale
```

## Points clés

### 🎯 Objectif principal
Réduire la verbosité de l'output Temporal de ~1500 lignes à ~15 lignes tout en préservant les informations critiques.

### ✅ Avantages
- **Lisibilité** : résumé clair et structuré
- **Focus** : mise en avant des SUCCESS pour 5m/1m
- **Performance** : réduction de la taille des logs
- **Debugging** : JSON complet toujours accessible

### 🔧 Extensibilité
- Ajout facile de nouveaux timeframes
- Filtrage personnalisable par statut
- Support de métriques additionnelles
- Tests unitaires pour validation

### 📊 Monitoring
- Logs INFO : résumé concis
- Logs DEBUG : réponse complète
- Résultat workflow : données structurées pour analyse

