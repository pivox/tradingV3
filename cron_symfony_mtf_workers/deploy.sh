#!/bin/bash
set -e

echo "🚀 Déploiement du worker cron-symfony-mtf-workers"
echo ""

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Navigate to project root
cd "$(dirname "$0")/.."

echo "📦 Build de l'image Docker..."
docker-compose build cron-symfony-mtf-workers

echo ""
echo "🔄 Redémarrage du service..."
docker-compose up -d cron-symfony-mtf-workers

echo ""
echo "⏳ Attente de 5 secondes pour stabilisation..."
sleep 5

echo ""
echo -e "${GREEN}✅ Déploiement terminé !${NC}"
echo ""
echo "📋 Pour voir les logs :"
echo -e "${YELLOW}docker-compose logs -f cron-symfony-mtf-workers${NC}"
echo ""
echo "📊 Pour voir le statut du service :"
echo -e "${YELLOW}docker-compose ps cron-symfony-mtf-workers${NC}"
echo ""
echo "🔍 Pour accéder à l'UI Temporal :"
echo -e "${YELLOW}http://localhost:8233${NC}"

