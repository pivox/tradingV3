"""Runner reproductible de recette runtime R1-R16 (#188).

Le runner applique les fixtures Fake/Paper dry-run, execute les scenarios
automatisables via l'API HTTP existante, exporte un rapport JSON et marque
explicitement `BLOCKED` les scenarios qui exigent une panne/crash/Temporal reel
non pilote par ce script. Il ne modifie aucune strategie et force toujours
`dry_run=true` sur les appels d'execution.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import subprocess
from collections import Counter
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterable, Protocol, Sequence
from uuid import uuid4

import httpx


ROOT = Path(__file__).resolve().parents[1]
DEFAULT_FIXTURES_DIR = ROOT / "fixtures" / "runtime-recipe"
DEFAULT_EXPORT_DIR = ROOT / "var" / "runtime-recipe"
CONFIRMATION_TOKEN = "DRY_RUN_ONLY"
FAKE_ONLY_EXCHANGE_SAFETY_SCHEMA_VERSION = "fake-only-exchange-safety-v2"
FAKE_ONLY_EXCHANGE_CALL_PROOF = {
    "bitmart": "fake_provider_boundary",
    "hyperliquid": "http_client_guard",
    "okx": "http_client_guard",
}

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

ALL_SCENARIOS = tuple(f"R{i}" for i in range(1, 17))
DEFAULT_SCENARIOS = ("R1", "R2", "R5", "R6", "R8", "R10", "R11", "R14", "R15", "R16")
TARGET_EXCHANGE_SCENARIOS = frozenset({"R1", "R2", "R14"})

SECRET_KEY_RE = re.compile(
    r"(api[_-]?key|secret|password|passwd|token|signature|private[_-]?key|"
    r"authorization|x-bitmart-sign)",
    re.IGNORECASE,
)

RUNTIME_CHECK_COMMAND_PREFIX = (
    "docker",
    "compose",
    "exec",
    "-T",
    "trading-app-php",
    "php",
    "bin/console",
    "app:exchange:runtime-check",
)

TARGET_FIXTURE_FILES = {
    "okx": "r1_r16_okx_dry_run_dashboard.json",
    "hyperliquid": "r1_r16_hyperliquid_dry_run_dashboard.json",
}
DEMO_EXCHANGES_TARGET = "demo-exchanges"
DEMO_EXCHANGE_TARGETS = {
    "global": "fake",
    "okx": "okx",
    "hyperliquid": "hyperliquid",
}


class RecipeHttpClient(Protocol):
    def request(
        self, method: str, path: str, *, json_body: Any = None, timeout: float = 30.0
    ) -> tuple[int, Any]:
        """Execute une requete HTTP et retourne `(status_code, body)`."""


class HttpxRecipeHttpClient:
    """Client HTTP simple vers l'API Python orchestrator."""

    def __init__(self, base_url: str) -> None:
        self.base_url = base_url.rstrip("/")

    def request(
        self, method: str, path: str, *, json_body: Any = None, timeout: float = 30.0
    ) -> tuple[int, Any]:
        try:
            with httpx.Client(timeout=timeout) as client:
                response = client.request(method, f"{self.base_url}{path}", json=json_body)
        except httpx.HTTPError as exc:
            return 0, {"detail": str(exc)}
        try:
            body: Any = response.json()
        except ValueError:
            body = {"raw": response.text}
        return response.status_code, body


@dataclass(frozen=True)
class RunnerConfig:
    export_dir: Path
    fixtures_dir: Path = DEFAULT_FIXTURES_DIR
    orchestrator_url: str = "http://localhost:8099"
    confirmation_token: str | None = None
    target_exchange: str = "fake"
    timeout_seconds: float = 30.0
    temporal_available: bool = False
    temporal_dry_run_command: Sequence[str] | None = None
    cleanup: bool = False


