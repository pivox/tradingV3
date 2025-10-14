# scripts/resume_mtf_schedule.py
import os, sys, argparse, asyncio
from typing import List

from temporalio.client import Client

# Reuse env defaults (same as create_mtf_schedule.py)
TEMPORAL_ADDRESS   = os.getenv("TEMPORAL_ADDRESS", "temporal:7233")
TEMPORAL_NAMESPACE = os.getenv("TEMPORAL_NAMESPACE", "default")

# Schedule ID du MTF
MTF_SCHEDULE_ID = "cron-symfony-mtf-1m"

async def resume_mtf(client: Client, dry_run: bool) -> None:
    handle = client.get_schedule_handle(MTF_SCHEDULE_ID)
    
    if dry_run:
        print(f"[dry-run] RESUME -> {MTF_SCHEDULE_ID}")
        return
    
    try:
        await handle.unpause(note="resume_mtf_schedule")
        print(f"[done] resumed {MTF_SCHEDULE_ID}")
    except Exception as e:
        print(f"[error] Erreur lors du resume de {MTF_SCHEDULE_ID}: {e}")

async def main(args: argparse.Namespace) -> None:
    client = await Client.connect(TEMPORAL_ADDRESS, namespace=TEMPORAL_NAMESPACE)
    await resume_mtf(client, dry_run=args.dry_run)

def parse_args(argv: List[str]) -> argparse.Namespace:
    p = argparse.ArgumentParser(description="RESUME la Schedule Temporal MTF.")
    p.add_argument("--dry-run", action="store_true", help="Afficher ce qui serait fait sans exécuter.")
    return p.parse_args(argv)

if __name__ == "__main__":
    try:
        asyncio.run(main(parse_args(sys.argv[1:])))
    except KeyboardInterrupt:
        pass
