#!/bin/bash

# Script de déploiement du système de logging asynchrone
# Usage: ./scripts/deploy-async-logging.sh

set -e

echo "🚀 Déploiement du Système de Logging Asynchrone"
echo "================================================"

# Vérifier que nous sommes dans le bon répertoire
if [ ! -f "docker-compose.yml" ]; then
    echo "❌ Erreur: Ce script doit être exécuté depuis la racine du projet"
    exit 1
fi

echo "📋 Étapes de déploiement:"
echo "1. Arrêt des services existants"
echo "2. Mise à jour de la configuration"
echo "3. Redémarrage des services"
echo "4. Test du système"
echo ""

# Étape 1: Arrêt des services
echo "🛑 Arrêt des services existants..."
docker-compose stop trading-app-php mtf-worker

# Étape 2: Mise à jour de la configuration
echo "⚙️  Mise à jour de la configuration..."
# La configuration a déjà été mise à jour dans les fichiers

# Étape 3: Redémarrage des services
echo "🔄 Redémarrage des services..."
echo "   - Démarrage du worker de logs..."
docker-compose up -d log-worker

echo "   - Démarrage de l'application trading..."
docker-compose up -d trading-app-php

echo "   - Démarrage du worker MTF..."
docker-compose up -d mtf-worker

# Attendre que les services soient prêts
echo "⏳ Attente du démarrage des services..."
sleep 10

# Étape 4: Test du système
echo "🧪 Test du système de logging..."

# Vérifier que le worker de logs fonctionne
echo "   - Vérification du worker de logs..."
if docker-compose ps log-worker | grep -q "Up"; then
    echo "   ✅ Worker de logs: OK"
else
    echo "   ❌ Worker de logs: ERREUR"
    docker-compose logs log-worker --tail 20
    exit 1
fi

# Vérifier que l'application fonctionne
echo "   - Vérification de l'application..."
if docker-compose ps trading-app-php | grep -q "Up"; then
    echo "   ✅ Application trading: OK"
else
    echo "   ❌ Application trading: ERREUR"
    docker-compose logs trading-app-php --tail 20
    exit 1
fi

# Test du système de logging
echo "   - Test du système de logging..."
docker-compose exec trading-app-php php bin/console app:test-logging --count=50

echo ""
echo "🎉 Déploiement terminé avec succès !"
echo ""
echo "📊 Services disponibles:"
echo "   - Application: http://localhost:8082"
echo "   - Temporal UI: http://localhost:8233"
echo "   - Grafana: http://localhost:3001"
echo ""
echo "🔧 Commandes utiles:"
echo "   - Test logging: docker-compose exec trading-app-php php bin/console app:test-logging"
echo "   - Logs worker: docker-compose logs log-worker -f"
echo "   - Statut services: docker-compose ps"
echo ""
echo "📈 Monitoring:"
echo "   - Temporal UI: http://localhost:8233 (queue: log-processing-queue)"
echo "   - Grafana: http://localhost:3001 (admin/admin)"


