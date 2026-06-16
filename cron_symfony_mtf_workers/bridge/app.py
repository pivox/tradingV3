"""Flask bridge between Temporal and Symfony.

Exposes ``POST /bridge/run`` consumed by the Temporal ``bridge_dashboard_call`` activity.
The route loads a dashboard by id, runs its targets sequentially against Symfony and
returns the aggregate (HTTP 200 only when every target is OK, otherwise 502).

The pure orchestration lives in :mod:`bridge.runner`; this module only wires the real
httpx caller and the dashboard registry. ``create_app`` accepts injected ``dashboards``
and ``caller`` so the HTTP layer is testable with the Flask test client and no network.
"""
from __future__ import annotations

import json
import os
from datetime import datetime, timezone
from typing import Any, Callable, Dict, Optional

import httpx
from flask import Flask, jsonify, request

from bridge.dashboard import Dashboard, load_dashboards_file
from bridge.runner import TargetCaller, run_dashboard
from utils.response_formatter import format_mtf_response

DEFAULT_DASHBOARDS_PATH = os.getenv("BRIDGE_DASHBOARDS_PATH", "bridge/dashboards.example.yaml")
SYMFONY_TIMEOUT_SECONDS = float(os.getenv("BRIDGE_SYMFONY_TIMEOUT", "900"))
BRIDGE_PORT = int(os.getenv("BRIDGE_PORT", "8090"))


def _utc_now_iso() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat()


def _safe_json(text: str) -> Any:
    try:
        return json.loads(text)
    except (json.JSONDecodeError, TypeError):
        return text


def _real_caller(url: str, payload: Dict[str, Any]) -> Dict[str, Any]:
    """Call Symfony ``/api/mtf/run`` and normalize the response for the aggregator."""
    try:
        response = httpx.post(url, json=payload, timeout=SYMFONY_TIMEOUT_SECONDS)
        raw_response = {
            "ok": response.is_success,
            "status": response.status_code,
            "body": _safe_json(response.text),
            "url": url,
            "payload": payload,
        }
    except Exception as exc:  # noqa: BLE001 - propagate transport failure as a failed target
        raw_response = {
            "ok": False,
            "status": None,
            "body": str(exc),
            "url": url,
            "payload": payload,
        }

    formatted = format_mtf_response(raw_response)
    return {
        "ok": bool(raw_response["ok"]),
        "status": raw_response["status"],
        "summary": formatted.get("summary"),
        "error": formatted.get("error"),
        "full_response": raw_response,
    }


def create_app(
    dashboards: Optional[Dict[str, Dashboard]] = None,
    caller: TargetCaller = _real_caller,
    *,
    dashboards_path: Optional[str] = None,
) -> Flask:
    app = Flask(__name__)
    if dashboards is None:
        dashboards = load_dashboards_file(dashboards_path or DEFAULT_DASHBOARDS_PATH)

    @app.get("/health")
    def health():  # noqa: ANN202 - Flask view
        return jsonify({"ok": True, "dashboards": sorted(dashboards.keys())})

    @app.post("/bridge/run")
    def bridge_run():  # noqa: ANN202 - Flask view
        data = request.get_json(force=True, silent=True) or {}
        dashboard_id = data.get("dashboard_id")
        if not dashboard_id:
            return jsonify({"ok": False, "error": "dashboard_id_required"}), 400

        dashboard = dashboards.get(dashboard_id)
        if dashboard is None:
            return (
                jsonify(
                    {
                        "ok": False,
                        "error": "unknown_dashboard",
                        "dashboard_id": dashboard_id,
                        "available": sorted(dashboards.keys()),
                    }
                ),
                404,
            )

        tick_timestamp = data.get("tick_timestamp") or _utc_now_iso()
        try:
            result = run_dashboard(
                dashboard,
                tick_timestamp,
                caller,
                run_id=data.get("run_id"),
                schedule_id=data.get("schedule_id"),
            )
        except Exception as exc:  # noqa: BLE001 - e.g. dry-run-only policy violation
            return (
                jsonify(
                    {
                        "ok": False,
                        "error": "dashboard_run_failed",
                        "detail": str(exc),
                        "dashboard_id": dashboard_id,
                    }
                ),
                400,
            )

        return jsonify(result), (200 if result["ok"] else 502)

    return app


def main() -> None:
    app = create_app()
    app.run(host="0.0.0.0", port=BRIDGE_PORT)


if __name__ == "__main__":
    main()
