"""Endpoint de healthcheck."""

from __future__ import annotations

from fastapi import APIRouter

from app import __version__
from app.schemas import Health
from app.settings import get_settings

router = APIRouter(tags=["health"])


@router.get("/healthcheck", response_model=Health)
def healthcheck() -> Health:
    """Retourne l'état de santé du service."""
    settings = get_settings()
    return Health(status="ok", service=settings.service_name, version=__version__)
