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


class ContractsUnavailableError(RuntimeError):
    """La liste des contrats sélectionnés n'a pas pu être récupérée (PY-003).

    Levée par ``fetch_selected_contracts`` ; le refresh est *fail-closed* : un
    groupe dont le fetch échoue interdit toute écriture partielle des sets.
    """


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

    positions = body.get("open_positions")
    orders = body.get("open_orders")
    # Ne pas normaliser un null/non-liste en [] : ce serait un snapshot "vide fiable"
    # alors que l'exchange n'a rien rapporté de valide. On rejette (fail-closed) pour
    # que les sets live ne s'exécutent pas comme si aucune position n'était ouverte.
    if not isinstance(positions, list) or not isinstance(orders, list):
        raise OpenStateUnavailableError(
            f"open-state response has non-list open_positions/open_orders for {exchange}/{market_type}"
        )

    return {
        "open_positions": positions,
        "open_orders": orders,
    }


async def fetch_selected_contracts(
    client: httpx.AsyncClient,
    base_url: str,
    profile: Optional[str],
    exchange: str,
    market_type: str,
) -> Dict[str, Any]:
    """Récupère les symboles sélectionnés par ``mtf_contracts`` pour un profil.

    Appelle ``GET /api/mtf/contracts`` (SF-001, lecture seule : ne consomme pas la
    file MTF switch). Sert au refresh explicite des contrats (PY-003) : Symfony
    reste la source de vérité de la sélection.

    Lève ``ContractsUnavailableError`` si l'appel échoue, si HTTP != 200, si le
    corps n'est pas un JSON conforme (``ok`` vrai, ``symbols`` liste, champs
    requis présents). On ne normalise jamais une réponse douteuse en sélection
    vide : ce serait écraser les sets avec un univers faux.

    Retourne ``{profile, exchange, market_type, count, symbols, filters}``.
    """
    url = f"{base_url.rstrip('/')}/api/mtf/contracts"
    params = {"profile": profile, "exchange": exchange, "market_type": market_type}
    # Un profil non fourni laisse Symfony retomber sur le mode actif : on n'envoie
    # alors pas la clé (évite ``profile=`` qui serait parsé comme chaîne vide).
    params = {k: v for k, v in params.items() if v is not None}
    try:
        response = await client.get(url, params=params)
    except httpx.HTTPError as exc:  # noqa: BLE001 - on remonte une erreur métier claire
        raise ContractsUnavailableError(
            f"contracts fetch failed for {profile}/{exchange}/{market_type}: {exc}"
        ) from exc

    if response.status_code != 200:
        raise ContractsUnavailableError(
            f"contracts fetch returned HTTP {response.status_code} "
            f"for {profile}/{exchange}/{market_type}"
        )

    try:
        body = response.json()
    except ValueError as exc:
        raise ContractsUnavailableError(
            f"contracts response is not valid JSON for {profile}/{exchange}/{market_type}"
        ) from exc

    if not isinstance(body, dict) or body.get("ok") is not True:
        raise ContractsUnavailableError(
            f"contracts response not ok for {profile}/{exchange}/{market_type}"
        )

    symbols = body.get("symbols")
    if not isinstance(symbols, list):
        raise ContractsUnavailableError(
            f"contracts response has non-list symbols for {profile}/{exchange}/{market_type}"
        )

    # Champs requis : leur absence signale une réponse tronquée/inattendue.
    for field in ("profile", "exchange", "market_type", "count"):
        if field not in body:
            raise ContractsUnavailableError(
                f"contracts response missing '{field}' for {profile}/{exchange}/{market_type}"
            )

    filters = body.get("filters")
    return {
        "profile": body["profile"],
        "exchange": body["exchange"],
        "market_type": body["market_type"],
        "count": body["count"],
        "symbols": symbols,
        "filters": filters if isinstance(filters, dict) else {},
    }


def _base_mtf_payload(
    *,
    dry_run: bool,
    workers: int,
    exchange: str,
    market_type: str,
    mtf_profile: str,
    symbols: Any,
) -> Dict[str, Any]:
    """Cœur du payload ``/api/mtf/run``, source unique de sa forme.

    Partagé entre la construction runtime (``build_mtf_payload``, set pydantic à
    enums) et la préparation persistée (``generate_set_payload``, set ORM à
    chaînes) pour éviter toute dérive de schéma.

    SF-002b : ``sync_tables`` et ``process_tp_sl`` sont toujours forcés à
    ``false`` (le snapshot partagé remplace tout fetch/effet de bord exchange par
    set). ``symbols`` est omis s'il est vide : Symfony interprète alors l'absence
    comme « tout l'univers actif ».
    """
    payload: Dict[str, Any] = {
        "dry_run": dry_run,
        "workers": workers,
        "exchange": exchange,
        "market_type": market_type,
        "mtf_profile": mtf_profile,
        "sync_tables": False,
        "process_tp_sl": False,
    }
    if symbols:
        payload["symbols"] = list(symbols)
    return payload


