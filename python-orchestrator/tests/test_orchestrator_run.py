"""Tests de ``POST /orchestrator/run`` (SF-002b).

L'orchestrateur récupère l'état ouvert UNE fois par couple (exchange,
market_type) via ``GET /api/exchange/open-state``, puis exécute chaque set
``mtf_run`` via ``POST /api/mtf/run`` avec ``sync_tables=false`` +
``open_state_snapshot``. Les appels HTTP sont mockés via un faux
``httpx.AsyncClient`` (aucun Symfony requis).
"""

from __future__ import annotations

from typing import Any, Dict, List

import httpx
import pytest
from fastapi.testclient import TestClient

from app.main import app
from app.routers import orchestrator as orch
from app.schemas import OrchestratorSet

client = TestClient(app)


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
    return OrchestratorSet(
        set_id=set_id,
        exchange=exchange,
        market_type=market_type,
        enabled=enabled,
        priority=priority,
        dry_run=dry_run,
        symbols=symbols,
    )


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


def test_run_returns_contract_shape(monkeypatch):
    monkeypatch.setattr(orch, "list_active_sets", lambda: [_make_set("a"), _make_set("b")])
    _install_fake_client(monkeypatch, _FakeAsyncClient())

    response = client.post("/orchestrator/run")
    assert response.status_code == 200

    body = response.json()
    assert set(body.keys()) == {"ok", "run_id", "status", "summary"}
    assert set(body["summary"].keys()) == {"total_calls", "success", "failed"}


def test_run_summary_matches_injected_sets(monkeypatch):
    monkeypatch.setattr(
        orch, "list_active_sets", lambda: [_make_set("a"), _make_set("b"), _make_set("c")]
    )
    _install_fake_client(monkeypatch, _FakeAsyncClient())

    body = client.post("/orchestrator/run").json()
    assert body["summary"] == {"total_calls": 3, "success": 3, "failed": 0}
    assert body["ok"] is True
    assert body["status"] == "success"


def test_business_failure_in_200_body_counts_as_failed(monkeypatch):
    # /api/mtf/run renvoie HTTP 200 mais un statut métier d'échec : le run global
    # ne doit pas être ok et le set doit être compté en échec.
    monkeypatch.setattr(orch, "list_active_sets", lambda: [_make_set("a")])
    _install_fake_client(
        monkeypatch,
        _FakeAsyncClient(mtf_body={"status": "partial_success", "data": {"errors": ["x"]}}),
    )

    body = client.post("/orchestrator/run").json()
    assert body["ok"] is False
    assert body["summary"] == {"total_calls": 1, "success": 0, "failed": 1}


def test_run_with_no_active_sets_is_not_success(monkeypatch):
    monkeypatch.setattr(orch, "list_active_sets", lambda: [])
    _install_fake_client(monkeypatch, _FakeAsyncClient())

    body = client.post("/orchestrator/run").json()
    assert body["status"] == "no_sets"
    assert body["ok"] is False
    assert body["summary"] == {"total_calls": 0, "success": 0, "failed": 0}


def test_run_id_is_idempotent_from_idempotency_key(monkeypatch):
    monkeypatch.setattr(orch, "list_active_sets", lambda: [_make_set("a")])
    _install_fake_client(monkeypatch, _FakeAsyncClient())

    payload = {"idempotency_key": "abc123"}
    first = client.post("/orchestrator/run", json=payload).json()["run_id"]
    second = client.post("/orchestrator/run", json=payload).json()["run_id"]
    assert first == second == "run_abc123"


def test_run_id_is_idempotent_from_dashboard_and_tick(monkeypatch):
    monkeypatch.setattr(orch, "list_active_sets", lambda: [_make_set("a")])
    _install_fake_client(monkeypatch, _FakeAsyncClient())

    payload = {"dashboard_id": "dash1", "tick_timestamp": "2026-06-17T00:00:00Z"}
    first = client.post("/orchestrator/run", json=payload).json()["run_id"]
    second = client.post("/orchestrator/run", json=payload).json()["run_id"]
    assert first == second
    assert first == "run_dash1_20260617T000000Z"


def test_run_id_is_random_without_context(monkeypatch):
    monkeypatch.setattr(orch, "list_active_sets", lambda: [_make_set("a")])
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


