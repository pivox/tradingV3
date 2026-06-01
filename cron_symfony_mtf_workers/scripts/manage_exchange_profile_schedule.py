import argparse
import asyncio
import os
import re
import subprocess
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, List, Optional


TEMPORAL_ADDRESS = os.getenv("TEMPORAL_ADDRESS", "temporal:7233")
TEMPORAL_NAMESPACE = os.getenv("TEMPORAL_NAMESPACE", "default")
TASK_QUEUE = os.getenv("TASK_QUEUE_NAME", "cron_symfony_mtf_workers")
TIME_ZONE = os.getenv("TZ", "UTC")

WORKFLOW_TYPE = "CronSymfonyMtfWorkersWorkflow"
DEFAULT_URL = os.getenv("MTF_WORKERS_URL", "http://trading-app-nginx:80/api/mtf/run")
DEFAULT_CRON = os.getenv("MTF_WORKERS_CRON", "*/1 * * * *")
DEFAULT_WORKERS = int(os.getenv("MTF_WORKERS_COUNT", "4"))

SUPPORTED_EXCHANGES = {"bitmart", "binance", "fake", "hyperliquid", "okx"}
SUPPORTED_MARKET_TYPES = {"perpetual", "spot"}
SUPPORTED_PROFILES = {"regular", "scalper", "scalper_micro"}


@dataclass(frozen=True)
class ScheduleConfig:
    command: str
    exchange: Optional[str]
    market_type: str
    profile: Optional[str]
    workers: int
    dry_run: bool
    cron: str
    schedule_id: str
    workflow_id: str
    dry_run_schedule: bool
    skip_runtime_check: bool


def parse_bool(value: Any, default: bool = True) -> bool:
    if value is None:
        return default
    if isinstance(value, bool):
        return value
    lowered = str(value).strip().lower()
    if lowered in {"1", "true", "yes", "on"}:
        return True
    if lowered in {"0", "false", "no", "off"}:
        return False

    return default


def cadence_suffix(cron_expression: str) -> str:
    aliases = {
        "*/1 * * * *": "1m",
        "*/5 * * * *": "5m",
        "*/15 * * * *": "15m",
        "0 * * * *": "1h",
    }
    if cron_expression in aliases:
        return aliases[cron_expression]

    sanitized = re.sub(r"[^a-zA-Z0-9]+", "-", cron_expression.strip()).strip("-").lower()
    return f"cron-{sanitized or 'custom'}"


def profile_slug(profile: str) -> str:
    return profile.replace("_", "-")


def generate_schedule_id(exchange: str, profile: str, cron_expression: str) -> str:
    return f"cron-mtf-{exchange}-{profile_slug(profile)}-{cadence_suffix(cron_expression)}"


def generate_workflow_id(exchange: str, profile: str) -> str:
    return f"mtf-{exchange}-{profile_slug(profile)}-runner"


def build_job(
    *,
    exchange: str,
    market_type: str,
    profile: str,
    workers: int,
    dry_run: bool,
    url: str = DEFAULT_URL,
) -> Dict[str, Any]:
    return {
        "url": url,
        "workers": max(1, workers),
        "dry_run": dry_run,
        "mtf_profile": profile,
        "exchange": exchange,
        "market_type": market_type,
    }


def parse_runtime_check_output(output: str) -> Dict[str, str]:
    parsed: Dict[str, str] = {}
    for line in output.splitlines():
        if ":" not in line:
            continue
        key, value = line.split(":", 1)
        parsed[key.strip().lower().replace(" ", "_")] = value.strip().lower()

    return parsed


def validate_live_guardrails(dry_run: bool, runtime_check: Dict[str, str]) -> List[str]:
    if dry_run:
        if runtime_check.get("schedule_ready") == "no":
            return ["Runtime check reports Schedule ready: no; schedule creation is allowed because dry_run=true."]
        return []

    failures = []
    required = {
        "schedule_ready": "yes",
        "credentials": "ok",
        "live_trading": "enabled",
    }
    for key, expected in required.items():
        if runtime_check.get(key) != expected:
            failures.append(f"{key} must be {expected} for dry_run=false")

    if failures:
        raise RuntimeError("; ".join(failures))

    return []


