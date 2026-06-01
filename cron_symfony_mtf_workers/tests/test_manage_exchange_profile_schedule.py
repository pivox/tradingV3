"""Tests for the generic exchange/profile Temporal schedule manager."""

import pytest

from scripts.manage_exchange_profile_schedule import (
    build_job,
    build_parser,
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
