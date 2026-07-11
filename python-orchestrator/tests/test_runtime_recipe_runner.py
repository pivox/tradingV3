"""Tests du runner de recette runtime R1-R16 (#188)."""

from __future__ import annotations

import json
import subprocess
from pathlib import Path
from threading import Lock
from typing import Any

import scripts.runtime_recipe_runner as runner_module
from scripts.runtime_recipe_runner import RecipeRunner, RunnerConfig


class FakeRecipeApi:
    """HTTP API en memoire pour tester le runner sans socket."""

    def __init__(
        self,
        *,
        health_ok: bool = True,
        run_transport_error: bool = False,
        overlap_guard: bool = False,
        r5_outage: bool = False,
        r6_outage: bool = False,
        r6_business_failure: bool = False,
        r8_failure: bool = False,
        r11_outage: bool = False,
        live_probe_status: int = 422,
        live_probe_body: dict[str, Any] | None = None,
    ) -> None:
        self.health_ok = health_ok
        self.run_transport_error = run_transport_error
        self.overlap_guard = overlap_guard
        self.r5_outage = r5_outage
        self.r6_outage = r6_outage
        self.r6_business_failure = r6_business_failure
        self.r8_failure = r8_failure
        self.r11_outage = r11_outage
        self.live_probe_status = live_probe_status
        self.live_probe_body = live_probe_body or {"detail": "live non autorise pour la recette"}
        self.r11_seen = 0
        self.lock = Lock()
        self.dashboards: list[dict[str, Any]] = []
        self.sets: dict[int, list[dict[str, Any]]] = {}
        self.requests: list[dict[str, Any]] = []
        self.runs_by_key: dict[str, dict[str, Any]] = {}
        self.run_details: dict[str, dict[str, Any]] = {}
        self.next_dashboard_id = 1
        self.next_set_id = 1

    def request(
        self,
        method: str,
        path: str,
        *,
        json_body: Any = None,
        timeout: float = 30.0,
    ) -> tuple[int, Any]:
        self.requests.append({"method": method, "path": path, "json": json_body, "timeout": timeout})

        if path == "/healthcheck":
            return (200, {"status": "ok"}) if self.health_ok else (503, {"status": "down"})
        if path == "/dashboards" and method == "GET":
            return 200, list(self.dashboards)
        if path == "/dashboards" and method == "POST":
            dashboard = {
                "id": self.next_dashboard_id,
                "name": json_body["name"],
                "enabled": json_body.get("enabled", True),
                "description": json_body.get("description"),
            }
            self.next_dashboard_id += 1
            self.dashboards.append(dashboard)
            self.sets[dashboard["id"]] = []
            return 201, dashboard
        if path.startswith("/dashboards/") and "/sets" not in path and method == "PATCH":
            dashboard_id = int(path.split("/")[2])
            dashboard = self._dashboard(dashboard_id)
            dashboard.update(json_body)
            return 200, dashboard
        if path.endswith("/sets") and method == "GET":
            dashboard_id = int(path.split("/")[2])
            return 200, list(self.sets.get(dashboard_id, []))
        if path.endswith("/sets") and method == "POST":
            dashboard_id = int(path.split("/")[2])
            return self._create_set(dashboard_id, json_body)
        if "/sets/" in path and method == "PATCH":
            dashboard_id = int(path.split("/")[2])
            set_id = path.rsplit("/", 1)[1]
            return self._patch_set(dashboard_id, set_id, json_body)
        if "/sets/" in path and method == "DELETE":
            dashboard_id = int(path.split("/")[2])
            set_id = path.rsplit("/", 1)[1]
            before = len(self.sets.get(dashboard_id, []))
            self.sets[dashboard_id] = [
                item for item in self.sets.get(dashboard_id, []) if item["set_id"] != set_id
            ]
            return (204, {}) if len(self.sets[dashboard_id]) != before else (404, {"detail": "set not found"})
        if path == "/orchestrator/run" and method == "POST":
            return self._run(json_body)
        if path.startswith("/runs/") and method == "GET":
            run_id = path.split("/", 2)[2]
            detail = self.run_details.get(run_id)
            return (200, detail) if detail is not None else (404, {"detail": "run not found"})
        return 404, {"detail": f"unexpected {method} {path}"}

    def _dashboard(self, dashboard_id: int) -> dict[str, Any]:
        for dashboard in self.dashboards:
            if dashboard["id"] == dashboard_id:
                return dashboard
        raise AssertionError(f"dashboard {dashboard_id} not found")

    def _create_set(self, dashboard_id: int, body: dict[str, Any]) -> tuple[int, Any]:
        if body["dry_run"] is not True:
            if 200 <= self.live_probe_status < 300:
                created = {
                    "id": self.next_set_id,
                    "dashboard_id": dashboard_id,
                    **body,
                    "payload": self._payload(body),
                }
                self.next_set_id += 1
                self.sets[dashboard_id].append(created)
                return self.live_probe_status, created
            return self.live_probe_status, self.live_probe_body
        created = {
            "id": self.next_set_id,
            "dashboard_id": dashboard_id,
            **body,
            "payload": self._payload(body),
        }
        self.next_set_id += 1
        self.sets[dashboard_id].append(created)
        return 201, created

    def _patch_set(self, dashboard_id: int, set_id: str, body: dict[str, Any]) -> tuple[int, Any]:
        if body.get("dry_run") is not True:
            return 422, {"detail": "live non autorise pour la recette"}
        for item in self.sets[dashboard_id]:
            if item["set_id"] == set_id:
                item.update(body)
                item["payload"] = self._payload(item)
                return 200, item
        return 404, {"detail": "set not found"}

    def _payload(self, body: dict[str, Any]) -> dict[str, Any] | None:
        symbols = body.get("symbols") or []
        if not symbols:
            return None
        return {
            "dry_run": body.get("dry_run", True),
            "workers": 1,
            "exchange": body["exchange"],
            "market_type": body["market_type"],
            "mtf_profile": body["mtf_profile"],
            "sync_tables": False,
            "process_tp_sl": False,
            "symbols": symbols,
        }

    def _run(self, body: dict[str, Any]) -> tuple[int, Any]:
        if self.run_transport_error:
            return 503, {"detail": "orchestrator unavailable"}
        assert body["dry_run"] is True
        dashboard_id = int(body["dashboard_id"])
        key = body["idempotency_key"]
        if key in self.runs_by_key:
            return 200, self.runs_by_key[key]
        active_sets = [item for item in self.sets[dashboard_id] if item["enabled"]]
        set_results = [self._set_result(item, key) for item in active_sets]
        if self.overlap_guard and key.startswith("recipe-r11-"):
            with self.lock:
                self.r11_seen += 1
                if self.r11_seen > 1:
                    set_results = [
                        {
                            "set_id": item["set_id"],
                            "ok": False,
                            "status": None,
                            "business_status": None,
                            "error": f"locked: {item['set_id']} held by run run_recipe-r11-a",
                            "response_json": None,
                        }
                        for item in active_sets
                    ]
        failed = sum(1 for item in set_results if not item["ok"])
        success = len(active_sets) - failed
        status = "success" if failed == 0 else ("failed" if success == 0 else "partial_failure")
        run_id = f"run_{key}"
        response = {
            "ok": failed == 0,
            "run_id": run_id,
            "status": status,
            "summary": {"total_calls": len(active_sets), "success": success, "failed": failed},
        }
        last_sets = [
            {
                "set_id": item["set_id"],
                "ok": item["ok"],
                "status": item["status"],
                "business_status": item["business_status"],
                "error": item["error"],
            }
            for item in set_results
        ]
        self.run_details[run_id] = {
            "run_id": run_id,
            "status": status,
            "last_json": {"sets": last_sets},
            "sets": set_results,
        }
        self.runs_by_key[key] = response
        return 200, response

    def _set_result(self, item: dict[str, Any], key: str) -> dict[str, Any]:
        if item["payload"] is None:
            return {
                "set_id": item["set_id"],
                "ok": False,
                "status": None,
                "business_status": None,
                "error": "set payload not materialized (no concrete symbols)",
                "response_json": None,
            }
        if item["mtf_profile"] == "recipe_functional_error":
            if self.r5_outage and key.startswith("recipe-r5-"):
                return {
                    "set_id": item["set_id"],
                    "ok": False,
                    "status": 503,
                    "business_status": None,
                    "error": "mtf run failed: Symfony unavailable",
                    "response_json": {"message": "Symfony unavailable"},
                }
            return {
                "set_id": item["set_id"],
                "ok": False,
                "status": 500,
                "business_status": "error",
                "error": "Configuration file not found for recipe fault profile",
                "response_json": {
                    "status": "error",
                    "message": "Configuration file not found for recipe fault profile",
                },
            }
        if self.r11_outage and key.startswith("recipe-r11-"):
            return {
                "set_id": item["set_id"],
                "ok": False,
                "status": 503,
                "business_status": None,
                "error": "mtf run failed: Symfony unavailable",
                "response_json": {"message": "Symfony unavailable"},
            }
        if self.r6_outage and key.startswith("recipe-r6-"):
            return {
                "set_id": item["set_id"],
                "ok": False,
                "status": 503,
                "business_status": None,
                "error": "mtf run failed: Symfony timeout",
                "response_json": {"message": "Symfony timeout"},
            }
        if self.r6_business_failure and key.startswith("recipe-r6-"):
            return {
                "set_id": item["set_id"],
                "ok": False,
                "status": 400,
                "business_status": "error",
                "error": "functional validation error",
                "response_json": {"status": "error", "message": "functional validation error"},
            }
        if self.r8_failure and key.startswith("recipe-r8-"):
            return {
                "set_id": item["set_id"],
                "ok": False,
                "status": 400,
                "business_status": "error",
                "error": "functional replay setup failure",
                "response_json": {"status": "error"},
            }
        return {
            "set_id": item["set_id"],
            "ok": True,
            "status": 200,
            "business_status": "success",
            "error": None,
            "response_json": {"status": "success"},
        }


