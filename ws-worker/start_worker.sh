#!/bin/bash

# Script de démarrage pour le BitMart WebSocket Worker

echo "=== BitMart WebSocket Worker ==="
echo

# Vérifier si le fichier .env existe
if [ ! -f .env ]; then
    echo "⚠️  Fichier .env non trouvé. Création depuis .env.example..."
    cp .env.example .env
    echo "📝 Veuillez éditer le fichier .env avec vos clés API BitMart"
    echo
fi

# Vérifier les dépendances
if [ ! -d "vendor" ]; then
    echo "📦 Installation des dépendances..."
    composer install
    echo
fi

# Afficher la configuration actuelle
echo "🔧 Configuration actuelle:"
echo "   - Public WS URI: ${BITMART_PUBLIC_WS_URI:-wss://openapi-ws-v2.bitmart.com/api?protocol=1.1}"
echo "   - Private WS URI: ${BITMART_PRIVATE_WS_URI:-wss://openapi-ws-v2.bitmart.com/user?protocol=1.1}"
echo "   - Control Server: ${CTRL_ADDR:-0.0.0.0:8089}"
echo "   - API Key: ${BITMART_API_KEY:-Non configuré}"
echo

# Vérifier si les clés API sont configurées
if [ -z "$BITMART_API_KEY" ] || [ -z "$BITMART_API_SECRET" ] || [ -z "$BITMART_API_MEMO" ]; then
    echo "⚠️  Clés API BitMart non configurées dans .env"
    echo "   Les canaux privés (ordres, positions) ne fonctionneront pas"
    echo "   Seuls les klines seront disponibles"
    echo
fi

echo "🚀 Démarrage du worker..."
echo "   Utilisez Ctrl+C pour arrêter"
echo "   API de contrôle: http://${CTRL_ADDR:-0.0.0.0:8089}"
echo "   Aide: curl http://${CTRL_ADDR:-0.0.0.0:8089}/help"
echo

# Charger les variables d'environnement et démarrer
export $(cat .env | grep -v '^#' | xargs)
php bin/console ws:run





