"""Helpers d'accès aux tables d'orchestration (DB-001).

Fonctions volontairement minimales et testables. Elles ne sont **pas** câblées
dans les routers/services existants : la gestion applicative (CRUD, lecture des
sets au run) est l'objet de PY-002. Elles servent de fondation et de surface de
test pour le schéma.
"""

from __future__ import annotations

from datetime import datetime
from typing import Any, Mapping, Optional, Sequence, Tuple

from sqlalchemy import delete, select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app.db.models import Dashboard, OrchestrationLock, OrchestrationSet, Run, RunSet

# Colonnes mutables recopiées lors d'un upsert (la PK et created_at sont exclus).
_RUN_UPDATABLE = (
    "dashboard_id", "ok", "status", "idempotency_key", "total_calls",
    "success_count", "failed_count", "started_at", "finished_at", "expires_at",
    "last_json",
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


def resolve_run(
    session: Session, run_id: str, idempotency_key: Optional[str] = None
) -> Optional[Run]:
    """Résout un run existant par ``run_id`` puis, à défaut, par ``idempotency_key``.

    Surface publique du court-circuit d'idempotence (SAFE-002) : le runner inspecte
    l'état du run existant (terminal success → replay, terminal non-ok → reprise,
    ``running`` non périmé → en vol) **avant** de poser le claim. Même résolution
    que ``_resolve_existing_run`` (utilisé par ``record_run``), donc le claim posé
    ensuite via ``record_run`` retombe sur exactement la même ligne.
    """
    existing = session.get(Run, run_id)
    if existing is None and idempotency_key:
        existing = get_run_by_idempotency_key(session, idempotency_key)
    return existing


def _resolve_existing_run(session: Session, run: Run) -> Optional[Run]:
    """Cherche le run existant par ``run_id`` puis, à défaut, par ``idempotency_key``."""
    return resolve_run(session, run.run_id, run.idempotency_key)


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


def _lock_run(session: Session, run_id: str) -> Optional[Run]:
    """Verrouille la ligne ``runs`` (``SELECT ... FOR UPDATE``) et rafraîchit l'instance.

    Sur PostgreSQL, le verrou ligne sérialise le read-modify-write du claim : un
    claimer concurrent **bloque** ici jusqu'au commit du gagnant, puis relit l'état
    à jour. ``populate_existing`` force le rafraîchissement des attributs (statut,
    ``expires_at``) à partir de la lecture verrouillée, sinon l'``identity map``
    renverrait les valeurs périmées du premier read et l'évaluation du claim actif se
    ferait sur des données obsolètes. Sur SQLite, ``FOR UPDATE`` est ignoré (accès
    sérialisé par le verrou base) : aucun impact sur les tests.
    """
    return session.scalars(
        select(Run)
        .where(Run.run_id == run_id)
        .with_for_update()
        .execution_options(populate_existing=True)
    ).first()


def _claim_locked(session: Session, run_id: str, run: Run, classify) -> Tuple[Run, Optional[str]]:
    """Tranche le claim sur une ligne existante, **sous verrou ligne** (atomique).

    Verrouille la ligne puis demande à l'appelant de la **classer** sur l'état frais :
    ``classify(locked)`` renvoie une *raison de céder* (chaîne opaque, ex. ``"replay"``
    quand la ligne est un succès terminal, ``"in_flight"`` quand un run concurrent est
    en vol) ou ``None`` pour **reprendre** la ligne (terminal non-ok / claim périmé →
    nouveau claim). Quand on cède, la ligne **n'est pas écrasée** : on la retourne
    telle quelle pour que l'appelant rejoue/réplique. La ligne peut avoir disparu entre
    la résolution et le verrou (suppression, non attendue en prod) : insertion fraîche.
    """
    locked = _lock_run(session, run_id)
    if locked is None:
        with session.begin_nested():  # SAVEPOINT : isole l'échec d'insertion
            session.add(run)
            session.flush()
        return run, None
    reason = classify(locked)
    if reason is not None:
        return locked, reason  # on cède (replay/in-flight) sans écraser la ligne
    return _update_existing_run(session, locked, run), None


def claim_run(session: Session, run: Run, *, classify) -> Tuple[Run, Optional[str]]:
    """Pose un claim de run de façon atomique. Retourne ``(run, yield_reason)``.

    Variante « compare-and-set » de ``record_run`` dédiée au **claim « en vol »**
    (SAFE-002). ``yield_reason`` est ``None`` quand le claim est obtenu (la ligne a été
    créée ou reprise vers le nouveau claim) ; sinon c'est la chaîne renvoyée par
    ``classify`` (ex. ``"replay"``/``"in_flight"``) et la ligne existante est retournée
    **sans modification** pour que l'appelant rejoue le succès persisté ou réplique
    l'état en vol — au lieu d'écraser la ligne partagée et de re-soumettre du travail.

    ``classify(existing)`` est fourni par l'appelant (il connaît la sémantique des
    statuts/TTL) : il **doit** céder (raison non ``None``) sur un succès terminal
    (→ replay) et sur un claim non périmé d'un autre run (→ in-flight), et renvoyer
    ``None`` pour les états repris (terminal non-ok, claim périmé).

    Deux courses sont neutralisées **sous l'état le plus frais possible** :

    - **INSERT concurrent** (la ligne n'existait pas encore) : savepoint + rattrapage
      de la violation d'unicité comme ``record_run`` ; le perdant relit le gagnant ;
    - **UPDATE concurrent** (la ligne existe déjà) : le read-modify-write se fait **sous
      ``SELECT ... FOR UPDATE``** (``_claim_locked``) — le perdant bloque, relit l'état
      committé par le gagnant (claim actif **ou succès finalisé**) et cède au lieu de
      l'écraser.
    """
    existing = _resolve_existing_run(session, run)
    if existing is not None:
        return _claim_locked(session, existing.run_id, run, classify)

    try:
        with session.begin_nested():  # SAVEPOINT : isole l'échec d'insertion
            session.add(run)
            session.flush()
        return run, None
    except IntegrityError:
        # Un writer concurrent a inséré la même clé/PK entre le lookup et le flush.
        if run in session:
            session.expunge(run)
        existing = _resolve_existing_run(session, run)
        if existing is None:
            raise  # conflit sur une autre contrainte : ne pas masquer
        # La ligne gagnante existe : on tranche sous verrou (le gagnant a pu, depuis,
        # finir — succès terminal — ou être encore en vol ; on classe l'état frais).
        return _claim_locked(session, existing.run_id, run, classify)


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


# ---------------------------------------------------------------------------
# Lecture de l'historique des runs (PY-006)
#
# Helpers en LECTURE SEULE : l'écriture de l'historique est faite par PY-005
# (``record_run``/``record_run_set``). Ils servent la surface GET exposée au
# cockpit (dernier JSON global d'un run + dernier JSON par set).
# ---------------------------------------------------------------------------


def list_runs(
    session: Session,
    *,
    dashboard_id: Optional[int] = None,
    limit: int = 20,
    offset: int = 0,
) -> Sequence[Run]:
    """Retourne les runs du plus récent au plus ancien (pagination bornée).

    Filtre optionnel par ``dashboard_id``. Tri secondaire stable par ``run_id``
    pour que des runs partageant le même ``created_at`` (granularité grossière en
    test) restent ordonnés de façon déterministe.
    """
    stmt = select(Run)
    if dashboard_id is not None:
        stmt = stmt.where(Run.dashboard_id == dashboard_id)
    stmt = stmt.order_by(Run.created_at.desc(), Run.run_id.desc()).limit(limit).offset(offset)
    return session.scalars(stmt).all()


def get_latest_run(session: Session, *, dashboard_id: Optional[int] = None) -> Optional[Run]:
    """Retourne le run le plus récent (optionnellement d'un dashboard), ou ``None``."""
    stmt = select(Run)
    if dashboard_id is not None:
        stmt = stmt.where(Run.dashboard_id == dashboard_id)
    stmt = stmt.order_by(Run.created_at.desc(), Run.run_id.desc()).limit(1)
    return session.scalar(stmt)


def list_run_sets(session: Session, run_id: str) -> Sequence[RunSet]:
    """Retourne les résultats par set d'un run, triés par ``set_id``."""
    stmt = select(RunSet).where(RunSet.run_id == run_id).order_by(RunSet.set_id.asc())
    return session.scalars(stmt).all()


def get_run_set(session: Session, run_id: str, set_id: str) -> Optional[RunSet]:
    """Retourne le résultat d'un set dans un run par ``(run_id, set_id)``, ou ``None``."""
    return session.scalar(
        select(RunSet).where(RunSet.run_id == run_id, RunSet.set_id == set_id)
    )


# ---------------------------------------------------------------------------
# Locks d'orchestration (SAFE-001)
#
# Sérialise deux runs concurrents sur le même (mtf_profile, exchange,
# market_type, symbol). L'exclusion mutuelle est portée par la contrainte
# UNIQUE sur ``OrchestrationLock.lock_key`` ; un lock expiré (TTL dépassé) est
# *reclaim* par le run suivant. Ces helpers ne committent pas : la transaction
# (courte, posée AVANT les appels Symfony longs) est gérée par l'appelant.
# ---------------------------------------------------------------------------


def build_lock_key(mtf_profile: str, exchange: str, market_type: str, symbol: str) -> str:
    """Clé canonique d'exclusion mutuelle d'un couple ``(profil, symbole)``.

    Forme : ``{profile}|{exchange}|{market_type}|{symbol}``. Les composantes sont
    supposées déjà normalisées par l'appelant (exchange/market_type comme Symfony,
    symbole en MAJUSCULES comme ``_symbols_overlap``) ; on se contente d'un
    ``strip()`` défensif pour que la clé soit stable. Le séparateur ``|`` n'apparaît
    pas dans un identifiant d'exchange/symbole, donc la clé est sans ambiguïté.
    """
    parts = (mtf_profile, exchange, market_type, symbol)
    return "|".join(p.strip() for p in parts)


def purge_expired_locks(session: Session, now: datetime) -> int:
    """Supprime tous les locks expirés (``expires_at <= now``).

    Balayage de garde au démarrage d'un run : évite les fuites si un process
    titulaire a été tué avant le ``finally`` de libération. Retourne le nombre de
    lignes supprimées (best-effort selon le dialecte).
    """
    result = session.execute(
        delete(OrchestrationLock).where(OrchestrationLock.expires_at <= now)
    )
    return result.rowcount or 0


def _acquire_one_lock(session: Session, lock: OrchestrationLock, now: datetime) -> Optional[str]:
    """Tente d'acquérir UN lock. Retourne ``None`` si acquis, sinon le ``run_id`` titulaire.

    Reclaim d'abord un éventuel lock EXPIRÉ sur la même clé (titulaire mort/abandonné)
    via un ``DELETE ... WHERE expires_at <= now`` : la comparaison du TTL est faite
    **côté SQL** (le round-trip SQLite perd le fuseau, donc une comparaison Python
    naïve/aware échouerait). On insère ensuite via un SAVEPOINT : une violation
    d'unicité (lock encore actif, ou course concurrente entre la purge et le flush)
    est rattrapée et on relit le titulaire gagnant plutôt que de propager l'erreur.
    Compatible SQLite et PostgreSQL (pas d'``ON CONFLICT`` dialecte-spécifique).
    """
    # Libère la clé si un lock expiré la détient encore (no-op sinon). Le lock actif
    # n'est PAS supprimé (expires_at > now) : son titulaire est préservé.
    session.execute(
        delete(OrchestrationLock).where(
            OrchestrationLock.lock_key == lock.lock_key,
            OrchestrationLock.expires_at <= now,
        )
    )
    session.flush()

    try:
        with session.begin_nested():  # SAVEPOINT : isole l'échec d'insertion
            session.add(lock)
            session.flush()
    except IntegrityError:
        # Clé encore détenue par un run actif (ou course concurrente) : fail-closed.
        if lock in session:
            session.expunge(lock)
        existing = session.scalar(
            select(OrchestrationLock).where(OrchestrationLock.lock_key == lock.lock_key)
        )
        if existing is None:
            raise  # conflit sur une autre contrainte : ne pas masquer
        return existing.run_id
    return None


def acquire_set_locks(
    session: Session, locks: Sequence[OrchestrationLock], now: datetime
) -> Optional[Tuple[str, str]]:
    """Acquiert **tout ou rien** l'ensemble des locks d'un set.

    Retourne ``None`` si tous les locks sont acquis. Si un lock est déjà détenu par
    un run actif, libère ceux déjà pris pour ce set (aucun lock résiduel) et
    retourne ``(lock_key, run_id_titulaire)`` du premier conflit.
    """
    acquired: list[OrchestrationLock] = []
    for lock in locks:
        holder = _acquire_one_lock(session, lock, now)
        if holder is not None:
            # Acquisition partielle annulée : on ne laisse aucun lock derrière soi.
            for got in acquired:
                session.delete(got)
            if acquired:
                session.flush()
            return (lock.lock_key, holder)
        acquired.append(lock)
    return None


def release_locks(
    session: Session, *, run_id: str, lock_keys: Optional[Sequence[str]] = None
) -> int:
    """Libère les locks détenus par ``run_id`` (optionnellement restreints à ``lock_keys``).

    Appelé dans le ``finally`` de chaque set (succès/échec/exception). Sans
    ``lock_keys``, libère tous les locks du run. Retourne le nombre de lignes
    supprimées (best-effort selon le dialecte).
    """
    stmt = delete(OrchestrationLock).where(OrchestrationLock.run_id == run_id)
    if lock_keys is not None:
        stmt = stmt.where(OrchestrationLock.lock_key.in_(list(lock_keys)))
    result = session.execute(stmt)
    return result.rowcount or 0
