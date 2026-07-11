"""Tests for the guarded demo/testnet orchestrator Temporal schedule."""

import asyncio
import argparse
import sys
import types

import pytest

import scripts.manage_demo_testnet_schedule as schedule_manager
from scripts.manage_demo_testnet_schedule import (
    DEFAULT_SCHEDULE_ID,
    DEFAULT_WORKFLOW_ID,
    SUPPORTED_ENVIRONMENT,
    ScheduleConfig,
    assert_dashboard_configured,
    assert_demo_environment,
    async_main,
    build_parser,
    build_runtime_check_command,
    build_workflow_config,
    create_schedule,
    delete_schedule,
    describe_schedule,
    ensure_runtime_checks_pass,
    dashboard_is_valid,
    parse_runtime_check_output,
    pause_schedule,
    resolve_schedule_config,
    resume_schedule,
    run_runtime_check,
    validate_schedule_definition,
    validate_runtime_checks,
)


class _DummyTemporalClass:
    def __init__(self, *args, **kwargs):
        self.args = args
        self.kwargs = kwargs


class _EncodedPayload:
    metadata = {}
    data = b"encoded"


class _FakeDataConverter:
    def __init__(self, decoded=None, exc=None):
        self.decoded = decoded
        self.exc = exc
        self.calls = []

    def decode(self, payloads, type_hints=None):
        self.calls.append((payloads, type_hints))
        if self.exc is not None:
            raise self.exc
        return self.decoded


class _AsyncFakeDataConverter(_FakeDataConverter):
    async def decode(self, payloads, type_hints=None):
        return super().decode(payloads, type_hints)


def _description(
    *,
    workflow="OrchestratorCronWorkflow",
    args=None,
    workflow_id=DEFAULT_WORKFLOW_ID,
    task_queue=None,
    data_converter=None,
    paused=True,
):
    if args is None:
        args = [
            {
                "url": "http://python-orchestrator:8099/orchestrator/run",
                "dashboard_id": "7",
                "schedule_id": DEFAULT_SCHEDULE_ID,
                "dry_run": True,
            }
        ]
    return types.SimpleNamespace(
        schedule=types.SimpleNamespace(
            action=types.SimpleNamespace(
                workflow=workflow,
                args=args,
                id=workflow_id,
                task_queue=task_queue or schedule_manager.TASK_QUEUE,
            ),
            state=types.SimpleNamespace(paused=paused),
        ),
        data_converter=data_converter,
    )


def _patch_schedule_classes(monkeypatch, captured):
    def action(workflow_type, *, args, id, task_queue):
        captured["workflow_type"] = workflow_type
        captured["workflow_args"] = args
        captured["workflow_id"] = id
        captured["task_queue"] = task_queue
        return _DummyTemporalClass()

    monkeypatch.setattr(
        schedule_manager,
        "temporal_schedule_classes",
        lambda: (
            _DummyTemporalClass,
            action,
            _DummyTemporalClass,
            _DummyTemporalClass,
            _DummyTemporalClass,
            "buffer_one",
        ),
    )


def _config(command="create", **overrides):
    base = dict(
        command=command,
        url="http://python-orchestrator:8099/orchestrator/run",
        dashboard_id="7",
        cron="*/1 * * * *",
        schedule_id=DEFAULT_SCHEDULE_ID,
        workflow_id=DEFAULT_WORKFLOW_ID,
        dry_run_schedule=False,
        paused=True,
        resume_on_create=False,
        skip_runtime_check=False,
        environment=SUPPORTED_ENVIRONMENT,
    )
    base.update(overrides)
    return ScheduleConfig(**base)


def test_workflow_config_forces_demo_schedule_dry_run():
    config = build_workflow_config(
        url="http://python-orchestrator:8099/orchestrator/run",
        dashboard_id="7",
        schedule_id=DEFAULT_SCHEDULE_ID,
    )

    assert config == {
        "url": "http://python-orchestrator:8099/orchestrator/run",
        "dashboard_id": "7",
        "schedule_id": DEFAULT_SCHEDULE_ID,
        "dry_run": True,
    }


