import pytest
from fastapi.testclient import TestClient

from app.main import app
from app.routers import orchestrator as orch
from app.schemas import OrchestratorSet

client = TestClient(app)


def _make_set(set_id: str, enabled: bool = True, priority: int = 0) -> OrchestratorSet:
    return OrchestratorSet(set_id=set_id, exchange="fake", enabled=enabled, priority=priority)


def test_run_returns_contract_shape(monkeypatch):
    monkeypatch.setattr(orch, "list_active_sets", lambda: [_make_set("a"), _make_set("b")])

    response = client.post("/orchestrator/run")
    assert response.status_code == 200

    body = response.json()
    assert set(body.keys()) == {"ok", "run_id", "status", "summary"}
    assert set(body["summary"].keys()) == {"total_calls", "success", "failed"}


def test_run_summary_matches_injected_sets(monkeypatch):
    # Oracle indépendant : on injecte explicitement 3 sets actifs.
    monkeypatch.setattr(
        orch, "list_active_sets", lambda: [_make_set("a"), _make_set("b"), _make_set("c")]
    )

    body = client.post("/orchestrator/run").json()
    assert body["summary"] == {"total_calls": 3, "success": 3, "failed": 0}
    assert body["ok"] is True
    assert body["status"] == "success"


def test_run_with_no_active_sets_is_not_success(monkeypatch):
    monkeypatch.setattr(orch, "list_active_sets", lambda: [])

    body = client.post("/orchestrator/run").json()
    assert body["status"] == "no_sets"
    assert body["ok"] is False
    assert body["summary"] == {"total_calls": 0, "success": 0, "failed": 0}


def test_run_id_is_idempotent_from_idempotency_key(monkeypatch):
    monkeypatch.setattr(orch, "list_active_sets", lambda: [_make_set("a")])

    payload = {"idempotency_key": "abc123"}
    first = client.post("/orchestrator/run", json=payload).json()["run_id"]
    second = client.post("/orchestrator/run", json=payload).json()["run_id"]
    assert first == second == "run_abc123"


def test_run_id_is_idempotent_from_dashboard_and_tick(monkeypatch):
    monkeypatch.setattr(orch, "list_active_sets", lambda: [_make_set("a")])

    payload = {"dashboard_id": "dash1", "tick_timestamp": "2026-06-17T00:00:00Z"}
    first = client.post("/orchestrator/run", json=payload).json()["run_id"]
    second = client.post("/orchestrator/run", json=payload).json()["run_id"]
    assert first == second
    assert first == "run_dash1_20260617T000000Z"


def test_run_id_is_random_without_context(monkeypatch):
    monkeypatch.setattr(orch, "list_active_sets", lambda: [_make_set("a")])

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
