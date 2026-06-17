"""Gestion des dashboards et des sets d'orchestration (PY-002).

CRUD REST câblé sur la couche DB (DB-001) :

- ``/dashboards``                          : création / liste ;
- ``/dashboards/{id}``                     : lecture / mise à jour / suppression ;
- ``/dashboards/{id}/sets``                : création / liste des sets ;
- ``/dashboards/{id}/sets/{set_id}``       : lecture / mise à jour / suppression.

Le câblage de ces sets dans l'exécution parallèle de ``/orchestrator/run`` est
l'objet de PY-005 ; cette PR ne livre que la configuration (sets « prêts »).
"""

from __future__ import annotations

from contextlib import contextmanager
from typing import Iterator

from fastapi import APIRouter, Depends, HTTPException, Response, status
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app.db import repositories as repo
from app.db.engine import get_session
from app.db.models import Dashboard, OrchestrationSet
from app.schemas import (
    DashboardCreate,
    DashboardRead,
    DashboardUpdate,
    Exchange,
    SetCreate,
    SetRead,
    SetUpdate,
    assert_live_allowed,
)

router = APIRouter(prefix="/dashboards", tags=["dashboards"])


def _require_dashboard(session: Session, dashboard_id: int) -> Dashboard:
    dashboard = repo.get_dashboard(session, dashboard_id)
    if dashboard is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, detail="dashboard not found")
    return dashboard


def _require_set(session: Session, dashboard_id: int, set_id: str) -> OrchestrationSet:
    a_set = repo.get_set(session, dashboard_id, set_id)
    if a_set is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, detail="set not found")
    return a_set


@contextmanager
def _conflict_guard(session: Session, detail: str) -> Iterator[None]:
    """Transforme toute violation d'unicité en ``409 Conflict``.

    La violation peut survenir au ``flush()`` (dans le repo) **ou** au commit :
    on englobe donc la mutation et le commit, puis on rollback proprement.
    """
    try:
        yield
        session.commit()
    except IntegrityError:
        session.rollback()
        raise HTTPException(status.HTTP_409_CONFLICT, detail=detail)


# --- Dashboards -------------------------------------------------------------


@router.get("", response_model=list[DashboardRead])
def list_dashboards(session: Session = Depends(get_session)) -> list[Dashboard]:
    return list(repo.list_dashboards(session))


@router.post("", response_model=DashboardRead, status_code=status.HTTP_201_CREATED)
def create_dashboard(body: DashboardCreate, session: Session = Depends(get_session)) -> Dashboard:
    with _conflict_guard(session, detail=f"dashboard name '{body.name}' already exists"):
        dashboard = repo.create_dashboard(
            session, name=body.name, enabled=body.enabled, description=body.description
        )
    session.refresh(dashboard)
    return dashboard


@router.get("/{dashboard_id}", response_model=DashboardRead)
def get_dashboard(dashboard_id: int, session: Session = Depends(get_session)) -> Dashboard:
    return _require_dashboard(session, dashboard_id)


@router.patch("/{dashboard_id}", response_model=DashboardRead)
def update_dashboard(
    dashboard_id: int, body: DashboardUpdate, session: Session = Depends(get_session)
) -> Dashboard:
    dashboard = _require_dashboard(session, dashboard_id)
    with _conflict_guard(session, detail=f"dashboard name '{body.name}' already exists"):
        repo.update_dashboard(session, dashboard, fields=body.model_dump(exclude_unset=True))
    session.refresh(dashboard)
    return dashboard


@router.delete("/{dashboard_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_dashboard(dashboard_id: int, session: Session = Depends(get_session)) -> Response:
    dashboard = _require_dashboard(session, dashboard_id)
    repo.delete_dashboard(session, dashboard)
    session.commit()
    return Response(status_code=status.HTTP_204_NO_CONTENT)


# --- Sets -------------------------------------------------------------------


@router.get("/{dashboard_id}/sets", response_model=list[SetRead])
def list_sets(
    dashboard_id: int,
    enabled_only: bool = False,
    session: Session = Depends(get_session),
) -> list[OrchestrationSet]:
    _require_dashboard(session, dashboard_id)
    return list(repo.list_sets(session, dashboard_id, enabled_only=enabled_only))


@router.post(
    "/{dashboard_id}/sets", response_model=SetRead, status_code=status.HTTP_201_CREATED
)
def create_set(
    dashboard_id: int, body: SetCreate, session: Session = Depends(get_session)
) -> OrchestrationSet:
    _require_dashboard(session, dashboard_id)
    detail = f"set_id '{body.set_id}' already exists in dashboard {dashboard_id}"
    with _conflict_guard(session, detail=detail):
        a_set = repo.create_set(session, dashboard_id, fields=body.model_dump(mode="json"))
    session.refresh(a_set)
    return a_set


@router.get("/{dashboard_id}/sets/{set_id}", response_model=SetRead)
def get_set(
    dashboard_id: int, set_id: str, session: Session = Depends(get_session)
) -> OrchestrationSet:
    _require_dashboard(session, dashboard_id)
    return _require_set(session, dashboard_id, set_id)


@router.patch("/{dashboard_id}/sets/{set_id}", response_model=SetRead)
def update_set(
    dashboard_id: int, set_id: str, body: SetUpdate, session: Session = Depends(get_session)
) -> OrchestrationSet:
    _require_dashboard(session, dashboard_id)
    a_set = _require_set(session, dashboard_id, set_id)
    updates = body.model_dump(mode="json", exclude_unset=True)

    # Le garde-fou live s'applique à l'état résultant : un PATCH peut ne fournir
    # que `dry_run` ou que `exchange`, donc on fusionne avec la ligne persistée.
    effective_exchange = Exchange(updates.get("exchange", a_set.exchange))
    effective_dry_run = updates.get("dry_run", a_set.dry_run)
    try:
        assert_live_allowed(effective_exchange, effective_dry_run)
    except ValueError as exc:
        # 422 littéral : la constante `status.HTTP_422_*` a été renommée selon
        # les versions de Starlette ; l'entier reste stable et non déprécié.
        raise HTTPException(422, detail=str(exc))

    repo.update_set(session, a_set, fields=updates)
    session.commit()
    session.refresh(a_set)
    return a_set


@router.delete(
    "/{dashboard_id}/sets/{set_id}", status_code=status.HTTP_204_NO_CONTENT
)
def delete_set(
    dashboard_id: int, set_id: str, session: Session = Depends(get_session)
) -> Response:
    _require_dashboard(session, dashboard_id)
    a_set = _require_set(session, dashboard_id, set_id)
    repo.delete_set(session, a_set)
    session.commit()
    return Response(status_code=status.HTTP_204_NO_CONTENT)
