from temporalio import activity
import httpx

@activity.defn(name="call_symfony_endpoint")
async def call_symfony_endpoint(url: str) -> dict:
    try:
        # Timeout de 5 minutes pour l'endpoint MTF qui peut prendre du temps
        timeout = 300 if "mtf" in url.lower() else 30
        async with httpx.AsyncClient(timeout=timeout) as client:
            resp = await client.post(url, json={})
        return {"ok": resp.is_success, "status": resp.status_code, "body": resp.text, "url": url}
    except Exception as e:
        return {"ok": False, "status": None, "body": str(e), "url": url}
