"""Endpoint d'orchestration : ``POST /orchestrator/run``.

SF-002b / PY-002 : l'orchestrateur récupère l'état ouvert (positions/ordres)
UNE seule fois par couple ``(exchange, market_type)`` via
``GET /api/exchange/open-state``, puis exécute chaque set ``mtf_run`` en
appelant ``POST /api/mtf/run`` avec ``sync_tables=false`` et le snapshot mis en
cache. Symfony ne refait donc aucun fetch exchange par set.

Concurrence bornée par ``asyncio.Semaphore(max_concurrency)``.

Fail-closed live : si le fetch du snapshot échoue pour un couple, les sets
**live** (``dry_run=false``) de ce couple sont marqués en erreur (on ne trade
pas à l'aveugle). Les sets dry-run peuvent continuer sans snapshot.
"""

from __future__ import annotations

import asyncio
import hashlib
import math
import re
import time
import uuid
from datetime import datetime, timedelta, timezone
from typing import Any, Dict, List, Optional

import httpx
from fastapi import APIRouter, Depends
from sqlalchemy import delete, select
from sqlalchemy.orm import Session

from app.db import repositories
from app.db.engine import get_session
from app.db.models import OrchestrationLock, OrchestrationSet, Run, RunSet
from app.schemas import (
    Action,
    LIVE_FORBIDDEN_EXCHANGES,
    RunRequest,
    RunResponse,
    RunStatus,
    RunSummary,
)
from app.services.symfony_client import (
    OpenStateUnavailableError,
    SnapshotKey,
    fetch_open_state,
    run_persisted_set,
    snapshot_key,
)
from app.settings import get_settings

router = APIRouter(tags=["orchestrator"])

# Timeout (s) des appels Symfony, aligné sur le worker Temporal historique.
_HTTP_TIMEOUT = 900.0

# Longueur max des colonnes persistées `runs.run_id` / `runs.idempotency_key`
# (String(255)). Au-delà, on hache de façon déterministe pour ne pas faire échouer
# l'INSERT après coup (run déjà exécuté) sur PostgreSQL.
_MAX_PERSISTED_LEN = 255

# Un `run_id` est à la fois la PK `runs.run_id` ET un identifiant adressable en URL
# (`GET /runs/{run_id}`, PY-006). Les routes à segment simple ne matchent pas les
# slashes : un `run_id` dérivé d'une `idempotency_key`/`dashboard_id` contenant
# `/` (ex. `temporal/dash/2026-06-19`) serait persisté mais non récupérable. On
# restreint donc le `run_id` aux caractères sûrs d'un segment de chemin ; tout le
# reste est haché (cf. `_resolve_run_id`).
_SAFE_RUN_ID = re.compile(r"^[A-Za-z0-9_.\-]+$")

# Exchanges dont le live est interdit (OKX/Hyperliquid), en chaînes pour comparer
# aux colonnes ORM. Le validateur de schéma bloque déjà toute persistance live ;
# ce miroir sert de garde-fou défense-en-profondeur au moment du run.
_LIVE_FORBIDDEN_EXCHANGES = frozenset(e.value for e in LIVE_FORBIDDEN_EXCHANGES)


