"""Validation des fixtures de recette runtime R1-R16 (#188)."""

from __future__ import annotations

import json
import re
from pathlib import Path
from typing import Any


FIXTURE_DIR = Path(__file__).resolve().parents[1] / "fixtures" / "runtime-recipe"

SET_FIELDS = {
    "set_id",
    "enabled",
    "action",
    "exchange",
    "market_type",
    "mtf_profile",
    "environment",
    "dry_run",
    "workers",
    "sync_tables",
    "symbols",
    "contracts_limit",
    "priority",
}

SECRET_RE = re.compile(
    r"(api[_-]?key|secret|password|passwd|token|signature|private[_-]?key|"
    r"authorization|x-bitmart-sign|begin private key)",
    re.IGNORECASE,
)


def _fixture_paths() -> list[Path]:
    return sorted(FIXTURE_DIR.glob("*.json"))


def _load(path: Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def _walk(value: Any):
    if isinstance(value, dict):
        for key, child in value.items():
            yield str(key)
            yield from _walk(child)
    elif isinstance(value, list):
        for child in value:
            yield from _walk(child)
    elif isinstance(value, str):
        yield value


def _dashboard_by_name(client, name: str) -> dict[str, Any] | None:
    response = client.get("/dashboards")
    assert response.status_code == 200
    for dashboard in response.json():
        if dashboard["name"] == name:
            return dashboard
    return None


def _apply_fixture(client, fixture: dict[str, Any]) -> int:
    dashboard_payload = fixture["dashboard"]
    existing = _dashboard_by_name(client, dashboard_payload["name"])
    if existing is None:
        created = client.post("/dashboards", json=dashboard_payload)
        assert created.status_code == 201
        dashboard_id = created.json()["id"]
    else:
        patched = client.patch(f"/dashboards/{existing['id']}", json=dashboard_payload)
        assert patched.status_code == 200
        dashboard_id = existing["id"]

    existing_sets = {
        item["set_id"]: item
        for item in client.get(f"/dashboards/{dashboard_id}/sets").json()
    }
    if fixture.get("expected_invariants", {}).get("disable_stale_sets") is True:
        expected_ids = {item["set_id"] for item in fixture["sets"]}
        for set_id, existing_set in existing_sets.items():
            if set_id not in expected_ids and existing_set.get("enabled") is True:
                response = client.patch(
                    f"/dashboards/{dashboard_id}/sets/{set_id}",
                    json={"enabled": False, "dry_run": True},
                )
                assert response.status_code == 200
    for fixture_set in fixture["sets"]:
        payload = {key: fixture_set[key] for key in SET_FIELDS if key in fixture_set}
        if fixture_set["set_id"] in existing_sets:
            patch_payload = {key: value for key, value in payload.items() if key != "set_id"}
            response = client.patch(
                f"/dashboards/{dashboard_id}/sets/{fixture_set['set_id']}",
                json=patch_payload,
            )
            assert response.status_code == 200
        else:
            response = client.post(f"/dashboards/{dashboard_id}/sets", json=payload)
            assert response.status_code == 201
    return dashboard_id


def test_runtime_recipe_fixture_files_are_valid_json():
    paths = _fixture_paths()
    assert paths, "expected runtime recipe fixtures"
    for path in paths:
        fixture = _load(path)
        assert fixture["version"] == 1
        assert fixture["dry_run_only"] is True
        assert fixture["dashboard"]["enabled"] is True
        assert fixture["sets"], path.name
        assert fixture["scenario_mapping"], path.name


def test_runtime_recipe_fixtures_are_fake_dry_run_only():
    profiles: set[str] = set()
    for path in sorted(FIXTURE_DIR.glob("*_fake_dashboard.json")):
        fixture = _load(path)
        for item in fixture["sets"]:
            profiles.add(item["mtf_profile"])
            assert item["exchange"] == "fake"
            assert item["market_type"] == "perpetual"
            assert item["environment"] == "demo"
            assert item["dry_run"] is True
            assert item["workers"] == 1
            for symbol in item["symbols"]:
                assert symbol == symbol.strip().upper()
                assert symbol.endswith("USDT")
    assert {"regular", "scalper", "scalper_micro"}.issubset(profiles)


def test_r5_uses_explicit_safe_fault_profile_instead_of_magic_symbol():
    fixture = _load(FIXTURE_DIR / "r1_r16_degraded_fake_dashboard.json")
    r5_set = next(
        item for item in fixture["sets"]
        if item["set_id"] == "recipe_fake_error_regular"
    )

    assert r5_set["mtf_profile"] == "recipe_functional_error"
    assert r5_set["symbols"] == ["BTCUSDT"]
    assert r5_set["exchange"] == "fake"
    assert r5_set["environment"] == "demo"
    assert r5_set["dry_run"] is True


def test_runtime_recipe_okx_dry_run_fixture_is_exchange_scoped():
    fixture = _load(FIXTURE_DIR / "r1_r16_okx_dry_run_dashboard.json")

    assert fixture["fixture_id"] == "runtime-recipe-r1-r16-okx-dry-run-v1"
    assert fixture["dry_run_only"] is True
    assert fixture["dashboard"]["name"] == "recipe-r1-r16-okx-dry-run"
    assert {item["set_id"] for item in fixture["sets"]} == {
        "recipe_okx_regular",
        "recipe_okx_scalper_micro",
        "recipe_okx_disabled",
    }
    for item in fixture["sets"]:
        assert item["exchange"] == "okx"
        assert item["market_type"] == "perpetual"
        assert item["environment"] == "demo"
        assert item["dry_run"] is True
        assert item["workers"] == 1
        assert item["sync_tables"] is False
        assert item["symbols"]
        for symbol in item["symbols"]:
            assert symbol == symbol.strip().upper()
            assert symbol.endswith("USDT")


def test_runtime_recipe_hyperliquid_dry_run_fixture_is_exchange_scoped():
    fixture = _load(FIXTURE_DIR / "r1_r16_hyperliquid_dry_run_dashboard.json")

    assert fixture["fixture_id"] == "runtime-recipe-r1-r16-hyperliquid-dry-run-v1"
    assert fixture["dry_run_only"] is True
    assert fixture["dashboard"]["name"] == "recipe-r1-r16-hyperliquid-dry-run"
    assert {item["set_id"] for item in fixture["sets"]} == {
        "recipe_hyperliquid_regular",
        "recipe_hyperliquid_scalper_micro",
        "recipe_hyperliquid_disabled",
    }
    for item in fixture["sets"]:
        assert item["exchange"] == "hyperliquid"
        assert item["market_type"] == "perpetual"
        assert item["environment"] == "testnet"
        assert item["dry_run"] is True
        assert item["workers"] == 1
        assert item["sync_tables"] is False
        assert item["symbols"]
        for symbol in item["symbols"]:
            assert symbol == symbol.strip().upper()
            assert symbol.endswith("USDT")


def test_demo_exchange_fixture_prepares_okx_and_hyperliquid_sets():
    fixture = _load(FIXTURE_DIR / "demo_exchanges_dashboard.json")

    assert fixture["fixture_id"] == "demo-exchanges-okx-hyperliquid-v1"
    assert fixture["dry_run_only"] is True
    assert fixture["dashboard"]["name"] == "demo-exchanges"
    assert {item["set_id"] for item in fixture["sets"]} == {
        "okx_scalper_demo",
        "okx_regular_demo",
        "hyperliquid_scalper_testnet",
        "hyperliquid_regular_testnet",
    }

    expected = {
        "okx_scalper_demo": ("okx", "demo", "scalper_micro"),
        "okx_regular_demo": ("okx", "demo", "regular"),
        "hyperliquid_scalper_testnet": ("hyperliquid", "testnet", "scalper_micro"),
        "hyperliquid_regular_testnet": ("hyperliquid", "testnet", "regular"),
    }
    for item in fixture["sets"]:
        exchange, environment, profile = expected[item["set_id"]]
        assert item["exchange"] == exchange
        assert item["environment"] == environment
        assert item["mtf_profile"] == profile
        assert item["market_type"] == "perpetual"
        assert item["dry_run"] is True
        assert item["enabled"] is False
        assert item["workers"] == 1
        assert item["sync_tables"] is False
        assert item["symbols"] == ["BTCUSDT"]


def test_demo_exchange_fixture_is_guarded_for_preparation_only():
    fixture = _load(FIXTURE_DIR / "demo_exchanges_dashboard.json")
    invariants = fixture["expected_invariants"]

    assert invariants["mainnet"] is False
    assert invariants["no_bitmart_fallback"] is True
    assert invariants["no_broadcast"] is True
    assert invariants["demo_testnet_write_enabled"] is False
    assert invariants["disable_stale_sets"] is True
    assert invariants["fixture_application"] == "upsert_by_dashboard_name_and_set_id"

    for item in fixture["sets"]:
        assert item["environment"] != "mainnet"
        assert item["dry_run"] is True
        assert item["safety_policy"]["kill_switch_enabled"] is True
        assert item["safety_policy"]["require_stop_loss"] is True
        assert item["safety_policy"]["max_notional_usdt"] > 0
        assert item["safety_policy"]["max_notional_usdt"] <= 50
        assert item["safety_policy"]["allowed_markets"] == ["perpetual"]
        assert item["safety_policy"]["allowed_symbols"] == item["symbols"]
        assert item["safety_policy"]["demo_testnet_write_enabled"] is False


def test_runtime_recipe_fixtures_do_not_contain_secret_material():
    for path in _fixture_paths():
        fixture = _load(path)
        for text in _walk(fixture):
            assert SECRET_RE.search(text) is None, f"{path.name} contains sensitive marker: {text}"


def test_runtime_recipe_fixtures_apply_repeatedly_without_duplicates(api_client):
    for path in _fixture_paths():
        fixture = _load(path)

        first_dashboard_id = _apply_fixture(api_client, fixture)
        second_dashboard_id = _apply_fixture(api_client, fixture)

        assert second_dashboard_id == first_dashboard_id
        dashboards = [
            item
            for item in api_client.get("/dashboards").json()
            if item["name"] == fixture["dashboard"]["name"]
        ]
        assert len(dashboards) == 1

        sets = api_client.get(f"/dashboards/{first_dashboard_id}/sets").json()
        expected_ids = {item["set_id"] for item in fixture["sets"]}
        observed_ids = {item["set_id"] for item in sets}
        assert expected_ids == observed_ids
        assert len(sets) == len(expected_ids)


def test_demo_exchange_fixture_disables_stale_live_sets_on_reapply(orchestrator_env):
    from app.db.models import Dashboard, OrchestrationSet

    api_client, session = orchestrator_env
    fixture = _load(FIXTURE_DIR / "demo_exchanges_dashboard.json")
    dashboard = Dashboard(
        name=fixture["dashboard"]["name"],
        enabled=True,
        description="existing demo dashboard",
    )
    session.add(dashboard)
    session.flush()
    session.add(
        OrchestrationSet(
            dashboard_id=dashboard.id,
            set_id="old_bitmart_live",
            enabled=True,
            action="mtf_run",
            exchange="bitmart",
            market_type="perpetual",
            mtf_profile="regular",
            environment="mainnet",
            dry_run=False,
            workers=1,
            sync_tables=False,
            symbols=["BTCUSDT"],
            priority=90,
        )
    )
    session.commit()

    dashboard_id = _apply_fixture(api_client, fixture)

    sets = {
        item["set_id"]: item
        for item in api_client.get(f"/dashboards/{dashboard_id}/sets").json()
    }
    assert sets["old_bitmart_live"]["enabled"] is False
    assert sets["old_bitmart_live"]["dry_run"] is True
    assert {item["set_id"] for item in fixture["sets"]}.issubset(sets)


def test_runtime_recipe_fixtures_generate_server_payloads(api_client):
    nominal = _load(FIXTURE_DIR / "r1_r16_nominal_fake_dashboard.json")
    dashboard_id = _apply_fixture(api_client, nominal)

    sets = {
        item["set_id"]: item
        for item in api_client.get(f"/dashboards/{dashboard_id}/sets").json()
    }

    assert sets["recipe_fake_regular"]["payload"] == {
        "dry_run": True,
        "workers": 1,
        "exchange": "fake",
        "market_type": "perpetual",
        "mtf_profile": "regular",
        "sync_tables": False,
        "process_tp_sl": False,
        "symbols": ["BTCUSDT", "ETHUSDT"],
    }
    assert sets["recipe_fake_disabled"]["payload"]["dry_run"] is True
