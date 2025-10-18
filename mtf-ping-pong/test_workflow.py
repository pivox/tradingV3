"""
Script de test pour le workflow MTF Ping-Pong
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


async def test_workflow():
    """Test du workflow MTF Ping-Pong"""
    
    # Configuration du logging
    logging.basicConfig(
        level=logging.INFO,
        format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
    )
    logger = logging.getLogger(__name__)
    
    logger.info(f"[Test] Démarrage du test du workflow MTF Ping-Pong")
    
    # Connexion au client Temporal
    try:
        client = await Client.connect(
            TEMPORAL_ADDRESS,
            namespace=TEMPORAL_NAMESPACE
        )
        logger.info(f"[Test] Connecté à Temporal")
    except Exception as e:
        logger.error(f"[Test] Erreur de connexion à Temporal: {e}")
        return False
    
    # Configuration de test (timeout court pour le test)
    config = MtfPingPongConfig(
        mtf_url="http://trading-app-nginx:80/api/mtf/run",
        symbols=["BTCUSDT"],  # Un seul symbole pour le test
        dry_run=True,
        force_run=False,
        max_execution_time=60,  # 1 minute pour le test
        ping_interval=10  # 10 secondes entre les pings
    )
    
    # ID du workflow de test
    workflow_id = f"test-mtf-ping-pong-{int(asyncio.get_event_loop().time())}"
    
    logger.info(f"[Test] Configuration de test: {config}")
    logger.info(f"[Test] Workflow ID: {workflow_id}")
    
    try:
        # Démarrage du workflow de test
        handle = await client.start_workflow(
            MtfPingPongWorkflow.run,
            config,
            id=workflow_id,
            task_queue=TASK_QUEUE,
            execution_timeout=timedelta(minutes=2)  # Timeout d'exécution de 2 minutes
        )
        
        logger.info(f"[Test] Workflow de test démarré avec succès")
        logger.info(f"[Test] Workflow ID: {handle.id}")
        logger.info(f"[Test] Run ID: {handle.result_run_id}")
        
        # Attendre un peu pour voir le workflow en action
        await asyncio.sleep(30)
        
        # Vérifier le statut
        status = await handle.query(MtfPingPongWorkflow.get_status)
        logger.info(f"[Test] Statut du workflow: {status}")
        
        # Envoyer un signal de continuation
        await handle.signal(MtfPingPongWorkflow.continue_signal)
        logger.info(f"[Test] Signal de continuation envoyé")
        
        # Attendre encore un peu
        await asyncio.sleep(20)
        
        # Vérifier le statut final
        final_status = await handle.query(MtfPingPongWorkflow.get_status)
        logger.info(f"[Test] Statut final du workflow: {final_status}")
        
        # Arrêter le workflow de test
        await handle.signal(MtfPingPongWorkflow.stop_signal)
        logger.info(f"[Test] Signal d'arrêt envoyé")
        
        logger.info(f"[Test] Test terminé avec succès")
        return True
        
    except Exception as e:
        logger.error(f"[Test] Erreur lors du test: {e}")
        return False


async def test_health_check():
    """Test de santé du service"""
    
    logging.basicConfig(
        level=logging.INFO,
        format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
    )
    logger = logging.getLogger(__name__)
    
    logger.info(f"[HealthTest] Test de santé du service")
    
    try:
        from activities.mtf_activities import health_check_activity
        result = await health_check_activity()
        logger.info(f"[HealthTest] Test de santé réussi: {result}")
        return True
    except Exception as e:
        logger.error(f"[HealthTest] Erreur lors du test de santé: {e}")
        return False


async def main():
    """Point d'entrée principal des tests"""
    
    print("🧪 Tests du workflow MTF Ping-Pong")
    print("=" * 50)
    
    # Test de santé
    print("\n1. Test de santé...")
    health_ok = await test_health_check()
    print(f"   Résultat: {'✅ OK' if health_ok else '❌ ÉCHEC'}")
    
    if not health_ok:
        print("❌ Test de santé échoué, arrêt des tests")
        return
    
    # Test du workflow
    print("\n2. Test du workflow...")
    workflow_ok = await test_workflow()
    print(f"   Résultat: {'✅ OK' if workflow_ok else '❌ ÉCHEC'}")
    
    # Résumé
    print("\n" + "=" * 50)
    if health_ok and workflow_ok:
        print("🎉 Tous les tests sont passés avec succès!")
    else:
        print("❌ Certains tests ont échoué")
        print(f"   - Test de santé: {'✅' if health_ok else '❌'}")
        print(f"   - Test de workflow: {'✅' if workflow_ok else '❌'}")


if __name__ == "__main__":
    asyncio.run(main())








