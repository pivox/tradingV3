"""Tests du gestionnaire de schedule cron cible vers l'orchestrateur (TM-001).

QA-003 complète l'existant pour couvrir les branches restantes du script :
import réel des classes Temporal (sans serveur), ``get_client``, routage cycle
de vie de ``async_main`` (pause/resume/delete/status + create connecté),
``delete``/``describe``, re-levée d'une erreur inattendue et ``main()``.
"""

import asyncio
import sys

import pytest

import scripts.manage_orchestrator_schedule as schedule_manager
from scripts.manage_orchestrator_schedule import (
    DEFAULT_URL,
    WORKFLOW_TYPE,
    ScheduleConfig,
    async_main,
    build_parser,
    build_workflow_config,
    create_schedule,
    describe_schedule,
    delete_schedule,
    pause_schedule,
    resolve_schedule_config,
    resume_schedule,
)


def _make_config(command, **overrides):
    """Fabrique une ScheduleConfig valide (dashboard numérique) surchargeable."""
    base = dict(
        command=command,
        url=DEFAULT_URL,
        dashboard_id="7",
        cron="*/1 * * * *",
        schedule_id="cron-orchestrator-run-1m",
        workflow_id="cron-orchestrator-run-runner",
        dry_run_schedule=False,
    )
    base.update(overrides)
    return ScheduleConfig(**base)


class _DummyScheduleClass:
    def __init__(self, *args, **kwargs):
        self.args = args
        self.kwargs = kwargs


def _patch_schedule_classes(monkeypatch):
    monkeypatch.setattr(
        schedule_manager,
        "temporal_schedule_classes",
        lambda: (
            _DummyScheduleClass,
            lambda *a, **k: _DummyScheduleClass(),
            _DummyScheduleClass,
            _DummyScheduleClass,
            "buffer_one",
        ),
    )


def test_build_workflow_config_includes_url_schedule_and_dashboard():
    config = build_workflow_config(
        url="http://python-orchestrator:8099/orchestrator/run",
        dashboard_id="7",
        schedule_id="cron-orchestrator-run-1m",
    )

    assert config == {
        "url": "http://python-orchestrator:8099/orchestrator/run",
        "schedule_id": "cron-orchestrator-run-1m",
        "dashboard_id": "7",
    }


def test_build_workflow_config_omits_absent_dashboard():
    config = build_workflow_config(
        url=DEFAULT_URL,
        dashboard_id=None,
        schedule_id="cron-orchestrator-run-1m",
    )

    assert "dashboard_id" not in config
    assert config["url"] == DEFAULT_URL


def test_workflow_type_targets_orchestrator_cron():
    # Sécurité : le schedule cible le workflow cron orchestrateur, pas le legacy MTF.
    assert WORKFLOW_TYPE == "OrchestratorCronWorkflow"


def test_default_url_targets_orchestrator_run_endpoint():
    assert DEFAULT_URL.endswith("/orchestrator/run")


def test_resolve_schedule_config_uses_defaults(monkeypatch):
    # Pas d'env override : on doit retomber sur les valeurs par défaut.
    monkeypatch.setattr(schedule_manager, "DEFAULT_DASHBOARD_ID", None)
    parser = build_parser()
    args = parser.parse_args(["create"])
    config = resolve_schedule_config(args)

    assert config.url == DEFAULT_URL
    assert config.cron == "*/1 * * * *"
    assert config.schedule_id == "cron-orchestrator-run-1m"
    assert config.workflow_id == "cron-orchestrator-run-runner"
    assert config.dashboard_id is None
    assert config.dry_run_schedule is False


def test_resolve_schedule_config_honors_cli_overrides():
    parser = build_parser()
    args = parser.parse_args(
        [
            "create",
            "--url",
            "http://custom:9000/orchestrator/run",
            "--dashboard-id",
            "12",
            "--cron",
            "*/5 * * * *",
            "--schedule-id",
            "cron-orchestrator-run-5m",
            "--workflow-id",
            "my-runner",
            "--dry-run-schedule",
        ]
    )
    config = resolve_schedule_config(args)

    assert config.url == "http://custom:9000/orchestrator/run"
    assert config.dashboard_id == "12"
    assert config.cron == "*/5 * * * *"
    assert config.schedule_id == "cron-orchestrator-run-5m"
    assert config.workflow_id == "my-runner"
    assert config.dry_run_schedule is True


