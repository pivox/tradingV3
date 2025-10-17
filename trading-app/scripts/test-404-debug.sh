#!/bin/bash

# Script de diagnostic pour le problème 404 depuis la page web
# Usage: ./scripts/test-404-debug.sh

set -e

echo "=========================================="
echo "Diagnostic du problème 404 depuis la page web"
echo "=========================================="
echo ""

# Test 1: Vérifier que le serveur répond
echo "Test 1: Vérification du serveur"
echo "----------------------------------------"
if curl -s -f "http://localhost:8082" > /dev/null; then
    echo "✅ Serveur accessible sur le port 8082"
else
    echo "❌ Serveur inaccessible sur le port 8082"
    exit 1
fi
echo ""

# Test 2: Vérifier la page web
echo "Test 2: Vérification de la page web"
echo "----------------------------------------"
if curl -s -f "http://localhost:8082/indicators/test" > /dev/null; then
    echo "✅ Page web accessible"
else
    echo "❌ Page web inaccessible"
    exit 1
fi
echo ""

# Test 3: Vérifier les routes Symfony
echo "Test 3: Vérification des routes Symfony"
echo "----------------------------------------"
ROUTES=$(cd /Users/haythem.mabrouk/workspace/perso/tradingV3/trading-app && php bin/console debug:router | grep indicators_revalidate)
if [ -n "$ROUTES" ]; then
    echo "✅ Route indicators_revalidate trouvée:"
    echo "$ROUTES"
else
    echo "❌ Route indicators_revalidate non trouvée"
fi
echo ""

# Test 4: Test direct de l'endpoint
echo "Test 4: Test direct de l'endpoint"
echo "----------------------------------------"
RESPONSE=$(curl -s -w "HTTP_STATUS:%{http_code}" -X POST "http://localhost:8082/indicators/revalidate" \
    -H "Content-Type: application/json" \
    -d '{"date": "2024-10-01", "contracts": "BTCUSDT", "timeframe": "1h"}')

HTTP_STATUS=$(echo "$RESPONSE" | grep -o "HTTP_STATUS:[0-9]*" | cut -d: -f2)
RESPONSE_BODY=$(echo "$RESPONSE" | sed 's/HTTP_STATUS:[0-9]*$//')

echo "Status HTTP: $HTTP_STATUS"
if [ "$HTTP_STATUS" = "200" ]; then
    echo "✅ Endpoint fonctionnel"
    SUCCESS=$(echo "$RESPONSE_BODY" | jq -r '.success // false' 2>/dev/null || echo "false")
    if [ "$SUCCESS" = "true" ]; then
        echo "✅ Réponse JSON valide"
    else
        echo "❌ Réponse JSON invalide"
        echo "Réponse: $RESPONSE_BODY"
    fi
else
    echo "❌ Endpoint défaillant (Status: $HTTP_STATUS)"
    echo "Réponse: $RESPONSE_BODY"
fi
echo ""

# Test 5: Test avec headers de navigateur
echo "Test 5: Test avec headers de navigateur"
echo "----------------------------------------"
BROWSER_RESPONSE=$(curl -s -w "HTTP_STATUS:%{http_code}" -X POST "http://localhost:8082/indicators/revalidate" \
    -H "Content-Type: application/json" \
    -H "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36" \
    -H "Referer: http://localhost:8082/indicators/test" \
    -H "Origin: http://localhost:8082" \
    -d '{"date": "2024-10-01", "contracts": "BTCUSDT", "timeframe": "1h"}')

BROWSER_HTTP_STATUS=$(echo "$BROWSER_RESPONSE" | grep -o "HTTP_STATUS:[0-9]*" | cut -d: -f2)
BROWSER_RESPONSE_BODY=$(echo "$BROWSER_RESPONSE" | sed 's/HTTP_STATUS:[0-9]*$//')

echo "Status HTTP avec headers navigateur: $BROWSER_HTTP_STATUS"
if [ "$BROWSER_HTTP_STATUS" = "200" ]; then
    echo "✅ Endpoint fonctionnel avec headers navigateur"
else
    echo "❌ Endpoint défaillant avec headers navigateur (Status: $BROWSER_HTTP_STATUS)"
    echo "Réponse: $BROWSER_RESPONSE_BODY"
