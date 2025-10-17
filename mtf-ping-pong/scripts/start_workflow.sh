#!/bin/bash

# Script pour démarrer le workflow MTF Ping-Pong

set -e

echo "🚀 Démarrage du workflow MTF Ping-Pong"
echo "📅 $(date)"

# Vérifier que le container est en cours d'exécution
if ! docker ps | grep -q "mtf_ping_pong_worker"; then
    echo "❌ Le worker MTF Ping-Pong n'est pas en cours d'exécution"
    echo "💡 Démarrez d'abord le worker avec: docker-compose up mtf-ping-pong-worker"
    exit 1
fi

echo "✅ Worker MTF Ping-Pong détecté"

# Démarrer le workflow
echo "🔄 Démarrage du workflow..."
docker exec mtf_ping_pong_worker python start_workflow.py start

echo "✅ Workflow MTF Ping-Pong démarré avec succès"
echo "📊 Vous pouvez surveiller les logs avec: docker logs -f mtf_ping_pong_worker"








