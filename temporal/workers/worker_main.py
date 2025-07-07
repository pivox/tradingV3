import asyncio
from task_processor.worker import start_worker_task_processor

if __name__ == "__main__":
    asyncio.run(asyncio.sleep(5))
    asyncio.run(start_worker_task_processor())
