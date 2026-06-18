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
import time
import uuid
from datetime import datetime, timezone
from typing import Any, Dict, List, Optional

import httpx
from fastapi import APIRouter, Depends
from sqlalchemy.orm import Session

from app.db import repositories
from app.db.engine import get_session
from app.db.models import Run, RunSet
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
    if request is not None:
        if request.idempotency_key:
            return f"run_{request.idempotency_key}"
        if request.dashboard_id and request.tick_timestamp:
            stamp = request.tick_timestamp.astimezone(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
            return f"run_{request.dashboard_id}_{stamp}"
    stamp = datetime.now(timezone.utc).strftime("%Y%m%d_%H%M%S")
    return f"run_{stamp}_{uuid.uuid4().hex[:6]}"


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
) -> None:
    """Persiste l'historique du run : un ``Run`` global + un ``RunSet`` par set.

    ``last_json`` agrège le résumé et le détail par set (le « dernier JSON » de la
    doc). ``record_run``/``record_run_set`` sont des upserts idempotents ; le
    commit est géré ici (la dépendance ``get_session`` ne committe pas).
    """
    idempotency_key = request.idempotency_key if request is not None else None
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

    repositories.record_run(
        session,
        Run(
            run_id=run_id,
            dashboard_id=dashboard_id,
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

    # L'ordre de `results` suit celui de `mtf_sets` (asyncio.gather préserve
    # l'ordre), donc le zip associe chaque résultat à son set ORM source.
    for a_set, result in zip(mtf_sets, results):
        body = result.get("body")
        repositories.record_run_set(
            session,
            RunSet(
                run_id=run_id,
                set_id=result["set_id"],
                set_ref_id=a_set.id,
                payload_sent=result.get("payload_sent"),
                response_json=body if isinstance(body, dict) else None,
                ok=bool(result.get("ok")),
                error=_result_error(result),
                duration_ms=result.get("duration_ms"),
            ),
        )

    session.commit()


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
            if (a.exchange, a.market_type) != (b.exchange, b.market_type):
                continue
            if _symbols_overlap(a, b):
                conflicting.add(a.set_id)
                conflicting.add(b.set_id)
    return conflicting


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

    # Détache les sets et clôt la transaction de lecture AVANT les appels Symfony
    # (jusqu'à 900s) : sinon la connexion PostgreSQL resterait « idle in
    # transaction » pendant toute l'attente réseau (risque d'épuisement du pool /
    # timeouts idle sous charge). Les colonnes déjà chargées restent lisibles sur
    # les instances détachées (`expire_on_commit=False`, aucune relation lazy
    # accédée pendant le run) ; `_persist_run` rouvre une transaction fraîche.
    session.expunge_all()
    session.commit()

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

    _persist_run(
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
