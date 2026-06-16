"""Endpoint d'orchestration : ``POST /orchestrator/run``.

PY-001 : squelette / stub. Le run lit les sets actifs simulés et retourne
le contrat JSON cible (ok / run_id / status / summary) sans appel réseau.

La vraie exécution parallèle bornée des appels Symfony, l'agrégation des
réponses et la persistance du dernier JSON sont l'objet de PY-002.
"""

from __future__ import annotations

import uuid
from datetime import datetime, timezone

from fastapi import APIRouter

from app.schemas import RunResponse, RunStatus, RunSummary
from app.services.sets import list_active_sets

router = APIRouter(tags=["orchestrator"])


def _generate_run_id() -> str:
    """Génère un identifiant de run lisible et unique."""
    stamp = datetime.now(timezone.utc).strftime("%Y%m%d_%H%M%S")
    return f"run_{stamp}_{uuid.uuid4().hex[:6]}"


@router.post("/orchestrator/run", response_model=RunResponse)
def run_orchestrator() -> RunResponse:
    """Déclenche un run d'orchestration (stub PY-001).

    Étapes cibles (PY-002) : lire les sets actifs, appliquer les garde-fous,
    lancer les appels Symfony en parallèle avec concurrence bornée, agréger,
    sauvegarder le dernier JSON, retourner un statut court.
    """
    run_id = _generate_run_id()
    active_sets = list_active_sets()

    # TODO(PY-002): remplacer la simulation par l'exécution parallèle bornée
    # des appels Symfony (httpx.AsyncClient + asyncio.Semaphore(max_concurrency)),
    # puis agréger les réponses réelles et persister le dernier JSON (DB-001).
    total_calls = len(active_sets)
    success = total_calls
    failed = 0

    summary = RunSummary(total_calls=total_calls, success=success, failed=failed)
    status: RunStatus = _resolve_status(total_calls, success, failed)

    return RunResponse(
        ok=(failed == 0),
        run_id=run_id,
        status=status,
        summary=summary,
    )


def _resolve_status(total: int, success: int, failed: int) -> RunStatus:
    """Dérive le statut agrégé à partir des compteurs d'appels."""
    if failed == 0:
        return "success"
    if success == 0:
        return "failed"
    return "partial_failure"
