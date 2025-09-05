# workflows/api_rate_limiter_workflow.py
# -*- coding: utf-8 -*-
"""
Workflow Temporal : Client de rate limiting par file et signaux.
- Traite les enveloppes (envelope) à cadence contrôlée.
- Écrit les résultats dans un JSON externe (pas d'accumulation dans l'état du workflow).
- Utilise Continue-As-New pour maintenir une histoire compacte.

Prérequis côté activités:
- "post_callback(envelope: Dict[str, Any]) -> Dict[str, Any]"
- "store_result_json(request_id: str, payload: Dict[str, Any], path: Optional[str]) -> None"
- "fetch_result_json(request_id: str, path: Optional[str]) -> Optional[Dict[str, Any]]"
"""

from __future__ import annotations

from temporalio import workflow
from datetime import timedelta, datetime
from typing import Dict, Any, List, Optional, Tuple

# --- Paramètres de cadence et rotation ---
ONE_SECOND = timedelta(seconds=1)

# Nombre maximal d'items traités par "run" avant rotation Continue-As-New
MAX_ITEMS_PER_RUN = 500

# Durée maximale (en secondes) d'un "run" avant rotation Continue-As-New
MAX_RUN_SECONDS = 15 * 60  # 15 minutes

# Combien d'éléments drainer par itération (1 = strict 1/s si on garde ONE_SECOND)
DRAIN_BATCH = 1

# Chemin du fichier de résultats JSON (peut être daté si besoin)
RESULTS_PATH_DEFAULT = "./data/results.json"


@workflow.defn(name="ApiRateLimiterClient")  # aligne ce nom avec PHP (workflowType)
class ApiRateLimiterClient:
    """
    Workflow de rate limiting alimenté par signaux "submit".
    - La file (_queue) est bornée par rotation CAN.
    - Les résultats ne sont pas stockés en mémoire du workflow, mais écrits en JSON externe.
    """

    def __init__(self, queue_init: Optional[List[Dict[str, Any]]] = None, results_path: Optional[str] = None):
        # État minimal et borné
        self._queue: List[Dict[str, Any]] = list(queue_init or [])
        self._closed: bool = False

        # Compteurs/horodatage du run courant (réinitialisés à chaque run)
        self._processed_in_run: int = 0
        self._started_at: datetime = workflow.now()

        # Chemin des résultats (fixe pour tous les runs, passez-le à continue_as_new si vous le changez)
        self._results_path: str = results_path or RESULTS_PATH_DEFAULT

    # ------------- Signals -------------

    @workflow.signal
    async def submit(self, envelope: Dict[str, Any]) -> None:
        """
        Dépose une nouvelle requête dans la file.
        """
        envelope.setdefault("request_id", workflow.uuid4())  # UUID "workflow-safe"
        self._queue.append(envelope)

    @workflow.signal
    async def close(self) -> None:
        """
        Indique que l'appelant n'enverra plus d'éléments pour l'instant.
        Le workflow reste toutefois capable de recevoir de futurs signaux après rotation.
        """
        self._closed = True

    # ------------- Queries -------------

    @workflow.query
    def size(self) -> int:
        """
        Taille actuelle de la file.
        """
        return len(self._queue)

    @workflow.query
    def can_rotate_now(self) -> Tuple[int, int]:
        """
        Retourne (traités_dans_ce_run, secondes_ecoulees) pour introspection/opérations.
        """
        elapsed = int((workflow.now() - self._started_at).total_seconds())
        return self._processed_in_run, elapsed

    @workflow.query
    async def get_result(self, request_id: str) -> Optional[Dict[str, Any]]:
        """
        Lit le résultat d'une requête (via activité), sans gonfler l'état du workflow.
        """
        return await workflow.execute_activity(
            "fetch_result_json",
            args=[request_id, self._results_path],
            start_to_close_timeout=timedelta(seconds=10),
        )

    # ------------- Helpers internes -------------

    def _must_continue_as_new(self) -> bool:
        """
        Politique de rotation: par nombre traité OU par durée de run.
        """
        elapsed = (workflow.now() - self._started_at).total_seconds()
        return (self._processed_in_run >= MAX_ITEMS_PER_RUN) or (elapsed >= MAX_RUN_SECONDS)

    async def _rotate_if_needed(self) -> None:
        """
        Déclenche une rotation Continue-As-New si nécessaire, en passant la file restante.
        """
        if self._must_continue_as_new():
            # On redémarre le workflow avec la queue restante et le même chemin de résultats.
            await workflow.continue_as_new(self._queue, self._results_path)

    # ------------- Run -------------

    @workflow.run
    async def run(self, queue_init: Optional[List[Dict[str, Any]]] = None, results_path: Optional[str] = None) -> None:
        """
        Démarre/relance le workflow. Peut recevoir:
        - queue_init : pour reprendre la file restante après une rotation CAN
        - results_path : pour conserver le chemin du JSON d'un run à l'autre
        """
        # Réinitialisation "run-scope"
        if queue_init:
            # __init__ a déjà injecté queue_init si fourni à la construction,
            # mais Temporal appele run() à chaque rotation avec les args; on harmonise.
            self._queue = list(queue_init)
        if results_path:
            self._results_path = results_path

        self._started_at = workflow.now()
        self._processed_in_run = 0

        # Boucle principale
        while True:
            # Attendre jusqu'à ce qu'il y ait du travail ou une demande de "close"
            await workflow.wait_condition(lambda: self._queue or self._closed)

            # Si fermé et file vide: respirer/rotater (évite un "idle" qui empile des ticks)
            if self._closed and not self._queue:
                self._closed = False
                await self._rotate_if_needed()
                await workflow.sleep(ONE_SECOND)
                continue

            # Drainer jusqu'à DRAIN_BATCH éléments (1 par défaut)
            for _ in range(min(DRAIN_BATCH, len(self._queue))):
                envelope = self._queue.pop(0)

                # Cadence simple: 1 req / seconde
                await workflow.sleep(ONE_SECOND)

                # Appel HTTP via l'activité existante
                result = await workflow.execute_activity(
                    "post_callback",
                    args=[envelope],
                    start_to_close_timeout=timedelta(seconds=30),
                    schedule_to_close_timeout=timedelta(seconds=60),
                )

                # Écriture du résultat dans le JSON externe (clé = request_id)
                await workflow.execute_activity(
                    "store_result_json",
                    args=[
                        envelope["request_id"],
                        {
                            "status": result.get("status"),
                            "meta": result,
                        },
                        self._results_path,
                    ],
                    start_to_close_timeout=timedelta(seconds=10),
                )

                self._processed_in_run += 1

                # Rotation périodique si nécessaire
                await self._rotate_if_needed()
