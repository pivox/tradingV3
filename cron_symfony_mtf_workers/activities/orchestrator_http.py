"""Temporal activity calling the Python orchestrator (``POST /orchestrator/run``).

TM-001 : cron supervisé basique. L'activity POST le ``RunRequest`` minimal
(``dashboard_id``, ``schedule_id``, ``tick_timestamp``, et éventuellement
``idempotency_key`` / ``dry_run``) vers l'orchestrateur Python et retourne tel
quel le ``RunResponse`` (``ok``, ``run_id``, ``status``, ``summary``).

Aucune logique métier ici : la sélection des sets, la concurrence, l'agrégation
et la conservation du JSON sont portées par l'API Python (PY-005/PY-006). On
réutilise simplement le pattern httpx de ``mtf_http.py`` (timeout, gestion
d'exception → dict explicite).

Hors-scope TM-001 : ne PAS lever d'exception sur ``ok=false`` (c'est TM-002).
L'activity remonte le ``RunResponse`` complet pour que TM-002 puisse s'appuyer
dessus.
"""

from __future__ import annotations

import json
from typing import Any, Dict, Optional

import httpx
from temporalio import activity


@activity.defn(name="orchestrator_run")
async def orchestrator_run(
    url: str,
    request: Optional[Dict[str, Any]] = None,
) -> Dict[str, Any]:
    """POST le ``RunRequest`` vers ``/orchestrator/run`` et retourne le ``RunResponse``.

    Args:
        url: URL complète de l'orchestrateur (ex.
            ``http://python-orchestrator:8099/orchestrator/run``).
        request: Corps ``RunRequest`` optionnel (``dashboard_id``,
            ``schedule_id``, ``tick_timestamp``, ``idempotency_key``,
            ``dry_run``). ``None`` → corps vide ``{}``.

    Returns:
        Le ``RunResponse`` parsé (``{ok, run_id, status, summary}``) tel que
        renvoyé par l'API Python. En cas d'erreur HTTP/réseau ou de corps non
        JSON, un dict explicite ``ok=false`` est retourné (jamais d'exception),
        pour que l'appelant (workflow / TM-002) puisse décider de la suite.
    """
    # Timeout aligné sur le worker MTF historique : l'orchestrateur peut fan-out
    # plusieurs appels Symfony (jusqu'à 900s côté API).
    timeout = 900
    payload = request or {}
    try:
        async with httpx.AsyncClient(timeout=timeout) as client:
            response = await client.post(url, json=payload)
    except Exception as exc:  # noqa: BLE001 - propager l'échec en dict explicite
        return {
            "ok": False,
            "run_id": None,
            "status": "error",
            "summary": {"total_calls": 0, "success": 0, "failed": 0},
            "error": str(exc),
        }

    try:
        body = response.json()
    except json.JSONDecodeError:
        # Corps non JSON (proxy, 5xx HTML…) : on ne peut pas remonter un
        # RunResponse exploitable, on signale l'échec sans masquer le statut HTTP.
        return {
            "ok": False,
            "run_id": None,
            "status": "error",
            "summary": {"total_calls": 0, "success": 0, "failed": 0},
            "error": f"non-JSON response (HTTP {response.status_code}): {response.text[:500]}",
        }

    if not response.is_success:
        # Corps JSON mais statut HTTP d'erreur : ce n'est PAS un RunResponse.
        # L'orchestrateur renvoie toujours HTTP 200 pour un vrai run (y compris
        # ok=false : no_sets / failed / partial_failure). Un non-2xx JSON est
        # donc un échec d'appel (404 mauvais chemin, 422 validation, 401/403,
        # 500…), p.ex. le `{"detail": "..."}` de FastAPI. On le normalise en
        # dict explicite ok=false pour que TM-002 puisse distinguer un appel HTTP
        # échoué d'un run réellement exécuté, plutôt que de propager un corps qui
        # n'a pas la forme RunResponse.
        return {
            "ok": False,
            "run_id": None,
            "status": "error",
            "summary": {"total_calls": 0, "success": 0, "failed": 0},
            "error": f"HTTP {response.status_code}: {json.dumps(body)[:500]}",
        }

    # Réponse JSON valide et 2xx : on la retourne telle quelle. C'est le contrat
    # RunResponse de l'API Python (ok / run_id / status / summary). On ne
    # reconstruit rien — TM-002 décidera de l'échec sur ok=false.
    return body
