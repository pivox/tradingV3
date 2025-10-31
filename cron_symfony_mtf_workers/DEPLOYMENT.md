# Guide de déploiement

## Changements apportés

### ✅ Formattage amélioré des résultats Temporal

Le workflow retourne maintenant un résumé concis au lieu du JSON complet de 150+ symboles.

**Modifications :**
- `utils/response_formatter.py` : module de formattage des réponses
- `activities/mtf_http.py` : intégration du formatter
- `workflows/mtf_workers.py` : affichage du résumé dans les logs
- `tests/test_response_formatter.py` : tests unitaires

**Nouveaux affichages :**
- 🎯 Liste des symboles SUCCESS pour chaque timeframe (5m, 1m, 15m, etc.)
- 📉 Compteurs INVALID par timeframe (sans lister tous les symboles)
- 📊 Métriques globales (temps, symboles traités, taux de succès)

---

## Déploiement

### 1. Rebuild le conteneur Docker

```bash
cd /Users/haythem.mabrouk/workspace/perso/tradingV3
docker-compose build cron-symfony-mtf-workers
```

### 2. Redémarrer le service

```bash
docker-compose up -d cron-symfony-mtf-workers
```

### 3. Vérifier les logs

```bash
docker-compose logs -f cron-symfony-mtf-workers
```

Vous devriez voir des messages de type :
```
[CronMtfWorkers] ✅ Result:
✅ MTF Run Completed (31.4s)
📊 Symbols: 148 processed | Success Rate: 2%
🔄 Workers: 5 | Dry-run: false

🎯 SUCCESS (5m): BTCUSDT, ETHUSDT
🎯 SUCCESS (1m): None
...
```

---

## Tests en local

### Tester le formatter

```bash
cd cron_symfony_mtf_workers
PYTHONPATH=$PWD python3 tests/test_response_formatter.py
```

### Tester le workflow complet (optionnel)

Si vous avez un environnement Temporal local :

```bash
docker exec -it cron_symfony_mtf_workers python3 -c "
from workflows.mtf_workers import CronSymfonyMtfWorkersWorkflow
print('Workflow loaded successfully')
"
```

---

## Rollback

Si vous souhaitez revenir à l'ancienne version :

```bash
git checkout HEAD~1 -- cron_symfony_mtf_workers/
docker-compose build cron-symfony-mtf-workers
docker-compose up -d cron-symfony-mtf-workers
```

---

## Notes

- Les résultats complets restent accessibles dans l'historique Temporal (logs de debug)
- Le résumé est optimisé pour une lecture rapide dans l'UI Temporal
- Les tests unitaires valident le formattage pour différents scénarios