def test_workflow_config_omits_absent_dashboard():
    config = build_workflow_config(
        url="http://python-orchestrator:8099/orchestrator/run",
        dashboard_id=None,
        schedule_id=DEFAULT_SCHEDULE_ID,
    )

    assert config == {
        "url": "http://python-orchestrator:8099/orchestrator/run",
        "schedule_id": DEFAULT_SCHEDULE_ID,
        "dry_run": True,
    }


def test_parser_defaults_to_dedicated_paused_demo_schedule():
    parser = build_parser()
    args = parser.parse_args(["create", "--dashboard-id", "7"])
    config = resolve_schedule_config(args)

    assert config.schedule_id == DEFAULT_SCHEDULE_ID
    assert config.workflow_id == DEFAULT_WORKFLOW_ID
    assert config.paused is True
    assert config.resume_on_create is False
    assert config.environment == SUPPORTED_ENVIRONMENT


def test_parser_resume_on_create_creates_active_config():
    parser = build_parser()
    args = parser.parse_args(["create", "--dashboard-id", "7", "--resume-on-create"])
    config = resolve_schedule_config(args)

    assert config.resume_on_create is True
    assert config.paused is False


def test_mainnet_environment_is_rejected():
    with pytest.raises(RuntimeError, match="mainnet schedules are forbidden"):
        assert_demo_environment("mainnet")


def test_dashboard_validation_rejects_missing_blank_and_non_numeric():
    assert dashboard_is_valid("7") is True
    assert dashboard_is_valid(" 7 ") is True
    assert dashboard_is_valid(None) is False
    assert dashboard_is_valid("") is False
    assert dashboard_is_valid("   ") is False
    assert dashboard_is_valid("abc") is False


def test_create_without_dashboard_fails_unless_preview():
    with pytest.raises(SystemExit, match="no valid demo/testnet dashboard configured"):
        assert_dashboard_configured(_config(dashboard_id=None, dry_run_schedule=False))

    warning = assert_dashboard_configured(_config(dashboard_id=None, dry_run_schedule=True))
    assert "no valid demo/testnet dashboard configured" in warning


def test_create_schedule_is_paused_by_default(monkeypatch):
    captured = {}
    _patch_schedule_classes(monkeypatch, captured)

    class CapturingClient:
        def __init__(self):
            self.created = []

        async def create_schedule(self, schedule_id, schedule):
            self.created.append((schedule_id, schedule))

    client = CapturingClient()
    asyncio.run(create_schedule(client, _config()))

    assert client.created[0][0] == DEFAULT_SCHEDULE_ID
    schedule = client.created[0][1]
    assert schedule.kwargs["state"].kwargs["paused"] is True
    assert "paused by default" in schedule.kwargs["state"].kwargs["note"]
    assert captured["workflow_type"] == "OrchestratorCronWorkflow"
    assert captured["workflow_args"] == [
        {
            "url": "http://python-orchestrator:8099/orchestrator/run",
            "dashboard_id": "7",
            "schedule_id": DEFAULT_SCHEDULE_ID,
            "dry_run": True,
        }
    ]


def test_create_schedule_preview_with_missing_dashboard_warns(capsys):
    asyncio.run(create_schedule(object(), _config(dashboard_id=None, dry_run_schedule=True)))

    output = capsys.readouterr().out
    assert "WARNING: no valid demo/testnet dashboard configured" in output
    assert "[DRY-RUN] would create demo/testnet schedule" in output


def test_create_schedule_can_be_active_after_runtime_checks(monkeypatch):
    calls = []
    captured = {}
    _patch_schedule_classes(monkeypatch, captured)

    def pass_runtime_checks(skip):
        calls.append(("runtime_checks", skip))

    monkeypatch.setattr(schedule_manager, "ensure_runtime_checks_pass", pass_runtime_checks)

    class CapturingClient:
        def __init__(self):
            self.created = []

        async def create_schedule(self, schedule_id, schedule):
            self.created.append((schedule_id, schedule))

    client = CapturingClient()
    asyncio.run(create_schedule(client, _config(paused=False, resume_on_create=True)))

    assert calls == [("runtime_checks", False)]
    schedule = client.created[0][1]
    assert schedule.kwargs["state"].kwargs["paused"] is False
    assert schedule.kwargs["state"].kwargs["note"] == "demo/testnet runtime checks passed"


