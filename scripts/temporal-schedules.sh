#!/bin/bash

# Script de gestion des schedules Temporal pour TradingV3
# Usage: ./scripts/temporal-schedules.sh [command] [schedule-id]

set -e

# Couleurs pour l'affichage
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Fonction d'aide
show_help() {
    echo -e "${BLUE}🎛️ Gestion des Schedules Temporal - TradingV3${NC}"
    echo ""
    echo "Usage: $0 [COMMAND] [OPTIONS]"
    echo ""
    echo "Commands:"
    echo "  list                    Lister tous les schedules"
    echo "  pause <schedule-id>     Pause un schedule"
    echo "  unpause <schedule-id>   Reprendre un schedule"
    echo "  delete <schedule-id>    Supprimer un schedule"
    echo "  describe <schedule-id>  Afficher les détails d'un schedule"
    echo "  trigger <schedule-id>   Déclencher un schedule manuellement"
    echo "  status                  Afficher le statut des services"
    echo "  help                    Afficher cette aide"
    echo ""
    echo "Exemples:"
    echo "  $0 list"
    echo "  $0 pause mtf-klines-fetch-15m"
    echo "  $0 describe api-rate-limiter-monitor"
    echo ""
}

# Vérifier que Docker Compose est disponible
check_docker() {
    if ! command -v docker-compose &> /dev/null; then
        echo -e "${RED}❌ docker-compose n'est pas installé${NC}"
        exit 1
    fi
}

# Vérifier que le service Temporal est en cours d'exécution
check_temporal() {
    if ! docker-compose ps temporal | grep -q "Up"; then
        echo -e "${RED}❌ Le service Temporal n'est pas en cours d'exécution${NC}"
        echo -e "${YELLOW}💡 Démarrez les services avec: docker-compose up -d${NC}"
        exit 1
    fi
}

# Lister tous les schedules
list_schedules() {
    echo -e "${BLUE}📋 Liste des Schedules Temporal:${NC}"
    echo ""
    
    docker-compose exec temporal tctl schedule list --output table
    echo ""
    
    # Afficher les statistiques
    echo -e "${GREEN}📊 Statistiques:${NC}"
    docker-compose exec temporal tctl workflow count
}

# Pause un schedule
pause_schedule() {
    local schedule_id="$1"
    
    if [ -z "$schedule_id" ]; then
        echo -e "${RED}❌ ID du schedule requis${NC}"
        echo "Usage: $0 pause <schedule-id>"
        exit 1
    fi
    
    echo -e "${YELLOW}⏸️ Pause du schedule: $schedule_id${NC}"
    docker-compose exec temporal tctl schedule pause --schedule-id "$schedule_id"
    echo -e "${GREEN}✅ Schedule $schedule_id mis en pause${NC}"
}

# Reprendre un schedule
unpause_schedule() {
    local schedule_id="$1"
    
    if [ -z "$schedule_id" ]; then
        echo -e "${RED}❌ ID du schedule requis${NC}"
        echo "Usage: $0 unpause <schedule-id>"
        exit 1
    fi
    
    echo -e "${YELLOW}▶️ Reprise du schedule: $schedule_id${NC}"
    docker-compose exec temporal tctl schedule unpause --schedule-id "$schedule_id"
    echo -e "${GREEN}✅ Schedule $schedule_id repris${NC}"
}

# Supprimer un schedule
delete_schedule() {
    local schedule_id="$1"
    
    if [ -z "$schedule_id" ]; then
        echo -e "${RED}❌ ID du schedule requis${NC}"
        echo "Usage: $0 delete <schedule-id>"
        exit 1
    fi
    
    echo -e "${RED}⚠️ ATTENTION: Suppression définitive du schedule: $schedule_id${NC}"
    read -p "Êtes-vous sûr de vouloir continuer? (y/N): " -n 1 -r
    echo
    
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        docker-compose exec temporal tctl schedule delete --schedule-id "$schedule_id"
        echo -e "${GREEN}✅ Schedule $schedule_id supprimé${NC}"
    else
        echo -e "${YELLOW}❌ Suppression annulée${NC}"
    fi
}

# Afficher les détails d'un schedule
describe_schedule() {
    local schedule_id="$1"
    
    if [ -z "$schedule_id" ]; then
        echo -e "${RED}❌ ID du schedule requis${NC}"
        echo "Usage: $0 describe <schedule-id>"
        exit 1
    fi
    
    echo -e "${BLUE}📊 Détails du schedule: $schedule_id${NC}"
    echo ""
    docker-compose exec temporal tctl schedule describe --schedule-id "$schedule_id"
}

# Déclencher un schedule manuellement
trigger_schedule() {
    local schedule_id="$1"
    
    if [ -z "$schedule_id" ]; then
        echo -e "${RED}❌ ID du schedule requis${NC}"
        echo "Usage: $0 trigger <schedule-id>"
        exit 1
    fi
    
    echo -e "${YELLOW}🔄 Déclenchement manuel du schedule: $schedule_id${NC}"
    docker-compose exec temporal tctl schedule trigger --schedule-id "$schedule_id"
    echo -e "${GREEN}✅ Schedule $schedule_id déclenché${NC}"
}

# Afficher le statut des services
show_status() {
    echo -e "${BLUE}🔍 Statut des Services:${NC}"
    echo ""
    
    # Statut des conteneurs
    echo -e "${GREEN}📦 Conteneurs Docker:${NC}"
    docker-compose ps | grep -E "(temporal|grafana|loki|promtail)"
    echo ""
    
    # Statut des workflows
    echo -e "${GREEN}🔄 Workflows en cours:${NC}"
    docker-compose exec temporal tctl workflow count
    echo ""
    
    # Statut des schedules
    echo -e "${GREEN}⏰ Schedules actifs:${NC}"
    docker-compose exec temporal tctl schedule list --output table
}

# Fonction principale
main() {
    local command="$1"
    local schedule_id="$2"
    
    # Vérifications préliminaires
    check_docker
    check_temporal
    
    case "$command" in
        "list")
            list_schedules
            ;;
        "pause")
            pause_schedule "$schedule_id"
            ;;
        "unpause")
            unpause_schedule "$schedule_id"
            ;;
        "delete")
            delete_schedule "$schedule_id"
            ;;
        "describe")
            describe_schedule "$schedule_id"
            ;;
        "trigger")
            trigger_schedule "$schedule_id"
            ;;
        "status")
            show_status
            ;;
        "help"|"--help"|"-h"|"")
            show_help
            ;;
        *)
            echo -e "${RED}❌ Commande inconnue: $command${NC}"
            echo ""
            show_help
            exit 1
            ;;
    esac
}

# Exécution du script
main "$@"