def _resolve_run_id(request: Optional[RunRequest]) -> str:
    """Dérive un ``run_id`` stable depuis le contexte, sinon en génère un.

    L'idempotence est portée par l'appelant (Temporal/front) :
    - ``idempotency_key`` explicite -> identifiant stable ;
    - sinon ``dashboard_id`` + ``tick_timestamp`` -> identifiant dérivé stable ;
    - sinon (aucun contexte) -> identifiant aléatoire non idempotent.
    """
    run_id = None
    if request is not None:
        # Une clé blanche/vide est traitée comme absente (cohérent avec
        # `_persist_run` qui la normalise en None) : sinon `run_   ` serait un
        # identifiant « stable » et des appels répétés à clé blanche écraseraient
        # le même historique au lieu d'obtenir des run_id frais.
        if request.idempotency_key and request.idempotency_key.strip():
            run_id = f"run_{request.idempotency_key}"
        elif request.dashboard_id and request.tick_timestamp:
            stamp = request.tick_timestamp.astimezone(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
            run_id = f"run_{request.dashboard_id}_{stamp}"
    if run_id is None:
        stamp = datetime.now(timezone.utc).strftime("%Y%m%d_%H%M%S")
        run_id = f"run_{stamp}_{uuid.uuid4().hex[:6]}"
    # Borne la PK `runs.run_id` (String(255)) ET garantit un identifiant URL-safe
    # (récupérable via `GET /runs/{run_id}`) : une idempotency_key/dashboard_id
    # surdimensionnée ferait échouer l'INSERT après l'exécution du run, et une clé
    # porteuse de `/` (ou d'autres caractères hors segment de chemin) produirait un
    # run_id non adressable. Hash déterministe dans les deux cas => idempotence
    # préservée (même entrée => même run_id), historique non perdu ET relisible.
    if len(run_id) > _MAX_PERSISTED_LEN or not _SAFE_RUN_ID.match(run_id):
        run_id = "run_" + hashlib.sha256(run_id.encode()).hexdigest()
    return run_id


def _resolve_status(success: int, failed: int) -> RunStatus:
    """Dérive le statut agrégé d'un run ayant au moins un set exécuté."""
    if failed == 0:
        return "success"
    if success == 0:
        return "failed"
    return "partial_failure"


def _resolve_dashboard_id(request: Optional[RunRequest]) -> Optional[int]:
    """Convertit le ``dashboard_id`` (chaîne) de la requête en entier, ou ``None``.

    Un ``dashboard_id`` absent ou non numérique ne résout aucun set : le run est
    alors un ``no_sets`` (aucun appel Symfony).
    """
    if request is None or request.dashboard_id is None:
        return None
    try:
        return int(request.dashboard_id)
    except (TypeError, ValueError):
        return None


def _no_sets_response(run_id: str) -> RunResponse:
    """Réponse ``no_sets`` (ok=false) : aucun set actif à exécuter.

    Contrat conservé (cf. ``temporal.md``) : ``ok=false`` n'est pas un succès
    Temporal, donc un tick sans set ne valide pas le schedule.
    """
    return RunResponse(
        ok=False,
        run_id=run_id,
        status="no_sets",
        summary=RunSummary(total_calls=0, success=0, failed=0),
    )


def _result_error(result: Dict[str, Any]) -> Optional[str]:
    """Message d'erreur d'un set en échec, sinon ``None``.

    Un corps dict (réponse Symfony structurée) n'est pas un message d'erreur : on
    remonte alors le statut métier. Un corps string (skip fail-closed, conflit,
    erreur HTTP) est lui-même le message.
    """
    if result.get("ok"):
        return None
    body = result.get("body")
    if isinstance(body, str):
        return body
    if isinstance(body, dict):
        # Symfony peut renvoyer HTTP 200 « success » AVEC des erreurs
        # (is_business_success traite ce cas comme un échec) : on remonte le détail
        # plutôt que le statut trompeur, pour une histoire de run exploitable.
        data = body.get("data") if isinstance(body.get("data"), dict) else {}
        errors = body.get("errors")
        if errors is None:
            errors = data.get("errors")
        if errors:
            return "; ".join(str(e) for e in errors) if isinstance(errors, list) else str(errors)
        # RunnerController renvoie HTTP 500 sous la forme {"status":"error","message": ...}
        # (exception Symfony) : on remonte ce message exploitable plutôt que le seul
        # statut « error », sinon le détail actionnable est perdu dans l'historique.
        message = body.get("message") or data.get("message")
        if message:
            return str(message)
        return body.get("status") or "business failure"
    return None


def _set_detail(result: Dict[str, Any]) -> Dict[str, Any]:
    """Détail d'un set pour le ``last_json`` agrégé (le corps brut va dans RunSet)."""
    return {
        "set_id": result.get("set_id"),
        "ok": bool(result.get("ok")),
        "status": result.get("status"),
        "business_status": result.get("business_status"),
        "error": _result_error(result),
        "duration_ms": result.get("duration_ms"),
    }


def _persist_run(
    session: Session,
    *,
    run_id: str,
    dashboard_id: int,
    request: Optional[RunRequest],
    ok: bool,
    status: RunStatus,
    summary: RunSummary,
    started_at: datetime,
    finished_at: datetime,
    mtf_sets: List[Any],
    results: List[Dict[str, Any]],
) -> str:
    """Persiste l'historique du run : un ``Run`` global + un ``RunSet`` par set.

    Retourne le ``run_id`` réellement persisté : si ``record_run`` résout un run
    existant par ``idempotency_key`` dont la PK diffère du run_id dérivé, l'appelant
    doit renvoyer ce run_id-là (sinon le client reçoit un id introuvable).

    ``last_json`` agrège le résumé et le détail par set (le « dernier JSON » de la
    doc). ``record_run``/``record_run_set`` sont des upserts idempotents ; le
    commit est géré ici (la dépendance ``get_session`` ne committe pas).
    """
    idempotency_key = request.idempotency_key if request is not None else None
    # Une clé vide/blanche est traitée comme absente par `_resolve_run_id` (run_id
    # aléatoire) : il faut la normaliser en None ici aussi, sinon on persiste ""
    # dans la colonne UNIQUE `runs.idempotency_key` et un second run à clé vide (avec
    # un run_id différent) violerait la contrainte d'unicité au commit.
    if idempotency_key is not None and not idempotency_key.strip():
        idempotency_key = None
    # Borne la colonne unique `runs.idempotency_key` (String(255)) pour ne pas
    # faire échouer la persistance après coup ; hash déterministe au-delà.
    if idempotency_key is not None and len(idempotency_key) > _MAX_PERSISTED_LEN:
        idempotency_key = "sha256:" + hashlib.sha256(idempotency_key.encode()).hexdigest()
    last_json = {
        "run_id": run_id,
        "dashboard_id": dashboard_id,
        "ok": ok,
        "status": status,
        "summary": summary.model_dump(),
        "started_at": started_at.isoformat(),
        "finished_at": finished_at.isoformat(),
        "sets": [_set_detail(r) for r in results],
    }

    # Les parents (dashboard, sets) ont pu être supprimés pendant les appels
    # Symfony (la transaction de lecture est clôturée avant l'attente). ON DELETE
    # SET NULL ne couvre PAS un INSERT vers un parent disparu : la FK échouerait au
    # commit et tout l'historique du run serait perdu. On neutralise donc les FK
    # périmées en les ré-interrogeant dans la transaction de persistance (le
    # `dashboard_id`/`set_id` réels restent tracés dans `last_json`/`RunSet.set_id`).
    dashboard_ref = (
        dashboard_id if repositories.get_dashboard(session, dashboard_id) is not None else None
    )
    set_ref_ids = [a_set.id for a_set in mtf_sets]
    existing_set_ids = set(
        session.scalars(
            select(OrchestrationSet.id).where(OrchestrationSet.id.in_(set_ref_ids))
        ).all()
    )

    persisted_run = repositories.record_run(
        session,
        Run(
            run_id=run_id,
            dashboard_id=dashboard_ref,
            ok=ok,
            status=status,
            idempotency_key=idempotency_key,
            total_calls=summary.total_calls,
            success_count=summary.success,
            failed_count=summary.failed,
            started_at=started_at,
            finished_at=finished_at,
            last_json=last_json,
        ),
    )
    # record_run peut résoudre un run existant par `idempotency_key` dont le
    # `run_id` diffère du run_id dérivé (cas de retry supporté par le repository) :
    # on réutilise alors le run_id réellement persisté pour la purge et les RunSet,
    # sinon ces lignes pointeraient un parent `runs` inexistant et la FK
    # `run_sets.run_id` casserait au commit (après l'exécution Symfony).
    if persisted_run.run_id != run_id:
        run_id = persisted_run.run_id
        # `last_json` a été bâti avec le run_id dérivé : on l'aligne sur le run_id
        # réellement persisté (réassignation explicite pour la détection de
        # modification du JSON), afin que l'historique stocké et le run_id renvoyé au
        # client soient cohérents et relisibles.
        persisted_run.last_json = {**last_json, "run_id": run_id}

    # Purge des RunSet périmés d'une exécution précédente du MÊME run_id (retry
    # via idempotency_key/dashboard+tick) : si un set a été désactivé/supprimé/
    # passé hors `mtf_run` entre-temps, son ancien RunSet subsisterait alors que le
    # summary/last_json ne le compte plus — historique incohérent pour les lecteurs.
    current_set_ids = {result["set_id"] for result in results}
    session.execute(
        delete(RunSet).where(
            RunSet.run_id == run_id,
            RunSet.set_id.notin_(current_set_ids),
        )
    )

    # L'ordre de `results` suit celui de `mtf_sets` (asyncio.gather préserve
    # l'ordre), donc le zip associe chaque résultat à son set ORM source.
    for a_set, result in zip(mtf_sets, results):
        body = result.get("body")
        repositories.record_run_set(
            session,
            RunSet(
                run_id=run_id,
                set_id=result["set_id"],
                set_ref_id=a_set.id if a_set.id in existing_set_ids else None,
                payload_sent=result.get("payload_sent"),
                response_json=body if isinstance(body, dict) else None,
                ok=bool(result.get("ok")),
                error=_result_error(result),
                duration_ms=result.get("duration_ms"),
            ),
        )

    session.commit()
    return run_id


def _symbols_overlap(a: Any, b: Any) -> bool:
    """Indique si deux sets ciblent au moins un symbole commun.

    Un ``symbols`` vide signifie « tout l'univers actif » (résolu côté Symfony),
    donc il chevauche n'importe quel autre set du même couple.
    """
    if not a.symbols or not b.symbols:
        return True
    # Symfony normalise les symboles en MAJUSCULES (SymbolUniverseResolver::resolve),
    # donc BTCUSDT et btcusdt ciblent le même instrument : comparer normalisé.
    norm_a = {s.strip().upper() for s in a.symbols}
    norm_b = {s.strip().upper() for s in b.symbols}
    return bool(norm_a & norm_b)


def _conflicting_live_set_ids(mtf_sets: List[Any], force_dry_run: bool) -> set:
    """``set_id`` des sets EFFECTIVEMENT live qui se chevauchent dans le batch.

    Deux sets live partageant ``(exchange, market_type)`` et au moins un symbole
    recevraient le même snapshot pré-run (``sync_tables=false``) : le second ne
    verrait pas une position/un ordre ouvert par le premier → trade live dupliqué.
    On rejette ces sets (fail-closed) plutôt que de dispatcher à l'aveugle. Les
    sets dry-run (ou forcés dry) ne posent pas ce problème.
    """
    live = [s for s in mtf_sets if not (s.dry_run or force_dry_run)]
    conflicting: set = set()
    for i, a in enumerate(live):
        for b in live[i + 1:]:
            # Comparaison via snapshot_key (exchange/market_type normalisés) : deux
            # sets ne se chevauchent que s'ils partageraient le MÊME snapshot pré-run.
            if snapshot_key(a) != snapshot_key(b):
                continue
            if _symbols_overlap(a, b):
                conflicting.add(a.set_id)
                conflicting.add(b.set_id)
    return conflicting


def _now() -> datetime:
    """Horloge de l'orchestrateur (UTC, déterministe).

    Aucune contrainte Temporal ici (contrairement au workflow) : on lit l'heure
    système. Indirection dédiée pour pouvoir l'injecter dans les tests (monkeypatch
    de ``orch._now``) sans figer ``datetime.now`` globalement.
    """
    return datetime.now(timezone.utc)


def _lock_specs_for_set(
    a_set: Any, run_id: str, now: datetime, ttl_seconds: int
) -> List[OrchestrationLock]:
    """Construit un ``OrchestrationLock`` par symbole concret d'un set (SAFE-001).

    Symboles normalisés en MAJUSCULES (comme ``_symbols_overlap``) et dédupliqués ;
    ``exchange``/``market_type`` normalisés comme Symfony (via ``snapshot_key``) ;
    ``mtf_profile`` normalisé (casse/espaces). Un set sans symbole concret (univers
    complet) ne produit AUCUN lock : il ne sera de toute façon pas dispatché
    (``run_persisted_set`` exige une sélection matérialisée), donc il n'y a rien à
    sérialiser per-symbole.
    """
    exchange, market_type = snapshot_key(a_set)
    raw_profile = getattr(a_set.mtf_profile, "value", a_set.mtf_profile)
    profile = raw_profile.strip().lower() if isinstance(raw_profile, str) else str(raw_profile)
    expires_at = now + timedelta(seconds=ttl_seconds)
    seen: set = set()
    locks: List[OrchestrationLock] = []
    for raw in a_set.symbols or []:
        if not isinstance(raw, str):
            continue
        symbol = raw.strip().upper()
        if not symbol or symbol in seen:
            continue
        seen.add(symbol)
        locks.append(
            OrchestrationLock(
                lock_key=repositories.build_lock_key(profile, exchange, market_type, symbol),
                mtf_profile=profile,
                exchange=exchange,
                market_type=market_type,
                symbol=symbol,
                run_id=run_id,
                acquired_at=now,
                expires_at=expires_at,
            )
        )
    return locks


async def _collect_snapshots(
    client: httpx.AsyncClient,
    base_url: str,
    mtf_sets: List[Any],
) -> Dict[SnapshotKey, Dict[str, Any]]:
    """Récupère un snapshot d'état ouvert par couple ``(exchange, market_type)``.

    Un seul appel ``GET /api/exchange/open-state`` par couple distinct. Les
    couples dont le fetch échoue restent absents du cache (fail-closed géré par
    l'appelant pour les sets live).
    """
    keys = {snapshot_key(s) for s in mtf_sets}
    snapshots: Dict[SnapshotKey, Dict[str, Any]] = {}
    for exchange, market_type in keys:
        try:
            snapshots[(exchange, market_type)] = await fetch_open_state(
                client, base_url, exchange, market_type
            )
        except OpenStateUnavailableError:
            # Pas de snapshot fiable pour ce couple : on ne met rien en cache.
            continue
    return snapshots


@router.post("/orchestrator/run", response_model=RunResponse)
async def run_orchestrator(
    request: Optional[RunRequest] = None,
    session: Session = Depends(get_session),
) -> RunResponse:
    """Déclenche un run d'orchestration DB-backed (PY-005).

    Lit les sets actifs du dashboard ciblé depuis la base, exécute chacun à partir
    de son ``payload`` persisté (+ snapshot runtime + override ``dry_run``), puis
    persiste l'historique (un ``Run`` global ``last_json`` + un ``RunSet`` par set).
    """
    settings = get_settings()
    run_id = _resolve_run_id(request)

    # Source des sets : la base, scopée par dashboard. Pas de dashboard / aucun
    # set actif => no_sets (aucun appel, aucune persistance).
    dashboard_id = _resolve_dashboard_id(request)
    if dashboard_id is None:
        return _no_sets_response(run_id)

    # Le flag `enabled` du dashboard est un interrupteur de pause global : un
    # dashboard absent ou désactivé ne lance aucun set (list_active_sets ne filtre
    # que `OrchestrationSet.enabled`, pas le dashboard parent).
    dashboard = repositories.get_dashboard(session, dashboard_id)
    if dashboard is None or not dashboard.enabled:
        return _no_sets_response(run_id)

    active_sets = list(repositories.list_active_sets(session, dashboard_id))
    if not active_sets:
        return _no_sets_response(run_id)

    mtf_sets = [s for s in active_sets if s.action == Action.MTF_RUN.value]
    if not mtf_sets:
        # Sets actifs mais aucun à exécuter via /api/mtf/run (actions hors scope).
        return _no_sets_response(run_id)

    # Override run-level : un appelant peut FORCER le dry-run (sécurité). Le
    # forçage ne peut que rendre un set plus sûr — il ne downgrade jamais un
    # set dry en live. Appliqué AVANT le garde fail-closed et la construction
    # du payload pour que `{"dry_run": true}` empêche réellement tout ordre live.
    force_dry_run = request.dry_run is True if request is not None else False

    # Fail-closed : des sets live chevauchants (même exchange/market + symbole
    # partagé) partageraient le même snapshot pré-run et pourraient dupliquer un
    # trade live ; on les rejette avant dispatch.
    conflicting_live_ids = _conflicting_live_set_ids(mtf_sets, force_dry_run)

    # SAFE-001 : sérialisation per-(profil, symbole) ENTRE runs/process via des locks
    # DB. Le garde intra-run (`conflicting_live_ids`) ne couvre qu'un seul batch ; deux
    # runs concurrents (overlap du cron Temporal, ou front + cron) ne se voient pas
    # sans lock partagé en base. On pose donc, pour chaque set, un lock par symbole
    # (clé UNIQUE = mutex) AVANT le dispatch, dans la transaction de lecture courte
    # qui sera committée juste après (jamais maintenue pendant les ~900s d'appels
    # Symfony). Politique : appliqué à TOUS les sets `mtf_run` (inoffensif en dry-run,
    # le live restant désactivé), sauf ceux déjà rejetés par le garde intra-run.
    # Acquisition « tout ou rien » par set ; un set dont un symbole est déjà verrouillé
    # par un run actif est skippé fail-closed (cohérent avec les autres skips du runner).
    lock_now = _now()
    # Balayage des locks expirés au démarrage : libère les fuites d'un process tué
    # avant son `finally` de libération (anti-deadlock via TTL).
    repositories.purge_expired_locks(session, lock_now)
    # TTL effectif : tous les locks d'un run partagent ce `lock_now`, mais un set
    # peut rester en file derrière le sémaphore (`max_concurrency`) bien après
    # l'acquisition. Si son `expires_at` tombait avant son dispatch, un run concurrent
    # pourrait le purger/reclaim et dispatcher le MÊME (profil, exchange, market, symbole)
    # — l'exclusion mutuelle SAFE-001 serait défaite. On dimensionne donc le TTL pour
    # couvrir le pire temps de paroi du run (chaque vague de `max_concurrency` sets peut
    # durer jusqu'au timeout Symfony) + la marge anti-deadlock configurée : aucun lock en
    # file n'expire avant la fin de son set, tout en gardant une expiration finie en cas
    # de crash. (Le `finally` libère de toute façon chaque lock dès la fin de son set.)
    concurrency = max(1, settings.max_concurrency)
    waves = math.ceil(len(mtf_sets) / concurrency)
    effective_ttl_seconds = int(waves * _HTTP_TIMEOUT) + settings.lock_ttl_seconds
    locked_out: Dict[str, str] = {}
    set_lock_keys: Dict[str, List[str]] = {}
    for a_set in mtf_sets:
        if a_set.set_id in conflicting_live_ids:
            continue  # déjà rejeté, jamais dispatché : rien à verrouiller
        locks = _lock_specs_for_set(a_set, run_id, lock_now, effective_ttl_seconds)
        if not locks:
            continue  # univers complet / aucun symbole concret : rien à sérialiser
        conflict = repositories.acquire_set_locks(session, locks, lock_now)
        if conflict is not None:
            key, holder = conflict
            locked_out[a_set.set_id] = f"locked: {key} held by run {holder}"
        else:
            set_lock_keys[a_set.set_id] = [lock.lock_key for lock in locks]

    # Persiste la purge + les locks acquis, PUIS détache les sets et clôt la
    # transaction de lecture AVANT les appels Symfony (jusqu'à 900s) : sinon la
    # connexion PostgreSQL resterait « idle in transaction » pendant toute l'attente
    # réseau (risque d'épuisement du pool / timeouts idle sous charge). L'ordre
    # commit→expunge importe : `expunge_all()` avant le commit jetterait les INSERT de
    # locks en attente. Les colonnes déjà chargées restent lisibles sur les instances
    # détachées (`expire_on_commit=False`, aucune relation lazy accédée pendant le
    # run) ; `_persist_run` rouvre une transaction fraîche.
    session.commit()
    session.expunge_all()

    started_at = datetime.now(timezone.utc)

    async with httpx.AsyncClient(timeout=_HTTP_TIMEOUT) as client:
        # 1) Un seul fetch d'état ouvert par couple (exchange, market_type).
        snapshots = await _collect_snapshots(client, settings.symfony_base_url, mtf_sets)

        # 2) Exécution bornée des sets avec le snapshot en cache.
        semaphore = asyncio.Semaphore(max(1, settings.max_concurrency))

        async def _execute(a_set: Any) -> Dict[str, Any]:
            if a_set.set_id in conflicting_live_ids:
                return {
                    "set_id": a_set.set_id,
                    "ok": False,
                    "status": None,
                    "body": "overlapping live set rejected (shared pre-run snapshot would miss sibling fills)",
                    "payload_sent": None,
                    "duration_ms": None,
                }
            # SAFE-001 : un set dont un symbole est déjà verrouillé par un autre run
            # actif est skippé fail-closed (aucun lock acquis pour lui : rien à
            # libérer ici). Les autres sets du run continuent normalement.
            if a_set.set_id in locked_out:
                return {
                    "set_id": a_set.set_id,
                    "ok": False,
                    "status": None,
                    "body": locked_out[a_set.set_id],
                    "payload_sent": None,
                    "duration_ms": None,
                }
            try:
                return await _dispatch_set(a_set)
            finally:
                # SAFE-001 : libération des locks de CE set, quoi qu'il arrive (succès,
                # échec métier, skip fail-closed, exception). Le bloc delete+commit ne
                # contient aucun `await` : il s'exécute atomiquement vis-à-vis des autres
                # coroutines (ordonnancement coopératif), donc partager la session de
                # requête entre les `_execute` concurrents est sûr.
                lock_keys = set_lock_keys.get(a_set.set_id)
                if lock_keys:
                    repositories.release_locks(session, run_id=run_id, lock_keys=lock_keys)
                    session.commit()

        async def _dispatch_set(a_set: Any) -> Dict[str, Any]:
            snapshot = snapshots.get(snapshot_key(a_set))
            effective_dry_run = a_set.dry_run or force_dry_run
            exchange = getattr(a_set.exchange, "value", a_set.exchange)
            # Garde live défense-en-profondeur : OKX/Hyperliquid live sont interdits.
            # La persistance les bloque déjà (assert_set_persistable), mais une ligne
            # ORM écrite hors API ne doit jamais déclencher un /api/mtf/run live ici.
            # Un override run-level dry_run rend le set sûr (effective_dry_run=True).
            # On normalise (casse/espaces) avant comparaison : une ligne hors API
            # avec `OKX` ou ` hyperliquid ` doit fail-closer comme `okx`/`hyperliquid`.
            normalized_exchange = exchange.strip().lower() if isinstance(exchange, str) else exchange
            if effective_dry_run is False and normalized_exchange in _LIVE_FORBIDDEN_EXCHANGES:
                return {
                    "set_id": a_set.set_id,
                    "ok": False,
                    "status": None,
                    "body": f"live forbidden for exchange '{exchange}': set skipped (fail-closed)",
                    "payload_sent": None,
                    "duration_ms": None,
                }
            # Fail-closed live (phase actuelle) : tant que la readiness live n'est pas
            # livrée, `assert_set_persistable` (app/schemas.py) interdit la persistance
            # de TOUT set live (tous exchanges/environnements). Le runner DB-backed
            # applique la même politique de bout en bout : une ligne ORM effectivement
            # live — écrite hors API, même avec un snapshot disponible — ne doit jamais
            # déclencher un /api/mtf/run live. L'override run-level dry_run reste le seul
            # moyen de rendre un set exécutable (en dry). À relâcher quand la readiness
            # live (SAFE-001/SAFE-002, TM-001) sera livrée.
            if effective_dry_run is False:
                return {
                    "set_id": a_set.set_id,
                    "ok": False,
                    "status": None,
                    "body": "live execution not yet enabled: live set skipped (fail-closed)",
                    "payload_sent": None,
                    "duration_ms": None,
                }
            # Fail-closed live : pas de snapshot fiable + set (effectivement) live => on n'exécute pas.
            if snapshot is None and effective_dry_run is False:
                return {
                    "set_id": a_set.set_id,
                    "ok": False,
                    "status": None,
                    "body": "open_state_snapshot unavailable: live set skipped (fail-closed)",
                    "payload_sent": None,
                    "duration_ms": None,
                }
            async with semaphore:
                start = time.monotonic()
                try:
                    result = await run_persisted_set(
                        client, settings.symfony_base_url, a_set, snapshot, effective_dry_run
                    )
                except httpx.HTTPError as exc:  # noqa: BLE001
                    result = {
                        "set_id": a_set.set_id,
                        "ok": False,
                        "status": None,
                        "body": f"mtf run failed: {exc}",
                        "payload_sent": None,
                    }
                # Durée mesurée autour de l'appel Symfony (monotonic, en ms).
                result["duration_ms"] = int((time.monotonic() - start) * 1000)
                return result

        results = await asyncio.gather(*(_execute(s) for s in mtf_sets))

    finished_at = datetime.now(timezone.utc)

    success = sum(1 for r in results if r.get("ok"))
    failed = len(results) - success
    summary = RunSummary(total_calls=len(results), success=success, failed=failed)
    status = _resolve_status(success, failed)
    ok = failed == 0

    # `_persist_run` peut renvoyer un run_id différent (run existant résolu par
    # idempotency_key) : on renvoie ce run_id-là pour que le client puisse relire le
    # run réellement persisté.
    run_id = _persist_run(
        session,
        run_id=run_id,
        dashboard_id=dashboard_id,
        request=request,
        ok=ok,
        status=status,
        summary=summary,
        started_at=started_at,
        finished_at=finished_at,
        mtf_sets=mtf_sets,
        results=results,
    )

    return RunResponse(ok=ok, run_id=run_id, status=status, summary=summary)
