"""Client HTTP vers Symfony pour l'orchestrateur (SF-002b / PY-002).

Deux responsabilités :

1. **Snapshot d'état ouvert** — ``GET /api/exchange/open-state`` est appelé UNE
   seule fois par couple ``(exchange, market_type)`` actif, en amont de la boucle
   des sets. Le résultat (positions/ordres ouverts) est mis en cache et transmis
   à chaque ``POST /api/mtf/run`` via ``open_state_snapshot`` : Symfony ne refait
   donc aucun fetch exchange par set.

2. **Exécution d'un set** — ``POST /api/mtf/run`` avec ``sync_tables=false`` et le
   snapshot en cache. La concurrence est bornée par l'appelant (``asyncio.Semaphore``).

Fail-closed live : si le fetch du snapshot échoue, les sets **live**
(``dry_run=false``) ne doivent pas être exécutés (on ne trade pas à l'aveugle).
Les sets dry-run peuvent continuer sans snapshot.
"""

from __future__ import annotations

from typing import Any, Dict, Optional, Tuple

import httpx

from app.schemas import OrchestratorSet

# Clé de cache d'un snapshot : (exchange, market_type).
SnapshotKey = Tuple[str, str]


class OpenStateUnavailableError(RuntimeError):
    """Le snapshot d'état ouvert n'a pas pu être récupéré (fail-closed live)."""


def snapshot_key(a_set: OrchestratorSet) -> SnapshotKey:
    """Clé de cache du snapshot pour un set."""
    return (a_set.exchange.value, a_set.market_type.value)


async def fetch_open_state(
    client: httpx.AsyncClient,
    base_url: str,
    exchange: str,
    market_type: str,
) -> Dict[str, Any]:
    """Récupère l'instantané d'état ouvert pour un couple (exchange, market_type).

    Lève ``OpenStateUnavailableError`` si l'appel échoue ou si la réponse n'a pas
    la forme attendue ``{"open_positions": [...], "open_orders": [...]}``.
    """
    url = f"{base_url.rstrip('/')}/api/exchange/open-state"
    params = {"exchange": exchange, "market_type": market_type}
    try:
        response = await client.get(url, params=params)
    except httpx.HTTPError as exc:  # noqa: BLE001 - on remonte une erreur métier claire
        raise OpenStateUnavailableError(
            f"open-state fetch failed for {exchange}/{market_type}: {exc}"
        ) from exc

    if response.status_code != 200:
        raise OpenStateUnavailableError(
            f"open-state fetch returned HTTP {response.status_code} for {exchange}/{market_type}"
        )

    try:
        body = response.json()
    except ValueError as exc:
        raise OpenStateUnavailableError(
            f"open-state response is not valid JSON for {exchange}/{market_type}"
        ) from exc

    if not isinstance(body, dict) or "open_positions" not in body or "open_orders" not in body:
        raise OpenStateUnavailableError(
            f"open-state response has unexpected shape for {exchange}/{market_type}"
        )

    return {
        "open_positions": body.get("open_positions") or [],
        "open_orders": body.get("open_orders") or [],
    }


def build_mtf_payload(
    a_set: OrchestratorSet,
    snapshot: Optional[Dict[str, Any]],
) -> Dict[str, Any]:
    """Construit le payload ``/api/mtf/run`` pour un set.

    SF-002b : ``sync_tables`` est toujours forcé à ``false`` et
    ``open_state_snapshot`` est joint (le snapshot remplace tout fetch exchange
    par set côté Symfony).

    ``process_tp_sl`` est aussi forcé à ``false`` : le recalcul TP/SL post-run
    refetch les positions/ordres depuis le provider pour chaque set (et a des
    effets de bord live), ce qui réintroduirait les appels exchange par set que
    le snapshot partagé vise justement à éliminer.
    """
    payload: Dict[str, Any] = {
        "dry_run": a_set.dry_run,
        "workers": a_set.workers,
        "exchange": a_set.exchange.value,
        "market_type": a_set.market_type.value,
        "mtf_profile": a_set.mtf_profile.value,
        "sync_tables": False,
        "process_tp_sl": False,
    }
    if a_set.symbols:
        payload["symbols"] = list(a_set.symbols)
    if snapshot is not None:
        payload["open_state_snapshot"] = snapshot
    return payload


async def run_mtf_set(
    client: httpx.AsyncClient,
    base_url: str,
    a_set: OrchestratorSet,
    snapshot: Optional[Dict[str, Any]],
) -> Dict[str, Any]:
    """Exécute un set via ``POST /api/mtf/run`` avec le snapshot en cache."""
    url = f"{base_url.rstrip('/')}/api/mtf/run"
    payload = build_mtf_payload(a_set, snapshot)
    response = await client.post(url, json=payload)
    try:
        body = response.json()
    except ValueError:
        body = response.text
    return {
        "set_id": a_set.set_id,
        "ok": response.is_success,
        "status": response.status_code,
        "body": body,
    }