class RecipeRunner:
    def __init__(
        self,
        config: RunnerConfig,
        *,
        http_client: RecipeHttpClient | None = None,
    ) -> None:
        self.config = config
        self.http = http_client or HttpxRecipeHttpClient(config.orchestrator_url)
        self.fixtures = self._load_fixtures()
        self.invocation_id = ""

    def run(
        self,
        *,
        scenarios: Iterable[str] | None = None,
        keep_fixtures: bool = False,
    ) -> dict[str, Any]:
        self._assert_confirmed()
        target_exchange = self.config.target_exchange.strip().lower()
        default_scenarios = (
            ALL_SCENARIOS if target_exchange == DEMO_EXCHANGES_TARGET else DEFAULT_SCENARIOS
        )
        selected = tuple(scenarios if scenarios is not None else default_scenarios)
        if target_exchange == DEMO_EXCHANGES_TARGET:
            return self._run_demo_exchanges(selected, keep_fixtures=keep_fixtures)

        started_at = datetime.now(timezone.utc).isoformat()
        self.invocation_id = uuid4().hex[:12]

        service_ok, service_note = self._check_orchestrator()
        preflight = self._run_target_preflight() if service_ok else {"status": "BLOCKED", "notes": service_note}
        dashboards: dict[str, dict[str, Any]] = {}
        results: list[dict[str, Any]] = []

        if service_ok:
            dashboards = self._apply_all_fixtures(
                include_target=not self._target_requires_preflight()
                or preflight["status"] == "PASS",
                include_multi_profile="R12" in selected,
            )
            for scenario in selected:
                if self._scenario_requires_target_preflight(scenario) and preflight["status"] != "PASS":
                    results.append(self._result(scenario, "BLOCKED", preflight["notes"], preflight))
                else:
                    results.append(self._run_scenario(scenario, dashboards))
            if self.config.cleanup and not keep_fixtures:
                self._cleanup_dashboards(dashboards)
        else:
            blocked_note = service_note if not service_ok else preflight["notes"]
            for scenario in selected:
                results.append(self._result(scenario, "BLOCKED", blocked_note))

        report = {
            "metadata": {
                "started_at": started_at,
                "finished_at": datetime.now(timezone.utc).isoformat(),
                "dry_run_forced": True,
                "confirmation_token": "REDACTED",
                "invocation_id": self.invocation_id,
                "orchestrator_url": self.config.orchestrator_url,
                "fixtures_dir": str(self.config.fixtures_dir),
                "target_exchange": self.config.target_exchange,
                "runtime_check": preflight,
                "cleanup_requested": self.config.cleanup,
            },
            "dashboards": dashboards,
            "results": results,
            "summary": {
                "scenario_counts": dict(Counter(item["status"] for item in results)),
                "ready_for_final_report": False,
            },
        }
        report = self._redact(report)
        self._export(report)
        return report

    def _run_demo_exchanges(
        self,
        selected: tuple[str, ...],
        *,
        keep_fixtures: bool,
    ) -> dict[str, Any]:
        started_at = datetime.now(timezone.utc).isoformat()
        self.invocation_id = uuid4().hex[:12]

        exchange_results: dict[str, list[dict[str, Any]]] = {}
        exchange_summaries: dict[str, dict[str, Any]] = {}
        dashboards: dict[str, dict[str, Any]] = {}
        runtime_checks: dict[str, Any] = {}
        flattened_results: list[dict[str, Any]] = []

        for exchange_scope, target_exchange in DEMO_EXCHANGE_TARGETS.items():
            target_scenarios = self._demo_exchange_scenarios(exchange_scope, selected)
            sub_report: dict[str, Any] | None = None
            observed_by_scenario: dict[str, dict[str, Any]] = {}
            if target_scenarios:
                sub_runner = RecipeRunner(
                    RunnerConfig(
                        export_dir=self.config.export_dir / exchange_scope,
                        fixtures_dir=self.config.fixtures_dir,
                        orchestrator_url=self.config.orchestrator_url,
                        confirmation_token=self.config.confirmation_token,
                        target_exchange=target_exchange,
                        timeout_seconds=self.config.timeout_seconds,
                        temporal_available=self.config.temporal_available,
                        temporal_dry_run_command=self.config.temporal_dry_run_command,
                        cleanup=self.config.cleanup,
                    ),
                    http_client=self.http,
                )
                sub_report = sub_runner.run(scenarios=target_scenarios, keep_fixtures=keep_fixtures)
                observed_by_scenario = {
                    item["scenario"]: {**item, "exchange_scope": exchange_scope}
                    for item in sub_report["results"]
                }

            ordered_results: list[dict[str, Any]] = []
            for scenario in selected:
                result = observed_by_scenario.get(scenario)
                if result is None:
                    result = {
                        **self._result(
                            scenario,
                            "BLOCKED",
                            (
                                f"{scenario} is covered by the global Fake/Paper baseline; "
                                f"no {exchange_scope} exchange-specific automation exists in this dry-run runner"
                            ),
                        ),
                        "exchange_scope": exchange_scope,
                    }
                ordered_results.append(result)

            exchange_results[exchange_scope] = ordered_results
            exchange_summaries[exchange_scope] = {
                "target_exchange": target_exchange,
                "scenario_counts": dict(Counter(item["status"] for item in ordered_results)),
            }
            dashboards[exchange_scope] = sub_report.get("dashboards", {}) if sub_report else {}
            runtime_checks[exchange_scope] = (
                sub_report.get("metadata", {}).get("runtime_check") if sub_report else None
            )
            flattened_results.extend(ordered_results)

        report = {
            "metadata": {
                "started_at": started_at,
                "finished_at": datetime.now(timezone.utc).isoformat(),
                "dry_run_forced": True,
                "confirmation_token": "REDACTED",
                "invocation_id": self.invocation_id,
                "orchestrator_url": self.config.orchestrator_url,
                "fixtures_dir": str(self.config.fixtures_dir),
                "target_exchange": DEMO_EXCHANGES_TARGET,
                "exchange_targets": DEMO_EXCHANGE_TARGETS,
                "runtime_checks": runtime_checks,
                "cleanup_requested": self.config.cleanup,
            },
            "dashboards": dashboards,
            "exchange_results": exchange_results,
            "exchange_summaries": exchange_summaries,
            "results": flattened_results,
            "summary": {
                "scenario_counts": dict(Counter(item["status"] for item in flattened_results)),
                "ready_for_final_report": False,
            },
        }
        report = self._redact(report)
        self._export(report)
        return report

    def _demo_exchange_scenarios(
        self,
        exchange_scope: str,
        selected: tuple[str, ...],
    ) -> tuple[str, ...]:
        if exchange_scope == "global":
            return selected
        return tuple(scenario for scenario in selected if scenario in TARGET_EXCHANGE_SCENARIOS)

    def _assert_confirmed(self) -> None:
        if self.config.confirmation_token != CONFIRMATION_TOKEN:
            raise SystemExit(
                f"refusing to run recipe without --confirm {CONFIRMATION_TOKEN}; "
                "this runner is dry-run only."
            )

    def _check_orchestrator(self) -> tuple[bool, str]:
        status, body = self.http.request("GET", "/healthcheck", timeout=self.config.timeout_seconds)
        if 200 <= status < 300:
            return True, "orchestrator reachable"
        return False, f"orchestrator unavailable: status={status}, body={body}"

    def _run_target_preflight(self) -> dict[str, Any]:
        target = self.config.target_exchange.strip().lower()
        if target == "fake":
            return {"status": "PASS", "notes": "runtime-check not required for Fake/Paper dry-run"}
        if target not in TARGET_FIXTURE_FILES:
            return {
                "status": "BLOCKED",
                "notes": f"unsupported runtime recipe target_exchange: {self.config.target_exchange}",
            }
        command = (*RUNTIME_CHECK_COMMAND_PREFIX, target, "perpetual")
        try:
            completed = subprocess.run(
                command,
                cwd=ROOT.parent,
                capture_output=True,
                text=True,
                check=False,
                timeout=self.config.timeout_seconds,
            )
        except (OSError, subprocess.TimeoutExpired) as exc:
            return {
                "status": "BLOCKED",
                "notes": f"{target} runtime-check did not complete",
                "command": list(command),
                "error_type": exc.__class__.__name__,
                "error": str(exc),
            }
        parsed = self._parse_runtime_check_output(completed.stdout)
        schedule_ready = parsed.get("schedule_ready")
        if completed.returncode == 0 and schedule_ready == "yes":
            return {
                "status": "PASS",
                "notes": f"{target} runtime-check schedule ready",
                "command": list(command),
                "readiness_level": parsed.get("readiness_level"),
                "schedule_ready": schedule_ready,
            }
        return {
            "status": "BLOCKED",
            "notes": f"{target} runtime-check is not schedule ready",
            "command": list(command),
            "returncode": completed.returncode,
            "readiness_level": parsed.get("readiness_level"),
            "schedule_ready": schedule_ready or "unknown",
            "stdout": completed.stdout,
            "stderr": completed.stderr,
        }

    @staticmethod
    def _parse_runtime_check_output(output: str) -> dict[str, str]:
        parsed: dict[str, str] = {}
        for line in output.splitlines():
            if ":" not in line:
                continue
            key, value = line.split(":", 1)
            parsed[key.strip().lower().replace(" ", "_")] = value.strip().lower()
        return parsed

    def _load_fixtures(self) -> dict[str, dict[str, Any]]:
        nominal = self._read_fixture("r1_r16_nominal_fake_dashboard.json")
        degraded = self._read_fixture("r1_r16_degraded_fake_dashboard.json")
        multi_profile = self._read_fixture("fake_multi_profile_same_symbol.json")
        fixtures = {"nominal": nominal, "degraded": degraded, "multi_profile": multi_profile}
        target = self.config.target_exchange.strip().lower()
        if target == "fake":
            return fixtures
        fixture_file = TARGET_FIXTURE_FILES.get(target)
        if fixture_file is None:
            return fixtures
        target_path = self.config.fixtures_dir / fixture_file
        if not target_path.exists():
            raise FileNotFoundError(f"missing {target} runtime recipe fixture: {target_path}")
        fixtures[target] = json.loads(target_path.read_text(encoding="utf-8"))
        return fixtures

    def _read_fixture(self, filename: str) -> dict[str, Any]:
        return json.loads((self.config.fixtures_dir / filename).read_text(encoding="utf-8"))

    def _apply_all_fixtures(
        self,
        *,
        include_target: bool = True,
        include_multi_profile: bool = False,
    ) -> dict[str, dict[str, Any]]:
        target = self.config.target_exchange.strip().lower()
        return {
            name: self._apply_fixture(fixture)
            for name, fixture in self.fixtures.items()
            if (include_target or name != target)
            and (include_multi_profile or name != "multi_profile")
        }

    def _scenario_requires_target_preflight(self, scenario: str) -> bool:
        return (
            self._target_requires_preflight()
            and scenario in TARGET_EXCHANGE_SCENARIOS
        )

    def _target_requires_preflight(self) -> bool:
        return self.config.target_exchange.strip().lower() in TARGET_FIXTURE_FILES

    def _apply_fixture(self, fixture: dict[str, Any]) -> dict[str, Any]:
        dashboard_id = self._upsert_dashboard(fixture["dashboard"])
        existing_sets = {
            item["set_id"]: item
            for item in self._request_ok("GET", f"/dashboards/{dashboard_id}/sets")
        }
        fixture_set_ids = {item["set_id"] for item in fixture["sets"]}
        for set_id, existing_set in existing_sets.items():
            if set_id not in fixture_set_ids and existing_set.get("enabled") is True:
                self._request_ok(
                    "PATCH",
                    f"/dashboards/{dashboard_id}/sets/{set_id}",
                    json_body={"enabled": False, "dry_run": True},
                )
        for fixture_set in fixture["sets"]:
            payload = {key: fixture_set[key] for key in SET_FIELDS if key in fixture_set}
            payload["dry_run"] = True
            payload["workers"] = 1
            payload["sync_tables"] = False
            if fixture_set["set_id"] in existing_sets:
                patch_payload = {key: value for key, value in payload.items() if key != "set_id"}
                self._request_ok(
                    "PATCH",
                    f"/dashboards/{dashboard_id}/sets/{fixture_set['set_id']}",
                    json_body=patch_payload,
                )
            else:
                self._request_ok(
                    "POST",
                    f"/dashboards/{dashboard_id}/sets",
                    json_body=payload,
                    expected=(200, 201),
                )
        return {"id": dashboard_id, "name": fixture["dashboard"]["name"]}

    def _upsert_dashboard(self, dashboard_payload: dict[str, Any]) -> int:
        dashboards = self._request_ok("GET", "/dashboards")
        for dashboard in dashboards:
            if dashboard["name"] == dashboard_payload["name"]:
                self._request_ok(
                    "PATCH",
                    f"/dashboards/{dashboard['id']}",
                    json_body=dashboard_payload,
                )
                return int(dashboard["id"])
        created = self._request_ok(
            "POST",
            "/dashboards",
            json_body=dashboard_payload,
            expected=(200, 201),
        )
        return int(created["id"])

    def _request_ok(
        self,
        method: str,
        path: str,
        *,
        json_body: Any = None,
        expected: tuple[int, ...] = (200,),
    ) -> Any:
        status, body = self.http.request(
            method,
            path,
            json_body=json_body,
            timeout=self.config.timeout_seconds,
        )
        if status not in expected:
            raise RuntimeError(f"{method} {path} failed: status={status}, body={body}")
        return body

    def _run_scenario(self, scenario: str, dashboards: dict[str, dict[str, Any]]) -> dict[str, Any]:
        handlers = {
            "R1": self._scenario_r1,
            "R2": self._scenario_r2,
            "R5": self._scenario_r5,
            "R6": self._scenario_r6,
            "R8": self._scenario_r8,
            "R10": self._scenario_r10,
            "R11": self._scenario_r11,
            "R12": self._scenario_r12,
            "R14": self._scenario_r14,
            "R15": self._scenario_r15,
            "R16": self._scenario_r16,
        }
        handler = handlers.get(scenario)
        if handler is None:
            return self._result(scenario, "BLOCKED", "scenario documented but not automated in this runner")
        return handler(dashboards)

    def _scenario_r1(self, dashboards: dict[str, dict[str, Any]]) -> dict[str, Any]:
        target = self._target_recipe()
        dashboard_id = dashboards[target["dashboard"]]["id"]
        self._set_enabled(dashboard_id, target["r1_set"], True)
        for set_id in target["r2_sets"]:
            if set_id != target["r1_set"]:
                self._set_enabled(dashboard_id, set_id, False)
        self._set_enabled(dashboard_id, target["disabled_set"], False)
        response = self._run_dashboard(dashboard_id, self._scenario_key("recipe-r1"))
        ok = response.get("status") == "success" and response.get("summary", {}).get("total_calls") == 1
        return self._result(
            "R1",
            "PASS" if ok else "FAIL",
            f"single-set {self.config.target_exchange} dry-run",
            response,
        )

    def _scenario_r2(self, dashboards: dict[str, dict[str, Any]]) -> dict[str, Any]:
        target = self._target_recipe()
        dashboard_id = dashboards[target["dashboard"]]["id"]
        for set_id in target["r2_sets"]:
            self._set_enabled(dashboard_id, set_id, True)
        self._set_enabled(dashboard_id, target["disabled_set"], False)
        response = self._run_dashboard(dashboard_id, self._scenario_key("recipe-r2"))
        ok = (
            response.get("status") == "success"
            and response.get("summary", {}).get("total_calls") == len(target["r2_sets"])
        )
        return self._result(
            "R2",
            "PASS" if ok else "FAIL",
            f"multi-set {self.config.target_exchange} dry-run",
            response,
        )

    def _scenario_r5(self, dashboards: dict[str, dict[str, Any]]) -> dict[str, Any]:
        dashboard_id = dashboards["degraded"]["id"]
        self._set_enabled(dashboard_id, "recipe_fake_not_materialized", False)
        self._set_enabled(dashboard_id, "recipe_fake_error_regular", True)
        self._set_enabled(dashboard_id, "recipe_fake_overlap_scalper", False)
        response = self._run_dashboard(dashboard_id, self._scenario_key("recipe-r5"))
        detail = self._fetch_run_detail(response)
        ok = (
            not self._is_transport_failure(response)
            and response.get("summary", {}).get("failed", 0) >= 1
            and response.get("status") != "success"
            and self._has_r5_functional_failure_evidence(detail)
        )
        return self._result(
            "R5",
            "PASS" if ok else "FAIL",
            "functional Symfony error remains failed",
            {"run": response, "detail": detail},
        )

    def _scenario_r6(self, dashboards: dict[str, dict[str, Any]]) -> dict[str, Any]:
        dashboard_id = dashboards["degraded"]["id"]
        self._set_enabled(dashboard_id, "recipe_fake_not_materialized", False)
        self._set_enabled(dashboard_id, "recipe_fake_error_regular", False)
        self._set_enabled(dashboard_id, "recipe_fake_overlap_scalper", True)
        response = self._run_dashboard(dashboard_id, self._scenario_key("recipe-r6"))
        if self._is_transport_failure(response):
            return self._result(
                "R6",
                "FAIL",
                "orchestrator transport failure is not Symfony outage evidence",
                response,
            )
        if response.get("status") == "success":
            return self._result(
                "R6",
                "BLOCKED",
                "Symfony outage/timeout was not injected or observed during this run",
                response,
            )
        detail = self._fetch_run_detail(response)
        ok = response.get("summary", {}).get("failed", 0) >= 1 and self._has_outage_evidence(detail)
        return self._result(
            "R6",
            "PASS" if ok else "FAIL",
            "Symfony outage/timeout surfaced as a failed set",
            {"run": response, "detail": detail},
        )

    def _scenario_r8(self, dashboards: dict[str, dict[str, Any]]) -> dict[str, Any]:
        dashboard_id = dashboards["nominal"]["id"]
        self._set_enabled(dashboard_id, "recipe_fake_regular", True)
        self._set_enabled(dashboard_id, "recipe_fake_scalper", False)
        self._set_enabled(dashboard_id, "recipe_fake_scalper_micro", False)
        replay_key = self._scenario_key("recipe-r8")
        first = self._run_dashboard(dashboard_id, replay_key)
        second = self._run_dashboard(dashboard_id, replay_key)
        ok = (
            self._has_real_run(first)
            and self._has_real_run(second)
            and self._is_successful_run(first)
            and self._is_successful_run(second)
            and first.get("run_id") == second.get("run_id")
        )
        return self._result(
            "R8",
            "PASS" if ok else "FAIL",
            "same idempotency key replays same run",
            {"first": first, "second": second},
        )

    def _scenario_r10(self, _dashboards: dict[str, dict[str, Any]]) -> dict[str, Any]:
        return self._result(
            "R10",
            "BLOCKED",
            "requires controlled orchestrator crash after claim; this runner does not kill services",
        )

    def _scenario_r11(self, dashboards: dict[str, dict[str, Any]]) -> dict[str, Any]:
        dashboard_id = dashboards["degraded"]["id"]
        self._set_enabled(dashboard_id, "recipe_fake_not_materialized", False)
        self._set_enabled(dashboard_id, "recipe_fake_error_regular", False)
        self._set_enabled(dashboard_id, "recipe_fake_overlap_scalper", True)
        with ThreadPoolExecutor(max_workers=2) as executor:
            first, second = list(
                executor.map(
                    lambda item: self._run_dashboard(
                        dashboard_id,
                        self._scenario_key(f"recipe-r11-{item[0]}"),
                        tick_timestamp=item[1],
                    ),
                    (
                        ("a", "2026-06-26T00:00:00Z"),
                        ("b", "2026-06-26T00:00:01Z"),
                    ),
                )
            )
        distinct_real_runs = (
            self._has_real_run(first)
            and self._has_real_run(second)
            and first.get("run_id") != second.get("run_id")
        )
        first_detail = self._fetch_run_detail(first)
        second_detail = self._fetch_run_detail(second)
        overlap_observed = self._has_overlap_guard_evidence(first, first_detail) or (
            self._has_overlap_guard_evidence(second, second_detail)
        )
        both_success = first.get("status") == "success" and second.get("status") == "success"
        if distinct_real_runs and overlap_observed:
            status = "PASS"
            notes = "overlapping ticks surfaced lock or in-flight evidence"
        elif distinct_real_runs and both_success:
            status = "BLOCKED"
            notes = "overlap contention was not observed; both runs completed sequentially"
        else:
            status = "FAIL"
            notes = "overlapping ticks did not produce valid lock or in-flight evidence"
        return self._result(
            "R11",
            status,
            notes,
            {
                "first": first,
                "second": second,
                "first_detail": first_detail,
                "second_detail": second_detail,
            },
        )

    def _scenario_r12(self, dashboards: dict[str, dict[str, Any]]) -> dict[str, Any]:
        fixture = self.fixtures["multi_profile"]
        dashboard_id = dashboards["multi_profile"]["id"]
        enabled_sets = [item for item in fixture["sets"] if item["enabled"] is True]
        disabled_sets = sorted(
            item["set_id"] for item in fixture["sets"] if item["enabled"] is False
        )
        for item in fixture["sets"]:
            self._set_enabled(dashboard_id, item["set_id"], bool(item["enabled"]))

        fixture_canonical = json.dumps(
            fixture,
            ensure_ascii=False,
            separators=(",", ":"),
            sort_keys=True,
        )
        fixture_hash = f"sha256:{hashlib.sha256(fixture_canonical.encode('utf-8')).hexdigest()}"
        recipe_key = (
            f"fake-golden20-{FAKE_ONLY_EXCHANGE_SAFETY_SCHEMA_VERSION}-"
            f"{fixture_hash[7:23]}"
        )
        first = self._run_dashboard(dashboard_id, recipe_key)
        replay = self._run_dashboard(dashboard_id, recipe_key)
        detail = self._fetch_run_detail(first)
        observed = {
            item["set_id"]: item
            for item in self._iter_run_set_evidence(detail)
            if isinstance(item.get("set_id"), str) and "payload_sent" in item
        }

        report_sets: list[dict[str, Any]] = []
        set_contracts_ok = True
        safety_evidence_ok = True
        exchange_calls = {"bitmart": 0, "hyperliquid": 0, "okx": 0}
        snapshot_reference_calls: dict[str, int] | None = None
        snapshot_exchange_calls = {"bitmart": 0, "hyperliquid": 0, "okx": 0}
        for fixture_set in enabled_sets:
            set_id = fixture_set["set_id"]
            result = observed.get(set_id, {})
            payload = result.get("payload_sent")
            payload = payload if isinstance(payload, dict) else {}
            response_json = result.get("response_json")
            processed_symbols = self._processed_symbols(response_json)
            orders_total = self._orders_total(response_json)
            evidence_ok, observed_exchange_calls = self._fake_only_safety_evidence(
                response_json
            )
            safety_evidence_ok = safety_evidence_ok and evidence_ok
            for exchange in exchange_calls:
                exchange_calls[exchange] += observed_exchange_calls[exchange]
            snapshot = payload.get("open_state_snapshot")
            snapshot_evidence = (
                snapshot.get("fake_only_safety_evidence") if isinstance(snapshot, dict) else None
            )
            snapshot_ok, observed_snapshot_calls = self._normalize_fake_only_safety_evidence(
                snapshot_evidence
            )
            safety_evidence_ok = safety_evidence_ok and snapshot_ok
            if snapshot_reference_calls is None:
                snapshot_reference_calls = observed_snapshot_calls
            elif snapshot_reference_calls != observed_snapshot_calls:
                safety_evidence_ok = False
            for exchange in snapshot_exchange_calls:
                snapshot_exchange_calls[exchange] = max(
                    snapshot_exchange_calls[exchange], observed_snapshot_calls[exchange]
                )
            config_hash = payload.get("config_hash")
            set_ok = (
                result.get("ok") is True
                and payload.get("dry_run") is True
                and payload.get("exchange") == "fake"
                and payload.get("market_type") == "perpetual"
                and payload.get("mtf_profile") == fixture_set["mtf_profile"]
                and payload.get("symbols") == ["BTCUSDT"]
                and processed_symbols is not None
                and set(processed_symbols) == {"BTCUSDT"}
                and payload.get("workers") == 1
                and isinstance(config_hash, str)
                and config_hash.startswith("sha256:")
                and orders_total == 0
            )
            set_contracts_ok = set_contracts_ok and set_ok
            report_sets.append(
                {
                    "config_hash": config_hash,
                    "dry_run": payload.get("dry_run"),
                    "exchange": payload.get("exchange"),
                    "lineage": {
                        "orchestration_run_id": first.get("run_id"),
                        "orchestration_set_id": set_id,
                    },
                    "market_type": payload.get("market_type"),
                    "orders_total": orders_total,
                    "profile": fixture_set["mtf_profile"],
                    "set_id": set_id,
                    "symbols": processed_symbols,
                }
            )

        for exchange in exchange_calls:
            exchange_calls[exchange] += snapshot_exchange_calls[exchange]

        config_hashes = [item["config_hash"] for item in report_sets]
        lineage_ids = [item["lineage"]["orchestration_set_id"] for item in report_sets]
        summary = first.get("summary") if isinstance(first.get("summary"), dict) else {}
        core_ok = (
            self._is_successful_run(first)
            and self._is_successful_run(replay)
            and first.get("run_id") == replay.get("run_id")
            and summary.get("total_calls") == len(enabled_sets)
            and summary.get("success") == len(enabled_sets)
            and len(observed) == len(enabled_sets)
            and set_contracts_ok
            and len(set(config_hashes)) == len(enabled_sets)
            and len(set(lineage_ids)) == len(enabled_sets)
            and not any(set_id in observed for set_id in disabled_sets)
        )
        if not core_ok or any(exchange_calls.values()):
            status = "FAIL"
        elif not safety_evidence_ok:
            status = "BLOCKED"
        else:
            status = "PASS"
        recipe_report = {
            "disabled_sets": disabled_sets,
            "exchange_call_proof": FAKE_ONLY_EXCHANGE_CALL_PROOF,
            "exchange_calls": exchange_calls,
            "fixture_hash": fixture_hash,
            "fixture_id": fixture["fixture_id"],
            "locks": {
                "business": {
                    "contract_conflict_reason": "cross_profile_symbol_locked",
                    "contract_conflict_status": "blocked",
                    "evidence_status": "not_exercised",
                    "observed": False,
                    "scope": "exchange+market_type+symbol",
                },
                "orchestration": {
                    "classification": "coexisting",
                    "conflict_reason": "locked",
                    "conflict_status": "skipped",
                    "keys": [
                        f"{item['mtf_profile']}|fake|perpetual|BTCUSDT"
                        for item in enabled_sets
                    ],
                    "scope": "mtf_profile+exchange+market_type+symbol",
                },
            },
            "parallelism": {
                "bounded": True,
                "sets": len(enabled_sets),
                "workers_per_set": 1,
            },
            "redacted": True,
            "replay": {
                "idempotency_key": recipe_key,
                "same_run_id": first.get("run_id") == replay.get("run_id"),
            },
            "restart": {"stable_recipe_key": True},
            "scenario": "dry_run_multi_profiles_same_symbol",
            "schema_version": "fake-multi-profile-recipe-report-v2",
            "sets": report_sets,
            "status": status,
        }
        return self._result(
            "R12",
            status,
            "three Fake profiles coexist on one symbol with deterministic isolated lineage",
            {"recipe_report": recipe_report},
        )

    @staticmethod
    def _orders_total(response_json: Any) -> int | None:
        if not isinstance(response_json, dict):
            return None
        data = response_json.get("data")
        orders_placed = data.get("orders_placed") if isinstance(data, dict) else None
        count = orders_placed.get("count") if isinstance(orders_placed, dict) else None
        total = count.get("total") if isinstance(count, dict) else None
        return total if isinstance(total, int) else None

    @staticmethod
    def _processed_symbols(response_json: Any) -> list[str] | None:
        if not isinstance(response_json, dict):
            return None
        data = response_json.get("data")
        symbols = data.get("symbols") if isinstance(data, dict) else None
        if not isinstance(symbols, dict):
            return None
        if any(type(symbol) is not str or not symbol for symbol in symbols):
            return None
        return sorted(symbols)

    @staticmethod
    def _fake_only_safety_evidence(response_json: Any) -> tuple[bool, dict[str, int]]:
        empty_calls = {"bitmart": 0, "hyperliquid": 0, "okx": 0}
        if not isinstance(response_json, dict):
            return False, empty_calls
        data = response_json.get("data")
        evidence = data.get("fake_only_safety_evidence") if isinstance(data, dict) else None
        return RecipeRunner._normalize_fake_only_safety_evidence(evidence)

    @staticmethod
    def _normalize_fake_only_safety_evidence(evidence: Any) -> tuple[bool, dict[str, int]]:
        empty_calls = {"bitmart": 0, "hyperliquid": 0, "okx": 0}
        if not isinstance(evidence, dict):
            return False, empty_calls
        calls = evidence.get("exchange_calls")
        calls_valid = isinstance(calls, dict) and set(calls) == set(empty_calls)
        normalized_calls = empty_calls.copy()
        if isinstance(calls, dict):
            for exchange in empty_calls:
                count = calls.get(exchange)
                if type(count) is int and count >= 0:
                    normalized_calls[exchange] = count
                else:
                    calls_valid = False

        ambiguous_calls = evidence.get("ambiguous_calls")
        valid = (
            calls_valid
            and evidence.get("schema_version") == FAKE_ONLY_EXCHANGE_SAFETY_SCHEMA_VERSION
            and evidence.get("source") == "symfony_fake_provider_boundary_and_http_guards"
            and evidence.get("exchange_call_proof") == FAKE_ONLY_EXCHANGE_CALL_PROOF
            and evidence.get("complete") is True
            and evidence.get("async_exchange_capable_dispatches_suppressed") is True
            and type(ambiguous_calls) is int
            and ambiguous_calls == 0
        )
        return valid, normalized_calls

    def _scenario_r14(self, dashboards: dict[str, dict[str, Any]]) -> dict[str, Any]:
        target = self._target_recipe()
        dashboard_id = dashboards[target["dashboard"]]["id"]
        status, body = self.http.request(
            "POST",
            f"/dashboards/{dashboard_id}/sets",
            json_body={
                "set_id": target["live_probe_set"],
                "enabled": False,
                "action": "mtf_run",
                "exchange": target["live_probe_exchange"],
                "market_type": "perpetual",
                "mtf_profile": "regular",
                "environment": target["live_probe_environment"],
                "dry_run": False,
                "workers": 1,
                "sync_tables": False,
                "symbols": ["BTCUSDT"],
                "contracts_limit": None,
                "priority": -100,
            },
            timeout=self.config.timeout_seconds,
        )
        ok = self._is_expected_live_guard_refusal(status, body)
        if not ok:
            self._delete_set_if_present(dashboard_id, target["live_probe_set"])
        return self._result(
            "R14",
            "PASS" if ok else "FAIL",
            "live guard refused non-dry-run set before dispatch",
            {"status": status, "body": body},
        )

    def _target_recipe(self) -> dict[str, Any]:
        target = self.config.target_exchange.strip().lower()
        if target == "fake":
            return {
                "dashboard": "nominal",
                "r1_set": "recipe_fake_regular",
                "r2_sets": ("recipe_fake_regular", "recipe_fake_scalper", "recipe_fake_scalper_micro"),
                "disabled_set": "recipe_fake_disabled",
                "live_probe_set": "recipe_okx_live_forbidden_probe",
                "live_probe_exchange": "okx",
                "live_probe_environment": "demo",
            }
        if target == "okx":
            return {
                "dashboard": "okx",
                "r1_set": "recipe_okx_regular",
                "r2_sets": ("recipe_okx_regular", "recipe_okx_scalper_micro"),
                "disabled_set": "recipe_okx_disabled",
                "live_probe_set": "recipe_okx_live_forbidden_probe",
                "live_probe_exchange": "okx",
                "live_probe_environment": "demo",
            }
        if target == "hyperliquid":
            return {
                "dashboard": "hyperliquid",
                "r1_set": "recipe_hyperliquid_regular",
                "r2_sets": ("recipe_hyperliquid_regular", "recipe_hyperliquid_scalper_micro"),
                "disabled_set": "recipe_hyperliquid_disabled",
                "live_probe_set": "recipe_hyperliquid_live_forbidden_probe",
                "live_probe_exchange": "hyperliquid",
                "live_probe_environment": "testnet",
            }
        raise ValueError(f"unsupported runtime recipe target_exchange: {self.config.target_exchange}")

    def _scenario_r15(self, dashboards: dict[str, dict[str, Any]]) -> dict[str, Any]:
        if not self.config.temporal_available:
            return self._result("R15", "BLOCKED", "Temporal availability not confirmed")
        command = tuple(self.config.temporal_dry_run_command or ())
        if command and command[0] == "--":
            command = command[1:]
        if not command:
            return self._result("R15", "BLOCKED", "no Temporal dry-run command configured")
        rendered_command = [
            part.replace("{dashboard_id}", str(dashboards["nominal"]["id"])) for part in command
        ]
        if "--dry-run" not in rendered_command:
            return self._result(
                "R15",
                "FAIL",
                "Temporal schedule command missing required --dry-run flag",
                {"command": rendered_command},
            )
        try:
            completed = subprocess.run(
                rendered_command,
                capture_output=True,
                text=True,
                check=False,
                timeout=self.config.timeout_seconds,
            )
        except subprocess.TimeoutExpired as exc:
            return self._result(
                "R15",
                "FAIL",
                "Temporal schedule dry-run command timed out",
                {
                    "timeout_seconds": self.config.timeout_seconds,
                    "stdout": self._normalize_subprocess_output(exc.stdout),
                    "stderr": self._normalize_subprocess_output(exc.stderr),
                },
            )
        except OSError as exc:
            return self._result(
                "R15",
                "FAIL",
                "Temporal schedule dry-run command failed to start",
                {
                    "error_type": exc.__class__.__name__,
                    "error": str(exc),
                },
            )
        ok = completed.returncode == 0
        return self._result(
            "R15",
            "PASS" if ok else "FAIL",
            "Temporal schedule dry-run command",
            {
                "returncode": completed.returncode,
                "stdout": completed.stdout,
                "stderr": completed.stderr,
            },
        )

    @staticmethod
    def _normalize_subprocess_output(value: str | bytes | None) -> str | None:
        if isinstance(value, bytes):
            return value.decode("utf-8", errors="replace")
        return value

    def _scenario_r16(self, _dashboards: dict[str, dict[str, Any]]) -> dict[str, Any]:
        if not self.config.temporal_available:
            return self._result("R16", "BLOCKED", "Temporal availability not confirmed")
        return self._result(
            "R16",
            "BLOCKED",
            "pause/resume rollback requires an existing Temporal schedule and operator confirmation",
        )

    def _set_enabled(self, dashboard_id: int, set_id: str, enabled: bool) -> None:
        self._request_ok(
            "PATCH",
            f"/dashboards/{dashboard_id}/sets/{set_id}",
            json_body={"enabled": enabled, "dry_run": True},
        )

    def _run_dashboard(
        self,
        dashboard_id: int,
        scenario_key: str,
        *,
        tick_timestamp: str = "2026-06-26T00:00:00Z",
    ) -> dict[str, Any]:
        status, body = self.http.request(
            "POST",
            "/orchestrator/run",
            json_body={
                "dashboard_id": str(dashboard_id),
                "schedule_id": "runtime-recipe-runner",
                "tick_timestamp": tick_timestamp,
                "idempotency_key": scenario_key,
                "dry_run": True,
            },
            timeout=self.config.timeout_seconds,
        )
        if status == 0 or status >= 500:
            return {
                "ok": False,
                "status": "transport_error",
                "summary": {"total_calls": 0, "success": 0, "failed": 1},
                "error": body,
            }
        return body if isinstance(body, dict) else {"ok": False, "status": "invalid_response", "body": body}

    def _fetch_run_detail(self, response: dict[str, Any]) -> dict[str, Any]:
        run_id = response.get("run_id")
        if not run_id:
            return {}
        status, body = self.http.request(
            "GET",
            f"/runs/{run_id}",
            timeout=self.config.timeout_seconds,
        )
        if status == 200 and isinstance(body, dict):
            return body
        return {"detail_unavailable": True, "status": status, "body": body}

    def _delete_set_if_present(self, dashboard_id: int, set_id: str) -> None:
        self.http.request(
            "DELETE",
            f"/dashboards/{dashboard_id}/sets/{set_id}",
            timeout=self.config.timeout_seconds,
        )

    def _scenario_key(self, base_key: str) -> str:
        return f"{base_key}-{self.invocation_id}"

    def _has_real_run(self, response: dict[str, Any]) -> bool:
        return bool(response.get("run_id")) and response.get("status") not in {
            "transport_error",
            "invalid_response",
            "no_sets",
        }

    def _is_successful_run(self, response: dict[str, Any]) -> bool:
        summary = response.get("summary", {})
        return (
            response.get("ok") is True
            and response.get("status") == "success"
            and isinstance(summary, dict)
            and summary.get("failed", 0) == 0
        )

    def _is_transport_failure(self, response: dict[str, Any]) -> bool:
        return response.get("status") == "transport_error"

    def _has_r5_functional_failure_evidence(self, detail: dict[str, Any]) -> bool:
        for item in self._iter_run_set_evidence(detail):
            if item.get("set_id") != "recipe_fake_error_regular":
                continue
            if item.get("ok") is True:
                continue
            text = json.dumps(item, sort_keys=True).lower()
            if self._looks_like_outage(text, item.get("status")):
                continue
            if item.get("business_status") == "error":
                return True
            if item.get("status") in {400, 409, 422}:
                return True
            return any(
                marker in text
                for marker in ("ok=false", "json", "validation", "business", "functional")
            )
        return False

    def _has_overlap_guard_evidence(
        self, response: dict[str, Any], detail: dict[str, Any]
    ) -> bool:
        if response.get("status") == "running":
            return bool(response.get("run_id"))
        text = json.dumps(list(self._iter_run_set_evidence(detail)), sort_keys=True).lower()
        return "locked:" in text or "held by run" in text or "overlapping live set rejected" in text

    def _has_outage_evidence(self, detail: dict[str, Any]) -> bool:
        for item in self._iter_run_set_evidence(detail):
            text = json.dumps(item, sort_keys=True).lower()
            if self._looks_like_outage(text, item.get("status")):
                return True
        return False

    def _iter_run_set_evidence(self, detail: dict[str, Any]) -> Iterable[dict[str, Any]]:
        last_json = detail.get("last_json")
        last_sets = last_json.get("sets") if isinstance(last_json, dict) else None
        if isinstance(last_sets, list):
            for item in last_sets:
                if isinstance(item, dict):
                    yield item
        sets = detail.get("sets")
        if isinstance(sets, list):
            for item in sets:
                if isinstance(item, dict):
                    yield item

    def _looks_like_outage(self, text: str, status: Any) -> bool:
        if isinstance(status, int) and status >= 502:
            return True
        return any(
            marker in text
            for marker in ("timeout", "unavailable", "connection", "mtf run failed", "transport")
        )

    def _is_expected_live_guard_refusal(self, status: int, body: Any) -> bool:
        if status != 422:
            return False
        text = (
            json.dumps(body, sort_keys=True).lower()
            if isinstance(body, (dict, list))
            else str(body).lower()
        )
        return any(
            marker in text
            for marker in ("live", "dry_run", "guard", "forbidden", "interdit", "non autor")
        )

    def _cleanup_dashboards(self, dashboards: dict[str, dict[str, Any]]) -> None:
        for dashboard in dashboards.values():
            self.http.request(
                "PATCH",
                f"/dashboards/{dashboard['id']}",
                json_body={"enabled": False},
                timeout=self.config.timeout_seconds,
            )

    def _result(
        self, scenario: str, status: str, notes: str, evidence: Any | None = None
    ) -> dict[str, Any]:
        return {
            "scenario": scenario,
            "status": status,
            "notes": notes,
            "evidence": self._redact(evidence or {}),
        }

    def _export(self, report: dict[str, Any]) -> None:
        self.config.export_dir.mkdir(parents=True, exist_ok=True)
        (self.config.export_dir / "runtime-recipe-report.json").write_text(
            json.dumps(report, indent=2, sort_keys=True) + "\n",
            encoding="utf-8",
        )
        r12_result = next(
            (
                result
                for result in report.get("results", [])
                if result.get("scenario") == "R12"
            ),
            None,
        )
        recipe_report = (
            r12_result.get("evidence", {}).get("recipe_report")
            if isinstance(r12_result, dict)
            else None
        )
        standalone_paths = (
            self.config.export_dir / "fake-multi-profile-recipe-report.json",
            self.config.export_dir / "fake-multi-profile-recipe-report.md",
        )
        if isinstance(recipe_report, dict):
            standalone_paths[0].write_text(
                json.dumps(recipe_report, indent=2, sort_keys=True) + "\n",
                encoding="utf-8",
            )
            standalone_paths[1].write_text(
                self._multi_profile_markdown(recipe_report),
                encoding="utf-8",
            )
        elif r12_result is not None:
            for path in standalone_paths:
                path.unlink(missing_ok=True)

    @staticmethod
    def _multi_profile_markdown(report: dict[str, Any]) -> str:
        exchange_calls = report["exchange_calls"]
        exchange_call_proof = report["exchange_call_proof"]
        business_lock = report["locks"]["business"]
        business_observed = str(business_lock["observed"]).lower()
        lines = [
            "# Fake multi-profile recipe",
            "",
            f"- Status: `{report['status']}`",
            f"- Fixture: `{report['fixture_id']}`",
            f"- Fixture hash: `{report['fixture_hash']}`",
            f"- Scenario: `{report['scenario']}`",
            "- Exchange calls: "
            f"`bitmart={exchange_calls['bitmart']}`, "
            f"`hyperliquid={exchange_calls['hyperliquid']}`, "
            f"`okx={exchange_calls['okx']}`",
            "- Proof methods: "
            f"`bitmart={exchange_call_proof['bitmart']}`, "
            f"`hyperliquid={exchange_call_proof['hyperliquid']}`, "
            f"`okx={exchange_call_proof['okx']}`",
            "",
            "| Set | Profile | Symbol | Config hash | Orders |",
            "| --- | --- | --- | --- | ---: |",
        ]
        for item in report["sets"]:
            processed_symbols = item["symbols"]
            if processed_symbols is None:
                rendered_symbols = "UNAVAILABLE"
            elif processed_symbols:
                rendered_symbols = ",".join(processed_symbols)
            else:
                rendered_symbols = "NONE"
            lines.append(
                f"| `{item['set_id']}` | `{item['profile']}` | "
                f"`{rendered_symbols}` | `{item['config_hash']}` | "
                f"{item['orders_total']} |"
            )
        lines.extend(
            [
                "",
                "Orchestration locks are profile-scoped and coexist. The business exposure lock ",
                "is symbol-scoped, but this dry-run acquires no business lock.",
                f"Business lock evidence: `{business_lock['evidence_status']}` "
                f"(`observed={business_observed}`).",
                "Business lock contract: "
                f"`{business_lock['contract_conflict_status']}/"
                f"{business_lock['contract_conflict_reason']}`.",
                "",
            ]
        )
        return "\n".join(lines)

    def _redact(self, value: Any) -> Any:
        if isinstance(value, dict):
            return {
                key: ("REDACTED" if SECRET_KEY_RE.search(str(key)) else self._redact(child))
                for key, child in value.items()
            }
        if isinstance(value, list):
            redacted: list[Any] = []
            redact_next = False
            for child in value:
                if redact_next:
                    redacted.append("REDACTED")
                    redact_next = False
                    continue
                redacted_child = self._redact(child)
                redacted.append(redacted_child)
                if (
                    isinstance(child, str)
                    and child.lstrip().startswith("-")
                    and "=" not in child
                    and SECRET_KEY_RE.search(child)
                ):
                    redact_next = True
            return redacted
        if isinstance(value, str) and SECRET_KEY_RE.search(value):
            return "REDACTED"
        return value