fi
echo ""

# Test 6: Vérifier les logs Symfony
echo "Test 6: Vérification des logs Symfony"
echo "----------------------------------------"
if [ -f "/Users/haythem.mabrouk/workspace/perso/tradingV3/trading-app/var/log/dev.log" ]; then
    echo "📋 Dernières entrées dans dev.log:"
    tail -10 /Users/haythem.mabrouk/workspace/perso/tradingV3/trading-app/var/log/dev.log | grep -E "(ERROR|CRITICAL|404)" || echo "Aucune erreur récente trouvée"
else
    echo "⚠️  Fichier de log dev.log non trouvé"
fi
echo ""

# Test 7: Vérifier la configuration nginx
echo "Test 7: Vérification de la configuration nginx"
echo "----------------------------------------"
if [ -f "/Users/haythem.mabrouk/workspace/perso/tradingV3/nginx/trading-app.conf" ]; then
    echo "📋 Configuration nginx trouvée:"
    grep -A 5 -B 5 "location" /Users/haythem.mabrouk/workspace/perso/tradingV3/nginx/trading-app.conf || echo "Aucune configuration location trouvée"
else
    echo "⚠️  Configuration nginx non trouvée"
fi
echo ""

# Test 8: Test de l'URL complète
echo "Test 8: Test de l'URL complète"
echo "----------------------------------------"
FULL_URL_RESPONSE=$(curl -s -w "HTTP_STATUS:%{http_code}" -X POST "http://localhost:8082/indicators/revalidate" \
    -H "Content-Type: application/json" \
    -H "Accept: application/json" \
    -d '{"date": "2024-10-01", "contracts": "BTCUSDT", "timeframe": "1h"}')

FULL_URL_HTTP_STATUS=$(echo "$FULL_URL_RESPONSE" | grep -o "HTTP_STATUS:[0-9]*" | cut -d: -f2)
FULL_URL_RESPONSE_BODY=$(echo "$FULL_URL_RESPONSE" | sed 's/HTTP_STATUS:[0-9]*$//')

echo "Status HTTP URL complète: $FULL_URL_HTTP_STATUS"
if [ "$FULL_URL_HTTP_STATUS" = "200" ]; then
    echo "✅ URL complète fonctionnelle"
    echo "📊 Données retournées:"
    echo "$FULL_URL_RESPONSE_BODY" | jq -r '.data.global_summary' 2>/dev/null || echo "Erreur de parsing JSON"
else
    echo "❌ URL complète défaillante (Status: $FULL_URL_HTTP_STATUS)"
    echo "Réponse: $FULL_URL_RESPONSE_BODY"
fi
echo ""

echo "=========================================="
echo "Diagnostic terminé"
echo "=========================================="
echo ""
echo "🔍 Résumé du diagnostic:"
echo "  - Serveur: $(curl -s -f "http://localhost:8082" > /dev/null && echo "✅ OK" || echo "❌ KO")"
echo "  - Page web: $(curl -s -f "http://localhost:8082/indicators/test" > /dev/null && echo "✅ OK" || echo "❌ KO")"
echo "  - Route Symfony: $([ -n "$ROUTES" ] && echo "✅ OK" || echo "❌ KO")"
echo "  - Endpoint direct: $([ "$HTTP_STATUS" = "200" ] && echo "✅ OK" || echo "❌ KO")"
echo "  - Headers navigateur: $([ "$BROWSER_HTTP_STATUS" = "200" ] && echo "✅ OK" || echo "❌ KO")"
echo "  - URL complète: $([ "$FULL_URL_HTTP_STATUS" = "200" ] && echo "✅ OK" || echo "❌ KO")"
echo ""
echo "💡 Si tous les tests sont OK mais que la page web retourne 404:"
echo "  1. Vérifiez la console du navigateur (F12)"
echo "  2. Vérifiez les erreurs JavaScript"
echo "  3. Vérifiez que les contrats sont sélectionnés"
echo "  4. Vérifiez que la date est valide"
echo ""
echo "🌐 Page web: http://localhost:8082/indicators/test"
echo "🔗 Endpoint: http://localhost:8082/indicators/revalidate"

