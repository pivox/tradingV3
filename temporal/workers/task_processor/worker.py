import asyncio
from temporalio.worker import Worker
from temporalio.client import Client

from task_processor.workflows import GenericTaskProcessorWorkflow
from task_processor.activities import (
    check_redis_for_task,
    call_api,
    post_result_to_symfony
)

async def main():
    client = await Client.connect("localhost:7233")

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

if __name__ == "__main__":
    asyncio.run(main())
