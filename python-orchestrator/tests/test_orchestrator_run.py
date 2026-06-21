"""Tests de ``POST /orchestrator/run`` (SF-002b / PY-005, DB-backed).

L'orchestrateur lit les sets actifs d'un dashboard **depuis la base**, récupère
l'état ouvert UNE fois par couple (exchange, market_type) via
``GET /api/exchange/open-state``, puis exécute chaque set ``mtf_run`` via
``POST /api/mtf/run`` (``sync_tables=false`` + ``open_state_snapshot``) à partir
de son ``payload`` persisté. Il persiste enfin l'historique (un ``Run`` global +
un ``RunSet`` par set). Les appels HTTP sont mockés via un faux
``httpx.AsyncClient`` (aucun Symfony requis) ; la DB est une SQLite in-memory.
"""

from __future__ import annotations

from typing import Any, Dict, List

import pytest
from sqlalchemy import select

from app.routers import orchestrator as orch
from app.db.models import Dashboard, OrchestrationSet, Run, RunSet
from app.schemas import OrchestratorSet

_SENTINEL = object()


# --------------------------------------------------------------------------
# Helpers de seed DB
# --------------------------------------------------------------------------


def _seed_dashboard(session, name: str = "dash", *, enabled: bool = True) -> Dashboard:
    dashboard = Dashboard(name=name, enabled=enabled)
    session.add(dashboard)
    session.commit()
    return dashboard


def _default_payload(
    *, exchange: str, market_type: str, dry_run: bool, symbols, mtf_profile: str, workers: int
) -> Dict[str, Any]:
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


def _seed_set(
    session,
    dashboard_id: int,
    set_id: str,
    *,
    exchange: str = "fake",
    market_type: str = "perpetual",
    dry_run: bool = True,
    symbols: tuple = (),
    payload: Any = _SENTINEL,
    priority: int = 0,
    enabled: bool = True,
    mtf_profile: str = "regular",
    action: str = "mtf_run",
    workers: int = 1,
) -> OrchestrationSet:
    """Insère un ``OrchestrationSet`` en direct ORM (contourne le validateur API).

    Permet notamment de seeder des sets **live** (``dry_run=false``) que l'API de
    configuration refuse de persister, pour tester les garde-fous fail-closed.
    """
    if payload is _SENTINEL:
        payload = _default_payload(
            exchange=exchange,
            market_type=market_type,
            dry_run=dry_run,
            symbols=symbols,
            mtf_profile=mtf_profile,
            workers=workers,
        )
    a_set = OrchestrationSet(
        dashboard_id=dashboard_id,
        set_id=set_id,
        exchange=exchange,
        market_type=market_type,
        dry_run=dry_run,
        symbols=list(symbols),
        payload=payload,
        priority=priority,
        enabled=enabled,
        mtf_profile=mtf_profile,
        action=action,
        workers=workers,
    )
    session.add(a_set)
    session.commit()
    return a_set


# --------------------------------------------------------------------------
# Faux client httpx
# --------------------------------------------------------------------------


class _FakeResponse:
    def __init__(self, status_code: int, payload: Any) -> None:
        self.status_code = status_code
        self._payload = payload
        self.text = "" if payload is None else str(payload)

    @property
    def is_success(self) -> bool:
        return 200 <= self.status_code < 300

    def json(self) -> Any:
        if isinstance(self._payload, Exception):
            raise self._payload
        return self._payload


class _FakeAsyncClient:
    """Faux client httpx enregistrant les GET (open-state) et POST (mtf/run).

    ``open_state`` : payload renvoyé par ``GET /api/exchange/open-state``.
    ``open_state_status`` : code HTTP pour simuler un échec de fetch.
    """

    def __init__(
        self,
        *,
        open_state: Dict[str, Any] | None = None,
        open_state_status: int = 200,
        mtf_status: int = 200,
        mtf_body: Dict[str, Any] | None = None,
        **_: Any,
    ) -> None:
        self._open_state = open_state if open_state is not None else {
            "open_positions": [],
            "open_orders": [],
        }
        self._open_state_status = open_state_status
        self._mtf_status = mtf_status
        self._mtf_body = mtf_body if mtf_body is not None else {"status": "success"}
        self.get_calls: List[Dict[str, Any]] = []
        self.post_calls: List[Dict[str, Any]] = []

    async def __aenter__(self) -> "_FakeAsyncClient":
        return self

    async def __aexit__(self, *_: Any) -> None:
        return None

    async def get(self, url: str, params: Dict[str, Any] | None = None) -> _FakeResponse:
        self.get_calls.append({"url": url, "params": params or {}})
        return _FakeResponse(self._open_state_status, self._open_state)

    async def post(self, url: str, json: Dict[str, Any] | None = None) -> _FakeResponse:
        self.post_calls.append({"url": url, "json": json or {}})
        return _FakeResponse(self._mtf_status, self._mtf_body)


def _install_fake_client(monkeypatch, fake: _FakeAsyncClient) -> None:
    monkeypatch.setattr(orch.httpx, "AsyncClient", lambda **kwargs: fake)


def _make_set(
    set_id: str,
    enabled: bool = True,
    priority: int = 0,
    *,
    exchange: str = "fake",
    market_type: str = "perpetual",
    dry_run: bool = True,
    symbols: tuple = (),
) -> OrchestratorSet:
    """Set pydantic (pour les tests unitaires des fonctions pures)."""
    return OrchestratorSet(
        set_id=set_id,
        exchange=exchange,
        market_type=market_type,
        enabled=enabled,
        priority=priority,
        dry_run=dry_run,
        symbols=symbols,
    )


# --------------------------------------------------------------------------
# Contrat de réponse / agrégation
# --------------------------------------------------------------------------


