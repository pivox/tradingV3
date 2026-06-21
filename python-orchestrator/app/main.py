"""Point d'entrée FastAPI de l'API Python Orchestrator (PY-001).

Lancement local :
    uvicorn app.main:app --host 0.0.0.0 --port 8099
"""

from __future__ import annotations

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from app import __version__
from app.logging_config import configure_audit_logging
from app.routers import dashboards, health, orchestrator, runs
from app.settings import get_settings

# Audit des runs (OBS-001) : on branche le logger JSON line `orchestrator.audit`
# au démarrage, au niveau piloté par `ORCHESTRATION_LOG_LEVEL` (défaut INFO,
# validé par `settings`). Idempotent (un seul handler), pas de double émission.
configure_audit_logging(get_settings().log_level)

app = FastAPI(
    title="TradingV3 — Python Orchestrator",
    version=__version__,
    description=(
        "Orchestrateur des appels TradingV3. Gère les dashboards et sets "
        "(PY-002), appelle Symfony en parallèle (PY-005), agrège et expose "
        "le dernier JSON global et par set (PY-006). "
        "Voir docs/handbook/technical/python-orchestrator.md."
    ),
)

# CORS : le cockpit (UI-001) appelle cette API directement depuis le navigateur.
# Origines restreintes par config (CORS_ALLOW_ORIGINS) — pas de wildcard par défaut.
# Pas de credentials (cookies) : authentification hors périmètre à ce stade.
app.add_middleware(
    CORSMiddleware,
    allow_origins=list(get_settings().cors_allow_origins),
    allow_credentials=False,
    allow_methods=["*"],
    allow_headers=["*"],
)

app.include_router(health.router)
app.include_router(orchestrator.router)
app.include_router(dashboards.router)
app.include_router(runs.router)
