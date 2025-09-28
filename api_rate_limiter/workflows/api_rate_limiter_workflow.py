# -*- coding: utf-8 -*-
from __future__ import annotations
from datetime import timedelta, datetime
from typing import Any, Dict, List, Optional, Set

from temporalio import workflow

# modèles
from models.api_call_request import ApiCallRequest  # doit fournir from_dict() et to_activity_payload()
from models.priority_config import PriorityConfig

# ---------------- Cadence & rotation ----------------
TICK = timedelta(seconds=0.2)
MIN_SPACING = timedelta(seconds=1)      # ≈ 1 req/s
DRAIN_BATCH = 1
MAX_ITEMS_PER_RUN = 400
MAX_RUN_SECONDS = 15 * 60

@workflow.defn(name="ApiRateLimiterClient")
class ApiRateLimiterClient:
    """
    Rate-limiter prioritaire. Entrée unique attendue :
      { "<bucket>": [ {..item..}, ... ], ... }
    """

    def __init__(self, queue_init: Optional[Dict[str, List[Dict[str, Any]]]] = None):
        self._prio = PriorityConfig()
        self._queues: Dict[str, List[ApiCallRequest]] = {b: [] for b in self._prio.order}
        self._paused: Set[str] = set()  # optionnel : buckets mis en pause
        self._closed: bool = False
        self._processed_in_run: int = 0
        self._started_at: datetime = workflow.now()
        self._last_dispatch_at: Optional[datetime] = None

        if queue_init:
            self._enqueue_bucket_map(queue_init)

    # ---------- utils ----------
    def _enqueue_bucket_map(self, bucket_map: Dict[str, List[Dict[str, Any]]]) -> None:
        if not isinstance(bucket_map, dict):
            raise TypeError("Queue must be a dict: {bucket: [items]}")

        known = self._prio.known_buckets()
        # Garantir l'existence des clés dans _queues si l'ordre actif est un sous-ensemble
        for b in known:
            if b not in self._queues:
                self._queues[b] = []

        for bucket, items in bucket_map.items():
            if bucket not in known:
                raise ValueError(f"Unknown bucket '{bucket}'. Allowed: {sorted(known)}")
            if not isinstance(items, list):
                raise TypeError(f"Bucket '{bucket}' must map to a list of items")

            q = self._queues[bucket]
            for i, env in enumerate(items):
                if not isinstance(env, dict):
                    raise TypeError(f"Item #{i} in bucket '{bucket}' must be a dict")
                req = ApiCallRequest.from_dict(env)
                q.append(req)

    def _total_queue_size(self) -> int:
        return sum(len(v) for v in self._queues.values())

    def _must_continue_as_new(self) -> bool:
        if self._processed_in_run >= MAX_ITEMS_PER_RUN:
            return True
        if (workflow.now() - self._started_at).total_seconds() >= MAX_RUN_SECONDS:
            return True
        return False

    async def _rotate_if_needed(self) -> None:
        if not self._must_continue_as_new():
            return
        # Reconstruire l'état restant au format {bucket: [dicts]}
        flat: Dict[str, List[Dict[str, Any]]] = {}
        for bucket, q in self._queues.items():
            if not q:
                continue
            # on suppose que req.payload est sérialisable via to_dict() si nécessaire
            items: List[Dict[str, Any]] = []
            for req in q:
                # On passe l’enveloppe « brute » attendue par from_dict()
                # Si ApiCallRequest conserve l'original, exposez-le (ex. req.raw) ; sinon, adaptez.
                if hasattr(req, "raw") and isinstance(req.raw, dict):
                    items.append(req.raw)  # meilleur cas : brut d'origine
                else:
                    # fallback minimal : reconstruire depuis l'objet (à adapter à votre modèle)
                    items.append({"payload": getattr(req, "payload", None)})
            flat[bucket] = items

        await workflow.continue_as_new(flat)

    # ---------- Signals ----------
    @workflow.signal
    def submit(self, bucket_map: Dict[str, List[Dict[str, Any]]]) -> None:
        if self._closed:
            return
        self._enqueue_bucket_map(bucket_map)

    @workflow.signal
    def close(self) -> None:
        self._closed = True

    @workflow.signal
    def pause_buckets(self, buckets: List[str]) -> None:
        """Met en pause certains buckets (optionnel)."""
        for b in buckets or []:
            if self._prio.is_known(b):
                self._paused.add(b)

    @workflow.signal
    def resume_buckets(self, buckets: List[str]) -> None:
        for b in buckets or []:
            self._paused.discard(b)

    # ---------- Updates (synchro) ----------
    @workflow.update
    def set_priority_order(self, new_order: List[str]) -> str:
        """Change l’ordre actif SANS redémarrer le worker."""
        self._prio.set_order(new_order)
        # S'assurer que _queues possède toutes les clés
        for b in self._prio.known_buckets():
            self._queues.setdefault(b, [])
        return "ok"

    # ---------- Queries ----------
    @workflow.query
    def queue_size(self) -> int:
        return self._total_queue_size()

    @workflow.query
    def stats(self) -> Dict[str, Any]:
        elapsed = int((workflow.now() - self._started_at).total_seconds())
        return {
            "processed_in_run": self._processed_in_run,
            "elapsed_seconds": elapsed,
            "per_bucket": {b: len(self._queues.get(b, [])) for b in self._prio.order},
            "paused": sorted(list(self._paused)),
            "active_order": list(self._prio.order),
        }

    # ---------- Run ----------
    @workflow.run
    async def run(self, queue_init: Optional[Dict[str, List[Dict[str, Any]]]] = None) -> None:
        if queue_init:
            self._enqueue_bucket_map(queue_init)
        print ("ApiRateLimiterClient started with queues:", {b: len(q) for b, q in self._queues.items()})
        self._started_at = workflow.now()
        self._processed_in_run = 0
        self._last_dispatch_at = workflow.now() - MIN_SPACING

        while True:
            if self._total_queue_size() == 0 or self._closed:
                break
            print("Current queue sizes:", {b: len(q) for b, q in self._queues.items()})

            if self._total_queue_size() == 0:
                await workflow.sleep(TICK)
                continue

            now = workflow.now()
            if (now - (self._last_dispatch_at or now)) < MIN_SPACING:
                await workflow.sleep(TICK)
                continue

            bucket = self._prio.next_non_empty_bucket(self._queues, paused=self._paused)
            print("Next bucket to process:", bucket)
            if not bucket:
                await workflow.sleep(TICK)
                continue

            # Draine au plus DRAIN_BATCH dans le bucket courant
            for _ in range(min(DRAIN_BATCH, len(self._queues[bucket]))):
                req = self._queues[bucket].pop(0)
                print(f"Dispatching request from bucket '{bucket}':", req)

                await workflow.execute_activity(
                    "post_callback",
                    args=[req.to_activity_payload()],
                    start_to_close_timeout=timedelta(seconds=30),
                    schedule_to_close_timeout=timedelta(seconds=60),
                )

                self._processed_in_run += 1
                self._last_dispatch_at = workflow.now()
                await self._rotate_if_needed()

            await workflow.sleep(TICK)
