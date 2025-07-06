import asyncio #Parce que le SDK Temporal Python est 100% asynchrone
import sys
import os
sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "../..")))

from temporalio.client import Client

from app.workflows.hello_workflow import HelloWorkflow


async def main():
    host = os.getenv("TEMPORAL_HOST", "localhost:7233")
    client = await Client.connect(host)
    handle = await client.start_workflow(
        HelloWorkflow,
        "Temporal",
        id="hello-workflow-id-2",
        task_queue="hello-task-queue",
    )
    result = await handle.result()
    print(f"Résultat : {result}")

if __name__ == "__main__":
    asyncio.run(main())
