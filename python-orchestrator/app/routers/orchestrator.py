"""Endpoint d'orchestration : ``POST /orchestrator/run``.

PY-001 : squelette / stub. Le run lit les sets actifs simulés et retourne
le contrat JSON cible (ok / run_id / status / summary) sans appel réseau.

La vraie exécution parallèle bornée des appels Symfony, l'agrégation des
réponses et la persistance du dernier JSON sont l'objet de PY-002.
"""

from __future__ import annotations

import uuid
from datetime import datetime, timezone
from typing import Optional

from fastapi import APIRouter

from app.schemas import RunRequest, RunResponse, RunStatus, RunSummary
from app.services.sets import list_active_sets

router = APIRouter(tags=["orchestrator"])


def _resolve_run_id(request: Optional[RunRequest]) -> str:
    """Dérive un ``run_id`` stable depuis le contexte, sinon en génère un.

    L'idempotence est portée par l'appelant (Temporal/front) :
    - ``idempotency_key`` explicite -> identifiant stable ;
    - sinon ``dashboard_id`` + ``tick_timestamp`` -> identifiant dérivé stable ;
    - sinon (aucun contexte) -> identifiant aléatoire non idempotent.
    """
    if request is not None:
        if request.idempotency_key:
            return f"run_{request.idempotency_key}"
        if request.dashboard_id and request.tick_timestamp:
            stamp = request.tick_timestamp.astimezone(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
            return f"run_{request.dashboard_id}_{stamp}"
    stamp = datetime.now(timezone.utc).strftime("%Y%m%d_%H%M%S")
    return f"run_{stamp}_{uuid.uuid4().hex[:6]}"


def _resolve_status(success: int, failed: int) -> RunStatus:
    """Dérive le statut agrégé d'un run ayant au moins un set exécuté."""
    if failed == 0:
        return "success"
    if success == 0:
        return "failed"
    return "partial_failure"


@router.post("/orchestrator/run", response_model=RunResponse)
def run_orchestrator(request: Optional[RunRequest] = None) -> RunResponse:
    """Déclenche un run d'orchestration (stub PY-001).

    Étapes cibles (PY-002) : lire les sets actifs, appliquer les garde-fous,
    lancer les appels Symfony en parallèle avec concurrence bornée, agréger,
    sauvegarder le dernier JSON, retourner un statut court.
    """
    run_id = _resolve_run_id(request)
    active_sets = list_active_sets()

    # Aucun set actif n'est PAS un succès : on remonte un état explicite pour
    # que Temporal ne considère pas le tick comme réussi (ok=false).
    if not active_sets:
        return RunResponse(
            ok=False,
            run_id=run_id,
            status="no_sets",
            summary=RunSummary(total_calls=0, success=0, failed=0),
        )

    # TODO(PY-002): remplacer la simulation par l'exécution parallèle bornée
    # des appels Symfony (httpx.AsyncClient + asyncio.Semaphore(max_concurrency)),
    # puis agréger les réponses réelles et persister le dernier JSON (DB-001).
    total_calls = len(active_sets)
    success = total_calls
    failed = 0

    summary = RunSummary(total_calls=total_calls, success=success, failed=failed)
    status = _resolve_status(success, failed)

    return RunResponse(
        ok=(failed == 0),
        run_id=run_id,
        status=status,
        summary=summary,
    )
