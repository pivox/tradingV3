# 🔧 Dépannage JavaScript - Interface des Indicateurs

## 🚨 Erreur Corrigée: "displayConditions is not defined"

### Problème
L'erreur `displayConditions is not defined` se produisait lors de l'évaluation des indicateurs dans l'interface web.

### Cause
La fonction `displayConditions` était appelée dans la nouvelle fonction `displayResults` mais n'existait pas. Il y avait une confusion entre les noms de fonctions.

### Solution Appliquée
```javascript
// AVANT (incorrect)
function displayResults(data) {
    // ...
    displayConditions(data.conditions_results); // ❌ Fonction inexistante
    // ...
}

// APRÈS (corrigé)
function displayResults(data) {
    // ...
    displayConditionsTable(data.conditions_results); // ✅ Fonction existante
    // ...
}
```

### Vérification
- ✅ Fonction `displayConditionsTable` existe et fonctionne
- ✅ Fonction `displayResults` corrigée
- ✅ Suppression de la fonction dupliquée
- ✅ Tests de validation passés

## 🛠️ Fonctions JavaScript Disponibles

### Fonctions d'Affichage
- `displayResults(data)` - Affiche tous les résultats
- `displaySummary(summary)` - Affiche le résumé des statistiques
- `displayConditionsTable(conditions)` - Affiche le tableau des conditions
- `displayTimeframeValidation(validation)` - Affiche la validation du timeframe
- `displayContext(context)` - Affiche le contexte des indicateurs
- `displayReplayResults(data)` - Affiche les résultats du replay

### Fonctions d'Évaluation
- `evaluateIndicators()` - Évalue les indicateurs
- `runReplayTest()` - Lance un test de replay
- `loadAvailableConditions()` - Charge les conditions disponibles
- `loadExampleKlines()` - Charge l'exemple de klines

### Fonctions Utilitaires
- `showLoading(show)` - Affiche/masque le spinner de chargement
- `showError(message)` - Affiche un message d'erreur
- `showSuccess(message)` - Affiche un message de succès
- `clearMessages()` - Efface les messages

## 🔍 Dépannage des Erreurs Courantes

### 1. "Function is not defined"
**Symptôme**: Erreur JavaScript dans la console du navigateur
**Solution**: Vérifier que la fonction existe et est correctement nommée

### 2. "Cannot read property of undefined"
**Symptôme**: Erreur lors de l'accès aux propriétés d'un objet
**Solution**: Vérifier que l'objet existe avant d'accéder à ses propriétés

### 3. "JSON parse error"
**Symptôme**: Erreur lors du parsing des données JSON
**Solution**: Vérifier le format des données envoyées au serveur

### 4. "Network error"
**Symptôme**: Erreur de communication avec le serveur
**Solution**: Vérifier que le serveur est démarré et accessible

## 🧪 Tests de Validation

### Script de Test
```bash
./scripts/test-interface-fix.sh
```

### Tests Inclus
- ✅ Page principale accessible
- ✅ Évaluation avec données par défaut
- ✅ Support des klines JSON
- ✅ Support des données personnalisées
- ✅ Validation des timeframes
- ✅ Gestion des erreurs

### Vérification Manuelle
1. Ouvrir l'interface: `http://localhost:8082/indicators/test`
2. Sélectionner un symbole et un timeframe
3. Cliquer sur "Évaluer les Indicateurs"
4. Vérifier que les résultats s'affichent correctement
5. Vérifier la console du navigateur (F12) pour les erreurs

## 📋 Checklist de Dépannage

### Avant de Signaler un Bug
- [ ] Vérifier que le serveur est démarré
- [ ] Vider le cache du navigateur (Ctrl+F5)
- [ ] Vérifier la console JavaScript (F12)
- [ ] Tester avec différents navigateurs
- [ ] Vérifier les logs du serveur

### En Cas d'Erreur
1. **Copier l'erreur exacte** de la console
2. **Noter les étapes** pour reproduire l'erreur
3. **Vérifier les données** envoyées au serveur
4. **Tester avec des données simples** d'abord
5. **Consulter les logs** du serveur

## 🔧 Outils de Débogage

### Console du Navigateur
- **F12** pour ouvrir les outils de développement
- **Console** pour voir les erreurs JavaScript
- **Network** pour voir les requêtes HTTP
- **Elements** pour inspecter le DOM

### Logs du Serveur
```bash
# Voir les logs en temps réel
tail -f var/log/dev.log

# Vider le cache
php bin/console cache:clear

# Vérifier les services
php bin/console debug:container | grep -i indicator
```

### Tests API
```bash
# Test simple
curl -X POST http://localhost:8082/indicators/evaluate \
  -H "Content-Type: application/json" \
  -d '{"symbol":"BTCUSDT","timeframe":"1h"}'

# Test avec klines JSON
curl -X POST http://localhost:8082/indicators/evaluate \
  -H "Content-Type: application/json" \
  -d '{"symbol":"ETHUSDT","timeframe":"4h","klines_json":[...]}'
```

## 📚 Ressources

### Documentation
- **Guide d'utilisation**: `docs/WEB_INTERFACE_GUIDE.md`
- **Résumé des améliorations**: `ENHANCED_INTERFACE_SUMMARY.md`
- **Validation des indicateurs**: `INDICATOR_VALIDATION.md`

### Scripts de Test
- **Test de correction**: `scripts/test-interface-fix.sh`
- **Test complet**: `scripts/test-enhanced-interface.sh`
- **Test web**: `scripts/test-web-interface.sh`

### Support
- **Issues**: GitHub Issues pour les bugs
- **Discussions**: GitHub Discussions pour les questions
- **Wiki**: Documentation collaborative

---

*Ce guide de dépannage vous aide à résoudre les problèmes JavaScript courants dans l'interface des indicateurs.* 🔧

