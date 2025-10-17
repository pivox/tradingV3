#!/bin/bash

echo "🧪 Test du Système de Dispatcher WebSocket"
echo "=========================================="

# Test 1: Vérifier que la page WebSocket se charge
echo "📄 Test 1: Chargement de la page WebSocket..."
if curl -s -o /dev/null -w "%{http_code}" http://localhost:8082/websocket | grep -q "200"; then
    echo "✅ Page WebSocket accessible (HTTP 200)"
else
    echo "❌ Page WebSocket non accessible"
    exit 1
fi

# Test 2: Vérifier l'endpoint des assignations
echo "📊 Test 2: Endpoint des assignations..."
response=$(curl -s http://localhost:8082/ws/assignments)
if echo "$response" | grep -q '"ok":true'; then
    echo "✅ Endpoint des assignations fonctionnel"
    workers_count=$(echo "$response" | grep -o '"workers":\[.*\]' | grep -o 'tradingv3-ws-worker' | wc -l)
    echo "   📋 $workers_count workers configurés"
else
    echo "❌ Endpoint des assignations défaillant: $response"
    exit 1
fi

# Test 3: Test du dispatch par hash
echo "🔀 Test 3: Dispatch par hash (dry-run)..."
response=$(curl -s -X POST http://localhost:8082/ws/dispatch \
    -H "Content-Type: application/json" \
    -d '{"symbols":["BTCUSDT","ETHUSDT","ADAUSDT"],"strategy":"hash","timeframes":["1m","5m"],"live":false}')

if echo "$response" | grep -q '"ok":true'; then
    echo "✅ Dispatch par hash fonctionnel"
    echo "   📋 Assignations: $(echo "$response" | grep -o '"assignments":{[^}]*}')"
else
    echo "❌ Dispatch par hash défaillant: $response"
fi

# Test 4: Test du dispatch équilibré
echo "⚖️  Test 4: Dispatch équilibré (least-loaded)..."
response=$(curl -s -X POST http://localhost:8082/ws/dispatch \
    -H "Content-Type: application/json" \
    -d '{"symbols":["SOLUSDT","DOTUSDT","LINKUSDT","UNIUSDT"],"strategy":"least","capacity":2,"timeframes":["1m","5m"],"live":false}')

if echo "$response" | grep -q '"ok":true'; then
    echo "✅ Dispatch équilibré fonctionnel"
    echo "   📋 Assignations: $(echo "$response" | grep -o '"assignments":{[^}]*}')"
else
    echo "❌ Dispatch équilibré défaillant: $response"
fi

# Test 5: Test du dispatch vers un worker spécifique
echo "🎯 Test 5: Dispatch vers worker spécifique..."
response=$(curl -s -X POST http://localhost:8082/ws/dispatch \
    -H "Content-Type: application/json" \
    -d '{"symbols":["XRPUSDT","LTCUSDT"],"strategy":"worker","worker":"tradingv3-ws-worker-1:8088","timeframes":["1m","5m"],"live":false}')

if echo "$response" | grep -q '"ok":true'; then
    echo "✅ Dispatch vers worker spécifique fonctionnel"
    echo "   📋 Assignations: $(echo "$response" | grep -o '"assignments":{[^}]*}')"
else
    echo "❌ Dispatch vers worker spécifique défaillant: $response"
fi

# Test 6: Vérifier les assignations finales
echo "📈 Test 6: Vérification des assignations finales..."
response=$(curl -s http://localhost:8082/ws/assignments)
if echo "$response" | grep -q '"ok":true'; then
    echo "✅ Assignations récupérées avec succès"
    stats=$(echo "$response" | grep -o '"stats":{[^}]*}')
    echo "   📊 Statistiques: $stats"
else
    echo "❌ Impossible de récupérer les assignations: $response"
fi

# Test 7: Test du rebalancement
echo "🔄 Test 7: Test du rebalancement..."
response=$(curl -s -X POST http://localhost:8082/ws/rebalance \
    -H "Content-Type: application/json" \
    -d '{"symbols":["BTCUSDT","ETHUSDT","ADAUSDT","SOLUSDT","DOTUSDT"],"timeframes":["1m","5m"],"live":false}')

if echo "$response" | grep -q '"ok":true'; then
    echo "✅ Rebalancement fonctionnel"
    moves=$(echo "$response" | grep -o '"moves":{[^}]*}')
    echo "   🔄 Mouvements: $moves"
else
    echo "❌ Rebalancement défaillant: $response"
fi

# Test 8: Test des commandes console
echo "💻 Test 8: Test des commandes console..."
echo "   📋 Test de la commande ws:dispatch..."
dispatch_output=$(docker exec trading_app_php php bin/console ws:dispatch "BTCUSDT,ETHUSDT" --strategy=hash --live=false 2>&1)
if echo "$dispatch_output" | grep -q "Dispatch terminé"; then
    echo "✅ Commande ws:dispatch fonctionnelle"
else
    echo "❌ Commande ws:dispatch défaillante: $dispatch_output"
fi

echo "   📋 Test de la commande ws:assignment..."
assignment_output=$(docker exec trading_app_php php bin/console ws:assignment --stats 2>&1)
if echo "$assignment_output" | grep -q "contrats assignés"; then
    echo "✅ Commande ws:assignment fonctionnelle"
else
    echo "❌ Commande ws:assignment défaillante: $assignment_output"
fi

# Test 9: Vérifier l'interface utilisateur
echo "🖥️  Test 9: Vérification de l'interface utilisateur..."
page_content=$(curl -s http://localhost:8082/websocket)

# Vérifier la présence des éléments du dispatcher
if echo "$page_content" | grep -q "Configuration du Dispatcher"; then
    echo "✅ Section dispatcher présente dans l'interface"
else
    echo "❌ Section dispatcher manquante dans l'interface"
fi

if echo "$page_content" | grep -q "Statistiques des Workers"; then
    echo "✅ Section statistiques workers présente"
else
    echo "❌ Section statistiques workers manquante"
fi

if echo "$page_content" | grep -q "dispatchBtn"; then
    echo "✅ Bouton dispatcher présent"
else
    echo "❌ Bouton dispatcher manquant"
fi

if echo "$page_content" | grep -q "rebalanceBtn"; then
    echo "✅ Bouton rebalancement présent"
else
    echo "❌ Bouton rebalancement manquant"
fi

# Test 10: Test de gestion d'erreur
echo "⚠️  Test 10: Test de gestion d'erreur..."
error_response=$(curl -s -X POST http://localhost:8082/ws/dispatch \
    -H "Content-Type: application/json" \
    -d '{"symbols":[],"strategy":"hash","timeframes":["1m"],"live":false}')

if echo "$error_response" | grep -q '"ok":false'; then
    echo "✅ Gestion d'erreur fonctionnelle (symboles vides)"
else
    echo "❌ Gestion d'erreur défaillante: $error_response"
fi

echo ""
echo "🎉 Tests du système de dispatcher terminés !"
echo "📱 Accédez à l'interface: http://localhost:8082/websocket"
echo "🔧 Fonctionnalités disponibles:"
echo "   • Dispatch par hash (cohérent)"
echo "   • Dispatch équilibré (least-loaded)"
echo "   • Dispatch vers worker spécifique"
echo "   • Rebalancement automatique"
echo "   • Interface graphique complète"
echo "   • Commandes console"
