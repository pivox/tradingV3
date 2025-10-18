# worker.py
import os
import asyncio
from temporalio.client import Client
from temporalio.worker import Worker
from workflows.api_rate_limiter_workflow import ApiRateLimiterClient
from activities.api_activities import post_callback

TEMPORAL_ADDRESS = os.getenv("TEMPORAL_ADDRESS", "temporal-grpc:7233")
TASK_QUEUE = os.getenv("TASK_QUEUE_NAME", "api_rate_limiter_queue")

async def connect_with_retry(address: str, max_attempts: int = 30, base_delay: float = 1.0) -> Client:
    last_exc = None
    for attempt in range(1, max_attempts + 1):
        try:
            print(f"[worker] Connecting to Temporal at {address} (attempt {attempt}/{max_attempts})...")
            client = await Client.connect(address)
            print("[worker] Connected to Temporal.")
            return client
        except Exception as e:
            last_exc = e
            delay = min(10.0, base_delay * (2 ** (attempt - 1)))
            print(f"[worker] Connection failed: {e}. Retrying in {delay:.1f}s...")
            await asyncio.sleep(delay)
    raise last_exc

async def main():
    client = await connect_with_retry(TEMPORAL_ADDRESS)
    worker = Worker(
        client,
        task_queue=TASK_QUEUE,
        workflows=[ApiRateLimiterClient],
        activities=[post_callback],
    )
    await worker.run()

if __name__ == "__main__":
    asyncio.run(main())