def test_runner_applies_fixtures_idempotently_without_sending_payload(tmp_path: Path):
    api = FakeRecipeApi()
    runner = RecipeRunner(
        RunnerConfig(export_dir=tmp_path, confirmation_token="DRY_RUN_ONLY"),
        http_client=api,
    )

    first = runner.run(scenarios=("R1",), keep_fixtures=True)
    second = runner.run(scenarios=("R1",), keep_fixtures=True)

    assert first["summary"]["scenario_counts"]["PASS"] >= 1
    assert second["dashboards"]["nominal"]["id"] == first["dashboards"]["nominal"]["id"]
    assert any(request["path"] == "/healthcheck" for request in api.requests)
    matching_dashboards = [
        dashboard
        for dashboard in api.dashboards
        if dashboard["name"] == "recipe-r1-r16-nominal-fake"
    ]
    okx_dashboards = [
        dashboard
        for dashboard in api.dashboards
        if dashboard["name"] == "recipe-r1-r16-okx-dry-run"
    ]
    assert len(matching_dashboards) == 1
    assert okx_dashboards == []
    assert len(api.sets[first["dashboards"]["nominal"]["id"]]) == 4
    run_keys = [
        request["json"]["idempotency_key"]
        for request in api.requests
        if request["path"] == "/orchestrator/run"
    ]
    assert len(run_keys) == 2
    assert all(key.startswith("recipe-r1-") for key in run_keys)
    assert run_keys[0] != run_keys[1]
    for request in api.requests:
        if request["method"] in {"POST", "PATCH"} and "/sets" in request["path"]:
            assert "payload" not in request["json"]
            assert request["json"]["dry_run"] is True


