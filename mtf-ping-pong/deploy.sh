#!/bin/bash

# Script de déploiement pour le workflow MTF Ping-Pong

set -e

echo "🚀 Déploiement du workflow MTF Ping-Pong"
echo "📅 $(date)"
echo ""

# Vérifier que nous sommes dans le bon répertoire
if [ ! -f "Dockerfile" ]; then
    echo "❌ Erreur: Ce script doit être exécuté depuis le répertoire mtf-ping-pong"
    exit 1
fi

echo "✅ Répertoire de travail correct"

# Construire l'image Docker
echo "🔨 Construction de l'image Docker..."
docker build -t mtf-ping-pong:latest .

if [ $? -eq 0 ]; then
    echo "✅ Image Docker construite avec succès"
else
    echo "❌ Erreur lors de la construction de l'image Docker"
    exit 1
fi

# Vérifier que docker-compose est disponible
if ! command -v docker-compose &> /dev/null; then
    echo "❌ docker-compose n'est pas installé"
    exit 1
fi

echo "✅ docker-compose disponible"

# Démarrer le service
echo "🚀 Démarrage du service..."
cd ..
docker-compose up -d mtf-ping-pong-worker

if [ $? -eq 0 ]; then
    echo "✅ Service démarré avec succès"
else
    echo "❌ Erreur lors du démarrage du service"
    exit 1
fi

# Attendre que le service soit prêt
echo "⏳ Attente que le service soit prêt..."
sleep 10

# Vérifier que le container est en cours d'exécution
if docker ps | grep -q "mtf_ping_pong_worker"; then
    echo "✅ Container en cours d'exécution"
else
    echo "❌ Container non trouvé"
    exit 1
fi

# Vérifier la santé du service
echo "🏥 Vérification de la santé du service..."
sleep 5

if docker exec mtf_ping_pong_worker python -c "from activities.mtf_activities import health_check_activity; print('OK')" 2>/dev/null; then
    echo "✅ Service en bonne santé"
else
    echo "⚠️  Service démarré mais vérification de santé échouée"
fi

echo ""
echo "🎉 Déploiement terminé avec succès!"
echo ""
echo "📋 Commandes utiles:"
echo "   - Voir les logs: docker logs -f mtf_ping_pong_worker"
echo "   - Démarrer un workflow: ./scripts/start_workflow.sh"
echo "   - Arrêter le service: docker-compose down mtf-ping-pong-worker"
echo "   - Tester le service: docker exec mtf_ping_pong_worker python test_workflow.py"
echo ""
echo "🔗 Interface Temporal UI: http://localhost:8233"








