"""Tests for the generic exchange/profile Temporal schedule manager."""

import asyncio

import pytest

import scripts.manage_exchange_profile_schedule as schedule_manager
from scripts.manage_exchange_profile_schedule import (
    OKX_DRY_RUN_ONLY_MESSAGE,
    ScheduleConfig,
    assert_exchange_schedule_policy,
    async_main,
    build_job,
    build_parser,
    create_schedule,
    generate_schedule_id,
    generate_workflow_id,
    parse_runtime_check_output,
    resolve_schedule_config,
    runtime_check_command,
    validate_live_guardrails,
)


def test_build_job_okx_scalper_payload_is_explicit():
    job = build_job(
        exchange="okx",
        market_type="perpetual",
        profile="scalper",
        workers=4,
        dry_run=True,
    )

    assert job == {
        "url": "http://trading-app-nginx:80/api/mtf/run",
        "workers": 4,
        "dry_run": True,
        "mtf_profile": "scalper",
        "exchange": "okx",
        "market_type": "perpetual",
    }


def test_build_job_bitmart_scalper_micro_payload_is_explicit():
    job = build_job(
        exchange="bitmart",
        market_type="perpetual",
        profile="scalper_micro",
        workers=8,
        dry_run=False,
    )

    assert job["dry_run"] is False
    assert job["workers"] == 8
    assert job["mtf_profile"] == "scalper_micro"
    assert job["exchange"] == "bitmart"
    assert job["market_type"] == "perpetual"


def test_generated_ids_use_exchange_profile_and_cron_suffix():
    assert generate_schedule_id("okx", "scalper", "*/1 * * * *") == "cron-mtf-okx-scalper-1m"
    assert generate_schedule_id("bitmart", "regular", "*/5 * * * *") == "cron-mtf-bitmart-regular-5m"
    assert generate_workflow_id("okx", "scalper_micro") == "mtf-okx-scalper-micro-runner"


def test_generated_ids_include_non_perpetual_market_type_to_avoid_collisions():
    assert (
        generate_schedule_id("okx", "scalper", "*/1 * * * *", market_type="spot")
        == "cron-mtf-okx-spot-scalper-1m"
    )
    assert generate_workflow_id("okx", "scalper", market_type="spot") == "mtf-okx-spot-scalper-runner"


def test_runtime_check_output_is_parsed_to_snake_case_keys():
    parsed = parse_runtime_check_output(
        "\n".join(
            [
                "Exchange: okx",
                "Market type: perpetual",
                "Schedule ready: no",
                "Live trading: disabled",
            ]
        )
    )

    assert parsed["exchange"] == "okx"
    assert parsed["market_type"] == "perpetual"
    assert parsed["schedule_ready"] == "no"
    assert parsed["live_trading"] == "disabled"


def test_dry_run_allows_unready_runtime_with_warning():
    warnings = validate_live_guardrails(
        dry_run=True,
        runtime_check={"schedule_ready": "no"},
    )

    assert warnings == [
        "Runtime check reports Schedule ready: no; schedule creation is allowed because dry_run=true."
    ]


def test_live_run_rejects_unready_runtime():
    with pytest.raises(RuntimeError, match="schedule_ready must be yes"):
        validate_live_guardrails(
            dry_run=False,
            runtime_check={
                "schedule_ready": "no",
                "credentials": "missing",
                "live_trading": "disabled",
            },
        )


def test_parser_defaults_to_dry_run_and_generated_ids():
    parser = build_parser()
    args = parser.parse_args(["create", "--exchange", "okx", "--profile", "scalper"])
    config = resolve_schedule_config(args)

    assert config.dry_run is True
    assert config.cron == "*/1 * * * *"
    assert config.schedule_id == "cron-mtf-okx-scalper-1m"
    assert config.workflow_id == "mtf-okx-scalper-runner"
    assert config.market_type == "perpetual"


def test_parser_honors_dry_run_environment_default(monkeypatch):
    monkeypatch.setenv("MTF_WORKERS_DRY_RUN", "false")

    parser = build_parser()
    args = parser.parse_args(["create", "--exchange", "bitmart", "--profile", "scalper"])
    config = resolve_schedule_config(args)

    assert config.dry_run is False


def test_explicit_dry_run_overrides_environment_default(monkeypatch):
    monkeypatch.setenv("MTF_WORKERS_DRY_RUN", "false")

    parser = build_parser()
    args = parser.parse_args(["create", "--exchange", "bitmart", "--profile", "scalper", "--dry-run=true"])
    config = resolve_schedule_config(args)

    assert config.dry_run is True


def test_status_can_use_explicit_schedule_id_without_exchange_or_profile():
    parser = build_parser()
    args = parser.parse_args(["status", "--schedule-id", "cron-mtf-okx-scalper-1m"])
    config = resolve_schedule_config(args)

    assert config.schedule_id == "cron-mtf-okx-scalper-1m"
    assert config.workflow_id == "mtf-schedule-runner"