def test_open_state_fetched_once_per_exchange_market_type(monkeypatch):
    # Trois sets : deux partagent (fake, perpetual), un autre (bitmart, perpetual).
    sets = [
        _make_set("a", exchange="fake", market_type="perpetual"),
        _make_set("b", exchange="fake", market_type="perpetual"),
        _make_set("c", exchange="bitmart", market_type="perpetual"),
    ]
    monkeypatch.setattr(orch, "list_active_sets", lambda: sets)
    fake = _FakeAsyncClient()
    _install_fake_client(monkeypatch, fake)

    body = client.post("/orchestrator/run").json()
    assert body["summary"]["total_calls"] == 3

    # Un seul GET open-state par couple distinct => 2 GET (fake, bitmart).
    open_state_calls = [c for c in fake.get_calls if c["url"].endswith("/api/exchange/open-state")]
    assert len(open_state_calls) == 2
    pairs = {(c["params"]["exchange"], c["params"]["market_type"]) for c in open_state_calls}
    assert pairs == {("fake", "perpetual"), ("bitmart", "perpetual")}


def test_each_mtf_payload_has_sync_tables_false_and_snapshot(monkeypatch):
    sets = [_make_set("a", symbols=("BTCUSDT",)), _make_set("b")]
    monkeypatch.setattr(orch, "list_active_sets", lambda: sets)
    snapshot = {"open_positions": [{"symbol": "BTCUSDT"}], "open_orders": []}
    fake = _FakeAsyncClient(open_state=snapshot)
    _install_fake_client(monkeypatch, fake)

    client.post("/orchestrator/run")

    mtf_posts = [c for c in fake.post_calls if c["url"].endswith("/api/mtf/run")]
    assert len(mtf_posts) == 2
    for call in mtf_posts:
        assert call["json"]["sync_tables"] is False
        assert call["json"]["open_state_snapshot"] == snapshot


def test_live_set_skipped_when_snapshot_fetch_fails(monkeypatch):
    # Un set live et un set dry-run, même couple ; le fetch open-state échoue (503).
    sets = [
        _make_set("live", dry_run=False, exchange="bitmart"),
        _make_set("dry", dry_run=True, exchange="bitmart"),
    ]
    monkeypatch.setattr(orch, "list_active_sets", lambda: sets)
    fake = _FakeAsyncClient(open_state_status=503)
    _install_fake_client(monkeypatch, fake)

    body = client.post("/orchestrator/run").json()

    # Live échoue (fail-closed), dry-run réussit sans snapshot.
    assert body["summary"]["total_calls"] == 2
    assert body["summary"]["failed"] == 1
    assert body["summary"]["success"] == 1
    assert body["status"] == "partial_failure"

    # Le set live n'a PAS déclenché de POST /api/mtf/run (skippé).
    mtf_posts = [c for c in fake.post_calls if c["url"].endswith("/api/mtf/run")]
    assert len(mtf_posts) == 1


def test_dry_run_proceeds_without_snapshot(monkeypatch):
    sets = [_make_set("dry", dry_run=True, exchange="bitmart")]
    monkeypatch.setattr(orch, "list_active_sets", lambda: sets)
    fake = _FakeAsyncClient(open_state_status=500)
    _install_fake_client(monkeypatch, fake)

    body = client.post("/orchestrator/run").json()
    assert body["ok"] is True
    assert body["summary"]["success"] == 1

    # Dry-run exécuté ; payload sans snapshot (couple sans cache).
    mtf_posts = [c for c in fake.post_calls if c["url"].endswith("/api/mtf/run")]
    assert len(mtf_posts) == 1
    assert "open_state_snapshot" not in mtf_posts[0]["json"]
    assert mtf_posts[0]["json"]["sync_tables"] is False


def test_run_level_dry_run_override_forces_live_set_to_dry(monkeypatch):
    # Set live + fetch open-state en échec : sans override il serait skippé
    # (fail-closed). Avec {"dry_run": true}, il est forcé en dry-run, donc exécuté.
    sets = [_make_set("live", dry_run=False, exchange="bitmart")]
    monkeypatch.setattr(orch, "list_active_sets", lambda: sets)
    fake = _FakeAsyncClient(open_state_status=503)
    _install_fake_client(monkeypatch, fake)

    body = client.post("/orchestrator/run", json={"dry_run": True}).json()

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


def test_overlapping_live_sets_rejected_before_dispatch(monkeypatch):
    sets = [
        _make_set("live1", dry_run=False, exchange="bitmart", symbols=("BTCUSDT",)),
        _make_set("live2", dry_run=False, exchange="bitmart", symbols=("BTCUSDT",)),
    ]
    monkeypatch.setattr(orch, "list_active_sets", lambda: sets)
    fake = _FakeAsyncClient()
    _install_fake_client(monkeypatch, fake)

    body = client.post("/orchestrator/run").json()

    # Les deux sets live chevauchants sont rejetés (fail-closed), aucun POST mtf/run.
    assert body["ok"] is False
    assert body["summary"] == {"total_calls": 2, "success": 0, "failed": 2}
    mtf_posts = [c for c in fake.post_calls if c["url"].endswith("/api/mtf/run")]
    assert len(mtf_posts) == 0
