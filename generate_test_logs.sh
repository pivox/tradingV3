#!/bin/bash

echo "=== Génération de logs de test pour Grafana ==="
echo ""

# Créer le répertoire de logs s'il n'existe pas
mkdir -p /Users/haythem.mabrouk/workspace/perso/tradingV3/trading-app/var/log

# Générer des logs de test
LOG_FILE="/Users/haythem.mabrouk/workspace/perso/tradingV3/trading-app/var/log/signals-$(date +%Y-%m-%d).log"

echo "Génération de logs dans : $LOG_FILE"
echo ""

# Générer des logs de signaux de trading
for i in {1..50}; do
    TIMESTAMP=$(date -u +"%Y-%m-%dT%H:%M:%S.%3NZ")
    SYMBOL="BTCUSDT"
    SIDE="BUY"
    PRICE=$(echo "scale=2; 45000 + $RANDOM % 1000" | bc)
    QUANTITY=$(echo "scale=4; 0.001 + $RANDOM % 100 / 10000" | bc)
    
    echo "[$TIMESTAMP] [INFO] [SIGNALS] [BTCUSDT] [1m] Signal détecté: $SIDE à $PRICE (quantité: $QUANTITY)" >> "$LOG_FILE"
    
    # Varier les symboles et côtés
    if [ $((i % 3)) -eq 0 ]; then
        SYMBOL="ETHUSDT"
        SIDE="SELL"
        PRICE=$(echo "scale=2; 3000 + $RANDOM % 200" | bc)
        echo "[$TIMESTAMP] [INFO] [SIGNALS] [ETHUSDT] [5m] Signal détecté: $SIDE à $PRICE (quantité: $QUANTITY)" >> "$LOG_FILE"
    fi
    
    if [ $((i % 5)) -eq 0 ]; then
        echo "[$TIMESTAMP] [ERROR] [VALIDATION] [BTCUSDT] Erreur de validation des données" >> "$LOG_FILE"
    fi
    
    if [ $((i % 7)) -eq 0 ]; then
        echo "[$TIMESTAMP] [WARNING] [POSITIONS] [ETHUSDT] Position en attente de confirmation" >> "$LOG_FILE"
    fi
    
    sleep 0.1
done

echo "✅ Logs de test générés avec succès !"
echo "📊 Vous pouvez maintenant voir les données dans Grafana :"
echo "   - Dashboard Test Simple : http://localhost:3001/d/test-simple/dashboard-test-simple"
echo "   - Trading App - Logs Dashboard : http://localhost:3001/d/trading-app-logs/trading-app-logs-dashboard"
echo ""
echo "🔍 Les logs incluent :"
echo "   - Signaux de trading (BUY/SELL)"
echo "   - Erreurs de validation"
echo "   - Warnings de positions"
echo "   - Différents symboles (BTCUSDT, ETHUSDT)"
echo ""
echo "⏱️  Attendez 1-2 minutes pour que Promtail collecte les logs et les envoie à Loki"