def test_runner_disables_stale_sets_when_reusing_recipe_dashboard(tmp_path: Path):
    api = FakeRecipeApi()
    runner = RecipeRunner(
        RunnerConfig(export_dir=tmp_path, confirmation_token="DRY_RUN_ONLY"),
        http_client=api,
    )

    first = runner.run(scenarios=("R1",), keep_fixtures=True)
    dashboard_id = first["dashboards"]["nominal"]["id"]
    api._create_set(
        dashboard_id,
        {
            "set_id": "recipe_fake_stale_enabled",
            "enabled": True,
            "action": "mtf_run",
            "exchange": "fake",
            "market_type": "perpetual",
            "mtf_profile": "regular",
            "environment": "test",
            "dry_run": True,
            "workers": 4,
            "sync_tables": False,
            "symbols": ["STALEUSDT"],
            "contracts_limit": None,
            "priority": 100,
        },
    )

    second = runner.run(scenarios=("R1",), keep_fixtures=True)

    r1 = second["results"][0]
    stale = next(item for item in api.sets[dashboard_id] if item["set_id"] == "recipe_fake_stale_enabled")
    assert stale["enabled"] is False
    assert r1["evidence"]["summary"]["total_calls"] == 1


def test_runner_can_target_okx_dry_run_recipe_without_bitmart_fallback(tmp_path: Path, monkeypatch):
    api = FakeRecipeApi()
    runtime_check_calls: list[tuple[Any, ...]] = []

    def runtime_check(*args, **kwargs):
        runtime_check_calls.append(args)

        class Completed:
            returncode = 0
            stdout = "Readiness level: local_dry_run_ready\nSchedule ready: yes\n"
            stderr = ""

        return Completed()

    monkeypatch.setattr(runner_module.subprocess, "run", runtime_check)

    report = RecipeRunner(
        RunnerConfig(
            export_dir=tmp_path,
            confirmation_token="DRY_RUN_ONLY",
            target_exchange="okx",
        ),
        http_client=api,
    ).run(scenarios=("R1", "R2", "R14"), keep_fixtures=True)

    statuses = {item["scenario"]: item["status"] for item in report["results"]}
    assert statuses == {"R1": "PASS", "R2": "PASS", "R14": "PASS"}
    assert report["dashboards"]["okx"]["name"] == "recipe-r1-r16-okx-dry-run"
    assert report["metadata"]["runtime_check"]["status"] == "PASS"
    assert report["metadata"]["runtime_check"]["schedule_ready"] == "yes"
    assert runtime_check_calls
    assert list(runtime_check_calls[0][0][-3:]) == ["app:exchange:runtime-check", "okx", "perpetual"]

    okx_dashboard_id = report["dashboards"]["okx"]["id"]
    okx_sets = api.sets[okx_dashboard_id]
    assert {item["set_id"] for item in okx_sets} == {
        "recipe_okx_regular",
        "recipe_okx_scalper_micro",
        "recipe_okx_disabled",
    }
    assert {item["exchange"] for item in okx_sets} == {"okx"}
    assert all(item["dry_run"] is True for item in okx_sets)
    assert all(item["environment"] == "demo" for item in okx_sets)

    run_requests = [request for request in api.requests if request["path"] == "/orchestrator/run"]
    assert [request["json"]["idempotency_key"].split("-")[1] for request in run_requests] == [
        "r1",
        "r2",
    ]
    assert not any(
        request["json"] and request["json"].get("exchange") == "bitmart"
        for request in api.requests
        if isinstance(request["json"], dict)
    )


