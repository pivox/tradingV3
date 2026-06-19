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

from app.schemas import MAX_WORKERS_PER_SET, OrchestratorSet

# Clé de cache d'un snapshot : (exchange, market_type).
SnapshotKey = Tuple[str, str]


class OpenStateUnavailableError(RuntimeError):
    """Le snapshot d'état ouvert n'a pas pu être récupéré (fail-closed live)."""


class ContractsUnavailableError(RuntimeError):
    """La liste des contrats sélectionnés n'a pas pu être récupérée (PY-003).

    Levée par ``fetch_selected_contracts`` ; le refresh est *fail-closed* : un
    groupe dont le fetch échoue interdit toute écriture partielle des sets.
    """


# Alias de ``market_type`` canonicalisés par Symfony
# (``ExchangeContextResolver::normalizeMarketType``, après ``strtolower(trim())``).
# On miroir EXACTEMENT cette table pour que le regroupement snapshot / la détection
# de conflit live correspondent au marché que Symfony exécutera réellement (sinon
# un set live ``perp`` et un set live ``perpetual`` viseraient le même marché tout
# en ayant des clés distinctes côté Python).
_MARKET_TYPE_ALIASES = {
    "perpetual": "perpetual",
    "perp": "perpetual",
    "future": "perpetual",
    "futures": "perpetual",
    "spot": "spot",
}


def _normalize_exchange(value: Any) -> Any:
    """Normalise un ``exchange`` comme Symfony : ``strtolower(trim())`` (sans alias)."""
    return value.strip().lower() if isinstance(value, str) else value


def _normalize_market_type(value: Any) -> Any:
    """Normalise un ``market_type`` comme Symfony : casse/espaces + alias canoniques.

    Les valeurs inconnues retombent sur leur forme normalisée (Symfony les
    rejetterait en 400 ; ici elles ne matchent simplement aucune autre).
    """
    if not isinstance(value, str):
        return value
    canon = value.strip().lower()
    return _MARKET_TYPE_ALIASES.get(canon, canon)


def snapshot_key(a_set: Any) -> SnapshotKey:
    """Clé de cache du snapshot pour un set.

    Accepte un set pydantic (``exchange``/``market_type`` sont des enums) **et** un
    ``OrchestrationSet`` ORM (ce sont des chaînes en base) : on lit ``.value`` quand
    il existe, sinon la chaîne telle quelle, puis on normalise comme Symfony
    (casse/espaces + alias de ``market_type``) afin que des variantes
    (``Bitmart``/``bitmart``, ``perp``/``perpetual``) partagent le même snapshot.
    """
    exchange = getattr(a_set.exchange, "value", a_set.exchange)
    market_type = getattr(a_set.market_type, "value", a_set.market_type)
    return (_normalize_exchange(exchange), _normalize_market_type(market_type))


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


