"""Tests unitaires de l'endpoint ``GET /metrics`` (QA-001, cf. OBS-002).

Le router se contente de sérialiser le snapshot du registre in-process. On vérifie
le câblage HTTP (statut, JSON dérivé) en états activé et désactivé, sans réseau.
"""

from __future__ import annotations

import pytest
from fastapi.testclient import TestClient

from app.main import app
from app.services import run_metrics


@pytest.fixture(autouse=True)
def _restore_metrics():
    """Réactive et vide le registre après chaque test (état global partagé)."""
    yield
    run_metrics.configure(enabled=True)
    run_metrics.reset()


def test_metrics_endpoint_returns_snapshot() -> None:
    run_metrics.configure(enabled=True)
    run_metrics.reset()
    run_metrics.observe_run_finished(status="success", total_calls=1, success=1, failed=0)

    resp = TestClient(app).get("/metrics")
    assert resp.status_code == 200
    body = resp.json()
    assert body["enabled"] is True
    # Le snapshot expose bien le compteur de runs alimenté ci-dessus.
    assert body == run_metrics.snapshot()


def test_metrics_endpoint_when_disabled() -> None:
    run_metrics.configure(enabled=False)
    resp = TestClient(app).get("/metrics")
    assert resp.status_code == 200
    assert resp.json()["enabled"] is False