def test_runtime_check_command_uses_docker_compose():
    assert runtime_check_command("okx", "perpetual") == [
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


def test_create_dry_run_schedule_preview_skips_temporal_and_runtime_check(monkeypatch, capsys):
    async def fail_get_client():
        raise AssertionError("dry-run schedule preview must not connect to Temporal")

    def fail_runtime_check(exchange, market_type):
        raise AssertionError("dry-run schedule preview must not run Docker runtime checks")

    monkeypatch.setattr(schedule_manager, "get_client", fail_get_client)
    monkeypatch.setattr(schedule_manager, "run_runtime_check", fail_runtime_check)

    parser = build_parser()
    args = parser.parse_args(
        [
            "create",
            "--exchange",
            "okx",
            "--profile",
            "scalper",
            "--dry-run-schedule",
        ]
    )

    asyncio.run(async_main(args))

    output = capsys.readouterr().out
    assert "[DRY-RUN] would create schedule cron-mtf-okx-scalper-1m" in output
    assert "'exchange': 'okx'" in output


def test_create_live_schedule_with_runtime_check_bypass_skips_guardrail_validation(monkeypatch):
    def fail_runtime_check(exchange, market_type):
        raise AssertionError("explicit runtime-check bypass must not run Docker runtime checks")

    class DummyScheduleClass:
        def __init__(self, *args, **kwargs):
            self.args = args
            self.kwargs = kwargs

    class CapturingClient:
        def __init__(self):
            self.created = []

        async def create_schedule(self, schedule_id, schedule):
            self.created.append((schedule_id, schedule))

    monkeypatch.setattr(schedule_manager, "run_runtime_check", fail_runtime_check)
    monkeypatch.setattr(
        schedule_manager,
        "temporal_schedule_classes",
        lambda: (
            DummyScheduleClass,
            DummyScheduleClass,
            DummyScheduleClass,
            DummyScheduleClass,
            "buffer_one",
        ),
    )

    # Bitmart is not dry-run-only, so the bypass still creates a live schedule.
    config = ScheduleConfig(
        command="create",
        exchange="bitmart",
        market_type="perpetual",
        profile="scalper",
        workers=4,
        dry_run=False,
        cron="*/1 * * * *",
        schedule_id="cron-mtf-bitmart-scalper-1m",
        workflow_id="mtf-bitmart-scalper-runner",
        dry_run_schedule=False,
        skip_runtime_check=True,
    )
    client = CapturingClient()

    asyncio.run(create_schedule(client, config))

    assert client.created[0][0] == "cron-mtf-bitmart-scalper-1m"


def test_create_live_okx_schedule_is_blocked_even_with_runtime_check_bypass():
    class FailingClient:
        async def create_schedule(self, schedule_id, schedule):
            raise AssertionError("OKX live schedule must never reach Temporal creation")

    config = ScheduleConfig(
        command="create",
        exchange="okx",
        market_type="perpetual",
        profile="scalper",
        workers=4,
        dry_run=False,
        cron="*/1 * * * *",
        schedule_id="cron-mtf-okx-scalper-1m",
        workflow_id="mtf-okx-scalper-runner",
        dry_run_schedule=False,
        skip_runtime_check=True,
    )

    with pytest.raises(RuntimeError, match=OKX_DRY_RUN_ONLY_MESSAGE):
        asyncio.run(create_schedule(FailingClient(), config))


def test_resolve_schedule_config_blocks_live_okx_create():
    parser = build_parser()
    args = parser.parse_args(
        ["create", "--exchange", "okx", "--profile", "scalper", "--dry-run=false"]
    )

    with pytest.raises(RuntimeError, match=OKX_DRY_RUN_ONLY_MESSAGE):
        resolve_schedule_config(args)


def test_resolve_schedule_config_allows_dry_run_okx_create():
    parser = build_parser()
    args = parser.parse_args(
        ["create", "--exchange", "okx", "--profile", "scalper", "--dry-run=true"]
    )

    config = resolve_schedule_config(args)

    assert config.exchange == "okx"
    assert config.dry_run is True


def test_assert_exchange_schedule_policy_allows_live_for_non_dry_run_only_exchanges():
    # Bitmart legacy can still go live; only dry-run-only exchanges (OKX) are blocked.
    assert_exchange_schedule_policy("bitmart", dry_run=False) is None
    assert_exchange_schedule_policy("okx", dry_run=True) is None


def test_assert_exchange_schedule_policy_blocks_uppercase_okx_live():
    # The gate normalizes casing/whitespace: a hand-built "OKX" must not bypass it.
    with pytest.raises(RuntimeError, match=OKX_DRY_RUN_ONLY_MESSAGE):
        assert_exchange_schedule_policy("OKX", dry_run=False)

    with pytest.raises(RuntimeError, match=OKX_DRY_RUN_ONLY_MESSAGE):
        assert_exchange_schedule_policy("  Okx ", dry_run=False)
