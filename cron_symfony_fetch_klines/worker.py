# worker.py (dans ton projet cron_symfony_4h)
import os, asyncio
from temporalio.client import Client
from temporalio.worker import Worker
from workflows.cron_symfony_4h import CronSymfony4hWorkflow
from workflows.cron_symfony_1h import CronSymfony1hWorkflow
from workflows.cron_symfony_15m import CronSymfony15mWorkflow
from workflows.cron_symfony_5m import CronSymfony5mWorkflow
from workflows.cron_symfony_1m import CronSymfony1mWorkflow
from workflows.cron_symfony_mtf import CronSymfonyMtfWorkflow
from activities.symfony_http import call_symfony_endpoint  # ici c'est OK (hors sandbox)

TEMPORAL_ADDRESS = os.getenv("TEMPORAL_ADDRESS", "temporal:7233")
TASK_QUEUE = os.getenv("TASK_QUEUE_NAME", "cron_symfony")

async def main():
    client = await Client.connect(TEMPORAL_ADDRESS)
    worker = Worker(
        client,
        task_queue=TASK_QUEUE,
        workflows=[
        CronSymfony4hWorkflow,
        CronSymfony1hWorkflow,
        CronSymfony15mWorkflow,
        CronSymfony5mWorkflow,
        CronSymfony1mWorkflow,
        CronSymfonyMtfWorkflow
        ],
        activities=[call_symfony_endpoint],     # registre l’activité
    )
    await worker.run()

if __name__ == "__main__":
    asyncio.run(main())