def _parse_args(argv: Sequence[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Run the TradingV3 orchestrator runtime recipe in dry-run mode."
    )
    parser.add_argument("--orchestrator-url", default="http://localhost:8099")
    parser.add_argument("--fixtures-dir", type=Path, default=DEFAULT_FIXTURES_DIR)
    parser.add_argument("--export-dir", type=Path, default=DEFAULT_EXPORT_DIR)
    parser.add_argument("--scenario", action="append", choices=ALL_SCENARIOS)
    parser.add_argument(
        "--target-exchange",
        choices=("fake", "okx", "hyperliquid", DEMO_EXCHANGES_TARGET),
        default="fake",
        help=(
            "Recipe target for R1/R2/R14; fake remains the default R1-R16 baseline. "
            "Use demo-exchanges to export global, OKX, and Hyperliquid sections."
        ),
    )
    parser.add_argument("--timeout-seconds", type=float, default=30.0)
    parser.add_argument("--confirm", required=True, help=f"Must be {CONFIRMATION_TOKEN}")
    parser.add_argument("--keep-fixtures", action="store_true")
    parser.add_argument("--cleanup", action="store_true")
    parser.add_argument("--temporal-available", action="store_true")
    parser.add_argument("--temporal-dry-run-command", nargs=argparse.REMAINDER)
    return parser.parse_args(argv)


def main(argv: Sequence[str] | None = None) -> int:
    args = _parse_args(argv)
    config = RunnerConfig(
        export_dir=args.export_dir,
        fixtures_dir=args.fixtures_dir,
        orchestrator_url=args.orchestrator_url,
        confirmation_token=args.confirm,
        target_exchange=args.target_exchange,
        timeout_seconds=args.timeout_seconds,
        temporal_available=args.temporal_available,
        temporal_dry_run_command=args.temporal_dry_run_command,
        cleanup=args.cleanup,
    )
    report = RecipeRunner(config).run(
        scenarios=tuple(args.scenario) if args.scenario else None,
        keep_fixtures=args.keep_fixtures,
    )
    print(json.dumps(report["summary"], indent=2, sort_keys=True))
    return 1 if report["summary"]["scenario_counts"].get("FAIL", 0) else 0


if __name__ == "__main__":
    raise SystemExit(main())
