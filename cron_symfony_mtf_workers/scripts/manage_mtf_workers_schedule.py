"""DEPRECATED (CLEAN-001) — schedule legacy générique vers ``/api/mtf/run``.

Ce script crée/gère un schedule legacy démarrant
``CronSymfonyMtfWorkersWorkflow``. Il est **déprécié** : utiliser le schedule
orchestrateur unique (``scripts/manage_orchestrator_schedule.py`` →
``POST /orchestrator/run``). Le script reste fonctionnel pendant la transition
et émet un ``DeprecationWarning`` à son lancement (suppression = jalon
ultérieur, hors CLEAN-001).
"""

import argparse
import asyncio
import os
import sys
from typing import Any, Dict

from temporalio.client import Client
from temporalio.api.enums.v1 import ScheduleOverlapPolicy

# Permet l'exécution directe `python scripts/manage_*.py` (où sys.path[0] est le
# dossier scripts/) de résoudre le paquet `utils` à la racine du projet cron.
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from utils.legacy_deprecation import warn_legacy_deprecation

# Support both modern (>=1.6) and legacy (<1.6) Temporal Python SDK layouts
try:
    from temporalio.client import (
        Schedule,
        ScheduleActionStartWorkflow,
        ScheduleHandle,
        SchedulePolicy,
        ScheduleSpec,
    )
except ImportError:  # pragma: no cover - backward compatibility
    from temporalio.client.schedule import (  # type: ignore[attr-defined]
        Schedule,
        ScheduleActionStartWorkflow,
        ScheduleHandle,
        SchedulePolicy,
        ScheduleSpec,
    )


TEMPORAL_ADDRESS = os.getenv("TEMPORAL_ADDRESS", "temporal:7233")
TEMPORAL_NAMESPACE = os.getenv("TEMPORAL_NAMESPACE", "default")
TASK_QUEUE = os.getenv("TASK_QUEUE_NAME", "cron_symfony_mtf_workers")
TIME_ZONE = os.getenv("TZ", "UTC")

SCHEDULE_ID = os.getenv("MTF_WORKERS_SCHEDULE_ID", "cron-symfony-mtf-workers-1m")
WORKFLOW_ID = os.getenv("MTF_WORKERS_WORKFLOW_ID", "cron-symfony-mtf-workers-runner")
WORKFLOW_TYPE = "CronSymfonyMtfWorkersWorkflow"
CRON_EXPRESSION = os.getenv("MTF_WORKERS_CRON", "*/1 * * * *")

try:
    OVERLAP_POLICY_BUFFER_ONE = ScheduleOverlapPolicy.BUFFER_ONE
except AttributeError:  # pragma: no cover - compatibility with < 1.6
    OVERLAP_POLICY_BUFFER_ONE = ScheduleOverlapPolicy.SCHEDULE_OVERLAP_POLICY_BUFFER_ONE

DEFAULT_URL = os.getenv("MTF_WORKERS_URL", "http://trading-app-nginx:80/api/mtf/run")
# Nombre de workers désiré pour les appels MTF (override via MTF_WORKERS_COUNT)
DEFAULT_WORKERS = int(os.getenv("MTF_WORKERS_COUNT", "4"))
DEFAULT_DRY_RUN = os.getenv("MTF_WORKERS_DRY_RUN", "true").lower() not in {"0", "false", "no", "off"}


def make_job() -> Dict[str, Any]:
    return {
        "url": DEFAULT_URL,
        "workers": max(1, DEFAULT_WORKERS),
        "dry_run": DEFAULT_DRY_RUN,
        "mtf_profile": "scalper",
    }


async def get_client() -> Client:
    return await Client.connect(TEMPORAL_ADDRESS, namespace=TEMPORAL_NAMESPACE)


async def create_schedule(client: Client, dry_run: bool) -> None:
    job = make_job()
    schedule = Schedule(
        action=ScheduleActionStartWorkflow(
            WORKFLOW_TYPE,
            args=[[job]],
            id=WORKFLOW_ID,
            task_queue=TASK_QUEUE,
        ),
        spec=ScheduleSpec(
            cron_expressions=[CRON_EXPRESSION],
            time_zone_name=TIME_ZONE,
        ),
        policy=SchedulePolicy(overlap=OVERLAP_POLICY_BUFFER_ONE),
    )

    if dry_run:
        print(
            f"[DRY-RUN] would create schedule {SCHEDULE_ID} (cron='{CRON_EXPRESSION}', tz='{TIME_ZONE}', job={job})"
        )
        return

    try:
        await client.create_schedule(SCHEDULE_ID, schedule)
    except Exception as exc:
        from temporalio.client import ScheduleAlreadyRunningError

        if isinstance(exc, ScheduleAlreadyRunningError):
            print(
                f"⚠️ schedule '{SCHEDULE_ID}' already exists (Temporal reported it as running)."
                " Use 'status' to inspect or 'delete' before recreating."
            )
            return
        raise

    print(f"✅ created schedule '{SCHEDULE_ID}' → cron='{CRON_EXPRESSION}' → job={job}")


async def pause_schedule(handle: ScheduleHandle) -> None:
    await handle.pause("manual pause")
    print(f"⏸️ schedule '{SCHEDULE_ID}' paused")


async def resume_schedule(handle: ScheduleHandle) -> None:
    await handle.unpause()
    print(f"▶️ schedule '{SCHEDULE_ID}' resumed")


async def delete_schedule(handle: ScheduleHandle) -> None:
    await handle.delete()
    print(f"🗑️ schedule '{SCHEDULE_ID}' deleted")


async def describe_schedule(handle: ScheduleHandle) -> None:
    description = await handle.describe()
    print("ℹ️ schedule status:")
    print(description)


async def async_main(args: argparse.Namespace) -> None:
    client = await get_client()
    if args.command == "create":
        await create_schedule(client, dry_run=args.dry_run)
        return

    handle = client.get_schedule_handle(SCHEDULE_ID)
    if args.command == "pause":
        await pause_schedule(handle)
    elif args.command == "resume":
        await resume_schedule(handle)
    elif args.command == "delete":
        await delete_schedule(handle)
    elif args.command == "status":
        await describe_schedule(handle)


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description=(
            "[DEPRECATED — CLEAN-001] Manage the legacy CronSymfonyMtfWorkers "
            "schedule. Use scripts/manage_orchestrator_schedule.py (single "
            "orchestrator schedule → POST /orchestrator/run) instead."
        )
    )
    sub = parser.add_subparsers(dest="command", required=True)

    create_cmd = sub.add_parser("create", help="Create the schedule if it does not exist")
    create_cmd.add_argument("--dry-run", action="store_true", help="Preview without creating")

    sub.add_parser("pause", help="Pause the schedule")
    sub.add_parser("resume", help="Resume the schedule")
    sub.add_parser("delete", help="Delete the schedule")
    sub.add_parser("status", help="Display schedule status")

    return parser


def main() -> None:
    warn_legacy_deprecation("manage_mtf_workers_schedule.py")
    parser = build_parser()
    args = parser.parse_args()
    asyncio.run(async_main(args))


if __name__ == "__main__":
    main()