def test_run_returns_contract_shape(orchestrator_env, monkeypatch):
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", symbols=("BTCUSDT",))
    _seed_set(session, dash.id, "b", symbols=("ETHUSDT",))
    _install_fake_client(monkeypatch, _FakeAsyncClient())

    response = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)})
    assert response.status_code == 200

    body = response.json()
    assert set(body.keys()) == {"ok", "run_id", "status", "summary"}
    assert set(body["summary"].keys()) == {"total_calls", "success", "failed"}


def test_run_summary_matches_active_sets(orchestrator_env, monkeypatch):
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", symbols=("BTCUSDT",))
    _seed_set(session, dash.id, "b", symbols=("ETHUSDT",))
    _seed_set(session, dash.id, "c", symbols=("XRPUSDT",))
    _install_fake_client(monkeypatch, _FakeAsyncClient())

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()
    assert body["summary"] == {"total_calls": 3, "success": 3, "failed": 0}
    assert body["ok"] is True
    assert body["status"] == "success"


def test_business_failure_in_200_body_counts_as_failed(orchestrator_env, monkeypatch):
    # /api/mtf/run renvoie HTTP 200 mais un statut métier d'échec : le run global
    # ne doit pas être ok et le set doit être compté en échec.
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", symbols=("BTCUSDT",))
    _install_fake_client(
        monkeypatch,
        _FakeAsyncClient(mtf_body={"status": "partial_success", "data": {"errors": ["x"]}}),
    )

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()
    assert body["ok"] is False
    assert body["summary"] == {"total_calls": 1, "success": 0, "failed": 1}


def test_run_without_dashboard_id_is_no_sets(orchestrator_env, monkeypatch):
    client, _session = orchestrator_env
    _install_fake_client(monkeypatch, _FakeAsyncClient())

    body = client.post("/orchestrator/run").json()
    assert body["status"] == "no_sets"
    assert body["ok"] is False
    assert body["summary"] == {"total_calls": 0, "success": 0, "failed": 0}


def test_run_with_no_active_sets_is_not_success(orchestrator_env, monkeypatch):
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    # Seul un set désactivé : aucun set actif à exécuter.
    _seed_set(session, dash.id, "off", symbols=("BTCUSDT",), enabled=False)
    _install_fake_client(monkeypatch, _FakeAsyncClient())

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()
    assert body["status"] == "no_sets"
    assert body["ok"] is False
    assert body["summary"] == {"total_calls": 0, "success": 0, "failed": 0}


# --------------------------------------------------------------------------
# run_id / idempotence
# --------------------------------------------------------------------------


def test_run_id_is_idempotent_from_idempotency_key(orchestrator_env, monkeypatch):
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", symbols=("BTCUSDT",))
    _install_fake_client(monkeypatch, _FakeAsyncClient())

    payload = {"idempotency_key": "abc123", "dashboard_id": str(dash.id)}
    first = client.post("/orchestrator/run", json=payload).json()["run_id"]
    second = client.post("/orchestrator/run", json=payload).json()["run_id"]
    assert first == second == "run_abc123"


def test_run_id_is_idempotent_from_dashboard_and_tick(orchestrator_env, monkeypatch):
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", symbols=("BTCUSDT",))
    _install_fake_client(monkeypatch, _FakeAsyncClient())

    payload = {"dashboard_id": str(dash.id), "tick_timestamp": "2026-06-17T00:00:00Z"}
    first = client.post("/orchestrator/run", json=payload).json()["run_id"]
    second = client.post("/orchestrator/run", json=payload).json()["run_id"]
    assert first == second
    assert first == f"run_{dash.id}_20260617T000000Z"


def test_long_idempotency_key_run_id_is_bounded():
    from app.schemas import RunRequest

    long_key = "x" * 300
    run_id = orch._resolve_run_id(RunRequest(idempotency_key=long_key))
    assert len(run_id) <= 255
    assert run_id.startswith("run_")
    # Déterministe : même clé => même run_id (idempotence préservée).
    assert run_id == orch._resolve_run_id(RunRequest(idempotency_key=long_key))


def test_run_id_with_slashes_is_url_safe_and_deterministic():
    # Une idempotency_key porteuse de séparateurs (`temporal/dash/2026-06-19`)
    # produirait un run_id avec `/`, non récupérable via GET /runs/{run_id}. Le
    # run_id dérivé doit rester un segment de chemin sûr, tout en étant idempotent.
    from app.schemas import RunRequest

    slash_key = "temporal/dash/2026-06-19"
    run_id = orch._resolve_run_id(RunRequest(idempotency_key=slash_key))
    assert "/" not in run_id
    assert orch._SAFE_RUN_ID.match(run_id)
    # Déterministe : même clé => même run_id (idempotence préservée).
    assert run_id == orch._resolve_run_id(RunRequest(idempotency_key=slash_key))
    # Distinct d'une clé qui ne diffère que par les séparateurs (pas de collision).
    other = orch._resolve_run_id(RunRequest(idempotency_key="temporal_dash_2026-06-19"))
    assert run_id != other


def test_run_id_with_safe_key_stays_readable():
    # Une clé déjà URL-safe ne doit PAS être hachée (run_id lisible conservé).
    from app.schemas import RunRequest

    run_id = orch._resolve_run_id(RunRequest(idempotency_key="abc-123_v2"))
    assert run_id == "run_abc-123_v2"


def test_long_idempotency_key_persists_bounded(orchestrator_env, monkeypatch):
    # Une clé surdimensionnée ne doit pas faire échouer la persistance (run_id et
    # idempotency_key bornés à 255) : l'historique du run déjà exécuté est conservé.
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", symbols=("BTCUSDT",))
    _install_fake_client(monkeypatch, _FakeAsyncClient())

    body = client.post(
        "/orchestrator/run", json={"dashboard_id": str(dash.id), "idempotency_key": "y" * 300}
    ).json()
    run_id = body["run_id"]
    assert len(run_id) <= 255

    session.expire_all()
    run = session.get(Run, run_id)
    assert run is not None
    assert run.idempotency_key is not None
    assert len(run.idempotency_key) <= 255