def test_runner_can_target_hyperliquid_dry_run_recipe_without_bitmart_fallback(
    tmp_path: Path,
    monkeypatch,
):
    api = FakeRecipeApi()
    runtime_check_calls: list[tuple[Any, ...]] = []

    def runtime_check(*args, **kwargs):
        runtime_check_calls.append(args)

        class Completed:
            returncode = 0
            stdout = "Readiness level: local_dry_run_ready\nSchedule ready: yes\n"
            stderr = ""

        return Completed()

    monkeypatch.setattr(runner_module.subprocess, "run", runtime_check)

    report = RecipeRunner(
        RunnerConfig(
            export_dir=tmp_path,
            confirmation_token="DRY_RUN_ONLY",
            target_exchange="hyperliquid",
        ),
        http_client=api,
    ).run(scenarios=("R1", "R2", "R14"), keep_fixtures=True)

    statuses = {item["scenario"]: item["status"] for item in report["results"]}
    assert statuses == {"R1": "PASS", "R2": "PASS", "R14": "PASS"}
    assert report["dashboards"]["hyperliquid"]["name"] == "recipe-r1-r16-hyperliquid-dry-run"
    assert report["metadata"]["runtime_check"]["status"] == "PASS"
    assert report["metadata"]["runtime_check"]["schedule_ready"] == "yes"
    assert runtime_check_calls
    assert list(runtime_check_calls[0][0][-3:]) == [
        "app:exchange:runtime-check",
        "hyperliquid",
        "perpetual",
    ]

    dashboard_id = report["dashboards"]["hyperliquid"]["id"]
    sets = api.sets[dashboard_id]
    assert {item["set_id"] for item in sets} == {
        "recipe_hyperliquid_regular",
        "recipe_hyperliquid_scalper_micro",
        "recipe_hyperliquid_disabled",
    }
    assert {item["exchange"] for item in sets} == {"hyperliquid"}
    assert all(item["dry_run"] is True for item in sets)
    assert all(item["environment"] == "testnet" for item in sets)

    run_requests = [request for request in api.requests if request["path"] == "/orchestrator/run"]
    assert [request["json"]["idempotency_key"].split("-")[1] for request in run_requests] == [
        "r1",
        "r2",
    ]
    assert not any(
        request["json"] and request["json"].get("exchange") == "bitmart"
        for request in api.requests
        if isinstance(request["json"], dict)
    )
    probe_requests = [
        request
        for request in api.requests
        if request["method"] == "POST"
        and request["path"].endswith("/sets")
        and request["json"]["set_id"] == "recipe_hyperliquid_live_forbidden_probe"
    ]
    assert probe_requests
    assert probe_requests[0]["json"]["exchange"] == "hyperliquid"
    assert probe_requests[0]["json"]["environment"] == "testnet"
    assert probe_requests[0]["json"]["dry_run"] is False


def test_runner_exports_demo_exchange_report_by_exchange(tmp_path: Path, monkeypatch):
    api = FakeRecipeApi()
    runtime_check_targets: list[str] = []

    def runtime_check(*args, **kwargs):
        runtime_check_targets.append(args[0][-2])

        class Completed:
            returncode = 0
            stdout = "Readiness level: local_dry_run_ready\nSchedule ready: yes\n"
            stderr = ""

        return Completed()

    monkeypatch.setattr(runner_module.subprocess, "run", runtime_check)

    report = RecipeRunner(
        RunnerConfig(
            export_dir=tmp_path,
            confirmation_token="DRY_RUN_ONLY",
            target_exchange="demo-exchanges",
        ),
        http_client=api,
    ).run(scenarios=("R1", "R2", "R3", "R14"), keep_fixtures=True)

    assert report["metadata"]["target_exchange"] == "demo-exchanges"
    assert set(report["exchange_results"]) == {"global", "okx", "hyperliquid"}
    assert runtime_check_targets == ["okx", "hyperliquid"]

    global_statuses = {
        item["scenario"]: item["status"] for item in report["exchange_results"]["global"]
    }
    okx_statuses = {
        item["scenario"]: item["status"] for item in report["exchange_results"]["okx"]
    }
    hyperliquid_statuses = {
        item["scenario"]: item["status"]
        for item in report["exchange_results"]["hyperliquid"]
    }

    assert global_statuses == {"R1": "PASS", "R2": "PASS", "R3": "BLOCKED", "R14": "PASS"}
    assert okx_statuses == {"R1": "PASS", "R2": "PASS", "R3": "BLOCKED", "R14": "PASS"}
    assert hyperliquid_statuses == {
        "R1": "PASS",
        "R2": "PASS",
        "R3": "BLOCKED",
        "R14": "PASS",
    }
    assert report["exchange_summaries"]["okx"]["scenario_counts"] == {
        "PASS": 3,
        "BLOCKED": 1,
    }
    assert report["exchange_summaries"]["hyperliquid"]["scenario_counts"] == {
        "PASS": 3,
        "BLOCKED": 1,
    }

    exported = json.loads((tmp_path / "runtime-recipe-report.json").read_text(encoding="utf-8"))
    assert exported == report
    assert not any(
        request["json"] and request["json"].get("exchange") == "bitmart"
        for request in api.requests
        if isinstance(request["json"], dict)
    )


