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
import uuid
from datetime import datetime, timezone
from typing import Any, Dict, List, Optional

import httpx
from fastapi import APIRouter

from app.schemas import Action, RunRequest, RunResponse, RunStatus, RunSummary
from app.services.sets import list_active_sets
from app.services.symfony_client import (
    OpenStateUnavailableError,
    SnapshotKey,
    fetch_open_state,
    run_mtf_set,
    snapshot_key,
)
from app.settings import get_settings

router = APIRouter(tags=["orchestrator"])

# Timeout (s) des appels Symfony, aligné sur le worker Temporal historique.
_HTTP_TIMEOUT = 900.0


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
async def run_orchestrator(request: Optional[RunRequest] = None) -> RunResponse:
    """Déclenche un run d'orchestration (SF-002b)."""
    settings = get_settings()
    run_id = _resolve_run_id(request)
    active_sets = list_active_sets()

    # Aucun set actif n'est PAS un succès : on remonte un état explicite pour
    # que Temporal ne considère pas le tick comme réussi (ok=false).
    if not active_sets:
        return RunResponse(
            ok=False,
            run_id=run_id,
            status="no_sets",
            summary=RunSummary(total_calls=0, success=0, failed=0),
        )

    mtf_sets = [s for s in active_sets if s.action == Action.MTF_RUN]
    if not mtf_sets:
        # Sets actifs mais aucun à exécuter via /api/mtf/run (actions hors scope).
        return RunResponse(
            ok=False,
            run_id=run_id,
            status="no_sets",
            summary=RunSummary(total_calls=0, success=0, failed=0),
        )

    success = 0
    failed = 0

    async with httpx.AsyncClient(timeout=_HTTP_TIMEOUT) as client:
        # 1) Un seul fetch d'état ouvert par couple (exchange, market_type).
        snapshots = await _collect_snapshots(client, settings.symfony_base_url, mtf_sets)

        # 2) Exécution bornée des sets avec le snapshot en cache.
        semaphore = asyncio.Semaphore(max(1, settings.max_concurrency))

        async def _execute(a_set: Any) -> Dict[str, Any]:
            snapshot = snapshots.get(snapshot_key(a_set))
            # Fail-closed live : pas de snapshot fiable + set live => on n'exécute pas.
            if snapshot is None and a_set.dry_run is False:
                return {
                    "set_id": a_set.set_id,
                    "ok": False,
                    "status": None,
                    "body": "open_state_snapshot unavailable: live set skipped (fail-closed)",
                }
            async with semaphore:
                try:
                    return await run_mtf_set(client, settings.symfony_base_url, a_set, snapshot)
                except httpx.HTTPError as exc:  # noqa: BLE001
                    return {
                        "set_id": a_set.set_id,
                        "ok": False,
                        "status": None,
                        "body": f"mtf run failed: {exc}",
                    }

        results = await asyncio.gather(*(_execute(s) for s in mtf_sets))

    for result in results:
        if result.get("ok"):
            success += 1
        else:
            failed += 1

    summary = RunSummary(total_calls=len(results), success=success, failed=failed)
    status = _resolve_status(success, failed)

    return RunResponse(
        ok=(failed == 0),
        run_id=run_id,
        status=status,
        summary=summary,
    )
