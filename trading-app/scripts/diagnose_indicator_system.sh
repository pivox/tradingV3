#!/bin/bash

# Script de diagnostic du système d'indicateurs
# Usage: ./scripts/diagnose_indicator_system.sh

set -e

echo "🔍 Diagnostic du système d'indicateurs"
echo "======================================"
echo ""

# Fonction pour exécuter une commande doctrine
run_doctrine_cmd() {
    docker exec trading_app_php bin/console "$@"
}

# Fonction pour exécuter une commande psql
run_psql_cmd() {
    docker exec trading_app_postgres psql -U postgres -d trading_app -t -c "$@"
}

echo "1️⃣ Vérification des services Symfony"
echo "-----------------------------------"
echo "Services d'indicateurs disponibles :"
run_doctrine_cmd debug:container | grep -i indicator | head -10

echo ""
echo "2️⃣ Vérification de la configuration"
echo "----------------------------------"
echo "Configuration du système de switch :"
if [ -f "config/trading.yml" ]; then
    grep -A 10 "indicator_calculation:" config/trading.yml || echo "  ❌ Section indicator_calculation non trouvée"
else
    echo "  ❌ Fichier config/trading.yml non trouvé"
fi

echo ""
echo "3️⃣ Vérification des vues matérialisées"
echo "-------------------------------------"
echo "Vues matérialisées disponibles :"
run_psql_cmd "
SELECT 
    matviewname,
    matviewowner,
    ispopulated,
    CASE 
        WHEN ispopulated THEN '✅' 
        ELSE '❌' 
    END as status
FROM pg_matviews 
WHERE matviewname LIKE 'mv_%'
ORDER BY matviewname;
"

echo ""
echo "4️⃣ Vérification des données"
echo "--------------------------"
echo "Données dans les tables :"
run_psql_cmd "
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
FROM mv_ema_5m

UNION ALL

SELECT 
    'mv_rsi14_5m' as table_name,
    COUNT(*) as records,
    MIN(bucket) as earliest,
    MAX(bucket) as latest
FROM mv_rsi14_5m

UNION ALL

SELECT 
    'mv_macd_5m' as table_name,
    COUNT(*) as records,
    MIN(bucket) as earliest,
    MAX(bucket) as latest
FROM mv_macd_5m

ORDER BY table_name;
"

echo ""
echo "5️⃣ Vérification des index"
echo "-------------------------"
echo "Index sur les vues matérialisées :"
run_psql_cmd "
SELECT 
    indexname,
    tablename,
    indexdef
FROM pg_indexes 
WHERE tablename LIKE 'mv_%'
ORDER BY tablename, indexname;
"

echo ""
echo "6️⃣ Test des performances"
echo "-----------------------"
echo "Test du système de switch :"
if run_doctrine_cmd app:test-indicator-calculation BTCUSDT 5m > /dev/null 2>&1; then
    echo "  ✅ Commande de test disponible"
    
    # Test en mode SQL
    echo "  🔄 Test mode SQL..."
    docker exec trading_app_php bash -c "
    sed -i 's/mode: php/mode: sql/g' config/trading.yml
    " > /dev/null 2>&1
    
    SQL_OUTPUT=$(run_doctrine_cmd app:test-indicator-calculation BTCUSDT 5m 2>&1 || echo "Erreur")
    if echo "$SQL_OUTPUT" | grep -q "SQL calculation took"; then
        SQL_DURATION=$(echo "$SQL_OUTPUT" | grep -o "SQL calculation took [0-9]*ms" | grep -o "[0-9]*")
        echo "    ✅ Mode SQL fonctionnel (${SQL_DURATION}ms)"
    else
        echo "    ❌ Mode SQL non fonctionnel"
    fi
    
    # Test en mode PHP
    echo "  🔄 Test mode PHP..."
    docker exec trading_app_php bash -c "
    sed -i 's/mode: sql/mode: php/g' config/trading.yml
    " > /dev/null 2>&1
    
    PHP_OUTPUT=$(run_doctrine_cmd app:test-indicator-calculation BTCUSDT 5m 2>&1 || echo "Erreur")
    if echo "$PHP_OUTPUT" | grep -q "PHP calculation took"; then
        PHP_DURATION=$(echo "$PHP_OUTPUT" | grep -o "PHP calculation took [0-9]*ms" | grep -o "[0-9]*")
        echo "    ✅ Mode PHP fonctionnel (${PHP_DURATION}ms)"
    else
        echo "    ❌ Mode PHP non fonctionnel"
    fi
    
    # Restaurer la configuration
    docker exec trading_app_php bash -c "
    sed -i 's/mode: php/mode: sql/g' config/trading.yml
    " > /dev/null 2>&1
    
