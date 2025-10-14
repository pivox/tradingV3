#!/bin/bash

# Script de démarrage de la stack de logging
# Système de Logging Multi-Canaux (Symfony + Grafana/Loki)

set -e

echo "🚀 Démarrage de la stack de logging multi-canaux..."

# Vérification des prérequis
echo "📋 Vérification des prérequis..."

if ! command -v docker &> /dev/null; then
    echo "❌ Docker n'est pas installé"
    exit 1
fi

if ! command -v docker-compose &> /dev/null; then
    echo "❌ Docker Compose n'est pas installé"
    exit 1
fi

# Création des répertoires de logs si nécessaire
echo "📁 Création des répertoires de logs..."
mkdir -p trading-app/var/log
mkdir -p symfony-app/var/log

# Démarrage de la stack de logging
echo "🐳 Démarrage des services de logging..."

# Démarrage de Loki
echo "  - Démarrage de Loki..."
docker-compose up -d loki

# Attendre que Loki soit prêt
echo "  - Attente que Loki soit prêt..."
sleep 10

# Démarrage de Promtail
echo "  - Démarrage de Promtail..."
docker-compose up -d promtail

# Démarrage de Grafana
echo "  - Démarrage de Grafana..."
docker-compose up -d grafana

# Attendre que Grafana soit prêt
echo "  - Attente que Grafana soit prêt..."
sleep 15

# Vérification de l'état des services
echo "🔍 Vérification de l'état des services..."

services=("loki" "promtail" "grafana")
for service in "${services[@]}"; do
    if docker-compose ps $service | grep -q "Up"; then
        echo "  ✅ $service est en cours d'exécution"
    else
        echo "  ❌ $service n'est pas en cours d'exécution"
    fi
done

# Affichage des URLs d'accès
echo ""
echo "🌐 URLs d'accès :"
echo "  - Grafana Dashboard: http://localhost:3000 (admin/admin)"
echo "  - Loki API: http://localhost:3100"
echo "  - Trading App: http://localhost:8082"
echo ""

# Test de génération de logs
echo "🧪 Test de génération de logs..."
if [ -f "trading-app/bin/console" ]; then
    echo "  - Génération d'exemples de logs..."
    cd trading-app
    php bin/console app:test-logging --count=2
    cd ..
    echo "  ✅ Exemples de logs générés"
else
    echo "  ⚠️  Console Symfony non trouvée, test de logs ignoré"
fi

echo ""
echo "✅ Stack de logging démarrée avec succès !"
echo ""
echo "📊 Prochaines étapes :"
echo "  1. Accédez à Grafana : http://localhost:3000"
echo "  2. Connectez-vous avec admin/admin"
echo "  3. Explorez le dashboard 'Trading App - Logs Dashboard'"
echo "  4. Testez les requêtes de logs par canal"
echo ""
echo "🔧 Commandes utiles :"
echo "  - Arrêter la stack : docker-compose down"
echo "  - Voir les logs : docker-compose logs -f [service]"
echo "  - Tester le logging : cd trading-app && php bin/console app:test-logging"
echo ""
