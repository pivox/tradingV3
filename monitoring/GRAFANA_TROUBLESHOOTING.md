# Guide de Dépannage Grafana

## 🚨 Erreurs Courantes et Solutions

### 1. "Failed to upgrade legacy queries"

**Problème** : Les requêtes utilisent un format obsolète de Grafana.

**Solution** :
```bash
# Redémarrer Grafana après correction des dashboards
docker-compose restart grafana
```

**Dashboards corrigés** :
- ✅ `comprehensive-logs-dashboard-fixed.json` (nouveau)
- ✅ `trading-logs-advanced.json` (corrigé)
- ✅ `error-monitoring-dashboard.json` (corrigé)
- ✅ `trading-app-logs.json` (corrigé)

### 2. "No data" dans les panels

**Causes possibles** :
- Aucun log disponible dans la période sélectionnée
- Requêtes incorrectes
- Labels inexistants

**Solutions** :
```logql
# Vérifier les logs disponibles
{job="symfony"}

# Vérifier les labels disponibles
label_values({job="symfony"}, channel)
label_values({job="symfony"}, level)
```

### 3. "Datasource not found"

**Problème** : Le datasource Loki n'est pas configuré.

**Solution** :
```bash
# Vérifier que Loki fonctionne
curl -s "http://localhost:3100/ready"

# Vérifier la configuration du datasource
docker exec grafana cat /etc/grafana/provisioning/datasources/loki.yaml
```

### 4. "Connection refused" à Loki

**Problème** : Loki n'est pas accessible depuis Grafana.

**Solutions** :
```bash
# Vérifier l'état de Loki
docker-compose ps loki

# Vérifier les logs de Loki
docker logs loki --tail 20

# Redémarrer Loki si nécessaire
docker-compose restart loki
```

### 5. "No logs found" dans Promtail

**Problème** : Promtail ne collecte pas les logs.

**Solutions** :
```bash
# Vérifier l'état de Promtail
docker-compose ps promtail

# Vérifier les logs de Promtail
docker logs promtail --tail 20

# Vérifier les fichiers de logs
docker exec trading_app_php ls -la /var/log/symfony/

# Redémarrer Promtail
docker-compose restart promtail
```

## 🔧 Commandes de Diagnostic

### Vérifier l'état des services
```bash
# État de tous les services
docker-compose ps

# Logs de Grafana
docker logs grafana --tail 20

# Logs de Loki
docker logs loki --tail 20

# Logs de Promtail
docker logs promtail --tail 20
```

### Tester les APIs
```bash
# Test de santé Grafana
curl -s "http://localhost:3001/api/health"

# Test de santé Loki
curl -s "http://localhost:3100/ready"

# Test des labels Loki
curl -s "http://localhost:3100/loki/api/v1/labels" | jq .

# Test des valeurs de labels
curl -s "http://localhost:3100/loki/api/v1/label/channel/values" | jq .
```

### Vérifier les logs disponibles
```bash
# Lister les fichiers de logs
docker exec trading_app_php find /var/log/symfony -name "*.log" -type f

# Voir le contenu d'un fichier de log
docker exec trading_app_php head -10 /var/log/symfony/signals-2025-10-14.log

# Créer un log de test
docker exec trading_app_php bash -c 'echo "[$(date)] Test log message" >> /var/log/symfony/test.log'
```

## 📊 Requêtes de Diagnostic

### Vérifier les logs disponibles
```logql
# Tous les logs
{job="symfony"}

# Logs par canal
{job="symfony", channel="signals"}
{job="symfony", channel="app"}
{job="symfony", channel="request"}

# Logs par niveau
{job="symfony", level="ERROR"}
{job="symfony", level="INFO"}
```

### Vérifier les labels
```logql
# Labels disponibles
label_values({job="symfony"}, channel)
label_values({job="symfony"}, level)
label_values({job="symfony"}, app)
```

### Vérifier les patterns
```logql
# Logs contenant "MTF"
{job="symfony"} |= "[MTF]"

# Logs contenant des erreurs
{job="symfony"} |= "ERROR"

# Logs contenant des exceptions
{job="symfony"} |= "Exception"
```

## 🚀 Solutions Rapides

### Redémarrer tout le stack de logging
```bash
docker-compose restart loki promtail grafana
```

### Réinitialiser Grafana
```bash
# Arrêter Grafana
docker-compose stop grafana

# Supprimer le volume de données
docker volume rm tradingv3_grafana-data

# Redémarrer Grafana
docker-compose up -d grafana
```

### Réinitialiser Loki
```bash
# Arrêter Loki
docker-compose stop loki

# Supprimer le volume de données
docker volume rm tradingv3_loki-data

# Redémarrer Loki
docker-compose up -d loki
```

## 📝 Logs de Debug

### Activer les logs de debug
```bash
# Grafana avec logs détaillés
docker-compose logs -f grafana

# Loki avec logs détaillés
docker-compose logs -f loki

# Promtail avec logs détaillés
docker-compose logs -f promtail
```

### Vérifier la configuration
```bash
# Configuration Promtail
docker exec promtail cat /etc/promtail/config.yml

# Configuration Loki
docker exec loki cat /etc/loki/local-config.yaml

# Configuration Grafana datasources
docker exec grafana cat /etc/grafana/provisioning/datasources/loki.yaml
```

## 🎯 Checklist de Diagnostic

- [ ] Services en cours d'exécution (`docker-compose ps`)
- [ ] Loki accessible (`curl http://localhost:3100/ready`)
- [ ] Grafana accessible (`curl http://localhost:3001/api/health`)
- [ ] Promtail collecte les logs (`docker logs promtail`)
- [ ] Fichiers de logs existent (`docker exec trading_app_php ls /var/log/symfony/`)
- [ ] Labels disponibles (`curl http://localhost:3100/loki/api/v1/labels`)
- [ ] Dashboards chargés (interface Grafana)
- [ ] Requêtes fonctionnent (panels affichent des données)

## 📞 Support

Si les problèmes persistent :

1. **Vérifiez les logs** de tous les services
2. **Testez les APIs** individuellement
3. **Redémarrez** les services dans l'ordre : Loki → Promtail → Grafana
4. **Consultez** la documentation Grafana et Loki
5. **Vérifiez** la configuration des dashboards

## 🔗 Liens Utiles

- [Documentation Grafana](https://grafana.com/docs/)
- [Documentation Loki](https://grafana.com/docs/loki/latest/)
- [LogQL Reference](https://grafana.com/docs/loki/latest/logql/)
- [Grafana Community](https://community.grafana.com/)
