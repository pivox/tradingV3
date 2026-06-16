"""Tests for the dashboard / target model and the dry-run-only guardrail."""

import pytest

from bridge.dashboard import (
    Dashboard,
    DashboardTarget,
    load_dashboards,
)


def _target(**overrides):
    data = {
        "target_id": "okx-demo-scalper",
        "exchange": "okx",
        "network": "demo",
        "market_type": "perpetual",
        "mtf_profile": "scalper",
        "dry_run": True,
        "workers": 4,
    }
    data.update(overrides)
    return DashboardTarget.from_dict(data)


def test_target_to_payload_mirrors_mtf_job_shape_plus_idempotency_key():
    target = _target()

    payload = target.to_payload("dash-1", "2026-06-16T00:01:00+00:00")

    assert payload == {
        "workers": 4,
        "dry_run": True,
        "force_run": False,
        "exchange": "okx",
        "market_type": "perpetual",
        "mtf_profile": "scalper",
        "idempotency_key": "dash-1:okx-demo-scalper:2026-06-16T00:01:00+00:00",
    }
    # network is informational only and must never leak into the Symfony trading payload.
    assert "network" not in payload


def test_idempotency_key_is_dashboard_target_tick():
    target = _target(target_id="t1")

    assert target.idempotency_key("d1", "ts") == "d1:t1:ts"


def test_target_requires_id_and_exchange():
    with pytest.raises(ValueError):
        DashboardTarget.from_dict({"exchange": "okx"})
    with pytest.raises(ValueError):
        DashboardTarget.from_dict({"target_id": "x"})


def test_validate_policy_blocks_live_okx_and_hyperliquid():
    for exchange in ("okx", "hyperliquid"):
        dashboard = Dashboard(
            dashboard_id="d",
            targets=[_target(target_id="t", exchange=exchange, dry_run=False)],
        )
        with pytest.raises(RuntimeError, match="dry_run=true"):
            dashboard.validate_policy()


def test_validate_policy_is_casing_proof():
    dashboard = Dashboard(
        dashboard_id="d",
        targets=[_target(target_id="t", exchange="  OKX ", dry_run=False)],
    )
    with pytest.raises(RuntimeError, match="dry_run=true"):
        dashboard.validate_policy()


def test_validate_policy_allows_dry_run_okx_hl_and_live_bitmart():
    dashboard = Dashboard(
        dashboard_id="d",
        targets=[
            _target(target_id="a", exchange="okx", dry_run=True),
            _target(target_id="b", exchange="hyperliquid", dry_run=True),
            # Bitmart legacy can still run live; only OKX/HL are dry-run only.
            _target(target_id="c", exchange="bitmart", dry_run=False),
        ],
    )

    assert dashboard.validate_policy() is None


def test_load_dashboards_builds_registry_and_defaults():
    registry = load_dashboards(
        {
            "dashboards": [
                {
                    "dashboard_id": "okx-hl",
                    "targets": [
                        {"target_id": "okx", "exchange": "okx", "mtf_profile": "scalper"},
                        {"target_id": "hl", "exchange": "hyperliquid", "mtf_profile": "regular"},
                    ],
                }
            ]
        }
    )

    assert set(registry) == {"okx-hl"}
    dashboard = registry["okx-hl"]
    assert dashboard.cadence == "*/1 * * * *"
    assert dashboard.fail_policy == "continue"
    assert [t.target_id for t in dashboard.targets] == ["okx", "hl"]


def test_load_dashboards_is_fail_closed_on_live_okx():
    with pytest.raises(RuntimeError, match="dry_run=true"):
        load_dashboards(
            {
                "dashboards": [
                    {
                        "dashboard_id": "bad",
                        "targets": [{"target_id": "x", "exchange": "okx", "dry_run": False}],
                    }
                ]
            }
        )


def test_load_dashboards_rejects_duplicate_target_ids():
    with pytest.raises(ValueError, match="duplicate target_id"):
        load_dashboards(
            {
                "dashboards": [
                    {
                        "dashboard_id": "d",
                        "targets": [
                            {"target_id": "x", "exchange": "okx"},
                            {"target_id": "x", "exchange": "hyperliquid"},
                        ],
                    }
                ]
            }
        )


def test_unsupported_fail_policy_is_rejected():
    with pytest.raises(ValueError, match="fail_policy"):
        Dashboard.from_dict(
            {
                "dashboard_id": "d",
                "fail_policy": "explode",
                "targets": [{"target_id": "x", "exchange": "okx"}],
            }
        )
