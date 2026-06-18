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


def _seed_dashboard(session, name: str = "dash") -> Dashboard:
    dashboard = Dashboard(name=name, enabled=True)
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
    # Corps structuré => l'erreur remonte le statut métier, le corps brut est gardé.
    assert rs.error == "partial_success"
    assert rs.response_json == {"status": "partial_success", "data": {"errors": ["boom"]}}


def test_no_sets_run_is_not_persisted(orchestrator_env, monkeypatch):
    client, session = orchestrator_env
    _install_fake_client(monkeypatch, _FakeAsyncClient())

    run_id = client.post("/orchestrator/run").json()["run_id"]

    session.expire_all()
    assert session.get(Run, run_id) is None
