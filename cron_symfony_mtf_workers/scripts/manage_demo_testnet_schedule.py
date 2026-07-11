"""Guarded Temporal schedule for OKX demo + Hyperliquid testnet orchestration.

This is the dedicated DEMO-004 entrypoint. It targets the orchestrator workflow,
forces the run request to dry-run, creates the schedule paused by default, and
requires OKX + Hyperliquid runtime checks before any activation.
"""

from __future__ import annotations

import argparse
import asyncio
import inspect
import os
import subprocess
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, Optional


TEMPORAL_ADDRESS = os.getenv("TEMPORAL_ADDRESS", "temporal:7233")
TEMPORAL_NAMESPACE = os.getenv("TEMPORAL_NAMESPACE", "default")
TASK_QUEUE = os.getenv("TASK_QUEUE_NAME", "cron_symfony_mtf_workers")
TIME_ZONE = os.getenv("TZ", "UTC")

WORKFLOW_TYPE = "OrchestratorCronWorkflow"
SUPPORTED_ENVIRONMENT = "demo-testnet"
RUNTIME_CHECK_EXCHANGES = ("okx", "hyperliquid")

DEFAULT_URL = os.getenv(
    "DEMO_TESTNET_ORCHESTRATOR_RUN_URL",
    os.getenv("ORCHESTRATOR_RUN_URL", "http://python-orchestrator:8099/orchestrator/run"),
)
DEFAULT_CRON = os.getenv("DEMO_TESTNET_ORCHESTRATOR_CRON", "*/1 * * * *")
DEFAULT_DASHBOARD_ID = os.getenv("DEMO_TESTNET_ORCHESTRATOR_DASHBOARD_ID")
DEFAULT_SCHEDULE_ID = os.getenv(
    "DEMO_TESTNET_ORCHESTRATOR_SCHEDULE_ID",
    "cron-orchestrator-demo-testnet-1m",
)
DEFAULT_WORKFLOW_ID = os.getenv(
    "DEMO_TESTNET_ORCHESTRATOR_WORKFLOW_ID",
    "cron-orchestrator-demo-testnet-runner",
)


@dataclass(frozen=True)
class ScheduleConfig:
    command: str
    url: str
    dashboard_id: Optional[str]
    cron: str
    schedule_id: str
    workflow_id: str
    dry_run_schedule: bool
    paused: bool
    resume_on_create: bool
    skip_runtime_check: bool
    environment: str


def assert_demo_environment(environment: str) -> None:
    if environment != SUPPORTED_ENVIRONMENT:
        raise RuntimeError(
            f"mainnet schedules are forbidden for this entrypoint: expected "
            f"{SUPPORTED_ENVIRONMENT!r}, got {environment!r}"
        )


def dashboard_is_valid(dashboard_id: Optional[str]) -> bool:
    if dashboard_id is None:
        return False
    stripped = str(dashboard_id).strip()
    if not stripped:
        return False
    try:
        int(stripped)
    except (TypeError, ValueError):
        return False
    return True


def assert_dashboard_configured(config: ScheduleConfig) -> Optional[str]:
    if dashboard_is_valid(config.dashboard_id):
        return None

    message = (
        f"no valid demo/testnet dashboard configured (got {config.dashboard_id!r}): "
        "pass a numeric --dashboard-id or set DEMO_TESTNET_ORCHESTRATOR_DASHBOARD_ID."
    )
    if not config.dry_run_schedule:
        raise SystemExit(f"refusing to create demo/testnet schedule: {message}")
    return message


def build_workflow_config(
    *,
    url: str,
    dashboard_id: Optional[str],
    schedule_id: str,
) -> Dict[str, Any]:
    config: Dict[str, Any] = {
        "url": url,
        "schedule_id": schedule_id,
        "dry_run": True,
    }
    if dashboard_id is not None:
        config["dashboard_id"] = dashboard_id
    return config


def _looks_like_temporal_payload(value: Any) -> bool:
    return hasattr(value, "metadata") and hasattr(value, "data")


async def decoded_schedule_args(description: Any, action: Any) -> list[Any]:
    raw_args = list(getattr(action, "args", []) or [])
    if not raw_args or not any(_looks_like_temporal_payload(arg) for arg in raw_args):
        return raw_args

    data_converter = getattr(description, "data_converter", None)
    if data_converter is None or not hasattr(data_converter, "decode"):
        raise RuntimeError("could not decode Temporal schedule args: missing data converter")

    try:
        decoded = data_converter.decode(raw_args)
        if inspect.isawaitable(decoded):
            decoded = await decoded
    except Exception as exc:  # noqa: BLE001 - expose decode failure as guardrail failure
        raise RuntimeError(f"could not decode Temporal schedule args: {exc}") from exc

    return list(decoded)