def test_create_resume_on_create_requires_runtime_checks(monkeypatch):
    def fail_runtime_checks(skip):
        raise RuntimeError("okx runtime-check failed")

    monkeypatch.setattr(schedule_manager, "ensure_runtime_checks_pass", fail_runtime_checks)

    class FailingClient:
        async def create_schedule(self, schedule_id, schedule):
            raise AssertionError("schedule must not be created when readiness fails")

    with pytest.raises(RuntimeError, match="okx runtime-check failed"):
        asyncio.run(create_schedule(FailingClient(), _config(resume_on_create=True, paused=False)))


def test_resume_requires_runtime_checks_before_unpause(monkeypatch):
    def fail_runtime_checks(skip):
        raise RuntimeError("hyperliquid runtime-check failed")

    monkeypatch.setattr(schedule_manager, "ensure_runtime_checks_pass", fail_runtime_checks)

    class Handle:
        def __init__(self):
            self.unpaused = False

        async def unpause(self, *, note=None):
            self.unpaused = True

    handle = Handle()
    with pytest.raises(RuntimeError, match="hyperliquid runtime-check failed"):
        asyncio.run(resume_schedule(handle, _config("resume")))

    assert handle.unpaused is False


def test_resume_unpauses_when_runtime_checks_pass(monkeypatch):
    calls = []

    def pass_runtime_checks(skip):
        calls.append(("runtime_checks", skip))

    monkeypatch.setattr(schedule_manager, "ensure_runtime_checks_pass", pass_runtime_checks)

    class Handle:
        async def describe(self):
            calls.append(("describe",))
            return _description()

        async def unpause(self, *, note=None):
            calls.append(("unpause", note))

    asyncio.run(resume_schedule(Handle(), _config("resume")))

    assert calls == [
        ("runtime_checks", False),
        ("describe",),
        ("unpause", "demo/testnet runtime checks passed"),
    ]


def test_resume_rejects_unsafe_existing_schedule_before_unpause(monkeypatch):
    monkeypatch.setattr(schedule_manager, "ensure_runtime_checks_pass", lambda skip: None)

    class Handle:
        def __init__(self):
            self.unpaused = False

        async def describe(self):
            return _description(
                workflow="CronSymfonyMtfWorkersWorkflow",
                args=[[{"dry_run": False, "exchange": "bitmart"}]],
            )

        async def unpause(self, *, note=None):
            self.unpaused = True

    handle = Handle()
    with pytest.raises(RuntimeError, match="unexpected workflow"):
        asyncio.run(resume_schedule(handle, _config("resume")))

    assert handle.unpaused is False


def test_validate_schedule_definition_rejects_missing_dry_run():
    unsafe_description = _description(args=[{"dashboard_id": "7", "schedule_id": DEFAULT_SCHEDULE_ID}])

    with pytest.raises(RuntimeError, match="unexpected workflow args"):
        asyncio.run(validate_schedule_definition(unsafe_description, _config("resume")))


def test_validate_schedule_definition_decodes_temporal_payload_args():
    decoded_args = [
        {
            "url": "http://python-orchestrator:8099/orchestrator/run",
            "dashboard_id": "7",
            "schedule_id": DEFAULT_SCHEDULE_ID,
            "dry_run": True,
        }
    ]
    converter = _AsyncFakeDataConverter(decoded=decoded_args)
    payload = _EncodedPayload()

    asyncio.run(
        validate_schedule_definition(
            _description(args=[payload], data_converter=converter),
            _config("resume"),
        )
    )

    assert converter.calls == [([payload], None)]


def test_validate_schedule_definition_supports_sync_converter_and_requires_converter():
    decoded_args = [
        {
            "url": "http://python-orchestrator:8099/orchestrator/run",
            "dashboard_id": "7",
            "schedule_id": DEFAULT_SCHEDULE_ID,
            "dry_run": True,
        }
    ]
    asyncio.run(
        validate_schedule_definition(
            _description(args=[_EncodedPayload()], data_converter=_FakeDataConverter(decoded=decoded_args)),
            _config("resume"),
        )
    )

    with pytest.raises(RuntimeError, match="missing data converter"):
        asyncio.run(
            validate_schedule_definition(
                _description(args=[_EncodedPayload()], data_converter=None),
                _config("resume"),
            )
        )


