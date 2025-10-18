"""
Script pour démarrer le workflow MTF Ping-Pong
"""
import asyncio
import logging
import os
from datetime import timedelta

from temporalio.client import Client

from workflows.mtf_ping_pong_workflow import MtfPingPongWorkflow, MtfPingPongConfig


# Configuration
TEMPORAL_ADDRESS = os.getenv("TEMPORAL_ADDRESS", "temporal-grpc:7233")
TEMPORAL_NAMESPACE = os.getenv("TEMPORAL_NAMESPACE", "default")
TASK_QUEUE = os.getenv("TASK_QUEUE_NAME", "mtf-ping-pong-queue")


async def start_mtf_ping_pong_workflow():
    """Démarre le workflow MTF Ping-Pong"""
    
    # Configuration du logging
    logging.basicConfig(
        level=logging.INFO,
        format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
    )
    logger = logging.getLogger(__name__)
    
    logger.info(f"[MtfPingPongStarter] Démarrage du workflow MTF Ping-Pong")
    
    # Connexion au client Temporal
    try:
        client = await Client.connect(
            TEMPORAL_ADDRESS,
            namespace=TEMPORAL_NAMESPACE
        )
        logger.info(f"[MtfPingPongStarter] Connecté à Temporal")
    except Exception as e:
        logger.error(f"[MtfPingPongStarter] Erreur de connexion à Temporal: {e}")
        raise
    
    # Configuration du workflow
    config = MtfPingPongConfig(
        mtf_url="http://trading-app-nginx:80/api/mtf/run",
        symbols=["BTCUSDT", "ETHUSDT", "ADAUSDT", "SOLUSDT", "DOTUSDT"],
        dry_run=True,
        force_run=False,
        max_execution_time=420,  # 7 minutes
        ping_interval=30  # 30 secondes entre les pings
    )
    
    # ID du workflow
    workflow_id = f"mtf-ping-pong-{int(asyncio.get_event_loop().time())}"
    
    logger.info(f"[MtfPingPongStarter] Démarrage du workflow avec ID: {workflow_id}")
    logger.info(f"[MtfPingPongStarter] Configuration: {config}")
    
    try:
        # Démarrage du workflow
        handle = await client.start_workflow(
            MtfPingPongWorkflow.run,
            config,
            id=workflow_id,
            task_queue=TASK_QUEUE,
            execution_timeout=timedelta(minutes=10)  # Timeout d'exécution de 10 minutes
        )
        
        logger.info(f"[MtfPingPongStarter] Workflow démarré avec succès")
        logger.info(f"[MtfPingPongStarter] Workflow ID: {handle.id}")
        logger.info(f"[MtfPingPongStarter] Run ID: {handle.result_run_id}")
        
        # Attendre le résultat (optionnel)
        try:
            result = await handle.result()
            logger.info(f"[MtfPingPongStarter] Workflow terminé: {result}")
        except Exception as e:
            logger.error(f"[MtfPingPongStarter] Erreur dans le workflow: {e}")
            
    except Exception as e:
        logger.error(f"[MtfPingPongStarter] Erreur lors du démarrage du workflow: {e}")
        raise


async def stop_mtf_ping_pong_workflow(workflow_id: str):
    """Arrête le workflow MTF Ping-Pong"""
    
    logging.basicConfig(
        level=logging.INFO,
        format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
    )
    logger = logging.getLogger(__name__)
    
    logger.info(f"[MtfPingPongStopper] Arrêt du workflow: {workflow_id}")
    
    try:
        client = await Client.connect(
            TEMPORAL_ADDRESS,
            namespace=TEMPORAL_NAMESPACE
        )
        
        handle = client.get_workflow_handle(workflow_id)
        await handle.signal(MtfPingPongWorkflow.stop_signal)
        
        logger.info(f"[MtfPingPongStopper] Signal d'arrêt envoyé au workflow")
        
    except Exception as e:
        logger.error(f"[MtfPingPongStopper] Erreur lors de l'arrêt du workflow: {e}")
        raise


async def get_workflow_status(workflow_id: str):
    """Récupère le statut du workflow"""
    
    logging.basicConfig(
        level=logging.INFO,
        format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
    )
    logger = logging.getLogger(__name__)
    
    try:
        client = await Client.connect(
            TEMPORAL_ADDRESS,
            namespace=TEMPORAL_NAMESPACE
        )
        
        handle = client.get_workflow_handle(workflow_id)
        status = await handle.query(MtfPingPongWorkflow.get_status)
        
        logger.info(f"[MtfPingPongStatus] Statut du workflow: {status}")
        return status
        
    except Exception as e:
        logger.error(f"[MtfPingPongStatus] Erreur lors de la récupération du statut: {e}")
        raise


if __name__ == "__main__":
    import sys
    
    if len(sys.argv) > 1:
        command = sys.argv[1]
        
        if command == "start":
            asyncio.run(start_mtf_ping_pong_workflow())
        elif command == "stop" and len(sys.argv) > 2:
            workflow_id = sys.argv[2]
            asyncio.run(stop_mtf_ping_pong_workflow(workflow_id))
        elif command == "status" and len(sys.argv) > 2:
            workflow_id = sys.argv[2]
            asyncio.run(get_workflow_status(workflow_id))
        else:
            print("Usage:")
            print("  python start_workflow.py start")
            print("  python start_workflow.py stop <workflow_id>")
            print("  python start_workflow.py status <workflow_id>")
    else:
        asyncio.run(start_mtf_ping_pong_workflow())