else
    echo "  ❌ Commande de test non disponible"
fi

echo ""
echo "7️⃣ Vérification des logs"
echo "-----------------------"
echo "Dernières erreurs dans les logs :"
if [ -f "var/log/prod.log" ]; then
    echo "  📄 Fichier de log trouvé"
    ERROR_COUNT=$(grep -c "ERROR" var/log/prod.log 2>/dev/null || echo "0")
    WARNING_COUNT=$(grep -c "WARNING" var/log/prod.log 2>/dev/null || echo "0")
    echo "  📊 Erreurs: $ERROR_COUNT, Avertissements: $WARNING_COUNT"
    
    if [ "$ERROR_COUNT" -gt 0 ]; then
        echo "  🔍 Dernières erreurs :"
        grep "ERROR" var/log/prod.log | tail -3 | sed 's/^/    /'
    fi
else
    echo "  ❌ Fichier de log non trouvé"
fi

echo ""
echo "8️⃣ Vérification des permissions"
echo "------------------------------"
echo "Permissions des fichiers critiques :"
if [ -f "config/trading.yml" ]; then
    PERMS=$(ls -la config/trading.yml | awk '{print $1}')
    echo "  📄 config/trading.yml: $PERMS"
fi

if [ -d "var/log" ]; then
    PERMS=$(ls -la var/log/ | head -1 | awk '{print $1}')
    echo "  📁 var/log/: $PERMS"
fi

echo ""
echo "9️⃣ Résumé du diagnostic"
echo "----------------------"

# Compter les problèmes
ISSUES=0

# Vérifier les services
if ! run_doctrine_cmd debug:container | grep -q "IndicatorCalculationModeService"; then
    echo "  ❌ Service IndicatorCalculationModeService non trouvé"
    ISSUES=$((ISSUES + 1))
fi

# Vérifier la configuration
if ! grep -q "indicator_calculation:" config/trading.yml 2>/dev/null; then
    echo "  ❌ Configuration indicator_calculation manquante"
    ISSUES=$((ISSUES + 1))
fi

# Vérifier les vues matérialisées
MV_COUNT=$(run_psql_cmd "SELECT COUNT(*) FROM pg_matviews WHERE matviewname LIKE 'mv_%';" | tr -d ' ')
if [ "$MV_COUNT" -lt 3 ]; then
    echo "  ❌ Vues matérialisées manquantes (trouvé: $MV_COUNT, attendu: 3+)"
    ISSUES=$((ISSUES + 1))
fi

# Vérifier les données
DATA_COUNT=$(run_psql_cmd "SELECT COUNT(*) FROM mv_ema_5m;" | tr -d ' ')
if [ "$DATA_COUNT" -eq 0 ]; then
    echo "  ❌ Aucune donnée dans les vues matérialisées"
    ISSUES=$((ISSUES + 1))
fi

# Vérifier la commande de test
if ! run_doctrine_cmd app:test-indicator-calculation BTCUSDT 5m > /dev/null 2>&1; then
    echo "  ❌ Commande de test non fonctionnelle"
    ISSUES=$((ISSUES + 1))
fi

echo ""
if [ $ISSUES -eq 0 ]; then
    echo "✅ Diagnostic terminé - Aucun problème détecté"
    echo "🎉 Le système d'indicateurs est opérationnel"
else
    echo "⚠️  Diagnostic terminé - $ISSUES problème(s) détecté(s)"
    echo "🔧 Consultez la documentation de dépannage pour résoudre ces problèmes"
fi

echo ""
echo "📚 Ressources utiles :"
echo "  • Documentation: docs/INDICATOR_SWITCH_SYSTEM.md"
echo "  • Dépannage: docs/TROUBLESHOOTING_INDICATOR_SWITCH.md"
echo "  • API: docs/API_REFERENCE_INDICATOR_SWITCH.md"
echo ""
echo "🆘 Support :"
echo "  • Équipe Backend: backend@trading-v3.com"
echo "  • Équipe DevOps: devops@trading-v3.com"
