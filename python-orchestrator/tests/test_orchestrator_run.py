from fastapi.testclient import TestClient

from app.main import app
from app.services.sets import list_active_sets

client = TestClient(app)


def test_run_returns_contract_shape():
    response = client.post("/orchestrator/run")
    assert response.status_code == 200

    body = response.json()
    assert set(body.keys()) == {"ok", "run_id", "status", "summary"}
    assert body["run_id"].startswith("run_")
    assert body["status"] in {"success", "partial_failure", "failed"}

    summary = body["summary"]
    assert set(summary.keys()) == {"total_calls", "success", "failed"}


def test_run_summary_is_consistent_with_active_sets():
    expected_calls = len(list_active_sets())

    body = client.post("/orchestrator/run").json()
    summary = body["summary"]

    assert summary["total_calls"] == expected_calls
    assert summary["success"] + summary["failed"] == summary["total_calls"]
    # PY-001 stub : tous les sets actifs simulés réussissent.
    assert summary["failed"] == 0
    assert body["ok"] is True
    assert body["status"] == "success"


def test_run_ids_are_unique():
    first = client.post("/orchestrator/run").json()["run_id"]
    second = client.post("/orchestrator/run").json()["run_id"]
    assert first != second
