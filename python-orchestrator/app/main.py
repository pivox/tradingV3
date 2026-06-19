"""Point d'entrée FastAPI de l'API Python Orchestrator (PY-001).

Lancement local :
    uvicorn app.main:app --host 0.0.0.0 --port 8099
"""

from __future__ import annotations

from fastapi import FastAPI

from app import __version__
from app.routers import dashboards, health, orchestrator, runs

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

app.include_router(health.router)
app.include_router(orchestrator.router)
app.include_router(dashboards.router)
app.include_router(runs.router)
