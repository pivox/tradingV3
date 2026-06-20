"""Gestion du schedule cron cible vers l'orchestrateur Python (TM-001).

Schedule unique qui déclenche ``OrchestratorCronWorkflow`` : un seul appel HTTP
vers ``POST /orchestrator/run``. Aucune logique de sélection de contrats côté
Temporal — tout est porté par l'API Python (PY-005/006).

Sous-commandes : ``create`` / ``pause`` / ``resume`` / ``delete`` / ``status``
(mêmes conventions que ``manage_exchange_profile_schedule.py``).

Paramètres via env (surchargés par les options CLI) :
    - ``ORCHESTRATOR_RUN_URL`` (défaut ``http://python-orchestrator:8099/orchestrator/run``)
    - ``ORCHESTRATOR_DASHBOARD_ID``
    - ``ORCHESTRATOR_SCHEDULE_ID`` / ``ORCHESTRATOR_WORKFLOW_ID``
    - ``ORCHESTRATOR_CRON`` (défaut ``*/1 * * * *``)

Overlap : ``BUFFER_ONE`` (un seul tick bufferisé si le précédent déborde).
"""

import argparse
import asyncio
import os
from dataclasses import dataclass
from typing import Any, Dict, Optional


TEMPORAL_ADDRESS = os.getenv("TEMPORAL_ADDRESS", "temporal:7233")
TEMPORAL_NAMESPACE = os.getenv("TEMPORAL_NAMESPACE", "default")
TASK_QUEUE = os.getenv("TASK_QUEUE_NAME", "cron_symfony_mtf_workers")
TIME_ZONE = os.getenv("TZ", "UTC")

WORKFLOW_TYPE = "OrchestratorCronWorkflow"

DEFAULT_URL = os.getenv(
    "ORCHESTRATOR_RUN_URL", "http://python-orchestrator:8099/orchestrator/run"
)
DEFAULT_CRON = os.getenv("ORCHESTRATOR_CRON", "*/1 * * * *")
DEFAULT_DASHBOARD_ID = os.getenv("ORCHESTRATOR_DASHBOARD_ID")
DEFAULT_SCHEDULE_ID = os.getenv("ORCHESTRATOR_SCHEDULE_ID", "cron-orchestrator-run-1m")
DEFAULT_WORKFLOW_ID = os.getenv("ORCHESTRATOR_WORKFLOW_ID", "cron-orchestrator-run-runner")


@dataclass(frozen=True)
class ScheduleConfig:
    command: str
    url: str
    dashboard_id: Optional[str]
    cron: str
    schedule_id: str
    workflow_id: str
    dry_run_schedule: bool


def build_workflow_config(
    *,
    url: str,
    dashboard_id: Optional[str],
    schedule_id: str,
) -> Dict[str, Any]:
    """Construit la config passée au ``OrchestratorCronWorkflow``.

    C'est l'unique argument du workflow : il s'en sert pour bâtir le minimal
    ``RunRequest`` et appeler ``/orchestrator/run``. Aucune logique métier ici.
    Le ``schedule_id`` est propagé pour tracer l'origine du tick côté API Python.
    """
    config: Dict[str, Any] = {"url": url, "schedule_id": schedule_id}
    if dashboard_id is not None:
        config["dashboard_id"] = dashboard_id
    return config