def test_validate_schedule_definition_reports_payload_decode_failure():
    converter = _FakeDataConverter(exc=ValueError("bad payload"))

    with pytest.raises(RuntimeError, match="could not decode Temporal schedule args"):
        asyncio.run(
            validate_schedule_definition(
                _description(args=[_EncodedPayload()], data_converter=converter),
                _config("resume"),
            )
        )


def test_validate_schedule_definition_rejects_wrong_workflow_id_and_task_queue():
    with pytest.raises(RuntimeError, match="unexpected workflow id"):
        asyncio.run(
            validate_schedule_definition(_description(workflow_id="other-workflow"), _config("resume"))
        )

    with pytest.raises(RuntimeError, match="unexpected task queue"):
        asyncio.run(
            validate_schedule_definition(_description(task_queue="other-task-queue"), _config("resume"))
        )


def test_pause_and_delete_delegate_to_handle():
    calls = []

    class Handle:
        async def pause(self, *, note=None):
            calls.append(("pause", note))

        async def delete(self):
            calls.append(("delete",))

    asyncio.run(pause_schedule(Handle(), _config("pause")))
    asyncio.run(delete_schedule(Handle(), _config("delete")))

    assert calls == [("pause", "manual demo/testnet pause"), ("delete",)]


def test_describe_schedule_prints_description(capsys):
    class Handle:
        async def describe(self):
            return "DEMO-SCHEDULE-DESCRIPTION"

    asyncio.run(describe_schedule(Handle()))

    output = capsys.readouterr().out
    assert "schedule status" in output
    assert "DEMO-SCHEDULE-DESCRIPTION" in output


def test_runtime_check_commands_cover_okx_and_hyperliquid():
    assert build_runtime_check_command("okx") == [
        "docker",
        "compose",
        "exec",
        "-T",
        "trading-app-php",
        "php",
        "bin/console",
        "app:exchange:runtime-check",
        "okx",
        "perpetual",
    ]
    assert build_runtime_check_command("hyperliquid")[-2:] == ["hyperliquid", "perpetual"]


def test_runtime_check_output_parser_skips_non_key_value_lines():
    parsed = parse_runtime_check_output(
        "not-a-pair\nExchange: okx\nSchedule ready: yes\nReadiness level: demo_testnet_candidate"
    )

    assert parsed == {
        "exchange": "okx",
        "schedule_ready": "yes",
        "readiness_level": "demo_testnet_candidate",
    }


def test_validate_runtime_checks_requires_schedule_ready_yes():
    okx = parse_runtime_check_output("Exchange: okx\nSchedule ready: yes\n")
    hyperliquid = parse_runtime_check_output(
        "Exchange: hyperliquid\nSchedule ready: no\n"
    )

    with pytest.raises(RuntimeError, match="hyperliquid runtime-check schedule_ready must be yes"):
        validate_runtime_checks({"okx": okx, "hyperliquid": hyperliquid})


def test_validate_runtime_checks_rejects_wrong_exchange_and_mainnet_readiness():
    with pytest.raises(RuntimeError, match="okx runtime-check returned exchange='bitmart'"):
        validate_runtime_checks(
            {
                "okx": {"exchange": "bitmart", "schedule_ready": "yes"},
                "hyperliquid": {"schedule_ready": "yes"},
            }
        )

    with pytest.raises(RuntimeError, match="forbidden readiness_level=mainnet_ready"):
        validate_runtime_checks(
            {
                "okx": {"exchange": "okx", "schedule_ready": "yes", "readiness_level": "mainnet_ready"},
                "hyperliquid": {"schedule_ready": "yes"},
            }
        )


