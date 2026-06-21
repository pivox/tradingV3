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
    RUN_STATUS_RUNNING,
    TERMINAL_RUN_STATUSES,
    RunRequest,
    RunResponse,
    RunStatus,
    RunSummary,
)
from app.services import run_audit
from app.services.live_guard import (
    OPEN_STATE_UNAVAILABLE,
    OPEN_STATE_UNAVAILABLE_REASON,
    assess_live,
)
from app.services.symfony_client import (
    OpenStateUnavailableError,
    SnapshotKey,
    effective_set_payload,
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

# Codes de skip d'audit (OBS-001) propres au runner (les refus live portent leur
# propre `code` via `live_guard.LiveDecision`). Stables, réutilisés tels quels.
_SKIP_CODE_LOCKED = "locked"  # symbole déjà verrouillé par un run actif (SAFE-001)
_SKIP_CODE_CONFLICTING_LIVE = "conflicting_live"  # sets live chevauchants intra-batch
_SKIP_CODE_NOT_MATERIALIZED = "not_materialized"  # sélection non matérialisée (aucun POST)


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


def _has_idempotency_anchor(request: Optional[RunRequest]) -> bool:
    """Indique si un ancrage d'idempotence existe (→ ``run_id`` stable, claim posé).

    Miroir exact de ``_resolve_run_id`` : avec un ``idempotency_key`` non blanc, ou
    ``dashboard_id`` + ``tick_timestamp``, le ``run_id`` est dérivé de façon stable
    et SAFE-002 s'applique (claim, replay, reprise, in-flight). Sinon le ``run_id``
    est aléatoire et reste non idempotent (comportement inchangé).
    """
    if request is None:
        return False
    if request.idempotency_key and request.idempotency_key.strip():
        return True
    if request.dashboard_id and request.tick_timestamp:
        return True
    return False


def _normalized_idempotency_key(request: Optional[RunRequest]) -> Optional[str]:
    """Clé d'idempotence normalisée pour la colonne UNIQUE ``runs.idempotency_key``.

    - clé absente/vide/blanche → ``None`` (traitée comme absente, cf. ``_resolve_run_id``) ;
    - clé > 255 → hash déterministe borné (ne pas faire échouer l'INSERT/UPDATE).

    Source **unique** partagée par le claim précoce (SAFE-002) et ``_persist_run`` :
    le claim et la finalisation portent ainsi exactement la même clé, donc
    ``record_run`` retombe sur la même ligne.
    """
    key = request.idempotency_key if request is not None else None
    if key is not None and not key.strip():
        key = None
    if key is not None and len(key) > _MAX_PERSISTED_LEN:
        key = "sha256:" + hashlib.sha256(key.encode()).hexdigest()
    return key


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

    OBS-001 : émet un ``run_finished`` corrélé (``status="no_sets"``, compteurs à
    0) — point d'émission unique partagé par les quatre sorties ``no_sets`` du
    runner (pas de dashboard, dashboard désactivé, aucun set actif, aucun set
    ``mtf_run``), sans changer le contrat HTTP.
    """
    run_audit.emit(
        run_audit.RUN_FINISHED,
        run_id=run_id,
        status="no_sets",
        total_calls=0,
        success=0,
        failed=0,
    )
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
    # Clé normalisée (blanche → None, > 255 → hash borné) : source unique partagée
    # avec le claim précoce (SAFE-002), pour que claim et finalisation portent la
    # même clé et que `record_run` retombe sur la même ligne.
    idempotency_key = _normalized_idempotency_key(request)
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


def _claim_expired(run: Run, now: datetime) -> bool:
    """Indique si le claim « en vol » d'un run ``running`` est périmé (TTL dépassé).

    Un ``expires_at`` absent (run ``running`` legacy sans TTL de claim) est traité
    comme périmé → reclaimable. Le round-trip SQLite peut renvoyer un datetime naïf :
    on normalise le fuseau en UTC avant comparaison (comme les locks SAFE-001).
    """
    expires_at = run.expires_at
    if expires_at is None:
        return True
    if expires_at.tzinfo is None:
        expires_at = expires_at.replace(tzinfo=timezone.utc)
    return expires_at <= now


def _replay_response(run: Run) -> RunResponse:
    """Réponse de **replay** d'un run terminal réussi (SAFE-002).

    Reconstruit le contrat depuis le run persisté (``last_json`` + colonnes) sans
    ré-exécuter ni ré-appeler Symfony. ``summary`` est relu depuis ``last_json`` (les
    colonnes de compteurs servent de repli si le JSON est absent/tronqué).
    """
    last_json = run.last_json if isinstance(run.last_json, dict) else {}
    summary = last_json.get("summary") if isinstance(last_json.get("summary"), dict) else {}
    return RunResponse(
        ok=bool(run.ok),
        run_id=run.run_id,
        status=run.status,
        summary=RunSummary(
            total_calls=summary.get("total_calls", run.total_calls),
            success=summary.get("success", run.success_count),
            failed=summary.get("failed", run.failed_count),
        ),
    )


def _in_flight_response(run: Run) -> RunResponse:
    """Réponse de **réplique** d'un run ``running`` non périmé (un autre run est en vol).

    Décision produit (SAFE-002) : ``ok=false`` (jamais un succès Temporal) + statut
    non terminal ``running`` ; aucun dispatch, aucun appel Symfony. ``summary``
    réplique l'état courant (compteurs à 0 tant que la finalisation n'a pas eu lieu).
    """
    return RunResponse(
        ok=False,
        run_id=run.run_id,
        status=RUN_STATUS_RUNNING,
        summary=RunSummary(
            total_calls=run.total_calls,
            success=run.success_count,
            failed=run.failed_count,
        ),
    )


def _preserved_results(run: Run, run_sets: List[Any]) -> Dict[str, Dict[str, Any]]:
    """Résultats des sets DÉJÀ réussis d'un run à reprendre (SAFE-002, reprise).

    Pour chaque ``RunSet.ok=true``, reconstruit le dict de résultat attendu par le
    runner/``_persist_run`` à partir des colonnes persistées (``response_json`` →
    ``body``, ``payload_sent``, ``duration_ms``). Le ``status``/``business_status``
    (non stockés en colonne) sont relus depuis le détail ``last_json`` de la
    tentative précédente quand il est disponible. Ces sets ne sont PAS re-dispatchés :
    leur résultat conservé est fusionné aux sets re-exécutés pour recomposer le
    summary et le ``last_json`` final.
    """
    detail_by_set: Dict[str, Dict[str, Any]] = {}
    last_json = run.last_json if isinstance(run.last_json, dict) else {}
    sets_detail = last_json.get("sets")
    if isinstance(sets_detail, list):
        for detail in sets_detail:
            if isinstance(detail, dict) and detail.get("set_id") is not None:
                detail_by_set[detail["set_id"]] = detail
    preserved: Dict[str, Dict[str, Any]] = {}
    for run_set in run_sets:
        if not run_set.ok:
            continue
        detail = detail_by_set.get(run_set.set_id, {})
        preserved[run_set.set_id] = {
            "set_id": run_set.set_id,
            "ok": True,
            "status": detail.get("status"),
            "business_status": detail.get("business_status"),
            "body": run_set.response_json,
            "payload_sent": run_set.payload_sent,
            "duration_ms": (
                run_set.duration_ms
                if run_set.duration_ms is not None
                else detail.get("duration_ms")
            ),
        }
    return preserved


def _seed_claim(
    session: Session,
    *,
    run_id: str,
    dashboard_id: int,
    idempotency_key: Optional[str],
    now: datetime,
    ttl_seconds: int,
) -> tuple[str, Run, Optional[str], Optional[str]]:
    """Pose/reprend le claim « en vol » d'un run (SAFE-002).

    Retourne ``(run_id_persisté, run, yield_reason, claim_reason)``. ``yield_reason``
    vaut ``None`` quand le claim est obtenu (création ou reprise). Sinon, un run
    concurrent a changé l'état **entre le pré-check et le seed** (course TOCTOU) et
    l'appelant doit céder sans dispatcher ni écraser la ligne partagée :

    - ``"replay"`` : le gagnant a déjà **finalisé en succès** → on rejoue le succès ;
    - ``"in_flight"`` : un run concurrent est **en vol** (claim non périmé) → réplique.

    ``claim_reason`` (OBS-001, observation d'audit pure) qualifie le claim OBTENU
    (``yield_reason is None``) d'après l'état PRÉ-claim **réellement classé** par
    ``claim_run`` (la ligne fraîche verrouillée — pas le résultat du pré-check, qui
    peut rater la ligne dans une course SAFE-002) :

    - ``"reclaim"`` : reprise d'un ``running`` périmé (TTL dépassé, process tué) ;
    - ``"resume"`` : reprise d'un terminal non-ok ;
    - ``None`` : création d'une ligne neuve (aucune existante) ou cession (yield).

    Transaction courte (committée par l'appelant AVANT les appels Symfony, jamais
    maintenue pendant les ~900s, comme les locks SAFE-001) : pose la ligne ``Run``
    via ``claim_run`` (statut ``running``, ``started_at``, ``expires_at`` = ``now`` +
    TTL de claim) **sous verrou ligne** (savepoint anti-course + ``FOR UPDATE``). Le
    claim ne remplace une ligne existante que si ``classify`` ne demande PAS de céder
    (terminal non-ok → reprise ; claim périmé → reclaim).

    ``claim_run`` peut résoudre un run existant (par ``idempotency_key``) dont le
    ``run_id`` diffère du dérivé : on retourne le run_id réellement persisté afin que
    claim, locks et finalisation portent le même identifiant (cf. logique
    ``_persist_run``). On neutralise une FK ``dashboard_id`` périmée (dashboard
    supprimé entre-temps) comme ``_persist_run``, pour ne pas faire échouer l'INSERT.
    """
    dashboard_ref = (
        dashboard_id if repositories.get_dashboard(session, dashboard_id) is not None else None
    )

    # OBS-001 : capture la cause du claim OBTENU depuis l'état PRÉ-claim que
    # `claim_run` classe réellement (la ligne fraîche verrouillée passée à
    # `_classify`). C'est la seule source fiable : le pré-check `existing_run` peut
    # rater la ligne dans une course SAFE-002, et `preserved_results` est vide pour
    # une reprise « full-failure » (aucun RunSet réussi). `_classify` n'est invoqué
    # que si une ligne existe ; un INSERT neuf laisse donc `claim_reason` à None.
    observed: Dict[str, Optional[str]] = {"reason": None}

    def _classify(existing: Run) -> Optional[str]:
        # Mêmes court-circuits que le pré-check, mais ré-évalués sur l'état FRAIS et
        # VERROUILLÉ (le gagnant d'une course a pu, depuis, finaliser ou rester en vol).
        if existing.status in TERMINAL_RUN_STATUSES and existing.ok:
            return "replay"  # succès finalisé → rejouer (ne PAS écraser/ré-exécuter)
        if existing.status == RUN_STATUS_RUNNING and not _claim_expired(existing, now):
            return "in_flight"  # un autre run est en vol → répliquer
        # Claim repris : on note la cause (audit) avant de céder la reprise au claim.
        if existing.status == RUN_STATUS_RUNNING:
            observed["reason"] = "reclaim"  # `running` périmé (TTL dépassé)
        elif existing.status in TERMINAL_RUN_STATUSES:
            observed["reason"] = "resume"  # terminal non-ok
        return None  # terminal non-ok / claim périmé → reprise/reclaim

    persisted, yield_reason = repositories.claim_run(
        session,
        Run(
            run_id=run_id,
            dashboard_id=dashboard_ref,
            ok=False,
            status=RUN_STATUS_RUNNING,
            idempotency_key=idempotency_key,
            total_calls=0,
            success_count=0,
            failed_count=0,
            started_at=now,
            expires_at=now + timedelta(seconds=ttl_seconds),
        ),
        classify=_classify,
    )
    # Une cession (yield_reason non None) n'est pas une reprise : on n'expose alors
    # aucune `claim_reason` (l'appelant émettra le short-circuit replay/in_flight).
    claim_reason = observed["reason"] if yield_reason is None else None
    return persisted.run_id, persisted, yield_reason, claim_reason


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
    *,
    run_id: str,
) -> Dict[SnapshotKey, Dict[str, Any]]:
    """Récupère un snapshot d'état ouvert par couple ``(exchange, market_type)``.

    Un seul appel ``GET /api/exchange/open-state`` par couple distinct. Les
    couples dont le fetch échoue restent absents du cache (fail-closed géré par
    l'appelant pour les sets live).

    OBS-001 : chaque couple émet un ``snapshot_fetch`` corrélé (``ok`` /
    indisponible), pour rendre visible en flux le fetch 1×/(exchange, market_type).
    """
    keys = {snapshot_key(s) for s in mtf_sets}
    snapshots: Dict[SnapshotKey, Dict[str, Any]] = {}
    for exchange, market_type in keys:
        try:
            snapshots[(exchange, market_type)] = await fetch_open_state(
                client, base_url, exchange, market_type
            )
            run_audit.emit(
                run_audit.SNAPSHOT_FETCH,
                run_id=run_id,
                exchange=exchange,
                market_type=market_type,
                ok=True,
            )
        except OpenStateUnavailableError:
            # Pas de snapshot fiable pour ce couple : on ne met rien en cache.
            run_audit.emit(
                run_audit.SNAPSHOT_FETCH,
                run_id=run_id,
                level="warning",
                exchange=exchange,
                market_type=market_type,
                ok=False,
                code=OPEN_STATE_UNAVAILABLE,
            )
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

    # Horloge unique du run (injectable en test via `orch._now`) : partagée par le
    # claim d'idempotence (SAFE-002), le TTL de claim ET le TTL des locks (SAFE-001).
    now = _now()

    # SAFE-002 : pré-résolution de l'éventuel run ancré (run_id stable). Les
    # court-circuits qui NE ré-exécutent PAS — replay (terminal success) et réplique
    # in-flight (run en vol) — sont traités ICI, AVANT le gating `no_sets` : ils ne
    # nécessitent ni dashboard ni sets, donc un retry après désactivation/suppression
    # du dashboard (ou avec la seule clé) doit quand même rejouer le succès persisté
    # plutôt que renvoyer `no_sets`. La reprise/reclaim, qui re-exécutent, restent
    # gated par la disponibilité des sets (plus bas).
    has_anchor = _has_idempotency_anchor(request)
    idempotency_key = _normalized_idempotency_key(request)

    existing_run = (
        repositories.resolve_run(session, run_id, idempotency_key) if has_anchor else None
    )

    if existing_run is not None:
        if existing_run.status in TERMINAL_RUN_STATUSES and existing_run.ok:
            # Terminal success → REPLAY : summary/run_id reconstruits depuis le run
            # persisté, aucun ré-appel Symfony, indépendant de l'état du dashboard.
            run_audit.emit(
                run_audit.RUN_SHORT_CIRCUIT,
                run_id=existing_run.run_id,
                level="warning",
                reason="replay",
            )
            return _replay_response(existing_run)
        if existing_run.status == RUN_STATUS_RUNNING and not _claim_expired(existing_run, now):
            # `running` non périmé → un autre run est EN VOL : réplique de l'état
            # courant (ok=false + statut running), aucun dispatch.
            run_audit.emit(
                run_audit.RUN_SHORT_CIRCUIT,
                run_id=existing_run.run_id,
                level="warning",
                reason="in_flight",
            )
            return _in_flight_response(existing_run)

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

    # TTL effectif : un set peut rester en file derrière le sémaphore (`max_concurrency`)
    # bien après l'acquisition de son lock. Si son `expires_at` tombait avant son
    # dispatch, un run concurrent pourrait le purger/reclaim et dispatcher le MÊME
    # (profil, exchange, market, symbole) — l'exclusion mutuelle SAFE-001 serait défaite.
    # On dimensionne donc le TTL pour couvrir le pire temps de paroi du run (chaque vague
    # de `max_concurrency` sets peut durer jusqu'au timeout Symfony) + la marge configurée.
    # Le même TTL borne le claim de run « en vol » (SAFE-002) : un run resté `running`
    # au-delà (process tué avant la finalisation) est reclaimable.
    concurrency = max(1, settings.max_concurrency)
    waves = math.ceil(len(mtf_sets) / concurrency)
    effective_ttl_seconds = int(waves * _HTTP_TIMEOUT) + settings.lock_ttl_seconds

    # SAFE-002 (suite) : les court-circuits qui RE-EXÉCUTENT — donc nécessitent des
    # sets — sont décidés ici, après le gating `no_sets`. `existing_run` a été résolu
    # en amont (le replay/in-flight ont déjà court-circuité). Reste :
    #  - terminal non-ok (failed/partial) → REPRISE : on conserve les RunSet déjà
    #    réussis et on ne re-dispatchera que les sets restants ;
    #  - `running` périmé (TTL, process tué) → reclaim : ré-exécution comme un nouveau
    #    run (aucun résultat conservé).
    preserved_results: Dict[str, Dict[str, Any]] = {}
    if (
        existing_run is not None
        and existing_run.status in TERMINAL_RUN_STATUSES
        and not existing_run.ok
    ):
        preserved_results = _preserved_results(
            existing_run, list(repositories.list_run_sets(session, existing_run.run_id))
        )

    # OBS-001 : la cause d'un éventuel court-circuit SAFE-002 « reprise » (resume /
    # reclaim) est déterminée par le claim lui-même (`_seed_claim` → `claim_reason`),
    # depuis l'état PRÉ-claim réellement classé — fiable même quand le pré-check
    # `existing_run` rate la ligne (course) ou quand aucun RunSet n'a été conservé.
    claim_reason: Optional[str] = None

    # SAFE-001 : sérialisation per-(profil, symbole) ENTRE runs/process via des locks
    # DB. Le garde intra-run (`conflicting_live_ids`) ne couvre qu'un seul batch ; deux
    # runs concurrents (overlap du cron Temporal, ou front + cron) ne se voient pas
    # sans lock partagé en base. On pose donc, pour chaque set, un lock par symbole
    # (clé UNIQUE = mutex) AVANT le dispatch, dans la transaction de lecture courte
    # qui sera committée juste après (jamais maintenue pendant les ~900s d'appels
    # Symfony). Politique : appliqué à TOUS les sets `mtf_run` (inoffensif en dry-run,
    # le live restant désactivé), sauf ceux déjà rejetés par le garde intra-run ou déjà
    # réussis (reprise SAFE-002). Acquisition « tout ou rien » par set ; un set dont un
    # symbole est déjà verrouillé par un run actif est skippé fail-closed.
    lock_now = now
    # Balayage des locks expirés au démarrage : libère les fuites d'un process tué
    # avant son `finally` de libération (anti-deadlock via TTL).
    repositories.purge_expired_locks(session, lock_now)

    # Claim précoce (SAFE-002) : pose/reprend la ligne Run (statut `running`,
    # started_at, expires_at) AVANT le dispatch, dans cette même transaction courte
    # committée juste après. `claim_run` peut résoudre un run existant sous un run_id
    # distinct (legacy par idempotency_key) : on réutilise le run_id réellement persisté
    # pour les locks ET la finalisation (claim et finalisation portent le même run_id).
    if has_anchor:
        run_id, claim_row, yield_reason, claim_reason = _seed_claim(
            session,
            run_id=run_id,
            dashboard_id=dashboard_id,
            idempotency_key=idempotency_key,
            now=now,
            ttl_seconds=effective_ttl_seconds,
        )
        if yield_reason is not None:
            # Course perdue : un run concurrent a changé l'état ENTRE le pré-check et le
            # seed (les deux requêtes avaient vu une absence de ligne, ou le gagnant a
            # finalisé depuis). On cède SANS dispatcher ni écrire — sinon on écraserait
            # la ligne partagée (statut/run_sets) et on re-soumettrait du travail. Rien
            # n'a été committé ici (la purge non committée sera annulée à la fermeture de
            # session). `replay` rejoue le succès finalisé par le gagnant ; `in_flight`
            # réplique l'état d'un run encore en vol.
            run_audit.emit(
                run_audit.RUN_SHORT_CIRCUIT,
                run_id=claim_row.run_id,
                level="warning",
                reason=yield_reason,
            )
            if yield_reason == "replay":
                return _replay_response(claim_row)
            return _in_flight_response(claim_row)

    # OBS-001 : point d'entrée d'audit émis ICI — APRÈS que le claim a résolu le
    # `run_id` réellement persisté (un run ancré peut le réécrire vers une ligne
    # legacy résolue par `idempotency_key`, y compris sur la course SAFE-002 ratée
    # au pré-check). Tous les événements du run (short_circuit resume/reclaim, set_*,
    # finished) + l'en-tête X-Run-Id partagent donc la même clé de corrélation. Les
    # sorties précoces (replay/in-flight, no_sets, cession de claim) n'exécutent rien
    # et émettent leur propre événement terminal — pas de `run_started` orphelin.
    run_audit.emit(
        run_audit.RUN_STARTED,
        run_id=run_id,
        dashboard_id=dashboard_id,
        has_anchor=has_anchor,
    )

    # OBS-001 : court-circuits SAFE-002 qui RE-EXÉCUTENT, fondés sur la cause réelle
    # du claim (`claim_reason`), pas sur le pré-check ni sur `preserved_results` :
    #  - `resume` : reprise d'un terminal non-ok — émis MÊME si aucun RunSet n'a été
    #    conservé (reprise « full-failure » → `preserved_sets=0`) ;
    #  - `reclaim` : reprise d'un `running` périmé (process tué), y compris quand le
    #    pré-check a raté la ligne et que seul le claim l'a résolue (course SAFE-002).
    if claim_reason == "resume":
        run_audit.emit(
            run_audit.RUN_SHORT_CIRCUIT,
            run_id=run_id,
            reason="resume",
            preserved_sets=len(preserved_results),
        )
    elif claim_reason == "reclaim":
        run_audit.emit(
            run_audit.RUN_SHORT_CIRCUIT,
            run_id=run_id,
            level="warning",
            reason="reclaim",
        )

    locked_out: Dict[str, str] = {}
    set_lock_keys: Dict[str, List[str]] = {}
    for a_set in mtf_sets:
        if a_set.set_id in conflicting_live_ids:
            continue  # déjà rejeté, jamais dispatché : rien à verrouiller
        if a_set.set_id in preserved_results:
            continue  # reprise SAFE-002 : set déjà réussi, pas de re-dispatch à sérialiser
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
        snapshots = await _collect_snapshots(
            client, settings.symfony_base_url, mtf_sets, run_id=run_id
        )

        # 2) Exécution bornée des sets avec le snapshot en cache.
        semaphore = asyncio.Semaphore(max(1, settings.max_concurrency))

        async def _execute(a_set: Any) -> Dict[str, Any]:
            # Reprise SAFE-002 : un set déjà réussi lors d'une tentative précédente du
            # même run n'est PAS re-dispatché (aucun appel Symfony, aucun lock posé) ;
            # son résultat conservé est fusionné au summary/last_json recomposés.
            if a_set.set_id in preserved_results:
                return preserved_results[a_set.set_id]
            if a_set.set_id in conflicting_live_ids:
                run_audit.emit(
                    run_audit.SET_SKIPPED,
                    run_id=run_id,
                    level="warning",
                    set_id=a_set.set_id,
                    code=_SKIP_CODE_CONFLICTING_LIVE,
                )
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
                run_audit.emit(
                    run_audit.SET_SKIPPED,
                    run_id=run_id,
                    level="warning",
                    set_id=a_set.set_id,
                    code=_SKIP_CODE_LOCKED,
                )
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
            # État live EFFECTIF (override run-level `{"dry_run": true}` déjà reflété) :
            # le forçage ne peut que rendre un set plus sûr, jamais downgrader dry→live.
            effective_dry_run = a_set.dry_run or force_dry_run
            # Couche UNIQUE de garde-fous live (SAFE-003) : toute la politique
            # (bannissements permanents OKX/Hyperliquid, interrupteur d'activation,
            # allow-list) vit dans `live_guard.assess_live`. Le runner DB-backed
            # délègue ici exactement comme `assert_set_persistable` à la persistance :
            # une ligne ORM écrite hors API ne peut jamais déclencher un /api/mtf/run
            # live que la persistance refuserait. Par défaut (interrupteur OFF), tout
            # set effectivement live est skippé fail-closed, comme avant SAFE-003.
            decision = assess_live(
                exchange=getattr(a_set.exchange, "value", a_set.exchange),
                market_type=getattr(a_set.market_type, "value", a_set.market_type),
                environment=getattr(a_set.environment, "value", a_set.environment),
                dry_run=effective_dry_run,
                settings=settings,
            )
            if not decision.allowed:
                # OBS-001 : skip garde-fou live — on relaie le `code` STABLE déjà
                # calculé par `live_guard` (live_not_enabled / live_forbidden_exchange
                # / live_exchange_not_allowlisted), source unique partagée avec
                # `RunSet.error`. L'audit ne redéfinit aucune raison.
                run_audit.emit(
                    run_audit.SET_SKIPPED,
                    run_id=run_id,
                    level="warning",
                    set_id=a_set.set_id,
                    code=decision.code,
                )
                return {
                    "set_id": a_set.set_id,
                    "ok": False,
                    "status": None,
                    "body": decision.reason,
                    "payload_sent": None,
                    "duration_ms": None,
                }
            # Prérequis runtime conservé : un set autorisé live mais sans snapshot
            # d'état ouvert fiable n'est PAS dispatché (on ne trade pas à l'aveugle).
            # `assess_live` ne peut pas l'évaluer (le snapshot n'existe pas à la
            # persistance), donc ce garde fail-closed reste ici, après la décision.
            if effective_dry_run is False and snapshot is None:
                run_audit.emit(
                    run_audit.SET_SKIPPED,
                    run_id=run_id,
                    level="warning",
                    set_id=a_set.set_id,
                    code=OPEN_STATE_UNAVAILABLE,
                )
                return {
                    "set_id": a_set.set_id,
                    "ok": False,
                    "status": None,
                    "body": OPEN_STATE_UNAVAILABLE_REASON,
                    "payload_sent": None,
                    "duration_ms": None,
                }
            # OBS-001 : un set dont la sélection n'est PAS matérialisée (symbols vide
            # alors qu'il est valide par `contracts_limit`) ne déclenche AUCUN
            # `POST /api/mtf/run` (`run_persisted_set` renvoie `payload_sent=None`).
            # On ne doit donc pas auditer un `set_dispatched`/trace-id qui n'a pas eu
            # lieu : c'est un skip fail-closed, audité comme tel. `effective_set_payload`
            # est la fonction canonique partagée avec `run_persisted_set` (même verdict).
            if effective_set_payload(a_set) is None:
                run_audit.emit(
                    run_audit.SET_SKIPPED,
                    run_id=run_id,
                    level="warning",
                    set_id=a_set.set_id,
                    code=_SKIP_CODE_NOT_MATERIALIZED,
                )
                return {
                    "set_id": a_set.set_id,
                    "ok": False,
                    "status": None,
                    "business_status": None,
                    "body": "set payload not materialized (no concrete symbols)",
                    "payload_sent": None,
                    "duration_ms": None,
                }
            async with semaphore:
                # OBS-001 : le set franchit toutes les gardes ET sa sélection est
                # matérialisée → dispatch Symfony effectif (POST réel + X-Run-Id).
                run_audit.emit(
                    run_audit.SET_DISPATCHED,
                    run_id=run_id,
                    set_id=a_set.set_id,
                    dry_run=effective_dry_run,
                )
                start = time.monotonic()
                try:
                    result = await run_persisted_set(
                        client,
                        settings.symfony_base_url,
                        a_set,
                        snapshot,
                        effective_dry_run,
                        run_id=run_id,
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
                # OBS-001 : issue du dispatch (ok + statut métier + durée), corrélée.
                run_audit.emit(
                    run_audit.SET_RESULT,
                    run_id=run_id,
                    level="info" if result.get("ok") else "warning",
                    set_id=a_set.set_id,
                    ok=bool(result.get("ok")),
                    business_status=result.get("business_status"),
                    duration_ms=result.get("duration_ms"),
                )
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

    # OBS-001 : clôture du run, corrélée par le run_id RÉELLEMENT persisté (celui
    # renvoyé par `_persist_run`, qui peut différer du dérivé en cas de retry
    # legacy par idempotency_key). Compteurs identiques au `summary` HTTP.
    run_audit.emit(
        run_audit.RUN_FINISHED,
        run_id=run_id,
        status=status,
        total_calls=summary.total_calls,
        success=summary.success,
        failed=summary.failed,
    )

    return RunResponse(ok=ok, run_id=run_id, status=status, summary=summary)
