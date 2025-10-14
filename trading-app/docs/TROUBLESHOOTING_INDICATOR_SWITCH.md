# Guide de Dépannage - Système de Switch PHP/SQL

## 🚨 Problèmes courants et solutions

### 1. Erreurs de configuration

#### Problème : Service non trouvé
```
Cannot autowire service "App\Service\Indicator\HybridIndicatorService"
```

**Causes possibles :**
- Service non enregistré dans la configuration
- Cache Symfony non vidé
- Dépendances manquantes

**Solutions :**
```bash
# 1. Vider le cache
docker exec trading_app_php bin/console cache:clear

# 2. Vérifier la configuration
docker exec trading_app_php bin/console debug:container | grep Indicator

# 3. Vérifier les services
docker exec trading_app_php bin/console debug:container IndicatorCalculationModeService
```

#### Problème : Configuration invalide
```
Invalid configuration for "indicator_calculation"
```

**Solutions :**
```bash
# 1. Vérifier le fichier de configuration
docker exec trading_app_php cat config/trading.yml | grep -A 10 indicator_calculation

# 2. Valider la syntaxe YAML
docker exec trading_app_php bin/console lint:yaml config/trading.yml

# 3. Recharger la configuration
docker exec trading_app_php bin/console cache:clear
```

### 2. Erreurs de base de données

#### Problème : Vues matérialisées non trouvées
```
ERROR: relation "mv_ema_5m" does not exist
```

**Solutions :**
```bash
# 1. Vérifier l'existence des vues
docker exec trading_app_postgres psql -U postgres -d trading_app -c "
SELECT schemaname, matviewname FROM pg_matviews WHERE matviewname LIKE 'mv_%';
"

# 2. Exécuter les migrations
docker exec trading_app_php bin/console doctrine:migrations:migrate

# 3. Rafraîchir les vues
./scripts/refresh_indicators.sh
```

#### Problème : Données manquantes dans les vues
```
No data found in materialized views
```

**Solutions :**
```bash
# 1. Vérifier les données source
docker exec trading_app_postgres psql -U postgres -d trading_app -c "
SELECT COUNT(*) FROM klines WHERE timeframe = '5m' AND symbol = 'BTCUSDT';
"

# 2. Rafraîchir les vues
docker exec trading_app_postgres psql -U postgres -d trading_app -c "
REFRESH MATERIALIZED VIEW mv_ema_5m;
REFRESH MATERIALIZED VIEW mv_rsi14_5m;
REFRESH MATERIALIZED VIEW mv_macd_5m;
"

# 3. Vérifier les données après rafraîchissement
docker exec trading_app_postgres psql -U postgres -d trading_app -c "
SELECT COUNT(*) FROM mv_ema_5m WHERE symbol = 'BTCUSDT';
"
```

### 3. Erreurs de performance

#### Problème : Calculs trop lents
```
SQL calculation exceeded performance threshold
```

**Diagnostic :**
```bash
# 1. Vérifier les performances
docker exec trading_app_php bin/console app:test-indicator-calculation BTCUSDT 5m

# 2. Analyser les index
docker exec trading_app_postgres psql -U postgres -d trading_app -c "
SELECT indexname, indexdef FROM pg_indexes WHERE tablename LIKE 'mv_%';
"

# 3. Vérifier les statistiques
docker exec trading_app_postgres psql -U postgres -d trading_app -c "
ANALYZE mv_ema_5m;
ANALYZE mv_rsi14_5m;
ANALYZE mv_macd_5m;
"
```

**Solutions :**
```bash
# 1. Optimiser les index
docker exec trading_app_postgres psql -U postgres -d trading_app -c "
CREATE INDEX IF NOT EXISTS idx_mv_ema_5m_symbol_timeframe_bucket 
ON mv_ema_5m(symbol, timeframe, bucket);

CREATE INDEX IF NOT EXISTS idx_mv_rsi14_5m_symbol_timeframe_bucket 
ON mv_rsi14_5m(symbol, timeframe, bucket);
"

# 2. Ajuster le seuil de performance
# Dans config/trading.yml
indicator_calculation:
    performance_threshold_ms: 200  # Augmenter le seuil
```

#### Problème : Fallback automatique trop fréquent
```
Falling back to PHP due to performance issues
```

**Solutions :**
```bash
# 1. Désactiver temporairement le fallback
# Dans config/trading.yml
indicator_calculation:
    fallback_to_php: false

# 2. Optimiser les requêtes SQL
docker exec trading_app_postgres psql -U postgres -d trading_app -c "
EXPLAIN ANALYZE SELECT * FROM mv_ema_5m WHERE symbol = 'BTCUSDT' ORDER BY bucket DESC LIMIT 1;
"
```