def test_ensure_runtime_checks_rejects_skip_and_runs_both_checks(monkeypatch):
    with pytest.raises(RuntimeError, match="cannot skip runtime-check"):
        ensure_runtime_checks_pass(skip_runtime_check=True)

    calls = []

    def fake_run_runtime_check(exchange):
        calls.append(exchange)
        return {"exchange": exchange, "schedule_ready": "yes"}

    monkeypatch.setattr(schedule_manager, "run_runtime_check", fake_run_runtime_check)

    ensure_runtime_checks_pass()

    assert calls == ["okx", "hyperliquid"]


def test_run_runtime_check_parses_success_and_reports_failure(monkeypatch):
    class Result:
        def __init__(self, returncode, stdout="", stderr=""):
            self.returncode = returncode
            self.stdout = stdout
            self.stderr = stderr

    calls = []

    def fake_run(command, cwd, capture_output, check, text):
        calls.append((command, cwd, capture_output, check, text))
        return Result(0, stdout="Exchange: okx\nSchedule ready: yes\n")

    monkeypatch.setattr(schedule_manager.subprocess, "run", fake_run)

    assert run_runtime_check("okx") == {"exchange": "okx", "schedule_ready": "yes"}
    assert calls[0][0] == build_runtime_check_command("okx")

    def fake_fail(command, cwd, capture_output, check, text):
        return Result(1, stdout="", stderr="boom")

    monkeypatch.setattr(schedule_manager.subprocess, "run", fake_fail)

    with pytest.raises(RuntimeError, match="okx runtime-check failed with exit code 1: boom"):
        run_runtime_check("okx")


def test_async_main_create_preview_skips_temporal(monkeypatch, capsys):
    async def fail_get_client():
        raise AssertionError("preview must not connect to Temporal")

    monkeypatch.setattr(schedule_manager, "get_client", fail_get_client)

    parser = build_parser()
    args = parser.parse_args(["create", "--dashboard-id", "7", "--dry-run"])

    asyncio.run(async_main(args))

    assert "[DRY-RUN] would create demo/testnet schedule" in capsys.readouterr().out


def test_async_main_create_connects_and_lifecycle_routes(monkeypatch):
    _patch_schedule_classes(monkeypatch, {})
    calls = []

    def pass_runtime_checks(skip):
        calls.append(("runtime_checks", skip))

    monkeypatch.setattr(schedule_manager, "ensure_runtime_checks_pass", pass_runtime_checks)

    class Handle:
        async def pause(self, *, note=None):
            calls.append(("pause", note))

        async def unpause(self, *, note=None):
            calls.append(("unpause", note))

        async def delete(self):
            calls.append(("delete",))

        async def describe(self):
            calls.append(("describe",))
            return _description()

    class Client:
        async def create_schedule(self, schedule_id, schedule):
            calls.append(("create", schedule_id))

        def get_schedule_handle(self, schedule_id):
            calls.append(("handle", schedule_id))
            return Handle()

    async def fake_get_client():
        return Client()

    monkeypatch.setattr(schedule_manager, "get_client", fake_get_client)

    parser = build_parser()
    for argv in (
        ["create", "--dashboard-id", "7"],
        ["pause"],
        ["resume", "--dashboard-id", "7"],
        ["delete"],
        ["status"],
    ):
        asyncio.run(async_main(parser.parse_args(argv)))

    assert ("create", DEFAULT_SCHEDULE_ID) in calls
    assert ("pause", "manual demo/testnet pause") in calls
    assert ("unpause", "demo/testnet runtime checks passed") in calls
    assert ("delete",) in calls
    assert ("describe",) in calls


def test_create_schedule_handles_already_running(monkeypatch, capsys):
    captured = {}
    _patch_schedule_classes(monkeypatch, captured)

    class FakeAlreadyRunning(Exception):
        pass

    temporalio_module = types.ModuleType("temporalio")
    temporalio_client_module = types.ModuleType("temporalio.client")
    temporalio_client_module.ScheduleAlreadyRunningError = FakeAlreadyRunning
    monkeypatch.setitem(sys.modules, "temporalio", temporalio_module)
    monkeypatch.setitem(sys.modules, "temporalio.client", temporalio_client_module)

    class Handle:
        async def describe(self):
            return _description()

    class AlreadyRunningClient:
        async def create_schedule(self, schedule_id, schedule):
            raise FakeAlreadyRunning("exists")

        def get_schedule_handle(self, schedule_id):
            return Handle()

    asyncio.run(create_schedule(AlreadyRunningClient(), _config()))

    output = capsys.readouterr().out
    assert "already exists" in output
    assert "Validated existing definition and pause state" in output