def test_demo_exchange_runner_materializes_scenario_iterable_once(
    tmp_path: Path,
    monkeypatch,
):
    api = FakeRecipeApi()

    def runtime_check(*args, **kwargs):
        class Completed:
            returncode = 0
            stdout = "Readiness level: local_dry_run_ready\nSchedule ready: yes\n"
            stderr = ""

        return Completed()

    monkeypatch.setattr(runner_module.subprocess, "run", runtime_check)

    scenarios = (scenario for scenario in ("R1", "R2"))
    report = RecipeRunner(
        RunnerConfig(
            export_dir=tmp_path,
            confirmation_token="DRY_RUN_ONLY",
            target_exchange="demo-exchanges",
        ),
        http_client=api,
    ).run(scenarios=scenarios, keep_fixtures=True)

    assert [item["scenario"] for item in report["exchange_results"]["global"]] == ["R1", "R2"]
    assert [item["scenario"] for item in report["exchange_results"]["okx"]] == ["R1", "R2"]
    assert [item["scenario"] for item in report["exchange_results"]["hyperliquid"]] == ["R1", "R2"]


def test_runner_blocks_okx_recipe_when_runtime_check_is_not_schedule_ready(
    tmp_path: Path,
    monkeypatch,
):
    api = FakeRecipeApi()

    def runtime_check(*args, **kwargs):
        class Completed:
            returncode = 0
            stdout = "Readiness level: public_read_only\nSchedule ready: no\n"
            stderr = ""

        return Completed()

    monkeypatch.setattr(runner_module.subprocess, "run", runtime_check)

    report = RecipeRunner(
        RunnerConfig(
            export_dir=tmp_path,
            confirmation_token="DRY_RUN_ONLY",
            target_exchange="okx",
        ),
        http_client=api,
    ).run(scenarios=("R1", "R2", "R14"), keep_fixtures=True)

    assert report["metadata"]["runtime_check"]["status"] == "BLOCKED"
    assert report["summary"]["scenario_counts"] == {"BLOCKED": 3}
    assert not any(request["path"] == "/orchestrator/run" for request in api.requests)


def test_runner_blocks_hyperliquid_recipe_when_runtime_check_is_not_schedule_ready(
    tmp_path: Path,
    monkeypatch,
):
    api = FakeRecipeApi()

    def runtime_check(*args, **kwargs):
        class Completed:
            returncode = 0
            stdout = "Readiness level: public_read_only\nSchedule ready: no\n"
            stderr = ""

        return Completed()

    monkeypatch.setattr(runner_module.subprocess, "run", runtime_check)

    report = RecipeRunner(
        RunnerConfig(
            export_dir=tmp_path,
            confirmation_token="DRY_RUN_ONLY",
            target_exchange="hyperliquid",
        ),
        http_client=api,
    ).run(scenarios=("R1", "R2", "R14"), keep_fixtures=True)

    assert report["metadata"]["runtime_check"]["status"] == "BLOCKED"
    assert report["metadata"]["runtime_check"]["schedule_ready"] == "no"
    assert report["summary"]["scenario_counts"] == {"BLOCKED": 3}
    assert "hyperliquid" not in report["dashboards"]
    assert not any(
        dashboard["name"] == "recipe-r1-r16-hyperliquid-dry-run"
        for dashboard in api.dashboards
    )
    assert not any(request["path"] == "/orchestrator/run" for request in api.requests)


def test_runner_does_not_block_fake_scenarios_when_okx_runtime_check_is_not_ready(
    tmp_path: Path,
    monkeypatch,
):
    api = FakeRecipeApi()

    def runtime_check(*args, **kwargs):
        class Completed:
            returncode = 0
            stdout = "Readiness level: public_read_only\nSchedule ready: no\n"
            stderr = ""

        return Completed()

    monkeypatch.setattr(runner_module.subprocess, "run", runtime_check)

    report = RecipeRunner(
        RunnerConfig(
            export_dir=tmp_path,
            confirmation_token="DRY_RUN_ONLY",
            target_exchange="okx",
        ),
        http_client=api,
    ).run(scenarios=("R5",), keep_fixtures=True)

    assert report["results"][0]["status"] == "PASS"
    assert "degraded" in report["dashboards"]
    assert "okx" not in report["dashboards"]
    assert any(request["path"] == "/orchestrator/run" for request in api.requests)


def test_runner_forces_dry_run_exports_report_and_redacts_sensitive_values(tmp_path: Path):
    api = FakeRecipeApi()
    report = RecipeRunner(
        RunnerConfig(export_dir=tmp_path, confirmation_token="DRY_RUN_ONLY"),
        http_client=api,
    ).run(scenarios=("R1", "R8"), keep_fixtures=True)

    exported = json.loads((tmp_path / "runtime-recipe-report.json").read_text(encoding="utf-8"))
    assert exported["metadata"]["dry_run_forced"] is True
    assert exported["metadata"]["confirmation_token"] == "REDACTED"
    assert all(result["status"] in {"PASS", "FAIL", "BLOCKED"} for result in exported["results"])
    assert all(
        request["json"].get("dry_run") is True
        for request in api.requests
        if request["path"] == "/orchestrator/run"
    )
    r8_keys = [
        request["json"]["idempotency_key"]
        for request in api.requests
        if request["path"] == "/orchestrator/run"
        and request["json"]["idempotency_key"].startswith("recipe-r8-")
    ]
    assert len(r8_keys) == 2
    assert len(set(r8_keys)) == 1
    assert report == exported