def test_run_id_is_random_without_context(orchestrator_env, monkeypatch):
    client, _session = orchestrator_env
    _install_fake_client(monkeypatch, _FakeAsyncClient())

    first = client.post("/orchestrator/run").json()["run_id"]
    second = client.post("/orchestrator/run").json()["run_id"]
    assert first != second
    assert first.startswith("run_")


@pytest.mark.parametrize(
    "success,failed,expected",
    [
        (2, 0, "success"),
        (1, 1, "partial_failure"),
        (0, 2, "failed"),
    ],
)
def test_resolve_status_branches(success, failed, expected):
    assert orch._resolve_status(success, failed) == expected


# --------------------------------------------------------------------------
# SF-002b — comportement spécifique snapshot / fail-closed
# --------------------------------------------------------------------------


def test_open_state_fetched_once_per_exchange_market_type(orchestrator_env, monkeypatch):
    # Trois sets : deux partagent (fake, perpetual), un autre (bitmart, perpetual).
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", exchange="fake", market_type="perpetual", symbols=("BTCUSDT",))
    _seed_set(session, dash.id, "b", exchange="fake", market_type="perpetual", symbols=("ETHUSDT",))
    _seed_set(session, dash.id, "c", exchange="bitmart", market_type="perpetual", symbols=("BTCUSDT",))
    fake = _FakeAsyncClient()
    _install_fake_client(monkeypatch, fake)

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()
    assert body["summary"]["total_calls"] == 3

    # Un seul GET open-state par couple distinct => 2 GET (fake, bitmart).
    open_state_calls = [c for c in fake.get_calls if c["url"].endswith("/api/exchange/open-state")]
    assert len(open_state_calls) == 2
    pairs = {(c["params"]["exchange"], c["params"]["market_type"]) for c in open_state_calls}
    assert pairs == {("fake", "perpetual"), ("bitmart", "perpetual")}


def test_each_mtf_payload_has_sync_tables_false_and_snapshot(orchestrator_env, monkeypatch):
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", symbols=("BTCUSDT",))
    _seed_set(session, dash.id, "b", symbols=("ETHUSDT",))
    snapshot = {"open_positions": [{"symbol": "BTCUSDT"}], "open_orders": []}
    fake = _FakeAsyncClient(open_state=snapshot)
    _install_fake_client(monkeypatch, fake)

    client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)})

    mtf_posts = [c for c in fake.post_calls if c["url"].endswith("/api/mtf/run")]
    assert len(mtf_posts) == 2
    for call in mtf_posts:
        assert call["json"]["sync_tables"] is False
        assert call["json"]["open_state_snapshot"] == snapshot


def test_live_set_skipped_when_snapshot_fetch_fails(orchestrator_env, monkeypatch):
    # Un set live et un set dry-run, même couple ; le fetch open-state échoue (503).
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "live", dry_run=False, exchange="bitmart", symbols=("BTCUSDT",))
    _seed_set(session, dash.id, "dry", dry_run=True, exchange="bitmart", symbols=("ETHUSDT",))
    fake = _FakeAsyncClient(open_state_status=503)
    _install_fake_client(monkeypatch, fake)

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()

    # Live échoue (fail-closed), dry-run réussit sans snapshot.
    assert body["summary"]["total_calls"] == 2
    assert body["summary"]["failed"] == 1
    assert body["summary"]["success"] == 1
    assert body["status"] == "partial_failure"

    # Le set live n'a PAS déclenché de POST /api/mtf/run (skippé).
    mtf_posts = [c for c in fake.post_calls if c["url"].endswith("/api/mtf/run")]
    assert len(mtf_posts) == 1


def test_dry_run_proceeds_without_snapshot(orchestrator_env, monkeypatch):
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "dry", dry_run=True, exchange="bitmart", symbols=("BTCUSDT",))
    fake = _FakeAsyncClient(open_state_status=500)
    _install_fake_client(monkeypatch, fake)

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()
    assert body["ok"] is True
    assert body["summary"]["success"] == 1

    # Dry-run exécuté ; payload sans snapshot (couple sans cache).
    mtf_posts = [c for c in fake.post_calls if c["url"].endswith("/api/mtf/run")]
    assert len(mtf_posts) == 1
    assert "open_state_snapshot" not in mtf_posts[0]["json"]
    assert mtf_posts[0]["json"]["sync_tables"] is False


def test_run_level_dry_run_override_forces_live_set_to_dry(orchestrator_env, monkeypatch):
    # Set live + fetch open-state en échec : sans override il serait skippé
    # (fail-closed). Avec {"dry_run": true}, il est forcé en dry-run, donc exécuté.
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "live", dry_run=False, exchange="bitmart", symbols=("BTCUSDT",))
    fake = _FakeAsyncClient(open_state_status=503)
    _install_fake_client(monkeypatch, fake)

    body = client.post(
        "/orchestrator/run", json={"dashboard_id": str(dash.id), "dry_run": True}
    ).json()

    assert body["ok"] is True
    assert body["summary"]["success"] == 1
    mtf_posts = [c for c in fake.post_calls if c["url"].endswith("/api/mtf/run")]
    assert len(mtf_posts) == 1
    assert mtf_posts[0]["json"]["dry_run"] is True


