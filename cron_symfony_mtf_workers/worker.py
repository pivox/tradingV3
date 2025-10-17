from __future__ import annotations

import asyncio
import os

from temporalio.client import Client
from temporalio.worker import Worker

from activities.mtf_http import mtf_api_call
from workflows.mtf_workers import CronSymfonyMtfWorkersWorkflow


TEMPORAL_ADDRESS = os.getenv("TEMPORAL_ADDRESS", "temporal:7233")
TASK_QUEUE = os.getenv("TASK_QUEUE_NAME", "cron_symfony_mtf_workers")


async def main() -> None:
    client = await Client.connect(TEMPORAL_ADDRESS)
    worker = Worker(
        client,
        task_queue=TASK_QUEUE,
        workflows=[CronSymfonyMtfWorkersWorkflow],
        activities=[mtf_api_call],
    )
    await worker.run()


if __name__ == "__main__":
    asyncio.run(main())
