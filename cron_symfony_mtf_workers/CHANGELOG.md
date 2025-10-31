# Changelog

## [Unreleased] - 2025-10-31

### ✨ Ajouté

- **Formattage concis des résultats MTF** (`utils/response_formatter.py`)
  - Extraction et affichage des contrats SUCCESS par timeframe (5m, 1m, 15m, 1h, 4h)
  - Compteurs INVALID par timeframe (sans lister tous les symboles)
  - Métriques globales condensées (temps, symboles, taux de succès)

- **Tests unitaires** (`tests/test_response_formatter.py`)
  - Test avec contrats SUCCESS
  - Test sans contrats SUCCESS
  - Test de gestion d'erreurs

- **Documentation améliorée**
  - Guide de déploiement (`DEPLOYMENT.md`)
  - Section formattage dans `README.md`
  - Changelog (`CHANGELOG.md`)

### 🔄 Modifié

- **Activity `mtf_api_call`** (`activities/mtf_http.py`)
  - Intégration du formatter pour réponses structurées
  - Retour d'un dict avec `summary`, `success_contracts`, `metrics`, `full_response`

- **Workflow `CronSymfonyMtfWorkersWorkflow`** (`workflows/mtf_workers.py`)
  - Affichage du résumé dans les logs INFO
  - Réponse complète disponible en logs DEBUG
  - Retour du résultat formaté au lieu de `None`

### 📈 Amélioration

- **Lisibilité Temporal UI** : passage de ~1500 lignes JSON à ~15 lignes de résumé
- **Focus sur SUCCESS** : mise en avant des contrats validés pour 5m et 1m
- **Debugging préservé** : JSON complet toujours accessible via historique Temporal

---

## Motivations

### Problème initial

L'output dans Temporal affichait un JSON de 150+ symboles avec tous les détails :
- Difficile de repérer rapidement les contrats SUCCESS
- Surcharge d'informations INVALID peu utiles en monitoring rapide
- Navigation compliquée dans l'UI Temporal

### Solution implémentée

1. **Résumé structuré** avec métriques clés
2. **Liste explicite** des symboles SUCCESS pour 5m/1m/15m/etc.
3. **Compteurs agrégés** pour INVALID par timeframe
4. **JSON complet préservé** pour analyse approfondie si nécessaire

### Exemple de résultat

**Avant :**
```json
{
  "body": "{\"status\":\"success\",\"data\":{\"results\":{\"BTCUSDT\":{...},\"ETHUSDT\":{...},...}}}",
  "ok": true,
  "status": 200,
  ...
}
```

**Après :**
```
✅ MTF Run Completed (31.4s)
📊 Symbols: 148 processed | Success Rate: 2%
🔄 Workers: 5 | Dry-run: false

🎯 SUCCESS (5m): BTCUSDT, ETHUSDT
🎯 SUCCESS (1m): ADAUSDT

📉 INVALID by timeframe:
  • 15m: 42 symbols
  • 1h: 92 symbols
  • 4h: 14 symbols
```

