"""
Worker Temporal pour le workflow MTF Ping-Pong
"""
import asyncio
import logging
import os
from typing import List

from temporalio.client import Client
from temporalio.worker import Worker

from workflows.mtf_ping_pong_workflow import MtfPingPongWorkflow
from activities.mtf_activities import (
    call_mtf_run_activity,
    wait_for_mtf_completion_activity,
    notify_temporal_activity,
    health_check_activity
)


# Configuration
TEMPORAL_ADDRESS = os.getenv("TEMPORAL_ADDRESS", "temporal:7233")
TEMPORAL_NAMESPACE = os.getenv("TEMPORAL_NAMESPACE", "default")
TASK_QUEUE = os.getenv("TASK_QUEUE_NAME", "mtf-ping-pong-queue")
WORKER_IDENTITY = os.getenv("WORKER_IDENTITY", "mtf-ping-pong-worker")


async def main():
    """Point d'entrée principal du worker"""
    
    # Configuration du logging
    logging.basicConfig(
        level=logging.INFO,
        format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
    )
    logger = logging.getLogger(__name__)
    
    logger.info(f"[MtfPingPongWorker] Démarrage du worker")
    logger.info(f"[MtfPingPongWorker] Temporal Address: {TEMPORAL_ADDRESS}")
    logger.info(f"[MtfPingPongWorker] Namespace: {TEMPORAL_NAMESPACE}")
    logger.info(f"[MtfPingPongWorker] Task Queue: {TASK_QUEUE}")
    logger.info(f"[MtfPingPongWorker] Worker Identity: {WORKER_IDENTITY}")
    
    # Connexion au client Temporal
    try:
        client = await Client.connect(
            TEMPORAL_ADDRESS,
            namespace=TEMPORAL_NAMESPACE
        )
        logger.info(f"[MtfPingPongWorker] Connecté à Temporal")
    except Exception as e:
        logger.error(f"[MtfPingPongWorker] Erreur de connexion à Temporal: {e}")
        raise
    
    # Configuration des workflows et activités
    workflows: List[type] = [MtfPingPongWorkflow]
    activities = [
        call_mtf_run_activity,
        wait_for_mtf_completion_activity,
        notify_temporal_activity,
        health_check_activity
    ]
    
    # Création du worker
    worker = Worker(
        client,
        task_queue=TASK_QUEUE,
        workflows=workflows,
        activities=activities,
        identity=WORKER_IDENTITY
    )
    
    logger.info(f"[MtfPingPongWorker] Worker configuré avec {len(workflows)} workflows et {len(activities)} activités")
    
    # Démarrage du worker
    try:
        logger.info(f"[MtfPingPongWorker] Démarrage du worker...")
        await worker.run()
    except KeyboardInterrupt:
        logger.info(f"[MtfPingPongWorker] Arrêt demandé par l'utilisateur")
    except Exception as e:
        logger.error(f"[MtfPingPongWorker] Erreur dans le worker: {e}")
        raise
    finally:
        logger.info(f"[MtfPingPongWorker] Worker arrêté")


if __name__ == "__main__":
    asyncio.run(main())








