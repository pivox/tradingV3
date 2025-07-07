import os

from temporalio.worker import Worker
from temporalio.client import Client

from task_processor.workflows import GenericTaskProcessorWorkflow
from task_processor.activities import (
    check_redis_for_task,
    call_api,
    post_result_to_symfony
)

async def start_worker_task_processor():
    TEMPORAL_HOST = os.getenv("TEMPORAL_HOST", "temporal:7233")

    client = await Client.connect(TEMPORAL_HOST)

    worker = Worker(
        client,
        task_queue="task-processor-queue",
        workflows=[GenericTaskProcessorWorkflow],
        activities=[
            check_redis_for_task,
            call_api,
            post_result_to_symfony
        ],
    )

    await worker.run()
