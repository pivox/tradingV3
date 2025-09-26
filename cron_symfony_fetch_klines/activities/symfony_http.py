from temporalio import activity
import httpx

@activity.defn(name="call_symfony_endpoint")
async def call_symfony_endpoint(url: str) -> dict:
    try:
        async with httpx.AsyncClient(timeout=30) as client:
            resp = await client.post(url, json={})
        return {"ok": resp.is_success, "status": resp.status_code, "body": resp.text, "url": url}
    except Exception as e:
        return {"ok": False, "status": None, "body": str(e), "url": url}
