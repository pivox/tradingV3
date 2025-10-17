"""
Workflow MTF Ping-Pong - Communication bidirectionnelle avec l'endpoint MTF run
"""
import asyncio
from datetime import timedelta
from typing import Dict, Any, Optional
from dataclasses import dataclass

from temporalio import workflow
from temporalio.common import RetryPolicy

from activities.mtf_activities import (
    call_mtf_run_activity,
    notify_temporal_activity,
    wait_for_mtf_completion_activity
)


@dataclass
class MtfPingPongConfig:
    """Configuration pour le workflow ping-pong MTF"""
    mtf_url: str = "http://trading-app-nginx:80/api/mtf/run"
    symbols: list = None
    dry_run: bool = True
    force_run: bool = False
    max_execution_time: int = 420  # 7 minutes en secondes
    ping_interval: int = 30  # Intervalle entre les pings en secondes
    
    def __post_init__(self):
        if self.symbols is None:
            self.symbols = ["BTCUSDT", "ETHUSDT", "ADAUSDT", "SOLUSDT", "DOTUSDT"]


@workflow.defn
class MtfPingPongWorkflow:
    """
    Workflow qui fait un ping-pong avec l'endpoint MTF run.
    
    Le workflow :
    1. Appelle l'endpoint MTF run
    2. Attend que MTF run se termine
    3. Notifie Temporal que c'est terminé
    4. Attend un signal de Temporal pour relancer
    5. Répète le cycle
    """
    
    def __init__(self):
        self.is_running = False
        self.current_iteration = 0
        self.mtf_completed = False
        self.temporal_notified = False
    
    @workflow.run
    async def run(self, config: MtfPingPongConfig) -> Dict[str, Any]:
        """
        Point d'entrée principal du workflow
        """
        workflow.logger.info(f"[MtfPingPong] Démarrage du workflow avec config: {config}")
        
        # Configuration du timeout global de 7 minutes
        timeout_duration = timedelta(seconds=config.max_execution_time)
        
        try:
            # Boucle principale avec timeout
            async with asyncio.timeout(timeout_duration):
                while True:
                    self.current_iteration += 1
                    workflow.logger.info(f"[MtfPingPong] Itération {self.current_iteration}")
                    
                    # Étape 1: Appeler MTF run
                    await self._call_mtf_run(config)
                    
                    # Étape 2: Attendre que MTF run se termine
                    await self._wait_for_mtf_completion(config)
                    
                    # Étape 3: Notifier Temporal
                    await self._notify_temporal(config)
                    
                    # Étape 4: Attendre le signal de Temporal pour continuer
                    await self._wait_for_temporal_signal()
                    
                    # Pause entre les cycles
                    await asyncio.sleep(config.ping_interval)
                    
        except asyncio.TimeoutError:
            workflow.logger.warning(f"[MtfPingPong] Timeout atteint après {config.max_execution_time} secondes")
            return {
                "status": "timeout",
                "iterations_completed": self.current_iteration,
                "message": f"Workflow arrêté après {config.max_execution_time} secondes"
            }
        except Exception as e:
            workflow.logger.error(f"[MtfPingPong] Erreur dans le workflow: {e}")
            return {
                "status": "error",
                "iterations_completed": self.current_iteration,
                "error": str(e)
            }
    
    async def _call_mtf_run(self, config: MtfPingPongConfig) -> None:
        """Appelle l'endpoint MTF run"""
        workflow.logger.info(f"[MtfPingPong] Appel de MTF run - Itération {self.current_iteration}")
        
        # Configuration de l'activité avec retry policy
        retry_policy = RetryPolicy(
            initial_interval=timedelta(seconds=5),
            maximum_interval=timedelta(seconds=30),
            maximum_attempts=3,
            backoff_coefficient=2.0
        )
        
        result = await workflow.execute_activity(
            call_mtf_run_activity,
            args=[config.mtf_url, config.symbols, config.dry_run, config.force_run],
            start_to_close_timeout=timedelta(minutes=2),
            retry_policy=retry_policy
        )
        
        workflow.logger.info(f"[MtfPingPong] MTF run appelé avec succès: {result}")
        self.mtf_completed = True
    
    async def _wait_for_mtf_completion(self, config: MtfPingPongConfig) -> None:
        """Attend que MTF run se termine complètement"""
        workflow.logger.info(f"[MtfPingPong] Attente de la completion de MTF run")
        
        # Vérification périodique que MTF run est terminé
        max_wait_time = timedelta(minutes=5)  # Maximum 5 minutes d'attente
        check_interval = timedelta(seconds=10)  # Vérification toutes les 10 secondes
        
        start_time = workflow.now()
        while (workflow.now() - start_time) < max_wait_time:
            try:
                result = await workflow.execute_activity(
                    wait_for_mtf_completion_activity,
                    args=[config.mtf_url],
                    start_to_close_timeout=timedelta(seconds=30),
                    retry_policy=RetryPolicy(maximum_attempts=2)
                )
                
                if result.get("completed", False):
                    workflow.logger.info(f"[MtfPingPong] MTF run terminé avec succès")
                    return
                    
            except Exception as e:
                workflow.logger.warning(f"[MtfPingPong] Erreur lors de la vérification: {e}")
            
            # Attendre avant la prochaine vérification
            await asyncio.sleep(check_interval.total_seconds())
        
        workflow.logger.warning(f"[MtfPingPong] Timeout d'attente de MTF run")
    
    async def _notify_temporal(self, config: MtfPingPongConfig) -> None:
        """Notifie Temporal que MTF run est terminé"""
        workflow.logger.info(f"[MtfPingPong] Notification à Temporal")
        
        notification_data = {
            "workflow_id": workflow.info().workflow_id,
            "iteration": self.current_iteration,
            "mtf_completed": True,
            "timestamp": workflow.now().isoformat(),
            "config": {
                "symbols": config.symbols,
                "dry_run": config.dry_run,
                "force_run": config.force_run
            }
        }
        
        result = await workflow.execute_activity(
            notify_temporal_activity,
            args=[notification_data],
            start_to_close_timeout=timedelta(seconds=30),
            retry_policy=RetryPolicy(maximum_attempts=3)
        )
        
        workflow.logger.info(f"[MtfPingPong] Temporal notifié: {result}")
        self.temporal_notified = True
    
    async def _wait_for_temporal_signal(self) -> None:
        """Attend un signal de Temporal pour continuer le cycle"""
        workflow.logger.info(f"[MtfPingPong] Attente du signal de Temporal")
        
        # Attendre un signal "continue" de Temporal
        await workflow.wait_condition(
            lambda: hasattr(self, 'temporal_continue_signal') and self.temporal_continue_signal,
            timeout=timedelta(minutes=2)  # Timeout de 2 minutes pour le signal
        )
        
        # Reset du signal pour la prochaine itération
        self.temporal_continue_signal = False
        workflow.logger.info(f"[MtfPingPong] Signal reçu de Temporal, continuation du cycle")
    
    @workflow.signal
    async def continue_signal(self) -> None:
        """Signal reçu de Temporal pour continuer le cycle"""
        workflow.logger.info(f"[MtfPingPong] Signal 'continue' reçu de Temporal")
        self.temporal_continue_signal = True
    
    @workflow.signal
    async def stop_signal(self) -> None:
        """Signal pour arrêter le workflow"""
        workflow.logger.info(f"[MtfPingPong] Signal 'stop' reçu, arrêt du workflow")
        self.is_running = False
    
    @workflow.query
    def get_status(self) -> Dict[str, Any]:
        """Retourne le statut actuel du workflow"""
        return {
            "is_running": self.is_running,
            "current_iteration": self.current_iteration,
            "mtf_completed": self.mtf_completed,
            "temporal_notified": self.temporal_notified,
            "workflow_id": workflow.info().workflow_id,
            "run_id": workflow.info().run_id
        }



