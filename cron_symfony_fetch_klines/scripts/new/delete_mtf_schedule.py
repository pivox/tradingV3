# scripts/delete_mtf_schedule.py
import os, sys, argparse, asyncio
from typing import List

from temporalio.client import Client

# Reuse env defaults (same as create_mtf_schedule.py)
TEMPORAL_ADDRESS   = os.getenv("TEMPORAL_ADDRESS", "temporal-grpc:7233")
TEMPORAL_NAMESPACE = os.getenv("TEMPORAL_NAMESPACE", "default")

# Schedule ID du MTF
MTF_SCHEDULE_ID = "cron-symfony-mtf-1m"

async def delete_mtf(client: Client, dry_run: bool) -> None:
    handle = client.get_schedule_handle(MTF_SCHEDULE_ID)
    
    if dry_run:
        print(f"[dry-run] DELETE -> {MTF_SCHEDULE_ID}")
        return
    
    try:
        await handle.delete()
        print(f"[done] deleted {MTF_SCHEDULE_ID}")
    except Exception as e:
        print(f"[error] Erreur lors de la suppression de {MTF_SCHEDULE_ID}: {e}")

async def main(args: argparse.Namespace) -> None:
    client = await Client.connect(TEMPORAL_ADDRESS, namespace=TEMPORAL_NAMESPACE)
    await delete_mtf(client, dry_run=args.dry_run)

def parse_args(argv: List[str]) -> argparse.Namespace:
    p = argparse.ArgumentParser(description="SUPPRIMER la Schedule Temporal MTF.")
    p.add_argument("--dry-run", action="store_true", help="Afficher ce qui serait fait sans exécuter.")
    return p.parse_args(argv)

if __name__ == "__main__":
    try:
        asyncio.run(main(parse_args(sys.argv[1:])))
    except KeyboardInterrupt:
        pass
