#!/bin/bash

# Script de test pour l'endpoint /api/mtf/run
# Usage: ./test-mtf-run.sh [URL] [SYMBOLS] [DRY_RUN] [FORCE_RUN]

set -e

# Configuration par défaut
DEFAULT_URL="http://localhost:8082"
DEFAULT_SYMBOLS="BTCUSDT,ETHUSDT"
DEFAULT_DRY_RUN="true"
DEFAULT_FORCE_RUN="false"

# Paramètres
URL=${1:-$DEFAULT_URL}
SYMBOLS=${2:-$DEFAULT_SYMBOLS}
DRY_RUN=${3:-$DEFAULT_DRY_RUN}
FORCE_RUN=${4:-$DEFAULT_FORCE_RUN}

echo "🧪 Test de l'endpoint /api/mtf/run"
echo "=================================="
echo "URL: $URL"
echo "Symboles: $SYMBOLS"
echo "Mode dry-run: $DRY_RUN"
echo "Force run: $FORCE_RUN"
echo ""

# Fonction pour tester la connectivité
test_connectivity() {
    echo "🔍 Test de connectivité..."
    if curl -s -f "$URL/api/mtf/status" > /dev/null 2>&1; then
        echo "✅ API accessible"
        return 0
    else
        echo "❌ API inaccessible"
        return 1
    fi
}

# Fonction pour tester l'endpoint
test_mtf_run() {
    echo "🚀 Test de l'endpoint /api/mtf/run..."
    
    # Préparer les données JSON
    JSON_DATA=$(cat <<EOF
{
  "symbols": ["$(echo $SYMBOLS | tr ',' '","')"],
  "dry_run": $DRY_RUN,
  "force_run": $FORCE_RUN
}
EOF
)
    
    echo "📤 Envoi de la requête..."
    echo "Données: $JSON_DATA"
    echo ""
    
    # Envoyer la requête et capturer la réponse
    RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "$URL/api/mtf/run" \
        -H "Content-Type: application/json" \
        -d "$JSON_DATA" \
        --max-time 60)
    
    # Séparer le contenu et le code de statut
    HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
    RESPONSE_BODY=$(echo "$RESPONSE" | head -n -1)
    
    echo "📥 Réponse reçue:"
    echo "Code HTTP: $HTTP_CODE"
    echo "Contenu:"
    echo "$RESPONSE_BODY" | jq '.' 2>/dev/null || echo "$RESPONSE_BODY"
    echo ""
    
    # Vérifier le code de statut
    if [ "$HTTP_CODE" = "200" ]; then
        echo "✅ Test réussi !"
        
        # Extraire et afficher le résumé si possible
        if command -v jq >/dev/null 2>&1; then
            echo ""
            echo "📊 Résumé de l'exécution:"
            echo "$RESPONSE_BODY" | jq -r '.data.summary | "Run ID: \(.run_id)\nTemps d\'exécution: \(.execution_time_seconds)s\nSymboles demandés: \(.symbols_requested)\nSymboles traités: \(.symbols_processed)\nSymboles réussis: \(.symbols_successful)\nSymboles échoués: \(.symbols_failed)\nSymboles ignorés: \(.symbols_skipped)\nTaux de succès: \(.success_rate)%"'
            
            echo ""
            echo "📈 Résultats par symbole:"
            echo "$RESPONSE_BODY" | jq -r '.data.results | to_entries[] | "\(.key): \(.value.status)"'
        fi
        
        return 0
    else
        echo "❌ Test échoué (code: $HTTP_CODE)"
        return 1
    fi
}

# Fonction pour afficher l'aide
show_help() {
    echo "Usage: $0 [URL] [SYMBOLS] [DRY_RUN] [FORCE_RUN]"
    echo ""
    echo "Paramètres:"
    echo "  URL        URL de base de l'API (défaut: $DEFAULT_URL)"
    echo "  SYMBOLS    Symboles à tester, séparés par des virgules (défaut: $DEFAULT_SYMBOLS)"
    echo "  DRY_RUN    Mode dry-run: true/false (défaut: $DEFAULT_DRY_RUN)"
    echo "  FORCE_RUN  Force run: true/false (défaut: $DEFAULT_FORCE_RUN)"
    echo ""
    echo "Exemples:"
    echo "  $0                                    # Test basique"
    echo "  $0 http://localhost:8082             # Test avec URL personnalisée"
    echo "  $0 http://localhost:8082 BTCUSDT     # Test avec un seul symbole"
    echo "  $0 http://localhost:8082 BTCUSDT,ETHUSDT false  # Test en mode production"
    echo "  $0 http://localhost:8082 BTCUSDT true true      # Test avec force run"
    echo ""
    echo "Options:"
    echo "  -h, --help    Afficher cette aide"
    echo "  -v, --verbose Mode verbeux"
}

# Vérifier les options
if [ "$1" = "-h" ] || [ "$1" = "--help" ]; then
    show_help
    exit 0
fi

# Vérifier que curl est installé
if ! command -v curl >/dev/null 2>&1; then
    echo "❌ curl n'est pas installé. Veuillez l'installer pour utiliser ce script."
    exit 1
fi

# Vérifier que jq est installé (optionnel)
if ! command -v jq >/dev/null 2>&1; then
    echo "⚠️  jq n'est pas installé. L'affichage des résultats sera limité."
    echo "   Installez jq pour une meilleure expérience:"
    echo "   - macOS: brew install jq"
    echo "   - Ubuntu/Debian: sudo apt-get install jq"
    echo "   - CentOS/RHEL: sudo yum install jq"
    echo ""
fi

# Exécuter les tests
echo "Démarrage des tests..."
echo ""

if test_connectivity; then
    echo ""
    if test_mtf_run; then
        echo ""
        echo "🎉 Tous les tests sont passés avec succès !"
        exit 0
    else
        echo ""
        echo "💥 Test de l'endpoint échoué !"
        exit 1
    fi
else
    echo ""
    echo "💥 Test de connectivité échoué !"
    exit 1
fi




