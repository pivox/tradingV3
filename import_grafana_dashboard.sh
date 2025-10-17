#!/bin/bash

echo "=== Importation des Dashboards Grafana ==="
echo ""
echo "Les dashboards sont déjà provisionnés automatiquement dans Grafana."
echo "Voici comment y accéder :"
echo ""
echo "1. Ouvrez votre navigateur et allez à : http://localhost:3001"
echo ""
echo "2. Connectez-vous avec les credentials par défaut :"
echo "   - Utilisateur : admin"
echo "   - Mot de passe : admin"
echo ""
echo "3. Si les credentials par défaut ne fonctionnent pas, essayez :"
echo "   - Utilisateur : admin"
echo "   - Mot de passe : (vide)"
echo ""
echo "4. Une fois connecté, vous devriez voir les dashboards dans le dossier 'Trading App' :"
echo "   - Trading App - Logs Dashboard"
echo "   - Dashboard Logs - Version Propre"
echo "   - Dashboard Monitoring - Erreurs"
echo "   - Dashboard Debug - Logs"
echo "   - Dashboard Logs Simple"
echo ""
echo "5. Si vous ne voyez pas les dashboards, vérifiez que :"
echo "   - Le datasource Loki est configuré (UID: loki)"
echo "   - Les logs sont bien envoyés vers Loki"
echo ""
echo "=== Vérification du statut des services ==="
echo ""

# Vérifier que Grafana est en cours d'exécution
if docker ps | grep -q grafana; then
    echo "✅ Grafana est en cours d'exécution"
else
    echo "❌ Grafana n'est pas en cours d'exécution"
    echo "   Démarrez-le avec : docker-compose up -d grafana"
fi

# Vérifier que Loki est en cours d'exécution
if docker ps | grep -q loki; then
    echo "✅ Loki est en cours d'exécution"
else
    echo "❌ Loki n'est pas en cours d'exécution"
    echo "   Démarrez-le avec : docker-compose up -d loki"
fi

# Vérifier que Promtail est en cours d'exécution
if docker ps | grep -q promtail; then
    echo "✅ Promtail est en cours d'exécution"
else
    echo "❌ Promtail n'est pas en cours d'exécution"
    echo "   Démarrez-le avec : docker-compose up -d promtail"
fi

echo ""
echo "=== URLs utiles ==="
echo "Grafana : http://localhost:3001"
echo "Loki API : http://localhost:3100"
echo ""
echo "=== Dashboards disponibles ==="
echo "Les dashboards suivants sont provisionnés :"
for file in /Users/haythem.mabrouk/workspace/perso/tradingV3/monitoring/grafana-dashboards/*.json; do
    if [[ ! "$file" == *".bak"* ]]; then
        filename=$(basename "$file")
        echo "  - $filename"
    fi
done

echo ""
echo "Pour importer manuellement un dashboard :"
echo "1. Allez dans Grafana > + > Import"
echo "2. Copiez le contenu du fichier JSON"
echo "3. Collez-le dans l'interface d'importation"
echo "4. Cliquez sur 'Load' puis 'Import'"
