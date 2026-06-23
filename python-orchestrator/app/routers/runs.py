"""Lecture de l'historique des runs d'orchestration (PY-006).

Endpoints en LECTURE SEULE exposant le « dernier JSON » conservé par
l'orchestrateur :

- ``GET /runs``                       : liste des runs (filtre dashboard, pagination) ;
- ``GET /runs/{run_id}``              : dernier JSON global + détail par set ;
- ``GET /runs/{run_id}/sets/{set_id}``: dernier JSON d'un set (payload + réponse brute).

L'écriture de cet historique est faite par PY-005 (``POST /orchestrator/run``) ;
cette PR n'ajoute que la surface de lecture consommée par le cockpit
(UI-001/UI-003) et tout client voulant rejouer le dernier retour Symfony.
"""

from __future__ import annotations

from typing import Optional

import httpx
from fastapi import APIRouter, Depends, HTTPException, Query, status
from fastapi.responses import JSONResponse
from sqlalchemy.orm import Session

from app.db import repositories as repo
from app.db.engine import get_session
from app.db.models import RunSet
from app.schemas import RunDetailRead, RunSetRead, RunSummaryRead
from app.services.correlation import canonical_correlation_id
from app.services.symfony_client import (
    OutcomeUnavailableError,
    fetch_run_trade_outcome,
)
from app.settings import get_settings

router = APIRouter(prefix="/runs", tags=["runs"])

# Borne la taille de page pour ne jamais renvoyer un historique non borné.
_MAX_PAGE_SIZE = 100

# Timeout court de l'appel outcome (lecture seule, pas d'effet de bord côté Symfony).
_OUTCOME_TIMEOUT = 30.0


@router.get("", response_model=list[RunSummaryRead])
def list_runs(
    dashboard_id: Optional[int] = Query(
        default=None, description="Filtre les runs d'un dashboard donné."
    ),
    limit: int = Query(default=20, ge=1, le=_MAX_PAGE_SIZE, description="Taille de page."),
    offset: int = Query(default=0, ge=0, description="Décalage de pagination."),
    session: Session = Depends(get_session),
) -> list:
    """Liste les runs du plus récent au plus ancien (vue allégée, sans ``last_json``)."""
    return list(
        repo.list_runs(session, dashboard_id=dashboard_id, limit=limit, offset=offset)
    )


@router.get("/{run_id}", response_model=RunDetailRead)
def get_run(run_id: str, session: Session = Depends(get_session)) -> RunDetailRead:
    """Détail d'un run : dernier JSON global + dernier JSON par set."""
    run = repo.get_run(session, run_id)
    if run is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, detail="run not found")
    return RunDetailRead.from_run(run, repo.list_run_sets(session, run_id))


@router.get("/{run_id}/sets/{set_id}", response_model=RunSetRead)
def get_run_set(
    run_id: str, set_id: str, session: Session = Depends(get_session)
) -> RunSet:
    """Dernier JSON d'un set dans un run (payload envoyé + réponse Symfony brute)."""
    run_set = repo.get_run_set(session, run_id, set_id)
    if run_set is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, detail="run set not found")
    return run_set


@router.get("/{run_id}/outcome")
async def get_run_outcome(
    run_id: str,
    set_id: Optional[str] = Query(
        default=None, description="Filtre l'outcome sur un set d'orchestration."
    ),
    session: Session = Depends(get_session),
) -> JSONResponse:
    """Outcome (trades résultants) d'un run d'orchestration (OBS-003).

    Relie le run à ses trades via la vue Symfony ``position_trade_analysis``, par
    identifiant de corrélation. Le PnL n'est jamais recalculé (agrégat relayé).

    Sémantique (jamais confondre indisponibilité et « 0 trade ») :

    - **404** si le run est inconnu de l'orchestrateur ;
    - **200** si le run est connu et la source répond — agrégat éventuellement vide
      (``trade_count=0``), ``source_available=true`` ;
    - **503** si la source Symfony est indisponible — ``source_available=false`` +
      ``error_code`` explicite, jamais un agrégat vide silencieux.

    Les agrégats historiques multi-runs restent hors-scope.
    """
    run = repo.get_run(session, run_id)
    if run is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, detail="run not found")

    settings = get_settings()
    try:
        async with httpx.AsyncClient(timeout=_OUTCOME_TIMEOUT) as client:
            outcome = await fetch_run_trade_outcome(
                client, settings.symfony_base_url, run_id, set_id
            )
    except OutcomeUnavailableError as exc:
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={
                "run_id": run_id,
                "correlation_run_id": canonical_correlation_id(run_id),
                "dashboard_id": run.dashboard_id,
                "set_id": set_id,
                "run_found": True,
                "source_available": False,
                "error_code": "outcome_source_unavailable",
                "detail": str(exc),
            },
        )

    return JSONResponse(
        status_code=status.HTTP_200_OK,
        content={
            "run_id": run_id,
            "correlation_run_id": outcome.get("correlation_run_id"),
            "dashboard_id": run.dashboard_id,
            "set_id": set_id,
            "run_found": True,
            "source_available": True,
            # Relayé tel quel : si Symfony a tronqué l'agrégat (run très large), on le
            # signale (data_complete est alors faux côté source) plutôt que de le masquer.
            "truncated": outcome.get("truncated"),
            "data_complete": outcome.get("data_complete"),
            "summary": outcome.get("summary"),
            "by_set": outcome.get("by_set"),
            "by_profile": outcome.get("by_profile"),
            "by_exchange": outcome.get("by_exchange"),
            "by_symbol": outcome.get("by_symbol"),
        },
    )
