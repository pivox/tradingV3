import redis
import json
import httpx
from temporalio import activity

REDIS_HOST = 'redis'
REDIS_PORT = 6379

@activity.defn
async def check_redis_for_task(queue_name: str, timeout: int = 5) -> dict | None:
    """
    Lit une tâche JSON depuis Redis avec BLPOP.
    """
    r = redis.Redis(host=REDIS_HOST, port=REDIS_PORT, decode_responses=True)
    task = r.blpop(queue_name, timeout=timeout)
    if task:
        return json.loads(task[1])
    return None

@activity.defn
async def call_api(task: dict) -> dict:
    """
    Exécute un appel HTTP dynamique selon le JSON de la tâche.
    """
    url = task.get('url')
    method = task.get('method', 'GET').upper()
    payload = task.get('payload', {})

    async with httpx.AsyncClient() as client:
        if method == 'GET':
            response = await client.get(url, params=payload)
        elif method == 'POST':
            response = await client.post(url, json=payload)
        elif method == 'PUT':
            response = await client.put(url, json=payload)
        else:
            raise ValueError(f"HTTP method {method} not supported.")

        response.raise_for_status()
        return response.json()

@activity.defn
async def post_result_to_symfony(result: dict, symfony_base_url: str, response_target: str):
    """
    Envoie le résultat obtenu vers un endpoint Symfony.
    """
    async with httpx.AsyncClient() as client:
        await client.post(f"{symfony_base_url}{response_target}", json=result)
