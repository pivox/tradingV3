from temporalio import activity, workflow
from temporalio.client import Client
from temporalio.worker import Worker

@activity.defn
async def my_activity(name: str) -> str:
    return f"Hello {name}!"

@workflow.defn
class MyWorkflow:
    @workflow.run
    async def run(self, name: str) -> str:
        result = await workflow.execute_activity(
            my_activity, name, schedule_to_close_timeout=10
        )
        return result

async def main():
    client = await Client.connect("temporal:7233", namespace="default")
    worker = Worker(
        client,
        task_queue="my-task-queue",
        workflows=[MyWorkflow],
        activities=[my_activity],
    )
    await worker.run()

if __name__ == "__main__":
    import asyncio
    asyncio.run(main())