def runtime_check_command(exchange: str, market_type: str) -> List[str]:
    return [
        "docker",
        "compose",
        "exec",
        "-T",
        "trading-app-php",
        "php",
        "bin/console",
        "app:exchange:runtime-check",
        exchange,
        market_type,
    ]


def run_runtime_check(exchange: str, market_type: str) -> Dict[str, str]:
    repo_root = Path(__file__).resolve().parents[2]
    result = subprocess.run(
        runtime_check_command(exchange, market_type),
        cwd=repo_root,
        capture_output=True,
        check=False,
        text=True,
    )
    if result.returncode != 0:
        raise RuntimeError(
            "Runtime check failed with exit code "
            f"{result.returncode}: {(result.stderr or result.stdout).strip()}"
        )

    return parse_runtime_check_output(result.stdout)


def resolve_schedule_config(args: argparse.Namespace) -> ScheduleConfig:
    cron = args.cron
    dry_run = parse_bool(getattr(args, "dry_run", None), True)
    exchange = getattr(args, "exchange", None)
    profile = getattr(args, "profile", None)

    if getattr(args, "schedule_id", None):
        schedule_id = args.schedule_id
    elif exchange and profile:
        schedule_id = generate_schedule_id(exchange, profile, cron)
    else:
        raise SystemExit("--schedule-id is required when --exchange or --profile is omitted")

    if getattr(args, "workflow_id", None):
        workflow_id = args.workflow_id
    elif exchange and profile:
        workflow_id = generate_workflow_id(exchange, profile)
    else:
        workflow_id = "mtf-schedule-runner"

    return ScheduleConfig(
        command=args.command,
        exchange=exchange,
        market_type=getattr(args, "market_type", "perpetual"),
        profile=profile,
        workers=max(1, getattr(args, "workers", DEFAULT_WORKERS)),
        dry_run=dry_run,
        cron=cron,
        schedule_id=schedule_id,
        workflow_id=workflow_id,
        dry_run_schedule=getattr(args, "dry_run_schedule", False),
        skip_runtime_check=getattr(args, "skip_runtime_check", False),
    )


async def get_client():
    from temporalio.client import Client

    return await Client.connect(TEMPORAL_ADDRESS, namespace=TEMPORAL_NAMESPACE)


def temporal_schedule_classes():
    from temporalio.api.enums.v1 import ScheduleOverlapPolicy

    try:
        from temporalio.client import (
            Schedule,
            ScheduleActionStartWorkflow,
            SchedulePolicy,
            ScheduleSpec,
        )
    except ImportError:  # pragma: no cover - compatibility with older Temporal SDKs
        from temporalio.client.schedule import (  # type: ignore[attr-defined]
            Schedule,
            ScheduleActionStartWorkflow,
            SchedulePolicy,
            ScheduleSpec,
        )

    try:
        overlap = ScheduleOverlapPolicy.BUFFER_ONE
    except AttributeError:  # pragma: no cover - compatibility with older Temporal SDKs
        overlap = ScheduleOverlapPolicy.SCHEDULE_OVERLAP_POLICY_BUFFER_ONE

    return Schedule, ScheduleActionStartWorkflow, SchedulePolicy, ScheduleSpec, overlap