def build_mtf_payload(
    a_set: OrchestratorSet,
    snapshot: Optional[Dict[str, Any]],
    dry_run: Optional[bool] = None,
) -> Dict[str, Any]:
    """Construit le payload ``/api/mtf/run`` runtime pour un set (pydantic).

    Joint ``open_state_snapshot`` (le snapshot remplace tout fetch exchange par
    set côté Symfony). ``dry_run`` permet de transmettre la valeur effective
    résolue par l'appelant (override run-level) ; si ``None``, on retombe sur le
    ``dry_run`` du set. Le reste de la forme vient de ``_base_mtf_payload``.
    """
    payload = _base_mtf_payload(
        dry_run=a_set.dry_run if dry_run is None else dry_run,
        workers=a_set.workers,
        exchange=a_set.exchange.value,
        market_type=a_set.market_type.value,
        mtf_profile=a_set.mtf_profile.value,
        symbols=a_set.symbols,
    )
    if snapshot is not None:
        payload["open_state_snapshot"] = snapshot
    return payload


def generate_set_payload(a_set: Any) -> Dict[str, Any]:
    """Prépare le payload ``/api/mtf/run`` **persisté** d'un set (PY-004).

    Lit un ``OrchestrationSet`` ORM, dont ``exchange``/``market_type``/
    ``mtf_profile`` sont des **chaînes** en base (pas des enums). N'inclut PAS
    ``open_state_snapshot`` : le snapshot est une valeur runtime récupérée à
    chaque run (PY-005), pas une donnée de configuration. Utilise le ``dry_run``
    configuré du set (les overrides run-level sont appliqués à l'exécution, pas
    stockés). Forme garantie identique à ``build_mtf_payload`` via le cœur partagé.
    """
    return _base_mtf_payload(
        dry_run=a_set.dry_run,
        workers=a_set.workers,
        exchange=a_set.exchange,
        market_type=a_set.market_type,
        mtf_profile=a_set.mtf_profile,
        symbols=a_set.symbols,
    )


# Statuts métier renvoyés par /api/mtf/run considérés comme un succès complet.
_SUCCESS_STATUSES = frozenset({"success"})


def is_business_success(body: Any) -> bool:
    """Indique si la réponse ``/api/mtf/run`` est un succès métier.

    Symfony renvoie HTTP 200 même pour des échecs métier : ``partial_success``
    (errors non vides), ``completed_with_errors`` (runs parallèles), ``rejected``
    (fail-closed SF-002b)... Un set n'est réussi que si le statut est un succès
    explicite ET qu'aucune erreur n'est remontée — le contrôleur peut écraser
    ``partial_success`` par ``summary.status``, donc on vérifie aussi ``errors``.
    """
    if not isinstance(body, dict):
        return False
    if body.get("status") not in _SUCCESS_STATUSES:
        return False
    errors = body.get("errors")
    if errors is None and isinstance(body.get("data"), dict):
        errors = body["data"].get("errors")
    return not errors


async def run_mtf_set(
    client: httpx.AsyncClient,
    base_url: str,
    a_set: OrchestratorSet,
    snapshot: Optional[Dict[str, Any]],
    dry_run: Optional[bool] = None,
) -> Dict[str, Any]:
    """Exécute un set via ``POST /api/mtf/run`` avec le snapshot en cache."""
    url = f"{base_url.rstrip('/')}/api/mtf/run"
    payload = build_mtf_payload(a_set, snapshot, dry_run)
    response = await client.post(url, json=payload)
    try:
        body = response.json()
    except ValueError:
        body = response.text
    return {
        "set_id": a_set.set_id,
        # Succès = HTTP 2xx ET succès métier (sinon un partial_success/rejected
        # renvoyé en 200 serait compté à tort comme réussi).
        "ok": response.is_success and is_business_success(body),
        "status": response.status_code,
        "business_status": body.get("status") if isinstance(body, dict) else None,
        "body": body,
    }