def test_create_schedule_reapplies_paused_state_for_existing_active_schedule(monkeypatch):
    _patch_schedule_classes(monkeypatch, {})

    class FakeAlreadyRunning(Exception):
        pass

    temporalio_module = types.ModuleType("temporalio")
    temporalio_client_module = types.ModuleType("temporalio.client")
    temporalio_client_module.ScheduleAlreadyRunningError = FakeAlreadyRunning
    monkeypatch.setitem(sys.modules, "temporalio", temporalio_module)
    monkeypatch.setitem(sys.modules, "temporalio.client", temporalio_client_module)

    calls = []

    class Handle:
        async def describe(self):
            calls.append(("describe",))
            return _description(paused=False)

        async def pause(self, *, note=None):
            calls.append(("pause", note))

    class AlreadyRunningClient:
        async def create_schedule(self, schedule_id, schedule):
            raise FakeAlreadyRunning("exists")

        def get_schedule_handle(self, schedule_id):
            return Handle()

    asyncio.run(create_schedule(AlreadyRunningClient(), _config()))

    assert calls == [
        ("describe",),
        ("pause", "demo/testnet create re-applied paused-by-default"),
    ]


def test_create_schedule_rejects_existing_unsafe_schedule(monkeypatch):
    _patch_schedule_classes(monkeypatch, {})

    class FakeAlreadyRunning(Exception):
        pass

    temporalio_module = types.ModuleType("temporalio")
    temporalio_client_module = types.ModuleType("temporalio.client")
    temporalio_client_module.ScheduleAlreadyRunningError = FakeAlreadyRunning
    monkeypatch.setitem(sys.modules, "temporalio", temporalio_module)
    monkeypatch.setitem(sys.modules, "temporalio.client", temporalio_client_module)

    class Handle:
        async def describe(self):
            return _description(args=[{"dashboard_id": "7", "schedule_id": DEFAULT_SCHEDULE_ID}])

    class AlreadyRunningClient:
        async def create_schedule(self, schedule_id, schedule):
            raise FakeAlreadyRunning("exists")

        def get_schedule_handle(self, schedule_id):
            return Handle()

    with pytest.raises(RuntimeError, match="unexpected workflow args"):
        asyncio.run(create_schedule(AlreadyRunningClient(), _config()))


def test_create_schedule_reraises_unexpected_create_error(monkeypatch):
    _patch_schedule_classes(monkeypatch, {})

    class BoomClient:
        async def create_schedule(self, schedule_id, schedule):
            raise RuntimeError("boom")

    with pytest.raises(RuntimeError, match="boom"):
        asyncio.run(create_schedule(BoomClient(), _config()))


def test_async_main_unknown_command_resolves_handle_but_noops(monkeypatch):
    calls = []

    class Handle:
        async def pause(self, *, note=None):
            calls.append("pause")

        async def unpause(self, *, note=None):
            calls.append("unpause")

        async def delete(self):
            calls.append("delete")

        async def describe(self):
            calls.append("describe")

    class Client:
        def get_schedule_handle(self, schedule_id):
            calls.append(("handle", schedule_id))
            return Handle()

    async def fake_get_client():
        return Client()

    monkeypatch.setattr(schedule_manager, "get_client", fake_get_client)

    args = argparse.Namespace(command="bogus", schedule_id=DEFAULT_SCHEDULE_ID)
    asyncio.run(async_main(args))

    assert calls == [("handle", DEFAULT_SCHEDULE_ID)]


def test_main_parses_args_and_runs(monkeypatch):
    ran = {}

    async def fake_async_main(args):
        ran["command"] = args.command
        ran["schedule_id"] = args.schedule_id

    monkeypatch.setattr(schedule_manager, "async_main", fake_async_main)
    monkeypatch.setattr(sys, "argv", ["prog", "status", "--schedule-id", "demo-schedule"])

    schedule_manager.main()

    assert ran == {"command": "status", "schedule_id": "demo-schedule"}
