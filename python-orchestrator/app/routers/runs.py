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

from typing import Awaitable, Callable, Optional

import httpx
from fastapi import APIRouter, Depends, HTTPException, Query, status
from sqlalchemy.orm import Session

from app.db import repositories as repo
from app.db.engine import get_session
from app.db.models import RunSet
from app.schemas import RunDetailRead, RunOutcomeRead, RunSetRead, RunSummaryRead
from app.services.symfony_client import fetch_run_trade_outcome
from app.settings import get_settings

router = APIRouter(prefix="/runs", tags=["runs"])

# Borne la taille de page pour ne jamais renvoyer un historique non borné.
_MAX_PAGE_SIZE = 100

# Règle de réconciliation du run_id (OBS-003) : `position_trade_analysis.run_id`
# (== `trade_lifecycle_event.run_id`) est un VARCHAR(64), alors que l'orchestration
# `runs.run_id` peut atteindre 255 (forme hachée = 68). Le runner Symfony tronque
# X-Run-Id à 64 avant de le stocker ; on interroge donc la vue avec le même
# `run_id[:64]` pour que les deux côtés utilisent strictement le même identifiant.
_SYMFONY_RUN_ID_MAXLEN = 64

# Timeout (s) de l'appel de lecture Symfony pour le rapprochement (lecture seule,
# court : pas de run/dispatch derrière, contrairement à `_HTTP_TIMEOUT` du runner).
_OUTCOME_HTTP_TIMEOUT = 30.0

# Type de la fonction de récupération de l'agrégat, injectable (tests).
OutcomeFetcher = Callable[[str], Awaitable[dict]]


async def _fetch_outcome_via_symfony(run_id: str) -> dict:
    """Récupère l'agrégat de rapprochement auprès de Symfony (HTTP, lecture seule)."""
    settings = get_settings()
    async with httpx.AsyncClient(timeout=_OUTCOME_HTTP_TIMEOUT) as client:
        return await fetch_run_trade_outcome(client, settings.symfony_base_url, run_id)


def get_outcome_fetcher() -> OutcomeFetcher:
    """Dépendance fournissant le fetcher d'agrégat (surchargée par les tests).

    Le runtime appelle Symfony en HTTP (séparation propre, pas de couplage de
    l'orchestrateur au schéma interne Symfony) ; les tests injectent un stub.
    """
    return _fetch_outcome_via_symfony


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


@router.get("/{run_id}/outcome", response_model=RunOutcomeRead)
async def get_run_outcome(
    run_id: str,
    session: Session = Depends(get_session),
    fetch: OutcomeFetcher = Depends(get_outcome_fetcher),
) -> RunOutcomeRead:
    """Rapproche un run d'orchestration de ses trades (OBS-003), en LECTURE SEULE.

    Relie le run (schéma ``orchestration``) à la vue Symfony
    ``position_trade_analysis`` par ``run_id`` : nombre de trades, PnL net (USDT / R),
    win-rate, MFE/MAE moyens et médians, durée de détention — avec ventilation par
    symbole. Le PnL n'est jamais recalculé (Symfony est la source).

    Fail-safe : un run inconnu, un run sans trade ou une vue indisponible renvoient un
    agrégat vide explicite (jamais un 500). ``run_found`` et ``source_available``
    qualifient le résultat.
    """
    run = repo.get_run(session, run_id)
    # Réconciliation : on requête la vue avec le run_id tronqué à 64 (forme réellement
    # stockée côté Symfony). `reconciled_run_id` documente l'identifiant utilisé.
    reconciled_run_id = run_id[:_SYMFONY_RUN_ID_MAXLEN]
    source = await fetch(reconciled_run_id)
    return RunOutcomeRead.from_source(
        run_id=run_id,
        reconciled_run_id=reconciled_run_id,
        run_found=run is not None,
        source=source,
    )


@router.get("/{run_id}/sets/{set_id}", response_model=RunSetRead)
def get_run_set(
    run_id: str, set_id: str, session: Session = Depends(get_session)
) -> RunSet:
    """Dernier JSON d'un set dans un run (payload envoyé + réponse Symfony brute)."""
    run_set = repo.get_run_set(session, run_id, set_id)
    if run_set is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, detail="run set not found")
    return run_set
