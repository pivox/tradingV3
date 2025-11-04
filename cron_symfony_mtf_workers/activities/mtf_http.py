from __future__ import annotations

import json
from typing import Any, Dict

import httpx
from temporalio import activity

from utils.response_formatter import format_mtf_response


@activity.defn(name="mtf_api_call")
async def mtf_api_call(url: str, payload: Dict[str, Any] | None = None) -> Dict[str, Any]:
    """Call the Symfony MTF endpoint with a JSON payload and format the response."""
    timeout = 900  # 15 minutes
    data = payload or {}
    try:
        async with httpx.AsyncClient(timeout=timeout) as client:
            response = await client.post(url, json=data)
        
        # Parse JSON body as object instead of string for cleaner output
        body_text = response.text
        try:
            body_json = json.loads(body_text)
        except json.JSONDecodeError:
            # If parsing fails, keep as string
            body_json = body_text
        
        raw_response = {
            "ok": response.is_success,
            "status": response.status_code,
            "body": body_json,  # Now it's a parsed JSON object, not a string
            "url": url,
            "payload": data,
        }
    except Exception as exc:  # noqa: BLE001 - propagate payload for troubleshooting
        raw_response = {
            "ok": False,
            "status": None,
            "body": str(exc),
            "url": url,
            "payload": data,
        }
    
    # Format the response for concise Temporal UI display
    return format_mtf_response(raw_response)