def test_live_forbidden_exchange_skipped_even_with_snapshot(orchestrator_env, monkeypatch):
    # Défense en profondeur : une ligne ORM live OKX/Hyperliquid (écrite hors API,
    # qui contourne assert_set_persistable) ne doit JAMAIS déclencher un run live,
    # même quand un snapshot est disponible.
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "okx_live", dry_run=False, exchange="okx", symbols=("BTCUSDT",))
    fake = _FakeAsyncClient()  # snapshot dispo (200)
    _install_fake_client(monkeypatch, fake)

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()

    assert body["ok"] is False
    assert body["summary"] == {"total_calls": 1, "success": 0, "failed": 1}
    mtf_posts = [c for c in fake.post_calls if c["url"].endswith("/api/mtf/run")]
    assert len(mtf_posts) == 0


def test_live_set_skipped_even_with_snapshot(orchestrator_env, monkeypatch):
    # Phase actuelle : la readiness live n'étant pas livrée, assert_set_persistable
    # interdit TOUT set live. Le runner applique la même politique : une ligne ORM
    # live (écrite hors API), même sur un exchange autorisé (bitmart) et même avec un
    # snapshot disponible, ne doit JAMAIS déclencher un /api/mtf/run live.
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "live", dry_run=False, exchange="bitmart", symbols=("BTCUSDT",))
    fake = _FakeAsyncClient()  # snapshot dispo (200)
    _install_fake_client(monkeypatch, fake)

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()

    assert body["ok"] is False
    assert body["summary"] == {"total_calls": 1, "success": 0, "failed": 1}
    mtf_posts = [c for c in fake.post_calls if c["url"].endswith("/api/mtf/run")]
    assert len(mtf_posts) == 0


def test_live_forbidden_exchange_skipped_with_uppercase_name(orchestrator_env, monkeypatch):
    # Une ligne ORM hors API peut porter une casse/des espaces non normalisés
    # (`OKX`) ; le garde doit fail-closer comme `okx`.
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "okx_live", dry_run=False, exchange="OKX", symbols=("BTCUSDT",))
    fake = _FakeAsyncClient()  # snapshot dispo (200)
    _install_fake_client(monkeypatch, fake)

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()

    assert body["ok"] is False
    assert body["summary"] == {"total_calls": 1, "success": 0, "failed": 1}
    mtf_posts = [c for c in fake.post_calls if c["url"].endswith("/api/mtf/run")]
    assert len(mtf_posts) == 0


def test_disabled_dashboard_returns_no_sets(orchestrator_env, monkeypatch):
    # Le flag enabled du dashboard est un interrupteur de pause global : un
    # dashboard désactivé ne lance aucun set, même s'ils sont actifs.
    client, session = orchestrator_env
    dash = _seed_dashboard(session, enabled=False)
    _seed_set(session, dash.id, "a", symbols=("BTCUSDT",))
    fake = _FakeAsyncClient()
    _install_fake_client(monkeypatch, fake)

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()

    assert body["status"] == "no_sets"
    assert body["ok"] is False
    assert fake.get_calls == []
    assert fake.post_calls == []


def test_dry_run_override_allows_forbidden_exchange(orchestrator_env, monkeypatch):
    # L'override run-level dry_run rend le set sûr : OKX devient exécutable en dry.
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "okx", dry_run=False, exchange="okx", symbols=("BTCUSDT",))
    fake = _FakeAsyncClient()
    _install_fake_client(monkeypatch, fake)

    body = client.post(
        "/orchestrator/run", json={"dashboard_id": str(dash.id), "dry_run": True}
    ).json()

    assert body["ok"] is True
    mtf_posts = [c for c in fake.post_calls if c["url"].endswith("/api/mtf/run")]
    assert len(mtf_posts) == 1
    assert mtf_posts[0]["json"]["dry_run"] is True


def test_conflicting_live_set_ids():
    a = _make_set("a", dry_run=False, exchange="bitmart", symbols=("BTCUSDT",))
    b = _make_set("b", dry_run=False, exchange="bitmart", symbols=("BTCUSDT",))
    c = _make_set("c", dry_run=False, exchange="bitmart", symbols=("ETHUSDT",))
    dry = _make_set("d", dry_run=True, exchange="bitmart", symbols=("BTCUSDT",))
    empty = _make_set("e", dry_run=False, exchange="bitmart")  # symbols=() => univers complet

    # a et b partagent BTCUSDT => conflit ; c (ETHUSDT) et dry (dry-run) hors conflit.
    assert orch._conflicting_live_set_ids([a, b, c, dry], force_dry_run=False) == {"a", "b"}
    # symbols vide chevauche tout le monde du même couple.
    assert orch._conflicting_live_set_ids([empty, c], force_dry_run=False) == {"e", "c"}
    # Override force-dry => plus aucun set effectivement live => aucun conflit.
    assert orch._conflicting_live_set_ids([a, b], force_dry_run=True) == set()
    # Symboles disjoints => pas de conflit.
    assert orch._conflicting_live_set_ids([a, c], force_dry_run=False) == set()

    # Casse différente => même instrument (Symfony normalise en MAJUSCULES).
    lower = _make_set("low", dry_run=False, exchange="bitmart", symbols=("btcusdt",))
    assert orch._conflicting_live_set_ids([a, lower], force_dry_run=False) == {"a", "low"}


def test_conflicting_live_set_ids_normalizes_exchange_market_key():
    from types import SimpleNamespace

    def s(set_id, exchange, market_type="perpetual", symbols=("BTCUSDT",), dry_run=False):
        return SimpleNamespace(
            set_id=set_id, exchange=exchange, market_type=market_type,
            symbols=symbols, dry_run=dry_run,
        )

    a = s("a", "bitmart")
    # Même exchange après normalisation casse/espaces => conflit.
    assert orch._conflicting_live_set_ids([a, s("b", " Bitmart ")], force_dry_run=False) == {"a", "b"}
    # Casse du market_type normalisée également.
    assert orch._conflicting_live_set_ids([a, s("c", "bitmart", market_type="PERPETUAL")], force_dry_run=False) == {"a", "c"}
    # Alias de market_type (perp == perpetual côté Symfony) => conflit.
    assert orch._conflicting_live_set_ids([a, s("e", "bitmart", market_type="perp")], force_dry_run=False) == {"a", "e"}
    # Exchanges réellement différents => pas de conflit.
    assert orch._conflicting_live_set_ids([a, s("d", "okx")], force_dry_run=False) == set()