def _normalize_overlap_policy(value: Any) -> str:
    if value == 2:
        return "buffer_one"
    name = getattr(value, "name", None)
    if name is None:
        name = str(value)
    return name.lower().replace("schedule_overlap_policy_", "")


def _normalize_workflow_args_for_comparison(args: list[Any], config: ScheduleConfig) -> list[Any]:
    if config.dashboard_id is not None or config.command not in {"pause", "delete"}:
        return args
    normalized: list[Any] = []
    for arg in args:
        if isinstance(arg, dict):
            arg = {key: value for key, value in arg.items() if key != "dashboard_id"}
        normalized.append(arg)
    return normalized


async def validate_schedule_definition(description: Any, config: ScheduleConfig) -> None:
    schedule = getattr(description, "schedule", None)
    action = getattr(schedule, "action", None)
    workflow = getattr(action, "workflow", None)
    if workflow != WORKFLOW_TYPE:
        raise RuntimeError(f"unexpected workflow for demo/testnet schedule: {workflow!r}")

    expected_args = [
        build_workflow_config(
            url=config.url,
            dashboard_id=config.dashboard_id,
            schedule_id=config.schedule_id,
        )
    ]
    actual_args = await decoded_schedule_args(description, action)
    actual_args = _normalize_workflow_args_for_comparison(actual_args, config)
    if actual_args != expected_args:
        raise RuntimeError(
            "unexpected workflow args for demo/testnet schedule: "
            f"expected {expected_args!r}, got {actual_args!r}"
        )

    workflow_id = getattr(action, "id", None)
    if workflow_id != config.workflow_id:
        raise RuntimeError(
            "unexpected workflow id for demo/testnet schedule: "
            f"expected {config.workflow_id!r}, got {workflow_id!r}"
        )

    task_queue = getattr(action, "task_queue", None)
    if task_queue != TASK_QUEUE:
        raise RuntimeError(
            "unexpected task queue for demo/testnet schedule: "
            f"expected {TASK_QUEUE!r}, got {task_queue!r}"
        )

    spec = getattr(schedule, "spec", None)
    cron_expressions = list(getattr(spec, "cron_expressions", []) or [])
    if cron_expressions != [config.cron]:
        raise RuntimeError(
            "unexpected cron for demo/testnet schedule: "
            f"expected {[config.cron]!r}, got {cron_expressions!r}"
        )

    time_zone_name = getattr(spec, "time_zone_name", None)
    if time_zone_name != TIME_ZONE:
        raise RuntimeError(
            "unexpected time zone for demo/testnet schedule: "
            f"expected {TIME_ZONE!r}, got {time_zone_name!r}"
        )

    policy = getattr(schedule, "policy", None)
    overlap = getattr(policy, "overlap", None)
    expected_overlap = temporal_schedule_classes()[-1]
    if overlap != expected_overlap and _normalize_overlap_policy(overlap) != _normalize_overlap_policy(
        expected_overlap
    ):
        raise RuntimeError(
            "unexpected overlap policy for demo/testnet schedule: "
            f"expected {expected_overlap!r}, got {overlap!r}"
        )


async def verify_existing_schedule(handle: Any, config: ScheduleConfig) -> None:
    description = await handle.describe()
    await validate_schedule_definition(description, config)


def existing_schedule_is_paused(description: Any) -> bool:
    schedule = getattr(description, "schedule", None)
    state = getattr(schedule, "state", None)
    return bool(getattr(state, "paused", False))


async def ensure_existing_schedule_pause_state(handle: Any, config: ScheduleConfig) -> None:
    description = await handle.describe()
    await validate_schedule_definition(description, config)
    is_paused = existing_schedule_is_paused(description)
    if config.paused and not is_paused:
        await handle.pause(note="demo/testnet create re-applied paused-by-default")
    elif not config.paused and is_paused:
        await handle.unpause(note="demo/testnet runtime checks passed")


def parse_runtime_check_output(output: str) -> Dict[str, str]:
    parsed: Dict[str, str] = {}
    for line in output.splitlines():
        if ":" not in line:
            continue
        key, value = line.split(":", 1)
        parsed[key.strip().lower().replace(" ", "_")] = value.strip().lower()
    return parsed


def build_runtime_check_command(exchange: str) -> list[str]:
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
        "perpetual",
    ]