### 4. Erreurs de calcul

#### Problème : Résultats incohérents
```
Indicator values are inconsistent between PHP and SQL
```

**Diagnostic :**
```bash
# 1. Comparer les résultats
./scripts/test_indicator_modes.sh BTCUSDT 5m

# 2. Vérifier les données source
docker exec trading_app_postgres psql -U postgres -d trading_app -c "
SELECT 
    bucket,
    ema9,
    ema21,
    ema50,
    ema200
FROM mv_ema_5m 
WHERE symbol = 'BTCUSDT' 
ORDER BY bucket DESC 
LIMIT 5;
"
```

**Solutions :**
```bash
# 1. Rafraîchir les vues matérialisées
./scripts/refresh_indicators.sh

# 2. Vérifier la cohérence des données
docker exec trading_app_postgres psql -U postgres -d trading_app -c "
SELECT 
    k.symbol,
    k.timeframe,
    k.open_time,
    k.close_price,
    e.ema9,
    e.ema21
FROM klines k
LEFT JOIN mv_ema_5m e ON k.symbol = e.symbol 
    AND k.timeframe = e.timeframe 
    AND DATE_TRUNC('minute', k.open_time) = e.bucket
WHERE k.symbol = 'BTCUSDT' 
    AND k.timeframe = '5m'
ORDER BY k.open_time DESC 
LIMIT 10;
"
```

#### Problème : Valeurs NULL dans les indicateurs
```
Indicator values are NULL
```

**Solutions :**
```bash
# 1. Vérifier les données source
docker exec trading_app_postgres psql -U postgres -d trading_app -c "
SELECT 
    COUNT(*) as total_klines,
    COUNT(close_price) as non_null_prices,
    MIN(close_price) as min_price,
    MAX(close_price) as max_price
FROM klines 
WHERE symbol = 'BTCUSDT' AND timeframe = '5m';
"

# 2. Vérifier les calculs SQL
docker exec trading_app_postgres psql -U postgres -d trading_app -c "
SELECT 
    COUNT(*) as total_ema,
    COUNT(ema9) as non_null_ema9,
    COUNT(ema21) as non_null_ema21
FROM mv_ema_5m 
WHERE symbol = 'BTCUSDT';
"
```

### 5. Erreurs de mémoire

#### Problème : Consommation mémoire excessive
```
PHP Fatal error: Allowed memory size exhausted
```

**Solutions :**
```bash
# 1. Augmenter la limite de mémoire
# Dans php.ini ou .env
MEMORY_LIMIT=512M

# 2. Optimiser les requêtes
# Limiter le nombre de bougies traitées
$klines = array_slice($klines, -200); // Limiter à 200 bougies

# 3. Utiliser le mode SQL pour de gros volumes
# Dans config/trading.yml
indicator_calculation:
    mode: sql
```

### 6. Erreurs de logs

#### Problème : Logs manquants ou corrompus
```
Log files are not being written
```

**Solutions :**
```bash
# 1. Vérifier les permissions
docker exec trading_app_php ls -la var/log/

# 2. Vérifier la configuration des logs
docker exec trading_app_php cat config/packages/monolog.yaml

# 3. Tester les logs
docker exec trading_app_php bin/console app:test-indicator-calculation BTCUSDT 5m
tail -f var/log/prod.log | grep indicator
```

## 🔍 Outils de diagnostic

### Scripts de diagnostic

#### 1. Diagnostic complet
```bash
#!/bin/bash
# scripts/diagnose_indicator_system.sh

echo "🔍 Diagnostic du système d'indicateurs"
echo "======================================"

echo "1️⃣ Vérification des services"
docker exec trading_app_php bin/console debug:container | grep -i indicator

echo ""
echo "2️⃣ Vérification de la configuration"
docker exec trading_app_php bin/console debug:config trading

echo ""
echo "3️⃣ Vérification des vues matérialisées"
docker exec trading_app_postgres psql -U postgres -d trading_app -c "
SELECT 
    matviewname,
    matviewowner,
    ispopulated
FROM pg_matviews 
WHERE matviewname LIKE 'mv_%'
ORDER BY matviewname;
"

echo ""
echo "4️⃣ Vérification des données"
docker exec trading_app_postgres psql -U postgres -d trading_app -c "
SELECT 
    'klines' as table_name,
    COUNT(*) as records,
    MIN(open_time) as earliest,
    MAX(open_time) as latest
FROM klines 
WHERE timeframe = '5m'

UNION ALL

SELECT 
    'mv_ema_5m' as table_name,
    COUNT(*) as records,
    MIN(bucket) as earliest,
    MAX(bucket) as latest
FROM mv_ema_5m;
"

echo ""
echo "5️⃣ Test des performances"
docker exec trading_app_php bin/console app:test-indicator-calculation BTCUSDT 5m

echo ""
echo "✅ Diagnostic terminé"
```

