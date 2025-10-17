#!/bin/bash

# Script de validation du système de logging asynchrone
# Usage: ./scripts/validate-async-logging.sh

set -e

echo "🔍 Validation du Système de Logging Asynchrone"
echo "=============================================="

VALIDATION_PASSED=true

# Fonction pour afficher les résultats
check_result() {
    if [ $1 -eq 0 ]; then
        echo "   ✅ $2"
    else
        echo "   ❌ $2"
        VALIDATION_PASSED=false
    fi
}

# Test 1: Vérifier que les services sont UP
echo "1. Vérification des services..."
docker-compose ps | grep -q "log-worker.*Up" && check_result 0 "Worker de logs: UP" || check_result 1 "Worker de logs: DOWN"
docker-compose ps | grep -q "trading-app-php.*Up" && check_result 0 "Application trading: UP" || check_result 1 "Application trading: DOWN"
docker-compose ps | grep -q "temporal.*Up" && check_result 0 "Temporal: UP" || check_result 1 "Temporal: DOWN"

# Test 2: Vérifier la connexion Temporal
echo ""
echo "2. Test de connexion Temporal..."
docker-compose exec trading-app-php php bin/console app:test-temporal > /dev/null 2>&1 && check_result 0 "Connexion Temporal: OK" || check_result 1 "Connexion Temporal: FAILED"

# Test 3: Test du système de logging
echo ""
echo "3. Test du système de logging..."
docker-compose exec trading-app-php php bin/console app:test-logging --count=10 > /dev/null 2>&1 && check_result 0 "Test de logging: OK" || check_result 1 "Test de logging: FAILED"

# Test 4: Vérifier les fichiers de logs
echo ""
echo "4. Vérification des fichiers de logs..."
sleep 2  # Attendre que les logs soient traités

LOG_FILES=("mtf.log" "signals.log" "positions.log" "indicators.log" "highconviction.log")
for log_file in "${LOG_FILES[@]}"; do
    if docker-compose exec trading-app-php test -f "/var/log/symfony/$log_file"; then
        check_result 0 "Fichier $log_file: Créé"
    else
        check_result 1 "Fichier $log_file: Manquant"
    fi
done

# Test 5: Vérifier le contenu des logs
echo ""
echo "5. Vérification du contenu des logs..."
if docker-compose exec trading-app-php test -s "/var/log/symfony/signals.log"; then
    check_result 0 "Contenu des logs: OK"
else
    check_result 1 "Contenu des logs: Vide"
fi

# Test 6: Test de performance
echo ""
echo "6. Test de performance..."
PERF_OUTPUT=$(docker-compose exec trading-app-php php bin/console app:test-logging --count=100 2>&1)
if echo "$PERF_OUTPUT" | grep -q "Test terminé avec succès"; then
    check_result 0 "Performance: OK"
    
    # Extraire les métriques
    LOGS_PER_SEC=$(echo "$PERF_OUTPUT" | grep "Débit:" | grep -o '[0-9]\+' | head -1)
    if [ -n "$LOGS_PER_SEC" ] && [ "$LOGS_PER_SEC" -gt 500 ]; then
        check_result 0 "Débit: $LOGS_PER_SEC logs/s (>500)"
    else
        check_result 1 "Débit: $LOGS_PER_SEC logs/s (<500)"
    fi
else
    check_result 1 "Performance: FAILED"
fi

# Test 7: Vérifier Temporal UI
echo ""
echo "7. Test d'accès à Temporal UI..."
if curl -s http://localhost:8233 > /dev/null 2>&1; then
    check_result 0 "Temporal UI: Accessible"
else
    check_result 1 "Temporal UI: Inaccessible"
fi

# Test 8: Vérifier Grafana
echo ""
echo "8. Test d'accès à Grafana..."
if curl -s http://localhost:3001 > /dev/null 2>&1; then
    check_result 0 "Grafana: Accessible"
else
    check_result 1 "Grafana: Inaccessible"
fi

# Résumé final
echo ""
echo "=============================================="
if [ "$VALIDATION_PASSED" = true ]; then
    echo "🎉 VALIDATION RÉUSSIE !"
    echo ""
    echo "✅ Le système de logging asynchrone fonctionne correctement"
    echo "✅ Tous les tests sont passés"
    echo "✅ Prêt pour la production"
    echo ""
    echo "📊 Services disponibles:"
    echo "   - Application: http://localhost:8082"
    echo "   - Temporal UI: http://localhost:8233"
    echo "   - Grafana: http://localhost:3001"
    echo ""
    echo "🔧 Commandes utiles:"
    echo "   - Test: docker-compose exec trading-app-php php bin/console app:test-logging"
    echo "   - Logs: docker-compose logs log-worker -f"
    echo "   - Statut: docker-compose ps"
    
    exit 0
else
    echo "❌ VALIDATION ÉCHOUÉE !"
    echo ""
    echo "❌ Certains tests ont échoué"
    echo "❌ Vérifiez les logs et la configuration"
    echo ""
    echo "🔧 Commandes de diagnostic:"
    echo "   - Logs worker: docker-compose logs log-worker"
    echo "   - Logs app: docker-compose logs trading-app-php"
    echo "   - Statut: docker-compose ps"
    echo "   - Test: docker-compose exec trading-app-php php bin/console app:test-logging"
    
    exit 1
fi


