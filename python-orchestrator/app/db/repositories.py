"""Helpers d'accès aux tables d'orchestration (DB-001).

Fonctions volontairement minimales et testables. Elles ne sont **pas** câblées
dans les routers/services existants : la gestion applicative (CRUD, lecture des
sets au run) est l'objet de PY-002. Elles servent de fondation et de surface de
test pour le schéma.
"""

from __future__ import annotations

from typing import Any, Mapping, Optional, Sequence

from sqlalchemy import select
from sqlalchemy.exc import IntegrityError
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


# ---------------------------------------------------------------------------
# Gestion des dashboards et des sets (PY-002)
#
# CRUD minimal et explicite. Ces helpers ne committent pas : la transaction est
# gérée par l'appelant (router), afin que création + sous-mutations restent
# atomiques et que les violations d'unicité soient rattrapées au commit.
# ---------------------------------------------------------------------------


def list_dashboards(session: Session) -> Sequence[Dashboard]:
    """Retourne tous les dashboards, triés par nom."""
    return session.scalars(select(Dashboard).order_by(Dashboard.name.asc())).all()


def create_dashboard(
    session: Session,
    *,
    name: str,
    enabled: bool = True,
    description: Optional[str] = None,
) -> Dashboard:
    """Crée un dashboard et le flush (l'unicité du nom est vérifiée au commit)."""
    dashboard = Dashboard(name=name, enabled=enabled, description=description)
    session.add(dashboard)
    session.flush()
    return dashboard


def update_dashboard(session: Session, dashboard: Dashboard, *, fields: Mapping[str, Any]) -> Dashboard:
    """Applique une mise à jour partielle à un dashboard existant."""
    for name, value in fields.items():
        setattr(dashboard, name, value)
    session.flush()
    return dashboard


def delete_dashboard(session: Session, dashboard: Dashboard) -> None:
    """Supprime un dashboard (cascade ses sets ; SET NULL sur ses runs)."""
    session.delete(dashboard)
    session.flush()


def list_sets(
    session: Session, dashboard_id: int, *, enabled_only: bool = False
) -> Sequence[OrchestrationSet]:
    """Retourne les sets d'un dashboard, triés par priorité décroissante.

    ``enabled_only=True`` ne renvoie que les sets actifs (même filtre que
    ``list_active_sets``, mais sans contrainte sur la présence du dashboard).
    """
    stmt = select(OrchestrationSet).where(OrchestrationSet.dashboard_id == dashboard_id)
    if enabled_only:
        stmt = stmt.where(OrchestrationSet.enabled.is_(True))
    stmt = stmt.order_by(OrchestrationSet.priority.desc(), OrchestrationSet.set_id.asc())
    return session.scalars(stmt).all()


def get_set(session: Session, dashboard_id: int, set_id: str) -> Optional[OrchestrationSet]:
    """Retourne un set par ``(dashboard_id, set_id)``, ou ``None``."""
    return session.scalar(
        select(OrchestrationSet).where(
            OrchestrationSet.dashboard_id == dashboard_id,
            OrchestrationSet.set_id == set_id,
        )
    )


def create_set(session: Session, dashboard_id: int, *, fields: Mapping[str, Any]) -> OrchestrationSet:
    """Crée un set rattaché à un dashboard (unicité ``(dashboard_id, set_id)`` au commit)."""
    a_set = OrchestrationSet(dashboard_id=dashboard_id, **dict(fields))
    session.add(a_set)
    session.flush()
    return a_set


def update_set(session: Session, a_set: OrchestrationSet, *, fields: Mapping[str, Any]) -> OrchestrationSet:
    """Applique une mise à jour partielle à un set existant."""
    for name, value in fields.items():
        setattr(a_set, name, value)
    session.flush()
    return a_set


def delete_set(session: Session, a_set: OrchestrationSet) -> None:
    """Supprime un set."""
    session.delete(a_set)
    session.flush()


