#!/bin/bash

# Script d'entrée pour le container MTF Ping-Pong

echo "🚀 Démarrage du service MTF Ping-Pong"
echo "📅 $(date)"
echo "🔧 Configuration:"
echo "   - Temporal Address: ${TEMPORAL_ADDRESS:-temporal:7233}"
echo "   - Namespace: ${TEMPORAL_NAMESPACE:-default}"
echo "   - Task Queue: ${TASK_QUEUE_NAME:-mtf-ping-pong-queue}"
echo "   - Worker Identity: ${WORKER_IDENTITY:-mtf-ping-pong-worker}"

# Attendre que Temporal soit disponible
echo "⏳ Attente de la disponibilité de Temporal..."
until curl -f http://temporal:8080/api/v1/namespaces 2>/dev/null; do
    echo "   Temporal non disponible, attente..."
    sleep 5
done

echo "✅ Temporal disponible, démarrage du worker..."

# Démarrer le worker
exec python worker.py








