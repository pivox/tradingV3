"""Create/manage a Temporal schedule that drives a dashboard via the Flask bridge.

The schedule starts ``CronSymfonyMtfWorkersWorkflow`` with a single *bridge job*
(``{dashboard_id, bridge_url, schedule_id, dry_run}``). The workflow then calls the
``bridge_dashboard_call`` activity, which expands the dashboard against Symfony.

Temporal plumbing (client, schedule classes, task queue, workflow type, time zone,
cadence suffix) is reused from ``manage_exchange_profile_schedule`` to avoid duplication.
The dashboard dry-run-only policy (PR11 OKX, PR12 Hyperliquid) is validated before any
schedule is created.

Usage:
    python scripts/manage_dashboard_schedule.py create --dashboard-id okx-hl-dry-run
    python scripts/manage_dashboard_schedule.py create --dashboard-id okx-hl-dry-run --dry-run-schedule
    python scripts/manage_dashboard_schedule.py status --dashboard-id okx-hl-dry-run
    python scripts/manage_dashboard_schedule.py pause  --schedule-id cron-mtf-dashboard-okx-hl-dry-run-1m
"""
import argparse
import asyncio
import os
from typing import Any, Dict, Optional

import scripts.manage_exchange_profile_schedule as base
from bridge.dashboard import Dashboard, load_dashboards_file

BRIDGE_URL = os.getenv("BRIDGE_URL", "http://mtf-bridge:8090/bridge/run")
DEFAULT_DASHBOARDS_PATH = os.getenv("BRIDGE_DASHBOARDS_PATH", "bridge/dashboards.example.yaml")


def generate_dashboard_schedule_id(dashboard: Dashboard) -> str:
    return f"cron-mtf-dashboard-{dashboard.dashboard_id}-{base.cadence_suffix(dashboard.cadence)}"


def generate_dashboard_workflow_id(dashboard: Dashboard) -> str:
    return f"mtf-dashboard-{dashboard.dashboard_id}-runner"


def build_bridge_job(dashboard: Dashboard, *, bridge_url: str, schedule_id: str) -> Dict[str, Any]:
    return {
        "dashboard_id": dashboard.dashboard_id,
        "bridge_url": bridge_url,
        "schedule_id": schedule_id,
        # Dashboard-level dry_run is informational (true only if every target is dry-run);
        # the per-target dry_run inside the dashboard is what actually drives Symfony.
        "dry_run": all(target.dry_run for target in dashboard.targets),
    }


def load_dashboard(dashboard_id: str, path: str) -> Dashboard:
    dashboards = load_dashboards_file(path)
    dashboard = dashboards.get(dashboard_id)
    if dashboard is None:
        raise SystemExit(
            f"unknown dashboard '{dashboard_id}' in {path} (available: {sorted(dashboards)})"
        )
    return dashboard


async def create_dashboard_schedule(args: argparse.Namespace) -> None:
    dashboard = load_dashboard(args.dashboard_id, args.dashboards_path)
    # Refuse a live OKX/Hyperliquid target at the matrix level before contacting Temporal.
    dashboard.validate_policy()

    schedule_id = args.schedule_id or generate_dashboard_schedule_id(dashboard)
    workflow_id = args.workflow_id or generate_dashboard_workflow_id(dashboard)
    job = build_bridge_job(dashboard, bridge_url=args.bridge_url, schedule_id=schedule_id)

    if args.dry_run_schedule:
        print(
            "[DRY-RUN] would create schedule "
            f"{schedule_id} (workflow_id='{workflow_id}', cron='{dashboard.cadence}', "
            f"tz='{base.TIME_ZONE}', job={job})"
        )
        return

    client = await base.get_client()
    Schedule, ScheduleActionStartWorkflow, SchedulePolicy, ScheduleSpec, overlap = (
        base.temporal_schedule_classes()
    )
    schedule = Schedule(
        action=ScheduleActionStartWorkflow(
            base.WORKFLOW_TYPE,
            args=[[job]],
            id=workflow_id,
            task_queue=base.TASK_QUEUE,
        ),
        spec=ScheduleSpec(
            cron_expressions=[dashboard.cadence],
            time_zone_name=base.TIME_ZONE,
        ),
        policy=SchedulePolicy(overlap=overlap),
    )

    try:
        await client.create_schedule(schedule_id, schedule)
    except Exception as exc:
        from temporalio.client import ScheduleAlreadyRunningError

        if isinstance(exc, ScheduleAlreadyRunningError):
            print(
                f"schedule '{schedule_id}' already exists."
                " Use 'status' to inspect or 'delete' before recreating."
            )
            return
        raise

    print(f"created schedule '{schedule_id}' -> cron='{dashboard.cadence}' -> job={job}")


def resolve_schedule_id(args: argparse.Namespace) -> str:
    if getattr(args, "schedule_id", None):
        return args.schedule_id
    if getattr(args, "dashboard_id", None):
        dashboard = load_dashboard(args.dashboard_id, args.dashboards_path)
        return generate_dashboard_schedule_id(dashboard)
    raise SystemExit("--schedule-id is required when --dashboard-id is omitted")


async def lifecycle(args: argparse.Namespace) -> None:
    schedule_id = resolve_schedule_id(args)
    client = await base.get_client()
    handle = client.get_schedule_handle(schedule_id)

    if args.command == "pause":
        await handle.pause("manual pause")
        print(f"schedule '{schedule_id}' paused")
    elif args.command == "resume":
        await handle.unpause()
        print(f"schedule '{schedule_id}' resumed")
    elif args.command == "delete":
        await handle.delete()
        print(f"schedule '{schedule_id}' deleted")
    elif args.command == "status":
        description = await handle.describe()
        print("schedule status:")
        print(description)


async def async_main(args: argparse.Namespace) -> None:
    if args.command == "create":
        await create_dashboard_schedule(args)
        return
    await lifecycle(args)


def add_common_options(parser: argparse.ArgumentParser) -> None:
    parser.add_argument("--dashboard-id")
    parser.add_argument("--dashboards-path", default=DEFAULT_DASHBOARDS_PATH)
    parser.add_argument("--bridge-url", default=BRIDGE_URL)
    parser.add_argument("--schedule-id")
    parser.add_argument("--workflow-id")


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Manage dashboard-driven Temporal MTF schedules")
    sub = parser.add_subparsers(dest="command", required=True)

    create_cmd = sub.add_parser("create", help="Create a dashboard schedule")
    add_common_options(create_cmd)
    create_cmd.add_argument("--dry-run-schedule", action="store_true", help="Preview without creating")

    for command in ("status", "pause", "resume", "delete"):
        cmd = sub.add_parser(command, help=f"{command.capitalize()} a dashboard schedule")
        add_common_options(cmd)
        cmd.set_defaults(dry_run_schedule=False)

    return parser


def main() -> None:
    parser = build_parser()
    args = parser.parse_args()
    asyncio.run(async_main(args))


if __name__ == "__main__":
    main()