def test_runner_marks_missing_services_and_temporal_absence_blocked(tmp_path: Path):
    api = FakeRecipeApi(health_ok=False)
    report = RecipeRunner(
        RunnerConfig(export_dir=tmp_path, confirmation_token="DRY_RUN_ONLY", temporal_available=False),
        http_client=api,
    ).run(scenarios=("R1", "R15", "R16"), keep_fixtures=True)

    statuses = {item["scenario"]: item["status"] for item in report["results"]}
    assert statuses["R1"] == "BLOCKED"
    assert statuses["R15"] == "BLOCKED"
    assert statuses["R16"] == "BLOCKED"
    assert report["summary"]["scenario_counts"]["BLOCKED"] == 3


def test_runner_exercises_live_guard_without_dispatching_live(tmp_path: Path):
    api = FakeRecipeApi()
    report = RecipeRunner(
        RunnerConfig(export_dir=tmp_path, confirmation_token="DRY_RUN_ONLY"),
        http_client=api,
    ).run(scenarios=("R14",), keep_fixtures=True)

    r14 = next(item for item in report["results"] if item["scenario"] == "R14")
    assert r14["status"] == "PASS"
    assert "live guard refused" in r14["notes"]
    assert not any(request["path"] == "/orchestrator/run" for request in api.requests)
    probe_requests = [
        request
        for request in api.requests
        if request["method"] == "POST"
        and request["path"].endswith("/sets")
        and request["json"]["set_id"] == "recipe_okx_live_forbidden_probe"
    ]
    assert probe_requests[0]["json"]["enabled"] is False


def test_temporal_command_parser_accepts_flagged_arguments():
    args = runner_module._parse_args(
        [
            "--confirm",
            "DRY_RUN_ONLY",
            "--temporal-dry-run-command",
            "python",
            "../cron_symfony_mtf_workers/scripts/manage_orchestrator_schedule.py",
            "create",
            "--dry-run",
            "--dashboard-id",
            "{dashboard_id}",
            "--schedule-id",
            "recipe-orchestrator-r1-r16",
        ]
    )

    assert args.temporal_dry_run_command == [
        "python",
        "../cron_symfony_mtf_workers/scripts/manage_orchestrator_schedule.py",
        "create",
        "--dry-run",
        "--dashboard-id",
        "{dashboard_id}",
        "--schedule-id",
        "recipe-orchestrator-r1-r16",
    ]


def test_parser_accepts_hyperliquid_runtime_recipe_target():
    args = runner_module._parse_args(
        [
            "--confirm",
            "DRY_RUN_ONLY",
            "--target-exchange",
            "hyperliquid",
            "--scenario",
            "R1",
        ]
    )

    assert args.target_exchange == "hyperliquid"
    assert args.scenario == ["R1"]


def test_parser_accepts_demo_exchange_runtime_recipe_target():
    args = runner_module._parse_args(
        [
            "--confirm",
            "DRY_RUN_ONLY",
            "--target-exchange",
            "demo-exchanges",
            "--scenario",
            "R1",
        ]
    )

    assert args.target_exchange == "demo-exchanges"
    assert args.scenario == ["R1"]


def test_r11_uses_distinct_anchors_for_schedule_overlap(tmp_path: Path):
    api = FakeRecipeApi(overlap_guard=True)
    report = RecipeRunner(
        RunnerConfig(export_dir=tmp_path, confirmation_token="DRY_RUN_ONLY"),
        http_client=api,
    ).run(scenarios=("R11",), keep_fixtures=True)

    r11 = report["results"][0]
    assert r11["status"] == "PASS"
    run_requests = [request for request in api.requests if request["path"] == "/orchestrator/run"]
    assert len(run_requests) == 2
    keys = [request["json"]["idempotency_key"] for request in run_requests]
    ticks = [request["json"]["tick_timestamp"] for request in run_requests]
    assert keys[0] != keys[1]
    assert all(key.startswith("recipe-r11-") for key in keys)
    assert set(ticks) == {"2026-06-26T00:00:00Z", "2026-06-26T00:00:01Z"}


def test_r11_blocks_when_overlap_contention_is_not_observed(tmp_path: Path):
    api = FakeRecipeApi()
    report = RecipeRunner(
        RunnerConfig(export_dir=tmp_path, confirmation_token="DRY_RUN_ONLY"),
        http_client=api,
    ).run(scenarios=("R11",), keep_fixtures=True)

    r11 = report["results"][0]
    assert r11["status"] == "BLOCKED"
    assert "not observed" in r11["notes"]


