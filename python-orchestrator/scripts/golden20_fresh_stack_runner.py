"""Executable golden proof for Fake/Paper scenario 20.

The proof starts two independent loopback HTTP stacks. Each stack contains the
real Symfony test Kernel, the real FastAPI application, and a fresh file-backed
SQLite orchestration database. It then executes the existing R12 recipe and
compares the normalized reports byte for byte.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import shutil
import socket
import subprocess
import sys
import tempfile
import time
from contextlib import contextmanager
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterator

import httpx

try:
    from scripts.runtime_recipe_runner import HttpxRecipeHttpClient, RecipeRunner, RunnerConfig
except ModuleNotFoundError:  # Direct `python scripts/...` execution.
    from runtime_recipe_runner import HttpxRecipeHttpClient, RecipeRunner, RunnerConfig


ORCHESTRATOR_ROOT = Path(__file__).resolve().parents[1]
REPOSITORY_ROOT = ORCHESTRATOR_ROOT.parent
TRADING_APP_ROOT = REPOSITORY_ROOT / "trading-app"
REPORT_NAME = "fake-multi-profile-recipe-report.json"
STARTUP_TIMEOUT_SECONDS = 15.0
PROXY_ENVIRONMENT_KEYS = (
    "HTTP_PROXY",
    "HTTPS_PROXY",
    "ALL_PROXY",
    "http_proxy",
    "https_proxy",
    "all_proxy",
)


@dataclass(frozen=True)
class FreshStackResult:
    report_bytes: bytes
    report: dict[str, Any]
    database_created_from_empty_path: bool
    stack_identity: str
    orchestrator_pid: int
    symfony_pid: int


def _available_loopback_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as listener:
        listener.bind(("127.0.0.1", 0))
        return int(listener.getsockname()[1])


def _wait_for_http(
    url: str,
    *,
    headers: dict[str, str] | None = None,
    expected_json_key: str | None = None,
) -> None:
    deadline = time.monotonic() + STARTUP_TIMEOUT_SECONDS
    last_error = "no response"
    with httpx.Client(timeout=1.0, trust_env=False) as client:
        while time.monotonic() < deadline:
            try:
                response = client.get(url, headers=headers)
                if response.status_code == 200:
                    if expected_json_key is None:
                        return
                    body = response.json()
                    if isinstance(body, dict) and expected_json_key in body:
                        return
                    last_error = f"HTTP 200 without {expected_json_key}"
                elif response.status_code >= 500:
                    raise RuntimeError(f"fresh stack returned HTTP {response.status_code} at {url}")
                else:
                    last_error = f"HTTP {response.status_code}"
            except (httpx.HTTPError, ValueError) as exc:
                last_error = f"{exc.__class__.__name__}: {exc}"
            time.sleep(0.05)
    raise RuntimeError(f"fresh stack did not become ready at {url}: {last_error}")


def _terminate(process: subprocess.Popen[bytes]) -> None:
    if process.poll() is not None:
        return
    process.terminate()
    try:
        process.wait(timeout=5.0)
    except subprocess.TimeoutExpired:
        process.kill()
        process.wait(timeout=5.0)


def _redacted_log_tail(path: Path, temporary_root: Path) -> str:
    try:
        content = path.read_text(encoding="utf-8", errors="replace")
    except OSError as exc:
        return f"log_unavailable:{exc.__class__.__name__}"
    return content[-4000:].replace(str(temporary_root), "<temporary>")


def _proxy_free_environment() -> dict[str, str]:
    environment = os.environ.copy()
    for key in PROXY_ENVIRONMENT_KEYS:
        environment.pop(key, None)
    environment["NO_PROXY"] = "127.0.0.1,localhost"
    environment["no_proxy"] = "127.0.0.1,localhost"
    return environment


def _initialize_orchestrator_database(environment: dict[str, str]) -> None:
    completed = subprocess.run(
        [
            sys.executable,
            "-c",
            (
                "from app.db.base import Base; import app.db.models; "
                "from app.db.engine import get_engine; "
                "Base.metadata.create_all(get_engine())"
            ),
        ],
        cwd=ORCHESTRATOR_ROOT,
        env=environment,
        capture_output=True,
        check=False,
        timeout=15.0,
    )
    if completed.returncode != 0:
        stderr = completed.stderr.decode("utf-8", errors="replace")[-2000:]
        raise RuntimeError(f"fresh orchestration schema initialization failed: {stderr}")


@contextmanager
def _fresh_stack() -> Iterator[tuple[str, Path, bool, str, int, int]]:
    php_binary = shutil.which("php")
    if php_binary is None:
        raise RuntimeError("php executable is required for golden scenario 20")

    with tempfile.TemporaryDirectory(prefix="tradingv3-golden20-") as temporary_root_raw:
        temporary_root = Path(temporary_root_raw)
        database_path = temporary_root / "orchestrator.sqlite"
        export_dir = temporary_root / "export"
        fake_state_root = temporary_root / "paper-fake-state"
        symfony_log_path = temporary_root / "symfony.log"
        orchestrator_log_path = temporary_root / "orchestrator.log"
        database_was_absent = not database_path.exists()

        symfony_port = _available_loopback_port()
        orchestrator_port = _available_loopback_port()
        while orchestrator_port == symfony_port:
            orchestrator_port = _available_loopback_port()

        symfony_url = f"http://127.0.0.1:{symfony_port}"
        orchestrator_url = f"http://127.0.0.1:{orchestrator_port}"

        symfony_environment = _proxy_free_environment()
        symfony_environment.update(
            {
                "APP_ENV": "test",
                "APP_DEBUG": "1",
                "APP_SECRET": "golden20-local-fake-only",
                "DEFAULT_URI": symfony_url,
                "LOCK_DSN": "flock",
                "PAPER_FAKE_STATE_ROOT": str(fake_state_root),
            }
        )
        orchestrator_environment = _proxy_free_environment()
        orchestrator_environment.update(
            {
                "DATABASE_URL": f"sqlite:///{database_path}",
                "MAX_CONCURRENCY": "2",
                "ORCHESTRATION_DB_SCHEMA": "main",
                "ORCHESTRATION_LIVE_ENABLED": "false",
                "ORCHESTRATOR_PORT": str(orchestrator_port),
                "SYMFONY_BASE_URL": symfony_url,
            }
        )
        _initialize_orchestrator_database(orchestrator_environment)

        with symfony_log_path.open("wb") as symfony_log, orchestrator_log_path.open(
            "wb"
        ) as orchestrator_log:
            symfony_process = subprocess.Popen(
                [
                    php_binary,
                    "-d",
                    "variables_order=EGPCS",
                    "-S",
                    f"127.0.0.1:{symfony_port}",
                    "-t",
                    "public",
                    "public/index.php",
                ],
                cwd=TRADING_APP_ROOT,
                env=symfony_environment,
                stdout=symfony_log,
                stderr=subprocess.STDOUT,
            )
            orchestrator_process: subprocess.Popen[bytes] | None = None
            try:
                try:
                    _wait_for_http(
                        (
                            f"{symfony_url}/api/exchange/open-state"
                            "?exchange=fake&market_type=perpetual&dry_run=true"
                        ),
                        headers={"X-Fake-Only-Safety-Evidence": "v2"},
                        expected_json_key="fake_only_safety_evidence",
                    )
                except RuntimeError as exc:
                    symfony_log.flush()
                    raise RuntimeError(
                        f"{exc}; symfony_log_tail="
                        f"{_redacted_log_tail(symfony_log_path, temporary_root)}"
                    ) from exc
                orchestrator_process = subprocess.Popen(
                    [
                        sys.executable,
                        "-m",
                        "uvicorn",
                        "app.main:app",
                        "--host",
                        "127.0.0.1",
                        "--port",
                        str(orchestrator_port),
                        "--log-level",
                        "warning",
                    ],
                    cwd=ORCHESTRATOR_ROOT,
                    env=orchestrator_environment,
                    stdout=orchestrator_log,
                    stderr=subprocess.STDOUT,
                )
                try:
                    _wait_for_http(f"{orchestrator_url}/healthcheck")
                except RuntimeError as exc:
                    orchestrator_log.flush()
                    raise RuntimeError(
                        f"{exc}; orchestrator_log_tail="
                        f"{_redacted_log_tail(orchestrator_log_path, temporary_root)}"
                    ) from exc

                yield (
                    orchestrator_url,
                    export_dir,
                    database_was_absent and database_path.is_file(),
                    str(database_path),
                    int(orchestrator_process.pid),
                    int(symfony_process.pid),
                )
            finally:
                if orchestrator_process is not None:
                    _terminate(orchestrator_process)
                _terminate(symfony_process)


def _run_one_fresh_stack() -> FreshStackResult:
    with _fresh_stack() as (
        orchestrator_url,
        export_dir,
        database_created_from_empty_path,
        stack_identity,
        orchestrator_pid,
        symfony_pid,
    ):
        runner = RecipeRunner(
            RunnerConfig(
                export_dir=export_dir,
                orchestrator_url=orchestrator_url,
                confirmation_token="DRY_RUN_ONLY",
                timeout_seconds=15.0,
            ),
            http_client=HttpxRecipeHttpClient(orchestrator_url, trust_env=False),
        )
        runtime_report = runner.run(scenarios=("R12",), keep_fixtures=True)
        results = runtime_report.get("results")
        if not isinstance(results, list) or len(results) != 1:
            raise RuntimeError("fresh R12 runtime report has an invalid result list")
        if results[0].get("status") != "PASS":
            raise RuntimeError(f"fresh R12 runtime did not pass: {results[0]!r}")

        report_path = export_dir / REPORT_NAME
        report_bytes = report_path.read_bytes()
        report = json.loads(report_bytes)
        if not isinstance(report, dict) or report.get("status") != "PASS":
            raise RuntimeError("fresh R12 normalized report is not PASS")

        return FreshStackResult(
            report_bytes=report_bytes,
            report=report,
            database_created_from_empty_path=database_created_from_empty_path,
            stack_identity=stack_identity,
            orchestrator_pid=orchestrator_pid,
            symfony_pid=symfony_pid,
        )


def run_fresh_stacks() -> dict[str, Any]:
    first = _run_one_fresh_stack()
    second = _run_one_fresh_stack()
    reports_identical = first.report_bytes == second.report_bytes
    process_ids = (
        first.orchestrator_pid,
        first.symfony_pid,
        second.orchestrator_pid,
        second.symfony_pid,
    )
    sets = first.report.get("sets")
    if not isinstance(sets, list):
        raise RuntimeError("fresh R12 normalized report has no set evidence")

    config_hashes = [item.get("config_hash") for item in sets if isinstance(item, dict)]
    profiles = [item.get("profile") for item in sets if isinstance(item, dict)]
    symbols = [item.get("symbols") for item in sets if isinstance(item, dict)]
    order_totals = [item.get("orders_total") for item in sets if isinstance(item, dict)]
    replay = first.report.get("replay")
    replay = replay if isinstance(replay, dict) else {}
    proof: dict[str, Any] = {
        "config_hashes_unique": len(config_hashes) == 3 and len(set(config_hashes)) == 3,
        "disabled_sets": first.report.get("disabled_sets"),
        "exchange_calls": first.report.get("exchange_calls"),
        "fresh_database_count": sum(
            int(item.database_created_from_empty_path) for item in (first, second)
        ),
        "fresh_process_count": 4 if all(process_id > 0 for process_id in process_ids) else 0,
        "loopback_http_stacks": 2,
        "orders_total": sum(value for value in order_totals if isinstance(value, int)),
        "profiles": profiles,
        "replay_same_run_id": replay.get("same_run_id") is True,
        "report_digest": "sha256:" + hashlib.sha256(first.report_bytes).hexdigest(),
        "reports_identical": reports_identical,
        "schema_version": "fake-paper-golden20-fresh-stacks-v1",
        "stack_count": 2,
        "status": "pass",
        "symbols": symbols,
        "transport": "loopback_tcp_http",
    }
    expected_invariants = {
        "config_hashes_unique": True,
        "exchange_calls": {"bitmart": 0, "hyperliquid": 0, "okx": 0},
        "fresh_database_count": 2,
        "fresh_process_count": 4,
        "orders_total": 0,
        "profiles": ["regular", "scalper", "scalper_micro"],
        "replay_same_run_id": True,
        "reports_identical": True,
        "symbols": [["BTCUSDT"], ["BTCUSDT"], ["BTCUSDT"]],
    }
    if first.stack_identity == second.stack_identity:
        raise RuntimeError("fresh stacks reused the same persistence identity")
    if any(proof.get(key) != value for key, value in expected_invariants.items()):
        raise RuntimeError(f"fresh-stack invariants failed: {proof!r}")

    return proof


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.parse_args()
    result = run_fresh_stacks()
    sys.stdout.write(json.dumps(result, ensure_ascii=False, separators=(",", ":"), sort_keys=True))
    sys.stdout.write("\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