def get_run(session: Session, run_id: str) -> Optional[Run]:
    """Retourne un run par son ``run_id``, ou ``None``."""
    return session.get(Run, run_id)


def get_run_by_idempotency_key(session: Session, idempotency_key: str) -> Optional[Run]:
    """Retourne un run par sa clé d'idempotence, ou ``None``."""
    return session.scalar(select(Run).where(Run.idempotency_key == idempotency_key))


def _resolve_existing_run(session: Session, run: Run) -> Optional[Run]:
    """Cherche le run existant par ``run_id`` puis, à défaut, par ``idempotency_key``."""
    existing = session.get(Run, run.run_id)
    if existing is None and run.idempotency_key:
        existing = get_run_by_idempotency_key(session, run.idempotency_key)
    return existing


def _update_existing_run(session: Session, existing: Run, run: Run) -> Run:
    # Mise à jour partielle : un champ non renseigné (None) ne doit pas effacer
    # l'idempotency_key/dashboard_id déjà stockés.
    _apply(existing, run, _RUN_UPDATABLE, clear_nullable=False)
    session.flush()
    return existing


def record_run(session: Session, run: Run) -> Run:
    """Upsert idempotent d'un run.

    Résout l'existant par ``run_id`` puis, à défaut, par ``idempotency_key`` :
    un retry réutilisant la même clé met à jour le run existant au lieu de violer
    ``uq_runs_idempotency_key``.

    Le chemin d'insertion est protégé contre une **course concurrente** : si deux
    writers manquent tous deux le lookup puis insèrent la même clé, le flush en
    conflit (`uq_runs_idempotency_key` ou PK) est rattrapé via un savepoint, et on
    recharge la ligne gagnante pour la mettre à jour — l'idempotence est donc
    réellement garantie sous retry/concurrence. Retourne l'instance persistée.
    """
    existing = _resolve_existing_run(session, run)
    if existing is not None:
        return _update_existing_run(session, existing, run)

    try:
        with session.begin_nested():  # SAVEPOINT : isole l'échec d'insertion
            session.add(run)
            session.flush()
        return run
    except IntegrityError:
        # Un autre writer a inséré la même clé/PK entre le lookup et le flush.
        if run in session:
            session.expunge(run)
        existing = _resolve_existing_run(session, run)
        if existing is None:
            raise  # conflit sur une autre contrainte : ne pas masquer
        return _update_existing_run(session, existing, run)


def _resolve_existing_run_set(session: Session, run_set: RunSet) -> Optional[RunSet]:
    return session.scalar(
        select(RunSet).where(
            RunSet.run_id == run_set.run_id,
            RunSet.set_id == run_set.set_id,
        )
    )


def _update_existing_run_set(session: Session, existing: RunSet, run_set: RunSet) -> RunSet:
    # Snapshot du dernier résultat : les champs nullable obsolètes
    # (ex. error d'un échec précédent) doivent pouvoir être effacés.
    _apply(existing, run_set, _RUN_SET_UPDATABLE, clear_nullable=True)
    session.flush()
    return existing


def record_run_set(session: Session, run_set: RunSet) -> RunSet:
    """Upsert idempotent du résultat d'un set dans un run.

    Résout l'existant par ``(run_id, set_id)`` : un retry du même set dans le même
    run met à jour le dernier résultat au lieu de violer ``uq_run_sets_run_set``.
    Le chemin d'insertion est protégé contre une course concurrente (savepoint +
    rattrapage de la violation d'unicité, puis rechargement) comme ``record_run``.
    """
    existing = _resolve_existing_run_set(session, run_set)
    if existing is not None:
        return _update_existing_run_set(session, existing, run_set)

    try:
        with session.begin_nested():  # SAVEPOINT : isole l'échec d'insertion
            session.add(run_set)
            session.flush()
        return run_set
    except IntegrityError:
        if run_set in session:
            session.expunge(run_set)
        existing = _resolve_existing_run_set(session, run_set)
        if existing is None:
            raise
        return _update_existing_run_set(session, existing, run_set)
