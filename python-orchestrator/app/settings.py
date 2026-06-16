"""Configuration de l'orchestrateur, chargée depuis l'environnement.

On suit la convention du sous-projet ``cron_symfony_mtf_workers`` :
lecture via ``os.getenv`` avec valeurs par défaut, pas de dépendance
supplémentaire.
"""

from __future__ import annotations

import os
from dataclasses import dataclass


def _int_env(name: str, default: int) -> int:
    raw = os.getenv(name)
    if raw is None or raw.strip() == "":
        return default
    try:
        return int(raw)
    except ValueError:
        return default


@dataclass(frozen=True)
class Settings:
    """Paramètres runtime de l'API Python Orchestrator."""

    service_name: str = "python-orchestrator"
    # URL de base de Symfony (le moteur métier MTF). Utilisée par PY-002+.
    symfony_base_url: str = "http://trading-app-nginx:80"
    # Port d'écoute HTTP du service.
    port: int = 8099
    # Concurrence globale bornée des appels Symfony (utilisée par PY-002+).
    max_concurrency: int = 2

    @classmethod
    def from_env(cls) -> "Settings":
        return cls(
            symfony_base_url=os.getenv("SYMFONY_BASE_URL", cls.symfony_base_url),
            port=_int_env("ORCHESTRATOR_PORT", cls.port),
            max_concurrency=_int_env("MAX_CONCURRENCY", cls.max_concurrency),
        )


def get_settings() -> Settings:
    """Retourne les paramètres courants chargés depuis l'environnement."""
    return Settings.from_env()
