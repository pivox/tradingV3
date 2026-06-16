"""Temporal activity that calls the Flask dashboard bridge.

The workflow sends a thin payload (``dashboard_id``, ``run_id``, ``schedule_id``,
``dry_run``, ``tick_timestamp``) to the bridge, which owns the matrix expansion and the
sequential Symfony calls. The activity reports success to Temporal **only** when the
bridge aggregate is ok; otherwise it raises so the failure is visible in Temporal.
"""
from __future__ import annotations

from typing import Any, Dict, Optional

import httpx
from temporalio import activity
from temporalio.exceptions import ApplicationError

ACTIVITY_TIMEOUT_SECONDS = 900  # 15 minutes, matches the legacy mtf_api_call activity


@activity.defn(name="bridge_dashboard_call")
async def bridge_dashboard_call(
    bridge_url: str, payload: Optional[Dict[str, Any]] = None
) -> Dict[str, Any]:
    data = payload or {}
    dashboard_id = data.get("dashboard_id")

    try:
        async with httpx.AsyncClient(timeout=ACTIVITY_TIMEOUT_SECONDS) as client:
            response = await client.post(bridge_url, json=data)
        try:
            body = response.json()
        except Exception:  # noqa: BLE001 - non-JSON bridge response
            body = {"ok": False, "status": response.status_code, "error": response.text}
    except Exception as exc:  # noqa: BLE001 - transport failure reaching the bridge
        raise ApplicationError(f"bridge call failed for dashboard '{dashboard_id}': {exc}") from exc

    if not body.get("ok"):
        # "OK only if every Symfony call of the dashboard is OK": surface the aggregate.
        raise ApplicationError(
            f"dashboard '{dashboard_id}' reported failing targets",
            body,
        )

    return body