def test_status_command_resolves_without_create_options():
    parser = build_parser()
    args = parser.parse_args(["status", "--schedule-id", "cron-orchestrator-run-1m"])
    config = resolve_schedule_config(args)

    assert config.command == "status"
    assert config.schedule_id == "cron-orchestrator-run-1m"


def test_create_dry_run_schedule_preview_skips_temporal(monkeypatch, capsys):
    async def fail_get_client():
        raise AssertionError("dry-run schedule preview must not connect to Temporal")

    def fail_schedule_classes():
        raise AssertionError("dry-run schedule preview must not build Temporal schedule classes")

    monkeypatch.setattr(schedule_manager, "get_client", fail_get_client)
    monkeypatch.setattr(schedule_manager, "temporal_schedule_classes", fail_schedule_classes)

    parser = build_parser()
    args = parser.parse_args(
        ["create", "--dashboard-id", "7", "--dry-run-schedule"]
    )

    asyncio.run(async_main(args))

    output = capsys.readouterr().out
    assert "[DRY-RUN] would create schedule cron-orchestrator-run-1m" in output
    assert "/orchestrator/run" in output
    assert "'dashboard_id': '7'" in output
    assert "*/1 * * * *" in output


def test_create_schedule_passes_single_workflow_config_arg(monkeypatch):
    class DummyScheduleClass:
        def __init__(self, *args, **kwargs):
            self.args = args
            self.kwargs = kwargs

    class CapturingClient:
        def __init__(self):
            self.created = []

        async def create_schedule(self, schedule_id, schedule):
            self.created.append((schedule_id, schedule))

    captured = {}

    def capturing_action(workflow_type, *, args, id, task_queue):
        captured["workflow_type"] = workflow_type
        captured["args"] = args
        captured["id"] = id
        captured["task_queue"] = task_queue
        return DummyScheduleClass()

    monkeypatch.setattr(
        schedule_manager,
        "temporal_schedule_classes",
        lambda: (
            DummyScheduleClass,
            capturing_action,
            DummyScheduleClass,
            DummyScheduleClass,
            "buffer_one",
        ),
    )

    config = ScheduleConfig(
        command="create",
        url=DEFAULT_URL,
        dashboard_id="7",
        cron="*/1 * * * *",
        schedule_id="cron-orchestrator-run-1m",
        workflow_id="cron-orchestrator-run-runner",
        dry_run_schedule=False,
    )
    client = CapturingClient()

    asyncio.run(create_schedule(client, config))

    assert client.created[0][0] == "cron-orchestrator-run-1m"
    assert captured["workflow_type"] == "OrchestratorCronWorkflow"
    # Un seul argument workflow : la config (pas une liste de jobs comme le legacy).
    assert captured["args"] == [
        {"url": DEFAULT_URL, "schedule_id": "cron-orchestrator-run-1m", "dashboard_id": "7"}
    ]


def test_create_without_dashboard_fails_fast(monkeypatch):
    # Un schedule sans dashboard tournerait en no_sets indéfiniment : refus.
    def fail_schedule_classes():
        raise AssertionError("must not build Temporal classes when dashboard is missing")

    monkeypatch.setattr(schedule_manager, "temporal_schedule_classes", fail_schedule_classes)

    config = ScheduleConfig(
        command="create",
        url=DEFAULT_URL,
        dashboard_id=None,
        cron="*/1 * * * *",
        schedule_id="cron-orchestrator-run-1m",
        workflow_id="cron-orchestrator-run-runner",
        dry_run_schedule=False,
    )

    with pytest.raises(SystemExit, match="no valid dashboard configured"):
        asyncio.run(create_schedule(object(), config))


def test_dashboard_is_valid_rejects_blank_and_non_numeric():
    assert schedule_manager.dashboard_is_valid("7") is True
    assert schedule_manager.dashboard_is_valid(" 7 ") is True
    assert schedule_manager.dashboard_is_valid(None) is False
    assert schedule_manager.dashboard_is_valid("") is False
    assert schedule_manager.dashboard_is_valid("   ") is False
    assert schedule_manager.dashboard_is_valid("abc") is False


def test_create_with_blank_or_non_numeric_dashboard_fails_fast():
    def make_config(dashboard_id):
        return ScheduleConfig(
            command="create",
            url=DEFAULT_URL,
            dashboard_id=dashboard_id,
            cron="*/1 * * * *",
            schedule_id="cron-orchestrator-run-1m",
            workflow_id="cron-orchestrator-run-runner",
            dry_run_schedule=False,
        )

    for bad in ("", "   ", "abc"):
        with pytest.raises(SystemExit, match="no valid dashboard configured"):
            asyncio.run(create_schedule(object(), make_config(bad)))


