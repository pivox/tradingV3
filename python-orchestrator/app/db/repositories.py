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

# Colonnes mutables recopiées lors d'un upsert (la PK et created_at sont exclus).
_RUN_UPDATABLE = (
    "dashboard_id", "ok", "status", "idempotency_key", "total_calls",
    "success_count", "failed_count", "started_at", "finished_at", "last_json",
)
_RUN_SET_UPDATABLE = (
    "set_ref_id", "payload_sent", "response_json", "ok", "error", "duration_ms",
)


def _apply(target: object, source: object, fields: Sequence[str], *, clear_nullable: bool) -> None:
    """Recopie ``fields`` de ``source`` vers ``target`` lors d'un upsert.

    Une valeur non ``None`` est toujours recopiée. Le traitement d'un ``None``
    dépend du mode :

    - ``clear_nullable=True`` (snapshot complet, ex. résultat d'un set) : une
      colonne **NULLABLE** est remise à ``None`` — le dernier résultat fait foi
      (ex. ``error`` effacée quand un set repasse au succès).
    - ``clear_nullable=False`` (mise à jour partielle, ex. transition de statut
      d'un run) : un ``None`` n'écrase jamais — on préserve les champs
      d'identité/contexte non renseignés (``idempotency_key``, ``dashboard_id``…).

    Dans tous les cas, une colonne **NOT NULL** n'est jamais écrasée par un
    ``None`` (pas de violation de contrainte ni d'effacement de ``server_default``).
    """
    columns = target.__table__.c
    for name in fields:
        value = getattr(source, name)
        if value is None and not (clear_nullable and columns[name].nullable):
            continue
        setattr(target, name, value)


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


def get_run_by_idempotency_key(session: Session, idempotency_key: str) -> Optional[Run]:
    """Retourne un run par sa clé d'idempotence, ou ``None``."""
    return session.scalar(select(Run).where(Run.idempotency_key == idempotency_key))


def record_run(session: Session, run: Run) -> Run:
    """Upsert idempotent d'un run.

    Résout l'existant par ``run_id`` puis, à défaut, par ``idempotency_key`` :
    un retry réutilisant la même clé met à jour le run existant au lieu de violer
    ``uq_runs_idempotency_key``. Retourne l'instance persistée après flush.
    """
    existing = session.get(Run, run.run_id)
    if existing is None and run.idempotency_key:
        existing = get_run_by_idempotency_key(session, run.idempotency_key)

    if existing is not None:
        # Mise à jour partielle : un champ non renseigné (None) ne doit pas
        # effacer l'idempotency_key/dashboard_id déjà stockés.
        _apply(existing, run, _RUN_UPDATABLE, clear_nullable=False)
        session.flush()
        return existing

    session.add(run)
    session.flush()
    return run


def record_run_set(session: Session, run_set: RunSet) -> RunSet:
    """Upsert idempotent du résultat d'un set dans un run.

    Résout l'existant par ``(run_id, set_id)`` : un retry du même set dans le même
    run met à jour le dernier résultat au lieu de violer ``uq_run_sets_run_set``.
    """
    existing = session.scalar(
        select(RunSet).where(
            RunSet.run_id == run_set.run_id,
            RunSet.set_id == run_set.set_id,
        )
    )
    if existing is not None:
        # Snapshot du dernier résultat : les champs nullable obsolètes
        # (ex. error d'un échec précédent) doivent pouvoir être effacés.
        _apply(existing, run_set, _RUN_SET_UPDATABLE, clear_nullable=True)
        session.flush()
        return existing

    session.add(run_set)
    session.flush()
    return run_set
