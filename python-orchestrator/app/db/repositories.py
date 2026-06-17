"""Helpers d'accès aux tables d'orchestration (DB-001).

Fonctions volontairement minimales et testables. Elles ne sont **pas** câblées
dans les routers/services existants : la gestion applicative (CRUD, lecture des
sets au run) est l'objet de PY-002. Elles servent de fondation et de surface de
test pour le schéma.
"""

from __future__ import annotations

from typing import Optional, Sequence

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.db.models import Dashboard, OrchestrationSet, Run, RunSet


def get_dashboard(session: Session, dashboard_id: int) -> Optional[Dashboard]:
    """Retourne un dashboard par son id, ou ``None``."""
    return session.get(Dashboard, dashboard_id)


def get_dashboard_by_name(session: Session, name: str) -> Optional[Dashboard]:
    """Retourne un dashboard par son nom unique, ou ``None``."""
    return session.scalar(select(Dashboard).where(Dashboard.name == name))


def list_active_sets(session: Session, dashboard_id: int) -> Sequence[OrchestrationSet]:
    """Retourne les sets actifs d'un dashboard, triés par priorité décroissante.

    Reproduit le tri du fournisseur in-memory (``services/sets.list_active_sets``)
    pour que PY-002 puisse basculer la source sans changer le comportement.
    """
    stmt = (
        select(OrchestrationSet)
        .where(
            OrchestrationSet.dashboard_id == dashboard_id,
            OrchestrationSet.enabled.is_(True),
        )
        .order_by(OrchestrationSet.priority.desc(), OrchestrationSet.set_id.asc())
    )
    return session.scalars(stmt).all()


def get_run(session: Session, run_id: str) -> Optional[Run]:
    """Retourne un run par son ``run_id``, ou ``None``."""
    return session.get(Run, run_id)


def record_run(session: Session, run: Run) -> Run:
    """Persiste (ou met à jour) un run et le renvoie après flush."""
    merged = session.merge(run)
    session.flush()
    return merged


def record_run_set(session: Session, run_set: RunSet) -> RunSet:
    """Persiste le résultat d'un set dans un run et le renvoie après flush."""
    session.add(run_set)
    session.flush()
    return run_set