def test_overlapping_live_sets_rejected_with_mixed_exchange_casing(orchestrator_env, monkeypatch):
    # Deux lignes live « bitmart » et « BITMART » (écrites hors API) ciblant le même
    # symbole partageraient le même snapshot pré-run : rejet fail-closed des deux.
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "live1", dry_run=False, exchange="bitmart", symbols=("BTCUSDT",))
    _seed_set(session, dash.id, "live2", dry_run=False, exchange="BITMART", symbols=("BTCUSDT",))
    fake = _FakeAsyncClient()
    _install_fake_client(monkeypatch, fake)

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()

    assert body["ok"] is False
    assert body["summary"] == {"total_calls": 2, "success": 0, "failed": 2}
    mtf_posts = [c for c in fake.post_calls if c["url"].endswith("/api/mtf/run")]
    assert len(mtf_posts) == 0


def test_overlapping_live_sets_rejected_before_dispatch(orchestrator_env, monkeypatch):
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "live1", dry_run=False, exchange="bitmart", symbols=("BTCUSDT",))
    _seed_set(session, dash.id, "live2", dry_run=False, exchange="bitmart", symbols=("BTCUSDT",))
    fake = _FakeAsyncClient()
    _install_fake_client(monkeypatch, fake)

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()

    # Les deux sets live chevauchants sont rejetés (fail-closed), aucun POST mtf/run.
    assert body["ok"] is False
    assert body["summary"] == {"total_calls": 2, "success": 0, "failed": 2}
    mtf_posts = [c for c in fake.post_calls if c["url"].endswith("/api/mtf/run")]
    assert len(mtf_posts) == 0


# --------------------------------------------------------------------------
# PY-005 — persistance du run (Run + RunSet)
# --------------------------------------------------------------------------


def test_run_persists_run_and_run_sets(orchestrator_env, monkeypatch):
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    set_a = _seed_set(session, dash.id, "a", symbols=("BTCUSDT",))
    set_b = _seed_set(session, dash.id, "b", symbols=("ETHUSDT",))
    snapshot = {"open_positions": [], "open_orders": []}
    fake = _FakeAsyncClient(open_state=snapshot)
    _install_fake_client(monkeypatch, fake)

    body = client.post(
        "/orchestrator/run", json={"dashboard_id": str(dash.id), "idempotency_key": "k1"}
    ).json()
    run_id = body["run_id"]

    session.expire_all()
    run = session.get(Run, run_id)
    assert run is not None
    assert run.dashboard_id == dash.id
    assert run.ok is True
    assert run.status == "success"
    assert run.idempotency_key == "k1"
    assert run.total_calls == 2
    assert run.success_count == 2
    assert run.failed_count == 0
    assert run.started_at is not None
    assert run.finished_at is not None
    assert run.last_json["summary"]["total_calls"] == 2
    assert len(run.last_json["sets"]) == 2

    run_sets = session.scalars(select(RunSet).where(RunSet.run_id == run_id)).all()
    assert len(run_sets) == 2
    by_set = {rs.set_id: rs for rs in run_sets}
    assert by_set["a"].set_ref_id == set_a.id
    assert by_set["b"].set_ref_id == set_b.id
    for rs in run_sets:
        assert rs.ok is True
        assert rs.payload_sent["sync_tables"] is False
        assert rs.payload_sent["open_state_snapshot"] == snapshot
        assert rs.response_json == {"status": "success"}
        assert rs.duration_ms is not None
        assert rs.duration_ms >= 0
        assert rs.error is None


def test_run_persists_failed_set_with_error(orchestrator_env, monkeypatch):
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", symbols=("BTCUSDT",))
    _install_fake_client(
        monkeypatch,
        _FakeAsyncClient(mtf_body={"status": "partial_success", "data": {"errors": ["boom"]}}),
    )

    run_id = client.post(
        "/orchestrator/run", json={"dashboard_id": str(dash.id)}
    ).json()["run_id"]

    session.expire_all()
    run = session.get(Run, run_id)
    assert run.ok is False
    assert run.status == "failed"
    rs = session.scalars(select(RunSet).where(RunSet.run_id == run_id)).one()
    assert rs.ok is False
    # Corps structuré avec data.errors => on remonte le détail (pas le statut),
    # le corps brut est gardé.
    assert rs.error == "boom"
    assert rs.response_json == {"status": "partial_success", "data": {"errors": ["boom"]}}


def test_run_persists_success_with_errors_keeps_error_detail(orchestrator_env, monkeypatch):
    # HTTP 200 status=success MAIS data.errors non vide => échec métier ; l'erreur
    # persistée doit être le détail, pas le statut « success » trompeur.
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", symbols=("BTCUSDT",))
    _install_fake_client(
        monkeypatch,
        _FakeAsyncClient(mtf_body={"status": "success", "data": {"errors": ["BTCUSDT: boom"]}}),
    )

    run_id = client.post(
        "/orchestrator/run", json={"dashboard_id": str(dash.id)}
    ).json()["run_id"]

    session.expire_all()
    rs = session.scalars(select(RunSet).where(RunSet.run_id == run_id)).one()
    assert rs.ok is False
    assert rs.error == "BTCUSDT: boom"
    run = session.get(Run, run_id)
    assert run.last_json["sets"][0]["error"] == "BTCUSDT: boom"


