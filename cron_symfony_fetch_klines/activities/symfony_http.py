# activities/symfony_http.py
from temporalio import activity
import httpx

@activity.defn(name="call_symfony_endpoint")
async def call_symfony_endpoint(url: str) -> dict:
    async with httpx.AsyncClient(timeout=30) as client:
        resp = await client.post(url, json={})
    return {"ok": resp.is_success, "status": resp.status_code, "body": resp.text}

# (optionnel) alias si tu avais utilisé un autre nom avant
symfony_http_call = call_symfony_endpoint
call_api = call_symfony_endpoint
