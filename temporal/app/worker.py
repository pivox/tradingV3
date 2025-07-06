import asyncio
import logging

from temporalio.client import Client
from temporalio.worker import Worker

# Import des workflows et activités
from workflows.kline_sync_all_workflow import KlineSyncAllWorkflow
from activities.fetch_contracts_klines import fetch_contracts_activity, fetch_klines_activity, save_klines_activity

# Configure le logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("temporal-worker")

TEMPORAL_SERVER = "temporal-trading:7233"
TASK_QUEUE = "kline-task-queue"

async def main():
    logger.info("⏳ Connexion à Temporal...")

    # Création du client Temporal
    client = await Client.connect(TEMPORAL_SERVER)
    logger.info("✅ Connecté à Temporal")

    # Création du worker avec les workflows et activités enregistrés
    worker = Worker(
        client=client,
        task_queue=TASK_QUEUE,
        workflows=[KlineSyncAllWorkflow],
        activities=[
            fetch_contracts_activity,
            fetch_klines_activity,
            save_klines_activity
        ],
    )

    logger.info("🚀 Worker démarré sur la task queue '%s'", TASK_QUEUE)

    # Lancement du worker
    await worker.run()

if __name__ == "__main__":
    asyncio.run(main())
