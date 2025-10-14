#!/bin/bash
# scripts/mtf.sh - Script shell pour gérer la schedule MTF

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

# Fonction d'aide
show_help() {
    echo "Usage: $0 <action> [options]"
    echo ""
    echo "Actions disponibles:"
    echo "  create    - Créer la schedule MTF"
    echo "  pause     - Mettre en pause la schedule MTF"
    echo "  resume    - Reprendre la schedule MTF"
    echo "  delete    - Supprimer la schedule MTF"
    echo "  status    - Afficher le statut de la schedule MTF"
    echo ""
    echo "Options:"
    echo "  --dry-run - Afficher ce qui serait fait sans exécuter"
    echo "  --help    - Afficher cette aide"
    echo ""
    echo "Exemples:"
    echo "  $0 create"
    echo "  $0 pause --dry-run"
    echo "  $0 status"
}

# Vérifier les arguments
if [ $# -eq 0 ] || [ "$1" = "--help" ] || [ "$1" = "-h" ]; then
    show_help
    exit 0
fi

ACTION="$1"
shift

# Vérifier que l'action est valide
case "$ACTION" in
    create|pause|resume|delete|status)
        ;;
    *)
        echo "Erreur: Action '$ACTION' non reconnue"
        echo ""
        show_help
        exit 1
        ;;
esac

# Aller dans le répertoire du projet
cd "$PROJECT_ROOT"

# Exécuter le script Python
echo "Exécution de l'action '$ACTION' sur la schedule MTF..."
python3 scripts/new/manage_mtf_schedule.py "$ACTION" "$@"