def test_dry_run_preview_without_dashboard_warns_but_previews(monkeypatch, capsys):
    monkeypatch.setattr(schedule_manager, "DEFAULT_DASHBOARD_ID", None)
    parser = build_parser()
    args = parser.parse_args(["create", "--dry-run"])

    asyncio.run(async_main(args))

    output = capsys.readouterr().out
    assert "WARNING: no valid dashboard configured" in output
    # La prévisualisation est tout de même affichée (rien n'est créé).
    assert "[DRY-RUN] would create schedule cron-orchestrator-run-1m" in output


def test_pause_passes_note_as_keyword():
    # ScheduleHandle.pause(note=...) est keyword-only : un appel positionnel
    # lèverait TypeError. Le fake ci-dessous reproduit cette contrainte.
    class _KwOnlyHandle:
        def __init__(self):
            self.calls = []

        async def pause(self, *, note=None):
            self.calls.append(("pause", note))

        async def unpause(self, *, note=None):
            self.calls.append(("unpause", note))

    config = ScheduleConfig(
        command="pause",
        url=DEFAULT_URL,
        dashboard_id="7",
        cron="*/1 * * * *",
        schedule_id="cron-orchestrator-run-1m",
        workflow_id="cron-orchestrator-run-runner",
        dry_run_schedule=False,
    )

    handle = _KwOnlyHandle()
    asyncio.run(pause_schedule(handle, config))
    assert handle.calls == [("pause", "manual pause")]

    # resume ne passe pas de note positionnelle non plus.
    asyncio.run(resume_schedule(handle, config))
    assert handle.calls[-1] == ("unpause", None)


def test_create_schedule_handles_already_running(monkeypatch, capsys):
    class DummyScheduleClass:
        def __init__(self, *args, **kwargs):
            pass

    # Reproduit ScheduleAlreadyRunningError sans dépendre du SDK réel.
    import temporalio.client as temporal_client

    class FakeAlreadyRunning(Exception):
        pass

    monkeypatch.setattr(temporal_client, "ScheduleAlreadyRunningError", FakeAlreadyRunning)

    class AlreadyRunningClient:
        async def create_schedule(self, schedule_id, schedule):
            raise FakeAlreadyRunning("exists")

    monkeypatch.setattr(
        schedule_manager,
        "temporal_schedule_classes",
        lambda: (
            DummyScheduleClass,
            lambda *a, **k: DummyScheduleClass(),
            DummyScheduleClass,
            DummyScheduleClass,
            "buffer_one",
        ),
    )

    config = ScheduleConfig(
        command="create",
        url=DEFAULT_URL,
        dashboard_id="7",
        cron="*/1 * * * *",
        schedule_id="cron-orchestrator-run-1m",
        workflow_id="cron-orchestrator-run-runner",
        dry_run_schedule=False,
    )

    asyncio.run(create_schedule(AlreadyRunningClient(), config))

    output = capsys.readouterr().out
    assert "already exists" in output


# ---------------------------------------------------------------------------
# QA-003 : branches restantes (sans serveur Temporal)
# ---------------------------------------------------------------------------


def test_temporal_schedule_classes_returns_sdk_tuple():
    # Import RÉEL des classes Temporal (SDK installé, aucun serveur requis) :
    # couvre les fallbacks d'import et la résolution de l'overlap policy.
    Schedule, action, policy, spec, overlap = schedule_manager.temporal_schedule_classes()

    assert all(item is not None for item in (Schedule, action, policy, spec))
    assert overlap is not None
    # L'action est bien le starter de workflow consommé par create_schedule.
    assert action.__name__ == "ScheduleActionStartWorkflow"


def test_get_client_connects_with_configured_address(monkeypatch):
    import temporalio.client as temporal_client

    captured = {}

    class FakeClient:
        pass

    async def fake_connect(address, namespace=None):
        captured["address"] = address
        captured["namespace"] = namespace
        return FakeClient()

    monkeypatch.setattr(temporal_client.Client, "connect", fake_connect)

    client = asyncio.run(schedule_manager.get_client())

    assert isinstance(client, FakeClient)
    assert captured["address"] == schedule_manager.TEMPORAL_ADDRESS
    assert captured["namespace"] == schedule_manager.TEMPORAL_NAMESPACE


