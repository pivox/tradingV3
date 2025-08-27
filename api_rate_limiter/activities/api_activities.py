# activities/api_activities.py
from temporalio import activity
import httpx
from typing import Dict, Any

@activity.defn(name="post_callback")
async def post_callback(envelope: Dict[str, Any]) -> Dict[str, Any]:
    url_cb = envelope.get("url_callback")
    base = envelope.get("base_url", "")
    method = envelope.get("method", "POST").upper()
    if not url_cb:
        return {"status": "error", "message": "url_callback manquant"}

    full_url = url_cb if url_cb.startswith("http") else f"{base.rstrip('/')}/{url_cb.lstrip('/')}"

    try:
        async with httpx.AsyncClient() as client:
            if method == "GET":
                resp = await client.get(full_url, params=envelope, timeout=10)
            else:
                resp = await client.post(full_url, json=envelope, timeout=10)
        return {
            "status": "ok" if resp.is_success else "error",
            "code": resp.status_code,
            "body": resp.text,
            "callback_url": full_url,
        }
    except Exception as e:
        return {"status": "error", "message": str(e), "callback_url": full_url}