def test_run_persists_symfony_exception_message(orchestrator_env, monkeypatch):
    # RunnerController renvoie HTTP 500 {"status":"error","message": <exception>} :
    # l'erreur persistée doit être le message exploitable, pas le seul statut « error ».
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", symbols=("BTCUSDT",))
    _install_fake_client(
        monkeypatch,
        _FakeAsyncClient(
            mtf_status=500,
            mtf_body={"status": "error", "message": "Doctrine\\DBAL connection refused"},
        ),
    )

    run_id = client.post(
        "/orchestrator/run", json={"dashboard_id": str(dash.id)}
    ).json()["run_id"]

    session.expire_all()
    rs = session.scalars(select(RunSet).where(RunSet.run_id == run_id)).one()
    assert rs.ok is False
    assert rs.error == "Doctrine\\DBAL connection refused"
    run = session.get(Run, run_id)
    assert run.last_json["sets"][0]["error"] == "Doctrine\\DBAL connection refused"


def test_blank_idempotency_key_persisted_as_null(orchestrator_env, monkeypatch):
    # Une idempotency_key vide est traitée comme absente (run_id aléatoire) ; elle
    # ne doit PAS être persistée telle quelle dans la colonne unique
    # `runs.idempotency_key`, sinon deux runs à clé vide violent la contrainte.
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", symbols=("BTCUSDT",))
    _install_fake_client(monkeypatch, _FakeAsyncClient())

    first = client.post(
        "/orchestrator/run", json={"dashboard_id": str(dash.id), "idempotency_key": ""}
    ).json()
    second = client.post(
        "/orchestrator/run", json={"dashboard_id": str(dash.id), "idempotency_key": "   "}
    ).json()
    third = client.post(
        "/orchestrator/run", json={"dashboard_id": str(dash.id), "idempotency_key": "   "}
    ).json()

    # Une clé blanche n'est pas idempotente : chaque appel obtient un run_id frais
    # (y compris deux appels « espaces » identiques) et aucun conflit d'unicité.
    run_ids = {first["run_id"], second["run_id"], third["run_id"]}
    assert len(run_ids) == 3
    session.expire_all()
    for run_id in run_ids:
        run = session.get(Run, run_id)
        assert run is not None
        assert run.idempotency_key is None


def test_persist_reuses_existing_run_id_resolved_by_idempotency_key(
    orchestrator_env, monkeypatch
):
    # record_run peut résoudre un run existant par idempotency_key dont le run_id
    # diffère du run_id dérivé. La persistance des RunSet doit alors réutiliser le
    # run_id réellement persisté, sinon ces lignes pointent un parent runs inexistant
    # et la FK run_sets.run_id casse au commit (après l'exécution Symfony).
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", symbols=("BTCUSDT",))

    # Run pré-existant portant idempotency_key="k1" SOUS un run_id distinct du dérivé
    # ("run_k1"), simulant un writer antérieur (autre convention de run_id).
    session.add(Run(run_id="run_legacy", idempotency_key="k1", ok=False, status="failed"))
    session.commit()

    _install_fake_client(monkeypatch, _FakeAsyncClient())
    resp = client.post(
        "/orchestrator/run", json={"dashboard_id": str(dash.id), "idempotency_key": "k1"}
    )
    assert resp.status_code == 200
    # Le run_id renvoyé est celui réellement persisté (run_legacy), pas le dérivé
    # introuvable (run_k1) : le client peut relire son run.
    assert resp.json()["run_id"] == "run_legacy"

    session.expire_all()
    # Aucun "run_k1" créé : le run existant est mis à jour, et les RunSet pointent le
    # run réellement persisté (pas d'orphelin, FK respectée).
    assert session.get(Run, "run_k1") is None
    legacy = session.get(Run, "run_legacy")
    assert legacy.status == "success"
    # last_json aligné sur le run_id persisté (cohérence historique / relecture).
    assert legacy.last_json["run_id"] == "run_legacy"
    run_sets = session.scalars(select(RunSet).where(RunSet.run_id == "run_legacy")).all()
    assert len(run_sets) == 1
    assert run_sets[0].set_id == "a"


def test_persist_run_nulls_stale_fks(orchestrator_env):
    # Régression : la transaction de lecture étant clôturée avant les appels
    # Symfony, un set/dashboard peut être supprimé pendant le run. La persistance
    # doit neutraliser les FK périmées (sinon l'INSERT viole la FK et tout
    # l'historique est perdu) tout en conservant le run et les set_id.
    from datetime import datetime, timezone
    from types import SimpleNamespace

    from app.schemas import RunSummary

    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    set_a = _seed_set(session, dash.id, "a", symbols=("BTCUSDT",))
    set_b = _seed_set(session, dash.id, "b", symbols=("ETHUSDT",))
    a_id, b_id = set_a.id, set_b.id

    # set_b supprimé « pendant les appels Symfony ».
    session.delete(set_b)
    session.commit()

    mtf_sets = [SimpleNamespace(id=a_id, set_id="a"), SimpleNamespace(id=b_id, set_id="b")]
    results = [
        {"set_id": "a", "ok": True, "status": 200, "business_status": "success",
         "body": {"status": "success"}, "payload_sent": {"x": 1}, "duration_ms": 5},
        {"set_id": "b", "ok": True, "status": 200, "business_status": "success",
         "body": {"status": "success"}, "payload_sent": {"x": 2}, "duration_ms": 6},
    ]
    now = datetime.now(timezone.utc)

    orch._persist_run(
        session,
        run_id="run_stale",
        dashboard_id=dash.id,
        request=None,
        ok=True,
        status="success",
        summary=RunSummary(total_calls=2, success=2, failed=0),
        started_at=now,
        finished_at=now,
        mtf_sets=mtf_sets,
        results=results,
    )

    session.expire_all()
    run = session.get(Run, "run_stale")
    assert run is not None  # historique non perdu malgré la FK périmée
    assert run.dashboard_id == dash.id
    by_set = {
        rs.set_id: rs
        for rs in session.scalars(select(RunSet).where(RunSet.run_id == "run_stale")).all()
    }
    assert by_set["a"].set_ref_id == a_id  # set encore présent
    assert by_set["b"].set_ref_id is None  # set supprimé => FK neutralisée


