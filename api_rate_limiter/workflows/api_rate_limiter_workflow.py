# workflows/api_rate_limiter_workflow.py
# -*- coding: utf-8 -*-
"""
Workflow Temporal : Client de rate limiting par file et signaux.
- Traite les enveloppes (envelope) à cadence contrôlée via un simple rate limiter interne.
- Utilise Continue-As-New pour maintenir une histoire compacte.
- Ne persiste plus de résultats en JSON : le seul effet de bord est l’activité HTTP "post_callback".
"""

from __future__ import annotations

from datetime import timedelta, datetime
from typing import Dict, Any, List, Optional, Tuple

from temporalio import workflow

# ---------------- Paramètres cadence & rotation ----------------

# Tick de réveil du loop (réactivité du workflow)
TICK = timedelta(seconds=0.2)

# Espacement minimal entre deux envois (ex: 1 req / seconde)
MIN_SPACING = timedelta(seconds=1)

# Nombre d’éléments max expédiés par tick (1 = stricte cadence 1/s)
DRAIN_BATCH = 1

# Garde-fous de rotation (Continue-As-New) pour compacter l’historique
MAX_ITEMS_PER_RUN = 400            # rotation après N éléments traités
MAX_RUN_SECONDS = 15 * 60          # ou après N secondes de runtime


@workflow.defn(name="ApiRateLimiterClient")
class ApiRateLimiterClient:
    """
    Workflow de rate limiting alimenté par signaux "submit".
    - La file (_queue) est bornée par la rotation CAN, pas de persistance locale des résultats.
    - Pour recevoir les résultats, utilisez l’endpoint "post_callback" côté service externe.
    """

    def __init__(self, queue_init: Optional[List[Dict[str, Any]]] = None):
        # État minimal et borné (repassé à chaque rotation CAN)
        self._queue: List[Dict[str, Any]] = list(queue_init or [])
        self._closed: bool = False

        # Compteurs/horodatage du run courant
        self._processed_in_run: int = 0
        self._started_at: datetime = workflow.now()
        self._last_dispatch_at: Optional[datetime] = None

    # ---------------- Signals ----------------

    @workflow.signal
    def submit(self, envelope: Dict[str, Any]) -> None:
        """
        Ajoute une enveloppe à la file.
        Attendu: envelope contient au moins "request_id" et les données nécessaires au callback.
        """
        if self._closed:
            # On ignore les nouveaux éléments si le workflow a été clos.
            return
        self._queue.append(envelope)

    @workflow.signal
    def close(self) -> None:
        """Ferme l’alimentation de la file (le workflow se termine une fois la file vidée)."""
        self._closed = True

    # ---------------- Queries ----------------

    @workflow.query
    def queue_size(self) -> int:
        """Taille actuelle de la file."""
        return len(self._queue)

    @workflow.query
    def stats(self) -> Tuple[int, int]:
        """
        Statistiques du run courant.
        Retourne (processed_in_run, elapsed_seconds).
        """
        elapsed = int((workflow.now() - self._started_at).total_seconds())
        return self._processed_in_run, elapsed

    # ---------------- Helpers internes ----------------

    def _must_continue_as_new(self) -> bool:
        """Décide s’il faut rotate en Continue-As-New pour garder une histoire compacte."""
        if self._processed_in_run >= MAX_ITEMS_PER_RUN:
            return True
        if (workflow.now() - self._started_at).total_seconds() >= MAX_RUN_SECONDS:
            return True
        return False

    async def _rotate_if_needed(self) -> None:
        """Effectue la rotation CAN en repassant la file restante."""
        if self._must_continue_as_new():
            # On redémarre avec l’état de file restant; les signaux reçus
            # pendant la fermeture sont correctement rejoués par Temporal.
            await workflow.continue_as_new(self._queue)

    # ---------------- Run ----------------

    @workflow.run
    async def run(self, queue_init: Optional[List[Dict[str, Any]]] = None) -> None:
        """
        Démarre/relance le workflow.
        - queue_init : reprend la file restante après une rotation CAN, si fournie.
        """
        # Réinitialisation "run-scope"
        if queue_init is not None:
            self._queue = list(queue_init)

        self._started_at = workflow.now()
        self._processed_in_run = 0
        # On force un premier envoi possible immédiatement
        self._last_dispatch_at = workflow.now() - MIN_SPACING

        # Boucle principale : draine la file à cadence contrôlée
        while True:
            # 1) Condition d’arrêt immédiate :
            #    si la file est vide → fin propre du workflow
            if not self._queue:
                break

            # 2) Condition d’arrêt naturelle si file vide ET close
            if self._closed and not self._queue:
                break

            now = workflow.now()
            can_dispatch = (now - (self._last_dispatch_at or now)) >= MIN_SPACING

            if self._queue and can_dispatch:
                # Draine un petit batch pour garder la cadence maîtrisée
                to_process = min(DRAIN_BATCH, len(self._queue))
                for _ in range(to_process):
                    if not self._queue:
                        break

                    envelope = self._queue.pop(0)

                    # Appel HTTP (activité) vers votre système externe
                    # Doit retourner un dict (ex: {"status": "ok", ...})
                    await workflow.execute_activity(
                        "post_callback",
                        args=[envelope],
                        start_to_close_timeout=timedelta(seconds=30),
                        schedule_to_close_timeout=timedelta(seconds=60),
                    )

                    self._processed_in_run += 1
                    self._last_dispatch_at = workflow.now()

                    # Rotation périodique si nécessaire
                    await self._rotate_if_needed()

                # Petit yield pour laisser la main même si la cadence autorise tout de suite
                await workflow.sleep(TICK)
            else:
                # Rien à faire ou attente du prochain slot de cadence
                await workflow.sleep(TICK)

