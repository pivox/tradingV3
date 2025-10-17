#!/bin/bash

echo "🧪 Test de la page de gestion des abonnements WebSocket"
echo "=================================================="

# Test 1: Vérifier que la page se charge
echo "📄 Test 1: Chargement de la page WebSocket..."
if curl -s -o /dev/null -w "%{http_code}" http://localhost:8082/websocket | grep -q "200"; then
    echo "✅ Page WebSocket accessible (HTTP 200)"
else
    echo "❌ Page WebSocket non accessible"
    exit 1
fi

# Test 2: Vérifier que le menu contient le lien WebSocket
echo "🔗 Test 2: Vérification du menu de navigation..."
if curl -s http://localhost:8082/websocket | grep -q "bi-wifi.*WebSocket"; then
    echo "✅ Lien WebSocket présent dans le menu"
else
    echo "❌ Lien WebSocket manquant dans le menu"
fi

# Test 3: Test de l'endpoint d'abonnement
echo "📡 Test 3: Test de l'endpoint d'abonnement..."
response=$(curl -s -X POST http://localhost:8082/ws/subscribe \
    -H "Content-Type: application/json" \
    -d '{"symbol":"TESTUSDT","tfs":["1m","5m"]}')

if echo "$response" | grep -q '"ok":true'; then
    echo "✅ Endpoint d'abonnement fonctionnel"
else
    echo "❌ Endpoint d'abonnement défaillant: $response"
fi

# Test 4: Test de l'endpoint de désabonnement
echo "📡 Test 4: Test de l'endpoint de désabonnement..."
response=$(curl -s -X POST http://localhost:8082/ws/unsubscribe \
    -H "Content-Type: application/json" \
    -d '{"symbol":"TESTUSDT","tfs":["1m","5m"]}')

if echo "$response" | grep -q '"ok":true'; then
    echo "✅ Endpoint de désabonnement fonctionnel"
else
    echo "❌ Endpoint de désabonnement défaillant: $response"
fi

# Test 5: Test de la gestion d'erreur
echo "⚠️  Test 5: Test de la gestion d'erreur..."
response=$(curl -s -X POST http://localhost:8082/ws/subscribe \
    -H "Content-Type: application/json" \
    -d '{"symbol":"","tfs":["1m"]}')

if echo "$response" | grep -q '"ok":false'; then
    echo "✅ Gestion d'erreur fonctionnelle"
else
    echo "❌ Gestion d'erreur défaillante: $response"
fi

# Test 6: Vérifier la présence des contrats
echo "📊 Test 6: Vérification de la présence des contrats..."
contract_count=$(curl -s http://localhost:8082/websocket | grep -c "data-symbol=")
if [ "$contract_count" -gt 0 ]; then
    echo "✅ $contract_count contrats trouvés dans la page"
else
    echo "❌ Aucun contrat trouvé dans la page"
fi

# Test 7: Vérifier la présence des boutons d'action
echo "🔘 Test 7: Vérification des boutons d'action..."
subscribe_buttons=$(curl -s http://localhost:8082/websocket | grep -c "subscribe-btn")
unsubscribe_buttons=$(curl -s http://localhost:8082/websocket | grep -c "unsubscribe-btn")

if [ "$subscribe_buttons" -gt 0 ] && [ "$unsubscribe_buttons" -gt 0 ]; then
    echo "✅ $subscribe_buttons boutons d'abonnement et $unsubscribe_buttons boutons de désabonnement trouvés"
else
    echo "❌ Boutons d'action manquants"
fi

echo ""
echo "🎉 Tests terminés !"
echo "📱 Accédez à la page: http://localhost:8082/websocket"
echo "🔧 Menu: Outils → WebSocket"
