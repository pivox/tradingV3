# scripts/create_all_schedules.py
import os
import sys
import argparse
import asyncio
from typing import Dict, Any, List

from temporalio.client import Client, Schedule, ScheduleSpec, ScheduleActionStartWorkflow

# --------- Paramètres via env (avec valeurs par défaut) ---------
TEMPORAL_ADDRESS   = os.getenv("TEMPORAL_ADDRESS", "temporal:7233")
TEMPORAL_NAMESPACE = os.getenv("TEMPORAL_NAMESPACE", "default")
TASK_QUEUE         = os.getenv("TASK_QUEUE_NAME", "cron_symfony_refresh")
TIME_ZONE          = os.getenv("TZ", "Africa/Tunis")

SCHEDULES: Dict[str, Dict[str, Any]] = {
    "1m": {
        "schedule_id": "cron-symfony-1m",
        "workflow_type": "CronSymfony1mWorkflow",
        "workflow_id": "cron-symfony-1m-runner",
        "cron": "*/1 * * * *",
        "url": os.getenv("SYM_CRON_URL_1M", "http://nginx/api/cron/bitmart/refresh-1m"),
    },
    "5m": {
        "schedule_id": "cron-symfony-5m",
        "workflow_type": "CronSymfony5mWorkflow",
        "workflow_id": "cron-symfony-5m-runner",
        "cron": "*/5 * * * *",
        "url": os.getenv("SYM_CRON_URL_5M", "http://nginx/api/cron/bitmart/refresh-5m"),
    },
    "15m": {
        "schedule_id": "cron-symfony-15m",
        "workflow_type": "CronSymfony15mWorkflow",
        "workflow_id": "cron-symfony-15m-runner",
        "cron": "*/15 * * * *",
        "url": os.getenv("SYM_CRON_URL_15M", "http://nginx/api/cron/bitmart/refresh-15m"),
    },
    "1h": {
        "schedule_id": "symfony-cron-1h",
        "workflow_type": "CronSymfony1hWorkflow",
        "workflow_id": "symfony-cron-1h-runner",
        "cron": "0 */1 * * *",
        "url": os.getenv("SYM_CRON_URL_1H", "http://nginx/api/cron/bitmart/refresh-1h"),
    },
    "4h": {
        "schedule_id": "symfony-cron-4h",
        "workflow_type": "CronSymfony4hWorkflow",
        "workflow_id": "symfony-cron-4h-runner",
        "cron": "0 */4 * * *",
        "url": os.getenv("SYM_CRON_URL_4H", "http://nginx/api/cron/bitmart/refresh-4h"),
    },
}

async def create_schedule(client: Client, key: str, cfg: Dict[str, Any], dry_run: bool = False) -> None:
    schedule = Schedule(
        action=ScheduleActionStartWorkflow(
            cfg["workflow_type"],
            args=[cfg["url"]],
            task_queue=TASK_QUEUE,
            id=cfg["workflow_id"],
        ),
        spec=ScheduleSpec(
            cron_expressions=[cfg["cron"]],
            time_zone_name=TIME_ZONE,
        ),
    )

    if dry_run:
        print(f"[DRY-RUN] {key}: would create schedule "
              f"id='{cfg['schedule_id']}', wf='{cfg['workflow_type']}', "
              f"cron='{cfg['cron']}', tz='{TIME_ZONE}', queue='{TASK_QUEUE}', url='{cfg['url']}'")
        return

    await client.create_schedule(cfg["schedule_id"], schedule)
    print(f"✅ {key}: Schedule '{cfg['schedule_id']}' créée — {cfg['cron']} ({TIME_ZONE}) → queue='{TASK_QUEUE}' → URL='{cfg['url']}'")

async def main(args: argparse.Namespace) -> None:
    client = await Client.connect(TEMPORAL_ADDRESS, namespace=TEMPORAL_NAMESPACE)
    for key, cfg in SCHEDULES.items():
        await create_schedule(client, key, cfg, dry_run=args.dry_run)

def parse_args(argv: List[str]) -> argparse.Namespace:
    p = argparse.ArgumentParser(description="Créer toutes les Schedules Temporal (1m/5m/15m/1h/4h) pour les cron Symfony.")
    p.add_argument("--dry-run", action="store_true", help="Afficher ce qui serait créé sans écrire.")
    return p.parse_args(argv)

if __name__ == "__main__":
    try:
        asyncio.run(main(parse_args(sys.argv[1:])))
    except KeyboardInterrupt:
        pass
