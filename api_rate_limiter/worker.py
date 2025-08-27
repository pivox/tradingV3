# worker.py
import os
import asyncio
from temporalio.client import Client
from temporalio.worker import Worker
from workflows.api_rate_limiter_workflow import ApiRateLimiterClient
from activities.api_activities import post_callback

TEMPORAL_ADDRESS = os.getenv("TEMPORAL_ADDRESS", "temporal:7233")
TASK_QUEUE = os.getenv("TASK_QUEUE_NAME", "api_rate_limiter_queue")

async def main():
    client = await Client.connect(TEMPORAL_ADDRESS)
    worker = Worker(
        client,
        task_queue=TASK_QUEUE,
        workflows=[ApiRateLimiterClient],
        activities=[post_callback],
    )
    await worker.run()

if __name__ == "__main__":
    asyncio.run(main())
