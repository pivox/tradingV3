from __future__ import annotations

from typing import Any, Dict

import httpx
from temporalio import activity


@activity.defn(name="mtf_api_call")
async def mtf_api_call(url: str, payload: Dict[str, Any] | None = None) -> Dict[str, Any]:
    """Call the Symfony MTF endpoint with a JSON payload."""
    timeout = 300
    data = payload or {}
    try:
        async with httpx.AsyncClient(timeout=timeout) as client:
            response = await client.post(url, json=data)
        return {
            "ok": response.is_success,
            "status": response.status_code,
            "body": response.text,
            "url": url,
            "payload": data,
        }
    except Exception as exc:  # noqa: BLE001 - propagate payload for troubleshooting
        return {
            "ok": False,
            "status": None,
            "body": str(exc),
            "url": url,
            "payload": data,
        }
