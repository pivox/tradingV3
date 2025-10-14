# Guide de Configuration des Logs Grafana

## 🚀 Accès à Grafana

- **URL** : http://localhost:3001
- **Identifiants** : admin / admin

## 📊 Dashboards Disponibles

### 1. **Dashboard Complet - Tous les Logs** (`comprehensive-logs`)
**URL** : http://localhost:3001/d/comprehensive-logs/dashboard-complet-tous-les-logs

**Fonctionnalités** :
- Vue d'ensemble de tous les logs
- Taux de logs par canal et niveau
- Distribution des logs (graphiques en secteurs)
- Logs en temps réel avec filtres
- Statistiques par type de log

**Panels principaux** :
- Taux de logs par canal (5m)
- Distribution des logs par canal (1h)
- Taux de logs par niveau (5m)
- Distribution des logs par niveau (1h)
- Tous les logs en temps réel
- Compteurs d'erreurs, infos, signaux, positions

### 2. **Dashboard Trading - Logs Avancés** (`trading-logs-advanced`)
**URL** : http://localhost:3001/d/trading-logs-advanced/dashboard-trading-logs-avances

**Fonctionnalités** :
- Focus sur les logs de trading MTF
- Analyse des signaux BUY/SELL
- Monitoring des erreurs spécifiques au trading
- Filtres par symbole et timeframe

**Panels principaux** :
- Taux de logs MTF par symbole
- Signaux BUY par symbole
- Signaux SELL par symbole
- Logs MTF avec signaux de trading
- Compteurs de signaux BUY/SELL/INVALID
- Logs d'erreurs de type (KlineDto)

### 3. **Dashboard Monitoring - Erreurs** (`error-monitoring`)
**URL** : http://localhost:3001/d/error-monitoring/dashboard-monitoring-erreurs

**Fonctionnalités** :
- Monitoring des erreurs en temps réel
- Analyse des exceptions et erreurs fatales
- Alertes visuelles pour les problèmes critiques

**Panels principaux** :
- Taux d'erreurs par canal (5m)
- Toutes les erreurs en temps réel
- Compteurs d'erreurs, exceptions, fatal
- Logs d'exceptions, erreurs de type, fatal, warnings

### 4. **Dashboard Original** (`trading-app-logs`)
**URL** : http://localhost:3001/d/trading-app-logs/trading-app-logs-dashboard

**Fonctionnalités** :
- Dashboard original avec les fonctionnalités de base
- Bon pour une vue générale

## 🔍 Requêtes Loki Avancées

### Requêtes de Base
```logql
# Tous les logs
{job="symfony"}

# Logs par canal
{job="symfony", channel="signals"}

# Logs par niveau
{job="symfony", level="ERROR"}

# Logs par application
{job="symfony", app="trading-app"}
```

### Requêtes avec Filtres
```logql
# Logs contenant "MTF"
{job="symfony"} |= "[MTF]"

# Logs contenant des signaux de trading
{job="symfony"} |~ "\\[\\w+USDT\\]\\[\\d+[hm]\\]\\[BUY|SELL\\]"

# Logs d'erreurs spécifiques
{job="symfony"} |= "Cannot use object"

# Logs d'exceptions
{job="symfony"} |= "Exception"
```

### Requêtes avec Agrégations
```logql
# Taux de logs par canal
sum(rate({job="symfony"}[5m])) by (channel)

# Comptage de logs par niveau
sum(count_over_time({job="symfony"}[1h])) by (level)

# Taux de signaux BUY
sum(rate({job="symfony"} |= "BUY" [5m])) by (symbol)
```

## 🎛️ Filtres et Variables

### Variables Disponibles
- **$channel** : Filtre par canal (signals, app, request)
- **$level** : Filtre par niveau (ERROR, INFO)
- **$app** : Filtre par application
- **$symbol** : Filtre par symbole (quand disponible)
- **$timeframe** : Filtre par timeframe (quand disponible)

### Utilisation des Filtres
1. Cliquez sur les variables en haut du dashboard
2. Sélectionnez les valeurs souhaitées
3. Les panels se mettent à jour automatiquement

## 📈 Types de Panels

### 1. **Time Series**
- Affiche l'évolution des métriques dans le temps
- Parfait pour les taux de logs et les tendances

### 2. **Logs**
- Affiche les logs bruts en temps réel
- Permet de voir les détails des messages

### 3. **Stat**
- Affiche des valeurs numériques simples
- Parfait pour les compteurs

### 4. **Pie Chart**
- Affiche la distribution des données
- Parfait pour voir les proportions

## 🔧 Configuration des Derived Fields

Les derived fields sont configurés pour extraire automatiquement :
- **Symbol** : `[BTCUSDT]` → BTCUSDT
- **Timeframe** : `[1h]` → 1h
- **Side** : `[BUY]` → BUY

## 🚨 Alertes et Monitoring

### Seuils Recommandés
- **Erreurs** : > 10 par heure
- **Exceptions** : > 5 par heure
- **Erreurs Fatal** : > 0 par heure

### Configuration d'Alertes
1. Allez dans "Alerting" → "Alert Rules"
2. Créez une nouvelle règle
3. Utilisez les requêtes Loki comme base
4. Configurez les seuils et notifications

## 📱 Accès Mobile

Grafana est responsive et fonctionne sur mobile :
- Accédez à http://localhost:3001 depuis votre téléphone
- Les dashboards s'adaptent automatiquement

## 🔄 Actualisation

- **Refresh automatique** : 5 secondes
- **Refresh manuel** : Bouton refresh en haut à droite
- **Time range** : Par défaut "Dernière heure"

## 🛠️ Personnalisation

### Ajouter un Nouveau Panel
1. Cliquez sur "Add panel"
2. Choisissez le type de visualisation
3. Configurez la requête Loki
4. Personnalisez l'affichage

### Modifier un Panel Existant
1. Cliquez sur le titre du panel
2. Sélectionnez "Edit"
3. Modifiez la requête ou l'affichage
4. Sauvegardez

## 📊 Exemples de Requêtes Utiles

### Performance
```logql
# Logs les plus fréquents
topk(10, sum(rate({job="symfony"}[5m])) by (__name__))

# Logs par minute
sum(rate({job="symfony"}[1m])) by (channel)
```

### Debugging
```logql
# Logs avec des erreurs spécifiques
{job="symfony"} |= "KlineDto" |= "array"

# Logs de validation
{job="symfony"} |= "validation" |= "failed"
```

### Trading
```logql
# Signaux par timeframe
{job="symfony"} |~ "\\[\\d+[hm]\\]" |= "BUY"

# Logs MTF par symbole
{job="symfony"} |= "[MTF]" |~ "\\[\\w+USDT\\]"
```

## 🎯 Bonnes Pratiques

1. **Utilisez les filtres** pour réduire le bruit
2. **Configurez des alertes** pour les erreurs critiques
3. **Surveillez les tendances** avec les time series
4. **Explorez les logs** avec les panels de logs
5. **Personnalisez** selon vos besoins

## 🔗 Liens Utiles

- [Documentation Loki](https://grafana.com/docs/loki/latest/)
- [LogQL Reference](https://grafana.com/docs/loki/latest/logql/)
- [Grafana Documentation](https://grafana.com/docs/)