def test_persist_run_purges_stale_run_sets_on_rerun(orchestrator_env):
    # Re-run du même run_id (retry idempotent) après désactivation d'un set : les
    # RunSet périmés doivent être purgés pour rester cohérents avec le summary.
    from datetime import datetime, timezone
    from types import SimpleNamespace

    from app.schemas import RunSummary

    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    set_a = _seed_set(session, dash.id, "a", symbols=("BTCUSDT",))
    set_b = _seed_set(session, dash.id, "b", symbols=("ETHUSDT",))
    now = datetime.now(timezone.utc)

    def _res(set_id):
        return {"set_id": set_id, "ok": True, "status": 200, "business_status": "success",
                "body": {"status": "success"}, "payload_sent": {}, "duration_ms": 1}

    def _persist(mtf_sets, results, summary):
        orch._persist_run(
            session,
            run_id="run_rerun",
            dashboard_id=dash.id,
            request=None,
            ok=True,
            status="success",
            summary=summary,
            started_at=now,
            finished_at=now,
            mtf_sets=mtf_sets,
            results=results,
        )

    # 1er run : a + b.
    _persist(
        [SimpleNamespace(id=set_a.id, set_id="a"), SimpleNamespace(id=set_b.id, set_id="b")],
        [_res("a"), _res("b")],
        RunSummary(total_calls=2, success=2, failed=0),
    )
    # 2e run même run_id : seulement a (b désactivé entre-temps).
    _persist(
        [SimpleNamespace(id=set_a.id, set_id="a")],
        [_res("a")],
        RunSummary(total_calls=1, success=1, failed=0),
    )

    session.expire_all()
    run = session.get(Run, "run_rerun")
    assert run.total_calls == 1
    run_sets = session.scalars(select(RunSet).where(RunSet.run_id == "run_rerun")).all()
    assert {rs.set_id for rs in run_sets} == {"a"}  # le RunSet périmé "b" est purgé


def test_no_sets_run_is_not_persisted(orchestrator_env, monkeypatch):
    client, session = orchestrator_env
    _install_fake_client(monkeypatch, _FakeAsyncClient())

    run_id = client.post("/orchestrator/run").json()["run_id"]

    session.expire_all()
    assert session.get(Run, run_id) is None


# --------------------------------------------------------------------------
# SAFE-001 — locks d'orchestration par (profil, symbole)
# --------------------------------------------------------------------------


def _seed_lock(
    session,
    run_id: str,
    symbol: str,
    *,
    profile: str = "regular",
    exchange: str = "bitmart",
    market_type: str = "perpetual",
    ttl_seconds: int = 1800,
    acquired_offset_seconds: int = 0,
):
    """Insère un ``OrchestrationLock`` détenu par ``run_id`` (simule un run concurrent).

    ``acquired_offset_seconds`` décale ``acquired_at`` dans le passé ; combiné à
    ``ttl_seconds`` il permet de produire un lock déjà expiré (TTL dépassé).
    """
    from datetime import datetime, timedelta, timezone

    from app.db import repositories as repo
    from app.db.models import OrchestrationLock

    acquired = datetime.now(timezone.utc) - timedelta(seconds=acquired_offset_seconds)
    lock = OrchestrationLock(
        lock_key=repo.build_lock_key(profile, exchange, market_type, symbol),
        mtf_profile=profile,
        exchange=exchange,
        market_type=market_type,
        symbol=symbol,
        run_id=run_id,
        acquired_at=acquired,
        expires_at=acquired + timedelta(seconds=ttl_seconds),
    )
    session.add(lock)
    session.commit()
    return lock


def _all_locks(session):
    from app.db.models import OrchestrationLock

    session.expire_all()
    return session.scalars(select(OrchestrationLock)).all()


def test_lock_acquired_then_released_after_successful_run(orchestrator_env, monkeypatch):
    # Acquisition réussie -> set exécuté (POST mtf/run) -> lock libéré en fin de run.
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", exchange="bitmart", symbols=("BTCUSDT",))
    fake = _FakeAsyncClient()
    _install_fake_client(monkeypatch, fake)

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()

    assert body["ok"] is True
    assert body["summary"]["success"] == 1
    # Le set a bien été dispatché.
    mtf_posts = [c for c in fake.post_calls if c["url"].endswith("/api/mtf/run")]
    assert len(mtf_posts) == 1
    # Et plus aucun lock résiduel : libéré dans le finally du set.
    assert _all_locks(session) == []


