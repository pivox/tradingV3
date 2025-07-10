import os
import asyncio
from dotenv import load_dotenv

from temporalio.worker import Worker
from temporalio.client import Client

load_dotenv()

from workflows.api_rate_limiter_workflow import ApiRateLimiterWorkflow
from activities.api_activities import call_api

TEMPORAL_ADDRESS = os.getenv("TEMPORAL_ADDRESS", "temporal:7233")
TASK_QUEUE_NAME = os.getenv("TASK_QUEUE_NAME", "default-queue")  # Harmonisé avec ton signal

async def main():
    client = await Client.connect(TEMPORAL_ADDRESS)
    worker = Worker(
        client,
        task_queue=TASK_QUEUE_NAME,
        workflows=[ApiRateLimiterWorkflow],
        activities=[call_api],
    )
    await worker.run()

if __name__ == "__main__":
    asyncio.run(main())
