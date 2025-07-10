import asyncio
from temporalio.client import Client

async def main():
    client = await Client.connect("temporal:7233")
    await client.start_workflow(
        "ApiRateLimiterWorkflow",
        id="api-rate-limiter",
        task_queue="default-queue"
    )

asyncio.run(main())