def generate_set_payload(a_set: Any) -> Optional[Dict[str, Any]]:
    """Prépare le payload ``/api/mtf/run`` **persisté** d'un set (PY-004).

    Lit un ``OrchestrationSet`` ORM, dont ``exchange``/``market_type``/
    ``mtf_profile`` sont des **chaînes** en base (pas des enums). N'inclut PAS
    ``open_state_snapshot`` : le snapshot est une valeur runtime récupérée à
    chaque run (PY-005), pas une donnée de configuration. Utilise le ``dry_run``
    configuré du set (les overrides run-level sont appliqués à l'exécution, pas
    stockés). Forme garantie identique à ``build_mtf_payload`` via le cœur partagé.

    Renvoie ``None`` tant que le set n'a **aucun symbole concret** : un set
    persisté valide à ce stade l'est forcément par sa ``contracts_limit`` seule
    (``assert_set_persistable`` interdit ``symbols`` vide ET ``contracts_limit``
    nulle), donc sa sélection n'est pas encore matérialisée. Or ``/api/mtf/run``
    n'a pas de paramètre de cap : un payload sans ``symbols`` y signifie « tout
    l'univers actif » — jamais l'intention d'un set capé. On laisse donc le
    payload ``null`` jusqu'à ce qu'un refresh (PY-003) renseigne des symboles
    concrets, plutôt que de persister un payload « run-all » trompeur.

    Les symboles vides/blancs sont écartés : Symfony les *trim* puis les filtre
    avant de résoudre l'univers, donc un ``symbols=[" "]`` y vaudrait « tout
    l'univers actif ». Une sélection qui se réduit à du vide après nettoyage est
    donc traitée comme **non matérialisée** (``None``).
    """
    symbols = [s.strip() for s in (a_set.symbols or []) if isinstance(s, str) and s.strip()]
    if not symbols:
        return None
    return _base_mtf_payload(
        dry_run=a_set.dry_run,
        workers=a_set.workers,
        exchange=a_set.exchange,
        market_type=a_set.market_type,
        mtf_profile=a_set.mtf_profile,
        symbols=symbols,
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


async def _dispatch_mtf_run(
    client: httpx.AsyncClient,
    base_url: str,
    set_id: str,
    payload: Dict[str, Any],
) -> Dict[str, Any]:
    """POST ``/api/mtf/run`` et normalise le résultat (succès métier + corps brut).

    Source unique de l'envoi : partagée entre ``run_mtf_set`` (set pydantic) et
    ``run_persisted_set`` (set ORM persisté) pour éviter toute dérive.
    """
    url = f"{base_url.rstrip('/')}/api/mtf/run"
    response = await client.post(url, json=payload)
    try:
        body = response.json()
    except ValueError:
        body = response.text
    return {
        "set_id": set_id,
        # Succès = HTTP 2xx ET succès métier (sinon un partial_success/rejected
        # renvoyé en 200 serait compté à tort comme réussi).
        "ok": response.is_success and is_business_success(body),
        "status": response.status_code,
        "business_status": body.get("status") if isinstance(body, dict) else None,
        "body": body,
    }


async def run_mtf_set(
    client: httpx.AsyncClient,
    base_url: str,
    a_set: OrchestratorSet,
    snapshot: Optional[Dict[str, Any]],
    dry_run: Optional[bool] = None,
) -> Dict[str, Any]:
    """Exécute un set pydantic via ``POST /api/mtf/run`` avec le snapshot en cache."""
    payload = build_mtf_payload(a_set, snapshot, dry_run)
    return await _dispatch_mtf_run(client, base_url, a_set.set_id, payload)


async def run_persisted_set(
    client: httpx.AsyncClient,
    base_url: str,
    orm_set: Any,
    snapshot: Optional[Dict[str, Any]],
    dry_run: Optional[bool] = None,
) -> Dict[str, Any]:
    """Exécute un ``OrchestrationSet`` ORM persisté via ``POST /api/mtf/run`` (PY-005).

    Reconstruit **toujours** le payload depuis les COLONNES ORM via la forme
    canonique ``generate_set_payload`` (allow-list de clés : ``dry_run``,
    ``workers``, ``exchange``, ``market_type``, ``mtf_profile``, ``symbols`` +
    ``sync_tables``/``process_tp_sl=false``) — plutôt que de faire confiance au JSON
    ``orm_set.payload`` stocké. Une ligne écrite hors API pourrait y avoir laissé
    des **flags de contrôle runner** (ex. ``skip_open_state_filter``), des champs
    critiques **divergents**, ou un payload **périmé** alors que ``symbols`` a été
    vidé. Pour une ligne gérée par l'API, ce payload est identique au ``payload``
    persisté (régénéré par PY-004), donc aucun changement de comportement ; les
    gardes de l'orchestrateur décident des mêmes colonnes ORM.

    On n'overlay ensuite que le runtime : override ``dry_run`` run-level et
    ``open_state_snapshot``. Le résultat est augmenté de ``payload_sent`` (l'envoi
    réel à Symfony).

    Si ``orm_set.symbols`` est vide (sélection non matérialisée), ``generate_set_
    payload`` renvoie ``None`` : on échoue **fail-closed sans appel HTTP** plutôt
    que d'envoyer un ``/api/mtf/run`` sans ``symbols`` (qui exécuterait tout
    l'univers actif).
    """
    # Allow-list : repart des colonnes ORM, jamais du JSON stocké (anti control-flag
    # et anti-divergence). `None` ⇔ symbols vide ⇒ non matérialisé.
    payload = generate_set_payload(orm_set)
    if payload is None:
        return {
            "set_id": orm_set.set_id,
            "ok": False,
            "status": None,
            "business_status": None,
            "body": "set payload not materialized (no concrete symbols)",
            "payload_sent": None,
        }
    # Garde runtime workers : la borne MAX_WORKERS_PER_SET n'est imposée qu'au
    # schéma de persistance (aucune contrainte CHECK en base). Une ligne écrite hors
    # API avec `workers>1` passerait Symfony en mode parallèle ; on clampe dans
    # [1, MAX] pour respecter la politique « workers=1 côté Symfony ».
    payload["workers"] = max(1, min(payload.get("workers") or 1, MAX_WORKERS_PER_SET))
    # Override run-level (sinon le dry_run de la colonne, déjà posé par
    # generate_set_payload) puis snapshot runtime.
    if dry_run is not None:
        payload["dry_run"] = dry_run
    if snapshot is not None:
        payload["open_state_snapshot"] = snapshot
    result = await _dispatch_mtf_run(client, base_url, orm_set.set_id, payload)
    result["payload_sent"] = payload
    return result
