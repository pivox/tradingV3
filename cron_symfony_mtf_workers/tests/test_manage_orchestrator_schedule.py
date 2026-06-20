"""Tests du gestionnaire de schedule cron cible vers l'orchestrateur (TM-001)."""

import asyncio

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
    pause_schedule,
    resolve_schedule_config,
    resume_schedule,
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
