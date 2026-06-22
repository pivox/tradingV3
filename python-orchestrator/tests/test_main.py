"""Tests unitaires du montage applicatif FastAPI (QA-001, cf. PY-001 / OBS-001/002).

Vérifie, sans réseau ni DB, que ``app/main`` :
- monte le middleware CORS avec les origines de config ;
- inclut bien tous les routers (health, orchestrator, dashboards, runs, metrics) ;
- a configuré l'audit (handler JSON line posé) et les métriques au démarrage.
"""

from __future__ import annotations

import logging

from fastapi.middleware.cors import CORSMiddleware
from fastapi.testclient import TestClient

from app.logging_config import _AUDIT_HANDLER_FLAG
from app.main import app
from app.services import run_metrics
from app.services.run_audit import AUDIT_LOGGER_NAME
from app.settings import get_settings


def test_cors_middleware_is_mounted() -> None:
    cors = [m for m in app.user_middleware if m.cls is CORSMiddleware]
    assert len(cors) == 1


def test_cors_preflight_allows_configured_origin() -> None:
    origin = get_settings().cors_allow_origins[0]
    client = TestClient(app)
    resp = client.options(
        "/healthcheck",
        headers={
            "Origin": origin,
            "Access-Control-Request-Method": "GET",
        },
    )
    assert resp.status_code == 200
    assert resp.headers["access-control-allow-origin"] == origin


def test_all_routers_are_included() -> None:
    # Les routers sont inclus paresseusement : on lit le schéma OpenAPI, qui
    # aplatit l'ensemble des routes effectivement montées.
    paths = set(app.openapi()["paths"])
    # Un endpoint représentatif par router monté dans app/main.py.
    assert "/healthcheck" in paths  # health
    assert "/metrics" in paths  # metrics
    assert "/orchestrator/run" in paths  # orchestrator
    # dashboards & runs exposent des routes préfixées : on vérifie leur présence.
    assert any(p.startswith("/dashboards") for p in paths)
    assert any(p.startswith("/runs") for p in paths)


def test_audit_logging_configured_at_import() -> None:
    logger = logging.getLogger(AUDIT_LOGGER_NAME)
    audit_handlers = [
        h for h in logger.handlers if getattr(h, _AUDIT_HANDLER_FLAG, False)
    ]
    # L'import de app.main a exécuté configure_audit_logging (un handler posé).
    assert len(audit_handlers) == 1
    assert logger.propagate is False


def test_metrics_configured_at_import() -> None:
    # run_metrics.configure(...) a été appelé au démarrage ; le snapshot reflète
    # l'état d'activation issu des settings.
    snap = run_metrics.snapshot()
    assert snap["enabled"] is get_settings().metrics_enabled


def test_app_metadata() -> None:
    from app import __version__

    assert app.title == "TradingV3 — Python Orchestrator"
    assert app.version == __version__