async def create_schedule(client: Any, config: ScheduleConfig) -> None:
    if config.exchange is None or config.profile is None:
        raise RuntimeError("create requires --exchange and --profile")

    runtime_check: Dict[str, str] = {}
    if not config.skip_runtime_check:
        runtime_check = run_runtime_check(config.exchange, config.market_type)

    for warning in validate_live_guardrails(config.dry_run, runtime_check):
        print(f"WARNING: {warning}")

    job = build_job(
        exchange=config.exchange,
        market_type=config.market_type,
        profile=config.profile,
        workers=config.workers,
        dry_run=config.dry_run,
    )
    Schedule, ScheduleActionStartWorkflow, SchedulePolicy, ScheduleSpec, overlap = temporal_schedule_classes()
    schedule = Schedule(
        action=ScheduleActionStartWorkflow(
            WORKFLOW_TYPE,
            args=[[job]],
            id=config.workflow_id,
            task_queue=TASK_QUEUE,
        ),
        spec=ScheduleSpec(
            cron_expressions=[config.cron],
            time_zone_name=TIME_ZONE,
        ),
        policy=SchedulePolicy(overlap=overlap),
    )

    if config.dry_run_schedule:
        print(
            "[DRY-RUN] would create schedule "
            f"{config.schedule_id} (workflow_id='{config.workflow_id}', cron='{config.cron}', "
            f"tz='{TIME_ZONE}', job={job})"
        )
        return

    try:
        await client.create_schedule(config.schedule_id, schedule)
    except Exception as exc:
        from temporalio.client import ScheduleAlreadyRunningError

        if isinstance(exc, ScheduleAlreadyRunningError):
            print(
                f"schedule '{config.schedule_id}' already exists."
                " Use 'status' to inspect or 'delete' before recreating."
            )
            return
        raise

    print(f"created schedule '{config.schedule_id}' -> cron='{config.cron}' -> job={job}")


async def pause_schedule(handle: Any, config: ScheduleConfig) -> None:
    await handle.pause("manual pause")
    print(f"schedule '{config.schedule_id}' paused")


async def resume_schedule(handle: Any, config: ScheduleConfig) -> None:
    await handle.unpause()
    print(f"schedule '{config.schedule_id}' resumed")


async def delete_schedule(handle: Any, config: ScheduleConfig) -> None:
    await handle.delete()
    print(f"schedule '{config.schedule_id}' deleted")


async def describe_schedule(handle: Any) -> None:
    description = await handle.describe()
    print("schedule status:")
    print(description)


async def async_main(args: argparse.Namespace) -> None:
    config = resolve_schedule_config(args)
    client = await get_client()

    if config.command == "create":
        await create_schedule(client, config)
        return

    handle = client.get_schedule_handle(config.schedule_id)
    if config.command == "pause":
        await pause_schedule(handle, config)
    elif config.command == "resume":
        await resume_schedule(handle, config)
    elif config.command == "delete":
        await delete_schedule(handle, config)
    elif config.command == "status":
        await describe_schedule(handle)


def add_common_options(parser: argparse.ArgumentParser, *, require_matrix: bool) -> None:
    parser.add_argument("--exchange", required=require_matrix, choices=sorted(SUPPORTED_EXCHANGES))
    parser.add_argument("--market-type", default="perpetual", choices=sorted(SUPPORTED_MARKET_TYPES))
    parser.add_argument("--profile", required=require_matrix, choices=sorted(SUPPORTED_PROFILES))
    parser.add_argument("--workers", type=int, default=DEFAULT_WORKERS)
    parser.add_argument("--dry-run", default="true")
    parser.add_argument("--cron", default=DEFAULT_CRON)
    parser.add_argument("--schedule-id")
    parser.add_argument("--workflow-id")
    parser.add_argument("--skip-runtime-check", action="store_true")


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Manage exchange/profile Temporal MTF schedules")
    sub = parser.add_subparsers(dest="command", required=True)

    create_cmd = sub.add_parser("create", help="Create a schedule")
    add_common_options(create_cmd, require_matrix=True)
    create_cmd.add_argument("--dry-run-schedule", action="store_true", help="Preview without creating")

    for command in ("status", "pause", "resume", "delete"):
        cmd = sub.add_parser(command, help=f"{command.capitalize()} a schedule")
        add_common_options(cmd, require_matrix=False)
        cmd.set_defaults(dry_run_schedule=False)

    return parser


def main() -> None:
    parser = build_parser()
    args = parser.parse_args()
    asyncio.run(async_main(args))


if __name__ == "__main__":
    main()
