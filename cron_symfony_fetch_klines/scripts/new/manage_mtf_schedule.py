#!/usr/bin/env python3
# scripts/manage_mtf_schedule.py
"""
Script de gestion global pour la schedule MTF
Usage:
    python manage_mtf_schedule.py create    # Créer la schedule
    python manage_mtf_schedule.py pause     # Mettre en pause
    python manage_mtf_schedule.py resume    # Reprendre
    python manage_mtf_schedule.py delete    # Supprimer
    python manage_mtf_schedule.py status    # Voir le statut
"""

import os, sys, argparse, asyncio
from typing import List

from temporalio.client import Client

# Reuse env defaults
TEMPORAL_ADDRESS   = os.getenv("TEMPORAL_ADDRESS", "temporal-grpc:7233")
TEMPORAL_NAMESPACE = os.getenv("TEMPORAL_NAMESPACE", "default")

# Schedule ID du MTF
MTF_SCHEDULE_ID = "cron-symfony-mtf-1m"

async def create_schedule(client: Client, dry_run: bool) -> None:
    """Créer la schedule MTF"""
    from create_mtf_schedule import create_mtf_schedule
    await create_mtf_schedule(client, dry_run)

async def pause_schedule(client: Client, dry_run: bool) -> None:
    """Mettre en pause la schedule MTF"""
    handle = client.get_schedule_handle(MTF_SCHEDULE_ID)
    
    if dry_run:
        print(f"[dry-run] PAUSE -> {MTF_SCHEDULE_ID}")
        return
    
    try:
        await handle.pause(note="manage_mtf_schedule")
        print(f"[done] paused {MTF_SCHEDULE_ID}")
    except Exception as e:
        print(f"[error] Erreur lors de la pause de {MTF_SCHEDULE_ID}: {e}")

async def resume_schedule(client: Client, dry_run: bool) -> None:
    """Reprendre la schedule MTF"""
    handle = client.get_schedule_handle(MTF_SCHEDULE_ID)
    
    if dry_run:
        print(f"[dry-run] RESUME -> {MTF_SCHEDULE_ID}")
        return
    
    try:
        await handle.unpause(note="manage_mtf_schedule")
        print(f"[done] resumed {MTF_SCHEDULE_ID}")
    except Exception as e:
        print(f"[error] Erreur lors du resume de {MTF_SCHEDULE_ID}: {e}")

async def delete_schedule(client: Client, dry_run: bool) -> None:
    """Supprimer la schedule MTF"""
    handle = client.get_schedule_handle(MTF_SCHEDULE_ID)
    
    if dry_run:
        print(f"[dry-run] DELETE -> {MTF_SCHEDULE_ID}")
        return
    
    try:
        await handle.delete()
        print(f"[done] deleted {MTF_SCHEDULE_ID}")
    except Exception as e:
        print(f"[error] Erreur lors de la suppression de {MTF_SCHEDULE_ID}: {e}")

async def status_schedule(client: Client) -> None:
    """Afficher le statut de la schedule MTF"""
    try:
        handle = client.get_schedule_handle(MTF_SCHEDULE_ID)
        desc = await handle.describe()
        
        print(f"=== Statut de la schedule MTF ===")
        print(f"ID: {desc.id}")
        print(f"Pause: {desc.schedule.paused}")
        print(f"Workflow Type: {desc.schedule.action.workflow_type}")
        print(f"Workflow ID: {desc.schedule.action.id}")
        print(f"Cron: {desc.schedule.spec.cron_expressions}")
        print(f"Time Zone: {desc.schedule.spec.time_zone_name}")
        print(f"Task Queue: {desc.schedule.action.task_queue}")
        
        if hasattr(desc, 'info') and desc.info:
            print(f"Info: {desc.info}")
            
    except Exception as e:
        print(f"[error] Erreur lors de la récupération du statut de {MTF_SCHEDULE_ID}: {e}")

async def main(args: argparse.Namespace) -> None:
    client = await Client.connect(TEMPORAL_ADDRESS, namespace=TEMPORAL_NAMESPACE)
    
    if args.action == "create":
        await create_schedule(client, args.dry_run)
    elif args.action == "pause":
        await pause_schedule(client, args.dry_run)
    elif args.action == "resume":
        await resume_schedule(client, args.dry_run)
    elif args.action == "delete":
        await delete_schedule(client, args.dry_run)
    elif args.action == "status":
        await status_schedule(client)
    else:
        print(f"Action inconnue: {args.action}")
        sys.exit(1)

def parse_args(argv: List[str]) -> argparse.Namespace:
    p = argparse.ArgumentParser(description="Gestionnaire global pour la schedule Temporal MTF.")
    p.add_argument("action", choices=["create", "pause", "resume", "delete", "status"], 
                   help="Action à effectuer sur la schedule MTF")
    p.add_argument("--dry-run", action="store_true", help="Afficher ce qui serait fait sans exécuter.")
    return p.parse_args(argv)

if __name__ == "__main__":
    try:
        asyncio.run(main(parse_args(sys.argv[1:])))
    except KeyboardInterrupt:
        pass