def test_r11_rejects_both_failed_without_overlap_evidence(tmp_path: Path):
    api = FakeRecipeApi(r11_outage=True)
    report = RecipeRunner(
        RunnerConfig(export_dir=tmp_path, confirmation_token="DRY_RUN_ONLY"),
        http_client=api,
    ).run(scenarios=("R11",), keep_fixtures=True)

    r11 = report["results"][0]
    assert r11["status"] == "FAIL"


def test_r14_rejects_non_guard_errors(tmp_path: Path):
    for status, body in (
        (500, {"detail": "database unavailable"}),
        (409, {"detail": "set already exists"}),
        (422, {"detail": "unrelated validation error"}),
    ):
        api = FakeRecipeApi(live_probe_status=status, live_probe_body=body)
        report = RecipeRunner(
            RunnerConfig(export_dir=tmp_path, confirmation_token="DRY_RUN_ONLY"),
            http_client=api,
        ).run(scenarios=("R14",), keep_fixtures=True)

        r14 = report["results"][0]
        assert r14["status"] == "FAIL"


def test_r14_deletes_probe_when_live_guard_accepts_it(tmp_path: Path):
    api = FakeRecipeApi(live_probe_status=201)
    report = RecipeRunner(
        RunnerConfig(export_dir=tmp_path, confirmation_token="DRY_RUN_ONLY"),
        http_client=api,
    ).run(scenarios=("R14",), keep_fixtures=True)

    r14 = report["results"][0]
    assert r14["status"] == "FAIL"
    assert any(
        request["method"] == "DELETE"
        and request["path"].endswith("/sets/recipe_okx_live_forbidden_probe")
        for request in api.requests
    )
    nominal_id = report["dashboards"]["nominal"]["id"]
    assert all(
        item["set_id"] != "recipe_okx_live_forbidden_probe"
        for item in api.sets[nominal_id]
    )


def test_r5_rejects_symfony_outage_as_functional_evidence(tmp_path: Path):
    api = FakeRecipeApi(r5_outage=True)
    report = RecipeRunner(
        RunnerConfig(export_dir=tmp_path, confirmation_token="DRY_RUN_ONLY"),
        http_client=api,
    ).run(scenarios=("R5",), keep_fixtures=True)

    r5 = report["results"][0]
    assert r5["status"] == "FAIL"


def test_r6_requires_outage_evidence_before_passing(tmp_path: Path):
    business_api = FakeRecipeApi(r6_business_failure=True)
    business_report = RecipeRunner(
        RunnerConfig(export_dir=tmp_path, confirmation_token="DRY_RUN_ONLY"),
        http_client=business_api,
    ).run(scenarios=("R6",), keep_fixtures=True)

    assert business_report["results"][0]["status"] == "FAIL"

    outage_api = FakeRecipeApi(r6_outage=True)
    outage_report = RecipeRunner(
        RunnerConfig(export_dir=tmp_path, confirmation_token="DRY_RUN_ONLY"),
        http_client=outage_api,
    ).run(scenarios=("R6",), keep_fixtures=True)

    assert outage_report["results"][0]["status"] == "PASS"


def test_transport_failures_do_not_pass_recipe_scenarios(tmp_path: Path):
    api = FakeRecipeApi(run_transport_error=True)
    report = RecipeRunner(
        RunnerConfig(export_dir=tmp_path, confirmation_token="DRY_RUN_ONLY"),
        http_client=api,
    ).run(scenarios=("R5", "R6", "R8", "R11"), keep_fixtures=True)

    statuses = {item["scenario"]: item["status"] for item in report["results"]}
    assert statuses == {"R5": "FAIL", "R6": "FAIL", "R8": "FAIL", "R11": "FAIL"}


def test_r8_replay_requires_successful_original_run(tmp_path: Path):
    api = FakeRecipeApi(r8_failure=True)

    report = RecipeRunner(
        RunnerConfig(export_dir=tmp_path, confirmation_token="DRY_RUN_ONLY"),
        http_client=api,
    ).run(scenarios=("R8",), keep_fixtures=True)

    assert report["results"][0]["status"] == "FAIL"


def test_temporal_dry_run_command_is_bounded_by_timeout(tmp_path: Path, monkeypatch):
    api = FakeRecipeApi()

    def timeout_run(*args, **kwargs):
        assert kwargs["timeout"] == 0.25
        raise subprocess.TimeoutExpired(cmd=args[0], timeout=kwargs["timeout"])

    monkeypatch.setattr(runner_module.subprocess, "run", timeout_run)

    report = RecipeRunner(
        RunnerConfig(
            export_dir=tmp_path,
            confirmation_token="DRY_RUN_ONLY",
            timeout_seconds=0.25,
            temporal_available=True,
            temporal_dry_run_command=(
                "temporal",
                "schedule",
                "describe",
                "{dashboard_id}",
                "--dry-run",
            ),
        ),
        http_client=api,
    ).run(scenarios=("R15",), keep_fixtures=True)

    r15 = report["results"][0]
    assert r15["status"] == "FAIL"
    assert "timed out" in r15["notes"]


