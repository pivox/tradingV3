from __future__ import annotations

import asyncio
import os
import time

from temporalio.client import Client
from temporalio.worker import Worker

from activities.mtf_http import mtf_api_call
from activities.orchestrator_http import orchestrator_run
from workflows.mtf_workers import CronSymfonyMtfWorkersWorkflow
from workflows.orchestrator_cron import OrchestratorCronWorkflow


TEMPORAL_ADDRESS = os.getenv("TEMPORAL_ADDRESS", "temporal:7233")
TASK_QUEUE = os.getenv("TASK_QUEUE_NAME", "cron_symfony_mtf_workers")


async def connect_with_retry(address: str, max_attempts: int = 30, base_delay: float = 1.0) -> Client:
    last_exc = None
    for attempt in range(1, max_attempts + 1):
        try:
            print(f"[worker] Connecting to Temporal at {address} (attempt {attempt}/{max_attempts})...")
            client = await Client.connect(address)
            print("[worker] Connected to Temporal.")
            return client
        except Exception as e:
            last_exc = e
            # backoff exponentiel plafonné
            delay = min(10.0, base_delay * (2 ** (attempt - 1)))
            print(f"[worker] Connection failed: {e}. Retrying in {delay:.1f}s...")
            await asyncio.sleep(delay)
    # si on est ici, toutes les tentatives ont échoué
    raise last_exc


async def main() -> None:
    client = await connect_with_retry(TEMPORAL_ADDRESS)
    worker = Worker(
        client,
        task_queue=TASK_QUEUE,
        # Legacy (transition, CLEAN-001) + cron cible orchestrateur (TM-001),
        # enregistrés côte à côte sur la même task queue.
        workflows=[CronSymfonyMtfWorkersWorkflow, OrchestratorCronWorkflow],
        activities=[mtf_api_call, orchestrator_run],
    )
    await worker.run()


if __name__ == "__main__":
    asyncio.run(main())
