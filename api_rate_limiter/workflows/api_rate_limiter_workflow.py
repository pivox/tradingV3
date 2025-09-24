# workflows/api_rate_limiter_workflow.py
# -*- coding: utf-8 -*-
"""
Workflow Temporal : Client de rate limiting par file et signaux.
- File interne (_queue) drainée à cadence contrôlée.
- Accepte dict ou list[dict] via queue_init et signal 'submit'.
- Continue-As-New pour compacter l'historique.
- Seul effet de bord : activité HTTP 'post_callback'.
"""

from __future__ import annotations

from datetime import timedelta, datetime
from typing import Any, Dict, List, Optional, Tuple

from temporalio import workflow

# ---------------- Paramètres cadence & rotation ----------------
TICK = timedelta(seconds=0.2)       # tick d'attente
MIN_SPACING = timedelta(seconds=1)  # espacement minimal entre envois
DRAIN_BATCH = 1                     # nb max d'éléments par tick
MAX_ITEMS_PER_RUN = 400             # seuil rotation Continue-As-New
MAX_RUN_SECONDS = 15 * 60           # seuil temps max par run

Envelope = Dict[str, Any]
Envelopes = List[Envelope]


def _normalize_batch(maybe: Any) -> Envelopes:
    """
    Normalise l'entrée vers une liste d'enveloppes.
      None       -> []
      dict       -> [dict]
      list[dict] -> list[dict]
    """
    if maybe is None:
        return []
    if isinstance(maybe, dict):
        return [maybe]
    if isinstance(maybe, list):
        for i, item in enumerate(maybe):
            if not isinstance(item, dict):
                raise TypeError(f"Batch item #{i} is not a dict (got {type(item).__name__})")
        return list(maybe)
    raise TypeError(f"Expected dict or list[dict], got {type(maybe).__name__}")


@workflow.defn(name="ApiRateLimiterClient")
class ApiRateLimiterClient:
    """
    Workflow de rate limiting alimenté par signaux 'submit'.
    - La file (_queue) vit en mémoire et est bornée par la rotation CAN.
    - L'activité 'post_callback' appelle votre service externe.
    """

    def __init__(self, queue_init: Optional[Envelopes] = None):
        self._queue: Envelopes = list(queue_init or [])
        self._closed: bool = False
        self._processed_in_run: int = 0
        self._started_at: datetime = workflow.now()
        self._last_dispatch_at: Optional[datetime] = None

    # ---------------- Signals ----------------
    @workflow.signal
    def submit(self, envelope_or_batch: Any) -> None:
        """
        Ajoute une enveloppe (dict) ou un batch (list[dict]) à la file.
        Type-hint en Any pour éviter l'échec de désérialisation si l'appelant envoie une liste.
        """
        if self._closed:
            return
        self._queue.extend(_normalize_batch(envelope_or_batch))

    @workflow.signal
    def close(self) -> None:
        """Marque la file comme fermée; le workflow termine une fois la file vidée."""
        self._closed = True

    # ---------------- Queries ----------------
    @workflow.query
    def queue_size(self) -> int:
        return len(self._queue)

    @workflow.query
    def stats(self) -> Tuple[int, int, int]:
        """
        Retourne (processed_in_run, elapsed_seconds, queue_size).
        """
        elapsed = int((workflow.now() - self._started_at).total_seconds())
        return self._processed_in_run, elapsed, len(self._queue)

    # ---------------- Helpers internes ----------------
    def _must_continue_as_new(self) -> bool:
        if self._processed_in_run >= MAX_ITEMS_PER_RUN:
            return True
        if (workflow.now() - self._started_at).total_seconds() >= MAX_RUN_SECONDS:
            return True
        return False

    async def _rotate_if_needed(self) -> None:
        if self._must_continue_as_new():
            # Redémarre avec la file restante; les signaux pendant la fermeture seront rejoués.
            await workflow.continue_as_new(self._queue)

    # ---------------- Run ----------------
    @workflow.run
    async def run(self, queue_init: Optional[Any] = None) -> None:
        """
        Démarre/relance le workflow.
        - queue_init : dict, list[dict] ou None.
        """
        if queue_init is not None:
            self._queue = _normalize_batch(queue_init)

        self._started_at = workflow.now()
        self._processed_in_run = 0
        self._last_dispatch_at = workflow.now() - MIN_SPACING  # premier envoi possible immédiat

        while True:
            # Fin propre si file vide
            if not self._queue:
                break
            # (couvert par la condition ci-dessus)
            if self._closed and not self._queue:
                break

            now = workflow.now()
            can_dispatch = (now - (self._last_dispatch_at or now)) >= MIN_SPACING

            if self._queue and can_dispatch:
                to_process = min(DRAIN_BATCH, len(self._queue))
                for _ in range(to_process):
                    if not self._queue:
                        break

                    envelope = self._queue.pop(0)

                    # Appel de l'activité externe
                    await workflow.execute_activity(
                        "post_callback",
                        args=[envelope],
                        start_to_close_timeout=timedelta(seconds=30),
                        schedule_to_close_timeout=timedelta(seconds=60),
                    )

                    self._processed_in_run += 1
                    self._last_dispatch_at = workflow.now()

                    # Rotation périodique pour compacter l'historique
                    await self._rotate_if_needed()

                # Laisse la main même si la cadence autorise immédiatement
                await workflow.sleep(TICK)
            else:
                await workflow.sleep(TICK)