def test_temporal_timeout_bytes_are_exported_as_text(tmp_path: Path, monkeypatch):
    api = FakeRecipeApi()

    def timeout_run(*args, **kwargs):
        raise subprocess.TimeoutExpired(
            cmd=args[0],
            timeout=kwargs["timeout"],
            output=b"partial stdout",
            stderr=b"partial stderr",
        )

    monkeypatch.setattr(runner_module.subprocess, "run", timeout_run)

    report = RecipeRunner(
        RunnerConfig(
            export_dir=tmp_path,
            confirmation_token="DRY_RUN_ONLY",
            timeout_seconds=0.25,
            temporal_available=True,
            temporal_dry_run_command=("temporal", "schedule", "describe", "--dry-run"),
        ),
        http_client=api,
    ).run(scenarios=("R15",), keep_fixtures=True)

    exported = json.loads((tmp_path / "runtime-recipe-report.json").read_text(encoding="utf-8"))
    evidence = exported["results"][0]["evidence"]
    assert report == exported
    assert evidence["stdout"] == "partial stdout"
    assert evidence["stderr"] == "partial stderr"


def test_temporal_command_replaces_only_dashboard_id_token(tmp_path: Path, monkeypatch):
    api = FakeRecipeApi()
    observed: dict[str, Any] = {}

    def successful_run(args, **kwargs):
        observed["args"] = args

        class Completed:
            returncode = 0
            stdout = "ok"
            stderr = ""

        return Completed()

    monkeypatch.setattr(runner_module.subprocess, "run", successful_run)

    report = RecipeRunner(
        RunnerConfig(
            export_dir=tmp_path,
            confirmation_token="DRY_RUN_ONLY",
            temporal_available=True,
            temporal_dry_run_command=(
                "temporal",
                "schedule",
                "create",
                "--dashboard-id",
                "{dashboard_id}",
                "--memo",
                '{"literal": "{kept}"}',
                "--dry-run",
            ),
        ),
        http_client=api,
    ).run(scenarios=("R15",), keep_fixtures=True)

    assert report["results"][0]["status"] == "PASS"
    assert observed["args"][4] == str(report["dashboards"]["nominal"]["id"])
    assert observed["args"][6] == '{"literal": "{kept}"}'


def test_temporal_command_requires_dry_run_flag(tmp_path: Path, monkeypatch):
    api = FakeRecipeApi()

    def forbidden_run(*args, **kwargs):
        raise AssertionError("subprocess.run must not execute a non-dry-run command")

    monkeypatch.setattr(runner_module.subprocess, "run", forbidden_run)

    report = RecipeRunner(
        RunnerConfig(
            export_dir=tmp_path,
            confirmation_token="DRY_RUN_ONLY",
            temporal_available=True,
            temporal_dry_run_command=(
                "temporal",
                "schedule",
                "create",
                "--dashboard-id",
                "{dashboard_id}",
            ),
        ),
        http_client=api,
    ).run(scenarios=("R15",), keep_fixtures=True)

    r15 = report["results"][0]
    assert r15["status"] == "FAIL"
    assert "missing required --dry-run" in r15["notes"]


def test_temporal_rejected_command_redacts_secret_flag_values(tmp_path: Path, monkeypatch):
    api = FakeRecipeApi()

    def forbidden_run(*args, **kwargs):
        raise AssertionError("subprocess.run must not execute a non-dry-run command")

    monkeypatch.setattr(runner_module.subprocess, "run", forbidden_run)

    report = RecipeRunner(
        RunnerConfig(
            export_dir=tmp_path,
            confirmation_token="DRY_RUN_ONLY",
            temporal_available=True,
            temporal_dry_run_command=(
                "temporal",
                "schedule",
                "create",
                "--api-key",
                "plaincredential123",
            ),
        ),
        http_client=api,
    ).run(scenarios=("R15",), keep_fixtures=True)

    exported_raw = (tmp_path / "runtime-recipe-report.json").read_text(encoding="utf-8")
    command = report["results"][0]["evidence"]["command"]
    assert "plaincredential123" not in exported_raw
    assert command[-2:] == ["REDACTED", "REDACTED"]


def test_temporal_launch_error_is_reported_without_aborting_later_scenarios(
    tmp_path: Path, monkeypatch
):
    api = FakeRecipeApi()

    def missing_run(*args, **kwargs):
        raise FileNotFoundError("temporal executable not found")

    monkeypatch.setattr(runner_module.subprocess, "run", missing_run)

    report = RecipeRunner(
        RunnerConfig(
            export_dir=tmp_path,
            confirmation_token="DRY_RUN_ONLY",
            temporal_available=True,
            temporal_dry_run_command=("temporal-missing", "schedule", "describe", "--dry-run"),
        ),
        http_client=api,
    ).run(scenarios=("R15", "R16"), keep_fixtures=True)

    statuses = {item["scenario"]: item["status"] for item in report["results"]}
    assert statuses == {"R15": "FAIL", "R16": "BLOCKED"}
    exported = json.loads((tmp_path / "runtime-recipe-report.json").read_text(encoding="utf-8"))
    assert exported["summary"]["scenario_counts"] == {"FAIL": 1, "BLOCKED": 1}
