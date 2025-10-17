#!/bin/bash

# Script de démarrage rapide pour le système de logging asynchrone
# Usage: ./quick-start-async-logging.sh

set -e

echo "🚀 Démarrage Rapide - Système de Logging Asynchrone"
echo "=================================================="

# Vérifier que Docker est en cours d'exécution
if ! docker info > /dev/null 2>&1; then
    echo "❌ Docker n'est pas en cours d'exécution"
    echo "   Veuillez démarrer Docker et réessayer"
    exit 1
fi

# Démarrer les services nécessaires
echo "📦 Démarrage des services..."
docker-compose up -d temporal postgresql
echo "   ⏳ Attente du démarrage de Temporal..."
sleep 15

# Démarrer le worker de logs
echo "🔧 Démarrage du worker de logs..."
docker-compose up -d log-worker
sleep 5

# Démarrer l'application
echo "🏃 Démarrage de l'application..."
docker-compose up -d trading-app-php
sleep 5

# Test rapide
echo "🧪 Test rapide du système..."
docker-compose exec trading-app-php php bin/console app:test-logging --count=20

# Validation
echo "🔍 Validation du système..."
./scripts/validate-async-logging.sh

echo ""
echo "🎉 Système de logging asynchrone démarré avec succès !"
echo ""
echo "📊 Accès aux services:"
echo "   - Application: http://localhost:8082"
echo "   - Temporal UI: http://localhost:8233"
echo "   - Grafana: http://localhost:3001"
echo ""
echo "🔧 Commandes utiles:"
echo "   - Test: docker-compose exec trading-app-php php bin/console app:test-logging"
echo "   - Logs: docker-compose logs log-worker -f"
echo "   - Statut: docker-compose ps"
echo ""
echo "📚 Documentation:"
echo "   - Guide complet: ./ASYNC_LOGGING_README.md"
echo "   - Migration: ./trading-app/LOGGING_MIGRATION_GUIDE.md"


