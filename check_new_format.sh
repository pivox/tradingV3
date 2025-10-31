#!/bin/bash

echo "🔍 Vérification du nouveau format MTF"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

cd "$(dirname "$0")"

# Check schedule status
echo "📅 Statut du schedule Temporal:"
docker exec cron_symfony_mtf_workers python3 scripts/new/manage_mtf_workers_schedule.py status 2>&1 | grep -E "(paused|num_actions|recent_actions)" | head -5
echo ""

# Check service status
echo "🔧 Statut du service:"
docker-compose ps cron-symfony-mtf-workers
echo ""

# Check recent logs
echo "📋 Logs récents (30 dernières lignes):"
docker-compose logs --tail=30 --since=5m cron-symfony-mtf-workers
echo ""

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "⏰ Heure actuelle: $(date '+%H:%M:%S')"
echo "📍 Prochain run normalement à: $(date -u -v+1M '+%H:%M'):00 UTC"
echo ""
echo "💡 Pour surveiller en temps réel:"
echo "   docker-compose logs -f cron-symfony-mtf-workers"
echo ""
echo "💡 Pour forcer un run manuel (test):"
echo "   # Ouvrir Temporal UI → http://localhost:8233"
echo "   # Start Workflow → CronSymfonyMtfWorkersWorkflow"


