import argparse
import asyncio
import os
from typing import Any, Dict

from temporalio.client import Client
from temporalio.api.enums.v1 import ScheduleOverlapPolicy

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

SCHEDULE_ID = os.getenv("CLEANUP_SCHEDULE_ID", "cron-db-cleanup-weekly")
WORKFLOW_ID = os.getenv("CLEANUP_WORKFLOW_ID", "db-cleanup-runner")
WORKFLOW_TYPE = "CronSymfonyMtfWorkersWorkflow"
# Par défaut : tous les dimanches à 3h du matin
CRON_EXPRESSION = os.getenv("CLEANUP_CRON", "0 3 * * 0")

try:
    OVERLAP_POLICY_BUFFER_ONE = ScheduleOverlapPolicy.BUFFER_ONE
except AttributeError:  # pragma: no cover - compatibility with < 1.6
    OVERLAP_POLICY_BUFFER_ONE = ScheduleOverlapPolicy.SCHEDULE_OVERLAP_POLICY_BUFFER_ONE

DEFAULT_URL = os.getenv("CLEANUP_URL", "http://trading-app-nginx:80/api/maintenance/cleanup")
# Paramètres de nettoyage
CLEANUP_DRY_RUN = os.getenv("CLEANUP_DRY_RUN", "false").lower() not in {"0", "false", "no", "off"}
CLEANUP_SYMBOL = os.getenv("CLEANUP_SYMBOL", "")  # Vide = tous les symboles
CLEANUP_KLINES_LIMIT = int(os.getenv("CLEANUP_KLINES_LIMIT", "500"))
CLEANUP_AUDIT_DAYS = int(os.getenv("CLEANUP_AUDIT_DAYS", "3"))
CLEANUP_SIGNAL_DAYS = int(os.getenv("CLEANUP_SIGNAL_DAYS", "3"))
CLEANUP_TIMEOUT_MINUTES = int(os.getenv("CLEANUP_TIMEOUT_MINUTES", "30"))


def make_job() -> Dict[str, Any]:
    """
    Construit le payload du job de nettoyage.
    
    Le payload sera envoyé en POST à l'endpoint /api/maintenance/cleanup
    """
    job = {
        "url": DEFAULT_URL,
        "timeout_minutes": CLEANUP_TIMEOUT_MINUTES,
        "method": "POST",
        "payload": {
            "dry_run": CLEANUP_DRY_RUN,
            "klines_limit": CLEANUP_KLINES_LIMIT,
            "audit_days": CLEANUP_AUDIT_DAYS,
            "signal_days": CLEANUP_SIGNAL_DAYS,
        }
    }
    
    # Ajouter le symbole seulement si défini
    if CLEANUP_SYMBOL and CLEANUP_SYMBOL.strip():
        job["payload"]["symbol"] = CLEANUP_SYMBOL.strip()
    
    return job


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


async def trigger_now(handle: ScheduleHandle) -> None:
    """Déclenche immédiatement une exécution du workflow (hors planning)"""
    await handle.trigger()
    print(f"🚀 schedule '{SCHEDULE_ID}' triggered manually (one-time execution)")


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
    elif args.command == "trigger":
        await trigger_now(handle)


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Manage the Database Cleanup schedule (weekly cleanup by default)",
        epilog="""
Environment variables:
  CLEANUP_SCHEDULE_ID      Schedule ID (default: cron-db-cleanup-weekly)
  CLEANUP_WORKFLOW_ID      Workflow ID (default: db-cleanup-runner)
  CLEANUP_CRON             Cron expression (default: '0 3 * * 0' = Sunday 3am)
  CLEANUP_URL              Endpoint URL (default: http://trading-app-nginx:80/api/maintenance/cleanup)
  CLEANUP_DRY_RUN          Dry-run mode (default: false)
  CLEANUP_SYMBOL           Filter by symbol (default: empty = all symbols)
  CLEANUP_KLINES_LIMIT     Klines to keep per (symbol, timeframe) (default: 500)
  CLEANUP_AUDIT_DAYS       Days of MTF audit to keep (default: 3)
  CLEANUP_SIGNAL_DAYS      Days of signals to keep (default: 3)
  CLEANUP_TIMEOUT_MINUTES  Request timeout in minutes (default: 30)
  
Examples:
  # Create schedule with default config (Sunday 3am, dry_run=false)
  python manage_cleanup_schedule.py create
  
  # Preview schedule creation
  python manage_cleanup_schedule.py create --dry-run
  
  # Create with custom settings via env vars
  CLEANUP_CRON="0 2 * * *" CLEANUP_DRY_RUN=true python manage_cleanup_schedule.py create
  
  # Trigger immediate cleanup (one-time, outside schedule)
  python manage_cleanup_schedule.py trigger
        """,
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    sub = parser.add_subparsers(dest="command", required=True)

    create_cmd = sub.add_parser("create", help="Create the schedule if it does not exist")
    create_cmd.add_argument("--dry-run", action="store_true", help="Preview without creating")

    sub.add_parser("pause", help="Pause the schedule")
    sub.add_parser("resume", help="Resume the schedule")
    sub.add_parser("delete", help="Delete the schedule")
    sub.add_parser("status", help="Display schedule status")
    sub.add_parser("trigger", help="Trigger an immediate one-time execution")

    return parser


def main() -> None:
    parser = build_parser()
    args = parser.parse_args()
    asyncio.run(async_main(args))


if __name__ == "__main__":
    main()
