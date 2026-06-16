"""Tests for the dashboard / target model, dry-run-only gate, fingerprint and snapshot."""

import pytest

from dashboards.model import (
    Dashboard,
    DashboardTarget,
    load_dashboards,
)


def _target(**overrides):
    data = {
        "target_id": "okx-demo-scalper",
        "exchange": "okx",
        "environment": "demo",
        "market_type": "perpetual",
        "mtf_profile": "scalper",
        "dry_run": True,
        "workers": 4,
    }
    data.update(overrides)
    return DashboardTarget.from_dict(data)


def test_target_from_dict_accepts_network_alias_for_environment():
    target = DashboardTarget.from_dict({"target_id": "t", "exchange": "okx", "network": "mainnet"})

    assert target.environment == "mainnet"


def test_target_requires_id_and_exchange():
    with pytest.raises(ValueError):
        DashboardTarget.from_dict({"exchange": "okx"})
    with pytest.raises(ValueError):
        DashboardTarget.from_dict({"target_id": "x"})


def test_snapshot_includes_fingerprint_and_fields():
    snapshot = _target().to_snapshot()

    assert snapshot["target_id"] == "okx-demo-scalper"
    assert snapshot["environment"] == "demo"
    assert "fingerprint" in snapshot and len(snapshot["fingerprint"]) == 12


def test_fingerprint_is_stable_and_changes_with_effective_config():
    base = _target()
    same = _target()
    changed = _target(mtf_profile="regular")

    assert base.fingerprint() == same.fingerprint()
    assert base.fingerprint() != changed.fingerprint()


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
            _target(target_id="c", exchange="bitmart", dry_run=False),
        ],
    )

    assert dashboard.validate_policy() is None


def test_load_dashboards_builds_registry_with_defaults():
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

    dashboard = registry["okx-hl"]
    assert dashboard.cadence == "*/1 * * * *"
    assert dashboard.fail_policy == "continue"
    assert dashboard.max_concurrency == 4
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