def test_set_skipped_when_symbol_locked_by_other_run(orchestrator_env, monkeypatch):
    # Un couple (profil, symbole) déjà verrouillé par un autre run actif => set skippé
    # (ok=false, message "locked"), les AUTRES sets du run continuent.
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "locked", exchange="bitmart", symbols=("BTCUSDT",))
    _seed_set(session, dash.id, "free", exchange="bitmart", symbols=("ETHUSDT",))
    # Run concurrent détenant déjà BTCUSDT (regular/bitmart/perpetual).
    _seed_lock(session, "run_other", "BTCUSDT")
    fake = _FakeAsyncClient()
    _install_fake_client(monkeypatch, fake)

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()

    assert body["summary"]["total_calls"] == 2
    assert body["summary"]["success"] == 1
    assert body["summary"]["failed"] == 1

    # Seul le set "free" (ETHUSDT) est dispatché ; "locked" est skippé sans POST.
    mtf_posts = [c for c in fake.post_calls if c["url"].endswith("/api/mtf/run")]
    assert len(mtf_posts) == 1

    run_id = body["run_id"]
    rs_locked = session.scalars(
        select(RunSet).where(RunSet.run_id == run_id, RunSet.set_id == "locked")
    ).one()
    assert rs_locked.ok is False
    assert rs_locked.error.startswith("locked: ")
    assert "held by run run_other" in rs_locked.error

    # Le lock du run concurrent reste intact ; aucun lock de ce run ne subsiste.
    remaining = _all_locks(session)
    assert {l.run_id for l in remaining} == {"run_other"}
    assert {l.symbol for l in remaining} == {"BTCUSDT"}


def test_expired_lock_is_reclaimed_and_set_runs(orchestrator_env, monkeypatch):
    # Un lock expiré (TTL dépassé) ne bloque pas : il est reclaim, le set s'exécute.
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", exchange="bitmart", symbols=("BTCUSDT",))
    # Lock détenu par un run mort, acquis il y a 2h avec un TTL de 10s => expiré.
    _seed_lock(session, "run_dead", "BTCUSDT", ttl_seconds=10, acquired_offset_seconds=7200)
    fake = _FakeAsyncClient()
    _install_fake_client(monkeypatch, fake)

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()

    assert body["ok"] is True
    assert body["summary"]["success"] == 1
    mtf_posts = [c for c in fake.post_calls if c["url"].endswith("/api/mtf/run")]
    assert len(mtf_posts) == 1
    # Lock du run mort reclaim puis libéré : table vide en fin de run.
    assert _all_locks(session) == []


def test_partial_lock_leaves_no_residual_lock(orchestrator_env, monkeypatch):
    # Acquisition « tout ou rien » : un set [BTCUSDT, ETHUSDT] dont ETHUSDT est déjà
    # verrouillé est skippé, et NE laisse aucun lock résiduel sur BTCUSDT.
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "multi", exchange="bitmart", symbols=("BTCUSDT", "ETHUSDT"))
    _seed_lock(session, "run_other", "ETHUSDT")
    fake = _FakeAsyncClient()
    _install_fake_client(monkeypatch, fake)

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()

    assert body["ok"] is False
    assert body["summary"] == {"total_calls": 1, "success": 0, "failed": 1}
    mtf_posts = [c for c in fake.post_calls if c["url"].endswith("/api/mtf/run")]
    assert len(mtf_posts) == 0

    # Seul le lock concurrent ETHUSDT subsiste : pas de BTCUSDT résiduel.
    remaining = _all_locks(session)
    assert {(l.run_id, l.symbol) for l in remaining} == {("run_other", "ETHUSDT")}


def test_lock_released_even_when_set_fails(orchestrator_env, monkeypatch):
    # Libération garantie dans le finally même si le set échoue (erreur métier).
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", exchange="bitmart", symbols=("BTCUSDT",))
    _install_fake_client(
        monkeypatch,
        _FakeAsyncClient(mtf_status=500, mtf_body={"status": "error", "message": "boom"}),
    )

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()

    assert body["ok"] is False
    assert body["summary"]["failed"] == 1
    # Échec métier => le lock est tout de même libéré (aucun lock résiduel).
    assert _all_locks(session) == []


def test_lock_released_even_on_dispatch_exception(orchestrator_env, monkeypatch):
    # Libération garantie dans le finally même si le dispatch lève (HTTPError caught).
    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", exchange="bitmart", symbols=("BTCUSDT",))

    fake = _FakeAsyncClient()

    async def _raise(*_a, **_k):
        raise __import__("httpx").HTTPError("connection reset")

    fake.post = _raise  # type: ignore[assignment]
    _install_fake_client(monkeypatch, fake)

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()

    assert body["ok"] is False
    assert body["summary"]["failed"] == 1
    # Exception de dispatch => lock libéré quand même.
    assert _all_locks(session) == []


def test_lock_uses_injected_clock_for_expiry(orchestrator_env, monkeypatch):
    # Le `now` de l'orchestrateur est injectable (orch._now) : un lock dont l'expiry
    # est antérieur au `now` injecté est reclaim, postérieur il bloque.
    from datetime import datetime, timedelta, timezone

    client, session = orchestrator_env
    dash = _seed_dashboard(session)
    _seed_set(session, dash.id, "a", exchange="bitmart", symbols=("BTCUSDT",))

    base = datetime(2026, 6, 21, 12, 0, 0, tzinfo=timezone.utc)
    # Lock qui expire 1s AVANT le now injecté => reclaimable.
    from app.db import repositories as repo
    from app.db.models import OrchestrationLock

    session.add(
        OrchestrationLock(
            lock_key=repo.build_lock_key("regular", "bitmart", "perpetual", "BTCUSDT"),
            mtf_profile="regular", exchange="bitmart", market_type="perpetual",
            symbol="BTCUSDT", run_id="run_old",
            acquired_at=base - timedelta(hours=1),
            expires_at=base - timedelta(seconds=1),
        )
    )
    session.commit()

    monkeypatch.setattr(orch, "_now", lambda: base)
    fake = _FakeAsyncClient()
    _install_fake_client(monkeypatch, fake)

    body = client.post("/orchestrator/run", json={"dashboard_id": str(dash.id)}).json()
    assert body["ok"] is True
    mtf_posts = [c for c in fake.post_calls if c["url"].endswith("/api/mtf/run")]
    assert len(mtf_posts) == 1