#### 2. Monitoring des performances
```bash
#!/bin/bash
# scripts/monitor_indicator_performance.sh

echo "📊 Monitoring des performances d'indicateurs"
echo "==========================================="

# Test en mode SQL
echo "🔄 Test mode SQL..."
docker exec trading_app_php bash -c "
sed -i 's/mode: php/mode: sql/g' config/trading.yml
"

SQL_START=$(date +%s%3N)
docker exec trading_app_php bin/console app:test-indicator-calculation BTCUSDT 5m > /dev/null 2>&1
SQL_END=$(date +%s%3N)
SQL_DURATION=$((SQL_END - SQL_START))

# Test en mode PHP
echo "🔄 Test mode PHP..."
docker exec trading_app_php bash -c "
sed -i 's/mode: sql/mode: php/g' config/trading.yml
"

PHP_START=$(date +%s%3N)
docker exec trading_app_php bin/console app:test-indicator-calculation BTCUSDT 5m > /dev/null 2>&1
PHP_END=$(date +%s%3N)
PHP_DURATION=$((PHP_END - PHP_START))

echo "📈 Résultats:"
echo "  SQL: ${SQL_DURATION}ms"
echo "  PHP: ${PHP_DURATION}ms"

if [ $SQL_DURATION -lt $PHP_DURATION ]; then
    echo "  🏆 SQL plus rapide de $((PHP_DURATION - SQL_DURATION))ms"
else
    echo "  🏆 PHP plus rapide de $((SQL_DURATION - PHP_DURATION))ms"
fi

# Restaurer la configuration
docker exec trading_app_php bash -c "
sed -i 's/mode: php/mode: sql/g' config/trading.yml
"
```

### Commandes de diagnostic

#### 1. Vérification des services
```bash
# Lister tous les services d'indicateurs
docker exec trading_app_php bin/console debug:container | grep -i indicator

# Vérifier un service spécifique
docker exec trading_app_php bin/console debug:container IndicatorCalculationModeService

# Vérifier la configuration
docker exec trading_app_php bin/console debug:config trading
```

#### 2. Vérification de la base de données
```bash
# Vérifier les vues matérialisées
docker exec trading_app_postgres psql -U postgres -d trading_app -c "
SELECT 
    schemaname,
    matviewname,
    matviewowner,
    ispopulated
FROM pg_matviews 
WHERE matviewname LIKE 'mv_%';
"

# Vérifier les index
docker exec trading_app_postgres psql -U postgres -d trading_app -c "
SELECT 
    indexname,
    tablename,
    indexdef
FROM pg_indexes 
WHERE tablename LIKE 'mv_%';
"

# Vérifier les statistiques
docker exec trading_app_postgres psql -U postgres -d trading_app -c "
SELECT 
    schemaname,
    tablename,
    n_tup_ins,
    n_tup_upd,
    n_tup_del,
    last_analyze,
    last_autoanalyze
FROM pg_stat_user_tables 
WHERE tablename LIKE 'mv_%';
"
```

#### 3. Vérification des performances
```bash
# Test de performance
docker exec trading_app_php bin/console app:test-indicator-calculation BTCUSDT 5m

# Analyse des requêtes lentes
docker exec trading_app_postgres psql -U postgres -d trading_app -c "
SELECT 
    query,
    calls,
    total_time,
    mean_time,
    rows
FROM pg_stat_statements 
WHERE query LIKE '%mv_%'
ORDER BY mean_time DESC 
LIMIT 10;
"
```

## 📞 Support

### Escalade des problèmes

1. **Niveau 1** : Vérifier la documentation et les scripts de diagnostic
2. **Niveau 2** : Contacter l'équipe de développement
3. **Niveau 3** : Escalade vers l'équipe DevOps

### Contacts

- **Équipe Backend** : backend@trading-v3.com
- **Équipe DevOps** : devops@trading-v3.com
- **Support Urgent** : support@trading-v3.com

### Informations à fournir

Lors de la remontée d'un problème, fournir :

1. **Logs d'erreur** complets
2. **Configuration** actuelle (`trading.yml`)
3. **Résultats** des scripts de diagnostic
4. **Contexte** : symbole, timeframe, volume de données
5. **Reproduction** : étapes pour reproduire le problème

---

**Version :** 1.0  
**Dernière mise à jour :** 2025-01-15  
**Auteur :** Équipe Trading V3