def run_runtime_check(exchange: str) -> Dict[str, str]:
    repo_root = Path(__file__).resolve().parents[2]
    result = subprocess.run(
        build_runtime_check_command(exchange),
        cwd=repo_root,
        capture_output=True,
        check=False,
        text=True,
    )
    if result.returncode != 0:
        raise RuntimeError(
            f"{exchange} runtime-check failed with exit code {result.returncode}: "
            f"{(result.stderr or result.stdout).strip()}"
        )
    return parse_runtime_check_output(result.stdout)


def validate_runtime_checks(checks: Dict[str, Dict[str, str]]) -> None:
    for exchange in RUNTIME_CHECK_EXCHANGES:
        parsed = checks.get(exchange, {})
        if parsed.get("schedule_ready") != "yes":
            raise RuntimeError(f"{exchange} runtime-check schedule_ready must be yes")
        if parsed.get("exchange") not in {None, exchange}:
            raise RuntimeError(f"{exchange} runtime-check returned exchange={parsed.get('exchange')!r}")
        readiness_level = parsed.get("readiness_level")
        if readiness_level in {"mainnet_ready", "live_ready"}:
            raise RuntimeError(f"{exchange} runtime-check returned forbidden readiness_level={readiness_level}")


def ensure_runtime_checks_pass(skip_runtime_check: bool = False) -> None:
    if skip_runtime_check:
        raise RuntimeError("demo/testnet activation cannot skip runtime-check")
    checks = {exchange: run_runtime_check(exchange) for exchange in RUNTIME_CHECK_EXCHANGES}
    validate_runtime_checks(checks)


async def get_client():  # pragma: no cover - thin Temporal SDK wrapper
    from temporalio.client import Client

    return await Client.connect(TEMPORAL_ADDRESS, namespace=TEMPORAL_NAMESPACE)


def temporal_schedule_classes():  # pragma: no cover - thin Temporal SDK wrapper
    from temporalio.api.enums.v1 import ScheduleOverlapPolicy

    try:
        from temporalio.client import (
            Schedule,
            ScheduleActionStartWorkflow,
            SchedulePolicy,
            ScheduleSpec,
            ScheduleState,
        )
    except ImportError:  # pragma: no cover - compatibility with older Temporal SDKs
        from temporalio.client.schedule import (  # type: ignore[attr-defined]
            Schedule,
            ScheduleActionStartWorkflow,
            SchedulePolicy,
            ScheduleSpec,
            ScheduleState,
        )

    try:
        overlap = ScheduleOverlapPolicy.BUFFER_ONE
    except AttributeError:  # pragma: no cover - compatibility with older Temporal SDKs
        overlap = ScheduleOverlapPolicy.SCHEDULE_OVERLAP_POLICY_BUFFER_ONE

    return Schedule, ScheduleActionStartWorkflow, SchedulePolicy, ScheduleSpec, ScheduleState, overlap


async def create_schedule(client: Any, config: ScheduleConfig) -> None:
    assert_demo_environment(config.environment)
    warning = assert_dashboard_configured(config)
    if warning is not None:
        print(f"WARNING: {warning}")

    if not config.dry_run_schedule and (config.resume_on_create or not config.paused):
        ensure_runtime_checks_pass(config.skip_runtime_check)

    workflow_config = build_workflow_config(
        url=config.url,
        dashboard_id=config.dashboard_id,
        schedule_id=config.schedule_id,
    )

    if config.dry_run_schedule:
        print(
            "[DRY-RUN] would create demo/testnet schedule "
            f"{config.schedule_id} (workflow_id='{config.workflow_id}', cron='{config.cron}', "
            f"tz='{TIME_ZONE}', paused={config.paused}, config={workflow_config})"
        )
        return

    Schedule, ScheduleActionStartWorkflow, SchedulePolicy, ScheduleSpec, ScheduleState, overlap = (
        temporal_schedule_classes()
    )
    schedule = Schedule(
        action=ScheduleActionStartWorkflow(
            WORKFLOW_TYPE,
            args=[workflow_config],
            id=config.workflow_id,
            task_queue=TASK_QUEUE,
        ),
        spec=ScheduleSpec(
            cron_expressions=[config.cron],
            time_zone_name=TIME_ZONE,
        ),
        policy=SchedulePolicy(overlap=overlap),
        state=ScheduleState(
            paused=config.paused,
            note=(
                "demo/testnet schedule paused by default"
                if config.paused
                else "demo/testnet runtime checks passed"
            ),
        ),
    )

    try:
        await client.create_schedule(config.schedule_id, schedule)
    except Exception as exc:
        from temporalio.client import ScheduleAlreadyRunningError

        if isinstance(exc, ScheduleAlreadyRunningError):
            handle = client.get_schedule_handle(config.schedule_id)
            await ensure_existing_schedule_pause_state(handle, config)
            print(
                f"schedule '{config.schedule_id}' already exists."
                " Validated existing definition and pause state before reusing it."
            )
            return
        raise

    print(
        f"created demo/testnet schedule '{config.schedule_id}' -> cron='{config.cron}' "
        f"-> paused={config.paused} -> config={workflow_config}"
    )


