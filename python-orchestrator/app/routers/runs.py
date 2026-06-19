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

from fastapi import APIRouter, Depends, HTTPException, Query, status
from sqlalchemy.orm import Session

from app.db import repositories as repo
from app.db.engine import get_session
from app.db.models import RunSet
from app.schemas import RunDetailRead, RunSetRead, RunSummaryRead

router = APIRouter(prefix="/runs", tags=["runs"])

# Borne la taille de page pour ne jamais renvoyer un historique non borné.
_MAX_PAGE_SIZE = 100


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
