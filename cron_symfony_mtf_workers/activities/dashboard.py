"""Temporal activities for the MTF dashboard orchestrator (one activity per concern).

- ``load_dashboard_snapshot`` : reads a fresh dashboard snapshot from the config source at run start.
- ``runtime_check_target``    : per-target dry-run-only guardrail (no Docker / no live check).
- ``call_mtf_run_target``     : posts ONE target to Symfony /api/mtf/run with an applicative
                                success contract and correct transient-vs-deterministic retry.

The decisions live in the pure helpers (``dashboards.runtime``); these activities only do I/O.
PR13 dashboard path is dry-run-only for every exchange (live stays on the legacy direct schedule).
"""
from __future__ import annotations

import json
import os
from typing import Any, Dict, Optional

import httpx
from temporalio import activity
from temporalio.exceptions import ApplicationError

from dashboards.model import load_dashboards_file
from dashboards.runtime import (
    DEFAULT_SYMFONY_URL,
    decide_runtime_check,
    is_mtf_run_success,
    is_transient_http,
    to_symfony_body,
)
from utils.response_formatter import format_mtf_response

DEFAULT_DASHBOARDS_PATH = os.getenv("DASHBOARDS_PATH", "dashboards/dashboards.example.yaml")
TARGET_TIMEOUT_SECONDS = float(os.getenv("MTF_TARGET_TIMEOUT", "900"))  # 15 min per target


def _safe_json(text: str) -> Any:
    try:
        return json.loads(text)
    except (json.JSONDecodeError, TypeError):
        return text


@activity.defn(name="load_dashboard_snapshot")
async def load_dashboard_snapshot(
    dashboard_id: str, dashboards_path: Optional[str] = None
) -> Dict[str, Any]:
    """Load a fresh snapshot of the dashboard at the start of the run (no stale matrix)."""
    path = dashboards_path or DEFAULT_DASHBOARDS_PATH
    try:
        dashboards = load_dashboards_file(path)  # validates dry-run-only (fail-closed)
    except (ValueError, RuntimeError, OSError) as exc:
        # Deterministic config error (bad YAML, policy/live violation, missing file): do not retry.
        raise ApplicationError(
            f"invalid dashboard config at {path}: {exc}", non_retryable=True
        ) from exc

    dashboard = dashboards.get(dashboard_id)
    if dashboard is None:
        raise ApplicationError(
            f"unknown dashboard '{dashboard_id}' in {path}",
            {"available": sorted(dashboards)},
            non_retryable=True,
        )
    return dashboard.to_snapshot()


@activity.defn(name="runtime_check_target")
async def runtime_check_target(target: Dict[str, Any]) -> Dict[str, Any]:
    """Per-target dry-run-only guardrail (defense in depth). No Docker, no live check."""
    try:
        return decide_runtime_check(target)
    except RuntimeError as exc:
        raise ApplicationError(str(exc), non_retryable=True) from exc


@activity.defn(name="call_mtf_run_target")
async def call_mtf_run_target(target: Dict[str, Any], idempotency_key: str) -> Dict[str, Any]:
    """Post ONE target to Symfony /api/mtf/run; success requires the applicative contract."""
    url = os.getenv("MTF_WORKERS_URL", DEFAULT_SYMFONY_URL)
    body = to_symfony_body(target, idempotency_key)

    try:
        async with httpx.AsyncClient(timeout=TARGET_TIMEOUT_SECONDS) as client:
            response = await client.post(url, json=body)
        raw_response = {
            "ok": response.is_success,
            "status": response.status_code,
            "body": _safe_json(response.text),
            "url": url,
            "payload": body,
        }
    except Exception as exc:  # noqa: BLE001 - transport failure is transient -> retryable
        raise ApplicationError(
            f"transport failure for target '{target.get('target_id')}': {exc}"
        ) from exc

    if is_mtf_run_success(raw_response):
        formatted = format_mtf_response(raw_response)
        return {"ok": True, "status": raw_response["status"], "summary": formatted.get("summary")}

    # Failure: keep per-target retry for transient HTTP (5xx/429/timeout), but mark deterministic
    # applicative / 4xx failures non-retryable so the workflow surfaces them without burning retries.
    formatted = format_mtf_response(raw_response)
    transient = is_transient_http(raw_response["status"])
    raise ApplicationError(
        f"target '{target.get('target_id')}' MTF run not successful",
        {"status": raw_response["status"], "summary": formatted.get("summary"), "error": formatted.get("error")},
        non_retryable=not transient,
    )
