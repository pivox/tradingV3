"""Tests for the dashboard-driven Temporal schedule manager (Temporal-native orchestrator)."""

import asyncio

import pytest

yaml = pytest.importorskip("yaml")

import scripts.manage_dashboard_schedule as dash  # noqa: E402
import scripts.manage_exchange_profile_schedule as base  # noqa: E402
from dashboards.model import Dashboard, DashboardTarget  # noqa: E402


def _dashboard():
    return Dashboard(
        dashboard_id="okx-hl",
        targets=[
            DashboardTarget(target_id="okx", exchange="okx", mtf_profile="scalper", dry_run=True),
        ],
        cadence="*/1 * * * *",
    )


def test_generate_ids():
    dashboard = _dashboard()

    assert dash.generate_dashboard_schedule_id(dashboard) == "cron-mtf-dashboard-okx-hl-1m"
    assert dash.generate_dashboard_workflow_id(dashboard) == "mtf-dashboard-okx-hl-runner"


def test_build_workflow_request():
    request = dash.build_workflow_request(_dashboard(), dashboards_path="dashboards/x.yaml")

    assert request == {"dashboard_id": "okx-hl", "dashboards_path": "dashboards/x.yaml"}


def _write_dashboards(tmp_path, payload):
    path = tmp_path / "dashboards.yaml"
    path.write_text(yaml.safe_dump(payload), encoding="utf-8")
    return str(path)


def test_create_dry_run_preview_skips_temporal(tmp_path, monkeypatch, capsys):
    async def fail_client():
        raise AssertionError("dry-run preview must not connect to Temporal")

    monkeypatch.setattr(base, "get_client", fail_client)

    path = _write_dashboards(
        tmp_path,
        {
            "dashboards": [
                {
                    "dashboard_id": "okx-hl",
                    "targets": [
                        {"target_id": "okx", "exchange": "okx", "mtf_profile": "scalper", "dry_run": True}
                    ],
                }
            ]
        },
    )

    parser = dash.build_parser()
    args = parser.parse_args(
        ["create", "--dashboard-id", "okx-hl", "--dashboards-path", path, "--dry-run-schedule"]
    )
    asyncio.run(dash.async_main(args))

    out = capsys.readouterr().out
    assert "[DRY-RUN] would create schedule cron-mtf-dashboard-okx-hl-1m" in out
    assert "MtfDashboardOrchestratorWorkflow" in out
    assert "'dashboard_id': 'okx-hl'" in out


def test_create_blocks_live_okx_dashboard(tmp_path, monkeypatch):
    async def fail_client():
        raise AssertionError("live OKX dashboard must never reach Temporal creation")

    monkeypatch.setattr(base, "get_client", fail_client)

    path = _write_dashboards(
        tmp_path,
        {
            "dashboards": [
                {
                    "dashboard_id": "bad",
                    "targets": [{"target_id": "okx", "exchange": "okx", "dry_run": False}],
                }
            ]
        },
    )

    parser = dash.build_parser()
    args = parser.parse_args(["create", "--dashboard-id", "bad", "--dashboards-path", path])

    with pytest.raises(RuntimeError, match="dry_run=true"):
        asyncio.run(dash.async_main(args))
