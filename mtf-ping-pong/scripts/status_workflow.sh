#!/bin/bash

# Script pour vérifier le statut du workflow MTF Ping-Pong

set -e

echo "📊 Statut du workflow MTF Ping-Pong"
echo "📅 $(date)"

# Vérifier les arguments
if [ $# -eq 0 ]; then
    echo "❌ Usage: $0 <workflow_id>"
    echo "💡 Pour trouver l'ID du workflow, consultez les logs du worker"
    exit 1
fi

WORKFLOW_ID=$1

# Vérifier que le container est en cours d'exécution
if ! docker ps | grep -q "mtf_ping_pong_worker"; then
    echo "❌ Le worker MTF Ping-Pong n'est pas en cours d'exécution"
    exit 1
fi

echo "✅ Worker MTF Ping-Pong détecté"
echo "🔄 Vérification du statut du workflow: $WORKFLOW_ID"

# Vérifier le statut du workflow
docker exec mtf_ping_pong_worker python start_workflow.py status "$WORKFLOW_ID"

echo "✅ Statut récupéré avec succès"