def resolve_schedule_config(args: argparse.Namespace) -> ScheduleConfig:
    return ScheduleConfig(
        command=args.command,
        url=getattr(args, "url", None) or DEFAULT_URL,
        dashboard_id=getattr(args, "dashboard_id", None) or DEFAULT_DASHBOARD_ID,
        cron=getattr(args, "cron", None) or DEFAULT_CRON,
        schedule_id=getattr(args, "schedule_id", None) or DEFAULT_SCHEDULE_ID,
        workflow_id=getattr(args, "workflow_id", None) or DEFAULT_WORKFLOW_ID,
        dry_run_schedule=getattr(args, "dry_run_schedule", False),
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


def dashboard_is_valid(dashboard_id: Optional[str]) -> bool:
    """Indique si ``dashboard_id`` résoudra un dashboard côté orchestrateur.

    ``/orchestrator/run`` fait ``int(dashboard_id)`` et retombe sur ``None``
    (=> ``no_sets``) pour une valeur absente, blanche ou non numérique
    (cf. ``_resolve_dashboard_id`` dans
    ``python-orchestrator/app/routers/orchestrator.py``). On applique la même
    règle ici pour ne pas créer un schedule qui tournerait à vide.
    """
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
    """Garde-fou ``create`` : un dashboard numérique valide est requis.

    ``/orchestrator/run`` résout un ``dashboard_id`` absent / blanc / non
    numérique en ``no_sets`` immédiat (cf.
    ``python-orchestrator/app/routers/orchestrator.py``) : un schedule créé
    ainsi tournerait indéfiniment sans exécuter aucun set. On échoue donc vite à
    la création réelle. En prévisualisation (``--dry-run``), on n'échoue pas mais
    on retourne le message d'avertissement.
    """
    if dashboard_is_valid(config.dashboard_id):
        return None
    message = (
        f"no valid dashboard configured (got {config.dashboard_id!r}): pass a numeric "
        "--dashboard-id or set a numeric ORCHESTRATOR_DASHBOARD_ID. /orchestrator/run "
        "resolves a missing/blank/non-numeric dashboard to no_sets, so the schedule "
        "would tick forever without executing any set."
    )
    if not config.dry_run_schedule:
        raise SystemExit(f"refusing to create schedule: {message}")
    return message


async def create_schedule(client: Any, config: ScheduleConfig) -> None:
    # Fail fast : pas de dashboard => schedule inutile (no_sets en boucle).
    warning = assert_dashboard_configured(config)
    if warning is not None:
        print(f"WARNING: {warning}")

    workflow_config = build_workflow_config(
        url=config.url,
        dashboard_id=config.dashboard_id,
        schedule_id=config.schedule_id,
    )

    if config.dry_run_schedule:
        print(
            "[DRY-RUN] would create schedule "
            f"{config.schedule_id} (workflow_id='{config.workflow_id}', cron='{config.cron}', "
            f"tz='{TIME_ZONE}', config={workflow_config})"
        )
        return

    Schedule, ScheduleActionStartWorkflow, SchedulePolicy, ScheduleSpec, overlap = (
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
    )

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

    print(
        f"created schedule '{config.schedule_id}' -> cron='{config.cron}' -> config={workflow_config}"
    )


async def pause_schedule(handle: Any, config: ScheduleConfig) -> None:
    # ``note`` est keyword-only sur ScheduleHandle.pause (SDK Temporal Python) :
    # le passer en positionnel lève TypeError.
    await handle.pause(note="manual pause")
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

    if config.command == "create":
        # Fail fast AVANT toute connexion Temporal : un create sans dashboard est
        # refusé (sinon la connexion réseau échouerait avant d'atteindre le
        # garde-fou). En dry-run, l'avertissement est émis par create_schedule.
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
    parser.add_argument("--url", help="Orchestrator run URL (default ORCHESTRATOR_RUN_URL)")
    parser.add_argument(
        "--dashboard-id", help="Dashboard id forwarded in the RunRequest (default ORCHESTRATOR_DASHBOARD_ID)"
    )
    parser.add_argument("--cron", help="Cron expression (default ORCHESTRATOR_CRON)")
    parser.add_argument("--schedule-id", help="Schedule id (default ORCHESTRATOR_SCHEDULE_ID)")
    parser.add_argument("--workflow-id", help="Workflow id (default ORCHESTRATOR_WORKFLOW_ID)")


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Manage the target Temporal cron schedule for the Python orchestrator (/orchestrator/run)"
    )
    sub = parser.add_subparsers(dest="command", required=True)

    create_cmd = sub.add_parser("create", help="Create the schedule")
    add_common_options(create_cmd)
    # ``--dry-run`` ne crée rien : il prévisualise le schedule cible. (Il n'y a
    # pas de dry_run de payload ici — le dry_run par set est décidé côté API
    # Python.) ``--dry-run-schedule`` est conservé comme alias rétro-compatible.
    create_cmd.add_argument(
        "--dry-run",
        "--dry-run-schedule",
        dest="dry_run_schedule",
        action="store_true",
        help="Preview the target schedule without creating it",
    )

    for command in ("status", "pause", "resume", "delete"):
        cmd = sub.add_parser(command, help=f"{command.capitalize()} the schedule")
        add_common_options(cmd)
        cmd.set_defaults(dry_run_schedule=False)

    return parser


def main() -> None:
    parser = build_parser()
    args = parser.parse_args()
    asyncio.run(async_main(args))


if __name__ == "__main__":
    main()