def test_delete_schedule_invokes_handle(capsys):
    class FakeHandle:
        def __init__(self):
            self.deleted = False

        async def delete(self):
            self.deleted = True

    handle = FakeHandle()
    asyncio.run(delete_schedule(handle, _make_config("delete")))

    assert handle.deleted is True
    assert "deleted" in capsys.readouterr().out


def test_describe_schedule_prints_description(capsys):
    class FakeHandle:
        async def describe(self):
            return "DESCRIBE-OBJECT"

    asyncio.run(describe_schedule(FakeHandle()))

    output = capsys.readouterr().out
    assert "schedule status" in output
    assert "DESCRIBE-OBJECT" in output


def test_create_schedule_reraises_unexpected_error(monkeypatch):
    # Une erreur qui N'EST PAS ScheduleAlreadyRunningError doit être propagée
    # (pas masquée en "already exists").
    _patch_schedule_classes(monkeypatch)

    class BoomClient:
        async def create_schedule(self, schedule_id, schedule):
            raise RuntimeError("boom-unexpected")

    with pytest.raises(RuntimeError, match="boom-unexpected"):
        asyncio.run(create_schedule(BoomClient(), _make_config("create")))


def test_async_main_create_connects_and_creates(monkeypatch, capsys):
    _patch_schedule_classes(monkeypatch)
    created = {}

    class FakeClient:
        async def create_schedule(self, schedule_id, schedule):
            created["schedule_id"] = schedule_id

    async def fake_get_client():
        return FakeClient()

    monkeypatch.setattr(schedule_manager, "get_client", fake_get_client)

    parser = build_parser()
    args = parser.parse_args(["create", "--dashboard-id", "7"])
    asyncio.run(async_main(args))

    assert created["schedule_id"] == "cron-orchestrator-run-1m"
    assert "created schedule" in capsys.readouterr().out


@pytest.mark.parametrize("command", ["pause", "resume", "delete", "status"])
def test_async_main_routes_lifecycle_commands(monkeypatch, command):
    calls = []

    class FakeHandle:
        async def pause(self, *, note=None):
            calls.append(("pause", note))

        async def unpause(self, *, note=None):
            calls.append(("unpause", note))

        async def delete(self):
            calls.append(("delete",))

        async def describe(self):
            calls.append(("describe",))
            return "DESC"

    class FakeClient:
        def get_schedule_handle(self, schedule_id):
            calls.append(("handle", schedule_id))
            return FakeHandle()

    async def fake_get_client():
        return FakeClient()

    monkeypatch.setattr(schedule_manager, "get_client", fake_get_client)

    parser = build_parser()
    args = parser.parse_args([command, "--schedule-id", "cron-orchestrator-run-1m"])
    asyncio.run(async_main(args))

    # Le handle est résolu sur le bon schedule_id, puis l'action est invoquée.
    assert ("handle", "cron-orchestrator-run-1m") in calls
    actions = {c[0] for c in calls}
    expected = {
        "pause": "pause",
        "resume": "unpause",
        "delete": "delete",
        "status": "describe",
    }[command]
    assert expected in actions


def test_async_main_unknown_command_resolves_handle_but_noops(monkeypatch):
    # Branche défensive : une commande hors {create,pause,resume,delete,status}
    # (non atteignable via le parser, mais possible via une Namespace forgée)
    # résout le handle puis ne fait rien — aucune action n'est invoquée.
    import argparse

    calls = []

    class FakeHandle:
        async def pause(self, *, note=None):
            calls.append("pause")

        async def unpause(self, *, note=None):
            calls.append("unpause")

        async def delete(self):
            calls.append("delete")

        async def describe(self):
            calls.append("describe")

    class FakeClient:
        def get_schedule_handle(self, schedule_id):
            calls.append(("handle", schedule_id))
            return FakeHandle()

    async def fake_get_client():
        return FakeClient()

    monkeypatch.setattr(schedule_manager, "get_client", fake_get_client)

    args = argparse.Namespace(command="bogus", schedule_id="cron-orchestrator-run-1m")
    asyncio.run(async_main(args))

    assert calls == [("handle", "cron-orchestrator-run-1m")]


def test_main_parses_args_and_runs(monkeypatch):
    ran = {}

    async def fake_async_main(args):
        ran["command"] = args.command
        ran["schedule_id"] = args.schedule_id

    monkeypatch.setattr(schedule_manager, "async_main", fake_async_main)
    monkeypatch.setattr(sys, "argv", ["prog", "status", "--schedule-id", "x"])

    schedule_manager.main()

    assert ran["command"] == "status"
    assert ran["schedule_id"] == "x"
