import os
import asyncio
from temporalio.client import Client
from temporalio.worker import Worker
from workflows.log_processing_workflow import LogProcessingWorkflow
from activities.log_activities import write_log_to_file

TEMPORAL_ADDRESS = os.getenv("TEMPORAL_ADDRESS", "temporal-grpc:7233")
TASK_QUEUE = os.getenv("TASK_QUEUE_NAME", "log-processing-queue")

async def main():
    print(f"🚀 Starting Log Processing Worker...", flush=True)
    print(f"   Temporal Address: {TEMPORAL_ADDRESS}", flush=True)
    print(f"   Task Queue: {TASK_QUEUE}", flush=True)
    
    try:
        client = await Client.connect(TEMPORAL_ADDRESS)
        print(f"✅ Connected to Temporal", flush=True)
        
        worker = Worker(
            client,
            task_queue=TASK_QUEUE,
            workflows=[LogProcessingWorkflow],
            activities=[write_log_to_file],
        )
        
        print(f"✅ Worker created, starting to poll task queue: {TASK_QUEUE}", flush=True)
        await worker.run()
    except Exception as e:
        print(f"❌ Error: {e}", flush=True)
        raise

if __name__ == "__main__":
    asyncio.run(main())