async def pause_schedule(handle: Any, config: ScheduleConfig) -> None:
    assert_demo_environment(config.environment)
    await verify_existing_schedule(handle, config)
    await handle.pause(note="manual demo/testnet pause")
    print(f"schedule '{config.schedule_id}' paused")


async def resume_schedule(handle: Any, config: ScheduleConfig) -> None:
    assert_demo_environment(config.environment)
    assert_dashboard_configured(config)
    ensure_runtime_checks_pass(config.skip_runtime_check)
    await verify_existing_schedule(handle, config)
    await handle.unpause(note="demo/testnet runtime checks passed")
    print(f"schedule '{config.schedule_id}' resumed")


async def delete_schedule(handle: Any, config: ScheduleConfig) -> None:
    assert_demo_environment(config.environment)
    await verify_existing_schedule(handle, config)
    await handle.delete()
    print(f"schedule '{config.schedule_id}' deleted")


async def describe_schedule(handle: Any) -> None:
    description = await handle.describe()
    print("schedule status:")
    print(description)


def resolve_schedule_config(args: argparse.Namespace) -> ScheduleConfig:
    resume_on_create = bool(getattr(args, "resume_on_create", False))
    paused = not resume_on_create
    return ScheduleConfig(
        command=args.command,
        url=getattr(args, "url", None) or DEFAULT_URL,
        dashboard_id=getattr(args, "dashboard_id", None) or DEFAULT_DASHBOARD_ID,
        cron=getattr(args, "cron", None) or DEFAULT_CRON,
        schedule_id=getattr(args, "schedule_id", None) or DEFAULT_SCHEDULE_ID,
        workflow_id=getattr(args, "workflow_id", None) or DEFAULT_WORKFLOW_ID,
        dry_run_schedule=getattr(args, "dry_run_schedule", False),
        paused=paused,
        resume_on_create=resume_on_create,
        skip_runtime_check=getattr(args, "skip_runtime_check", False),
        environment=getattr(args, "environment", SUPPORTED_ENVIRONMENT),
    )


async def async_main(args: argparse.Namespace) -> None:
    config = resolve_schedule_config(args)

    if config.command == "create":
        assert_demo_environment(config.environment)
        assert_dashboard_configured(config)
        client = None if config.dry_run_schedule else await get_client()
        await create_schedule(client, config)
        return

    client = await get_client()
    handle = client.get_schedule_handle(config.schedule_id)
    if config.command == "pause":
        await pause_schedule(handle, config)
    elif config.command == "resume":
        await resume_schedule(handle, config)
    elif config.command == "delete":
        await delete_schedule(handle, config)
    elif config.command == "status":
        await describe_schedule(handle)


def add_common_options(parser: argparse.ArgumentParser) -> None:
    parser.add_argument("--url", help="Orchestrator run URL")
    parser.add_argument("--dashboard-id", help="Numeric demo-exchanges dashboard id")
    parser.add_argument("--cron", help="Cron expression")
    parser.add_argument("--schedule-id", help="Schedule id")
    parser.add_argument("--workflow-id", help="Workflow id")
    parser.add_argument("--environment", default=SUPPORTED_ENVIRONMENT)
    parser.add_argument(
        "--skip-runtime-check",
        action="store_true",
        help="Accepted for parser compatibility, but rejected before activation",
    )


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Manage the guarded OKX demo + Hyperliquid testnet orchestrator schedule"
    )
    sub = parser.add_subparsers(dest="command", required=True)

    create_cmd = sub.add_parser("create", help="Create the paused demo/testnet schedule")
    add_common_options(create_cmd)
    create_cmd.add_argument(
        "--dry-run",
        "--dry-run-schedule",
        dest="dry_run_schedule",
        action="store_true",
        help="Preview without creating the Temporal schedule",
    )
    create_cmd.add_argument(
        "--resume-on-create",
        action="store_true",
        help="Create active only after OKX and Hyperliquid runtime checks pass",
    )

    for command in ("status", "pause", "resume", "delete"):
        cmd = sub.add_parser(command, help=f"{command.capitalize()} the schedule")
        add_common_options(cmd)
        cmd.set_defaults(dry_run_schedule=False, resume_on_create=False)

    return parser


def main() -> None:
    parser = build_parser()
    args = parser.parse_args()
    asyncio.run(async_main(args))


if __name__ == "__main__":  # pragma: no cover
    main()
